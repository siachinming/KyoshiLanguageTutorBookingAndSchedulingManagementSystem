<?php
include 'config.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require '../vendor/autoload.php';

date_default_timezone_set('Asia/Kuala_Lumpur');

echo "=== Reschedule Request Expiry Cron Job ===\n";
echo "Run at: " . date('Y-m-d H:i:s') . "\n\n";

// Find expired pending reschedule requests where original booking date has passed (today or earlier)
$expiredRequests = $conn->query("
    SELECT rr.*, 
           u.fullname as student_name, 
           u.email as student_email, 
           u.id as student_id,
           t.fullname as tutor_name,
           t.email as tutor_email,
           t.id as tutor_id,
           b.booking_date as original_date,
           b.booking_time as original_time,
           b.language,
           b.status as booking_status
    FROM reschedule_requests rr
    JOIN users u ON rr.student_id = u.id
    JOIN users t ON rr.tutor_id = t.id
    JOIN bookings b ON rr.booking_id = b.id
    WHERE rr.status = 'pending' 
    AND b.booking_date <= CURDATE()
");

$expiredCount = 0;

while ($request = $expiredRequests->fetch_assoc()) {
    
    $originalDate = date('l, F j, Y', strtotime($request['original_date']));
    $originalTime = date('g:i A', strtotime($request['original_time']));
    $requestedDate = date('l, F j, Y', strtotime($request['new_date']));
    $requestedTime = date('g:i A', strtotime($request['new_time']));
    
    // 1. Update reschedule request to 'rejected'
    $updateReq = $conn->prepare("
        UPDATE reschedule_requests 
        SET status = 'rejected', 
            reject_reason = 'Auto-rejected: Tutor did not respond before the original booking date',
            responded_at = NOW() 
        WHERE id = ?
    ");
    $updateReq->bind_param("i", $request['id']);
    $updateReq->execute();
    
    // 2. Update booking status back to 'confirmed' (keep original session)
    $updateBooking = $conn->prepare("
        UPDATE bookings 
        SET status = 'confirmed' 
        WHERE id = ? AND status = 'rescheduled'
    ");
    $updateBooking->bind_param("i", $request['booking_id']);
    $updateBooking->execute();
    
    // 3. Insert notification for STUDENT
    $studentMessage = "Dear {$request['student_name']},\n\n" .
                      "Your reschedule request for {$request['language']} session has been rejected because the tutor did not respond before your original booking date.\n\n" .
                      "Original Session: {$originalDate} at {$originalTime}\n" .
                      "You Requested: {$requestedDate} at {$requestedTime}\n\n" .
                      "Your original session remains confirmed. Please attend as scheduled.\n\n" .
                      "- Kyoshi Team";
    
    $studentNotif = $conn->prepare("
        INSERT INTO notifications (user_id, title, message, type, is_read, created_at)
        VALUES (?, ?, ?, 'reschedule_rejected', 0, NOW())
    ");
    $studentTitle = "Reschedule Request Rejected";
    $studentNotif->bind_param("iss", $request['student_id'], $studentTitle, $studentMessage);
    $studentNotif->execute();
    
    // 4. Insert notification for TUTOR (warning)
    $tutorMessage = "Dear {$request['tutor_name']},\n\n" .
                    "You did not respond to a reschedule request from {$request['student_name']} for {$request['language']} session before the booking date.\n\n" .
                    "Original Session: {$originalDate} at {$originalTime}\n" .
                    "Student Requested: {$requestedDate} at {$requestedTime}\n\n" .
                    "The request has been rejected. The student will keep the original schedule.\n\n" .
                    "Please respond to future reschedule requests promptly.\n\n" .
                    "- Kyoshi Team";
    
    $tutorNotif = $conn->prepare("
        INSERT INTO notifications (user_id, title, message, type, is_read, created_at)
        VALUES (?, ?, ?, 'warning', 0, NOW())
    ");
    $tutorTitle = "Reschedule Request Rejected - No Response";
    $tutorNotif->bind_param("iss", $request['tutor_id'], $tutorTitle, $tutorMessage);
    $tutorNotif->execute();
    
    // 5. Send EMAIL to STUDENT
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = SMTP_USER;
        $mail->Password = SMTP_PASS;
        $mail->SMTPSecure = 'tls';
        $mail->Port = 587;
        $mail->setFrom('sohisabella87@gmail.com', 'Kyoshi');
        $mail->addAddress($request['student_email'], $request['student_name']);
        $mail->isHTML(true);
        $mail->Subject = 'Reschedule Request Rejected - Kyoshi';
        
        $mail->Body = "
        <html>
        <head>
        <style>
            body { font-family: Arial, sans-serif; }
            .container { max-width: 550px; margin: auto; background: #f9f9f9; border-radius: 20px; padding: 30px; }
            .header { text-align: center; }
            .header h1 { color: #dc2626; }
            .info-box { background: white; border-radius: 16px; padding: 20px; margin: 20px 0; }
            .status-badge { display: inline-block; background: #dc2626; color: white; padding: 4px 12px; border-radius: 20px; font-size: 12px; }
            .footer { text-align: center; font-size: 12px; color: #999; margin-top: 20px; }
        </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>Reschedule Request Rejected</h1>
                </div>
                <div class='info-box'>
                    <p>Dear <strong>{$request['student_name']}</strong>,</p>
                    <p>Your reschedule request has been <strong style='color: #dc2626;'>REJECTED</strong> because the tutor did not respond before your original booking date.</p>
                    
                    <hr>
                    
                    <p><strong>Language:</strong> {$request['language']}</p>
                    <p><strong>Original Session:</strong> {$originalDate} at {$originalTime}</p>
                    <p><strong>You Requested:</strong> {$requestedDate} at {$requestedTime}</p>
                    <p><strong>Status:</strong> <span class='status-badge'>REJECTED</span></p>
                    
                    <hr>
                    
                    <p><strong>What happens now?</strong><br>
                    Your original session remains <strong style='color: #28a745;'>confirmed</strong>. Please attend as scheduled on {$originalDate} at {$originalTime}.</p>
                    
                    <p>If you still need to reschedule, please submit a new request.</p>
                </div>
                <div class='footer'>
                    <p>Kyoshi Language Learning Platform</p>
                </div>
            </div>
        </body>
        </html>
        ";
        $mail->send();
        echo "  Email sent to student: {$request['student_name']}\n";
        
    } catch (Exception $e) {
        error_log("Reschedule reject email failed: " . $mail->ErrorInfo);
        echo "  Email failed to student: {$request['student_name']}\n";
    }
    
    // 6. Send EMAIL to TUTOR (warning)
    $tutorMail = new PHPMailer(true);
    try {
        $tutorMail->isSMTP();
        $tutorMail->Host = 'smtp.gmail.com';
        $tutorMail->SMTPAuth = true;
        $tutorMail->Username = SMTP_USER;
        $tutorMail->Password = SMTP_PASS;
        $tutorMail->SMTPSecure = 'tls';
        $tutorMail->Port = 587;
        $tutorMail->setFrom('sohisabella87@gmail.com', 'Kyoshi');
        $tutorMail->addAddress($request['tutor_email'], $request['tutor_name']);
        $tutorMail->isHTML(true);
        $tutorMail->Subject = 'Reminder: Reschedule Request Expired - Kyoshi';
        
        $tutorMail->Body = "
        <html>
        <head>
        <style>
            body { font-family: Arial, sans-serif; }
            .container { max-width: 550px; margin: auto; background: #f9f9f9; border-radius: 20px; padding: 30px; }
            .header { text-align: center; }
            .header h1 { color: #dc2626; }
            .info-box { background: white; border-radius: 16px; padding: 20px; margin: 20px 0; }
            .warning-box { background: #fee2e2; padding: 15px; border-radius: 12px; margin: 15px 0; }
            .footer { text-align: center; font-size: 12px; color: #999; margin-top: 20px; }
        </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>⚠️ Reschedule Request Expired</h1>
                </div>
                <div class='info-box'>
                    <p>Dear <strong>{$request['tutor_name']}</strong>,</p>
                    <p>You did not respond to a reschedule request from <strong>{$request['student_name']}</strong> before the original booking date.</p>
                    
                    <hr>
                    
                    <p><strong>Student:</strong> {$request['student_name']}</p>
                    <p><strong>Language:</strong> {$request['language']}</p>
                    <p><strong>Original Session:</strong> {$originalDate} at {$originalTime}</p>
                    <p><strong>Student Requested:</strong> {$requestedDate} at {$requestedTime}</p>
                    
                    <hr>
                    
                    <div class='warning-box'>
                        <strong>What happened?</strong><br>
                        The reschedule request has been <strong>AUTO-REJECTED</strong>. The student will keep the original schedule.
                    </div>
                    
                    <div class='warning-box'>
                        <strong>Reminder for next time:</strong><br>
                        Please respond to reschedule requests within 24 hours to avoid disappointing students.
                    </div>
                </div>
                <div class='footer'>
                    <p>Kyoshi Language Learning Platform</p>
                </div>
            </div>
        </body>
        </html>
        ";
        $tutorMail->send();
        echo "  Email sent to tutor: {$request['tutor_name']}\n";
        
    } catch (Exception $e) {
        error_log("Reschedule reject tutor email failed: " . $tutorMail->ErrorInfo);
        echo "  Email failed to tutor: {$request['tutor_name']}\n";
    }
    
    $expiredCount++;
    echo "  Processed request ID: {$request['id']} (Booking: {$request['booking_id']})\n";
}

echo "\n=== SUMMARY ===\n";
echo "Auto-rejected {$expiredCount} expired reschedule requests\n";
echo "Booking status changed back to 'confirmed' for affected bookings\n";
echo "Job completed at: " . date('Y-m-d H:i:s') . "\n";
?>