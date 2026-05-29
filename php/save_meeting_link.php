<?php
error_reporting(0);
ini_set('display_errors', 0);

session_start();

include __DIR__ . '/config.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require __DIR__ . '/../vendor/autoload.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$data = json_decode(file_get_contents('php://input'), true);
$booking_id = isset($data['booking_id']) ? intval($data['booking_id']) : 0;
$meeting_link = isset($data['meeting_link']) ? trim($data['meeting_link']) : '';
$userID = $_SESSION['user_id'];

if (!$booking_id) {
    echo json_encode(['success' => false, 'message' => 'Invalid booking ID']);
    exit();
}

if (empty($meeting_link)) {
    echo json_encode(['success' => false, 'message' => 'Please enter a meeting link']);
    exit();
}

if (!filter_var($meeting_link, FILTER_VALIDATE_URL)) {
    echo json_encode(['success' => false, 'message' => 'Please enter a valid URL starting with http:// or https://']);
    exit();
}

// Verify booking belongs to this tutor and get current link
$stmt = $conn->prepare("
    SELECT b.*, u.fullname as student_name, u.email as student_email, u.id as student_id
    FROM bookings b
    JOIN users u ON b.student_id = u.id
    WHERE b.id = ? AND b.tutor_id = ?
");
$stmt->bind_param("ii", $booking_id, $userID);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'Booking not found or you do not have permission']);
    exit();
}

$booking = $result->fetch_assoc();
$old_link = $booking['meeting_link'];
$isNewLink = empty($old_link);

// Check if the link actually changed
if ($old_link === $meeting_link && !$isNewLink) {
    echo json_encode(['success' => true, 'message' => 'No changes made to meeting link']);
    exit();
}

// Update meeting link
$update = $conn->prepare("UPDATE bookings SET meeting_link = ?, link_provided_at = NOW() WHERE id = ?");
$update->bind_param("si", $meeting_link, $booking_id);

