<?php
session_start();
include 'config.php';

// Include PHPMailer
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require '../vendor/autoload.php';

$assetBase = '../assets/img';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$userID = $_SESSION['user_id'];

/* ─────────────────────────────
   GET TUTOR INFO
───────────────────────────── */
$stmt = $conn->prepare("SELECT * FROM users WHERE id = ? AND role = 'tutor'");
$stmt->bind_param("i", $userID);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

if (!$user) {
    header("Location: login.php");
    exit();
}

$displayName = $user['fullname'];
$profilePic = !empty($user['profile_pic'])
    ? '../uploads/profiles/' . $user['profile_pic']
    : $assetBase . '/profile-tutor.png';
$tutorEmail = $user['email'];

/* ─────────────────────────────
   HANDLE ACCEPT/REJECT
───────────────────────────── */
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['accept_booking'])) {
        $booking_id = $_POST['booking_id'];
        
        $stmt = $conn->prepare("UPDATE bookings SET status = 'accepted' WHERE id = ? AND tutor_id = ?");
        $stmt->bind_param("ii", $booking_id, $userID);
        
        if ($stmt->execute()) {
            $stmt2 = $conn->prepare("
                SELECT b.*, u.fullname as student_name, u.email as student_email, u.id as student_id
                FROM bookings b
                JOIN users u ON b.student_id = u.id
                WHERE b.id = ?
            ");
            $stmt2->bind_param("i", $booking_id);
            $stmt2->execute();
            $booking = $stmt2->get_result()->fetch_assoc();
            
            $bookingDate = date('l, F j, Y', strtotime($booking['booking_date']));
            $bookingTime = date('g:i A', strtotime($booking['booking_time']));
            
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
                // Notification for student (in-app)
                $notifTitle = "Reschedule Request Approved";
                $notifMessage = "Your tutor has approved your reschedule request. New date: " . date('d M Y', strtotime($newDate)) . " at " . date('g:i A', strtotime($newTime));
                
                $notif = $conn->prepare("INSERT INTO notifications (user_id, title, message, type, is_read, created_at) VALUES (?, ?, ?, 'reschedule', 0, NOW())");
                $notif->bind_param("iss", $booking['student_id'], $notifTitle, $notifMessage);
                $notif->execute();
            }
            
            // Send email
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
                $mail->Subject = 'Booking Accepted by your tutor - Kyoshi';
                $mail->Body = "
                    <div style='font-family: Arial, sans-serif; max-width: 600px; margin: auto; background: #f9f9f9; border-radius: 20px; padding: 30px;'>
                        <div style='text-align: center;'>
                            <h1 style='color: #1d3156;'>Booking Accepted!</h1>
                        </div>
                        <div style='background: white; border-radius: 16px; padding: 20px;'>
                            <p>Dear <strong>{$booking['student_name']}</strong>,</p>
                            <p>Your booking has been <strong style='color: #28a745;'>ACCEPTED</strong> by tutor {$displayName}.</p>
                            <div style='background: #e8f4f8; border-radius: 12px; padding: 15px; margin: 20px 0;'>
                                <p><strong>Language:</strong> {$booking['language']}</p>
                                <p><strong>Date:</strong> {$bookingDate}</p>
                                <p><strong>Time:</strong> {$bookingTime}</p>
                                <p><strong>Rate:</strong> RM {$booking['total_amount']}</p>
                                <hr style='margin:10px 0;'>
                                <p><strong>Learning Mode:</strong> " . ucfirst($booking['learning_mode']) . "</p>
                                ";

                                if ($booking['learning_mode'] !== 'online') {
                                    $location = !empty($booking['meeting_location']) 
                                        ? $booking['meeting_location'] 
                                        : 'To be confirmed';

                                    $mail->Body .= "
                                            <p><strong>Location:</strong> {$location}</p>
                                    ";
                                }

                                $mail->Body .= "
                            </div>
                            <p><strong>Next Steps:</strong><br><br> Please proceed to payment.</p>
                        </div>
                        <div style='text-align: center; margin-top: 20px;'>
                            <a href='http://localhost/kyoshi/php/payment_form.php?id={$booking_id}' 
                               style='display: inline-block; padding: 12px 30px; background: #1d3156; color: white; 
                                      text-decoration: none; border-radius: 30px;'>PAY NOW</a>
                        </div>
                    </div>
                ";
                $mail->send();
            }catch (Exception $e) {
    echo "Mailer Error: " . $mail->ErrorInfo;
}
            
            $message = "Booking accepted successfully! Student has been notified.";
            $messageType = "success";
        } else {
            $message = "Error accepting booking: " . $conn->error;
            $messageType = "error";
        }
    }
    
    if (isset($_POST['reject_booking'])) {
        $booking_id = $_POST['booking_id'];
        $reject_reason = $_POST['reject_reason'] ?? 'No reason provided';
        
        $stmt = $conn->prepare("UPDATE bookings SET status = 'cancelled', cancelled_by = 'tutor', cancel_reason = ? WHERE id = ? AND tutor_id = ?");
$stmt->bind_param("sii", $reject_reason, $booking_id, $userID);
        
        if ($stmt->execute()) {
            $stmt2 = $conn->prepare("
                SELECT b.*, u.fullname as student_name, u.email as student_email, u.id as student_id
                FROM bookings b
                JOIN users u ON b.student_id = u.id
                WHERE b.id = ?
            ");
            $stmt2->bind_param("i", $booking_id);
            $stmt2->execute();
            $booking = $stmt2->get_result()->fetch_assoc();
            
           // Check if notification already sent
            $checkNotif = $conn->prepare("
                SELECT id FROM notifications 
                WHERE user_id = ? 
                AND type = 'reschedule' 
                AND message LIKE ? 
                AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
            ");
            $likeMessage = "%declined your reschedule request%";
            $checkNotif->bind_param("is", $booking['student_id'], $likeMessage);
            $checkNotif->execute();
            $existingNotif = $checkNotif->get_result()->fetch_assoc();

            if (!$existingNotif) {
                $notif = $conn->prepare("INSERT INTO notifications (user_id, title, message, type, is_read, created_at) VALUES (?, ?, ?, 'reschedule', 0, NOW())");
                $notif->bind_param("iss", $booking['student_id'], $notifTitle, $notifMessage);
                $notif->execute();
            }
            
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
                $mail->Subject = 'Booking Request Update - Kyoshi';
                $mail->Body = "
                    <div style='font-family: Arial, sans-serif; max-width: 600px; margin: auto; background: #f9f9f9; border-radius: 20px; padding: 30px;'>
                        <div style='text-align: center;'>
                            <h1 style='color: #dc2626;'>Booking Request Declined</h1>
                        </div>
                        <div style='background: white; border-radius: 16px; padding: 20px;'>
                            <p>Dear {$booking['student_name']},</p>
                            <p>Your booking request has been <strong style='color: #dc2626;'>DECLINED</strong> by tutor {$displayName}.</p>
                            <p><strong>Reason:</strong> {$reject_reason}</p>
                        </div>
                    </div>
                ";
                $mail->send();
            } catch (Exception $e) {
                error_log("Email failed: " . $mail->ErrorInfo);
            }
            
            $message = "Booking rejected successfully.";
            $messageType = "success";
        } else {
            $message = "Error rejecting booking: " . $conn->error;
            $messageType = "error";
        }
    }
}
/* ─────────────────────────────
   FETCH BOOKING REQUESTS - EACH SLOT INDIVIDUALLY
───────────────────────────── */
// Get all pending bookings - EACH ROW IS ONE SLOT
$stmt = $conn->prepare("
    SELECT 
        b.id, 
        b.language, 
        b.booking_date, 
        b.booking_time, 
        b.total_amount, 
        b.status,
        b.created_at,
        b.learning_mode,
        b.meeting_location,  
        u.fullname as student_name, 
        u.email as student_email,
        u.id as student_id,
        tp.rate
    FROM bookings b
    JOIN users u ON b.student_id = u.id
    JOIN tutor_profiles tp ON b.tutor_id = tp.user_id
    WHERE b.tutor_id = ? AND b.status = 'pending'
    ORDER BY b.booking_date ASC, b.booking_time ASC
");
$stmt->bind_param("i", $userID);
$stmt->execute();
$pendingRequests = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Upcoming confirmed bookings
$stmt = $conn->prepare("
    SELECT b.*, b.meeting_location, b.learning_mode, u.fullname as student_name, u.email as student_email
    FROM bookings b
    JOIN users u ON b.student_id = u.id
    WHERE b.tutor_id = ? AND b.status = 'confirmed' AND b.booking_date >= CURDATE()
    ORDER BY b.booking_date ASC, b.booking_time ASC
");
$stmt->bind_param("i", $userID);
$stmt->execute();
$upcomingBookings = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt = $conn->prepare("
    SELECT DISTINCT language 
    FROM tutor_languages
    WHERE user_id = ?
    ORDER BY language ASC
");
$stmt->bind_param("i", $userID);
$stmt->execute();
$tutorLanguages = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$stmt = $conn->prepare("
    SELECT rr.*, 
           u.fullname as student_name,
           b.id as booking_id,
           b.total_amount
    FROM reschedule_requests rr
    JOIN users u ON rr.student_id = u.id
    JOIN bookings b ON rr.booking_id = b.id
    WHERE rr.tutor_id = ? AND rr.status = 'pending'
    ORDER BY rr.created_at ASC
");
$stmt->bind_param("i", $userID);
$stmt->execute();
$rescheduleRequests = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

function e($value) {
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Booking Requests - Kyoshi Tutor</title>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">

<style>
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: 'Poppins', sans-serif;
    background: url('../assets/img/background2.png') no-repeat center top;
    background-size: cover;
    min-height: 100vh;
    position: relative;
}

body::before {
    content: '';
    position: fixed;
    inset: 0;
    background: rgba(255, 255, 255, 0.25);
    z-index: -1;
}

.topbar {
    width: 100%;
    background: rgba(254, 214, 206, 0.92);
    backdrop-filter: blur(12px);
    position: sticky;
    top: 0;
    z-index: 999;
    box-shadow: 0 2px 20px rgba(0, 0, 0, 0.08);
    border-bottom: 1px solid rgba(255, 255, 255, 0.3);
}

.container {
    width: min(1400px, 94%);
    margin: auto;
}

.nav {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 32px;
    min-height: 70px;
}

.brand {
    display: flex;
    align-items: center;
    gap: 10px;
    text-decoration: none;
    flex-shrink: 0;
}

.brand img {
    width: 42px;
    height: 42px;
    object-fit: contain;
}

.brand strong {
    display: block;
    color: #1d3156;
    font-size: 20px;
    line-height: 1.2;
}

.brand span {
    color: #496894;
    font-size: 11px;
}

.nav-links {
    display: flex;
    gap: 28px;
    align-items: center;
    flex-wrap: wrap;
}

.nav-links a {
    text-decoration: none;
    color: #1d3156;
    font-size: 14px;
    font-weight: 600;
    position: relative;
    transition: 0.25s;
    padding: 6px 0;
}

.nav-links a:hover,
.nav-links a.active {
    color: #496894;
}

.nav-links a::after {
    content: '';
    position: absolute;
    left: 0;
    bottom: -6px;
    width: 0%;
    height: 3px;
    background: #496894;
    transition: 0.25s;
    border-radius: 10px;
}

.nav-links a:hover::after,
.nav-links .active::after {
    width: 100%;
}

.profile {
    display: flex;
    align-items: center;
    gap: 8px;
    background: rgba(255, 255, 255, 0.1);
    border: 1px solid rgba(255, 255, 255, 0.2);
    padding: 6px 14px 6px 8px;
    border-radius: 40px;
    cursor: pointer;
    color: black;
    transition: 0.25s;
}

.profile:hover {
    background: rgba(255, 255, 255, 0.2);
}

.profile img {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    object-fit: cover;
    border: 2px solid rgba(255, 255, 255, 0.3);
}

.profile span {
    font-size: 13px;
    font-weight: 500;
}

.dropdown {
    position: absolute;
    top: calc(100% + 10px);
    right: 0;
    width: 220px;
    background: white;
    border-radius: 16px;
    overflow: hidden;
    display: none;
    border: 1px solid #e2edf7;
    box-shadow: 0 15px 35px rgba(0, 0, 0, 0.15);
    z-index: 1000;
}

.dropdown a {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 18px;
    text-decoration: none;
    color: #1e293b;
    font-size: 13px;
    font-weight: 500;
}

.dropdown a:hover {
    background: #f8fafc;
}

.dropdown hr {
    border: none;
    border-top: 1px solid #ecf3f9;
}

.main {
    width: min(1280px, 92%);
    margin: 32px auto 48px;
}

.page-header {
    background: linear-gradient(135deg, rgba(29, 49, 86, 0.95), rgba(73, 104, 148, 0.9));
    border-radius: 28px;
    padding: 32px 36px;
    color: white;
    margin-bottom: 32px;
}

.page-header h1 {
    font-size: 32px;
    font-weight: 700;
    margin-bottom: 10px;
}

.page-header p {
    color: #cbddee;
    font-size: 15px;
}

.alert {
    padding: 14px 20px;
    border-radius: 12px;
    margin-bottom: 24px;
    font-weight: 500;
    animation: slideDown 0.3s ease;
}

@keyframes slideDown {
    from { opacity: 0; transform: translateY(-10px); }
    to { opacity: 1; transform: translateY(0); }
}

.alert-success {
    background: #d4edda;
    color: #155724;
    border-left: 4px solid #28a745;
}

.alert-error {
    background: #f8d7da;
    color: #721c24;
    border-left: 4px solid #dc3545;
}

/* Section Tabs */
.section-tabs {
    display: flex;
    justify-content: center;
    gap: 30px;
    margin-bottom: 32px;
}

.tab-btn {
    background: none;
    border: none;
    padding: 12px 28px;
    font-size: 16px;
    font-weight: 600;
    color: #64748b;
    cursor: pointer;
    transition: 0.2s;
    position: relative;
    border-radius: 40px;
}

.tab-btn.active {
    color: #1d3156;
    background: rgba(29, 49, 86, 0.1);
}

.tab-btn:hover {
    color: #1d3156;
    background: rgba(29, 49, 86, 0.05);
}

.tab-content {
    display: none;
}

.tab-content.active {
    display: block;
}

/* Table Styles */
.bookings-table {
    width: 100%;
    background: white;
    border-radius: 20px;
    overflow: hidden;
    border-collapse: collapse;
}

.bookings-table th {
    background: #f8fafc;
    padding: 16px;
    text-align: left;
    font-size: 13px;
    font-weight: 600;
    color: #1d3156;
    border-bottom: 2px solid #eef2f7;
}

.bookings-table td {
    padding: 16px;
    font-size: 13px;
    color: #475569;
    border-bottom: 1px solid #eef2f7;
    vertical-align: middle;
}

.bookings-table tr:hover {
    background: #f8fafc;
}

.student-cell {
    font-weight: 700;
    color: #1d3156;
}

.status-badge {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 700;
}

.status-pending {
    background: #fff3e0;
    color: #e67e22;
}

.status-confirmed {
    background: #d4edda;
    color: #28a745;
}

.action-buttons {
    display: flex;
    gap: 8px;
}

.btn-accept {
    background: #28a745;
    color: white;
    border: none;
    padding: 6px 14px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    transition: 0.2s;
}

.btn-accept:hover {
    background: #218838;
}

.btn-reject {
    background: #dc2626;
    color: white;
    border: none;
    padding: 6px 14px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    transition: 0.2s;
}

.btn-reject:hover {
    background: #c82333;
}

.btn-view {
    background: #e2e8f0;
    color: #1d3156;
    border: none;
    padding: 6px 14px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    text-decoration: none;
    display: inline-block;
}

.btn-view:hover {
    background: #cbd5e1;
}

.empty-state {
    text-align: center;
    padding: 60px 20px;
    background: white;
    border-radius: 20px;
    color: #94a3b8;
}

.empty-state i {
    font-size: 64px;
    margin-bottom: 16px;
    display: block;
}

/* Reject Modal */
.modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.5);
    z-index: 1000;
    align-items: center;
    justify-content: center;
}

.modal.active {
    display: flex;
}

.modal-content {
    background: white;
    border-radius: 24px;
    width: 450px;
    max-width: 90%;
    padding: 28px;
}

.modal-content h3 {
    font-size: 20px;
    color: #1d3156;
    margin-bottom: 16px;
}

.modal-content textarea {
    width: 100%;
    padding: 12px;
    border: 1.5px solid #e2e8f0;
    border-radius: 12px;
    font-family: 'Poppins', sans-serif;
    font-size: 14px;
    resize: vertical;
    min-height: 100px;
    margin-bottom: 20px;
}

.modal-buttons {
    display: flex;
    gap: 12px;
    justify-content: flex-end;
}

@media (max-width: 900px) {
    .bookings-table, .bookings-table tbody, .bookings-table tr, .bookings-table td {
        display: block;
        width: 100%;
    }
    .bookings-table thead {
        display: none;
    }
    .bookings-table tr {
        margin-bottom: 16px;
        border: 1px solid #eef2f7;
        border-radius: 16px;
        padding: 12px;
    }
    .bookings-table td {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 10px 12px;
        border: none;
        border-bottom: 1px solid #eef2f7;
    }
    .bookings-table td:last-child {
        border-bottom: none;
    }
    .bookings-table td::before {
        content: attr(data-label);
        font-weight: 700;
        color: #1d3156;
        margin-right: 16px;
    }
    .action-buttons {
        justify-content: flex-end;
    }
}

@media (max-width: 768px) {
    .page-header {
        padding: 24px;
    }
    .page-header h1 {
        font-size: 24px;
    }
    .nav-links {
        gap: 14px;
    }
    .nav-links a {
        font-size: 12px;
    }
    .section-tabs {
        gap: 12px;
    }
    .tab-btn {
        padding: 8px 16px;
        font-size: 14px;
    }
}

/* Back Button Hover Effect */
.back-btn:hover {
    background: #b8d0e9;
    border-color: #6b9cd7;
    transform: translateX(-3px);
}
</style>
</head>

<body>

<header class="topbar">
    <div class="container">
        <nav class="nav">
            <a href="tutor_dashboard.php" class="brand">
                <img src="<?= e($assetBase) ?>/logo.png" alt="Kyoshi">
                <div>
                    <strong>Kyoshi</strong>
                    <span>Teacher Space</span>
                </div>
            </a>
            <div class="nav-links">
                <a href="tutor_dashboard.php">Dashboard</a>
                <a href="booking_requests.php" class="active">My Bookings</a>
                <a href="learning_materials.php">My Materials</a>
                <a href="assignments.php">My Assignments</a>
            </div>
            <div style="position:relative;">
                <button class="profile" onclick="toggleDropdown()">
                    <img src="<?= e($profilePic) ?>">
                    <span><?= e($displayName) ?></span>
                    <i class="bi bi-chevron-down"></i>
                </button>
                <div class="dropdown" id="profileDropdown">
                    <a href="tutor_profile.php"><i class="bi bi-person-circle"></i> My Profile</a>
                    <a href="earnings.php"><i class="bi bi-wallet2"></i> My Earnings</a>
                    <hr>
                    <a href="logout.php" style="color:#dc2626;"><i class="bi bi-box-arrow-right"></i> Logout</a>
                </div>
            </div>
        </nav>
    </div>
</header>

<div class="main">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; position: relative;">
        <!-- Back Button - Left -->
        <a href="tutor_dashboard.php" class="back-btn" style="display: inline-flex; align-items: center; gap: 8px; background: white; color: #1d3156; padding: 10px 20px; border-radius: 40px; text-decoration: none; font-weight: 600; font-size: 14px; border: 1px solid #e2e8f0; transition: 0.25s;">
            <i class="bi bi-arrow-left"></i> Back
        </a>
        
        <!-- Centered Title and Description -->
        <div style="position: absolute; left: 50%; transform: translateX(-50%); text-align: center;">
        <h1 id="pageTitle" style="font-size: 28px; font-weight: 800; color: #1d3156; margin: 0; letter-spacing: -0.5px;">
            <i class="bi bi-clock-history" style="color: #1d3156; margin-right: 8px;"></i> Pending Requests
        </h1>
        <p id="pageDescription" style="color: #1e293b; margin: 6px 0 0; font-size: 13px; font-weight: 500;">
            Auto reject at 12 AM on booking date if you not response.
        </p>
        </div>
        
        <!-- Empty spacer for balance -->
        <div style="width: 200px;"></div>
    </div><br>

    <?php if ($message): ?>
        <div class="alert alert-<?= e($messageType) ?>">
            <i class="bi bi-<?= $messageType === 'success' ? 'check-circle' : 'exclamation-circle' ?>"></i>
            <?= e($message) ?>
        </div>
    <?php endif; ?>

    <!-- Section Tabs -->
    <div class="section-tabs">
        <button class="tab-btn active" onclick="switchTab('pending', this)">
            Pending Classes
            <span style="background:#496894; color:white; padding:2px 8px; border-radius:20px; margin-left:8px; font-size:12px;"><?= count($pendingRequests) ?></span>
        </button>
        <button class="tab-btn" onclick="switchTab('reschedule', this)">
        Reschedule Requests
        <span style="background:#f59e0b; color:white; padding:2px 8px; border-radius:20px; margin-left:8px; font-size:12px;"><?= count($rescheduleRequests) ?></span>
    </button>
        <button class="tab-btn" onclick="switchTab('upcoming', this)">
            Upcoming Classes
            <span style="background:rgba(254, 214, 206, 0.92);; color:black; padding:2px 8px; border-radius:20px; margin-left:8px; font-size:12px;"><?= count($upcomingBookings) ?></span>
        </button>
    </div>
    <!-- FILTER SECTION - WHITE BACKGROUND, NO HEADER -->
<div style="background: white; border-radius: 16px; padding: 10px 14px; margin-bottom: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.08); border: 1px solid #eef2f7;">
    <div style="display: flex; justify-content: center; gap: 20px; flex-wrap: wrap; align-items: flex-end;">
        <div style="min-width: 150px;">
            <label style="display: block; font-size: 12px; font-weight: 600; margin-bottom: 6px; color: #1d3156;">
                <i class="bi bi-translate"></i> Language
            </label>
            <select id="languageFilter" style="width: 100%; padding: 10px 12px; border-radius: 10px; border: 1px solid #cbd5e1; background: white; font-family: 'Poppins', sans-serif;">
                <option value="all">All Languages</option>
                <?php foreach ($tutorLanguages as $lang): ?>
                    <option value="<?= e($lang['language']) ?>"><?= e($lang['language']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- Session Type Filter -->
        <div style="min-width: 180px;">
            <label style="display: block; font-size: 12px; font-weight: 600; margin-bottom: 6px; color: #1d3156;">
                <i class="bi bi-camera-video"></i> Session Mode
            </label>
            <select id="sessionTypeFilter" style="width: 100%; padding: 10px 12px; border-radius: 10px; border: 1px solid #cbd5e1; background: white; font-family: 'Poppins', sans-serif;">
                <option value="all">All Types</option>
                <option value="face_to_face">Face to Face</option>
                <option value="online">Online</option>
            </select>
        </div>

        <!-- From Date -->
        <div style="min-width: 160px;">
            <label style="display: block; font-size: 12px; font-weight: 600; margin-bottom: 6px; color: #1d3156;">
                 From            </label>
            <input type="date" id="dateFrom" style="width: 100%; padding: 10px 12px; border-radius: 10px; border: 1px solid #cbd5e1; font-family: 'Poppins', sans-serif;">
        </div>

        <!-- To Date -->
        <div style="min-width: 160px;">
            <label style="display: block; font-size: 12px; font-weight: 600; margin-bottom: 6px; color: #1d3156;">
             To
            </label>
            <input type="date" id="dateTo" style="width: 100%; padding: 10px 12px; border-radius: 10px; border: 1px solid #cbd5e1; font-family: 'Poppins', sans-serif;">
        </div>

        <!-- SEARCH BUTTON -->
        <div>
            <button onclick="applyFilters()" style="background: #1d3156; color: white; border: none; padding: 10px 28px; border-radius: 10px; cursor: pointer; font-weight: 600; font-family: 'Poppins', sans-serif; display: flex; align-items: center; gap: 8px;">
                <i class="bi bi-search"></i> Search
            </button>
        </div>

        <!-- RESET BUTTON -->
        <div>
            <button onclick="resetFilters()" style="background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0; padding: 10px 24px; border-radius: 10px; cursor: pointer; font-weight: 500; font-family: 'Poppins', sans-serif; display: flex; align-items: center; gap: 8px;">
                <i class="bi bi-arrow-counterclockwise"></i> Reset
            </button>
        </div>
    </div>
</div><br>
<!-- Pending Requests Tab - TABLE VIEW with Mode & Location (Day, Date, Time separated) -->
<div id="pending-tab" class="tab-content active">
    <?php if (empty($pendingRequests)): ?>
        <div class="empty-state">
            <i class="bi bi-inbox"></i>
            <p>No pending booking requests</p>
            <p style="font-size: 13px;">When students book sessions, they'll appear here.</p>
        </div>
    <?php else: ?>
        <table class="bookings-table">
            <thead>
                <tr>
                    <th>Student</th>
                    <th>Language</th>
                    <th>Mode & Location</th>
                    <th>Day</th>
                    <th>Date</th>
                    <th>Time</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $prevStudent = '';
                $prevLanguage = '';
                $prevMode = '';
                $prevDate = '';
                foreach ($pendingRequests as $request): 
                    $currentStudent = $request['student_name'];
                    $currentLanguage = $request['language'];
                    $currentMode = $request['learning_mode'];
                    $currentDate = $request['booking_date'];
                    
                    $showStudent = ($currentStudent != $prevStudent);
                    $showLanguage = ($currentLanguage != $prevLanguage || $showStudent);
                    $showMode = ($currentMode != $prevMode || $showStudent);
                    $showDate = ($currentDate != $prevDate || $showStudent);
                    
                    // Build mode & location display
                    $modeDisplay = '';
                    if ($request['learning_mode'] === 'online') {
                        $modeDisplay = '<span style="color:#80A1BA ;">Online</span>';
                    } else {
                        $location = !empty($request['meeting_location']) ? $request['meeting_location'] : 'Location to be confirmed';
                        $modeDisplay = '<span style="color:#B4DEBD;"> Face to Face</span><br><small style="font-size:11px; color:#64748b;">📍 ' . e($location) . '</small>';
                    }
                ?>
                    <tr>
                        <td data-label="Student" class="student-cell">
                            <?php if ($showStudent): ?>
                                <?= e($request['student_name']) ?>
                            <?php else: ?>
                                <span style="visibility: hidden;">—</span>
                            <?php endif; ?>
                        </td>
                        <td data-label="Language">
                            <?php if ($showLanguage): ?>
                                <?= e($request['language']) ?>
                            <?php else: ?>
                                <span style="visibility: hidden;">—</span>
                            <?php endif; ?>
                        </td>
                        <td data-label="Mode & Location">
                            <?php if ($showMode): ?>
                                <?= $modeDisplay ?>
                            <?php else: ?>
                                <span style="visibility: hidden;">—</span>
                            <?php endif; ?>
                        </td>
                        <td data-label="Day">
                            <?php if ($showDate): ?>
                                <?= date('l', strtotime($request['booking_date'])) ?>
                            <?php else: ?>
                                <span style="visibility: hidden;">—</span>
                            <?php endif; ?>
                        </td>
                        <td data-label="Date">
                            <?php if ($showDate): ?>
                                <?= date('d M Y', strtotime($request['booking_date'])) ?>
                            <?php else: ?>
                                <span style="visibility: hidden;">—</span>
                            <?php endif; ?>
                        </td>
                        <td data-label="Time" style="white-space: nowrap;">
                            <?= date('g:i A', strtotime($request['booking_time'])) ?>
                        </td>
                        <td data-label="Actions">
                            <div class="action-buttons">
                                <button class="btn-accept" onclick="acceptBooking(<?= $request['id'] ?>)">
                                    <i class="bi bi-check-lg"></i> Accept
                                </button>
                                <button class="btn-reject" onclick="showRejectModal(<?= $request['id'] ?>)">
                                    <i class="bi bi-x-lg"></i> Reject
                                </button>
                            </div>
                        </td>
                    </tr>
                <?php 
                    $prevStudent = $currentStudent;
                    $prevLanguage = $currentLanguage;
                    $prevMode = $currentMode;
                    $prevDate = $currentDate;
                endforeach; 
                ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
<!-- Reschedule Requests Tab -->
<div id="reschedule-tab" class="tab-content">
    <?php if (empty($rescheduleRequests)): ?>
        <div class="empty-state">
            <i class="bi bi-calendar-x"></i>
            <p>No pending reschedule requests</p>
            <p style="font-size: 13px;">When students request to reschedule, they'll appear here.</p>
        </div>
    <?php else: ?>
        <table class="bookings-table">
            <thead>
                <tr>
                    <th>Student</th>
                    <th>Language</th>
                    <th>Mode & Location</th>
                    <th>Original Date/Time</th>
                    <th>Requested Date/Time</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $prevStudent = '';
                $prevLanguage = '';
                $prevMode = '';
                $prevOriginal = '';
                foreach ($rescheduleRequests as $rr): 
                    $currentStudent = $rr['student_name'];
                    $currentLanguage = $rr['language'];
                    $currentMode = $rr['learning_mode'];
                    $currentOriginal = $rr['old_date'] . '|' . $rr['old_time'];
                    
                    $showStudent = ($currentStudent != $prevStudent);
                    $showLanguage = ($currentLanguage != $prevLanguage || $showStudent);
                    $showMode = ($currentMode != $prevMode || $showStudent);
                    $showOriginal = ($currentOriginal != $prevOriginal || $showStudent);
                    
                    // Build mode & location display
                    $modeDisplay = '';
                    if ($rr['learning_mode'] === 'online') {
                        $modeDisplay = '<span style="color:#80A1BA;">Online</span>';
                    } else {
                        $location = !empty($rr['meeting_location']) ? $rr['meeting_location'] : 'Location to be confirmed';
                        $modeDisplay = '<span style="color:#B4DEBD;">Face to Face</span><br><small style="font-size:11px; color:#64748b;">📍 ' . e($location) . '</small>';
                    }
                ?>
                    <tr>
                        <td data-label="Student" class="student-cell">
                            <?php if ($showStudent): ?>
                                <?= e($rr['student_name']) ?>
                            <?php else: ?>
                                <span style="display: inline-block; width: 1px;">&nbsp;</span>
                            <?php endif; ?>
                        </td>
                        <td data-label="Language">
                            <?php if ($showLanguage): ?>
                                <?= e($rr['language']) ?>
                            <?php else: ?>
                                <span style="display: inline-block; width: 1px;">&nbsp;</span>
                            <?php endif; ?>
                        </td>
                        <td data-label="Mode & Location">
                            <?php if ($showMode): ?>
                                <?= $modeDisplay ?>
                            <?php else: ?>
                                <span style="display: inline-block; width: 1px;">&nbsp;</span>
                            <?php endif; ?>
                        </td>
                        <td data-label="Original">
                            <?php if ($showOriginal): ?>
                                <?= date('d M Y', strtotime($rr['old_date'])) ?> @ <?= date('g:i A', strtotime($rr['old_time'])) ?>
                            <?php else: ?>
                                <span style="display: inline-block; width: 1px;">&nbsp;</span>
                            <?php endif; ?>
                        </td>
                        <td data-label="Requested">
                            <?= date('d M Y', strtotime($rr['new_date'])) ?> @ <?= date('g:i A', strtotime($rr['new_time'])) ?>
                        </td>
                        <td data-label="Actions">
                            <div class="action-buttons">
                                <button class="btn-accept" onclick="processReschedule(<?= $rr['id'] ?>, <?= $rr['booking_id'] ?>, 'accept', '<?= $rr['new_date'] ?>', '<?= $rr['new_time'] ?>')">
                                    <i class="bi bi-check-lg"></i> Accept
                                </button>
                                <button class="btn-reject" onclick="showRejectRescheduleModal(<?= $rr['id'] ?>, <?= $rr['booking_id'] ?>)">
                                    <i class="bi bi-x-lg"></i> Reject
                                </button>
                            </div>
                        </td>
                    </tr>
                <?php 
                    $prevStudent = $currentStudent;
                    $prevLanguage = $currentLanguage;
                    $prevMode = $currentMode;
                    $prevOriginal = $currentOriginal;
                endforeach; 
                ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
 <!-- Upcoming Sessions Tab -->
<div id="upcoming-tab" class="tab-content">
    <?php if (empty($upcomingBookings)): ?>
        <div class="empty-state">
            <i class="bi bi-calendar-check"></i>
            <p>No upcoming confirmed sessions</p>
            <p style="font-size: 13px;">Accepted bookings will appear here.</p>
        </div>
    <?php else: ?>
        <table class="bookings-table">
            <thead>
                <tr>
                    <th>Student</th>
                    <th>Language</th>
                    <th>Mode & Location</th>
                    <th>Day</th>
                    <th>Date</th>
                    <th>Time</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $prevStudent = '';
                $prevLanguage = '';
                $prevMode = '';
                $prevDate = '';
                foreach ($upcomingBookings as $booking): 
                    $currentStudent = $booking['student_name'];
                    $currentLanguage = $booking['language'];
                    $currentMode = $booking['learning_mode'];
                    $currentDate = $booking['booking_date'];
                    
                    $showStudent = ($currentStudent != $prevStudent);
                    $showLanguage = ($currentLanguage != $prevLanguage || $showStudent);
                    $showMode = ($currentMode != $prevMode || $showStudent);
                    $showDate = ($currentDate != $prevDate || $showStudent);
                    
                    // Build mode & location display
                    $modeDisplayUpcoming = '';
                    if ($booking['learning_mode'] === 'online') {
                        $modeDisplayUpcoming = '<span style="color:#80A1BA;">Online</span>';
                    } else {
                        $location = !empty($booking['meeting_location']) ? $booking['meeting_location'] : 'Location to be confirmed';
                        $modeDisplayUpcoming = '<span style="color:#B4DEBD;">Face to Face</span><br><small style="font-size:11px; color:#64748b;">📍 ' . e($location) . '</small>';
                    }
                ?>
                    <tr>
                        <td data-label="Student" class="student-cell">
                            <?php if ($showStudent): ?>
                                <?= e($booking['student_name']) ?>
                            <?php else: ?>
                                <span style="display: inline-block; width: 1px;">&nbsp;</span>
                            <?php endif; ?>
                        </td>
                        <td data-label="Language">
                            <?php if ($showLanguage): ?>
                                <?= e($booking['language']) ?>
                            <?php else: ?>
                                <span style="display: inline-block; width: 1px;">&nbsp;</span>
                            <?php endif; ?>
                        </td>
                        <td data-label="Mode & Location">
                            <?php if ($showMode): ?>
                                <?= $modeDisplayUpcoming ?>
                            <?php else: ?>
                                <span style="display: inline-block; width: 1px;">&nbsp;</span>
                            <?php endif; ?>
                        </td>
                        <td data-label="Day">
                            <?php if ($showDate): ?>
                                <?= date('l', strtotime($booking['booking_date'])) ?>
                            <?php else: ?>
                                <span style="display: inline-block; width: 1px;">&nbsp;</span>
                            <?php endif; ?>
                        </td>
                        <td data-label="Date">
                            <?php if ($showDate): ?>
                                <?= date('d M Y', strtotime($booking['booking_date'])) ?>
                            <?php else: ?>
                                <span style="display: inline-block; width: 1px;">&nbsp;</span>
                            <?php endif; ?>
                        </td>
                        <td data-label="Time" style="white-space: nowrap;">
                            <?= date('g:i A', strtotime($booking['booking_time'])) ?>
                        </td>
                        <td data-label="Actions">
                            <div class="action-buttons" style="display: flex; gap: 8px; flex-wrap: wrap;">
                                
                                <?php if ($booking['learning_mode'] === 'online'): ?>
                                    <?php if (!empty($booking['meeting_link'])): ?>
                                        <a href="<?= e($booking['meeting_link']) ?>" target="_blank" class="btn-attend" style="background: #28a745; color: white; padding: 6px 14px; border-radius: 20px; text-decoration: none; font-size: 12px; font-weight: 600;">
                                            <i class="bi bi-camera-video-fill"></i> Attend Class
                                        </a>
                                    <?php else: ?>
                                        <button class="btn-add-link" onclick="showAddLinkModal(<?= $booking['id'] ?>)" style="background: #f59e0b; color: white; padding: 6px 14px; border-radius: 20px; border: none; font-size: 12px; font-weight: 600; cursor: pointer;">
                                            <i class="bi bi-link"></i> Add Link
                                        </button>
                                    <?php endif; ?>
                                <?php endif; ?>
                                
                                <a href="tutor_booking_detail.php?id=<?= $booking['id'] ?>" class="btn-view" style="background: #e2e8f0; color: #1d3156; padding: 6px 12px; border-radius: 20px; text-decoration: none; font-size: 12px;">
                                    <i class="bi bi-eye"></i> Details
                                </a>
                            </div>
                        </td>
                    </tr>
                <?php 
                    $prevStudent = $currentStudent;
                    $prevLanguage = $currentLanguage;
                    $prevMode = $currentMode;
                    $prevDate = $currentDate;
                endforeach; 
                ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<!-- Reject Modal -->
<div id="rejectModal" class="modal">
    <div class="modal-content">
        <h3><i class="bi bi-exclamation-triangle" style="color: #dc2626;"></i> Reject Booking</h3>
        <p style="margin-bottom: 16px;">Please provide a reason for rejecting this booking:</p>
        <form method="POST" action="" id="rejectForm">
            <input type="hidden" name="booking_id" id="reject_booking_id">
            <textarea name="reject_reason" placeholder="e.g., I'm not available at this time..." required></textarea>
            <div class="modal-buttons">
                <button type="button" class="btn-view" onclick="closeRejectModal()">Cancel</button>
                <button type="submit" name="reject_booking" class="btn-reject">Submit Rejection</button>
            </div>
        </form>
    </div>
</div>

<!-- Meeting Link Modal -->
<div id="meetingLinkModal" class="modal">
    <div class="modal-content" style="max-width: 500px;">
        <h3><i class="bi bi-link"></i> Add Meeting Link</h3>
        <p style="margin-bottom: 16px;">Enter the url link for this session</p>
        <input type="hidden" id="link_booking_id">
        <input type="url" id="meeting_link_input" placeholder="https://meet.google.com/123456789" style="width: 100%; padding: 12px; border: 1px solid #cbd5e1; border-radius: 12px; margin-bottom: 16px; font-family: 'Poppins', sans-serif;">
        <div class="modal-buttons">
            <button type="button" class="btn-view" onclick="closeLinkModal()">Cancel</button>
            <button type="button" class="btn-accept" onclick="saveMeetingLink()">Save Link</button>
        </div>
    </div>
</div>

<!-- Reject Reschedule Modal -->
<div id="rejectRescheduleModal" class="modal">
    <div class="modal-content" style="max-width: 500px;">
        <h3><i class="bi bi-exclamation-triangle" style="color: #dc2626;"></i> Reject Reschedule Request</h3>
        <p style="margin-bottom: 16px;">Please provide a reason for rejecting this reschedule request:</p>
        <input type="hidden" id="reject_request_id">
        <input type="hidden" id="reject_booking_id">
        <textarea id="reject_reason_input" placeholder="e.g., Time slot not available, Schedule conflict..." style="width: 100%; padding: 12px; border: 1px solid #cbd5e1; border-radius: 12px; min-height: 100px; font-family: 'Poppins', sans-serif;" required></textarea>
        <div class="modal-buttons" style="margin-top: 16px;">
            <button type="button" class="btn-view" onclick="closeRejectRescheduleModal()">Cancel</button>
            <button type="button" class="btn-reject" onclick="submitRejectReschedule()">Submit Rejection</button>
        </div>
    </div>
</div>

<script>
function toggleDropdown() {
    const dropdown = document.getElementById('profileDropdown');
    dropdown.style.display = dropdown.style.display === 'block' ? 'none' : 'block';
}


function updateHeaderTitle(tab) {
    const titleEl = document.getElementById('pageTitle');
    const descEl = document.getElementById('pageDescription');
    if (tab === 'pending') {
        titleEl.innerHTML = '<i class="bi bi-clock-history" style="color: #1d3156; margin-right: 8px;"></i> Pending Requests';
        descEl.innerHTML = 'Auto-reject at 12 AM on booking date if no response.';
    } else {
        titleEl.innerHTML = '<i class="bi bi-calendar-check" style="color: #1d3156; margin-right: 8px;"></i> Upcoming Sessions';
        descEl.innerHTML = 'View your confirmed and upcoming teaching sessions';
    }
}

window.addEventListener('click', function(e) {
    const dropdown = document.getElementById('profileDropdown');
    const button = document.querySelector('.profile');
    if (button && !button.contains(e.target) && dropdown && !dropdown.contains(e.target)) {
        dropdown.style.display = 'none';
    }
});
function switchTab(tab, btnElement) {
    document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
    btnElement.classList.add('active');
    
    document.getElementById('pending-tab').classList.remove('active');
    document.getElementById('upcoming-tab').classList.remove('active');
    document.getElementById('reschedule-tab').classList.remove('active');
    
    const titleEl = document.getElementById('pageTitle');
    const descEl = document.getElementById('pageDescription');
    
    if (tab === 'pending') {
        document.getElementById('pending-tab').classList.add('active');
        titleEl.innerHTML = '<i class="bi bi-clock-history"></i> Pending Requests';
        descEl.innerHTML = 'Auto reject at 12 AM on booking date if you not response.';
    } else if (tab === 'reschedule') {
        document.getElementById('reschedule-tab').classList.add('active');
        titleEl.innerHTML = '<i class="bi bi-calendar-plus"></i> Reschedule Requests';
        descEl.innerHTML = 'Auto reject at 12 AM on booking date if you not response.';
    } else {
        document.getElementById('upcoming-tab').classList.add('active');
        titleEl.innerHTML = '<i class="bi bi-calendar-check"></i> Upcoming Sessions';
        descEl.innerHTML = 'View your confirmed and upcoming teaching sessions';
    }
}

function acceptBooking(bookingId) {
    if (confirm('Accept this booking? The student will be notified.')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = '';
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'booking_id';
        input.value = bookingId;
        form.appendChild(input);
        
        const acceptInput = document.createElement('input');
        acceptInput.type = 'hidden';
        acceptInput.name = 'accept_booking';
        acceptInput.value = '1';
        form.appendChild(acceptInput);
        
        document.body.appendChild(form);
        form.submit();
    }
}

function showRejectModal(bookingId) {
    document.getElementById('reject_booking_id').value = bookingId;
    document.getElementById('rejectModal').classList.add('active');
}

function closeRejectModal() {
    document.getElementById('rejectModal').classList.remove('active');
}

window.onclick = function(event) {
    const modal = document.getElementById('rejectModal');
    if (event.target === modal) {
        closeRejectModal();
    }
}

setTimeout(function() {
    const alerts = document.querySelectorAll('.alert');
    alerts.forEach(alert => {
        alert.style.transition = 'opacity 0.5s';
        alert.style.opacity = '0';
        setTimeout(() => alert.remove(), 500);
    });
}, 4000);

// ============================================
// PENDING REQUESTS FILTER SYSTEM (WORKING VERSION)
// ============================================

// Store all pending requests as JavaScript array
const allPendingRequests = <?= json_encode($pendingRequests) ?>;

// Helper function to format time
function formatTime(timeString) {
    if (!timeString) return '--:--';
    const date = new Date(`2000-01-01T${timeString}`);
    return date.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true });
}

// Helper function to escape HTML
function escapeHtml(str) {
    if (!str) return '';
    return str.replace(/[&<>]/g, function(m) {
        if (m === '&') return '&amp;';
        if (m === '<') return '&lt;';
        if (m === '>') return '&gt;';
        return m;
    });
}
// Reset all filters - clear and show all
function resetFilters() {
    console.log('Reset filters called');
    document.getElementById('languageFilter').value = 'all';
    document.getElementById('sessionTypeFilter').value = 'all';
    document.getElementById('dateFrom').value = '';
    document.getElementById('dateTo').value = '';
    // Show all requests
    renderFilteredTable(allPendingRequests);
    
    // Show toast message
    showToast(`Filters cleared. Showing all result.`, '#64748b');
}

// Meeting Link Functions
function showAddLinkModal(bookingId) {
    document.getElementById('link_booking_id').value = bookingId;
    document.getElementById('meeting_link_input').value = '';
    document.getElementById('meetingLinkModal').classList.add('active');
}

function closeLinkModal() {
    document.getElementById('meetingLinkModal').classList.remove('active');
}

function saveMeetingLink() {
    const bookingId = document.getElementById('link_booking_id').value;
    const meetingLink = document.getElementById('meeting_link_input').value;
    
    if (!meetingLink) {
        showToast('Please enter a meeting link', '#f59e0b');
        return;
    }
    
    fetch('save_meeting_link.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ booking_id: bookingId, meeting_link: meetingLink })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showToast('Meeting link saved!', '#28a745');
            closeLinkModal();
            setTimeout(() => location.reload(), 1000);
        } else {
            showToast('Error saving link', '#dc2626');
        }
    })
    .catch(error => {
        showToast('Error: ' + error, '#dc2626');
    });
}

