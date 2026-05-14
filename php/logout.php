<?php
session_start();

// clear all session data
$_SESSION = [];

// destroy session
session_destroy();

// optional: clear cookie (more solid logout)
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// redirect to landing page
header("Location: ../index.html");
exit();
?>