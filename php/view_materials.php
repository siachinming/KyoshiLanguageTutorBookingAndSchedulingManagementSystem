<?php
session_start();
include 'config.php';
include 'check_login.php';

$assetBase = '../assets/img';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$material_id = intval($_GET['id'] ?? 0);
$booking_id = intval($_GET['booking_id'] ?? 0);
$action = $_GET['action'] ?? 'view';

if (!$material_id) {
    die("Material not found.");
}

// Get user info
$userID = $_SESSION['user_id'];
$userRole = $_SESSION['role'];

// Handle edit save
if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $description = trim($_POST['description']);
    $feedback = trim($_POST['feedback']);
    
    $stmt = $conn->prepare("UPDATE learning_materials SET description = ?, feedback = ? WHERE id = ?");
    $stmt->bind_param("ssi", $description, $feedback, $material_id);
    
    if ($stmt->execute()) {
        header("Location: view_materials.php?id=" . $material_id . "&booking_id=" . $booking_id . "&success=1");
        exit();
    }
}

// Get tutor/student info
if ($userRole === 'tutor') {
    $stmt = $conn->prepare("SELECT * FROM users WHERE id = ? AND role = 'tutor'");
    $stmt->bind_param("i", $userID);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $displayName = $user['fullname'] ?? '';
    $profilePic = !empty($user['profile_pic']) ? '../uploads/profiles/' . $user['profile_pic'] : $assetBase . '/profile-tutor.png';
} else {
    $stmt = $conn->prepare("SELECT * FROM users WHERE id = ? AND role = 'student'");
    $stmt->bind_param("i", $userID);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $displayName = $user['fullname'] ?? '';
    $profilePic = !empty($user['profile_pic']) ? '../uploads/profiles/' . $user['profile_pic'] : $assetBase . '/profile-student.png';
}

$stmt = $conn->prepare("
    SELECT lm.*, b.tutor_id, b.student_id, b.language, b.booking_date
    FROM learning_materials lm
    JOIN bookings b ON lm.booking_id = b.id
    WHERE lm.id = ?
");
$stmt->bind_param("i", $material_id);
$stmt->execute();
$material = $stmt->get_result()->fetch_assoc();

if (!$material) {
    die("Material not found.");
}

$hasAccess = false;
if ($userRole === 'tutor' && $material['tutor_id'] == $userID) {
    $hasAccess = true;
} elseif ($userRole === 'student' && $material['student_id'] == $userID) {
    $hasAccess = true;
}

if (!$hasAccess) {
    die("Access denied.");
}

// Fix the file path - remove double slash issue
$filePath = $material['file_path'];
// If path starts with '../uploads/', keep as is, otherwise add '../'
if (strpos($filePath, '../uploads/') !== 0 && strpos($filePath, 'uploads/') !== false) {
    $filePath = '../' . $filePath;
} elseif (strpos($filePath, 'uploads/') === 0) {
    $filePath = '../' . $filePath;
}

$success = isset($_GET['success']) ? true : false;

function formatFileSize($bytes) {
    if (!$bytes) return 'Unknown';
    if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024) return round($bytes / 1024, 1) . ' KB';
    return $bytes . ' bytes';
}

function e($value) {
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

$ext = strtolower(pathinfo($material['file_name'], PATHINFO_EXTENSION));

// For PDF, images, text - direct display (stream)
if ($action === 'view' && in_array($ext, ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'txt'])) {
    if (file_exists($filePath)) {
        if ($ext === 'pdf') {
            header('Content-Type: application/pdf');
            header('Content-Disposition: inline; filename="' . $material['file_name'] . '"');
            readfile($filePath);
            exit();
        } elseif (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
            header('Content-Type: ' . mime_content_type($filePath));
            header('Content-Disposition: inline; filename="' . $material['file_name'] . '"');
            readfile($filePath);
            exit();
        } elseif ($ext === 'txt') {
            header('Content-Type: text/plain');
            header('Content-Disposition: inline; filename="' . $material['file_name'] . '"');
            readfile($filePath);
            exit();
        }
    }
}

