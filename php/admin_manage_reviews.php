<?php
session_start();
include 'config.php';
// Track if admin has viewed the reviews page
if (!isset($_SESSION['reviews_viewed'])) {
    $_SESSION['reviews_viewed'] = false;
}

// When viewing the manage reviews page, mark as viewed
if (basename($_SERVER['PHP_SELF']) == 'admin_manage_reviews.php') {
    $_SESSION['reviews_viewed'] = true;
}

// Get total reviews count
$totalReviews = $conn->query("SELECT COUNT(*) as count FROM ratings")->fetch_assoc()['count'];

// Get count of NEW reviews since last view
$lastViewTime = $_SESSION['last_reviews_view_time'] ?? date('Y-m-d H:i:s', strtotime('-1 day'));
$newReviewsCount = $conn->query("
    SELECT COUNT(*) as count FROM ratings WHERE created_at > '$lastViewTime'
")->fetch_assoc()['count'];

// Update last view time when on the manage reviews page
if (basename($_SERVER['PHP_SELF']) == 'admin_manage_reviews.php') {
    $_SESSION['last_reviews_view_time'] = date('Y-m-d H:i:s');
}
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

// Delete review
if (isset($_GET['delete_id'])) {
    $deleteId = intval($_GET['delete_id']);
    $deleteStmt = $conn->prepare("DELETE FROM ratings WHERE id = ?");
    $deleteStmt->bind_param("i", $deleteId);
    if ($deleteStmt->execute()) {
        $_SESSION['success_message'] = "Review deleted successfully!";
        header("Location: admin_manage_reviews.php");
        exit();
    }
}

// Get filter parameters
$search = $_GET['search'] ?? '';
$sort_by = $_GET['sort'] ?? 'newest';
$language_filter = $_GET['language'] ?? '';

// Build query
$sql = "
    SELECT r.*, 
           r.rating,
           r.comment,
           r.is_anonymous,
           r.created_at,
           u.fullname as tutor_name,
           u.email as tutor_email,
           u.profile_pic as tutor_pic,
           s.fullname as student_name,
           b.language,
           b.booking_date
    FROM ratings r
    JOIN users u ON r.tutor_id = u.id
    JOIN users s ON r.student_id = s.id
    JOIN bookings b ON r.booking_id = b.id
    WHERE 1=1
";

if (!empty($search)) {
    $search_like = $conn->real_escape_string($search);
    $sql .= " AND (u.fullname LIKE '%$search_like%' OR s.fullname LIKE '%$search_like%')";
}

if (!empty($language_filter)) {
    $sql .= " AND b.language = '" . $conn->real_escape_string($language_filter) . "'";
}

// Sorting
switch ($sort_by) {
    case 'rating_high':
        $sql .= " ORDER BY r.rating DESC";
        break;
    case 'rating_low':
        $sql .= " ORDER BY r.rating ASC";
        break;
    case 'language_az':
        $sql .= " ORDER BY b.language ASC";
        break;
    case 'language_za':
        $sql .= " ORDER BY b.language DESC";
        break;
    case 'oldest':
        $sql .= " ORDER BY r.created_at ASC";
        break;
    case 'newest':
    default:
        $sql .= " ORDER BY r.created_at DESC";
        break;
}

$reviews = $conn->query($sql);

// Get languages for filter dropdown
$languages = $conn->query("SELECT DISTINCT language FROM bookings ORDER BY language");

// Get counts for sidebar
$totalTutors = $conn->query("SELECT COUNT(*) as count FROM users WHERE role = 'tutor'")->fetch_assoc()['count'];
$totalStudents = $conn->query("SELECT COUNT(*) as count FROM users WHERE role = 'student'")->fetch_assoc()['count'];
$pendingPayments = $conn->query("SELECT COUNT(*) as count FROM payments WHERE status = 'pending'")->fetch_assoc()['count'];
$pendingDisputes = $conn->query("SELECT COUNT(*) as count FROM disputes WHERE status = 'pending'")->fetch_assoc()['count'];
$pendingPayouts = $conn->query("SELECT COUNT(*) as count FROM payout_requests WHERE status = 'pending'")->fetch_assoc()['count'] ?? 0;
$pendingTutors = $conn->query("SELECT COUNT(*) as count FROM users WHERE role = 'tutor' AND status = 'pending'")->fetch_assoc()['count'];
$totalBookings = $conn->query("SELECT COUNT(*) as count FROM bookings")->fetch_assoc()['count'];

function e($value) {
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kyoshi | Manage Reviews</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600&family=Open+Sans&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    
    body {
        font-family: "Montserrat", "Open Sans", sans-serif;
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
    
    .sidebar.closed {
        transform: translateX(-100%);
    }
    
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
    
    .nav-section-label i {
        font-size: 0.7rem;
        color: #B26EA7;
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
    
    .admin-role {
        font-size: 0.6rem;
        color: #B26EA7;
        margin-top: 2px;
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

    /* ========== MAIN CONTENT ========== */
    .main-content {
        margin-left: 230px;
        padding: 20px 24px;
        transition: margin-left 0.3s ease;
        height: 100vh;
        overflow-y: auto;
        scroll-behavior: smooth;
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

    .main-content::-webkit-scrollbar-thumb:hover {
        background: #C94F86;
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
    
    /* ========== FILTER BAR ========== */
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
    
    .search-box i { color: #94a3b8; }
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
        font-size: 13px;
        outline: none;
        cursor: pointer;
        min-width: 130px;
    }
    
    .filter-btn {
        background: #E75A9B;
        color: white;
        border: none;
        padding: 10px 24px;
        border-radius: 40px;
        cursor: pointer;
        font-weight: 600;
        font-size: 13px;
    }
    
    .reset-btn {
        background: #64748b;
    }
    
    /* ========== TABLE STYLES ========== */
    .reviews-table-container {
        background: white;
        border-radius: 20px;
        overflow-x: auto;
        box-shadow: 0 2px 10px rgba(0,0,0,0.05);
    }
    
    .reviews-table {
        width: 100%;
        border-collapse: collapse;
        min-width: 700px;
    }
    
    .reviews-table th {
        padding: 14px 16px;
        text-align: left;
        background: #f8f8f8;
        font-size: 11px;
        font-weight: 700;
        color: #302E63;
        border-bottom: 1px solid #e0e0e0;
    }
    
    .reviews-table td {
        padding: 14px 16px;
        border-bottom: 1px solid #eef2f7;
        font-size: 13px;
        color: #475569;
        vertical-align: top;
    }
    
    .reviews-table tr:hover td {
        background: #fafcff;
    }
    
    .rating-stars {
        color: #FFB800;
        font-size: 13px;
        white-space: nowrap;
    }
    
    .anonymous-badge {
        background: #fef3c7;
        color: #f59e0b;
        padding: 2px 8px;
        border-radius: 20px;
        font-size: 10px;
        font-weight: 600;
        display: inline-block;
    }
    
    .delete-btn {
        background: #fee2e2;
        color: #dc2626;
        border: none;
        padding: 6px 12px;
        border-radius: 20px;
        cursor: pointer;
        font-size: 11px;
        font-weight: 600;
        transition: 0.2s;
    }
    
    .delete-btn:hover {
        background: #dc2626;
        color: white;
    }
    
    .alert {
        padding: 12px 16px;
        border-radius: 12px;
        margin-bottom: 20px;
    }
    
    /* Alert with auto-dismiss animation */
.alert-success {
    background: #d4edda;
    color: #155724;
    border-left: 4px solid #28a745;
    border-radius: 12px;
    padding: 12px 16px;
    margin-bottom: 20px;
    display: flex;
    justify-content: space-between;
    gap: 12px;
    transition: opacity 0.5s ease, transform 0.3s ease;
    position: relative;
}

.alert-success.fade-out {
    opacity: 0;
    transform: translateY(-10px);
    pointer-events: none;
}

.alert-close {
    background: transparent;
    border: none;
    font-size: 20px;
    cursor: pointer;
    color: #155724;
    padding: 0 6px;
    font-weight: bold;
    opacity: 0.6;
    transition: opacity 0.2s;
}

.alert-close:hover {
    opacity: 1;
}
    
    .empty-state {
        text-align: center;
        padding: 60px;
        color: #999;
    }
    
    .empty-state i {
        font-size: 48px;
        color: #ccc;
        margin-bottom: 15px;
        display: block;
    }
    
    /* ========== RESPONSIVE ========== */
    @media (max-width: 768px) {
        .menu-toggle { display: block; }
        .sidebar { transform: translateX(-100%); }
        .sidebar.open { transform: translateX(0); }
        .main-content { margin-left: 0; padding: 16px; }
        .filter-bar { flex-direction: column; align-items: stretch; }
        .brand-icon { width: 38px; height: 38px; }
        .brand-title h1 { font-size: 1.1rem; }
        .sidebar-header { padding: 20px 16px; }
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
        <!-- DASHBOARD -->
        <div class="nav-section">
            <a href="admin_dashboard.php" class="nav-item">
                <i class="bi bi-speedometer2"></i><span>Dashboard</span>
            </a>
        </div>

        <!-- USERS -->
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

        <!-- FINANCE -->
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

        <!-- BOOKINGS -->
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

        <!-- REPORTS -->
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
        <a href="logout.php" class="logout-icon" title="Logout">
            <i class="bi bi-box-arrow-right"></i>
        </a>
    </div>
</aside>

<div class="main-content" id="mainContent">
   <div class="top-bar">
    <div style="display: flex; align-items: center; gap: 16px;">
        <a href="admin_tutor_actions.php" class="btn-back" style="display: inline-flex; align-items: center; gap: 8px; background: #e2e8f0; color: #1d3156; padding: 8px 16px; border-radius: 40px; text-decoration: none; font-size: 13px; font-weight: 600; transition: 0.2s;">
            <i class="bi bi-arrow-left"></i> Back
        </a>
        <div class="page-title">
            <h1>Manage Reviews</h1>
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
    <div class="alert alert-success" id="successAlert">
        <i class="bi bi-check-circle"></i>
        <?= $_SESSION['success_message'] ?>
        <button class="alert-close" onclick="this.closest('.alert').remove()">&times;</button>
    </div>
    <?php unset($_SESSION['success_message']); ?>
<?php endif; ?>

    <?php
    $totalReviewsCount = $reviews->num_rows;
    $avgRating = 0;
    $ratingSum = 0;
    $reviews->data_seek(0);
    while ($row = $reviews->fetch_assoc()) {
        $ratingSum += $row['rating'];
    }
    $avgRating = $totalReviewsCount > 0 ? round($ratingSum / $totalReviewsCount, 1) : 0;
    $reviews->data_seek(0);
    ?>


    <!-- Filter Bar -->
    <form method="GET" class="filter-bar">
        <div class="search-box">
            <i class="bi bi-search"></i>
            <input type="text" name="search" placeholder="Search by tutor or student name..." value="<?= e($search) ?>">
        </div>
        <select name="language" class="filter-select">
            <option value="">All Languages</option>
            <?php while ($lang = $languages->fetch_assoc()): ?>
                <option value="<?= e($lang['language']) ?>" <?= $language_filter == $lang['language'] ? 'selected' : '' ?>><?= e($lang['language']) ?></option>
            <?php endwhile; ?>
        </select>
        <select name="sort" class="filter-select">
            <option value="newest" <?= $sort_by == 'newest' ? 'selected' : '' ?>>Newest First</option>
            <option value="oldest" <?= $sort_by == 'oldest' ? 'selected' : '' ?>>Oldest First</option>
            <option value="rating_high" <?= $sort_by == 'rating_high' ? 'selected' : '' ?>>Highest Rating</option>
            <option value="rating_low" <?= $sort_by == 'rating_low' ? 'selected' : '' ?>>Lowest Rating</option>
            <option value="language_az" <?= $sort_by == 'language_az' ? 'selected' : '' ?>>Language A-Z</option>
            <option value="language_za" <?= $sort_by == 'language_za' ? 'selected' : '' ?>>Language Z-A</option>
        </select>
        <button type="submit" class="filter-btn"><i class="bi bi-search"></i> Filter</button>
        <?php if ($search || $language_filter || $sort_by != 'newest'): ?>
            <a href="admin_manage_reviews.php" class="filter-btn reset-btn" style="text-decoration: none;"><i class="bi bi-x-circle"></i> Clear</a>
        <?php endif; ?>
    </form>

    <!-- Reviews Table -->
    <div class="reviews-table-container">
        <?php if ($totalReviewsCount == 0): ?>
            <div class="empty-state">
                <i class="bi bi-chat-dots"></i>
                <p>No reviews found. <?= $search ? 'Try changing your search criteria.' : 'Reviews will appear here once students rate their sessions.' ?></p>
            </div>
        <?php else: ?>
            <table class="reviews-table">
                <thead>
                    <tr>
                        <th>Tutor</th>
                        <th>Student</th>
                        <th>Language</th>
                        <th>Rating</th>
                        <th>Review</th>
                        <th>Session Date</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($review = $reviews->fetch_assoc()): ?>
                        <tr>
                            <td>
                                <strong><?= e($review['tutor_name']) ?></strong>
                                <div style="font-size: 11px; color: #999;"><?= e($review['tutor_email']) ?></div>
                             </td>
                            <td>
                                <?php if ($review['is_anonymous']): ?>
                                    <span class="anonymous-badge"><i class="bi bi-incognito"></i> Anonymous Student</span>
                                <?php else: ?>
                                    <strong><?= e($review['student_name']) ?></strong>
                                <?php endif; ?>
                             </td>
                            <td><span style="background: #e0f2fe; color: #0284c7; padding: 4px 10px; border-radius: 20px; font-size: 11px;"><?= e($review['language']) ?></span></td>
                            <td>
                                <div class="rating-stars">
                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                        <i class="bi bi-star<?= $i <= $review['rating'] ? '-fill' : '' ?>"></i>
                                    <?php endfor; ?>
                                    <span style="margin-left: 5px; color: #333;"><?= $review['rating'] ?>/5</span>
                                </div>
                            </td>
                            <td style="max-width: 250px;">
                                <?php if ($review['comment']): ?>
                                    "<?= nl2br(e($review['comment'])) ?>"
                                <?php else: ?>
                                    <span style="color: #999; font-style: italic;">No comment</span>
                                <?php endif; ?>
                            </td>
                            <td style="font-size: 12px;"><?= date('d M Y', strtotime($review['booking_date'])) ?></td>
                            <td>
                                <button class="delete-btn" onclick="confirmDelete(<?= $review['id'] ?>, '<?= e(addslashes($review['tutor_name'])) ?>')">
                                    <i class="bi bi-trash"></i> Delete
                                </button>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        <?php endif; ?>
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


window.addEventListener('beforeunload', function() {
    // This will trigger when navigating away
    sessionStorage.setItem('reviews_viewed', 'true');
});

function confirmDelete(reviewId, tutorName) {
    Swal.fire({
        title: 'Delete Review?',
        html: `Are you sure you want to delete the review for <strong>${tutorName}</strong>?<br>This action cannot be undone.`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc2626',
        cancelButtonColor: '#64748b',
        confirmButtonText: 'Yes, Delete',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            window.location.href = `admin_manage_reviews.php?delete_id=${reviewId}`;
        }
    });
}

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

// Auto-dismiss success message after 3 seconds
function setupAutoDismissAlerts() {
    const successAlert = document.getElementById('successAlert');
    
    if (successAlert) {
        // Auto dismiss after 3 seconds
        setTimeout(() => {
            successAlert.classList.add('fade-out');
            setTimeout(() => {
                if (successAlert && successAlert.parentNode) {
                    successAlert.remove();
                }
            }, 500);
        }, 3000);
    }
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    setupAutoDismissAlerts();
});
</script>

</body>
</html>