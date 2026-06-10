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
$adminEmail = $admin['email'];
$adminPhone = $admin['phone'] ?? '';
$profilePic = !empty($admin['profile_pic'])
    ? '../uploads/profiles/' . $admin['profile_pic']
    : $assetBase . '/profile-admin.png';

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $fullname = trim($_POST['fullname'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    
    $errors = [];
    
    if (empty($fullname)) {
        $errors[] = "Full name is required";
    }
    if (empty($email)) {
        $errors[] = "Email is required";
    }
    
    if (empty($errors)) {
        $updateStmt = $conn->prepare("UPDATE users SET fullname = ?, email = ?, phone = ? WHERE id = ? AND role = 'admin'");
        $updateStmt->bind_param("sssi", $fullname, $email, $phone, $adminID);
        
        if ($updateStmt->execute()) {
            $_SESSION['success_message'] = "Profile updated successfully!";
            header("Location: admin_profile.php");
            exit();
        } else {
            $_SESSION['error_message'] = "Failed to update profile: " . $conn->error;
        }
    } else {
        $_SESSION['error_message'] = implode(", ", $errors);
    }
}

// Handle password change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
        $_SESSION['error_message'] = "All password fields are required";
    } elseif ($new_password !== $confirm_password) {
        $_SESSION['error_message'] = "New passwords do not match";
    } else {
        // Validate password strength
        $password_errors = [];
        
        if (strlen($new_password) < 8) {
            $password_errors[] = "Password must be at least 8 characters long";
        }
        if (!preg_match('/[A-Z]/', $new_password)) {
            $password_errors[] = "Password must contain at least 1 uppercase letter";
        }
        if (!preg_match('/[!@#$%^&*(),.?":{}|<>]/', $new_password)) {
            $password_errors[] = "Password must contain at least 1 special character";
        }
        
        if (!empty($password_errors)) {
            $_SESSION['error_message'] = implode(" | ", $password_errors);
        } else {
            // Verify current password
            $passStmt = $conn->prepare("SELECT password FROM users WHERE id = ? AND role = 'admin'");
            $passStmt->bind_param("i", $adminID);
            $passStmt->execute();
            $userData = $passStmt->get_result()->fetch_assoc();
            
            if (password_verify($current_password, $userData['password'])) {
                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                $updatePass = $conn->prepare("UPDATE users SET password = ? WHERE id = ? AND role = 'admin'");
                $updatePass->bind_param("si", $hashed_password, $adminID);
                
                if ($updatePass->execute()) {
                    $_SESSION['success_message'] = "Password changed successfully!";
                } else {
                    $_SESSION['error_message'] = "Failed to change password";
                }
            } else {
                $_SESSION['error_message'] = "Current password is incorrect";
            }
        }
    }
    header("Location: admin_profile.php");
    exit();
}
// Get counts for sidebar
$totalTutors = $conn->query("SELECT COUNT(*) as count FROM users WHERE role = 'tutor'")->fetch_assoc()['count'];
$totalStudents = $conn->query("SELECT COUNT(*) as count FROM users WHERE role = 'student'")->fetch_assoc()['count'];
$totalBookings = $conn->query("SELECT COUNT(*) as count FROM bookings")->fetch_assoc()['count'];
$pendingPayments = $conn->query("SELECT COUNT(*) as count FROM payments WHERE status = 'pending'")->fetch_assoc()['count'];
$pendingDisputes = $conn->query("SELECT COUNT(*) as count FROM disputes WHERE status = 'pending'")->fetch_assoc()['count'];
$pendingPayouts = $conn->query("SELECT COUNT(*) as count FROM payout_requests WHERE status = 'pending'")->fetch_assoc()['count'] ?? 0;

