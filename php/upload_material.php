<?php
session_start();
include 'config.php';
include 'insert_notification.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require '../vendor/autoload.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'tutor') {
    header("Location: login.php");
    exit();
}

$userID     = $_SESSION['user_id'];
$booking_id = intval($_GET['booking_id'] ?? 0);

if (!$booking_id) {
    header("Location: booking_requests.php");
    exit();
}

$assetBase = '../assets/img';
    // Determine where to go back
    $referer = $_SERVER['HTTP_REFERER'] ?? '';
    if (strpos($referer, 'material_overview.php') !== false) {
        $backUrl = 'material_overview.php';
    } elseif (strpos($referer, 'select_booking.php') !== false) {
        $backUrl = 'select_booking.php?action=upload';
    } else {
        $backUrl = 'learning_materials.php?booking_id=' . $booking_id;
    }

// Get tutor info
$stmt = $conn->prepare("SELECT * FROM users WHERE id = ? AND role = 'tutor'");
$stmt->bind_param("i", $userID);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
if (!$user) { header("Location: login.php"); exit(); }

$displayName = $user['fullname'];
$profilePic  = !empty($user['profile_pic'])
    ? '../uploads/profiles/' . $user['profile_pic']
    : $assetBase . '/profile-tutor.png';
$tutorName   = $displayName;

// Get booking + student info
$stmt = $conn->prepare("
    SELECT b.*, 
           u.fullname AS student_name,
           u.email    AS student_email,
           u.id       AS student_id,
           b.focus,
           b.proficiency_level
    FROM bookings b
    JOIN users u ON b.student_id = u.id
    WHERE b.id = ? AND b.tutor_id = ?
");
$stmt->bind_param("ii", $booking_id, $userID);
$stmt->execute();
$booking = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$booking) { header("Location: booking_requests.php"); exit(); }

function e($v) { return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }

// Email helper
function sendMaterialEmail($booking, $title, $description, $material_type, $tutorName) {
    $mail      = new PHPMailer(true);
    $typeLabel = $material_type === 'pre' ? 'Pre-Session' : 'Post-Session';
    $hint      = $material_type === 'pre'
        ? '📖 Please review this before your next session.'
        : '✅ Review this after your session.';
    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = 'tls';
        $mail->Port       = 587;
        $mail->setFrom('sohisabella87@gmail.com', 'Kyoshi');
        $mail->addAddress($booking['student_email'], $booking['student_name']);
        $mail->Subject = "New {$typeLabel} Material from {$tutorName}";
        $mail->isHTML(true);
        $mail->Body = "
        <div style='font-family:Segoe UI,sans-serif;max-width:560px;margin:auto;
                    border:1px solid #e0e0e0;border-radius:16px;padding:28px;background:#fff;'>
            <h2 style='color:#1d3156;margin-bottom:16px;'>New Learning Material! 📚</h2>
            <p>Dear <strong>{$booking['student_name']}</strong>,</p>
            <p>Your tutor <strong>{$tutorName}</strong> has uploaded a new
            <strong>{$typeLabel}</strong> material for your
            <strong>{$booking['language']}</strong> session.</p>
            <div style='background:#eef2ff;border-radius:12px;padding:16px;margin:16px 0;'>
                <p style='margin:0;font-weight:700;color:#1d3156;'>" . htmlspecialchars($title) . "</p>
                " . (!empty($description) ? "<p style='margin:8px 0 0;font-size:13px;'>" . htmlspecialchars($description) . "</p>" : "") . "
            </div>
            <div style='background:#f0f9ff;border-radius:12px;padding:12px;margin-bottom:20px;'>
                <p style='margin:0;font-size:13px;color:#0284c7;'>{$hint}</p>
            </div>
            <div style='text-align:center;'>
                <a href='http://localhost/kyoshi/php/learning_materials.php?booking_id={$booking['id']}'
                   style='display:inline-block;padding:12px 30px;background:#1d3156;
                          color:white;border-radius:30px;text-decoration:none;'>View Material</a>
            </div>
        </div>";
        $mail->send();
    } catch (Exception $e) {
        error_log("Material email failed: " . $e->getMessage());
    }
}