function applyFilters() {
    console.log('Apply filters called');
    const language = document.getElementById('languageFilter').value;
    const sessionType = document.getElementById('sessionTypeFilter').value;
    const dateFrom = document.getElementById('dateFrom').value;
    const dateTo = document.getElementById('dateTo').value;
    
    console.log('Language:', language);
    console.log('Session Type:', sessionType);
    console.log('Date From:', dateFrom);
    console.log('Date To:', dateTo);
    
    // Check if any filter is actually selected
    const hasActiveFilters = (language !== 'all' || sessionType !== 'all' || dateFrom || dateTo);
    
    // If no filters selected, show message and do nothing
    if (!hasActiveFilters) {
        showToast(' Please select at least one filter (Language, Session Mode, or Date Range) before searching.', '#f59e0b');
        return;  // Exit the function without filtering
    }
    
    const filtered = allPendingRequests.filter(request => {
        // Filter by language
        if (language !== 'all' && request.language !== language) {
            return false;
        }
        
        // Filter by session type (learning_mode)
        if (sessionType !== 'all' && request.learning_mode !== sessionType) {
            return false;
        }
        
        // Filter by date range
        if (dateFrom && request.booking_date < dateFrom) {
            return false;
        }
        if (dateTo && request.booking_date > dateTo) {
            return false;
        }
        
        return true;
    });
    
    console.log('Filtered count:', filtered.length);
    renderFilteredTable(filtered);
    
    // Show result toast
    if (filtered.length === 0) {
        showToast(' No requests match your filters.', '#dc2626');
    } else if (filtered.length !== allPendingRequests.length) {
        showToast(` Found ${filtered.length} request${filtered.length !== 1 ? 's' : ''} matching your filters.`, '#0369a1');
    } else {
        showToast(` ${filtered.length} requests match your filters.`, '#28a745');
    }
}

