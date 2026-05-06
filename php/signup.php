<?php session_start(); ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Sign Up - Kyoshi</title>

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/intl-tel-input@18/build/css/intlTelInput.css">

  <style>
    * { box-sizing: border-box; }

    body { margin: 0; }

    .signup-container {
      height: 100vh;
      display: flex;
      overflow: hidden;
    }

    .signup-left {
      flex: 1;
      background: url('../assets/img/student.jpg') no-repeat center/cover;
      position: relative;
    }

    .signup-logo {
      position: absolute;
      top: 20px;
      left: 20px;
      width: 120px;
    }

    .signup-right {
      flex: 1;
      background: #a6bed7;
      padding: 40px 40px;
      display: flex;
      justify-content: center;
      align-items: flex-start;
      height: 100vh;
      overflow-y: auto;
    }

    .signup-box {
      width: 100%;
      max-width: 420px;
      background: white;
      padding: 30px;
      border-radius: 20px;
      margin: auto 0;
    }

    .form-control {
      border-radius: 10px;
      padding: 12px;
    }

    .btn-main {
      background: #38bdf8;
      border: none;
      width: 100%;
      padding: 10px;
      border-radius: 10px;
      color: white;
      font-size: 15px;
      cursor: pointer;
    }

    .btn-main:hover { background: #0ea5e9; }

    .btn-back {
      background: transparent;
      border: 1px solid #cbd5e1;
      border-radius: 8px;
      padding: 5px 12px;
      font-size: 13px;
      cursor: pointer;
      color: #64748b;
      margin-bottom: 16px;
    }

    .role-box {
      border: 2px solid #ddd;
      padding: 15px;
      border-radius: 10px;
      cursor: pointer;
      margin-bottom: 10px;
      text-align: center;
      transition: border-color 0.2s, background 0.2s;
      font-size: 15px;
    }

    .role-box.active {
      border-color: #38bdf8;
      background: #f0f9ff;
    }

    .password-wrapper { position: relative; margin-bottom: 8px; }
    .password-wrapper input { padding-right: 45px; }

    .eye-icon {
      position: absolute;
      right: 14px;
      top: 50%;
      transform: translateY(-50%);
      cursor: pointer;
      color: gray;
      font-size: 18px;
    }

    #rulesBox {
      background: #f8fafc;
      border: 1px solid #e2e8f0;
      border-radius: 10px;
      padding: 10px 14px;
      font-size: 13px;
      margin-bottom: 10px;
      display: none;
    }

    .rule-item {
      display: flex;
      align-items: center;
      gap: 7px;
      margin-bottom: 4px;
      color: #94a3b8;
      transition: color 0.2s;
    }

    .rule-item.pass { color: #16a34a; }
    .rule-item i { font-size: 14px; }

    #matchMsg {
      font-size: 12px;
      min-height: 18px;
      margin-bottom: 8px;
    }

    #matchMsg.ok   { color: #16a34a; }
    #matchMsg.fail { color: #dc2626; }

    #errBox {
      background: #fef2f2;
      color: #b91c1c;
      border: 1px solid #fecaca;
      border-radius: 10px;
      padding: 10px 14px;
      font-size: 13px;
      margin-bottom: 14px;
      display: none;
    }
  </style>
</head>

