<?php
session_start();
include 'config.php';

header('Content-Type: application/json');

// Allow both student and tutor to use this API
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$userID = $_SESSION['user_id'];
$userRole = $_SESSION['role'];
$data = json_decode(file_get_contents('php://input'), true);
$booking_id = $data['booking_id'] ?? 0;
$action = $data['action'] ?? '';

if (!$booking_id) {
    echo json_encode(['success' => false, 'message' => 'Invalid booking ID']);
    exit();
}

// Verify booking exists and user is involved
$stmt = $conn->prepare("SELECT id, student_id, tutor_id FROM bookings WHERE id = ?");
$stmt->bind_param("i", $booking_id);
$stmt->execute();
$booking = $stmt->get_result()->fetch_assoc();

if (!$booking) {
    echo json_encode(['success' => false, 'message' => 'Booking not found']);
    exit();
}

// Check permissions based on role
if ($userRole === 'student' && $booking['student_id'] != $userID) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}
if ($userRole === 'tutor' && $booking['tutor_id'] != $userID) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

// ==================== STUDENT ACTIONS ====================
if ($userRole === 'student' && $action === 'confirm') {
    // Student confirms they attended
    $stmt = $conn->prepare("
        INSERT INTO session_completion (booking_id, student_confirmed, student_confirmed_at, attendance_manually_set) 
        VALUES (?, 1, NOW(), 1)
        ON DUPLICATE KEY UPDATE 
        student_confirmed = 1, 
        student_confirmed_at = NOW(),
        attendance_manually_set = 1,
        dispute_reason = NULL,
        no_show_type = NULL
    ");
    $stmt->bind_param("i", $booking_id);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Attendance confirmed! Tutor will submit feedback soon.']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $stmt->error]);
    }
} 
elseif ($userRole === 'student' && $action === 'student_no_show') {
    // Student admits they did NOT attend
    $reason = $data['reason'] ?? 'Student did not attend the session';
    
    if (empty(trim($reason))) {
        $reason = 'Student did not attend the session';
    }
    
    $stmt = $conn->prepare("
        INSERT INTO session_completion (booking_id, student_confirmed, dispute_reason, attendance_manually_set, no_show_type) 
        VALUES (?, 0, ?, 1, 'student_no_show')
        ON DUPLICATE KEY UPDATE 
        student_confirmed = 0, 
        dispute_reason = ?,
        attendance_manually_set = 1,
        no_show_type = 'student_no_show'
    ");
    $stmt->bind_param("iss", $booking_id, $reason, $reason);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'We understand. No refund will be issued as the tutor reserved this time for you.']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $stmt->error]);
    }
}
elseif ($userRole === 'student' && $action === 'tutor_no_show') {
    // Student reports tutor did NOT attend
    $reason = $data['reason'] ?? 'Tutor did not attend the session';
    
    $stmt = $conn->prepare("
        INSERT INTO session_completion (booking_id, student_confirmed, dispute_reason, attendance_manually_set, no_show_type) 
        VALUES (?, 0, ?, 1, 'tutor_no_show')
        ON DUPLICATE KEY UPDATE 
        student_confirmed = 0, 
        dispute_reason = ?,
        attendance_manually_set = 1,
        no_show_type = 'tutor_no_show'
    ");
    $stmt->bind_param("iss", $booking_id, $reason, $reason);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'We apologize for the inconvenience. Refund will be processed.']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $stmt->error]);
    }
}

// ==================== TUTOR ACTIONS ====================
elseif ($userRole === 'tutor' && $action === 'confirm') {
    // Tutor confirms they attended (online session)
    $stmt = $conn->prepare("
        INSERT INTO session_completion (booking_id, tutor_confirmed, tutor_confirmed_at, attendance_manually_set) 
        VALUES (?, 1, NOW(), 1)
        ON DUPLICATE KEY UPDATE 
        tutor_confirmed = 1, 
        tutor_confirmed_at = NOW(),
        attendance_manually_set = 1
    ");
    $stmt->bind_param("i", $booking_id);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Attendance confirmed! Waiting for student confirmation.']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $stmt->error]);
    }
}
elseif ($userRole === 'tutor' && $action === 'face_to_face_attended') {
    // Tutor confirms student attended face-to-face session
    $reason = $data['reason'] ?? 'Tutor confirmed student attended';
    
    $stmt = $conn->prepare("
        INSERT INTO session_completion (booking_id, tutor_confirmed, tutor_confirmed_at, attendance_manually_set, no_show_type) 
        VALUES (?, 1, NOW(), 1, NULL)
        ON DUPLICATE KEY UPDATE 
        tutor_confirmed = 1, 
        tutor_confirmed_at = NOW(),
        attendance_manually_set = 1,
        no_show_type = NULL
    ");
    $stmt->bind_param("i", $booking_id);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Attendance recorded. Student has 48 hours to dispute if needed.']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $stmt->error]);
    }
}
elseif ($userRole === 'tutor' && $action === 'student_no_show') {
    // Tutor reports student did NOT attend (student no-show)
    $reason = $data['reason'] ?? 'Tutor reported student did not attend';
    
    $stmt = $conn->prepare("
        INSERT INTO session_completion (booking_id, tutor_confirmed, dispute_reason, attendance_manually_set, no_show_type) 
        VALUES (?, 1, ?, 1, 'student_no_show')
        ON DUPLICATE KEY UPDATE 
        tutor_confirmed = 1, 
        dispute_reason = ?,
        attendance_manually_set = 1,
        no_show_type = 'student_no_show'
    ");
    $stmt->bind_param("iss", $booking_id, $reason, $reason);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Student marked as no-show. No refund issued.']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $stmt->error]);
    }
}
elseif ($userRole === 'tutor' && $action === 'tutor_no_show') {
    // Tutor admits they did NOT attend (tutor no-show - refund)
    $reason = $data['reason'] ?? 'Tutor did not attend the session';
    
    $conn->begin_transaction();
    
    try {
        $stmt = $conn->prepare("
            INSERT INTO session_completion (booking_id, tutor_confirmed, dispute_reason, attendance_manually_set, no_show_type) 
            VALUES (?, 0, ?, 1, 'tutor_no_show')
            ON DUPLICATE KEY UPDATE 
            tutor_confirmed = 0, 
            dispute_reason = ?,
            attendance_manually_set = 1,
            no_show_type = 'tutor_no_show'
        ");
        $stmt->bind_param("iss", $booking_id, $reason, $reason);
        $stmt->execute();
        
        // Update booking status
        $conn->query("UPDATE bookings SET status = 'tutor_no_show' WHERE id = $booking_id");
        
        $conn->commit();
        
        echo json_encode(['success' => true, 'message' => 'We apologize. Refund will be processed for the student.']);
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => 'Error processing request']);
    }
}
else {
    echo json_encode(['success' => false, 'message' => 'Invalid action: ' . $action . ' for role: ' . $userRole]);
}
?>