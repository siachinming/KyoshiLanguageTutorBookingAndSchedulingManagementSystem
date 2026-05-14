<?php
session_start();
include 'config.php';
$assetBase = '../assets/img';

if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit(); }
$userID = $_SESSION['user_id'];

$bookingID = intval($_GET['id'] ?? 0);
if (!$bookingID) { header("Location: booking_status.php"); exit(); }

// Get original booking
$stmt = $conn->prepare("
    SELECT b.*, u.fullname AS tutor_name, u.profile_pic AS tutor_pic, u.email AS tutor_email,
           tp.rate, tp.bio, tp.experience,
           GROUP_CONCAT(DISTINCT tl.language) AS tutor_languages,
           GROUP_CONCAT(DISTINCT ttm.mode) AS teaching_modes,
           ul.location
    FROM bookings b
    JOIN users u ON b.tutor_id = u.id
    JOIN tutor_profiles tp ON b.tutor_id = tp.user_id
    LEFT JOIN tutor_languages tl ON b.tutor_id = tl.user_id
    LEFT JOIN tutor_teaching_modes ttm ON b.tutor_id = ttm.user_id
    LEFT JOIN user_locations ul ON b.tutor_id = ul.user_id
    WHERE b.id = ? AND b.student_id = ? AND b.status = 'confirmed'
");
$stmt->bind_param("ii", $bookingID, $userID);
$stmt->execute();
$b = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$b) { header("Location: booking_status.php"); exit(); }

// Get student info
$stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
$stmt->bind_param("i", $userID);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

$displayName = $user['fullname'];
$profilePic  = !empty($user['profile_pic']) ? '../uploads/profiles/' . $user['profile_pic'] : $assetBase . '/profile-student.png';
$tutorPic    = !empty($b['tutor_pic']) ? '../uploads/profiles/' . $b['tutor_pic'] : $assetBase . '/profile-tutor.png';

// Get tutor availability
$stmt = $conn->prepare("SELECT * FROM tutor_availability WHERE tutor_id = ? ORDER BY FIELD(day_of_week,'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday')");
$stmt->bind_param("i", $b['tutor_id']);
$stmt->execute();
$availResult = $stmt->get_result();
$availRows = [];
while ($row = $availResult->fetch_assoc()) $availRows[] = $row;
$stmt->close();

$availability = [];
foreach ($availRows as $row) {
    $availability[$row['day_of_week']] = ['start' => $row['start_time'], 'end' => $row['end_time']];
}

$tutorLangs = array_filter(array_map('trim', explode(',', $b['tutor_languages'] ?? '')));
$tutorModes = array_filter(array_map('trim', explode(',', $b['teaching_modes'] ?? '')));

$availDaysJson = json_encode(array_keys($availability));
$availJson     = json_encode($availability);

