<?php
session_start();
include 'config.php';
require_once '../vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

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

// Get return URL (where to go back after creation)
$return_to = $_GET['return_to'] ?? 'admin_tutor_actions.php';
// Validate return_to to prevent redirect attacks
$allowed_returns = ['admin_tutor_actions.php', 'admin_students.php', 'admin_dashboard.php', 'admin_tutors.php', 'admin_all_users.php'];
if (!in_array($return_to, $allowed_returns)) {
    $return_to = 'admin_tutor_actions.php';
}

// Get preset role from URL (for auto-selecting role)
$preset_role = $_GET['role'] ?? '';

// Handle user creation
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_user'])) {
    $fullname = trim($_POST['fullname'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $role = $_POST['role'] ?? 'student';
    $return_to = $_POST['return_to'] ?? 'admin_tutor_actions.php';
    $default_password = 'Kyoshi@2026';
    
    // Validation
    if (empty($fullname) || empty($email)) {
        $error = "Full name and email are required.";
    } else {
        // Check if email already exists
        $checkStmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
        $checkStmt->bind_param("s", $email);
        $checkStmt->execute();
        $checkResult = $checkStmt->get_result();
        
        if ($checkResult->num_rows > 0) {
            $error = "Email already exists. Please use a different email.";
        } else {
            // Hash the password
            $hashed_password = password_hash($default_password, PASSWORD_DEFAULT);
            
            // Insert new user
            $insertStmt = $conn->prepare("INSERT INTO users (fullname, email, phone, password, role, status, created_at) VALUES (?, ?, ?, ?, ?, 'approved', NOW())");
            $insertStmt->bind_param("sssss", $fullname, $email, $phone, $hashed_password, $role);
            
            if ($insertStmt->execute()) {
                // Send email with credentials
                $subject = "Welcome to Kyoshi - Your Account Has Been Created";
                
                $emailBody = "
                <div style='font-family:Segoe UI,sans-serif;max-width:550px;margin:auto;'>
                    <div style='background:linear-gradient(135deg, #1d3156, #E75A9B);padding:30px;text-align:center;border-radius:16px 16px 0 0;color:white;'>
                        <h2>Welcome to Kyoshi!</h2>
                    </div>
                    <div style='background:#fff;padding:30px;border:1px solid #eef2f7;border-top:none;border-radius:0 0 16px 16px;'>
                        <p>Dear <strong>" . htmlspecialchars($fullname) . "</strong>,</p>
                        <p>An account has been created for you on the Kyoshi Language Learning Platform as a <strong>" . ucfirst($role) . "</strong>.</p>
                        <div style='background:#f8fafc;padding:16px;border-radius:12px;margin:15px 0;'>
                            <p><strong>Your Login Credentials:</strong></p>
                            <p>Email: <strong>" . htmlspecialchars($email) . "</strong></p>
                            <p>Password: <strong>" . $default_password . "</strong></p>
                        </div>
                        <div style='background:#fff3cd;padding:12px;border-radius:8px;margin-top:20px;border-left:4px solid #ffc107;'>
                            <p style='margin:0;font-size:13px;color:#856404;'>
                                <strong>Important:</strong><br>
                                • This password is temporary. Please change it after your first login.<br>
                                • Please complete your profile with accurate information.<br>
                                • For any issues, contact support@kyoshi.com
                            </p>
                        </div>
                        <hr>
                        <p style='font-size:12px;color:#666;'>Kyoshi Language Platform</p>
                    </div>
                </div>
                ";
                
                // Send email using PHPMailer
                $mail = new PHPMailer(true);
                try {
                    $mail->isSMTP();
                    $mail->Host       = 'smtp.gmail.com';
                    $mail->SMTPAuth   = true;
                    $mail->Username   = SMTP_USER;
                    $mail->Password   = SMTP_PASS;
                    $mail->SMTPSecure = 'tls';
                    $mail->Port       = 587;
                    
                    $mail->setFrom('sohisabella87@gmail.com', 'Kyoshi');
                    $mail->addAddress($email, $fullname);
                    $mail->isHTML(true);
                    $mail->Subject = $subject;
                    $mail->Body = $emailBody;
                    $mail->send();
                    
                    $_SESSION['success_message'] = ucfirst($role) . " account created successfully! Login credentials sent to " . htmlspecialchars($email);
                } catch (Exception $e) {
                    $_SESSION['success_message'] = ucfirst($role) . " account created! But email failed to send. Error: " . $mail->ErrorInfo;
                }
                
                header("Location: " . $return_to . "?success=1");
                exit();
            } else {
                $error = "Failed to create user. Please try again.";
            }
            $insertStmt->close();
        }
        $checkStmt->close();
    }
}

// Get counts for sidebar
$totalTutors = $conn->query("SELECT COUNT(*) as count FROM users WHERE role = 'tutor'")->fetch_assoc()['count'];
$totalStudents = $conn->query("SELECT COUNT(*) as count FROM users WHERE role = 'student'")->fetch_assoc()['count'];
$totalBookings = $conn->query("SELECT COUNT(*) as count FROM bookings")->fetch_assoc()['count'];
$pendingPayouts = $conn->query("SELECT COUNT(*) as count FROM payout_requests WHERE status = 'pending'")->fetch_assoc()['count'] ?? 0;
$pendingPayments = $conn->query("SELECT COUNT(*) as count FROM payments WHERE status = 'pending'")->fetch_assoc()['count'];
$pendingDisputes = $conn->query("SELECT COUNT(*) as count FROM disputes WHERE status = 'pending'")->fetch_assoc()['count'];
$pendingQualifications = $conn->query("
    SELECT COUNT(*) as count 
    FROM tutor_certificates tc
    JOIN users u ON tc.tutor_id = u.id
    WHERE tc.status = 'pending' AND u.status = 'approved'
")->fetch_assoc()['count'];
$totalReviews = $conn->query("SELECT COUNT(*) as count FROM ratings")->fetch_assoc()['count'];
$pendingReports = $conn->query("SELECT COUNT(*) as count FROM session_reports WHERE report_status = 'submitted'")->fetch_assoc()['count'];

function e($value) {
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kyoshi | Create User</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
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
            transform: scale(1.05);
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
        
        /* Form Container */
        .form-container {
            background: white;
            border-radius: 20px;
            padding: 30px;
            max-width: 600px;
            margin: 0 auto;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
        }
        
        .form-title {
            font-size: 1.2rem;
            font-weight: 700;
            color: #302E63;
            margin-bottom: 24px;
            padding-bottom: 12px;
            border-bottom: 2px solid #E75A9B;
            display: inline-block;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            font-weight: 600;
            font-size: 13px;
            margin-bottom: 8px;
            color: #1a1a3e;
        }
        
        .form-group label .required {
            color: #dc2626;
        }
        
        .form-group input, .form-group select {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid #cbd5e1;
            border-radius: 12px;
            font-family: inherit;
            font-size: 14px;
            transition: all 0.2s;
        }
        
        .form-group input:focus, .form-group select:focus {
            outline: none;
            border-color: #E75A9B;
            box-shadow: 0 0 0 3px rgba(231, 90, 155, 0.1);
        }
        
        .btn-submit {
            background: #E75A9B;
            color: white;
            border: none;
            padding: 12px 28px;
            border-radius: 40px;
            cursor: pointer;
            font-weight: 600;
            font-size: 14px;
            transition: all 0.2s;
            width: 100%;
        }
        
        .btn-submit:hover {
            background: #d44a8a;
            transform: translateY(-1px);
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
        
        .info-note {
            background: #e8f4fd;
            padding: 16px;
            border-radius: 12px;
            margin-top: 20px;
            font-size: 13px;
            color: #1e40af;
        }
        
        .info-note i {
            margin-right: 8px;
        }
        
        .btn-back {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: #e2e8f0;
            color: #1d3156;
            padding: 8px 16px;
            border-radius: 40px;
            text-decoration: none;
            font-size: 13px;
            font-weight: 600;
            transition: 0.2s;
        }
        
        .btn-back:hover {
            background: #cbd5e1;
            transform: translateX(-3px);
        }
        
        /* Responsive */
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
            
            .form-container {
                padding: 20px;
            }
            
            .top-bar {
                flex-direction: column;
                align-items: flex-start;
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
        <div style="display: flex; align-items: center; gap: 16px;">
            <a href="<?= e($return_to) ?>" class="btn-back">
                <i class="bi bi-arrow-left"></i> Back
            </a>
            <div class="page-title">
                <h1>Create New User</h1>
            </div>
        </div>
        <button class="menu-toggle" id="menuToggle"><i class="bi bi-list"></i> Menu</button>
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

    <?php if (isset($_SESSION['success_message'])): ?>
        <div class="alert-success" id="successAlert">
            <i class="bi bi-check-circle"></i> <?= $_SESSION['success_message'] ?>
        </div>
        <?php unset($_SESSION['success_message']); ?>
    <?php endif; ?>
    
    <?php if ($error): ?>
        <div class="alert-error" id="errorAlert">
            <i class="bi bi-exclamation-triangle"></i> <?= e($error) ?>
        </div>
    <?php endif; ?>

    <div class="form-container">
        <h2 class="form-title">Create User Account</h2>
        
        <form method="POST" action="">
            <input type="hidden" name="return_to" value="<?= e($return_to) ?>">
            
            <div class="form-group">
                <label>Full Name <span class="required">*</span></label>
                <input type="text" name="fullname" required placeholder="e.g., John Doe">
            </div>
            
            <div class="form-group">
                <label>Email Address <span class="required">*</span></label>
                <input type="email" name="email" required placeholder="user@example.com">
                <small style="font-size: 11px; color: #64748b;">Login credentials will be sent to this email.</small>
            </div>
            
            <div class="form-group">
                <label>Phone Number</label>
                <input type="tel" name="phone" placeholder="e.g., 0123456789">
            </div>
            
            <div class="form-group">
                <label>Role <span class="required">*</span></label>
                <select name="role" required>
                    <option value="student" <?= $preset_role === 'student' ? 'selected' : '' ?>>Student</option>
                    <option value="tutor" <?= $preset_role === 'tutor' ? 'selected' : '' ?>>Tutor</option>
                    <option value="admin">Admin</option>
                </select>
            </div>
            
            <div class="info-note">
                <i class="bi bi-info-circle"></i>
                <strong>Default Password:</strong> Kyoshi@2026<br>
                <strong>What happens next:</strong><br>
                1. The user will receive an email with their login credentials<br>
                2. They should change their password after first login<br>
                3. They can complete their profile with additional information<br>
                4. For any issues, they can contact support@kyoshi.com
            </div>
            <br>
            <button type="submit" name="create_user" class="btn-submit">
                <i class="bi bi-person-plus"></i> Create <?= ucfirst($preset_role ?: 'User') ?> & Send Email
            </button>
        </form>
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

</body>
</html>