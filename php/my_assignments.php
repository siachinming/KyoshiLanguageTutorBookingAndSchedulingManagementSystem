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

// Verify student role
$stmt = $conn->prepare("SELECT * FROM users WHERE id = ? AND role = 'student'");
$stmt->bind_param("i", $userID);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
if (!$user) { 
    header("Location: login.php"); 
    exit(); 
}

$displayName = $user['fullname'];
if (!empty($user['profile_pic']) && file_exists('../uploads/profiles/' . $user['profile_pic'])) {
    $profilePic = '../uploads/profiles/' . $user['profile_pic'];
} else {
    $profilePic = $assetBase . '/profile.png';
}
// Get filter parameters
$filterStatus = $_GET['status'] ?? 'all';
$sortBy = $_GET['sort'] ?? 'due_date_asc';

// Build query for student assignments
$query = "
    SELECT 
        a.*,
        b.booking_date,
        b.language,
        b.learning_mode,
        u.fullname as tutor_name,
        u.id as tutor_id,
        s.id as submission_id,
        s.submission_text,
        s.file_name as submission_file_name,
        s.file_path as submission_file_path,
        s.status as submission_status,
        s.submitted_at,
        s.reviewed_at,
        s.feedback,
        s.grade,
        s.file_type as submission_file_type,
        CASE 
            WHEN s.id IS NOT NULL AND s.grade IS NOT NULL AND s.grade != '' THEN 'graded'
            WHEN s.id IS NOT NULL THEN 'submitted'
            WHEN a.due_date IS NULL OR a.due_date = '0000-00-00 00:00:00' THEN 'no_due_date'
            WHEN a.due_date < NOW() AND a.allow_late_submission = 0 THEN 'overdue_no_submit'
            WHEN a.due_date < NOW() AND a.allow_late_submission = 1 THEN 'pending_late_allowed'
            ELSE 'pending'
        END as assignment_status
    FROM assignments a
    JOIN bookings b ON a.booking_id = b.id
    JOIN users u ON b.tutor_id = u.id
    LEFT JOIN assignment_submissions s ON a.id = s.assignment_id AND s.student_id = ?
    WHERE b.student_id = ?
";

$params = [$userID, $userID];
$types = "ii";

// Add status filter
if ($filterStatus !== 'all') {
    if ($filterStatus === 'submitted') {
        $query .= " AND s.id IS NOT NULL AND (s.grade IS NULL OR s.grade = '')";
    } elseif ($filterStatus === 'pending') {
        $query .= " AND s.id IS NULL AND (a.due_date IS NULL OR a.due_date = '0000-00-00 00:00:00' OR a.due_date >= NOW())";
    } elseif ($filterStatus === 'overdue') {
        $query .= " AND s.id IS NULL AND a.due_date IS NOT NULL AND a.due_date != '0000-00-00 00:00:00' AND a.due_date < NOW() AND a.allow_late_submission = 0";
    } elseif ($filterStatus === 'graded') {
        $query .= " AND s.grade IS NOT NULL AND s.grade != ''";
    }
}

// Add sorting
if ($sortBy === 'due_date_asc') {
    $query .= " ORDER BY CASE WHEN a.due_date IS NULL OR a.due_date = '0000-00-00 00:00:00' THEN 1 ELSE 0 END, a.due_date ASC, a.created_at ASC";
} elseif ($sortBy === 'due_date_desc') {
    $query .= " ORDER BY CASE WHEN a.due_date IS NULL OR a.due_date = '0000-00-00 00:00:00' THEN 1 ELSE 0 END, a.due_date DESC, a.created_at DESC";
} elseif ($sortBy === 'newest') {
    $query .= " ORDER BY a.created_at DESC";
} elseif ($sortBy === 'oldest') {
    $query .= " ORDER BY a.created_at ASC";
} else {
    $query .= " ORDER BY a.created_at DESC";
}