function e($v){ return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Reschedule Booking · Kyoshi</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
  <style>
    :root{
      --cream:#FFF1F6;--paper:rgba(255,255,255,.88);--ink:#342635;--muted:#7B6178;
      --pink:#F28AB2;--pink-dark:#C94F86;--hot-pink:#E75A9B;
      --lavender:#EAD7FF;--peach:#FFD0DD;--mint:#DDF4E3;--sky:#D8ECFF;
      --shadow:0 18px 45px rgba(201,79,134,.16);--shadow-soft:0 10px 26px rgba(201,79,134,.10);
      --radius-xl:32px;--radius-lg:24px;--radius-md:18px;
    }
    *{box-sizing:border-box}html{scroll-behavior:smooth}
    body{margin:0;min-height:100vh;font-family:"Segoe UI",Arial,sans-serif;color:var(--ink);
      background:linear-gradient(120deg,rgba(255,241,246,.74),rgba(255,203,220,.30)),
      url("<?= e($assetBase) ?>/background3.jpg") center/cover fixed no-repeat;}
    body::before{content:"";position:fixed;inset:0;pointer-events:none;z-index:-1;
      background:radial-gradient(circle at 7% 10%,rgba(231,90,155,.32),transparent 24%),
      radial-gradient(circle at 90% 8%,rgba(255,195,216,.42),transparent 26%)}
    a{text-decoration:none;color:inherit}button,input,select,textarea{font-family:inherit}
    .container{width:min(1100px,calc(100% - 40px));margin:0 auto}

    .topbar{position:sticky;top:0;z-index:50;background:rgba(255,241,246,.86);backdrop-filter:blur(20px);border-bottom:1px solid rgba(231,90,155,.18);box-shadow:0 10px 30px rgba(201,79,134,.10)}
    .nav{min-height:78px;display:grid;grid-template-columns:190px minmax(0,1fr) auto;gap:16px;align-items:center;}
    .brand{display:flex;align-items:center;gap:10px}
    .brand img{width:44px;height:44px;object-fit:contain;border-radius:14px}
    .brand strong{display:block;font-size:18px;line-height:1.05}
    .brand span{display:block;margin-top:3px;font-size:11px;color:var(--muted);white-space:nowrap}
    .nav-links{display:flex;align-items:center;justify-content:center;gap:6px;background:rgba(255,255,255,.58);border:1px solid rgba(242,138,178,.18);border-radius:999px;padding:7px;overflow:auto;scrollbar-width:none;}
    .nav-links::-webkit-scrollbar{display:none}
    .nav-links a{flex:0 0 auto;padding:9px 12px;border-radius:999px;font-size:13px;font-weight:900;color:#6D4964;white-space:nowrap;transition:.18s ease}
    .nav-links a.active,.nav-links a:hover{background:linear-gradient(135deg,var(--hot-pink),var(--pink));color:#fff}
    .nav-actions{display:flex;align-items:center;gap:10px}
    .profile{display:flex;align-items:center;gap:9px;border-radius:999px;padding:6px 12px 6px 6px;font-weight:900;color:#7A3D65;border:1px solid rgba(46,42,59,.08);background:rgba(255,255,255,.88);cursor:pointer}
    .profile img{width:34px;height:34px;object-fit:cover;border-radius:50%}

    .back-link{display:inline-flex;align-items:center;gap:6px;color:var(--pink-dark);font-weight:900;font-size:13px;padding:9px 16px;border-radius:999px;background:rgba(255,255,255,.78);border:1px solid rgba(46,42,59,.08);transition:.18s ease;margin:20px 0 16px;}
    .back-link:hover{transform:translateY(-1px)}

    .booking-grid{display:grid;grid-template-columns:1fr 380px;gap:24px;padding-bottom:48px;align-items:start}
    .glass{background:var(--paper);border:1px solid rgba(255,255,255,.55);box-shadow:var(--shadow);border-radius:var(--radius-xl);padding:28px}

    /* RESCHEDULE NOTICE */
    .reschedule-notice{padding:14px 18px;border-radius:16px;background:rgba(221,211,255,.4);border:1px solid rgba(167,123,232,.25);color:#5A3D7A;font-size:13px;font-weight:700;margin-bottom:20px;display:flex;align-items:center;gap:10px;line-height:1.5}

    .tutor-summary{display:flex;gap:16px;align-items:flex-start;margin-bottom:24px;padding-bottom:24px;border-bottom:1px solid rgba(46,42,59,.08)}
    .tutor-summary img{width:80px;height:80px;object-fit:cover;border-radius:20px;flex:0 0 auto;background:#eee}
    .tutor-summary h2{margin:0 0 6px;font-size:20px}
    .tutor-summary p{margin:0;color:var(--muted);font-size:13px;line-height:1.5}
    .lang-tags{display:flex;flex-wrap:wrap;gap:6px;margin-top:8px}
    .lang-tag{padding:4px 10px;border-radius:999px;background:rgba(242,138,178,.18);color:var(--pink-dark);font-size:11px;font-weight:900}
    .mode-tag{padding:4px 10px;border-radius:999px;background:rgba(221,211,255,.5);color:#7648B8;font-size:11px;font-weight:900}

    .steps{display:flex;gap:0;margin-bottom:28px}
    .step{flex:1;text-align:center;position:relative}
    .step::after{content:"";position:absolute;top:18px;left:50%;width:100%;height:2px;background:rgba(46,42,59,.10);z-index:0}
    .step:last-child::after{display:none}
    .step-dot{width:36px;height:36px;border-radius:50%;display:grid;place-items:center;margin:0 auto 8px;font-size:13px;font-weight:900;position:relative;z-index:1;transition:.2s ease}
    .step-dot.done{background:linear-gradient(135deg,var(--hot-pink),var(--pink));color:white}
    .step-dot.active{background:linear-gradient(135deg,var(--hot-pink),var(--pink));color:white;box-shadow:0 6px 16px rgba(231,90,155,.32)}
    .step-dot.inactive{background:rgba(255,255,255,.88);border:2px solid rgba(46,42,59,.12);color:#9080a0}
    .step-label{font-size:11px;font-weight:700;color:var(--muted)}

    .form-section{display:none}
    .form-section.active{display:block}
    .form-group{margin-bottom:20px}
    .form-group label{display:block;font-size:13px;font-weight:900;color:#342635;margin-bottom:8px}
    .form-group label i{color:var(--hot-pink);margin-right:5px}
    .form-control{width:100%;padding:12px 16px;border:1px solid rgba(46,42,59,.12);border-radius:14px;outline:none;font-size:14px;color:#342635;background:rgba(255,255,255,.9);transition:.15s ease}
    .form-control:focus{border-color:var(--hot-pink);box-shadow:0 0 0 3px rgba(231,90,155,.12)}
    textarea.form-control{resize:vertical;min-height:90px}

    .chip-group{display:flex;flex-wrap:wrap;gap:8px}
    .sel-chip{padding:9px 16px;border-radius:999px;border:1px solid rgba(46,42,59,.12);background:white;font-size:13px;font-weight:700;color:#7A5570;cursor:pointer;transition:.15s ease}
    .sel-chip.active{background:linear-gradient(135deg,var(--hot-pink),var(--pink));color:white;border-color:var(--pink);box-shadow:0 6px 14px rgba(231,90,155,.25)}
    .sel-chip:hover:not(.active){transform:translateY(-1px)}

    .calendar-wrap{margin-bottom:4px}
    .cal-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:12px}
    .cal-header strong{font-size:15px}
    .cal-nav{width:32px;height:32px;border-radius:10px;border:1px solid rgba(46,42,59,.10);background:white;cursor:pointer;display:grid;place-items:center;color:#7A4A68;font-size:14px}
    .cal-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:4px}
    .cal-day-name{text-align:center;font-size:11px;font-weight:900;color:var(--muted);padding:4px 0}
    .cal-day{text-align:center;padding:8px 4px;border-radius:10px;font-size:13px;font-weight:700;cursor:pointer;transition:.15s ease;border:1px solid transparent}
    .cal-day.available{background:rgba(221,244,230,.6);color:#2D6A42}
    .cal-day.available:hover{background:rgba(231,90,155,.12);border-color:var(--pink);color:var(--pink-dark)}
    .cal-day.unavailable{color:#ccc;cursor:not-allowed}
    .cal-day.past{color:#ddd;cursor:not-allowed}
    .cal-day.empty{visibility:hidden}
    .cal-day.in-range{background:rgba(242,138,178,.18);border-radius:0;color:var(--pink-dark)}
    .cal-day.range-start{background:linear-gradient(135deg,var(--hot-pink),var(--pink));color:white;border-radius:10px 0 0 10px;box-shadow:0 4px 12px rgba(231,90,155,.28)}
    .cal-day.range-end{background:linear-gradient(135deg,var(--hot-pink),var(--pink));color:white;border-radius:0 10px 10px 0;box-shadow:0 4px 12px rgba(231,90,155,.28)}
    .cal-day.range-start.range-end{border-radius:10px}
    .cal-day.has-slots{outline:2px solid var(--hot-pink);outline-offset:-2px}

    .time-slot{padding:8px 14px;border-radius:999px;border:1px solid rgba(46,42,59,.10);background:white;font-size:12px;font-weight:700;color:#7A5570;cursor:pointer;transition:.15s ease}
    .time-slot.active{background:linear-gradient(135deg,var(--hot-pink),var(--pink));color:white;border-color:var(--pink)}
    .time-slot:hover:not(.active){background:rgba(231,90,155,.08);border-color:var(--pink)}

    .focus-chip{display:flex;align-items:center;gap:8px;padding:10px 14px;border-radius:14px;border:1px solid rgba(46,42,59,.10);background:white;font-size:13px;font-weight:700;color:#7A5570;cursor:pointer;transition:.15s ease}
    .focus-chip input[type=checkbox]{accent-color:var(--hot-pink);width:16px;height:16px}
    .focus-chip.active{background:rgba(231,90,155,.08);border-color:var(--pink);color:var(--pink-dark)}

    .form-nav{display:flex;justify-content:space-between;align-items:center;margin-top:24px;padding-top:20px;border-top:1px solid rgba(46,42,59,.08)}
    .btn-prev{padding:11px 22px;border-radius:999px;border:1px solid rgba(46,42,59,.12);background:none;color:#7A5570;font-size:13px;font-weight:900;cursor:pointer;transition:.18s ease}
    .btn-next{padding:11px 26px;border-radius:999px;border:none;background:linear-gradient(135deg,var(--hot-pink),var(--pink));color:white;font-size:13px;font-weight:900;cursor:pointer;box-shadow:0 8px 20px rgba(231,90,155,.28);transition:.18s ease}
    .btn-next:hover{transform:translateY(-1px)}

    .summary-card{background:var(--paper);border:1px solid rgba(255,255,255,.55);box-shadow:var(--shadow);border-radius:var(--radius-xl);padding:24px;position:sticky;top:96px}
    .summary-card h3{margin:0 0 18px;font-size:18px}
    .summary-row{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;padding:10px 0;border-bottom:1px solid rgba(46,42,59,.06);font-size:13px}
    .summary-row:last-of-type{border-bottom:none}
    .summary-label{color:var(--muted);font-weight:700;flex-shrink:0}
    .summary-val{font-weight:900;color:#342635;text-align:right}

    .avail-note{padding:12px 16px;border-radius:14px;background:rgba(221,244,230,.6);border:1px solid rgba(45,106,66,.2);color:#2D6A42;font-size:13px;font-weight:700;margin-bottom:16px;display:flex;align-items:center;gap:8px}

    .toast{position:fixed;left:50%;bottom:28px;transform:translate(-50%,18px);opacity:0;pointer-events:none;z-index:99;background:#8E3F70;color:#fff;border-radius:999px;padding:12px 18px;font-size:13px;font-weight:900;transition:.2s ease}
    .toast.show{opacity:1;transform:translate(-50%,0)}

    /* LOCATION */
    .form-group#locationGroup{display:none}

    @media(max-width:900px){.booking-grid{grid-template-columns:1fr}}
  </style>
</head>
<body data-tutor-state="<?= e($b['location'] ?? '') ?>" data-tutor-modes="<?= e(implode(',', array_values($tutorModes))) ?>">

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
  <a href="booking_detail.php?id=<?= $bookingID ?>" class="back-link">
    <i class="bi bi-arrow-left"></i> Back to Booking
  </a>

  <!-- RESCHEDULE NOTICE -->
  <div class="reschedule-notice">
    <i class="bi bi-calendar-plus" style="font-size:18px;color:#7648B8;"></i>
    <div>
      <strong style="display:block;margin-bottom:2px;">Rescheduling Booking #<?= $bookingID ?></strong>
      Your payment will carry over. After rescheduling, the tutor needs to approve the new date.
    </div>
  </div>

  <div class="booking-grid">
    <!-- LEFT FORM -->
    <div class="glass">
      <!-- Tutor Summary -->
      <div class="tutor-summary">
        <img src="<?= e($tutorPic) ?>" alt="<?= e($b['tutor_name']) ?>">
        <div>
          <h2><?= e($b['tutor_name']) ?></h2>
          <p><?= e($b['experience']) ?> years experience · RM <?= e($b['rate']) ?>/hr</p>
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
        <div class="step"><div class="step-dot active" id="dot1">1</div><div class="step-label">Details</div></div>
        <div class="step"><div class="step-dot inactive" id="dot2">2</div><div class="step-label">Schedule</div></div>
        <div class="step"><div class="step-dot inactive" id="dot3">3</div><div class="step-label">Review</div></div>
      </div>

      <!-- STEP 1 -->
      <div class="form-section active" id="step1">

        <!-- Language -->
        <div class="form-group">
          <label><i class="bi bi-translate"></i> Language to learn</label>
          <div class="chip-group" id="langChips">
            <?php foreach ($tutorLangs as $l): ?>
              <button type="button" class="sel-chip <?= $l === $b['language'] ? 'active' : '' ?>"
                data-val="<?= e($l) ?>" onclick="selectSingle(this,'langChips','selectedLang')">
                <?= e($l) ?>
              </button>
            <?php endforeach; ?>
          </div>
        </div>

        <!-- Mode -->
        <div class="form-group">
          <label><i class="bi bi-laptop"></i> Learning mode</label>
          <div class="chip-group" id="modeChips">
            <?php foreach ($tutorModes as $m): ?>
              <button type="button" class="sel-chip <?= $m === $b['learning_mode'] ? 'active' : '' ?>"
                data-val="<?= e($m) ?>" onclick="selectSingle(this,'modeChips','selectedMode');checkModeLocation()">
                <?= $m === 'online' ? '💻 Online' : '🤝 Face to Face' ?>
              </button>
            <?php endforeach; ?>
          </div>
          <input type="hidden" id="selectedLang" value="<?= e($b['language']) ?>">
          <input type="hidden" id="selectedMode" value="<?= e($b['learning_mode']) ?>">
        </div>

        <!-- Location (if face to face) -->
        <div class="form-group" id="locationGroup" style="display:none;">
          <label><i class="bi bi-geo-alt"></i> Your meeting location</label>
          <div style="padding:10px 14px;border-radius:12px;background:rgba(221,211,255,.3);border:1px solid rgba(167,123,232,.2);font-size:13px;color:#6D4964;margin-bottom:10px;display:flex;align-items:center;gap:8px;">
            <i class="bi bi-info-circle" style="color:#A77BE8;"></i>
            Tutor is based in <strong style="margin:0 4px;"><?= e($b['location'] ?? 'Unknown') ?></strong> — face to face within 30km only.
          </div>
          <div style="display:flex;gap:8px;align-items:center;">
            <div style="position:relative;flex:1;">
              <i class="bi bi-search" style="position:absolute;left:14px;top:50%;transform:translateY(-50%);color:#91899F;pointer-events:none;"></i>
              <input type="text" id="locationInput" class="form-control" placeholder="Type your area e.g. Bangsar, Kuala Lumpur" style="padding-left:40px;" autocomplete="off" onkeydown="if(event.key==='Enter'){event.preventDefault();searchLocation();}">
            </div>
            <button type="button" onclick="searchLocation()" style="padding:12px 18px;border-radius:14px;border:none;background:linear-gradient(135deg,#E75A9B,#F28AB2);color:white;font-size:13px;font-weight:900;cursor:pointer;white-space:nowrap;">Search</button>
          </div>
          <div id="locationResults" style="display:none;margin-top:8px;background:white;border:1px solid rgba(46,42,59,.12);border-radius:14px;overflow:hidden;box-shadow:0 10px 26px rgba(201,79,134,.10);"></div>
          <div id="locationChecking" style="display:none;margin-top:10px;padding:10px 14px;border-radius:12px;background:rgba(221,211,255,.3);border:1px solid rgba(167,123,232,.2);font-size:13px;color:#6D4964;"><i class="bi bi-hourglass-split"></i> Searching...</div>
          <div id="locationOk" style="display:none;margin-top:10px;padding:10px 14px;border-radius:12px;background:rgba(221,244,230,.6);border:1px solid rgba(45,106,66,.2);color:#2D6A42;font-size:13px;font-weight:700;"><i class="bi bi-check-circle-fill"></i> <span id="locationOkText">Within range!</span></div>
          <div id="locationWarning" style="display:none;margin-top:10px;padding:10px 14px;border-radius:12px;background:rgba(255,217,199,.6);border:1px solid rgba(163,95,63,.2);color:#A35F3F;font-size:13px;font-weight:700;">
            <i class="bi bi-exclamation-triangle"></i> <span id="locationWarnText">Too far from tutor.</span>
            <div id="locationAction" style="margin-top:8px;"></div>
          </div>
        </div>

        <!-- Focus -->
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

        <!-- Notes -->
        <div class="form-group">
          <label><i class="bi bi-chat-left-text"></i> Notes for tutor <span style="font-weight:400;color:var(--muted);">(optional)</span></label>
          <textarea class="form-control" id="bookingNotes" placeholder="Any special requests for the new session..."></textarea>
        </div>

        <div class="form-nav">
          <span></span>
          <button class="btn-next" onclick="goStep(2)">Next: Choose Schedule <i class="bi bi-arrow-right"></i></button>
        </div>
      </div>

      <!-- STEP 2 -->
      <div class="form-section" id="step2">
        <?php if (empty($availability)): ?>
          <div style="padding:12px 16px;border-radius:14px;background:rgba(255,217,199,.5);border:1px solid rgba(163,95,63,.2);color:#A35F3F;font-size:13px;font-weight:700;">
            <i class="bi bi-exclamation-circle"></i> This tutor hasn't set availability yet.
          </div>
        <?php else: ?>
          <div class="avail-note">
            <i class="bi bi-info-circle-fill"></i>
            <span>Green dates are available. Tap a date to select then choose time slots to reschedule.</span>
          </div>
          <div class="form-group">
            <label><i class="bi bi-calendar3"></i> Pick your new session date(s)</label>
            <div class="calendar-wrap">
              <div class="cal-header">
                <button class="cal-nav" type="button" onclick="changeMonth(-1)"><i class="bi bi-chevron-left"></i></button>
                <strong id="calMonthLabel"></strong>
                <button class="cal-nav" type="button" onclick="changeMonth(1)"><i class="bi bi-chevron-right"></i></button>
              </div>
              <div class="cal-grid" id="calGrid"></div>
            </div>
            <div id="rangeHint" style="margin-top:10px;font-size:12px;color:var(--muted);font-weight:700;min-height:18px;"></div>
          </div>
          <div id="daySlotPanels"></div>
        <?php endif; ?>

        <div class="form-nav">
          <button class="btn-prev" onclick="goStep(1)"><i class="bi bi-arrow-left"></i> Back</button>
          <button class="btn-next" onclick="goStep(3)">Next: Review <i class="bi bi-arrow-right"></i></button>
        </div>
      </div>

      <!-- STEP 3 -->
      <div class="form-section" id="step3">
        <h3 style="margin:0 0 20px;font-size:18px;">Review your reschedule</h3>
        <div style="background:rgba(255,241,246,.6);border:1px solid rgba(242,138,178,.2);border-radius:20px;padding:20px;margin-bottom:20px;">
          <div class="summary-row"><span class="summary-label">Tutor</span><span class="summary-val"><?= e($b['tutor_name']) ?></span></div>
          <div class="summary-row"><span class="summary-label">Language</span><span class="summary-val" id="rev-lang">—</span></div>
          <div class="summary-row"><span class="summary-label">Mode</span><span class="summary-val" id="rev-mode">—</span></div>
          <div class="summary-row" id="rev-location-row" style="display:none;"><span class="summary-label">Location</span><span class="summary-val" id="rev-location">—</span></div>
          <div class="summary-row"><span class="summary-label">New Date</span><span class="summary-val" id="rev-date">—</span></div>
          <div class="summary-row"><span class="summary-label">New Time</span><span class="summary-val" id="rev-time">—</span></div>
          <div class="summary-row"><span class="summary-label">Focus</span><span class="summary-val" id="rev-focus">—</span></div>
          <div class="summary-row" id="rev-notes-row" style="display:none;"><span class="summary-label">Notes</span><span class="summary-val" id="rev-notes" style="max-width:200px;word-break:break-word;">—</span></div>
        </div>
        <div style="padding:12px 16px;border-radius:14px;background:rgba(221,211,255,.3);border:1px solid rgba(167,123,232,.2);font-size:13px;color:#5A3D7A;font-weight:700;">
          <i class="bi bi-info-circle"></i> After confirming, your reschedule request will be sent to the tutor for approval. Your original booking remains active until the tutor responds. Your payment is carried over.
        </div>
        <div class="form-nav">
          <button class="btn-prev" onclick="goStep(2)"><i class="bi bi-arrow-left"></i> Back</button>
          <button class="btn-next" onclick="submitReschedule()" id="submitBtn"><i class="bi bi-check2-circle"></i> Confirm Reschedule</button>
        </div>
      </div>
    </div>

    <!-- RIGHT SUMMARY -->
    <div class="summary-card">
      <h3>Reschedule Summary</h3>
      <div class="summary-row"><span class="summary-label">Tutor</span><span class="summary-val"><?= e($b['tutor_name']) ?></span></div>
      <div class="summary-row"><span class="summary-label">Language</span><span class="summary-val" id="sum-lang"><?= e($b['language']) ?></span></div>
      <div class="summary-row"><span class="summary-label">Mode</span><span class="summary-val" id="sum-mode"><?= $b['learning_mode']==='online'?'💻 Online':'🤝 Face to Face' ?></span></div>
      <div class="summary-row"><span class="summary-label">New Date</span><span class="summary-val" id="sum-date">Not selected</span></div>
      <div class="summary-row"><span class="summary-label">New Time</span><span class="summary-val" id="sum-time">Not selected</span></div>
      <div style="margin-top:16px;padding:14px;border-radius:16px;background:rgba(221,211,255,.3);border:1px solid rgba(167,123,232,.2);font-size:12px;color:#5A3D7A;font-weight:700;line-height:1.5;">
        <i class="bi bi-shield-check"></i> Payment already verified — no need to pay again after rescheduling.
      </div>
    </div>
  </div>
</div>

<div class="toast" id="toast"></div>

<script>
  const availability = <?= $availJson ?>;
  const availDays    = <?= $availDaysJson ?>;
  const tutorRate    = <?= intval($b['rate']) ?>;
  const bookingID    = <?= $bookingID ?>;
  const tutorModes   = document.body.dataset.tutorModes.split(',').map(m => m.trim()).filter(Boolean);
  const tutorState   = document.body.dataset.tutorState;

  let currentStep = 1;
  let calYear, calMonth;
  const now = new Date(); now.setHours(0,0,0,0);
  calYear  = now.getFullYear();
  calMonth = now.getMonth();

  let selectedDate = null;
let selectedTime = null;
  let langUserSelected = document.getElementById('selectedLang').value;
  let modeUserSelected = document.getElementById('selectedMode').value;
  let studentLatLng = null;

  const DAY_NAMES   = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
  const MONTH_NAMES = ['January','February','March','April','May','June','July','August','September','October','November','December'];

  function dateStr(d) { return d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0')+'-'+String(d.getDate()).padStart(2,'0'); }
  function parseDateStr(s) { const [y,m,d]=s.split('-').map(Number); return new Date(y,m-1,d); }
  function dayName(d) { return DAY_NAMES[d.getDay()]; }
  function isAvail(d) { return availDays.includes(dayName(d)); }
  function isPast(d)  { return d < now; }
  function fmt(h,m) { const s=h>=12?'PM':'AM',h12=h%12||12; return h12+(m?':'+String(m).padStart(2,'0'):'')+' '+s; }

  function renderCalendar() {
    document.getElementById('calMonthLabel').textContent = MONTH_NAMES[calMonth]+' '+calYear;
    const grid = document.getElementById('calGrid');
    grid.innerHTML = '';
    ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'].forEach(n => {
      const el = document.createElement('div'); el.className='cal-day-name'; el.textContent=n; grid.appendChild(el);
    });
    const firstDay=new Date(calYear,calMonth,1).getDay();
    const daysInMonth=new Date(calYear,calMonth+1,0).getDate();
    for(let i=0;i<firstDay;i++){const el=document.createElement('div');el.className='cal-day empty';grid.appendChild(el);}
    for(let d=1;d<=daysInMonth;d++){
      const date=new Date(calYear,calMonth,d), ds=dateStr(date);
      const el=document.createElement('div'); el.textContent=d; el.dataset.date=ds;
      if(isPast(date)){el.className='cal-day past';}
      else if(!isAvail(date)){el.className='cal-day unavailable';}
      else{
        let cls = 'cal-day available';
        if (selectedDate === ds) cls += ' range-start range-end';
        if (selectedDate === ds && selectedTime) cls += ' has-slots';
        el.className = cls;
        el.onclick = () => handleDateClick(date);
      }
      grid.appendChild(el);
    }
  }

  function changeMonth(dir){calMonth+=dir;if(calMonth>11){calMonth=0;calYear++;}if(calMonth<0){calMonth=11;calYear--;}renderCalendar();}

  function buildSessionsFromRange(){
    Object.keys(sessions).forEach(ds=>{const d=parseDateStr(ds);if(d<rangeStart||d>rangeEnd)delete sessions[ds];});
    const cur=new Date(rangeStart);
    while(cur<=rangeEnd){const ds=dateStr(cur);if(isAvail(cur)&&!isPast(cur)&&!sessions[ds])sessions[ds]={dayName:dayName(cur),slots:new Set()};cur.setDate(cur.getDate()+1);}
    renderDayPanels();updateSummary();
  }

  function renderDayPanels(){
  const container = document.getElementById('daySlotPanels');
  container.innerHTML = '';

  if(!selectedDate) return;

  const dateObj = parseDateStr(selectedDate);
  const day = dayName(dateObj);
  const avail = availability[day];

  if(!avail) return;

  const panel = document.createElement('div');
  panel.style.cssText =
    'background:rgba(255,241,246,.7);border:1px solid rgba(242,138,178,.18);border-radius:20px;padding:16px;';

  const header = document.createElement('div');
  header.innerHTML = `<strong>${selectedDate}</strong>`;
  panel.appendChild(header);

  const wrap = document.createElement('div');
  wrap.style.cssText = 'display:flex;flex-wrap:wrap;gap:8px;margin-top:12px;';

  let cur = parseInt(avail.start.split(':')[0]) * 60;
  const end = parseInt(avail.end.split(':')[0]) * 60;

  while(cur + 60 <= end){
    const h = Math.floor(cur/60);
    const timeVal = String(h).padStart(2,'0') + ':00:00';

    const btn = document.createElement('button');
    btn.className = 'time-slot' + (selectedTime === timeVal ? ' active' : '');
    btn.textContent = fmt(h,0) + ' - ' + fmt(h+1,0);

    btn.onclick = () => {
      selectedTime = timeVal;
      renderDayPanels();
      updateSummary();
    };

    wrap.appendChild(btn);
    cur += 60;
  }

  panel.appendChild(wrap);
  container.appendChild(panel);
}
function handleDateClick(date) {
    selectedDate = dateStr(date);
    selectedTime = null;
    renderCalendar();
    renderDayPanels();
    updateSummary();
}

  function toggleSlot(ds,timeVal,btn){
    const sess=sessions[ds];if(!sess)return;
    if(sess.slots.has(timeVal)){sess.slots.delete(timeVal);btn.classList.remove('active');}
    else{sess.slots.add(timeVal);btn.classList.add('active');}
    const sub=document.getElementById('subtotal-'+ds);
    if(sub)sub.textContent=sess.slots.size>0?sess.slots.size+' slot'+(sess.slots.size>1?'s':'')+' selected':'No slots selected yet';
    const calDay=document.querySelector('[data-date="'+ds+'"]');
    if(calDay)calDay.classList.toggle('has-slots',sess.slots.size>0);
    updateSummary();
  }

  function removeDay(ds){
    delete sessions[ds];
    const remaining=Object.keys(sessions).sort();
    if(remaining.length===0){rangeStart=null;rangeEnd=null;document.getElementById('rangeHint').textContent='';}
    else{rangeStart=parseDateStr(remaining[0]);rangeEnd=parseDateStr(remaining[remaining.length-1]);}
    renderCalendar();renderDayPanels();updateSummary();
  }

  function updateSummary(){
  document.getElementById('sum-lang').textContent = langUserSelected || 'Not selected';
  document.getElementById('sum-mode').textContent = modeUserSelected 
    ? (modeUserSelected === 'online' ? '💻 Online' : '🤝 Face to Face')
    : 'Not selected';

  if(!selectedDate){
    document.getElementById('sum-date').textContent = 'Not selected';
    document.getElementById('sum-time').textContent = 'Not selected';
    return;
  }

  document.getElementById('sum-date').textContent = selectedDate;
  document.getElementById('sum-time').textContent = selectedTime || 'Not selected';
}

  function populateReview(){
    const lang=document.getElementById('selectedLang').value;
    const mode=document.getElementById('selectedMode').value;
    const loc=document.getElementById('locationInput')?.value||'';
    const notes=document.getElementById('bookingNotes').value;
    const focus=[...document.querySelectorAll('.focus-chip input:checked')].map(c=>c.value).join(', ')||'—';
    document.getElementById('rev-lang').textContent=lang||'—';
    document.getElementById('rev-mode').textContent=mode==='online'?'💻 Online':'🤝 Face to Face';
    document.getElementById('rev-focus').textContent=focus;
    document.getElementById('rev-date').textContent = selectedDate || '—';
document.getElementById('rev-time').textContent = selectedTime ? selectedTime.substring(0,5) : '—';
    if(mode==='face_to_face'&&loc){document.getElementById('rev-location-row').style.display='flex';document.getElementById('rev-location').textContent=loc;}
    if(notes){document.getElementById('rev-notes-row').style.display='flex';document.getElementById('rev-notes').textContent=notes;}
  }

  function goStep(n){
    if(n===2){
      const lang=document.getElementById('selectedLang').value;
      const mode=document.getElementById('selectedMode').value;
      const focus=[...document.querySelectorAll('.focus-chip input:checked')];
      const isFace=mode==='face_to_face';
      const locVal=document.getElementById('locationInput').value.trim();
      if(!lang||!mode||focus.length===0||(isFace&&(!locVal||!studentLatLng))){showToast('Please fill in all details');return;}
      if(isFace&&document.getElementById('locationWarning').style.display==='block'&&!tutorModes.includes('online')){showToast('You are too far from the tutor');return;}
    }
    if(n===3){
      if(!selectedDate || !selectedTime){
  showToast('Please select date and time');
  return;
}
      populateReview();
    }
    currentStep=n;
    document.querySelectorAll('.form-section').forEach((s,i)=>s.classList.toggle('active',i+1===n));
    [1,2,3].forEach(i=>{const dot=document.getElementById('dot'+i);dot.className='step-dot '+(i<n?'done':i===n?'active':'inactive');dot.textContent=i<n?'✓':i;});
    window.scrollTo({top:0,behavior:'smooth'});
  }

  function submitReschedule(){
    const lang=document.getElementById('selectedLang').value;
    const mode=document.getElementById('selectedMode').value;
    const notes=document.getElementById('bookingNotes').value;
    const focus=[...document.querySelectorAll('.focus-chip input:checked')].map(c=>c.value).join(', ');
    const loc=document.getElementById('locationInput')?.value||'';
   const bookings = [{
  date: selectedDate,
  time: selectedTime
}];
    if(!lang||!mode||bookings.length===0){showToast('Please complete all fields');return;}
    const btn=document.getElementById('submitBtn');btn.disabled=true;btn.textContent='Submitting...';
    const form=document.createElement('form');form.method='POST';form.action='submit_reschedule.php';
    const fields={booking_id:bookingID,language:lang,mode:mode,focus:focus,notes:notes,location:loc};
    Object.entries(fields).forEach(([key,val])=>{const input=document.createElement('input');input.type='hidden';input.name=key;input.value=val;form.appendChild(input);});
    bookings.forEach(bk=>{
      const d=document.createElement('input');d.type='hidden';d.name='booking_date[]';d.value=bk.date;form.appendChild(d);
      const t=document.createElement('input');t.type='hidden';t.name='booking_time[]';t.value=bk.time;form.appendChild(t);
    });
    document.body.appendChild(form);form.submit();
  }

  function selectSingle(el,groupId,hiddenId){
    document.querySelectorAll('#'+groupId+' .sel-chip').forEach(c=>c.classList.remove('active'));
    el.classList.add('active');
    document.getElementById(hiddenId).value=el.dataset.val;
    if(hiddenId==='selectedLang')langUserSelected=el.dataset.val;
    if(hiddenId==='selectedMode')modeUserSelected=el.dataset.val;
    updateSummary();
  }

  function checkModeLocation(){
    const mode=document.getElementById('selectedMode').value;
    document.getElementById('locationGroup').style.display=mode==='face_to_face'?'block':'none';
    if(mode!=='face_to_face')resetLocationUI();
  }

  function searchLocation(){
    const query=document.getElementById('locationInput').value.trim();
    if(!query){showToast('Please enter a location');return;}
    document.getElementById('locationChecking').style.display='block';
    document.getElementById('locationResults').style.display='none';
    document.getElementById('locationOk').style.display='none';
    document.getElementById('locationWarning').style.display='none';
    fetch('nominatim_proxy.php?q='+encodeURIComponent(query)).then(r=>r.json()).then(results=>{
      document.getElementById('locationChecking').style.display='none';
      if(!results||results.length===0){showToast('No results found.');return;}
      const box=document.getElementById('locationResults');box.innerHTML='';box.style.display='block';
      results.forEach(place=>{
        const item=document.createElement('div');item.style.cssText='padding:12px 16px;cursor:pointer;font-size:13px;border-bottom:1px solid rgba(46,42,59,.06);';
        item.textContent=place.display_name;item.onmouseover=()=>item.style.background='#FFF1F6';item.onmouseout=()=>item.style.background='white';
        item.onclick=()=>{document.getElementById('locationInput').value=place.display_name;box.style.display='none';studentLatLng={lat:parseFloat(place.lat),lng:parseFloat(place.lon)};checkDistanceFromTutor();};
        box.appendChild(item);
      });
    }).catch(()=>{document.getElementById('locationChecking').style.display='none';showToast('Search failed.');});
  }

  function checkDistanceFromTutor(){
    const cityCoords={'kuala lumpur':{lat:3.1390,lng:101.6869},'penang':{lat:5.4141,lng:100.3288},'johor bahru':{lat:1.4927,lng:103.7414},'kota kinabalu':{lat:5.9804,lng:116.0735}};
    const key=tutorState.toLowerCase().trim(),tc=cityCoords[key];
    if(!tc||!studentLatLng){showLocationOk('Location accepted.');return;}
    const R=6371,dLat=toRad(studentLatLng.lat-tc.lat),dLng=toRad(studentLatLng.lng-tc.lng);
    const a=Math.sin(dLat/2)**2+Math.cos(toRad(tc.lat))*Math.cos(toRad(studentLatLng.lat))*Math.sin(dLng/2)**2;
    const km=(R*2*Math.atan2(Math.sqrt(a),Math.sqrt(1-a))).toFixed(1);
    parseFloat(km)<=30?showLocationOk('You are '+km+' km away ✓'):showLocationWarning('You are '+km+' km away — outside 30km range.');
  }
  function toRad(d){return d*Math.PI/180;}
  function showLocationOk(msg){document.getElementById('locationOkText').textContent=msg;document.getElementById('locationOk').style.display='block';document.getElementById('locationWarning').style.display='none';}
  function showLocationWarning(msg){document.getElementById('locationWarnText').textContent=msg;document.getElementById('locationWarning').style.display='block';document.getElementById('locationOk').style.display='none';}
  function resetLocationUI(){['locationOk','locationWarning','locationChecking','locationResults'].forEach(id=>{document.getElementById(id).style.display='none';});studentLatLng=null;}
  function updateFocusChip(cb){document.getElementById('fc-'+cb.value).classList.toggle('active',cb.checked);}

  let toastTimer;
  function showToast(msg){const t=document.getElementById('toast');t.textContent=msg;t.classList.add('show');clearTimeout(toastTimer);toastTimer=setTimeout(()=>t.classList.remove('show'),2000);}
  function toggleDropdown(){const d=document.getElementById('profileDropdown');d.style.display=d.style.display==='none'?'block':'none';}
  document.addEventListener('click',function(e){const btn=document.getElementById('profileBtn');const dd=document.getElementById('profileDropdown');if(btn&&dd&&!btn.contains(e.target)&&!dd.contains(e.target))dd.style.display='none';});

  checkModeLocation();
  updateSummary();
  renderCalendar();
</script>
</body>
</html>