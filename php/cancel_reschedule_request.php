<?php
session_start();
include 'config.php';
include 'insert_notification.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$userID = $_SESSION['user_id'];
$bookingId = $_POST['booking_id'] ?? 0;

if (!$bookingId) {
    header("Location: booking_status.php");
    exit();
}

// Get booking details
$stmt = $conn->prepare("
    SELECT b.id, b.tutor_id, b.language, b.booking_date, b.booking_time,
           u.fullname as tutor_name
    FROM bookings b
    JOIN users u ON b.tutor_id = u.id
    WHERE b.id = ? AND b.student_id = ? AND b.status = 'rescheduled'
");
$stmt->bind_param("ii", $bookingId, $userID);
$stmt->execute();
$booking = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$booking) {
    header("Location: booking_status.php?error=not_found");
    exit();
}

// Update booking status back to confirmed
$update = $conn->prepare("UPDATE bookings SET status = 'confirmed' WHERE id = ?");
$update->bind_param("i", $bookingId);
$update->execute();
$update->close();

// Delete the pending reschedule request
$delete = $conn->prepare("DELETE FROM reschedule_requests WHERE booking_id = ? AND status = 'pending'");
$delete->bind_param("i", $bookingId);
$delete->execute();
$delete->close();

// Format date for message
$bookingDate = date('d M Y, g:i A', strtotime($booking['booking_date'] . ' ' . $booking['booking_time']));

// Insert notification for STUDENT
insertNotification(
    $conn,
    $userID,
    'Reschedule Request Cancelled',
    "You have cancelled your reschedule request for {$booking['language']} session on {$bookingDate}. Your original booking remains confirmed.",
    'booking',
    "booking_detail.php?id={$bookingId}",
    true
);

// Insert notification for TUTOR
insertNotification(
    $conn,
    $booking['tutor_id'],
    'Reschedule Request Cancelled by Student',
    "Student has cancelled their reschedule request for {$booking['language']} session on {$bookingDate}. The original booking remains confirmed.",
    'booking',
    "tutor_booking_detail.php?id={$bookingId}",
    true
);

// Redirect back
header("Location: booking_detail.php?id=" . $bookingId . "&cancelled_reschedule=1");
exit();
?>