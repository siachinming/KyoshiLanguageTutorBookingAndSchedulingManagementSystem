<?php
session_start();
include 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'tutor') {
    header("Location: login.php");
    exit();
}

$userID = $_SESSION['user_id'];
$material_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$booking_id = isset($_GET['booking_id']) ? (int)$_GET['booking_id'] : 0;

if (!$material_id) {
    header("Location: booking_requests.php");
    exit();
}

// First, get the material info (file path and ownership)
$stmt = $conn->prepare("SELECT file_path, is_url, tutor_id, file_name FROM learning_materials WHERE id = ?");
$stmt->bind_param("i", $material_id);
$stmt->execute();
$material = $stmt->get_result()->fetch_assoc();

// Verify tutor owns this material
if (!$material || $material['tutor_id'] != $userID) {
    header("Location: booking_requests.php");
    exit();
}

$success = false;
$error_msg = '';

// Delete physical file if it exists (not a URL)
if ($material['is_url'] == 0 && !empty($material['file_path'])) {
    $filePath = $material['file_path'];
    if (file_exists($filePath)) {
        if (unlink($filePath)) {
            $success = true;
        } else {
            $error_msg = "Failed to delete file: " . basename($material['file_name']);
        }
    } else {
        // File doesn't exist, but we can still delete from DB
        $success = true;
    }
} else {
    // It's a URL, no file to delete
    $success = true;
}

// Delete from database if file deletion succeeded or it's a URL
if ($success) {
    $deleteStmt = $conn->prepare("DELETE FROM learning_materials WHERE id = ? AND tutor_id = ?");
    $deleteStmt->bind_param("ii", $material_id, $userID);
    if ($deleteStmt->execute()) {
        header("Location: learning_materials.php?booking_id=" . $booking_id . "&success=" . urlencode("Material deleted successfully"));
        exit();
    } else {
        $error_msg = "Database error: Could not delete material";
    }
}

// If we get here, something failed
header("Location: learning_materials.php?booking_id=" . $booking_id . "&error=" . urlencode($error_msg));
exit();
?>