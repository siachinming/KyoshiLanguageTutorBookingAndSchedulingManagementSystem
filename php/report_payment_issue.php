<?php
session_start();
error_reporting(0);
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

// Validate
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

// For reschedule, check if preferred datetime is provided
if ($resolution_requested === 'reschedule' && empty($preferred_datetime)) {
    echo json_encode(['success' => false, 'message' => 'Please select a preferred new date and time for reschedule']);
    exit();
}

// For refund, check if bank details are provided
if ($resolution_requested === 'refund') {
    if (empty($bank_name) || empty($bank_account_number) || empty($bank_account_name)) {
        echo json_encode(['success' => false, 'message' => 'Please provide all bank account details for refund']);
        exit();
    }
}

// Handle file upload (only for refund)
$proof_path = null;
if ($resolution_requested === 'refund') {
    if (!isset($_FILES['proof_image']) || $_FILES['proof_image']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'message' => 'Please upload proof of deduction']);
        exit();
    }
    
    $upload_dir = '../uploads/dispute_proofs/';
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }
    
    $file_ext = strtolower(pathinfo($_FILES['proof_image']['name'], PATHINFO_EXTENSION));
    $allowed = ['jpg', 'jpeg', 'png', 'pdf'];
    
    if (!in_array($file_ext, $allowed)) {
        echo json_encode(['success' => false, 'message' => 'Only JPG, PNG, or PDF files allowed']);
        exit();
    }
    
    $file_name = 'dispute_' . time() . '_' . rand(1000, 9999) . '.' . $file_ext;
    $file_path = $upload_dir . $file_name;
    
    if (!move_uploaded_file($_FILES['proof_image']['tmp_name'], $file_path)) {
        echo json_encode(['success' => false, 'message' => 'Failed to upload file']);
        exit();
    }
    $proof_path = 'uploads/dispute_proofs/' . $file_name;
}

// Get payment and booking details
$query = $conn->prepare("
    SELECT p.*, b.student_id, b.tutor_id, b.language, b.booking_date, b.booking_time, b.status as booking_status,
           u.fullname as tutor_name, s.fullname as student_name, s.email as student_email
    FROM payments p
    JOIN bookings b ON p.booking_id = b.id
    JOIN users u ON b.tutor_id = u.id
    JOIN users s ON b.student_id = s.id
    WHERE p.id = ? AND b.student_id = ?
");
$query->bind_param("ii", $payment_id, $userID);
$query->execute();
$payment = $query->get_result()->fetch_assoc();

if (!$payment) {
    echo json_encode(['success' => false, 'message' => 'Payment not found']);
    exit();
}

// Check if booking is cancelled
if ($payment['booking_status'] === 'cancelled') {
    echo json_encode(['success' => false, 'message' => 'This booking has been cancelled. Please contact support.']);
    exit();
}

// Parse preferred date and time if reschedule
$preferred_date = null;
$preferred_time = null;
if ($resolution_requested === 'reschedule' && $preferred_datetime) {
    $preferred_date = date('Y-m-d', strtotime($preferred_datetime));
    $preferred_time = date('H:i:s', strtotime($preferred_datetime));
    
    // Check if the preferred time is in the future
    if (strtotime($preferred_datetime) < time()) {
        echo json_encode(['success' => false, 'message' => 'Please select a future date and time for reschedule']);
        exit();
    }
    
    // Check if tutor is available at that time
    $check_availability = $conn->prepare("
        SELECT COUNT(*) as count 
        FROM bookings 
        WHERE tutor_id = ? 
        AND booking_date = ? 
        AND booking_time = ?
        AND status NOT IN ('cancelled', 'rejected')
        AND id != ?
    ");
    $check_availability->bind_param("issi", $payment['tutor_id'], $preferred_date, $preferred_time, $booking_id);
    $check_availability->execute();
    $availability = $check_availability->get_result()->fetch_assoc();
    
    if ($availability['count'] > 0) {
        echo json_encode([
            'success' => false, 
            'message' => 'The tutor already has a booking at your preferred time. Please select a different time.'
        ]);
        exit();
    }
}

// Build detailed message
$message = "=== DISPUTE REPORT ===\n\n";
$message .= "Payment ID: #{$payment_id}\n";
$message .= "Booking ID: #{$booking_id}\n";
$message .= "Resolution: " . ucfirst($resolution_requested) . "\n";
if ($resolution_requested === 'reschedule' && $preferred_datetime) {
    $message .= "Preferred new date/time: " . date('d M Y, h:i A', strtotime($preferred_datetime)) . "\n";
}
$message .= "Description: {$description}\n";
if ($proof_path) {
    $message .= "Proof: {$proof_path}\n";
}
if ($bank_name) {
    $message .= "\n=== BANK DETAILS FOR REFUND ===\n";
    $message .= "Bank: {$bank_name}\n";
    $message .= "Account Number: {$bank_account_number}\n";
    $message .= "Account Name: {$bank_account_name}\n";
}

// Check for existing dispute
$check = $conn->prepare("SELECT id FROM disputes WHERE payment_id = ? AND student_id = ? AND status = 'pending'");
$check->bind_param("ii", $payment_id, $userID);
$check->execute();
if ($check->get_result()->num_rows > 0) {
    echo json_encode(['success' => false, 'message' => 'You already have a pending dispute for this payment']);
    exit();
}

// Insert dispute with all fields
$resolution_type = 'admin';

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
        resolution_type, 
        resolution_requested,
        preferred_date,
        preferred_time,
        bank_name,
        bank_account_number,
        bank_account_name,
        created_at
    ) VALUES (?, ?, ?, ?, 'money_deducted', 'payment', ?, ?, 'pending', ?, ?, ?, ?, ?, ?, ?, NOW())
");

$insert->bind_param("iiissssssssss", 
    $booking_id,           // i
    $payment_id,           // i  
    $userID,               // i
    $payment['tutor_id'],  // i
    $message,              // s
    $proof_path,           // s
    $resolution_type,      // s
    $resolution_requested, // s
    $preferred_date,       // s
    $preferred_time,       // s
    $bank_name,            // s
    $bank_account_number,  // s
    $bank_account_name     // s
);

if ($insert->execute()) {
    $dispute_id = $conn->insert_id;
    
    // Send notification to admin (optional - you can use email or database notification)
    // You can uncomment this if you have mail configured
    /*
    $admin_email = "admin@kyoshi.com";
    $subject = "New Payment Dispute #$dispute_id";
    $email_body = "A new payment dispute has been submitted.\n\n";
    $email_body .= "Student: " . $payment['student_name'] . "\n";
    $email_body .= "Booking ID: #$booking_id\n";
    $email_body .= "Tutor: " . $payment['tutor_name'] . "\n";
    $email_body .= "Resolution: " . ucfirst($resolution_requested) . "\n";
    if ($resolution_requested === 'reschedule' && $preferred_datetime) {
        $email_body .= "Preferred New Time: " . date('d M Y, h:i A', strtotime($preferred_datetime)) . "\n";
    }
    mail($admin_email, $subject, $email_body);
    */
    
    echo json_encode([
        'success' => true,
        'message' => 'Dispute submitted successfully! Admin will review within 24-48 hours.'
    ]);
} else {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
}
?>