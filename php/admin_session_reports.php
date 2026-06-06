<?php
session_start();
include 'config.php';
include 'send_session_report_email.php';
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

$displayName = $admin['fullname'];
$profilePic = !empty($admin['profile_pic']) ? '../uploads/profiles/' . $admin['profile_pic'] : $assetBase . '/profile-admin.png';
// Handle session report verification
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verify_report'])) {
    $report_id = intval($_POST['report_id']);
    $action = $_POST['verify_action'];
    $admin_notes = trim($_POST['admin_notes'] ?? '');
    
    // Get report details with tutor info FIRST (before updating)
    $reportStmt = $conn->prepare("
        SELECT sr.*, 
               t.email as tutor_email, t.fullname as tutor_name,
               s.fullname as student_name
        FROM session_reports sr
        JOIN users t ON sr.tutor_id = t.id
        JOIN users s ON sr.student_id = s.id
        WHERE sr.id = ?
    ");
    $reportStmt->bind_param("i", $report_id);
    $reportStmt->execute();
    $report = $reportStmt->get_result()->fetch_assoc();
    
    if ($action === 'approve') {
        $stmt = $conn->prepare("UPDATE session_reports SET report_status = 'approved', admin_notes = ?, updated_at = NOW() WHERE id = ?");
        $stmt->bind_param("si", $admin_notes, $report_id);
        if ($stmt->execute()) {
            // Send email to tutor
            if (function_exists('sendSessionReportNotification')) {
                sendSessionReportNotification(
                    $report['tutor_email'],
                    $report['tutor_name'],
                    $report['student_name'],
                    $report['session_date'],
                    $report['session_time'],
                    'approve',
                    $admin_notes,
                    $report_id
                );
            }
            $_SESSION['success_message'] = "Session report approved! Email sent to tutor.";
        }
    } elseif ($action === 'reject') {
        if (empty($admin_notes)) {
            $_SESSION['error_message'] = "Please provide a reason for rejection.";
            header("Location: admin_session_reports.php");
            exit();
        }
        $stmt = $conn->prepare("UPDATE session_reports SET report_status = 'rejected', admin_notes = ?, updated_at = NOW() WHERE id = ?");
        $stmt->bind_param("si", $admin_notes, $report_id);
        if ($stmt->execute()) {
            // Send rejection email with resubmit link to tutor
            if (function_exists('sendSessionReportNotification')) {
                sendSessionReportNotification(
                    $report['tutor_email'],
                    $report['tutor_name'],
                    $report['student_name'],
                    $report['session_date'],
                    $report['session_time'],
                    'reject',
                    $admin_notes,
                    $report_id
                );
            }
            $_SESSION['success_message'] = "Session report rejected! Email sent to tutor with resubmit instructions.";
        }
    }
    header("Location: admin_session_reports.php");
    exit();
}

// Get filter parameters
$status_filter = $_GET['status'] ?? 'all';
$search = $_GET['search'] ?? '';

// Build query for session reports - FIXED: removed b.hours since it doesn't exist
$reports_sql = "
    SELECT sr.*, 
           t.fullname as tutor_name, 
           t.email as tutor_email,
           s.fullname as student_name,
           s.email as student_email,
           b.total_amount,
           b.booking_date,
           b.booking_time,
           b.status as booking_status
    FROM session_reports sr
    JOIN users t ON sr.tutor_id = t.id
    JOIN users s ON sr.student_id = s.id
    LEFT JOIN bookings b ON sr.booking_id = b.id
    WHERE 1=1
";

if ($status_filter !== 'all') {
    $reports_sql .= " AND sr.report_status = '" . $conn->real_escape_string($status_filter) . "'";
}

if (!empty($search)) {
    $search_like = $conn->real_escape_string($search);
    $reports_sql .= " AND (t.fullname LIKE '%$search_like%' 
                        OR t.email LIKE '%$search_like%'
                        OR s.fullname LIKE '%$search_like%')";
}

$reports_sql .= " ORDER BY sr.submitted_at DESC";
$reports = $conn->query($reports_sql);

// Get counts
$approvedReports = $conn->query("SELECT COUNT(*) as count FROM session_reports WHERE report_status = 'approved'")->fetch_assoc()['count'];
$rejectedReports = $conn->query("SELECT COUNT(*) as count FROM session_reports WHERE report_status = 'rejected'")->fetch_assoc()['count'];
$submittedReports = $conn->query("SELECT COUNT(*) as count FROM session_reports WHERE report_status = 'submitted'")->fetch_assoc()['count'];

