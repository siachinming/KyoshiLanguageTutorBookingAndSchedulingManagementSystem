<?php
session_start();
include 'config.php';
$assetBase = '../assets/img';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

$adminID = $_SESSION['user_id'];
$stmt = $conn->prepare("SELECT * FROM users WHERE id = ? AND role = 'admin'");
$stmt->bind_param("i", $adminID);
$stmt->execute();
$admin = $stmt->get_result()->fetch_assoc();
$displayName = $admin['fullname'];
$profilePic = !empty($admin['profile_pic']) ? '../uploads/profiles/' . $admin['profile_pic'] : $assetBase . '/profile-admin.png';

// Handle booking cancellation by admin - ONLY if payment status is pending/unpaid
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_booking'])) {
    $booking_id = intval($_POST['booking_id']);
    $cancel_reason = trim($_POST['cancel_reason']);
    if (empty($cancel_reason)) $cancel_reason = "Cancelled by admin";

    // First check if booking exists and payment is not completed
    $check_sql = "SELECT b.id, b.status, p.status as payment_status 
                  FROM bookings b 
                  LEFT JOIN payments p ON b.id = p.booking_id 
                  WHERE b.id = ?";
    $check_stmt = $conn->prepare($check_sql);
    $check_stmt->bind_param("i", $booking_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    $booking_data = $check_result->fetch_assoc();

    if ($booking_data) {
        $payment_status = $booking_data['payment_status'] ?? 'pending';
        $can_cancel = ($payment_status == 'pending' || $payment_status == 'unpaid' || $payment_status === null);

        if ($can_cancel) {
            $update = $conn->prepare("UPDATE bookings SET status = 'cancelled', cancel_reason = ? WHERE id = ?");
            $update->bind_param("si", $cancel_reason, $booking_id);
            if ($update->execute()) {
                $_SESSION['success_message'] = "Booking #{$booking_id} cancelled successfully.";
            } else {
                $_SESSION['error_message'] = "Failed to cancel booking.";
            }
        } else {
            $_SESSION['error_message'] = "Cannot cancel this booking because payment has already been processed.";
        }
    } else {
        $_SESSION['error_message'] = "Booking not found.";
    }
    header("Location: admin_bookings.php");
    exit();
}

// Get filter params
$search = $_GET['search'] ?? '';
$status_filter = $_GET['status'] ?? 'all';
$order_by = "b.created_at DESC";

// Build base query with tutor & student names, learning method, cancellation reason, AND payment status
// NOTE: bookings table uses booking_date, booking_time, learning_mode, cancel_reason (no duration column)
$sql = "SELECT b.*, 
        u_tutor.fullname as tutor_name, u_tutor.email as tutor_email,
        u_student.fullname as student_name, u_student.email as student_email,
        b.learning_mode, b.cancelled_by, b.cancel_reason,
        p.status as payment_status, p.amount as payment_amount
        FROM bookings b
        LEFT JOIN users u_tutor ON b.tutor_id = u_tutor.id
        LEFT JOIN users u_student ON b.student_id = u_student.id
        LEFT JOIN payments p ON b.id = p.booking_id
        WHERE 1=1";

if (!empty($search)) {
    $search_like = $conn->real_escape_string($search);
    $sql .= " AND (u_tutor.fullname LIKE '%$search_like%' OR u_student.fullname LIKE '%$search_like%' OR b.id LIKE '%$search_like%')";
}
if ($status_filter !== 'all') {
    $status_filter_safe = $conn->real_escape_string($status_filter);
    $sql .= " AND b.status = '$status_filter_safe'";
}
$sql .= " ORDER BY $order_by";
$bookings_result = $conn->query($sql);

// Stats for dashboard counts
$total_bookings    = $conn->query("SELECT COUNT(*) as cnt FROM bookings")->fetch_assoc()['cnt'];
$pending_bookings  = $conn->query("SELECT COUNT(*) as cnt FROM bookings WHERE status = 'pending'")->fetch_assoc()['cnt'];
$completed_bookings = $conn->query("SELECT COUNT(*) as cnt FROM bookings WHERE status = 'completed'")->fetch_assoc()['cnt'];
$cancelled_bookings = $conn->query("SELECT COUNT(*) as cnt FROM bookings WHERE status = 'cancelled'")->fetch_assoc()['cnt'];

