<?php
include 'config.php';

// Include PHPMailer for emails
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require '../vendor/autoload.php';

// Get current date (today)
$today = date('Y-m-d');

// Find pending reschedule requests where ORIGINAL booking date is LESS than today
// (meaning the original class date has passed and tutor never responded)
$sql = "SELECT rr.*, 
        u.fullname as student_name, 
        u.email as student_email, 
        u.id as student_id,
        t.fullname as tutor_name,
        t.email as tutor_email,
        b.booking_date as original_date,
        b.booking_time as original_time
        FROM reschedule_requests rr
        JOIN users u ON rr.student_id = u.id
        JOIN users t ON rr.tutor_id = t.id
        JOIN bookings b ON rr.booking_id = b.id
        WHERE rr.status = 'pending' 
        AND b.booking_date < ?";

$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $today);
$stmt->execute();
$expiredRequests = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$expiredCount = 0;

foreach ($expiredRequests as $request) {
    $conn->begin_transaction();
    
    try {
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
        
        // 2. Update booking status back to 'confirmed' (original date/time stays)
        $updateBooking = $conn->prepare("
            UPDATE bookings 
            SET status = 'confirmed' 
            WHERE id = ? AND status = 'rescheduled'
        ");
        $updateBooking->bind_param("i", $request['booking_id']);
        $updateBooking->execute();
        
        $originalDate = date('l, F j, Y', strtotime($request['original_date']));
        $originalTime = date('g:i A', strtotime($request['original_time']));
        
        // 3. Insert notification for STUDENT
        $notifTitle = "Reschedule Request Expired";
        $notifMessage = "Your reschedule request has expired because the tutor did not respond before your original session date ({$originalDate}). Your original session remains as scheduled.";
        
        $notif = $conn->prepare("
            INSERT INTO notifications (user_id, title, message, type, is_read, created_at)
            VALUES (?, ?, ?, 'reschedule_expired', 0, NOW())
        ");
        $notif->bind_param("iss", $request['student_id'], $notifTitle, $notifMessage);
        $notif->execute();
        
        // 4. Insert notification for TUTOR (warning)
        $tutorNotifTitle = "Reschedule Request Expired - Missed Response";
        $tutorNotifMessage = "You did not respond to a reschedule request from {$request['student_name']} before the original booking date. The request has expired. Please respond to future requests promptly.";
        
        $tutorNotif = $conn->prepare("
            INSERT INTO notifications (user_id, title, message, type, is_read, created_at)
            VALUES (?, ?, ?, 'warning', 0, NOW())
        ");
        $tutorNotif->bind_param("iss", $request['tutor_id'], $tutorNotifTitle, $tutorNotifMessage);
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
            $mail->Subject = 'Reschedule Request Expired - Kyoshi';
            $mail->Body = "
                <div style='font-family: Arial, sans-serif; max-width: 600px; margin: auto; background: #f9f9f9; border-radius: 20px; padding: 30px;'>
                    <div style='text-align: center;'>
                        <h1 style='color: #f59e0b;'>Reschedule Request Expired</h1>
                    </div>
                    <div style='background: white; border-radius: 16px; padding: 20px;'>
                        <p>Dear <strong>{$request['student_name']}</strong>,</p>
                        <p>Your reschedule request has expired because the tutor did not respond before your original session date.</p>
                        <div style='background: #e8f4f8; border-radius: 12px; padding: 15px; margin: 20px 0;'>
                            <p><strong>Language:</strong> {$request['language']}</p>
                            <p><strong>Original Date:</strong> {$originalDate}</p>
                            <p><strong>Original Time:</strong> {$originalTime}</p>
                        </div>
                        <p>Your original session remains as scheduled.</p>
                        <p>If you still need to reschedule, please submit a new request.</p>
                    </div>
                </div>
            ";
            $mail->send();
        } catch (Exception $e) {
            error_log("Reschedule expire email failed for booking {$request['booking_id']}: " . $mail->ErrorInfo);
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
            $tutorMail->Subject = 'Reschedule Request Expired - Action Required';
            $tutorMail->Body = "
                <div style='font-family: Arial, sans-serif; max-width: 600px; margin: auto; background: #f9f9f9; border-radius: 20px; padding: 30px;'>
                    <div style='text-align: center;'>
                        <h1 style='color: #dc2626;'>Reschedule Request Expired</h1>
                    </div>
                    <div style='background: white; border-radius: 16px; padding: 20px;'>
                        <p>Dear <strong>{$request['tutor_name']}</strong>,</p>
                        <p>You did not respond to a reschedule request from <strong>{$request['student_name']}</strong> before the original booking date.</p>
                        <div style='background: #e8f4f8; border-radius: 12px; padding: 15px; margin: 20px 0;'>
                            <p><strong>Student:</strong> {$request['student_name']}</p>
                            <p><strong>Language:</strong> {$request['language']}</p>
                            <p><strong>Original Date:</strong> {$originalDate}</p>
                            <p><strong>Original Time:</strong> {$originalTime}</p>
                            <p><strong>Requested New Date:</strong> " . date('d M Y', strtotime($request['new_date'])) . " at " . date('g:i A', strtotime($request['new_time'])) . "</p>
                        </div>
                        <p style='color: #dc2626;'><strong>Please respond to reschedule requests promptly to avoid student disappointment.</strong></p>
                    </div>
                </div>
            ";
            $tutorMail->send();
        } catch (Exception $e) {
            error_log("Reschedule expire tutor email failed: " . $tutorMail->ErrorInfo);
        }
        
        $conn->commit();
        $expiredCount++;
        
    } catch (Exception $e) {
        $conn->rollback();
        error_log("Reschedule expire failed for request {$request['id']}: " . $e->getMessage());
    }
}

// Output result (for cron logging)
echo date('Y-m-d H:i:s') . " - Expired {$expiredCount} reschedule requests.\n";
?>