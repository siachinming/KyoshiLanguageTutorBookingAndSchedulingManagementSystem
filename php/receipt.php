<?php
session_start();
include 'config.php';

if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit(); }
$userID = $_SESSION['user_id'];

$bookingID = intval($_GET['booking_id'] ?? 0);
if (!$bookingID) { header("Location: booking_status.php"); exit(); }

// Get all details
$stmt = $conn->prepare("
    SELECT b.*, u.fullname AS tutor_name, u.email AS tutor_email,
           tp.rate,
           p.id AS payment_id, p.amount, p.payment_method, p.status AS payment_status,
           p.receipt_number, p.created_at AS paid_at,
           s.fullname AS student_name, s.email AS student_email
    FROM bookings b
    JOIN users u ON b.tutor_id = u.id
    JOIN tutor_profiles tp ON b.tutor_id = tp.user_id
    JOIN payments p ON p.booking_id = b.id
    JOIN users s ON b.student_id = s.id
    WHERE b.id = ? AND b.student_id = ? AND p.status = 'verified'
");
$stmt->bind_param("ii", $bookingID, $userID);
$stmt->execute();
$data = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$data) { header("Location: booking_detail.php?id=$bookingID"); exit(); }

$action = $_GET['action'] ?? 'view'; // view, pdf, email

// ── PDF Generation ────────────────────────────────
if ($action === 'pdf') {
    require '../vendor/setasign/fpdf/fpdf.php';

    $pdf = new FPDF();
    $pdf->AddPage();
    $pdf->SetMargins(20, 20, 20);

    // Header background
    $pdf->SetFillColor(231, 90, 155);
    $pdf->Rect(0, 0, 210, 45, 'F');

    // Logo text (since FPDF can't easily embed PNG without GD, use text)
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('Arial', 'B', 28);
    $pdf->SetY(12);
    $pdf->Cell(0, 12, 'Kyoshi', 0, 1, 'C');
    $pdf->SetFont('Arial', '', 11);
    $pdf->Cell(0, 6, 'Language Learning Platform', 0, 1, 'C');

    // Reset color
    $pdf->SetTextColor(52, 38, 53);
    $pdf->SetY(55);

    // Receipt title
    $pdf->SetFont('Arial', 'B', 18);
    $pdf->Cell(0, 10, 'PAYMENT RECEIPT', 0, 1, 'C');
    $pdf->SetFont('Arial', '', 11);
    $pdf->SetTextColor(123, 97, 120);
    $pdf->Cell(0, 7, $data['receipt_number'], 0, 1, 'C');
    $pdf->SetTextColor(52, 38, 53);
    $pdf->Ln(6);

    // Divider
    $pdf->SetDrawColor(242, 138, 178);
    $pdf->SetLineWidth(0.5);
    $pdf->Line(20, $pdf->GetY(), 190, $pdf->GetY());
    $pdf->Ln(6);

    // Helper function for rows
    $row = function($label, $value) use ($pdf) {
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->SetTextColor(123, 97, 120);
        $pdf->Cell(60, 8, $label, 0, 0);
        $pdf->SetFont('Arial', '', 10);
        $pdf->SetTextColor(52, 38, 53);
        $pdf->Cell(0, 8, $value, 0, 1);
    };

    // STUDENT INFO
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->SetFillColor(255, 241, 246);
    $pdf->SetTextColor(201, 79, 134);
    $pdf->Cell(0, 9, '  Student Information', 0, 1, 'L', true);
    $pdf->SetTextColor(52, 38, 53);
    $pdf->Ln(2);
    $row('Name', $data['student_name']);
    $row('Email', $data['student_email']);
    $pdf->Ln(4);

    // TUTOR INFO
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->SetTextColor(201, 79, 134);
    $pdf->Cell(0, 9, '  Tutor Information', 0, 1, 'L', true);
    $pdf->SetTextColor(52, 38, 53);
    $pdf->Ln(2);
    $row('Name', $data['tutor_name']);
    $row('Email', $data['tutor_email']);
    $pdf->Ln(4);

    // SESSION INFO
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->SetTextColor(201, 79, 134);
    $pdf->Cell(0, 9, '  Session Details', 0, 1, 'L', true);
    $pdf->SetTextColor(52, 38, 53);
    $pdf->Ln(2);
    $row('Language', $data['language']);
    $row('Mode', $data['learning_mode'] === 'online' ? 'Online' : 'Face to Face');
    $row('Date', date('d M Y', strtotime($data['booking_date'])));
    $row('Time', date('g:i A', strtotime($data['booking_time'])));
    if ($data['focus']) $row('Focus', $data['focus']);
    $pdf->Ln(4);

    // PAYMENT INFO
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->SetTextColor(201, 79, 134);
    $pdf->Cell(0, 9, '  Payment Details', 0, 1, 'L', true);
    $pdf->SetTextColor(52, 38, 53);
    $pdf->Ln(2);
    $row('Receipt No.', $data['receipt_number']);
    $row('Amount Paid', 'RM ' . number_format($data['amount'], 2));
    $row('Payment Method', ucwords(str_replace('_', ' ', $data['payment_method'])));
    $row('Paid On', date('d M Y, g:i A', strtotime($data['paid_at'])));
    $row('Status', 'VERIFIED');
    $pdf->Ln(6);

    // Total box
    $pdf->SetFillColor(231, 90, 155);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('Arial', 'B', 14);
    $pdf->Cell(0, 14, 'Total Paid: RM ' . number_format($data['amount'], 2), 0, 1, 'C', true);
    $pdf->Ln(8);

    // Footer
    $pdf->SetDrawColor(242, 138, 178);
    $pdf->Line(20, $pdf->GetY(), 190, $pdf->GetY());
    $pdf->Ln(4);
    $pdf->SetFont('Arial', 'I', 9);
    $pdf->SetTextColor(123, 97, 120);
    $pdf->Cell(0, 6, 'Thank you for learning with Kyoshi! We hope you enjoyed your session.', 0, 1, 'C');
    $pdf->Cell(0, 6, 'For support, contact us at support@kyoshi.com', 0, 1, 'C');
    $pdf->Cell(0, 6, 'Generated on ' . date('d M Y, g:i A'), 0, 1, 'C');

    $pdf->Output('D', 'Kyoshi_Receipt_' . $data['receipt_number'] . '.pdf');
    exit();
}

