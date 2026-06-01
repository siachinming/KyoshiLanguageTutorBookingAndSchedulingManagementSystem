<?php
session_start();
include 'config.php';
$assetBase = '../assets/img';

if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit(); }
$userID = $_SESSION['user_id'];

$bookingID = intval($_GET['id'] ?? 0);
$nextID = intval($_GET['next'] ?? 0);
if (!$bookingID) { header("Location: booking_status.php"); exit(); }

$stmt = $conn->prepare("
    SELECT b.*, u.fullname AS tutor_name, u.profile_pic AS tutor_pic, u.email AS tutor_email,
           tp.rate, tp.bio, tp.experience,
           GROUP_CONCAT(DISTINCT tl.language) AS tutor_languages,
           GROUP_CONCAT(DISTINCT ttm.mode) AS teaching_modes,
           ul.location,
           b.proficiency_level,
           b.meeting_link,
           b.meeting_location
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
$levelLabels = [
    'beginner' => 'Beginner',
    'intermediate' => 'Intermediate', 
    'advanced' => 'Advanced',
    'master' => 'Master'
];
$currentLevel = $b['proficiency_level'] ?? 'beginner';
$currentLevelLabel = $levelLabels[$currentLevel] ?? ucfirst($currentLevel);
$meetingLink = $b['meeting_link'] ?? '';
$meetingLocation = $b['meeting_location'] ?? '';
$hasMeetingLink = !empty($meetingLink);
$hasMeetingLocation = !empty($meetingLocation);
$isOnline = $b['learning_mode'] === 'online';
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

// Get already booked slots for this tutor (prevent overbooking)
$stmt = $conn->prepare("
    SELECT booking_date, booking_time 
    FROM bookings 
    WHERE tutor_id = ? 
    AND status IN ('pending','accepted','confirmed','rescheduled')
    AND id != ?
");
$stmt->bind_param("ii", $b['tutor_id'], $bookingID);
$stmt->execute();
$bookedResult = $stmt->get_result();
$bookedSlots = [];
while ($row = $bookedResult->fetch_assoc()) {
    $d = $row['booking_date'];
    $t = $row['booking_time'];
    if (!isset($bookedSlots[$d])) $bookedSlots[$d] = [];
    $bookedSlots[$d][] = $t;
}
$stmt->close();
$bookedSlotsJson = json_encode($bookedSlots);

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
    /* Modal Styles - Make sure this exists */
    .modal {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.5);
        z-index: 1000;
        align-items: center;
        justify-content: center;
    }

    .modal.active {
        display: flex;
    }

    .modal-content {
        background: white;
        border-radius: 24px;
        width: 450px;
        max-width: 90%;
        padding: 28px;
        position: relative;
        max-height: 80vh;
        overflow-y: auto;
    }
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
        <a href="find_language.php">Find Language</a>
        <a href="booking_status.php" class="active">My Bookings</a>
        <a href="my_payments.php">My Payments</a>
        <a href="my_materials.php">My Materials</a>
        <a href="my_assignments.php">My Assignments</a>
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
      <button onclick="showRescheduleRulesModal()" style="background: none; border: none; color: #f59e0b; font-size: 13px; cursor: pointer; margin-left: 10px;">
    <i class="bi bi-question-circle"></i> Rules
</button>
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

      <!-- STEP 1 - Simplified for reschedule only -->
<div class="form-section active" id="step1">

    <!-- Language -->
    <div class="form-group">
        <label><i class="bi bi-translate"></i> Language</label>
        <div class="form-control" style="background:#f5f5f5; cursor: not-allowed;">
            <?= e($b['language']) ?>
        </div>
        <input type="hidden" id="selectedLang" value="<?= e($b['language']) ?>">
    </div>

    <!-- Proficiency Level -->
    <div class="form-group">
        <label><i class="bi bi-bar-chart-steps"></i> Proficiency Level</label>
        <div class="form-control" style="background:#f5f5f5; cursor: not-allowed;">
            <?= e($currentLevelLabel) ?>
        </div>
        <input type="hidden" id="selectedLevel" value="<?= e($currentLevel) ?>">
    </div>

    <!-- Learning Mode -->
    <div class="form-group">
        <label><i class="bi bi-laptop"></i> Learning mode</label>
        <div class="form-control" style="background:#f5f5f5; cursor: not-allowed;">
            <?= $b['learning_mode'] === 'online' ? 'Online' : 'Face to Face' ?>
        </div>
        <input type="hidden" id="selectedMode" value="<?= e($b['learning_mode']) ?>">
    </div>

    <!-- Meeting Info -->
    <div class="form-group">
        <label><i class="bi bi-<?= $isOnline ? 'camera-video' : 'geo-alt' ?>"></i> 
            <?= $isOnline ? 'Meeting Link' : 'Meeting Location' ?>
        </label>
        <div class="form-control" style="background:#f5f5f5; <?= $isOnline && !$hasMeetingLink ? 'color:#dc2626;' : '' ?>">
            <?php if ($isOnline): ?>
                <?php if ($hasMeetingLink): ?>
                    <a href="<?= e($meetingLink) ?>" target="_blank" style="color:#E75A9B; text-decoration:underline;">
                        <?= e($meetingLink) ?>
                    </a>
                    <i class="bi bi-box-arrow-up-right" style="margin-left:5px;"></i>
                <?php else: ?>
                    <span style="display:flex; align-items:center; gap:8px;">
                        <i class="bi bi-exclamation-triangle-fill" style="color:#dc2626;"></i>
                        No meeting link provided yet. Tutor will share before session.
                    </span>
                <?php endif; ?>
            <?php else: ?>
                <?php if ($hasMeetingLocation): ?>
                    <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                        <span><?= nl2br(e($meetingLocation)) ?></span>
                        <a href="https://www.google.com/maps/search/?api=1&query=<?= urlencode($meetingLocation) ?>" 
                           target="_blank" style="color:#E75A9B; text-decoration:underline; font-size:12px;">
                            <i class="bi bi-map"></i> View Map
                        </a>
                    </div>
                <?php else: ?>
                    <span style="display:flex; align-items:center; gap:8px;">
                        <i class="bi bi-exclamation-triangle-fill" style="color:#f59e0b;"></i>
                        Meeting location not set. Please contact tutor for venue details.
                    </span>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Focus area -->
    <div class="form-group">
        <label><i class="bi bi-bullseye"></i> Focus area</label>
        <div class="form-control" style="background:#f5f5f5; cursor: not-allowed;">
            <?= e($b['focus'] ?? '—') ?>
        </div>
        <input type="hidden" id="selectedFocus" value="<?= e($b['focus']) ?>">
    </div>

    <!-- Reminder Note -->
    <div style="margin-top: 16px; margin-bottom: 16px; padding: 12px 16px; border-radius: 14px; background: rgba(231,90,155,.08); border: 1px solid rgba(231,90,155,.15);">
        <i class="bi bi-info-circle-fill" style="color: #E75A9B; margin-right: 8px;"></i>
        <span style="font-size: 12px; color: #342635;">
            <?php if ($isOnline): ?>
                The meeting link will remain the same after rescheduling. Contact tutor if you need a new link.
            <?php else: ?>
                The meeting location will remain the same after rescheduling. Confirm with tutor if needed.
            <?php endif; ?>
        </span>
    </div>

    <!-- Notes for tutor -->
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
<!-- RIGHT SUMMARY -->
<div style="display:flex;flex-direction:column;gap:18px;">

    <!-- CURRENT BOOKING -->
    <div style="
        background:var(--paper);
        border:1px solid rgba(255,255,255,.55);
        box-shadow:var(--shadow);
        border-radius:var(--radius-xl);
        padding:24px;
    ">
        <h3 style="margin:0 0 18px;font-size:18px;">
            <i class="bi bi-clock-history"></i>
            Recent Booking Details
        </h3>

        <div class="summary-row">
            <span class="summary-label">Date</span>
            <span class="summary-val">
                <?= e(date('d M Y', strtotime($b['booking_date']))) ?>
            </span>
        </div>

        <div class="summary-row">
            <span class="summary-label">Time</span>
            <span class="summary-val">
                <?= e(date('g:i A', strtotime($b['booking_time']))) ?>
            </span>
        </div>

        <div class="summary-row">
            <span class="summary-label">Language</span>
            <span class="summary-val"><?= e($b['language']) ?></span>
        </div>

        <div class="summary-row">
            <span class="summary-label">Mode</span>
            <span class="summary-val">
                <?= $b['learning_mode']=='online' ? 'Online' : 'Face to Face' ?>
            </span>
        </div>
    </div>

    <!-- ONLY THIS ONE STICKY -->
    <div class="summary-card">
        <h3 style="margin:0 0 18px;font-size:18px;">
            <i class="bi bi-arrow-repeat"></i>
            Reschedule Summary
        </h3>

        <div class="summary-row">
            <span class="summary-label">New Date</span>
            <span class="summary-val" id="sum-date">Not selected</span>
        </div>

        <div class="summary-row">
            <span class="summary-label">New Time</span>
            <span class="summary-val" id="sum-time">Not selected</span>
        </div>

        <div class="summary-row">
            <span class="summary-label">Language</span>
            <span class="summary-val" id="sum-lang"><?= e($b['language']) ?></span>
        </div>

        <div class="summary-row">
            <span class="summary-label">Mode</span>
            <span class="summary-val" id="sum-mode">
                <?= $b['learning_mode']=='online' ? 'Online' : 'Face to Face' ?>
            </span>
        </div>

    </div>

</div>
</div>
<!-- Reschedule Rules Modal -->
<div id="rescheduleRulesModal" class="modal">
    <div class="modal-content" style="max-width: 500px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
            <h3 style="margin: 0; font-size: 18px;"><i class="bi bi-info-circle" style="color: #f59e0b;"></i> Reschedule Rules</h3>
            <button type="button" onclick="closeRescheduleRulesModal()" style="background: none; border: none; font-size: 24px; cursor: pointer; color: #94a3b8; line-height: 1;">&times;</button>
        </div>
        <div style="margin: 16px 0; padding: 12px; background: #fef3c7; border-radius: 12px;">
            <ul style="margin: 0; padding-left: 20px; color: #92400e; font-size: 13px; line-height: 1.8;">
                <li>You can only reschedule confirmed bookings after payment</li>
                <li>You cannot reschedule a class that has already passed</li>
                <li>You cannot reschedule to a past date or time</li>
                <li>You cannot reschedule to the same date and time as your current booking</li>
                <li>You cannot request a time slot that is already booked by another student</li>
                <li>You can only have ONE pending reschedule request per booking</li>
                <li>Your original booking remains active until the tutor approves</li>
                <li>Reschedule requests expire at 12 AM on the original booking date</li>
            </ul>
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
  const bookedSlots = <?= $bookedSlotsJson ?>;
  let currentStep = 1;
  let calYear, calMonth;
  const now = new Date();
now.setHours(0, 0, 0, 0);  
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
  function isPast(d) {
    // Don't allow today or past dates
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    return d <= today;
}
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
  
// Check if student already has another booking at this date/time with ANY tutor
async function checkStudentOwnSchedule(date, time) {
    try {
        const response = await fetch('check_student_schedule.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ 
                date: date, 
                time: time,
                exclude_booking_id: bookingID
            })
        });
        
        const data = await response.json();
        
        if (data.hasConflict) {
            showToast(`You already have a session with ${data.tutor_name} at ${time.substring(0,5)} on this date. Please choose a different time.`);
            return true;
        }
        return false;
    } catch (error) {
        console.error('Error checking schedule:', error);
        showToast('Unable to check schedule. Please try again.');
        return false;
    }
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

    // Check if already booked by another student
    const isBooked = bookedSlots[selectedDate] && 
                     bookedSlots[selectedDate].includes(timeVal);

    const btn = document.createElement('button');

    if (isBooked) {
        btn.className = 'time-slot';
        btn.textContent = fmt(h,0) + ' – ' + fmt(h+1,0) + ' · Booked';
        btn.disabled = true;
        btn.style.cssText = 'opacity:.4;cursor:not-allowed;text-decoration:line-through;background:#f5f5f5;';
    } else {
        btn.className = 'time-slot' + (selectedTime === timeVal ? ' active' : '');
        btn.textContent = fmt(h,0) + ' – ' + fmt(h+1,0);
        
        // Add loading state and check student's own schedule
        btn.onclick = async () => {
            // Show loading state
            const originalText = btn.textContent;
            btn.textContent = '⏳ Checking...';
            btn.disabled = true;
            btn.style.opacity = '0.7';
            
            // Check if student has another booking at same time with different tutor
            const hasConflict = await checkStudentOwnSchedule(selectedDate, timeVal);
            
            if (!hasConflict) {
                selectedTime = timeVal;
                renderDayPanels();
                updateSummary();
                showToast('Time slot selected!');
            }
            
            // Reset button (will be recreated by renderDayPanels)
        };
    }

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

