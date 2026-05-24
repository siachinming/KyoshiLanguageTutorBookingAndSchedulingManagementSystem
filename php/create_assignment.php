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
$assetBase = '../assets/img';

if (!$booking_id) {
    header("Location: booking_requests.php");
    exit();
}

// Get tutor info for nav
$stmtUser = $conn->prepare("SELECT * FROM users WHERE id = ? AND role = 'tutor'");
$stmtUser->bind_param("i", $userID);
$stmtUser->execute();
$user = $stmtUser->get_result()->fetch_assoc();
$displayName = $user['fullname'] ?? '';
$profilePic = !empty($user['profile_pic']) ? '../uploads/profiles/' . $user['profile_pic'] : $assetBase . '/profile-tutor.png';

// Get booking + student info
$stmt = $conn->prepare("
    SELECT b.*, 
           u.fullname AS student_name,
           u.email    AS student_email,
           u.id       AS student_id
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

// Email function for assignment
function sendAssignmentEmail($booking, $title, $description, $due_date, $tutorName) {
    $mail = new PHPMailer(true);
    $due_text = !empty($due_date) ? "<p><strong>Due Date:</strong> " . date('d M Y, g:i A', strtotime($due_date)) . "</p>" : "";
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
        $mail->Subject = "📝 New Assignment from {$tutorName}";
        $mail->isHTML(true);
        $mail->Body = "
        <div style='font-family:Segoe UI,sans-serif;max-width:560px;margin:auto;
                    border:1px solid #e0e0e0;border-radius:16px;padding:28px;background:#fff;'>
            <div style='text-align:center;margin-bottom:20px;'>
                <img src='http://localhost/kyoshi/assets/img/logo.png' alt='Kyoshi' style='height:50px;'>
                <h2 style='color:#1d3156;margin-top:12px;'>📝 New Assignment</h2>
            </div>
            <p>Dear <strong>{$booking['student_name']}</strong>,</p>
            <p>Your tutor <strong>{$tutorName}</strong> has created a new assignment for your
            <strong>{$booking['language']}</strong> session.</p>
            <div style='background:#eef2ff;border-radius:12px;padding:16px;margin:16px 0;'>
                <p style='margin:0;font-weight:700;color:#1d3156;'>" . htmlspecialchars($title) . "</p>
                " . (!empty($description) ? "<p style='margin:8px 0 0;font-size:13px;'>" . htmlspecialchars($description) . "</p>" : "") . "
                {$due_text}
            </div>
            <div style='text-align:center;'>
                <a href='http://localhost/kyoshi/php/assignments.php?booking_id={$booking['id']}'
                   style='display:inline-block;padding:12px 30px;background:#1d3156;
                          color:white;border-radius:30px;text-decoration:none;'>View Assignment</a>
            </div>
            <p style='font-size:12px;color:#aaa;text-align:center;margin-top:24px;'>Keep learning! 🚀</p>
        </div>";
        $mail->send();
    } catch (Exception $e) {
        error_log("Assignment email failed: " . $e->getMessage());
    }
}