if ($update->execute()) {
    $bookingDate = date('l, F j, Y', strtotime($booking['booking_date']));
    $bookingTime = date('g:i A', strtotime($booking['booking_time']));
    
    // Create tracking link that goes through join_meeting.php for attendance recording
    $tracking_link = "join_meeting.php?booking_id=" . $booking['id'] . "&link=" . urlencode($meeting_link);
    
    // Insert notification for STUDENT with tracking link
    if ($isNewLink) {
        $notifTitle = "Meeting Link Added!";
        $notifMessage = "Your tutor has added the meeting link for your {$booking['language']} session on {$bookingDate} at {$bookingTime}.";
    } else {
        $notifTitle = "Meeting Link Updated";
        $notifMessage = "Your tutor has updated the meeting link for your {$booking['language']} session on {$bookingDate} at {$bookingTime}.";
    }
    
    $notifStmt = $conn->prepare("
        INSERT INTO notifications (user_id, title, message, type, link, is_read, created_at)
        VALUES (?, ?, ?, 'meeting_link', ?, 0, NOW())
    ");
    $notifStmt->bind_param("isss", $booking['student_id'], $notifTitle, $notifMessage, $tracking_link);
    $notifStmt->execute();
    
    // Send email to STUDENT with tracking link
    sendMeetingLinkEmail($booking, $tracking_link, $bookingDate, $bookingTime, $isNewLink, $tutorName ?? '');
    
    echo json_encode([
        'success' => true, 
        'message' => $isNewLink ? 'Meeting link added successfully!' : 'Meeting link updated successfully!'
    ]);
} else {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
}

// ============================================================
// FUNCTION TO SEND MEETING LINK EMAIL
// ============================================================
function sendMeetingLinkEmail($booking, $tracking_link, $bookingDate, $bookingTime, $isNewLink, $tutorName) {
    $mail = new PHPMailer(true);
    
    // Get tutor name if not provided
    if (empty($tutorName)) {
        $tutorStmt = $GLOBALS['conn']->prepare("SELECT fullname FROM users WHERE id = ?");
        $tutorStmt->bind_param("i", $booking['tutor_id']);
        $tutorStmt->execute();
        $tutorResult = $tutorStmt->get_result();
        if ($tutorResult->num_rows > 0) {
            $tutor = $tutorResult->fetch_assoc();
            $tutorName = $tutor['fullname'];
        }
    }
    
    try {
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = SMTP_USER;
        $mail->Password = SMTP_PASS;
        $mail->SMTPSecure = 'tls';
        $mail->Port = 587;
        $mail->CharSet = 'UTF-8';
        $mail->setFrom('sohisabella87@gmail.com', 'Kyoshi');
        $mail->addAddress($booking['student_email'], $booking['student_name']);
        $mail->isHTML(true);
        
        if ($isNewLink) {
            $mail->Subject = 'Meeting Link Available for Your Session - Kyoshi';
        } else {
            $mail->Subject = 'Meeting Link Updated for Your Session - Kyoshi';
        }
        
        $mail->Body = "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <style>
                body { font-family: 'Segoe UI', Arial, sans-serif; margin: 0; padding: 0; background: #f5f5f5; }
                .container { max-width: 580px; margin: 0 auto; padding: 20px; }
                .card { background: white; border-radius: 20px; overflow: hidden; box-shadow: 0 8px 30px rgba(201,79,134,.12); }
                .header { background: linear-gradient(135deg, #E75A9B, #F28AB2); padding: 32px; text-align: center; }
                .header h1 { margin: 0; color: white; font-size: 24px; }
                .header p { margin: 8px 0 0; color: rgba(255,255,255,.88); font-size: 14px; }
                .content { padding: 28px 32px; }
                .session-info { background: #e8f4f8; border-radius: 16px; padding: 20px; margin: 20px 0; }
                .info-row { margin: 8px 0; }
                .info-label { font-weight: 700; color: #1d3156; }
                .btn { display: inline-block; padding: 14px 32px; background: linear-gradient(135deg, #E75A9B, #F28AB2); color: white; text-decoration: none; border-radius: 999px; font-weight: 700; font-size: 14px; }
                .btn:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(231,90,155,.3); }
                .footer { text-align: center; font-size: 12px; color: #999; margin-top: 20px; padding-top: 20px; border-top: 1px solid #eee; }
                .note { background: #fef3c7; border-radius: 12px; padding: 12px; margin-top: 20px; font-size: 12px; color: #92400e; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='card'>
                    <div class='header'>
                        <h1>" . ($isNewLink ? "Meeting Link Added" : "Meeting Link Updated") . "</h1>
                        <p>Your tutor has " . ($isNewLink ? "added the" : "updated the") . " meeting link</p>
                    </div>
                    <div class='content'>
                        <p>Dear <strong>" . htmlspecialchars($booking['student_name']) . "</strong>,</p>
                        <p>Your tutor <strong>" . htmlspecialchars($tutorName) . "</strong> has " . ($isNewLink ? "added the" : "updated the") . " meeting link for your upcoming session.</p>
                        
                        <div class='session-info'>
                            <div class='info-row'><span class='info-label'>Language:</span> " . htmlspecialchars($booking['language']) . "</div>
                            <div class='info-row'><span class='info-label'>Date:</span> {$bookingDate}</div>
                            <div class='info-row'><span class='info-label'>Time:</span> {$bookingTime}</div>
                        </div>
                        
                        <div style='text-align: center; margin: 30px 0;'>
                            <a href='{$tracking_link}' class='btn'>
                                Join Meeting Now
                            </a>
                        </div>
                        
                        <div class='note'>
                            <strong>📌 Note:</strong> When you click the button above, your attendance will be automatically recorded.
                            Please only click when you are ready to join the session.
                        </div>
                    </div>
                    <div class='footer'>
                        <p>&copy; " . date('Y') . " Kyoshi - Language Learning Platform</p>
                        <p>Need help? Contact us at support@kyoshi.com</p>
                    </div>
                </div>
            </div>
        </body>
        </html>
        ";
        
        $mail->send();
    } catch (Exception $e) {
        error_log("Meeting link email failed for booking {$booking['id']}: " . $e->getMessage());
    }
}
?>