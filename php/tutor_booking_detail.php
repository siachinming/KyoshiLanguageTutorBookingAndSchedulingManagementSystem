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

$isCancelled = ($booking['status'] === 'cancelled');
$isCompleted = ($booking['status'] === 'completed');
$isConfirmed = ($booking['status'] === 'confirmed');
$isActive = ($isConfirmed && !$isCancelled && !$isCompleted);
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
$completionStmt = $conn->prepare("SELECT * FROM session_completion WHERE booking_id = ?");
$completionStmt->bind_param("i", $booking_id);
$completionStmt->execute();
$completion = $completionStmt->get_result()->fetch_assoc();

$disputeCheck = $conn->prepare("
    SELECT id, issue_type, status, message 
    FROM disputes 
    WHERE booking_id = ? AND status = 'pending'
    LIMIT 1
");

$disputeCheck->bind_param("i", $booking_id);
$disputeCheck->execute();
$pendingReport = $disputeCheck->get_result()->fetch_assoc();

$is_disputed = ($completion['status'] ?? '') === 'disputed';
$has_pending_report = ($pendingReport !== null);
$show_proof_upload = ($is_disputed || $has_pending_report);

$tutor_confirmed = $completion['tutor_confirmed'] ?? 0;
$student_confirmed = $completion['student_confirmed'] ?? 0;
$both_confirmed = ($tutor_confirmed && $student_confirmed);
$no_show_type = $completion['no_show_type'] ?? null;

$class_time = strtotime($booking['booking_date'] . ' ' . $booking['booking_time']);
$current_time = time();
$is_past_class = $class_time < $current_time;
$hours_passed = round(($current_time - $class_time) / 3600, 1);

$show_completion_section = (
    $isActive && 
    $booking['status'] === 'confirmed' && 
    !$both_confirmed &&
    !$isCompleted &&
    !$isCancelled &&
    $is_past_class  // Only show if session time has passed, regardless of dispute
);
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

.toast {
    position: fixed;
    bottom: 30px;
    left: 50%;
    transform: translateX(-50%);
    background: #8E3F70;
    color: white;
    padding: 12px 24px;
    border-radius: 30px;
    font-size: 14px;
    font-weight: 600;
    z-index: 9999;
    opacity: 0;
    transition: opacity 0.3s ease;
    pointer-events: none;
}

.toast.show {
    opacity: 1;
}

.toast.success {
    background: #28a745;
}

.toast.error {
    background: #dc2626;
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

    <!-- Show Cancel Reason if cancelled -->
<?php if ($isCancelled && !empty($booking['cancel_reason'])): ?>
    <div style="background: #fee2e2; border-left: 4px solid #dc2626; padding: 15px; border-radius: 12px; margin-bottom: 20px;">
        <h4 style="color: #dc2626; margin: 0 0 8px 0;">
            <i class="bi bi-exclamation-triangle-fill"></i> Booking Cancelled
        </h4>
        <p style="margin: 0;"><strong>Reason: </strong> <?= e($booking['cancel_reason']) ?></p>
        <?php if (!empty($booking['cancelled_by'])): ?>
            <p style="margin: 5px 0 0; font-size: 12px;">Cancelled by <?= e($booking['cancelled_by']) === 'tutor' ? 'You' : 'Student' ?></p>
        <?php endif; ?>
    </div>
<?php endif; ?>

<!-- Show Completed badge if completed -->
<?php if ($isCompleted): ?>
    <div style="background: #d4edda; border-left: 4px solid #28a745; padding: 15px; border-radius: 12px; margin-bottom: 20px;">
        <h4 style="color: #28a745; margin: 0;">
            <i class="bi bi-check2-circle"></i> Session Completed
        </h4>
        <p style="margin: 5px 0 0;">This session has been marked as completed.</p>
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

                            <?php if ($isCancelled): ?>
            <span class="payment-badge" style="background:#fee2e2; color:#dc2626;">
                <i class="bi bi-x-circle"></i> Cancelled - No Payment
            </span>
        <?php elseif ($payment && $payment['status'] === 'verified'): ?>
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

<!-- DISPUTE WARNING BOX -->
<?php if ($has_pending_report): ?>
<div class="warning-box" style="background: #fff3cd; border-left: 4px solid #f59e0b; margin-bottom: 20px;">
    <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 8px;">
        <i class="bi bi-exclamation-triangle-fill" style="color: #f59e0b;"></i>
        <strong style="color: #856404;">Student Reported an Issue</strong>
    </div>
    <p style="font-size: 13px; margin: 0; color: #856404;">
        <strong>Issue Type:</strong> 
        <?php 
        $issue_labels = [
            'tutor_no_show' => 'Tutor Did Not Attend',
            'student_no_show' => 'Student Did Not Attend',
            'technical_issues' => 'Technical Issues',
            'wrong_materials' => 'Wrong Materials Provided',
            'other' => 'Other Issue'
        ];
        echo $issue_labels[$pendingReport['issue_type']] ?? ucfirst(str_replace('_', ' ', $pendingReport['issue_type']));
        ?>
    </p>
    <?php if (!empty($pendingReport['message'])): ?>
        <p style="font-size: 12px; margin: 5px 0 0; color: #856404;">
            <strong>Student's message:</strong> <?= e($pendingReport['message']) ?>
        </p>
    <?php endif; ?>
    <p style="font-size: 12px; margin: 8px 0 0; color: #856404;">
        <?php if ($is_past_class): ?>
            Please confirm attendance and upload proof if necessary to resolve this dispute.
        <?php else: ?>
            The session will end on <?= date('d M Y, g:i A', $class_time) ?>. After the session ends, you can confirm attendance to resolve this dispute.
        <?php endif; ?>
    </p>
    
    <!-- RESOLVE BUTTON FOR ALL MINOR ISSUES (wrong_materials, technical_issues, other) -->
    <?php if (!in_array($pendingReport['issue_type'], ['tutor_no_show', 'student_no_show', 'harassment', 'fraud']) && !$is_past_class): ?>
    <div style="margin-top: 15px; padding-top: 10px; border-top: 1px solid rgba(245, 158, 11, 0.3);">
        <p style="font-size: 12px; margin-bottom: 8px; color: #856404;">
            <i class="bi bi-info-circle"></i> 
            <?php if ($pendingReport['issue_type'] === 'wrong_materials'): ?>
                <strong>Solution:</strong> Upload the correct materials below, then click "Mark as Resolved" to close this dispute.
            <?php elseif ($pendingReport['issue_type'] === 'technical_issues'): ?>
                <strong>Solution:</strong> Help the student resolve the technical issue, then click "Mark as Resolved".
            <?php else: ?>
                <strong>Solution:</strong> Discuss with the student to resolve the issue, then click "Mark as Resolved".
            <?php endif; ?>
        </p>
        <button onclick="markDisputeResolved(<?= $pendingReport['id'] ?>, <?= $booking_id ?>)" 
        class="btn-pink" style="background: #28a745; margin-top: 5px;"
        id="resolveBtn-<?= $pendingReport['id'] ?>">
    <i class="bi bi-check-circle"></i> Mark as Resolved
</button>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>
                <?php if ($isActive): ?>
                <!-- Meeting Link or Location -->
                                <!-- Meeting Link or Location -->
                <?php if ($booking['learning_mode'] === 'online'): ?>
                    <!-- ONLINE SESSION SECTION -->
                    <div class="info-box">
                        <div class="section-subtitle">
                            <i class="bi bi-camera-video"></i> Online Session
                        </div>
                        
                        <?php if (!empty($booking['meeting_link'])): ?>
                            <div class="light-bg" style="margin-bottom: 12px;">
                                <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 8px;">
                                    <i class="bi bi-link-45deg"></i>
                                    <span style="font-size: 13px; font-weight: 600;">Meeting Link:</span>
                                </div>
                                <a href="<?= e($booking['meeting_link']) ?>" target="_blank" style="color: #E75A9B; word-break: break-all; font-size: 13px;">
                                    <?= e($booking['meeting_link']) ?>
                                </a>
                            </div>
                            
                            <div class="button-group">
                                <a href="join_meeting.php?booking_id=<?= $booking_id ?>&link=<?= urlencode($booking['meeting_link']) ?>" target="_blank" class="btn-pink">
                                    <i class="bi bi-camera-video-fill"></i> Join Meeting
                                </a>
                                <button onclick="showEditLinkModal(<?= $booking['id'] ?>, '<?= e($booking['meeting_link']) ?>')" class="btn-outline">
                                    <i class="bi bi-pencil"></i> Edit Link
                                </button>
                            </div>
                            
                           <!-- Meeting Activity -->
<div style="margin-top: 16px;">
    <strong style="font-size: 13px;"><i class="bi bi-clock-history"></i> Meeting Activity</strong>
    <div style="background: #f8fafc; border-radius: 12px; padding: 12px; margin-top: 8px;">
        <?php
        $logsStmt = $conn->prepare("SELECT * FROM meeting_logs WHERE booking_id = ? ORDER BY join_time DESC");
        $logsStmt->bind_param("i", $booking_id);
        $logsStmt->execute();
        $logs = $logsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        
        if (!empty($logs)):
        ?>
            <?php foreach ($logs as $log): ?>
            <div style="font-size: 12px; padding: 6px 0; border-bottom: 1px solid #eef2f7;">
                <i class="bi bi-person-circle"></i> <strong><?= ucfirst(e($log['participant_role'])) ?></strong>
                joined: <?= date('d M Y, g:i A', strtotime($log['join_time'])) ?>
                <?php if ($log['leave_time']): ?>
                    - left: <?= date('g:i A', strtotime($log['leave_time'])) ?>
                    <span style="color: #28a745;">(<?= $log['duration_minutes'] ?> min)</span>
                <?php else: ?>
                    <span style="color: #f59e0b;">(Active)</span>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        <?php else: ?>
            <p style="font-size: 12px; color: #64748b; margin: 0; text-align: center;">
                <i class="bi bi-info-circle"></i> No meeting activity recorded yet.
                <?php if (!$is_session_past): ?>
                    <br><small>Activity will appear after the session starts.</small>
                <?php endif; ?>
            </p>
        <?php endif; ?>
    </div>
</div>
                            
                            <!-- End Session Button -->
<div style="margin-top: 16px;">
    <strong style="font-size: 13px;"><i class="bi bi-door-closed"></i> End Session</strong>
    <div style="background: #f8fafc; border-radius: 12px; padding: 12px; margin-top: 8px;">
        <p style="font-size: 12px; color: #64748b; margin-bottom: 10px;">
            After finishing your session, click below to record your leave time.
        </p>
        
        <?php
        // Check if session time has passed (using the actual booking time)
        $session_time = strtotime($booking['booking_date'] . ' ' . $booking['booking_time']);
        $is_session_past = (time() > $session_time);
        
        // Check if session is completed
        $is_session_completed = ($booking['status'] === 'completed');
        
        if ($is_session_past && !$is_session_completed):
        ?>
        <div style="background: #fff3cd; border: 1px solid #ffc107; border-radius: 10px; padding: 8px 12px; margin-bottom: 10px;">
            <i class="bi bi-exclamation-triangle-fill" style="color: #856404;"></i>
            <span style="font-size: 11px; color: #856404;">
                This session has ended. Please record your leave time.
            </span>
        </div>
        <?php endif; ?>
        
        <?php
        // ONLY show the End Session button if:
        // 1. Session time has passed
        // 2. AND there's an active session (someone joined)
        // 3. AND session is not already completed
        $hasActiveSession = false;
        if ($is_session_past && !$is_session_completed) {
            $activeCheck = $conn->prepare("SELECT id FROM meeting_logs WHERE booking_id = ? AND leave_time IS NULL LIMIT 1");
            $activeCheck->bind_param("i", $booking_id);
            $activeCheck->execute();
            $hasActiveSession = $activeCheck->get_result()->num_rows > 0;
        }
        
        if ($is_session_past && $hasActiveSession && !$is_session_completed):
        ?>
        <button onclick="recordMeetingLeave(<?= $booking_id ?>)" class="btn-outline" style="width: auto; padding: 8px 20px; background: #dc3545; color: white; border: none; border-radius: 30px; cursor: pointer;">
            <i class="bi bi-box-arrow-right"></i> End Session & Record Leave
        </button>
        <?php elseif ($is_session_past && !$is_session_completed): ?>
        <button disabled style="width: auto; padding: 8px 20px; background: #6c757d; color: white; border: none; border-radius: 30px; opacity: 0.6;">
            <i class="bi bi-clock"></i> Waiting for meeting activity...
        </button>
        <?php elseif (!$is_session_past): ?>
        <button disabled style="width: auto; padding: 8px 20px; background: #6c757d; color: white; border: none; border-radius: 30px; opacity: 0.6;">
            <i class="bi bi-calendar-clock"></i> Available after session ends (<?= date('d M Y, g:i A', $session_time) ?>)
        </button>
        <?php else: ?>
        <button disabled style="width: auto; padding: 8px 20px; background: #6c757d; color: white; border: none; border-radius: 30px; opacity: 0.6;">
            <i class="bi bi-check-circle"></i> Session already completed
        </button>
        <?php endif; ?>
    </div>
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
                    <!-- FACE-TO-FACE SESSION SECTION -->
                    <div class="info-box">
                        <div class="section-subtitle">
                            <i class="bi bi-geo-alt"></i> Face-to-Face Session
                        </div>
                        
                        <!-- Meeting Location -->
                        <div style="margin-bottom: 16px;">
                            <strong style="font-size: 13px;"><i class="bi bi-geo-alt"></i> Meeting Location</strong>
                            <div style="background: #f8fafc; border-radius: 12px; padding: 12px; margin-top: 8px;">
                                <?php if (!empty($booking['meeting_location'])): ?>
                                    <p style="margin: 0 0 10px 0;"><?= nl2br(e($booking['meeting_location'])) ?></p>
                                    <a href="https://www.google.com/maps/search/?api=1&query=<?= urlencode($booking['meeting_location']) ?>" 
                                       target="_blank" class="btn-outline" style="width: auto; padding: 6px 16px;">
                                        <i class="bi bi-map"></i> Open in Maps
                                    </a>
                                <?php else: ?>
                                    <p style="color: #f59e0b; margin: 0;">No location provided. Please contact the student.</p>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <!-- Attendance Proof Upload -->
                        <div>
                            <strong style="font-size: 13px;"><i class="bi bi-camera"></i> Attendance Proof</strong>
                            <div style="background: #f8fafc; border-radius: 12px; padding: 12px; margin-top: 8px;">
                                <p style="font-size: 12px; color: #64748b; margin-bottom: 12px;">
                                    Take a photo at the meeting location as proof of attendance. This helps resolve any disputes.
                                </p>
                                
                                <?php
                                // Check existing proofs
                                $proofStmt = $conn->prepare("SELECT * FROM attendance_proofs WHERE booking_id = ? AND user_id = ? ORDER BY uploaded_at DESC");
                                $proofStmt->bind_param("ii", $booking_id, $userID);
                                $proofStmt->execute();
                                $proofs = $proofStmt->get_result()->fetch_all(MYSQLI_ASSOC);
                                ?>
                                
                                <form id="proofUploadForm" enctype="multipart/form-data" style="margin-bottom: 12px;">
                                    <input type="hidden" name="booking_id" value="<?= $booking_id ?>">
                                    <input type="hidden" name="action" value="upload_proof">
                                    <input type="hidden" name="proof_type" value="photo">
                                    <input type="file" name="proof" accept="image/*" required style="margin-bottom: 10px; width: 100%;">
                                    <button type="submit" class="btn-outline" style="width: auto; padding: 8px 20px;">
                                        <i class="bi bi-upload"></i> Upload Proof Photo
                                    </button>
                                </form>
                                
                                <?php if (!empty($proofs)): ?>
                                    <div style="margin-top: 12px;">
                                        <strong style="font-size: 12px;">Uploaded Proofs:</strong>
                                        <?php foreach ($proofs as $proof): ?>
                                        <div style="margin-top: 8px; padding: 8px; background: #e8f5e9; border-radius: 8px;">
                                            <a href="../uploads/proofs/<?= e($proof['file_path']) ?>" target="_blank" style="color: #E75A9B; font-size: 12px;">
                                                <i class="bi bi-image"></i> View Proof (<?= date('d M Y, g:i A', strtotime($proof['uploaded_at'])) ?>)
                                            </a>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
                
                <?php elseif ($isCancelled): ?>
                <div class="info-box">
                    <div class="section-subtitle">
                        <i class="bi bi-info-circle"></i> Note
                    </div>
                    <p style="color: #64748b; font-size: 13px;">This booking has been cancelled. <br>No further actions available.</p>
                </div>
            <?php endif; ?>
<?php if ($show_completion_section): ?>
<div class="info-box">
    <div class="section-subtitle">
        <i class="bi bi-check2-circle"></i> Session Completion
    </div>
    
    <?php if ($booking['learning_mode'] === 'online'): ?>
    <!-- ONLINE SESSION -->
    <div class="light-bg" style="margin-bottom: 12px;">
        <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 8px;">
            <?php if ($tutor_confirmed): ?>
                <i class="bi bi-check-circle-fill" style="color: #28a745;"></i>
                <span>You have confirmed this session</span>
            <?php else: ?>
                <i class="bi bi-clock-history" style="color: #f59e0b;"></i>
                <span>Confirm if the session took place</span>
            <?php endif; ?>
            
            <?php if ($is_disputed || $has_pending_report): ?>
                <span style="background: #fef3c7; color: #f59e0b; padding: 2px 8px; border-radius: 12px; font-size: 11px; margin-left: 8px;">
                    <i class="bi bi-exclamation-triangle-fill"></i> Disputed
                </span>
            <?php endif; ?>
        </div>
        
        <?php if (!$tutor_confirmed): ?>
            <!-- Show proof upload ONLY if disputed or has pending report -->
            <?php if ($show_proof_upload): ?>
                <div style="margin-bottom: 12px; background: #fff3cd; padding: 12px; border-radius: 8px;">
                    <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 8px;">
                        <i class="bi bi-info-circle-fill" style="color: #f59e0b;"></i>
                        <span style="font-size: 12px; font-weight: bold;">Student has reported an issue. Please provide proof to support your confirmation.</span>
                    </div>
                    <label style="font-size: 12px; display: block; margin-bottom: 5px;">Upload Screenshot as Proof:</label>
                    <input type="file" id="onlineProof_<?= $booking_id ?>" accept="image/*" style="margin-bottom: 8px; width: 100%;">
                    <small style="font-size: 11px; color: #666;">Upload a screenshot showing you were present (e.g., meeting room, chat, shared screen)</small>
                </div>
            <?php endif; ?>
            
            <button onclick="confirmOnlineSession(<?= $booking_id ?>, 'attended')" class="btn-pink" style="margin-top: 8px;">
                <i class="bi bi-check-lg"></i> Confirm Session Happened
            </button>
        <?php endif; ?>
    </div>
    
    <?php else: ?>
    <!-- FACE-TO-FACE SESSION -->
    <div class="light-bg" style="margin-bottom: 12px;">
        <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 12px;">
            <i class="bi bi-geo-alt-fill" style="color: #E75A9B;"></i>
            <span><strong>Face-to-Face Session</strong> - Please confirm attendance</span>
        </div>
        
        <?php if (!$tutor_confirmed): ?>
            <div style="display: flex; gap: 10px; flex-direction: column;">
                <button onclick="confirmFaceToFace(<?= $booking_id ?>, 'attended')" class="btn-pink">
                    <i class="bi bi-check-circle-fill"></i> Student Attended (Mark as Completed)
                </button>
                <button onclick="confirmFaceToFace(<?= $booking_id ?>, 'student_no_show')" class="btn-secondary" style="background: #f59e0b;">
                    <i class="bi bi-x-circle-fill"></i> Student Did NOT Attend (No Refund)
                </button>
                <button onclick="confirmFaceToFace(<?= $booking_id ?>, 'tutor_no_show')" class="btn-secondary" style="background: #dc2626;">
                    <i class="bi bi-cash-stack"></i> I Did NOT Attend (Refund Student)
                </button>
            </div>
        <?php else: ?>
            <div style="display: flex; align-items: center; gap: 8px;">
                <i class="bi bi-check-circle-fill" style="color: #28a745;"></i>
                <span>
                    You confirmed: 
                    <?php if ($no_show_type == 'student_no_show'): ?>
                        Student did NOT attend
                    <?php elseif ($no_show_type == 'tutor_no_show'): ?>
                        You did NOT attend - Refund processing
                    <?php else: ?>
                        Student attended
                    <?php endif; ?>
                </span>
            </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    
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
        <i class="bi bi-check-circle-fill" style="color: #28a745;"></i>
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
<?php if ($isActive): ?>
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
                <?php endif; ?>

                
                <?php if ($isActive): ?>
                <div class="info-box">
        <div class="section-subtitle">
            <i class="bi bi-journal-bookmark-fill"></i> Homework & Assignments
        </div>
        <div class="button-group">
            <a href="create_assignment.php?booking_id=<?= $booking['id'] ?>" class="btn-pink">
                <i class="bi bi-plus-circle"></i> Create Assignment
            </a>
            <?php endif; ?>
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
              <!-- Session Report (for completed bookings) -->
<?php if ($booking['status'] === 'completed'): ?>
    <div class="info-box">
        <div class="section-subtitle">
            <i class="bi bi-journal-bookmark-fill"></i> Session Report
        </div>
        <div class="button-group">
            <?php
            // Check if a report already exists
            $checkReport = $conn->prepare("SELECT id, report_status FROM session_reports WHERE booking_id = ? ORDER BY created_at DESC LIMIT 1");
            $checkReport->bind_param("i", $booking_id);
            $checkReport->execute();
            $existingReport = $checkReport->get_result()->fetch_assoc();
            ?>
            <?php if ($existingReport && $existingReport['report_status'] === 'submitted'): ?>
                <a href="view_session_reports.php" class="btn-outline">
                    <i class="bi bi-eye"></i> View All Reports
                </a>
                <a href="submit_session_report.php?booking_id=<?= $booking['id'] ?>" class="btn-pink">
                    <i class="bi bi-pencil-square"></i> Edit Report
                </a>
            <?php else: ?>
                <a href="submit_session_report.php?booking_id=<?= $booking['id'] ?>" class="btn-pink">
                    <i class="bi bi-pencil-square"></i> Submit Session Report
                </a>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>
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

function recordMeetingLeave(bookingId) {
    if (confirm('Record that you have left the session? Your attendance duration will be calculated.')) {
        fetch('record_meeting_leave.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ booking_id: bookingId })
        })
        .then(response => response.json())
        .then(data => {
            showToast(data.message, data.success ? 'success' : 'error');
            if (data.success) {
                setTimeout(() => location.reload(), 1500);
            }
        });
    }
}

// Proof upload handler for face-to-face sessions
const proofForm = document.getElementById('proofUploadForm');
if (proofForm) {
    proofForm.addEventListener('submit', function(e) {
        e.preventDefault();
        let formData = new FormData(this);
        
        let submitBtn = this.querySelector('button[type="submit"]');
        let originalText = submitBtn.innerHTML;
        submitBtn.innerHTML = '<i class="bi bi-hourglass-split"></i> Uploading...';
        submitBtn.disabled = true;
        
        fetch('upload_proof.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            showToast(data.message, data.success ? 'success' : 'error');
            if (data.success) {
                setTimeout(() => location.reload(), 1500);
            }
            submitBtn.innerHTML = originalText;
            submitBtn.disabled = false;
        })
        .catch(error => {
            showToast('Upload failed', 'error');
            submitBtn.innerHTML = originalText;
            submitBtn.disabled = false;
        });
    });
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
        showToast('Please enter a meeting link', 'error');
        return;
    }
    
    if (!meetingLink.startsWith('http://') && !meetingLink.startsWith('https://')) {
        showToast('Please enter a valid URL starting with http:// or https://', 'error');
        return;
    }
    
    const saveBtn = document.querySelector('#meetingLinkModal .btn-pink');
    const originalText = saveBtn.innerHTML;
    saveBtn.innerHTML = '<i class="bi bi-hourglass-split"></i> Saving...';
    saveBtn.disabled = true;
    
    fetch('save_meeting_link.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ booking_id: bookingId, meeting_link: meetingLink })
    })
    .then(response => response.text())  // Get as text first to debug
    .then(text => {
        console.log("Raw response:", text);  // See what the server returns
        try {
            const data = JSON.parse(text);
            if (data.success) {
                showToast(data.message || 'Meeting link saved!', 'success');
                closeMeetingLinkModal();
                setTimeout(() => location.reload(), 1500);
            } else {
                showToast('Error: ' + data.message, 'error');
                saveBtn.innerHTML = originalText;
                saveBtn.disabled = false;
            }
        } catch (e) {
            console.error("JSON parse error:", e);
            showToast('Server error. Please try again.', 'error');
            saveBtn.innerHTML = originalText;
            saveBtn.disabled = false;
        }
    })
    .catch(error => {
        console.error("Fetch error:", error);
        showToast('Network error. Please try again.', 'error');
        saveBtn.innerHTML = originalText;
        saveBtn.disabled = false;
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

function confirmOnlineSession(bookingId, action) {
    // First check if session time has passed
    fetch('check_session_time.php?booking_id=' + bookingId)
        .then(response => response.json())
        .then(data => {
            if (!data.can_complete) {
                showToast('Session time has not passed yet. Please wait until after the session time to confirm.', 'error');
                return;
            }
            
            // Check if we need to upload proof (if dispute exists)
            const proofInput = document.getElementById('onlineProof_' + bookingId);
            const hasProof = proofInput && proofInput.files && proofInput.files.length > 0;
            
            if (hasProof) {
                // Upload proof first, then confirm
                const formData = new FormData();
                formData.append('booking_id', bookingId);
                formData.append('proof', proofInput.files[0]);
                formData.append('action', 'upload_proof');
                
                fetch('upload_online_proof.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // After proof uploaded, confirm the session
                        submitOnlineConfirmation(bookingId, action);
                    } else {
                        showToast('Failed to upload proof. Please try again.', 'error');
                    }
                })
                .catch(error => {
                    showToast('Error uploading proof', 'error');
                });
            } else {
                // No proof, just confirm
                submitOnlineConfirmation(bookingId, action);
            }
        })
        .catch(error => {
            showToast('Error checking session time', 'error');
        });
}

