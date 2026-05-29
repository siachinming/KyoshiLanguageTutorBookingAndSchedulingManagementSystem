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

// Filters
$filterStatus = $_GET['status'] ?? 'all';
$filterFrom   = $_GET['date_from'] ?? '';
$filterTo     = $_GET['date_to']   ?? '';
$sortBy       = $_GET['sort']      ?? 'newest';

// Build filter conditions
$filterWhere  = "";
$filterParams = [];
$filterTypes  = "";

if ($filterStatus !== 'all' && in_array($filterStatus, ['pending','verified','failed','disputed'])) {
    $filterWhere   .= " AND p.status = ?";
    $filterParams[] = $filterStatus;
    $filterTypes   .= "s";
} elseif ($filterStatus === 'awaiting') {
     $filterWhere .= " AND b.status = 'accepted' AND p.id IS NULL";
}

if ($filterFrom) {
    $filterWhere   .= " AND b.booking_date >= ?";
    $filterParams[] = $filterFrom;
    $filterTypes   .= "s";
}
if ($filterTo) {
    $filterWhere   .= " AND b.booking_date <= ?";
    $filterParams[] = $filterTo;
    $filterTypes   .= "s";
}

$orderBy = $sortBy === 'oldest'
    ? "ORDER BY b.booking_date ASC, b.booking_time ASC"
    : "ORDER BY b.booking_date DESC, b.booking_time DESC";