// ── Email Receipt ─────────────────────────────────
if ($action === 'email') {
    require '../vendor/autoload.php';

    use PHPMailer\PHPMailer\PHPMailer;
    use PHPMailer\PHPMailer\Exception;

    // Generate PDF to string first
    require '../vendor/setasign/fpdf/fpdf.php';

    $pdf = new FPDF();
    $pdf->AddPage();
    $pdf->SetMargins(20, 20, 20);

    $pdf->SetFillColor(231, 90, 155);
    $pdf->Rect(0, 0, 210, 45, 'F');
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('Arial', 'B', 28);
    $pdf->SetY(12);
    $pdf->Cell(0, 12, 'Kyoshi', 0, 1, 'C');
    $pdf->SetFont('Arial', '', 11);
    $pdf->Cell(0, 6, 'Language Learning Platform', 0, 1, 'C');
    $pdf->SetTextColor(52, 38, 53);
    $pdf->SetY(55);
    $pdf->SetFont('Arial', 'B', 18);
    $pdf->Cell(0, 10, 'PAYMENT RECEIPT', 0, 1, 'C');
    $pdf->SetFont('Arial', '', 11);
    $pdf->SetTextColor(123, 97, 120);
    $pdf->Cell(0, 7, $data['receipt_number'], 0, 1, 'C');
    $pdf->SetTextColor(52, 38, 53);
    $pdf->Ln(6);
    $pdf->SetDrawColor(242, 138, 178);
    $pdf->SetLineWidth(0.5);
    $pdf->Line(20, $pdf->GetY(), 190, $pdf->GetY());
    $pdf->Ln(6);

    $row = function($label, $value) use ($pdf) {
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->SetTextColor(123, 97, 120);
        $pdf->Cell(60, 8, $label, 0, 0);
        $pdf->SetFont('Arial', '', 10);
        $pdf->SetTextColor(52, 38, 53);
        $pdf->Cell(0, 8, $value, 0, 1);
    };

    $pdf->SetFont('Arial', 'B', 12);
    $pdf->SetFillColor(255, 241, 246);
    $pdf->SetTextColor(201, 79, 134);
    $pdf->Cell(0, 9, '  Student Information', 0, 1, 'L', true);
    $pdf->SetTextColor(52, 38, 53); $pdf->Ln(2);
    $row('Name', $data['student_name']);
    $row('Email', $data['student_email']);
    $pdf->Ln(4);

    $pdf->SetFont('Arial', 'B', 12);
    $pdf->SetTextColor(201, 79, 134);
    $pdf->Cell(0, 9, '  Tutor Information', 0, 1, 'L', true);
    $pdf->SetTextColor(52, 38, 53); $pdf->Ln(2);
    $row('Name', $data['tutor_name']);
    $row('Email', $data['tutor_email']);
    $pdf->Ln(4);

    $pdf->SetFont('Arial', 'B', 12);
    $pdf->SetTextColor(201, 79, 134);
    $pdf->Cell(0, 9, '  Session Details', 0, 1, 'L', true);
    $pdf->SetTextColor(52, 38, 53); $pdf->Ln(2);
    $row('Language', $data['language']);
    $row('Mode', $data['learning_mode'] === 'online' ? 'Online' : 'Face to Face');
    $row('Date', date('d M Y', strtotime($data['booking_date'])));
    $row('Time', date('g:i A', strtotime($data['booking_time'])));
    if ($data['focus']) $row('Focus', $data['focus']);
    $pdf->Ln(4);

    $pdf->SetFont('Arial', 'B', 12);
    $pdf->SetTextColor(201, 79, 134);
    $pdf->Cell(0, 9, '  Payment Details', 0, 1, 'L', true);
    $pdf->SetTextColor(52, 38, 53); $pdf->Ln(2);
    $row('Receipt No.', $data['receipt_number']);
    $row('Amount Paid', 'RM ' . number_format($data['amount'], 2));
    $row('Payment Method', ucwords(str_replace('_', ' ', $data['payment_method'])));
    $row('Paid On', date('d M Y, g:i A', strtotime($data['paid_at'])));
    $row('Status', 'VERIFIED');
    $pdf->Ln(6);

    $pdf->SetFillColor(231, 90, 155);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('Arial', 'B', 14);
    $pdf->Cell(0, 14, 'Total Paid: RM ' . number_format($data['amount'], 2), 0, 1, 'C', true);
    $pdf->Ln(8);

    $pdf->SetDrawColor(242, 138, 178);
    $pdf->Line(20, $pdf->GetY(), 190, $pdf->GetY());
    $pdf->Ln(4);
    $pdf->SetFont('Arial', 'I', 9);
    $pdf->SetTextColor(123, 97, 120);
    $pdf->Cell(0, 6, 'Thank you for learning with Kyoshi!', 0, 1, 'C');
    $pdf->Cell(0, 6, 'Generated on ' . date('d M Y, g:i A'), 0, 1, 'C');

    // Get PDF as string
    $pdfString = $pdf->Output('S', 'receipt.pdf');

    // Send email
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
        $mail->addAddress($data['student_email'], $data['student_name']);

        $mail->isHTML(true);
        $mail->Subject = 'Your Kyoshi Receipt - ' . $data['receipt_number'];
        $mail->Body = "
            <div style='font-family:Segoe UI,sans-serif;max-width:500px;margin:auto;'>
                <div style='background:linear-gradient(135deg,#E75A9B,#F28AB2);padding:30px;text-align:center;border-radius:16px 16px 0 0;'>
                    <h1 style='color:white;margin:0;font-size:28px;'>Kyoshi</h1>
                    <p style='color:rgba(255,255,255,.85);margin:6px 0 0;font-size:14px;'>Language Learning Platform</p>
                </div>
                <div style='background:#fff;padding:28px;border:1px solid #f0d0e0;border-top:none;border-radius:0 0 16px 16px;'>
                    <h2 style='color:#342635;margin:0 0 6px;'>Payment Confirmed! 🎉</h2>
                    <p style='color:#7B6178;'>Hi {$data['student_name']}, your payment has been verified. Please find your receipt attached.</p>
                    <div style='background:#FFF1F6;border-radius:12px;padding:16px;margin:20px 0;'>
                        <p style='margin:0 0 8px;font-size:13px;'><strong>Receipt No:</strong> {$data['receipt_number']}</p>
                        <p style='margin:0 0 8px;font-size:13px;'><strong>Tutor:</strong> {$data['tutor_name']}</p>
                        <p style='margin:0 0 8px;font-size:13px;'><strong>Language:</strong> {$data['language']}</p>
                        <p style='margin:0 0 8px;font-size:13px;'><strong>Date:</strong> " . date('d M Y', strtotime($data['booking_date'])) . "</p>
                        <p style='margin:0;font-size:13px;'><strong>Amount Paid:</strong> RM " . number_format($data['amount'], 2) . "</p>
                    </div>
                    <p style='color:#7B6178;font-size:13px;'>Thank you for learning with Kyoshi! We hope you enjoy your session.</p>
                </div>
            </div>
        ";

        // Attach PDF
        $mail->addStringAttachment($pdfString, 'Kyoshi_Receipt_' . $data['receipt_number'] . '.pdf');
        $mail->send();

        header("Location: booking_detail.php?id=$bookingID&emailed=1");
        exit();

    } catch (Exception $e) {
        header("Location: booking_detail.php?id=$bookingID&email_failed=1");
        exit();
    }
}

function e($v){ return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }
?>