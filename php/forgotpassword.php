<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Forgot Password - Kyoshi</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

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

  <h3 class="fw-bold mb-3">Forgot Password</h3>

  <p class="text-muted">
    Enter your email and we’ll send you a reset link.
  </p>

  <!-- FORM START -->
  <form action="forgot_process.php" method="POST">

    <input type="email" name="email" class="form-control mb-3" placeholder="Enter email" required>

    <button type="submit" class="btn btn-main">Send Reset Link</button>

  </form>
  <!-- FORM END -->

  <p class="text-center mt-3">
    <a href="login.php">Back to Login</a>
  </p>

</div>

</body>
</html>