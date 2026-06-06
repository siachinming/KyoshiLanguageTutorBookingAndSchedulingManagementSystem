<?php
session_start();
include 'config.php';
include 'send_tutor_email.php';
$assetBase = '../assets/img';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

$adminID = $_SESSION['user_id'];

// Get admin info
$stmt = $conn->prepare("SELECT * FROM users WHERE id = ? AND role = 'admin'");
$stmt->bind_param("i", $adminID);
$stmt->execute();
$admin = $stmt->get_result()->fetch_assoc();

if (!$admin) {
    header("Location: login.php");
    exit();
}

$displayName = $admin['fullname'];
$profilePic = !empty($admin['profile_pic'])
    ? '../uploads/profiles/' . $admin['profile_pic']
    : $assetBase . '/profile-admin.png';

// Get counts for sidebar
$totalTutors = $conn->query("SELECT COUNT(*) as count FROM users WHERE role = 'tutor'")->fetch_assoc()['count'];
$totalStudents = $conn->query("SELECT COUNT(*) as count FROM users WHERE role = 'student'")->fetch_assoc()['count'];
$pendingPayments = $conn->query("SELECT COUNT(*) as count FROM payments WHERE status = 'pending'")->fetch_assoc()['count'];
$pendingDisputes = $conn->query("SELECT COUNT(*) as count FROM disputes WHERE status = 'pending'")->fetch_assoc()['count'];
$pendingPayouts = $conn->query("SELECT COUNT(*) as count FROM payout_requests WHERE status = 'pending'")->fetch_assoc()['count'] ?? 0;
$totalBookings = $conn->query("SELECT COUNT(*) as count FROM bookings")->fetch_assoc()['count'];
// Get search and filter parameters
$search = $_GET['search'] ?? '';
$language_filter = $_GET['language'] ?? '';

$sql = "
    SELECT u.*, tp.rate, tp.experience, tp.bio,
           GROUP_CONCAT(DISTINCT CONCAT(tl.language, ' (', tl.proficiency_level, ')') SEPARATOR ', ') as languages
    FROM users u 
    LEFT JOIN tutor_profiles tp ON u.id = tp.user_id 
    LEFT JOIN tutor_languages tl ON u.id = tl.user_id
    WHERE u.role = 'tutor' AND u.status = 'pending'
";

if (!empty($search)) {
    $search_like = $conn->real_escape_string($search);
    $sql .= " AND (u.fullname LIKE '%$search_like%' OR u.email LIKE '%$search_like%')";
}

if (!empty($language_filter)) {
    $sql .= " AND tl.language = '" . $conn->real_escape_string($language_filter) . "'";
}

$sql .= " GROUP BY u.id ORDER BY u.created_at DESC";

$pendingTutors = $conn->query($sql);
$pendingCount = $pendingTutors->num_rows;

// Get all languages for filter dropdown
$languages = $conn->query("SELECT DISTINCT language FROM tutor_languages ORDER BY language");
if (isset($_POST['approve_with_qualification'])) {
    $tutor_id = intval($_POST['tutor_id']);
    $qualifications = $_POST['qualifications'] ?? [];
    
    // Get tutor email and name first
    $tutorInfo = $conn->query("SELECT email, fullname FROM users WHERE id = $tutor_id")->fetch_assoc();
    
    $conn->query("UPDATE users SET status = 'approved' WHERE id = $tutor_id");
    
    $qualificationList = [];
    // Insert each qualification into tutor_qualifications table
    if (!empty($qualifications)) {
        foreach ($qualifications as $qualification) {
            $qual = trim($qualification);
            if (!empty($qual)) {
                $stmt = $conn->prepare("INSERT INTO tutor_qualifications (tutor_id, qualification_name, created_at) VALUES (?, ?, NOW())");
                $stmt->bind_param("is", $tutor_id, $qual);
                $stmt->execute();
                $qualificationList[] = $qual;
            }
        }
    }
    
    $conn->query("UPDATE tutor_certificates SET status = 'approved' WHERE tutor_id = $tutor_id AND status = 'pending'");
    
    // Send email notification
    $emailSent = sendTutorNotificationEmail($tutorInfo['email'], $tutorInfo['fullname'], 'approved', null, $qualificationList);
    
    if ($emailSent) {
        $_SESSION['success_message'] = "Tutor approved and email notification sent!";
    } else {
        $_SESSION['warning_message'] = "Tutor approved but email notification failed to send.";
    }
    
    header("Location: admin_verify_tutors.php?msg=approved");
    exit();
}

if (isset($_POST['reject'])) {
    $tutor_id = intval($_POST['tutor_id']);
    $rejection_reason = $_POST['rejection_reason'] ?? '';
    
    // Get tutor email and name first
    $tutorInfo = $conn->query("SELECT email, fullname FROM users WHERE id = $tutor_id")->fetch_assoc();
    
    $conn->query("UPDATE users SET status = 'rejected' WHERE id = $tutor_id");
    $conn->query("UPDATE tutor_certificates SET status = 'rejected' WHERE tutor_id = $tutor_id AND status = 'pending'");
    
    // Send email notification with rejection reason
    $emailSent = sendTutorNotificationEmail($tutorInfo['email'], $tutorInfo['fullname'], 'rejected', $rejection_reason);
    
    if ($emailSent) {
        $_SESSION['success_message'] = "Tutor rejected and email notification sent!";
    } else {
        $_SESSION['warning_message'] = "Tutor rejected but email notification failed to send.";
    }
    
    header("Location: admin_verify_tutors.php?msg=rejected");
    exit();
}

$message = '';
if (isset($_GET['msg'])) {
    if ($_GET['msg'] == 'approved') $message = 'Tutor approved successfully! Certificates verified and qualifications added.';
    if ($_GET['msg'] == 'rejected') $message = 'Tutor rejected.';
}