// Get counts for sidebar
$totalTutors = $conn->query("SELECT COUNT(*) as count FROM users WHERE role = 'tutor'")->fetch_assoc()['count'];
$totalStudents = $conn->query("SELECT COUNT(*) as count FROM users WHERE role = 'student'")->fetch_assoc()['count'];
$totalBookings = $conn->query("SELECT COUNT(*) as count FROM bookings")->fetch_assoc()['count'];
$pendingPayments = $conn->query("SELECT COUNT(*) as count FROM payments WHERE status = 'pending'")->fetch_assoc()['count'];
$pendingDisputes = $conn->query("SELECT COUNT(*) as count FROM disputes WHERE status = 'pending'")->fetch_assoc()['count'];
$pendingPayouts = $conn->query("SELECT COUNT(*) as count FROM payout_requests WHERE status = 'pending'")->fetch_assoc()['count'] ?? 0;
$pendingQualifications = $conn->query("
    SELECT COUNT(*) as count 
    FROM tutor_certificates tc
    JOIN users u ON tc.tutor_id = u.id
    WHERE tc.status = 'pending' AND u.status = 'approved'
")->fetch_assoc()['count'];
$totalReviews = $conn->query("SELECT COUNT(*) as count FROM ratings")->fetch_assoc()['count'];

function e($value) {
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function getStatusBadge($status) {
    switch ($status) {
        case 'approved':
            return '<span class="badge-approved"><i class="bi bi-check-circle"></i> Approved</span>';
        case 'rejected':
            return '<span class="badge-rejected"><i class="bi bi-x-circle"></i> Rejected</span>';
        case 'submitted':
            return '<span class="badge-submitted"><i class="bi bi-send"></i> Submitted</span>';
        default:
            return '<span class="badge-pending">Pending</span>';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kyoshi | Session Reports</title>
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
            letter-spacing: 1px;
            display: flex;
            align-items: center;
            gap: 6px;
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
        
        /* Stats Cards */
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
        }
        
        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(48, 46, 99, 0.08);
        }
        
        .stat-left {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .stat-icon {
            width: 44px;
            height: 44px;
            background: rgba(135, 93, 156, 0.1);
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .stat-icon i {
            font-size: 22px;
            color: #875D9C;
        }
        
        .stat-info .stat-label {
            font-size: 0.7rem;
            font-weight: 600;
            color: #7B6E8F;
            text-transform: uppercase;
        }
        
        .stat-value {
            font-size: 26px;
            font-weight: 800;
            color: #302E63;
        }
        
        /* Filter Bar */
        .filter-bar {
            background: white;
            border-radius: 16px;
            padding: 16px 20px;
            margin-bottom: 24px;
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            align-items: center;
        }
        
        .search-box {
            flex: 2;
            min-width: 200px;
            display: flex;
            align-items: center;
            gap: 10px;
            background: #f8f9fa;
            padding: 10px 16px;
            border-radius: 40px;
            border: 1px solid #e2e8f0;
        }
        
        .search-box input {
            border: none;
            background: transparent;
            flex: 1;
            outline: none;
            font-size: 13px;
        }
        
        .filter-select {
            padding: 10px 16px;
            border-radius: 40px;
            border: 1px solid #e2e8f0;
            background: #f8f9fa;
            cursor: pointer;
            font-size: 13px;
        }
        
        .btn-filter, .btn-reset {
            background: #E75A9B;
            color: white;
            border: none;
            padding: 10px 24px;
            border-radius: 40px;
            cursor: pointer;
            font-weight: 600;
            font-size: 13px;
            text-decoration: none;
        }
        
        .btn-reset {
            background: #64748b;
        }
        
        /* Table Styles */
        .reports-table {
            background: white;
            border-radius: 20px;
            overflow-x: auto;
            width: 100%;
            border-collapse: collapse;
        }
        
        .reports-table th {
            padding: 14px 16px;
            text-align: left;
            background: #f8f8f8;
            font-size: 12px;
            font-weight: 700;
            color: #302E63;
            border-bottom: 1px solid #e0e0e0;
        }
        
        .reports-table td {
            padding: 14px 16px;
            border-bottom: 1px solid #eef2f7;
            font-size: 13px;
            color: #475569;
            vertical-align: middle;
        }
        
        .reports-table tr:hover td {
            background: #fafcff;
        }
        
        /* Badges */
        .badge-approved {
            background: #d4edda;
            color: #155724;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        
        .badge-pending {
            background: #fff3cd;
            color: #856404;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        
        .badge-rejected {
            background: #f8d7da;
            color: #721c24;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        
        .badge-submitted {
            background: #cfe2ff;
            color: #084298;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        
        /* Buttons */
        .btn-view, .btn-approve, .btn-reject {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            cursor: pointer;
            border: none;
            margin: 2px;
            transition: 0.2s;
        }
        
        .btn-view {
            background: #e2e8f0;
            color: #1d3156;
        }
        
        .btn-view:hover {
            background: #cbd5e1;
        }
        
        .btn-approve {
            background: #d4edda;
            color: #28a745;
        }
        
        .btn-approve:hover {
            background: #a3d4a8;
        }
        
        .btn-reject {
            background: #f8d7da;
            color: #dc2626;
        }
        
        .btn-reject:hover {
            background: #f5c6cb;
        }
        
        /* Modal */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.7);
            z-index: 9999;
            display: flex;
            align-items: center;
            justify-content: center;
            visibility: hidden;
            opacity: 0;
            transition: all 0.3s ease;
        }
        
        .modal-overlay.active {
            visibility: visible;
            opacity: 1;
        }
        
        .modal-container {
            background: white;
            border-radius: 24px;
            width: 90%;
            max-width: 700px;
            max-height: 85vh;
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px 24px;
            border-bottom: 1px solid #e2e8f0;
            background: #f8fafc;
        }
        
        .modal-header h3 {
            font-size: 1.2rem;
            font-weight: 700;
            color: #1a1a3e;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .modal-close {
            background: none;
            border: none;
            font-size: 28px;
            cursor: pointer;
            color: #94a3b8;
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .modal-close:hover {
            background: #fee2e2;
            color: #dc2626;
        }
        
        .modal-body {
            padding: 24px;
            overflow-y: auto;
            flex: 1;
        }
        
        .form-group {
            margin-bottom: 18px;
        }
        
        .form-group label {
            display: block;
            font-weight: 600;
            font-size: 12px;
            margin-bottom: 6px;
            color: #1a1a3e;
        }
        
        .form-group textarea {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid #cbd5e1;
            border-radius: 12px;
            font-family: inherit;
            font-size: 13px;
            resize: vertical;
        }
        
        .modal-buttons {
            display: flex;
            gap: 12px;
            justify-content: flex-end;
            padding: 16px 24px;
            border-top: 1px solid #e2e8f0;
        }
        
        .btn-cancel {
            background: #e2e8f0;
            color: #475569;
            padding: 10px 20px;
            border-radius: 30px;
            border: none;
            cursor: pointer;
            font-weight: 600;
        }
        
        .btn-save {
            background: #28a745;
            color: white;
            padding: 10px 20px;
            border-radius: 30px;
            border: none;
            cursor: pointer;
            font-weight: 600;
        }
        
        .btn-save.reject-mode {
            background: #dc2626;
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
        
        .empty-state {
            text-align: center;
            padding: 60px;
            background: white;
            border-radius: 20px;
            color: #94a3b8;
        }
        
        .empty-state i {
            font-size: 48px;
            margin-bottom: 16px;
            display: block;
        }
        
        .report-details {
            background: #f8fafc;
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 20px;
        }
        
        .report-details p {
            margin: 8px 0;
            font-size: 13px;
            line-height: 1.6;
        }
        
        .report-details strong {
            color: #1a1a3e;
            min-width: 140px;
            display: inline-block;
        }
        
        .section-title {
            font-weight: 700;
            color: #1a1a3e;
            margin-top: 12px;
            margin-bottom: 8px;
            font-size: 14px;
            border-left: 3px solid #E75A9B;
            padding-left: 10px;
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
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 12px;
            }
            
            .filter-bar {
                flex-direction: column;
                align-items: stretch;
            }
            
            .search-box {
                width: 100%;
            }
            
            .reports-table {
                font-size: 12px;
            }
            
            .reports-table th,
            .reports-table td {
                padding: 8px 10px;
            }
            
            .report-details p strong {
                min-width: 100px;
            }
        }
        
        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
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
            <a href="admin_tutor_actions.php" class="nav-item active">
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
        <a href="admin_tutor_actions.php" class="btn-back" style="display: inline-flex; align-items: center; gap: 8px; background: #e2e8f0; color: #1d3156; padding: 8px 16px; border-radius: 40px; text-decoration: none; font-size: 13px; font-weight: 600; transition: 0.2s;">
            <i class="bi bi-arrow-left"></i> Back
        </a>
        <div class="page-title">
            <h1>Session Reports</h1>
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
    
    <?php if (isset($_SESSION['error_message'])): ?>
        <div class="alert-error" id="errorAlert">
            <i class="bi bi-exclamation-triangle"></i> <?= $_SESSION['error_message'] ?>
        </div>
        <?php unset($_SESSION['error_message']); ?>
    <?php endif; ?>
    <!-- Filter Bar -->
    <div class="filter-bar">
        <div class="search-box">
            <i class="bi bi-search"></i>
            <input type="text" id="searchInput" placeholder="Search by tutor or student name..." value="<?= e($search) ?>">
        </div>
        <select id="statusFilter" class="filter-select">
            <option value="all" <?= $status_filter == 'all' ? 'selected' : '' ?>>All Status</option>
            <option value="approved" <?= $status_filter == 'approved' ? 'selected' : '' ?>>Approved</option>
            <option value="rejected" <?= $status_filter == 'rejected' ? 'selected' : '' ?>>Rejected</option>
            <option value="submitted" <?= $status_filter == 'submitted' ? 'selected' : '' ?>>Submitted</option>
        </select>
        <button class="btn-filter" onclick="applyFilters()"><i class="bi bi-search"></i> Apply</button>
        <a href="admin_session_reports.php" class="btn-reset"><i class="bi bi-x-circle"></i> Reset</a>
    </div>

    <!-- Reports Table -->
    <div class="reports-container">
        <?php if (!$reports || $reports->num_rows == 0): ?>
            <div class="empty-state">
                <i class="bi bi-file-text"></i>
                <p>No session reports found.</p>
            </div>
        <?php else: ?>
            <table class="reports-table">
                <thead>
                    <tr>
                        <th>Tutor</th>
                        <th>Student</th>
                        <th>Session Date</th>
                        <th>Amount</th>
                        <th>Submitted At</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($report = $reports->fetch_assoc()): ?>
                    <tr>
                        <td>
                            <strong><?= e($report['tutor_name']) ?></strong><br>
                            <small><?= e($report['tutor_email']) ?></small>
                        </td>
                        <td>
                            <strong><?= e($report['student_name']) ?></strong><br>
                            <small><?= e($report['student_email']) ?></small>
                        </td>
                        <td><?= date('d M Y', strtotime($report['session_date'])) ?> <br> at <?= date('h:i A', strtotime($report['session_time'])) ?></td>
                        <td><strong> RM <?= number_format($report['total_amount'] ?? 0, 2) ?></strong></td>
                        <td><?= date('d M Y, h:i A', strtotime($report['submitted_at'])) ?></td>
                        <td><?= getStatusBadge($report['report_status']) ?></td>
                        <td>
                            <button class="btn-view" onclick="viewReport(<?= $report['id'] ?>)">
                                <i class="bi bi-eye"></i> View
                            </button>
                            <?php if ($report['report_status'] == 'submitted'): ?>
                                <button class="btn-approve" onclick="verifyReport(<?= $report['id'] ?>, 'approve')">
                                    <i class="bi bi-check-lg"></i> Approve
                                </button>
                                <button class="btn-reject" onclick="verifyReport(<?= $report['id'] ?>, 'reject')">
                                    <i class="bi bi-x-lg"></i> Reject
                                </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<!-- Verify Report Modal -->
<div id="verifyModal" class="modal-overlay">
    <div class="modal-container">
        <div class="modal-header">
            <h3 id="modalTitle"><i class="bi bi-check-circle"></i> Verify Session Report</h3>
            <button class="modal-close" onclick="closeVerifyModal()">&times;</button>
        </div>
        <form method="POST" action="">
            <input type="hidden" name="verify_report" value="1">
            <input type="hidden" name="report_id" id="reportId">
            <input type="hidden" name="verify_action" id="verifyAction">
            
            <div class="modal-body">
                <div id="reportPreview">
                    <!-- Dynamic content will be inserted here -->
                </div>
                
                <div class="form-group">
                    <label id="notesLabel"><i class="bi bi-pencil-square"></i> Admin Notes</label>
                    <textarea name="admin_notes" id="adminNotes" rows="3" placeholder="Add notes about this report..."></textarea>
                </div>
            </div>
            
            <div class="modal-buttons">
                <button type="button" class="btn-cancel" onclick="closeVerifyModal()">Cancel</button>
                <button type="submit" class="btn-save" id="submitBtn">Confirm</button>
            </div>
        </form>
    </div>
</div>

<!-- View Report Modal -->
<div id="viewModal" class="modal-overlay">
    <div class="modal-container">
        <div class="modal-header">
            <h3><i class="bi bi-file-text"></i> Session Report Details</h3>
            <button class="modal-close" onclick="closeViewModal()">&times;</button>
        </div>
        <div class="modal-body">
            <div id="viewReportContent">
                <!-- Dynamic content will be inserted here -->
            </div>
        </div>
        <div class="modal-buttons">
            <button class="btn-cancel" onclick="closeViewModal()">Close</button>
        </div>
    </div>
</div>

<script>
// Store reports data for modal display
let reportsData = {};

<?php
// Fetch all reports data for modals
$reports->data_seek(0);
while ($report = $reports->fetch_assoc()) {
    $report_id = $report['id'];
    ?>
    reportsData[<?= $report_id ?>] = {
        id: <?= $report_id ?>,
        tutor_name: "<?= e(addslashes($report['tutor_name'])) ?>",
        tutor_email: "<?= e(addslashes($report['tutor_email'])) ?>",
        student_name: "<?= e(addslashes($report['student_name'])) ?>",
        student_email: "<?= e(addslashes($report['student_email'])) ?>",
        session_date: "<?= date('d M Y', strtotime($report['session_date'])) ?>",
        session_time: "<?= date('h:i A', strtotime($report['session_time'])) ?>",
        total_amount: "<?= number_format($report['total_amount'] ?? 0, 2) ?>",
        lesson_summary: "<?= e(addslashes($report['lesson_summary'] ?? 'N/A')) ?>",
        student_progress: "<?= e(addslashes($report['student_progress'] ?? 'N/A')) ?>",
        topics_covered: "<?= e(addslashes($report['topics_covered'] ?? 'N/A')) ?>",
        homework_given: "<?= e(addslashes($report['homework_given'] ?? 'N/A')) ?>",
        tutor_notes: "<?= e(addslashes($report['tutor_notes'] ?? 'N/A')) ?>",
        materials_used: "<?= e(addslashes($report['materials_used'] ?? 'N/A')) ?>",
        next_session_focus: "<?= e(addslashes($report['next_session_focus'] ?? 'N/A')) ?>",
        attendance_status: "<?= $report['attendance_status'] ?>",
        submitted_at: "<?= date('d M Y, h:i A', strtotime($report['submitted_at'])) ?>"
    };
    <?php
}
?>

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

function applyFilters() {
    const search = document.getElementById('searchInput').value;
    const status = document.getElementById('statusFilter').value;
    let url = `admin_session_reports.php?status=${status}`;
    if (search && search.trim() !== '') {
        url += `&search=${encodeURIComponent(search.trim())}`;
    }
    window.location.href = url;
}
function viewReport(reportId) {
    const report = reportsData[reportId];
    if (!report) return;
    
    const modal = document.getElementById('viewModal');
    const content = document.getElementById('viewReportContent');
    
    // Helper function to check if field is empty
    function displayField(value, fieldName) {
        if (!value || value === 'N/A' || value.trim() === '') {
            return '<span style="color: #94a3b8; font-style: italic;">No ' + fieldName + ' provided</span>';
        }
        return value.replace(/\n/g, '<br>');
    }
    
    content.innerHTML = `
        <div class="report-details">
            <p><strong>Tutor:</strong> ${report.tutor_name} (${report.tutor_email})</p>
            <p><strong>Student:</strong> ${report.student_name} (${report.student_email})</p>
            <p><strong>Session Date & Time:</strong> ${report.session_date} at ${report.session_time}</p>
            <p><strong>Amount:</strong> RM ${report.total_amount}</p>
            <p><strong>Attendance:</strong> <span class="badge-${report.attendance_status}">${report.attendance_status}</span></p>
            <p><strong>Submitted:</strong> ${report.submitted_at}</p>
            
            <div class="section-title">Lesson Summary</div>
            <p>${displayField(report.lesson_summary, 'lesson summary')}</p>
            
            <div class="section-title">Student Progress</div>
            <p>${displayField(report.student_progress, 'student progress')}</p>
            
            <div class="section-title">Topics Covered</div>
            <p>${displayField(report.topics_covered, 'topics covered')}</p>
            
            <div class="section-title">Homework Given</div>
            <p>${displayField(report.homework_given, 'homework')}</p>
            
            <div class="section-title">Tutor Notes</div>
            <p>${displayField(report.tutor_notes, 'tutor notes')}</p>
            
            <div class="section-title">Materials Used</div>
            <p>${displayField(report.materials_used, 'materials')}</p>
            
            <div class="section-title">Next Session Focus</div>
            <p>${displayField(report.next_session_focus, 'next session focus')}</p>
        </div>
    `;
    
    modal.classList.add('active');
    document.body.style.overflow = 'hidden';
}

function verifyReport(reportId, action) {
    const report = reportsData[reportId];
    if (!report) return;
    
    document.getElementById('reportId').value = reportId;
    document.getElementById('verifyAction').value = action;
    
    const modalTitle = document.getElementById('modalTitle');
    const submitBtn = document.getElementById('submitBtn');
    const notesLabel = document.getElementById('notesLabel');
    const adminNotes = document.getElementById('adminNotes');
    const reportPreview = document.getElementById('reportPreview');
    
    // Show report preview in modal
    reportPreview.innerHTML = `
        <div class="report-details">
            <p><strong>Tutor:</strong> ${report.tutor_name}</p>
            <p><strong>Student:</strong> ${report.student_name}</p>
            <p><strong>Session:</strong> ${report.session_date} at ${report.session_time}</p>
            <p><strong>Amount:</strong> RM ${report.total_amount}</p>
            <p><strong>Lesson Summary:</strong> ${report.lesson_summary.substring(0, 100)}${report.lesson_summary.length > 100 ? '...' : ''}</p>
        </div>
    `;
    
    if (action === 'approve') {
        modalTitle.innerHTML = '<i class="bi bi-check-circle"></i> Approve Session Report';
        submitBtn.innerHTML = 'Approve Report';
        submitBtn.style.background = '#28a745';
        submitBtn.className = 'btn-save';
        notesLabel.innerHTML = '<i class="bi bi-pencil-square"></i> Admin Notes (Optional)';
        adminNotes.placeholder = 'Add any notes about this approval...';
        adminNotes.removeAttribute('required');
    } else {
        modalTitle.innerHTML = '<i class="bi bi-x-circle"></i> Reject Session Report';
        submitBtn.innerHTML = 'Reject Report';
        submitBtn.style.background = '#dc2626';
        submitBtn.className = 'btn-save reject-mode';
        notesLabel.innerHTML = '<i class="bi bi-exclamation-triangle-fill"></i> Reason for Rejection <span style="color: #dc2626;">*</span>';
        adminNotes.placeholder = 'Please provide a reason for rejection...';
        adminNotes.setAttribute('required', 'required');
    }
    
    adminNotes.value = '';
    document.getElementById('verifyModal').classList.add('active');
    document.body.style.overflow = 'hidden';
}

function closeVerifyModal() {
    document.getElementById('verifyModal').classList.remove('active');
    document.body.style.overflow = '';
    document.getElementById('adminNotes').removeAttribute('required');
}

function closeViewModal() {
    document.getElementById('viewModal').classList.remove('active');
    document.body.style.overflow = '';
}

// Close modals when clicking outside
window.onclick = function(event) {
    const verifyModal = document.getElementById('verifyModal');
    const viewModal = document.getElementById('viewModal');
    if (event.target === verifyModal) closeVerifyModal();
    if (event.target === viewModal) closeViewModal();
}

// Auto-dismiss alerts
setTimeout(() => {
    const successAlert = document.getElementById('successAlert');
    const errorAlert = document.getElementById('errorAlert');
    if (successAlert) {
        successAlert.style.opacity = '0';
        setTimeout(() => { if(successAlert) successAlert.remove(); }, 500);
    }
    if (errorAlert) {
        errorAlert.style.opacity = '0';
        setTimeout(() => { if(errorAlert) errorAlert.remove(); }, 500);
    }
}, 3000);
</script>

</body>
</html>