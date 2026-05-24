<?php
session_start();
include 'config.php';
include 'insert_notification.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require '../vendor/autoload.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];
$booking_id = intval($_POST['booking_id'] ?? 0);
$action = $_POST['action'] ?? '';

if (!$booking_id) {
    header("Location: booking_status.php?error=invalid");
    exit();
}

// Get booking details with completion status
$stmt = $conn->prepare("
    SELECT 
        b.*, 
        s.fullname AS student_name, s.email AS student_email,
        t.fullname AS tutor_name, t.email AS tutor_email,
        sc.tutor_confirmed, sc.student_confirmed, sc.id as completion_id
    FROM bookings b
    JOIN users s ON b.student_id = s.id
    JOIN users t ON b.tutor_id = t.id
    LEFT JOIN session_completion sc ON b.id = sc.booking_id
    WHERE b.id = ?
");
$stmt->bind_param("i", $booking_id);
$stmt->execute();
$booking = $stmt->get_result()->fetch_assoc();

if (!$booking) {
    header("Location: booking_status.php?error=not_found");
    exit();
}

// Check if session time has passed
$class_time = strtotime($booking['booking_date'] . ' ' . $booking['booking_time']);
$current_time = time();

if ($class_time > $current_time) {
    header("Location: booking_detail.php?id=$booking_id&error=session_not_ended");
    exit();
}

// Handle different actions
if ($action === 'confirm') {
    $confirm_by = $role; // 'student' or 'tutor'
    
    // Insert or update completion record
    if (!$booking['completion_id']) {
        $insertStmt = $conn->prepare("
            INSERT INTO session_completion (booking_id, tutor_confirmed, student_confirmed)
            VALUES (?, 0, 0)
        ");
        $insertStmt->bind_param("i", $booking_id);
        $insertStmt->execute();
        $completion_id = $conn->insert_id;
    } else {
        $completion_id = $booking['completion_id'];
    }
    
    // Update confirmation based on who confirmed
    if ($confirm_by === 'tutor') {
        $updateStmt = $conn->prepare("
            UPDATE session_completion 
            SET tutor_confirmed = 1, tutor_confirmed_at = NOW()
            WHERE booking_id = ?
        ");
    } else {
        $updateStmt = $conn->prepare("
            UPDATE session_completion 
            SET student_confirmed = 1, student_confirmed_at = NOW()
            WHERE booking_id = ?
        ");
    }
    $updateStmt->bind_param("i", $booking_id);
    $updateStmt->execute();
    
    // Get updated completion status
    $checkStmt = $conn->prepare("
        SELECT tutor_confirmed, student_confirmed FROM session_completion 
        WHERE booking_id = ?
    ");
    $checkStmt->bind_param("i", $booking_id);
    $checkStmt->execute();
    $completion = $checkStmt->get_result()->fetch_assoc();
    
    $both_confirmed = ($completion['tutor_confirmed'] && $completion['student_confirmed']);
    
    // Send notification to the OTHER party
    if ($confirm_by === 'tutor') {
        insertNotification($conn, $booking['student_id'], "Tutor Confirmed Session", 
            "Your tutor has confirmed the {$booking['language']} session. Please confirm to complete.",
            "confirmation", "booking_detail.php?id=$booking_id");
    } else {
        insertNotification($conn, $booking['tutor_id'], "Student Confirmed Session", 
            "The student has confirmed the {$booking['language']} session. Please confirm to complete.",
            "confirmation", "tutor_booking_detail.php?id=$booking_id");
    }
    
    // If both confirmed, complete the booking
    if ($both_confirmed) {
        $completeStmt = $conn->prepare("
            UPDATE bookings 
            SET status = 'completed', completed_at = NOW()
            WHERE id = ?
        ");
        $completeStmt->bind_param("i", $booking_id);
        $completeStmt->execute();
        
        insertNotification($conn, $booking['student_id'], "Session Completed!", 
            "Your {$booking['language']} session has been completed. Thank you for attending!",
            "completed", "booking_detail.php?id=$booking_id");
        
        insertNotification($conn, $booking['tutor_id'], "Session Completed!", 
            "Your {$booking['language']} session has been completed. Payment will be processed.",
            "completed", "tutor_booking_detail.php?id=$booking_id");
        
        sendCompletionEmail($booking, 'both');
        
        if ($role === 'tutor') {
            header("Location: tutor_booking_detail.php?id=$booking_id&completed=1");
        } else {
            header("Location: booking_detail.php?id=$booking_id&completed=1");
        }
        exit();
    }
    
    // If only one confirmed, show waiting message
    if ($role === 'tutor') {
        header("Location: tutor_booking_detail.php?id=$booking_id&waiting=1");
    } else {
        header("Location: booking_detail.php?id=$booking_id&waiting=1");
    }
    exit();
}

function sendCompletionEmail($booking, $recipient) {
    $mail = new PHPMailer(true);
    $date = date('l, d F Y', strtotime($booking['booking_date']));
    $time = date('g:i A', strtotime($booking['booking_time']));
    
    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = 'tls';
        $mail->Port       = 587;
        $mail->setFrom('sohisabella87@gmail.com', 'Kyoshi');
        $mail->CharSet = 'UTF-8';
        $mail->isHTML(true);
        
        // Send to STUDENT
        $mail->clearAddresses();
        $mail->addAddress($booking['student_email'], $booking['student_name']);
        $mail->Subject = 'Session Completed - Thank you!';
        $mail->Body = "
        <div style='font-family:Segoe UI,sans-serif;max-width:550px;margin:auto;border:1px solid #e0e0e0;border-radius:16px;padding:24px;background:#fff;'>
            <div style='text-align:center;'>
                <h2 style='color:#E75A9B;'>Session Completed!</h2>
            </div>
            <p>Dear <strong>{$booking['student_name']}</strong>,</p>
            <p>Thank you for attending your <strong>{$booking['language']}</strong> session with <strong>{$booking['tutor_name']}</strong> on <strong>$date at $time</strong>.</p>
            <div style='background:#d4edda;padding:16px;border-radius:12px;margin:20px 0;border-left:4px solid #28a745;'>
                <p style='margin:0;'>Session completed successfully!</p>
            </div>
            <div style='text-align:center;margin-top:20px;'>
                <a href='http://localhost/kyoshi/php/rate_tutor.php?booking_id={$booking['id']}' style='display:inline-block;padding:10px 20px;background:#E75A9B;color:white;border-radius:30px;text-decoration:none;'>Leave a Review</a>
                <a href='http://localhost/kyoshi/php/find_language.php' style='display:inline-block;padding:10px 20px;background:#6c757d;color:white;border-radius:30px;text-decoration:none;margin-left:10px;'>Book Next Session</a>
            </div>
            <p style='font-size:12px;color:#999;text-align:center;margin-top:20px;'>Keep learning!</p>
        </div>
        ";
        $mail->send();
        
        // Send to TUTOR
        $mail->clearAddresses();
        $mail->addAddress($booking['tutor_email'], $booking['tutor_name']);
        $mail->Subject = 'Session Completed - Great teaching!';
        $mail->Body = "
        <div style='font-family:Segoe UI,sans-serif;max-width:550px;margin:auto;border:1px solid #e0e0e0;border-radius:16px;padding:24px;background:#fff;'>
            <div style='text-align:center;'>
                <h2 style='color:#E75A9B;'>Session Completed!</h2>
            </div>
            <p>Dear <strong>{$booking['tutor_name']}</strong>,</p>
            <p>Your <strong>{$booking['language']}</strong> session with <strong>{$booking['student_name']}</strong> on <strong>$date at $time</strong> has been completed.</p>
            <div style='background:#d4edda;padding:16px;border-radius:12px;margin:20px 0;border-left:4px solid #28a745;'>
                <p style='margin:0;'>Payment will be processed to your account.</p>
            </div>
            <div style='text-align:center;margin-top:20px;'>
                <a href='http://localhost/kyoshi/php/tutor_dashboard.php' style='display:inline-block;padding:10px 20px;background:#E75A9B;color:white;border-radius:30px;text-decoration:none;'>View Dashboard</a>
            </div>
            <p style='font-size:12px;color:#999;text-align:center;margin-top:20px;'>Great teaching!</p>
        </div>
        ";
        $mail->send();
        
    } catch (Exception $e) {
        error_log("Email failed: " . $e->getMessage());
    }
}
?>