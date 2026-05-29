<?php
session_start();
include 'config.php';
include 'insert_notification.php';

header('Content-Type: application/json');

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require '../vendor/autoload.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit();
}

$student_id = $_SESSION['user_id'];
$data = json_decode(file_get_contents('php://input'), true);
$payment_id = $data['payment_id'] ?? 0;
$booking_id = $data['booking_id'] ?? 0;
$amount = $data['amount'] ?? 0;

if (!$payment_id || !$booking_id) {
    echo json_encode(['success' => false, 'message' => 'Invalid payment data']);
    exit();
}

// Verify payment belongs to this student and is failed
$checkStmt = $conn->prepare("
    SELECT p.id, p.status, p.amount, b.student_id, b.tutor_id, b.language, b.booking_date, b.total_amount
    FROM payments p
    JOIN bookings b ON p.booking_id = b.id
    WHERE p.id = ? AND b.student_id = ? AND p.status = 'failed'
");
$checkStmt->bind_param("ii", $payment_id, $student_id);
$checkStmt->execute();
$payment = $checkStmt->get_result()->fetch_assoc();

if (!$payment) {
    echo json_encode(['success' => false, 'message' => 'Payment not found or not in failed status']);
    exit();
}

// Prevent duplicate disputes
$checkDispute = $conn->prepare("
    SELECT id 
    FROM disputes 
    WHERE booking_id = ? 
      AND student_id = ? 
      AND status IN ('pending', 'escalated')
");
$checkDispute->bind_param("ii", $booking_id, $student_id);
$checkDispute->execute();
$existingDispute = $checkDispute->get_result()->fetch_assoc();

if ($existingDispute) {
    echo json_encode([
        'success' => false,
        'message' => 'You have already submitted a dispute for this payment.'
    ]);
    exit();
}

// ============================================
// INSERT INTO DISPUTES TABLE
// ============================================
$issueType = "payment_failed_but_deducted";
$message = "Student reported money deducted but payment shows failed. Payment ID: $payment_id. Amount: RM $amount";

$disputeStmt = $conn->prepare("
    INSERT INTO disputes (
        booking_id,
        student_id,
        tutor_id,
        issue_type,
        message,
        status,
        resolution_type,
        created_at
    )
    VALUES (?, ?, ?, ?, ?, 'pending', 'admin', NOW())
");

$disputeStmt->bind_param(
    "iiiss",
    $booking_id,
    $student_id,
    $payment['tutor_id'],
    $issueType,
    $message
);

if (!$disputeStmt->execute()) {
    echo json_encode(['success' => false, 'message' => 'Failed to create dispute: ' . $conn->error]);
    exit();
}
$dispute_id = $conn->insert_id;
$disputeStmt->close();

// ============================================
// UPDATE PAYMENT STATUS TO 'DISPUTED'
// ============================================
$updateStmt = $conn->prepare("
    UPDATE payments 
    SET status = 'disputed', 
        notes = CONCAT(IFNULL(notes, ''), '\n[', NOW(), '] Student reported: Money deducted but payment shows failed. Amount: RM', ?)
    WHERE id = ?
");
$updateStmt->bind_param("di", $amount, $payment_id);
$updateStmt->execute();
$updateStmt->close();

// ============================================
// UPDATE BOOKING STATUS TO 'DISPUTED' (since payment issue is serious)
// ============================================
$updateBooking = $conn->prepare("
    UPDATE bookings 
    SET status = 'disputed' 
    WHERE id = ?
");
$updateBooking->bind_param("i", $booking_id);
$updateBooking->execute();
$updateBooking->close();

// ============================================
// SEND NOTIFICATIONS
// ============================================

// Get student name
$studentStmt = $conn->prepare("SELECT fullname, email FROM users WHERE id = ?");
$studentStmt->bind_param("i", $student_id);
$studentStmt->execute();
$student = $studentStmt->get_result()->fetch_assoc();
$studentStmt->close();

// Get tutor name and email
$tutorStmt = $conn->prepare("SELECT fullname, email FROM users WHERE id = ?");
$tutorStmt->bind_param("i", $payment['tutor_id']);
$tutorStmt->execute();
$tutor = $tutorStmt->get_result()->fetch_assoc();
$tutorStmt->close();

// Get admin ID (user with role 'admin' or specific ID)
$adminStmt = $conn->prepare("SELECT id, email FROM users WHERE role = 'admin' LIMIT 1");
$adminStmt->execute();
$admin = $adminStmt->get_result()->fetch_assoc();
$admin_id = $admin['id'] ?? 1;
$adminStmt->close();

// Student notification
$studentMsg = "Your payment dispute has been submitted. Admin will review within 24 hours.";
insertNotification($conn, $student_id, "Payment Dispute Submitted", $studentMsg, "payment_dispute", "my_payments.php");

// Tutor notification (inform tutor about dispute)
$tutorMsg = "A student has disputed a payment for your {$payment['language']} session. Admin will review the case.";
insertNotification($conn, $payment['tutor_id'], "Payment Dispute - Under Review", $tutorMsg, "payment_dispute", "tutor_earnings.php");

// Admin notification
$adminMsg = "New payment dispute #$dispute_id\n";
$adminMsg .= "Student: {$student['fullname']}\n";
$adminMsg .= "Tutor: {$tutor['fullname']}\n";
$adminMsg .= "Booking ID: $booking_id\n";
$adminMsg .= "Amount: RM $amount\n";
$adminMsg .= "Issue: Money deducted but payment shows as failed.";
insertNotification($conn, $admin_id, "Payment Dispute Reported", $adminMsg, "payment_dispute", "admin/payments.php?status=disputed");

// ============================================
// SEND EMAILS
// ============================================
$bookingDate = date('l, d F Y', strtotime($payment['booking_date']));

// Email to STUDENT
sendStudentPaymentDisputeEmail($student, $payment, $amount, $bookingDate);

// Email to ADMIN
sendAdminPaymentDisputeEmail($admin, $student, $tutor, $payment, $amount, $bookingDate, $dispute_id);

// ============================================
// RESPONSE
// ============================================
echo json_encode([
    'success' => true, 
    'message' => 'Payment dispute reported. Admin will verify within 24 hours.',
    'dispute_id' => $dispute_id
]);
exit();

// ============================================
// EMAIL FUNCTIONS
// ============================================

function sendStudentPaymentDisputeEmail($student, $payment, $amount, $bookingDate) {
    $mail = new PHPMailer(true);
    
    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = 'tls';
        $mail->Port       = 587;
        $mail->CharSet = 'UTF-8';
        $mail->isHTML(true); 
        $mail->setFrom('sohisabella87@gmail.com', 'Kyoshi');
        $mail->addAddress($student['email'], $student['fullname']);
        $mail->Subject = 'Payment Dispute Submitted - Kyoshi';
        $mail->Body = "
        <div style='font-family:Segoe UI,sans-serif;max-width:550px;margin:auto;border:1px solid #e0e0e0;border-radius:16px;padding:24px;background:#fff;'>
            <div style='text-align:center;'>
                <h2 style='color:#E75A9B;'>Payment Dispute Submitted</h2>
            </div>
            <p>Dear <strong>{$student['fullname']}</strong>,</p>
            <p>We have received your payment dispute for the following session:</p>
            <div style='background:#f0f9ff;border-radius:12px;padding:16px;margin:20px 0;'>
                <p><strong>Booking ID:</strong> {$payment['id']}</p>
                <p><strong>Language:</strong> {$payment['language']}</p>
                <p><strong>Session Date:</strong> {$bookingDate}</p>
                <p><strong>Amount:</strong> RM " . number_format($amount, 2) . "</p>
            </div>
            <div style='background:#fff3cd;padding:16px;border-radius:12px;margin:20px 0;border-left:4px solid #ffc107;'>
                <p style='margin:0;color:#856404;'>Our admin team will verify your payment within 24 hours. We will update you via email and notification.</p>
            </div>
            <div style='text-align:center;margin-top:20px;'>
                <a href='http://localhost/kyoshi/php/my_payments.php' 
                   style='display:inline-block;padding:10px 20px;background:#E75A9B;color:white;border-radius:30px;text-decoration:none;font-weight:bold;'>
                    View My Payments →
                </a>
            </div>
        </div>
        ";
        $mail->send();
    } catch (Exception $e) {
        error_log("Student payment dispute email failed: " . $e->getMessage());
    }
}

function sendAdminPaymentDisputeEmail($admin, $student, $tutor, $payment, $amount, $bookingDate, $dispute_id) {
    $mail = new PHPMailer(true);
    
    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = 'tls';
        $mail->Port       = 587;
        $mail->CharSet = 'UTF-8';
        $mail->isHTML(true); 
        $mail->setFrom('sohisabella87@gmail.com', 'Kyoshi System');
        $mail->addAddress($admin['email'], 'Admin');
        $mail->Subject = '⚠️ Payment Dispute #' . $dispute_id . ' - Kyoshi';
        $mail->Body = "
        <div style='font-family:Segoe UI,sans-serif;max-width:550px;margin:auto;border:1px solid #e0e0e0;border-radius:16px;padding:24px;background:#fff;'>
            <div style='text-align:center;'>
                <h2 style='color:#dc2626;'>⚠️ Payment Dispute Reported</h2>
                <p style='font-size:12px;color:#999;'>Dispute ID: #{$dispute_id}</p>
            </div>
            <p>A student has reported that money was deducted but payment shows as FAILED.</p>
            <table style='width:100%;border-collapse:collapse;margin:15px 0;'>
                <tr style='background:#f5f5f5;'>
                    <td style='padding:10px;border:1px solid #ddd;'><strong>Payment ID:</strong></td>
                    <td style='padding:10px;border:1px solid #ddd;'>{$payment['id']}</td>
                </tr>
                <tr>
                    <td style='padding:10px;border:1px solid #ddd;'><strong>Booking ID:</strong></td>
                    <td style='padding:10px;border:1px solid #ddd;'>{$payment['booking_id']}</td>
                </tr>
                <tr style='background:#f5f5f5;'>
                    <td style='padding:10px;border:1px solid #ddd;'><strong>Student:</strong></td>
                    <td style='padding:10px;border:1px solid #ddd;'>{$student['fullname']} ({$student['email']})</td>
                </tr>
                <tr>
                    <td style='padding:10px;border:1px solid #ddd;'><strong>Tutor:</strong></td>
                    <td style='padding:10px;border:1px solid #ddd;'>{$tutor['fullname']} ({$tutor['email']})</td>
                </tr>
                <tr style='background:#f5f5f5;'>
                    <td style='padding:10px;border:1px solid #ddd;'><strong>Language:</strong></td>
                    <td style='padding:10px;border:1px solid #ddd;'>{$payment['language']}</td>
                </tr>
                <tr>
                    <td style='padding:10px;border:1px solid #ddd;'><strong>Session Date:</strong></td>
                    <td style='padding:10px;border:1px solid #ddd;'>{$bookingDate}</td>
                </tr>
                <tr style='background:#f5f5f5;'>
                    <td style='padding:10px;border:1px solid #ddd;'><strong>Amount:</strong></td>
                    <td style='padding:10px;border:1px solid #ddd;' style='color:#dc2626;'>RM " . number_format($amount, 2) . "</td>
                </tr>
            </table>
            <div style='background:#fff3cd;padding:16px;border-radius:12px;margin:20px 0;border-left:4px solid #ffc107;'>
                <p><strong>Student's Message:</strong></p>
                <p>Money was deducted from my account but the payment shows as failed. Please verify with the payment gateway.</p>
            </div>
            <div style='text-align:center;margin-top:20px;'>
                <a href='http://localhost/kyoshi/admin/payment_disputes.php?id={$dispute_id}' 
                   style='display:inline-block;padding:10px 20px;background:#dc2626;color:white;border-radius:30px;text-decoration:none;font-weight:bold;'>
                    Review Dispute →
                </a>
            </div>
        </div>
        ";
        $mail->send();
    } catch (Exception $e) {
        error_log("Admin payment dispute email failed: " . $e->getMessage());
    }
}
?>