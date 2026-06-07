<?php
session_start();
header('Content-Type: application/json');

include "config.php";

// Check if request is AJAX (JSON) or traditional form
$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
          strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';

// Get data from either JSON input or POST
if ($isAjax) {
    $data = json_decode(file_get_contents('php://input'), true);
    $token = $data['token'] ?? '';
    $email = $data['email'] ?? '';
    $password = $data['password'] ?? '';
    $confirm_password = $data['confirm_password'] ?? '';
} else {
    $token = $_POST['token'] ?? '';
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
}

// Validate inputs
if (empty($token) || empty($password) || empty($confirm_password)) {
    if ($isAjax) {
        echo json_encode(['success' => false, 'message' => 'Please fill in all fields.']);
        exit();
    } else {
        header("Location: reset_password.php?token=$token&error=Please+fill+in+all+fields.");
        exit();
    }
}

if ($password !== $confirm_password) {
    if ($isAjax) {
        echo json_encode(['success' => false, 'message' => 'Passwords do not match.']);
        exit();
    } else {
        header("Location: reset_password.php?token=$token&error=Passwords+do+not+match.");
        exit();
    }
}

if (strlen($password) < 8) {
    if ($isAjax) {
        echo json_encode(['success' => false, 'message' => 'Password must be at least 8 characters.']);
        exit();
    } else {
        header("Location: reset_password.php?token=$token&error=Password+must+be+at+least+6+characters.");
        exit();
    }
}

// Verify token is valid and not expired - Use prepared statement for security
$stmt = $conn->prepare("SELECT * FROM password_resets WHERE token = ? AND created_at >= NOW() - INTERVAL 1 HOUR");
$stmt->bind_param("s", $token);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows == 0) {
    if ($isAjax) {
        echo json_encode(['success' => false, 'message' => 'Reset link expired. Please request a new one.']);
        exit();
    } else {
        header("Location: forgotpassword.php?error=Reset+link+expired.+Please+request+a+new+one.");
        exit();
    }
}

$row = $result->fetch_assoc();
$email = $row['email'];

// Update user password - Use prepared statement
$hashed = password_hash($password, PASSWORD_DEFAULT);
$updateStmt = $conn->prepare("UPDATE users SET password = ? WHERE email = ?");
$updateStmt->bind_param("ss", $hashed, $email);

if ($updateStmt->execute()) {
    // Delete the used token - Use prepared statement
    $deleteStmt = $conn->prepare("DELETE FROM password_resets WHERE token = ?");
    $deleteStmt->bind_param("s", $token);
    $deleteStmt->execute();
    
    if ($isAjax) {
        echo json_encode(['success' => true, 'message' => 'Password reset successful! You can now log in.']);
        exit();
    } else {
        header("Location: login.php?success=Password+reset+successful!+You+can+now+log+in.");
        exit();
    }
} else {
    if ($isAjax) {
        echo json_encode(['success' => false, 'message' => 'Failed to update password. Please try again.']);
        exit();
    } else {
        header("Location: reset_password.php?token=$token&error=Failed+to+update+password.+Please+try+again.");
        exit();
    }
}

$conn->close();
?>