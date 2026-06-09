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
$bookingID = intval($_GET['id'] ?? 0);

if (!$bookingID) {
    header("Location: student_dashboard.php");
    exit();
}

// Get student info
$stmt = $conn->prepare("SELECT * FROM users WHERE id = ? AND role = 'student'");
$stmt->bind_param("i", $userID);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
if (!$user) { header("Location: login.php"); exit(); }

$displayName = $user['fullname'];
if (!empty($user['profile_pic']) && file_exists('../uploads/profiles/' . $user['profile_pic'])) {
    $profilePic = '../uploads/profiles/' . $user['profile_pic'];
} else {
    $profilePic = $assetBase . '/profile.png';
}
// Get booking details
$stmt = $conn->prepare("
    SELECT b.*, u.fullname as tutor_name, u.profile_pic as tutor_pic, tp.rate
    FROM bookings b
    JOIN users u ON b.tutor_id = u.id
    JOIN tutor_profiles tp ON b.tutor_id = tp.user_id
    WHERE b.id = ? AND b.student_id = ?
    ORDER BY b.booking_date ASC
    LIMIT 1
");
$stmt->bind_param("ii", $bookingID, $userID);
$stmt->execute();
$booking = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$booking) {
    header("Location: student_dashboard.php");
    exit();
}

