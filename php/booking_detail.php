<?php
session_start();
include 'config.php';
$assetBase = '../assets/img';

if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit(); }
$userID = $_SESSION['user_id'];
$bookingID = intval($_GET['id'] ?? 0);
if (!$bookingID) { header("Location: booking_status.php"); exit(); }
$stmt = $conn->prepare("
    SELECT b.*, 
           u.fullname AS tutor_name, 
           u.profile_pic AS tutor_pic, 
           u.email AS tutor_email,
           s.phone AS student_phone,  -- Add student phone
           s.fullname AS student_name, -- Add student name
           tp.rate, tp.bio, tp.experience,
           GROUP_CONCAT(DISTINCT tl.language) AS tutor_languages,
           p.id AS payment_id, 
           p.amount AS payment_amount, 
           p.payment_method, 
           p.status AS payment_status,
           p.receipt_number AS receipt_number, 
           p.receipt_url AS receipt_url,
           p.created_at AS paid_at,
           r.id AS rated, 
           r.rating AS my_rating, 
           r.comment AS my_comment,
           r.is_anonymous AS my_anonymous
    FROM bookings b
    JOIN users u ON b.tutor_id = u.id
    JOIN users s ON b.student_id = s.id  -- Add this join for student
    JOIN tutor_profiles tp ON b.tutor_id = tp.user_id
    LEFT JOIN tutor_languages tl ON b.tutor_id = tl.user_id
    LEFT JOIN payments p ON p.booking_id = b.id
    LEFT JOIN ratings r ON r.booking_id = b.id AND r.student_id = ?
    WHERE b.id = ? AND b.student_id = ?
    GROUP BY b.id
");
$stmt->bind_param("iii", $userID, $bookingID, $userID);
$stmt->execute();
$b = $stmt->get_result()->fetch_assoc();

$stmt->close();

if (!$b) { header("Location: booking_status.php"); exit(); }

$user = $conn->query("SELECT * FROM users WHERE id = $userID")->fetch_assoc();
$displayName = $user['fullname'];
$profilePic  = !empty($user['profile_pic']) ? '../uploads/profiles/' . $user['profile_pic'] : $assetBase . '/profile-student.png';
$tutorPic    = !empty($b['tutor_pic']) ? '../uploads/profiles/' . $b['tutor_pic'] : $assetBase . '/profile-tutor.png';
$payStatus   = $b['payment_status'] ?? null;
$payMethod   = $b['payment_method'] ?? '';
$bookStatus  = $b['status'];
$paymentState = 'unpaid';

if (!empty($payStatus)) {

    if ($payStatus === 'failed') {
        $paymentState = 'failed';

    } elseif (in_array($payStatus, ['verified', 'completed', 'approved', 'paid'])) {
    $paymentState = 'paid';

    } elseif (in_array($payStatus, ['pending'])) {
        $paymentState = 'processing';
    }

} else {

    // fallback if no payment row yet
    if ($payMethod === 'cash') {
        $paymentState = 'cash';
    }
}

$displayState = $bookStatus;


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_rating']) && $b['status'] === 'completed' && !$b['rated']) {
    $rating      = intval($_POST['rating'] ?? 0);
    $comment     = trim($_POST['comment'] ?? '');
    $isAnonymous = isset($_POST['is_anonymous']) ? 1 : 0;
    if ($rating >= 1 && $rating <= 5) {
        $stmt = $conn->prepare("INSERT INTO ratings (booking_id, student_id, tutor_id, rating, comment, is_anonymous, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
        $stmt->bind_param("iiiisi", $bookingID, $userID, $b['tutor_id'], $rating, $comment, $isAnonymous);
        $stmt->execute();
        $stmt->close();
        header("Location: booking_detail.php?id=$bookingID&rated=1");
        exit();
    }
}

function e($v){ return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }
function statusCfg($s) {
    $map = [
        'pending'   => ['label'=>'Pending',   'icon'=>'bi-hourglass-split',   'bg'=>'rgba(255,217,199,.74)', 'color'=>'#A35F3F', 'desc'=>'Waiting for tutor to approve your booking.'],
        'accepted'  => ['label'=>'Accepted',   'icon'=>'bi-check-circle',      'bg'=>'rgba(216,236,255,.78)', 'color'=>'#1A5FA8', 'desc'=>'Tutor accepted! Please make payment to confirm your session.'],
        'confirmed' => ['label'=>'Confirmed',  'icon'=>'bi-check-circle-fill', 'bg'=>'rgba(215,238,219,.78)', 'color'=>'#3D7047', 'desc'=>'Payment verified! Your session is confirmed.'],
        'rescheduled' => ['label'=>'Rescheduled', 'icon'=>'bi-calendar-plus', 'bg'=>'rgba(255,241,200,.78)', 'color'=>'#A06B00', 'desc'=>'Your reschedule request is waiting for tutor approval.'],
        'completed' => ['label'=>'Completed',  'icon'=>'bi-patch-check-fill',  'bg'=>'rgba(221,211,255,.78)', 'color'=>'#7648B8', 'desc'=>'Session completed. Don\'t forget to rate your tutor!'],
        'cancelled' => ['label'=>'Cancelled',  'icon'=>'bi-x-circle-fill',     'bg'=>'rgba(255,200,200,.78)', 'color'=>'#C94F4F', 'desc'=>'This booking was cancelled.'],
    ];
    return $map[$s] ?? $map['pending'];
}
$cfg = statusCfg($displayState);
$stateOrder = ['pending'=>0, 'accepted'=>1, 'confirmed'=>2,'rescheduled'=>2, 'completed'=>3];
$currentOrder = $stateOrder[$displayState] ?? 0;
$steps = [
    ['label'=>'Requested', 'icon'=>'bi-send'],
    ['label'=>'Accepted',  'icon'=>'bi-check2'],
    ['label'=>'Confirmed', 'icon'=>'bi-patch-check'],
    ['label'=>'Completed', 'icon'=>'bi-trophy'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Booking Details · Kyoshi</title>
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
    a{text-decoration:none;color:inherit}button,input,select,textarea{font-family:inherit}
    .container{width:min(1440px,calc(100% - 40px));margin:0 auto}
    .inner{width:min(860px,100%);margin:0 auto}

    /* ── TOPBAR (unchanged) ── */
    .topbar{position:sticky;top:0;z-index:50;background:rgba(255,241,246,.86);backdrop-filter:blur(20px);border-bottom:1px solid rgba(231,90,155,.18);box-shadow:0 10px 30px rgba(201,79,134,.10)}
    .nav{min-height:78px;display:grid;grid-template-columns:190px minmax(0,1fr) 360px;gap:16px;align-items:center;}
    .brand{display:flex;align-items:center;gap:10px}
    .brand img{width:44px;height:44px;object-fit:contain;border-radius:14px}
    .brand strong{display:block;font-size:18px;line-height:1.05}
    .brand span{display:block;margin-top:3px;font-size:11px;color:var(--muted);white-space:nowrap}
    .nav-links{display:flex;align-items:center;justify-content:center;gap:6px;border-radius:999px;padding:7px;overflow:auto;scrollbar-width:none;}
    .nav-links::-webkit-scrollbar{display:none}
    .nav-links a{flex:0 0 auto;padding:9px 12px;border-radius:999px;font-size:13px;font-weight:900;color:#6D4964;white-space:nowrap;transition:.18s ease}
    .nav-links a.active,.nav-links a:hover{background:linear-gradient(135deg,var(--hot-pink),var(--pink));color:#fff}
    .nav-actions{display:flex;align-items:center;justify-content:flex-end;gap:10px;min-width:0}
    .search{position:relative;flex:1 1 auto;min-width:0}
    .search i{position:absolute;left:14px;top:50%;transform:translateY(-50%);color:#91899F}
    .search input{width:100%;border:1px solid rgba(46,42,59,.10);background:rgba(255,255,255,.88);outline:none;border-radius:999px;padding:12px 14px 12px 38px;box-shadow:0 10px 26px rgba(201,79,134,.10)}
    .icon-btn{width:44px;height:44px;border-radius:16px;color:#7A4A68;position:relative;flex:0 0 auto;border:1px solid rgba(46,42,59,.08);background:rgba(255,255,255,.88);cursor:pointer;display:grid;place-items:center}
    .dot{position:absolute;top:10px;right:10px;width:8px;height:8px;border-radius:50%;background:#E17C91}
    .profile{display:flex;align-items:center;gap:9px;border-radius:999px;padding:6px 12px 6px 6px;font-weight:900;color:#7A3D65;border:1px solid rgba(46,42,59,.08);background:rgba(255,255,255,.88);cursor:pointer}
    .profile img{width:34px;height:34px;object-fit:cover;border-radius:50%}

    /* ── PAGE ── */
    .page-wrap{padding:28px 0 60px}
    .back-link{display:inline-flex;align-items:center;gap:6px;color:var(--pink-dark);font-weight:900;font-size:13px;padding:9px 16px;border-radius:999px;background:rgba(255,255,255,.78);border:1px solid rgba(46,42,59,.08);transition:.18s ease;margin-bottom:20px;}
    .back-link:hover{transform:translateY(-1px)}

    /* STATUS BANNER */
    .status-banner{border-radius:20px;padding:18px 22px;display:flex;align-items:flex-start;gap:14px;margin-bottom:16px;border:1px solid}
    .status-banner .s-icon{width:46px;height:46px;border-radius:14px;display:grid;place-items:center;font-size:22px;flex:0 0 auto;background:rgba(255,255,255,.55)}
    .status-banner strong{display:block;font-size:15px;font-weight:900;margin-bottom:4px}
    .status-banner p{margin:0;font-size:13px;line-height:1.5;opacity:.9}

    /* PROGRESS STEPS */
    .progress-wrap{background:var(--paper);border:1px solid rgba(255,255,255,.55);box-shadow:0 6px 20px rgba(201,79,134,.08);border-radius:20px;padding:18px 20px;margin-bottom:16px;overflow-x:auto}
    .progress-steps{display:flex;align-items:center;min-width:400px}
    .p-step{flex:1;display:flex;flex-direction:column;align-items:center;position:relative}
    .p-step:not(:last-child)::after{content:"";position:absolute;top:15px;left:calc(50% + 15px);right:calc(-50% + 15px);height:2px;background:rgba(46,42,59,.10);z-index:0}
    .p-step.done::after{background:linear-gradient(90deg,var(--pink),rgba(242,138,178,.3))}
    .p-dot{width:30px;height:30px;border-radius:50%;border:2px solid rgba(46,42,59,.12);background:white;display:grid;place-items:center;font-size:11px;font-weight:900;color:#9080a0;margin-bottom:6px;position:relative;z-index:1;transition:.2s ease}
    .p-step.done .p-dot{background:linear-gradient(135deg,var(--hot-pink),var(--pink));color:white;border-color:var(--pink)}
    .p-step.active .p-dot{background:linear-gradient(135deg,var(--hot-pink),var(--pink));color:white;border-color:var(--pink);box-shadow:0 4px 12px rgba(231,90,155,.3)}
    .p-label{font-size:10px;font-weight:900;color:var(--muted);text-align:center;white-space:nowrap}
    .p-step.active .p-label{color:var(--hot-pink)}
    .p-step.done .p-label{color:var(--pink-dark)}

    /* CARDS */
    .card{background:var(--paper);border:1px solid rgba(255,255,255,.55);box-shadow:var(--shadow);border-radius:var(--radius-xl);padding:24px;margin-bottom:16px}
    .card-title{font-size:15px;font-weight:900;margin:0 0 16px;display:flex;align-items:center;gap:8px;color:#342635}
    .card-title i{color:var(--hot-pink)}

    .tutor-row{display:flex;align-items:center;gap:16px}
    .tutor-row img{width:68px;height:68px;object-fit:cover;border-radius:18px;background:#eee;flex:0 0 auto}
    .tutor-row h3{margin:0 0 4px;font-size:17px}
    .tutor-row p{margin:0;color:var(--muted);font-size:13px}
    .view-profile{display:inline-flex;align-items:center;gap:5px;margin-top:8px;padding:6px 14px;border-radius:999px;background:rgba(231,90,155,.1);color:var(--hot-pink);font-size:12px;font-weight:900;border:1px solid rgba(231,90,155,.2)}

    .detail-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
    .detail-item{background:rgba(255,241,246,.5);border:1px solid rgba(242,138,178,.15);border-radius:14px;padding:14px}
    .detail-item .dlabel{font-size:11px;font-weight:900;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:5px}
    .detail-item .dval{font-size:14px;font-weight:900;color:var(--ink)}
    .tags-row{display:flex;flex-wrap:wrap;gap:5px;margin-top:4px}
    .tag{padding:3px 9px;border-radius:999px;font-size:11px;font-weight:900;background:rgba(221,244,230,.6);color:#2D6A42}

    /* PAYMENT BOX */
    .pay-box{border-radius:16px;padding:16px 20px;margin-bottom:12px}
    .pay-box.paid{background:rgba(215,238,219,.5);border:1px solid rgba(45,106,66,.2)}
    .pay-box.unpaid{background:rgba(255,217,199,.4);border:1px solid rgba(163,95,63,.2)}
    .pay-box.review{background:rgba(221,211,255,.4);border:1px solid rgba(118,72,184,.2)}
    .pay-box.cancelled{background:rgba(255,200,200,.4);border:1px solid rgba(201,79,134,.2)}
    .pay-row{display:flex;justify-content:space-between;align-items:center;font-size:13px;padding:7px 0;border-bottom:1px solid rgba(46,42,59,.06)}
    .pay-row:last-child{border-bottom:none}
    .pay-row .pl{color:var(--muted);font-weight:700}
    .pay-row .pv{font-weight:900}

    /* CANCEL BOX */
    .cancel-info{border-radius:16px;padding:16px 20px;background:rgba(255,200,200,.4);border:1px solid rgba(201,79,134,.2)}
    .cancel-who{display:inline-flex;align-items:center;gap:6px;padding:5px 12px;border-radius:999px;font-size:12px;font-weight:900;margin-bottom:10px}
    .cancel-who.student{background:rgba(255,217,199,.7);color:#A35F3F}
    .cancel-who.tutor{background:rgba(221,211,255,.7);color:#7648B8}
    .cancel-who.admin{background:rgba(255,200,200,.7);color:#C94F4F}
    .cancel-reason{font-size:13px;color:#7A3030;font-weight:700;line-height:1.5}

    /* ACTIONS */
    .action-bar{display:flex;gap:10px;flex-wrap:wrap;margin-top:14px}
    .btn-primary{padding:11px 22px;border-radius:999px;border:none;background:linear-gradient(135deg,var(--hot-pink),var(--pink));color:white;font-size:13px;font-weight:900;cursor:pointer;box-shadow:0 8px 20px rgba(231,90,155,.28);text-decoration:none;display:inline-flex;align-items:center;gap:7px;transition:.18s ease}
    .btn-primary:hover{transform:translateY(-1px)}
    .btn-secondary{padding:11px 22px;border-radius:999px;border:1px solid rgba(46,42,59,.12);background:white;color:#7A5570;font-size:13px;font-weight:900;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;gap:7px;transition:.18s ease}
    .btn-secondary:hover{transform:translateY(-1px)}
    .btn-danger{padding:11px 22px;border-radius:999px;border:1px solid rgba(201,79,134,.3);background:white;color:var(--pink-dark);font-size:13px;font-weight:900;cursor:pointer;display:inline-flex;align-items:center;gap:7px;transition:.18s ease}
    .btn-danger:hover{background:rgba(255,241,246,.8)}

    /* RATING */
    .star-row{display:flex;gap:8px;margin:12px 0}
    .star-btn{width:40px;height:40px;border-radius:12px;border:2px solid rgba(46,42,59,.10);background:white;font-size:20px;cursor:pointer;transition:.15s ease;display:grid;place-items:center}
    .star-btn.active{border-color:#FFB800;background:rgba(255,184,0,.1)}
    .star-display{display:flex;gap:4px}
    .star-display i{color:#FFB800;font-size:18px}

    .info-note{padding:12px 16px;border-radius:14px;background:rgba(221,211,255,.3);border:1px solid rgba(167,123,232,.2);font-size:13px;color:#6D4964;font-weight:700;line-height:1.5}

    /* MODAL */
    .modal-overlay{position:fixed;inset:0;background:rgba(52,38,53,.5);backdrop-filter:blur(6px);z-index:200;display:none;place-items:center}
    .modal-overlay.show{display:grid}
    .modal-box{background:white;border-radius:24px;padding:28px;max-width:420px;width:calc(100% - 40px);box-shadow:0 30px 60px rgba(201,79,134,.2)}
    .modal-box h3{margin:0 0 8px;font-size:18px}
    .modal-box p{margin:0 0 20px;color:var(--muted);font-size:14px;line-height:1.5}
    .modal-actions{display:flex;gap:10px;justify-content:flex-end}

    .toast{position:fixed;left:50%;bottom:28px;transform:translate(-50%,18px);opacity:0;pointer-events:none;z-index:99;background:#8E3F70;color:#fff;border-radius:999px;padding:12px 18px;font-size:13px;font-weight:900;transition:.2s ease}
    .toast.show{opacity:1;transform:translate(-50%,0)}

    @media(max-width:768px){
      .detail-grid{grid-template-columns:1fr}
      .nav{grid-template-columns:1fr auto}
      .nav-links{display:none}
    }

    .star-btn { font-size:24px; width:44px; height:44px; }
#starRow { gap:10px; margin:14px 0; }
.card-title { font-size:16px; }
.star-display i { font-size:20px; }

    .completed-grid{
  display:grid;
  grid-template-columns:1fr 1fr;
  gap:16px;
  align-items:start;
}

@media(max-width:768px){
  .completed-grid{
    grid-template-columns:1fr;
  }
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
          <a class="active" href="booking_status.php">My Bookings</a>
          <a href="my_payments.php">My Payments</a>
          <a href="my_materials.php">My Materials</a>
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
<?php 
// Check if session has ended and needs confirmation
$class_time = strtotime($b['booking_date'] . ' ' . $b['booking_time']);
$current_time = time();
$is_past_class = $class_time < $current_time;
$is_confirmed = ($bookStatus === 'confirmed');
$is_completed = ($bookStatus === 'completed');
$hours_passed = round(($current_time - $class_time) / 3600, 1);
$role = $_SESSION['role']; // 'student'

// Get completion status from session_completion table
$completionStmt = $conn->prepare("
    SELECT tutor_confirmed, student_confirmed FROM session_completion 
    WHERE booking_id = ?
");
$completionStmt->bind_param("i", $bookingID);
$completionStmt->execute();
$completion = $completionStmt->get_result()->fetch_assoc();

$tutor_confirmed = $completion['tutor_confirmed'] ?? false;
$student_confirmed = $completion['student_confirmed'] ?? false;
$both_confirmed = ($tutor_confirmed && $student_confirmed);
?>

<div class="container">
  <div class="page-wrap">
    <a href="booking_status.php" class="back-link"><i class="bi bi-arrow-left"></i> Back to My Bookings</a>
<div class="status-banner" style="background:<?= $cfg['bg'] ?>;border:1px solid <?= $cfg['color'] ?>33;align-items:center;">
    <div class="s-icon" style="color:<?= $cfg['color'] ?>"><i class="bi <?= $cfg['icon'] ?>"></i></div>
    <div style="flex:1;">
        <strong style="color:<?= $cfg['color'] ?>"><?= $cfg['label'] ?></strong>
        <p style="color:<?= $cfg['color'] ?>;margin:0;text-size:25px;">
            <?php 
            if ($is_past_class && $is_confirmed && !$is_completed) {
                echo '⚠️ Session has ended. Please confirm your attendance.';
            } elseif ($is_completed) {
                echo '✨Session completed. Thank you for attending!';
            } elseif ($is_confirmed) {
                echo '✨Payment verified! Your session is confirmed.';
            } else {
                echo $cfg['desc'];
            }
            ?>
        </p>
        <?php if ($is_completed && ($b['auto_completed'] ?? false)): ?>
        <small style="color:<?= $cfg['color'] ?>; text-size:25px; opacity:0.8;">(Auto-completed after 24 hours)</small>
        <?php endif; ?>
    </div>

    <?php if ($is_past_class && $is_confirmed && !$is_completed): ?>
    <div style="display:flex;gap:8px;flex-shrink:0;">
        <form method="POST" action="confirm_session.php" style="margin:0;">
            <input type="hidden" name="booking_id" value="<?= $bookingID ?>">
            <input type="hidden" name="action" value="confirm">
            <button type="submit" style="background:linear-gradient(135deg,#28a745,#20c997);color:white;padding:10px 18px;border:none;border-radius:999px;cursor:pointer;font-weight:900;font-size:13px;white-space:nowrap;display:inline-flex;align-items:center;gap:6px;">
                <i class="bi bi-check-lg"></i> Confirm Attendance
            </button>
        </form>
        <button onclick="showReportIssue(<?= $bookingID ?>)" style="background:#dc3545;color:white;padding:10px 18px;border:none;border-radius:999px;cursor:pointer;font-weight:900;font-size:13px;white-space:nowrap;display:inline-flex;align-items:center;gap:6px;">
            <i class="bi bi-exclamation-triangle"></i> Report
        </button>
    </div>
    <?php endif; ?>
</div>

<!-- ========== END COMPLETION SECTION ========== -->

    <?php if ($bookStatus !== 'cancelled'): ?>
    <div class="progress-wrap">
      <div class="progress-steps">
        <?php foreach ($steps as $i => $step): ?>
          <div class="p-step <?= $i < $currentOrder ? 'done' : ($i === $currentOrder ? 'active' : '') ?>">
            <div class="p-dot"><?= $i < $currentOrder ? '✓' : ($i + 1) ?></div>
            <div class="p-label"><?= $step['label'] ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <div class="card">
  <div class="card-title"><i class="bi bi-calendar-check"></i> Booking Details</div>
  
<div style="display:flex;gap:24px;align-items:flex-start;">

  <!-- LEFT: Tutor -->
  <div style="flex:0 0 300px;display:flex;flex-direction:column;">
    <p style="margin:0 0 12px;font-size:11px;font-weight:900;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;">Tutor</p>
    <div style="background:rgba(255,241,246,.5);border:1px solid rgba(242,138,178,.15);border-radius:16px;padding:16px;height:100%;">
      <div style="display:flex;align-items:center;gap:12px;margin-bottom:14px;">
        <img src="<?= e($tutorPic) ?>" style="width:60px;height:60px;object-fit:cover;border-radius:14px;background:#eee;flex-shrink:0;">
        <div style="margin-bottom:28px">
          <strong style="display:block;font-size:15px;margin-bottom:3px;"><?= e($b['tutor_name']) ?></strong>
          <span style="display:block;font-size:12px;color:var(--muted);"><?= e($b['experience']) ?> yrs exp · RM <?= e($b['rate']) ?>/hr</span>
          <span style="display:block;font-size:12px;color:var(--muted);margin-top:2px;"><?= e($b['tutor_languages']) ?></span>
        </div>
      </div>
      <a href="tutor_profile.php?id=<?= $b['tutor_id'] ?>"  class="view-profile" style="margin-top:auto;">
        <i class="bi bi-arrow-up-right"></i> View Profile
      </a>
    </div>
  </div>

  <!-- RIGHT: Session -->
  <div style="flex:1;min-width:0;">
    <p style="margin:0 0 12px;font-size:11px;font-weight:900;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;">Session</p>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
        
        <div class="detail-item">
          <div class="dlabel">Language</div>
          <div class="dval"><?= e($b['language']) ?></div>
        </div>

        <div class="detail-item">
          <div class="dlabel">Date & Time</div>
          <div class="dval"><?= date('D, d M Y', strtotime($b['booking_date'])) ?></div>
          <div style="font-size:13px;color:var(--muted);font-weight:700;margin-top:2px;"><?= date('g:i A', strtotime($b['booking_time'])) ?></div>
        </div>

        <div class="detail-item">
          <div class="dlabel">Learning Mode</div>
          <div class="dval"><?= $b['learning_mode']==='online'?' Online':' Face to Face' ?></div>
          <?php if (!empty($b['meeting_location'])): ?>
            <div style="font-size:12px;color:var(--muted);margin-top:3px;"><i class="bi bi-geo-alt"></i> <?= e($b['meeting_location']) ?></div>
          <?php endif; ?>
        </div>

        <div class="detail-item">
          <div class="dlabel">Focus Areas</div>
          <?php if (!empty($b['focus'])): ?>
            <div class="tags-row">
              <?php foreach(explode(',',$b['focus']) as $f): if(trim($f)): ?>
                <span class="tag"><?= e(trim($f)) ?></span>
              <?php endif; endforeach; ?>
            </div>
          <?php else: ?>
            <div class="dval">—</div>
          <?php endif; ?>
        </div>

        <?php if (!empty($b['notes'])): ?>
        <div class="detail-item" style="grid-column:1/-1;">
          <div class="dlabel">Notes</div>
          <div class="dval" style="font-weight:700;font-size:13px;line-height:1.5;"><?= e($b['notes']) ?></div>
        </div>
        <?php endif; ?>

      </div>
    </div>

  </div>
</div>

    <!-- PAYMENT -->
    <div class="card">
      <div class="card-title"><i class="bi bi-credit-card"></i> Payment</div>
            <?php if ($bookStatus === 'pending'): ?>
            <div class="info-note">
            <i class="bi bi-info-circle"></i> Payment will be available once the tutor accepts your booking.
            </div>  
            <?php elseif ($bookStatus === 'rescheduled'): ?>
    <div class="pay-box review">
        <div class="pay-row">
            <span class="pl">Status</span>
            <span class="pv" style="color:#A06B00;">
                <i class="bi bi-calendar-plus"></i> Reschedule Requested
            </span>
        </div>
        <div class="pay-row">
            <span class="pl">Amount</span>
            <span class="pv">
                RM <?= e(number_format($b['payment_amount'] ?? $b['rate'], 2)) ?>
            </span>
        </div>
        <?php if (!empty($b['payment_method'])): ?>
        <div class="pay-row">
            <span class="pl">Method</span>
            <span class="pv"><?= e(ucwords(str_replace('_', ' ', $b['payment_method']))) ?></span>
        </div>
        <?php endif; ?>
    </div>
    <div class="info-note">
        <i class="bi bi-info-circle"></i> Your reschedule request has been sent. Waiting for tutor to approve the new schedule.
    </div>
    <?php elseif ($bookStatus === 'accepted'): ?>

  <?php if ($paymentState === 'processing'): ?>
    <div class="pay-box review">
      <div class="pay-row"><span class="pl">Status</span><span class="pv" style="color:#A06B00;"><i class="bi bi-hourglass-split"></i> Payment Under Review</span></div>
      <div class="pay-row"><span class="pl">Amount</span><span class="pv">RM <?= e(number_format($b['payment_amount'] ?? 0,2)) ?></span></div>
      <?php if (!empty($b['payment_method'])): ?>
      <div class="pay-row"><span class="pl">Method</span><span class="pv"><?= e(ucwords(str_replace('_',' ',$b['payment_method']))) ?></span></div>
      <?php endif; ?>
      <?php if (!empty($b['paid_at'])): ?>
      <div class="pay-row"><span class="pl">Submitted On</span><span class="pv"><?= date('d M Y, g:i A', strtotime($b['paid_at'])) ?></span></div>
      <?php endif; ?>
    </div>
    <div class="info-note"><i class="bi bi-info-circle"></i> Your payment is being verified. Session will be confirmed shortly.</div>

  <?php elseif ($paymentState === 'failed'): ?>
    <div class="pay-box cancelled">
      <div class="pay-row"><span class="pl">Status</span><span class="pv" style="color:#C94F4F;"><i class="bi bi-x-circle-fill"></i> Payment Failed</span></div>
    </div>
    <div class="action-bar">
      <a href="payment_form.php?booking_id=<?= $b['id'] ?>" class="btn-primary"><i class="bi bi-credit-card"></i> Retry Payment</a>
    </div>

  <?php else: ?>
    <div class="pay-box unpaid">
      <div class="pay-row"><span class="pl">Status</span><span class="pv" style="color:#A35F3F;"><i class="bi bi-clock"></i> Awaiting Payment</span></div>
      <div class="pay-row"><span class="pl">Amount Due</span><span class="pv" style="color:var(--hot-pink);font-size:18px;">RM <?= e($b['rate']) ?></span></div>
    </div>
    <div class="info-note" style="margin-bottom:12px;"><i class="bi bi-info-circle"></i> After payment, admin will verify and confirm your session within 1–2 business days.</div>
    <div class="action-bar">
      <a href="payment_form.php?booking_id=<?= $b['id'] ?>" class="btn-primary"><i class="bi bi-credit-card"></i> Pay Now</a>
      <button class="btn-danger" onclick="openCancelModal()"><i class="bi bi-x-circle"></i> Cancel Booking</button>
    </div>
  <?php endif; ?>
<?php elseif ($bookStatus === 'confirmed'): ?>

<div class="pay-box paid">
    <div class="pay-row">
        <span class="pl">Status</span>
        <span class="pv" style="color:#3D7047;">
            <i class="bi bi-check-circle-fill"></i>
            Payment Paid & Verified
        </span>
    </div>

    <div class="pay-row">
    <span class="pl">Amount</span>
    <span class="pv">
        <?php 
        $amount = $b['payment_amount'] ?? 0;
        if ($amount > 0) {
            echo 'RM ' . number_format($amount, 2);
        } else {
            echo '<span style="color: #999;">—</span>';
        }
        ?>
    </span>
</div>

<div class="pay-row">
    <span class="pl">Method</span>
    <span class="pv">
        <?php 
        $method = $b['payment_method'] ?? '';
        if (!empty($method)) {
            echo ucwords(str_replace('_', ' ', $method));
        } else {
            echo '<span style="color: #999;">Not selected</span>';
        }
        ?>
    </span>
</div>

    <?php if (!empty($b['paid_at'])): ?>
      <div class="pay-row"><span class="pl">Submitted On</span><span class="pv"><?= date('d M Y, g:i A', strtotime($b['paid_at'])) ?></span></div>
      <?php endif; ?>
    </div>
</div>

<div class="action-bar" style="margin-top:16px;">
    <a href="reschedule_booking.php?id=<?= $bookingID ?>" class="btn-primary">
        <i class="bi bi-calendar-plus"></i> Reschedule Session
    </a>
    <?php if ($paymentState === 'paid'): ?>
  <?php if ($b['payment_method'] === 'stripe'): ?>
    <a href="receipt_stripe.php?booking_id=<?= $b['id'] ?>&action=pdf" class="btn-secondary">
      <i class="bi bi-download"></i> Download Receipt
    </a>
  <?php else: ?>
    <a href="receipt.php?booking_id=<?= $b['id'] ?>&action=pdf" class="btn-secondary">
      <i class="bi bi-download"></i> Download Receipt
    </a>
  <?php endif; ?>
<?php endif; ?>
</div>

    <?php elseif ($bookStatus === 'completed'): ?>

<?php if($paymentState === 'paid'): ?>
<div class="pay-box paid">
    <div class="pay-row">
        <span class="pl">Status</span>
        <span class="pv" style="color:#3D7047;">
            <i class="bi bi-check-circle-fill"></i> Payment Verified
        </span>
    </div>
    <div class="pay-row">
        <span class="pl">Amount</span>
        <span class="pv">RM <?= e(number_format($b['payment_amount'] ?? 0, 2)) ?></span>
    </div>
    <div class="pay-row">
        <span class="pl">Method</span>
        <span class="pv"><?= e(ucwords(str_replace('_', ' ', $b['payment_method'] ?? ''))) ?></span>
    </div>
    <?php if (!empty($b['receipt_number'])): ?>
    <div class="pay-row">
        <span class="pl">Receipt No.</span>
        <span class="pv"><?= e($b['receipt_number']) ?></span>
    </div>
    <?php endif; ?>
    <?php if (!empty($b['paid_at'])): ?>
    <div class="pay-row">
        <span class="pl">Paid On</span>
        <span class="pv"><?= date('d M Y, g:i A', strtotime($b['paid_at'])) ?></span>
    </div>
    <?php endif; ?>
</div>

<?php elseif($paymentState === 'processing'): ?>

<div class="pay-box review">
  <div class="pay-row">
    <span class="pl">Status</span>
    <span class="pv" style="color:#A06B00;">
      <i class="bi bi-hourglass-split"></i>
      Waiting For Verification
    </span>
  </div>
</div>

<div class="info-note">
<i class="bi bi-info-circle"></i>
Admin will verify your payment within 1–2 business days.
</div>

<?php elseif($paymentState === 'cash'): ?>

<div class="pay-box unpaid">
  <div class="pay-row">
    <span class="pl">Status</span>
    <span class="pv" style="color:#A35F3F;">
      <i class="bi bi-cash"></i>
      Cash Payment
    </span>
  </div>
</div>

<div class="info-note">
<i class="bi bi-info-circle"></i>
Payment made directly during session.
</div>

<?php elseif ($paymentState === 'failed'): ?>
<div class="pay-box cancelled">
  <div class="pay-row">
    <span class="pl">Status</span>
    <span class="pv" style="color:#C94F4F;"><i class="bi bi-x-circle-fill"></i> Payment Failed</span>
  </div>
</div>

<?php else: ?>
<div class="pay-box unpaid">
  <div class="pay-row">
    <span class="pl">Status</span>
    <span class="pv" style="color:#A35F3F;"><i class="bi bi-clock"></i> No Payment Record</span>
  </div>
</div>

<?php endif; ?>

<?php if ($paymentState === 'paid'): ?>
<div class="action-bar" style="margin-top:16px;">
  <?php if ($b['payment_method'] === 'card'): ?>
    <a href="receipt_stripe.php?booking_id=<?= $b['id'] ?>&action=pdf" class="btn-secondary">
      <i class="bi bi-download"></i> Download Receipt
    </a>
  <?php else: ?>
    <a href="receipt.php?booking_id=<?= $b['id'] ?>&action=pdf" class="btn-secondary">
      <i class="bi bi-download"></i> Download Receipt
    </a>
  <?php endif; ?>
</div>
<?php endif; ?>


      <?php elseif ($bookStatus === 'cancelled'): ?>
        <?php
          $cancelledBy  = $b['cancelled_by'] ?? 'student';
          $cancelReason = $b['cancel_reason'] ?? 'No reason provided.';
          $whoLabel = match($cancelledBy) {
            'tutor'  => '🎓 Cancelled by Tutor',
            'admin'  => '🛡️ Cancelled by Admin',
            default  => '👤 Cancelled by You',
          };
        ?>
        <div class="cancel-info">
          <div class="cancel-who <?= e($cancelledBy) ?>"><?= $whoLabel ?></div>
          <div class="cancel-reason"><i class="bi bi-chat-left-text"></i> Reason: <?= e($cancelReason) ?></div>
        </div>
      <?php endif; ?>
    </div>
<?php if ($b['status'] === 'completed'): ?>
<div class="completed-grid">

<div class="card" id="rate">
  <div class="card-title"><i class="bi bi-star"></i> Rate Your Session</div>
  <?php if ($b['rated']): ?>
    <div style="padding:16px;border-radius:14px;background:rgba(221,211,255,.3);border:1px solid rgba(167,123,232,.2);">
      <p style="margin:0 0 8px;font-size:13px;font-weight:900;color:#7648B8;">You rated this session:</p>
      <div class="star-display">
        <?php for($i=1;$i<=5;$i++): ?>
          <i class="bi bi-star<?= $i<=$b['my_rating']?'-fill':'' ?>"></i>
        <?php endfor; ?>
      </div>
      <?php if ($b['my_comment']): ?>
        <p style="margin:10px 0 0;font-size:13px;color:var(--muted);font-style:italic;">"<?= e($b['my_comment']) ?>"</p>
      <?php endif; ?>
      <?php if ($b['my_anonymous']): ?>
        <div style="display:inline-flex;align-items:center;gap:5px;margin-top:8px;padding:4px 12px;border-radius:999px;font-size:11px;font-weight:900;background:rgba(255,241,246,.8);color:var(--pink-dark);border:1px solid rgba(242,138,178,.2);">
          <i class="bi bi-incognito"></i> Posted anonymously
        </div>
      <?php else: ?>
        <div style="display:inline-flex;align-items:center;gap:5px;margin-top:8px;padding:4px 12px;border-radius:999px;font-size:11px;font-weight:900;background:rgba(215,238,219,.5);color:#3D7047;border:1px solid rgba(45,106,66,.2);">
          <i class="bi bi-person-check"></i> Posted as <?= e($displayName) ?>
        </div>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <form method="POST">
      <p style="margin:0 0 10px;font-size:15px;font-weight:700;color:var(--ink);">How was your session with <strong><?= e($b['tutor_name']) ?></strong>?</p>
      <div class="star-row" id="starRow">
        <?php for($i=1;$i<=5;$i++): ?>
          <button type="button" class="star-btn" data-val="<?= $i ?>" onclick="setRating(<?= $i ?>)">⭐</button>
        <?php endfor; ?>
      </div>
      <input type="hidden" name="rating" id="ratingInput" value="0">
      <textarea name="comment" placeholder="Share your experience (optional)..." style="width:100%;padding:14px 16px;border:1px solid rgba(46,42,59,.12);border-radius:14px;outline:none;font-size:14px;resize:vertical;min-height:100px;margin:14px 0;color:var(--ink);"></textarea>

      <!-- Anonymous toggle -->
<label id="anonToggle" style="display:flex;align-items:center;gap:12px;padding:10px 14px;border-radius:14px;border:1px solid rgba(46,42,59,.10);background:rgba(255,255,255,.7);cursor:pointer;margin-bottom:12px;">
  <input type="checkbox" name="is_anonymous" id="anonCheckbox" style="display:none;">
  <i class="bi bi-incognito" style="font-size:18px;color:var(--muted);transition:.15s ease;" id="anonIcon"></i>
  <div>
    <strong style="display:block;font-size:13px;font-weight:900;color:#342635;">Post anonymously</strong>
    <span style="font-size:12px;color:var(--muted);">Your name will be hidden from the tutor and other students.</span>
  </div>
  <div id="anonCheck" style="margin-left:auto;width:20px;height:20px;border-radius:6px;border:2px solid rgba(46,42,59,.15);background:white;display:grid;place-items:center;flex-shrink:0;transition:.15s ease;"></div>
</label>

      <div id="previewBox" style="padding:10px 14px;border-radius:12px;background:rgba(221,211,255,.2);border:1px solid rgba(167,123,232,.15);font-size:12px;color:#6D4964;font-weight:700;margin-bottom:14px;">
        <i class="bi bi-eye"></i> Will appear as: <strong id="previewName"><?= e($displayName) ?></strong>
      </div>

      <button type="submit" name="submit_rating" class="btn-primary"><i class="bi bi-star-fill"></i> Submit Rating</button>
    </form>
  <?php endif; ?>
</div>
<div class="card" style="display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;padding:32px 24px;">
  <div style="width:64px;height:64px;border-radius:20px;background:rgba(231,90,155,.1);display:grid;place-items:center;margin-bottom:16px;">
    <i class="bi bi-arrow-repeat" style="font-size:28px;color:var(--hot-pink);"></i>
  </div>
  <h3 style="margin:0 0 8px;font-size:18px;font-weight:900;">Book Again</h3>
  <p style="margin:0 0 20px;font-size:14px;color:var(--muted);line-height:1.6;max-width:260px;">Had a great session? Book <strong><?= e($b['tutor_name']) ?></strong> again with the same settings.</p>
  <form method="POST" action="booking_form.php?tutor_id=<?= $b['tutor_id'] ?>">
    <input type="hidden" name="prefill_lang" value="<?= e($b['language']) ?>">
    <input type="hidden" name="prefill_mode" value="<?= e($b['learning_mode']) ?>">
    <input type="hidden" name="prefill_focus" value="<?= e($b['focus']) ?>">
    <button type="submit" class="btn-primary" style="padding:12px 28px;"><i class="bi bi-arrow-repeat"></i> Rebook This Tutor</button>
  </form>
</div>    
</div>
<?php endif; ?>
   
    <?php if (
    $displayState === 'pending'
): ?>

<div class="action-bar">
    <button class="btn-danger" onclick="openCancelModal()">
        <i class="bi bi-x-circle"></i>
        Cancel Booking
    </button>
</div>

<?php endif; ?>
  </div>
</div>
</div>
<div class="modal-overlay" id="cancelModal">
  <div class="modal-box">
    <h3>Cancel this booking?</h3>
    <p>Are you sure you want to cancel? This cannot be undone and the tutor will be notified.</p>
    <div class="modal-actions">
      <button onclick="closeCancelModal()" style="padding:10px 20px;border-radius:999px;border:1px solid rgba(46,42,59,.12);background:none;color:#7A5570;font-size:13px;font-weight:900;cursor:pointer;">Keep Booking</button>
      <a href="cancel_booking.php?id=<?= $bookingID ?>" style="padding:10px 20px;border-radius:999px;border:none;background:linear-gradient(135deg,#E75A9B,#F28AB2);color:white;font-size:13px;font-weight:900;text-decoration:none;display:inline-flex;align-items:center;gap:6px;"><i class="bi bi-x-circle"></i> Yes, Cancel</a>
    </div>
  </div>
</div>

<div class="toast" id="toast"></div>
<?php include 'nav_search_modal.php'; ?>
<script src="../js/search_modal.js"></script>
<script>

const studentName = <?= json_encode($displayName) ?>;

const anonCb = document.getElementById('anonCheckbox');
if (anonCb) anonCb.addEventListener('change', function () {

  const anonActive = this.checked;

  const toggle = document.getElementById('anonToggle');
  const check  = document.getElementById('anonCheck');
  const icon   = document.getElementById('anonIcon');

  toggle.style.borderColor =
    anonActive ? 'var(--hot-pink)' : 'rgba(46,42,59,.10)';

  toggle.style.background =
    anonActive ? 'rgba(255,241,246,.8)' : 'rgba(255,255,255,.7)';

  check.style.background =
    anonActive ? 'linear-gradient(135deg,#E75A9B,#F28AB2)' : 'white';

  check.style.borderColor =
    anonActive ? 'var(--pink)' : 'rgba(46,42,59,.15)';

  check.innerHTML = anonActive
    ? '<i class="bi bi-check" style="font-size:13px;color:white;"></i>'
    : '';

  icon.style.color =
    anonActive ? 'var(--hot-pink)' : 'var(--muted)';

  document.getElementById('previewName').textContent =
    anonActive ? 'Anonymous Student' : studentName;
}); // end of anonCb listener
  function setRating(val) {
    document.getElementById('ratingInput').value = val;
    document.querySelectorAll('.star-btn').forEach((btn, i) => {
      btn.classList.toggle('active', i < val);
    });
  }

  function openCancelModal()  { document.getElementById('cancelModal').classList.add('show'); }
  function closeCancelModal() { document.getElementById('cancelModal').classList.remove('show'); }
  document.getElementById('cancelModal').addEventListener('click', function(e) {
    if (e.target === this) closeCancelModal();
  });

  let toastTimer;
  function showToast(msg) {
    const t = document.getElementById('toast');
    t.textContent = msg; t.classList.add('show');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => t.classList.remove('show'), 2500);
  }
  function toggleDropdown() {
    const d = document.getElementById('profileDropdown');
    d.style.display = d.style.display === 'none' ? 'block' : 'none';
  }
  document.addEventListener('click', function(e) {
    const btn = document.getElementById('profileBtn');
    const dd  = document.getElementById('profileDropdown');
    if (btn && dd && !btn.contains(e.target) && !dd.contains(e.target)) dd.style.display = 'none';
  });

  <?php if (isset($_GET['rated'])): ?>showToast('Rating submitted! Thank you 🌟');<?php endif; ?>
  <?php if (isset($_GET['emailed'])): ?>showToast('Receipt sent to your email!');<?php endif; ?>
  <?php if (isset($_GET['email_failed'])): ?>showToast('Failed to send email. Please try again.');<?php endif; ?>
  <?php if (isset($_GET['paid'])): ?>showToast('Payment submitted! Waiting for admin verification.');<?php endif; ?>
  <?php if (isset($_GET['rescheduled'])): ?>showToast('Rescheduled! Waiting for tutor approval.');<?php endif; ?>
</script>
<script>
function showReportIssue(bookingId) {
    const modal = document.createElement('div');
    modal.id = 'reportModal';
    modal.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:1000;display:flex;align-items:center;justify-content:center;';
    modal.innerHTML = `
        <div style="background:white;border-radius:20px;padding:25px;max-width:500px;width:90%;">
            <h3 style="margin-bottom:15px;">Report Issue</h3>
            <form method="POST" action="report_issue.php">
                <input type="hidden" name="booking_id" value="${bookingId}">
                <div style="margin-bottom:15px;">
                    <label style="display:block;margin-bottom:5px;font-weight:bold;">Issue Type</label>
                    <select name="issue_type" required style="width:100%;padding:10px;border-radius:10px;border:1px solid #ddd;">
                        <option value="">Select issue type</option>
                        <option value="tutor_no_show">Tutor didn't attend</option>
                        <option value="technical_issues">Technical issues</option>
                        <option value="wrong_materials">Wrong materials provided</option>
                        <option value="other">Other</option>
                    </select>
                </div>
                <div style="margin-bottom:15px;">
                    <label style="display:block;margin-bottom:5px;font-weight:bold;">Description</label>
                    <textarea name="message" rows="4" required style="width:100%;padding:10px;border-radius:10px;border:1px solid #ddd;" placeholder="Please describe your issue..."></textarea>
                </div>
                <div style="display:flex;gap:10px;">
                    <button type="submit" style="background:#E75A9B;color:white;padding:10px 20px;border:none;border-radius:30px;cursor:pointer;">Submit Report</button>
                    <button type="button" onclick="closeReportModal()" style="background:#ccc;color:#333;padding:10px 20px;border:none;border-radius:30px;cursor:pointer;">Cancel</button>
                </div>
            </form>
        </div>
    `;
    document.body.appendChild(modal);
}

function closeReportModal() {
    const modal = document.getElementById('reportModal');
    if (modal) modal.remove();
}
</script>
</body>
</html>