// Main query — add tutor_profiles for rate fallback
$stmt = $conn->prepare("
    SELECT
        p.id            AS payment_id,
        p.amount,
        p.payment_method,
        p.status        AS payment_status,
        p.receipt_number,
        p.created_at    AS paid_at,
        p.proof_image,
        b.id            AS booking_id,
        b.language,
        b.booking_date,
        b.booking_time,
        b.learning_mode,
        b.status        AS booking_status,
        COALESCE(b.total_amount, tp.rate) AS total_amount,
        u.fullname      AS tutor_name,
        u.profile_pic   AS tutor_pic
    FROM bookings b
    JOIN users u ON b.tutor_id = u.id
    JOIN tutor_profiles tp ON b.tutor_id = tp.user_id
    LEFT JOIN payments p ON b.id = p.booking_id
    WHERE b.student_id = ?
      AND (
          p.status IN ('verified','failed','disputed','pending')
          OR
          (b.status = 'accepted' AND p.id IS NULL)
          OR
          (b.status = 'disputed' AND p.id IS NULL)
      )
      $filterWhere
    $orderBy
");

$allParams  = array_merge([$userID], $filterParams);
$allTypes   = "i" . $filterTypes;
$stmt->bind_param($allTypes, ...$allParams);
$stmt->execute();
$payments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$cntStmt = $conn->prepare("
    SELECT p.status, COUNT(*) as cnt, SUM(p.amount) as total
    FROM payments p
    WHERE p.student_id = ?
    GROUP BY p.status

    UNION ALL

    SELECT 'pending_booking' as status, COUNT(*) as cnt, SUM(tp.rate) as total
    FROM bookings b
    JOIN tutor_profiles tp ON b.tutor_id = tp.user_id
    WHERE b.student_id = ?
      AND b.status = 'accepted'
      AND NOT EXISTS (SELECT 1 FROM payments WHERE booking_id = b.id)
    
    UNION ALL
    
    SELECT 'disputed_booking' as status, COUNT(*) as cnt, SUM(b.total_amount) as total
    FROM bookings b
    WHERE b.student_id = ?
      AND b.status = 'disputed'
      AND NOT EXISTS (SELECT 1 FROM payments WHERE booking_id = b.id)
");
$cntStmt->bind_param("iii", $userID, $userID, $userID);
$cntStmt->execute();
$cntResult = $cntStmt->get_result();

$counts = ['all'=>0,'pending'=>0,'verified'=>0,'failed'=>0,'disputed'=>0,'pending_booking'=>0,'disputed_booking'=>0];
$totals = ['pending'=>0,'verified'=>0,'failed'=>0,'disputed'=>0,'pending_booking'=>0,'disputed_booking'=>0];

while ($row = $cntResult->fetch_assoc()) {
    $status = $row['status'];
    if (isset($counts[$status])) {
        $counts[$status] = (int)$row['cnt'];
        if (isset($totals[$status])) $totals[$status] = (float)$row['total'];
    }
}

$counts['all'] = $counts['pending'] + $counts['verified'] + $counts['failed'] + $counts['disputed'] + $counts['pending_booking'];


// Get awaiting payments (bookings with no payment record)
$awaitingStmt = $conn->prepare("
    SELECT 
        COUNT(*) as cnt,
        SUM(b.total_amount) as total
    FROM bookings b
    WHERE b.student_id = ? 
    AND b.status = 'accepted'
    AND NOT EXISTS (SELECT 1 FROM payments WHERE booking_id = b.id)
");
$awaitingStmt->bind_param("i", $userID);
$awaitingStmt->execute();
$awaitingResult = $awaitingStmt->get_result()->fetch_assoc();

$counts['awaiting'] = $awaitingResult['cnt'] ?? 0;
$totals['awaiting'] = $awaitingResult['total'] ?? 0;
$counts['all'] += $counts['awaiting'];
function e($v){ return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }

function paymentCfg($s) {
    return [
        'verified' => ['label'=>'Verified',   'icon'=>'bi-check-circle-fill', 'bg'=>'rgba(215,238,219,.78)', 'color'=>'#3D7047'],
        'pending'  => ['label'=>'Pending',     'icon'=>'bi-hourglass-split',   'bg'=>'rgba(255,241,200,.78)', 'color'=>'#A06B00'],
        'failed'   => ['label'=>'Failed',      'icon'=>'bi-x-circle-fill',     'bg'=>'rgba(255,200,200,.78)', 'color'=>'#C94F4F'],
    ][$s] ?? ['label'=>'Unknown','icon'=>'bi-question','bg'=>'#eee','color'=>'#999'];
}

function methodIcon($m) {
    $map = ['stripe'=>'💳','online_banking'=>'🏦','duitnow'=>'📱','cash'=>'💵'];
    return $map[strtolower($m)] ?? '💳';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>My Payments · Kyoshi</title>
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
    .nav-links{display:flex;align-items:center;justify-content:center;gap:6px;;border-radius:999px;padding:7px;overflow:auto;scrollbar-width:none;}
    .nav-links::-webkit-scrollbar{display:none}
    .nav-links a{flex:0 0 auto;padding:9px 12px;border-radius:999px;font-size:13px;font-weight:900;color:#6D4964;white-space:nowrap;transition:.18s ease}
    .nav-links a.active,.nav-links a:hover{background:linear-gradient(135deg,var(--hot-pink),var(--pink));color:#fff;box-shadow:0 8px 18px rgba(231,90,155,.28)}
    .nav-actions{display:flex;align-items:center;gap:10px}
    .profile{display:flex;align-items:center;gap:9px;border-radius:999px;padding:6px 12px 6px 6px;font-weight:900;color:#7A3D65;border:1px solid rgba(46,42,59,.08);background:rgba(255,255,255,.88);cursor:pointer}
    .profile img{width:34px;height:34px;object-fit:cover;border-radius:50%}

    /* PAGE */
    .back-link{display:inline-flex;align-items:center;gap:6px;color:var(--pink-dark);font-weight:900;font-size:13px;padding:9px 16px;border-radius:999px;background:rgba(255,255,255,.78);border:1px solid rgba(46,42,59,.08);transition:.18s ease;margin-bottom:20px;}
    .back-link:hover{transform:translateY(-1px)}

    /* SUMMARY CARDS */
    .summary-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:20px;margin-bottom:24px}
    .sum-card{background:var(--paper);border:1px solid rgba(255,255,255,.55);box-shadow:var(--shadow);border-radius:20px;padding:18px 22px;display:flex;align-items:center;gap:16px}
    .sum-icon{width:48px;height:48px;border-radius:16px;display:grid;place-items:center;font-size:20px;flex-shrink:0}
    .sum-icon.verified{background:rgba(215,238,219,.6);color:#3D7047}
    .sum-icon.pending{background:rgba(255,241,200,.6);color:#A06B00}
    .sum-icon.failed{background:rgba(255,200,200,.6);color:#C94F4F}
    .sum-label{font-size:13px;font-weight:700;color:var(--muted);display:block;margin-bottom:5px}
    .sum-amount{font-size:20px;font-weight:900;color:var(--ink);display:block;line-height:1}
    .sum-count{font-size:12px;color:var(--muted);margin-top:4px;display:block}

    /* FILTER BAR */
    .filter-bar{background:var(--paper);border:1px solid rgba(255,255,255,.55);box-shadow:0 6px 20px rgba(201,79,134,.08);border-radius:var(--radius-lg);padding:20px 24px;margin-bottom:20px;display:flex;flex-wrap:wrap;gap:16px;align-items:flex-end;}
    .filter-group{display:flex;flex-direction:column;gap:6px;flex:1;min-width:150px}
    .filter-group label{font-size:12px;font-weight:900;color:var(--muted);text-transform:uppercase;letter-spacing:.5px}
    .filter-select,.filter-input{padding:11px 15px;border:1px solid rgba(46,42,59,.12);border-radius:14px;outline:none;font-size:14px;font-weight:700;color:var(--ink);background:rgba(255,255,255,.9)}
    .filter-select:focus,.filter-input:focus{border-color:var(--hot-pink);box-shadow:0 0 0 3px rgba(231,90,155,.12)}
    .btn-reset{padding:11px 22px;border-radius:999px;border:1px solid rgba(46,42,59,.12);background:none;color:var(--muted);font-size:14px;font-weight:900;cursor:pointer;white-space:nowrap;align-self:flex-end}
    .btn-reset:hover{background:rgba(255,255,255,.88)}

    /* BULK SELECT TOOLBAR */
    .bulk-toolbar{background:var(--paper);border:1px solid rgba(242,138,178,.25);box-shadow:0 6px 20px rgba(201,79,134,.10);border-radius:var(--radius-lg);padding:16px 24px;margin-bottom:20px;display:flex;flex-wrap:wrap;align-items:center;gap:16px;}
    .bulk-toolbar .tb-group{display:flex;align-items:center;gap:12px;flex:1;flex-wrap:wrap}
    .select-all-btn{padding:10px 20px;border-radius:999px;font-size:13px;font-weight:900;cursor:pointer;border:1px solid rgba(231,90,155,.3);background:rgba(255,241,246,.7);color:var(--pink-dark);transition:.15s ease;display:inline-flex;align-items:center;gap:8px}
    .select-all-btn.active{background:linear-gradient(135deg,var(--hot-pink),var(--pink));color:#fff;border-color:transparent}
    .tb-count{font-size:13px;color:var(--muted);font-weight:700}
    .tb-count strong{color:var(--ink);font-size:16px}

    /* PAYMENT CARDS - BIGGER */
    .payment-list{display:flex;flex-direction:column;gap:20px;padding-bottom:100px}
    .payment-card{background:var(--paper);border:1px solid rgba(255,255,255,.55);box-shadow:0 8px 24px rgba(201,79,134,.10);border-radius:var(--radius-lg);padding:28px 32px;transition:.18s ease;position:relative;}
    .payment-card:hover{transform:translateY(-3px);box-shadow:var(--shadow)}
    .payment-card.selected{border-color:var(--hot-pink);box-shadow:0 0 0 3px rgba(231,90,155,.18),var(--shadow)}

    .pay-top{display:flex;align-items:center;gap:20px;margin-bottom:20px}
    .tutor-img{width:70px;height:70px;object-fit:cover;border-radius:20px;background:#eee;flex-shrink:0}
    .pay-top-info{flex:1}
    .pay-top-info h4{margin:0 0 6px;font-size:18px;font-weight:900}
    .pay-top-info .sub{margin:0;color:var(--muted);font-size:14px}
    .status-badge{display:inline-flex;align-items:center;gap:8px;padding:10px 18px;border-radius:999px;font-size:13px;font-weight:900;white-space:nowrap;flex-shrink:0}

    .pay-body{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:20px}
    .pay-item{background:rgba(255,241,246,.5);border:1px solid rgba(242,138,178,.12);border-radius:16px;padding:14px 18px}
    .pay-item .lbl{font-size:11px;font-weight:900;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px}
    .pay-item .val{font-size:15px;font-weight:900;color:var(--ink)}
    .pay-item .val.amount{color:var(--hot-pink);font-size:22px}

    .pay-actions{display:flex;gap:12px;flex-wrap:wrap;padding-top:18px;border-top:1px solid rgba(46,42,59,.06)}
    .btn-action{padding:11px 22px;border-radius:999px;font-size:13px;font-weight:900;cursor:pointer;display:inline-flex;align-items:center;gap:8px;transition:.15s ease;text-decoration:none;border:none;white-space:nowrap}
    .btn-action:hover{transform:translateY(-2px)}
    .btn-action.primary{background:linear-gradient(135deg,var(--hot-pink),var(--pink));color:white;box-shadow:0 6px 14px rgba(231,90,155,.25)}
    .btn-action.ghost{background:white;color:var(--pink-dark);border:1px solid rgba(201,79,134,.25)}
    .btn-action.purple{background:rgba(221,211,255,.8);color:#7648B8;border:1px solid rgba(167,123,232,.2)}
    .btn-action.muted{background:rgba(255,255,255,.7);color:var(--muted);border:1px solid rgba(46,42,59,.10);cursor:default}

    .empty-state{padding:60px 30px;border-radius:var(--radius-lg);background:var(--paper);border:1px dashed rgba(46,42,59,.16);color:#6D647C;text-align:center;font-weight:700}
    .empty-state i{font-size:48px;color:rgba(231,90,155,.3);display:block;margin-bottom:16px}

    /* STICKY BULK ACTION BAR */
    .bulk-action-bar{position:fixed;bottom:0;left:0;right:0;z-index:60;padding:0 20px 20px;pointer-events:none;transition:.3s ease}
    .bulk-action-inner{max-width:1200px;margin:0 auto;background:rgba(52,38,53,.96);backdrop-filter:blur(20px);border-radius:var(--radius-lg);padding:18px 28px;display:flex;align-items:center;gap:20px;flex-wrap:wrap;box-shadow:0 -4px 40px rgba(201,79,134,.3);border:1px solid rgba(231,90,155,.3);pointer-events:all;transform:translateY(120%);transition:.32s cubic-bezier(.34,1.56,.64,1);opacity:0}
    .bulk-action-bar.visible .bulk-action-inner{transform:translateY(0);opacity:1}
    .bulk-info{flex:1}
    .bulk-info .bi-line{font-size:13px;color:rgba(255,255,255,.6);margin-bottom:4px}
    .bulk-info .b-count{font-size:18px;font-weight:900;color:#fff}
    .bulk-info .b-amount{font-size:14px;color:var(--pink);font-weight:700;margin-left:8px}
    .bulk-btns{display:flex;gap:12px;flex-wrap:wrap}
    .bulk-btn{padding:12px 26px;border-radius:999px;font-size:14px;font-weight:900;cursor:pointer;display:inline-flex;align-items:center;gap:8px;transition:.18s ease;border:none;white-space:nowrap}
    .bulk-btn:hover{transform:translateY(-2px)}
    .bulk-btn.pay{background:linear-gradient(135deg,#7648B8,#A47DE0);color:#fff}
    .bulk-btn.download{background:linear-gradient(135deg,var(--hot-pink),var(--pink));color:#fff}
    .bulk-btn.clear{background:rgba(255,255,255,.12);color:rgba(255,255,255,.7);border:1px solid rgba(255,255,255,.15)}
    .bulk-btn.clear:hover{background:rgba(255,255,255,.2)}

    .toast{position:fixed;left:50%;bottom:28px;transform:translate(-50%,18px);opacity:0;pointer-events:none;z-index:99;background:#8E3F70;color:#fff;border-radius:999px;padding:14px 22px;font-size:14px;font-weight:900;transition:.2s ease}
    .toast.show{opacity:1;transform:translate(-50%,0)}

    .selection-mode .payment-card{cursor:pointer}
    .selection-mode .payment-card:hover{transform:translateY(-3px);box-shadow:var(--shadow)}
    .selection-mode .payment-card.selected{border:2px solid var(--hot-pink);background:rgba(255,167,211,0.9)}

    .selection-mode-indicator{position:fixed;bottom:0;left:0;right:0;background:linear-gradient(135deg,#1a1a2e,#16213e);color:white;padding:20px 30px;display:flex;justify-content:space-between;align-items:center;box-shadow:0 -4px 20px rgba(0,0,0,0.3);z-index:1000;animation:slideUp 0.3s ease-out}
    @keyframes slideUp{from{transform:translateY(100%)}to{transform:translateY(0)}}
    .selection-mode-indicator .cancel-btn{background:rgba(255,255,255,0.15);border:none;color:white;padding:12px 24px;border-radius:40px;cursor:pointer;font-size:14px;font-weight:700;transition:all 0.2s}
    .selection-mode-indicator .cancel-btn:hover{transform:translateY(-2px);background:rgba(255,23,23,0.3)}
    .selection-mode-indicator .cancel-btn:last-child{background:#28a745}
    .selection-mode-indicator .cancel-btn:last-child:hover{background:#34ce57}

    @media(max-width:900px){
      .summary-grid{grid-template-columns:1fr}
      .pay-body{grid-template-columns:1fr 1fr}
      .nav{flex-wrap:wrap}
      .nav-links{order:3;width:100%;margin-top:12px}
      .brand strong{font-size:20px}
      .brand img{width:46px;height:46px}
      .profile img{width:38px;height:38px}
    }
    @media(max-width:600px){
      .pay-body{grid-template-columns:1fr}
      .bulk-btns{width:100%}
      .nav-links a{padding:8px 16px;font-size:13px}
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
          <a  href="find_language.php">Find Language</a>
          <a  href="booking_status.php">My Bookings</a>
          <a class="active" href="my_payments.php">My Payments</a>
          <a href="my_materials.php">My Materials</a>
          <a href="my_assignments.php">My Assignments</a>
        </div>
        <div class="nav-actions" style="display:flex;align-items:center;justify-content:flex-end;gap:10px;margin-left:auto;">
          <div style="position:relative;">
            <button class="profile" onclick="toggleDropdown()" id="profileBtn">
              <img src="<?= e($profilePic) ?>" alt="Student profile">
              <span><?= e($displayName) ?></span>
              <i class="bi bi-chevron-down" style="font-size:11px; margin-left:4px;"></i>
            </button>
            <div id="profileDropdown" style="display:none;position:absolute;top:calc(100% + 10px);right:0;background:white;border-radius:16px;box-shadow:0 18px 45px rgba(201,79,134,.2);border:1px solid rgba(242,138,178,.2);min-width:180px;overflow:hidden;z-index:100;">
              <a href="student_profile.php" style="display:flex;align-items:center;gap:10px;padding:14px 16px;font-size:14px;font-weight:700;color:#342635;transition:.15s ease;" onmouseover="this.style.background='#FFF1F6'" onmouseout="this.style.background='white'">
                <i class="bi bi-person-circle" style="color:#E75A9B;"></i> My Profile
              </a>
              <a href="my_progress.php" style="display:flex;align-items:center;gap:10px;padding:14px 16px;font-size:14px;font-weight:700;color:#342635;transition:.15s ease;" onmouseover="this.style.background='#FFF1F6'" onmouseout="this.style.background='white'">
  <i class="bi bi-bar-chart-steps" style="color:#E75A9B;"></i> My Progress
</a>
              <a href="student_favourites.php" style="display:flex;align-items:center;gap:10px;padding:14px 16px;font-size:14px;font-weight:700;color:#342635;transition:.15s ease;" onmouseover="this.style.background='#FFF1F6'" onmouseout="this.style.background='white'">
                <i class="bi bi-heart" style="color:#E75A9B;"></i> My Favourites
              </a>
              <hr style="margin:4px 0;border-color:rgba(242,138,178,.2);">
              <a href="logout.php" style="display:flex;align-items:center;gap:10px;padding:14px 16px;font-size:14px;font-weight:700;color:#dc2626;transition:.15s ease;" onmouseover="this.style.background='#FFF1F6'" onmouseout="this.style.background='white'">
                <i class="bi bi-box-arrow-right"></i> Logout
              </a>
            </div>
          </div>
        </div>
      </nav>
  </div>
</header>

<div class="container" style="padding:24px 0 60px;">

  <div style="position:relative;text-align:center;margin-bottom:20px;">
    <a href="student_dashboard.php" class="back-link" style="position:absolute;left:0;top:50%;transform:translateY(-50%);margin:0;">
      <i class="bi bi-arrow-left"></i> Back
    </a>
    <h1 style="margin:0;font-size:32px;letter-spacing:-.6px;">My Payments</h1>
    <p style="margin:8px 0 0;color:var(--muted);font-size:15px;">Track all your payment records and download receipts.</p>
  </div>

  <!-- SUMMARY CARDS -->
  <div class="summary-grid">
    <div class="sum-card">
      <div class="sum-icon verified"><i class="bi bi-check-circle-fill"></i></div>
      <div>
        <span class="sum-label">Total Paid</span>
        <span class="sum-amount">RM <?= number_format($totals['verified'], 2) ?></span>
        <span class="sum-count"><?= $counts['verified'] ?> verified payment<?= $counts['verified'] != 1 ? 's' : '' ?></span>
      </div>
    </div>
    <div class="sum-card">
      <div class="sum-icon pending"><i class="bi bi-hourglass-split"></i></div>
      <div>
        <span class="sum-label">Pending Verification</span>
        <span class="sum-amount">RM <?= number_format($totals['pending'], 2) ?></span>
        <span class="sum-count"><?= $counts['pending'] ?> awaiting review</span>
      </div>
    </div>
    <div class="sum-card">
      <div class="sum-icon failed"><i class="bi bi-x-circle-fill"></i></div>
      <div>
        <span class="sum-label">Failed / Disputed</span>
        <span class="sum-amount">RM <?= number_format($totals['failed'] + $totals['disputed'], 2) ?></span>
        <span class="sum-count">
          <?= $counts['failed'] ?> failed
          <?php if ($counts['disputed'] > 0): ?>
            + <?= $counts['disputed'] ?> disputed
          <?php endif; ?>
        </span>
      </div>
    </div>
  </div>

  <!-- FILTER BAR -->
  <form method="GET" class="filter-bar">
    <div class="filter-group">
      <label><i class="bi bi-funnel"></i> Status</label>
      <select name="status" class="filter-select" onchange="this.form.submit()">
        <option value="all" <?= $filterStatus==='all'?'selected':'' ?>>All (<?= $counts['all'] ?>)</option>
        <option value="awaiting" <?= $filterStatus==='awaiting'?'selected':'' ?>>Awaiting Payment (<?= $counts['awaiting'] ?>)</option>
        <option value="pending" <?= $filterStatus==='pending'?'selected':'' ?>>Pending Verification (<?= $counts['pending'] ?>)</option>
        <option value="verified" <?= $filterStatus==='verified'?'selected':'' ?>>Verified (<?= $counts['verified'] ?>)</option>
        <option value="failed" <?= $filterStatus==='failed'?'selected':'' ?>>Failed (<?= $counts['failed'] ?>)</option>
        <option value="disputed" <?= $filterStatus==='disputed'?'selected':'' ?>>Disputed (<?= $counts['disputed'] ?? 0 ?>)</option>
      </select>
    </div>
    <div class="filter-group">
      <label><i class="bi bi-calendar3"></i> From</label>
      <input type="date" name="date_from" class="filter-input" value="<?= e($filterFrom) ?>" onchange="this.form.submit()">
    </div>
    <div class="filter-group">
      <label><i class="bi bi-calendar3"></i> To</label>
      <input type="date" name="date_to" class="filter-input" value="<?= e($filterTo) ?>" onchange="this.form.submit()">
    </div>
    <div class="filter-group">
      <label><i class="bi bi-sort-down"></i> Sort</label>
      <select name="sort" class="filter-select" onchange="this.form.submit()">
        <option value="newest" <?= $sortBy==='newest'?'selected':'' ?>>Newest First</option>
        <option value="oldest" <?= $sortBy==='oldest'?'selected':'' ?>>Oldest First</option>
      </select>
    </div>
    <a href="my_payments.php" class="btn-reset"><i class="bi bi-x"></i> Reset</a>
  </form>
  
  <div style="display: flex; justify-content: flex-end; margin-bottom: 20px;">
    <button class="btn-primary" onclick="toggleSelectionMode()" id="selectModeBtn" style="background: linear-gradient(135deg, var(--hot-pink), var(--pink)); padding: 12px 28px; border-radius: 40px; border: none; color: white; font-weight: 800; cursor: pointer; display: flex; align-items: center; gap: 8px; font-size: 14px;">
      <i class="bi bi-check2-square"></i> Select More to Pay
    </button>
  </div>

  <!-- PAYMENT LIST -->
  <div class="payment-list" id="paymentList">
    <?php if (empty($payments)): ?>
      <div class="empty-state">
        <i class="bi bi-receipt"></i>
        No payment records found.<br>
        <a href="booking_status.php" style="color:var(--hot-pink);font-weight:900;margin-top:12px;display:inline-block;">View Bookings →</a>
      </div>
    <?php else: ?>
      <?php foreach ($payments as $p):
      
        if (!$p['payment_id']): 
          $tPic = !empty($p['tutor_pic']) ? '../uploads/profiles/' . $p['tutor_pic'] : $assetBase . '/profile-tutor.png';
      ?>
        <div class="payment-card" 
          id="card-booking-<?= $p['booking_id'] ?>"
          data-booking-id="<?= $p['booking_id'] ?>"
          data-amount="<?= $p['total_amount'] ?>"
          data-method="">
          <div class="pay-top">
            <img src="<?= e($tPic) ?>" class="tutor-img" alt="<?= e($p['tutor_name']) ?>">
            <div class="pay-top-info">
              <h4><?= e($p['language']) ?> with <?= e($p['tutor_name']) ?></h4>
              <p class="sub">
                <i class="bi bi-calendar3"></i> Session: <?= date('D, d M Y', strtotime($p['booking_date'])) ?>
                &nbsp;·&nbsp;
                <i class="bi bi-clock"></i> <?= date('g:i A', strtotime($p['booking_time'])) ?>
                &nbsp;·&nbsp;
                <?= $p['learning_mode']==='online'?'💻 Online':'🤝 Face to Face' ?>
              </p>
            </div>
            <span class="status-badge" style="background:#fff3e0;color:#e67e22;">
              <i class="bi bi-hourglass-split"></i> Awaiting Payment
            </span>
          </div>
          
          <div class="pay-body">
            <div class="pay-item">
              <div class="lbl">Amount</div>
              <div class="val amount">RM <?= number_format($p['total_amount'], 2) ?></div>
            </div>
            <div class="pay-item">
              <div class="lbl">Method</div>
              <div class="val">💳 Not selected</div>
            </div>
            <div class="pay-item">
              <div class="lbl">Status</div>
              <div class="val">Awaiting payment</div>
            </div>
            <div class="pay-item">
              <div class="lbl">Receipt No.</div>
              <div class="val">—</div>
            </div>
          </div>
          
          <div class="pay-actions">
            <a href="booking_detail.php?id=<?= $p['booking_id'] ?>" class="btn-action primary">
              <i class="bi bi-eye"></i> View Booking
            </a>
            <a href="payment_form.php?booking_id=<?= $p['booking_id'] ?>" class="btn-action purple">
              <i class="bi bi-credit-card"></i> Pay Now
            </a>
          </div>
        </div>
      <?php 
        continue;
        endif;
        
        // Regular payment display
        $cfg = paymentCfg($p['payment_status']);
        $tPic = !empty($p['tutor_pic']) ? '../uploads/profiles/' . $p['tutor_pic'] : $assetBase . '/profile-tutor.png';
        $methodLabel = ucwords(str_replace('_', ' ', $p['payment_method'] ?? ''));
        $methodEmoji = methodIcon($p['payment_method'] ?? '');
        $isStripe = ($p['payment_method'] === 'stripe');
        $isOnlineBanking = in_array($p['payment_method'], ['online_banking', 'duitnow']);
        $isCash = ($p['payment_method'] === 'cash');
        $hasProof = !empty($p['proof_image']);
        $isFaceToFace = ($p['learning_mode'] === 'face_to_face');
        
        $statusIcon = 'bi-hourglass-split';
        $statusBg = 'rgba(255,241,200,.78)';
        $statusColor = '#A06B00';
        $statusLabel = 'Pending';
        
        if ($p['payment_status'] === 'verified') {
          $statusLabel = 'Verified';
          $statusIcon = 'bi-check-circle-fill';
          $statusBg = 'rgba(215,238,219,.78)';
          $statusColor = '#3D7047';
        } elseif ($p['payment_status'] === 'failed') {
          $statusLabel = 'Failed';
          $statusIcon = 'bi-x-circle-fill';
          $statusBg = 'rgba(255,200,200,.78)';
          $statusColor = '#C94F4F';
        } elseif ($p['payment_status'] === 'disputed') {
          $statusLabel = 'Disputed - Under Review';
          $statusIcon = 'bi-exclamation-triangle-fill';
          $statusBg = 'rgba(255,200,200,.78)';
          $statusColor = '#C94F4F';
        } elseif ($p['payment_status'] === 'pending') {
          if ($isStripe) {
            $statusLabel = 'Processing';
            $statusIcon = 'bi-arrow-repeat';
          } elseif ($isOnlineBanking && $hasProof) {
            $statusLabel = 'Waiting Verification';
            $statusIcon = 'bi-clock-history';
          } elseif ($isOnlineBanking && !$hasProof) {
            $statusLabel = 'Pending Payment';
            $statusIcon = 'bi-hourglass-split';
          } elseif ($isCash && $isFaceToFace) {
            $statusLabel = 'Pay Cash to Tutor';
            $statusIcon = 'bi-cash-stack';
          }
        }
      ?>
        <div class="payment-card" 
          id="card-<?= $p['payment_id'] ?>" 
          data-payment-id="<?= $p['payment_id'] ?>" 
          data-booking-id="<?= $p['booking_id'] ?>" 
          data-amount="<?= $p['amount'] ?>"
          data-method="<?= $p['payment_method'] ?>">
        
          <div class="pay-top">
            <img src="<?= e($tPic) ?>" class="tutor-img" alt="<?= e($p['tutor_name']) ?>">
            <div class="pay-top-info">
              <h4><?= e($p['language']) ?> with <?= e($p['tutor_name']) ?></h4>
              <p class="sub">
                <i class="bi bi-calendar3"></i> Session: <?= date('D, d M Y', strtotime($p['booking_date'])) ?>
                &nbsp;·&nbsp;
                <i class="bi bi-clock"></i> <?= date('g:i A', strtotime($p['booking_time'])) ?>
                &nbsp;·&nbsp;
                <?= $p['learning_mode']==='online'?'💻 Online':'🤝 Face to Face' ?>
              </p>
            </div>
            <span class="status-badge" style="background:<?= $statusBg ?>;color:<?= $statusColor ?>;">
              <i class="bi <?= $statusIcon ?>"></i> <?= $statusLabel ?>
            </span>
          </div>
          
          <div class="pay-body">
            <div class="pay-item">
              <div class="lbl">Amount</div>
              <div class="val amount">RM <?= e(number_format($p['amount'], 2)) ?></div>
            </div>
            <div class="pay-item">
              <div class="lbl">Method</div>
              <div class="val"><?= $methodEmoji ?> <?= e($methodLabel) ?></div>
            </div>
            <div class="pay-item">
              <div class="lbl">Paid On</div>
              <div class="val" style="font-size:13px;"><?= date('d M Y, g:i A', strtotime($p['paid_at'])) ?></div>
            </div>
            <div class="pay-item">
              <div class="lbl">Receipt No.</div>
              <div class="val" style="font-size:13px;"><?= e($p['receipt_number'] ?? '—') ?></div>
            </div>
          </div>
          
          <div class="pay-actions">
            <a href="booking_detail.php?id=<?= $p['booking_id'] ?>" class="btn-action primary">
              <i class="bi bi-eye"></i> View Booking
            </a>

            <?php if ($p['payment_status'] === 'verified'): ?>
              <?php if ($p['payment_method'] === 'stripe'): ?>
                <a href="receipt_stripe.php?booking_id=<?= $p['booking_id'] ?>&action=pdf" class="btn-action ghost">
                  <i class="bi bi-download"></i> Download Receipt
                </a>
                <a href="receipt_stripe.php?booking_id=<?= $p['booking_id'] ?>&action=email" class="btn-action ghost">
                  <i class="bi bi-envelope"></i> Email Receipt
                </a>
              <?php else: ?>
                <a href="receipt.php?booking_id=<?= $p['booking_id'] ?>&action=pdf" class="btn-action ghost">
                  <i class="bi bi-download"></i> Download Receipt
                </a>
                <a href="receipt.php?booking_id=<?= $p['booking_id'] ?>&action=email" class="btn-action ghost">
                  <i class="bi bi-envelope"></i> Email Receipt
                </a>
              <?php endif; ?>
            <?php elseif ($p['payment_status'] === 'pending'): ?>
              <?php if ($isStripe): ?>
                <a href="payment_form.php?booking_id=<?= $p['booking_id'] ?>&method=stripe" class="btn-action purple">
                  <i class="bi bi-credit-card"></i> Pay with Card
                </a>
                <button class="btn-action ghost" onclick="cancelPayment(<?= $p['payment_id'] ?>, <?= $p['booking_id'] ?>)">
                  <i class="bi bi-x-circle"></i> Cancel
                </button>
              <?php elseif ($isOnlineBanking && $hasProof): ?>
                <span class="btn-action muted">
                  <i class="bi bi-hourglass-split"></i> Awaiting Admin Verification
                </span>
                <a href="../uploads/payment_proofs/<?= e($p['proof_image']) ?>" target="_blank" class="btn-action ghost">
                  <i class="bi bi-image"></i> View My Proof
                </a>
              <?php elseif ($isOnlineBanking && !$hasProof): ?>
                <button class="btn-action ghost" onclick="cancelPayment(<?= $p['payment_id'] ?>, <?= $p['booking_id'] ?>)">
                  <i class="bi bi-x-circle"></i> Cancel
                </button>
              <?php elseif ($isCash && $isFaceToFace): ?>
                <span class="btn-action muted">
                  <i class="bi bi-cash-stack"></i> Pay RM <?= number_format($p['amount'], 2) ?> cash to tutor during class
                </span>
                <button class="btn-action ghost" onclick="cancelPayment(<?= $p['payment_id'] ?>, <?= $p['booking_id'] ?>)">
                  <i class="bi bi-x-circle"></i> Cancel
                </button>
              <?php else: ?>
                <span class="btn-action muted">
                  <i class="bi bi-info-circle"></i> Contact Support
                </span>
              <?php endif; ?>
            <?php elseif ($p['payment_status'] === 'failed'): ?>
              <div style="display: flex; gap: 12px; flex-wrap: wrap;">
                <a href="payment_form.php?booking_id=<?= $p['booking_id'] ?>" class="btn-action purple">
                  <i class="bi bi-arrow-repeat"></i> Retry Payment
                </a>
                <button class="btn-action ghost" onclick="reportPaymentIssue(<?= $p['payment_id'] ?>, <?= $p['booking_id'] ?>, <?= $p['amount'] ?>)">
                  <i class="bi bi-exclamation-triangle"></i> Money Already Deducted?
                </button>
              </div>
            <?php elseif ($p['payment_status'] === 'disputed'): ?>
              <span class="btn-action muted">
                <i class="bi bi-clock-history"></i> Admin is reviewing your dispute
              </span>
            <?php endif; ?>

            <?php if ($hasProof && $p['payment_status'] !== 'pending'): ?>
              <a href="../uploads/payment_proofs/<?= e($p['proof_image']) ?>" target="_blank" class="btn-action ghost">
                <i class="bi bi-image"></i> View Proof
              </a>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <!-- STICKY BULK ACTION BAR - MOVED OUTSIDE PAYMENT LIST -->
  <div id="bulkBar" class="bulk-action-bar">
    <div class="bulk-action-inner">
      <div class="bulk-info">
        <div class="bi-line">Selected items</div>
        <div>
          <span class="b-count" id="barCount">0 items</span>
          <span class="b-amount" id="barAmount"></span>
        </div>
      </div>
      <div class="bulk-btns">
        <button class="bulk-btn clear" onclick="clearAll()">
          <i class="bi bi-x-lg"></i> Clear
        </button>
        <button class="bulk-btn pay" id="barPayBtn" onclick="proceedToBulkPayment()" style="display:none">
          <i class="bi bi-credit-card"></i> Pay Selected
        </button>
        <button class="bulk-btn download" id="barDlBtn" onclick="bulkDownload()" style="display:none">
          <i class="bi bi-download"></i> Download Receipts
        </button>
      </div>
    </div>
  </div>

</div>

<div class="toast" id="toast"></div>

<script>
// State
const selected = { failed: new Set(), verified: new Set() };
let selectionMode = false;
let selectedPayments = new Set();

function toggleCard(paymentId) {
  const chk = document.getElementById('chk-' + paymentId);
  if (!chk) return;
  chk.checked = !chk.checked;
  onCheckboxChange(chk);
}

function onCheckboxChange(chk) {
  const type = chk.dataset.type;
  const bookingId = chk.dataset.bookingId;
  const card = chk.closest('.payment-card');

  if (chk.checked) {
    selected[type].add(bookingId);
    card.classList.add('selected');
  } else {
    selected[type].delete(bookingId);
    card.classList.remove('selected');
  }
  updateUI();
}

function toggleSelectAll(type) {
  const checkboxes = document.querySelectorAll(`.pay-checkbox[data-type="${type}"]`);
  const allChecked = [...checkboxes].every(c => c.checked);

  checkboxes.forEach(chk => {
    chk.checked = !allChecked;
    const card = chk.closest('.payment-card');
    if (!allChecked) {
      selected[type].add(chk.dataset.bookingId);
      card.classList.add('selected');
    } else {
      selected[type].delete(chk.dataset.bookingId);
      card.classList.remove('selected');
    }
  });

  const btn = document.getElementById('selectAll' + (type === 'failed' ? 'Failed' : 'Verified'));
  if (btn) {
    btn.classList.toggle('active', !allChecked);
    btn.querySelector('i').className = !allChecked ? 'bi bi-check-square' : 'bi bi-square';
  }
  updateUI();
}

function reportPaymentIssue(paymentId, bookingId, amount) {
  if (confirm(`Has RM ${amount} been deducted from your bank account/credit card?\n\nAdmin will verify your payment within 24 hours.`)) {
    fetch('report_payment_issue.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ payment_id: paymentId, booking_id: bookingId, amount: amount })
    })
    .then(response => response.json())
    .then(data => {
      if (data.success) {
        showToast('Issue reported! Admin will verify within 24 hours.');
        setTimeout(() => location.reload(), 2000);
      } else {
        showToast('Error: ' + data.message, true);
      }
    })
    .catch(error => showToast('Error reporting issue', true));
  }
}

function clearAll() {
  document.querySelectorAll('.pay-checkbox').forEach(chk => {
    chk.checked = false;
    chk.closest('.payment-card').classList.remove('selected');
  });
  selected.failed.clear();
  selected.verified.clear();
  ['selectAllFailed','selectAllVerified'].forEach(id => {
    const btn = document.getElementById(id);
    if (btn) {
      btn.classList.remove('active');
      btn.querySelector('i').className = 'bi bi-square';
    }
  });
  updateUI();
}

function updateUI() {
  const totalCount = selected.failed.size + selected.verified.size;
  const tbCount = document.getElementById('tbCount');
  if (tbCount) {
    tbCount.style.display = totalCount > 0 ? '' : 'none';
    document.getElementById('tbCountNum').textContent = totalCount;
  }

  const bar = document.getElementById('bulkBar');
  bar.classList.toggle('visible', totalCount > 0);
  document.getElementById('barCount').textContent = totalCount + ' item' + (totalCount !== 1 ? 's' : '');

  let failedTotal = 0;
  document.querySelectorAll('.pay-checkbox[data-type="failed"]').forEach(chk => {
    if (chk.checked) failedTotal += parseFloat(chk.dataset.amount || 0);
  });
  document.getElementById('barAmount').textContent = selected.failed.size > 0 ? '· RM ' + failedTotal.toFixed(2) + ' to pay' : '';
  document.getElementById('barPayBtn').style.display = selected.failed.size > 0 ? '' : 'none';
  document.getElementById('barDlBtn').style.display = selected.verified.size > 0 ? '' : 'none';

  ['failed','verified'].forEach(type => {
    const checkboxes = document.querySelectorAll(`.pay-checkbox[data-type="${type}"]`);
    const allChecked = checkboxes.length > 0 && [...checkboxes].every(c => c.checked);
    const btn = document.getElementById(type === 'failed' ? 'selectAllFailed' : 'selectAllVerified');
    if (btn) {
      btn.classList.toggle('active', allChecked);
      btn.querySelector('i').className = allChecked ? 'bi bi-check-square' : 'bi bi-square';
    }
  });
}

function toggleSelectionMode() {
  selectionMode = !selectionMode;
  const container = document.querySelector('.payment-list');
  const btn = document.getElementById('selectModeBtn');
  
  if (selectionMode) {
    container.classList.add('selection-mode');
    btn.innerHTML = '<i class="bi bi-x-lg"></i> Cancel Selection';
    btn.style.background = 'linear-gradient(135deg, #dc3545, #c82333)';
    showSelectionIndicator();
    
    document.querySelectorAll('.payment-card').forEach(card => {
      const statusEl = card.querySelector('.status-badge');
      const paymentStatus = statusEl ? statusEl.innerText : '';
      const isSelectable = paymentStatus.includes('Pending') || paymentStatus.includes('Awaiting') || paymentStatus.includes('Processing') || paymentStatus.includes('Failed');
      
      if (isSelectable) {
        card.style.cursor = 'pointer';
        card.style.opacity = '1';
        card.onclick = (e) => {
          if (e.target.tagName === 'BUTTON' || e.target.tagName === 'A' || e.target.closest('button') || e.target.closest('a')) return;
          const selectId = card.dataset.paymentId || ('booking-' + card.dataset.bookingId);
          toggleCardSelection(selectId);
        };
      } else {
        card.style.opacity = '0.6';
        card.style.cursor = 'not-allowed';
        card.onclick = null;
      }
    });
  } else {
    container.classList.remove('selection-mode');
    btn.innerHTML = '<i class="bi bi-check2-square"></i> Select More to Pay';
    btn.style.background = 'linear-gradient(135deg, var(--hot-pink), var(--pink))';
    clearSelection();
    hideSelectionIndicator();
    
    document.querySelectorAll('.payment-card').forEach(card => {
      card.style.cursor = '';
      card.style.opacity = '';
      card.onclick = null;
      card.classList.remove('selected');
    });
  }
}

function showSelectionIndicator() {
  let indicator = document.getElementById('selectionIndicator');
  if (!indicator) {
    indicator = document.createElement('div');
    indicator.id = 'selectionIndicator';
    indicator.className = 'selection-mode-indicator';
    indicator.innerHTML = `
      <div style="display: flex; align-items: center; gap: 20px;">
        <i class="bi bi-check2-square" style="font-size: 22px;"></i>
        <span id="selectedCount">0</span> payment(s) selected
        <span id="selectedAmount" style="font-weight: 900; color: #F28AB2;"></span>
      </div>
      <div style="display: flex; gap: 12px;">
        <button class="cancel-btn" onclick="clearSelection()">Clear All</button>
        <button class="cancel-btn" onclick="toggleSelectionMode()" style="background: rgba(255,255,255,0.15);">Cancel</button>
        <button class="cancel-btn" onclick="proceedToBulkPayment()" style="background: #28a745;">Pay Now</button>
      </div>
    `;
    document.body.appendChild(indicator);
  }
  indicator.style.display = 'flex';
}

function hideSelectionIndicator() {
  const indicator = document.getElementById('selectionIndicator');
  if (indicator) indicator.style.display = 'none';
}

function toggleCardSelection(id) {
  const card = document.getElementById('card-' + id) || document.getElementById('card-booking-' + id);
  if (!card) return;
  
  if (selectedPayments.has(String(id))) {
    selectedPayments.delete(String(id));
    card.classList.remove('selected');
  } else {
    selectedPayments.add(String(id));
    card.classList.add('selected');
  }
  updateSelectionUI();
}

function clearSelection() {
  selectedPayments.clear();
  document.querySelectorAll('.payment-card.selected').forEach(card => card.classList.remove('selected'));
  updateSelectionUI();
  if (selectedPayments.size === 0 && selectionMode) {
    const indicator = document.getElementById('selectionIndicator');
    if (indicator) {
      document.getElementById('selectedCount').innerText = '0';
      document.getElementById('selectedAmount').innerHTML = '';
    }
  }
}

function updateSelectionUI() {
  const count = selectedPayments.size;
  const indicator = document.getElementById('selectionIndicator');
  if (indicator) {
    document.getElementById('selectedCount').innerText = count;
    let totalAmount = 0;
    selectedPayments.forEach(paymentId => {
      const card = document.getElementById(`card-${paymentId}`);
      if (card && card.dataset.amount) totalAmount += parseFloat(card.dataset.amount || 0);
    });
    document.getElementById('selectedAmount').innerHTML = totalAmount > 0 ? `· RM ${totalAmount.toFixed(2)}` : '';
  }
}

function proceedToBulkPayment() {
  if (selectedPayments.size === 0) {
    showToast('Please select at least one payment to proceed');
    return;
  }
  
  const bookingIds = [];
  selectedPayments.forEach(id => {
    const card = document.getElementById('card-' + id) || document.getElementById('card-booking-' + id);
    if (card && card.dataset.bookingId) bookingIds.push(card.dataset.bookingId);
  });
  
  if (bookingIds.length === 0) return;
  if (bookingIds.length === 1) {
    window.location.href = 'payment_form.php?booking_id=' + bookingIds[0];
  } else {
    window.location.href = 'payment_form.php?' + bookingIds.map(id => 'booking_ids[]=' + id).join('&');
  }
}

function bulkDownload() {
  const checkboxes = document.querySelectorAll('.pay-checkbox[data-type="verified"]:checked');
  if (!checkboxes.length) return;
  showToast('Opening ' + checkboxes.length + ' receipt(s)…');
  [...checkboxes].forEach((chk, i) => setTimeout(() => window.open(chk.dataset.receipt, '_blank'), i * 350));
}

let toastTimer;
function showToast(msg) {
  const t = document.getElementById('toast');
  t.textContent = msg;
  t.classList.add('show');
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => t.classList.remove('show'), 2200);
}

function toggleDropdown() {
  const d = document.getElementById('profileDropdown');
  d.style.display = d.style.display === 'none' ? 'block' : 'none';
}

function cancelPayment(paymentId, bookingId) {
  if (confirm('Are you sure you want to cancel this payment?')) {
    fetch('cancel_payment.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ payment_id: paymentId, booking_id: bookingId })
    })
    .then(response => response.json())
    .then(data => {
      if (data.success) {
        showToast('Payment cancelled successfully');
        setTimeout(() => location.reload(), 1500);
      } else {
        showToast('Error: ' + data.message, true);
      }
    })
    .catch(error => showToast('Error cancelling payment', true));
  }
}

document.addEventListener('click', function(e) {
  const btn = document.getElementById('profileBtn');
  const dd = document.getElementById('profileDropdown');
  if (btn && dd && !btn.contains(e.target) && !dd.contains(e.target)) dd.style.display = 'none';
});
</script>

<?php include 'nav_search_modal.php'; ?>
<script src="../js/search_modal.js"></script>
</body>
</html>