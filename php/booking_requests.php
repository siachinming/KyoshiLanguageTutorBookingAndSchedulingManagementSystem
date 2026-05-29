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
        $notifTitle = "Booking Accepted!";
        $notifMessage = "Your booking for {$booking['language']} on " . date('d M Y', strtotime($booking['booking_date'])) . " at " . date('g:i A', strtotime($booking['booking_time'])) . " has been accepted by tutor {$displayName}. Please proceed to payment.";

       $link = 'payment_form.php?booking_id=' . $booking_id;
$notif = $conn->prepare("INSERT INTO notifications (user_id, title, message, type, link, is_read, created_at) VALUES (?, ?, ?, 'booking', ?, 0, NOW())");
$notif->bind_param("isss", $booking['student_id'], $notifTitle, $notifMessage, $link);
        $notif->execute();
            
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
            // Send notification to student about booking rejection
            $notifTitle = "Booking Declined";
            $notifMessage = "Your booking for {$booking['language']} on " . date('d M Y', strtotime($booking['booking_date'])) . " at " . date('g:i A', strtotime($booking['booking_time'])) . " has been declined by tutor {$displayName}. Reason: {$reject_reason}";
            
            $notif = $conn->prepare("INSERT INTO notifications (user_id, title, message, type, is_read, created_at) VALUES (?, ?, ?, 'booking', 0, NOW())");
            $notif->bind_param("iss", $booking['student_id'], $notifTitle, $notifMessage);
            $notif->execute();
            
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

