<?php
session_start();
include 'config.php';
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
$profilePic = !empty($user['profile_pic']) ? '../uploads/profiles/' . $user['profile_pic'] : $assetBase . '/profile-student.png';

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
    WHEN s.id IS NOT NULL AND s.status = 'graded' THEN 'graded'
    WHEN s.id IS NOT NULL THEN 'submitted'
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
        $query .= " AND s.id IS NOT NULL AND s.status != 'graded'";
    } elseif ($filterStatus === 'pending') {
        $query .= " AND s.id IS NULL AND a.due_date >= NOW()";
    } elseif ($filterStatus === 'overdue') {
        $query .= " AND s.id IS NULL AND a.due_date < NOW()";
    } elseif ($filterStatus === 'graded') {
        $query .= " AND s.status = 'graded'";
    }
}

// Add sorting
if ($sortBy === 'due_date_asc') {
    $query .= " ORDER BY a.due_date ASC, a.created_at ASC";
} elseif ($sortBy === 'due_date_desc') {
    $query .= " ORDER BY a.due_date DESC, a.created_at DESC";
} elseif ($sortBy === 'newest') {
    $query .= " ORDER BY a.created_at DESC";
} elseif ($sortBy === 'oldest') {
    $query .= " ORDER BY a.created_at ASC";
}

$stmt = $conn->prepare($query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$assignments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get statistics
$statsQuery = "
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN s.id IS NOT NULL AND s.status = 'graded' THEN 1 ELSE 0 END) as graded,
        SUM(CASE WHEN s.id IS NOT NULL AND s.status != 'graded' THEN 1 ELSE 0 END) as submitted,
        SUM(CASE WHEN s.id IS NULL AND a.due_date >= NOW() THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN s.id IS NULL AND a.due_date < NOW() AND a.allow_late_submission = 0 THEN 1 ELSE 0 END) as overdue,
        SUM(CASE WHEN s.id IS NULL AND a.due_date < NOW() AND a.allow_late_submission = 1 THEN 1 ELSE 0 END) as late_allowed
    FROM assignments a
    JOIN bookings b ON a.booking_id = b.id
    LEFT JOIN assignment_submissions s ON a.id = s.assignment_id AND s.student_id = ?
    WHERE b.student_id = ?
";
$statsStmt = $conn->prepare($statsQuery);
$statsStmt->bind_param("ii", $userID, $userID);
$statsStmt->execute();
$stats = $statsStmt->get_result()->fetch_assoc();

function e($v) { 
    return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); 
}

