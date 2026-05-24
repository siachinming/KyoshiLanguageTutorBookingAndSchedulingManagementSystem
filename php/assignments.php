<?php
session_start();
include 'config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$userID = $_SESSION['user_id'];
$userRole = $_SESSION['role'];
$booking_id = intval($_GET['booking_id'] ?? 0);
$assetBase = '../assets/img';

// Get user info for nav
$stmtUser = $conn->prepare("SELECT * FROM users WHERE id = ?");
$stmtUser->bind_param("i", $userID);
$stmtUser->execute();
$user = $stmtUser->get_result()->fetch_assoc();
$displayName = $user['fullname'] ?? '';
$profilePic = !empty($user['profile_pic']) ? '../uploads/profiles/' . $user['profile_pic'] : ($userRole === 'tutor' ? $assetBase . '/profile-tutor.png' : $assetBase . '/profile-student.png');

if (!$booking_id) {
    header("Location: " . ($userRole === 'tutor' ? "booking_requests.php" : "student_dashboard.php"));
    exit();
}

// Verify booking access and get student info
if ($userRole === 'tutor') {
    $stmt = $conn->prepare("
        SELECT b.*, u.fullname as student_name 
        FROM bookings b
        JOIN users u ON b.student_id = u.id
        WHERE b.id = ? AND b.tutor_id = ?
    ");
    $stmt->bind_param("ii", $booking_id, $userID);
} else {
    $stmt = $conn->prepare("
        SELECT b.*, u.fullname as student_name 
        FROM bookings b
        JOIN users u ON b.student_id = u.id
        WHERE b.id = ? AND b.student_id = ?
    ");
    $stmt->bind_param("ii", $booking_id, $userID);
}
$stmt->execute();
$booking = $stmt->get_result()->fetch_assoc();

if (!$booking) {
    header("Location: " . ($userRole === 'tutor' ? "booking_requests.php" : "student_dashboard.php"));
    exit();
}

// Get assignments
$stmt = $conn->prepare("
    SELECT a.*, 
           s.id as submission_id, s.file_name, s.submitted_at, s.grade, s.feedback,
           CASE WHEN s.id IS NOT NULL THEN 1 ELSE 0 END as submitted,
           CASE WHEN s.grade IS NOT NULL THEN 1 ELSE 0 END as graded
    FROM assignments a
    LEFT JOIN submissions s ON a.id = s.assignment_id AND s.student_id = ?
    WHERE a.booking_id = ?
    ORDER BY a.due_date ASC, a.created_at DESC
");
$stmt->bind_param("ii", $booking['student_id'], $booking_id);
$stmt->execute();
$assignments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

function e($value) { return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8'); }
function formatFileSize($bytes) {
    if (!$bytes) return '';
    if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024) return round($bytes / 1024, 1) . ' KB';
    return $bytes . ' bytes';
}

$success_msg = $_GET['success'] ?? '';
$error_msg = $_GET['error'] ?? '';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Assignments - Kyoshi</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Poppins', sans-serif;
            background: url('../assets/img/background2.png') no-repeat center top;
            background-size: cover;
            min-height: 100vh;
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
        .container { width: min(1400px, 94%); margin: auto; }
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
        }
        .brand img { width: 42px; height: 42px; object-fit: contain; }
        .brand strong { display: block; color: #1d3156; font-size: 20px; }
        .brand span { color: #496894; font-size: 11px; font-weight: 600; }
        .nav-links { display: flex; gap: 28px; align-items: center; flex-wrap: wrap; }
        .nav-links a {
            text-decoration: none;
            color: #1d3156;
            font-size: 14px;
            font-weight: 600;
            transition: 0.25s;
            padding: 6px 0;
        }
        .nav-links a:hover, .nav-links a.active { color: #496894; }
        .nav-links a.active { border-bottom: 2px solid #496894; }
        
        .profile {
            display: flex;
            align-items: center;
            gap: 8px;
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            padding: 6px 14px 6px 8px;
            border-radius: 40px;
            cursor: pointer;
            transition: 0.25s;
        }
        .profile:hover { background: rgba(255, 255, 255, 0.2); }
        .profile img { width: 36px; height: 36px; border-radius: 50%; object-fit: cover; }
        .profile span { font-size: 13px; font-weight: 500; color: #1d3156; }
        
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
        .dropdown a:hover { background: #f8fafc; }
        .dropdown hr { margin: 0; border-color: #ecf3f9; }
        
        .main-container {
            max-width: 1000px;
            margin: 32px auto 60px;
            padding: 0 20px;
        }
        
        .assignments-card {
            background: white;
            border-radius: 24px;
            padding: 32px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
        }
        
        h1 { font-size: 24px; color: #1d3156; margin-bottom: 8px; }
        .subtitle { color: #64748b; font-size: 14px; margin-bottom: 24px; border-bottom: 1px solid #eef2f7; padding-bottom: 16px; }
        .btn-back { display: inline-flex; align-items: center; gap: 6px; color: #64748b; text-decoration: none; margin-bottom: 20px; font-size: 13px; }
        .btn-back:hover { color: #E75A9B; }
        
        .alert { padding: 12px 16px; border-radius: 12px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; }
        .alert-success { background: #e8f5e9; color: #2e7d32; border-left: 4px solid #2e7d32; }
        .alert-error { background: #ffebee; color: #c62828; border-left: 4px solid #c62828; }
        
        .assignment-card { border: 1px solid #eef2f7; border-radius: 16px; padding: 20px; margin-bottom: 20px; background: white; transition: all 0.2s; }
        .assignment-card:hover { box-shadow: 0 4px 12px rgba(0,0,0,0.08); }
        
        .assignment-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px; flex-wrap: wrap; gap: 10px; }
        .assignment-title { font-size: 18px; font-weight: 700; color: #1d3156; }
        .assignment-points { background: #e0f2fe; color: #0284c7; padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; }
        
        .assignment-desc { color: #64748b; font-size: 13px; margin-bottom: 12px; line-height: 1.5; }
        .assignment-meta { font-size: 12px; color: #94a3b8; display: flex; gap: 20px; flex-wrap: wrap; margin-bottom: 12px; }
        
        .status-badge { display: inline-flex; align-items: center; gap: 5px; padding: 4px 12px; border-radius: 20px; font-size: 11px; font-weight: 600; }
        .status-submitted { background: #d4edda; color: #28a745; }
        .status-pending { background: #fff3e0; color: #e67e22; }
        .status-graded { background: #dbeafe; color: #3b82f6; }
        .status-overdue { background: #fee2e2; color: #dc2626; }
        
        .btn-primary, .btn-outline {
            padding: 8px 20px; border-radius: 30px; font-size: 13px; font-weight: 600; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; cursor: pointer; border: none;
        }
        .btn-primary { background: linear-gradient(135deg, #E75A9B, #F28AB2); color: white; }
        .btn-outline { background: #e2e8f0; color: #1d3156; }
        .btn-primary:hover, .btn-outline:hover { transform: translateY(-1px); }
        
        .button-group { display: flex; gap: 10px; margin-top: 15px; flex-wrap: wrap; }
        .create-btn { margin-bottom: 20px; display: inline-block; }
        
        .empty-state { text-align: center; padding: 60px; color: #94a3b8; }
        .empty-state i { font-size: 64px; margin-bottom: 16px; display: block; }
        
        @media (max-width: 768px) {
            .main-container { padding: 0 16px; }
            .assignments-card { padding: 20px; }
            .nav { flex-wrap: wrap; }
            .nav-links { order: 3; width: 100%; justify-content: center; padding-bottom: 10px; }
        }
    </style>
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
    </script>
</head>
<body>

<!-- TOPBAR -->
<div class="topbar">
    <div class="container">
        <nav class="nav">
            <a href="<?= $userRole === 'tutor' ? 'tutor_dashboard.php' : 'student_dashboard.php' ?>" class="brand">
                <img src="<?= e($assetBase) ?>/logo.png" alt="Kyoshi">
                <div>
                    <strong>Kyoshi</strong>
                    <span><?= $userRole === 'tutor' ? 'Teacher Space' : 'Student Space' ?></span>
                </div>
            </a>
            <div class="nav-links">
                <a href="<?= $userRole === 'tutor' ? 'tutor_dashboard.php' : 'student_dashboard.php' ?>">Dashboard</a>
                <?php if ($userRole === 'tutor'): ?>
                    <a href="availability.php">Availability</a>
                    <a href="booking_requests.php">Requests</a>
                <?php else: ?>
                    <a href="find_language.php">Find Tutor</a>
                    <a href="my_bookings.php">My Bookings</a>
                <?php endif; ?>
                <a href="learning_materials.php?booking_id=<?= $booking_id ?>">Materials</a>
                <a href="assignments.php?booking_id=<?= $booking_id ?>" class="active">Homework</a>
                <a href="<?= $userRole === 'tutor' ? 'earnings.php' : 'my_progress.php' ?>"><?= $userRole === 'tutor' ? 'Earnings' : 'Progress' ?></a>
            </div>
            <div style="position:relative;">
                <button class="profile" onclick="toggleDropdown()">
                    <img src="<?= e($profilePic) ?>" alt="Profile">
                    <span><?= e($displayName) ?></span>
                    <i class="bi bi-chevron-down"></i>
                </button>
                <div class="dropdown" id="profileDropdown">
                    <a href="<?= $userRole === 'tutor' ? 'tutor_profile.php' : 'student_profile.php' ?>"><i class="bi bi-person-circle"></i> My Profile</a>
                    <?php if ($userRole === 'tutor'): ?>
                        <a href="earnings.php"><i class="bi bi-wallet2"></i> My Earnings</a>
                    <?php else: ?>
                        <a href="my_bookings.php"><i class="bi bi-calendar"></i> My Bookings</a>
                    <?php endif; ?>
                    <hr>
                    <a href="logout.php" style="color:#dc2626;"><i class="bi bi-box-arrow-right"></i> Logout</a>
                </div>
            </div>
        </nav>
    </div>
</div>

<!-- MAIN CONTENT -->
<div class="main-container">
    <div class="assignments-card">
        <a href="<?= $userRole === 'tutor' ? 'tutor_booking_detail.php?id=' . $booking_id : 'student_booking_detail.php?id=' . $booking_id ?>" class="btn-back">
            <i class="bi bi-arrow-left"></i> Back to Booking
        </a>
        
        <h1><i class="bi bi-journal-bookmark-fill"></i> Homework & Assignments</h1>
        <p class="subtitle">For: <?= e($booking['student_name'] ?? 'Student') ?> · <?= e($booking['language'] ?? 'Language') ?> session</p>
        
        <?php if ($success_msg): ?>
            <div class="alert alert-success"><i class="bi bi-check-circle-fill"></i><span><?= e(urldecode($success_msg)) ?></span></div>
        <?php endif; ?>
        <?php if ($error_msg): ?>
            <div class="alert alert-error"><i class="bi bi-exclamation-triangle-fill"></i><span><?= e(urldecode($error_msg)) ?></span></div>
        <?php endif; ?>
        
        <?php if ($userRole === 'tutor'): ?>
            <a href="create_assignment.php?booking_id=<?= $booking_id ?>" class="btn-primary create-btn">
                <i class="bi bi-plus-circle"></i> Create Assignment
            </a>
        <?php endif; ?>
        
        <?php if (empty($assignments)): ?>
            <div class="empty-state">
                <i class="bi bi-inbox"></i>
                <p>No assignments yet.</p>
                <?php if ($userRole === 'tutor'): ?>
                    <p style="font-size:12px;">Click "Create Assignment" to give homework to your student.</p>
                <?php else: ?>
                    <p style="font-size:12px;">Your tutor hasn't assigned any homework yet.</p>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <?php foreach ($assignments as $assignment): 
                $is_overdue = !empty($assignment['due_date']) && strtotime($assignment['due_date']) < time() && !$assignment['submitted'];
                $status_class = $assignment['graded'] ? 'status-graded' : ($assignment['submitted'] ? 'status-submitted' : ($is_overdue ? 'status-overdue' : 'status-pending'));
                $status_text = $assignment['graded'] ? 'Graded' : ($assignment['submitted'] ? 'Submitted' : ($is_overdue ? 'Overdue' : 'Pending'));
            ?>
            <div class="assignment-card">
                <div class="assignment-header">
                    <div class="assignment-title"><?= e($assignment['title']) ?></div>
                    <div class="assignment-points"><?= $assignment['total_points'] ?> pts</div>
                </div>
                
                <?php if (!empty($assignment['description'])): ?>
                    <div class="assignment-desc"><?= nl2br(e($assignment['description'])) ?></div>
                <?php endif; ?>
                
                <div class="assignment-meta">
                    <?php if (!empty($assignment['due_date'])): ?>
                        <span><i class="bi bi-calendar"></i> Due: <?= date('d M Y, g:i A', strtotime($assignment['due_date'])) ?></span>
                    <?php endif; ?>
                    <span><i class="bi bi-clock"></i> Created: <?= date('d M Y', strtotime($assignment['created_at'])) ?></span>
                    <span class="status-badge <?= $status_class ?>"><?= $status_text ?></span>
                </div>
                
                <?php if ($assignment['submitted']): ?>
                    <div class="assignment-meta" style="background:#f8fafc; padding:10px; border-radius:12px;">
                        <span><i class="bi bi-file-earmark"></i> Submitted: <?= date('d M Y, g:i A', strtotime($assignment['submitted_at'])) ?></span>
                        <?php if ($assignment['grade'] !== null): ?>
                            <span><i class="bi bi-star-fill" style="color:#f59e0b;"></i> Grade: <?= $assignment['grade'] ?> / <?= $assignment['total_points'] ?></span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                
                <div class="button-group">
                    <?php if ($userRole === 'tutor'): ?>
                        <?php if ($assignment['submitted']): ?>
                            <a href="grade_assignment.php?id=<?= $assignment['id'] ?>&booking_id=<?= $booking_id ?>" class="btn-primary">
                                <i class="bi bi-pencil"></i> Grade / Feedback
                            </a>
                            <a href="view_submission.php?id=<?= $assignment['submission_id'] ?>&booking_id=<?= $booking_id ?>" class="btn-outline">
                                <i class="bi bi-eye"></i> View Submission
                            </a>
                        <?php else: ?>
                            <span class="btn-outline" style="opacity:0.5; cursor:not-allowed;">
                                <i class="bi bi-clock"></i> Waiting for Submission
                            </span>
                        <?php endif; ?>
                    <?php else: ?>
                        <?php if ($assignment['submitted']): ?>
                            <a href="view_submission.php?id=<?= $assignment['submission_id'] ?>&booking_id=<?= $booking_id ?>" class="btn-outline">
                                <i class="bi bi-eye"></i> View My Submission
                            </a>
                            <?php if ($assignment['grade'] !== null): ?>
                                <span class="btn-primary" style="background:#e2e8f0; color:#1d3156;">
                                    <i class="bi bi-trophy"></i> Grade: <?= $assignment['grade'] ?> / <?= $assignment['total_points'] ?>
                                </span>
                            <?php endif; ?>
                        <?php else: ?>
                            <a href="submit_assignment.php?id=<?= $assignment['id'] ?>&booking_id=<?= $booking_id ?>" class="btn-primary">
                                <i class="bi bi-upload"></i> Submit Assignment
                            </a>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

</body>
</html>