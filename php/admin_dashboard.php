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

$totalTutors = $conn->query("SELECT COUNT(*) as count FROM users WHERE role = 'tutor'")->fetch_assoc()['count'];
$pendingTutors = $conn->query("SELECT COUNT(*) as count FROM users WHERE role = 'tutor' AND status = 'pending'")->fetch_assoc()['count'];
$approvedTutors = $conn->query("SELECT COUNT(*) as count FROM users WHERE role = 'tutor' AND (status = 'active' OR status = 'approved')")->fetch_assoc()['count'];
$rejectedTutors = $conn->query("SELECT COUNT(*) as count FROM users WHERE role = 'tutor' AND status = 'rejected'")->fetch_assoc()['count'];
$totalStudents = $conn->query("SELECT COUNT(*) as count FROM users WHERE role = 'student'")->fetch_assoc()['count'];
$totalBookings = $conn->query("SELECT COUNT(*) as count FROM bookings")->fetch_assoc()['count'];
$pendingBookings = $conn->query("SELECT COUNT(*) as count FROM bookings WHERE status = 'pending'")->fetch_assoc()['count'];
$confirmedBookings = $conn->query("SELECT COUNT(*) as count FROM bookings WHERE status = 'confirmed'")->fetch_assoc()['count'];
$completedBookings = $conn->query("SELECT COUNT(*) as count FROM bookings WHERE status = 'completed'")->fetch_assoc()['count'];
$cancelledBookings = $conn->query("SELECT COUNT(*) as count FROM bookings WHERE status = 'cancelled'")->fetch_assoc()['count'];

$totalPayments = $conn->query("SELECT COUNT(*) as count FROM payments")->fetch_assoc()['count'];
$verifiedPayments = $conn->query("SELECT COUNT(*) as count FROM payments WHERE status = 'verified'")->fetch_assoc()['count'];
$pendingPayments = $conn->query("SELECT COUNT(*) as count FROM payments WHERE status = 'pending'")->fetch_assoc()['count'];
$totalRevenue = $conn->query("SELECT SUM(amount) as total FROM payments WHERE status = 'verified'")->fetch_assoc()['total'] ?? 0;

$totalDisputes = $conn->query("SELECT COUNT(*) as count FROM disputes")->fetch_assoc()['count'];
$pendingDisputes = $conn->query("SELECT COUNT(*) as count FROM disputes WHERE status = 'pending'")->fetch_assoc()['count'];
$resolvedDisputes = $conn->query("SELECT COUNT(*) as count FROM disputes WHERE status = 'resolved'")->fetch_assoc()['count'];

$totalRatings = $conn->query("SELECT COUNT(*) as count FROM ratings")->fetch_assoc()['count'];
$avgRating = $conn->query("SELECT AVG(rating) as avg FROM ratings")->fetch_assoc()['avg'] ?? 0;

$totalPayouts = $conn->query("SELECT COUNT(*) as count FROM payout_requests")->fetch_assoc()['count'] ?? 0;
$pendingPayouts = $conn->query("SELECT COUNT(*) as count FROM payout_requests WHERE status = 'pending'")->fetch_assoc()['count'] ?? 0;

$monthlyData = [];

for ($i = 5; $i >= 0; $i--) {
    $monthKey = date('Y-m', strtotime("-$i months"));
    $monthName = date('M', strtotime("-$i months"));
    $yearDisplay = date('Y', strtotime("-$i months"));
    
    // Only show year if different from current year
    if ($yearDisplay != date('Y')) {
        $displayMonth = $monthName . " '" . substr($yearDisplay, -2);
    } else {
        $displayMonth = $monthName;
    }
    
    $count = $conn->query("SELECT COUNT(*) as c FROM bookings WHERE DATE_FORMAT(created_at, '%Y-%m') = '$monthKey'")->fetch_assoc()['c'];
    
    $monthlyData[] = [
        'month' => $displayMonth,
        'full_month' => $monthName,
        'count' => (int)$count
    ];
}

