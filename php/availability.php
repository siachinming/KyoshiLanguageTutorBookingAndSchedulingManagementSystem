<?php
session_start();
include 'config.php';

$assetBase = '../assets/img';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$userID = $_SESSION['user_id'];

/* ─────────────────────────────
   GET TUTOR INFO
───────────────────────────── */
$stmt = $conn->prepare("
    SELECT *
    FROM users
    WHERE id = ? AND role = 'tutor'
");

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
$firstName = explode(' ', trim($displayName))[0];

/* ─────────────────────────────
   CHECK AND ADD timezone COLUMN IF MISSING
───────────────────────────── */
$checkColumn = $conn->query("SHOW COLUMNS FROM tutor_availability LIKE 'timezone'");
if ($checkColumn->num_rows == 0) {
    $conn->query("ALTER TABLE tutor_availability ADD COLUMN timezone VARCHAR(50) DEFAULT 'Asia/Kuala_Lumpur'");
}

/* ─────────────────────────────
   HANDLE ADD AVAILABILITY
───────────────────────────── */
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_slot'])) {
        $day_of_week = $_POST['day_of_week'];
        $start_time = $_POST['start_time'];
        $end_time = $_POST['end_time'];
        $timezone = isset($_POST['timezone']) ? $_POST['timezone'] : 'Asia/Kuala_Lumpur';
        
        // VALIDATION 1: Check if end time is after start time
        if ($start_time >= $end_time) {
            $message = "End time must be after start time!";
            $messageType = "error";
        }
        else {
            // Calculate duration in minutes
            $startDateTime = new DateTime($start_time);
            $endDateTime = new DateTime($end_time);
            $interval = $startDateTime->diff($endDateTime);
            $totalMinutes = ($interval->h * 60) + $interval->i;
            
            // VALIDATION 2: MUST BE AT LEAST 1 HOUR (60 minutes)
            if ($totalMinutes < 60) {
                $message = "Time slot must be at least 1 hour (60 minutes)! Current: " . floor($totalMinutes/60) . "h " . ($totalMinutes % 60) . "m";
                $messageType = "error";
            }
            else {
                // Check for overlapping slots (back-to-back allowed)
                $checkOverlap = $conn->prepare("
                    SELECT COUNT(*) as count 
                    FROM tutor_availability 
                    WHERE tutor_id = ? 
                    AND day_of_week = ? 
                    AND (
                        (start_time < ? AND end_time > ?) OR
                        (start_time < ? AND end_time > ?)
                    )
                ");
                $checkOverlap->bind_param("isssss", 
                    $userID, $day_of_week, 
                    $end_time, $start_time,
                    $end_time, $start_time
                );
                $checkOverlap->execute();
                $overlapResult = $checkOverlap->get_result()->fetch_assoc();
                
                if ($overlapResult['count'] > 0) {
                    $message = "This time slot overlaps with an existing slot on $day_of_week!";
                    $messageType = "error";
                } else {
                    // Insert the slot
                    $stmt = $conn->prepare("
                        INSERT INTO tutor_availability (tutor_id, day_of_week, start_time, end_time, timezone)
                        VALUES (?, ?, ?, ?, ?)
                    ");
                    $stmt->bind_param("issss", $userID, $day_of_week, $start_time, $end_time, $timezone);
                    
                    if ($stmt->execute()) {
                        $message = "Availability slot added successfully!";
                        $messageType = "success";
                    } else {
                        $message = "Error adding availability: " . $conn->error;
                        $messageType = "error";
                    }
                }
            }
        }
    }
    
    // Delete single slot
    if (isset($_POST['delete_slot'])) {
        $slot_id = $_POST['slot_id'];
        
        // Check if this slot has any future bookings
        $checkBookings = $conn->prepare("
            SELECT COUNT(*) as booking_count
            FROM bookings b
            WHERE b.tutor_id = ?
            AND b.booking_date >= CURDATE()
            AND b.status NOT IN ('cancelled', 'completed')
            AND TIME(b.booking_time) >= (SELECT start_time FROM tutor_availability WHERE id = ?)
            AND TIME(b.booking_time) < (SELECT end_time FROM tutor_availability WHERE id = ?)
        ");
        $checkBookings->bind_param("iii", $userID, $slot_id, $slot_id);
        $checkBookings->execute();
        $bookingResult = $checkBookings->get_result()->fetch_assoc();
        $bookingCount = $bookingResult['booking_count'] ?? 0;
        
        if ($bookingCount > 0) {
            $message = "Cannot delete this time slot because it has $bookingCount upcoming booking(s).";
            $messageType = "error";
        } else {
            $stmt = $conn->prepare("DELETE FROM tutor_availability WHERE id = ? AND tutor_id = ?");
            $stmt->bind_param("ii", $slot_id, $userID);
            
            if ($stmt->execute()) {
                $message = "Slot deleted successfully!";
                $messageType = "success";
            } else {
                $message = "Error deleting slot: " . $conn->error;
                $messageType = "error";
            }
        }
    }
    
    // Delete ALL slots for a specific day
    if (isset($_POST['delete_all_day'])) {
        $day_to_delete = $_POST['day_to_delete'];
        
        $checkDayBookings = $conn->prepare("
            SELECT COUNT(*) as booking_count
            FROM bookings b
            JOIN tutor_availability ta ON b.tutor_id = ta.tutor_id
            WHERE b.tutor_id = ?
            AND ta.day_of_week = ?
            AND b.booking_date >= CURDATE()
            AND b.status NOT IN ('cancelled', 'completed')
        ");
        $checkDayBookings->bind_param("is", $userID, $day_to_delete);
        $checkDayBookings->execute();
        $dayBookingResult = $checkDayBookings->get_result()->fetch_assoc();
        $dayBookingCount = $dayBookingResult['booking_count'] ?? 0;
        
        if ($dayBookingCount > 0) {
            $message = "Cannot delete all slots for $day_to_delete because $dayBookingCount slot(s) have upcoming bookings.";
            $messageType = "error";
        } else {
            $stmt = $conn->prepare("DELETE FROM tutor_availability WHERE tutor_id = ? AND day_of_week = ?");
            $stmt->bind_param("is", $userID, $day_to_delete);
            
            if ($stmt->execute()) {
                $deletedCount = $stmt->affected_rows;
                $message = "Deleted all $deletedCount slot(s) for $day_to_delete!";
                $messageType = "success";
            } else {
                $message = "Error deleting slots: " . $conn->error;
                $messageType = "error";
            }
        }
    }

    // EDIT/UPDATE SLOT
    if (isset($_POST['update_slot'])) {
        $slot_id = $_POST['slot_id'];
        $day_of_week = $_POST['day_of_week'];
        $start_time = $_POST['start_time'];
        $end_time = $_POST['end_time'];
        $timezone = isset($_POST['timezone']) ? $_POST['timezone'] : 'Asia/Kuala_Lumpur';
        
        if ($start_time >= $end_time) {
            $message = "End time must be after start time!";
            $messageType = "error";
        } else {
            $startDateTime = new DateTime($start_time);
            $endDateTime = new DateTime($end_time);
            $interval = $startDateTime->diff($endDateTime);
            $totalMinutes = ($interval->h * 60) + $interval->i;
            
            if ($totalMinutes < 60) {
                $message = "Time slot must be at least 1 hour (60 minutes)!";
                $messageType = "error";
            } else {
               // Check for overlaps with OTHER slots (excluding current)
                $checkOverlap = $conn->prepare("
                    SELECT COUNT(*) as count 
                    FROM tutor_availability 
                    WHERE tutor_id = ? 
                    AND day_of_week = ? 
                    AND id != ?
                    AND start_time < ?
                    AND end_time > ?
                ");
                $checkOverlap->bind_param("isiss", 
                    $userID,        // i
                    $day_of_week,   // s
                    $slot_id,       // i
                    $end_time,      // s - new slot ends after existing starts
                    $start_time     // s - new slot starts before existing ends
                );
                $checkOverlap->execute();
                $overlapResult = $checkOverlap->get_result()->fetch_assoc();
                
                if ($overlapResult['count'] > 0) {
                    $message = "This would overlap with another slot on $day_of_week!";
                    $messageType = "error";
                } else {
                    $stmt = $conn->prepare("
                        UPDATE tutor_availability 
                        SET day_of_week = ?, start_time = ?, end_time = ?, timezone = ?
                        WHERE id = ? AND tutor_id = ?
                    ");
                    $stmt->bind_param("ssssii", $day_of_week, $start_time, $end_time, $timezone, $slot_id, $userID);
                    
                    if ($stmt->execute()) {
                        $message = "Slot updated successfully!";
                        $messageType = "success";
                        // Redirect to clear edit mode
                        header("Location: availability.php");
                        exit();
                    } else {
                        $message = "Error updating slot: " . $conn->error;
                        $messageType = "error";
                    }
                }
            }
        }
    }
}
/* ─────────────────────────────
   FETCH AVAILABILITY SLOTS
───────────────────────────── */

// Check if editing a slot FIRST (separate query)
$editSlot = null;
if (isset($_GET['edit_id'])) {
    $editId = intval($_GET['edit_id']);
    $stmt = $conn->prepare("SELECT * FROM tutor_availability WHERE id = ? AND tutor_id = ?");
    $stmt->bind_param("ii", $editId, $userID);
    $stmt->execute();
    $editSlot = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

// THEN fetch all availability slots
$stmt = $conn->prepare("
    SELECT * FROM tutor_availability
    WHERE tutor_id = ?
    ORDER BY FIELD(day_of_week, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'), start_time
");
$stmt->bind_param("i", $userID);
$stmt->execute();
$availabilitySlots = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$daysOfWeek = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
function e($value) {
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Manage Availability - Kyoshi Tutor</title>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">

<style>*{
    margin:0;
    padding:0;
    box-sizing:border-box;
}

body{
    font-family:'Poppins',sans-serif;
    background: url('../assets/img/background2.png') no-repeat center top;
    background-size: cover;
    min-height: 100vh;
    position: relative;
}

/* Dark transparent overlay */
body::before{
    content:'';
    position:fixed;
    inset:0;
    background: rgba(255,255,255,0.25);
    z-index:-1;
}


.topbar{
    width:100%;
    background: rgba(254, 214, 206, 0.92);
    backdrop-filter: blur(12px);
    position:sticky;
    top:0;
    z-index:999;
    box-shadow:0 2px 20px rgba(0,0,0,0.08);
    border-bottom:1px solid rgba(255,255,255,0.3);
}

.container{
    width:min(1400px,94%);
    margin:auto;
}

.nav{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap: 32px;
    min-height: 70px;
}

/* BRAND */
.brand{
    display:flex;
    align-items:center;
    gap:10px;
    text-decoration:none;
    flex-shrink: 0;
}

.brand img{
    width:42px;
    height:42px;
    object-fit:contain;
}

.brand strong{
    display:block;
    color: #1d3156;
    font-size:20px;
    line-height: 1.2;
    letter-spacing:-0.3px;
}

.brand span{
    color:#496894;
    font-size:11px;
    font-weight:600;
    letter-spacing:0.5px;
}

/* NAVIGATION */
.nav-links{
    display:flex;
    gap: 28px;
    align-items: center;
    flex-wrap: wrap;
}

.nav-links a{
    text-decoration:none;
    color:#1d3156;
    font-size:14px;
    font-weight:600;
    position:relative;
    transition:0.25s;
    padding: 6px 0;
    white-space: nowrap;
}

.nav-links a:hover,
.nav-links a.active{
    color:#496894;
    font-weight:700;
}

.nav-links a::after{
    content:'';
    position:absolute;
    left:0;
    bottom:-6px;
    width:0%;
    height:3px;
    background:#496894;
    transition:0.25s;
    border-radius:10px;
}

.nav-links a:hover::after,
.nav-links .active::after{
    width:100%;
}

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
}

.profile:hover {
    background: rgba(255, 255, 255, 0.2);
}

.profile img {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    object-fit: cover;
    border: 2px solid rgba(255, 255, 255, 0.3);
}

.profile span {
    font-size: 13px;
    font-weight: 500;
}

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
    transition: 0.2s;
}

.dropdown a:hover {
    background: #f8fafc;
}

.dropdown hr {
    border: none;
    border-top: 1px solid #ecf3f9;
}

/* Main Content */
.main {
    width: min(1280px, 92%);
    margin: 32px auto 48px;
}

/* Page Header */
.page-header {
    background: linear-gradient(135deg, rgba(29, 49, 86, 0.95), rgba(73, 104, 148, 0.9));
    border-radius: 28px;
    padding: 32px 36px;
    color: white;
    margin-bottom: 32px;
}

.page-header h1 {
    font-size: 32px;
    font-weight: 700;
    margin-bottom: 10px;
}

.page-header p {
    color: #cbddee;
    font-size: 15px;
}

/* Alert */
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

.alert-success {
    background: #d4edda;
    color: #155724;
    border-left: 4px solid #28a745;
}

.alert-error {
    background: #f8d7da;
    color: #721c24;
    border-left: 4px solid #dc3545;
}

/* Two Column Layout */
.availability-container {
    display: grid;
    grid-template-columns: 1fr 1.5fr;
    gap: 30px;
}

@media (max-width: 900px) {
    .availability-container {
        grid-template-columns: 1fr;
    }
}

/* Form Card - Simplified */
.form-card {
    background: white;
    border-radius: 24px;
    padding: 28px;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
}

.form-card h2 {
    font-size: 20px;
    font-weight: 700;
    color: #1d3156;
    margin-bottom: 24px;
    display: flex;
    align-items: center;
    gap: 10px;
    padding-bottom: 12px;
    border-bottom: 2px solid #eef2f7;
}

.form-group {
    margin-bottom: 20px;
}

.form-group label {
    display: block;
    font-size: 13px;
    font-weight: 600;
    color: #475569;
    margin-bottom: 8px;
}

.form-group select,
.form-group input {
    width: 100%;
    padding: 12px 14px;
    border: 1.5px solid #e2e8f0;
    border-radius: 12px;
    font-family: 'Poppins', sans-serif;
    font-size: 14px;
    transition: 0.2s;
    background: white;
}

.form-group select:focus,
.form-group input:focus {
    outline: none;
    border-color: #1d3156;
    box-shadow: 0 0 0 3px rgba(29, 49, 86, 0.1);
}

.form-hint {
    font-size: 11px;
    color: #64748b;
    margin-top: 5px;
    display: block;
}

.btn-primary {
    width: 100%;
    background: #1d3156;
    color: white;
    border: none;
    padding: 14px;
    border-radius: 12px;
    font-size: 15px;
    font-weight: 600;
    cursor: pointer;
    transition: 0.25s;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
}

.btn-primary:hover {
    background: #2a4575;
    transform: translateY(-2px);
}

/* Rules Box */
.rules-box {
    background: #f0f9ff;
    border-left: 4px solid #1d3156;
    padding: 12px 16px;
    border-radius: 12px;
    margin-top: 20px;
}

.rules-box p {
    font-size: 12px;
    color: #475569;
    margin: 5px 0;
}

.rules-box i {
    color: #1d3156;
    margin-right: 6px;
}

/* Slots Card */
.slots-card {
    background: white;
    border-radius: 24px;
    padding: 28px;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
    display: flex;
    flex-direction: column;
}
/* Remove extra margin from last item */
.day-group:last-child {
    margin-bottom: 0;
}

.slot-item:last-child {
    margin-bottom: 0;
}

.slots-card h2 {
    font-size: 20px;
    font-weight: 700;
    color: #1d3156;
    margin-bottom: 24px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding-bottom: 12px;
    border-bottom: 2px solid #eef2f7;
}

.slots-list {
    min-height: 400px;  /* Minimum height */
    max-height: 600px;  /* Maximum height before scroll */
    overflow-y: auto;
}

.empty-slots {
    text-align: center;
    padding: 40px 20px;
    color: #94a3b8;
}

.empty-slots i {
    font-size: 64px;
    margin-bottom: 16px;
    display: block;
}

/* Day Group */
.day-group {
    margin-bottom: 24px;
    border: 1px solid #eef2f7;
    border-radius: 16px;
    overflow: hidden;
}

.day-group-header {
    font-weight: 700;
    color: #1d3156;
    background: #eef2ff;
    padding: 12px 16px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.btn-delete-day {
    background: #fee2e2;
    border: none;
    color: #dc2626;
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 6px;
}

.btn-delete-day:hover {
    background: #dc2626;
    color: white;
}

.day-group-slots {
    padding: 12px;
}

/* Slot Item */
.slot-item {
    background: #f8fafc;
    border-radius: 16px;
    padding: 16px;
    margin-bottom: 12px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    transition: 0.2s;
    border: 1px solid #eef2f7;
}

.slot-item:hover {
    background: white;
    border-color: #cbd5e1;
}

.slot-time {
    color: #64748b;
    font-size: 14px;
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}

.slot-duration {
    background: #dcfce7;
    color: #166534;
    padding: 2px 8px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 600;
}

.slot-timezone {
    background: #e2e8f0;
    padding: 2px 8px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 600;
    color: #475569;
}

.btn-delete {
    background: #fee2e2;
    border: none;
    color: #dc2626;
    width: 36px;
    height: 36px;
    border-radius: 10px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
}

.btn-delete:hover {
    background: #dc2626;
    color: white;
}

@media (max-width: 768px) {
    .page-header {
        padding: 24px;
    }
    .page-header h1 {
        font-size: 24px;
    }
    .form-card, .slots-card {
        padding: 20px;
    }
    .nav-links {
        gap: 14px;
    }
    .nav-links a {
        font-size: 12px;
    }
    .day-group-header {
        flex-direction: column;
        gap: 10px;
        align-items: stretch;
    }
}

/* Modal styles */
#editModal {
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

#editModal.active {
    display: flex;
}

/* Add this to your existing CSS */
.form-control {
    width: 100%;
    padding: 12px 14px;
    border: 1.5px solid #e2e8f0;
    border-radius: 12px;
    font-family: 'Poppins', sans-serif;
    font-size: 14px;
    transition: 0.2s;
    background: white;
}

.form-control:focus {
    outline: none;
    border-color: #1d3156;
    box-shadow: 0 0 0 3px rgba(29, 49, 86, 0.1);
}

/* Back Button */
.back-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: white;
    color: #1d3156;
    padding: 8px 18px;
    border-radius: 40px;
    text-decoration: none;
    font-weight: 600;
    font-size: 13px;
    transition: 0.25s;
    border: 1px solid #e2e8f0;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
    z-index: 10;
}

.back-btn:hover {
    background: #f8fafc;
    border-color: #cbd5e1;
    transform: translateX(-3px);
}

.back-btn i {
    font-size: 14px;
}

/* Responsive */
@media (max-width: 768px) {
    div[style*="position: absolute"] {
        position: relative !important;
        left: auto !important;
        transform: none !important;
        margin: 10px 0;
    }
    
    .back-btn {
        position: relative;
        z-index: 10;
    }
}

</style>
</head>

<body>

<header class="topbar">
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
   <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; flex-wrap: wrap; gap: 15px; position: relative;">
    <a href="tutor_dashboard.php" class="back-btn" style="flex-shrink: 0;">
        <i class="bi bi-arrow-left"></i> Back
    </a>
    
    <div style="position: absolute; left: 50%; transform: translateX(-50%); text-align: center;">
        <h1 style="font-size: 28px; font-weight: 800; color: #1d3156; margin: 0; white-space: nowrap;">
            Manage Availability
        </h1>
        <p style="color: #1e293b; margin: 6px 0 0; font-size: 13px; font-weight: 500;">
            Set your available teaching hours
        </p>
    </div>
    
    <div style="width: 70px; flex-shrink: 0;"></div>
</div><br>

    <?php if ($message): ?>
        <div class="alert alert-<?= e($messageType) ?>">
            <i class="bi bi-<?= $messageType === 'success' ? 'check-circle' : 'exclamation-circle' ?>"></i>
            <?= e($message) ?>
        </div>
    <?php endif; ?>

    <div class="availability-container">
        <!-- Add Slot Form -->
        <div class="form-card">
            <h2><i class="bi bi-plus-circle"></i> Add New Time Slot</h2>
            
            <form method="POST" action="">
                <div class="form-group">
                    <label>Day of Week</label>
                    <select name="day_of_week" required>
                        <option value="">Select Day</option>
                        <?php foreach ($daysOfWeek as $day): ?>
                            <option value="<?= $day ?>"><?= $day ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Start Time</label>
                    <input type="time" name="start_time" required>
                </div>
                
                <div class="form-group">
                    <label>End Time</label>
                    <input type="time" name="end_time" required>
                    <small class="form-hint"><i class="bi bi-exclamation-triangle"></i> Must be at least 1 hour after start time</small>
                </div>
                
                <div class="form-group">
                    <label>Timezone</label>
                    <select name="timezone">
                        <option value="Asia/Kuala_Lumpur">Asia/Kuala_Lumpur (GMT+8)</option>
                        <option value="Asia/Singapore">Asia/Singapore (GMT+8)</option>
                        <option value="UTC">UTC</option>
                    </select>
                </div>
                
                <button type="submit" name="add_slot" class="btn-primary">
                    <i class="bi bi-calendar-plus"></i> Add Time Slot
                </button>
            </form>
            
            <div class="rules-box">
                <p><i class="bi bi-check-circle-fill"></i> <strong>Slot Rules:</strong></p>
                <p><i class="bi bi-clock"></i> <strong>Minimum 1 hour</strong> (60 minutes)</p>
                <p><i class="bi bi-arrow-left-right"></i> Back-to-back slots allowed (9AM-10AM, 10AM-11AM ✅)</p>
                <p><i class="bi bi-x-circle"></i> Overlapping slots not allowed</p>
            </div>
        </div>

        <!-- Existing Slots -->
        <div class="slots-card">
            <h2>
                <i class="bi bi-calendar-week"></i> Your Available Slots
                <span style="font-size: 14px; font-weight: normal;">(<?= count($availabilitySlots) ?> slots)</span>
            </h2>
            
            <div class="slots-list">
                <?php if (empty($availabilitySlots)): ?>
                    <div class="empty-slots">
                        <i class="bi bi-calendar-x"></i>
                        <p>No availability slots added yet.</p>
                        <p style="font-size: 13px; margin-top: 8px;">Add your first teaching slot above.</p>
                    </div>
                <?php else: ?>
                    <?php
                    $groupedSlots = [];
                    foreach ($availabilitySlots as $slot) {
                        $groupedSlots[$slot['day_of_week']][] = $slot;
                    }
                    ?>
                    
                    <?php foreach ($daysOfWeek as $day): ?>
                        <?php if (isset($groupedSlots[$day])): ?>
                            <div class="day-group">
                                <div class="day-group-header">
                                    <span><i class="bi bi-calendar-day"></i> <?= $day ?> <span style="font-size: 12px; color: #64748b;">(<?= count($groupedSlots[$day]) ?>)</span></span>
                                    <form method="POST" action="" style="margin: 0;" onsubmit="return confirm('⚠️ Delete ALL <?= count($groupedSlots[$day]) ?> slots for <?= $day ?>?');">
                                        <input type="hidden" name="day_to_delete" value="<?= $day ?>">
                                        <button type="submit" name="delete_all_day" class="btn-delete-day">
                                            <i class="bi bi-trash3"></i> Delete All
                                        </button>
                                    </form>
                                </div>
                                <div class="day-group-slots">
                                    <?php foreach ($groupedSlots[$day] as $slot): 
                                        $start = new DateTime($slot['start_time']);
                                        $end = new DateTime($slot['end_time']);
                                        $interval = $start->diff($end);
                                        $hours = $interval->h;
                                        $minutes = $interval->i;
                                        $duration = '';
                                        if ($hours > 0) $duration .= $hours . 'h ';
                                        if ($minutes > 0) $duration .= $minutes . 'min';
                                        if (empty($duration)) $duration = '0 min';
                                    ?>
                                        <div class="slot-item">
    <div class="slot-time">
        <i class="bi bi-clock"></i>
        <?= date('g:i A', strtotime($slot['start_time'])) ?> - 
        <?= date('g:i A', strtotime($slot['end_time'])) ?>
        <span class="slot-duration"><i class="bi bi-hourglass-split"></i> <?= $duration ?></span>
    </div>
    <div style="display: flex; gap: 8px;">
        <button onclick='openEditModal(
    <?= $slot['id'] ?>, 
    "<?= $slot['day_of_week'] ?>", 
    "<?= $slot['start_time'] ?>", 
    "<?= $slot['end_time'] ?>", 
    "<?= $slot['timezone'] ?? 'Asia/Kuala_Lumpur' ?>")' class="btn-delete" style="background: #e0e7ff; color: #4f46e5;" title="Edit Slot">
    <i class="bi bi-pencil"></i>
</button>
        <form method="POST" action="" style="margin: 0;" onsubmit="return confirm('Delete this slot?');">
            <input type="hidden" name="slot_id" value="<?= $slot['id'] ?>">
            <button type="submit" name="delete_slot" class="btn-delete" title="Delete Slot">
                <i class="bi bi-trash3"></i>
            </button>
        </form>
    </div>
</div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
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

// Modal functions
function openEditModal(slotId, dayOfWeek, startTime, endTime, timezone) {
    document.getElementById('edit_slot_id').value = slotId;
    document.getElementById('edit_day_of_week').value = dayOfWeek;
    document.getElementById('edit_start_time').value = startTime;
    document.getElementById('edit_end_time').value = endTime;
    document.getElementById('edit_timezone').value = timezone;
    document.getElementById('editModal').style.display = 'flex';
}

function closeEditModal() {
    document.getElementById('editModal').style.display = 'none';
}

// Close modal when clicking outside
window.onclick = function(event) {
    const modal = document.getElementById('editModal');
    if (event.target === modal) {
        closeEditModal();
    }
}

setTimeout(function() {
    const alerts = document.querySelectorAll('.alert');
    alerts.forEach(alert => {
        alert.style.transition = 'opacity 0.5s';
        alert.style.opacity = '0';
        setTimeout(() => alert.remove(), 500);
    });
}, 4000);
</script>
<!-- Edit Slot Modal -->
<div id="editModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:1000; align-items:center; justify-content:center;">
    <div style="background:white; border-radius:24px; width:500px; max-width:90%; padding:28px; box-shadow:0 20px 40px rgba(0,0,0,0.2);">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
            <h2 style="font-size:20px; color:#1d3156;"><i class="bi bi-pencil-square"></i> Edit Time Slot</h2>
            <button onclick="closeEditModal()" style="background:none; border:none; font-size:24px; cursor:pointer; color:#64748b;">&times;</button>
        </div>
        
        <form method="POST" action="" id="editForm">
            <input type="hidden" name="slot_id" id="edit_slot_id">
            
            <div class="form-group">
                <label>Day of Week</label>
                <select name="day_of_week" id="edit_day_of_week" class="form-control" required>
                    <option value="">Select Day</option>
                    <?php foreach ($daysOfWeek as $day): ?>
                        <option value="<?= $day ?>"><?= $day ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-group">
                <label>Start Time</label>
                <input type="time" name="start_time" id="edit_start_time" class="form-control" required>
            </div>
            
            <div class="form-group">
                <label>End Time</label>
                <input type="time" name="end_time" id="edit_end_time" class="form-control" required>
                <small class="form-hint"><i class="bi bi-exclamation-triangle"></i> Must be at least 1 hour after start time</small>
            </div>
            
            <div class="form-group">
                <label>Timezone</label>
                <select name="timezone" id="edit_timezone" class="form-control">
                    <option value="Asia/Kuala_Lumpur">Asia/Kuala_Lumpur (GMT+8)</option>
                    <option value="Asia/Singapore">Asia/Singapore (GMT+8)</option>
                    <option value="UTC">UTC</option>
                </select>
            </div>
            
            <div style="display:flex; gap:12px; margin-top:20px;">
                <button type="submit" name="update_slot" class="btn-primary" style="flex:1; background:#1d3156;">
                    <i class="bi bi-check-lg"></i> Update Slot
                </button>
                <button type="button" onclick="closeEditModal()" class="btn-primary" style="flex:1; background:#64748b;">
                    <i class="bi bi-x-circle"></i> Cancel
                </button>
            </div>
        </form>
    </div>
</div>
</body>
</html>