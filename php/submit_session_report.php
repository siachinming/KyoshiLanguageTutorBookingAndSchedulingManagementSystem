<?php
session_start();
include 'config.php';
include 'insert_notification.php';

$assetBase = '../assets/img';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'tutor') {
    header("Location: login.php");
    exit();
}

$userID = $_SESSION['user_id'];
$booking_id = isset($_GET['booking_id']) ? intval($_GET['booking_id']) : 0;

// Get tutor info
$stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
$stmt->bind_param("i", $userID);
$stmt->execute();
$tutor = $stmt->get_result()->fetch_assoc();
// Check if resubmitting a rejected report
$resubmit = isset($_GET['resubmit']) && $_GET['resubmit'] == 1;
$existing_report_id = isset($_GET['report_id']) ? intval($_GET['report_id']) : 0;

// Load existing report data if resubmitting
$resubmitData = null;
if ($resubmit && $existing_report_id > 0) {
    $stmt = $conn->prepare("
        SELECT sr.*, b.language, b.booking_date, b.booking_time, u.fullname as student_name
        FROM session_reports sr
        JOIN bookings b ON sr.booking_id = b.id
        JOIN users u ON sr.student_id = u.id
        WHERE sr.id = ? AND sr.tutor_id = ? AND sr.report_status = 'rejected'
    ");
    $stmt->bind_param("ii", $existing_report_id, $userID);
    $stmt->execute();
    $resubmitData = $stmt->get_result()->fetch_assoc();
    
    if ($resubmitData) {
        // Override the booking_id with the rejected report's booking_id
        $booking_id = $resubmitData['booking_id'];
        // Set a flag to show this is a resubmission
        $isResubmit = true;
    }
}
$displayName = $tutor['fullname'];
$profilePic = !empty($tutor['profile_pic']) ? '../uploads/profiles/' . $tutor['profile_pic'] : $assetBase . '/profile-tutor.png';

if (!$booking_id) {
   $completedBookings = $conn->query("
    SELECT 
        b.id, 
        b.language, 
        b.booking_date, 
        b.booking_time, 
        u.fullname as student_name,
        sr.id as report_id,
        sr.report_status,
        sr.submitted_at
    FROM bookings b
    JOIN users u ON b.student_id = u.id
    LEFT JOIN session_reports sr ON b.id = sr.booking_id
    LEFT JOIN session_completion sc ON b.id = sc.booking_id
    WHERE b.tutor_id = $userID 
    AND b.status = 'completed'
    AND (sc.student_confirmed IS NULL OR sc.student_confirmed = 1)
    AND (sc.no_show_type IS NULL OR sc.no_show_type != 'student_no_show')
    AND (sr.report_status IS NULL OR sr.report_status != 'approved')  -- Add this line to exclude approved reports
    ORDER BY b.booking_date DESC
");
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Select Session - Session Report</title>
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
        <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
        <style>
            * { margin: 0; padding: 0; box-sizing: border-box; }
            body { font-family: 'Poppins', sans-serif; background: url('../assets/img/background2.png') no-repeat center top; background-size: cover; min-height: 100vh; }
            body::before { content: ''; position: fixed; inset: 0; background: rgba(255,255,255,0.25); z-index: -1; }
            .topbar { width: 100%; background: rgba(254,214,206,0.92); backdrop-filter: blur(12px); position: sticky; top: 0; z-index: 999; box-shadow: 0 2px 20px rgba(0,0,0,0.08); border-bottom: 1px solid rgba(255,255,255,0.3); }
            .container { width: min(1400px, 94%); margin: auto; }
            .nav { display: flex; justify-content: space-between; align-items: center; gap: 32px; min-height: 70px; }
            .brand { display: flex; align-items: center; gap: 10px; text-decoration: none; }
            .brand img { width: 42px; height: 42px; object-fit: contain; }
            .brand strong { display: block; color: #1d3156; font-size: 20px; }
            .brand span { color: #496894; font-size: 11px; font-weight: 600; }
            .nav-links { display: flex; gap: 28px; align-items: center; flex-wrap: wrap; }
            .nav-links a { text-decoration: none; color: #1d3156; font-size: 14px; font-weight: 600; transition: 0.25s; padding: 6px 0; }
            .nav-links a:hover, .nav-links a.active { color: #496894; }
            .profile { display: flex; align-items: center; gap: 8px; background: rgba(255,255,255,0.1); border: 1px solid rgba(255,255,255,0.2); padding: 6px 14px 6px 8px; border-radius: 40px; cursor: pointer; }
            .profile img { width: 36px; height: 36px; border-radius: 50%; object-fit: cover; }
            .profile span { font-size: 13px; font-weight: 500; color: #1d3156; }
            .dropdown { position: absolute; top: calc(100% + 10px); right: 0; width: 220px; background: white; border-radius: 16px; overflow: hidden; display: none; border: 1px solid #e2edf7; box-shadow: 0 15px 35px rgba(0,0,0,0.15); z-index: 1000; }
            .dropdown a { display: flex; align-items: center; gap: 12px; padding: 12px 18px; text-decoration: none; color: #1e293b; font-size: 13px; font-weight: 500; }
            .dropdown a:hover { background: #f8fafc; }
            .main { max-width: 900px; margin: 32px auto 60px; padding: 0 20px; }
            .back-link { display: inline-flex; align-items: center; gap: 6px; color: #64748b; text-decoration: none; margin-bottom: 20px; }
            .back-link:hover { color: #E75A9B; }
            .card { background: white; border-radius: 24px; padding: 32px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); }
            h1 { font-size: 24px; color: #1d3156; margin-bottom: 8px; }
            .subtitle { color: #64748b; font-size: 14px; margin-bottom: 24px; padding-bottom: 16px; border-bottom: 1px solid #eef2f7; }
            .session-list { display: flex; flex-direction: column; gap: 12px; }
            .session-item { display: flex; justify-content: space-between; align-items: center; padding: 16px 20px; border: 1px solid #eef2f7; border-radius: 16px; transition: 0.2s; }
            .session-item:hover { background: #f8fafc; transform: translateX(5px); }
            .session-info h4 { font-size: 16px; font-weight: 700; color: #1d3156; }
            .session-info p { font-size: 12px; color: #64748b; margin-top: 4px; }
            .btn-select { background: linear-gradient(135deg, #E75A9B, #F28AB2); color: white; padding: 8px 20px; border-radius: 30px; text-decoration: none; font-size: 13px; font-weight: 600; }
            .empty-state { text-align: center; padding: 60px; background: white; border-radius: 24px; color: #94a3b8; }
            .empty-state i { font-size: 64px; margin-bottom: 16px; display: block; }
            .status-badge {
                display: inline-block;
                padding: 4px 12px;
                border-radius: 20px;
                font-size: 11px;
                font-weight: 700;
                white-space: nowrap;
            }
            .status-not-started {
                background: #f1f5f9;
                color: #64748b;
            }
            .status-draft {
                background: #fef3c7;
                color: #f59e0b;
            }
            .status-submitted {
                background: #d4edda;
                color: #28a745;
            }
            .btn-view {
                background: #e2e8f0;
                color: #1d3156;
                padding: 8px 20px;
                border-radius: 30px;
                text-decoration: none;
                font-size: 13px;
                font-weight: 600;
                transition: 0.2s;
            }
            .btn-view:hover {
                background: #cbd5e1;
            }
            .btn-edit {
                background: linear-gradient(135deg, #E75A9B, #F28AB2);
                color: white;
                padding: 8px 20px;
                border-radius: 30px;
                text-decoration: none;
                font-size: 13px;
                font-weight: 600;
            }
            .session-actions {
                display: flex;
                gap: 8px;
            }
            .info-text {
                font-size: 11px;
                color: #64748b;
                margin-top: 4px;
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
                    <a href="assignment_overview.php">My Assignments</a>
                    <a href="view_session_reports.php" class="active">My Reports</a>
                </div>
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
            </nav>
        </div>
    </header>

    <div class="main">
        <div class="card">
            <a href="tutor_dashboard.php" class="back-link">
            <i class="bi bi-arrow-left"></i> Back to Dashboard
        </a>
            <h1><i class="bi bi-journal-bookmark-fill"></i> Session Reports</h1>
            <p class="subtitle">View, edit, or create reports for completed sessions</p>
            
            <?php if ($completedBookings->num_rows === 0): ?>
                <div class="empty-state">
                    <i class="bi bi-calendar-x"></i>
                    <p>No completed sessions yet</p>
                    <p style="font-size: 13px;">Once you complete a session, you can create a report here.</p>
                </div>
            <?php else: ?>
                <div class="session-list">
                    <?php while ($booking = $completedBookings->fetch_assoc()): 
                        $status = $booking['report_status'] ?? 'not_started';
                        $statusText = [
                            'not_started' => 'Not Started',
                            'draft' => 'Draft',
                            'submitted' => 'Submitted',
                            'approved' => 'Approved'  // Add this
                        ][$status];

                        $statusClass = [
                            'not_started' => 'status-not-started',
                            'draft' => 'status-draft',
                            'submitted' => 'status-submitted',
                            'approved' => 'status-approved'  // Add this
                        ][$status];
                    ?>
                        <div class="session-item">
                            <div class="session-info">
                                <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 6px;">
                                    <h4><?= e($booking['language']) ?> with <?= e($booking['student_name']) ?></h4>
                                    <span class="status-badge <?= $statusClass ?>"><?= $statusText ?></span>
                                </div>
                                <p><i class="bi bi-calendar3"></i> <?= date('d M Y, g:i A', strtotime($booking['booking_date'] . ' ' . $booking['booking_time'])) ?></p>
                                <?php if ($status === 'submitted' && $booking['submitted_at']): ?>
                                    <div class="info-text">
                                        <i class="bi bi-check-circle"></i> Submitted on: <?= date('d M Y, g:i A', strtotime($booking['submitted_at'])) ?>
                                    </div>
                                <?php endif; ?>
                                <?php if ($status === 'draft'): ?>
                                    <div class="info-text">
                                        <i class="bi bi-pencil"></i> Draft saved - not visible to student
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="session-actions">
                                <?php if ($status === 'submitted'): ?>
                                    <a href="view_session_reports.php?booking_id=<?= $booking['id'] ?>" class="btn-view">
                                        <i class="bi bi-eye"></i> View Report
                                    </a>
                                <?php elseif ($status === 'draft'): ?>
                                    <a href="submit_session_report.php?booking_id=<?= $booking['id'] ?>" class="btn-edit">
                                        <i class="bi bi-pencil-square"></i> Edit Draft
                                    </a>
                                <?php else: ?>
                                    <a href="submit_session_report.php?booking_id=<?= $booking['id'] ?>" class="btn-select">
                                        Submit Report →
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endwhile; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <script>
        function toggleDropdown() { const d = document.getElementById('profileDropdown'); d.style.display = d.style.display === 'block' ? 'none' : 'block'; }
        window.addEventListener('click', function(e) { const btn = document.querySelector('.profile'); const dd = document.getElementById('profileDropdown'); if (btn && dd && !btn.contains(e.target) && !dd.contains(e.target)) { dd.style.display = 'none'; } });
    </script>
    </body>
    </html>
    <?php
    exit();
}

// Get booking and student info
$stmt = $conn->prepare("
    SELECT b.*, u.fullname as student_name, u.email as student_email, u.id as student_id
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

$stmt = $conn->prepare("
    SELECT student_confirmed, no_show_type, attendance_manually_set 
    FROM session_completion 
    WHERE booking_id = ?
");
$stmt->bind_param("i", $booking_id);
$stmt->execute();
$completion = $stmt->get_result()->fetch_assoc();

// If student reported they did NOT attend (student_no_show), prevent report submission
if ($completion && $completion['student_confirmed'] == 0 && $completion['no_show_type'] == 'student_no_show') {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Cannot Submit Report - Kyoshi Tutor</title>
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
        <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
        <style>
            * { margin: 0; padding: 0; box-sizing: border-box; }
            body { font-family: 'Poppins', sans-serif; background: url('../assets/img/background2.png') no-repeat center top; background-size: cover; min-height: 100vh; }
            body::before { content: ''; position: fixed; inset: 0; background: rgba(255,255,255,0.25); z-index: -1; }
            .container { width: min(1400px, 94%); margin: auto; }
            .main { max-width: 600px; margin: 80px auto; padding: 0 20px; }
            .card { background: white; border-radius: 24px; padding: 40px; text-align: center; box-shadow: 0 4px 20px rgba(0,0,0,0.08); }
            .card i { font-size: 64px; color: #f59e0b; margin-bottom: 20px; }
            .card h2 { color: #92400e; margin-bottom: 16px; }
            .card p { color: #64748b; margin-bottom: 24px; line-height: 1.6; }
            .btn { display: inline-block; padding: 12px 28px; background: linear-gradient(135deg, #E75A9B, #F28AB2); color: white; border-radius: 30px; text-decoration: none; font-weight: 600; }
            .btn:hover { transform: translateY(-2px); opacity: 0.95; }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="main">
                <div class="card">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                    <h2>Cannot Submit Session Report</h2>
                    <p>The student has reported that they did <strong>NOT attend</strong> this session.</p>
                    <p>No session report is needed for missed sessions. The tutor is still paid for their reserved time.</p>
                    <a href="view_session_reports.php" class="btn">Back to Reports</a>
                </div>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit();
}

// If tutor no-show (student reported tutor didn't attend), also prevent report
if ($completion && $completion['student_confirmed'] == 0 && $completion['no_show_type'] == 'tutor_no_show') {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Refund Processing - Kyoshi Tutor</title>
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
        <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
        <style>
            * { margin: 0; padding: 0; box-sizing: border-box; }
            body { font-family: 'Poppins', sans-serif; background: url('../assets/img/background2.png') no-repeat center top; background-size: cover; min-height: 100vh; }
            body::before { content: ''; position: fixed; inset: 0; background: rgba(255,255,255,0.25); z-index: -1; }
            .container { width: min(1400px, 94%); margin: auto; }
            .main { max-width: 600px; margin: 80px auto; padding: 0 20px; }
            .card { background: white; border-radius: 24px; padding: 40px; text-align: center; box-shadow: 0 4px 20px rgba(0,0,0,0.08); }
            .card i { font-size: 64px; color: #dc2626; margin-bottom: 20px; }
            .card h2 { color: #991b1b; margin-bottom: 16px; }
            .card p { color: #64748b; margin-bottom: 24px; line-height: 1.6; }
            .btn { display: inline-block; padding: 12px 28px; background: #1d3156; color: white; border-radius: 30px; text-decoration: none; font-weight: 600; }
            .btn:hover { transform: translateY(-2px); opacity: 0.95; }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="main">
                <div class="card">
                    <i class="bi bi-cash-stack"></i>
                    <h2>Refund Processing</h2>
                    <p>The student reported that you did <strong>NOT attend</strong> this session.</p>
                    <p>A refund is being processed. No session report is needed.</p>
                    <a href="view_session_reports.php" class="btn">Back to Reports</a>
                </div>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit();
}

// Get uploaded materials for this booking
$materialsStmt = $conn->prepare("
    SELECT title, file_name, material_url, is_url 
    FROM learning_materials 
    WHERE booking_id = ? 
    ORDER BY uploaded_at DESC
");
$materialsStmt->bind_param("i", $booking_id);
$materialsStmt->execute();
$uploadedMaterials = $materialsStmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Generate materials list string
$materialsList = '';
foreach ($uploadedMaterials as $m) {
    if ($m['is_url'] == 1) {
        $materialsList .= "- " . $m['title'] . " (Link: " . $m['material_url'] . ")\n";
    } else {
        $materialsList .= "- " . $m['title'] . " (" . $m['file_name'] . ")\n";
    }
}

// Get assignments for this booking
$assignmentsStmt = $conn->prepare("
    SELECT a.title, a.description, a.due_date, 
           (SELECT COUNT(*) FROM assignment_submissions WHERE assignment_id = a.id) as submission_count
    FROM assignments a
    WHERE a.booking_id = ? 
    ORDER BY a.created_at DESC
");
$assignmentsStmt->bind_param("i", $booking_id);
$assignmentsStmt->execute();
$assignments = $assignmentsStmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Generate homework list string
$homeworkList = '';
foreach ($assignments as $a) {
    $homeworkList .= "- " . $a['title'];
    if ($a['due_date']) {
        $homeworkList .= " (Due: " . date('d M Y', strtotime($a['due_date'])) . ")";
    }
    if ($a['submission_count'] > 0) {
        $homeworkList .= " - " . $a['submission_count'] . " submission(s) received";
    }
    $homeworkList .= "\n";
    if ($a['description']) {
        $homeworkList .= "  " . $a['description'] . "\n";
    }
}

// Check if report already exists
$stmt = $conn->prepare("SELECT * FROM session_reports WHERE booking_id = ? ORDER BY created_at DESC LIMIT 1");
$stmt->bind_param("i", $booking_id);
$stmt->execute();
$existingReport = $stmt->get_result()->fetch_assoc();

$isEdit = ($existingReport && ($existingReport['report_status'] !== 'submitted' || $existingReport['report_status'] === 'rejected'));

// Handle form submission
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $lesson_summary = trim($_POST['lesson_summary'] ?? '');
    $student_progress = trim($_POST['student_progress'] ?? '');
    $topics_covered = trim($_POST['topics_covered'] ?? '');
    $homework_given = trim($_POST['homework_given'] ?? '');
    $tutor_notes = trim($_POST['tutor_notes'] ?? '');
    $materials_used = trim($_POST['materials_used'] ?? '');
    $next_session_focus = trim($_POST['next_session_focus'] ?? '');
    $attendance_status = $_POST['attendance_status'] ?? 'attended';
    $action = $_POST['action'] ?? 'draft';
    
    // Validation - ONLY for SUBMITTED
    $errors = [];
    
    if ($action === 'submitted') {
        if (empty($lesson_summary)) {
            $errors[] = "Lesson summary is required.";
        }
        
        if (empty($student_progress)) {
            $errors[] = "Student progress is required.";
        }
        
        if (empty($topics_covered)) {
            $errors[] = "Topics covered is required.";
        }
    }
    
    // If there are validation errors, show them and DON'T proceed
    if (!empty($errors)) {
        $message = implode(" ", $errors);
        $messageType = "error";
    } 
    // Only proceed if no errors
else {
    // Check if this is a resubmit (updating a rejected report by ID)
    if ($resubmit && $existing_report_id > 0 && $resubmitData) {
        $stmt = $conn->prepare("
            UPDATE session_reports 
            SET lesson_summary = ?, student_progress = ?, topics_covered = ?, 
                homework_given = ?, tutor_notes = ?, materials_used = ?, 
                next_session_focus = ?, attendance_status = ?, 
                report_status = ?, updated_at = NOW()
            WHERE id = ? AND tutor_id = ? AND report_status = 'rejected'
        ");
        $stmt->bind_param("sssssssssii", 
            $lesson_summary, $student_progress, $topics_covered,
            $homework_given, $tutor_notes, $materials_used,
            $next_session_focus, $attendance_status, $action, 
            $existing_report_id, $userID
        );
        $stmt->execute();
        
        if ($action === 'submitted') {
            // Update submitted_at time
            $updateStmt = $conn->prepare("
                UPDATE session_reports 
                SET submitted_at = NOW() 
                WHERE id = ?
            ");
            $updateStmt->bind_param("i", $existing_report_id);
            $updateStmt->execute();
            
            // Send notification to student
            $notifMessage = "Your tutor has resubmitted the session report for your {$booking['language']} session on " . date('d M Y', strtotime($booking['booking_date']));
            insertNotification($conn, $booking['student_id'], "Report Resubmitted", $notifMessage, "session_report", "my_progress.php", true);
            
            $message = "Session report resubmitted successfully! Admin will review again.";
            $messageType = "success";
            $_SESSION['toast'] = ['message' => $message, 'type' => 'success'];
            header("Location: view_session_reports.php");
            exit();
        } else {
            $message = "Draft saved for rejected report.";
            $messageType = "success";
            $_SESSION['toast'] = ['message' => $message, 'type' => 'success'];
            header("Location: view_session_reports.php");
            exit();
        }
    }
    // Normal update for existing draft reports
    elseif ($existingReport && $isEdit && !$resubmit) {
        $stmt = $conn->prepare("
            UPDATE session_reports 
            SET lesson_summary = ?, student_progress = ?, topics_covered = ?, 
                homework_given = ?, tutor_notes = ?, materials_used = ?, 
                next_session_focus = ?, attendance_status = ?, 
                report_status = ?, updated_at = NOW()
            WHERE booking_id = ?
        ");
        $stmt->bind_param("sssssssssi", 
            $lesson_summary, $student_progress, $topics_covered,
            $homework_given, $tutor_notes, $materials_used,
            $next_session_focus, $attendance_status, $action, $booking_id
        );
        $stmt->execute();
        
        if ($action === 'submitted') {
            $updateStmt = $conn->prepare("
                UPDATE session_reports 
                SET submitted_at = NOW(), report_status = 'submitted' 
                WHERE booking_id = ?
            ");
            $updateStmt->bind_param("i", $booking_id);
            $updateStmt->execute();
            
            $notifMessage = "Your tutor has updated your progress for your {$booking['language']} session on " . date('d M Y', strtotime($booking['booking_date']));
            insertNotification($conn, $booking['student_id'], "Progress updated", $notifMessage, "session_report", "my_progress.php", true);
            
            $message = "Session report submitted successfully! Student has been notified.";
            $messageType = "success";
            $_SESSION['toast'] = ['message' => $message, 'type' => 'success'];
            header("Location: view_session_reports.php");
            exit();
        } else {
            $message = "Session report saved as draft.";
            $messageType = "success";
            $_SESSION['toast'] = ['message' => $message, 'type' => 'success'];
        }
    } 
    // New report (insert)
    else {
        $stmt = $conn->prepare("
            INSERT INTO session_reports 
            (booking_id, tutor_id, student_id, session_date, session_time, 
             lesson_summary, student_progress, topics_covered, homework_given, 
             tutor_notes, materials_used, next_session_focus, attendance_status, report_status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param("iiisssssssssss", 
            $booking_id, $userID, $booking['student_id'],
            $booking['booking_date'], $booking['booking_time'],
            $lesson_summary, $student_progress, $topics_covered,
            $homework_given, $tutor_notes, $materials_used,
            $next_session_focus, $attendance_status, $action
        );
        $stmt->execute();
        
        if ($action === 'submitted') {
            $updateStmt = $conn->prepare("
                UPDATE session_reports 
                SET submitted_at = NOW(), report_status = 'submitted' 
                WHERE booking_id = ?
            ");
            $updateStmt->bind_param("i", $booking_id);
            $updateStmt->execute();
            
            $notifMessage = "Your tutor has submitted a session report for your {$booking['language']} session on " . date('d M Y', strtotime($booking['booking_date']));
            insertNotification($conn, $booking['student_id'], "Session Report", $notifMessage, "session_report", "my_progress.php", true);
            
            $message = "Session report submitted successfully! Student has been notified.";
            $messageType = "success";
            $_SESSION['toast'] = ['message' => $message, 'type' => 'success'];
            header("Location: view_session_reports.php");
            exit();
        } else {
            $message = "Session report saved as draft.";
            $messageType = "success";
            $_SESSION['toast'] = ['message' => $message, 'type' => 'success'];
        }
    }
}
}


function e($v) { return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Session Report - Kyoshi Tutor</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Poppins', sans-serif; background: url('../assets/img/background2.png') no-repeat center top; background-size: cover; min-height: 100vh; }
        body::before { content: ''; position: fixed; inset: 0; background: rgba(255,255,255,0.25); z-index: -1; }
        .topbar { width: 100%; background: rgba(254,214,206,0.92); backdrop-filter: blur(12px); position: sticky; top: 0; z-index: 999; box-shadow: 0 2px 20px rgba(0,0,0,0.08); border-bottom: 1px solid rgba(255,255,255,0.3); }
        .container { width: min(1400px, 94%); margin: auto; }
        .nav { display: flex; justify-content: space-between; align-items: center; gap: 32px; min-height: 70px; }
        .brand { display: flex; align-items: center; gap: 10px; text-decoration: none; }
        .brand img { width: 42px; height: 42px; object-fit: contain; }
        .brand strong { display: block; color: #1d3156; font-size: 20px; }
        .brand span { color: #496894; font-size: 11px; font-weight: 600; }
        .nav-links { display: flex; gap: 28px; align-items: center; flex-wrap: wrap; }
        .nav-links a { text-decoration: none; color: #1d3156; font-size: 14px; font-weight: 600; transition: 0.25s; padding: 6px 0; }
        .nav-links a:hover, .nav-links a.active { color: #496894; }
        .profile { display: flex; align-items: center; gap: 8px; background: rgba(255,255,255,0.1); border: 1px solid rgba(255,255,255,0.2); padding: 6px 14px 6px 8px; border-radius: 40px; cursor: pointer; }
        .profile img { width: 36px; height: 36px; border-radius: 50%; object-fit: cover; }
        .profile span { font-size: 13px; font-weight: 500; color: #1d3156; }
        .dropdown { position: absolute; top: calc(100% + 10px); right: 0; width: 220px; background: white; border-radius: 16px; overflow: hidden; display: none; border: 1px solid #e2edf7; box-shadow: 0 15px 35px rgba(0,0,0,0.15); z-index: 1000; }
        .dropdown a { display: flex; align-items: center; gap: 12px; padding: 12px 18px; text-decoration: none; color: #1e293b; font-size: 13px; font-weight: 500; }
        .dropdown a:hover { background: #f8fafc; }
        .main { max-width: 900px; margin: 32px auto 60px; padding: 0 20px; }
        .btn-back { display: inline-flex; align-items: center; gap: 6px; color: #64748b; text-decoration: none; margin-bottom: 20px; font-size: 13px; }
        .btn-back:hover { color: #E75A9B; }
        .card { background: white; border-radius: 24px; padding: 32px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); }
        h1 { font-size: 24px; color: #1d3156; margin-bottom: 8px; }
        .subtitle { color: #64748b; font-size: 14px; margin-bottom: 24px; padding-bottom: 16px; border-bottom: 1px solid #eef2f7; }
        .alert { padding: 12px 16px; border-radius: 12px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; }
        .alert-success { background: #d4edda; color: #155724; border-left: 4px solid #28a745; }
        .alert-error { background: #f8d7da; color: #721c24; border-left: 4px solid #dc2626; }
        .form-group { margin-bottom: 20px; }
        label { display: block; font-size: 13px; font-weight: 600; margin-bottom: 6px; color: #1d3156; }
        .form-control, select, textarea { width: 100%; padding: 12px 14px; border: 1px solid #cbd5e1; border-radius: 12px; font-family: 'Poppins', sans-serif; font-size: 14px; }
        .form-control:focus, select:focus, textarea:focus { outline: none; border-color: #E75A9B; }
        textarea.form-control { resize: vertical; min-height: 100px; }
        .info-bar { background: #f0f9ff; border-radius: 12px; padding: 12px 16px; margin-bottom: 20px; display: flex; align-items: center; gap: 12px; }
        .info-bar i { font-size: 24px; color: #0284c7; }
        .info-bar strong { display: block; font-size: 14px; color: #1d3156; }
        .info-bar span { font-size: 12px; color: #64748b; }
        .btn-submit, .btn-draft { padding: 12px 24px; border-radius: 30px; font-size: 14px; font-weight: 700; cursor: pointer; transition: 0.2s; border: none; }
        .btn-submit { background: linear-gradient(135deg, #E75A9B, #F28AB2); color: white; }
        .btn-submit:hover { transform: translateY(-2px); opacity: 0.95; }
        .btn-draft { background: #e2e8f0; color: #1d3156; margin-right: 12px; }
        .btn-draft:hover { transform: translateY(-2px); background: #cbd5e1; }
        .status-badge { display: inline-block; padding: 4px 12px; border-radius: 20px; font-size: 11px; font-weight: 700; }
        .status-draft { background: #fef3c7; color: #f59e0b; }
        .status-submitted { background: #d4edda; color: #28a745; }
        .preview-box { background: #f8fafc; border-radius: 12px; padding: 12px; margin-bottom: 8px; font-size: 13px; border-left: 3px solid #E75A9B; }
        .preview-box strong { color: #1d3156; display: block; margin-bottom: 8px; }
        .preview-box ul { margin: 0; padding-left: 20px; }
        .preview-box li { margin: 4px 0; color: #475569; }
        .preview-box li a { color: #E75A9B; text-decoration: none; }
        .preview-box li a:hover { text-decoration: underline; }
        .required { color: #dc2626; margin-left: 4px; }
        .toast {
            position: fixed;
            bottom: 30px;
            left: 50%;
            transform: translateX(-50%);
            background: #8E3F70;
            color: white;
            padding: 12px 24px;
            border-radius: 30px;
            font-size: 14px;
            font-weight: 600;
            z-index: 9999;
            opacity: 0;
            transition: opacity 0.3s ease;
            pointer-events: none;
        }
        .toast.show { opacity: 1; }
        .toast.error { background: #dc2626; }
        .toast.success { background: #28a745; }
        @media (max-width: 768px) { .card { padding: 20px; } .btn-submit, .btn-draft { width: 100%; margin-bottom: 10px; margin-right: 0; } }
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
                <a href="assignment_overview.php">My Assignments</a>
                <a href="view_session_reports.php" class="active">My Reports</a>
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
    <div class="card">
        <a href="tutor_booking_detail.php?id=<?= $booking_id ?>" class="btn-back">
            <i class="bi bi-arrow-left"></i> Back to Booking
        </a>
        
        <h1><i class="bi bi-journal-bookmark-fill"></i> Session Report</h1>
        <p class="subtitle">Document lesson progress and provide feedback</p>

<div class="info-bar">
    <i class="bi bi-person-circle"></i>
    <div>
        <strong><?= e($booking['student_name']) ?></strong>
        <span><?= e($booking['language']) ?> · <?= date('d M Y, g:i A', strtotime($booking['booking_date'] . ' ' . $booking['booking_time'])) ?></span>
        <?php if (!empty($booking['focus'])): ?>
            <div style="margin-top: 6px;">
                <span style="background: rgba(231,90,155,0.1); padding: 3px 10px; border-radius: 20px; font-size: 11px;">
                    Focus: <?= e($booking['focus']) ?>
                </span>
            </div>
        <?php endif; ?>
        <?php if (!empty($booking['proficiency_level'])): 
            $levelLabels = [
                'beginner' => 'Beginner',
                'intermediate' => 'Intermediate', 
                'advanced' => 'Advanced',
                'master' => 'Master'
            ];
            $levelDisplay = $levelLabels[$booking['proficiency_level']] ?? ucfirst($booking['proficiency_level']);
        ?>
            <div style="margin-top: 6px;">
                <span style="background: rgba(167,123,232,0.1); padding: 3px 10px; border-radius: 20px; font-size: 11px;">
                    Proficiency Level: <?= e($levelDisplay) ?>
                </span>
            </div>
        <?php endif; ?>
    </div>
    <?php if ($existingReport && $existingReport['report_status'] === 'submitted'): ?>
        <div style="margin-left: auto;">
            <span class="status-badge status-submitted">Submitted</span>
        </div>
    <?php elseif ($existingReport && $existingReport['report_status'] === 'draft'): ?>
        <div style="margin-left: auto;">
            <span class="status-badge status-draft">Draft</span>
        </div>
    <?php endif; ?>
</div>
<?php if ($resubmit && $resubmitData && !empty($resubmitData['admin_notes'])): ?>
    <div class="alert" style="background: #fef3c7; border-left: 4px solid #f59e0b; margin-bottom: 20px;">
        <i class="bi bi-arrow-repeat"></i>
        <strong>Resubmitting Rejected Report</strong>
        <div style="margin-top: 10px; padding: 10px; background: #fee2e2; border-radius: 8px;">
            <i class="bi bi-chat"></i> <strong>Admin's Feedback:</strong><br>
            <?= nl2br(e($resubmitData['admin_notes'])) ?>
        </div>
        <p style="margin-top: 10px; font-size: 13px;">Please review the feedback above and make necessary changes before resubmitting.</p>
    </div>
<?php endif; ?>

        <?php if ($message): ?>
            <div class="alert alert-<?= $messageType ?>">
                <i class="bi bi-<?= $messageType === 'success' ? 'check-circle' : 'exclamation-circle' ?>"></i>
                <?= e($message) ?>
            </div>
        <?php endif; ?>

        <?php if ($existingReport && $existingReport['report_status'] === 'submitted' && !$isEdit): ?>
            <div class="alert alert-success">
                <i class="bi bi-info-circle"></i>
                This session report has already been submitted. You can view it below.
            </div>
            <div style="background: #f8fafc; border-radius: 16px; padding: 20px; margin-top: 20px;">
                <h3>Submitted Report</h3>
                <p><strong>Lesson Summary:</strong><br><?= nl2br(e($existingReport['lesson_summary'])) ?></p>
                <?php if ($existingReport['student_progress']): ?>
                    <p><strong>Student Progress:</strong><br><?= nl2br(e($existingReport['student_progress'])) ?></p>
                <?php endif; ?>
                <?php if ($existingReport['homework_given']): ?>
                    <p><strong>Homework Given:</strong><br><?= nl2br(e($existingReport['homework_given'])) ?></p>
                <?php endif; ?>
                <p><strong>Submitted on:</strong> <?= date('d M Y, g:i A', strtotime($existingReport['submitted_at'])) ?></p>
            </div>
        <?php else: ?>
            <?php 
            // Determine which data to use for pre-filling
            $prefillData = ($resubmit && $resubmitData) ? $resubmitData : $existingReport;
            ?>
            <form method="POST" action="" id="reportForm">
                <input type="hidden" name="action" id="formAction" value="draft">
                
                <div class="form-group">
                    <label><i class="bi bi-chat-text"></i> Lesson Summary <span class="required">*</span></label>
                    <textarea name="lesson_summary" class="form-control" rows="4" 
                        placeholder="What was covered in this session? What were the key learning points?"><?= e($prefillData['lesson_summary'] ?? '') ?></textarea>
                </div>

                <div class="form-group">
                    <label><i class="bi bi-graph-up"></i> Student Progress <span class="required">*</span></label>
                    <textarea name="student_progress" class="form-control" rows="3" 
                        placeholder="How is the student progressing? What improvements have you noticed?"><?= e($prefillData['student_progress'] ?? '') ?></textarea>
                </div>

                <div class="form-group">
                    <label><i class="bi bi-book"></i> Topics Covered <span class="required">*</span></label>
                    <textarea name="topics_covered" class="form-control" rows="3" 
                        placeholder="List the specific topics or skills covered in this session"><?= e($prefillData['topics_covered'] ?? '') ?></textarea>
                </div>

                <div class="form-group">
                    <label><i class="bi bi-file-earmark-text"></i> Tutor's Notes (Private)</label>
                    <textarea name="tutor_notes" class="form-control" rows="3" 
                        placeholder="Private notes for your reference (student cannot see this)"><?= e($prefillData['tutor_notes'] ?? '') ?></textarea>
                    <small style="font-size: 11px; color: #64748b;">These notes are only visible to you.</small>
                </div>

                <div class="form-group">
                    <label><i class="bi bi-box"></i> Materials Used</label>
                    <?php if (!empty($uploadedMaterials)): ?>
                        <div class="preview-box" style="background: #f8fafc; border-left-color: #E75A9B;">
                            <strong><i class="bi bi-link"></i> Materials Provided:</strong>
                            <ul style="margin-top: 8px;">
                                <?php foreach ($uploadedMaterials as $m): ?>
                                    <li style="margin-bottom: 6px;">
                                        <?php if ($m['is_url'] == 1): ?>
                                            <i class="bi bi-link-45deg" style="color: #E75A9B;"></i>
                                            <strong><?= e($m['title']) ?></strong>
                                            <div style="font-size: 11px; color: #64748b; margin-left: 20px;">
                                                Link: <a href="<?= e($m['material_url']) ?>" target="_blank" style="color: #E75A9B;"><?= e($m['material_url']) ?></a>
                                            </div>
                                        <?php else: ?>
                                            <i class="bi bi-file-earmark" style="color: #E75A9B;"></i>
                                            <strong><?= e($m['title']) ?></strong>
                                            <div style="font-size: 11px; color: #64748b; margin-left: 20px;">
                                                File: <?= e($m['file_name']) ?>
                                            </div>
                                        <?php endif; ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                        <input type="hidden" name="materials_used" value="<?= e($materialsList) ?>">
                        <small style="font-size: 11px; color: #64748b;">Materials are automatically recorded from your uploads.</small>
                    <?php else: ?>
                        <div class="preview-box" style="background: #f8fafc; border-left-color: #cbd5e1;">
                            <p style="margin: 0; color: #64748b;">No materials were uploaded for this session.</p>
                        </div>
                        <input type="hidden" name="materials_used" value="No materials uploaded">
                    <?php endif; ?>
                </div>

                <div class="form-group">
                    <label><i class="bi bi-pencil-square"></i> Homework Given</label>
                    <?php if (!empty($assignments)): ?>
                        <div class="preview-box" style="background: #fefce8; border-left-color: #f59e0b;">
                            <strong><i class="bi bi-journal-check"></i> Assignments Created:</strong>
                            <ul style="margin-top: 8px;">
                                <?php foreach ($assignments as $a): ?>
                                    <li style="margin-bottom: 8px;">
                                        <i class="bi bi-file-earmark"></i> <strong><?= e($a['title']) ?></strong>
                                        <?php if ($a['due_date']): ?>
                                            <span style="font-size: 11px; color: #64748b;">(Due: <?= date('d M Y', strtotime($a['due_date'])) ?>)</span>
                                        <?php endif; ?>
                                        <?php if ($a['submission_count'] > 0): ?>
                                            <span style="font-size: 11px; color: #28a745;"> - <?= $a['submission_count'] ?> submission(s)</span>
                                        <?php endif; ?>
                                        <?php if ($a['description']): ?>
                                            <div style="font-size: 11px; color: #64748b; margin-top: 4px; margin-left: 20px;"><?= nl2br(e($a['description'])) ?></div>
                                        <?php endif; ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                        <input type="hidden" name="homework_given" value="<?= e($homeworkList) ?>">
                        <small style="font-size: 11px; color: #64748b;">Homework is automatically recorded from your assignments.</small>
                    <?php else: ?>
                        <div class="preview-box" style="background: #f8fafc; border-left-color: #cbd5e1;">
                            <p style="margin: 0; color: #64748b;">No homework was assigned for this session.</p>
                        </div>
                        <input type="hidden" name="homework_given" value="No homework assigned">
                    <?php endif; ?>
                </div>

                <div class="form-group">
                    <label><i class="bi bi-calendar-check"></i> Next Session Focus</label>
                    <textarea name="next_session_focus" class="form-control" rows="2" 
                        placeholder="What should be covered in the next session?"><?= e($prefillData['next_session_focus'] ?? '') ?></textarea>
                </div>

                <div class="form-group">
                    <label><i class="bi bi-person-check"></i> Attendance</label>
                    <select name="attendance_status" class="form-control">
                        <option value="attended" <?= (isset($prefillData) && $prefillData['attendance_status'] === 'attended') ? 'selected' : '' ?>>Attended</option>
                        <option value="late" <?= (isset($prefillData) && $prefillData['attendance_status'] === 'late') ? 'selected' : '' ?>>Late</option>
                        <option value="absent" <?= (isset($prefillData) && $prefillData['attendance_status'] === 'absent') ? 'selected' : '' ?>>Absent</option>
                    </select>
                </div>

                <div style="display: flex; justify-content: center; gap: 16px; margin-top: 32px; flex-wrap: wrap;">
                    <button type="submit" class="btn-draft" id="draftBtn" onclick="document.getElementById('formAction').value='draft'">
                        <i class="bi bi-save"></i> Save as Draft
                    </button>
                    <button type="submit" class="btn-submit" id="submitBtn" onclick="document.getElementById('formAction').value='submitted'">
                        <i class="bi bi-send"></i> Submit Report
                    </button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>

<div id="customToast" class="toast"></div>

<script>
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

function showToast(message, type) {
    let toast = document.getElementById('customToast');
    toast.textContent = message;
    toast.className = 'toast ' + type;
    toast.classList.add('show');
    setTimeout(function() {
        toast.classList.remove('show');
    }, 3000);
}

document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('reportForm');
    if (!form) return;

    form.addEventListener('submit', function(e) {
        const action = document.getElementById('formAction').value;

        // Only validate for SUBMIT, not for DRAFT
        if (action !== 'submitted') return;

        const lessonSummary = document.querySelector('textarea[name="lesson_summary"]')?.value.trim() || '';
        const studentProgress = document.querySelector('textarea[name="student_progress"]')?.value.trim() || '';
        const topicsCovered = document.querySelector('textarea[name="topics_covered"]')?.value.trim() || '';

        const errors = [];
        if (!lessonSummary) errors.push('Lesson summary');
        if (!studentProgress) errors.push('Student progress');
        if (!topicsCovered) errors.push('Topics covered');

        if (errors.length > 0) {
            e.preventDefault();
            showToast('Please fill in: ' + errors.join(', '), 'error');
            return false;
        }

        if (!confirm('Submit this report? The student will be notified and can view it. You cannot edit after submission.')) {
            e.preventDefault();
            return false;
        }
    });
});
</script>

</body>
</html>