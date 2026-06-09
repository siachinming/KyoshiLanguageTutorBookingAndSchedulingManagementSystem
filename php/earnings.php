<?php
session_start();
include 'config.php';
include 'check_login.php';
// Initialize session flags for no-show messages
if (!isset($_SESSION['shown_tutor_no_show'])) {
    $_SESSION['shown_tutor_no_show'] = false;
}
if (!isset($_SESSION['shown_student_no_show'])) {
    $_SESSION['shown_student_no_show'] = false;
}

// Handle dismissal via GET parameter
if (isset($_GET['dismiss_tutor_no_show'])) {
    $_SESSION['shown_tutor_no_show'] = true;
    header("Location: earnings.php");
    exit();
}
if (isset($_GET['dismiss_student_no_show'])) {
    $_SESSION['shown_student_no_show'] = true;
    header("Location: earnings.php");
    exit();
}

$assetBase = '../assets/img';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'tutor') {
    header("Location: login.php");
    exit();
}
$payoutMessage = null;
$payoutMessageType = null;
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

// Platform commission rate (20%)
$COMMISSION_RATE = 0.20;

// Get ALL completed bookings with attendance info
$stmt = $conn->prepare("
    SELECT 
        b.id,
        b.language,
        b.booking_date,
        b.booking_time,
        b.total_amount,
        b.status,
        sc.completed_at,
        sc.student_confirmed,
        sc.no_show_type,
        sc.attendance_manually_set,
        u.fullname as student_name,
        u.id as student_id
    FROM bookings b
    JOIN users u ON b.student_id = u.id
    LEFT JOIN session_completion sc ON b.id = sc.booking_id
    WHERE b.tutor_id = ? 
        AND b.status = 'completed'
    ORDER BY b.booking_date DESC
");
$stmt->bind_param("i", $userID);
$stmt->execute();
$allCompletedBookings = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Check payments table for verified status
$stmt = $conn->prepare("
    SELECT booking_id, status as payment_status 
    FROM payments 
    WHERE status = 'verified'
");
$stmt->execute();
$verifiedPayments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$verifiedBookingIds = array_column($verifiedPayments, 'booking_id');



$stmt = $conn->prepare("
    SELECT booking_id, report_status 
    FROM session_reports 
    WHERE tutor_id = ? AND report_status = 'approved'
");
$stmt->bind_param("i", $userID);
$stmt->execute();
$approvedReports = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$approvedBookingIds = array_column($approvedReports, 'booking_id');

// Filter bookings based on attendance type
$verifiedBookings = [];        // Sessions that count for earnings (attended + verified + has report) OR (student no-show + verified)
$pendingReportCount = 0;       // Sessions that need report (attended + verified + NO report)
$pendingVerificationCount = 0; // Sessions waiting for payment verification
$studentNoShowCount = 0;       // Student no-show (paid but no report needed)
$tutorNoShowCount = 0;         // Tutor no-show (refunded, no payment)

foreach ($allCompletedBookings as $booking) {
    $isVerified = in_array($booking['id'], $verifiedBookingIds);
    $hasReport = in_array($booking['id'], $approvedBookingIds);
    
    // Determine attendance type
    $studentConfirmed = $booking['student_confirmed'];
    $noShowType = $booking['no_show_type'];
    
    // Case 1: Student attended the session (confirmed = 1)
if ($studentConfirmed == 1) {
    if ($isVerified) {
        if ($hasReport) {
            $verifiedBookings[] = $booking;
        } else {
            // Attended, payment verified, but report NOT approved by admin yet
            $pendingReportCount++;
        }
    } else {
        $pendingVerificationCount++;
    }
}
    // Case 2: Student no-show (didn't come) - Tutor still gets paid, NO report needed
    elseif ($studentConfirmed == 0 && $noShowType == 'student_no_show') {
        if ($isVerified) {
            // Count as earnings but NO report needed
            $verifiedBookings[] = $booking;
            $studentNoShowCount++;
        } else {
            $pendingVerificationCount++;
        }
    }
    // Case 3: Tutor no-show - Refund, NO payment, NO report
    elseif ($studentConfirmed == 0 && $noShowType == 'tutor_no_show') {
        $tutorNoShowCount++;
        // Do NOT include in earnings
    }
    // Case 4: Unknown or no completion record yet
    else {
        // Don't count until attendance is confirmed
    }
}

// Calculate total earnings (only verified bookings with attendance OR student no-show)
$totalGross = 0;
$totalCommission = 0;
$totalNet = 0;
$monthlyEarnings = [];
$studentEarnings = [];
$languageEarnings = [];
$chartMonths = [];
$chartNetEarnings = [];
$chartGrossEarnings = [];

foreach ($verifiedBookings as $booking) {
    $amount = floatval($booking['total_amount']);
    $commission = $amount * $COMMISSION_RATE;
    $net = $amount - $commission;
    
    $totalGross += $amount;
    $totalCommission += $commission;
    $totalNet += $net;
    
    // Monthly breakdown for charts
    $month = date('Y-m', strtotime($booking['booking_date']));
    $monthName = date('M Y', strtotime($booking['booking_date']));
    
    if (!isset($monthlyEarnings[$month])) {
        $monthlyEarnings[$month] = [
            'gross' => 0,
            'commission' => 0,
            'net' => 0,
            'count' => 0,
            'month_name' => $monthName,
            'student_no_shows' => 0,
            'attended' => 0
        ];
    }
    $monthlyEarnings[$month]['gross'] += $amount;
    $monthlyEarnings[$month]['commission'] += $commission;
    $monthlyEarnings[$month]['net'] += $net;
    $monthlyEarnings[$month]['count']++;
    
    // Track attendance type for this booking
    if ($booking['student_confirmed'] == 0 && $booking['no_show_type'] == 'student_no_show') {
        $monthlyEarnings[$month]['student_no_shows']++;
    } else {
        $monthlyEarnings[$month]['attended']++;
    }
    
    // Student earnings tracking
    $studentName = $booking['student_name'];
    if (!isset($studentEarnings[$studentName])) {
        $studentEarnings[$studentName] = [
            'student_id' => $booking['student_id'],
            'total' => 0,
            'count' => 0,
            'languages' => [],
            'no_shows' => 0
        ];
    }
    $studentEarnings[$studentName]['total'] += $net;
    $studentEarnings[$studentName]['count']++;
    
    // Track if this was a student no-show
    if ($booking['student_confirmed'] == 0 && $booking['no_show_type'] == 'student_no_show') {
        $studentEarnings[$studentName]['no_shows']++;
    }
    
    if (!in_array($booking['language'], $studentEarnings[$studentName]['languages'])) {
        $studentEarnings[$studentName]['languages'][] = $booking['language'];
    }
    
    // Language earnings tracking for pie chart
    if (!isset($languageEarnings[$booking['language']])) {
        $languageEarnings[$booking['language']] = [
            'total' => 0,
            'count' => 0
        ];
    }
    $languageEarnings[$booking['language']]['total'] += $net;
    $languageEarnings[$booking['language']]['count']++;
}

$stmt = $conn->prepare("
    SELECT SUM(amount) as total_requested 
    FROM payout_requests 
    WHERE tutor_id = ? AND status IN ('pending', 'approved', 'completed')
");
$stmt->bind_param("i", $userID);
$stmt->execute();
$requestedResult = $stmt->get_result()->fetch_assoc();
$totalRequested = $requestedResult['total_requested'] ?? 0;

// Calculate AVAILABLE balance (earnings - already requested)
$availableBalance = $totalNet - $totalRequested;

// Update the canRequestPayout condition to use available balance
$canRequestPayout = ($availableBalance >= 50) && ($pendingReportCount == 0);

// Also update the max amount for payout request
$maxPayoutAmount = $availableBalance;


// Sort months in ascending order for chart
ksort($monthlyEarnings);
foreach ($monthlyEarnings as $month => $data) {
    $chartMonths[] = $data['month_name'];
    $chartNetEarnings[] = $data['net'];
    $chartGrossEarnings[] = $data['gross'];
}

// Sort students by total earnings (highest first)
arsort($studentEarnings);
$topStudents = array_slice($studentEarnings, 0, 5, true);

// Sort languages by earnings
arsort($languageEarnings);

// Get current month earnings
$currentMonth = date('Y-m');
$currentMonthEarnings = $monthlyEarnings[$currentMonth] ?? ['gross' => 0, 'commission' => 0, 'net' => 0, 'count' => 0];
$totalSessions = count($verifiedBookings);


// Get payout history
$payoutHistory = [];
$checkTable = $conn->query("SHOW TABLES LIKE 'payout_requests'");
if ($checkTable->num_rows > 0) {
    $stmt = $conn->prepare("
        SELECT * FROM payout_requests 
        WHERE tutor_id = ? 
        ORDER BY requested_at DESC
    ");
    $stmt->bind_param("i", $userID);
    $stmt->execute();
    $payoutHistory = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function e($value) {
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function formatMoney($amount) {
    return 'RM ' . number_format($amount, 2);
}

// Get all bank accounts from tutor_bank_details table
$bankStmt = $conn->prepare("
    SELECT id, bank_name, bank_account_number, bank_account_name, is_default 
    FROM tutor_bank_details 
    WHERE tutor_id = ? 
    ORDER BY is_default DESC, id ASC
");
$bankStmt->bind_param("i", $userID);
$bankStmt->execute();
$bankAccounts = $bankStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$hasBankAccounts = count($bankAccounts) > 0;

// Get the default bank account (or first if none marked as default)
$defaultBank = null;
if ($hasBankAccounts) {
    foreach ($bankAccounts as $bank) {
        if ($bank['is_default']) {
            $defaultBank = $bank;
            break;
        }
    }
    if (!$defaultBank) {
        $defaultBank = $bankAccounts[0];
    }
}
// Handle payout request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_payout'])) {
    $amount = floatval($_POST['amount'] ?? 0);
    $selectedBankId = intval($_POST['selected_bank'] ?? 0);
    
    // Get the selected bank details
    $selectedBank = null;
    foreach ($bankAccounts as $bank) {
        if ($bank['id'] == $selectedBankId) {
            $selectedBank = $bank;
            break;
        }
    }
    
    // If no bank selected but has accounts, use default
    if (!$selectedBank && $hasBankAccounts) {
        $selectedBank = $defaultBank;
    }
    
    $bankErrors = [];
    
    if (!$hasBankAccounts) {
        $bankErrors[] = "Please add a bank account in your profile before requesting payout";
    } elseif (!$selectedBank) {
        $bankErrors[] = "Please select a bank account";
    }
    
    if (!empty($bankErrors)) {
        $payoutMessage = implode(", ", $bankErrors);
        $payoutMessageType = "error";
    } elseif ($amount <= 0) {
        $payoutMessage = "Please enter a valid amount";
        $payoutMessageType = "error";
    } elseif ($amount > $availableBalance) {
    $payoutMessage = "Request amount exceeds your available balance (Already requested a total amount of " . formatMoney($totalRequested) . ")";
    } elseif ($amount < 50) {
        $payoutMessage = "Minimum payout amount is RM50";
        $payoutMessageType = "error";
    } elseif ($pendingReportCount > 0) {
        $payoutMessage = "You have $pendingReportCount attended session(s) with payment verified but report not submitted. Please submit all session reports before requesting payout.";
        $payoutMessageType = "error";
    } else {
        // Create payout_requests table if not exists
        $conn->query("
            CREATE TABLE IF NOT EXISTS payout_requests (
                id INT AUTO_INCREMENT PRIMARY KEY,
                tutor_id INT NOT NULL,
                amount DECIMAL(10,2) NOT NULL,
                bank_account_id INT,
                bank_name VARCHAR(100),
                bank_account_number VARCHAR(50),
                bank_account_name VARCHAR(100),
                status ENUM('pending', 'approved', 'completed', 'rejected') DEFAULT 'pending',
                requested_at DATETIME,
                processed_at DATETIME,
                admin_notes TEXT,
                FOREIGN KEY (tutor_id) REFERENCES users(id)
            )
        ");

        // Insert payout request
        $stmt = $conn->prepare("
            INSERT INTO payout_requests (tutor_id, amount, bank_account_id, bank_name, bank_account_number, bank_account_name, status, requested_at) 
            VALUES (?, ?, ?, ?, ?, ?, 'pending', NOW())
        ");
        $stmt->bind_param("idisss", $userID, $amount, $selectedBank['id'], $selectedBank['bank_name'], $selectedBank['bank_account_number'], $selectedBank['bank_account_name']);
        
        if ($stmt->execute()) {
            $payoutMessage = "Payout request submitted successfully!";
            $payoutMessageType = "success";
            
            header("Location: earnings.php?success=1");
            exit();
        } else {
            $payoutMessage = "Error submitting request. Please try again.";
            $payoutMessageType = "error";
        }
    }
}

// SAVE BANK ACCOUNT - Direct handler in earnings.php
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_bank') {
    $bankId = intval($_POST['bank_id'] ?? 0);
    $bankName = trim($_POST['bank_name'] ?? '');
    $bankAccountNumber = trim($_POST['bank_account_number'] ?? '');
    $bankAccountName = trim($_POST['bank_account_name'] ?? '');
    
    $errors = [];
    if (empty($bankName)) $errors[] = "Bank name is required";
    if (empty($bankAccountNumber)) $errors[] = "Account number is required";
    if (empty($bankAccountName)) $errors[] = "Account holder name is required";
    
    if (empty($errors)) {
        // Check for duplicate account number
        $dupStmt = $conn->prepare("
            SELECT id FROM tutor_bank_details 
            WHERE tutor_id = ? AND bank_account_number = ? AND id != ?
        ");
        $dupStmt->bind_param("isi", $userID, $bankAccountNumber, $bankId);
        $dupStmt->execute();
        $duplicate = $dupStmt->get_result()->fetch_assoc();
        
        if ($duplicate) {
            $_SESSION['error_message'] = "This bank account number already exists!";
        } else {
            $checkCount = $conn->prepare("SELECT COUNT(*) as count FROM tutor_bank_details WHERE tutor_id = ?");
            $checkCount->bind_param("i", $userID);
            $checkCount->execute();
            $count = $checkCount->get_result()->fetch_assoc()['count'];
            
            if ($bankId > 0) {
                // Update existing
                $stmt = $conn->prepare("
                    UPDATE tutor_bank_details SET 
                        bank_name = ?, bank_account_number = ?, bank_account_name = ? 
                    WHERE id = ? AND tutor_id = ?
                ");
                $stmt->bind_param("sssii", $bankName, $bankAccountNumber, $bankAccountName, $bankId, $userID);
            } else {
                // Insert new - check limit
                if ($count >= 3) {
                    $_SESSION['error_message'] = "Maximum 3 bank accounts allowed.";
                    header("Location: earnings.php");
                    exit();
                }
                $isDefault = ($count == 0) ? 1 : 0;
                $stmt = $conn->prepare("
                    INSERT INTO tutor_bank_details (tutor_id, bank_name, bank_account_number, bank_account_name, is_default) 
                    VALUES (?, ?, ?, ?, ?)
                ");
                $stmt->bind_param("isssi", $userID, $bankName, $bankAccountNumber, $bankAccountName, $isDefault);
            }
            
            if ($stmt->execute()) {
                $_SESSION['success_message'] = $bankId > 0 ? 'Bank account updated!' : 'Bank account added!';
            } else {
                $_SESSION['error_message'] = 'Error saving bank details.';
            }
        }
    } else {
        $_SESSION['error_message'] = implode(", ", $errors);
    }
    
    header("Location: earnings.php?bank_added=1");
    exit();
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
    <title>My Earnings - Kyoshi Tutor</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../css/style.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 30px;
        }
        .stat-card {
            background: white;
            border-radius: 20px;
            padding: 24px;
            text-align: center;
            border: 1px solid #eef2f7;
            transition: all 0.3s ease;
        }
        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.08);
        }
        .stat-icon {
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, #E75A9B, #F28AB2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 15px;
        }
        .stat-icon i { font-size: 28px; color: white; }
        .stat-value { font-size: 28px; font-weight: 800; color: #E75A9B; }
        .stat-label { font-size: 13px; color: #64748b; margin-top: 5px; }
        .stat-sub { font-size: 11px; color: #94a3b8; margin-top: 3px; }
        .stat-warning {
            font-size: 11px;
            color: #f59e0b;
            margin-top: 5px;
            background: #fef3c7;
            padding: 4px 8px;
            border-radius: 20px;
            display: inline-block;
        }
        .stat-info {
            font-size: 11px;
            color: #28a745;
            margin-top: 5px;
            background: #d4edda;
            padding: 4px 8px;
            border-radius: 20px;
            display: inline-block;
        }

        .charts-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 24px;
            margin-bottom: 30px;
        }
        .chart-card {
            background: white;
            border-radius: 20px;
            padding: 20px;
            border: 1px solid #eef2f7;
            transition: all 0.3s ease;
        }
        .chart-card:hover {
            box-shadow: 0 8px 25px rgba(0,0,0,0.08);
        }
        .chart-title {
            font-size: 16px;
            font-weight: 700;
            color: #1d3156;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .chart-container {
            position: relative;
            height: 300px;
        }
        canvas {
            max-height: 280px;
            width: 100%;
        }

        .payout-card {
            background: linear-gradient(135deg, #1d3156, #2d4a7c);
            border-radius: 24px;
            padding: 24px;
            margin-bottom: 24px;
            color: white;
        }
        .payout-card h3 {
            font-size: 18px;
            margin-bottom: 8px;
        }
        .payout-card p {
            font-size: 13px;
            opacity: 0.9;
            margin-bottom: 16px;
        }
        .payout-amount {
            font-size: 36px;
            font-weight: 800;
            margin-bottom: 16px;
        }
        .btn-payout {
            background: #28a745;
            color: white;
            border: none;
            padding: 12px 28px;
            border-radius: 40px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            transition: 0.2s;
        }
        .btn-payout:hover {
            transform: translateY(-2px);
            background: #218838;
        }
        .btn-payout.disabled {
            background: #94a3b8;
            cursor: not-allowed;
        }
        .glass-card {
            background: rgba(255, 255, 255, 0.92);
            border: 1px solid rgba(255, 255, 255, 0.55);
            border-radius: 24px;
            overflow: hidden;
            margin-bottom: 24px;
        }
        .card-header {
            padding: 20px 24px;
            background: #f8fafc;
            border-bottom: 1px solid #eef2f7;
        }
        .card-header h2 {
            font-size: 18px;
            font-weight: 700;
            color: #1d3156;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .card-header p {
            font-size: 13px;
            color: #64748b;
            margin-top: 4px;
        }

        .earnings-table {
            width: 100%;
            border-collapse: collapse;
        }
        .earnings-table th {
            text-align: left;
            padding: 14px 20px;
            background: #f8fafc;
            font-size: 12px;
            font-weight: 700;
            color: #1d3156;
            border-bottom: 2px solid #eef2f7;
        }
        .earnings-table td {
            padding: 14px 20px;
            font-size: 13px;
            color: #475569;
            border-bottom: 1px solid #eef2f7;
        }
        .earnings-table tr:hover td {
            background: #fafcff;
        }

        .positive { color: #28a745; font-weight: 700; }
        .negative { color: #dc2626; font-weight: 700; }
        .commission { color: #f59e0b; font-weight: 700; }

        .top-student-card {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 16px;
            border-bottom: 1px solid #eef2f7;
        }
        .top-student-card:last-child { border-bottom: none; }
        .rank-badge {
            width: 32px;
            height: 32px;
            background: #f1f5f9;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            font-size: 14px;
            color: #1d3156;
        }
        .rank-badge.gold { background: #fef3c7; color: #f59e0b; }
        .rank-badge.silver { background: #e2e8f0; color: #64748b; }
        .rank-badge.bronze { background: #fee2e2; color: #dc2626; }
        .badge-no-show { background: #fff3e0; color: #f59e0b; padding: 2px 8px; border-radius: 12px; font-size: 10px; display: inline-block; margin-left: 8px; }

        .empty-state {
            text-align: center;
            padding: 60px;
            background: white;
            border-radius: 24px;
            color: #94a3b8;
        }
        .empty-state i { font-size: 64px; margin-bottom: 16px; display: block; }

        .alert {
            padding: 12px 16px;
            border-radius: 12px;
            margin-bottom: 20px;
            font-size: 13px;
        }
        .alert-success {
            background: #d4edda;
            color: #155724;
            border-left: 4px solid #28a745;
        }
        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border-left: 4px solid #dc2626;
        }
        .alert-warning {
            background: #fff3e0;
            color: #e67e22;
            border-left: 4px solid #f59e0b;
        }
        .alert-info {
            background: #d1ecf1;
            color: #0c5460;
            border-left: 4px solid #17a2b8;
        }

        .status-pending { background: #fef3c7; color: #f59e0b; padding: 2px 10px; border-radius: 20px; font-size: 11px; display: inline-block; }
        .status-approved { background: #d4edda; color: #28a745; padding: 2px 10px; border-radius: 20px; font-size: 11px; display: inline-block; }
        .status-completed { background: #d4edda; color: #28a745; padding: 2px 10px; border-radius: 20px; font-size: 11px; display: inline-block; }
        .status-rejected { background: #fee2e2; color: #dc2626; padding: 2px 10px; border-radius: 20px; font-size: 11px; display: inline-block; }

        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            display: none;
            align-items: center;
            justify-content: center;
        }
        .modal-overlay.active {
            display: flex;
        }
        .modal-container {
            background: white;
            border-radius: 24px;
            padding: 28px;
            width: 400px;
            max-width: 90%;
        }
        .modal-header {
            padding: 20px 24px;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .modal-close {
            background: none;
            border: none;
            font-size: 28px;
            cursor: pointer;
            color: #94a3b8;
        }
        .modal-close:hover {
            color: #1d3156;
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            font-size: 13px;
            color: #1d3156;
        }
        .form-group input {
            width: 100%;
            padding: 12px;
            border: 1px solid #cbd5e1;
            border-radius: 12px;
            font-size: 14px;
        }
        .modal-buttons {
            display: flex;
            gap: 12px;
            justify-content: flex-end;
        }
        .btn-cancel {
            padding: 10px 20px;
            border-radius: 30px;
            border: 1px solid #cbd5e1;
            background: white;
            cursor: pointer;
            font-size: 13px;
            font-weight: 600;
        }
        .btn-save {
            padding: 10px 20px;
            border-radius: 30px;
            background: #28a745;
            color: white;
            border: none;
            cursor: pointer;
            font-size: 13px;
            font-weight: 600;
        }

        @media (max-width: 900px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .charts-grid { grid-template-columns: 1fr; }
            .earnings-table { display: block; overflow-x: auto; }
            .back-btn span{ display:none;}
        }
        /* Two column layout that stacks on mobile */
.two-column-layout {
    display: grid;
    grid-template-columns: 0.4fr 1fr;
    gap: 24px;
}

/* Fix for Monthly Breakdown to be in its own row on mobile */
@media (max-width: 768px) {
    .two-column-layout {
        grid-template-columns: 1fr;
        gap: 20px;
    }
    
    /* Make sure Monthly Breakdown takes full width */
    .two-column-layout .glass-card:last-child {
        width: 100%;
    }
    
    /* Adjust card headers for mobile */
    .card-header {
        padding: 16px !important;
    }
    
    .card-header h2 {
        font-size: 16px;
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
                <a href="material_overview.php">My Materials</a>
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
                    <a href="earnings.php" class="active"><i class="bi bi-wallet2"></i> My Earnings</a>
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
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 32px;">
        <a href="tutor_dashboard.php" class="back-btn"><i class="bi bi-arrow-left"></i> <span>Back</span></a>
        <div style="text-align: center;">
            <h1 style="font-size: 24px; font-weight: 800; color: #1d3156; margin: 0;"><i class="bi bi-wallet2"></i> My Earnings</h1>
            <p style="color: #1e293b; margin: 4px 0 0; font-size: 12px;">Track your earnings and request payouts</p>
        </div>
        <div style="width: 50px;"></div>

    </div>

    <?php if (isset($payoutMessage)): ?>
        <div class="alert alert-<?= $payoutMessageType ?>">
            <i class="bi bi-<?= $payoutMessageType === 'success' ? 'check-circle' : 'exclamation-circle' ?>"></i>
            <?= e($payoutMessage) ?>
        </div>
    <?php endif; ?>

    <?php if (isset($_SESSION['success_message'])): ?>
        <div class="alert alert-success">
            <i class="bi bi-check-circle"></i>
            <?= $_SESSION['success_message'] ?>
        </div>
        <?php unset($_SESSION['success_message']); ?>
    <?php endif; ?>

    <?php if (isset($_SESSION['error_message'])): ?>
        <div class="alert alert-error">
            <i class="bi bi-exclamation-circle"></i>
            <?= $_SESSION['error_message'] ?>
        </div>
        <?php unset($_SESSION['error_message']); ?>
    <?php endif; ?>
<?php if ($tutorNoShowCount > 0 && !$_SESSION['shown_tutor_no_show']): ?>
    <div class="alert alert-info" id="tutorNoShowAlert" style="position: relative;">
        <i class="bi bi-info-circle"></i>
        You have <?= $tutorNoShowCount ?> session(s) where you didn't attend. These sessions have been refunded to students and are not included in your earnings.
        <button onclick="dismissAlert('tutor_no_show', this)" style="float: right; background: none; border: none; color: #0c5460; font-weight: bold; cursor: pointer; font-size: 18px;">&times;</button>
    </div>
    <script>
        // Auto-dismiss after 5 seconds
        setTimeout(function() {
            const alert = document.getElementById('tutorNoShowAlert');
            if (alert) {
                alert.style.transition = 'opacity 0.5s ease';
                alert.style.opacity = '0';
                setTimeout(function() {
                    if (alert) alert.remove();
                    // Also mark as seen in session
                    fetch('earnings.php?dismiss_tutor_no_show=1', { method: 'GET' });
                }, 500);
            }
        }, 5000);
    </script>
<?php endif; ?>

<?php if ($studentNoShowCount > 0 && !$_SESSION['shown_student_no_show']): ?>
    <div class="alert alert-info" id="studentNoShowAlert" style="position: relative;">
        <i class="bi bi-calendar-x"></i>
        You have a total of <?= $studentNoShowCount ?> session(s) where the student didn't attend. You were still paid for your reserved time. No session reports needed for no-shows.
        <button onclick="dismissAlert('student_no_show', this)" style="float: right; background: none; border: none; color: #0c5460; font-weight: bold; cursor: pointer; font-size: 18px;">&times;</button>
    </div>
    <script>
        // Auto-dismiss after 5 seconds
        setTimeout(function() {
            const alert = document.getElementById('studentNoShowAlert');
            if (alert) {
                alert.style.transition = 'opacity 0.5s ease';
                alert.style.opacity = '0';
                setTimeout(function() {
                    if (alert) alert.remove();
                    // Also mark as seen in session
                    fetch('earnings.php?dismiss_student_no_show=1', { method: 'GET' });
                }, 500);
            }
        }, 5000);
    </script>
<?php endif; ?>

    <?php if (empty($verifiedBookings) && $totalNet == 0): ?>
        <div class="empty-state">
            <i class="bi bi-cash-stack"></i>
            <h3>No Verified Earnings Yet</h3>
            <p>Complete sessions and wait for payment verification to see your earnings.</p>
            <?php if ($pendingVerificationCount > 0): ?>
                <div style="margin-top: 16px; padding: 12px; background: #fef3c7; border-radius: 12px;">
                    <i class="bi bi-clock-history"></i> You have <strong><?= $pendingVerificationCount ?></strong> session(s) waiting for payment verification.
                </div>
            <?php endif; ?>
            <a href="booking_requests.php" style="display: inline-block; margin-top: 15px; padding: 10px 24px; background: linear-gradient(135deg, #E75A9B, #F28AB2); color: white; border-radius: 30px; text-decoration: none;">View Bookings</a>
        </div>
    <?php else: ?>

    <!-- Payout Section -->
    <div class="payout-card">
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 20px;">
            <div>
                <h3><i class="bi bi-cash"></i> Available for Payout</h3>
                <div class="payout-amount"><?= formatMoney($availableBalance) ?></div>
                <p>After 20% platform commission | Minimum payout: RM50</p>
                <?php if ($pendingReportCount > 0): ?>
                    <p style="color: #fef3c7; background: rgba(0,0,0,0.2); padding: 8px 12px; border-radius: 12px; margin-top: 8px;">
                        <i class="bi bi-exclamation-triangle"></i> 
                        You have <?= $pendingReportCount ?> attended session(s) pending report submission.
                        <strong>Payment requires: Submit Report → Admin Verification → Payment Release</strong>
                    </p>
                <?php endif; ?>
                <?php if ($pendingVerificationCount > 0): ?>
                    <p style="color: #fef3c7; background: rgba(0,0,0,0.2); padding: 8px 12px; border-radius: 12px; margin-top: 8px;">
                        <i class="bi bi-clock-history"></i> 
                        <?= $pendingVerificationCount ?> session(s) waiting for payment verification.
                    </p>
                <?php endif; ?>
            </div>
            <div>
            <button class="btn-payout <?= (!$canRequestPayout) ? 'disabled' : '' ?>" 
        onclick="openPayoutModal(<?= $availableBalance ?>)"
        <?= (!$canRequestPayout) ? 'disabled' : '' ?>>
                    <i class="bi bi-cash-stack"></i> Request Payout
                </button>
            </div>
        </div>
    </div>
<!-- Payout History -->
<?php if (!empty($payoutHistory)): ?>
<div class="glass-card">
    <div class="card-header">
        <h2><i class="bi bi-clock-history"></i> Payout History</h2>
        <p>Your previous withdrawal requests</p>
    </div>
    <table class="earnings-table">
        <thead>
            <tr>
                <th>Date Requested</th>
                <th>Amount</th>
                <th>Bank Account</th>
                <th>Status</th>
                <th>Processed Date</th>
                <th>Admin Notes</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($payoutHistory as $payout): ?>
            <tr>
                <td><?= date('d M Y', strtotime($payout['requested_at'])) ?></td>
                <td class="positive"><?= formatMoney($payout['amount']) ?></td>
                <td style="font-size: 11px;">
                    <?= e($payout['bank_name']) ?><br>
                    ****<?= substr(e($payout['bank_account_number']), -4) ?>
                </td>
                <td>
                    <span class="status-<?= $payout['status'] ?>">
                        <?= ucfirst($payout['status']) ?>
                    </span>
                </td>
                <td><?= $payout['processed_at'] ? date('d M Y', strtotime($payout['processed_at'])) : '-' ?></td>
                <td style="max-width: 200px; font-size: 11px; color: #64748b;">
                    <?php if ($payout['status'] == 'rejected' && !empty($payout['admin_notes'])): ?>
                        <span style="color: #dc2626;">
                            <i class="bi bi-exclamation-triangle"></i> 
                            <?= e($payout['admin_notes']) ?>
                        </span>
                    <?php elseif ($payout['status'] == 'approved' && !empty($payout['admin_notes'])): ?>
                        <span style="color: #28a745; font-size: 10px;">
                            <?= e($payout['admin_notes']) ?>
                        </span>
                    <?php elseif ($payout['status'] == 'completed' && !empty($payout['transaction_reference'])): ?>
                        <span style="font-size: 10px;">
                            Ref: <?= e($payout['transaction_reference']) ?>
                        </span>
                    <?php else: ?>
                        —
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

    <!-- Charts Section -->
    <div class="charts-grid">
        <div class="chart-card">
            <div class="chart-title">
                <i class="bi bi-graph-up" style="color: #E75A9B;"></i>
                Monthly Earnings Trend
            </div>
            <div class="chart-container">
                <canvas id="monthlyEarningsChart"></canvas>
            </div>
        </div>
        <div class="chart-card">
            <div class="chart-title">
                <i class="bi bi-pie-chart" style="color: #E75A9B;"></i>
                Total Earnings by Language
            </div>
            <div class="chart-container">
                <canvas id="languagePieChart"></canvas>
            </div>
        </div>
    </div>
    <!-- Two Column Layout - Stack on mobile -->
<div class="two-column-layout">
    <!-- Top Students -->
    <div class="glass-card top-students-card">
            <div class="card-header" style="padding: 12px 16px;">
                <h2><i class="bi bi-trophy"></i> Top Students</h2>
                <p>Students who generated the most earnings</p>
            </div>
            <div>
                <?php 
                $rank = 1;
                foreach ($topStudents as $studentName => $data): 
                    $rankClass = $rank == 1 ? 'gold' : ($rank == 2 ? 'silver' : ($rank == 3 ? 'bronze' : ''));
                ?>
                <div class="top-student-card">
                    <div class="rank-badge <?= $rankClass ?>"><?= $rank ?></div>
                    <div style="flex: 1;">
                        <strong><?= e($studentName) ?></strong>
                        <div style="font-size: 11px; color: #64748b;">
                            <?= $data['count'] ?> session(s) · <?= implode(', ', $data['languages']) ?>
                            <?php if ($data['no_shows'] > 0): ?>
                                <span class="badge-no-show"><?= $data['no_shows'] ?> no-show(s)</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div>
                        <strong class="positive"><?= formatMoney($data['total']) ?></strong>
                    </div>
                </div>
                <?php 
                    $rank++;
                    if ($rank > 5) break;
                endforeach; 
                ?>
            </div>
        </div>
    <!-- Monthly Breakdown -->
    <div class="glass-card">
        <div class="card-header">
            <h2><i class="bi bi-bar-chart-steps"></i> Monthly Breakdown</h2>
            <p>Your earnings per month (after commission) - Includes attended sessions and student no-shows</p>
        </div>
        <table class="earnings-table">
            <thead>
                <tr>
                    <th>Month</th>
                    <th>Attended</th>
                    <th>Student Not Shows</th>
                    <th>Total Sessions</th>
                    <th>Gross</th>
                    <th>Commission (20%)</th>
                    <th>Net Earnings</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($monthlyEarnings as $month => $data): 
                    $monthName = date('F Y', strtotime($month . '-01'));
                ?>
                <tr>
                    <td><strong><?= e($monthName) ?></strong></td>
                    <td><?= $data['attended'] ?></td>
                    <td><?= $data['student_no_shows'] ?> <?php if ($data['student_no_shows'] > 0): ?><span class="badge-no-show">no report needed</span><?php endif; ?></td>
                    <td><?= $data['count'] ?></td>
                    <td><?= formatMoney($data['gross']) ?></td>
                    <td class="commission">- <?= formatMoney($data['commission']) ?></td>
                    <td class="positive"><?= formatMoney($data['net']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<!-- Recent Earnings Table -->
<div class="glass-card" style="margin-top: 24px;">
    <div class="card-header" style="display: flex; justify-content: space-between; align-items: center;">
        <div>
            <h2><i class="bi bi-clock-history"></i> Recent Earnings</h2>
            <p>Transaction history - Includes both attended sessions and student no-shows</p>
        </div>
        <?php if (count($verifiedBookings) > 5): ?>
            <button id="toggleEarningsBtn" style="background: none; border: none; color: #E75A9B; font-size: 12px; cursor: pointer;">
                <i class="bi bi-eye"></i> View All (<?= count($verifiedBookings) ?>)
            </button>
        <?php endif; ?>
    </div>
    <div id="recentEarningsContainer">
        <table class="earnings-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Student</th>
                    <th>Language</th>
                    <th>Session Fee</th>
                    <th>Commission (20%)</th>
                    <th>Your Earnings</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody id="earningsTableBody">
                <?php 
                $count = 0;
                foreach ($verifiedBookings as $booking): 
                    $count++;
                    if ($count > 5) continue;
                    $amount = floatval($booking['total_amount']);
                    $commission = $amount * $COMMISSION_RATE;
                    $net = $amount - $commission;
                    $isNoShow = ($booking['student_confirmed'] == 0 && $booking['no_show_type'] == 'student_no_show');
                ?>
                <tr class="earning-row">
                    <td><?= date('d M Y', strtotime($booking['booking_date'])) ?></td>
                    <td><?= e($booking['student_name']) ?> <?php if ($isNoShow): ?><span class="badge-no-show">No-Show</span><?php endif; ?></td>
                    <td><?= e($booking['language']) ?></td>
                    <td><?= formatMoney($amount) ?></td>
                    <td class="commission">- <?= formatMoney($commission) ?></td>
                    <td class="positive"><?= formatMoney($net) ?></td>
                    <td>
                        <?php if ($isNoShow): ?>
                            <span class="status-approved"><i class="bi bi-calendar-x"></i> No report needed</span>
                        <?php else: ?>
                            <span class="status-approved"><i class="bi bi-check-circle"></i> Report submitted</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tbody id="moreEarningsBody" style="display: none;">
                <?php 
                $count = 0;
                foreach ($verifiedBookings as $booking): 
                    $count++;
                    if ($count <= 5) continue;
                    $amount = floatval($booking['total_amount']);
                    $commission = $amount * $COMMISSION_RATE;
                    $net = $amount - $commission;
                    $isNoShow = ($booking['student_confirmed'] == 0 && $booking['no_show_type'] == 'student_no_show');
                ?>
                <tr class="earning-row">
                    <td><?= date('d M Y', strtotime($booking['booking_date'])) ?></td>
                    <td><?= e($booking['student_name']) ?> <?php if ($isNoShow): ?><span class="badge-no-show">No-Show</span><?php endif; ?></td>
                    <td><?= e($booking['language']) ?></td>
                    <td><?= formatMoney($amount) ?></td>
                    <td class="commission">- <?= formatMoney($commission) ?></td>
                    <td class="positive"><?= formatMoney($net) ?></td>
                    <td>
                        <?php if ($isNoShow): ?>
                            <span class="status-approved"><i class="bi bi-calendar-x"></i> No report needed</span>
                        <?php else: ?>
                            <span class="status-approved"><i class="bi bi-check-circle"></i> Report submitted</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
</div><!-- Payout Modal - Shows ALL bank accounts -->
<div id="payoutModal" class="modal-overlay">
    <div class="modal-container" style="max-width: 500px;">
        <h3 style="margin-bottom: 16px;"><i class="bi bi-cash-stack"></i> Request Payout</h3>
        <input type="hidden" name="return_to_payout" id="return_to_payout" value="0">
        <?php if ($hasBankAccounts): ?>
            <form method="POST" action="">
                <div class="form-group">
                    <label>Select Bank Account</label>
                    <select name="selected_bank" required style="width: 100%; padding: 12px; border: 1px solid #cbd5e1; border-radius: 12px;">
                        <?php foreach ($bankAccounts as $bank): ?>
                            <option value="<?= $bank['id'] ?>" <?= ($bank['is_default']) ? 'selected' : '' ?>>
                                <?= e($bank['bank_name']) ?> - ****<?= substr(e($bank['bank_account_number']), -4) ?>
                                <?= $bank['is_default'] ? '(DEFAULT)' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Payout Amount (RM)</label>
                    <input type="number" name="amount" id="payoutAmount" step="0.01" min="50" max="<?= $availableBalance ?>" required>
                    <small style="font-size: 11px; color: #64748b;">Minimum: RM50 | Maximum: <?= formatMoney($totalNet) ?></small>
                </div>
                
                <div class="modal-buttons">
                    <button type="button" class="btn-cancel" onclick="closePayoutModal()">Cancel</button>
                    <button type="submit" name="request_payout" class="btn-save">Submit Request</button>
                </div>
            </form>
            
            <?php if (count($bankAccounts) < 3): ?>
                <div style="margin-top: 15px; text-align: center; padding-top: 12px; border-top: 1px solid #eef2f7;">
                    <a href="#" onclick="event.preventDefault(); closePayoutModal(); openBankModal();" style="color: #E75A9B; font-size: 12px;">
                        <i class="bi bi-plus-circle"></i> Add another bank account (<?= count($bankAccounts) ?>/3)
                    </a>
                </div>
            <?php endif; ?>
            
        <?php else: ?>
            <div style="background: #fef3c7; padding: 12px; border-radius: 12px; margin-bottom: 15px; color: #92400e; text-align: center;">
                <i class="bi bi-info-circle-fill"></i>
                <strong>No bank account added yet.</strong><br>
                Your first bank account will be automatically set as DEFAULT.
            </div>
            
            <!-- Bank Account Form (inline when no accounts) -->
            <div style="background: #f8fafc; padding: 15px; border-radius: 12px; border: 1px solid #e2e8f0;">
                <div style="margin-bottom: 12px;">
                    <label style="font-size: 12px; font-weight: 600;">Bank Name</label>
                    <select name="bank_name" id="modal_bank_name" style="width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 10px;">
                        <option value="">-- Select Bank --</option>
                        <option value="Maybank">Maybank</option>
                        <option value="CIMB Bank">CIMB Bank</option>
                        <option value="Public Bank">Public Bank</option>
                        <option value="RHB Bank">RHB Bank</option>
                        <option value="Hong Leong Bank">Hong Leong Bank</option>
                        <option value="AmBank">AmBank</option>
                        <option value="Bank Islam">Bank Islam</option>
                        <option value="Bank Rakyat">Bank Rakyat</option>
                        <option value="BSN">BSN</option>
                        <option value="OCBC Bank">OCBC Bank</option>
                        <option value="UOB Bank">UOB Bank</option>
                        <option value="Standard Chartered">Standard Chartered</option>
                        <option value="HSBC Bank">HSBC Bank</option>
                        <option value="Other">Other</option>
                    </select>
                </div>
                <div style="margin-bottom: 12px;">
                    <label style="font-size: 12px; font-weight: 600;">Account Number</label>
                    <input type="text" name="bank_account_number" id="modal_bank_account_number" placeholder="e.g., 112233445566" style="width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 10px;">
                </div>
                <div style="margin-bottom: 12px;">
                    <label style="font-size: 12px; font-weight: 600;">Account Holder Name</label>
                    <input type="text" name="bank_account_name" id="modal_bank_account_name" placeholder="As shown on bank statement" style="width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 10px;">
                </div>
                <div>
                    <label style="display: flex; align-items: center; gap: 8px; font-size: 12px;">
                        <input type="checkbox" id="modal_confirm_bank" required> I confirm the details are correct
                    </label>
                </div>
            </div>
            
            <div class="modal-buttons" style="margin-top: 15px;">
                <button type="button" class="btn-cancel" onclick="closePayoutModal()">Cancel</button>
                <button type="button" class="btn-save" onclick="saveBankAndRequestPayout()">Add & Request Payout</button>
            </div>
        <?php endif; ?>
    </div>
</div>

    <!-- Bank Account Add/Edit Modal - SIMPLIFIED -->
    <div id="bankModal" style="display: none;">
        <div class="modal-container" style="max-width: 500px; width: 90%; margin: auto; position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; border-radius: 24px; z-index: 1001; box-shadow: 0 20px 35px rgba(0,0,0,0.2);">
            <div class="modal-header" style="padding: 20px 24px; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center;">
                <h2 id="bankModalTitle" style="font-size: 20px; margin: 0;"><i class="bi bi-bank2"></i> Add Bank Account</h2>
                <button class="modal-close" onclick="closeBankModal()" style="background: none; border: none; font-size: 28px; cursor: pointer; color: #94a3b8;">&times;</button>
            </div>
            <form method="POST" action="" id="bankForm">
                <input type="hidden" name="action" value="save_bank">
                <input type="hidden" name="bank_id" id="bank_id" value="0">
                <div class="modal-body" style="padding: 24px;">
                    <div class="form-group" style="margin-bottom: 20px;">
                        <label style="display: block; margin-bottom: 8px; font-weight: 600; font-size: 13px;">Bank Name</label>
                        <select name="bank_name" id="bank_name" required style="width: 100%; padding: 12px; border: 1px solid #cbd5e1; border-radius: 12px;">
                            <option value="">-- Select Bank --</option>
                            <option value="Maybank">Maybank</option>
                            <option value="CIMB Bank">CIMB Bank</option>
                            <option value="Public Bank">Public Bank</option>
                            <option value="RHB Bank">RHB Bank</option>
                            <option value="Hong Leong Bank">Hong Leong Bank</option>
                            <option value="AmBank">AmBank</option>
                            <option value="Bank Islam">Bank Islam</option>
                            <option value="Bank Rakyat">Bank Rakyat</option>
                            <option value="BSN">BSN</option>
                            <option value="OCBC Bank">OCBC Bank</option>
                            <option value="UOB Bank">UOB Bank</option>
                            <option value="Standard Chartered">Standard Chartered</option>
                            <option value="HSBC Bank">HSBC Bank</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                    
                    <div class="form-group" style="margin-bottom: 20px;">
                        <label style="display: block; margin-bottom: 8px; font-weight: 600; font-size: 13px;">Account Number</label>
                        <input type="text" name="bank_account_number" id="bank_account_number" placeholder="e.g., 112233445566" required pattern="[0-9]{8,20}" style="width: 100%; padding: 12px; border: 1px solid #cbd5e1; border-radius: 12px;">
                        <small style="font-size: 11px; color: #64748b;">Numbers only, 8-20 digits</small>
                    </div>
                    
                    <div class="form-group" style="margin-bottom: 20px;">
                        <label style="display: block; margin-bottom: 8px; font-weight: 600; font-size: 13px;">Account Holder Name</label>
                        <input type="text" name="bank_account_name" id="bank_account_name" placeholder="As shown on bank statement" required style="width: 100%; padding: 12px; border: 1px solid #cbd5e1; border-radius: 12px;">
                    </div>
                    
                    <div class="form-group">
                        <label style="display: flex; align-items: center; gap: 10px; cursor: pointer;">
                            <input type="checkbox" required style="width: 18px; height: 18px;"> 
                            <span style="font-size: 12px;">I confirm that the bank details above are correct and belong to me.</span>
                        </label>
                    </div>
                </div>
                <div class="modal-footer" style="padding: 16px 24px; border-top: 1px solid #e2e8f0; display: flex; justify-content: flex-end; gap: 12px;">
                    <button type="button" class="btn-cancel" onclick="closeBankModal()" style="padding: 8px 20px; border-radius: 30px; border: 1px solid #cbd5e1; background: white; cursor: pointer;">Cancel</button>
                    <button type="submit" class="btn-save" style="padding: 8px 20px; border-radius: 30px; background: #28a745; color: white; border: none; cursor: pointer;">Save Bank Account</button>
                </div>
            </form>
        </div>
    </div>

    <?php endif; ?>

</div>
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

function saveBankAndRequestPayout() {
    const bankName = document.getElementById('modal_bank_name').value;
    const bankAccountNumber = document.getElementById('modal_bank_account_number').value;
    const bankAccountName = document.getElementById('modal_bank_account_name').value;
    const confirmCheckbox = document.getElementById('modal_confirm_bank');
    
    if (!bankName) {
        alert('Please select a bank name');
        return;
    }
    if (!bankAccountNumber) {
        alert('Please enter account number');
        return;
    }
    if (!bankAccountName) {
        alert('Please enter account holder name');
        return;
    }
    if (!confirmCheckbox.checked) {
        alert('Please confirm the bank details are correct');
        return;
    }
    
    const form = document.createElement('form');
    form.method = 'POST';
    form.innerHTML = `
        <input type="hidden" name="action" value="save_bank">
        <input type="hidden" name="bank_name" value="${bankName}">
        <input type="hidden" name="bank_account_number" value="${bankAccountNumber}">
        <input type="hidden" name="bank_account_name" value="${bankAccountName}">
        <input type="hidden" name="amount" value="${document.getElementById('payoutAmount').value}">
        <input type="hidden" name="request_payout" value="1">
    `;
    document.body.appendChild(form);
    form.submit();
}

function openBankModal() {
    document.getElementById('bank_id').value = '0';
    document.getElementById('bank_name').value = '';
    document.getElementById('bank_account_number').value = '';
    document.getElementById('bank_account_name').value = '';
    
    const modal = document.getElementById('bankModal');
    if (modal) {
        modal.style.display = 'block';
        document.body.style.overflow = 'hidden';
    }
}

function closeBankModal() {
    const modal = document.getElementById('bankModal');
    if (modal) {
        modal.style.display = 'none';
        document.body.style.overflow = 'auto';
    }
}

function openPayoutModal(maxAmount) {
    document.getElementById('payoutAmount').max = maxAmount;
    document.getElementById('payoutModal').classList.add('active');
}

function closePayoutModal() {
    document.getElementById('payoutModal').classList.remove('active');
}

// Monthly Earnings Chart
const monthlyCtx = document.getElementById('monthlyEarningsChart')?.getContext('2d');
if (monthlyCtx) {
    new Chart(monthlyCtx, {
        type: 'line',
        data: {
            labels: <?= json_encode($chartMonths) ?>,
            datasets: [{
                label: 'Net Earnings (RM)',
                data: <?= json_encode($chartNetEarnings) ?>,
                borderColor: '#E75A9B',
                backgroundColor: 'rgba(231,90,155,0.1)',
                borderWidth: 3,
                fill: true,
                tension: 0.4,
                pointBackgroundColor: '#E75A9B',
                pointBorderColor: '#fff',
                pointBorderWidth: 2,
                pointRadius: 5
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            plugins: { legend: { position: 'top' } },
            scales: { y: { beginAtZero: true, ticks: { callback: function(value) { return ' ' + value; } } } }
        }
    });
}

// Language Pie Chart - Clean tooltip without duplicate label
const pieCtx = document.getElementById('languagePieChart')?.getContext('2d');
if (pieCtx) {
    new Chart(pieCtx, {
        type: 'pie',
        data: {
            labels: <?= json_encode(array_keys($languageEarnings)) ?>,
            datasets: [{
                data: <?= json_encode(array_values(array_column($languageEarnings, 'total'))) ?>,
                backgroundColor: ['#E75A9B', '#F28AB2', '#A77BE8', '#7648B8', '#F59E0B', '#10B981', '#3B82F6', '#EF4444'],
                borderWidth: 0
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            plugins: {
                legend: { 
                    position: 'right',
                    labels: {
                        font: { size: 11 }
                    }
                },
                tooltip: {
                    backgroundColor: 'white',
                    titleColor: '#1d3156',
                    bodyColor: '#475569',
                    borderColor: '#e2e8f0',
                    borderWidth: 1,
                    callbacks: {
                        title: (tooltipItems) => {
                            // Language name appears once as title
                            return tooltipItems[0].label;
                        },
                        label: (context) => {
                            // Everything combined in one line
                            const value = context.raw || 0;
                            const sessions = <?= json_encode(array_values(array_column($languageEarnings, 'count'))) ?>[context.dataIndex];
                            return `RM${value.toFixed(2)} (${sessions} sessions)`;
                        }
                    }
                }
            }
        }
    });
}

window.onclick = function(event) {
    const modal = document.getElementById('payoutModal');
    if (event.target === modal) {
        closePayoutModal();
    }
}
// Toggle Recent Earnings View (without page refresh)
const toggleBtn = document.getElementById('toggleEarningsBtn');
if (toggleBtn) {
    toggleBtn.addEventListener('click', function() {
        const moreRows = document.getElementById('moreEarningsBody');
        const isShowingAll = moreRows.style.display !== 'none';
        
        if (isShowingAll) {
            moreRows.style.display = 'none';
            toggleBtn.innerHTML = '<i class="bi bi-eye"></i> View All (<?= count($verifiedBookings) ?>)';
        } else {
            moreRows.style.display = 'table-row-group';
            toggleBtn.innerHTML = '<i class="bi bi-eye-slash"></i> Show Less';
        }
        
        // Save preference to localStorage
        localStorage.setItem('showAllEarnings', !isShowingAll);
    });
    
    // Load saved preference
    const savedPreference = localStorage.getItem('showAllEarnings');
    if (savedPreference === 'true') {
        const moreRows = document.getElementById('moreEarningsBody');
        if (moreRows) {
            moreRows.style.display = 'table-row-group';
            toggleBtn.innerHTML = '<i class="bi bi-eye-slash"></i> Show Less';
        }
    }
}
// Auto-open payout modal when bank_added parameter is present
<?php if (isset($_GET['bank_added']) && $_GET['bank_added'] == 1): ?>
setTimeout(function() {
    openPayoutModal(<?= $totalNet ?>);
    const toast = document.createElement('div');
    toast.style.cssText = 'position: fixed; bottom: 20px; right: 20px; background: #28a745; color: white; padding: 12px 20px; border-radius: 8px; z-index: 9999;';
    toast.innerHTML = '<i class="bi bi-check-circle"></i> Bank account added! Select it below for payout.';
    document.body.appendChild(toast);
    setTimeout(function() { if(toast) toast.remove(); }, 3000);
}, 500);
<?php endif; ?>
</script>
<script>
function dismissAlert(type, buttonElement) {
    // Hide the alert immediately
    const alertDiv = buttonElement.closest('.alert');
    if (alertDiv) {
        alertDiv.style.display = 'none';
    }
    
    // Send request to mark as seen in session
    fetch(`earnings.php?dismiss_${type}=1`, { method: 'GET' })
        .then(response => {
            if (!response.ok) {
                console.error('Failed to dismiss alert');
            }
        })
        .catch(error => console.error('Error:', error));
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
