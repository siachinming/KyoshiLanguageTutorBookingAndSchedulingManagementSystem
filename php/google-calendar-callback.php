<?php
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/google-calendar-helper.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$userId = $_SESSION['user_id'];

if (isset($_GET['code'])) {
    $calendar = new GoogleCalendarHelper($userId);
    
    if ($calendar->authenticate($_GET['code'])) {
        // Update user that they connected Google Calendar
        $stmt = $conn->prepare("UPDATE users SET google_calendar_connected = 1 WHERE id = ?");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        
        $_SESSION['calendar_connected'] = true;
        
        // Redirect back to booking status page
        header("Location: booking_status.php?calendar=connected");
    } else {
        header("Location: booking_status.php?calendar=error");
    }
} else {
    header("Location: booking_status.php");
}
exit();
?>