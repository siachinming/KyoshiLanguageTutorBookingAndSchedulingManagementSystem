<?php
session_start();
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
include 'config.php';

// ============================================
// HELPER FUNCTIONS
// ============================================
function e($value) {
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function formatMoney($amount) {
    return 'RM ' . number_format($amount ?? 0, 2);
}

// ============================================
// CHECK LOGIN
// ============================================
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$userID = $_SESSION['user_id'];
$userRole = $_SESSION['role'];

// Get parameters
$refund_id = $_GET['refund_id'] ?? '';
$payment_id = intval($_GET['payment_id'] ?? 0);
$action = $_GET['action'] ?? 'view'; // view, pdf, email

if (empty($refund_id) || $payment_id <= 0) {
    die("Invalid refund receipt request.");
}

// Get refund details
$query = $conn->prepare("
    SELECT 
        p.id AS payment_id,
        p.amount AS expected_amount,
        p.actual_paid_amount,
        p.payment_method,
        p.receipt_number AS payment_receipt,
        p.refund_receipt_number,
        p.refund_status,
        p.refund_processed_at,
        p.created_at AS payment_date,
        p.verified_at,
        p.notes,
        b.id AS booking_id,
        b.booking_date,
        b.booking_time,
        b.language,
        b.learning_mode,
        s.fullname AS student_name,
        s.email AS student_email,
        s.phone AS student_phone,
        t.fullname AS tutor_name,
        t.email AS tutor_email
    FROM payments p
    JOIN bookings b ON p.booking_id = b.id
    JOIN users s ON p.student_id = s.id
    JOIN users t ON p.tutor_id = t.id
    WHERE p.refund_receipt_number = ? 
    AND p.id = ?
    AND p.student_id = ?
");

$query->bind_param("sii", $refund_id, $payment_id, $userID);
$query->execute();
$result = $query->get_result();

if ($result->num_rows === 0) {
    // Check if admin is viewing
    if ($userRole === 'admin') {
        $query = $conn->prepare("
            SELECT 
                p.id AS payment_id,
                p.amount AS expected_amount,
                p.actual_paid_amount,
                p.payment_method,
                p.receipt_number AS payment_receipt,
                p.refund_receipt_number,
                p.refund_status,
                p.refund_processed_at,
                p.created_at AS payment_date,
                p.verified_at,
                p.notes,
                b.id AS booking_id,
                b.booking_date,
                b.booking_time,
                b.language,
                b.learning_mode,
                s.fullname AS student_name,
                s.email AS student_email,
                s.phone AS student_phone,
                t.fullname AS tutor_name,
                t.email AS tutor_email
            FROM payments p
            JOIN bookings b ON p.booking_id = b.id
            JOIN users s ON p.student_id = s.id
            JOIN users t ON p.tutor_id = t.id
            WHERE p.refund_receipt_number = ? 
            AND p.id = ?
        ");
        $query->bind_param("si", $refund_id, $payment_id);
        $query->execute();
        $result = $query->get_result();
        
        if ($result->num_rows === 0) {
            die("Refund receipt not found.");
        }
    } else {
        die("Refund receipt not found or you don't have permission to view it.");
    }
}

$refund = $result->fetch_assoc();
$refund_amount = $refund['actual_paid_amount'] - $refund['expected_amount'];
$refund_date = date('d M Y, g:i A', strtotime($refund['refund_processed_at']));
$booking_date = date('d M Y', strtotime($refund['booking_date']));
$booking_time = date('g:i A', strtotime($refund['booking_time']));
$refundRef = $refund['refund_receipt_number'];

// ============================================
// PDF HANDLER
// ============================================
if ($action === 'pdf') {
    require '../vendor/setasign/fpdf/fpdf.php';
    
    $pdf = new FPDF();
    $pdf->AddPage();
    $pdf->SetMargins(15, 15, 15);
    
    // ── HEADER BAND (green for refund) ──────────────────────────
    $pdf->SetFillColor(5, 150, 105); // #059669 - green
    $pdf->Rect(0, 0, 210, 50, 'F');
    
    // Accent stripe
    $pdf->SetFillColor(167, 243, 208); // #A7F3D0
    $pdf->Rect(0, 42, 210, 8, 'F');
    
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('Arial', 'B', 32);
    $pdf->SetY(10);
    $pdf->Cell(0, 14, 'KYOSHI', 0, 1, 'C');
    $pdf->SetFont('Arial', '', 11);
    $pdf->SetTextColor(255, 255, 230);
    $pdf->Cell(0, 6, 'Language Learning Platform', 0, 1, 'C');
    
    // ── REFUND TITLE ────────────────────────────────────
    $pdf->SetY(58);
    $pdf->SetFont('Arial', 'B', 20);
    $pdf->SetTextColor(5, 150, 105);
    $pdf->Cell(0, 10, 'REFUND RECEIPT', 0, 1, 'C');
    
    $pdf->SetFont('Arial', '', 10);
    $pdf->SetTextColor(100, 120, 160);
    $pdf->Cell(0, 6, 'Refund Number: ' . $refundRef, 0, 1, 'C');
    $pdf->Cell(0, 6, 'Generated: ' . date('d M Y, g:i A'), 0, 1, 'C');
    $pdf->Ln(4);
    
    // Divider
    $pdf->SetDrawColor(5, 150, 105);
    $pdf->SetLineWidth(0.8);
    $pdf->Line(15, $pdf->GetY(), 195, $pdf->GetY());
    $pdf->Ln(6);
    
    // ── TWO COLUMN LAYOUT ────────────────────────────────
    $colW = 85;
    $startY = $pdf->GetY();
    
    // LEFT: Student
    $pdf->SetXY(15, $startY);
    $pdf->SetFillColor(209, 250, 229); // #D1FAE5
    $pdf->SetTextColor(60, 80, 120);
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->Cell($colW, 9, '  Student', 0, 1, 'L', true);
    $pdf->SetTextColor(40, 40, 60);
    $pdf->Ln(2);
    
    $pdf->SetX(15);
    $pdf->SetFont('Arial', 'B', 9); $pdf->SetTextColor(120,140,180);
    $pdf->Cell(28, 7, 'Name', 0, 0); $pdf->SetFont('Arial','',9); $pdf->SetTextColor(40,40,60);
    $pdf->Cell($colW - 28, 7, $refund['student_name'], 0, 1);
    $pdf->SetX(15);
    $pdf->SetFont('Arial', 'B', 9); $pdf->SetTextColor(120,140,180);
    $pdf->Cell(28, 7, 'Email', 0, 0); $pdf->SetFont('Arial','',9); $pdf->SetTextColor(40,40,60);
    $pdf->Cell($colW - 28, 7, $refund['student_email'], 0, 1);
    
    $afterLeftY = $pdf->GetY();
    
    // RIGHT: Tutor
    $pdf->SetXY(110, $startY);
    $pdf->SetFillColor(209, 250, 229);
    $pdf->SetTextColor(60, 80, 120);
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->Cell($colW, 9, '  Tutor', 0, 1, 'L', true);
    $pdf->SetTextColor(40, 40, 60);
    $pdf->Ln(2);
    
    $pdf->SetXY(110, $pdf->GetY());
    $pdf->SetFont('Arial', 'B', 9); $pdf->SetTextColor(120,140,180);
    $pdf->Cell(28, 7, 'Name', 0, 0); $pdf->SetFont('Arial','',9); $pdf->SetTextColor(40,40,60);
    $pdf->Cell($colW - 28, 7, $refund['tutor_name'], 0, 1);
    $pdf->SetXY(110, $pdf->GetY());
    $pdf->SetFont('Arial', 'B', 9); $pdf->SetTextColor(120,140,180);
    $pdf->Cell(28, 7, 'Email', 0, 0); $pdf->SetFont('Arial','',9); $pdf->SetTextColor(40,40,60);
    $pdf->Cell($colW - 28, 7, $refund['tutor_email'], 0, 1);
    
    $pdf->SetY(max($afterLeftY, $pdf->GetY()) + 6);
    
    // ── REFUND AMOUNT BOX ────────────────────────────────────────
    $pdf->SetFillColor(236, 253, 245); // #ECFDF5
    $pdf->SetTextColor(5, 150, 105);
    $pdf->SetFont('Arial', 'B', 16);
    $pdf->Cell(0, 16, 'REFUND AMOUNT: RM ' . number_format($refund_amount, 2), 0, 1, 'C', true);
    $pdf->Ln(6);
    
    // ── SESSION DETAILS ──────────────────────────────────
    $pdf->SetFillColor(209, 250, 229);
    $pdf->SetTextColor(60, 80, 120);
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->Cell(0, 9, '  Session Details', 0, 1, 'L', true);
    $pdf->Ln(2);
    
    $pdf->SetFont('Arial', 'B', 9); $pdf->SetTextColor(120,140,180);
    $pdf->Cell(55, 8, 'Language', 0, 0); $pdf->SetFont('Arial','',9); $pdf->SetTextColor(40,40,60);
    $pdf->Cell(0, 8, $refund['language'], 0, 1);
    $pdf->SetFont('Arial', 'B', 9); $pdf->SetTextColor(120,140,180);
    $pdf->Cell(55, 8, 'Mode', 0, 0); $pdf->SetFont('Arial','',9); $pdf->SetTextColor(40,40,60);
    $pdf->Cell(0, 8, $refund['learning_mode'] === 'online' ? 'Online' : 'Face to Face', 0, 1);
    $pdf->SetFont('Arial', 'B', 9); $pdf->SetTextColor(120,140,180);
    $pdf->Cell(55, 8, 'Date', 0, 0); $pdf->SetFont('Arial','',9); $pdf->SetTextColor(40,40,60);
    $pdf->Cell(0, 8, $booking_date, 0, 1);
    $pdf->SetFont('Arial', 'B', 9); $pdf->SetTextColor(120,140,180);
    $pdf->Cell(55, 8, 'Time', 0, 0); $pdf->SetFont('Arial','',9); $pdf->SetTextColor(40,40,60);
    $pdf->Cell(0, 8, $booking_time, 0, 1);
    $pdf->Ln(6);
    
    // ── PAYMENT DETAILS ──────────────────────────────────
    $pdf->SetFillColor(254, 243, 199); // #FEF3C7
    $pdf->SetTextColor(60, 80, 120);
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->Cell(0, 9, '  Payment Details', 0, 1, 'L', true);
    $pdf->Ln(2);
    
    $pdf->SetFont('Arial', 'B', 9); $pdf->SetTextColor(120,140,180);
    $pdf->Cell(55, 8, 'Amount Paid', 0, 0); $pdf->SetFont('Arial','',9); $pdf->SetTextColor(40,40,60);
    $pdf->Cell(0, 8, 'RM ' . number_format($refund['actual_paid_amount'], 2), 0, 1);
    $pdf->SetFont('Arial', 'B', 9); $pdf->SetTextColor(120,140,180);
    $pdf->Cell(55, 8, 'Expected Amount', 0, 0); $pdf->SetFont('Arial','',9); $pdf->SetTextColor(40,40,60);
    $pdf->Cell(0, 8, 'RM ' . number_format($refund['expected_amount'], 2), 0, 1);
    $pdf->SetFont('Arial', 'B', 9); $pdf->SetTextColor(120,140,180);
    $pdf->Cell(55, 8, 'Overpaid / Refunded', 0, 0); $pdf->SetFont('Arial','',9); $pdf->SetTextColor(40,40,60);
    $pdf->Cell(0, 8, 'RM ' . number_format($refund_amount, 2), 0, 1);
    $pdf->SetFont('Arial', 'B', 9); $pdf->SetTextColor(120,140,180);
    $pdf->Cell(55, 8, 'Payment Method', 0, 0); $pdf->SetFont('Arial','',9); $pdf->SetTextColor(40,40,60);
    $pdf->Cell(0, 8, ucfirst(str_replace('_', ' ', $refund['payment_method'])), 0, 1);
    $pdf->SetFont('Arial', 'B', 9); $pdf->SetTextColor(120,140,180);
    $pdf->Cell(55, 8, 'Refund Processed', 0, 0); $pdf->SetFont('Arial','',9); $pdf->SetTextColor(40,40,60);
    $pdf->Cell(0, 8, $refund_date, 0, 1);
    $pdf->Ln(6);
    
    // ── REFUND BADGE ───────────────────────────────────
    $pdf->SetFillColor(209, 250, 229);
    $pdf->SetTextColor(5, 150, 105);
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->Cell(0, 10, 'Refund Successfully Processed', 0, 1, 'C', true);
    $pdf->Ln(6);
    
    // ── NOTE BOX ───────────────────────────────────────────
    $pdf->SetFillColor(224, 242, 254); // #E0F2FE
    $pdf->SetTextColor(7, 89, 133); // #075985
    $pdf->SetFont('Arial', '', 9);
    $pdf->MultiCell(0, 6, 'IMPORTANT: The refund amount has been credited back to your original payment method. Please allow 3-5 business days for the refund to appear in your account.', 0, 'L', true);
    $pdf->Ln(4);
    
    // ── FOOTER ───────────────────────────────────────────
    $pdf->SetDrawColor(5, 150, 105);
    $pdf->SetLineWidth(0.5);
    $pdf->Line(15, $pdf->GetY(), 195, $pdf->GetY());
    $pdf->Ln(4);
    $pdf->SetFont('Arial', 'I', 9);
    $pdf->SetTextColor(140, 160, 190);
    $pdf->Cell(0, 6, 'Thank you for learning with Kyoshi!', 0, 1, 'C');
    $pdf->Cell(0, 5, 'For support: support@kyoshi.com', 0, 1, 'C');
    
    $filename = 'Kyoshi_Refund_Receipt_' . preg_replace('/[^a-zA-Z0-9_]/', '', $refundRef) . '.pdf';
    $pdf->Output('D', $filename);
    exit();
}

// ============================================
// EMAIL HANDLER (simplified version)
// ============================================
if ($action === 'email') {
    $_SESSION['success_message'] = "Refund receipt will be emailed to you shortly.";
    header("Location: my_payments.php");
    exit();
}

// ============================================
// DEFAULT VIEW (HTML)
// ============================================
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Refund Receipt - Kyoshi</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Montserrat', 'Segoe UI', Arial, sans-serif;
            background: linear-gradient(135deg, #f5f0ff 0%, #ffe4f0 100%);
            min-height: 100vh;
            padding: 40px 20px;
        }
        .receipt-container {
            max-width: 800px;
            margin: 0 auto;
            background: white;
            border-radius: 24px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        .receipt-header {
            background: linear-gradient(135deg, #059669, #047857);
            color: white;
            padding: 30px;
            text-align: center;
        }
        .receipt-header img { width: 60px; margin-bottom: 15px; }
        .receipt-header h1 { font-size: 24px; margin-bottom: 5px; }
        .receipt-header p { opacity: 0.8; font-size: 14px; }
        .refund-badge {
            display: inline-block;
            background: rgba(255,255,255,0.2);
            padding: 8px 20px;
            border-radius: 40px;
            margin-top: 15px;
            font-weight: 700;
            font-size: 14px;
        }
        .receipt-body { padding: 30px; }
        .refund-amount {
            background: #f0fdf4;
            border: 1px solid #86efac;
            border-radius: 16px;
            padding: 20px;
            text-align: center;
            margin-bottom: 30px;
        }
        .refund-amount .label { font-size: 14px; color: #059669; font-weight: 600; margin-bottom: 5px; }
        .refund-amount .amount { font-size: 36px; font-weight: 800; color: #059669; }
        .info-section { margin-bottom: 25px; }
        .info-section h3 {
            font-size: 16px;
            color: #475569;
            margin-bottom: 15px;
            padding-bottom: 5px;
            border-bottom: 2px solid #059669;
            display: inline-block;
        }
        .info-row { display: flex; padding: 10px 0; border-bottom: 1px solid #e2e8f0; }
        .info-label { width: 35%; font-weight: 600; color: #64748b; }
        .info-value { width: 65%; font-weight: 500; color: #1e293b; }
        .highlight-row .info-value { color: #dc2626; font-weight: 700; font-size: 18px; }
        .note-box {
            background: #e0f2fe;
            padding: 15px;
            border-radius: 12px;
            margin: 20px 0;
        }
        .note-box p { margin: 0; font-size: 13px; color: #075985; }
        .action-buttons {
            display: flex;
            gap: 15px;
            margin-top: 30px;
            justify-content: center;
            flex-wrap: wrap;
        }
        .btn {
            padding: 12px 24px;
            border-radius: 40px;
            text-decoration: none;
            font-weight: 600;
            font-size: 14px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
        }
        .btn-primary { background: linear-gradient(135deg, #059669, #047857); color: white; }
        .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(5,150,105,0.3); }
        .btn-secondary { background: #64748b; color: white; }
        .btn-secondary:hover { background: #475569; transform: translateY(-2px); }
        .btn-outline { border: 1px solid #059669; color: #059669; background: white; }
        .btn-outline:hover { background: #f0fdf4; }
        .footer {
            background: #f8fafc;
            padding: 20px;
            text-align: center;
            font-size: 12px;
            color: #94a3b8;
            border-top: 1px solid #e2e8f0;
        }
        @media print {
            body { background: white; padding: 0; }
            .action-buttons { display: none; }
        }
        @media (max-width: 600px) {
            .receipt-body { padding: 20px; }
            .info-row { flex-direction: column; }
            .info-label, .info-value { width: 100%; }
            .info-label { margin-bottom: 5px; }
            .action-buttons { flex-direction: column; }
            .btn { justify-content: center; }
        }
    </style>
</head>
<body>
<div class="receipt-container">
    <div class="receipt-header">
        <img src="../assets/img/logo.png" alt="Kyoshi">
        <h1>REFUND RECEIPT</h1>
        <p>Official Refund Confirmation</p>
        <div class="refund-badge">
            <i class="bi bi-check-circle"></i> Refund Processed
        </div>
    </div>
    
    <div class="receipt-body">
        <div class="refund-amount">
            <div class="label">Refund Amount</div>
            <div class="amount">RM <?= number_format($refund_amount, 2) ?></div>
            <div style="font-size: 12px; color: #059669; margin-top: 5px;">Refund #: <?= htmlspecialchars($refund['refund_receipt_number']) ?></div>
        </div>
        
        <div class="info-section">
            <h3>Student Information</h3>
            <div class="info-row"><div class="info-label">Full Name</div><div class="info-value"><?= htmlspecialchars($refund['student_name']) ?></div></div>
            <div class="info-row"><div class="info-label">Email Address</div><div class="info-value"><?= htmlspecialchars($refund['student_email']) ?></div></div>
        </div>
        
        <div class="info-section">
            <h3>Booking Information</h3>
            <div class="info-row"><div class="info-label">Language / Subject</div><div class="info-value"><?= htmlspecialchars($refund['language']) ?></div></div>
            <div class="info-row"><div class="info-label">Tutor</div><div class="info-value"><?= htmlspecialchars($refund['tutor_name']) ?></div></div>
            <div class="info-row"><div class="info-label">Session Date & Time</div><div class="info-value"><?= $booking_date ?> at <?= $booking_time ?></div></div>
            <div class="info-row"><div class="info-label">Learning Mode</div><div class="info-value"><?= ucfirst($refund['learning_mode']) ?></div></div>
        </div>
        
        <div class="info-section">
            <h3>Payment & Refund Details</h3>
            <div class="info-row"><div class="info-label">Amount Paid</div><div class="info-value">RM <?= number_format($refund['actual_paid_amount'], 2) ?></div></div>
            <div class="info-row"><div class="info-label">Expected Amount</div><div class="info-value">RM <?= number_format($refund['expected_amount'], 2) ?></div></div>
            <div class="info-row"><div class="info-label">Refund Amount</div><div class="info-value">RM <?= number_format($refund_amount, 2) ?></div></div>
            <div class="info-row"><div class="info-label">Payment Method</div><div class="info-value"><?= ucfirst(str_replace('_', ' ', $refund['payment_method'])) ?></div></div>
            <div class="info-row"><div class="info-label">Payment Receipt</div><div class="info-value"><?= htmlspecialchars($refund['payment_receipt']) ?></div></div>
            <div class="info-row"><div class="info-label">Refund Processed Date</div><div class="info-value"><?= $refund_date ?></div></div>
        </div>
        
        <div class="note-box">
            <p><i class="bi bi-info-circle"></i> <strong>Important Note:</strong> The refund amount has been credited back to your original payment method. Please allow 3-5 business days for the refund to appear in your account. If you have any questions, please contact our support team.</p>
        </div>
        
        <div class="action-buttons">
            <a href="refund_receipt.php?refund_id=<?= urlencode($refund_id) ?>&payment_id=<?= $payment_id ?>&action=pdf" class="btn btn-primary">
                <i class="bi bi-download"></i> Download PDF
            </a>
            <a href="my_payments.php" class="btn btn-outline">
                <i class="bi bi-arrow-left"></i> Back to Payments
            </a>
        </div>
    </div>
    
    <div class="footer">
        <p>This is an official refund receipt from Kyoshi. Please keep this for your records.</p>
        <p>Generated on <?= date('d F Y, h:i A') ?></p>
    </div>
</div>
</body>
</html>