<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Forgot Password - Kyoshi</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<style>
body {
  background: #a6bed7;
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
  width: 90%;
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

.btn-main:hover {
  background: #0ea5e9;
}
</style>
</head>

<body>

<div class="box">

  <h3 class="fw-bold mb-3">Forgot Password</h3>

  <p class="text-muted">
    Enter your email and we’ll send you a reset link.
  </p>

  <!-- FORM START -->
  <form id="forgotForm">
    <input type="email" name="email" id="email" class="form-control mb-3" placeholder="Enter email" required>
    <button type="submit" class="btn btn-main" id="submitBtn">Send Reset Link</button>
  </form>
  <!-- FORM END -->

  <p class="text-center mt-3">
    <a href="login.php">Back to Login</a>
  </p>

</div>

<script>
document.getElementById('forgotForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const email = document.getElementById('email').value;
    const submitBtn = document.getElementById('submitBtn');
    const originalText = submitBtn.innerHTML;
    
    // Show loading state
    submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span> Sending...';
    submitBtn.disabled = true;
    
    try {
        const response = await fetch('forgot_process.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: new URLSearchParams({ email: email })
        });
        
        const result = await response.json();
        
        if (result.success) {
            // Show success popup
            Swal.fire({
                icon: 'success',
                title: 'Email Sent!',
                html: `A password reset link has been sent to <strong>${email}</strong><br><br>Please check your inbox (and spam folder).`,
                confirmButtonColor: '#38bdf8',
                confirmButtonText: 'OK',
                allowOutsideClick: false
            }).then(() => {
                // Optional: redirect to login after 2 seconds
                setTimeout(() => {
                    window.location.href = 'login.php';
                }, 2000);
            });
        } else {
            // Show error popup
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: result.message || 'Email not found. Please try again.',
                confirmButtonColor: '#dc2626',
                confirmButtonText: 'Try Again'
            });
        }
    } catch (error) {
        Swal.fire({
            icon: 'error',
            title: 'Error',
            text: 'Something went wrong. Please try again later.',
            confirmButtonColor: '#dc2626'
        });
    } finally {
        // Reset button
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;
    }
});
</script>

</body>
</html>