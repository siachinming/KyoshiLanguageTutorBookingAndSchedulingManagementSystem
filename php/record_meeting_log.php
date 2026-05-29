<?php
session_start();
include 'config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$userID = $_SESSION['user_id'];
$data = json_decode(file_get_contents('php://input'), true);
$booking_id = $data['booking_id'] ?? 0;
$action = $data['action'] ?? '';

if ($action === 'join_meeting') {
    // Record when user joins the meeting
    $stmt = $conn->prepare("
        INSERT INTO meeting_logs (booking_id, participant_name, participant_role, join_time) 
        SELECT ?, u.fullname, ?, NOW()
        FROM users u WHERE u.id = ?
    ");
    $role = $_SESSION['role'];
    $stmt->bind_param("isi", $booking_id, $role, $userID);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Meeting join recorded']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Error recording join']);
    }
}
elseif ($action === 'leave_meeting') {
    // Update when user leaves the meeting
    $stmt = $conn->prepare("
        UPDATE meeting_logs 
        SET leave_time = NOW(),
            duration_minutes = TIMESTAMPDIFF(MINUTE, join_time, NOW())
        WHERE booking_id = ? AND participant_role = ? AND leave_time IS NULL
        ORDER BY id DESC LIMIT 1
    ");
    $role = $_SESSION['role'];
    $stmt->bind_param("is", $booking_id, $role);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Meeting leave recorded']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Error recording leave']);
    }
}
?>