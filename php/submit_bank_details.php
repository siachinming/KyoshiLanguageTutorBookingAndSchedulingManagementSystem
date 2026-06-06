<?php
session_start();
include 'config.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$userID = $_SESSION['user_id'];
$data = json_decode(file_get_contents('php://input'), true);

$payment_id = $data['payment_id'] ?? 0;
$refund_amount = $data['refund_amount'] ?? 0;
$bank_name = $data['bank_name'] ?? '';
$bank_account_number = $data['bank_account_number'] ?? '';
$bank_account_name = $data['bank_account_name'] ?? '';

if (!$payment_id) {
    echo json_encode(['success' => false, 'message' => 'Invalid payment ID']);
    exit();
}

// Update payment notes with bank details
$update = $conn->prepare("
    UPDATE payments 
    SET notes = CONCAT(IFNULL(notes, ''), '\n[REFUND BANK DETAILS: ', ?, ' - ', ?, ' - ', ?, ' submitted on ', NOW(), ']')
    WHERE id = ? AND student_id = ?
");

$update->bind_param("ssssi", $bank_name, $bank_account_number, $bank_account_name, $payment_id, $userID);

if ($update->execute()) {
    echo json_encode([
        'success' => true,
        'message' => 'Bank details submitted successfully'
    ]);
} else {
    echo json_encode([
        'success' => false,
        'message' => 'Failed to save bank details'
    ]);
}
?>