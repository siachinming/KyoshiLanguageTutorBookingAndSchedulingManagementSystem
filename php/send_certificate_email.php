<?php
// send_certificate_email.php
require_once 'config.php';
require_once '../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function sendCertificateNotification($tutorEmail, $tutorName, $certificateName, $status, $adminNotes = null) {
    $mail = new PHPMailer(true);
    
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
        
        $mail->setFrom(SMTP_USER, 'Kyoshi Language Platform');
        $mail->addAddress($tutorEmail, $tutorName);
        
        $mail->isHTML(true);
        
        if ($status === 'approved') {
            $mail->Subject = '✅ Certificate Approved - Kyoshi';
            $mail->Body = "
            <!DOCTYPE html>
            <html>
            <head>
                <style>
                    body { font-family: 'Segoe UI', Arial, sans-serif; line-height: 1.6; }
                    .container { max-width: 550px; margin: 0 auto; }
                    .header { background: linear-gradient(135deg, #28a745, #20c997); padding: 30px; text-align: center; border-radius: 16px 16px 0 0; color: white; }
                    .content { background: #ffffff; padding: 30px; border-radius: 0 0 16px 16px; border: 1px solid #eef2f7; }
                    .cert-details { background: #f0fdf4; padding: 15px; border-radius: 12px; margin: 15px 0; border-left: 4px solid #28a745; }
                    .footer { text-align: center; padding: 20px; font-size: 12px; color: #94a3b8; }
                </style>
            </head>
            <body>
                <div class='container'>
                    <div class='header'>
                        <h2>✅ Certificate Approved!</h2>
                    </div>
                    <div class='content'>
                        <h3>Dear {$tutorName},</h3>
                        <p>We are pleased to inform you that your certificate has been <strong style='color: #28a745;'>APPROVED</strong> and added to your qualifications!</p>
                        <div class='cert-details'>
                            <strong>📜 Certificate Details:</strong><br>
                            <strong>Name:</strong> " . htmlspecialchars($certificateName) . "<br>
                            <strong>Status:</strong> <span style='color: #28a745;'>Approved ✓</span>
                        </div>
                        <p>This qualification will now appear on your tutor profile, increasing your credibility with potential students.</p>
                        <p>Keep up the great work! 🎉</p>
                    </div>
                    <div class='footer'>
                        <p>© 2024 Kyoshi Language Learning Platform</p>
                    </div>
                </div>
            </body>
            </html>
            ";
        } else {
            $mail->Subject = '❌ Certificate Update - Kyoshi';
            $mail->Body = "
            <!DOCTYPE html>
            <html>
            <head>
                <style>
                    body { font-family: 'Segoe UI', Arial, sans-serif; line-height: 1.6; }
                    .container { max-width: 550px; margin: 0 auto; }
                    .header { background: #dc2626; padding: 30px; text-align: center; border-radius: 16px 16px 0 0; color: white; }
                    .content { background: #ffffff; padding: 30px; border-radius: 0 0 16px 16px; border: 1px solid #eef2f7; }
                    .reason-box { background: #fef2f2; padding: 15px; border-radius: 12px; margin: 15px 0; border-left: 4px solid #dc2626; }
                    .footer { text-align: center; padding: 20px; font-size: 12px; color: #94a3b8; }
                </style>
            </head>
            <body>
                <div class='container'>
                    <div class='header'>
                        <h2>❌ Certificate Update</h2>
                    </div>
                    <div class='content'>
                        <h3>Dear {$tutorName},</h3>
                        <p>Regarding your certificate <strong>" . htmlspecialchars($certificateName) . "</strong>:</p>
                        <p>We regret to inform you that your certificate has been <strong style='color: #dc2626;'>REJECTED</strong>.</p>";
                        
            if ($adminNotes) {
                $mail->Body .= "
                        <div class='reason-box'>
                            <strong>📝 Reason for rejection:</strong><br>
                            " . htmlspecialchars($adminNotes) . "
                        </div>
                        <p><strong>What you can do:</strong></p>
                        <ul>
                            <li>Review the feedback provided above</li>
                            <li>Upload a clearer or more appropriate certificate</li>
                            <li>Contact support if you need clarification</li>
                        </ul>";
            }
            
            $mail->Body .= "
                    </div>
                    <div class='footer'>
                        <p>© 2024 Kyoshi Language Learning Platform</p>
                    </div>
                </div>
            </body>
            </html>
            ";
        }
        
        return $mail->send();
        
    } catch (Exception $e) {
        error_log("Certificate email failed: " . $mail->ErrorInfo);
        return false;
    }
}
?>