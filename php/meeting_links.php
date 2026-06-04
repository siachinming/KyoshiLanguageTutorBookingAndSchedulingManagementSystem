<?php
session_start();
include 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'tutor') {
    header("Location: login.php");
    exit();
}

$userID = $_SESSION['user_id'];
$assetBase = '../assets/img';

// Get user info for nav
$stmtUser = $conn->prepare("SELECT * FROM users WHERE id = ? AND role = 'tutor'");
$stmtUser->bind_param("i", $userID);
$stmtUser->execute();
$user = $stmtUser->get_result()->fetch_assoc();
$displayName = $user['fullname'] ?? '';
$profilePic = !empty($user['profile_pic']) ? '../uploads/profiles/' . $user['profile_pic'] : $assetBase . '/profile-tutor.png';

// Get ONLY ONLINE upcoming confirmed bookings
$stmt = $conn->prepare("
    SELECT b.id, b.booking_date, b.booking_time, b.language, b.meeting_link, b.learning_mode,
           u.fullname as student_name, u.id as student_id
    FROM bookings b
    JOIN users u ON b.student_id = u.id
    WHERE b.tutor_id = ? 
    AND b.status = 'confirmed' 
    AND b.learning_mode = 'online'
    AND b.booking_date >= CURDATE()
    ORDER BY b.booking_date ASC, b.booking_time ASC
");
$stmt->bind_param("i", $userID);
$stmt->execute();
$bookings = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$success_msg = '';
$error_msg = '';

// Handle single update via AJAX (for the modal) - DIRECT DATABASE UPDATE
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SERVER['HTTP_CONTENT_TYPE']) && strpos($_SERVER['HTTP_CONTENT_TYPE'], 'application/json') !== false) {
    $input = json_decode(file_get_contents('php://input'), true);
    if (isset($input['booking_id']) && isset($input['meeting_link'])) {
        $booking_id = intval($input['booking_id']);
        $meeting_link = trim($input['meeting_link']);
        
        if (empty($meeting_link)) {
            echo json_encode(['success' => false, 'message' => 'Meeting link is required']);
            exit();
        }
        
        if (!filter_var($meeting_link, FILTER_VALIDATE_URL)) {
            echo json_encode(['success' => false, 'message' => 'Please enter a valid URL starting with http:// or https://']);
            exit();
        }
        
        // Verify booking belongs to this tutor
        $checkStmt = $conn->prepare("SELECT id FROM bookings WHERE id = ? AND tutor_id = ?");
        $checkStmt->bind_param("ii", $booking_id, $userID);
        $checkStmt->execute();
        if ($checkStmt->get_result()->num_rows === 0) {
            echo json_encode(['success' => false, 'message' => 'Booking not found']);
            exit();
        }
        
        // Update the meeting link directly
        $updateStmt = $conn->prepare("UPDATE bookings SET meeting_link = ?, link_provided_at = NOW() WHERE id = ?");
        $updateStmt->bind_param("si", $meeting_link, $booking_id);
        
        if ($updateStmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Meeting link updated']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
        }
        exit();
    }
}
// Handle bulk update - Overwrite All (DIRECT DATABASE UPDATE - NO CURL)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_update_overwrite'])) {
    $bulk_link = trim($_POST['bulk_link']);
    if (empty($bulk_link)) {
        $error_msg = "Please enter a meeting link.";
    } elseif (!filter_var($bulk_link, FILTER_VALIDATE_URL)) {
        $error_msg = "Please enter a valid URL starting with http:// or https://";
    } else {
        $updated_count = 0;
        $failed_count = 0;
        foreach ($bookings as $booking) {
            $updateStmt = $conn->prepare("UPDATE bookings SET meeting_link = ?, link_provided_at = NOW() WHERE id = ? AND tutor_id = ?");
            $updateStmt->bind_param("sii", $bulk_link, $booking['id'], $userID);
            $updateStmt->execute();
            
            // Check if any row was actually affected
            if ($updateStmt->affected_rows > 0) {
                $updated_count++;
            } else {
                $failed_count++;
            }
            $updateStmt->close();
        }
        
        // Refresh the bookings data after update
        $stmt = $conn->prepare("
            SELECT b.id, b.booking_date, b.booking_time, b.language, b.meeting_link, b.learning_mode,
                   u.fullname as student_name, u.id as student_id
            FROM bookings b
            JOIN users u ON b.student_id = u.id
            WHERE b.tutor_id = ? 
            AND b.status = 'confirmed' 
            AND b.learning_mode = 'online'
            AND b.booking_date >= CURDATE()
            ORDER BY b.booking_date ASC, b.booking_time ASC
        ");
        $stmt->bind_param("i", $userID);
        $stmt->execute();
        $bookings = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        
        $success_msg = "$updated_count meetings updated successfully" . ($failed_count > 0 ? " ($failed_count failed)" : "");
        header("Location: meeting_links.php?success=" . urlencode($success_msg));
        exit();
    }
}

