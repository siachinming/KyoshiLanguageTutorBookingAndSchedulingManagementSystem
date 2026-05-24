<?php
session_start();
include 'config.php';
$assetBase = '../assets/img';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$userID = $_SESSION['user_id'];
$successMsg = '';
$errorMsg = '';

// Get student info
$stmt = $conn->prepare("SELECT * FROM users WHERE id = ? AND role = 'student'");
$stmt->bind_param("i", $userID);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
if (!$user) { 
    header("Location: login.php"); 
    exit(); 
}

$displayName = $user['fullname'];
$profilePic  = !empty($user['profile_pic'])
    ? '../uploads/profiles/' . $user['profile_pic'] . '?v=' . time()
    : $assetBase . '/profile-student.png';

// Get preferred languages
$preferredLanguages = [];
$result = $conn->query("SELECT language FROM student_preferences WHERE user_id = $userID");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $preferredLanguages[] = $row['language'];
    }
}

// Get learning modes
$preferredModes = [];
$result = $conn->query("SELECT mode FROM student_learning_modes WHERE user_id = $userID");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $preferredModes[] = $row['mode'];
    }
}

// Get bookings for learning progress
$bookings = [];
$stmt = $conn->prepare("
    SELECT b.id, b.language, b.booking_date, b.status,
           u.fullname AS tutor_name
    FROM bookings b JOIN users u ON b.tutor_id = u.id
    WHERE b.student_id = ? ORDER BY b.booking_date DESC
");
$stmt->bind_param("i", $userID);
$stmt->execute();
$bRes = $stmt->get_result();
while ($row = $bRes->fetch_assoc()) {
    $bookings[] = $row;
}

$completedCount = count(array_filter($bookings, fn($b) => $b['status'] === 'completed'));
$totalSessions  = count($bookings);

// Languages practiced (from completed bookings)
$langStats = [];
foreach ($bookings as $b) {
    if ($b['status'] === 'completed') {
        $lang = trim($b['language']);
        $langStats[$lang] = ($langStats[$lang] ?? 0) + 1;
    }
}

// Handle POST request
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── Instant photo upload (no page reload) ──
    if ($action === 'update_pic_only') {
        header('Content-Type: application/json');
        if (!empty($_FILES['profile_pic']['name'])) {
            $ext = strtolower(pathinfo($_FILES['profile_pic']['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg','jpeg','png','webp','gif'];
            if (in_array($ext, $allowed)) {
                $upload_dir = '../uploads/profiles/';
                if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
                $filename = 'student_' . $userID . '_' . time() . '.' . $ext;
                if (move_uploaded_file($_FILES['profile_pic']['tmp_name'], $upload_dir . $filename)) {
                    $stmt = $conn->prepare("UPDATE users SET profile_pic=? WHERE id=?");
                    $stmt->bind_param("si", $filename, $userID);
                    $stmt->execute();
                    echo json_encode(['success' => true, 'filename' => $filename]);
                    exit();
                }
            }
        }
        echo json_encode(['success' => false, 'error' => 'Upload failed']);
        exit();
    }

    // ── Full profile update ──
    if ($action === 'update_profile') {
        $fullname = trim($_POST['fullname'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        $phone    = trim($_POST['phone'] ?? '');
        $newPic   = $user['profile_pic']; // keep existing by default

        // Handle profile pic upload (fallback if JS upload failed)
        if (!empty($_FILES['profile_pic']['name'])) {
            $upload_dir = '../uploads/profiles/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
            $ext = strtolower(pathinfo($_FILES['profile_pic']['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg','jpeg','png','webp','gif'];
            if (in_array($ext, $allowed)) {
                $filename = 'student_' . $userID . '_' . time() . '.' . $ext;
                if (move_uploaded_file($_FILES['profile_pic']['tmp_name'], $upload_dir . $filename)) {
                    $newPic = $filename;
                }
            } else {
                $errorMsg = 'Invalid file type. Please use JPG, PNG, or WebP.';
            }
        }

        if (!$errorMsg) {
            $stmt = $conn->prepare("UPDATE users SET fullname=?, email=?, phone=?, profile_pic=? WHERE id=?");
            $stmt->bind_param("ssssi", $fullname, $email, $phone, $newPic, $userID);
            $stmt->execute();

            $conn->query("DELETE FROM student_preferences WHERE user_id = $userID");
            $conn->query("DELETE FROM student_learning_modes WHERE user_id = $userID");

            $langs = $_POST['languages'] ?? [];
            foreach ($langs as $lang) {
                $s = $conn->prepare("INSERT INTO student_preferences (user_id, language) VALUES (?, ?)");
                $s->bind_param("is", $userID, $lang);
                $s->execute();
            }

            $modes = $_POST['modes'] ?? [];
            foreach ($modes as $mode) {
                $s = $conn->prepare("INSERT INTO student_learning_modes (user_id, mode) VALUES (?, ?)");
                $s->bind_param("is", $userID, $mode);
                $s->execute();
            }

            header("Location: student_profile.php?success=1");
            exit();
        }
    }

    if ($action === 'change_password') {
    $current = $_POST['current_password'] ?? '';
    $new     = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if (!password_verify($current, $user['password'])) {
    $errorMsg = 'Current password is incorrect.';
} elseif ($new === $current) {
    $errorMsg = 'New password must be different from your current password.';
} elseif (strlen($new) < 8) {
    $errorMsg = 'New password must be at least 8 characters.';
} elseif ($new !== $confirm) {
    $errorMsg = 'Passwords do not match.';
} else {
        $hashed = password_hash($new, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("UPDATE users SET password=? WHERE id=?");
        $stmt->bind_param("si", $hashed, $userID);
        $stmt->execute();

        // ── Send email notification via PHPMailer ──
        require_once 'send_mail.php';
        sendPasswordChangedEmail($user['email'], $user['fullname']);

        header("Location: student_profile.php?success=pw");
        exit();
    }
}

}
// Check for success parameter and reload data
if (isset($_GET['success'])) {
    if ($_GET['success'] == 1) {
        $successMsg = 'Profile updated successfully!';
        
        // Reload user data
        $stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->bind_param("i", $userID);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $displayName = $user['fullname'];
       $profilePic  = !empty($user['profile_pic']) ? '../uploads/profiles/'.$user['profile_pic'] . '?v=' . time() : $assetBase.'/profile-student.png';
        
        // Reload languages
        $preferredLanguages = [];
        $result = $conn->query("SELECT language FROM student_preferences WHERE user_id = $userID");
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $preferredLanguages[] = $row['language'];
            }
        }
        
        // Reload modes
        $preferredModes = [];
        $result = $conn->query("SELECT mode FROM student_learning_modes WHERE user_id = $userID");
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $preferredModes[] = $row['mode'];
            }
        }
    } elseif ($_GET['success'] == 'pw') {
        $successMsg = 'Password updated! A confirmation email has been sent to ' . e($user['email']) . '.';
    }
}
function e($v) { 
    return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); 
}

$allLanguages = ['Japanese', 'English', 'Mandarin', 'Korean', 'Malay'];
$allModes = ['online', 'face_to_face'];
$focusAreaOptions = ['Speaking', 'Listening', 'Reading', 'Writing'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>My Profile · Kyoshi</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
  <style>
    :root{
      --cream:#FFF1F6; --paper:rgba(255,255,255,.88); --ink:#342635; --muted:#7B6178;
      --pink:#F28AB2; --pink-dark:#C94F86; --hot-pink:#E75A9B; --purple:#A77BE8;
      --lavender:#EAD7FF; --peach:#FFD0DD; --mint:#DDF4E3; --sky:#D8ECFF;
      --shadow:0 18px 45px rgba(201,79,134,.16); --shadow-soft:0 10px 26px rgba(201,79,134,.10);
      --radius-xl:32px; --radius-lg:24px; --radius-md:18px;
    }
    *{box-sizing:border-box} html{scroll-behavior:smooth}
    body{margin:0;min-height:100vh;font-family:"Segoe UI",Arial,sans-serif;color:var(--ink);
      background:linear-gradient(120deg,rgba(255,241,246,.74),rgba(255,203,220,.30)),
        url("<?= e($assetBase) ?>/background3.jpg") center/cover fixed no-repeat;}
    body::before{content:"";position:fixed;inset:0;pointer-events:none;z-index:-1;
      background:radial-gradient(circle at 7% 10%,rgba(231,90,155,.32),transparent 24%),
        radial-gradient(circle at 90% 8%,rgba(255,195,216,.42),transparent 26%),
        radial-gradient(circle at 55% 95%,rgba(234,215,255,.30),transparent 28%);}
    a{text-decoration:none;color:inherit} button,input,textarea,select{font-family:inherit}
    .container{width:min(1440px,calc(100% - 40px));margin:0 auto}

    /* ── TOPBAR ── */
    .topbar{position:sticky;top:0;z-index:50;background:rgba(255,241,246,.86);backdrop-filter:blur(20px);border-bottom:1px solid rgba(231,90,155,.18);box-shadow:0 10px 30px rgba(201,79,134,.10);}
    .nav{min-height:78px;display:grid;grid-template-columns:190px minmax(0,1fr) 360px;gap:16px;align-items:center;}
    .brand{display:flex;align-items:center;gap:10px;min-width:0}
    .brand img{width:44px;height:44px;object-fit:contain;border-radius:14px}
    .brand strong{display:block;font-size:18px;line-height:1.05}
    .brand span{display:block;margin-top:3px;font-size:11px;color:var(--muted);white-space:nowrap}
    .nav-links{display:flex;align-items:center;justify-content:center;gap:6px;border:1px solid rgba(242,138,178,.18);border-radius:999px;padding:7px;overflow:auto;scrollbar-width:none;box-shadow:inset 0 1px 0 rgba(255,255,255,.70);}
    .nav-links::-webkit-scrollbar{display:none}
    .nav-links a{flex:0 0 auto;padding:9px 12px;border-radius:999px;font-size:13px;font-weight:900;color:#6D4964;white-space:nowrap;transition:.18s ease}
    .nav-links a.active,.nav-links a:hover{background:linear-gradient(135deg,var(--hot-pink),var(--pink));color:#fff;box-shadow:0 8px 18px rgba(231,90,155,.28)}
    .nav-actions{display:flex;align-items:center;justify-content:flex-end;gap:10px;min-width:0}
    .search{position:relative;flex:1 1 auto;min-width:0}
    .search i{position:absolute;left:14px;top:50%;transform:translateY(-50%);color:#91899F}
    .search input{width:100%;border:1px solid rgba(46,42,59,.10);background:rgba(255,255,255,.88);outline:none;border-radius:999px;padding:12px 14px 12px 38px;box-shadow:var(--shadow-soft)}
    .icon-btn,.profile-nav{border:1px solid rgba(46,42,59,.08);background:rgba(255,255,255,.88);box-shadow:var(--shadow-soft);cursor:pointer}
    .icon-btn{width:44px;height:44px;border-radius:16px;color:#7A4A68;position:relative;flex:0 0 auto;display:grid;place-items:center}
    .dot{position:absolute;top:10px;right:10px;width:8px;height:8px;border-radius:50%;background:#E17C91}
    .profile-nav{display:flex;align-items:center;gap:9px;border-radius:999px;padding:6px 12px 6px 6px;font-weight:900;color:#7A3D65;flex:0 0 auto;max-width:150px}
    .profile-nav img{width:34px;height:34px;object-fit:cover;border-radius:50%}
    .profile-nav span{max-width:86px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}

    /* ── LAYOUT ── */
    .glass{background:var(--paper);border:1px solid rgba(255,255,255,.55);box-shadow:var(--shadow)}
    .page-wrap{padding:28px 0 48px}
    .breadcrumb{display:flex;align-items:center;gap:8px;font-size:13px;color:var(--muted);margin-bottom:20px}
    .breadcrumb a{color:var(--pink-dark);font-weight:700}

    /* Tabs */
    .tabs{display:flex;gap:6px;background:rgba(255,255,255,.58);border:1px solid rgba(242,138,178,.18);border-radius:999px;padding:7px;width:max-content;margin-bottom:24px;box-shadow:inset 0 1px 0 rgba(255,255,255,.70);}
    .tab{padding:10px 18px;border-radius:999px;font-size:13px;font-weight:900;color:#6D4964;cursor:pointer;border:none;background:transparent;transition:.18s ease}
    .tab.active,.tab:hover{background:linear-gradient(135deg,var(--hot-pink),var(--pink));color:#fff;box-shadow:0 8px 18px rgba(231,90,155,.28)}

    /* Profile card */
    .profile-layout{display:grid;grid-template-columns:320px 1fr;gap:22px;align-items:start}
    .profile-sidebar{border-radius:var(--radius-xl);padding:28px;text-align:center;position:relative}
    .avatar-wrap{position:relative;width:120px;height:120px;margin:0 auto 18px}
    .avatar-wrap img{width:120px;height:120px;border-radius:50%;object-fit:cover;border:4px solid white;box-shadow:0 12px 28px rgba(201,79,134,.2)}
    .avatar-edit{position:absolute;bottom:4px;right:4px;width:34px;height:34px;border-radius:50%;background:linear-gradient(135deg,var(--hot-pink),var(--pink));border:2px solid white;color:#fff;cursor:pointer;display:grid;place-items:center;font-size:14px;box-shadow:0 4px 12px rgba(231,90,155,.3)}
    .sidebar-name{font-size:22px;font-weight:900;letter-spacing:-.5px;margin:0}
    .sidebar-role{display:inline-flex;align-items:center;gap:6px;padding:6px 14px;background:rgba(242,138,178,.18);color:var(--pink-dark);border-radius:999px;font-size:12px;font-weight:900;margin:8px 0 0}
    .sidebar-stats{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:22px;text-align:left}
    .s-stat{background:rgba(255,241,246,.7);border-radius:18px;padding:14px}
    .s-stat span{display:block;font-size:11px;color:var(--muted);font-weight:700}
    .s-stat strong{display:block;font-size:22px;font-weight:900;margin-top:4px;letter-spacing:-.5px}
    .sidebar-langs{display:flex;flex-wrap:wrap;gap:6px;justify-content:center;margin-top:18px}
    .lang-badge{padding:7px 12px;border-radius:999px;background:rgba(167,123,232,.15);color:var(--purple-dark,#7648B8);font-size:12px;font-weight:900}
    .sidebar-btns{display:flex;flex-direction:column;gap:10px;margin-top:22px}
    .btn-primary{background:linear-gradient(135deg,var(--hot-pink),var(--pink));color:#fff;padding:13px 20px;border-radius:999px;border:none;font-size:13px;font-weight:900;cursor:pointer;box-shadow:0 8px 18px rgba(231,90,155,.26);transition:.18s ease;width:100%}
    .btn-primary:hover{transform:translateY(-1px)}
    .btn-outline{background:rgba(255,255,255,.84);color:#7A3D65;padding:13px 20px;border-radius:999px;border:1px solid rgba(46,42,59,.10);font-size:13px;font-weight:900;cursor:pointer;transition:.18s ease;width:100%}
    .btn-outline:hover{transform:translateY(-1px);background:#fff}

    /* Form panels */
    .form-panel{border-radius:var(--radius-xl);padding:28px}
    .form-panel h3{margin:0 0 6px;font-size:22px;letter-spacing:-.5px}
    .form-panel .sub{color:var(--muted);font-size:14px;margin:0 0 24px}
    .form-grid{display:grid;grid-template-columns:1fr 1fr;gap:18px}
    .form-grid.full{grid-template-columns:1fr}
    .form-group{display:flex;flex-direction:column;gap:6px}
    .form-group label{font-size:13px;font-weight:900;color:#6D4964}
    .form-group input,.form-group textarea,.form-group select{border:1px solid rgba(46,42,59,.12);border-radius:14px;padding:12px 14px;font-size:14px;outline:none;color:var(--ink);background:rgba(255,255,255,.88);transition:.15s ease}
    .form-group input:focus,.form-group textarea:focus{border-color:var(--hot-pink);box-shadow:0 0 0 3px rgba(231,90,155,.12)}
    .form-group textarea{min-height:90px;resize:vertical}
    .section-divider{height:1px;background:rgba(46,42,59,.08);margin:24px 0}
    .section-label{font-size:14px;font-weight:900;color:#6D4964;margin:0 0 12px}
    .chip-group{display:flex;flex-wrap:wrap;gap:10px}
    .pref-chip{border:1px solid rgba(46,42,59,.10);background:rgba(255,255,255,.82);color:#7A5570;padding:10px 16px;border-radius:999px;font-size:13px;font-weight:900;cursor:pointer;transition:.18s ease}
    .pref-chip.active{
  background: linear-gradient(135deg,var(--hot-pink),var(--pink)) !important;
  color: #fff !important;
  border-color: var(--pink) !important;
  box-shadow: 0 8px 18px rgba(231,90,155,.22);
}
    .form-actions{
   display:flex;
  justify-content:center;
  align-items:center;
  gap:20px;
  margin-top:26px;
}

.form-actions .btn-primary,
.form-actions .btn-outline{
  width:180px;
  padding:13px 20px;
}

    /* Alert */
    .alert{padding:14px 18px;border-radius:16px;font-size:14px;font-weight:700;margin-bottom:18px}
    .alert-success{background:rgba(221,244,227,.88);color:#3D7047;border:1px solid rgba(61,112,71,.18)}
    .alert-error{background:rgba(255,220,220,.88);color:#c0392b;border:1px solid rgba(192,57,43,.18)}

    /* Progress section */
    .progress-section{border-radius:var(--radius-xl);padding:28px}
    .prog-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:24px}
    .prog-card{background:rgba(255,241,246,.7);border-radius:22px;padding:18px;text-align:center}
    .prog-card .val{font-size:36px;font-weight:900;letter-spacing:-1px;line-height:1}
    .prog-card .lbl{font-size:13px;color:var(--muted);font-weight:700;margin-top:6px}
    .lang-progress{display:flex;flex-direction:column;gap:14px}
    .lp-item{background:rgba(255,241,246,.7);border-radius:18px;padding:16px}
    .lp-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;font-weight:900}
    .track{height:12px;border-radius:999px;background:rgba(221,211,255,.5);overflow:hidden}
    .fill{height:100%;border-radius:999px;background:linear-gradient(90deg,var(--hot-pink),var(--pink),var(--peach));transition:width .5s ease}
    .session-list{display:flex;flex-direction:column;gap:12px;margin-top:22px}
    .session-item{display:flex;align-items:center;gap:14px;padding:14px;background:rgba(255,241,246,.7);border-radius:18px}
    .session-icon{width:44px;height:44px;border-radius:14px;background:rgba(242,138,178,.18);color:var(--hot-pink);display:grid;place-items:center;font-size:18px;flex:0 0 auto}
    .session-item strong{display:block;font-size:14px}
    .session-item span{display:block;margin-top:3px;font-size:12px;color:var(--muted)}
    .status-badge{margin-left:auto;padding:6px 12px;border-radius:999px;font-size:11px;font-weight:900}
    .status-completed{background:rgba(221,244,227,.88);color:#3D7047}
    .status-confirmed{background:rgba(215,232,255,.88);color:#2056a8}
    .status-pending{background:rgba(255,229,199,.88);color:#a35f3f}
    .status-cancelled{background:rgba(255,220,220,.88);color:#c0392b}
    .empty-state{padding:28px;border-radius:22px;background:rgba(255,241,246,.7);text-align:center;color:#6D647C;font-weight:700}

    /* Password panel */
    .password-strength{display:flex;gap:4px;margin-top:6px}
    .ps-bar{flex:1;height:5px;border-radius:999px;background:rgba(46,42,59,.12)}
    .ps-bar.w{background:#e74c3c}.ps-bar.m{background:#f39c12}.ps-bar.s{background:#27ae60}
    .ps-hint{font-size:12px;color:var(--muted);margin-top:4px}

    /* Toast */
    .toast{position:fixed;left:50%;bottom:28px;transform:translate(-50%,18px);opacity:0;pointer-events:none;z-index:500;background:#8E3F70;color:#fff;border-radius:999px;padding:12px 22px;font-size:13px;font-weight:900;transition:.2s ease}
    .toast.show{opacity:1;transform:translate(-50%,0)}

    @media(max-width:900px){.nav{grid-template-columns:170px minmax(0,1fr) 320px}.profile-layout{grid-template-columns:1fr}}
    @media(max-width:980px){.nav{grid-template-columns:1fr auto;min-height:auto;padding:10px 0}.nav-links{grid-column:1/-1;grid-row:2;width:100%}.search{display:none}.form-grid{grid-template-columns:1fr}.prog-grid{grid-template-columns:1fr 1fr}}
    @media(max-width:760px){.container{width:min(100% - 22px,100%)}.profile-nav span,.brand span{display:none}.prog-grid{grid-template-columns:1fr}}
    .edit-actions{
  display:none;
}

.form-panel.disabled{
  opacity:.75;
}

.form-panel.disabled input,
.form-panel.disabled textarea,
.form-panel.disabled button.pref-chip,
.form-panel.disabled label.avatar-edit{
  pointer-events:none;
}

.form-panel.disabled input,
.form-panel.disabled textarea{
  background:#f8f8f8;
  color:#888;
}

.form-panel.disabled .pref-chip {
  pointer-events: none;
  opacity: 0.7;
  cursor: default;
}

.form-panel.disabled .pref-chip.active{
  background: linear-gradient(135deg,var(--hot-pink),var(--pink));
  color:#fff;
  opacity: 0.6;
}

.form-panel:not(.disabled) .pref-chip {
  pointer-events: auto;
  cursor: pointer;
}

.profile-nav{
    display:flex;
    align-items:center;
    gap:9px;
    border-radius:999px;
    padding:6px 12px 6px 6px;
    font-weight:900;
    color:#7A3D65;
    border:1px solid rgba(46,42,59,.08);
    background:rgba(255,255,255,.88);
    cursor:pointer;
}
.profile-nav img{
    width:34px;
    height:34px;
    object-fit:cover;
    border-radius:50%;
}
.profile-nav span{
    max-width:86px;
    overflow:hidden;
    text-overflow:ellipsis;
    white-space:nowrap;
}
  </style>
</head>
<body>

<!-- ── TOPBAR ── -->
<header class="topbar">
  <div class="container">
    <nav class="nav">
      <a href="student_dashboard.php" class="brand">
        <img src="<?= e($assetBase) ?>/logo.png" alt="Kyoshi logo">
        <div><strong>Kyoshi</strong><span>Student Learning Space</span></div>
      </a>
      <div class="nav-links">
                    <a href="student_dashboard.php">Home</a>
                    <a href="find_language.php">Find Language</a>
                    <a href="booking_status.php">My Bookings</a>
                    <a href="my_payments.php">My Payments</a>
                    <a href="my_materials.php">My Materials</a>
                </div>

        <div class="nav-actions" style="display:flex;align-items:center;justify-content:flex-end;gap:10px;margin-left:auto;">
          <div style="position:relative;">
            <button class="profile-nav" onclick="toggleDropdown()" id="profileBtn">
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

<!-- ── MAIN ── -->
<main class="container">
  <div class="page-wrap">
    <div class="breadcrumb">
      <a href="student_dashboard.php">Home</a>
      <i class="bi bi-chevron-right" style="font-size:10px;"></i>
      <span>My Profile</span>
    </div>

    <!-- Tabs -->
    <div class="tabs" style="display: flex; justify-content: center; width: 100%; margin: 0 auto 24px auto;">
      <button class="tab active" onclick="switchTab('profile',this)"><i class="bi bi-person" style="margin-right:6px;"></i>Profile</button>
      <button class="tab" onclick="switchTab('password',this)"><i class="bi bi-shield-lock" style="margin-right:6px;"></i>Security</button>
    </div>

    <!-- ═══════════ TAB: PROFILE ═══════════ -->
    <div id="tab-profile">
      <div class="profile-layout">

        <!-- Sidebar -->
        <aside class="profile-sidebar glass">
          <div class="avatar-wrap">
            <img src="<?= e($profilePic) ?>" alt="Profile" id="previewImg">
            <label for="picInput" class="avatar-edit" title="Change photo">
              <i class="bi bi-camera"></i>
            </label>
          </div>
          <h2 class="sidebar-name"><?= e($displayName) ?></h2>
          <span class="sidebar-role"><i class="bi bi-mortarboard-fill"></i> Student</span>
            <br>
          <div class="sidebar-stats">
            <div class="s-stat">
              <span>Sessions</span>
              <strong><?= $totalSessions ?></strong>
            </div>
            <div class="s-stat">
              <span>Completed</span>
              <strong><?= $completedCount ?></strong>
            </div>
            <div class="s-stat">
              <span>Languages</span>
              <strong><?= count($preferredLanguages) ?: '—' ?></strong>
            </div>
            <div class="s-stat">
              <span>Member Since</span>
              <strong><?= date('Y', strtotime($user['created_at'] ?? 'now')) ?></strong>
            </div>
          </div><br><br>
          <div class="sidebar-btns">
            <button type="button" class="btn-primary" onclick="enableEditMode()">
              <i class="bi bi-pencil" style="margin-right:7px;"></i>Edit Profile
            </button><br>
            <button class="btn-outline" onclick="switchTab('password', document.querySelectorAll('.tab')[2])">
              <i class="bi bi-shield-lock" style="margin-right:7px;margin-bottom:30px;"></i>Change Password
            </button><br><br>
          </div><br>
        </aside>

        <!-- Edit form -->
        <form method="POST" enctype="multipart/form-data" id="profileForm" action="">
          <input type="hidden" name="action" value="update_profile">
          <!-- Hidden file input triggered by avatar label -->
          <input type="file" id="picInput" name="profile_pic" accept="image/*" style="display:none;" onchange="previewPhoto(this)">

          <div class="form-panel glass disabled" id="personalPanel" style="margin-bottom:20px;">
            <h3>Personal Information</h3>
            <p class="sub">Update your name and details.</p>

            <div class="form-grid">
              <div class="form-group">
                <label><i class="bi bi-person" style="color:var(--hot-pink);margin-right:5px;"></i>Full Name</label>
                <input type="text" name="fullname" disabled value="<?= e($user['fullname']) ?>" placeholder="Your full name" required>
              </div>
              <div class="form-group">
                <label><i class="bi bi-envelope" style="color:var(--hot-pink);margin-right:5px;"></i>Email Address</label>
                <input type="email" name="email" disabled value="<?= e($user['email']) ?>" placeholder="your@email.com" required>
              </div>
              <div class="form-group">
                <label><i class="bi bi-phone" style="color:var(--hot-pink);margin-right:5px;"></i>Contact Number</label>
                <input type="tel" name="phone" disabled value="<?= e($user['phone'] ?? '') ?>" placeholder="">
              </div>
            </div>
          </div>

          <div class="form-panel glass disabled" id="prefPanel" style="margin-bottom:20px;">
            <h3>Learning Preferences</h3>
            <p class="sub">Select the languages you want to learn and how you prefer to study.</p><br>

            <p class="section-label"><i class="bi bi-globe2" style="color:var(--hot-pink);margin-right:6px;"></i>Languages I want to learn</p>
<div class="chip-group" id="langChips">
  <?php foreach ($allLanguages as $lang): ?>
    <button type="button" class="pref-chip <?= in_array($lang, $preferredLanguages) ? 'active' : '' ?>"
      onclick="togglePrefChip(this,'langHidden','<?= e($lang) ?>')">
      <?= e($lang) ?>
    </button>
  <?php endforeach; ?>
</div>
<div id="langHidden"></div>
  <?php foreach ($preferredLanguages as $lang): ?>
    <input type="hidden" name="languages[]" value="<?= e($lang) ?>" data-val="<?= e($lang) ?>">
  <?php endforeach; ?>

            <div class="section-divider"></div>

            <p class="section-label"><i class="bi bi-laptop" style="color:var(--hot-pink);margin-right:6px;"></i>Preferred Learning Mode</p>
            <div class="chip-group" id="modeChips">
              <button type="button" class="pref-chip <?= in_array('online',$preferredModes)?'active':'' ?>"
                onclick="togglePrefChip(this,'modeHidden','online')">💻 Online</button>
              <button type="button" class="pref-chip <?= in_array('face_to_face',$preferredModes)?'active':'' ?>"
                onclick="togglePrefChip(this,'modeHidden','face_to_face')">🤝 Face to Face</button>
            </div>
            <div id="modeHidden"></div>
              <?php foreach ($preferredModes as $mode): ?>
                <input type="hidden" name="modes[]" value="<?= e($mode) ?>" data-val="<?= e($mode) ?>">
              <?php endforeach; ?>
            

            <div class="section-divider"></div>

          </div>

          <div class="form-actions edit-actions" id="editActions">
            <button type="submit" class="btn-primary"><i class="bi bi-check2" style="margin-right:7px;"></i>Save Changes</button>
           <button type="button" class="btn-outline" onclick="discardChanges()"><i class="bi bi-x-lg" style="margin-right:7px;"></i>Discard </button>
          </div>
        </form>
      </div>
    </div>
    <!-- ═══════════ TAB: SECURITY ═══════════ -->
    <div id="tab-password" style="display:none;">
       <div class="form-panel glass" style="max-width: 100%;">
 <form method="POST">
          <input type="hidden" name="action" value="change_password">
          <h3>Change Password</h3>
          <p class="sub">Choose a strong password to protect your account.</p>

          <div class="form-group" style="margin-bottom:16px;">
            <label><i class="bi bi-lock" style="color:var(--hot-pink);margin-right:5px;"></i>Current Password</label>
            <div style="position:relative;">
              <input type="password" name="current_password" id="curPwd" placeholder="Enter current password" required style="width:100%;padding-right:44px;">
              <button type="button" onclick="togglePwd('curPwd','eyeCur')" style="position:absolute;right:12px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--muted);">
                <i class="bi bi-eye" id="eyeCur"></i>
              </button>
            </div>
          </div>

          <div class="form-group" style="margin-bottom:6px;">
            <label><i class="bi bi-lock-fill" style="color:var(--hot-pink);margin-right:5px;"></i>New Password</label>
            <div style="position:relative;">
              <input type="password" name="new_password" id="newPwd" placeholder="At least 8 characters" required oninput="checkStrength(this.value)" style="width:100%;padding-right:44px;">
              <button type="button" onclick="togglePwd('newPwd','eyeNew')" style="position:absolute;right:12px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--muted);">
                <i class="bi bi-eye" id="eyeNew"></i>
              </button>
            </div>
            <div class="password-strength" id="strengthBars">
              <div class="ps-bar" id="bar1"></div>
              <div class="ps-bar" id="bar2"></div>
              <div class="ps-bar" id="bar3"></div>
            </div>
            <span class="ps-hint" id="strengthHint">Enter a password</span>
          </div>

          <div class="form-group" style="margin-bottom:24px;">
            <label><i class="bi bi-lock-fill" style="color:var(--hot-pink);margin-right:5px;"></i>Confirm New Password</label>
            <div style="position:relative;">
              <input type="password" name="confirm_password" id="conPwd" placeholder="Repeat new password" required style="width:100%;padding-right:44px;">
              <button type="button" onclick="togglePwd('conPwd','eyeCon')" style="position:absolute;right:12px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--muted);">
                <i class="bi bi-eye" id="eyeCon"></i>
              </button>
            </div>
          </div>

          <div style="background:rgba(255,241,246,.7);border-radius:16px;padding:16px;margin-bottom:22px;">
            <p style="margin:0 0 8px;font-size:13px;font-weight:900;color:#6D4964;"><i class="bi bi-info-circle" style="margin-right:5px;"></i>Password Tips</p>
            <ul style="margin:0;padding-left:16px;font-size:13px;color:var(--muted);line-height:1.7;">
              <li>Use at least 8 characters</li>
              <li>Mix uppercase, lowercase, and numbers</li>
              <li>Add special characters (!, @, #…)</li>
              <li>Avoid easily guessed phrases</li>
            </ul>
          </div>

          <button type="submit" class="btn-primary" style="width:100%;">
            <i class="bi bi-shield-check" style="margin-right:7px;"></i>Update Password
          </button>
        </form>
      </div>
    </div>

  </div>
</main>

<div class="toast" id="toast"></div>

<script>
  // ── Tab switching
  function switchTab(name, btn) {
    ['profile','password'].forEach(t => {
      const tab = document.getElementById('tab-'+t);
      if (tab) tab.style.display = 'none';
    });
    document.getElementById('tab-'+name).style.display = 'block';
    document.querySelectorAll('.tab').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    window.scrollTo({top:0,behavior:'smooth'});
  }

function togglePrefChip(btn, containerId, value) {
  const container = document.getElementById(containerId);
  const name = containerId === 'langHidden' ? 'languages[]' : 'modes[]';

  const isActive = btn.classList.contains('active');

  if (isActive) {
    btn.classList.remove('active');

    container.querySelectorAll(`input[value="${value}"]`).forEach(el => el.remove());

  } else {
    btn.classList.add('active');

    const inp = document.createElement('input');
    inp.type = 'hidden';
    inp.name = name;
    inp.value = value;

    container.appendChild(inp);
  }
}

  // ── Photo preview
  function previewPhoto(input) {
    if (!input.files || !input.files[0]) return;

    // Show preview instantly
    const reader = new FileReader();
    reader.onload = e => document.getElementById('previewImg').src = e.target.result;
    reader.readAsDataURL(input.files[0]);

    // Upload immediately via fetch
    const formData = new FormData();
    formData.append('profile_pic', input.files[0]);
    formData.append('action', 'update_pic_only');

    fetch('student_profile.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.success) showToast('Profile photo updated!');
            else showToast('Upload failed: ' + data.error);
        });
}

  // ── Password visibility toggle
  function togglePwd(inputId, iconId) {
    const inp = document.getElementById(inputId);
    const icon = document.getElementById(iconId);
    if (inp.type === 'password') {
      inp.type = 'text';
      icon.className = 'bi bi-eye-slash';
    } else {
      inp.type = 'password';
      icon.className = 'bi bi-eye';
    }
  }

  // ── Password strength
  function checkStrength(val) {
    const b1 = document.getElementById('bar1');
    const b2 = document.getElementById('bar2');
    const b3 = document.getElementById('bar3');
    const hint = document.getElementById('strengthHint');
    let score = 0;
    if (val.length >= 8) score++;
    if (/[A-Z]/.test(val) && /[0-9]/.test(val)) score++;
    if (/[!@#$%^&*]/.test(val)) score++;
    [b1,b2,b3].forEach(b => b.className='ps-bar');
    if (score === 1) { b1.classList.add('w'); hint.textContent='Weak – try adding numbers'; }
    else if (score === 2) { b1.classList.add('m'); b2.classList.add('m'); hint.textContent='Medium – add special characters'; }
    else if (score === 3) { b1.classList.add('s'); b2.classList.add('s'); b3.classList.add('s'); hint.textContent='Strong password ✓'; }
    else { hint.textContent='Too short'; }
  }

  // ── Toast
  function showToast(msg) {
    const t = document.getElementById('toast');
    t.textContent = msg; 
    t.classList.add('show');
    setTimeout(() => t.classList.remove('show'), 3000);
  }

  // ── Dropdown
  function toggleDropdown() {
    const d = document.getElementById('profileDropdown');
    d.style.display = d.style.display === 'none' ? 'block' : 'none';
  }
  
  document.addEventListener('click', e => {
    const btn = document.getElementById('profileBtn');
    const dd = document.getElementById('profileDropdown');
    if (btn && dd && !btn.contains(e.target) && !dd.contains(e.target)) {
      dd.style.display = 'none';
    }
  });

  function enableEditMode() {
    document.getElementById('personalPanel').classList.remove('disabled');
    document.getElementById('prefPanel').classList.remove('disabled');
    document.getElementById('editActions').style.display = 'flex';

    document.querySelectorAll('#profileForm input').forEach(el => {
        el.disabled = false;
    });

    // Ensure file input and camera button always work
    document.getElementById('picInput').disabled = false;
    document.querySelector('.avatar-edit').style.pointerEvents = 'auto';

    document.querySelectorAll('.pref-chip').forEach(chip => {
        chip.style.pointerEvents = 'auto';
        chip.style.opacity = '1';
    });

    showToast('Edit mode enabled — you can now change your details');
}

  function discardChanges() {
    if (confirm('Discard all changes? Your unsaved changes will be lost.')) {
      location.reload();
    }
  }

  // ── Notifications
  let notifOpen = false;
  
  function toggleNotifications() {
    notifOpen = !notifOpen;
    const dd = document.getElementById('notifDropdown');
    dd.style.display = notifOpen ? 'block' : 'none';
    if (notifOpen) loadNotifications();
  }
  
  function loadNotifications() {
    fetch('get_notifications.php').then(r=>r.json()).then(data=>{
      const dot=document.getElementById('notifDot');
      const list=document.getElementById('notifList');
      dot.style.display = data.count>0 ? 'block':'none';
      if (!data.notifications.length) { 
        list.innerHTML='<div style="padding:20px;text-align:center;color:#9080a0;font-size:13px;">No notifications yet.</div>'; 
        return; 
      }
      list.innerHTML = data.notifications.map(n=>`
        <div onclick="markRead(${n.id},this)" style="padding:14px 16px;border-bottom:1px solid rgba(242,138,178,.08);cursor:pointer;background:${n.is_read?'white':'rgba(255,241,246,.6)'};">
          <div style="display:flex;align-items:flex-start;gap:10px;">
            <div style="width:8px;height:8px;border-radius:50%;background:${n.is_read?'transparent':'#E75A9B'};flex-shrink:0;margin-top:5px;"></div>
            <div><strong style="display:block;font-size:13px;">${n.title}</strong>
            <p style="margin:3px 0 0;font-size:12px;color:#7B6178;">${n.message}</p>
            <span style="display:block;margin-top:4px;font-size:11px;color:#aaa;">${timeAgo(n.created_at)}</span></div>
          </div>
        </div>`).join('');
    });
  }
  
  function markRead(id,el){
    fetch('mark_notification_read.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id})});
    loadNotifications();
  }
  
  function markAllRead(){
    fetch('mark_notification_read.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id:0})}).then(()=>loadNotifications());
  }
  
  function timeAgo(d){
    const diff=Math.floor((new Date()-new Date(d))/1000);
    if(diff<60) return 'Just now';
    if(diff<3600) return Math.floor(diff/60)+'m ago';
    if(diff<86400) return Math.floor(diff/3600)+'h ago';
    return Math.floor(diff/86400)+'d ago';
  }
  
  function checkUnread(){
    fetch('get_notifications.php').then(r=>r.json()).then(data=>{
      const dot=document.getElementById('notifDot');
      if(dot) dot.style.display=data.count>0?'block':'none';
    });
  }
  
  checkUnread(); 
  setInterval(checkUnread,60000);
  
  document.addEventListener('click', e=>{
    const bell=document.getElementById('bellBtn');
    const dd=document.getElementById('notifDropdown');
    if(bell&&dd&&!bell.contains(e.target)&&!dd.contains(e.target)){
      dd.style.display='none';
      notifOpen=false;
    }
  });

  // ── Open correct tab on load
  if (window.location.hash === '#security') switchTab('password', document.querySelectorAll('.tab')[2]);
  if (window.location.hash === '#progress') switchTab('progress', document.querySelectorAll('.tab')[1]);
  
  // ── Initialize - disable chips initially
  document.querySelectorAll('.pref-chip').forEach(chip => {
    chip.style.pointerEvents = 'none';
    chip.style.opacity = '0.7';
  });


  <?php if ($successMsg): ?>
  showToast("<?= e($successMsg) ?>");
  <?php endif; ?>

  <?php if ($errorMsg): ?>
showToast("<?= addslashes(htmlspecialchars_decode(e($errorMsg))) ?>");
<?php endif; ?>
</script>
</body>
</html>