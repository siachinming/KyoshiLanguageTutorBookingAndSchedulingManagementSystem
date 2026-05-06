<?php
session_start();
include "config.php";

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require '../vendor/autoload.php';

$email = $_POST['email'] ?? '';

if (empty($email)) {
    header("Location: forgotpassword.php?error=Please+enter+your+email.");
    exit();
}

// CHECK IF EMAIL EXISTS
$sql = "SELECT * FROM users WHERE email = '$email'";
$result = $conn->query($sql);

if ($result->num_rows == 0) {
    header("Location: forgotpassword.php?error=No+account+found+with+that+email.");
    exit();
}

// GENERATE TOKEN
$token = bin2hex(random_bytes(32));
$sql2 = "INSERT INTO password_resets (email, token, created_at) VALUES ('$email', '$token', NOW())
         ON DUPLICATE KEY UPDATE token = '$token', created_at = NOW()";
$conn->query($sql2); // ✅ this line was missing!

// RESET LINK
$resetLink = "http://localhost/kyoshi/php/reset_password.php?token=$token";

// SEND EMAIL
$mail = new PHPMailer(true);

try {
    $mail->isSMTP();
    $mail->Host       = 'smtp.gmail.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = 'sohisabella87@gmail.com';
    $mail->Password   = 'plvq ersb kkvv vlir';
    $mail->SMTPSecure = 'tls';
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
    header("Location: forgotpassword.php?success=Reset+link+sent!+Check+your+email.");
    exit();

} catch (Exception $e) {
    header("Location: forgotpassword.php?error=Failed+to+send+email.+Please+try+again.");
    exit();
}

$conn->close();
?>