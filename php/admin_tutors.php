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

// Get stats
$totalTutors = $conn->query("SELECT COUNT(*) as count FROM users WHERE role = 'tutor'")->fetch_assoc()['count'];
$pendingTutors = $conn->query("SELECT COUNT(*) as count FROM users WHERE role = 'tutor' AND status = 'pending'")->fetch_assoc()['count'];
$approvedTutors = $conn->query("SELECT COUNT(*) as count FROM users WHERE role = 'tutor' AND status = 'active'")->fetch_assoc()['count'];
$rejectedTutors = $conn->query("SELECT COUNT(*) as count FROM users WHERE role = 'tutor' AND status = 'rejected'")->fetch_assoc()['count'];
$resignedTutors = $conn->query("SELECT COUNT(*) as count FROM users WHERE role = 'tutor' AND status = 'resigned'")->fetch_assoc()['count'];
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

// Get filter parameter
$filter = $_GET['filter'] ?? 'pending'; // Default to 'pending' for verify tutor first
$search = $_GET['search'] ?? '';

// Build tutor query
$tutorQuery = "SELECT u.*, tp.bio, tp.rate, tp.experience 
               FROM users u 
               LEFT JOIN tutor_profiles tp ON u.id = tp.user_id 
               WHERE u.role = 'tutor'";

if ($filter == 'pending') {
    $tutorQuery .= " AND u.status = 'pending'";
} elseif ($filter == 'active') {
    $tutorQuery .= " AND u.status = 'active'";
} elseif ($filter == 'rejected') {
    $tutorQuery .= " AND u.status = 'rejected'";
} elseif ($filter == 'resigned') {
    $tutorQuery .= " AND u.status = 'resigned'";
}

if (!empty($search)) {
    $tutorQuery .= " AND (u.fullname LIKE '%$search%' OR u.email LIKE '%$search%')";
}

$tutorQuery .= " ORDER BY u.created_at DESC";
$tutors = $conn->query($tutorQuery);

// Handle POST actions
$message = '';
$error = '';

// Approve tutor
if (isset($_POST['approve_tutor'])) {
    $tutor_id = intval($_POST['tutor_id']);
    $stmt = $conn->prepare("UPDATE users SET status = 'active' WHERE id = ? AND role = 'tutor'");
    $stmt->bind_param("i", $tutor_id);
    if ($stmt->execute()) {
        $message = "Tutor approved successfully!";
        // Redirect to refresh page
        header("Location: admin_tutors.php?filter=pending&success=1");
        exit();
    } else {
        $error = "Failed to approve tutor.";
    }
}

// Reject tutor
if (isset($_POST['reject_tutor'])) {
    $tutor_id = intval($_POST['tutor_id']);
    $reason = $_POST['reject_reason'] ?? '';
    $stmt = $conn->prepare("UPDATE users SET status = 'rejected' WHERE id = ? AND role = 'tutor'");
    $stmt->bind_param("i", $tutor_id);
    if ($stmt->execute()) {
        $message = "Tutor rejected.";
        header("Location: admin_tutors.php?filter=pending");
        exit();
    } else {
        $error = "Failed to reject tutor.";
    }
}

// Edit User
if (isset($_POST['edit_user'])) {
    $user_id = intval($_POST['user_id']);
    $fullname = $_POST['fullname'];
    $email = $_POST['email'];
    $phone = $_POST['phone'];
    
    $stmt = $conn->prepare("UPDATE users SET fullname = ?, email = ?, phone = ? WHERE id = ?");
    $stmt->bind_param("sssi", $fullname, $email, $phone, $user_id);
    if ($stmt->execute()) {
        $message = "User updated successfully!";
    } else {
        $error = "Failed to update user.";
    }
}

// Mark as resigned
if (isset($_POST['resign_tutor'])) {
    $tutor_id = intval($_POST['tutor_id']);
    $stmt = $conn->prepare("UPDATE users SET status = 'resigned' WHERE id = ? AND role = 'tutor'");
    $stmt->bind_param("i", $tutor_id);
    if ($stmt->execute()) {
        $message = "Tutor marked as resigned.";
    } else {
        $error = "Failed to update tutor status.";
    }
}

