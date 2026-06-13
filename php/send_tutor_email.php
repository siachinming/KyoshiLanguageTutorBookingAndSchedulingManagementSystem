<?php
if (!isset($_SESSION)) {
    session_start();
}
require_once 'config.php';
require_once '../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function sendTutorNotificationEmail($email, $name, $status, $rejectionReason = null, $qualifications = []) {
    $mail = new PHPMailer(true);
    
    try {
    $mail->isSMTP();
    $mail->Host       = 'smtp.gmail.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = SMTP_USER;
    $mail->Password   = SMTP_PASS;
    $mail->SMTPSecure = 'tls';
    $mail->Port       = 587;

    $mail->setFrom('sohisabella87@gmail.com', 'Kyoshi');
    $mail->addAddress($email);

    $mail->isHTML(true);
        
        if ($status === 'approved') {
            $mail->Subject = 'Welcome to Kyoshi - Your Tutor Application Has Been Approved!';
            
            $qualificationsHtml = '';
            if (!empty($qualifications)) {
                $qualificationsHtml = '
                <div style="background: #f0fdf4; padding: 15px; border-radius: 12px; margin: 15px 0; border-left: 4px solid #28a745;">
                    <strong style="color: #166534;">Your Verified Qualifications:</strong>
                    <ul style="margin-top: 10px;">';
                foreach ($qualifications as $qual) {
                    $qualificationsHtml .= '<li style="margin: 5px 0; color: #14532d;">' . htmlspecialchars($qual) . '</li>';
                }
                $qualificationsHtml .= '
                    </ul>
                </div>';
            }
            
            $mail->Body = "
            <!DOCTYPE html>
            <html>
            <head>
                <meta charset='UTF-8'>
                <style>
                    body { font-family: 'Segoe UI', Arial, sans-serif; line-height: 1.6; }
                    .container { max-width: 550px; margin: 0 auto; }
                    .header { background: linear-gradient(135deg, #1d3156, #E75A9B); padding: 30px; text-align: center; border-radius: 16px 16px 0 0; color: white; }
                    .content { background: #ffffff; padding: 30px; border-radius: 0 0 16px 16px; border: 1px solid #eef2f7; }
                    .btn { background: #E75A9B; color: white; padding: 12px 28px; text-decoration: none; border-radius: 30px; display: inline-block; margin-top: 20px; font-weight: bold; }
                    .footer { text-align: center; padding: 20px; font-size: 12px; color: #94a3b8; border-top: 1px solid #eef2f7; margin-top: 20px; }
                    .highlight { color: #28a745; font-weight: bold; }
                </style>
            </head>
            <body>
                <div class='container'>
                    <div class='header'>
                        <h2>✨ Welcome to Kyoshi! ✨</h2>
                    </div>
                    <div class='content'>
                        <h3>Dear {$name},</h3>
                        <p>We are thrilled to inform you that your tutor application has been <span class='highlight'>APPROVED</span>!</p>
                        <p>You can now start sharing your knowledge and earning on our platform.</p>
                        {$qualificationsHtml}
                        <p><strong>🎯 What's Next?</strong></p>
                        <ul>
                            <li>Log in to your tutor dashboard</li>
                            <li>Set your availability calendar</li>
                            <li>Start accepting student bookings</li>
                        </ul>
                        <center>
                            <a href='http://kyoshitutor.site/php/tutor_dashboard.php' class='btn'>Go to Dashboard</a>
                        </center>
                    </div>
                    <div class='footer'>
                        <p>© 2024 Kyoshi Language Learning Platform</p>
                        <p style='font-size: 11px;'>Questions? Contact us at support@kyoshi.com</p>
                    </div>
                </div>
            </body>
            </html>
            ";
            
            $mail->AltBody = "Dear {$name},\n\nWelcome to Kyoshi! Your tutor application has been APPROVED!\n\nYou can now start teaching and earning on our platform.\n\nLog in here: http://kyoshitutor.site/php/tutor_dashboard.php\n\nBest regards,\nKyoshi Team";
            
        } else {
            $mail->Subject = '📝 Update on Your Kyoshi Tutor Application';
            
            $reasonHtml = '';
            if ($rejectionReason) {
                $reasonHtml = '
                <div style="background: #fef2f2; padding: 15px; border-radius: 12px; margin: 15px 0; border-left: 4px solid #dc2626;">
                    <strong style="color: #991b1b;">Reason for rejection:</strong>
                    <p style="margin-top: 8px; color: #7f1d1d;">' . htmlspecialchars($rejectionReason) . '</p>
                </div>';
            }
            
            $mail->Body = "
            <!DOCTYPE html>
            <html>
            <head>
                <meta charset='UTF-8'>
                <style>
                    body { font-family: 'Segoe UI', Arial, sans-serif; line-height: 1.6; }
                    .container { max-width: 550px; margin: 0 auto; }
                    .header { background: #dc2626; padding: 30px; text-align: center; border-radius: 16px 16px 0 0; color: white; }
                    .content { background: #ffffff; padding: 30px; border-radius: 0 0 16px 16px; border: 1px solid #eef2f7; }
                    .footer { text-align: center; padding: 20px; font-size: 12px; color: #94a3b8; border-top: 1px solid #eef2f7; margin-top: 20px; }
                </style>
            </head>
            <body>
                <div class='container'>
                    <div class='header'>
                        <h2>Application Update</h2>
                    </div>
                    <div class='content'>
                        <h3>Dear {$name},</h3>
                        <p>Thank you for your interest in becoming a tutor on Kyoshi.</p>
                        <p>After careful review, we regret to inform you that your application has been <strong style='color: #dc2626;'>REJECTED</strong> at this time.</p>
                        {$reasonHtml}
                        <p><strong>What You Can Do:</strong></p>
                        <ul>
                            <li>Review the feedback provided above</li>
                            <li>Improve your application based on the feedback</li>
                            <li>Submit a new application after making improvements</li>
                        </ul>
                        <p>We encourage you to re-apply in the future!</p>
                    </div>
                    <div class='footer'>
                        <p>© 2024 Kyoshi Language Learning Platform</p>
                        <p style='font-size: 11px;'>Questions? Contact us at support@kyoshi.com</p>
                    </div>
                </div>
            </body>
            </html>
            ";
            
            $mail->AltBody = "Dear {$name},\n\nThank you for your interest in Kyoshi.\n\nYour tutor application has been REJECTED.\n\nReason: " . ($rejectionReason ?? 'Not provided') . "\n\nYou may re-apply after addressing the feedback.\n\nBest regards,\nKyoshi Team";
        }
        
        return $mail->send();
        
    } catch (Exception $e) {
        error_log("Email sending failed: " . $mail->ErrorInfo);
        return false;
    }
}
?>