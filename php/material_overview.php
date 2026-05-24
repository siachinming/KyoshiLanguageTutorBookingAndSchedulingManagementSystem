<?php
session_start();
include 'config.php';

$assetBase = '../assets/img';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$userID = $_SESSION['user_id'];

// Get tutor info
$stmt = $conn->prepare("SELECT * FROM users WHERE id = ? AND role = 'tutor'");
$stmt->bind_param("i", $userID);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

if (!$user) {
    header("Location: login.php");
    exit();
}

$displayName = $user['fullname'];
$profilePic = !empty($user['profile_pic'])
    ? '../uploads/profiles/' . $user['profile_pic']
    : $assetBase . '/profile-tutor.png';

// Handle delete material
$deleteMessage = '';
$deleteMessageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_material'])) {
    $materialId = $_POST['material_id'];
    
    $stmt = $conn->prepare("SELECT file_path, is_url FROM learning_materials WHERE id = ? AND tutor_id = ?");
    $stmt->bind_param("ii", $materialId, $userID);
    $stmt->execute();
    $material = $stmt->get_result()->fetch_assoc();
    
    if ($material) {
        if ($material['is_url'] == 0 && !empty($material['file_path'])) {
            $filePath = '../uploads/learning_materials/' . $material['file_path'];
            if (file_exists($filePath)) {
                unlink($filePath);
            }
        }
        
        $stmt = $conn->prepare("DELETE FROM learning_materials WHERE id = ? AND tutor_id = ?");
        $stmt->bind_param("ii", $materialId, $userID);
        if ($stmt->execute()) {
            $deleteMessage = "Material deleted successfully!";
            $deleteMessageType = "success";
        } else {
            $deleteMessage = "Error deleting material: " . $conn->error;
            $deleteMessageType = "error";
        }
    } else {
        $deleteMessage = "Material not found or you don't have permission to delete it.";
        $deleteMessageType = "error";
    }
}

// Handle edit material
$editMessage = '';
$editMessageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_material'])) {
    $materialId = $_POST['material_id'];
    $title = trim($_POST['title']);
    $description = trim($_POST['description']);
    $materialType = $_POST['material_type'];
    $feedback = trim($_POST['feedback']);
    
    $stmt = $conn->prepare("UPDATE learning_materials SET title = ?, description = ?, material_type = ?, feedback = ? WHERE id = ? AND tutor_id = ?");
    $stmt->bind_param("ssssii", $title, $description, $materialType, $feedback, $materialId, $userID);
    
    if ($stmt->execute()) {
        $editMessage = "Material updated successfully!";
        $editMessageType = "success";
    } else {
        $editMessage = "Error updating material: " . $conn->error;
        $editMessageType = "error";
    }
}

