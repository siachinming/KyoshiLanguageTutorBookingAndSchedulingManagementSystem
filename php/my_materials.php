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
if (!empty($user['profile_pic']) && file_exists('../uploads/profiles/' . $user['profile_pic'])) {
    $profilePic = '../uploads/profiles/' . $user['profile_pic'];
} else {
    $profilePic = $assetBase . '/profile.png';
}
// Get all learning materials with tutor info
$allMaterials = [];
$result = $conn->query("
    SELECT 
        lm.*,
        u.fullname AS tutor_name,
        b.language,
        b.booking_date,
        b.booking_time,
        b.focus,
        b.learning_mode
    FROM learning_materials lm
    LEFT JOIN users u ON lm.tutor_id = u.id
    LEFT JOIN bookings b ON lm.booking_id = b.id
    WHERE b.student_id = $userID OR b.student_id IS NULL
    ORDER BY lm.uploaded_at DESC
");

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $lang = $row['language'] ?? 'General';
        if (!isset($allMaterials[$lang])) {
            $allMaterials[$lang] = [];
        }
        $allMaterials[$lang][] = $row;
    }
}

// Get all unique languages for filter
$allLanguages = array_keys($allMaterials);

// Get confirmed ONLINE bookings for active classrooms
$stmt = $conn->prepare("
    SELECT DISTINCT 
        b.id as booking_id,
        b.language,
        b.booking_date,
        b.booking_time,
        b.status,
        b.learning_mode,
        b.focus,
        b.meeting_link,
        u.fullname as tutor_name,
        u.id as tutor_id
    FROM bookings b
    JOIN users u ON b.tutor_id = u.id
    WHERE b.student_id = ? 
        AND b.status IN ('confirmed')
        AND b.booking_date >= CURDATE()
        AND b.learning_mode = 'online'
    ORDER BY b.booking_date ASC
");
$stmt->bind_param("i", $userID);
$stmt->execute();
$activeClassrooms = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get filter parameters
$filterStatus = $_GET['status'] ?? 'all';
$filterLanguage = $_GET['language'] ?? 'all';
$filterMaterialType = $_GET['material_type_filter'] ?? 'all';
$filterFrom = $_GET['date_from'] ?? '';
$filterTo = $_GET['date_to'] ?? '';
$filterSort = $_GET['sort'] ?? 'newest';
$filterSearch = trim($_GET['search'] ?? '');

$hasActiveFilters = ($filterStatus !== 'all') || ($filterLanguage !== 'all') || ($filterMaterialType !== 'all') || !empty($filterFrom) || !empty($filterTo) || !empty($filterSearch);

// Helper function to get material type from file
function getMaterialFileType($fileName, $isUrl) {
    if ($isUrl) return 'url';
    if (!$fileName) return 'other';
    $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    $typeMap = [
        'pdf' => 'pdf',
        'doc' => 'word', 'docx' => 'word',
        'ppt' => 'powerpoint', 'pptx' => 'powerpoint',
        'jpg' => 'image', 'jpeg' => 'image', 'png' => 'image', 'gif' => 'image', 'webp' => 'image',
        'mp4' => 'video', 'mov' => 'video', 'avi' => 'video', 'mkv' => 'video',
        'mp3' => 'audio', 'wav' => 'audio', 'ogg' => 'audio'
    ];
    return $typeMap[$ext] ?? 'other';
}

// Apply filters to materials
$filteredMaterials = [];
foreach ($allMaterials as $lang => $materials) {
    // Filter by language
    if ($filterLanguage !== 'all' && $lang !== $filterLanguage) {
        continue;
    }
    
    foreach ($materials as $material) {
        // Filter by status (pre/post)
        if ($filterStatus !== 'all' && $material['material_type'] !== $filterStatus) {
            continue;
        }
        
        // Filter by material type
        if ($filterMaterialType !== 'all') {
            $materialFileType = getMaterialFileType($material['file_name'], $material['is_url'] == 1);
            if ($materialFileType !== $filterMaterialType) {
                continue;
            }
        }
        
        // Filter by search term
        if (!empty($filterSearch)) {
            $searchLower = strtolower($filterSearch);
            $matchTitle = stripos($material['title'], $searchLower) !== false;
            $matchTutor = stripos($material['tutor_name'], $searchLower) !== false;
            $matchLang = stripos($material['language'] ?? '', $searchLower) !== false;
            $matchDesc = stripos($material['description'] ?? '', $searchLower) !== false;
            if (!$matchTitle && !$matchTutor && !$matchLang && !$matchDesc) {
                continue;
            }
        }
        
        // Filter by date range
        if ($filterFrom && strtotime($material['uploaded_at']) < strtotime($filterFrom . ' 00:00:00')) {
            continue;
        }
        if ($filterTo && strtotime($material['uploaded_at']) > strtotime($filterTo . ' 23:59:59')) {
            continue;
        }
        
        if (!isset($filteredMaterials[$lang])) {
            $filteredMaterials[$lang] = [];
        }
        $filteredMaterials[$lang][] = $material;
    }
}


function e($value) {
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function getFileIcon($fileName) {
    if (!$fileName) return 'bi-file-earmark';
    $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    $icons = [
        'pdf' => 'bi-filetype-pdf',
        'mp4' => 'bi-camera-reels-fill',
        'mov' => 'bi-camera-reels-fill',
        'mp3' => 'bi-headphones',
        'wav' => 'bi-headphones',
        'jpg' => 'bi-file-image',
        'jpeg' => 'bi-file-image',
        'png' => 'bi-file-image',
        'zip' => 'bi-file-zip',
        'doc' => 'bi-file-word',
        'docx' => 'bi-file-word',
        'xls' => 'bi-file-excel',
        'xlsx' => 'bi-file-excel',
        'ppt' => 'bi-file-ppt',
        'pptx' => 'bi-file-ppt'
    ];
    return $icons[$ext] ?? 'bi-file-earmark';
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
  <title>My Materials · Kyoshi</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
  <link rel="stylesheet" href="../css/style.css">
  <style>
    :root{
      --cream:#FFF1F6;
      --paper:rgba(255,255,255,.92);
      --ink:#342635;
      --muted:#7B6178;
      --pink:#F28AB2;
      --pink-dark:#C94F86;
      --hot-pink:#E75A9B;
      --shadow:0 18px 45px rgba(201,79,134,.16);
      --radius-xl:32px;
      --radius-lg:24px;
    }
    *{box-sizing:border-box}html{scroll-behavior:smooth}
    body{margin:0;min-height:100vh;font-family:"Segoe UI",Arial,sans-serif;color:var(--ink);
      background:linear-gradient(120deg,rgba(255,241,246,.74),rgba(255,203,220,.30)),
      url("<?= e($assetBase) ?>/background3.jpg") center/cover fixed no-repeat;}
    body::before{content:"";position:fixed;inset:0;pointer-events:none;z-index:-1;
      background:radial-gradient(circle at 7% 10%,rgba(231,90,155,.32),transparent 24%),
      radial-gradient(circle at 90% 8%,rgba(255,195,216,.42),transparent 26%)}
    a{text-decoration:none;color:inherit}button,input{font-family:inherit}
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

    /* Active Classrooms */
    .classrooms-section{background:var(--paper);border-radius:var(--radius-lg);padding:12px 20px;margin-bottom:24px;border:1px solid rgba(255,255,255,.55);box-shadow:var(--shadow)}
    .classrooms-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;flex-wrap:wrap;gap:8px;}
    .classrooms-header h3{margin:0;font-size:16px}
    .classrooms-header h3 i{font-size:14px}
    .classrooms-header span{font-size:10px}
    .classrooms-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:10px}
    .classroom-card{background:rgba(255,241,246,.6);border-radius:16px;padding:10px 14px;display:flex;align-items:center;gap:12px;border:1px solid rgba(231,90,155,.15);transition:.2s}
    .classroom-card:hover{transform:translateY(-1px);box-shadow:0 4px 12px rgba(0,0,0,.06)}
    .classroom-info{flex:1}
    .classroom-info h4{margin:0 0 2px;font-size:13px;font-weight:900}
    .classroom-info p{margin:0;font-size:10px;color:var(--muted)}
    .classroom-info .focus-area{margin-top:3px;font-size:10px;color:var(--hot-pink);display:block}
    .join-btn{background:linear-gradient(135deg,var(--hot-pink),var(--pink));color:white;border:none;padding:6px 12px;border-radius:25px;font-size:11px;font-weight:700;cursor:pointer;transition:.2s;display:inline-flex;align-items:center;gap:5px;text-decoration:none;}
    .join-btn:hover{transform:scale(1.02);box-shadow:0 4px 12px rgba(231,90,155,.3);}
    .awaiting-btn{background:#e2e8f0;color:#64748b;border:none;padding:6px 12px;border-radius:25px;font-size:11px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:5px}
    .awaiting-btn:hover{background:#cbd5e1;}
    .empty-classrooms{text-align:center;padding:20px;background:rgba(255,241,246,.4);border-radius:16px;}
    .empty-classrooms i{font-size:32px;color:var(--muted);margin-bottom:8px;display:block;}
    .empty-classrooms p{margin:0;color:var(--muted);font-size:12px;}

    /* FILTER BAR - Student style */
    .filter-bar{background:var(--paper);border:1px solid rgba(255,255,255,.55);box-shadow:0 6px 20px rgba(201,79,134,.08);border-radius:var(--radius-lg);padding:16px 20px;margin-bottom:20px;display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;}
    .filter-group{display:flex;flex-direction:column;gap:5px;flex:1;min-width:140px}
    .filter-group label{font-size:11px;font-weight:900;color:var(--muted);text-transform:uppercase;letter-spacing:.5px}
    .filter-select,.filter-input{padding:9px 13px;border:1px solid rgba(46,42,59,.12);border-radius:12px;outline:none;font-size:13px;font-weight:700;color:var(--ink);background:rgba(255,255,255,.9)}
    .filter-select:focus,.filter-input:focus{border-color:var(--hot-pink);box-shadow:0 0 0 3px rgba(231,90,155,.12)}
    .btn-reset{padding:9px 18px;border-radius:999px;border:1px solid rgba(46,42,59,.12);background:none;color:var(--muted);font-size:13px;font-weight:900;cursor:pointer;white-space:nowrap;align-self:flex-end;text-decoration:none;display:inline-flex;align-items:center;gap:6px;}
    .btn-reset:hover{background:rgba(255,255,255,.88)}
    .filter-applied {
    background: #fef3c7;
    color: #92400e;
    padding: 8px 15px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
    margin-bottom: 15px;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}
    /* Materials */
    .materials-card{background:var(--paper);border:1px solid rgba(255,255,255,.55);box-shadow:var(--shadow);border-radius:var(--radius-xl);overflow:hidden;padding:28px}
    .materials-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(340px,1fr));gap:24px;margin-top:16px;}
    .material-card{background:white;border-radius:20px;padding:20px;transition:.3s;border:1px solid rgba(231,90,155,.1);box-shadow:0 2px 8px rgba(0,0,0,.04)}
    .material-card:hover{transform:translateY(-3px);box-shadow:var(--shadow);border-color:rgba(231,90,155,.3)}
    .material-header{display:flex;align-items:center;gap:14px;margin-bottom:16px}
    .material-icon{width:55px;height:55px;background:rgba(231,90,155,.1);border-radius:16px;display:flex;align-items:center;justify-content:center}
    .material-icon i{font-size:28px;color:var(--hot-pink)}
    .material-info{flex:1}
    .material-info h4{margin:0 0 4px;font-size:16px;font-weight:700}
    .material-tutor{font-size:11px;color:var(--muted);display:flex;align-items:center;gap:4px}
    .material-description{font-size:13px;color:#475569;line-height:1.5;margin-bottom:16px;padding:10px 0;border-top:1px solid rgba(231,90,155,.1)}
    .material-meta{display:flex;flex-wrap:wrap;gap:12px;margin-bottom:16px;font-size:11px;color:var(--muted)}
    .material-actions{display:flex;gap:10px;border-top:1px solid rgba(231,90,155,.1);padding-top:14px}
    .btn-download,.btn-preview{flex:1;padding:8px;border-radius:30px;font-weight:600;font-size:12px;cursor:pointer;transition:.2s;text-align:center;display:inline-flex;align-items:center;justify-content:center;gap:6px;text-decoration:none;}
    .btn-download{background:linear-gradient(135deg,var(--hot-pink),var(--pink));color:white;border:none}
    .btn-preview{background:white;color:var(--hot-pink);border:1px solid var(--hot-pink)}
    .btn-download:hover,.btn-preview:hover{transform:translateY(-2px)}
    .empty-state{text-align:center;padding:60px;background:rgba(255,241,246,.8);border-radius:30px}
    .empty-state i{font-size:64px;color:var(--muted);margin-bottom:20px;display:block}
    .empty-state h3{margin-bottom:10px}
    .toast{position:fixed;left:50%;bottom:28px;transform:translate(-50%,18px);opacity:0;pointer-events:none;z-index:99;background:#8E3F70;color:#fff;border-radius:999px;padding:12px 18px;font-size:13px;font-weight:900;transition:.2s ease}
    .toast.show{opacity:1;transform:translate(-50%,0)}

    /* Loading Overlay */
    .loading-overlay {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: linear-gradient(135deg, #FFF1F6, #FFCBDC);
        display: flex;
        justify-content: center;
        align-items: center;
        z-index: 9999;
        transition: opacity 0.5s ease;
    }

    .loading-overlay.hide {
        opacity: 0;
        pointer-events: none;
    }

    .spinner {
        text-align: center;
    }

    .spinner i {
        font-size: 48px;
        color: var(--hot-pink);
        animation: spin 1s linear infinite;
    }

    .spinner p {
        margin-top: 16px;
        color: var(--ink);
        font-size: 14px;
        font-weight: 500;
    }

    @keyframes spin {
        from { transform: rotate(0deg); }
        to { transform: rotate(360deg); }
    }

    @media(max-width:900px){
      .nav{grid-template-columns:1fr auto;gap:12px;}
      .nav-links{grid-column:1/-1;justify-content:center;}
      .filter-bar{flex-direction:column;align-items:stretch;}
      .filter-group{min-width:auto;}
    }
    @media(max-width:600px){.materials-grid{grid-template-columns:1fr}.classrooms-grid{grid-template-columns:1fr}}
  /* ========== FIX BACK BUTTON ON MOBILE ========== */
@media (max-width: 768px) {
    /* Fix container for the back button and title */
    .container > div:first-child {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        margin-bottom: 20px;
        position: relative;
        min-height: 60px;
    }
        .btn-reset {
        width: 100% !important;
        justify-content: center !important;
        margin-top: 8px;
    }
    
    /* Back button - position at top left on mobile */
    .back-link {
        position: absolute !important;
        left: 0 !important;
        top: 0 !important;
        transform: none !important;
        margin: 0 !important;
        padding: 8px 12px;
        font-size: 12px;
        z-index: 10;
    }
    
    /* Hide the text on mobile, show only icon */
    .back-link span {
        display: none;
    }
    
    .back-link i {
        font-size: 16px;
    }
    
    /* Title - centered with some top margin */
    .container > div:first-child h1 {
        font-size: 22px !important;
        margin: 0 !important;
        padding-top: 10px;
    }
    
    .container > div:first-child p {
        font-size: 12px;
        margin-top: 5px !important;
    }
}

/* For very small screens */
@media (max-width: 480px) {
    .back-link {
        padding: 6px 10px;
    }
    
    .container > div:first-child h1 {
        font-size: 20px !important;
    }
}
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
        <a href="find_language.php">Find Language</a>
        <a href="booking_status.php">My Bookings</a>
        <a href="my_payments.php">My Payments</a>
        <a class="active" href="my_materials.php">My Materials</a>
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
<div class="container" style="padding:24px 0 60px;">
  
  <div style="position:relative;text-align:center;margin-bottom:20px;">
    <a href="student_dashboard.php" class="back-link" style="position:absolute;left:0;top:50%;transform:translateY(-50%);margin:0;">
      <i class="bi bi-arrow-left"></i><span>Back</span>
    </a>
    <h1 style="margin:0;font-size:28px;letter-spacing:-.6px;">My Learning Materials</h1>
    <p style="margin:5px 0 0;color:var(--muted);font-size:14px;">Access all resources shared by your tutors</p>
  </div>

  <!-- CLASSROOMS SECTION -->
  <div class="classrooms-section">
    <div class="classrooms-header">
      <h3><i class="bi bi-camera-reels-fill"></i> Live Classrooms</h3>
      <span style="font-size:10px;color:var(--muted);">Upcoming online sessions</span>
    </div>
    <?php if (!empty($activeClassrooms)): ?>
    <div class="classrooms-grid">
      <?php foreach ($activeClassrooms as $classroom): ?>
      <div class="classroom-card">
        <div class="classroom-info">
          <h4><?= e($classroom['language']) ?> with <?= e($classroom['tutor_name']) ?></h4>
          <p><?= date('d M Y, g:i A', strtotime($classroom['booking_date'] . ' ' . $classroom['booking_time'])) ?></p>
          <?php if (!empty($classroom['focus'])): ?>
            <span class="focus-area">Focus Area: <?= e($classroom['focus']) ?></span>
          <?php endif; ?>
        </div>
        <?php if (!empty($classroom['meeting_link'])): ?>
    <a href="join_meeting.php?booking_id=<?= $classroom['booking_id'] ?>&link=<?= urlencode($classroom['meeting_link']) ?>" target="_blank" class="join-btn">
        <i class="bi bi-google"></i> Join Meeting
    </a>
    <?php else: ?>
        <button class="awaiting-btn" onclick="showToast('Meeting link will be shared by your tutor before the session')">
            <i class="bi bi-clock"></i> Awaiting Link
        </button>
    <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div class="empty-classrooms">
      <i class="bi bi-camera-video-off"></i>
      <p>No upcoming online sessions</p>
      <p style="font-size:10px; margin-top:3px;">Book a session to see your live classrooms here</p>
    </div>
    <?php endif; ?>
  </div>

 <!-- FILTER BAR - With Language, Material Type, and Search -->
<form method="GET" class="filter-bar" id="filterForm">
    <div class="filter-group">
        <label><i class="bi bi-search"></i> Search</label>
        <input type="text" name="search" class="filter-input" placeholder="Title and tutor" value="<?= e($filterSearch) ?>">
    </div>
    <div class="filter-group">
        <label><i class="bi bi-funnel"></i> Status</label>
        <select name="status" class="filter-select">
            <option value="all" <?= $filterStatus==='all'?'selected':'' ?>>All Sessions</option>
            <option value="pre" <?= $filterStatus==='pre'?'selected':'' ?>>Pre-Session</option>
            <option value="post" <?= $filterStatus==='post'?'selected':'' ?>>Post-Session</option>
        </select>
    </div>
    <div class="filter-group">
        <label><i class="bi bi-translate"></i> Language</label>
        <select name="language" class="filter-select">
            <option value="all" <?= $filterLanguage==='all'?'selected':'' ?>>All Languages</option>
            <?php foreach ($allLanguages as $lang): ?>
                <option value="<?= e($lang) ?>" <?= $filterLanguage===$lang?'selected':'' ?>><?= e($lang) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="filter-group">
        <label><i class="bi bi-file-earmark"></i> Material Type</label>
        <select name="material_type_filter" class="filter-select">
            <option value="all" <?= ($filterMaterialType) === 'all' ? 'selected' : '' ?>>All Materials</option>
            <option value="url" <?= $filterMaterialType === 'url' ? 'selected' : '' ?>>URL / Link</option>
            <option value="pdf" <?= $filterMaterialType === 'pdf' ? 'selected' : '' ?>>PDF</option>
            <option value="word" <?= $filterMaterialType === 'word' ? 'selected' : '' ?>>Word Document</option>
            <option value="powerpoint" <?= $filterMaterialType === 'powerpoint' ? 'selected' : '' ?>>PowerPoint</option>
            <option value="image" <?= $filterMaterialType === 'image' ? 'selected' : '' ?>>Image</option>
            <option value="video" <?= $filterMaterialType === 'video' ? 'selected' : '' ?>>Video</option>
            <option value="audio" <?= $filterMaterialType === 'audio' ? 'selected' : '' ?>>Audio</option>
        </select>
    </div>
    <div class="filter-group">
        <label><i class="bi bi-sort-down"></i> Sort By</label>
        <select name="sort" class="filter-select">
            <option value="newest" <?= $filterSort==='newest'?'selected':'' ?>>Newest First</option>
            <option value="oldest" <?= $filterSort==='oldest'?'selected':'' ?>>Oldest First</option>
            <option value="title_az" <?= $filterSort==='title_az'?'selected':'' ?>>Title (A-Z)</option>
            <option value="title_za" <?= $filterSort==='title_za'?'selected':'' ?>>Title (Z-A)</option>
        </select>
    </div>
    <button type="submit" class="btn-search" style="background:linear-gradient(135deg,var(--hot-pink),var(--pink));color:white;border:none;padding:9px 18px;border-radius:12px;font-weight:700;cursor:pointer;">
        <i class="bi bi-search"></i> Search
    </button>
    <a href="my_materials.php" class="btn-reset"><i class="bi bi-x"></i> Reset</a>
</form>

  <!-- MATERIALS SECTION -->
<div class="materials-card">
    <?php 
    // Collect all filtered materials into one array for display
    $allFilteredMaterials = [];
    foreach ($filteredMaterials as $lang => $materials) {
        $allFilteredMaterials = array_merge($allFilteredMaterials, $materials);
    }
    
    // Sort all materials
    if ($filterSort === 'newest') {
        usort($allFilteredMaterials, function($a, $b) {
            return strtotime($b['uploaded_at']) - strtotime($a['uploaded_at']);
        });
    } elseif ($filterSort === 'oldest') {
        usort($allFilteredMaterials, function($a, $b) {
            return strtotime($a['uploaded_at']) - strtotime($b['uploaded_at']);
        });
    } elseif ($filterSort === 'title_az') {
        usort($allFilteredMaterials, function($a, $b) {
            return strcmp($a['title'] ?? '', $b['title'] ?? '');
        });
    } elseif ($filterSort === 'title_za') {
        usort($allFilteredMaterials, function($a, $b) {
            return strcmp($b['title'] ?? '', $a['title'] ?? '');
        });
    }
    
    $hasFilteredMaterials = !empty($allFilteredMaterials);
    $noMaterialsAtAll = empty($allMaterials);
    ?>
    
    <?php if ($noMaterialsAtAll): ?>
      <div class="empty-state">
        <i class="bi bi-journal-bookmark-fill"></i>
        <h3>No materials provided</h3>
        <p>Your learning materials will appear here once your tutors upload them.</p>
        <a href="find_language.php" class="btn-download" style="display: inline-flex; align-items: center; justify-content: center; gap: 8px; background: linear-gradient(135deg, #E75A9B, #F28AB2); color: white; padding: 12px 28px; border-radius: 40px; text-decoration: none; font-weight: 600; font-size: 14px; transition: all 0.3s ease; box-shadow: 0 4px 12px rgba(231, 90, 155, 0.3); margin-top: 16px; border: none; cursor: pointer; width: auto;">
    <span style="line-height: 1;">Find a Tutor</span>
</a>
      </div>
   <?php elseif (!$hasFilteredMaterials && $hasActiveFilters): ?>
      <div class="empty-state">
        <i class="bi bi-funnel"></i>
        <h3>No materials found</h3>
        <p>We couldn't find any materials matching your search criteria.</p>
        <div style="margin-top: 20px;">
            <a href="my_materials.php" class="btn-download" style="display:inline-block;padding:12px 28px;width:auto;background: linear-gradient(135deg, #E75A9B, #F28AB2);">
                <i class="bi bi-arrow-repeat" style="font-size: 50px;"></i> Clear All Filters
            </a>
        </div>
      </div>
    <?php elseif (!$hasFilteredMaterials && !$hasActiveFilters): ?>
      <div class="empty-state">
        <i class="bi bi-journal-bookmark-fill"></i>
        <h3>No materials available</h3>
        <p>Check back later for new learning materials from your tutors.</p>
      </div>
    <?php else: ?>
      <div id="materialsContainer"></div>
    <?php endif; ?>
  </div>
</div>

<div class="toast" id="toast"></div>

<script>
const materialsData = <?= json_encode($allFilteredMaterials) ?>;
const MATERIAL_BASE = '';

function showToast(message) {
    const toast = document.getElementById('toast');
    toast.textContent = message;
    toast.classList.add('show');
    setTimeout(() => toast.classList.remove('show'), 3000);
}

function toggleDropdown() {
    const d = document.getElementById('profileDropdown');
    d.style.display = d.style.display === 'block' ? 'none' : 'block';
}

document.addEventListener('click', function(e) {
    const btn = document.querySelector('.profile');
    const dd = document.getElementById('profileDropdown');
    if (btn && dd && !btn.contains(e.target) && !dd.contains(e.target)) {
        dd.style.display = 'none';
    }
});


document.addEventListener('DOMContentLoaded', function() {
    const hasFilters = <?= json_encode($hasActiveFilters) ?>;
    const hasResults = <?= json_encode($hasFilteredMaterials) ?>;
    
    if (hasFilters && !hasResults) {
        showToast('No materials found matching your search criteria', '#dc2626');
    }
});

function getFileIcon(fileName) {
    if (!fileName) return 'bi-file-earmark';
    const ext = fileName.split('.').pop().toLowerCase();
    const icons = {
        'pdf': 'bi-filetype-pdf',
        'mp4': 'bi-camera-reels-fill',
        'mov': 'bi-camera-reels-fill',
        'mp3': 'bi-headphones',
        'wav': 'bi-headphones',
        'jpg': 'bi-file-image',
        'jpeg': 'bi-file-image',
        'png': 'bi-file-image',
        'zip': 'bi-file-zip',
        'doc': 'bi-file-word',
        'docx': 'bi-file-word',
        'xls': 'bi-file-excel',
        'xlsx': 'bi-file-excel',
        'ppt': 'bi-file-ppt',
        'pptx': 'bi-file-ppt'
    };
    return icons[ext] || 'bi-file-earmark';
}

function formatDate(dateString) {
    if (!dateString) return '';
    const date = new Date(dateString);
    return date.toLocaleDateString('en-MY', { day: 'numeric', month: 'short', year: 'numeric' });
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function downloadMaterial(filePath, fileName) {
    if (!filePath || filePath === 'null' || filePath === '') {
        showToast('File not available for download', '#dc2626');
        return;
    }
    
    // Construct the full URL
    const downloadUrl = MATERIAL_BASE + filePath;
    
    // Show downloading toast
    showToast('Downloading ' + (fileName || 'file') + '...', '#f59e0b');
    
    // Create a temporary link and trigger download
    const link = document.createElement('a');
    link.href = downloadUrl;
    link.download = fileName || 'download';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    
    // Show success message
    setTimeout(() => {
        showToast('Download started!', '#28a745');
    }, 500);
}

function displayMaterials(materials) {
    const container = document.getElementById('materialsContainer');
    
    if (materials.length === 0) {
        container.innerHTML = `
            <div class="empty-state">
                <i class="bi bi-file-earmark-text"></i>
                <h3>No Materials</h3>
                <p>No materials match your filters.</p>
            </div>
        `;
        return;
    }
    
    let html = '<div class="materials-grid">';
    for (const material of materials) {
        const isUrl = material.is_url == 1;
        const fileIcon = isUrl ? 'bi-link-45deg' : getFileIcon(material.file_name);
        const fileSize = material.file_size ? (material.file_size / 1024 / 1024).toFixed(1) + ' MB' : '';
        const fileExt = material.file_name ? material.file_name.split('.').pop().toUpperCase() : (isUrl ? 'LINK' : 'FILE');
        
        const hasFeedback = material.feedback && material.feedback.trim() !== '';
        const hasDescription = material.description && material.description.trim() !== '';
        const previewText = hasFeedback ? material.feedback.substring(0, 80) : (hasDescription ? material.description.substring(0, 80) : '');
        
        html += `
            <div class="material-card">
                <div class="material-header">
                    <div class="material-icon">
                        <i class="${fileIcon}"></i>
                    </div>
                    <div class="material-info">
                        <h4>${escapeHtml(material.title)}</h4>
                        <div class="material-tutor">
                            <i class="bi bi-person-circle"></i> ${escapeHtml(material.tutor_name || 'Tutor')}
                        </div>
                    </div>
                </div>
                <div class="material-meta">
                    <span><i class="bi bi-calendar"></i> ${formatDate(material.uploaded_at)}</span>
                    <span><i class="bi bi-translate"></i> ${escapeHtml(material.language || 'General')}</span>
                    <span><i class="bi ${isUrl ? 'bi-link-45deg' : 'bi-file-earmark'}"></i> ${fileExt}</span>
                    ${!isUrl && fileSize ? `<span><i class="bi bi-database"></i> ${fileSize}</span>` : ''}
                </div>
                <div class="material-actions" style="${isUrl ? 'justify-content: center;' : ''}">
                    ${isUrl ? `
                        <a href="view_material.php?id=${material.id}" class="btn-preview" style="flex: 1; max-width: 350px; margin: 0 auto;">
                            <i class="bi bi-eye"></i> View Material
                        </a>
                    ` : `
                        <a href="view_material.php?id=${material.id}" class="btn-preview" style="flex:1;">
                            <i class="bi bi-eye"></i> View Material
                        </a>
                        <button class="btn-download" onclick="downloadMaterial('${escapeHtml(material.file_path)}', '${escapeHtml(material.file_name)}')" style="flex:1;">
                            <i class="bi bi-download"></i> Download
                        </button>
                    `}
                </div>
            </div>
        `;
    }
    html += '</div>';
    container.innerHTML = html;
}

// Initialize display with filtered materials
displayMaterials(<?= json_encode($allFilteredMaterials) ?>);


// Show toast when page loads with active filters
document.addEventListener('DOMContentLoaded', function() {
    const urlParams = new URLSearchParams(window.location.search);
    const hasFilters = urlParams.get('status') || urlParams.get('language') || urlParams.get('material_type_filter') || urlParams.get('search') || urlParams.get('date_from') || urlParams.get('date_to');
    const materialsCount = <?= count($allFilteredMaterials) ?>;
    
    if (hasFilters) {
        if (materialsCount > 0) {
            showToast(`Found ${materialsCount} material${materialsCount !== 1 ? 's' : ''} matching your filters`, '#28a745');
        } else {
            showToast('No materials match your filters', '#dc2626');
        }
    }
});

// Also show toast when reset button is clicked
document.querySelector('.btn-reset')?.addEventListener('click', function(e) {
    // Don't show toast on reset since it redirects to clean page
    // The page will reload without filters
});

// Record when user leaves the page (for meeting logs)
window.addEventListener('beforeunload', function() {
    const bookingId = new URLSearchParams(window.location.search).get('id');
    if (bookingId) {
        fetch('record_meeting_leave.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ booking_id: bookingId }),
            keepalive: true
        });
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