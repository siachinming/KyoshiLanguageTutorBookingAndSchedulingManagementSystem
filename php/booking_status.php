<?php
session_start();
include 'config.php';
$assetBase = '../assets/img';

if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit(); }
$userID = $_SESSION['user_id'];

$stmt = $conn->prepare("SELECT * FROM users WHERE id = ? AND role = 'student'");
$stmt->bind_param("i", $userID);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
if (!$user) { header("Location: login.php"); exit(); }

$displayName = $user['fullname'];
$profilePic  = !empty($user['profile_pic']) ? '../uploads/profiles/' . $user['profile_pic'] : $assetBase . '/profile-student.png';

$filterStatus  = $_GET['status'] ?? 'all';
$filterDateFrom = $_GET['date_from'] ?? '';
$filterDateTo   = $_GET['date_to'] ?? '';
$sortBy = $_GET['sort'] ?? 'booked_newest';

$where = "WHERE b.student_id = ?";
$params = [$userID];
$types  = "i";

if ($filterStatus !== 'all' && in_array($filterStatus, ['pending','accepted','confirmed','completed','cancelled','rescheduled'])) {
    $where .= " AND b.status = ?";
    $params[] = $filterStatus;
    $types .= "s";
}
if ($filterDateFrom) {
    $where .= " AND b.booking_date >= ?";
    $params[] = $filterDateFrom;
    $types .= "s";
}
if ($filterDateTo) {
    $where .= " AND b.booking_date <= ?";
    $params[] = $filterDateTo;
    $types .= "s";
}


$orderBy = "ORDER BY b.created_at DESC";

if ($sortBy === 'booked_newest') {
    $orderBy = "ORDER BY b.created_at DESC";
} elseif ($sortBy === 'booked_oldest') {
    $orderBy = "ORDER BY b.created_at ASC";
} elseif ($sortBy === 'session_soonest') {
    $orderBy = "ORDER BY b.booking_date ASC, b.booking_time ASC";
} elseif ($sortBy === 'session_latest') {
    $orderBy = "ORDER BY b.booking_date DESC, b.booking_time DESC";
} elseif ($sortBy === 'price_low') {
    $orderBy = "ORDER BY tp.rate ASC";
} elseif ($sortBy === 'price_high') {
    $orderBy = "ORDER BY tp.rate DESC";
} elseif ($sortBy === 'language') {
    $orderBy = "ORDER BY b.language ASC";
}

