<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require '../vendor/autoload.php';
require 'config.php';

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
        $mail->Subject = 'Your Kyoshi Password Was Changed';
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
                    <div style='background:#FFF1F6;border-radius:14px;padding:14px 18px;margin:16px 0;font-weight:700;color:#C94F86;'>{$time}
                    </div>
                    <p style='color:#7B6178;'>If <strong>you made this change</strong>, no action is needed — you're all good!</p>
                    <div style='background:#FFF0F0;border:1px solid #FFCCCC;border-radius:14px;padding:16px 18px;margin:16px 0;'>
                        <p style='margin:0;color:#c0392b;font-weight:700;'>Wasn't you?</p>
                        <p style='margin:8px 0 0;color:#7B6178;font-size:13px;'>
                            If you didn't change your password, your account may be compromised.
                            Contact us immediately or reset your password right away.
                        </p>
                    </div>
                    <a href='http://kyoshitutor.site/php/forgotpassword.php'
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

// NEW FUNCTION: Send admin payment reminder email
function sendAdminPaymentReminder($toEmail, $toName, $pendingCount, $pendingPayments) {
    $mail = new PHPMailer(true);
    $today = date('d M Y, h:i A');

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

        $mail->setFrom('sohisabella87@gmail.com', 'Kyoshi System');
        $mail->addAddress($toEmail, $toName);

        $mail->isHTML(true);
        $mail->Subject = "Payment Verification Required - {$pendingCount} Pending Payment(s)";
        
        // Build payment table rows
        $paymentRows = '';
        foreach ($pendingPayments as $payment) {
            $paymentRows .= "
                <tr style='border-bottom: 1px solid #eee;'>
                    <td style='padding: 10px;'><strong>{$payment['student_name']}</strong></td>
                    <td style='padding: 10px;'>{$payment['tutor_name']}</td>
                    <td style='padding: 10px;'>{$payment['language']}</td>
                    <td style='padding: 10px;'><strong>RM " . number_format($payment['amount'], 2) . "</strong></td>
                    <td style='padding: 10px;'>" . date('d M Y', strtotime($payment['created_at'])) . "</td>
                </tr>
            ";
        }
        
        $mail->Body = "
            <div style='font-family:Segoe UI,sans-serif;max-width:600px;margin:auto;background:#FFF1F6;padding:30px;border-radius:24px;'>
                <div style='background:linear-gradient(135deg,#E75A9B,#F28AB2);padding:28px 32px;text-align:center;border-radius:16px;'>
                    <h1 style='color:white;margin:0;font-size:22px;'>Kyoshi</h1>
                    <p style='color:rgba(255,255,255,.85);margin:6px 0 0;font-size:13px;'>Payment Verification Required</p>
                </div>
                
                <div style='background:white;border-radius:16px;padding:32px;margin-top:16px;'>
                    <h2 style='color:#342635;margin:0 0 12px;'>Payment Verification Needed</h2>
                    
                    <p style='color:#7B6178;'>Dear <strong>{$toName}</strong>,</p>
                    
                    <div style='background:#FFF1F6;border-radius:14px;padding:18px;margin:16px 0;text-align:center;'>
                        <span style='font-size:36px;font-weight:900;color:#E75A9B;'>{$pendingCount}</span>
                        <p style='margin:5px 0 0;color:#7B6178;font-weight:700;'>payment(s) awaiting verification</p>
                    </div>
                    
                    <h3 style='color:#342635;margin:20px 0 15px;'>Pending Payments:</h3>
                    
                    <div style='overflow-x:auto;'>
                        <table style='width:100%;border-collapse:collapse;'>
                            <thead>
                                <tr style='background:#FFF1F6;'>
                                    <th style='padding:10px;text-align:left;'>Student</th>
                                    <th style='padding:10px;text-align:left;'>Tutor</th>
                                    <th style='padding:10px;text-align:left;'>Language</th>
                                    <th style='padding:10px;text-align:left;'>Amount</th>
                                    <th style='padding:10px;text-align:left;'>Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                {$paymentRows}
                            </tbody>
                        </table>
                    </div>
                    
                    <div style='margin:25px 0;text-align:center;'>
                        <a href='https://kyoshitutor.site/admin/manage_payments.php'
                           style='display:inline-block;background:linear-gradient(135deg,#E75A9B,#F28AB2);
                                  color:white;padding:14px 32px;border-radius:999px;text-decoration:none;
                                  font-weight:700;font-size:14px;'>
                            🔍 Verify Payments Now
                        </a>
                    </div>
                    
                    <div style='background:#FFF9E6;border:1px solid #FFE5B4;border-radius:14px;padding:16px 18px;margin:16px 0;'>
                        <p style='margin:0;color:#856404;font-weight:700;'>⏰ Action Required</p>
                        <p style='margin:8px 0 0;color:#7B6178;font-size:13px;'>
                            Please verify these payments as soon as possible to avoid delays in session confirmations.
                        </p>
                    </div>
                </div>
                
                <p style='text-align:center;margin-top:18px;font-size:11px;color:#aaa;'>
                    This is an automated reminder from Kyoshi. Please do not reply.<br>
                    You're receiving this because there are pending payments awaiting your verification.
                </p>
            </div>
        ";

        $mail->send();
        return true;

    } catch (Exception $e) {
        error_log("Admin payment reminder email failed: " . $mail->ErrorInfo);
        return false;
    }
}