$stmt = $conn->prepare($query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$assignments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

function e($v) { 
    return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); 
}

function getStatusBadge($status) {
    if ($status === 'graded') {
        return '<span class="badge graded"><i class="bi bi-trophy-fill"></i> Graded</span>';
    } elseif ($status === 'submitted') {
        return '<span class="badge submitted"><i class="bi bi-clock-history"></i> Awaiting Review</span>';
    } elseif ($status === 'overdue_no_submit') {
        return '<span class="badge overdue"><i class="bi bi-exclamation-triangle-fill"></i> Overdue</span>';
    } elseif ($status === 'pending_late_allowed') {
        return '<span class="badge late-allowed"><i class="bi bi-clock"></i> Late Allowed</span>';
    } elseif ($status === 'no_due_date') {
        return '<span class="badge pending"><i class="bi bi-infinity"></i> No Deadline</span>';
    } else {
        return '<span class="badge pending"><i class="bi bi-hourglass-split"></i> Pending</span>';
    }
}

function formatDueDate($due_date) {
    if (empty($due_date) || $due_date === '0000-00-00 00:00:00') {
        return '<span style="color:#f59e0b;"><i class="bi bi-infinity"></i> No due date</span>';
    }
    return date('d M Y, g:i A', strtotime($due_date));
}

