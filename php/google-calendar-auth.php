<?php
session_start();
require_once __DIR__ . '/google-calendar-helper.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$userId = $_SESSION['user_id'];
$calendar = new GoogleCalendarHelper($userId);
$authUrl = $calendar->getAuthUrl();

header('Location: ' . filter_var($authUrl, FILTER_SANITIZE_URL));
exit();
?>