$stmt = $conn->prepare("
    SELECT b.id, b.language, b.learning_mode, b.booking_date, b.booking_time,
           b.status, b.focus, b.created_at, b.tutor_id,
           u.fullname AS tutor_name, u.profile_pic AS tutor_pic, tp.rate,
           p.status AS payment_status,
           p.amount AS payment_amount,
           r.id AS rated
    FROM bookings b
    JOIN users u ON b.tutor_id = u.id
    JOIN tutor_profiles tp ON b.tutor_id = tp.user_id
    LEFT JOIN payments p ON p.booking_id = b.id
    LEFT JOIN ratings r ON r.booking_id = b.id AND r.student_id = ?
$where
$orderBy
");
array_unshift($params, $userID);
$types = "i" . $types;
$stmt->bind_param($types, ...$params);
$stmt->execute();
$bookings = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Counts
$countStmt = $conn->prepare("SELECT status, COUNT(*) as cnt FROM bookings WHERE student_id = ? GROUP BY status");
$countStmt->bind_param("i", $userID);
$countStmt->execute();
$countResult = $countStmt->get_result();
$counts = ['all'=>0,'pending'=>0,'accepted'=>0,'confirmed'=>0,'rescheduled'=>0,'completed'=>0,'cancelled'=>0];
while ($row = $countResult->fetch_assoc()) {
    $counts[$row['status']] = $row['cnt'];
    $counts['all'] += $row['cnt'];
}
$counts['all'] = array_sum($counts);

function e($v){ return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }
function statusCfg($s) {
    $s = strtolower(trim($s));

    return [
        'pending' => [
            'label' => 'Pending',
            'icon' => 'bi-hourglass-split',
            'bg' => 'rgba(255,217,199,.74)',
            'color' => '#A35F3F'
        ],
        'accepted' => [
            'label' => 'Accepted',
            'icon' => 'bi-check-circle',
            'bg' => 'rgba(216,236,255,.78)',
            'color' => '#1A5FA8'
        ],
        'confirmed' => [
            'label' => 'Confirmed',
            'icon' => 'bi-shield-check',
            'bg' => 'rgba(215,238,219,.78)',
            'color' => '#3D7047'
        ],
        'rescheduled' => [
            'label' => 'Rescheduled',
            'icon' => 'bi-calendar-plus',
            'bg' => 'rgba(255,241,200,.78)',
            'color' => '#A06B00'
        ],
        'completed' => [
            'label' => 'Completed',
            'icon' => 'bi-patch-check-fill',
            'bg' => 'rgba(221,211,255,.78)',
            'color' => '#7648B8'
        ],
        'cancelled' => [
            'label' => 'Cancelled',
            'icon' => 'bi-x-circle-fill',
            'bg' => 'rgba(255,200,200,.78)',
            'color' => '#C94F4F'
        ],
    ][$s] ?? [
        'label' => 'Unknown',
        'icon' => 'bi-question',
        'bg' => '#eee',
        'color' => '#999'
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>My Bookings · Kyoshi</title>
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
      url("<?= e($assetBase) ?>/background3.jpg") center/cover fixed no-repeat;}
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
    .nav-links{display:flex;align-items:center;justify-content:center;gap:6px;background:rgba(255,255,255,.58);border:1px solid rgba(242,138,178,.18);border-radius:999px;padding:7px;overflow:auto;scrollbar-width:none;}
    .nav-links::-webkit-scrollbar{display:none}
    .nav-links a{flex:0 0 auto;padding:9px 12px;border-radius:999px;font-size:13px;font-weight:900;color:#6D4964;white-space:nowrap;transition:.18s ease}
    .nav-links a.active,.nav-links a:hover{background:linear-gradient(135deg,var(--hot-pink),var(--pink));color:#fff;box-shadow:0 8px 18px rgba(231,90,155,.28)}
    .nav-actions{display:flex;align-items:center;gap:10px}
    .profile{display:flex;align-items:center;gap:9px;border-radius:999px;padding:6px 12px 6px 6px;font-weight:900;color:#7A3D65;border:1px solid rgba(46,42,59,.08);background:rgba(255,255,255,.88);cursor:pointer}
    .profile img{width:34px;height:34px;object-fit:cover;border-radius:50%}

    .page-wrap{padding:28px 0 60px}
    .back-link{display:inline-flex;align-items:center;gap:6px;color:var(--pink-dark);font-weight:900;font-size:13px;padding:9px 16px;border-radius:999px;background:rgba(255,255,255,.78);border:1px solid rgba(46,42,59,.08);transition:.18s ease;margin-bottom:20px;}
    .back-link:hover{transform:translateY(-1px)}
    .page-head{margin-bottom:20px}
    .page-head h1{margin:0 0 4px;font-size:28px;letter-spacing:-.6px}
    .page-head p{margin:0;color:var(--muted);font-size:14px}

    /* FILTERS */
    .filter-bar{background:var(--paper);border:1px solid rgba(255,255,255,.55);box-shadow:0 6px 20px rgba(201,79,134,.08);justify-content:center;border-radius:var(--radius-lg);padding:18px 20px;margin-bottom:20px;display:flex;flex-wrap:wrap;gap:14px;align-items:center;margin-top:20px;}
    .filter-group{display:flex;flex-direction:column;gap:5px;flex:1;min-width:160px}
    .filter-group label{font-size:11px;font-weight:900;color:var(--muted);text-transform:uppercase;letter-spacing:.5px}
    .filter-select,.filter-input{padding:10px 14px;border:1px solid rgba(46,42,59,.12);border-radius:12px;outline:none;font-size:13px;font-weight:700;color:var(--ink);background:rgba(255,255,255,.9);transition:.15s ease}
    .filter-select:focus,.filter-input:focus{border-color:var(--hot-pink);box-shadow:0 0 0 3px rgba(231,90,155,.12)}
    .filter-counts{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
    .count-pill{padding:6px 14px;border-radius:999px;font-size:12px;font-weight:900;background:rgba(255,255,255,.8);border:1px solid rgba(46,42,59,.10);color:var(--muted)}
    .count-pill.active{background:linear-gradient(135deg,var(--hot-pink),var(--pink));color:white;border-color:var(--pink)}
    .btn-reset{
    width:100px;
    height:42px;

    display:flex;
    align-items:center;
    justify-content:center;
    gap:6px;

    padding:0;
    border-radius:999px;
    border:1px solid rgba(46,42,59,.12);

    background:none;
    color:var(--muted);

    font-size:13px;
    font-weight:900;

    cursor:pointer;
    white-space:nowrap;
    transition:.18s ease;
    margin-bottom: 5px;
}

.btn-reset:hover{
    background:rgba(255,255,255,.88);
}
    .filter-right{
    margin-left:auto;
    display:flex;
    flex-direction:column;
    align-items:flex-end;
    gap:8px;
    }

    .sort-select{
        min-width:80px;
    }

    /* CARDS */
    .booking-cards{display:flex;flex-direction:column;gap:16px}
    .booking-card{
    background:var(--paper);border:1px solid rgba(255,255,255,.55);
    box-shadow:0 8px 24px rgba(201,79,134,.10);border-radius:var(--radius-lg);
    padding:20px 24px;transition:.18s ease;
    }
    .booking-card:hover{transform:translateY(-2px);box-shadow:var(--shadow)}

    /* TOP ROW: tutor info + status badge */
    .card-top{display:flex;align-items:center;gap:14px;margin-bottom:14px}
    .tutor-img{width:56px;height:56px;object-fit:cover;border-radius:14px;background:#eee;flex-shrink:0}
    .card-top-info{flex:1;min-width:0}
    .card-top-info h4{margin:0 0 3px;font-size:15px;font-weight:900}
    .card-top-info .sub{margin:0;color:var(--muted);font-size:12px;line-height:1.4}
    .status-badge{display:inline-flex;align-items:center;gap:5px;padding:7px 13px;border-radius:999px;font-size:11px;font-weight:900;white-space:nowrap;flex-shrink:0}

    /* MID ROW: tags */
    .card-tags{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:14px}
    .tag{padding:4px 10px;border-radius:999px;font-size:11px;font-weight:900}
    .tag.mode{background:rgba(221,211,255,.5);color:#7648B8}
    .tag.focus{background:rgba(221,244,230,.6);color:#2D6A42}
    .tag.pay-ok{background:rgba(215,238,219,.78);color:#3D7047}
    .tag.pay-no{background:rgba(255,217,199,.74);color:#A35F3F}

    /* BOTTOM ROW: left meta + right actions */
    .card-bottom{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;padding-top:14px;border-top:1px solid rgba(46,42,59,.06)}
    .card-meta{font-size:12px;color:var(--muted);font-weight:700;display:flex;align-items:center;gap:12px;flex-wrap:wrap}
    .card-meta strong{color:var(--hot-pink);font-size:14px}
    .card-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center}

    /* ACTION BUTTONS — all same size/shape */
    .btn-action{padding:9px 18px;border-radius:999px;font-size:12px;font-weight:900;cursor:pointer;display:inline-flex;align-items:center;gap:6px;transition:.15s ease;text-decoration:none;border:none;white-space:nowrap}
    .btn-action:hover{transform:translateY(-1px)}
    .btn-action.primary{background:linear-gradient(135deg,var(--hot-pink),var(--pink));color:white;box-shadow:0 6px 14px rgba(231,90,155,.25)}
    .btn-action.ghost{background:white;color:var(--pink-dark);border:1px solid rgba(201,79,134,.25)}
    .btn-action.purple{background:rgba(221,211,255,.8);color:#7648B8;border:1px solid rgba(167,123,232,.2)}
    .btn-action.muted{background:rgba(255,255,255,.7);color:var(--muted);border:1px solid rgba(46,42,59,.10);cursor:default}
    .btn-action.muted:hover{transform:none}

    .booking-right{display:flex;flex-direction:column;align-items:flex-end;gap:6px;min-width:110px}
    .status-badge{display:inline-flex;align-items:center;gap:5px;padding:7px 12px;border-radius:999px;font-size:11px;font-weight:900;white-space:nowrap}
    .booking-date-label{font-size:11px;font-weight:700;color:var(--muted);text-align:right}
    .booking-rate{font-size:13px;font-weight:900;color:var(--hot-pink)}
    .payment-label{font-size:11px;font-weight:900;padding:4px 10px;border-radius:999px}
    .payment-label.paid{background:rgba(215,238,219,.78);color:#3D7047}
    .payment-label.unpaid{background:rgba(255,217,199,.74);color:#A35F3F}

    .empty-state{padding:48px 24px;border-radius:var(--radius-lg);background:var(--paper);border:1px dashed rgba(46,42,59,.16);color:#6D647C;text-align:center;font-weight:700}
    .empty-state i{font-size:40px;color:rgba(231,90,155,.3);display:block;margin-bottom:12px}

    .toast{position:fixed;left:50%;bottom:28px;transform:translate(-50%,18px);opacity:0;pointer-events:none;z-index:99;background:#8E3F70;color:#fff;border-radius:999px;padding:12px 18px;font-size:13px;font-weight:900;transition:.2s ease}
    .toast.show{opacity:1;transform:translate(-50%,0)}

    /* CANCEL MODAL */
    .modal-overlay{position:fixed;inset:0;background:rgba(52,38,53,.5);backdrop-filter:blur(6px);z-index:200;display:none;place-items:center;}
    .modal-overlay.show{display:grid}
    .modal-box{background:white;border-radius:24px;padding:28px;max-width:400px;width:calc(100% - 40px);box-shadow:0 30px 60px rgba(201,79,134,.2)}
    .modal-box h3{margin:0 0 10px;font-size:18px}
    .modal-box p{margin:0 0 20px;color:var(--muted);font-size:14px;line-height:1.5}
    .modal-actions{display:flex;gap:10px;justify-content:flex-end}
    .search{position:relative;flex:1 1 auto;min-width:0}
    .search i{position:absolute;left:14px;top:50%;transform:translateY(-50%);color:#91899F}
    .search input{width:100%;border:1px solid rgba(46,42,59,.10);background:rgba(255,255,255,.88);outline:none;border-radius:999px;padding:12px 14px 12px 38px;box-shadow:0 10px 26px rgba(201,79,134,.10)}
    .icon-btn{width:44px;height:44px;border-radius:16px;color:#7A4A68;position:relative;flex:0 0 auto;border:1px solid rgba(46,42,59,.08);background:rgba(255,255,255,.88);box-shadow:0 10px 26px rgba(201,79,134,.10);cursor:pointer;display:grid;place-items:center}
    .dot{position:absolute;top:10px;right:10px;width:8px;height:8px;border-radius:50%;background:#E17C91}
    .nav-actions{display:flex;align-items:center;justify-content:flex-end;gap:10px;min-width:0}
    @media(max-width:768px){
      .booking-card{grid-template-columns:1fr;gap:12px}
      .booking-right{align-items:flex-start;flex-direction:row;flex-wrap:wrap}
      .nav{grid-template-columns:1fr auto}
      .nav-links{grid-column:1/-1}
    }
    /* Checkboxes hidden by default, shown in select-mode */
.card-checkbox { display: none; }
.select-mode-reschedule .checkbox-reschedule,
.select-mode-cancel     .checkbox-cancel,
.select-mode-rate       .checkbox-rate { display: block; }

/* Active state for select-mode buttons */
.select-mode-btn { 
  padding:9px 18px;border-radius:999px;font-size:12px;font-weight:900;
  cursor:pointer;display:inline-flex;align-items:center;gap:6px;
  border:1.5px solid rgba(46,42,59,.14);background:rgba(255,255,255,.85);
  color:var(--muted);transition:.15s ease;
}
.select-mode-btn:hover { background:white; color:var(--ink); }
.select-mode-btn.active-reschedule { background:rgba(215,238,219,.9); color:#3D7047; border-color:#3D7047; }
.select-mode-btn.active-cancel     { background:rgba(255,200,200,.9); color:#C94F4F; border-color:#C94F4F; }
.select-mode-btn.active-rate       { background:rgba(221,211,255,.9); color:#7648B8; border-color:#7648B8; }
  .card-checkbox{
  display:none;
  width:18px;
  height:18px;
  margin-top:22px;
  accent-color:#E75A9B;
  cursor:pointer;
  flex-shrink:0;
}

.booking-row{
    display:flex;
    align-items:stretch;
    gap:12px;
    margin-bottom:16px;
}

.checkbox-slot{
    width:0;
    overflow:hidden;
    flex-shrink:0;
    display:flex;
    justify-content:center;
    transition:.2s ease;
}

.select-mode-reschedule .checkbox-slot,
.select-mode-cancel .checkbox-slot,
.select-mode-rate .checkbox-slot{
    width:24px;
    padding-top:22px;
}

.card-checkbox{
    display:none;
    width:18px;
    height:18px;
    accent-color:#E75A9B;
    cursor:pointer;
    margin:0;
}

.select-mode-reschedule .checkbox-reschedule,
.select-mode-cancel .checkbox-cancel,
.select-mode-rate .checkbox-rate{
    display:block;
}
  </style>
</head>
<body>

<header class="topbar">
  <div class="container">
    <nav class="nav">
    <a href="student_dashboard.php" class="brand">
        <img src="<?= e($assetBase) ?>/logo.png" alt="Kyoshi">
        <div><strong>Kyoshi</strong><span>Student Learning Space</span></div>
    </a>
    <div class="nav-links">
        <a href="student_dashboard.php">Home</a>
        <a href="student_dashboard.php#preferences">Learning Goals</a>
        <a href="find_language.php">Find Language</a>
        <a href="booking_status.php" class="active">Bookings</a>
        <a href="student_dashboard.php#progress">Progress</a>
        <a href="student_dashboard.php#payments">Payments</a>
    </div>
    <div class="nav-actions">
        <div class="search">
            <i class="bi bi-search"></i>
            
            <input type="text" id="modalTutorSearchInput" placeholder="Search by language..." readonly style="cursor:pointer;" onclick="openSearch()">
        </div>
        <button class="icon-btn" onclick="showToast('Notifications coming soon')">
            <i class="bi bi-bell"></i><span class="dot"></span>
        </button>
        <div style="position:relative;">
            <button class="profile" onclick="toggleDropdown()" id="profileBtn">
                <img src="<?= e($profilePic) ?>" alt="Profile">
                <span><?= e($displayName) ?></span>
                <i class="bi bi-chevron-down" style="font-size:11px;margin-left:4px;"></i>
            </button>
            <div id="profileDropdown" style="display:none;position:absolute;top:calc(100% + 10px);right:0;background:white;border-radius:16px;box-shadow:0 18px 45px rgba(201,79,134,.2);border:1px solid rgba(242,138,178,.2);min-width:180px;overflow:hidden;z-index:100;">
                <a href="student_profile.php" style="display:flex;align-items:center;gap:10px;padding:14px 16px;font-size:14px;font-weight:700;color:#342635;" onmouseover="this.style.background='#FFF1F6'" onmouseout="this.style.background='white'"><i class="bi bi-person-circle" style="color:#E75A9B;"></i> My Profile</a>
                <a href="student_favourites.php" style="display:flex;align-items:center;gap:10px;padding:14px 16px;font-size:14px;font-weight:700;color:#342635;" onmouseover="this.style.background='#FFF1F6'" onmouseout="this.style.background='white'"><i class="bi bi-heart" style="color:#E75A9B;"></i> My Favourites</a>
                <hr style="margin:4px 0;border-color:rgba(242,138,178,.2);">
                <a href="logout.php" style="display:flex;align-items:center;gap:10px;padding:14px 16px;font-size:14px;font-weight:700;color:#dc2626;" onmouseover="this.style.background='#FFF1F6'" onmouseout="this.style.background='white'"><i class="bi bi-box-arrow-right"></i> Logout</a>
            </div>
        </div>
    </div>
</nav>
  </div>
</header>

<div class="container">
<?php if (isset($_GET['cancelled'])): ?>
<script>window.addEventListener('DOMContentLoaded',()=>showToast('Booking cancelled successfully.'));</script>
<?php endif; ?>
<div class="page-head" style="position:relative;text-align:center;margin-top:20px;">
  
  <a href="student_dashboard.php" class="back-link" style="position:absolute; left:0; top:50%; transform:translateY(-50%); margin:0;">
    <i class="bi bi-arrow-left"></i> Back
  </a>

  <h1 style="margin:0;">My Bookings</h1>

  <p style="margin:5px 0 0;color:var(--muted);font-size:14px;">
    Track all your session requests and their status.
  </p>

</div>

    <!-- FILTER BAR -->
    <form method="GET" class="filter-bar">
        <div class="filter-group">
            <label><i class="bi bi-funnel"></i> Status</label>
            <select name="status" class="filter-select" onchange="this.form.submit()">
            <option value="all" <?= $filterStatus==='all'?'selected':'' ?>>All (<?= $counts['all'] ?>)</option>
            <option value="pending"   <?= $filterStatus==='pending'?'selected':'' ?>>Pending (<?= $counts['pending'] ?>)</option>
            <option value="accepted" <?= $filterStatus==='accepted'?'selected':'' ?>>Accepted (<?= $counts['accepted'] ?>)</option>
            <option value="confirmed" <?= $filterStatus==='confirmed'?'selected':'' ?>>Confirmed (<?= $counts['confirmed'] ?>)</option>
            <option value="completed" <?= $filterStatus==='completed'?'selected':'' ?>>Completed (<?= $counts['completed'] ?>)</option>
            <option value="rescheduled" <?= $filterStatus==='rescheduled'?'selected':'' ?>>Rescheduled (<?= $counts['rescheduled'] ?>)</option>
            <option value="cancelled" <?= $filterStatus==='cancelled'?'selected':'' ?>>Cancelled (<?= $counts['cancelled'] ?>)</option>
            </select>
        </div>
        <div class="filter-group">
            <label><i class="bi bi-calendar3"></i> From</label>
            <input type="date" name="date_from" class="filter-input" value="<?= e($_GET['date_from'] ?? '') ?>" onchange="this.form.submit()">
        </div>
        <div class="filter-group">
            <label><i class="bi bi-calendar3"></i> To</label>
            <input type="date" name="date_to" class="filter-input" value="<?= e($_GET['date_to'] ?? '') ?>" onchange="this.form.submit()">
        </div>
        <div class="filter-right">
    <?php if (
        $filterStatus !== 'all' ||
        !empty($_GET['date_from']) ||
        !empty($_GET['date_to']) ||
        $sortBy !== 'furthest'
    ): ?>
    <?php endif; ?>

            <div class="filter-group">
                <label><i class="bi bi-sort-down"></i> Sort By</label>
                <select name="sort" class="filter-select" onchange="this.form.submit()">
  <optgroup label="Booked On">
    <option value="booked_newest" <?= $sortBy==='booked_newest'?'selected':'' ?>>Newest Booking First</option>
    <option value="booked_oldest" <?= $sortBy==='booked_oldest'?'selected':'' ?>>Oldest Booking First</option>
  </optgroup>
  <optgroup label="Session Date">
    <option value="session_soonest" <?= $sortBy==='session_soonest'?'selected':'' ?>>Soonest Session First</option>
    <option value="session_latest"  <?= $sortBy==='session_latest'?'selected':'' ?>>Latest Session First</option>
  </optgroup>
  <optgroup label="Price">
    <option value="price_low"  <?= $sortBy==='price_low'?'selected':'' ?>>Lowest Price</option>
    <option value="price_high" <?= $sortBy==='price_high'?'selected':'' ?>>Highest Price</option>
  </optgroup>
  <optgroup label="Other">
    <option value="language" <?= $sortBy==='language'?'selected':'' ?>>Language A–Z</option>
  </optgroup>
</select>
            </div>

        </div>
                <a href="booking_status.php" class="btn-reset">
            <i class="bi bi-x"></i> Reset
        </a>
        </form>
    <div id="bulkBar" style="display:none;position:sticky;top:90px;z-index:40;
  background:linear-gradient(135deg,#E75A9B,#F28AB2);border-radius:999px;
  padding:12px 20px;margin-bottom:16px;align-items:center;
  justify-content:space-between;gap:12px;box-shadow:0 8px 24px rgba(231,90,155,.3);">
  <span id="bulkCount" style="color:white;font-weight:900;font-size:13px;">0 selected</span>
  <div style="display:flex;gap:8px;flex-wrap:wrap;">
    <button id="bulkRateBtn"    onclick="bulkAction('rate')"       class="btn-action ghost" style="background:white;display:none;"><i class="bi bi-star"></i> Rate Selected</button>
    <button id="bulkReschedBtn" onclick="bulkAction('reschedule')" class="btn-action ghost" style="background:white;display:none;"><i class="bi bi-calendar-plus"></i> Reschedule Selected</button>
    <button id="bulkCancelBtn"  onclick="bulkAction('cancel')"     class="btn-action ghost" style="background:white;display:none;"><i class="bi bi-x-circle"></i> Cancel Selected</button>
    <button onclick="clearSelection()" class="btn-action ghost" style="background:rgba(255,255,255,.3);color:white;border-color:rgba(255,255,255,.4);">✕ Clear</button>
  </div>
</div>
<!-- SELECT MODE BAR -->
<div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px;align-items:center;">
  <span style="font-size:12px;font-weight:900;color:var(--muted);">Select more to:</span>
  <button type="button" class="select-mode-btn" id="btnSelectReschedule" onclick="toggleSelectMode('reschedule')">
    <i class="bi bi-calendar-plus"></i> Reschedule
  </button>
  <button type="button" class="select-mode-btn" id="btnSelectCancel" onclick="toggleSelectMode('cancel')">
    <i class="bi bi-x-circle"></i> Cancel
  </button>
  <button type="button" class="select-mode-btn" id="btnSelectRate" onclick="toggleSelectMode('rate')">
    <i class="bi bi-star"></i> Rate
  </button>
</div>
    <div class="booking-cards">
  <?php if (empty($bookings)): ?>
    <div class="empty-state">
      <i class="bi bi-calendar-x"></i>
      No bookings found.<br>
      <a href="search_tutors.php" style="color:var(--hot-pink);font-weight:900;margin-top:8px;display:inline-block;">Find a tutor →</a>
    </div>
  <?php else: ?>
   <?php foreach ($bookings as $b):
  $cfg = statusCfg($b['status']);
  $tutorPic = !empty($b['tutor_pic']) ? '../uploads/profiles/' . $b['tutor_pic'] : $assetBase . '/profile-tutor.png';
  // Determine which mode this card belongs to
  $checkboxClass = '';
  if (in_array($b['status'], ['confirmed']))              $checkboxClass = 'checkbox-reschedule';
  elseif (in_array($b['status'], ['pending','accepted'])) $checkboxClass = 'checkbox-cancel';
  elseif ($b['status'] === 'completed' && !$b['rated'])   $checkboxClass = 'checkbox-rate';

  $bulkable = !empty($checkboxClass);
?>
<div class="booking-row">
<div class="checkbox-slot">
<?php if ($bulkable): ?>
    <input type="checkbox"
    class="card-checkbox <?= $checkboxClass ?>"
    data-id="<?= $b['id'] ?>"
    data-status="<?= e($b['status']) ?>"
    data-rated="<?= $b['rated'] ? '1' : '0' ?>">
<?php endif; ?>
</div>
  <div class="booking-card" style="flex:1;">

      <!-- TOP: tutor + status -->
      <div class="card-top">
        <img src="<?= e($tutorPic) ?>" class="tutor-img" alt="<?= e($b['tutor_name']) ?>">
        <div class="card-top-info">
          <h4><?= e($b['language']) ?> with <?= e($b['tutor_name']) ?></h4>
          <p class="sub">
            <i class="bi bi-calendar3"></i> <?= date('D, d M Y', strtotime($b['booking_date'])) ?>
            &nbsp;·&nbsp;
            <i class="bi bi-clock"></i> <?= date('g:i A', strtotime($b['booking_time'])) ?>
          </p>
        </div>
        <span class="status-badge" style="background:<?= $cfg['bg'] ?>;color:<?= $cfg['color'] ?>;">
          <i class="bi <?= $cfg['icon'] ?>"></i> <?= $cfg['label'] ?>
        </span>
      </div>

      <!-- TAGS -->
      <div class="card-tags">
        <span class="tag mode"><?= $b['learning_mode']==='online'?'💻 Online':'🤝 Face to Face' ?></span>
        <?php if ($b['focus']): foreach(explode(',',$b['focus']) as $f): ?>
          <span class="tag focus"><?= e(trim($f)) ?></span>
        <?php endforeach; endif; ?>
        <?php if ($b['status'] === 'accepted'): ?>

    <?php if ($b['payment_status'] === 'verified'): ?>
        <span class="tag pay-ok"><i class="bi bi-check-circle"></i> Payment Verified</span>

    <?php elseif ($b['payment_status'] === 'pending'): ?>
        <span class="tag pay-no"><i class="bi bi-hourglass"></i> Payment Processing</span>

    <?php elseif ($b['payment_status'] === 'failed'): ?>
        <span class="tag pay-no"><i class="bi bi-x-circle"></i> Payment Failed</span>

    <?php elseif ($b['status'] === 'accepted'): ?>
        <span class="tag pay-no"><i class="bi bi-clock"></i> Awaiting Payment</span>

    <?php endif; ?>

<?php endif; ?>
      </div>

      <!-- BOTTOM: meta + actions -->
      <div class="card-bottom">
                <div class="card-meta">
    <?php if ($b['payment_status'] === 'verified'): ?>
        <strong>RM <?= e(number_format($b['payment_amount'], 2)) ?> paid</strong>
    <?php else: ?>
        <strong>RM <?= e($b['rate']) ?>/hr</strong>
    <?php endif; ?>
</div>
                <div class="card-actions">
        <a href="booking_detail.php?id=<?= $b['id'] ?>" class="btn-action primary">
            <i class="bi bi-eye"></i> View Details
        </a>

    <?php if ($b['status'] === 'pending'): ?>
    <button class="btn-action ghost" onclick="confirmCancel(<?= $b['id'] ?>, 'pending')">
        <i class="bi bi-x-circle"></i> Cancel
    </button>

<?php elseif ($b['status'] === 'accepted'): ?>
    <?php if ($b['payment_status'] !== 'verified'): ?>
        <a href="payment_form.php?booking_id=<?= $b['id'] ?>" class="btn-action purple">
            <i class="bi bi-credit-card"></i> Pay Now
        </a>
    <?php else: ?>
        <span class="btn-action muted">Waiting admin verification</span>
    <?php endif; ?>
    <button class="btn-action ghost" onclick="confirmCancel(<?= $b['id'] ?>, 'accepted')">
        <i class="bi bi-x-circle"></i> Cancel
    </button>

<?php elseif ($b['status'] === 'confirmed'): ?>
    <a href="reschedule_booking.php?id=<?= $b['id'] ?>" class="btn-action ghost">
        <i class="bi bi-calendar-plus"></i> Reschedule
</a>

<?php elseif ($b['status'] === 'rescheduled'): ?>
    <span class="btn-action muted">
        <i class="bi bi-calendar-check"></i> Reschedule Requested
    </span>

<?php elseif ($b['status'] === 'completed'): ?>
    <?php if ($b['rated']): ?>
        <span class="btn-action muted"><i class="bi bi-star-fill"></i> Rated</span>
    <?php else: ?>
        <a href="booking_detail.php?id=<?= $b['id'] ?>#rate" class="btn-action purple">
            <i class="bi bi-star"></i> Rate Session
        </a>
    <?php endif; ?>

<?php endif; ?>
        </div>
      </div>

    </div>
    </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<!-- CANCEL MODAL -->
<div class="modal-overlay" id="cancelModal">
  <div class="modal-box">
    <h3>Cancel Booking?</h3>
    <p>Are you sure you want to cancel this booking? This action cannot be undone.</p>
    <div class="modal-actions">
      <button onclick="closeCancelModal()" style="padding:10px 20px;border-radius:999px;border:1px solid rgba(46,42,59,.12);background:none;color:#7A5570;font-size:13px;font-weight:900;cursor:pointer;">Keep Booking</button>
      <a id="cancelConfirmBtn" href="cancel_booking.php?id=" 
   style="padding:10px 20px;border-radius:999px;border:none;
   background:linear-gradient(135deg,#E75A9B,#F28AB2);
   color:white;font-size:13px;font-weight:900;
   cursor:pointer;text-decoration:none;">
   Yes, Cancel
</a>
    </div>
  </div>
</div>
<script>
let toastTimer;
let activeMode = null; // 'reschedule' | 'cancel' | 'rate' | null

function showToast(msg) {
  const t = document.getElementById('toast');
  t.textContent = msg; t.classList.add('show');
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => t.classList.remove('show'), 1800);
}

function toggleDropdown() {
  const d = document.getElementById('profileDropdown');
  d.style.display = d.style.display === 'none' ? 'block' : 'none';
}

document.addEventListener('click', function(e) {
  const btn = document.getElementById('profileBtn');
  const dd  = document.getElementById('profileDropdown');
  if (btn && dd && !btn.contains(e.target) && !dd.contains(e.target)) dd.style.display = 'none';
  if (e.target === document.getElementById('cancelModal')) closeCancelModal();
});

function confirmCancel(id, status) {
  const msg = status === 'accepted'
    ? 'You have already been accepted by the tutor. Cancel anyway?'
    : 'Are you sure you want to cancel this booking?';
  document.querySelector('#cancelModal p').textContent = msg;
  document.getElementById('cancelConfirmBtn').href = 'cancel_booking.php?id=' + id;
  document.getElementById('cancelModal').classList.add('show');
}

function closeCancelModal() {
  document.getElementById('cancelModal').classList.remove('show');
}

// ── SELECT MODE ──
function toggleSelectMode(mode) {
  const body = document.body;
  const btnMap = {
    reschedule: 'btnSelectReschedule',
    cancel:     'btnSelectCancel',
    rate:       'btnSelectRate'
  };

  if (activeMode === mode) {
    // Toggle off
    body.classList.remove('select-mode-' + mode);
    document.getElementById(btnMap[mode]).classList.remove('active-' + mode);
    activeMode = null;
    clearSelection();
    return;
  }

  // Clear previous mode
  if (activeMode) {
    body.classList.remove('select-mode-' + activeMode);
    document.getElementById(btnMap[activeMode]).classList.remove('active-' + activeMode);
    clearSelection();
  }

  // Apply new mode
  activeMode = mode;
  body.classList.add('select-mode-' + mode);
  document.getElementById(btnMap[mode]).classList.add('active-' + mode);
}

// ── BULK BAR ──
function updateBulkBar() {
  const checked = [...document.querySelectorAll('.card-checkbox:checked')];
  const bar = document.getElementById('bulkBar');
  if (checked.length === 0) { bar.style.display = 'none'; return; }
  bar.style.display = 'flex';
  document.getElementById('bulkCount').textContent = checked.length + ' selected';

  document.getElementById('bulkRateBtn').style.display    = activeMode === 'rate'        ? '' : 'none';
  document.getElementById('bulkReschedBtn').style.display = activeMode === 'reschedule'  ? '' : 'none';
  document.getElementById('bulkCancelBtn').style.display  = activeMode === 'cancel'      ? '' : 'none';
}

function clearSelection() {
  document.querySelectorAll('.card-checkbox:checked').forEach(c => c.checked = false);
  document.getElementById('bulkBar').style.display = 'none';
}

function bulkAction(type) {
  const checked = [...document.querySelectorAll('.card-checkbox:checked')];

  if (type === 'rate') {
    checked.forEach(c => window.open('booking_detail.php?id=' + c.dataset.id + '#rate', '_blank'));

  } else if (type === 'reschedule') {
    checked.forEach(c => window.open('reschedule_booking.php?id=' + c.dataset.id, '_blank'));

  } else if (type === 'cancel') {
    const ids = checked.map(c => c.dataset.id);
    if (ids.length === 1) {
      confirmCancel(ids[0], checked[0].dataset.status);
    } else {
      if (confirm('Cancel ' + ids.length + ' bookings? This cannot be undone.')) {
        ids.forEach(id => fetch('cancel_booking.php?id=' + id + '&ajax=1').catch(() => {}));
        setTimeout(() => location.reload(), 800);
      }
    }
  }
}

document.addEventListener('change', e => {
  if (e.target.classList.contains('card-checkbox')) updateBulkBar();
});
</script>
<?php include 'nav_search_modal.php'; ?>
<script src="../js/search_modal.js"></script>
</body>
</html>