function getStatusBadge($status) {
    if ($status === 'graded') {
        return '<span class="badge graded"><i class="bi bi-trophy-fill"></i> Graded</span>';
    } elseif ($status === 'submitted') {
        return '<span class="badge submitted"><i class="bi bi-clock-history"></i> Awaiting Review</span>';
    } elseif ($status === 'overdue_no_submit') {
        return '<span class="badge overdue"><i class="bi bi-exclamation-triangle-fill"></i> Overdue (No Submission)</span>';
    } elseif ($status === 'pending_late_allowed') {
        return '<span class="badge late-allowed"><i class="bi bi-clock"></i> Late Submission Allowed</span>';
    } else {
        return '<span class="badge pending"><i class="bi bi-hourglass-split"></i> Pending</span>';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Assignments · Kyoshi</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
    <style>
        :root{
            --cream:#FFF1F6;--paper:rgba(255,255,255,.88);--ink:#342635;--muted:#7B6178;
            --pink:#F28AB2;--pink-dark:#C94F86;--hot-pink:#E75A9B;
            --shadow:0 18px 45px rgba(201,79,134,.16);--radius-xl:32px;--radius-lg:24px;
        }
        *{box-sizing:border-box}html{scroll-behavior:smooth}
        body{margin:0;min-height:100vh;font-family:"Segoe UI",Arial,sans-serif;color:var(--ink);
            background:linear-gradient(120deg,rgba(255,241,246,.74),rgba(255,203,220,.30)),
            url("../assets/img/background3.jpg") center/cover fixed no-repeat;}
        body::before{content:"";position:fixed;inset:0;pointer-events:none;z-index:-1;
            background:radial-gradient(circle at 7% 10%,rgba(231,90,155,.32),transparent 24%),
            radial-gradient(circle at 90% 8%,rgba(255,195,216,.42),transparent 26%)}
        a{text-decoration:none;color:inherit}button,input,select{font-family:inherit}
        .container{width:min(1440px,calc(100% - 40px));margin:0 auto}

        .topbar{position:sticky;top:0;z-index:50;background:rgba(255,241,246,.86);backdrop-filter:blur(20px);border-bottom:1px solid rgba(231,90,155,.18);box-shadow:0 10px 30px rgba(201,79,134,.10)}
        .nav{min-height:78px;display:grid;grid-template-columns:190px minmax(0,1fr) 360px;gap:16px;align-items:center;}
        .brand{display:flex;align-items:center;gap:10px}
        .brand img{width:44px;height:44px;object-fit:contain;border-radius:14px}
        .brand strong{display:block;font-size:18px;line-height:1.05}
        .brand span{display:block;margin-top:3px;font-size:11px;color:var(--muted);white-space:nowrap}
        .nav-links{display:flex;align-items:center;justify-content:center;gap:6px;border-radius:999px;padding:7px;overflow:auto;scrollbar-width:none;}
        .nav-links::-webkit-scrollbar{display:none}
        .nav-links a{flex:0 0 auto;padding:9px 12px;border-radius:999px;font-size:13px;font-weight:900;color:#6D4964;white-space:nowrap;transition:.18s ease}
        .nav-links a.active,.nav-links a:hover{background:linear-gradient(135deg,var(--hot-pink),var(--pink));color:#fff;box-shadow:0 8px 18px rgba(231,90,155,.28)}
        .nav-actions{display:flex;align-items:center;gap:10px}
        .profile{display:flex;align-items:center;gap:9px;border-radius:999px;padding:6px 12px 6px 6px;font-weight:900;color:#7A3D65;border:1px solid rgba(46,42,59,.08);background:rgba(255,255,255,.88);cursor:pointer}
        .profile img{width:34px;height:34px;object-fit:cover;border-radius:50%}

        .back-link{display:inline-flex;align-items:center;gap:6px;color:var(--pink-dark);font-weight:900;font-size:13px;padding:9px 16px;border-radius:999px;background:rgba(255,255,255,.78);border:1px solid rgba(46,42,59,.08);transition:.18s ease;margin-bottom:20px;}
        .back-link:hover{transform:translateY(-1px)}

        /* SUMMARY CARDS */
        .summary-grid{display:grid;grid-template-columns:repeat(5,1fr);gap:20px;margin-bottom:24px}
        .sum-card{background:var(--paper);border:1px solid rgba(255,255,255,.55);box-shadow:var(--shadow);border-radius:var(--radius-lg);padding:20px 24px;text-align:center}
        .sum-value{font-size:28px;font-weight:900;color:var(--ink);display:block;line-height:1}
        .sum-label{font-size:12px;font-weight:700;color:var(--muted);margin-top:8px;display:block}
        .sum-card.total .sum-value{color:var(--hot-pink)}
        .sum-card.pending .sum-value{color:#A06B00}
        .sum-card.submitted .sum-value{color:#0284c7}
        .sum-card.graded .sum-value{color:#3D7047}
        .sum-card.overdue .sum-value{color:#dc2626}

        /* FILTER BAR */
        .filter-bar{background:var(--paper);border:1px solid rgba(255,255,255,.55);box-shadow:0 6px 20px rgba(201,79,134,.08);border-radius:var(--radius-lg);padding:20px 24px;margin-bottom:20px;display:flex;flex-wrap:wrap;gap:16px;align-items:flex-end;}
        .filter-group{display:flex;flex-direction:column;gap:6px;flex:1;min-width:150px}
        .filter-group label{font-size:12px;font-weight:900;color:var(--muted);text-transform:uppercase;letter-spacing:.5px}
        .filter-select{padding:11px 15px;border:1px solid rgba(46,42,59,.12);border-radius:14px;outline:none;font-size:14px;font-weight:700;color:var(--ink);background:rgba(255,255,255,.9)}
        .btn-reset{padding:11px 22px;border-radius:999px;border:1px solid rgba(46,42,59,.12);background:none;color:var(--muted);font-size:14px;font-weight:900;cursor:pointer;white-space:nowrap;align-self:flex-end}
        .btn-reset:hover{background:rgba(255,255,255,.88)}

        /* ASSIGNMENT CARDS */
        /* ASSIGNMENT CARDS - COMPACT LIKE MATERIALS */
        .badge{display:inline-flex;align-items:center;gap:8px;padding:8px 16px;border-radius:999px;font-size:12px;font-weight:900}
        .badge.pending{background:rgba(255,241,200,.78);color:#A06B00}
        .badge.submitted{background:rgba(56,189,248,.12);color:#0284c7}
        .badge.graded{background:rgba(215,238,219,.78);color:#3D7047}
        .badge.overdue{background:rgba(255,200,200,.78);color:#dc2626}

        .assign-description{background:rgba(255,241,246,.3);border-radius:16px;padding:16px 20px;margin-bottom:20px;color:#475569;line-height:1.6}
        .assign-attachment{margin-bottom:20px}
        .attach-btn{display:inline-flex;align-items:center;gap:8px;padding:8px 16px;background:rgba(255,255,255,.9);border-radius:40px;font-size:13px;font-weight:700;color:var(--pink-dark);border:1px solid rgba(201,79,134,.2)}
        
        .submission-area{margin-top:20px;padding-top:20px;border-top:1px solid rgba(46,42,59,.06)}
        .submission-info{background:rgba(56,189,248,.08);border-radius:16px;padding:16px;margin-bottom:16px}
        .grade-info{background:rgba(215,238,219,.3);border-radius:16px;padding:16px;margin-top:16px}
        
        .btn-action{padding:11px 22px;border-radius:999px;font-size:13px;font-weight:900;cursor:pointer;display:inline-flex;align-items:center;gap:8px;transition:.15s ease;text-decoration:none;border:none}
        .btn-action:hover{transform:translateY(-2px)}
        .btn-action.primary{background:linear-gradient(135deg,var(--hot-pink),var(--pink));color:white;box-shadow:0 6px 14px rgba(231,90,155,.25)}
        .btn-action.ghost{background:white;color:var(--pink-dark);border:1px solid rgba(201,79,134,.25)}
        .btn-action.outline{background:transparent;border:1px solid var(--pink-dark);color:var(--pink-dark)}

        .empty-state{padding:60px 30px;border-radius:var(--radius-lg);background:var(--paper);border:1px dashed rgba(46,42,59,.16);color:#6D647C;text-align:center;font-weight:700}
        .empty-state i{font-size:48px;color:rgba(231,90,155,.3);display:block;margin-bottom:16px}

        /* MODAL */
        .modal-overlay{position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:1000;display:flex;align-items:center;justify-content:center}
        .modal-container{background:white;border-radius:var(--radius-lg);max-width:650px;width:90%;max-height:90vh;overflow-y:auto}
        .modal-header{padding:20px 24px;border-bottom:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center}
        .modal-header h2{font-size:20px;font-weight:800;color:var(--ink)}
        .modal-close{background:none;border:none;font-size:28px;cursor:pointer;color:#94a3b8}
        .modal-body{padding:24px}
        .modal-footer{padding:16px 24px;border-top:1px solid #e2e8f0;display:flex;justify-content:flex-end;gap:12px}
        .form-group{margin-bottom:20px}
        .form-group label{font-weight:800;display:block;margin-bottom:8px;color:var(--ink)}
        .form-control{width:100%;padding:12px;border-radius:14px;border:1px solid rgba(46,42,59,.12);font-family:inherit}
        .form-control:focus{outline:none;border-color:var(--hot-pink)}
        .badge.late-allowed {
            background: rgba(168,85,247,.12);
            color: #9333ea;
        }
        .assignment-list {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(400px, 1fr));
    gap: 16px;
    padding-bottom: 40px;
}

    .assignment-card {
        background: white;
        border-radius: 20px;
        padding: 20px 20px;
        transition: .3s;
        border: 1px solid rgba(231,90,155,.1);
        box-shadow: 0 2px 8px rgba(0,0,0,.04);
    }

    .assignment-card:hover {
        transform: translateY(-3px);
        box-shadow: 0 8px 25px rgba(201,79,134,.12);
        border-color: rgba(231,90,155,.3);
    }

    .assign-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        flex-wrap: wrap;
        gap: 8px;
        margin-bottom: 10px;
    }

    .assign-title h3 {
        margin: 0 0 4px;
        font-size: 16px;
        font-weight: 800;
    }

    .assign-meta {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        margin-top: 4px;
    }

    .meta-item {
        font-size: 15px;
        color: var(--muted);
        display: inline-flex;
        align-items: center;
        gap: 4px;
    }

    .badge {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        padding: 3px 10px;
        border-radius: 20px;
        font-size: 10px;
        font-weight: 700;
        white-space: nowrap;
    }

    .badge.pending {
        background: rgba(255,241,200,.78);
        color: #A06B00;
    }

    .badge.submitted {
        background: rgba(56,189,248,.12);
        color: #0284c7;
    }

    .badge.graded {
        background: rgba(215,238,219,.78);
        color: #3D7047;
    }

    .badge.overdue {
        background: rgba(255,200,200,.78);
        color: #dc2626;
    }

    .badge.late-allowed {
        background: rgba(168,85,247,.12);
        color: #9333ea;
    }

    .assign-description {
        background: rgba(255,241,246,.3);
        border-radius: 12px;
        padding: 10px 12px;
        margin-bottom: 12px;
        font-size: 12px;
        line-height: 1.4;
        color: #475569;
    }

    .assign-attachment {
        margin-bottom: 10px;
    }

    .attach-btn {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 4px 10px;
        background: rgba(255,255,255,.9);
        border-radius: 25px;
        font-size: 11px;
        font-weight: 600;
        color: var(--pink-dark);
        border: 1px solid rgba(201,79,134,.2);
        text-decoration: none;
    }

    .submission-area {
        margin-top: 10px;
        padding-top: 10px;
        border-top: 1px solid rgba(46,42,59,.06);
    }

    .submission-info {
        background: rgba(56,189,248,.08);
        border-radius: 10px;
        padding: 8px 12px;
        margin-bottom: 10px;
        font-size: 11px;
    }

    .grade-info {
        background: rgba(215,238,219,.3);
        border-radius: 10px;
        padding: 10px 14px;
        margin-top: 10px;
        font-size: 12px;
    }

    .btn-action {
        padding: 6px 14px;
        border-radius: 25px;
        font-size: 11px;
        font-weight: 700;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 5px;
        transition: .15s ease;
        text-decoration: none;
        border: none;
    }

    .btn-action.primary {
        background: linear-gradient(135deg, var(--hot-pink), var(--pink));
        color: white;
    }

    .btn-action.outline {
        background: transparent;
        border: 1px solid var(--pink-dark);
        color: var(--pink-dark);
    }
        .toast {
            position: fixed;
            left: 50%;
            bottom: 30px;
            transform: translateX(-50%);
            background: #8E3F70;
            color: white;
            padding: 12px 24px;
            border-radius: 40px;
            font-size: 14px;
            font-weight: 600;
            opacity: 0;
            pointer-events: none;
            z-index: 10001;
            transition: opacity 0.2s ease;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
            text-align: center;
            white-space: nowrap;
            max-width: 90%;
        }
        .toast.show {
            opacity: 1;
        }
        .toast.error {
            background: #dc2626;
        }
                @media(max-width:600px){
            .summary-grid{grid-template-columns:repeat(3,1fr)}
            .nav{flex-wrap:wrap}
            .nav-links{order:3;width:100%;margin-top:12px}
        }
        @media(max-width:600px){
            .summary-grid{grid-template-columns:repeat(2,1fr)}
            .assign-header{flex-direction:column;gap:12px}
        }
    </style>
</head>
<body>

<header class="topbar">
    <div class="container">
        <nav class="nav">
            <a href="student_dashboard.php" class="brand">
                <img src="<?= e($assetBase) ?>/logo.png" alt="Kyoshi logo">
                <div>
                    <strong>Kyoshi</strong>
                    <span>Student Learning Space</span>
                </div>
            </a>

            <div class="nav-links">
                <a href="student_dashboard.php">Home</a>
                <a href="find_language.php">Find Language</a>
                <a href="booking_status.php">My Bookings</a>
                <a href="my_payments.php">My Payments</a>
                <a href="my_materials.php">My Materials</a>
                <a href="my_assignments.php" class="active">My Assignments</a>
            </div>
            <div class="nav-actions" style="display:flex;align-items:center;justify-content:flex-end;gap:10px;margin-left:auto;">
                <div style="position:relative;">
                    <button class="profile" onclick="toggleDropdown()" id="profileBtn">
                        <img src="<?= e($profilePic) ?>" alt="Student profile">
                        <span><?= e($displayName) ?></span>
                        <i class="bi bi-chevron-down" style="font-size:11px; margin-left:4px;"></i>
                    </button>
                    <div id="profileDropdown" style="display:none;position:absolute;top:calc(100% + 10px);right:0;background:white;border-radius:16px;box-shadow:0 18px 45px rgba(201,79,134,.2);border:1px solid rgba(242,138,178,.2);min-width:180px;overflow:hidden;z-index:100;">
                        <a href="student_profile.php" style="display:flex;align-items:center;gap:10px;padding:14px 16px;font-size:14px;font-weight:700;color:#342635;">
                            <i class="bi bi-person-circle" style="color:#E75A9B;"></i> My Profile
                        </a>
                        <a href="my_progress.php" style="display:flex;align-items:center;gap:10px;padding:14px 16px;font-size:14px;font-weight:700;color:#342635;">
                            <i class="bi bi-bar-chart-steps" style="color:#E75A9B;"></i> My Progress
                        </a>
                        <a href="student_favourites.php" style="display:flex;align-items:center;gap:10px;padding:14px 16px;font-size:14px;font-weight:700;color:#342635;">
                            <i class="bi bi-heart" style="color:#E75A9B;"></i> My Favourites
                        </a>
                        <hr style="margin:4px 0;border-color:rgba(242,138,178,.2);">
                        <a href="logout.php" style="display:flex;align-items:center;gap:10px;padding:14px 16px;font-size:14px;font-weight:700;color:#dc2626;">
                            <i class="bi bi-box-arrow-right"></i> Logout
                        </a>
                    </div>
                </div>
            </div>
        </nav>
    </div>
</header>

<div class="container" style="padding:24px 0 60px;">
    <!-- Header -->
    <div style="position:relative;text-align:center;margin-bottom:20px;">
        <a href="student_dashboard.php" class="back-link" style="position:absolute;left:0;top:50%;transform:translateY(-50%);margin:0;">
            <i class="bi bi-arrow-left"></i> Back
        </a>
        <h1 style="margin:0;font-size:32px;letter-spacing:-.6px;"><i class="bi bi-journal-bookmark-fill"></i> My Assignments</h1>
        <p style="margin:8px 0 0;color:var(--muted);font-size:15px;">Track, submit, and get feedback on your assignments</p>
    </div>



    <!-- FILTER BAR -->
    <form method="GET" class="filter-bar">
        <div class="filter-group">
            <label><i class="bi bi-funnel"></i> Status</label>
            <select name="status" class="filter-select" onchange="this.form.submit()">
                <option value="all" <?= $filterStatus === 'all' ? 'selected' : '' ?>>All</option>
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
                No assignments found.<br>
                <a href="booking_status.php" style="color:var(--hot-pink);font-weight:900;margin-top:12px;display:inline-block;">Book a class to get assignments →</a>
            </div>
        <?php else: ?>
            <?php foreach ($assignments as $assignment):
                $status = $assignment['assignment_status'];
                $canSubmit = ($status === 'pending' && strtotime($assignment['due_date']) >= time());
                $hasMaterial = !empty($assignment['material_url']) || !empty($assignment['file_path']);
            ?>
            <div class="assignment-card">
                <div class="assign-header">
    <div style="display: flex; justify-content: space-between; align-items: center; width: 100%;">
        <h3 style="margin: 0; font-size: 16px; font-weight: 800;"><?= e($assignment['title']) ?></h3>
        <?= getStatusBadge($status) ?>
    </div>
    <div class="assign-meta" style="margin-top: 8px;">
        <span class="meta-item"><i class="bi bi-person"></i> <?= e($assignment['tutor_name']) ?></span>
        <span class="meta-item"><i class="bi bi-calendar3"></i> Due: <?= date('d M Y', strtotime($assignment['due_date'])) ?></span>
        <span class="meta-item"><i class="bi bi-star"></i> <?= e($assignment['total_points']) ?> pts</span>
        <span class="meta-item"><i class="bi bi-translate"></i> <?= e($assignment['language']) ?></span>
    </div>
</div>

                <div class="assign-description">
                    <i class="bi bi-chat-text-fill" style="color:var(--pink); margin-right:8px;"></i>
                    <?= nl2br(e($assignment['description'])) ?>
                </div>

                <?php if ($hasMaterial): ?>
                <div class="assign-attachment">
                    <i class="bi bi-paperclip"></i> <strong>Assignment Materials:</strong>
                    <?php if (!empty($assignment['material_url'])): ?>
                        <a href="<?= e($assignment['material_url']) ?>" target="_blank" class="attach-btn">
                            <i class="bi bi-link"></i> View Material Link
                        </a>
                    <?php endif; ?>
                    <?php if (!empty($assignment['file_path'])): ?>
                        <a href="../uploads/assignments/<?= e($assignment['file_path']) ?>" download class="attach-btn">
                            <i class="bi bi-download"></i> Download Attachment
                        </a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <div class="submission-area">
    <?php if ($status === 'graded'): ?>
    <!-- Show Grade & Feedback -->
    <div class="grade-info">
        <div style="text-align: center; margin-bottom: 12px;">
            <span style="font-size: 32px; font-weight: 900; color: var(--hot-pink);"><?= e($assignment['grade']) ?></span>
            <span style="font-size: 16px; color: var(--muted);"> / <?= e($assignment['total_points']) ?> points</span>
            <div style="margin-top: 5px;">
                <span class="badge graded"><i class="bi bi-trophy-fill"></i> Grade Released</span>
            </div>
        </div>
        <?php if ($assignment['feedback']): ?>
            <div style="margin-top: 12px; padding: 12px; background: white; border-radius: 12px;">
                <strong><i class="bi bi-chat-dots"></i> Feedback:</strong>
                <p style="margin-top: 6px; font-size: 13px;"><?= nl2br(e($assignment['feedback'])) ?></p>
            </div>
        <?php endif; ?>
        <div style="margin-top: 10px; padding: 8px 12px; background: rgba(56,189,248,.08); border-radius: 10px; font-size: 11px;">
            <i class="bi bi-check-circle"></i> Submitted: <?= date('d M Y, g:i A', strtotime($assignment['submitted_at'])) ?>
        </div>
    </div>
    <?php elseif ($status === 'submitted'): ?>
        <!-- Show Submitted Info -->
        <div class="submission-info">
            <i class="bi bi-clock-history"></i> <strong>Submitted on </strong> <?= date('d M Y, g:i A', strtotime($assignment['submitted_at'])) ?>
            <div style="margin-top: 8px; color: #0284c7;">
                <i class="bi bi-hourglass-split"></i> Your assignment has been submitted and is awaiting review.
            </div>
        </div>
        <?php if ($assignment['submission_file_name']): ?>
    <a href="../uploads/assignments/submission/<?= e($assignment['submission_file_path']) ?>" target="_blank" class="btn-action outline" style="margin-top: 8px;">
        <i class="bi bi-eye"></i> View My Submission
    </a>
<?php endif; ?>
    <?php elseif ($status === 'overdue_no_submit'): ?>
        <!-- Overdue - Cannot Submit (Tutor disabled late submissions) -->
        <div class="grade-info" style="background: rgba(220,38,38,.08);">
            <i class="bi bi-exclamation-triangle-fill" style="color: #dc2626;"></i>
            <strong>Assignment Overdue</strong>
            <p style="margin-top: 8px;">The submission deadline has passed and late submissions are not allowed for this assignment.</p>
            <p style="margin-top: 8px; font-size: 13px;">Please contact your tutor if you have any questions.</p>
        </div>
    <?php elseif ($status === 'pending_late_allowed'): ?>
        <!-- Late Submission Allowed -->
        <div class="submission-info" style="background: rgba(168,85,247,.08);">
            <i class="bi bi-clock"></i> <strong>Late Submission Allowed</strong>
            <p style="margin-top: 4px;">The due date has passed, but your tutor allows late submissions.</p>
        </div>
        <button class="btn-action primary" onclick="openSubmissionModal(<?= $assignment['id'] ?>, '<?= e(addslashes($assignment['title'])) ?>')">
            <i class="bi bi-cloud-upload"></i> Submit Assignment (Late)
        </button>
    <?php else: ?>
        <!-- Pending - Can Submit -->
        <div class="submission-info" style="background: rgba(255,241,200,.3);">
            <i class="bi bi-info-circle"></i> Complete your assignment and submit it before the due date.
        </div>
        <button class="btn-action primary" onclick="openSubmissionModal(<?= $assignment['id'] ?>, '<?= e(addslashes($assignment['title'])) ?>')">
            <i class="bi bi-cloud-upload"></i> Submit Assignment
        </button>
    <?php endif; ?>
</div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Submission Modal -->
<div id="submissionModal" style="display: none;">
    <div class="modal-overlay" onclick="closeModal()">
        <div class="modal-container" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h2><i class="bi bi-cloud-upload"></i> Submit Assignment</h2>
                <button class="modal-close" onclick="closeModal()">&times;</button>
            </div>
            <form id="submissionForm" method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="submit_assignment">
                <input type="hidden" name="assignment_id" id="submitAssignmentId">
                <div class="modal-body">
                    <div class="form-group">
                        <label>Your Answer / Submission</label>
                        <textarea name="submission_text" rows="6" class="form-control" placeholder="Write your answer here..."></textarea>
                    </div>
                    <div class="form-group">
                        <label>Attachment (Optional)</label>
                       <input type="file" name="submission_file" class="form-control" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.txt,.zip,.mp3,.mp4,.wav,.ogg,.mov,.avi,.mkv">
<small style="color:var(--muted);">PDF, DOC, JPG, PNG, TXT, ZIP, MP3, MP4, MOV, AVI (Max 50MB)</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-action ghost" onclick="closeModal()">Cancel</button>
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
    toast.style.background = isError ? '#dc2626' : '#8E3F70';
    toast.classList.add('show');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => toast.classList.remove('show'), 3000);
}

function toggleDropdown() {
    const dd = document.getElementById('profileDropdown');
    dd.style.display = dd.style.display === 'none' ? 'block' : 'none';
}

document.addEventListener('click', function(e) {
    const btn = document.getElementById('profileBtn');
    const dd = document.getElementById('profileDropdown');
    if (btn && dd && !btn.contains(e.target) && !dd.contains(e.target)) {
        dd.style.display = 'none';
    }
});

function openSubmissionModal(assignmentId, title) {
    document.getElementById('submitAssignmentId').value = assignmentId;
    document.getElementById('submissionModal').style.display = 'block';
    document.body.style.overflow = 'hidden';
}

function closeModal() {
    document.getElementById('submissionModal').style.display = 'none';
    document.body.style.overflow = 'auto';
}

// Submit assignment via AJAX with full validation
document.getElementById('submissionForm')?.addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const submissionText = document.querySelector('textarea[name="submission_text"]').value.trim();
    const fileInput = document.querySelector('input[name="submission_file"]');
    const file = fileInput?.files[0];
    
    // VALIDATION: Must have either text OR file
    if (!submissionText && !file) {
        showToast('Please provide either an answer/text submission or upload a file.', true);
        return;
    }
    
    // Validate file if uploaded
    if (file) {
        // Check file size (max 50MB)
        if (file.size > 50 * 1024 * 1024) {
            showToast('File too large! Maximum size is 50MB', true);
            return;
        }
        
        // Check file type by extension
        const allowedExtensions = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png', 'txt', 'zip', 'mp3', 'mp4', 'wav', 'ogg', 'mov', 'avi', 'mkv'];
        const fileExt = file.name.split('.').pop().toLowerCase();
        if (!allowedExtensions.includes(fileExt)) {
            showToast('File type not allowed! Allowed: ' + allowedExtensions.join(', '), true);
            return;
        }
    }
    
    const formData = new FormData(this);
    
    showToast('Uploading submission...');
    
    try {
        const response = await fetch('submit_assignment.php', {
            method: 'POST',
            body: formData
        });
        const data = await response.json();
        
        if (data.success) {
            showToast('Assignment submitted successfully!');
            setTimeout(() => location.reload(), 1500);
        } else {
            showToast(data.error || 'Submission failed', true);
        }
    } catch (error) {
        console.error('Submission error:', error);
        showToast('Error uploading submission. Please try again.', true);
    }
});

<?php if (isset($_SESSION['success_message'])): ?>
    showToast("<?= $_SESSION['success_message'] ?>");
    <?php unset($_SESSION['success_message']); ?>
<?php endif; ?>
<?php if (isset($_SESSION['error_message'])): ?>
    showToast("<?= $_SESSION['error_message'] ?>", true);
    <?php unset($_SESSION['error_message']); ?>
<?php endif; ?>
</script>
</body>
</html>