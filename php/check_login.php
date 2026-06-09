<?php
// check_login.php - Include this at the top of all protected pages

// Only start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Prevent browser caching - THIS STOPS BACK BUTTON FROM SHOWING PAGE
header("Cache-Control: no-cache, no-store, must-revalidate, private");
header("Pragma: no-cache");
header("Expires: 0");

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}
?>