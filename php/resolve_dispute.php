<?php
session_start();
include 'config.php';
include 'insert_notification.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require '../vendor/autoload.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit();
}

$userID = $_SESSION['user_id'];
$data = json_decode(file_get_contents('php://input'), true);
$dispute_id = $data['dispute_id'] ?? 0;
$booking_id = $data['booking_id'] ?? 0;
$action = $data['action'] ?? '';

if (!$dispute_id || !$booking_id) {
    echo json_encode(['success' => false, 'message' => 'Invalid data']);
    exit();
}

// Get dispute and booking details with student info
$stmt = $conn->prepare("
    SELECT d.*, 
           b.tutor_id, 
           b.student_id, 
           b.language,
           b.booking_date,
           b.booking_time,
           s.fullname as student_name,
           s.email as student_email,
           t.fullname as tutor_name,
           t.email as tutor_email
    FROM disputes d
    JOIN bookings b ON d.booking_id = b.id
    JOIN users s ON b.student_id = s.id
    JOIN users t ON b.tutor_id = t.id
    WHERE d.id = ? AND b.tutor_id = ?
");
$stmt->bind_param("ii", $dispute_id, $userID);
$stmt->execute();
$dispute = $stmt->get_result()->fetch_assoc();

if (!$dispute) {
    echo json_encode(['success' => false, 'message' => 'Dispute not found']);
    exit();
}

if ($action === 'resolve') {
    // Update dispute status to resolved
    $update = $conn->prepare("
        UPDATE disputes 
        SET status = 'resolved', 
            resolved_at = NOW(),
            resolved_by = ?,
            resolution_note = 'Tutor resolved the issue'
        WHERE id = ?
    ");
    $update->bind_param("ii", $userID, $dispute_id);
    $update->execute();
    
    // Send in-app notification to student
    $studentMsg = "Great news! The tutor has resolved the issue for your {$dispute['language']} session.";
    insertNotification($conn, $dispute['student_id'], "Dispute Resolved ✓", $studentMsg, "dispute_resolved", "booking_detail.php?id={$booking_id}&resolved=1");
    
    // Send in-app notification to tutor (confirmation)
    $tutorMsg = "You have successfully resolved the dispute for the {$dispute['language']} session.";
    insertNotification($conn, $userID, "Dispute Resolved - Confirmation", $tutorMsg, "dispute_resolved", "tutor_booking_detail.php?id={$booking_id}");
    
    // Send email to student
    $mail = new PHPMailer(true);
    $bookingDate = date('l, d F Y', strtotime($dispute['booking_date']));
    $bookingTime = date('g:i A', strtotime($dispute['booking_time']));
    
    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;
        $mail->CharSet = 'UTF-8';
        $mail->isHTML(true);
        $mail->setFrom('sohisabella87@gmail.com', 'Kyoshi');
        $mail->addAddress($dispute['student_email'], $dispute['student_name']);
        $mail->Subject = '✓ Dispute Resolved - Kyoshi';
        
        $issue_labels = [
            'tutor_no_show' => 'Tutor Did Not Attend',
            'student_no_show' => 'Student Did Not Attend',
            'technical_issues' => 'Technical Issues',
            'wrong_materials' => 'Wrong Materials Provided',
            'other' => 'Other Issue'
        ];
        $issueLabel = $issue_labels[$dispute['issue_type']] ?? ucfirst(str_replace('_', ' ', $dispute['issue_type']));
        
        $mail->Body = "
        <div style='font-family:Segoe UI,sans-serif;max-width:550px;margin:auto;border:1px solid #e0e0e0;border-radius:16px;padding:24px;background:#fff;'>
            <div style='text-align:center;'>
                <h2 style='color:#28a745;'>✓ Dispute Resolved</h2>
            </div>
            <p>Dear <strong>{$dispute['student_name']}</strong>,</p>
            <p>Great news! Your tutor has resolved the issue you reported.</p>
            <div style='background:#e8f5e9;border-radius:12px;padding:16px;margin:20px 0;'>
                <p><strong>Session:</strong> {$bookingDate} at {$bookingTime}</p>
                <p><strong>Language:</strong> {$dispute['language']}</p>
                <p><strong>Issue Type:</strong> {$issueLabel}</p>
            </div>
            <div style='text-align:center;margin-top:20px;'>
                <a href='http://localhost/kyoshi/php/booking_detail.php?id={$booking_id}&resolved=1' 
                   style='display:inline-block;padding:10px 20px;background:#28a745;color:white;border-radius:30px;text-decoration:none;font-weight:bold;'>
                    View Session →
                </a>
            </div>
        </div>";
        $mail->send();
    } catch (Exception $e) {
        error_log("Dispute resolution email failed: " . $e->getMessage());
    }
    
    echo json_encode(['success' => true, 'message' => 'Dispute resolved successfully']);
    
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid action']);
}
?>