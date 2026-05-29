<?php
session_start();
include 'config.php';
include 'insert_notification.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require '../vendor/autoload.php';

// Check if this is an AJAX request or form POST
$is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';
$is_json = isset($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false;

if ($is_ajax || $is_json) {
    header('Content-Type: application/json');
}

if (!isset($_SESSION['user_id'])) {
    if ($is_ajax || $is_json) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    } else {
        header("Location: login.php");
    }
    exit();
}

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];

// Get booking_id from either JSON input or POST form
if ($is_json) {
    $input = json_decode(file_get_contents('php://input'), true);
    $booking_id = intval($input['booking_id'] ?? 0);
    $action = $input['action'] ?? 'confirm';
} else {
    $booking_id = intval($_POST['booking_id'] ?? $_GET['booking_id'] ?? 0);
    $action = $_POST['action'] ?? $_GET['action'] ?? 'confirm';
}

if (!$booking_id) {
    if ($is_ajax || $is_json) {
        echo json_encode(['success' => false, 'message' => 'Invalid booking ID']);
    } else {
        $_SESSION['error_message'] = 'Invalid booking ID';
        header("Location: booking_status.php");
    }
    exit();
}

// Get booking details
if ($role === 'tutor') {
    $stmt = $conn->prepare("
        SELECT b.*, 
               s.fullname as student_name, s.email as student_email, s.id as student_id,
               t.fullname as tutor_name, t.email as tutor_email, t.id as tutor_id
        FROM bookings b
        JOIN users s ON b.student_id = s.id
        JOIN users t ON b.tutor_id = t.id
        WHERE b.id = ? AND b.tutor_id = ?
    ");
    $stmt->bind_param("ii", $booking_id, $user_id);
} else {
    $stmt = $conn->prepare("
        SELECT b.*, 
               s.fullname as student_name, s.email as student_email, s.id as student_id,
               t.fullname as tutor_name, t.email as tutor_email, t.id as tutor_id
        FROM bookings b
        JOIN users s ON b.student_id = s.id
        JOIN users t ON b.tutor_id = t.id
        WHERE b.id = ? AND b.student_id = ?
    ");
    $stmt->bind_param("ii", $booking_id, $user_id);
}
$stmt->execute();
$booking = $stmt->get_result()->fetch_assoc();

if (!$booking) {
    if ($is_ajax || $is_json) {
        echo json_encode(['success' => false, 'message' => 'Booking not found']);
    } else {
        $_SESSION['error_message'] = 'Booking not found';
        header("Location: booking_status.php");
    }
    exit();
}

// Check if session time has passed
$class_time = strtotime($booking['booking_date'] . ' ' . $booking['booking_time']);
$current_time = time();

if ($class_time > $current_time) {
    if ($is_ajax || $is_json) {
        echo json_encode(['success' => false, 'message' => 'Session has not ended yet']);
    } else {
        $_SESSION['error_message'] = 'Session has not ended yet';
        $redirect_page = ($role === 'tutor') ? "tutor_booking_detail.php?id=$booking_id" : "booking_detail.php?id=$booking_id";
        header("Location: $redirect_page");
    }
    exit();
}

// Check if booking is already completed
if ($booking['status'] === 'completed') {
    if ($is_ajax || $is_json) {
        echo json_encode(['success' => false, 'message' => 'Session already completed']);
    } else {
        $_SESSION['error_message'] = 'Session already completed';
        $redirect_page = ($role === 'tutor') ? "tutor_booking_detail.php?id=$booking_id" : "booking_detail.php?id=$booking_id";
        header("Location: $redirect_page");
    }
    exit();
}

// Get or create session_completion record
$completionStmt = $conn->prepare("SELECT * FROM session_completion WHERE booking_id = ?");
$completionStmt->bind_param("i", $booking_id);
$completionStmt->execute();
$completion = $completionStmt->get_result()->fetch_assoc();

if (!$completion) {
    $insertStmt = $conn->prepare("
        INSERT INTO session_completion (booking_id, tutor_confirmed, student_confirmed)
        VALUES (?, 0, 0)
    ");
    $insertStmt->bind_param("i", $booking_id);
    $insertStmt->execute();
    
    // Refresh completion data
    $completionStmt->execute();
    $completion = $completionStmt->get_result()->fetch_assoc();
}

// Handle No-Show actions
$is_no_show = ($action === 'no_show' || $action === 'student_no_show' || $action === 'tutor_no_show');

if ($is_no_show) {
    $no_show_type = ($role === 'tutor') ? 'tutor_no_show' : 'student_no_show';
    
    // Check if the other party already reported no-show
    $other_no_show = false;
    if ($role === 'tutor' && isset($completion['no_show_type']) && $completion['no_show_type'] === 'student_no_show') {
        $other_no_show = true;
    } elseif ($role !== 'tutor' && isset($completion['no_show_type']) && $completion['no_show_type'] === 'tutor_no_show') {
        $other_no_show = true;
    }
    
    // If both parties said no-show, cancel session with no payment
    if ($other_no_show) {
        $cancelStmt = $conn->prepare("
            UPDATE bookings 
            SET status = 'cancelled', 
                cancel_reason = 'Session did not happen - both parties confirmed no-show',
                cancelled_by = 'system',
                cancelled_at = NOW()
            WHERE id = ?
        ");
        $cancelStmt->bind_param("i", $booking_id);
        $cancelStmt->execute();
        
        $message = "Session cancelled. Both parties confirmed the session did not happen. No payment will be issued.";
        if ($is_ajax || $is_json) {
            echo json_encode(['success' => true, 'message' => $message]);
        } else {
            $_SESSION['success_message'] = $message;
            $redirect_page = ($role === 'tutor') ? "tutor_booking_detail.php?id=$booking_id" : "booking_detail.php?id=$booking_id";
            header("Location: $redirect_page");
        }
        exit();
    }
    
    // Update with no-show (first party reporting)
    $noShowStmt = $conn->prepare("
        UPDATE session_completion 
        SET no_show_type = ?, 
            status = 'disputed',
            tutor_confirmed = 0,
            student_confirmed = 0
        WHERE booking_id = ?
    ");
    $noShowStmt->bind_param("si", $no_show_type, $booking_id);
    $noShowStmt->execute();
    
    // Notify the other party
    if ($role === 'tutor') {
        $message = "⚠️ Your tutor reported that the session did NOT happen. Admin will review. You will NOT be charged if confirmed.";
        insertNotification($conn, $booking['student_id'], "Session Dispute", $message, "dispute", "booking_detail.php?id={$booking_id}");
    } else {
        $message = "⚠️ You reported that the session did NOT happen. The tutor will NOT be paid. Admin may contact you for details.";
        insertNotification($conn, $booking['tutor_id'], "Session Dispute", $message, "dispute", "tutor_booking_detail.php?id={$booking_id}");
    }
    
    $message = "Report submitted. Admin will review.";
    if ($is_ajax || $is_json) {
        echo json_encode(['success' => true, 'message' => $message]);
    } else {
        $_SESSION['success_message'] = $message;
        $redirect_page = ($role === 'tutor') ? "tutor_booking_detail.php?id=$booking_id" : "booking_detail.php?id=$booking_id";
        header("Location: $redirect_page");
    }
    exit();
}

// Handle ATTENDED confirmation
if ($role === 'tutor') {
    $updateStmt = $conn->prepare("
        UPDATE session_completion 
        SET tutor_confirmed = 1, tutor_confirmed_at = NOW()
        WHERE booking_id = ?
    ");
    $updateStmt->bind_param("i", $booking_id);
    $updateStmt->execute();
    
    // Notify student
    $message = "Your tutor {$booking['tutor_name']} has confirmed they attended the {$booking['language']} session.";
    insertNotification($conn, $booking['student_id'], "Tutor Confirmed", $message, "session_confirmation", "booking_detail.php?id={$booking_id}");
} else {
    $updateStmt = $conn->prepare("
        UPDATE session_completion 
        SET student_confirmed = 1, student_confirmed_at = NOW()
        WHERE booking_id = ?
    ");
    $updateStmt->bind_param("i", $booking_id);
    $updateStmt->execute();
    
    // Notify tutor
    $message = "Your student {$booking['student_name']} has confirmed they attended the {$booking['language']} session.";
    insertNotification($conn, $booking['tutor_id'], "Student Confirmed", $message, "session_confirmation", "tutor_booking_detail.php?id={$booking_id}");
}

// Refresh completion data
$completionStmt->execute();
$completion = $completionStmt->get_result()->fetch_assoc();

$tutor_confirmed = $completion['tutor_confirmed'] ?? 0;
$student_confirmed = $completion['student_confirmed'] ?? 0;
$no_show_type = $completion['no_show_type'] ?? null;
$status = $completion['status'] ?? 'pending';

// Check if already disputed
if ($status === 'disputed') {
    $message = "This session is under dispute. Admin will review.";
    if ($is_ajax || $is_json) {
        echo json_encode(['success' => false, 'message' => $message]);
    } else {
        $_SESSION['error_message'] = $message;
        $redirect_page = ($role === 'tutor') ? "tutor_booking_detail.php?id=$booking_id" : "booking_detail.php?id=$booking_id";
        header("Location: $redirect_page");
    }
    exit();
}

// ============================================================
// DECISION LOGIC FOR COMPLETION
// ============================================================

$should_complete = false;
$is_student_no_show = false;

// Case 1: Both attended
if ($tutor_confirmed == 1 && $student_confirmed == 1) {
    $should_complete = true;
}
// Case 2: Student no-show, Tutor attended (tutor gets paid, no report needed)
elseif ($no_show_type === 'student_no_show' && $tutor_confirmed == 1) {
    $should_complete = true;
    $is_student_no_show = true;
}
// Case 3: Tutor no-show (no payment) - handled above by dispute
// Case 4: Both no-show - handled above by cancellation
if ($should_complete) {
    // Update booking status to completed
    $completeStmt = $conn->prepare("
        UPDATE bookings 
        SET status = 'completed', 
            completed_at = NOW() 
        WHERE id = ?
    ");
    $completeStmt->bind_param("i", $booking_id);
    $completeStmt->execute();
    
    // Update session_completion status
    $updateStatusStmt = $conn->prepare("
        UPDATE session_completion 
        SET status = 'completed'
        WHERE booking_id = ?
    ");
    $updateStatusStmt->bind_param("i", $booking_id);
    $updateStatusStmt->execute();
    
    if ($is_student_no_show) {
        // Student no-show - Tutor gets paid automatically, NO report required
        $studentMessage = "You did not attend the session. No refund will be issued. Please contact support if you believe this is an error.";
        $tutorMessage = "Student did not attend. Session completed. Payment will be processed automatically (no report needed).";
        
        insertNotification($conn, $booking['student_id'], "Session No-Show", $studentMessage, "completed", "booking_detail.php?id={$booking_id}");
        insertNotification($conn, $booking['tutor_id'], "Session Completed - Payment Processing", $tutorMessage, "completed", "tutor_booking_detail.php?id={$booking_id}");
        
        // Send no-show emails
        sendNoShowEmails($booking);
        
        $response_message = "Session completed (student no-show). Tutor will be paid.";
    } else {
        // Both attended - require report from tutor
        $studentMessage = "Your {$booking['language']} session with {$booking['tutor_name']} has been completed. Thank you for attending!";
        $tutorMessage = "Session completed! Please submit a session report to release payment.";
        
        insertNotification($conn, $booking['student_id'], "Session Completed", $studentMessage, "completed", "booking_detail.php?id={$booking_id}");
        insertNotification($conn, $booking['tutor_id'], "Session Completed - Report Required", $tutorMessage, "completed", "tutor_booking_detail.php?id={$booking_id}");
        
        // Send emails
        sendCompletionEmails($booking);
        sendReportReminderEmail($booking);
        
        $response_message = "Session completed! Thank you for attending.";
    }
    
    if ($is_ajax || $is_json) {
        echo json_encode(['success' => true, 'message' => $response_message]);
    } else {
        $_SESSION['success_message'] = $response_message;
        $redirect_page = ($role === 'tutor') ? "tutor_booking_detail.php?id=$booking_id" : "booking_detail.php?id=$booking_id";
        header("Location: $redirect_page");
    }
    exit();
}

// Not completed yet - waiting for other party
$response_message = ($role === 'tutor') 
    ? 'Confirmed! Waiting for student confirmation.'
    : 'Confirmed! Waiting for tutor confirmation.';

if ($is_ajax || $is_json) {
    echo json_encode(['success' => true, 'message' => $response_message]);
} else {
    $_SESSION['success_message'] = $response_message;
    $redirect_page = ($role === 'tutor') ? "tutor_booking_detail.php?id=$booking_id" : "booking_detail.php?id=$booking_id";
    header("Location: $redirect_page");
}
exit();

// ============================================================
// FUNCTIONS
// ============================================================

function sendCompletionEmails($booking) {
    $mail = new PHPMailer(true);
    $date = date('l, d F Y', strtotime($booking['booking_date']));
    $time = date('g:i A', strtotime($booking['booking_time']));
    
    try {
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = SMTP_USER;
        $mail->Password = SMTP_PASS;
        $mail->SMTPSecure = 'tls';
        $mail->Port = 587;
        $mail->setFrom('sohisabella87@gmail.com', 'Kyoshi');
        $mail->CharSet = 'UTF-8';
        $mail->isHTML(true);
        
        // Email to STUDENT
        $mail->clearAddresses();
        $mail->addAddress($booking['student_email'], $booking['student_name']);
        $mail->Subject = 'Session Completed - Kyoshi';
        $mail->Body = "
        <div style='font-family:Segoe UI,sans-serif;max-width:550px;margin:auto;border:1px solid #e0e0e0;border-radius:16px;padding:24px;background:#fff;'>
            <div style='text-align:center;'>
                <h2 style='color:#28a745;'>Session Completed</h2>
            </div>
            <p>Dear <strong>{$booking['student_name']}</strong>,</p>
            <p>Your <strong>{$booking['language']}</strong> session with <strong>{$booking['tutor_name']}</strong> on <strong>$date at $time</strong> has been completed.</p>
            <div style='background:#d4edda;padding:16px;border-radius:12px;margin:20px 0;border-left:4px solid #28a745;'>
                <p style='margin:0;'>Thank you for learning with us!</p>
            </div>
            <div style='text-align:center;margin-top:20px;'>
                <a href='http://localhost/kyoshi/php/rate_tutor.php?booking_id={$booking['id']}' style='display:inline-block;padding:10px 20px;background:#E75A9B;color:white;border-radius:30px;text-decoration:none;'>Rate Your Tutor</a>
            </div>
            <p style='font-size:12px;color:#999;text-align:center;margin-top:20px;'>Keep learning!</p>
        </div>
        ";
        $mail->send();
        
        // Email to TUTOR
        $mail->clearAddresses();
        $mail->addAddress($booking['tutor_email'], $booking['tutor_name']);
        $mail->Subject = 'Session Completed - Report Required - Kyoshi';
        $mail->Body = "
        <div style='font-family:Segoe UI,sans-serif;max-width:550px;margin:auto;border:1px solid #e0e0e0;border-radius:16px;padding:24px;background:#fff;'>
            <div style='text-align:center;'>
                <h2 style='color:#f59e0b;'>Session Completed - Action Required</h2>
            </div>
            <p>Dear <strong>{$booking['tutor_name']}</strong>,</p>
            <p>Your <strong>{$booking['language']}</strong> session with <strong>{$booking['student_name']}</strong> on <strong>$date at $time</strong> has been completed.</p>
            <div style='background:#fee2e2;padding:16px;border-radius:12px;margin:20px 0;border-left:4px solid #f59e0b;'>
                <p style='margin:0;color:#991b1b;'>
                    <strong>Action Required: Please submit a session report to release payment.</strong>
                </p>
            </div>
            <div style='text-align:center;margin-top:20px;'>
                <a href='http://localhost/kyoshi/php/submit_session_report.php?booking_id={$booking['id']}' 
                   style='display:inline-block;padding:10px 20px;background:#f59e0b;color:white;border-radius:30px;text-decoration:none;font-weight:bold;'>
                    Submit Session Report
                </a>
            </div>
            <p style='font-size:12px;color:#999;text-align:center;margin-top:20px;'>Great teaching!</p>
        </div>
        ";
        $mail->send();
        
    } catch (Exception $e) {
        error_log("Email failed: " . $e->getMessage());
    }
}

function sendNoShowEmails($booking) {
    $mail = new PHPMailer(true);
    $date = date('l, d F Y', strtotime($booking['booking_date']));
    $time = date('g:i A', strtotime($booking['booking_time']));
    
    try {
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = SMTP_USER;
        $mail->Password = SMTP_PASS;
        $mail->SMTPSecure = 'tls';
        $mail->Port = 587;
        $mail->setFrom('sohisabella87@gmail.com', 'Kyoshi');
        $mail->CharSet = 'UTF-8';
        $mail->isHTML(true);
        
        // Email to TUTOR (no report required)
        $mail->clearAddresses();
        $mail->addAddress($booking['tutor_email'], $booking['tutor_name']);
        $mail->Subject = 'Session Completed - Payment Processing - Kyoshi';
        $mail->Body = "
        <div style='font-family:Segoe UI,sans-serif;max-width:550px;margin:auto;border:1px solid #e0e0e0;border-radius:16px;padding:24px;background:#fff;'>
            <div style='text-align:center;'>
                <h2 style='color:#28a745;'>Session Completed</h2>
            </div>
            <p>Dear <strong>{$booking['tutor_name']}</strong>,</p>
            <p>Your <strong>{$booking['language']}</strong> session with <strong>{$booking['student_name']}</strong> on <strong>$date at $time</strong> has been completed.</p>
            <div style='background:#d4edda;padding:16px;border-radius:12px;margin:20px 0;border-left:4px solid #28a745;'>
                <p style='margin:0;color:#155724;'>
                    <strong>Note:</strong> The student did not attend. Payment will be processed automatically.
                    <br>No session report is required for this session.
                </p>
            </div>
            <p style='font-size:12px;color:#999;text-align:center;margin-top:20px;'>Thank you for your professionalism!</p>
        </div>
        ";
        $mail->send();
        
        // Email to STUDENT (no-show notice)
        $mail->clearAddresses();
        $mail->addAddress($booking['student_email'], $booking['student_name']);
        $mail->Subject = 'Session No-Show Notice - Kyoshi';
        $mail->Body = "
        <div style='font-family:Segoe UI,sans-serif;max-width:550px;margin:auto;border:1px solid #e0e0e0;border-radius:16px;padding:24px;background:#fff;'>
            <div style='text-align:center;'>
                <h2 style='color:#f59e0b;'>Session No-Show</h2>
            </div>
            <p>Dear <strong>{$booking['student_name']}</strong>,</p>
            <p>You did not attend your <strong>{$booking['language']}</strong> session with <strong>{$booking['tutor_name']}</strong> on <strong>$date at $time</strong>.</p>
            <div style='background:#fff3cd;padding:16px;border-radius:12px;margin:20px 0;border-left:4px solid #f59e0b;'>
                <p style='margin:0;color:#856404;'>
                    <strong>Note:</strong> No refund will be issued for missed sessions. 
                    Please contact support if you believe this is an error.
                </p>
            </div>
            <p style='font-size:12px;color:#999;text-align:center;margin-top:20px;'>Please attend future sessions on time.</p>
        </div>
        ";
        $mail->send();
        
    } catch (Exception $e) {
        error_log("No-show email failed: " . $e->getMessage());
    }
}

function sendReportReminderEmail($booking) {
    $mail = new PHPMailer(true);
    $date = date('l, d F Y', strtotime($booking['booking_date']));
    $time = date('g:i A', strtotime($booking['booking_time']));
    
    try {
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = SMTP_USER;
        $mail->Password = SMTP_PASS;
        $mail->SMTPSecure = 'tls';
        $mail->Port = 587;
        $mail->setFrom('sohisabella87@gmail.com', 'Kyoshi');
        $mail->addAddress($booking['tutor_email'], $booking['tutor_name']);
        $mail->isHTML(true);
        $mail->Subject = 'Session Report Required - Payment Pending - Kyoshi';
        $mail->Body = "
        <div style='font-family:Segoe UI,sans-serif;max-width:550px;margin:auto;border:1px solid #e0e0e0;border-radius:16px;padding:24px;background:#fff;'>
            <div style='text-align:center;margin-bottom:24px;'>
                <h2 style='color:#f59e0b;'>Session Report Required</h2>
            </div>
            <p>Dear <strong>{$booking['tutor_name']}</strong>,</p>
            <p>Your <strong>{$booking['language']}</strong> session with <strong>{$booking['student_name']}</strong> on <strong>$date at $time</strong> has been completed.</p>
            <div style='background:#fee2e2;padding:16px;border-radius:12px;margin:20px 0;border-left:4px solid #dc2626;'>
                <p style='margin:0;color:#991b1b;'>
                    <strong>Payment will NOT be released to your account until you submit a session report.</strong>
                </p>
            </div>
            <p><strong>Please include in your report:</strong></p>
            <ul>
                <li>Lesson summary</li>
                <li>Topics covered</li>
                <li>Student progress</li>
                <li>Homework assigned</li>
            </ul>
            <div style='text-align:center;margin:30px 0;'>
                <a href='http://localhost/kyoshi/php/submit_session_report.php?booking_id={$booking['id']}' 
                   style='display:inline-block;padding:12px 28px;background:linear-gradient(135deg,#E75A9B,#F28AB2);color:white;border-radius:30px;text-decoration:none;font-weight:bold;'>
                    Submit Session Report Now
                </a>
            </div>
            <p style='font-size:12px;color:#999;text-align:center;'>Payment will be processed within 3-5 business days after report submission.</p>
        </div>
        ";
        $mail->send();
    } catch (Exception $e) {
        error_log("Report reminder email failed: " . $e->getMessage());
    }
}
?>