function e($value) {
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kyoshi | Verify Tutors</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: 'Montserrat', sans-serif;
            background: url('../assets/img/background3.jpg') no-repeat center top;
            background-size: cover;
            min-height: 100vh;
            color: #1E1B2E;
            overflow-x: hidden;
        }

        body::before {
            content: "";
            position: fixed;
            inset: 0;
            background: radial-gradient(circle at 7% 10%, rgba(231,90,155,.32), transparent 24%),
                        radial-gradient(circle at 90% 8%, rgba(255,195,216,.42), transparent 26%);
            z-index: -1;
        }

        /* ========== SIDEBAR STYLES ========== */
        .sidebar {
            position: fixed;
            left: 0;
            top: 0;
            width: 230px;
            height: 100vh;
            background: #272754;
            color: #E8E4F0;
            overflow-y: hidden;
            z-index: 1000;
            transition: transform 0.3s ease;
            transform: translateX(0);
            display: flex;
            flex-direction: column;
        }
        
        .sidebar.closed {
            transform: translateX(-100%);
        }
        
        .sidebar-header {
            padding: 28px 20px;
            flex-shrink: 0;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        
        .brand-wrapper {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .brand-icon {
            width: 60px;
            height: 60px;
            object-fit: contain;
        }

        .brand-title {
            display: flex;
            flex-direction: column;
        }

        .brand-title h1 {
            font-size: 1.4rem;
            font-weight: 700;
            background: linear-gradient(135deg, #ffffff, #B26EA7);
            background-clip: text;
            -webkit-background-clip: text;
            color: transparent;
            margin: 0;
            line-height: 1.2;
        }

        .admin-space-text {
            font-size: 0.6rem;
            color: #e7c7f7;
            letter-spacing: 0.5px;
            margin-top: 2px;
        }
        
        .nav-menu {
            padding: 16px 0;
            flex: 1;
            overflow-y: auto;
            min-height: 0;
        }
        
        .nav-menu::-webkit-scrollbar {
            width: 3px;
        }
        
        .nav-menu::-webkit-scrollbar-track {
            background: rgba(255,255,255,0.1);
        }
        
        .nav-menu::-webkit-scrollbar-thumb {
            background: #B26EA7;
            border-radius: 3px;
        }
        
        .nav-section {
            margin-bottom: 8px;
        }
        
        .nav-section-label {
            padding: 12px 20px 6px 20px;
            font-size: 0.65rem;
            font-weight: 600;
            color: #B26EA7;
            text-transform: uppercase;
            letter-spacing: 1px;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        
        .nav-section-label i {
            font-size: 0.7rem;
            color: #B26EA7;
        }
        
        .nav-item {
            padding: 10px 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            color: #D4CFE8;
            text-decoration: none;
            transition: all 0.2s;
            border-left: 3px solid transparent;
            font-size: 0.85rem;
        }
        
        .nav-item:hover {
            background: rgba(255,255,255,0.08);
            color: white;
        }
        
        .nav-item.active {
            background: rgba(255,255,255,0.1);
            border-left-color: #B26EA7;
            color: white;
        }
        
        .nav-item i {
            width: 20px;
            font-size: 1.1rem;
        }
        
        .nav-badge {
            margin-left: auto;
            font-size: 0.65rem;
            background: rgba(178, 110, 167, 0.25);
            padding: 2px 8px;
            border-radius: 30px;
            color: #D4CFE8;
            font-weight: 600;
        }
        
        .nav-badge.pending {
            background: rgba(245, 158, 11, 0.25);
            color: #F59E0B;
        }
        
        .nav-badge.dispute {
            background: rgba(220, 38, 38, 0.25);
            color: #FFA3A3;
        }
        
        .sidebar-footer {
            padding: 20px;
            border-top: 1px solid rgba(255,255,255,0.15);
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }
        
        .admin-info {
            display: flex;
            align-items: center;
            gap: 12px;
            flex: 1;
            overflow: hidden;
        }
        
        .footer-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid rgba(255,255,255,0.2);
        }
        
        .admin-details {
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        
        .admin-name {
            font-size: 0.8rem;
            font-weight: 600;
            color: white;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .logout-icon {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 36px;
            height: 36px;
            background: rgba(220, 38, 38, 0.15);
            border-radius: 10px;
            color: #FFA3A3;
            text-decoration: none;
            transition: all 0.2s;
            flex-shrink: 0;
        }
        
        .logout-icon:hover {
            background: rgba(220, 38, 38, 0.4);
            color: white;
            transform: scale(1.05);
        }
        
        .logout-icon i {
            font-size: 1.2rem;
        }
        
        /* ========== MAIN CONTENT STYLES ========== */
        .main-content {
            margin-left: 230px;
            padding: 20px 24px;
            transition: margin-left 0.3s ease;
            height: 100vh;
            overflow-y: auto;
        }
        
        .main-content::-webkit-scrollbar {
            width: 8px;
        }
        
        .main-content::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 10px;
        }
        
        .main-content::-webkit-scrollbar-thumb {
            background: #E75A9B;
            border-radius: 10px;
        }
        
        .main-content::-webkit-scrollbar-thumb:hover {
            background: #C94F86;
        }
        
        .top-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
        }
        
        .page-title h1 {
            font-size: 1.4rem;
            font-weight: 700;
            color: #302E63;
        }
        
        .menu-toggle {
            background: #272754;
            color: white;
            border: none;
            padding: 8px 12px;
            border-radius: 10px;
            cursor: pointer;
            display: none;
        }
        
        .admin-profile {
            display: flex;
            align-items: center;
            gap: 10px;
            background: white;
            padding: 6px 14px 6px 10px;
            border-radius: 50px;
            cursor: pointer;
            border: 1px solid #E4DCF0;
        }
        
        .admin-profile img { 
            width: 36px; 
            height: 36px; 
            border-radius: 50%; 
            object-fit: cover; 
        }
        
        .admin-profile span { 
            font-weight: 600; 
            font-size: 0.8rem; 
            color: #302E63; 
        }
        
        .relative { 
            position: relative; 
        }
        
        .dropdown {
            position: absolute;
            top: calc(100% + 10px);
            right: 0;
            width: 180px;
            background: white;
            border-radius: 12px;
            display: none;
            border: 1px solid #E4DCF0;
            box-shadow: 0 15px 35px rgba(0,0,0,0.15);
            z-index: 1000;
        }
        
        .dropdown a {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 16px;
            text-decoration: none;
            color: #1E1B2E;
            font-size: 12px;
        }
        
        .dropdown a:hover { 
            background: #F4F0F8; 
        }
        
        .dropdown hr { 
            margin: 0; 
            border-color: #E4DCF0; 
        }
        
        .sidebar-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(126, 96, 223, 0.5);
            z-index: 999;
            display: none;
        }
        
        .sidebar-overlay.active { 
            display: block; 
        }
        
        /* ========== FILTER BAR ========== */
        .filter-bar {
            background: white;
            border-radius: 16px;
            padding: 16px 20px;
            margin-bottom: 24px;
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            align-items: center;
        }
        
        .search-box {
            flex: 2;
            min-width: 250px;
            display: flex;
            align-items: center;
            gap: 10px;
            background: #f8f9fa;
            padding: 10px 16px;
            border-radius: 40px;
            border: 1px solid #e2e8f0;
        }
        
        .search-box i { 
            color: #94a3b8; 
        }
        
        .search-box input {
            border: none;
            background: transparent;
            flex: 1;
            outline: none;
            font-size: 13px;
        }
        
        .filter-select {
            padding: 10px 16px;
            border-radius: 40px;
            border: 1px solid #e2e8f0;
            background: #f8f9fa;
            font-size: 13px;
            outline: none;
            cursor: pointer;
            min-width: 150px;
        }
        
        .filter-btn {
            background: #E75A9B;
            color: white;
            border: none;
            padding: 10px 24px;
            border-radius: 40px;
            cursor: pointer;
            font-weight: 600;
            font-size: 13px;
        }
        
        .reset-btn {
            background: #64748b;
        }
        
        /* ========== TABLE STYLES ========== */
        .tutors-table-container {
            background: white;
            border-radius: 20px;
            overflow-x: auto;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 800px;
        }
        
        th {
            text-align: left;
            padding: 14px 16px;
            background: #f8f9fa;
            color: #475569;
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: uppercase;
            border-bottom: 1px solid #e2e8f0;
        }
        
        td {
            padding: 14px 16px;
            border-bottom: 1px solid #f1f5f9;
            font-size: 0.8rem;
            vertical-align: middle;
        }
        
        tr:hover { 
            background: #fef9f5; 
        }
        
        .has-cert {
            background: #E0F2FE;
            color: #0284C7;
            padding: 4px 8px;
            border-radius: 20px;
            font-size: 0.65rem;
            display: inline-block;
        }
        
        .no-cert {
            color: #94a3b8;
            font-size: 0.7rem;
        }
        
        .btn-view {
            background: #E75A9B;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 20px;
            cursor: pointer;
            font-size: 0.7rem;
            font-weight: 600;
        }
        
        .message {
            background: #d4edda;
            color: #155724;
            padding: 12px 20px;
            border-radius: 12px;
            margin-bottom: 20px;
        }
        
        /* ========== MODAL STYLES ========== */
        .step-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.8);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }
        
        .modal-content {
            background: white;
            border-radius: 28px;
            max-width: 1200px;
            width: 95%;
            max-height: 90vh;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            position: relative;
            z-index: 10000;
        }
        
        .modal-header {
            padding: 20px 28px;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: white;
        }
        
        .modal-header h3 {
            font-size: 1.3rem;
            font-weight: 800;
            color: #302E63;
        }
        
        .close-modal {
            background: none;
            border: none;
            font-size: 28px;
            cursor: pointer;
            color: #94a3b8;
        }
        
        .step-indicator {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px 28px;
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .step-nav-btn {
            background: #E75A9B;
            color: white;
            border: none;
            width: 36px;
            height: 36px;
            border-radius: 50%;
            cursor: pointer;
            font-size: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
        }
        
        .step-nav-btn:hover {
            background: #C94F86;
            transform: scale(1.05);
        }
        
        .step-nav-btn:disabled {
            background: #cbd5e1;
            cursor: not-allowed;
            transform: none;
        }
        
        .steps-wrapper {
            display: flex;
            gap: 30px;
        }
        
        .step {
            display: flex;
            align-items: center;
            gap: 10px;
            color: #94a3b8;
            font-size: 13px;
            font-weight: 600;
        }
        
        .step .step-number {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            background: #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            transition: all 0.2s;
        }
        
        .step.active {
            color: #E75A9B;
        }
        
        .step.active .step-number {
            background: #E75A9B !important;
            color: white;
        }
        
        .step.completed {
            color: #28a745;
        }
        
        .step.completed .step-number {
            background: #28a745 !important;
            color: white;
        }
        
        .step-body {
            padding: 28px;
            overflow-y: auto;
            flex: 1;
        }
        
        .profile-split {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
        }
        
        .profile-left {
            text-align: center;
            background: #f8fafc;
            border-radius: 20px;
            padding: 30px;
        }
        
        .profile-left img {
            width: 180px;
            height: 180px;
            border-radius: 50%;
            object-fit: cover;
            border: 4px solid #E75A9B;
            cursor: pointer;
        }
        
        .profile-right {
            background: #f8fafc;
            border-radius: 20px;
            padding: 30px;
        }
        
        .info-row {
            display: flex;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .info-label {
            width: 130px;
            font-weight: 700;
            color: #64748b;
            font-size: 0.75rem;
        }
        
        .info-value {
            flex: 1;
            font-weight: 600;
            color: #1e293b;
            font-size: 0.85rem;
        }
        
        .lang-tag {
            background: #e0f2fe;
            color: #0284c7;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            display: inline-block;
            margin: 2px;
        }
        
        .step-buttons {
            display: flex;
            justify-content: space-between;
            margin-top: 30px;
            gap: 15px;
        }
        
        .btn-next {
            background: #E75A9B;
            color: white;
            border: none;
            padding: 12px 30px;
            border-radius: 40px;
            cursor: pointer;
            font-weight: 700;
        }
        
        .btn-reject-step {
            background: #dc3545;
            color: white;
            border: none;
            padding: 12px 30px;
            border-radius: 40px;
            cursor: pointer;
            font-weight: 700;
        }
        
        .step2-split {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
            align-items: stretch;
        }
        
        .cert-panel-step2, .qualification-panel-step2 {
            background: #f8fafc;
            border-radius: 20px;
            padding: 20px;
            display: flex;
            flex-direction: column;
            height: 100%;
            min-height: 500px;
        }
        
        .cert-viewer-step2 {
            background: white;
            border-radius: 16px;
            padding: 20px;
            text-align: center;
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            min-height: 350px;
        }
        
        .cert-image-step2 {
            max-width: 100%;
            max-height: 250px;
            object-fit: contain;
            margin-bottom: 15px;
            cursor: pointer;
            transition: transform 0.2s;
        }
        
        .cert-nav-step2 {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 15px;
            gap: 10px;
        }
        
        .nav-btn-step2 {
            background: #E75A9B;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 30px;
            cursor: pointer;
            font-size: 12px;
        }
        
        .nav-btn-step2:disabled {
            background: #cbd5e1;
            cursor: not-allowed;
        }
        
        .qual-textarea-single {
            width: 100%;
            padding: 12px;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            font-family: inherit;
            font-size: 0.8rem;
            resize: vertical;
            min-height: 60px;
        }
        
        .btn-add-qualification {
            background: #e0f2fe;
            color: #0284c7;
            border: none;
            padding: 8px 16px;
            border-radius: 30px;
            cursor: pointer;
            margin-bottom: 20px;
            width: 100%;
        }
        
        .btn-approve-step2-horizontal {
            background: #28a745;
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 40px;
            cursor: pointer;
            font-weight: 700;
            width: auto;
            min-width: 160px;
        }
        
        .btn-reject-step2-horizontal {
            background: #dc3545;
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 40px;
            cursor: pointer;
            font-weight: 700;
            width: auto;
            min-width: 160px;
        }
        
        .step2-action-buttons-horizontal {
            display: flex;
            justify-content: center;
            gap: 15px;
            margin-top: 20px;
        }
        
        .image-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.9);
            z-index: 3000;
            align-items: center;
            justify-content: center;
            cursor: pointer;
        }
        
        .image-modal img {
            max-width: 90%;
            max-height: 90%;
            object-fit: contain;
        }
        
        .close-image-modal {
            position: absolute;
            top: 20px;
            right: 40px;
            color: white;
            font-size: 40px;
            cursor: pointer;
        }
        
        /* ========== RESPONSIVE ========== */
        @media (max-width: 768px) {
            .menu-toggle {
                display: block;
            }
            
            .sidebar {
                transform: translateX(-100%);
            }
            
            .sidebar.open {
                transform: translateX(0);
            }
            
            .main-content {
                margin-left: 0;
                padding: 16px;
            }
            
            .filter-bar {
                flex-direction: column;
                align-items: stretch;
            }
            
            .profile-split, .step2-split {
                grid-template-columns: 1fr;
            }
            
            .brand-icon {
                width: 38px;
                height: 38px;
            }
            
            .brand-title h1 {
                font-size: 1.1rem;
            }
            
            .sidebar-header {
                padding: 20px 16px;
            }
        }
    </style>
