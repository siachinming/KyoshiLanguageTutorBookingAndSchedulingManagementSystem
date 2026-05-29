<?php
session_start();
include 'config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$userID = $_SESSION['user_id'];
$role = $_SESSION['role'];
$material_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$material_id) {
    header("Location: " . ($role === 'tutor' ? 'material_overview.php' : 'my_materials.php'));
    exit();
}

// Get material info
$stmt = $conn->prepare("
    SELECT lm.*, b.student_id, b.tutor_id 
    FROM learning_materials lm
    LEFT JOIN bookings b ON lm.booking_id = b.id
    WHERE lm.id = ?
");
$stmt->bind_param("i", $material_id);
$stmt->execute();
$material = $stmt->get_result()->fetch_assoc();

if (!$material) {
    header("Location: " . ($role === 'tutor' ? 'material_overview.php' : 'my_materials.php'));
    exit();
}

// Check permission
if ($role === 'tutor' && $material['tutor_id'] != $userID) {
    header("Location: material_overview.php");
    exit();
} elseif ($role === 'student') {
    $checkStmt = $conn->prepare("
        SELECT 1 FROM bookings 
        WHERE id = ? AND student_id = ?
    ");
    $checkStmt->bind_param("ii", $material['booking_id'], $userID);
    $checkStmt->execute();
    if (!$checkStmt->get_result()->fetch_assoc()) {
        header("Location: my_materials.php");
        exit();
    }
}

// If it's a URL, redirect
if ($material['is_url'] == 1) {
    header("Location: " . $material['material_url']);
    exit();
}

// Get file path from database
$filePath = $material['file_path'];
$fileName = $material['file_name'];

// Use the path directly
$fullPath = $filePath;

// Check if file exists
if (!file_exists($fullPath)) {
    $altPath = dirname(__DIR__) . '/uploads/materials/' . basename($filePath);
    if (file_exists($altPath)) {
        $fullPath = $altPath;
    } else {
        die("File not found");
    }
}

// FORCE DOWNLOAD - These headers are critical
header('Content-Description: File Transfer');
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . basename($fileName) . '"');
header('Content-Transfer-Encoding: binary');
header('Expires: 0');
header('Cache-Control: must-revalidate');
header('Pragma: public');
header('Content-Length: ' . filesize($fullPath));

// Clear output buffer
ob_clean();
flush();

// Read file and force download
readfile($fullPath);
exit();
?>