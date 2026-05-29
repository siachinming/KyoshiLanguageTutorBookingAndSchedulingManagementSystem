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
$booking_id = $_POST['booking_id'] ?? 0;
$action = $_POST['action'] ?? '';

if ($action === 'upload_proof') {
    if (!isset($_FILES['proof']) || $_FILES['proof']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'message' => 'No file uploaded']);
        exit();
    }
    
    $upload_dir = '../uploads/proofs/';
    if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
    
    $ext = strtolower(pathinfo($_FILES['proof']['name'], PATHINFO_EXTENSION));
    $allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
    
    if (!in_array($ext, $allowed)) {
        echo json_encode(['success' => false, 'message' => 'Invalid file type']);
        exit();
    }
    
    $proof_type = $_POST['proof_type'] ?? 'screenshot';
    $filename = 'proof_' . $booking_id . '_' . $userID . '_' . time() . '.' . $ext;
    
    if (move_uploaded_file($_FILES['proof']['tmp_name'], $upload_dir . $filename)) {
        $stmt = $conn->prepare("
            INSERT INTO attendance_proofs (booking_id, user_id, user_role, proof_type, file_path, uploaded_at) 
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        $stmt->bind_param("iisss", $booking_id, $userID, $userRole, $proof_type, $filename);
        
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Proof uploaded successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Database error']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to save file']);
    }
    exit();
}
?>