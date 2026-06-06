<?php
session_start();
include 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$payment_id = $_GET['payment_id'] ?? 0;

if (!$payment_id) {
    echo json_encode(['success' => false, 'message' => 'Invalid payment ID']);
    exit();
}

// Extract bank details from payment notes
$query = $conn->prepare("SELECT notes FROM payments WHERE id = ?");
$query->bind_param("i", $payment_id);
$query->execute();
$result = $query->get_result()->fetch_assoc();

$bank_name = '';
$bank_account_number = '';
$bank_account_name = '';

if ($result && $result['notes']) {
    // Parse bank details from notes
    preg_match('/REFUND BANK DETAILS: ([^-]+) - ([^-]+) - ([^\n]+)/', $result['notes'], $matches);
    if (count($matches) >= 4) {
        $bank_name = trim($matches[1]);
        $bank_account_number = trim($matches[2]);
        $bank_account_name = trim($matches[3]);
    }
}

echo json_encode([
    'success' => !empty($bank_name),
    'bank_name' => $bank_name,
    'bank_account_number' => $bank_account_number,
    'bank_account_name' => $bank_account_name
]);
?>