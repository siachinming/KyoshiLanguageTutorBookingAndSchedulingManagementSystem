<?php
session_start();
include 'config.php';

header('Content-Type: text/plain'); // Force plain text response

if (!isset($_SESSION['user_id'])) {
    echo 'error';
    exit();
}

$studentID = $_SESSION['user_id'];
$tutorID = $_POST['tutor_id'] ?? 0;

if (!$tutorID) {
    echo 'error';
    exit();
}

// Check if already favourited
$check = $conn->prepare("SELECT id FROM student_favourites WHERE student_id = ? AND tutor_id = ?");
$check->bind_param("ii", $studentID, $tutorID);
$check->execute();
$exists = $check->get_result()->fetch_assoc();

if ($exists) {
    // Remove from favourites
    $delete = $conn->prepare("DELETE FROM student_favourites WHERE student_id = ? AND tutor_id = ?");
    $delete->bind_param("ii", $studentID, $tutorID);
    if ($delete->execute()) {
        echo 'removed';
    } else {
        echo 'error';
    }
} else {
    // Add to favourites
    $insert = $conn->prepare("INSERT INTO student_favourites (student_id, tutor_id, created_at) VALUES (?, ?, NOW())");
    $insert->bind_param("ii", $studentID, $tutorID);
    if ($insert->execute()) {
        echo 'added';
    } else {
        echo 'error';
    }
}
?>