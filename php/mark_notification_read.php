<?php
session_start();
include 'config.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false]);
    exit();
}

$userID = $_SESSION['user_id'];
$data = json_decode(file_get_contents('php://input'), true);
$notifId = intval($data['id'] ?? 0);

if ($notifId) {
    $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $notifId, $userID);
    $stmt->execute();
    $stmt->close();
}

// Mark all if id = 0
if (!$notifId) {
    $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
    $stmt->bind_param("i", $userID);
    $stmt->execute();
    $stmt->close();
}

echo json_encode(['success' => true]);