<?php
session_start();
include 'config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['available' => false, 'message' => 'Please login']);
    exit();
}

// Get POST data
$data = json_decode(file_get_contents('php://input'), true);
$booking_id = $data['booking_id'] ?? 0;
$preferred_datetime = $data['preferred_datetime'] ?? '';

if (!$booking_id || !$preferred_datetime) {
    echo json_encode(['available' => false, 'message' => 'Missing required information']);
    exit();
}

$student_id = $_SESSION['user_id'];

// Get the tutor_id, original booking date/time, and payment status
$booking_query = $conn->prepare("
    SELECT b.tutor_id, b.booking_date, b.booking_time, b.status as booking_status,
           p.status as payment_status, p.rejection_type
    FROM bookings b
    LEFT JOIN payments p ON b.id = p.booking_id
    WHERE b.id = ? AND b.student_id = ?
");
$booking_query->bind_param("ii", $booking_id, $student_id);
$booking_query->execute();
$booking = $booking_query->get_result()->fetch_assoc();

if (!$booking) {
    echo json_encode(['available' => false, 'message' => 'Booking not found']);
    exit();
}

$tutor_id = $booking['tutor_id'];
$original_date = $booking['booking_date'];
$original_time = $booking['booking_time'];
$payment_status = $booking['payment_status'];
$rejection_type = $booking['rejection_type'];

// Parse the preferred datetime
$preferred_date_obj = new DateTime($preferred_datetime);
$preferred_date = $preferred_date_obj->format('Y-m-d');
$preferred_time = $preferred_date_obj->format('H:i:s');
$day_of_week = $preferred_date_obj->format('w');

// ========== CRITICAL CHECK: Same as original cancelled booking ==========
// If payment was rejected and booking is cancelled, check if trying to reschedule to SAME time
if (($payment_status === 'rejected' || $payment_status === 'failed') && 
    $preferred_date === $original_date && 
    $preferred_time === $original_time) {
    echo json_encode([
        'available' => false,
        'same_as_original' => true,  // ← SPECIAL FLAG for JavaScript
        'message' => 'You selected the same date and time as your original cancelled booking.'
    ]);
    exit();
}

// Check 1: Is tutor available according to tutor_availability table?
$avail_query = $conn->prepare("
    SELECT * FROM tutor_availability 
    WHERE tutor_id = ? 
    AND day_of_week = ?
    AND start_time <= ?
    AND end_time >= ?
");
$avail_query->bind_param("iiss", $tutor_id, $day_of_week, $preferred_time, $preferred_time);
$avail_query->execute();
$availability = $avail_query->get_result()->fetch_assoc();

if (!$availability) {
    $day_name = $preferred_date_obj->format('l');
    echo json_encode([
        'available' => false,
        'same_as_original' => false,
        'message' => "Tutor is not available on $day_name at " . date('g:i A', strtotime($preferred_time)) . ". Please check the tutor's available hours."
    ]);
    exit();
}

// Check 2: Does tutor already have a booking at this specific date/time?
$tutor_booking_query = $conn->prepare("
    SELECT COUNT(*) as count 
    FROM bookings 
    WHERE tutor_id = ? 
    AND booking_date = ? 
    AND booking_time = ?
    AND status NOT IN ('cancelled', 'rejected')
    AND id != ?
");
$tutor_booking_query->bind_param("issi", $tutor_id, $preferred_date, $preferred_time, $booking_id);
$tutor_booking_query->execute();
$tutor_booking = $tutor_booking_query->get_result()->fetch_assoc();

if ($tutor_booking['count'] > 0) {
    echo json_encode([
        'available' => false,
        'same_as_original' => false,
        'message' => 'This time slot is already booked by another student. Please select a different time.'
    ]);
    exit();
}

// Check 3: Does student already have another booking at this specific date/time?
$student_booking_query = $conn->prepare("
    SELECT COUNT(*) as count 
    FROM bookings 
    WHERE student_id = ? 
    AND booking_date = ? 
    AND booking_time = ?
    AND status NOT IN ('cancelled', 'rejected')
    AND id != ?
");
$student_booking_query->bind_param("issi", $student_id, $preferred_date, $preferred_time, $booking_id);
$student_booking_query->execute();
$student_booking = $student_booking_query->get_result()->fetch_assoc();

if ($student_booking['count'] > 0) {
    echo json_encode([
        'available' => false,
        'same_as_original' => false,
        'message' => 'You already have another active booking scheduled at this time. Please select a different time.'
    ]);
    exit();
}

// All checks passed
echo json_encode([
    'available' => true,
    'same_as_original' => false,
    'message' => 'Time is available!'
]);
?>