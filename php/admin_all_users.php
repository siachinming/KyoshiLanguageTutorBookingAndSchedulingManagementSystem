<?php
session_start();
include 'config.php';
include 'check_login.php';
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

// Handle user deletion (ADD THIS SECTION)
if (isset($_POST['delete_user']) && isset($_POST['user_id'])) {
    $user_id = intval($_POST['user_id']);
    
    // Don't allow admin to delete themselves
    if ($user_id == $adminID) {
        $_SESSION['error_message'] = "You cannot delete your own account!";
    } else {
        // Check if user has existing bookings
        $check_stmt = $conn->prepare("SELECT COUNT(*) as count FROM bookings WHERE student_id = ? OR tutor_id = ?");
        $check_stmt->bind_param("ii", $user_id, $user_id);
        $check_stmt->execute();
        $result = $check_stmt->get_result();
        $has_bookings = $result->fetch_assoc()['count'] > 0;
        $check_stmt->close();
        
        if ($has_bookings) {
            $_SESSION['error_message'] = "Cannot delete user with existing bookings. Please deactivate instead.";
        } else {
            // Get user info for email before deleting
            $userStmt = $conn->prepare("SELECT fullname, email FROM users WHERE id = ?");
            $userStmt->bind_param("i", $user_id);
            $userStmt->execute();
            $userInfo = $userStmt->get_result()->fetch_assoc();
            
            // Delete the user
            $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
            $stmt->bind_param("i", $user_id);
            if ($stmt->execute()) {
                $_SESSION['success_message'] = "User deleted successfully!";
                
                // Send email notification
                if ($userInfo) {
                    $emailBody = "
                    <div style='font-family:Segoe UI,sans-serif;max-width:550px;margin:auto;'>
                        <div style='background:linear-gradient(135deg, #1d3156, #E75A9B);padding:30px;text-align:center;border-radius:16px 16px 0 0;color:white;'>
                            <h2>Account Deleted</h2>
                        </div>
                        <div style='background:#fff;padding:30px;border:1px solid #eef2f7;border-top:none;border-radius:0 0 16px 16px;'>
                            <p>Dear <strong>" . htmlspecialchars($userInfo['fullname']) . "</strong>,</p>
                            <p>Your Kyoshi account has been <strong>permanently deleted</strong> by an administrator.</p>
                            <div style='background:#f8fafc;padding:16px;border-radius:12px;margin:15px 0;'>
                                <p style='margin:0;color:#666;'>If you believe this was done in error, please contact our support team.</p>
                            </div>
                            <hr>
                            <p style='font-size:12px;color:#666;'>Kyoshi Language Platform</p>
                        </div>
                    </div>
                    ";
                    
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
                        $mail->addAddress($userInfo['email'], $userInfo['fullname']);
                        $mail->isHTML(true);
                        $mail->Subject = "Account Deleted - Kyoshi";
                        $mail->Body = $emailBody;
                        $mail->send();
                    } catch (Exception $e) {}
                }
            } else {
                $_SESSION['error_message'] = "Failed to delete user.";
            }
            $stmt->close();
        }
    }
    header("Location: admin_all_users.php?" . http_build_query($_GET));
    exit();
}

