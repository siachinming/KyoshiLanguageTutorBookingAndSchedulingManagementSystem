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
    $proficiency_level = $_POST['proficiency_level'];
    $feedback = trim($_POST['feedback']);
    $replace_type = $_POST['replace_type'];
    
    // First, get current values to check if anything changed
    $checkStmt = $conn->prepare("SELECT title, description, material_type, proficiency_level, feedback FROM learning_materials WHERE id = ? AND tutor_id = ?");
    $checkStmt->bind_param("ii", $materialId, $userID);
    $checkStmt->execute();
    $current = $checkStmt->get_result()->fetch_assoc();
    
    $hasChanges = false;
    $updateFields = [];
    $updateValues = [];
    
    // Check each field for changes
    if ($current['title'] !== $title) {
        $hasChanges = true;
        $updateFields[] = "title = ?";
        $updateValues[] = $title;
    }
    if ($current['description'] !== $description) {
        $hasChanges = true;
        $updateFields[] = "description = ?";
        $updateValues[] = $description;
    }
    if ($current['material_type'] !== $materialType) {
        $hasChanges = true;
        $updateFields[] = "material_type = ?";
        $updateValues[] = $materialType;
    }
    if ($current['proficiency_level'] !== $proficiency_level) {
        $hasChanges = true;
        $updateFields[] = "proficiency_level = ?";
        $updateValues[] = $proficiency_level;
    }
    if ($current['feedback'] !== $feedback) {
        $hasChanges = true;
        $updateFields[] = "feedback = ?";
        $updateValues[] = $feedback;
    }
    // Handle file/URL replacement (these always count as changes)
        // Handle file/URL replacement (these always count as changes)
    if ($replace_type === 'file' && isset($_FILES['new_material_file']) && $_FILES['new_material_file']['error'] === UPLOAD_ERR_OK) {
        $hasChanges = true;
        
        // VALIDATE FILE
        $file = $_FILES['new_material_file'];
        $fileExt = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowedExts = ['pdf', 'doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png', 'mp4', 'mp3', 'zip', 'txt'];
        
        // Check file extension
        if (!in_array($fileExt, $allowedExts)) {
            $editMessage = "File type not allowed. Supported File Type : " . implode(', ', $allowedExts);
            $editMessageType = "error";
            $hasChanges = false;
        }
        // Check file size (max 50MB)
        elseif ($file['size'] > 50 * 1024 * 1024) {
            $editMessage = "File too large! Maximum size is 50MB";
            $editMessageType = "error";
            $hasChanges = false;
        }
        // For PDF files, verify it's actually a PDF
        elseif ($fileExt === 'pdf') {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
            
            if ($mimeType !== 'application/pdf') {
                $editMessage = "Invalid PDF file. Please upload a valid PDF document.";
                $editMessageType = "error";
                $hasChanges = false;
            }
        }
        // For images, verify they're actual images
        elseif (in_array($fileExt, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
            if (!getimagesize($file['tmp_name'])) {
                $editMessage = "Invalid image file. Please upload a valid image.";
                $editMessageType = "error";
                $hasChanges = false;
            }
        }
        
        if ($hasChanges) {
            // Delete old file
            $oldStmt = $conn->prepare("SELECT file_path, is_url FROM learning_materials WHERE id = ? AND tutor_id = ?");
            $oldStmt->bind_param("ii", $materialId, $userID);
            $oldStmt->execute();
            $oldMaterial = $oldStmt->get_result()->fetch_assoc();
            if ($oldMaterial && $oldMaterial['is_url'] == 0 && !empty($oldMaterial['file_path'])) {
                $oldFilePath = '../uploads/learning_materials/' . $oldMaterial['file_path'];
                if (file_exists($oldFilePath)) {
                    unlink($oldFilePath);
                }
            }
            
            // Upload new file
            $uploadDir = '../uploads/learning_materials/';
            if (!file_exists($uploadDir)) mkdir($uploadDir, 0777, true);
            $newFileName = time() . '_' . bin2hex(random_bytes(8)) . '.' . $fileExt;
            $newFilePath = $uploadDir . $newFileName;
            
            if (move_uploaded_file($file['tmp_name'], $newFilePath)) {
                $origName = $file['name'];
                $fileType = $file['type'];
                $fileSize = $file['size'];
                
                $updateFields[] = "file_name = ?";
                $updateFields[] = "file_path = ?";
                $updateFields[] = "file_type = ?";
                $updateFields[] = "file_size = ?";
                $updateFields[] = "is_url = ?";
                $updateFields[] = "material_url = ?";
                $updateValues[] = $origName;
                $updateValues[] = $newFileName;
                $updateValues[] = $fileType;
                $updateValues[] = $fileSize;
                $updateValues[] = 0;
                $updateValues[] = null;
            } else {
                $editMessage = "File upload failed.";
                $editMessageType = "error";
                $hasChanges = false;
            }
        }
    } 
    elseif ($replace_type === 'url' && !empty($_POST['new_material_url'])) {
        $hasChanges = true;
        $newUrl = trim($_POST['new_material_url']);
        
        // VALIDATE URL FORMAT
        if (!filter_var($newUrl, FILTER_VALIDATE_URL)) {
            $editMessage = "Invalid URL format. Please enter a valid URL (e.g., https://example.com)";
            $editMessageType = "error";
            $hasChanges = false;
        } 
        else {
            // Delete old file if exists
            if ($current && $current['is_url'] == 0 && !empty($current['file_path'])) {
                $oldFilePath = '../uploads/learning_materials/' . $current['file_path'];
                if (file_exists($oldFilePath)) {
                    unlink($oldFilePath);
                }
            }
            
            $updateFields[] = "material_url = ?";
            $updateFields[] = "is_url = ?";
            $updateFields[] = "file_name = ?";
            $updateFields[] = "file_path = ?";
            $updateValues[] = $newUrl;
            $updateValues[] = 1;
            $updateValues[] = null;
            $updateValues[] = null;
        }
    }
    
    // Only run update if there are changes
    if ($hasChanges && !empty($updateFields)) {
        $updateFields[] = "updated_at = NOW()";
        $sql = "UPDATE learning_materials SET " . implode(", ", $updateFields) . " WHERE id = ? AND tutor_id = ?";
        $updateValues[] = $materialId;
        $updateValues[] = $userID;
        
        $stmt = $conn->prepare($sql);
        $types = str_repeat("s", count($updateValues) - 2) . "ii";
        $stmt->bind_param($types, ...$updateValues);
        
        if ($stmt->execute()) {
            $editMessage = "Material updated successfully!";
            $editMessageType = "success";
        } else {
            $editMessage = "Error updating material: " . $conn->error;
            $editMessageType = "error";
        }
    } elseif (!$hasChanges) {
        $editMessage = "No changes were made.";
        $editMessageType = "warning";
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
        b.focus,
        b.proficiency_level,
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
<meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
<meta http-equiv="Pragma" content="no-cache">
<meta http-equiv="Expires" content="0">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>My Teaching Materials - Kyoshi Tutor</title>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../css/style.css">
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
/* Edit Modal Toggle Buttons */
.edit-toggle-row {
    display: flex;
    gap: 12px;
    margin-bottom: 16px;
}
.edit-toggle-btn {
    flex: 1;
    text-align: center;
    padding: 10px;
    border-radius: 30px;
    border: 1px solid #cbd5e1;
    background: #f8fafc;
    cursor: pointer;
    font-size: 13px;
    font-weight: 600;
    transition: all 0.2s;
    color: #1d3156;
}
.edit-toggle-btn.active {
    background: #1d3156;
    color: white;
    border-color: #1d3156;
}
.edit-toggle-btn:hover {
    background: #e2e8f0;
}
.edit-toggle-btn.active:hover {
    background: #142544;
}

.alert-warning { background: #fff3e0; color: #e67e22; border-left: 4px solid #f59e0b; }
/* File input with clear button */
.file-input-wrapper {
    position: relative;
    display: flex;
    gap: 10px;
    align-items: center;
    flex-wrap: wrap;
}
.file-input-wrapper input[type="file"] {
    flex: 1;
}
.btn-clear-file {
    background: #fee2e2;
    color: #dc2626;
    border: none;
    padding: 10px 16px;
    border-radius: 30px;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    transition: 0.2s;
    display: inline-flex;
    align-items: center;
    gap: 5px;
    white-space: nowrap;
}
.btn-clear-file:hover {
    background: #fecaca;
    transform: translateY(-1px);
}
.selected-file-name {
    font-size: 12px;
    color: #28a745;
    margin-top: 5px;
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}
.selected-file-name i {
    font-size: 14px;
}
.selected-file-name .remove-file {
    background: none;
    border: none;
    color: #dc2626;
    cursor: pointer;
    font-size: 14px;
    padding: 2px 6px;
    border-radius: 20px;
    transition: 0.2s;
}
.selected-file-name .remove-file:hover {
    background: #fee2e2;
}

.url-input-wrapper {
    display: flex;
    gap: 10px;
    align-items: center;
    flex-wrap: wrap;
}
.url-input-wrapper input {
    flex: 1;
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

/* Mobile responsive - hide button text on small screens */
@media (max-width: 900px) {
    .back-btn span {
        display: none;
    }
    
    .back-btn {
        padding: 10px 16px !important;
    }
    
    .add-material-btn span {
        display: none;
    }
    
    .add-material-btn {
        padding: 10px 16px !important;
    }

    .abc{
        margin-bottom: 20px;
    }

        .material-title {
        font-size: 15px !important;
    }
    
    .upload-date {
        font-size: 9px;
        align-items: flex-start;
    }
}

/* For very small screens */
@media (max-width: 600px) {
    .back-btn {
        padding: 8px 12px !important;
    }
    
    .add-material-btn {
        padding: 8px 12px !important;
    }
    
    .page-header-centered h1 {
        font-size: 18px !important;
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
            <a href="tutor_dashboard.php" class="brand">
                <img src="<?= e($assetBase) ?>/logo.png" alt="Kyoshi">
                <div><strong>Kyoshi</strong><span>Teacher Space</span></div>
            </a>
            <div class="nav-links">
                <a href="tutor_dashboard.php">Dashboard</a>
                <a href="booking_requests.php">My Bookings</a>
                <a href="material_overview.php" class="active">My Materials</a>
                <a href="assignment_overview.php">My Assignments</a>
                <a href="view_session_reports.php">My Reports</a>
            </div>
            <div class="nav-actions">
            <div style="position:relative;">
                <button class="profile" onclick="toggleDropdown()">
                    <img src="<?= e($profilePic) ?>">
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
            </div>
        </nav>
    </div>
</header>
 <div class="nav-overlay" id="navOverlay"></div>
<div class="main">
<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 32px; flex-wrap: wrap; gap: 16px;">
    <!-- Left: Back Button -->
    <a href="tutor_dashboard.php" class="back-btn" style="display: inline-flex; align-items: center; gap: 8px; background: white; color: #1d3156; padding: 10px 20px; border-radius: 40px; text-decoration: none; font-weight: 600; font-size: 14px; border: 1px solid #e2e8f0; transition: 0.25s;">
        <i class="bi bi-arrow-left"></i> <span>Back</span>
    </a>
    
    <!-- Center: Title -->
    <div style="text-align: center; flex: 1;">
        <h1 style="font-size: 24px; font-weight: 800; color: #1d3156; margin: 0; letter-spacing: -0.5px;">
            <i class="bi bi-journal-bookmark-fill" style="margin-right: 10px;"></i> My Teaching Materials
        </h1>
        <p style="color: #1e293b; margin: 4px 0 0; font-size: 12px; font-weight: 500;">Manage all the learning materials you've shared with your students</p>
    </div>
    
    <!-- Right: Add Button -->
    <a href="select_booking.php?action=upload" class="add-material-btn" style="background: #1d3156; color: white; border: none; padding: 10px 24px; border-radius: 40px; font-weight: 600; font-size: 14px; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; transition: 0.2s; text-decoration: none;">
        <i class="bi bi-plus-lg"></i> <span>Add New Material</span>
    </a>
</div>

    <?php if ($deleteMessage): ?>
        <div class="alert alert-<?= $deleteMessageType === 'success' ? 'success' : 'error' ?>">
            <i class="bi bi-<?= $deleteMessageType === 'success' ? 'check-circle' : 'exclamation-circle' ?>"></i>
            <?= e($deleteMessage) ?>
        </div>
    <?php endif; ?>

    <?php if ($editMessage): ?>
    <div class="alert alert-<?= $editMessageType === 'success' ? 'success' : ($editMessageType === 'warning' ? 'warning' : 'error') ?>">
        <i class="bi bi-<?= $editMessageType === 'success' ? 'check-circle' : ($editMessageType === 'warning' ? 'exclamation-triangle' : 'exclamation-circle') ?>"></i>
        <?= e($editMessage) ?>
    </div>
    <?php endif; ?>

    <div class="filter-bar">
        <div class="filter-row">
            <div class="filter-group search-group">
                <label>Search</label>
                <input type="text" id="searchInput" placeholder="By title, student, language">
            </div>
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
                        <option value="powerpoint">PowerPoint</option>
                        <option value="image">Image</option>
                        <option value="video">Video</option>
                        <option value="audio">Audio</option>
                        <option value="archive">Archive</option>
                        <option value="text">Text</option>
                        <option value="other">Other</option>
                    </select>
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
                <button class="btn-search" onclick="applyFilters()">Apply</button>
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
    <div class="modal-content" style="max-width: 600px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
    <h3 style="margin: 0; font-size: 20px; display: flex; align-items: center; gap: 8px;">
        <i class="bi bi-pencil-square"></i> Edit Material
    </h3>
    <button onclick="closeEditModal()" style="background: none; border: none; font-size: 22px; cursor: pointer; color: #64748b; width: 32px; height: 32px; display: flex; align-items: center; justify-content: center; border-radius: 50%; transition: all 0.2s;">
        <i class="bi bi-x-lg"></i>
    </button>
</div>
        <form method="POST" action="" id="editForm" enctype="multipart/form-data">
            <input type="hidden" name="material_id" id="edit_material_id">
            <input type="hidden" name="edit_material" value="1">
            <input type="hidden" name="current_file_path" id="edit_current_file_path">
            <input type="hidden" name="current_is_url" id="edit_current_is_url">
            
            <div class="form-group">
                <label>Title</label>
                <input type="text" name="title" id="edit_title" required>
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
                <label>Proficiency Level</label>
                <select name="proficiency_level" id="edit_proficiency_level">
                    <option value="beginner">Beginner</option>
                    <option value="intermediate">Intermediate</option>
                    <option value="advanced">Advanced</option>
                    <option value="master">Master</option>
                </select>
            </div>
            
            <div class="form-group">
                <label>Description</label>
                <textarea name="description" id="edit_description" rows="3"></textarea>
            </div>

            <div class="form-group">
                <label>Instruction / Feedback</label>
                <textarea name="feedback" id="edit_feedback" rows="3" placeholder="Add comments or feedback for the student..."></textarea>
            </div>

            <!-- Current File Display -->
            <div class="form-group" id="edit_current_file_section">
                <label>Current File/Link</label>
                <div id="edit_current_file_display" style="background: #f8fafc; padding: 10px; border-radius: 12px; font-size: 13px; word-break: break-all;">
                    <!-- Will be filled by JS -->
                </div>
            </div>

            <!-- Option to replace file/URL -->
            <div class="form-group">
                <div class="edit-toggle-row">
                    <div class="edit-toggle-btn active" data-edit-content="keep">Keep Current</div>
                    <div class="edit-toggle-btn" data-edit-content="file">Replace with File</div>
                    <div class="edit-toggle-btn" data-edit-content="url">Replace with URL</div>
                </div>
                <input type="hidden" name="replace_type" id="edit_replace_type" value="keep">
            </div>

            <div id="edit_file_section" style="display: none;">
                <div class="form-group">
                    <label>New File</label>
                    <div class="file-input-wrapper">
                        <input type="file" name="new_material_file" id="edit_new_file" accept=".pdf,.doc,.docx,.ppt,.pptx,.jpg,.jpeg,.png,.mp4,.mp3,.m4a,.mov,.zip">
                        <button type="button" class="btn-clear-file" id="clearFileBtn" onclick="clearSelectedFile()" style="display: none;">
                            <i class="bi bi-x-circle"></i> Clear
                        </button>
                    </div>
                    <small class="form-hint">Supported FIle Type: PDF, Word, PowerPoint, Images, Video, Audio</small>
                </div>
            </div>
            <div id="edit_url_section" style="display: none;">
            <div class="form-group">
                <label>New URL</label>
                <div class="url-input-wrapper">
                    <input type="url" name="new_material_url" id="edit_new_url" placeholder="https://...">
                    <button type="button" id="clearUrlBtn" class="btn-clear-file" style="display: none;" onclick="clearUrlInput()">
                        <i class="bi bi-x-circle"></i> Clear
                    </button>
                </div>
                <small class="form-hint"> Leave Empty if no changes</small>
            </div>
        </div>
                <br>
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
        'focus' => $material['focus'],
        'proficiency_level' => $material['proficiency_level'],
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

// Store original values for reset
let originalFilePath = null;
let originalIsUrl = null;
let originalMaterialUrl = null;
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

// Reset file inputs when cancel is clicked
function resetFileInputs() {
    const fileInput = document.getElementById('edit_new_file');
    const clearFileBtn = document.getElementById('clearFileBtn');
    const fileNameDisplay = document.getElementById('selectedFileName');
    
    if (fileInput) {
        fileInput.value = '';
        selectedFile = null;
    }
    if (clearFileBtn) {
        clearFileBtn.style.display = 'none';
    }
    if (fileNameDisplay) {
        fileNameDisplay.style.display = 'none';
        fileNameDisplay.innerHTML = '';
    }
}

// Reset URL inputs when cancel is clicked
function resetUrlInputs() {
    const urlInput = document.getElementById('edit_new_url');
    const clearUrlBtn = document.getElementById('clearUrlBtn');
    const urlDisplay = document.getElementById('selectedUrlDisplay');
    
    if (urlInput) {
        urlInput.value = '';
    }
    if (clearUrlBtn) {
        clearUrlBtn.style.display = 'none';
    }
    if (urlDisplay) {
        urlDisplay.style.display = 'none';
        urlDisplay.innerHTML = '';
    }
}

function showEditModal(materialId) {
    const material = allMaterials.find(m => m.id == materialId);
    if (material) {
        document.getElementById('edit_material_id').value = material.id;
        document.getElementById('edit_title').value = material.title || '';
        document.getElementById('edit_description').value = material.description || '';
        document.getElementById('edit_material_type').value = material.material_type || '';
        document.getElementById('edit_proficiency_level').value = material.proficiency_level || 'beginner';
        document.getElementById('edit_feedback').value = material.feedback || '';
        document.getElementById('edit_current_file_path').value = material.file_path || '';
        document.getElementById('edit_current_is_url').value = material.is_url || 0;
        
        // Show current file/link
        const currentDisplay = document.getElementById('edit_current_file_display');
        if (material.is_url == 1) {
            currentDisplay.innerHTML = `<i class="bi bi-link-45deg"></i> <a href="${material.material_url}" target="_blank">${material.material_url}</a>`;
        } else if (material.file_name) {
            currentDisplay.innerHTML = `<i class="bi bi-file-earmark"></i> ${material.file_name} ${material.file_size ? '(' + formatFileSize(material.file_size) + ')' : ''}`;
        } else {
            currentDisplay.innerHTML = 'No file attached';
        }
        
               // Store original values for reset on cancel
        originalFilePath = material.file_path || '';
        originalIsUrl = material.is_url || 0;
        originalMaterialUrl = material.material_url || '';
        
        // Reset file and URL inputs
        resetFileInputs();
        resetUrlInputs();
        
        // Reset replace type to "keep"
        document.getElementById('edit_replace_type').value = 'keep';
        document.getElementById('edit_file_section').style.display = 'none';
        document.getElementById('edit_url_section').style.display = 'none';

        // Reset toggle buttons using classList
        document.querySelectorAll('.edit-toggle-btn').forEach(btn => {
            btn.classList.remove('active');
        });
        document.querySelector('.edit-toggle-btn[data-edit-content="keep"]').classList.add('active');
        document.getElementById('editModal').classList.add('active');
    }
}
// Edit content type toggle
document.querySelectorAll('.edit-toggle-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        document.querySelectorAll('.edit-toggle-btn').forEach(b => {
            b.classList.remove('active');
        });
        this.classList.add('active');
        
        const type = this.dataset.editContent;
        document.getElementById('edit_replace_type').value = type;
        
        const fileSection = document.getElementById('edit_file_section');
        const urlSection = document.getElementById('edit_url_section');
        
        if (type === 'file') {
            fileSection.style.display = 'block';
            urlSection.style.display = 'none';
        } else if (type === 'url') {
            fileSection.style.display = 'none';
            urlSection.style.display = 'block';
        } else {
            fileSection.style.display = 'none';
            urlSection.style.display = 'none';
        }
    });
});

function closeEditModal() {
    resetFileInputs();
    resetUrlInputs();
    document.getElementById('editModal').classList.remove('active');
}

function showDeleteModal(materialId) {
    document.getElementById('delete_material_id').value = materialId;
    document.getElementById('deleteModal').classList.add('active');
}

function closeDeleteModal() {
    document.getElementById('deleteModal').classList.remove('active');
}
function capitalizeLevel(level) {
    if (!level) return 'Not specified';
    const levelMap = {
        'beginner': 'Beginner',
        'intermediate': 'Intermediate',
        'advanced': 'Advanced',
        'master': 'Master'
    };
    return levelMap[level.toLowerCase()] || level.charAt(0).toUpperCase() + level.slice(1);
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
        // Remove any double 'uploads/' pattern
let cleanPath = material.file_path;
cleanPath = cleanPath.replace('uploads/uploads/', 'uploads/');
cleanPath = cleanPath.replace('../uploads/uploads/', '../uploads/');

const viewUrl = isUrl ? material.material_url : cleanPath;
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
                    <div class="session-row" style="display: flex; align-items: center; gap: 12px; font-size: 12px; margin-bottom: 6px;">
                        <span class="session-label" style="font-weight: 600; color: #1d3156; width: 70px; flex-shrink: 0;"><i class="bi bi-bullseye"></i> Focus:</span>
                        <span class="session-value" style="color: #475569;">${material.focus ? escapeHtml(material.focus) : 'Not specified'}</span>
                    </div>
                    <div class="session-row" style="display: flex; align-items: center; gap: 12px; font-size: 12px; margin-bottom: 6px;">
                        <span class="session-label" style="font-weight: 600; color: #1d3156; width: 70px; flex-shrink: 0;"><i class="bi bi-graph-up"></i> Level:</span>
                        <span class="session-value" style="color: #475569;">${material.proficiency_level ? capitalizeLevel(escapeHtml(material.proficiency_level)) : 'Not specified'}</span>
                    </div>
                    <div class="session-row" style="display: flex; align-items: center; gap: 12px; font-size: 12px;">
                        <span class="session-label" style="font-weight: 600; color: #1d3156; width: 70px; flex-shrink: 0;"><i class="bi bi-calendar-event"></i> Session:</span>
                        <span class="session-value" style="color: #475569;">${escapeHtml(material.session_display || 'No session linked')}</span>
                    </div>
                </div>
                
               ${material.description ? 
    `<div class="material-description">${escapeHtml(material.description)}</div>` : 
    `<div class="material-description" style="color: #94a3b8; font-style: italic;"><i class="bi bi-info-circle"></i> No description provided</div>`}
                
                ${material.feedback ? 
    `<div class="feedback-section">
        <div class="feedback-label">
            <i class="bi bi-chat-dots"></i> Feedback:
        </div>
        <div class="feedback-text">
            ${escapeHtml(material.feedback)}
        </div>
     </div>` : 
    `<div class="feedback-section" style="background: #f8fafc; border-left-color: #cbd5e1;">
        <div class="feedback-label" style="color: #64748b;">
            <i class="bi bi-chat-dots"></i> Feedback:
        </div>
        <div class="feedback-text" style="color: #94a3b8;">
            No feedback added yet
        </div>
     </div>`
}
                
                <div class="card-footer" style="display: flex; justify-content: space-between; align-items: center; border-top: 1px solid #eef2f7; padding-top: 14px; margin-top: 4px;">
                    <div class="file-info" style="display: flex; align-items: center; gap: 8px; font-size: 11px; color: #64748b;">
                        <i class="${iconClass}" style="font-size: 14px;"></i>
                        <span>${escapeHtml(displayType)}</span>
                        ${fileSizeDisplay ? `<span>• ${fileSizeDisplay}</span>` : ''}
                    </div>
                   <div class="action-buttons" style="display: flex; gap: 8px;">
    ${isUrl ? 
        `<a href="${material.material_url}" class="btn-view" target="_blank" style="background: #e2e8f0; color: #1d3156; padding: 6px 14px; border-radius: 20px; font-size: 12px; font-weight: 600; text-decoration: none; display: inline-flex; align-items: center; gap: 5px;">
            <i class="bi bi-box-arrow-up-right"></i> Open Link
        </a>` : 
        `<a href="view_materials.php?id=${material.id}&booking_id=${material.booking_id}" class="btn-view" style="background: #e2e8f0; color: #1d3156; padding: 6px 14px; border-radius: 20px; font-size: 12px; font-weight: 600; text-decoration: none; display: inline-flex; align-items: center; gap: 5px;">
            <i class="bi bi-eye"></i> Preview
        </a>`
    }
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
    const searchTerm = document.getElementById('searchInput').value.toLowerCase().trim();
    
    const hasActiveFilters = (sessionType !== 'all') || 
                             (materialType !== 'all') || 
                             (searchTerm !== '');
    
    if (!hasActiveFilters) {
        showToast('Please select at least one filter (Session Type, Material Type, or Search) before applying.', '#f59e0b');
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
    
    // Helper function to get material type text for searching
    function getMaterialTypeSearchText(m) {
        if (m.is_url == 1) return 'url link external';
        if (!m.file_name) return 'file';
        
        const ext = m.file_name.split('.').pop().toLowerCase();
        const typeMap = {
            'pdf': 'pdf document',
            'doc': 'word document', 'docx': 'word document',
            'ppt': 'powerpoint presentation', 'pptx': 'powerpoint presentation',
            'xls': 'excel spreadsheet', 'xlsx': 'excel spreadsheet',
            'jpg': 'image picture photo', 'jpeg': 'image picture photo',
            'png': 'image picture photo', 'gif': 'image picture photo',
            'mp4': 'video movie', 'mov': 'video movie', 'avi': 'video movie',
            'mp3': 'audio music', 'wav': 'audio music',
            'zip': 'archive compressed zip', 'rar': 'archive compressed',
            'txt': 'text file'
        };
        return typeMap[ext] || ext;
    }
    
    // Filter by search term (UPDATED to include material type AND student name)
    if (searchTerm) {
        filtered = filtered.filter(m => {
            const materialTypeText = getMaterialTypeSearchText(m);
            // Debug: log what we're searching
            console.log('Searching for:', searchTerm);
            console.log('Material title:', m.title);
            console.log('Student name:', m.student_name);
            
            return (m.title && m.title.toLowerCase().includes(searchTerm)) ||
                   (m.description && m.description.toLowerCase().includes(searchTerm)) ||
                   (m.student_name && m.student_name.toLowerCase().includes(searchTerm)) ||
                   (m.booking_language && m.booking_language.toLowerCase().includes(searchTerm)) ||
                   (m.feedback && m.feedback.toLowerCase().includes(searchTerm)) ||
                   (materialTypeText && materialTypeText.toLowerCase().includes(searchTerm));
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
    
    // Show appropriate toast message
    if (filtered.length === 0) {
        showToast('No materials match your search/filters', '#dc2626');
    } else {
        // Always show how many found when filters are applied
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

// Track selected file
let selectedFile = null;

// Handle file selection change
// Handle file selection change with client-side validation
document.getElementById('edit_new_file').addEventListener('change', function(e) {
    const file = e.target.files[0];
    const clearBtn = document.getElementById('clearFileBtn');
    const fileNameDisplay = document.getElementById('selectedFileName');
    
    if (file) {
        selectedFile = file;
        // Check file size (max 50MB)
        if (file.size > 50 * 1024 * 1024) {
            showToast('File too large! Maximum size is 50MB', '#dc2626');
            this.value = '';
            selectedFile = null;
            clearBtn.style.display = 'none';
            fileNameDisplay.style.display = 'none';
            return;
        }
        
        // Check file type extension
        const allowedTypes = ['pdf', 'doc', 'docx', 'ppt', 'pptx', 'jpg', 'jpeg', 'png', 'mp4', 'mp3', 'm4a', 'mov', 'zip'];
        const fileExt = file.name.split('.').pop().toLowerCase();
        if (!allowedTypes.includes(fileExt)) {
            showToast('File type not allowed!', '#dc2626');
            this.value = '';
            selectedFile = null;
            clearBtn.style.display = 'none';
            fileNameDisplay.style.display = 'none';
            return;
        }
        
        // CLIENT-SIDE PDF VALIDATION - Check PDF magic bytes (%PDF)
        if (fileExt === 'pdf') {
            const reader = new FileReader();
            reader.onload = function(e) {
                const buffer = new Uint8Array(e.target.result);
                // PDF files start with "%PDF" (bytes: 37, 80, 68, 70)
                if (buffer[0] !== 37 || buffer[1] !== 80 || buffer[2] !== 68 || buffer[3] !== 70) {
                    showToast('Invalid PDF file! Please upload a valid PDF document.', '#dc2626');
                    fileInput.value = '';
                    selectedFile = null;
                    clearBtn.style.display = 'none';
                    fileNameDisplay.style.display = 'none';
                    return;
                }
                // Valid PDF - show file info
                clearBtn.style.display = 'inline-flex';
                fileNameDisplay.style.display = 'flex';
                fileNameDisplay.innerHTML = `
                    <i class="bi bi-file-earmark-check"></i>
                    <span>${escapeHtml(file.name)} (${formatFileSize(file.size)})</span>
                    <button type="button" class="remove-file" onclick="removeSelectedFile()">
                        <i class="bi bi-x-lg"></i>
                    </button>
                `;
            };
            reader.readAsArrayBuffer(file.slice(0, 5));
            return; // Wait for validation
        }
        
        // CLIENT-SIDE IMAGE VALIDATION
        if (fileExt === 'jpg' || fileExt === 'jpeg' || fileExt === 'png') {
            const reader = new FileReader();
            reader.onload = function(e) {
                const img = new Image();
                img.onload = function() {
                    // Valid image
                    clearBtn.style.display = 'inline-flex';
                    fileNameDisplay.style.display = 'flex';
                    fileNameDisplay.innerHTML = `
                        <i class="bi bi-file-earmark-check"></i>
                        <span>${escapeHtml(file.name)} (${formatFileSize(file.size)})</span>
                        <button type="button" class="remove-file" onclick="removeSelectedFile()">
                            <i class="bi bi-x-lg"></i>
                        </button>
                    `;
                };
                img.onerror = function() {
                    showToast('Invalid image file! Please upload a valid image.', '#dc2626');
                    this.value = '';
                    selectedFile = null;
                    clearBtn.style.display = 'none';
                    fileNameDisplay.style.display = 'none';
                };
                img.src = e.target.result;
            };
            reader.readAsDataURL(file);
            return; // Wait for validation
        }
        
        // For other file types (doc, docx, mp4, etc.), just show file info
        clearBtn.style.display = 'inline-flex';
        fileNameDisplay.style.display = 'flex';
        fileNameDisplay.innerHTML = `
            <i class="bi bi-file-earmark-check"></i>
            <span>${escapeHtml(file.name)} (${formatFileSize(file.size)})</span>
            <button type="button" class="remove-file" onclick="removeSelectedFile()">
                <i class="bi bi-x-lg"></i>
            </button>
        `;
    } else {
        clearSelectedFile();
    }
});

function clearSelectedFile() {
    const fileInput = document.getElementById('edit_new_file');
    const clearBtn = document.getElementById('clearFileBtn');
    const fileNameDisplay = document.getElementById('selectedFileName');
    
    fileInput.value = '';
    selectedFile = null;
    clearBtn.style.display = 'none';
    fileNameDisplay.style.display = 'none';
    fileNameDisplay.innerHTML = '';

    showToast('File selection cleared', '#64748b');
}

function removeSelectedFile() {
    clearSelectedFile();
    showToast('File selection cleared', '#64748b');
}

// Also update the URL section to have a clear button
function updateUrlSection() {
    const urlInput = document.getElementById('edit_new_url');
    if (urlInput && urlInput.value) {
        // Add a clear button next to URL input if needed
        const urlWrapper = urlInput.parentElement;
        if (!document.getElementById('clearUrlBtn') && urlWrapper) {
            const clearBtn = document.createElement('button');
            clearBtn.id = 'clearUrlBtn';
            clearBtn.type = 'button';
            clearBtn.className = 'btn-clear-file';
            clearBtn.style.marginLeft = '10px';
            clearBtn.innerHTML = '<i class="bi bi-x-circle"></i> Clear';
            clearBtn.onclick = function() {
                urlInput.value = '';
                this.style.display = 'none';
                showToast('URL cleared', '#64748b');
            };
            urlWrapper.appendChild(clearBtn);
        }
        const clearUrlBtn = document.getElementById('clearUrlBtn');
        if (clearUrlBtn) {
            clearUrlBtn.style.display = urlInput.value ? 'inline-flex' : 'none';
        }
    }
}

// Add event listener for URL input
document.addEventListener('DOMContentLoaded', function() {
    const urlInput = document.getElementById('edit_new_url');
    if (urlInput) {
        urlInput.addEventListener('input', function() {
            const clearUrlBtn = document.getElementById('clearUrlBtn');
            if (clearUrlBtn) {
                clearUrlBtn.style.display = this.value ? 'inline-flex' : 'none';
            }
        });
    }
});

function clearUrlInput() {
    document.getElementById('edit_new_url').value = '';
    document.getElementById('clearUrlBtn').style.display = 'none';
    showToast('URL cleared', '#64748b');
}

window.onclick = function(event) {
    const modal = document.getElementById('deleteModal');
    if (event.target === modal) closeDeleteModal();
    const editModal = document.getElementById('editModal');
    if (event.target === editModal) closeEditModal();
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