// Create a direct file URL for video/audio
$fileUrl = 'download_material.php?id=' . $material_id . '&booking_id=' . $booking_id . '&inline=1';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
<meta http-equiv="Pragma" content="no-cache">
<meta http-equiv="Expires" content="0">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Preview: <?= e($material['title']) ?> · Kyoshi</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../css/style.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Poppins', sans-serif;
            background: url('../assets/img/background2.png') no-repeat center center fixed;
            background-size: cover;
            min-height: 100vh;
            position: relative;
        }
        body::before {
            content: '';
            position: fixed;
            inset: 0;
            background: rgba(255, 255, 255, 0.25);
            z-index: -1;
        }
        
        .topbar {
            width: 100%;
            background: rgba(254, 214, 206, 0.92);
            backdrop-filter: blur(12px);
            position: sticky;
            top: 0;
            z-index: 999;
            box-shadow: 0 2px 20px rgba(0, 0, 0, 0.08);
            border-bottom: 1px solid rgba(255, 255, 255, 0.3);
        }
        .container { width: min(1400px, 94%); margin: auto; }
        .nav {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 32px;
            min-height: 70px;
        }
        .brand {
            display: flex;
            align-items: center;
            gap: 10px;
            text-decoration: none;
        }
        .brand img { width: 42px; height: 42px; object-fit: contain; }
        .brand strong { display: block; color: #1d3156; font-size: 20px; }
        .brand span { color: #496894; font-size: 11px; font-weight: 600; }
        .nav-links { display: flex; gap: 28px; align-items: center; flex-wrap: wrap; }
        .nav-links a {
            text-decoration: none;
            color: #1d3156;
            font-size: 14px;
            font-weight: 600;
            transition: 0.25s;
            padding: 6px 0;
        }
        .nav-links a:hover, .nav-links a.active { color: #496894; }
        
        .profile {
            display: flex;
            align-items: center;
            gap: 8px;
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            padding: 6px 14px 6px 8px;
            border-radius: 40px;
            cursor: pointer;
            transition: 0.25s;
        }
        .profile:hover { background: rgba(255, 255, 255, 0.2); }
        .profile img { width: 36px; height: 36px; border-radius: 50%; object-fit: cover; }
        .profile span { font-size: 13px; font-weight: 500; color: #1d3156; }
        
        .dropdown {
            position: absolute;
            top: calc(100% + 10px);
            right: 0;
            width: 220px;
            background: white;
            border-radius: 16px;
            overflow: hidden;
            display: none;
            border: 1px solid #e2edf7;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.15);
            z-index: 1000;
        }
        .dropdown a {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 18px;
            text-decoration: none;
            color: #1e293b;
            font-size: 13px;
            font-weight: 500;
        }
        .dropdown a:hover { background: #f8fafc; }
        .dropdown hr { margin: 0; border-color: #ecf3f9; }
        
        .main-content {
            max-width: 1000px;
            margin: 32px auto 60px;
            padding: 0 20px;
        }
        
        .card {
            background: white;
            border-radius: 24px;
            padding: 32px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
        }
        
        .back-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: #e2e8f0;
            color: #1d3156;
            padding: 10px 20px;
            border-radius: 40px;
            text-decoration: none;
            font-weight: 600;
            font-size: 14px;
            margin-bottom: 24px;
            transition: 0.25s;
        }
        .back-btn:hover {
            background: #cbd5e1;
            transform: translateX(-3px);
        }
        
        h1 {
            font-size: 24px;
            font-weight: 700;
            color: #1d3156;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            justify-content: space-between;
        }
        
        .edit-btn {
            background: #fef3c7;
            color: #f59e0b;
            padding: 8px 20px;
            border-radius: 30px;
            text-decoration: none;
            font-size: 13px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: 0.2s;
            border: none;
            cursor: pointer;
        }
        .edit-btn:hover {
            background: #fde68a;
            transform: translateY(-2px);
        }
        
        .media-container {
    background: #f8fafc;
    border-radius: 16px;
    overflow: hidden;
    margin: 20px 0;
    text-align: center;
    min-height: 200px;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
}

video {
    width: 100%;
    max-height: 500px;
    background: #000;
    border-radius: 12px;
}

audio {
    width: 100%;
    max-width: 600px;
    margin: 20px auto;
    display: block;
    border-radius: 40px;
    background: #f1f5f9;
    padding: 10px;
}

audio::-webkit-media-controls-panel {
    background: #f1f5f9;
    border-radius: 40px;
}

.audio-placeholder {
    text-align: center;
    padding: 40px;
    background: #f8fafc;
    border-radius: 16px;
    width: 100%;
}

.audio-placeholder i {
    font-size: 64px;
    color: #E75A9B;
    margin-bottom: 16px;
    display: block;
}
        
        .info-box {
            background: #f8fafc;
            border-radius: 16px;
            padding: 20px;
            margin-top: 20px;
        }
        
        .info-row {
            display: flex;
            padding: 10px 0;
            border-bottom: 1px solid #eef2f7;
        }
        .info-row:last-child {
            border-bottom: none;
        }
        .info-label {
            font-weight: 600;
            color: #1d3156;
            width: 120px;
            flex-shrink: 0;
            font-size: 13px;
        }
        .info-value {
            color: #475569;
            font-size: 13px;
            word-break: break-word;
        }
        
        .description-box {
            background: #fefce8;
            border-left: 3px solid #f59e0b;
            border-radius: 12px;
            padding: 16px;
            margin-top: 20px;
        }
        
        .feedback-box {
            background: #e8f4f8;
            border-left: 3px solid #1d3156;
            border-radius: 12px;
            padding: 16px;
            margin-top: 16px;
        }
        
        .btn-download {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: linear-gradient(135deg, #1d3156, #496894);
            color: white;
            padding: 12px 24px;
            border-radius: 40px;
            text-decoration: none;
            font-weight: 600;
            font-size: 14px;
            margin-top: 20px;
            transition: 0.2s;
        }
        .btn-download:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        
        .edit-form {
            margin-top: 20px;
        }
        .edit-form textarea {
            width: 100%;
            padding: 12px;
            border: 1.5px solid #e2e8f0;
            border-radius: 12px;
            font-family: 'Poppins', sans-serif;
            font-size: 13px;
            resize: vertical;
        }
        .edit-form textarea:focus {
            outline: none;
            border-color: #E75A9B;
        }
        .form-actions {
            display: flex;
            gap: 12px;
            margin-top: 12px;
        }
        .btn-save {
            background: #28a745;
            color: white;
            padding: 8px 24px;
            border-radius: 30px;
            border: none;
            font-weight: 600;
            cursor: pointer;
        }
        .btn-cancel {
            background: #e2e8f0;
            color: #475569;
            padding: 8px 24px;
            border-radius: 30px;
            border: none;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
        }
        .alert-success {
            background: #d4edda;
            color: #155724;
            padding: 12px 16px;
            border-radius: 12px;
            margin-bottom: 20px;
        }
        
        @media (max-width: 768px) {
            .card { padding: 20px; }
            .info-row { flex-direction: column; gap: 5px; }
            .info-label { width: auto; }
            h1 { flex-direction: column; align-items: flex-start; }
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
            <a href="<?= $userRole === 'tutor' ? 'tutor_dashboard.php' : 'student_dashboard.php' ?>" class="brand">
                <img src="<?= e($assetBase) ?>/logo.png" alt="Kyoshi">
                <div>
                    <strong>Kyoshi</strong>
                    <span><?= $userRole === 'tutor' ? 'Teacher Space' : 'Student Space' ?></span>
                </div>
            </a>
            <div class="nav-links">
                <?php if ($userRole === 'tutor'): ?>
                    <a href="tutor_dashboard.php">Dashboard</a>
                    <a href="booking_requests.php">My Bookings</a>
                    <a class="active" href="learning_materials.php?booking_id=<?= $booking_id ?>">My Materials</a>
                    <a href="assignments.php">My Assignments</a>
                    <a href="view_session_reports.php">My Reports</a>
                <?php else: ?>
                    <a href="student_dashboard.php">Dashboard</a>
                    <a href="my_bookings.php">My Bookings</a>
                    <a href="student_learning_materials.php?booking_id=<?= $booking_id ?>" class="active">Materials</a>
                <?php endif; ?>
            </div>
            <div class="nav-actions">
            <div style="position:relative;">
                <button class="profile" onclick="toggleDropdown()">
                    <img src="<?= e($profilePic) ?>" alt="">
                    <span><?= e($displayName) ?></span>
                    <i class="bi bi-chevron-down"></i>
                </button>
                <div class="dropdown" id="profileDropdown">
                    <a href="<?= $userRole === 'tutor' ? 'teacher_profile.php' : 'student_profile.php' ?>"><i class="bi bi-person-circle"></i> My Profile</a>
                     <a href="earnings.php">
                <i class="bi bi-wallet2"></i> My Earnings
            </a>
                    <hr>
                    <a href="logout.php" style="color:#dc2626;"><i class="bi bi-box-arrow-right"></i> Logout</a>
                </div>
            </div>
                </div>
        </nav>
    </div>
</header>
<div class="nav-overlay" id="navOverlay"></div>
<div class="main-content">
    <div class="card">
        <a href="material_overview.php" class="back-btn">
            <i class="bi bi-arrow-left"></i> Back to Materials
        </a>
        
        <?php if ($success): ?>
            <div class="alert-success">
                <i class="bi bi-check-circle"></i> Material updated successfully!
            </div>
        <?php endif; ?>
        
        <h1>
            <span>
                <i class="bi <?= in_array($ext, ['mp4', 'mov', 'avi', 'mkv', 'webm']) ? 'bi-camera-video' : (in_array($ext, ['mp3', 'wav', 'ogg', 'm4a']) ? 'bi-mic' : 'bi-file-earmark') ?>"></i>
                <?= e($material['title']) ?>
            </span>
            <?php if ($userRole === 'tutor'): ?>
                <button class="edit-btn" onclick="toggleEditMode()">
                    <i class="bi bi-pencil"></i> Edit Description & Feedback
                </button>
            <?php endif; ?>
        </h1>
        
        <!-- Video or Audio Player -->
<div class="media-container">
    <?php if (in_array($ext, ['mp4', 'mov', 'avi', 'mkv', 'webm'])): ?>
        <video controls style="width: 100%; max-height: 500px;">
            <source src="<?= e($fileUrl) ?>" type="video/mp4">
            Your browser does not support the video tag.
        </video>
    <?php elseif (in_array($ext, ['mp3', 'wav', 'ogg', 'm4a'])): ?>
        <div class="audio-placeholder">
            <i class="bi bi-mic"></i>
            <h4 style="color: #1d3156; margin-bottom: 20px;">Audio Player</h4>
            <audio controls style="width: 100%;">
                <source src="<?= e($fileUrl) ?>" type="audio/mpeg">
                Your browser does not support the audio tag.
            </audio>
            <p style="font-size: 12px; color: #64748b; margin-top: 16px;">
                Audio file - <?= e($material['file_name']) ?>
            </p>
        </div>
    <?php else: ?>
        <div style="padding: 60px; text-align: center; background: #f8fafc; width: 100%; border-radius: 16px;">
            <i class="bi bi-file-earmark" style="font-size: 48px; color: #cbd5e1;"></i>
            <p style="margin-top: 16px;">Preview not available for this file type.</p>
            <a href="download_material.php?id=<?= $material_id ?>&booking_id=<?= $booking_id ?>" class="btn-download" style="margin-top: 16px; display: inline-flex;">
                <i class="bi bi-download"></i> Download File
            </a>
        </div>
    <?php endif; ?>
</div>
        
        
        <!-- File Information -->
        <div class="info-box">
            <div class="info-row">
                <div class="info-label"><i class="bi bi-file-earmark"></i> File Name:</div>
                <div class="info-value"><?= e($material['file_name']) ?></div>
            </div>
            <div class="info-row">
                <div class="info-label"><i class="bi bi-database"></i> File Size:</div>
                <div class="info-value"><?= formatFileSize($material['file_size']) ?></div>
            </div>
            <div class="info-row">
                <div class="info-label"><i class="bi bi-calendar"></i> Uploaded:</div>
                <div class="info-value"><?= date('d M Y, g:i A', strtotime($material['uploaded_at'])) ?></div>
            </div>
            <div class="info-row">
                <div class="info-label"><i class="bi bi-translate"></i> Language:</div>
                <div class="info-value"><?= e($material['language'] ?? 'Not specified') ?></div>
            </div>
            <div class="info-row">
                <div class="info-label"><i class="bi bi-calendar-event"></i> Session:</div>
                <div class="info-value"><?= date('d M Y', strtotime($material['booking_date'])) ?></div>
            </div>
        </div>
        
        <!-- Description and Feedback - View Mode -->
        <div id="viewMode">
            <?php if (!empty($material['description'])): ?>
                <div class="description-box">
                    <div style="font-size: 12px; font-weight: 600; color: #f59e0b; margin-bottom: 8px;">
                        <i class="bi bi-text-paragraph"></i> Description
                    </div>
                    <div style="font-size: 14px; color: #475569; line-height: 1.5;">
                        <?= nl2br(e($material['description'])) ?>
                    </div>
                </div>
            <?php endif; ?>
            
            <?php if (!empty($material['feedback'])): ?>
                <div class="feedback-box">
                    <div style="font-size: 12px; font-weight: 600; color: #1d3156; margin-bottom: 8px;">
                        <i class="bi bi-chat-dots"></i> Instruction / Feedback
                    </div>
                    <div style="font-size: 14px; color: #475569; line-height: 1.5;">
                        <?= nl2br(e($material['feedback'])) ?>
                    </div>
                </div>
            <?php endif; ?>
            
            <?php if (empty($material['description']) && empty($material['feedback'])): ?>
                <div class="description-box" style="background: #f8fafc; border-left-color: #cbd5e1;">
                    <div style="font-size: 13px; color: #94a3b8; text-align: center;">
                        <i class="bi bi-info-circle"></i> No description or feedback added yet.
                    </div>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Edit Mode Form (hidden by default) -->
        <div id="editMode" style="display: none;">
            <form method="POST" action="?id=<?= $material_id ?>&booking_id=<?= $booking_id ?>&action=save" class="edit-form">
                <div class="form-group">
                    <label style="font-weight: 600; font-size: 13px; color: #1d3156; margin-bottom: 6px; display: block;">
                        <i class="bi bi-text-paragraph"></i> Description
                    </label>
                    <textarea name="description" rows="4" placeholder="Describe what this material is about..."><?= e($material['description']) ?></textarea>
                </div>
                
                <div class="form-group">
                    <label style="font-weight: 600; font-size: 13px; color: #1d3156; margin-bottom: 6px; display: block;">
                        <i class="bi bi-chat-dots"></i> Instruction / Feedback
                    </label>
                    <textarea name="feedback" rows="4" placeholder="Add instructions or feedback for the student..."><?= e($material['feedback']) ?></textarea>
                </div>
                
                <div class="form-actions">
                    <button type="submit" class="btn-save"><i class="bi bi-check-lg"></i> Save Changes</button>
                    <button type="button" class="btn-cancel" onclick="toggleEditMode()"><i class="bi bi-x-lg"></i> Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function toggleDropdown() {
    const dropdown = document.getElementById('profileDropdown');
    dropdown.style.display = dropdown.style.display === 'block' ? 'none' : 'block';
}

window.addEventListener('click', function(e) {
    const dropdown = document.getElementById('profileDropdown');
    const button = document.querySelector('.profile');
    if (button && !button.contains(e.target) && dropdown && !dropdown.contains(e.target)) {
        dropdown.style.display = 'none';
    }
});

function toggleEditMode() {
    const viewMode = document.getElementById('viewMode');
    const editMode = document.getElementById('editMode');
    
    if (viewMode.style.display === 'none') {
        viewMode.style.display = 'block';
        editMode.style.display = 'none';
    } else {
        viewMode.style.display = 'none';
        editMode.style.display = 'block';
    }
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