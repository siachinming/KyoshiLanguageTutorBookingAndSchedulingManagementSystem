<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'vendor/autoload.php';
include "config.php";

$email = $_POST['email'];

// check user
$sql = "SELECT * FROM users WHERE email='$email'";
$result = $conn->query($sql);

if ($result->num_rows == 0) {
    die("❌ Email not found");
}

// generate token
$token = bin2hex(random_bytes(32));

// store token
$conn->query("INSERT INTO password_resets (email, token)
VALUES ('$email', '$token')");

// reset link
$resetLink = "http://localhost/kyoshi/reset_password.php?token=$token";

// PHPMailer
$mail = new PHPMailer(true);

try {
    $mail->isSMTP();
    $mail->Host = 'smtp.gmail.com';
    $mail->SMTPAuth = true;
    $mail->Username = 'yourgmail@gmail.com';
    $mail->Password = 'your_app_password';
    $mail->SMTPSecure = 'tls';
    $mail->Port = 587;

    $mail->setFrom('noreply@kyoshi.com', 'Kyoshi');
    $mail->addAddress($email);

    $mail->Subject = "Password Reset - Kyoshi";
    $mail->Body = "Click to reset password:\n\n$resetLink";

    $mail->send();

    echo "✅ Reset link sent to email";

} catch (Exception $e) {
    echo "❌ Email failed: {$mail->ErrorInfo}";
}
?>