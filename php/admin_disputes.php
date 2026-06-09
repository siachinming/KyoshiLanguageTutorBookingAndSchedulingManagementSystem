<?php
session_start();
include 'config.php';
include 'insert_notification.php';
include 'check_login.php';
require_once '../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
// Auto-escalate wrong_materials disputes after 2 days if tutor hasn't responded
$autoEscalateWrongMaterials = $conn->prepare("
    UPDATE disputes d
    SET d.status = 'escalated',
        d.escalated_at = NOW(),
        d.escalation_reason = 'Tutor did not respond within 2 days'
    WHERE d.issue_type = 'wrong_materials'
      AND d.status = 'pending'
      AND d.created_at < DATE_SUB(NOW(), INTERVAL 2 DAY)
");
$autoEscalateWrongMaterials->execute();

// Also send email notification to admin when escalated
$getEscalatedWrongMaterials = $conn->prepare("
    SELECT d.*, 
           s.fullname as student_name, s.email as student_email,
           t.fullname as tutor_name, t.email as tutor_email,
           b.language, b.booking_date
    FROM disputes d
    JOIN users s ON d.student_id = s.id
    JOIN users t ON d.tutor_id = t.id
    JOIN bookings b ON d.booking_id = b.id
    WHERE d.issue_type = 'wrong_materials'
      AND d.status = 'escalated'
      AND d.escalated_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
      AND d.notification_sent IS NULL
");
$getEscalatedWrongMaterials->execute();
$escalatedDisputes = $getEscalatedWrongMaterials->get_result();

while ($escalated = $escalatedDisputes->fetch_assoc()) {
    // Send email to admin
    $admin_email = "admin@kyoshi.com";
    $subject = "URGENT: Wrong Materials Dispute Escalated - No Tutor Response";
    $body = "A 'Wrong Materials' dispute has been automatically escalated because the tutor did not respond within 2 days.\n\n";
    $body .= "Student: " . $escalated['student_name'] . "\n";
    $body .= "Tutor: " . $escalated['tutor_name'] . "\n";
    $body .= "Booking ID: #" . $escalated['booking_id'] . "\n";
    $body .= "Language: " . $escalated['language'] . "\n";
    $body .= "Session Date: " . date('d M Y', strtotime($escalated['booking_date'])) . "\n\n";
    $body .= "Please review and take action immediately.\n";
    mail($admin_email, $subject, $body);
    
    // Mark notification as sent
    $updateNotif = $conn->prepare("UPDATE disputes SET notification_sent = NOW() WHERE id = ?");
    $updateNotif->bind_param("i", $escalated['id']);
    $updateNotif->execute();
}

// Add this function after checkTutorAvailability function
function checkTutorScheduleAvailability($conn, $tutor_id, $new_date, $new_time) {
    $day_of_week = date('l', strtotime($new_date));
    
    $scheduleCheck = $conn->prepare("
        SELECT COUNT(*) as count 
        FROM tutor_availability 
        WHERE tutor_id = ? 
        AND day_of_week = ?
        AND start_time <= ? 
        AND end_time >= ?
    ");
    $scheduleCheck->bind_param("isss", $tutor_id, $day_of_week, $new_time, $new_time);
    $scheduleCheck->execute();
    $result = $scheduleCheck->get_result()->fetch_assoc();
    
    return $result['count'] > 0;
}
// Function to check tutor availability for reschedule
function checkTutorAvailability($conn, $tutor_id, $new_date, $new_time, $exclude_booking_id = null) {
    $sql = "SELECT COUNT(*) as count FROM bookings 
            WHERE tutor_id = ? 
            AND booking_date = ? 
            AND booking_time = ?
            AND status NOT IN ('cancelled', 'rejected')";
    
    $params = [$tutor_id, $new_date, $new_time];
    $types = "iss";
    
    if ($exclude_booking_id) {
        $sql .= " AND id != ?";
        $params[] = $exclude_booking_id;
        $types .= "i";
    }
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    
    return $result['count'] == 0;
}
$assetBase = '../assets/img';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

$adminID = $_SESSION['user_id'];

// Get admin info
$stmt = $conn->prepare("SELECT * FROM users WHERE id = ? AND role = 'admin'");
$stmt->bind_param("i", $adminID);
$stmt->execute();
$admin = $stmt->get_result()->fetch_assoc();

$displayName = $admin['fullname'];
$profilePic = !empty($admin['profile_pic']) ? '../uploads/profiles/' . $admin['profile_pic'] : $assetBase . '/profile-admin.png';

// Get counts for sidebar
$totalTutors = $conn->query("SELECT COUNT(*) as count FROM users WHERE role = 'tutor'")->fetch_assoc()['count'];
$totalStudents = $conn->query("SELECT COUNT(*) as count FROM users WHERE role = 'student'")->fetch_assoc()['count'];
$totalBookings = $conn->query("SELECT COUNT(*) as count FROM bookings")->fetch_assoc()['count'];
$pendingPayments = $conn->query("SELECT COUNT(*) as count FROM payments WHERE status = 'pending'")->fetch_assoc()['count'];
$pendingDisputes = $conn->query("SELECT COUNT(*) as count FROM disputes WHERE status = 'pending'")->fetch_assoc()['count'];
$pendingPayouts = $conn->query("SELECT COUNT(*) as count FROM payout_requests WHERE status = 'pending'")->fetch_assoc()['count'] ?? 0;
// ============================================
// HANDLE DISPUTE RESOLUTION
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resolve_dispute'])) {
    $dispute_id = intval($_POST['dispute_id']);
    $resolution_note = trim($_POST['resolution_note'] ?? '');
    $action = $_POST['action'] ?? 'resolve';
    $refund_amount = floatval($_POST['refund_amount'] ?? 0);
    $new_booking_date = $_POST['new_booking_date'] ?? '';
    $new_booking_time = $_POST['new_booking_time'] ?? '';
    
    // Get dispute details with all related info
    $disputeStmt = $conn->prepare("
        SELECT d.*, 
               b.student_id, b.tutor_id, b.total_amount, b.language, b.booking_date, b.booking_time, b.learning_mode,
               s.email as student_email, s.fullname as student_name, s.phone as student_phone,
               t.email as tutor_email, t.fullname as tutor_name,
               p.id as payment_id, p.amount as payment_amount, p.payment_method, p.receipt_number, p.refund_receipt_number
        FROM disputes d 
        JOIN bookings b ON d.booking_id = b.id 
        JOIN users s ON b.student_id = s.id
        JOIN users t ON b.tutor_id = t.id
        LEFT JOIN payments p ON d.payment_id = p.id
        WHERE d.id = ?
    ");
    $disputeStmt->bind_param("i", $dispute_id);
    $disputeStmt->execute();
    $dispute = $disputeStmt->get_result()->fetch_assoc();
    
    if ($dispute) {
        $student_email = $dispute['student_email'];
        $student_name = $dispute['student_name'];
        $tutor_email = $dispute['tutor_email'];
        $tutor_name = $dispute['tutor_name'];
        $booking_id = $dispute['booking_id'];
        $issue_type = $dispute['issue_type'];
        
        // ✅ IMPORTANT: For payment disputes, use resolution_requested from database
        // This handles when student/tutor resolved it themselves
        if ($dispute['issue_type'] === 'money_deducted' && !empty($dispute['resolution_requested']) && $action === 'resolve') {
            $action = $dispute['resolution_requested'];
            error_log("Payment dispute - using resolution_requested as action: " . $action);
        }
        
        // Generate refund receipt number if refund
        $refund_receipt_no = null;
        $resolution_detail = '';
        
        // ============================================
        // HANDLE DIFFERENT ACTIONS
        // ============================================
        
        if ($action === 'refund') {
            // Generate refund receipt number
            $refund_receipt_no = 'RFD-' . date('Ymd') . '-' . str_pad($dispute_id, 6, '0', STR_PAD_LEFT);
            
            // Update payment with refund status
            $updatePayment = $conn->prepare("UPDATE payments SET refund_status = 'completed', refund_receipt_number = ?, refund_processed_at = NOW() WHERE booking_id = ? OR id = ?");
            $updatePayment->bind_param("sii", $refund_receipt_no, $booking_id, $dispute['payment_id']);
            $updatePayment->execute();
            
            // Update booking status
            $updateBooking = $conn->prepare("UPDATE bookings SET status = 'cancelled', cancel_reason = 'Refunded due to dispute resolution', cancelled_by = 'admin' WHERE id = ?");
            $updateBooking->bind_param("i", $booking_id);
            $updateBooking->execute();
            
            // Generate PDF Refund Receipt (this creates the PDF file)
            $pdf_path = generateRefundReceiptPDF($dispute, $refund_amount, $refund_receipt_no);
            
            // Send refund email with PDF attachment (like payout does)
            sendRefundEmailWithPDF($student_email, $student_name, $refund_amount, $dispute, $refund_receipt_no, $pdf_path);
            sendTutorRefundNotificationEmail($tutor_email, $tutor_name, $dispute, $refund_amount);
            
            $resolution_detail = "Refund of RM " . number_format($refund_amount, 2) . " processed. Receipt: $refund_receipt_no";
            
        } elseif ($action === 'reschedule' && $new_booking_date && $new_booking_time) {
            // FIRST: Check if tutor has this time in their availability schedule
            $day_of_week = date('l', strtotime($new_booking_date));
            $scheduleCheck = $conn->prepare("
                SELECT COUNT(*) as count 
                FROM tutor_availability 
                WHERE tutor_id = ? 
                AND day_of_week = ?
                AND start_time <= ? 
                AND end_time >= ?
            ");
            $scheduleCheck->bind_param("isss", $dispute['tutor_id'], $day_of_week, $new_booking_time, $new_booking_time);
            $scheduleCheck->execute();
            $hasSchedule = $scheduleCheck->get_result()->fetch_assoc();
            
            if ($hasSchedule['count'] == 0) {
                $_SESSION['error_message'] = "❌ Cannot reschedule: Tutor is not available on " . date('l', strtotime($new_booking_date)) . " at " . date('g:i A', strtotime($new_booking_time)) . ". Please check tutor's availability schedule.";
                header("Location: admin_disputes.php");
                exit();
            }
            
            // SECOND: Check if tutor has any conflicting booking at this time
            $conflictCheck = $conn->prepare("
                SELECT COUNT(*) as count 
                FROM bookings 
                WHERE tutor_id = ? 
                AND booking_date = ? 
                AND booking_time = ?
                AND status NOT IN ('cancelled', 'rejected')
                AND id != ?
            ");
            $conflictCheck->bind_param("issi", $dispute['tutor_id'], $new_booking_date, $new_booking_time, $booking_id);
            $conflictCheck->execute();
            $hasConflict = $conflictCheck->get_result()->fetch_assoc();
            
            if ($hasConflict['count'] > 0) {
                $_SESSION['error_message'] = "❌ Cannot reschedule: Tutor already has a booking at " . date('g:i A', strtotime($new_booking_time)) . " on " . date('d M Y', strtotime($new_booking_date)) . ". Please choose a different time.";
                header("Location: admin_disputes.php");
                exit();
            }
            
            // Reschedule booking
            $updateBooking = $conn->prepare("UPDATE bookings SET booking_date = ?, booking_time = ?, status = 'confirmed' WHERE id = ?");
            $updateBooking->bind_param("ssi", $new_booking_date, $new_booking_time, $booking_id);
            
            if ($updateBooking->execute()) {
                // Send reschedule confirmation emails with old and new times
                $studentEmailSent = sendRescheduleEmail($student_email, $student_name, $tutor_name, $new_booking_date, $new_booking_time, $dispute);
                $tutorEmailSent = sendTutorRescheduleEmail($tutor_email, $tutor_name, $student_name, $new_booking_date, $new_booking_time, $dispute);
                
                $resolution_detail = "Session rescheduled from " . date('d M Y', strtotime($dispute['booking_date'])) . " at " . date('g:i A', strtotime($dispute['booking_time'])) . " to " . date('d M Y', strtotime($new_booking_date)) . " at " . date('g:i A', strtotime($new_booking_time));
                
                if ($studentEmailSent && $tutorEmailSent) {
                    $_SESSION['success_message'] = "✓ Dispute resolved: Session rescheduled. Email notifications sent to both parties.";
                } else {
                    $_SESSION['warning_message'] = "⚠️ Session rescheduled but some emails could not be sent. Please check SMTP settings.";
                }
            } else {
                $_SESSION['error_message'] = "❌ Failed to reschedule session. Please try again.";
                header("Location: admin_disputes.php");
                exit();
            }
            
        } elseif ($action === 'complete') {
            // Confirm existing booking
            $updateBooking = $conn->prepare("UPDATE bookings SET status = 'confirmed' WHERE id = ?");
            $updateBooking->bind_param("i", $booking_id);
            
            if ($updateBooking->execute()) {
                // Send confirmation emails
                $studentEmailSent = sendBookingConfirmedEmail($student_email, $student_name, $tutor_name, $dispute['booking_date'], $dispute['booking_time'], $dispute['language']);
                $tutorEmailSent = sendTutorBookingConfirmedEmail($tutor_email, $tutor_name, $student_name, $dispute['booking_date'], $dispute['booking_time'], $dispute['language']);
                
                $resolution_detail = "Booking confirmed as originally scheduled.";
                
                if ($studentEmailSent && $tutorEmailSent) {
                    $_SESSION['success_message'] = "✓ Booking confirmed. Email notifications sent to both parties.";
                } else {
                    $_SESSION['warning_message'] = "⚠️ Booking confirmed but some emails could not be sent.";
                }
            } else {
                $_SESSION['error_message'] = "❌ Failed to confirm booking.";
            }
            
        } elseif ($action === 'resolve') {
            // Just mark dispute as resolved (for minor issues like wrong_materials)
            $resolution_detail = "Issue resolved between student and tutor.";
            
            if ($dispute['issue_type'] === 'wrong_materials') {
                sendIssueResolvedEmail($student_email, $student_name, $tutor_name, $dispute);
                sendTutorIssueResolvedEmail($tutor_email, $tutor_name, $student_name, $dispute);
            }
            
        } elseif ($action === 'reject') {
            // Reject the dispute based on type
            $resolution_detail = "Dispute rejected by admin. Reason: " . $resolution_note;
            
            if ($dispute['dispute_type'] === 'payment') {
                // For payment disputes: Cancel booking and mark payment as rejected
                $updateBooking = $conn->prepare("UPDATE bookings SET status = 'cancelled', cancel_reason = 'Payment dispute rejected - student must pay correct amount', cancelled_by = 'admin' WHERE id = ?");
                $updateBooking->bind_param("i", $booking_id);
                $updateBooking->execute();
                
                // Update payment status to rejected
                $updatePayment = $conn->prepare("UPDATE payments SET status = 'rejected', admin_notes = CONCAT(IFNULL(admin_notes, ''), ' Dispute rejected: ', ?) WHERE id = ?");
                $updatePayment->bind_param("si", $resolution_note, $dispute['payment_id']);
                $updatePayment->execute();
                
                $resolution_detail = "Payment dispute rejected. Booking cancelled. Student must make correct payment.";
                
            } else {
                // For booking disputes: Keep booking as confirmed
                $updateBooking = $conn->prepare("UPDATE bookings SET status = 'confirmed' WHERE id = ?");
                $updateBooking->bind_param("i", $booking_id);
                $updateBooking->execute();
                
                $resolution_detail = "Booking dispute rejected. Session will proceed as scheduled.";
            }
            
            // Send rejection notification emails
            sendDisputeRejectedEmail($student_email, $student_name, $tutor_name, $dispute, $resolution_note, $dispute['dispute_type']);
            sendTutorDisputeRejectedEmail($tutor_email, $tutor_name, $student_name, $dispute, $resolution_note, $dispute['dispute_type']);
        }
        
        // Update dispute status
        $updateStmt = $conn->prepare("UPDATE disputes SET status = 'resolved', resolution_note = CONCAT(?, ' ', ?), resolved_by = ?, resolved_at = NOW() WHERE id = ?");
        $updateStmt->bind_param("ssii", $resolution_detail, $resolution_note, $adminID, $dispute_id);
        $updateStmt->execute();
        
        // Send notifications via database
        sendDisputeResolvedNotification($conn, $dispute, $resolution_detail);
        
        $_SESSION['success_message'] = "Dispute resolved successfully. Emails have been sent to both parties.";
    }
    
    header("Location: admin_disputes.php");
    exit();
}

// ============================================
// HELPER FUNCTIONS
// ============================================
// Replace the old generateRefundReceiptPDF function with this - just return a path
function generateRefundReceiptPDF($dispute, $refund_amount, $receipt_no) {
    require_once('../vendor/setasign/fpdf/fpdf.php');
    
    $pdf_dir = '../uploads/refunds/';
    if (!file_exists($pdf_dir)) {
        mkdir($pdf_dir, 0777, true);
    }
    
    $pdf_filename = 'refund_receipt_' . $receipt_no . '.pdf';
    $pdf_path = $pdf_dir . $pdf_filename;
    
    // Create PDF
    $pdf = new FPDF();
    $pdf->AddPage();
    $pdf->SetMargins(15, 15, 15);
    
    // ── HEADER BAND ──────────────────────────
    $pdf->SetFillColor(231, 90, 155); // #E75A9B
    $pdf->Rect(0, 0, 210, 50, 'F');
    
    // Accent stripe
    $pdf->SetFillColor(255, 200, 220);
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
    $pdf->SetTextColor(231, 90, 155);
    $pdf->Cell(0, 10, 'REFUND RECEIPT', 0, 1, 'C');
    
    $pdf->SetFont('Arial', '', 10);
    $pdf->SetTextColor(100, 120, 160);
    $pdf->Cell(0, 6, 'Refund Number: ' . $receipt_no, 0, 1, 'C');
    $pdf->Cell(0, 6, 'Generated: ' . date('d M Y, g:i A'), 0, 1, 'C');
    $pdf->Ln(4);
    
    // Divider
    $pdf->SetDrawColor(231, 90, 155);
    $pdf->SetLineWidth(0.8);
    $pdf->Line(15, $pdf->GetY(), 195, $pdf->GetY());
    $pdf->Ln(6);
    
    // ── TWO COLUMN LAYOUT ────────────────────────────────
    $colW = 85;
    $startY = $pdf->GetY();
    
    // LEFT: Student
    $pdf->SetXY(15, $startY);
    $pdf->SetFillColor(253, 242, 248); // Light pink
    $pdf->SetTextColor(60, 80, 120);
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->Cell($colW, 9, '  Student', 0, 1, 'L', true);
    $pdf->SetTextColor(40, 40, 60);
    $pdf->Ln(2);
    
    $pdf->SetX(15);
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->SetTextColor(120, 140, 180);
    $pdf->Cell(28, 7, 'Name', 0, 0);
    $pdf->SetFont('Arial', '', 9);
    $pdf->SetTextColor(40, 40, 60);
    $pdf->Cell($colW - 28, 7, $dispute['student_name'], 0, 1);
    $pdf->SetX(15);
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->SetTextColor(120, 140, 180);
    $pdf->Cell(28, 7, 'Email', 0, 0);
    $pdf->SetFont('Arial', '', 9);
    $pdf->SetTextColor(40, 40, 60);
    $pdf->Cell($colW - 28, 7, $dispute['student_email'], 0, 1);
    
    $afterLeftY = $pdf->GetY();
    
    // RIGHT: Tutor
    $pdf->SetXY(110, $startY);
    $pdf->SetFillColor(253, 242, 248);
    $pdf->SetTextColor(60, 80, 120);
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->Cell($colW, 9, '  Tutor', 0, 1, 'L', true);
    $pdf->SetTextColor(40, 40, 60);
    $pdf->Ln(2);
    
    $pdf->SetXY(110, $pdf->GetY());
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->SetTextColor(120, 140, 180);
    $pdf->Cell(28, 7, 'Name', 0, 0);
    $pdf->SetFont('Arial', '', 9);
    $pdf->SetTextColor(40, 40, 60);
    $pdf->Cell($colW - 28, 7, $dispute['tutor_name'], 0, 1);
    $pdf->SetXY(110, $pdf->GetY());
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->SetTextColor(120, 140, 180);
    $pdf->Cell(28, 7, 'Email', 0, 0);
    $pdf->SetFont('Arial', '', 9);
    $pdf->SetTextColor(40, 40, 60);
    $pdf->Cell($colW - 28, 7, $dispute['tutor_email'], 0, 1);
    
    $pdf->SetY(max($afterLeftY, $pdf->GetY()) + 6);
    
    // ── REFUND AMOUNT BOX ────────────────────────────────────────
    $pdf->SetFillColor(254, 242, 248);
    $pdf->SetTextColor(231, 90, 155);
    $pdf->SetFont('Arial', 'B', 16);
    $pdf->Cell(0, 16, 'REFUND AMOUNT: RM ' . number_format($refund_amount, 2), 0, 1, 'C', true);
    $pdf->Ln(6);
    
    // ── SESSION DETAILS ──────────────────────────────────
    $booking_date = date('d F Y', strtotime($dispute['booking_date']));
    $booking_time = date('g:i A', strtotime($dispute['booking_time']));
    
    $pdf->SetFillColor(253, 242, 248);
    $pdf->SetTextColor(60, 80, 120);
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->Cell(0, 9, '  Session Details', 0, 1, 'L', true);
    $pdf->Ln(2);
    
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->SetTextColor(120, 140, 180);
    $pdf->Cell(55, 8, 'Language', 0, 0);
    $pdf->SetFont('Arial', '', 9);
    $pdf->SetTextColor(40, 40, 60);
    $pdf->Cell(0, 8, $dispute['language'], 0, 1);
    
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->SetTextColor(120, 140, 180);
    $pdf->Cell(55, 8, 'Mode', 0, 0);
    $pdf->SetFont('Arial', '', 9);
    $pdf->SetTextColor(40, 40, 60);
    $pdf->Cell(0, 8, $dispute['learning_mode'] === 'online' ? 'Online' : 'Face to Face', 0, 1);
    
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->SetTextColor(120, 140, 180);
    $pdf->Cell(55, 8, 'Date', 0, 0);
    $pdf->SetFont('Arial', '', 9);
    $pdf->SetTextColor(40, 40, 60);
    $pdf->Cell(0, 8, $booking_date, 0, 1);
    
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->SetTextColor(120, 140, 180);
    $pdf->Cell(55, 8, 'Time', 0, 0);
    $pdf->SetFont('Arial', '', 9);
    $pdf->SetTextColor(40, 40, 60);
    $pdf->Cell(0, 8, $booking_time, 0, 1);
    
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->SetTextColor(120, 140, 180);
    $pdf->Cell(55, 8, 'Issue Type', 0, 0);
    $pdf->SetFont('Arial', '', 9);
    $pdf->SetTextColor(40, 40, 60);
    $pdf->Cell(0, 8, ucfirst(str_replace('_', ' ', $dispute['issue_type'])), 0, 1);
    $pdf->Ln(6);
    
    // ── PAYMENT DETAILS ──────────────────────────────────
    $pdf->SetFillColor(255, 245, 250);
    $pdf->SetTextColor(60, 80, 120);
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->Cell(0, 9, '  Payment Details', 0, 1, 'L', true);
    $pdf->Ln(2);
    
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->SetTextColor(120, 140, 180);
    $pdf->Cell(55, 8, 'Original Amount', 0, 0);
    $pdf->SetFont('Arial', '', 9);
    $pdf->SetTextColor(40, 40, 60);
    $pdf->Cell(0, 8, 'RM ' . number_format($dispute['total_amount'], 2), 0, 1);
    
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->SetTextColor(120, 140, 180);
    $pdf->Cell(55, 8, 'Refund Amount', 0, 0);
    $pdf->SetFont('Arial', '', 9);
    $pdf->SetTextColor(231, 90, 155);
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Cell(0, 8, 'RM ' . number_format($refund_amount, 2), 0, 1);
    
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->SetTextColor(120, 140, 180);
    $pdf->Cell(55, 8, 'Refund Processed', 0, 0);
    $pdf->SetFont('Arial', '', 9);
    $pdf->SetTextColor(40, 40, 60);
    $pdf->Cell(0, 8, date('d M Y, g:i A'), 0, 1);
    $pdf->Ln(6);
    
    // ── REFUND BADGE ───────────────────────────────────
    $pdf->SetFillColor(253, 242, 248);
    $pdf->SetTextColor(231, 90, 155);
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->Cell(0, 10, '✓ Refund Successfully Processed', 0, 1, 'C', true);
    $pdf->Ln(6);
    
    // ── NOTE BOX ───────────────────────────────────────────
    $pdf->SetFillColor(240, 249, 255);
    $pdf->SetTextColor(7, 89, 133);
    $pdf->SetFont('Arial', '', 9);
    $pdf->MultiCell(0, 6, '📌 IMPORTANT: The refund amount has been credited back to your original payment method. Please allow 3-5 business days for the refund to appear in your account.', 0, 'L', true);
    $pdf->Ln(4);
    
    // ── FOOTER ───────────────────────────────────────────
    $pdf->SetDrawColor(231, 90, 155);
    $pdf->SetLineWidth(0.5);
    $pdf->Line(15, $pdf->GetY(), 195, $pdf->GetY());
    $pdf->Ln(4);
    $pdf->SetFont('Arial', 'I', 9);
    $pdf->SetTextColor(140, 160, 190);
    $pdf->Cell(0, 6, 'Thank you for learning with Kyoshi!', 0, 1, 'C');
    $pdf->Cell(0, 5, 'For support: support@kyoshi.com', 0, 1, 'C');
    
    // Save PDF
    $pdf->Output('F', $pdf_path);
    
    return $pdf_path;
}

// Update sendRefundEmailWithPDF to use HTML receipt instead of PDF
function sendRefundEmailWithPDF($to, $name, $amount, $dispute, $receipt_no, $pdf_path) {
    $mail = new PHPMailer(true);
    $subject = "Refund Processed for Your Session - Kyoshi";
    
    $booking_date = date('d F Y', strtotime($dispute['booking_date']));
    $booking_time = date('g:i A', strtotime($dispute['booking_time']));
    $refundAmount = 'RM ' . number_format($amount, 2);
    
    $body = "
    <div style='font-family:Segoe UI,sans-serif;max-width:600px;margin:auto;border:1px solid #e0e0e0;border-radius:16px;overflow:hidden;'>
        <div style='background:#1d3156;padding:25px 30px;text-align:center;'>
            <h2 style='color:white;margin:0;font-size:22px;'>KYOSHI</h2>
            <p style='color:#c8c8e6;margin:5px 0 0;font-size:12px;'>Language Learning Platform</p>
        </div>
        <div style='height:5px;background:#E75A9B;'></div>
        <div style='padding:25px 30px;background:white;'>
            <div style='background:#d4edda;border:1px solid #28a745;border-radius:10px;padding:12px 16px;margin-bottom:20px;display:flex;align-items:center;gap:10px;'>
                <span style='font-size:20px;color:#28a745;'>✓</span>
                <div>
                    <div style='font-size:13px;font-weight:700;color:#28a745;'>REFUND SUCCESSFULLY PROCESSED</div>
                    <div style='font-size:11px;color:#155724;'>The refund amount has been credited back to your payment method.</div>
                </div>
            </div>
            
            <div style='text-align:center;margin-bottom:20px;'>
                <div style='font-size:14px;font-weight:700;color:#1d3156;'>REFUND CONFIRMATION</div>
                <div style='font-size:11px;color:#94a3b8;'>Refund ID: {$receipt_no}</div>
                <div style='font-size:11px;color:#94a3b8;'>Processed on: " . date('d F Y, g:i A') . "</div>
            </div>
            
            <hr style='border-color:#E75A9B;margin:15px 0;'>
            
            <div style='margin-bottom:20px;'>
                <div style='background:#f8fafc;border-radius:12px;padding:15px;margin-bottom:15px;'>
                    <h4 style='color:#E75A9B;font-size:11px;margin-bottom:10px;'>SESSION DETAILS</h4>
                    <p style='font-size:12px;margin:5px 0;'><strong>Language:</strong> {$dispute['language']}</p>
                    <p style='font-size:12px;margin:5px 0;'><strong>Tutor:</strong> {$dispute['tutor_name']}</p>
                    <p style='font-size:12px;margin:5px 0;'><strong>Session Date:</strong> $booking_date at $booking_time</p>
                    <p style='font-size:12px;margin:5px 0;'><strong>Issue Type:</strong> " . ucfirst(str_replace('_', ' ', $dispute['issue_type'])) . "</p>
                </div>
            </div>
            
            <div style='background:#E75A9B;border-radius:10px;padding:15px 25px;text-align:center;margin:20px 0;'>
                <div style='font-size:11px;font-weight:700;color:rgba(255,255,255,0.8);letter-spacing:1px;'>REFUND AMOUNT</div>
                <div style='font-size:28px;font-weight:800;color:white;margin-top:5px;'>{$refundAmount}</div>
            </div>
            
            <div style='background:#d4edda;border:1px solid #28a745;border-radius:10px;padding:15px;margin:20px 0;'>
                <div style='font-size:12px;font-weight:700;color:#28a745;margin-bottom:6px;'>✓ REFUND CONFIRMATION</div>
                <div style='font-size:11px;color:#155724;'>This refund has been successfully processed and credited back to your original payment method.</div>
                <div style='font-size:11px;color:#155724;margin-top:5px;'>Please allow 3-5 business days for the refund to appear in your account.</div>
            </div>
            
            <div style='text-align:center;font-size:10px;color:#94a3b8;margin-top:20px;padding-top:15px;border-top:1px solid #e2e8f0;'>
                This is an official refund receipt from Kyoshi.<br>
                For support: support@kyoshi.com<br>
                © " . date('Y') . " Kyoshi Language Learning Platform
            </div>
        </div>
    </div>";
    
    try {
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = SMTP_USER;
        $mail->Password = SMTP_PASS;
        $mail->SMTPSecure = 'tls';
        $mail->Port = 587;
        $mail->setFrom('noreply@kyoshi.com', 'Kyoshi');
        $mail->addAddress($to);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $body;
        
        // Attach PDF file
        if ($pdf_path && file_exists($pdf_path)) {
            $mail->addAttachment($pdf_path, 'Refund_Receipt_' . $receipt_no . '.pdf');
        }
        
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Refund email failed: " . $e->getMessage());
        return false;
    }
}
function sendTutorRefundNotificationEmail($to, $name, $dispute, $amount) {
    $mail = new PHPMailer(true);
    $subject = "Session Cancelled - Refund Issued - Kyoshi";
    
    $booking_date = date('d F Y', strtotime($dispute['booking_date']));
    $booking_time = date('g:i A', strtotime($dispute['booking_time']));
    
    $body = "
    <div style='font-family: Arial, sans-serif; max-width: 600px; margin: auto; padding: 20px; border: 1px solid #eee; border-radius: 10px;'>
        <div style='text-align: center; border-bottom: 2px solid #E75A9B; padding-bottom: 15px; margin-bottom: 20px;'>
            <h2 style='color: #E75A9B;'>Kyoshi</h2>
            <h3 style='color: #dc2626;'>Session Cancelled - Refund Issued</h3>
        </div>
        
        <p>Dear <strong>" . htmlspecialchars($name) . "</strong>,</p>
        
        <p>The following session has been cancelled and a full refund of <strong>RM " . number_format($amount, 2) . "</strong> has been issued to the student due to a dispute.</p>
        
        <div style='background: #f8f9fa; padding: 15px; border-radius: 8px; margin: 15px 0;'>
            <p><strong>Booking ID:</strong> #{$dispute['booking_id']}</p>
            <p><strong>Language:</strong> " . htmlspecialchars($dispute['language']) . "</p>
            <p><strong>Student:</strong> " . htmlspecialchars($dispute['student_name']) . "</p>
            <p><strong>Original Session:</strong> $booking_date at $booking_time</p>
            <p><strong>Issue Type:</strong> " . ucfirst(str_replace('_', ' ', $dispute['issue_type'])) . "</p>
        </div>
        
        <p><strong>Note:</strong> This session will not be paid out as it was cancelled due to the dispute.</p>
        
        <hr style='margin: 20px 0;'>
        <p style='font-size: 12px; color: #666;'>- Kyoshi Team</p>
    </div>";
    
    try {
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = SMTP_USER;
        $mail->Password = SMTP_PASS;
        $mail->SMTPSecure = 'tls';
        $mail->Port = 587;
        $mail->setFrom('noreply@kyoshi.com', 'Kyoshi');
        $mail->addAddress($to);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $body;
        $mail->send();
    } catch (Exception $e) {
        error_log("Tutor refund email failed: " . $e->getMessage());
    }
}
function sendRescheduleEmail($to, $name, $tutor_name, $new_date, $new_time, $dispute) {
    $mail = new PHPMailer(true);
    $subject = "Session Rescheduled - Kyoshi";
    
    // Format dates and times
    $oldDate = date('d F Y', strtotime($dispute['booking_date']));
    $oldTime = date('g:i A', strtotime($dispute['booking_time']));
    $newDateFormatted = date('d F Y', strtotime($new_date));
    $newTimeFormatted = date('g:i A', strtotime($new_time));
    
    $body = "
    <div style='font-family: Arial, sans-serif; max-width: 600px; margin: auto; padding: 20px; border: 1px solid #eee; border-radius: 10px;'>
        <div style='text-align: center; border-bottom: 2px solid #E75A9B; padding-bottom: 15px; margin-bottom: 20px;'>
            <h2 style='color: #E75A9B;'>Kyoshi</h2>
            <h3 style='color: #f59e0b;'>Session Rescheduled</h3>
        </div>
        
        <p>Dear <strong>" . htmlspecialchars($name) . "</strong>,</p>
        
        <p>Your session has been rescheduled due to a dispute resolution.</p>
        
        <div style='background: #f8f9fa; padding: 15px; border-radius: 8px; margin: 15px 0;'>
            <p><strong>❌ Original Session:</strong></p>
            <p style='font-size: 16px; font-weight: bold; color: #dc2626;'>$oldDate at $oldTime</p>
            
            <hr style='margin: 10px 0; border-color: #ddd;'>
            
            <p><strong>✅ New Session Time:</strong></p>
            <p style='font-size: 18px; font-weight: bold; color: #059669;'>$newDateFormatted at $newTimeFormatted</p>
        </div>
        
        <div style='background: #e8f0fe; padding: 15px; border-radius: 8px; margin: 15px 0;'>
            <p><strong>Tutor:</strong> " . htmlspecialchars($tutor_name) . "</p>
            <p><strong>Language:</strong> " . htmlspecialchars($dispute['language']) . "</p>
            <p><strong>Learning Mode:</strong> " . ($dispute['learning_mode'] === 'online' ? 'Online' : 'Face to Face') . "</p>
        </div>
        
        <p>Please log in to your account to view the meeting link or location details.</p>
        
        <hr style='margin: 20px 0;'>
        <p style='font-size: 12px; color: #666;'>- Kyoshi Team</p>
    </div>";
    
    try {
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = SMTP_USER;
        $mail->Password = SMTP_PASS;
        $mail->SMTPSecure = 'tls';
        $mail->Port = 587;
        $mail->setFrom('noreply@kyoshi.com', 'Kyoshi');
        $mail->addAddress($to);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $body;
        
        $mail->send();
        error_log("Reschedule email sent successfully to: $to");
        return true;
    } catch (Exception $e) {
        error_log("Reschedule email FAILED to: $to - Error: " . $mail->ErrorInfo);
        return false;
    }
}
    function sendDisputeRejectedEmail($to, $name, $tutor_name, $dispute, $reason, $dispute_type) {
    $mail = new PHPMailer(true);
    $subject = "Dispute Review Complete - Kyoshi";
    
    $booking_date = date('d F Y', strtotime($dispute['booking_date']));
    $booking_time = date('g:i A', strtotime($dispute['booking_time']));
    
    if ($dispute_type === 'payment') {
        $action_taken = "Your payment dispute has been reviewed and rejected. The booking has been cancelled. Please make a new booking with the correct payment amount.";
        $status_color = "#dc2626";
        $status_title = "Payment Dispute Rejected";
    } else {
        $action_taken = "Your booking dispute has been reviewed and rejected. Your session will proceed as originally scheduled.";
        $status_color = "#f59e0b";
        $status_title = "Booking Dispute Rejected";
    }
    
    $body = "
    <div style='font-family: Arial, sans-serif; max-width: 600px; margin: auto; padding: 20px; border: 1px solid #eee; border-radius: 10px;'>
        <div style='text-align: center; border-bottom: 2px solid #E75A9B; padding-bottom: 15px; margin-bottom: 20px;'>
            <h2 style='color: #E75A9B;'>Kyoshi</h2>
            <h3 style='color: {$status_color};'>{$status_title}</h3>
        </div>
        
        <p>Dear <strong>" . htmlspecialchars($name) . "</strong>,</p>
        
        <p>After reviewing your dispute report, the admin has determined that your dispute is rejected.</p>
        
        <div style='background: #f8f9fa; padding: 15px; border-radius: 8px; margin: 15px 0;'>
            <p><strong>Booking ID:</strong> #{$dispute['booking_id']}</p>
            <p><strong>Tutor:</strong> " . htmlspecialchars($tutor_name) . "</p>
            <p><strong>Language:</strong> " . htmlspecialchars($dispute['language']) . "</p>
            <p><strong>Session Date:</strong> $booking_date at $booking_time</p>
            <p><strong>Issue Type:</strong> " . ucfirst(str_replace('_', ' ', $dispute['issue_type'])) . "</p>
            " . (!empty($reason) ? "<p><strong>Admin's Reason:</strong> " . htmlspecialchars($reason) . "</p>" : "") . "
        </div>
        
        <div style='background: #fef2f2; padding: 15px; border-radius: 8px; margin: 15px 0; border-left: 4px solid #dc2626;'>
            <p><strong>Action Taken:</strong> {$action_taken}</p>
        </div>
        
        <p>If you have any questions, please contact our support team.</p>
        
        <hr style='margin: 20px 0;'>
        <p style='font-size: 12px; color: #666;'>- Kyoshi Team</p>
    </div>";
    
    try {
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = SMTP_USER;
        $mail->Password = SMTP_PASS;
        $mail->SMTPSecure = 'tls';
        $mail->Port = 587;
        $mail->setFrom('noreply@kyoshi.com', 'Kyoshi');
        $mail->addAddress($to);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $body;
        $mail->send();
    } catch (Exception $e) {
        error_log("Dispute rejected email failed: " . $e->getMessage());
    }
}

function sendTutorDisputeRejectedEmail($to, $name, $student_name, $dispute, $reason, $dispute_type) {
    $mail = new PHPMailer(true);
    $subject = "Dispute Resolved in Your Favor - Kyoshi";
    
    $booking_date = date('d F Y', strtotime($dispute['booking_date']));
    $booking_time = date('g:i A', strtotime($dispute['booking_time']));
    
    if ($dispute_type === 'payment') {
        $action_taken = "The student's payment dispute has been rejected. The booking has been cancelled. The student needs to make a new booking with correct payment.";
        $status_color = "#28a745";
    } else {
        $action_taken = "The student's booking dispute has been rejected. The session will proceed as originally scheduled.";
        $status_color = "#28a745";
    }
    
    $body = "
    <div style='font-family: Arial, sans-serif; max-width: 600px; margin: auto; padding: 20px; border: 1px solid #eee; border-radius: 10px;'>
        <div style='text-align: center; border-bottom: 2px solid #E75A9B; padding-bottom: 15px; margin-bottom: 20px;'>
            <h2 style='color: #E75A9B;'>Kyoshi</h2>
            <h3 style='color: {$status_color};'>Dispute Resolved in Your Favor</h3>
        </div>
        
        <p>Dear <strong>" . htmlspecialchars($name) . "</strong>,</p>
        
        <p>The dispute reported by <strong>" . htmlspecialchars($student_name) . "</strong> has been reviewed and rejected by the admin.</p>
        
        <div style='background: #f8f9fa; padding: 15px; border-radius: 8px; margin: 15px 0;'>
            <p><strong>Booking ID:</strong> #{$dispute['booking_id']}</p>
            <p><strong>Student:</strong> " . htmlspecialchars($student_name) . "</p>
            <p><strong>Language:</strong> " . htmlspecialchars($dispute['language']) . "</p>
            <p><strong>Session Date:</strong> $booking_date at $booking_time</p>
            <p><strong>Issue Type:</strong> " . ucfirst(str_replace('_', ' ', $dispute['issue_type'])) . "</p>
            " . (!empty($reason) ? "<p><strong>Admin's Reason:</strong> " . htmlspecialchars($reason) . "</p>" : "") . "
        </div>
        
        <div style='background: #f0fdf4; padding: 15px; border-radius: 8px; margin: 15px 0; border-left: 4px solid #28a745;'>
            <p><strong>Action Taken:</strong> {$action_taken}</p>
        </div>
        
        <p>No further action is required from you.</p>
        
        <hr style='margin: 20px 0;'>
        <p style='font-size: 12px; color: #666;'>- Kyoshi Team</p>
    </div>";
    
    try {
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = SMTP_USER;
        $mail->Password = SMTP_PASS;
        $mail->SMTPSecure = 'tls';
        $mail->Port = 587;
        $mail->setFrom('noreply@kyoshi.com', 'Kyoshi');
        $mail->addAddress($to);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $body;
        $mail->send();
    } catch (Exception $e) {
        error_log("Tutor dispute rejected email failed: " . $e->getMessage());
    }
}

function sendTutorRescheduleEmail($to, $name, $student_name, $new_date, $new_time, $dispute) {
    $mail = new PHPMailer(true);
    $subject = "Session Rescheduled - Student Notified - Kyoshi";
    
    // Format dates and times
    $oldDate = date('d F Y', strtotime($dispute['booking_date']));
    $oldTime = date('g:i A', strtotime($dispute['booking_time']));
    $newDateFormatted = date('d F Y', strtotime($new_date));
    $newTimeFormatted = date('g:i A', strtotime($new_time));
    
    $body = "
    <div style='font-family: Arial, sans-serif; max-width: 600px; margin: auto; padding: 20px; border: 1px solid #eee; border-radius: 10px;'>
        <div style='text-align: center; border-bottom: 2px solid #E75A9B; padding-bottom: 15px; margin-bottom: 20px;'>
            <h2 style='color: #E75A9B;'>Kyoshi</h2>
            <h3 style='color: #f59e0b;'>Session Rescheduled</h3>
        </div>
        
        <p>Dear <strong>" . htmlspecialchars($name) . "</strong>,</p>
        
        <p>The session with <strong>" . htmlspecialchars($student_name) . "</strong> has been rescheduled due to a dispute resolution.</p>
        
        <div style='background: #f8f9fa; padding: 15px; border-radius: 8px; margin: 15px 0;'>
            <p><strong>Original Session:</strong></p>
            <p style='font-size: 16px; font-weight: bold; color: #dc2626;'>$oldDate at $oldTime</p>
            
            <hr style='margin: 10px 0; border-color: #ddd;'>
            
            <p><strong>New Session Time:</strong></p>
            <p style='font-size: 18px; font-weight: bold; color: #059669;'>$newDateFormatted at $newTimeFormatted</p>
        </div>
        
        <div style='background: #e8f0fe; padding: 15px; border-radius: 8px; margin: 15px 0;'>
            <p><strong>Language:</strong> " . htmlspecialchars($dispute['language']) . "</p>
            <p><strong>Mode:</strong> " . ($dispute['learning_mode'] === 'online' ? 'Online' : 'Face to Face') . "</p>
        </div>
        
        <p>Please prepare for the session accordingly. The student has been notified.</p>
        
        <hr style='margin: 20px 0;'>
        <p style='font-size: 12px; color: #666;'>- Kyoshi Team</p>
    </div>";
    
    try {
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = SMTP_USER;
        $mail->Password = SMTP_PASS;
        $mail->SMTPSecure = 'tls';
        $mail->Port = 587;
        $mail->setFrom('noreply@kyoshi.com', 'Kyoshi');
        $mail->addAddress($to);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $body;
        
        $mail->send();
        error_log("Tutor reschedule email sent successfully to: $to");
        return true;
    } catch (Exception $e) {
        error_log("Tutor reschedule email FAILED to: $to - Error: " . $mail->ErrorInfo);
        return false;
    }
}
function sendBookingConfirmedEmail($to, $name, $tutor_name, $date, $time, $language) {
    $mail = new PHPMailer(true);
    $subject = "Booking Confirmed - Kyoshi";
    
    $formattedDate = date('d F Y', strtotime($date));
    $formattedTime = date('g:i A', strtotime($time));
    
    $body = "
    <div style='font-family: Arial, sans-serif; max-width: 600px; margin: auto; padding: 20px; border: 1px solid #eee; border-radius: 10px;'>
        <div style='text-align: center; border-bottom: 2px solid #E75A9B; padding-bottom: 15px; margin-bottom: 20px;'>
            <h2 style='color: #E75A9B;'>Kyoshi</h2>
            <h3 style='color: #28a745;'>Booking Confirmed ✓</h3>
        </div>
        
        <p>Dear <strong>" . htmlspecialchars($name) . "</strong>,</p>
        
        <p>Your session has been confirmed for:</p>
        
        <div style='background: #f8f9fa; padding: 15px; border-radius: 8px; margin: 15px 0; text-align: center;'>
            <p style='font-size: 18px; font-weight: bold;'>$formattedDate at $formattedTime</p>
            <p><strong>Tutor:</strong> " . htmlspecialchars($tutor_name) . "</p>
            <p><strong>Language:</strong> " . htmlspecialchars($language) . "</p>
        </div>
        
        <p>The tutor has been notified and will prepare for your session.</p>
        
        <hr style='margin: 20px 0;'>
        <p style='font-size: 12px; color: #666;'>- Kyoshi Team</p>
    </div>";
    
    try {
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = SMTP_USER;
        $mail->Password = SMTP_PASS;
        $mail->SMTPSecure = 'tls';
        $mail->Port = 587;
        $mail->setFrom('noreply@kyoshi.com', 'Kyoshi');
        $mail->addAddress($to);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $body;
        
        $mail->send();
        error_log("Booking confirmed email sent to student: $to");
        return true;
    } catch (Exception $e) {
        error_log("Booking confirmed email FAILED to: $to - Error: " . $mail->ErrorInfo);
        return false;
    }
}
function sendTutorBookingConfirmedEmail($to, $name, $student_name, $date, $time, $language) {
    $mail = new PHPMailer(true);
    $subject = "Booking Confirmed - Student Notified - Kyoshi";
    
    $formattedDate = date('d F Y', strtotime($date));
    $formattedTime = date('g:i A', strtotime($time));
    
    $body = "
    <div style='font-family: Arial, sans-serif; max-width: 600px; margin: auto; padding: 20px; border: 1px solid #eee; border-radius: 10px;'>
        <div style='text-align: center; border-bottom: 2px solid #E75A9B; padding-bottom: 15px; margin-bottom: 20px;'>
            <h2 style='color: #E75A9B;'>Kyoshi</h2>
            <h3 style='color: #28a745;'>Booking Confirmed</h3>
        </div>
        
        <p>Dear <strong>" . htmlspecialchars($name) . "</strong>,</p>
        
        <p>The session with <strong>" . htmlspecialchars($student_name) . "</strong> has been confirmed for:</p>
        
        <div style='background: #f8f9fa; padding: 15px; border-radius: 8px; margin: 15px 0; text-align: center;'>
            <p style='font-size: 18px; font-weight: bold;'>$formattedDate at $formattedTime</p>
            <p><strong>Language:</strong> " . htmlspecialchars($language) . "</p>
        </div>
        
        <p>Please prepare for the session accordingly. The student has been notified.</p>
        
        <hr style='margin: 20px 0;'>
        <p style='font-size: 12px; color: #666;'>- Kyoshi Team</p>
    </div>";
    
    try {
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = SMTP_USER;
        $mail->Password = SMTP_PASS;
        $mail->SMTPSecure = 'tls';
        $mail->Port = 587;
        $mail->setFrom('noreply@kyoshi.com', 'Kyoshi');
        $mail->addAddress($to);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $body;
        
        $mail->send();
        error_log("Booking confirmed email sent to tutor: $to");
        return true;
    } catch (Exception $e) {
        error_log("Tutor booking confirmed email FAILED to: $to - Error: " . $mail->ErrorInfo);
        return false;
    }
}

function sendIssueResolvedEmail($to, $name, $tutor_name, $dispute) {
    $mail = new PHPMailer(true);
    $subject = "Issue Resolved - Kyoshi";
    
    $body = "
    <div style='font-family: Arial, sans-serif; max-width: 600px; margin: auto; padding: 20px; border: 1px solid #eee; border-radius: 10px;'>
        <div style='text-align: center; border-bottom: 2px solid #E75A9B; padding-bottom: 15px; margin-bottom: 20px;'>
            <h2 style='color: #E75A9B;'>Kyoshi</h2>
            <h3 style='color: #28a745;'>Issue Resolved ✓</h3>
        </div>
        
        <p>Dear <strong>" . htmlspecialchars($name) . "</strong>,</p>
        
        <p>The issue you reported for your session has been resolved.</p>
        
        <div style='background: #f8f9fa; padding: 15px; border-radius: 8px; margin: 15px 0;'>
            <p><strong>Issue Type:</strong> " . ucfirst(str_replace('_', ' ', $dispute['issue_type'])) . "</p>
            <p><strong>Tutor:</strong> " . htmlspecialchars($tutor_name) . "</p>
            <p><strong>Language:</strong> " . htmlspecialchars($dispute['language']) . "</p>
        </div>
        
        <p>You can now continue with your learning journey.</p>
        
        <hr style='margin: 20px 0;'>
        <p style='font-size: 12px; color: #666;'>- Kyoshi Team</p>
    </div>";
    
    try {
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = SMTP_USER;
        $mail->Password = SMTP_PASS;
        $mail->SMTPSecure = 'tls';
        $mail->Port = 587;
        $mail->setFrom('noreply@kyoshi.com', 'Kyoshi');
        $mail->addAddress($to);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $body;
        $mail->send();
    } catch (Exception $e) {
        error_log("Issue resolved email failed: " . $e->getMessage());
    }
}

function sendTutorIssueResolvedEmail($to, $name, $student_name, $dispute) {
    $mail = new PHPMailer(true);
    $subject = "Issue Resolved - Kyoshi";
    
    $body = "
    <div style='font-family: Arial, sans-serif; max-width: 600px; margin: auto; padding: 20px; border: 1px solid #eee; border-radius: 10px;'>
        <div style='text-align: center; border-bottom: 2px solid #E75A9B; padding-bottom: 15px; margin-bottom: 20px;'>
            <h2 style='color: #E75A9B;'>Kyoshi</h2>
            <h3 style='color: #28a745;'>Issue Resolved</h3>
        </div>
        
        <p>Dear <strong>" . htmlspecialchars($name) . "</strong>,</p>
        
        <p>The issue reported by <strong>" . htmlspecialchars($student_name) . "</strong> for your session has been marked as resolved.</p>
        
        <div style='background: #f8f9fa; padding: 15px; border-radius: 8px; margin: 15px 0;'>
            <p><strong>Issue Type:</strong> " . ucfirst(str_replace('_', ' ', $dispute['issue_type'])) . "</p>
            <p><strong>Language:</strong> " . htmlspecialchars($dispute['language']) . "</p>
        </div>
        
        <p>Thank you for resolving this matter.</p>
        
        <hr style='margin: 20px 0;'>
        <p style='font-size: 12px; color: #666;'>- Kyoshi Team</p>
    </div>";
    
    try {
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = SMTP_USER;
        $mail->Password = SMTP_PASS;
        $mail->SMTPSecure = 'tls';
        $mail->Port = 587;
        $mail->setFrom('noreply@kyoshi.com', 'Kyoshi');
        $mail->addAddress($to);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $body;
        $mail->send();
    } catch (Exception $e) {
        error_log("Tutor issue resolved email failed: " . $e->getMessage());
    }
}

function sendDisputeResolvedNotification($conn, $dispute, $resolution_detail) {
    // Insert notification for student
    $studentMsg = "Your dispute has been resolved. " . $resolution_detail;
    insertNotification($conn, $dispute['student_id'], "Dispute Resolved", $studentMsg, "dispute", "booking_detail.php?id={$dispute['booking_id']}&resolved=1");
    
    // Insert notification for tutor
    $tutorMsg = "The dispute for session #{$dispute['booking_id']} has been resolved. " . $resolution_detail;
    insertNotification($conn, $dispute['tutor_id'], "Dispute Resolved", $tutorMsg, "dispute", "tutor_booking_detail.php?id={$dispute['booking_id']}");
}

// Get filter parameters
$filter_status = $_GET['status'] ?? 'pending';
$filter_type = $_GET['type'] ?? 'all';
$search = $_GET['search'] ?? '';

$sql = "SELECT d.*, 
        s.fullname as student_name, s.email as student_email, s.phone as student_phone,
        t.fullname as tutor_name, t.email as tutor_email,
        b.language, b.booking_date, b.booking_time, b.total_amount, b.learning_mode,
        p.amount as payment_amount, p.payment_method, p.receipt_number,
        p.refund_receipt_number,
        d.resolution_type,
        d.resolution_requested,
        d.preferred_date,
        d.preferred_time,
        d.bank_name,
        d.bank_account_number,
        d.bank_account_name,
        CASE 
            WHEN d.issue_type IN ('tutor_no_show', 'student_no_show', 'harassment', 'fraud') THEN 'serious'
            ELSE 'minor'
        END as severity
        FROM disputes d
        JOIN users s ON d.student_id = s.id
        JOIN users t ON d.tutor_id = t.id
        JOIN bookings b ON d.booking_id = b.id
        LEFT JOIN payments p ON d.payment_id = p.id
        WHERE 1=1
        AND (
            -- For wrong_materials, only show if older than 2 days OR escalated OR resolved
            (d.issue_type = 'wrong_materials' AND (d.created_at < DATE_SUB(NOW(), INTERVAL 2 DAY) OR d.status IN ('escalated', 'resolved')))
            OR
            -- For all other issues, show normally
            d.issue_type != 'wrong_materials'
        )";
if ($filter_status !== 'all') {
    $sql .= " AND d.status = '$filter_status'";
}
if ($filter_type !== 'all') {
    $sql .= " AND d.dispute_type = '$filter_type'";
}
if (!empty($search)) {
    $search_like = $conn->real_escape_string($search);
    $sql .= " AND (s.fullname LIKE '%$search_like%' OR t.fullname LIKE '%$search_like%' OR d.issue_type LIKE '%$search_like%')";
}

$sql .= " ORDER BY d.created_at DESC";
$disputes = $conn->query($sql);

// Count statistics
// Count pending disputes (excluding wrong_materials that are less than 2 days old)
$pending_count_query = $conn->query("
    SELECT COUNT(*) as count 
    FROM disputes 
    WHERE status = 'pending'
    AND NOT (
        issue_type = 'wrong_materials' 
        AND created_at >= DATE_SUB(NOW(), INTERVAL 2 DAY)
    )
");
$pending_count = $pending_count_query->fetch_assoc()['count'];
$resolved_count = $conn->query("SELECT COUNT(*) as count FROM disputes WHERE status = 'resolved'")->fetch_assoc()['count'];
$escalated_count = $conn->query("SELECT COUNT(*) as count FROM disputes WHERE status = 'escalated'")->fetch_assoc()['count'];

function e($value) {
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function getSeverityBadge($severity) {
    if ($severity === 'serious') {
        return '<span class="badge-critical">CRITICAL</span>';
    }
    return '<span class="badge-minor">MINOR</span>';
}

function getStatusBadge($status) {
    switch($status) {
        case 'pending': return '<span class="badge-pending">PENDING</span>';
        case 'resolved': return '<span class="badge-resolved">RESOLVED</span>';
        case 'escalated': return '<span class="badge-escalated">ESCALATED</span>';
        case 'rejected': return '<span class="badge-rejected">REJECTED</span>';
        default: return '<span class="badge-pending">PENDING</span>';
    }
}

function getDisputeTypeLabel($type, $issue_type) {
    if ($type === 'payment') {
        return '<span class="badge-payment">💰Payment Dispute</span>';
    }
    if ($issue_type === 'tutor_no_show') {
        return '<span class="badge-no-show">🚫Tutor No-Show</span>';
    }
    if ($issue_type === 'wrong_materials') {
        return '<span class="badge-materials">📚Wrong Materials</span>';
    }
    return '<span class="badge-booking">📅Booking Dispute</span>';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Disputes Management · Admin</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../css/astyle.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        /* Refund Receipt Modal Styles */
        /* Fix notification to appear above modal */
.toast, 
#toast,
.swal2-container,
.alert-success {
    z-index: 10000 !important;
    position: fixed !important;
}

/* Make sure modal doesn't block notifications */
.modal-overlay {
    z-index: 9999 !important;
}

/* Ensure notifications are above everything */
.toast.show {
    z-index: 10001 !important;
}

/* For SweetAlert2 */
.swal2-popup {
    z-index: 10002 !important;
}
#refundReceiptModal {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.7);
    z-index: 9999;
    display: flex;
    align-items: center;
    justify-content: center;
    visibility: hidden;
    opacity: 0;
    transition: all 0.3s ease;
}

#refundReceiptModal.active {
    visibility: visible;
    opacity: 1;
}

#refundReceiptModal .modal-container {
    background: white;
    border-radius: 24px;
    width: 90%;
    max-width: 560px;
    max-height: 85vh;
    overflow: hidden;
    display: flex;
    flex-direction: column;
    box-shadow: 0 20px 40px rgba(0,0,0,0.2);
}

#refundReceiptModal .modal-body {
    flex: 1;
    overflow-y: auto;
}

#refundReceiptModal .modal-buttons {
    padding: 16px 24px;
    border-top: 1px solid #e2e8f0;
    display: flex;
    justify-content: flex-end;
    gap: 12px;
}

#refundReceiptModal .btn-cancel {
    background: #e2e8f0;
    color: #475569;
    padding: 10px 20px;
    border-radius: 40px;
    border: none;
    cursor: pointer;
    font-weight: 600;
}

#refundReceiptModal .btn-save {
    background: #E75A9B;
    color: white;
    padding: 10px 24px;
    border-radius: 40px;
    border: none;
    cursor: pointer;
    font-weight: 600;
}

#refundReceiptModal .btn-cancel:hover,
#refundReceiptModal .btn-save:hover {
    transform: translateY(-2px);
    transition: all 0.2s;
}