$totalUsers = $conn->query("SELECT COUNT(*) as count FROM users")->fetch_assoc()['count'];
$totalStudents = $conn->query("SELECT COUNT(*) as count FROM users WHERE role = 'student'")->fetch_assoc()['count'];
$totalTutors = $conn->query("SELECT COUNT(*) as count FROM users WHERE role = 'tutor'")->fetch_assoc()['count'];
$pendingTutors = $conn->query("SELECT COUNT(*) as count FROM users WHERE role = 'tutor' AND status = 'pending'")->fetch_assoc()['count'];
$totalBookings = $conn->query("SELECT COUNT(*) as count FROM bookings")->fetch_assoc()['count'];
$pendingPayouts = $conn->query("SELECT COUNT(*) as count FROM payout_requests WHERE status = 'pending'")->fetch_assoc()['count'] ?? 0;
$pendingPayments = $conn->query("SELECT COUNT(*) as count FROM payments WHERE status = 'pending'")->fetch_assoc()['count'];
$pendingDisputes = $conn->query("SELECT COUNT(*) as count FROM disputes WHERE status = 'pending'")->fetch_assoc()['count'];
$pendingReports = $conn->query("SELECT COUNT(*) as count FROM session_reports WHERE report_status = 'submitted'")->fetch_assoc()['count'];

function e($val) { return htmlspecialchars($val ?? '', ENT_QUOTES, 'UTF-8'); }

function getStatusBadge($status) {
    switch($status) {
        case 'completed': return '<span class="badge-completed">COMPLETED</span>';
        case 'cancelled': return '<span class="badge-cancelled">CANCELLED</span>';
        case 'pending':   return '<span class="badge-pending">PENDING</span>';
        case 'confirmed': return '<span class="badge-approved">CONFIRMED</span>';
        case 'accepted':  return '<span class="badge-approved">ACCEPTED</span>';
        case 'disputed':  return '<span class="badge-cancelled" style="background:#fde8d8;color:#92400e;">DISPUTED</span>';
        default:          return '<span class="badge-pending">' . e($status) . '</span>';
    }
}


