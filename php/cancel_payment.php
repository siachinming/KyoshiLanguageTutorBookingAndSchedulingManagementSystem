<?php
session_start();
include 'config.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require '../vendor/autoload.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit();
}

$student_id = $_SESSION['user_id'];
$data = json_decode(file_get_contents('php://input'), true);
$payment_id = $data['payment_id'] ?? 0;
$booking_id = $data['booking_id'] ?? 0;

if (!$payment_id || !$booking_id) {
    echo json_encode(['success' => false, 'message' => 'Invalid payment data']);
    exit();
}

// Get booking and user details
$checkStmt = $conn->prepare("
    SELECT 
        p.id, p.status, p.proof_image, p.amount,
        b.id as booking_id, b.student_id, b.tutor_id, b.language, 
        b.booking_date, b.booking_time, b.status as booking_status,
        s.fullname as student_name, s.email as student_email,
        t.fullname as tutor_name, t.email as tutor_email
    FROM payments p
    JOIN bookings b ON p.booking_id = b.id
    JOIN users s ON b.student_id = s.id
    JOIN users t ON b.tutor_id = t.id
    WHERE p.id = ? AND b.student_id = ?
");
$checkStmt->bind_param("ii", $payment_id, $student_id);
$checkStmt->execute();
$result = $checkStmt->get_result();

if ($result->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'Payment not found']);
    exit();
}

$payment = $result->fetch_assoc();

$canCancel = false;

if ($payment['status'] === 'pending' && empty($payment['proof_image']) && $payment['booking_status'] !== 'cancelled') {
    $canCancel = true;
}

if (!$canCancel) {
    echo json_encode(['success' => false, 'message' => 'Cannot cancel - payment already submitted or booking already processed']);
    exit();
}

// Start transaction
$conn->begin_transaction();

