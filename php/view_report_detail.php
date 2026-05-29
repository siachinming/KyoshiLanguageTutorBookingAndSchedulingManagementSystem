<?php
session_start();
include 'config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$userID = $_SESSION['user_id'];
$role = $_SESSION['role'];
$report_id = isset($_GET['report_id']) ? intval($_GET['report_id']) : 0;
$booking_id = isset($_GET['booking_id']) ? intval($_GET['booking_id']) : 0;

if (!$report_id && !$booking_id) {
    header("Location: view_session_reports.php");
    exit();
}

// Get report details
if ($report_id > 0) {
    $stmt = $conn->prepare("
        SELECT sr.*, b.language, b.booking_date, b.booking_time,
               tutor.fullname as tutor_name, tutor.email as tutor_email,
               student.fullname as student_name, student.email as student_email
        FROM session_reports sr
        JOIN bookings b ON sr.booking_id = b.id
        JOIN users tutor ON sr.tutor_id = tutor.id
        JOIN users student ON sr.student_id = student.id
        WHERE sr.id = ?
    ");
    $stmt->bind_param("i", $report_id);
} else {
    $stmt = $conn->prepare("
        SELECT sr.*, b.language, b.booking_date, b.booking_time,
               tutor.fullname as tutor_name, tutor.email as tutor_email,
               student.fullname as student_name, student.email as student_email
        FROM session_reports sr
        JOIN bookings b ON sr.booking_id = b.id
        JOIN users tutor ON sr.tutor_id = tutor.id
        JOIN users student ON sr.student_id = student.id
        WHERE sr.booking_id = ? AND sr.report_status = 'submitted'
        ORDER BY sr.created_at DESC LIMIT 1
    ");
    $stmt->bind_param("i", $booking_id);
}
$stmt->execute();
$report = $stmt->get_result()->fetch_assoc();

if (!$report) {
    header("Location: view_session_reports.php");
    exit();
}

// Check permission
if (($role === 'tutor' && $report['tutor_id'] != $userID) || 
    ($role === 'student' && $report['student_id'] != $userID)) {
    header("Location: view_session_reports.php");
    exit();
}

$assetBase = '../assets/img';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Session Report Details - Kyoshi</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        /* Add your styles here - similar to your existing styles */
        body { font-family: 'Poppins', sans-serif; background: #f0f9ff; padding: 40px; }
        .container { max-width: 900px; margin: auto; background: white; border-radius: 24px; padding: 40px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); }
        .back-btn { display: inline-flex; align-items: center; gap: 8px; color: #64748b; text-decoration: none; margin-bottom: 24px; }
        h1 { color: #1d3156; margin-bottom: 8px; }
        .report-section { margin-bottom: 24px; padding-bottom: 16px; border-bottom: 1px solid #eef2f7; }
        .report-section h3 { color: #1d3156; font-size: 16px; margin-bottom: 8px; }
        .report-section p { color: #475569; line-height: 1.6; }
        .private-note { background: #fef3c7; padding: 16px; border-radius: 12px; margin-top: 24px; }
    </style>
</head>
<body>
<div class="container">
    <a href="view_session_reports.php" class="back-btn"><i class="bi bi-arrow-left"></i> Back to Reports</a>
    
    <h1><i class="bi bi-journal-bookmark-fill"></i> Session Report</h1>
    <p style="color: #64748b; margin-bottom: 24px;">
        <?= $report['language'] ?> Session with <?= $role === 'tutor' ? $report['student_name'] : $report['tutor_name'] ?>
        · <?= date('d M Y, g:i A', strtotime($report['session_date'] . ' ' . $report['session_time'])) ?>
    </p>
    
    <div class="report-section">
        <h3><i class="bi bi-chat-text"></i> Lesson Summary</h3>
        <p><?= nl2br(htmlspecialchars($report['lesson_summary'])) ?></p>
    </div>
    
    <div class="report-section">
        <h3><i class="bi bi-graph-up"></i> Student Progress</h3>
        <p><?= nl2br(htmlspecialchars($report['student_progress'])) ?></p>
    </div>
    
    <div class="report-section">
        <h3><i class="bi bi-book"></i> Topics Covered</h3>
        <p><?= nl2br(htmlspecialchars($report['topics_covered'])) ?></p>
    </div>
    
    <?php if ($report['homework_given'] && $report['homework_given'] !== 'No homework assigned'): ?>
    <div class="report-section">
        <h3><i class="bi bi-pencil-square"></i> Homework Given</h3>
        <p><?= nl2br(htmlspecialchars($report['homework_given'])) ?></p>
    </div>
    <?php endif; ?>
    
    <?php if ($report['materials_used'] && $report['materials_used'] !== 'No materials uploaded'): ?>
    <div class="report-section">
        <h3><i class="bi bi-box"></i> Materials Used</h3>
        <p><?= nl2br(htmlspecialchars($report['materials_used'])) ?></p>
    </div>
    <?php endif; ?>
    
    <?php if ($report['next_session_focus']): ?>
    <div class="report-section">
        <h3><i class="bi bi-calendar-check"></i> Next Session Focus</h3>
        <p><?= nl2br(htmlspecialchars($report['next_session_focus'])) ?></p>
    </div>
    <?php endif; ?>
    
    <?php if ($role === 'tutor' && $report['tutor_notes']): ?>
    <div class="private-note">
        <h3><i class="bi bi-lock"></i> Private Tutor Notes (Only visible to you)</h3>
        <p><?= nl2br(htmlspecialchars($report['tutor_notes'])) ?></p>
    </div>
    <?php endif; ?>
    
    <div class="report-section">
        <h3><i class="bi bi-person-check"></i> Attendance</h3>
        <p><?= ucfirst($report['attendance_status']) ?></p>
    </div>
    
    <div style="margin-top: 32px; padding-top: 16px; border-top: 1px solid #eef2f7; font-size: 12px; color: #64748b;">
        <p>Report submitted on: <?= date('d M Y, g:i A', strtotime($report['submitted_at'])) ?></p>
    </div>
</div>
</body>
</html>