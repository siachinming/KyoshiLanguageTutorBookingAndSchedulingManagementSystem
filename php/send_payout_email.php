<?php
// send_payout_email.php
require_once 'config.php';
require_once '../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function sendPayoutNotification($conn, $payout_id, $status, $transaction_ref = null, $reason = null, $admin_id = null) {
    // Get payout details with admin who processed it
    $stmt = $conn->prepare("
        SELECT p.*, 
               u.email as tutor_email, 
               u.fullname as tutor_name,
               b.bank_name, 
               b.bank_account_number, 
               b.bank_account_name,
               a.id as admin_id,
               a.fullname as admin_name,
               a.email as admin_email,
               a.phone as admin_phone
        FROM payout_requests p
        JOIN users u ON p.tutor_id = u.id
        LEFT JOIN tutor_bank_details b ON p.bank_account_id = b.id
        LEFT JOIN users a ON p.processed_by = a.id
        WHERE p.id = ?
    ");
    $stmt->bind_param("i", $payout_id);
    $stmt->execute();
    $payout = $stmt->get_result()->fetch_assoc();
    
    if (!$payout) return false;
    
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
        $mail->addAddress($payout['tutor_email'], $payout['tutor_name']);
        $mail->isHTML(true);
        
        $amountFormatted = 'RM ' . number_format($payout['amount'], 2);
        
        // Mask bank account number (show only last 4 digits)
        $maskedAccount = !empty($payout['bank_account_number']) ? '****' . substr($payout['bank_account_number'], -4) : 'N/A';
        
        // Get admin contact info (the one who processed this payout)
        $adminName = $payout['admin_name'] ?? 'Admin Team';
        $adminEmail = $payout['admin_email'] ?? 'admin@kyoshi.com';
        $adminPhone = $payout['admin_phone'] ?? '+60 12-345 6789';
        
        if ($status === 'approved') {
            $mail->Subject = 'Payout Request Approved - Kyoshi';
            $mail->Body = "
            <div style='font-family:Segoe UI,sans-serif;max-width:550px;margin:auto;'>
                <div style='background:linear-gradient(135deg, #1d3156, #E75A9B);padding:30px;text-align:center;border-radius:16px 16px 0 0;color:white;'>
                    <h2>✅ Payout Approved!</h2>
                </div>
                <div style='background:#fff;padding:30px;border:1px solid #eef2f7;border-top:none;border-radius:0 0 16px 16px;'>
                    <p>Dear <strong>{$payout['tutor_name']}</strong>,</p>
                    <p>Your payout request of <strong>{$amountFormatted}</strong> has been <strong style='color:#28a745;'>APPROVED</strong> by <strong>{$adminName}</strong>.</p>
                    <div style='background:#f8fafc;padding:16px;border-radius:12px;margin:15px 0;'>
                        <p><strong>Transfer to Bank Account</strong></p>
                        <hr>
                        <p><strong>Bank:</strong> {$payout['bank_name']}</p>
                        <p><strong>Account Number:</strong> {$maskedAccount}</p>
                        <p><strong>Account Name:</strong> {$payout['bank_account_name']}</p>
                    </div>
                    <p>We will transfer the amount to your bank account within 3-5 business days.</p>
                    <p>You will receive another email once the transfer is completed.</p>
                    <div style='background:#fff3cd;padding:12px;border-radius:8px;margin-top:20px;border-left:4px solid #ffc107;'>
                        <p style='margin:0;font-size:13px;color:#856404;'>
                            <strong>⚠️ Important:</strong> Please check your bank account within 2 days after receiving the completion confirmation email. 
                            If you encounter any issues or the amount is not credited, please contact the admin who approved your request immediately within 2 days.
                        </p>
                        <div style='margin-top:12px;padding-top:10px;border-top:1px solid #ffe0a3;'>
                            <p style='margin:5px 0;'><strong>Contact Admin ({$adminName}):</strong></p>
                            <p style='margin:3px 0;'>• Phone/WhatsApp: <a href='tel:{$adminPhone}' style='color:#856404;'>{$adminPhone}</a></p>
                            <p style='margin:3px 0;'>• Email: <a href='mailto:{$adminEmail}' style='color:#856404;'>{$adminEmail}</a></p>
                            <p style='margin:3px 0;'>• Support Hours: Monday-Friday, 9:00 AM - 6:00 PM</p>
                        </div>
                    </div>
                    <hr>
                    <p style='font-size:12px;color:#666;'>Kyoshi Language Platform</p>
                </div>
            </div>
            ";
        } elseif ($status === 'completed') {
            // Generate PDF receipt and attach
            require_once 'generate_payout_receipt.php';
            $pdfContent = generatePayoutReceiptPDF($payout, $transaction_ref);
            
            $mail->Subject = 'Payout Completed - Kyoshi';
            $mail->Body = "
            <div style='font-family:Segoe UI,sans-serif;max-width:550px;margin:auto;'>
                <div style='background:linear-gradient(135deg, #28a745, #20c997);padding:30px;text-align:center;border-radius:16px 16px 0 0;color:white;'>
                    <h2>💰 Payout Completed!</h2>
                </div>
                <div style='background:#fff;padding:30px;border:1px solid #eef2f7;border-top:none;border-radius:0 0 16px 16px;'>
                    <div style='background:#d4edda;padding:16px;border-radius:12px;margin-bottom:20px;border-left:4px solid #28a745;'>
                        <p style='margin:0;color:#155724;'><strong>✓ SUCCESSFULLY TRANSFERRED</strong><br>The amount of <strong>{$amountFormatted}</strong> has been transferred to your bank account.</p>
                        <p style='margin:5px 0 0 0;font-size:12px;color:#155724;'>Processed by: <strong>{$adminName}</strong></p>
                    </div>
                    <p>Dear <strong>{$payout['tutor_name']}</strong>,</p>
                    <div style='background:#f8fafc;padding:16px;border-radius:12px;margin:15px 0;'>
                        <p><strong>Transferred to Bank Account:</strong></p>
                        <p><strong>Bank:</strong> {$payout['bank_name']}</p>
                        <p><strong>Account Number:</strong> {$maskedAccount}</p>
                        <p><strong>Account Name:</strong> {$payout['bank_account_name']}</p>
                        <p><strong>Amount Transferred:</strong> {$amountFormatted}</p>
                        " . ($transaction_ref ? "<p><strong>Transaction Ref:</strong> {$transaction_ref}</p>" : "") . "
                    </div>
                    <p>Please find your official payout receipt attached.</p>
                    <div style='background:#fff3cd;padding:12px;border-radius:8px;margin:20px 0;border-left:4px solid #ffc107;'>
                        <p style='margin:0;font-size:13px;color:#856404;'>
                            <strong>⚠️ Important Notice:</strong><br>
                            • Please check your bank account within the next 2 days<br>
                            • If the amount is not credited or you experience any issues, <strong>please contact the admin who processed your payout within 2 days</strong><br>
                            • For any errors or discrepancies, reach out to {$adminName} immediately<br>
                            • Delayed reports beyond 2 days may require additional verification
                        </p>
                        <div style='margin-top:12px;padding-top:10px;border-top:1px solid #ffe0a3;'>
                            <p style='margin:5px 0;'><strong>📞 Contact Admin ({$adminName}):</strong></p>
                            <p style='margin:3px 0;'>• Phone/WhatsApp: <a href='tel:{$adminPhone}' style='color:#856404;'>{$adminPhone}</a></p>
                            <p style='margin:3px 0;'>• Email: <a href='mailto:{$adminEmail}' style='color:#856404;'>{$adminEmail}</a></p>
                            <p style='margin:3px 0;'>• Response Time: Within 24 hours</p>
                        </div>
                    </div>
                    <p>Thank you for teaching with Kyoshi! 🎉</p>
                    <hr>
                    <p style='font-size:12px;color:#666;'>Kyoshi Language Platform</p>
                </div>
            </div>
            ";
            $mail->addStringAttachment($pdfContent, 'Kyoshi_Payout_Receipt_PO-' . str_pad($payout['id'], 8, '0', STR_PAD_LEFT) . '.pdf');
        } else {
            $mail->Subject = 'Payout Request Declined - Kyoshi';
            $mail->Body = "
            <div style='font-family:Segoe UI,sans-serif;max-width:550px;margin:auto;'>
                <div style='background:#dc2626;padding:30px;text-align:center;border-radius:16px 16px 0 0;color:white;'>
                    <h2>❌ Payout Request Declined</h2>
                </div>
                <div style='background:#fff;padding:30px;border:1px solid #eef2f7;border-top:none;border-radius:0 0 16px 16px;'>
                    <p>Dear <strong>{$payout['tutor_name']}</strong>,</p>
                    <p>Your payout request of <strong>{$amountFormatted}</strong> has been <strong style='color:#dc2626;'>REJECTED</strong> by <strong>{$adminName}</strong>.</p>
                    <div style='background:#fef2f2;padding:15px;border-radius:12px;margin:15px 0;border-left:4px solid #dc2626;'>
                        <strong>Reason:</strong><br>{$reason}
                    </div>
                    <p>If you have any questions about this decision, please contact:</p>
                    <div style='background:#f8fafc;padding:12px;border-radius:8px;margin:10px 0;'>
                        <p style='margin:3px 0;'><strong>{$adminName}</strong></p>
                        <p style='margin:3px 0;'>Email: <a href='mailto:{$adminEmail}' style='color:#dc2626;'>{$adminEmail}</a></p>
                        <p style='margin:3px 0;'>Phone: {$adminPhone}</p>
                    </div>
                    <hr>
                    <p style='font-size:12px;color:#666;'>Kyoshi Language Platform</p>
                </div>
            </div>
            ";
        }
        
        return $mail->send();
    } catch (Exception $e) {
        error_log("Payout email failed: " . $mail->ErrorInfo);
        return false;
    }
}
?>