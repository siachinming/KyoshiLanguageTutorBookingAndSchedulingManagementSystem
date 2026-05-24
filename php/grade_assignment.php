<?php
session_start();
include 'config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$userID = $_SESSION['user_id'];
$userRole = $_SESSION['role'];
$submission_id = intval($_GET['id'] ?? 0);
$booking_id = intval($_GET['booking_id'] ?? 0);

if (!$submission_id || !$booking_id) {
    header("Location: " . ($userRole === 'tutor' ? "booking_requests.php" : "student_dashboard.php"));
    exit();
}

// Get submission with access check
$stmt = $conn->prepare("
    SELECT s.*, a.title, a.total_points, a.tutor_id, b.student_id, u.fullname as student_name
    FROM submissions s
    JOIN assignments a ON s.assignment_id = a.id
    JOIN bookings b ON a.booking_id = b.id
    JOIN users u ON s.student_id = u.id
    WHERE s.id = ?
");
$stmt->bind_param("i", $submission_id);
$stmt->execute();
$submission = $stmt->get_result()->fetch_assoc();

if (!$submission) {
    die("Submission not found.");
}

// Verify access
$hasAccess = false;
if ($userRole === 'tutor' && $submission['tutor_id'] == $userID) {
    $hasAccess = true;
} elseif ($userRole === 'student' && $submission['student_id'] == $userID) {
    $hasAccess = true;
}

if (!$hasAccess) {
    die("Access denied.");
}

function e($value) { return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8'); }
function formatFileSize($bytes) {
    if (!$bytes) return '';
    if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024) return round($bytes / 1024, 1) . ' KB';
    return $bytes . ' bytes';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Submission - Kyoshi</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Poppins', sans-serif; background: url('../assets/img/background2.png') no-repeat center top; background-size: cover; padding: 40px; }
        .container { max-width: 800px; margin: 0 auto; background: white; border-radius: 24px; padding: 32px; box-shadow: 0 4px 20px rgba(0,0,0,0.1); }
        h1 { font-size: 24px; color: #1d3156; }
        .subtitle { color: #64748b; font-size: 14px; margin-bottom: 24px; border-bottom: 1px solid #eef2f7; padding-bottom: 16px; }
        .btn-back { display: inline-flex; align-items: center; gap: 6px; color: #64748b; text-decoration: none; margin-bottom: 20px; font-size: 13px; }
        .info-card { background: #f8fafc; border-radius: 16px; padding: 20px; margin-bottom: 20px; }
        .info-row { display: flex; padding: 10px 0; border-bottom: 1px solid #eef2f7; }
        .info-label { width: 140px; font-weight: 600; color: #1d3156; }
        .info-value { flex: 1; color: #64748b; }
        .grade-box { background: #e0f2fe; border-radius: 12px; padding: 16px; text-align: center; margin-top: 20px; }
        .grade-number { font-size: 36px; font-weight: 700; color: #0284c7; }
        .feedback-box { background: #fefce8; padding: 16px; border-radius: 12px; margin-top: 16px; border-left: 4px solid #E75A9B; }
        .btn-download { background: #e2e8f0; color: #1d3156; padding: 10px 20px; border-radius: 30px; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; }
        @media (max-width: 768px) {
            body { padding: 20px; }
            .info-row { flex-direction: column; gap: 5px; }
            .info-label { width: 100%; }
        }
    </style>
</head>
<body>
    <div class="container">
        <a href="assignments.php?booking_id=<?= $booking_id ?>" class="btn-back">
            <i class="bi bi-arrow-left"></i> Back to Assignments
        </a>
        
        <h1><i class="bi bi-file-earmark-text"></i> Submission Details</h1>
        <p class="subtitle"><?= e($submission['title']) ?></p>
        
        <div class="info-card">
            <div class="info-row">
                <div class="info-label">Student:</div>
                <div class="info-value"><?= e($submission['student_name']) ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Submitted:</div>
                <div class="info-value"><?= date('d M Y, g:i A', strtotime($submission['submitted_at'])) ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">File:</div>
                <div class="info-value">
                    <a href="download_submission.php?id=<?= $submission_id ?>&booking_id=<?= $booking_id ?>" class="btn-download">
                        <i class="bi bi-download"></i> <?= e($submission['file_name']) ?> (<?= formatFileSize($submission['file_size']) ?>)
                    </a>
                </div>
            </div>
            <?php if (!empty($submission['comments'])): ?>
                <div class="info-row">
                    <div class="info-label">Student Comments:</div>
                    <div class="info-value"><?= nl2br(e($submission['comments'])) ?></div>
                </div>
            <?php endif; ?>
        </div>
        
        <?php if ($submission['grade'] !== null): ?>
            <div class="grade-box">
                <div class="grade-number"><?= $submission['grade'] ?> / <?= $submission['total_points'] ?></div>
                <div style="font-size: 13px; color: #64748b;">Grade</div>
            </div>
            
            <?php if (!empty($submission['feedback'])): ?>
                <div class="feedback-box">
                    <strong><i class="bi bi-chat-dots"></i> Tutor Feedback:</strong><br>
                    <?= nl2br(e($submission['feedback'])) ?>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <div class="feedback-box" style="background:#f8fafc; border-left-color:#94a3b8;">
                <i class="bi bi-hourglass"></i> Not graded yet. Check back later for feedback.
            </div>
        <?php endif; ?>
    </div>
</body>
</html>