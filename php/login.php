<?php
session_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
<title>Login - Kyoshi</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<style>
* {
  margin: 0;
  padding: 0;
  box-sizing: border-box;
}

body {
  font-family: 'Segoe UI', system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif;
  background: linear-gradient(135deg, #E0F3F8 0%, #b8e1f0 100%);
  min-height: 100vh;
  width: 100%;
  overflow-x: hidden;
}

/* Main container */
.login-container {
  display: flex;
  min-height: 100vh;
  width: 100%;
}

/* Left side with logo */
.login-left {
  flex: 1;
  background: url('../assets/img/login.jpg') no-repeat center/cover;
  position: relative;
  overflow: hidden;
  transition: background-image 0.3s ease;
}

.login-left::before {
  content: '';
  position: absolute;
  width: 200%;
  height: 200%;
  background: radial-gradient(circle, rgba(56, 189, 248, 0.1) 0%, transparent 70%);
  animation: pulse 8s ease-in-out infinite;
}

@keyframes pulse {
  0%, 100% { transform: translate(-25%, -25%) scale(1); opacity: 0.5; }
  50% { transform: translate(-25%, -25%) scale(1.1); opacity: 0.8; }
}

.login-left img {
  position: absolute;
  top: 20px;
  left: 20px;
  z-index: 2;
  width: 120px;
}

/* Right side with form */
.login-right {
  flex: 1;
  background: #a6bed7;
  padding: 40px;
  display: flex;
  align-items: center;
  justify-content: center;
  min-height: 100vh;
}

.login-box {
  width: 100%;
  max-width: 450px;
  background: white;
  padding: 35px;
  border-radius: 24px;
  box-shadow: 0 20px 35px rgba(0, 0, 0, 0.1);
  margin: 0 auto;
}

.btn-back {
  background: transparent;
  border: 1px solid #cbd5e1;
  border-radius: 40px;
  padding: 6px 14px;
  font-size: 13px;
  cursor: pointer;
  color: #64748b;
  margin-bottom: 20px;
  transition: all 0.25s ease;
}

.btn-back:hover {
  border-color: #38bdf8;
  color: #38bdf8;
  transform: translateX(-3px);
}

.login-box h3 {
  font-size: 1.8rem;
  margin-bottom: 25px;
  color: #0f172a;
  font-weight: 700;
}

.form-control {
  border-radius: 12px;
  border: 2px solid #e2e8f0;
  padding: 12px 16px;
  font-size: 0.95rem;
  transition: all 0.25s ease;
  width: 100%;
}

.form-control:focus {
  border-color: #38bdf8;
  box-shadow: 0 0 0 3px rgba(56, 189, 248, 0.1);
  outline: none;
}

label {
  font-weight: 600;
  margin-bottom: 8px;
  color: #0f172a;
  display: block;
}

.remember-label {
  display: inline-block !important;
  margin-bottom: 0 !important;
  font-weight: normal;
  cursor: pointer;
}

.password-wrapper {
  position: relative;
}

.password-wrapper .form-control {
  padding-right: 45px;
}

.eye-icon {
  position: absolute;
  right: 14px;
  top: 50%;
  transform: translateY(-50%);
  cursor: pointer;
  color: #94a3b8;
  font-size: 1.2rem;
  transition: color 0.2s;
  z-index: 10;
}

.eye-icon:hover {
  color: #38bdf8;
}

.btn-main {
  background: #38bdf8;
  border: none;
  color: white;
  font-weight: 700;
  padding: 12px;
  border-radius: 40px;
  width: 100%;
  transition: all 0.25s ease;
  font-size: 1rem;
  cursor: pointer;
}

.btn-main:hover {
  background: #0ea5e9;
  transform: translateY(-2px);
  box-shadow: 0 6px 14px rgba(56, 189, 248, 0.3);
}

.login-box a {
  color: #38bdf8;
  text-decoration: none;
}

.login-box a:hover {
  color: #0ea5e9;
  text-decoration: underline;
}

input[type="checkbox"] {
  width: 18px;
  height: 18px;
  margin-right: 8px;
  accent-color: #38bdf8;
  cursor: pointer;
}

    input[type="password"]::-ms-reveal,
    input[type="password"]::-ms-clear {
        display: none;
    }

    input[type="password"]::-webkit-credentials-auto-fill-button,
    input[type="password"]::-webkit-contacts-auto-fill-button {
        visibility: hidden;
        display: none;
        pointer-events: none;
    }

.d-flex.justify-content-between {
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: nowrap;
  gap: 10px;
}


#toast {
  position: fixed;
  bottom: 30px;
  left: 50%;
  transform: translateX(-50%);
  background: #1e1e1e;
  color: white;
  padding: 12px 24px;
  border-radius: 50px;
  font-size: 14px;
  opacity: 0;
  transition: opacity 0.3s ease;
  z-index: 9999;
  white-space: nowrap;
  box-shadow: 0 4px 12px rgba(0,0,0,0.15);
  pointer-events: none;
}

