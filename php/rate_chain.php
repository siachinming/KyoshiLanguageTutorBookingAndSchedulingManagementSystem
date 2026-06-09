<?php
session_start();
include 'config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$userID = $_SESSION['user_id'];
$ids = isset($_GET['ids']) ? explode(',', $_GET['ids']) : [];

if (empty($ids)) {
    header("Location: booking_status.php");
    exit();
}

// Store the queue in session
$_SESSION['rating_queue'] = $ids;
$_SESSION['rating_index'] = 0;

// Redirect to first booking's rating page with next parameter
$remainingIds = array_slice($ids, 1);
$nextParam = !empty($remainingIds) ? '&next=' . implode(',', $remainingIds) : '';

header("Location: rate_session.php?id=" . $ids[0] . "&queue=1" . $nextParam);
exit();
?>