</head>
<body>

<div class="sidebar-overlay" id="sidebarOverlay"></div>
<aside class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <div class="brand-wrapper">
            <img src="<?= e($assetBase) ?>/logo.png" alt="Kyoshi" class="brand-icon">
            <div class="brand-title">
                <h1>Kyoshi</h1>
                <span class="admin-space-text">Admin Space</span>
            </div>
        </div>
    </div>
    <nav class="nav-menu">
        <div class="nav-section">
            <a href="admin_dashboard.php" class="nav-item"><i class="bi bi-speedometer2"></i><span>Dashboard</span></a>
        </div>
        <div class="nav-section">
            <div class="nav-section-label">USERS</div>
            <a href="admin_tutor_actions.php" class="nav-item active"><i class="bi bi-person-badge"></i><span>Tutors</span><span class="nav-badge"><?= $totalTutors ?></span></a>
            <a href="admin_student_actions.php" class="nav-item"><i class="bi bi-person"></i><span>Students</span><span class="nav-badge"><?= $totalStudents ?></span></a>
        </div>
        <div class="nav-section">
            <div class="nav-section-label">FINANCE</div>
            <a href="admin_payments.php" class="nav-item"><i class="bi bi-credit-card"></i><span>Payments</span><span class="nav-badge pending"><?= $pendingPayments ?></span></a>
            <a href="admin_payouts.php" class="nav-item"><i class="bi bi-cash-stack"></i><span>Payouts</span><span class="nav-badge"><?= $pendingPayouts ?></span></a>
        </div>
        <div class="nav-section">
            <div class="nav-section-label">BOOKINGS</div>
            <a href="admin_bookings.php" class="nav-item"><i class="bi bi-calendar-check"></i><span>Bookings</span><span class="nav-badge"><?= $totalBookings ?></span></a>
            <a href="admin_disputes.php" class="nav-item"><i class="bi bi-flag"></i><span>Disputes</span><span class="nav-badge pending"><?= $pendingDisputes ?></span></a>
        </div>
        <div class="nav-section">
            <div class="nav-section-label">REPORTS</div>
            <a href="admin_reports.php" class="nav-item"><i class="bi bi-graph-up"></i><span>Analytics</span></a>
        </div>
    </nav>
    <div class="sidebar-footer">
        <div class="admin-info">
            <img src="<?= e($profilePic) ?>" alt="Admin" class="footer-avatar">
            <span class="admin-name"><?= e($displayName) ?></span>
        </div>
        <a href="logout.php" class="logout-icon"><i class="bi bi-box-arrow-right"></i></a>
    </div>
