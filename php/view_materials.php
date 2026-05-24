<?php
session_start();
include 'config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$material_id = intval($_GET['id'] ?? 0);
$booking_id = intval($_GET['booking_id'] ?? 0);

if (!$material_id) {
    die("Material not found.");
}

$stmt = $conn->prepare("
    SELECT lm.*, b.tutor_id, b.student_id 
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

$userID = $_SESSION['user_id'];
$userRole = $_SESSION['role'];

$hasAccess = false;
if ($userRole === 'tutor' && $material['tutor_id'] == $userID) {
    $hasAccess = true;
} elseif ($userRole === 'student' && $material['student_id'] == $userID) {
    $hasAccess = true;
}

if (!$hasAccess) {
    die("Access denied.");
}

if (!file_exists($material['file_path'])) {
    die("File not found.");
}

function formatFileSize($bytes) {
    if (!$bytes) return 'Unknown';
    if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024) return round($bytes / 1024, 1) . ' KB';
    return $bytes . ' bytes';
}

$ext = strtolower(pathinfo($material['file_name'], PATHINFO_EXTENSION));

if ($ext === 'pdf') {
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $material['file_name'] . '"');
    readfile($material['file_path']);
    exit();
} elseif (in_array($ext, ['jpg', 'jpeg', 'png', 'gif'])) {
    header('Content-Type: ' . mime_content_type($material['file_path']));
    header('Content-Disposition: inline; filename="' . $material['file_name'] . '"');
    readfile($material['file_path']);
    exit();
} elseif ($ext === 'txt') {
    header('Content-Type: text/plain');
    header('Content-Disposition: inline; filename="' . $material['file_name'] . '"');
    readfile($material['file_path']);
    exit();
} else {
    // HTML preview for video/audio
    ?>
<!DOCTYPE html>
<html>
<head>
    <title>Preview: <?= htmlspecialchars($material['title']) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 20px; background: #f5f5f5; }
        .container { max-width: 900px; margin: 0 auto; background: white; border-radius: 12px; padding: 20px; }
        .back-btn { display: inline-block; margin-bottom: 20px; padding: 8px 16px; background: #E75A9B; color: white; text-decoration: none; border-radius: 8px; }
        video, audio { width: 100%; margin: 20px 0; }
        .info { background: #f8fafc; padding: 15px; border-radius: 8px; margin-top: 20px; }
        .info-row { padding: 8px 0; border-bottom: 1px solid #eef2f7; }
    </style>
</head>
<body>
    <div class="container">
        <a href="learning_materials.php?booking_id=<?= $booking_id ?>" class="back-btn">← Back</a>
        <h2><?= htmlspecialchars($material['title']) ?></h2>
        <?php if ($ext === 'mp4'): ?>
            <video controls><source src="view_materials.php?id=<?= $material_id ?>&booking_id=<?= $booking_id ?>" type="video/mp4"></video>
        <?php elseif ($ext === 'mp3'): ?>
            <audio controls><source src="view_materials.php?id=<?= $material_id ?>&booking_id=<?= $booking_id ?>" type="audio/mpeg"></audio>
        <?php else: ?>
            <p>This file type cannot be previewed. <a href="download_material.php?id=<?= $material_id ?>&booking_id=<?= $booking_id ?>">Download</a> to view.</p>
        <?php endif; ?>
        <div class="info">
            <div class="info-row"><strong>File:</strong> <?= htmlspecialchars($material['file_name']) ?></div>
            <div class="info-row"><strong>Size:</strong> <?= formatFileSize($material['file_size']) ?></div>
            <div class="info-row"><strong>Uploaded:</strong> <?= date('d M Y, g:i A', strtotime($material['uploaded_at'])) ?></div>
        </div>
        <?php if (!empty($material['description'])): ?>
            <div class="info" style="background:#fefce8; margin-top:10px;"><strong>Description:</strong><br><?= nl2br(htmlspecialchars($material['description'])) ?></div>
        <?php endif; ?>
    </div>
</body>
</html>
    <?php
    exit();
}
?>