<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
<title>Reset Password - Kyoshi</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<style>
* {
  margin: 0;
  padding: 0;
  box-sizing: border-box;
}

body {
  background: linear-gradient(135deg, #a6bed7 0%, #8ba3c2 100%);
  min-height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
  font-family: 'Segoe UI', -apple-system, BlinkMacSystemFont, Roboto, Helvetica, Arial, sans-serif;
  padding: 16px;
}

.box {
  background: white;
  padding: 28px 24px;
  border-radius: 24px;
  width: 100%;
  max-width: 420px;
  box-shadow: 0 20px 40px rgba(0,0,0,0.15);
  animation: fadeInUp 0.4s ease-out;
}

@keyframes fadeInUp {
  from {
    opacity: 0;
    transform: translateY(20px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}

h3 {
  font-size: 1.75rem;
  margin-bottom: 0.5rem;
}

.text-muted {
  font-size: 0.85rem;
  margin-bottom: 1.5rem;
}

.btn-main {
  background: linear-gradient(135deg, #38bdf8, #0ea5e9);
  border: none;
  width: 100%;
  padding: 14px;
  border-radius: 40px;
  color: white;
  font-weight: 700;
  font-size: 15px;
  transition: all 0.2s ease;
  margin-top: 8px;
}

.btn-main:hover {
  transform: translateY(-2px);
  box-shadow: 0 6px 20px rgba(56,189,248,0.4);
}

.btn-main:active {
  transform: translateY(0);
}

.btn-main:disabled {
  opacity: 0.7;
  transform: none;
  cursor: not-allowed;
}

.form-control {
  border-radius: 12px;
  padding: 12px 40px 12px 16px;
  font-size: 15px;
  border: 1px solid #e2e8f0;
  transition: all 0.2s ease;
}

.form-control:focus {
  border-color: #38bdf8;
  box-shadow: 0 0 0 3px rgba(56,189,248,0.2);
  outline: none;
}

.alert {
  border-radius: 12px;
  font-size: 14px;
  padding: 12px;
  margin-bottom: 20px;
}

.alert-danger {
  background: #fee2e2;
  border: 1px solid #fecaca;
  color: #dc2626;
}

.alert-success {
  background: #d1fae5;
  border: 1px solid #a7f3d0;
  color: #059669;
}

/* Mobile adjustments */
@media (max-width: 480px) {
  body {
    padding: 12px;
  }
  
  .box {
    padding: 20px 16px;
    border-radius: 20px;
  }
  
  h3 {
    font-size: 1.5rem;
  }
  
  .btn-main {
    padding: 12px;
    font-size: 14px;
  }
  
  .form-control {
    padding: 10px 36px 10px 14px;
    font-size: 14px;
  }
}
.password-toggle {
  position: absolute;
  right: 12px;
  top: 50%;
  transform: translateY(-50%);
  cursor: pointer;
  color: #94a3b8;
  font-size: 18px;
  z-index: 10;
  display: flex;
  align-items: center;
  justify-content: center;
  width: 32px;
  height: 32px;
  border-radius: 50%;
  transition: all 0.2s ease;
  background: transparent;
  pointer-events: auto;
}

.password-toggle:hover {
  color: #38bdf8;
}

.password-toggle:active {
  transform: translateY(-50%) scale(0.95);
}
</style>
</head>

<body>
<div class="box">

  <h3 class="fw-bold mb-2">Reset Password</h3>
  <p class="text-muted">Enter your new password below.</p>

  <?php
  session_start();
  include "config.php";

  $token = $_GET['token'] ?? '';

  if (empty($token)) {
      echo "<div class='alert alert-danger'>❌ Invalid reset link. Please request a new one.</div>";
      echo '<a href="forgotpassword.php" class="btn btn-main" style="text-align:center; display:block;">Request New Link</a>';
      exit();
  }

  // CHECK TOKEN AND IF IT'S WITHIN 1 HOUR
  $sql = "SELECT * FROM password_resets WHERE token = ? AND created_at >= NOW() - INTERVAL 1 HOUR";
  $stmt = $conn->prepare($sql);
  $stmt->bind_param("s", $token);
  $stmt->execute();
  $result = $stmt->get_result();

  if ($result->num_rows == 0) {
      echo "<div class='alert alert-danger'>This reset link has <strong>expired</strong> or is invalid.</div>";
      echo '<a href="forgotpassword.php" class="btn btn-main" style="text-align:center; display:block; background:#64748b;">Request New Link</a>';
      exit();
  }
  
  $resetData = $result->fetch_assoc();
  $email = $resetData['email'];
  ?>

  <!-- FORM START -->
  <form id="resetForm">
    <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
    <input type="hidden" name="email" value="<?php echo htmlspecialchars($email); ?>">

    <div class="mb-3">
      <label class="form-label fw-semibold">New Password</label>
      <div class="position-relative">
        <input type="password" name="password" id="newPassword" class="form-control" placeholder="Enter new password" required minlength="6">
        <span class="password-toggle" onclick="togglePass('newPassword', 'eye1', event)">
          <i class="bi bi-eye" id="eye1"></i>
        </span>
      </div>
      <small class="text-muted" style="font-size: 11px;">Minimum 8 characters</small>
    </div>

    <div class="mb-4">
      <label class="form-label fw-semibold">Confirm Password</label>
      <div class="position-relative">
        <input type="password" name="confirm_password" id="confirmPassword" class="form-control" placeholder="Confirm new password" required minlength="6">
        <span class="password-toggle" onclick="togglePass('confirmPassword', 'eye2', event)">
          <i class="bi bi-eye" id="eye2"></i>
        </span>
      </div>
    </div>

    <button type="submit" class="btn btn-main" id="submitBtn">
      <i class="bi bi-key"></i> Reset Password
    </button>
  </form>

</div>

<script>function togglePass(inputId, iconId, event) {
  // Stop event bubbling to prevent input from losing focus
  if (event) {
    event.stopPropagation();
    event.preventDefault();
  }
  
  const input = document.getElementById(inputId);
  const icon = document.getElementById(iconId);
  
  if (!input || !icon) return;
  
  if (input.type === 'password') {
    input.type = 'text';
    icon.classList.remove('bi-eye');
    icon.classList.add('bi-eye-slash');
  } else {
    input.type = 'password';
    icon.classList.remove('bi-eye-slash');
    icon.classList.add('bi-eye');
  }
  
  // Keep focus on input field after toggling
  input.focus();
}

// Handle URL parameters with SweetAlert
const urlParams = new URLSearchParams(window.location.search);

if (urlParams.get('success')) {
  Swal.fire({
    icon: 'success',
    title: 'Password Reset Successfully!',
    text: urlParams.get('success'),
    confirmButtonColor: '#38bdf8',
    confirmButtonText: 'Go to Login'
  }).then(() => {
    window.location.href = 'login.php';
  });
}

if (urlParams.get('error')) {
  Swal.fire({
    icon: 'error',
    title: 'Reset Failed',
    text: urlParams.get('error'),
    confirmButtonColor: '#dc2626',
    confirmButtonText: 'Try Again'
  });
}

// Handle form submission with AJAX
document.getElementById('resetForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const password = document.getElementById('newPassword').value;
    const confirmPassword = document.getElementById('confirmPassword').value;
    const token = document.querySelector('input[name="token"]').value;
    const email = document.querySelector('input[name="email"]').value;
    const submitBtn = document.getElementById('submitBtn');
    const originalText = submitBtn.innerHTML;
    
    // Validate passwords match
    if (password !== confirmPassword) {
        Swal.fire({
            icon: 'error',
            title: 'Passwords Do Not Match',
            text: 'Please make sure both passwords are the same.',
            confirmButtonColor: '#dc2626'
        });
        return;
    }
    
    // Validate password length
    if (password.length < 8) {
        Swal.fire({
            icon: 'error',
            title: 'Password Too Short',
            text: 'Password must be at least 8 characters long.',
            confirmButtonColor: '#dc2626'
        });
        return;
    }
    
    // Show loading state
    submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span> Resetting...';
    submitBtn.disabled = true;
    
    try {
        const response = await fetch('reset_process.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify({ 
                token: token, 
                email: email,
                password: password, 
                confirm_password: confirmPassword 
            })
        });
        
        const result = await response.json();
        
        if (result.success) {
            Swal.fire({
                icon: 'success',
                title: 'Password Reset Successfully!',
                html: 'Your password has been changed.<br><br>You can now login with your new password.',
                confirmButtonColor: '#38bdf8',
                confirmButtonText: 'Go to Login'
            }).then(() => {
                window.location.href = 'login.php';
            });
        } else {
            Swal.fire({
                icon: 'error',
                title: 'Reset Failed',
                text: result.message || 'Something went wrong. Please try again.',
                confirmButtonColor: '#dc2626'
            });
        }
    } catch (error) {
        Swal.fire({
            icon: 'error',
            title: 'Error',
            text: 'Network error. Please check your connection and try again.',
            confirmButtonColor: '#dc2626'
        });
    } finally {
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;
    }
});
</script>

</body>
</html>