// Get all slots from the same booking session (last 2 minutes)
$stmt = $conn->prepare("
    SELECT booking_date, booking_time 
    FROM bookings
    WHERE student_id = ? 
    AND tutor_id = ? 
    AND language = ? 
    AND learning_mode = ?
    AND created_at > DATE_SUB(NOW(), INTERVAL 2 MINUTE)
    AND status = 'pending'
    ORDER BY booking_date, booking_time
");
$stmt->bind_param("iiss", $userID, $booking['tutor_id'], $booking['language'], $booking['learning_mode']);
$stmt->execute();
$allSlots = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// If no slots found from recent query, at least show the current booking
if (empty($allSlots)) {
    $allSlots[] = ['booking_date' => $booking['booking_date'], 'booking_time' => $booking['booking_time']];
}

$tutorPic = !empty($booking['tutor_pic'])
    ? '../uploads/profiles/' . $booking['tutor_pic']
    : $assetBase . '/profile-tutor.png';

function e($v){ return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
<meta http-equiv="Pragma" content="no-cache">
<meta http-equiv="Expires" content="0">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Booking Submitted · Kyoshi</title>
  <link rel="stylesheet" href="../css/style.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
  <style>
    :root{
      --cream:#FFF1F6; --paper:rgba(255,255,255,.88); --ink:#342635; --muted:#7B6178;
      --pink:#F28AB2; --pink-dark:#C94F86; --hot-pink:#E75A9B;
      --shadow:0 18px 45px rgba(201,79,134,.16);
      --radius-xl:32px;
    }
    *{box-sizing:border-box} html{scroll-behavior:smooth}
    body{
      margin:0; min-height:100vh; font-family:"Segoe UI",Arial,sans-serif; color:var(--ink);
      background:linear-gradient(120deg,rgba(255,241,246,.74),rgba(255,203,220,.30)),
        url("<?= e($assetBase) ?>/background3.jpg") center/cover fixed no-repeat;
    }
    body::before{content:"";position:fixed;inset:0;pointer-events:none;z-index:-1;
      background:radial-gradient(circle at 7% 10%,rgba(231,90,155,.32),transparent 24%),
        radial-gradient(circle at 90% 8%,rgba(255,195,216,.42),transparent 26%)}
    a{text-decoration:none;color:inherit}
    .container{width:min(1440px, calc(100% - 40px)); margin:0 auto}

    .topbar{
      position:sticky; top:0; z-index:50;
      background:rgba(255,241,246,.86);
      backdrop-filter:blur(20px);
      border-bottom:1px solid rgba(231,90,155,.18);
      box-shadow:0 10px 30px rgba(201,79,134,.10);
    }
    .nav{
      min-height:78px;
      display:grid;
      grid-template-columns:160px 1fr 320px;
      gap:16px;
      align-items:center;
    }
    .brand{display:flex; align-items:center; gap:10px; min-width:0}
    .brand img{width:44px; height:44px; object-fit:contain; border-radius:14px}
    .brand strong{display:block; font-size:18px; line-height:1.05}
    .brand span{display:block; margin-top:3px; font-size:11px; color:var(--muted); white-space:nowrap}

    .nav-links{
      display:flex; align-items:center; justify-content:center; gap:6px;
      overflow:auto; scrollbar-width:none;
      
    }
    .nav-links::-webkit-scrollbar{display:none}
    .nav-links a{flex:0 0 auto; padding:9px 12px; border-radius:999px; font-size:13px; font-weight:900; color:#6D4964; white-space:nowrap; transition:.18s ease}
    .nav-links a.active,.nav-links a:hover{background:linear-gradient(135deg, var(--hot-pink), var(--pink)); color:#fff; box-shadow:0 8px 18px rgba(231,90,155,.28)}

    .nav-actions{display:flex; align-items:center; justify-content:flex-end; gap:10px; min-width:0}
    .search{position:relative; flex:1 1 auto; min-width:150px;}
    .search i{position:absolute; left:14px; top:50%; transform:translateY(-50%); color:#91899F}
    .search input{width:100%; border:1px solid rgba(46,42,59,.10); background:rgba(255,255,255,.88); outline:none; border-radius:999px; padding:12px 14px 12px 38px; box-shadow:var(--shadow-soft)}
    .icon-btn,.profile{border:1px solid rgba(46,42,59,.08); background:rgba(255,255,255,.88); box-shadow:var(--shadow-soft); cursor:pointer}
    .icon-btn{width:44px; height:44px; border-radius:16px; color:#7A4A68; position:relative; flex:0 0 auto}
    .dot{position:absolute; top:10px; right:10px; width:8px; height:8px; border-radius:50%; background:#E17C91}
    .profile{display:flex; align-items:center; gap:9px; border-radius:999px; padding:6px 12px 6px 6px; font-weight:900; color:#7A3D65; flex:0 0 auto; max-width:150px}
    .profile img{width:34px; height:34px; object-fit:cover; border-radius:50%}
    .profile span{max-width:86px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap}


    /* SUCCESS CARD */
    .success-wrap{padding:40px 0 60px}
    .success-card{background:var(--paper);border:1px solid rgba(255,255,255,.55);box-shadow:var(--shadow);border-radius:var(--radius-xl);padding:36px;text-align:center}

    /* ICON */
    .success-icon{width:80px;height:80px;border-radius:50%;background:linear-gradient(135deg,rgba(231,90,155,.15),rgba(255,195,216,.25));border:2px solid rgba(231,90,155,.2);display:grid;place-items:center;margin:0 auto 20px;font-size:36px}

    /* TUTOR ROW */
    .tutor-row{display:flex;align-items:center;gap:14px;background:rgba(255,241,246,.6);border:1px solid rgba(242,138,178,.2);border-radius:20px;padding:16px;margin:24px 0;text-align:left}
    .tutor-row img{width:56px;height:56px;object-fit:cover;border-radius:14px;flex:0 0 auto}
    .tutor-row h4{margin:0 0 4px;font-size:16px}
    .tutor-row p{margin:0;font-size:12px;color:var(--muted);font-weight:700}

    /* DETAILS */
    .detail-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin:20px 0;text-align:left}
    .detail-item{background:rgba(255,255,255,.7);border:1px solid rgba(46,42,59,.06);border-radius:16px;padding:14px 16px}
    .detail-item .label{font-size:11px;font-weight:900;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px}
    .detail-item .val{font-size:14px;font-weight:900;color:var(--ink)}

    /* STATUS BADGE */
    .status-badge{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:999px;background:rgba(255,217,199,.5);border:1px solid rgba(163,95,63,.2);color:#A35F3F;font-size:13px;font-weight:900;margin:16px 0}

    /* PENDING NOTE */
    .pending-note{background:rgba(221,211,255,.3);border:1px solid rgba(167,123,232,.2);border-radius:16px;padding:16px 20px;margin:20px 0;text-align:left;font-size:13px;color:#6D4964;line-height:1.6}
    .pending-note strong{display:block;margin-bottom:4px;font-size:14px;color:#5A3D7A}

    /* SLOTS LIST */
    .slots-wrap{background:rgba(255,255,255,.7);border:1px solid rgba(46,42,59,.06);border-radius:16px;padding:16px;margin:16px 0;text-align:left}
    .slots-wrap .slot-label{font-size:11px;font-weight:900;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:10px}
    .slot-item{display:flex;align-items:center;gap:8px;padding:7px 0;border-bottom:1px solid rgba(46,42,59,.05);font-size:13px;font-weight:700}
    .slot-item:last-child{border-bottom:none}
    .slot-item i{color:var(--hot-pink);font-size:14px}

    /* ACTIONS */
    .actions{display:flex;gap:12px;justify-content:center;margin-top:28px;flex-wrap:wrap}
    .btn-primary{padding:13px 28px;border-radius:999px;border:none;background:linear-gradient(135deg,var(--hot-pink),var(--pink));color:white;font-size:14px;font-weight:900;cursor:pointer;box-shadow:0 8px 20px rgba(231,90,155,.28);text-decoration:none;display:inline-flex;align-items:center;gap:8px}
    .btn-secondary{padding:13px 28px;border-radius:999px;border:1px solid rgba(46,42,59,.12);background:white;color:#7A5570;font-size:14px;font-weight:900;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;gap:8px}

    @media(max-width:600px){.detail-grid{grid-template-columns:1fr}}
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
<div class="nav-overlay" id="navOverlay"></div>

<div class="container">
  <div class="success-wrap">
    <div class="success-card">

      <!-- Icon -->
      <div class="success-icon">🎉</div>

      <h2 style="margin:0 0 8px;font-size:22px;">Booking Request Sent!</h2>
      <p style="color:var(--muted);font-size:14px;margin:0;">Your booking is waiting for the tutor to approve.</p>

      <!-- Status Badge -->
      <div class="status-badge">
        <i class="bi bi-hourglass-split"></i> Pending Approval
      </div>

      <!-- Tutor Row -->
      <div class="tutor-row">
        <img src="<?= e($tutorPic) ?>" alt="<?= e($booking['tutor_name']) ?>">
        <div>
          <h4><?= e($booking['tutor_name']) ?></h4>
          <p>RM <?= e($booking['rate']) ?>/hr · <?= e(ucfirst(str_replace('_',' ',$booking['learning_mode']))) ?></p>
        </div>
      </div>

      <!-- Detail Grid -->
      <div class="detail-grid">
        <div class="detail-item">
          <div class="label">Language</div>
          <div class="val"><?= e($booking['language']) ?></div>
        </div>
        <div class="detail-item">
          <div class="label">Mode</div>
          <div class="val"><?= $booking['learning_mode'] === 'online' ? '💻 Online' : '🤝 Face to Face' ?></div>
        </div>
        <div class="detail-item">
          <div class="label">Focus</div>
          <div class="val"><?= e($booking['focus'] ?: '—') ?></div>
        </div>
        <div class="detail-item">
          <div class="label">Proficiency Level</div>
          <div class="val">
            <?= e($booking['proficiency_level'] ?: '—') ?>
          </div>
        </div>
        <?php if ($booking['meeting_location']): ?>
        <div class="detail-item">
          <div class="label">Location</div>
          <div class="val"><?= e($booking['meeting_location']) ?></div>
        </div>
        <?php endif; ?>
      </div>

      <!-- Slots -->
      <?php if (!empty($allSlots)): ?>
      <div class="slots-wrap">
        <div class="slot-label">Your booked slots</div>
        <?php foreach ($allSlots as $slot): ?>
          <div class="slot-item">
            <i class="bi bi-calendar-check"></i>
            <?= date('D, d M Y', strtotime($slot['booking_date'])) ?> &nbsp;·&nbsp;
            <?= date('g:i A', strtotime($slot['booking_time'])) ?>
          </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <!-- Pending Note -->
      <div class="pending-note">
        <strong><i class="bi bi-info-circle-fill" style="color:#A77BE8;margin-right:6px;"></i> What happens next?</strong>
        <?= e($booking['tutor_name']) ?> will review your booking request and approve or decline it. 
        You'll be able to see the updated status in your dashboard. 
        Payment will be arranged directly with the tutor after approval.
      </div>

      <!-- Actions -->
      <div class="actions">
        <a href="student_dashboard.php" class="btn-primary">
          <i class="bi bi-house"></i> Go to Dashboard
        </a>
        <a href="search_tutors.php" class="btn-secondary">
          <i class="bi bi-search"></i> Find More Tutors
        </a>
      </div>

    </div>
  </div>
</div>

<script>
  function toggleDropdown() {
    const d = document.getElementById('profileDropdown');
    d.style.display = d.style.display === 'none' ? 'block' : 'none';
  }
  document.addEventListener('click', function(e) {
    const btn = document.getElementById('profileBtn');
    const dd  = document.getElementById('profileDropdown');
    if (btn && dd && !btn.contains(e.target) && !dd.contains(e.target)) dd.style.display = 'none';
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