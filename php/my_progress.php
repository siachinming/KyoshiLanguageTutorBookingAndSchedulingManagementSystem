<?php
session_start();
include 'config.php';
$assetBase = '../assets/img';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$userID = $_SESSION['user_id'];
$role = $_SESSION['role'];

// Get student info
$stmt = $conn->prepare("SELECT * FROM users WHERE id = ? AND role = 'student'");
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
    : $assetBase . '/profile-student.png';

// Get ALL completed sessions with attendance from session_completion
$stmt = $conn->prepare("
    SELECT 
        b.id as booking_id,
        b.language,
        b.booking_date,
        b.booking_time,
        b.status,
        b.learning_mode,
        u.fullname as tutor_name,
        sc.student_confirmed,
        sc.tutor_confirmed,
        sc.completed_at,
        sc.student_confirmed_at,
        sc.tutor_confirmed_at,
        sc.attendance_manually_set,
        sc.dispute_reason,
        sc.no_show_type
    FROM bookings b
    JOIN users u ON b.tutor_id = u.id
    LEFT JOIN session_completion sc ON b.id = sc.booking_id
    WHERE b.student_id = ? 
        AND b.status = 'completed'
        AND b.booking_date <= CURDATE()
    ORDER BY b.booking_date DESC
");
$stmt->bind_param("i", $userID);
$stmt->execute();
$allCompletedSessions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get session reports that have been submitted (feedback from tutors)
$stmt = $conn->prepare("
    SELECT 
        sr.*,
        b.language,
        b.booking_date,
        b.booking_time,
        u.fullname as tutor_name
    FROM session_reports sr
    JOIN bookings b ON sr.booking_id = b.id
    JOIN users u ON sr.tutor_id = u.id
    WHERE sr.student_id = ? AND sr.report_status = 'submitted'
    ORDER BY sr.session_date DESC
");
$stmt->bind_param("i", $userID);
$stmt->execute();
$sessionReports = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get upcoming sessions
$stmt = $conn->prepare("
    SELECT 
        b.id as booking_id,
        b.language,
        b.booking_date,
        b.booking_time,
        b.total_amount,
        b.learning_mode,
        u.fullname as tutor_name
    FROM bookings b
    JOIN users u ON b.tutor_id = u.id
    WHERE b.student_id = ? 
        AND b.status IN ('confirmed', 'accepted')
        AND b.booking_date >= CURDATE()
    ORDER BY b.booking_date ASC
");
$stmt->bind_param("i", $userID);
$stmt->execute();
$upcomingSessions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Calculate statistics
$totalSessions = count($allCompletedSessions);
$attendedSessions = 0;

foreach ($allCompletedSessions as $session) {
    if ($session['student_confirmed'] == 1) {
        $attendedSessions++;
    }
}

$attendanceRate = $totalSessions > 0 ? round(($attendedSessions / $totalSessions) * 100) : 0;

// Count sessions without feedback yet
$sessionsWithoutFeedback = 0;
$reportBookingIds = array_column($sessionReports, 'booking_id');
foreach ($allCompletedSessions as $session) {
    if (!in_array($session['booking_id'], $reportBookingIds)) {
        $sessionsWithoutFeedback++;
    }
}

function e($value) {
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function getAttendanceBadge($session) {
    $studentConfirmed = $session['student_confirmed'] ?? 0;
    $isManuallySet = $session['attendance_manually_set'] ?? 0;
    $noShowType = $session['no_show_type'] ?? null;
    $autoCompleted = $session['auto_completed'] ?? 0;  // Add this line
    
    if ($studentConfirmed == 1) {
        return '<span class="badge-attended"><i class="bi bi-check-circle-fill"></i> Attended</span>';
    } elseif ($studentConfirmed == 0 && $isManuallySet && $noShowType == 'student_no_show') {
        return '<span class="badge-missed"><i class="bi bi-x-circle-fill"></i> Missed (No Refund)</span>';
    } elseif ($studentConfirmed == 0 && $isManuallySet && $noShowType == 'tutor_no_show') {
        return '<span class="badge-refund"><i class="bi bi-cash-stack"></i> Refund Processing</span>';
    } elseif ($autoCompleted == 1) {
        return '<span class="badge-auto"><i class="bi bi-robot"></i> Auto-Completed</span>';
    } else {
        return '<span class="badge-pending"><i class="bi bi-clock-history"></i> Pending Confirmation</span>';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Progress · Kyoshi</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root{
            --cream:#FFF1F6; --paper:rgba(255,255,255,.88); --ink:#342635; --muted:#7B6178;
            --pink:#F28AB2; --hot-pink:#E75A9B;
            --shadow:0 18px 45px rgba(201,79,134,.16);
            --radius-xl:32px; --radius-lg:24px; --radius-md:18px;
        }
        *{box-sizing:border-box}
        body{margin:0;min-height:100vh;font-family:"Segoe UI",Arial,sans-serif;color:var(--ink);
            background:linear-gradient(120deg,rgba(255,241,246,.74),rgba(255,203,220,.30)),
                url("<?= e($assetBase) ?>/background3.jpg") center/cover fixed no-repeat;}
        a{text-decoration:none;color:inherit}
       .container{width:min(1440px,calc(100% - 40px));margin:0 auto}

     .topbar{position:sticky;top:0;z-index:50;background:rgba(255,241,246,.86);backdrop-filter:blur(20px);border-bottom:1px solid rgba(231,90,155,.18);box-shadow:0 10px 30px rgba(201,79,134,.10)}
    .nav{min-height:78px;display:grid;grid-template-columns:190px minmax(0,1fr) 360px;gap:16px;align-items:center;}
    .brand{display:flex;align-items:center;gap:10px}
    .brand img{width:44px;height:44px;object-fit:contain;border-radius:14px}
    .brand strong{display:block;font-size:18px;line-height:1.05}
    .brand span{display:block;margin-top:3px;font-size:11px;color:var(--muted);white-space:nowrap}
    .nav-links{display:flex;align-items:center;justify-content:center;gap:6px;;border-radius:999px;padding:7px;overflow:auto;scrollbar-width:none;}
    .nav-links::-webkit-scrollbar{display:none}
    .nav-links a{flex:0 0 auto;padding:9px 12px;border-radius:999px;font-size:13px;font-weight:900;color:#6D4964;white-space:nowrap;transition:.18s ease}
    .nav-links a.active,.nav-links a:hover{background:linear-gradient(135deg,var(--hot-pink),var(--pink));color:#fff;box-shadow:0 8px 18px rgba(231,90,155,.28)}
    .nav-actions{display:flex;align-items:center;gap:10px}
    .profile{display:flex;align-items:center;gap:9px;border-radius:999px;padding:6px 12px 6px 6px;font-weight:900;color:#7A3D65;border:1px solid rgba(46,42,59,.08);background:rgba(255,255,255,.88);cursor:pointer}
    .profile img{width:34px;height:34px;object-fit:cover;border-radius:50%}
        
        .page-wrap{padding:28px 0 48px}
        .breadcrumb{font-size:13px;color:var(--muted);margin-bottom:20px}
        .breadcrumb a{color:var(--hot-pink);font-weight:700}

        .stats-simple{
            display:flex;
            gap:20px;
            margin-bottom:30px;
            flex-wrap:wrap;
        }
        .stat-simple{
            background:white;
            border-radius:20px;
            padding:20px 30px;
            text-align:center;
            flex:1;
            min-width:150px;
            border:1px solid rgba(0,0,0,.05);
        }
        .stat-number{
            font-size:36px;
            font-weight:800;
            color:var(--hot-pink);
        }
        .stat-label{
            font-size:13px;
            color:var(--muted);
            margin-top:5px;
        }
        .stat-note{
            font-size:11px;
            color:#f59e0b;
            margin-top:5px;
        }

        .glass-card{
            background:var(--paper);
            border:1px solid rgba(255,255,255,.55);
            box-shadow:var(--shadow);
            border-radius:var(--radius-xl);
            overflow:hidden;
            margin-bottom:24px;
        }
        .card-header{
            padding:24px 28px 0 28px;
        }
        .card-header h3{
            font-size:20px;
            margin:0 0 6px;
        }
        .card-header .sub{
            color:var(--muted);
            font-size:13px;
            margin:0 0 20px;
        }

        .session-tabs{
            display:flex;
            gap:8px;
            padding:0 28px;
            border-bottom:2px solid rgba(0,0,0,.05);
        }
        .session-tab{
            padding:12px 20px;
            background:none;
            border:none;
            font-size:14px;
            font-weight:800;
            color:var(--muted);
            cursor:pointer;
        }
        .session-tab.active{
            color:var(--hot-pink);
            border-bottom:2px solid var(--hot-pink);
            margin-bottom:-2px;
        }
        .session-group{
            display:none;
            padding:24px 28px;
        }
        .session-group.active{
            display:block;
        }
        .sessions-list{
            display:flex;
            flex-direction:column;
            gap:16px;
        }
        .session-card{
            background:white;
            border-radius:var(--radius-md);
            padding:20px;
            border:1px solid rgba(0,0,0,.05);
        }
        .session-header{
            display:flex;
            justify-content:space-between;
            align-items:center;
            margin-bottom:12px;
            flex-wrap:wrap;
            gap:10px;
        }
        .session-language{
            font-size:16px;
            font-weight:800;
            color:var(--hot-pink);
        }
        .badge-attended{
            background:#d4edda;
            color:#2D6A42;
            padding:4px 12px;
            border-radius:20px;
            font-size:11px;
            font-weight:800;
            display:inline-flex;
            align-items:center;
            gap:4px;
        }
        .badge-auto{
            background:#e9ecef;
            color:#6c757d;
            padding:4px 12px;
            border-radius:20px;
            font-size:11px;
            font-weight:800;
            display:inline-flex;
            align-items:center;
            gap:4px;
        }
        .badge-missed{
            background:#fee2e2;
            color:#dc2626;
            padding:4px 12px;
            border-radius:20px;
            font-size:11px;
            font-weight:800;
            display:inline-flex;
            align-items:center;
            gap:4px;
        }
        .badge-refund{
            background:#fff3e0;
            color:#f59e0b;
            padding:4px 12px;
            border-radius:20px;
            font-size:11px;
            font-weight:800;
            display:inline-flex;
            align-items:center;
            gap:4px;
        }
        .badge-pending{
            background:#e3f2fd;
            color:#1976d2;
            padding:4px 12px;
            border-radius:20px;
            font-size:11px;
            font-weight:800;
            display:inline-flex;
            align-items:center;
            gap:4px;
        }
        .badge-upcoming{
            background:#e3f2fd;
            color:#1976d2;
            padding:4px 12px;
            border-radius:20px;
            font-size:11px;
            font-weight:800;
            display:inline-flex;
            align-items:center;
            gap:4px;
        }
        
        .session-details{
            display:flex;
            flex-wrap:wrap;
            gap:20px;
            margin-bottom:12px;
            font-size:12px;
            color:var(--muted);
        }
        .feedback-section{
            background:#f8fafc;
            border-radius:16px;
            padding:15px;
            margin-top:12px;
        }
        .feedback-text{
            font-size:13px;
            color:#475569;
            line-height:1.5;
            margin-bottom:12px;
        }
        .feedback-text:last-child{
            margin-bottom:0;
        }
        .pending-feedback{
            background:#fef3c7;
            border-radius:16px;
            padding:15px;
            margin-top:12px;
            text-align:center;
            color:#b45309;
        }
        .empty-state{
            text-align:center;
            padding:60px;
            background:rgba(255,241,246,.6);
            border-radius:24px;
        }
        .empty-state i{
            font-size:64px;
            color:var(--muted);
            margin-bottom:20px;
            display:block;
        }
        
        .confirmation-buttons {
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid rgba(0,0,0,.05);
            display: flex;
            gap: 12px;
            justify-content: center;
            flex-wrap: wrap;
        }
        
        .proof-upload {
            margin-top: 12px;
            padding: 12px;
            background: #f8fafc;
            border-radius: 12px;
        }
        
        @media(max-width:980px){
            .nav{grid-template-columns:1fr auto;padding:10px 0}
            .nav-links{grid-column:1/-1;grid-row:2;width:100%;justify-content:center}
        }
        @media(max-width:760px){
            .stats-simple{flex-direction:column;}
            .session-header{flex-direction:column;align-items:flex-start;}
        }
    </style>
</head>
<body>

<header class="topbar">
    <div class="container">
        <nav class="nav">
            <a href="student_dashboard.php" class="brand">
                <img src="<?= e($assetBase) ?>/logo.png" alt="Logo">
                <div><strong>Kyoshi</strong><span>Student Space</span></div>
            </a>
            
        <div class="nav-links">
          <a href="student_dashboard.php">Home</a>
          <a  href="find_language.php">Find Language</a>
          <a href="booking_status.php">My Bookings</a>
          <a href="my_payments.php">My Payments</a>
          <a href="my_materials.php">My Materials</a>
          <a href="my_assignments.php">My Assignments</a>
        </div>
        <div class="nav-actions" style="display:flex;align-items:center;justify-content:flex-end;gap:10px;margin-left:auto;">
          <div style="position:relative;">
            <button class="profile" onclick="toggleDropdown()" id="profileBtn">
              <img src="<?= e($profilePic) ?>" alt="Student profile">
              <span><?= e($displayName) ?></span>
              <i class="bi bi-chevron-down" style="font-size:11px; margin-left:4px;"></i>
            </button>
            <div id="profileDropdown" style="display:none;position:absolute;top:calc(100% + 10px);right:0;background:white;border-radius:16px;box-shadow:0 18px 45px rgba(201,79,134,.2);border:1px solid rgba(242,138,178,.2);min-width:180px;overflow:hidden;z-index:100;">
              <a href="student_profile.php" style="display:flex;align-items:center;gap:10px;padding:14px 16px;font-size:14px;font-weight:700;color:#342635;transition:.15s ease;" onmouseover="this.style.background='#FFF1F6'" onmouseout="this.style.background='white'">
                <i class="bi bi-person-circle" style="color:#E75A9B;"></i> My Profile
              </a>
              <a href="my_progress.php" style="display:flex;align-items:center;gap:10px;padding:14px 16px;font-size:14px;font-weight:700;color:#342635;transition:.15s ease;" onmouseover="this.style.background='#FFF1F6'" onmouseout="this.style.background='white'">
  <i class="bi bi-bar-chart-steps" style="color:#E75A9B;"></i> My Progress
</a>
              <a href="student_favourites.php" style="display:flex;align-items:center;gap:10px;padding:14px 16px;font-size:14px;font-weight:700;color:#342635;transition:.15s ease;" onmouseover="this.style.background='#FFF1F6'" onmouseout="this.style.background='white'">
                <i class="bi bi-heart" style="color:#E75A9B;"></i> My Favourites
              </a>
              <hr style="margin:4px 0;border-color:rgba(242,138,178,.2);">
              <a href="logout.php" style="display:flex;align-items:center;gap:10px;padding:14px 16px;font-size:14px;font-weight:700;color:#dc2626;transition:.15s ease;" onmouseover="this.style.background='#FFF1F6'" onmouseout="this.style.background='white'">
                <i class="bi bi-box-arrow-right"></i> Logout
              </a>
            </div>
          </div>
        </div>
        </nav>
    </div>
</header>

<main class="container">
    <div class="page-wrap">
        <div class="breadcrumb">
            <a href="student_dashboard.php">Home</a> / <span>My Progress</span>
        </div>

        <!-- Statistics -->
        <div class="stats-simple">
            <div class="stat-simple">
                <div class="stat-number"><?= $totalSessions ?></div>
                <div class="stat-label">Total Sessions</div>
            </div>
            <div class="stat-simple">
                <div class="stat-number"><?= $attendedSessions ?></div>
                <div class="stat-label">Attended</div>
            </div>
            <div class="stat-simple">
                <div class="stat-number"><?= $attendanceRate ?>%</div>
                <div class="stat-label">Attendance Rate</div>
                <?php if ($sessionsWithoutFeedback > 0): ?>
                <div class="stat-note">
                    <i class="bi bi-info-circle"></i> <?= $sessionsWithoutFeedback ?> session(s) waiting for feedback
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Session History -->
        <div class="glass-card">
            <div class="card-header">
                <h3><i class="bi bi-journal-bookmark-fill" style="color:var(--hot-pink);"></i> Session Reports & Feedback</h3>
                <p class="sub">View feedback from your tutors on completed sessions</p>
            </div>
            
            <div class="session-tabs">
                <button class="session-tab active" onclick="showSessionType('completed', this)">Completed (<?= count($allCompletedSessions) ?>)</button>
                <button class="session-tab" onclick="showSessionType('upcoming', this)">Upcoming (<?= count($upcomingSessions) ?>)</button>
            </div>

            <!-- Completed Sessions -->
            <div id="completed-sessions" class="session-group active">
                <?php if (empty($allCompletedSessions)): ?>
                <div class="empty-state">
                    <i class="bi bi-calendar-x"></i>
                    <h3>No Completed Sessions Yet</h3>
                    <p>Your completed sessions and tutor feedback will appear here.</p>
                    <a href="find_language.php" style="display:inline-block;margin-top:15px;padding:10px 24px;background:linear-gradient(135deg,#E75A9B,#F28AB2);color:white;border-radius:30px;">Find a Tutor</a>
                </div>
                <?php else: ?>
                <div class="sessions-list">
                    <?php foreach ($allCompletedSessions as $session): 
                        // Check if this session has a report (feedback)
                        $hasReport = false;
                        $reportData = null;
                        foreach ($sessionReports as $report) {
                            if ($report['booking_id'] == $session['booking_id']) {
                                $hasReport = true;
                                $reportData = $report;
                                break;
                            }
                        }
                        
                        $studentConfirmed = $session['student_confirmed'] ?? 0;
                        $isManuallySet = $session['attendance_manually_set'] ?? 0;
                        $showConfirmButtons = (!$isManuallySet && $studentConfirmed == 0 && strtotime($session['booking_date']) <= strtotime('now'));
                    ?>
                    <div class="session-card">
                        <div class="session-header">
                            <span class="session-language"><?= e($session['language']) ?></span>
                            <?= getAttendanceBadge($session) ?>
                        </div>
                        <div class="session-details">
                            <span><i class="bi bi-person"></i> Tutor: <?= e($session['tutor_name']) ?></span>
                            <span><i class="bi bi-calendar3"></i> <?= date('d M Y', strtotime($session['booking_date'])) ?></span>
                            <span><i class="bi bi-clock"></i> <?= date('g:i A', strtotime($session['booking_time'])) ?></span>
                            <span><i class="bi bi-laptop"></i> <?= $session['learning_mode'] === 'online' ? 'Online' : 'Face to Face' ?></span>
                        </div>
                        
                        <!-- Meeting Logs for Online Sessions -->
                        <?php if ($session['learning_mode'] === 'online' && $studentConfirmed == 1): ?>
                        <div class="proof-upload" style="margin-top: 8px;">
                            <details>
                                <summary style="cursor: pointer; font-size: 12px; color: #E75A9B;">
                                    <i class="bi bi-clock-history"></i> View Meeting Activity
                                </summary>
                                <div style="margin-top: 8px; font-size: 11px;">
                                    <?php
                                    $logsStmt = $conn->prepare("SELECT * FROM meeting_logs WHERE booking_id = ? ORDER BY join_time DESC");
                                    $logsStmt->bind_param("i", $session['booking_id']);
                                    $logsStmt->execute();
                                    $logs = $logsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
                                    if (!empty($logs)):
                                    ?>
                                    <?php foreach ($logs as $log): ?>
                                    <div style="padding: 4px 0; border-bottom: 1px solid #eef2f7;">
                                        <strong><?= ucfirst($log['participant_role']) ?></strong> joined: <?= date('g:i A', strtotime($log['join_time'])) ?>
                                        <?php if ($log['leave_time']): ?>
                                            - stayed <?= $log['duration_minutes'] ?> min
                                        <?php endif; ?>
                                    </div>
                                    <?php endforeach; ?>
                                    <?php else: ?>
                                    <p>No meeting activity recorded.</p>
                                    <?php endif; ?>
                                </div>
                            </details>
                        </div>
                        <?php endif; ?>
                        
                        <!-- Show content based on attendance -->
                        <?php if ($studentConfirmed == 1): ?>
                            <!-- Attended - show feedback if available -->
                            <?php if ($hasReport && $reportData): ?>
                            <div class="feedback-section">
                                <?php if (!empty($reportData['lesson_summary'])): ?>
                                <div class="feedback-text">
                                    <strong><i class="bi bi-journal-bookmark"></i> Lesson Summary:</strong>
                                    <p style="margin-top:5px;"><?= nl2br(e($reportData['lesson_summary'])) ?></p>
                                </div>
                                <?php endif; ?>
                                
                                <?php if (!empty($reportData['topics_covered'])): ?>
                                <div class="feedback-text">
                                    <strong><i class="bi bi-book"></i> Topics Covered:</strong>
                                    <p style="margin-top:5px;"><?= nl2br(e($reportData['topics_covered'])) ?></p>
                                </div>
                                <?php endif; ?>
                                
                                <?php if (!empty($reportData['student_progress'])): ?>
                                <div class="feedback-text">
                                    <strong><i class="bi bi-graph-up"></i> Your Progress:</strong>
                                    <p style="margin-top:5px;"><?= nl2br(e($reportData['student_progress'])) ?></p>
                                </div>
                                <?php endif; ?>
                                
                                <?php if (!empty($reportData['homework_given']) && $reportData['homework_given'] !== 'No homework assigned'): ?>
                                <div class="feedback-text" style="background:#fefce8;padding:10px;border-radius:12px;">
                                    <strong><i class="bi bi-pencil-square"></i> Homework Given:</strong>
                                    <p style="margin-top:5px;"><?= nl2br(e($reportData['homework_given'])) ?></p>
                                </div>
                                <?php endif; ?>
                                
                                <?php if (!empty($reportData['next_session_focus'])): ?>
                                <div class="feedback-text">
                                    <strong><i class="bi bi-calendar-check"></i> Next Session Focus:</strong>
                                    <p style="margin-top:5px;"><?= nl2br(e($reportData['next_session_focus'])) ?></p>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php else: ?>
                            <div class="pending-feedback">
                                <i class="bi bi-clock-history"></i> 
                                <strong>Tutor is preparing feedback for this session.</strong>
                                <p style="margin-top:5px;font-size:12px;">Feedback will appear here once available.</p>
                            </div>
                            <?php endif; ?>
                            
                        <?php elseif ($studentConfirmed == 0 && $isManuallySet && $session['no_show_type'] == 'student_no_show'): ?>
                            <!-- Missed - No feedback -->
                            <div class="pending-feedback" style="background:#fee2e2;color:#dc2626;">
                                <i class="bi bi-x-circle-fill"></i>
                                <strong>You reported that you did not attend this session.</strong>
                                <p style="margin-top:5px;font-size:12px;">No feedback available. The tutor was still paid for their reserved time.</p>
                            </div>
                        <?php elseif ($studentConfirmed == 0 && $isManuallySet && $session['no_show_type'] == 'tutor_no_show'): ?>
                            <!-- Refund processing -->
                            <div class="pending-feedback" style="background:#fff3e0;color:#f59e0b;">
                                <i class="bi bi-cash-stack"></i>
                                <strong>You reported the tutor did not attend.</strong>
                                <p style="margin-top:5px;font-size:12px;">Refund is being processed.</p>
                            </div>
                        <?php else: ?>
                            <!-- Pending confirmation -->
                            <div class="proof-upload">
                                <p style="margin-bottom: 8px;"><i class="bi bi-info-circle"></i> Please confirm your attendance for this session.</p>
                                <div class="confirmation-buttons" style="border-top: none; padding-top: 0;">
                                    <button onclick="confirmAttendance(<?= $session['booking_id'] ?>, 'confirm')" class="btn-confirm" style="background: #28a745; color: white; padding: 8px 20px; border-radius: 30px; border: none; cursor: pointer;">
                                        <i class="bi bi-check-circle"></i> Yes, I Attended
                                    </button>
                                    <button onclick="confirmAttendance(<?= $session['booking_id'] ?>, 'student_no_show')" class="btn-missed" style="background: #f59e0b; color: white; padding: 8px 20px; border-radius: 30px; border: none; cursor: pointer;">
                                        <i class="bi bi-x-circle"></i> No, I Did Not Attend
                                    </button>
                                    <button onclick="confirmAttendance(<?= $session['booking_id'] ?>, 'tutor_no_show')" class="btn-refund" style="background: #dc2626; color: white; padding: 8px 20px; border-radius: 30px; border: none; cursor: pointer;">
                                        <i class="bi bi-cash-stack"></i> Tutor Didn't Attend
                                    </button>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- Upcoming Sessions -->
            <div id="upcoming-sessions" class="session-group">
                <?php if (empty($upcomingSessions)): ?>
                <div class="empty-state">
                    <i class="bi bi-calendar-check"></i>
                    <h3>No Upcoming Sessions</h3>
                    <p>Book a session to continue your learning journey!</p>
                    <a href="find_language.php" style="display:inline-block;margin-top:15px;padding:10px 24px;background:linear-gradient(135deg,#E75A9B,#F28AB2);color:white;border-radius:30px;">Find a Tutor</a>
                </div>
                <?php else: ?>
                <div class="sessions-list">
                    <?php foreach ($upcomingSessions as $session): ?>
                    <div class="session-card">
                        <div class="session-header">
                            <span class="session-language"><?= e($session['language']) ?></span>
                            <span class="badge-upcoming"><i class="bi bi-calendar-clock"></i> Upcoming</span>
                        </div>
                        <div class="session-details">
                            <span><i class="bi bi-person"></i> Tutor: <?= e($session['tutor_name']) ?></span>
                            <span><i class="bi bi-calendar3"></i> <?= date('d M Y', strtotime($session['booking_date'])) ?></span>
                            <span><i class="bi bi-clock"></i> <?= date('g:i A', strtotime($session['booking_time'])) ?></span>
                            <span><i class="bi bi-cash-stack"></i> RM <?= number_format($session['total_amount'], 2) ?></span>
                        </div>
                        <div style="margin-top: 12px;">
                            <a href="booking_detail.php?id=<?= $session['booking_id'] ?>" style="display:inline-block;padding:8px 20px;background:#1d3156;color:white;border-radius:30px;text-decoration:none;font-size:12px;font-weight:600;">
                                <i class="bi bi-eye"></i> View Details
                            </a>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</main>

<script>
function toggleDropdown() {
    const d = document.getElementById('profileDropdown');
    d.style.display = d.style.display === 'none' ? 'block' : 'none';
}

document.addEventListener('click', function(e) {
    const btn = document.querySelector('.profile-nav');
    const dd = document.getElementById('profileDropdown');
    if (btn && dd && !btn.contains(e.target) && !dd.contains(e.target)) {
        dd.style.display = 'none';
    }
});

function showSessionType(type, element) {
    document.querySelectorAll('.session-tab').forEach(tab => tab.classList.remove('active'));
    element.classList.add('active');
    document.getElementById('completed-sessions').classList.remove('active');
    document.getElementById('upcoming-sessions').classList.remove('active');
    document.getElementById(type === 'completed' ? 'completed-sessions' : 'upcoming-sessions').classList.add('active');
}

function confirmAttendance(bookingId, action) {
    if (action === 'confirm') {
        if (!confirm('Confirm that you attended this session?')) {
            return;
        }
        submitAttendance(bookingId, action, null);
    } else if (action === 'student_no_show') {
        let reason = prompt('Why did you not attend this session? (Optional)', '');
        submitAttendance(bookingId, action, reason || '');
    } else if (action === 'tutor_no_show') {
        let reason = prompt('Please describe what happened:', 'Tutor did not show up for the session');
        if (reason === null) return;
        submitAttendance(bookingId, action, reason);
    }
}

function submitAttendance(bookingId, action, reason) {
    let data = {booking_id: bookingId, action: action};
    data.reason = reason && reason.trim() !== '' ? reason.trim() : 'No reason provided';
    
    fetch('confirm_attendance_api.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(data)
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

function showToast(message, type) {
    let toast = document.getElementById('toast');
    if (!toast) {
        toast = document.createElement('div');
        toast.id = 'toast';
        toast.style.cssText = 'position:fixed;bottom:30px;left:50%;transform:translateX(-50%);background:#333;color:white;padding:12px 24px;border-radius:40px;z-index:9999;opacity:0;transition:opacity 0.3s;';
        document.body.appendChild(toast);
    }
    toast.textContent = message;
    toast.style.backgroundColor = type === 'success' ? '#28a745' : '#dc2626';
    toast.style.opacity = '1';
    setTimeout(() => {
        toast.style.opacity = '0';
    }, 3000);
}
</script>
</body>
</html>