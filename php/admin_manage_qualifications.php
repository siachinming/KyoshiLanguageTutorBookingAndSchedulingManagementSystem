<?php
session_start();
include 'config.php';
include 'check_login.php';
include 'send_certificate_email.php';
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

$displayName = $admin['fullname'];
$profilePic = !empty($admin['profile_pic']) ? '../uploads/profiles/' . $admin['profile_pic'] : $assetBase . '/profile-admin.png';

// Handle adding qualification manually
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_qualification'])) {
    $tutor_id = intval($_POST['tutor_id']);
    $qualification_name = trim($_POST['qualification_name']);
    
    $stmt = $conn->prepare("INSERT INTO tutor_qualifications (tutor_id, qualification_name) VALUES (?, ?)");
    $stmt->bind_param("is", $tutor_id, $qualification_name);
    
    if ($stmt->execute()) {
        $_SESSION['success_message'] = "Qualification added successfully!";
    } else {
        $_SESSION['error_message'] = "Error adding qualification: " . $conn->error;
    }
    header("Location: admin_manage_qualifications.php");
    exit();
}

// Handle editing qualification
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_qualification'])) {
    $qualification_id = intval($_POST['qualification_id']);
    $qualification_name = trim($_POST['qualification_name']);
    
    $stmt = $conn->prepare("UPDATE tutor_qualifications SET qualification_name = ? WHERE id = ?");
    $stmt->bind_param("si", $qualification_name, $qualification_id);
    
    if ($stmt->execute()) {
        $_SESSION['success_message'] = "Qualification updated successfully!";
    } else {
        $_SESSION['error_message'] = "Error updating qualification";
    }
    header("Location: admin_manage_qualifications.php");
    exit();
}

// Delete qualification
if (isset($_GET['delete_qualification'])) {
    $qualification_id = intval($_GET['delete_qualification']);
    $stmt = $conn->prepare("DELETE FROM tutor_qualifications WHERE id = ?");
    $stmt->bind_param("i", $qualification_id);
    if ($stmt->execute()) {
        $_SESSION['success_message'] = "Qualification deleted successfully!";
    }
    header("Location: admin_manage_qualifications.php");
    exit();
}
// Handle certificate verification (approve/reject)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verify_certificate'])) {
    $certificate_id = intval($_POST['certificate_id']);
    $action = $_POST['verify_action'];
    $admin_notes = trim($_POST['admin_notes'] ?? '');
    
    // Get certificate details first with tutor info
    $certStmt = $conn->prepare("
        SELECT c.*, u.email as tutor_email, u.fullname as tutor_name 
        FROM tutor_certificates c
        JOIN users u ON c.tutor_id = u.id
        WHERE c.id = ?
    ");
    $certStmt->bind_param("i", $certificate_id);
    $certStmt->execute();
    $cert = $certStmt->get_result()->fetch_assoc();
    
    if ($action === 'approve') {
        // Update certificate status
        $stmt = $conn->prepare("UPDATE tutor_certificates SET status = 'approved', admin_notes = ? WHERE id = ?");
        $stmt->bind_param("si", $admin_notes, $certificate_id);
        
        if ($stmt->execute()) {
            // Auto-add to qualifications table
            $checkQual = $conn->prepare("SELECT id FROM tutor_qualifications WHERE tutor_id = ? AND qualification_name = ?");
            $checkQual->bind_param("is", $cert['tutor_id'], $cert['certificate_name']);
            $checkQual->execute();
            $existing = $checkQual->get_result()->fetch_assoc();
            
            if (!$existing) {
                $addQual = $conn->prepare("INSERT INTO tutor_qualifications (tutor_id, qualification_name) VALUES (?, ?)");
                $addQual->bind_param("is", $cert['tutor_id'], $cert['certificate_name']);
                $addQual->execute();
            }
            
            // Send email notification for approval
            sendCertificateNotification($cert['tutor_email'], $cert['tutor_name'], $cert['certificate_name'], 'approved', $admin_notes);
            
            $_SESSION['success_message'] = "Certificate approved, added to qualifications, and email sent to tutor!";
        }
    } elseif ($action === 'reject') {
        // Make sure rejection reason is provided
        if (empty($admin_notes)) {
            $_SESSION['error_message'] = "Please provide a reason for rejection.";
            header("Location: admin_manage_qualifications.php?tab=certificates&status=pending");
            exit();
        }
        $stmt = $conn->prepare("UPDATE tutor_certificates SET status = 'rejected', admin_notes = ? WHERE id = ?");
        $stmt->bind_param("si", $admin_notes, $certificate_id);
        if ($stmt->execute()) {
            // Send email notification for rejection
            sendCertificateNotification($cert['tutor_email'], $cert['tutor_name'], $cert['certificate_name'], 'rejected', $admin_notes);
            
            $_SESSION['success_message'] = "Certificate rejected and email sent to tutor.";
        }
    }
    header("Location: admin_manage_qualifications.php?tab=certificates&status=pending");
    exit();
}

// Delete certificate
if (isset($_GET['delete_certificate'])) {
    $certificate_id = intval($_GET['delete_certificate']);
    // Get file path first
    $stmt = $conn->prepare("SELECT file_path FROM tutor_certificates WHERE id = ?");
    $stmt->bind_param("i", $certificate_id);
    $stmt->execute();
    $cert = $stmt->get_result()->fetch_assoc();
    if ($cert && !empty($cert['file_path']) && file_exists('../uploads/certificates/' . $cert['file_path'])) {
        unlink('../uploads/certificates/' . $cert['file_path']);
    }
    
    $stmt = $conn->prepare("DELETE FROM tutor_certificates WHERE id = ?");
    $stmt->bind_param("i", $certificate_id);
    if ($stmt->execute()) {
        $_SESSION['success_message'] = "Certificate deleted successfully!";
    }
    header("Location: admin_manage_qualifications.php");
    exit();
}

$search = $_GET['search'] ?? '';
$tab = $_GET['tab'] ?? 'certificates';

// Set default status based on tab
if ($tab === 'certificates') {
    $filter_status = $_GET['status'] ?? 'pending';
} else {
    // For qualifications tab - default to 'approved'
    $filter_status = $_GET['status'] ?? 'approved';
}

// Get all tutors for dropdown
$tutors = $conn->query("SELECT id, fullname, email FROM users WHERE role = 'tutor' ORDER BY fullname");

// Get certificate requests - ONLY pending from APPROVED tutors
$cert_sql = "
    SELECT c.*, u.fullname as tutor_name, u.email as tutor_email
    FROM tutor_certificates c
    JOIN users u ON c.tutor_id = u.id
    WHERE u.status = 'approved' AND c.status = 'pending'
";

// Add search filter for certificates
if (!empty($search)) {
    $search_like = $conn->real_escape_string($search);
    $cert_sql .= " AND (u.fullname LIKE '%$search_like%' OR c.certificate_name LIKE '%$search_like%')";
}

$cert_sql .= " ORDER BY c.uploaded_at ASC";
$certificates = $conn->query($cert_sql);

// Get qualifications with status and search filters
$search_filter = !empty($search) ? $conn->real_escape_string($search) : '';

