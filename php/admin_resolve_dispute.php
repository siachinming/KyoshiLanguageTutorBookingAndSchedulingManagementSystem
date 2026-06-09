<?php
session_start();
include 'config.php';
include 'check_login.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require '../vendor/autoload.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

$dispute_id = $_GET['id'] ?? 0;
$type = $_GET['type'] ?? 'payment';

if (!$dispute_id) {
    header("Location: admin_payments.php");
    exit();
}

// Get dispute details including student's requested resolution
$query = $conn->prepare("
    SELECT d.*, 
           s.fullname as student_name, s.email as student_email,
           t.fullname as tutor_name, t.email as tutor_email,
           p.id as payment_id, p.amount, p.payment_method,
           b.id as booking_id, b.booking_date, b.booking_time, b.language
    FROM disputes d
    LEFT JOIN users s ON d.student_id = s.id
    LEFT JOIN users t ON d.tutor_id = t.id
    LEFT JOIN payments p ON d.payment_id = p.id
    LEFT JOIN bookings b ON d.booking_id = b.id
    WHERE d.id = ?
");
$query->bind_param("i", $dispute_id);
$query->execute();
$dispute = $query->get_result()->fetch_assoc();

if (!$dispute) {
    header("Location: admin_payments.php");
    exit();
}

// Handle resolution submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resolve_dispute'])) {
    $resolution_action = $_POST['resolution_action'] ?? '';
    $resolution_message = $conn->real_escape_string($_POST['resolution_message'] ?? '');
    $refund_amount = isset($_POST['refund_amount']) ? floatval($_POST['refund_amount']) : 0;
    $new_booking_datetime = isset($_POST['new_booking_datetime']) ? $conn->real_escape_string($_POST['new_booking_datetime']) : null;
    
    $resolution_log = "\n[" . date('Y-m-d H:i:s') . "] DISPUTE RESOLVED\n";
    $resolution_log .= "Student Requested: " . ucfirst(str_replace('_', ' ', $dispute['resolution_type'])) . "\n";
    $resolution_log .= "Admin Action: " . ucfirst(str_replace('_', ' ', $resolution_action)) . "\n";
    $resolution_log .= "Message to Student: " . $resolution_message . "\n";
    
    $conn->begin_transaction();
    
    try {
        // Update dispute record
        $updateDispute = $conn->prepare("
            UPDATE disputes 
            SET status = 'resolved', 
                resolution_type = ?,
                resolution_note = ?,
                resolved_by = ?,
                resolved_at = NOW()
            WHERE id = ?
        ");
        $admin_id = $_SESSION['user_id'];
        $updateDispute->bind_param("ssii", $resolution_action, $resolution_message, $admin_id, $dispute_id);
        $updateDispute->execute();
        
        // Execute based on admin's chosen action
        switch($resolution_action) {
            case 'approve_complete':
                $conn->query("UPDATE payments SET status = 'verified', verified_at = NOW(), notes = CONCAT(COALESCE(notes, ''), '$resolution_log') WHERE id = {$dispute['payment_id']}");
                if ($dispute['booking_id']) {
                    $conn->query("UPDATE bookings SET status = 'confirmed' WHERE id = {$dispute['booking_id']}");
                }
                $success_msg = "Dispute resolved! Booking confirmed as requested by student.";
                break;
                
            case 'process_refund':
                $refund_amt = ($refund_amount > 0) ? $refund_amount : $dispute['amount'];
                $conn->query("UPDATE payments SET status = 'refunded', notes = CONCAT(COALESCE(notes, ''), '$resolution_log\nRefund Amount: RM $refund_amt') WHERE id = {$dispute['payment_id']}");
                if ($dispute['booking_id']) {
                    $conn->query("UPDATE bookings SET status = 'cancelled', cancel_reason = 'Dispute resolved - Refund issued' WHERE id = {$dispute['booking_id']}");
                }
                $success_msg = "Refund of RM " . number_format($refund_amt, 2) . " processed.";
                break;
                
            case 'approve_reschedule':
                if ($new_booking_datetime && $dispute['booking_id']) {
                    $new_date = date('Y-m-d', strtotime($new_booking_datetime));
                    $new_time = date('H:i:s', strtotime($new_booking_datetime));
                    $conn->query("UPDATE bookings SET booking_date = '$new_date', booking_time = '$new_time', status = 'confirmed' WHERE id = {$dispute['booking_id']}");
                    $conn->query("UPDATE payments SET status = 'verified', verified_at = NOW(), notes = CONCAT(COALESCE(notes, ''), '$resolution_log') WHERE id = {$dispute['payment_id']}");
                    $success_msg = "Booking rescheduled to " . date('d M Y h:i A', strtotime($new_booking_datetime));
                } else {
                    throw new Exception("New date/time required");
                }
                break;
                
            case 'reject_dispute':
                $conn->query("UPDATE payments SET status = 'rejected', notes = CONCAT(COALESCE(notes, ''), '$resolution_log') WHERE id = {$dispute['payment_id']}");
                $success_msg = "Dispute rejected.";
                break;
                
            default:
                $success_msg = "Dispute resolution recorded.";
        }
        
        $conn->commit();
        
        sendDisputeResolutionEmail($conn, $dispute, $resolution_action, $resolution_message, $refund_amount, $new_booking_datetime);
        
        $_SESSION['success_message'] = $success_msg;
        header("Location: admin_payments.php");
        exit();
        
    } catch (Exception $e) {
        $conn->rollback();
        $error = "Error: " . $e->getMessage();
    }
}

function sendDisputeResolutionEmail($conn, $dispute, $action, $message, $refund_amount = 0, $new_datetime = null) {
    $mail = new PHPMailer(true);
    
    try {
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = SMTP_USER;
        $mail->Password = SMTP_PASS;
        $mail->SMTPSecure = 'tls';
        $mail->Port = 587;
        $mail->setFrom('sohisabella87@gmail.com', 'Kyoshi');
        $mail->addAddress($dispute['student_email'], $dispute['student_name']);
        $mail->isHTML(true);
        
        $action_text = '';
        $action_color = '';
        switch($action) {
            case 'approve_complete':
                $action_text = 'Booking Confirmed';
                $action_color = '#059669';
                break;
            case 'process_refund':
                $action_text = 'Refund Processed';
                $action_color = '#dc2626';
                break;
            case 'approve_reschedule':
                $action_text = 'Booking Rescheduled';
                $action_color = '#f59e0b';
                break;
            case 'reject_dispute':
                $action_text = 'Dispute Rejected';
                $action_color = '#6b7280';
                break;
        }
        
        $mail->Subject = "Dispute Resolution Update - Kyoshi";
        $mail->Body = "
        <div style='font-family:Segoe UI,sans-serif;max-width:550px;margin:auto;border:1px solid #e0e0e0;border-radius:16px;padding:24px;background:#fff;'>
            <div style='text-align:center;margin-bottom:24px;'>
                <h2 style='color:{$action_color};margin:0;'>{$action_text}</h2>
            </div>
            <p>Dear <strong>{$dispute['student_name']}</strong>,</p>
            <p>Your dispute has been reviewed and resolved.</p>
            <div style='background:#f8f9fa;padding:16px;border-radius:12px;margin:16px 0;'>
                <p><strong>What you requested:</strong> " . ucfirst(str_replace('_', ' ', $dispute['resolution_type'])) . "</p>
                <p><strong>Admin's resolution:</strong> " . ucfirst(str_replace('_', ' ', $action)) . "</p>
                " . ($refund_amount > 0 ? "<p><strong>Refund Amount:</strong> RM " . number_format($refund_amount, 2) . "</p>" : "") . "
                " . ($new_datetime ? "<p><strong>New Session Time:</strong> " . date('d M Y h:i A', strtotime($new_datetime)) . "</p>" : "") . "
                <hr>
                <p><strong>Message from Admin:</strong></p>
                <p style='background:white;padding:12px;border-radius:8px;'>" . nl2br(htmlspecialchars($message)) . "</p>
            </div>
            <hr>
            <p style='font-size:12px;color:#666;'>This is an automated message from Kyoshi.</p>
        </div>
        ";
        $mail->send();
    } catch (Exception $e) {
        error_log("Dispute resolution email failed: " . $mail->ErrorInfo);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
<meta http-equiv="Pragma" content="no-cache">
<meta http-equiv="Expires" content="0">
    <meta charset="UTF-8">
    <title>Resolve Dispute - Kyoshi Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../css/astyle.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: "Montserrat", "Open Sans", sans-serif;
            background: url('../assets/img/background3.jpg') no-repeat center top;
            background-size: cover;
            min-height: 100vh;
            position: relative;
            color: #1E1B2E;
            line-height: 1.45;
        }
        
        /* Main Content */
        .main-content {
            max-width: 1200px;
            margin: 0 auto;
            padding: 40px 24px;
        }
        
        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: #e2e8f0;
            color: #1d3156;
            padding: 8px 16px;
            border-radius: 40px;
            text-decoration: none;
            font-size: 13px;
            font-weight: 600;
            transition: 0.2s;
            margin-bottom: 24px;
        }
        
        .back-link:hover {
            background: #cbd5e1;
            transform: translateX(-3px);
        }
        
        .page-title {
            margin-bottom: 24px;
        }
        
        .page-title h1 {
            font-size: 1.8rem;
            font-weight: 700;
            color: #302E63;
        }
        
        .page-title p {
            font-size: 0.8rem;
            color: #7B6E8F;
            margin-top: 4px;
        }
        
        /* Card */
        .card {
            background: white;
            border-radius: 24px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.08);
            overflow: hidden;
            margin-bottom: 24px;
        }
        
        .card-header {
            padding: 20px 24px;
            border-bottom: 1px solid #eef2f7;
            background: #f8fafc;
        }
        
        .card-header h2 {
            font-size: 1.2rem;
            font-weight: 700;
            color: #1E1B2E;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .card-body {
            padding: 24px;
        }
        
        /* Dispute Info Grid */
        .info-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 16px;
        }
        
        .info-item {
            background: #f8fafc;
            padding: 16px;
            border-radius: 16px;
        }
        
        .info-item.full-width {
            grid-column: span 2;
        }
        
        .info-label {
            font-size: 11px;
            font-weight: 700;
            color: #7B6E8F;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 6px;
        }
        
        .info-value {
            font-size: 14px;
            font-weight: 600;
            color: #1E1B2E;
        }
        
        .student-request {
            background: #fef3c7;
            border-left: 4px solid #f59e0b;
            padding: 16px;
            border-radius: 12px;
            margin-top: 8px;
        }
        
        .student-request strong {
            color: #d97706;
        }
        
        .message-box {
            background: #fff3cd;
            padding: 16px;
            border-radius: 12px;
            margin-top: 8px;
            font-size: 13px;
            line-height: 1.5;
        }
        
        /* Form */
        .form-group {
            margin-bottom: 24px;
        }
        
        .form-group label {
            display: block;
            font-weight: 700;
            font-size: 13px;
            margin-bottom: 8px;
            color: #302E63;
        }
        
        .form-group label i {
            color: #E75A9B;
            margin-right: 6px;
        }
        
        .form-group select,
        .form-group textarea,
        .form-group input {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            font-family: 'Montserrat', sans-serif;
            font-size: 13px;
            transition: all 0.2s;
            background: white;
        }
        
        .form-group select:focus,
        .form-group textarea:focus,
        .form-group input:focus {
            outline: none;
            border-color: #E75A9B;
            box-shadow: 0 0 0 3px rgba(231, 90, 155, 0.1);
        }
        
        .form-group small {
            font-size: 11px;
            color: #7B6E8F;
            margin-top: 6px;
            display: block;
        }
        
        .hidden {
            display: none;
        }
        
        /* Buttons */
        .btn-primary {
            background: linear-gradient(135deg, #E75A9B, #C94F86);
            color: white;
            padding: 12px 28px;
            border: none;
            border-radius: 40px;
            cursor: pointer;
            font-weight: 700;
            font-size: 14px;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(231, 90, 155, 0.3);
        }
        
        .btn-secondary {
            background: #64748b;
            color: white;
            padding: 12px 28px;
            border: none;
            border-radius: 40px;
            cursor: pointer;
            font-weight: 700;
            font-size: 14px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-left: 12px;
            transition: all 0.2s;
        }
        
        .btn-secondary:hover {
            background: #475569;
            transform: translateY(-2px);
        }
        
        .alert-error {
            background: #fee2e2;
            color: #dc2626;
            padding: 12px 16px;
            border-radius: 12px;
            margin-bottom: 20px;
            border-left: 4px solid #dc2626;
        }
        
        /* Alert */
        .alert-success {
            background: #d4edda;
            color: #155724;
            padding: 12px 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            border-left: 4px solid #28a745;
        }
        
        @media (max-width: 768px) {
            .info-grid {
                grid-template-columns: 1fr;
            }
            .info-item.full-width {
                grid-column: span 1;
            }
            .main-content {
                padding: 20px 16px;
            }
            .btn-primary, .btn-secondary {
                padding: 10px 20px;
                font-size: 13px;
            }
        }
    </style>
</head>
<body>
<div class="main-content">
    <a href="admin_payments.php" class="back-link">
        <i class="bi bi-arrow-left"></i> Back to Payments
    </a>
    
    <div class="page-title">
        <h1><i class="bi bi-flag"></i> Resolve Payment Dispute</h1>
        <p>Review the dispute details and select an appropriate resolution action</p>
    </div>
    
    <?php if (isset($_SESSION['success_message'])): ?>
        <div class="alert-success">
            <i class="bi bi-check-circle-fill"></i> <?= $_SESSION['success_message'] ?>
            <?php unset($_SESSION['success_message']); ?>
        </div>
    <?php endif; ?>
    
    <?php if (isset($error)): ?>
        <div class="alert-error">
            <i class="bi bi-exclamation-triangle-fill"></i> <?= $error ?>
        </div>
    <?php endif; ?>
    
    <!-- Dispute Information Card -->
    <div class="card">
        <div class="card-header">
            <h2><i class="bi bi-chat-dots"></i> Dispute Information</h2>
        </div>
        <div class="card-body">
            <div class="info-grid">
                <div class="info-item">
                    <div class="info-label"><i class="bi bi-person"></i> Student</div>
                    <div class="info-value"><?= htmlspecialchars($dispute['student_name']) ?></div>
                    <div class="info-value" style="font-size: 12px; font-weight: normal;"><?= htmlspecialchars($dispute['student_email']) ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label"><i class="bi bi-person-badge"></i> Tutor</div>
                    <div class="info-value"><?= htmlspecialchars($dispute['tutor_name']) ?></div>
                    <div class="info-value" style="font-size: 12px; font-weight: normal;"><?= htmlspecialchars($dispute['tutor_email']) ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label"><i class="bi bi-credit-card"></i> Amount</div>
                    <div class="info-value">RM <?= number_format($dispute['amount'], 2) ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label"><i class="bi bi-calendar"></i> Booking</div>
                    <div class="info-value"><?= htmlspecialchars($dispute['language']) ?></div>
                    <div class="info-value" style="font-size: 12px; font-weight: normal;">
                        <?= date('d M Y', strtotime($dispute['booking_date'])) ?> at <?= date('h:i A', strtotime($dispute['booking_time'])) ?>
                    </div>
                </div>
                <div class="info-item full-width">
                    <div class="info-label"><i class="bi bi-chat-right-quote"></i> Student's Requested Resolution</div>
                    <div class="student-request">
                        <?php 
                        switch($dispute['resolution_type']) {
                            case 'refund': echo '<i class="bi bi-cash-stack"></i> <strong>Full Refund</strong> - Student wants money back'; break;
                            case 'reschedule': echo '<i class="bi bi-calendar-plus"></i> <strong>Reschedule Booking</strong> - Student wants different time'; break;
                            case 'complete_booking': echo '<i class="bi bi-check-circle"></i> <strong>Complete Current Booking</strong> - Student wants booking confirmed'; break;
                            default: echo ucfirst(str_replace('_', ' ', $dispute['resolution_type']));
                        }
                        ?>
                    </div>
                </div>
                <div class="info-item full-width">
                    <div class="info-label"><i class="bi bi-envelope"></i> Student Message</div>
                    <div class="message-box">
                        <?= nl2br(htmlspecialchars($dispute['message'])) ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Resolution Form Card -->
    <div class="card">
        <div class="card-header">
            <h2><i class="bi bi-gear"></i> Resolution Action</h2>
        </div>
        <div class="card-body">
            <form method="POST">
                <div class="form-group">
                    <label><i class="bi bi-list-check"></i> Admin Resolution Action</label>
                    <select name="resolution_action" id="resolutionAction" required onchange="showFields()">
                        <option value="">Select action to take...</option>
                        <?php if ($dispute['resolution_type'] == 'complete_booking'): ?>
                            <option value="approve_complete">✓ Approve & Confirm Booking (matches student's request)</option>
                        <?php endif; ?>
                        <?php if ($dispute['resolution_type'] == 'refund'): ?>
                            <option value="process_refund">💰 Process Full Refund (matches student's request)</option>
                        <?php endif; ?>
                        <?php if ($dispute['resolution_type'] == 'reschedule'): ?>
                            <option value="approve_reschedule">📅 Approve Reschedule (matches student's request)</option>
                        <?php endif; ?>
                        <option value="reject_dispute">❌ Reject Dispute (student's claim is invalid)</option>
                    </select>
                    <small>Based on the student's request above, select the appropriate action</small>
                </div>
                
                <div id="refundFields" class="hidden">
                    <div class="form-group">
                        <label>Refund Amount (RM)</label>
                        <input type="number" name="refund_amount" step="0.01" placeholder="Leave empty for full refund" value="<?= $dispute['amount'] ?>">
                        <small>Student requested a refund of RM <?= number_format($dispute['amount'], 2) ?></small>
                    </div>
                </div>
                
                <div id="rescheduleFields" class="hidden">
                    <div class="form-group">
                        <label>New Booking Date & Time</label>
                        <input type="datetime-local" name="new_booking_datetime" class="form-control">
                        <small>Set new time for the rescheduled session as requested by student</small>
                    </div>
                </div>
                
                <div class="form-group">
                    <label><i class="bi bi-envelope"></i> Message to Student</label>
                    <textarea name="resolution_message" rows="4" placeholder="Explain how this dispute was resolved..." required></textarea>
                    <small>This message will be emailed to the student</small>
                </div>
                
                <div style="display: flex; gap: 12px; margin-top: 24px;">
                    <button type="submit" name="resolve_dispute" class="btn-primary">
                        <i class="bi bi-check2-all"></i> Apply Resolution
                    </button>
                    <a href="admin_payments.php" class="btn-secondary">
                        <i class="bi bi-x-lg"></i> Cancel
                    </a>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function showFields() {
    const action = document.getElementById('resolutionAction').value;
    const refundFields = document.getElementById('refundFields');
    const rescheduleFields = document.getElementById('rescheduleFields');
    
    refundFields.classList.toggle('hidden', action !== 'process_refund');
    rescheduleFields.classList.toggle('hidden', action !== 'approve_reschedule');
}
</script>
<script>
history.pushState(null, null, location.href);
window.addEventListener('popstate', function() {
    window.location.href = 'login.php';
});
</script>
</body>
</html>