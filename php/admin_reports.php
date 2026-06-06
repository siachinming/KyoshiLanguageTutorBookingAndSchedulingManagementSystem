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

// Get counts for sidebar
$totalTutors = $conn->query("SELECT COUNT(*) as count FROM users WHERE role = 'tutor'")->fetch_assoc()['count'];
$totalStudents = $conn->query("SELECT COUNT(*) as count FROM users WHERE role = 'student'")->fetch_assoc()['count'];
$totalBookings = $conn->query("SELECT COUNT(*) as count FROM bookings")->fetch_assoc()['count'];
$pendingPayments = $conn->query("SELECT COUNT(*) as count FROM payments WHERE status = 'pending'")->fetch_assoc()['count'];
$pendingDisputes = $conn->query("SELECT COUNT(*) as count FROM disputes WHERE status = 'pending'")->fetch_assoc()['count'];
$pendingPayouts = $conn->query("SELECT COUNT(*) as count FROM payout_requests WHERE status = 'pending'")->fetch_assoc()['count'] ?? 0;

// Get selected month (default to current month)
$selected_month = isset($_GET['month']) ? $_GET['month'] : date('Y-m');
$report_month_name = date('F Y', strtotime($selected_month));
$start_date = date('Y-m-01', strtotime($selected_month));
$end_date = date('Y-m-t', strtotime($selected_month));

// ============================================
// 1. BOOKINGS SUMMARY for the month
// Using booking_date from your schema
// ============================================
$bookings_sql = "SELECT 
                    COUNT(*) as total_bookings,
                    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_bookings,
                    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_bookings,
                    SUM(CASE WHEN status = 'accepted' THEN 1 ELSE 0 END) as accepted_bookings,
                    SUM(CASE WHEN status = 'confirmed' THEN 1 ELSE 0 END) as confirmed_bookings,
                    SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_bookings,
                    SUM(CASE WHEN status = 'disputed' THEN 1 ELSE 0 END) as disputed_bookings,
                    SUM(CASE WHEN learning_mode = 'online' THEN 1 ELSE 0 END) as online_bookings,
                    SUM(CASE WHEN learning_mode = 'face_to_face' THEN 1 ELSE 0 END) as in_person_bookings
                FROM bookings 
                WHERE booking_date BETWEEN '$start_date' AND '$end_date'";
$bookings_data = $conn->query($bookings_sql)->fetch_assoc();

// Get daily bookings breakdown for chart
$daily_bookings_sql = "SELECT 
                        booking_date as date,
                        COUNT(*) as daily_count
                    FROM bookings 
                    WHERE booking_date BETWEEN '$start_date' AND '$end_date'
                    GROUP BY booking_date
                    ORDER BY booking_date ASC";
$daily_bookings_result = $conn->query($daily_bookings_sql);
$daily_labels = [];
$daily_counts = [];
while ($row = $daily_bookings_result->fetch_assoc()) {
    $daily_labels[] = date('d M', strtotime($row['date']));
    $daily_counts[] = $row['daily_count'];
}

// ============================================
// 2. PAYMENTS SUMMARY for the month
// ============================================
$payments_sql = "SELECT 
                    COUNT(*) as total_payments,
                    SUM(CASE WHEN status = 'verified' THEN 1 ELSE 0 END) as verified_payments,
                    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_payments,
                    SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected_payments,
                    SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_payments,
                    SUM(CASE WHEN status = 'disputed' THEN 1 ELSE 0 END) as disputed_payments,
                    SUM(CASE WHEN status = 'verified' THEN COALESCE(actual_paid_amount, amount, 0) ELSE 0 END) as total_revenue,
                    AVG(CASE WHEN status = 'verified' THEN COALESCE(actual_paid_amount, amount, 0) ELSE NULL END) as avg_payment,
                    SUM(CASE WHEN payment_method = 'stripe' AND status = 'verified' THEN COALESCE(actual_paid_amount, amount, 0) ELSE 0 END) as stripe_revenue,
                    SUM(CASE WHEN payment_method = 'online_banking' AND status = 'verified' THEN COALESCE(actual_paid_amount, amount, 0) ELSE 0 END) as online_banking_revenue,
                    SUM(CASE WHEN payment_method = 'duitnow' AND status = 'verified' THEN COALESCE(actual_paid_amount, amount, 0) ELSE 0 END) as duitnow_revenue
                FROM payments 
                WHERE DATE(created_at) BETWEEN '$start_date' AND '$end_date'";
$payments_data = $conn->query($payments_sql)->fetch_assoc();