// When selecting a time slot, add this check
function selectTimeSlot(timeVal) {
    // First check if slot is booked by other student (already in bookedSlots)
    const isBooked = bookedSlots[selectedDate] && bookedSlots[selectedDate].includes(timeVal);
    if (isBooked) {
        showToast('This time slot is already booked by another student.');
        return;
    }
    
    // Then check if student has another booking at same time with different tutor
    checkStudentOwnSchedule(selectedDate, timeVal).then(hasConflict => {
        if (!hasConflict) {
            selectedTime = timeVal;
            renderDayPanels();  // Re-render to show selected
            updateSummary();
            showToast('Time slot selected!');
        }
    });
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
    ? (modeUserSelected === 'online' ? 'Online' : 'Face to Face')
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
    const lang = document.getElementById('selectedLang').value;
    const mode = document.getElementById('selectedMode').value;
    const notes = document.getElementById('bookingNotes').value;
    const focus = document.getElementById('selectedFocus')?.value || '—';
    const level = document.getElementById('selectedLevel')?.value || 'beginner';
    const levelLabels = {
        'beginner': 'Beginner',
        'intermediate': 'Intermediate',
        'advanced': 'Advanced',
        'master': 'Master'
    };
    const levelLabel = levelLabels[level] || level;
    
    document.getElementById('rev-lang').textContent = lang || '—';
    document.getElementById('rev-mode').textContent = mode === 'online' ? 'Online' : 'Face to Face';
    document.getElementById('rev-focus').textContent = focus;
    document.getElementById('rev-date').textContent = selectedDate || '—';
    document.getElementById('rev-time').textContent = selectedTime ? selectedTime.substring(0,5) : '—';
    
    if(notes){
        document.getElementById('rev-notes-row').style.display = 'flex';
        document.getElementById('rev-notes').textContent = notes;
    }
}