// Show toast notification
function showToast(message, color) {
    // Remove existing toast
    const existingToast = document.querySelector('.filter-toast');
    if (existingToast) existingToast.remove();
    
    const toast = document.createElement('div');
    toast.className = 'filter-toast';
    toast.style.cssText = `position: fixed; bottom: 20px; right: 20px; background: ${color}; color: white; padding: 12px 24px; border-radius: 12px; font-size: 13px; font-weight: 500; z-index: 9999; box-shadow: 0 4px 12px rgba(0,0,0,0.15); animation: slideIn 0.3s ease;`;
    toast.innerHTML = `<i class="bi bi-bell"></i> ${message}`;
    document.body.appendChild(toast);
    
    setTimeout(() => {
        toast.style.opacity = '0';
        setTimeout(() => toast.remove(), 300);
    }, 3000);
}

// Render the filtered table
function renderFilteredTable(requests) {
    const pendingTab = document.getElementById('pending-tab');
    if (!pendingTab) {
        console.error('pending-tab not found');
        return;
    }
    
    // Check if table exists, if not, create it
    let table = pendingTab.querySelector('.bookings-table');
    let tbody;
    
    if (!table) {
        // Remove empty state if exists
        const emptyState = pendingTab.querySelector('.empty-state');
        if (emptyState) emptyState.remove();
        
        // Create new table
        table = document.createElement('table');
        table.className = 'bookings-table';
        table.innerHTML = `
            <thead>
                <tr>
                    <th>Student</th>
                    <th>Language</th>
                    <th>Mode & Location</th>
                    <th>Day</th>
                    <th>Date</th>
                    <th>Time</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody></tbody>
        `;
        pendingTab.appendChild(table);
        tbody = table.querySelector('tbody');
    } else {
        tbody = table.querySelector('tbody');
    }
    
    if (!tbody) {
        console.error('tbody not found');
        return;
    }
    
    if (requests.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="7" style="text-align: center; padding: 60px; color: #94a3b8;">
                    <i class="bi bi-inbox" style="font-size: 48px; display: block; margin-bottom: 16px;"></i>
                    No pending requests match your filters
                    <br>
                    <button onclick="resetFilters()" style="margin-top: 12px; background: #1d3156; color: white; border: none; padding: 8px 20px; border-radius: 20px; cursor: pointer;">
                        <i class="bi bi-arrow-counterclockwise"></i> Reset Filters
                    </button>
                </td>
            </tr>
        `;
        return;
    }
    
    let prevStudent = '';
    let prevLanguage = '';
    let prevMode = '';
    let prevDate = '';
    let html = '';
    
    for (const request of requests) {
        const currentStudent = request.student_name;
        const currentLanguage = request.language;
        const currentMode = request.learning_mode;
        const currentDate = request.booking_date;
        
        const showStudent = (currentStudent !== prevStudent);
        const showLanguage = (currentLanguage !== prevLanguage || showStudent);
        const showMode = (currentMode !== prevMode || showStudent);
        const showDate = (currentDate !== prevDate || showStudent);
        
        // Build mode & location display
        let modeDisplay = '';
        if (request.learning_mode === 'online') {
            modeDisplay = '<span style="color:#80A1BA;">Online</span>';
        } else {
            const location = (request.meeting_location && request.meeting_location !== '') 
                ? request.meeting_location 
                : 'Location to be confirmed';
            modeDisplay = '<span style="color:#B4DEBD;">Face to Face</span><br><small style="font-size:11px; color:#64748b;">📍 ' + escapeHtml(location) + '</small>';
        }
        
        html += `
            <tr>
                <td data-label="Student" class="student-cell">
                    ${showStudent ? escapeHtml(request.student_name) : '<span style="visibility: hidden;">—</span>'}
                 </td>
                <td data-label="Language">
                    ${showLanguage ? escapeHtml(request.language) : '<span style="visibility: hidden;">—</span>'}
                 </td>
                <td data-label="Mode & Location">
                    ${showMode ? modeDisplay : '<span style="visibility: hidden;">—</span>'}
                 </td>
                <td data-label="Day">
                    ${showDate ? new Date(request.booking_date).toLocaleDateString('en-US', { weekday: 'long' }) : '<span style="visibility: hidden;">—</span>'}
                 </td>
                <td data-label="Date">
                    ${showDate ? new Date(request.booking_date).toLocaleDateString('en-US', { day: '2-digit', month: 'short', year: 'numeric' }) : '<span style="visibility: hidden;">—</span>'}
                 </td>
                <td data-label="Time" style="white-space: nowrap;">
                    ${formatTime(request.booking_time)}
                 </td>
                <td data-label="Actions">
                    <div class="action-buttons">
                        <button class="btn-accept" onclick="acceptBooking(${request.id})">
                            <i class="bi bi-check-lg"></i> Accept
                        </button>
                        <button class="btn-reject" onclick="showRejectModal(${request.id})">
                            <i class="bi bi-x-lg"></i> Reject
                        </button>
                    </div>
                 </td>
             </tr>
        `;
        
        prevStudent = currentStudent;
        prevLanguage = currentLanguage;
        prevMode = currentMode;
        prevDate = currentDate;
    }
    
    tbody.innerHTML = html;
}

// On page load - show ALL pending requests (no filter applied)
document.addEventListener('DOMContentLoaded', function() {
    console.log('DOM loaded, rendering all requests...');
    console.log('Total pending requests:', allPendingRequests.length);
    renderFilteredTable(allPendingRequests);
});

// Add animation style for toast
const style = document.createElement('style');
style.textContent = `
    @keyframes slideIn {
        from { transform: translateX(100%); opacity: 0; }
        to { transform: translateX(0); opacity: 1; }
    }
`;
document.head.appendChild(style);

function checkExpiringRequests() {
    const rows = document.querySelectorAll('#pending-tab .bookings-table tbody tr');
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    
    rows.forEach(row => {
        const dateCell = row.querySelector('td[data-label="Date"]');
        if (dateCell) {
            const dateText = dateCell.innerText;
            const bookingDate = new Date(dateText);
            bookingDate.setHours(0, 0, 0, 0);
            
            const daysUntil = Math.ceil((bookingDate - today) / (1000 * 60 * 60 * 24));
            
            if (daysUntil === 0) {
                // Today - expires at midnight
                row.style.background = '#fef2f2';
                const warningSpan = document.createElement('span');
                warningSpan.style.cssText = 'display: inline-flex; align-items: center; gap: 4px; margin-left: 10px; background: #dc2626; color: white; padding: 2px 8px; border-radius: 20px; font-size: 10px;';
                warningSpan.innerHTML = '<i class="bi bi-exclamation-triangle-fill"></i> Expires tonight!';
                const actionsCell = row.querySelector('td:last-child');
                if (actionsCell && !row.querySelector('.expiry-warning')) {
                    const existing = row.querySelector('.expiry-warning');
                    if (!existing) {
                        warningSpan.className = 'expiry-warning';
                        actionsCell.appendChild(warningSpan);
                    }
                }
            } else if (daysUntil === 1) {
                // Tomorrow
                row.style.background = '#fffbeb';
            }
        }
    });
}

// Reject Reschedule Modal Functions
let currentRejectRequestId = null;
let currentRejectBookingId = null;

function showRejectRescheduleModal(requestId, bookingId) {
    currentRejectRequestId = requestId;
    currentRejectBookingId = bookingId;
    document.getElementById('reject_reason_input').value = '';
    document.getElementById('rejectRescheduleModal').classList.add('active');
}

function closeRejectRescheduleModal() {
    document.getElementById('rejectRescheduleModal').classList.remove('active');
    currentRejectRequestId = null;
    currentRejectBookingId = null;
}

function submitRejectReschedule() {
    const rejectReason = document.getElementById('reject_reason_input').value.trim();
    
    if (!rejectReason) {
        showToast('Please provide a reason for rejection', '#f59e0b');
        return;
    }
    
    fetch('process_reschedule.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ 
            request_id: currentRejectRequestId, 
            booking_id: currentRejectBookingId, 
            action: 'reject',
            reject_reason: rejectReason
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showToast('Reschedule rejected.', '#64748b');
            closeRejectRescheduleModal();
            setTimeout(() => location.reload(), 1500);
        } else {
            showToast('Error: ' + data.message, '#dc2626');
        }
    });
}
// Process reschedule request (Accept/Reject)
function processReschedule(requestId, bookingId, action, newDate = null, newTime = null) {
    if (action === 'accept') {
        if (confirm('Accept this reschedule request? The booking will be updated to the new date/time.')) {
            fetch('process_reschedule.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ 
                    request_id: requestId, 
                    booking_id: bookingId, 
                    action: 'accept',
                    new_date: newDate,
                    new_time: newTime
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast('Reschedule accepted! Booking updated.', '#28a745');
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showToast('Error: ' + data.message, '#dc2626');
                }
            });
        }
    } else {
        if (confirm('Reject this reschedule request? The original booking will remain.')) {
            fetch('process_reschedule.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ 
                    request_id: requestId, 
                    booking_id: bookingId, 
                    action: 'reject'
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast('Reschedule rejected.', '#64748b');
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showToast('Error: ' + data.message, '#dc2626');
                }
            });
        }
    }
}
// Call after table loads
const originalRenderFilteredTable = renderFilteredTable;
window.renderFilteredTable = function(requests) {
    originalRenderFilteredTable(requests);
    setTimeout(checkExpiringRequests, 100);
};
</script>

</body>
</html>