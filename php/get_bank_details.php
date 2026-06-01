<?php
session_start();
include 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'tutor') {
    exit(json_encode(['error' => 'Unauthorized']));
}

$bankId = intval($_GET['id'] ?? 0);
$stmt = $conn->prepare("SELECT id, bank_name, bank_account_number, bank_account_name FROM tutor_bank_details WHERE id = ? AND tutor_id = ?");
$stmt->bind_param("ii", $bankId, $_SESSION['user_id']);
$stmt->execute();
$result = $stmt->get_result()->fetch_assoc();
header('Content-Type: application/json');
echo json_encode($result);
?>