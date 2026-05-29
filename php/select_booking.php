<?php
session_start();
include 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'tutor') {
    header("Location: login.php");
    exit();
}

$userID = $_SESSION['user_id'];
$assetBase = '../assets/img';
$action = $_GET['action'] ?? ''; // 'upload' or 'assignment'

// Get user info for nav
$stmtUser = $conn->prepare("SELECT * FROM users WHERE id = ? AND role = 'tutor'");
$stmtUser->bind_param("i", $userID);
$stmtUser->execute();
$user = $stmtUser->get_result()->fetch_assoc();
$displayName = $user['fullname'] ?? '';
$profilePic = !empty($user['profile_pic']) ? '../uploads/profiles/' . $user['profile_pic'] : $assetBase . '/profile-tutor.png';
$sort = $_GET['sort'] ?? 'latest';

switch ($sort) {
    case 'upcoming':
        $orderBy = "b.booking_date ASC, b.booking_time ASC";
        break;

    case 'student':
        $orderBy = "u.fullname ASC";
        break;

    default:
        $orderBy = "b.booking_date DESC, b.booking_time DESC";
        break;
}
// Filter bookings based on action
$statusFilter = ($action === 'assignment')
    ? "('confirmed')"
    : "('confirmed', 'completed')";

$query = "
    SELECT b.id, b.booking_date, b.booking_time, b.language, b.status, b.learning_mode,
           u.fullname as student_name
    FROM bookings b
    JOIN users u ON b.student_id = u.id
    WHERE b.tutor_id = ?
    AND b.status IN $statusFilter
    AND TIMESTAMP(b.booking_date, b.booking_time) > NOW()  -- Only future sessions
    ORDER BY $orderBy
";

$stmt = $conn->prepare($query);
$stmt->bind_param("i", $userID);
$stmt->bind_param("i", $userID);
$stmt->execute();
$bookings = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

function e($v) { return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }

