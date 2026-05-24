<?php
session_start();
include 'config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$userID = $_SESSION['user_id'];
$userRole = $_SESSION['role'];
$submission_id = intval($_GET['id'] ?? 0);
$booking_id = intval($_GET['booking_id'] ?? 0);

if (!$submission_id) {
    header("Location: " . ($userRole === 'tutor' ? "booking_requests.php" : "student_dashboard.php"));
    exit();
}

$stmt = $conn->prepare("
    SELECT s.*, a.tutor_id, b.student_id 
    FROM submissions s
    JOIN assignments a ON s.assignment_id = a.id
    JOIN bookings b ON a.booking_id = b.id
    WHERE s.id = ?
");
$stmt->bind_param("i", $submission_id);
$stmt->execute();
$submission = $stmt->get_result()->fetch_assoc();

if (!$submission) {
    die("Submission not found.");
}

$hasAccess = false;
if ($userRole === 'tutor' && $submission['tutor_id'] == $userID) {
    $hasAccess = true;
} elseif ($userRole === 'student' && $submission['student_id'] == $userID) {
    $hasAccess = true;
}

if (!$hasAccess) {
    die("Access denied.");
}

if (!file_exists($submission['file_path'])) {
    die("File not found.");
}

header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $submission['file_name'] . '"');
header('Content-Length: ' . filesize($submission['file_path']));
readfile($submission['file_path']);
exit();
?>