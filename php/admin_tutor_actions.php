<?php
session_start();
include 'config.php';

$assetBase = '../assets/img';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

$adminID = $_SESSION['user_id'];

// Get admin info
$stmt = $conn->prepare("SELECT * FROM users WHERE id = ? AND role = 'admin'");
$stmt->bind_param("i", $adminID);
$stmt->execute();
$admin = $stmt->get_result()->fetch_assoc();

if (!$admin) {
    header("Location: login.php");
    exit();
}

$displayName = $admin['fullname'];
$profilePic = !empty($admin['profile_pic'])
    ? '../uploads/profiles/' . $admin['profile_pic']
    : $assetBase . '/profile-admin.png';

// Get counts
$pendingTutors = $conn->query("SELECT COUNT(*) as count FROM users WHERE role = 'tutor' AND status = 'pending'")->fetch_assoc()['count'];
$totalTutors = $conn->query("SELECT COUNT(*) as count FROM users WHERE role = 'tutor'")->fetch_assoc()['count'];
$totalStudents = $conn->query("SELECT COUNT(*) as count FROM users WHERE role = 'student'")->fetch_assoc()['count'];
$pendingPayments = $conn->query("SELECT COUNT(*) as count FROM payments WHERE status = 'pending'")->fetch_assoc()['count'];
$pendingDisputes = $conn->query("SELECT COUNT(*) as count FROM disputes WHERE status = 'pending'")->fetch_assoc()['count'];
$pendingPayouts = $conn->query("SELECT COUNT(*) as count FROM payout_requests WHERE status = 'pending'")->fetch_assoc()['count'] ?? 0;
$pendingQualifications = $conn->query("
    SELECT COUNT(*) as count 
    FROM tutor_certificates tc
    JOIN users u ON tc.tutor_id = u.id
    WHERE tc.status = 'pending' 
    AND u.status = 'approved'  -- Only show from approved tutors
")->fetch_assoc()['count'];
$totalReviews = $conn->query("SELECT COUNT(*) as count FROM ratings")->fetch_assoc()['count'];

function e($value) {
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kyoshi | Tutor Actions</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600&family=Open+Sans&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: "Montserrat", "Open Sans", sans-serif;
            background: url('../assets/img/background3.jpg') no-repeat center top;
            background-size: cover;
            min-height: 100vh;
            position: relative;
            color: #1E1B2E;
            line-height: 1.45;
            overflow-x: hidden;
        }
        
        body::before {
            content: "";
            position: fixed;
            inset: 0;
            pointer-events: none;
            z-index: -1;
            background: radial-gradient(circle at 7% 10%, rgba(231,90,155,.32), transparent 24%),
                        radial-gradient(circle at 90% 8%, rgba(255,195,216,.42), transparent 26%);
        }

       
        .sidebar {
            position: fixed;
            left: 0;
            top: 0;
            width: 230px;
            height: 100%;
            background: #272754;
            color: #E8E4F0;
            overflow-y: auto;
            z-index: 1000;
            transition: transform 0.3s ease;
            transform: translateX(0);
            display:flex;
            flex-direction: column;
        }
        
        .sidebar.closed {
            transform: translateX(-100%);
        }
        
        .sidebar-header {
            padding: 28px 20px;
            flex-shrink: 0;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        
        
        .sidebar-header p {
            font-size: 0.65rem;
            color: rgba(255,255,255,0.5);
            margin-top: 4px;
        }
        
        .nav-menu {
            padding: 16px 0;
            flex:1;
            justify-content: center;
            flex-direction: column;
            display:flex;
            min-height:0;
        }
        
        .nav-item {
            padding: 10px 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            color: #D4CFE8;
            text-decoration: none;
            transition: all 0.2s;
            border-left: 3px solid transparent;
            font-size: 0.85rem;
        }
        
        .nav-item:hover {
            background: rgba(255,255,255,0.08);
            color: white;
        }
        
        .nav-item.active {
            background: rgba(255,255,255,0.1);
            border-left-color: #B26EA7;
            color: white;
        }
        
        .nav-item i {
            width: 20px;
            font-size: 1.1rem;
        }
        /* Sidebar Section Labels */
        .nav-section {
            margin-bottom: 8px;
        }

        .nav-section-label {
            padding: 12px 20px 6px 20px;
            font-size: 0.65rem;
            font-weight: 600;
            color: #B26EA7;
            text-transform: uppercase;
            letter-spacing: 1px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .nav-section-label i {
            font-size: 0.7rem;
            color: #B26EA7;
        }

        .nav-badge {
            margin-left: auto;
            font-size: 0.65rem;
            background: rgba(178, 110, 167, 0.25);
            padding: 2px 8px;
            border-radius: 30px;
            color: #D4CFE8;
            font-weight: 600;
        }

        .nav-badge.pending {
            background: rgba(245, 158, 11, 0.25);
            color: #F59E0B;
        }

        .nav-badge.dispute {
            background: rgba(220, 38, 38, 0.25);
            color: #FFA3A3;
        }

        .nav-item {
            position: relative;
        }
        
        /* Main Content */
        .main-content {
            margin-left: 220px;
            padding: 20px 24px;
            transition: margin-left 0.3s ease;
        }
        
        .main-content.expanded {
            margin-left: 0;
        }
        
        .top-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 16px;
            margin-bottom: 24px;
            padding-bottom: 16px;
        }
        
        .page-title h1 {
            font-size: 1.4rem;
            font-weight: 700;
            color: #302E63;
        }
        
        .page-title p {
            font-size: 0.75rem;
            color: #7B6E8F;
            margin-top: 4px;
        }
        
        .menu-toggle {
            background: #272754;
            color: white;
            border: none;
            padding: 8px 12px;
            border-radius: 10px;
            cursor: pointer;
            display: none;
            font-size: 1.1rem;
        }
        
        .admin-profile {
            display: flex;
            align-items: center;
            gap: 10px;
            background: white;
            padding: 6px 14px 6px 10px;
            border-radius: 50px;
            cursor: pointer;
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
            border: 1px solid #E4DCF0;
        }
        
        .admin-profile img {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            object-fit: cover;
        }
        
        .admin-profile span {
            font-weight: 600;
            font-size: 0.8rem;
            color: #302E63;
        }
        
        .admin-profile i {
            font-size: 11px;
            color: #A59BB5;
        }
        
        /* Stats Grid - Responsive */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 28px;
        }
        
        .stat-card {
            background: white;
            border-radius: 20px;
            padding: 18px;
            border: 1px solid #E4DCF0;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(48, 46, 99, 0.08);
        }

       .sidebar-footer {
            padding: 20px;
            border-top: 1px solid rgba(255, 255, 255, 0.15);
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }

        .admin-info {
            display: flex;
            align-items: center;
            gap: 12px;
            flex: 1;
            overflow: hidden;
        }

        .footer-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid rgba(255,255,255,0.2);
        }

        .admin-details {
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .admin-name {
            font-size: 0.8rem;
            font-weight: 600;
            color: white;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .admin-role {
            font-size: 0.6rem;
            color: #B26EA7;
            margin-top: 2px;
        }

        .logout-icon {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 36px;
            height: 36px;
            background: rgba(220, 38, 38, 0.15);
            border-radius: 10px;
            color: #FFA3A3;
            text-decoration: none;
            transition: all 0.2s;
            flex-shrink: 0;
        }

        .logout-icon:hover {
            background: rgba(220, 38, 38, 0.4);
            color: white;
            transform: scale(1.05);
        }

        .logout-icon i {
            font-size: 1.2rem;
        }

        
        .nav-badge {
            margin-left: auto;
            font-size: 0.65rem;
            background: rgba(178, 110, 167, 0.25);
            padding: 2px 8px;
            border-radius: 30px;
            color: #D4CFE8;
            font-weight: 600;
        }
        
        .nav-badge.pending { background: rgba(245, 158, 11, 0.25); color: #F59E0B; }
        .nav-badge.dispute { background: rgba(220, 38, 38, 0.25); color: #FFA3A3; }
        
        /* Main Content */
        .main-content {
            margin-left: 230px;
            padding: 20px 24px;
            transition: margin-left 0.3s ease;
        }
        
        .top-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 16px;
            margin-bottom: 20px;
        }
        
        .page-title h1 {
            font-size: 1.4rem;
            font-weight: 700;
            color: #302E63;
            text-align:center;
        }
        
        .page-title p {
            font-size: 0.75rem;
            color: #7B6E8F;
            margin-top: 4px;
        }
        
        .menu-toggle {
            background: #272754;
            color: white;
            border: none;
            padding: 8px 12px;
            border-radius: 10px;
            cursor: pointer;
            display: none;
        }
        
        .admin-profile {
            display: flex;
            align-items: center;
            gap: 10px;
            background: white;
            padding: 6px 14px 6px 10px;
            border-radius: 50px;
            cursor: pointer;
            border: 1px solid #E4DCF0;
        }
        
        .admin-profile img {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            object-fit: cover;
        }
        
        .admin-profile span { font-weight: 600; font-size: 0.8rem; color: #302E63; }
        
        .admin-info {
            display: flex;
            align-items: center;
            gap: 10px;
            flex: 1;
            overflow: hidden;
        }
        
        .footer-avatar {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid rgba(255,255,255,0.2);
        }
        
        .admin-name {
            font-size: 0.75rem;
            font-weight: 600;
            color: white;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .logout-icon {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 32px;
            height: 32px;
            background: rgba(220, 38, 38, 0.15);
            border-radius: 8px;
            color: #FFA3A3;
            text-decoration: none;
        }
        
        .logout-icon:hover { background: rgba(220, 38, 38, 0.4); color: white; }
        
        .brand-wrapper {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .brand-icon {
            width: 40px;
            height: 40px;
            object-fit: contain;
        }
        
        .brand-title h1 {
            font-size: 1.2rem;
            font-weight: 700;
            background: linear-gradient(135deg, #ffffff, #B26EA7);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }
        
        .admin-space-text { font-size: 0.5rem; color: #e7c7f7; }
        
        .relative { position: relative; }
        
        .dropdown {
            position: absolute;
            top: calc(100% + 10px);
            right: 0;
            width: 180px;
            background: white;
            border-radius: 12px;
            overflow: hidden;
            display: none;
            border: 1px solid #E4DCF0;
            box-shadow: 0 15px 35px rgba(0,0,0,0.15);
            z-index: 1000;
        }
        
        .dropdown a {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 16px;
            text-decoration: none;
            color: #1E1B2E;
            font-size: 12px;
        }
        
        .dropdown a:hover { background: #F4F0F8; }
        .dropdown hr { margin: 0; border-color: #E4DCF0; }
        
        .sidebar-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(126, 96, 223, 0.5);
            z-index: 999;
            display: none;
        }
        
        .sidebar-overlay.active { display: block; }
        
        /* Cards Grid - CENTERED, 2x3 */
        .actions-wrapper {
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: calc(100vh - 200px);
        }
        
        .actions-grid {
            display: grid;
            grid-template-columns: repeat(2, 280px);
            gap: 24px;
            margin: 0 auto;
        }
        
        .action-card {
            background: white;
            border-radius: 20px;
            padding: 24px 20px;
            transition: all 0.2s;
            text-decoration: none;
            color: #1E1B2E;
            align-items:center;
            display: flex;
            flex-direction: column;
            box-shadow: 0 8px 25px rgba(0,0,0,0.08);
            border: 1px solid #eee;
        }
        
        .action-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 15px 35px rgba(0,0,0,0.1);
            border-color: #E75A9B;
        }
        
        .card-icon {
            width: 50px;
            height: 50px;
            background: #FFF1F6;
            border-radius: 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 16px;
        }
        
        .card-icon i {
            align-items: center;
            font-size: 24px;
            color: #E75A9B;
        }
        
        /* Card Badge - shows number on the card icon */
        .card-icon {
            width: 50px;
            height: 50px;
            background: #FFF1F6;
            border-radius: 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 16px;
            position: relative;
        }

        .card-icon i {
            align-items: center;
            font-size: 24px;
            color: #E75A9B;
        }

        /* Badge styles */
        .card-badge {
            position: absolute;
            top: -8px;
            right: -8px;
            background: #E75A9B;
            color: white;
            font-size: 10px;
            font-weight: 700;
            padding: 2px 6px;
            border-radius: 20px;
            min-width: 20px;
            text-align: center;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .card-badge.pending {
            background: #F59E0B;
        }

        .card-badge.dispute {
            background: #DC2626;
        }

        .card-badge.success {
            background: #10B981;
        }

        .card-badge.primary {
            background: #3B82F6;
        }
                .card-title {
            text-align: center;
            font-size: 1rem;
            font-weight: 800;
            margin-bottom: 8px;
            color: #1E1B2E;
        }
        
        .card-desc {
            text-align: center;
            font-size: 0.7rem;
            color: #7B6178;
            line-height: 1.4;
        }
        
        /* Alert */
        .alert-card {
            background: #FEF3C7;
            border-left: 4px solid #F59E0B;
            border-radius: 12px;
            padding: 12px 16px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
        }
        
        .alert-content {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }
        
        .alert-content i { font-size: 20px; color: #F59E0B; }
        .alert-content strong { color: #92400E; font-size: 0.8rem; }
        .alert-content p { color: #B45309; font-size: 11px; margin-top: 2px; }
        
        .alert-btn {
            background: #F59E0B;
            color: white;
            padding: 5px 16px;
            border-radius: 25px;
            text-decoration: none;
            font-weight: 600;
            font-size: 11px;
        }
        
        @media (max-width: 700px) {
            .actions-grid {
                grid-template-columns: 1fr;
                gap: 16px;
            }
            .actions-wrapper {
                min-height: auto;
            }
        }
        
        @media (max-width: 768px) {
            .menu-toggle { display: block; }
            .sidebar { transform: translateX(-100%); }
            .sidebar.open { transform: translateX(0); }
            .main-content { margin-left: 0; padding: 16px; }
        }
    </style>
</head>
<body>

<div class="sidebar-overlay" id="sidebarOverlay"></div>
<aside class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <div class="brand-wrapper">
            <img src="<?= e($assetBase) ?>/logo.png" alt="Kyoshi" class="brand-icon">
            <div class="brand-title">
                <h1>Kyoshi</h1>
                <span class="admin-space-text">Admin Space</span>
            </div>
        </div>
    </div>
    <nav class="nav-menu">
        <div class="nav-section">
            <div class="nav-section-label">MAIN</div>
            <a href="admin_dashboard.php" class="nav-item">
                <i class="bi bi-speedometer2"></i><span>Dashboard</span>
            </a>
        </div>
        <div class="nav-section">
            <div class="nav-section-label">USERS</div>
            <a href="admin_tutors.php" class="nav-item active">
                <i class="bi bi-person-badge"></i><span>Tutors</span>
                <span class="nav-badge"><?= $totalTutors ?></span>
            </a>
            <a href="admin_students.php" class="nav-item">
                <i class="bi bi-person"></i><span>Students</span>
                <span class="nav-badge"><?= $totalStudents ?></span>
            </a>
        </div>
        <div class="nav-section">
            <div class="nav-section-label">FINANCE</div>
            <a href="admin_payments.php" class="nav-item">
                <i class="bi bi-credit-card"></i><span>Payments</span>
                <span class="nav-badge pending"><?= $pendingPayments ?></span>
            </a>
            <a href="admin_payouts.php" class="nav-item">
                <i class="bi bi-cash-stack"></i><span>Payouts</span>
                <span class="nav-badge"><?= $pendingPayouts ?></span>
            </a>
        </div>
        <div class="nav-section">
            <div class="nav-section-label">BOOKINGS</div>
            <a href="admin_bookings.php" class="nav-item">
                <i class="bi bi-calendar-check"></i><span>Bookings</span>
            </a>
            <a href="admin_disputes.php" class="nav-item">
                <i class="bi bi-flag"></i><span>Disputes</span>
                <span class="nav-badge dispute"><?= $pendingDisputes ?></span>
            </a>
        </div>
        <div class="nav-section">
            <div class="nav-section-label">REPORTS</div>
            <a href="admin_reports.php" class="nav-item">
                <i class="bi bi-graph-up"></i><span>Analytics</span>
            </a>
        </div>
    </nav>
    <div class="sidebar-footer">
        <div class="admin-info">
            <img src="<?= e($profilePic) ?>" alt="Admin" class="footer-avatar">
            <div class="admin-details">
                <span class="admin-name"><?= e($displayName) ?></span>
            </div>
        </div>
        <a href="logout.php" class="logout-icon"><i class="bi bi-box-arrow-right"></i></a>
    </div>
</aside>

<div class="main-content" id="mainContent">
    <div class="top-bar">
        <button class="menu-toggle" id="menuToggle"><i class="bi bi-list"></i> Menu</button>
        <div class="page-title">
            <h1>Tutor Actions</h1>
        </div>
        <div class="relative">
            <button class="admin-profile" onclick="toggleDropdown()">
                <img src="<?= e($profilePic) ?>" alt="Admin">
                <span><?= e($displayName) ?></span>
                <i class="bi bi-chevron-down"></i>
            </button>
            <div class="dropdown" id="profileDropdown">
                <a href="admin_profile.php"><i class="bi bi-person-circle"></i> My Profile</a>
                <hr>
                <a href="logout.php" style="color:#dc2626;"><i class="bi bi-box-arrow-right"></i> Logout</a>
            </div>
        </div>
    </div>


    <div class="actions-wrapper">
    <div class="actions-grid">
        
        <a href="admin_verify_tutors.php" class="action-card">
            <div class="card-icon">
                <i class="bi bi-person-check"></i>
                <?php if ($pendingTutors > 0): ?>
                    <span class="card-badge"><?= $pendingTutors ?></span>
                <?php endif; ?>
            </div>
            <div class="card-title">Verify Tutors</div>
            <div class="card-desc">Approve or reject new tutor applications</div>
        </a>

        <a href="admin_manage_reviews.php" class="action-card">
            <div class="card-icon">
                <i class="bi bi-chat-dots"></i>
                <?php if ($totalReviews > 0): ?>
                    <span class="card-badge"><?= $totalReviews ?></span>
                <?php endif; ?>
            </div>
            <div class="card-title">Manage Reviews</div>
            <div class="card-desc">View and delete tutor reviews</div>
        </a>

        <a href="admin_manage_qualifications.php" class="action-card">
            <div class="card-icon">
                <i class="bi bi-file-earmark-text"></i>
                <?php if ($pendingQualifications > 0): ?>
                    <span class="card-badge pending"><?= $pendingQualifications ?></span>
                <?php endif; ?>
            </div>
            <div class="card-title">Add New Qualifications</div>
            <div class="card-desc">View and verify tutor uploaded certificates</div>
        </a>

        <a href="admin_payouts.php" class="action-card">
            <div class="card-icon">
                <i class="bi bi-cash-stack"></i>
                <?php if ($pendingPayouts > 0): ?>
                    <span class="card-badge pending"><?= $pendingPayouts ?></span>
                <?php endif; ?>
            </div>
            <div class="card-title">Payout Requests</div>
            <div class="card-desc">Review and process tutor payout requests</div>
        </a>

        <a href="admin_create_tutor.php" class="action-card">
            <div class="card-icon">
                <i class="bi bi-person-plus"></i>
            </div>
            <div class="card-title">Create Tutor Account</div>
            <div class="card-desc">Manually create new tutor account</div>
        </a>

        <a href="admin_all_tutors.php" class="action-card">
            <div class="card-icon">
                <i class="bi bi-people"></i>
            </div>
            <div class="card-title">Manage & Edit Tutors</div>
            <div class="card-desc">View all tutors, edit profiles, update status</div>
        </a>

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
    const button = document.querySelector('.admin-profile');
    if (button && !button.contains(e.target) && dropdown && !dropdown.contains(e.target)) {
        dropdown.style.display = 'none';
    }
});

const menuToggle = document.getElementById('menuToggle');
const sidebar = document.getElementById('sidebar');
const overlay = document.getElementById('sidebarOverlay');

if (menuToggle) {
    menuToggle.addEventListener('click', function() {
        sidebar.classList.toggle('open');
        overlay.classList.toggle('active');
        document.body.style.overflow = sidebar.classList.contains('open') ? 'hidden' : '';
    });
}

if (overlay) {
    overlay.addEventListener('click', function() {
        sidebar.classList.remove('open');
        overlay.classList.remove('active');
        document.body.style.overflow = '';
    });
}
</script>

</body>
</html>