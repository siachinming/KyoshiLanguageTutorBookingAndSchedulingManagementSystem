<?php
session_start();
include 'config.php';

if (!isset($_SESSION['user_id'])) exit();

$tutorId = (int)($_POST['tutor_id'] ?? 0);
$userId = $_SESSION['user_id'];

$stmt = $conn->prepare("SELECT id FROM student_favourites WHERE student_id = ? AND tutor_id = ?");
$stmt->bind_param("ii", $userId, $tutorId);
$stmt->execute();
$exists = $stmt->get_result()->fetch_assoc();

if ($exists) {
    $stmt = $conn->prepare("DELETE FROM student_favourites WHERE student_id = ? AND tutor_id = ?");
    $stmt->bind_param("ii", $userId, $tutorId);
    $stmt->execute();
    echo "removed";  // just plain text
} else {
    $stmt = $conn->prepare("INSERT INTO student_favourites (student_id, tutor_id) VALUES (?, ?)");
    $stmt->bind_param("ii", $userId, $tutorId);
    $stmt->execute();
    echo "added";    // just plain text
}