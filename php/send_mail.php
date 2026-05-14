<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require '../vendor/autoload.php';

function sendPasswordChangedEmail($toEmail, $toName) {
    $mail = new PHPMailer(true);
    $time = date('d M Y, h:i A');

    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = 'tls';
        $mail->Port       = 587;
        $mail->CharSet    = 'UTF-8';       
        $mail->Encoding   = 'base64';     

        $mail->setFrom('sohisabella87@gmail.com', 'Kyoshi');
        $mail->addAddress($toEmail, $toName);

        $mail->isHTML(true);
        $mail->Subject = '⚠️ Your Kyoshi Password Was Changed';
        $mail->Body    = "
            <div style='font-family:Segoe UI,sans-serif;max-width:520px;margin:auto;background:#FFF1F6;padding:30px;border-radius:24px;'>
                <div style='background:linear-gradient(135deg,#E75A9B,#F28AB2);padding:28px 32px;text-align:center;border-radius:16px;'>
                    <h1 style='color:white;margin:0;font-size:22px;'>Kyoshi</h1>
                    <p style='color:rgba(255,255,255,.85);margin:6px 0 0;font-size:13px;'>Student Learning Space</p>
                </div>
                <div style='background:white;border-radius:16px;padding:32px;margin-top:16px;'>
                    <h2 style='color:#342635;margin:0 0 12px;'>Password Changed</h2>
                    <p style='color:#7B6178;'>Hi <strong>{$toName}</strong>,</p>
                    <p style='color:#7B6178;'>Your Kyoshi account password was successfully changed on:</p>
                    <div style='background:#FFF1F6;border-radius:14px;padding:14px 18px;margin:16px 0;font-weight:700;color:#C94F86;'>
                        🕐 {$time}
                    </div>
                    <p style='color:#7B6178;'>If <strong>you made this change</strong>, no action is needed — you're all good!</p>
                    <div style='background:#FFF0F0;border:1px solid #FFCCCC;border-radius:14px;padding:16px 18px;margin:16px 0;'>
                        <p style='margin:0;color:#c0392b;font-weight:700;'>⚠️ Wasn't you?</p>
                        <p style='margin:8px 0 0;color:#7B6178;font-size:13px;'>
                            If you didn't change your password, your account may be compromised.
                            Contact us immediately or reset your password right away.
                        </p>
                    </div>
                    <a href='http://localhost/kyoshi/php/forgotpassword.php'
                       style='display:inline-block;margin-top:10px;background:linear-gradient(135deg,#E75A9B,#F28AB2);
                              color:white;padding:13px 28px;border-radius:999px;text-decoration:none;
                              font-weight:700;font-size:14px;'>
                        Reset My Password
                    </a>
                </div>
                <p style='text-align:center;margin-top:18px;font-size:12px;color:#aaa;'>
                    This is an automated message from Kyoshi. Please do not reply.
                </p>
            </div>
        ";

        $mail->send();
        return true;

    } catch (Exception $e) {
        error_log("Password change email failed: " . $mail->ErrorInfo);
        return false;
    }
}