<body>
<div class="signup-container">

  <!-- LEFT panel -->
  <div class="signup-left" id="signupLeft">
    <img src="../assets/img/logo.png" class="signup-logo" alt="Kyoshi logo">
  </div>

  <!-- RIGHT panel -->
  <div class="signup-right">
    <div class="signup-box">

      <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-danger">
          <?php echo htmlspecialchars($_SESSION['error'], ENT_QUOTES, 'UTF-8'); unset($_SESSION['error']); ?>
        </div>
      <?php endif; ?>

      <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success">
          <?php echo htmlspecialchars($_SESSION['success'], ENT_QUOTES, 'UTF-8'); unset($_SESSION['success']); ?>
        </div>
      <?php endif; ?>

      <input type="hidden" name="role" id="roleInput">

      <!-- STEP 1: Role selection -->
      <div id="roleStep">
        <h3 class="fw-bold mb-1">Create Account</h3>
        <p class="text-muted mb-3" style="font-size:14px;">Select your role to continue</p>

        <div class="role-box" onclick="selectRole(this, 'student')">
          👩‍🎓 I am a Student
        </div>
        <div class="role-box" onclick="selectRole(this, 'tutor')">
          👨‍🏫 I am a Tutor
        </div>

        <button type="button" class="btn-main mt-3" onclick="goNext()">Continue</button>
        <button type="button" class="btn btn-secondary w-100 mt-2" onclick="goHome()">Back to Home Page</button>
      </div>

      <div id="formStep" style="display:none;">

        <button type="button" class="btn-back" onclick="goBack()">← Back</button>
        <h4 class="fw-bold mb-3" id="formTitle"></h4>

        <div id="errBox"></div>

        <form action="signup_process.php" method="POST" enctype="multipart/form-data" id="signupForm" novalidate>

          <!-- Common fields -->
          <!-- this hidden field is what actually gets submitted -->
          <input type="hidden" name="role" id="roleSubmit">
          <input type="text"  name="fullname" class="form-control mb-2" placeholder="Full Name" required>
          <input type="email" name="email"    class="form-control mb-2" placeholder="Email Address" required>

          <!-- Password -->
          <div class="password-wrapper">
            <input type="password" name="password" id="passInput" class="form-control"
                   placeholder="Password" oninput="liveCheck()" required>
            <span class="eye-icon" onclick="toggleVis('passInput', this)">
              <i class="bi bi-eye"></i>
            </span>
          </div>

          <!-- Live password rules -->
          <div id="rulesBox">
            <div class="rule-item" id="r-len"><i class="bi bi-circle"></i> At least 8 characters</div>
            <div class="rule-item" id="r-up" ><i class="bi bi-circle"></i> One uppercase letter</div>
            <div class="rule-item" id="r-num"><i class="bi bi-circle"></i> One number</div>
            <div class="rule-item" id="r-sp" ><i class="bi bi-circle"></i> One special character (!@#$…)</div>
          </div>

          <!-- Confirm password -->
          <div class="password-wrapper">
            <input type="password" name="confirm_password" id="confirmInput" class="form-control"
                   placeholder="Confirm Password" oninput="checkMatch()" required>
            <span class="eye-icon" onclick="toggleVis('confirmInput', this)">
              <i class="bi bi-eye"></i>
            </span>
          </div>
          <div id="matchMsg"></div>

          <!-- Phone -->
          <input type="tel" name="phone" id="phoneInput" class="form-control mb-2" placeholder="Phone Number">

          <!-- Tutor-only fields -->
          <div id="tutorFields" style="display:none;">
            <hr class="my-3">
            <p class="text-muted mb-2" style="font-size:13px;">Tutor details</p>
            <input type="number" name="experience" class="form-control mb-2" placeholder="Years of Experience" min="0">
            <input type="text"   name="rate"       class="form-control mb-2" placeholder="Hourly Rate (RM)">
            <textarea name="bio" class="form-control mb-2" placeholder="Short bio about yourself" rows="3"></textarea>
            <label class="form-label text-muted" style="font-size:13px;">Profile Picture</label>
            <input type="file" name="profile_pic" class="form-control mb-2" accept="image/*">
            <label class="form-label text-muted" style="font-size:13px;">Certificate</label>
            <input type="file" name="certificate" class="form-control mb-2">
          </div>

          <button type="submit" class="btn-main mt-3" id="submitBtn">Create Account</button>
        </form>

      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/intl-tel-input@18/build/js/intlTelInput.min.js"></script>

<script>
  let currentRole = '';
  let itiInstance = null;

  function selectRole(el, role) {
    currentRole = role;
    document.getElementById('roleInput').value = role;
    document.querySelectorAll('.role-box').forEach(b => b.classList.remove('active'));
    el.classList.add('active');
    const left = document.getElementById('signupLeft');
    left.style.backgroundImage = role === 'tutor'
      ? "url('../assets/img/tutor.jpg')"
      : "url('../assets/img/student.jpg')";
  }

  function goNext() {
    if (!currentRole) { alert('Please select a role first.'); return; }
    document.getElementById('roleStep').style.display = 'none';
    document.getElementById('formStep').style.display = 'block';
    document.getElementById('formTitle').textContent  = currentRole === 'tutor' ? 'Tutor Sign Up' : 'Student Sign Up';
    document.getElementById('tutorFields').style.display = currentRole === 'tutor' ? 'block' : 'none';
    document.getElementById('submitBtn').textContent  = currentRole === 'tutor' ? 'Submit Tutor Application' : 'Create Account';

    // sync role into the form's hidden field so it gets submitted
    document.getElementById('roleSubmit').value = currentRole;

    if (!itiInstance) {
      itiInstance = window.intlTelInput(document.getElementById('phoneInput'), {
        initialCountry: 'my',
        separateDialCode: true
      });
    }
  }

  function goBack() {
    document.getElementById('formStep').style.display = 'none';
    document.getElementById('roleStep').style.display = 'block';
    currentRole = '';
    document.getElementById('roleInput').value = '';
    document.querySelectorAll('.role-box').forEach(b => b.classList.remove('active'));
    hideErr();
    document.getElementById('rulesBox').style.display = 'none';
    document.getElementById('matchMsg').textContent   = '';
    document.getElementById('matchMsg').className     = '';
  }

  function goHome() { window.location.href = '../index.html'; }

  function showErr(msg) {
    const b = document.getElementById('errBox');
    b.textContent   = msg;
    b.style.display = 'block';
    b.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }
  function hideErr() { document.getElementById('errBox').style.display = 'none'; }

  function toggleVis(inputId, span) {
    const inp  = document.getElementById(inputId);
    const icon = span.querySelector('i');
    if (inp.type === 'password') {
      inp.type = 'text';
      icon.className = 'bi bi-eye-slash';
    } else {
      inp.type = 'password';
      icon.className = 'bi bi-eye';
    }
  }

  function setRule(id, ok) {
    const el = document.getElementById(id);
    const ic = el.querySelector('i');
    if (ok) { el.classList.add('pass');    ic.className = 'bi bi-check-circle-fill'; }
    else    { el.classList.remove('pass'); ic.className = 'bi bi-circle'; }
  }

  function liveCheck() {
    const val = document.getElementById('passInput').value;
    document.getElementById('rulesBox').style.display = val.length > 0 ? 'block' : 'none';
    setRule('r-len', val.length >= 8);
    setRule('r-up',  /[A-Z]/.test(val));
    setRule('r-num', /[0-9]/.test(val));
    setRule('r-sp',  /[!@#$%^&*(),.?":{}|<>]/.test(val));
    checkMatch();
  }

  function checkMatch() {
    const p = document.getElementById('passInput').value;
    const c = document.getElementById('confirmInput').value;
    const m = document.getElementById('matchMsg');
    if (!c) { m.textContent = ''; m.className = ''; return; }
    if (p === c) { m.textContent = '✓ Passwords match';      m.className = 'ok'; }
    else         { m.textContent = '✗ Passwords do not match'; m.className = 'fail'; }
  }

  document.getElementById('signupForm').addEventListener('submit', function (e) {
    hideErr();

    const pass    = document.getElementById('passInput').value;
    const confirm = document.getElementById('confirmInput').value;
    const len = pass.length >= 8;
    const up  = /[A-Z]/.test(pass);
    const num = /[0-9]/.test(pass);
    const sp  = /[!@#$%^&*(),.?":{}|<>]/.test(pass);

    if (!len || !up || !num || !sp) {
      e.preventDefault();
      showErr('Password must be at least 8 characters and include an uppercase letter, a number, and a special character.');
      document.getElementById('rulesBox').style.display = 'block';
      return;
    }

    if (pass !== confirm) {
      e.preventDefault();
      showErr('Passwords do not match. Please re-enter your password.');
      return;
    }

    // Inject full international phone number
    if (itiInstance) {
      document.getElementById('phoneInput').value = itiInstance.getNumber();
    }
  });
</script>
</body>
</html>