// Daily payments for chart
$daily_payments_sql = "SELECT 
                        DATE(created_at) as date,
                        COUNT(*) as daily_payments,
                        SUM(COALESCE(actual_paid_amount, amount, 0)) as daily_revenue
                    FROM payments 
                    WHERE DATE(created_at) BETWEEN '$start_date' AND '$end_date' AND status = 'verified'
                    GROUP BY DATE(created_at)
                    ORDER BY date ASC";
$daily_payments_result = $conn->query($daily_payments_sql);
$daily_payment_labels = [];
$daily_revenue_data = [];
while ($row = $daily_payments_result->fetch_assoc()) {
    $daily_payment_labels[] = date('d M', strtotime($row['date']));
    $daily_revenue_data[] = $row['daily_revenue'];
}

// ============================================
// 3. DETAILED BOOKINGS LIST for the month
// ============================================
$detailed_bookings_sql = "SELECT 
                            b.id, b.booking_date, b.booking_time, b.language, b.learning_mode, b.status, b.cancel_reason, b.total_amount,
                            s.fullname as student_name, s.email as student_email,
                            t.fullname as tutor_name, t.email as tutor_email,
                            p.amount as payment_amount, p.actual_paid_amount, p.status as payment_status, p.payment_method,
                            sc.tutor_confirmed, sc.student_confirmed, sc.completed_at
                        FROM bookings b
                        JOIN users s ON b.student_id = s.id
                        JOIN users t ON b.tutor_id = t.id
                        LEFT JOIN payments p ON b.id = p.booking_id
                        LEFT JOIN session_completion sc ON b.id = sc.booking_id
                        WHERE b.booking_date BETWEEN '$start_date' AND '$end_date'
                        ORDER BY b.booking_date DESC, b.booking_time DESC";
$detailed_bookings = $conn->query($detailed_bookings_sql);

// ============================================
// 4. TOP TUTORS for the month
// ============================================
$top_tutors_sql = "SELECT 
                        u.id, u.fullname, u.email,
                        COUNT(b.id) as total_sessions,
                        SUM(CASE WHEN b.status = 'completed' THEN 1 ELSE 0 END) as completed_sessions,
                        SUM(CASE WHEN p.status = 'verified' THEN COALESCE(p.actual_paid_amount, p.amount, 0) ELSE 0 END) as total_earned
                    FROM users u
                    JOIN bookings b ON u.id = b.tutor_id
                    LEFT JOIN payments p ON b.id = p.booking_id AND p.status = 'verified'
                    WHERE b.booking_date BETWEEN '$start_date' AND '$end_date'
                    GROUP BY u.id
                    ORDER BY total_sessions DESC
                    LIMIT 5";
$top_tutors = $conn->query($top_tutors_sql);

// ============================================
// 5. TOP STUDENTS for the month
// ============================================
$top_students_sql = "SELECT 
                        u.id, u.fullname, u.email,
                        COUNT(b.id) as total_sessions,
                        SUM(CASE WHEN b.status = 'completed' THEN 1 ELSE 0 END) as completed_sessions,
                        SUM(CASE WHEN p.status = 'verified' THEN COALESCE(p.actual_paid_amount, p.amount, 0) ELSE 0 END) as total_spent
                    FROM users u
                    JOIN bookings b ON u.id = b.student_id
                    LEFT JOIN payments p ON b.id = p.booking_id AND p.status = 'verified'
                    WHERE b.booking_date BETWEEN '$start_date' AND '$end_date'
                    GROUP BY u.id
                    ORDER BY total_sessions DESC
                    LIMIT 5";
$top_students = $conn->query($top_students_sql);

// ============================================
// 6. LANGUAGE DISTRIBUTION
// ============================================
$language_sql = "SELECT 
                    language,
                    COUNT(*) as total_bookings,
                    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed
                FROM bookings 
                WHERE booking_date BETWEEN '$start_date' AND '$end_date'
                GROUP BY language
                ORDER BY total_bookings DESC";
$language_data = $conn->query($language_sql);

// ============================================
// 7. PAYMENT METHOD DISTRIBUTION
// ============================================
$payment_method_sql = "SELECT 
                        payment_method,
                        COUNT(*) as total,
                        SUM(COALESCE(actual_paid_amount, amount, 0)) as total_amount
                    FROM payments 
                    WHERE DATE(created_at) BETWEEN '$start_date' AND '$end_date' AND status = 'verified'
                    GROUP BY payment_method";
$payment_methods = $conn->query($payment_method_sql);

