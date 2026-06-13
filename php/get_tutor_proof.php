<?php
session_start();
header('Content-Type: application/json');
include 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$booking_id = intval($_GET['booking_id'] ?? 0);

if ($booking_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid booking ID']);
    exit();
}

$stmt = $conn->prepare("
    SELECT * FROM attendance_proofs 
    WHERE booking_id = ? AND user_role = 'tutor'
    ORDER BY uploaded_at DESC
    LIMIT 1
");
$stmt->bind_param("i", $booking_id);
$stmt->execute();
$proof = $stmt->get_result()->fetch_assoc();

if ($proof && $proof['file_path']) {
    echo json_encode([
        'success' => true,
        'has_proof' => true,
        'proof_path' => '../uploads/proofs/' . $proof['file_path'],
        'uploaded_at' => date('d M Y, g:i A', strtotime($proof['uploaded_at']))
    ]);
} else {
    echo json_encode([
        'success' => true,
        'has_proof' => false
    ]);
}
?>