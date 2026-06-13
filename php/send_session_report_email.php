<?php
// send_session_report_email.php
require_once 'config.php';
require_once '../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function sendSessionReportNotification($tutorEmail, $tutorName, $studentName, $sessionDate, $sessionTime, $status, $adminNotes = null, $reportId = null) {
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
    $mail->addAddress($tutorEmail, $tutorName);

    $mail->isHTML(true);
        
        $formattedDate = date('d M Y', strtotime($sessionDate));
        $formattedTime = date('h:i A', strtotime($sessionTime));
        $resubmitLink = "http://kyoshitutor.site/php/submit_session_report.php?resubmit=1&report_id=" . $reportId;
        
        if ($status === 'approve') {
            $mail->Subject = 'Session Report Approved - Kyoshi';
            $mail->Body = "
            <!DOCTYPE html>
            <html>
            <head>
                <style>
                    body { font-family: 'Segoe UI', Arial, sans-serif; line-height: 1.6; }
                    .container { max-width: 550px; margin: 0 auto; }
                    .header { background: linear-gradient(135deg, #28a745, #20c997); padding: 30px; text-align: center; border-radius: 16px 16px 0 0; color: white; }
                    .content { background: #ffffff; padding: 30px; border-radius: 0 0 16px 16px; border: 1px solid #eef2f7; }
                    .session-details { background: #f0fdf4; padding: 15px; border-radius: 12px; margin: 15px 0; border-left: 4px solid #28a745; }
                    .payout-info { background: #fff3cd; padding: 15px; border-radius: 12px; margin: 15px 0; border-left: 4px solid #f59e0b; }
                    .footer { text-align: center; padding: 20px; font-size: 12px; color: #94a3b8; }
                </style>
            </head>
            <body>
                <div class='container'>
                    <div class='header'>
                        <h2>Session Report Approved!</h2>
                    </div>
                    <div class='content'>
                        <h3>Dear {$tutorName},</h3>
                        <p>Great news! Your session report for <strong>" . htmlspecialchars($studentName) . "</strong> has been <strong style='color: #28a745;'>APPROVED</strong> by the admin.</p>
                        <div class='session-details'>
                            <strong>📋 Session Details:</strong><br>
                            <strong>Student:</strong> " . htmlspecialchars($studentName) . "<br>
                            <strong>Date:</strong> {$formattedDate}<br>
                            <strong>Time:</strong> {$formattedTime}
                        </div>
                        <div class='payout-info'>
                            <strong>💰 Payout Information:</strong><br>
                            • Your earnings from this session have been added to your balance.<br>
                            • <strong>Minimum payout amount: RM 50.00</strong><br>
                            • You can request payout once your total earnings reach RM 50.00 or more.<br>
                            • Payout requests are processed within 3-5 business days.
                        </div>
                        <p><strong>📌 Next Steps:</strong></p>
                        <ul>
                            <li>Check your earnings balance in your dashboard</li>
                            <li>Once you reach RM 50.00, you can request a payout</li>
                            <li>Continue providing great lessons to your students!</li>
                        </ul>
                        <p>Keep up the excellent work! 🎉</p>
                    </div>
                    <div class='footer'>
                        <p>© 2024 Kyoshi Language Learning Platform</p>
                        <p style='font-size: 11px;'>Questions? Contact us at support@kyoshi.com</p>
                    </div>
                </div>
            </body>
            </html>
            ";
        } else {
            $mail->Subject = 'Session Report Needs Resubmit - Kyoshi';
            $mail->Body = "
            <!DOCTYPE html>
            <html>
            <head>
                <style>
                    body { font-family: 'Segoe UI', Arial, sans-serif; line-height: 1.6; }
                    .container { max-width: 550px; margin: 0 auto; }
                    .header { background: #dc2626; padding: 30px; text-align: center; border-radius: 16px 16px 0 0; color: white; }
                    .content { background: #ffffff; padding: 30px; border-radius: 0 0 16px 16px; border: 1px solid #eef2f7; }
                    .session-details { background: #f0fdf4; padding: 15px; border-radius: 12px; margin: 15px 0; }
                    .reason-box { background: #fef2f2; padding: 15px; border-radius: 12px; margin: 15px 0; border-left: 4px solid #dc2626; }
                    .btn-resubmit { background: #E75A9B; color: white; padding: 12px 24px; text-decoration: none; border-radius: 30px; display: inline-block; margin-top: 20px; font-weight: bold; }
                    .footer { text-align: center; padding: 20px; font-size: 12px; color: #94a3b8; }
                </style>
            </head>
            <body>
                <div class='container'>
                    <div class='header'>
                        <h2>Session Report Needs to resubmit</h2>
                    </div>
                    <div class='content'>
                        <h3>Dear {$tutorName},</h3>
                        <p>Regarding your session report for <strong>" . htmlspecialchars($studentName) . "</strong>:</p>
                        <div class='session-details'>
                            <strong>Student:</strong> " . htmlspecialchars($studentName) . "<br>
                            <strong>Date:</strong> {$formattedDate}<br>
                            <strong>Time:</strong> {$formattedTime}
                        </div>
                        <p>Your session report has been <strong style='color: #dc2626;'>REJECTED</strong> and needs revision.</p>";
                        
            if ($adminNotes) {
                $mail->Body .= "
                        <div class='reason-box'>
                            <strong>📝 Reason for rejection:</strong><br>
                            " . htmlspecialchars($adminNotes) . "
                        </div>";
            }
            
            $mail->Body .= "
                        <p><strong>What you need to do:</strong></p>
                        <ul>
                            <li>Review the feedback provided above</li>
                            <li>Click the button below to edit and resubmit your report</li>
                            <li>Make the necessary changes based on admin feedback</li>
                        </ul>
                        <center>
                            <a href='{$resubmitLink}' class='btn-resubmit' style='background: #E75A9B; color: white; padding: 12px 24px; text-decoration: none; border-radius: 30px; display: inline-block; margin-top: 20px; font-weight: bold;'>
                                ✏️ Edit & Resubmit Report
                            </a>
                        </center>
                        <p style='margin-top: 20px;'>Your payment will be processed only after the report is approved.</p>
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
        error_log("Session report email failed: " . $mail->ErrorInfo);
        return false;
    }
}
?>