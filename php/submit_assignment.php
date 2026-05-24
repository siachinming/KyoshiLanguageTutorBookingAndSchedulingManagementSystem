<?php
session_start();
include 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header("Location: login.php");
    exit();
}

$userID = $_SESSION['user_id'];
$assignment_id = intval($_GET['id'] ?? 0);
$booking_id = intval($_GET['booking_id'] ?? 0);

if (!$assignment_id || !$booking_id) {
    header("Location: student_dashboard.php");
    exit();
}

// Get assignment info
$stmt = $conn->prepare("
    SELECT a.*, b.student_id 
    FROM assignments a
    JOIN bookings b ON a.booking_id = b.id
    WHERE a.id = ? AND b.student_id = ?
");
$stmt->bind_param("ii", $assignment_id, $userID);
$stmt->execute();
$assignment = $stmt->get_result()->fetch_assoc();

if (!$assignment) {
    header("Location: student_dashboard.php");
    exit();
}

// Check if already submitted
$stmt = $conn->prepare("SELECT * FROM submissions WHERE assignment_id = ? AND student_id = ?");
$stmt->bind_param("ii", $assignment_id, $userID);
$stmt->execute();
$existing = $stmt->get_result()->fetch_assoc();

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $comments = trim($_POST['comments'] ?? '');
    
    if (isset($_FILES['submission_file']) && $_FILES['submission_file']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = '../uploads/submissions/';
        if (!file_exists($uploadDir)) mkdir($uploadDir, 0777, true);
        
        $fileExt = pathinfo($_FILES['submission_file']['name'], PATHINFO_EXTENSION);
        $fileName = time() . '_' . bin2hex(random_bytes(8)) . '.' . $fileExt;
        $filePath = $uploadDir . $fileName;
        $originalName = $_FILES['submission_file']['name'];
        $fileSize = $_FILES['submission_file']['size'];
        
        if (move_uploaded_file($_FILES['submission_file']['tmp_name'], $filePath)) {
            if ($existing) {
                // Delete old file if exists
                if (file_exists($existing['file_path'])) unlink($existing['file_path']);
                $stmt = $conn->prepare("UPDATE submissions SET file_name=?, file_path=?, file_size=?, comments=?, submitted_at=NOW() WHERE id=?");
                $stmt->bind_param("ssisi", $originalName, $filePath, $fileSize, $comments, $existing['id']);
            } else {
                $stmt = $conn->prepare("INSERT INTO submissions (assignment_id, student_id, file_name, file_path, file_size, comments) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("iisssi", $assignment_id, $userID, $originalName, $filePath, $fileSize, $comments);
            }
            
            if ($stmt->execute()) {
                header("Location: assignments.php?booking_id=" . $booking_id . "&success=" . urlencode("Assignment submitted!"));
                exit();
            } else {
                $message = "Database error.";
                $messageType = "error";
            }
        } else {
            $message = "Failed to upload file.";
            $messageType = "error";
        }
    } else {
        $message = "Please select a file to upload.";
        $messageType = "error";
    }
}

function e($value) { return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8'); }
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Submit Assignment - Kyoshi</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Poppins', sans-serif; background: url('../assets/img/background2.png') no-repeat center top; background-size: cover; padding: 40px; }
        .container { max-width: 700px; margin: 0 auto; background: white; border-radius: 24px; padding: 32px; box-shadow: 0 4px 20px rgba(0,0,0,0.1); }
        h1 { font-size: 24px; color: #1d3156; }
        .subtitle { color: #64748b; font-size: 14px; margin-bottom: 24px; border-bottom: 1px solid #eef2f7; padding-bottom: 16px; }
        .btn-back { display: inline-flex; align-items: center; gap: 6px; color: #64748b; text-decoration: none; margin-bottom: 20px; font-size: 13px; }
        .form-group { margin-bottom: 20px; }
        label { display: block; font-size: 13px; font-weight: 600; margin-bottom: 6px; color: #1d3156; }
        textarea { width: 100%; padding: 10px 12px; border: 1px solid #cbd5e1; border-radius: 12px; font-family: 'Poppins', sans-serif; min-height: 100px; }
        input[type="file"] { padding: 10px 0; }
        .btn-submit { background: linear-gradient(135deg, #E75A9B, #F28AB2); color: white; border: none; padding: 12px 24px; border-radius: 30px; font-size: 14px; font-weight: 600; cursor: pointer; width: 100%; }
        .alert { padding: 12px 16px; border-radius: 12px; margin-bottom: 20px; }
        .alert-success { background: #d4edda; color: #155724; border-left: 3px solid #28a745; }
        .alert-error { background: #f8d7da; color: #721c24; border-left: 3px solid #dc3545; }
        .info-box { background: #f8fafc; padding: 16px; border-radius: 12px; margin-bottom: 24px; }
        .hint { font-size: 11px; color: #64748b; margin-top: 4px; }
    </style>
</head>
<body>
    <div class="container">
        <a href="assignments.php?booking_id=<?= $booking_id ?>" class="btn-back">
            <i class="bi bi-arrow-left"></i> Back to Assignments
        </a>
        
        <h1><i class="bi bi-upload"></i> Submit Assignment</h1>
        <p class="subtitle"><?= e($assignment['title']) ?></p>
        
        <div class="info-box">
            <strong><i class="bi bi-info-circle"></i> Instructions:</strong><br>
            <?= nl2br(e($assignment['description'])) ?>
            <?php if (!empty($assignment['due_date'])): ?>
                <br><br><strong>Due Date:</strong> <?= date('d M Y, g:i A', strtotime($assignment['due_date'])) ?>
            <?php endif; ?>
        </div>
        
        <?php if ($message): ?>
            <div class="alert alert-<?= $messageType ?>"><?= e($message) ?></div>
        <?php endif; ?>
        
        <form method="POST" enctype="multipart/form-data">
            <div class="form-group">
                <label>Your Work (File)</label>
                <input type="file" name="submission_file" required>
                <div class="hint">Upload PDF, Word, Image, or ZIP files (Max 50MB)</div>
            </div>
            
            <div class="form-group">
                <label>Private Comments (Optional)</label>
                <textarea name="comments" placeholder="Add any notes for your tutor..."></textarea>
            </div>
            
            <button type="submit" class="btn-submit">
                <i class="bi bi-cloud-upload"></i> Submit Assignment
            </button>
        </form>
    </div>
</body>
</html>