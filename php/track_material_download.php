<?php
session_start();
include 'config.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    exit();
}

$userID = $_SESSION['user_id'];
$material_id = $_POST['material_id'] ?? 0;

if ($material_id) {
    $stmt = $conn->prepare("
        INSERT INTO material_downloads (user_id, material_id, downloaded_at) 
        VALUES (?, ?, NOW())
        ON DUPLICATE KEY UPDATE downloaded_at = NOW()
    ");
    $stmt->bind_param("ii", $userID, $material_id);
    $stmt->execute();
}

echo json_encode(['success' => true]);
?>