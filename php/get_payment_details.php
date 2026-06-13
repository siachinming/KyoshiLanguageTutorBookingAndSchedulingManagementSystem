<?php
session_start();
include 'config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$payment_id = $_GET['payment_id'] ?? 0;

if (!$payment_id) {
    echo json_encode(['success' => false, 'message' => 'Invalid payment ID']);
    exit();
}

$query = $conn->query("SELECT amount FROM payments WHERE id = $payment_id");
$payment = $query->fetch_assoc();

if (!$payment) {
    echo json_encode(['success' => false, 'message' => 'Payment not found']);
    exit();
}

echo json_encode([
    'success' => true,
    'amount' => $payment['amount']
]);
?>