<?php
session_start();
include 'config.php';

$assetBase = '../assets/img';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$userID = $_SESSION['user_id'];
$booking_id = intval($_GET['id'] ?? 0);

if (!$booking_id) {
    header("Location: booking_requests.php");
    exit();
}

$stmt = $conn->prepare("SELECT * FROM users WHERE id = ? AND role = 'tutor'");
$stmt->bind_param("i", $userID);
$stmt->execute();
$tutor = $stmt->get_result()->fetch_assoc();

if (!$tutor) {
    header("Location: login.php");
    exit();
}

$displayName = $tutor['fullname'];
$profilePic = !empty($tutor['profile_pic'])
    ? '../uploads/profiles/' . $tutor['profile_pic']
    : $assetBase . '/profile-tutor.png';

$stmt = $conn->prepare("
    SELECT b.*, 
           u.fullname as student_name, 
           u.email as student_email,
           u.phone as student_phone,
           u.profile_pic as student_pic,
           tp.rate
    FROM bookings b
    JOIN users u ON b.student_id = u.id
    JOIN tutor_profiles tp ON b.tutor_id = tp.user_id
    WHERE b.id = ? AND b.tutor_id = ?
");
$stmt->bind_param("ii", $booking_id, $userID);
$stmt->execute();
$booking = $stmt->get_result()->fetch_assoc();

if (!$booking) {
    header("Location: booking_requests.php");
    exit();
}

$stmt = $conn->prepare("
    SELECT * FROM reschedule_requests 
    WHERE booking_id = ? AND status = 'pending'
    ORDER BY created_at DESC LIMIT 1
");
$stmt->bind_param("i", $booking_id);
$stmt->execute();
$pendingReschedule = $stmt->get_result()->fetch_assoc();

$stmt = $conn->prepare("
    SELECT * FROM payments 
    WHERE booking_id = ? 
    ORDER BY created_at DESC 
    LIMIT 1
");
$stmt->bind_param("i", $booking_id);
$stmt->execute();
$payment = $stmt->get_result()->fetch_assoc();

function e($value) {
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Booking Details - Kyoshi Tutor</title>

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

.page-top {
    display: grid;
    grid-template-columns: 120px 1fr 120px;
    align-items: center;
    margin-bottom: 35px;
}

.page-top h1 {
    text-align: center;
    font-size: 30px;
    color: #1d3156;
    font-weight: 700;
}

.back-link {
    display: flex;
    align-items: center;
    gap: 8px;
    width: max-content;
    text-decoration: none;
    color: #496894;
    font-size: 14px;
    font-weight: 600;
    padding: 10px 18px;
    border-radius: 30px;
    background: white;
    border: 1px solid #e2e8f0;
}

.back-link:hover {
    background: #f8fafc;
}

.main-container {
    background: white;
    border-radius: 24px;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.05);
    overflow: hidden;
}

.main-content {
    display: grid;
    grid-template-columns: 1fr 380px;
    gap: 0;
}

@media (max-width: 900px) {
    .main-content {
        grid-template-columns: 1fr;
    }
}

.left-section {
    padding: 28px;
    border-right: 1px solid #eef2f7;
}

.right-section {
    padding: 28px;
}

.section-title {
    font-size: 16px;
    font-weight: 700;
    color: #1d3156;
    margin-bottom: 16px;
    padding-bottom: 8px;
    border-bottom: 2px solid rgba(254, 214, 206, 0.92);;
    display: inline-block;
}

.section-subtitle {
    font-size: 14px;
    font-weight: 700;
    color: #1d3156;
    margin-bottom: 12px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.divider {
    height: 1px;
    background: #eef2f7;
    margin: 24px 0;
}

.info-row {
    display: flex;
    justify-content: space-between;
    padding: 10px 0;
    border-bottom: 1px solid #eef2f7;
}

.info-row:last-child {
    border-bottom: none;
}

.info-label {
    font-weight: 600;
    color: #475569;
    font-size: 13px;
}

.info-value {
    font-weight: 700;
    color: #1d3156;
    font-size: 13px;
    text-align: right;
}

.student-info {
    display: flex;
    gap: 20px;
    align-items: center;
    margin-bottom: 20px;
}

.student-avatar {
    width: 70px;
    height: 70px;
    border-radius: 50%;
    object-fit: cover;
    border: 3px solid #eef2f7;
}

.student-details h3 {
    font-size: 18px;
    color: #1d3156;
    margin-bottom: 5px;
}

.student-details p {
    font-size: 13px;
    color: #64748b;
    margin: 3px 0;
}

.status-badge {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 700;
}

.status-pending { background: #fff3e0; color: #e67e22; }
.status-accepted { background: #fff3e0; color: #e67e22; }
.status-confirmed { background: #d4edda; color: #28a745; }
.status-completed { background: #dbeafe; color: #3b82f6; }
.status-cancelled { background: #fee2e2; color: #dc2626; }
.status-rescheduled { background: #fef3c7; color: #f59e0b; }

.payment-badge {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 600;
}

.payment-pending { background: #fff3e0; color: #e67e22; }
.payment-verified { background: #d4edda; color: #28a745; }

.btn-pink {
    background: linear-gradient(135deg, #E75A9B, #F28AB2);
    color: white;
    border: none;
    padding: 10px 20px;
    border-radius: 30px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    transition: 0.2s;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    text-decoration: none;
    width: 100%;
    justify-content: center;
}

.btn-pink:hover {
    background: #d94a8a;
    transform: translateY(-1px);
}

.btn-outline {
    background: #e2e8f0;
    color: #1d3156;
    border: none;
    padding: 10px 20px;
    border-radius: 30px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    transition: 0.2s;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    text-decoration: none;
    width: 100%;
    justify-content: center;
}

.btn-outline:hover {
    background: #cbd5e1;
    transform: translateY(-1px);
}

.btn-whatsapp {
    background: #25D366;
    color: white;
    border: none;
    padding: 10px 20px;
    border-radius: 30px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    transition: 0.2s;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    text-decoration: none;
    width: 100%;
    justify-content: center;
}

.btn-whatsapp:hover {
    background: #1da851;
    transform: translateY(-1px);
}

.btn-secondary {
    background: #dc2626;
    color: white;
    border: none;
    padding: 10px 20px;
    border-radius: 30px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    transition: 0.2s;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    width: 100%;
    justify-content: center;
}

.btn-secondary:hover {
    background: #c82333;
    transform: translateY(-1px);
}

.reschedule-notice {
    background: #fef3c7;
    border-left: 4px solid #f59e0b;
    border-radius: 12px;
    padding: 16px;
    margin-bottom: 20px;
}

.reschedule-notice p {
    margin: 0;
    color: #92400e;
    font-size: 13px;
}

.info-box {
    border: 1px solid #eef2f7;
    border-radius: 16px;
    padding: 16px;
    margin-bottom: 20px;
}

.warning-box {
    background: #fef3c7;
    border-radius: 12px;
    padding: 12px;
    margin-bottom: 16px;
}

.light-bg {
    background: #f8fafc;
    border-radius: 12px;
    padding: 12px;
    margin-bottom: 12px;
}

.button-group {
    display: flex;
    gap: 12px;
    flex-direction: column;
    margin-top: 12px;
}

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

.modal-buttons {
    display: flex;
    gap: 12px;
    justify-content: flex-end;
    margin-top: 20px;
}

@media (max-width: 768px) {
    .left-section {
        padding: 20px;
    }
    .right-section {
        padding: 20px;
    }
    .student-info {
        flex-direction: column;
        text-align: center;
    }
    .info-row {
        flex-direction: column;
        gap: 5px;
        text-align: center;
    }
    .info-value {
        text-align: center;
    }
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
                <a href="availability.php">Availability</a>
                <a href="booking_requests.php" class="active">Requests</a>
                <a href="learning_materials.php">Materials</a>
                <a href="earnings.php">Earnings</a>
                <a href="meeting_links.php">Meeting Links</a>
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
    <div class="page-top">
        <a href="booking_requests.php" class="back-link">
            <i class="bi bi-arrow-left"></i> Back
        </a>
        <h1>Booking Details</h1>
        <div></div>
    </div>

    <?php if ($pendingReschedule): ?>
    <div class="reschedule-notice">
        <i class="bi bi-clock-history"></i>
        <p><strong>Pending Reschedule Request</strong><br>
        Student has requested to reschedule this session from <?= date('d M Y, g:i A', strtotime($pendingReschedule['old_date'] . ' ' . $pendingReschedule['old_time'])) ?> 
        to <?= date('d M Y, g:i A', strtotime($pendingReschedule['new_date'] . ' ' . $pendingReschedule['new_time'])) ?>.
        Please go to <a href="booking_requests.php" style="color:#f59e0b;">Reschedule Requests tab</a> to accept or reject.</p>
    </div>
    <?php endif; ?>

    <div class="main-container">
        <div class="main-content">
            <!-- LEFT SIDE -->
            <div class="left-section">
                <div>
                    <div class="section-title">
                        <i class="bi bi-person-circle"></i> Student Information
                    </div>
                    <div class="student-info">
                        <?php 
                        $studentPic = !empty($booking['student_pic']) 
                            ? '../uploads/profiles/' . $booking['student_pic']
                            : $assetBase . '/profile-student.png';
                        ?>
                        <img src="<?= e($studentPic) ?>" alt="Student" class="student-avatar">
                        <div class="student-details">
                            <h3><?= e($booking['student_name']) ?></h3>
                            <p><i class="bi bi-envelope"></i> <?= e($booking['student_email']) ?></p>
                            <?php if (!empty($booking['student_phone'])): ?>
                                <p><i class="bi bi-telephone"></i> <?= e($booking['student_phone']) ?></p>
                            <?php else: ?>
                                <p><i class="bi bi-telephone"></i> <span style="color: #0e97b6;">No phone number provided</span></p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="divider"></div>

                <div>
                    <div class="section-title">
                        <i class="bi bi-calendar-event"></i> Session Details
                    </div>
                    <div class="info-row">
                        <span class="info-label">Date</span>
                        <span class="info-value"><?= date('l, F j, Y', strtotime($booking['booking_date'])) ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Time</span>
                        <span class="info-value"><?= date('g:i A', strtotime($booking['booking_time'])) ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Duration</span>
                        <span class="info-value">1 hour</span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Language</span>
                        <span class="info-value"><?= e($booking['language']) ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Learning Mode</span>
                        <span class="info-value"><?= $booking['learning_mode'] === 'online' ? 'Online' : 'Face to Face' ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Focus Areas</span>
                        <span class="info-value"><?= e($booking['focus'] ?? 'Not specified') ?></span>
                    </div>
                                            <div class="info-row">
                            <span class="info-label">Proficiency Level</span>
                            <span class="info-value">
                                <?php 
                                $level = $booking['proficiency_level'] ?? 'beginner';
                                $levelLabels = [
                                    'beginner' => 'Beginner',
                                    'intermediate' => 'Intermediate',
                                    'advanced' => 'Advanced',
                                    'master' => 'Master'
                                ];
                                echo $levelLabels[$level] ?? ucfirst($level);
                                ?>
                            </span>
                        </div>
                    <?php if (!empty($booking['notes'])): ?>
                        <div class="info-row">
                            <span class="info-label">Student Notes</span>
                            <span class="info-value" style="text-align: left;"><?= nl2br(e($booking['notes'])) ?></span>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="divider"></div>

                <div>
                    <div class="section-title">
                        <i class="bi bi-cash-stack"></i> Payment
                    </div>
                    <div class="info-row">
                        <span class="info-label">Total Amount</span>
                        <span class="info-value" style="font-size: 18px; color: #e67e22;">
                            RM <?= number_format($booking['total_amount'], 2) ?>
                        </span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Payment Status</span>
                        <span class="info-value">
                            <?php if ($payment && $payment['status'] === 'verified'): ?>
                                <span class="payment-badge payment-verified"><i class="bi bi-check-circle"></i> Paid</span>
                            <?php elseif ($payment && $payment['status'] === 'pending'): ?>
                                <span class="payment-badge payment-pending"><i class="bi bi-clock"></i> Pending Verification</span>
                            <?php else: ?>
                                <span class="payment-badge payment-pending"><i class="bi bi-hourglass"></i> Awaiting Payment</span>
                            <?php endif; ?>
                        </span>
                    </div>
                    <?php if ($payment && $payment['proof_image']): ?>
                        <div class="info-row">
                            <span class="info-label">Payment Proof</span>
                            <span class="info-value">
                                <a href="../uploads/payment_proofs/<?= e($payment['proof_image']) ?>" target="_blank" style="color: #1d3156;">
                                    <i class="bi bi-file-earmark-image"></i> View Proof
                                </a>
                            </span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- RIGHT SIDE -->
            <div class="right-section">
                <div class="section-title" style="margin-bottom: 20px;">
                    <i class="bi bi-gear"></i> Actions
                </div>

                <!-- Status -->
                <div class="info-box">
                    <div class="info-row">
                        <span class="info-label">Current Status</span>
                        <span class="info-value">
                            <span class="status-badge status-<?= e($booking['status']) ?>">
                                <?php 
                                $statusDisplay = $booking['status'];
                                if ($statusDisplay === 'accepted') $statusDisplay = 'Awaiting Payment';
                                echo ucfirst($statusDisplay);
                                ?>
                            </span>
                        </span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Booked On</span>
                        <span class="info-value"><?= date('d M Y, g:i A', strtotime($booking['created_at'])) ?></span>
                    </div>
                </div>

                <!-- Meeting Link or Location -->
                <?php if ($booking['learning_mode'] === 'online'): ?>
                    <div class="info-box">
                        <div class="section-subtitle">
                            <i class="bi bi-camera-video"></i> Meeting Link
                        </div>
                        <?php if (!empty($booking['meeting_link'])): ?>
                            <div class="light-bg">
                                <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 8px;">
                                    <i class="bi bi-link-45deg"></i>
                                    <span style="font-size: 13px; font-weight: 600;">Current Link:</span>
                                </div>
                                <a href="<?= e($booking['meeting_link']) ?>" target="_blank" style="color: #E75A9B; word-break: break-all; font-size: 13px;">
                                    <?= e($booking['meeting_link']) ?>
                                </a>
                            </div>
                            <div class="button-group">
                                <a href="<?= e($booking['meeting_link']) ?>" target="_blank" class="btn-pink">
                                    <i class="bi bi-camera-video-fill"></i> Join Meeting
                                </a>
                                <button onclick="showEditLinkModal(<?= $booking['id'] ?>, '<?= e($booking['meeting_link']) ?>')" class="btn-outline">
                                    <i class="bi bi-pencil"></i> Edit Link
                                </button>
                            </div>
                        <?php else: ?>
                            <div class="warning-box">
                                <div style="display: flex; align-items: center; gap: 8px;">
                                    <i class="bi bi-exclamation-triangle"></i>
                                    <span>No meeting link added yet</span>
                                </div>
                                <p style="font-size: 12px; margin-top: 8px;">Please add a meeting link so the student can join the session.</p>
                            </div>
                            <button onclick="showAddLinkModal(<?= $booking['id'] ?>)" class="btn-pink">
                                <i class="bi bi-plus-circle"></i> Add Meeting Link
                            </button>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="info-box">
                        <div class="section-subtitle">
                            <i class="bi bi-geo-alt"></i> Meeting Location
                        </div>
                        <?php if (!empty($booking['meeting_location'])): ?>
                            <div class="light-bg">

                                <p style="margin: 0 0 12px 0; font-size: 14px;"><?= nl2br(e($booking['meeting_location'])) ?></p>
                                <a href="https://www.google.com/maps/search/?api=1&query=<?= urlencode($booking['meeting_location']) ?>" 
                                   target="_blank" class="btn-outline">
                                    <i class="bi bi-map"></i> Open in Google Maps
                                </a>
                            </div>
                        <?php else: ?>
                            <div class="warning-box">
                                <div style="display: flex; align-items: center; gap: 8px;">
                                    <i class="bi bi-exclamation-triangle"></i>
                                    <span>No location provided</span>
                                </div>
                                <p style="font-size: 12px; margin-top: 8px;">Please contact the student to confirm meeting location.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                <!-- Session Completion (for confirmed sessions that have ended) -->
                <?php
                $completionStmt = $conn->prepare("SELECT * FROM session_completion WHERE booking_id = ?");
                $completionStmt->bind_param("i", $booking_id);
                $completionStmt->execute();
                $completion = $completionStmt->get_result()->fetch_assoc();

                $tutor_confirmed = $completion['tutor_confirmed'] ?? 0;
                $student_confirmed = $completion['student_confirmed'] ?? 0;
                $both_confirmed = ($tutor_confirmed && $student_confirmed);

                $class_time = strtotime($booking['booking_date'] . ' ' . $booking['booking_time']);
                $current_time = time();
                $is_past_class = $class_time < $current_time;
                $hours_passed = round(($current_time - $class_time) / 3600, 1);
                ?>

                <?php if ($booking['status'] === 'confirmed' && $is_past_class && !$both_confirmed): ?>
                <div class="info-box">
                    <div class="section-subtitle">
                        <i class="bi bi-check2-circle"></i> Session Completion
                    </div>
                    
                    <div class="light-bg" style="margin-bottom: 12px;">
                        <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 8px;">
                            <?php if ($tutor_confirmed): ?>
                                <i class="bi bi-check-circle-fill" style="color: #28a745;"></i>
                                <span>You have confirmed this session</span>
                            <?php else: ?>
                                <i class="bi bi-clock-history" style="color: #f59e0b;"></i>
                                <span>Waiting for your confirmation</span>
                            <?php endif; ?>
                        </div>
                        <?php if (!$tutor_confirmed): ?>
                            <button onclick="confirmSession(<?= $booking_id ?>, 'tutor')" class="btn-pink" style="margin-top: 8px;">
                                <i class="bi bi-check-lg"></i> Confirm I Attended
                            </button>
                        <?php endif; ?>
                    </div>
                    
                    <div class="light-bg">
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <?php if ($student_confirmed): ?>
                                <i class="bi bi-check-circle-fill" style="color: #28a745;"></i>
                                <span>Student has confirmed attendance</span>
                            <?php else: ?>
                                <i class="bi bi-hourglass-split" style="color: #64748b;"></i>
                                <span>Waiting for student confirmation</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <?php if ($tutor_confirmed && $student_confirmed): ?>
                    <div class="warning-box" style="background: #d4edda; border-color: #28a745; margin-top: 12px;">
                        <i class="bi bi-check-circle-fill"></i>
                        <span>Both confirmed! Session will be marked as completed.</span>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                <!-- Contact -->
                <div class="info-box">
                    <div class="section-subtitle">
                        <i class="bi bi-chat-dots"></i> Contact Student
                    </div>
                    <?php if (!empty($booking['student_phone'])): 
                        $phone_raw = preg_replace('/[^0-9]/', '', $booking['student_phone']);
                        if (substr($phone_raw, 0, 1) == '0') {
                            $whatsapp_number = '60' . substr($phone_raw, 1);
                        } else {
                            $whatsapp_number = $phone_raw;
                        }
                        $whatsapp_message = urlencode("Hi {$booking['student_name']}, I'm {$displayName}, your tutor for {$booking['language']}. I'm contacting you regarding our upcoming session.");
                    ?>
                        <a href="https://wa.me/<?= $whatsapp_number ?>?text=<?= $whatsapp_message ?>" 
                           target="_blank" class="btn-whatsapp" style="margin-bottom: 12px;">
                            <i class="bi bi-whatsapp"></i> WhatsApp
                        </a>
                    <?php endif; ?>
                    <a href="https://mail.google.com/mail/?view=cm&fs=1&to=<?= urlencode($booking['student_email']) ?>&su=<?= urlencode("Regarding your {$booking['language']} session with {$displayName}") ?>" 
                       target="_blank" class="btn-pink">
                        <i class="bi bi-envelope"></i> Send Email
                    </a>
                </div>

                <!-- Materials -->
                <div class="info-box">
                    <div class="section-subtitle">
                        <i class="bi bi-book"></i> Learning Materials
                    </div>
                    <div class="button-group">
                        <a href="upload_material.php?booking_id=<?= $booking['id'] ?>" class="btn-outline">
                            <i class="bi bi-upload"></i> Upload Material
                        </a>
                        <a href="learning_materials.php?booking_id=<?= $booking['id'] ?>" class="btn-outline">
    <i class="bi bi-eye"></i> View Materials
</a>
                    </div>
                </div>
                

                <div class="info-box">
        <div class="section-subtitle">
            <i class="bi bi-journal-bookmark-fill"></i> Homework & Assignments
        </div>
        <div class="button-group">
            <a href="create_assignment.php?booking_id=<?= $booking['id'] ?>" class="btn-pink">
                <i class="bi bi-plus-circle"></i> Create Assignment
            </a>
<?php
// Check if there are any SUBMITTED assignments
$checkSubmissions = $conn->prepare("
    SELECT COUNT(*) as count 
    FROM assignment_submissions 
    WHERE booking_id = ? AND status = 'submitted'
");
$checkSubmissions->bind_param("i", $booking['id']);
$checkSubmissions->execute();
$submissionCount = $checkSubmissions->get_result()->fetch_assoc()['count'];
?>

<?php if ($submissionCount > 0): ?>
    <a href="assignments.php?booking_id=<?= $booking['id'] ?>" class="btn-outline">
        <i class="bi bi-list-check"></i> View Submissions (<?= $submissionCount ?>)
    </a>
<?php endif; ?>
        </div>
    </div>
                <!-- Actions (only for pending) -->
                <?php if ($booking['status'] === 'pending'): ?>
                    <div class="info-box">
                        <div class="section-subtitle">
                            <i class="bi bi-gear"></i> Actions
                        </div>
                        <form method="POST" action="booking_requests.php">
                            <input type="hidden" name="booking_id" value="<?= $booking['id'] ?>">
                            <button type="submit" name="accept_booking" class="btn-pink" style="margin-bottom: 12px;" 
                                    onclick="return confirm('Accept this booking? The student will be notified.')">
                                <i class="bi bi-check-lg"></i> Accept Booking
                            </button>
                        </form>
                        <button class="btn-secondary" onclick="showRejectModal(<?= $booking['id'] ?>)">
                            <i class="bi bi-x-lg"></i> Reject Booking
                        </button>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Reject Modal -->
<div id="rejectModal" class="modal">
    <div class="modal-content">
        <h3><i class="bi bi-exclamation-triangle"></i> Reject Booking</h3>
        <p style="margin-bottom: 16px;">Please provide a reason for rejecting this booking:</p>
        <form method="POST" action="booking_requests.php" id="rejectForm">
            <input type="hidden" name="booking_id" id="reject_booking_id">
            <textarea name="reject_reason" placeholder="e.g., I'm not available at this time..." required style="width: 100%; padding: 12px; border: 1px solid #cbd5e1; border-radius: 12px; min-height: 100px;"></textarea>
            <div class="modal-buttons">
                <button type="button" class="btn-outline" onclick="closeRejectModal()">Cancel</button>
                <button type="submit" name="reject_booking" class="btn-secondary">Submit Rejection</button>
            </div>
        </form>
    </div>
</div>

<!-- Meeting Link Modal -->
<div id="meetingLinkModal" class="modal">
    <div class="modal-content">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
            <h3 id="meetingLinkModalTitle">Add Meeting Link</h3>
            <button onclick="closeMeetingLinkModal()" style="background: none; border: none; font-size: 24px; cursor: pointer;">&times;</button>
        </div>
        <p style="margin-bottom: 16px; font-size: 13px; color: #64748b;">Enter the meeting link for this session</p>
        <input type="hidden" id="meeting_link_booking_id">
        <input type="url" id="meeting_link_input" 
               placeholder="https://zoom.us/j/123456789 or https://meet.google.com/abc-defg-hij" 
               style="width: 100%; padding: 12px; border: 1px solid #cbd5e1; border-radius: 12px; margin-bottom: 16px;">
        <div class="modal-buttons">
            <button type="button" onclick="closeMeetingLinkModal()" class="btn-outline">Cancel</button>
            <button type="button" onclick="saveMeetingLinkFromModal()" class="btn-pink">Save Link</button>
        </div>
    </div>
</div>

<script>
function toggleDropdown() {
    const dropdown = document.getElementById('profileDropdown');
    dropdown.style.display = dropdown.style.display === 'block' ? 'none' : 'block';
}

window.addEventListener('click', function(e) {
    const dropdown = document.getElementById('profileDropdown');
    const button = document.querySelector('.profile');
    if (button && !button.contains(e.target) && dropdown && !dropdown.contains(e.target)) {
        dropdown.style.display = 'none';
    }
});

function showRejectModal(bookingId) {
    document.getElementById('reject_booking_id').value = bookingId;
    document.getElementById('rejectModal').classList.add('active');
}

function closeRejectModal() {
    document.getElementById('rejectModal').classList.remove('active');
}

function showAddLinkModal(bookingId) {
    document.getElementById('meeting_link_booking_id').value = bookingId;
    document.getElementById('meeting_link_input').value = '';
    document.getElementById('meetingLinkModalTitle').textContent = 'Add Meeting Link';
    document.getElementById('meetingLinkModal').classList.add('active');
}

function showEditLinkModal(bookingId, currentLink) {
    document.getElementById('meeting_link_booking_id').value = bookingId;
    document.getElementById('meeting_link_input').value = currentLink;
    document.getElementById('meetingLinkModalTitle').textContent = 'Edit Meeting Link';
    document.getElementById('meetingLinkModal').classList.add('active');
}

function closeMeetingLinkModal() {
    document.getElementById('meetingLinkModal').classList.remove('active');
}

function saveMeetingLinkFromModal() {
    const bookingId = document.getElementById('meeting_link_booking_id').value;
    const meetingLink = document.getElementById('meeting_link_input').value.trim();
    
    if (!meetingLink) {
        alert('Please enter a meeting link');
        return;
    }
    
    if (!meetingLink.startsWith('http://') && !meetingLink.startsWith('https://')) {
        alert('Please enter a valid URL starting with http:// or https://');
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
            alert('Meeting link saved! Student has been notified.');
            closeMeetingLinkModal();
            location.reload();
        } else {
            alert('Error: ' + data.message);
        }
    });
}

function confirmSession(bookingId, role) {
    fetch('confirm_session.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ booking_id: bookingId, role: role })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            if (data.both_confirmed) {
                alert('Both confirmed! Session completed.');
            } else {
                alert('Confirmed! Waiting for student confirmation.');
            }
            location.reload();
        } else {
            alert('Error: ' + data.message);
        }
    });
}

window.onclick = function(event) {
    const modal = document.getElementById('rejectModal');
    if (event.target === modal) closeRejectModal();
    const meetingModal = document.getElementById('meetingLinkModal');
    if (event.target === meetingModal) closeMeetingLinkModal();
}
</script>

</body>
</html>