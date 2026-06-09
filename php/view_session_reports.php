<?php
session_start();
include 'config.php';
include 'check_login.php';

$assetBase = '../assets/img';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$userID = $_SESSION['user_id'];
$role = $_SESSION['role'];
$booking_id = isset($_GET['booking_id']) ? intval($_GET['booking_id']) : 0;
$from_booking = isset($_GET['from_booking']) ? true : false;

// Get user info
$stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
$stmt->bind_param("i", $userID);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

$displayName = $user['fullname'];
$profilePic = !empty($user['profile_pic']) ? '../uploads/profiles/' . $user['profile_pic'] : $assetBase . '/profile-tutor.png';

// Get back URL based on where user came from
$backUrl = ($role === 'tutor') ? 'tutor_dashboard.php' : 'student_dashboard.php';
if ($from_booking && $booking_id > 0) {
    $backUrl = ($role === 'tutor') ? "tutor_booking_detail.php?id=$booking_id" : "booking_detail.php?id=$booking_id";
}

// Get tutor languages for filter (only for tutors)
$tutorLanguages = [];
if ($role === 'tutor') {
    $stmt = $conn->prepare("SELECT DISTINCT language FROM tutor_languages WHERE user_id = ?");
    $stmt->bind_param("i", $userID);
    $stmt->execute();
    $tutorLanguages = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

if ($role === 'tutor') {
    if ($booking_id > 0) {
        $stmt = $conn->prepare("
            SELECT sr.*, b.language, b.booking_date, b.booking_time, u.fullname as student_name
            FROM session_reports sr
            JOIN bookings b ON sr.booking_id = b.id
            JOIN users u ON sr.student_id = u.id
            WHERE sr.tutor_id = ? AND sr.booking_id = ?
            ORDER BY sr.session_date DESC
        ");
        $stmt->bind_param("ii", $userID, $booking_id);
    } else {
        $stmt = $conn->prepare("
            SELECT sr.*, b.language, b.booking_date, b.booking_time, u.fullname as student_name
            FROM session_reports sr
            JOIN bookings b ON sr.booking_id = b.id
            JOIN users u ON sr.student_id = u.id
            WHERE sr.tutor_id = ?
            ORDER BY sr.session_date DESC
        ");
        $stmt->bind_param("i", $userID);
    }
} else {
    // Students see ALL submitted, approved, and rejected reports (not drafts)
    if ($booking_id > 0) {
        $stmt = $conn->prepare("
            SELECT sr.*, b.language, b.booking_date, b.booking_time, u.fullname as tutor_name
            FROM session_reports sr
            JOIN bookings b ON sr.booking_id = b.id
            JOIN users u ON sr.tutor_id = u.id
            WHERE sr.student_id = ? AND sr.report_status IN ('submitted', 'approved', 'rejected') AND sr.booking_id = ?
            ORDER BY sr.session_date DESC
        ");
        $stmt->bind_param("ii", $userID, $booking_id);
    } else {
        $stmt = $conn->prepare("
            SELECT sr.*, b.language, b.booking_date, b.booking_time, u.fullname as tutor_name
            FROM session_reports sr
            JOIN bookings b ON sr.booking_id = b.id
            JOIN users u ON sr.tutor_id = u.id
            WHERE sr.student_id = ? AND sr.report_status IN ('submitted', 'approved', 'rejected')
            ORDER BY sr.session_date DESC
        ");
        $stmt->bind_param("i", $userID);
    }
}
$stmt->execute();
$reports = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);


$pendingReportCount = 0;
if ($role === 'tutor' && $booking_id === 0) {
        $pendingReports = $conn->query("
        SELECT COUNT(*) as count 
        FROM bookings b
        LEFT JOIN session_reports sr ON b.id = sr.booking_id
        LEFT JOIN session_completion sc ON b.id = sc.booking_id
        WHERE b.tutor_id = $userID 
        AND b.status = 'completed'
        AND (sr.id IS NULL OR sr.report_status = 'draft')
        AND (sc.student_confirmed IS NULL OR sc.student_confirmed = 1)
        AND (sc.no_show_type IS NULL OR sc.no_show_type != 'student_no_show')
    ");
   $pendingReportCount = $pendingReports->fetch_assoc()['count'];
}
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
    <title>Session Reports - Kyoshi</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../css/style.css">
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
            padding: 8px 16px;
            border-radius: 40px;
            text-decoration: none;
            font-weight: 600;
            font-size: 13px;
            border: 1px solid #e2e8f0;
            transition: 0.25s;
        }
        .back-btn:hover { background: #b8d0e9; border-color: #6b9cd7; transform: translateX(-3px); }
        .create-btn {
            background: #1d3156;
            color: white;
            border: none;
            padding: 8px 20px;
            border-radius: 40px;
            font-weight: 600;
            font-size: 13px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: 0.2s;
            text-decoration: none;
        }
        .create-btn:hover { background: #142544; transform: translateY(-2px); }
        .filter-bar {
            background: white;
            border-radius: 16px;
            padding: 14px 20px;
            margin-bottom: 24px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
            border: 1px solid #eef2f7;
        }
        .filter-row {
            display: flex;
            justify-content: center;
            gap: 15px;
            flex-wrap: wrap;
            align-items: flex-end;
        }
        .filter-group { min-width: 150px; }
        .filter-group label { display: block; font-size: 11px; font-weight: 600; margin-bottom: 4px; color: #1d3156; }
        .filter-group select, .filter-group input {
            width: 100%;
            padding: 8px 10px;
            border-radius: 10px;
            border: 1px solid #cbd5e1;
            background: white;
            font-family: 'Poppins', sans-serif;
            font-size: 12px;
        }
        .search-group input {
            padding-left: 32px;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='%2394a3b8' stroke-width='2'%3E%3Ccircle cx='11' cy='11' r='8'%3E%3C/circle%3E%3Cline x1='21' y1='21' x2='16.65' y2='16.65'%3E%3C/line%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: 10px center;
        }
        .btn-search, .btn-reset {
            padding: 8px 20px;
            border-radius: 10px;
            font-weight: 600;
            cursor: pointer;
            border: none;
            font-size: 12px;
        }
        .btn-search { background: #1d3156; color: white; }
        .btn-search:hover { background: #142544; }
        .btn-reset { background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0; }
        .btn-reset:hover { background: #e2e8f0; }
        .alert {
            padding: 10px 16px;
            border-radius: 12px;
            margin-bottom: 20px;
            font-weight: 500;
            font-size: 13px;
        }
        .alert-success { background: #d4edda; color: #155724; border-left: 4px solid #28a745; }
        .alert-error { background: #f8d7da; color: #721c24; border-left: 4px solid #dc2626; }
        .alert-warning { background: #fff3e0; color: #e67e22; border-left: 4px solid #f59e0b; }
        
        /* Compact Report Cards */
        .reports-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(380px, 1fr));
            gap: 16px;
        }
        .report-card {
            background: white;
            border-radius: 16px;
            overflow: hidden;
            border: 1px solid #eef2f7;
            transition: all 0.3s ease;
        }
        .report-card:hover {
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            transform: translateY(-2px);
        }
        .report-header {
            padding: 12px 16px;
            background: #f8fafc;
            border-bottom: 1px solid #eef2f7;
        }
        .report-header-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 6px;
        }
        .report-title {
            font-size: 14px;
            font-weight: 700;
            color: #1d3156;
        }
        .report-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 2px 8px;
            border-radius: 20px;
            font-size: 10px;
            font-weight: 600;
        }
        /* Additional status badges */
        .badge-approved { background: #d4edda; color: #28a745; }
        .badge-rejected { background: #f8d7da; color: #dc2626; }
        .badge-submitted { background: #cfe2ff; color: #084298; }
        .badge-draft { background: #d7d5ce; color: #fcebcd; }
        .badge-attended { background: #d4edda; color: #28a745; }
        .badge-late { background: #fff3e0; color: #f59e0b; }
        .badge-absent { background: #fee2e2; color: #dc2626; }
        .report-info {
            display: flex;
            gap: 12px;
            font-size: 10px;
            color: #64748b;
            flex-wrap: wrap;
        }
        .report-content {
            padding: 12px 16px;
        }
        .card-actions {
    padding: 8px 16px 12px 16px;
    display: flex;
    gap: 8px;
    justify-content: center;  /* ← Change flex-start to center */
    flex-wrap: wrap;
}
        .card-actions a,
        .card-actions button {
            white-space: nowrap;
            font-size: 12px;
            padding: 6px 16px;
        }
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            background: white;
            border-radius: 24px;
            color: #94a3b8;
        }
        .empty-state i { font-size: 48px; margin-bottom: 12px; display: block; color: #cbd5e1; }
        .pending-warning {
            background: #fff3e0;
            border-left: 4px solid #f59e0b;
            padding: 12px 16px;
            margin-bottom: 20px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
        }
        .toast-notification {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: #1d3156;
            color: white;
            padding: 10px 20px;
            border-radius: 12px;
            font-size: 12px;
            z-index: 9999;
            animation: slideIn 0.3s ease;
        }
        @keyframes slideIn {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        @media (max-width: 900px) {
            .filter-row { flex-direction: column; align-items: stretch; }
            .reports-grid { grid-template-columns: 1fr; }
        }
        @media (max-width: 768px) {
            .card-actions { flex-direction: column; }
            .card-actions a,
            .card-actions button { white-space: normal; text-align: center; justify-content: center; }
        }
        /* Fix header alignment on mobile - Session Reports */
@media (max-width: 900px) {
    /* Header layout - keep everything on one line */
    .main > div:first-child {
        display: flex !important;
        flex-direction: row !important;
        justify-content: space-between !important;
        align-items: center !important;
        gap: 8px !important;
        margin-bottom: 20px !important;
        flex-wrap: nowrap !important;
    }
    
    /* Back button - LEFT side */
    .main > div:first-child .back-btn {
        order: 0 !important;
        flex-shrink: 0 !important;
        padding: 6px 10px !important;
        font-size: 12px !important;
    }
    
    .main > div:first-child .back-btn i {
        font-size: 14px;
    }
    
    .main > div:first-child .back-btn span {
        display: none !important;
    }
    
    /* Title - CENTER */
    .main > div:first-child > div:first-child {
        order: 1 !important;
        position: static !important;
        left: auto !important;
        transform: none !important;
        flex: 1 !important;
        text-align: center !important;
        min-width: 0 !important;
        width: auto !important;
    }
    
    .main > div:first-child h1 {
        font-size: 16px !important;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    
    .main > div:first-child p {
        display: none !important;
    }
    
    /* Create button - RIGHT side */
    .main > div:first-child .create-btn {
        order: 2 !important;
        flex-shrink: 0 !important;
        padding: 6px 10px !important;
        font-size: 12px !important;
    }
    
    .main > div:first-child .create-btn i {
        font-size: 14px;
    }
    
    .main > div:first-child .create-btn span {
        display: none !important;
    }
    
    /* Empty spacer div */
    .main > div:first-child > div:last-child {
        display: none !important;
    }
    
    /* Filter bar - stack vertically */
    .filter-row {
        flex-direction: column !important;
        align-items: stretch !important;
    }
    
    .filter-group {
        width: 100% !important;
    }
    
    .btn-search, .btn-reset {
        width: 100% !important;
        justify-content: center !important;
        margin-top: 5px;
    }
    
    /* Report cards - full width */
    .reports-grid {
        grid-template-columns: 1fr !important;
    }
    
    /* Report card actions - stack */
    .card-actions {
        flex-direction: column !important;
    }
    
    .card-actions a,
    .card-actions button {
        width: 100% !important;
        justify-content: center !important;
        text-align: center !important;
    }
}
    </style>
</head>
<body>

<header class="topbar">
    <div class="container">
        <nav class="nav">
            <button class="hamburger-menu" id="hamburgerBtn">
                <i class="bi bi-list"></i>
            </button>

            <a href="<?= $role === 'tutor' ? 'tutor_dashboard.php' : 'student_dashboard.php' ?>" class="brand">
                <img src="<?= e($assetBase) ?>/logo.png" alt="Kyoshi">
                <div><strong>Kyoshi</strong><span><?= $role === 'tutor' ? 'Teacher Space' : 'Student Learning Space' ?></span></div>
            </a>
            <div class="nav-links">
                <?php if ($role === 'tutor'): ?>
                    <a href="tutor_dashboard.php">Dashboard</a>
                    <a href="booking_requests.php">My Bookings</a>
                    <a href="material_overview.php">My Materials</a>
                    <a href="assignment_overview.php">My Assignments</a>
                    <a href="view_session_reports.php" class="active">My Reports</a>
                <?php else: ?>
                    <a href="student_dashboard.php">Home</a>
                    <a href="find_language.php">Find Language</a>
                    <a href="booking_status.php">My Bookings</a>
                    <a href="my_payments.php">My Payments</a>
                    <a href="my_materials.php">My Materials</a>
                    <a href="view_session_reports.php" class="active">My Reports</a>
                <?php endif; ?>
            </div>
            <div class="nav-actions">
            <div style="position:relative;">
                <button class="profile" onclick="toggleDropdown()">
                    <img src="<?= e($profilePic) ?>">
                    <span><?= e($displayName) ?></span>
                    <i class="bi bi-chevron-down"></i>
                </button>
                <div class="dropdown" id="profileDropdown">
                    <a href="<?= $role === 'tutor' ? 'teacher_profile.php' : 'student_profile.php' ?>"><i class="bi bi-person-circle"></i> My Profile</a>
                    <?php if ($role === 'tutor'): ?>
                        <a href="earnings.php"><i class="bi bi-wallet2"></i> My Earnings</a>
                    <?php else: ?>
                        <a href="my_progress.php"><i class="bi bi-bar-chart-steps"></i> My Progress</a>
                        <a href="student_favourites.php"><i class="bi bi-heart"></i> My Favourites</a>
                    <?php endif; ?>
                    <hr>
                    <a href="logout.php" style="color:#dc2626;"><i class="bi bi-box-arrow-right"></i> Logout</a>
                </div>
            </div>
            </div>
        </nav>
    </div>
</header>
  <div class="nav-overlay" id="navOverlay"></div>
<div class="main">
<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 32px; gap: 16px;">
    <a href="<?= $backUrl ?>" class="back-btn">
        <i class="bi bi-arrow-left"></i> <span>Back</span>
    </a>
    
    <div style="text-align: center; flex: 1;">
        <h1 style="font-size: 24px; font-weight: 800; color: #1d3156; margin: 0;">
            Session Reports
        </h1>
        <p style="color: #1e293b; margin: 4px 0 0; font-size: 12px;">
            <?= $role === 'tutor' ? 'Track and document lesson progress for your students' : 'Review your session reports and progress summaries from your tutors' ?>
        </p>
    </div>
    
    <?php if ($role === 'tutor' && $booking_id === 0): ?>
        <a href="submit_session_report.php" class="create-btn">
            <i class="bi bi-plus-lg"></i> <span>Create Report</span>
        </a>
    <?php else: ?>
        <div style="width: 80px;"></div>
    <?php endif; ?>
</div>

    <?php if ($role === 'tutor' && $pendingReportCount > 0 && $booking_id === 0): ?>
    <div class="pending-warning" id="reportAlert">
        <div style="display: flex; align-items: center; gap: 12px; flex: 1;">
            <i class="bi bi-exclamation-triangle-fill" style="color: #f59e0b; font-size: 24px;"></i>
            <div>
                <strong style="color: #92400e;">Action Required: <?= $pendingReportCount ?> Session Report<?= $pendingReportCount != 1 ? 's' : '' ?> Pending</strong>
                <p style="margin: 4px 0 0; font-size: 13px; color: #b45309;">Payment will only be released after you submit AND admin verifies session reports.</p>
            </div>
        </div>
        <div style="display: flex; align-items: center; gap: 12px;">
            <a href="submit_session_report.php" style="background: #f59e0b; color: white; padding: 10px 24px; border-radius: 30px; text-decoration: none; font-weight: 600; font-size: 13px;">
                Submit Reports Now
            </a>
            <button onclick="dismissAlert('reportAlert')" style="background: none; border: none; font-size: 20px; cursor: pointer; color: #92400e; width: 32px; height: 32px; display: flex; align-items: center; justify-content: center; border-radius: 50%; transition: 0.2s;">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>
    </div>
<?php endif; ?>
    <?php if ($role === 'tutor' && $booking_id > 0): ?>
        <div class="alert alert-info" style="background: #e0f2fe; color: #0369a1; border-left: 4px solid #0284c7;">
            <i class="bi bi-info-circle"></i>
            Showing reports for this specific booking. <a href="view_session_reports.php" style="color: #0369a1; text-decoration: underline;">View all reports →</a>
        </div>
    <?php endif; ?>

    <div class="filter-bar">
    <div class="filter-row">
        <div class="filter-group search-group">
            <label>Search</label>
            <input type="text" id="searchInput" placeholder="Search by student, language, or lesson...">
        </div>
        <?php if ($role === 'tutor' && $booking_id === 0): ?>
        <div class="filter-group">
            <label>Language</label>
            <select id="languageFilter">
                <option value="all">All Languages</option>
                <?php foreach ($tutorLanguages as $lang): ?>
                    <option value="<?= e($lang['language']) ?>"><?= e($lang['language']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
        <div class="filter-group">
            <label>Attendance</label>
            <select id="attendanceFilter">
                <option value="all">All</option>
                <option value="attended">Attended</option>
                <option value="late">Late</option>
                <option value="absent">Absent</option>
            </select>
        </div>
        <!-- Status Filter for report_status -->
        <div class="filter-group">
            <label>Report Status</label>
            <select id="statusFilter">
                <option value="all">All Reports</option>
                <option value="submitted">Submitted Only</option>
                <?php if ($role === 'tutor'): ?>
                    <option value="draft">Draft Only</option>
                <?php endif; ?>
                <option value="approved">Approved</option>
                <option value="rejected">Rejected</option>
            </select>
        </div>
        <div class="filter-group">
            <label>Sort By</label>
            <select id="sortBy">
                <option value="latest">Latest First</option>
                <option value="oldest">Oldest First</option>
                <option value="newest_submitted">Recently Submitted</option>
            </select>
        </div>
        <div><button class="btn-search" onclick="applyManualFilters()"><i class="bi bi-funnel"></i> Apply</button></div>
<div><button class="btn-reset" onclick="resetFilters()"><i class="bi bi-arrow-counterclockwise"></i> Reset</button></div>
    </div>
</div>

    <div id="reportsContainer"></div>
</div>
<script>
const allReports = <?= json_encode($reports) ?>;
const userRole = '<?= $role ?>';
const tutorLanguages = <?= json_encode($tutorLanguages) ?>;
const bookingId = <?= $booking_id ?>;

// Store current filter values
let currentLanguageFilter = 'all';
let currentAttendanceFilter = 'all';
let currentStatusFilter = 'all';
let currentSortBy = 'latest';
let currentSearchTerm = '';

function showToast(message, color) {
    const existing = document.querySelector('.toast-notification');
    if (existing) existing.remove();
    const toast = document.createElement('div');
    toast.className = 'toast-notification';
    toast.style.backgroundColor = color;
    toast.innerHTML = `<i class="bi bi-info-circle"></i> ${message}`;
    document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 3000);
}

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

function escapeHtml(str) {
    if (!str) return '';
    return str.replace(/[&<>]/g, function(m) {
        if (m === '&') return '&amp;';
        if (m === '<') return '&lt;';
        if (m === '>') return '&gt;';
        return m;
    });
}

function formatDate(dateString) {
    if (!dateString) return 'N/A';
    try {
        let date;
        if (dateString.includes('-') && dateString.includes(':')) {
            date = new Date(dateString.replace(' ', 'T'));
        } else {
            date = new Date(dateString);
        }
        if (isNaN(date.getTime())) {
            return 'Invalid Date';
        }
        return date.toLocaleDateString('en-US', { 
            month: 'short', 
            day: 'numeric', 
            year: 'numeric' 
        });
    } catch(e) {
        return dateString;
    }
}

function formatTime(timeString) {
    if (!timeString) return 'N/A';
    try {
        let time;
        if (timeString.length <= 8 && timeString.includes(':')) {
            const parts = timeString.split(':');
            time = new Date();
            time.setHours(parseInt(parts[0]), parseInt(parts[1]), 0);
        } else {
            let dateTime;
            if (timeString.includes('-') && timeString.includes(':')) {
                dateTime = new Date(timeString.replace(' ', 'T'));
            } else {
                dateTime = new Date(timeString);
            }
            time = dateTime;
        }
        if (isNaN(time.getTime())) {
            return timeString.substring(0, 5);
        }
        return time.toLocaleTimeString('en-US', { 
            hour: '2-digit', 
            minute: '2-digit',
            hour12: true 
        });
    } catch(e) {
        return timeString;
    }
}

function getBadgeClass(status) {
    switch(status) {
        case 'attended': return 'badge-attended';
        case 'late': return 'badge-late';
        case 'absent': return 'badge-absent';
        default: return 'badge-attended';
    }
}

function getBadgeText(status) {
    switch(status) {
        case 'attended': return 'Attended';
        case 'late': return 'Late';
        case 'absent': return 'Absent';
        default: return 'Attended';
    }
}

function submitReport(bookingId) {
    if (confirm('Submit this report? The student will be notified and can view it. You cannot edit after submission.')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = 'submit_session_report.php?booking_id=' + bookingId;
        
        const actionInput = document.createElement('input');
        actionInput.type = 'hidden';
        actionInput.name = 'action';
        actionInput.value = 'submitted';
        
        const report = allReports.find(r => r.booking_id == bookingId);
        if (report) {
            const fields = ['lesson_summary', 'student_progress', 'topics_covered', 'homework_given', 'tutor_notes', 'materials_used', 'next_session_focus', 'attendance_status'];
            fields.forEach(field => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = field;
                input.value = report[field] || '';
                form.appendChild(input);
            });
        }
        
        form.appendChild(actionInput);
        document.body.appendChild(form);
        form.submit();
    }
}function getReportStatusBadge(status) {
    switch(status) {
        case 'approved':
            return '<span class="report-badge" style="background: #d4edda; color: #28a745;"><i class="bi bi-check-circle-fill"></i> Approved</span>';
        case 'rejected':
            return '<span class="report-badge" style="background: #f8d7da; color: #dc2626;"><i class="bi bi-x-circle-fill"></i> Rejected</span>';
        case 'submitted':
            return '<span class="report-badge" style="background: #cfe2ff; color: #084298;"><i class="bi bi-send"></i> Submitted (Pending)</span>';
        case 'draft':
            return '<span class="report-badge" style="background: #fef3c7; color: #f59e0b;"><i class="bi bi-pencil"></i> Draft</span>';
        default:
            return '';
    }
}

function getAdminNotesHtml(adminNotes) {
    if (adminNotes && adminNotes.trim() !== '') {
        return `
            <div style="margin-top: 8px; padding: 8px; background: #fef3c7; border-radius: 8px; border-left: 3px solid #f59e0b;">
                <strong style="color: #92400e; font-size: 10px;"><i class="bi bi-chat"></i> Admin Note:</strong>
                <p style="color: #b45309; font-size: 10px; margin-top: 2px;">${escapeHtml(adminNotes)}</p>
            </div>
        `;
    }
    return '';
}

function renderReports(reports) {
    const container = document.getElementById('reportsContainer');
    
    if (!reports || reports.length === 0) {
        container.innerHTML = '<div class="empty-state"><i class="bi bi-journal-x"></i><p>No session reports found</p><small>Try changing your search filters</small></div>';
        return;
    }
    
    let html = '<div class="reports-grid">';
    for (const report of reports) {
        const badgeClass = getBadgeClass(report.attendance_status);
        const badgeText = getBadgeText(report.attendance_status);
        const personName = userRole === 'tutor' ? report.student_name : (report.tutor_name || 'Tutor');
        
        const sessionDate = formatDate(report.session_date);
        const sessionTime = formatTime(report.session_time);
        const submittedDate = report.submitted_at ? formatDate(report.submitted_at) : 'Not submitted';
        
        const reportStatusBadge = getReportStatusBadge(report.report_status);
        const adminNotesHtml = getAdminNotesHtml(report.admin_notes);
        
        const rejectionNoteHtml = (report.report_status === 'rejected' && report.admin_notes) ? `
            <div style="margin-top: 8px; padding: 12px; background: #fee2e2; border-radius: 8px; border-left: 4px solid #dc2626;">
                <strong style="color: #991b1b; font-size: 12px;"><i class="bi bi-exclamation-triangle-fill"></i> Report Rejected:</strong>
                <p style="color: #7f1d1d; font-size: 11px; margin-top: 6px; margin-bottom: 0;">${escapeHtml(report.admin_notes)}</p>
                <p style="color: #991b1b; font-size: 10px; margin-top: 6px; margin-bottom: 0;">
                    <i class="bi bi-arrow-repeat"></i> Please edit and resubmit your report.
                </p>
            </div>
        ` : '';
        
        let lessonPreview = '';
        if (report.lesson_summary && report.lesson_summary.trim() !== '') {
            let summary = report.lesson_summary;
            if (summary.length > 80) {
                summary = summary.substring(0, 80) + '...';
            }
            lessonPreview = `
                <div style="margin-top: 8px; padding: 8px; background: #f8fafc; border-radius: 8px;">
                    <strong style="color: #1d3156; font-size: 10px;"><i class="bi bi-chat-text"></i> Lesson Summary:</strong>
                    <p style="color: #475569; font-size: 11px; margin-top: 4px; line-height: 1.4;">${escapeHtml(summary)}</p>
                </div>
            `;
        } else if (report.report_status === 'draft') {
            lessonPreview = `
                <div style="margin-top: 8px; padding: 8px; background: #fef3c7; border-radius: 8px; border-left: 3px solid #f59e0b;">
                    <strong style="color: #92400e; font-size: 10px;"><i class="bi bi-exclamation-triangle"></i> No Lesson Summary Yet</strong>
                    <p style="color: #b45309; font-size: 10px; margin-top: 2px;">You haven't added a lesson summary yet. Click "Continue Editing" to add details to submit.</p>
                </div>
            `;
        }

        let actionButtons = '';
if (userRole === 'tutor') {
    if (report.report_status === 'draft') {
        actionButtons = `
            <div class="card-actions" style="justify-content: center;">
                <a href="submit_session_report.php?booking_id=${report.booking_id}" class="btn-edit" style="background: linear-gradient(135deg, #E75A9B, #F28AB2); color: white; padding: 6px 16px; border-radius: 30px; text-decoration: none; font-size: 12px; font-weight: 600; display: inline-flex; align-items: center; gap: 6px;">
                    <i class="bi bi-pencil-square"></i> Continue Editing
                </a>
            </div>
        `;
    } else if (report.report_status === 'rejected') {
        // For rejected reports - allow resubmission
        actionButtons = `
            <div class="card-actions" style="justify-content: center;">
                <a href="view_report_detail.php?report_id=${report.id}&booking_id=${report.booking_id}" class="btn-view" style="background: #1d3156; color: white; padding: 6px 16px; border-radius: 30px; text-decoration: none; font-size: 12px; font-weight: 600; display: inline-flex; align-items: center; gap: 6px; margin-right: 8px;">
                    <i class="bi bi-eye"></i> View Rejection Reason
                </a>
                <a href="submit_session_report.php?booking_id=${report.booking_id}&resubmit=1&report_id=${report.id}" class="btn-edit" style="background: linear-gradient(135deg, #E75A9B, #F28AB2); color: white; padding: 6px 16px; border-radius: 30px; text-decoration: none; font-size: 12px; font-weight: 600; display: inline-flex; align-items: center; gap: 6px;">
                    <i class="bi bi-arrow-repeat"></i> Edit & Resubmit
                </a>
            </div>
        `;
    } else {
        // For submitted, approved - just view
        actionButtons = `
            <div class="card-actions" style="justify-content: center;">
                <a href="view_report_detail.php?report_id=${report.id}&booking_id=${report.booking_id}" class="btn-view" style="background: #1d3156; color: white; padding: 6px 16px; border-radius: 30px; text-decoration: none; font-size: 12px; font-weight: 600; display: inline-flex; align-items: center; gap: 6px;">
                    <i class="bi bi-eye"></i> View Full Report
                </a>
            </div>
        `;
    }
}
        
        html += `
            <div class="report-card">
                <div class="report-header">
                    <div class="report-header-top">
                        <div class="report-title">
                            <i class="bi bi-journal-bookmark"></i> ${escapeHtml(report.language)} Session
                        </div>
                        <div style="display: flex; gap: 6px; flex-wrap: wrap;">
                            <span class="report-badge ${badgeClass}">${badgeText}</span>
                            ${reportStatusBadge}
                        </div>
                    </div>
                    <div class="report-info">
                        <span><i class="bi bi-calendar3"></i> ${sessionDate}</span>
                        <span><i class="bi bi-clock"></i> ${sessionTime}</span>
                        <span><i class="bi bi-person"></i> ${escapeHtml(personName)}</span>
                        ${report.submitted_at ? `<span><i class="bi bi-calendar-check"></i> ${submittedDate}</span>` : '<span><i class="bi bi-clock-history"></i> Not Submitted Yet</span>'}
                    </div>
                </div>
                <div class="report-content">
                    ${lessonPreview}
                    ${adminNotesHtml}
                    ${rejectionNoteHtml}
                </div>
                ${actionButtons}
            </div>
        `;
    }
    html += '</div>';
    container.innerHTML = html;
}

// Update the filter application to handle new statuses
function applyManualFilters() {
    // Get values from dropdowns
    if (document.getElementById('languageFilter')) {
        currentLanguageFilter = document.getElementById('languageFilter').value;
    }
    currentAttendanceFilter = document.getElementById('attendanceFilter').value;
    currentStatusFilter = document.getElementById('statusFilter').value;
    currentSortBy = document.getElementById('sortBy').value;
    
    applyAllFilters();
    showToast('Filters applied', '#28a745');
}

// Update the filter logic in applyAllFilters
function applyAllFilters() {
    let filtered = [...allReports];
    
    // Apply search filter
    if (currentSearchTerm) {
        filtered = filtered.filter(r => {
            const studentName = (r.student_name || '').toLowerCase();
            const tutorName = (r.tutor_name || '').toLowerCase();
            const language = (r.language || '').toLowerCase();
            
            return studentName.includes(currentSearchTerm) || 
                   tutorName.includes(currentSearchTerm) ||
                   language.includes(currentSearchTerm);
        });
    }
    
    // Apply language filter
    if (currentLanguageFilter && currentLanguageFilter !== 'all') {
        filtered = filtered.filter(r => r.language === currentLanguageFilter);
    }
    
    // Apply attendance filter
    if (currentAttendanceFilter !== 'all') {
        filtered = filtered.filter(r => r.attendance_status === currentAttendanceFilter);
    }
    
    // Apply status filter - UPDATED for new statuses
    if (currentStatusFilter !== 'all') {
        filtered = filtered.filter(r => r.report_status === currentStatusFilter);
    }
    
    // Apply sort
    if (currentSortBy === 'latest') {
        filtered.sort((a, b) => new Date(b.session_date) - new Date(a.session_date));
    } else if (currentSortBy === 'oldest') {
        filtered.sort((a, b) => new Date(a.session_date) - new Date(b.session_date));
    } else if (currentSortBy === 'newest_submitted') {
        filtered.sort((a, b) => new Date(b.submitted_at || 0) - new Date(a.submitted_at || 0));
    }
    
    renderReports(filtered);
}

function dismissAlert(alertId) {
    const alert = document.getElementById(alertId);
    if (alert) {
        alert.style.transition = 'opacity 0.3s ease';
        alert.style.opacity = '0';
        setTimeout(() => {
            alert.style.display = 'none';
        }, 300);
    }
}

// Automatic search - triggered when user types
function onSearchInput() {
    currentSearchTerm = document.getElementById('searchInput').value.toLowerCase().trim();
    applyAllFilters(); // Re-apply all filters (search + manual)
}

// Manual Apply button - for non-search filters
function applyManualFilters() {
    // Get values from dropdowns
    currentLanguageFilter = document.getElementById('languageFilter')?.value || 'all';
    currentAttendanceFilter = document.getElementById('attendanceFilter').value;
    currentStatusFilter = document.getElementById('statusFilter')?.value || 'all';
    currentSortBy = document.getElementById('sortBy').value;
    
    // Check if any manual filter is selected (excluding search)
    const hasLanguageFilter = currentLanguageFilter !== 'all';
    const hasAttendanceFilter = currentAttendanceFilter !== 'all';
    const hasStatusFilter = currentStatusFilter !== 'all';
    const hasSortChange = currentSortBy !== 'latest';
    
    // If no filters selected and no search term, show toast
    if (!hasLanguageFilter && !hasAttendanceFilter && !hasStatusFilter && !hasSortChange && !currentSearchTerm) {
        showToast('Please select at least one filter (Language, Attendance, Report Status, or Sort By)', '#f59e0b');
        return;
    }
    
    // Apply all filters
    applyAllFilters();
    
    // Show success message
    if (hasLanguageFilter || hasAttendanceFilter || hasStatusFilter || hasSortChange) {
        showToast('Filters applied', '#28a745');
    }
}

// Reset all filters
function resetFilters() {
    // Reset search
    document.getElementById('searchInput').value = '';
    currentSearchTerm = '';
    
    // Reset dropdowns
    if (document.getElementById('languageFilter')) {
        document.getElementById('languageFilter').value = 'all';
        currentLanguageFilter = 'all';
    }
    document.getElementById('attendanceFilter').value = 'all';
    currentAttendanceFilter = 'all';
    if (document.getElementById('statusFilter')) {
        document.getElementById('statusFilter').value = 'all';
        currentStatusFilter = 'all';
    }
    document.getElementById('sortBy').value = 'latest';
    currentSortBy = 'latest';
    
    // Apply reset (show all reports)
    applyAllFilters();
    showToast('All filters cleared', '#64748b');
}

// Initialize
document.addEventListener('DOMContentLoaded', function() {
    renderReports(allReports);
    
    // Setup AUTOMATIC search (triggers as you type)
    const searchInput = document.getElementById('searchInput');
    if (searchInput) {
        searchInput.addEventListener('input', onSearchInput);
    }
    
    // Note: Dropdown filters are MANUAL - only work when Apply button is clicked
});

// Auto-hide alerts after 4 seconds
setTimeout(function() {
    const alerts = document.querySelectorAll('.alert-success, .alert-error, .alert-warning');
    alerts.forEach(alert => {
        if (alert.style.transition !== 'opacity 0.5s') {
            alert.style.transition = 'opacity 0.5s';
            alert.style.opacity = '0';
            setTimeout(() => alert.remove(), 500);
        }
    });
}, 4000);
</script>
<script src="../js/nav.js"></script>
<script>
history.pushState(null, null, location.href);
window.addEventListener('popstate', function() {
    window.location.href = 'login.php';
});
</script>
</body>
</html>