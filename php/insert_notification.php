<?php
// insert_notification.php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require '../vendor/autoload.php';

function insertNotification($conn, $userId, $title, $message, $type = 'general', $link = null) {
    // Insert into database
    $stmt = $conn->prepare("INSERT INTO notifications (user_id, title, message, type, link) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("issss", $userId, $title, $message, $type, $link);
    $stmt->execute();
    $stmt->close();
    
    // Send email
    sendEmailNotification($conn, $userId, $title, $message, $link);
}

function sendEmailNotification($conn, $userId, $title, $message, $link = null) {
    // Get user details
    $stmt = $conn->prepare("SELECT email, fullname FROM users WHERE id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if (!$user || empty($user['email'])) {
        return false;
    }
    
    $fullLink = $link ? "http://localhost/kyoshi/php/" . ltrim($link, '/') : "";
    
    $mail = new PHPMailer(true);
    
    try {
        // SMTP Configuration
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;  // Define this in config.php
        $mail->Password   = SMTP_PASS;  // Define this in config.php
        $mail->SMTPSecure = 'tls';
        $mail->Port       = 587;
        
        // Recipients
        $mail->setFrom('sohisabella87@gmail.com', 'Kyoshi');
        $mail->addAddress($user['email'], $user['fullname']);
        
        // Content
        $mail->isHTML(true);
        $mail->Subject = '[Kyoshi] ' . $title;
        $mail->Body    = "
            <div style='font-family:Segoe UI,sans-serif;max-width:550px;margin:auto;border:1px solid #e0e0e0;border-radius:16px;padding:24px;background:#fff;'>
                <div style='text-align:center;margin-bottom:24px;'>
                    <img src='http://localhost/kyoshi/assets/img/logo.png' alt='Kyoshi' style='height:50px;'>
                    <h2 style='color:#E75A9B;margin:10px 0 0;'>" . htmlspecialchars($title) . "</h2>
                </div>
                
                <p>Dear <strong>" . htmlspecialchars($user['fullname']) . "</strong>,</p>
                
                <div style='background:#f8f9fa;padding:16px;border-radius:12px;margin:20px 0;'>
                    <p style='margin:0;'>" . nl2br(htmlspecialchars($message)) . "</p>
                </div>
                
                " . ($fullLink ? "
                <div style='text-align:center;margin:30px 0 20px;'>
                    <a href='$fullLink' 
                       style='display:inline-block;padding:12px 28px;background:linear-gradient(135deg,#E75A9B,#F28AB2);
                              color:white;border-radius:40px;text-decoration:none;font-weight:bold;'>
                        View Details →
                    </a>
                </div>
                " : "") . "
                
                <hr style='border:none;border-top:1px solid #eee;margin:20px 0;'>
                <p style='font-size:12px;color:#999;text-align:center;'>
                    This is an automated message. Please do not reply to this email.<br>
                    &copy; " . date('Y') . " Kyoshi Learning. All rights reserved.
                </p>
            </div>
        ";
        
        $mail->send();
        return true;
        
    } catch (Exception $e) {
        error_log("Email failed to send to {$user['email']}: " . $mail->ErrorInfo);
        return false;
    }
}
?>