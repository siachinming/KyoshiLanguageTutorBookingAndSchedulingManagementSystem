<?php
session_start();
include 'config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$booking_id = intval($_POST['booking_id'] ?? 0);
$user_id = $_SESSION['user_id'];

if (!$booking_id) {
    echo json_encode(['success' => false, 'message' => 'Invalid booking ID']);
    exit();
}

$upload_dir = '../uploads/dispute_proofs/';
if (!file_exists($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}

if (isset($_FILES['proof']) && $_FILES['proof']['error'] === UPLOAD_ERR_OK) {
    $file_extension = pathinfo($_FILES['proof']['name'], PATHINFO_EXTENSION);
    $filename = 'dispute_' . $booking_id . '_' . time() . '_' . $user_id . '.' . $file_extension;
    $file_path = $upload_dir . $filename;
    
    if (move_uploaded_file($_FILES['proof']['tmp_name'], $file_path)) {
        // Save proof to database
        $updateStmt = $conn->prepare("
            UPDATE session_completion 
            SET tutor_proof_image = ? 
            WHERE booking_id = ?
        ");
        $updateStmt->bind_param("si", $filename, $booking_id);
        $updateStmt->execute();
        
        echo json_encode(['success' => true, 'message' => 'Proof uploaded successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to upload file']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'No file uploaded']);
}
?>