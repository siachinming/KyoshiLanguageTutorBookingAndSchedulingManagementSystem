<?php
session_start();
include 'config.php';

// Check if admin is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$tutor_id = intval($_GET['tutor_id'] ?? 0);

if ($tutor_id <= 0) {
    echo json_encode([]);
    exit();
}

$result = $conn->query("
    SELECT id, certificate_name, file_path, status, uploaded_at 
    FROM tutor_certificates 
    WHERE tutor_id = $tutor_id 
    ORDER BY uploaded_at DESC
");

$certificates = [];
while ($row = $result->fetch_assoc()) {
    $certificates[] = [
        'id' => $row['id'],
        'certificate_name' => $row['certificate_name'],
        'file_path' => $row['file_path'],
        'status' => $row['status'],
        'uploaded_at' => $row['uploaded_at']
    ];
}

header('Content-Type: application/json');
echo json_encode($certificates);
?>