#refundReceiptModal .modal-close {
    background: rgba(255,255,255,0.15);
    border: none;
    font-size: 28px;
    cursor: pointer;
    color: rgba(255,255,255,0.7);
    width: 36px;
    height: 36px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    transition: all 0.2s;
}

#refundReceiptModal .modal-close:hover {
    background: rgba(255,255,255,0.3);
    color: white;
}
        * { margin: 0; padding: 0; box-sizing: border-box; }
        /* Availability check styling */
.availability-status {
    margin-top: 10px;
    padding: 10px 12px;
    border-radius: 10px;
    font-size: 12px;
    display: flex;
    align-items: center;
    gap: 8px;
}
.availability-status.available {
    background: #d1fae5;
    border: 1px solid #059669;
    color: #065f46;
}
.availability-status.unavailable {
    background: #fee2e2;
    border: 1px solid #dc2626;
    color: #991b1b;
}
.availability-status i {
    font-size: 14px;
}
        body {
            font-family: "Montserrat", "Open Sans", sans-serif;
            background: url('../assets/img/background3.jpg') no-repeat center top;
            background-size: cover;
            min-height: 100vh;
            color: #1E1B2E;
            line-height: 1.45;
            overflow-x: hidden;
        }
        .btn-reject {
    background: #dc2626;
    color: white;
    padding: 10px 24px;
    border-radius: 40px;
    border: none;
    cursor: pointer;
    font-weight: 600;
    transition: 0.2s;
}
.btn-reject:hover {
    background: #b91c1c;
    transform: translateY(-1px);
}
        .sidebar {
            position: fixed;
            left: 0;
            top: 0;
            width: 230px;
            height: 100vh;
            background: #272754;
            color: #E8E4F0;
            overflow-y: hidden;
            z-index: 1000;
            transition: transform 0.3s ease;
            transform: translateX(0);
            display: flex;
            flex-direction: column;
        }
        .sidebar.closed { transform: translateX(-100%); }
        .sidebar.open { transform: translateX(0); }
        .sidebar-header { padding: 28px 20px; flex-shrink: 0; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .sidebar-footer {
            padding: 20px;
            border-top: 1px solid rgba(255,255,255,0.15);
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }
        .admin-info { display: flex; align-items: center; gap: 12px; flex: 1; overflow: hidden; }
        .brand-wrapper { display: flex; align-items: center; gap: 12px; }
        .brand-icon { width: 60px; height: 60px; object-fit: contain; }
        .brand-title h1 {
            font-size: 1.4rem;
            font-weight: 700;
            background: linear-gradient(135deg, #ffffff, #B26EA7);
            background-clip: text;
            -webkit-background-clip: text;
            color: transparent;
        }
        .admin-space-text { font-size: 0.6rem; color: #e7c7f7; }
        .nav-menu { padding: 16px 0; flex: 1; overflow-y: auto; min-height: 0; }
        .nav-menu::-webkit-scrollbar { width: 3px; }
        .nav-menu::-webkit-scrollbar-track { background: rgba(255,255,255,0.1); }
        .nav-menu::-webkit-scrollbar-thumb { background: #B26EA7; border-radius: 3px; }
        .menu-toggle {
            background: #272754;
            color: white;
            border: none;
            padding: 8px 12px;
            border-radius: 10px;
            cursor: pointer;
            display: none;
            font-size: 1.1rem;
        }
        .nav-item {
            padding: 10px 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            color: #D4CFE8;
            text-decoration: none;
            transition: all 0.2s;
            border-left: 3px solid transparent;
            font-size: 0.85rem;
        }
        .nav-item:hover { background: rgba(255,255,255,0.08); color: white; }
        .nav-item.active { background: rgba(255,255,255,0.1); border-left-color: #B26EA7; color: white; }
        .nav-item i { width: 20px; font-size: 1.1rem; }
        .nav-section { margin-bottom: 8px; }
        .nav-section-label {
            padding: 12px 20px 6px 20px;
            font-size: 0.65rem;
            font-weight: 600;
            color: #B26EA7;
            text-transform: uppercase;
        }
        .nav-badge {
            margin-left: auto;
            font-size: 0.65rem;
            background: rgba(178, 110, 167, 0.25);
            padding: 2px 8px;
            border-radius: 30px;
        }
        .footer-avatar { width: 40px; height: 40px; border-radius: 50%; object-fit: cover; border: 2px solid rgba(255,255,255,0.2); }
        .admin-name { font-size: 0.8rem; font-weight: 600; color: white; }
        .logout-icon {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 36px;
            height: 36px;
            background: rgba(220, 38, 38, 0.15);
            border-radius: 10px;
            color: #FFA3A3;
            text-decoration: none;
            transition: all 0.2s;
        }
        .logout-icon:hover { background: rgba(220, 38, 38, 0.4); color: white; }
        .main-content {
            margin-left: 230px;
            padding: 20px 24px;
            transition: margin-left 0.3s ease;
            height: 100vh;
            overflow-y: auto;
        }
        .main-content::-webkit-scrollbar { width: 8px; }
        .main-content::-webkit-scrollbar-track { background: #f1f1f1; border-radius: 10px; }
        .main-content::-webkit-scrollbar-thumb { background: #E75A9B; border-radius: 10px; }
        .top-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 16px;
            margin-bottom: 24px;
        }
        .page-title h1 { font-size: 1.4rem; font-weight: 700; color: #302E63; }
        .page-title p { font-size: 0.75rem; color: #7B6E8F; margin-top: 4px; }
        .admin-profile {
            display: flex;
            align-items: center;
            gap: 10px;
            background: white;
            padding: 6px 14px 6px 10px;
            border-radius: 50px;
            cursor: pointer;
            border: 1px solid #E4DCF0;
            position: relative;
        }
        .admin-profile img { width: 36px; height: 36px; border-radius: 50%; object-fit: cover; }
        .admin-profile span { font-weight: 600; font-size: 0.8rem; color: #302E63; }
        .dropdown {
            position: absolute;
            top: calc(100% + 10px);
            right: 0;
            width: 200px;
            background: white;
            border-radius: 14px;
            overflow: hidden;
            display: none;
            border: 1px solid #E4DCF0;
            box-shadow: 0 15px 35px rgba(0,0,0,0.15);
            z-index: 1000;
        }
        .dropdown a { display: flex; align-items: center; gap: 12px; padding: 10px 16px; text-decoration: none; color: #1E1B2E; font-size: 13px; font-weight: 500; transition: background 0.2s; }
        .dropdown a:hover { background: #F4F0F8; }
        .dropdown hr { margin: 0; border-color: #E4DCF0; }
        .relative { position: relative; }
        
        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 28px;
        }
        .stat-card {
            background: white;
            border-radius: 20px;
            padding: 18px;
            border: 1px solid #E4DCF0;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .stat-card:hover { transform: translateY(-2px); box-shadow: 0 8px 24px rgba(48, 46, 99, 0.08); }
        .stat-left { display: flex; align-items: center; gap: 12px; }
        .stat-icon {
            width: 44px;
            height: 44px;
            background: rgba(135, 93, 156, 0.1);
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .stat-icon i { font-size: 22px; color: #875D9C; }
        .stat-value { font-size: 26px; font-weight: 800; color: #302E63; line-height: 1.2; }
        .stat-label { font-size: 0.7rem; font-weight: 600; color: #7B6E8F; text-transform: uppercase; letter-spacing: 0.5px; }
        
        /* Filter Bar */
        .filter-bar {
            background: white;
            border-radius: 16px;
            padding: 16px 20px;
            margin-bottom: 24px;
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            align-items: center;
        }
        .search-box {
            flex: 2;
            min-width: 200px;
            display: flex;
            align-items: center;
            gap: 10px;
            background: #f8f9fa;
            padding: 10px 16px;
            border-radius: 40px;
            border: 1px solid #e2e8f0;
        }
        .search-box input { border: none; background: transparent; flex: 1; outline: none; font-size: 13px; }
        .filter-select {
            padding: 10px 16px;
            border-radius: 40px;
            border: 1px solid #e2e8f0;
            background: #f8f9fa;
            cursor: pointer;
            font-size: 13px;
        }
        .btn-filter, .btn-reset {
            background: #E75A9B;
            color: white;
            border: none;
            padding: 10px 24px;
            border-radius: 40px;
            cursor: pointer;
            font-weight: 600;
            font-size: 13px;
            text-decoration: none;
        }
        .btn-reset { background: #64748b; }
        
        /* Disputes Table */
        .disputes-table {
            background: white;
            border-radius: 20px;
            overflow-x: auto;
            width: 100%;
            border-collapse: collapse;
        }
        .disputes-table th, .disputes-table td {
            padding: 14px 16px;
            text-align: left;
            border-bottom: 1px solid #eef2f7;
            font-size: 13px;
            vertical-align: middle;
        }
        .disputes-table th {
            background: #f8f8f8;
            font-size: 12px;
            font-weight: 700;
            color: #302E63;
        }
        .disputes-table tr:hover td { background: #fafcff; }
        
        /* Badges */
        .badge-pending { background: #fff3cd; color: #856404; padding: 4px 12px; border-radius: 20px; font-size: 11px; display: inline-block; }
        .badge-resolved { background: #d4edda; color: #155724; padding: 4px 12px; border-radius: 20px; font-size: 11px; display: inline-block; }
        .badge-escalated { background: #f8d7da; color: #721c24; padding: 4px 12px; border-radius: 20px; font-size: 11px; display: inline-block; }
        .badge-critical { background: #f8d7da; color: #dc2626; padding: 4px 12px; border-radius: 20px; font-size: 11px; display: inline-block; }
        .badge-minor { background: #e2e8f0; color: #64748b; padding: 4px 12px; border-radius: 20px; font-size: 11px; display: inline-block; }
        .badge-payment { background: #d1ecf1; color: #0c5460; padding: 4px 12px; border-radius: 20px; font-size: 11px; display: inline-block; }
        .badge-booking { background: #e7e5ff; color: #4c3d8c; padding: 4px 12px; border-radius: 20px; font-size: 11px; display: inline-block; }
        .badge-no-show { background: #f8d7da; color: #dc2626; padding: 4px 12px; border-radius: 20px; font-size: 11px; display: inline-block; font-weight: bold; }
        .badge-materials { background: #fff3cd; color: #f59e0b; padding: 4px 12px; border-radius: 20px; font-size: 11px; display: inline-block; }
        .badge-rejected {
    background: #fee2e2;
    color: #dc2626;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 11px;
    display: inline-block;
}
        /* Buttons */
        .btn-view, .btn-resolve {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            cursor: pointer;
            border: none;
            margin: 2px;
            transition: 0.2s;
        }
        .btn-view { background: #e2e8f0; color: #1d3156; }
        .btn-view:hover { background: #cbd5e1; }
        .btn-resolve { background: #28a745; color: white; }
        .btn-resolve:hover { background: #218838; }
        
        /* Modal */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.7);
            z-index: 2000;
            display: flex;
            align-items: center;
            justify-content: center;
            visibility: hidden;
            opacity: 0;
            transition: all 0.3s ease;
        }
        .modal-overlay.active { visibility: visible; opacity: 1; }
        .modal-container {
            background: white;
            border-radius: 24px;
            width: 90%;
            max-width: 550px;
            max-height: 85vh;
            overflow-y: auto;
        }
        .modal-header {
    padding: 20px 24px;
    border-bottom: 1px solid #e2e8f0;
    background: linear-gradient(135deg, #1a1a3e 0%, #272754 100%);
    border-radius: 24px 24px 0 0;
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.modal-header h3 { 
    color: white; 
    font-size: 1.2rem; 
    font-weight: 700; 
    display: flex; 
    align-items: center; 
    gap: 10px; 
    margin: 0; 
}
.modal-close {
    background: none;
    border: none;
    font-size: 28px;
    cursor: pointer;
    color: rgba(255,255,255,0.7);
    width: 36px;
    height: 36px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    transition: all 0.2s;
}
.modal-close:hover {
    background: rgba(255,255,255,0.2);
    color: white;
}
        .modal-header h3 { color: white; font-size: 1.2rem; font-weight: 700; display: flex; align-items: center; gap: 10px; margin: 0; }
        .modal-close {
            background: none;
            border: none;
            font-size: 28px;
            cursor: pointer;
            color: rgba(255,255,255,0.7);
            float: right;
        }
        .modal-body { padding: 24px; }
        .modal-footer {
            padding: 16px 24px;
            border-top: 1px solid #e2e8f0;
            display: flex;
            justify-content: flex-end;
            gap: 12px;
        }
        .form-group { margin-bottom: 18px; }
        .form-group label { display: block; font-weight: 700; font-size: 12px; margin-bottom: 6px; color: #1e293b; }
        .form-group input, .form-group textarea, .form-group select {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid #cbd5e1;
            border-radius: 12px;
            font-family: inherit;
            font-size: 13px;
        }
        .btn-cancel { background: #e2e8f0; color: #475569; padding: 10px 20px; border-radius: 40px; border: none; cursor: pointer; font-weight: 600; }
        .btn-save { background: #28a745; color: white; padding: 10px 24px; border-radius: 40px; border: none; cursor: pointer; font-weight: 600; }
        
        /* Proof Image */
        .proof-image {
            max-width: 80px;
            max-height: 60px;
            border-radius: 8px;
            cursor: pointer;
            object-fit: cover;
        }
        .proof-preview {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.9);
            z-index: 3000;
            display: none;
            align-items: center;
            justify-content: center;
        }
        .proof-preview.active { display: flex; }
        .proof-preview img { max-width: 90%; max-height: 90%; object-fit: contain; border-radius: 8px; }
        
        /* Empty State */
        .empty-state { text-align: center; padding: 60px; background: white; border-radius: 20px; color: #94a3b8; }
        .empty-state i { font-size: 48px; margin-bottom: 16px; display: block; }
        
        .sidebar-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(126, 96, 223, 0.5);
            z-index: 999;
            display: none;
        }
        .sidebar-overlay.active { display: block; }
        
        @media (max-width: 768px) {
            .menu-toggle { display: block; }
            .sidebar { transform: translateX(-100%); }
            .sidebar.open { transform: translateX(0); }
            .main-content { margin-left: 0; padding: 16px; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); gap: 12px; }
            .filter-bar { flex-direction: column; align-items: stretch; }
            .disputes-table { font-size: 12px; }
            .disputes-table th, .disputes-table td { padding: 8px 10px; }
        }
        
        @media (max-width: 480px) {
            .stats-grid { grid-template-columns: 1fr; }
            .top-bar { flex-direction: column; align-items: flex-start; }
            .admin-profile { align-self: flex-end; }
        }
        
        .alert-success {
            background: #d4edda;
            color: #155724;
            border-left: 4px solid #28a745;
            padding: 12px 16px;
            border-radius: 12px;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>

<div class="sidebar-overlay" id="sidebarOverlay"></div>
<aside class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <div class="brand-wrapper">
            <img src="<?= e($assetBase) ?>/logo.png" alt="Kyoshi" class="brand-icon">
            <div class="brand-title">
                <h1>KYOSHI</h1>
                <span class="admin-space-text">Admin Space</span>
            </div>
        </div>
    </div>
    <nav class="nav-menu">
        <div class="nav-section">
            <a href="admin_dashboard.php" class="nav-item"><i class="bi bi-speedometer2"></i><span>Dashboard</span></a>
        </div>
        <div class="nav-section">
            <div class="nav-section-label">USERS</div>
            <a href="admin_tutor_actions.php" class="nav-item"><i class="bi bi-person-badge"></i><span>Tutors</span><span class="nav-badge"><?= $totalTutors ?></span></a>
            <a href="admin_student_actions.php" class="nav-item"><i class="bi bi-person"></i><span>Students</span><span class="nav-badge"><?= $totalStudents ?></span></a>
        </div>
        <div class="nav-section">
            <div class="nav-section-label">FINANCE</div>
            <a href="admin_payments.php" class="nav-item"><i class="bi bi-credit-card"></i><span>Payments</span><span class="nav-badge"><?= $pendingPayments ?></span></a>
            <a href="admin_payouts.php" class="nav-item"><i class="bi bi-cash-stack"></i><span>Payouts</span><span class="nav-badge"><?= $pendingPayouts ?></span></a>
        </div>
        <div class="nav-section">
            <div class="nav-section-label">BOOKINGS</div>
            <a href="admin_bookings.php" class="nav-item"><i class="bi bi-calendar-check"></i><span>Bookings</span><span class="nav-badge"><?= $totalBookings ?></span></a>
            <a href="admin_disputes.php" class="nav-item active"><i class="bi bi-flag"></i><span>Disputes</span><span class="nav-badge dispute"><?= $pendingDisputes ?></span></a>
        </div>
        <div class="nav-section">
            <div class="nav-section-label">REPORTS</div>
            <a href="admin_reports.php" class="nav-item"><i class="bi bi-graph-up"></i><span>Analytics</span></a>
        </div>
    </nav>
    <div class="sidebar-footer">
        <div class="admin-info">
            <img src="<?= e($profilePic) ?>" alt="Admin" class="footer-avatar">
            <div class="admin-details">
                <span class="admin-name"><?= e($displayName) ?></span>
            </div>
        </div>
        <a href="logout.php" class="logout-icon" title="Logout"><i class="bi bi-box-arrow-right"></i></a>
    </div>
</aside>

<div class="main-content" id="mainContent">
            <div class="top-bar">
    <button class="menu-toggle" id="menuToggle"><i class="bi bi-list"></i></button>
    
    <!-- Mobile Logo (visible only on mobile) -->
    <div class="mobile-logo">
        <img src="<?= e($assetBase) ?>/logo.png" alt="Kyoshi" class="mobile-logo-img">
        <span class="mobile-logo-text">KYOSHI</span>
    </div>
    
    <!-- Desktop Title with Back Button Beside It -->
    <div class="page-title">
        <div class="title-with-back">
            <a href="admin_student_actions.php" class="back-btn-desktop">
                <i class="bi bi-arrow-left"></i>
                <span>Back</span>
            </a>
            <h1>Manage Dispute</h1>
        </div>
    </div>
    
    <div class="relative">
        <div class="admin-profile" onclick="toggleDropdown()">
            <img src="<?= e($profilePic) ?>" alt="Admin">
            <span><?= e($displayName) ?></span>
            <i class="bi bi-chevron-down"></i>
        </div>
        
        <!-- Mobile Profile Button -->
        <div class="mobile-profile-btn" onclick="toggleDropdown()">
            <img src="<?= e($profilePic) ?>" alt="Admin" class="mobile-profile-img">
        </div>
        
        <div class="dropdown" id="profileDropdown">
            <a href="admin_profile.php"><i class="bi bi-person-circle"></i> My Profile</a>
            <hr>
            <a href="logout.php" style="color:#dc2626;"><i class="bi bi-box-arrow-right"></i> Logout</a>
        </div>
    </div>
</div>

<!-- Mobile Page Header with Arrow Only (no text) -->
<div class="mobile-page-header" style="margin-top: 20px;">
    <div class="mobile-title-with-back">
        <a href="admin_student_actions.php" class="mobile-back-arrow">
            <i class="bi bi-arrow-left"></i>
        </a>
        <h1 class="mobile-page-title">Manage Dispute</h1>
    </div>
</div>

    <?php if (isset($_SESSION['success_message'])): ?>
        <div class="alert-success" id="successAlert">
            <i class="bi bi-check-circle"></i> <?= $_SESSION['success_message'] ?>
        </div>
        <?php unset($_SESSION['success_message']); ?>
    <?php endif; ?>

    <!-- Stats Cards -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-left">
                <div class="stat-icon"><i class="bi bi-clock-history"></i></div>
                <div class="stat-label">Pending Disputes</div>
            </div>
            <div class="stat-value"><?= $pending_count ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-left">
                <div class="stat-icon"><i class="bi bi-check-circle"></i></div>
                <div class="stat-label">Resolved</div>
            </div>
            <div class="stat-value"><?= $resolved_count ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-left">
                <div class="stat-icon"><i class="bi bi-exclamation-triangle"></i></div>
                <div class="stat-label">Escalated</div>
            </div>
            <div class="stat-value"><?= $escalated_count ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-left">
                <div class="stat-icon"><i class="bi bi-flag"></i></div>
                <div class="stat-label">Total</div>
            </div>
            <div class="stat-value"><?= $pending_count + $resolved_count + $escalated_count ?></div>
        </div>
    </div>

    <!-- Filter Bar -->
    <div class="filter-bar">
        <div class="search-box">
            <i class="bi bi-search"></i>
            <input type="text" id="searchInput" placeholder="Search by student, tutor, or issue type..." value="<?= e($search) ?>">
        </div>
        <select id="statusFilter" class="filter-select">
            <option value="all" <?= $filter_status == 'all' ? 'selected' : '' ?>>All Statuses</option>
            <option value="pending" <?= $filter_status == 'pending' ? 'selected' : '' ?>>Pending</option>
            <option value="resolved" <?= $filter_status == 'resolved' ? 'selected' : '' ?>>Resolved</option>
            <option value="escalated" <?= $filter_status == 'escalated' ? 'selected' : '' ?>>Escalated</option>
        </select>
        <select id="typeFilter" class="filter-select">
            <option value="all" <?= $filter_type == 'all' ? 'selected' : '' ?>>All Types</option>
            <option value="booking" <?= $filter_type == 'booking' ? 'selected' : '' ?>>Booking Disputes</option>
            <option value="payment" <?= $filter_type == 'payment' ? 'selected' : '' ?>>Payment Disputes</option>
        </select>
        <button class="btn-filter" onclick="applyFilters()"><i class="bi bi-search"></i> Apply</button>
        <a href="admin_disputes.php" class="btn-reset" style="text-align:center;"><i class="bi bi-x-circle"></i> Reset</a>
    </div>

    <!-- Disputes Table -->
    <?php if ($disputes->num_rows == 0): ?>
        <div class="empty-state">
            <i class="bi bi-inbox"></i>
            <p>No disputes found.</p>
        </div>
    <?php else: ?>
        <div style="overflow-x: auto;">
            <table class="disputes-table">
                <thead>
                    <tr>
                        <th>Type</th>
                        <th>Student</th>
                        <th>Tutor</th>
                        <th>Issue</th>
                        <th>Severity</th>
                        <th>Status</th>
                        <th>Reported on</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($dispute = $disputes->fetch_assoc()): ?>
                    <tr>
                        <td><?= getDisputeTypeLabel($dispute['dispute_type'], $dispute['issue_type']) ?></td>
                        <td>
                            <strong><?= e($dispute['student_name']) ?></strong><br>
                            <small><?= e($dispute['student_email']) ?></small>
                        </td>
                        <td>
                            <strong><?= e($dispute['tutor_name']) ?></strong><br>
                            <small><?= e($dispute['tutor_email']) ?></small>
                        </td>
                        <td>
                            <?= ucfirst(str_replace('_', ' ', $dispute['issue_type'])) ?>
                            <br><small><?= e($dispute['language']) ?> · <?= date('d M Y', strtotime($dispute['booking_date'])) ?></small>
                        </td>
                        <td><?= getSeverityBadge($dispute['severity']) ?></td>
                        <td><?= getStatusBadge($dispute['status']) ?></td>
                        <td><?= date('d M Y', strtotime($dispute['created_at'])) ?></td>
                        <td>
                            <button class="btn-view" onclick="viewDispute(<?= htmlspecialchars(json_encode($dispute)) ?>)">
                                <i class="bi bi-eye"></i> View
                            </button>
                            <?php if ($dispute['status'] === 'pending'): ?>
                                <button class="btn-resolve" onclick="openResolveModal(<?= htmlspecialchars(json_encode($dispute)) ?>)">
                                    <i class="bi bi-check-lg"></i> Resolve
                                </button>
                            <?php endif; ?>
                            <?php 
$isRefundResolved = ($dispute['status'] === 'resolved') && 
                    (($dispute['resolution_type'] === 'refund') || 
                     ($dispute['resolution_type'] === 'admin' && strpos($dispute['resolution_note'] ?? '', 'Refund') !== false));
if ($isRefundResolved): 
?>
<button class="btn-view view-receipt-btn" 
    data-dispute-id="<?= $dispute['id'] ?>"
    data-receipt-no="RFD-<?= date('Ymd') ?>-<?= str_pad($dispute['id'], 6, '0', STR_PAD_LEFT) ?>"
    data-amount="<?= (float)($dispute['total_amount'] ?? 0) ?>"
    data-student-name="<?= htmlspecialchars($dispute['student_name'] ?? '', ENT_QUOTES) ?>"
    data-student-email="<?= htmlspecialchars($dispute['student_email'] ?? '', ENT_QUOTES) ?>"
    data-tutor-name="<?= htmlspecialchars($dispute['tutor_name'] ?? '', ENT_QUOTES) ?>"
    data-tutor-email="<?= htmlspecialchars($dispute['tutor_email'] ?? '', ENT_QUOTES) ?>"
    data-language="<?= htmlspecialchars($dispute['language'] ?? '', ENT_QUOTES) ?>"
    data-booking-date="<?= $dispute['booking_date'] ?? '' ?>"
    data-booking-time="<?= $dispute['booking_time'] ?? '' ?>"
    data-issue-type="<?= htmlspecialchars($dispute['issue_type'] ?? '', ENT_QUOTES) ?>"
    data-processed-at="<?= date('d M Y, g:i A', strtotime($dispute['resolved_at'] ?? 'now')) ?>">
    <i class="bi bi-receipt"></i> View Receipt
</button>
<?php endif; ?>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- View Dispute Modal -->
<div id="viewModal" class="modal-overlay">
    <div class="modal-container">
        <div class="modal-header">
            <h3><i class="bi bi-flag"></i> Dispute Details</h3>
            <button class="modal-close" onclick="closeViewModal()">&times;</button>
        </div>
        <div class="modal-body" id="viewModalBody">
            <!-- Content loaded dynamically -->
        </div>
        <div class="modal-footer">
            <button class="btn-cancel" onclick="closeViewModal()">Close</button>
        </div>
    </div>
</div>

<!-- Resolve Dispute Modal -->
<div id="resolveModal" class="modal-overlay">
    <div class="modal-container">
        <div class="modal-header">
            <h3><i class="bi bi-check-circle"></i> Resolve Dispute</h3>
            <button class="modal-close" onclick="closeResolveModal()">&times;</button>
        </div>
        <form method="POST" action="" id="resolveForm">
            <input type="hidden" name="resolve_dispute" value="1">
            <input type="hidden" name="dispute_id" id="resolve_dispute_id">
            <input type="hidden" name="action" id="resolve_action">
            
            <div class="modal-body">
                <div id="disputeSummary"></div>
                
                <div class="form-group" id="refundGroup" style="display:none;">
                    <label>Refund Amount</label>
                    <input type="number" step="0.01" name="refund_amount" id="refund_amount" class="form-control">
                </div>
                
                <div class="form-group" id="rescheduleGroup" style="display:none;">
                    <label>New Date</label>
                    <input type="date" name="new_booking_date" id="new_booking_date" class="form-control">
                    <label style="margin-top:10px;">New Time</label>
                    <input type="time" name="new_booking_time" id="new_booking_time" class="form-control">
                </div>
                
                <div class="form-group">
                    <label>Resolution Notes (Optional)</label>
                    <textarea name="resolution_note" id="resolution_note" rows="3" class="form-control" placeholder="Add any notes about this resolution..."></textarea>
                </div>
            </div>
            
           <div class="modal-footer">
            <button type="button" class="btn-cancel" onclick="closeResolveModal()">Cancel</button>
            <button type="button" class="btn-reject" id="rejectBtn" style="background: #dc2626; color: white; padding: 10px 24px; border-radius: 40px; border: none; cursor: pointer; font-weight: 600; display: none;">
                <i class="bi bi-x-circle" style="text-decoration:none;"></i> Reject Dispute
            </button>
            <button type="submit" class="btn-save" id="resolveSubmitBtn">Confirm Resolution</button>
        </div>
        </form>
    </div>
</div>

<!-- Proof Image Preview -->
<div id="proofPreview" class="proof-preview" onclick="closeProofPreview()">
    <img id="proofPreviewImage" src="">
</div>

<script>
let currentDispute = null;

function toggleDropdown() {
    const dropdown = document.getElementById('profileDropdown');
    if (!dropdown) return;
    
    if (dropdown.style.display === 'block') {
        dropdown.style.display = 'none';
        dropdown.classList.remove('show');
    } else {
        dropdown.style.display = 'block';
        dropdown.classList.add('show');
    }
}

// Close dropdown when clicking outside
document.addEventListener('click', function(e) {
    const dropdown = document.getElementById('profileDropdown');
    const mobileProfileBtn = document.querySelector('.mobile-profile-btn');
    const desktopProfile = document.querySelector('.admin-profile');
    
    if (!dropdown) return;
    
    const isClickOnMobileBtn = mobileProfileBtn && mobileProfileBtn.contains(e.target);
    const isClickOnDesktop = desktopProfile && desktopProfile.contains(e.target);
    const isClickInsideDropdown = dropdown.contains(e.target);
    
    if (!isClickOnMobileBtn && !isClickOnDesktop && !isClickInsideDropdown) {
        dropdown.style.display = 'none';
        dropdown.classList.remove('show');
    }
});

// Prevent dropdown from closing when clicking inside it
const dropdownEl = document.getElementById('profileDropdown');
if (dropdownEl) {
    dropdownEl.addEventListener('click', function(e) {
        e.stopPropagation();
    });
}

// Close dropdown on Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        const dropdown = document.getElementById('profileDropdown');
        if (dropdown) {
            dropdown.style.display = 'none';
            dropdown.classList.remove('show');
        }
    }
});

function applyFilters() {
    const search = document.getElementById('searchInput').value;
    const status = document.getElementById('statusFilter').value;
    const type = document.getElementById('typeFilter').value;
    let url = `admin_disputes.php?status=${status}&type=${type}`;
    if (search.trim() !== '') {
        url += `&search=${encodeURIComponent(search.trim())}`;
    }
    window.location.href = url;
}function viewDispute(dispute) {
    currentDispute = dispute;
    
    // Extract student's requested resolution from message for payment disputes
    let requestedResolution = 'Not specified';
    let studentChoiceHtml = '';
    let preferredDateTimeHtml = '';
    
    if (dispute.message) {
        // Check for Reschedule in message
        if (dispute.message.includes('Resolution: Reschedule') || dispute.message.includes('Reschedule')) {
            requestedResolution = 'Reschedule Booking - Student wants different time';
            
            // Extract preferred date/time from message
            const dateMatch = dispute.message.match(/Preferred new date\/time:\s*([^\n]+)/i);
            if (dateMatch) {
                const preferredDateTime = dateMatch[1].trim();
                preferredDateTimeHtml = `
                    <div style="background: #fef3c7; padding: 10px; border-radius: 8px; margin-top: 10px; border-left: 3px solid #f59e0b;">
                        <small><i class="bi bi-calendar-event"></i> <strong>Student's Preferred New Date/Time:</strong> ${preferredDateTime}</small>
                    </div>
                `;
            }
        } 
        else if (dispute.message.includes('Resolution: Refund') || dispute.message.includes('Refund')) {
            requestedResolution = 'Full Refund - Student wants money back';
        }
        else if (dispute.message.includes('Resolution: Complete') || dispute.message.includes('Complete')) {
            requestedResolution = 'Complete Current Booking - Student wants to keep booking';
        }
        
        // Build the student choice HTML if we found a resolution
        if (requestedResolution !== 'Not specified') {
            studentChoiceHtml = `
                <div style="background: #e0f2fe; padding: 15px; border-radius: 12px; margin-bottom: 15px; border-left: 4px solid #075985;">
                    <p><strong><i class="bi bi-chat-right-quote"></i> Student's Requested Resolution:</strong></p>
                    <p style="font-size: 16px; font-weight: bold; color: #075985;">${requestedResolution}</p>
                    ${preferredDateTimeHtml}
                    <p style="font-size: 12px; color: #64748b; margin-top: 5px;">
                        <i class="bi bi-info-circle"></i> Admin should honor this request
                    </p>
                </div>
            `;
        }
    }
    
    const issueLabels = {
        'tutor_no_show': 'Tutor Did Not Attend',
        'student_no_show': 'Student Did Not Attend',
        'wrong_materials': 'Wrong Materials Provided',
        'technical_issues': 'Technical Issues',
        'money_deducted': 'Money Deducted (Payment Issue)',
        'other': 'Other Issue'
    };
    
    const issueLabel = issueLabels[dispute.issue_type] || dispute.issue_type;
    
    // Determine resolution type text
    let resolutionTypeText = '';
    if (dispute.dispute_type === 'payment') {
        resolutionTypeText = 'Admin Review Required (Payment Dispute)';
    } else if (dispute.resolution_type === 'admin' || dispute.severity === 'serious') {
        resolutionTypeText = 'Admin Review Required';
    } else {
        resolutionTypeText = 'Student/Tutor Resolution';
    }
    
    // Student's proof
    let proofHtml = '';
    if (dispute.proof_image) {
        let proofPath = dispute.proof_image;
        if (proofPath.startsWith('uploads/')) {
            proofPath = `../${proofPath}`;
        } else if (!proofPath.startsWith('../')) {
            proofPath = `../uploads/${proofPath}`;
        }
        
        const isPdf = proofPath.toLowerCase().endsWith('.pdf');
        const isImage = /\.(jpg|jpeg|png|gif|webp)$/i.test(proofPath);
        
        if (isImage) {
            proofHtml = `
                <div class="form-group">
                    <label><i class="bi bi-image"></i> Student's Proof Attachment:</label>
                    <div>
                        <img src="${proofPath}" class="proof-image" onclick="event.stopPropagation(); showProofPreview('${proofPath}')" style="max-width: 100%; max-height: 200px; border-radius: 8px; cursor: pointer;">
                    </div>
                </div>
            `;
        } else if (isPdf) {
            proofHtml = `
                <div class="form-group">
                    <label><i class="bi bi-file-earmark-pdf"></i> Student's Proof Attachment (PDF):</label>
                    <div>
                        <a href="${proofPath}" target="_blank" class="btn-view">View PDF Proof</a>
                    </div>
                </div>
            `;
        } else {
            proofHtml = `
                <div class="form-group">
                    <label>Student's Proof Attachment:</label>
                    <div><a href="${proofPath}" target="_blank">View Proof</a></div>
                </div>
            `;
        }
    }
    
    // Keep the FULL original message - don't strip anything!
    let displayMessage = dispute.message || 'No message provided.';
    
    // Just ensure it displays properly with line breaks
    displayMessage = displayMessage.replace(/\n/g, '<br>');
    
    const html = `
        <div style="background: #f8f9fa; padding: 15px; border-radius: 12px; margin-bottom: 15px;">
            <p><strong>Booking ID:</strong> #${dispute.booking_id}</p>
            <p><strong>Language:</strong> ${dispute.language}</p>
            <p><strong>Session Date:</strong> ${new Date(dispute.booking_date).toLocaleDateString('en-MY')} at ${dispute.booking_time}</p>
            <p><strong>Amount:</strong> RM ${parseFloat(dispute.total_amount || 0).toFixed(2)}</p>
            <p><strong>Learning Mode:</strong> ${dispute.learning_mode === 'online' ? 'Online' : 'Face to Face'}</p>
        </div>
        
        ${studentChoiceHtml}
        
        <div style="background: #fff3cd; padding: 15px; border-radius: 12px; margin-bottom: 15px;">
            <p><strong><i class="bi bi-exclamation-triangle"></i> Issue Type:</strong> ${issueLabel}</p>
            <p><strong>Severity:</strong> ${dispute.severity === 'serious' ? '⚠️ Critical' : '📝 Minor'}</p>
            <p><strong>Resolution Type:</strong> ${resolutionTypeText}</p>
            <p><strong>Reported On:</strong> ${new Date(dispute.created_at).toLocaleString()}</p>
        </div>
        
        <div style="background: #e8f0fe; padding: 15px; border-radius: 12px; margin-bottom: 15px;">
            <p><strong><i class="bi bi-chat-left-text"></i> Student's Full Message:</strong></p>
            <div style="background: white; padding: 12px; border-radius: 8px; font-size: 13px; max-height: 300px; overflow-y: auto; white-space: pre-wrap;">${displayMessage}</div>
        </div>
        
        ${proofHtml}
        <div id="meetingLogsContainer"></div>
        <div id="tutorProofContainer"></div>
        
        ${dispute.resolution_note ? `
        <div style="background: #e8f0fe; padding: 15px; border-radius: 12px;">
            <p><strong>Resolution Notes:</strong></p>
            <p>${dispute.resolution_note}</p>
        </div>
        ` : ''}
    `;
    
    document.getElementById('viewModalBody').innerHTML = html;
    document.getElementById('viewModal').classList.add('active');
    
// Show meeting logs based on issue type and learning mode
if (dispute.issue_type === 'wrong_materials') {
    // Wrong materials - no logs needed
    document.getElementById('meetingLogsContainer').innerHTML = `
        <div class="form-group">
            <label><i class="bi bi-file-text"></i> Wrong Materials Issue:</label>
            <div style="background: #fef3c7; padding: 12px; border-radius: 8px; border-left: 4px solid #f59e0b;">
                <i class="bi bi-exclamation-triangle" style="color: #f59e0b;"></i>
                This dispute is about <strong>wrong materials provided</strong>. 
                No attendance logs are required for this type of issue.
                <br><small>The tutor must respond within 2 days or this will be automatically escalated.</small>
            </div>
        </div>
    `;
    document.getElementById('tutorProofContainer').innerHTML = '';
    
} else if (dispute.learning_mode === 'online' && dispute.issue_type === 'tutor_no_show') {
    // Online sessions - show meeting logs
    fetch(`get_meeting_logs.php?booking_id=${dispute.booking_id}`)
        .then(response => response.json())
        .then(data => {
            let logsHtml = '<div class="form-group"><label><i class="bi bi-camera-video"></i> Meeting Attendance Logs:</label><div style="background: #f8fafc; padding: 12px; border-radius: 8px;">';
            if (data.logs && data.logs.length > 0) {
                logsHtml += '<div style="margin-bottom: 10px;"><strong>Session Attendance Record:</strong></div>';
                data.logs.forEach(log => {
                    logsHtml += `
                        <div style="padding: 8px 0; border-bottom: 1px solid #eee;">
                            <strong>${log.participant_role === 'tutor' ? '🎓 Tutor' : '👤 Student'}</strong><br>
                            Joined: ${log.join_time}<br>
                            ${log.leave_time ? `Left: ${log.leave_time} (${log.duration_minutes} min)` : '<span style="color: #f59e0b;">Still in meeting</span>'}
                        </div>
                    `;
                });
            } else {
                logsHtml += '<div style="color: #666; text-align: center; padding: 20px;"><i class="bi bi-clock-history"></i> No meeting logs available for this session.</div>';
            }
            logsHtml += '</div></div>';
            document.getElementById('meetingLogsContainer').innerHTML = logsHtml;
        })
        .catch(error => {
            document.getElementById('meetingLogsContainer').innerHTML = '<div class="form-group"><label><i class="bi bi-camera-video"></i> Meeting Attendance Logs:</label><div style="background: #f8fafc; padding: 12px; border-radius: 8px;"><div style="color: #666; text-align: center; padding: 20px;"><i class="bi bi-exclamation-triangle"></i> Could not load meeting logs.</div></div></div>';
        });
    
    // For online sessions, tutor proof is not needed
    document.getElementById('tutorProofContainer').innerHTML = '';
    
} else if (dispute.learning_mode === 'face_to_face' && dispute.issue_type !== 'wrong_materials') {
    // Face-to-face sessions - show tutor proof
    fetch(`get_tutor_proof.php?booking_id=${dispute.booking_id}`)
        .then(response => response.json())
        .then(data => {
            let tutorProofHtml = '<div class="form-group"><label><i class="bi bi-camera-fill"></i> Tutor\'s Attendance Proof:</label><div style="background: #f8fafc; padding: 12px; border-radius: 8px;">';
            if (data.has_proof) {
                tutorProofHtml += `
                    <div style="background: #f0fdf4; padding: 12px; border-radius: 8px; border-left: 4px solid #059669;">
                        <a href="${data.proof_path}" target="_blank">
                            <img src="${data.proof_path}" style="max-width: 100%; max-height: 150px; border-radius: 8px; border: 1px solid #ddd;">
                        </a>
                        <p style="color: #059669; margin-top: 5px;">
                            <i class="bi bi-check-circle"></i> Tutor uploaded proof at ${data.uploaded_at}
                        </p>
                    </div>
                `;
            } else {
                tutorProofHtml += '<div style="color: #666; text-align: center; padding: 20px;"><i class="bi bi-camera-off"></i> No attendance proof uploaded by tutor for this session.</div>';
            }
            tutorProofHtml += '</div></div>';
            document.getElementById('tutorProofContainer').innerHTML = tutorProofHtml;
        })
        .catch(error => {
            document.getElementById('tutorProofContainer').innerHTML = '<div class="form-group"><label><i class="bi bi-camera-fill"></i> Tutor\'s Attendance Proof:</label><div style="background: #f8fafc; padding: 12px; border-radius: 8px;"><div style="color: #666; text-align: center; padding: 20px;"><i class="bi bi-exclamation-triangle"></i> Could not load attendance proof.</div></div></div>';
        });
    
    document.getElementById('meetingLogsContainer').innerHTML = '';
    
} else {
    // Default empty state for both containers
    document.getElementById('meetingLogsContainer').innerHTML = '';
    document.getElementById('tutorProofContainer').innerHTML = '';
}
    
    
}
// Helper function
function ucfirst(str) {
    if (!str) return str;
    return str.charAt(0).toUpperCase() + str.slice(1);
}

function closeViewModal() {
    document.getElementById('viewModal').classList.remove('active');
}
function openResolveModal(dispute) {
        // Add reject button functionality - BUT NOT FOR WRONG MATERIALS
    const rejectBtn = document.getElementById('rejectBtn');
    if (rejectBtn) {
        // Only show reject button for payment disputes and tutor_no_show, NOT for wrong_materials
        if (dispute.dispute_type === 'payment' || dispute.issue_type === 'tutor_no_show') {
            rejectBtn.style.display = 'flex';
            rejectBtn.style.alignItems = 'center';
            rejectBtn.style.gap = '8px';
            
            const newRejectBtn = rejectBtn.cloneNode(true);
            rejectBtn.parentNode.replaceChild(newRejectBtn, rejectBtn);
            
            newRejectBtn.onclick = function() {
                Swal.fire({
                    title: 'Reject Dispute?',
                    text: dispute.dispute_type === 'payment' ? 'Are you sure you want to reject this payment dispute? The booking will be cancelled.' : 'Are you sure you want to reject this dispute? The session will proceed as scheduled.',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#dc2626',
                    cancelButtonColor: '#64748b',
                    confirmButtonText: 'Yes, Reject',
                    cancelButtonText: 'Cancel',
                    input: 'textarea',
                    inputPlaceholder: 'Please provide a reason for rejection (optional)...',
                    inputAttributes: {
                        'aria-label': 'Rejection reason'
                    }
                }).then((result) => {
                    if (result.isConfirmed) {
                        document.getElementById('resolve_action').value = 'reject';
                        const reasonTextarea = document.getElementById('resolution_note');
                        if (result.value) {
                            reasonTextarea.value = 'REJECTION REASON: ' + result.value;
                        } else {
                            reasonTextarea.value = 'REJECTION: No reason provided.';
                        }
                        document.getElementById('resolveForm').submit();
                    }
                });
            };
        } else {
            // Hide reject button for wrong_materials and other non-refundable disputes
            rejectBtn.style.display = 'none';
        }
    }
    
    currentDispute = dispute;
    document.getElementById('resolve_dispute_id').value = dispute.id;
    
    // Extract student's requested resolution
    let studentRequest = null;
    let preferredDate = null;
    let preferredTime = null;
    
    if (dispute.preferred_date && dispute.preferred_time) {
        preferredDate = dispute.preferred_date;
        preferredTime = dispute.preferred_time;
    }
    
    // SECOND: Check in message if not found
    if (!studentRequest && dispute.message) {
        if (dispute.message.includes('Resolution: Reschedule') || dispute.message.includes('Reschedule')) {
            studentRequest = 'reschedule';
        } else if (dispute.message.includes('Resolution: Refund') || dispute.message.includes('Refund')) {
            studentRequest = 'refund';
        } else if (dispute.message.includes('Resolution: Complete') || dispute.message.includes('Complete')) {
            studentRequest = 'complete';
        }
    }
    
    // Extract preferred date/time from message if not in database
    if (!preferredDate && dispute.message) {
        const dateMatch = dispute.message.match(/Preferred new date\/time:\s*([^\n]+)/i);
        if (dateMatch) {
            const dateTimeStr = dateMatch[1].trim();
            
            // Try to parse various date formats
            let parts = dateTimeStr.match(/(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})[,\s]*(\d{1,2}):(\d{2})/);
            if (parts) {
                let day = parts[1].padStart(2, '0');
                let month = parts[2].padStart(2, '0');
                let year = parts[3];
                let hour = parts[4].padStart(2, '0');
                let minute = parts[5].padStart(2, '0');
                preferredDate = `${year}-${month}-${day}`;
                preferredTime = `${hour}:${minute}`;
            }
            
            // Format: "26 June 2026, 2:30 PM"
            if (!preferredDate) {
                const dateParts = dateTimeStr.match(/(\d{1,2})\s+(\w+)\s+(\d{4})[,\s]*(\d{1,2}):(\d{2})\s*(AM|PM)/i);
                if (dateParts) {
                    const months = {
                        'january': '01', 'february': '02', 'march': '03', 'april': '04',
                        'may': '05', 'june': '06', 'july': '07', 'august': '08',
                        'september': '09', 'october': '10', 'november': '11', 'december': '12'
                    };
                    let day = dateParts[1].padStart(2, '0');
                    let monthName = dateParts[2].toLowerCase();
                    let year = dateParts[3];
                    let hour = parseInt(dateParts[4]);
                    let minute = dateParts[5];
                    let ampm = dateParts[6].toUpperCase();
                    
                    let month = months[monthName] || '01';
                    
                    if (ampm === 'PM' && hour < 12) hour += 12;
                    if (ampm === 'AM' && hour === 12) hour = 0;
                    
                    preferredDate = `${year}-${month}-${day}`;
                    preferredTime = `${hour.toString().padStart(2, '0')}:${minute}`;
                }
            }
        }
    }
    
    const issueLabels = {
        'tutor_no_show': 'Tutor Did Not Attend',
        'wrong_materials': 'Wrong Materials Provided',
        'money_deducted': 'Money Deducted (Payment Issue)',
        'other': 'Other Issue'
    };
    
    const issueLabel = issueLabels[dispute.issue_type] || dispute.issue_type;
    
    let summaryHtml = `
        <div style="background: #f8f9fa; padding: 15px; border-radius: 12px; margin-bottom: 15px;">
            <p><strong>Booking #${dispute.booking_id}</strong> - ${dispute.language} with ${dispute.tutor_name}</p>
            <p><strong>Issue:</strong> ${issueLabel}</p>
            <p><strong>Amount:</strong> RM ${parseFloat(dispute.total_amount || 0).toFixed(2)}</p>
    `;
    
    // Show student's requested resolution if found
    if (studentRequest) {
        const resolutionLabels = {
            'refund': 'Full Refund (Student wants money back)',
            'reschedule': 'Reschedule Booking (Student wants different time)',
            'complete': 'Complete Current Booking (Student wants to keep booking)'
        };
        summaryHtml += `
            <div style="margin-top: 10px; padding: 10px; background: #e0f2fe; border-radius: 8px; border-left: 4px solid #075985;">
                <strong><i class="bi bi-chat-right-quote"></i> Student's Request:</strong><br>
                ${resolutionLabels[studentRequest] || studentRequest}
                ${preferredDate ? `<br><small><i class="bi bi-calendar"></i> <strong>Preferred:</strong> ${preferredDate} at ${preferredTime}</small>` : ''}
            </div>
        `;
    }
    
    // Show bank details if refund was requested
    if (dispute.bank_name && dispute.bank_account_number && dispute.bank_account_name) {
        summaryHtml += `
            <div style="margin-top: 10px; padding: 10px; background: #f0fdf4; border-radius: 8px; border-left: 4px solid #059669;">
                <strong><i class="bi bi-bank"></i> Bank Details for Refund:</strong><br>
                <small>Bank: ${dispute.bank_name}</small><br>
                <small>Account: ****${dispute.bank_account_number.slice(-4)}</small><br>
                <small>Name: ${dispute.bank_account_name}</small>
            </div>
        `;
    }
    
    summaryHtml += `</div>`;
    
    document.getElementById('disputeSummary').innerHTML = summaryHtml;
    
    const refundGroup = document.getElementById('refundGroup');
    const rescheduleGroup = document.getElementById('rescheduleGroup');
    const submitBtn = document.getElementById('resolveSubmitBtn');
    const newDateInput = document.getElementById('new_booking_date');
    const newTimeInput = document.getElementById('new_booking_time');
    
    // Reset
    refundGroup.style.display = 'none';
    rescheduleGroup.style.display = 'none';
    submitBtn.disabled = false;
    submitBtn.style.opacity = '1';
    submitBtn.style.cursor = 'pointer';
    
    // Determine action based on student request and dispute type
    if (studentRequest === 'refund') {
        refundGroup.style.display = 'block';
        document.getElementById('refund_amount').value = dispute.total_amount;
        document.getElementById('resolve_action').value = 'refund';
        submitBtn.innerHTML = 'Process Refund (Student Request)';
        submitBtn.style.background = '#dc2626';
    } 
    else if (studentRequest === 'reschedule') {
        rescheduleGroup.style.display = 'block';
        document.getElementById('resolve_action').value = 'reschedule';
        submitBtn.innerHTML = 'Confirm Reschedule (Student Request)';
        submitBtn.style.background = '#f59e0b';
        
        // Pre-fill the date/time with student's preferred values if available
        if (preferredDate) {
            newDateInput.value = preferredDate;
        }
        if (preferredTime) {
            newTimeInput.value = preferredTime;
        }
        
        const noteField = document.getElementById('resolution_note');
        if (preferredDate && !noteField.value) {
            noteField.placeholder = `Student requested: ${preferredDate} at ${preferredTime}. Confirm this time or enter new date/time.`;
        } else {
            noteField.placeholder = 'Enter the new date and time for the rescheduled session...';
        }
        
        // ============================================
        // AVAILABILITY CHECK - MOVED HERE (outside the parse block)
        // ============================================
        const dateInput = document.getElementById('new_booking_date');
        const timeInput = document.getElementById('new_booking_time');
        
        // Remove existing warning if any
        const existingWarning = document.getElementById('availabilityWarning');
        if (existingWarning) existingWarning.remove();
        
        // Create warning div
        const availabilityWarning = document.createElement('div');
        availabilityWarning.id = 'availabilityWarning';
        availabilityWarning.style.cssText = 'margin-top: 10px; padding: 10px; border-radius: 8px; display: none;';
        
        // Insert warning after the time input
        const rescheduleGroupDiv = document.getElementById('rescheduleGroup');
        if (rescheduleGroupDiv) {
            rescheduleGroupDiv.appendChild(availabilityWarning);
        }
        
        function checkAvailability() {
            const date = dateInput.value;
            const time = timeInput.value;
            
            if (date && time) {
                fetch(`check_tutor_availability.php?tutor_id=${dispute.tutor_id}&date=${date}&time=${time}&booking_id=${dispute.booking_id}`)
                    .then(response => response.json())
                    .then(data => {
                        const warningDiv = document.getElementById('availabilityWarning');
                        if (!data.available) {
                            warningDiv.innerHTML = `
                                <i class="bi bi-exclamation-triangle-fill" style="color: #dc2626;"></i>
                                <strong style="color: #dc2626;">Warning:</strong> ${data.message}
                                <br><small>Please choose a different time or contact the tutor.</small>
                            `;
                            warningDiv.style.background = '#fee2e2';
                            warningDiv.style.border = '1px solid #dc2626';
                            warningDiv.style.display = 'block';
                            
                            // Disable submit button
                            submitBtn.disabled = true;
                            submitBtn.style.opacity = '0.5';
                            submitBtn.style.cursor = 'not-allowed';
                        } else {
                            warningDiv.innerHTML = `
                                <i class="bi bi-check-circle-fill" style="color: #059669;"></i>
                                <strong style="color: #059669;">Available:</strong> ${data.message}
                            `;
                            warningDiv.style.background = '#d1fae5';
                            warningDiv.style.border = '1px solid #059669';
                            warningDiv.style.display = 'block';
                            
                            // Enable submit button
                            submitBtn.disabled = false;
                            submitBtn.style.opacity = '1';
                            submitBtn.style.cursor = 'pointer';
                        }
                    })
                    .catch(error => {
                        console.error('Error checking availability:', error);
                    });
            } else {
                const warningDiv = document.getElementById('availabilityWarning');
                if (warningDiv) warningDiv.style.display = 'none';
                submitBtn.disabled = false;
                submitBtn.style.opacity = '1';
            }
        }
        
        // Add event listeners
        dateInput.addEventListener('change', checkAvailability);
        timeInput.addEventListener('change', checkAvailability);
        
        // Initial check if values are pre-filled
        if (newDateInput.value && newTimeInput.value) {
            checkAvailability();
        }
    } 
    else if (studentRequest === 'complete') {
        document.getElementById('resolve_action').value = 'complete';
        submitBtn.innerHTML = 'Confirm Booking (Student Request)';
        submitBtn.style.background = '#28a745';
    }
    else if (dispute.issue_type === 'tutor_no_show') {
        refundGroup.style.display = 'block';
        document.getElementById('refund_amount').value = dispute.total_amount;
        document.getElementById('resolve_action').value = 'refund';
        submitBtn.innerHTML = 'Process Refund';
        submitBtn.style.background = '#dc2626';
    } 
    else if (dispute.issue_type === 'wrong_materials') {
        document.getElementById('resolve_action').value = 'resolve';
        submitBtn.innerHTML = 'Mark as Resolved';
        submitBtn.style.background = '#28a745';
    } 
    else {
        document.getElementById('resolve_action').value = 'resolve';
        submitBtn.innerHTML = 'Mark as Resolved';
        submitBtn.style.background = '#28a745';
    }
    
    document.getElementById('resolveModal').classList.add('active');
}
function showResolutionOptions() {
    const type = document.getElementById('resolution_type').value;
    const refundGroup = document.getElementById('refundGroup');
    const rescheduleGroup = document.getElementById('rescheduleGroup');
    const completeGroup = document.getElementById('completeGroup');
    
    refundGroup.style.display = 'none';
    rescheduleGroup.style.display = 'none';
    completeGroup.style.display = 'none';
    
    if (type === 'refund') {
        refundGroup.style.display = 'block';
        document.getElementById('resolve_action').value = 'refund';
    } else if (type === 'reschedule') {
        rescheduleGroup.style.display = 'block';
        document.getElementById('resolve_action').value = 'reschedule';
    } else if (type === 'complete') {
        completeGroup.style.display = 'block';
        document.getElementById('resolve_action').value = 'complete';
    }
}

function closeResolveModal() {
    document.getElementById('resolveModal').classList.remove('active');
    document.getElementById('resolveForm').reset();
}

function showProofPreview(imageSrc) {
    const preview = document.getElementById('proofPreview');
    const img = document.getElementById('proofPreviewImage');
    img.src = imageSrc;
    preview.classList.add('active');
}

function closeProofPreview() {
    document.getElementById('proofPreview').classList.remove('active');
}

// Close modals with Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeViewModal();
        closeResolveModal();
        closeProofPreview();
    }
});

// Sidebar toggle
const menuToggle = document.getElementById('menuToggle');
const sidebar = document.getElementById('sidebar');
const overlay = document.getElementById('sidebarOverlay');

if (menuToggle) {
    menuToggle.addEventListener('click', function() {
        sidebar.classList.toggle('open');
        overlay.classList.toggle('active');
        document.body.style.overflow = sidebar.classList.contains('open') ? 'hidden' : '';
    });
}
if (overlay) {
    overlay.addEventListener('click', function() {
        sidebar.classList.remove('open');
        overlay.classList.remove('active');
        document.body.style.overflow = '';
    });
}

// Auto-dismiss alert
setTimeout(() => {
    const alert = document.getElementById('successAlert');
    if (alert) {
        alert.style.opacity = '0';
        setTimeout(() => alert.remove(), 500);
    }
}, 5000);

function viewRefundReceipt(disputeId, receiptNo, amount, studentName, studentEmail, tutorName, tutorEmail, language, bookingDate, bookingTime, issueType, processedAt) {
    // Parse the amount safely
    const parsedAmount = parseFloat(amount) || 0;
    const amountFmt = 'RM ' + parsedAmount.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    
    // Parse the booking date safely
    let bookingDateFmt = '';
    try {
        const dateObj = new Date(bookingDate);
        if (!isNaN(dateObj.getTime())) {
            bookingDateFmt = dateObj.toLocaleDateString('en-MY', {day:'2-digit', month:'long', year:'numeric'});
        } else {
            bookingDateFmt = bookingDate || 'Date not available';
        }
    } catch(e) {
        bookingDateFmt = bookingDate || 'Date not available';
    }
    
    const bookingTimeFmt = bookingTime || 'Time not available';
    
    // Format issue type
    const formattedIssueType = (issueType || '').replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
    
    // Get the modal element
    const modal = document.getElementById('refundReceiptModal');
    const contentDiv = document.getElementById('refundReceiptContent');
    
    if (!modal || !contentDiv) {
        console.error('Modal or content div not found');
        return;
    }
    
    contentDiv.innerHTML = `
        <!-- Success Banner -->
        <div style="background:#d4edda;border:1px solid #28a745;border-radius:10px;padding:12px 16px;margin-bottom:16px;display:flex;align-items:center;gap:10px;">
            <i class="bi bi-check-circle-fill" style="color:#28a745;font-size:20px;flex-shrink:0;"></i>
            <div>
                <div style="font-size:13px;font-weight:700;color:#28a745;">REFUND SUCCESSFULLY PROCESSED</div>
                <div style="font-size:11px;color:#155724;">The refund amount has been credited back to the student's payment method.</div>
            </div>
        </div>

        <!-- Title + IDs -->
        <div style="text-align:center;margin-bottom:14px;">
            <div style="font-size:1rem;font-weight:800;color:#1d3156;letter-spacing:1px;">REFUND CONFIRMATION</div>
            <div style="font-size:11px;color:#94a3b8;margin-top:4px;">Refund ID: ${receiptNo || 'N/A'}</div>
            <div style="font-size:11px;color:#94a3b8;">Processed on: ${processedAt || new Date().toLocaleString()}</div>
        </div>

        <hr style="border-color:#E75A9B;margin-bottom:14px;">

        <!-- Two column info -->
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px;">
            <!-- Student Info -->
            <div style="background:#f5f5fa;border-radius:10px;padding:14px;">
                <div style="font-size:11px;font-weight:700;color:#E75A9B;letter-spacing:1px;margin-bottom:10px;">STUDENT INFORMATION</div>
                <div style="font-size:11px;margin-bottom:6px;"><span style="color:#94a3b8;font-weight:600;">Name: </span><span style="color:#3c5078;">${escapeHtml(studentName) || 'N/A'}</span></div>
                <div style="font-size:11px;margin-bottom:6px;word-break:break-all;"><span style="color:#94a3b8;font-weight:600;">Email: </span><span style="color:#3c5078;">${escapeHtml(studentEmail) || 'N/A'}</span></div>
                <div style="font-size:11px;"><span style="color:#94a3b8;font-weight:600;">Status: </span><span style="color:#28a745;font-weight:700;">VERIFIED</span></div>
            </div>
            <!-- Tutor Info -->
            <div style="background:#f5f5fa;border-radius:10px;padding:14px;">
                <div style="font-size:11px;font-weight:700;color:#E75A9B;letter-spacing:1px;margin-bottom:10px;">TUTOR INFORMATION</div>
                <div style="font-size:11px;margin-bottom:6px;"><span style="color:#94a3b8;font-weight:600;">Name: </span><span style="color:#3c5078;">${escapeHtml(tutorName) || 'N/A'}</span></div>
                <div style="font-size:11px;margin-bottom:6px;word-break:break-all;"><span style="color:#94a3b8;font-weight:600;">Email: </span><span style="color:#3c5078;">${escapeHtml(tutorEmail) || 'N/A'}</span></div>
                <div style="font-size:11px;"><span style="color:#94a3b8;font-weight:600;">Session: </span><span style="color:#3c5078;">${escapeHtml(language) || 'N/A'}</span></div>
            </div>
        </div>

        <!-- Refund Amount Box -->
        <div style="background:#E75A9B;border-radius:10px;padding:15px 25px;text-align:center;margin-bottom:16px;">
            <div style="font-size:10px;font-weight:700;color:rgba(255,255,255,0.8);letter-spacing:1px;">REFUND AMOUNT</div>
            <div style="font-size:24px;font-weight:800;color:white;margin-top:5px;">${amountFmt}</div>
        </div>

        <!-- Payment Details Table -->
        <div style="background:#1d3156;border-radius:8px 8px 0 0;padding:8px 14px;margin-bottom:0;">
            <span style="font-size:11px;font-weight:700;color:white;letter-spacing:1px;">REFUND DETAILS</span>
        </div>
        <table style="width:100%;border-collapse:collapse;margin-bottom:16px;font-size:11px;">
            <thead>
                <tr style="background:#f5f5fa;">
                    <th style="padding:8px 10px;text-align:left;color:#3c5078;font-weight:700;">Description</th>
                    <th style="padding:8px 10px;text-align:left;color:#3c5078;font-weight:700;">Details</th>
                 </tr>
            </thead>
            <tbody>
                <tr style="border-bottom:1px solid #eef2f7;">
                    <td style="padding:8px 10px;color:#64748b;">Original Payment</td>
                    <td style="padding:8px 10px;color:#64748b;">${escapeHtml(language) || 'N/A'} session with ${escapeHtml(tutorName) || 'N/A'}</td>
                </tr>
                <tr style="border-bottom:1px solid #eef2f7;">
                    <td style="padding:8px 10px;color:#64748b;">Issue Type</td>
                    <td style="padding:8px 10px;color:#64748b;">${formattedIssueType}</td>
                </tr>
                <tr style="border-bottom:1px solid #eef2f7;">
                    <td style="padding:8px 10px;color:#64748b;">Session Date</td>
                    <td style="padding:8px 10px;color:#64748b;">${bookingDateFmt} at ${bookingTimeFmt}</td>
                </tr>
            </tbody>
        </table>

        <!-- Confirmation Footer -->
        <div style="background:#d4edda;border:1px solid #28a745;border-radius:10px;padding:14px 16px;margin-bottom:14px;">
            <div style="font-size:12px;font-weight:700;color:#28a745;margin-bottom:6px;">✓ REFUND CONFIRMATION</div>
            <div style="font-size:11px;color:#155724;margin-bottom:4px;">This refund has been successfully processed and credited back to the original payment method.</div>
            <div style="font-size:11px;color:#155724;">Please allow 3-5 business days for the refund to appear in the account.</div>
        </div>

        <!-- Footer note -->
        <div style="text-align:center;font-size:10px;color:#94a3b8;line-height:1.8;">
            This is an official refund receipt from Kyoshi.<br>
            For any inquiries, please contact support@kyoshi.com<br>
            © ${new Date().getFullYear()} Kyoshi Language Learning Platform
        </div>
    `;

    modal.classList.add('active');
    document.body.style.overflow = 'hidden';
}

// Add escapeHtml helper function if not exists
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function closeRefundReceiptModal() {
    document.getElementById('refundReceiptModal').classList.remove('active');
    document.body.style.overflow = '';
}

function downloadRefundReceiptPDF() {
    const receiptContent = document.getElementById('refundReceiptContent').innerHTML;
    
    const win = window.open('', '_blank', 'width=620,height=800');
    
    win.document.write(`
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <title>Kyoshi Refund Receipt</title>
            <style>
                * { margin: 0; padding: 0; box-sizing: border-box; }
                body {
                    font-family: 'Segoe UI', 'Poppins', sans-serif;
                    padding: 40px;
                    max-width: 800px;
                    margin: auto;
                    background: white;
                }
                @media print {
                    body { padding: 20px; }
                    .no-print { display: none !important; }
                }
                .receipt-header {
                    background: #1d3156;
                    padding: 20px 25px;
                    border-radius: 16px 16px 0 0;
                    display: flex;
                    align-items: center;
                    justify-content: space-between;
                }
                .receipt-header .logo-section {
                    display: flex;
                    align-items: center;
                    gap: 12px;
                }
                .receipt-header .logo-section img {
                    width: 48px;
                    height: 48px;
                    object-fit: contain;
                }
                .receipt-header .logo-section h1 {
                    color: white;
                    font-size: 24px;
                    margin: 0;
                }
                .receipt-header .badge {
                    background: #E75A9B;
                    padding: 6px 16px;
                    border-radius: 8px;
                    text-align: center;
                }
                .receipt-header .badge div:first-child {
                    font-size: 10px;
                    font-weight: 700;
                    color: white;
                }
                .receipt-header .badge div:last-child {
                    font-size: 14px;
                    font-weight: 800;
                    color: white;
                }
                .receipt-stripe {
                    height: 6px;
                    background: #E75A9B;
                    margin-bottom: 20px;
                }
                hr { border-color: #E75A9B; margin: 15px 0; }
                .two-column {
                    display: grid;
                    grid-template-columns: 1fr 1fr;
                    gap: 20px;
                    margin-bottom: 20px;
                }
                .info-card {
                    background: #f5f5fa;
                    border-radius: 12px;
                    padding: 15px;
                }
                .info-card h4 {
                    color: #E75A9B;
                    font-size: 12px;
                    margin-bottom: 10px;
                }
                .info-card p {
                    font-size: 12px;
                    margin: 6px 0;
                }
                .payment-table {
                    width: 100%;
                    border-collapse: collapse;
                    margin-bottom: 20px;
                }
                .payment-table th {
                    background: #1d3156;
                    color: white;
                    padding: 10px;
                    font-size: 11px;
                    text-align: left;
                }
                .payment-table td {
                    padding: 10px;
                    border-bottom: 1px solid #eef2f7;
                    font-size: 11px;
                }
                .total-box {
                    display: flex;
                    justify-content: flex-end;
                    margin-bottom: 20px;
                }
                .total-box div {
                    background: #E75A9B;
                    border-radius: 8px;
                    padding: 12px 24px;
                    min-width: 180px;
                }
                .total-box div div:first-child {
                    font-size: 10px;
                    font-weight: 700;
                    color: white;
                }
                .total-box div div:last-child {
                    font-size: 20px;
                    font-weight: 800;
                    color: white;
                }
                .confirmation-footer {
                    background: #d4edda;
                    border: 1px solid #28a745;
                    border-radius: 10px;
                    padding: 15px;
                    margin-bottom: 20px;
                }
                .confirmation-footer h4 {
                    color: #28a745;
                    margin-bottom: 8px;
                }
                .footer-note {
                    text-align: center;
                    font-size: 10px;
                    color: #94a3b8;
                    margin-top: 20px;
                    padding-top: 15px;
                    border-top: 1px solid #e2e8f0;
                }
            </style>
        </head>
        <body>
            <div class="receipt-header">
                <div class="logo-section">
                    <img src="../assets/img/logo.png" alt="Kyoshi" onerror="this.style.display='none'">
                    <div>
                        <h1>KYOSHI</h1>
                        <p>Language Learning Platform</p>
                    </div>
                </div>
                <div class="badge">
                    <div>REFUND</div>
                    <div>RECEIPT</div>
                </div>
            </div>
            <div class="receipt-stripe"></div>
            
            ${receiptContent}
            
            <div class="footer-note no-print" style="margin-top: 30px; text-align: center;">
                <button onclick="window.print()" style="background: #1d3156; color: white; border: none; padding: 10px 24px; border-radius: 30px; cursor: pointer; font-weight: 600;">
                    🖨️ Print / Save as PDF
                </button>
            </div>
        </body>
        </html>
    `);
    
    win.document.close();
    win.focus();
}
window.onclick = function(event) {
    const viewModal = document.getElementById('viewModal');
    const resolveModal = document.getElementById('resolveModal');
    const proofPreview = document.getElementById('proofPreview');
    
    if (event.target === viewModal) closeViewModal();
    if (event.target === resolveModal) closeResolveModal();
    if (event.target === proofPreview) closeProofPreview();
}

// Handle view receipt buttons
document.querySelectorAll('.view-receipt-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        const disputeId = this.dataset.disputeId;
        const receiptNo = this.dataset.receiptNo;
        const amount = this.dataset.amount;
        const studentName = this.dataset.studentName;
        const studentEmail = this.dataset.studentEmail;
        const tutorName = this.dataset.tutorName;
        const tutorEmail = this.dataset.tutorEmail;
        const language = this.dataset.language;
        const bookingDate = this.dataset.bookingDate;
        const bookingTime = this.dataset.bookingTime;
        const issueType = this.dataset.issueType;
        const processedAt = this.dataset.processedAt;
        
        viewRefundReceipt(disputeId, receiptNo, amount, studentName, studentEmail, tutorName, tutorEmail, language, bookingDate, bookingTime, issueType, processedAt);
    });
});
</script>
<script>
history.pushState(null, null, location.href);
window.addEventListener('popstate', function() {
    window.location.href = 'login.php';
});
</script>
<!-- Refund Receipt Modal -->
<div id="refundReceiptModal" class="modal-overlay">
    <div class="modal-container" style="max-width: 560px;">
        <div class="receipt-modal-header" style="border-radius: 24px 24px 0 0; overflow: hidden;height:200px;">
            <div class="receipt-header-top" style="background: #1d3156; padding: 20px 25px; display: flex; align-items: center; justify-content: space-between;">
                <div style="display: flex; align-items: center; gap: 12px;">
                    <img src="../assets/img/logo.png" alt="Kyoshi" style="width: 36px; height: 36px; object-fit: contain;">
                    <div>
                        <div style="font-size: 1.2rem; font-weight: 800; color: white; letter-spacing: 2px;">KYOSHI</div>
                        <div style="font-size: 0.65rem; color: #c8c8e6;">Language Learning Platform</div>
                    </div>
                </div>
                <div style="display: flex; align-items: center; gap: 12px;">
                    <div style="background: #E75A9B; padding: 6px 14px; border-radius: 6px; text-align: center;">
                        <div style="font-size: 0.6rem; font-weight: 700; color: white; letter-spacing: 1px;">REFUND</div>
                        <div style="font-size: 0.95rem; font-weight: 800; color: white; letter-spacing: 1px;">RECEIPT</div>
                    </div>
                    <button class="modal-close" onclick="closeRefundReceiptModal()" style="color: white; background: rgba(255,255,255,0.15);">&times;</button>
                </div>
            </div>
            <div class="receipt-header-stripe" style="height: 5px; background: #E75A9B;"></div>
        </div>

        <div class="modal-body" id="refundReceiptContent" style="padding: 20px 24px; max-height: 70vh; overflow-y: auto;">
            <!-- filled by JS -->
        </div>

        <div class="modal-buttons" style="margin-bottom:20px;">
            <button class="btn-cancel" onclick="closeRefundReceiptModal()">Close</button>
            <button class="btn-save" onclick="downloadRefundReceiptPDF()" style="background: #E75A9B;">
                <i class="bi bi-download"></i> Download PDF
            </button>
        </div>
    </div>
</div>
</body>
</html>