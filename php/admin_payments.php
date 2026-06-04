<?php
session_start();
include 'config.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require '../vendor/autoload.php';

// Email sending function
function sendPaymentStatusEmail($conn, $payment_id, $status, $reason = '') {
    // Get payment details with student and booking info
    $stmt = $conn->prepare("
        SELECT p.*, 
               b.booking_date, b.booking_time,
               s.fullname as student_name, s.email as student_email,
               t.fullname as tutor_name
        FROM payments p
        LEFT JOIN bookings b ON p.booking_id = b.id
        LEFT JOIN users s ON p.student_id = s.id
        LEFT JOIN users t ON p.tutor_id = t.id
        WHERE p.id = ?
    ");
    $stmt->bind_param("i", $payment_id);
    $stmt->execute();
    $payment = $stmt->get_result()->fetch_assoc();
    
    if (!$payment || empty($payment['student_email'])) {
        return false;
    }
    
    $mail = new PHPMailer(true);
    
    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;  // Make sure SMTP_USER is defined in config.php
        $mail->Password   = SMTP_PASS;  // Make sure SMTP_PASS is defined in config.php
        $mail->SMTPSecure = 'tls';
        $mail->Port       = 587;
        $mail->setFrom('sohisabella87@gmail.com', 'Kyoshi');
        $mail->addAddress($payment['student_email'], $payment['student_name']);
        
        $mail->isHTML(true);
        
        if ($status == 'verified') {
            $mail->Subject = 'Payment Verified - Kyoshi';
            $mail->Body = "
                <div style='font-family:Segoe UI,sans-serif;max-width:550px;margin:auto;border:1px solid #e0e0e0;border-radius:16px;padding:24px;'>
                    <div style='text-align:center;margin-bottom:24px;'>
                        <h2 style='color:#059669;margin-top:8px;'>Payment Verified ✓</h2>
                    </div>
                    <p>Dear <strong>" . htmlspecialchars($payment['student_name']) . "</strong>,</p>
                    <p>Your payment has been <strong style='color:#059669;'>successfully verified</strong>!</p>
                    <div style='background:#f0fdf4;padding:16px;border-radius:12px;margin:16px 0;'>
                        <p><strong>Receipt Number:</strong> " . htmlspecialchars($payment['receipt_number']) . "</p>
                        <p><strong>Amount Paid:</strong> RM " . number_format($payment['amount'], 2) . "</p>
                        <p><strong>Payment Method:</strong> " . ucfirst(str_replace('_', ' ', $payment['payment_method'])) . "</p>
                        <p><strong>Booking Date:</strong> " . date('d M Y', strtotime($payment['booking_date'])) . "</p>
                        <p><strong>Tutor:</strong> " . htmlspecialchars($payment['tutor_name']) . "</p>
                    </div>
                    <p>Your booking is now <strong>confirmed</strong>.</p>
                    <hr>
                    <p style='font-size:12px;color:#666;'>This is an automated message from Kyoshi.</p>
                </div>
            ";
        } else {
            $mail->Subject = 'Payment Rejected - Kyoshi';
            $mail->Body = "
                <div style='font-family:Segoe UI,sans-serif;max-width:550px;margin:auto;border:1px solid #e0e0e0;border-radius:16px;padding:24px;'>
                    <div style='text-align:center;margin-bottom:24px;'>
                        <h2 style='color:#dc2626;margin-top:8px;'>Payment Rejected ✗</h2>
                    </div>
                    <p>Dear <strong>" . htmlspecialchars($payment['student_name']) . "</strong>,</p>
                    <p>Your payment has been <strong style='color:#dc2626;'>rejected</strong>.</p>
                    <div style='background:#fef2f2;padding:16px;border-radius:12px;margin:16px 0;'>
                        <p><strong>Amount:</strong> RM " . number_format($payment['amount'], 2) . "</p>
                        <p><strong>Reason:</strong> " . htmlspecialchars($reason) . "</p>
                    </div>
                    <p>Please check your payment details and try again.</p>
                    <hr>
                    <p style='font-size:12px;color:#666;'>This is an automated message from Kyoshi.</p>
                </div>
            ";
        }
        
        $mail->send();
        return true;
        
    } catch (Exception $e) {
        error_log("Email failed: " . $mail->ErrorInfo);
        return false;
    }
}
$assetBase = '../assets/img';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

$adminID = $_SESSION['user_id'];

// Get admin info
$stmt = $conn->prepare("SELECT * FROM users WHERE id = ? AND role = 'admin'");
$stmt->bind_param("i", $adminID);
$stmt->execute();
$admin = $stmt->get_result()->fetch_assoc();

$displayName = $admin['fullname'];
$profilePic = !empty($admin['profile_pic'])
    ? '../uploads/profiles/' . $admin['profile_pic']
    : $assetBase . '/profile-admin.png';

// Get counts for sidebar
$totalTutors = $conn->query("SELECT COUNT(*) as count FROM users WHERE role = 'tutor'")->fetch_assoc()['count'];
$totalStudents = $conn->query("SELECT COUNT(*) as count FROM users WHERE role = 'student'")->fetch_assoc()['count'];
$pendingPayments = $conn->query("SELECT COUNT(*) as count FROM payments WHERE status = 'pending'")->fetch_assoc()['count'];
$pendingDisputes = $conn->query("SELECT COUNT(*) as count FROM disputes WHERE status = 'pending'")->fetch_assoc()['count'] ?? 0;
$pendingPayouts = $conn->query("SELECT COUNT(*) as count FROM payout_requests WHERE status = 'pending'")->fetch_assoc()['count'] ?? 0;

// Get message from session
$message = '';
$messageType = '';
if (isset($_SESSION['success_message'])) {
    $message = $_SESSION['success_message'];
    $messageType = 'success';
    unset($_SESSION['success_message']);
}
if (isset($_SESSION['error_message'])) {
    $message = $_SESSION['error_message'];
    $messageType = 'error';
    unset($_SESSION['error_message']);
}

// Handle verify payment
if (isset($_POST['verify_payment'])) {
    $payment_id = intval($_POST['payment_id']);
    $receipt_number = 'RCP-' . date('Ymd') . '-' . str_pad($payment_id, 6, '0', STR_PAD_LEFT);
    
    $conn->query("UPDATE payments SET status = 'verified', receipt_number = '$receipt_number', verified_at = NOW() WHERE id = $payment_id");
    
    $payment = $conn->query("SELECT booking_id FROM payments WHERE id = $payment_id")->fetch_assoc();
    if ($payment && $payment['booking_id']) {
        $conn->query("UPDATE bookings SET status = 'confirmed' WHERE id = {$payment['booking_id']}");
    }

    sendPaymentStatusEmail($conn, $payment_id, 'verified');
    
    $_SESSION['success_message'] = "Payment verified successfully! Receipt: $receipt_number";
    header("Location: admin_payments.php");
    exit();
}

// Handle reject payment
if (isset($_POST['reject_payment'])) {
    $payment_id = intval($_POST['payment_id']);
    $rejection_reason = $conn->real_escape_string($_POST['rejection_reason'] ?? '');
    
    $conn->query("UPDATE payments SET status = 'rejected', notes = '$rejection_reason' WHERE id = $payment_id");
    sendPaymentStatusEmail($conn, $payment_id, 'rejected', $rejection_reason);
    $_SESSION['error_message'] = "Payment rejected: $rejection_reason";
    header("Location: admin_payments.php");
    exit();
}