// Set page title based on action
if ($action === 'upload') {
    $pageTitle = "Upload Material";
    $pageIcon = "bi-cloud-upload";
    $actionText = "Upload Material";
    $actionDesc = "Choose a student to upload learning materials for";
    $activeNav = "materials"; // For highlighting My Materials
} elseif ($action === 'assignment') {
    $pageTitle = "Create Assignment";
    $pageIcon = "bi-pencil-square";
    $actionText = "Create Assignment";
    $actionDesc = "Choose a student to create homework for";
    $activeNav = "assignments"; // For highlighting My Assignments
} else {
    $pageTitle = "Select Student";
    $pageIcon = "bi-person-arms-up";
    $actionText = "Continue";
    $actionDesc = "Choose a student to continue";
    $activeNav = "";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?> · Kyoshi</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Poppins', sans-serif;
            background: url('../assets/img/background2.png') no-repeat center top;
            background-size: cover;
            min-height: 100vh;
        }
        body::before {
            content: ''; position: fixed; inset: 0;
            background: rgba(255, 255, 255, 0.25); z-index: -1;
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
        .nav {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 32px;
            min-height: 70px;
        }
        .brand {
            display: flex;
            align-items: center;
            gap: 10px;
            text-decoration: none;
        }
        .brand img { width: 42px; height: 42px; object-fit: contain; }
        .brand strong { display: block; color: #1d3156; font-size: 20px; }
        .brand span { color: #496894; font-size: 11px; font-weight: 600; }
        .nav-links { display: flex; gap: 28px; align-items: center; flex-wrap: wrap; }
        
.nav-links a{
    text-decoration:none;
    color:#1d3156;
    font-size:14px;
    font-weight:600;
    position:relative;
    transition:0.25s;
    padding: 6px 0;
    white-space: nowrap;
}

.nav-links a:hover,
.nav-links a.active{
    color:#496894;
    font-weight:700;
}

.nav-links a::after{
    content:'';
    position:absolute;
    left:0;
    bottom:-6px;
    width:0%;
    height:3px;
    background:#496894;
    transition:0.25s;
    border-radius:10px;
}

.nav-links a:hover::after,
.nav-links .active::after{
    width:100%;
}

        
        .profile {
            display: flex;
            align-items: center;
            gap: 8px;
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            padding: 6px 14px 6px 8px;
            border-radius: 40px;
            cursor: pointer;
            transition: 0.25s;
        }
        .profile:hover { background: rgba(255, 255, 255, 0.2); }
        .profile img { width: 36px; height: 36px; border-radius: 50%; object-fit: cover; }
        .profile span { font-size: 13px; font-weight: 500; color: #1d3156; }
        
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
        .dropdown hr { margin: 0; border-color: #ecf3f9; }
        
        .main {
            max-width: 800px;
            margin: 32px auto 60px;
            padding: 0 20px;
        }
        
        .card {
            background: white;
            border-radius: 24px;
            padding: 32px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
        }
        
        .btn-back {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            color: #64748b;
            text-decoration: none;
            margin-bottom: 20px;
            font-size: 13px;
        }
        .btn-back:hover { color: #E75A9B; }
        
        h1 { font-size: 24px; color: #1d3156; margin-bottom: 8px; display: flex; align-items: center; gap: 10px; }
        .subtitle { color: #64748b; font-size: 14px; margin-bottom: 24px; border-bottom: 1px solid #eef2f7; padding-bottom: 16px; }
        
        .student-card {
            border: 1px solid #eef2f7;
            border-radius: 16px;
            padding: 16px 20px;
            margin-bottom: 12px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: all 0.2s;
            text-decoration: none;
            background: white;
            cursor: pointer;
        }
        .student-card:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
            transform: translateY(-2px);
            border-color: #E75A9B;
        }
        .student-info h3 { 
            font-size: 16px; 
            color: #1d3156; 
            margin-bottom: 4px;
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }
        .student-info p { font-size: 12px; color: #64748b; }
        
        .session-badge {
            font-size: 10px;
            padding: 2px 8px;
            border-radius: 20px;
            font-weight: 600;
        }
        .badge-confirmed { background: #d4edda; color: #28a745; }
        .badge-completed { background: #dbeafe; color: #3b82f6; }
        
        .btn-go {
            background: linear-gradient(135deg, #E75A9B, #F28AB2);
            color: white;
            padding: 8px 20px;
            border-radius: 30px;
            font-size: 13px;
            font-weight: 600;
            transition: all 0.2s;
        }
        .btn-go:hover { transform: translateY(-1px); opacity: 0.95; }
        
        .empty-state { 
            text-align: center; 
            padding: 60px; 
            color: #94a3b8; 
        }
        .empty-state i { font-size: 64px; margin-bottom: 16px; display: block; }
        .empty-state p { margin-bottom: 8px; }
        
        @media (max-width: 768px) {
            body { padding: 0; }
            .main { padding: 0 16px; }
            .card { padding: 20px; }
            .student-card { flex-direction: column; text-align: center; gap: 12px; }
            .student-info h3 { justify-content: center; }
            .nav { flex-wrap: wrap; }
            .nav-links { order: 3; width: 100%; justify-content: center; padding-bottom: 10px; }
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
            <?php
            $materialsActive = ($action === 'upload') ? 'active' : '';
            $assignmentsActive = ($action === 'assignment') ? 'active' : '';
            ?>
            <div class="nav-links">
              <a href="tutor_dashboard.php">Dashboard</a>
                <a href="booking_requests.php">My Bookings</a>
                <a href="material_overview.php" class="<?= $materialsActive ?>">My Materials</a>
                <a href="assignment_overview.php" class="<?= $assignmentsActive ?>">My Assignments</a>
                <a href="view_session_reports.php">My Reports</a>
            </div>
            <div style="position:relative;">
                <button class="profile" onclick="toggleDropdown()">
                    <img src="<?= e($profilePic) ?>" alt="">
                    <span><?= e($displayName) ?></span>
                    <i class="bi bi-chevron-down"></i>
                </button>
                <div class="dropdown" id="profileDropdown">
                    <a href="teacher_profile.php"><i class="bi bi-person-circle"></i> My Profile</a>
                    <a href="earnings.php"><i class="bi bi-wallet2"></i> My Earnings</a>
                    <hr>
                    <a href="logout.php" style="color:#dc2626;"><i class="bi bi-box-arrow-right"></i> Logout</a>
                </div>
            </div>
        </nav>
    </div>
</header>

<div class="main">
    <div class="card">
        <a href="tutor_dashboard.php" class="btn-back">
            <i class="bi bi-arrow-left"></i> Back to Dashboard
        </a>
        
        <h1>
            <i class="bi <?= $pageIcon ?>"></i> 
            <?= $pageTitle ?>
        </h1>
        <p class="subtitle"><?= $actionDesc ?></p>

        <form method="GET" 
      style="margin-bottom:20px;display:flex;align-items:center;gap:10px;">

    <input type="hidden" name="action" value="<?= e($action) ?>">

    <label style="font-size:14px;font-weight:600;color:#64748b;">
        Sort by
    </label>

    <select name="sort" onchange="this.form.submit()" class="sort-select"
        style="
            padding:10px 16px;
            border-radius:12px;
            border:1px solid #e2edf7;
            background:white;
            font-size:13px;
            color:#1d3156;
            font-weight:500;
            cursor:pointer;
        ">

        <option value="latest" <?= $sort === 'latest' ? 'selected' : '' ?>>
            Latest Session Date
        </option>

        <option value="upcoming" <?= $sort === 'upcoming' ? 'selected' : '' ?>>
            Upcoming Session Date
        </option>

        <option value="student" <?= $sort === 'student' ? 'selected' : '' ?>>
            Student Name 
        </option>

    </select>
</form>
        
        <?php if (empty($bookings)): ?>
            <div class="empty-state">
                <i class="bi bi-inbox"></i>
                <p><strong>No active bookings found</strong></p>
                <p style="font-size: 12px;">You need confirmed bookings before uploading materials or creating assignments.</p>
                <a href="booking_requests.php" style="display: inline-block; margin-top: 16px; padding: 10px 24px; background: #E75A9B; color: white; border-radius: 30px; text-decoration: none; font-size: 13px;">
                    <i class="bi bi-calendar-check"></i> View Bookings
                </a>
            </div>
        <?php else: ?>
            <?php foreach ($bookings as $booking): ?>
                <a href="<?= $action === 'upload' ? 'upload_material.php?booking_id=' . $booking['id'] : 'create_assignment.php?booking_id=' . $booking['id'] ?>" class="student-card">
                    <div class="student-info">
                        <h3>
                            <?= e($booking['student_name']) ?>
                            <span class="session-badge badge-<?= $booking['status'] ?>">
                                <?= ucfirst($booking['status']) ?>
                            </span>
                        </h3>
                        <p>
                            <i class="bi bi-calendar"></i> <?= date('d M Y', strtotime($booking['booking_date'])) ?> at <?= date('g:i A', strtotime($booking['booking_time'])) ?>
                            <span style="margin: 0 6px">•</span>
                            <i class="bi bi-chat-dots"></i> <?= e($booking['language']) ?>
                            <span style="margin: 0 6px">•</span>
                            <i class="bi bi-<?= $booking['learning_mode'] === 'online' ? 'camera-video' : 'building' ?>"></i>
                            <?= $booking['learning_mode'] === 'online' ? 'Online' : 'Face to Face' ?>
                        </p>
                    </div>
                    <div class="btn-go">
                        <?= $actionText ?> <i class="bi bi-arrow-right"></i>
                    </div>
                </a>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
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
</script>
</body>
</html>