</aside>

<div class="main-content" id="mainContent">
<div class="top-bar">
    <div style="display: flex; align-items: center; gap: 16px;">
        <a href="admin_tutor_actions.php" class="btn-back" style="display: inline-flex; align-items: center; gap: 8px; background: #e2e8f0; color: #1d3156; padding: 8px 16px; border-radius: 40px; text-decoration: none; font-size: 13px; font-weight: 600; transition: 0.2s;">
            <i class="bi bi-arrow-left"></i> Back
        </a>
        <div class="page-title">
            <h1>Verify Tutors</h1>
        </div>
    </div>
    <button class="menu-toggle" id="menuToggle"><i class="bi bi-list"></i> Menu</button>
    <div class="relative">
        <button class="admin-profile" onclick="toggleDropdown()">
            <img src="<?= e($profilePic) ?>" alt="Admin">
            <span><?= e($displayName) ?></span>
            <i class="bi bi-chevron-down"></i>
        </button>
        <div class="dropdown" id="profileDropdown">
            <a href="admin_profile.php"><i class="bi bi-person-circle"></i> My Profile</a>
            <hr>
            <a href="logout.php" style="color:#dc2626;"><i class="bi bi-box-arrow-right"></i> Logout</a>
        </div>
    </div>
</div>

    <?php if ($message): ?>
    <div class="message"><?= $message ?></div>
    <?php endif; ?>
    <?php if (isset($_SESSION['success_message'])): ?>
    <div class="message" style="background: #d4edda; color: #155724;">
        <i class="bi bi-check-circle"></i> <?= $_SESSION['success_message'] ?>
    </div>
    <?php unset($_SESSION['success_message']); ?>
