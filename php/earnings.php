<?php
session_start();
include 'config.php';

$assetBase = '../assets/img';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'tutor') {
    header("Location: login.php");
    exit();
}

$userID = $_SESSION['user_id'];

// Get tutor info
$stmt = $conn->prepare("SELECT * FROM users WHERE id = ? AND role = 'tutor'");
$stmt->bind_param("i", $userID);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

if (!$user) {
    header("Location: login.php");
    exit();
}

$displayName = $user['fullname'];
$profilePic = !empty($user['profile_pic'])
    ? '../uploads/profiles/' . $user['profile_pic']
    : $assetBase . '/profile-tutor.png';

// Platform commission rate (20%)
$COMMISSION_RATE = 0.20;

// Get ALL completed bookings with attendance info
$stmt = $conn->prepare("
    SELECT 
        b.id,
        b.language,
        b.booking_date,
        b.booking_time,
        b.total_amount,
        b.status,
        sc.completed_at,
        sc.student_confirmed,
        sc.no_show_type,
        sc.attendance_manually_set,
        u.fullname as student_name,
        u.id as student_id
    FROM bookings b
    JOIN users u ON b.student_id = u.id
    LEFT JOIN session_completion sc ON b.id = sc.booking_id
    WHERE b.tutor_id = ? 
        AND b.status = 'completed'
    ORDER BY b.booking_date DESC
");
$stmt->bind_param("i", $userID);
$stmt->execute();
$allCompletedBookings = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Check payments table for verified status
$stmt = $conn->prepare("
    SELECT booking_id, status as payment_status 
    FROM payments 
    WHERE status = 'verified'
");
$stmt->execute();
$verifiedPayments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$verifiedBookingIds = array_column($verifiedPayments, 'booking_id');

// Check which sessions have reports submitted
$stmt = $conn->prepare("
    SELECT booking_id, report_status 
    FROM session_reports 
    WHERE tutor_id = ? AND report_status = 'submitted'
");
$stmt->bind_param("i", $userID);
$stmt->execute();
$submittedReports = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$submittedBookingIds = array_column($submittedReports, 'booking_id');

// Filter bookings based on attendance type
$verifiedBookings = [];        // Sessions that count for earnings (attended + verified + has report) OR (student no-show + verified)
$pendingReportCount = 0;       // Sessions that need report (attended + verified + NO report)
$pendingVerificationCount = 0; // Sessions waiting for payment verification
$studentNoShowCount = 0;       // Student no-show (paid but no report needed)
$tutorNoShowCount = 0;         // Tutor no-show (refunded, no payment)

foreach ($allCompletedBookings as $booking) {
    $isVerified = in_array($booking['id'], $verifiedBookingIds);
    $hasReport = in_array($booking['id'], $submittedBookingIds);
    
    // Determine attendance type
    $studentConfirmed = $booking['student_confirmed'];
    $noShowType = $booking['no_show_type'];
    
    // Case 1: Student attended the session (confirmed = 1)
    if ($studentConfirmed == 1) {
        if ($isVerified) {
            if ($hasReport) {
                $verifiedBookings[] = $booking;
            } else {
                // Attended, payment verified, but report not submitted
                $pendingReportCount++;
            }
        } else {
            $pendingVerificationCount++;
        }
    }
    // Case 2: Student no-show (didn't come) - Tutor still gets paid, NO report needed
    elseif ($studentConfirmed == 0 && $noShowType == 'student_no_show') {
        if ($isVerified) {
            // Count as earnings but NO report needed
            $verifiedBookings[] = $booking;
            $studentNoShowCount++;
        } else {
            $pendingVerificationCount++;
        }
    }
    // Case 3: Tutor no-show - Refund, NO payment, NO report
    elseif ($studentConfirmed == 0 && $noShowType == 'tutor_no_show') {
        $tutorNoShowCount++;
        // Do NOT include in earnings
    }
    // Case 4: Unknown or no completion record yet
    else {
        // Don't count until attendance is confirmed
    }
}

// Calculate total earnings (only verified bookings with attendance OR student no-show)
$totalGross = 0;
$totalCommission = 0;
$totalNet = 0;
$monthlyEarnings = [];
$studentEarnings = [];
$languageEarnings = [];
$chartMonths = [];
$chartNetEarnings = [];
$chartGrossEarnings = [];

foreach ($verifiedBookings as $booking) {
    $amount = floatval($booking['total_amount']);
    $commission = $amount * $COMMISSION_RATE;
    $net = $amount - $commission;
    
    $totalGross += $amount;
    $totalCommission += $commission;
    $totalNet += $net;
    
    // Monthly breakdown for charts
    $month = date('Y-m', strtotime($booking['booking_date']));
    $monthName = date('M Y', strtotime($booking['booking_date']));
    
    if (!isset($monthlyEarnings[$month])) {
        $monthlyEarnings[$month] = [
            'gross' => 0,
            'commission' => 0,
            'net' => 0,
            'count' => 0,
            'month_name' => $monthName,
            'student_no_shows' => 0,
            'attended' => 0
        ];
    }
    $monthlyEarnings[$month]['gross'] += $amount;
    $monthlyEarnings[$month]['commission'] += $commission;
    $monthlyEarnings[$month]['net'] += $net;
    $monthlyEarnings[$month]['count']++;
    
    // Track attendance type for this booking
    if ($booking['student_confirmed'] == 0 && $booking['no_show_type'] == 'student_no_show') {
        $monthlyEarnings[$month]['student_no_shows']++;
    } else {
        $monthlyEarnings[$month]['attended']++;
    }
    
    // Student earnings tracking
    $studentName = $booking['student_name'];
    if (!isset($studentEarnings[$studentName])) {
        $studentEarnings[$studentName] = [
            'student_id' => $booking['student_id'],
            'total' => 0,
            'count' => 0,
            'languages' => [],
            'no_shows' => 0
        ];
    }
    $studentEarnings[$studentName]['total'] += $net;
    $studentEarnings[$studentName]['count']++;
    
    // Track if this was a student no-show
    if ($booking['student_confirmed'] == 0 && $booking['no_show_type'] == 'student_no_show') {
        $studentEarnings[$studentName]['no_shows']++;
    }
    
    if (!in_array($booking['language'], $studentEarnings[$studentName]['languages'])) {
        $studentEarnings[$studentName]['languages'][] = $booking['language'];
    }
    
    // Language earnings tracking for pie chart
    if (!isset($languageEarnings[$booking['language']])) {
        $languageEarnings[$booking['language']] = [
            'total' => 0,
            'count' => 0
        ];
    }
    $languageEarnings[$booking['language']]['total'] += $net;
    $languageEarnings[$booking['language']]['count']++;
}

// Sort months in ascending order for chart
ksort($monthlyEarnings);
foreach ($monthlyEarnings as $month => $data) {
    $chartMonths[] = $data['month_name'];
    $chartNetEarnings[] = $data['net'];
    $chartGrossEarnings[] = $data['gross'];
}

// Sort students by total earnings (highest first)
arsort($studentEarnings);
$topStudents = array_slice($studentEarnings, 0, 5, true);

// Sort languages by earnings
arsort($languageEarnings);

// Get current month earnings
$currentMonth = date('Y-m');
$currentMonthEarnings = $monthlyEarnings[$currentMonth] ?? ['gross' => 0, 'commission' => 0, 'net' => 0, 'count' => 0];
$totalSessions = count($verifiedBookings);

// Check if tutor can request payout (must have at least RM50 and NO pending reports for attended sessions)
// Note: Student no-shows don't require reports, so they don't block payout
$canRequestPayout = ($totalNet >= 50) && ($pendingReportCount == 0);

// Get payout history
$payoutHistory = [];
$checkTable = $conn->query("SHOW TABLES LIKE 'payout_requests'");
if ($checkTable->num_rows > 0) {
    $stmt = $conn->prepare("
        SELECT * FROM payout_requests 
        WHERE tutor_id = ? 
        ORDER BY requested_at DESC
    ");
    $stmt->bind_param("i", $userID);
    $stmt->execute();
    $payoutHistory = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function e($value) {
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function formatMoney($amount) {
    return 'RM ' . number_format($amount, 2);
}

// Handle payout request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_payout'])) {
    $amount = floatval($_POST['amount'] ?? 0);
    
    if ($amount <= 0) {
        $payoutMessage = "Please enter a valid amount";
        $payoutMessageType = "error";
    } elseif ($amount > $totalNet) {
        $payoutMessage = "Request amount exceeds your available balance";
        $payoutMessageType = "error";
    } elseif ($amount < 50) {
        $payoutMessage = "Minimum payout amount is RM50";
        $payoutMessageType = "error";
    } elseif ($pendingReportCount > 0) {
        $payoutMessage = "You have $pendingReportCount attended session(s) with payment verified but report not submitted. Please submit all session reports before requesting payout.";
        $payoutMessageType = "error";
    } else {
        // Create payout_requests table if not exists
        $conn->query("
            CREATE TABLE IF NOT EXISTS payout_requests (
                id INT AUTO_INCREMENT PRIMARY KEY,
                tutor_id INT NOT NULL,
                amount DECIMAL(10,2) NOT NULL,
                status ENUM('pending', 'approved', 'completed', 'rejected') DEFAULT 'pending',
                requested_at DATETIME,
                processed_at DATETIME,
                admin_notes TEXT,
                FOREIGN KEY (tutor_id) REFERENCES users(id)
            )
        ");
        
        $stmt = $conn->prepare("
            INSERT INTO payout_requests (tutor_id, amount, status, requested_at) 
            VALUES (?, ?, 'pending', NOW())
        ");
        $stmt->bind_param("id", $userID, $amount);
        
        if ($stmt->execute()) {
            $payoutMessage = "Payout request submitted successfully! Admin will process within 3-5 business days.";
            $payoutMessageType = "success";
            
            header("Location: earnings.php?success=1");
            exit();
        } else {
            $payoutMessage = "Error submitting request. Please try again.";
            $payoutMessageType = "error";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Earnings - Kyoshi Tutor</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Poppins', sans-serif;
            background: url('../assets/img/background2.png') no-repeat center top;
            background-size: cover;
            min-height: 100vh;
            position: relative;
        }
        body::before {
            content: '';
            position: fixed;
            inset: 0;
            background: rgba(255, 255, 255, 0.25);
            z-index: -1;
        }
        .topbar {
            width: 100%;
            background: rgba(254, 214, 206, 0.92);
            backdrop-filter: blur(12px);
            position: sticky;
            top: 0;
            z-index: 999;
            box-shadow: 0 2px 20px rgba(0, 0, 0, 0.08);
            border-bottom: 1px solid rgba(255, 255, 255, 0.3);
        }
        .container { width: min(1400px, 94%); margin: auto; }
        .nav { display: flex; justify-content: space-between; align-items: center; gap: 32px; min-height: 70px; }
        .brand {
            display: flex;
            align-items: center;
            gap: 10px;
            text-decoration: none;
            flex-shrink: 0;
        }
        .brand img { width: 42px; height: 42px; object-fit: contain; }
        .brand strong { display: block; color: #1d3156; font-size: 20px; line-height: 1.2; }
        .brand span { color: #496894; font-size: 11px; }
        .nav-links { display: flex; gap: 28px; align-items: center; flex-wrap: wrap; }
        .nav-links a {
            text-decoration: none;
            color: #1d3156;
            font-size: 14px;
            font-weight: 600;
            position: relative;
            transition: 0.25s;
            padding: 6px 0;
        }
        .nav-links a:hover, .nav-links a.active { color: #496894; }
        .nav-links a::after {
            content: '';
            position: absolute;
            left: 0;
            bottom: -6px;
            width: 0%;
            height: 3px;
            background: #496894;
            transition: 0.25s;
            border-radius: 10px;
        }
        .nav-links a:hover::after, .nav-links .active::after { width: 100%; }
        .profile {
            display: flex;
            align-items: center;
            gap: 8px;
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            padding: 6px 14px 6px 8px;
            border-radius: 40px;
            cursor: pointer;
            color: black;
            transition: 0.25s;
            position: relative;
        }
        .profile:hover { background: rgba(255, 255, 255, 0.2); }
        .profile img { width: 36px; height: 36px; border-radius: 50%; object-fit: cover; border: 2px solid rgba(255, 255, 255, 0.3); }
        .profile span { font-size: 13px; font-weight: 500; }
        .dropdown {
            position: absolute;
            top: calc(100% + 10px);
            right: 0;
            width: 220px;
            background: white;
            border-radius: 16px;
            overflow: hidden;
            display: none;
            border: 1px solid #e2edf7;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.15);
            z-index: 1000;
        }
        .dropdown a {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 18px;
            text-decoration: none;
            color: #1e293b;
            font-size: 13px;
            font-weight: 500;
        }
        .dropdown a:hover { background: #f8fafc; }
        .dropdown hr { border: none; border-top: 1px solid #ecf3f9; }
        .main { width: min(1280px, 92%); margin: 32px auto 48px; }
        .back-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: white;
            color: #1d3156;
            padding: 10px 20px;
            border-radius: 40px;
            text-decoration: none;
            font-weight: 600;
            font-size: 14px;
            border: 1px solid #e2e8f0;
            transition: 0.25s;
        }
        .back-btn:hover { background: #b8d0e9; border-color: #6b9cd7; transform: translateX(-3px); }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 30px;
        }
        .stat-card {
            background: white;
            border-radius: 20px;
            padding: 24px;
            text-align: center;
            border: 1px solid #eef2f7;
            transition: all 0.3s ease;
        }
        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.08);
        }
        .stat-icon {
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, #E75A9B, #F28AB2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 15px;
        }
        .stat-icon i { font-size: 28px; color: white; }
        .stat-value { font-size: 28px; font-weight: 800; color: #E75A9B; }
        .stat-label { font-size: 13px; color: #64748b; margin-top: 5px; }
        .stat-sub { font-size: 11px; color: #94a3b8; margin-top: 3px; }
        .stat-warning {
            font-size: 11px;
            color: #f59e0b;
            margin-top: 5px;
            background: #fef3c7;
            padding: 4px 8px;
            border-radius: 20px;
            display: inline-block;
        }
        .stat-info {
            font-size: 11px;
            color: #28a745;
            margin-top: 5px;
            background: #d4edda;
            padding: 4px 8px;
            border-radius: 20px;
            display: inline-block;
        }

        .charts-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 24px;
            margin-bottom: 30px;
        }
        .chart-card {
            background: white;
            border-radius: 20px;
            padding: 20px;
            border: 1px solid #eef2f7;
            transition: all 0.3s ease;
        }
        .chart-card:hover {
            box-shadow: 0 8px 25px rgba(0,0,0,0.08);
        }
        .chart-title {
            font-size: 16px;
            font-weight: 700;
            color: #1d3156;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .chart-container {
            position: relative;
            height: 300px;
        }
        canvas {
            max-height: 280px;
            width: 100%;
        }

        .payout-card {
            background: linear-gradient(135deg, #1d3156, #2d4a7c);
            border-radius: 24px;
            padding: 24px;
            margin-bottom: 24px;
            color: white;
        }
        .payout-card h3 {
            font-size: 18px;
            margin-bottom: 8px;
        }
        .payout-card p {
            font-size: 13px;
            opacity: 0.9;
            margin-bottom: 16px;
        }
        .payout-amount {
            font-size: 36px;
            font-weight: 800;
            margin-bottom: 16px;
        }
        .btn-payout {
            background: #28a745;
            color: white;
            border: none;
            padding: 12px 28px;
            border-radius: 40px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            transition: 0.2s;
        }
        .btn-payout:hover {
            transform: translateY(-2px);
            background: #218838;
        }
        .btn-payout.disabled {
            background: #94a3b8;
            cursor: not-allowed;
        }
        .glass-card {
            background: rgba(255, 255, 255, 0.92);
            border: 1px solid rgba(255, 255, 255, 0.55);
            border-radius: 24px;
            overflow: hidden;
            margin-bottom: 24px;
        }
        .card-header {
            padding: 20px 24px;
            background: #f8fafc;
            border-bottom: 1px solid #eef2f7;
        }
        .card-header h2 {
            font-size: 18px;
            font-weight: 700;
            color: #1d3156;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .card-header p {
            font-size: 13px;
            color: #64748b;
            margin-top: 4px;
        }

        .earnings-table {
            width: 100%;
            border-collapse: collapse;
        }
        .earnings-table th {
            text-align: left;
            padding: 14px 20px;
            background: #f8fafc;
            font-size: 12px;
            font-weight: 700;
            color: #1d3156;
            border-bottom: 2px solid #eef2f7;
        }
        .earnings-table td {
            padding: 14px 20px;
            font-size: 13px;
            color: #475569;
            border-bottom: 1px solid #eef2f7;
        }
        .earnings-table tr:hover td {
            background: #fafcff;
        }

        .positive { color: #28a745; font-weight: 700; }
        .negative { color: #dc2626; font-weight: 700; }
        .commission { color: #f59e0b; font-weight: 700; }

        .top-student-card {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 16px;
            border-bottom: 1px solid #eef2f7;
        }
        .top-student-card:last-child { border-bottom: none; }
        .rank-badge {
            width: 32px;
            height: 32px;
            background: #f1f5f9;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            font-size: 14px;
            color: #1d3156;
        }
        .rank-badge.gold { background: #fef3c7; color: #f59e0b; }
        .rank-badge.silver { background: #e2e8f0; color: #64748b; }
        .rank-badge.bronze { background: #fee2e2; color: #dc2626; }
        .badge-no-show { background: #fff3e0; color: #f59e0b; padding: 2px 8px; border-radius: 12px; font-size: 10px; display: inline-block; margin-left: 8px; }

        .empty-state {
            text-align: center;
            padding: 60px;
            background: white;
            border-radius: 24px;
            color: #94a3b8;
        }
        .empty-state i { font-size: 64px; margin-bottom: 16px; display: block; }

        .alert {
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
        .alert-warning {
            background: #fff3e0;
            color: #e67e22;
            border-left: 4px solid #f59e0b;
        }
        .alert-info {
            background: #d1ecf1;
            color: #0c5460;
            border-left: 4px solid #17a2b8;
        }

        .status-pending { background: #fef3c7; color: #f59e0b; padding: 2px 10px; border-radius: 20px; font-size: 11px; display: inline-block; }
        .status-approved { background: #d4edda; color: #28a745; padding: 2px 10px; border-radius: 20px; font-size: 11px; display: inline-block; }
        .status-completed { background: #d4edda; color: #28a745; padding: 2px 10px; border-radius: 20px; font-size: 11px; display: inline-block; }
        .status-rejected { background: #fee2e2; color: #dc2626; padding: 2px 10px; border-radius: 20px; font-size: 11px; display: inline-block; }

        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            display: none;
            align-items: center;
            justify-content: center;
        }
        .modal-overlay.active {
            display: flex;
        }
        .modal-container {
            background: white;
            border-radius: 24px;
            padding: 28px;
            width: 400px;
            max-width: 90%;
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            font-size: 13px;
            color: #1d3156;
        }
        .form-group input {
            width: 100%;
            padding: 12px;
            border: 1px solid #cbd5e1;
            border-radius: 12px;
            font-size: 14px;
        }
        .modal-buttons {
            display: flex;
            gap: 12px;
            justify-content: flex-end;
        }
        .btn-cancel {
            padding: 10px 20px;
            border-radius: 30px;
            border: 1px solid #cbd5e1;
            background: white;
            cursor: pointer;
            font-size: 13px;
            font-weight: 600;
        }
        .btn-save {
            padding: 10px 20px;
            border-radius: 30px;
            background: #28a745;
            color: white;
            border: none;
            cursor: pointer;
            font-size: 13px;
            font-weight: 600;
        }

        @media (max-width: 900px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .charts-grid { grid-template-columns: 1fr; }
            .earnings-table { display: block; overflow-x: auto; }
        }
    </style>
</head>
<body>

<header class="topbar">
    <div class="container">
        <nav class="nav">
            <a href="tutor_dashboard.php" class="brand">
                <img src="<?= e($assetBase) ?>/logo.png" alt="Kyoshi">
                <div><strong>Kyoshi</strong><span>Teacher Space</span></div>
            </a>
            <div class="nav-links">
                <a href="tutor_dashboard.php">Dashboard</a>
                <a href="booking_requests.php">My Bookings</a>
                <a href="material_overview.php">My Materials</a>
                <a href="assignment_overview.php">My Assignments</a>
                <a href="view_session_reports.php">My Reports</a>
            </div>
            <div style="position:relative;">
                <button class="profile" onclick="toggleDropdown()">
                    <img src="<?= e($profilePic) ?>">
                    <span><?= e($displayName) ?></span>
                    <i class="bi bi-chevron-down"></i>
                </button>
                <div class="dropdown" id="profileDropdown">
                    <a href="teacher_profile.php"><i class="bi bi-person-circle"></i> My Profile</a>
                    <a href="earnings.php" class="active"><i class="bi bi-wallet2"></i> My Earnings</a>
                    <hr>
                    <a href="logout.php" style="color:#dc2626;"><i class="bi bi-box-arrow-right"></i> Logout</a>
                </div>
            </div>
        </nav>
    </div>
</header>

<div class="main">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 32px;">
        <a href="tutor_dashboard.php" class="back-btn"><i class="bi bi-arrow-left"></i> Back</a>
        <div style="text-align: center;">
            <h1 style="font-size: 24px; font-weight: 800; color: #1d3156; margin: 0;"><i class="bi bi-wallet2"></i> My Earnings</h1>
            <p style="color: #1e293b; margin: 4px 0 0; font-size: 12px;">Track your earnings and request payouts</p>
        </div>
        <div style="width: 100px;"></div>
    </div>

    <?php if (isset($payoutMessage)): ?>
        <div class="alert alert-<?= $payoutMessageType ?>">
            <i class="bi bi-<?= $payoutMessageType === 'success' ? 'check-circle' : 'exclamation-circle' ?>"></i>
            <?= e($payoutMessage) ?>
        </div>
    <?php endif; ?>

    <?php if ($tutorNoShowCount > 0): ?>
        <div class="alert alert-info">
            <i class="bi bi-info-circle"></i>
            You have <?= $tutorNoShowCount ?> session(s) where you didn't attend. These sessions have been refunded to students and are not included in your earnings.
        </div>
    <?php endif; ?>

    <?php if ($studentNoShowCount > 0): ?>
        <div class="alert alert-info">
            <i class="bi bi-calendar-x"></i>
            You have <?= $studentNoShowCount ?> session(s) where the student didn't attend. You were still paid for your reserved time. No session reports needed for no-shows.
        </div>
    <?php endif; ?>

    <?php if (empty($verifiedBookings) && $totalNet == 0): ?>
        <div class="empty-state">
            <i class="bi bi-cash-stack"></i>
            <h3>No Verified Earnings Yet</h3>
            <p>Complete sessions and wait for payment verification to see your earnings.</p>
            <?php if ($pendingVerificationCount > 0): ?>
                <div style="margin-top: 16px; padding: 12px; background: #fef3c7; border-radius: 12px;">
                    <i class="bi bi-clock-history"></i> You have <strong><?= $pendingVerificationCount ?></strong> session(s) waiting for payment verification.
                </div>
            <?php endif; ?>
            <a href="booking_requests.php" style="display: inline-block; margin-top: 15px; padding: 10px 24px; background: linear-gradient(135deg, #E75A9B, #F28AB2); color: white; border-radius: 30px; text-decoration: none;">View Bookings</a>
        </div>
    <?php else: ?>

    <!-- Payout Section -->
    <div class="payout-card">
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 20px;">
            <div>
                <h3><i class="bi bi-cash"></i> Available for Payout</h3>
                <div class="payout-amount"><?= formatMoney($totalNet) ?></div>
                <p>After 20% platform commission | Minimum payout: RM50</p>
                <?php if ($pendingReportCount > 0): ?>
                    <p style="color: #fef3c7; background: rgba(0,0,0,0.2); padding: 8px 12px; border-radius: 12px; margin-top: 8px;">
                        <i class="bi bi-exclamation-triangle"></i> 
                        You have <?= $pendingReportCount ?> attended session(s) with payment verified but report not submitted.
                        Please submit all session reports before requesting payout.
                    </p>
                <?php endif; ?>
                <?php if ($pendingVerificationCount > 0): ?>
                    <p style="color: #fef3c7; background: rgba(0,0,0,0.2); padding: 8px 12px; border-radius: 12px; margin-top: 8px;">
                        <i class="bi bi-clock-history"></i> 
                        <?= $pendingVerificationCount ?> session(s) waiting for payment verification.
                    </p>
                <?php endif; ?>
            </div>
            <div>
                <button class="btn-payout <?= (!$canRequestPayout) ? 'disabled' : '' ?>" 
                        onclick="openPayoutModal(<?= $totalNet ?>)"
                        <?= (!$canRequestPayout) ? 'disabled' : '' ?>>
                    <i class="bi bi-cash-stack"></i> Request Payout
                </button>
            </div>
        </div>
    </div>

    <!-- Payout History -->
    <?php if (!empty($payoutHistory)): ?>
    <div class="glass-card">
        <div class="card-header">
            <h2><i class="bi bi-clock-history"></i> Payout History</h2>
            <p>Your previous withdrawal requests</p>
        </div>
        <table class="earnings-table">
            <thead>
                <tr>
                    <th>Date Requested</th>
                    <th>Amount</th>
                    <th>Status</th>
                    <th>Processed Date</th>
                    <th>Notes</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($payoutHistory as $payout): ?>
                <tr>
                    <td><?= date('d M Y', strtotime($payout['requested_at'])) ?></td>
                    <td class="positive"><?= formatMoney($payout['amount']) ?></td>
                    <td><span class="status-<?= $payout['status'] ?>"><?= ucfirst($payout['status']) ?></span></td>
                    <td><?= $payout['processed_at'] ? date('d M Y', strtotime($payout['processed_at'])) : '-' ?></td>
                    <td><?= e($payout['admin_notes'] ?? '-') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <!-- Charts Section -->
    <div class="charts-grid">
        <div class="chart-card">
            <div class="chart-title">
                <i class="bi bi-graph-up" style="color: #E75A9B;"></i>
                Monthly Earnings Trend
            </div>
            <div class="chart-container">
                <canvas id="monthlyEarningsChart"></canvas>
            </div>
        </div>
        <div class="chart-card">
            <div class="chart-title">
                <i class="bi bi-pie-chart" style="color: #E75A9B;"></i>
                Earnings by Language
            </div>
            <div class="chart-container">
                <canvas id="languagePieChart"></canvas>
            </div>
        </div>
    </div>

    <!-- Stats Cards -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon"><i class="bi bi-cash-stack"></i></div>
            <div class="stat-value"><?= formatMoney($totalGross) ?></div>
            <div class="stat-label">Total Gross Earnings</div>
            <div class="stat-sub">Before commission</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon"><i class="bi bi-percent"></i></div>
            <div class="stat-value"><?= formatMoney($totalCommission) ?></div>
            <div class="stat-label">Platform Commission (20%)</div>
            <div class="stat-sub">Automatically deducted</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon"><i class="bi bi-wallet2"></i></div>
            <div class="stat-value"><?= formatMoney($totalNet) ?></div>
            <div class="stat-label">Your Net Earnings</div>
            <div class="stat-sub"><?= $totalSessions ?> total sessions</div>
            <?php if ($studentNoShowCount > 0): ?>
                <div class="stat-info">
                    <i class="bi bi-calendar-x"></i> <?= $studentNoShowCount ?> student no-shows
                </div>
            <?php endif; ?>
            <?php if ($pendingReportCount > 0): ?>
                <div class="stat-warning">
                    <i class="bi bi-clock-history"></i> <?= $pendingReportCount ?> reports pending
                </div>
            <?php endif; ?>
        </div>
        <div class="stat-card">
            <div class="stat-icon"><i class="bi bi-calendar-check"></i></div>
            <div class="stat-value"><?= formatMoney($currentMonthEarnings['net']) ?></div>
            <div class="stat-label">This Month</div>
            <div class="stat-sub"><?= $currentMonthEarnings['count'] ?> sessions this month</div>
        </div>
    </div>

    <!-- Monthly Breakdown -->
    <div class="glass-card">
        <div class="card-header">
            <h2><i class="bi bi-bar-chart-steps"></i> Monthly Breakdown</h2>
            <p>Your earnings per month (after commission) - Includes attended sessions and student no-shows</p>
        </div>
        <table class="earnings-table">
            <thead>
                <tr>
                    <th>Month</th>
                    <th>Attended</th>
                    <th>Student Not Shows</th>
                    <th>Total Sessions</th>
                    <th>Gross</th>
                    <th>Commission (20%)</th>
                    <th>Net Earnings</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($monthlyEarnings as $month => $data): 
                    $monthName = date('F Y', strtotime($month . '-01'));
                ?>
                <tr>
                    <td><strong><?= e($monthName) ?></strong></td>
                    <td><?= $data['attended'] ?></td>
                    <td><?= $data['student_no_shows'] ?> <?php if ($data['student_no_shows'] > 0): ?><span class="badge-no-show">no report needed</span><?php endif; ?></td>
                    <td><?= $data['count'] ?></td>
                    <td><?= formatMoney($data['gross']) ?></td>
                    <td class="commission">- <?= formatMoney($data['commission']) ?></td>
                    <td class="positive"><?= formatMoney($data['net']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Two Column Layout -->
    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px;">
        <!-- Top Students -->
        <div class="glass-card">
            <div class="card-header">
                <h2><i class="bi bi-trophy"></i> Top Students</h2>
                <p>Students who generated the most earnings</p>
            </div>
            <div>
                <?php 
                $rank = 1;
                foreach ($topStudents as $studentName => $data): 
                    $rankClass = $rank == 1 ? 'gold' : ($rank == 2 ? 'silver' : ($rank == 3 ? 'bronze' : ''));
                ?>
                <div class="top-student-card">
                    <div class="rank-badge <?= $rankClass ?>"><?= $rank ?></div>
                    <div style="flex: 1;">
                        <strong><?= e($studentName) ?></strong>
                        <div style="font-size: 11px; color: #64748b;">
                            <?= $data['count'] ?> session(s) · <?= implode(', ', $data['languages']) ?>
                            <?php if ($data['no_shows'] > 0): ?>
                                <span class="badge-no-show"><?= $data['no_shows'] ?> no-show(s)</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div>
                        <strong class="positive"><?= formatMoney($data['total']) ?></strong>
                    </div>
                </div>
                <?php 
                    $rank++;
                    if ($rank > 5) break;
                endforeach; 
                ?>
            </div>
        </div>

        <!-- Earnings by Language Table -->
        <div class="glass-card">
            <div class="card-header">
                <h2><i class="bi bi-translate"></i> Earnings by Language</h2>
                <p>Breakdown of earnings by language taught</p>
            </div>
            <table class="earnings-table">
                <thead>
                    <tr>
                        <th>Language</th>
                        <th>Sessions</th>
                        <th>Total Earnings</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($languageEarnings as $language => $data): ?>
                    <tr>
                        <td><strong><?= e($language) ?></strong></td>
                        <td><?= $data['count'] ?></td>
                        <td class="positive"><?= formatMoney($data['total']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Recent Earnings Table -->
    <div class="glass-card" style="margin-top: 24px;">
        <div class="card-header">
            <h2><i class="bi bi-clock-history"></i> Recent Earnings</h2>
            <p>Transaction history - Includes both attended sessions and student no-shows</p>
        </div>
        <table class="earnings-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Student</th>
                    <th>Language</th>
                    <th>Session Fee</th>
                    <th>Commission (20%)</th>
                    <th>Your Earnings</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($verifiedBookings as $booking): 
                    $amount = floatval($booking['total_amount']);
                    $commission = $amount * $COMMISSION_RATE;
                    $net = $amount - $commission;
                    $isNoShow = ($booking['student_confirmed'] == 0 && $booking['no_show_type'] == 'student_no_show');
                ?>
                <tr>
                    <td><?= date('d M Y', strtotime($booking['booking_date'])) ?></td>
                    <td><?= e($booking['student_name']) ?> <?php if ($isNoShow): ?><span class="badge-no-show">No-Show</span><?php endif; ?></td>
                    <td><?= e($booking['language']) ?></td>
                    <td><?= formatMoney($amount) ?></td>
                    <td class="commission">- <?= formatMoney($commission) ?></td>
                    <td class="positive"><?= formatMoney($net) ?></td>
                    <td>
                        <?php if ($isNoShow): ?>
                            <span class="status-approved"><i class="bi bi-calendar-x"></i> No report needed</span>
                        <?php else: ?>
                            <span class="status-approved"><i class="bi bi-check-circle"></i> Report submitted</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Payout Modal -->
    <div id="payoutModal" class="modal-overlay">
        <div class="modal-container">
            <h3 style="margin-bottom: 16px;"><i class="bi bi-cash-stack"></i> Request Payout</h3>
            <form method="POST" action="">
                <div class="form-group">
                    <label>Enter Amount (RM)</label>
                    <input type="number" name="amount" id="payoutAmount" step="0.01" min="50" max="<?= $totalNet ?>" required>
                    <small style="font-size: 11px; color: #64748b;">Minimum: RM50 | Maximum: <?= formatMoney($totalNet) ?></small>
                </div>
                <div class="modal-buttons">
                    <button type="button" class="btn-cancel" onclick="closePayoutModal()">Cancel</button>
                    <button type="submit" name="request_payout" class="btn-save">Submit Request</button>
                </div>
            </form>
        </div>
    </div>

    <?php endif; ?>
</div>

<script>
function toggleDropdown() {
    const d = document.getElementById('profileDropdown');
    d.style.display = d.style.display === 'block' ? 'none' : 'block';
}

window.addEventListener('click', function(e) {
    const btn = document.querySelector('.profile');
    const dd = document.getElementById('profileDropdown');
    if (btn && dd && !btn.contains(e.target) && !dd.contains(e.target)) {
        dd.style.display = 'none';
    }
});

function openPayoutModal(maxAmount) {
    document.getElementById('payoutAmount').max = maxAmount;
    document.getElementById('payoutModal').classList.add('active');
}

function closePayoutModal() {
    document.getElementById('payoutModal').classList.remove('active');
}

// Monthly Earnings Chart
const monthlyCtx = document.getElementById('monthlyEarningsChart')?.getContext('2d');
if (monthlyCtx) {
    new Chart(monthlyCtx, {
        type: 'line',
        data: {
            labels: <?= json_encode($chartMonths) ?>,
            datasets: [{
                label: 'Net Earnings (RM)',
                data: <?= json_encode($chartNetEarnings) ?>,
                borderColor: '#E75A9B',
                backgroundColor: 'rgba(231, 90, 155, 0.1)',
                borderWidth: 3,
                fill: true,
                tension: 0.4,
                pointBackgroundColor: '#E75A9B',
                pointBorderColor: '#fff',
                pointBorderWidth: 2,
                pointRadius: 5,
                pointHoverRadius: 7
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            plugins: {
                legend: { position: 'top' },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            return 'RM ' + context.raw.toFixed(2);
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: function(value) {
                            return 'RM ' + value;
                        }
                    }
                }
            }
        }
    });
}

// Language Pie Chart
const pieCtx = document.getElementById('languagePieChart')?.getContext('2d');
if (pieCtx) {
    new Chart(pieCtx, {
        type: 'pie',
        data: {
            labels: <?= json_encode(array_keys($languageEarnings)) ?>,
            datasets: [{
                data: <?= json_encode(array_values(array_column($languageEarnings, 'total'))) ?>,
                backgroundColor: ['#E75A9B', '#F28AB2', '#A77BE8', '#7648B8', '#F59E0B', '#10B981', '#3B82F6', '#EF4444'],
                borderWidth: 0
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            plugins: {
                legend: { position: 'right' },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            const label = context.label || '';
                            const value = context.raw || 0;
                            const total = context.dataset.data.reduce((a, b) => a + b, 0);
                            const percentage = ((value / total) * 100).toFixed(1);
                            return `${label}: RM ${value.toFixed(2)} (${percentage}%)`;
                        }
                    }
                }
            }
        }
    });
}

// Close modal when clicking outside
window.onclick = function(event) {
    const modal = document.getElementById('payoutModal');
    if (event.target === modal) {
        closePayoutModal();
    }
}
</script>

</body>
</html>