$qual_sql = "
    SELECT DISTINCT 
        q.*, 
        u.fullname as tutor_name, 
        u.email as tutor_email,
        CASE 
            WHEN c.id IS NULL THEN 'approved'
            ELSE c.status 
        END as status,
        c.uploaded_at as certificate_date,
        c.id as certificate_id
    FROM tutor_qualifications q
    JOIN users u ON q.tutor_id = u.id
    LEFT JOIN tutor_certificates c ON q.tutor_id = c.tutor_id 
        AND q.qualification_name = c.certificate_name
    WHERE 1=1
";

// Add search filter
if (!empty($search_filter)) {
    $qual_sql .= " AND (u.fullname LIKE '%$search_filter%' 
                        OR u.email LIKE '%$search_filter%' 
                        OR q.qualification_name LIKE '%$search_filter%')";
}

// Add status filter - USING THE CORRECT VARIABLE
if ($filter_status !== 'all') {
    if ($filter_status === 'approved') {
        // Show: Manual entries (no certificate) + Approved certificates
        $qual_sql .= " AND (c.id IS NULL OR c.status = 'approved')";
    } else if ($filter_status === 'pending') {
        // Show only qualifications that have pending certificates
        $qual_sql .= " AND c.status = 'pending'";
    } else if ($filter_status === 'rejected') {
        // Show only qualifications that have rejected certificates
        $qual_sql .= " AND c.status = 'rejected'";
    }
}

$qual_sql .= " GROUP BY q.id ORDER BY u.fullname ASC, q.created_at DESC";
$qualifications = $conn->query($qual_sql);

// Check for SQL errors
if (!$qualifications) {
    error_log("SQL Error: " . $conn->error);
    $qualifications = false;
}

