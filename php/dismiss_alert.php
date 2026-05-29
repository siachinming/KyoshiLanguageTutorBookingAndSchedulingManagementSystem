<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    exit();
}

$type = $_POST['type'] ?? '';

if ($type === 'dispute') {
    $_SESSION['dismissed_dispute_alert'] = true;
} elseif ($type === 'report') {
    $_SESSION['dismissed_report_alert'] = true;
}

echo json_encode(['success' => true]);
?>