<?php
session_start();
header('Content-Type: application/json');

include 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$adminID = $_SESSION['user_id'];

if (!isset($_FILES['profile_pic']) || $_FILES['profile_pic']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'message' => 'No file uploaded or upload error']);
    exit();
}

$file = $_FILES['profile_pic'];
$file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
$allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

if (!in_array($file_ext, $allowed)) {
    echo json_encode(['success' => false, 'message' => 'Invalid file type. Allowed: JPG, PNG, GIF, WEBP']);
    exit();
}

// Check file size (max 2MB)
if ($file['size'] > 2 * 1024 * 1024) {
    echo json_encode(['success' => false, 'message' => 'File too large. Max 2MB']);
    exit();
}

$upload_dir = '../uploads/profiles/';
if (!file_exists($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}

// Get current admin info to delete old photo
$stmt = $conn->prepare("SELECT profile_pic FROM users WHERE id = ? AND role = 'admin'");
$stmt->bind_param("i", $adminID);
$stmt->execute();
$admin = $stmt->get_result()->fetch_assoc();

// Delete old profile picture if exists and not default
if (!empty($admin['profile_pic']) && file_exists($upload_dir . $admin['profile_pic'])) {
    unlink($upload_dir . $admin['profile_pic']);
}

$new_filename = 'admin_' . $adminID . '_' . time() . '.' . $file_ext;
$upload_path = $upload_dir . $new_filename;

if (move_uploaded_file($file['tmp_name'], $upload_path)) {
    // Update database
    $updateStmt = $conn->prepare("UPDATE users SET profile_pic = ? WHERE id = ? AND role = 'admin'");
    $updateStmt->bind_param("si", $new_filename, $adminID);
    
    if ($updateStmt->execute()) {
        echo json_encode([
            'success' => true,
            'filename' => $new_filename,
            'file_path' => '../uploads/profiles/' . $new_filename,
            'message' => 'Profile picture updated successfully'
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database update failed']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to save file']);
}
?>