/* ========== MOBILE RESPONSIVE ========== */
@media (max-width: 900px) {
  .d-flex.justify-content-between {
    flex-wrap: nowrap !important;
    gap: 5px !important;
  }

  .login-container {
    flex-direction: column !important;
  }
  
  .login-left {
    display: none !important;
  }
  
  .login-right {
    flex: none !important;
    width: 100% !important;
    padding: 30px 20px !important;
    min-height: 100vh !important;
  }
  
  .login-box {
    max-width: 450px !important;
    margin: 0 auto !important;
    padding: 30px 25px !important;
  }
  
  .login-box h3 {
    font-size: 1.5rem !important;
  }
}

@media (max-width: 480px) {
  .d-flex.justify-content-between {
    flex-wrap: nowrap !important;
    gap: 5px !important;
  }
  
  .d-flex.justify-content-between div {
    white-space: nowrap;
  }
  
  .d-flex.justify-content-between a {
    white-space: nowrap;
    font-size: 13px;
  }
  
  .d-flex.justify-content-between label {
    font-size: 13px;
    display: inline-block;
  }

}
</style>
</head>

<body>

<div class="login-container">

  <div class="login-left">
    <img src="../assets/img/Logo.png" style="width:120px;">
  </div>

  <div class="login-right">

    <div class="login-box">
      <button type="button" class="btn-back" onclick="window.location.href='../index.html'">← Back</button>

      <h3 class="fw-bold mb-4">Member Log In</h3>

      <form action="login_process.php" method="POST">

        <input type="hidden" name="redirect" value="<?= htmlspecialchars($_GET['redirect'] ?? '') ?>">
        <input type="hidden" name="lang" value="<?= htmlspecialchars($_GET['lang'] ?? '') ?>">

        <div class="mb-3">
          <label>Email Address</label>
          <input type="email" name="email" class="form-control" placeholder="Enter email" required>
        </div>

        <div class="mb-3">
          <label>Password</label>
          <div class="password-wrapper">
            <input type="password" name="password" id="loginPassword" class="form-control" placeholder="Enter password" required>
            <span class="eye-icon" onclick="togglePassword()">
              <i class="bi bi-eye" id="eyeIcon"></i>
            </span>
          </div>
        </div>

        <div class="d-flex justify-content-between mb-3">
        <div style="display: flex; align-items: center; gap: 8px;">
          <input type="checkbox" name="remember_me" id="remember_me"> 
          <label for="remember_me" style="margin-bottom: 0; cursor: pointer;">Remember me</label>
        </div>
        <a href="forgotpassword.php" style="text-decoration: underline;">Forgot password?</a>
      </div>
        
        <button type="submit" class="btn-main" >Login</button>

      </form>

      <p class="text-center mt-5">New here? <br>
        <a href="signup.php" class="fw-bold" style="text-decoration: underline;">Create an account</a>
      </p>

    </div>

  </div>

</div>

<div id="toast"></div>

<script>
function togglePassword() {
  const passwordInput = document.getElementById('loginPassword');
  const eyeIcon = document.getElementById('eyeIcon');
  
  if (passwordInput.type === 'password') {
    passwordInput.type = 'text';
    eyeIcon.classList.remove('bi-eye');
    eyeIcon.classList.add('bi-eye-slash');
  } else {
    passwordInput.type = 'password';
    eyeIcon.classList.remove('bi-eye-slash');
    eyeIcon.classList.add('bi-eye');
  }
}

function showToast(msg) {
  const toast = document.getElementById('toast');
  toast.textContent = msg;
  toast.style.opacity = '1';
  setTimeout(() => {
    toast.style.opacity = '0';
  }, 3000);
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