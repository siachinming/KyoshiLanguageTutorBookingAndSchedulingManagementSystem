<?php
session_start();
include 'config.php';

header('Content-Type: application/json');

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
    SELECT p.id, p.status, p.amount, b.student_id, b.tutor_id, b.language, b.booking_date
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

// Update payment status to 'disputed'
$updateStmt = $conn->prepare("
    UPDATE payments 
    SET status = 'disputed', 
        notes = CONCAT(IFNULL(notes, ''), '\n[', NOW(), '] Student reported: Money deducted but payment shows failed. Amount: RM', ?)
    WHERE id = ?
");
$updateStmt->bind_param("di", $amount, $payment_id);
$updateStmt->execute();

// ✅ Send notification to ADMIN (user_id = 1 or your admin user ID)
// Use a specific admin user ID (e.g., 1) instead of 0
$admin_id = 1; // Change this to your actual admin user ID
$notifStmt = $conn->prepare("
    INSERT INTO notifications (user_id, type, title, message, link, created_at)
    VALUES (?, 'payment_dispute', 'Payment Dispute Reported', 
            CONCAT('Student #', ?, ' reported payment issue for booking #', ?, '. Amount: RM', ?), 
            'admin_payments.php?status=disputed', NOW())
");
$notifStmt->bind_param("iiid", $admin_id, $student_id, $booking_id, $amount);
$notifStmt->execute();

// ✅ Send notification to STUDENT
$studentNotif = $conn->prepare("
    INSERT INTO notifications (user_id, type, title, message, link, created_at)
    VALUES (?, 'payment_dispute', 'Dispute Submitted', 
            'Your payment dispute has been submitted. Admin will review within 24 hours.', 
            'my_payments.php', NOW())
");
$studentNotif->bind_param("i", $student_id);
$studentNotif->execute();

// ✅ Also send email to admin (optional)
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require '../vendor/autoload.php';

try {
    $adminMail = new PHPMailer(true);
    $adminMail->isSMTP();
    $adminMail->Host       = 'smtp.gmail.com';
    $adminMail->SMTPAuth   = true;
    $adminMail->Username   = SMTP_USER;
    $adminMail->Password   = SMTP_PASS;
    $adminMail->SMTPSecure = 'tls';
    $adminMail->Port       = 587;
    $adminMail->setFrom('sohisabella87@gmail.com', 'Kyoshi System');
    $adminMail->addAddress('Ali@gmail.com', 'Admin'); // Change to your admin email
    $adminMail->isHTML(true);
    $adminMail->Subject = 'Payment Dispute - Student Claims Money Deducted';
    $adminMail->Body    = "
        <h2>Payment Dispute Reported</h2>
        <p>A student has reported that money was deducted but payment shows as FAILED.</p>
        <table border='1' cellpadding='8' style='border-collapse:collapse;'>
            <tr><td><strong>Payment ID:</strong></td><td>{$payment_id}</td></tr>
            <tr><td><strong>Booking ID:</strong></td><td>{$booking_id}</td></tr>
            <tr><td><strong>Student ID:</strong></td><td>{$student_id}</td></tr>
            <tr><td><strong>Amount:</strong></td><td>RM {$amount}</td></tr>
            <tr><td><strong>Language:</strong></td><td>{$payment['language']}</td></tr>
            <tr><td><strong>Date:</strong></td><td>{$payment['booking_date']}</td></tr>
        </table>
        <p>Please verify with the payment gateway and update the payment status.</p>
        <a href='http://localhost/kyoshi/admin/admin_payments.php?status=disputed'>Review Dispute →</a>
    ";
    $adminMail->send();
} catch (Exception $e) {
    // Log error but don't stop process
    error_log("Admin email failed: " . $e->getMessage());
}

echo json_encode(['success' => true, 'message' => 'Reported to admin. They will verify within 24 hours.']);
?>