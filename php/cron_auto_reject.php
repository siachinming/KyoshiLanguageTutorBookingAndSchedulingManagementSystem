<?php
// cron_auto_reject.php - Run this daily at 12:01 AM via Windows Task Scheduler

session_start();
include 'config.php';
date_default_timezone_set('Asia/Kuala_Lumpur');
// Include PHPMailer
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require '../vendor/autoload.php';

// Get current date
$today = date('Y-m-d');

// Find all pending bookings where booking_date is less than today (expired)
$sql = "SELECT b.*, 
        u.fullname as student_name, 
        u.email as student_email, 
        u.id as student_id,
        t.fullname as tutor_name,
        t.email as tutor_email
        FROM bookings b
        JOIN users u ON b.student_id = u.id
        JOIN users t ON b.tutor_id = t.id
        WHERE b.status = 'pending' 
        AND b.booking_date < ?
        ORDER BY b.booking_date ASC";

$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $today);
$stmt->execute();
$expiredBookings = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$rejectedCount = 0;
$cancel_reason = "Auto-rejected: Tutor did not respond before booking date";

foreach ($expiredBookings as $booking) {
    // Update booking status to 'cancelled' with system as canceller
    $update = $conn->prepare("UPDATE bookings SET status = 'cancelled', cancelled_by = 'system', cancel_reason = ? WHERE id = ? AND status = 'pending'");
    $update->bind_param("si", $cancel_reason, $booking['id']);
    
    if ($update->execute()) {
        $rejectedCount++;
        
        $bookingDate = date('l, F j, Y', strtotime($booking['booking_date']));
        $bookingTime = date('g:i A', strtotime($booking['booking_time']));
        
        // 1. Insert notification for STUDENT
        $notifTitle = "Booking Expired - No Response";
        $notifMessage = "Your {$booking['language']} session scheduled for {$bookingDate} at {$bookingTime} has been automatically cancelled because the tutor did not respond before the booking date.";
        $notifType = "booking_expired";
        $notifLink = "booking_detail.php?id={$booking['id']}";
        
        $stmt3 = $conn->prepare("
            INSERT INTO notifications (user_id, title, message, type, link, is_read, created_at)
            VALUES (?, ?, ?, ?, ?, 0, NOW())
        ");
        $stmt3->bind_param("issss", $booking['student_id'], $notifTitle, $notifMessage, $notifType, $notifLink);
        $stmt3->execute();
        
        // 2. Insert notification for TUTOR
        $tutorNotifTitle = "Booking Auto-Cancelled";
        $tutorNotifMessage = "You did not respond to {$booking['student_name']}'s {$booking['language']} session request for {$bookingDate} at {$bookingTime}. It has been automatically cancelled.";
        $tutorNotifType = "booking_auto_cancelled";
        
        $stmt4 = $conn->prepare("
            INSERT INTO notifications (user_id, title, message, type, link, is_read, created_at)
            VALUES (?, ?, ?, ?, ?, 0, NOW())
        ");
        $stmt4->bind_param("issss", $booking['tutor_id'], $tutorNotifTitle, $tutorNotifMessage, $tutorNotifType, $notifLink);
        $stmt4->execute();
        
        // 3. Send EMAIL to STUDENT
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
            $mail->addAddress($booking['student_email'], $booking['student_name']);
            $mail->isHTML(true);
            $mail->Subject = 'Booking Expired - Kyoshi';
            $mail->Body = "
                <div style='font-family: Arial, sans-serif; max-width: 600px; margin: auto; background: #f9f9f9; border-radius: 20px; padding: 30px;'>
                    <div style='text-align: center;'>
                        <h1 style='color: #dc2626;'>Booking Expired</h1>
                    </div>
                    <div style='background: white; border-radius: 16px; padding: 20px;'>
                        <p>Dear <strong>{$booking['student_name']}</strong>,</p>
                        <p>Your booking request has been <strong style='color: #dc2626;'>AUTOMATICALLY CANCELLED</strong> because the tutor did not respond before the booking date.</p>
                        <div style='background: #e8f4f8; border-radius: 12px; padding: 15px; margin: 20px 0;'>
                            <p><strong>Language:</strong> {$booking['language']}</p>
                            <p><strong>Date:</strong> {$bookingDate}</p>
                            <p><strong>Time:</strong> {$bookingTime}</p>
                            <p><strong>Learning Mode:</strong> " . ucfirst($booking['learning_mode']) . "</p>
                        </div>
                        <p><strong>Next Steps:</strong><br><br> Please make a new booking request.</p>
                    </div>
                    <div style='text-align: center; margin-top: 20px;'>
                        <a href='http://localhost/kyoshi/php/booking.php' 
                           style='display: inline-block; padding: 12px 30px; background: #1d3156; color: white; 
                                  text-decoration: none; border-radius: 30px;'>Book New Session</a>
                    </div>
                </div>
            ";
            $mail->send();
        } catch (Exception $e) {
            error_log("Auto-reject email failed for booking {$booking['id']}: " . $mail->ErrorInfo);
        }
        
        // 4. Send EMAIL to TUTOR (warning)
        $tutorMail = new PHPMailer(true);
        try {
            $tutorMail->isSMTP();
            $tutorMail->Host = 'smtp.gmail.com';
            $tutorMail->SMTPAuth = true;
            $tutorMail->Username = SMTP_USER;
            $tutorMail->Password = SMTP_PASS;
            $tutorMail->SMTPSecure = 'tls';
            $tutorMail->Port = 587;
            $tutorMail->setFrom('sohisabella87@gmail.com', 'Kyoshi');
            $tutorMail->addAddress($booking['tutor_email'], $booking['tutor_name']);
            $tutorMail->isHTML(true);
            $tutorMail->Subject = 'Booking Auto-Cancelled - Action Required';
            $tutorMail->Body = "
                <div style='font-family: Arial, sans-serif; max-width: 600px; margin: auto; background: #f9f9f9; border-radius: 20px; padding: 30px;'>
                    <div style='text-align: center;'>
                        <h1 style='color: #dc2626;'>Booking Auto-Cancelled</h1>
                    </div>
                    <div style='background: white; border-radius: 16px; padding: 20px;'>
                        <p>Dear <strong>{$booking['tutor_name']}</strong>,</p>
                        <p>You did not respond to a booking request before the scheduled date.</p>
                        <div style='background: #e8f4f8; border-radius: 12px; padding: 15px; margin: 20px 0;'>
                            <p><strong>Student:</strong> {$booking['student_name']}</p>
                            <p><strong>Language:</strong> {$booking['language']}</p>
                            <p><strong>Date:</strong> {$bookingDate}</p>
                            <p><strong>Time:</strong> {$bookingTime}</p>
                        </div>
                        <p style='color: #dc2626;'><strong>⚠️ Please respond to pending requests promptly to avoid this in the future.</strong></p>
                    </div>
                </div>
            ";
            $tutorMail->send();
        } catch (Exception $e) {
            error_log("Auto-reject tutor email failed for booking {$booking['id']}: " . $tutorMail->ErrorInfo);
        }
    }
}

// Output result (for cron logging)
echo date('Y-m-d H:i:s') . " - Auto-cancelled {$rejectedCount} expired bookings.\n";
?>