// Handle bulk update - Only update students WITHOUT existing links
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_update_skip'])) {
    $bulk_link = trim($_POST['bulk_link']);
    if (empty($bulk_link)) {
        $error_msg = "Please enter a meeting link.";
    } elseif (!filter_var($bulk_link, FILTER_VALIDATE_URL)) {
        $error_msg = "Please enter a valid URL starting with http:// or https://";
    } else {
        $updated_count = 0;
        $skipped_count = 0;
        $failed_count = 0;
        foreach ($bookings as $booking) {
            // Only update if no meeting link exists
            if (empty($booking['meeting_link']) || $booking['meeting_link'] === null) {
                $updateStmt = $conn->prepare("UPDATE bookings SET meeting_link = ?, link_provided_at = NOW() WHERE id = ? AND tutor_id = ?");
                $updateStmt->bind_param("sii", $bulk_link, $booking['id'], $userID);
                $updateStmt->execute();
                
                if ($updateStmt->affected_rows > 0) {
                    $updated_count++;
                } else {
                    $failed_count++;
                }
                $updateStmt->close();
            } else {
                $skipped_count++;
            }
        }
        
        // Refresh the bookings data after update
        $stmt = $conn->prepare("
            SELECT b.id, b.booking_date, b.booking_time, b.language, b.meeting_link, b.learning_mode,
                   u.fullname as student_name, u.id as student_id
            FROM bookings b
            JOIN users u ON b.student_id = u.id
            WHERE b.tutor_id = ? 
            AND b.status = 'confirmed' 
            AND b.learning_mode = 'online'
            AND b.booking_date >= CURDATE()
            ORDER BY b.booking_date ASC, b.booking_time ASC
        ");
        $stmt->bind_param("i", $userID);
        $stmt->execute();
        $bookings = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        
        $success_msg = "Updated $updated_count students (skipped $skipped_count who already had links)" . ($failed_count > 0 ? " ($failed_count failed)" : "");
        header("Location: meeting_links.php?success=" . urlencode($success_msg));
        exit();
    }
}