try {
    // 1. Cancel the payment
    $cancelPaymentStmt = $conn->prepare("
        UPDATE payments 
        SET status = 'cancelled', 
            notes = CONCAT(IFNULL(notes, ''), '\n[', NOW(), '] Booking and payment cancelled by student')
        WHERE id = ?
    ");
    $cancelPaymentStmt->bind_param("i", $payment_id);
    $cancelPaymentStmt->execute();
    
    // 2. Cancel the booking
    $cancelBookingStmt = $conn->prepare("
        UPDATE bookings 
        SET status = 'cancelled',
            cancellation_reason = 'Cancelled by student before payment',
            cancelled_at = NOW()
        WHERE id = ?
    ");
    $cancelBookingStmt->bind_param("i", $booking_id);
    $cancelBookingStmt->execute();
    
    // 3. Add notification for tutor
    $notifStmt = $conn->prepare("
        INSERT INTO notifications (user_id, type, title, message, link, created_at)
        SELECT tutor_id, 'booking_cancelled', 'Booking Cancelled', 
               CONCAT(?, ' cancelled booking for ', ?, ' on ', DATE_FORMAT(?, '%d %b %Y'), ' at ', TIME_FORMAT(?, '%h:%i %p')),
               CONCAT('tutor_bookings.php?id=', ?), NOW()
        FROM bookings WHERE id = ?
    ");
    $notifStmt->bind_param("ssssii", $payment['student_name'], $payment['language'], $payment['booking_date'], $payment['booking_time'], $booking_id, $booking_id);
    $notifStmt->execute();
    
    // 4. Send email to STUDENT
    $studentMail = new PHPMailer(true);
    $studentMail->isSMTP();
    $studentMail->Host       = 'smtp.gmail.com';
    $studentMail->SMTPAuth   = true;
    $studentMail->Username   = SMTP_USER;
    $studentMail->Password   = SMTP_PASS;
    $studentMail->SMTPSecure = 'tls';
    $studentMail->Port       = 587;
    $studentMail->setFrom('sohisabella87@gmail.com', 'Kyoshi');
    $studentMail->addAddress($payment['student_email'], $payment['student_name']);
    $studentMail->isHTML(true);
    $studentMail->Subject = 'Booking Cancellation Confirmation - Kyoshi';
    $studentMail->Body    = "
        <div style='font-family:Segoe UI,sans-serif;max-width:550px;margin:auto;border:1px solid #e0e0e0;border-radius:16px;padding:24px;background:#fff;'>
            <div style='text-align:center;margin-bottom:24px;'>
                <h2 style='color:#E75A9B;margin:10px 0 0;'>Booking Cancelled</h2>
            </div>
            
            <p>Dear <strong>" . htmlspecialchars($payment['student_name']) . "</strong>,</p>
            
            <p>Your booking has been <strong style='color:#dc3545;'>CANCELLED</strong> as requested.</p>
            
            <div style='background:#f8f9fa;padding:16px;border-radius:12px;margin:20px 0;'>
                <h3 style='margin:0 0 12px;color:#342635;'>Cancelled Session Details:</h3>
                <table style='width:100%;'>
                    <tr><td style='padding:6px 0;'><strong>Language:</strong></td><td>" . htmlspecialchars($payment['language']) . "</td></tr>
                    <tr><td style='padding:6px 0;'><strong>Tutor:</strong></td><td>" . htmlspecialchars($payment['tutor_name']) . "</td></tr>
                    <tr><td style='padding:6px 0;'><strong>Date:</strong></td><td>" . date('l, d F Y', strtotime($payment['booking_date'])) . "</td></tr>
                    <tr><td style='padding:6px 0;'><strong>Time:</strong></td><td>" . date('g:i A', strtotime($payment['booking_time'])) . "</td></tr>
                    <tr><td style='padding:6px 0;'><strong>Amount:</strong></td><td style='color:#E75A9B;font-weight:bold;'>RM " . number_format($payment['amount'], 2) . "</td></tr>
                </table>
            </div>
            
            <p><strong>No payment was processed</strong> for this booking. You will not be charged.</p>
            
            <p>If you wish to reschedule, please book a new session with your preferred tutor.</p>
            
            <div style='text-align:center;margin:30px 0 20px;'>
                <a href='http://localhost/kyoshi/php/find_language.php' 
                   style='display:inline-block;padding:12px 28px;background:linear-gradient(135deg,#E75A9B,#F28AB2);
                          color:white;border-radius:40px;text-decoration:none;font-weight:bold;'>
                    Book New Session →
                </a>
            </div>
            
            <hr style='border:none;border-top:1px solid #eee;margin:20px 0;'>
            <p style='font-size:12px;color:#999;text-align:center;'>
                This is an automated message. Please do not reply to this email.<br>
                &copy; " . date('Y') . " Kyoshi Learning. All rights reserved.
            </p>
        </div>
    ";
    $studentMail->send();
    
    // 5. Send email to TUTOR
    $tutorMail = new PHPMailer(true);
    $tutorMail->isSMTP();
    $tutorMail->Host       = 'smtp.gmail.com';
    $tutorMail->SMTPAuth   = true;
    $tutorMail->Username   = SMTP_USER;
    $tutorMail->Password   = SMTP_PASS;
    $tutorMail->SMTPSecure = 'tls';
    $tutorMail->Port       = 587;
    $tutorMail->setFrom('sohisabella87@gmail.com', 'Kyoshi');
    $tutorMail->addAddress($payment['tutor_email'], $payment['tutor_name']);
    $tutorMail->isHTML(true);
    $tutorMail->Subject = 'Booking Cancelled by Student - Kyoshi';
    $tutorMail->Body    = "
        <div style='font-family:Segoe UI,sans-serif;max-width:550px;margin:auto;border:1px solid #e0e0e0;border-radius:16px;padding:24px;background:#fff;'>
            <div style='text-align:center;margin-bottom:24px;'>
                <img src='http://localhost/kyoshi/assets/img/logo.png' alt='Kyoshi' style='height:50px;'>
                <h2 style='color:#E75A9B;margin:10px 0 0;'>Booking Cancelled</h2>
            </div>
            
            <p>Dear <strong>" . htmlspecialchars($payment['tutor_name']) . "</strong>,</p>
            
            <p>A student has cancelled their booking. Please see details below:</p>
            
            <div style='background:#f8f9fa;padding:16px;border-radius:12px;margin:20px 0;'>
                <h3 style='margin:0 0 12px;color:#342635;'>Cancelled Session Details:</h3>
                <table style='width:100%;'>
                    <tr><td style='padding:6px 0;'><strong>Student:</strong></td><td>" . htmlspecialchars($payment['student_name']) . "</td></tr>
                    <tr><td style='padding:6px 0;'><strong>Language:</strong></td><td>" . htmlspecialchars($payment['language']) . "</td></tr>
                    <tr><td style='padding:6px 0;'><strong>Date:</strong></td><td>" . date('l, d F Y', strtotime($payment['booking_date'])) . "</td></tr>
                    <tr><td style='padding:6px 0;'><strong>Time:</strong></td><td>" . date('g:i A', strtotime($payment['booking_time'])) . "</td></tr>
                </table>
            </div>
            
            <p>Your available time slots have been freed up for other students.</p>
            
            <div style='text-align:center;margin:30px 0 20px;'>
                <a href='http://localhost/kyoshi/php/tutor_dashboard.php' 
                   style='display:inline-block;padding:12px 28px;background:linear-gradient(135deg,#E75A9B,#F28AB2);
                          color:white;border-radius:40px;text-decoration:none;font-weight:bold;'>
                    View Dashboard →
                </a>
            </div>
            
            <hr style='border:none;border-top:1px solid #eee;margin:20px 0;'>
            <p style='font-size:12px;color:#999;text-align:center;'>
                This is an automated message. Please do not reply to this email.<br>
                &copy; " . date('Y') . " Kyoshi Learning. All rights reserved.
            </p>
        </div>
    ";
    $tutorMail->send();
    
    $conn->commit();
    
    echo json_encode(['success' => true, 'message' => 'Booking and payment cancelled successfully. Email notifications sent.']);
    
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => 'Error cancelling: ' . $e->getMessage()]);
}
?>