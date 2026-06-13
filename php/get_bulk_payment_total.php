<?php
session_start();
include 'config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$receipt_number = $_GET['receipt_number'] ?? '';

if (empty($receipt_number)) {
    echo json_encode(['success' => false, 'message' => 'Receipt number required']);
    exit();
}

$query = $conn->query("
    SELECT id, amount, booking_id 
    FROM payments 
    WHERE receipt_number = '$receipt_number' AND status = 'pending'
");

$payments = [];
$total_expected = 0;

while ($row = $query->fetch_assoc()) {
    $total_expected += $row['amount'];
    $payments[] = [
        'id' => $row['id'],
        'booking_id' => $row['booking_id'],
        'amount' => $row['amount']
    ];
}

echo json_encode([
    'success' => true,
    'total_expected' => $total_expected,
    'payments' => $payments,
    'count' => count($payments)
]);
?>