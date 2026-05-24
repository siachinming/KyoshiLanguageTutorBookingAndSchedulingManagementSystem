<?php
session_start();
include 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'tutor') {
    header("Location: login.php");
    exit();
}

$userID = $_SESSION['user_id'];
$booking_id = intval($_GET['booking_id'] ?? 0);
$assetBase = '../assets/img';

// Get tutor info for nav
$stmtUser = $conn->prepare("SELECT * FROM users WHERE id = ? AND role = 'tutor'");
$stmtUser->bind_param("i", $userID);
$stmtUser->execute();
$user = $stmtUser->get_result()->fetch_assoc();
$displayName = $user['fullname'] ?? '';
$profilePic = !empty($user['profile_pic']) ? '../uploads/profiles/' . $user['profile_pic'] : $assetBase . '/profile-tutor.png';

if (!$booking_id) {
    header("Location: booking_requests.php");
    exit();
}

// Get booking info
$stmt = $conn->prepare("
    SELECT b.*, u.fullname as student_name
    FROM bookings b
    JOIN users u ON b.student_id = u.id
    WHERE b.id = ? AND b.tutor_id = ?
");
$stmt->bind_param("ii", $booking_id, $userID);
$stmt->execute();
$booking = $stmt->get_result()->fetch_assoc();

if (!$booking) {
    header("Location: booking_requests.php");
    exit();
}

// Get pre-session materials (including URLs)
$preStmt = $conn->prepare("
    SELECT * FROM learning_materials 
    WHERE booking_id = ? AND tutor_id = ? AND material_type = 'pre'
    ORDER BY uploaded_at DESC
");
$preStmt->bind_param("ii", $booking_id, $userID);
$preStmt->execute();
$preMaterials = $preStmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get post-session materials (including URLs)
$postStmt = $conn->prepare("
    SELECT * FROM learning_materials 
    WHERE booking_id = ? AND tutor_id = ? AND material_type = 'post'
    ORDER BY uploaded_at DESC
");
$postStmt->bind_param("ii", $booking_id, $userID);
$postStmt->execute();
$postMaterials = $postStmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Helper functions
function getFileIcon($filename, $is_url = false, $material_url = null) {
    if ($is_url) {
        // Detect platform from URL
        if (strpos($material_url, 'youtube.com') !== false || strpos($material_url, 'youtu.be') !== false) {
            return 'bi-youtube';
        } elseif (strpos($material_url, 'docs.google.com') !== false) {
            return 'bi-google';
        } elseif (strpos($material_url, 'quizlet.com') !== false) {
            return 'bi-question-circle';
        } else {
            return 'bi-link-45deg';
        }
    }
    
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $icons = [
        'pdf' => 'bi-filetype-pdf',
        'doc' => 'bi-filetype-doc',
        'docx' => 'bi-filetype-docx',
        'jpg' => 'bi-filetype-jpg',
        'jpeg' => 'bi-filetype-jpg',
        'png' => 'bi-filetype-png',
        'txt' => 'bi-filetype-txt',
        'zip' => 'bi-filetype-zip',
        'mp4' => 'bi-filetype-mp4',
        'mp3' => 'bi-filetype-mp3'
    ];
    return $icons[$ext] ?? 'bi-file-earmark-text';
}

function e($value) {
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function fileExists($path) {
    return file_exists($path) && is_file($path);
}

function formatFileSize($bytes) {
    if (!$bytes) return '';
    if ($bytes >= 1048576) {
        return round($bytes / 1048576, 1) . ' MB';
    } elseif ($bytes >= 1024) {
        return round($bytes / 1024, 1) . ' KB';
    } else {
        return $bytes . ' bytes';
    }
}

function getDomainName($url) {
    $domain = parse_url($url, PHP_URL_HOST);
    if ($domain) {
        $domain = str_replace('www.', '', $domain);
        return $domain;
    }
    return 'Link';
}

$success_msg = $_GET['success'] ?? '';
$error_msg = $_GET['error'] ?? '';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Learning Materials - Kyoshi</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Poppins', sans-serif;
            background: url('../assets/img/background2.png') no-repeat center top;
            background-size: cover;
            min-height: 100vh;
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
        .nav-links a.active { border-bottom: 2px solid #496894; }
        
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
            max-width: 900px;
            margin: 32px auto 60px;
            padding: 0 20px;
        }
        
        .materials-card {
            background: white;
            border-radius: 24px;
            padding: 32px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
        }
        
        h1 { font-size: 24px; color: #1d3156; margin-bottom: 8px; }
        .subtitle { color: #64748b; font-size: 14px; margin-bottom: 24px; border-bottom: 1px solid #eef2f7; padding-bottom: 16px; }
        .btn-back { display: inline-flex; align-items: center; gap: 6px; color: #64748b; text-decoration: none; margin-bottom: 20px; font-size: 13px; }
        .btn-back:hover { color: #E75A9B; }
        
        .alert { padding: 12px 16px; border-radius: 12px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; }
        .alert-success { background: #e8f5e9; color: #2e7d32; border-left: 4px solid #2e7d32; }
        .alert-error { background: #ffebee; color: #c62828; border-left: 4px solid #c62828; }
        
        .tabs { display: flex; gap: 0; margin-bottom: 24px; border-bottom: 1px solid #eef2f7; justify-content: center;}
        .tab-btn { background: none; border: none; padding: 12px 24px; font-size: 14px; font-weight: 600; color: #64748b; cursor: pointer; }
        .tab-btn:hover { color: #E75A9B; }
        .tab-btn.active { color: #E75A9B; border-bottom: 2px solid #E75A9B; }
        
        .stat-badge { background: #f8fafc; padding: 6px 12px; border-radius: 20px; font-size: 12px; color: #1d3156; margin-left: 8px; }
        
        .tab-content { display: none; }
        .tab-content.active { display: block; }
        
        .material-item { border: 1px solid #eef2f7; border-radius: 16px; padding: 20px; margin-bottom: 16px; background: white; }
        .material-item:hover { box-shadow: 0 4px 12px rgba(0,0,0,0.08); }
        
        .material-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px; flex-wrap: wrap; gap: 12px; }
        .material-title { font-size: 16px; font-weight: 600; color: #1d3156; display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
        .material-actions { display: flex; gap: 8px; flex-wrap: wrap; }
        .material-desc { color: #64748b; font-size: 13px; margin-bottom: 12px; background: #f8fafc; padding: 12px; border-radius: 12px; }
        .material-meta { font-size: 11px; color: #94a3b8; display: flex; gap: 16px; flex-wrap: wrap; margin-top: 8px; }
        
        .btn-view, .btn-download, .btn-delete, .btn-link {
            padding: 6px 14px;
            border-radius: 30px;
            text-decoration: none;
            font-size: 12px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s;
            cursor: pointer;
            border: none;
        }
        .btn-view { background: #e0f2fe; color: #0284c7; }
        .btn-view:hover { background: #bae6fd; transform: translateY(-1px); }
        .btn-download { background: #e2e8f0; color: #1d3156; }
        .btn-download:hover { background: #cbd5e1; transform: translateY(-1px); }
        .btn-link { background: #e0f2fe; color: #0284c7; }
        .btn-link:hover { background: #bae6fd; transform: translateY(-1px); }
        .btn-delete { background: #fee2e2; color: #dc2626; }
        .btn-delete:hover { background: #fecaca; transform: translateY(-1px); }
        
        .url-badge {
            background: #e0f2fe;
            color: #0284c7;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: 600;
            margin-left: 8px;
        }
        
        .empty-state { text-align: center; padding: 60px 20px; color: #94a3b8; }
        .empty-state i { font-size: 64px; margin-bottom: 16px; display: block; opacity: 0.5; }
        .upload-btn { margin-top: 24px; text-align: center; padding-top: 20px; border-top: 1px solid #eef2f7; }
        
        .search-box { margin-bottom: 20px; position: relative; }
        .search-box input { width: 100%; padding: 10px 16px 10px 40px; border: 1px solid #eef2f7; border-radius: 30px; font-size: 13px; }
        .search-box i { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: #94a3b8; }
        
        @media (max-width: 768px) {
            .main-content { padding: 0 16px; }
            .materials-card { padding: 20px; }
            .material-header { flex-direction: column; }
            .nav { flex-wrap: wrap; }
            .nav-links { order: 3; width: 100%; justify-content: center; padding-bottom: 10px; }
        }
    </style>
</head>
<body>

<!-- TOPBAR -->
<div class="topbar">
    <div class="container">
        <nav class="nav">
            <a href="tutor_dashboard.php" class="brand">
                <img src="<?= e($assetBase) ?>/logo.png" alt="Kyoshi">
                <div>
                    <strong>Kyoshi</strong>
                    <span>Teacher Space</span>
                </div>
            </a>
            <div class="nav-links">
                <a href="tutor_dashboard.php">Dashboard</a>
                <a href="availability.php">Availability</a>
                <a href="booking_requests.php">Requests</a>
                <a href="learning_materials.php?booking_id=<?= $booking_id ?>" class="active">Materials</a>
                <a href="earnings.php">Earnings</a>
                <a href="meeting_links.php">Meeting Links</a>
            </div>
            <div style="position:relative;">
                <button class="profile" onclick="toggleDropdown()">
                    <img src="<?= e($profilePic) ?>" alt="Profile">
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
</div>

<!-- MAIN CONTENT -->
<div class="main-content">
    <div class="materials-card">
        <a href="tutor_booking_detail.php?id=<?= $booking_id ?>" class="btn-back">
            <i class="bi bi-arrow-left"></i> Back to Booking
        </a>
        
        <h1><i class="bi bi-book"></i> Learning Materials</h1>
        <p class="subtitle">For: <?= e($booking['student_name']) ?> · <?= e($booking['language']) ?> session</p>
        
        <?php if ($success_msg): ?>
            <div class="alert alert-success"><i class="bi bi-check-circle-fill"></i><span><?= e(urldecode($success_msg)) ?></span></div>
        <?php endif; ?>
        <?php if ($error_msg): ?>
            <div class="alert alert-error"><i class="bi bi-exclamation-triangle-fill"></i><span><?= e(urldecode($error_msg)) ?></span></div>
        <?php endif; ?>
        
        <div class="tabs">
            <button class="tab-btn active" onclick="switchTab('pre')">📖 Pre-Session <span class="stat-badge"><?= count($preMaterials) ?></span></button>
            <button class="tab-btn" onclick="switchTab('post')">✏️ Post-Session <span class="stat-badge"><?= count($postMaterials) ?></span></button>
        </div>
        
        <div class="search-box" id="pre-search" style="display: block;">
            <i class="bi bi-search"></i>
            <input type="text" placeholder="Search pre-session materials..." onkeyup="filterMaterials('pre', this.value)">
        </div>
        <div class="search-box" id="post-search" style="display: none;">
            <i class="bi bi-search"></i>
            <input type="text" placeholder="Search post-session materials..." onkeyup="filterMaterials('post', this.value)">
        </div>
        
        <!-- Pre-Session Tab -->
        <div id="pre-tab" class="tab-content active">
            <?php if (empty($preMaterials)): ?>
                <div class="empty-state"><i class="bi bi-inbox"></i><p>No pre-session materials uploaded yet</p></div>
            <?php else: ?>
                <div id="pre-materials-list">
                    <?php foreach ($preMaterials as $material): ?>
                        <div class="material-item" data-title="<?= e(strtolower($material['title'])) ?>" data-desc="<?= e(strtolower($material['description'] ?? '')) ?>">
                            <div class="material-header">
                                <div class="material-title">
                                    <i class="bi <?= getFileIcon($material['file_name'] ?? '', $material['is_url'] ?? 0, $material['material_url'] ?? '') ?>"></i>
                                    <?= e($material['title']) ?>
                                    <?php if (isset($material['is_url']) && $material['is_url'] == 1): ?>
                                        <span class="url-badge"><i class="bi bi-link-45deg"></i> Link</span>
                                    <?php endif; ?>
                                </div>
                                <div class="material-actions">
                                    <?php if (isset($material['is_url']) && $material['is_url'] == 1): ?>
                                        <!-- URL Material -->
                                        <a href="<?= e($material['material_url']) ?>" target="_blank" class="btn-link">
                                            <i class="bi bi-box-arrow-up-right"></i> Open Link
                                        </a>
                                    <?php else: ?>
                                        <!-- File Material -->
                                        <?php if (fileExists($material['file_path'])): ?>
                                            <a href="view_materials.php?id=<?= $material['id'] ?>&booking_id=<?= $booking_id ?>" target="_blank" class="btn-view">
                                                <i class="bi bi-eye"></i> Preview
                                            </a>
                                            <a href="download_material.php?id=<?= $material['id'] ?>&booking_id=<?= $booking_id ?>" class="btn-download">
                                                <i class="bi bi-download"></i> Download
                                            </a>
                                        <?php else: ?>
                                            <span class="btn-view" style="opacity:0.5;cursor:not-allowed;">
                                                <i class="bi bi-exclamation-triangle"></i> File Missing
                                            </span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    <a href="delete_material.php?id=<?= $material['id'] ?>&booking_id=<?= $booking_id ?>" class="btn-delete" onclick="return confirm('Delete <?= e($material['title']) ?>?')">
                                        <i class="bi bi-trash"></i> Delete
                                    </a>
                                </div>
                            </div>
                            <?php if (!empty($material['description'])): ?>
                                <div class="material-desc"><?= nl2br(e($material['description'])) ?></div>
                            <?php endif; ?>
                            <div class="material-meta">
                                <span><i class="bi bi-calendar"></i> Uploaded <?= date('d M Y, g:i A', strtotime($material['uploaded_at'])) ?></span>
                                <?php if (isset($material['is_url']) && $material['is_url'] == 1): ?>
                                    <span><i class="bi bi-link-45deg"></i> <?= e(getDomainName($material['material_url'])) ?></span>
                                <?php else: ?>
                                    <span><i class="bi bi-file-earmark"></i> <?= e($material['file_name']) ?></span>
                                    <?php if (isset($material['file_size']) && $material['file_size']): ?>
                                        <span><i class="bi bi-database"></i> <?= formatFileSize($material['file_size']) ?></span>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Post-Session Tab -->
        <div id="post-tab" class="tab-content">
            <?php if (empty($postMaterials)): ?>
                <div class="empty-state"><i class="bi bi-inbox"></i><p>No post-session materials uploaded yet</p></div>
            <?php else: ?>
                <div id="post-materials-list">
                    <?php foreach ($postMaterials as $material): ?>
                        <div class="material-item" data-title="<?= e(strtolower($material['title'])) ?>" data-desc="<?= e(strtolower($material['description'] ?? '')) ?>">
                            <div class="material-header">
                                <div class="material-title">
                                    <i class="bi <?= getFileIcon($material['file_name'] ?? '', $material['is_url'] ?? 0, $material['material_url'] ?? '') ?>"></i>
                                    <?= e($material['title']) ?>
                                    <?php if (isset($material['is_url']) && $material['is_url'] == 1): ?>
                                        <span class="url-badge"><i class="bi bi-link-45deg"></i> Link</span>
                                    <?php endif; ?>
                                </div>
                                <div class="material-actions">
                                    <?php if (isset($material['is_url']) && $material['is_url'] == 1): ?>
                                        <!-- URL Material -->
                                        <a href="<?= e($material['material_url']) ?>" target="_blank" class="btn-link">
                                            <i class="bi bi-box-arrow-up-right"></i> Open Link
                                        </a>
                                    <?php else: ?>
                                        <!-- File Material -->
                                        <?php if (fileExists($material['file_path'])): ?>
                                            <a href="view_materials.php?id=<?= $material['id'] ?>&booking_id=<?= $booking_id ?>" target="_blank" class="btn-view">
                                                <i class="bi bi-eye"></i> Preview
                                            </a>
                                            <a href="download_material.php?id=<?= $material['id'] ?>&booking_id=<?= $booking_id ?>" class="btn-download">
                                                <i class="bi bi-download"></i> Download
                                            </a>
                                        <?php else: ?>
                                            <span class="btn-view" style="opacity:0.5;cursor:not-allowed;">
                                                <i class="bi bi-exclamation-triangle"></i> File Missing
                                            </span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    <a href="delete_material.php?id=<?= $material['id'] ?>&booking_id=<?= $booking_id ?>" class="btn-delete" onclick="return confirm('Delete <?= e($material['title']) ?>?')">
                                        <i class="bi bi-trash"></i> Delete
                                    </a>
                                </div>
                            </div>
                            <?php if (!empty($material['description'])): ?>
                                <div class="material-desc"><?= nl2br(e($material['description'])) ?></div>
                            <?php endif; ?>
                            <div class="material-meta">
                                <span><i class="bi bi-calendar"></i> Uploaded on <?= date('d M Y, g:i A', strtotime($material['uploaded_at'])) ?></span>
                                <?php if (isset($material['is_url']) && $material['is_url'] == 1): ?>
                                    <span><i class="bi bi-link-45deg"></i> <?= e(getDomainName($material['material_url'])) ?></span>
                                <?php else: ?>
                                    <span><i class="bi bi-file-earmark"></i> <?= e($material['file_name']) ?></span>
                                    <?php if (isset($material['file_size']) && $material['file_size']): ?>
                                        <span><i class="bi bi-database"></i> <?= formatFileSize($material['file_size']) ?></span>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        
        <div class="upload-btn">
            <a href="upload_material.php?booking_id=<?= $booking_id ?>" class="btn-download" style="background: linear-gradient(135deg, #E75A9B, #F28AB2); color: white; padding: 12px 24px;">
                <i class="bi bi-plus-circle"></i> Upload New Material
            </a>
        </div>
    </div>
</div>

<script>
function toggleDropdown() {
    const dropdown = document.getElementById('profileDropdown');
    if (dropdown.style.display === 'block') {
        dropdown.style.display = 'none';
    } else {
        dropdown.style.display = 'block';
    }
}

window.addEventListener('click', function(e) {
    const dropdown = document.getElementById('profileDropdown');
    const button = document.querySelector('.profile');
    if (button && !button.contains(e.target) && dropdown && !dropdown.contains(e.target)) {
        dropdown.style.display = 'none';
    }
});

function switchTab(tab) {
    document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
    event.target.classList.add('active');
    document.getElementById('pre-tab').classList.remove('active');
    document.getElementById('post-tab').classList.remove('active');
    document.getElementById(tab + '-tab').classList.add('active');
    document.getElementById('pre-search').style.display = tab === 'pre' ? 'block' : 'none';
    document.getElementById('post-search').style.display = tab === 'post' ? 'block' : 'none';
    if (tab === 'pre') {
        let searchInput = document.querySelector('#pre-search input');
        if (searchInput) { searchInput.value = ''; filterMaterials('pre', ''); }
    } else {
        let searchInput = document.querySelector('#post-search input');
        if (searchInput) { searchInput.value = ''; filterMaterials('post', ''); }
    }
}

function filterMaterials(type, searchTerm) {
    const materialsList = document.getElementById(type + '-materials-list');
    if (!materialsList) return;
    const items = materialsList.getElementsByClassName('material-item');
    const searchLower = searchTerm.toLowerCase();
    let visibleCount = 0;
    for (let item of items) {
        const title = item.getAttribute('data-title') || '';
        const desc = item.getAttribute('data-desc') || '';
        if (title.includes(searchLower) || desc.includes(searchLower)) {
            item.style.display = '';
            visibleCount++;
        } else {
            item.style.display = 'none';
        }
    }
    let existingEmpty = materialsList.parentElement.querySelector('.empty-state-message');
    if (existingEmpty) existingEmpty.remove();
    if (visibleCount === 0 && items.length > 0) {
        const emptyDiv = document.createElement('div');
        emptyDiv.className = 'empty-state empty-state-message';
        emptyDiv.innerHTML = '<i class="bi bi-search"></i><p>No matching materials found</p>';
        materialsList.insertAdjacentElement('afterend', emptyDiv);
    }
}

setTimeout(() => {
    document.querySelectorAll('.alert').forEach(alert => {
        alert.style.opacity = '0';
        setTimeout(() => alert.remove(), 300);
    });
}, 5000);
</script>

</body>
</html>