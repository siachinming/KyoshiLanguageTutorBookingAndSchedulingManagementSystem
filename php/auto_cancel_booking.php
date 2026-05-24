<?php

require_once '../config.php';
require_once '../../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// ============================================================
// AUTO CANCEL BOOKINGS
// Rule:
// If tomorrow's session still has no verified payment,
// cancel it automatically.
// ============================================================

$conn->query("
    UPDATE bookings b
    LEFT JOIN payments p 
        ON b.id = p.booking_id 
        AND p.status = 'verified'

    SET b.status = 'cancelled',
        b.cancel_reason = 'Payment not received before deadline'

    WHERE b.status = 'accepted'
    AND b.booking_date = CURDATE() + INTERVAL 1 DAY
    AND p.id IS NULL
");

// ============================================================
// GET CANCELLED BOOKINGS NOT YET NOTIFIED
// ============================================================

$result = $conn->query("
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
    AND b.cancel_reason = 'Payment not received before deadline'

    AND NOT EXISTS (
        SELECT 1
        FROM notifications n
        WHERE n.type = 'auto_cancelled'
        AND n.link LIKE CONCAT('%', b.id, '%')
    )
");

while ($booking = $result->fetch_assoc()) {

    $bookingDate = date('l, F j, Y', strtotime($booking['booking_date']));
    $bookingTime = date('g:i A', strtotime($booking['booking_time']));

    // ========================================================
    // STUDENT NOTIFICATION
    // ========================================================

    $studentMessage = "Your {$booking['language']} session on {$bookingDate} at {$bookingTime} was cancelled because payment was not received before the deadline.";

    $stmt = $conn->prepare("
        INSERT INTO notifications
        (user_id, title, message, type, link, created_at)
        VALUES (?, ?, ?, ?, ?, NOW())
    ");

    $title = "Session Cancelled";
    $type = "auto_cancelled";
    $link = "booking_status.php?id={$booking['id']}";

    $stmt->bind_param(
        "issss",
        $booking['student_id'],
        $title,
        $studentMessage,
        $type,
        $link
    );

    $stmt->execute();

    // ========================================================
    // TUTOR NOTIFICATION
    // ========================================================

    $tutorMessage = "Your session with {$booking['student_name']} on {$bookingDate} at {$bookingTime} was cancelled because payment was not received.";

    $stmt = $conn->prepare("
        INSERT INTO notifications
        (user_id, title, message, type, link, created_at)
        VALUES (?, ?, ?, ?, ?, NOW())
    ");

    $title = "Session Auto-Cancelled";
    $type = "tutor_auto_cancelled";
    $link = "tutor_booking.php?id={$booking['id']}";

    $stmt->bind_param(
        "issss",
        $booking['tutor_id'],
        $title,
        $tutorMessage,
        $type,
        $link
    );

    $stmt->execute();

    // ========================================================
    // EMAIL STUDENT
    // ========================================================

    try {

        $mail = new PHPMailer(true);

        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = SMTP_USER;
        $mail->Password = SMTP_PASS;
        $mail->SMTPSecure = 'tls';
        $mail->Port = 587;

        $mail->setFrom('sohisabella87@gmail.com', 'Kyoshi');

        $mail->addAddress(
            $booking['student_email'],
            $booking['student_name']
        );

        $mail->isHTML(true);

        $mail->Subject = '❌ Session Cancelled - Kyoshi';

        $mail->Body = "
            <h2>Session Cancelled</h2>

            <p>Dear {$booking['student_name']},</p>

            <p>
                Your {$booking['language']} session on
                {$bookingDate} at {$bookingTime}
                has been cancelled because payment
                was not received before the deadline.
            </p>
        ";

        $mail->send();

    } catch (Exception $e) {
        error_log($mail->ErrorInfo);
    }

    // ========================================================
    // EMAIL TUTOR
    // ========================================================

    try {

        $mail2 = new PHPMailer(true);

        $mail2->isSMTP();
        $mail2->Host = 'smtp.gmail.com';
        $mail2->SMTPAuth = true;
        $mail2->Username = SMTP_USER;
        $mail2->Password = SMTP_PASS;
        $mail2->SMTPSecure = 'tls';
        $mail2->Port = 587;

        $mail2->setFrom('sohisabella87@gmail.com', 'Kyoshi');

        $mail2->addAddress(
            $booking['tutor_email'],
            $booking['tutor_name']
        );

        $mail2->isHTML(true);

        $mail2->Subject = '❌ Session Auto-Cancelled - Kyoshi';

        $mail2->Body = "
            <h2>Session Cancelled</h2>

            <p>Dear {$booking['tutor_name']},</p>

            <p>
                Your session with
                {$booking['student_name']}
                on {$bookingDate} at {$bookingTime}
                has been cancelled because payment
                was not received before deadline.
            </p>
        ";

        $mail2->send();

    } catch (Exception $e) {
        error_log($mail2->ErrorInfo);
    }
}

echo "Auto-cancel completed.";