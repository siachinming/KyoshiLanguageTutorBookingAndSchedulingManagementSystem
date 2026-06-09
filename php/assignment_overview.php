<?php
session_start();
include 'config.php';
include 'check_login.php';
$assetBase = '../assets/img';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'tutor') {
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

// Handle Delete Assignment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_assignment'])) {
    $assignmentId = $_POST['assignment_id'];
    
    $stmt = $conn->prepare("SELECT file_path FROM assignment_submissions WHERE assignment_id = ?");
    $stmt->bind_param("i", $assignmentId);
    $stmt->execute();
    $submissions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    foreach ($submissions as $sub) {
        if (!empty($sub['file_path'])) {
            $filePath = '../uploads/assignments/' . $sub['file_path'];
            if (file_exists($filePath)) {
                unlink($filePath);
            }
        }
    }
    
    $stmt = $conn->prepare("DELETE FROM assignment_submissions WHERE assignment_id = ?");
    $stmt->bind_param("i", $assignmentId);
    $stmt->execute();
    
    $stmt = $conn->prepare("DELETE FROM assignments WHERE id = ? AND tutor_id = ?");
    $stmt->bind_param("ii", $assignmentId, $userID);
    if ($stmt->execute()) {
        $deleteMessage = "Assignment deleted successfully!";
        $deleteMessageType = "success";
    } else {
        $deleteMessage = "Error deleting assignment.";
        $deleteMessageType = "error";
    }
}

// Handle Edit Assignment with file/URL
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_assignment'])) {
    $assignmentId = $_POST['assignment_id'];
    $title       = trim($_POST['title']       ?? '');
    $allowLate = isset($_POST['allow_late_submission']) ? 1 : 0;
    $description = trim($_POST['description'] ?? '');
    $due_date = !empty($_POST['due_date']) 
    ? str_replace('T', ' ', $_POST['due_date']) . ':00'
    : null;
    $replace_type = $_POST['replace_type'] ?? 'keep';
    $late_cutoff_type = $_POST['late_cutoff_type'] ?? 'no_limit';
    $late_days = isset($_POST['late_days']) ? intval($_POST['late_days']) : null;
    $late_cutoff_date_raw = $_POST['late_cutoff_date'] ?? '';
    
    // Process late cutoff date
    if (!empty($late_cutoff_date_raw)) {
        $late_cutoff_date = str_replace('T', ' ', $late_cutoff_date_raw) . ':00';
    } else {
        $late_cutoff_date = null;
    }

  $stmt = $conn->prepare("SELECT * FROM assignments WHERE id = ? AND tutor_id = ?");
    $stmt->bind_param("ii", $assignmentId, $userID);
    $stmt->execute();
    $current = $stmt->get_result()->fetch_assoc();
    
    $hasChanges = false;
    $updateFields = [];
    $updateValues = [];
    
    if ($current && $current['title'] !== $title) {
        $hasChanges = true;
        $updateFields[] = "title = ?";
        $updateValues[] = $title;
    }
    
    if ($current && $current['description'] !== $description) {
        $hasChanges = true;
        $updateFields[] = "description = ?";
        $updateValues[] = $description;
    }
    
    $currentDueDate = $current['due_date'] ?? null;
    if ($currentDueDate != $due_date) {
        $hasChanges = true;
        $updateFields[] = "due_date = ?";
        $updateValues[] = $due_date;
    }

    if ($current && $current['allow_late_submission'] != $allowLate) {
    $hasChanges = true;
    $updateFields[] = "allow_late_submission = ?";
    $updateValues[] = $allowLate;
}
        if ($current && $current['late_cutoff_type'] != $late_cutoff_type) {
        $hasChanges = true;
        $updateFields[] = "late_cutoff_type = ?";
        $updateValues[] = $late_cutoff_type;
    }
    
    if ($current && $current['late_days'] != $late_days) {
        $hasChanges = true;
        $updateFields[] = "late_days = ?";
        $updateValues[] = $late_days;
    }
    
    if ($current && $current['late_cutoff_date'] != $late_cutoff_date) {
        $hasChanges = true;
        $updateFields[] = "late_cutoff_date = ?";
        $updateValues[] = $late_cutoff_date;
    }
    if ($replace_type === 'file' && isset($_FILES['new_assignment_file']) && $_FILES['new_assignment_file']['error'] === UPLOAD_ERR_OK) {
        $hasChanges = true;
        $file = $_FILES['new_assignment_file'];
        $fileExt = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowedExts = ['pdf', 'doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png', 'mp4', 'mp3', 'zip', 'txt'];
        
        if (!in_array($fileExt, $allowedExts)) {
            $editMessage = "File type not allowed. Allowed: " . implode(', ', $allowedExts);
            $editMessageType = "error";
            $hasChanges = false;
        } elseif ($file['size'] > 50 * 1024 * 1024) {
            $editMessage = "File too large! Maximum size is 50MB";
            $editMessageType = "error";
            $hasChanges = false;
        } elseif ($fileExt === 'pdf') {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
            if ($mimeType !== 'application/pdf') {
                $editMessage = "Invalid PDF file. Please upload a valid PDF document.";
                $editMessageType = "error";
                $hasChanges = false;
            }
        } elseif (in_array($fileExt, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
            if (!getimagesize($file['tmp_name'])) {
                $editMessage = "Invalid image file. Please upload a valid image.";
                $editMessageType = "error";
                $hasChanges = false;
            }
        }
        
        if ($hasChanges) {
            if ($current && $current['is_url'] == 0 && !empty($current['file_path'])) {
                $oldFilePath = '../uploads/assignments/' . $current['file_path'];
                if (file_exists($oldFilePath)) unlink($oldFilePath);
            }
            
            $uploadDir = '../uploads/assignments/';
            if (!file_exists($uploadDir)) mkdir($uploadDir, 0777, true);
            $newFileName = time() . '_' . bin2hex(random_bytes(8)) . '.' . $fileExt;
            $newFilePath = $uploadDir . $newFileName;
            
            if (move_uploaded_file($file['tmp_name'], $newFilePath)) {
                $updateFields[] = "file_name = ?";
                $updateFields[] = "file_path = ?";
                $updateFields[] = "file_type = ?";
                $updateFields[] = "file_size = ?";
                $updateFields[] = "is_url = ?";
                $updateFields[] = "material_url = ?";
                $updateValues[] = $file['name'];
                $updateValues[] = $newFileName;
                $updateValues[] = $file['type'];
                $updateValues[] = $file['size'];
                $updateValues[] = 0;
                $updateValues[] = null;
            } else {
                $editMessage = "File upload failed.";
                $editMessageType = "error";
                $hasChanges = false;
            }
        }
    } elseif ($replace_type === 'url' && !empty($_POST['new_material_url'])) {
        $hasChanges = true;
        $newUrl = trim($_POST['new_material_url']);
        
        if (!filter_var($newUrl, FILTER_VALIDATE_URL)) {
            $editMessage = "Invalid URL format. Please enter a valid URL (e.g., https://example.com)";
            $editMessageType = "error";
            $hasChanges = false;
        } else {
            if ($current && $current['is_url'] == 0 && !empty($current['file_path'])) {
                $oldFilePath = '../uploads/assignments/' . $current['file_path'];
                if (file_exists($oldFilePath)) unlink($oldFilePath);
            }
            
            $updateFields[] = "material_url = ?";
            $updateFields[] = "is_url = ?";
            $updateFields[] = "file_name = ?";
            $updateFields[] = "file_path = ?";
            $updateFields[] = "file_type = ?";
            $updateFields[] = "file_size = ?";
            $updateValues[] = $newUrl;
            $updateValues[] = 1;
            $updateValues[] = null;
            $updateValues[] = null;
            $updateValues[] = null;
            $updateValues[] = null;
        }
    } elseif ($replace_type === 'remove') {
        $hasChanges = true;
        if ($current && $current['is_url'] == 0 && !empty($current['file_path'])) {
            $oldFilePath = '../uploads/assignments/' . $current['file_path'];
            if (file_exists($oldFilePath)) unlink($oldFilePath);
        }
        
        $updateFields[] = "material_url = ?";
        $updateFields[] = "is_url = ?";
        $updateFields[] = "file_name = ?";
        $updateFields[] = "file_path = ?";
        $updateFields[] = "file_type = ?";
        $updateFields[] = "file_size = ?";
        $updateValues[] = null;
        $updateValues[] = 0;
        $updateValues[] = null;
        $updateValues[] = null;
        $updateValues[] = null;
        $updateValues[] = null;
    }
    
    if ($hasChanges && !empty($updateFields)) {
        $updateFields[] = "updated_at = NOW()";
        $sql = "UPDATE assignments SET " . implode(", ", $updateFields) . " WHERE id = ? AND tutor_id = ?";
        $updateValues[] = $assignmentId;
        $updateValues[] = $userID;
        
        $stmt = $conn->prepare($sql);
        $types = str_repeat("s", count($updateValues) - 2) . "ii";
        $stmt->bind_param($types, ...$updateValues);
        
        if ($stmt->execute()) {
            $editMessage = "Assignment updated successfully!";
            $editMessageType = "success";
        } else {
            $editMessage = "Error updating assignment: " . $conn->error;
            $editMessageType = "error";
        }
    } elseif (!$hasChanges) {
        $editMessage = "No changes were made.";
        $editMessageType = "warning";
    }
}

