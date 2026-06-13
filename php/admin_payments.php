<?php
session_start();
include 'config.php';
include 'check_login.php';
$assetBase = '../assets/img';
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
        $mail->Username   = SMTP_USER; 
        $mail->Password   = SMTP_PASS;
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

// Send overpayment email to student
function sendOverpaymentEmail($conn, $payment_id, $actual_paid, $expected, $overpaid) {
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
        $mail->Username   = SMTP_USER; 
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = 'tls';
        $mail->Port       = 587;
        $mail->setFrom('sohisabella87@gmail.com', 'Kyoshi');
        $mail->addAddress($payment['student_email'], $payment['student_name']);
        
        $mail->isHTML(true);
        $mail->Subject = 'Payment Overpaid - Kyoshi';
        $mail->Body = "
            <div style='font-family:Segoe UI,sans-serif;max-width:550px;margin:auto;border:1px solid #e0e0e0;border-radius:16px;padding:24px;'>
                <div style='text-align:center;margin-bottom:24px;'>
                    <h2 style='color:#f59e0b;margin-top:8px;'>Payment Overpaid</h2>
                </div>
                <p>Dear <strong>" . htmlspecialchars($payment['student_name']) . "</strong>,</p>
                <p>Your payment has been <strong style='color:#059669;'>verified</strong> but you have overpaid.</p>
                <div style='background:#f0fdf4;padding:16px;border-radius:12px;margin:16px 0;'>
                    <p><strong>You paid:</strong> RM " . number_format($actual_paid, 2) . "</p>
                    <p><strong>Expected amount:</strong> RM " . number_format($expected, 2) . "</p>
                    <p><strong>Overpaid amount:</strong> <span style='color:#dc2626;'>RM " . number_format($overpaid, 2) . "</span></p>
                    <p><strong>Booking Date:</strong> " . date('d M Y', strtotime($payment['booking_date'])) . "</p>
                    <p><strong>Tutor:</strong> " . htmlspecialchars($payment['tutor_name']) . "</p>
                </div>
                <div style='background:#fef3c7;padding:16px;border-radius:12px;margin:16px 0;'>
                    <p><strong>What happens next?</strong></p>
                    <p>Your booking is <strong>confirmed</strong>. Our admin will process a refund for the overpaid amount of <strong>RM " . number_format($overpaid, 2) . "</strong> within 3-5 business days.</p>
                </div>
                <hr>
                <p style='font-size:12px;color:#666;'>This is an automated message from Kyoshi.</p>
            </div>
        ";
        $mail->send();
        return true;
        
    } catch (Exception $e) {
        error_log("Overpayment email failed: " . $mail->ErrorInfo);
        return false;
    }
}

