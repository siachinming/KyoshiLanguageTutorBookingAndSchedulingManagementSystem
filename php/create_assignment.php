<?php
session_start();
include 'config.php';
include 'insert_notification.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require '../vendor/autoload.php';

// Determine where to go back - MOVED HERE
$referer = $_SERVER['HTTP_REFERER'] ?? '';
if (strpos($referer, 'select_booking.php') !== false) {
    $backUrl = 'select_booking.php?action=assignment';
} else {
    $backUrl = 'assignment_overview.php';
}

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
        $mail->Subject = "New Assignment from {$tutorName}";
        $mail->isHTML(true);
        $mail->Body = "
        <div style='font-family:Segoe UI,sans-serif;max-width:560px;margin:auto;
                    border:1px solid #e0e0e0;border-radius:16px;padding:28px;background:#fff;'>
            <div style='text-align:center;margin-bottom:20px;'>
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
    

    
   $due_date_raw = $_POST['due_date'] ?? '';

// Debug: Log the raw due date
error_log("Raw due date from form: " . $due_date_raw);
$due_date = null;
if (!empty($due_date_raw)) {
    $due_date = str_replace('T', ' ', $due_date_raw) . ':00';
}

// Double-check: Make sure it's not empty or invalid
if ($due_date && $due_date == '0000-00-00 00:00:00') {
    $due_date = null; // Don't save zeros
    error_log("Reset zero date to NULL");
}
    
    $total_points  = intval($_POST['total_points'] ?? 100);
   $allowLate = isset($_POST['allow_late_submission']) ? 1 : 0;
$late_cutoff_type = $_POST['late_cutoff_type'] ?? 'no_limit';
$late_days = isset($_POST['late_days']) ? intval($_POST['late_days']) : null;
$late_cutoff_date_raw = $_POST['late_cutoff_date'] ?? '';

// If no due date, ignore late submission settings
if (empty($due_date)) {
    $allowLate = 0;
    $late_cutoff_type = 'days_after';
    $late_days = null;
    $late_cutoff_date = null;
    error_log("No due date set - ignoring late submission settings");
} else {
    // Process late cutoff date only if due date exists
    if (!empty($late_cutoff_date_raw)) {
        $late_cutoff_date = str_replace('T', ' ', $late_cutoff_date_raw) . ':00';
    } else {
        $late_cutoff_date = null;
    }
}

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
        $is_url = 0;
        
        // Handle file upload
       // Handle multiple file uploads
