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

// Get tutor ID from URL
$tutorID = intval($_GET['tutor_id'] ?? 0);
if (!$tutorID) {
    header("Location: search_tutors.php");
    exit();
}

if (!$tutorID) {
    echo "<script>
        alert('Please select a tutor first.');
        window.location.href='find_language.php';
    </script>";
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

// Get tutor info
$stmt = $conn->prepare("
    SELECT u.id, u.fullname, u.profile_pic, u.email,
           tp.rate, tp.bio, tp.experience,
           GROUP_CONCAT(DISTINCT tl.language) as languages,
           GROUP_CONCAT(DISTINCT ttm.mode) as teaching_modes,
           ul.location
    FROM users u
    JOIN tutor_profiles tp ON u.id = tp.user_id
    LEFT JOIN tutor_languages tl ON u.id = tl.user_id
    LEFT JOIN tutor_teaching_modes ttm ON u.id = ttm.user_id
    LEFT JOIN user_locations ul ON u.id = ul.user_id
    WHERE u.id = ? AND u.role = 'tutor' AND u.status = 'approved'
    GROUP BY u.id
");
$stmt->bind_param("i", $tutorID);
$stmt->execute();
$tutor = $stmt->get_result()->fetch_assoc();
if (!$tutor) { header("Location: search_tutors.php"); exit(); }

// Get tutor availability
$stmt = $conn->prepare("SELECT * FROM tutor_availability WHERE tutor_id = ? ORDER BY FIELD(day_of_week,'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday')");
$stmt->bind_param("i", $tutorID);
$stmt->execute();
$availResult = $stmt->get_result();
$availRows = [];
while ($row = $availResult->fetch_assoc()) {
    $availRows[] = $row;
}
$stmt->close();
$availability = [];
foreach ($availRows as $row) {
    $day = $row['day_of_week'];
    if (!isset($availability[$day])) {
        $availability[$day] = [];
    }
    $availability[$day][] = [
        'start' => $row['start_time'],
        'end'   => $row['end_time']
    ];
}
// Get already booked slots for this tutor by ANY student (to show as "Booked")
$stmt = $conn->prepare("
    SELECT booking_date, booking_time 
    FROM bookings 
    WHERE tutor_id = ? AND status IN ('pending','accepted','confirmed')
");
$stmt->bind_param("i", $tutorID);
$stmt->execute();
$bookedResult = $stmt->get_result();
$bookedSlots = []; // ['2025-05-21' => ['09:00:00','10:00:00']]
while ($row = $bookedResult->fetch_assoc()) {
    $d = $row['booking_date'];
    $t = $row['booking_time'];
    if (!isset($bookedSlots[$d])) $bookedSlots[$d] = [];
    $bookedSlots[$d][] = $t;
}
$stmt->close();

// Get count of booked slots per day for THIS STUDENT ONLY (for max 2 per day limit)
$stmt = $conn->prepare("
    SELECT booking_date, COUNT(*) as count
    FROM bookings 
    WHERE tutor_id = ? AND student_id = ? AND status IN ('pending','accepted','confirmed')
    GROUP BY booking_date
");
$stmt->bind_param("ii", $tutorID, $userID);
$stmt->execute();
$countResult = $stmt->get_result();
$myBookedCountPerDay = [];
while ($row = $countResult->fetch_assoc()) {
    $myBookedCountPerDay[$row['booking_date']] = $row['count'];
}
$stmt->close();
$bookedSlotsJson = json_encode($bookedSlots);
// Query 4 - student prefenguages
$stmt = $conn->prepare("SELECT language FROM student_preferences WHERE user_id = ?");
$stmt->bind_param("i", $userID);
$stmt->execute();
$result4 = $stmt->get_result();
$prefLangs = [];
while ($row = $result4->fetch_assoc()) {
    $prefLangs[] = $row['language'];
}
$stmt->close();

// Query 5 - student preferred modes
$stmt = $conn->prepare("SELECT mode FROM student_learning_modes WHERE user_id = ?");
$stmt->bind_param("i", $userID);
$stmt->execute();
$result5 = $stmt->get_result();
$prefModes = [];
while ($row = $result5->fetch_assoc()) {
    $prefModes[] = $row['mode'];
}
$stmt->close();

$tutorPic = !empty($tutor['profile_pic'])
    ? '../uploads/profiles/' . $tutor['profile_pic']
    : $assetBase. '/profile-tutor.png';

$tutorLangs = array_filter(array_map('trim', explode(',', $tutor['languages'] ?? '')));
$tutorModes = array_filter(array_map('trim', explode(',', $tutor['teaching_modes'] ?? '')));

function e($v){ return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }

$availDaysJson = json_encode(array_keys($availability));
$availJson = json_encode($availability);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
<meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
<meta http-equiv="Pragma" content="no-cache">
<meta http-equiv="Expires" content="0">

  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Book <?= e($tutor['fullname']) ?> · Kyoshi</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
  <link rel="stylesheet" href="../css/style.css">
  <style>
    :root{
      --cream:#FFF1F6; --paper:rgba(255,255,255,.88); --ink:#342635; --muted:#7B6178;
      --pink:#F28AB2; --pink-dark:#C94F86; --hot-pink:#E75A9B;
      --lavender:#EAD7FF; --peach:#FFD0DD; --mint:#DDF4E3; --sky:#D8ECFF;
      --shadow:0 18px 45px rgba(201,79,134,.16); --shadow-soft:0 10px 26px rgba(201,79,134,.10);
      --radius-xl:32px; --radius-lg:24px; --radius-md:18px;
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
    a{text-decoration:none;color:inherit} button,input,select,textarea{font-family:inherit}
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

    .icon-btn,.profile{border:1px solid rgba(46,42,59,.08); background:rgba(255,255,255,.88); box-shadow:var(--shadow-soft); cursor:pointer}
    .icon-btn{width:44px; height:44px; border-radius:16px; color:#7A4A68; position:relative; flex:0 0 auto}
    .dot{position:absolute; top:10px; right:10px; width:8px; height:8px; border-radius:50%; background:#E17C91}
    .profile{display:flex; align-items:center; gap:9px; border-radius:999px; padding:6px 12px 6px 6px; font-weight:900; color:#7A3D65; flex:0 0 auto; max-width:150px}
    .profile img{width:34px; height:34px; object-fit:cover; border-radius:50%}
    .profile span{max-width:86px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap}


    /* BACK LINK */
    .back-link{display:inline-flex;align-items:center;gap:6px;color:var(--pink-dark);font-weight:900;font-size:13px;padding:9px 16px;border-radius:999px;background:rgba(255,255,255,.78);border:1px solid rgba(46,42,59,.08);transition:.18s ease;margin:20px 0 16px;display:inline-flex}
    .back-link:hover{transform:translateY(-1px)}

    /* LAYOUT */
    .booking-grid{display:grid;grid-template-columns:1fr 380px;gap:24px;padding-bottom:48px;align-items:start}
    .glass{background:var(--paper);border:1px solid rgba(255,255,255,.55);box-shadow:var(--shadow);border-radius:var(--radius-xl);padding:28px}

    /* TUTOR CARD */
    .tutor-summary{display:flex;gap:16px;align-items:flex-start;margin-bottom:24px;padding-bottom:24px;border-bottom:1px solid rgba(46,42,59,.08)}
    .tutor-summary img{width:80px;height:80px;object-fit:cover;border-radius:20px;flex:0 0 auto;background:#eee}
    .tutor-summary h2{margin:0 0 6px;font-size:20px}
    .tutor-summary p{margin:0;color:var(--muted);font-size:13px;line-height:1.5}
    .lang-tags{display:flex;flex-wrap:wrap;gap:6px;margin-top:8px}
    .lang-tag{padding:4px 10px;border-radius:999px;background:rgba(242,138,178,.18);color:var(--pink-dark);font-size:11px;font-weight:900}
    .mode-tag{padding:4px 10px;border-radius:999px;background:rgba(221,211,255,.5);color:#7648B8;font-size:11px;font-weight:900}

    /* STEPS */
    .steps{display:flex;gap:0;margin-bottom:28px}
    .step{flex:1;text-align:center;position:relative}
    .step::after{content:"";position:absolute;top:18px;left:50%;width:100%;height:2px;background:rgba(46,42,59,.10);z-index:0}
    .step:last-child::after{display:none}
    .step-dot{width:36px;height:36px;border-radius:50%;display:grid;place-items:center;margin:0 auto 8px;font-size:13px;font-weight:900;position:relative;z-index:1;transition:.2s ease}
    .step-dot.done{background:linear-gradient(135deg,var(--hot-pink),var(--pink));color:white}
    .step-dot.active{background:linear-gradient(135deg,var(--hot-pink),var(--pink));color:white;box-shadow:0 6px 16px rgba(231,90,155,.32)}
    .step-dot.inactive{background:rgba(255,255,255,.88);border:2px solid rgba(46,42,59,.12);color:#9080a0}
    .step-label{font-size:11px;font-weight:700;color:var(--muted)}

    /* FORM SECTIONS */
    .form-section{display:none}
    .form-section.active{display:block}
    .form-group{margin-bottom:20px}
    .form-group label{display:block;font-size:13px;font-weight:900;color:#342635;margin-bottom:8px}
    .form-group label i{color:var(--hot-pink);margin-right:5px}
    .form-control{width:100%;padding:12px 16px;border:1px solid rgba(46,42,59,.12);border-radius:14px;outline:none;font-size:14px;color:#342635;background:rgba(255,255,255,.9);transition:.15s ease}
    .form-control:focus{border-color:var(--hot-pink);box-shadow:0 0 0 3px rgba(231,90,155,.12)}
    textarea.form-control{resize:vertical;min-height:90px}

    /* CHIP SELECTORS */
    .chip-group{display:flex;flex-wrap:wrap;gap:8px}
    .sel-chip{padding:9px 16px;border-radius:999px;border:1px solid rgba(46,42,59,.12);background:white;font-size:13px;font-weight:700;color:#7A5570;cursor:pointer;transition:.15s ease}
    .sel-chip.active{background:linear-gradient(135deg,var(--hot-pink),var(--pink));color:white;border-color:var(--pink);box-shadow:0 6px 14px rgba(231,90,155,.25)}
    .sel-chip:hover:not(.active){transform:translateY(-1px)}

    /* CALENDAR */
    .calendar-wrap{margin-bottom:4px}
    .cal-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:12px}
    .cal-header strong{font-size:15px}
    .cal-nav{width:32px;height:32px;border-radius:10px;border:1px solid rgba(46,42,59,.10);background:white;cursor:pointer;display:grid;place-items:center;color:#7A4A68;font-size:14px}
    .cal-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:4px}
    .cal-day-name{text-align:center;font-size:11px;font-weight:900;color:var(--muted);padding:4px 0}
    .cal-day{text-align:center;padding:8px 4px;border-radius:10px;font-size:13px;font-weight:700;cursor:pointer;transition:.15s ease;border:1px solid transparent}
    .cal-day.available{background:rgba(221,244,230,.6);color:#2D6A42}
    .cal-day.available:hover{background:rgba(231,90,155,.12);border-color:var(--pink);color:var(--pink-dark)}
    .cal-day.selected{background:linear-gradient(135deg,var(--hot-pink),var(--pink));color:white;box-shadow:0 4px 12px rgba(231,90,155,.28)}
    .cal-day.unavailable{color:#ccc;cursor:not-allowed}
    .cal-day.past{color:#ddd;cursor:not-allowed}
    .cal-day.empty{visibility:hidden}
    .cal-day.in-range   { background:rgba(242,138,178,.18); border-radius:0; color:var(--pink-dark); }
  .cal-day.range-start{ background:linear-gradient(135deg,var(--hot-pink),var(--pink)); color:white; border-radius:10px 0 0 10px; box-shadow:0 4px 12px rgba(231,90,155,.28); }
  .cal-day.range-end  { background:linear-gradient(135deg,var(--hot-pink),var(--pink)); color:white; border-radius:0 10px 10px 0; box-shadow:0 4px 12px rgba(231,90,155,.28); }
  .cal-day.range-start.range-end { border-radius:10px; }
  .cal-day.has-slots  { outline:2px solid var(--hot-pink); outline-offset:-2px; }
  .cal-day.available:hover { background:rgba(231,90,155,.12); border-color:var(--pink); color:var(--pink-dark); cursor:pointer; }

    /* TIME SLOTS */
    .time-slots{display:flex;flex-wrap:wrap;gap:8px;margin-top:12px}
    .time-slot{padding:8px 14px;border-radius:999px;border:1px solid rgba(46,42,59,.10);background:white;font-size:12px;font-weight:700;color:#7A5570;cursor:pointer;transition:.15s ease}
    .time-slot.active{background:linear-gradient(135deg,var(--hot-pink),var(--pink));color:white;border-color:var(--pink)}
    .time-slot:hover:not(.active){background:rgba(231,90,155,.08);border-color:var(--pink)}

    /* FOCUS CHIPS */
    .focus-chip{display:flex;align-items:center;gap:8px;padding:10px 14px;border-radius:14px;border:1px solid rgba(46,42,59,.10);background:white;font-size:13px;font-weight:700;color:#7A5570;cursor:pointer;transition:.15s ease}
    .focus-chip input[type=checkbox]{accent-color:var(--hot-pink);width:16px;height:16px}
    .focus-chip.active{background:rgba(231,90,155,.08);border-color:var(--pink);color:var(--pink-dark)}

    /* NAV BUTTONS */
    .form-nav{display:flex;justify-content:space-between;align-items:center;margin-top:24px;padding-top:20px;border-top:1px solid rgba(46,42,59,.08)}
    .btn-prev{padding:11px 22px;border-radius:999px;border:1px solid rgba(46,42,59,.12);background:none;color:#7A5570;font-size:13px;font-weight:900;cursor:pointer;transition:.18s ease}
    .btn-prev:hover{background:rgba(255,255,255,.88)}
    .btn-next{padding:11px 26px;border-radius:999px;border:none;background:linear-gradient(135deg,var(--hot-pink),var(--pink));color:white;font-size:13px;font-weight:900;cursor:pointer;box-shadow:0 8px 20px rgba(231,90,155,.28);transition:.18s ease}
    .btn-next:hover{transform:translateY(-1px)}
    
    /* SUMMARY CARD */
    .summary-card{background:var(--paper);border:1px solid rgba(255,255,255,.55);box-shadow:var(--shadow);border-radius:var(--radius-xl);padding:24px;position:sticky;top:96px}
    .summary-card h3{margin:0 0 18px;font-size:18px}
    .summary-row{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;padding:10px 0;border-bottom:1px solid rgba(46,42,59,.06);font-size:13px}
    .summary-row:last-of-type{border-bottom:none}
    .summary-label{color:var(--muted);font-weight:700;flex-shrink:0}
    .summary-val{font-weight:900;color:#342635;text-align:right}
    .price-total{margin-top:16px;padding:16px;border-radius:18px;background:linear-gradient(135deg,rgba(231,90,155,.10),rgba(255,195,216,.15));border:1px solid rgba(242,138,178,.22);text-align:center}
    .price-total p{margin:0 0 4px;font-size:12px;color:var(--muted);font-weight:700}
    .price-total strong{font-size:28px;color:var(--hot-pink)}

    /* TOAST */
    .toast{position:fixed;left:50%;bottom:28px;transform:translate(-50%,18px);opacity:0;pointer-events:none;z-index:99;background:#8E3F70;color:#fff;border-radius:999px;padding:12px 18px;font-size:13px;font-weight:900;transition:.2s ease}
    .toast.show{opacity:1;transform:translate(-50%,0)}

    /* AVAILABILITY NOTE */
    .avail-note{padding:12px 16px;border-radius:14px;background:rgba(221,244,230,.6);border:1px solid rgba(45,106,66,.2);color:#2D6A42;font-size:13px;font-weight:700;margin-bottom:16px;display:flex;align-items:center;gap:8px}
    .no-avail-note{padding:12px 16px;border-radius:14px;background:rgba(255,217,199,.5);border:1px solid rgba(163,95,63,.2);color:#A35F3F;font-size:13px;font-weight:700;margin-bottom:16px}

    @media(max-width:900px){.booking-grid{grid-template-columns:1fr} .back-link span {display:none;} .button-prev span{display:none;}}
  </style>
</head>
<body data-tutor-state="<?= e($tutor['location'] ?? '') ?>" data-tutor-modes="<?= e(implode(',', array_values($tutorModes))) ?>">

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
                <a href="find_language.php" class="active">Find Language</a>
                <a href="booking_status.php">My Bookings</a>
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
  <a href="tutor_profile.php?id=<?= $tutorID ?>" class="back-link">
    <i class="bi bi-arrow-left"></i> <span>Back to Profile</span>
  </a>

  <div class="booking-grid">
    <!-- LEFT: FORM -->
    <div class="glass">
      <!-- Tutor Summary -->
      <div class="tutor-summary">
        <img src="<?= e($tutorPic) ?>" alt="<?= e($tutor['fullname']) ?>">
        <div>
          <h2><?= e($tutor['fullname']) ?></h2>
          <p><?= e($tutor['experience']) ?> years experience · RM <?= e($tutor['rate']) ?>/hr</p>
          <div class="lang-tags">
            <?php foreach ($tutorLangs as $l): ?>
              <span class="lang-tag"><?= e($l) ?></span>
            <?php endforeach; ?>
            <?php foreach ($tutorModes as $m): ?>
              <span class="mode-tag"><?= e(ucfirst(str_replace('_',' ',$m))) ?></span>
            <?php endforeach; ?>
          </div>
        </div>
      </div>

      <!-- Steps -->
      <div class="steps">
        <div class="step">
          <div class="step-dot active" id="dot1">1</div>
          <div class="step-label">Details</div>
        </div>
        <div class="step">
          <div class="step-dot inactive" id="dot2">2</div>
          <div class="step-label">Schedule</div>
        </div>
        <div class="step">
          <div class="step-dot inactive" id="dot3">3</div>
          <div class="step-label">Review</div>
        </div>
      </div>

      <!-- STEP 1: Details -->
      <div class="form-section active" id="step1">
        <div class="form-group">
          <label><i class="bi bi-translate"></i> Language to learn
            <?php if (!empty($prefLangs)): ?>
                <span style="font-weight:400;color:var(--muted);font-size:11px;margin-left:6px;">
                <i class="bi bi-star-fill" style="color:var(--hot-pink);font-size:10px;"></i> 
                Your preferred: <?= e(implode(', ', $prefLangs)) ?> 
                </span>
            <?php endif; ?>
            </label>
          <div class="chip-group" id="langChips">
            <?php foreach ($tutorLangs as $l): ?>
              <button type="button" class="sel-chip"
                data-val="<?= e($l) ?>" onclick="selectSingle(this,'langChips','selectedLang')">
                <?= e($l) ?>
              </button>
            <?php endforeach; ?>
          </div>
        
        </div>

        <div class="form-group">
          <label><i class="bi bi-laptop"></i> Learning mode
                <?php if (!empty($prefModes)): ?>
                    <span style="font-weight:400;color:var(--muted);font-size:11px;margin-left:6px;">
                    <i class="bi bi-star-fill" style="color:var(--hot-pink);font-size:10px;"></i> 
                    Your preferred: <?= e(implode(', ', array_map(fn($m) => ucfirst(str_replace('_',' ',$m)), $prefModes))) ?>
                    </span>
                <?php endif; ?>
                </label>
          <div class="chip-group" id="modeChips">
            <?php foreach ($tutorModes as $m): ?>
              <button type="button" class="sel-chip"
                data-val="<?= e($m) ?>" onclick="selectSingle(this,'modeChips','selectedMode');checkModeLocation()">
                <?= $m === 'online' ? '💻 Online' : '🤝 Face to Face' ?>
              </button>
            <?php endforeach; ?>
          </div>
            <input type="hidden" id="selectedLang" value="">
            <input type="hidden" id="selectedMode" value="">
        </div>

    <div class="form-group" id="locationGroup" style="display:none;">
        <label><i class="bi bi-geo-alt"></i> Your meeting location</label>

        <div style="padding:10px 14px;border-radius:12px;background:rgba(221,211,255,.3);border:1px solid rgba(167,123,232,.2);font-size:13px;color:#6D4964;margin-bottom:10px;display:flex;align-items:center;gap:8px;">
            <i class="bi bi-info-circle" style="color:#A77BE8;"></i>
            Tutor is based in <strong style="margin:0 4px;"><?= e($tutor['location'] ?? 'Unknown') ?></strong> — face to face within 30km only.
        </div>

        <div style="display:flex;gap:8px;align-items:center;">
            <div style="position:relative;flex:1;">
            <i class="bi bi-search" style="position:absolute;left:14px;top:50%;transform:translateY(-50%);color:#91899F;pointer-events:none;"></i>
            <input type="text" id="locationInput" class="form-control"
                placeholder="Type your area e.g. Bangsar, Kuala Lumpur"
                style="padding-left:40px;"
                autocomplete="off"
                onkeydown="if(event.key==='Enter'){event.preventDefault();searchLocation();}">
            </div>
            <button type="button" onclick="searchLocation()"
            style="padding:12px 18px;border-radius:14px;border:none;background:linear-gradient(135deg,#E75A9B,#F28AB2);color:white;font-size:13px;font-weight:900;cursor:pointer;white-space:nowrap;">
            Search
            </button>
        </div>

        <div id="locationResults" style="display:none;margin-top:8px;background:white;border:1px solid rgba(46,42,59,.12);border-radius:14px;overflow:hidden;box-shadow:0 10px 26px rgba(201,79,134,.10);"></div>

        <div id="locationChecking" style="display:none;margin-top:10px;padding:10px 14px;border-radius:12px;background:rgba(221,211,255,.3);border:1px solid rgba(167,123,232,.2);font-size:13px;color:#6D4964;">
            <i class="bi bi-hourglass-split"></i> Searching...
        </div>

        <div id="locationOk" style="display:none;margin-top:10px;padding:10px 14px;border-radius:12px;background:rgba(221,244,230,.6);border:1px solid rgba(45,106,66,.2);color:#2D6A42;font-size:13px;font-weight:700;">
            <i class="bi bi-check-circle-fill"></i>
            <span id="locationOkText">Within range!</span>
        </div>

        <div id="locationWarning" style="display:none;margin-top:10px;padding:10px 14px;border-radius:12px;background:rgba(255,217,199,.6);border:1px solid rgba(163,95,63,.2);color:#A35F3F;font-size:13px;font-weight:700;">
            <i class="bi bi-exclamation-triangle"></i>
            <span id="locationWarnText">Too far from tutor.</span>
                <div id="locationAction" style="margin-top:8px;"></div>

                <?php if (in_array('online', $tutorModes)): ?>
                <br>
                <span style="color:#3D7047;">
                Good news! You can still book this tutor online.
                </span>
                <?php else: ?>
                <br>
                <span>
                Try another location closer to the tutor, or choose a different tutor.
                </span>
                <?php endif; ?>
                        </div>
        </div>

        <div class="form-group">
          <label><i class="bi bi-bullseye"></i> Focus area <span style="font-weight:400;color:var(--muted);">(choose all that apply)</span></label>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
            <?php foreach (['Speaking','Listening','Reading','Writing'] as $focus): ?>
              <label class="focus-chip" id="fc-<?= $focus ?>">
                <input type="checkbox" value="<?= $focus ?>" onchange="updateFocusChip(this)"> <?= $focus ?>
              </label>
            <?php endforeach; ?>
          </div>
        </div>

      <div class="form-group">
        <label><i class="bi bi-graph-up"></i> Your Proficiency Level</label>
        <div class="chip-group" id="levelChips">
    <button type="button" class="sel-chip" data-val="beginner" onclick="selectSingle(this,'levelChips','selectedLevel')">
        <i class="bi bi-emoji-smile"></i> Beginner
    </button>
    <button type="button" class="sel-chip" data-val="intermediate" onclick="selectSingle(this,'levelChips','selectedLevel')">
        <i class="bi bi-emoji-neutral"></i> Intermediate
    </button>
    <button type="button" class="sel-chip" data-val="advanced" onclick="selectSingle(this,'levelChips','selectedLevel')">
        <i class="bi bi-emoji-sunglasses"></i> Advanced
    </button>
    <button type="button" class="sel-chip" data-val="master" onclick="selectSingle(this,'levelChips','selectedLevel')">
        <i class="bi bi-emoji-dizzy"></i> Master
    </button>
</div>
<input type="hidden" id="selectedLevel" value=""> 
    </div>

        <div class="form-group">
          <label><i class="bi bi-chat-left-text"></i> Notes for tutor <span style="font-weight:400;color:var(--muted);">(optional)</span></label>
          <textarea class="form-control" id="bookingNotes" placeholder="Tell your tutor what you want to focus on, your current level, or any special requests..."></textarea>
        </div>

        <div class="form-nav">
          <span></span>
          <button class="btn-next" onclick="goStep(2)">Next: Choose Schedule <i class="bi bi-arrow-right"></i></button>
        </div>
      </div>

            <!-- STEP 2: Schedule -->
      <div class="form-section" id="step2">
        <?php if (empty($availability)): ?>
          <div class="no-avail-note">
            <i class="bi bi-exclamation-circle"></i>
            This tutor hasn't set their availability yet. Please contact them directly.
          </div>
        <?php else: ?>
          <div class="avail-note" style="background: rgba(221, 244, 230, 0.6); border-color: rgba(45, 106, 66, 0.2); color: #2D6A42;">
    <i class="bi bi-info-circle-fill"></i>
    <div>
        <strong>Green dates are available.</strong> Tap a date to select and choose your time slots.<br>
        <span style="color: #f5220b;">Bookings must be made at least 1 day in advance. Same day bookings are not allowed.</span>
    </div>
</div>
            <!-- Calendar -->
            <div class="form-group">
            <label><i class="bi bi-calendar3"></i> Pick your session date(s)</label>
            <div class="calendar-wrap">
              <div class="cal-header">
                <button class="cal-nav" type="button" onclick="changeMonth(-1)"><i class="bi bi-chevron-left"></i></button>
                <strong id="calMonthLabel"></strong>
                <button class="cal-nav" type="button" onclick="changeMonth(1)"><i class="bi bi-chevron-right"></i></button>
              </div>
              <div class="cal-grid" id="calGrid"></div>
            </div>
            <!-- Range hint -->
            <div id="rangeHint" style="margin-top:10px;font-size:12px;color:var(--muted);font-weight:700;min-height:18px;"></div>
          </div>

          <!-- Per-day time slot panels -->
          <div id="daySlotPanels"></div>

          <!-- Running total -->
          <div id="priceRunning" style="display:none;margin-top:4px;padding:14px 18px;border-radius:16px;background:linear-gradient(135deg,rgba(231,90,155,.10),rgba(255,195,216,.15));border:1px solid rgba(242,138,178,.22);display:flex;justify-content:space-between;align-items:center;">
            <span style="font-size:13px;font-weight:900;color:var(--muted);" id="sessionCountLabel">0 sessions selected</span>
            <span style="font-size:22px;font-weight:900;color:var(--hot-pink);" id="runningTotal">RM 0</span>
          </div>

        <?php endif; ?>

        <div class="form-nav">
          <button class="btn-prev" type="button" onclick="goStep(1)"><i class="bi bi-arrow-left"></i> Back</button>
          <button class="btn-next" type="button" onclick="goStep(3)">Next: Review <i class="bi bi-arrow-right"></i></button>
        </div>
      </div>

      <!-- STEP 3: Review & Submit -->
      <div class="form-section" id="step3">
        <h3 style="margin:0 0 20px;font-size:18px;">Review your booking</h3>

        <div style="background:rgba(255,241,246,.6);border:1px solid rgba(242,138,178,.2);border-radius:20px;padding:20px;margin-bottom:20px;">
          <div class="summary-row"><span class="summary-label">Tutor</span><span class="summary-val"><?= e($tutor['fullname']) ?></span></div>
          <div class="summary-row"><span class="summary-label">Language</span><span class="summary-val" id="rev-lang">—</span></div>
          <div class="summary-row"><span class="summary-label">Mode</span><span class="summary-val" id="rev-mode">—</span></div>
          <div class="summary-row"><span class="summary-label">Proficiency Level</span><span class="summary-val" id="rev-level">—</span></div>
          <div class="summary-row" id="rev-location-row" style="display:none;"><span class="summary-label">Location</span><span class="summary-val" id="rev-location">—</span></div>
          <div class="summary-row" style="flex-direction:column;align-items:flex-start;gap:8px;">
                <span class="summary-label">Date &amp; Time</span>
                <div id="rev-datetime" style="width:100%;"></div>
              </div>
          <div class="summary-row"><span class="summary-label">Focus</span><span class="summary-val" id="rev-focus">—</span></div>
          <div class="summary-row" id="rev-notes-row" style="display:none;"><span class="summary-label">Notes</span><span class="summary-val" id="rev-notes" style="max-width:200px;word-break:break-word;">—</span></div>
        </div>

        <p style="font-size:12px;color:var(--muted);margin:14px 0 0;text-align:center;">
          By confirming, you agree to the session terms. Payment will be arranged separately.
        </p>

        <div class="form-nav">
          <button class="btn-prev" onclick="goStep(2)"><i class="bi bi-arrow-left"></i> <span>Back</span></button>
          <button class="btn-next" onclick="submitBooking()" id="submitBtn">
            <i class="bi bi-check2-circle"></i> Confirm Booking
          </button>
        </div>
      </div>
    </div>

    <!-- RIGHT: SUMMARY CARD -->
    <div class="summary-card">
      <h3>Booking Summary</h3>
      <div class="summary-row"><span class="summary-label">Tutor</span><span class="summary-val"><?= e($tutor['fullname']) ?></span></div>
      <div class="summary-row"><span class="summary-label">Language</span><span class="summary-val" id="sum-lang">Not selected</span></div>
      <div class="summary-row"><span class="summary-label">Mode</span><span class="summary-val" id="sum-mode">Not selected</span></div>
      <div class="summary-row"><span class="summary-label">Level</span><span class="summary-val" id="sum-level">Not selected</span></div>
      <div class="summary-row" style="flex-direction:column;align-items:flex-start;gap:8px;">
  <span class="summary-label">Date &amp; Time</span>
  <div id="sum-datetime" style="width:100%;">
    <span style="font-size:12px;color:var(--muted);">Not selected</span>
  </div>
</div>
      <div class="price-total">
        <p>TOTAL</p>
        <strong>RM <?= e($tutor['rate']) ?></strong>
      </div>
      <p style="font-size:12px;color:var(--muted);margin-top:14px;line-height:1.5;">
        <i class="bi bi-info-circle" style="color:var(--hot-pink);"></i>
        Payment details will be confirmed after booking approval.
      </p>
    </div>
  </div>
</div>

<div class="toast" id="toast"></div>
<script>
  const availability = <?= $availJson ?>;
  const availDays    = <?= $availDaysJson ?>;
  const tutorRate    = <?= intval($tutor['rate']) ?>;
  const tutorId      = <?= $tutorID ?>;
  const bookedSlots = <?= $bookedSlotsJson ?>;  // All students (show as Booked)
  const myBookedCountPerDay = <?= json_encode($myBookedCountPerDay) ?>;  // Current student only (for limit)
  const MAX_SLOTS_PER_DAY = 2;

  // ── State ────────────────────────────────────────
  let currentStep  = 1;
  let calYear, calMonth;
  const now = new Date(); now.setHours(0,0,0,0);
  calYear  = now.getFullYear();
  calMonth = now.getMonth();

  // Range selection state
  let rangeStart   = null;   // Date object
  let rangeEnd     = null;   // Date object
  let pickingEnd   = false;  // waiting for second click

  // sessions: { dateStr → { dayName, slots: Set of timeVal } }
  let sessions = {};
  let langUserSelected = null;
let modeUserSelected = null;
let levelUserSelected = null;

  // ── Helpers ──────────────────────────────────────
  const DAY_NAMES  = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
  const MONTH_NAMES= ['January','February','March','April','May','June','July','August','September','October','November','December'];

  function dateStr(d) {
    return d.getFullYear() + '-' +
      String(d.getMonth()+1).padStart(2,'0') + '-' +
      String(d.getDate()).padStart(2,'0');
  }
  function parseDateStr(s) {
    const [y,m,d] = s.split('-').map(Number);
    return new Date(y, m-1, d);
  }
  function dayName(d)  { return DAY_NAMES[d.getDay()]; }
  function isAvail(date) { 
    // Check if date is today - DISALLOW
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    
    if (date.getDate() === today.getDate() &&
        date.getMonth() === today.getMonth() &&
        date.getFullYear() === today.getFullYear()) {
        return false; // Cannot book for today
    }
    
    return availability[dayName(date)] && availability[dayName(date)].length > 0; 
}

function isPast(date) {
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    return date < today;
}

function fmt(h, m) {
    const suffix = h >= 12 ? 'PM' : 'AM';
    const h12 = h % 12 || 12;
    return h12 + (m ? ':' + String(m).padStart(2, '0') : '') + ' ' + suffix;
}
        
function renderCalendar() {
    document.getElementById('calMonthLabel').textContent =
        MONTH_NAMES[calMonth] + ' ' + calYear;
    const grid = document.getElementById('calGrid');
    grid.innerHTML = '';

    // Day-name headers
    ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'].forEach(n => {
        const el = document.createElement('div');
        el.className = 'cal-day-name';
        el.textContent = n;
        grid.appendChild(el);
    });

    const firstDay = new Date(calYear, calMonth, 1).getDay();
    const daysInMonth = new Date(calYear, calMonth + 1, 0).getDate();

    // Fill empty cells before first day
    for (let i = 0; i < firstDay; i++) {
        const el = document.createElement('div');
        el.className = 'cal-day empty';
        grid.appendChild(el);
    }

    // Fill actual days
    for (let d = 1; d <= daysInMonth; d++) {
        const date = new Date(calYear, calMonth, d);
        const ds = dateStr(date);
        const el = document.createElement('div');
        el.textContent = d;
        el.dataset.date = ds;

        const today = new Date();
        today.setHours(0, 0, 0, 0);
        const isTodayDate = date.getDate() === today.getDate() &&
            date.getMonth() === today.getMonth() &&
            date.getFullYear() === today.getFullYear();

        if (isPast(date)) {
            el.className = 'cal-day past';
        } else if (isTodayDate) {
            el.className = 'cal-day unavailable';
            el.title = 'Cannot book for today';
        } else if (!isAvail(date)) {
            el.className = 'cal-day unavailable';
        } else {
            let cls = 'cal-day available';
            if (sessions[ds]) cls += ' selected';
            el.className = cls;
            el.onclick = (function(date) {
                return function() { handleDateClick(date); };
            })(date);
        }
        grid.appendChild(el);
    }
}

  function changeMonth(dir) {
    calMonth += dir;
    if (calMonth > 11) { calMonth = 0; calYear++; }
    if (calMonth < 0)  { calMonth = 11; calYear--; }
    renderCalendar();
  }

 function handleDateClick(date) {
  const ds = dateStr(date);

  if (sessions[ds]) {
    // Deselect this date
    delete sessions[ds];
    document.getElementById('rangeHint').textContent =
      Object.keys(sessions).length + ' date(s) selected';
  } else {
    // Select this date
    sessions[ds] = { dayName: dayName(date), slots: new Set() };
    document.getElementById('rangeHint').textContent =
      Object.keys(sessions).length + ' date(s) selected — pick time slots below';
  }

  renderCalendar();
  renderDayPanels();
  updateRunningTotal();
}

  // ── Build sessions for each available day in range ─
  function buildSessionsFromRange() {
    // Remove sessions outside new range
    Object.keys(sessions).forEach(ds => {
      const d = parseDateStr(ds);
      if (d < rangeStart || d > rangeEnd) delete sessions[ds];
    });

    // Add entries for each available day in range
    const cur = new Date(rangeStart);
    while (cur <= rangeEnd) {
      const ds = dateStr(cur);
      if (isAvail(cur) && !isPast(cur) && !sessions[ds]) {
        sessions[ds] = { dayName: dayName(cur), slots: new Set() };
      }
      cur.setDate(cur.getDate() + 1);
    }

    renderDayPanels();
    updateRunningTotal();
  }

function renderDayPanels() {
    const container = document.getElementById('daySlotPanels');
    container.innerHTML = '';

    const sortedDates = Object.keys(sessions).sort();
    if (sortedDates.length === 0) return;

    sortedDates.forEach(ds => {
        const sess  = sessions[ds];
        const blocks = availability[sess.dayName]; // now an array
        if (!blocks || blocks.length === 0) return;

        const panel = document.createElement('div');
        panel.style.cssText = 'margin-bottom:16px;background:rgba(255,241,246,.7);border:1px solid rgba(242,138,178,.18);border-radius:20px;padding:16px;';

        // Header
        const header = document.createElement('div');
        header.style.cssText = 'display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;';
        
        const availText = blocks.map(b => {
            const [sh, sm] = b.start.split(':').map(Number);
            const [eh, em] = b.end.split(':').map(Number);
            return fmt(sh, sm) + ' – ' + fmt(eh, em);
        }).join(', ');

        header.innerHTML = `
            <div>
                <strong style="font-size:14px;">${sess.dayName}, ${parseDateStr(ds).toLocaleDateString('en-MY',{day:'numeric',month:'short',year:'numeric'})}</strong>
            </div>
            <button type="button" onclick="removeDay('${ds}')"
                style="width:28px;height:28px;border-radius:8px;border:1px solid rgba(46,42,59,.10);background:white;cursor:pointer;font-size:14px;color:#9080a0;">✕</button>
        `;
        panel.appendChild(header);

        // Time slots — loop through ALL blocks
        const slotWrap = document.createElement('div');
        slotWrap.style.cssText = 'display:flex;flex-wrap:wrap;gap:8px;';

        blocks.forEach(block => {
            let cur = parseInt(block.start.split(':')[0]) * 60 + parseInt(block.start.split(':')[1]);
            const end = parseInt(block.end.split(':')[0]) * 60 + parseInt(block.end.split(':')[1]);

            while (cur + 60 <= end) {
                const h1 = Math.floor(cur/60), m1 = cur % 60;
                const h2 = Math.floor((cur+60)/60), m2 = (cur+60) % 60;
                const label   = fmt(h1,m1) + ' – ' + fmt(h2,m2);
                const timeVal = String(h1).padStart(2,'0') + ':' + String(m1).padStart(2,'0') + ':00';

                const isBooked   = bookedSlots[ds] && bookedSlots[ds].includes(timeVal);
                const isSelected = sess.slots.has(timeVal);

                const btn = document.createElement('button');
                btn.type = 'button';

                if (isBooked) {
                    btn.className = 'time-slot';
                    btn.textContent = label + ' · Booked';
                    btn.disabled = true;
                    btn.style.cssText = 'opacity:.4;cursor:not-allowed;text-decoration:line-through;';
                } else {
                    btn.className = 'time-slot' + (isSelected ? ' active' : '');
                    btn.textContent = label;
                    btn.onclick = () => toggleSlot(ds, timeVal, btn);
                }
                slotWrap.appendChild(btn);
                cur += 60;
            }
        });

        panel.appendChild(slotWrap);

        const sub = document.createElement('div');
        sub.id = 'subtotal-' + ds;
        sub.style.cssText = 'margin-top:10px;font-size:12px;font-weight:700;color:var(--pink-dark);';
        sub.textContent = sess.slots.size > 0
            ? sess.slots.size + ' slot' + (sess.slots.size>1?'s':'') + ' selected'
            : 'No slots selected yet';
        panel.appendChild(sub);

        container.appendChild(panel);
    });
}

function updateAfterSlotChange(ds) {
    updateRunningTotal();
    renderDayPanels();
    updateSummary();
}
function toggleSlot(ds, timeVal, btn) {
    const sess = sessions[ds];
    if (!sess) return;

    // Check if this slot is already booked by ANY student
    const isBookedByOther = bookedSlots[ds] && bookedSlots[ds].includes(timeVal);
    if (isBookedByOther) {
        showToast('This time slot is already booked by another student.');
        return;
    }

    // Check if THIS student has a booking at same date/time with ANY tutor
    fetch('check_student_bookings.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ date: ds, time: timeVal })
    })
    .then(response => response.json())
    .then(data => {
        if (data.hasConflict) {
            showToast(`You already have a session at this time on ${ds} with another tutor.`);
            return;
        }
        
        // Proceed with selection
        if (sess.slots.has(timeVal)) {
            sess.slots.delete(timeVal);
            btn.classList.remove('active');
        } else {
            // Check max 2 per day limit
            const existingBookedCount = myBookedCountPerDay[ds] || 0;
            const currentSelectedCount = sess.slots.size;
            
            if (existingBookedCount + currentSelectedCount + 1 > MAX_SLOTS_PER_DAY) {
                showToast(`You can only book up to ${MAX_SLOTS_PER_DAY} sessions per day. Give yourself a break`);
                return;
            }
            
            sess.slots.add(timeVal);
            btn.classList.add('active');
        }
        
        updateAfterSlotChange(ds);
        updateSummary();  // ← ADD THIS LINE to update the right sidebar
    });
}

  function removeDay(ds) {
    delete sessions[ds];
    // Adjust range if needed
    const remaining = Object.keys(sessions).sort();
    if (remaining.length === 0) {
      rangeStart = null; rangeEnd = null;
      document.getElementById('rangeHint').textContent = '';
    } else {
      rangeStart = parseDateStr(remaining[0]);
      rangeEnd   = parseDateStr(remaining[remaining.length-1]);
    }
    renderCalendar();
    renderDayPanels();
    updateRunningTotal();
  }

  // ── Running total ────────────────────────────────
  function updateRunningTotal() {
    let totalSlots = 0;
    Object.values(sessions).forEach(s => { totalSlots += s.slots.size; });
    const total = totalSlots * tutorRate;

    const box = document.getElementById('priceRunning');
    box.style.display = totalSlots > 0 ? 'flex' : 'none';
    document.getElementById('sessionCountLabel').textContent =
      totalSlots + ' session' + (totalSlots !== 1 ? 's' : '') + ' selected';
    document.getElementById('runningTotal').textContent = 'RM ' + total;

    updateSummary();
  }

  function updateSummary() {
  document.getElementById('sum-lang').textContent = langUserSelected || 'Not selected';
  document.getElementById('sum-mode').textContent = modeUserSelected
    ? (modeUserSelected === 'online' ? 'Online' : 'Face to Face')
    : 'Not selected';
  document.getElementById('sum-level').textContent = levelUserSelected ? 
    (levelUserSelected === 'beginner' ? 'Beginner' :
     levelUserSelected === 'intermediate' ? 'Intermediate' :
     levelUserSelected === 'advanced' ? 'Advanced' : 'Master') : 'Not selected';

  const sortedDates = Object.keys(sessions).sort();
  const slotCount   = Object.values(sessions).reduce((a, s) => a + s.slots.size, 0);
  const dtBox       = document.getElementById('sum-datetime');

  if (sortedDates.length === 0) {
    dtBox.innerHTML = '<span style="font-size:12px;color:var(--muted);">Not selected</span>';
  } else {
    let html = '';
    sortedDates.forEach(ds => {
      const sess  = sessions[ds];
      const d     = new Date(ds + 'T00:00:00');
      const label = d.toLocaleDateString('en-MY', { day: 'numeric', month: 'long', year: 'numeric' });
      const slots = [...sess.slots].sort();
      const pills = slots.length
        ? slots.map(t => {
            const [h, m] = t.split(':');
            const hour   = parseInt(h);
            const suffix = hour >= 12 ? 'PM' : 'AM';
            const h12    = hour % 12 || 12;
            return `<span style="font-size:11px;padding:3px 10px;border-radius:999px;
                      background:rgba(231,90,155,.12);color:var(--pink-dark);font-weight:700;">
                      ${h12}:${m} ${suffix}</span>`;
          }).join('')
        : '<span style="font-size:11px;color:var(--muted);">No slots yet</span>';

      html += `
        <div style="margin-bottom:8px;background:rgba(255,241,246,.7);border:1px solid rgba(242,138,178,.18);
                    border-radius:14px;padding:10px 12px;">
          <p style="margin:0 0 6px;font-size:13px;font-weight:900;color:#342635;">${label}</p>
          <div style="display:flex;flex-wrap:wrap;gap:5px;">${pills}</div>
        </div>`;
    });

    if (slotCount > 0) {
      html += `<p style="margin:4px 0 0;font-size:12px;color:var(--muted);text-align:right;font-weight:700;">
                 ${slotCount} session${slotCount !== 1 ? 's' : ''} total</p>`;
    }
    dtBox.innerHTML = html;
  }

  // Update price
  const priceEl = document.querySelector('.summary-card .price-total strong');
  if (priceEl) {
    priceEl.textContent = slotCount > 0
      ? 'RM ' + (slotCount * tutorRate)
      : 'RM ' + tutorRate + '/hr';
  }
}

  function populateReview() {
  const lang  = document.getElementById('selectedLang').value;
  const mode  = document.getElementById('selectedMode').value;
  const loc   = document.getElementById('locationInput')?.value || '';
  const notes = document.getElementById('bookingNotes').value;
  const focus = [...document.querySelectorAll('.focus-chip input:checked')]
                  .map(c => c.value).join(', ') || '—';

  const level = document.getElementById('selectedLevel').value;
document.getElementById('rev-level').textContent = level ? 
    (level === 'beginner' ? 'Beginner' : 
     level === 'intermediate' ? 'Intermediate' : 
     level === 'advanced' ? 'Advanced' : 'Master') : '—';

  document.getElementById('rev-lang').textContent  = lang || '—';
  document.getElementById('rev-mode').textContent  = mode === 'online' ? '💻 Online' : '🤝 Face to Face';
  document.getElementById('rev-focus').textContent = focus;

  if (mode === 'face_to_face' && loc) {
    document.getElementById('rev-location-row').style.display = 'flex';
    document.getElementById('rev-location').textContent = loc;
  }
  if (notes) {
    document.getElementById('rev-notes-row').style.display = 'flex';
    document.getElementById('rev-notes').textContent = notes;
  }

  // Date & Time block
  const sortedDates = Object.keys(sessions).sort();
  const slotCount   = Object.values(sessions).reduce((a, s) => a + s.slots.size, 0);
  const dtBox       = document.getElementById('rev-datetime');

  if (sortedDates.length === 0) {
    dtBox.innerHTML = '<span style="color:var(--muted);font-size:13px;">—</span>';
  } else {
    let html = '';
    sortedDates.forEach(ds => {
      const sess  = sessions[ds];
      const d     = new Date(ds + 'T00:00:00');
      const label = d.toLocaleDateString('en-MY', { day: 'numeric', month: 'long', year: 'numeric' });
      const slots = [...sess.slots].sort();

      const pills = slots.length
        ? slots.map(t => {
            const [h, m] = t.split(':');
            const hour   = parseInt(h);
            const suffix = hour >= 12 ? 'PM' : 'AM';
            const h12    = hour % 12 || 12;
            return `<span style="font-size:11px;padding:3px 10px;border-radius:999px;
                      background:rgba(231,90,155,.12);color:var(--pink-dark);font-weight:700;">
                      ${h12}:${m} ${suffix}</span>`;
          }).join('')
        : '<span style="font-size:11px;color:var(--muted);">No slots</span>';

      html += `
        <div style="margin-bottom:8px;background:rgba(255,241,246,.7);
                    border:1px solid rgba(242,138,178,.18);border-radius:14px;padding:10px 12px;">
          <p style="margin:0 0 6px;font-size:13px;font-weight:900;color:#342635;">${label}</p>
          <div style="display:flex;flex-wrap:wrap;gap:5px;">${pills}</div>
        </div>`;
    });

    html += `<p style="margin:4px 0 0;font-size:12px;color:var(--muted);text-align:right;font-weight:700;">
               ${slotCount} session${slotCount !== 1 ? 's' : ''} total</p>`;

    dtBox.innerHTML = html;
  }

  // Update total price in review
  const priceEl = document.querySelector('#step3 .price-total strong');
  if (priceEl) priceEl.textContent = 'RM ' + (slotCount * tutorRate) + ' total';

  updateSummary();
}
            
  // ── Step navigation ──────────────────────────────
  function goStep(n) {
   if (n === 2) {
  const lang  = document.getElementById('selectedLang').value;
  const mode  = document.getElementById('selectedMode').value;
  const level = document.getElementById('selectedLevel').value;
  const focus = [...document.querySelectorAll('.focus-chip input:checked')];
  const isFace = mode === 'face_to_face';
  const locVal = document.getElementById('locationInput').value.trim();

  if (!lang || !mode || focus.length === 0 || (isFace && (!locVal || !studentLatLng))) {
    showToast('Please fill in all details'); return;
  }
  if (!level) { showToast('Please select your proficiency level'); return; }
  if (!lang) { showToast('Please select a language'); return; }
  if (!mode) { showToast('Please select a learning mode'); return; }
  if (focus.length === 0) { showToast('Please select at least one focus area'); return; }
  if (isFace) {
    if (!locVal) { showToast('Please enter your meeting location'); return; }
    if (!studentLatLng) { showToast('Please search and select a location from the results'); return; }
    if (document.getElementById('locationWarning').style.display === 'block'
        && !tutorModes.includes('online')) {
      showToast('You are too far from the tutor for face to face sessions'); return;
    }
  }
}
    
    if (n === 3) {
      const slotCount = Object.values(sessions).reduce((a,s) => a + s.slots.size, 0);
      if (Object.keys(sessions).length === 0) { showToast('Please select at least one date'); return; }
      const emptyDay = Object.entries(sessions).find(([ds, sess]) => sess.slots.size === 0);
        if (emptyDay) {
          const [ds, sess] = emptyDay;
          showToast('Please select a time slot for ' + sess.dayName + ' (' + ds + ')');
          return;
        }
      populateReview();
    }
    currentStep = n;
    document.querySelectorAll('.form-section').forEach((s,i) => {
      s.classList.toggle('active', i+1 === n);
    });
    [1,2,3].forEach(i => {
      const dot = document.getElementById('dot'+i);
      dot.className = 'step-dot ' + (i < n ? 'done' : i === n ? 'active' : 'inactive');
      dot.textContent = i < n ? '✓' : i;
    });
    window.scrollTo({top:0,behavior:'smooth'});
  }

  function submitBooking() {
  const lang  = document.getElementById('selectedLang').value;
  const mode  = document.getElementById('selectedMode').value;
  const notes = document.getElementById('bookingNotes').value;
  const focus = [...document.querySelectorAll('.focus-chip input:checked')].map(c => c.value).join(', ');
  const loc   = document.getElementById('locationInput')?.value || '';
  const level = document.getElementById('selectedLevel').value;
  const bookings = [];
  Object.entries(sessions).forEach(([ds, sess]) => {
    sess.slots.forEach(timeVal => {
      bookings.push({ date: ds, time: timeVal });
    });
  });

  if (!lang || !mode || !level || bookings.length === 0) {  
      showToast('Please complete all required fields');
      return;
   }

  const btn = document.getElementById('submitBtn');
  btn.disabled = true;
  btn.textContent = 'Submitting...';

  // Build a hidden form and submit it
  const form = document.createElement('form');
  form.method = 'POST';
  form.action = 'submit_booking.php';

// Add to fields object
const fields = {
    tutor_id: tutorId,
    language: lang,
    mode:     mode,
    focus:    focus,
    proficiency_level: level,  
    notes:    notes,
    location: loc
};

  Object.entries(fields).forEach(([key, val]) => {
    const input = document.createElement('input');
    input.type  = 'hidden';
    input.name  = key;
    input.value = val;
    form.appendChild(input);
  });

  // Add each booking slot as indexed fields
  bookings.forEach((b, i) => {
    const d = document.createElement('input');
    d.type  = 'hidden';
    d.name  = 'booking_date[]';
    d.value = b.date;
    form.appendChild(d);

    const t = document.createElement('input');
    t.type  = 'hidden';
    t.name  = 'booking_time[]';
    t.value = b.time;
    form.appendChild(t);
  });

  document.body.appendChild(form);
  form.submit();
}

    function selectSingle(el, groupId, hiddenId) {
    document.querySelectorAll('#'+groupId+' .sel-chip').forEach(c => c.classList.remove('active'));
    el.classList.add('active');
    document.getElementById(hiddenId).value = el.dataset.val;
    if (hiddenId === 'selectedLang') langUserSelected = el.dataset.val;
    if (hiddenId === 'selectedMode') modeUserSelected = el.dataset.val;
    if (hiddenId === 'selectedLevel') levelUserSelected = el.dataset.val;
    updateSummary();
    }

  const tutorState = document.body.dataset.tutorState;
  const tutorModes = document.body.dataset.tutorModes.split(',').map(m => m.trim()).filter(Boolean);
  const cityCoords = {
    'kuala lumpur': { lat: 3.1390, lng: 101.6869 },
    'penang':       { lat: 5.4141, lng: 100.3288 },
    'johor bahru':  { lat: 1.4927, lng: 103.7414 },
    'kota kinabalu':{ lat: 5.9804, lng: 116.0735 }
  };
  const MAX_KM = 30;
  let studentLatLng = null;

  function checkModeLocation() {
    const mode = document.getElementById('selectedMode').value;
    document.getElementById('locationGroup').style.display = mode === 'face_to_face' ? 'block' : 'none';
    if (mode !== 'face_to_face') resetLocationUI();
  }

  function searchLocation() {
    const query = document.getElementById('locationInput').value.trim();
    if (!query) { showToast('Please enter a location'); return; }
    document.getElementById('locationChecking').style.display = 'block';
    document.getElementById('locationResults').style.display  = 'none';
    document.getElementById('locationOk').style.display       = 'none';
    document.getElementById('locationWarning').style.display  = 'none';
    fetch('nominatim_proxy.php?q=' + encodeURIComponent(query))
      .then(r => r.json())
      .then(results => {
        document.getElementById('locationChecking').style.display = 'none';
        if (!results || results.length === 0) { showToast('No results found.'); return; }
        const box = document.getElementById('locationResults');
        box.innerHTML = ''; box.style.display = 'block';
        results.forEach(place => {
          const item = document.createElement('div');
          item.style.cssText = 'padding:12px 16px;cursor:pointer;font-size:13px;border-bottom:1px solid rgba(46,42,59,.06);';
          item.textContent = place.display_name;
          item.onmouseover = () => item.style.background = '#FFF1F6';
          item.onmouseout  = () => item.style.background = 'white';
          item.onclick = () => {
            document.getElementById('locationInput').value = place.display_name;
            box.style.display = 'none';
            studentLatLng = { lat: parseFloat(place.lat), lng: parseFloat(place.lon) };
            checkDistanceFromTutor();
          };
          box.appendChild(item);
        });
      })
      .catch(() => { document.getElementById('locationChecking').style.display='none'; showToast('Search failed.'); });
  }

  function checkDistanceFromTutor() {
    const key = tutorState.toLowerCase().trim();
    const tc  = cityCoords[key];
    if (!tc || !studentLatLng) { showLocationOk('Location accepted.'); return; }
    const R    = 6371;
    const dLat = toRad(studentLatLng.lat - tc.lat);
    const dLng = toRad(studentLatLng.lng - tc.lng);
    const a    = Math.sin(dLat/2)**2 + Math.cos(toRad(tc.lat))*Math.cos(toRad(studentLatLng.lat))*Math.sin(dLng/2)**2;
    const km   = (R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a))).toFixed(1);
    parseFloat(km) <= MAX_KM ? showLocationOk('You are '+km+' km away ✓') : showLocationWarning('You are '+km+' km away — outside 30km range.');
  }
  function toRad(d) { return d * Math.PI / 180; }
  function showLocationOk(msg) {
    document.getElementById('locationOkText').textContent = msg;
    document.getElementById('locationOk').style.display = 'block';
    document.getElementById('locationWarning').style.display = 'none';
  }
  function showLocationWarning(msg) {
    document.getElementById('locationWarnText').textContent = msg;
    document.getElementById('locationWarning').style.display = 'block';
    document.getElementById('locationOk').style.display = 'none';
    const actionBox = document.getElementById('locationAction');
    if (tutorModes.includes('online')) {
      actionBox.innerHTML = `<button onclick="switchToOnline()" style="padding:8px 14px;border-radius:10px;border:none;background:#2D6A42;color:white;font-weight:700;cursor:pointer;">Switch to Online</button>`;
    } else {
      actionBox.innerHTML = `<button onclick="window.location.href='search_tutors.php'" style="padding:8px 14px;border-radius:10px;border:none;background:#A35F3F;color:white;font-weight:700;cursor:pointer;">Find Other Tutors</button>`;
    }
  }
  function resetLocationUI() {
    ['locationOk','locationWarning','locationChecking','locationResults'].forEach(id => {
      document.getElementById(id).style.display = 'none';
    });
    studentLatLng = null;
  }
  function switchToOnline() {
    document.querySelector('[data-val="online"]').click();
    showToast('Switched to online mode');
  }
  function updateFocusChip(cb) {
    document.getElementById('fc-'+cb.value).classList.toggle('active', cb.checked);
  }

  // ── Toast + dropdown ─────────────────────────────
  let toastTimer;
  function showToast(msg) {
    const t = document.getElementById('toast');
    t.textContent = msg; t.classList.add('show');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => t.classList.remove('show'), 2000);
  }
  function toggleDropdown() {
    const d = document.getElementById('profileDropdown');
    d.style.display = d.style.display==='none' ? 'block' : 'none';
  }
  document.addEventListener('click', function(e) {
    const btn = document.getElementById('profileBtn');
    const dd  = document.getElementById('profileDropdown');
    if (btn && dd && !btn.contains(e.target) && !dd.contains(e.target)) dd.style.display = 'none';
  });

  // ── Init ─────────────────────────────────────────
  checkModeLocation();
  updateSummary();
  renderCalendar();
function checkStudentConflict(date, time) {
    fetch('check_student_bookings.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ date: date, time: time })
    })
    .then(response => response.json())
    .then(data => {
        if (data.hasConflict) {
            showToast(`You already have a session at ${time} on this day with another tutor.`);
            return false;
        }
        return true;
    });
}
</script>
<script>
history.pushState(null, null, location.href);
window.addEventListener('popstate', function() {
    window.location.href = 'login.php';
});
</script>
<script src="../js/nav.js"></script>
<?php
$prefillLang  = $_POST['prefill_lang']  ?? '';
$prefillMode  = $_POST['prefill_mode']  ?? '';
$prefillFocus = $_POST['prefill_focus'] ?? '';
?>
<script>
<?php if ($prefillLang): ?>
document.querySelectorAll('#langChips .sel-chip').forEach(btn => {
  if (btn.dataset.val === '<?= e($prefillLang) ?>') btn.click();
});
<?php endif; ?>
<?php if ($prefillMode): ?>
document.querySelectorAll('#modeChips .sel-chip').forEach(btn => {
  if (btn.dataset.val === '<?= e($prefillMode) ?>') btn.click();
});
<?php endif; ?>
<?php if ($prefillFocus): ?>
const focuses = '<?= e($prefillFocus) ?>'.split(',').map(f => f.trim());
document.querySelectorAll('.focus-chip input[type=checkbox]').forEach(cb => {
  if (focuses.includes(cb.value)) {
    cb.checked = true;
    updateFocusChip(cb);
  }
});
<?php endif; ?>
</script>
</body>
</html>