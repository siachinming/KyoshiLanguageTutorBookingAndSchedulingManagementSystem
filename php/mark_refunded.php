<?php
session_start();
include 'config.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require '../vendor/autoload.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$data = json_decode(file_get_contents('php://input'), true);
$payment_id = $data['payment_id'] ?? 0;
$refund_amount = $data['refund_amount'] ?? 0;
$refund_receipt = $data['refund_receipt'] ?? '';

if (!$payment_id) {
    echo json_encode(['success' => false, 'message' => 'Invalid payment ID']);
    exit();
}

// Get payment details
$query = $conn->prepare("
    SELECT p.*, 
           b.booking_date, b.booking_time,
           s.fullname as student_name, s.email as student_email,
           t.fullname as tutor_name,
           b.language, b.learning_mode
    FROM payments p
    LEFT JOIN bookings b ON p.booking_id = b.id
    LEFT JOIN users s ON p.student_id = s.id
    LEFT JOIN users t ON p.tutor_id = t.id
    WHERE p.id = ?
");
$query->bind_param("i", $payment_id);
$query->execute();
$payment = $query->get_result()->fetch_assoc();

if (!$payment) {
    echo json_encode(['success' => false, 'message' => 'Payment not found']);
    exit();
}

// If refund_amount not passed, calculate it
if ($refund_amount <= 0) {
    $actual_paid = $payment['actual_paid_amount'] ?? $payment['amount'];
    $expected = $payment['amount'];
    $refund_amount = $actual_paid - $expected;
}

// Generate refund receipt number if not provided
if (empty($refund_receipt)) {
    $refund_receipt = 'RFD-' . date('Ymd') . '-' . str_pad($payment_id, 6, '0', STR_PAD_LEFT);
}

// Update payment with refund status
$update = $conn->prepare("
    UPDATE payments 
    SET refund_status = 'completed',
        refund_receipt_number = ?,
        refund_processed_at = NOW(),
        notes = CONCAT(IFNULL(notes, ''), '\n[REFUNDED: RM " . number_format($refund_amount, 2) . " on " . date('Y-m-d H:i:s') . "] Refund receipt: ', ?)
    WHERE id = ? AND refund_status = 'pending'
");
$update->bind_param("ssi", $refund_receipt, $refund_receipt, $payment_id);
$update->execute();

if ($update->affected_rows == 0) {
    echo json_encode(['success' => false, 'message' => 'Refund already processed or payment not found']);
    exit();
}

// Send refund email to student
sendRefundEmail($payment, $refund_amount, $refund_receipt);

echo json_encode([
    'success' => true, 
    'message' => 'Refund of RM ' . number_format($refund_amount, 2) . ' processed successfully',
    'refund_amount' => $refund_amount,
    'refund_receipt' => $refund_receipt
]);
exit();

// ============================================
// EMAIL FUNCTION
// ============================================

function sendRefundEmail($payment, $refund_amount, $refund_receipt_number) {
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
        $mail->addAddress($payment['student_email'], $payment['student_name']);
        
        $mail->isHTML(true);
        $mail->Subject = 'Refund Processed - Kyoshi';
        
        $mail->Body = "
        <div style='font-family:Segoe UI,sans-serif;max-width:550px;margin:auto;border:1px solid #e0e0e0;border-radius:16px;padding:24px;background:#fff;'>
            <div style='text-align:center;margin-bottom:24px;'>
                <div style='background:#059669;width:60px;height:60px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;margin-bottom:16px;'>
                    <span style='font-size:32px;color:white;'>✓</span>
                </div>
                <h2 style='color:#059669;margin:0;'>Refund Processed</h2>
            </div>
            
            <p>Dear <strong>" . htmlspecialchars($payment['student_name']) . "</strong>,</p>
            
            <p>Your refund for the overpaid amount has been <strong style='color:#059669;'>successfully processed</strong>.</p>
            
            <div style='background:#f0fdf4;padding:16px;border-radius:12px;margin:16px 0;border:1px solid #86efac;'>
                <p style='margin:0 0 8px 0;'><strong>Refund Details:</strong></p>
                <p style='margin:4px 0;'><strong>Refund Receipt:</strong> " . htmlspecialchars($refund_receipt_number) . "</p>
                <p style='margin:4px 0;'><strong>Refund Amount:</strong> <span style='color:#059669;font-size:18px;font-weight:bold;'>RM " . number_format($refund_amount, 2) . "</span></p>
                <p style='margin:4px 0;'><strong>Payment Method:</strong> " . ucfirst(str_replace('_', ' ', $payment['payment_method'])) . "</p>
                <p style='margin:4px 0;'><strong>Refund Date:</strong> " . date('d M Y, h:i A') . "</p>
            </div>
            
            <div style='background:#f8f9fa;padding:16px;border-radius:12px;margin:16px 0;'>
                <p style='margin:0 0 8px 0;'><strong>Original Payment Details:</strong></p>
                <p style='margin:4px 0;'><strong>Booking:</strong> " . htmlspecialchars($payment['language']) . " with " . htmlspecialchars($payment['tutor_name']) . "</p>
                <p style='margin:4px 0;'><strong>Session Date:</strong> " . date('d M Y, h:i A', strtotime($payment['booking_date'] . ' ' . $payment['booking_time'])) . "</p>
                <p style='margin:4px 0;'><strong>You Paid:</strong> RM " . number_format($payment['actual_paid_amount'] ?? $payment['amount'], 2) . "</p>
                <p style='margin:4px 0;'><strong>Correct Amount:</strong> RM " . number_format($payment['amount'], 2) . "</p>
                <p style='margin:4px 0;'><strong>Overpaid:</strong> <span style='color:#dc2626;'>RM " . number_format($refund_amount, 2) . "</span></p>
            </div>
            
            <div style='background:#e0f2fe;padding:12px;border-radius:8px;margin:16px 0;'>
                <p style='margin:0;font-size:13px;'><i class='bi bi-info-circle'></i> The refund amount will be credited back to your original payment method within 3-5 business days.</p>
            </div>
            
            <div style='text-align:center;margin-top:24px;'>
                <a href='http://localhost/kyoshi/php/receipt_refund.php?refund_id=" . $refund_receipt_number . "&payment_id=" . $payment['id'] . "' 
                   style='display:inline-block;padding:10px 24px;background:#E75A9B;color:white;border-radius:30px;text-decoration:none;font-weight:bold;margin-right:10px;'>
                     📄 Download Refund Receipt
                </a>
                <a href='http://localhost/kyoshi/php/my_payments.php' 
                   style='display:inline-block;padding:10px 24px;background:#64748b;color:white;border-radius:30px;text-decoration:none;font-weight:bold;'>
                    View My Payments
                </a>
            </div>
            
            <hr style='margin:24px 0 16px;'>
            <p style='font-size:12px;color:#666;text-align:center;'>This is an automated message from Kyoshi. Please keep this refund receipt for your records.</p>
        </div>
        ";
        
        $mail->send();
        return true;
        
    } catch (Exception $e) {
        error_log("Refund email failed: " . $mail->ErrorInfo);
        return false;
    }
}
?>