function confirmFaceToFace(bookingId, action) {
    // First check if session time has passed
    fetch('check_session_time.php?booking_id=' + bookingId)
        .then(response => response.json())
        .then(data => {
            if (!data.can_complete) {
                showToast('Session time has not passed yet. Please wait until after the session time to confirm attendance.', 'error');
                return;
            }
            
            let confirmMessage = '';
            let actionText = '';
            
            if (action === 'attended') {
                confirmMessage = 'Confirm that the student ATTENDED this face-to-face session?';
                actionText = 'face_to_face_attended';
            } else if (action === 'student_no_show') {
                confirmMessage = 'Confirm that the student did NOT attend? No refund will be issued.';
                actionText = 'student_no_show';
            } else if (action === 'tutor_no_show') {
                confirmMessage = 'Confirm that YOU did NOT attend? Student will receive a full refund.';
                actionText = 'tutor_no_show';
            }
            
            if (!confirm(confirmMessage)) {
                return;
            }
            
            fetch('confirm_attendance_api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ booking_id: bookingId, action: actionText })
            })
            .then(response => response.json())
            .then(data => {
                showToast(data.message, data.success ? 'success' : 'error');
                if (data.success) {
                    setTimeout(() => location.reload(), 1500);
                }
            })
            .catch(error => {
                showToast('Network error. Please try again.', 'error');
            });
        })
        .catch(error => {
            showToast('Error checking session time', 'error');
        });
}
function markDisputeResolved(disputeId, bookingId) {
    // Get the button that was clicked
    const btn = event.target;
    const originalText = btn.innerHTML;
    
    // Disable button and show loading state
    btn.disabled = true;
    btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Processing...';
    btn.style.opacity = '0.6';
    btn.style.cursor = 'not-allowed';
    
    if (confirm('Have you resolved this issue? The student will be notified that this issue is resolved.')) {
        fetch('resolve_dispute.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ 
                dispute_id: disputeId, 
                booking_id: bookingId,
                action: 'resolve'
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showToast('Dispute resolved! Student has been notified.', 'success');
                setTimeout(() => location.reload(), 1500);
            } else {
                showToast('Error: ' + data.message, 'error');
                // Re-enable button on error
                btn.disabled = false;
                btn.innerHTML = originalText;
                btn.style.opacity = '1';
                btn.style.cursor = 'pointer';
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showToast('Network error. Please try again.', 'error');
            // Re-enable button on error
            btn.disabled = false;
            btn.innerHTML = originalText;
            btn.style.opacity = '1';
            btn.style.cursor = 'pointer';
        });
    } else {
        // User cancelled - re-enable button
        btn.disabled = false;
        btn.innerHTML = originalText;
        btn.style.opacity = '1';
        btn.style.cursor = 'pointer';
    }
}

