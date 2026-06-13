<?php
session_start();
include 'config.php';

header('Content-Type: application/json');

// Allow students to check (not just admin)
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['available' => false, 'error' => 'Unauthorized']);
    exit();
}

$tutor_id = isset($_GET['tutor_id']) ? intval($_GET['tutor_id']) : 0;
$booking_date = isset($_GET['date']) ? $_GET['date'] : '';
$booking_time = isset($_GET['time']) ? $_GET['time'] : '';
$current_booking_id = isset($_GET['booking_id']) ? intval($_GET['booking_id']) : 0;

if (!$tutor_id || !$booking_date || !$booking_time) {
    echo json_encode(['available' => true, 'warning' => 'Missing parameters']);
    exit();
}

// Get day of week from booking date
$date_obj = new DateTime($booking_date);
$day_of_week = $date_obj->format('w'); // 0=Sunday, 1=Monday, etc.

// Check 1: Is tutor available according to tutor_availability table?
$avail_query = $conn->prepare("
    SELECT * FROM tutor_availability 
    WHERE tutor_id = ? 
    AND day_of_week = ?
    AND start_time <= ?
    AND end_time >= ?
");
$avail_query->bind_param("iiss", $tutor_id, $day_of_week, $booking_time, $booking_time);
$avail_query->execute();
$availability = $avail_query->get_result()->fetch_assoc();

if (!$availability) {
    $day_name = $date_obj->format('l');
    echo json_encode([
        'available' => false,
        'message' => "Tutor is not available on $day_name at this time. Please check tutor's available hours."
    ]);
    exit();
}

// Check 2: Does tutor already have a booking at this specific date/time?
$check_query = $conn->prepare("
    SELECT COUNT(*) as count 
    FROM bookings 
    WHERE tutor_id = ? 
    AND booking_date = ? 
    AND booking_time = ?
    AND status NOT IN ('cancelled', 'rejected', 'disputed')
    AND id != ?
");
$check_query->bind_param("issi", $tutor_id, $booking_date, $booking_time, $current_booking_id);
$check_query->execute();
$result = $check_query->get_result()->fetch_assoc();

if ($result['count'] > 0) {
    echo json_encode([
        'available' => false,
        'message' => 'This time slot is already booked by another student'
    ]);
    exit();
}

// Check 3: Does student already have another booking at this time?
$student_id = $_SESSION['user_id'];
$self_query = $conn->prepare("
    SELECT COUNT(*) as count 
    FROM bookings 
    WHERE student_id = ? 
    AND booking_date = ? 
    AND booking_time = ?
    AND status NOT IN ('cancelled', 'rejected', 'disputed')
    AND id != ?
");
$self_query->bind_param("issi", $student_id, $booking_date, $booking_time, $current_booking_id);
$self_query->execute();
$self_result = $self_query->get_result()->fetch_assoc();

if ($self_result['count'] > 0) {
    echo json_encode([
        'available' => false,
        'message' => 'You already have another booking at this time'
    ]);
    exit();
}

// All checks passed
echo json_encode([
    'available' => true,
    'message' => 'Time is available'
]);
?>