// Send underpaid email to student
function sendUnderpaidEmail($conn, $payment_id, $actual_paid, $expected, $remaining) {
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
        $mail->Username   = SMTP_USER; 
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = 'tls';
        $mail->Port       = 587;
        $mail->setFrom('sohisabella87@gmail.com', 'Kyoshi');
        $mail->addAddress($payment['student_email'], $payment['student_name']);
        
        $mail->isHTML(true);
        $mail->Subject = 'Partial Payment Received - Kyoshi';
        $mail->Body = "
            <div style='font-family:Segoe UI,sans-serif;max-width:550px;margin:auto;border:1px solid #e0e0e0;border-radius:16px;padding:24px;'>
                <div style='text-align:center;margin-bottom:24px;'>
                    <h2 style='color:#f59e0b;margin-top:8px;'>Partial Payment Received</h2>
                </div>
                <p>Dear <strong>" . htmlspecialchars($payment['student_name']) . "</strong>,</p>
                <p>We have received your payment, but the amount is less than expected.</p>
                <div style='background:#fef3c7;padding:16px;border-radius:12px;margin:16px 0;'>
                    <p><strong>You paid:</strong> RM " . number_format($actual_paid, 2) . "</p>
                    <p><strong>Expected amount:</strong> RM " . number_format($expected, 2) . "</p>
                    <p><strong>Remaining to pay:</strong> <span style='color:#dc2626;'>RM " . number_format($remaining, 2) . "</span></p>
                    <p><strong>Booking Date:</strong> " . date('d M Y', strtotime($payment['booking_date'])) . "</p>
                    <p><strong>Tutor:</strong> " . htmlspecialchars($payment['tutor_name']) . "</p>
                </div>
                <div style='background:#e0f2fe;padding:16px;border-radius:12px;margin:16px 0;'>
                    <p><strong>What happens next?</strong></p>
                    <p>Please pay the remaining <strong>RM " . number_format($remaining, 2) . "</strong> to confirm your booking.</p>
                    <p style='margin-top:10px;'>
                        <a href='http://kyoshitutor.site/php/booking_detail.php?id={$payment['booking_id']}' 
                           style='display:inline-block;padding:10px 20px;background:#f59e0b;color:white;border-radius:30px;text-decoration:none;font-weight:bold;'>
                            Pay Remaining Amount →
                        </a>
                    </p>
                </div>
                <hr>
                <p style='font-size:12px;color:#666;'>This is an automated message from Kyoshi.</p>
            </div>
        ";
        $mail->send();
        return true;
        
    } catch (Exception $e) {
        error_log("Underpaid email failed: " . $mail->ErrorInfo);
        return false;
    }
}


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
$totalBookings = $conn->query("SELECT COUNT(*) as count FROM bookings")->fetch_assoc()['count'];

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
    $rejection_type = $conn->real_escape_string($_POST['rejection_type'] ?? 'other');
    $actual_paid_amount = null;
    if (isset($_POST['actual_paid_amount']) && $_POST['actual_paid_amount'] !== '') {
        $actual_paid_amount = floatval($_POST['actual_paid_amount']);
    }
    // Log for debugging
    error_log("Rejection Debug - Type: $rejection_type, Actual Paid: " . ($actual_paid_amount ?? 'NULL'));
    
    if (empty($rejection_reason)) {
        $_SESSION['error_message'] = "Please provide a rejection reason.";
        header("Location: admin_payments.php");
        exit();
    }
    
    $conn->begin_transaction();
    
    try {
        // Get payment details
        $payment_query = $conn->query("SELECT amount, booking_id, student_id FROM payments WHERE id = $payment_id");
        $payment_data = $payment_query->fetch_assoc();
        $expected_amount = $payment_data['amount'];
        $booking_id = $payment_data['booking_id'];
        $student_id = $payment_data['student_id'];
        
        // Handle OVERPAYMENT - Accept and create refund record
        // Handle OVERPAID
        if ($rejection_type == 'overpaid' && $actual_paid_amount > $expected_amount) {
    $overpaid_amount = $actual_paid_amount - $expected_amount;

    $conn->query("UPDATE payments 
                  SET status = 'verified', 
                      refund_status = 'pending',
                      actual_paid_amount = $actual_paid_amount,
                      notes = CONCAT(IFNULL(notes, ''), ' [OVERPAID: RM " . number_format($overpaid_amount, 2) . "]'),
                      verified_at = NOW()
                  WHERE id = $payment_id");
    
    // Confirm booking
    $conn->query("UPDATE bookings SET status = 'confirmed' WHERE id = $booking_id");
    
    // Send overpayment email
    sendOverpaymentEmail($conn, $payment_id, $actual_paid_amount, $expected_amount, $overpaid_amount);
    
    $_SESSION['success_message'] = "Payment verified! Student overpaid RM " . number_format($overpaid_amount, 2) . ". Please process refund.";
}
        // Handle UNDERPAID - Create ONE consolidated remaining payment for single booking
        else if ($rejection_type == 'wrong_amount' && $actual_paid_amount < $expected_amount) {
            $total_remaining = $expected_amount - $actual_paid_amount;
            $booking_ids_str = $booking_id;
            
            // Update current payment as partial_paid (not rejected)
            $conn->query("UPDATE payments 
                          SET status = 'rejected', 
                              rejection_type = 'wrong_amount',
                              actual_paid_amount = $actual_paid_amount,
                              notes = 'Part of payment. Paid: RM $actual_paid_amount, Remaining: RM $total_remaining'
                          WHERE id = $payment_id");
            
            $conn->query("UPDATE bookings SET status = 'accepted' WHERE id = $booking_id");
            

        // Other rejections - Reject and cancel booking
        } else {
        $update_query = "UPDATE payments 
                        SET status = 'rejected', 
                            notes = '$rejection_reason',
                            rejection_type = '$rejection_type' 
                        WHERE id = $payment_id";
        $conn->query($update_query);
        
        $conn->query("UPDATE bookings SET status = 'cancelled' WHERE id = $booking_id");
        
        $_SESSION['error_message'] = "Payment rejected: $rejection_reason";
    }
        
        $conn->commit();
        
       // Send email based on status
    if ($rejection_type == 'overpaid') {
        // Email already sent from the overpaid block
    } else if ($rejection_type == 'wrong_amount') {
        // Send a custom email for underpaid (partial payment)
        sendUnderpaidEmail($conn, $payment_id, $actual_paid_amount, $expected_amount, $total_remaining);
    } else {
        sendPaymentStatusEmail($conn, $payment_id, 'rejected', $rejection_reason);
    }
        
        header("Location: admin_payments.php");
        exit();
        
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['error_message'] = "Error processing payment: " . $e->getMessage();
        header("Location: admin_payments.php");
        exit();
    }
}
if (isset($_POST['reject_bulk_payment'])) {
    $receipt_number = $conn->real_escape_string($_POST['receipt_number']);
    $rejection_reason = $conn->real_escape_string($_POST['rejection_reason'] ?? '');
    $rejection_type = $conn->real_escape_string($_POST['rejection_type'] ?? 'other');
    $actual_paid_amount = isset($_POST['actual_paid_amount']) ? floatval($_POST['actual_paid_amount']) : null;
    
    if (empty($rejection_reason)) {
        $_SESSION['error_message'] = "Please provide a rejection reason.";
        header("Location: admin_payments.php");
        exit();
    }
    
    $conn->begin_transaction();
    
    try {
        // First, get all payments with this receipt number to get expected amounts
        $payments_query = $conn->query("SELECT id, amount, booking_id FROM payments WHERE receipt_number = '$receipt_number' AND status = 'pending'");
        $payment_records = [];
        while ($row = $payments_query->fetch_assoc()) {
            $payment_records[] = $row;
        }
        
        // Calculate total expected amount
        $total_expected = 0;
        foreach ($payment_records as $payment) {
            $total_expected += $payment['amount'];
        }
        
        // Determine if it's overpaid or underpaid based on actual vs expected
        $is_overpaid = ($actual_paid_amount > $total_expected);
        $is_underpaid = ($actual_paid_amount < $total_expected);
        
        if ($rejection_type == 'overpaid' && $is_overpaid) {
            // OVERPAID - Split proportionally
            foreach ($payment_records as $payment) {
                $expected_amount = $payment['amount'];
                $proportion = $expected_amount / $total_expected;
                $paid_for_this = round($actual_paid_amount * $proportion, 2);
                $overpaid_amount = $paid_for_this - $expected_amount;
                
                $conn->query("UPDATE payments 
                              SET status = 'verified', 
                                  refund_status = 'pending',
                                  actual_paid_amount = $paid_for_this,
                                  notes = CONCAT(IFNULL(notes, ''), ' [OVERPAID: RM " . number_format($overpaid_amount, 2) . "]'),
                                  verified_at = NOW()
                              WHERE id = {$payment['id']}");
                
                $conn->query("UPDATE bookings SET status = 'confirmed' WHERE id = {$payment['booking_id']}");
                sendOverpaymentEmail($conn, $payment['id'], $paid_for_this, $expected_amount, $overpaid_amount);
            }
            
            $_SESSION['success_message'] = "Bulk payments verified! Students overpaid. Please process refunds separately.";
            
        } else if ($rejection_type == 'wrong_amount' && $is_underpaid) {
            // UNDERPAID - Split proportionally
            $total_paid = $actual_paid_amount;
            $total_remaining = $total_expected - $total_paid;
            
            foreach ($payment_records as $payment) {
                $proportion = $payment['amount'] / $total_expected;
                $paid_for_this = round($total_paid * $proportion, 2);
                $remaining_for_this = round($payment['amount'] - $paid_for_this, 2);
                
                // FIX: Set status to 'partial_paid' NOT 'rejected'
                $conn->query("UPDATE payments 
                              SET status = 'rejected', 
                                  rejection_type = 'underpaid_bulk',
                                  actual_paid_amount = $paid_for_this,
                                  notes = CONCAT(IFNULL(notes, ''), ' [UNDERPAID: Paid RM $paid_for_this, Remaining: RM $remaining_for_this]')
                              WHERE id = {$payment['id']}");
                
                // FIX: Set booking status to 'pending' NOT 'accepted'
                $conn->query("UPDATE bookings SET status = 'accepted', 
                              notes = CONCAT(IFNULL(notes, ''), ' Partial payment received. Remaining balance: RM $remaining_for_this')
                              WHERE id = {$payment['booking_id']}");
                
                sendUnderpaidEmail($conn, $payment['id'], $paid_for_this, $payment['amount'], $remaining_for_this);
            }
            
            $_SESSION['success_message'] = "Bulk payment processed as partial payment. Student needs to pay remaining RM " . number_format($total_remaining, 2);
            
        } else {
            // OTHER REJECTIONS - Reject and cancel bookings
            foreach ($payment_records as $payment) {
                $conn->query("UPDATE payments 
                              SET status = 'rejected', 
                                  rejection_type = '$rejection_type',
                                  notes = '$rejection_reason'
                              WHERE id = {$payment['id']}");
                
                $conn->query("UPDATE bookings SET status = 'cancelled', cancel_reason = 'Payment rejected: $rejection_reason' WHERE id = {$payment['booking_id']}");
            }
            
            $_SESSION['error_message'] = "Bulk payment rejected! Bookings cancelled.";
        }
        
        $conn->commit();
        header("Location: admin_payments.php");
        exit();
        
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['error_message'] = "Error processing bulk payments: " . $e->getMessage();
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

// Handle bulk verify payment (for normal bulk)
if (isset($_POST['verify_bulk_payment'])) {
    $receipt_number = $conn->real_escape_string($_POST['receipt_number']);
    
    $conn->begin_transaction();
    
    try {
        $payments_query = $conn->query("
            SELECT id, booking_id, amount, student_id 
            FROM payments 
            WHERE receipt_number = '$receipt_number' AND status = 'pending'
        ");
        
        $verified_count = 0;
        
        while ($payment = $payments_query->fetch_assoc()) {
            $receipt_num = 'RCP-' . date('Ymd') . '-' . str_pad($payment['id'], 6, '0', STR_PAD_LEFT);
            
            $conn->query("
                UPDATE payments 
                SET status = 'verified', 
                    receipt_number = '$receipt_num', 
                    verified_at = NOW() 
                WHERE id = {$payment['id']}
            ");
            
            $conn->query("
                UPDATE bookings 
                SET status = 'confirmed' 
                WHERE id = {$payment['booking_id']}
            ");
            
            sendPaymentStatusEmail($conn, $payment['id'], 'verified');
            $verified_count++;
        }
        
        $conn->commit();
        $_SESSION['success_message'] = "$verified_count payment(s) verified successfully!";
        header("Location: admin_payments.php");
        exit();
        
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['error_message'] = "Error processing bulk verification: " . $e->getMessage();
        header("Location: admin_payments.php");
        exit();
    }
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

$payments = $conn->query("
    SELECT p.*, 
           b.booking_date, b.booking_time, b.status as booking_status,
           s.fullname as student_name,
           t.fullname as tutor_name,
           DATE_FORMAT(p.created_at, '%Y-%m') as payment_month,
           p.refund_status,
           p.refund_receipt_number,
           p.actual_paid_amount
    FROM payments p
    LEFT JOIN bookings b ON p.booking_id = b.id
    LEFT JOIN users s ON p.student_id = s.id
    LEFT JOIN users t ON p.tutor_id = t.id
    WHERE $where AND p.payment_method != 'stripe' AND p.payment_method != 'remaining_balance' AND p.status != 'disputed'
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
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kyoshi | Payments</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="../css/astyle.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700;800&display=swap" rel="stylesheet">

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
                * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Montserrat', 'Open Sans', sans-serif;
            background: url('../assets/img/background3.jpg') no-repeat center top;
            background-size: cover;
            min-height: 100vh;
            position: relative;
            color: #1E1B2E;
            line-height: 1.45;
            overflow-x: hidden;
        }
        
       /* Make sidebar fixed - NO SCROLLING */
.sidebar {
    position: fixed;
    left: 0;
    top: 0;
    width: 230px;
    height: 100vh;
    background: #272754;
    color: #E8E4F0;
    overflow-y: hidden;  /* ← CHANGE from 'auto' to 'hidden' */
    z-index: 1000;
    transition: transform 0.3s ease;
    transform: translateX(0);
    display: flex;
    flex-direction: column;
}

/* Make only the navigation menu scrollable if needed (optional) */
.nav-menu {
    padding: 16px 0;
    flex: 1;
    overflow-y: auto;  /* Only menu scrolls if content too long */
    min-height: 0;
}

/* Custom scrollbar for nav-menu (optional) */
.nav-menu::-webkit-scrollbar {
    width: 3px;
}

.nav-menu::-webkit-scrollbar-track {
    background: rgba(255,255,255,0.1);
}

.nav-menu::-webkit-scrollbar-thumb {
    background: #B26EA7;
    border-radius: 3px;
}
/* Dispute modal specific styles */
#disputeNotes {
    font-family: 'Courier New', monospace;
    font-size: 12px;
    line-height: 1.6;
    white-space: pre-wrap;
    word-wrap: break-word;
    background: #fefce8;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 12px;
    color: #1a1a2e;
    width: 100%;
    resize: vertical;
}

#disputeNotes:read-only {
    cursor: default;
}

.dispute-highlight {
    padding: 2px 4px;
    border-radius: 4px;
    font-weight: 600;
}

.dispute-highlight.warning {
    background: #fef3c7;
    color: #d97706;
}

.dispute-highlight.error {
    background: #fee2e2;
    color: #dc2626;
}

.dispute-highlight.amount {
    background: #d1fae5;
    color: #059669;
}

.dispute-timestamp {
    color: #64748b;
    font-size: 11px;
    font-family: monospace;
    background: #f3f4f6;
    padding: 2px 6px;
    border-radius: 4px;
    display: inline-block;
}

/* Keep header and footer fixed */
.sidebar-header {
    flex-shrink: 0;
}

.sidebar-footer {
    flex-shrink: 0;
}

/* MAIN CONTENT - THIS SHOULD SCROLL */
.main-content {
    margin-left: 230px;
    padding: 20px 24px;
    transition: margin-left 0.3s ease;
    height: 100vh;
    overflow-y: auto;  /* ← ADD THIS - makes main content scrollable */
    scroll-behavior: smooth;
}

/* Custom scrollbar for main content (optional) */
.main-content::-webkit-scrollbar {
    width: 8px;
}

.main-content::-webkit-scrollbar-track {
    background: #f1f1f1;
    border-radius: 10px;
}

.main-content::-webkit-scrollbar-thumb {
    background: #E75A9B;
    border-radius: 10px;
}

.main-content::-webkit-scrollbar-thumb:hover {
    background: #C94F86;
}
        
        .sidebar.closed {
            transform: translateX(-100%);
        }
        
        .sidebar-header {
            padding: 28px 20px;
            flex-shrink: 0;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        
        
        .sidebar-header p {
            font-size: 0.65rem;
            color: rgba(255,255,255,0.5);
            margin-top: 4px;
        }
        
        
        .nav-item {
            padding: 10px 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            color: #D4CFE8;
            text-decoration: none;
            transition: all 0.2s;
            border-left: 3px solid transparent;
            font-size: 0.85rem;
        }
        
        .nav-item:hover {
            background: rgba(255,255,255,0.08);
            color: white;
        }
        
        .nav-item.active {
            background: rgba(255,255,255,0.1);
            border-left-color: #B26EA7;
            color: white;
        }
        
        .nav-item i {
            width: 20px;
            font-size: 1.1rem;
        }
        /* Sidebar Section Labels */
        .nav-section {
            margin-bottom: 8px;
        }

        .nav-section-label {
            padding: 12px 20px 6px 20px;
            font-size: 0.65rem;
            font-weight: 600;
            color: #B26EA7;
            text-transform: uppercase;
            letter-spacing: 1px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .nav-section-label i {
            font-size: 0.7rem;
            color: #B26EA7;
        }

        .nav-badge {
            margin-left: auto;
            font-size: 0.65rem;
            background: rgba(178, 110, 167, 0.25);
            padding: 2px 8px;
            border-radius: 30px;
            color: #D4CFE8;
            font-weight: 600;
        }

        .nav-badge.pending {
            background: rgba(245, 158, 11, 0.25);
            color: #F59E0B;
        }

        .nav-badge.dispute {
            background: rgba(220, 38, 38, 0.25);
            color: #FFA3A3;
        }

        .nav-item {
            position: relative;
        }
        
        /* Main Content */
        .main-content {
            margin-left: 220px;
            padding: 20px 24px;
            transition: margin-left 0.3s ease;
        }
        
        .main-content.expanded {
            margin-left: 0;
        }
        
        .top-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 16px;
            margin-bottom: 24px;
            padding-bottom: 16px;
        }
        
        .page-title h1 {
            font-size: 1.4rem;
            font-weight: 700;
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
            font-size: 1.1rem;
        }
        
        .admin-profile {
            display: flex;
            align-items: center;
            gap: 10px;
            background: white;
            padding: 6px 14px 6px 10px;
            border-radius: 50px;
            cursor: pointer;
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
            border: 1px solid #E4DCF0;
        }
        
        .admin-profile img {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            object-fit: cover;
        }
        
        .admin-profile span {
            font-weight: 600;
            font-size: 0.8rem;
            color: #302E63;
        }
        
        .admin-profile i {
            font-size: 11px;
            color: #A59BB5;
        }
        
        /* Stats Grid - Responsive */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 28px;
        }
        
        .stat-card {
            background: white;
            border-radius: 20px;
            padding: 18px;
            border: 1px solid #E4DCF0;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(48, 46, 99, 0.08);
        }

       .sidebar-footer {
            padding: 20px;
            border-top: 1px solid rgba(255, 255, 255, 0.15);
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }

        .admin-info {
            display: flex;
            align-items: center;
            gap: 12px;
            flex: 1;
            overflow: hidden;
        }

        .footer-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid rgba(255,255,255,0.2);
        }

        .admin-details {
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .admin-name {
            font-size: 0.8rem;
            font-weight: 600;
            color: white;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .logout-icon {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 36px;
            height: 36px;
            background: rgba(220, 38, 38, 0.15);
            border-radius: 10px;
            color: #FFA3A3;
            text-decoration: none;
            transition: all 0.2s;
            flex-shrink: 0;
        }

        .logout-icon:hover {
            background: rgba(220, 38, 38, 0.4);
            color: white;
            transform: scale(1.05);
        }

        .logout-icon i {
            font-size: 1.2rem;
        }
        
        .relative {
            position: relative;
        }
        
        .dropdown {
            position: absolute;
            top: calc(100% + 10px);
            right: 0;
            width: 180px;
            background: white;
            border-radius: 12px;
            overflow: hidden;
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
        
        .dropdown a:hover {
            background: #F4F0F8;
        }
        
        .dropdown hr {
            margin: 0;
            border-color: #E4DCF0;
        }
        
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
        
        .sidebar-overlay.active {
            display: block;
        }
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
        
        /* Table responsive fix */
.payments-container {
    overflow-x: auto;
}

table {
    min-width: 1000px;
    width: 100%;
    border-collapse: collapse;
}

th, td {
    white-space: nowrap;
}

td .btn-verify, td .btn-reject, td .btn-dispute, td .btn-refund {
    white-space: nowrap;
}

/* For date cells that might need wrapping */
.date-cell {
    white-space: normal;
    line-height: 1.3;
}

.date-cell strong {
    font-size: 12px;
}

/* Proof column */
td:has(.proof-image) {
    text-align: center;
}

/* Action column buttons */
.action-cell {
    display: flex;
    flex-wrap: wrap;
    gap: 5px;
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
        .status-partial { background: #fef3c7; color: #d97706; }
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
        .payments-container.btn-view { background: #E75A9B; color: white; border: none; padding: 6px 12px; border-radius: 20px; cursor: pointer; font-size: 0.7rem; font-weight: 600; }
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
        
        /* Modal close button styling */
.modal-header {
    padding: 20px 24px;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.modal-header h3 {
    font-size: 1.2rem;
    font-weight: 700;
    color: #1E1B2E;
    margin: 0;
}

.close-modal {
    background: none;
    border: none;
    font-size: 28px;
    font-weight: 300;
    cursor: pointer;
    color: #64748b;
    transition: all 0.2s ease;
    width: 32px;
    height: 32px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 8px;
    line-height: 1;
}

.close-modal:hover {
    background: #f1f5f9;
    color: #dc2626;
    transform: scale(1.1);
}

.close-modal:active {
    transform: scale(0.95);
}
        .modal-body { padding: 24px; }
        .modal-footer {
            padding: 16px 24px;
            border-top: 1px solid #e2e8f0;
            display: flex;
            justify-content: flex-end;
            gap: 12px;
        }

        .modal-footer button {
            padding: 8px 20px;
            border-radius: 30px;
            font-weight: 600;
            font-size: 0.75rem;
            cursor: pointer;
            transition: all 0.2s;
            border: none;
        }

        .modal-footer button:first-child {
            background: #64748b;
            color: white;
        }

        .modal-footer button:first-child:hover {
            background: #475569;
        }

        .modal-footer .btn-verify {
            background: #28a745;
        }

        .modal-footer .btn-reject {
            background: #dc3545;
        }
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
        .brand-wrapper {
    display: flex;
    align-items: center;
    gap: 12px;
}

.brand-icon {
    width: 60px;
    height: 60px;
    object-fit: contain;
}

.brand-title {
    display: flex;
    flex-direction: column;
}

.brand-title h1 {
    font-size: 1.4rem;
    font-weight: 700;
    background: linear-gradient(135deg, #ffffff, #B26EA7);
    background-clip: text;
    -webkit-background-clip: text;
    color: transparent;
    margin: 0;
    line-height: 1.2;
}

.admin-space-text {
    font-size: 0.6rem;
    color: #e7c7f7;
    letter-spacing: 0.5px;
    margin-top: 2px;
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
    padding: 12px 16px;
    font-size: 12px;
    font-weight: 700;
    text-align: center;
    vertical-align: middle;
}

.detail-table td {
    padding: 12px 8px;
    border-bottom: 1px solid #eee;
    vertical-align: middle;
}

.detail-table td:first-child {
    font-weight: 500;
}

.detail-table .action-cell {
    display: flex;
    gap: 6px;
    justify-content: flex-start;
    align-items: center;
}


.detail-table td div {
    display: flex;
    flex-direction: column;
    justify-content: center;
    gap: 2px;
}

/* Filter Bar */
.filter-bar {
    background: white;
    border-radius: 20px;
    padding: 20px 24px;
    margin-bottom: 24px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 20px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.04);
    border: 1px solid #eef2f7;
}

.filter-controls {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    flex: 1;
}

.filter-select {
    padding: 10px 16px;
    border-radius: 40px;
    border: 1px solid #e2e8f0;
    background: #f8f9fa;
    cursor: pointer;
    font-size: 13px;
    font-family: 'Montserrat', sans-serif;
    transition: all 0.2s;
}

.filter-select:hover {
    border-color: #E75A9B;
}

.filter-select:focus {
    outline: none;
    border-color: #E75A9B;
    box-shadow: 0 0 0 3px rgba(231,90,155,0.1);
}

.filter-buttons {
    display: flex;
    gap: 12px;
    flex-shrink: 0;
}

.filter-btn {
    background: #E75A9B;
    color: white;
    border: none;
    padding: 10px 24px;
    border-radius: 40px;
    cursor: pointer;
    font-weight: 600;
    font-size: 13px;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: all 0.2s;
}

.filter-btn i {
    font-size: 14px;
}

.apply-btn {
    background: linear-gradient(135deg, #E75A9B, #d44a8a);
}

.apply-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(231,90,155,0.3);
}

.clear-btn {
    background: #64748b;
}

.clear-btn:hover {
    background: #475569;
    transform: translateY(-2px);
}

/* Responsive */
@media (max-width: 768px) {
    .filter-bar {
        flex-direction: column;
        align-items: stretch;
    }
    
    .filter-controls {
        flex-direction: column;
    }
    
    .filter-select {
        width: 100%;
    }
    
    .filter-buttons {
        justify-content: center;
    }
    
    .filter-btn {
        flex: 1;
        justify-content: center;
    }
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

/* ============================================
   URGENT BANNER RESPONSIVE (max-width: 900px)
   ============================================ */

@media (max-width: 900px) {
    /* Urgent banner container */
    .urgent-banner {
        padding: 15px !important;
        margin-bottom: 20px !important;
    }
    
    /* Flex container in urgent banner */
    .urgent-banner > div:first-child {
        flex-direction: column !important;
        align-items: stretch !important;
        gap: 12px !important;
    }
    
    /* Banner text section */
    .urgent-banner > div:first-child > div:first-child {
        text-align: center !important;
    }
    
    /* Banner title */
    .urgent-banner > div:first-child i {
        font-size: 24px !important;
    }
    
    .urgent-banner > div:first-child strong {
        font-size: 16px !important;
        margin-left: 5px !important;
    }
    
    .urgent-banner > div:first-child p {
        font-size: 12px !important;
        margin-top: 5px !important;
    }
    
    /* Verify All button */
    .urgent-banner form {
        width: 100% !important;
    }
    
    .btn-urgent {
        width: 100% !important;
        padding: 10px 16px !important;
        font-size: 13px !important;
        display: flex !important;
        align-items: center !important;
        justify-content: center !important;
        gap: 8px !important;
    }
    
    /* Table inside urgent banner */
    .urgent-banner > div:last-child {
        overflow-x: auto !important;
        -webkit-overflow-scrolling: touch !important;
        max-height: 400px !important;
    }
    
    .urgent-banner > div:last-child table {
        min-width: 600px !important;
        width: 100% !important;
    }
    
    .urgent-banner > div:last-child th,
    .urgent-banner > div:last-child td {
        padding: 8px 10px !important;
        font-size: 12px !important;
    }
    
    /* Hours warning text */
    .hours-warning {
        font-size: 10px !important;
    }
    
    /* Verify button inside banner */
    .urgent-banner .btn-verify {
        padding: 4px 10px !important;
        font-size: 11px !important;
        white-space: nowrap !important;
    }
}

/* Even smaller phones */
@media (max-width: 480px) {
    .urgent-banner > div:last-child th,
    .urgent-banner > div:last-child td {
        padding: 6px 8px !important;
        font-size: 11px !important;
    }
    
    .btn-urgent {
        font-size: 12px !important;
    }
}

/* Rejection Reasons Popup Styling */
.rejection-reasons-popup {
    border-radius: 16px !important;
    padding: 0 !important;
}

.swal2-popup {
    font-size: 13px !important;
}

.swal2-close {
    color: #dc2626 !important;
    font-size: 28px !important;
    transition: all 0.2s ease !important;
}

.swal2-close:hover {
    transform: scale(1.1) !important;
    background: #fee2e2 !important;
}

/* Custom scrollbar for reasons box */
.rejection-reasons-popup .swal2-html-container::-webkit-scrollbar {
    width: 4px;
}

.rejection-reasons-popup .swal2-html-container::-webkit-scrollbar-track {
    background: #f1f1f1;
    border-radius: 4px;
}

.rejection-reasons-popup .swal2-html-container::-webkit-scrollbar-thumb {
    background: #E75A9B;
    border-radius: 4px;
}

/* Fix Swal z-index to appear above modals */
.swal2-container {
    z-index: 10000 !important;
}

.swal2-popup {
    z-index: 10001 !important;
}

/* Ensure Swal backdrop covers everything */
.swal2-backdrop-show {
    z-index: 9999 !important;
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
            <a href="admin_dashboard.php" class="nav-item">
                <i class="bi bi-speedometer2"></i><span>Dashboard</span>
            </a>
        </div>
        <div class="nav-section">
            <div class="nav-section-label">USERS</div>
            <a href="admin_tutor_actions.php" class="nav-item">
                <i class="bi bi-person-badge"></i><span>Tutors</span>
                <span class="nav-badge"><?= $totalTutors ?></span>
            </a>
            <a href="admin_student_actions.php" class="nav-item">
                <i class="bi bi-person"></i><span>Students</span>
                <span class="nav-badge"><?= $totalStudents ?></span>
            </a>
        </div>
        <div class="nav-section">
            <div class="nav-section-label">FINANCE</div>
            <a href="admin_payments.php" class="nav-item active">
                <i class="bi bi-credit-card"></i><span>Payments</span>
                <span class="nav-badge pending"><?= $pendingPayments ?></span>
            </a>
            <a href="admin_payouts.php" class="nav-item">
                <i class="bi bi-cash-stack"></i><span>Payouts</span>
                <span class="nav-badge"><?= $pendingPayouts ?></span>
            </a>
        </div>
        <div class="nav-section">
            <div class="nav-section-label">BOOKINGS</div>
            <a href="admin_bookings.php" class="nav-item">
                <i class="bi bi-calendar-check"></i><span>Bookings</span>
                <span class="nav-badge"><?= $totalBookings ?></span>
            </a>
            <a href="admin_disputes.php" class="nav-item">
                <i class="bi bi-flag"></i><span>Disputes</span>
                <span class="nav-badge dispute"><?= $pendingDisputes ?></span>
            </a>
        </div>
        <div class="nav-section">
            <div class="nav-section-label">REPORTS</div>
            <a href="admin_reports.php" class="nav-item">
                <i class="bi bi-graph-up"></i><span>Analytics</span>
            </a>
        </div>
    </nav>

    <div class="sidebar-footer">
        <div class="admin-info">
            <img src="<?= e($profilePic) ?>" alt="Admin" class="footer-avatar">
            <div class="admin-details">
                <span class="admin-name"><?= e($displayName) ?></span>
            </div>
        </div>
        <a href="logout.php" class="logout-icon"><i class="bi bi-box-arrow-right"></i></a>
    </div>
</aside>

<div class="main-content" id="mainContent">
        <div class="top-bar">
    <button class="menu-toggle" id="menuToggle"><i class="bi bi-list"></i></button>
    
    <!-- Mobile Logo (visible only on mobile) -->
    <div class="mobile-logo">
        <img src="<?= e($assetBase) ?>/logo.png" alt="Kyoshi" class="mobile-logo-img">
        <span class="mobile-logo-text">KYOSHI</span>
    </div>
    
    <!-- Desktop Title with Back Button Beside It -->
    <div class="page-title">
        <div class="title-with-back">
            <a href="admin_student_actions.php" class="back-btn-desktop">
                <i class="bi bi-arrow-left"></i>
                <span>Back</span>
            </a>
            <h1>Payment</h1>
        </div>
    </div>
    
    <div class="relative">
        <div class="admin-profile" onclick="toggleDropdown()">
            <img src="<?= e($profilePic) ?>" alt="Admin">
            <span><?= e($displayName) ?></span>
            <i class="bi bi-chevron-down"></i>
        </div>
        
        <!-- Mobile Profile Button -->
        <div class="mobile-profile-btn" onclick="toggleDropdown()">
            <img src="<?= e($profilePic) ?>" alt="Admin" class="mobile-profile-img">
        </div>
        
        <div class="dropdown" id="profileDropdown">
            <a href="admin_profile.php"><i class="bi bi-person-circle"></i> My Profile</a>
            <hr>
            <a href="logout.php" style="color:#dc2626;"><i class="bi bi-box-arrow-right"></i> Logout</a>
        </div>
    </div>
</div>

<!-- Mobile Page Header with Arrow Only (no text) -->
<div class="mobile-page-header" style="margin-top: 20px;">
    <div class="mobile-title-with-back">
        <a href="admin_student_actions.php" class="mobile-back-arrow">
            <i class="bi bi-arrow-left"></i>
        </a>
        <h1 class="mobile-page-title">Payments</h1>
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
                <strong style="font-size: 18px; margin-left: 10px;">URGENT - Class Starting Soon!</strong>
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
                                <div class="hours-warning" style="color: #ffc107;"><?= $urgent['hours_until_class'] ?> hours left</div>
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
    <?php
// Get pending refunds summary
$pending_refunds = $conn->query("
    SELECT COUNT(*) as count, 
           SUM(actual_paid_amount - amount) as total_amount
    FROM payments 
    WHERE status = 'verified' 
    AND refund_status = 'pending' 
    AND actual_paid_amount > amount
")->fetch_assoc();

$pending_count = $pending_refunds['count'] ?? 0;
$pending_total = $pending_refunds['total_amount'] ?? 0;
?>


    <!-- Filter Bar with Year/Month -->
    <form method="GET" class="filter-bar">
        <select name="status" class="filter-select">
            <option value="all" <?= $status_filter == 'all' ? 'selected' : '' ?>>All Status</option>
            <option value="pending" <?= $status_filter == 'pending' ? 'selected' : '' ?>>Pending (<?= $pendingCount ?>)</option>
            <option value="verified" <?= $status_filter == 'verified' ? 'selected' : '' ?>>Verified(<?= $verifiedCount ?>)</option>
            <option value="rejected" <?= $status_filter == 'rejected' ? 'selected' : '' ?>>Rejected(<?= $rejectedCount ?>)</option>
        </select>
        <select name="method" class="filter-select">
            <option value="all" <?= $method_filter == 'all' ? 'selected' : '' ?>>All Methods</option>
            <option value="online_banking">Online Banking</option>
            <option value="duitnow">DuitNow</option>
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
        <div class="filter-buttons">
        <button type="submit" class="filter-btn">Apply Filters</button>
        <?php if ($status_filter != 'all' || $method_filter != 'all' || $year_filter != 'all' || $month_filter != ''): ?>
        <button href="admin_payments.php" class="filter-btn" style="background:#64748b;">Clear All</button>
        <?php endif; ?>
        </div>
    </form>

    <!-- PAYMENTS TABLE with Month Grouping -->
    <div class="payments-container">
        <table id="paymentsTable">
            <thead>
                <tr>
                    <th style="width: 30px;"></th>
                    <th style="width: 100px;">Student</th>
                    <th style="width: 100px;">Tutor</th>
                    <th style="width: 80px;">Amount</th>
                    <th style="width: 100px;">Method</th>
                    <th style="width: 90px;">Status</th>
                    <th style="width: 140px;">Booking Date & Time</th>
                    <th style="width: 100px;">Pay At</th>
                    <th style="width: 80px;">Proof</th>
                    <th style="width: 180px;">Action</th>
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
                    elseif ($payment['status'] == 'partial_paid') {
    $statusClass = 'status-partial';
    $statusLabel = 'Partial Paid';
}
                    
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
                        
                        // ADDED FOR NOTES HANDLING:
                        $has_bulk_notes = false;
                        $compiled_notes = [];
                        
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
                        
                        // Create a clean line-broken string for the JS popup alert
                        $bulk_notes_js_string = implode('\n', $compiled_notes);
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
                        <td class="action-cell" style="justify-content:center;">
                                <?php 
                        // Determine bulk status from ALL items
                        $bulk_status = '';
                        $all_verified = true;
                        $all_rejected = true;
                        $any_pending = false;

                        if (!empty($bulk_items)) {
                            foreach ($bulk_items as $item) {
                                $stat = $item['status'] ?? '';
                                if ($stat != 'verified') $all_verified = false;
                                if ($stat != 'rejected') $all_rejected = false;
                                if ($stat == 'pending') $any_pending = true;
                            }
                            
                            if ($all_verified) {
                                $bulk_status = 'verified';
                            } elseif ($all_rejected) {
                                $bulk_status = 'rejected';
                            } elseif ($any_pending) {
                                $bulk_status = 'pending';
                            } else {
                                $bulk_status = 'mixed';
                            }
                        }
?>
    <?php if ($bulk_status == 'verified'): ?>
        <span class="status-badge status-verified">Verified</span>
    <?php elseif ($bulk_status == 'rejected'): ?>
        <span class="status-badge status-rejected">Rejected</span>
    <?php elseif ($bulk_status == 'mixed'): ?>
        <span class="status-badge status-pending">Mixed Status</span>
    <?php else: ?>
        <span class="status-badge status-pending">Pending</span>
    <?php endif; ?>
                            </td>

                        <td class="date-cell">
                            <?php 
                            if (!empty($bulk_items)) {
                                $dates = [];
                                $times = [];
                                foreach ($bulk_items as $item) {
                                    $dates[] = date('d M Y', strtotime($item['booking_date']));
                                    $times[] = date('g:i A', strtotime($item['booking_time']));
                                }
                                $unique_dates = array_unique($dates);
                                if (count($unique_dates) == 1) {
                                    // Same date – show date and time range
                                    $min_time = min($times);
                                    $max_time = max($times);
                                    if ($min_time == $max_time) {
                                        echo '<strong>' . $unique_dates[0] . '</strong><br>' . $min_time;
                                    } else {
                                        echo '<strong>' . $unique_dates[0] . '</strong><br>' . $min_time . ' - ' . $max_time;
                                    }
                                } else {
                                    // Different dates – show date range
                                    echo '<strong>' . min($unique_dates) . ' - ' . max($unique_dates) . '</strong><br>';
                                    echo '<small>Multiple dates</small>';
                                }
                            } else {
                                echo '<span style="color:#999;">No booking data</span>';
                            }
                            ?>
                         </td>
                        <td class="date-cell">
                            <strong><?= date('d M Y', strtotime($payment['created_at'])) ?></strong><br>
                            <?= date('h:i A', strtotime($payment['created_at'])) ?>
                         </td>
                                                <td>
                            <?php if (!empty($payment['proof_image'])): 
                                $proof_file = '../uploads/payment_proofs/' . $payment['proof_image'];
                                $is_pdf = strtolower(pathinfo($payment['proof_image'], PATHINFO_EXTENSION)) === 'pdf';
                                $is_image = preg_match('/\.(jpg|jpeg|png|gif|webp)$/i', $payment['proof_image']);
                            ?>
                                <?php if ($is_image): ?>
                                    <img src="<?= e($proof_file) ?>" 
                                        class="proof-image" 
                                        onclick="viewProofImage('<?= e($proof_file) ?>')"
                                        title="Click to view proof"
                                        style="width: 50px; height: 50px; object-fit: cover; border-radius: 8px; cursor: pointer;">
                                <?php elseif ($is_pdf): ?>
                                    <div style="display: flex; flex-direction: column; align-items: center; gap: 5px;">
                                        <i class="bi bi-file-earmark-pdf" style="font-size: 32px; color: #dc2626;"></i>
                                        <button onclick="viewProofFile('<?= e($proof_file) ?>', true)" 
                                                class="btn-verify" 
                                                style="padding: 2px 8px; font-size: 10px;">
                                            <i class="bi bi-eye"></i> View PDF
                                        </button>
                                    </div>
                                <?php else: ?>
                                    <a href="<?= e($proof_file) ?>" target="_blank" class="btn-verify" style="padding: 2px 8px; font-size: 10px;">
                                        <i class="bi bi-download"></i> Download
                                    </a>
                                <?php endif; ?>
                            <?php else: ?>
                                <span>-</span>
                            <?php endif; ?>
                        </td>
                        <td class="action-cell" style="width:100px;">
    <?php if ($all_pending): ?>
        <button class="btn-verify" onclick="event.stopPropagation(); verifyBulkPayment('<?= $bulk_receipt ?>')">Verify All</button>
        <button class="btn-reject" onclick="event.stopPropagation(); openRejectBulkModal('<?= $bulk_receipt ?>')">Reject All</button>
    <?php endif; ?>
    
    <?php if ($has_bulk_notes): ?>
        <button class="btn-view" onclick="event.stopPropagation(); showNotes('<?= addslashes($bulk_notes_js_string) ?>')" style="padding: 3px 8px; font-size: 11px;">
            Notes
        </button>
    <?php endif; ?>
    
    <?php if ($bulk_status == 'rejected'): ?>
        <?php 
        $bulk_items_json = json_encode(array_values($bulk_items));
        $bulk_items_json = htmlspecialchars($bulk_items_json, ENT_QUOTES, 'UTF-8');
        ?>
        <button class="btn-view" 
                onclick="event.stopPropagation(); showBulkRejectionReasons(JSON.parse('<?= $bulk_items_json ?>'))" 
                style="padding: 4px 10px; font-size: 11px; background: #dc2626; color: white; border: none; border-radius: 20px; cursor: pointer; display: inline-flex; align-items: center; gap: 4px;">
            <i class="bi bi-info-circle"></i> View Reasons
        </button>
    <?php endif; ?>
    
    <?php if ($bulk_status == 'verified'): 
        // Calculate total overpaid amount for this bulk group
        $total_overpaid = 0;
        $has_refunded = false;
        foreach ($bulk_items as $item) {
            $overpaid = ($item['actual_paid_amount'] ?? 0) - $item['amount'];
            if ($overpaid > 0) {
                if (isset($item['refund_status']) && $item['refund_status'] == 'completed') {
                    $has_refunded = true;
                } elseif (!isset($item['refund_status']) || $item['refund_status'] == 'pending') {
                    $total_overpaid += $overpaid;
                }
            }
        }
    ?>
        <?php if ($total_overpaid > 0): ?>
            <button class="btn-refund" 
                    onclick="event.stopPropagation(); markBulkAsRefunded(<?= htmlspecialchars(json_encode($bulk_items)) ?>, <?= $total_overpaid ?>)" 
                    style="background: #f59e0b; color: white; border: none; padding: 6px 12px; border-radius: 20px; cursor: pointer; font-size: 0.7rem; font-weight: 600;">
                <i class="bi bi-cash-stack"></i> Refund RM <?= number_format($total_overpaid, 2) ?>
            </button>
        <?php elseif ($has_refunded): ?>
            <span style="color: #059669; display: inline-flex; align-items: center; gap: 5px;">
                <i class="bi bi-check-circle"></i> Refunded
            </span>
        <?php else: ?>
            <span style="color: #059669;">Verified</span>
        <?php endif; ?>
    <?php endif; ?>
</td>
                     </tr>
 <!-- Bulk Detail Row -->
<tr class="bulk-detail-row" id="bulk_<?= $bulk_receipt ?>" style="display: none;">
    <td colspan="11" style="padding: 0;">
        <div class="bulk-detail-content">
            <table class="detail-table" style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="background: #f0f0f0; border-bottom: 1px solid #ddd;">
                        <th style="padding: 10px; text-align: left; width: 20%;">Tutor</th>
                        <th style="padding: 10px; text-align: left; width: 25%;">Booking Date & Time</th>
                        <th style="padding: 10px; text-align: left; width: 15%;">Amount</th>
                        <th style="padding: 10px; text-align: left; width: 10%;">Pay At</th>
                        <th style="padding: 10px; text-align: left; width: 10%;">Status</th>
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
                        </tr>
                    <?php endforeach; unset($item); ?>
                </tbody>
            </table>
        </div>
    </td>
</tr>
<?php else: ?>

                    <tr style="<?= $classSoon ? 'background: #fff3cd;' : '' ?>">
                        <td style="text-align: center;"></td>
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
                            <?php if (!empty($payment['proof_image'])): 
                                $proof_file = '../uploads/payment_proofs/' . $payment['proof_image'];
                                $is_pdf = strtolower(pathinfo($payment['proof_image'], PATHINFO_EXTENSION)) === 'pdf';
                                $is_image = preg_match('/\.(jpg|jpeg|png|gif|webp)$/i', $payment['proof_image']);
                            ?>
                                <?php if ($is_image): ?>
                                    <img src="<?= e($proof_file) ?>" 
                                        class="proof-image" 
                                        onclick="viewProofImage('<?= e($proof_file) ?>')"
                                        title="Click to view proof"
                                        style="width: 50px; height: 50px; object-fit: cover; border-radius: 8px; cursor: pointer;">
                                <?php elseif ($is_pdf): ?>
                                    <div style="display: flex; flex-direction: column; align-items: center; gap: 5px;">
                                        <i class="bi bi-file-earmark-pdf" style="font-size: 32px; color: #dc2626;"></i>
                                        <button onclick="viewProofFile('<?= e($proof_file) ?>', true)" 
                                                class="btn-verify" 
                                                style="padding: 2px 8px; font-size: 10px;">
                                            <i class="bi bi-eye"></i> View PDF
                                        </button>
                                    </div>
                                <?php else: ?>
                                    <a href="<?= e($proof_file) ?>" target="_blank" class="btn-verify" style="padding: 2px 8px; font-size: 10px;">
                                        <i class="bi bi-download"></i> Download
                                    </a>
                                <?php endif; ?>
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
                                <button class="btn-dispute" onclick="viewPaymentDispute(<?= $payment['id'] ?>, <?= $payment['booking_id'] ?>)">
                                    View Dispute
                                </button>
                            <?php elseif ($payment['status'] == 'verified'): ?>
                                <?php 
                                $overpaid = ($payment['actual_paid_amount'] ?? 0) - $payment['amount'];
                                if ($overpaid > 0 && isset($payment['refund_status']) && $payment['refund_status'] == 'pending'): 
                                ?>
                                    <button class="btn-refund" onclick="markAsRefunded(<?= $payment['id'] ?>, <?= $overpaid ?>)" 
                                            style="background: #f59e0b; color: white; border: none; padding: 8px 12px; border-radius: 20px; cursor: pointer; font-size: 0.7rem; font-weight: 600;">
                                        <i class="bi bi-cash-stack"></i> Refund RM <?= number_format($overpaid, 2) ?>
                                    </button>
                                <?php elseif ($overpaid > 0 && isset($payment['refund_status']) && $payment['refund_status'] == 'completed'): ?>
                                    <span style="color: #059669;">
                                        <i class="bi bi-check-circle"></i> Refunded
                                        <?php if ($payment['refund_receipt_number']): ?>
                                            <br><small>Ref: <?= e($payment['refund_receipt_number']) ?></small>
                                        <?php endif; ?>
                                    </span>
                                <?php else: ?>
                                    <span style="color: #059669;">Verified</span>
                                <?php endif; ?>
                            <?php elseif ($payment['status'] == 'rejected'): ?>
                                <?php if (!empty($payment['notes'])): ?>
                                    <button class="btn-view" 
                                            onclick="Swal.fire({title:'Rejection Reason', text:'<?= addslashes($payment['notes']) ?>', icon:'info'})"
                                            style="padding:2px 8px; font-size:10px; margin-left:5px; background: #dc2626; color: white; border: none; border-radius: 20px; cursor: pointer;">
                                        <i class="bi bi-info-circle"></i> Reason
                                    </button>
                                <?php endif; ?>
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
                </p>
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
        <form method="POST" id="rejectForm" onsubmit="return validateAndSubmitRejection();">
            <input type="hidden" name="payment_id" id="rejectPaymentId">
            <div class="modal-body">
                <div class="form-group">
                    <label>Rejection Type <span style="color: red;">*</span></label>
                    <select name="rejection_type" id="rejectionType" required onchange="toggleActualAmount()">
                        <option value="">Select reason...</option>
                        <option value="wrong_amount">Wrong Amount Paid (Underpaid)</option>
                        <option value="overpaid">Overpaid Amount</option>
                        <option value="invalid_proof">Invalid/Unclear Payment Proof</option>
                        <option value="unrelated_proof">Unrelated/Screenshot not from this payment</option>
                        <option value="other">Other Reason</option>
                    </select>
                </div>
                
                <div id="actualAmountDiv" style="display:none; margin-top: 15px;">
                    <div class="form-group">
                        <label id="actualAmountLabel">What amount did the student actually pay from screenshot proof?</label>
                        <input type="number" name="actual_paid_amount" id="actualPaidAmount" step="0.01" class="form-control" placeholder="e.g., 150">
                        <small id="actualAmountHint" style="font-size: 11px; color: #666;"></small>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Rejection Reason <span style="color: red;">*</span></label>
                    <textarea name="rejection_reason" id="rejectionReason" rows="3" placeholder="Please provide detailed reason for rejection..." required></textarea>
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
        <form method="POST" id="rejectBulkForm" onsubmit="return validateAndSubmitBulkRejection();">
            <input type="hidden" name="receipt_number" id="rejectBulkReceipt">
            <input type="hidden" name="reject_bulk_payment" value="1">
            <div class="modal-body">
                <div class="form-group">
                    <label>Rejection Type <span style="color: red;">*</span></label>
                    <select name="rejection_type" id="bulkRejectionType" required onchange="toggleBulkActualAmount()">
                        <option value="">Select reason...</option>
                        <option value="wrong_amount">Wrong Amount Paid (Underpaid)</option>
                        <option value="overpaid">Overpaid Amount</option>
                        <option value="invalid_proof">Invalid/Unclear Payment Proof</option>
                        <option value="unrelated_proof">Unrelated/Screenshot not from this payment</option>
                    </select>
                </div>

                <div id="bulkActualAmountDiv" style="display:none; margin-top: 15px;">
                    <div class="form-group">
                        <label>What amount did the student actually pay? (from proof)</label>
                        <input type="number" name="actual_paid_amount" id="bulkActualPaidAmount" step="0.01" class="form-control">
                        <small>Look at the payment proof screenshot. Enter the amount shown.</small>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Rejection Reason <span style="color: red;">*</span></label>
                    <textarea name="rejection_reason" id="rejectBulkReason" rows="3" placeholder="Please provide detailed reason for rejection..." required></textarea>
                </div>
                <p style="margin-top: 12px; font-size: 13px; color: #dc2626;">
                    <i class="bi bi-exclamation-triangle"></i> This will reject ALL payments in this bulk group.
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
    <div class="modal-container" style="max-width: 650px;">
        <div class="modal-header">
            <h3><i class="bi bi-exclamation-triangle-fill" style="color: #f59e0b;"></i> Resolve Dispute</h3>
            <button class="close-modal" onclick="closeDisputeModal()">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="payment_id" id="disputePaymentId">
            <div class="modal-body">
                <div class="form-group">
                    <label><i class="bi bi-chat-left-text"></i> Dispute Details</label>
                    <textarea id="disputeNotes" rows="8" readonly style="background: #fefce8; font-family: monospace; font-size: 12px; line-height: 1.6; width: 100%; resize: vertical;"></textarea>
                    <small style="color: #64748b; font-size: 11px;">Student's dispute message</small>
                </div>
                <div class="form-group">
                    <label><i class="bi bi-check-circle"></i> Resolution <span style="color: #dc2626;">*</span></label>
                    <textarea name="resolution" rows="4" placeholder="How was this dispute resolved? (e.g., Payment confirmed, refund issued, etc.)" required style="width: 100%;"></textarea>
                    <small style="color: #64748b; font-size: 11px;">This will be added to payment notes and the student will be notified.</small>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-reject" onclick="closeDisputeModal()" style="background: #64748b;">Cancel</button>
                <button type="submit" name="resolve_dispute" class="btn-verify">
                    <i class="bi bi-check2-all"></i> Resolve & Verify
                </button>
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
// Single toggle function - works for both open and close
function toggleDropdown() {
    const dropdown = document.getElementById('profileDropdown');
    if (!dropdown) return;
    
    // Toggle based on current display state
    const isVisible = dropdown.style.display === 'block';
    
    if (isVisible) {
        dropdown.style.display = 'none';
        dropdown.classList.remove('show');
    } else {
        dropdown.style.display = 'block';
        dropdown.classList.add('show');
    }
}

// Close dropdown when clicking outside (only one listener)
document.addEventListener('click', function(e) {
    const dropdown = document.getElementById('profileDropdown');
    const mobileProfileBtn = document.querySelector('.mobile-profile-btn');
    const desktopProfile = document.querySelector('.admin-profile');
    
    if (!dropdown) return;
    
    const isClickOnMobileBtn = mobileProfileBtn && mobileProfileBtn.contains(e.target);
    const isClickOnDesktop = desktopProfile && desktopProfile.contains(e.target);
    const isClickInsideDropdown = dropdown.contains(e.target);
    
    // If click is NOT on any profile button and NOT inside dropdown, close it
    if (!isClickOnMobileBtn && !isClickOnDesktop && !isClickInsideDropdown) {
        dropdown.style.display = 'none';
        dropdown.classList.remove('show');
    }
});

// Prevent dropdown from closing when clicking inside it
const dropdownEl = document.getElementById('profileDropdown');
if (dropdownEl) {
    dropdownEl.addEventListener('click', function(e) {
        e.stopPropagation();
    });
}

function openVerifyModal(paymentId) {
    document.getElementById('verifyPaymentId').value = paymentId;
    document.getElementById('verifyModal').style.display = 'flex';
}

function closeVerifyModal() {
    document.getElementById('verifyModal').style.display = 'none';
}

function openRejectModal(paymentId) {
    document.getElementById('rejectPaymentId').value = paymentId;
    document.getElementById('rejectionReason').value = '';
    document.getElementById('rejectionType').value = '';
    document.getElementById('actualAmountDiv').style.display = 'none';
    document.getElementById('rejectModal').style.display = 'flex';
    
    // Store payment ID for validation
    window.currentRejectPaymentId = paymentId;
}

// Add this new function for single reject validation
function validateAndSubmitRejection() {
    const paymentId = document.getElementById('rejectPaymentId').value;
    const rejectionType = document.getElementById('rejectionType').value;
    const reason = document.getElementById('rejectionReason').value;
    let actualAmount = null;
    
    // Get actual amount if showing
    const actualAmountDiv = document.getElementById('actualAmountDiv');
    if (actualAmountDiv.style.display === 'block') {
        actualAmount = parseFloat(document.getElementById('actualPaidAmount').value);
        if (isNaN(actualAmount) || actualAmount <= 0) {
            Swal.fire({
                title: 'Error',
                text: 'Please enter the actual amount paid',
                icon: 'error',
                confirmButtonColor: '#dc2626'
            });
            return false;
        }
    }
    
    if (!rejectionType) {
        Swal.fire({
            title: 'Error',
            text: 'Please select a rejection type',
            icon: 'error',
            confirmButtonColor: '#dc2626'
        });
        return false;
    }
    
    if (!reason) {
        Swal.fire({
            title: 'Error',
            text: 'Please provide a rejection reason',
            icon: 'error',
            confirmButtonColor: '#dc2626'
        });
        return false;
    }
    
    // Close the modal first
    closeRejectModal();
    
    // Show loading indicator
    Swal.fire({
        title: 'Validating...',
        text: 'Please wait',
        allowOutsideClick: false,
        didOpen: () => {
            Swal.showLoading();
        }
    });
    
    // Fetch payment details for validation
    fetch(`get_payment_details.php?payment_id=${paymentId}`)
        .then(response => response.json())
        .then(data => {
            Swal.close(); // Close loading
            if (data.success) {
                const expectedAmount = parseFloat(data.amount);
                if (rejectionType === 'wrong_amount' && actualAmount !== null && actualAmount > expectedAmount) {
    Swal.fire({
        title: 'Amount Mismatch',
        html: `You selected <strong>"Wrong Amount Paid (Underpaid)"</strong> but the amount you entered (RM ${actualAmount.toFixed(2)}) is <strong>GREATER THAN</strong> the expected amount (RM ${expectedAmount.toFixed(2)}).<br><br>
               Please select <strong>"Overpaid Amount"</strong> instead.`,
        icon: 'error',
        confirmButtonColor: '#dc2626'
    });
    // Re-open the modal
    document.getElementById('rejectModal').style.display = 'flex';
    return false;
}

if (rejectionType === 'overpaid' && actualAmount !== null && actualAmount < expectedAmount) {
    Swal.fire({
        title: 'Amount Mismatch',
        html: `You selected <strong>"Overpaid Amount"</strong> but the amount you entered (RM ${actualAmount.toFixed(2)}) is <strong>LESS THAN</strong> the expected amount (RM ${expectedAmount.toFixed(2)}).<br><br>
               Please select <strong>"Wrong Amount Paid (Underpaid)"</strong> instead.`,
        icon: 'error',
        confirmButtonColor: '#dc2626'
    });
    // Re-open the modal
    document.getElementById('rejectModal').style.display = 'flex';
    return false;
}

if (rejectionType === 'overpaid' && actualAmount !== null && actualAmount < expectedAmount) {
    Swal.fire({
        title: 'Amount Mismatch',
        html: `You selected <strong>"Overpaid Amount"</strong> but the amount you entered (RM ${actualAmount.toFixed(2)}) is <strong>LESS THAN</strong> the expected amount (RM ${expectedAmount.toFixed(2)}).<br><br>
               Please select <strong>"Wrong Amount Paid (Underpaid)"</strong> instead.`,
        icon: 'error',
        confirmButtonColor: '#dc2626'
    });
    // Re-open the modal
    document.getElementById('rejectModal').style.display = 'flex';
    return false;
}

                const isOverpaid = (actualAmount && actualAmount > expectedAmount);
                const isUnderpaid = (actualAmount && actualAmount < expectedAmount);
                const difference = actualAmount ? actualAmount - expectedAmount : 0;
                

                // Check if rejection type matches actual situation
                let mismatchWarning = '';
                if (rejectionType === 'overpaid' && isUnderpaid) {
                    mismatchWarning = `
                        <div style="background: #fee2e2; padding: 12px; border-radius: 8px; margin: 10px 0;">
                            <p style="color: #dc2626; margin: 0;">
                                <i class="bi bi-exclamation-triangle-fill"></i> 
                                <strong>WARNING:</strong> You selected "Overpaid Amount" but the actual amount (RM ${actualAmount.toFixed(2)}) is 
                                <strong>LESS THAN</strong> the expected amount (RM ${expectedAmount.toFixed(2)}).
                            </p>
                            <p style="color: #dc2626; margin: 5px 0 0 0; font-size: 12px;">
                                This should be treated as UNDERPAID, not OVERPAID.
                            </p>
                        </div>
                    `;
                } else if (rejectionType === 'wrong_amount' && isOverpaid) {
                    mismatchWarning = `
                        <div style="background: #fee2e2; padding: 12px; border-radius: 8px; margin: 10px 0;">
                            <p style="color: #dc2626; margin: 0;">
                                <i class="bi bi-exclamation-triangle-fill"></i> 
                                <strong>WARNING:</strong> You selected "Wrong Amount Paid (Underpaid)" but the actual amount (RM ${actualAmount.toFixed(2)}) is 
                                <strong>GREATER THAN</strong> the expected amount (RM ${expectedAmount.toFixed(2)}).
                            </p>
                            <p style="color: #dc2626; margin: 5px 0 0 0; font-size: 12px;">
                                This should be treated as OVERPAID, not UNDERPAID.
                            </p>
                        </div>
                    `;
                }
                
                let statusMessage = '';
                let statusColor = '';
                if (isOverpaid) {
                    statusMessage = `OVERPAID by RM ${difference.toFixed(2)}`;
                    statusColor = '#f59e0b';
                } else if (isUnderpaid) {
                    statusMessage = `UNDERPAID by RM ${Math.abs(difference).toFixed(2)}`;
                    statusColor = '#dc2626';
                } else if (actualAmount && actualAmount === expectedAmount) {
                    statusMessage = `EXACT AMOUNT`;
                    statusColor = '#059669';
                } else {
                    statusMessage = `No amount validation needed`;
                    statusColor = '#64748b';
                }
                
                // Build the confirmation HTML
                let confirmationHtml = `
                    <div style="text-align: left;">
                        <p><strong>Payment ID:</strong> ${paymentId}</p>
                        <p><strong>Expected Amount:</strong> RM ${expectedAmount.toFixed(2)}</p>
                `;
                
                if (actualAmount) {
                    confirmationHtml += `<p><strong>Actual Paid:</strong> RM ${actualAmount.toFixed(2)}</p>`;
                    confirmationHtml += `<p><strong>Status:</strong> <span style="color: ${statusColor}; font-weight: bold;">${statusMessage}</span></p>`;
                }
                
                confirmationHtml += `
                        <p><strong>Selected Type:</strong> ${rejectionType === 'overpaid' ? 'Overpaid Amount' : rejectionType === 'wrong_amount' ? 'Wrong Amount (Underpaid)' : rejectionType}</p>
                        ${mismatchWarning}
                        <hr>
                        <p><strong>Reason:</strong> ${reason}</p>
                        <p style="margin-top: 15px;">Are you sure you want to proceed?</p>
                    </div>
                `;
                
                // Show confirmation modal
                Swal.fire({
                    title: 'Confirm Payment Rejection',
                    html: confirmationHtml,
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#dc2626',
                    confirmButtonText: 'Yes, Reject Payment',
                    cancelButtonText: 'Cancel',
                    width: '550px',
                    allowOutsideClick: false
   }).then((result) => {
    if (result.isConfirmed) {
        // Use the variables already defined outside
        // paymentId, rejectionType, reason, actualAmount are already available
        
        // Create a hidden form and submit
        const hiddenForm = document.createElement('form');
        hiddenForm.method = 'POST';
        hiddenForm.action = '';
        
        hiddenForm.innerHTML = `
            <input type="hidden" name="reject_payment" value="1">
            <input type="hidden" name="payment_id" value="${paymentId}">
            <input type="hidden" name="rejection_type" value="${rejectionType}">
            <input type="hidden" name="rejection_reason" value="${reason.replace(/"/g, '&quot;')}">
            <input type="hidden" name="actual_paid_amount" value="${actualAmount !== null ? actualAmount : ''}">
        `;
        
        document.body.appendChild(hiddenForm);
        hiddenForm.submit();
    } else {
        document.getElementById('rejectModal').style.display = 'flex';
    }
});
            } else {
                Swal.fire({
                    title: 'Error',
                    text: 'Could not fetch payment details',
                    icon: 'error',
                    confirmButtonColor: '#dc2626'
                }).then(() => {
                    document.getElementById('rejectModal').style.display = 'flex';
                });
            }
        })
        .catch(error => {
            console.error('Error:', error);
            Swal.fire({
                title: 'Error',
                text: 'An error occurred',
                icon: 'error',
                confirmButtonColor: '#dc2626'
            }).then(() => {
                document.getElementById('rejectModal').style.display = 'flex';
            });
        });
    
    return false;
}

function closeRejectModal() {
    document.getElementById('rejectModal').style.display = 'none';
}

function openRejectBulkModal(receiptNumber) {
    document.getElementById('rejectBulkReceipt').value = receiptNumber;
    document.getElementById('rejectBulkReason').value = '';
    document.getElementById('rejectBulkModal').style.display = 'flex';
    
    // Also store the receipt number for validation
    window.currentBulkReceipt = receiptNumber;
}
function validateAndSubmitBulkRejection() {
    const receiptNumber = document.getElementById('rejectBulkReceipt').value;
    const rejectionType = document.getElementById('bulkRejectionType').value;
    const actualAmount = parseFloat(document.getElementById('bulkActualPaidAmount').value);
    const reason = document.getElementById('rejectBulkReason').value;
    
    if (!rejectionType) {
        Swal.fire({
            title: 'Error',
            text: 'Please select a rejection type',
            icon: 'error',
            confirmButtonColor: '#dc2626'
        });
        return false;
    }
    
    if (isNaN(actualAmount) || actualAmount <= 0) {
        Swal.fire({
            title: 'Error',
            text: 'Please enter the actual amount paid',
            icon: 'error',
            confirmButtonColor: '#dc2626'
        });
        return false;
    }
    
    if (!reason) {
        Swal.fire({
            title: 'Error',
            text: 'Please provide a rejection reason',
            icon: 'error',
            confirmButtonColor: '#dc2626'
        });
        return false;
    }
    
    // Close the bulk modal first
    closeRejectBulkModal();
    
    // Show loading indicator
    Swal.fire({
        title: 'Validating...',
        text: 'Please wait',
        allowOutsideClick: false,
        didOpen: () => {
            Swal.showLoading();
        }
    });
    
    // Fetch the total expected amount for validation
    fetch(`get_bulk_payment_total.php?receipt_number=${receiptNumber}`)
        .then(response => response.json())
        .then(data => {
            Swal.close(); // Close loading
            if (data.success) {
                const totalExpected = parseFloat(data.total_expected);
                const difference = actualAmount - totalExpected;
                const isOverpaid = (actualAmount > totalExpected);
                const isUnderpaid = (actualAmount < totalExpected);
                
                // Check if the rejection type matches the actual situation
                let mismatchWarning = '';
                if (rejectionType === 'overpaid' && isUnderpaid) {
                    mismatchWarning = `
                        <div style="background: #fee2e2; padding: 12px; border-radius: 8px; margin: 10px 0;">
                            <p style="color: #dc2626; margin: 0;">
                                <i class="bi bi-exclamation-triangle-fill"></i> 
                                <strong>WARNING:</strong> You selected "Overpaid Amount" but the actual amount (RM ${actualAmount.toFixed(2)}) is 
                                <strong>LESS THAN</strong> the expected amount (RM ${totalExpected.toFixed(2)}).
                            </p>
                            <p style="color: #dc2626; margin: 5px 0 0 0; font-size: 12px;">
                                This should be treated as UNDERPAID, not OVERPAID.
                            </p>
                        </div>
                    `;
                } else if (rejectionType === 'wrong_amount' && isOverpaid) {
                    mismatchWarning = `
                        <div style="background: #fee2e2; padding: 12px; border-radius: 8px; margin: 10px 0;">
                            <p style="color: #dc2626; margin: 0;">
                                <i class="bi bi-exclamation-triangle-fill"></i> 
                                <strong>WARNING:</strong> You selected "Wrong Amount Paid (Underpaid)" but the actual amount (RM ${actualAmount.toFixed(2)}) is 
                                <strong>GREATER THAN</strong> the expected amount (RM ${totalExpected.toFixed(2)}).
                            </p>
                            <p style="color: #dc2626; margin: 5px 0 0 0; font-size: 12px;">
                                This should be treated as OVERPAID, not UNDERPAID.
                            </p>
                        </div>
                    `;
                }
                
                let statusMessage = '';
                let statusColor = '';
                if (isOverpaid) {
                    statusMessage = `OVERPAID by RM ${difference.toFixed(2)}`;
                    statusColor = '#f59e0b';
                } else if (isUnderpaid) {
                    statusMessage = `UNDERPAID by RM ${Math.abs(difference).toFixed(2)}`;
                    statusColor = '#dc2626';
                } else {
                    statusMessage = `EXACT AMOUNT`;
                    statusColor = '#059669';
                }
                
                // Show confirmation modal
                Swal.fire({
                    title: 'Confirm Payment Processing',
                    html: `
                        <div style="text-align: left;">
                            <p><strong>Receipt:</strong> ${receiptNumber}</p>
                            <p><strong>Total Expected:</strong> RM ${totalExpected.toFixed(2)}</p>
                            <p><strong>Actual Paid:</strong> RM ${actualAmount.toFixed(2)}</p>
                            <p><strong>Status:</strong> <span style="color: ${statusColor}; font-weight: bold;">${statusMessage}</span></p>
                            <p><strong>Selected Type:</strong> ${rejectionType === 'overpaid' ? 'Overpaid Amount' : 'Wrong Amount (Underpaid)'}</p>
                            ${mismatchWarning}
                            <hr>
                            <p><strong>Reason:</strong> ${reason}</p>
                            <p style="margin-top: 15px;">Are you sure you want to proceed?</p>
                        </div>
                    `,
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#f59e0b',
                    confirmButtonText: 'Yes, Process',
                    cancelButtonText: 'Cancel',
                    width: '550px',
                    allowOutsideClick: false
                }).then((result) => {
                    if (result.isConfirmed) {
                        // Submit the form
                        const form = document.getElementById('rejectBulkForm');
                        form.submit();
                    } else {
                        // Re-open the bulk modal if user cancels
                        document.getElementById('rejectBulkModal').style.display = 'flex';
                    }
                });
            } else {
                Swal.fire({
                    title: 'Error',
                    text: 'Could not fetch payment details',
                    icon: 'error',
                    confirmButtonColor: '#dc2626'
                }).then(() => {
                    // Re-open the bulk modal
                    document.getElementById('rejectBulkModal').style.display = 'flex';
                });
            }
        })
        .catch(error => {
            console.error('Error:', error);
            Swal.fire({
                title: 'Error',
                text: 'An error occurred',
                icon: 'error',
                confirmButtonColor: '#dc2626'
            }).then(() => {
                // Re-open the bulk modal
                document.getElementById('rejectBulkModal').style.display = 'flex';
            });
        });
    
    return false;
}

function closeRejectBulkModal() {
    document.getElementById('rejectBulkModal').style.display = 'none';
}
function openDisputeModal(paymentId, notes) {
    document.getElementById('disputePaymentId').value = paymentId;
    
    // Also fetch dispute details including proof image
    fetch(`get_dispute_details.php?payment_id=${paymentId}`)
        .then(response => response.json())
        .then(data => {
            const notesTextarea = document.getElementById('disputeNotes');
            let displayHtml = '';
            
            if (data.success && data.dispute) {
                // Show proof image if exists
                if (data.dispute.proof_image) {
                    displayHtml += `
                        <div style="margin-bottom: 15px;">
                            <label><strong>Student's Proof Attachment:</strong></label>
                            <div style="margin-top: 8px;">
                                <a href="../${data.dispute.proof_image}" target="_blank" class="btn-action ghost" style="display: inline-flex; align-items: center; gap: 5px;">
                                    <i class="bi bi-file-earmark-image"></i> View Proof Image
                                </a>
                                <button onclick="viewProofImage('../${data.dispute.proof_image}')" class="btn-action ghost" style="display: inline-flex; align-items: center; gap: 5px;">
                                    <i class="bi bi-eye"></i> Preview
                                </button>
                            </div>
                        </div>
                        <hr>
                    `;
                }
                
                // Format dispute message
                let formattedMessage = data.dispute.message || notes || 'No dispute details provided.';
                formattedMessage = formattedMessage.replace(/\n/g, '<br>');
                displayHtml += `<div style="font-family: monospace; font-size: 12px; line-height: 1.6; background: #fefce8; padding: 12px; border-radius: 8px;">${formattedMessage}</div>`;
                
                notesTextarea.value = displayHtml;
                notesTextarea.style.height = 'auto';
                notesTextarea.style.height = notesTextarea.scrollHeight + 'px';
            } else {
                // Fallback to old method
                let decodedNotes = (notes || 'No dispute details provided.')
                    .replace(/&lt;/g, '<')
                    .replace(/&gt;/g, '>')
                    .replace(/&amp;/g, '&')
                    .replace(/\\n/g, '\n');
                
                decodedNotes = decodedNotes.replace(/\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]/g, '[$1]\n');
                notesTextarea.value = decodedNotes;
                notesTextarea.style.height = 'auto';
                notesTextarea.style.height = notesTextarea.scrollHeight + 'px';
            }
        })
        .catch(error => {
            // Fallback
            let decodedNotes = (notes || 'No dispute details provided.')
                .replace(/&lt;/g, '<')
                .replace(/&gt;/g, '>')
                .replace(/&amp;/g, '&')
                .replace(/\\n/g, '\n');
            
            decodedNotes = decodedNotes.replace(/\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]/g, '[$1]\n');
            notesTextarea.value = decodedNotes;
            notesTextarea.style.height = 'auto';
            notesTextarea.style.height = notesTextarea.scrollHeight + 'px';
        });
    
    document.getElementById('disputeModal').style.display = 'flex';
}
function closeDisputeModal() {
    document.getElementById('disputeModal').style.display = 'none';
}function showBulkRejectionReasons(items) {
    let reasonsHtml = '<div style="max-height: 350px; overflow-y: auto; padding-right: 5px;">';
    
    items.forEach(function(item, index) {
        if (item.status === 'rejected') {
            let rejectionType = item.rejection_type || 'other';
            let rejectionNote = item.notes || 'No reason provided';
            
            // Format rejection type nicely
            let typeLabel = '';
            let typeIcon = '';
            switch(rejectionType) {
                case 'wrong_amount':
                    typeLabel = 'Wrong Amount Paid (Underpaid)';
                    typeIcon = '💰';
                    break;
                case 'overpaid':
                    typeLabel = 'Overpaid Amount';
                    typeIcon = '💸';
                    break;
                case 'invalid_proof':
                    typeLabel = 'Invalid/Unclear Payment Proof';
                    typeIcon = '📷';
                    break;
                case 'unrelated_proof':
                    typeLabel = 'Unrelated Screenshot';
                    typeIcon = '🖼️';
                    break;
                case 'underpaid_bulk':
                    typeLabel = 'Underpaid (Bulk Payment)';
                    typeIcon = '⚠️';
                    break;
                default:
                    typeLabel = 'Other Reason';
                    typeIcon = '❓';
            }
            
            reasonsHtml += `
                <div style="border-left: 3px solid #dc2626; border-radius: 8px; padding: 10px 12px; margin-bottom: 10px; background: #fef2f2;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                        <strong style="color: #991b1b; font-size: 13px;">${typeIcon} Booking #${item.booking_id}</strong>
                        <span style="font-size: 10px; color: #666;">${item.booking_date} ${item.booking_time}</span>
                    </div>
                    <div style="margin-bottom: 4px; font-size: 12px;">
                        <span style="font-weight: 600;">Amount:</span> RM ${parseFloat(item.amount).toFixed(2)}
                    </div>
                    <div style="margin-bottom: 4px; font-size: 12px;">
                        <span style="font-weight: 600;">Type:</span> ${typeLabel}
                    </div>
                    <div style="margin-top: 6px;">
                        <div style="background: white; padding: 6px 8px; border-radius: 6px; font-size: 11px; color: #991b1b; max-height: 60px; overflow-y: auto;">
                            ${rejectionNote.replace(/\n/g, '<br>')}
                        </div>
                    </div>
                </div>
            `;
        }
    });
    
    reasonsHtml += '</div>';
    
    Swal.fire({
        title: 'Rejection Reasons',
        html: reasonsHtml,
        icon: 'info',
        showCloseButton: true,
        showConfirmButton: false,
        confirmButtonColor: '#E75A9B',
        width: '500px',
        customClass: {
            popup: 'rejection-reasons-popup'
        }
    });
}

function showSingleRejectionReason(note, rejectionType, amount, bookingDate, bookingTime) {
    let typeLabel = '';
    let typeIcon = '';
    switch(rejectionType) {
        case 'wrong_amount':
            typeLabel = 'Wrong Amount Paid (Underpaid)';
            typeIcon = '💰';
            break;
        case 'overpaid':
            typeLabel = 'Overpaid Amount';
            typeIcon = '💸';
            break;
        case 'invalid_proof':
            typeLabel = 'Invalid/Unclear Payment Proof';
            typeIcon = '📷';
            break;
        case 'unrelated_proof':
            typeLabel = 'Unrelated Screenshot';
            typeIcon = '🖼️';
            break;
        case 'underpaid_bulk':
            typeLabel = 'Underpaid (Bulk Payment)';
            typeIcon = '⚠️';
            break;
        default:
            typeLabel = 'Other Reason';
            typeIcon = '❓';
    }
    
    Swal.fire({
        title: 'Rejection Reason',
        html: `
            <div style="text-align: left;">
                <div style="background: #fef2f2; border-radius: 8px; padding: 12px; margin-bottom: 10px;">
                    <div style="margin-bottom: 8px;">
                        <span style="font-weight: 600;">📅 Booking:</span>
                        <span style="font-size: 13px;">${bookingDate || ''} ${bookingTime || ''}</span>
                    </div>
                    <div style="margin-bottom: 8px;">
                        <span style="font-weight: 600;">${typeIcon} Type:</span>
                        <span style="font-size: 13px;">${typeLabel}</span>
                    </div>
                    <div style="margin-bottom: 8px;">
                        <span style="font-weight: 600;">💵 Amount:</span>
                        <span style="font-size: 13px;">RM ${parseFloat(amount).toFixed(2)}</span>
                    </div>
                    <div style="margin-top: 8px;">
                        <div style="font-weight: 600; margin-bottom: 4px;">📝 Reason:</div>
                        <div style="background: white; padding: 8px; border-radius: 6px; font-size: 12px; color: #991b1b; max-height: 100px; overflow-y: auto;">
                            ${note.replace(/\n/g, '<br>')}
                        </div>
                    </div>
                </div>
            </div>
        `,
        icon: 'info',
        showCloseButton: true,
        showConfirmButton: false,
        width: '450px'
    });
}
function toggleActualAmount() {
    const type = document.getElementById('rejectionType').value;
    const div = document.getElementById('actualAmountDiv');
    const amountInput = document.getElementById('actualPaidAmount');
    const label = document.getElementById('actualAmountLabel');
    const hint = document.getElementById('actualAmountHint');
    
    if (type === 'wrong_amount') {
        div.style.display = 'block';
        label.innerHTML = 'What amount did the student actually pay? (RM)';
        hint.innerHTML = '⚠️ LESS than expected. Student will need to pay the remaining amount.';
        amountInput.setAttribute('required', 'required');
        amountInput.style.borderColor = '#dc2626';
    } else if (type === 'overpaid') {
        div.style.display = 'block';
        label.innerHTML = 'What amount did the student actually pay? (RM)';
        hint.innerHTML = '✅ MORE than expected. Student will receive a refund for the overpaid amount.';
        amountInput.setAttribute('required', 'required');
        amountInput.style.borderColor = '#059669';
    } else {
        div.style.display = 'none';
        amountInput.removeAttribute('required');
        amountInput.style.borderColor = '';
        amountInput.value = '';
    }
}

function toggleBulkActualAmount() {
    const type = document.getElementById('bulkRejectionType').value;
    const div = document.getElementById('bulkActualAmountDiv');
    div.style.display = (type === 'wrong_amount' || type === 'overpaid') ? 'block' : 'none';
}

function viewProofImage(src) {
    document.getElementById('fullImage').src = src;
    document.getElementById('imageModal').style.display = 'flex';
}

function viewProofFile(filePath, isPDF) {
    if (isPDF) {
        window.open(filePath, '_blank');
    } else {
        viewProofImage(filePath);
    }
}

function viewPaymentDispute(paymentId, bookingId) {
    fetch(`get_payment_dispute.php?payment_id=${paymentId}&booking_id=${bookingId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                Swal.fire({
                    title: 'Payment Dispute Details',
                    html: `
                        <div style="text-align: left;">
                            <p><strong>Student:</strong> ${data.dispute.student_name}</p>
                            <p><strong>Tutor:</strong> ${data.dispute.tutor_name}</p><br>
                            <p><strong>Issue Type:</strong> ${data.dispute.issue_type}</p>
                            <p><strong>Requested Resolution:</strong> ${data.dispute.resolution_type}</p>
                            <p><strong>Status:</strong> ${data.dispute.status}</p><br>
                            <hr>
                            <p><strong>Student Message:</strong></p>
                            <div style="background: #f5f5f5; padding: 12px; border-radius: 8px;">
                                ${data.dispute.message.replace(/\n/g, '<br>')}
                            </div>
                            ${data.dispute.resolution_note ? `<hr><p><strong>Resolution Note:</strong> ${data.dispute.resolution_note}</p>` : ''}
                        </div>
                    `,
                    icon: 'info',
                    confirmButtonColor: '#E75A9B',
                    showCancelButton: true,
                    cancelButtonText: 'Close',
                    confirmButtonText: 'Resolve Dispute',
                    width: '600px'
                }).then((result) => {
                    if (result.isConfirmed) {
                        window.location.href = `admin_resolve_dispute.php?id=${data.dispute.id}&type=payment`;
                    }
                });
            } else {
                showToast('Error loading dispute details', true);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showToast('Error loading dispute', true);
        });
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
}function markBulkAsRefunded(bulkItems, totalRefundAmount) {
    // Collect all payment IDs that have overpaid amounts
    const overpaidPayments = bulkItems.filter(item => {
        const overpaid = (item.actual_paid_amount || 0) - item.amount;
        return overpaid > 0 && item.refund_status === 'pending';
    });
    
    if (overpaidPayments.length === 0) {
        Swal.fire({
            title: 'No Refunds Pending',
            text: 'No overpaid payments need refund in this bulk group.',
            icon: 'info',
            confirmButtonColor: '#E75A9B'
        });
        return;
    }
    
    // CHECK ALL PAYMENTS for bank details (not just the first one)
    let bankDetailsFound = null;
    let checkPromises = overpaidPayments.map(payment => {
        return fetch(`get_payment_bank_details.php?payment_id=${payment.id}`)
            .then(response => response.json())
            .then(data => {
                if (data.success && data.bank_name && !bankDetailsFound) {
                    bankDetailsFound = data;
                }
                return data;
            });
    });
    
    Promise.all(checkPromises).then(() => {
        let bankHtml = '';
        let showBankInputs = true;
        
        if (bankDetailsFound && bankDetailsFound.bank_name) {
            // Bank details found in one of the payments
            bankHtml = `
                <div style="background: #e0f2fe; padding: 12px; border-radius: 8px; margin-bottom: 15px;">
                    <p style="margin: 0 0 8px 0; font-weight: 700;">
                        <i class="bi bi-bank"></i> Student's Bank Details:
                    </p>
                    <p style="margin: 0;"><strong>Bank:</strong> ${bankDetailsFound.bank_name}</p>
                    <p style="margin: 0;"><strong>Account Number:</strong> ${bankDetailsFound.bank_account_number}</p>
                    <p style="margin: 0;"><strong>Account Name:</strong> ${bankDetailsFound.bank_account_name}</p>
                </div>
            `;
            showBankInputs = false;
        } else {
            bankHtml = `
                <div style="background: #fef3c7; padding: 12px; border-radius: 8px; margin-bottom: 15px;">
                    <p style="margin: 0; color: #d97706;">
                        <i class="bi bi-exclamation-triangle"></i> 
                        <strong>No bank details found.</strong>
                    </p>
                    <p style="margin: 5px 0 0 0; font-size: 12px;">Please enter bank details manually below.</p>
                </div>
            `;
            showBankInputs = true;
        }
        
        // Build HTML list of overpaid payments
        let paymentsList = '';
        overpaidPayments.forEach(payment => {
            const overpaid = (payment.actual_paid_amount || 0) - payment.amount;
            paymentsList += `
                <div style="border-bottom: 1px solid #eee; padding: 6px 0; font-size: 12px;">
                    <strong>Booking #${payment.booking_id}</strong> - 
                    Tutor: ${payment.tutor_name}<br>
                    Overpaid: <strong style="color: #059669;">RM ${overpaid.toFixed(2)}</strong>
                </div>
            `;
        });
        
        Swal.fire({
            title: 'Process Bulk Refund',
            html: `
                <div style="text-align: left; font-size: 13px;">
                    <p><strong>Total refund amount:</strong> <span style="color: #059669;">RM ${totalRefundAmount.toFixed(2)}</span></p>
                    <p><strong>${overpaidPayments.length} payment(s) to refund:</strong></p>
                    <div style="max-height: 120px; overflow-y: auto; margin: 8px 0; border: 1px solid #eee; border-radius: 8px; padding: 6px; background: #f9f9f9;">
                        ${paymentsList}
                    </div>
                    
                    ${bankHtml}
                    
                    ${showBankInputs ? `
                        <div style="background: #f0fdf4; padding: 12px; border-radius: 8px; margin-top: 10px;">
                            <label style="font-weight: 700; font-size: 12px;">Enter bank details manually:</label>
                            <div class="form-group" style="margin-top: 8px;">
                                <input type="text" id="swal_bank_name" class="swal2-input" placeholder="Bank Name" style="width: 100%; padding: 8px; margin-bottom: 8px; border: 1px solid #ddd; border-radius: 6px;">
                                <input type="text" id="swal_bank_account" class="swal2-input" placeholder="Account Number" style="width: 100%; padding: 8px; margin-bottom: 8px; border: 1px solid #ddd; border-radius: 6px;">
                                <input type="text" id="swal_account_name" class="swal2-input" placeholder="Account Holder Name" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 6px;">
                            </div>
                        </div>
                    ` : ''}
                    
                    <div class="form-group" style="margin-top: 12px;">
                        <label style="font-size: 12px; font-weight: 600;">Refund Receipt Number (optional):</label>
                        <input type="text" id="swal_refund_receipt" class="swal2-input" placeholder="Auto-generated if empty" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 6px;">
                    </div>
                    
                    <div style="margin-top: 12px; padding: 8px; background: #fef3c7; border-radius: 8px; font-size: 12px;">
                        <i class="bi bi-exclamation-triangle"></i> 
                        Confirm you have transferred <strong>RM ${totalRefundAmount.toFixed(2)}</strong> to the student's bank account.
                    </div>
                </div>
            `,
            icon: 'question',
            showCancelButton: true,
            showCloseButton: true,
            confirmButtonColor: '#f59e0b',
            confirmButtonText: 'Yes, Process Refunds',
            cancelButtonText: 'Cancel',
            width: '500px',
            preConfirm: () => {
                if (showBankInputs) {
                    const bankName = document.getElementById('swal_bank_name').value;
                    const bankAccount = document.getElementById('swal_bank_account').value;
                    const accountName = document.getElementById('swal_account_name').value;
                    
                    if (!bankName || !bankAccount || !accountName) {
                        Swal.showValidationMessage('Please provide bank account details for the refund');
                        return false;
                    }
                    return {
                        refundReceipt: document.getElementById('swal_refund_receipt').value,
                        bank_name: bankName,
                        bank_account_number: bankAccount,
                        bank_account_name: accountName
                    };
                } else {
                    return {
                        refundReceipt: document.getElementById('swal_refund_receipt').value,
                        bank_name: bankDetailsFound.bank_name,
                        bank_account_number: bankDetailsFound.bank_account_number,
                        bank_account_name: bankDetailsFound.bank_account_name
                    };
                }
            }
        }).then((result) => {
            if (result.isConfirmed) {
                Swal.fire({
                    title: 'Processing Refunds...',
                    text: 'Please wait',
                    allowOutsideClick: false,
                    didOpen: () => {
                        Swal.showLoading();
                    }
                });
                
                const promises = overpaidPayments.map(payment => {
                    const overpaid = (payment.actual_paid_amount || 0) - payment.amount;
                    const receipt = result.value.refundReceipt 
                        ? `${result.value.refundReceipt}-${payment.id}` 
                        : `RFD-${Date.now()}-${payment.id}`;
                    
                    return fetch('mark_refunded.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            payment_id: payment.id,
                            refund_amount: overpaid,
                            refund_receipt: receipt,
                            bank_name: result.value.bank_name,
                            bank_account_number: result.value.bank_account_number,
                            bank_account_name: result.value.bank_account_name
                        })
                    }).then(response => response.json());
                });
                
                Promise.all(promises)
                    .then(results => {
                        const allSuccess = results.every(r => r.success);
                        if (allSuccess) {
                            Swal.fire({
                                icon: 'success',
                                title: 'Refunds Processed',
                                html: `${overpaidPayments.length} payment(s) marked as refunded.<br><small>Refund emails sent to student.</small>`,
                                confirmButtonColor: '#E75A9B'
                            }).then(() => {
                                location.reload();
                            });
                        } else {
                            const failedCount = results.filter(r => !r.success).length;
                            Swal.fire({
                                icon: 'error',
                                title: 'Partial Error',
                                text: `${failedCount} of ${overpaidPayments.length} refunds failed. Please try again.`,
                                confirmButtonColor: '#dc2626'
                            });
                        }
                    })
                    .catch(error => {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: 'Error processing refunds. Please try again.',
                            confirmButtonColor: '#dc2626'
                        });
                    });
            }
        });
    }).catch(error => {
        console.error('Error checking bank details:', error);
        // Fallback - proceed with manual entry
        Swal.fire({
            title: 'Process Bulk Refund',
            html: `
                <div style="text-align: left;">
                    <p>Total refund amount: <strong>RM ${totalRefundAmount.toFixed(2)}</strong></p>
                    <div class="form-group">
                        <label>Bank Name:</label>
                        <input type="text" id="fallbackBankName" class="swal2-input" placeholder="Bank Name">
                    </div>
                    <div class="form-group">
                        <label>Account Number:</label>
                        <input type="text" id="fallbackAccountNumber" class="swal2-input" placeholder="Account Number">
                    </div>
                    <div class="form-group">
                        <label>Account Holder Name:</label>
                        <input type="text" id="fallbackAccountName" class="swal2-input" placeholder="Account Holder Name">
                    </div>
                </div>
            `,
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#f59e0b',
            confirmButtonText: 'Yes, Process Refunds',
            preConfirm: () => {
                const bankName = document.getElementById('fallbackBankName').value;
                const bankAccount = document.getElementById('fallbackAccountNumber').value;
                const accountName = document.getElementById('fallbackAccountName').value;
                
                if (!bankName || !bankAccount || !accountName) {
                    Swal.showValidationMessage('Please provide bank account details');
                    return false;
                }
                return { bank_name: bankName, bank_account_number: bankAccount, bank_account_name: accountName };
            }
        }).then((result) => {
            if (result.isConfirmed) {
                // Process refunds with manual bank details
                // ... similar processing as above
            }
        });
    });
}
function markAsRefunded(paymentId, refundAmount) {
    // First, fetch student's bank details if already provided
    fetch(`get_payment_bank_details.php?payment_id=${paymentId}`)
        .then(response => response.json())
        .then(data => {
            let bankHtml = '';
            let showBankInputs = true;
            
            if (data.success && data.bank_name) {
                // Bank details already provided by student
                bankHtml = `
                    <div style="background: #e0f2fe; padding: 12px; border-radius: 8px; margin-top: 10px;">
                        <p style="margin: 0 0 8px 0; font-weight: 700;">
                            <i class="bi bi-bank"></i> Student's Bank Details (Provided):
                        </p>
                        <p style="margin: 0;"><strong>Bank:</strong> ${data.bank_name}</p>
                        <p style="margin: 0;"><strong>Account Number:</strong> ${data.bank_account_number}</p>
                        <p style="margin: 0;"><strong>Account Name:</strong> ${data.bank_account_name}</p>
                    </div>
                `;
                showBankInputs = false;
            } else {
                bankHtml = `
                    <div style="background: #fef3c7; padding: 12px; border-radius: 8px; margin-top: 10px;">
                        <p style="margin: 0; color: #d97706;">
                            <i class="bi bi-exclamation-triangle"></i> 
                            <strong>Waiting for student to provide bank details.</strong>
                        </p>
                        <p style="margin: 5px 0 0 0; font-size: 12px;">Student needs to click "Provide Bank Details" in their My Payments page.</p>
                    </div>
                `;
                showBankInputs = true;
            }
            
            Swal.fire({
                title: 'Process Refund',
                html: `
                    <div style="text-align: left;">
                        <p>Refund amount: <strong style="color: #059669;">RM ${parseFloat(refundAmount).toFixed(2)}</strong></p>
                        ${bankHtml}
                        ${showBankInputs ? `
                            <div style="background: #f0fdf4; padding: 12px; border-radius: 8px; margin-top: 10px;">
                                <label style="font-weight: 700;">Or enter bank details manually:</label>
                                <div class="form-group" style="margin-top: 8px;">
                                    <input type="text" id="bank_name" class="form-control" placeholder="Bank Name" style="width: 100%; padding: 8px; margin-bottom: 8px;">
                                    <input type="text" id="bank_account_number" class="form-control" placeholder="Account Number" style="width: 100%; padding: 8px; margin-bottom: 8px;">
                                    <input type="text" id="bank_account_name" class="form-control" placeholder="Account Holder Name" style="width: 100%; padding: 8px;">
                                </div>
                            </div>
                        ` : ''}
                        <div class="form-group" style="margin-top: 15px;">
                            <label>Refund Receipt Number:</label>
                            <input type="text" id="refundReceipt" class="form-control" placeholder="Auto-generated if empty">
                        </div>
                        <p style="margin-top: 15px; padding: 10px; background: #fef3c7; border-radius: 8px;">
                            <i class="bi bi-exclamation-triangle"></i> 
                            Confirm you have transferred <strong>RM ${parseFloat(refundAmount).toFixed(2)}</strong> to the student's bank account.
                        </p>
                    </div>
                `,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#f59e0b',
                confirmButtonText: 'Yes, Mark as Refunded',
                cancelButtonText: 'Cancel',
                preConfirm: () => {
                    const refundReceipt = document.getElementById('refundReceipt').value;
                    
                    if (showBankInputs) {
                        const bankName = document.getElementById('bank_name').value;
                        const bankAccount = document.getElementById('bank_account_number').value;
                        const bankAccountName = document.getElementById('bank_account_name').value;
                        
                        if (!bankName || !bankAccount || !bankAccountName) {
                            Swal.showValidationMessage('Please provide bank account details for the refund');
                            return false;
                        }
                        return { refundReceipt, bank_name: bankName, bank_account_number: bankAccount, bank_account_name: bankAccountName };
                    } else {
                        return { refundReceipt };
                    }
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    const postData = { 
                        payment_id: paymentId, 
                        refund_amount: refundAmount,
                        refund_receipt: result.value.refundReceipt
                    };
                    
                    if (result.value.bank_name) {
                        postData.bank_name = result.value.bank_name;
                        postData.bank_account_number = result.value.bank_account_number;
                        postData.bank_account_name = result.value.bank_account_name;
                    }
                    
                    fetch('mark_refunded.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify(postData)
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            Swal.fire({
                                icon: 'success',
                                title: 'Refund Marked',
                                html: `${data.message}<br><small>Receipt: ${data.refund_receipt}</small>`,
                                confirmButtonColor: '#E75A9B'
                            }).then(() => {
                                location.reload();
                            });
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: 'Error',
                                text: data.message,
                                confirmButtonColor: '#dc2626'
                            });
                        }
                    });
                }
            });
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
<script>
history.pushState(null, null, location.href);
window.addEventListener('popstate', function() {
    window.location.href = 'login.php';
});
</script>
</body>
</html>