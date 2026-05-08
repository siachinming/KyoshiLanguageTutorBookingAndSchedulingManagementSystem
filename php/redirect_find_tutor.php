<?php
session_start();

$lang = $_GET['lang'] ?? '';

if (!isset($_SESSION['user_id'])) {
    // not logged in → send to login
    header("Location: login.php?redirect=search_tutors&lang=" . urlencode($lang));
    exit();
}

// logged in → go to tutor page
header("Location: search_tutors.php?lang=" . urlencode($lang));
exit();
?>