<?php endif; ?>

<?php if (isset($_SESSION['warning_message'])): ?>
    <div class="message" style="background: #fff3cd; color: #856404;">
        <i class="bi bi-exclamation-triangle"></i> <?= $_SESSION['warning_message'] ?>
    </div>
    <?php unset($_SESSION['warning_message']); ?>
<?php endif; ?>
    <form method="GET" class="filter-bar">
        <div class="search-box">
            <i class="bi bi-search"></i>
            <input type="text" name="search" placeholder="Search by name or email..." value="<?= e($search) ?>">
        </div>
        <select name="language" class="filter-select">
            <option value="">All Languages</option>
            <?php while ($lang = $languages->fetch_assoc()): ?>
                <option value="<?= e($lang['language']) ?>" <?= $language_filter == $lang['language'] ? 'selected' : '' ?>><?= e($lang['language']) ?></option>
            <?php endwhile; ?>
        </select>
        <button type="submit" class="filter-btn"><i class="bi bi-search"></i> Search</button>
        <?php if ($search || $language_filter): ?>
            <a style="text-decoration: none !important;" href="admin_verify_tutors.php" class="filter-btn reset-btn"><i class="bi bi-x-circle"></i> Clear</a>
        <?php endif; ?>
    </form>
    
    <div class="tutors-table-container">
        <table>
            <thead>
                <tr><th>Name & Email</th><th>Experience</th><th>Rate</th><th>Languages (with level)</th><th>Certificates</th><th>Action</th></tr>
            </thead>
            <tbody>
                <?php if ($pendingTutors && $pendingTutors->num_rows > 0): ?>
                    <?php while ($tutor = $pendingTutors->fetch_assoc()): 
                        $certCheck = $conn->prepare("SELECT COUNT(*) as count FROM tutor_certificates WHERE tutor_id = ?");
                        $certCheck->bind_param("i", $tutor['id']);
                        $certCheck->execute();
                        $hasCerts = $certCheck->get_result()->fetch_assoc()['count'] > 0;
                    ?>
                        <tr>
                            <td><strong><?= e($tutor['fullname']) ?></strong><br><small><?= e($tutor['email']) ?></small></td>
                            <td><?= $tutor['experience'] ?? 0 ?> years</td>
                            <td>RM <?= $tutor['rate'] ?? 0 ?></td>
                            <td><small><?= !empty($tutor['languages']) ? e($tutor['languages']) : '-' ?></small></td>
                            <td><?= $hasCerts ? '<span class="has-cert"><i class="bi bi-file-earmark-text"></i> Has certificates</span>' : '<span class="no-cert">No certificates</span>' ?></td>
                            <td><button class="btn-view" onclick="openVerifyWizard(<?= $tutor['id'] ?>, '<?= e(addslashes($tutor['fullname'])) ?>')"><i class="bi bi-eye"></i> Verify</button></td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr><td colspan="6" style="text-align: center; padding: 60px;"><i class="bi bi-check-circle" style="font-size: 48px; color: #ccc;"></i><p>No pending tutor applications.</p></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Image Enlarge Modal -->
<div id="imageEnlargeModal" class="image-modal" onclick="closeImageModal()">
    <span class="close-image-modal">&times;</span>
    <img id="enlargedImage" src="" alt="Enlarged Certificate">
</div>
<!-- Step Wizard Modal -->
<div id="wizardModal" class="step-modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 id="wizardTitle"><i class="bi bi-person-check"></i> Verify Tutor</h3>
            <button class="close-modal" onclick="closeWizard()">&times;</button>
        </div>
        
       <!-- Step Indicators with Navigation Arrows -->
        <div class="step-indicator">
            <button class="step-nav-btn" id="prevStepBtn" onclick="prevStep()" disabled>
                <i class="bi bi-chevron-left"></i>
            </button>
            <div class="steps-wrapper">
                <div class="step active" id="step1Indicator">
                    <span class="step-number">1</span>
                    <span>Profile Review</span>
                </div>
                <div class="step" id="step2Indicator">
                    <span class="step-number">2</span>
                    <span>Certificates & Qualifications</span>
                </div>
            </div>
            <button class="step-nav-btn" id="nextStepBtn" onclick="nextStep()">
                <i class="bi bi-chevron-right"></i>
            </button>
        </div>
        
        <div class="step-body">
            <!-- STEP 1: Profile -->
            <div id="step1Content">
                <div class="profile-split">
                    <div class="profile-left">
                        <img id="step1ProfileImg" src="" alt="Profile Photo" onerror="this.src='../assets/img/profile-tutor.png'">
                        <h3 id="step1Name" style="margin-top: 15px;"></h3>
                        <p id="step1Email" style="color: #64748b;"></p>
                    </div>
                    <div class="profile-right">
                        <div class="info-row">
                            <div class="info-label">Phone</div>
                            <div class="info-value" id="step1Phone">-</div>
                        </div>
                        <div class="info-row">
                            <div class="info-label">Experience</div>
                            <div class="info-value" id="step1Exp">-</div>
                        </div>
                        <div class="info-row">
                            <div class="info-label">Rate</div>
                            <div class="info-value" id="step1Rate">-</div>
                        </div>
                        <div class="info-row">
                            <div class="info-label">Languages & Level</div>
                            <div class="info-value" id="step1Languages"></div>
                        </div>
                        <div class="info-row">
                            <div class="info-label">Bio</div>
                            <div class="info-value" id="step1Bio" style="max-height: 100px; overflow-y: auto;">-</div>
                        </div>
                    </div>
                </div>
                <div class="step-buttons">
                    <button class="btn-reject-step" onclick="confirmReject()"><i class="bi bi-x-lg"></i> Reject Tutor</button>
                    <button class="btn-next" onclick="goToStep2()">Next: Certificates <i class="bi bi-arrow-right"></i></button>
                </div>
            </div>
            
           <!-- STEP 2: Certificates + Qualifications -->
