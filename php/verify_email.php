<?php
include "config.php";

$token = $_GET['token'] ?? '';

if (empty($token)) {
    die("Invalid verification link.");
}

$stmt = $conn->prepare("SELECT id FROM users WHERE verification_token = ?");
$stmt->bind_param("s", $token);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    die("Invalid or expired token.");
}

$user = $result->fetch_assoc();

$update = $conn->prepare("
UPDATE users 
SET is_verified = 1, verification_token = NULL 
WHERE id = ?
");
$update->bind_param("i", $user['id']);
$update->execute();

echo "✅ Email verified successfully! You can now log in.";
?>