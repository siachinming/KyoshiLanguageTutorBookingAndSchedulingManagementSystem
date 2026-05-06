<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Reset Password - Kyoshi</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">

<style>
body {
  background: #c3a6d7;
  height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
  font-family: 'Segoe UI', sans-serif;
}

.box {
  background: white;
  padding: 30px;
  border-radius: 20px;
  width: 100%;
  max-width: 400px;
  box-shadow: 0 10px 25px rgba(0,0,0,0.1);
}

.btn-main {
  background: #38bdf8;
  border: none;
  width: 100%;
  padding: 10px;
  border-radius: 10px;
  color: white;
}
</style>
</head>

<body>
<div class="box">

  <h3 class="fw-bold mb-3">Reset Password</h3>
  <p class="text-muted">Enter your new password below.</p>

  <?php
  session_start();
  include "config.php";

  $token = $_GET['token'] ?? '';

  if (empty($token)) {
      echo "<div class='alert alert-danger'>Invalid reset link.</div>";
      exit();
  }

  // CHECK TOKEN AND IF IT'S WITHIN 1 HOUR
  $sql = "SELECT * FROM password_resets WHERE token = '$token'
          AND created_at >= NOW() - INTERVAL 1 HOUR";
  $result = $conn->query($sql);

  if ($result->num_rows == 0) {
      echo "<div class='alert alert-danger'>This reset link has <strong>expired</strong> or is invalid.<br><a href='forgotpassword.php'>Request a new one</a></div>";
      exit();
  }
  ?>

  <?php if (!empty($_GET['error'])): ?>
    <div class="alert alert-danger"><?php echo htmlspecialchars($_GET['error']); ?></div>
  <?php endif; ?>

  <!-- FORM START -->
  <form action="reset_process.php" method="POST">

    <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">

    <div class="mb-3">
      <label>New Password</label>
      <div class="position-relative">
      <input type="password" name="password" id="newPassword" class="form-control" placeholder="Enter new password" required minlength="6">
      <span class="position-absolute top-50 end-0 translate-middle-y me-3" style="cursor:pointer;" onclick="togglePass('newPassword', 'eye1')">
      <i class="bi bi-eye" id="eye1"></i>
      </span>
      </div>
    </div>

    <div class="mb-3">
        <label>Confirm Password</label>
        <div class="position-relative">
            <input type="password" name="confirm_password" id="confirmPassword" class="form-control" placeholder="Confirm new password" required minlength="6">
            <span class="position-absolute top-50 end-0 translate-middle-y me-3" style="cursor:pointer;" onclick="togglePass('confirmPassword', 'eye2')">
            <i class="bi bi-eye" id="eye2"></i></span>
        </div>
    </div>

    <button type="submit" class="btn btn-main">Reset Password</button>

  </form>
</div>
</body>
<script>
function togglePass(inputId, iconId) {
  const input = document.getElementById(inputId);
  const icon  = document.getElementById(iconId);
  if (input.type === 'password') {
    input.type = 'text';
    icon.classList.replace('bi-eye', 'bi-eye-slash');
  } else {
    input.type = 'password';
    icon.classList.replace('bi-eye-slash', 'bi-eye');
  }
}

const params = new URLSearchParams(window.location.search);

  if (params.get('error')) {
    alert(params.get('error'));
  }

  if (params.get('success')) {
    alert(params.get('success'));
  }
</script>
</html>