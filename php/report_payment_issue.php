<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);
header('Content-Type: application/json');

include 'config.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Please login again']);
    exit();
}

$userID = $_SESSION['user_id'];

// Get form data
$payment_id = isset($_POST['payment_id']) ? intval($_POST['payment_id']) : 0;
$booking_id = isset($_POST['booking_id']) ? intval($_POST['booking_id']) : 0;
$resolution_requested = isset($_POST['resolution_requested']) ? $_POST['resolution_requested'] : '';
$description = isset($_POST['description']) ? trim($_POST['description']) : '';
$preferred_datetime = isset($_POST['preferred_datetime']) ? $_POST['preferred_datetime'] : null;
$bank_name = isset($_POST['bank_name']) ? trim($_POST['bank_name']) : null;
$bank_account_number = isset($_POST['bank_account_number']) ? trim($_POST['bank_account_number']) : null;
$bank_account_name = isset($_POST['bank_account_name']) ? trim($_POST['bank_account_name']) : null;

// Simple validation
if ($payment_id <= 0 || $booking_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid payment or booking ID']);
    exit();
}

if (empty($resolution_requested)) {
    echo json_encode(['success' => false, 'message' => 'Please select what you would like to happen']);
    exit();
}

if (empty($description)) {
    echo json_encode(['success' => false, 'message' => 'Please describe what happened']);
    exit();
}