function e($value) {
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function formatMoney($amount) {
    return 'RM ' . number_format($amount ?? 0, 2);
}

function getStatusBadge($status) {
    switch(strtolower($status)) {
        case 'completed': return '<span class="badge-completed">COMPLETED</span>';
        case 'cancelled': return '<span class="badge-cancelled">CANCELLED</span>';
        case 'pending': return '<span class="badge-pending">PENDING</span>';
        case 'accepted': return '<span class="badge-approved">ACCEPTED</span>';
        case 'confirmed': return '<span class="badge-approved">CONFIRMED</span>';
        case 'disputed': return '<span class="badge-cancelled">DISPUTED</span>';
        case 'verified': return '<span class="badge-approved">VERIFIED</span>';
        case 'rejected': return '<span class="badge-cancelled">REJECTED</span>';
        default: return '<span class="badge-pending">'.$status.'</span>';
    }
}

// Generate month options for select (last 12 months)
$month_options = '';
for ($i = 0; $i < 12; $i++) {
    $month_val = date('Y-m', strtotime("-$i months"));
    $month_display = date('F Y', strtotime("-$i months"));
    $selected = ($month_val == $selected_month) ? 'selected' : '';
    $month_options .= "<option value=\"$month_val\" $selected>$month_display</option>";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Kyoshi | Monthly Reports</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
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
        body::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(255, 255, 255, 0.7);
            z-index: -1;
            pointer-events: none;
        }
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
        .sidebar-header { padding: 28px 20px; flex-shrink: 0; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .sidebar-footer {
            padding: 20px;
            border-top: 1px solid rgba(255,255,255,0.15);
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }
        .admin-info { display: flex; align-items: center; gap: 12px; flex: 1; overflow: hidden; }
        .brand-wrapper { display: flex; align-items: center; gap: 12px; }
        .brand-icon { width: 60px; height: 60px; object-fit: contain; }
        .brand-title h1 {
            font-size: 1.4rem;
            font-weight: 700;
            background: linear-gradient(135deg, #ffffff, #B26EA7);
            background-clip: text;
            -webkit-background-clip: text;
            color: transparent;
        }
        .admin-space-text { font-size: 0.6rem; color: #e7c7f7; }
        .nav-menu { padding: 16px 0; flex: 1; overflow-y: auto; min-height: 0; }
        .nav-menu::-webkit-scrollbar { width: 3px; }
        .nav-menu::-webkit-scrollbar-track { background: rgba(255,255,255,0.1); }
        .nav-menu::-webkit-scrollbar-thumb { background: #B26EA7; border-radius: 3px; }
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
        .nav-item:hover { background: rgba(255,255,255,0.08); color: white; }
        .nav-item.active { background: rgba(255,255,255,0.1); border-left-color: #B26EA7; color: white; }
        .nav-item i { width: 20px; font-size: 1.1rem; }
        .nav-section { margin-bottom: 8px; }
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
        .footer-avatar { width: 40px; height: 40px; border-radius: 50%; object-fit: cover; border: 2px solid rgba(255,255,255,0.2); }
        .admin-name { font-size: 0.8rem; font-weight: 600; color: white; }
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
        .logout-icon:hover { background: rgba(220, 38, 38, 0.4); color: white; }
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
        .page-title h1 { font-size: 1.4rem; font-weight: 700; color: #302E63; }
        .page-title p { font-size: 0.75rem; color: #7B6E8F; margin-top: 4px; }
        .admin-profile {
            display: flex;
            align-items: center;
            gap: 10px;
            background: white;
            padding: 6px 14px 6px 10px;
            border-radius: 50px;
            cursor: pointer;
            border: 1px solid #E4DCF0;
            position: relative;
        }
        .admin-profile img { width: 36px; height: 36px; border-radius: 50%; object-fit: cover; }
        .admin-profile span { font-weight: 600; font-size: 0.8rem; color: #302E63; }
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
        .dropdown a { display: flex; align-items: center; gap: 12px; padding: 10px 16px; text-decoration: none; color: #1E1B2E; font-size: 13px; font-weight: 500; transition: background 0.2s; }
        .dropdown a:hover { background: #F4F0F8; }
        .dropdown hr { margin: 0; border-color: #E4DCF0; }
        .relative { position: relative; }
        
        .filter-bar {
            background: white;
            border-radius: 20px;
            padding: 20px 24px;
            margin-bottom: 24px;
            border: 1px solid #E4DCF0;
            display: flex;
            flex-wrap: wrap;
            align-items: flex-end;
            gap: 16px;
            justify-content: space-between;
        }
        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        .filter-group label {
            font-size: 11px;
            font-weight: 700;
            color: #7B6E8F;
            text-transform: uppercase;
        }
        .filter-group select {
            padding: 10px 14px;
            border-radius: 12px;
            border: 1px solid #E4DCF0;
            font-family: inherit;
            font-size: 13px;
            background: #f8f9fa;
            min-width: 200px;
        }
        .btn-generate {
            background: #875D9C;
            color: white;
            border: none;
            padding: 10px 28px;
            border-radius: 12px;
            cursor: pointer;
            font-weight: 600;
            font-size: 13px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .btn-pdf {
            background: #dc2626;
            color: white;
            border: none;
            padding: 10px 28px;
            border-radius: 12px;
            cursor: pointer;
            font-weight: 600;
            font-size: 13px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .report-section { margin-bottom: 32px; }
        .section-title {
            font-size: 1.1rem;
            font-weight: 700;
            color: #302E63;
            margin-bottom: 16px;
            padding-bottom: 8px;
            border-bottom: 2px solid #875D9C;
            display: inline-block;
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
            margin-bottom: 28px;
        }
        .stat-card {
            background: white;
            border-radius: 20px;
            padding: 20px;
            border: 1px solid #E4DCF0;
            transition: all 0.2s;
        }
        .stat-card:hover { transform: translateY(-2px); box-shadow: 0 8px 24px rgba(48, 46, 99, 0.08); }
        .stat-header { display: flex; align-items: center; gap: 12px; margin-bottom: 12px; }
        .stat-icon {
            width: 44px;
            height: 44px;
            background: rgba(135, 93, 156, 0.1);
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .stat-icon i { font-size: 22px; color: #875D9C; }
        .stat-title { font-size: 12px; font-weight: 600; color: #7B6E8F; text-transform: uppercase; }
        .stat-value { font-size: 28px; font-weight: 800; color: #302E63; }
        .stat-sub { font-size: 11px; color: #A59BB5; margin-top: 6px; }
        .stat-row {
            display: flex;
            justify-content: space-between;
            margin-top: 12px;
            padding-top: 10px;
            border-top: 1px solid #EEF2F7;
            font-size: 12px;
        }
        
        .section-card {
            background: white;
            border-radius: 20px;
            margin-bottom: 28px;
            border: 1px solid #E4DCF0;
            overflow: hidden;
        }
        .section-header {
            padding: 18px 24px;
            border-bottom: 1px solid #E4DCF0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            background: #faf8fc;
        }
        .section-header h3 {
            font-size: 1rem;
            font-weight: 700;
            color: #302E63;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .section-header h3 i { color: #875D9C; }
        .section-body { padding: 20px 24px; overflow-x: auto; }
        
        .data-table {
            width: 100%;
            border-collapse: collapse;
        }
        .data-table th, .data-table td {
            padding: 12px 12px;
            text-align: left;
            border-bottom: 1px solid #EEF2F7;
            font-size: 13px;
        }
        .data-table th {
            background: #F8F8F8;
            font-weight: 700;
            color: #302E63;
            font-size: 12px;
        }
        .data-table tr:hover td { background: #FAFCFF; }
        
        .badge-approved, .badge-completed { background: #d4edda; color: #155724; padding: 4px 12px; border-radius: 20px; font-size: 11px; display: inline-block; }
        .badge-pending { background: #fff3cd; color: #856404; padding: 4px 12px; border-radius: 20px; font-size: 11px; display: inline-block; }
        .badge-cancelled { background: #f8d7da; color: #721c24; padding: 4px 12px; border-radius: 20px; font-size: 11px; display: inline-block; }
        
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
        .chart-title { font-size: 0.85rem; font-weight: 700; color: #302E63; margin-bottom: 14px; }
        canvas { max-height: 250px; width: 100%; }
        
        .two-columns {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
        }
        
        @media (max-width: 768px) {
            .menu-toggle { display: block; }
            .sidebar { transform: translateX(-100%); }
            .sidebar.open { transform: translateX(0); }
            .main-content { margin-left: 0; padding: 16px; }
            .stats-grid { grid-template-columns: 1fr; }
            .charts-row { grid-template-columns: 1fr; }
            .two-columns { grid-template-columns: 1fr; }
            .filter-bar { flex-direction: column; align-items: stretch; }
            .btn-generate, .btn-pdf { width: 100%; justify-content: center; }
        }
        
        .empty-state { text-align: center; padding: 40px; color: #94a3b8; }
        .empty-state i { font-size: 48px; margin-bottom: 12px; display: block; }
        
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
        
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 9999;
            display: none;
            justify-content: center;
            align-items: center;
        }
        .loading-overlay.active { display: flex; }
        .spinner {
            width: 50px;
            height: 50px;
            border: 4px solid #f3f3f3;
            border-top: 4px solid #875D9C;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            background: white;
            padding: 10px;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        @media print {
            .sidebar, .top-bar, .filter-bar, .btn-pdf, .menu-toggle, .admin-profile, .sidebar-overlay, .btn-generate { display: none !important; }
            .main-content { margin-left: 0 !important; padding: 0 !important; }
            .stat-card, .section-card, .chart-card { break-inside: avoid; page-break-inside: avoid; }
            body { background: white; }
        }
        
        .report-header {
            text-align: center;
            margin-bottom: 28px;
        }
        .report-header h2 {
            font-size: 1.6rem;
            color: #302E63;
        }
        .report-header p {
            color: #7B6E8F;
            font-size: 12px;
            margin-top: 6px;
        }
    </style>
</head>
<body>
<div class="sidebar-overlay" id="sidebarOverlay"></div>
<div class="loading-overlay" id="loadingOverlay">
    <div class="spinner"></div>
</div>

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
        <div class="nav-section"><a href="admin_dashboard.php" class="nav-item"><i class="bi bi-speedometer2"></i><span>Dashboard</span></a></div>
        <div class="nav-section">
            <div class="nav-section-label">USERS</div>
            <a href="admin_tutor_actions.php" class="nav-item"><i class="bi bi-person-badge"></i><span>Tutors</span><span class="nav-badge"><?= $totalTutors ?></span></a>
            <a href="admin_student_actions.php" class="nav-item"><i class="bi bi-person"></i><span>Students</span><span class="nav-badge"><?= $totalStudents ?></span></a>
        </div>
        <div class="nav-section">
            <div class="nav-section-label">FINANCE</div>
            <a href="admin_payments.php" class="nav-item"><i class="bi bi-credit-card"></i><span>Payments</span><span class="nav-badge"><?= $pendingPayments ?></span></a>
            <a href="admin_payouts.php" class="nav-item"><i class="bi bi-cash-stack"></i><span>Payouts</span><span class="nav-badge"><?= $pendingPayouts ?></span></a>
        </div>
        <div class="nav-section">
            <div class="nav-section-label">BOOKINGS</div>
            <a href="admin_bookings.php" class="nav-item"><i class="bi bi-calendar-check"></i><span>Bookings</span><span class="nav-badge"><?= $totalBookings ?></span></a>
            <a href="admin_disputes.php" class="nav-item"><i class="bi bi-flag"></i><span>Disputes</span><span class="nav-badge"><?= $pendingDisputes ?></span></a>
        </div>
         <div class="nav-section">
            <div class="nav-section-label">REPORTS</div>
            <a href="admin_reports.php" class="nav-item active">
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
        <a href="logout.php" class="logout-icon" title="Logout"><i class="bi bi-box-arrow-right"></i></a>
    </div>
</aside>

<div class="main-content" id="mainContent">
    <div class="top-bar">
        <button class="menu-toggle" id="menuToggle"><i class="bi bi-list"></i> Menu</button>
        <div class="page-title">
            <h1>Monthly Reports</h1>
            <p>Generate and download monthly booking & payment reports</p>
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

    <div class="filter-bar">
        <div class="filter-group">
            <label><i class="bi bi-calendar-month"></i> Select Month</label>
            <form method="GET" action="" id="reportForm">
                <select name="month" id="monthSelect">
                    <?= $month_options ?>
                </select>
            </form>
        </div>
        <div style="display: flex; gap: 12px;">
            <button type="button" class="btn-generate" onclick="generateReport()">
                <i class="bi bi-graph-up"></i> Generate Report
            </button>
            <button type="button" class="btn-pdf" onclick="downloadPDF()">
                <i class="bi bi-file-pdf"></i> Download PDF
            </button>
        </div>
    </div>

    <div id="reportContent">
        <div class="report-header">
            <h2><?= $report_month_name ?> - System Report</h2>
            <p>Generated on <?= date('d M Y, h:i A') ?></p>
        </div>

        <!-- SECTION 1: BOOKINGS SUMMARY -->
        <div class="report-section">
            <div class="section-title"><i class="bi bi-calendar-check"></i> 1. Bookings Summary</div>
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon"><i class="bi bi-calendar-check"></i></div>
                        <div class="stat-title">Total Bookings</div>
                    </div>
                    <div class="stat-value"><?= number_format($bookings_data['total_bookings'] ?? 0) ?></div>
                    <div class="stat-sub">For <?= $report_month_name ?></div>
                    <div class="stat-row">
                        <span>Completed: <?= $bookings_data['completed_bookings'] ?? 0 ?></span>
                        <span>Accepted: <?= $bookings_data['accepted_bookings'] ?? 0 ?></span>
                    </div>
                    <div class="stat-row">
                        <span>Pending: <?= $bookings_data['pending_bookings'] ?? 0 ?></span>
                        <span>Cancelled: <?= $bookings_data['cancelled_bookings'] ?? 0 ?></span>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon"><i class="bi bi-laptop"></i></div>
                        <div class="stat-title">Learning Mode</div>
                    </div>
                    <div class="stat-value"><?= number_format(($bookings_data['online_bookings'] ?? 0) + ($bookings_data['in_person_bookings'] ?? 0)) ?></div>
                    <div class="stat-sub">Total sessions</div>
                    <div class="stat-row">
                        <span><i class="bi bi-wifi"></i> Online: <?= $bookings_data['online_bookings'] ?? 0 ?></span>
                        <span><i class="bi bi-building"></i> Face-to-face: <?= $bookings_data['in_person_bookings'] ?? 0 ?></span>
                    </div>
                </div>
            </div>
        </div>

        <div class="charts-row">
            <div class="chart-card">
                <div class="chart-title">Daily Bookings Trend</div>
                <canvas id="dailyBookingsChart" width="400" height="200"></canvas>
            </div>
            <div class="chart-card">
                <div class="chart-title">Booking Status Distribution</div>
                <canvas id="statusChart" width="400" height="200"></canvas>
            </div>
        </div>

        <!-- SECTION 2: PAYMENTS SUMMARY -->
        <div class="report-section">
            <div class="section-title"><i class="bi bi-credit-card"></i> 2. Payments Summary</div>
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon"><i class="bi bi-credit-card"></i></div>
                        <div class="stat-title">Total Transactions</div>
                    </div>
                    <div class="stat-value"><?= number_format($payments_data['total_payments'] ?? 0) ?></div>
                    <div class="stat-sub">Payment attempts</div>
                    <div class="stat-row">
                        <span>✅ Verified: <?= $payments_data['verified_payments'] ?? 0 ?></span>
                        <span>⏳ Pending: <?= $payments_data['pending_payments'] ?? 0 ?></span>
                    </div>
                    <div class="stat-row">
                        <span>❌ Rejected: <?= $payments_data['rejected_payments'] ?? 0 ?></span>
                        <span>⚖️ Disputed: <?= $payments_data['disputed_payments'] ?? 0 ?></span>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon"><i class="bi bi-cash-stack"></i></div>
                        <div class="stat-title">Total Revenue</div>
                    </div>
                    <div class="stat-value"><?= formatMoney($payments_data['total_revenue'] ?? 0) ?></div>
                    <div class="stat-sub">From verified payments</div>
                    <div class="stat-row">
                        <span>💳 Stripe: <?= formatMoney($payments_data['stripe_revenue'] ?? 0) ?></span>
                        <span>🏦 Online Banking: <?= formatMoney($payments_data['online_banking_revenue'] ?? 0) ?></span>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-icon"><i class="bi bi-graph-up"></i></div>
                        <div class="stat-title">Average Payment</div>
                    </div>
                    <div class="stat-value"><?= formatMoney($payments_data['avg_payment'] ?? 0) ?></div>
                    <div class="stat-sub">Per successful transaction</div>
                    <div class="stat-row">
                        <span>📊 Success rate: <?= $payments_data['total_payments'] > 0 ? round(($payments_data['verified_payments'] / $payments_data['total_payments']) * 100, 1) : 0 ?>%</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="charts-row">
            <div class="chart-card">
                <div class="chart-title">💰 Daily Revenue Trend</div>
                <canvas id="dailyRevenueChart" width="400" height="200"></canvas>
            </div>
            <div class="chart-card">
                <div class="chart-title">🌍 Languages Distribution</div>
                <canvas id="languageChart" width="400" height="200"></canvas>
            </div>
        </div>

        <!-- SECTION 3: TOP TUTORS & STUDENTS -->
        <div class="two-columns">
            <div class="section-card">
                <div class="section-header">
                    <h3><i class="bi bi-trophy"></i> Top Tutors (by sessions)</h3>
                </div>
                <div class="section-body">
                    <?php if ($top_tutors->num_rows > 0): ?>
                        <table class="data-table">
                            <thead><tr><th>Tutor</th><th>Sessions</th><th>Completed</th><th>Earned</th></tr></thead>
                            <tbody>
                            <?php while($tutor = $top_tutors->fetch_assoc()): ?>
                                <tr>
                                    <td><strong><?= e($tutor['fullname']) ?></strong><br><small><?= e($tutor['email']) ?></small></td>
                                    <td><?= $tutor['total_sessions'] ?></td>
                                    <td><?= $tutor['completed_sessions'] ?></td>
                                    <td><?= formatMoney($tutor['total_earned']) ?></td>
                                </tr>
                            <?php endwhile; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="empty-state"><i class="bi bi-person-x"></i><p>No tutor data available</p></div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="section-card">
                <div class="section-header">
                    <h3><i class="bi bi-star"></i> Top Students (by sessions)</h3>
                </div>
                <div class="section-body">
                    <?php if ($top_students->num_rows > 0): ?>
                        <table class="data-table">
                            <thead><tr><th>Student</th><th>Sessions</th><th>Completed</th><th>Spent</th></tr></thead>
                            <tbody>
                            <?php while($student = $top_students->fetch_assoc()): ?>
                                <tr>
                                    <td><strong><?= e($student['fullname']) ?></strong><br><small><?= e($student['email']) ?></small></td>
                                    <td><?= $student['total_sessions'] ?></td>
                                    <td><?= $student['completed_sessions'] ?></td>
                                    <td><?= formatMoney($student['total_spent']) ?></td>
                                </tr>
                            <?php endwhile; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="empty-state"><i class="bi bi-person-x"></i><p>No student data available</p></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- SECTION 4: DETAILED BOOKINGS LIST -->
        <div class="section-card">
            <div class="section-header">
                <h3><i class="bi bi-table"></i> Detailed Bookings List</h3>
                <span style="font-size: 12px; color: #7B6E8F;">Total: <?= $detailed_bookings->num_rows ?> records</span>
            </div>
            <div class="section-body">
                <?php if ($detailed_bookings->num_rows > 0): ?>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>ID</th><th>Student</th><th>Tutor</th><th>Language</th>
                                <th>Date</th><th>Time</th><th>Mode</th><th>Amount</th><th>Payment</th><th>Status</th><th>Cancel Reason</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php while($booking = $detailed_bookings->fetch_assoc()): ?>
                            <tr>
                                <td>#<?= $booking['id'] ?></td>
                                <td><strong><?= e($booking['student_name']) ?></strong><br><small><?= e($booking['student_email']) ?></small></td>
                                <td><?= e($booking['tutor_name']) ?></td>
                                <td><?= e($booking['language']) ?></td>
                                <td><?= date('d M Y', strtotime($booking['booking_date'])) ?></td>
                                <td><?= date('h:i A', strtotime($booking['booking_time'])) ?></td>
                                <td><span class="badge-pending" style="background:#e9ecef;"><?= $booking['learning_mode'] == 'online' ? 'Online' : 'Face-to-face' ?></span></td>
                                <td><?= formatMoney($booking['total_amount']) ?></td>
                                <td><?= getStatusBadge($booking['payment_status'] ?? 'pending') ?></td>
                                <td><?= getStatusBadge($booking['status']) ?></td>
                                <td><?= e($booking['cancel_reason']) ?: '—' ?></td>
                            </tr>
                        <?php endwhile; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="empty-state"><i class="bi bi-inbox"></i><p>No bookings found for <?= $report_month_name ?></p></div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
function toggleDropdown() {
    const dd = document.getElementById('profileDropdown');
    dd.style.display = dd.style.display === 'block' ? 'none' : 'block';
}

window.addEventListener('click', function(e) {
    const dropdown = document.getElementById('profileDropdown');
    const button = document.querySelector('.admin-profile');
    if (button && !button.contains(e.target) && dropdown && !dropdown.contains(e.target)) {
        dropdown.style.display = 'none';
    }
});

function generateReport() {
    const month = document.getElementById('monthSelect').value;
    window.location.href = `admin_reports.php?month=${month}`;
}

function downloadPDF() {
    const loadingOverlay = document.getElementById('loadingOverlay');
    loadingOverlay.classList.add('active');
    
    const element = document.getElementById('reportContent');
    const opt = {
        margin: [0.5, 0.5, 0.5, 0.5],
        filename: `monthly_report_<?= $selected_month ?>.pdf`,
        image: {
    type: 'jpeg',
    quality: 0.8
},
        html2canvas: { scale: 2, useCORS: true, logging: false },
        jsPDF: { unit: 'in', format: 'a4', orientation: 'portrait' }
    };
    
    html2pdf().set(opt).from(element).save().then(() => {
        loadingOverlay.classList.remove('active');
    }).catch(() => {
        loadingOverlay.classList.remove('active');
        alert('Error generating PDF. Please try again.');
    });
}

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

// Charts
const dailyLabels = <?= json_encode($daily_labels) ?>;
const dailyCounts = <?= json_encode($daily_counts) ?>;

const dailyCtx = document.getElementById('dailyBookingsChart')?.getContext('2d');
if (dailyCtx && dailyLabels.length > 0) {
    new Chart(dailyCtx, {
        type: 'bar',
        data: {
            labels: dailyLabels,
            datasets: [{
                label: 'Number of Bookings',
                data: dailyCounts,
                backgroundColor: '#875D9C',
                borderRadius: 8,
                borderWidth: 0
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            plugins: { legend: { position: 'top', labels: { font: { size: 10 } } } },
            scales: {
                y: { beginAtZero: true, ticks: { stepSize: 1, precision: 0 }, title: { display: true, text: 'Bookings', font: { size: 10 } } },
                x: { ticks: { maxRotation: 45, minRotation: 45, font: { size: 9 } } }
            }
        }
    });
}

const statusCtx = document.getElementById('statusChart')?.getContext('2d');
if (statusCtx) {
    new Chart(statusCtx, {
        type: 'doughnut',
        data: {
            labels: ['Completed', 'Confirmed', 'Accepted', 'Pending', 'Cancelled', 'Disputed'],
            datasets: [{
                data: [<?= $bookings_data['completed_bookings'] ?? 0 ?>, <?= $bookings_data['confirmed_bookings'] ?? 0 ?>, <?= $bookings_data['accepted_bookings'] ?? 0 ?>, <?= $bookings_data['pending_bookings'] ?? 0 ?>, <?= $bookings_data['cancelled_bookings'] ?? 0 ?>, <?= $bookings_data['disputed_bookings'] ?? 0 ?>],
                backgroundColor: ['#28A745', '#17a2b8', '#875D9C', '#F59E0B', '#DC2626', '#6c757d'],
                borderWidth: 0
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            plugins: { legend: { position: 'bottom', labels: { font: { size: 10 } } } }
        }
    });
}

const revenueLabels = <?= json_encode($daily_payment_labels) ?>;
const revenueData = <?= json_encode($daily_revenue_data) ?>;

const revenueCtx = document.getElementById('dailyRevenueChart')?.getContext('2d');
if (revenueCtx && revenueLabels.length > 0) {
    new Chart(revenueCtx, {
        type: 'line',
        data: {
            labels: revenueLabels,
            datasets: [{
                label: 'Revenue (RM)',
                data: revenueData,
                borderColor: '#28A745',
                backgroundColor: 'rgba(40, 167, 69, 0.1)',
                borderWidth: 3,
                fill: true,
                tension: 0.3,
                pointBackgroundColor: '#28A745',
                pointBorderColor: '#fff',
                pointBorderWidth: 2,
                pointRadius: 4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            plugins: { legend: { position: 'top', labels: { font: { size: 10 } } } },
            scales: {
                y: { beginAtZero: true, title: { display: true, text: 'Revenue (RM)', font: { size: 10 } } },
                x: { ticks: { maxRotation: 45, minRotation: 45, font: { size: 9 } } }
            }
        }
    });
}

const languageLabels = [];
const languageCounts = [];
<?php 
$language_data->data_seek(0);
while($lang = $language_data->fetch_assoc()): 
?>
languageLabels.push('<?= e($lang['language']) ?>');
languageCounts.push(<?= $lang['total_bookings'] ?>);
<?php endwhile; ?>

const langCtx = document.getElementById('languageChart')?.getContext('2d');
if (langCtx && languageLabels.length > 0) {
    new Chart(langCtx, {
        type: 'pie',
        data: {
            labels: languageLabels,
            datasets: [{
                data: languageCounts,
                backgroundColor: ['#875D9C', '#28A745', '#F59E0B', '#DC2626', '#17a2b8', '#6f42c1'],
                borderWidth: 0
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            plugins: { legend: { position: 'bottom', labels: { font: { size: 10 } } } }
        }
    });
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