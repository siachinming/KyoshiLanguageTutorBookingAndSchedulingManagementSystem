<?php
session_start();
include 'config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$payment_id = $_GET['payment_id'] ?? 0;

$query = $conn->prepare("SELECT notes FROM payments WHERE id = ?");
$query->bind_param("i", $payment_id);
$query->execute();
$result = $query->get_result()->fetch_assoc();

if ($result) {
    echo json_encode(['success' => true, 'notes' => $result['notes'] ?? '']);
} else {
    echo json_encode(['success' => false, 'message' => 'Payment not found']);
}
?>