// Get payment details
$query = $conn->prepare("
    SELECT p.*, b.student_id, b.tutor_id, b.status as booking_status, b.booking_date, b.booking_time
    FROM payments p
    JOIN bookings b ON p.booking_id = b.id
    WHERE p.id = ? AND b.student_id = ?
");
$query->bind_param("ii", $payment_id, $userID);
$query->execute();
$payment = $query->get_result()->fetch_assoc();

if (!$payment) {
    echo json_encode(['success' => false, 'message' => 'Payment not found']);
    exit();
}

// ========== ADD AVAILABILITY CHECK FOR RESCHEDULE ==========
if ($resolution_requested === 'reschedule' && $preferred_datetime) {
    $preferred_date_obj = new DateTime($preferred_datetime);
    $preferred_date = $preferred_date_obj->format('Y-m-d');
    $preferred_time = $preferred_date_obj->format('H:i:s');
    $day_of_week = $preferred_date_obj->format('w'); // 0=Sunday, 1=Monday, etc.
    $tutor_id = $payment['tutor_id'];
    $requested_time = $preferred_date_obj->format('H:i:s');
    
    // Check 1: Is the tutor available on this day_of_week within their time range?
    $avail_stmt = $conn->prepare("
        SELECT * FROM tutor_availability 
        WHERE tutor_id = ? 
        AND day_of_week = ?
        AND start_time <= ?
        AND end_time >= ?
    ");
    $avail_stmt->bind_param("iiss", $tutor_id, $day_of_week, $requested_time, $requested_time);
    $avail_stmt->execute();
    $availability = $avail_stmt->get_result()->fetch_assoc();
    
    if (!$availability) {
        echo json_encode([
            'success' => false,
            'message' => 'The tutor is not available at this time. Please check the tutor\'s available hours for ' . $preferred_date_obj->format('l') . '.'
        ]);
        exit();
    }
    
    // Check 2: Is the tutor already booked by another student at this specific date/time?
    $check_stmt = $conn->prepare("
        SELECT COUNT(*) as count 
        FROM bookings 
        WHERE tutor_id = ? 
        AND booking_date = ? 
        AND booking_time = ? 
        AND status IN ('accepted', 'pending', 'verified')
        AND id != ?
    ");
    $check_stmt->bind_param("issi", $tutor_id, $preferred_date, $preferred_time, $booking_id);
    $check_stmt->execute();
    $tutor_booked = $check_stmt->get_result()->fetch_assoc();
    
    if ($tutor_booked['count'] > 0) {
        echo json_encode([
            'success' => false, 
            'message' => 'This time slot is already booked by another student. Please select a different time.'
        ]);
        exit();
    }
    
    // Check 3: Does the student already have another booking at this specific date/time?
    $self_stmt = $conn->prepare("
        SELECT COUNT(*) as count 
        FROM bookings 
        WHERE student_id = ? 
        AND booking_date = ? 
        AND booking_time = ? 
        AND id != ?
        AND status IN ('accepted', 'pending', 'verified')
    ");
    $self_stmt->bind_param("issi", $userID, $preferred_date, $preferred_time, $booking_id);
    $self_stmt->execute();
    $self_booked = $self_stmt->get_result()->fetch_assoc();
    
    if ($self_booked['count'] > 0) {
        echo json_encode([
            'success' => false,
            'message' => 'You already have another booking scheduled at this time. Please select a different time.'
        ]);
        exit();
    }
}

// Handle file upload if needed
$proof_path = null;
if (isset($_FILES['proof_image']) && $_FILES['proof_image']['error'] === UPLOAD_ERR_OK) {
    $upload_dir = '../uploads/dispute_proofs/';
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }
    $file_name = 'dispute_' . time() . '_' . rand(1000, 9999) . '.' . strtolower(pathinfo($_FILES['proof_image']['name'], PATHINFO_EXTENSION));
    $file_path = $upload_dir . $file_name;
    if (move_uploaded_file($_FILES['proof_image']['tmp_name'], $file_path)) {
        $proof_path = 'uploads/dispute_proofs/' . $file_name;
    }
}

// Parse preferred date (if not already set from above)
$preferred_date = null;
$preferred_time = null;
if ($resolution_requested === 'reschedule' && $preferred_datetime) {
    $preferred_date_obj = new DateTime($preferred_datetime);
    $preferred_date = $preferred_date_obj->format('Y-m-d');
    $preferred_time = $preferred_date_obj->format('H:i:s');
}

// Build detailed message
$message = "Payment ID: #{$payment_id}\n";
$message .= "Booking ID: #{$booking_id}\n";
$message .= "Resolution: " . ucfirst($resolution_requested) . "\n";
$message .= "Description: {$description}\n";
if ($proof_path) {
    $message .= "Proof: {$proof_path}\n";
}
if ($bank_name) {
    $message .= "Bank: {$bank_name}\n";
    $message .= "Account: {$bank_account_number}\n";
    $message .= "Name: {$bank_account_name}\n";
}
if ($preferred_datetime && $resolution_requested === 'reschedule') {
    $message .= "Preferred Reschedule Date/Time: {$preferred_datetime}\n";
}

// Check for existing dispute
$check = $conn->prepare("SELECT id FROM disputes WHERE payment_id = ? AND student_id = ? AND status = 'pending'");
$check->bind_param("ii", $payment_id, $userID);
$check->execute();
if ($check->get_result()->num_rows > 0) {
    echo json_encode(['success' => false, 'message' => 'You already have a pending dispute for this payment']);
    exit();
}

// INSERT with all fields
$insert = $conn->prepare("
    INSERT INTO disputes (
        booking_id, 
        payment_id, 
        student_id, 
        tutor_id, 
        issue_type, 
        dispute_type, 
        message, 
        proof_image, 
        status,
        resolution_requested,
        preferred_date,
        preferred_time,
        bank_name,
        bank_account_number,
        bank_account_name,
        created_at
    ) VALUES (?, ?, ?, ?, 'money_deducted', 'payment', ?, ?, 'pending', ?, ?, ?, ?, ?, ?, NOW())
");

$insert->bind_param("iiiissssssss", 
    $booking_id,           
    $payment_id,           
    $userID,               
    $payment['tutor_id'],  
    $message,              
    $proof_path,           
    $resolution_requested,
    $preferred_date,       
    $preferred_time,       
    $bank_name,            
    $bank_account_number,  
    $bank_account_name     
);

if ($insert->execute()) {
    $conn->query("UPDATE payments SET status = 'disputed', disputed_at = NOW() WHERE id = $payment_id");
    $conn->query("UPDATE bookings SET status = 'disputed' WHERE id = $booking_id");
    
    echo json_encode([
        'success' => true,
        'message' => 'Dispute submitted successfully! Admin will review within 24-48 hours.'
    ]);
} else {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
}
?>