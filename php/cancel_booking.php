<?php
session_start();
include 'config.php';

if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit(); }
$userID = $_SESSION['user_id'];

$bookingId = intval($_GET['id'] ?? 0);
if (!$bookingId) { header("Location: booking_status.php"); exit(); }

$stmt = $conn->prepare("
    SELECT 
        b.id,
        b.tutor_id,
        b.language,
        b.booking_date,
        b.booking_time,
        b.status,
        u.fullname AS student_name
    FROM bookings b
    JOIN users u ON u.id = b.student_id
    WHERE b.id = ? AND b.student_id = ?
");
$stmt->bind_param("ii", $bookingId, $userID);
$stmt->execute();
$booking = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$booking) {
    header("Location: booking_status.php?error=notfound");
    exit();
}

if (!in_array($booking['status'], ['pending', 'accepted'])) {
    header("Location: booking_status.php?error=cannot_cancel");
    exit();
}

$stmt = $conn->prepare("
    UPDATE bookings 
    SET status = 'cancelled', 
        cancelled_by = 'student', 
        cancel_reason = 'Cancelled by student' 
    WHERE id = ? AND student_id = ?
");

$stmt->bind_param("ii", $bookingId, $userID);
$stmt->execute();

if ($stmt->affected_rows === 0) {
    $stmt->close();
    header("Location: booking_status.php?error=cancel_failed");
    exit();
}

$stmt->close();
// Send notification to tutor
$tutorId     = $booking['tutor_id'];
$studentName = $booking['student_name'];
$language    = $booking['language'];
$date        = date('d M Y', strtotime($booking['booking_date']));
$time        = date('g:i A', strtotime($booking['booking_time']));

$title   = "Booking Cancelled";
$message = "$studentName has cancelled their $language lesson booking on $date at $time.";
$type    = "booking_cancelled";

$stmt = $conn->prepare("
    INSERT INTO notifications (user_id, title, message, type, related_id)
    VALUES (?, ?, ?, ?, ?)
");
$stmt->bind_param("isssi", $tutorId, $title, $message, $type, $bookingId);
$stmt->execute();
$stmt->close();

header("Location: booking_status.php?cancelled=1");
exit();