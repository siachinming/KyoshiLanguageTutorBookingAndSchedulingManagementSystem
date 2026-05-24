<?php

include "config.php";

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require '../vendor/autoload.php';

$mail = new PHPMailer(true);

try {

    $mail->SMTPDebug = 2;

    $mail->isSMTP();
    $mail->Host       = 'smtp.gmail.com';
    $mail->SMTPAuth   = true;

    $mail->Username   = SMTP_USER;
    $mail->Password   = SMTP_PASS;

    $mail->SMTPSecure = 'tls';
    $mail->Port       = 587;

    $mail->setFrom(SMTP_USER, 'Kyoshi');
    $mail->addAddress('YOUR_EMAIL@gmail.com');

    $mail->isHTML(true);
    $mail->Subject = 'SMTP Test';
    $mail->Body    = 'Testing Gmail SMTP';

    $mail->send();

    echo "SUCCESS";

} catch (Exception $e) {

    echo $mail->ErrorInfo;
}