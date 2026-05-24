<?php
session_start();
include 'config.php';

if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit(); }
$userID = $_SESSION['user_id'];

$bookingID = intval($_GET['booking_id'] ?? 0);
if (!$bookingID) { header("Location: booking_status.php"); exit(); }

$stmt = $conn->prepare("
    SELECT b.*, u.fullname AS tutor_name, u.email AS tutor_email,
           tp.rate,
           p.id AS payment_id, p.amount, p.payment_method, p.status AS payment_status,
           p.receipt_number, p.receipt_url, p.created_at AS paid_at,
           s.fullname AS student_name, s.email AS student_email
    FROM bookings b
    JOIN users u ON b.tutor_id = u.id
    JOIN tutor_profiles tp ON b.tutor_id = tp.user_id
    JOIN payments p ON p.booking_id = b.id
    JOIN users s ON b.student_id = s.id
    WHERE b.id = ? AND b.student_id = ? AND p.status = 'verified' AND p.payment_method = 'stripe'
");
$stmt->bind_param("ii", $bookingID, $userID);
$stmt->execute();
$data = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$data) { header("Location: booking_detail.php?id=$bookingID"); exit(); }

$action = $_GET['action'] ?? 'pdf';

if ($action === 'pdf') {
    require '../vendor/setasign/fpdf/fpdf.php';

    $receiptRef = $data['receipt_number'] ?? 'Stripe Payment';

    $pdf = new FPDF();
    $pdf->AddPage();
    $pdf->SetMargins(15, 15, 15);

    // ── HEADER BAND (deep blue) ──────────────────────────
    $pdf->SetFillColor(140, 192, 235); // #8CC0EB
    $pdf->Rect(0, 0, 210, 50, 'F');

    // Accent stripe
    $pdf->SetFillColor(191, 221, 240); // #BFDDF0
    $pdf->Rect(0, 42, 210, 8, 'F');

    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('Arial', 'B', 32);
    $pdf->SetY(10);
    $pdf->Cell(0, 14, 'KYOSHI', 0, 1, 'C');
    $pdf->SetFont('Arial', '', 11);
    $pdf->SetTextColor(255, 255, 230);
    $pdf->Cell(0, 6, 'Language Learning Platform', 0, 1, 'C');

    // ── RECEIPT TITLE ────────────────────────────────────
    $pdf->SetY(58);
    $pdf->SetFont('Arial', 'B', 20);
    $pdf->SetTextColor(60, 80, 120);
    $pdf->Cell(0, 10, 'PAYMENT RECEIPT', 0, 1, 'C');

    $pdf->SetFont('Arial', '', 10);
    $pdf->SetTextColor(100, 120, 160);
    $pdf->Cell(0, 6, 'Stripe Transaction: ' . $receiptRef, 0, 1, 'C');
    $pdf->Cell(0, 6, 'Generated: ' . date('d M Y, g:i A'), 0, 1, 'C');
    $pdf->Ln(4);

    // Divider
    $pdf->SetDrawColor(140, 192, 235);
    $pdf->SetLineWidth(0.8);
    $pdf->Line(15, $pdf->GetY(), 195, $pdf->GetY());
    $pdf->Ln(6);

    // ── SECTION HELPER ───────────────────────────────────
    $section = function($title, $bgR, $bgG, $bgB) use ($pdf) {
        $pdf->SetFillColor($bgR, $bgG, $bgB);
        $pdf->SetTextColor(60, 80, 120);
        $pdf->SetFont('Arial', 'B', 11);
        $pdf->Cell(0, 9, '  ' . $title, 0, 1, 'L', true);
        $pdf->SetTextColor(52, 52, 52);
        $pdf->Ln(2);
    };

    $row = function($label, $value) use ($pdf) {
        $pdf->SetFont('Arial', 'B', 9);
        $pdf->SetTextColor(120, 140, 180);
        $pdf->Cell(55, 8, $label, 0, 0);
        $pdf->SetFont('Arial', '', 9);
        $pdf->SetTextColor(40, 40, 60);
        $pdf->Cell(0, 8, $value, 0, 1);
    };

    // Zebra row helper
    $zebraOn = false;
    $zebraRow = function($label, $value) use ($pdf, &$zebraOn) {
        if ($zebraOn) {
            $pdf->SetFillColor(255, 249, 210); // #FFF9D2
            $pdf->SetFont('Arial', 'B', 9);
            $pdf->SetTextColor(120, 140, 180);
            $pdf->Cell(55, 8, $label, 0, 0, 'L', true);
            $pdf->SetFont('Arial', '', 9);
            $pdf->SetTextColor(40, 40, 60);
            $pdf->Cell(0, 8, $value, 0, 1, 'L', true);
        } else {
            $pdf->SetFont('Arial', 'B', 9);
            $pdf->SetTextColor(120, 140, 180);
            $pdf->Cell(55, 8, $label, 0, 0);
            $pdf->SetFont('Arial', '', 9);
            $pdf->SetTextColor(40, 40, 60);
            $pdf->Cell(0, 8, $value, 0, 1);
        }
        $zebraOn = !$zebraOn;
    };

    // ── TWO COLUMN LAYOUT ────────────────────────────────
    $colW = 85;
    $startY = $pdf->GetY();

    // LEFT: Student
    $pdf->SetXY(15, $startY);
    $pdf->SetFillColor(191, 221, 240); // #BFDDF0
    $pdf->SetTextColor(60, 80, 120);
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->Cell($colW, 9, '  Student', 0, 1, 'L', true);
    $pdf->SetTextColor(40, 40, 60);
    $pdf->Ln(2);

    $pdf->SetX(15);
    $pdf->SetFont('Arial', 'B', 9); $pdf->SetTextColor(120,140,180);
    $pdf->Cell(28, 7, 'Name', 0, 0); $pdf->SetFont('Arial','',9); $pdf->SetTextColor(40,40,60);
    $pdf->Cell($colW - 28, 7, $data['student_name'], 0, 1);
    $pdf->SetX(15);
    $pdf->SetFont('Arial', 'B', 9); $pdf->SetTextColor(120,140,180);
    $pdf->Cell(28, 7, 'Email', 0, 0); $pdf->SetFont('Arial','',9); $pdf->SetTextColor(40,40,60);
    $pdf->Cell($colW - 28, 7, $data['student_email'], 0, 1);

    $afterLeftY = $pdf->GetY();

    // RIGHT: Tutor
    $pdf->SetXY(110, $startY);
    $pdf->SetFillColor(191, 221, 240);
    $pdf->SetTextColor(60, 80, 120);
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->Cell($colW, 9, '  Tutor', 0, 1, 'L', true);
    $pdf->SetTextColor(40, 40, 60);
    $pdf->Ln(2);

    $pdf->SetXY(110, $pdf->GetY());
    $pdf->SetFont('Arial', 'B', 9); $pdf->SetTextColor(120,140,180);
    $pdf->Cell(28, 7, 'Name', 0, 0); $pdf->SetFont('Arial','',9); $pdf->SetTextColor(40,40,60);
    $pdf->Cell($colW - 28, 7, $data['tutor_name'], 0, 1);
    $pdf->SetXY(110, $pdf->GetY());
    $pdf->SetFont('Arial', 'B', 9); $pdf->SetTextColor(120,140,180);
    $pdf->Cell(28, 7, 'Email', 0, 0); $pdf->SetFont('Arial','',9); $pdf->SetTextColor(40,40,60);
    $pdf->Cell($colW - 28, 7, $data['tutor_email'], 0, 1);

    $pdf->SetY(max($afterLeftY, $pdf->GetY()) + 6);

    // ── SESSION DETAILS ──────────────────────────────────
    $section('Session Details', 191, 221, 240);
    $zebraOn = false;
    $zebraRow('Language', $data['language']);
    $zebraRow('Mode', $data['learning_mode'] === 'online' ? 'Online' : 'Face to Face');
    $zebraRow('Date', date('d M Y', strtotime($data['booking_date'])));
    $zebraRow('Time', date('g:i A', strtotime($data['booking_time'])));
    if (!empty($data['focus'])) $zebraRow('Focus', $data['focus']);
    $pdf->Ln(6);

    // ── PAYMENT DETAILS ──────────────────────────────────
    $section('Payment Details', 255, 235, 204); // #FFEBCC
    $zebraOn = false;
    $zebraRow('Transaction Ref', $receiptRef);
    $zebraRow('Payment Method', 'Stripe (Card)');
    $zebraRow('Paid On', date('d M Y, g:i A', strtotime($data['paid_at'])));
    $zebraRow('Status', 'VERIFIED');
    $pdf->Ln(6);

    // ── TOTAL BOX ────────────────────────────────────────
    $pdf->SetFillColor(140, 192, 235); // #8CC0EB
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('Arial', 'B', 16);
    $pdf->Cell(0, 16, 'Total Paid: RM ' . number_format($data['amount'], 2), 0, 1, 'C', true);
    $pdf->Ln(8);

    // ── VERIFIED BADGE ───────────────────────────────────
    $pdf->SetFillColor(255, 249, 210); // #FFF9D2
    $pdf->SetTextColor(100, 140, 60);
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->Cell(0, 10, 'Payment Verified via Stripe', 0, 1, 'C', true);
    $pdf->Ln(6);

    // ── FOOTER ───────────────────────────────────────────
    $pdf->SetDrawColor(140, 192, 235);
    $pdf->SetLineWidth(0.5);
    $pdf->Line(15, $pdf->GetY(), 195, $pdf->GetY());
    $pdf->Ln(4);
    $pdf->SetFont('Arial', 'I', 9);
    $pdf->SetTextColor(140, 160, 190);
    $pdf->Cell(0, 6, 'Thank you for learning with Kyoshi! We hope you enjoyed your session.', 0, 1, 'C');
    $pdf->Cell(0, 5, 'For support: support@kyoshi.com', 0, 1, 'C');

    $filename = 'Kyoshi_Stripe_Receipt_' . preg_replace('/[^a-zA-Z0-9_]/', '', $receiptRef) . '.pdf';
    $pdf->Output('D', $filename);
    exit();
}
?>