<div id="step2Content" style="display: none;">
    <div class="step2-split">
        <div class="cert-panel-step2">
            <h4 style="margin-bottom: 15px; color: #E75A9B;"><i class="bi bi-file-earmark-text"></i> Certificates</h4>
            <div id="certViewerStep2" class="cert-viewer-step2">
                Loading...
            </div>
            <div class="cert-nav-step2" id="certNavStep2" style="display: none;">
                <button class="nav-btn-step2" id="prevCertBtn" onclick="prevCertStep2()">← Previous</button>
                <span id="certCounterStep2">1 of 1</span>
                <button class="nav-btn-step2" id="nextCertBtn" onclick="nextCertStep2()">Next →</button>
            </div>
        </div>
        
        <div class="qualification-panel-step2">
            <h4 style="margin-bottom: 15px; color: #E75A9B;">
                <i class="bi bi-patch-check"></i> Add Qualifications
            </h4>
            
            <div id="qualificationsList">
                <div class="qualification-item" style="margin-bottom: 12px;">
                    <div style="display: flex; gap: 10px; align-items: center;">
                        <textarea name="qualifications[]" class="qual-textarea-single" placeholder="e.g., TESOL Certified (2023)" style="flex: 1; min-height: 60px; padding: 10px; border: 1px solid #e2e8f0; border-radius: 12px; font-family: inherit; font-size: 0.8rem; resize: vertical;"></textarea>
                        <button type="button" class="remove-qual-btn" style="background: #fee2e2; color: #dc2626; border: none; width: 36px; height: 36px; border-radius: 50%; cursor: pointer; display: none;">
                            <i class="bi bi-trash"></i>
                        </button>
                    </div>
                </div>
            </div>
            
            <button type="button" class="btn-add-qualification" onclick="addQualificationField()" style="background: #e0f2fe; color: #0284c7; border: none; padding: 8px 16px; border-radius: 30px; cursor: pointer; margin-bottom: 20px; width: 100%;">
                <i class="bi bi-plus-lg"></i> Add Another Qualification
            </button>
            
            <form method="POST" id="approveForm">
                <input type="hidden" name="tutor_id" id="step2TutorId">
                <div class="step2-action-buttons-horizontal" style="justify-content: center;">
                    <button type="button" class="btn-approve-step2-horizontal" onclick="validateAndApprove()">
                        <i class="bi bi-check-lg"></i> Approve Tutor
                    </button>
                    <button type="button" class="btn-reject-step2-horizontal" onclick="confirmReject()">
                        <i class="bi bi-x-lg"></i> Reject Tutor
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
let currentTutorData = null;
let currentCertificates = [];
let currentCertIndex = 0;
let currentStep = 1;

function toggleDropdown() {
    const dropdown = document.getElementById('profileDropdown');
    dropdown.style.display = dropdown.style.display === 'block' ? 'none' : 'block';
}

window.addEventListener('click', function(e) {
    const dropdown = document.getElementById('profileDropdown');
    const button = document.querySelector('.admin-profile');
    if (button && !button.contains(e.target) && dropdown && !dropdown.contains(e.target)) {
        dropdown.style.display = 'none';
    }
});

function nextStep() {
    if (currentStep === 1) {
        goToStep2();
    }
}

function prevStep() {
    if (currentStep === 2) {
        goToStep1();
    }
}

function goToStep2() {
    currentStep = 2;
    document.getElementById('step1Content').style.display = 'none';
    document.getElementById('step2Content').style.display = 'block';
    
    // First remove active from step1
    document.getElementById('step1Indicator').classList.remove('active');
    // Then add active to step2
    document.getElementById('step2Indicator').classList.add('active');
    // Then add completed to step1
    document.getElementById('step1Indicator').classList.add('completed');
    
    document.getElementById('prevStepBtn').disabled = false;
    document.getElementById('nextStepBtn').disabled = true;
}

function goToStep1() {
    currentStep = 1;
    document.getElementById('step2Content').style.display = 'none';
    document.getElementById('step1Content').style.display = 'block';
    
    // First remove active from step2
    document.getElementById('step2Indicator').classList.remove('active');
    // Then remove completed from step1
    document.getElementById('step1Indicator').classList.remove('completed');
    // Then add active to step1
    document.getElementById('step1Indicator').classList.add('active');
    
    document.getElementById('prevStepBtn').disabled = true;
    document.getElementById('nextStepBtn').disabled = false;
}

