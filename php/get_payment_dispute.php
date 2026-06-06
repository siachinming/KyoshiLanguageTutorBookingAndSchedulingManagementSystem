<?php
session_start();
include 'config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$payment_id = $_GET['payment_id'] ?? 0;
$booking_id = $_GET['booking_id'] ?? 0;

if (!$payment_id && !$booking_id) {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit();
}

// Get dispute from disputes table
$query = $conn->prepare("
    SELECT d.*, 
           s.fullname as student_name,
           t.fullname as tutor_name
    FROM disputes d
    LEFT JOIN users s ON d.student_id = s.id
    LEFT JOIN users t ON d.tutor_id = t.id
    WHERE d.payment_id = ? AND d.dispute_type = 'payment'
    ORDER BY d.created_at DESC
    LIMIT 1
");
$query->bind_param("i", $payment_id);
$query->execute();
$result = $query->get_result();

if ($dispute = $result->fetch_assoc()) {
    echo json_encode([
        'success' => true,
        'dispute' => [
            'id' => $dispute['id'],
            'student_name' => $dispute['student_name'],
            'tutor_name' => $dispute['tutor_name'],
            'issue_type' => $dispute['issue_type'],
            'resolution_type' => $dispute['resolution_type'] ?? 'Pending',
            'status' => $dispute['status'],
            'message' => $dispute['message'],
            'resolution_note' => $dispute['resolution_note'] ?? null,
            'created_at' => $dispute['created_at']
        ]
    ]);
} else {
    // If no dispute found in disputes table, check payment notes
    $paymentQuery = $conn->prepare("
        SELECT p.notes, p.status, p.amount, 
               s.fullname as student_name,
               t.fullname as tutor_name
        FROM payments p
        LEFT JOIN users s ON p.student_id = s.id
        LEFT JOIN users t ON p.tutor_id = t.id
        WHERE p.id = ?
    ");
    $paymentQuery->bind_param("i", $payment_id);
    $paymentQuery->execute();
    $payment = $paymentQuery->get_result()->fetch_assoc();
    
    if ($payment && $payment['status'] == 'disputed') {
        echo json_encode([
            'success' => true,
            'dispute' => [
                'id' => 'N/A',
                'student_name' => $payment['student_name'] ?? 'Unknown',
                'tutor_name' => $payment['tutor_name'] ?? 'Unknown',
                'issue_type' => 'Payment Dispute',
                'resolution_type' => 'Pending',
                'status' => 'pending',
                'message' => $payment['notes'] ?? 'No dispute details available',
                'resolution_note' => null,
                'created_at' => date('Y-m-d H:i:s')
            ]
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'No dispute found for this payment']);
    }
}
?>