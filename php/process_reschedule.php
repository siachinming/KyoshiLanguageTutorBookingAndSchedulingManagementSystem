<?php
session_start();
include 'config.php';

// Include PHPMailer
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require '../vendor/autoload.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$data = json_decode(file_get_contents('php://input'), true);
$requestId = $data['request_id'] ?? 0;
$bookingId = $data['booking_id'] ?? 0;
$action = $data['action'] ?? '';
$userID = $_SESSION['user_id'];

if ($action === 'accept') {
    $newDate = $data['new_date'];
    $newTime = $data['new_time'];
    
    $conn->begin_transaction();
    
    try {
        // Get booking details first (before update)
        $stmt = $conn->prepare("
            SELECT b.*, u.fullname as student_name, u.email as student_email, t.fullname as tutor_name
            FROM bookings b
            JOIN users u ON b.student_id = u.id
            JOIN users t ON b.tutor_id = t.id
            WHERE b.id = ?
        ");
        $stmt->bind_param("i", $bookingId);
        $stmt->execute();
        $booking = $stmt->get_result()->fetch_assoc();
        
        // Update booking with new date/time
        $update = $conn->prepare("UPDATE bookings SET booking_date = ?, booking_time = ?, status = 'confirmed' WHERE id = ? AND tutor_id = ?");
        $update->bind_param("ssii", $newDate, $newTime, $bookingId, $userID);
        $update->execute();
        
        // Update reschedule request status to 'approved'
        $updateReq = $conn->prepare("UPDATE reschedule_requests SET status = 'approved', responded_at = NOW() WHERE id = ? AND tutor_id = ?");
        $updateReq->bind_param("ii", $requestId, $userID);
        $updateReq->execute();
        
        $formattedNewDate = date('l, F j, Y', strtotime($newDate));
        $formattedNewTime = date('g:i A', strtotime($newTime));
        
        // Notification for student (in-app)
        $notifTitle = "Reschedule Request Approved";
        $notifMessage = "Your tutor has approved your reschedule request. New date: " . date('d M Y', strtotime($newDate)) . " at " . date('g:i A', strtotime($newTime));
        
       // Check if notification already sent for this reschedule request
        $checkNotif = $conn->prepare("
            SELECT id FROM notifications 
            WHERE user_id = ? 
            AND type = 'reschedule' 
            AND message LIKE ? 
            AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
        ");
        $likeMessage = "%approved your reschedule request%";
        $checkNotif->bind_param("is", $booking['student_id'], $likeMessage);
        $checkNotif->execute();
        $existingNotif = $checkNotif->get_result()->fetch_assoc();

        if (!$existingNotif) {
            // Only insert if not sent in last hour
            $notif = $conn->prepare("INSERT INTO notifications (user_id, title, message, type, is_read, created_at) VALUES (?, ?, ?, 'reschedule', 0, NOW())");
            $notif->bind_param("iss", $booking['student_id'], $notifTitle, $notifMessage);
            $notif->execute();
        }
            
        
        // Send EMAIL to STUDENT
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
            $mail->addAddress($booking['student_email'], $booking['student_name']);
            $mail->isHTML(true);
            $mail->Subject = 'Reschedule Request Approved - Kyoshi';
            $mail->Body = "
                <div style='font-family: Arial, sans-serif; max-width: 600px; margin: auto; background: #f9f9f9; border-radius: 20px; padding: 30px;'>
                    <div style='text-align: center;'>
                        <h1 style='color: #1d3156;'>Reschedule Request Approved</h1>
                    </div>
                    <div style='background: white; border-radius: 16px; padding: 20px;'>
                        <p>Dear <strong>{$booking['student_name']}</strong>,</p>
                        <p>Your tutor <strong>{$booking['tutor_name']}</strong> has approved your reschedule request.</p>
                        <div style='background: #e8f4f8; border-radius: 12px; padding: 15px; margin: 20px 0;'>
                            <p><strong>Language:</strong> {$booking['language']}</p>
                            <p><strong>New Date:</strong> {$formattedNewDate}</p>
                            <p><strong>New Time:</strong> {$formattedNewTime}</p>
                            <p><strong>Learning Mode:</strong> " . ucfirst($booking['learning_mode']) . "</p>
                        </div>
                        <p>Your session has been rescheduled to the new date and time.</p>
                    </div>
                    <div style='text-align: center; margin-top: 20px;'>
                        <a href='http://localhost/kyoshi/php/booking_detail.php?id={$bookingId}' 
                           style='display: inline-block; padding: 12px 30px; background: #1d3156; color: white; 
                                  text-decoration: none; border-radius: 30px;'>View Booking Details</a>
                    </div>
                </div>
            ";
            $mail->send();
        } catch (Exception $e) {
            error_log("Reschedule accept email failed: " . $mail->ErrorInfo);
        }
        
        $conn->commit();
        echo json_encode(['success' => true]);
        
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    
} elseif ($action === 'reject') {
    $rejectReason = $data['reject_reason'] ?? 'No reason provided';
    
    // Get booking details first
    $stmt = $conn->prepare("
        SELECT b.*, u.fullname as student_name, u.email as student_email, t.fullname as tutor_name
        FROM bookings b
        JOIN users u ON b.student_id = u.id
        JOIN users t ON b.tutor_id = t.id
        WHERE b.id = ?
    ");
    $stmt->bind_param("i", $bookingId);
    $stmt->execute();
    $booking = $stmt->get_result()->fetch_assoc();
    
    // Update reschedule request status to 'rejected' with reason
    $updateReq = $conn->prepare("UPDATE reschedule_requests SET status = 'rejected', reject_reason = ?, responded_at = NOW() WHERE id = ? AND tutor_id = ?");
    $updateReq->bind_param("sii", $rejectReason, $requestId, $userID);

    if ($updateReq->execute()) {
        $updateBooking = $conn->prepare("UPDATE bookings SET status = 'confirmed' WHERE id = ? AND tutor_id = ? AND status = 'rescheduled'");
        $updateBooking->bind_param("ii", $bookingId, $userID);
        $updateBooking->execute();
        $formattedOriginalDate = date('l, F j, Y', strtotime($booking['booking_date']));
        $formattedOriginalTime = date('g:i A', strtotime($booking['booking_time']));
        
        // Notification for student (in-app)
        $notifTitle = "Reschedule Request Declined";
        $notifMessage = "Your tutor has declined your reschedule request. Reason: " . $rejectReason;
        
        $notif = $conn->prepare("INSERT INTO notifications (user_id, title, message, type, is_read, created_at) VALUES (?, ?, ?, 'reschedule', 0, NOW())");
        $notif->bind_param("iss", $booking['student_id'], $notifTitle, $notifMessage);
        $notif->execute();
        
        // Send EMAIL to STUDENT
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
            $mail->addAddress($booking['student_email'], $booking['student_name']);
            $mail->isHTML(true);
            $mail->Subject = 'Reschedule Request Declined - Kyoshi';
            $mail->Body = "
                <div style='font-family: Arial, sans-serif; max-width: 600px; margin: auto; background: #f9f9f9; border-radius: 20px; padding: 30px;'>
                    <div style='text-align: center;'>
                        <h1 style='color: #dc2626;'>Reschedule Request Declined</h1>
                    </div>
                    <div style='background: white; border-radius: 16px; padding: 20px;'>
                        <p>Dear <strong>{$booking['student_name']}</strong>,</p>
                        <p>Your tutor <strong>{$booking['tutor_name']}</strong> has declined your reschedule request.</p>
                        <div style='background: #e8f4f8; border-radius: 12px; padding: 15px; margin: 20px 0;'>
                            <p><strong>Language:</strong> {$booking['language']}</p>
                            <p><strong>Original Date:</strong> {$formattedOriginalDate}</p>
                            <p><strong>Original Time:</strong> {$formattedOriginalTime}</p>
                        </div>
                        <p><strong>Reason given:</strong> {$rejectReason}</p>
                        <p>Your original session remains as scheduled.</p>
                    </div>
                    <div style='text-align: center; margin-top: 20px;'>
                        <a href='http://localhost/kyoshi/php/booking_detail.php?id={$bookingId}' 
                           style='display: inline-block; padding: 12px 30px; background: #1d3156; color: white; 
                                  text-decoration: none; border-radius: 30px;'>View Booking Details</a>
                    </div>
                </div>
            ";
            $mail->send();
        } catch (Exception $e) {
            error_log("Reschedule reject email failed: " . $mail->ErrorInfo);
        }
        
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => $conn->error]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid action']);
}
?>