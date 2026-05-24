<?php
session_start();
include 'config.php';
$assetBase = '../assets/img';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$userID = $_SESSION['user_id'];

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

// Get all bookings with session_completion
$stmt = $conn->prepare("
    SELECT 
        b.id as booking_id,
        b.language,
        b.booking_date,
        b.booking_time,
        b.total_amount,
        b.status as booking_status,
        u.fullname as tutor_name,
        u.id as tutor_id,
        sc.tutor_confirmed,
        sc.student_confirmed,
        sc.completed_at,
        tf.feedback,
        tf.rating,
        tf.strengths,
        tf.areas_to_improve
    FROM bookings b
    JOIN users u ON b.tutor_id = u.id
    LEFT JOIN session_completion sc ON b.id = sc.booking_id
    LEFT JOIN tutor_feedback tf ON b.id = tf.booking_id AND tf.student_id = ?
    WHERE b.student_id = ? 
        AND b.status IN ('completed', 'accepted', 'confirmed')
    ORDER BY b.booking_date DESC
");
$stmt->bind_param("ii", $userID, $userID);
$stmt->execute();
$allSessions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Filter sessions based on date
$today = date('Y-m-d');
$pastSessions = [];
$upcomingSessions = [];
$languageProgress = [];

foreach ($allSessions as $session) {
    $sessionDate = $session['booking_date'];
    $isPast = $sessionDate <= $today;
    
    if ($isPast) {
        $pastSessions[] = $session;
    } else {
        $upcomingSessions[] = $session;
    }
}

// Calculate statistics (only for past sessions)
$totalSessions = count($pastSessions);
$attendedSessions = 0;
$totalRating = 0;
$ratingCount = 0;

foreach ($pastSessions as $session) {
    if ($session['student_confirmed'] == 1) {
        $attendedSessions++;
    }
    if ($session['rating'] > 0) {
        $totalRating += $session['rating'];
        $ratingCount++;
    }
    
    // Track language progress
    $lang = $session['language'];
    if (!isset($languageProgress[$lang])) {
        $languageProgress[$lang] = [
            'total' => 0,
            'attended' => 0,
            'feedback_count' => 0
        ];
    }
    $languageProgress[$lang]['total']++;
    if ($session['student_confirmed'] == 1) {
        $languageProgress[$lang]['attended']++;
    }
    if (!empty($session['feedback'])) {
        $languageProgress[$lang]['feedback_count']++;
    }
}

$averageRating = $ratingCount > 0 ? round($totalRating / $ratingCount, 1) : 0;
$attendanceRate = $totalSessions > 0 ? round(($attendedSessions / $totalSessions) * 100) : 0;

// Function to get session status badge
function getSessionBadge($session) {
    $today = date('Y-m-d');
    $sessionDate = $session['booking_date'];
    
    // Future session
    if ($sessionDate > $today) {
        return '<span class="session-badge badge-upcoming">
                    <i class="bi bi-calendar-clock"></i> Upcoming
                </span>';
    }
    
    // Past session
    if ($session['student_confirmed'] == 1) {
        return '<span class="session-badge badge-attended">
                    <i class="bi bi-check-circle-fill"></i> Attended 
                </span>';
    } elseif ($session['booking_status'] === 'completed' && $session['completed_at'] !== null) {
        return '<span class="session-badge badge-missed">
                    <i class="bi bi-x-circle-fill"></i> Missed ✗
                </span>';
    } elseif ($session['booking_status'] === 'accepted' || $session['booking_status'] === 'confirmed') {
        return '<span class="session-badge badge-pending">
                    <i class="bi bi-clock-history"></i> Awaiting Confirmation
                </span>';
    } else {
        return '<span class="session-badge badge-completed">
                    <i class="bi bi-check-circle"></i> Completed
                </span>';
    }
}

function e($value) {
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function getRatingStars($rating) {
    $stars = '';
    for ($i = 1; $i <= 5; $i++) {
        if ($i <= $rating) {
            $stars .= '<i class="bi bi-star-fill" style="color: #f5b042;"></i>';
        } elseif ($i - 0.5 <= $rating) {
            $stars .= '<i class="bi bi-star-half" style="color: #f5b042;"></i>';
        } else {
            $stars .= '<i class="bi bi-star" style="color: #ddd;"></i>';
        }
    }
    return $stars;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Progress - Kyoshi Student</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
    <style>
        :root{
            --cream:#FFF1F6;
            --paper:rgba(255,255,255,.88);
            --ink:#342635;
            --muted:#7B6178;
            --pink:#F28AB2;
            --pink-dark:#C94F86;
            --hot-pink:#E75A9B;
            --purple:#A77BE8;
            --purple-dark:#7648B8;
            --green:#2D6A42;
            --orange:#e67e22;
            --blue:#1976d2;
            --shadow:0 18px 45px rgba(201,79,134,.16);
            --shadow-soft:0 10px 26px rgba(201,79,134,.10);
            --radius-xl:32px;
            --radius-lg:24px;
            --radius-md:18px;
        }

        *{box-sizing:border-box}
        
        html{scroll-behavior:smooth}
        body{
            margin:0;
            min-height:100vh;
            font-family:"Segoe UI", Arial, sans-serif;
            color:var(--ink);
            background:
                linear-gradient(120deg, rgba(255,241,246,.74), rgba(255,203,220,.30)),
                url("<?= e($assetBase) ?>/background3.jpg") center/cover fixed no-repeat;
        }
        body::before{
            content:"";
            position:fixed;
            inset:0;
            pointer-events:none;
            z-index:-1;
            background:
                radial-gradient(circle at 7% 10%, rgba(231,90,155,.32), transparent 24%),
                radial-gradient(circle at 90% 8%, rgba(255,195,216,.42), transparent 26%),
                radial-gradient(circle at 55% 95%, rgba(234,215,255,.30), transparent 28%);
        }

        a{text-decoration:none;color:inherit}
        button,input{font-family:inherit}
        .container{width:min(1440px, calc(100% - 40px)); margin:0 auto}

        .topbar{
            position:sticky; top:0; z-index:50;
            background:rgba(255,241,246,.86);
            backdrop-filter:blur(20px);
            border-bottom:1px solid rgba(231,90,155,.18);
            box-shadow:0 10px 30px rgba(201,79,134,.10);
        }
        .nav{
            min-height:78px;
            display:grid;
            grid-template-columns:auto 1fr auto;
            gap:16px;
            align-items:center;
        }
        .brand{display:flex; align-items:center; gap:10px; min-width:0}
        .brand img{width:44px; height:44px; object-fit:contain; border-radius:14px}
        .brand strong{display:block; font-size:18px; line-height:1.05}
        .brand span{display:block; margin-top:3px; font-size:11px; color:var(--muted); white-space:nowrap}

        .nav-links{
            display:flex; align-items:center; justify-content:center; gap:6px;
            border-radius:999px; padding:7px;
            overflow:auto; scrollbar-width:none;
            box-shadow:inset 0 1px 0 rgba(255,255,255,.70);
            justify-self: center;
        }
        .nav-links::-webkit-scrollbar{display:none}
        .nav-links a{flex:0 0 auto; padding:9px 12px; border-radius:999px; font-size:13px; font-weight:900; color:#6D4964; white-space:nowrap; transition:.18s ease}
        .nav-links a.active,.nav-links a:hover{background:linear-gradient(135deg, var(--hot-pink), var(--pink)); color:#fff; box-shadow:0 8px 18px rgba(231,90,155,.28)}

        .nav-actions {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 12px;
            margin-left: auto;
            position: relative;
        }

        .profile{
            display:flex;
            align-items:center;
            gap:9px;
            border-radius:999px;
            padding:6px 12px 6px 6px;
            font-weight:900;
            color:#7A3D65;
            border:1px solid rgba(46,42,59,.08);
            background:rgba(255,255,255,.88);
            cursor:pointer;
        }
        .profile img{width:34px; height:34px; object-fit:cover; border-radius:50%}
        .profile span{max-width:86px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap}

        .dropdown {
            position: absolute;
            top: calc(100% + 10px);
            right: 0;
            width: 220px;
            background: white;
            border-radius: 16px;
            overflow: hidden;
            display: none;
            border: 1px solid rgba(242,138,178,.2);
            box-shadow: 0 18px 45px rgba(201,79,134,.2);
            z-index: 100;
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
            transition: 0.2s;
        }
        .dropdown a:hover {
            background: #f8fafc;
        }
        .dropdown hr {
            border: none;
            border-top: 1px solid #ecf3f9;
            margin: 0;
        }

        .glass{background:var(--paper); border:1px solid rgba(255,255,255,.55); box-shadow:var(--shadow); border-radius:var(--radius-xl); padding:28px}
        
        .section{margin-top:20px}
        .section-head{display:flex; justify-content:space-between; align-items:end; gap:18px; margin-bottom:15px}
        .section-head h2{font-size:24px; margin:0}
        .section-head p{margin:6px 0 0; color:var(--muted)}

        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
            border-radius: var(--radius-lg);
            padding: 24px;
            text-align: center;
            transition: transform 0.2s;
            border: 1px solid rgba(231,90,155,.1);
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow);
        }
        
        .stat-icon {
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, var(--hot-pink), var(--pink));
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 15px;
        }
        
        .stat-icon i {
            font-size: 28px;
            color: white;
        }
        
        .stat-value {
            font-size: 32px;
            font-weight: 800;
            color: var(--hot-pink);
        }
        
        .stat-label {
            font-size: 13px;
            color: var(--muted);
            margin-top: 5px;
        }

        /* Progress Bar */
        .progress-section {
            background: white;
            border-radius: var(--radius-lg);
            padding: 24px;
            margin-bottom: 30px;
        }
        
        .progress-title {
            font-size: 18px;
            font-weight: 700;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .progress-bar-container {
            background: #eef2f7;
            border-radius: 30px;
            height: 12px;
            overflow: hidden;
        }
        
        .progress-bar-fill {
            background: linear-gradient(135deg, var(--hot-pink), var(--pink));
            border-radius: 30px;
            height: 100%;
            width: 0%;
            transition: width 0.5s ease;
        }

        /* Session Tabs */
        .session-tabs {
            display: flex;
            gap: 16px;
            margin-bottom: 25px;
            border-bottom: 2px solid #eef2f7;
        }
        
        .session-tab {
            padding: 10px 20px;
            background: none;
            border: none;
            font-size: 14px;
            font-weight: 600;
            color: var(--muted);
            cursor: pointer;
            transition: 0.2s;
            position: relative;
        }
        
        .session-tab.active {
            color: var(--hot-pink);
        }
        
        .session-tab.active::after {
            content: '';
            position: absolute;
            bottom: -2px;
            left: 0;
            width: 100%;
            height: 2px;
            background: var(--hot-pink);
        }
        
        .session-tab:hover {
            color: var(--hot-pink);
        }
        
        .session-group {
            display: none;
        }
        
        .session-group.active {
            display: block;
        }

        /* Language Chips */
        .language-chips {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 25px;
        }
        
        .lang-chip {
            padding: 8px 20px;
            border-radius: 40px;
            background: white;
            border: 1px solid rgba(231,90,155,.2);
            font-weight: 600;
            font-size: 13px;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .lang-chip.active {
            background: linear-gradient(135deg, var(--hot-pink), var(--pink));
            color: white;
            border-color: transparent;
        }
        
        /* Session Cards */
        .sessions-list {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }
        
        .session-card {
            background: white;
            border-radius: var(--radius-md);
            padding: 20px;
            border: 1px solid rgba(231,90,155,.1);
            transition: all 0.2s;
        }
        
        .session-card:hover {
            box-shadow: var(--shadow-soft);
        }
        
        .session-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
            flex-wrap: wrap;
            gap: 10px;
        }
        
        .session-language {
            font-size: 18px;
            font-weight: 700;
            color: var(--hot-pink);
        }
        
        .session-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        
        .badge-attended {
            background: #d4edda;
            color: var(--green);
        }
        
        .badge-missed {
            background: #fee2e2;
            color: #dc2626;
        }
        
        .badge-upcoming {
            background: #e3f2fd;
            color: var(--blue);
        }
        
        .badge-pending {
            background: #fff3e0;
            color: var(--orange);
        }
        
        .badge-completed {
            background: #d4edda;
            color: var(--green);
        }
        
        .session-details {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            margin-bottom: 12px;
            font-size: 13px;
            color: var(--muted);
        }
        
        .session-details i {
            width: 18px;
            color: var(--hot-pink);
        }
        
        .feedback-section {
            background: #f8fafc;
            border-radius: 16px;
            padding: 15px;
            margin-top: 12px;
        }
        
        .feedback-rating {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 10px;
            flex-wrap: wrap;
        }
        
        .feedback-text {
            font-size: 13px;
            color: #475569;
            line-height: 1.5;
        }
        
        .empty-state {
            text-align: center;
            padding: 60px;
            background: rgba(255,241,246,.8);
            border-radius: 30px;
        }
        
        .empty-state i {
            font-size: 64px;
            color: var(--muted);
            margin-bottom: 20px;
        }
        
        .toast {
            position: fixed;
            bottom: 30px;
            left: 50%;
            transform: translateX(-50%);
            background: var(--ink);
            color: white;
            padding: 12px 24px;
            border-radius: 40px;
            font-weight: 700;
            z-index: 1000;
            opacity: 0;
            transition: opacity 0.3s;
            pointer-events: none;
        }
        
        .toast.show {
            opacity: 1;
        }
        
        @media (max-width: 980px) {
            .nav {
                grid-template-columns: 1fr;
            }
            .nav-links {
                justify-content: center;
                flex-wrap: wrap;
            }
            .nav-actions {
                justify-content: center;
                margin-left: 0;
            }
        }
        
        @media (max-width: 760px) {
            .session-header {
                flex-direction: column;
                align-items: flex-start;
            }
            .stats-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <header class="topbar">
        <div class="container">
            <nav class="nav">
                <a href="student_dashboard.php" class="brand">
                    <img src="<?= e($assetBase) ?>/logo.png" alt="Kyoshi logo">
                    <div>
                        <strong>Kyoshi</strong>
                        <span>Student Learning Space</span>
                    </div>
                </a>

                <div class="nav-links">
                    <a href="student_dashboard.php">Home</a>
                    <a href="find_language.php">Find Language</a>
                    <a href="booking_status.php">My Bookings</a>
                    <a href="my_payments.php">My Payments</a>
                    <a href="my_materials.php">My Materials</a>
                </div>

                <div class="nav-actions">
                    <div style="position:relative;">
                        <button class="profile" onclick="toggleDropdown()" id="profileBtn">
                            <img src="<?= e($profilePic) ?>" alt="Student profile">
                            <span><?= e($displayName) ?></span>
                            <i class="bi bi-chevron-down" style="font-size:11px; margin-left:4px;"></i>
                        </button>
                        <div class="dropdown" id="profileDropdown">
                            <a href="student_profile.php"><i class="bi bi-person-circle"></i> My Profile</a>
                            <a href="my_progress.php"><i class="bi bi-bar-chart-steps"></i> My Progress</a>
                            <a href="student_favourites.php"><i class="bi bi-heart"></i> My Favourites</a>
                            <hr>
                            <a href="logout.php" style="color:#dc2626;"><i class="bi bi-box-arrow-right"></i> Logout</a>
                        </div>
                    </div>
                </div>
            </nav>
        </div>
    </header>

    <main class="container">
        <div class="section">
            <div class="glass">
                <div class="section-head">
                    <div>
                        <h2>My Learning Progress</h2>
                        <p>Track your attendance, feedback, and learning journey</p>
                    </div>
                </div>

                <!-- Statistics Cards -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="bi bi-journal-bookmark-fill"></i>
                        </div>
                        <div class="stat-value"><?= $totalSessions ?></div>
                        <div class="stat-label">Past Sessions</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="bi bi-check-circle-fill"></i>
                        </div>
                        <div class="stat-value"><?= $attendedSessions ?></div>
                        <div class="stat-label">Sessions Attended</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="bi bi-graph-up"></i>
                        </div>
                        <div class="stat-value"><?= $attendanceRate ?>%</div>
                        <div class="stat-label">Attendance Rate</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="bi bi-star-fill"></i>
                        </div>
                        <div class="stat-value"><?= number_format($averageRating, 1) ?></div>
                        <div class="stat-label">Average Rating</div>
                    </div>
                </div>

                <!-- Overall Progress Bar -->
                <div class="progress-section">
                    <div class="progress-title">
                        <i class="bi bi-trophy-fill" style="color: var(--orange);"></i>
                        Overall Progress
                    </div>
                    <div class="progress-bar-container">
                        <div class="progress-bar-fill" style="width: <?= $attendanceRate ?>%;"></div>
                    </div>
                    <p style="margin-top: 10px; font-size: 13px; color: var(--muted);">
                        <?= $attendedSessions ?> out of <?= $totalSessions ?> past sessions attended
                    </p>
                </div>

                <!-- Session Type Tabs -->
                <div class="session-tabs">
                    <button class="session-tab active" onclick="showSessionType('past', this)">Past Sessions</button>
                    <button class="session-tab" onclick="showSessionType('upcoming', this)">Upcoming Sessions (<?= count($upcomingSessions) ?>)</button>
                </div>

                <!-- Past Sessions -->
                <div id="past-sessions" class="session-group active">
                    <?php if (empty($pastSessions)): ?>
                    <div class="empty-state">
                        <i class="bi bi-calendar-x"></i>
                        <h3>No Past Sessions Yet</h3>
                        <p>Your completed sessions and feedback will appear here.</p>
                        <a href="find_language.php" style="display: inline-block; margin-top: 15px; padding: 10px 24px; background: linear-gradient(135deg, var(--hot-pink), var(--pink)); color: white; border-radius: 30px; text-decoration: none;">
                            <i class="bi bi-search"></i> Book a Session
                        </a>
                    </div>
                    <?php else: ?>
                    
                    <!-- Language Progress Chips -->
                    <?php if (!empty($languageProgress)): ?>
                    <div class="language-chips" id="languageChips">
                        <button class="lang-chip active" onclick="filterByLanguage('all', this)">All Languages</button>
                        <?php foreach ($languageProgress as $lang => $progress): ?>
                        <button class="lang-chip" onclick="filterByLanguage('<?= e($lang) ?>', this)">
                            <?= e($lang) ?> (<?= $progress['attended'] ?>/<?= $progress['total'] ?>)
                        </button>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                    
                    <div class="sessions-list" id="sessionsList">
                        <?php foreach ($pastSessions as $session): ?>
                        <div class="session-card" data-language="<?= e($session['language']) ?>">
                            <div class="session-header">
                                <span class="session-language"><?= e($session['language']) ?></span>
                                <?= getSessionBadge($session) ?>
                            </div>
                            <div class="session-details">
                                <span><i class="bi bi-person"></i> Tutor: <?= e($session['tutor_name']) ?></span>
                                <span><i class="bi bi-calendar3"></i> <?= date('l, F j, Y', strtotime($session['booking_date'])) ?></span>
                                <span><i class="bi bi-clock"></i> <?= date('g:i A', strtotime($session['booking_time'])) ?></span>
                            </div>
                            
                            <?php if (!empty($session['feedback'])): ?>
                            <div class="feedback-section">
                                <div class="feedback-rating">
                                    <?= getRatingStars($session['rating']) ?>
                                    <span style="font-size: 13px; font-weight: 600;">
                                        <i class="bi bi-chat-dots-fill"></i> Feedback from Tutor
                                    </span>
                                </div>
                                <div class="feedback-text">
                                    <i class="bi bi-chat-quote-fill" style="color: var(--hot-pink); margin-right: 8px;"></i>
                                    "<?= nl2br(e($session['feedback'])) ?>"
                                </div>
                                <?php if (!empty($session['strengths'])): ?>
                                <div class="feedback-text" style="margin-top: 10px;">
                                    <strong><i class="bi bi-trophy"></i> 👍 Strengths:</strong> <?= e($session['strengths']) ?>
                                </div>
                                <?php endif; ?>
                                <?php if (!empty($session['areas_to_improve'])): ?>
                                <div class="feedback-text" style="margin-top: 10px;">
                                    <strong><i class="bi bi-graph-up"></i> 📈 Areas to Improve:</strong> <?= e($session['areas_to_improve']) ?>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php else: ?>
                            <div class="feedback-section" style="background: rgba(231,90,155,.08);">
                                <div class="feedback-text" style="text-align: center; color: var(--muted);">
                                    <i class="bi bi-clock-history"></i> Waiting for tutor's feedback...
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
                        <p>Book a session to see it here.</p>
                        <a href="find_language.php" style="display: inline-block; margin-top: 15px; padding: 10px 24px; background: linear-gradient(135deg, var(--hot-pink), var(--pink)); color: white; border-radius: 30px; text-decoration: none;">
                            <i class="bi bi-search"></i> Find a Tutor
                        </a>
                    </div>
                    <?php else: ?>
                    <div class="sessions-list">
                        <?php foreach ($upcomingSessions as $session): ?>
                        <div class="session-card">
                            <div class="session-header">
                                <span class="session-language"><?= e($session['language']) ?></span>
                                <span class="session-badge badge-upcoming">
                                    <i class="bi bi-calendar-clock"></i> Upcoming
                                </span>
                            </div>
                            <div class="session-details">
                                <span><i class="bi bi-person"></i> Tutor: <?= e($session['tutor_name']) ?></span>
                                <span><i class="bi bi-calendar3"></i> <?= date('l, F j, Y', strtotime($session['booking_date'])) ?></span>
                                <span><i class="bi bi-clock"></i> <?= date('g:i A', strtotime($session['booking_time'])) ?></span>
                                <span><i class="bi bi-cash-stack"></i> RM <?= number_format($session['total_amount'], 2) ?></span>
                            </div>
                            <div class="booking-actions" style="margin-top: 12px;">
                                <a href="booking_detail.php?id=<?= $session['booking_id'] ?>" class="btn-view" style="display: inline-block; padding: 8px 16px; background: #e2e8f0; color: #1d3156; border-radius: 30px; text-decoration: none; font-size: 12px; font-weight: 600;">
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

    <div id="toast" class="toast"></div>

    <script>
        function toggleDropdown() {
            const dropdown = document.getElementById('profileDropdown');
            dropdown.style.display = dropdown.style.display === 'block' ? 'none' : 'block';
        }

        window.addEventListener('click', function(e) {
            const dropdown = document.getElementById('profileDropdown');
            const button = document.getElementById('profileBtn');
            if (button && !button.contains(e.target) && dropdown && !dropdown.contains(e.target)) {
                dropdown.style.display = 'none';
            }
        });

        function showSessionType(type, element) {
            // Update tabs
            document.querySelectorAll('.session-tab').forEach(tab => tab.classList.remove('active'));
            element.classList.add('active');
            
            // Show/hide content
            document.getElementById('past-sessions').classList.remove('active');
            document.getElementById('upcoming-sessions').classList.remove('active');
            
            if (type === 'past') {
                document.getElementById('past-sessions').classList.add('active');
            } else {
                document.getElementById('upcoming-sessions').classList.add('active');
            }
        }

        function filterByLanguage(language, element) {
            // Update active state on chips
            document.querySelectorAll('.lang-chip').forEach(chip => {
                chip.classList.remove('active');
            });
            element.classList.add('active');
            
            // Filter sessions
            const sessions = document.querySelectorAll('#past-sessions .session-card');
            sessions.forEach(session => {
                if (language === 'all' || session.dataset.language === language) {
                    session.style.display = 'block';
                } else {
                    session.style.display = 'none';
                }
            });
            
            showToast(language === 'all' ? 'Showing all languages' : `Showing ${language} sessions`);
        }
        
        function showToast(message) {
            const toast = document.getElementById('toast');
            toast.textContent = message;
            toast.classList.add('show');
            setTimeout(() => {
                toast.classList.remove('show');
            }, 3000);
        }
    </script>
</body>
</html>