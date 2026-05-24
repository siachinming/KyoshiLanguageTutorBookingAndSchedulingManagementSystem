<?php
session_start();
include 'config.php';
if (!isset($_SESSION['user_id'])) { echo json_encode(['notifications'=>[],'count'=>0]); exit(); }
$userID = $_SESSION['user_id'];

$stmt = $conn->prepare("
    SELECT id, title, message, type, link, is_read, created_at
    FROM notifications
    WHERE user_id = ?
    ORDER BY created_at DESC
    LIMIT 20
");
$stmt->bind_param("i", $userID);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$unread = $conn->prepare("SELECT COUNT(*) AS cnt FROM notifications WHERE user_id = ? AND is_read = 0");
$unread->bind_param("i", $userID);
$unread->execute();
$count = $unread->get_result()->fetch_assoc()['cnt'];
$unread->close();

header('Content-Type: application/json');
echo json_encode(['notifications' => $rows, 'count' => (int)$count]);