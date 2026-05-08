<?php
session_start();

$lang = $_GET['lang'] ?? '';

// store where user wants to go
$_SESSION['redirect_lang'] = $lang;

// go login
header("Location: login.php");
exit();
?>