$success_msg = $_GET['success'] ?? '';
$error_msg = $_GET['error'] ?? '';
function e($value) { return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8'); }
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Meeting Links - Kyoshi</title>
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
        
        .main-container {
            max-width: 1200px;
            margin: 32px auto 60px;
            padding: 0 20px;
        }
        
        .content-card {
            background: white;
            border-radius: 24px;
            padding: 32px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
        }
        
        .btn-back {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            color: #64748b;
            text-decoration: none;
            margin-bottom: 20px;
            font-size: 13px;
        }
        .btn-back:hover { color: #E75A9B; }
        
        h1 { font-size: 24px; color: #1d3156; margin-bottom: 8px; }
        .subtitle { color: #64748b; font-size: 14px; margin-bottom: 24px; border-bottom: 1px solid #eef2f7; padding-bottom: 16px; }
        
        .alert { padding: 12px 16px; border-radius: 12px; margin-bottom: 20px; }
        .alert-success { background: #d4edda; color: #155724; border-left: 3px solid #28a745; }
        .alert-error { background: #f8d7da; color: #721c24; border-left: 3px solid #dc3545; }
        
        .alert-success, .alert-error {
            transition: opacity 0.5s ease;
        }

        .alert-success.fade-out, .alert-error.fade-out {
            opacity: 0;
            display: none;
        }
        .bulk-card {
            background: #f0f9ff;
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 24px;
            border: 1px solid #bae6fd;
        }
        
        .bulk-title {
            font-size: 16px;
            font-weight: 700;
            color: #0284c7;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-primary, .btn-outline, .btn-edit, .btn-warning {
            padding: 8px 20px;
            border-radius: 30px;
            font-size: 12px;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            cursor: pointer;
            border: none;
            transition: all 0.2s;
        }
        .btn-primary { background: linear-gradient(135deg, #E75A9B, #F28AB2); color: white; }
        .btn-outline { background: #e2e8f0; color: #1d3156; }
        .btn-edit { background: #e0f2fe; color: #0284c7; }
        .btn-warning { background: #fef3c7; color: #d97706; }
        
        .info-message {
            background: #fef3c7;
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 12px;
            color: #92400e;
            font-size: 14px;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th, td {
            padding: 14px 12px;
            text-align: left;
            border-bottom: 1px solid #eef2f7;
        }
        th {
            background: #f8fafc;
            font-weight: 600;
            color: #1d3156;
        }
        
        .meeting-link-display {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }
        .link-text {
            background: #f8fafc;
            padding: 6px 12px;
            border-radius: 8px;
            font-family: monospace;
            font-size: 12px;
            color: #E75A9B;
            word-break: break-all;
            flex: 1;
        }
        .no-link {
            color: #94a3b8;
            font-style: italic;
            font-size: 12px;
        }
        
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
        .modal.active {
            display: flex;
        }
        .modal-content {
            background: white;
            border-radius: 24px;
            width: 450px;
            max-width: 90%;
            padding: 28px;
        }
        .modal-header {
            font-size: 20px;
            font-weight: 700;
            color: #1d3156;
            margin-bottom: 16px;
            padding-bottom: 12px;
            border-bottom: 1px solid #eef2f7;
        }
        .modal-body {
            margin-bottom: 24px;
        }
        .modal-body label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: #1d3156;
            margin-bottom: 8px;
        }
        .modal-body input {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #cbd5e1;
            border-radius: 12px;
            font-family: monospace;
            font-size: 13px;
        }
        .modal-footer {
            display: flex;
            gap: 12px;
            justify-content: flex-end;
        }
        .student-info {
            background: #f8fafc;
            padding: 12px;
            border-radius: 12px;
            margin-bottom: 16px;
            font-size: 13px;
        }
        .student-info span {
            font-weight: 600;
            color: #1d3156;
        }
        
        .loading {
            display: inline-block;
            width: 16px;
            height: 16px;
            border: 2px solid #f3f3f3;
            border-top: 2px solid #E75A9B;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        @media (max-width: 768px) {
            .main-container { padding: 0 16px; }
            .content-card { padding: 20px; overflow-x: auto; }
            table { min-width: 600px; }
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
                <a href="booking_requests.php">My Bookings</a>
                <a href="material_overview.php">My Materials</a>
                <a href="assignment_overview.php">My Assignments</a>
                <a href="view_session_reports.php">My Reports</a>
            </div>
            <div style="position:relative;">
                <button class="profile" onclick="toggleDropdown()">
                    <img src="<?= e($profilePic) ?>" alt="Profile">
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
</div>

<!-- MAIN CONTENT -->
<div class="main-container">
    <div class="content-card">
        <a href="tutor_dashboard.php" class="btn-back">
            <i class="bi bi-arrow-left"></i> Back to Dashboard
        </a>
        
        <h1><i class="bi bi-camera-video"></i> Meeting Links</h1>
        <p class="subtitle">Manage meeting links for online sessions only</p>
        
        <?php if ($success_msg): ?>
            <div class="alert alert-success"><i class="bi bi-check-circle-fill"></i> <?= e(urldecode($success_msg)) ?></div>
        <?php endif; ?>
        <?php if ($error_msg): ?>
            <div class="alert alert-error"><i class="bi bi-exclamation-triangle-fill"></i> <?= e($error_msg) ?></div>
        <?php endif; ?>
        
        <?php if (empty($bookings)): ?>
            <div class="info-message">
                <i class="bi bi-info-circle-fill"></i>
                <div>
                    <strong>No online sessions found</strong><br>
                    You don't have any upcoming confirmed online sessions. Face-to-face sessions don't require meeting links.
                </div>
            </div>
        <?php else: ?>
        
        <!-- Bulk Apply Card -->
        <div class="bulk-card">
            <div class="bulk-title">
                <i class="bi bi-lightning-charge"></i> Apply to All Online Students
            </div>
            <form method="POST">
                <div style="display: flex; gap: 12px; flex-wrap: wrap; align-items: center;">
                    <input type="url" name="bulk_link" id="bulk_link_input" placeholder="https://meet.google.com/xxx or https://zoom.us/j/xxx" required style="flex: 1; padding: 10px 12px; border: 1px solid #cbd5e1; border-radius: 12px;">
                    <button type="submit" name="bulk_update_skip" class="btn-outline" onclick="return confirm('Only update students WITHOUT existing links? This will NOT change students who already have links.')">
                        <i class="bi bi-skip-forward"></i> Update Missing Only
                    </button>
                    <button type="submit" name="bulk_update_overwrite" class="btn-warning" onclick="return confirm('⚠️ WARNING: This will OVERWRITE ALL existing meeting links for ALL students. Continue?')">
                        <i class="bi bi-exclamation-triangle"></i> Apply for All
                    </button>
                </div>
            </form>
        </div>
        
        <!-- Individual links table -->
        <table>
            <thead>
                <tr>
                    <th>Student</th>
                    <th>Language</th>
                    <th>Date & Time</th>
                    <th>Meeting Link</th>
                    <th style="width:100px">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($bookings as $booking): ?>
                    <tr id="row-<?= $booking['id'] ?>">
                        <td>
                            <strong><?= e($booking['student_name']) ?></strong>
                        </td>
                        <td><?= e($booking['language']) ?></td>
                        <td>
                            <?= date('d M Y', strtotime($booking['booking_date'])) ?><br>
                            <small><?= date('g:i A', strtotime($booking['booking_time'])) ?></small>
                        </td>
                        <td id="display-<?= $booking['id'] ?>">
                            <div class="meeting-link-display">
                                <?php if (!empty($booking['meeting_link'])): ?>
                                    <span class="link-text">
                                        <i class="bi bi-link-45deg"></i> <?= e(trim($booking['meeting_link'])) ?>
                                    </span>
                                <?php else: ?>
                                    <span class="no-link">No meeting link set</span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td>
                            <button type="button" class="btn-edit" onclick="openEditModal(<?= $booking['id'] ?>, '<?= e(addslashes($booking['student_name'])) ?>', '<?= e(addslashes($booking['meeting_link'])) ?>')">
                                <i class="bi bi-pencil"></i> Edit
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<!-- Edit Modal -->
<div id="editModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <i class="bi bi-pencil-square"></i> Edit Meeting Link
        </div>
        <div class="modal-body">
            <div class="student-info">
                <span>Student:</span> <span id="modalStudentName"></span>
            </div>
            <label>Meeting Link</label>
            <input type="url" id="modalMeetingLink" placeholder="https://meet.google.com/xxx">
        </div>
        <div class="modal-footer">
            <button type="button" class="btn-outline" onclick="closeEditModal()">
                <i class="bi bi-x-lg"></i> Cancel
            </button>
            <button type="button" class="btn-primary" id="saveModalBtn" onclick="saveModalLink()">
                <i class="bi bi-check-lg"></i> Save
            </button>
        </div>
    </div>
</div>
<script>
// Auto-hide messages after 3 seconds with fade effect
document.addEventListener('DOMContentLoaded', function() {
    const messages = document.querySelectorAll('.alert-success, .alert-error');
    messages.forEach(function(message) {
        setTimeout(function() {
            message.classList.add('fade-out');
            setTimeout(function() {
                message.style.display = 'none';
            }, 500);
        }, 3000);
    });
});
</script>
<script>
let currentBookingId = null;

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

function openEditModal(bookingId, studentName, currentLink) {
    currentBookingId = bookingId;
    document.getElementById('modalStudentName').innerText = studentName;
    document.getElementById('modalMeetingLink').value = currentLink || '';
    document.getElementById('editModal').classList.add('active');
}

function closeEditModal() {
    document.getElementById('editModal').classList.remove('active');
    currentBookingId = null;
}

async function saveModalLink() {
    let newLink = document.getElementById('modalMeetingLink').value.trim();
    const saveBtn = document.getElementById('saveModalBtn');
    
    if (!newLink) {
        alert('Please enter a meeting link');
        return;
    }
    
    if (!newLink.startsWith('http://') && !newLink.startsWith('https://')) {
        alert('Please enter a valid URL starting with http:// or https://');
        return;
    }
    
    if (!currentBookingId) {
        alert('Error: No booking selected');
        return;
    }
    
    const originalText = saveBtn.innerHTML;
    saveBtn.innerHTML = '<span class="loading"></span> Saving...';
    saveBtn.disabled = true;
    
    try {
        // Post to the same file (meeting_links.php) which handles JSON requests
        const response = await fetch(window.location.href, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                booking_id: currentBookingId,
                meeting_link: newLink
            })
        });
        
        const result = await response.json();
        
        if (result.success) {
            const displayCell = document.getElementById(`display-${currentBookingId}`);
            if (displayCell) {
                displayCell.innerHTML = `
                    <div class="meeting-link-display">
                        <span class="link-text">
                            <i class="bi bi-link-45deg"></i> ${escapeHtml(newLink)}
                        </span>
                    </div>
                `;
            }
            
            closeEditModal();
            const successDiv = document.createElement('div');
            successDiv.className = 'alert alert-success';
            successDiv.innerHTML = '<i class="bi bi-check-circle-fill"></i> Meeting link updated!';
            document.querySelector('.content-card').insertBefore(successDiv, document.querySelector('.bulk-card'));
            setTimeout(() => successDiv.remove(), 3000);
            
            // Refresh after 1 second to show updated data
            setTimeout(() => location.reload(), 1000);
        } else {
            alert('Error: ' + result.message);
        }
    } catch (error) {
        alert('Error saving link: ' + error.message);
    }
    
    saveBtn.innerHTML = originalText;
    saveBtn.disabled = false;
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

window.onclick = function(event) {
    const modal = document.getElementById('editModal');
    if (event.target === modal) {
        closeEditModal();
    }
}
</script>
</body>
</html>