// NEW FUNCTION: General email sender (for flexibility)
function sendGeneralEmail($toEmail, $toName, $subject, $htmlBody) {
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

        $mail->setFrom('sohisabella87@gmail.com', 'Kyoshi System');
        $mail->addAddress($toEmail, $toName);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $htmlBody;

        $mail->send();
        return true;

    } catch (Exception $e) {
        error_log("General email failed: " . $mail->ErrorInfo);
        return false;
    }
}

function sendAdminPaymentReminderUrgent($toEmail, $toName, $pendingCount, $urgentToday, $urgentTomorrow, $critical, $pendingPayments) {
    $mail = new PHPMailer(true);
    
    // Determine urgency level and subject
    if ($urgentToday > 0) {
        $subject = "🔴 URGENT: {$urgentToday} Payment(s) for TODAY's classes need verification!";
        $headerColor = "#dc3545";
        $headerEmoji = "🔴 URGENT ACTION REQUIRED";
    } elseif ($critical > 0) {
        $subject = "⚠️ CRITICAL: {$critical} Overdue Payment(s) - Classes at risk!";
        $headerColor = "#dc3545";
        $headerEmoji = "⚠️ CRITICAL - CLASSES TOMORROW!";
    } elseif ($urgentTomorrow > 0) {
        $subject = "🟠 Important: {$urgentTomorrow} Payment(s) for TOMORROW's classes pending";
        $headerColor = "#fd7e14";
        $headerEmoji = "🟠 IMPORTANT - ACTION NEEDED";
    } else {
        $subject = "🔔 Payment Verification Required - {$pendingCount} Pending Payment(s)";
        $headerColor = "#E75A9B";
        $headerEmoji = "🔔 Payment Verification Required";
    }

    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = 'tls';
        $mail->Port       = 587;
        $mail->CharSet    = 'UTF-8';       

        $mail->setFrom('sohisabella87@gmail.com', 'Kyoshi System');
        $mail->addAddress($toEmail, $toName);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        
        // Build payment table rows with urgency coloring
        $paymentRows = '';
        foreach ($pendingPayments as $payment) {
            $bookingDate = date('d M Y', strtotime($payment['booking_date']));
            $isToday = ($payment['booking_date'] == date('Y-m-d'));
            $isTomorrow = ($payment['booking_date'] == date('Y-m-d', strtotime('+1 day')));
            $isOverdue = ($payment['booking_date'] < date('Y-m-d'));
            
            $rowColor = '';
            $urgencyBadge = '';
            
            if ($isToday) {
                $rowColor = '#fff0f0';
                $urgencyBadge = '<span style="background:#dc3545;color:white;padding:3px 8px;border-radius:12px;font-size:10px;margin-left:8px;">⚠️ TODAY!</span>';
            } elseif ($isTomorrow) {
                $rowColor = '#fff5e6';
                $urgencyBadge = '<span style="background:#fd7e14;color:white;padding:3px 8px;border-radius:12px;font-size:10px;margin-left:8px;">⏰ TOMORROW!</span>';
            } elseif ($isOverdue) {
                $rowColor = '#ffe6e6';
                $urgencyBadge = '<span style="background:#8B0000;color:white;padding:3px 8px;border-radius:12px;font-size:10px;margin-left:8px;">❌ OVERDUE!</span>';
            }
            
            $paymentRows .= "
                <tr style='border-bottom: 1px solid #eee; background:{$rowColor};'>
                    <td style='padding: 10px;'><strong>" . htmlspecialchars($payment['student_name']) . "</strong></td>
                    <td style='padding: 10px;'>" . htmlspecialchars($payment['tutor_name']) . "</td>
                    <td style='padding: 10px;'>" . htmlspecialchars($payment['language']) . "</td>
                    <td style='padding: 10px;'><strong>RM " . number_format($payment['amount'], 2) . "</strong></td>
                    <td style='padding: 10px;'>{$bookingDate} " . date('h:i A', strtotime($payment['booking_time'])) . "{$urgencyBadge}</td>
                </tr>
            ";
        }
        
        $mail->Body = "
        <div style='font-family:Segoe UI,sans-serif;max-width:650px;margin:auto;background:#FFF1F6;padding:30px;border-radius:24px;'>
            <div style='background:linear-gradient(135deg,{$headerColor},{$headerColor});padding:28px 32px;text-align:center;border-radius:16px;'>
                <h1 style='color:white;margin:0;font-size:22px;'>{$headerEmoji}</h1>
                <p style='color:rgba(255,255,255,.9);margin:6px 0 0;font-size:13px;'>Action Required Immediately</p>
            </div>
            
            <div style='background:white;border-radius:16px;padding:32px;margin-top:16px;'>
                
                <!-- URGENCY SUMMARY BOX -->
                <div style='background:#f8f9fa;border-radius:14px;padding:18px;margin-bottom:20px;text-align:center;border:2px solid {$headerColor};'>
                    <p style='margin:0 0 5px;color:#666;font-size:12px;'>PENDING PAYMENTS SUMMARY</p>
                    <div style='display:flex;justify-content:space-around;margin-top:10px;'>
                        <div>
                            <span style='font-size:28px;font-weight:900;color:#dc3545;'>{$urgentToday}</span>
                            <p style='margin:0;font-size:11px;color:#dc3545;'>TODAY's Classes</p>
                        </div>
                        <div>
                            <span style='font-size:28px;font-weight:900;color:#fd7e14;'>{$urgentTomorrow}</span>
                            <p style='margin:0;font-size:11px;color:#fd7e14;'>TOMORROW's Classes</p>
                        </div>
                        <div>
                            <span style='font-size:28px;font-weight:900;color:#8B0000;'>{$critical}</span>
                            <p style='margin:0;font-size:11px;color:#8B0000;'>OVERDUE Classes</p>
                        </div>
                    </div>
                </div>
                
                <p style='color:#7B6178;'>Dear <strong>" . htmlspecialchars($toName) . "</strong>,</p>
                
                " . ($urgentToday > 0 ? "
                <div style='background:#dc3545;color:white;border-radius:14px;padding:15px;margin:15px 0;text-align:center;'>
                    <strong>🔴 URGENT: {$urgentToday} class(es) are scheduled for TODAY!</strong><br>
                    <span style='font-size:13px;'>Students cannot attend without payment verification.</span>
                </div>
                " : "") . "
                
                " . ($urgentTomorrow > 0 && $urgentToday == 0 ? "
                <div style='background:#fd7e14;color:white;border-radius:14px;padding:15px;margin:15px 0;text-align:center;'>
                    <strong>🟠 IMPORTANT: {$urgentTomorrow} class(es) scheduled for TOMORROW!</strong><br>
                    <span style='font-size:13px;'>Please verify before students go to bed tonight.</span>
                </div>
                " : "") . "
                
                <h3 style='color:#342635;margin:20px 0 15px;'>📋 Pending Payments Details:</h3>
                
                <div style='overflow-x:auto;'>
                    <table style='width:100%;border-collapse:collapse;font-size:13px;'>
                        <thead>
                            <tr style='background:#FFF1F6;'>
                                <th style='padding:10px;text-align:left;'>Student</th>
                                <th style='padding:10px;text-align:left;'>Tutor</th>
                                <th style='padding:10px;text-align:left;'>Language</th>
                                <th style='padding:10px;text-align:left;'>Amount</th>
                                <th style='padding:10px;text-align:left;'>Class Date & Time</th>
                            </tr>
                        </thead>
                        <tbody>
                            {$paymentRows}
                        </tbody>
                    </table>
                </div>
                
                <div style='margin:25px 0;text-align:center;'>
                    <a href='https://kyoshitutor.site/admin/manage_payments.php'
                       style='display:inline-block;background:linear-gradient(135deg,{$headerColor},{$headerColor});
                              color:white;padding:14px 32px;border-radius:999px;text-decoration:none;
                              font-weight:700;font-size:14px;'>
                        🔍 VERIFY PAYMENTS NOW
                    </a>
                </div>
                
                <div style='background:#FFF9E6;border:1px solid #FFE5B4;border-radius:14px;padding:16px 18px;margin:16px 0;'>
                    <p style='margin:0;color:#856404;font-weight:700;'>⏰ WHY THIS IS URGENT:</p>
                    <p style='margin:8px 0 0;color:#7B6178;font-size:13px;'>
                        • Students cannot join classes without payment verification<br>
                        • Tutors may cancel if payment is not confirmed<br>
                        • Delays cause poor user experience and potential refund requests
                    </p>
                </div>
            </div>
            
            <p style='text-align:center;margin-top:18px;font-size:11px;color:#aaa;'>
                This is an automated reminder from Kyoshi. Please verify payments immediately.<br>
                You're receiving this because there are pending payments awaiting your verification.
            </p>
        </div>
        ";

        $mail->send();
        return true;

    } catch (Exception $e) {
        error_log("Admin payment reminder email failed: " . $mail->ErrorInfo);
        return false;
    }
}