<?php
session_start();
header('Content-Type: application/json');
include 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'tutor') {
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$userID = $_SESSION['user_id'];
$bankId = intval($_GET['id'] ?? 0);

if (!$bankId) {
    echo json_encode(['error' => 'Invalid bank ID']);
    exit();
}

$stmt = $conn->prepare("
    SELECT id, bank_name, bank_account_number, bank_account_name, is_default 
    FROM tutor_bank_details 
    WHERE id = ? AND tutor_id = ?
");
$stmt->bind_param("ii", $bankId, $userID);
$stmt->execute();
$result = $stmt->get_result()->fetch_assoc();

if ($result) {
    echo json_encode($result);
} else {
    echo json_encode(['error' => 'Bank account not found']);
}
?>