// Handle user status toggle (activate/deactivate)
if (isset($_POST['toggle_status']) && isset($_POST['user_id'])) {
    $user_id = intval($_POST['user_id']);
    $current_status = $_POST['current_status'];
    $new_status = ($current_status === 'approved') ? 'inactive' : 'approved';
    
    $stmt = $conn->prepare("UPDATE users SET status = ?, deactivated_at = IF(? = 'inactive', NOW(), NULL) WHERE id = ?");
    $stmt->bind_param("ssi", $new_status, $new_status, $user_id);
    
    if ($stmt->execute()) {
        $_SESSION['success_message'] = "User status updated successfully!";
        
        // Send email notification for deactivation/reactivation
        $userStmt = $conn->prepare("SELECT fullname, email FROM users WHERE id = ?");
        $userStmt->bind_param("i", $user_id);
        $userStmt->execute();
        $userInfo = $userStmt->get_result()->fetch_assoc();
        
        if ($userInfo) {
            $subject = $new_status === 'inactive' ? "Account Deactivated - Kyoshi" : "Account Reactivated - Kyoshi";
            $action_text = $new_status === 'inactive' ? "deactivated" : "reactivated";
            
            $emailBody = "
            <div style='font-family:Segoe UI,sans-serif;max-width:550px;margin:auto;'>
                <div style='background:linear-gradient(135deg, #1d3156, #E75A9B);padding:30px;text-align:center;border-radius:16px 16px 0 0;color:white;'>
                    <h2>Account " . ucfirst($action_text) . "</h2>
                </div>
                <div style='background:#fff;padding:30px;border:1px solid #eef2f7;border-top:none;border-radius:0 0 16px 16px;'>
                    <p>Dear <strong>" . htmlspecialchars($userInfo['fullname']) . "</strong>,</p>
                    <p>Your Kyoshi account has been <strong>" . $action_text . "</strong> by an administrator.</p>
                    " . ($new_status === 'inactive' ? "
                    <div style='background:#fff3cd;padding:12px;border-radius:8px;margin:15px 0;border-left:4px solid #ffc107;'>
                        <p style='margin:0;font-size:13px;color:#856404;'>
                            <strong>Note:</strong> You cannot book or conduct sessions while your account is inactive.
                            Please contact support if you believe this is an error.
                        </p>
                    </div>
                    " : "
                    <div style='background:#d4edda;padding:12px;border-radius:8px;margin:15px 0;border-left:4px solid #28a745;'>
                        <p style='margin:0;font-size:13px;color:#155724;'>
                            <strong>Welcome back!</strong> Your account has been reactivated. You can now continue using the platform.
                        </p>
                    </div>
                    ") . "
                    <hr>
                    <p style='font-size:12px;color:#666;'>Kyoshi Language Platform</p>
                </div>
            </div>
            ";
            
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
                $mail->addAddress($userInfo['email'], $userInfo['fullname']);
                $mail->isHTML(true);
                $mail->Subject = $subject;
                $mail->Body = $emailBody;
                $mail->send();
            } catch (Exception $e) {}
        }
    } else {
        $_SESSION['error_message'] = "Failed to update user status.";
    }
    $stmt->close();
    header("Location: admin_all_users.php?" . http_build_query($_GET));
    exit();
}

// Handle user edit
if (isset($_POST['edit_user']) && isset($_POST['user_id'])) {
    $user_id = intval($_POST['user_id']);
    $fullname = trim($_POST['fullname']);
    $email = trim($_POST['email']);
    $phone = trim($_POST['phone']);
    $role = $_POST['role'];
    
    $check_stmt = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
    $check_stmt->bind_param("si", $email, $user_id);
    $check_stmt->execute();
    if ($check_stmt->get_result()->num_rows > 0) {
        $_SESSION['error_message'] = "Email already exists for another user!";
    } else {
        $stmt = $conn->prepare("UPDATE users SET fullname = ?, email = ?, phone = ?, role = ? WHERE id = ?");
        $stmt->bind_param("ssssi", $fullname, $email, $phone, $role, $user_id);
        if ($stmt->execute()) {
            $_SESSION['success_message'] = "User updated successfully!";
        } else {
            $_SESSION['error_message'] = "Failed to update user.";
        }
        $stmt->close();
    }
    $check_stmt->close();
    header("Location: admin_all_users.php?" . http_build_query($_GET));
    exit();
}

// Get filter parameters
$role_filter = isset($_GET['role']) ? $_GET['role'] : 'all';
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'all';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Build query
$query = "SELECT u.*, 
          (SELECT COUNT(*) FROM bookings WHERE student_id = u.id) as student_bookings,
          (SELECT COUNT(*) FROM bookings WHERE tutor_id = u.id) as tutor_bookings,
          (SELECT COUNT(*) FROM tutor_certificates WHERE tutor_id = u.id AND status = 'pending') as pending_certs
          FROM users u WHERE 1=1";
$params = [];
$types = "";

if ($role_filter !== 'all') {
    $query .= " AND u.role = ?";
    $params[] = $role_filter;
    $types .= "s";
}

if ($status_filter !== 'all') {
    $query .= " AND u.status = ?";
    $params[] = $status_filter;
    $types .= "s";
}

if (!empty($search)) {
    $query .= " AND (u.fullname LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "sss";
}

$query .= " ORDER BY u.created_at DESC";