// Function to check if submission was late
function getSubmissionTiming($submitted_at, $due_date) {
    if (empty($due_date) || $due_date === '0000-00-00 00:00:00') {
        return '<span class="timing-badge no-deadline"><i class="bi bi-infinity"></i> No deadline</span>';
    }
    
    $submitted = new DateTime($submitted_at);
    $due = new DateTime($due_date);
    
    if ($submitted <= $due) {
        // Calculate how early
        $diff = $submitted->diff($due);
        if ($diff->days > 0) {
            return '<span class="timing-badge early"><i class="bi bi-clock"></i> ' . $diff->days . ' day(s) early</span>';
        } elseif ($diff->h > 0) {
            return '<span class="timing-badge early"><i class="bi bi-clock"></i> ' . $diff->h . ' hour(s) early</span>';
        } elseif ($diff->i > 0) {
            return '<span class="timing-badge early"><i class="bi bi-clock"></i> ' . $diff->i . ' minute(s) early</span>';
        } else {
            return '<span class="timing-badge on-time"><i class="bi bi-check-circle"></i> On time</span>';
        }
    } else {
        // Late submission
        $diff = $due->diff($submitted);
        if ($diff->days > 0) {
            return '<span class="timing-badge late"><i class="bi bi-exclamation-triangle"></i> ' . $diff->days . ' day(s) late</span>';
        } elseif ($diff->h > 0) {
            return '<span class="timing-badge late"><i class="bi bi-exclamation-triangle"></i> ' . $diff->h . ' hour(s) late</span>';
        } else {
            return '<span class="timing-badge late"><i class="bi bi-exclamation-triangle"></i> ' . $diff->i . ' minute(s) late</span>';
        }
    }
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
    <title>My Assignments · Kyoshi</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="../css/style.css">
    <style>
        :root{
            --cream:#FFF1F6;--paper:rgba(255,255,255,.88);--ink:#342635;--muted:#7B6178;
            --pink:#F28AB2;--pink-dark:#C94F86;--hot-pink:#E75A9B;
            --shadow:0 18px 45px rgba(201,79,134,.16);--radius-xl:32px;--radius-lg:24px;
        }
        *{box-sizing:border-box}
        body{margin:0;min-height:100vh;font-family:'Segoe UI',Arial,sans-serif;color:var(--ink);
            background:linear-gradient(120deg,rgba(255,241,246,.74),rgba(255,203,220,.30)),
            url("../assets/img/background3.jpg") center/cover fixed no-repeat;}
        .container{width:min(1440px,calc(100% - 40px));margin:0 auto}

     .topbar{position:sticky;top:0;z-index:50;background:rgba(255,241,246,.86);backdrop-filter:blur(20px);border-bottom:1px solid rgba(231,90,155,.18);box-shadow:0 10px 30px rgba(201,79,134,.10)}
    .nav{min-height:78px;display:grid;grid-template-columns:190px minmax(0,1fr) 360px;gap:16px;align-items:center;}
    .brand{display:flex;align-items:center;gap:10px;text-decoration: none;}
    .brand img{width:44px;height:44px;object-fit:contain;border-radius:14px}
    .brand strong{display:block;font-size:18px;line-height:1.05;color:black;}
    .brand span{display:block;margin-top:3px;font-size:11px;color:var(--muted);white-space:nowrap}
    .nav-links{display:flex;align-items:center;justify-content:center;gap:6px;;border-radius:999px;padding:7px;overflow:auto;scrollbar-width:none;}
    .nav-links::-webkit-scrollbar{display:none}
    .nav-links a{flex:0 0 auto;padding:9px 12px;border-radius:999px;font-size:13px;font-weight:900;color:#6D4964;white-space:nowrap;transition:.18s ease;text-decoration:none;}
    .nav-links a.active,.nav-links a:hover{background:linear-gradient(135deg,var(--hot-pink),var(--pink));color:#fff;box-shadow:0 8px 18px rgba(231,90,155,.28)}
    .nav-actions{display:flex;align-items:center;gap:10px}
    .profile{display:flex;align-items:center;gap:9px;border-radius:999px;padding:6px 12px 6px 6px;font-weight:900;color:#7A3D65;border:1px solid rgba(46,42,59,.08);background:rgba(255,255,255,.88);cursor:pointer}
    .profile img{width:34px;height:34px;object-fit:cover;border-radius:50%}
        .back-link{display:inline-flex;align-items:center;gap:6px;color:var(--pink-dark);font-weight:900;font-size:13px;padding:9px 16px;border-radius:999px;background:rgba(255,255,255,.78);text-decoration:none;margin-bottom:20px}
        
        .filter-bar{background:var(--paper);border-radius:var(--radius-lg);padding:20px;margin-bottom:20px;display:flex;flex-wrap:wrap;gap:16px;align-items:flex-end}
        .filter-group{display:flex;flex-direction:column;gap:6px;flex:1;min-width:150px}
        .filter-group label{font-size:12px;font-weight:900;color:var(--muted)}
        .filter-select{padding:11px 15px;border-radius:14px;border:1px solid rgba(46,42,59,.12);font-weight:700;background:white}
        .btn-reset{padding:11px 22px;border-radius:999px;background:none;color:var(--muted);font-weight:900;cursor:pointer;text-decoration:none;border:1px solid rgba(46,42,59,.12)}
        
        .assignment-list{display:grid;grid-template-columns:repeat(auto-fill,minmax(450px,1fr));gap:20px;padding-bottom:40px}
        .assignment-card {
            background: white;
            border-radius: 20px;
            padding: 20px;
            border: 1px solid rgba(231,90,155,.1);
            transition: .3s;
            display: flex;
            flex-direction: column;
            height: 100%;
        }
        .assignment-card:hover{transform:translateY(-3px);box-shadow:0 8px 25px rgba(201,79,134,.12)}
        
        .assign-header{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:12px;flex-wrap:wrap;gap:10px}
        .assign-title h3{margin:0;font-size:16px;font-weight:800}
        .badge{display:inline-flex;align-items:center;gap:4px;padding:4px 12px;border-radius:20px;font-size:10px;font-weight:700}
        .badge.pending{background:#fff3cd;color:#856404}
        .badge.submitted{background:#d1ecf1;color:#0c5460}
        .badge.graded{background:#d4edda;color:#155724}
        .badge.overdue{background:#f8d7da;color:#721c24}
        .badge.late-allowed{background:#e0d4ff;color:#6f42c1}
        
        .assign-meta{display:flex;flex-wrap:wrap;gap:12px;margin:12px 0;font-size:12px;color:var(--muted)}
        .meta-item{display:inline-flex;align-items:center;gap:4px}
        
        .assign-description{background:#fefce8;border-radius:12px;padding:10px 14px;margin-bottom:12px;font-size:12px;color:#475569}
        
        .section-title{font-size:13px;font-weight:800;color:var(--ink);margin-bottom:10px;display:flex;align-items:center;gap:8px;border-left:3px solid var(--hot-pink);padding-left:10px}
        .attachments-grid{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:16px}
        .attach-btn{display:inline-flex;align-items:center;gap:4px;padding:5px 12px;background:#f1f5f9;border-radius:20px;font-size:11px;font-weight:600;color:var(--pink-dark);text-decoration:none}
        .my-submission-box{border-radius:16px;padding:12px;margin-bottom:12px}
        .timing-badge{display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:20px;font-size:10px;font-weight:700}
        .timing-badge.early{background:#d4edda;color:#155724}
        .timing-badge.on-time{background:#d1ecf1;color:#0c5460}
        .timing-badge.late{background:#f8d7da;color:#721c24}
        .timing-badge.no-deadline{background:#e2e3e5;color:#383d41}
        
        .submission-area{margin-top:auto;padding-top:12px;border-top:1px solid rgba(0,0,0,.06)}
        .submission-info{background:#f0f9ff;border-radius:12px;padding:10px 14px;margin-bottom:12px;font-size:12px}
        .grade-info{background:#ecfdf5;border-radius:12px;padding:12px;margin-top:12px}
        .btn-action{padding:8px 20px;border-radius:25px;font-size:12px;font-weight:700;cursor:pointer;border:none}
        .btn-action.primary{background:linear-gradient(135deg,var(--hot-pink),var(--pink));color:white}
        .btn-action.outline{background:transparent;border:1px solid var(--pink-dark);color:var(--pink-dark)}
        
        .empty-state{text-align:center;padding:60px;background:white;border-radius:24px;color:#94a3b8}
        .modal-overlay{position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:1000;display:flex;align-items:center;justify-content:center}
        .modal-container{background:white;border-radius:24px;max-width:600px;width:90%;max-height:90vh;overflow-y:auto}
        .modal-header{padding:20px 24px;border-bottom:1px solid #e2e8f0;display:flex;justify-content:space-between}
        .modal-body{padding:24px}
        .modal-footer{padding:16px 24px;border-top:1px solid #e2e8f0;display:flex;justify-content:flex-end;gap:12px}
        .form-control{width:100%;padding:12px;border-radius:12px;border:1px solid #cbd5e1;font-family:inherit}
        .toast{position:fixed;bottom:30px;left:50%;transform:translateX(-50%);background:#8E3F70;color:white;padding:12px 24px;border-radius:40px;font-size:14px;font-weight:600;z-index:10001;opacity:0;transition:opacity 0.2s}
        .toast.show{opacity:1}
        .toast.error{background:#dc2626}
        
        @media(max-width:600px){.assignment-list{grid-template-columns:1fr}}
    </style>
</head>
<body>

<header class="topbar">
  <div class="container">
    <nav class="nav">
        <button class="hamburger-menu" id="hamburgerBtn">
    <i class="bi bi-list"></i>
</button>
      <a href="student_dashboard.php" class="brand">
        <img src="<?= e($assetBase) ?>/logo.png" alt="Kyoshi logo">
        <div>
          <strong style="text-decoration:none;">Kyoshi</strong>
          <span style="text-decoration:none;">Student Learning Space</span>
        </div>
      </a>

      <div class="nav-links">
        <a href="student_dashboard.php">Home</a>
        <a href="find_language.php">Find Language</a>
        <a href="booking_status.php">My Bookings</a>
        <a href="my_payments.php">My Payments</a>
        <a href="my_materials.php">My Materials</a>
        <a class="active" href="my_assignments.php">My Assignments</a>
      </div>
      
      <div class="nav-actions" style="display:flex;align-items:center;justify-content:flex-end;gap:10px;margin-left:auto;">
        <div style="position:relative;">
          <button class="profile" onclick="toggleDropdown()" id="profileBtn">
            <img src="<?= e($profilePic) ?>" alt="Student profile">
            <span><?= e($displayName) ?></span>
            <i class="bi bi-chevron-down" style="font-size:11px; margin-left:4px;"></i>
          </button>
          <div id="profileDropdown" style="display:none;position:absolute;top:calc(100% + 10px);right:0;background:white;border-radius:16px;box-shadow:0 18px 45px rgba(201,79,134,.2);border:1px solid rgba(242,138,178,.2);min-width:180px;overflow:hidden;z-index:100;">
            <a href="student_profile.php" style="text-decoration:none; display:flex;align-items:center;gap:10px;padding:14px 16px;font-size:14px;font-weight:700;color:#342635;transition:.15s ease;" onmouseover="this.style.background='#FFF1F6'" onmouseout="this.style.background='white'">
              <i class="bi bi-person-circle" style="color:#E75A9B;"></i> My Profile
            </a>
            <a href="my_progress.php" style="text-decoration:none;display:flex;align-items:center;gap:10px;padding:14px 16px;font-size:14px;font-weight:700;color:#342635;transition:.15s ease;" onmouseover="this.style.background='#FFF1F6'" onmouseout="this.style.background='white'">
              <i class="bi bi-bar-chart-steps" style="color:#E75A9B;"></i> My Progress
            </a>
            <a href="student_favourites.php" style="text-decoration:none; display:flex;align-items:center;gap:10px;padding:14px 16px;font-size:14px;font-weight:700;color:#342635;transition:.15s ease;" onmouseover="this.style.background='#FFF1F6'" onmouseout="this.style.background='white'">
              <i class="bi bi-heart" style="color:#E75A9B;"></i> My Favourites
            </a>
            <hr style="margin:4px 0;border-color:rgba(242,138,178,.2);">
            <a href="logout.php" style="text-decoration:none; display:flex;align-items:center;gap:10px;padding:14px 16px;font-size:14px;font-weight:700;color:#dc2626;transition:.15s ease;" onmouseover="this.style.background='#FFF1F6'" onmouseout="this.style.background='white'">
              <i class="bi bi-box-arrow-right"></i> Logout
            </a>
          </div>
        </div>
      </div>
    </nav>
  </div>
</header>

  <div class="nav-overlay" id="navOverlay"></div>


<div class="container" style="padding:24px 0 60px;">
    <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 16px; margin-bottom: 20px;">
    <a href="student_dashboard.php" class="back-link" style="margin-bottom: 0;">
        <i class="bi bi-arrow-left"></i><span>Back</span>
    </a><br>
    <div style="text-align: center; flex: 1;">
        <h1 style="margin:0;font-size:28px;">My Assignments</h1>
        <p style="margin:8px 0 0;color:var(--muted);font-size:14px;">Track, submit, and get feedback on your assignments</p>
    </div>
    <div style="width: 70px;"></div> <!-- Spacer to balance the layout -->
</div>

    <!-- FILTER BAR -->
    <form method="GET" class="filter-bar">
        <div class="filter-group">
            <label><i class="bi bi-funnel"></i> Status</label>
            <select name="status" class="filter-select" onchange="this.form.submit()">
                <option value="all" <?= $filterStatus === 'all' ? 'selected' : '' ?>>All Assignments</option>
                <option value="pending" <?= $filterStatus === 'pending' ? 'selected' : '' ?>>Pending</option>
                <option value="submitted" <?= $filterStatus === 'submitted' ? 'selected' : '' ?>>Submitted</option>
                <option value="graded" <?= $filterStatus === 'graded' ? 'selected' : '' ?>>Graded</option>
                <option value="overdue" <?= $filterStatus === 'overdue' ? 'selected' : '' ?>>Overdue</option>
            </select>
        </div>
        <div class="filter-group">
            <label><i class="bi bi-sort-down"></i> Sort By</label>
            <select name="sort" class="filter-select" onchange="this.form.submit()">
                <option value="due_date_asc" <?= $sortBy === 'due_date_asc' ? 'selected' : '' ?>>Due Date (Earliest First)</option>
                <option value="due_date_desc" <?= $sortBy === 'due_date_desc' ? 'selected' : '' ?>>Due Date (Latest First)</option>
                <option value="newest" <?= $sortBy === 'newest' ? 'selected' : '' ?>>Newest First</option>
                <option value="oldest" <?= $sortBy === 'oldest' ? 'selected' : '' ?>>Oldest First</option>
            </select>
        </div>
        <a href="my_assignments.php" class="btn-reset"><i class="bi bi-x"></i> Reset</a>
    </form>

    <!-- ASSIGNMENTS LIST -->
    <div class="assignment-list">
        <?php if (empty($assignments)): ?>
            <div class="empty-state">
                <i class="bi bi-journal-x"></i>
                <p>No assignments found.</p>
                <small>Book a class to receive assignments from your tutor</small>
            </div>
        <?php else: ?>
            <?php foreach ($assignments as $assignment): ?>
                <?php 
                $status = $assignment['assignment_status'];
                $canSubmit = ($status === 'pending' || $status === 'pending_late_allowed' || $status === 'no_due_date');
                
                // Parse tutor's materials (multiple files and URLs)
                $materialUrls = !empty($assignment['material_url']) ? explode('|', $assignment['material_url']) : [];
                $filePaths = !empty($assignment['file_path']) ? explode('|', $assignment['file_path']) : [];
                $fileNames = !empty($assignment['file_name']) ? explode('|', $assignment['file_name']) : $filePaths;
                
                // Parse student's submission
                $hasSubmission = !empty($assignment['submission_id']);
                $submissionFiles = !empty($assignment['submission_file_path']) ? explode('|', $assignment['submission_file_path']) : [];
                $submissionFileNames = !empty($assignment['submission_file_name']) ? explode('|', $assignment['submission_file_name']) : $submissionFiles;
                ?>
                <div class="assignment-card">
                    <div class="assign-header">
                        <div class="assign-title">
                            <h3><?= e($assignment['title']) ?></h3>
                        </div>
                        <?= getStatusBadge($status) ?>
                    </div>
                    
                    <div class="assign-meta">
                        <span class="meta-item"><i class="bi bi-person"></i> <?= e($assignment['tutor_name']) ?></span>
                        <span class="meta-item"><i class="bi bi-calendar3"></i> Due: <?= formatDueDate($assignment['due_date']) ?></span>
                        <span class="meta-item"><i class="bi bi-star"></i> <?= e($assignment['total_points']) ?> pts</span>
                        <span class="meta-item"><i class="bi bi-translate"></i> <?= e($assignment['language']) ?></span>
                    </div>

                    <?php if (!empty($assignment['description'])): ?>
                    <div class="assign-description">
                        <i class="bi bi-chat-text-fill" style="color:var(--pink); margin-right:8px;"></i>
                        <?= nl2br(e($assignment['description'])) ?>
                    </div>
                    <?php endif; ?>
                    <br>
                   <!-- SECTION 1: TUTOR'S ASSIGNMENT MATERIALS (ALWAYS SHOW) -->
<?php 
// Parse tutor's materials
$materialUrls = !empty($assignment['material_url']) ? explode('|', $assignment['material_url']) : [];
$filePaths = !empty($assignment['file_path']) ? explode('|', $assignment['file_path']) : [];
$fileNames = !empty($assignment['file_name']) ? explode('|', $assignment['file_name']) : $filePaths;

// Remove any null/empty values
$materialUrls = array_filter($materialUrls, function($val) { return !empty($val) && $val != 'null'; });
$filePaths = array_filter($filePaths, function($val) { return !empty($val) && $val != 'null'; });
?>

<?php if (!empty($materialUrls) || !empty($filePaths)): ?>
<div style="margin-bottom:16px">
    <div class="section-title">
        <i class="bi bi-paperclip" style="color:var(--hot-pink)"></i> Assignment Attachments (from tutor)
    </div>
    <div class="attachments-grid" style="margin-left:20px;">
        <?php foreach ($materialUrls as $url): ?>
            <a href="<?= e($url) ?>" target="_blank" class="attach-btn">
                <i class="bi bi-link-45deg"></i> <?= htmlspecialchars(parse_url($url, PHP_URL_HOST) ?: 'Link') ?>
            </a>
        <?php endforeach; ?>
        <?php foreach ($filePaths as $idx => $path): ?>
            <a href="../uploads/assignments/<?= e($path) ?>" download class="attach-btn">
                <i class="bi bi-download"></i> <?= e($fileNames[$idx] ?? 'File') ?>
            </a>
        <?php endforeach; ?>
    </div>
</div>
<?php else: ?>
<div style="margin-bottom:16px; color:#94a3b8; font-size:12px;">
    <i class="bi bi-info-circle"></i> No materials provided for this assignment.
</div>
<?php endif; ?><br>
                    <!-- SECTION 2: STUDENT'S SUBMISSION (if submitted) -->
                    <?php if ($hasSubmission): ?>
                    <div style="margin-bottom:16px">
                        <div class="section-title">
                            <i class="bi bi-cloud-upload" style="color:var(--hot-pink)"></i> My Submission
                        </div>
                        <div class="my-submission-box">
                            <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin-bottom:8px">
                                <span style="font-size:10px;">Submitted on <?= date('d M Y, g:i A', strtotime($assignment['submitted_at'])) ?></span>
                                <?= getSubmissionTiming($assignment['submitted_at'], $assignment['due_date']) ?>
                            </div>
                            
                            <?php if (!empty($assignment['submission_text'])): ?>
                                <div style="margin-top:8px;padding:8px;background:white;border-radius:8px">
                                    <strong>Your answer:</strong>
                                    <p style="margin-top:4px;font-size:12px"><?= nl2br(e($assignment['submission_text'])) ?></p>
                                </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($submissionFiles)): ?>
                                <div class="attachments-grid" style="margin-top:8px">
                                    <?php foreach ($submissionFiles as $idx => $path): ?>
                                        <?php if (!empty($path)): ?>
                                            <a href="../uploads/assignments/submission/<?= e($path) ?>" target="_blank" class="attach-btn">
                                                <i class="bi bi-eye"></i> <?= e($submissionFileNames[$idx] ?? 'Submission File') ?>
                                            </a>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="submission-area">
                        <?php if ($status === 'graded'): ?>
                            <div class="grade-info">
                                <div style="text-align:center;margin-bottom:8px">
                                    <span style="font-size:28px;font-weight:900;color:var(--hot-pink)"><?= e($assignment['grade']) ?></span>
                                    <span>/ <?= e($assignment['total_points']) ?> points</span>
                                </div>
                                <?php if ($assignment['feedback']): ?>
                                    <div style="margin-top:8px;padding:8px;background:white;border-radius:8px">
                                        <strong><i class="bi bi-chat-dots"></i> Tutor Feedback:</strong>
                                        <p style="margin-top:4px;font-size:12px"><?= nl2br(e($assignment['feedback'])) ?></p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php elseif ($status === 'submitted'): ?>
                            <div class="submission-info">
                                <i class="bi bi-clock-history"></i> <strong>Awaiting Review</strong>
                                <div style="margin-top:6px">Your submission has been received. The tutor will review and grade it soon.</div>
                            </div>
                        <?php elseif ($status === 'overdue_no_submit'): ?>
                            <div class="submission-info" style="background:#fef2f2">
                                <i class="bi bi-exclamation-triangle-fill" style="color:#dc2626"></i>
                                <strong>Assignment Overdue</strong>
                                <p style="margin-top:6px">The deadline has passed and late submissions are not allowed.</p>
                            </div>
                        <?php elseif ($canSubmit): ?>
                            <div class="submission-info" style="background:#fff9e6">
                                <i class="bi bi-info-circle"></i> Complete your assignment and submit it before the deadline if had been set by the tutor.
                            </div>
                            <button class="btn-action primary" onclick="openSubmissionModal(<?= $assignment['id'] ?>, '<?= e(addslashes($assignment['title'])) ?>')">
                                <i class="bi bi-cloud-upload"></i> Submit Assignment
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Submission Modal -->
<div id="submissionModal" style="display:none;">
    <div class="modal-overlay" onclick="closeModal()">
        <div class="modal-container" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h3><i class="bi bi-cloud-upload"></i> Submit Assignment</h3>
                <button class="modal-close" onclick="closeModal()" style="background:none;border:none;font-size:24px;cursor:pointer">&times;</button>
            </div>
            <form id="submissionForm" method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="submit_assignment">
                <input type="hidden" name="assignment_id" id="submitAssignmentId">
                <div class="modal-body">
                    <div class="form-group" style="margin-bottom:20px">
                        <label style="font-weight:700">Your Answer / Submission</label>
                        <textarea name="submission_text" rows="5" class="form-control" placeholder="Write your answer here..."></textarea>
                    </div>
                    <div class="form-group">
                        <label style="font-weight:700">Attachment (Optional)</label>
                        <input type="file" name="submission_file" class="form-control" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.txt,.zip">
                        <small style="color:var(--muted)">Max 50MB. Allowed: PDF, DOC, DOCX, JPG, PNG, TXT, ZIP</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-action outline" onclick="closeModal()">Cancel</button>
                    <button type="submit" class="btn-action primary">Submit Assignment</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="toast" id="toast"></div>

<script>
let toastTimer;
function showToast(msg, isError = false) {
    const toast = document.getElementById('toast');
    toast.textContent = msg;
    toast.classList.add('show');
    if (isError) toast.classList.add('error');
    else toast.classList.remove('error');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => toast.classList.remove('show'), 3000);
}

function toggleDropdown() {
    const dd = document.getElementById('profileDropdown');
    dd.style.display = dd.style.display === 'none' ? 'block' : 'none';
}

document.addEventListener('click', function(e) {
    const btn = document.querySelector('.profile');
    const dd = document.getElementById('profileDropdown');
    if (btn && dd && !btn.contains(e.target) && !dd.contains(e.target)) {
        dd.style.display = 'none';
    }
});

function openSubmissionModal(assignmentId, title) {
    document.getElementById('submitAssignmentId').value = assignmentId;
    document.getElementById('submissionModal').style.display = 'flex';
}

function closeModal() {
    document.getElementById('submissionModal').style.display = 'none';
}

document.getElementById('submissionForm')?.addEventListener('submit', async function(e) {
    e.preventDefault();
    const formData = new FormData(this);
    showToast('Uploading submission...');
    try {
        const response = await fetch('submit_assignment.php', { method: 'POST', body: formData });
        const data = await response.json();
        if (data.success) {
            showToast('Assignment submitted successfully!');
            setTimeout(() => location.reload(), 1500);
        } else {
            showToast(data.error || 'Submission failed', true);
        }
    } catch (error) {
        showToast('Error uploading submission', true);
    }
});
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