// Handle POST
$message     = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title         = trim($_POST['title'] ?? '');
    $description   = trim($_POST['description'] ?? '');
    $due_date      = $_POST['due_date'] ?? '';
    $total_points  = intval($_POST['total_points'] ?? 100);
    $attachment_type = $_POST['attachment_type'] ?? 'none';
    
    if (empty($title)) {
        $message = "Please enter a title.";
        $messageType = "error";
    } else {
        $material_url = null;
        $file_name = null;
        $file_path = null;
        $file_size = null;
        $file_type = null;
        
        // Handle file upload
        if ($attachment_type === 'file' && isset($_FILES['assignment_file']) && $_FILES['assignment_file']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = '../uploads/assignments/';
            if (!file_exists($uploadDir)) mkdir($uploadDir, 0777, true);
            
            $fileExt = pathinfo($_FILES['assignment_file']['name'], PATHINFO_EXTENSION);
            $fileName = time() . '_' . bin2hex(random_bytes(8)) . '.' . $fileExt;
            $filePath = $uploadDir . $fileName;
            $originalName = $_FILES['assignment_file']['name'];
            $fileSize = $_FILES['assignment_file']['size'];
            $fileMime = $_FILES['assignment_file']['type'];
            
            if (move_uploaded_file($_FILES['assignment_file']['tmp_name'], $filePath)) {
                $file_name = $originalName;
                $file_path = $filePath;
                $file_size = $fileSize;
                $file_type = $fileMime;
            } else {
                $message = "Failed to upload file.";
                $messageType = "error";
            }
        } elseif ($attachment_type === 'url') {
            $material_url = trim($_POST['material_url'] ?? '');
            if (!empty($material_url) && !filter_var($material_url, FILTER_VALIDATE_URL)) {
                $message = "Please enter a valid URL.";
                $messageType = "error";
            }
        }
        
        if (empty($message)) {
            $stmt = $conn->prepare("
                INSERT INTO assignments (booking_id, tutor_id, title, description, due_date, total_points, 
                                        material_url, file_name, file_path, file_size, file_type, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $due_date_sql = !empty($due_date) ? $due_date : null;
            $stmt->bind_param("iisssisssis", 
                $booking_id, $userID, $title, $description, $due_date_sql, $total_points,
                $material_url, $file_name, $file_path, $file_size, $file_type
            );
            
            if ($stmt->execute()) {
                // Insert notification for student
                $due_text = !empty($due_date) ? " (Due: " . date('d M Y, g:i A', strtotime($due_date)) . ")" : "";
                $notification_title = "New Assignment: " . $title;
                $notification_message = "You have a new assignment: " . $title . $due_text;
                $notification_link = "assignments.php?booking_id=" . $booking_id;
                
                insertNotification($conn, $booking['student_id'], $notification_title, $notification_message, 'assignment', $notification_link);
                sendAssignmentEmail($booking, $title, $description, $due_date, $displayName);
                
                header("Location: assignments.php?booking_id=" . $booking_id . "&success=" . urlencode("Assignment created! Student notified."));
                exit();
            } else {
                $message = "Error: " . $conn->error;
                $messageType = "error";
                if ($file_path && file_exists($file_path)) unlink($file_path);
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
    <title>Create Assignment · Kyoshi</title>
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
        .brand img { width:42px; height:42px; object-fit:contain; }
        .brand strong { display:block; color:#1d3156; font-size:20px; }
        .brand span { color:#496894; font-size:11px; font-weight:600; }
        .nav-links { display:flex; gap:28px; align-items:center; flex-wrap:wrap; }
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
            overflow:hidden; display:none;
            border:1px solid #e2edf7;
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
        .info-bar i { font-size:24px; color:#0284c7; }
        .info-bar strong { display:block; font-size:14px; color:#1d3156; }
        .info-bar span { font-size:12px; color:#64748b; }
        .loading {
            display: inline-block;
            width: 16px;
            height: 16px;
            border: 2px solid #fff;
            border-top: 2px solid transparent;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin-right: 8px;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        /* Toast Message */
        .toast-message {
            position: fixed;
            bottom: 30px;
            right: 30px;
            background: #28a745;
            color: white;
            padding: 14px 20px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 14px;
            font-weight: 500;
            z-index: 9999;
            animation: slideIn 0.3s ease;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            max-width: 350px;
        }
        .toast-message.error {
            background: #dc3545;
        }
        .toast-message.warning {
            background: #ffc107;
            color: #1d3156;
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
        @media (max-width: 768px) {
            .toast-message {
                bottom: 20px;
                right: 20px;
                left: 20px;
                max-width: none;
            }
        }
    </style>
</head>
<body>

<header class="topbar">
    <div class="container">
        <nav class="nav">
            <a href="tutor_dashboard.php" class="brand">
                <img src="<?= e($assetBase) ?>/logo.png" alt="Kyoshi">
                <div><strong>Kyoshi</strong><span>Teacher Space</span></div>
            </a>
            <div class="nav-links">
                <a href="tutor_dashboard.php">Dashboard</a>
                <a href="availability.php">Availability</a>
                <a href="booking_requests.php">Requests</a>
                <a href="learning_materials.php?booking_id=<?= $booking_id ?>">Materials</a>
                <a href="assignments.php?booking_id=<?= $booking_id ?>" class="active">Homework</a>
                <a href="earnings.php">Earnings</a>
                <a href="meeting_links.php">Meeting Links</a>
            </div>
            <div style="position:relative;">
                <button class="profile" onclick="toggleDropdown()">
                    <img src="<?= e($profilePic) ?>" alt="">
                    <span><?= e($displayName) ?></span>
                    <i class="bi bi-chevron-down"></i>
                </button>
                <div class="dropdown" id="profileDropdown">
                    <a href="tutor_profile.php"><i class="bi bi-person-circle"></i> My Profile</a>
                    <a href="earnings.php"><i class="bi bi-wallet2"></i> My Earnings</a>
                    <hr>
                    <a href="logout.php" style="color:#dc2626;"><i class="bi bi-box-arrow-right"></i> Logout</a>
                </div>
            </div>
        </nav>
    </div>
</header>

<div class="main">
    <a href="assignments.php?booking_id=<?= $booking_id ?>" class="btn-back">
        <i class="bi bi-arrow-left"></i> Back to Assignments
    </a>

    <div class="card">
        <h1><i class="bi bi-pencil-square"></i> Create Assignment</h1>
        <p class="subtitle">Give homework or pre-work for your student</p>

        <div class="info-bar">
            <i class="bi bi-person-circle"></i>
            <div>
                <strong><?= e($booking['student_name']) ?></strong>
                <span><?= e($booking['language']) ?> · <?= date('d M Y', strtotime($booking['booking_date'])) ?></span>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-<?= $messageType ?>">
                <i class="bi bi-<?= $messageType === 'success' ? 'check-circle' : 'exclamation-circle' ?>"></i>
                <?= e($message) ?>
            </div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data" id="assignmentForm">
            <div class="form-group">
                <label>Assignment Title</label>
                <input type="text" name="title" id="titleInput" class="form-control" placeholder="e.g., Essay: My Hobby, Grammar Exercise 1" required>
            </div>

            <div class="form-group">
                <label>Instructions / Description</label>
                <textarea name="description" id="descInput" class="form-control" placeholder="Describe what the student needs to do..."></textarea>
                <div class="form-hint">Explain what the student should complete</div>
            </div>

            <div class="form-group">
                <label>Due Date (Optional)</label>
                <input type="datetime-local" name="due_date" id="dueDateInput" class="form-control">
                <div class="form-hint">Leave empty for no deadline</div>
            </div>

            <div class="form-group">
                <label>Total Points</label>
                <input type="number" name="total_points" value="100" min="1" max="1000" class="form-control">
            </div>

            <div class="form-group">
                <label>Attach Material (Optional)</label>
                <div class="toggle-row">
                    <div class="toggle-btn active" data-attach="none">No attachment</div>
                    <div class="toggle-btn" data-attach="file">📁 Upload File</div>
                    <div class="toggle-btn" data-attach="url">🔗 Share Link</div>
                </div>
                <input type="hidden" name="attachment_type" id="attachmentType" value="none">
            </div>

            <div id="fileSection" class="hidden">
                <div class="drop-wrapper">
                    <div class="drop-zone" id="dropZone">
                        <i class="bi bi-cloud-arrow-up"></i>
                        <p>Drag & drop or click to browse</p>
                        <small>PDF, Word, PPT, Images, MP4, ZIP (Max 50MB)</small>
                    </div>
                    <input type="file" name="assignment_file" id="fileInput" accept=".pdf,.doc,.docx,.ppt,.pptx,.jpg,.jpeg,.png,.mp4,.zip" onchange="previewFile(this)">
                </div>
                <div class="file-preview" id="filePreview">
                    <i class="bi bi-file-earmark-check"></i>
                    <div><strong id="fileName"></strong> <span id="fileSize"></span></div>
                </div>
            </div>

            <div id="urlSection" class="hidden">
                <div class="form-group">
                    <label>URL Link</label>
                    <input type="url" name="material_url" id="urlInput" class="form-control" placeholder="https://www.youtube.com/watch?v=...">
                    <div class="form-hint">YouTube, Google Docs, articles, Quizlet, etc.</div>
                </div>
            </div>

            <button type="submit" class="btn-submit" id="submitBtn">
                <i class="bi bi-plus-circle"></i> Create Assignment
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
        const icon = type === 'success' ? 'check-circle-fill' : (type === 'error' ? 'exclamation-circle-fill' : 'exclamation-triangle-fill');
        toast.innerHTML = `<i class="bi bi-${icon}"></i> ${message}`;
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

    // Attachment Type Toggle
    document.querySelectorAll('[data-attach]').forEach(btn => {
        btn.addEventListener('click', function() {
            document.querySelectorAll('[data-attach]').forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            const type = this.dataset.attach;
            document.getElementById('attachmentType').value = type;
            
            const fileSection = document.getElementById('fileSection');
            const urlSection = document.getElementById('urlSection');
            
            if (type === 'file') {
                fileSection.classList.remove('hidden');
                urlSection.classList.add('hidden');
            } else if (type === 'url') {
                fileSection.classList.add('hidden');
                urlSection.classList.remove('hidden');
            } else {
                fileSection.classList.add('hidden');
                urlSection.classList.add('hidden');
            }
        });
    });

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

    let isSubmitting = false;

    document.getElementById('assignmentForm').addEventListener('submit', function(e) {
        if (isSubmitting) {
            e.preventDefault();
            showToast('Please wait, already submitting...', 'warning');
            return;
        }
        
        const title = document.getElementById('titleInput').value.trim();
        if (!title) {
            e.preventDefault();
            showToast('Please enter a title', 'error');
            return;
        }
        
        // Validate due date (cannot be in the past)
        const dueDate = document.getElementById('dueDateInput').value;
        if (dueDate) {
            const selectedDate = new Date(dueDate);
            const now = new Date();
            // Remove time from now for date-only comparison? No, keep full datetime
            if (selectedDate < now) {
                e.preventDefault();
                showToast('Due date cannot be in the past. Please select a future date.', 'error');
                return;
            }
        }
        
        const attachmentType = document.getElementById('attachmentType').value;
        if (attachmentType === 'url') {
            const url = document.getElementById('urlInput').value.trim();
            if (!url) {
                e.preventDefault();
                showToast('Please enter a URL', 'error');
                return;
            }
            if (!url.startsWith('http://') && !url.startsWith('https://')) {
                e.preventDefault();
                showToast('Please enter a valid URL starting with http:// or https://', 'error');
                return;
            }
        } else if (attachmentType === 'file') {
            const fileInput = document.getElementById('fileInput');
            if (!fileInput.files || !fileInput.files[0]) {
                e.preventDefault();
                showToast('Please select a file to upload', 'error');
                return;
            }
        }
        
        isSubmitting = true;
        const submitBtn = document.getElementById('submitBtn');
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<span class="loading"></span> Creating...';
        showToast('Creating assignment...', 'success');
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