function e($value) {
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function formatMoney($amount) {
    return 'RM ' . number_format($amount, 2);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Kyoshi | Admin Dashboard</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600&family=Open+Sans&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
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

        .stat-left {
            display: flex;
            align-items: center;
            gap: 12px;
            flex: 1;
        }

        .stat-icon {
            width: 44px;
            height: 44px;
            background: rgba(135, 93, 156, 0.1);
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .stat-icon i {
            font-size: 22px;
            color: #875D9C;
        }

        .stat-info {
            display: flex;
            flex-direction: column;
        }

        .stat-label {
            font-size: 0.7rem;
            font-weight: 600;
            color: #7B6E8F;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .stat-sub {
            font-size: 0.65rem;
            color: #A59BB5;
            margin-top: 4px;
        }

        .stat-right {
            text-align: right;
        }

        .stat-value {
            font-size: 26px;
            font-weight: 800;
            color: #302E63;
            line-height: 1.2;
        }

        /* Alert with auto-dismiss animation - FIXED */
.alert-card {
    background: #FEF3C7;
    border-left: 4px solid #F59E0B;
    border-radius: 14px;
    padding: 14px 18px;
    margin-bottom: 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 12px;
    transition: opacity 0.5s ease, transform 0.3s ease;
    position: relative;
}

.alert-card.fade-out {
    opacity: 0;
    transform: translateY(-10px);
    pointer-events: none;
}

.alert-content {
    display: flex;
    align-items: center;
    gap: 12px;
    flex: 1;
}

.alert-content i {
    font-size: 22px;
    color: #F59E0B;
    flex-shrink: 0;
}

.alert-content strong {
    color: #92400E;
    font-size: 0.85rem;
}

.alert-content p {
    color: #B45309;
    font-size: 12px;
    margin-top: 2px;
}

.alert-actions {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-shrink: 0;
}

.alert-btn {
    background: #F59E0B;
    color: white;
    padding: 6px 18px;
    border-radius: 30px;
    text-decoration: none;
    font-weight: 600;
    font-size: 12px;
    white-space: nowrap;
}

.alert-close {
    background: transparent;
    border: none;
    font-size: 22px;
    cursor: pointer;
    color: #92400E;
    padding: 0 6px;
    font-weight: bold;
    opacity: 0.5;
    transition: opacity 0.2s;
    line-height: 1;
}

.alert-close:hover {
    opacity: 1;
}

/* Mobile responsive fix */
@media (max-width: 768px) {
    .alert-card {
        flex-direction: column;
        align-items: stretch;
    }
    
    .alert-actions {
        justify-content: flex-end;
        margin-top: 8px;
    }
    
    .alert-btn {
        text-align: center;
        flex: 1;
    }
}
        
        /* Charts Row - Responsive */
        .charts-row {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 24px;
            margin-bottom: 28px;
        }
        
        .chart-card {
            background: white;
            border-radius: 20px;
            padding: 18px;
            border: 1px solid #E4DCF0;
        }
        
        .chart-title {
            font-size: 0.85rem;
            font-weight: 700;
            color: #302E63;
            margin-bottom: 14px;
        }
        
        canvas {
            max-height: 240px;
            width: 100%;
        }
        
        /* Quick Stats Row - Responsive */
        .quick-stats {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 28px;
        }
        
        .quick-stat {
            background: white;
            border-radius: 14px;
            padding: 14px;
            text-align: center;
            border: 1px solid #E4DCF0;
        }
        
        .quick-stat .number {
            font-size: 20px;
            font-weight: 800;
            color: #875D9C;
        }
        
        .quick-stat .label {
            font-size: 0.65rem;
            color: #7B6E8F;
            margin-top: 4px;
        }
        
        .dropdown {
            position: absolute;
            top: calc(100% + 10px);
            right: 0;
            width: 200px;
            background: white;
            border-radius: 14px;
            overflow: hidden;
            display: none;
            border: 1px solid #E4DCF0;
            box-shadow: 0 15px 35px rgba(0,0,0,0.15);
            z-index: 1000;
        }
        
        .dropdown a {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 16px;
            text-decoration: none;
            color: #1E1B2E;
            font-size: 13px;
            font-weight: 500;
        }
        
        .dropdown a:hover {
            background: #F4F0F8;
        }
        
        .dropdown hr {
            border: none;
            border-top: 1px solid #E4DCF0;
            margin: 0;
        }
        
        .relative {
            position: relative;
        }
        
        footer {
            margin-top: 28px;
            text-align: center;
            font-size: 0.65rem;
            color: #A59BB5;
            border-top: 1px solid #E4DCF0;
            padding-top: 18px;
        }
        
        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 16px;
            }
            .quick-stats {
                grid-template-columns: repeat(2, 1fr);
                gap: 16px;
            }
        }
        
        @media (max-width: 768px) {
            .menu-toggle {
                display: block;
            }
            
            .sidebar {
                transform: translateX(-100%);
            }
            
            .sidebar.open {
                transform: translateX(0);
            }
            
            .main-content {
                margin-left: 0;
                padding: 16px;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 12px;
            }
            
            .charts-row {
                grid-template-columns: 1fr;
                gap: 16px;
            }
            
            .quick-stats {
                grid-template-columns: repeat(2, 1fr);
                gap: 12px;
            }
            
            .stat-card {
                padding: 14px;
            }
            
            .stat-value {
                font-size: 22px;
            }
            
            .stat-icon {
                width: 38px;
                height: 38px;
            }
            
            .stat-icon i {
                font-size: 18px;
            }
            
            .alert-card {
                padding: 12px 16px;
            }
            
            .alert-content {
                gap: 10px;
            }
            
            .page-title h1 {
                font-size: 1.2rem;
            }
        }
        
        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .quick-stats {
                grid-template-columns: 1fr 1fr;
            }
            
            .top-bar {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .admin-profile {
                align-self: flex-end;
            }
            
            .alert-card {
                flex-direction: column;
                text-align: center;
            }
            
            .alert-btn {
                width: 100%;
                text-align: center;
            }
            
            .alert-content {
                justify-content: center;
            }
        }

        .brand-wrapper {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .brand-icon {
            width: 60px;
            height: 60px;
            object-fit: contain;
        }

        .brand-title {
            display: flex;
            flex-direction: column;
        }

        .brand-title h1 {
            font-size: 1.4rem;
            font-weight: 700;
            background: linear-gradient(135deg, #ffffff, #B26EA7);
            background-clip: text;
            -webkit-background-clip: text;
            color: transparent;
            margin: 0;
            line-height: 1.2;
        }

        .admin-space-text {
            font-size: 0.6rem;
            color: #e7c7f7;
            letter-spacing: 0.5px;
            margin-top: 2px;
        }

        /* Mobile adjustments */
        @media (max-width: 768px) {
            .sidebar-header {
                padding: 20px 16px;
            }
            .brand-icon {
                width: 38px;
                height: 38px;
            }
            .brand-title h1 {
                font-size: 1.1rem;
            }
            .admin-space-text {
                font-size: 0.55rem;
            }
        }
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
        
        .sidebar-overlay.active {
            display: block;
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
    <!-- DASHBOARD -->
    <div class="nav-section">
        <a href="admin_dashboard.php" class="nav-item active">
            <i class="bi bi-speedometer2"></i><span>Dashboard</span>
        </a>
    </div>

    <!-- USERS -->
    <div class="nav-section">
        <div class="nav-section-label">
            USERS
        </div>
        <a href="admin_tutor_actions.php" class="nav-item">
    <i class="bi bi-person-badge"></i><span>Tutors</span>
            <span class="nav-badge"><?= $totalTutors ?></span>
        </a>
        <a href="admin_students.php" class="nav-item">
            <i class="bi bi-person"></i><span>Students</span>
            <span class="nav-badge"><?= $totalStudents ?></span>
        </a>
    </div>

    <!-- FINANCE -->
    <div class="nav-section">
        <div class="nav-section-label">
            FINANCE
        </div>
        <a href="admin_payments.php" class="nav-item">
            <i class="bi bi-credit-card"></i><span>Payments</span>
            <span class="nav-badge pending"><?= $pendingPayments ?></span>
        </a>
        <a href="admin_payouts.php" class="nav-item">
             <i class="bi bi-cash-stack"></i><span>Payouts</span>
            <span class="nav-badge"><?= $pendingPayouts ?></span>
        </a>
    </div>

    <!-- BOOKINGS -->
    <div class="nav-section">
        <div class="nav-section-label">
            BOOKINGS
        </div>
        <a href="admin_bookings.php" class="nav-item">
            <i class="bi bi-calendar-check"></i><span>Bookings</span>
            <span class="nav-badge"><?= $totalBookings ?></span>
        </a>
        <a href="admin_disputes.php" class="nav-item">
            <i class="bi bi-flag"></i><span>Disputes</span>
            <span class="nav-badge dispute"><?= $pendingDisputes ?></span>
        </a>
    </div>

    <!-- REPORTS -->
    <div class="nav-section">
        <div class="nav-section-label">
            REPORTS
        </div>
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
    <a href="logout.php" class="logout-icon" title="Logout">
        <i class="bi bi-box-arrow-right"></i>
    </a>
</div>
</aside>

<!-- Main Content -->
<div class="main-content" id="mainContent">
    <div class="top-bar">
        <button class="menu-toggle" id="menuToggle"><i class="bi bi-list"></i> Menu</button>
        <div class="page-title">
            <h1>Dashboard Overview</h1>
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

   <!-- Alerts for pending items -->
<?php if ($pendingTutors > 0): ?>
<div class="alert-card">
    <div class="alert-content">
        <i class="bi bi-person-plus"></i>
        <div>
            <strong><?= $pendingTutors ?> new tutor application<?= $pendingTutors > 1 ? 's' : '' ?></strong>
            <p>Review and approve qualified tutors to join the platform</p>
        </div>
    </div>
    <div class="alert-actions">
        <a href="admin_tutors.php" class="alert-btn">Review Now</a>
        <button class="alert-close" onclick="this.closest('.alert-card').classList.add('fade-out'); setTimeout(() => this.closest('.alert-card').remove(), 500);">&times;</button>
    </div>
</div>
<?php endif; ?>

<?php if ($pendingPayments > 0): ?>
<div class="alert-card">
    <div class="alert-content">
        <i class="bi bi-exclamation-triangle"></i>
        <div>
            <strong><?= $pendingPayments ?> pending payment verification<?= $pendingPayments > 1 ? 's' : '' ?></strong>
            <p>Manual payments need to be verified before sessions are confirmed</p>
        </div>
    </div>
    <div class="alert-actions">
        <a href="admin_payments.php" class="alert-btn">Verify Now</a>
        <button class="alert-close" onclick="this.closest('.alert-card').classList.add('fade-out'); setTimeout(() => this.closest('.alert-card').remove(), 500);">&times;</button>
    </div>
</div>
<?php endif; ?>

<?php if ($pendingDisputes > 0): ?>
<div class="alert-card">
    <div class="alert-content">
        <i class="bi bi-exclamation-triangle-fill"></i>
        <div>
            <strong><?= $pendingDisputes ?> open dispute<?= $pendingDisputes > 1 ? 's' : '' ?></strong>
            <p>Requires immediate attention for fair resolution</p>
        </div>
    </div>
    <div class="alert-actions">
        <a href="admin_disputes.php" class="alert-btn">View Disputes</a>
        <button class="alert-close" onclick="this.closest('.alert-card').classList.add('fade-out'); setTimeout(() => this.closest('.alert-card').remove(), 500);">&times;</button>
    </div>
</div>
<?php endif; ?>

    <!-- Main Stats Grid -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-left">
            <div class="stat-icon"><i class="bi bi-people"></i></div>
            <div class="stat-info">
                <div class="stat-label">Total Users</div>
                <div class="stat-sub"><?= $totalStudents ?> students · <?= $totalTutors ?> tutors</div>
            </div>
        </div>
        <div class="stat-right">
            <div class="stat-value"><?= $totalStudents + $totalTutors ?></div>
        </div>
    </div>
    
    <div class="stat-card">
    <div class="stat-left">
        <div class="stat-icon"><i class="bi bi-person-badge"></i></div>
        <div class="stat-info">
            <div class="stat-label">Approved Tutors</div>
            <div class="stat-sub">
                <?= $pendingTutors ?> pending · 
                <?= $rejectedTutors ?> rejected
            </div>
        </div>
    </div>
    <div class="stat-right">
        <div class="stat-value"><?= $approvedTutors ?></div>
    </div>
</div>
    
    <div class="stat-card">
        <div class="stat-left">
            <div class="stat-icon"><i class="bi bi-calendar-check"></i></div>
            <div class="stat-info">
                <div class="stat-label">Total Bookings</div>
                <div class="stat-sub"><?= $completedBookings ?> completed · <?= $pendingBookings ?> pending</div>
            </div>
        </div>
        <div class="stat-right">
            <div class="stat-value"><?= $totalBookings ?></div>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-left">
            <div class="stat-icon"><i class="bi bi-cash-stack"></i></div>
            <div class="stat-info">
                <div class="stat-label">Total Revenue</div>
                <div class="stat-sub"><?= $verifiedPayments ?> verified payments</div>
            </div>
        </div>
        <div class="stat-right">
            <div class="stat-value"><?= formatMoney($totalRevenue) ?></div>
        </div>
    </div>
</div>

    <!-- Charts Row -->
    <div class="charts-row">
        <div class="chart-card">
            <div class="chart-title">Monthly Bookings Trend</div>
            <canvas id="bookingsChart"></canvas>
        </div>
        <div class="chart-card">
            <div class="chart-title">Booking Status Overview</div>
            <canvas id="overviewChart"></canvas>
        </div>
    </div>

    <!-- Additional Stats -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon"><i class="bi bi-star-fill"></i></div>
            <div class="stat-value"><?= number_format($avgRating, 1) ?></div>
            <div class="stat-label">Average Tutor Rating</div>
            <div class="stat-sub">from <?= $totalRatings ?> reviews</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon"><i class="bi bi-flag"></i></div>
            <div class="stat-value"><?= $pendingDisputes ?></div>
            <div class="stat-label">Open Disputes</div>
            <div class="stat-sub"><?= $resolvedDisputes ?> resolved total</div>
        </div>
        <div class="stat-card">
        <div class="stat-icon"><i class="bi bi-clock-history"></i></div>
        <div class="stat-value"><?= $pendingPayments ?></div>
        <div class="stat-label">Payment Verification</div>
        <div class="stat-sub"><?= $verifiedPayments ?> verified</div>
    </div>
        <div class="stat-card">
            <div class="stat-icon"><i class="bi bi-cash"></i></div>
            <div class="stat-value"><?= $pendingPayouts ?></div>
            <div class="stat-label">Payout Requests</div>
            <div class="stat-sub"><?= $totalPayouts ?> total requests</div>
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

// Mobile menu toggle
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
// Monthly Bookings Chart - FIXED
const bookingsCtx = document.getElementById('bookingsChart')?.getContext('2d');
if (bookingsCtx) {
    const chartLabels = <?= json_encode(array_column($monthlyData, 'month')) ?>;
    const chartData = <?= json_encode(array_column($monthlyData, 'count')) ?>;
    
    new Chart(bookingsCtx, {
        type: 'line',
        data: {
            labels: chartLabels,
            datasets: [{
                label: 'Number of Bookings',
                data: chartData,
                borderColor: '#875D9C',
                backgroundColor: 'rgba(135, 93, 156, 0.1)',
                borderWidth: 3,
                fill: true,
                tension: 0.3,
                pointBackgroundColor: '#875D9C',
                pointBorderColor: '#fff',
                pointBorderWidth: 2,
                pointRadius: 5,
                pointHoverRadius: 7
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            scales: {
                y: {
                    beginAtZero: true,
                    title: {
                        display: true,
                        text: 'Number of Bookings',
                        font: { size: 11 }
                    },
                    ticks: {
                        stepSize: 1,
                        precision: 0
                    }
                },
                x: {
                    title: {
                        display: true,
                        text: 'Month',
                        font: { size: 11 }
                    }
                }
            },
            plugins: { 
                legend: { 
                    position: 'top', 
                    labels: { font: { size: 11 } } 
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            return context.raw + ' booking' + (context.raw !== 1 ? 's' : '');
                        }
                    }
                }
            }
        }
    });
}
// Overview Pie Chart
const overviewCtx = document.getElementById('overviewChart')?.getContext('2d');
if (overviewCtx) {
    new Chart(overviewCtx, {
        type: 'doughnut',
        data: {
            labels: ['Completed Sessions', 'Pending', 'Cancelled'],
            datasets: [{
                data: [<?= $completedBookings ?>, <?= $pendingBookings + $confirmedBookings ?>, <?= $cancelledBookings ?>],
                backgroundColor: ['#28A745', '#F59E0B', '#DC2626'],
                borderWidth: 0
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            plugins: { 
                legend: { position: 'bottom', labels: { font: { size: 11 } } }
            }
        }
    });
}

// Close sidebar on window resize if screen becomes larger
window.addEventListener('resize', function() {
    if (window.innerWidth > 768) {
        sidebar.classList.remove('open');
        if (overlay) overlay.classList.remove('active');
        document.body.style.overflow = '';
    }
});

// Auto-dismiss alerts after 5 seconds
function setupAutoDismissAlerts() {
    const alerts = document.querySelectorAll('.alert-card');
    
    alerts.forEach(alert => {
        // Set timeout to fade out after 5 seconds
        setTimeout(() => {
            alert.classList.add('fade-out');
            // Remove from DOM after animation completes
            setTimeout(() => {
                if (alert.parentNode) {
                    alert.remove();
                }
            }, 500);
        }, 5000); // 5 seconds
    });
}

// Add close button to each alert (optional)
function addCloseButtonToAlerts() {
    const alerts = document.querySelectorAll('.alert-card');
    
    alerts.forEach(alert => {
        // Check if close button already exists
        if (!alert.querySelector('.alert-close')) {
            const closeBtn = document.createElement('button');
            closeBtn.innerHTML = '&times;';
            closeBtn.className = 'alert-close';
            closeBtn.style.cssText = `
                background: transparent;
                border: none;
                font-size: 20px;
                cursor: pointer;
                color: #92400E;
                padding: 0 8px;
                font-weight: bold;
                opacity: 0.6;
                transition: opacity 0.2s;
            `;
            closeBtn.onmouseover = () => closeBtn.style.opacity = '1';
            closeBtn.onmouseout = () => closeBtn.style.opacity = '0.6';
            closeBtn.onclick = () => {
                alert.classList.add('fade-out');
                setTimeout(() => alert.remove(), 500);
            };
            alert.appendChild(closeBtn);
        }
    });
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    setupAutoDismissAlerts();
    addCloseButtonToAlerts();
});
</script>

</body>
</html>