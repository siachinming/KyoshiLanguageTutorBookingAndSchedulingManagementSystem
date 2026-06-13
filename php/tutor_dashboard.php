<?php
session_start();
include 'config.php';
include 'check_login.php';

$assetBase = '../assets/img';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$userID = $_SESSION['user_id'];

/* ─────────────────────────────
   GET TUTOR INFO
───────────────────────────── */
$stmt = $conn->prepare("
    SELECT *
    FROM users
    WHERE id = ? AND role = 'tutor'
");

$stmt->bind_param("i", $userID);
$stmt->execute();

$user = $stmt->get_result()->fetch_assoc();

if (!$user) {
    header("Location: login.php");
    exit();
}

$pendingReports = $conn->query("
    SELECT COUNT(*) as count 
    FROM bookings b
    LEFT JOIN session_reports sr ON b.id = sr.booking_id
    LEFT JOIN session_completion sc ON b.id = sc.booking_id
    WHERE b.tutor_id = $userID 
    AND b.status = 'completed'
    AND (sr.id IS NULL OR sr.report_status = 'draft')
    AND (sc.student_confirmed IS NULL OR sc.student_confirmed = 1)
    AND (sc.no_show_type IS NULL OR sc.no_show_type != 'student_no_show')
");
$pendingReportCount = $pendingReports->fetch_assoc()['count'];

$displayName = $user['fullname'];

$profilePic = !empty($user['profile_pic'])
    ? '../uploads/profiles/' . $user['profile_pic']
    : $assetBase . '/profile-tutor.png';

$firstName = explode(' ', trim($displayName))[0];

/* ─────────────────────────────
   TOTAL BOOKINGS
───────────────────────────── */
$stmt = $conn->prepare("
    SELECT COUNT(*) AS total
    FROM bookings
    WHERE tutor_id = ?
");

$stmt->bind_param("i", $userID);
$stmt->execute();

$totalBookings = $stmt->get_result()->fetch_assoc()['total'] ?? 0;

/* ─────────────────────────────
   PENDING BOOKINGS
───────────────────────────── */
$stmt = $conn->prepare("
    SELECT COUNT(*) AS total
    FROM bookings
    WHERE tutor_id = ?
    AND status = 'pending'
");

$stmt->bind_param("i", $userID);
$stmt->execute();

$pendingBookings = $stmt->get_result()->fetch_assoc()['total'] ?? 0;

/* ─────────────────────────────
   COMPLETED SESSIONS
───────────────────────────── */
$stmt = $conn->prepare("
    SELECT COUNT(*) AS total
    FROM bookings
    WHERE tutor_id = ?
    AND status = 'completed'
");

$stmt->bind_param("i", $userID);
$stmt->execute();

$completedSessions = $stmt->get_result()->fetch_assoc()['total'] ?? 0;

/* ─────────────────────────────
   TOTAL MATERIALS
───────────────────────────── */
$stmt = $conn->prepare("
    SELECT COUNT(*) AS total
    FROM learning_materials
    WHERE tutor_id = ?
");

$stmt->bind_param("i", $userID);
$stmt->execute();

$totalMaterials = $stmt->get_result()->fetch_assoc()['total'] ?? 0;

/* ─────────────────────────────
   TOTAL EARNINGS
───────────────────────────── */
$stmt = $conn->prepare("
    SELECT SUM(b.total_amount) AS total
    FROM bookings b
    JOIN payments p ON b.id = p.booking_id
    LEFT JOIN session_completion sc ON b.id = sc.booking_id
    LEFT JOIN session_reports sr ON b.id = sr.booking_id AND sr.report_status = 'submitted'
    WHERE b.tutor_id = ?
    AND b.status = 'completed'
    AND p.status = 'verified'
    AND (
        (sc.student_confirmed = 1 AND sr.id IS NOT NULL)
        OR
        (sc.student_confirmed = 0 AND sc.no_show_type = 'student_no_show')
    )
");

$stmt->bind_param("i", $userID);
$stmt->execute();

$totalEarnings = $stmt->get_result()->fetch_assoc()['total'] ?? 0;

$commissionRate = 0.20;
$netEarnings = $totalEarnings * (1 - $commissionRate);

/* ─────────────────────────────
   UPCOMING BOOKINGS
───────────────────────────── */
$stmt = $conn->prepare("
    SELECT b.*, u.fullname AS student_name
    FROM bookings b
    JOIN users u ON b.student_id = u.id
    WHERE b.tutor_id = ?
    AND b.booking_date >= CURDATE()
    AND b.status IN ('pending', 'confirmed')
    ORDER BY b.booking_date ASC
    LIMIT 5
");

$stmt->bind_param("i", $userID);
$stmt->execute();

$upcomingSessions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);




/* ─────────────────────────────
   PENDING DISPUTES
───────────────────────────── */
$totalDisputeStmt = $conn->prepare("
    SELECT COUNT(*) as count 
    FROM disputes d
    JOIN bookings b ON d.booking_id = b.id
    WHERE b.tutor_id = ? 
    AND d.status = 'pending'
    AND d.resolution_type = 'student_tutor'
");
$totalDisputeStmt->bind_param("i", $userID);
$totalDisputeStmt->execute();
$totalDisputes = $totalDisputeStmt->get_result()->fetch_assoc()['count'] ?? 0;

// Check if alerts have been dismissed this session
$showDisputeAlert = ($totalDisputes > 0 && !isset($_SESSION['dismissed_dispute_alert']));
$showReportAlert = ($pendingReportCount > 0 && !isset($_SESSION['dismissed_report_alert']));


function e($value){
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
<meta http-equiv="Pragma" content="no-cache">
<meta http-equiv="Expires" content="0">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Kyoshi Tutor Dashboard</title>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../css/style.css">
<style>
/* Your existing CSS styles remain the same */
*{
    margin:0;
    padding:0;
    box-sizing:border-box;
}

body{
    font-family:'Poppins',sans-serif;
    background: url('../assets/img/background2.png') no-repeat center top;
    background-size: cover;
    min-height: 100vh;
    position: relative;
}

body::before{
    content:'';
    position:fixed;
    inset:0;
    background: rgba(255,255,255,0.25);
    z-index:-1;
}

.topbar{
    width:100%;
    background: rgba(254, 214, 206, 0.92);
    backdrop-filter: blur(12px);
    position:sticky;
    top:0;
    z-index:999;
    box-shadow:0 2px 20px rgba(0,0,0,0.08);
    border-bottom:1px solid rgba(255,255,255,0.3);
}

.container{
    width:min(1400px,94%);
    margin:auto;
}

.nav{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap: 32px;
    min-height: 70px;
}

.brand{
    display:flex;
    align-items:center;
    gap:10px;
    text-decoration:none;
    flex-shrink: 0;
}

.brand img{
    width:42px;
    height:42px;
    object-fit:contain;
}

.brand strong{
    display:block;
    color: #1d3156;
    font-size:20px;
    line-height: 1.2;
    letter-spacing:-0.3px;
}

.brand span{
    color:#496894;
    font-size:11px;
    font-weight:600;
    letter-spacing:0.5px;
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
    white-space: nowrap;
    display: inline-block;  /* Add this */
}

.nav-links a:hover,
.nav-links a.active {
    color: #496894;
    font-weight: 700;
}
/* Underline styles */
.nav-links a::after {
    content: '';
    position: absolute;
    left: 0;
    bottom: -6px;
    width: 0%;
    height: 4px;
    background: #496894;
    transition: width 0.3s ease;
    border-radius: 10px;
}

.nav-links a:hover::after,
.nav-links a.active::after {
    width: 100%;
}

.profile:hover{
    background:rgba(255,255,255,0.2);
    transform: translateY(-1px);
}

.profile img{
    width:36px;
    height:36px;
    border-radius:50%;
    object-fit:cover;
    border:2px solid rgba(255,255,255,0.4);
}

.profile span{
    font-size:13px;
    font-weight:500;
}

.profile i{
    font-size:12px;
    color:#b0cbe6;
}

.dropdown{
    position:absolute;
    top:calc(100% + 10px);
    right:0;
    width:220px;
    background:white;
    border-radius:16px;
    overflow:hidden;
    display:none;
    border:1px solid #e2edf7;
    box-shadow:0 15px 35px rgba(0,0,0,0.15);
    z-index: 1000;
}

.dropdown a{
    display:flex;
    align-items:center;
    gap:12px;
    padding:12px 18px;
    text-decoration:none;
    color:#1e293b;
    font-size:13px;
    font-weight:500;
    transition:0.2s;
}

.dropdown a:hover{
    background:#f8fafc;
}

.dropdown hr{
    border:none;
    border-top:1px solid #ecf3f9;
    margin:0;
}

.main{
    width:min(1280px,92%);
    margin:32px auto 48px;
}

.hero{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:50px;
    flex-wrap:wrap;
    background: linear-gradient(135deg, rgba(29,49,86,0.92), rgba(73,104,148,0.88));
    border-radius:32px;
    padding:42px;
    color:white;
    box-shadow: 0 10px 30px rgba(0,0,0,0.12);
    border:1px solid rgba(255,255,255,0.15);
    overflow:hidden;
    position:relative;
}

.hero::before{
    content:'';
    position:absolute;
    width:300px;
    height:300px;
    background:rgba(255,255,255,0.08);
    border-radius:50%;
    top:-120px;
    right:-80px;
    filter:blur(10px);
}

.hero h1{
    font-size:42px;
    font-weight:800;
    line-height:1.1;
    margin-bottom:14px;
    letter-spacing:-1px;
}

.hero p{
    color:#dbe8f5;
    font-size:16px;
    line-height:1.7;
    max-width:560px;
}

.hero-left{
    flex:1;
    min-width:280px;
}

.hero-stats{
    flex:1;
    display:grid;
    grid-template-columns:repeat(2,1fr);
    gap:16px;
    min-width:320px;
}

.hero-card{
    background:rgba(255,255,255,0.15);
    border:1px solid rgba(255,255,255,0.18);
    backdrop-filter:blur(10px);
    border-radius:20px;
    padding:18px;
    display:flex;
    align-items:center;
    gap:14px;
    transition:0.25s;
}

.hero-card:hover{
    transform:translateY(-3px);
    background:rgba(255,255,255,0.2);
}

.hero-icon{
    width:52px;
    height:52px;
    border-radius:16px;
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:22px;
    flex-shrink:0;
    background:rgba(255,255,255,0.2);
}

.hero-card span{
    display:block;
    font-size:12px;
    color:#dbe8f5;
    margin-bottom:4px;
}

.hero-card h3{
    font-size:24px;
    font-weight:700;
    color:white;
}

.section{
    margin-top:40px;
}

.section-title{
    color:#0f172a;
    font-size:22px;
    font-weight:700;
    margin-bottom:18px;
    letter-spacing:-0.2px;
}

.quick-actions-section {
    margin-top: 0;
}

.quick-actions-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 20px;
}

.quick-card {
    background: white;
    border-radius: 20px;
    padding: 24px 20px;
    text-decoration: none;
    transition: all 0.25s;
    border: 1px solid #eef2f7;
    display: block;
    box-shadow: 0 2px 8px rgba(0,0,0,0.04);
}

.quick-card:hover {
    transform: translateY(-4px);
    border-color: #E75A9B;
    box-shadow: 0 14px 30px rgba(0,0,0,0.08);
}

.quick-card i {
    font-size: 32px;
    color: #E75A9B;
    margin-bottom: 16px;
    display: block;
}

.quick-card span {
    font-size: 16px;
    font-weight: 700;
    color: #1d3156;
    display: block;
    margin-bottom: 6px;
}

.quick-card small {
    font-size: 12px;
    color: #64748b;
    display: block;
    line-height: 1.4;
}

.session-box{
    background:rgba(255,255,255,0.95);
    backdrop-filter: blur(5px);
    border-radius:24px;
    padding:20px 24px;
    box-shadow:0 4px 12px rgba(0,0,0,0.05);
    border:1px solid rgba(255,255,255,0.3);
}

.session-item{
    display:flex;
    justify-content:space-between;
    align-items:center;
    padding:16px 0;
    border-bottom:1px solid #edf2f9;
}

.session-item:last-child{
    border-bottom:none;
}

.session-left h4{
    color:#0f172a;
    font-weight:700;
    font-size:16px;
    margin-bottom:6px;
}

.session-left p{
    color:#5c6f91;
    font-size:13px;
}

.status{
    padding:6px 14px;
    border-radius:40px;
    font-size:12px;
    font-weight:700;
    text-transform:capitalize;
}

.pending{
    background:#fff0e0;
    color:#b45309;
}

.confirmed{
    background:#e0f2e9;
    color:#1e6f3f;
}

.dismissible-alert {
    position: relative;
    transition: opacity 0.3s ease;
}

@media(max-width:1000px){
    .nav{
        flex-wrap: wrap;
        gap: 14px;
        padding: 12px 0;
    }
    .nav-links{
        gap: 20px;
        justify-content: center;
    }
    .brand strong{
        font-size: 18px;
    }
    .profile{
        padding: 4px 12px;
    }
    .hero-stats{
        grid-template-columns: 1fr 1fr;
    }
}

@media(max-width:720px){
    .hero{
        padding: 24px;
    }
    .hero h1{
        font-size: 24px;
    }
    .hero-stats{
        gap: 14px;
    }
    .nav-links{
        gap: 14px;
    }
    .nav-links a{
        font-size: 12px;
    }
}
/* Fix for profile dropdown positioning */
.nav-actions {
    position: relative;
}

.nav-actions > div {
    position: relative;
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
/* Dropdown - positioned to the RIGHT */
.dropdown {
    position: absolute;
    top: calc(100% + 10px);
    right: 0;
    left: auto;
    width: 220px;
    background: white;
    border-radius: 16px;
    overflow: hidden;
    display: none;
    border: 1px solid #e2edf7;
    box-shadow: 0 15px 35px rgba(0, 0, 0, 0.15);
    z-index: 1000;
}

.dropdown.show {
    display: block;
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

/* Hamburger Menu Styles */
.hamburger-menu {
    display: none;
    background: none;
    border: none;
    font-size: 28px;
    cursor: pointer;
    color: #1d3156;
    padding: 8px 12px;
    border-radius: 12px;
    transition: all 0.2s ease;
    z-index: 100;
}


.nav-overlay {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.5);
    z-index: 60;
}

.nav-overlay.active {
    display: block;
}
/* Mobile Responsive */
@media(max-width: 980px) {
    .hamburger-menu {
        display: flex;
        align-items: center;
        justify-content: center;
        order: 1;
    }
    
    .brand {
        order: 2;
    }
    
    .nav-actions {
        order: 3;
    }
    
    .nav {
        flex-wrap: wrap;
        position: relative;
        justify-content: space-between;
    }
    
    .nav-links {
        display: none;
        position: absolute;
        top: 100%;
        left: 0;
        right: 0;
        background: rgba(255, 255, 255, 0.98);
        backdrop-filter: blur(20px);
        flex-direction: column;
        border-radius: 0 0 24px 24px;
        box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
        z-index: 70;
        padding: 16px;
        gap: 10px;
        border: 1px solid rgba(231, 90, 155, 0.2);
        border-top: none;
        width: 100%;
    }
    
    .nav-links.active {
        display: flex;
    }
    
    .nav-links a {
        padding: 12px 16px;
        width: 100%;
        text-align: center;
        background: transparent !important;  /* Remove background */
        border-radius: 12px;
        color: #1d3156 !important;  /* Keep text color */
    }
    
    .nav-links a:hover {
        background: rgba(231, 90, 155, 0.1) !important;  /* Light hover effect only */
        color: #1d3156 !important;
    }
    
    .nav-links a.active {
        color: #496894 !important;  /* Active text color - blue */
        font-weight: 700;
        background: transparent !important;  /* No pink background */
        position: relative;
    }
    
    /* Add underline for active on mobile too */
    .nav-links a.active::after {
        content: '';
        position: absolute;
        bottom: 8px;
        left: 50%;
        transform: translateX(-50%);
        width: 100% !important;
        height: 3px;
        background: #496894;
        border-radius: 10px;
        display: block;
    }
    
    .nav-links a::after {
        display: none;
    }
    
    /* Dropdown on mobile - stays on right */
    .dropdown {
        right: 0;
        left: auto;
        min-width: 180px;
    }
}
</style>
</head>

<body>

<header class="topbar">
<div class="container">
<nav class="nav">
    <button class="hamburger-menu" id="hamburgerBtn">
    <i class="bi bi-list"></i>
</button>
    <a href="tutor_dashboard.php" class="brand">

        <img src="<?= e($assetBase) ?>/logo.png" alt="Kyoshi">
        <div>
            <strong>Kyoshi</strong>
            <span>Teacher Space</span>
        </div>
    </a>

    <div class="nav-links">
        <a href="tutor_dashboard.php" class="active">Dashboard</a>
        <a href="booking_requests.php">My Bookings</a>
        <a href="material_overview.php">My Materials</a>
        <a href="assignment_overview.php">My Assignments</a>
        <a href="view_session_reports.php">My Reports</a>
    </div>
<div class="nav-actions">
    <div style="position:relative;">
        <button class="profile" onclick="toggleDropdown()">
            <img src="<?= e($profilePic) ?>">
            <span><?= e($displayName) ?></span>
            <i class="bi bi-chevron-down"></i>
        </button>
        <div class="dropdown" id="profileDropdown">
            <a href="teacher_profile.php">
                <i class="bi bi-person-circle"></i> My Profile
            </a>
            <a href="earnings.php">
                <i class="bi bi-wallet2"></i> My Earnings
            </a>
            <hr>
            <a href="logout.php" style="color:#dc2626;">
                <i class="bi bi-box-arrow-right"></i> Logout
            </a>
        </div>
    </div>
</div>
</nav>
</div>
</header>

  <div class="nav-overlay" id="navOverlay"></div>
<div class="main">

<div class="hero">
    <div class="hero-left">
        <h1>Welcome Back, <?= e($firstName) ?></h1>
        <p>Manage sessions, bookings, materials, and student interactions — all in one place.</p>
    </div>




</div>

<?php if ($showDisputeAlert): ?>
<div id="disputeAlert" class="alert alert-warning dismissible-alert" style="background: #fff3cd; border-left: 4px solid #f59e0b; margin-bottom: 20px; padding: 15px 20px; border-radius: 12px; position: relative;">
    <button onclick="dismissAlert('disputeAlert', 'dispute')" style="position: absolute; right: 15px; top: 50%; transform: translateY(-50%); background: none; border: none; font-size: 20px; cursor: pointer; color: #856404;">&times;</button>
    <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 15px; padding-right: 30px;">
        <div style="display: flex; align-items: center; gap: 12px;">
            <i class="bi bi-exclamation-triangle-fill" style="font-size: 24px; color: #f59e0b;"></i>
            <div>
                <strong style="color: #856404;">Pending Disputes Require Your Attention</strong>
                <p style="margin: 5px 0 0; color: #856404; font-size: 13px;">
                    You have <strong><?= $totalDisputes ?></strong> pending dispute<?= $totalDisputes > 1 ? 's' : '' ?> that need resolution.
                    Please resolve them within 24 hours.
                </p>
            </div>
        </div>
        <a href="booking_requests.php?tab=disputes" class="btn-pink" style="background: #f59e0b; color: white; padding: 8px 20px; border-radius: 30px; text-decoration: none; font-weight: 600; font-size: 13px;">
            <i class="bi bi-eye"></i> View Disputes
        </a>
    </div>
</div>
<?php endif; ?>
<br>
<?php if ($showReportAlert): ?>
<div id="reportAlert" class="alert alert-warning dismissible-alert" style="background: #fff3e0; border-left: 4px solid #f59e0b; padding: 16px 20px; margin-bottom: 24px; border-radius: 16px; position: relative;">
    <button onclick="dismissAlert('reportAlert', 'report')" style="position: absolute; right: 15px; top: 50%; transform: translateY(-50%); background: none; border: none; font-size: 20px; cursor: pointer; color: #b45309;">&times;</button>
    <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 15px; padding-right: 30px;">
        <div style="display: flex; align-items: center; gap: 12px;">
            <i class="bi bi-exclamation-triangle-fill" style="color: #f59e0b; font-size: 24px;"></i>
            <div>
                <strong style="color: #92400e;">Action Required: <?= $pendingReportCount ?> Session Report<?= $pendingReportCount != 1 ? 's' : '' ?> Pending</strong>
                <p style="margin: 4px 0 0; font-size: 13px; color: #b45309;">Payment will only be released after you submit AND admin verifies session reports.</p>
            </div>
        </div>
        <a href="submit_session_report.php" style="background: #f59e0b; color: white; padding: 10px 24px; border-radius: 30px; text-decoration: none; font-weight: 600; font-size: 13px;">
            Submit Reports Now
        </a>
    </div>
</div>
<?php endif; ?>

<!-- QUICK ACTIONS -->
<div class="section">
    <h2 class="section-title">Quick Actions</h2>
    <div class="quick-actions-section">
        <div class="quick-actions-grid">
            <a href="select_booking.php?action=upload" class="quick-card">
                <i class="bi bi-upload"></i>
                <span>Upload Material</span>
                <small>Share resources with students</small>
            </a>
            <a href="select_booking.php?action=assignment" class="quick-card">
                <i class="bi bi-pencil-square"></i>
                <span>Create Assignment</span>
                <small>Give homework or tasks</small>
            </a>
            <a href="meeting_links.php" class="quick-card">
                <i class="bi bi-camera-video"></i>
                <span>Meeting Links</span>
                <small>Manage session links</small>
            </a>
            <a href="availability.php" class="quick-card">
                <i class="bi bi-calendar-week"></i>
                <span>Set Availability</span>
                <small>Update your schedule</small>
            </a>
        </div>
    </div>
</div>

<!-- UPCOMING SESSIONS -->
<div class="section">
    <h2 class="section-title">Upcoming Sessions</h2>
    <div class="session-box">
        <?php if(empty($upcomingSessions)): ?>
            <p style="color:#5c6f91; padding: 16px 0;">📭 No upcoming sessions scheduled.</p>
        <?php else: ?>
            <?php foreach($upcomingSessions as $session): ?>
                <div class="session-item">
                    <div class="session-left">
                        <h4><?= e($session['student_name']) ?></h4>
                        <p>
                            <?= e($session['language']) ?> •
                            <?= date('d M Y', strtotime($session['booking_date'])) ?> •
                            <?= date('g:i A', strtotime($session['booking_time'])) ?>
                        </p>
                    </div>
                    <div class="status <?= e($session['status']) ?>">
                        <?= ucfirst(e($session['status'])) ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>
</div>
<script>
function toggleDropdown() {
    const dropdown = document.getElementById('profileDropdown');
    const isVisible = dropdown.style.display === 'block';
    
    // Close all other dropdowns
    document.querySelectorAll('.dropdown').forEach(dd => {
        if (dd !== dropdown) dd.style.display = 'none';
    });
    
    // Toggle current dropdown
    dropdown.style.display = isVisible ? 'none' : 'block';
}

function dismissAlert(alertId, type) {
    const alert = document.getElementById(alertId);
    if (alert) {
        alert.style.transition = 'opacity 0.3s ease';
        alert.style.opacity = '0';
        setTimeout(() => {
            alert.style.display = 'none';
            fetch('dismiss_alert.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'type=' + type
            });
        }, 300);
    }
}

// Close dropdown when clicking outside
window.addEventListener('click', function(e) {
    const dropdown = document.getElementById('profileDropdown');
    const button = document.querySelector('.profile');
    if (button && dropdown && !button.contains(e.target) && !dropdown.contains(e.target)) {
        dropdown.style.display = 'none';
    }
});

// Mobile hamburger menu
document.addEventListener('DOMContentLoaded', function() {
    const hamburgerBtn = document.getElementById('hamburgerBtn');
    const navLinks = document.querySelector('.nav-links');
    const navOverlay = document.getElementById('navOverlay');
    
    if (hamburgerBtn && navLinks) {
        hamburgerBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            navLinks.classList.toggle('active');
            if (navOverlay) navOverlay.classList.toggle('active');
            document.body.style.overflow = navLinks.classList.contains('active') ? 'hidden' : '';
        });
    }
    
    if (navOverlay) {
        navOverlay.addEventListener('click', function() {
            navLinks.classList.remove('active');
            navOverlay.classList.remove('active');
            document.body.style.overflow = '';
        });
    }
    
    // Close menu when clicking a nav link
    document.querySelectorAll('.nav-links a').forEach(link => {
        link.addEventListener('click', function() {
            navLinks.classList.remove('active');
            if (navOverlay) navOverlay.classList.remove('active');
            document.body.style.overflow = '';
        });
    });
});
</script>
<script src="../js/nav.js"></script>
<script>
history.pushState(null, null, location.href);
window.addEventListener('popstate', function() {
    window.location.href = 'login.php';
});
</script>
</body>
</html>