function openVerifyWizard(tutorId, tutorName) {
    // Reset to step 1
    currentStep = 1;
    document.getElementById('step2Content').style.display = 'none';
    document.getElementById('step1Content').style.display = 'block';
    document.getElementById('step2Indicator').classList.remove('active');
    document.getElementById('step1Indicator').classList.add('active');
    document.getElementById('step1Indicator').classList.remove('completed');
    document.getElementById('prevStepBtn').disabled = true;
    document.getElementById('nextStepBtn').disabled = false;
    
    document.getElementById('wizardTitle').innerHTML = '<i class="bi bi-person-check"></i> Verify Tutor - ' + tutorName;
    document.getElementById('step2TutorId').value = tutorId;
    document.getElementById('wizardModal').style.display = 'flex';
    
    // Load tutor details
    fetch('get_tutor_details.php?tutor_id=' + tutorId)
        .then(response => response.json())
        .then(data => {
            currentTutorData = data;
            
            let profilePicPath = data.profile_pic ? '../uploads/profiles/' + data.profile_pic : '../assets/img/profile-tutor.png';
            document.getElementById('step1ProfileImg').src = profilePicPath;
            document.getElementById('step1Name').innerHTML = escapeHtml(data.fullname || '');
            document.getElementById('step1Email').innerHTML = escapeHtml(data.email || '');
            document.getElementById('step1Phone').innerHTML = escapeHtml(data.phone || 'No phone');
            document.getElementById('step1Exp').innerHTML = (data.experience || 0) + ' years';
            document.getElementById('step1Rate').innerHTML = 'RM ' + (data.rate || 0);
            document.getElementById('step1Bio').innerHTML = escapeHtml(data.bio || '-');
            
            // Display languages with proficiency as tags
            let languagesHtml = '';
            if (data.languages && data.languages !== '-') {
                let langArray = data.languages.split(', ');
                langArray.forEach(lang => {
                    languagesHtml += `<span class="lang-tag" style="display: inline-block; margin-right: 8px; margin-bottom: 5px;">${escapeHtml(lang)}</span>`;
                });
                document.getElementById('step1Languages').innerHTML = languagesHtml;
            } else {
                document.getElementById('step1Languages').innerHTML = '-';
            }
        })
        .catch(error => {
            console.error('Error loading tutor details:', error);
            document.getElementById('step1Name').innerHTML = 'Error loading data';
        });
    
    // Load certificates
    fetch('get_tutor_certificates.php?tutor_id=' + tutorId)
        .then(response => response.json())
        .then(data => {
            currentCertificates = data;
            currentCertIndex = 0;
            displayCertStep2();
        })
        .catch(error => {
            console.error('Error loading certificates:', error);
            const viewer = document.getElementById('certViewerStep2');
            if (viewer) {
                viewer.innerHTML = '<div style="text-align: center; padding: 40px; color: red;">Error loading certificates</div>';
            }
        });
}
function displayCertStep2() {
    const viewer = document.getElementById('certViewerStep2');
    const nav = document.getElementById('certNavStep2');
    
    if (!viewer) return;
    
    if (currentCertificates.length === 0) {
        viewer.innerHTML = '<div style="text-align: center; padding: 40px;"><i class="bi bi-file-earmark-text" style="font-size: 48px; color: #ccc;"></i><p>No certificates uploaded</p></div>';
        if (nav) nav.style.display = 'none';
        return;
    }
    
    if (nav) nav.style.display = 'flex';
    const cert = currentCertificates[currentCertIndex];
    const fileExt = cert.file_path.split('.').pop().toLowerCase();
    const isImage = ['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(fileExt);
    const fileUrl = '/Kyoshi/uploads/certificates/' + cert.file_path;
    
    let previewHtml = '';
    
    if (isImage) {
        previewHtml = `<img src="${fileUrl}" class="cert-image-step2" alt="Certificate" onclick="enlargeImage(this.src)" style="max-width: 100%; max-height: 250px; object-fit: contain; cursor: pointer;">`;
    } else if (fileExt === 'pdf') {
        previewHtml = `
            <div style="background: #f8fafc; border-radius: 12px; padding: 20px;">
                <embed src="${fileUrl}" type="application/pdf" style="width: 100%; height: 400px; border-radius: 12px;" />
                <div style="display: flex; gap: 10px; justify-content: center; margin-top: 15px;">
                    <a href="${fileUrl}" target="_blank" class="btn-view" style="background: #E75A9B; color: white; padding: 8px 20px; border-radius: 30px; text-decoration: none;">Open PDF</a>
                    <a href="${fileUrl}" download class="btn-view" style="background: #64748b; color: white; padding: 8px 20px; border-radius: 30px; text-decoration: none;">Download</a>
                </div>
            </div>
        `;
    } else {
        previewHtml = `<a href="${fileUrl}" target="_blank" class="btn-view" style="background: #E75A9B; color: white; padding: 10px 20px; border-radius: 30px; text-decoration: none;">View File</a>`;
    }
    
    viewer.innerHTML = `
        ${previewHtml}
        <div style="margin-top: 8px;"><strong>${escapeHtml(cert.certificate_name)}</strong></div>
    `;
    
    const counter = document.getElementById('certCounterStep2');
    const prevBtn = document.getElementById('prevCertBtn');
    const nextBtn = document.getElementById('nextCertBtn');
    
    if (counter) counter.innerHTML = `${currentCertIndex + 1} of ${currentCertificates.length}`;
    if (prevBtn) prevBtn.disabled = (currentCertIndex === 0);
    if (nextBtn) nextBtn.disabled = (currentCertIndex === currentCertificates.length - 1);
}
function enlargeImage(src) {
    document.getElementById('enlargedImage').src = src;
    document.getElementById('imageEnlargeModal').style.display = 'flex';
}

function closeImageModal() {
    document.getElementById('imageEnlargeModal').style.display = 'none';
}

function prevCertStep2() {
    if (currentCertIndex > 0) {
        currentCertIndex--;
        displayCertStep2();
    }
}

function nextCertStep2() {
    if (currentCertIndex < currentCertificates.length - 1) {
        currentCertIndex++;
        displayCertStep2();
    }
}

function closeWizard() {
    document.getElementById('wizardModal').style.display = 'none';
    document.getElementById('step2Content').style.display = 'none';
    document.getElementById('step1Content').style.display = 'block';
    document.getElementById('step2Indicator').classList.remove('active');
    document.getElementById('step1Indicator').classList.add('active');
    document.getElementById('step1Indicator').classList.remove('completed');
    currentStep = 1;
    const prevBtn = document.getElementById('prevStepBtn');
    const nextBtn = document.getElementById('nextStepBtn');
    if (prevBtn) prevBtn.disabled = true;
    if (nextBtn) nextBtn.disabled = false;
}
let qualificationCount = 1;

function addQualificationField() {
    qualificationCount++;
    const container = document.getElementById('qualificationsList');
    const newItem = document.createElement('div');
    newItem.className = 'qualification-item';
    newItem.style.marginBottom = '12px';
    newItem.innerHTML = `
        <div style="display: flex; gap: 10px; align-items: center;">
            <textarea name="qualifications[]" class="qual-textarea-single" placeholder="e.g., Bachelor's Degree in English" style="flex: 1; min-height: 60px; padding: 10px; border: 1px solid #e2e8f0; border-radius: 12px; font-family: inherit; font-size: 0.8rem; resize: vertical;"></textarea>
            <button type="button" class="remove-qual-btn" onclick="removeQualificationField(this)" style="background: #fee2e2; color: #dc2626; border: none; width: 36px; height: 36px; border-radius: 50%; cursor: pointer;">
                <i class="bi bi-trash"></i>
            </button>
        </div>
    `;
    container.appendChild(newItem);
}

function removeQualificationField(button) {
    const item = button.closest('.qualification-item');
    if (document.querySelectorAll('.qualification-item').length > 1) {
        item.remove();
        qualificationCount--;
    } else {
        Swal.fire('Cannot Remove', 'You need at least one qualification field', 'info');
    }
}
function validateAndApprove() {
    const qualifications = document.querySelectorAll('textarea[name="qualifications[]"]');
    let qualificationList = [];
    
    // Only collect NON-EMPTY qualifications (ignore empty ones)
    qualifications.forEach((q) => {
        const val = q.value.trim();
        if (val !== '') {
            qualificationList.push(val);
        }
    });
    
    // If NO qualifications at all (all fields empty) - still allow?
    if (qualificationList.length === 0) {
        // Show warning but allow? Or block?
        Swal.fire({
            title: 'No Qualifications',
            text: 'You are approving this tutor without any qualifications. Continue?',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#28a745',
            cancelButtonColor: '#dc3545',
            confirmButtonText: 'Yes, Approve Without Qualifications',
            cancelButtonText: 'Cancel'
        }).then((result) => {
            if (result.isConfirmed) {
                submitApproval([]);
            }
        });
        return;
    }
    
    // Has qualifications - show them in confirmation
    let displayText = '<ul style="text-align: left;">';
    qualificationList.forEach(q => {
        displayText += `<li>${escapeHtml(q)}</li>`;
    });
    displayText += '</ul>';
    
    const tutorId = document.getElementById('step2TutorId').value;
    
    Swal.fire({
        title: 'Approve Tutor?',
        html: `Are you sure you want to approve this tutor with the following qualifications?<br><br>
               <strong>Qualifications:</strong><br>
               ${displayText}`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#28a745',
        cancelButtonColor: '#dc3545',
        confirmButtonText: 'Yes, Approve',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            submitApproval(qualificationList);
        }
    });
}