// Fetch all materials with student, booking, and session info
$stmt = $conn->prepare("
    SELECT 
        lm.*,
        b.language as booking_language,
        b.booking_date,
        b.booking_time,
        b.learning_mode,
        u.fullname as student_name,
        u.id as student_id
    FROM learning_materials lm
    LEFT JOIN bookings b ON lm.booking_id = b.id
    LEFT JOIN users u ON b.student_id = u.id
    WHERE lm.tutor_id = ?
    ORDER BY lm.uploaded_at DESC
");
$stmt->bind_param("i", $userID);
$stmt->execute();
$allMaterials = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

function e($value) {
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function formatFileSize($bytes) {
    if ($bytes === null || $bytes === 0) return 'Unknown';
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = floor(log($bytes, 1024));
    return round($bytes / pow(1024, $i), 1) . ' ' . $units[$i];
}

function getReadableFileType($fileName, $isUrl = false) {
    if ($isUrl) return 'External Link';
    if (!$fileName) return 'File';
    
    $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    
    $typeMap = [
        'pdf' => 'PDF',
        'doc' => 'Word', 'docx' => 'Word',
        'xls' => 'Excel', 'xlsx' => 'Excel',
        'ppt' => 'PowerPoint', 'pptx' => 'PowerPoint',
        'jpg' => 'Image', 'jpeg' => 'Image', 'png' => 'Image', 'gif' => 'Image', 'webp' => 'Image',
        'mp4' => 'Video', 'mov' => 'Video', 'avi' => 'Video', 'mkv' => 'Video',
        'mp3' => 'Audio', 'wav' => 'Audio', 'ogg' => 'Audio',
        'zip' => 'Archive', 'rar' => 'Archive',
        'txt' => 'Text', 'md' => 'Markdown',
    ];
    
    return $typeMap[$ext] ?? strtoupper($ext) . ' File';
}

function getFileIconClassFromName($fileName, $isUrl = false) {
    if ($isUrl) return 'bi-link-45deg';
    if (!$fileName) return 'bi-file-earmark';
    
    $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    
    $iconMap = [
        'pdf' => 'bi-filetype-pdf',
        'doc' => 'bi-file-word', 'docx' => 'bi-file-word',
        'xls' => 'bi-file-excel', 'xlsx' => 'bi-file-excel',
        'ppt' => 'bi-file-ppt', 'pptx' => 'bi-file-ppt',
        'jpg' => 'bi-file-image', 'jpeg' => 'bi-file-image', 'png' => 'bi-file-image', 'gif' => 'bi-file-image', 'webp' => 'bi-file-image',
        'mp4' => 'bi-file-play', 'mov' => 'bi-file-play', 'avi' => 'bi-file-play',
        'mp3' => 'bi-file-music', 'wav' => 'bi-file-music',
        'zip' => 'bi-file-zip', 'rar' => 'bi-file-zip',
        'txt' => 'bi-file-text',
    ];
    
    return $iconMap[$ext] ?? 'bi-file-earmark';
}

function getFileTypeBadge($fileName, $isUrl = false) {
    if ($isUrl) {
        return '<span style="background: #8b5cf620; color: #8b5cf6; padding: 4px 12px; border-radius: 20px; font-size: 11px; font-weight: 600;"><i class="bi bi-link-45deg"></i> External Link</span>';
    }
    
    if (!$fileName) return '';
    
    $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    
    $badges = [
        'pdf' => ['color' => '#dc2626', 'icon' => 'bi-filetype-pdf', 'label' => 'PDF'],
        'doc' => ['color' => '#1d3156', 'icon' => 'bi-file-word', 'label' => 'Word'], 'docx' => ['color' => '#1d3156', 'icon' => 'bi-file-word', 'label' => 'Word'],
        'xls' => ['color' => '#28a745', 'icon' => 'bi-file-excel', 'label' => 'Excel'], 'xlsx' => ['color' => '#28a745', 'icon' => 'bi-file-excel', 'label' => 'Excel'],
        'ppt' => ['color' => '#f59e0b', 'icon' => 'bi-file-ppt', 'label' => 'PowerPoint'], 'pptx' => ['color' => '#f59e0b', 'icon' => 'bi-file-ppt', 'label' => 'PowerPoint'],
        'jpg' => ['color' => '#8b5cf6', 'icon' => 'bi-file-image', 'label' => 'Image'], 'jpeg' => ['color' => '#8b5cf6', 'icon' => 'bi-file-image', 'label' => 'Image'],
        'png' => ['color' => '#8b5cf6', 'icon' => 'bi-file-image', 'label' => 'Image'], 'gif' => ['color' => '#8b5cf6', 'icon' => 'bi-file-image', 'label' => 'Image'],
        'mp4' => ['color' => '#dc2626', 'icon' => 'bi-file-play', 'label' => 'Video'], 'mov' => ['color' => '#dc2626', 'icon' => 'bi-file-play', 'label' => 'Video'],
        'mp3' => ['color' => '#8b5cf6', 'icon' => 'bi-file-music', 'label' => 'Audio'], 'wav' => ['color' => '#8b5cf6', 'icon' => 'bi-file-music', 'label' => 'Audio'],
        'zip' => ['color' => '#64748b', 'icon' => 'bi-file-zip', 'label' => 'Archive'], 'rar' => ['color' => '#64748b', 'icon' => 'bi-file-zip', 'label' => 'Archive'],
        'txt' => ['color' => '#64748b', 'icon' => 'bi-file-text', 'label' => 'Text'],
    ];
    
    if (isset($badges[$ext])) {
        $badge = $badges[$ext];
        return '<span style="background: ' . $badge['color'] . '20; color: ' . $badge['color'] . '; padding: 4px 12px; border-radius: 20px; font-size: 11px; font-weight: 600;"><i class="' . $badge['icon'] . '"></i> ' . $badge['label'] . '</span>';
    }
    
    return '<span style="background: #64748b20; color: #64748b; padding: 4px 12px; border-radius: 20px; font-size: 11px; font-weight: 600;"><i class="bi bi-file"></i> ' . strtoupper($ext) . '</span>';
}

function getSessionTypeBadge($materialType) {
    if ($materialType == 'pre') {
        return '<span style="background: #f59e0b20; color: #f59e0b; padding: 4px 12px; border-radius: 20px; font-size: 11px; font-weight: 600;"><i class="bi bi-clock-history"></i> Pre-Session</span>';
    } elseif ($materialType == 'post') {
        return '<span style="background: #28a74520; color: #28a745; padding: 4px 12px; border-radius: 20px; font-size: 11px; font-weight: 600;"><i class="bi bi-check2-circle"></i> Post-Session</span>';
    }
    return '';
}
function formatSessionDateTime($date, $time) {
    if (!$date) return 'No session linked';
    $dateObj = new DateTime($date);
    $formattedDate = $dateObj->format('d M Y');
    if ($time) {
        $timeObj = new DateTime($time);
        $formattedTime = $timeObj->format('g:i A');
        return $formattedDate . ' @ ' . $formattedTime;
    }
    return $formattedDate;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>My Teaching Materials - Kyoshi Tutor</title>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">

<style>
* { margin: 0; padding: 0; box-sizing: border-box; }

body {
    font-family: 'Poppins', sans-serif;
    background: url('../assets/img/background2.png') no-repeat center top;
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
.nav { display: flex; justify-content: space-between; align-items: center; gap: 32px; min-height: 70px; }

.brand {
    display: flex;
    align-items: center;
    gap: 10px;
    text-decoration: none;
    flex-shrink: 0;
}
.brand img { width: 42px; height: 42px; object-fit: contain; }
.brand strong { display: block; color: #1d3156; font-size: 20px; line-height: 1.2; }
.brand span { color: #496894; font-size: 11px; }

.nav-links { display: flex; gap: 28px; align-items: center; flex-wrap: wrap; }
.nav-links a {
    text-decoration: none;
    color: #1d3156;
    font-size: 14px;
    font-weight: 600;
    position: relative;
    transition: 0.25s;
    padding: 6px 0;
}
.nav-links a:hover, .nav-links a.active { color: #496894; }
.nav-links a::after {
    content: '';
    position: absolute;
    left: 0;
    bottom: -6px;
    width: 0%;
    height: 3px;
    background: #496894;
    transition: 0.25s;
    border-radius: 10px;
}
.nav-links a:hover::after, .nav-links .active::after { width: 100%; }

.profile {
    display: flex;
    align-items: center;
    gap: 8px;
    background: rgba(255, 255, 255, 0.1);
    border: 1px solid rgba(255, 255, 255, 0.2);
    padding: 6px 14px 6px 8px;
    border-radius: 40px;
    cursor: pointer;
    color: black;
    transition: 0.25s;
    position: relative;
}
.profile:hover { background: rgba(255, 255, 255, 0.2); }
.profile img { width: 36px; height: 36px; border-radius: 50%; object-fit: cover; border: 2px solid rgba(255, 255, 255, 0.3); }
.profile span { font-size: 13px; font-weight: 500; }

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
.dropdown hr { border: none; border-top: 1px solid #ecf3f9; }

.main { width: min(1280px, 92%); margin: 32px auto 48px; }

.back-btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: white;
    color: #1d3156;
    padding: 10px 20px;
    border-radius: 40px;
    text-decoration: none;
    font-weight: 600;
    font-size: 14px;
    border: 1px solid #e2e8f0;
    transition: 0.25s;
}
.back-btn:hover { background: #b8d0e9; border-color: #6b9cd7; transform: translateX(-3px); }

.page-header-centered { text-align: center; margin-bottom: 28px; }
.page-header-centered h1 { font-size: 28px; font-weight: 800; color: #1d3156; letter-spacing: -0.5px; }
.page-header-centered p { color: #1e293b; margin-top: 6px; font-size: 13px; font-weight: 500; }

.add-material-btn {
    background: #1d3156;
    color: white;
    border: none;
    padding: 12px 28px;
    border-radius: 40px;
    font-weight: 600;
    font-size: 14px;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: 0.2s;
    text-decoration: none;
}
.add-material-btn:hover { background: #142544; transform: translateY(-2px); }

.filter-bar {
    background: white;
    border-radius: 16px;
    padding: 14px 20px;
    margin-bottom: 24px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.08);
    border: 1px solid #eef2f7;
}
.filter-row {
    display: flex;
    justify-content: center;
    gap: 20px;
    flex-wrap: wrap;
    align-items: flex-end;
}
.filter-group { min-width: 170px; }
.filter-group label { display: block; font-size: 12px; font-weight: 600; margin-bottom: 6px; color: #1d3156; }
.filter-group select, .filter-group input {
    width: 100%;
    padding: 10px 12px;
    border-radius: 10px;
    border: 1px solid #cbd5e1;
    background: white;
    font-family: 'Poppins', sans-serif;
    font-size: 13px;
}
.search-group { min-width: 250px; }
.search-group input {
    padding-left: 36px;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%2394a3b8' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Ccircle cx='11' cy='11' r='8'%3E%3C/circle%3E%3Cline x1='21' y1='21' x2='16.65' y2='16.65'%3E%3C/line%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: 12px center;
}
.btn-search, .btn-reset {
    padding: 10px 24px;
    border-radius: 10px;
    font-weight: 600;
    font-family: 'Poppins', sans-serif;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    border: none;
}
.btn-search { background: #1d3156; color: white; }
.btn-search:hover { background: #142544; }
.btn-reset { background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0; }
.btn-reset:hover { background: #e2e8f0; }

.alert {
    padding: 14px 20px;
    border-radius: 12px;
    margin-bottom: 24px;
    font-weight: 500;
    animation: slideDown 0.3s ease;
}
@keyframes slideDown {
    from { opacity: 0; transform: translateY(-10px); }
    to { opacity: 1; transform: translateY(0); }
}
.alert-success { background: #d4edda; color: #155724; border-left: 4px solid #28a745; }
.alert-error { background: #f8d7da; color: #721c24; border-left: 4px solid #dc3545; }

.materials-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(400px, 1fr));
    gap: 20px;
}
.material-card {
    background: white;
    border-radius: 20px;
    padding: 20px;
    transition: 0.25s;
    border: 1px solid #eef2f7;
    box-shadow: 0 2px 8px rgba(0,0,0,0.03);
}
.material-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 12px 24px rgba(0,0,0,0.08);
    border-color: #e2edf7;
}
.card-header {
    display: flex;
    align-items: flex-start;
    gap: 14px;
    margin-bottom: 12px;
}
.file-icon {
    width: 52px;
    height: 52px;
    background: #f1f5f9;
    border-radius: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 26px;
    color: #496894;
    flex-shrink: 0;
}
.material-info { flex: 1; min-width: 0; }
.material-title {
    font-weight: 700;
    font-size: 17px;
    color: #0f172a;
    margin-bottom: 8px;
    word-break: break-word;
    line-height: 1.3;
}
.badges-container {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-bottom: 10px;
}
.session-info {
    background: #f8fafc;
    border-radius: 12px;
    padding: 10px 12px;
    margin-bottom: 12px;
}
.session-row {
    display: flex;
    align-items: center;
    gap: 12px;
    font-size: 12px;
    margin-bottom: 6px;
}
.session-row:last-child { margin-bottom: 0; }
.session-label {
    font-weight: 600;
    color: #1d3156;
    width: 70px;
    flex-shrink: 0;
}
.session-value {
    color: #475569;
    display: flex;
    align-items: center;
    gap: 6px;
    flex-wrap: wrap;
}
.material-description {
    font-size: 13px;
    color: #475569;
    line-height: 1.5;
    margin-bottom: 12px;
    word-break: break-word;
}
.feedback-section {
    background: #fefce8;
    border-left: 3px solid #f59e0b;
    padding: 10px 12px;
    margin-bottom: 16px;
    border-radius: 8px;
}
.feedback-label {
    font-size: 11px;
    font-weight: 600;
    color: #f59e0b;
    margin-bottom: 4px;
    display: flex;
    align-items: center;
    gap: 4px;
}
.feedback-text {
    font-size: 12px;
    color: #475569;
    line-height: 1.4;
}
.card-footer {
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-top: 1px solid #eef2f7;
    padding-top: 14px;
    margin-top: 4px;
}
.file-info {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 11px;
    color: #64748b;
}
.action-buttons { display: flex; gap: 8px; }
.btn-view, .btn-edit, .btn-delete {
    padding: 6px 14px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
    text-decoration: none;
    cursor: pointer;
    transition: 0.2s;
    border: none;
    display: inline-flex;
    align-items: center;
    gap: 5px;
}
.btn-view { background: #e2e8f0; color: #1d3156; }
.btn-view:hover { background: #cbd5e1; }
.btn-edit { background: #fef3c7; color: #f59e0b; }
.btn-edit:hover { background: #fde68a; }
.btn-delete { background: #fee2e2; color: #dc2626; }
.btn-delete:hover { background: #fecaca; }

.empty-state {
    text-align: center;
    padding: 80px 20px;
    background: white;
    border-radius: 24px;
    color: #94a3b8;
}
.empty-state i { font-size: 72px; margin-bottom: 20px; display: block; color: #cbd5e1; }
.empty-state p { font-size: 16px; margin-bottom: 8px; }
.empty-state small { font-size: 13px; color: #a0aec0; }

.toast-notification {
    position: fixed;
    bottom: 20px;
    right: 20px;
    background: #1d3156;
    color: white;
    padding: 12px 24px;
    border-radius: 12px;
    font-size: 13px;
    font-weight: 500;
    z-index: 9999;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    animation: slideIn 0.3s ease;
}
@keyframes slideIn {
    from { transform: translateX(100%); opacity: 0; }
    to { transform: translateX(0); opacity: 1; }
}

/* Modal Styles */
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
.modal.active { display: flex; }
.modal-content {
    background: white;
    border-radius: 24px;
    width: 500px;
    max-width: 90%;
    padding: 28px;
    max-height: 90vh;
    overflow-y: auto;
}
.modal-content h3 {
    font-size: 20px;
    color: #1d3156;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 10px;
}
.modal-content .form-group {
    margin-bottom: 20px;
}
.modal-content label {
    display: block;
    font-weight: 600;
    font-size: 13px;
    color: #1d3156;
    margin-bottom: 6px;
}
.modal-content input, .modal-content textarea, .modal-content select {
    width: 100%;
    padding: 10px 14px;
    border: 1.5px solid #e2e8f0;
    border-radius: 12px;
    font-family: 'Poppins', sans-serif;
    font-size: 13px;
}
.modal-content textarea {
    resize: vertical;
    min-height: 80px;
}
.modal-buttons {
    display: flex;
    gap: 12px;
    justify-content: flex-end;
    margin-top: 20px;
}
.btn-save {
    background: #28a745;
    color: white;
    border: none;
    padding: 10px 24px;
    border-radius: 30px;
    font-weight: 600;
    cursor: pointer;
}
.btn-cancel {
    background: #e2e8f0;
    color: #475569;
    border: none;
    padding: 10px 24px;
    border-radius: 30px;
    font-weight: 600;
    cursor: pointer;
}

@media (max-width: 768px) {
    .materials-grid { grid-template-columns: 1fr; }
    .filter-row { flex-direction: column; align-items: stretch; }
    .filter-group, .search-group { min-width: auto; }
    .btn-search, .btn-reset { justify-content: center; width: 100%; }
    .page-header-centered h1 { font-size: 22px; }
    .nav-links { gap: 14px; }
    .nav-links a { font-size: 12px; }
    .action-buttons { flex-direction: column; }
    .session-row { flex-direction: column; align-items: flex-start; gap: 4px; }
    .session-label { width: auto; }
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
                <a href="material_overview.php" class="active">My Materials</a>
                <a href="assignments.php">My Assignments</a>
            </div>
            <div style="position:relative;">
                <button class="profile" onclick="toggleDropdown()">
                    <img src="<?= e($profilePic) ?>">
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
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 32px; position: relative;">
        <!-- Left: Back Button -->
        <a href="tutor_dashboard.php" class="back-btn" style="display: inline-flex; align-items: center; gap: 8px; background: white; color: #1d3156; padding: 10px 20px; border-radius: 40px; text-decoration: none; font-weight: 600; font-size: 14px; border: 1px solid #e2e8f0; transition: 0.25s;">
            <i class="bi bi-arrow-left"></i> Back
        </a>
        
        <!-- Center: Title -->
        <div style="position: absolute; left: 50%; transform: translateX(-50%); text-align: center;">
            <h1 style="font-size: 24px; font-weight: 800; color: #1d3156; margin: 0; letter-spacing: -0.5px;">
                <i class="bi bi-journal-bookmark-fill" style="margin-right: 10px;"></i> My Teaching Materials
            </h1>
            <p style="color: #1e293b; margin: 4px 0 0; font-size: 12px; font-weight: 500;">Manage all the learning materials you've shared with your students</p>
        </div>
        
        <!-- Right: Add Button -->
        <!-- Right: Add Button -->
<a href="select_booking.php?action=upload" class="add-material-btn" style="background: #1d3156; color: white; border: none; padding: 10px 24px; border-radius: 40px; font-weight: 600; font-size: 14px; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; transition: 0.2s; text-decoration: none;">
    <i class="bi bi-plus-lg"></i> Add New Material
</a>
    </div>

    <?php if ($deleteMessage): ?>
        <div class="alert alert-<?= $deleteMessageType === 'success' ? 'success' : 'error' ?>">
            <i class="bi bi-<?= $deleteMessageType === 'success' ? 'check-circle' : 'exclamation-circle' ?>"></i>
            <?= e($deleteMessage) ?>
        </div>
    <?php endif; ?>

    <?php if ($editMessage): ?>
        <div class="alert alert-<?= $editMessageType === 'success' ? 'success' : 'error' ?>">
            <i class="bi bi-<?= $editMessageType === 'success' ? 'check-circle' : 'exclamation-circle' ?>"></i>
            <?= e($editMessage) ?>
        </div>
    <?php endif; ?>

    <div class="filter-bar">
        <div class="filter-row">
            <div class="filter-group">
                <label><i class="bi bi-tag"></i> Session Type</label>
                <select id="sessionTypeFilter">
                    <option value="all">All Session</option>
                    <option value="pre">Pre-Session</option>
                    <option value="post">Post-Session</option>
                </select>
            </div>
                    <div class="filter-group">
            <label><i class="bi bi-file-earmark"></i> Material Type</label>
                    <select id="materialTypeFilter">
                        <option value="all">All Materials</option>
                        <option value="url">URL</option>
                        <option value="pdf">PDF</option>
                        <option value="word">Word</option>
                        <option value="excel">Excel</option>
                        <option value="powerpoint">PowerPoint</option>
                        <option value="image">Image</option>
                        <option value="video">Video</option>
                        <option value="audio">Audio</option>
                        <option value="archive">Archive</option>
                        <option value="text">Text</option>
                        <option value="other">Other</option>
                    </select>
                </div>
            
            <div class="filter-group search-group">
                <label><i class="bi bi-search"></i> Search</label>
                <input type="text" id="searchInput" placeholder="By title, student, language">
            </div>
            <div class="filter-group">
                <label><i class="bi bi-sort-alpha-down"></i> Sort By</label>
                <select id="sortBy">
                    <option value="latest">Latest First</option>
                    <option value="oldest">Oldest First</option>
                    <option value="title_az">Title (A-Z)</option>
                    <option value="title_za">Title (Z-A)</option>
                </select>
            </div>
            <div>
                <button class="btn-search" onclick="applyFilters()"><i class="bi bi-funnel"></i> Apply</button>
            </div>
            <div>
                <button class="btn-reset" onclick="resetFilters()"><i class="bi bi-arrow-counterclockwise"></i> Reset</button>
            </div>
        </div>
    </div>

    <div id="materialsContainer"></div>
</div>

<!-- Edit Modal -->
<div id="editModal" class="modal">
    <div class="modal-content">
        <h3><i class="bi bi-pencil-square"></i> Edit Material</h3>
        <form method="POST" action="" id="editForm">
            <input type="hidden" name="material_id" id="edit_material_id">
            <input type="hidden" name="edit_material" value="1">
            
            <div class="form-group">
                <label>Title</label>
                <input type="text" name="title" id="edit_title" required>
            </div>
            
            <div class="form-group">
                <label>Description</label>
                <textarea name="description" id="edit_description"></textarea>
            </div>
            
            <div class="form-group">
                <label>Material Type</label>
                <select name="material_type" id="edit_material_type">
                    <option value="">Select Type</option>
                    <option value="pre">Pre-Session (Before class)</option>
                    <option value="post">Post-Session (After class)</option>
                </select>
            </div>
            
            <div class="form-group">
                <label>Feedback for Student</label>
                <textarea name="feedback" id="edit_feedback" placeholder="Add comments or feedback for the student about this material..."></textarea>
            </div>
            
            <div class="modal-buttons">
                <button type="button" class="btn-cancel" onclick="closeEditModal()">Cancel</button>
                <button type="submit" class="btn-save">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- Delete Modal -->
<div id="deleteModal" class="modal">
    <div class="modal-content">
        <h3><i class="bi bi-trash3" style="color: #dc2626;"></i> Delete Material</h3>
        <p>Are you sure you want to delete this material? This action cannot be undone.</p>
        <form method="POST" action="" id="deleteForm">
            <input type="hidden" name="material_id" id="delete_material_id">
            <input type="hidden" name="delete_material" value="1">
            <div class="modal-buttons">
                <button type="button" class="btn-cancel" onclick="closeDeleteModal()">Cancel</button>
                <button type="submit" class="btn-confirm-delete" style="background:#dc2626; color:white; padding:10px 24px; border-radius:30px; border:none; font-weight:600; cursor:pointer;">Delete</button>
            </div>
        </form>
    </div>
</div>

<script>
const allMaterials = <?= json_encode(array_map(function($material) {
    return [
        'id' => $material['id'],
        'title' => $material['title'],
        'description' => $material['description'],
        'feedback' => $material['feedback'],
        'file_name' => $material['file_name'],
        'file_size' => $material['file_size'],
        'is_url' => $material['is_url'],
        'material_url' => $material['material_url'],
        'material_type' => $material['material_type'],
        'uploaded_at' => $material['uploaded_at'],
        'file_path' => $material['file_path'],
        'booking_language' => $material['booking_language'],
        'booking_date' => $material['booking_date'],
        'booking_time' => $material['booking_time'],
        'student_name' => $material['student_name'],
        'readable_type' => $material['is_url'] == 1 ? 'External Link' : getReadableFileType($material['file_name'], false),
        'icon_class' => getFileIconClassFromName($material['file_name'], $material['is_url'] == 1),
        'file_type_badge' => getFileTypeBadge($material['file_name'], $material['is_url'] == 1),
        'session_type_badge' => getSessionTypeBadge($material['material_type']),
        'session_display' => formatSessionDateTime($material['booking_date'], $material['booking_time'])
    ];
}, $allMaterials)) ?>;

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

function formatDate(dateString) {
    const date = new Date(dateString);
    return date.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
}

function showToast(message, color = '#1d3156') {
    const existing = document.querySelector('.toast-notification');
    if (existing) existing.remove();
    const toast = document.createElement('div');
    toast.className = 'toast-notification';
    toast.style.backgroundColor = color;
    toast.innerHTML = `<i class="bi bi-info-circle"></i> ${message}`;
    document.body.appendChild(toast);
    setTimeout(() => { toast.style.opacity = '0'; setTimeout(() => toast.remove(), 300); }, 3000);
}

function showEditModal(materialId) {
    const material = allMaterials.find(m => m.id == materialId);
    if (material) {
        document.getElementById('edit_material_id').value = material.id;
        document.getElementById('edit_title').value = material.title || '';
        document.getElementById('edit_description').value = material.description || '';
        document.getElementById('edit_material_type').value = material.material_type || '';
        document.getElementById('edit_feedback').value = material.feedback || '';
        document.getElementById('editModal').classList.add('active');
    }
}

function closeEditModal() {
    document.getElementById('editModal').classList.remove('active');
}

function showDeleteModal(materialId) {
    document.getElementById('delete_material_id').value = materialId;
    document.getElementById('deleteModal').classList.add('active');
}

function closeDeleteModal() {
    document.getElementById('deleteModal').classList.remove('active');
}

function renderMaterials(materials) {
    const container = document.getElementById('materialsContainer');
    
    if (!materials || materials.length === 0) {
        container.innerHTML = `<div class="empty-state"><i class="bi bi-journal-x"></i><p>No learning materials found</p><small>Click "Add New Material" to share your first learning resource with students</small></div>`;
        return;
    }
    
    let html = '<div class="materials-grid">';
    
    for (const material of materials) {
        const isUrl = material.is_url == 1;
        const viewUrl = isUrl ? material.material_url : '../uploads/learning_materials/' + material.file_path;
        const fileSizeDisplay = !isUrl && material.file_size ? formatFileSize(material.file_size) : '';
        const iconClass = material.icon_class || 'bi-file-earmark';
        
        let displayType;
        if (isUrl) {
            displayType = 'External Link';
        } else if (material.readable_type && material.readable_type !== 'File') {
            displayType = material.readable_type;
        } else if (material.file_name) {
            const ext = material.file_name.split('.').pop().toUpperCase();
            displayType = ext + ' File';
        } else {
            displayType = 'File';
        }
        
        const studentDisplay = material.student_name ? 
    `${escapeHtml(material.student_name)}` : 
    `<i class="bi bi-globe" style="color: #64748b;"></i> General Material`;
        // Language as plain text (no badge)
        const languageText = material.booking_language ? 
            `<span style="color: #f59e0b; font-size: 11px; font-weight: 500;"><i class="bi bi-translate"></i> ${escapeHtml(material.booking_language)}</span>` : '';
        
        // Session type as plain text (no badge)
        let sessionTypeText = '';
        if (material.material_type === 'pre') {
            sessionTypeText = `<span style="color: #c089ea; font-size: 12px; font-weight: 500;"><i class="bi bi-clock-history"></i> Pre-Session</span>`;
        } else if (material.material_type === 'post') {
            sessionTypeText = `<span style="color: #8c8bdd; font-size: 12px; font-weight: 500;"><i class="bi bi-check2-circle"></i> Post-Session</span>`;
        }
                
        const formattedDate = formatDate(material.uploaded_at);
        
        html += `
            <div class="material-card">
                <div class="card-header">
                    <!-- Bigger file icon -->
                    <div class="file-icon" style="width: 60px; height: 60px; background: #f1f5f9; border-radius: 16px; display: flex; align-items: center; justify-content: center; font-size: 32px; color: #496894; flex-shrink: 0;">
                        <i class="${iconClass}"></i>
                    </div>
                    <div class="material-info" style="flex: 1; min-width: 0;">
                        <div style="display: flex; justify-content: space-between; align-items: flex-start;">
                            <div class="material-title" style="font-weight: 700; font-size: 17px; color: #0f172a; word-break: break-word; line-height: 1.3;">${escapeHtml(material.title)}</div>
                            <div class="upload-date" style="font-size: 10px; color: #94a3b8; white-space: nowrap; margin-left: 10px;">
                                <i class="bi bi-clock"></i> ${formattedDate}
                            </div>
                        </div>
                        <!-- Language and Session Type as plain text -->
                        <div style="margin-top: 6px; display: flex; flex-wrap: wrap; gap: 12px;">
                            ${languageText}
                            ${sessionTypeText}
                        </div>
                    </div>
                </div>
                
                <div class="session-info" style="background: #f8fafc; border-radius: 12px; padding: 10px 12px; margin-bottom: 12px;">
                    <div class="session-row" style="display: flex; align-items: center; gap: 12px; font-size: 12px; margin-bottom: 6px;">
                        <span class="session-label" style="font-weight: 600; color: #1d3156; width: 70px; flex-shrink: 0;"><i class="bi bi-person"></i> Student:</span>
                        <span class="session-value" style="display: flex; align-items: center; gap: 6px; flex-wrap: wrap;">${studentDisplay}</span>
                    </div>
                    <div class="session-row" style="display: flex; align-items: center; gap: 12px; font-size: 12px;">
                        <span class="session-label" style="font-weight: 600; color: #1d3156; width: 70px; flex-shrink: 0;"><i class="bi bi-calendar-event"></i> Session:</span>
                        <span class="session-value" style="color: #475569;">${escapeHtml(material.session_display || 'No session linked')}</span>
                    </div>
                </div>
                
                ${material.description ? `<div class="material-description" style="font-size: 13px; color: #475569; line-height: 1.5; margin-bottom: 12px;">${escapeHtml(material.description)}</div>` : ''}
                
                ${material.feedback ? `
                    <div class="feedback-section" style="background: #fefce8; border-left: 3px solid #f59e0b; padding: 10px 12px; margin-bottom: 16px; border-radius: 8px;">
                        <div class="feedback-label" style="font-size: 11px; font-weight: 600; color: #f59e0b; margin-bottom: 4px; display: flex; align-items: center; gap: 4px;"><i class="bi bi-chat-dots"></i> Feedback:</div>
                        <div class="feedback-text" style="font-size: 12px; color: #475569; line-height: 1.4;">${escapeHtml(material.feedback)}</div>
                    </div>
                ` : ''}
                
                <div class="card-footer" style="display: flex; justify-content: space-between; align-items: center; border-top: 1px solid #eef2f7; padding-top: 14px; margin-top: 4px;">
                    <div class="file-info" style="display: flex; align-items: center; gap: 8px; font-size: 11px; color: #64748b;">
                        <i class="${iconClass}" style="font-size: 14px;"></i>
                        <span>${escapeHtml(displayType)}</span>
                        ${fileSizeDisplay ? `<span>• ${fileSizeDisplay}</span>` : ''}
                    </div>
                    <div class="action-buttons" style="display: flex; gap: 8px;">
                        <a href="${viewUrl}" class="btn-view" target="_blank" style="background: #e2e8f0; color: #1d3156; padding: 6px 14px; border-radius: 20px; font-size: 12px; font-weight: 600; text-decoration: none; display: inline-flex; align-items: center; gap: 5px;">
                            <i class="bi ${isUrl ? 'bi-box-arrow-up-right' : 'bi-download'}"></i> ${isUrl ? 'Open Link' : 'Download'}
                        </a>
                        <button class="btn-edit" onclick="showEditModal(${material.id})" style="background: #fef3c7; color: #f59e0b; padding: 6px 14px; border-radius: 20px; font-size: 12px; font-weight: 600; border: none; cursor: pointer; display: inline-flex; align-items: center; gap: 5px;">
                            <i class="bi bi-pencil"></i> Edit
                        </button>
                        <button class="btn-delete" onclick="showDeleteModal(${material.id})" style="background: #fee2e2; color: #dc2626; padding: 6px 14px; border-radius: 20px; font-size: 12px; font-weight: 600; border: none; cursor: pointer; display: inline-flex; align-items: center; gap: 5px;">
                            <i class="bi bi-trash3"></i> Delete
                        </button>
                    </div>
                </div>
            </div>
        `;
    }
    
    html += '</div>';
    container.innerHTML = html;
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

function formatFileSize(bytes) {
    if (!bytes || bytes === 0) return 'Unknown';
    const units = ['B', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(1024));
    return parseFloat((bytes / Math.pow(1024, i)).toFixed(1)) + ' ' + units[i];
}

function getMaterialTypeFromFile(fileName, isUrl) {
    if (isUrl) return 'url';
    if (!fileName) return 'other';
    
    const ext = fileName.split('.').pop().toLowerCase();
    
    const typeMap = {
        'pdf': 'pdf',
        'doc': 'word', 'docx': 'word',
        'xls': 'excel', 'xlsx': 'excel',
        'ppt': 'powerpoint', 'pptx': 'powerpoint',
        'jpg': 'image', 'jpeg': 'image', 'png': 'image', 'gif': 'image', 'webp': 'image',
        'mp4': 'video', 'mov': 'video', 'avi': 'video', 'mkv': 'video',
        'mp3': 'audio', 'wav': 'audio', 'ogg': 'audio',
        'zip': 'archive', 'rar': 'archive',
        'txt': 'text', 'md': 'text'
    };
    
    return typeMap[ext] || 'other';
}

function applyFilters() {
    const sortBy = document.getElementById('sortBy').value;
    const sessionType = document.getElementById('sessionTypeFilter').value;
    const materialType = document.getElementById('materialTypeFilter').value;
    const searchTerm = document.getElementById('searchInput').value.toLowerCase();
     const hasActiveFilters = (sessionType !== 'all') || 
                             (materialType !== 'all') || 
                             (searchTerm !== '');
    
    if (!hasActiveFilters) {
        showToast(' Please select at least one filter (Session Type, Material Type, or Search) before applying.', '#f59e0b');
        return;
    }
    let filtered = [...allMaterials];
    
    // Filter by session type (pre/post)
    if (sessionType !== 'all') {
        filtered = filtered.filter(m => m.material_type === sessionType);
    }
    
    // Filter by material type (pdf, link, video, etc.)
    if (materialType !== 'all') {
        filtered = filtered.filter(m => {
            const fileType = getMaterialTypeFromFile(m.file_name, m.is_url == 1);
            return fileType === materialType;
        });
    }
    
    // Filter by search term
    if (searchTerm) {
        filtered = filtered.filter(m => {
            return (m.title && m.title.toLowerCase().includes(searchTerm)) ||
                   (m.description && m.description.toLowerCase().includes(searchTerm)) ||
                   (m.student_name && m.student_name.toLowerCase().includes(searchTerm)) ||
                   (m.booking_language && m.booking_language.toLowerCase().includes(searchTerm)) ||
                   (m.feedback && m.feedback.toLowerCase().includes(searchTerm));
        });
    }
    
    // Sort
    switch (sortBy) {
        case 'latest': filtered.sort((a, b) => new Date(b.uploaded_at) - new Date(a.uploaded_at)); break;
        case 'oldest': filtered.sort((a, b) => new Date(a.uploaded_at) - new Date(b.uploaded_at)); break;
        case 'title_az': filtered.sort((a, b) => (a.title || '').localeCompare(b.title || '')); break;
        case 'title_za': filtered.sort((a, b) => (b.title || '').localeCompare(a.title || '')); break;
        default: filtered.sort((a, b) => new Date(b.uploaded_at) - new Date(a.uploaded_at));
    }
    
    renderMaterials(filtered);
    
    if (filtered.length === 0) {
        showToast('No materials match your filters', '#dc2626');
    } else if (filtered.length !== allMaterials.length) {
        showToast(`Found ${filtered.length} material${filtered.length !== 1 ? 's' : ''}`, '#28a745');
    }
}

function resetFilters() {
    document.getElementById('sortBy').value = 'latest';
    document.getElementById('sessionTypeFilter').value = 'all';
    document.getElementById('materialTypeFilter').value = 'all';
    document.getElementById('searchInput').value = '';
    

    let allSorted = [...allMaterials];
    
    allSorted.sort((a, b) => new Date(b.uploaded_at) - new Date(a.uploaded_at));
    
    renderMaterials(allSorted);
    
    showToast('Filters cleared. Showing all materials.', '#28a745');
}


setTimeout(function() {
    const alerts = document.querySelectorAll('.alert');
    alerts.forEach(alert => {
        alert.style.transition = 'opacity 0.5s';
        alert.style.opacity = '0';
        setTimeout(() => alert.remove(), 500);
    });
}, 4000);

document.addEventListener('DOMContentLoaded', function() {
    renderMaterials(allMaterials);
    document.getElementById('searchInput').addEventListener('keypress', function(e) {
        if (e.key === 'Enter') applyFilters();
    });
});

window.onclick = function(event) {
    const modal = document.getElementById('deleteModal');
    if (event.target === modal) closeDeleteModal();
    const editModal = document.getElementById('editModal');
    if (event.target === editModal) closeEditModal();
}
</script>

</body>
</html>