// Completed/Cancelled bookings
$stmt = $conn->prepare("
    SELECT b.*, b.meeting_location, b.learning_mode, 
           u.fullname as student_name, u.email as student_email, u.id as student_id,
           p.status as payment_status
    FROM bookings b
    JOIN users u ON b.student_id = u.id
    LEFT JOIN payments p ON b.id = p.booking_id
    WHERE b.tutor_id = ? 
    AND (b.status = 'completed' OR b.status = 'cancelled')
    ORDER BY b.booking_date DESC, b.booking_time DESC
    LIMIT 50
");
$stmt->bind_param("i", $userID);
$stmt->execute();
$completedBookings = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Fetch disputed bookings (where student reported an issue that is pending)
$stmt = $conn->prepare("
    SELECT 
        d.id as dispute_id,
        d.issue_type,
        d.message,
        d.created_at as dispute_date,
        d.status as dispute_status,
        d.resolution_type,
        b.id as booking_id,
        b.language,
        b.booking_date,
        b.booking_time,
        b.learning_mode,
        b.total_amount,
        u.fullname as student_name,
        u.email as student_email,
        u.id as student_id
    FROM disputes d
    JOIN bookings b ON d.booking_id = b.id
    JOIN users u ON b.student_id = u.id
    WHERE b.tutor_id = ? AND d.status = 'pending'
    ORDER BY d.created_at DESC
");
$stmt->bind_param("i", $userID);
$stmt->execute();
$disputes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
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
                <a href="material_overview.php">My Materials</a>
                <a href="assignment_overview.php">My Assignments</a>
                <a href="view_session_reports.php">My Reports</a>
            </div>
            <div style="position:relative;">
                <button class="profile" onclick="toggleDropdown()">
                    <img src="<?= e($profilePic) ?>">
                    <span><?= e($displayName) ?></span>
                    <i class="bi bi-chevron-down"></i>
                </button>
                <div class="dropdown" id="profileDropdown">
                    <a href="teacher_profile.php"><i class="bi bi-person-circle"></i> My Profile</a>
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
        <?php
// Fetch dispute urgency statistics
$urgentCount = 0;
$totalPending = 0;

$urgentStmt = $conn->prepare("
    SELECT COUNT(*) as count 
    FROM disputes d
    JOIN bookings b ON d.booking_id = b.id
    WHERE b.tutor_id = ? 
    AND d.status = 'pending' 
    AND d.resolution_type = 'student_tutor'
    AND d.created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)
");
$urgentStmt->bind_param("i", $userID);
$urgentStmt->execute();
$urgentCount = $urgentStmt->get_result()->fetch_assoc()['count'];

$totalStmt = $conn->prepare("
    SELECT COUNT(*) as count 
    FROM disputes d
    JOIN bookings b ON d.booking_id = b.id
    WHERE b.tutor_id = ? 
    AND d.status = 'pending' 
    AND d.resolution_type = 'student_tutor'
");
$totalStmt->bind_param("i", $userID);
$totalStmt->execute();
$totalPending = $totalStmt->get_result()->fetch_assoc()['count'];
?>

<?php if ($totalPending > 0): ?>
<div class="alert alert-warning" style="background: #fff3cd; border-left: 4px solid #f59e0b; margin-bottom: 20px; padding: 15px 20px; border-radius: 12px;">
    <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 15px;">
        <div>
            <i class="bi bi-exclamation-triangle-fill" style="color: #f59e0b;"></i>
            <strong style="color: #856404;">Disputes Needing Resolution</strong>
            <p style="margin: 5px 0 0; color: #856404; font-size: 13px;">
                You have <strong><?= $totalPending ?></strong> pending dispute<?= $totalPending > 1 ? 's' : '' ?>.
                <?php if ($urgentCount > 0): ?>
                    <span style="color: #dc2626;"><strong><?= $urgentCount ?></strong> require<?= $urgentCount > 1 ? '' : 's' ?> immediate attention (over 24 hours).</span>
                <?php endif; ?>
            </p>
        </div>
        <a href="#disputes-tab" onclick="switchTab('disputes', this)" class="btn-pink" style="background: #f59e0b; color: white; padding: 8px 20px; border-radius: 30px; text-decoration: none; font-weight: 600; font-size: 13px;">
            <i class="bi bi-eye"></i> Resolve Now
        </a>
    </div>
</div>
<?php endif; ?>
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
    <button class="tab-btn" onclick="switchTab('disputes', this)">
        Disputes
        <span style="background:#dc3545; color:white; padding:2px 8px; border-radius:20px; margin-left:8px; font-size:12px;"><?= count($disputes) ?></span>
    </button>
        <button class="tab-btn" onclick="switchTab('upcoming', this)">
            Upcoming Classes
            <span style="background:rgba(254, 214, 206, 0.92);; color:black; padding:2px 8px; border-radius:20px; margin-left:8px; font-size:12px;"><?= count($upcomingBookings) ?></span>
        </button>
        <button class="tab-btn" onclick="switchTab('completed', this)">
    Completed Sessions
    <span style="background:#28a745; color:white; padding:2px 8px; border-radius:20px; margin-left:8px; font-size:12px;"><?= count($completedBookings) ?></span>
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
                                <td data-label="Actions">
                                    <div class="action-buttons">
                                        <button class="btn-accept" onclick="processReschedule(<?= $rr['id'] ?>, <?= $rr['booking_id'] ?>, 'accept', '<?= $rr['new_date'] ?>', '<?= $rr['new_time'] ?>', event)"><i class="bi bi-check-lg"></i> Accept</button>
                                        <button class="btn-reject" onclick="showRejectRescheduleModal(<?= $rr['id'] ?>, <?= $rr['booking_id'] ?>)"><i class="bi bi-x-lg"></i> Reject</button>
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
<!-- Disputes Tab -->
<div id="disputes-tab" class="tab-content">
    <?php if (empty($disputes)): ?>
        <div class="empty-state">
            <i class="bi bi-chat-dots"></i>
            <p>No pending disputes</p>
            <p style="font-size: 13px;">When students report issues, they'll appear here.</p>
        </div>
    <?php else: ?>
        <table class="bookings-table">
            <thead>
                <tr>
                    <th>Student</th>
                    <th>Language</th>
                    <th>Issue Type</th>
                    <th>Message</th>
                    <th>Reported On</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($disputes as $dispute): 
                    $issue_labels = [
                        'tutor_no_show' => 'Tutor Did Not Attend',
                        'student_no_show' => 'Student Did Not Attend',
                        'technical_issues' => 'Technical Issues',
                        'wrong_materials' => 'Wrong Materials Provided',
                        'payment_failed_but_deducted' => 'Payment Issue',
                        'other' => 'Other Issue'
                    ];
                    $issueLabel = $issue_labels[$dispute['issue_type']] ?? ucfirst(str_replace('_', ' ', $dispute['issue_type']));
                ?>
                    <tr>
                        <td data-label="Student" class="student-cell">
                            <?= e($dispute['student_name']) ?>
                        </td>
                        <td data-label="Language">
                            <?= e($dispute['language']) ?>
                        </td>
                        <td data-label="Issue Type">
                            <span style="background:#fee2e2; color:#dc2626; padding:4px 10px; border-radius:20px; font-size:11px; display:inline-block;">
                                <?= e($issueLabel) ?>
                            </span>
                        </td>
                        <td data-label="Message" style="max-width: 250px;">
                            <?php if (!empty($dispute['message'])): ?>
                                <?= e(substr($dispute['message'], 0, 100)) ?><?= strlen($dispute['message']) > 100 ? '...' : '' ?>
                            <?php else: ?>
                                <span style="color:#999;">No message provided</span>
                            <?php endif; ?>
                        </td>
                        <td data-label="Reported On" style="white-space: nowrap;">
    <?= date('d M Y', strtotime($dispute['dispute_date'])) ?>
    <?php 
    $hoursOld = (time() - strtotime($dispute['dispute_date'])) / 3600;
    if ($hoursOld > 24): 
    ?>
        <span style="background: #dc2626; color: white; padding: 2px 8px; border-radius: 12px; font-size: 10px; margin-left: 8px;">
            URGENT
        </span>
    <?php elseif ($hoursOld > 12): ?>
        <span style="background: #f59e0b; color: white; padding: 2px 8px; border-radius: 12px; font-size: 10px; margin-left: 8px;">
            Due Soon
        </span>
    <?php endif; ?>
</td>
                        <td data-label="Actions">
    <div class="action-buttons">
        <a href="tutor_booking_detail.php?id=<?= $dispute['booking_id'] ?>" class="btn-view">
            <i class="bi bi-eye"></i> View Details
        </a>
    </div>
</td>
                    </tr>
                <?php endforeach; ?>
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
                            <?= $modeDisplayUpcoming ?> 
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
                                        <a href="join_meeting.php?booking_id=<?= $booking['id'] ?>&link=<?= urlencode($booking['meeting_link']) ?>" 
                                           target="_blank" class="btn-attend" 
                                           style="background: #28a745; color: white; padding: 6px 14px; border-radius: 20px; text-decoration: none; font-size: 12px; font-weight: 600; display: inline-flex; align-items: center; gap: 5px;">
                                            <i class="bi bi-camera-video-fill"></i> Attend Class
                                        </a>
                                    <?php else: ?>
                                        <button class="btn-add-link" onclick="showAddLinkModal(<?= $booking['id'] ?>)" 
                                                style="background: #f59e0b; color: white; padding: 6px 14px; border-radius: 20px; border: none; font-size: 12px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 5px;">
                                            <i class="bi bi-link"></i> Add Link
                                        </button>
                                    <?php endif; ?>
                                <?php endif; ?>
                                
                                <a href="tutor_booking_detail.php?id=<?= $booking['id'] ?>" 
                                   class="btn-view" 
                                   style="background: #e2e8f0; color: #1d3156; padding: 6px 12px; border-radius: 20px; text-decoration: none; font-size: 12px; display: inline-flex; align-items: center; gap: 5px;">
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

<!-- Completed Sessions Tab -->
<div id="completed-tab" class="tab-content">
    <?php if (empty($completedBookings)): ?>
        <div class="empty-state">
            <i class="bi bi-check2-circle"></i>
            <p>No completed or cancelled sessions</p>
            <p style="font-size: 13px;">Past sessions will appear here.</p>
        </div>
    <?php else: ?>
        <table class="bookings-table">
            <thead>
                <tr>
                    <th>Student</th>
                    <th>Language</th>
                    <th>Mode & Location</th>
                    <th>Date</th>
                    <th>Time</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($completedBookings as $booking): 
                    $statusColor = $booking['status'] === 'completed' ? '#28a745' : '#dc2626';
                    $statusIcon = $booking['status'] === 'completed' ? 'bi-check2-circle' : 'bi-x-circle';
                    $statusText = $booking['status'] === 'completed' ? 'Completed' : 'Cancelled';
                ?>
                    <tr>
                        <td data-label="Student" class="student-cell"><?= e($booking['student_name']) ?></td>
                        <td data-label="Language"><?= e($booking['language']) ?></td>
                        <td data-label="Mode & Location">
                            <?php if ($booking['learning_mode'] === 'online'): ?>
                                <span style="color:#80A1BA;">Online</span>
                            <?php else: ?>
                                <span style="color:#B4DEBD;">Face to Face</span>
                                <?php if (!empty($booking['meeting_location'])): ?>
                                    <br><small>📍 <?= e($booking['meeting_location']) ?></small>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td data-label="Date"><?= date('d M Y', strtotime($booking['booking_date'])) ?></td>
                        <td data-label="Time"><?= date('g:i A', strtotime($booking['booking_time'])) ?></td>
                        <td data-label="Status">
                            <span style="background: <?= $statusColor ?>20; color: <?= $statusColor ?>; padding: 4px 12px; border-radius: 20px; font-size: 12px;">
                                <i class="bi <?= $statusIcon ?>"></i> <?= $statusText ?>
                            </span>
                        </td>
                        <td data-label="Actions">
                            <div class="action-buttons">
                                <a href="tutor_booking_detail.php?id=<?= $booking['id'] ?>" class="btn-view">
                                    <i class="bi bi-eye"></i> Details
                                </a>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
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
    document.getElementById('completed-tab').classList.remove('active');
    document.getElementById('disputes-tab').classList.remove('active');  // ADD THIS

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
    } else if (tab === 'disputes') {  // ADD THIS
        document.getElementById('disputes-tab').classList.add('active');
        titleEl.innerHTML = '<i class="bi bi-exclamation-triangle"></i> Disputes';
        descEl.innerHTML = 'Student-reported issues requiring attention.';
    } else if (tab === 'upcoming') {
        document.getElementById('upcoming-tab').classList.add('active');
        titleEl.innerHTML = '<i class="bi bi-calendar-check"></i> Upcoming Sessions';
        descEl.innerHTML = 'View your confirmed and upcoming teaching sessions';
    } else if (tab === 'completed') {  
        document.getElementById('completed-tab').classList.add('active');
        titleEl.innerHTML = '<i class="bi bi-check2-circle"></i> Completed Sessions';
        descEl.innerHTML = 'Past sessions where you can review and contact students.';
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

function contactStudent(studentName, studentEmail, language) {
    // Get tutor's WhatsApp number from your user data
    const tutorPhone = "<?= e($user['phone'] ?? '') ?>";
    
    if (!tutorPhone) {
        showToast('Please add your WhatsApp number in your profile first', '#f59e0b');
        return;
    }
    
    const message = `Hi ${studentName}! I'm your ${language} tutor. I wanted to follow up on our completed session. Do you have any questions or feedback for me?`;
    const encodedMessage = encodeURIComponent(message);
    const whatsappUrl = `https://wa.me/${studentEmail}?text=${encodedMessage}`;
    
    // Since we don't have student's phone, open email or use the email
    if (confirm(`Contact ${studentName} via email? Click OK to open email client.`)) {
        window.location.href = `mailto:${studentEmail}?subject=Follow up on ${language} session&body=${encodedMessage}`;
    }
}

// ============================================
// FILTER SYSTEM FOR ALL TABS (CLIENT-SIDE)
// ============================================

// Store all data as JavaScript arrays
const allPendingRequests = <?= json_encode($pendingRequests) ?>;
const allRescheduleRequests = <?= json_encode($rescheduleRequests) ?>;
const allUpcomingBookings = <?= json_encode($upcomingBookings) ?>;
const allCompletedBookings = <?= json_encode($completedBookings) ?>;
const allDisputes = <?= json_encode($disputes) ?>;

function formatTime(timeString) {
    if (!timeString) return '--:--';
    const date = new Date(`2000-01-01T${timeString}`);
    return date.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true });
}

function escapeHtml(str) {
    if (!str) return '';
    return str.replace(/[&<>]/g, function(m) {
        if (m === '&') return '&amp;';
        if (m === '<') return '&lt;';
        if (m === '>') return '&gt;';
        return m;
    });
}

function showToast(message, color) {
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

// ========== PENDING REQUESTS RENDER ==========
function renderPendingTable(requests) {
    const container = document.getElementById('pending-tab');
    if (!container) return;
    
    if (requests.length === 0) {
        container.innerHTML = `
            <div class="empty-state">
                <i class="bi bi-inbox"></i>
                <p>No pending booking requests</p>
                <p style="font-size: 13px;">When students book sessions, they'll appear here.</p>
            </div>
        `;
        return;
    }
    
    let html = `
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
    `;
    
    let prevStudent = '', prevLanguage = '', prevMode = '', prevDate = '';
    
    for (const request of requests) {
        const showStudent = (request.student_name !== prevStudent);
        const showLanguage = (request.language !== prevLanguage || showStudent);
        const showMode = (request.learning_mode !== prevMode || showStudent);
        const showDate = (request.booking_date !== prevDate || showStudent);
        
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
                <td data-label="Student" class="student-cell">${showStudent ? escapeHtml(request.student_name) : '<span style="visibility: hidden;">—</span>'}</td>
                <td data-label="Language">${showLanguage ? escapeHtml(request.language) : '<span style="visibility: hidden;">—</span>'}</td>
                <td data-label="Mode & Location">${showMode ? modeDisplay : '<span style="visibility: hidden;">—</span>'}</td>
                <td data-label="Day">${showDate ? new Date(request.booking_date).toLocaleDateString('en-US', { weekday: 'long' }) : '<span style="visibility: hidden;">—</span>'}</td>
                <td data-label="Date">${showDate ? new Date(request.booking_date).toLocaleDateString('en-US', { day: '2-digit', month: 'short', year: 'numeric' }) : '<span style="visibility: hidden;">—</span>'}</td>
                <td data-label="Time" style="white-space: nowrap;">${formatTime(request.booking_time)}</td>
                <td data-label="Actions">
                    <div class="action-buttons">
                        <button class="btn-accept" onclick="acceptBooking(${request.id})"><i class="bi bi-check-lg"></i> Accept</button>
                        <button class="btn-reject" onclick="showRejectModal(${request.id})"><i class="bi bi-x-lg"></i> Reject</button>
                    </div>
                </td>
            </tr>
        `;
        
        prevStudent = request.student_name;
        prevLanguage = request.language;
        prevMode = request.learning_mode;
        prevDate = request.booking_date;
    }
    
    html += '</tbody></table>';
    container.innerHTML = html;
}

// ========== DISPUTES RENDER ==========
function renderDisputesTable(disputes) {
    const container = document.getElementById('disputes-tab');
    if (!container) return;
    
    if (disputes.length === 0) {
        container.innerHTML = `
            <div class="empty-state">
                <i class="bi bi-chat-dots"></i>
                <p>No pending disputes</p>
                <p style="font-size: 13px;">When students report issues, they'll appear here.</p>
            </div>
        `;
        return;
    }
    
    const issue_labels = {
        'tutor_no_show': 'Tutor Did Not Attend',
        'student_no_show': 'Student Did Not Attend',
        'technical_issues': 'Technical Issues',
        'wrong_materials': 'Wrong Materials Provided',
        'payment_failed_but_deducted': 'Payment Issue',
        'other': 'Other Issue'
    };
    
    let html = `
        <table class="bookings-table">
            <thead>
                <tr>
                    <th>Student</th>
                    <th>Language</th>
                    <th>Issue Type</th>
                    <th>Message</th>
                    <th>Reported On</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
    `;
    
    for (const dispute of disputes) {
        const issueLabel = issue_labels[dispute.issue_type] || dispute.issue_type.replace(/_/g, ' ');
        const shortMessage = dispute.message ? (dispute.message.length > 100 ? dispute.message.substring(0, 100) + '...' : dispute.message) : '<span style="color:#999;">No message provided</span>';
        
        html += `
            <tr>
                <td data-label="Student" class="student-cell">${escapeHtml(dispute.student_name)}</td>
                <td data-label="Language">${escapeHtml(dispute.language)}</td>
                <td data-label="Issue Type">
                    <span style="background:#fee2e2; color:#dc2626; padding:4px 10px; border-radius:20px; font-size:11px; display:inline-block;">
                        ${escapeHtml(issueLabel)}
                    </span>
                </td>
                <td data-label="Message" style="max-width: 250px;">${shortMessage}</td>
                <td data-label="Reported On" style="white-space: nowrap;">
    <?= date('d M Y', strtotime($dispute['dispute_date'])) ?>
    <?php 
    $hoursOld = (time() - strtotime($dispute['dispute_date'])) / 3600;
    if ($hoursOld > 24): 
    ?>
        <span style="background: #dc2626; color: white; padding: 2px 8px; border-radius: 12px; font-size: 10px; margin-left: 8px;">
            URGENT
        </span>
    <?php elseif ($hoursOld > 12): ?>
        <span style="background: #f59e0b; color: white; padding: 2px 8px; border-radius: 12px; font-size: 10px; margin-left: 8px;">
            Due Soon
        </span>
    <?php endif; ?>
</td>
                                        <td data-label="Actions">
    <div class="action-buttons">
        <a href="tutor_booking_detail.php?id=<?= $dispute['booking_id'] ?>" class="btn-view">
            <i class="bi bi-eye"></i> View Details
        </a>
    </div>
</td>
            </tr>
        `;
    }
    
    html += '</tbody><tr>';
    container.innerHTML = html;
}

// ========== COMPLETED BOOKINGS RENDER ==========
function renderCompletedTable(bookings) {
    const container = document.getElementById('completed-tab');
    if (!container) return;
    
    if (bookings.length === 0) {
        container.innerHTML = `
            <div class="empty-state">
                <i class="bi bi-check2-circle"></i>
                <p>No completed or cancelled sessions</p>
                <p style="font-size: 13px;">Past sessions will appear here.</p>
            </div>
        `;
        return;
    }
    
    let html = `
        <table class="bookings-table">
            <thead>
                <tr>
                    <th>Student</th>
                    <th>Language</th>
                    <th>Mode & Location</th>
                    <th>Date</th>
                    <th>Time</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
    `;
    
    for (const booking of bookings) {
        const statusColor = booking.status === 'completed' ? '#28a745' : '#dc2626';
        const statusIcon = booking.status === 'completed' ? 'bi-check2-circle' : 'bi-x-circle';
        const statusText = booking.status === 'completed' ? 'Completed' : 'Cancelled';
        
        html += `
            <tr>
                <td data-label="Student" class="student-cell">${escapeHtml(booking.student_name)}</td>
                <td data-label="Language">${escapeHtml(booking.language)}</td>
                <td data-label="Mode & Location">
                    ${booking.learning_mode === 'online' ? 
                        '<span style="color:#80A1BA;">Online</span>' : 
                        '<span style="color:#B4DEBD;">Face to Face</span>' +
                        (booking.meeting_location ? '<br><small>📍 ' + escapeHtml(booking.meeting_location) + '</small>' : '')
                    }
                </td>
                <td data-label="Date">${new Date(booking.booking_date).toLocaleDateString('en-US', { day: '2-digit', month: 'short', year: 'numeric' })}</td>
                <td data-label="Time">${formatTime(booking.booking_time)}</td>
                <td data-label="Status">
                    <span style="background: ${statusColor}20; color: ${statusColor}; padding: 4px 12px; border-radius: 20px; font-size: 12px;">
                        <i class="bi ${statusIcon}"></i> ${statusText}
                    </span>
                </td>
                <td data-label="Actions">
                    <div class="action-buttons">
                        <a href="tutor_booking_detail.php?id=${booking.id}" class="btn-view">
                            <i class="bi bi-eye"></i> Details
                        </a>
                    </div>
                </td>
            </tr>
        `;
    }
    
    html += '</tbody></table>';
    container.innerHTML = html;
}
// ========== RESCHEDULE REQUESTS RENDER ==========
function renderRescheduleTable(requests) {
    const container = document.getElementById('reschedule-tab');
    if (!container) return;
    
    if (requests.length === 0) {
        container.innerHTML = `
            <div class="empty-state">
                <i class="bi bi-calendar-x"></i>
                <p>No pending reschedule requests</p>
                <p style="font-size: 13px;">When students request to reschedule, they'll appear here.</p>
            </div>
        `;
        return;
    }
    
    let html = `
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
    `;
    
    let prevStudent = '', prevLanguage = '', prevMode = '', prevOriginal = '';
    
    for (const rr of requests) {
        const showStudent = (rr.student_name !== prevStudent);
        const showLanguage = (rr.language !== prevLanguage || showStudent);
        const showMode = (rr.learning_mode !== prevMode || showStudent);
        const showOriginal = ((rr.old_date + '|' + rr.old_time) !== prevOriginal || showStudent);
        
        let modeDisplay = '';
        if (rr.learning_mode === 'online') {
            modeDisplay = '<span style="color:#80A1BA;">Online</span>';
        } else {
            const location = (rr.meeting_location && rr.meeting_location !== '') ? rr.meeting_location : 'Location to be confirmed';
            modeDisplay = '<span style="color:#B4DEBD;">Face to Face</span><br><small style="font-size:11px; color:#64748b;">📍 ' + escapeHtml(location) + '</small>';
        }
        
        html += `
            <tr>
                <td data-label="Student" class="student-cell">${showStudent ? escapeHtml(rr.student_name) : '<span style="display: inline-block; width: 1px;">&nbsp;</span>'}</td>
                <td data-label="Language">${showLanguage ? escapeHtml(rr.language) : '<span style="display: inline-block; width: 1px;">&nbsp;</span>'}</td>
                <td data-label="Mode & Location">${showMode ? modeDisplay : '<span style="display: inline-block; width: 1px;">&nbsp;</span>'}</td>
                <td data-label="Original">${showOriginal ? new Date(rr.old_date).toLocaleDateString('en-US', { day: '2-digit', month: 'short', year: 'numeric' }) + ' @ ' + formatTime(rr.old_time) : '<span style="display: inline-block; width: 1px;">&nbsp;</span>'}</td>
                <td data-label="Requested">${new Date(rr.new_date).toLocaleDateString('en-US', { day: '2-digit', month: 'short', year: 'numeric' })} @ ${formatTime(rr.new_time)}</td>
                <td data-label="Actions">
                    <div class="action-buttons">
                        <button class="btn-accept" onclick="processReschedule(${rr.id}, ${rr.booking_id}, 'accept', '${rr.new_date}', '${rr.new_time}', event)"><i class="bi bi-check-lg"></i> Accept</button>
                        <button class="btn-reject" onclick="showRejectRescheduleModal(${rr.id}, ${rr.booking_id})"><i class="bi bi-x-lg"></i> Reject</button>
                    </div>
                </td>
             </tr>
        `;
        
        prevStudent = rr.student_name;
        prevLanguage = rr.language;
        prevMode = rr.learning_mode;
        prevOriginal = rr.old_date + '|' + rr.old_time;
    }
    
    html += '</tbody></table>';
    container.innerHTML = html;
}
// ========== UPCOMING BOOKINGS RENDER ==========
function renderUpcomingTable(bookings) {
    const container = document.getElementById('upcoming-tab');
    if (!container) return;
    
    if (bookings.length === 0) {
        container.innerHTML = `
            <div class="empty-state">
                <i class="bi bi-calendar-check"></i>
                <p>No upcoming confirmed sessions</p>
                <p style="font-size: 13px;">Accepted bookings will appear here.</p>
            </div>
        `;
        return;
    }
    
    let html = `
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
    `;
    
    let prevStudent = '', prevLanguage = '', prevDate = '';
    
    for (const booking of bookings) {
        const showStudent = (booking.student_name !== prevStudent);
        const showLanguage = (booking.language !== prevLanguage || showStudent);
        const showDate = (booking.booking_date !== prevDate || showStudent);
        
        // Mode & Location - ALWAYS SHOW (no condition)
        let modeDisplay = '';
        if (booking.learning_mode === 'online') {
            modeDisplay = '<span style="color:#80A1BA;">Online</span>';
        } else {
            const location = (booking.meeting_location && booking.meeting_location !== '') 
                ? booking.meeting_location 
                : 'Location to be confirmed';
            modeDisplay = '<span style="color:#B4DEBD;">Face to Face</span><br><small style="font-size:11px; color:#64748b;">📍 ' + escapeHtml(location) + '</small>';
        }
        
        html += `
            <tr>
                <td data-label="Student" class="student-cell">${showStudent ? escapeHtml(booking.student_name) : '<span style="display: inline-block; width: 1px;">&nbsp;</span>'}</td>
                <td data-label="Language">${showLanguage ? escapeHtml(booking.language) : '<span style="display: inline-block; width: 1px;">&nbsp;</span>'}</td>
                <td data-label="Mode & Location">${modeDisplay}</td>
                <td data-label="Day">${showDate ? new Date(booking.booking_date).toLocaleDateString('en-US', { weekday: 'long' }) : '<span style="display: inline-block; width: 1px;">&nbsp;</span>'}</td>
                <td data-label="Date">${showDate ? new Date(booking.booking_date).toLocaleDateString('en-US', { day: '2-digit', month: 'short', year: 'numeric' }) : '<span style="display: inline-block; width: 1px;">&nbsp;</span>'}</td>
                <td data-label="Time" style="white-space: nowrap;">${formatTime(booking.booking_time)}</td>
                <td data-label="Actions">
                    <div class="action-buttons" style="display: flex; gap: 8px; flex-wrap: wrap;">
                        ${booking.learning_mode === 'online' ? 
                            (booking.meeting_link ? 
                                `<a href="join_meeting.php?booking_id=${booking.id}&link=${encodeURIComponent(booking.meeting_link)}" target="_blank" class="btn-attend" style="background: #28a745; color: white; padding: 6px 14px; border-radius: 20px; text-decoration: none; font-size: 12px; font-weight: 600;"><i class="bi bi-camera-video-fill"></i> Attend Class</a>` :
                                `<button class="btn-add-link" onclick="showAddLinkModal(${booking.id})" style="background: #f59e0b; color: white; padding: 6px 14px; border-radius: 20px; border: none; font-size: 12px; font-weight: 600; cursor: pointer;"><i class="bi bi-link"></i> Add Link</button>`
                            ) : ''
                        }
                        <a href="tutor_booking_detail.php?id=${booking.id}" class="btn-view" style="background: #e2e8f0; color: #1d3156; padding: 6px 12px; border-radius: 20px; text-decoration: none; font-size: 12px;"><i class="bi bi-eye"></i> Details</a>
                    </div>
                </td>
            </tr>
        `;
        
        prevStudent = booking.student_name;
        prevLanguage = booking.language;
        prevDate = booking.booking_date;
    }
    
    html += '</tbody></table>';
    container.innerHTML = html;
}

// ========== MEETING LINK FUNCTIONS ==========
function showAddLinkModal(bookingId) {
    document.getElementById('link_booking_id').value = bookingId;
    document.getElementById('meeting_link_input').value = '';
    document.getElementById('meetingLinkModal').classList.add('active');
}

function showEditLinkModal(bookingId, currentLink) {
    document.getElementById('link_booking_id').value = bookingId;
    document.getElementById('meeting_link_input').value = currentLink;
    document.getElementById('meetingLinkModal').classList.add('active');
}

function closeLinkModal() {
    document.getElementById('meetingLinkModal').classList.remove('active');
    document.getElementById('meeting_link_input').value = '';
}

function saveMeetingLink() {
    const bookingId = document.getElementById('link_booking_id').value;
    const meetingLink = document.getElementById('meeting_link_input').value.trim();
    
    if (!meetingLink) {
        showToast('Please enter a meeting link', '#f59e0b');
        return;
    }
    
    if (!meetingLink.startsWith('http://') && !meetingLink.startsWith('https://')) {
        showToast('Please enter a valid URL starting with http:// or https://', '#f59e0b');
        return;
    }
    
    const saveBtn = document.querySelector('#meetingLinkModal .btn-accept');
    const originalText = saveBtn.innerHTML;
    saveBtn.innerHTML = '<i class="bi bi-hourglass-split"></i> Saving...';
    saveBtn.disabled = true;
    
    fetch('save_meeting_link.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ booking_id: bookingId, meeting_link: meetingLink })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showToast(data.message || 'Meeting link saved!', '#28a745');
            closeLinkModal();
            setTimeout(() => location.reload(), 1500);
        } else {
            showToast('Error: ' + data.message, '#dc2626');
            saveBtn.innerHTML = originalText;
            saveBtn.disabled = false;
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showToast('Error saving meeting link', '#dc2626');
        saveBtn.innerHTML = originalText;
        saveBtn.disabled = false;
    });
}

// ========== APPLY FILTERS (CLIENT-SIDE, NO PAGE RELOAD) ==========
function applyFilters() {
    const language = document.getElementById('languageFilter').value;
    const sessionType = document.getElementById('sessionTypeFilter').value;
    const dateFrom = document.getElementById('dateFrom').value;
    const dateTo = document.getElementById('dateTo').value;
    
    const hasActiveFilters = (language !== 'all' || sessionType !== 'all' || dateFrom || dateTo);
    
    if (!hasActiveFilters) {
        showToast('Please select at least one filter before searching.', '#f59e0b');
        return;
    }
    
    // Filter based on which tab is active
    if (document.getElementById('pending-tab').classList.contains('active')) {
        const filtered = allPendingRequests.filter(request => {
            if (language !== 'all' && request.language !== language) return false;
            if (sessionType !== 'all' && request.learning_mode !== sessionType) return false;
            if (dateFrom && request.booking_date < dateFrom) return false;
            if (dateTo && request.booking_date > dateTo) return false;
            return true;
        });
        renderPendingTable(filtered);
        showToast(`Found ${filtered.length} pending request${filtered.length !== 1 ? 's' : ''}`, filtered.length === 0 ? '#dc2626' : '#28a745');
    } 
    else if (document.getElementById('reschedule-tab').classList.contains('active')) {
        const filtered = allRescheduleRequests.filter(rr => {
            if (language !== 'all' && rr.language !== language) return false;
            if (sessionType !== 'all' && rr.learning_mode !== sessionType) return false;
            if (dateFrom && rr.new_date < dateFrom) return false;
            if (dateTo && rr.new_date > dateTo) return false;
            return true;
        });
        renderRescheduleTable(filtered);
        showToast(`Found ${filtered.length} reschedule request${filtered.length !== 1 ? 's' : ''}`, filtered.length === 0 ? '#dc2626' : '#28a745');
    }
    else if (document.getElementById('disputes-tab').classList.contains('active')) {
    const filtered = allDisputes.filter(dispute => {
        if (language !== 'all' && dispute.language !== language) return false;
        if (sessionType !== 'all' && dispute.learning_mode !== sessionType) return false;
        if (dateFrom && dispute.booking_date < dateFrom) return false;
        if (dateTo && dispute.booking_date > dateTo) return false;
        return true;
    });
    renderDisputesTable(filtered);
    showToast(`Found ${filtered.length} dispute${filtered.length !== 1 ? 's' : ''}`, filtered.length === 0 ? '#dc2626' : '#f59e0b');
}
    else if (document.getElementById('upcoming-tab').classList.contains('active')) {
        const filtered = allUpcomingBookings.filter(booking => {
            if (language !== 'all' && booking.language !== language) return false;
            if (sessionType !== 'all' && booking.learning_mode !== sessionType) return false;
            if (dateFrom && booking.booking_date < dateFrom) return false;
            if (dateTo && booking.booking_date > dateTo) return false;
            return true;
        });
        renderUpcomingTable(filtered);
        showToast(`Found ${filtered.length} upcoming session${filtered.length !== 1 ? 's' : ''}`, filtered.length === 0 ? '#dc2626' : '#28a745');
    }else if (document.getElementById('completed-tab').classList.contains('active')) {
    const filtered = allCompletedBookings.filter(booking => {
        if (language !== 'all' && booking.language !== language) return false;
        if (sessionType !== 'all' && booking.learning_mode !== sessionType) return false;
        if (dateFrom && booking.booking_date < dateFrom) return false;
        if (dateTo && booking.booking_date > dateTo) return false;
        return true;
    });
    renderCompletedTable(filtered);
    showToast(`Found ${filtered.length} completed session${filtered.length !== 1 ? 's' : ''}`, filtered.length === 0 ? '#dc2626' : '#28a745');
}
}

// ========== RESET FILTERS ==========
function resetFilters() {
    document.getElementById('languageFilter').value = 'all';
    document.getElementById('sessionTypeFilter').value = 'all';
    document.getElementById('dateFrom').value = '';
    document.getElementById('dateTo').value = '';
    
    // Reset all tables to original data
    renderPendingTable(allPendingRequests);
    renderRescheduleTable(allRescheduleRequests);
    renderUpcomingTable(allUpcomingBookings);
    renderCompletedTable(allCompletedBookings); 
    showToast('Filters cleared. Showing all results.', '#64748b');
}

// ========== INITIAL RENDER ON PAGE LOAD ==========
document.addEventListener('DOMContentLoaded', function() {
    console.log('DOM loaded, rendering all tables...');
    renderPendingTable(allPendingRequests);
    renderRescheduleTable(allRescheduleRequests);
    renderUpcomingTable(allUpcomingBookings);
    renderDisputesTable(allDisputes);
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
                row.style.background = '#fef2f2';
                const warningSpan = document.createElement('span');
                warningSpan.style.cssText = 'display: inline-flex; align-items: center; gap: 4px; margin-left: 10px; background: #dc2626; color: white; padding: 2px 8px; border-radius: 20px; font-size: 10px;';
                warningSpan.innerHTML = '<i class="bi bi-exclamation-triangle-fill"></i> Expires tonight!';
                const actionsCell = row.querySelector('td:last-child');
                if (actionsCell && !row.querySelector('.expiry-warning')) {
                    warningSpan.className = 'expiry-warning';
                    actionsCell.appendChild(warningSpan);
                }
            } else if (daysUntil === 1) {
                row.style.background = '#fffbeb';
            }
        }
    });
}

let isSubmittingReschedule = false;
let isSubmittingRejectReschedule = false;
let currentRejectRequestId = null;
let currentRejectBookingId = null;

// ─── RESCHEDULE: ACCEPT ───────────────────────────────────────────────────
function processReschedule(requestId, bookingId, action, newDate, newTime, clickEvent) {
    if (isSubmittingReschedule) { showToast('Please wait…', '#f59e0b'); return; }

    const clickedBtn = clickEvent ? clickEvent.target.closest('button') : null;
    const row = clickedBtn ? clickedBtn.closest('tr') : null;
    const rowBtns = row ? row.querySelectorAll('button') : [];
    const originalHtml = clickedBtn ? clickedBtn.innerHTML : '';

    if (!confirm('Accept this reschedule request? The booking will be updated to the new date/time.')) return;

    // Lock
    isSubmittingReschedule = true;
    rowBtns.forEach(b => { b.disabled = true; b.style.opacity = '0.5'; });
    if (clickedBtn) clickedBtn.innerHTML = '<i class="bi bi-hourglass-split"></i> Processing…';

    fetch('process_reschedule.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ request_id: requestId, booking_id: bookingId, action: 'accept', new_date: newDate, new_time: newTime })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            showToast('Reschedule accepted! Refreshing…', '#28a745');
            setTimeout(() => location.reload(), 500);
        } else {
            showToast('Error: ' + data.message, '#dc2626');
            unlock();
        }
    })
    .catch(() => { showToast('Network error. Please try again.', '#dc2626'); unlock(); });

    function unlock() {
        isSubmittingReschedule = false;
        rowBtns.forEach(b => { b.disabled = false; b.style.opacity = '1'; });
        if (clickedBtn) clickedBtn.innerHTML = originalHtml;
    }
}

// ─── RESCHEDULE: REJECT MODAL ─────────────────────────────────────────────
function showRejectRescheduleModal(requestId, bookingId) {
    currentRejectRequestId = requestId;
    currentRejectBookingId = bookingId;
    isSubmittingRejectReschedule = false;
    document.getElementById('reject_reason_input').value = '';
    const submitBtn = document.querySelector('#rejectRescheduleModal .btn-reject');
    if (submitBtn) { submitBtn.disabled = false; submitBtn.innerHTML = '<i class="bi bi-x-lg"></i> Submit Rejection'; submitBtn.style.opacity = '1'; }
    document.getElementById('rejectRescheduleModal').classList.add('active');
}



function submitRejectReschedule() {
    if (isSubmittingRejectReschedule) { showToast('Already submitting…', '#f59e0b'); return; }

    const reason = document.getElementById('reject_reason_input').value.trim();
    if (!reason) { showToast('Please provide a reason for rejection', '#f59e0b'); return; }

    const submitBtn = document.querySelector('#rejectRescheduleModal .btn-reject');
    const cancelBtn = document.querySelector('#rejectRescheduleModal .btn-view');

    isSubmittingRejectReschedule = true;
    if (submitBtn) { submitBtn.disabled = true; submitBtn.innerHTML = '<i class="bi bi-hourglass-split"></i> Submitting…'; submitBtn.style.opacity = '0.5'; }
    if (cancelBtn) { cancelBtn.disabled = true; cancelBtn.style.opacity = '0.5'; }

    fetch('process_reschedule.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ request_id: currentRejectRequestId, booking_id: currentRejectBookingId, action: 'reject', reject_reason: reason })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            showToast('Reschedule rejected. Refreshing…', '#64748b');
            closeRejectRescheduleModal();
            setTimeout(() => location.reload(), 500);
        } else {
            showToast('Error: ' + data.message, '#dc2626');
            unlock();
        }
    })
    .catch(() => { showToast('Network error. Please try again.', '#dc2626'); unlock(); });

    function unlock() {
        isSubmittingRejectReschedule = false;
        if (submitBtn) { submitBtn.disabled = false; submitBtn.innerHTML = '<i class="bi bi-x-lg"></i> Submit Rejection'; submitBtn.style.opacity = '1'; }
        if (cancelBtn) { cancelBtn.disabled = false; cancelBtn.style.opacity = '1'; }
    }
}

function closeRejectRescheduleModal() {
    document.getElementById('rejectRescheduleModal').classList.remove('active');
    currentRejectRequestId = null;
    currentRejectBookingId = null;
}

setTimeout(checkExpiringRequests, 500);
</script>

</body>
</html>