if ($attachment_type === 'file' && isset($_FILES['assignment_files']) && count($_FILES['assignment_files']['name']) > 0) {
    $uploadDir = '../uploads/assignments/';
    if (!file_exists($uploadDir)) mkdir($uploadDir, 0777, true);
    
    $uploadedFiles = [];
    $uploadErrors = [];
    
    $totalFiles = count($_FILES['assignment_files']['name']);
    error_log("Uploading $totalFiles files");
    
    for ($i = 0; $i < $totalFiles; $i++) {
        if ($_FILES['assignment_files']['error'][$i] === UPLOAD_ERR_OK) {
            $fileExt = pathinfo($_FILES['assignment_files']['name'][$i], PATHINFO_EXTENSION);
            $fileName = time() . '_' . bin2hex(random_bytes(8)) . '.' . $fileExt;
            $fullFilePath = $uploadDir . $fileName;
            $originalName = $_FILES['assignment_files']['name'][$i];
            $fileSize = $_FILES['assignment_files']['size'][$i];
            $fileMime = $_FILES['assignment_files']['type'][$i];
            
            if (move_uploaded_file($_FILES['assignment_files']['tmp_name'][$i], $fullFilePath)) {
                $uploadedFiles[] = [
                    'name' => $originalName,
                    'path' => $fileName,
                    'size' => $fileSize,
                    'type' => $fileMime
                ];
                error_log("File uploaded: " . $fileName);
            } else {
                $uploadErrors[] = "Failed to upload: " . $originalName;
            }
        } else {
            $uploadErrors[] = "Error uploading file " . ($i+1);
        }
    }
    
    if (!empty($uploadErrors)) {
        $message = implode(", ", $uploadErrors);
        $messageType = "error";
    } else {
$file_name = implode('|', array_column($uploadedFiles, 'name'));
$file_path = implode('|', array_column($uploadedFiles, 'path'));
$file_size = array_sum(array_column($uploadedFiles, 'size'));
$file_type = implode('|', array_column($uploadedFiles, 'type'));
    }
} elseif ($attachment_type === 'url') {
    $material_urls = $_POST['material_urls'] ?? [];
    $validUrls = [];
    $urlErrors = [];
    
    foreach ($material_urls as $url) {
        $url = trim($url);
        if (!empty($url)) {
            if (filter_var($url, FILTER_VALIDATE_URL)) {
                $validUrls[] = $url;
            } else {
                $urlErrors[] = "Invalid URL: " . $url;
            }
        }
    }
    
    if (!empty($urlErrors)) {
        $message = implode(", ", $urlErrors);
        $messageType = "error";
    } elseif (!empty($validUrls)) {
        // Store as pipe-separated values
        $material_url = implode('|', $validUrls);
        $is_url = 1;
        error_log("URLs set: " . $material_url);
    } else {
        $message = "Please enter at least one valid URL.";
        $messageType = "error";
    }
}
        
        if (empty($message)) {
            $stmt = $conn->prepare("
    INSERT INTO assignments (
        booking_id, tutor_id, student_id, title, description, 
        due_date, total_points, allow_late_submission,
        file_name, file_path, file_size, file_type,
        is_url, material_url, created_at,
        late_cutoff_type, late_days, late_cutoff_date
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?)
");

$stmt->bind_param(
    "iiissiiisssiissis",
    $booking_id,
    $userID,
    $booking['student_id'],
    $title,
    $description,
    $due_date,
    $total_points,
    $allowLate,
    $file_name,
    $file_path,
    $file_size,
    $file_type,
    $is_url,
    $material_url,
    $late_cutoff_type,
    $late_days,
    $late_cutoff_date
);
                        
            if ($stmt->execute()) {
                $assignment_id = $conn->insert_id;
                error_log("SUCCESS! Assignment created with ID: " . $assignment_id);
                
                // Insert notification for student
                $due_text = !empty($due_date) ? " (Due: " . date('d M Y, g:i A', strtotime($due_date)) . ")" : "";
                $notification_title = "New Assignment: " . $title;
                $notification_message = "You have a new assignment: " . $title . $due_text;
                $notification_link = "my_assignments.php?booking_id=" . $booking_id;
                
                insertNotification($conn, $booking['student_id'], $notification_title, $notification_message, 'assignment', $notification_link);
                sendAssignmentEmail($booking, $title, $description, $due_date, $displayName);
                
                header("Location: " . $backUrl . (strpos($backUrl, '?') !== false ? '&' : '?') . "success=Assignment created!");
                exit();
            } else {
                $message = "Error: " . $conn->error;
                $messageType = "error";
                error_log("Database error: " . $conn->error);
                // Delete uploaded file if database insert failed
                if ($file_path && file_exists('../uploads/assignments/' . $file_path)) {
                    unlink('../uploads/assignments/' . $file_path);
                }
            }
        }
    }
}

     function isLateSubmissionAllowed($assignment) {
    if (!$assignment['allow_late_submission']) {
        return false; // Checkbox not checked = no late submissions
    }
    
    $now = new DateTime();
    $dueDate = new DateTime($assignment['due_date']);
    
    if ($now <= $dueDate) {
        return true; // Not late yet, always allowed
    }
    
    // Check cutoff based on type (only reached if checkbox is checked AND past due date)
    switch ($assignment['late_cutoff_type']) {
        case 'days_after':
            $cutoff = clone $dueDate;
            $cutoff->modify('+' . $assignment['late_days'] . ' days');
            return $now <= $cutoff;
        case 'specific_date':
            $cutoff = new DateTime($assignment['late_cutoff_date']);
            return $now <= $cutoff;
        case 'no_limit':
            return true;
        default:
            return false;
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

        .url-preview-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    background: #e8f0fe;
    border-radius: 12px;
    padding: 10px 12px;
    margin-bottom: 8px;
}
.url-preview-item i {
    color: #1d3156;
    margin-right: 10px;
}
.url-preview-item a {
    color: #1d3156;
    text-decoration: none;
    flex: 1;
    word-break: break-all;
}
.url-preview-item a:hover {
    text-decoration: underline;
}
.btn-add-url:hover {
    background: #142544 !important;
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
                <a href="booking_requests.php">My Bookings</a>
                <a href="material_overview.php">My Materials</a>
                <a href="assignment_overview.php" class="active">My Assignments</a>
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
        <h1><i class="bi bi-pencil-square"></i> Create Assignment</h1>
        <p class="subtitle">Give homework for your student</p>

        <div class="info-bar">
            <i class="bi bi-person-circle"></i>
            <div>
                <strong><?= e($booking['student_name']) ?></strong>
                <span><?= e($booking['language']) ?> · <?= date('d M Y', strtotime($booking['booking_date'])) ?></span>
                <?php if (!empty($booking['focus'])): ?>
                    <br><span>Focus: <?= e($booking['focus']) ?></span>
                <?php endif; ?>
                <?php if (!empty($booking['proficiency_level'])): ?>
                    <span> · Level: <?= ucfirst(e($booking['proficiency_level'])) ?></span>
                <?php endif; ?>
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
                <textarea name="description" id="descInput" class="form-control" placeholder="Describe what the student needs to do... required"></textarea>
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

            <div id="latePolicyDiv" style="display: none;">
    <div class="form-group">
        <label>Late Submission Policy</label>
        <div style="margin-bottom: 10px;">
            <input type="checkbox" name="allow_late_submission" id="allowLateCheckbox" value="1" style="width: auto; margin-right: 8px;" onchange="toggleLateOptions()">
            <label style="display: inline; font-weight: normal;">Allow late submissions</label>
        </div>
        
        <div id="lateOptions" style="display: none; margin-top: 10px; padding: 12px; background: #f8fafc; border-radius: 12px;">
            <select name="late_cutoff_type" id="lateCutoffType" class="form-control" style="margin-bottom: 10px;" onchange="updateLateOptions()">
                <option value="no_limit" selected>No limit (always accept late)</option>
                <option value="days_after">Allow X days after due date</option>
                <option value="specific_date">Allow until specific date</option>
            </select>
            <div id="daysAfterOption" style="display: none; margin-top: 10px;">
                <label style="font-size: 12px;">Days allowed after due date:</label>
                <input type="number" name="late_days" id="lateDays" min="1" max="30" value="7" class="form-control" style="width: 100px;">
                <small class="form-hint">Example: 7 days after due date</small>
            </div>
            
            <div id="specificDateOption" style="display: none; margin-top: 10px;">
                <label style="font-size: 12px;">Cutoff date & time:</label>
                <input type="datetime-local" name="late_cutoff_date" id="lateCutoffDate" class="form-control">
                <small class="form-hint">Submissions accepted until this date/time</small>
            </div>
        </div>
        <div class="form-hint">Define when late submissions will be accepted until</div>
    </div>
</div>
            
        
            <div class="form-group">
                <label>Attach Work</label>
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
            <small>PDF, Word, PPT, Images, MP4, ZIP (Max 50MB each)</small>
        </div>
        <input type="file" name="assignment_files[]" id="fileInput" accept=".pdf,.doc,.docx,.ppt,.pptx,.jpg,.jpeg,.png,.mp4,.zip" multiple onchange="previewMultipleFiles(this)">
    </div>
    <div id="filePreviewList" style="margin-top: 10px; max-height: 200px; overflow-y: auto;"></div>
</div>

           <div id="urlSection" class="hidden">
    <div class="form-group">
        <label>URL Links (You can add multiple)</label>
        <div id="urlsContainer">
            <div class="url-input-group" style="display: flex; gap: 10px; margin-bottom: 10px;">
                <input type="url" name="material_urls[]" class="form-control url-input" placeholder="https://...">
                <button type="button" class="remove-url-btn" style="background: #dc2626; color: white; border: none; border-radius: 20px; padding: 0 15px; cursor: pointer; display: none;">Remove</button>
            </div>
        </div>
        <button type="button" id="addUrlBtn" class="btn-add-url" style="background: #1d3156; color: white; border: none; border-radius: 20px; padding: 8px 16px; margin-top: 8px; cursor: pointer;">
            <i class="bi bi-plus-circle"></i> Add Another URL
        </button>
    </div>
    <div id="urlPreviewList" style="margin-top: 10px;"></div>
</div>

            <button type="submit" class="btn-submit" id="submitBtn">
                <i class="bi bi-plus-circle"></i> Create Assignment
            </button>
        </form>
    </div>
</div>

<script>
document.getElementById('addUrlBtn')?.addEventListener('click', addUrlInput);
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
let selectedFiles = [];
function toggleLateOptions() {
    const checkbox = document.getElementById('allowLateCheckbox');
    const lateOptions = document.getElementById('lateOptions');
    
    if (checkbox.checked) {
        lateOptions.style.display = 'block';
    } else {
        lateOptions.style.display = 'none';
    }
}

function updateLateOptions() {
    const cutoffType = document.getElementById('lateCutoffType').value;
    const daysAfterDiv = document.getElementById('daysAfterOption');
    const specificDateDiv = document.getElementById('specificDateOption');
    
    daysAfterDiv.style.display = 'none';
    specificDateDiv.style.display = 'none';
    
    if (cutoffType === 'days_after') {
        daysAfterDiv.style.display = 'block';
    } else if (cutoffType === 'specific_date') {
        specificDateDiv.style.display = 'block';
    }
}
function previewMultipleFiles(input) {
    // ADD new files to existing selection, don't replace
    if (input.files && input.files.length > 0) {
        for (let i = 0; i < input.files.length; i++) {
            selectedFiles.push(input.files[i]);
        }
        // Sync back to input
        syncFilesToInput();
    }
    renderFilePreview();
}

function syncFilesToInput() {
    const dataTransfer = new DataTransfer();
    selectedFiles.forEach(file => dataTransfer.items.add(file));
    document.getElementById('fileInput').files = dataTransfer.files;
}

function toggleLateOptionsByDueDate() {
    const dueDateInput = document.getElementById('dueDateInput');
    const latePolicyDiv = document.getElementById('latePolicyDiv');
    const allowLateCheckbox = document.getElementById('allowLateCheckbox');
    const lateOptions = document.getElementById('lateOptions');
    
    if (dueDateInput.value) {
        // Due date is set - show late policy options
        latePolicyDiv.style.display = 'block';
    } else {
        // No due date - hide late policy and uncheck checkbox
        latePolicyDiv.style.display = 'none';
        allowLateCheckbox.checked = false;
        lateOptions.style.display = 'none';
    }
}

function renderFilePreview() {
    const previewContainer = document.getElementById('filePreviewList');
    previewContainer.innerHTML = '';

    selectedFiles.forEach((file, i) => {
        const fileDiv = document.createElement('div');
        fileDiv.style.cssText = 'display:flex;align-items:center;justify-content:space-between;background:#eef2ff;border-radius:12px;padding:10px 12px;margin-bottom:8px;';
        fileDiv.innerHTML = `
            <div style="display:flex;align-items:center;gap:10px;">
                <i class="bi bi-file-earmark" style="color:#E75A9B;font-size:18px;"></i>
                <div>
                    <strong style="font-size:13px;">${escapeHtml(file.name)}</strong>
                    <span style="font-size:11px;color:#64748b;display:block;">${(file.size/1024/1024).toFixed(2)} MB</span>
                </div>
            </div>
            <button type="button" onclick="removeFile(${i})" 
                style="background:#dc2626;color:white;border:none;border-radius:20px;padding:4px 12px;cursor:pointer;font-size:12px;">
                <i class="bi bi-x"></i> 
            </button>
        `;
        previewContainer.appendChild(fileDiv);
    });

    // Update drop zone text
    const dropZone = document.getElementById('dropZone');
    if (selectedFiles.length > 0) {
        dropZone.querySelector('p').textContent = `${selectedFiles.length} file(s) selected — click to add more`;
        dropZone.querySelector('i').style.color = '#28a745';
    } else {
        dropZone.querySelector('p').textContent = 'Drag & drop or click to browse';
        dropZone.querySelector('i').style.color = '#E75A9B';
    }
}

function removeFile(index) {
    selectedFiles.splice(index, 1);
    syncFilesToInput();
    renderFilePreview();
}

function escapeHtml(str) {
    if (!str) return '';
    return str.replace(/[&<>]/g, function(m) {
        if (m === '&') return '&amp;';
        if (m === '<') return '&lt;';
        if (m === '>') return '&gt;';
        return m;
    });
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
    const urlInputs = document.querySelectorAll('.url-input');
    let hasValidUrl = false;
    
    urlInputs.forEach(input => {
        const url = input.value.trim();
        if (url && (url.startsWith('http://') || url.startsWith('https://'))) {
            hasValidUrl = true;
        }
    });
    
    if (!hasValidUrl) {
        e.preventDefault();
        showToast('Please enter at least one valid URL (starting with http:// or https://)', 'error');
        return;
    }
        } else if (attachmentType === 'file') {
    const fileInput = document.getElementById('fileInput');
    if (!fileInput.files || fileInput.files.length === 0) {
        e.preventDefault();
        showToast('Please select at least one file to upload', 'error');
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
            previewMultipleFiles(fileInput);
        });
    }

    let selectedUrls = [];

function addUrlInput() {
    const container = document.getElementById('urlsContainer');
    const urlCount = container.children.length;
    
    const urlDiv = document.createElement('div');
    urlDiv.className = 'url-input-group';
    urlDiv.style.cssText = 'display: flex; gap: 10px; margin-bottom: 10px;';
    urlDiv.innerHTML = `
        <input type="url" name="material_urls[]" class="form-control url-input" placeholder="https://...">
        <button type="button" class="remove-url-btn" onclick="removeUrlInput(this)" style="background: #dc2626; color: white; border: none; border-radius: 20px; padding: 0 15px; cursor: pointer;">
            Remove
        </button>
    `;
    container.appendChild(urlDiv);
    updateUrlPreviews();
}

function removeUrlInput(button) {
    button.parentElement.remove();
    updateUrlPreviews();
}

function updateUrlPreviews() {
    const urlInputs = document.querySelectorAll('.url-input');
    const previewContainer = document.getElementById('urlPreviewList');
    previewContainer.innerHTML = '';
    selectedUrls = [];
    
    urlInputs.forEach((input, index) => {
        const url = input.value.trim();
        if (url) {
            selectedUrls.push(url);
            const previewDiv = document.createElement('div');
            previewDiv.className = 'url-preview-item';
            previewDiv.innerHTML = `
                <div style="display: flex; align-items: center; gap: 10px; flex: 1;">
                    <i class="bi bi-link-45deg"></i>
                    <a href="${escapeHtml(url)}" target="_blank">${escapeHtml(url.length > 50 ? url.substring(0, 50) + '...' : url)}</a>
                </div>
                <button type="button" onclick="removeUrlByIndex(${index})" style="background: #dc2626; color: white; border: none; border-radius: 20px; padding: 4px 12px; cursor: pointer;">
                    <i class="bi bi-x"></i> Remove
                </button>
            `;
            previewContainer.appendChild(previewDiv);
        }
    });
}

function removeUrlByIndex(index) {
    const urlInputs = document.querySelectorAll('.url-input');
    if (urlInputs[index]) {
        urlInputs[index].remove();
        updateUrlPreviews();
    }
}

// Add event listener for URL inputs
document.addEventListener('input', function(e) {
    if (e.target && e.target.classList && e.target.classList.contains('url-input')) {
        updateUrlPreviews();
    }
});

const dueDateInput = document.getElementById('dueDateInput');
if (dueDateInput) {
    dueDateInput.addEventListener('change', toggleLateOptionsByDueDate);
    dueDateInput.addEventListener('input', toggleLateOptionsByDueDate);
    // Check initial state on page load
    toggleLateOptionsByDueDate();
}
</script>
</body>
</html>