function goStep(n){
    if(n===3){
        if(!selectedDate || !selectedTime){
            showToast('Please select date and time');
            return;
        }
        
        // Show loading indicator
        const btn = document.querySelector('#step3 .btn-next');
        const originalText = btn.textContent;
        btn.textContent = '⏳ Checking availability...';
        btn.disabled = true;
        
        // Double-check both conflicts before proceeding
        const isBooked = bookedSlots[selectedDate] && bookedSlots[selectedDate].includes(selectedTime);
        
        if (isBooked) {
            showToast('❌ Sorry, this time slot was just booked by another student. Please choose another time.');
            btn.textContent = originalText;
            btn.disabled = false;
            goStep(2);
            return;
        }
        
        // Check student's own schedule
        checkStudentOwnSchedule(selectedDate, selectedTime).then(hasConflict => {
            btn.textContent = originalText;
            btn.disabled = false;
            
            if (hasConflict) {
                goStep(2);
            } else {
                populateReview();
                currentStep = n;
                document.querySelectorAll('.form-section').forEach((s,i)=>s.classList.toggle('active',i+1===n));
                [1,2,3].forEach(i=>{
                    const dot=document.getElementById('dot'+i);
                    dot.className='step-dot '+(i<n?'done':i===n?'active':'inactive');
                    dot.textContent=i<n?'✓':i;
                });
                window.scrollTo({top:0,behavior:'smooth'});
            }
        });
        return;
    }
    
    currentStep=n;
    document.querySelectorAll('.form-section').forEach((s,i)=>s.classList.toggle('active',i+1===n));
    [1,2,3].forEach(i=>{
        const dot=document.getElementById('dot'+i);
        dot.className='step-dot '+(i<n?'done':i===n?'active':'inactive');
        dot.textContent=i<n?'✓':i;
    });
    window.scrollTo({top:0,behavior:'smooth'});
}

  function submitReschedule() {
    const lang = document.getElementById('selectedLang').value;
    const mode = document.getElementById('selectedMode').value;
    const level = document.getElementById('selectedLevel')?.value || 'beginner';
    const focus = document.getElementById('selectedFocus')?.value || '';
    const notes = document.getElementById('bookingNotes').value;
    
    if (!selectedDate || !selectedTime) {
        showToast('Please select a date and time');
        return;
    }
    
    // Get next IDs from URL - THIS IS CRITICAL
    const urlParams = new URLSearchParams(window.location.search);
    let nextIds = urlParams.get('next') || '';
    
    // DEBUG - Check console
    console.log('Next IDs to pass:', nextIds);
    
    const btn = document.getElementById('submitBtn');
    btn.disabled = true;
    btn.textContent = 'Submitting...';
    
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = 'submit_reschedule.php';
    
    const fields = {
        booking_id: bookingID,
        language: lang,
        mode: mode,
        proficiency_level: level,
        focus: focus,
        notes: notes,
        next_ids: nextIds  // ← THIS MUST BE EXACTLY 'next_ids'
    };
    
    Object.entries(fields).forEach(([key, val]) => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = key;
        input.value = val;
        form.appendChild(input);
    });
    
    // Add date and time
    const d = document.createElement('input');
    d.type = 'hidden';
    d.name = 'booking_date[]';
    d.value = selectedDate;
    form.appendChild(d);
    
    const t = document.createElement('input');
    t.type = 'hidden';
    t.name = 'booking_time[]';
    t.value = selectedTime;
    form.appendChild(t);
    
    document.body.appendChild(form);
    form.submit();
}

  function selectSingle(el,groupId,hiddenId){
    document.querySelectorAll('#'+groupId+' .sel-chip').forEach(c=>c.classList.remove('active'));
    el.classList.add('active');
    document.getElementById(hiddenId).value=el.dataset.val;
    if(hiddenId==='selectedLang')langUserSelected=el.dataset.val;
    if(hiddenId==='selectedMode')modeUserSelected=el.dataset.val;
    updateSummary();
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

  function showRescheduleRulesModal() {
    document.getElementById('rescheduleRulesModal').classList.add('active');
}

function closeRescheduleRulesModal() {
    document.getElementById('rescheduleRulesModal').classList.remove('active');
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
  
  updateSummary();
  renderCalendar();

  window.onclick = function(event) {
    const modal = document.getElementById('rejectModal');
    if (event.target === modal) {
        closeRejectModal();
    }
    const rulesModal = document.getElementById('rescheduleRulesModal');
    if (event.target === rulesModal) {
        closeRescheduleRulesModal();
    }
}

</script>
<script>
    console.log('=== RESCHEDULE PAGE LOADED ===');
    console.log('Current URL:', window.location.href);
    
    // Get next parameter from URL
    const urlParams = new URLSearchParams(window.location.search);
    const nextParam = urlParams.get('next');
    console.log('Next parameter value:', nextParam);
    
    // Test if the submitReschedule function exists
    if (typeof submitReschedule === 'function') {
        console.log('submitReschedule function exists');
    } else {
        console.log('submitReschedule function NOT found!');
    }
</script>
</body>
</html>