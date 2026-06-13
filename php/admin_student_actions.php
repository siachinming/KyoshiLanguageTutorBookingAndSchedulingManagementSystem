<?php
session_start();
include 'config.php';
include 'check_login.php';
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
$totalStudents = $conn->query("SELECT COUNT(*) as count FROM users WHERE role = 'student'")->fetch_assoc()['count'];
$totalBookings = $conn->query("SELECT COUNT(*) as count FROM bookings")->fetch_assoc()['count'];
$pendingPayments = $conn->query("SELECT COUNT(*) as count FROM payments WHERE status = 'pending'")->fetch_assoc()['count'];
$pendingDisputes = $conn->query("SELECT COUNT(*) as count FROM disputes WHERE status = 'pending' AND dispute_type != 'wrong_materials'")->fetch_assoc()['count'];
$pendingPayouts = $conn->query("SELECT COUNT(*) as count FROM payout_requests WHERE status = 'pending'")->fetch_assoc()['count'] ?? 0;
$totalTutors = $conn->query("SELECT COUNT(*) as count FROM users WHERE role = 'tutor'")->fetch_assoc()['count'];
$paymentDisputes = $conn->query("SELECT COUNT(*) as count FROM payments WHERE status = 'disputed'")->fetch_assoc()['count'] ?? 0;
function e($value) {
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
    <title>Kyoshi | Student Actions</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="../css/astyle.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600&family=Open+Sans&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: 'Montserrat', 'Open Sans', sans-serif;
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

        /* ========== SIDEBAR STYLES ========== */
        .sidebar {
            position: fixed;
            left: 0;
            top: 0;
            width: 230px;
            height: 100vh;
            background: #272754;
            color: #E8E4F0;
            overflow-y: hidden;
            z-index: 1000;
            transition: transform 0.3s ease;
            transform: translateX(0);
            display: flex;
            flex-direction: column;
        }
        
        .sidebar.closed { transform: translateX(-100%); }
        
        .sidebar-header {
            padding: 28px 20px;
            flex-shrink: 0;
            border-bottom: 1px solid rgba(255,255,255,0.1);
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
        
        .nav-menu {
            padding: 16px 0;
            flex: 1;
            overflow-y: auto;
            min-height: 0;
        }
        
        .nav-menu::-webkit-scrollbar {
            width: 3px;
        }
        
        .nav-menu::-webkit-scrollbar-track {
            background: rgba(255,255,255,0.1);
        }
        
        .nav-menu::-webkit-scrollbar-thumb {
            background: #B26EA7;
            border-radius: 3px;
        }
        
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
        
        .sidebar-footer {
            padding: 20px;
            border-top: 1px solid rgba(255,255,255,0.15);
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
        
        /* ========== MAIN CONTENT STYLES ========== */
        .main-content {
            margin-left: 230px;
            padding: 20px 24px;
            transition: margin-left 0.3s ease;
            height: 100vh;
            overflow-y: auto;
        }
        
        .main-content::-webkit-scrollbar {
            width: 8px;
        }
        
        .main-content::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 10px;
        }
        
        .main-content::-webkit-scrollbar-thumb {
            background: #E75A9B;
            border-radius: 10px;
        }
        
        .top-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 16px;
            margin-bottom: 24px;
        }
        
        .page-title h1 {
            font-size: 1.4rem;
            font-weight: 700;
            color: #302E63;
        }
        
        .page-title p {
            font-size: 12px;
            color: #64748b;
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
        
        .relative {
            position: relative;
        }
        
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
        
        .dropdown a:hover {
            background: #F4F0F8;
        }
        
        .dropdown hr {
            margin: 0;
            border-color: #E4DCF0;
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
        
        /* ========== ACTIONS GRID ========== */
        .actions-wrapper {
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: calc(100vh - 200px);
        }
        
        .actions-grid {
            display: grid;
            grid-template-columns: repeat(3, 280px);
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
            display: flex;
            flex-direction: column;
            align-items: center;
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
            position: relative;
        }
        
        .card-icon i {
            font-size: 24px;
            color: #E75A9B;
        }
        
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
        
        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
            border-radius: 16px;
            padding: 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }
        
        .stat-info h3 {
            font-size: 28px;
            font-weight: 700;
            color: #302E63;
        }
        
        .stat-info p {
            color: #666;
            font-size: 12px;
            margin-top: 5px;
        }
        
        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
        }
        
        /* ========== RESPONSIVE ========== */
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
            
            .actions-grid {
                grid-template-columns: 1fr;
                gap: 16px;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .actions-wrapper {
                min-height: auto;
            }
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
                <h1>KYOSHI</h1>
                <span class="admin-space-text">Admin Space</span>
            </div>
        </div>
    </div>
    <nav class="nav-menu">
        <div class="nav-section">
            <a href="admin_dashboard.php" class="nav-item">
                <i class="bi bi-speedometer2"></i><span>Dashboard</span>
            </a>
        </div>
        <div class="nav-section">
            <div class="nav-section-label">USERS</div>
            <a href="admin_tutor_actions.php" class="nav-item">
                <i class="bi bi-person-badge"></i><span>Tutors</span>
                <span class="nav-badge"><?= $totalTutors ?></span>
            </a>
            <a href="admin_student_actions.php" class="nav-item active">
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
                <span class="nav-badge"><?= $totalBookings ?></span>
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
    <button class="menu-toggle" id="menuToggle"><i class="bi bi-list"></i></button>
    
    <!-- Mobile Logo (visible only on mobile) -->
    <div class="mobile-logo">
        <img src="<?= e($assetBase) ?>/logo.png" alt="Kyoshi" href="admin_dashboard.php" class="mobile-logo-img">
        <span class="mobile-logo-text">KYOSHI</span>
    </div>
    
    <!-- Desktop Page Title (visible only on desktop) -->
    <div class="page-title">
        <h1>Student Actions</h1>
    </div>
    <div class="relative">
    <!-- Desktop Admin Profile -->
    <div class="admin-profile" onclick="toggleDropdown()">
        <img src="<?= e($profilePic) ?>" alt="Admin">
        <span><?= e($displayName) ?></span>
        <i class="bi bi-chevron-down"></i>
    </div>
    
    <!-- Mobile Profile Button -->
    <div class="mobile-profile-btn" onclick="toggleDropdown()">
        <img src="<?= e($profilePic) ?>" alt="Admin" class="mobile-profile-img">
    </div>
    
    <div class="dropdown" id="profileDropdown">
        <a href="admin_profile.php"><i class="bi bi-person-circle"></i> My Profile</a>
        <hr>
        <a href="logout.php" style="color:#dc2626;"><i class="bi bi-box-arrow-right"></i> Logout</a>
    </div>
</div>
</div>

<!-- Mobile Page Header (visible only on mobile) -->
<div class="mobile-page-header">
    <h1 class="mobile-page-title">Student Actions</h1>
</div>
    <div class="actions-wrapper">
        <div class="actions-grid">
            
            <!-- 1. Verify Manual Payments -->
            <a href="admin_payments.php?status=pending" class="action-card">
                <div class="card-icon">
                    <i class="bi bi-credit-card"></i>
                    <?php if ($pendingPayments > 0): ?>
                        <span class="card-badge pending"><?= $pendingPayments ?></span>
                    <?php endif; ?>
                </div>
                <div class="card-title">Verify Manual Payments</div>
                <div class="card-desc">Verify student uploaded bank transfer/DuitNow payment proofs</div>
            </a>

            <a href="admin_disputes.php" class="action-card">
                <div class="card-icon">
                    <i class="bi bi-exclamation-triangle"></i>
                    <?php 
                    $totalActiveDisputes = $pendingDisputes;
                    if ($totalActiveDisputes > 0): 
                    ?>
                        <span class="card-badge dispute"><?= $totalActiveDisputes ?></span>
                    <?php endif; ?>
                </div>
                <div class="card-title">Verify Disputes</div>
                <div class="card-desc">Handle payment disputes & booking issues (no-show only)</div>
            </a>

            <!-- 3. Create Student Account -->
            <a href="admin_create_user.php?return_to=admin_student_actions.php&role=student" class="action-card">
                <div class="card-icon">
                    <i class="bi bi-person-plus"></i>
                </div>
                <div class="card-title">Create Student Account</div>
                <div class="card-desc">Manually create new student account</div>
            </a>

            <!-- 4. Manage & Edit Students -->
            <a href="admin_all_users.php?role=student" class="action-card">
                <div class="card-icon">
                    <i class="bi bi-people"></i>
                </div>
                <div class="card-title">Manage & Edit Students</div>
                <div class="card-desc">View all students, edit profiles, update status</div>
            </a>

            <!-- 5. Student Bookings -->
            <a href="admin_bookings.php?role=student" class="action-card">
                <div class="card-icon">
                    <i class="bi bi-calendar-check"></i>
                </div>
                <div class="card-title">Student Bookings</div>
                <div class="card-desc">View and manage all student bookings</div>
            </a>

        </div>
    </div>
</div>

<script>
function toggleDropdown() {
    const dropdown = document.getElementById('profileDropdown');
    if (!dropdown) return;
    
    if (dropdown.style.display === 'block') {
        dropdown.style.display = 'none';
        dropdown.classList.remove('show');
    } else {
        dropdown.style.display = 'block';
        dropdown.classList.add('show');
    }
}

// Close dropdown when clicking outside
document.addEventListener('click', function(e) {
    const dropdown = document.getElementById('profileDropdown');
    const mobileProfileBtn = document.querySelector('.mobile-profile-btn');
    const desktopProfile = document.querySelector('.admin-profile');
    
    if (!dropdown) return;
    
    const isClickOnMobileBtn = mobileProfileBtn && mobileProfileBtn.contains(e.target);
    const isClickOnDesktop = desktopProfile && desktopProfile.contains(e.target);
    const isClickInsideDropdown = dropdown.contains(e.target);
    
    if (!isClickOnMobileBtn && !isClickOnDesktop && !isClickInsideDropdown) {
        dropdown.style.display = 'none';
        dropdown.classList.remove('show');
    }
});

// Prevent dropdown from closing when clicking inside it
const dropdownEl = document.getElementById('profileDropdown');
if (dropdownEl) {
    dropdownEl.addEventListener('click', function(e) {
        e.stopPropagation();
    });
}

// Close dropdown on Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        const dropdown = document.getElementById('profileDropdown');
        if (dropdown) {
            dropdown.style.display = 'none';
            dropdown.classList.remove('show');
        }
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
<script>
history.pushState(null, null, location.href);
window.addEventListener('popstate', function() {
    window.location.href = 'login.php';
});
</script>
</body>
</html>