function e($value) {
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Profile - Kyoshi</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="../css/astyle.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: "Montserrat", "Open Sans", sans-serif;
            background: url('../assets/img/background3.jpg') no-repeat center top;
            background-size: cover;
            min-height: 100vh;
            color: #1E1B2E;
            line-height: 1.45;
            overflow-x: hidden;
        }
        
        /* Sidebar styles */
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
        .sidebar.open { transform: translateX(0); }
        
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
        
        .brand-title h1 {
            font-size: 1.4rem;
            font-weight: 700;
            background: linear-gradient(135deg, #ffffff, #B26EA7);
            background-clip: text;
            -webkit-background-clip: text;
            color: transparent;
            margin: 0;
        }
        
        .admin-space-text {
            font-size: 0.6rem;
            color: #e7c7f7;
            margin-top: 2px;
        }
        
        .nav-menu {
            padding: 16px 0;
            flex: 1;
            overflow-y: auto;
            min-height: 0;
        }
        
        .nav-menu::-webkit-scrollbar { width: 3px; }
        .nav-menu::-webkit-scrollbar-track { background: rgba(255,255,255,0.1); }
        .nav-menu::-webkit-scrollbar-thumb { background: #B26EA7; border-radius: 3px; }
        
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
        
        .nav-section {
            margin-bottom: 8px;
        }
        
        .nav-section-label {
            padding: 12px 20px 6px 20px;
            font-size: 0.65rem;
            font-weight: 600;
            color: #B26EA7;
            text-transform: uppercase;
        }
        
        .nav-badge {
            margin-left: auto;
            font-size: 0.65rem;
            background: rgba(178, 110, 167, 0.25);
            padding: 2px 8px;
            border-radius: 30px;
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
        
        .admin-name {
            font-size: 0.8rem;
            font-weight: 600;
            color: white;
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
        }
        
        .logout-icon:hover {
            background: rgba(220, 38, 38, 0.4);
            color: white;
        }
        
        /* Main Content */
        .main-content {
            margin-left: 230px;
            padding: 20px 24px;
            transition: margin-left 0.3s ease;
            height: 100vh;
            overflow-y: auto;
        }
        
        .main-content::-webkit-scrollbar { width: 8px; }
        .main-content::-webkit-scrollbar-track { background: #f1f1f1; border-radius: 10px; }
        .main-content::-webkit-scrollbar-thumb { background: #E75A9B; border-radius: 10px; }
        
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
        
        /* Profile Container */
        .profile-container {
            background: white;
            border-radius: 24px;
            padding: 32px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
        }
        
        .profile-header {
            display: flex;
            align-items: center;
            gap: 32px;
            margin-bottom: 32px;
            flex-wrap: wrap;
        }
        
        .profile-avatar {
            position: relative;
            width: 120px;
            height: 120px;
        }
        
        .profile-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 50%;
            border: 4px solid #E75A9B;
        }
        
        .avatar-upload {
            position: absolute;
            bottom: 5px;
            right: 5px;
            background: #E75A9B;
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            color: white;
            transition: all 0.2s;
        }
        
        .avatar-upload:hover {
            transform: scale(1.05);
            background: #d44a8a;
        }
        
        .profile-title h2 {
            font-size: 24px;
            font-weight: 800;
            color: #302E63;
            margin-bottom: 5px;
        }
        
        .profile-title p {
            color: #7B6E8F;
            font-size: 14px;
        }
        
        .profile-stats {
            display: flex;
            gap: 24px;
            margin-left: auto;
        }
        
        .stat-item {
            text-align: center;
        }
        
        .stat-number {
            font-size: 24px;
            font-weight: 800;
            color: #E75A9B;
        }
        
        .stat-label {
            font-size: 11px;
            color: #7B6E8F;
            text-transform: uppercase;
        }
        
        /* Tabs */
        .profile-tabs {
            display: flex;
            gap: 8px;
            border-bottom: 2px solid #eef2f7;
            margin-bottom: 28px;
        }
        
        .tab-btn {
            padding: 12px 24px;
            background: none;
            border: none;
            font-size: 14px;
            font-weight: 700;
            color: #7B6E8F;
            cursor: pointer;
            transition: all 0.2s;
            position: relative;
        }
        
        .tab-btn.active {
            color: #E75A9B;
        }
        
        .tab-btn.active::after {
            content: '';
            position: absolute;
            bottom: -2px;
            left: 0;
            right: 0;
            height: 2px;
            background: #E75A9B;
        }
        
        .tab-pane {
            display: none;
            animation: fadeIn 0.3s ease;
        }
        
        .tab-pane.active {
            display: block;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            font-weight: 700;
            font-size: 12px;
            margin-bottom: 6px;
            color: #1e293b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .form-group input,
        .form-group textarea {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid #cbd5e1;
            border-radius: 12px;
            font-family: inherit;
            font-size: 14px;
            transition: all 0.2s;
        }
        
        .form-group input:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #E75A9B;
            box-shadow: 0 0 0 3px rgba(231,90,155,0.1);
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        
        .btn-save {
            background: linear-gradient(135deg, #E75A9B, #d44a8a);
            color: white;
            padding: 12px 28px;
            border-radius: 40px;
            border: none;
            cursor: pointer;
            font-weight: 700;
            font-size: 14px;
            transition: all 0.2s;
        }
        
        .btn-save:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(231,90,155,0.3);
        }
        
        .btn-secondary {
            background: #e2e8f0;
            color: #475569;
            padding: 12px 28px;
            border-radius: 40px;
            border: none;
            cursor: pointer;
            font-weight: 700;
            font-size: 14px;
            transition: all 0.2s;
        }
        
        .btn-secondary:hover {
            background: #cbd5e1;
        }
        
        .alert-success {
            background: #d4edda;
            color: #155724;
            border-left: 4px solid #28a745;
            padding: 12px 16px;
            border-radius: 12px;
            margin-bottom: 20px;
        }
        
        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border-left: 4px solid #dc2626;
            padding: 12px 16px;
            border-radius: 12px;
            margin-bottom: 20px;
        }
        
        @media (max-width: 768px) {
            .menu-toggle { display: block; }
            .sidebar { transform: translateX(-100%); }
            .sidebar.open { transform: translateX(0); }
            .main-content { margin-left: 0; padding: 16px; }
            .profile-header { flex-direction: column; text-align: center; }
            .profile-stats { margin-left: 0; justify-content: center; }
            .form-row { grid-template-columns: 1fr; }
            .profile-tabs { flex-wrap: wrap; }
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
            <a href="admin_student_actions.php" class="nav-item">
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
        <h1>My Profile</h1>
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
    <h1 class="mobile-page-title">My Profile</h1>
</div>

    <?php if (isset($_SESSION['success_message'])): ?>
        <div class="alert-success" id="successAlert">
            <i class="bi bi-check-circle"></i> <?= $_SESSION['success_message'] ?>
        </div>
        <?php unset($_SESSION['success_message']); ?>
    <?php endif; ?>
    
    <?php if (isset($_SESSION['error_message'])): ?>
        <div class="alert-error" id="errorAlert">
            <i class="bi bi-exclamation-triangle"></i> <?= $_SESSION['error_message'] ?>
        </div>
        <?php unset($_SESSION['error_message']); ?>
    <?php endif; ?>

    <div class="profile-container">
        <!-- Profile Header -->
        <div class="profile-header">
            <div class="profile-avatar">
                <img src="<?= e($profilePic) ?>" alt="Profile Picture" id="avatarPreview">
                <label for="profile_pic_upload" class="avatar-upload">
                    <i class="bi bi-camera-fill"></i>
                </label>
                <input type="file" id="profile_pic_upload" accept="image/jpeg,image/png,image/gif,image/webp" style="display: none;">
            </div>
            <div class="profile-title">
                <h2><?= e($displayName) ?></h2>
                <p><i class="bi bi-envelope"></i> <?= e($adminEmail) ?> • <i class="bi bi-shield-check"></i> Administrator</p>
            </div>
            <div class="profile-stats">
                <div class="stat-item">
                    <div class="stat-number"><?= $totalTutors + $totalStudents ?></div>
                    <div class="stat-label">Total Users</div>
                </div>
                <div class="stat-item">
                    <div class="stat-number"><?= $totalBookings ?></div>
                    <div class="stat-label">Bookings</div>
                </div>
            </div>
        </div>

        <!-- Tabs -->
        <div class="profile-tabs">
            <button class="tab-btn active" onclick="switchTab('info')">Personal Information</button>
            <button class="tab-btn" onclick="switchTab('password')">Change Password</button>
        </div>

        <!-- Personal Information Tab -->
        <div id="infoTab" class="tab-pane active">
            <form method="POST" action="" id="profileForm">
                <input type="hidden" name="update_profile" value="1">

                <div class="form-row">
                    <div class="form-group">
                        <label><i class="bi bi-person"></i> Full Name</label>
                        <input type="text" name="fullname" value="<?= e($displayName) ?>" required>
                    </div>
                    <div class="form-group">
                        <label><i class="bi bi-envelope"></i> Email Address</label>
                        <input type="email" name="email" value="<?= e($adminEmail) ?>" required>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label><i class="bi bi-phone"></i> Phone Number</label>
                        <input type="tel" name="phone" value="<?= e($adminPhone) ?>" placeholder="e.g., +60123456789">
                    </div>
                    <div class="form-group">
                        <label><i class="bi bi-shield"></i> Role</label>
                        <input type="text" value="Administrator" disabled style="background: #f1f5f9;">
                    </div>
                </div>
                
                
                <div style="display: flex; gap: 12px; margin-top: 24px;">
                    <button type="submit" class="btn-save"><i class="bi bi-save"></i> Save Changes</button>
                </div>
            </form>
        </div>

        <!-- Change Password Tab -->
        <div id="passwordTab" class="tab-pane">
            <form method="POST" action="" id="passwordForm">
                <input type="hidden" name="change_password" value="1">
                
                <div class="form-group">
                    <label><i class="bi bi-lock"></i> Current Password</label>
                    <input type="password" name="current_password" required>
                </div>
                
              <div class="form-row">
    <div class="form-group">
        <label><i class="bi bi-key"></i> New Password</label>
        <input type="password" name="new_password" 
               id="new_password"
               required 
               pattern="(?=.*[A-Z])(?=.*[!@#$%^&*]).{8,}"
               title="Password must be at least 8 characters with 1 uppercase letter and 1 special character">
        <small style="font-size: 11px; color: #666; display: block; margin-top: 5px;">
            <i class="bi bi-info-circle"></i> Requirements:<br>
            <span id="lengthReq">✗ 8+ characters</span> <br> 
            <span id="upperReq">✗ 1 uppercase letter</span> <br>
            <span id="specialReq">✗ 1 special character</span>
        </small>
    </div>
    <div class="form-group">
        <label><i class="bi bi-check-circle"></i> Confirm New Password</label>
        <input type="password" name="confirm_password" id="confirm_password" required>
        <small style="font-size: 11px; color: #666;" id="matchMsg"></small>
    </div>
</div>
            </form>
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

window.addEventListener('click', function(e) {
    const dropdown = document.getElementById('profileDropdown');
    const button = document.querySelector('.admin-profile');
    if (button && !button.contains(e.target) && dropdown && !dropdown.contains(e.target)) {
        dropdown.style.display = 'none';
    }
});

function switchTab(tab) {
    const infoTab = document.getElementById('infoTab');
    const passwordTab = document.getElementById('passwordTab');
    const btns = document.querySelectorAll('.tab-btn');
    
    btns.forEach(btn => btn.classList.remove('active'));
    
    if (tab === 'info') {
        infoTab.classList.add('active');
        passwordTab.classList.remove('active');
        btns[0].classList.add('active');
    } else {
        infoTab.classList.remove('active');
        passwordTab.classList.add('active');
        btns[1].classList.add('active');
    }
}

// Profile picture upload
document.getElementById('profile_pic_upload').addEventListener('change', function(e) {
    const file = e.target.files[0];
    if (file) {
        const formData = new FormData();
        formData.append('profile_pic', file);
        formData.append('upload_profile_pic', '1');
        
        fetch('upload_profile_pic.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                document.getElementById('avatarPreview').src = data.file_path + '?t=' + new Date().getTime();
                document.getElementById('profile_pic_hidden').value = data.filename;
                Swal.fire({
                    icon: 'success',
                    title: 'Profile Picture Updated',
                    text: 'Your profile picture has been updated successfully.',
                    confirmButtonColor: '#E75A9B',
                    timer: 2000,
                    showConfirmButton: false
                });
            } else {
                Swal.fire({
                    icon: 'error',
                    title: 'Upload Failed',
                    text: data.message,
                    confirmButtonColor: '#dc2626'
                });
            }
        })
        .catch(error => {
            console.error('Error:', error);
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: 'Failed to upload profile picture',
                confirmButtonColor: '#dc2626'
            });
        });
    }
});

// Click on avatar to trigger file upload
document.querySelector('.avatar-upload').addEventListener('click', function() {
    document.getElementById('profile_pic_upload').click();
});

// Close dropdown when clicking outside
window.addEventListener('click', function(e) {
    const dropdown = document.getElementById('profileDropdown');
    const button = document.querySelector('.admin-profile');
    if (button && !button.contains(e.target) && dropdown && !dropdown.contains(e.target)) {
        dropdown.style.display = 'none';
    }
});

// Sidebar toggle
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

// Real-time password validation
const newPassword = document.getElementById('new_password');
const confirmPassword = document.getElementById('confirm_password');
const lengthReq = document.getElementById('lengthReq');
const upperReq = document.getElementById('upperReq');
const specialReq = document.getElementById('specialReq');
const matchMsg = document.getElementById('matchMsg');

function validatePassword() {
    const password = newPassword.value;
    let isValid = true;
    
    // Check length (8+ characters)
    if (password.length >= 8) {
        lengthReq.innerHTML = '✓ At least 8 characters';
        lengthReq.style.color = '#28a745';
    } else {
        lengthReq.innerHTML = '✗ At least 8 characters';
        lengthReq.style.color = '#dc2626';
        isValid = false;
    }
    
    // Check uppercase letter
    if (/[A-Z]/.test(password)) {
        upperReq.innerHTML = '✓ 1 uppercase letter';
        upperReq.style.color = '#28a745';
    } else {
        upperReq.innerHTML = '✗ 1 uppercase letter';
        upperReq.style.color = '#dc2626';
        isValid = false;
    }
    
    // Check special character (simplified set)
    if (/[!@#$%^&*]/.test(password)) {
        specialReq.innerHTML = '✓ 1 special character (!@#$%^&*)';
        specialReq.style.color = '#28a745';
    } else {
        specialReq.innerHTML = '✗ 1 special character (!@#$%^&*)';
        specialReq.style.color = '#dc2626';
        isValid = false;
    }
    
    return isValid;
}

function checkPasswordMatch() {
    if (confirmPassword.value.length > 0) {
        if (newPassword.value === confirmPassword.value) {
            matchMsg.innerHTML = '✓ Passwords match';
            matchMsg.style.color = '#28a745';
            return true;
        } else {
            matchMsg.innerHTML = '✗ Passwords do not match';
            matchMsg.style.color = '#dc2626';
            return false;
        }
    } else {
        matchMsg.innerHTML = '';
        return false;
    }
}

// Add event listeners
if (newPassword) {
    newPassword.addEventListener('input', function() {
        validatePassword();
        checkPasswordMatch();
    });
}

if (confirmPassword) {
    confirmPassword.addEventListener('input', checkPasswordMatch);
}

// Validate form before submission
document.getElementById('passwordForm')?.addEventListener('submit', function(e) {
    const isPasswordValid = validatePassword();
    const isMatch = checkPasswordMatch();
    
    if (!isPasswordValid) {
        e.preventDefault();
        Swal.fire({
            icon: 'error',
            title: 'Password Requirements',
            html: 'Please meet all password requirements:<br>• At least 8 characters<br>• 1 uppercase letter<br>• 1 special character (!@#$%^&*)',
            confirmButtonColor: '#dc2626'
        });
        return false;
    }
    
    if (!isMatch) {
        e.preventDefault();
        Swal.fire({
            icon: 'error',
            title: 'Password Mismatch',
            text: 'New password and confirm password do not match',
            confirmButtonColor: '#dc2626'
        });
        return false;
    }
});

// Auto-dismiss alerts
setTimeout(() => {
    const successAlert = document.getElementById('successAlert');
    const errorAlert = document.getElementById('errorAlert');
    if (successAlert) {
        successAlert.style.opacity = '0';
        successAlert.style.transition = 'opacity 0.5s';
        setTimeout(() => { if (successAlert) successAlert.remove(); }, 500);
    }
    if (errorAlert) {
        errorAlert.style.opacity = '0';
        errorAlert.style.transition = 'opacity 0.5s';
        setTimeout(() => { if (errorAlert) errorAlert.remove(); }, 500);
    }
}, 3000);
</script>
<script>
history.pushState(null, null, location.href);
window.addEventListener('popstate', function() {
    window.location.href = 'login.php';
});
</script>
</body>
</html>