// Reactivate tutor
if (isset($_POST['reactivate_tutor'])) {
    $tutor_id = intval($_POST['tutor_id']);
    $stmt = $conn->prepare("UPDATE users SET status = 'active' WHERE id = ? AND role = 'tutor'");
    $stmt->bind_param("i", $tutor_id);
    if ($stmt->execute()) {
        $message = "Tutor reactivated successfully!";
    } else {
        $error = "Failed to reactivate tutor.";
    }
}

// Delete user
if (isset($_POST['delete_user'])) {
    $user_id = intval($_POST['user_id']);
    $stmt = $conn->prepare("DELETE FROM users WHERE id = ? AND role = 'tutor'");
    $stmt->bind_param("i", $user_id);
    if ($stmt->execute()) {
        $message = "Tutor deleted successfully!";
        header("Location: admin_tutors.php?deleted=1");
        exit();
    } else {
        $error = "Failed to delete tutor.";
    }
}

// Add qualification
if (isset($_POST['add_qualification'])) {
    $tutor_id = intval($_POST['tutor_id']);
    $cert_name = $_POST['cert_name'];
    $institution = $_POST['institution'];
    $year_obtained = $_POST['year_obtained'];
    $cert_file = '';
    
    if (isset($_FILES['cert_file']) && $_FILES['cert_file']['error'] == 0) {
        $allowed = ['pdf', 'jpg', 'jpeg', 'png'];
        $filename = $_FILES['cert_file']['name'];
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (in_array($ext, $allowed)) {
            $upload_dir = '../uploads/certificates/';
            if (!file_exists($upload_dir)) mkdir($upload_dir, 0777, true);
            $cert_file = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', $filename);
            move_uploaded_file($_FILES['cert_file']['tmp_name'], $upload_dir . $cert_file);
        }
    }
    
    $stmt = $conn->prepare("INSERT INTO tutor_qualifications (tutor_id, certificate_name, institution, year_obtained, file_path, status, created_at) VALUES (?, ?, ?, ?, ?, 'pending', NOW())");
    $stmt->bind_param("issss", $tutor_id, $cert_name, $institution, $year_obtained, $cert_file);
    if ($stmt->execute()) {
        $message = "Qualification added! Waiting for approval.";
    } else {
        $error = "Failed to add qualification.";
    }
}