// Handle POST
$message     = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title         = trim($_POST['title'] ?? '');
    $description   = trim($_POST['description'] ?? '');
    $feedback      = trim($_POST['feedback'] ?? '');
    $material_type = $_POST['material_type'] ?? 'pre';
    $url_type      = $_POST['url_type'] ?? 'file';
    $proficiency_level = $_POST['proficiency_level'] ?? 'beginner';
    
    if (empty($title)) {
        $message = "Please enter a title.";
        $messageType = "error";
    } elseif ($url_type === 'url') {
        $material_url = trim($_POST['material_url'] ?? '');
        if (empty($material_url)) {
            $message = "Please enter a URL.";
            $messageType = "error";
        } elseif (!filter_var($material_url, FILTER_VALIDATE_URL)) {
            $message = "Please enter a valid URL (http:// or https://).";
            $messageType = "error";
        } else {
           $stmt = $conn->prepare("
    INSERT INTO learning_materials
                (tutor_id, student_id, booking_id, title, description, feedback, material_url,
                is_url, file_type, material_type, proficiency_level, uploaded_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, 1, 'url', ?, ?, NOW())
        ");
        $stmt->bind_param("iiissssss",
            $userID,                    // tutor_id
            $booking['student_id'],     // student_id
            $booking_id,                // booking_id
            $title,                     // title
            $description,               // description
            $feedback,                  // feedback
            $material_url,              // material_url
            $material_type,             // material_type
            $proficiency_level          // proficiency_level
        );
                    
            if ($stmt->execute()) {
                $stmt->close();
                insertNotification($conn, $booking['student_id'],
                    "New Learning Material", "{$tutorName} uploaded: {$title}", "view_material.php?id={$booking_id}");
                sendMaterialEmail($booking, $title, $description, $material_type, $tutorName);
                header("Location: " . $backUrl . (strpos($backUrl, '?') !== false ? '&' : '?') . "success=Material added!");
                exit();
            } else {
                $message = "Database error: " . $conn->error;
                $messageType = "error";
            }
        }
    } else {
        if (!isset($_FILES['material_file']) || $_FILES['material_file']['error'] !== UPLOAD_ERR_OK) {
            $message = "Please select a file.";
            $messageType = "error";
        } elseif ($_FILES['material_file']['size'] > 50 * 1024 * 1024) {
            $message = "File too large. Max 50MB.";
            $messageType = "error";
        } else {
            $uploadDir = '../uploads/materials/';
            if (!file_exists($uploadDir)) mkdir($uploadDir, 0777, true);
            $fileExt = strtolower(pathinfo($_FILES['material_file']['name'], PATHINFO_EXTENSION));
            $fileName = time() . '_' . bin2hex(random_bytes(8)) . '.' . $fileExt;
            $filePath = $uploadDir . $fileName;
            
            if (move_uploaded_file($_FILES['material_file']['tmp_name'], $filePath)) {
                $origName = $_FILES['material_file']['name'];
                $fileType = $_FILES['material_file']['type'];
                $fileSize = $_FILES['material_file']['size'];
                $stmt = $conn->prepare("
                    INSERT INTO learning_materials
                        (tutor_id, student_id, booking_id, title, description, feedback,
                        file_name, file_path, file_type, file_size,
                        is_url, material_type, proficiency_level, uploaded_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, NOW())
                ");
                $stmt->bind_param("iiissssssiss", 
                    $userID,                    // tutor_id
                    $booking['student_id'],     // student_id - THIS WAS MISSING
                    $booking_id,                // booking_id
                    $title,                     // title
                    $description,               // description
                    $feedback,                  // feedback
                    $origName,                  // file_name
                    $filePath,                  // file_path
                    $fileType,                  // file_type
                    $fileSize,                  // file_size
                    $material_type,             // material_type
                    $proficiency_level          // proficiency_level
                );
                            
                if ($stmt->execute()) {
                    $stmt->close();
                    insertNotification($conn, $booking['student_id'],
                        "New Learning Material", "{$tutorName} uploaded: {$title}", "learning_materials.php?booking_id={$booking_id}");
                    sendMaterialEmail($booking, $title, $description, $material_type, $tutorName);
                    header("Location: " . $backUrl . (strpos($backUrl, '?') !== false ? '&' : '?') . "success=Material added!");
                    exit();
                } else {
                    $message = "Database error.";
                    $messageType = "error";
                    unlink($filePath);
                }
            } else {
                $message = "Upload failed.";
                $messageType = "error";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Upload Material · Kyoshi</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        body {
            font-family:'Poppins',sans-serif;
            background: url('../assets/img/background2.png') no-repeat center top;
            background-size: cover;
            min-height: 100vh;
        }
        body::before {
            content:''; position:fixed; inset:0;
            background: rgba(255,255,255,0.25); z-index:-1;
        }
        .topbar {
            width:100%; background: rgba(254,214,206,0.92);
            backdrop-filter: blur(12px); position:sticky; top:0; z-index:999;
            box-shadow: 0 2px 20px rgba(0,0,0,0.08);
            border-bottom: 1px solid rgba(255,255,255,0.3);
        }
        .container { width:min(1400px,94%); margin:auto; }
        .nav {
            display:flex; justify-content:space-between;
            align-items:center; gap:32px; min-height:70px;
        }
        .brand {
            display:flex; align-items:center; gap:10px; text-decoration:none;
        }
        .brand strong { display:block; color:#1d3156; font-size:20px; }
        .brand span { color:#496894; font-size:11px; font-weight:600; }
        .nav-links { display:flex; gap:28px; align-items:center; }
        .nav-links a {
            text-decoration:none; color:#1d3156; font-size:14px;
            font-weight:600; transition:0.25s; padding:6px 0;
        }
        .nav-links a:hover, .nav-links a.active { color:#496894; }
        .profile {
            display:flex; align-items:center; gap:8px;
            background:rgba(255,255,255,0.12); border:1px solid rgba(255,255,255,0.2);
            padding:6px 14px 6px 8px; border-radius:40px;
            cursor:pointer; transition:0.25s;
        }
        .profile:hover { background:rgba(255,255,255,0.2); }
        .profile img { width:36px; height:36px; border-radius:50%; object-fit:cover; }
        .profile span { font-size:13px; font-weight:500; color:#1d3156; }
        .dropdown {
            position:absolute; top:calc(100% + 10px); right:0;
            width:220px; background:white; border-radius:16px;
            overflow:hidden; display:none; border:1px solid #e2edf7;
            box-shadow:0 15px 35px rgba(0,0,0,0.15); z-index:1000;
        }
        .dropdown a {
            display:flex; align-items:center; gap:12px;
            padding:12px 18px; text-decoration:none;
            color:#1e293b; font-size:13px; font-weight:500;
        }
        .dropdown a:hover { background:#f8fafc; }
        .dropdown hr { margin:0; border-color:#ecf3f9; }
        .main { max-width:700px; margin:32px auto 60px; padding:0 20px; }
        .card { background:white; border-radius:24px; padding:28px; box-shadow:0 4px 20px rgba(0,0,0,0.08); }
        .btn-back {
            display:inline-flex; align-items:center; gap:6px;
            color:#64748b; text-decoration:none; margin-bottom:20px; font-size:13px;
        }
        .btn-back:hover { color:#E75A9B; }
        h1 { font-size:24px; color:#1d3156; margin-bottom:8px; }
        .subtitle { color:#64748b; font-size:14px; margin-bottom:24px; padding-bottom:16px; border-bottom:1px solid #eef2f7; }
        .alert { padding:12px 16px; border-radius:12px; margin-bottom:20px; display:flex; align-items:center; gap:10px; }
        .alert-success { background:#d4edda; color:#155724; border-left:4px solid #28a745; }
        .alert-error { background:#f8d7da; color:#721c24; border-left:4px solid #dc3545; }
        .form-group { margin-bottom:20px; }
        label { display:block; font-size:13px; font-weight:600; margin-bottom:6px; color:#1d3156; }
        .form-control, select {
            width:100%; padding:12px 14px; border:1px solid #cbd5e1; border-radius:12px;
            font-family:'Poppins',sans-serif; font-size:14px;
        }
        .form-control:focus, select:focus { outline:none; border-color:#E75A9B; }
        textarea.form-control { resize:vertical; min-height:100px; }
        .form-hint { font-size:11px; color:#94a3b8; margin-top:5px; }
        .toggle-row { display:flex; gap:12px; margin-bottom:16px; }
        .toggle-btn {
            flex:1; text-align:center; padding:10px; border-radius:30px;
            border:1px solid #cbd5e1; background:#f8fafc; cursor:pointer;
            font-size:13px; font-weight:600; transition:all 0.2s;
        }
        .toggle-btn.active { background:#E75A9B; color:white; border-color:#E75A9B; }
        .drop-zone {
            border:2px dashed #cbd5e1; border-radius:16px; padding:30px;
            text-align:center; cursor:pointer; transition:0.2s;
        }
        .drop-zone:hover { border-color:#E75A9B; background:#fef2f8; }
        .drop-zone i { font-size:36px; color:#E75A9B; margin-bottom:8px; display:block; }
        .drop-zone p { font-size:13px; color:#64748b; }
        .drop-zone small { font-size:11px; color:#94a3b8; }
        .drop-wrapper { position:relative; }
        .drop-wrapper input[type=file] { position:absolute; inset:0; opacity:0; cursor:pointer; }
        .file-preview {
            display:none; margin-top:10px; padding:10px 12px;
            background:#eef2ff; border-radius:12px; font-size:13px;
            align-items:center; gap:10px;
        }
        .hidden { display:none !important; }
        .btn-submit {
            width:100%; padding:14px; background:linear-gradient(135deg,#E75A9B,#F28AB2);
            color:white; border:none; border-radius:30px; font-size:15px;
            font-weight:700; cursor:pointer; transition:0.2s; margin-top:10px;
        }
        .btn-submit:hover { transform:translateY(-2px); opacity:0.95; }
        .info-bar {
            background:#f0f9ff; border-radius:12px; padding:12px 16px;
            margin-bottom:20px; display:flex; align-items:center; gap:12px;
        }
        .toast-message.info {
            background: #64748b;
        }
        .info-bar i { font-size:24px; color:#0284c7; }
        .info-bar strong { display:block; font-size:14px; color:#1d3156; }
        .info-bar span { font-size:12px; color:#64748b; }
        .toast-message {
            position: fixed;
            bottom: 30px;
            right: 30px;
            background: #28a745;
            color: white;
            padding: 12px 20px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 14px;
            font-weight: 500;
            z-index: 9999;
            animation: slideIn 0.3s ease;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        .toast-message.error {
            background: #dc3545;
        }

        .file-preview-remove {
    background: none;
    border: none;
    color: #dc2626;
    cursor: pointer;
    font-size: 18px;
    padding: 0 5px;
    transition: 0.2s;
}
.file-preview-remove:hover {
    color: #b91c1c;
    transform: scale(1.1);
}
.file-preview {
    display: none;
    margin-top: 10px;
    padding: 10px 12px;
    background: #eef2ff;
    border-radius: 12px;
    font-size: 13px;
    align-items: center;
    gap: 10px;
    justify-content: space-between;
}
        @keyframes slideIn {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
}
        
        @media (max-width:768px) {
            .main { padding:0 16px; }
            .card { padding:20px; }
            .nav-links { display:none; }
            .toast-message { bottom: 20px; right: 20px; left: 20px; text-align: center; }
        }
    </style>
</head>
<body>

<header class="topbar">
    <div class="container">
        <nav class="nav">
                <a href="tutor_dashboard.php" class="brand">
    <img src="<?= e($assetBase) ?>/logo.png" alt="Kyoshi" style="width: 42px; height: 42px; object-fit: contain;">
    <div>
        <strong>Kyoshi</strong>
        <span>Teacher Space</span>
    </div>
</a>
            </a>
            <div class="nav-links">
                <a href="tutor_dashboard.php">Dashboard</a>
                <a href="booking_requests.php">My Bookings</a>
                <a href="material_overview.php" class="active">My Materials</a>
                <a href="assignment_overview.php">My Assignments</a>
                <a href="view_session_reports.php">My Reports</a>
            </div>
            <div style="position:relative;">
                <button class="profile" onclick="toggleDropdown()">
                    <img src="<?= e($profilePic) ?>" alt="">
                    <span><?= e($displayName) ?></span>
                    <i class="bi bi-chevron-down"></i>
                </button>
                <div class="dropdown" id="profileDropdown">
                    <a href="teacher_profile.php"><i class="bi bi-person-circle"></i> My Profile</a>
                    <a href="earnings.php"><i class="bi bi-wallet2"></i> My Earnings</a>
                    <hr>
                    <a href="logout.php" style="color:#dc2626;"><i class="bi bi-box-arrow-right"></i> Logout</a>
                </div>
            </div>
        </nav>
    </div>
</header>

<div class="main">
    <div class="card">
        <a href="<?= $backUrl ?>" class="btn-back">
    <i class="bi bi-arrow-left"></i> Back
</a>
        <h1><i class="bi bi-cloud-upload"></i> Upload Material</h1>
        <p class="subtitle">Share files or links with your student</p>

        <div class="info-bar">
            <i class="bi bi-person-circle"></i>
            <div>
                <strong><?= e($booking['student_name']) ?></strong>
                <span><?= e($booking['language']) ?> · <?= date('d M Y', strtotime($booking['booking_date'])) ?></span>
                <?php if (!empty($booking['focus'])): ?>
            <br><span>Focus on <?= e($booking['focus']) ?></span>
        <?php endif; ?>
        <?php if (!empty($booking['proficiency_level'])): ?>
            <span> · Level - <?= e($booking['proficiency_level']) ?></span>
        <?php endif; ?>
            </div>
        </div>

        <form method="POST" enctype="multipart/form-data" id="uploadForm">
            <div class="form-group">
                <label>Material Type</label>
                <div class="toggle-row">
                    <div class="toggle-btn active" data-type="pre">Pre-Session</div>
                    <div class="toggle-btn" data-type="post">Post-Session</div>
                </div>
                <input type="hidden" name="material_type" id="materialType" value="pre">
            </div>

            <div class="form-group">
                <label>Title</label>
                <input type="text" name="title" id="titleInput" class="form-control" placeholder="e.g., Lesson 1 Notes" required>
            </div>

            <div class="form-group">
                <label>Description (optional)</label>
                <textarea name="description" id="descInput" class="form-control" placeholder="Describe what material is this..."></textarea>
            </div>

            <div class="form-group">
                <label>Instruction (optional)</label>
                <textarea name="feedback" id="feedbackInput" class="form-control" placeholder="Add feedback or instructions for the student..."></textarea>
            </div>

            <div class="form-group">
                <label>Content Type</label>
                <div class="toggle-row">
                    <div class="toggle-btn active" data-content="file">📁 File</div>
                    <div class="toggle-btn" data-content="url">🔗 URL </div>
                </div>
                <input type="hidden" name="url_type" id="urlType" value="file">
            </div>

            <div id="fileSection">
                <div class="drop-wrapper">
                    <div class="drop-zone" id="dropZone">
                        <i class="bi bi-cloud-arrow-up"></i>
                        <p>Drag & drop or click to browse</p>
                        <small>PDF, Word, PPT, Images, MP4, ZIP (Max 50MB)</small>
                    </div>
                    <input type="file" name="material_file" id="fileInput" accept=".pdf,.doc,.docx,.ppt,.pptx,.jpg,.jpeg,.png,.mp4,.mp3,.zip" onchange="previewFile(this)">
                </div>
                <div class="file-preview" id="filePreview">
                    <i class="bi bi-file-earmark-check"></i>
                    <div style="flex: 1;"><strong id="fileName"></strong> <span id="fileSize"></span></div>
                    <button type="button" class="file-preview-remove" id="removeFileBtn" style="background: none; border: none; color: #dc2626; cursor: pointer; font-size: 18px;" onclick="removeSelectedFile()">
                        <i class="bi bi-x-circle"></i>
                    </button>
                </div>
            </div>

            <div id="urlSection" class="hidden">
                <div class="form-group">
                    <label>URL</label>
                    <input type="url" name="material_url" id="urlInput" class="form-control" placeholder="https://...">
                </div>
            </div>

            <button type="submit" class="btn-submit" id="submitBtn">
                <i class="bi bi-send"></i> Upload 
            </button>
        </form>
    </div>
</div>

<script>
    // Toast notification function
    function showToast(message, type = 'success') {
        const existingToast = document.querySelector('.toast-message');
        if (existingToast) existingToast.remove();
        
        const toast = document.createElement('div');
        toast.className = 'toast-message ' + type;
        toast.innerHTML = `<i class="bi bi-${type === 'success' ? 'check-circle-fill' : 'exclamation-circle-fill'}"></i> ${message}`;
        document.body.appendChild(toast);
        
        setTimeout(() => {
            toast.style.opacity = '0';
            setTimeout(() => toast.remove(), 500);
        }, 4000);
    }

    function toggleDropdown() {
        const d = document.getElementById('profileDropdown');
        d.style.display = d.style.display === 'block' ? 'none' : 'block';
    }
    
    window.addEventListener('click', function(e) {
        const btn = document.querySelector('.profile');
        const dd = document.getElementById('profileDropdown');
        if (btn && dd && !btn.contains(e.target) && !dd.contains(e.target)) dd.style.display = 'none';
    });

    // Material Type Toggle
    document.querySelectorAll('[data-type]').forEach(btn => {
        btn.addEventListener('click', function() {
            document.querySelectorAll('[data-type]').forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            document.getElementById('materialType').value = this.dataset.type;
        });
    });

    // Content Type Toggle
    document.querySelectorAll('[data-content]').forEach(btn => {
        btn.addEventListener('click', function() {
            document.querySelectorAll('[data-content]').forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            const type = this.dataset.content;
            document.getElementById('urlType').value = type;
            const fileSection = document.getElementById('fileSection');
            const urlSection = document.getElementById('urlSection');
            if (type === 'url') {
                fileSection.classList.add('hidden');
                urlSection.classList.remove('hidden');
            } else {
                urlSection.classList.add('hidden');
                fileSection.classList.remove('hidden');
            }
        });
    });

    function removeSelectedFile() {
    // Clear the file input
    const fileInput = document.getElementById('fileInput');
    fileInput.value = '';
    
    // Hide the preview
    const preview = document.getElementById('filePreview');
    preview.style.display = 'none';
    
    // Clear the file name and size display
    document.getElementById('fileName').textContent = '';
    document.getElementById('fileSize').textContent = '';
    
    showToast('File removed', 'info');
}

function previewFile(input) {
    const preview = document.getElementById('filePreview');
    const nameEl = document.getElementById('fileName');
    const sizeEl = document.getElementById('fileSize');
    if (input.files && input.files[0]) {
        const f = input.files[0];
        nameEl.textContent = f.name;
        sizeEl.textContent = '(' + (f.size / 1024 / 1024).toFixed(2) + ' MB)';
        preview.style.display = 'flex';
    } else {
        preview.style.display = 'none';
    }
}

    // Form validation before submit
    document.getElementById('uploadForm').addEventListener('submit', function(e) {
        const title = document.getElementById('titleInput').value.trim();
        if (!title) {
            e.preventDefault();
            showToast('Please enter a title', 'error');
            return;
        }
        
        const urlType = document.getElementById('urlType').value;
        if (urlType === 'url') {
            const url = document.getElementById('urlInput').value.trim();
            if (!url) {
                e.preventDefault();
                showToast('Please enter a URL', 'error');
                return;
            }
            if (!url.startsWith('http://') && !url.startsWith('https://')) {
                e.preventDefault();
                showToast('Please enter a valid URL (http:// or https://)', 'error');
                return;
            }
        } else {
            const fileInput = document.getElementById('fileInput');
            if (!fileInput.files || !fileInput.files[0]) {
                e.preventDefault();
                showToast('Please select a file to upload', 'error');
                return;
            }
        }
        
        const submitBtn = document.getElementById('submitBtn');
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<span class="loading"></span> Uploading...';
    });

    const dropZone = document.getElementById('dropZone');
    const fileInput = document.getElementById('fileInput');
    if (dropZone) {
        dropZone.addEventListener('dragover', e => e.preventDefault());
        dropZone.addEventListener('drop', e => {
            e.preventDefault();
            fileInput.files = e.dataTransfer.files;
            previewFile(fileInput);
        });
    }
</script>
</body>
</html>