// Separate function to submit approval
function submitApproval(qualificationList) {
    const tutorId = document.getElementById('step2TutorId').value;
    const form = document.createElement('form');
    form.method = 'POST';
    
    const tutorIdInput = document.createElement('input');
    tutorIdInput.type = 'hidden';
    tutorIdInput.name = 'tutor_id';
    tutorIdInput.value = tutorId;
    form.appendChild(tutorIdInput);
    
    const actionInput = document.createElement('input');
    actionInput.type = 'hidden';
    actionInput.name = 'approve_with_qualification';
    actionInput.value = '1';
    form.appendChild(actionInput);
    
    // Add each qualification (only non-empty ones)
    qualificationList.forEach((qual, index) => {
        const qualInput = document.createElement('input');
        qualInput.type = 'hidden';
        qualInput.name = `qualifications[${index}]`;
        qualInput.value = qual;
        form.appendChild(qualInput);
    });
    
    document.body.appendChild(form);
    form.submit();
}
function escapeHtml(text) {
    if (!text) return '';
    return text.replace(/[&<>]/g, function(m) {
        if (m === '&') return '&amp;';
        if (m === '<') return '&lt;';
        if (m === '>') return '&gt;';
        return m;
    });
}
function confirmReject() {
    const tutorId = document.getElementById('step2TutorId').value;
    
    Swal.fire({
        title: 'Reject Tutor?',
        text: 'This will reject the tutor and their certificates. This action cannot be undone.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        cancelButtonColor: '#64748b',
        confirmButtonText: 'Yes, Reject',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = '<input type="hidden" name="reject" value="1"><input type="hidden" name="tutor_id" value="' + tutorId + '">';
            document.body.appendChild(form);
            form.submit();
        }
    });
}
const menuToggle = document.getElementById('menuToggle');
const sidebar = document.getElementById('sidebar');
const overlay = document.getElementById('sidebarOverlay');

if (menuToggle) {
    menuToggle.addEventListener('click', function() {
        sidebar.classList.toggle('open');
        overlay.classList.toggle('active');
        document.body.style.overflow = sidebar.classList.contains('open') ? 'hidden' : '';
    });
}

if (overlay) {
    overlay.addEventListener('click', function() {
        sidebar.classList.remove('open');
        overlay.classList.remove('active');
        document.body.style.overflow = '';
    });
}

// Show search results popup
function showSearchResults(count, searchTerm, languageFilter) {
    let message = '';
    if (count > 0) {
        message = `Found ${count} pending tutor application${count > 1 ? 's' : ''} matching your criteria.`;
    } else {
        message = 'No pending tutor applications match your search criteria.';
    }
    
    Swal.fire({
        title: count > 0 ? 'Search Results' : 'No Results Found',
        text: message,
        icon: count > 0 ? 'success' : 'info',
        confirmButtonColor: '#E75A9B',
        timer: 2000,
        showConfirmButton: false
    });
}

// Show clear filters popup
function showClearPopup() {
    Swal.fire({
        title: 'Filters Cleared',
        text: 'All search filters have been reset.',
        icon: 'success',
        confirmButtonColor: '#E75A9B',
        timer: 1500,
        showConfirmButton: false
    });
}

// Check and show popup on page load
document.addEventListener('DOMContentLoaded', function() {
    <?php if (!empty($search) || !empty($language_filter)): ?>
        <?php if ($pendingCount > 0): ?>
            showSearchResults(<?= $pendingCount ?>, '<?= addslashes($search) ?>', '<?= addslashes($language_filter) ?>');
        <?php else: ?>
            showSearchResults(0, '', '');
        <?php endif; ?>
    <?php endif; ?>
});

// For Clear button click
document.querySelector('.reset-btn')?.addEventListener('click', function(e) {
    <?php if (!empty($search) || !empty($language_filter)): ?>
        showClearPopup();
    <?php endif; ?>
});
</script>
</body>
</html>