// Fetch all assignments with booking info
$stmt = $conn->prepare("
    SELECT 
        a.*,
        a.allow_late_submission,
        b.language,
        b.booking_date,
        b.booking_time,
        u.fullname as student_name,
        u.id as student_id,
        (SELECT COUNT(*) FROM assignment_submissions WHERE assignment_id = a.id) as submission_count
    FROM assignments a
    LEFT JOIN bookings b ON a.booking_id = b.id
    LEFT JOIN users u ON b.student_id = u.id
    WHERE a.tutor_id = ?
    ORDER BY a.created_at DESC
");
$stmt->bind_param("i", $userID);
$stmt->execute();
$assignments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get submissions for each assignment
$submissionsByAssignment = [];
foreach ($assignments as $assignment) {
    $stmt = $conn->prepare("
    SELECT s.id, s.assignment_id, s.student_id, s.tutor_id, s.booking_id, 
           s.submission_text, s.file_name, s.file_path, s.file_type, s.file_size, 
           s.status, s.submitted_at, s.reviewed_at, s.feedback, s.grade, s.graded_at,
           u.fullname as student_name
    FROM assignment_submissions s
    JOIN users u ON s.student_id = u.id
    WHERE s.assignment_id = ?
    ORDER BY s.submitted_at DESC
");
    $stmt->bind_param("i", $assignment['id']);
    $stmt->execute();
    $submissionsByAssignment[$assignment['id']] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

$stmt = $conn->prepare("SELECT DISTINCT language FROM tutor_languages WHERE user_id = ?");
$stmt->bind_param("i", $userID);
$stmt->execute();
$tutorLanguages = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

function e($value) {
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function formatFileSize($bytes) {
    if (!$bytes) return '';
    if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024) return round($bytes / 1024, 1) . ' KB';
    return $bytes . ' bytes';
}
function getAssignmentAttachmentDisplay($assignment) {
    if ($assignment['is_url'] == 1 && !empty($assignment['material_url'])) {
        // Handle multiple URLs (pipe-separated)
        $urls = explode('|', $assignment['material_url']);
        if (count($urls) > 1) {
            $html = '<div class="current-attachment"><i class="bi bi-link-45deg"></i> Multiple Links:<br>';
            foreach ($urls as $url) {
                $html .= '<div style="margin-left: 20px; margin-top: 5px;">
                            <i class="bi bi-link"></i> <a href="' . e($url) . '" target="_blank">' . e($url) . '</a>
                          </div>';
            }
            $html .= '</div>';
            return $html;
        }
        return '<div class="current-attachment"><i class="bi bi-link-45deg"></i> <a href="' . e($assignment['material_url']) . '" target="_blank">' . e($assignment['material_url']) . '</a></div>';
    } elseif (!empty($assignment['file_name'])) {
        // Handle multiple files (pipe-separated)
        $fileNames = explode('|', $assignment['file_name']);
        $filePaths = !empty($assignment['file_path']) ? explode('|', $assignment['file_path']) : [];
        
        if (count($fileNames) > 1) {
            $html = '<div class="current-attachment"><i class="bi bi-files"></i> Multiple Files:<br>';
            foreach ($fileNames as $i => $name) {
                $path = isset($filePaths[$i]) ? $filePaths[$i] : '';
                $html .= '<div style="margin-left: 20px; margin-top: 5px;">
                            <i class="bi bi-file-earmark"></i> ' . e($name) . ' 
                            <a href="../uploads/assignments/' . e($path) . '" target="_blank" style="margin-left: 10px; font-size: 11px;">[Download]</a>
                          </div>';
            }
            $html .= '</div>';
            return $html;
        } else {
            // Single file
            $path = !empty($filePaths[0]) ? $filePaths[0] : $assignment['file_path'];
            return '<div class="current-attachment"><i class="bi bi-file-earmark"></i> ' . e($assignment['file_name']) . ' (' . formatFileSize($assignment['file_size']) . ')</div>';
        }
    }
    return '<div class="current-attachment"><i class="bi bi-exclamation-triangle"></i> No attachment</div>';
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
<title>Assignments - Kyoshi Tutor</title>
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
.form-hint-small {
    font-size: 10px;
    color: #94a3b8;
    margin-top: 4px;
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
.create-btn {
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
.create-btn:hover { background: #142544; transform: translateY(-2px); }
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
.search-group input {
    padding-left: 36px;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%2394a3b8' stroke-width='2'%3E%3Ccircle cx='11' cy='11' r='8'%3E%3C/circle%3E%3Cline x1='21' y1='21' x2='16.65' y2='16.65'%3E%3C/line%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: 12px center;
}
.btn-search, .btn-reset {
    padding: 10px 24px;
    border-radius: 10px;
    font-weight: 600;
    cursor: pointer;
    border: none;
}
.btn-search { background: #1d3156; color: white; }
.btn-search:hover { background: #142544; }
.btn-reset { background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0; }
.btn-reset:hover { background: #e2e8f0; }
.alert {
    padding: 12px 20px;
    border-radius: 12px;
    margin-bottom: 20px;
    font-weight: 500;
}
.alert-success { background: #d4edda; color: #155724; border-left: 4px solid #28a745; }
.alert-error { background: #f8d7da; color: #721c24; border-left: 4px solid #dc2626; }
.alert-warning { background: #fff3e0; color: #e67e22; border-left: 4px solid #f59e0b; }
.assignment-card {
    background: white;
    border-radius: 20px;
    margin-bottom: 20px;
    overflow: hidden;
    border: 1px solid #eef2f7;
    transition: all 0.3s ease;
}
.assignment-card:hover {
    box-shadow: 0 8px 25px rgba(0,0,0,0.08);
    transform: translateY(-2px);
}
.assignment-header {
    padding: 20px 24px;
    background: #f8fafc;
    border-bottom: 1px solid #eef2f7;
}
.assignment-header-top {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    flex-wrap: wrap;
    gap: 12px;
    margin-bottom: 8px;
}
.assignment-title {
    font-size: 18px;
    font-weight: 700;
    color: #1d3156;
}
.assignment-actions {
    display: flex;
    gap: 8px;
}
.btn-edit-assignment, .btn-delete-assignment {
    padding: 6px 14px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    transition: 0.2s;
    border: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}
.btn-edit-assignment { background: #fef3c7; color: #f59e0b; }
.btn-edit-assignment:hover { background: #fde68a; transform: translateY(-1px); }
.btn-delete-assignment { background: #fee2e2; color: #dc2626; }
.btn-delete-assignment:hover { background: #fecaca; transform: translateY(-1px); }
.assignment-info {
    display: flex;
    gap: 20px;
    font-size: 12px;
    color: #64748b;
    flex-wrap: wrap;
    margin-top: 8px;
}
.assignment-desc {
    margin-top: 12px;
    padding: 10px 14px;
    background: #fefce8;
    border-left: 3px solid #f59e0b;
    border-radius: 8px;
    font-size: 13px;
    color: #475569;
}
.submission-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 16px 24px;
    border-bottom: 1px solid #eef2f7;
    transition: background 0.2s;
}
.submission-row:hover { background: #fafcff; }
.submission-row:last-child { border-bottom: none; }
.student-col { flex: 2; min-width: 160px; }
.student-name {
    font-weight: 600;
    color: #1d3156;
    font-size: 14px;
    display: flex;
    align-items: center;
    gap: 8px;
}
.submission-time { font-size: 11px; color: #94a3b8; margin-top: 2px; }
.status-col { flex: 1.5; min-width: 130px; }
.status-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 500;
}
.status-pending { background: #fef3c7; color: #f59e0b; }
.status-graded { background: #d4edda; color: #28a745; }
.status-missing { background: #fee2e2; color: #dc2626; }
.file-col { flex: 2.5; min-width: 200px; }
.file-link {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: #f1f5f9;
    padding: 5px 12px;
    border-radius: 20px;
    text-decoration: none;
    font-size: 12px;
    color: #1d3156;
    transition: 0.2s;
}
.file-link:hover { background: #e2e8f0; }
.file-size { font-size: 10px; color: #94a3b8; margin-left: 6px; }
.no-file { font-size: 12px; color: #94a3b8; font-style: italic; }
.actions-col { flex: 1; text-align: right; }
.btn-grade {
    background: #28a745;
    color: white;
    border: none;
    padding: 6px 18px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 500;
    cursor: pointer;
    transition: 0.2s;
}
.btn-grade:hover { background: #218838; transform: translateY(-1px); }
.empty-state {
    text-align: center;
    padding: 80px 20px;
    background: white;
    border-radius: 24px;
    color: #94a3b8;
}
.empty-state i { font-size: 64px; margin-bottom: 16px; display: block; color: #cbd5e1; }
.toast-notification {
    position: fixed;
    bottom: 20px;
    right: 20px;
    background: #1d3156;
    color: white;
    padding: 12px 24px;
    border-radius: 12px;
    font-size: 13px;
    z-index: 9999;
    animation: slideIn 0.3s ease;
}
@keyframes slideIn {
    from { transform: translateX(100%); opacity: 0; }
    to { transform: translateX(0); opacity: 1; }
}
.modal-overlay {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.5);
    z-index: 1000;
    align-items: center;
    justify-content: center;
}
.modal-overlay.active { display: flex; }
.modal-container {
    background: white;
    border-radius: 24px;
    width: 550px;
    max-width: 90%;
    padding: 28px;
    max-height: 90vh;
    overflow-y: auto;
}
.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
}
.modal-header h3 { font-size: 20px; color: #1d3156; }
.modal-close {
    background: none;
    border: none;
    font-size: 24px;
    cursor: pointer;
    color: #94a3b8;
}
.form-group { margin-bottom: 20px; }
.form-group label {
    display: block;
    font-weight: 600;
    font-size: 13px;
    margin-bottom: 6px;
    color: #1d3156;
}
.form-group input, .form-group textarea, .form-group select {
    width: 100%;
    padding: 10px 14px;
    border: 1px solid #cbd5e1;
    border-radius: 12px;
    font-family: 'Poppins', sans-serif;
    font-size: 13px;
}
.form-group textarea { resize: vertical; }

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
    font-size: 12px;
    font-weight: 600;
    transition: all 0.2s;
    color: #1d3156;
}
.edit-toggle-btn.active {
    background: #1d3156;
    color: white;
    border-color: #1d3156;
}
.edit-toggle-btn:hover { background: #e2e8f0; }
.edit-toggle-btn.active:hover { background: #142544; }
.modal-buttons {
    display: flex;
    gap: 12px;
    justify-content: flex-end;
    margin-top: 20px;
}
.btn-cancel {
    background: #e2e8f0;
    color: #475569;
    padding: 8px 20px;
    border-radius: 30px;
    border: none;
    cursor: pointer;
}
.btn-save {
    background: #28a745;
    color: white;
    padding: 8px 20px;
    border-radius: 30px;
    border: none;
    cursor: pointer;
}
.btn-confirm-delete {
    background: #dc2626;
    color: white;
    padding: 8px 20px;
    border-radius: 30px;
    border: none;
    cursor: pointer;
}
.file-input-wrapper {
    position: relative;
    display: flex;
    gap: 10px;
    align-items: center;
    flex-wrap: wrap;
}
.file-input-wrapper input[type="file"] { flex: 1; }
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
.selected-file-name i { font-size: 14px; }
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
.selected-file-name .remove-file:hover { background: #fee2e2; }
.url-input-wrapper {
    display: flex;
    gap: 10px;
    align-items: center;
    flex-wrap: wrap;
}
.url-input-wrapper input { flex: 1; }
.current-attachment {
    background: #f1f5f9;
    padding: 10px 14px;
    border-radius: 12px;
    font-size: 13px;
    word-break: break-all;
}
.current-attachment i { margin-right: 8px; }
.current-attachment button {
    transition: 0.2s;
}
.current-attachment button:hover {
    opacity: 0.8;
    transform: scale(1.02);
}

/* Mobile responsive - Clean version */
@media (max-width: 900px) {
    /* Header - keep back on left, title center, plus on right */
    .main > div:first-child {
        display: flex !important;
        flex-direction: row !important;
        justify-content: space-between !important;
        align-items: center !important;
        gap: 8px !important;
        margin-bottom: 20px !important;
        flex-wrap: nowrap !important;
    }
    
    /* Back button - LEFT side (order: 1) */
    .main > div:first-child .back-btn {
        order: 1 !important;
        flex-shrink: 0 !important;
        padding: 8px 12px !important;
    }
    
    .main > div:first-child .back-btn span {
        display: none !important;
    }
    
    /* Title - CENTER (order: 2) */
    .main > div:first-child > div:first-child {
        order: 2 !important;
        flex: 1 !important;
        text-align: center !important;
        min-width: 0 !important;
    }
    
    .main > div:first-child h1 {
        font-size: 16px !important;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    
    .main > div:first-child p {
        display: none !important;
    }
    
    /* Create button - RIGHT side (order: 3) */
    .main > div:first-child .create-btn {
        order: 3 !important;
        flex-shrink: 0 !important;
        padding: 8px 12px !important;
    }
    
    .main > div:first-child .create-btn span {
        display: none !important;
    }
    
    /* Submission rows */
    .submission-row {
        flex-direction: column !important;
        align-items: flex-start !important;
        gap: 12px !important;
    }
    
    .actions-col {
        text-align: left !important;
        width: 100% !important;
    }
    
    /* Filter bar */
    .filter-row {
        flex-direction: column !important;
        align-items: stretch !important;
    }
    
    .filter-group {
        width: 100% !important;
    }
    
    .btn-search, .btn-reset {
        width: 100% !important;
        justify-content: center !important;
    }
    
    /* Assignment header */
    .assignment-header-top {
        flex-direction: column !important;
        align-items: flex-start !important;
    }
    
    .assignment-actions {
        width: 100% !important;
        justify-content: flex-start !important;
        margin-top: 8px !important;
    }
    
    .assignment-info {
        flex-direction: column !important;
        gap: 6px !important;
    }
}



@media (max-width: 768px) {
    .filter-row {
        flex-direction: column;
        align-items: stretch;
    }
    
    .filter-group {
        width: 100%;
    }
    
    .btn-search, .btn-reset {
        width: 100%;
        justify-content: center;
    }
}

@media (max-width: 768px) {
    .assignment-header-top {
        flex-direction: column;
        align-items: flex-start;
    }
    
    .assignment-actions {
        width: 100%;
        justify-content: flex-start;
        margin-top: 8px;
    }
    
    .assignment-info {
        flex-direction: column;
        gap: 6px;
    }
}

@media (max-width: 600px) {
    .modal-container {
        width: 95%;
        padding: 20px;
    }
    
    .modal-header h3 {
        font-size: 18px;
    }
    
    .edit-toggle-row {
        flex-direction: column;
        gap: 8px;
    }
    
    .edit-toggle-btn {
        width: 100%;
    }
    
    .modal-buttons {
        flex-direction: column;
    }
    
    .modal-buttons button {
        width: 100%;
    }
}

/* FORCE BACK BUTTON ON LEFT - OVERRIDE EVERYTHING */
@media (max-width: 900px) {
    /* Force back button to be first (left) */
    .main > div:first-child {
        display: flex !important;
        flex-direction: row !important;
        justify-content: space-between !important;
        align-items: center !important;
        gap: 8px !important;
    }
    
    /* Back button - FORCE LEFT */
    .main > div:first-child .back-btn {
        order: 0 !important;
        margin-right: auto !important;
        margin-left: 0 !important;
    }
    
    /* Title - CENTER */
    .main > div:first-child > div:first-child {
        order: 1 !important;
        flex: 1 !important;
        text-align: center !important;
    }
    
    /* Create button - FORCE RIGHT */
    .main > div:first-child .create-btn {
        order: 2 !important;
        margin-left: auto !important;
        margin-right: 0 !important;
    }
}

.modal-container embed,
.modal-container iframe {
    width: 100%;
    height: 500px;
    border-radius: 12px;
}

.modal-container video {
    max-width: 100%;
    border-radius: 12px;
}

.modal-container audio {
    width: 100%;
    border-radius: 40px;
}

.file-link {
    cursor: pointer;
    transition: all 0.2s;
}

.file-link:hover {
    transform: translateY(-1px);
    opacity: 0.9;
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
                <a href="material_overview.php">My Materials</a>
                <a href="assignment_overview.php" class="active">My Assignments</a>
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
<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 32px; gap: 16px;">
    <a href="tutor_dashboard.php" class="back-btn" style="display: inline-flex; align-items: center; gap: 6px; background: white; color: #1d3156; padding: 10px 20px; border-radius: 40px; text-decoration: none; font-weight: 600; font-size: 14px; border: 1px solid #e2e8f0; flex-shrink: 0;">
        <i class="bi bi-arrow-left"></i> <span>Back</span>
    </a>
    
    <div style="text-align: center; flex: 1;">
        <h1 style="font-size: 24px; font-weight: 800; color: #1d3156; margin: 0;"><i class="bi bi-journal-check"></i> My Assignments</h1>
        <p style="color: #1e293b; margin: 4px 0 0; font-size: 12px;">Review and grade student submissions</p>
    </div>
    
    <a href="select_booking.php?action=assignment" class="create-btn" style="display: inline-flex; align-items: center; gap: 6px; background: #1d3156; color: white; border: none; padding: 10px 20px; border-radius: 40px; font-weight: 600; font-size: 14px; cursor: pointer; text-decoration: none; flex-shrink: 0;">
        <i class="bi bi-plus-lg"></i> <span>Create Assignment</span>
    </a>
</div>
    <?php if (isset($deleteMessage)): ?>
        <div class="alert alert-<?= $deleteMessageType ?>">
            <i class="bi bi-<?= $deleteMessageType === 'success' ? 'check-circle' : 'exclamation-circle' ?>"></i>
            <?= e($deleteMessage) ?>
        </div>
    <?php endif; ?>

    <?php if (isset($editMessage)): ?>
        <div class="alert alert-<?= $editMessageType ?>">
            <i class="bi bi-<?= $editMessageType === 'success' ? 'check-circle' : ($editMessageType === 'warning' ? 'exclamation-triangle' : 'exclamation-circle') ?>"></i>
            <?= e($editMessage) ?>
        </div>
    <?php endif; ?>

    <div class="filter-bar">
        <div class="filter-row">
            <div class="filter-group search-group">
                <label>Search</label>
                <input type="text" id="searchInput" placeholder="Title or student name...">
            </div>
            <div class="filter-group">
                <label>Language</label>
                <select id="languageFilter">
                    <option value="all">All Languages</option>
                    <?php foreach ($tutorLanguages as $lang): ?>
                        <option value="<?= e($lang['language']) ?>"><?= e($lang['language']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-group">
                <label>Status</label>
                <select id="statusFilter">
                    <option value="all">All Status</option>
                    <option value="pending">Pending Grade</option>
                    <option value="graded">Graded</option>
                    <option value="no_submission">No Submission</option>
                </select>
            </div>
            <div class="filter-group">
                <label><i class="bi bi-clock-history"></i> Late Policy</label>
                <select id="latePolicyFilter">
                    <option value="all">All Policies</option>
                    <option value="no_late">No late submissions</option>
                    <option value="always">Always accept late</option>
                    <option value="days">X days after due date</option>
                    <option value="specific">Until specific date</option>
                </select>
            </div>
            <div class="filter-group">
                <label><i class="bi bi-sort-alpha-down"></i> Sort By</label>
                <select id="sortBy">
                    <option value="latest">Latest First</option>
                    <option value="oldest">Oldest First</option>
                    <option value="due_asc">Due Date (Earliest)</option>
                    <option value="title_az">Title (A-Z)</option>
                </select>
            </div>
            <div><button class="btn-search" onclick="applyFilters()"><i class="bi bi-search"></i>Search</button></div>
            <div><button class="btn-reset" onclick="resetFilters()"><i class="bi bi-arrow-counterclockwise"></i> Reset</button></div>
        </div>
    </div>

    <div id="assignmentsContainer"></div>
</div>
<!-- View Submission Modal -->
<div id="viewSubmissionModal" class="modal-overlay">
    <div class="modal-container" style="max-width: 700px; width: 90%;">
        <div class="modal-header">
            <h3><i class="bi bi-file-text"></i> Student Submission</h3>
            <button class="modal-close" onclick="closeViewSubmissionModal()">&times;</button>
        </div>
        <div id="submissionPreviewContent" style="max-height: 60vh; overflow-y: auto; min-height: 200px;">
            <!-- Content loads here -->
        </div>
        <div class="modal-buttons" style="margin-top: 20px; justify-content: center;">
            <button class="btn-cancel" onclick="closeViewSubmissionModal()">Close</button>
            <button id="downloadSubmissionBtn" class="btn-save" style="background: #1d3156;">
                <i class="bi bi-download"></i> Download File
            </button>
        </div>
    </div>
</div>

<!-- Grade Modal -->
<div id="gradeModal" class="modal-overlay">
    <div class="modal-container">
        <div class="modal-header">
            <h3><i class="bi bi-star"></i> Grade Submission</h3>
            <button class="modal-close" onclick="closeGradeModal()">&times;</button>
        </div>
        <input type="hidden" id="grade_submission_id">
        <div class="form-group">
            <label>Grade / Points</label>
            <input type="text" id="grade_value" placeholder="e.g., 85/100, A, Pass">
        </div>
        <div class="form-group">
            <label>Private Feedback</label>
            <textarea id="grade_feedback" rows="4" placeholder="Provide feedback to the student..."></textarea>
        </div>
        <div class="modal-buttons">
            <button class="btn-cancel" onclick="closeGradeModal()">Cancel</button>
            <button class="btn-save" onclick="submitGrade()">Save Grade</button>
        </div>
    </div>
</div>
<!-- Edit Assignment Modal -->
<div id="editAssignmentModal" class="modal-overlay">
    <div class="modal-container">
        <div class="modal-header">
            <h3><i class="bi bi-pencil-square"></i> Edit Assignment</h3>
            <button class="modal-close" onclick="closeEditAssignmentModal()">&times;</button>
        </div>
        <form method="POST" action="" enctype="multipart/form-data" id="editAssignmentForm">
            <input type="hidden" name="assignment_id" id="edit_assignment_id">
            <input type="hidden" name="edit_assignment" value="1">
            <input type="hidden" name="replace_type" id="edit_replace_type" value="keep">
            
            <div class="form-group">
                <label>Title *</label>
                <input type="text" name="title" id="edit_title" required>
            </div>
            
            <div class="form-group">
                <label>Description</label>
                <textarea name="description" id="edit_description" rows="3"></textarea>
            </div>
            
            <!-- ADD THE MISSING DUE DATE INPUT -->
            <div class="form-group">
                <label>Due Date & Time</label>
                <input type="datetime-local" name="due_date" id="edit_due_date" class="form-control">
                <small style="font-size:11px;color:#94a3b8;margin-top:4px;display:block;">
                    Leave empty for no due date.
                </small>
            </div>
            
            <!-- Late Policy Section (only one) -->
            <div id="edit_latePolicyDiv" style="display: none;">
                <div class="form-group">
                    <label>Late Submission Policy</label>
                    <div style="margin-bottom: 10px;">
                        <input type="checkbox" name="allow_late_submission" id="edit_allow_late" value="1" style="width: auto; margin-right: 8px;" onchange="toggleEditLateOptions()">
                        <label style="display: inline; font-weight: normal;">Allow late submissions</label>
                    </div>
                    
                    <div id="edit_lateOptions" style="display: none; margin-top: 10px; padding: 12px; background: #f8fafc; border-radius: 12px;">
                        <label style="font-size: 12px; margin-bottom: 8px;">Late submission cutoff:</label>
                   <select name="late_cutoff_type" id="edit_lateCutoffType" class="form-control" style="margin-bottom: 10px;" onchange="updateEditLateOptions()">
                        <option value="no_limit" selected>No limit (always accept late)</option>
                        <option value="days_after">Allow X days after due date</option>
                        <option value="specific_date">Allow until specific date</option>
                    </select>
                        
                        <div id="edit_daysAfterOption" style="display: none; margin-top: 10px;">
                            <label style="font-size: 12px;">Days allowed after due date:</label>
                            <input type="number" name="late_days" id="edit_lateDays" min="1" max="30" value="7" class="form-control" style="width: 100px;">
                            <small class="form-hint-small">Example: 7 days after due date</small>
                        </div>
                        
                        <div id="edit_specificDateOption" style="display: none; margin-top: 10px;">
                            <label style="font-size: 12px;">Cutoff date & time:</label>
                            <input type="datetime-local" name="late_cutoff_date" id="edit_lateCutoffDate" class="form-control">
                            <small class="form-hint-small">Submissions accepted until this date/time</small>
                        </div>
                    </div>
                    <div class="form-hint-small">Define when late submissions will be accepted until</div>
                </div>
            </div>
            
            <div class="form-group">
                <label>Current Attachment</label>
                <div id="edit_current_attachment" class="current-attachment"></div>
            </div>
            
            <div class="form-group">
                <label>Update Attachment (Optional)</label>
                <div class="edit-toggle-row">
                    <div class="edit-toggle-btn active" data-edit-content="keep">Keep Current</div>
                    <div class="edit-toggle-btn" data-edit-content="file">Replace with New File</div>
                    <div class="edit-toggle-btn" data-edit-content="url">Replace with URL</div>
                </div>
            </div>
            
            <div id="edit_file_section" style="display: none;">
                <div class="form-group">
                    <label>New File</label>
                    <div class="file-input-wrapper">
                        <input type="file" name="new_assignment_file" id="edit_new_file" accept=".pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.jpg,.jpeg,.png,.mp4,.mp3,.zip,.txt">
                        <button type="button" class="btn-clear-file" id="clearFileBtn" onclick="clearSelectedFile()" style="display: none;">
                            <i class="bi bi-x-circle"></i> Clear
                        </button>
                    </div>
                    <div id="selectedFileName" class="selected-file-name" style="display: none;"></div>
                    <small style="font-size: 11px; color: #64748b;">Allowed: PDF, Word, PowerPoint, Excel, Images, Video, Audio, ZIP, TXT (Max 50MB)</small>
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
                    <div id="selectedUrlDisplay" class="selected-file-name" style="display: none;"></div>
                    <small style="font-size: 11px; color: #64748b;">Students will be able to access this link</small>
                </div>
            </div>
            
            <div class="modal-buttons">
                <button type="button" class="btn-cancel" onclick="closeEditAssignmentModal()">Cancel</button>
                <button type="submit" class="btn-save">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- Delete Assignment Modal -->
<div id="deleteAssignmentModal" class="modal-overlay">
    <div class="modal-container">
        <div class="modal-header">
            <h3><i class="bi bi-trash3" style="color: #dc2626;"></i> Delete Assignment</h3>
            <button class="modal-close" onclick="closeDeleteAssignmentModal()">&times;</button>
        </div>
        <p>Are you sure you want to delete this assignment? <br>All student submissions will also be deleted.<br> This action cannot be undone.</p>
        <form method="POST" action="">
            <input type="hidden" name="assignment_id" id="delete_assignment_id">
            <input type="hidden" name="delete_assignment" value="1">
            <div class="modal-buttons">
                <button type="button" class="btn-cancel" onclick="closeDeleteAssignmentModal()">Cancel</button>
                <button type="submit" class="btn-confirm-delete">Delete Assignment</button>
            </div>
        </form>
    </div>
</div>

<script>
const allAssignments = <?= json_encode($assignments) ?>;
const submissionsData = <?= json_encode($submissionsByAssignment) ?>;
let selectedFile = null;

function showToast(message, color) {
    const existing = document.querySelector('.toast-notification');
    if (existing) existing.remove();
    const toast = document.createElement('div');
    toast.className = 'toast-notification';
    toast.style.backgroundColor = color;
    toast.innerHTML = `<i class="bi bi-info-circle"></i> ${message}`;
    document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 3000);
}

function deleteIndividualFile(assignmentId, fileIndex) {
    if (confirm('Are you sure you want to remove this file? This action cannot be undone.')) {
        fetch('delete_assignment_file.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                assignment_id: assignmentId,
                file_index: fileIndex
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showToast('File removed successfully!', '#28a745');
                location.reload();
            } else {
                showToast('Error: ' + data.message, '#dc2626');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showToast('Network error', '#dc2626');
        });
    }
}

function deleteIndividualUrl(assignmentId, urlIndex) {
    if (confirm('Are you sure you want to remove this URL? This action cannot be undone.')) {
        fetch('delete_assignment_url.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                assignment_id: assignmentId,
                url_index: urlIndex
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showToast('URL removed successfully!', '#28a745');
                location.reload();
            } else {
                showToast('Error: ' + data.message, '#dc2626');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showToast('Network error', '#dc2626');
        });
    }
}

function toggleDropdown() {
    const d = document.getElementById('profileDropdown');
    d.style.display = d.style.display === 'block' ? 'none' : 'block';
}

window.addEventListener('click', function(e) {
    const btn = document.querySelector('.profile');
    const dd = document.getElementById('profileDropdown');
    if (btn && dd && !btn.contains(e.target) && !dd.contains(e.target)) {
        dd.style.display = 'none';
    }
});

function openGradeModal(submissionId, currentGrade, currentFeedback) {
    console.log('Opening grade modal for submission ID:', submissionId); // Debug
    
    if (!submissionId || submissionId === 'undefined') {
        showToast('Error: Invalid submission ID', '#dc2626');
        return;
    }
    
    document.getElementById('grade_submission_id').value = submissionId;
    document.getElementById('grade_value').value = currentGrade || '';
    document.getElementById('grade_feedback').value = currentFeedback || '';
    document.getElementById('gradeModal').classList.add('active');
}

function closeGradeModal() {
    document.getElementById('gradeModal').classList.remove('active');
}

function submitGrade() {
    const submissionId = document.getElementById('grade_submission_id').value;
    const grade = document.getElementById('grade_value').value;
    const feedback = document.getElementById('grade_feedback').value;
    
    console.log('Submitting grade for submission ID:', submissionId);
    console.log('Grade:', grade);
    
    if (!submissionId || submissionId === '0') {
        showToast('Error: No submission selected', '#dc2626');
        return;
    }
    
    // If grade is empty, show warning but allow
    if (!grade) {
        if (!confirm('Grade is empty. Continue anyway?')) {
            return;
        }
    }
    
    fetch('save_grade.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ 
            submission_id: parseInt(submissionId), 
            grade: grade || 'Not graded', 
            feedback: feedback || '' 
        })
    })
    .then(response => response.text())  // Get as text first to see raw response
    .then(text => {
        console.log('Raw response:', text);  // Debug - see what PHP returns
        try {
            const data = JSON.parse(text);
            if (data.success) {
                showToast('Grade saved!', '#28a745');
                closeGradeModal();
                location.reload();
            } else {
                showToast('Error: ' + data.message, '#dc2626');
            }
        } catch(e) {
            console.error('JSON parse error:', e);
            showToast('Server error. Check console for details.', '#dc2626');
        }
    })
    .catch(error => {
        console.error('Fetch error:', error);
        showToast('Network error', '#dc2626');
    });
}
function resetFileInputs() {
    const fileInput = document.getElementById('edit_new_file');
    const clearBtn = document.getElementById('clearFileBtn');
    const fileNameDisplay = document.getElementById('selectedFileName');
    
    if (fileInput) fileInput.value = '';
    selectedFile = null;
    if (clearBtn) clearBtn.style.display = 'none';
    if (fileNameDisplay) {
        fileNameDisplay.style.display = 'none';
        fileNameDisplay.innerHTML = '';
    }
}

function resetUrlInputs() {
    const urlInput = document.getElementById('edit_new_url');
    const clearUrlBtn = document.getElementById('clearUrlBtn');
    const urlDisplay = document.getElementById('selectedUrlDisplay');
    
    if (urlInput) urlInput.value = '';
    if (clearUrlBtn) clearUrlBtn.style.display = 'none';
    if (urlDisplay) {
        urlDisplay.style.display = 'none';
        urlDisplay.innerHTML = '';
    }
}

function openEditAssignmentModal(assignmentId, title, description, dueDate, attachmentHtml, allowLate, lateCutoffType, lateDays, lateCutoffDate) {
    // Set basic fields
    document.getElementById('edit_assignment_id').value = assignmentId;
    document.getElementById('edit_title').value = title || '';
    document.getElementById('edit_description').value = description || '';
    
    // Set due date
    let formattedDueDate = '';
    if (dueDate && dueDate !== 'null' && dueDate !== 'undefined' && dueDate.trim() !== '') {
        formattedDueDate = dueDate.trim().substring(0, 16).replace(' ', 'T');
    }
    document.getElementById('edit_due_date').value = formattedDueDate;
    
    // Set allow late checkbox
    const allowLateCheckbox = document.getElementById('edit_allow_late');
    if (allowLateCheckbox) {
        allowLateCheckbox.checked = (allowLate == 1 || allowLate === true || allowLate === '1');
    }
    // Set late cutoff type and values
    const lateCutoffTypeSelect = document.getElementById('edit_lateCutoffType');
    const lateDaysInput = document.getElementById('edit_lateDays');
    const lateCutoffDateInput = document.getElementById('edit_lateCutoffDate');
    
    if (lateCutoffTypeSelect && lateCutoffType) {
        lateCutoffTypeSelect.value = lateCutoffType;
        updateEditLateOptions();
        
        if (lateDays && lateDays > 0) {
            lateDaysInput.value = lateDays;
        }
        if (lateCutoffDate && lateCutoffDate !== '0000-00-00 00:00:00' && lateCutoffDate !== 'null') {
            lateCutoffDateInput.value = lateCutoffDate.replace(' ', 'T').substring(0, 16);
        }
    }
    document.getElementById('edit_current_attachment').innerHTML = attachmentHtml || '<i class="bi bi-exclamation-triangle"></i> No attachment';
    
    // Reset file and URL inputs
    resetFileInputs();
    resetUrlInputs();
    
    // Reset replace type to "keep"
    document.getElementById('edit_replace_type').value = 'keep';
    document.getElementById('edit_file_section').style.display = 'none';
    document.getElementById('edit_url_section').style.display = 'none';
    
    // Reset toggle buttons
    document.querySelectorAll('.edit-toggle-btn').forEach(btn => {
        btn.classList.remove('active');
    });
    const keepBtn = document.querySelector('.edit-toggle-btn[data-edit-content="keep"]');
    if (keepBtn) keepBtn.classList.add('active');
    
    // Trigger due date change to show/hide late policy
    toggleEditLateOptionsByDueDate();
    
    // If allow late is checked, show the late options
    if (allowLateCheckbox && allowLateCheckbox.checked) {
        const lateOptions = document.getElementById('edit_lateOptions');
        if (lateOptions) lateOptions.style.display = 'block';
    }
    
    // Show the modal
    document.getElementById('editAssignmentModal').classList.add('active');
}

function toggleEditLateOptions() {
    const checkbox = document.getElementById('edit_allow_late');
    const lateOptions = document.getElementById('edit_lateOptions');
    
    if (checkbox.checked) {
        lateOptions.style.display = 'block';
    } else {
        lateOptions.style.display = 'none';
    }
}

function updateEditLateOptions() {
    const cutoffType = document.getElementById('edit_lateCutoffType').value;
    const daysAfterDiv = document.getElementById('edit_daysAfterOption');
    const specificDateDiv = document.getElementById('edit_specificDateOption');
    
    // Hide both by default
    daysAfterDiv.style.display = 'none';
    specificDateDiv.style.display = 'none';
    
    // Show only the selected option's div
    if (cutoffType === 'days_after') {
        daysAfterDiv.style.display = 'block';
    } else if (cutoffType === 'specific_date') {
        specificDateDiv.style.display = 'block';
    }
}

function toggleEditLateOptionsByDueDate() {
    const dueDateInput = document.getElementById('edit_due_date');
    const latePolicyDiv = document.getElementById('edit_latePolicyDiv');
    const allowLateCheckbox = document.getElementById('edit_allow_late');
    const lateOptions = document.getElementById('edit_lateOptions');
    
    if (dueDateInput && dueDateInput.value) {
        latePolicyDiv.style.display = 'block';
    } else if (latePolicyDiv) {
        latePolicyDiv.style.display = 'none';
        if (allowLateCheckbox) allowLateCheckbox.checked = false;
        if (lateOptions) lateOptions.style.display = 'none';
    }
}

function closeEditAssignmentModal() {
    resetFileInputs();
    resetUrlInputs();
    document.getElementById('editAssignmentModal').classList.remove('active');
}

function openDeleteAssignmentModal(assignmentId) {
    document.getElementById('delete_assignment_id').value = assignmentId;
    document.getElementById('deleteAssignmentModal').classList.add('active');
}

function closeDeleteAssignmentModal() {
    document.getElementById('deleteAssignmentModal').classList.remove('active');
}

// Toggle buttons
document.querySelectorAll('.edit-toggle-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        document.querySelectorAll('.edit-toggle-btn').forEach(b => b.classList.remove('active'));
        this.classList.add('active');
        
        const type = this.dataset.editContent;
        document.getElementById('edit_replace_type').value = type;
        
        const fileSection = document.getElementById('edit_file_section');
        const urlSection = document.getElementById('edit_url_section');
        
        if (type === 'file') {
            if (fileSection) fileSection.style.display = 'block';
            if (urlSection) urlSection.style.display = 'none';
        } else if (type === 'url') {
            if (fileSection) fileSection.style.display = 'none';
            if (urlSection) urlSection.style.display = 'block';
        } else {
            if (fileSection) fileSection.style.display = 'none';
            if (urlSection) urlSection.style.display = 'none';
        }
    });
});

function clearSelectedFile() {
    const fileInput = document.getElementById('edit_new_file');
    const clearBtn = document.getElementById('clearFileBtn');
    const fileNameDisplay = document.getElementById('selectedFileName');
    
    if (fileInput) fileInput.value = '';
    selectedFile = null;
    if (clearBtn) clearBtn.style.display = 'none';
    if (fileNameDisplay) {
        fileNameDisplay.style.display = 'none';
        fileNameDisplay.innerHTML = '';
    }
    showToast('File selection cleared', '#64748b');
}

function clearUrlInput() {
    const urlInput = document.getElementById('edit_new_url');
    const clearUrlBtn = document.getElementById('clearUrlBtn');
    const urlDisplay = document.getElementById('selectedUrlDisplay');
    
    if (urlInput) urlInput.value = '';
    if (clearUrlBtn) clearUrlBtn.style.display = 'none';
    if (urlDisplay) {
        urlDisplay.style.display = 'none';
        urlDisplay.innerHTML = '';
    }
    showToast('URL cleared', '#64748b');
}

function formatFileSize(bytes) {
    if (!bytes) return '';
    if (bytes >= 1048576) return (bytes / 1048576).toFixed(1) + ' MB';
    if (bytes >= 1024) return (bytes / 1024).toFixed(1) + ' KB';
    return bytes + ' bytes';
}

function formatDate(dateString) {
    if (!dateString || dateString === '0000-00-00 00:00:00') {
        return 'No due date';
    }
    let datePart = dateString;
    if (dateString.includes(' ')) {
        datePart = dateString.split(' ')[0];
    }
    const date = new Date(datePart);
    return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
}

function formatTime(dateString) {
    if (!dateString) return '';
    const date = new Date(dateString);
    return date.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });
}function getAttachmentHtml(assignment, showDeleteButtons = false) {
    if (assignment.is_url == 1 && assignment.material_url) {
        const urls = assignment.material_url.split('|');
        if (urls.length > 1) {
            let html = '<div><i class="bi bi-link-45deg"></i> Multiple Links:<br>';
            urls.forEach((url, i) => {
                html += `<div style="margin-left: 20px; margin-top: 5px; display: flex; justify-content: space-between; align-items: center;">
                            <div><i class="bi bi-link"></i> <a href="${escapeHtml(url)}" target="_blank">${escapeHtml(url)}</a></div>
                            ${showDeleteButtons ? `<button type="button" onclick="deleteIndividualUrl(${assignment.id}, ${i})" style="background: #dc2626; color: white; border: none; border-radius: 20px; padding: 2px 10px; cursor: pointer; font-size: 11px;">Delete</button>` : ''}
                        </div>`;
            });
            html += '</div>';
            return html;
        }
        return `<i class="bi bi-link-45deg"></i> <a href="${escapeHtml(assignment.material_url)}" target="_blank">${escapeHtml(assignment.material_url)}</a>`;
    } else if (assignment.file_name) {
        // Check if pipe-separated (multiple files)
        const fileNames = assignment.file_name.split('|');
        const filePaths = assignment.file_path ? assignment.file_path.split('|') : [];
        
        if (fileNames.length > 1) {
            let html = '<div><i class="bi bi-files"></i> Multiple Files:<br>';
            fileNames.forEach((name, i) => {
                const path = filePaths[i] || '';
                html += `<div style="margin-left: 20px; margin-top: 5px; display: flex; justify-content: space-between; align-items: center;">
                            <div><i class="bi bi-file-earmark"></i> ${escapeHtml(name)} 
                            <a href="../uploads/assignments/${escapeHtml(path)}" target="_blank" style="margin-left: 10px; font-size: 11px;">[Download]</a></div>
                            ${showDeleteButtons ? `<button type="button" onclick="deleteIndividualFile(${assignment.id}, ${i})" style="background: #dc2626; color: white; border: none; border-radius: 20px; padding: 2px 10px; cursor: pointer; font-size: 11px;">Delete</button>` : ''}
                        </div>`;
            });
            html += '</div>';
            return html;
        } else {
            // Single file
            const path = filePaths[0] || assignment.file_path || '';
            return `<div style="display: flex; justify-content: space-between; align-items: center;">
                        <div><i class="bi bi-file-earmark"></i> ${escapeHtml(assignment.file_name)} (${formatFileSize(assignment.file_size)})</div>
                        ${showDeleteButtons ? `<button type="button" onclick="deleteIndividualFile(${assignment.id}, 0)" style="background: #dc2626; color: white; border: none; border-radius: 20px; padding: 2px 10px; cursor: pointer; font-size: 11px;">Delete</button>` : ''}
                    </div>`;
        }
    }
    return '<span style="color:#94a3b8;font-size:12px;font-style:italic;">No attachment</span>';
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

function getSubmissionStatusHtml(submittedAt, dueDate) {
    if (!dueDate || dueDate === '0000-00-00 00:00:00') {
        return '<span style="color: #28a745; margin-left: 8px;"><i class="bi bi-check-circle"></i> No deadline</span>';
    }
    
    const submitted = new Date(submittedAt);
    const due = new Date(dueDate);
    
    if (submitted <= due) {
        // Early or on-time submission
        const diffMs = due - submitted;
        const diffMins = Math.floor(diffMs / 60000);
        const diffHours = Math.floor(diffMins / 60);
        const diffDays = Math.floor(diffHours / 24);
        
        let earlyText = '';
        if (diffDays > 0) {
            earlyText = `Submitted ${diffDays} day${diffDays > 1 ? 's' : ''} early`;
        } else if (diffHours > 0) {
            earlyText = `Submitted ${diffHours} hour${diffHours > 1 ? 's' : ''} early`;
        } else if (diffMins > 0) {
            earlyText = `Submitted ${diffMins} minute${diffMins > 1 ? 's' : ''} early`;
        } else {
            earlyText = 'On time';
        }
        return `<span style="color: #28a745; margin-left: 8px;"><i class="bi bi-clock"></i> ${earlyText}</span>`;
    } else {
        // Late submission
        const diffMs = submitted - due;
        const diffMins = Math.floor(diffMs / 60000);
        const diffHours = Math.floor(diffMins / 60);
        const diffDays = Math.floor(diffHours / 24);
        
        let lateText = '';
        if (diffDays > 0) {
            lateText = `Submitted ${diffDays} day${diffDays > 1 ? 's' : ''} late`;
        } else if (diffHours > 0) {
            lateText = `Submitted ${diffHours} hour${diffHours > 1 ? 's' : ''} late`;
        } else {
            lateText = `Submitted ${diffMins} minute${diffMins > 1 ? 's' : ''} late`;
        }
        return `<span style="color: #dc2626; margin-left: 8px;"><i class="bi bi-exclamation-triangle"></i> ${lateText}</span>`;
    }
}
function getLatePolicyBadge(assignment) {
    // If no due date, don't show any badge
    if (!assignment.due_date || assignment.due_date === '0000-00-00 00:00:00') {
        return '';
    }
    
    // If late submissions are NOT allowed
    if (assignment.allow_late_submission != 1) {
        return `<span style="display: inline-block; background: #fee2e2; color: #dc2626; padding: 2px 10px; border-radius: 20px; font-size: 10px; font-weight: 500; margin-left: 8px;">No late submissions
                </span>`;
    }
    
    // If late submissions ARE allowed, show the policy
    let policyText = '';
    let badgeColor = '';
    let textColor = '';
    
    switch (assignment.late_cutoff_type) {
        case 'no_limit':
            policyText = 'Always accept late';
            badgeColor = '#e0e7ff';
            textColor = '#4338ca';
            break;
        case 'days_after':
            const days = assignment.late_days || 7;
            policyText = `Late allowed: ${days} day${days > 1 ? 's' : ''} after due`;
            badgeColor = '#fef3c7';
            textColor = '#f59e0b';
            break;
        case 'specific_date':
            if (assignment.late_cutoff_date) {
                const cutoffDate = new Date(assignment.late_cutoff_date);
                policyText = `Late allowed until ${cutoffDate.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })}`;
            } else {
                policyText = 'Late allowed until specific date';
            }
            badgeColor = '#d1fae5';
            textColor = '#059669';
            break;
        default:
            policyText = 'Late submissions allowed';
            badgeColor = '#e2e8f0';
            textColor = '#475569';
    }
    
    return `<span style="display: inline-block; background: ${badgeColor}; color: ${textColor}; padding: 2px 10px; border-radius: 20px; font-size: 10px; font-weight: 500; margin-left: 8px;">${policyText}
            </span>`;
}


function renderAssignments(assignments) {
    const container = document.getElementById('assignmentsContainer');
    
    if (!assignments || assignments.length === 0) {
        container.innerHTML = '<div class="empty-state"><i class="bi bi-inbox"></i><p>No assignments found</p><small>Click "Create Assignment" to get started</small></div>';
        return;
    }
    
    let html = '';
    for (const assignment of assignments) {
        const submissions = submissionsData[assignment.id] || [];
        const attachmentHtml = getAttachmentHtml(assignment);
        const escapedAttachmentHtml = encodeURIComponent(attachmentHtml);
        
        let dueDateDisplay = '';
        if (assignment.due_date && assignment.due_date !== '0000-00-00 00:00:00') {
            const lateBadge = getLatePolicyBadge(assignment);
            dueDateDisplay = `<span><i class="bi bi-calendar"></i> Due: ${formatDate(assignment.due_date)} ${lateBadge}</span>`;
        } else {
            dueDateDisplay = '<span><i class="bi bi-calendar"></i> No due date</span>';
        }
        
        html += `
            <div class="assignment-card">
                <div class="assignment-header">
                    <div class="assignment-header-top">
                        <div class="assignment-title">${escapeHtml(assignment.title)}</div>
                        <div class="assignment-actions">
                            <button class="btn-edit-assignment" onclick='openEditAssignmentModal(
                                ${assignment.id}, 
                                ${JSON.stringify(escapeHtml(assignment.title))}, 
                                ${JSON.stringify(escapeHtml(assignment.description || ''))}, 
                                ${JSON.stringify(assignment.due_date || '')}, 
                                ${JSON.stringify(attachmentHtml)}, 
                                ${assignment.allow_late_submission || 0},
                                ${JSON.stringify(assignment.late_cutoff_type || 'days_after')},
                                ${assignment.late_days || null},
                                ${JSON.stringify(assignment.late_cutoff_date || '')}
                            )'>
                                <i class="bi bi-pencil"></i> Edit
                            </button>
                            <button class="btn-delete-assignment" onclick="openDeleteAssignmentModal(${assignment.id})">
                                <i class="bi bi-trash3"></i> Delete
                            </button>
                        </div>
                    </div>
                    <div class="assignment-info">
                        <span><i class="bi bi-translate"></i> ${escapeHtml(assignment.language)}</span>
                        <span><i class="bi bi-person"></i> ${escapeHtml(assignment.student_name)}</span>
                        ${dueDateDisplay}
                    </div>
                    ${assignment.description ? `<div class="assignment-desc">${escapeHtml(assignment.description)}</div>` : ''}
                </div>`;
        
        if (submissions.length === 0) {
            html += `
                <div class="submission-row">
                    <div class="student-col">
                        <div class="student-name">${escapeHtml(assignment.student_name)}</div>
                    </div>
                    <div class="status-col">
                        <span class="status-badge status-missing"><i class="bi bi-exclamation-triangle"></i> No Submission</span>
                    </div>
                    <div class="file-col">
                        <span class="no-file">— No file uploaded —</span>
                    </div>
                    <div class="actions-col"></div>
                </div>`;
        } else {
            for (const sub of submissions) {
                const hasGrade = sub.grade && sub.grade !== '';
                const statusClass = hasGrade ? 'status-graded' : 'status-pending';
                const statusIcon = hasGrade ? '<i class="bi bi-check-circle"></i>' : '<i class="bi bi-clock-history"></i>';
                const statusText = hasGrade ? `Graded: ${escapeHtml(sub.grade)}` : 'Pending Grade';
                
                html += `
                    <div class="submission-row">
                        <div class="student-col">
                            <div class="student-name">${escapeHtml(sub.student_name)}</div>
                            <div class="submission-time">
                                ${formatDate(sub.submitted_at)} at ${formatTime(sub.submitted_at)}
                                ${getSubmissionStatusHtml(sub.submitted_at, assignment.due_date)}
                            </div>
                        </div>
                        <div class="status-col">
                            <span class="status-badge ${statusClass}">${statusIcon} ${statusText}</span>
                        </div>
                        <div class="file-col">
                           ${sub.file_path ? `
    <button onclick="openViewSubmissionModal('${escapeHtml(sub.file_path)}', '${escapeHtml(sub.file_name || 'submission')}', ${sub.file_size || 0})" class="file-link" style="background: #1d3156; color: white; border: none; cursor: pointer; display: inline-flex; align-items: center; gap: 6px;">
        <i class="bi bi-eye"></i> View Submission
        <span class="file-size" style="color: #cbd5e1;">${formatFileSize(sub.file_size)}</span>
    </button>
` : '<span class="no-file">No file attached</span>'}
                        </div>
                        <div class="actions-col">
                           <button class="btn-grade" onclick="openGradeModal(${sub.id}, '${escapeHtml(sub.grade || '')}', '${escapeHtml(sub.feedback || '')}')">
    <i class="bi bi-pencil"></i> ${hasGrade ? 'Edit Grade' : 'Grade'}
</button>
                        </div>
                    </div>`;
            }
        }
        html += `</div>`;
    }
    container.innerHTML = html;
}

function getAttachmentHtmlWithDelete(assignmentId, originalHtml) {
    // Just return the original HTML without delete buttons
    return originalHtml;
}

function applyFilters() {
    const searchTerm = document.getElementById('searchInput').value.toLowerCase().trim();
    const languageFilter = document.getElementById('languageFilter').value;
    const statusFilter = document.getElementById('statusFilter').value;
    const sortBy = document.getElementById('sortBy').value;
    const latePolicyFilter = document.getElementById('latePolicyFilter').value;
    
    // Start with all assignments
    let filtered = [...allAssignments];
    
    // Filter by late policy
    if (latePolicyFilter !== 'all') {
        filtered = filtered.filter(a => {
            // Skip assignments without due date
            if (!a.due_date || a.due_date === '0000-00-00 00:00:00') {
                return latePolicyFilter === 'no_late' ? false : true;
            }
            
            switch (latePolicyFilter) {
                case 'no_late':
                    return a.allow_late_submission != 1;
                case 'always':
                    return a.allow_late_submission == 1 && a.late_cutoff_type === 'no_limit';
                case 'days':
                    return a.allow_late_submission == 1 && a.late_cutoff_type === 'days_after';
                case 'specific':
                    return a.allow_late_submission == 1 && a.late_cutoff_type === 'specific_date';
                default:
                    return true;
            }
        });
    }
    
    // Filter by search term
    if (searchTerm) {
        filtered = filtered.filter(a => 
            (a.title && a.title.toLowerCase().includes(searchTerm)) ||
            (a.student_name && a.student_name.toLowerCase().includes(searchTerm))
        );
    }
    
    // Filter by language
    if (languageFilter !== 'all') {
        filtered = filtered.filter(a => a.language === languageFilter);
    }
    
    // Filter by status
    if (statusFilter === 'pending') {
        filtered = filtered.filter(a => {
            const subs = submissionsData[a.id] || [];
            return subs.some(s => !s.grade || s.grade === '');
        });
    } else if (statusFilter === 'graded') {
        filtered = filtered.filter(a => {
            const subs = submissionsData[a.id] || [];
            return subs.some(s => s.grade && s.grade !== '');
        });
    } else if (statusFilter === 'no_submission') {
        filtered = filtered.filter(a => parseInt(a.submission_count) === 0);
    }
    
    // Sort
    switch (sortBy) {
        case 'latest': filtered.sort((a, b) => new Date(b.created_at) - new Date(a.created_at)); break;
        case 'oldest': filtered.sort((a, b) => new Date(a.created_at) - new Date(b.created_at)); break;
        case 'due_asc': filtered.sort((a, b) => {
            if (!a.due_date) return 1;
            if (!b.due_date) return -1;
            return new Date(a.due_date) - new Date(b.due_date);
        }); break;
        case 'title_az': filtered.sort((a, b) => (a.title || '').localeCompare(b.title || '')); break;
        default: filtered.sort((a, b) => new Date(b.created_at) - new Date(a.created_at));
    }
    
    renderAssignments(filtered);
    if (filtered.length === 0) showToast('No assignments match your filters', '#dc2626');
}


function resetFilters() {
    document.getElementById('searchInput').value = '';
    document.getElementById('languageFilter').value = 'all';
    document.getElementById('statusFilter').value = 'all';
    document.getElementById('sortBy').value = 'latest';
    document.getElementById('latePolicyFilter').value = 'all';
    renderAssignments(allAssignments);
    showToast('Filters cleared', '#64748b');
}

document.addEventListener('DOMContentLoaded', function() {
    renderAssignments(allAssignments);
    const searchInput = document.getElementById('searchInput');
    if (searchInput) {
        searchInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') applyFilters();
        });
    }
});

window.onclick = function(event) {
    const gradeModal = document.getElementById('gradeModal');
    const editModal = document.getElementById('editAssignmentModal');
    const deleteModal = document.getElementById('deleteAssignmentModal');
    const viewModal = document.getElementById('viewSubmissionModal');
if (event.target === viewModal) closeViewSubmissionModal();
    if (event.target === gradeModal) closeGradeModal();
    if (event.target === editModal) closeEditAssignmentModal();
    if (event.target === deleteModal) closeDeleteAssignmentModal();
}

setTimeout(function() {
    const alerts = document.querySelectorAll('.alert');
    alerts.forEach(alert => {
        alert.style.transition = 'opacity 0.5s';
        alert.style.opacity = '0';
        setTimeout(() => alert.remove(), 500);
    });
}, 4000);

let currentSubmissionPath = '';
let currentSubmissionName = '';

function openViewSubmissionModal(filePath, fileName, fileSize) {
    currentSubmissionPath = filePath;
    currentSubmissionName = fileName;
    
    const contentDiv = document.getElementById('submissionPreviewContent');
    const downloadBtn = document.getElementById('downloadSubmissionBtn');
    const fullPath = '../uploads/assignments/submission/' + filePath;
    const fileExt = fileName.split('.').pop().toLowerCase();
    
    // Show loading state
    contentDiv.innerHTML = '<div style="text-align: center; padding: 40px;"><i class="bi bi-hourglass-split" style="font-size: 32px;"></i><p>Loading...</p></div>';
    
    // Set download button action
    downloadBtn.onclick = function() {
        window.location.href = fullPath;
    };
    
    // Check file type and display appropriately
    if (['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(fileExt)) {
        contentDiv.innerHTML = `
            <div style="text-align: center;">
                <img src="${fullPath}" alt="Submission" style="max-width: 100%; border-radius: 12px; max-height: 50vh; object-fit: contain;">
                <p style="margin-top: 12px; font-size: 12px; color: #64748b;">${fileName} (${formatFileSize(fileSize)})</p>
            </div>
        `;
    } 
    else if (fileExt === 'pdf') {
        contentDiv.innerHTML = `
            <embed src="${fullPath}" style="width: 100%; height: 500px; border-radius: 12px;" type="application/pdf">
            <p style="margin-top: 12px; font-size: 12px; color: #64748b; text-align: center;">${fileName} (${formatFileSize(fileSize)})</p>
        `;
    }
    else if (['mp4', 'mov', 'avi', 'webm', 'mkv'].includes(fileExt)) {
        contentDiv.innerHTML = `
            <video controls style="width: 100%; border-radius: 12px; max-height: 50vh;">
                <source src="${fullPath}">
                Your browser does not support the video tag.
            </video>
            <p style="margin-top: 12px; font-size: 12px; color: #64748b; text-align: center;">${fileName} (${formatFileSize(fileSize)})</p>
        `;
    }
    else if (['mp3', 'wav', 'ogg', 'm4a'].includes(fileExt)) {
        contentDiv.innerHTML = `
            <div style="text-align: center; padding: 40px; background: #f8fafc; border-radius: 16px;">
                <i class="bi bi-mic" style="font-size: 64px; color: #E75A9B;"></i>
                <audio controls style="width: 100%; margin-top: 20px;">
                    <source src="${fullPath}">
                    Your browser does not support the audio tag.
                </audio>
                <p style="margin-top: 12px; font-size: 12px; color: #64748b;">${fileName} (${formatFileSize(fileSize)})</p>
            </div>
        `;
    }
    else if (fileExt === 'txt') {
        fetch(fullPath)
            .then(response => response.text())
            .then(text => {
                contentDiv.innerHTML = `
                    <div style="background: #f8fafc; padding: 16px; border-radius: 12px;">
                        <pre style="white-space: pre-wrap; font-family: monospace; font-size: 13px; margin: 0;">${escapeHtml(text)}</pre>
                    </div>
                    <p style="margin-top: 12px; font-size: 12px; color: #64748b;">${fileName} (${formatFileSize(fileSize)})</p>
                `;
            })
            .catch(() => {
                contentDiv.innerHTML = `
                    <div style="text-align: center; padding: 60px; background: #f8fafc; border-radius: 16px;">
                        <i class="bi bi-file-earmark" style="font-size: 64px; color: #cbd5e1;"></i>
                        <p style="margin-top: 16px;">Cannot preview this file.</p>
                        <p style="font-size: 13px; color: #64748b;">${fileName}</p>
                    </div>
                `;
            });
        return;
    }
    else {
        contentDiv.innerHTML = `
            <div style="text-align: center; padding: 60px; background: #f8fafc; border-radius: 16px;">
                <i class="bi bi-file-earmark" style="font-size: 64px; color: #cbd5e1;"></i>
                <p style="margin-top: 16px;">Preview not available for this file type.</p>
                <p style="font-size: 13px; color: #64748b;">${fileName}</p>
                <p style="margin-top: 16px;">Click Download to view the file.</p>
            </div>
        `;
    }
    
    document.getElementById('viewSubmissionModal').classList.add('active');
}

function closeViewSubmissionModal() {
    document.getElementById('viewSubmissionModal').classList.remove('active');
    document.getElementById('submissionPreviewContent').innerHTML = '';
    currentSubmissionPath = '';
    currentSubmissionName = '';
}
// File input handler
const fileInput = document.getElementById('edit_new_file');
if (fileInput) {
    fileInput.addEventListener('change', function(e) {
        const file = e.target.files[0];
        const clearBtn = document.getElementById('clearFileBtn');
        const fileNameDisplay = document.getElementById('selectedFileName');
        
        if (file) {
            selectedFile = file;
            if (file.size > 50 * 1024 * 1024) {
                showToast('File too large! Maximum size is 50MB', '#dc2626');
                this.value = '';
                selectedFile = null;
                if (clearBtn) clearBtn.style.display = 'none';
                if (fileNameDisplay) fileNameDisplay.style.display = 'none';
                return;
            }
            
            if (clearBtn) clearBtn.style.display = 'inline-flex';
            if (fileNameDisplay) {
                fileNameDisplay.style.display = 'flex';
                fileNameDisplay.innerHTML = `
                    <i class="bi bi-file-earmark-check"></i>
                    <span>${escapeHtml(file.name)} (${formatFileSize(file.size)})</span>
                    <button type="button" class="remove-file" onclick="clearSelectedFile()">
                        <i class="bi bi-x-circle"></i>
                    </button>
                `;
            }
        } else {
            clearSelectedFile();
        }
    });
}

// URL input handler
const urlInput = document.getElementById('edit_new_url');
if (urlInput) {
    urlInput.addEventListener('input', function() {
        const url = this.value.trim();
        const clearUrlBtn = document.getElementById('clearUrlBtn');
        const urlDisplay = document.getElementById('selectedUrlDisplay');
        
        if (url && isValidUrl(url)) {
            if (clearUrlBtn) clearUrlBtn.style.display = 'inline-flex';
            if (urlDisplay) {
                urlDisplay.style.display = 'flex';
                urlDisplay.innerHTML = `
                    <i class="bi bi-link-45deg"></i>
                    <span>${escapeHtml(url)}</span>
                    <button type="button" class="remove-file" onclick="clearUrlInput()">
                        <i class="bi bi-x-circle"></i>
                    </button>
                `;
            }
        } else {
            if (clearUrlBtn) clearUrlBtn.style.display = 'none';
            if (urlDisplay) {
                urlDisplay.style.display = 'none';
                urlDisplay.innerHTML = '';
            }
        }
    });
}

function isValidUrl(string) {
    try { new URL(string); return true; } catch(_) { return false; }
}

// Add event listeners for edit modal
const editDueDateInput = document.getElementById('edit_due_date');
if (editDueDateInput) {
    editDueDateInput.addEventListener('change', toggleEditLateOptionsByDueDate);
    editDueDateInput.addEventListener('input', toggleEditLateOptionsByDueDate);
}

// Add event listener for allow late checkbox
const editAllowLate = document.getElementById('edit_allow_late');
if (editAllowLate) {
    editAllowLate.addEventListener('change', toggleEditLateOptions);
}

// Add event listener for cutoff type
const editLateCutoffType = document.getElementById('edit_lateCutoffType');
if (editLateCutoffType) {
    editLateCutoffType.addEventListener('change', updateEditLateOptions);
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