// Get counts - only count pending certificates from approved tutors
$pendingCertificates = $conn->query("
    SELECT COUNT(*) as count 
    FROM tutor_certificates c
    JOIN users u ON c.tutor_id = u.id
    WHERE c.status = 'pending' AND u.status = 'approved'
")->fetch_assoc()['count'];


// Get counts
$totalTutors = $conn->query("SELECT COUNT(*) as count FROM users WHERE role = 'tutor'")->fetch_assoc()['count'];
$totalStudents = $conn->query("SELECT COUNT(*) as count FROM users WHERE role = 'student'")->fetch_assoc()['count'];
$pendingPayments = $conn->query("SELECT COUNT(*) as count FROM payments WHERE status = 'pending'")->fetch_assoc()['count'];
$pendingDisputes = $conn->query("SELECT COUNT(*) as count FROM disputes WHERE status = 'pending'")->fetch_assoc()['count'];
$pendingPayouts = $conn->query("SELECT COUNT(*) as count FROM payout_requests WHERE status = 'pending'")->fetch_assoc()['count'] ?? 0;
$totalBookings = $conn->query("SELECT COUNT(*) as count FROM bookings")->fetch_assoc()['count'];

function e($value) {
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function getStatusBadge($status) {
    switch ($status) {
        case 'approved':
            return '<span class="badge-approved">VERIFIED</span>';
        case 'pending':
            return '<span class="badge-pending">PENDING</span>';
        case 'rejected':
            return '<span class="badge-rejected">REJECTED</span>';
        default:
            return '<span class="badge-pending">PENDING</span>';
    }
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
    <title>Manage Qualifications · Admin</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../css/astyle.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: "Montserrat", "Open Sans", sans-serif;
            background: url('../assets/img/background3.jpg') no-repeat center top;
            background-size: cover;
            min-height: 100vh;
            position: relative;
            color: #1E1B2E;
            line-height: 1.45;
            overflow-x: hidden;
        }

        /* Sidebar - FIXED */
        /* Sidebar - FIXED */
.sidebar {
    position: fixed;
    left: 0;
    top: 0;
    width: 230px;
    height: 100vh;
    background: #272754;
    color: #E8E4F0;
    overflow-y: auto;
    z-index: 1000;
    transition: transform 0.3s ease;
    transform: translateX(0);
    display: flex;
    flex-direction: column;
}

/* Mobile styles */
@media (max-width: 768px) {
    .menu-toggle {
        display: block !important;
        background: #272754;
        color: white;
        border: none;
        width: 42px;
        height: 42px;
        border-radius: 10px;
        cursor: pointer;
        font-size: 1.3rem;
    }
    
    .sidebar {
        transform: translateX(-100%);
    }
    
    .sidebar.open {
        transform: translateX(0);
    }
}

        .sidebar.closed { transform: translateX(-100%); }
        .sidebar.open { transform: translateX(0); }

        .sidebar-header {
            padding: 28px 20px;
            flex-shrink: 0;
            border-bottom: 1px solid rgba(255,255,255,0.1);
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

        .brand-title h1 {
            font-size: 1.4rem;
            font-weight: 700;
            background: linear-gradient(135deg, #ffffff, #B26EA7);
            background-clip: text;
            -webkit-background-clip: text;
            color: transparent;
            margin: 0;
        }

        .admin-space-text {
            font-size: 0.6rem;
            color: #e7c7f7;
            margin-top: 2px;
        }

        .nav-menu {
            padding: 16px 0;
            flex: 1;
            overflow-y: auto;
            min-height: 0;
        }

        .nav-menu::-webkit-scrollbar { width: 3px; }
        .nav-menu::-webkit-scrollbar-track { background: rgba(255,255,255,0.1); }
        .nav-menu::-webkit-scrollbar-thumb { background: #B26EA7; border-radius: 3px; }
        .menu-toggle {
            background: #272754;
            color: white;
            border: none;
            padding: 8px 12px;
            border-radius: 10px;
            cursor: pointer;
            display: none;  /* Add this line - hidden by default */
            font-size: 1.1rem;
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

        /* Verify Certificate Modal - Enhanced Styling */
        #verifyModal .modal-container {
            max-width: 500px;
            width: 90%;
            border-radius: 24px;
            overflow: hidden;
            animation: modalFadeIn 0.3s ease;
        }
        
        .swal2-container {
            z-index: 99999 !important;
        }

        .swal2-popup {
            z-index: 99999 !important;
        }
        /* Required field styling */
        #verifyModal .form-group label .required-star {
            color: #dc2626;
            margin-left: 2px;
        }

        #verifyModal .form-group textarea:required {
            border-color: #dc2626;
        }

        #verifyModal .form-group textarea:required:valid {
            border-color: #e2e8f0;
        }

        @keyframes modalFadeIn {
            from {
                opacity: 0;
                transform: scale(0.95);
            }
            to {
                opacity: 1;
                transform: scale(1);
            }
        }

        #verifyModal .modal-header {
            background: linear-gradient(135deg, #1a1a3e 0%, #272754 100%);
            padding: 20px 24px;
            border-bottom: none;
        }

        #verifyModal .modal-header h3 {
            color: white;
            font-size: 1.2rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 0;
        }

        #verifyModal .modal-header h3 i {
            font-size: 1.3rem;
        }

        #verifyModal .modal-close {
            color: rgba(255, 255, 255, 0.7);
            transition: all 0.2s;
        }

        #verifyModal .modal-close:hover {
            color: white;
            background: rgba(255, 255, 255, 0.2);
            transform: rotate(90deg);
        }

        #verifyModal .modal-body {
            padding: 24px;
            background: #f8fafc;
        }

        #verifyModal .form-group {
            margin-bottom: 0;
        }

        #verifyModal .form-group label {
            font-size: 13px;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 8px;
            display: block;
        }

        #verifyModal .form-group label i {
            color: #E75A9B;
            margin-right: 6px;
        }

        #verifyModal .form-group textarea {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            font-family: 'Montserrat', sans-serif;
            font-size: 13px;
            resize: vertical;
            transition: all 0.2s;
            background: white;
        }

        #verifyModal .form-group textarea:focus {
            outline: none;
            border-color: #E75A9B;
            box-shadow: 0 0 0 3px rgba(231, 90, 155, 0.1);
        }

        #verifyModal .form-group textarea::placeholder {
            color: #94a3b8;
            font-size: 12px;
        }

        #verifyModal .modal-buttons {
            padding: 16px 24px;
            background: white;
            border-top: 1px solid #e2e8f0;
            display: flex;

            justify-content: flex-end;
            gap: 12px;
        }

        #verifyModal .btn-cancel {
            background: #f1f5f9;
            color: #475569;
            padding: 10px 20px;
            border-radius: 40px;
            font-weight: 600;
            font-size: 13px;
            border: none;
            cursor: pointer;
            transition: all 0.2s;
        }

        #verifyModal .btn-cancel:hover {
            background: #e2e8f0;
            transform: translateY(-1px);
        }

        #verifyModal .btn-save {
            background: #28a745;
            color: white;
            padding: 10px 24px;
            border-radius: 40px;
            font-weight: 600;
            font-size: 13px;
            border: none;
            cursor: pointer;
            transition: all 0.2s;
        }

        #verifyModal .btn-save:hover {
            background: #218838;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(40, 167, 69, 0.3);
        }

        /* Different styles for approve vs reject buttons in modal */
        #verifyModal .btn-save.approve-mode {
            background: #28a745;
        }

        #verifyModal .btn-save.approve-mode:hover {
            background: #218838;
        }

        #verifyModal .btn-save.reject-mode {
            background: #dc2626;
        }

        #verifyModal .btn-save.reject-mode:hover {
            background: #c82333;
            box-shadow: 0 4px 12px rgba(220, 38, 38, 0.3);
        }

        /* Certificate info preview in modal (optional - shows what you're verifying) */
        .verify-cert-info {
            background: #f1f5f9;
            border-radius: 12px;
            padding: 12px 16px;
            margin-bottom: 20px;
            font-size: 12px;
        }

        .verify-cert-info p {
            margin: 4px 0;
            color: #475569;
        }

        .verify-cert-info strong {
            color: #1e293b;
        }

        .verify-cert-info i {
            color: #E75A9B;
            margin-right: 6px;
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

        .nav-badge {
            margin-left: auto;
            font-size: 0.65rem;
            background: rgba(178, 110, 167, 0.25);
            padding: 2px 8px;
            border-radius: 30px;
            color: #D4CFE8;
        }

        .footer-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid rgba(255,255,255,0.2);
        }

        .admin-name {
            font-size: 0.8rem;
            font-weight: 600;
            color: white;
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
        }

        .logout-icon:hover {
            background: rgba(220, 38, 38, 0.4);
            color: white;
            transform: scale(1.05);
        }

        /* Main Content - FIXED (removed duplicate) */
        .main-content {
            margin-left: 230px;
            padding: 20px 24px;
            transition: margin-left 0.3s ease;
            height: 100vh;
            overflow-y: auto;
            scroll-behavior: smooth;
        }

        .main-content::-webkit-scrollbar { width: 8px; }
        .main-content::-webkit-scrollbar-track { background: #f1f1f1; border-radius: 10px; }
        .main-content::-webkit-scrollbar-thumb { background: #E75A9B; border-radius: 10px; }

        .top-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 16px;
            margin-bottom: 24px;
            padding-bottom: 16px;
        }

        .page-title h1 {
            font-size: 1.4rem;
            font-weight: 700;
            color: #302E63;
        }

        .page-title p {
            font-size: 0.75rem;
            color: #7B6E8F;
            margin-top: 4px;
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
            position: relative;
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
        /* Expanded Image Viewer */
.image-expand-modal {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.95);
    z-index: 3000;
    display: flex;
    align-items: center;
    justify-content: center;
    visibility: hidden;
    opacity: 0;
    transition: all 0.3s ease;
    cursor: pointer;
}

.image-expand-modal.active {
    visibility: visible;
    opacity: 1;
}

.expanded-image {
    max-width: 90%;
    max-height: 90%;
    object-fit: contain;
    border-radius: 8px;
    box-shadow: 0 8px 32px rgba(0,0,0,0.3);
}

.expand-close {
    position: absolute;
    top: 20px;
    right: 30px;
    font-size: 40px;
    color: white;
    cursor: pointer;
    background: none;
    border: none;
    z-index: 3001;
}

.expand-close:hover {
    color: #E75A9B;
}
        .relative { position: relative; }

        .dropdown {
    position: absolute;
    top: calc(100% + 10px);
    right: 0;
    width: 200px;
    background: white;
    border-radius: 14px;
    overflow: hidden;
    display: none;
    border: 1px solid #E4DCF0;
    box-shadow: 0 15px 35px rgba(0,0,0,0.15);
    z-index: 1000;
}

.dropdown a {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 16px;
    text-decoration: none;
    color: #1E1B2E;
    font-size: 13px;
    font-weight: 500;
    transition: background 0.2s;
}

.dropdown a:hover { 
    background: #F4F0F8; 
}

.dropdown hr { 
    margin: 0; 
    border-color: #E4DCF0; 
}

        .dropdown a:hover { background: #F4F0F8; }
        .dropdown hr { margin: 0; border-color: #E4DCF0; }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 28px;
        }

        .stat-card {
            background: white;
            border-radius: 20px;
            padding: 18px;
            border: 1px solid #E4DCF0;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(48, 46, 99, 0.08);
        }

        .stat-left {
            display: flex;
            align-items: center;
            gap: 12px;
            flex: 1;
        }

        .stat-icon {
            width: 44px;
            height: 44px;
            background: rgba(135, 93, 156, 0.1);
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .stat-icon i {
            font-size: 22px;
            color: #875D9C;
        }

        .stat-info {
            display: flex;
            flex-direction: column;
        }

        .stat-label {
            font-size: 0.7rem;
            font-weight: 600;
            color: #7B6E8F;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .stat-sub {
            font-size: 0.65rem;
            color: #A59BB5;
            margin-top: 4px;
        }

        .stat-right {
            text-align: right;
        }

        .stat-value {
            font-size: 26px;
            font-weight: 800;
            color: #302E63;
            line-height: 1.2;
        }

        /* Filter Bar */
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
            min-width: 200px;
            display: flex;
            align-items: center;
            gap: 10px;
            background: #f8f9fa;
            padding: 10px 16px;
            border-radius: 40px;
            border: 1px solid #e2e8f0;
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
            cursor: pointer;
            font-size: 13px;
        }

        .btn-filter, .btn-reset {
            background: #E75A9B;
            color: white;
            border: none;
            padding: 10px 24px;
            border-radius: 40px;
            cursor: pointer;
            font-weight: 600;
            font-size: 13px;
            text-decoration: none;
        }

        .btn-reset { background: #64748b; }

       /* Tab Bar */
.tab-bar {
    justify-content: center;
    display: flex;
    gap: 8px;
    margin-bottom: 20px;
    text-align: center;
    background: #ddc9e3;
    border-radius: 12px;
    padding: 6px;
}

.tab-btn {
    padding: 10px 24px;
    background: none;
    border: none;
    font-weight: 600;
    cursor: pointer;
    color: #64748b;
    position: relative;
    font-size: 13px;
    border-radius: 10px;
    transition: all 0.2s;
}

.tab-btn.active {
    background: white;
    color: #1d3156;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
}

.tab-btn:hover:not(.active) {
    color: #1d3156;
}

/* Remove the underline style */
.tab-btn.active::after {
    display: none;
}

        .tab-content { display: none; }
        .tab-content.active { display: block; }

        /* Tables */
        .certificates-table, .qualifications-table {
            background: white;
            border-radius: 20px;
            overflow-x: auto;
            width: 100%;
            border-collapse: collapse;
        }

        .certificates-table th, .qualifications-table th {
            padding: 14px 16px;
            text-align: left;
            background: #f8f8f8;
            font-size: 12px;
            font-weight: 700;
            color: #302E63;
            border-bottom: 1px solid #e0e0e0;
        }

        .certificates-table td, .qualifications-table td {
            padding: 14px 16px;
            border-bottom: 1px solid #eef2f7;
            font-size: 13px;
            color: #475569;
            vertical-align: middle;
        }

        .certificates-table tr:hover td, .qualifications-table tr:hover td {
            background: #fafcff;
        }

        /* Badges */
        .badge-approved {
            background: #d4edda;
            color: #155724;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            display: inline-block;
        }
        .badge-pending {
            background: #fff3cd;
            color: #856404;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            display: inline-block;
        }
        .badge-rejected {
            background: #f8d7da;
            color: #721c24;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            display: inline-block;
        }

        /* Buttons */
        .btn-view, .btn-approve, .btn-reject, .btn-edit, .btn-delete {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            cursor: pointer;
            border: none;
            margin: 2px;
            transition: 0.2s;
        }
        .btn-view-cert {
            transition: all 0.2s;
        }

        .btn-view-cert:hover {
            background: #cbd5e1 !important;
            transform: translateY(-1px);
        }
        .btn-view { background: #e2e8f0; color: #1d3156; }
        .btn-view:hover { background: #cbd5e1; }
        .btn-approve { background: #d4edda; color: #28a745; }
        .btn-approve:hover { background: #a3d4a8; }
        .btn-reject { background: #f8d7da; color: #dc2626; }
        .btn-reject:hover { background: #f5c6cb; }
        .btn-edit { background: #fef3c7; color: #f59e0b; }
        .btn-edit:hover { background: #fde68a; }
        .btn-delete { background: #fee2e2; color: #dc2626; }
        .btn-delete:hover { background: #fecaca; }

        /* Alert */
        .alert-success {
            background: #d4edda;
            color: #155724;
            border-left: 4px solid #28a745;
            padding: 12px 16px;
            border-radius: 12px;
            margin-bottom: 20px;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px;
            background: white;
            border-radius: 20px;
            color: #94a3b8;
        }
        .empty-state i { font-size: 48px; margin-bottom: 16px; display: block; }

        /* Modal */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.7);
            z-index: 2000;
            display: flex;
            align-items: center;
            justify-content: center;
            visibility: hidden;
            opacity: 0;
            transition: all 0.3s ease;
        }
        .modal-overlay.active { visibility: visible; opacity: 1; }
        #certificateViewerModal.modal-overlay {
            z-index: 3000;
        }
        .modal-container {
            background: white;
            border-radius: 24px;
            width: 90%;
            max-width: 800px;
            max-height: 85vh;
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px 24px;
            border-bottom: 1px solid #e2e8f0;
            background: #f8fafc;
        }
        .modal-header h3 { font-size: 1.2rem; font-weight: 700; color: #1a1a3e; display: flex; align-items: center; gap: 10px; }
        
        .modal-close {
            background: none;
            border: none;
            font-size: 28px;
            cursor: pointer;
            color: #94a3b8;
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .modal-close:hover { background: #fee2e2; color: #dc2626; }

        .modal-body { padding: 24px; overflow-y: auto; flex: 1; }

        /* Certificate Viewer */
        .certificate-viewer { text-align: center; }
        .certificate-image {
            max-width: 100%;
            max-height: 60vh;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        .certificate-info {
            margin-top: 20px;
            padding: 16px;
            background: #f8fafc;
            border-radius: 16px;
            text-align: left;
            justify-content: center;
        }
        .certificate-info p { margin: 8px 0; font-size: 13px; }
        .certificate-info strong { color: #1a1a3e; width: 200px; display: inline-block; }

        /* Form */
        .form-group { margin-bottom: 18px; }
        .form-group label { display: block; font-weight: 600; font-size: 12px; margin-bottom: 6px; color: #1a1a3e; }
        .form-group input, .form-group textarea, .form-group select {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid #cbd5e1;
            border-radius: 12px;
            font-family: inherit;
            font-size: 13px;
        }

        .modal-buttons {
            display: flex;
            gap: 12px;
            justify-content: center;
            margin-top: 20px;
            padding-top: 16px;
            padding-bottom: 20px;
            border-top: 1px solid #e2e8f0;
        }
        .btn-cancel { background: #e2e8f0; color: #475569; padding: 10px 20px; border-radius: 30px; border: none; cursor: pointer; font-weight: 600; }
        .btn-save { background: #28a745; color: white; padding: 10px 20px; border-radius: 30px; border: none; cursor: pointer; font-weight: 600; }

        /* Sidebar Overlay */
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
        .sidebar-overlay.active { display: block; }

        /* Mobile Responsive */
        @media (max-width: 768px) {
            .menu-toggle{display:block;}
            .sidebar { transform: translateX(-100%); }
            .sidebar.open { transform: translateX(0); }
            .main-content { margin-left: 0; padding: 16px; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); gap: 12px; }
            .stat-card { padding: 14px; }
            .stat-value { font-size: 22px; }
            .stat-icon { width: 38px; height: 38px; }
            .stat-icon i { font-size: 18px; }
            .filter-bar { flex-direction: column; align-items: stretch; }
            .search-box { width: 100%; }
            .btn-filter, .btn-reset { text-align: center; }
            .certificates-table, .qualifications-table { font-size: 12px; }
            .certificates-table th, .certificates-table td, 
            .qualifications-table th, .qualifications-table td { padding: 8px 10px; }
        }

        @media (max-width: 480px) {
            .stats-grid { grid-template-columns: 1fr; }
            .top-bar { flex-direction: column; align-items: flex-start; }
            .admin-profile { align-self: flex-end; }
            .tab-bar { flex-wrap: wrap; }
            .tab-btn { padding: 8px 16px; font-size: 12px; }
        }

        /* ============================================
   TABLE RESPONSIVE FIXES - MOBILE ONLY
   ============================================ */

@media (max-width: 768px) {
    /* Make both tables scrollable horizontally */
    .certificates-container,
    .qualifications-container {
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
        margin: 0 -16px;
        width: calc(100% + 32px);
        padding: 0 16px;
    }
    
    .certificates-table,
    .qualifications-table {
        min-width: 650px;
        width: max-content;
    }
    
    /* Smaller padding for table cells */
    .certificates-table th,
    .certificates-table td,
    .qualifications-table th,
    .qualifications-table td {
        padding: 10px 12px;
        font-size: 0.75rem;
    }
    
    /* Make action buttons smaller and wrap properly */
    .certificates-table td:last-child,
    .qualifications-table td:last-child {
        white-space: nowrap;
        min-width: 160px;
    }
    
    .btn-view, .btn-approve, .btn-reject, 
    .btn-edit, .btn-delete {
        padding: 4px 8px;
        font-size: 0.65rem;
        margin: 2px;
        display: inline-block;
    }
    
    /* Badge size adjustment */
    .badge-approved, .badge-pending, .badge-rejected {
        padding: 3px 8px;
        font-size: 0.6rem;
        white-space: nowrap;
    }
    
    /* Filter bar improvements */
    .filter-bar {
        flex-direction: column;
        align-items: stretch;
        gap: 10px;
    }
    
    .search-box {
        width: 100%;
    }
    
    .btn-filter, .btn-reset {
        text-align: center;
        width: 100%;
    }
    
    /* Tab bar improvements */
    .tab-bar {
        flex-wrap: wrap;
        gap: 8px;
    }
    
    .tab-btn {
        padding: 8px 16px;
        font-size: 0.75rem;
        flex: 1;
        text-align: center;
    }
    
    /* Empty state padding */
    .empty-state {
        padding: 30px;
    }
    
    .empty-state i {
        font-size: 36px;
    }
    
    /* Qualification table specific */
    .qualifications-table td:first-child,
    .certificates-table td:first-child {
        min-width: 120px;
    }
}

/* Emergency fix for sidebar toggle */
.menu-toggle {
    display: block !important;
    background: #272754;
    color: white;
    border: none;
    width: 42px;
    height: 42px;
    border-radius: 10px;
    cursor: pointer;
    font-size: 1.3rem;
}

@media (min-width: 769px) {
    .menu-toggle {
        display: none !important;
    }
}

.sidebar {
    transform: translateX(-100%);
    transition: transform 0.3s ease;
}

.sidebar.open {
    transform: translateX(0);
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
                <h1>KYOSHI</h1>
                <span class="admin-space-text">Admin Space</span>
            </div>
        </div>
    </div>
    <nav class="nav-menu">
    <!-- DASHBOARD -->
    <div class="nav-section">
        <a href="admin_dashboard.php" class="nav-item">
            <i class="bi bi-speedometer2"></i><span>Dashboard</span>
        </a>
    </div>

    <!-- USERS -->
    <div class="nav-section">
        <div class="nav-section-label">
            USERS
        </div>
        <a href="admin_tutor_actions.php" class="nav-item active">
    <i class="bi bi-person-badge"></i><span>Tutors</span>
            <span class="nav-badge"><?= $totalTutors ?></span>
        </a>
        <a href="admin_student_actions.php" class="nav-item">
            <i class="bi bi-person"></i><span>Students</span>
            <span class="nav-badge"><?= $totalStudents ?></span>
        </a>
    </div>

    <!-- FINANCE -->
    <div class="nav-section">
        <div class="nav-section-label">
            FINANCE
        </div>
        <a href="admin_payments.php" class="nav-item">
            <i class="bi bi-credit-card"></i><span>Payments</span>
            <span class="nav-badge pending"><?= $pendingPayments ?></span>
        </a>
        <a href="admin_payouts.php" class="nav-item">
             <i class="bi bi-cash-stack"></i><span>Payouts</span>
            <span class="nav-badge"><?= $pendingPayouts ?></span>
        </a>
    </div>

    <!-- BOOKINGS -->
    <div class="nav-section">
        <div class="nav-section-label">
            BOOKINGS
        </div>
        <a href="admin_bookings.php" class="nav-item">
            <i class="bi bi-calendar-check"></i><span>Bookings</span>
            <span class="nav-badge"><?= $totalBookings ?></span>
        </a>
        <a href="admin_disputes.php" class="nav-item">
            <i class="bi bi-flag"></i><span>Disputes</span>
            <span class="nav-badge dispute"><?= $pendingDisputes ?></span>
        </a>
    </div>

    <!-- REPORTS -->
    <div class="nav-section">
        <div class="nav-section-label">
            REPORTS
        </div>
        <a href="admin_reports.php" class="nav-item">
            <i class="bi bi-graph-up"></i><span>Analytics</span>
        </a>
    </div>
</nav>
    
    <div class="sidebar-footer">
    <div class="admin-info">
        <img src="<?= e($profilePic) ?>" alt="Admin" class="footer-avatar">
        <div class="admin-details">
            <span class="admin-name"><?= e($displayName) ?></span>
        </div>
    </div>
    <a href="logout.php" class="logout-icon" title="Logout">
        <i class="bi bi-box-arrow-right"></i>
    </a>
</div>
</aside>

<div class="main-content">
<div class="top-bar">
    <button class="menu-toggle" id="menuToggle"><i class="bi bi-list"></i></button>
    
    <!-- Mobile Logo (visible only on mobile) -->
    <div class="mobile-logo">
        <img src="<?= e($assetBase) ?>/logo.png" alt="Kyoshi" class="mobile-logo-img">
        <span class="mobile-logo-text">KYOSHI</span>
    </div>
    
    <!-- Desktop Title with Back Button Beside It -->
    <div class="page-title">
        <div class="title-with-back">
            <a href="admin_tutor_actions.php" class="back-btn-desktop">
                <i class="bi bi-arrow-left"></i>
                <span>Back</span>
            </a>
            <h1>Manage Qualifications</h1>
        </div>
    </div>
    
    <div class="relative">
        <div class="admin-profile" onclick="toggleDropdown()">
            <img src="<?= e($profilePic) ?>" alt="Admin">
            <span><?= e($displayName) ?></span>
            <i class="bi bi-chevron-down"></i>
        </div>
        
        <!-- Mobile Profile Button -->
        <div class="mobile-profile-btn" onclick="toggleDropdown()">
            <img src="<?= e($profilePic) ?>" alt="Admin" class="mobile-profile-img">
        </div>
        
        <div class="dropdown" id="profileDropdown">
            <a href="admin_profile.php"><i class="bi bi-person-circle"></i> My Profile</a>
            <hr>
            <a href="logout.php" style="color:#dc2626;"><i class="bi bi-box-arrow-right"></i> Logout</a>
        </div>
    </div>
</div>

<!-- Mobile Page Header with Arrow Only (no text) -->
<div class="mobile-page-header" style="margin-top: 20px;">
    <div class="mobile-title-with-back">
        <a href="admin_tutor_actions.php" class="mobile-back-arrow">
            <i class="bi bi-arrow-left"></i>
        </a>
        <h1 class="mobile-page-title">Manage Qualifications</h1>
    </div>
</div>

    <?php if (isset($_SESSION['success_message'])): ?>
        <div class="alert alert-success" id="successAlert">
            <i class="bi bi-check-circle"></i> <?= $_SESSION['success_message'] ?>
        </div>
        <?php unset($_SESSION['success_message']); ?>
    <?php endif; ?>

    <div class="filter-bar">
    <div class="search-box">
        <i class="bi bi-search"></i>
        <input type="text" id="searchInput" placeholder="Search by tutor name or qualification..." value="<?= e($search) ?>">
    </div>
    <button class="btn-filter" onclick="applyFilters()"><i class="bi bi-search"></i> Apply</button>
    <a href="admin_manage_qualifications.php?tab=<?= $tab ?>" class="btn-reset">
    <i class="bi bi-x-circle"></i> Reset
</a>
</div>
    <div class="tab-bar">
        <button class="tab-btn <?= $tab == 'certificates' ? 'active' : '' ?>" onclick="switchTab('certificates')">
            <i class="bi bi-file-earmark-text"></i> Qualification Requests <?= $pendingCertificates > 0 ? "<span style='background:#E75A9B; color:white; padding:2px 8px; border-radius:20px; margin-left:5px;'>$pendingCertificates</span>" : '' ?>
        </button>
        <button class="tab-btn <?= $tab == 'qualifications' ? 'active' : '' ?>" onclick="switchTab('qualifications')">
            <i class="bi bi-award"></i> All Qualifications
        </button>
    </div>

    <!-- Certificates Tab -->
    <div id="certificatesTab" class="tab-content <?= $tab == 'certificates' ? 'active' : '' ?>">
        <div class="certificates-container">
            <?php if ($certificates->num_rows == 0): ?>
                <div class="empty-state">
                    <i class="bi bi-file-earmark-text" style="font-size: 48px;"></i>
                    <p>No certificate requests found.</p>
                </div>
            <?php else: ?>
                <table class="certificates-table">
                    <thead>
                        <tr>
                            <th>Tutor</th>
                            <th>Qualification Name</th>
                            <th>Uploaded on</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($cert = $certificates->fetch_assoc()): ?>
                        <tr>
                            <td>
                                <strong><?= e($cert['tutor_name']) ?></strong><br>
                                <small><?= e($cert['tutor_email']) ?></small>
                            </td>
                            <td><?= e($cert['certificate_name']) ?></td>
                            <td><?= date('d M Y', strtotime($cert['uploaded_at'])) ?></td>
                            <td><?= getStatusBadge($cert['status']) ?></td>
                            <td>
                                <button class="btn-view" onclick="viewCertificateModal('<?= e($cert['file_path']) ?>', '<?= e(addslashes($cert['certificate_name'])) ?>', '<?= e(addslashes($cert['tutor_name'])) ?>', '<?= date('d M Y', strtotime($cert['uploaded_at'])) ?>')"><i class="bi bi-eye"></i> View Certificate </button>
                                <?php if ($cert['status'] == 'pending'): ?>
                                   <button class="btn-approve" onclick="verifyCertificate(<?= $cert['id'] ?>, 'approve', event)"><i class="bi bi-check-lg"></i> Approve</button>
<button class="btn-reject" onclick="verifyCertificate(<?= $cert['id'] ?>, 'reject', event)"><i class="bi bi-x-lg"></i> Reject</button>
                                <?php endif; ?>
                              </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <!-- Qualifications Tab -->
    <div id="qualificationsTab" class="tab-content <?= $tab == 'qualifications' ? 'active' : '' ?>">
        <div style="margin-bottom: 20px; text-align: right;">
            <button class="btn-filter" onclick="openAddQualificationModal()"><i class="bi bi-plus-circle"></i> Add Qualification</button>
        </div>
        
        <div class="qualifications-container">
            <?php if ($qualifications->num_rows == 0): ?>
                <div class="empty-state">
                    <i class="bi bi-award" style="font-size: 48px;"></i>
                    <p>No qualifications found.</p>
                </div>
            <?php else: ?>
                <table class="qualifications-table">
    <thead>
        <tr>
            <th>Tutor</th>
            <th>Qualification</th>
            <th>Added on</th>
            <th>Status</th>
            <th>Actions</th>
        </tr>
    </thead>
    <tbody>
        <?php while ($qual = $qualifications->fetch_assoc()): ?>
        <tr>
            <td>
                <strong><?= e($qual['tutor_name']) ?></strong><br>
                <small><?= e($qual['tutor_email']) ?></small>
             </td>
            <td><?= e($qual['qualification_name']) ?></td>
            <td><?= date('d M Y', strtotime($qual['created_at'])) ?></td>
            <td><?= getStatusBadge($qual['status'] ?? 'approved') ?></td>
            <td>
                <button class="btn-edit" onclick="editQualification(<?= $qual['id'] ?>, '<?= e(addslashes($qual['qualification_name'])) ?>')"><i class="bi bi-pencil"></i> Edit</button>
                <button class="btn-delete" onclick="deleteQualification(<?= $qual['id'] ?>, '<?= e(addslashes($qual['qualification_name'])) ?>')"><i class="bi bi-trash"></i> Delete</button>
            </td>
        </tr>
        <?php endwhile; ?>
    </tbody>
</table>
            <?php endif; ?>
        </div>
    </div>
</div>

<div id="certificateViewerModal" class="modal-overlay">
    <div class="modal-container">
        <div class="modal-header">
            <h3><i class="bi bi-file-earmark-text"></i> Certificate Viewer</h3>
            <button class="modal-close" onclick="closeCertificateViewer()">&times;</button>
        </div>
        <div class="modal-body">
            <div class="certificate-viewer">
                <img id="certificateImage" src="" alt="Certificate" class="certificate-image" style="cursor: pointer;">
                <div style="margin-top: 12px; text-align: center;">
                    <small style="color: #64748b;"><i class="bi bi-info-circle"></i> Click on the image to enlarge</small>
                </div>
                <div class="certificate-info">
                    <p><strong>Certificate Name:</strong> <span id="certName"></span></p>
                    <p><strong>Uploaded by </strong> <span id="certTutor"></span></p>
                    <p><strong>Uploaded on </strong> <span id="certDate"></span></p>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Expanded Image Viewer Modal -->
<div id="imageExpandModal" class="image-expand-modal" onclick="closeExpandImage()">
    <button class="expand-close" onclick="closeExpandImage()">&times;</button>
    <img id="expandedImage" src="" alt="Expanded Certificate" class="expanded-image">
</div>

<!-- Add/Edit Qualification Modal -->
<div id="qualificationModal" class="modal-overlay">
    <div class="modal-container">
        <div class="modal-header">
            <h3 id="modalTitle"><i class="bi bi-plus-circle"></i> Add Qualification</h3>
            <button class="modal-close" onclick="closeModal()">&times;</button>
        </div>
        <form method="POST" action="" id="qualificationForm">
            <input type="hidden" name="add_qualification" id="formAction" value="1">
            <input type="hidden" name="qualification_id" id="qualificationId">
            
            <div class="modal-body">
                <div class="form-group">
                    <label>Select Tutor</label>
                    <select name="tutor_id" id="tutorId" required>
                        <option value="">Select a tutor...</option>
                        <?php 
                        $tutors->data_seek(0);
                        while ($tutor = $tutors->fetch_assoc()): ?>
                            <option value="<?= $tutor['id'] ?>"><?= e($tutor['fullname']) ?> (<?= e($tutor['email']) ?>)</option>
                        <?php endwhile; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Qualification Name</label>
                    <input type="text" name="qualification_name" id="qualificationName" required placeholder="e.g., HSK Level 5, TESOL Certified">
                </div>
            </div>
            
            <div class="modal-buttons">
                <button type="button" class="btn-cancel" onclick="closeModal()">Cancel</button>
                <button type="submit" class="btn-save">Save</button>
            </div>
        </form>
    </div>
</div>
<!-- Verify Certificate Modal -->
<div id="verifyModal" class="modal-overlay">
    <div class="modal-container">
        <div class="modal-header">
            <h3 id="verifyModalTitle"><i class="bi bi-check-circle"></i> Verify Certificate</h3>
            <button class="modal-close" onclick="closeVerifyModal()">&times;</button>
        </div>
        <form method="POST" action="">
            <input type="hidden" name="verify_certificate" value="1">
            <input type="hidden" name="certificate_id" id="verifyCertId">
            <input type="hidden" name="verify_action" id="verifyAction">
            <!-- ADD THESE HIDDEN INPUTS -->
            <input type="hidden" id="verifyCertFilePath">
            <input type="hidden" id="verifyCertNameHidden">
            <input type="hidden" id="verifyTutorNameHidden">
            <input type="hidden" id="verifyUploadDateHidden">
            
            <div class="modal-body">
                <!-- Certificate Info Preview -->
                <div class="verify-cert-info">
                    <p><i class="bi bi-award"></i> <strong>Qualification Name:</strong> <span id="verifyCertName">-</span></p>
                    <p><i class="bi bi-person"></i> <strong>Tutor Name:</strong> <span id="verifyTutorName">-</span></p>
                    <p><i class="bi bi-calendar"></i> <strong>Uploaded On</strong> <span id="verifyUploadDate">-</span></p>
                </div>
                <div style="margin-bottom: 20px;">
                    <button type="button" class="btn-view-cert" onclick="viewCertificateFromModal()" style="background: #e2e8f0; color: #1d3156; padding: 10px 20px; border-radius: 40px; border: none; cursor: pointer; font-weight: 600; width: 100%;">
                        <i class="bi bi-eye"></i> View Certificate
                    </button>
                </div>
                
                <div class="form-group">
                    <label><i class="bi bi-pencil-square"></i> Admin Notes (Optional)</label>
                    <textarea name="admin_notes" id="adminNotes" rows="3" placeholder="Add any notes about this verification..."></textarea>
                </div>
            </div>
            
            <div class="modal-buttons">
                <button type="button" class="btn-cancel" onclick="closeVerifyModal()">Cancel</button>
                <button type="submit" class="btn-save" id="verifySubmitBtn" onclick="return validateAndSubmit()">Confirm</button>
            </div>
        </form>
    </div>
</div>

<script>
function toggleDropdown() {
    const dropdown = document.getElementById('profileDropdown');
    if (!dropdown) return;
    
    if (dropdown.style.display === 'block') {
        dropdown.style.display = 'none';
        dropdown.classList.remove('show');
    } else {
        dropdown.style.display = 'block';
        dropdown.classList.add('show');
    }
}

// Close dropdown when clicking outside
document.addEventListener('click', function(e) {
    const dropdown = document.getElementById('profileDropdown');
    const mobileProfileBtn = document.querySelector('.mobile-profile-btn');
    const desktopProfile = document.querySelector('.admin-profile');
    
    if (!dropdown) return;
    
    const isClickOnMobileBtn = mobileProfileBtn && mobileProfileBtn.contains(e.target);
    const isClickOnDesktop = desktopProfile && desktopProfile.contains(e.target);
    const isClickInsideDropdown = dropdown.contains(e.target);
    
    if (!isClickOnMobileBtn && !isClickOnDesktop && !isClickInsideDropdown) {
        dropdown.style.display = 'none';
        dropdown.classList.remove('show');
    }
});

// Prevent dropdown from closing when clicking inside it
const dropdownEl = document.getElementById('profileDropdown');
if (dropdownEl) {
    dropdownEl.addEventListener('click', function(e) {
        e.stopPropagation();
    });
}

// Close dropdown on Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        const dropdown = document.getElementById('profileDropdown');
        if (dropdown) {
            dropdown.style.display = 'none';
            dropdown.classList.remove('show');
        }
    }
});


function switchTab(tab) {
    const currentSearch = document.getElementById('searchInput')?.value || '';
    const currentStatus = document.getElementById('statusFilter')?.value || (tab === 'qualifications' ? 'approved' : 'pending');
    
    let url = `admin_manage_qualifications.php?tab=${tab}`;
    
    if (currentSearch && currentSearch.trim() !== '') {
        url += `&search=${encodeURIComponent(currentSearch.trim())}`;
    }
    
    // For qualifications tab, pass status if not 'approved' (default)
    if (tab === 'qualifications') {
        if (currentStatus && currentStatus !== 'approved') {
            url += `&status=${currentStatus}`;
        }
    } else if (tab === 'certificates') {
        url += `&status=pending`;
    }
    
    window.location.href = url;
}

function openExpandImage(imageSrc) {
    const modal = document.getElementById('imageExpandModal');
    const img = document.getElementById('expandedImage');
    img.src = imageSrc;
    modal.classList.add('active');
    document.body.style.overflow = 'hidden';
}

function closeExpandImage() {
    const modal = document.getElementById('imageExpandModal');
    modal.classList.remove('active');
    document.body.style.overflow = '';
}

// Also close with Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeExpandImage();
        closeCertificateViewer();
        closeModal();
        closeVerifyModal();
    }
});

// Mobile menu toggle - FIXED
const menuToggleBtn = document.getElementById('menuToggle');
const sidebarEl = document.getElementById('sidebar');
const overlayEl = document.getElementById('sidebarOverlay');

if (menuToggleBtn) {
    menuToggleBtn.addEventListener('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        console.log('Menu clicked'); // Debug
        sidebarEl.classList.toggle('open');
        if (overlayEl) overlayEl.classList.toggle('active');
        document.body.style.overflow = sidebarEl.classList.contains('open') ? 'hidden' : '';
    });
}

if (overlayEl) {
    overlayEl.addEventListener('click', function() {
        sidebarEl.classList.remove('open');
        overlayEl.classList.remove('active');
        document.body.style.overflow = '';
    });
}
function applyFilters() {
    const search = document.getElementById('searchInput').value;
    const status = document.getElementById('statusFilter')?.value || 'approved';
    const tab = '<?= $tab ?>';
    
    let url = `admin_manage_qualifications.php?tab=${tab}`;
    
    if (search && search.trim() !== '') {
        url += `&search=${encodeURIComponent(search.trim())}`;
    }
    
    if (tab === 'qualifications') {
        if (status && status !== 'approved') {
            url += `&status=${status}`;
        }
    } else if (tab === 'certificates') {
        if (status && status !== 'pending') {
            url += `&status=${status}`;
        }
    }
    
    window.location.href = url;
}

function viewCertificateModal(filePath, certName, tutorName, uploadDate) {
    const modal = document.getElementById('certificateViewerModal');
    const img = document.getElementById('certificateImage');
    const nameSpan = document.getElementById('certName');
    const tutorSpan = document.getElementById('certTutor');
    const dateSpan = document.getElementById('certDate');
    
    // Set the image source
    const fullImagePath = `../uploads/certificates/${filePath}`;
    img.src = fullImagePath;
    nameSpan.textContent = certName;
    tutorSpan.textContent = tutorName;
    dateSpan.textContent = uploadDate;
    
    // Make image clickable to expand
    img.style.cursor = 'pointer';
    img.onclick = function() {
        openExpandImage(fullImagePath);
    };
    
    // Show modal
    modal.classList.add('active');
    document.body.style.overflow = 'hidden';
}

function closeCertificateViewer() {
    const modal = document.getElementById('certificateViewerModal');
    modal.classList.remove('active');
    document.body.style.overflow = '';
}

function viewCertificateFromModal() {
    const filePath = document.getElementById('verifyCertFilePath').value;
    const certName = document.getElementById('verifyCertNameHidden').value;
    const tutorName = document.getElementById('verifyTutorNameHidden').value;
    const uploadDate = document.getElementById('verifyUploadDateHidden').value;
    
    // Reuse existing certificate viewer modal
    viewCertificateModal(filePath, certName, tutorName, uploadDate);
}function verifyCertificate(certId, action, event) {
    // Get certificate data from the row
    const certRow = event?.target?.closest('tr');
    if (certRow) {
        const certName = certRow.querySelector('td:nth-child(2)')?.innerText || 'Certificate';
        const tutorName = certRow.querySelector('td:first-child strong')?.innerText || 'Tutor';
        const uploadDate = certRow.querySelector('td:nth-child(3)')?.innerText || '';
        
        // Get the file path from the view button
        const viewBtn = certRow.querySelector('.btn-view');
        let filePath = '';
        if (viewBtn) {
            const onclickAttr = viewBtn.getAttribute('onclick');
            const match = onclickAttr?.match(/viewCertificateModal\('([^']+)'/);
            if (match && match[1]) {
                filePath = match[1];
            }
        }
        
        // Store in hidden fields for the modal
        document.getElementById('verifyCertName').innerText = certName;
        document.getElementById('verifyTutorName').innerText = tutorName;
        document.getElementById('verifyUploadDate').innerText = uploadDate;
        document.getElementById('verifyCertNameHidden').value = certName;
        document.getElementById('verifyTutorNameHidden').value = tutorName;
        document.getElementById('verifyUploadDateHidden').value = uploadDate;
        document.getElementById('verifyCertFilePath').value = filePath;
    }
    
    document.getElementById('verifyCertId').value = certId;
    document.getElementById('verifyAction').value = action;
    
    const modalTitle = document.getElementById('verifyModalTitle');
    const submitBtn = document.getElementById('verifySubmitBtn');
    const adminNotesField = document.getElementById('adminNotes');
    const notesLabel = document.querySelector('#verifyModal .form-group label');
    
    if (action === 'approve') {
        modalTitle.innerHTML = '<i class="bi bi-check-circle"></i> Approve Certificate & Qualification';
        submitBtn.innerHTML = 'Approve';
        submitBtn.style.background = '#28a745';
        submitBtn.className = 'btn-save approve-mode';
        // Make notes optional for approval
        adminNotesField.removeAttribute('required');
        adminNotesField.placeholder = "Add any notes about this verification... (Optional)";
        notesLabel.innerHTML = '<i class="bi bi-pencil-square"></i> Admin Notes (Optional)';
        notesLabel.style.color = '#1e293b';
    } else {
        modalTitle.innerHTML = '<i class="bi bi-x-circle"></i> Reject Certification & Qualification';
        submitBtn.innerHTML = 'Reject';
        submitBtn.style.background = '#dc2626';
        submitBtn.className = 'btn-save reject-mode';
        // Make notes required for rejection
        adminNotesField.setAttribute('required', 'required');
        adminNotesField.placeholder = "Please provide a reason for rejection... (Required)";
        notesLabel.innerHTML = '<i class="bi bi-exclamation-triangle-fill"></i> Reason for Rejection <span style="color: #dc2626;">*</span>';
        notesLabel.style.color = '#dc2626';
    }
    
    // Clear previous notes
    adminNotesField.value = '';
    
    document.getElementById('verifyModal').classList.add('active');
}

function closeVerifyModal() {
    document.getElementById('verifyModal').classList.remove('active');
    document.getElementById('adminNotes').value = '';
}
function openAddQualificationModal() {
    document.getElementById('modalTitle').innerHTML = '<i class="bi bi-plus-circle"></i> Add Qualification';
    document.getElementById('formAction').value = 'add_qualification';
    document.getElementById('qualificationId').value = '';
    document.getElementById('qualificationName').value = '';
    document.getElementById('tutorId').value = '';
    
    // Show the tutor select field when adding
    const tutorSelect = document.getElementById('tutorId');
    const tutorLabel = tutorSelect.closest('.form-group');
    tutorLabel.style.display = 'block';
    
    document.getElementById('qualificationModal').classList.add('active');
}

function editQualification(id, name) {
    document.getElementById('modalTitle').innerHTML = '<i class="bi bi-pencil"></i> Edit Qualification';
    document.getElementById('formAction').value = 'edit_qualification';
    document.getElementById('qualificationId').value = id;
    document.getElementById('qualificationName').value = name;
    
    // Hide the tutor select field when editing
    const tutorSelect = document.getElementById('tutorId');
    const tutorLabel = tutorSelect.closest('.form-group');
    tutorLabel.style.display = 'none';
    
    document.getElementById('qualificationModal').classList.add('active');
}

function deleteQualification(id, name) {
    Swal.fire({
        title: 'Delete Qualification?',
        html: `Are you sure you want to delete "<strong>${name}</strong>"?`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc2626',
        confirmButtonText: 'Yes, Delete'
    }).then((result) => {
        if (result.isConfirmed) {
            window.location.href = `admin_manage_qualifications.php?delete_qualification=${id}`;
        }
    });
}

function deleteCertificate(id) {
    Swal.fire({
        title: 'Delete Certificate?',
        text: 'This action cannot be undone.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc2626',
        confirmButtonText: 'Yes, Delete'
    }).then((result) => {
        if (result.isConfirmed) {
            window.location.href = `admin_manage_qualifications.php?delete_certificate=${id}`;
        }
    });
}

// Add this function to validate before form submit
function validateAndSubmit() {
    const action = document.getElementById('verifyAction').value;
    const adminNotes = document.getElementById('adminNotes').value.trim();
    
    if (action === 'reject' && !adminNotes) {
        Swal.fire({
            title: 'Reason Required',
            text: 'Please provide a reason for rejecting this certificate. This will help the tutor understand what needs to be improved.',
            icon: 'warning',
            confirmButtonColor: '#dc2626',
            confirmButtonText: 'OK'
        });
        return false;
    }
    return true;
}

function closeModal() {
    document.getElementById('qualificationModal').classList.remove('active');
    document.getElementById('tutorId').style.display = 'block';
    document.getElementById('tutorId').setAttribute('required', 'required');
}

// Auto-dismiss alert
setTimeout(() => {
    const alert = document.getElementById('successAlert');
    if (alert) alert.style.opacity = '0';
    setTimeout(() => { if(alert) alert.remove(); }, 500);
}, 3000);

// Close modals when clicking outside
window.onclick = function(event) {
    const certModal = document.getElementById('certificateViewerModal');
    const qualModal = document.getElementById('qualificationModal');
    const verifyModal = document.getElementById('verifyModal');
    
    if (event.target === certModal) closeCertificateViewer();
    if (event.target === qualModal) closeModal();
    if (event.target === verifyModal) closeVerifyModal();
}

// Escape key to close modals
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeCertificateViewer();
        closeModal();
        closeVerifyModal();
    }
});
</script>
<script>
history.pushState(null, null, location.href);
window.addEventListener('popstate', function() {
    window.location.href = 'login.php';
});
</script>
</body>
</html>