?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Bookings · Admin</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
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
            left: 0; top: 0;
            width: 230px; height: 100vh;
            background: #272754;
            color: #E8E4F0;
            z-index: 1000;
            transition: transform 0.3s ease;
            transform: translateX(0);
            display: flex; flex-direction: column;
        }
        .sidebar.closed { transform: translateX(-100%); }
        .sidebar.open  { transform: translateX(0); }
        .sidebar-header { padding: 28px 20px; flex-shrink: 0; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .sidebar-footer {
            padding: 20px;
            border-top: 1px solid rgba(255,255,255,0.15);
            flex-shrink: 0;
            display: flex; align-items: center; justify-content: space-between; gap: 12px;
        }
        .admin-info  { display: flex; align-items: center; gap: 12px; flex: 1; overflow: hidden; }
        .brand-wrapper { display: flex; align-items: center; gap: 12px; }
        .brand-icon  { width: 60px; height: 60px; object-fit: contain; }
        .brand-title h1 {
            font-size: 1.4rem; font-weight: 700;
            background: linear-gradient(135deg, #ffffff, #B26EA7);
            background-clip: text; -webkit-background-clip: text; color: transparent;
        }
        .admin-space-text { font-size: 0.6rem; color: #e7c7f7; }
        .nav-menu { padding: 16px 0; flex: 1; overflow-y: auto; }
        .nav-menu::-webkit-scrollbar { width: 3px; }
        .nav-menu::-webkit-scrollbar-track { background: rgba(255,255,255,0.1); }
        .nav-menu::-webkit-scrollbar-thumb { background: #B26EA7; border-radius: 3px; }
        .menu-toggle {
            background: #272754; color: white; border: none;
            padding: 8px 12px; border-radius: 10px; cursor: pointer;
            display: none; font-size: 1.1rem;
        }
        .nav-item {
            padding: 10px 20px;
            display: flex; align-items: center; gap: 12px;
            color: #D4CFE8; text-decoration: none;
            transition: all 0.2s;
            border-left: 3px solid transparent;
            font-size: 0.85rem;
        }
        .nav-item:hover { background: rgba(255,255,255,0.08); color: white; }
        .nav-item.active { background: rgba(255,255,255,0.1); border-left-color: #B26EA7; color: white; }
        .nav-item i { width: 20px; font-size: 1.1rem; }
        .nav-section { margin-bottom: 8px; }
        .nav-section-label {
            padding: 12px 20px 6px 20px;
            font-size: 0.65rem; font-weight: 600; color: #B26EA7; text-transform: uppercase;
        }
        .nav-badge {
            margin-left: auto; font-size: 0.65rem;
            background: rgba(178, 110, 167, 0.25); padding: 2px 8px; border-radius: 30px;
        }
        .footer-avatar { width: 40px; height: 40px; border-radius: 50%; object-fit: cover; border: 2px solid rgba(255,255,255,0.2); }
        .admin-name  { font-size: 0.8rem; font-weight: 600; color: white; }
        .logout-icon {
            display: flex; align-items: center; justify-content: center;
            width: 36px; height: 36px;
            background: rgba(220, 38, 38, 0.15);
            border-radius: 10px; color: #FFA3A3;
            text-decoration: none; transition: all 0.2s;
        }
        .logout-icon:hover { background: rgba(220, 38, 38, 0.4); color: white; }
        .main-content {
            margin-left: 230px; padding: 20px 24px;
            transition: margin-left 0.3s ease;
            height: 100vh; overflow-y: auto;
        }
        .main-content::-webkit-scrollbar { width: 8px; }
        .main-content::-webkit-scrollbar-track { background: #f1f1f1; border-radius: 10px; }
        .main-content::-webkit-scrollbar-thumb { background: #E75A9B; border-radius: 10px; }
        .top-bar {
            display: flex; justify-content: space-between; align-items: center;
            flex-wrap: wrap; gap: 16px; margin-bottom: 24px;
        }
        .page-title h1 { font-size: 1.4rem; font-weight: 700; color: #302E63; }
        .page-title p  { font-size: 0.75rem; color: #7B6E8F; margin-top: 4px; }
        .admin-profile {
            display: flex; align-items: center; gap: 10px;
            background: white; padding: 6px 14px 6px 10px;
            border-radius: 50px; cursor: pointer;
            border: 1px solid #E4DCF0; position: relative;
        }
        .admin-profile img { width: 36px; height: 36px; border-radius: 50%; object-fit: cover; }
        .admin-profile span { font-weight: 600; font-size: 0.8rem; color: #302E63; }
        .dropdown {
            position: absolute; top: calc(100% + 10px); right: 0;
            width: 200px; background: white; border-radius: 14px;
            overflow: hidden; display: none;
            border: 1px solid #E4DCF0;
            box-shadow: 0 15px 35px rgba(0,0,0,0.15); z-index: 1000;
        }
        .dropdown a {
            display: flex; align-items: center; gap: 12px;
            padding: 10px 16px; text-decoration: none;
            color: #1E1B2E; font-size: 13px; font-weight: 500; transition: background 0.2s;
        }
        .dropdown a:hover { background: #F4F0F8; }
        .dropdown hr { margin: 0; border-color: #E4DCF0; }
        .filter-bar {
            background: white; border-radius: 16px; padding: 16px 20px;
            margin-bottom: 24px;
            display: flex; flex-wrap: wrap; gap: 12px; align-items: center;
        }
        .search-box {
            flex: 2; min-width: 200px;
            display: flex; align-items: center; gap: 10px;
            background: #f8f9fa; padding: 10px 16px;
            border-radius: 40px; border: 1px solid #e2e8f0;
        }
        .search-box input { border: none; background: transparent; flex: 1; outline: none; font-size: 13px; }
        .filter-select {
            padding: 10px 16px; border-radius: 40px;
            border: 1px solid #e2e8f0; background: #f8f9fa;
            cursor: pointer; font-size: 13px;
        }
        .btn-filter, .btn-reset {
            background: #E75A9B; color: white; border: none;
            padding: 10px 24px; border-radius: 40px;
            cursor: pointer; font-weight: 600; font-size: 13px; text-decoration: none;
        }
        .btn-reset { background: #64748b; }
        .stats-grid {
            display: grid; grid-template-columns: repeat(4, 1fr);
            gap: 20px; margin-bottom: 28px;
        }
        .stat-card {
            background: white; border-radius: 20px; padding: 18px;
            border: 1px solid #E4DCF0;
            display: flex; align-items: center; justify-content: space-between;
        }
        .stat-left { display: flex; align-items: center; gap: 12px; }
        .stat-icon {
            width: 44px; height: 44px;
            background: rgba(135, 93, 156, 0.1);
            border-radius: 14px;
            display: flex; align-items: center; justify-content: center;
        }
        .stat-icon i { font-size: 22px; color: #875D9C; }
        .stat-label { font-size: 0.7rem; font-weight: 600; color: #7B6E8F; text-transform: uppercase; }
        .stat-value { font-size: 26px; font-weight: 800; color: #302E63; line-height: 1.2; }
        .bookings-table {
            background: white; border-radius: 20px;
            overflow-x: auto; width: 100%; border-collapse: collapse;
        }
        .bookings-table th, .bookings-table td {
            padding: 14px 16px; text-align: left;
            border-bottom: 1px solid #eef2f7;
            font-size: 13px; color: #475569; vertical-align: middle;
        }
        .bookings-table th { background: #f8f8f8; font-size: 12px; font-weight: 700; color: #302E63; }
        .bookings-table tr:hover td { background: #fafcff; }
        .badge-approved  { background: #d4edda; color: #155724; padding: 4px 12px; border-radius: 20px; font-size: 11px; display: inline-block; }
        .badge-pending   { background: #fff3cd; color: #856404; padding: 4px 12px; border-radius: 20px; font-size: 11px; display: inline-block; }
        .badge-cancelled { background: #f8d7da; color: #721c24; padding: 4px 12px; border-radius: 20px; font-size: 11px; display: inline-block; }
        .badge-completed { background: #d1ecf1; color: #0c5460; padding: 4px 12px; border-radius: 20px; font-size: 11px; display: inline-block; }
        .badge-rejected  { background: #f8d7da; color: #721c24; padding: 4px 12px; border-radius: 20px; font-size: 11px; display: inline-block; }
        .learning-badge {
            background: #e9ecef; color: #495057;
            padding: 4px 10px; border-radius: 20px;
            font-size: 11px; font-weight: 600;
        }
        .cancelled-by-badge {
            padding: 4px 10px; border-radius: 20px;
            font-size: 11px; font-weight: 600; display: inline-flex; align-items: center; gap: 4px;
        }
        .cb-student { background: #e0f0ff; color: #1d4ed8; }
        .cb-tutor   { background: #f3e8ff; color: #7c3aed; }
        .cb-admin   { background: #fef3c7; color: #b45309; }
        .btn-view-detail {
            background: #ede9fe; color: #7c3aed; border: none;
            padding: 6px 14px; border-radius: 20px;
            font-size: 11px; font-weight: 600; cursor: pointer;
            text-decoration: none; display: inline-flex; align-items: center; gap: 4px;
            transition: background 0.2s;
        }
        .btn-view-detail:hover { background: #ddd6fe; }
        .btn-view-reason {
            background: #e2e8f0; border: none;
            padding: 6px 12px; border-radius: 20px;
            font-size: 11px; cursor: pointer;
        }
        .btn-cancel-booking {
            background: #f8d7da; color: #dc2626; border: none;
            padding: 6px 12px; border-radius: 20px;
            font-size: 11px; cursor: pointer; transition: all 0.2s;
        }
        .btn-cancel-booking:hover:not(:disabled) { background: #f5c6cb; transform: translateY(-1px); }
        .btn-cancel-booking:disabled { opacity: 0.5; cursor: not-allowed; }
        .btn-cancel-booking.disabled-btn { background: #e2e8f0; color: #94a3b8; cursor: not-allowed; }
        .detail-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0; }
        .detail-item {
            padding: 14px 20px;
            border-bottom: 1px solid #f1f5f9;
        }
        .detail-item:nth-child(odd) { border-right: 1px solid #f1f5f9; }
        .detail-item.full { grid-column: 1 / -1; }
        .detail-label { font-size: 11px; font-weight: 700; color: #94a3b8; text-transform: uppercase; margin-bottom: 4px; }
        .detail-value { font-size: 13px; color: #1e293b; font-weight: 500; word-break: break-word; }
        .modal-overlay {
            position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0,0,0,0.7); z-index: 2000;
            display: flex; align-items: center; justify-content: center;
            visibility: hidden; opacity: 0; transition: all 0.3s ease;
        }
        .modal-overlay.active { visibility: visible; opacity: 1; }
        .modal-container { background: white; border-radius: 24px; width: 90%; max-width: 500px; overflow: hidden; }
        .modal-header {
            display: flex; justify-content: space-between; align-items: center;
            padding: 20px 24px; background: #f8fafc; border-bottom: 1px solid #e2e8f0;
        }
        .modal-header h3 { font-size: 1.2rem; font-weight: 700; color: #1a1a3e; }
        .modal-close { background: none; border: none; font-size: 28px; cursor: pointer; color: #94a3b8; }
        .modal-body { padding: 24px; }
        .reason-text {
            background: #f8fafc; padding: 16px; border-radius: 16px;
            color: #1e293b; font-size: 14px; line-height: 1.5;
        }
        .warning-text {
            background: #fff3cd; color: #856404; padding: 12px;
            border-radius: 12px; font-size: 13px; margin-bottom: 16px;
        }
        .form-group label { display: block; font-weight: 600; margin-bottom: 8px; font-size: 13px; }
        .form-group textarea {
            width: 100%; padding: 12px; border-radius: 16px;
            border: 1px solid #cbd5e1; font-family: inherit; resize: vertical;
        }
        .modal-buttons {
            display: flex; gap: 12px; justify-content: flex-end;
            padding: 16px 24px; border-top: 1px solid #e2e8f0;
        }
        .btn-cancel-modal { background: #e2e8f0; border: none; padding: 10px 20px; border-radius: 40px; cursor: pointer; }
        .btn-confirm-cancel { background: #dc2626; color: white; border: none; padding: 10px 24px; border-radius: 40px; cursor: pointer; }
        .sidebar-overlay {
            display: none; position: fixed; top: 0; left: 0;
            width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 999;
        }
        .sidebar-overlay.active { display: block; }
        @media (max-width: 768px) {
            .menu-toggle { display: block; }
            .sidebar { transform: translateX(-100%); }
            .sidebar.open { transform: translateX(0); }
            .main-content { margin-left: 0; padding: 16px; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .filter-bar { flex-direction: column; align-items: stretch; }
        }
        .empty-state { text-align: center; padding: 60px; background: white; border-radius: 20px; color: #94a3b8; }
        .empty-state i { font-size: 48px; margin-bottom: 16px; display: block; }
        .relative { position: relative; }
        .alert-success {
            background: #d4edda; color: #155724;
            border-left: 4px solid #28a745;
            padding: 12px 16px; border-radius: 12px; margin-bottom: 20px;
        }
        .alert-error {
            background: #f8d7da; color: #721c24;
            border-left: 4px solid #dc2626;
            padding: 12px 16px; border-radius: 12px; margin-bottom: 20px;
        }
    </style>
</head>
<body>
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<aside class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <div class="brand-wrapper">
            <img src="<?= e($assetBase) ?>/logo.png" alt="Kyoshi" class="brand-icon">
            <div class="brand-title"><h1>Kyoshi</h1><span class="admin-space-text">Admin Space</span></div>
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
            <a href="admin_bookings.php" class="nav-item active">
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
            <div class="admin-details"><span class="admin-name"><?= e($displayName) ?></span></div>
        </div>
        <a href="logout.php" class="logout-icon" title="Logout"><i class="bi bi-box-arrow-right"></i></a>
    </div>
</aside>

<div class="main-content">
    <div class="top-bar">
        <div>
            <div class="page-title">
                <h1>Manage Bookings</h1>
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
        <div class="alert-success"><i class="bi bi-check-circle"></i> <?= e($_SESSION['success_message']) ?></div>
        <?php unset($_SESSION['success_message']); endif; ?>
    <?php if (isset($_SESSION['error_message'])): ?>
        <div class="alert-error"><i class="bi bi-exclamation-triangle"></i> <?= e($_SESSION['error_message']) ?></div>
        <?php unset($_SESSION['error_message']); endif; ?>

    <!-- Stats -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-left">
                <div class="stat-icon"><i class="bi bi-calendar-week"></i></div>
                <div><div class="stat-label">Total Bookings</div></div>
            </div>
            <div class="stat-value"><?= $total_bookings ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-left">
                <div class="stat-icon"><i class="bi bi-hourglass-split"></i></div>
                <div><div class="stat-label">Pending</div></div>
            </div>
            <div class="stat-value"><?= $pending_bookings ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-left">
                <div class="stat-icon"><i class="bi bi-check2-circle"></i></div>
                <div><div class="stat-label">Completed</div></div>
            </div>
            <div class="stat-value"><?= $completed_bookings ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-left">
                <div class="stat-icon"><i class="bi bi-x-circle"></i></div>
                <div><div class="stat-label">Cancelled</div></div>
            </div>
            <div class="stat-value"><?= $cancelled_bookings ?></div>
        </div>
    </div>

    <!-- Filter Bar -->
    <div class="filter-bar">
        <div class="search-box">
            <i class="bi bi-search"></i>
            <input type="text" id="searchInput"
                   placeholder="Search by tutor, student or booking ID..."
                   value="<?= e($search) ?>">
        </div>
        <select id="statusFilter" class="filter-select">
            <option value="all"      <?= $status_filter == 'all'       ? 'selected' : '' ?>>All Statuses</option>
            <option value="pending"  <?= $status_filter == 'pending'   ? 'selected' : '' ?>>Pending</option>
            <option value="accepted" <?= $status_filter == 'accepted'  ? 'selected' : '' ?>>Accepted</option>
            <option value="confirmed"<?= $status_filter == 'confirmed' ? 'selected' : '' ?>>Confirmed</option>
            <option value="completed"<?= $status_filter == 'completed' ? 'selected' : '' ?>>Completed</option>
            <option value="cancelled"<?= $status_filter == 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
            <option value="disputed" <?= $status_filter == 'disputed'  ? 'selected' : '' ?>>Disputed</option>
        </select>
        <button class="btn-filter" onclick="applyFilters()"><i class="bi bi-search"></i> Apply</button>
        <a href="admin_bookings.php" class="btn-reset"><i class="bi bi-x-circle"></i> Reset</a>
    </div>

    <!-- Table -->
    <?php if ($bookings_result && $bookings_result->num_rows == 0): ?>
        <div class="empty-state">
            <i class="bi bi-calendar-x"></i>
            <p>No bookings found.</p>
        </div>
    <?php else: ?>
        <div style="overflow-x:auto;">
            <table class="bookings-table">
                <thead>
                    <tr>
                        <th>Student</th>
                        <th>Tutor</th>
                        <th>Language</th>
                        <th>Method</th>
                        <th>Status</th>
                        <th>Cancelled By</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php while ($row = $bookings_result->fetch_assoc()):
                    $cb = $row['cancelled_by'] ?? null;
                    $bookingJson = json_encode([
                        'student_name'  => $row['student_name'],
                        'student_email' => $row['student_email'],
                        'tutor_name'    => $row['tutor_name'],
                        'tutor_email'   => $row['tutor_email'],
                        'language'      => $row['language'],
                        'learning_mode' => $row['learning_mode'],
                        'booking_date'  => $row['booking_date'] ? date('d M Y', strtotime($row['booking_date'])) : '—',
                        'booking_time'  => $row['booking_time'] ? date('h:i A', strtotime($row['booking_time'])) : '—',
                        'total_amount'  => number_format($row['total_amount'], 2),
                        'status'        => $row['status'],
                        'cancelled_by'  => $row['cancelled_by'] ?? '',
                        'cancel_reason' => $row['cancel_reason'] ?? '',
                        'focus'         => $row['focus'] ?? '',
                        'notes'         => $row['notes'] ?? '',
                        'proficiency'   => $row['proficiency_level'] ?? '',
                        'created_at'    => $row['created_at'] ? date('d M Y, h:i A', strtotime($row['created_at'])) : '—',
                        'meeting_location' => $row['meeting_location'] ?? '',
                    ]);
                ?>
                    <tr>
                        <td>
                            <strong><?= e($row['student_name']) ?></strong><br>
                            <small style="color:#94a3b8;"><?= e($row['student_email']) ?></small>
                        </td>
                        <td>
                            <strong><?= e($row['tutor_name']) ?></strong><br>
                            <small style="color:#94a3b8;"><?= e($row['tutor_email']) ?></small>
                        </td>
                        <td><?= e($row['language']) ?></td>
                        <td>
                            <span class="learning-badge">
                                <i class="bi <?= ($row['learning_mode'] == 'online' ? 'bi-wifi' : 'bi-building') ?>"></i>
                                <?= ucfirst(e($row['learning_mode'])) ?>
                            </span>
                        </td>
                        <td><?= getStatusBadge($row['status']) ?></td>
                        <td>
                            <?php if ($cb === 'student'): ?>
                                <span class="cancelled-by-badge cb-student"><i class="bi bi-person"></i> Student</span>
                            <?php elseif ($cb === 'tutor'): ?>
                                <span class="cancelled-by-badge cb-tutor"><i class="bi bi-person-badge"></i> Tutor</span>
                            <?php elseif ($cb === 'admin'): ?>
                                <span class="cancelled-by-badge cb-admin"><i class="bi bi-shield"></i> Admin</span>
                            <?php else: ?>
                                <span style="color:#94a3b8;">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <button class="btn-view-detail" onclick='openDetailModal(<?= htmlspecialchars($bookingJson, ENT_QUOTES) ?>)'>
                                <i class="bi bi-eye"></i> View
                            </button>
                        </td>
                    </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div><!-- /main-content -->

<!-- Booking Detail Modal -->
<div id="detailModal" class="modal-overlay">
    <div class="modal-container" style="max-width:560px;">
        <div class="modal-header">
            <h3><i class="bi bi-calendar-check"></i> Booking Details <span id="modalBookingId" style="color:#B26EA7;"></span></h3>
            <button class="modal-close" onclick="closeDetailModal()">&times;</button>
        </div>
        <div class="modal-body" style="padding:0;">
            <div class="detail-grid" id="detailGrid"></div>
        </div>
    </div>
</div>



<script>
// Dropdown
function toggleDropdown() {
    const dd = document.getElementById('profileDropdown');
    dd.style.display = dd.style.display === 'block' ? 'none' : 'block';
}
document.addEventListener('click', function(e) {
    const profile = document.querySelector('.admin-profile');
    const dd = document.getElementById('profileDropdown');
    if (profile && dd && !profile.contains(e.target) && !dd.contains(e.target)) dd.style.display = 'none';
});

// Filters
function applyFilters() {
    const search = document.getElementById('searchInput').value;
    const status = document.getElementById('statusFilter').value;
    let url = `admin_bookings.php?status=${status}`;
    if (search.trim() !== '') url += `&search=${encodeURIComponent(search.trim())}`;
    window.location.href = url;
}
document.getElementById('searchInput').addEventListener('keydown', function(e) {
    if (e.key === 'Enter') applyFilters();
});

// Detail modal
function openDetailModal(b) {
    document.getElementById('modalBookingId').textContent = '#' + b.id;

    const statusColors = {
        completed: '#0c5460', cancelled: '#721c24', pending: '#856404',
        confirmed: '#155724', accepted: '#155724', disputed: '#92400e'
    };
    const statusBg = {
        completed: '#d1ecf1', cancelled: '#f8d7da', pending: '#fff3cd',
        confirmed: '#d4edda', accepted: '#d4edda', disputed: '#fde8d8'
    };
    const s = b.status || '';
    const statusBadge = `<span style="background:${statusBg[s]||'#e2e8f0'};color:${statusColors[s]||'#475569'};padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;">${s.toUpperCase()}</span>`;

    const cbMap = { student: '👤 Student', tutor: '🎓 Tutor', admin: '🛡️ Admin' };
    const cbLabel = cbMap[b.cancelled_by] || '—';

    const modeIcon = b.learning_mode === 'online' ? '🌐' : '🏢';

    const rows = [
        { label: 'Student',       value: `<strong>${b.student_name}</strong><br><small style="color:#94a3b8">${b.student_email}</small>` },
        { label: 'Tutor',         value: `<strong>${b.tutor_name}</strong><br><small style="color:#94a3b8">${b.tutor_email}</small>` },
        { label: 'Language',      value: b.language },
        { label: 'Learning Mode', value: `${modeIcon} ${b.learning_mode.charAt(0).toUpperCase() + b.learning_mode.slice(1)}` },
        { label: 'Date',          value: b.booking_date },
        { label: 'Time',          value: b.booking_time },
        { label: 'Amount',        value: `RM ${b.total_amount}` },
        { label: 'Proficiency',   value: b.proficiency || '—' },
        { label: 'Status',        value: statusBadge },
        { label: 'Cancelled By',  value: b.cancelled_by ? cbLabel : '—' },
        { label: 'Booked On',     value: b.created_at },
        { label: 'Focus',         value: b.focus || '—' },
    ];

    // Full-width rows
    const fullRows = [];
    if (b.cancel_reason) fullRows.push({ label: 'Cancel Reason', value: b.cancel_reason });
    if (b.meeting_location) fullRows.push({ label: 'Meeting Location', value: b.meeting_location });
    if (b.notes) fullRows.push({ label: 'Notes', value: b.notes });

    let html = rows.map(r =>
        `<div class="detail-item"><div class="detail-label">${r.label}</div><div class="detail-value">${r.value}</div></div>`
    ).join('');
    html += fullRows.map(r =>
        `<div class="detail-item full"><div class="detail-label">${r.label}</div><div class="detail-value">${r.value}</div></div>`
    ).join('');

    document.getElementById('detailGrid').innerHTML = html;
    document.getElementById('detailModal').classList.add('active');
}
function closeDetailModal() { document.getElementById('detailModal').classList.remove('active'); }

window.addEventListener('click', function(e) {
    const m = document.getElementById('detailModal');
    if (e.target === m) closeDetailModal();
});
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeDetailModal();
});

// Sidebar toggle
const menuToggle = document.getElementById('menuToggle');
const sidebar    = document.getElementById('sidebar');
const overlay    = document.getElementById('sidebarOverlay');
if (menuToggle) menuToggle.addEventListener('click', () => { sidebar.classList.toggle('open'); overlay.classList.toggle('active'); });
if (overlay)    overlay.addEventListener('click', () => { sidebar.classList.remove('open'); overlay.classList.remove('active'); });



// Auto-dismiss alerts after 3 s
setTimeout(() => {
    document.querySelectorAll('.alert-success, .alert-error').forEach(el => {
        el.style.transition = 'opacity 0.5s';
        el.style.opacity = '0';
        setTimeout(() => el.remove(), 500);
    });
}, 3000);
</script>
</body>
</html>