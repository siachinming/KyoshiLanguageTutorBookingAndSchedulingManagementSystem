<?php
header('Content-Type: application/json');
include 'config.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['can_complete' => false, 'message' => 'Not logged in']);
    exit();
}

$booking_id = intval($_GET['booking_id'] ?? 0);

if (!$booking_id) {
    echo json_encode(['can_complete' => false, 'message' => 'Invalid booking']);
    exit();
}

$stmt = $conn->prepare("SELECT booking_date, booking_time, status FROM bookings WHERE id = ?");
$stmt->bind_param("i", $booking_id);
$stmt->execute();
$booking = $stmt->get_result()->fetch_assoc();

if (!$booking) {
    echo json_encode(['can_complete' => false, 'message' => 'Booking not found']);
    exit();
}

// Check if booking is cancelled
if ($booking['status'] === 'cancelled') {
    echo json_encode(['can_complete' => false, 'message' => 'Booking is cancelled']);
    exit();
}

// Check if booking is already completed
if ($booking['status'] === 'completed') {
    echo json_encode(['can_complete' => false, 'message' => 'Booking already completed']);
    exit();
}

$session_time = strtotime($booking['booking_date'] . ' ' . $booking['booking_time']);
$current_time = time();

if ($current_time > $session_time) {
    echo json_encode(['can_complete' => true, 'message' => 'Can complete session']);
} else {
    $wait_minutes = ceil(($session_time - $current_time) / 60);
    echo json_encode(['can_complete' => false, 'message' => 'Session not started yet. Wait ' . $wait_minutes . ' minutes.']);
}
?>