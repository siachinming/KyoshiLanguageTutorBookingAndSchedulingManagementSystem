<?php
session_start();
include 'config.php';

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
    SELECT SUM(amount) AS total
    FROM payments p
    JOIN bookings b ON p.booking_id = b.id
    WHERE b.tutor_id = ?
    AND p.status = 'verified'
");

$stmt->bind_param("i", $userID);
$stmt->execute();

$totalEarnings = $stmt->get_result()->fetch_assoc()['total'] ?? 0;

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

function e($value){
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Kyoshi Tutor Dashboard</title>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">

<style>

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

/* Dark transparent overlay */
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

/* BRAND */
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

/* NAVIGATION */
.nav-links{
    display:flex;
    gap: 28px;
    align-items: center;
    flex-wrap: wrap;
}

.nav-links a{
    text-decoration:none;
    color:#1d3156;
    font-size:14px;
    font-weight:600;
    position:relative;
    transition:0.25s;
    padding: 6px 0;
    white-space: nowrap;
}

.nav-links a:hover,
.nav-links a.active{
    color:#496894;
    font-weight:700;
}

.nav-links a::after{
    content:'';
    position:absolute;
    left:0;
    bottom:-6px;
    width:0%;
    height:3px;
    background:#496894;
    transition:0.25s;
    border-radius:10px;
}

.nav-links a:hover::after,
.nav-links .active::after{
    width:100%;
}

/* PROFILE BUTTON */
.profile{
    display:flex;
    align-items:center;
    gap:8px;
    background:rgba(255,255,255,0.12);
    border:1px solid rgba(255,255,255,0.2);
    padding:6px 14px 6px 8px;
    border-radius:40px;
    cursor:pointer;
    color:#1d3156;
    transition:0.25s;
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

/* DROPDOWN */
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

/* MAIN CONTENT CARDS - Glass effect */
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

    background:
    linear-gradient(
        135deg,
        rgba(29,49,86,0.92),
        rgba(73,104,148,0.88)
    );

    border-radius:32px;
    padding:42px;

    color:white;

    box-shadow:
    0 10px 30px rgba(0,0,0,0.12);

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

.hero{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:40px;
    flex-wrap:wrap;
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

/* SECTIONS */
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

/* QUICK BUTTONS */
.quick-grid{
    display:grid;
    grid-template-columns:repeat(auto-fit,minmax(210px,1fr));
    gap:20px;
}

.quick-btn{
    background:rgba(255,255,255,0.95);
    backdrop-filter: blur(5px);
    border:1px solid rgba(255,255,255,0.3);
    border-radius:24px;
    padding:22px 20px;
    text-align:left;
    cursor:pointer;
    transition:0.25s;
    box-shadow:0 2px 8px rgba(0,0,0,0.03);
}

.quick-btn:hover{
    transform:translateY(-4px);
    background:white;
    border-color:#cbd5e1;
    box-shadow:0 14px 30px rgba(0,0,0,0.08);
}

.quick-btn i{
    font-size:28px;
    margin-bottom:16px;
    display:block;
    color:#1d3156;
}

.quick-btn h4{
    margin-bottom:8px;
    font-size:17px;
    font-weight:700;
    color:#0f172a;
}

.quick-btn p{
    font-size:13px;
    line-height:1.5;
    color:#5b6e8c;
}

/* UPCOMING SESSIONS */
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

/* RESPONSIVE */
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
}

@media(max-width:720px){
    .hero{
        padding: 24px;
    }
    .hero h1{
        font-size: 24px;
    }
    .stats-grid{
        gap: 14px;
    }
    .nav-links{
        gap: 14px;
    }
    .nav-links a{
        font-size: 12px;
    }
}

/* QUICK ACTIONS */
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
    <a href="tutor_dashboard.php" class="active">Dashboard</a>
    <a href="booking_requests.php">My Bookings</a>
    <a href="material_overview.php">My Materials</a>
    <a href="assignment_overview.php">My Assignments</a>
</div>

    <div style="position:relative;">
        <button class="profile" onclick="toggleDropdown()">
            <img src="<?= e($profilePic) ?>">
            <span><?= e($displayName) ?></span>
            <i class="bi bi-chevron-down"></i>
        </button>
        <div class="dropdown" id="profileDropdown">
            <a href="tutor_profile.php">
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
</nav>
</div>
</header>
<!-- HERO -->
 <div class="main">
<div class="hero">

    <div class="hero-left">
        <h1>Welcome Back, <?= e($firstName) ?></h1>

        <p>
            Manage sessions, bookings, materials,
            and student interactions — all in one place.
        </p>
    </div>

    <div class="hero-stats">

        <div class="hero-card">
            <div class="hero-icon bg1">
                <i class="bi bi-calendar-check"></i>
            </div>

            <div>
                <span>Total Bookings</span>
                <h3><?= e($totalBookings) ?></h3>
            </div>
        </div>

        <div class="hero-card">
            <div class="hero-icon bg2">
                <i class="bi bi-hourglass-split"></i>
            </div>

            <div>
                <span>Pending Requests</span>
                <h3><?= e($pendingBookings) ?></h3>
            </div>
        </div>

        <div class="hero-card">
            <div class="hero-icon bg3">
                <i class="bi bi-book"></i>
            </div>

            <div>
                <span>Materials Uploaded</span>
                <h3><?= e($totalMaterials) ?></h3>
            </div>
        </div>

        <div class="hero-card">
            <div class="hero-icon bg4">
                <i class="bi bi-wallet2"></i>
            </div>

            <div>
                <span>Total Earnings</span>
                <h3>RM <?= number_format($totalEarnings,2) ?></h3>
            </div>
        </div>

    </div>

</div>

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
function toggleDropdown(){
    const dropdown = document.getElementById('profileDropdown');
    dropdown.style.display = dropdown.style.display === 'block' ? 'none' : 'block';
}

window.addEventListener('click', function(e){
    const dropdown = document.getElementById('profileDropdown');
    const button = document.querySelector('.profile');
    if(button && !button.contains(e.target) && dropdown && !dropdown.contains(e.target)){
        dropdown.style.display = 'none';
    }
});
</script>

</body>
</html>