function submitOnlineConfirmation(bookingId, action) {
    if (!confirm('Confirm that this ONLINE session took place? The student will be notified.')) {
        return;
    }
    
    fetch('confirm_attendance_api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ booking_id: bookingId, action: 'confirm' })
    })
    .then(response => response.json())
    .then(data => {
        showToast(data.message, data.success ? 'success' : 'error');
        if (data.success) {
            setTimeout(() => location.reload(), 1500);
        }
    })
    .catch(error => {
        showToast('Network error. Please try again.', 'error');
    });
}

function showToast(message, type = 'success') {
    // Create toast element if it doesn't exist
    let toast = document.getElementById('customToast');
    if (!toast) {
        toast = document.createElement('div');
        toast.id = 'customToast';
        toast.className = 'toast';
        document.body.appendChild(toast);
    }
    
    toast.textContent = message;
    toast.className = `toast ${type}`;
    toast.classList.add('show');
    
    setTimeout(() => {
        toast.classList.remove('show');
    }, 3000);
}

window.onclick = function(event) {
    const modal = document.getElementById('rejectModal');
    if (event.target === modal) closeRejectModal();
    const meetingModal = document.getElementById('meetingLinkModal');
    if (event.target === meetingModal) closeMeetingLinkModal();
}
</script>
<div id="customToast" class="toast"></div>

</body>
</html>