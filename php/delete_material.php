<?php
session_start();
include 'config.php';

// Check if user is logged in and is a tutor
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'tutor') {
    header("Location: login.php");
    exit();
}

$userID = $_SESSION['user_id'];
$material_id = intval($_GET['id'] ?? 0);
$booking_id = intval($_GET['booking_id'] ?? 0);

if (!$material_id || !$booking_id) {
    header("Location: booking_requests.php");
    exit();
}

// Get material info to check ownership and file path
$stmt = $conn->prepare("
    SELECT * FROM learning_materials 
    WHERE id = ? AND tutor_id = ?
");
$stmt->bind_param("ii", $material_id, $userID);
$stmt->execute();
$material = $stmt->get_result()->fetch_assoc();

if (!$material) {
    // Material not found or doesn't belong to this tutor
    header("Location: learning_materials.php?booking_id=" . $booking_id . "&error=" . urlencode("Material not found or access denied"));
    exit();
}

// Delete the physical file from server
$deleted = false;
if (file_exists($material['file_path']) && is_file($material['file_path'])) {
    $deleted = unlink($material['file_path']);
}

// Delete from database
$deleteStmt = $conn->prepare("DELETE FROM learning_materials WHERE id = ? AND tutor_id = ?");
$deleteStmt->bind_param("ii", $material_id, $userID);

if ($deleteStmt->execute()) {
    $message = "Material '" . $material['title'] . "' deleted successfully";
    if (!$deleted && file_exists($material['file_path'])) {
        $message .= " (Note: File could not be deleted from server)";
    }
    header("Location: learning_materials.php?booking_id=" . $booking_id . "&success=" . urlencode($message));
} else {
    header("Location: learning_materials.php?booking_id=" . $booking_id . "&error=" . urlencode("Failed to delete material"));
}
exit();
?>