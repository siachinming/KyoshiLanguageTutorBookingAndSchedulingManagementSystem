<?php
session_start();
include 'config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['can_join' => false, 'message' => 'Not logged in']);
    exit();
}

$booking_id = intval($_GET['booking_id'] ?? 0);
$userID = $_SESSION['user_id'];
$userRole = $_SESSION['role'];

if (!$booking_id) {
    echo json_encode(['can_join' => false, 'message' => 'Invalid booking']);
    exit();
}

// Get booking details
if ($userRole === 'tutor') {
    $stmt = $conn->prepare("SELECT booking_date, booking_time, status FROM bookings WHERE id = ? AND tutor_id = ?");
    $stmt->bind_param("ii", $booking_id, $userID);
} else {
    $stmt = $conn->prepare("SELECT booking_date, booking_time, status FROM bookings WHERE id = ? AND student_id = ?");
    $stmt->bind_param("ii", $booking_id, $userID);
}
$stmt->execute();
$booking = $stmt->get_result()->fetch_assoc();

if (!$booking) {
    echo json_encode(['can_join' => false, 'message' => 'Booking not found']);
    exit();
}

$class_start = strtotime($booking['booking_date'] . ' ' . $booking['booking_time']);
$current_time = time();

// Check if too early
if ($current_time < strtotime('-15 minutes', $class_start)) {
    $minutes_early = round(($class_start - $current_time) / 60);
    $days_early = floor($minutes_early / 1440);
    $hours_early = floor(($minutes_early % 1440) / 60);
    $mins_early = $minutes_early % 60;
    
    $time_message = "";
    if ($days_early > 0) $time_message .= $days_early . "d ";
    if ($hours_early > 0) $time_message .= $hours_early . "h ";
    $time_message .= $mins_early . "m";
    
    echo json_encode([
        'can_join' => false, 
        'message' => "Class is at " . date('D, M j', $class_start) . " at " . date('g:i A', $class_start) . 
                     "\n You can join 15 minutes before. (" . $time_message . " early)"
    ]);
    exit();
}

// Check if booking is confirmed
if ($booking['status'] !== 'confirmed' && $booking['status'] !== 'completed') {
    echo json_encode(['can_join' => false, 'message' => 'Session not confirmed yet. Please wait for payment verification.']);
    exit();
}

// Check if too late (more than 30 minutes after class)
if ($current_time > strtotime('+30 minutes', $class_start)) {
    echo json_encode(['can_join' => false, 'message' => 'Session started more than 30 minutes ago. Please contact your tutor to reschedule.']);
    exit();
}

echo json_encode(['can_join' => true]);
?>