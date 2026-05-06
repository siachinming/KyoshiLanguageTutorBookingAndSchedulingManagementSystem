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
<?php if (isset($_SESSION['error'])): ?>
<div id="errBox" class="alert alert-danger">
  <?php 
    echo $_SESSION['error']; 
    unset($_SESSION['error']);
  ?>
</div>
<?php endif; ?>
<div class="login-container">

  <div class="login-left">
    <img src="../assets/img/logo.png" style="width:120px;">
  </div>

  <div class="login-right">

    <div class="login-box">
      <button type="button" class="btn-back" onclick="goBack()">← Back</button>
      <h3 class="fw-bold mb-4">Member Sign In</h3>

      <!-- FORM START -->
      <form action="login_process.php" method="POST">

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
        <!-- Add this inside your form, above the Login button -->
<?php if (!empty($_GET['error'])): ?>
  <div class="alert alert-danger mb-3" role="alert">
    <i class="bi bi-exclamation-circle me-2"></i>
    <?php echo htmlspecialchars($_GET['error']); ?>
  </div>
<?php endif; ?>
        <button type="submit" class="btn btn-main text-white">Login</button>

      </form>

      <p class="text-center mt-5">New here? <br>
        <a href="signup.php" class="fw-bold">Create an account</a>
      </p>

    </div>

  </div>

</div>

<script src="../js/login.js"></script>

</body>
</html>