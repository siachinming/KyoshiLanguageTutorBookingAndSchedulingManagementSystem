<?php session_start()?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Login - Kyoshi</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">

<link rel="stylesheet" href="../css/login.css">
</head>

<body>

<div class="login-container">

  <div class="login-left">
    <img src="../assets/img/logo.png" style="width:120px;">
  </div>

  <div class="login-right">

    <div class="login-box">
      <button type="button" class="btn-back" onclick="window.location.href='../index.html'">← Back</button>

      <h3 class="fw-bold mb-4">Member Sign In</h3>

      <!-- FORM START -->
      <form action="login_process.php" method="POST">

      <input type="hidden" name="redirect" value="<?= $_GET['redirect'] ?? '' ?>">
      <input type="hidden" name="lang" value="<?= $_GET['lang'] ?? '' ?>">

        <div class="mb-3">
          <label>Email Address</label>
          <input type="email" name="email" class="form-control" placeholder="Enter email" required>
        </div>

        <div class="mb-3">
          <label>Password</label>

          <div class="password-wrapper">
            <input type="password"
           name="password"
           id="loginPassword"
           class="form-control"
           placeholder="Enter password"
           required>

    <span class="eye-icon" onclick="togglePassword()">
      <i class="bi bi-eye" id="eyeIcon"></i>
    </span>
  </div>
</div>

        <div class="d-flex justify-content-between mb-3">
          <div>
            <input type="checkbox"> Remember me
          </div>
          <a href="forgotpassword.php">Forgot password?</a>
        </div>
        
        <button type="submit" class="btn btn-main text-white">Login</button>

      </form>

      <p class="text-center mt-5">New here? <br>
        <a href="signup.php" class="fw-bold">Create an account</a>
      </p>

    </div>

  </div>

</div>

<script src="../js/login.js"></script>
<div id="toast" style="
  position: fixed;
  bottom: 30px;
  left: 50%;
  transform: translateX(-50%);
  background: #1e1e1e;
  color: white;
  padding: 12px 24px;
  border-radius: 8px;
  font-size: 14px;
  opacity: 0;
  transition: opacity 0.3s ease;
  z-index: 9999;
  white-space: nowrap;
"></div>

<script>
function showToast(msg) {
  const toast = document.getElementById('toast');
  toast.textContent = msg;
  toast.style.opacity = '1';
  setTimeout(() => toast.style.opacity = '0', 3000);
}

<?php if (isset($_SESSION['error'])): ?>
  showToast("<?php echo addslashes($_SESSION['error']); unset($_SESSION['error']); ?>");
<?php endif; ?>

<?php if (isset($_SESSION['success'])): ?>
  showToast("<?php echo addslashes($_SESSION['success']); unset($_SESSION['success']); ?>");
<?php endif; ?>
</script>
</body>
</html>