// Handle resolve dispute
if (isset($_POST['resolve_dispute'])) {
    $payment_id = intval($_POST['payment_id']);
    $resolution = $conn->real_escape_string($_POST['resolution'] ?? '');
    
    $conn->query("UPDATE payments SET status = 'verified', verified_at = NOW(), notes = CONCAT(COALESCE(notes, ''), '\n[Resolved: $resolution]') WHERE id = $payment_id");
    
    $payment = $conn->query("SELECT booking_id FROM payments WHERE id = $payment_id")->fetch_assoc();
    if ($payment && $payment['booking_id']) {
        $conn->query("UPDATE bookings SET status = 'confirmed' WHERE id = {$payment['booking_id']}");
    }
    sendPaymentStatusEmail($conn, $payment_id, 'verified');
    $_SESSION['success_message'] = "Dispute resolved! Payment verified.";
    header("Location: admin_payments.php");
    exit();
}

// Handle bulk payment verification
if (isset($_POST['verify_bulk_payment'])) {
    $receipt_number = $conn->real_escape_string($_POST['receipt_number']);
    
    $conn->begin_transaction();
    
    try {
        $conn->query("UPDATE payments SET status = 'verified', verified_at = NOW() WHERE receipt_number = '$receipt_number' AND status = 'pending'");
        
        $booking_result = $conn->query("SELECT booking_id FROM payments WHERE receipt_number = '$receipt_number'");
        $booking_ids = [];
        while ($row = $booking_result->fetch_assoc()) {
            $booking_ids[] = $row['booking_id'];
        }
        
        if (!empty($booking_ids)) {
            $ids_string = implode(',', $booking_ids);
            $conn->query("UPDATE bookings SET status = 'confirmed' WHERE id IN ($ids_string)");
        }
        
        $conn->commit();
        
        $_SESSION['success_message'] = "Bulk payment verified! " . count($booking_ids) . " bookings confirmed. Receipt: $receipt_number";
        header("Location: admin_payments.php");
        exit();
        
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['error_message'] = "Error verifying bulk payment: " . $e->getMessage();
        header("Location: admin_payments.php");
        exit();
    }
}

// Handle bulk payment rejection
if (isset($_POST['reject_bulk_payment'])) {
    $receipt_number = $conn->real_escape_string($_POST['receipt_number']);
    $rejection_reason = $conn->real_escape_string($_POST['rejection_reason'] ?? '');
    
    $conn->begin_transaction();
    
    try {
        $conn->query("UPDATE payments SET status = 'rejected', notes = '$rejection_reason' WHERE receipt_number = '$receipt_number' AND status = 'pending'");
        
        $booking_result = $conn->query("SELECT booking_id FROM payments WHERE receipt_number = '$receipt_number'");
        $booking_ids = [];
        while ($row = $booking_result->fetch_assoc()) {
            $booking_ids[] = $row['booking_id'];
        }
        
        if (!empty($booking_ids)) {
            $ids_string = implode(',', $booking_ids);
            $conn->query("UPDATE bookings SET status = 'cancelled', cancel_reason = 'Payment rejected: $rejection_reason' WHERE id IN ($ids_string)");
        }
        
        $conn->commit();
        
        $_SESSION['error_message'] = "Bulk payment rejected! " . count($booking_ids) . " bookings cancelled. Receipt: $receipt_number";
        header("Location: admin_payments.php");
        exit();
        
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['error_message'] = "Error rejecting bulk payment: " . $e->getMessage();
        header("Location: admin_payments.php");
        exit();
    }
}

