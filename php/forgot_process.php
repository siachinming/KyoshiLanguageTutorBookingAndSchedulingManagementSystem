<?php
session_start();
header('Content-Type: application/json');

include "config.php";

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require '../vendor/autoload.php';

$email = $_POST['email'] ?? '';

if (empty($email)) {
    echo json_encode(['success' => false, 'message' => 'Please enter your email.']);
    exit();
}

// CHECK IF EMAIL EXISTS - Use prepared statement to prevent SQL injection
$stmt = $conn->prepare("SELECT * FROM users WHERE email = ?");
$stmt->bind_param("s", $email);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows == 0) {
    echo json_encode(['success' => false, 'message' => 'No account found with that email.']);
    exit();
}

// GENERATE TOKEN
$token = bin2hex(random_bytes(32));

// Check if password_resets table exists, create if not
$tableCheck = $conn->query("SHOW TABLES LIKE 'password_resets'");
if ($tableCheck->num_rows == 0) {
    $conn->query("CREATE TABLE password_resets (
        id INT AUTO_INCREMENT PRIMARY KEY,
        email VARCHAR(255) NOT NULL,
        token VARCHAR(255) NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY email (email)
    )");
}

// Insert or update token - Use prepared statement
$stmt2 = $conn->prepare("INSERT INTO password_resets (email, token, created_at) VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE token = ?, created_at = NOW()");
$stmt2->bind_param("sss", $email, $token, $token);
$stmt2->execute();

$resetLink = "http://kyoshitutor.site/php/reset_password.php?token=$token";

$mail = new PHPMailer(true);

try {
    $mail->isSMTP();
    $mail->Host       = 'smtp.gmail.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = SMTP_USER;
    $mail->Password   = SMTP_PASS;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = 587;

    $mail->setFrom('sohisabella87@gmail.com', 'Kyoshi');
    $mail->addAddress($email);

    $mail->isHTML(true);
    $mail->Subject = 'Reset Your Kyoshi Password';
    $mail->Body    = "
        <div style='font-family:Segoe UI,sans-serif;max-width:500px;margin:auto;'>
            <h2 style='color:#38bdf8;'>Kyoshi Password Reset</h2>
            <p>We received a request to reset your password. Click the button below:</p>
            <a href='$resetLink'
               style='display:inline-block;padding:12px 24px;background:#38bdf8;
                      color:white;border-radius:10px;text-decoration:none;font-weight:bold;'>
               Reset Password
            </a>
            <p style='margin-top:20px;color:gray;font-size:13px;'>
                This link expires in <strong>1 hour</strong>.<br>
                If you didn't request this, you can safely ignore this email.
            </p>
        </div>
    ";

    $mail->send();
    echo json_encode(['success' => true, 'message' => 'Reset link sent! Check your email.']);
    exit();

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Failed to send email. Error: ' . $mail->ErrorInfo]);
    exit();
}

$conn->close();
?>