function e($value) {
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function getStatusBadge($status) {
    switch($status) {
        case 'active': return '<span style="background:#28a745; color:white; padding:2px 8px; border-radius:20px; font-size:11px;">Active</span>';
        case 'pending': return '<span style="background:#f59e0b; color:white; padding:2px 8px; border-radius:20px; font-size:11px;">Pending</span>';
        case 'rejected': return '<span style="background:#dc3545; color:white; padding:2px 8px; border-radius:20px; font-size:11px;">Rejected</span>';
        case 'resigned': return '<span style="background:#6c757d; color:white; padding:2px 8px; border-radius:20px; font-size:11px;">Resigned</span>';
        default: return '<span style="background:#6c757d; color:white; padding:2px 8px; border-radius:20px; font-size:11px;">Unknown</span>';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kyoshi | Manage Tutors</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600&family=Open+Sans&display=swap" rel="stylesheet">
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
        
        .sidebar {
            position: fixed;
            left: 0;
            top: 0;
            width: 250px;
            height: 100vh;
            background: #272754;
            color: #E8E4F0;
            overflow-y: hidden;
            z-index: 1000;
            transition: transform 0.3s ease;
            display: flex;
            flex-direction: column;
        }
        
        .sidebar.closed { transform: translateX(-100%); }
        
        .sidebar-header {
            padding: 28px 20px;
            flex-shrink: 0;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        
        .nav-menu {
            flex: 1;
            display: flex;
            flex-direction: column;
            overflow-y: auto;
            overflow-x: hidden;
            padding: 16px 0;
        }
        
        .nav-menu::-webkit-scrollbar { width: 4px; }
        .nav-menu::-webkit-scrollbar-track { background: rgba(255,255,255,0.1); }
        .nav-menu::-webkit-scrollbar-thumb { background: #B26EA7; border-radius: 4px; }
        
        .sidebar-footer {
            padding: 20px;
            border-top: 1px solid rgba(255,255,255,0.15);
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-shrink: 0;
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
        
        .nav-item:hover, .nav-item.active {
            background: rgba(255,255,255,0.1);
            border-left-color: #B26EA7;
            color: white;
        }
        
        .nav-item i { width: 20px; font-size: 1.1rem; }
        
        .nav-section { margin-bottom: 8px; }
        
        .nav-section-label {
            padding: 12px 20px 6px 20px;
            font-size: 0.65rem;
            font-weight: 600;
            color: #B26EA7;
            text-transform: uppercase;
            letter-spacing: 1px;
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
        
        /* ========== MAIN CONTENT ========== */
        .main-content {
            margin-left: 250px;
            padding: 20px 24px;
            transition: margin-left 0.3s ease;
        }
        
        .top-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 16px;
            margin-bottom: 24px;
        }
        
        .page-title h1 { font-size: 1.4rem; font-weight: 700; color: #302E63; }
        
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
            gap: 12px;
            flex: 1;
            overflow: hidden;
        }
        
        .admin-details { display: flex; flex-direction: column; overflow: hidden; }
        
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
        }
        
        .logout-icon:hover { background: rgba(220, 38, 38, 0.4); color: white; }
        
        .brand-wrapper { display: flex; align-items: center; gap: 12px; }
        .brand-icon { width: 50px; height: 50px; object-fit: contain; }
        
        .brand-title h1 {
            font-size: 1.3rem;
            font-weight: 700;
            background: linear-gradient(135deg, #ffffff, #B26EA7);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }
        
        .admin-space-text { font-size: 0.55rem; color: #e7c7f7; }
        
        .relative { position: relative; }
        
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
        
        /* ========== TABLE STYLES ========== */
        .tutors-table-container {
            background: white;
            border-radius: 20px;
            border: 1px solid #E4DCF0;
            overflow-x: auto;
            margin-top: 20px;
        }
        
        .tutors-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.8rem;
        }
        
        .tutors-table th {
            text-align: left;
            padding: 15px 16px;
            background: #f8f9fa;
            color: #302E63;
            font-weight: 700;
            border-bottom: 2px solid #E4DCF0;
        }
        
        .tutors-table td {
            padding: 14px 16px;
            border-bottom: 1px solid #E4DCF0;
            vertical-align: middle;
        }
        
        .tutors-table tr:hover {
            background: rgba(242,138,178,0.05);
        }
        
        .table-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            object-fit: cover;
        }
        
        .table-actions {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
        }
        
        .btn-table {
            padding: 5px 10px;
            border-radius: 15px;
            font-size: 0.7rem;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        
        .btn-approve { background: #28a745; color: white; }
        .btn-reject { background: #dc3545; color: white; }
        .btn-edit { background: #875D9C; color: white; }
        .btn-resign { background: #f59e0b; color: white; }
        .btn-reactivate { background: #17a2b8; color: white; }
        .btn-delete { background: #6c757d; color: white; }
        .btn-view { background: #e9ecef; color: #342635; border: 1px solid #ddd; }
        
        .filter-bar {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-bottom: 20px;
            align-items: center;
        }
        
        .filter-btn {
            padding: 8px 16px;
            border-radius: 30px;
            font-size: 0.8rem;
            font-weight: 600;
            text-decoration: none;
            background: white;
            color: #342635;
            border: 1px solid #E4DCF0;
        }
        
        .filter-btn.active { background: #875D9C; color: white; border-color: #875D9C; }
        
        .search-box {
            display: flex;
            gap: 10px;
            margin-left: auto;
        }
        
        .search-box input {
            padding: 8px 15px;
            border-radius: 30px;
            border: 1px solid #E4DCF0;
            font-size: 0.8rem;
            width: 200px;
        }
        
        .search-box button {
            padding: 8px 15px;
            border-radius: 30px;
            background: #875D9C;
            color: white;
            border: none;
            cursor: pointer;
        }
        
        /* Pending alert banner */
        .pending-alert {
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
        }
        
        /* Modal */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1001;
            display: none;
            align-items: center;
            justify-content: center;
        }
        
        .modal-overlay.active { display: flex; }
        
        .modal-box {
            background: white;
            border-radius: 24px;
            padding: 25px;
            width: 500px;
            max-width: 90%;
            max-height: 85vh;
            overflow-y: auto;
        }
        
        .modal-box h3 { margin-bottom: 15px; color: #302E63; }
        .modal-box input, .modal-box select, .modal-box textarea {
            width: 100%;
            padding: 10px;
            margin-bottom: 15px;
            border: 1px solid #E4DCF0;
            border-radius: 10px;
            font-family: inherit;
        }
        
        .modal-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 15px;
        }
        
        .toast {
            position: fixed;
            bottom: 30px;
            left: 50%;
            transform: translateX(-50%);
            padding: 12px 24px;
            border-radius: 30px;
            color: white;
            font-weight: 600;
            font-size: 0.85rem;
            z-index: 1100;
            display: none;
        }
        
        .toast.success { background: #28a745; display: block; }
        .toast.error { background: #dc3545; display: block; }
        
        @media (max-width: 768px) {
            .menu-toggle { display: block; }
            .sidebar { transform: translateX(-100%); }
            .sidebar.open { transform: translateX(0); }
            .main-content { margin-left: 0; }
            .filter-bar { flex-direction: column; align-items: stretch; }
            .search-box { margin-left: 0; }
            .search-box input { width: 100%; }
            .tutors-table th, .tutors-table td { padding: 10px 8px; }
            .table-actions { flex-direction: column; }
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
        <button class="menu-toggle" id="menuToggle"><i class="bi bi-list"></i> Menu</button>
        <div class="page-title">
            <h1>Manage Tutors</h1>
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

    <?php if ($message): ?>
    <div class="toast success" id="toast"><?= $message ?></div>
    <script>setTimeout(() => document.getElementById('toast')?.remove(), 3000);</script>
    <?php endif; ?>
    <?php if ($error): ?>
    <div class="toast error" id="toastError"><?= $error ?></div>
    <script>setTimeout(() => document.getElementById('toastError')?.remove(), 3000);</script>
    <?php endif; ?>

    <!-- Pending Alert - Show when there are pending tutors -->
    <?php if ($pendingTutors > 0 && $filter != 'pending'): ?>
    <div class="pending-alert">
        <div class="alert-content">
            <i class="bi bi-person-plus"></i>
            <div>
                <strong><?= $pendingTutors ?> new tutor application(s) waiting for verification</strong>
                <p>Review and approve qualified tutors to join the platform</p>
            </div>
        </div>
        <a href="?filter=pending" class="filter-btn" style="background:#F59E0B; color:white; border:none;">Verify Now →</a>
    </div>
    <?php endif; ?>

    <div class="filter-bar">
        <a href="?filter=pending" class="filter-btn <?= $filter == 'pending' ? 'active' : '' ?>">Pending (<?= $pendingTutors ?>)</a>
        <a href="?filter=active" class="filter-btn <?= $filter == 'active' ? 'active' : '' ?>">Active (<?= $approvedTutors ?>)</a>
        <a href="?filter=all" class="filter-btn <?= $filter == 'all' ? 'active' : '' ?>">All (<?= $totalTutors ?>)</a>
        <a href="?filter=rejected" class="filter-btn <?= $filter == 'rejected' ? 'active' : '' ?>">Rejected (<?= $rejectedTutors ?>)</a>
        <a href="?filter=resigned" class="filter-btn <?= $filter == 'resigned' ? 'active' : '' ?>">Resigned (<?= $resignedTutors ?>)</a>
        
        <form class="search-box" method="GET">
            <input type="text" name="search" placeholder="Search tutors..." value="<?= e($search) ?>">
            <input type="hidden" name="filter" value="<?= e($filter) ?>">
            <button type="submit"><i class="bi bi-search"></i></button>
        </form>
    </div>

    <!-- TABLE FORMAT -->
    <div class="tutors-table-container">
        <table class="tutors-table">
            <thead>
                <tr>
                    <th>Avatar</th>
                    <th>Name & Email</th>
                    <th>Phone</th>
                    <th>Experience</th>
                    <th>Rate</th>
                    <th>Status</th>
                    <th>Joined</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($tutors && $tutors->num_rows > 0): ?>
                    <?php while ($tutor = $tutors->fetch_assoc()): ?>
                        <tr>
                            <td>
                                <img src="<?= !empty($tutor['profile_pic']) ? '../uploads/profiles/' . $tutor['profile_pic'] : $assetBase . '/profile-tutor.png' ?>" class="table-avatar">
                            </td>
                            <td>
                                <strong><?= e($tutor['fullname']) ?></strong><br>
                                <small style="color:#7B6E8F;"><?= e($tutor['email']) ?></small>
                            </td>
                            <td><?= e($tutor['phone'] ?? '-') ?></td>
                            <td><?= e($tutor['experience'] ?? 0) ?> yrs</td>
                            <td>RM <?= e($tutor['rate'] ?? 0) ?></td>
                            <td><?= getStatusBadge($tutor['status']) ?></td>
                            <td><?= date('d M Y', strtotime($tutor['created_at'])) ?></td>
                            <td class="table-actions">
                                <!-- VIEW button -->
                                <button class="btn-table btn-view" onclick="viewTutorDetails(<?= $tutor['id'] ?>, '<?= e($tutor['fullname']) ?>')">
                                    <i class="bi bi-eye"></i> View
                                </button>
                                
                                <!-- EDIT button -->
                                <button class="btn-table btn-edit" onclick="editTutor(<?= $tutor['id'] ?>, '<?= e($tutor['fullname']) ?>', '<?= e($tutor['email']) ?>', '<?= e($tutor['phone']) ?>')">
                                    <i class="bi bi-pencil"></i> Edit
                                </button>
                                
                                <!-- PENDING: Approve/Reject buttons -->
                                <?php if ($tutor['status'] == 'pending'): ?>
                                    <button class="btn-table btn-approve" onclick="approveTutor(<?= $tutor['id'] ?>)">
                                        <i class="bi bi-check-lg"></i> Approve
                                    </button>
                                    <button class="btn-table btn-reject" onclick="rejectTutor(<?= $tutor['id'] ?>)">
                                        <i class="bi bi-x-lg"></i> Reject
                                    </button>
                                
                                <!-- ACTIVE: Resign + Delete -->
                                <?php elseif ($tutor['status'] == 'active'): ?>
                                    <button class="btn-table btn-resign" onclick="resignTutor(<?= $tutor['id'] ?>)">
                                        <i class="bi bi-person-down"></i> Resign
                                    </button>
                                    <button class="btn-table btn-delete" onclick="deleteTutor(<?= $tutor['id'] ?>, '<?= e($tutor['fullname']) ?>')">
                                        <i class="bi bi-trash"></i> Delete
                                    </button>
                                
                                <!-- RESIGNED: Reactivate -->
                                <?php elseif ($tutor['status'] == 'resigned'): ?>
                                    <button class="btn-table btn-reactivate" onclick="reactivateTutor(<?= $tutor['id'] ?>)">
                                        <i class="bi bi-arrow-repeat"></i> Reactivate
                                    </button>
                                    <button class="btn-table btn-delete" onclick="deleteTutor(<?= $tutor['id'] ?>, '<?= e($tutor['fullname']) ?>')">
                                        <i class="bi bi-trash"></i> Delete
                                    </button>
                                
                                <!-- REJECTED: Approve Anyway -->
                                <?php elseif ($tutor['status'] == 'rejected'): ?>
                                    <button class="btn-table btn-approve" onclick="approveTutor(<?= $tutor['id'] ?>)">
                                        <i class="bi bi-check-lg"></i> Approve
                                    </button>
                                    <button class="btn-table btn-delete" onclick="deleteTutor(<?= $tutor['id'] ?>, '<?= e($tutor['fullname']) ?>')">
                                        <i class="bi bi-trash"></i> Delete
                                    </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="8" style="text-align: center; padding: 50px;">
                            <i class="bi bi-people" style="font-size: 48px; color: #ccc;"></i>
                            <p style="margin-top: 15px;">No tutors found.</p>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- View Details Modal -->
<div class="modal-overlay" id="viewDetailsModal">
    <div class="modal-box" style="width: 600px;">
        <h3 id="detailsTitle">Tutor Details</h3>
        <div id="detailsContent"></div>
        <div class="modal-actions">
            <button onclick="closeModal('viewDetailsModal')" class="btn-table btn-delete">Close</button>
        </div>
    </div>
</div>

<!-- Edit User Modal -->
<div class="modal-overlay" id="editUserModal">
    <div class="modal-box">
        <h3>Edit Tutor Information</h3>
        <form method="POST">
            <input type="hidden" name="user_id" id="edit_user_id">
            <input type="text" name="fullname" id="edit_fullname" placeholder="Full Name" required>
            <input type="email" name="email" id="edit_email" placeholder="Email" required>
            <input type="text" name="phone" id="edit_phone" placeholder="Phone">
            <div class="modal-actions">
                <button type="button" onclick="closeModal('editUserModal')" class="btn-table btn-delete">Cancel</button>
                <button type="submit" name="edit_user" class="btn-table btn-approve">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- Reject Reason Modal -->
<div class="modal-overlay" id="rejectModal">
    <div class="modal-box">
        <h3>Reject Tutor Application</h3>
        <form method="POST">
            <input type="hidden" name="tutor_id" id="reject_tutor_id">
            <label>Reason for rejection:</label>
            <textarea name="reject_reason" rows="3" placeholder="Please provide a reason for rejecting this tutor..." required></textarea>
            <div class="modal-actions">
                <button type="button" onclick="closeModal('rejectModal')" class="btn-table btn-delete">Cancel</button>
                <button type="submit" name="reject_tutor" class="btn-table btn-reject">Reject</button>
            </div>
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

function closeModal(modalId) {
    document.getElementById(modalId).classList.remove('active');
}

function viewTutorDetails(tutorId, tutorName) {
    document.getElementById('detailsTitle').innerHTML = tutorName + ' - Full Details';
    document.getElementById('detailsContent').innerHTML = '<div style="text-align:center; padding:20px;">Loading...</div>';
    document.getElementById('viewDetailsModal').classList.add('active');
    
    fetch('admin_get_tutor_details.php?id=' + tutorId)
        .then(response => response.text())
        .then(data => {
            document.getElementById('detailsContent').innerHTML = data;
        })
        .catch(error => {
            document.getElementById('detailsContent').innerHTML = '<div style="text-align:center; padding:20px; color:red;">Error loading details.</div>';
        });
}

function editTutor(id, name, email, phone) {
    document.getElementById('edit_user_id').value = id;
    document.getElementById('edit_fullname').value = name;
    document.getElementById('edit_email').value = email;
    document.getElementById('edit_phone').value = phone || '';
    document.getElementById('editUserModal').classList.add('active');
}

function approveTutor(id) {
    if (confirm('Approve this tutor?')) {
        let form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = '<input type="hidden" name="tutor_id" value="' + id + '"><input type="hidden" name="approve_tutor" value="1">';
        document.body.appendChild(form);
        form.submit();
    }
}

function rejectTutor(id) {
    document.getElementById('reject_tutor_id').value = id;
    document.getElementById('rejectModal').classList.add('active');
}

function resignTutor(id) {
    if (confirm('Mark this tutor as resigned? They will no longer receive new bookings.')) {
        let form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = '<input type="hidden" name="tutor_id" value="' + id + '"><input type="hidden" name="resign_tutor" value="1">';
        document.body.appendChild(form);
        form.submit();
    }
}

function reactivateTutor(id) {
    if (confirm('Reactivate this tutor?')) {
        let form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = '<input type="hidden" name="tutor_id" value="' + id + '"><input type="hidden" name="reactivate_tutor" value="1">';
        document.body.appendChild(form);
        form.submit();
    }
}

function deleteTutor(id, name) {
    if (confirm('WARNING: Delete tutor "' + name + '"? This action cannot be undone!')) {
        let form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = '<input type="hidden" name="user_id" value="' + id + '"><input type="hidden" name="delete_user" value="1">';
        document.body.appendChild(form);
        form.submit();
    }
}

window.addEventListener('resize', function() {
    if (window.innerWidth > 768) {
        sidebar.classList.remove('open');
        if (overlay) overlay.classList.remove('active');
        document.body.style.overflow = '';
    }
});
</script>

</body>
</html>