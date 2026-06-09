<?php
ignore_user_abort(true);
set_time_limit(0);  // Remove PHP timeout limit

// Add this - it prevents Windows from thinking the script is frozen
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error) {
        file_put_contents(__DIR__ . '/cron_error.log', date('Y-m-d H:i:s') . ' - ' . $error['message'] . PHP_EOL, FILE_APPEND);
    }
});

include __DIR__ . '/config.php';
include __DIR__ . '/insert_notification.php';
date_default_timezone_set('Asia/Kuala_Lumpur');
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require __DIR__ . '/../vendor/autoload.php';

// ============================================================
// AUTO CANCEL BOOKINGS - ONLY CANCEL IF NO PAYMENT EXISTS (not even pending)
// ============================================================

// 1. Cancel past date bookings (yesterday and earlier)
// Only cancel if there is NO payment record at all (not verified, not pending, not processing)
$conn->query("
    UPDATE bookings b
    LEFT JOIN payments p ON b.id = p.booking_id 
    SET b.status = 'cancelled',
        b.cancel_reason = 'Payment not received before session time',
        b.cancelled_by = 'system'
    WHERE b.status = 'accepted'
    AND b.booking_date < CURDATE()
    AND p.id IS NULL
");

// 2. Cancel same-day bookings where time has already passed
// Only cancel if there is NO payment record at all
$conn->query("
    UPDATE bookings b
    LEFT JOIN payments p ON b.id = p.booking_id 
    SET b.status = 'cancelled',
        b.cancel_reason = 'Payment not received before session time',
        b.cancelled_by = 'system'
    WHERE b.status = 'accepted'
    AND b.booking_date = CURDATE()
    AND b.booking_time < CURTIME()
    AND p.id IS NULL
");

// 3. Cancel tomorrow's bookings (original logic)
// Only cancel if there is NO payment record at all
$conn->query("
    UPDATE bookings b
    LEFT JOIN payments p ON b.id = p.booking_id 
    SET b.status = 'cancelled',
        b.cancel_reason = 'Payment not received before deadline',
        b.cancelled_by = 'system'
    WHERE b.status = 'accepted'
    AND b.booking_date = CURDATE() + INTERVAL 1 DAY
    AND p.id IS NULL
");

// ============================================================
// GET CANCELLED BOOKINGS NOT YET NOTIFIED
// ============================================================

$stmt = $conn->prepare("
    SELECT 
        b.*,
        s.fullname AS student_name,
        s.email AS student_email,
        t.fullname AS tutor_name,
        t.email AS tutor_email
    FROM bookings b
    JOIN users s ON b.student_id = s.id
    JOIN users t ON b.tutor_id = t.id
    WHERE b.status = 'cancelled'
    AND (b.cancel_reason = 'Payment not received before deadline' 
         OR b.cancel_reason = 'Payment not received before session time')
    AND b.cancelled_by = 'system'
    AND NOT EXISTS (
        SELECT 1 FROM notifications n
        WHERE n.type = 'auto_cancelled'
        AND n.link LIKE CONCAT('%', b.id, '%')
    )
");
$stmt->execute();
$cancelledBookings = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$cancelled_count = 0;

foreach ($cancelledBookings as $booking) {
    $bookingDate = date('l, F j, Y', strtotime($booking['booking_date']));
    $bookingTime = date('g:i A', strtotime($booking['booking_time']));
    
    // STUDENT NOTIFICATION
    insertNotification($conn, $booking['student_id'], "Session Cancelled",
        "Your {$booking['language']} session on {$bookingDate} at {$bookingTime} was cancelled because payment was not received before the session time.",
        "auto_cancelled", "booking_status.php?id={$booking['id']}");
    
    // TUTOR NOTIFICATION
    insertNotification($conn, $booking['tutor_id'], "Session Auto-Cancelled",
        "Your {$booking['language']} session with {$booking['student_name']} on {$bookingDate} at {$bookingTime} was cancelled because payment was not received.",
        "tutor_auto_cancelled", "tutor_booking_detail.php?id={$booking['id']}");
    
    // EMAIL STUDENT
    sendCancellationEmail($booking, 'student');
    
    // EMAIL TUTOR
    sendCancellationEmail($booking, 'tutor');
    
    $cancelled_count++;
    echo "Auto-cancelled booking #{$booking['id']}\n";
}

echo "Total auto-cancelled: $cancelled_count\n";

// ============================================================
// FUNCTION TO SEND CANCELLATION EMAILS
// ============================================================
function sendCancellationEmail($booking, $recipient) {
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
        $mail->CharSet = 'UTF-8';
        $mail->isHTML(true); 
        $mail->setFrom('sohisabella87@gmail.com', 'Kyoshi');
        
        if ($recipient === 'student') {
            $mail->addAddress($booking['student_email'], $booking['student_name']);
            $mail->Subject = 'Session Cancelled - Kyoshi';
            $mail->Body = "
            <div style='font-family:Segoe UI,sans-serif;max-width:550px;margin:auto;border:1px solid #e0e0e0;border-radius:16px;padding:24px;background:#fff;'>
                <div style='text-align:center;'>
                    <h2 style='color:#dc2626;'>Session Cancelled</h2>
                </div>
                <p>Dear <strong>{$booking['student_name']}</strong>,</p>
                <p>Your <strong>{$booking['language']}</strong> session with <strong>{$booking['tutor_name']}</strong> on <strong>$date at $time</strong> has been <strong style='color:#dc2626;'>CANCELLED</strong>.</p>
                <div style='background:#fee2e2;padding:16px;border-radius:12px;margin:20px 0;border-left:4px solid #dc2626;'>
                    <p style='margin:0;color:#991b1b;'>
                        <strong>Reason:</strong> Payment was not received before the session time.
                    </p>
                </div>
                <p><strong>What can you do?</strong></p>
                <ul>
                    <li>Book a new session with this tutor</li>
                    <li>Try a different tutor</li>
                    <li>Contact support if you have questions</li>
                </ul>
                <div style='text-align:center;margin-top:20px;'>
                    <a href='http://kyoshitutor.site/php/find_language.php' 
                       style='display:inline-block;padding:12px 28px;background:linear-gradient(135deg,#E75A9B,#F28AB2);color:white;border-radius:30px;text-decoration:none;font-weight:bold;'>
                        Book New Session 
                    </a>
                </div>
                <p style='font-size:12px;color:#999;text-align:center;margin-top:20px;'>Keep learning! 📚</p>
            </div>
            ";
        } else {
            $mail->addAddress($booking['tutor_email'], $booking['tutor_name']);
            $mail->Subject = 'Session Auto-Cancelled - Kyoshi';
            $mail->Body = "
            <div style='font-family:Segoe UI,sans-serif;max-width:550px;margin:auto;border:1px solid #e0e0e0;border-radius:16px;padding:24px;background:#fff;'>
                <div style='text-align:center;'>
                    <h2 style='color:#dc2626;'>Session Auto-Cancelled</h2>
                </div>
                <p>Dear <strong>{$booking['tutor_name']}</strong>,</p>
                <p>Your <strong>{$booking['language']}</strong> session with <strong>{$booking['student_name']}</strong> on <strong>$date at $time</strong> has been <strong style='color:#dc2626;'>AUTO-CANCELLED</strong>.</p>
                <div style='background:#fee2e2;padding:16px;border-radius:12px;margin:20px 0;border-left:4px solid #dc2626;'>
                    <p style='margin:0;color:#991b1b;'>
                        <strong>Reason:</strong> Student did not complete payment before the session time.
                    </p>
                </div>
                <p style='font-size:12px;color:#999;text-align:center;margin-top:20px;'>Thank you for your understanding.</p>
            </div>
            ";
        }
        $mail->send();
    } catch (Exception $e) {
        error_log("Cancellation email failed: " . $e->getMessage());
    }
}
?>