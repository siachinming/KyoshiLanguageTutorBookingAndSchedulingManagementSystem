<?php
session_start();
include 'config.php';
include 'send_payout_email.php';
include 'check_login.php';
require_once '../vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
 
    // Remove the processing_payout lock — it was causing permanent lockout on crashes
    // If you need idempotency, use a nonce tied to the form instead
 if (isset($_POST['update_bank_account'])) {
    $payout_id = intval($_POST['payout_id']);
    $new_bank_name = trim($_POST['new_bank_name'] ?? '');
    $new_account_number = trim($_POST['new_account_number'] ?? '');
    $new_account_name = trim($_POST['new_account_name'] ?? '');
    $update_reason = trim($_POST['update_reason'] ?? '');
 
    if (empty($new_bank_name) || empty($new_account_number) || empty($new_account_name)) {
        $_SESSION['error_message'] = "All bank account fields are required.";
        header("Location: admin_payouts.php");
        exit();
    }
 
    // Check if payout exists and is in approved status
    $checkStmt = $conn->prepare("
        SELECT p.*, u.email as tutor_email, u.fullname as tutor_name
        FROM payout_requests p
        JOIN users u ON p.tutor_id = u.id
        WHERE p.id = ? AND p.status = 'approved'
    ");
    $checkStmt->bind_param("i", $payout_id);
    $checkStmt->execute();
    $payout = $checkStmt->get_result()->fetch_assoc();
 
    if (!$payout) {
        $_SESSION['error_message'] = "Payout not found or not in approved status.";
        header("Location: admin_payouts.php");
        exit();
    }
 
    $old_bank = $payout['bank_name'];
    $old_account_masked = '****' . substr($payout['bank_account_number'], -4);
    $old_account_name = $payout['bank_account_name'];
 
    // Update ONLY the payout_requests table
    $updateBankStmt = $conn->prepare("
        UPDATE payout_requests 
        SET bank_name = ?, bank_account_number = ?, bank_account_name = ? 
        WHERE id = ? AND status = 'approved'
    ");
    $updateBankStmt->bind_param("sssi", $new_bank_name, $new_account_number, $new_account_name, $payout_id);
    $updateBankStmt->execute();
 
    // Add note to payout admin notes
    $note = "\n[Bank Account Updated on " . date('Y-m-d H:i:s') . " by {$admin['fullname']}]\n";
    $note .= "Reason: {$update_reason}\n";
    $note .= "Old Bank: {$old_bank}\n";
    $note .= "Old Account: {$old_account_masked}\n";
    $note .= "Old Account Name: {$old_account_name}\n";
    $note .= "New Bank: {$new_bank_name}\n";
    $note .= "New Account: ****" . substr($new_account_number, -4) . "\n";
    $note .= "New Account Name: {$new_account_name}\n";
 
    $updateNotesStmt = $conn->prepare("UPDATE payout_requests SET admin_notes = CONCAT(IFNULL(admin_notes, ''), ?) WHERE id = ?");
    $updateNotesStmt->bind_param("si", $note, $payout_id);
    $updateNotesStmt->execute();
 
    $_SESSION['success_message'] = "Bank account updated successfully!";
    header("Location: admin_payouts.php");
    exit();
}
 
    // =============================================
    // APPROVE PAYOUT
    // =============================================
    elseif (isset($_POST['payout_action']) && $_POST['payout_action'] === 'approve') {
        $payout_id = intval($_POST['payout_id']);
        $admin_notes = trim($_POST['admin_notes'] ?? '');
 
        // Use prepared statement — no raw string queries
        $payoutStmt = $conn->prepare("SELECT * FROM payout_requests WHERE id = ? AND status = 'pending'");
        $payoutStmt->bind_param("i", $payout_id);
        $payoutStmt->execute();
        $payout = $payoutStmt->get_result()->fetch_assoc();
 
        if (!$payout) {
            $_SESSION['error_message'] = "Payout not found or already processed.";
            header("Location: admin_payouts.php");
            exit();
        }
 
        if ($payout['amount'] < 50) {
            $_SESSION['error_message'] = "Payout amount below minimum RM50.";
            header("Location: admin_payouts.php");
            exit();
        }
 
        $stmt = $conn->prepare("UPDATE payout_requests SET status = 'approved', admin_notes = ?, processed_at = NOW(), processed_by = ? WHERE id = ? AND status = 'pending'");
        $stmt->bind_param("sii", $admin_notes, $adminID, $payout_id);
 
        if ($stmt->execute() && $stmt->affected_rows > 0) {
            sendPayoutNotification($conn, $payout_id, 'approved', null, null, $adminID);
            $_SESSION['success_message'] = "Payout approved! Please transfer within 3 days.";
        } else {
            $_SESSION['error_message'] = "Failed to approve payout. Please try again.";
        }
 
        header("Location: admin_payouts.php");
        exit();
    }
 
    // =============================================
    // COMPLETE PAYOUT — fully using prepared statements
    // =============================================
    elseif (isset($_POST['complete_payout'])) {
        $payout_id = intval($_POST['payout_id']);
        $transaction_ref = trim($_POST['transaction_ref'] ?? '');
        $admin_notes = trim($_POST['admin_notes'] ?? '');
 
        // Check current status with prepared statement
        $checkStmt = $conn->prepare("SELECT status FROM payout_requests WHERE id = ?");
        $checkStmt->bind_param("i", $payout_id);
        $checkStmt->execute();
        $row = $checkStmt->get_result()->fetch_assoc();
 
        if (!$row) {
            $_SESSION['error_message'] = "Payout not found.";
            header("Location: admin_payouts.php");
            exit();
        }
 
        if ($row['status'] !== 'approved') {
            $_SESSION['error_message'] = "Payout status is '{$row['status']}', not 'approved'. Please approve first.";
            header("Location: admin_payouts.php");
            exit();
        }
 
        // Update using prepared statement (fixes the SQL injection vulnerability)
        $updateStmt = $conn->prepare("UPDATE payout_requests SET status = 'completed', transaction_reference = ?, completed_at = NOW(), processed_by = ? WHERE id = ? AND status = 'approved'");
        $updateStmt->bind_param("sii", $transaction_ref, $adminID, $payout_id);
 
        if ($updateStmt->execute() && $updateStmt->affected_rows > 0) {
            // Add note
            $note = "\n[Completed on " . date('Y-m-d H:i:s') . " by {$admin['fullname']}]\n";
            if (!empty($transaction_ref)) $note .= "Transaction Reference: {$transaction_ref}\n";
            if (!empty($admin_notes)) $note .= "Notes: {$admin_notes}\n";
 
            $noteStmt = $conn->prepare("UPDATE payout_requests SET admin_notes = CONCAT(IFNULL(admin_notes, ''), ?) WHERE id = ?");
            $noteStmt->bind_param("si", $note, $payout_id);
            $noteStmt->execute();
 
            sendPayoutNotification($conn, $payout_id, 'completed', $transaction_ref, null, $adminID);
            $_SESSION['success_message'] = "Payout marked as completed! Receipt sent to tutor.";
        } else {
            $_SESSION['error_message'] = "Failed to complete payout. It may already be completed.";
        }
 
        header("Location: admin_payouts.php");
        exit();
    }
 
    // =============================================
    // REJECT PAYOUT
    // =============================================
    elseif (isset($_POST['payout_action']) && $_POST['payout_action'] === 'reject') {
        $payout_id = intval($_POST['payout_id']);
        $admin_notes = trim($_POST['admin_notes'] ?? '');
 
        if (empty($admin_notes)) {
            $_SESSION['error_message'] = "Please provide a reason for rejection.";
            header("Location: admin_payouts.php");
            exit();
        }
 
        $checkStmt = $conn->prepare("SELECT status FROM payout_requests WHERE id = ? AND status = 'pending'");
        $checkStmt->bind_param("i", $payout_id);
        $checkStmt->execute();
 
        if ($checkStmt->get_result()->num_rows === 0) {
            $_SESSION['error_message'] = "Payout not found or already processed.";
            header("Location: admin_payouts.php");
            exit();
        }
 
        $stmt = $conn->prepare("UPDATE payout_requests SET status = 'rejected', admin_notes = ?, processed_at = NOW(), processed_by = ? WHERE id = ? AND status = 'pending'");
        $stmt->bind_param("sii", $admin_notes, $adminID, $payout_id);
 
        if ($stmt->execute() && $stmt->affected_rows > 0) {
            sendPayoutNotification($conn, $payout_id, 'rejected', null, $admin_notes, $adminID);
            $_SESSION['success_message'] = "Payout rejected. Tutor notified.";
        } else {
            $_SESSION['error_message'] = "Failed to reject payout. Please try again.";
        }
 
        header("Location: admin_payouts.php");
        exit();
    }
 
    // No matching action — redirect cleanly
    header("Location: admin_payouts.php");
    exit();
}


// Get filter parameters
$status_filter = $_GET['status'] ?? 'all';
$search = $_GET['search'] ?? '';

$payout_sql = "
    SELECT p.*, 
           u.fullname as tutor_name, 
           u.email as tutor_email,
           p.bank_name,
           p.bank_account_number,
           p.bank_account_name
    FROM payout_requests p
    JOIN users u ON p.tutor_id = u.id
    WHERE 1=1
";

if ($status_filter !== 'all') {
    $payout_sql .= " AND p.status = '" . $conn->real_escape_string($status_filter) . "'";
}

if (!empty($search)) {
    $search_like = $conn->real_escape_string($search);
    $payout_sql .= " AND (u.fullname LIKE '%$search_like%' OR u.email LIKE '%$search_like%')";
}

$payout_sql .= " ORDER BY p.requested_at DESC";
$payouts = $conn->query($payout_sql);

// Get counts for badges
$pendingPayouts = $conn->query("SELECT COUNT(*) as count FROM payout_requests WHERE status = 'pending'")->fetch_assoc()['count'];
$approvedPayouts = $conn->query("SELECT COUNT(*) as count FROM payout_requests WHERE status = 'approved'")->fetch_assoc()['count'];
$completedPayouts = $conn->query("SELECT COUNT(*) as count FROM payout_requests WHERE status = 'completed'")->fetch_assoc()['count'];
$rejectedPayouts = $conn->query("SELECT COUNT(*) as count FROM payout_requests WHERE status = 'rejected'")->fetch_assoc()['count'];
$totalPayouts = $pendingPayouts + $approvedPayouts + $completedPayouts + $rejectedPayouts;

// Get total payout amount
$totalAmount = $conn->query("SELECT SUM(amount) as total FROM payout_requests WHERE status = 'completed'")->fetch_assoc()['total'] ?? 0;

// Get counts for sidebar
$totalTutors = $conn->query("SELECT COUNT(*) as count FROM users WHERE role = 'tutor'")->fetch_assoc()['count'];
$totalStudents = $conn->query("SELECT COUNT(*) as count FROM users WHERE role = 'student'")->fetch_assoc()['count'];
$totalBookings = $conn->query("SELECT COUNT(*) as count FROM bookings")->fetch_assoc()['count'];
$pendingPayments = $conn->query("SELECT COUNT(*) as count FROM payments WHERE status = 'pending'")->fetch_assoc()['count'];
$pendingDisputes = $conn->query("SELECT COUNT(*) as count FROM disputes WHERE status = 'pending'")->fetch_assoc()['count'];
$pendingQualifications = $conn->query("
    SELECT COUNT(*) as count 
    FROM tutor_certificates tc
    JOIN users u ON tc.tutor_id = u.id
    WHERE tc.status = 'pending' AND u.status = 'approved'
")->fetch_assoc()['count'];
$totalReviews = $conn->query("SELECT COUNT(*) as count FROM ratings")->fetch_assoc()['count'];
$pendingReports = $conn->query("SELECT COUNT(*) as count FROM session_reports WHERE report_status = 'submitted'")->fetch_assoc()['count'];

function e($value) {
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function getStatusBadge($status) {
    switch ($status) {
        case 'completed':
            return '<span class="badge-completed"><i class="bi bi-check-circle-fill"></i> Completed</span>';
        case 'approved':
            return '<span class="badge-approved"><i class="bi bi-check-circle"></i> Approved</span>';
        case 'pending':
            return '<span class="badge-pending"><i class="bi bi-clock-history"></i> Pending</span>';
        case 'rejected':
            return '<span class="badge-rejected"><i class="bi bi-x-circle"></i> Rejected</span>';
        default:
            return '<span class="badge-pending">Pending</span>';
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Kyoshi | Payout Requests</title>
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
            font-family: 'Montserrat', 'Open Sans', sans-serif;
            background: url('../assets/img/background3.jpg') no-repeat center top;
            background-size: cover;
            min-height: 100vh;
            position: relative;
            color: #1E1B2E;
            line-height: 1.45;
            overflow-x: hidden;
        }
        
        /* Sidebar styles */
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
        
.receipt-modal-header {
    display: flex;
    flex-direction: column;
    border-radius: 24px 24px 0 0;
    overflow: hidden;
}

.receipt-modal-header .receipt-header-top {
    background: #1d3156;
    padding: 25px 25px;  
    display: flex;
    align-items: center;
    justify-content: space-between;
    width: 100%;
    margin-bottom: 40px;
}

.receipt-modal-header .receipt-header-stripe {
    height: 8px;
    background: #E75A9B;
    width: 100%;
    display: block;
    flex-shrink: 0;
}

        .sidebar.closed { transform: translateX(-100%); }
        .sidebar.open { transform: translateX(0); }
        
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
        .payouts-container {
    background: white;
    border-radius: 20px;
    overflow-x: auto;
    box-shadow: 0 4px 12px rgba(0,0,0,0.05);
    margin-bottom: 24px;
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
        

.modal-container {
    background: white;
    border-radius: 24px;
    width: 90%;
    max-width: 500px;
    max-height: 85vh;
    overflow: hidden;
    display: flex;
    flex-direction: column;
}

.modal-body {
    padding: 24px;
    overflow-y: auto;  /* Enable vertical scrolling */
    flex: 1;
    max-height: calc(85vh - 130px); /* Adjust based on header + footer height */
}


.modal-buttons {
    display: flex;
    gap: 12px;
    justify-content: flex-end;
    padding: 16px 24px;
    border-top: 1px solid #e2e8f0;
    flex-shrink: 0;
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
        
        /* Main Content */
        .main-content {
            margin-left: 230px;
            padding: 20px 24px;
            transition: margin-left 0.3s ease;
            height: 100vh;
            overflow-y: auto;
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
            font-size: 1.1rem;
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
            overflow: hidden;
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
        
        /* Stats Cards */
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
        }
        
        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(48, 46, 99, 0.08);
        }
        
        .stat-left {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .stat-icon {
            width: 44px;
            height: 44px;
            background: rgba(135, 93, 156, 0.1);
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .stat-icon i {
            font-size: 22px;
            color: #875D9C;
        }
        
        .stat-info .stat-label {
            font-size: 0.7rem;
            font-weight: 600;
            color: #7B6E8F;
            text-transform: uppercase;
        }
        
        .stat-value {
            font-size: 26px;
            font-weight: 800;
            color: #302E63;
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
        
        .btn-reset {
            background: #64748b;
        }
        
        /* Table Styles */
        .payouts-table {
    width: 100%;
    border-collapse: collapse;
    min-width: 900px;  /* add min-width so it scrolls on small screens */
    overflow-x: auto;
}
        
        .payouts-table th {
            padding: 14px 16px;
            text-align: left;
            background: #f8f8f8;
            font-size: 12px;
            font-weight: 700;
            color: #302E63;
            border-bottom: 1px solid #e0e0e0;
        }
        
        .payouts-table td {
            padding: 14px 16px;
            border-bottom: 1px solid #eef2f7;
            font-size: 13px;
            color: #475569;
            vertical-align: middle;
        }
        
        .payouts-table tr:hover td {
            background: #fafcff;
        }
        
        /* Badges */
        .badge-completed {
            background: #d4edda;
            color: #28a745;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .badge-approved {
            background: #cfe2ff;
            color: #084298;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .badge-pending {
            background: #fff3cd;
            color: #856404;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .badge-rejected {
            background: #f8d7da;
            color: #721c24;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        
        /* Buttons */
        .btn-view, .btn-approve, .btn-reject, .btn-complete {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            cursor: pointer;
            border: none;
            margin: 2px;
            transition: 0.2s;
        }

        .btn-edit-bank {
            background: #E75A9B;
            color: white;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            cursor: pointer;
            border: none;
            margin: 2px;
            transition: 0.2s;
        }

        .btn-edit-bank:hover {
            background: #d44a8a;
            transform: translateY(-1px);
        }
        
        .btn-view {
            background: #e2e8f0;
            color: #1d3156;
        }
        
        .btn-view:hover {
            background: #cbd5e1;
        }
        
        .btn-approve {
            background: #cfe2ff;
            color: #084298;
        }
        
        .btn-approve:hover {
            background: #b8d4e8;
        }
        
        .btn-complete {
            background: #28a745;
            color: white;
        }
        
        .btn-complete:hover {
            background: #218838;
        }
        
        .btn-reject {
            background: #f8d7da;
            color: #dc2626;
        }
        
        .btn-reject:hover {
            background: #f5c6cb;
        }
        
        /* Modal */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.7);
            z-index: 9999;
            display: flex;
            align-items: center;
            justify-content: center;
            visibility: hidden;
            opacity: 0;
            transition: all 0.3s ease;
        }
        
        .modal-overlay.active {
            visibility: visible;
            opacity: 1;
        }
        
        .modal-container {
            background: white;
            border-radius: 24px;
            width: 90%;
            max-width: 500px;
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
        
        .modal-header h3 {
            font-size: 1.2rem;
            font-weight: 700;
            color: #1a1a3e;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
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
        
        .modal-close:hover {
            background: #fee2e2;
            color: #dc2626;
        }
        
        .modal-body {
            padding: 24px;
            overflow-y: auto;
            flex: 1;
        }
        
        .form-group {
            margin-bottom: 18px;
        }
        
        .form-group label {
            display: block;
            font-weight: 600;
            font-size: 12px;
            margin-bottom: 6px;
            color: #1a1a3e;
        }
        
        .form-group textarea,
        .form-group input {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid #cbd5e1;
            border-radius: 12px;
            font-family: inherit;
            font-size: 13px;
            resize: vertical;
        }
        
        .modal-buttons {
            display: flex;
            gap: 12px;
            justify-content: flex-end;
            padding: 16px 24px;
            border-top: 1px solid #e2e8f0;
        }
        
        .btn-cancel {
            background: #e2e8f0;
            color: #475569;
            padding: 10px 20px;
            border-radius: 30px;
            border: none;
            cursor: pointer;
            font-weight: 600;
        }
        
        .btn-save {
            background: #28a745;
            color: white;
            padding: 10px 20px;
            border-radius: 30px;
            border: none;
            cursor: pointer;
            font-weight: 600;
        }
        
        .btn-save.reject-mode {
            background: #dc2626;
        }
        
        .alert-success {
            background: #d4edda;
            color: #155724;
            border-left: 4px solid #28a745;
            padding: 12px 16px;
            border-radius: 12px;
            margin-bottom: 20px;
        }
        
        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border-left: 4px solid #dc2626;
            padding: 12px 16px;
            border-radius: 12px;
            margin-bottom: 20px;
        }
        
        .empty-state {
            text-align: center;
            padding: 60px;
            background: white;
            border-radius: 20px;
            color: #94a3b8;
        }
        
        .empty-state i {
            font-size: 48px;
            margin-bottom: 16px;
            display: block;
        }
        
        .report-details {
            background: #f8fafc;
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 20px;
        }
        
        .report-details p {
            margin: 8px 0;
            font-size: 13px;
            line-height: 1.6;
        }
        
        /* Responsive */
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
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 12px;
            }
            
            .filter-bar {
                flex-direction: column;
                align-items: stretch;
            }
            
            .search-box {
                width: 100%;
            }
            
            .payouts-table {
                font-size: 12px;
            }
            
            .payouts-table th,
            .payouts-table td {
                padding: 8px 10px;
            }
        }
        
        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .top-bar {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .admin-profile {
                align-self: flex-end;
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
                <h1>KYOSHI</h1>
                <span class="admin-space-text">Admin Space</span>
            </div>
        </div>
    </div>
    <nav class="nav-menu">
        <div class="nav-section">
            <a href="admin_dashboard.php" class="nav-item">
                <i class="bi bi-speedometer2"></i><span>Dashboard</span>
            </a>
        </div>
        <div class="nav-section">
            <div class="nav-section-label">USERS</div>
            <a href="admin_tutor_actions.php" class="nav-item">
                <i class="bi bi-person-badge"></i><span>Tutors</span>
                <span class="nav-badge"><?= $totalTutors ?></span>
            </a>
            <a href="admin_student_actions.php" class="nav-item">
                <i class="bi bi-person"></i><span>Students</span>
                <span class="nav-badge"><?= $totalStudents ?></span>
            </a>
        </div>
        <div class="nav-section">
            <div class="nav-section-label">FINANCE</div>
            <a href="admin_payments.php" class="nav-item">
                <i class="bi bi-credit-card"></i><span>Payments</span>
                <span class="nav-badge pending"><?= $pendingPayments ?></span>
            </a>
            <a href="admin_payouts.php" class="nav-item active">
                <i class="bi bi-cash-stack"></i><span>Payouts</span>
                <span class="nav-badge"><?= $pendingPayouts ?></span>
            </a>
        </div>
        <div class="nav-section">
            <div class="nav-section-label">BOOKINGS</div>
            <a href="admin_bookings.php" class="nav-item">
                <i class="bi bi-calendar-check"></i><span>Bookings</span>
                <span class="nav-badge"><?= $totalBookings ?></span>
            </a>
            <a href="admin_disputes.php" class="nav-item">
                <i class="bi bi-flag"></i><span>Disputes</span>
                <span class="nav-badge dispute"><?= $pendingDisputes ?></span>
            </a>
        </div>
        <div class="nav-section">
            <div class="nav-section-label">REPORTS</div>
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
        <a href="logout.php" class="logout-icon"><i class="bi bi-box-arrow-right"></i></a>
    </div>
</aside>

<div class="main-content" id="mainContent">
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
            <h1>Payout Requests</h1>
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
        <h1 class="mobile-page-title">Payout Requests</h1>
    </div>
</div>

    <?php if (isset($_SESSION['success_message'])): ?>
        <div class="alert-success" id="successAlert">
            <i class="bi bi-check-circle"></i> <?= $_SESSION['success_message'] ?>
        </div>
        <?php unset($_SESSION['success_message']); ?>
    <?php endif; ?>
    
    <?php if (isset($_SESSION['error_message'])): ?>
        <div class="alert-error" id="errorAlert">
            <i class="bi bi-exclamation-triangle"></i> <?= $_SESSION['error_message'] ?>
        </div>
        <?php unset($_SESSION['error_message']); ?>
    <?php endif; ?>

    <!-- Filter Bar -->
    <div class="filter-bar">
        <div class="search-box">
            <i class="bi bi-search"></i>
            <input type="text" id="searchInput" placeholder="Search by tutor name or email..." value="<?= e($search) ?>">
        </div>
        <select id="statusFilter" class="filter-select">
            <option value="all" <?= $status_filter == 'all' ? 'selected' : '' ?>>All Status</option>
            <option value="pending" <?= $status_filter == 'pending' ? 'selected' : '' ?>>Pending</option>
            <option value="approved" <?= $status_filter == 'approved' ? 'selected' : '' ?>>Approved</option>
            <option value="completed" <?= $status_filter == 'completed' ? 'selected' : '' ?>>Completed</option>
            <option value="rejected" <?= $status_filter == 'rejected' ? 'selected' : '' ?>>Rejected</option>
        </select>
        <button class="btn-filter" onclick="applyFilters()"><i class="bi bi-search"></i> Apply</button>
        <a href="admin_payouts.php" class="btn-reset" style="text-align: center;;"><i class="bi bi-x-circle"></i> Reset</a>
    </div>

    <!-- Payouts Table -->
    <div class="payouts-container">
        <?php if (!$payouts || $payouts->num_rows == 0): ?>
            <div class="empty-state">
                <i class="bi bi-cash-stack"></i>
                <p>No payout requests found.</p>
            </div>
        <?php else: ?>
            <table class="payouts-table">
                <thead>
                    <tr>
                        <th>Tutor</th>
                        <th>Amount</th>
                        <th>Bank Account</th>
                        <th>Requested On</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($payout = $payouts->fetch_assoc()): ?>
                    <tr>
                        <td>
                            <strong><?= e($payout['tutor_name']) ?></strong><br>
                            <small><?= e($payout['tutor_email']) ?></small>
                        </td>
                        <td>
                            <strong style="color: <?= $payout['amount'] < 50 ? '#dc2626' : '#28a745' ?>;">
                                RM <?= number_format($payout['amount'], 2) ?>
                            </strong>
                            <?php if ($payout['amount'] < 50 && $payout['status'] == 'pending'): ?>
                                <span class="badge-rejected" style="background: #fee2e2; color: #dc2626; font-size: 9px; margin-left: 5px;">
                                    Below min (RM50)
                                </span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <small>
                                <strong><?= e($payout['bank_name'] ?? 'N/A') ?></strong><br>
                                <?= e($payout['bank_account_number'] ? '****' . substr($payout['bank_account_number'], -4) : 'N/A') ?><br>
                                <?= e($payout['bank_account_name'] ?? 'N/A') ?>
                            </small>
                        </td>
                        <td><?= date('d M Y, h:i A', strtotime($payout['requested_at'])) ?></td>
                        <td><?= getStatusBadge($payout['status']) ?></td>
                        <td class="action-cell">
                           <?php if ($payout['status'] == 'pending'): ?>
                            <button class="btn-approve" onclick="processPayout(
                                <?= $payout['id'] ?>, 
                                'approve', 
                                <?= $payout['amount'] ?>, 
                                '<?= e(addslashes($payout['tutor_name'])) ?>',
                                '<?= e(addslashes($payout['bank_name'] ?? 'N/A')) ?>',
                                '<?= e(addslashes($payout['bank_account_number'] ? '****' . substr($payout['bank_account_number'], -4) : 'N/A')) ?>',
                                '<?= e(addslashes($payout['bank_account_name'] ?? 'N/A')) ?>'
                            )">
                                <i class="bi bi-check-lg"></i> Approve
                            </button>
                                <button class="btn-reject" onclick="processPayout(
                                    <?= $payout['id'] ?>, 
                                    'reject', 
                                    <?= $payout['amount'] ?>, 
                                    '<?= e(addslashes($payout['tutor_name'])) ?>',
                                    '<?= e(addslashes($payout['bank_name'] ?? 'N/A')) ?>',
                                    '<?= e(addslashes($payout['bank_account_number'] ? '****' . substr($payout['bank_account_number'], -4) : 'N/A')) ?>',
                                    '<?= e(addslashes($payout['bank_account_name'] ?? 'N/A')) ?>'
                                )">
                                    <i class="bi bi-x-lg"></i> Reject
                                </button>
                            <?php elseif ($payout['status'] == 'approved'): ?>
                                <button class="btn-complete" onclick="completePayout(<?= $payout['id'] ?>, <?= $payout['amount'] ?>, '<?= e(addslashes($payout['tutor_name'])) ?>')">
                                    <i class="bi bi-cash-stack"></i> Mark as Transferred
                                </button>
                                <button class="btn-edit-bank" onclick="editBankAccount(<?= $payout['id'] ?>, '<?= e(addslashes($payout['tutor_name'])) ?>', '<?= e(addslashes($payout['bank_name'] ?? 'N/A')) ?>', '<?= e(addslashes($payout['bank_account_number'] ?? '')) ?>')">
                                    <i class="bi bi-pencil-square"></i> Edit Bank
                                </button>
                                <button class="btn-view" onclick="viewDetails(<?= $payout['id'] ?>, '<?= e(addslashes($payout['tutor_name'])) ?>', <?= $payout['amount'] ?>, '<?= e(addslashes($payout['admin_notes'] ?? 'No notes')) ?>', '<?= $payout['status'] ?>', '<?= e(addslashes($payout['bank_name'] ?? 'N/A')) ?>', '<?= e(addslashes($payout['bank_account_number'] ?? '')) ?>', '<?= e(addslashes($payout['bank_account_name'] ?? 'N/A')) ?>')">
                                    <i class="bi bi-eye"></i> View
                                </button>
                            <?php elseif ($payout['status'] == 'completed'): ?>
                                <button class="btn-view" onclick="viewReceipt(
                                    <?= $payout['id'] ?>,
                                    '<?= e(addslashes($payout['tutor_name'])) ?>',
                                    '<?= e(addslashes($payout['tutor_email'])) ?>',
                                    <?= $payout['tutor_id'] ?>,
                                    <?= $payout['amount'] ?>,
                                    '<?= e(addslashes($payout['bank_name'] ?? 'N/A')) ?>',
                                    '<?= e(addslashes($payout['bank_account_number'] ?? 'N/A')) ?>',
                                    '<?= e(addslashes($payout['bank_account_name'] ?? 'N/A')) ?>',
                                    '<?= e(addslashes($payout['transaction_reference'] ?? '')) ?>',
                                    '<?= e(date('d M Y, g:i A', strtotime($payout['requested_at']))) ?>',
                                    '<?= e(date('d M Y, g:i A', strtotime($payout['completed_at'] ?? $payout['processed_at'] ?? 'now'))) ?>'
                                )" style="background: #28a745; color: white;">
                                    <i class="bi bi-receipt"></i> View Receipt
                                </button>
                            <?php else: ?>
                                <button class="btn-view" onclick="viewDetails(<?= $payout['id'] ?>, '<?= e(addslashes($payout['tutor_name'])) ?>', <?= $payout['amount'] ?>, '<?= e(addslashes($payout['admin_notes'] ?? 'No notes')) ?>', '<?= $payout['status'] ?>', '<?= e(addslashes($payout['bank_name'] ?? 'N/A')) ?>', '<?= e(addslashes($payout['bank_account_number'] ?? '')) ?>', '<?= e(addslashes($payout['bank_account_name'] ?? 'N/A')) ?>')">
                                    <i class="bi bi-eye"></i> View Details
                                </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div><!-- Receipt Modal -->
<div id="receiptModal" class="modal-overlay">
    <div class="modal-container" style="max-width: 560px;">
        <div class="receipt-modal-header" style="border-radius: 24px 24px 0 0; overflow: hidden;">
    <div class="receipt-header-top">
        <div style="display: flex; align-items: center; gap: 12px;">
            <img src="../assets/img/logo.png" alt="Kyoshi" style="width: 36px; height: 36px; object-fit: contain;">
            <div>
                <div style="font-size: 1.2rem; font-weight: 800; color: white; letter-spacing: 2px;">KYOSHI</div>
                <div style="font-size: 0.65rem; color: #c8c8e6;">Language Learning Platform</div>
            </div>
        </div>
        <div style="display: flex; align-items: center; gap: 12px;">
            <div style="background: #E75A9B; padding: 6px 14px; border-radius: 6px; text-align: center;">
                <div style="font-size: 0.6rem; font-weight: 700; color: white; letter-spacing: 1px;">PAYOUT</div>
                <div style="font-size: 0.95rem; font-weight: 800; color: white; letter-spacing: 1px;">RECEIPT</div>
            </div>
            <button class="modal-close" onclick="closeReceiptModal()" style="color: white; background: rgba(255,255,255,0.15);">&times;</button>
        </div>
    </div>
    <div class="receipt-header-stripe"></div>
</div>

        <div class="modal-body" id="receiptContent" style="padding: 20px 24px; max-height: 70vh; overflow-y: auto;">
            <!-- filled by JS -->
        </div>

                <div class="modal-buttons">
            <button class="btn-cancel" onclick="closeReceiptModal()">Close</button>
            <button class="btn-save" onclick="downloadReceiptPDF()" style="background: #E75A9B;">
                <i class="bi bi-download"></i> Download PDF
            </button>
        </div>
    </div>
</div> 

<div id="editBankModal" class="modal-overlay">
    <div class="modal-container" style="max-width: 550px;">
        <div class="modal-header">
            <h3><i class="bi bi-pencil-square"></i> Update Bank Account</h3>
            <button class="modal-close" onclick="closeEditBankModal()">&times;</button>
        </div>
        <form method="POST" action="" id="editBankForm">
            <input type="hidden" name="update_bank_account" value="1">
            <input type="hidden" name="payout_id" id="editPayoutId">
            
            <div class="modal-body" style="overflow-y: auto; max-height: 60vh; padding: 24px;">
                <div class="report-details" style="background: #fef2f2; border-radius: 12px; padding: 16px; margin-bottom: 20px; border-left: 4px solid #dc2626;">
                    <p style="margin: 0 0 10px 0;"><strong>⚠️ Warning:</strong> The tutor reported that the bank account information is incorrect. Please verify and update below:</p>
                    <p><strong>Tutor:</strong> <span id="editTutorName">-</span></p>
                    <p><strong>Original Bank:</strong> <span id="oldBankName">-</span></p>
                    <p><strong>Original Account:</strong> <span id="oldAccountNumber">-</span></p>
                </div>
                
                <div class="form-group">
                    <label><i class="bi bi-bank"></i> New Bank Name <span style="color: #dc2626;">*</span></label>
                    <input type="text" name="new_bank_name" id="newBankName" required placeholder="e.g., Maybank, CIMB, Public Bank">
                </div>
                
                <div class="form-group">
                    <label><i class="bi bi-credit-card"></i> New Account Number <span style="color: #dc2626;">*</span></label>
                    <input type="text" name="new_account_number" id="newAccountNumber" required placeholder="Enter full account number">
                </div>
                
                <div class="form-group">
                    <label><i class="bi bi-person"></i> New Account Holder Name <span style="color: #dc2626;">*</span></label>
                    <input type="text" name="new_account_name" id="newAccountName" required placeholder="Full name as per bank account">
                </div>
                
                <div class="form-group">
                    <label><i class="bi bi-chat-text"></i> Reason for Update <span style="color: #dc2626;">*</span></label>
                    <textarea name="update_reason" id="updateReason" rows="2" required placeholder="Why is the bank account being updated? (e.g., Tutor reported wrong account number)"></textarea>
                </div>
            </div>
            
            <div class="modal-buttons">
                <button type="button" class="btn-cancel" onclick="closeEditBankModal()">Cancel</button>
                <button type="submit" class="btn-save" style="background: #E75A9B;">Update Bank Account</button>
            </div>
        </form>
    </div>
</div>

<!-- Process Payout Modal -->
<div id="processModal" class="modal-overlay">
    <div class="modal-container">
        <div class="modal-header">
            <h3 id="modalTitle"><i class="bi bi-cash-stack"></i> Process Payout</h3>
            <button class="modal-close" onclick="closeProcessModal()">&times;</button>
        </div>
        <form method="POST" action="" id="processForm">
            <input type="hidden" name="payout_id" id="payoutId">
            <input type="hidden" name="payout_action" id="payoutAction" value="">
            
            <div class="modal-body">
                <div class="report-details" style="background: #f8fafc; border-radius: 12px; padding: 16px; margin-bottom: 20px;">
                    <p><strong>Tutor:</strong> <span id="modalTutorName">-</span></p>
                    <p><strong>Amount:</strong> RM <span id="modalAmount">-</span></p>
                    <p><strong>Bank:</strong> <span id="modalBank">-</span></p>
                    <p><strong>Account Number:</strong> <span id="modalAccount">-</span></p>
                    <p><strong>Account Holder:</strong> <span id="modalHolder">-</span></p>
                </div>
                
                <div class="form-group">
                    <label id="notesLabel"><i class="bi bi-pencil-square"></i> Admin Notes</label>
                    <textarea name="admin_notes" id="adminNotes" rows="3" placeholder="Add notes about this payout..."></textarea>
                </div>
            </div>
            
            <div class="modal-buttons">
                <button type="button" class="btn-cancel" onclick="closeProcessModal()">Cancel</button>
                <button type="submit" class="btn-save" id="submitBtn">Confirm</button>
            </div>
        </form>
    </div>
</div>

<!-- Complete Payout Modal -->
<div id="completeModal" class="modal-overlay">
    <div class="modal-container">
        <div class="modal-header">
            <h3><i class="bi bi-cash-stack"></i> Complete Payout Transfer</h3>
            <button class="modal-close" onclick="closeCompleteModal()">&times;</button>
        </div>
        <form method="POST" action="" id="completeForm">
            <input type="hidden" name="complete_payout" value="1">
            <input type="hidden" name="payout_id" id="completePayoutId">
            
            <div class="modal-body">
                <div class="report-details" style="background: #f8fafc; border-radius: 12px; padding: 16px; margin-bottom: 20px;">
                    <p><strong>Tutor:</strong> <span id="completeTutorName">-</span></p>
                    <p><strong>Amount:</strong> RM <span id="completeAmount">-</span></p>
                </div>
                
                <div class="form-group">
                    <label><i class="bi bi-receipt"></i> Transaction Reference (Optional)</label>
                    <input type="text" name="transaction_ref" id="transactionRef" placeholder="e.g., TRF-12345678">
                </div>
                
                <div class="form-group">
                    <label><i class="bi bi-pencil-square"></i> Admin Notes (Optional)</label>
                    <textarea name="admin_notes" rows="3" placeholder="Add notes about this transfer..."></textarea>
                </div>
            </div>
            
            <div class="modal-buttons">
                <button type="button" class="btn-cancel" onclick="closeCompleteModal()">Cancel</button>
                <button type="submit" class="btn-save" style="background: #28a745;">Confirm Transfer</button>
            </div>
        </form>
    </div>
</div>

<!-- View Details Modal -->
<div id="viewModal" class="modal-overlay">
    <div class="modal-container">
        <div class="modal-header">
            <h3><i class="bi bi-info-circle"></i> Payout Details</h3>
            <button class="modal-close" onclick="closeViewModal()">&times;</button>
        </div>
        <div class="modal-body">
            <div class="report-details" style="background: #f8fafc; border-radius: 12px; padding: 16px;">
                <p><strong>Tutor:</strong> <span id="viewTutorName">-</span></p>
                <p><strong>Amount:</strong> RM <span id="viewAmount">-</span></p>
                <p><strong>Bank:</strong> <span id="viewBank">-</span></p>
                <p><strong>Account Number:</strong> <span id="viewAccount">-</span></p>
                <p><strong>Account Holder:</strong> <span id="viewHolder">-</span></p>
                <p><strong>Status:</strong> <span id="viewStatus">-</span></p>
                <p><strong>Admin Notes:</strong> <span id="viewNotes">-</span></p>
            </div>
        </div>
        <div class="modal-buttons">
            <button class="btn-cancel" onclick="closeViewModal()">Close</button>
        </div>
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

function viewReceipt(id, tutorName, tutorEmail, tutorId, amount, bankName, accountNumber, accountHolder, transactionRef, requestedAt, completedAt) {
    const receiptNo  = 'PO-' + String(id).padStart(8, '0');
    const tutorCode  = 'TCH-' + String(tutorId).padStart(6, '0');
    const txRef      = transactionRef || 'N/A';
    const amountFmt  = 'RM ' + parseFloat(amount).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');

    document.getElementById('receiptContent').innerHTML = `
        <!-- Success Banner -->
        <div style="background:#d4edda;border:1px solid #28a745;border-radius:10px;padding:12px 16px;margin-bottom:16px;display:flex;align-items:center;gap:10px;">
            <i class="bi bi-check-circle-fill" style="color:#28a745;font-size:20px;flex-shrink:0;"></i>
            <div>
                <div style="font-size:13px;font-weight:700;color:#28a745;">PAYMENT SUCCESSFULLY TRANSFERRED</div>
                <div style="font-size:11px;color:#155724;">The amount has been credited to the tutor's registered bank account.</div>
            </div>
        </div>

        <!-- Title + IDs -->
        <div style="text-align:center;margin-bottom:14px;">
            <div style="font-size:1rem;font-weight:800;color:#1d3156;letter-spacing:1px;">PAYOUT CONFIRMATION</div>
            <div style="font-size:11px;color:#94a3b8;margin-top:4px;">Transaction ID: ${receiptNo}</div>
            <div style="font-size:11px;color:#94a3b8;">Date: ${completedAt}</div>
        </div>

        <hr style="border-color:#E75A9B;margin-bottom:14px;">

        <!-- Two column info -->
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px;">
            <!-- Tutor Info -->
            <div style="background:#f5f5fa;border-radius:10px;padding:14px;">
                <div style="font-size:11px;font-weight:700;color:#E75A9B;letter-spacing:1px;margin-bottom:10px;">TUTOR INFORMATION</div>
                <div style="font-size:11px;margin-bottom:6px;"><span style="color:#94a3b8;font-weight:600;">Name: </span><span style="color:#3c5078;">${tutorName}</span></div>
                <div style="font-size:11px;margin-bottom:6px;word-break:break-all;"><span style="color:#94a3b8;font-weight:600;">Email: </span><span style="color:#3c5078;">${tutorEmail}</span></div>
                <div style="font-size:11px;margin-bottom:6px;"><span style="color:#94a3b8;font-weight:600;">Tutor ID: </span><span style="color:#3c5078;">${tutorCode}</span></div>
                <div style="font-size:11px;"><span style="color:#94a3b8;font-weight:600;">Status: </span><span style="color:#28a745;font-weight:700;">VERIFIED</span></div>
            </div>
            <!-- Bank Info -->
            <div style="background:#f5f5fa;border-radius:10px;padding:14px;">
                <div style="font-size:11px;font-weight:700;color:#E75A9B;letter-spacing:1px;margin-bottom:10px;">BANK ACCOUNT</div>
                <div style="font-size:11px;margin-bottom:6px;"><span style="color:#94a3b8;font-weight:600;">Bank: </span><span style="color:#3c5078;">${bankName}</span></div>
                <div style="font-size:11px;margin-bottom:6px;"><span style="color:#94a3b8;font-weight:600;">Account No: </span><span style="color:#3c5078;">${accountNumber}</span></div>
                <div style="font-size:11px;"><span style="color:#94a3b8;font-weight:600;">Account Name: </span><span style="color:#3c5078;">${accountHolder}</span></div>
            </div>
        </div>

        <!-- Payment Details Table -->
        <div style="background:#1d3156;border-radius:8px 8px 0 0;padding:8px 14px;margin-bottom:0;">
            <span style="font-size:11px;font-weight:700;color:white;letter-spacing:1px;">PAYMENT DETAILS</span>
        </div>
        <table style="width:100%;border-collapse:collapse;margin-bottom:12px;font-size:11px;">
            <thead>
                <tr style="background:#f5f5fa;">
                    <th style="padding:8px 10px;text-align:left;color:#3c5078;font-weight:700;width:38%;">Description</th>
                    <th style="padding:8px 10px;text-align:left;color:#3c5078;font-weight:700;">Details</th>
                    <th style="padding:8px 10px;text-align:right;color:#3c5078;font-weight:700;width:22%;">Amount</th>
                </tr>
            </thead>
            <tbody>
                <tr style="border-bottom:1px solid #eef2f7;">
                    <td style="padding:8px 10px;color:#64748b;">Payout Amount</td>
                    <td style="padding:8px 10px;color:#64748b;">Tutor Earnings Payout</td>
                    <td style="padding:8px 10px;text-align:right;color:#3c5078;font-weight:600;">${amountFmt}</td>
                </tr>
                <tr style="border-bottom:1px solid #eef2f7;">
                    <td style="padding:8px 10px;color:#64748b;">Request Date</td>
                    <td style="padding:8px 10px;color:#64748b;">${requestedAt}</td>
                    <td style="padding:8px 10px;"></td>
                </tr>
                <tr style="border-bottom:1px solid #eef2f7;">
                    <td style="padding:8px 10px;color:#64748b;">Transfer Date</td>
                    <td style="padding:8px 10px;color:#64748b;">${completedAt}</td>
                    <td style="padding:8px 10px;"></td>
                </tr>
                ${txRef !== 'N/A' ? `
                <tr style="border-bottom:1px solid #eef2f7;">
                    <td style="padding:8px 10px;color:#64748b;">Transaction Ref</td>
                    <td style="padding:8px 10px;color:#64748b;">${txRef}</td>
                    <td style="padding:8px 10px;"></td>
                </tr>` : ''}
            </tbody>
        </table>

        <!-- Total Box -->
        <div style="display:flex;justify-content:flex-end;margin-bottom:16px;">
            <div style="background:#E75A9B;border-radius:8px;padding:10px 20px;text-align:left;min-width:180px;">
                <div style="font-size:10px;font-weight:700;color:white;letter-spacing:1px;">TOTAL TRANSFERRED</div>
                <div style="font-size:18px;font-weight:800;color:white;margin-top:2px;">${amountFmt}</div>
            </div>
        </div>

        <!-- Confirmation Footer -->
        <div style="background:#d4edda;border:1px solid #28a745;border-radius:10px;padding:14px 16px;margin-bottom:14px;">
            <div style="font-size:12px;font-weight:700;color:#28a745;margin-bottom:6px;">✓ CONFIRMATION OF TRANSFER</div>
            <div style="font-size:11px;color:#155724;margin-bottom:4px;">This payout has been successfully transferred to the tutor's bank account.</div>
            <div style="font-size:11px;color:#155724;">Effective Date: ${new Date().toLocaleDateString('en-GB', {day:'2-digit', month:'long', year:'numeric'})}</div>
        </div>

        <!-- Footer note -->
        <div style="text-align:center;font-size:10px;color:#94a3b8;line-height:1.8;">
            This is an official payout receipt from Kyoshi.<br>
            For any inquiries, please contact support@kyoshi.com<br>
            © ${new Date().getFullYear()} Kyoshi Language Learning Platform
        </div>
    `;

    document.getElementById('receiptModal').classList.add('active');
    document.body.style.overflow = 'hidden';
}

function closeReceiptModal() {
    document.getElementById('receiptModal').classList.remove('active');
    document.body.style.overflow = '';
}

function printReceipt() {
    const content = document.getElementById('receiptContent').innerHTML;
    const win = window.open('', '_blank', 'width=620,height=800');
    win.document.write(`
        <html><head><title>Payout Receipt</title>
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
        <style>
            * { box-sizing: border-box; margin: 0; padding: 0; }
            body { font-family: 'Segoe UI', sans-serif; padding: 30px; max-width: 560px; margin: auto; }
            @media print { .no-print { display: none !important; } }
        </style></head>
        <body>
            <div style="background:#1d3156;padding:16px 20px;border-radius:8px 8px 0 0;display:flex;align-items:center;justify-content:space-between;margin-bottom:0;">
                <div style="display:flex;align-items:center;gap:10px;">
                    <img src="../assets/img/logo.png" style="width:30px;height:30px;object-fit:contain;">
                    <div>
                        <div style="font-size:1.1rem;font-weight:800;color:white;letter-spacing:2px;">KYOSHI</div>
                        <div style="font-size:0.6rem;color:#c8c8e6;">Language Learning Platform</div>
                    </div>
                </div>
                <div style="background:#E75A9B;padding:5px 12px;border-radius:5px;text-align:center;">
                    <div style="font-size:0.55rem;font-weight:700;color:white;letter-spacing:1px;">PAYOUT</div>
                    <div style="font-size:0.85rem;font-weight:800;color:white;">RECEIPT</div>
                </div>
            </div>
            <div style="height:5px;background:#E75A9B;margin-bottom:20px;"></div>
            ${content}
            <div style="margin-top:20px;text-align:center;" class="no-print">
                <button onclick="window.print()" style="background:#1d3156;color:white;border:none;padding:10px 24px;border-radius:20px;cursor:pointer;font-weight:600;">Print / Save as PDF</button>
            </div>
        </body></html>
    `);
    win.document.close();
    win.focus();
}

function editBankAccount(payoutId, tutorName, oldBankName, oldAccountNumber) {
    document.getElementById('editPayoutId').value = payoutId;
    document.getElementById('editTutorName').innerText = tutorName;
    document.getElementById('oldBankName').innerText = oldBankName;
    
    let maskedOldAccount = oldAccountNumber ? '****' + oldAccountNumber.slice(-4) : 'N/A';
    document.getElementById('oldAccountNumber').innerText = maskedOldAccount;
    
    document.getElementById('newBankName').value = '';
    document.getElementById('newAccountNumber').value = '';
    document.getElementById('newAccountName').value = '';
    document.getElementById('updateReason').value = '';
    
    document.getElementById('editBankModal').classList.add('active');
    document.body.style.overflow = 'hidden';
}

function closeEditBankModal() {
    const modal = document.getElementById('editBankModal');
    modal.classList.remove('active');
    document.body.style.overflow = '';
}

function disableSubmitButton(form) {
    const submitBtn = form.querySelector('button[type="submit"]');
    if (submitBtn && !submitBtn.disabled) {
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="bi bi-hourglass-split"></i> Processing...';
    }
    return true;
}

// ==================== EVENT LISTENERS ====================
document.getElementById('editBankForm').addEventListener('submit', function() {
    disableSubmitButton(this);
});

const completeForm = document.getElementById('completeForm');
if (completeForm) {
    completeForm.addEventListener('submit', function() {
        disableSubmitButton(this);
    });
}

document.getElementById('processForm').addEventListener('submit', function() {
    disableSubmitButton(this);
});

window.addEventListener('click', function(e) {
    const dropdown = document.getElementById('profileDropdown');
    const button = document.querySelector('.admin-profile');
    if (button && !button.contains(e.target) && dropdown && !dropdown.contains(e.target)) {
        dropdown.style.display = 'none';
    }
});

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

// ==================== FUNCTIONS ====================
function applyFilters() {
    const search = document.getElementById('searchInput').value;
    const status = document.getElementById('statusFilter').value;
    let url = `admin_payouts.php?status=${status}`;
    if (search && search.trim() !== '') {
        url += `&search=${encodeURIComponent(search.trim())}`;
    }
    window.location.href = url;
}function processPayout(payoutId, action, amount, tutorName, bankName, accountNumber, accountHolder) {
    if (action === 'approve' && amount < 50) {
        Swal.fire({
            title: 'Cannot Approve',
            text: `This payout amount (RM ${amount.toFixed(2)}) is below the minimum required amount of RM50.`,
            icon: 'error',
            confirmButtonColor: '#dc2626'
        });
        return;
    }

    const modal = document.getElementById('processModal');
    const modalTitle = document.getElementById('modalTitle');
    const submitBtn = document.getElementById('submitBtn');
    const notesLabel = document.getElementById('notesLabel');
    const adminNotes = document.getElementById('adminNotes');

    document.getElementById('payoutId').value = payoutId;
    document.getElementById('modalTutorName').innerText = tutorName;
    document.getElementById('modalAmount').innerText = parseFloat(amount).toFixed(2);
    document.getElementById('modalBank').innerText = bankName;
    document.getElementById('modalAccount').innerText = accountNumber;
    document.getElementById('modalHolder').innerText = accountHolder;

    if (action === 'approve') {
        modalTitle.innerHTML = '<i class="bi bi-check-circle"></i> Approve Payout';
        submitBtn.innerHTML = 'Approve Payout';
        submitBtn.style.background = '#28a745';
        submitBtn.style.backgroundColor = '#28a745';
        submitBtn.className = 'btn-save';
        notesLabel.innerHTML = '<i class="bi bi-pencil-square"></i> Admin Notes (Optional)';
        adminNotes.placeholder = 'Add any notes about this approval...';
        adminNotes.removeAttribute('required');
        document.getElementById('payoutAction').value = 'approve';
    } else {
        modalTitle.innerHTML = '<i class="bi bi-x-circle"></i> Reject Payout';
        submitBtn.innerHTML = 'Reject Payout';
        submitBtn.style.background = '#dc2626';
        submitBtn.style.backgroundColor = '#dc2626';
        submitBtn.className = 'btn-save reject-mode';
        notesLabel.innerHTML = '<i class="bi bi-exclamation-triangle-fill"></i> Reason for Rejection <span style="color: #dc2626;">*</span>';
        adminNotes.placeholder = 'Please provide a reason for rejection...';
        adminNotes.setAttribute('required', 'required');
        document.getElementById('payoutAction').value = 'reject';
    }

    adminNotes.value = '';
    modal.classList.add('active');
    document.body.style.overflow = 'hidden';
}

function closeProcessModal() {
    const modal = document.getElementById('processModal');
    modal.classList.remove('active');
    document.body.style.overflow = '';
    document.getElementById('adminNotes').removeAttribute('required');
}

function completePayout(payoutId, amount, tutorName) {
    document.getElementById('completePayoutId').value = payoutId;
    document.getElementById('completeTutorName').innerText = tutorName;
    document.getElementById('completeAmount').innerText = amount.toFixed(2);
    document.getElementById('completeModal').classList.add('active');
    document.body.style.overflow = 'hidden';
}

function closeCompleteModal() {
    const modal = document.getElementById('completeModal');
    modal.classList.remove('active');
    document.body.style.overflow = '';
}
function downloadReceiptPDF() {
    // Get the receipt content
    const receiptContent = document.getElementById('receiptContent').innerHTML;
    
    // Create a new window for PDF generation
    const win = window.open('', '_blank', 'width=620,height=800');
    
    win.document.write(`
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <title>Kyoshi Payout Receipt</title>
            <style>
                * {
                    margin: 0;
                    padding: 0;
                    box-sizing: border-box;
                }
                body {
                    font-family: 'Segoe UI', 'Poppins', sans-serif;
                    padding: 40px;
                    max-width: 800px;
                    margin: auto;
                    background: white;
                }
                @media print {
                    body {
                        padding: 20px;
                    }
                    .no-print {
                        display: none !important;
                    }
                }
                .receipt-header {
                    background: #1d3156;
                    padding: 20px 25px;
                    border-radius: 16px 16px 0 0;
                    display: flex;
                    align-items: center;
                    justify-content: space-between;
                }
                .receipt-header .logo-section {
                    display: flex;
                    align-items: center;
                    gap: 12px;
                }
                .receipt-header .logo-section img {
                    width: 48px;
                    height: 48px;
                    object-fit: contain;
                }
                .receipt-header .logo-section h1 {
                    color: white;
                    font-size: 24px;
                    margin: 0;
                }
                .receipt-header .logo-section p {
                    color: #c8c8e6;
                    font-size: 11px;
                }
                .receipt-header .badge {
                    background: #E75A9B;
                    padding: 6px 16px;
                    border-radius: 8px;
                    text-align: center;
                }
                .receipt-header .badge div:first-child {
                    font-size: 10px;
                    font-weight: 700;
                    color: white;
                    letter-spacing: 1px;
                }
                .receipt-header .badge div:last-child {
                    font-size: 14px;
                    font-weight: 800;
                    color: white;
                }
                .receipt-stripe {
                    height: 6px;
                    background: #E75A9B;
                    margin-bottom: 20px;
                }
                hr {
                    border-color: #E75A9B;
                    margin: 15px 0;
                }
                .two-column {
                    display: grid;
                    grid-template-columns: 1fr 1fr;
                    gap: 20px;
                    margin-bottom: 20px;
                }
                .info-card {
                    background: #f5f5fa;
                    border-radius: 12px;
                    padding: 15px;
                }
                .info-card h4 {
                    color: #E75A9B;
                    font-size: 12px;
                    margin-bottom: 10px;
                }
                .info-card p {
                    font-size: 12px;
                    margin: 6px 0;
                }
                .payment-table {
                    width: 100%;
                    border-collapse: collapse;
                    margin-bottom: 20px;
                }
                .payment-table th {
                    background: #1d3156;
                    color: white;
                    padding: 10px;
                    font-size: 11px;
                    text-align: left;
                }
                .payment-table td {
                    padding: 10px;
                    border-bottom: 1px solid #eef2f7;
                    font-size: 11px;
                }
                .total-box {
                    display: flex;
                    justify-content: flex-end;
                    margin-bottom: 20px;
                }
                .total-box div {
                    background: #E75A9B;
                    border-radius: 8px;
                    padding: 12px 24px;
                    text-align: left;
                    min-width: 180px;
                }
                .total-box div div:first-child {
                    font-size: 10px;
                    font-weight: 700;
                    color: white;
                    letter-spacing: 1px;
                }
                .total-box div div:last-child {
                    font-size: 20px;
                    font-weight: 800;
                    color: white;
                }
                .confirmation-footer {
                    background: #d4edda;
                    border: 1px solid #28a745;
                    border-radius: 10px;
                    padding: 15px;
                    margin-bottom: 20px;
                }
                .confirmation-footer h4 {
                    color: #28a745;
                    margin-bottom: 8px;
                }
                .footer-note {
                    text-align: center;
                    font-size: 10px;
                    color: #94a3b8;
                    margin-top: 20px;
                    padding-top: 15px;
                    border-top: 1px solid #e2e8f0;
                }
            </style>
        </head>
        <body>
            <div class="receipt-header">
                <div class="logo-section">
                    <img src="../assets/img/logo.png" alt="Kyoshi" onerror="this.style.display='none'">
                    <div>
                        <h1>KYOSHI</h1>
                        <p>Language Learning Platform</p>
                    </div>
                </div>
                <div class="badge">
                    <div>PAYOUT</div>
                    <div>RECEIPT</div>
                </div>
            </div>
            <div class="receipt-stripe"></div>
            
            ${receiptContent}
            
            <div class="footer-note no-print" style="margin-top: 30px; text-align: center;">
                <button onclick="window.print()" style="background: #1d3156; color: white; border: none; padding: 10px 24px; border-radius: 30px; cursor: pointer; font-weight: 600;">
                    🖨️ Print / Save as PDF
                </button>
            </div>
        </body>
        </html>
    `);
    
    win.document.close();
    win.focus();
}

function viewDetails(payoutId, tutorName, amount, notes, status, bankName, accountNumber, accountHolder) {
    const modal = document.getElementById('viewModal');
    document.getElementById('viewTutorName').innerText = tutorName;
    document.getElementById('viewAmount').innerText = amount.toFixed(2);
    document.getElementById('viewBank').innerText = bankName;
    document.getElementById('viewAccount').innerText = accountNumber ? '****' + accountNumber.slice(-4) : 'N/A';
    document.getElementById('viewHolder').innerText = accountHolder;
    document.getElementById('viewNotes').innerText = notes || 'No notes provided';
    
    const statusSpan = document.getElementById('viewStatus');
    if (status === 'completed') {
        statusSpan.innerHTML = '<span class="badge-completed">Completed</span>';
    } else if (status === 'approved') {
        statusSpan.innerHTML = '<span class="badge-approved">Approved</span>';
    } else if (status === 'rejected') {
        statusSpan.innerHTML = '<span class="badge-rejected">Rejected</span>';
    } else {
        statusSpan.innerHTML = '<span class="badge-pending">Pending</span>';
    }
    
    const oldBtn = document.getElementById('editFromViewBtn');
    if (oldBtn) oldBtn.remove();
    
    if (status === 'approved') {
        const modalButtons = document.querySelector('#viewModal .modal-buttons');
        if (modalButtons) {
            const editBtn = document.createElement('button');
            editBtn.id = 'editFromViewBtn';
            editBtn.type = 'button';
            editBtn.className = 'btn-edit-bank';
            editBtn.innerHTML = '<i class="bi bi-pencil-square"></i> Edit Bank Account';
            editBtn.onclick = function(e) {
                e.preventDefault();
                closeViewModal();
                editBankAccount(payoutId, tutorName, bankName, accountNumber);
            };
            modalButtons.insertBefore(editBtn, modalButtons.firstChild);
        }
    }
    
    modal.classList.add('active');
    document.body.style.overflow = 'hidden';
}

function closeViewModal() {
    const modal = document.getElementById('viewModal');
    modal.classList.remove('active');
    document.body.style.overflow = '';
}

// ==================== MODAL CLOSE HANDLERS ====================
window.onclick = function(event) {
    const processModal = document.getElementById('processModal');
    const completeModal = document.getElementById('completeModal');
    const viewModal = document.getElementById('viewModal');
    const editBankModal = document.getElementById('editBankModal');
    
    if (event.target === processModal) closeProcessModal();
    if (event.target === completeModal) closeCompleteModal();
    if (event.target === viewModal) closeViewModal();
    if (event.target === editBankModal) closeEditBankModal();
    if (event.target === document.getElementById('receiptModal')) closeReceiptModal();
}

// ==================== AUTO-DISMISS ALERTS ====================
setTimeout(() => {
    const successAlert = document.getElementById('successAlert');
    const errorAlert = document.getElementById('errorAlert');
    
    if (successAlert) {
        successAlert.style.opacity = '0';
        successAlert.style.transition = 'opacity 0.5s';
        setTimeout(() => { if (successAlert) successAlert.remove(); }, 500);
    }
    if (errorAlert) {
        errorAlert.style.opacity = '0';
        errorAlert.style.transition = 'opacity 0.5s';
        setTimeout(() => { if (errorAlert) errorAlert.remove(); }, 500);
    }
}, 3000);
</script>
<script>
history.pushState(null, null, location.href);
window.addEventListener('popstate', function() {
    window.location.href = 'login.php';
});
</script>
</body>
</html>