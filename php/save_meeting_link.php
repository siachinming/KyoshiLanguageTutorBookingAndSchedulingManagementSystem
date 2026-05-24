<?php
session_start();
include 'config.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require '../vendor/autoload.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$data = json_decode(file_get_contents('php://input'), true);
$booking_id = $data['booking_id'] ?? 0;
$meeting_link = $data['meeting_link'] ?? '';
$userID = $_SESSION['user_id'];

// Verify booking belongs to this tutor and get booking details
$stmt = $conn->prepare("
    SELECT b.*, 
           u.fullname as student_name, 
           u.email as student_email, 
           u.id as student_id,
           t.fullname as tutor_name
    FROM bookings b
    JOIN users u ON b.student_id = u.id
    JOIN users t ON b.tutor_id = t.id
    WHERE b.id = ? AND b.tutor_id = ?
");
$stmt->bind_param("ii", $booking_id, $userID);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'Booking not found']);
    exit();
}

$booking = $result->fetch_assoc();

// Check if this is a new link or update
$isNewLink = empty($booking['meeting_link']);

// Update meeting link
$update = $conn->prepare("UPDATE bookings SET meeting_link = ?, link_provided_at = NOW() WHERE id = ?");
$update->bind_param("si", $meeting_link, $booking_id);

if ($update->execute()) {
    $bookingDate = date('l, F j, Y', strtotime($booking['booking_date']));
    $bookingTime = date('g:i A', strtotime($booking['booking_time']));
    
    // 1. Insert in-app notification for STUDENT
    if ($isNewLink) {
        $notifTitle = "Meeting Link Added!";
        $notifMessage = "Your tutor has added the meeting link for your {$booking['language']} session on {$bookingDate} at {$bookingTime}.";
    } else {
        $notifTitle = "Meeting Link Updated";
        $notifMessage = "Your tutor has updated the meeting link for your {$booking['language']} session on {$bookingDate} at {$bookingTime}.";
    }
    $notifType = "meeting_link_updated";
    $notifLink = "booking_detail.php?id={$booking_id}";
    
    $stmt3 = $conn->prepare("
        INSERT INTO notifications (user_id, title, message, type, link, is_read, created_at)
        VALUES (?, ?, ?, ?, ?, 0, NOW())
    ");
    $stmt3->bind_param("issss", $booking['student_id'], $notifTitle, $notifMessage, $notifType, $notifLink);
    $stmt3->execute();
    
    // 2. Send EMAIL to STUDENT
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
        $mail->CharSet = 'UTF-8';
        
        if ($isNewLink) {
            $mail->Subject = 'Meeting Link Available for Your Session - Kyoshi';
        } else {
            $mail->Subject = 'Meeting Link Updated for Your Session - Kyoshi';
        }
        
        $mail->Body = "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <title>Meeting Link " . ($isNewLink ? "Added" : "Updated") . "</title>
        </head>
        <body>
        <div style='font-family:Segoe UI,Arial,sans-serif;max-width:580px;margin:auto;background:#fff;border-radius:20px;overflow:hidden;box-shadow:0 8px 30px rgba(201,79,134,.12);'>
            <div style='background:linear-gradient(135deg,#E75A9B,#F28AB2);padding:32px 32px 24px;text-align:center;'>
                <h1 style='margin:0;color:white;font-size:24px;'>" . ($isNewLink ? "Meeting Link Added" : "Meeting Link Updated") . "</h1>
                <p style='margin:8px 0 0;color:rgba(255,255,255,.88);font-size:14px;'>Your tutor has " . ($isNewLink ? "added the" : "updated the") . " meeting link</p>
            </div>
            <div style='padding:28px 32px;'>
                <p style='margin:0 0 20px;font-size:15px;color:#342635;'>
                    Dear <strong>" . htmlspecialchars($booking['student_name']) . "</strong>,
                </p>
                <p style='margin:0 0 20px;font-size:14px;color:#7B6178;line-height:1.6;'>
                    Your tutor <strong>" . htmlspecialchars($booking['tutor_name']) . "</strong> has " . ($isNewLink ? "added the" : "updated the") . " meeting link for your upcoming session.
                </p>
                <div style='background:#e8f4f8;border-radius:16px;padding:20px;margin-bottom:20px;'>
                    <p style='margin:0 0 10px;font-size:13px;font-weight:700;color:#1d3156;'>Session Details:</p>
                    <p style='margin:6px 0;font-size:14px;color:#342635;'><strong>Language:</strong> " . htmlspecialchars($booking['language']) . "</p>
                    <p style='margin:6px 0;font-size:14px;color:#342635;'><strong>Date:</strong> {$bookingDate}</p>
                    <p style='margin:6px 0;font-size:14px;color:#342635;'><strong>Time:</strong> {$bookingTime}</p>
                    <p style='margin:6px 0;font-size:14px;color:#342635;'><strong>Meeting Link:</strong> <a href='{$meeting_link}' style='color:#E75A9B;'>{$meeting_link}</a></p>
                </div>
                <div style='text-align:center;'>
                    <a href='http://localhost/kyoshi/php/booking_detail.php?id={$booking_id}'
                       style='display:inline-block;padding:14px 32px;background:linear-gradient(135deg,#E75A9B,#F28AB2);
                              color:white;border-radius:999px;text-decoration:none;font-weight:700;font-size:14px;'>
                        View Booking Details
                    </a>
                </div>
                <p style='margin:24px 0 0;font-size:12px;color:#9080a0;text-align:center;line-height:1.6;'>
                    Click the button above to join your session at the scheduled time.
                </p>
            </div>
            <div style='background:#FFF1F6;padding:16px 32px;text-align:center;border-top:1px solid #fce7f3;'>
                <p style='margin:0;font-size:12px;color:#9080a0;'>
                    &copy; " . date('Y') . " Kyoshi
                </p>
            </div>
        </div>
        </body>
        </html>
        ";
        
        $mail->send();
        
    } catch (Exception $e) {
        error_log("Meeting link email failed for booking {$booking_id}: " . $mail->ErrorInfo);
    }
    
    // 3. Also insert notification for TUTOR (confirmation)
    $tutorNotifTitle = "Meeting Link " . ($isNewLink ? "Added" : "Updated");
    $tutorNotifMessage = "You have " . ($isNewLink ? "added the" : "updated the") . " meeting link for {$booking['student_name']}'s {$booking['language']} session.";
    
    $stmt4 = $conn->prepare("
        INSERT INTO notifications (user_id, title, message, type, link, is_read, created_at)
        VALUES (?, ?, ?, ?, ?, 0, NOW())
    ");
    $stmt4->bind_param("issss", $userID, $tutorNotifTitle, $tutorNotifMessage, 'meeting_link', $notifLink);
    $stmt4->execute();
    
    echo json_encode(['success' => true]);
    
} else {
    echo json_encode(['success' => false, 'message' => $conn->error]);
}
?>