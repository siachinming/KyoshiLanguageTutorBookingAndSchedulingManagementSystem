<?php
session_start();
include 'config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$material_id = intval($_GET['id'] ?? 0);
$booking_id = intval($_GET['booking_id'] ?? 0);

if (!$material_id) {
    header("Location: booking_requests.php");
    exit();
}

$stmt = $conn->prepare("
    SELECT lm.*, b.tutor_id, b.student_id 
    FROM learning_materials lm
    JOIN bookings b ON lm.booking_id = b.id
    WHERE lm.id = ?
");
$stmt->bind_param("i", $material_id);
$stmt->execute();
$material = $stmt->get_result()->fetch_assoc();

if (!$material) {
    die("Material not found.");
}

$userID = $_SESSION['user_id'];
$userRole = $_SESSION['role'];

$hasAccess = false;
if ($userRole === 'tutor' && $material['tutor_id'] == $userID) {
    $hasAccess = true;
} elseif ($userRole === 'student' && $material['student_id'] == $userID) {
    $hasAccess = true;
}

if (!$hasAccess) {
    die("Access denied.");
}

if (!file_exists($material['file_path'])) {
    die("File not found.");
}

header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $material['file_name'] . '"');
header('Content-Length: ' . filesize($material['file_path']));
readfile($material['file_path']);
exit();
?>