// Handle bulk verify urgent payments
if (isset($_POST['bulk_verify_urgent'])) {
    $conn->query("
        UPDATE payments p
        JOIN bookings b ON p.booking_id = b.id
        SET p.status = 'verified', p.verified_at = NOW(),
            p.notes = CONCAT(COALESCE(p.notes, ''), '\n[Bulk urgent verified: ', NOW(), ']')
        WHERE p.status = 'pending'
        AND CONCAT(b.booking_date, ' ', b.booking_time) > NOW()
        AND TIMESTAMPDIFF(HOUR, NOW(), CONCAT(b.booking_date, ' ', b.booking_time)) <= 24
    ");
    
    $conn->query("
        UPDATE bookings b
        JOIN payments p ON b.id = p.booking_id
        SET b.status = 'confirmed'
        WHERE p.status = 'verified' AND p.notes LIKE '%Bulk urgent verified%'
    ");
    
    $_SESSION['success_message'] = "All urgent payments have been verified!";
    header("Location: admin_payments.php");
    exit();
}

// Get filter
$status_filter = $_GET['status'] ?? 'all';
$method_filter = $_GET['method'] ?? 'all';
$year_filter = $_GET['year'] ?? date('Y');
$month_filter = $_GET['month'] ?? '';

$where = "1=1";
if ($status_filter != 'all') {
    $where .= " AND p.status = '$status_filter'";
}
if ($method_filter != 'all') {
    $where .= " AND p.payment_method = '$method_filter'";
}
if ($year_filter != 'all') {
    $where .= " AND YEAR(p.created_at) = '$year_filter'";
}
if ($month_filter != '') {
    $where .= " AND MONTH(p.created_at) = '$month_filter'";
}

// Get all payments with student and tutor info
$payments = $conn->query("
    SELECT p.*, 
           b.booking_date, b.booking_time, b.status as booking_status,
           s.fullname as student_name,
           t.fullname as tutor_name,
           DATE_FORMAT(p.created_at, '%Y-%m') as payment_month
    FROM payments p
    LEFT JOIN bookings b ON p.booking_id = b.id
    LEFT JOIN users s ON p.student_id = s.id
    LEFT JOIN users t ON p.tutor_id = t.id
    WHERE $where
    ORDER BY p.created_at DESC
");

// Get urgent payments for priority queue
$urgentPayments = $conn->query("
    SELECT p.*, b.booking_date, b.booking_time,
           TIMESTAMPDIFF(HOUR, NOW(), CONCAT(b.booking_date, ' ', b.booking_time)) as hours_until_class,
           s.fullname as student_name
    FROM payments p
    JOIN bookings b ON p.booking_id = b.id
    JOIN users s ON p.student_id = s.id
    WHERE p.status = 'pending'
    AND p.payment_method IN ('online_banking', 'duitnow')
    AND CONCAT(b.booking_date, ' ', b.booking_time) > NOW()
    AND TIMESTAMPDIFF(HOUR, NOW(), CONCAT(b.booking_date, ' ', b.booking_time)) <= 24
    ORDER BY hours_until_class ASC
");

// Get available years for filter
$years_result = $conn->query("SELECT DISTINCT YEAR(created_at) as yr FROM payments ORDER BY yr DESC");
$available_years = [];
while ($row = $years_result->fetch_assoc()) {
    $available_years[] = $row['yr'];
}

// Get counts for filters
$pendingCount = $conn->query("SELECT COUNT(*) as count FROM payments WHERE status = 'pending'")->fetch_assoc()['count'];
$verifiedCount = $conn->query("SELECT COUNT(*) as count FROM payments WHERE status = 'verified'")->fetch_assoc()['count'];
$disputedCount = $conn->query("SELECT COUNT(*) as count FROM payments WHERE status = 'disputed'")->fetch_assoc()['count'];
$rejectedCount = $conn->query("SELECT COUNT(*) as count FROM payments WHERE status = 'rejected'")->fetch_assoc()['count'];

function e($value) {
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kyoshi | Payments</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: 'Montserrat', sans-serif;
            background: url('../assets/img/background3.jpg') no-repeat center top;
            background-size: cover;
            min-height: 100vh;
            color: #1E1B2E;
            overflow-x: hidden;
        }
        
        body::before {
            content: "";
            position: fixed;
            inset: 0;
            background: radial-gradient(circle at 7% 10%, rgba(231,90,155,.32), transparent 24%),
                        radial-gradient(circle at 90% 8%, rgba(255,195,216,.42), transparent 26%);
            z-index: -1;
        }

        .sidebar {
            position: fixed;
            left: 0;
            top: 0;
            width: 260px;
            height: 100vh;
            background: #272754;
            color: #E8E4F0;
            overflow-y: auto;
            z-index: 1000;
            transition: transform 0.3s ease;
            display: flex;
            flex-direction: column;
        }
        
        .sidebar.closed { transform: translateX(-100%); }
        
        .sidebar-header { padding: 24px 20px; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .nav-menu { flex: 1; padding: 16px 0; }
        .sidebar-footer { padding: 16px 20px; border-top: 1px solid rgba(255,255,255,0.15); display: flex; justify-content: space-between; align-items: center; }
        
        .nav-item {
            padding: 10px 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            color: #D4CFE8;
            text-decoration: none;
            border-left: 3px solid transparent;
            font-size: 0.85rem;
        }
        
        .nav-item:hover, .nav-item.active {
            background: rgba(255,255,255,0.1);
            border-left-color: #B26EA7;
            color: white;
        }
        
        .nav-item i { width: 20px; }
        .nav-section-label {
            padding: 10px 20px 4px 20px;
            font-size: 0.65rem;
            font-weight: 600;
            color: #B26EA7;
            text-transform: uppercase;
        }
        
        .nav-badge {
            margin-left: auto;
            font-size: 0.65rem;
            background: rgba(178, 110, 167, 0.25);
            padding: 2px 8px;
            border-radius: 30px;
        }
        .nav-badge.pending { background: rgba(245, 158, 11, 0.25); color: #F59E0B; }
        .nav-badge.dispute { background: rgba(220, 38, 38, 0.25); color: #FFA3A3; }
        
        .main-content {
            margin-left: 260px;
            padding: 20px 24px;
            transition: margin-left 0.3s ease;
        }
        
        .top-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
        }
        
        .page-title h1 {
            font-size: 1.5rem;
            font-weight: 800;
            color: #302E63;
        }
        
        .page-title p {
            font-size: 0.75rem;
            color: #7B6E8F;
            margin-top: 4px;
        }
        
        .menu-toggle {
            background: #272754;
            color: white;
            border: none;
            padding: 8px 12px;
            border-radius: 10px;
            cursor: pointer;
            display: none;
        }
        
        .admin-profile {
            display: flex;
            align-items: center;
            gap: 10px;
            background: white;
            padding: 6px 14px 6px 10px;
            border-radius: 50px;
            cursor: pointer;
            border: 1px solid #E4DCF0;
        }
        
        .admin-profile img { width: 36px; height: 36px; border-radius: 50%; object-fit: cover; }
        .admin-profile span { font-weight: 600; font-size: 0.8rem; color: #302E63; }
        
        .admin-info { display: flex; align-items: center; gap: 10px; }
        .footer-avatar { width: 36px; height: 36px; border-radius: 50%; object-fit: cover; }
        .admin-name { font-size: 0.75rem; font-weight: 600; color: white; }
        .logout-icon { color: #FFA3A3; text-decoration: none; font-size: 1.2rem; }
        
        .brand-wrapper { display: flex; align-items: center; gap: 10px; }
        .brand-icon { width: 40px; height: 40px; object-fit: contain; }
        .brand-title h1 { font-size: 1.2rem; color: white; }
        .admin-space-text { font-size: 0.5rem; color: #e7c7f7; }
        
        .relative { position: relative; }
        
        .dropdown {
            position: absolute;
            top: calc(100% + 10px);
            right: 0;
            width: 180px;
            background: white;
            border-radius: 12px;
            display: none;
            border: 1px solid #E4DCF0;
            box-shadow: 0 15px 35px rgba(0,0,0,0.15);
            z-index: 1000;
        }
        
        .dropdown a {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 16px;
            text-decoration: none;
            color: #1E1B2E;
            font-size: 12px;
        }
        .dropdown a:hover { background: #F4F0F8; }
        
        .sidebar-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(126, 96, 223, 0.5);
            z-index: 999;
            display: none;
        }
        .sidebar-overlay.active { display: block; }
        
        .filter-bar {
            background: white;
            border-radius: 16px;
            padding: 16px 20px;
            margin-bottom: 24px;
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            align-items: center;
        }
        
        .filter-select {
            padding: 8px 16px;
            border-radius: 40px;
            border: 1px solid #e2e8f0;
            background: #f8f9fa;
            font-size: 13px;
            outline: none;
            cursor: pointer;
            min-width: 140px;
        }
        
        .filter-btn {
            background: #E75A9B;
            color: white;
            border: none;
            padding: 8px 20px;
            border-radius: 40px;
            cursor: pointer;
            font-weight: 600;
            font-size: 13px;
        }
        
        .urgent-banner {
            background: linear-gradient(135deg, #dc2626 0%, #b91c1c 100%);
            color: white;
            padding: 20px;
            border-radius: 15px;
            margin-bottom: 25px;
            box-shadow: 0 4px 15px rgba(220,38,38,0.3);
        }
        
        .payments-container {
            background: white;
            border-radius: 20px;
            overflow-x: auto;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1200px;
        }
        
        th {
            text-align: left;
            padding: 14px 12px;
            background: #f8f9fa;
            color: #475569;
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: uppercase;
            border-bottom: 1px solid #e2e8f0;
        }
        
        td {
            padding: 14px 12px;
            border-bottom: 1px solid #f1f5f9;
            font-size: 0.8rem;
            vertical-align: middle;
        }
        
        tr:hover { background: #fef9f5; }
        
        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
        }
        
        .status-pending { background: #fef3c7; color: #d97706; }
        .status-verified { background: #d1fae5; color: #059669; }
        .status-rejected { background: #fee2e2; color: #dc2626; }
        .status-disputed { background: #fed7aa; color: #9a3412; }
        
        .method-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 0.65rem;
            font-weight: 600;
        }
        
        .method-stripe { background: #635bff; color: white; }
        .method-online_banking { background: #059669; color: white; }
        .method-duitnow { background: #7c3aed; color: white; }
        
        .proof-image {
            width: 50px;
            height: 50px;
            object-fit: cover;
            border-radius: 8px;
            cursor: pointer;
            border: 1px solid #e2e8f0;
        }
        
        .btn-verify { background: #28a745; color: white; border: none; padding: 6px 12px; border-radius: 20px; cursor: pointer; font-size: 0.7rem; font-weight: 600; }
        .btn-reject { background: #dc3545; color: white; border: none; padding: 6px 12px; border-radius: 20px; cursor: pointer; font-size: 0.7rem; font-weight: 600; }
        .btn-dispute { background: #f59e0b; color: white; border: none; padding: 6px 12px; border-radius: 20px; cursor: pointer; font-size: 0.7rem; font-weight: 600; }
        .btn-view { background: #E75A9B; color: white; border: none; padding: 6px 12px; border-radius: 20px; cursor: pointer; font-size: 0.7rem; font-weight: 600; }
        .btn-urgent { background: #dc2626; color: white; border: none; padding: 8px 16px; border-radius: 20px; cursor: pointer; font-size: 0.75rem; font-weight: 600; }
        
        .action-cell { display: flex; gap: 6px; flex-wrap: wrap; }
        
        .message { padding: 12px 20px; border-radius: 12px; margin-bottom: 20px; }
        .message.success { background: #d4edda; color: #155724; }
        .message.error { background: #f8d7da; color: #721c24; }
        
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 2000;
            display: flex;
            align-items: center;
            justify-content: center;
            display: none;
        }
        
        .modal-container {
            background: white;
            border-radius: 24px;
            max-width: 500px;
            width: 90%;
            overflow: hidden;
        }
        
        .modal-header { padding: 20px 24px; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; }
        .modal-body { padding: 24px; }
        .modal-footer { padding: 16px 24px; border-top: 1px solid #e2e8f0; display: flex; justify-content: flex-end; gap: 12px; }
        
        .form-group { margin-bottom: 16px; }
        .form-group label { display: block; font-weight: 600; font-size: 0.8rem; margin-bottom: 6px; }
        .form-group input, .form-group textarea { width: 100%; padding: 10px; border: 1px solid #e2e8f0; border-radius: 12px; font-family: inherit; }
        
        .hours-warning { font-size: 11px; color: #dc2626; margin-top: 4px; }
        .date-cell { font-size: 11px; color: #666; }
        .date-cell strong { font-size: 13px; color: #333; display: block; }
        
        /* Month separator styling */
        .month-separator {
            background: linear-gradient(135deg, #E75A9B, #C94F86);
            color: white;
            font-weight: 800;
            font-size: 14px;
        }
        .month-separator td {
            padding: 10px 16px;
            background: linear-gradient(135deg, #E75A9B, #C94F86);
            color: white;
        }
        .month-separator i {
            margin-right: 8px;
        }
        
        /* Bulk row styling - collapsible */
        .bulk-parent-row {
            background-color: #fef9f5;
            cursor: pointer;
        }
        .bulk-parent-row:hover {
            background-color: #fef0e8;
        }
        .bulk-detail-row {
            background-color: #fffaf5;
        }
        .bulk-detail-row td {
            padding: 0 !important;
        }
        .bulk-detail-content {
            padding: 20px 30px;
            background: #fff8f0;
            border-top: 1px solid #ffe0d0;
            border-bottom: 1px solid #ffe0d0;
        }
        .bulk-badge {
            display: inline-block;
            background: #E75A9B;
            color: white;
            font-size: 9px;
            padding: 2px 6px;
            border-radius: 10px;
            margin-left: 5px;
            font-weight: 600;
            vertical-align: middle;
        }
        .detail-table {
    width: 100%;
    border-collapse: collapse;
    min-width: 800px;
}

.detail-table th {
    background: #f0f0f0;
    padding: 20px 16px;
    font-size: 12px;
    font-weight: 700;
    text-align: center;
    vertical-align: middle;
}

.detail-table td {
    padding: 30px 16px;
    border-bottom: 1px solid #eee;
    vertical-align: middle;
    text-align: center;
}
.detail-table td div {
    display: flex;
    flex-direction: column;
    justify-content: center;
}

        .rotate-icon {
            transition: transform 0.2s ease;
            display: inline-block;
        }
        .rotate-icon.rotated {
            transform: rotate(90deg);
        }

        /* Auto-hide message */
.message {
    position: relative;
    animation: slideDown 0.3s ease;
}

.message.fade-out {
    animation: fadeOut 0.5s ease forwards;
}

@keyframes slideDown {
    from {
        opacity: 0;
        transform: translateY(-20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

@keyframes fadeOut {
    from {
        opacity: 1;
        transform: translateY(0);
    }
    to {
        opacity: 0;
        transform: translateY(-20px);
        display: none;
    }
}
    </style>
</head>
<body>

<div class="sidebar-overlay" id="sidebarOverlay"></div>
<aside class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <div class="brand-wrapper">
            <img src="<?= e($assetBase) ?>/logo.png" alt="Kyoshi" class="brand-icon">
            <div class="brand-title">
                <h1>Kyoshi</h1>
                <span class="admin-space-text">Admin Space</span>
            </div>
        </div>
    </div>
    <nav class="nav-menu">
        <div class="nav-section">
            <div class="nav-section-label">MAIN</div>
            <a href="admin_dashboard.php" class="nav-item"><i class="bi bi-speedometer2"></i><span>Dashboard</span></a>
        </div>
        <div class="nav-section">
            <div class="nav-section-label">USERS</div>
            <a href="admin_tutors.php" class="nav-item"><i class="bi bi-person-badge"></i><span>Tutors</span><span class="nav-badge"><?= $totalTutors ?></span></a>
            <a href="admin_verify_tutors.php" class="nav-item"><i class="bi bi-person-check"></i><span>Verify Tutors</span></a>
            <a href="admin_students.php" class="nav-item"><i class="bi bi-person"></i><span>Students</span><span class="nav-badge"><?= $totalStudents ?></span></a>
        </div>
        <div class="nav-section">
            <div class="nav-section-label">FINANCE</div>
            <a href="admin_payments.php" class="nav-item active"><i class="bi bi-credit-card"></i><span>Payments</span><span class="nav-badge pending"><?= $pendingPayments ?></span></a>
            <a href="admin_payouts.php" class="nav-item"><i class="bi bi-cash-stack"></i><span>Payouts</span><span class="nav-badge"><?= $pendingPayouts ?></span></a>
        </div>
        <div class="nav-section">
            <div class="nav-section-label">BOOKINGS</div>
            <a href="admin_bookings.php" class="nav-item"><i class="bi bi-calendar-check"></i><span>Bookings</span></a>
            <a href="admin_disputes.php" class="nav-item"><i class="bi bi-flag"></i><span>Disputes</span><span class="nav-badge pending"><?= $pendingDisputes ?></span></a>
        </div>
        <div class="nav-section">
            <div class="nav-section-label">REPORTS</div>
            <a href="admin_reports.php" class="nav-item"><i class="bi bi-graph-up"></i><span>Analytics</span></a>
        </div>
    </nav>
    <div class="sidebar-footer">
        <div class="admin-info">
            <img src="<?= e($profilePic) ?>" alt="Admin" class="footer-avatar">
            <span class="admin-name"><?= e($displayName) ?></span>
        </div>
        <a href="logout.php" class="logout-icon"><i class="bi bi-box-arrow-right"></i></a>
    </div>
</aside>

<div class="main-content" id="mainContent">
    <div class="top-bar">
        <button class="menu-toggle" id="menuToggle"><i class="bi bi-list"></i> Menu</button>
        <div class="page-title">
            <h1>Payments</h1>
        </div>
        <div class="relative">
            <button class="admin-profile" onclick="toggleDropdown()">
                <img src="<?= e($profilePic) ?>" alt="Admin">
                <span><?= e($displayName) ?></span>
                <i class="bi bi-chevron-down"></i>
            </button>
            <div class="dropdown" id="profileDropdown">
                <a href="admin_profile.php"><i class="bi bi-person-circle"></i> My Profile</a>
                <hr>
                <a href="logout.php" style="color:#dc2626;"><i class="bi bi-box-arrow-right"></i> Logout</a>
            </div>
        </div>
    </div>

    <?php if ($message): ?>
    <div class="message <?= $messageType ?>"><?= $message ?></div>
    <?php endif; ?>

    <!-- URGENT PRIORITY QUEUE -->
    <?php if ($urgentPayments && $urgentPayments->num_rows > 0): ?>
    <div class="urgent-banner">
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;">
            <div>
                <i class="bi bi-alarm" style="font-size: 28px;"></i>
                <strong style="font-size: 18px; margin-left: 10px;">🚨 URGENT - Class Starting Soon!</strong>
                <p style="margin: 8px 0 0 0; font-size: 14px; opacity: 0.95;">
                    <?= $urgentPayments->num_rows ?> payment(s) need verification before class starts!
                </p>
            </div>
            <form method="POST" onsubmit="return confirm('Verify all urgent payments?')">
                <button type="submit" name="bulk_verify_urgent" class="btn-urgent">
                    <i class="bi bi-check2-all"></i> Verify All Urgent
                </button>
            </form>
        </div>
        
        <div style="margin-top: 15px; max-height: 300px; overflow-y: auto;">
            <table style="width: 100%; background: rgba(255,255,255,0.1); border-radius: 10px; overflow: hidden;">
                <thead>
                    <tr style="border-bottom: 1px solid rgba(255,255,255,0.2);">
                        <th style="padding: 10px;">Student</th>
                        <th style="padding: 10px;">Class Date & Time</th>
                        <th style="padding: 10px;">Amount</th>
                        <th style="padding: 10px;">Payment Date</th>
                        <th style="padding: 10px;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($urgent = $urgentPayments->fetch_assoc()): ?>
                        <tr style="border-bottom: 1px solid rgba(255,255,255,0.1);">
                            <td style="padding: 10px;"><strong><?= e($urgent['student_name']) ?></strong></td>
                            <td style="padding: 10px;">
                                <strong><?= date('d M Y', strtotime($urgent['booking_date'])) ?></strong><br>
                                <?= date('h:i A', strtotime($urgent['booking_time'])) ?>
                                <div class="hours-warning" style="color: #ffc107;">⚠️ <?= $urgent['hours_until_class'] ?> hours left</div>
                              </td>
                            <td style="padding: 10px;">RM <?= number_format($urgent['amount'], 2) ?></td>
                            <td style="padding: 10px;">
                                <?= date('d M Y', strtotime($urgent['created_at'])) ?><br>
                                <small><?= date('h:i A', strtotime($urgent['created_at'])) ?></small>
                              </td>
                            <td style="padding: 10px;">
                                <button onclick="openVerifyModal(<?= $urgent['id'] ?>)" class="btn-verify" style="background: white; color: #dc2626;">Verify Now</button>
                              </td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- Filter Bar with Year/Month -->
    <form method="GET" class="filter-bar">
        <select name="status" class="filter-select">
            <option value="all" <?= $status_filter == 'all' ? 'selected' : '' ?>>All Status</option>
            <option value="pending" <?= $status_filter == 'pending' ? 'selected' : '' ?>>Pending (<?= $pendingCount ?>)</option>
            <option value="verified" <?= $status_filter == 'verified' ? 'selected' : '' ?>>Verified</option>
            <option value="disputed" <?= $status_filter == 'disputed' ? 'selected' : '' ?>>Disputed</option>
            <option value="rejected" <?= $status_filter == 'rejected' ? 'selected' : '' ?>>Rejected</option>
        </select>
        <select name="method" class="filter-select">
            <option value="all" <?= $method_filter == 'all' ? 'selected' : '' ?>>All Methods</option>
            <option value="online_banking">Online Banking</option>
            <option value="duitnow">DuitNow</option>
            <option value="stripe">Stripe</option>
        </select>
        <select name="year" class="filter-select">
            <option value="all">All Years</option>
            <?php foreach ($available_years as $year): ?>
                <option value="<?= $year ?>" <?= $year_filter == $year ? 'selected' : '' ?>><?= $year ?></option>
            <?php endforeach; ?>
        </select>
        <select name="month" class="filter-select">
            <option value="">All Months</option>
            <option value="1" <?= $month_filter == '1' ? 'selected' : '' ?>>January</option>
            <option value="2" <?= $month_filter == '2' ? 'selected' : '' ?>>February</option>
            <option value="3" <?= $month_filter == '3' ? 'selected' : '' ?>>March</option>
            <option value="4" <?= $month_filter == '4' ? 'selected' : '' ?>>April</option>
            <option value="5" <?= $month_filter == '5' ? 'selected' : '' ?>>May</option>
            <option value="6" <?= $month_filter == '6' ? 'selected' : '' ?>>June</option>
            <option value="7" <?= $month_filter == '7' ? 'selected' : '' ?>>July</option>
            <option value="8" <?= $month_filter == '8' ? 'selected' : '' ?>>August</option>
            <option value="9" <?= $month_filter == '9' ? 'selected' : '' ?>>September</option>
            <option value="10" <?= $month_filter == '10' ? 'selected' : '' ?>>October</option>
            <option value="11" <?= $month_filter == '11' ? 'selected' : '' ?>>November</option>
            <option value="12" <?= $month_filter == '12' ? 'selected' : '' ?>>December</option>
        </select>
        <button type="submit" class="filter-btn">Filter</button>
        <?php if ($status_filter != 'all' || $method_filter != 'all' || $year_filter != 'all' || $month_filter != ''): ?>
            <a href="admin_payments.php" class="filter-btn" style="background:#64748b;">Clear</a>
        <?php endif; ?>
    </form>

    <!-- PAYMENTS TABLE with Month Grouping -->
    <div class="payments-container">
        <table id="paymentsTable">
            <thead>
                <tr>
                    <th style="width: 30px;"></th>
                    <th>Student</th>
                    <th>Tutor</th>
                    <th>Amount</th>
                    <th>Method</th>
                    <th>Status</th>
                    <th>Booking Date & Time</th>
                    <th>Payment Date & Time</th>
                    <th>Proof</th>
                    <th>Receipt</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $all_payments = [];
                $bulk_receipts = [];
                
                if ($payments) {
                    while ($row = $payments->fetch_assoc()) {
                        $all_payments[] = $row;
                        if ($row['receipt_number'] && $row['payment_method'] != 'stripe') {
                            if (!isset($bulk_receipts[$row['receipt_number']])) {
                                $bulk_receipts[$row['receipt_number']] = 0;
                            }
                            $bulk_receipts[$row['receipt_number']]++;
                        }
                    }
                }
                
                // Group payments by month
                $payments_by_month = [];
                foreach ($all_payments as $payment) {
                    $month_key = date('F Y', strtotime($payment['created_at']));
                    $month_sort_key = date('Y-m', strtotime($payment['created_at']));
                    if (!isset($payments_by_month[$month_sort_key])) {
                        $payments_by_month[$month_sort_key] = [
                            'display' => $month_key,
                            'payments' => []
                        ];
                    }
                    $payments_by_month[$month_sort_key]['payments'][] = $payment;
                }
                
                // Sort months descending (latest first)
                krsort($payments_by_month);
                
                // Process each month
                foreach ($payments_by_month as $month_sort_key => $month_data):
                    $month_display = $month_data['display'];
                    $month_payments = $month_data['payments'];
                ?>
                <!-- Month Separator Row -->
                <tr class="month-separator">
                    <td colspan="11">
                        <i class="bi bi-calendar-month"></i> 
                        <strong><?= $month_display ?></strong> 
                        <span style="margin-left: 10px; font-size: 12px; opacity: 0.8;">(<?= count($month_payments) ?> payments)</span>
                    </td>
                </tr>
                
                <?php
                // Process each payment in this month
                $processed_receipts = [];
                foreach ($month_payments as $payment):
                    $isBulk = ($payment['receipt_number'] && isset($bulk_receipts[$payment['receipt_number']]) && $bulk_receipts[$payment['receipt_number']] > 1);
                    
                    // Skip if already processed as part of bulk
                    if ($isBulk && in_array($payment['receipt_number'], $processed_receipts)) {
                        continue;
                    }
                    
                    $statusClass = '';
                    if ($payment['status'] == 'pending') $statusClass = 'status-pending';
                    elseif ($payment['status'] == 'verified') $statusClass = 'status-verified';
                    elseif ($payment['status'] == 'rejected') $statusClass = 'status-rejected';
                    elseif ($payment['status'] == 'disputed') $statusClass = 'status-disputed';
                    
                    $methodClass = '';
                    if ($payment['payment_method'] == 'stripe') $methodClass = 'method-stripe';
                    elseif ($payment['payment_method'] == 'online_banking') $methodClass = 'method-online_banking';
                    elseif ($payment['payment_method'] == 'duitnow') $methodClass = 'method-duitnow';
                    
                    $methodDisplay = $payment['payment_method'];
                    if ($methodDisplay == 'online_banking') $methodDisplay = 'Online Banking';
                    if ($methodDisplay == 'duitnow') $methodDisplay = 'DuitNow';
                    
                    $classSoon = false;
                    $hoursToClass = 0;
                    if ($payment['booking_date'] && $payment['booking_time']) {
                        $classTime = strtotime($payment['booking_date'] . ' ' . $payment['booking_time']);
                        $hoursToClass = ($classTime - time()) / 3600;
                        $classSoon = ($hoursToClass <= 24 && $hoursToClass > 0 && $payment['status'] == 'pending');
                    }
                    
                    if ($isBulk):
                        // Get all bulk items
                        $bulk_items = array_filter($all_payments, function($p) use ($payment) {
                            return $p['receipt_number'] == $payment['receipt_number'];
                        });
                        $total_amount = array_sum(array_column($bulk_items, 'amount'));
                        $booking_count = count($bulk_items);
                        $bulk_receipt = $payment['receipt_number'];
                        $all_pending = true;
                        $proof_image = '';
                        $tutor_names = [];
                        foreach ($bulk_items as $item) {
                            if ($item['status'] != 'pending') $all_pending = false;
                            if (empty($proof_image) && !empty($item['proof_image'])) $proof_image = $item['proof_image'];
                            if (!empty($item['tutor_name'])) {
                                $tutor_names[] = $item['tutor_name'];
                            }
                        }
                        $unique_tutors = array_unique($tutor_names);
                        $display_tutor = (count($unique_tutors) === 1) ? $unique_tutors[0] : 'Multiple tutors';
                        
                        $processed_receipts[] = $bulk_receipt;
                ?>
                    <!-- Bulk Parent Row -->
                    <tr class="bulk-parent-row" onclick="toggleBulkDetails('bulk_<?= $bulk_receipt ?>')" style="cursor: pointer;">
                        <td style="text-align: center;">
                            <i class="bi bi-chevron-right rotate-icon" id="icon_bulk_<?= $bulk_receipt ?>"></i>
                        </td>
                        <td>
                            <strong><?= e($payment['student_name']) ?></strong>
                            <span class="bulk-badge"><?= $booking_count ?>x</span>
                        </td>
                        <td><strong><?= e($display_tutor) ?></strong></td>
                        <td><strong>RM <?= number_format($total_amount, 2) ?></strong></td>
                        <td><span class="method-badge <?= $methodClass ?>"><?= $methodDisplay ?></span></td>
                        <td>
                            <?php if ($all_pending): ?>
                                <span class="status-badge status-pending">Pending</span>
                            <?php else: ?>
                                <span class="status-badge status-verified">Partially Verified</span>
                            <?php endif; ?>
                         </td>
                        <td class="date-cell">
                            <?php 
                            $dates = [];
                            foreach ($bulk_items as $item) {
                                $dates[] = date('d M Y', strtotime($item['booking_date']));
                            }
                            $unique_dates = array_unique($dates);
                            if (count($unique_dates) == 1) {
                                echo '<strong>' . $unique_dates[0] . '</strong><br>';
                                echo date('h:i A', strtotime($bulk_items[0]['booking_time']));
                            } else {
                                echo '<strong>' . min($unique_dates) . ' - ' . max($unique_dates) . '</strong><br>';
                                echo '<small>Multiple dates</small>';
                            }
                            ?>
                         </td>
                        <td class="date-cell">
                            <strong><?= date('d M Y', strtotime($payment['created_at'])) ?></strong><br>
                            <?= date('h:i A', strtotime($payment['created_at'])) ?>
                         </td>
                        <td>
                            <?php if (!empty($proof_image)): ?>
                                <img src="../uploads/payment_proofs/<?= e($proof_image) ?>" 
                                     class="proof-image" 
                                     onclick="event.stopPropagation(); viewProofImage('../uploads/payment_proofs/<?= e($proof_image) ?>')"
                                     title="Click to view full proof">
                            <?php else: ?>
                                <span>-</span>
                            <?php endif; ?>
                         </td>
                        <td>
                            <span class="method-badge method-online_banking"><?= e($bulk_receipt) ?></span>
                         </td>
                        <td class="action-cell">
                            <?php if ($all_pending): ?>
                                <button class="btn-verify" onclick="event.stopPropagation(); verifyBulkPayment('<?= $bulk_receipt ?>')">Verify All</button>
                                <button class="btn-reject" onclick="event.stopPropagation(); openRejectBulkModal('<?= $bulk_receipt ?>')">Reject All</button>
                            <?php endif; ?>
                         </td>
                     </tr>
                     <!-- Bulk Detail Row -->
<tr class="bulk-detail-row" id="bulk_<?= $bulk_receipt ?>" style="display: none;">
    <td colspan="11" style="padding: 0;">
        <div class="bulk-detail-content">
            <table class="detail-table" style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="background: #f0f0f0;">
                        <th style="padding: 12px;  font-size: 12px;">Tutor</th>
                        <th style="padding: 12px; font-size: 12px;">Booking Date & Time</th>
                        <th style="padding: 12px; font-size: 12px;">Amount</th>
                        <th style="padding: 12px;  font-size: 12px;">Payment Date</th>
                        <th style="padding: 12px; font-size: 12px;">Status</th>
                        <th style="padding: 12px; font-size: 12px;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($bulk_items as $item): ?>
                        <?php
                        $item_statusClass = '';
                        if ($item['status'] == 'pending') $item_statusClass = 'status-pending';
                        elseif ($item['status'] == 'verified') $item_statusClass = 'status-verified';
                        ?>
                        <tr style="border-bottom: 1px solid #eee;">
                            <td style="vertical-align: middle;"><?= e($item['tutor_name']) ?></td>
                            <td style="vertical-align: middle;">
                                <div style="display: flex; flex-direction: column; justify-content: center;">
                                    <strong><?= date('d M Y', strtotime($item['booking_date'])) ?></strong>
                                    <span style="font-size: 11px; color: #666;"><?= date('h:i A', strtotime($item['booking_time'])) ?></span>
                                </div>
                            </td>
                            <td style=" vertical-align: middle;">RM <?= number_format($item['amount'], 2) ?></td>
                            <td style=" vertical-align: middle;">
                                <div style="display: flex; flex-direction: column; justify-content: center;">
                                    <strong><?= date('d M Y', strtotime($item['created_at'])) ?></strong>
                                    <span style="font-size: 11px; color: #666;"><?= date('h:i A', strtotime($item['created_at'])) ?></span>
                                </div>
                            </td>
                            <td style="vertical-align:middle;">
    <span class="status-badge <?= $item_statusClass ?>">
        <?= ucfirst($item['status']) ?>
    </span>
</td>
<td style="vertical-align: middle;">
    <?php if ($item['status'] == 'pending'): ?>
        <button class="btn-verify" onclick="openVerifyModal(<?= $item['id'] ?>); event.stopPropagation();" style="padding: 3px 8px; font-size: 11px;">
            Verify
        </button>
        <button class="btn-reject" onclick="openRejectModal(<?= $item['id'] ?>); event.stopPropagation();" style="padding: 3px 8px; font-size: 11px;">
            Reject
        </button>
    <?php endif; ?>
</td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </td>
</tr>
                <?php else: ?>
                    <!-- Regular single payment row -->
                    <tr style="<?= $classSoon ? 'background: #fff3cd;' : '' ?>">
                        <td></td>
                        <td><strong><?= e($payment['student_name']) ?></strong></td>
                        <td><strong><?= e($payment['tutor_name']) ?></strong></td>
                        <td>RM <?= number_format($payment['amount'], 2) ?></td>
                        <td><span class="method-badge <?= $methodClass ?>"><?= $methodDisplay ?></span></td>
                        <td>
                            <span class="status-badge <?= $statusClass ?>"><?= ucfirst($payment['status']) ?></span>
                            <?php if ($classSoon): ?>
                                <div class="hours-warning">⚠️ Class in <?= round($hoursToClass) ?>h</div>
                            <?php endif; ?>
                          </td>
                        <td class="date-cell">
                            <strong><?= date('d M Y', strtotime($payment['booking_date'])) ?></strong><br>
                            <?= date('h:i A', strtotime($payment['booking_time'])) ?>
                         </td>
                        <td class="date-cell">
                            <strong><?= date('d M Y', strtotime($payment['created_at'])) ?></strong><br>
                            <?= date('h:i A', strtotime($payment['created_at'])) ?>
                         </td>
                        <td>
                            <?php if (!empty($payment['proof_image'])): ?>
                                <img src="../uploads/payment_proofs/<?= e($payment['proof_image']) ?>" 
                                     class="proof-image" 
                                     onclick="viewProofImage('../uploads/payment_proofs/<?= e($payment['proof_image']) ?>')"
                                     title="Click to view full proof">
                            <?php else: ?>
                                <span>-</span>
                            <?php endif; ?>
                         </td>
                        <td>
                            <?php if (!empty($payment['receipt_url'])): ?>
                                <a href="<?= e($payment['receipt_url']) ?>" target="_blank" class="btn-view" style="background: #635bff;">Stripe Receipt</a>
                            <?php elseif (!empty($payment['receipt_number'])): ?>
                                <span class="method-badge method-online_banking"><?= e($payment['receipt_number']) ?></span>
                            <?php else: ?>
                                <span>-</span>
                            <?php endif; ?>
                         </td>
                        <td class="action-cell">
                            <?php if ($payment['status'] == 'pending'): ?>
                                <?php if ($payment['payment_method'] == 'stripe'): ?>
                                    <span class="method-badge method-stripe">Auto-verified</span>
                                <?php else: ?>
                                    <button class="btn-verify" onclick="openVerifyModal(<?= $payment['id'] ?>)">Verify</button>
                                    <button class="btn-reject" onclick="openRejectModal(<?= $payment['id'] ?>)">Reject</button>
                                <?php endif; ?>
                            <?php elseif ($payment['status'] == 'disputed'): ?>
                                <button class="btn-dispute" onclick="openDisputeModal(<?= $payment['id'] ?>, '<?= addslashes($payment['notes']) ?>')">Resolve</button>
                            <?php endif; ?>
                            <?php if (!empty($payment['notes']) && $payment['status'] != 'disputed'): ?>
                                <button class="btn-view" onclick="showNotes('<?= addslashes($payment['notes']) ?>')">Notes</button>
                            <?php endif; ?>
                         </td>
                            </tr>
                <?php endif; ?>
                <?php endforeach; ?>
                <?php endforeach; ?>
                
                <?php if (count($all_payments) == 0): ?>
                    <tr>
                        <td colspan="11" style="text-align: center; padding: 40px;">
                            <i class="bi bi-inbox" style="font-size: 48px; color: #ccc;"></i>
                            <p style="margin-top: 10px; color: #666;">No payments found</p>
                         </td>
                    </tr>
                <?php endif; ?>
            </tbody>
         </table>
    </div>
</div>

<!-- Modals (same as before) -->
<div id="verifyModal" class="modal-overlay">
    <div class="modal-container">
        <div class="modal-header">
            <h3>Verify Manual Payment</h3>
            <button class="close-modal" onclick="closeVerifyModal()">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="payment_id" id="verifyPaymentId">
            <div class="modal-body">
                <p style="margin-bottom: 16px;">
                    <i class="bi bi-info-circle" style="color: #059669;"></i> 
                    <strong>Receipt number will be auto-generated</strong><br>
                    Format: RCP-YYYYMMDD-XXXXXX
                </p>
                <ul style="margin-top: 12px; font-size: 13px; color: #666;">
                    <li>Mark payment as verified</li>
                    <li>Generate a unique receipt number</li>
                    <li>Confirm the associated booking</li>
                </ul>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-reject" onclick="closeVerifyModal()" style="background: #64748b;">Cancel</button>
                <button type="submit" name="verify_payment" class="btn-verify">Verify Payment</button>
            </div>
        </form>
    </div>
</div>

<div id="rejectModal" class="modal-overlay">
    <div class="modal-container">
        <div class="modal-header">
            <h3>Reject Payment</h3>
            <button class="close-modal" onclick="closeRejectModal()">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="payment_id" id="rejectPaymentId">
            <div class="modal-body">
                <div class="form-group">
                    <label>Rejection Reason</label>
                    <textarea name="rejection_reason" rows="3" placeholder="Please provide reason for rejection..." required></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-verify" onclick="closeRejectModal()" style="background: #64748b;">Cancel</button>
                <button type="submit" name="reject_payment" class="btn-reject">Reject Payment</button>
            </div>
        </form>
    </div>
</div>

<div id="rejectBulkModal" class="modal-overlay">
    <div class="modal-container">
        <div class="modal-header">
            <h3>Reject Bulk Payment</h3>
            <button class="close-modal" onclick="closeRejectBulkModal()">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="receipt_number" id="rejectBulkReceipt">
            <input type="hidden" name="reject_bulk_payment" value="1">
            <div class="modal-body">
                <div class="form-group">
                    <label>Rejection Reason</label>
                    <textarea name="rejection_reason" id="rejectBulkReason" rows="3" placeholder="Please provide reason for rejection..." required></textarea>
                </div>
                <p style="margin-top: 12px; font-size: 13px; color: #dc2626;">
                    <i class="bi bi-exclamation-triangle"></i> This will reject ALL payments in this bulk group and cancel all associated bookings.
                </p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-verify" onclick="closeRejectBulkModal()" style="background: #64748b;">Cancel</button>
                <button type="submit" name="reject_bulk_payment" class="btn-reject">Reject All</button>
            </div>
        </form>
    </div>
</div>

<div id="disputeModal" class="modal-overlay">
    <div class="modal-container">
        <div class="modal-header">
            <h3>Resolve Dispute</h3>
            <button class="close-modal" onclick="closeDisputeModal()">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="payment_id" id="disputePaymentId">
            <div class="modal-body">
                <div class="form-group">
                    <label>Dispute Details</label>
                    <textarea id="disputeNotes" rows="4" readonly style="background: #f8f9fa;"></textarea>
                </div>
                <div class="form-group">
                    <label>Resolution</label>
                    <textarea name="resolution" rows="3" placeholder="How was this dispute resolved?"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-reject" onclick="closeDisputeModal()" style="background: #64748b;">Cancel</button>
                <button type="submit" name="resolve_dispute" class="btn-verify">Resolve & Verify</button>
            </div>
        </form>
    </div>
</div>

<div id="imageModal" class="modal-overlay" onclick="closeImageModal()">
    <div style="background: white; border-radius: 16px; padding: 20px; max-width: 90%; max-height: 90%; overflow: auto;">
        <img id="fullImage" src="" style="max-width: 100%; max-height: 80vh; display: block; margin: auto;">
        <button onclick="closeImageModal()" style="display: block; margin: 15px auto 0; background: #E75A9B; color: white; border: none; padding: 8px 20px; border-radius: 30px; cursor: pointer;">Close</button>
    </div>
</div>

<script>
function toggleDropdown() {
    const dropdown = document.getElementById('profileDropdown');
    dropdown.style.display = dropdown.style.display === 'block' ? 'none' : 'block';
}

window.addEventListener('click', function(e) {
    const dropdown = document.getElementById('profileDropdown');
    const button = document.querySelector('.admin-profile');
    if (button && !button.contains(e.target) && dropdown && !dropdown.contains(e.target)) {
        dropdown.style.display = 'none';
    }
});

function openVerifyModal(paymentId) {
    document.getElementById('verifyPaymentId').value = paymentId;
    document.getElementById('verifyModal').style.display = 'flex';
}

function closeVerifyModal() {
    document.getElementById('verifyModal').style.display = 'none';
}

function openRejectModal(paymentId) {
    document.getElementById('rejectPaymentId').value = paymentId;
    document.getElementById('rejectModal').style.display = 'flex';
}

function closeRejectModal() {
    document.getElementById('rejectModal').style.display = 'none';
}

function openRejectBulkModal(receiptNumber) {
    document.getElementById('rejectBulkReceipt').value = receiptNumber;
    document.getElementById('rejectBulkReason').value = '';
    document.getElementById('rejectBulkModal').style.display = 'flex';
}

function closeRejectBulkModal() {
    document.getElementById('rejectBulkModal').style.display = 'none';
}

function openDisputeModal(paymentId, notes) {
    document.getElementById('disputePaymentId').value = paymentId;
    document.getElementById('disputeNotes').value = notes;
    document.getElementById('disputeModal').style.display = 'flex';
}

function closeDisputeModal() {
    document.getElementById('disputeModal').style.display = 'none';
}

function viewProofImage(src) {
    document.getElementById('fullImage').src = src;
    document.getElementById('imageModal').style.display = 'flex';
}

function closeImageModal() {
    document.getElementById('imageModal').style.display = 'none';
}

function showNotes(notes) {
    Swal.fire({
        title: 'Payment Notes',
        text: notes,
        icon: 'info',
        confirmButtonColor: '#E75A9B'
    });
}

function verifyBulkPayment(receiptNumber) {
    Swal.fire({
        title: 'Verify Bulk Payment?',
        html: `<p>This will verify <strong>ALL payments</strong> with receipt: <strong style="color:#059669;">${receiptNumber}</strong></p>`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, Verify All',
        confirmButtonColor: '#28a745'
    }).then((result) => {
        if (result.isConfirmed) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = `
                <input type="hidden" name="verify_bulk_payment" value="1">
                <input type="hidden" name="receipt_number" value="${receiptNumber}">
            `;
            document.body.appendChild(form);
            form.submit();
        }
    });
}

function toggleBulkDetails(id) {
    const row = document.getElementById(id);
    const icon = document.getElementById('icon_' + id);
    if (row && icon) {
        if (row.style.display === 'none') {
            row.style.display = 'table-row';
            icon.classList.add('rotated');
        } else {
            row.style.display = 'none';
            icon.classList.remove('rotated');
        }
    }
}

const menuToggle = document.getElementById('menuToggle');
const sidebar = document.getElementById('sidebar');
const overlay = document.getElementById('sidebarOverlay');

if (menuToggle) {
    menuToggle.addEventListener('click', function() {
        sidebar.classList.toggle('open');
        overlay.classList.toggle('active');
        document.body.style.overflow = sidebar.classList.contains('open') ? 'hidden' : '';
    });
}

if (overlay) {
    overlay.addEventListener('click', function() {
        sidebar.classList.remove('open');
        overlay.classList.remove('active');
        document.body.style.overflow = '';
    });
}

// Auto-hide success/error messages after 3 seconds
document.addEventListener('DOMContentLoaded', function() {
    const messages = document.querySelectorAll('.message');
    messages.forEach(function(message) {
        setTimeout(function() {
            message.classList.add('fade-out');
            // Remove the message from DOM after animation
            setTimeout(function() {
                message.style.display = 'none';
            }, 500);
        }, 3000); // 3 seconds
    });
});
</script>

</body>
</html>