$stmt = $conn->prepare($query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$users = $stmt->get_result();
$stmt->close();

// Get counts for stats and sidebar
$totalUsers = $conn->query("SELECT COUNT(*) as count FROM users")->fetch_assoc()['count'];
$totalStudents = $conn->query("SELECT COUNT(*) as count FROM users WHERE role = 'student'")->fetch_assoc()['count'];
$totalTutors = $conn->query("SELECT COUNT(*) as count FROM users WHERE role = 'tutor'")->fetch_assoc()['count'];
$pendingTutors = $conn->query("SELECT COUNT(*) as count FROM users WHERE role = 'tutor' AND status = 'pending'")->fetch_assoc()['count'];
$totalBookings = $conn->query("SELECT COUNT(*) as count FROM bookings")->fetch_assoc()['count'];
$pendingPayouts = $conn->query("SELECT COUNT(*) as count FROM payout_requests WHERE status = 'pending'")->fetch_assoc()['count'] ?? 0;
$pendingPayments = $conn->query("SELECT COUNT(*) as count FROM payments WHERE status = 'pending'")->fetch_assoc()['count'];
$pendingDisputes = $conn->query("SELECT COUNT(*) as count FROM disputes WHERE status = 'pending'")->fetch_assoc()['count'];
$pendingReports = $conn->query("SELECT COUNT(*) as count FROM session_reports WHERE report_status = 'submitted'")->fetch_assoc()['count'];

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
    <title>Kyoshi | All Users</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../css/astyle.css">
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
            min-height: 100vh;
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
        
        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 24px;
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
        
        /* Filter Bar */
        .filter-bar {
            background: white;
            border-radius: 16px;
            padding: 16px 20px;
            margin-bottom: 24px;
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            align-items: center;
        }
        
        .filter-group {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .filter-group label {
            font-weight: 600;
            color: #555;
            font-size: 13px;
        }
        
        .filter-group select, .filter-group input {
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 8px;
            outline: none;
            font-family: inherit;
        }
        
        .btn-filter {
            padding: 8px 16px;
            background: #E75A9B;
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
        }
        
        .btn-reset {
            padding: 8px 16px;
            background: #6c757d;
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            text-decoration: none;
            font-size: 13px;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        
        .btn-add {
            margin-left: auto;
            background: #28a745;
        }
        
        /* Users Table */
        .users-table-container {
            background: white;
            border-radius: 16px;
            overflow-x: auto;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }
        
        .users-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 800px;
        }
        
        .users-table th,
        .users-table td {
            padding: 15px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }
        
        .users-table th {
            background: #f8f9fa;
            font-weight: 600;
            color: #555;
            font-size: 13px;
        }
        
        .users-table tr:hover {
            background: #f8f9fa;
        }
        
        .badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }
        
        .badge-student {
            background: #e3f2fd;
            color: #1976d2;
        }
        
        .badge-tutor {
            background: #f3e5f5;
            color: #7b1fa2;
        }
        
        .badge-admin {
            background: #ffebee;
            color: #c62828;
        }
        
        .badge-approved {
            background: #e8f5e9;
            color: #2e7d32;
        }
        
        .badge-pending {
            background: #fff3e0;
            color: #ef6c00;
        }
        
        .badge-inactive {
            background: #fafafa;
            color: #757575;
        }
        
        .action-buttons {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        
        .btn-icon {
            padding: 6px 12px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 12px;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            text-decoration: none;
            font-weight: 500;
        }
        
        .btn-edit {
            background: #ffc107;
            color: #333;
        }
        
        .btn-delete {
            background: #dc3545;
            color: white;
        }
        
        .btn-toggle {
            background: #17a2b8;
            color: white;
        }
        
        .btn-toggle.active {
            background: #f59e0b;
        }
        
        /* Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }
        
        .modal.active {
            display: flex;
        }
        
        .modal-content {
            background: white;
            border-radius: 20px;
            padding: 28px;
            width: 500px;
            max-width: 90%;
        }
        
        .modal-content h3 {
            margin-bottom: 20px;
            color: #302E63;
        }
        
        .modal-content input,
        .modal-content select {
            width: 100%;
            padding: 12px;
            margin-bottom: 15px;
            border: 1px solid #ddd;
            border-radius: 10px;
            font-family: inherit;
        }
        
        .modal-content input:focus,
        .modal-content select:focus {
            outline: none;
            border-color: #E75A9B;
        }
        
        .modal-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 20px;
        }
        
        /* Alert messages */
        .alert-success, .alert-error {
            padding: 12px 16px;
            border-radius: 12px;
            margin-bottom: 20px;
            font-size: 13px;
        }
        
        .alert-success {
            background: #d4edda;
            color: #155724;
            border-left: 4px solid #28a745;
        }
        
        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border-left: 4px solid #dc2626;
        }
        
        /* Status badge with dot */
        .status-dot {
            display: inline-block;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            margin-right: 6px;
        }
        
        .status-dot.active { background: #28a745; box-shadow: 0 0 4px #28a745; }
        .status-dot.inactive { background: #dc3545; }
        .status-dot.pending { background: #ffc107; }
        
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
            
            .filter-bar {
                flex-direction: column;
                align-items: stretch;
            }
            
            .filter-group {
                justify-content: space-between;
            }
            
            .btn-add {
                margin-left: 0;
            }
        }

        /* ============================================
   MOBILE FILTER BAR RESPONSIVE (max-width: 900px)
   ============================================ */

@media (max-width: 900px) {
    /* Filter bar - stack vertically */
    .filter-bar {
        flex-direction: column !important;
        align-items: stretch !important;
        gap: 12px !important;
        padding: 16px !important;
    }
    
    /* Filter bar form - stack all elements */
    .filter-bar form {
        display: flex !important;
        flex-direction: column !important;
        gap: 10px !important;
        width: 100% !important;
    }
    
    /* Filter groups - full width */
    .filter-group {
        display: flex !important;
        flex-direction: column !important;
        align-items: flex-start !important;
        gap: 6px !important;
        width: 100% !important;
    }
    
    .filter-group label {
        font-size: 12px !important;
        margin-bottom: 2px !important;
    }
    
    /* Select and input - full width */
    .filter-group select,
    .filter-group input {
        width: 100% !important;
        padding: 10px 12px !important;
        font-size: 13px !important;
    }
    
    /* Search input group - full width */
    .filter-group input[name="search"] {
        width: 100% !important;
    }
    
    /* Buttons - full width, side by side */
    .btn-filter,
    .btn-reset {
        width: calc(50% - 5px) !important;
        display: inline-block !important;
        text-align: center !important;
        padding: 10px 12px !important;
    }
    
    /* Form buttons container */
    .filter-bar form div:last-child,
    .filter-bar form .button-group {
        display: flex !important;
        gap: 10px !important;
        width: 100% !important;
    }
    
    /* Add User button - full width, separate */
    .filter-bar .btn-add,
    .filter-bar > .btn-icon.btn-edit {
        width: 100% !important;
        text-align: center !important;
        justify-content: center !important;
        margin-top: 5px !important;
    }
}

/* Even smaller phones */
@media (max-width: 480px) {
    .btn-filter,
    .btn-reset {
        width: 100% !important;
        margin-bottom: 5px !important;
    }
    
    .filter-bar form {
        gap: 8px !important;
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
            </a>
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
                <span class="nav-badge pending"><?= $pendingReports ?></span>
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
        <img src="<?= e($assetBase) ?>/logo.png" alt="Kyoshi" class="mobile-logo-img">
        <span class="mobile-logo-text">KYOSHI</span>
    </div>
    
    <!-- Desktop Title with Back Button Beside It -->
    <div class="page-title">
        <div class="title-with-back">
            <a href="admin_dashboard.php" class="back-btn-desktop">
                <i class="bi bi-arrow-left"></i>
                <span>Back</span>
            </a>
            <h1>All User</h1>
        </div>
    </div>
    
    <div class="relative">
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

<!-- Mobile Page Header with Arrow Only (no text) -->
<div class="mobile-page-header" style="margin-top: 20px;">
    <div class="mobile-title-with-back">
        <a href="admin_dashboard.php"  class="mobile-back-arrow">
            <i class="bi bi-arrow-left"></i>
        </a>
        <h1 class="mobile-page-title">All User</h1>
    </div>
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



    <!-- Filter Bar -->
    <div class="filter-bar">
        <form method="GET" style="display: flex; gap: 15px; flex-wrap: wrap; align-items: center; flex: 1;">
            <div class="filter-group">
                <label>Role:</label>
                <select name="role" onchange="this.form.submit()">
                    <option value="all" <?= $role_filter === 'all' ? 'selected' : '' ?>>All</option>
                    <option value="student" <?= $role_filter === 'student' ? 'selected' : '' ?>>Student</option>
                    <option value="tutor" <?= $role_filter === 'tutor' ? 'selected' : '' ?>>Tutor</option>
                    <option value="admin" <?= $role_filter === 'admin' ? 'selected' : '' ?>>Admin</option>
                </select>
            </div>
            <div class="filter-group">
                <label>Status:</label>
                <select name="status" onchange="this.form.submit()">
                    <option value="all" <?= $status_filter === 'all' ? 'selected' : '' ?>>All</option>
                    <option value="approved" <?= $status_filter === 'approved' ? 'selected' : '' ?>>Active</option>
                    <option value="pending" <?= $status_filter === 'pending' ? 'selected' : '' ?>>Pending</option>
                    <option value="inactive" <?= $status_filter === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                </select>
            </div>
            <div class="filter-group" style="flex: 2;">
                <input type="text" name="search" placeholder="Search by name, email or phone..." value="<?= e($search) ?>">
            </div>
            <button type="submit" class="btn-filter">Search</button>
            <a href="admin_all_users.php" class="btn-reset">Reset</a>
        </form>
        <a href="admin_create_user.php?return_to=admin_all_users.php" class="btn-icon btn-edit" style="background: #28a745; color: white;">
            <i class="bi bi-person-plus-fill"></i> Add User
        </a>
    </div>

    <!-- Users Table -->
    <div class="users-table-container">
        <table class="users-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Phone</th>
                    <th>Role</th>
                    <th>Status</th>
                    <th>Joined On</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($users->num_rows > 0): ?>
                    <?php while ($user = $users->fetch_assoc()): ?>
                        <tr>
                            <td>
                                <div style="display: flex; align-items: center; gap: 10px;">
                                    <?php if (!empty($user['profile_pic']) && file_exists('../uploads/profiles/' . $user['profile_pic'])): ?>
                                    <img src="../uploads/profiles/<?= e($user['profile_pic']) ?>" style="width: 35px; height: 35px; border-radius: 50%; object-fit: cover;">
                                <?php else: ?>
                                    <?php 
                                    // Use correct path based on user role
                                    $role = $user['role'] ?? 'user';
                                    $defaultAvatar = '../assets/img/profile.png';
                                    
                                    if ($role === 'admin') {
                                        $defaultAvatar = '../assets/img/profile-admin.png';
                                    } elseif ($role === 'tutor') {
                                        $defaultAvatar = '../assets/img/profile-tutor.png';
                                    } elseif ($role === 'student') {
                                        $defaultAvatar = '../assets/img/profile.png';
                                    }
                                    ?>
                                    <img src="<?= $defaultAvatar ?>" style="width: 35px; height: 35px; border-radius: 50%; object-fit: cover; background: #e0e0e0;">
                                <?php endif; ?>
                                    <strong><?= e($user['fullname']) ?></strong>
                                </div>
                            </td>
                            <td><?= e($user['email']) ?></td>
                            <td><?= e($user['phone'] ?: '-') ?></td>
                            <td>
                                <span class="badge badge-<?= $user['role'] ?>">
                                    <i class="bi bi-<?= $user['role'] === 'student' ? 'mortarboard' : ($user['role'] === 'tutor' ? 'person-badge' : 'shield') ?>"></i>
                                    <?= ucfirst($user['role']) ?>
                                </span>
                                <?php if ($user['role'] === 'tutor' && $user['pending_certs'] > 0): ?>
                                    <span class="badge badge-pending" style="margin-left: 5px;"><?= $user['pending_certs'] ?> cert pending</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge badge-<?= $user['status'] ?>">
                                    <span class="status-dot <?= $user['status'] === 'approved' ? 'active' : ($user['status'] === 'inactive' ? 'inactive' : 'pending') ?>"></span>
                                    <?= $user['status'] === 'approved' ? 'Active' : ucfirst($user['status']) ?>
                                </span>
                            </td>
                            <td><?= date('d M Y', strtotime($user['created_at'])) ?></td>
                            <td>
                                <div class="action-buttons">
                                    <?php if ($user['status'] === 'pending'): ?>
                                        <a href="admin_verify_tutors.php?user_id=<?= $user['id'] ?>" class="btn-icon" style="background: #28a745; color: white;">
                                            <i class="bi bi-check-circle"></i> Approve
                                        </a>
                                    <?php endif; ?>
                                    <button onclick="openEditModal(<?= htmlspecialchars(json_encode($user)) ?>)" class="btn-icon btn-edit">
                                        <i class="bi bi-pencil"></i> Edit
                                    </button>
                                    <button onclick="toggleStatus(<?= $user['id'] ?>, '<?= $user['status'] ?>')" class="btn-icon btn-toggle <?= $user['status'] === 'inactive' ? 'active' : '' ?>">
                                        <i class="bi bi-<?= $user['status'] === 'inactive' ? 'play-circle' : 'pause-circle' ?>"></i>
                                        <?= $user['status'] === 'inactive' ? 'Activate' : 'Deactivate' ?>
                                    </button>
                                    <?php if ($user['id'] != $adminID): ?>
                                        <button onclick="confirmDelete(<?= $user['id'] ?>)" class="btn-icon btn-delete">
                                            <i class="bi bi-trash"></i> Delete
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="7" style="text-align: center; padding: 40px;">
                            <i class="bi bi-people" style="font-size: 48px; color: #ccc;"></i>
                            <p style="margin-top: 10px; color: #999;">No users found</p>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Edit User Modal -->
<div class="modal" id="editModal">
    <div class="modal-content">
        <h3><i class="bi bi-pencil-square"></i> Edit User</h3>
        <form method="POST" action="">
            <input type="hidden" name="user_id" id="edit_user_id">
            <input type="hidden" name="edit_user" value="1">
            <div class="form-group">
                <label>Full Name</label>
                <input type="text" name="fullname" id="edit_fullname" required>
            </div>
            <div class="form-group">
                <label>Email</label>
                <input type="email" name="email" id="edit_email" required>
            </div>
            <div class="form-group">
                <label>Phone</label>
                <input type="text" name="phone" id="edit_phone">
            </div>
            <div class="form-group">
                <label>Role</label>
                <select name="role" id="edit_role">
                    <option value="student">Student</option>
                    <option value="tutor">Tutor</option>
                    <option value="admin">Admin</option>
                </select>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn-icon" style="background: #6c757d; color: white;" onclick="closeEditModal()">Cancel</button>
                <button type="submit" class="btn-icon" style="background: #E75A9B; color: white;">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- Delete Confirmation Form -->
<form id="deleteForm" method="POST" style="display: none;">
    <input type="hidden" name="delete_user" value="1">
    <input type="hidden" name="user_id" id="delete_user_id">
</form>

<!-- Toggle Status Form -->
<form id="toggleForm" method="POST" style="display: none;">
    <input type="hidden" name="toggle_status" value="1">
    <input type="hidden" name="user_id" id="toggle_user_id">
    <input type="hidden" name="current_status" id="toggle_current_status">
</form>

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

function openEditModal(user) {
    document.getElementById('edit_user_id').value = user.id;
    document.getElementById('edit_fullname').value = user.fullname;
    document.getElementById('edit_email').value = user.email;
    document.getElementById('edit_phone').value = user.phone || '';
    document.getElementById('edit_role').value = user.role;
    document.getElementById('editModal').classList.add('active');
}

function closeEditModal() {
    document.getElementById('editModal').classList.remove('active');
}

function toggleStatus(userId, currentStatus) {
    const action = currentStatus === 'inactive' ? 'activate' : 'deactivate';
    const message = currentStatus === 'inactive' 
        ? 'Are you sure you want to ACTIVATE this user? They will be able to use the platform again.'
        : 'Are you sure you want to DEACTIVATE this user? They will not be able to book or conduct sessions.';
    
    if (confirm(message)) {
        document.getElementById('toggle_user_id').value = userId;
        document.getElementById('toggle_current_status').value = currentStatus;
        document.getElementById('toggleForm').submit();
    }
}

function confirmDelete(userId) {
    if (confirm('⚠️ WARNING: This action cannot be undone!\n\nAre you sure you want to permanently delete this user? The user must have no existing bookings.')) {
        document.getElementById('delete_user_id').value = userId;
        document.getElementById('deleteForm').submit();
    }
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

// Close modal when clicking outside
document.getElementById('editModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeEditModal();
    }
});
</script>
<script>
history.pushState(null, null, location.href);
window.addEventListener('popstate', function() {
    window.location.href = 'login.php';
});
</script>
</body>
</html>