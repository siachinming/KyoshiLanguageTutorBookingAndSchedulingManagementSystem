<?php
include "config.php";

$token = $_GET['token'] ?? '';
?>

<form action="update_password.php" method="POST">
    <input type="hidden" name="token" value="<?= $token ?>">

    <input type="password" name="password" placeholder="New Password" required>
    <input type="password" name="confirm_password" placeholder="Confirm Password" required>

    <button type="submit">Reset Password</button>
</form>