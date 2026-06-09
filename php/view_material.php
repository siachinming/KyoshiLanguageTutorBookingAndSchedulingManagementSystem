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

// Get material ID
$materialId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Fetch material details with tutor info
$stmt = $conn->prepare("
    SELECT 
        lm.*,
        u.fullname AS tutor_name,
        u.id AS tutor_id,
        u.profile_pic AS tutor_pic,
        u.phone AS tutor_phone,
        b.language,
        b.booking_date,
        b.booking_time,
        b.focus,
        b.learning_mode
    FROM learning_materials lm
    LEFT JOIN users u ON lm.tutor_id = u.id
    LEFT JOIN bookings b ON lm.booking_id = b.id
    WHERE lm.id = ? AND (b.student_id = ? OR b.student_id IS NULL)
");
$stmt->bind_param("ii", $materialId, $userID);
$stmt->execute();
$material = $stmt->get_result()->fetch_assoc();

if (!$material) {
    header("Location: my_materials.php");
    exit();
}

$isUrl = $material['is_url'] == 1;
$filePath = !$isUrl && $material['file_path'] ? $material['file_path'] : '';
$materialUrl = $isUrl ? $material['material_url'] : '';

function e($value) {
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function formatDate($dateString) {
    if (!$dateString) return '';
    $date = new DateTime($dateString);
    return $date->format('d M Y');
}

function formatFileSize($bytes) {
    if ($bytes === null || $bytes === 0) return 'Unknown';
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = floor(log($bytes, 1024));
    return round($bytes / pow(1024, $i), 1) . ' ' . $units[$i];
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
        'ogg' => 'bi-headphones',
        'm4a' => 'bi-headphones',
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
  <title><?= e($material['title']) ?> · Kyoshi</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
  <link rel="stylesheet" href="../css/style.css">
  <style>
    :root{
      --cream:#FFF1F6;--paper:rgba(255,255,255,.92);--ink:#342635;--muted:#7B6178;
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
    a{text-decoration:none;color:inherit}button{font-family:inherit}
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

    /* MAIN CONTENT */
    .back-link{display:inline-flex;align-items:center;gap:6px;color:var(--pink-dark);font-weight:900;font-size:13px;padding:9px 16px;border-radius:999px;background:rgba(255,255,255,.78);border:1px solid rgba(46,42,59,.08);transition:.18s ease;}
    .back-link:hover{transform:translateY(-1px)}

    .material-viewer{background:var(--paper);border-radius:var(--radius-xl);padding:32px;box-shadow:var(--shadow);border:1px solid rgba(255,255,255,.55);margin-top:20px;}
    
    /* Two column layout */
    .material-layout{display:flex;gap:32px;flex-wrap:wrap}
    .material-main{flex:2;min-width:250px}
    .material-sidebar{flex:1;min-width:220px}
    
    /* Sidebar styles */
    .info-card{background:rgba(255,255,255,.6);border-radius:20px;padding:20px;margin-bottom:20px}
    .info-card h4{font-size:12px;color:var(--muted);margin:0 0 12px;text-transform:uppercase;letter-spacing:1px;display:flex;align-items:center;gap:6px}
    .tutor-section{display:flex;align-items:center;gap:12px;margin-bottom:16px}
    .tutor-section img{width:50px;height:50px;border-radius:50%;object-fit:cover}
    .tutor-section .tutor-name{font-weight:700;font-size:15px}
    .tutor-section .tutor-role{font-size:11px;color:var(--muted);margin-top:2px}
    
    .info-row{display:flex;align-items:center;gap:10px;padding:10px 0;border-bottom:1px solid rgba(231,90,155,.1)}
    .info-row:last-child{border-bottom:none}
    .info-row i{width:24px;color:var(--hot-pink);font-size:14px}
    .info-row .label{font-size:12px;color:var(--muted);flex:1}
    .info-row .value{font-size:13px;font-weight:600;color:var(--ink)}
    
    .session-badge{display:inline-block;padding:4px 12px;border-radius:20px;font-size:11px;font-weight:600}
    .session-pre{background:rgba(245,158,11,.15);color:#f59e0b}
    .session-post{background:rgba(40,167,69,.15);color:#28a745}
    
    .material-header{display:flex;gap:20px;margin-bottom:24px;flex-wrap:wrap;align-items:center;}
    .material-icon{width:70px;height:70px;background:rgba(231,90,155,.1);border-radius:20px;display:flex;align-items:center;justify-content:center}
    .material-icon i{font-size:36px;color:var(--hot-pink)}
    .material-title-section h1{margin:0 0 5px;font-size:24px}
    .material-title-section .material-subtitle{font-size:12px;color:var(--muted)}
    
    /* Instruction Section - Important, placed at top */
    .instruction-section{background:rgba(231,90,155,.06);border-left:4px solid var(--hot-pink);padding:20px;border-radius:16px;margin-bottom:24px}
    .instruction-section h3{font-size:14px;margin:0 0 8px;color: var(--hot-pink);display:flex;align-items:center;gap:8px}
    .instruction-section p{margin:0;font-size:14px;line-height:1.6;color:var(--ink);white-space:pre-wrap}
    
    .description-section{background:linear-gradient(135deg, #f4ecff);border-left:4px solid #a78bfa;padding:20px;border-radius:16px;margin-bottom:24px;box-shadow:0 2px 8px rgba(0,0,0,.04)}
    .description-section h3{font-size:14px;margin:0 0 10px;color:#8b5cf6;display:flex;align-items:center;gap:8px}
    .description-section h3 i{font-size:16px}
    .description-section p{margin:0;font-size:14px;line-height:1.6;color:#334155;white-space:pre-wrap}

    /* Description Section - Empty state (gray, subtle) */
    .description-section.empty{border-left-color:#cbd5e1;background:rgba(0,0,0,.02);box-shadow:none}
    .description-section.empty h3{color:#94a3b8}
    .description-section.empty p{color:#94a3b8}
    .preview-area{background:transparent;border-radius:0;padding:30px;text-align:center;border:none;margin-bottom:24px}
    .preview-area iframe{width:100%;height:450px;border:none;border-radius:0}
.preview-area video{max-width:100%;border-radius:0;max-height:400px;outline:none;border:none}
.preview-area img{max-width:100%;border-radius:0;max-height:400px;object-fit:contain}
    .preview-area audio{width:100%;max-width:500px;margin:20px auto;display:block}
    
    .action-buttons{display:flex;gap:12px;justify-content:center;flex-wrap:wrap;margin-top:20px}
    .btn{display:inline-flex;align-items:center;gap:8px;padding:10px 24px;border-radius:999px;font-weight:700;font-size:13px;cursor:pointer;transition:.2s;border:none;text-decoration:none}
    .btn-primary{background:linear-gradient(135deg,var(--hot-pink),var(--pink));color:white}
    .btn-secondary{background:white;color:var(--hot-pink);border:1px solid var(--hot-pink)}
    .btn:hover{transform:translateY(-2px)}
    
    .toast{position:fixed;left:50%;bottom:28px;transform:translate(-50%,18px);opacity:0;pointer-events:none;z-index:99;background:#8E3F70;color:#fff;border-radius:999px;padding:12px 18px;font-size:13px;font-weight:900;transition:.2s ease}
    .toast.show{opacity:1;transform:translate(-50%,0)}
    /* Document Preview Styles */
    .pdf-preview-container,
    .doc-preview-container,
    .ppt-preview-container,
    .excel-preview-container {
        background: #f5f5f5;
        border-radius: 16px;
        overflow: hidden;
    }

    .pdf-preview-container iframe,
    .doc-preview-container iframe,
    .ppt-preview-container iframe,
    .excel-preview-container iframe {
        width: 100%;
        height: 600px;
        border: none;
    }

    .text-preview-container pre {
        background: #2d2d2d;
        color: #f8f8f2;
        padding: 20px;
        border-radius: 12px;
        overflow-x: auto;
        font-size: 12px;
        line-height: 1.5;
    }

    .source-indicator a {
        text-decoration: none;
    }

    .source-indicator a:hover {
        text-decoration: underline;
    }

    /* Loading indicator for iframes */
    iframe {
        background: #f0f0f0;
        position: relative;
    }

    iframe::before {
        content: "Loading...";
        position: absolute;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        color: #999;
    }
    @media(max-width:900px){
      .material-layout{flex-direction:column}
      .material-sidebar{order:-1}
      .page-title{font-size:10px !important;}
      h2{font-size: 0px;}
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
    <!-- Header with Back button and centered Material Details title -->
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <a href="my_materials.php" class="back-link">
            <i class="bi bi-arrow-left"></i><span>Back</span>
        </a>
        <h2 style="margin:0; font-size: 20px; color: var(--ink); text-align: center; flex:1;">
             Material Details
        </h2>
        <div style="width: 90px;"></div> <!-- Spacer to balance the layout -->
    </div>
    
    <div class="material-viewer">
        <div class="material-layout">
            <!-- MAIN CONTENT - Left side -->
            <div class="material-main">
                <div class="material-header">
                    <div class="material-icon">
                        <i class="<?= getFileIcon($material['file_name']) ?>"></i>
                    </div>
                    <div class="material-title-section">
                        <h1><?= e($material['title']) ?></h1>
                        <div class="material-subtitle">
                            <?= $isUrl ? 'External Link' : 'Learning Material' ?>
                        </div>
                    </div>
                </div>
                
                <!-- TUTOR'S INSTRUCTIONS / FEEDBACK - Placed at TOP (important) -->
                <?php if (!empty($material['feedback'])): ?>
                <div class="instruction-section">
                    <h3>Tutor's Instructions</h3>
                    <p><?= nl2br(e($material['feedback'])) ?></p>
                </div>
                <?php else: ?>
                <div class="instruction-section" style="background:rgba(0,0,0,.02); border-left-color: #cbd5e1;">
                    <h3>Tutor's Instructions</h3>
                    <p style="color: #94a3b8;">No specific instructions provided for this material.</p>
                </div>
                <?php endif; ?>
                
                <!-- MATERIAL DESCRIPTION - Placed after instructions -->
                <?php if (!empty($material['description'])): ?>
                <div class="description-section">
                    <h3>Description</h3>
                    <p><?= nl2br(e($material['description'])) ?></p>
                </div>
                <?php else: ?>
                <div class="description-section empty">
                    <h3>Description</h3>
                    <p>No description provided for this material.</p>
                </div>
                <?php endif; ?>

                
                <!-- PREVIEW AREA -->
<div class="preview-area">
    <?php if ($isUrl): ?>
        <?php 
        $url = $materialUrl;
        $isYouTube = false;
        $isGoogleDocs = false;
        
        // Check for YouTube
        if (strpos($url, 'youtube.com/watch?v=') !== false || strpos($url, 'youtu.be/') !== false) {
            $isYouTube = true;
            if (strpos($url, 'youtu.be/') !== false) {
                $videoId = substr($url, strrpos($url, '/') + 1);
                $embedUrl = 'https://www.youtube.com/embed/' . $videoId;
            } else {
                parse_str(parse_url($url, PHP_URL_QUERY), $params);
                $videoId = $params['v'] ?? '';
                $embedUrl = 'https://www.youtube.com/embed/' . $videoId;
            }
            echo '<iframe src="' . e($embedUrl) . '" frameborder="0" allowfullscreen></iframe>';
            echo '<div class="source-indicator"><i class="bi bi-youtube" style="color:#ff0000;"></i> YouTube Video</div>';
        }
        // Check for Google Docs/Sheets/Slides
        elseif (strpos($url, 'docs.google.com') !== false) {
            echo '<iframe src="' . e($url) . '" title="' . e($material['title']) . '"></iframe>';
            echo '<div class="source-indicator"><i class="bi bi-google"></i> Google Document</div>';
        }
        else {
            echo '<iframe src="' . e($url) . '" title="' . e($material['title']) . '"></iframe>';
            echo '<div class="source-indicator"><i class="bi bi-link-45deg"></i> External Website</div>';
        }
        ?>
    <?php else:
        $ext = strtolower(pathinfo($material['file_name'] ?? '', PATHINFO_EXTENSION));
        $fullPath = $filePath;
        
        // Images
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])): ?>
            <img src="<?= e($fullPath) ?>" alt="<?= e($material['title']) ?>">
            <div class="source-indicator"><i class="bi bi-file-image"></i> Image File</div>
            
        <?php 
        // Videos
        elseif (in_array($ext, ['mp4', 'mov', 'avi', 'mkv', 'webm'])): ?>
            <video controls src="<?= e($fullPath) ?>" style="border:none; outline:none; width:100%; max-height:500px;">
                Your browser doesn't support video playback.
            </video>
            <div class="source-indicator"><i class="bi bi-camera-reels-fill"></i> Video File</div>
            
        <?php 
        // Audio
        elseif (in_array($ext, ['mp3', 'wav', 'ogg', 'm4a'])): ?>
            <audio controls src="<?= e($fullPath) ?>" style="width:100%;">
                Your browser doesn't support audio playback.
            </audio>
            <div class="source-indicator"><i class="bi bi-headphones"></i> Audio File</div>
            
        <?php 
        // PDF - Use Google Docs Viewer for better preview
        elseif ($ext === 'pdf'): ?>
            <div class="pdf-preview-container">
                <iframe src="https://docs.google.com/viewer?url=<?= urlencode($fullPath) ?>&embedded=true" 
                        title="<?= e($material['title']) ?>"
                        style="width:100%; height:600px; border:none; border-radius:16px;">
                </iframe>
                <div class="source-indicator">
                    <i class="bi bi-filetype-pdf"></i> PDF Document 
                    <a href="<?= e($fullPath) ?>" download style="margin-left:12px; color:var(--hot-pink);">
                        <i class="bi bi-download"></i> Download PDF
                    </a>
                </div>
            </div>
            
        <?php 
        // Word Documents - Use Google Docs Viewer
        elseif (in_array($ext, ['doc', 'docx'])): ?>
            <div class="doc-preview-container">
                <iframe src="https://docs.google.com/viewer?url=<?= urlencode($fullPath) ?>&embedded=true" 
                        title="<?= e($material['title']) ?>"
                        style="width:100%; height:600px; border:none; border-radius:16px;">
                </iframe>
                <div class="source-indicator">
                    <i class="bi bi-file-word"></i> Word Document
                    <a href="<?= e($fullPath) ?>" download style="margin-left:12px; color:var(--hot-pink);">
                        <i class="bi bi-download"></i> Download
                    </a>
                </div>
            </div>
            
        <?php 
        // PowerPoint - Use Google Docs Viewer
        elseif (in_array($ext, ['ppt', 'pptx'])): ?>
            <div class="ppt-preview-container">
                <iframe src="https://docs.google.com/viewer?url=<?= urlencode($fullPath) ?>&embedded=true" 
                        title="<?= e($material['title']) ?>"
                        style="width:100%; height:600px; border:none; border-radius:16px;">
                </iframe>
                <div class="source-indicator">
                    <i class="bi bi-file-ppt"></i> PowerPoint Presentation
                    <a href="<?= e($fullPath) ?>" download style="margin-left:12px; color:var(--hot-pink);">
                        <i class="bi bi-download"></i> Download
                    </a>
                </div>
            </div>
            
        <?php 
        // Excel - Use Google Docs Viewer
        elseif (in_array($ext, ['xls', 'xlsx'])): ?>
            <div class="excel-preview-container">
                <iframe src="https://docs.google.com/viewer?url=<?= urlencode($fullPath) ?>&embedded=true" 
                        title="<?= e($material['title']) ?>"
                        style="width:100%; height:600px; border:none; border-radius:16px;">
                </iframe>
                <div class="source-indicator">
                    <i class="bi bi-file-excel"></i> Excel Spreadsheet
                    <a href="<?= e($fullPath) ?>" download style="margin-left:12px; color:var(--hot-pink);">
                        <i class="bi bi-download"></i> Download
                    </a>
                </div>
            </div>
            
        <?php 
        // Text files
        elseif (in_array($ext, ['txt', 'csv', 'json', 'xml'])): ?>
            <div class="text-preview-container" style="background:#f5f5f5; padding:20px; border-radius:16px;">
                <pre style="white-space:pre-wrap; font-family:monospace; font-size:12px; max-height:400px; overflow:auto;">
                    <?php 
                    if (file_exists($fullPath)) {
                        echo htmlspecialchars(file_get_contents($fullPath));
                    } else {
                        echo "File content cannot be displayed.";
                    }
                    ?>
                </pre>
                <div class="source-indicator">
                    <i class="bi bi-file-text"></i> Text File
                    <a href="<?= e($fullPath) ?>" download style="margin-left:12px; color:var(--hot-pink);">
                        <i class="bi bi-download"></i> Download
                    </a>
                </div>
            </div>
            
        <?php 
        // Zip files
        elseif (in_array($ext, ['zip', 'rar', '7z'])): ?>
            <div style="padding:60px 40px; text-align:center; background:#f9f9f9; border-radius:16px;">
                <i class="bi bi-file-zip" style="font-size:64px;color:var(--muted);display:block;margin-bottom:16px"></i>
                <h3>Compressed File</h3>
                <p style="color:var(--muted); margin-bottom:20px;">Preview not available for zip/archive files.</p>
                <a href="<?= e($fullPath) ?>" download class="btn btn-primary">
                    <i class="bi bi-download"></i> Download & Extract
                </a>
            </div>
            <div class="source-indicator"><i class="bi bi-file-zip"></i> Archive File</div>
            
        <?php 
        // Other files
        else: ?>
            <div style="padding:60px 40px; text-align:center; background:#f9f9f9; border-radius:16px;">
                <i class="bi <?= getFileIcon($material['file_name']) ?>" style="font-size:64px;color:var(--muted);display:block;margin-bottom:16px"></i>
                <p>Preview not available for this file type.</p>
                <p style="font-size:12px;color:var(--muted); margin-bottom:20px;"><?= e($material['file_name']) ?></p>
                <a href="<?= e($fullPath) ?>" download class="btn btn-primary">
                    <i class="bi bi-download"></i> Download File
                </a>
            </div>
            <div class="source-indicator"><i class="bi bi-file-earmark"></i> <?= strtoupper($ext) ?> File</div>
        <?php endif; ?>
    <?php endif; ?>
</div>
                
                <div class="action-buttons">
                    <?php if ($isUrl): ?>
                        <a href="<?= e($materialUrl) ?>" target="_blank" class="btn btn-primary">
                            <i class="bi bi-box-arrow-up-right"></i> Open Link
                        </a>
                    <?php else: ?>
                        <a href="<?= e($filePath) ?>" download class="btn btn-primary">
                            <i class="bi bi-download"></i> Download
                        </a>
                        <?php if ($material['file_name'] && in_array(strtolower(pathinfo($material['file_name'], PATHINFO_EXTENSION)), ['pdf', 'jpg', 'jpeg', 'png', 'mp4', 'mp3', 'wav', 'ogg', 'mov', 'avi', 'mkv'])): ?>
                            <a href="<?= e($filePath) ?>" target="_blank" class="btn btn-secondary">
                                <i class="bi bi-eye"></i> Open in New Tab
                            </a>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- SIDEBAR - Right side -->
            <div class="material-sidebar">
                <!-- Tutor Info Card -->
                <div class="info-card">
                    <h4>Shared by</h4>
                    <div class="tutor-section">
                        <img src="<?= !empty($material['tutor_pic']) ? '../uploads/profiles/' . e($material['tutor_pic']) : $assetBase . '/profile-tutor.png' ?>">
                        <div>
                            <div class="tutor-name"><?= e($material['tutor_name']) ?></div>
                            <div class="tutor-role">Language Tutor</div>
                        </div>
                    </div>
                    <?php if (!empty($material['tutor_phone'])): ?>
                    <?php 
                        $whatsappNumber = preg_replace('/[^0-9]/', '', $material['tutor_phone']);
                        $whatsappMessage = "Hi " . urlencode($material['tutor_name']) . "! I have a question about the material \"" . urlencode($material['title']) . "\" (Student: " . urlencode($displayName) . ")";
                    ?>
                    <a href="https://wa.me/<?= e($whatsappNumber) ?>?text=<?= $whatsappMessage ?>" 
                       target="_blank" class="btn btn-secondary" style="width:100%; justify-content:center; margin-top:8px;">
                        <i class="bi bi-whatsapp"></i> Message on WhatsApp
                    </a>
                    <?php else: ?>
                    <button class="btn btn-secondary" onclick="showToast('Tutor WhatsApp number not available')" style="width:100%; justify-content:center; margin-top:8px; opacity:0.6;">
                        <i class="bi bi-whatsapp"></i> WhatsApp Unavailable
                    </button>
                    <?php endif; ?>
                </div>
                
                <!-- Material Info Card -->
                <div class="info-card">
                    <h4>Material Details</h4>
                    <div class="info-row">
                        <i class="bi bi-translate"></i>
                        <span class="label">Language</span>
                        <span class="value"><?= e($material['language'] ?? 'General') ?></span>
                    </div>
                    <div class="info-row">
                        <i class="bi bi-calendar"></i>
                        <span class="label">Uploaded</span>
                        <span class="value"><?= formatDate($material['uploaded_at']) ?></span>
                    </div>
                    <?php if (!$isUrl && $material['file_size']): ?>
                    <div class="info-row">
                        <i class="bi bi-database"></i>
                        <span class="label">File Size</span>
                        <span class="value"><?= formatFileSize($material['file_size']) ?></span>
                    </div>
                    <?php endif; ?>
                    <div class="info-row">
                        <i class="bi bi-file-earmark"></i>
                        <span class="label">File Type</span>
                        <span class="value"><?= $isUrl ? 'External Link' : strtoupper(pathinfo($material['file_name'] ?? '', PATHINFO_EXTENSION)) ?></span>
                    </div>
                    <div class="info-row">
                        <i class="bi bi-tag"></i>
                        <span class="label">Session Type</span>
                        <span class="value">
                            <?php if ($material['material_type'] === 'pre'): ?>
                                <span class="session-badge session-pre">Pre-Session</span>
                            <?php elseif ($material['material_type'] === 'post'): ?>
                                <span class="session-badge session-post">Post-Session</span>
                            <?php else: ?>
                                General
                            <?php endif; ?>
                        </span>
                    </div>
                    <?php if (!empty($material['focus'])): ?>
                    <div class="info-row">
                        <i class="bi bi-bullseye"></i>
                        <span class="label">Focus Area</span>
                        <span class="value"><?= e($material['focus']) ?></span>
                    </div>
                    <?php endif; ?>
                </div>
                
                <!-- Session Info Card -->
                <?php if (!empty($material['booking_date'])): ?>
                <div class="info-card">
                    <h4>Related Session</h4>
                    <div class="info-row">
                        <i class="bi bi-calendar3"></i>
                        <span class="label">Date</span>
                        <span class="value"><?= date('d M Y', strtotime($material['booking_date'])) ?></span>
                    </div>
                    <div class="info-row">
                        <i class="bi bi-clock"></i>
                        <span class="label">Time</span>
                        <span class="value"><?= date('g:i A', strtotime($material['booking_time'])) ?></span>
                    </div>
                    <div class="info-row">
                        <i class="bi bi-laptop"></i>
                        <span class="label">Mode</span>
                        <span class="value"><?= $material['learning_mode'] === 'online' ? 'Online' : 'Face to Face' ?></span>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="toast" id="toast"></div>

<script>
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
function showToast(msg) {
  const t = document.getElementById('toast');
  t.textContent = msg; t.classList.add('show');
  setTimeout(() => t.classList.remove('show'), 3000);
}
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