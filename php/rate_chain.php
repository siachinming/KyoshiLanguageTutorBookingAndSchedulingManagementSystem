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

// Redirect to first booking
header("Location: booking_detail.php?id=" . $ids[0] . "#rate&queue=1");
exit();
?>