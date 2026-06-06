<?php

require_once '../vendor/setasign/fpdf/fpdf.php';

function generatePayoutReceiptPDF($payout, $transaction_ref = null) {
    $pdf = new FPDF('P', 'mm', 'A4');
    $pdf->AddPage();
    $pdf->SetMargins(20, 20, 20);
    $pdf->SetAutoPageBreak(true, 20);

    // ============================================================
    // HEADER SECTION
    // ============================================================
    $pdf->SetFillColor(29, 49, 86);
    $pdf->Rect(0, 0, 210, 45, 'F');
    $pdf->SetFillColor(231, 90, 155);
    $pdf->Rect(0, 45, 210, 6, 'F');

    // Logo
    $logoPaths = [
        '../assets/img/logo.png',
        '../assets/img/logo.jpg',
        '../img/logo.png',
        '../../assets/img/logo.png',
        '../assets/img/logo.webp'
    ];
    
    $logoPath = null;
    foreach ($logoPaths as $path) {
        if (file_exists($path)) {
            $logoPath = $path;
            break;
        }
    }
    
    if ($logoPath) {
        $pdf->Image($logoPath, 20, 8, 20);
        $textX = 46;
    } else {
        $textX = 20;
    }

    // Company Name
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('Arial', 'B', 20);
    $pdf->SetXY($textX, 10);
    $pdf->Cell(0, 10, 'KYOSHI', 0, 1, 'L');
    $pdf->SetFont('Arial', '', 8);
    $pdf->SetTextColor(200, 200, 230);
    $pdf->SetX($textX);
    $pdf->Cell(0, 4, 'Language Learning Platform', 0, 1, 'L');

    // Receipt badge
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetXY(140, 12);
    $pdf->SetFillColor(231, 90, 155);
    $pdf->Rect(140, 10, 50, 20, 'F');
    $pdf->SetXY(142, 14);
    $pdf->Cell(0, 5, 'PAYOUT', 0, 1, 'L');
    $pdf->SetXY(142, 20);
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(0, 7, 'RECEIPT', 0, 1, 'L');

    // ============================================================
    // SUCCESS BANNER
    // ============================================================
    $pdf->SetY(58);
    $pdf->SetFillColor(212, 237, 218);
    $pdf->SetDrawColor(40, 167, 69);
    $pdf->SetLineWidth(0.5);
    $pdf->Rect(20, $pdf->GetY(), 170, 22, 'DF');
    
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->SetTextColor(40, 167, 69);
    $pdf->SetXY(30, 63);
    $pdf->Cell(0, 6, 'PAYMENT SUCCESSFULLY TRANSFERRED', 0, 1, 'L');
    
    $pdf->SetFont('Arial', '', 8);
    $pdf->SetTextColor(21, 87, 36);
    $pdf->SetXY(30, 71);
    $pdf->Cell(0, 4, 'The amount has been credited to the tutor\'s registered bank account.', 0, 1, 'L');
    
    $pdf->SetY(88);

    // ============================================================
    // TITLE
    // ============================================================
    $pdf->SetFont('Arial', 'B', 16);
    $pdf->SetTextColor(29, 49, 86);
    $pdf->Cell(0, 8, 'PAYOUT CONFIRMATION', 0, 1, 'C');
    
    $pdf->SetFont('Arial', '', 8);
    $pdf->SetTextColor(100, 120, 160);
    $pdf->Cell(0, 5, 'Transaction ID: PO-' . str_pad($payout['id'], 8, '0', STR_PAD_LEFT), 0, 1, 'C');
    
    $completedDate = isset($payout['completed_at']) && $payout['completed_at'] ? $payout['completed_at'] : ($payout['processed_at'] ?? date('Y-m-d H:i:s'));
    $pdf->Cell(0, 4, 'Date: ' . date('d F Y, g:i A', strtotime($completedDate)), 0, 1, 'C');
    $pdf->Ln(3);

    // Divider line
    $pdf->SetDrawColor(231, 90, 155);
    $pdf->SetLineWidth(0.3);
    $pdf->Line(20, $pdf->GetY(), 190, $pdf->GetY());
    $pdf->Ln(5);

    // ============================================================
    // TWO COLUMN LAYOUT
    // ============================================================
    $startY = $pdf->GetY();
    $colW = 82;

    // LEFT COLUMN - Tutor Info
    $pdf->SetXY(20, $startY);
    $pdf->SetFillColor(248, 248, 252);
    $pdf->Rect(20, $startY, $colW, 52, 'F');
    
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->SetTextColor(231, 90, 155);
    $pdf->SetXY(25, $startY + 4);
    $pdf->Cell(0, 6, 'TUTOR INFORMATION', 0, 1, 'L');
    
    $pdf->SetFont('Arial', 'B', 7);
    $pdf->SetTextColor(100, 120, 160);
    $pdf->SetXY(25, $startY + 13);
    $pdf->Cell(25, 5, 'Name:', 0, 0);
    $pdf->SetFont('Arial', '', 7);
    $pdf->SetTextColor(60, 80, 120);
    $pdf->Cell(0, 5, $payout['tutor_name'] ?? 'N/A', 0, 1);
    
    $pdf->SetXY(25, $startY + 19);
    $pdf->SetFont('Arial', 'B', 7);
    $pdf->SetTextColor(100, 120, 160);
    $pdf->Cell(25, 5, 'Email:', 0, 0);
    $pdf->SetFont('Arial', '', 7);
    $pdf->SetTextColor(60, 80, 120);
    $pdf->Cell(0, 5, $payout['tutor_email'] ?? 'N/A', 0, 1);
    
    $pdf->SetXY(25, $startY + 25);
    $pdf->SetFont('Arial', 'B', 7);
    $pdf->SetTextColor(100, 120, 160);
    $pdf->Cell(25, 5, 'Tutor ID:', 0, 0);
    $pdf->SetFont('Arial', '', 7);
    $pdf->SetTextColor(60, 80, 120);
    $pdf->Cell(0, 5, 'TCH-' . str_pad($payout['tutor_id'], 6, '0', STR_PAD_LEFT), 0, 1);
    
    $pdf->SetXY(25, $startY + 31);
    $pdf->SetFont('Arial', 'B', 7);
    $pdf->SetTextColor(100, 120, 160);
    $pdf->Cell(25, 5, 'Status:', 0, 0);
    $pdf->SetFont('Arial', 'B', 7);
    $pdf->SetTextColor(40, 167, 69);
    $pdf->Cell(0, 5, 'VERIFIED', 0, 1);

    // RIGHT COLUMN - Bank Info
    $pdf->SetXY(108, $startY);
    $pdf->SetFillColor(248, 248, 252);
    $pdf->Rect(108, $startY, $colW, 52, 'F');
    
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->SetTextColor(231, 90, 155);
    $pdf->SetXY(113, $startY + 4);
    $pdf->Cell(0, 6, 'BANK ACCOUNT', 0, 1, 'L');
    
    $pdf->SetFont('Arial', 'B', 7);
    $pdf->SetTextColor(100, 120, 160);
    $pdf->SetXY(113, $startY + 13);
    $pdf->Cell(25, 5, 'Bank:', 0, 0);
    $pdf->SetFont('Arial', '', 7);
    $pdf->SetTextColor(60, 80, 120);
    $pdf->Cell(0, 5, $payout['bank_name'] ?? 'N/A', 0, 1);
    
    $pdf->SetXY(113, $startY + 19);
    $pdf->SetFont('Arial', 'B', 7);
    $pdf->SetTextColor(100, 120, 160);
    $pdf->Cell(25, 5, 'Account No:', 0, 0);
    $pdf->SetFont('Arial', '', 7);
    $pdf->SetTextColor(60, 80, 120);
    $accountNum = $payout['bank_account_number'] ?? 'N/A';
    $maskedAccount = ($accountNum !== 'N/A') ? '****' . substr($accountNum, -4) : 'N/A';
    $pdf->Cell(0, 5, $maskedAccount, 0, 1);
    
    $pdf->SetXY(113, $startY + 25);
    $pdf->SetFont('Arial', 'B', 7);
    $pdf->SetTextColor(100, 120, 160);
    $pdf->Cell(25, 5, 'Account Name:', 0, 0);
    $pdf->SetFont('Arial', '', 7);
    $pdf->SetTextColor(60, 80, 120);
    $pdf->Cell(0, 5, $payout['bank_account_name'] ?? 'N/A', 0, 1);

    $pdf->SetY($startY + 58);

    // ============================================================
    // PAYMENT DETAILS TABLE
    // ============================================================
    $pdf->SetFillColor(29, 49, 86);
    $pdf->Rect(20, $pdf->GetY(), 170, 8, 'F');
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetXY(25, $pdf->GetY() + 2);
    $pdf->Cell(0, 5, 'PAYMENT DETAILS', 0, 1, 'L');
    $pdf->Ln(4);

    // Table header
    $pdf->SetFillColor(248, 248, 252);
    $pdf->SetDrawColor(200, 200, 210);
    $pdf->SetFont('Arial', 'B', 7);
    $pdf->SetTextColor(60, 80, 120);
    
    $pdf->Cell(50, 7, 'Description', 0, 0, 'L', true);
    $pdf->Cell(80, 7, 'Details', 0, 0, 'L', true);
    $pdf->Cell(40, 7, 'Amount', 0, 1, 'R', true);
    
    $pdf->SetFont('Arial', '', 7);
    $pdf->SetTextColor(60, 80, 120);
    
    // Row 1 - Payout Amount
    $pdf->Cell(50, 6, 'Payout Amount', 0, 0, 'L');
    $pdf->Cell(80, 6, 'Tutor Earnings Payout', 0, 0, 'L');
    $pdf->Cell(40, 6, 'RM ' . number_format($payout['amount'], 2), 0, 1, 'R');
    
    // Row 2 - Request Date
    $pdf->Cell(50, 6, 'Request Date', 0, 0, 'L');
    $pdf->Cell(80, 6, date('d F Y, g:i A', strtotime($payout['requested_at'])), 0, 0, 'L');
    $pdf->Cell(40, 6, '', 0, 1, 'R');
    
    // Row 3 - Transfer Date
    $pdf->Cell(50, 6, 'Transfer Date', 0, 0, 'L');
    $pdf->Cell(80, 6, date('d F Y, g:i A', strtotime($completedDate)), 0, 0, 'L');
    $pdf->Cell(40, 6, '', 0, 1, 'R');
    
    // Row 4 - Transaction Reference (if available)
    $ref = $transaction_ref ?? ($payout['transaction_reference'] ?? '');
    if (!empty($ref) && $ref !== 'N/A') {
        $pdf->Cell(50, 6, 'Transaction Ref', 0, 0, 'L');
        $pdf->Cell(80, 6, $ref, 0, 0, 'L');
        $pdf->Cell(40, 6, '', 0, 1, 'R');
    }
    
    $pdf->Ln(3);
    
    // ============================================================
    // TOTAL AMOUNT BOX
    // ============================================================
    $totalY = $pdf->GetY();
    $pdf->SetFillColor(231, 90, 155);
    $pdf->Rect(130, $totalY, 60, 16, 'F');

    $pdf->SetFont('Arial', 'B', 8);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetXY(135, $totalY + 2);
    $pdf->Cell(0, 5, 'TOTAL TRANSFERRED', 0, 1, 'L');

    $pdf->SetFont('Arial', 'B', 12);
    $pdf->SetXY(135, $totalY + 8);
    $pdf->Cell(0, 6, 'RM ' . number_format($payout['amount'], 2), 0, 1, 'L');

    $pdf->SetY($totalY + 22);

    // ============================================================
    // CONFIRMATION FOOTER
    // ============================================================
    $footerY = $pdf->GetY();
    $pdf->SetFillColor(212, 237, 218);
    $pdf->Rect(20, $footerY, 170, 22, 'F');
    $pdf->SetDrawColor(40, 167, 69);
    $pdf->SetLineWidth(0.3);
    $pdf->Rect(20, $footerY, 170, 22, 'D');

    $pdf->SetFont('Arial', 'B', 9);
    $pdf->SetTextColor(40, 167, 69);
    $pdf->SetXY(30, $footerY + 3);
    $pdf->Cell(0, 5, 'CONFIRMATION OF TRANSFER', 0, 1, 'L');

    $pdf->SetFont('Arial', '', 7);
    $pdf->SetTextColor(21, 87, 36);
    $pdf->SetXY(30, $footerY + 10);
    $pdf->Cell(0, 4, 'This payout has been successfully transferred to the tutor\'s bank account.', 0, 1, 'L');

    $pdf->SetXY(30, $footerY + 16);
    $pdf->Cell(0, 4, 'Effective Date: ' . date('d F Y'), 0, 1, 'L');

    $pdf->SetY($footerY + 30);

    // ============================================================
    // FOOTER
    // ============================================================
    $pdf->SetDrawColor(231, 90, 155);
    $pdf->SetLineWidth(0.3);
    $pdf->Line(20, $pdf->GetY(), 190, $pdf->GetY());
    $pdf->Ln(4);
    
    $pdf->SetFont('Arial', 'I', 7);
    $pdf->SetTextColor(150, 150, 180);
    $pdf->Cell(0, 4, 'This is an official payout receipt from Kyoshi.', 0, 1, 'C');
    $pdf->Cell(0, 4, 'For any inquiries, please contact support@kyoshi.com', 0, 1, 'C');
    $pdf->Cell(0, 4, '(c) ' . date('Y') . ' Kyoshi Language Learning Platform', 0, 1, 'C');

    // Return PDF as string
    return $pdf->Output('S');
}
?>