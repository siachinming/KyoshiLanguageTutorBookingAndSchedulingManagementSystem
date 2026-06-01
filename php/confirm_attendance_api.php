<?php
session_start();
include 'config.php';

header('Content-Type: application/json');

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
$stmt = $conn->prepare("SELECT id, student_id, tutor_id, status FROM bookings WHERE id = ?");
$stmt->bind_param("i", $booking_id);
$stmt->execute();
$booking = $stmt->get_result()->fetch_assoc();

if (!$booking) {
    echo json_encode(['success' => false, 'message' => 'Booking not found']);
    exit();
}

// Check permissions
if ($userRole === 'student' && $booking['student_id'] != $userID) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}
if ($userRole === 'tutor' && $booking['tutor_id'] != $userID) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

// ==================== STUDENT ACTIONS ====================
if ($userRole === 'student') {
    // Check if already confirmed
    $checkStmt = $conn->prepare("SELECT student_confirmed, attendance_manually_set FROM session_completion WHERE booking_id = ?");
    $checkStmt->bind_param("i", $booking_id);
    $checkStmt->execute();
    $existing = $checkStmt->get_result()->fetch_assoc();
    
    if ($existing && $existing['student_confirmed'] == 1) {
        echo json_encode(['success' => false, 'message' => 'You have already confirmed attendance for this session']);
        exit();
    }
    
    if ($action === 'confirm') {
        // Student confirms attendance
        if ($existing) {
            $stmt = $conn->prepare("
                UPDATE session_completion 
                SET student_confirmed = 1, 
                    student_confirmed_at = NOW(),
                    attendance_manually_set = 1,
                    dispute_reason = NULL,
                    no_show_type = NULL
                WHERE booking_id = ?
            ");
        } else {
            $stmt = $conn->prepare("
                INSERT INTO session_completion (booking_id, student_confirmed, student_confirmed_at, attendance_manually_set) 
                VALUES (?, 1, NOW(), 1)
            ");
        }
        $stmt->bind_param("i", $booking_id);
        
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Attendance confirmed! Tutor will submit feedback soon.']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $stmt->error]);
        }
        
    } elseif ($action === 'student_no_show') {
        // Student admits no-show
        $reason = $data['reason'] ?? 'Student did not attend the session';
        
        if ($existing) {
            $stmt = $conn->prepare("
                UPDATE session_completion 
                SET student_confirmed = 0, 
                    dispute_reason = ?,
                    attendance_manually_set = 1,
                    no_show_type = 'student_no_show'
                WHERE booking_id = ?
            ");
        } else {
            $stmt = $conn->prepare("
                INSERT INTO session_completion (booking_id, student_confirmed, dispute_reason, attendance_manually_set, no_show_type) 
                VALUES (?, 0, ?, 1, 'student_no_show')
            ");
        }
        $stmt->bind_param("si", $reason, $booking_id);
        
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'We understand. No refund will be issued as the tutor reserved this time for you.']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $stmt->error]);
        }
        
    } elseif ($action === 'tutor_no_show') {
        // Student reports tutor no-show
        $reason = $data['reason'] ?? 'Tutor did not attend the session';
        
        if ($existing) {
            $stmt = $conn->prepare("
                UPDATE session_completion 
                SET student_confirmed = 0, 
                    dispute_reason = ?,
                    attendance_manually_set = 1,
                    no_show_type = 'tutor_no_show'
                WHERE booking_id = ?
            ");
        } else {
            $stmt = $conn->prepare("
                INSERT INTO session_completion (booking_id, student_confirmed, dispute_reason, attendance_manually_set, no_show_type) 
                VALUES (?, 0, ?, 1, 'tutor_no_show')
            ");
        }
        $stmt->bind_param("si", $reason, $booking_id);
        
        if ($stmt->execute()) {
            // Update booking status
            $conn->query("UPDATE bookings SET status = 'disputed' WHERE id = $booking_id");
            echo json_encode(['success' => true, 'message' => 'We apologize for the inconvenience. Refund will be processed.']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $stmt->error]);
        }
    }
}

// ==================== TUTOR ACTIONS ====================
elseif ($userRole === 'tutor') {
    // Similar logic for tutors - check if exists first
    $checkStmt = $conn->prepare("SELECT id FROM session_completion WHERE booking_id = ?");
    $checkStmt->bind_param("i", $booking_id);
    $checkStmt->execute();
    $exists = $checkStmt->get_result()->fetch_assoc();
    
    if ($action === 'confirm') {
        if ($exists) {
            $stmt = $conn->prepare("
                UPDATE session_completion 
                SET tutor_confirmed = 1, 
                    tutor_confirmed_at = NOW(),
                    attendance_manually_set = 1
                WHERE booking_id = ?
            ");
        } else {
            $stmt = $conn->prepare("
                INSERT INTO session_completion (booking_id, tutor_confirmed, tutor_confirmed_at, attendance_manually_set) 
                VALUES (?, 1, NOW(), 1)
            ");
        }
        $stmt->bind_param("i", $booking_id);
        
    } elseif ($action === 'student_no_show') {
        $reason = $data['reason'] ?? 'Tutor reported student did not attend';
        
        if ($exists) {
            $stmt = $conn->prepare("
                UPDATE session_completion 
                SET tutor_confirmed = 1, 
                    dispute_reason = ?,
                    attendance_manually_set = 1,
                    no_show_type = 'student_no_show'
                WHERE booking_id = ?
            ");
        } else {
            $stmt = $conn->prepare("
                INSERT INTO session_completion (booking_id, tutor_confirmed, dispute_reason, attendance_manually_set, no_show_type) 
                VALUES (?, 1, ?, 1, 'student_no_show')
            ");
        }
        $stmt->bind_param("si", $reason, $booking_id);
        
    } elseif ($action === 'tutor_no_show') {
        $reason = $data['reason'] ?? 'Tutor did not attend the session';
        
        if ($exists) {
            $stmt = $conn->prepare("
                UPDATE session_completion 
                SET tutor_confirmed = 0, 
                    dispute_reason = ?,
                    attendance_manually_set = 1,
                    no_show_type = 'tutor_no_show'
                WHERE booking_id = ?
            ");
        } else {
            $stmt = $conn->prepare("
                INSERT INTO session_completion (booking_id, tutor_confirmed, dispute_reason, attendance_manually_set, no_show_type) 
                VALUES (?, 0, ?, 1, 'tutor_no_show')
            ");
        }
        $stmt->bind_param("si", $reason, $booking_id);
        
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
        exit();
    }
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Action completed successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $stmt->error]);
    }
}

else {
    echo json_encode(['success' => false, 'message' => 'Invalid role or action']);
}
?>