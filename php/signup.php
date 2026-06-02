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
      transition: background-image 0.3s ease;
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
      max-width: 550px;
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
      border: 1px solid #000000;
      border-radius: 8px;
      padding: 5px 12px;
      font-size: 13px;
      cursor: pointer;
      color: #64748b;
      margin-bottom: 16px;
    }

    .role-box {
      border: 2px solid #e5e7eb;
      padding: 18px;
      border-radius: 14px;
      cursor: pointer;
      margin-bottom: 12px;
      text-align: center;
      font-size: 15px;
      font-weight: 600;
      background: #f9fafb;
      transition: all 0.25s ease;
    }

    .role-box:hover {
      border-color: #38bdf8;
      background: #f0f9ff;
      transform: translateY(-2px);
    }

    .role-box.active {
      border-color: #38bdf8;
      background: #e0f2fe;
      box-shadow: 0 6px 16px rgba(56, 189, 248, 0.25);
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

    #matchMsg.ok { color: #16a34a; }
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

    .mode-option {
      display: flex;
      align-items: center;
      gap: 8px;
      border: 2px solid #ddd;
      border-radius: 10px;
      padding: 10px 16px;
      cursor: pointer;
      font-size: 14px;
      flex: 1;
      justify-content: center;
    }

    .mode-option.selected {
      border-color: #38bdf8;
      background: #e0f2fe;
    }

    .language-item {
      border: 1px solid #e2e8f0;
      border-radius: 10px;
      padding: 12px;
      margin-bottom: 10px;
    }

    .language-item .row {
      display: flex;
      align-items: center;
      gap: 10px;
      flex-wrap: wrap;
    }

    .lang-checkbox {
      display: flex;
      align-items: center;
      gap: 8px;
      min-width: 100px;
    }

    .proficiency-select {
      flex: 1;
      min-width: 150px;
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

    #imageModal {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0,0,0,0.85);
        z-index: 9999;
        align-items: center;
        justify-content: center;
        cursor: pointer;
    }

    #imageModal img {
        max-width: 90%;
        max-height: 90%;
        object-fit: contain;
        border-radius: 10px;
        box-shadow: 0 0 30px rgba(0,0,0,0.3);
    }

    #closeModalBtn {
        position: absolute;
        top: 20px;
        right: 30px;
        background: white;
        border: none;
        font-size: 28px;
        cursor: pointer;
        width: 45px;
        height: 45px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: all 0.2s;
    }

    #closeModalBtn:hover {
        background: #f0f0f0;
        transform: scale(1.05);
    }

@media (max-width: 768px) {
    .role-box {
        width: 100%;
        margin-bottom: 12px;
    }
}

@media (max-width: 768px) {
    .signup-container {
        flex-direction: column;
        height: auto;
        min-height: 100vh;
    }

    .signup-left {
        display: none;
    }

    .signup-right {
        padding: 20px 16px;
        height: auto;
        min-height: 100vh;
    }

    .signup-box {
        padding: 20px;
        margin: 20px 0;
    }

    .role-box {
        padding: 20px !important;
    }

    .role-box div:first-child {
        font-size: 36px !important;
    }

    .role-box div:nth-child(2) {
        font-size: 20px !important;
    }

    .language-item .row {
        flex-direction: column;
        align-items: flex-start;
    }

    .lang-checkbox {
        min-width: auto;
    }

    .proficiency-select {
        width: 100%;
        min-width: auto;
    }

    .mode-option {
        padding: 8px 12px;
        font-size: 12px;
    }

    .d-flex.gap-3 {
        gap: 10px;
        flex-wrap: wrap;
    }

    .filter-chips {
        gap: 8px;
    }

    .btn-main, .btn-back, .btn-secondary {
        font-size: 14px;
        padding: 10px;
    }

    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
        gap: 12px;
    }

    .card-title h1 {
        font-size: 1.2rem;
    }

    .page-title p {
        font-size: 0.7rem;
    }
}

@media (max-width: 480px) {
    .signup-box {
        padding: 16px;
    }

    .role-box {
        padding: 16px !important;
    }

    .role-box div:first-child {
        font-size: 28px !important;
    }

    .role-box div:nth-child(2) {
        font-size: 18px !important;
    }

    .form-control {
        padding: 10px;
        font-size: 14px;
    }

    .btn-main, .btn-back {
        font-size: 13px;
        padding: 8px;
    }

    .language-item {
        padding: 10px;
    }

    .mode-option {
        flex: 1;
        text-align: center;
    }

    .certificate-item .d-flex {
        flex-direction: column;
    }

    .certificate-item .btn-danger {
        align-self: flex-start;
    }
}
  </style>
</head>

<body>
<div class="signup-container">

  <div class="signup-left" id="signupLeft">
    <img src="../assets/img/logo.png" class="signup-logo" alt="Kyoshi logo">
  </div>

  <div class="signup-right">
    <div class="signup-box">

      <!-- STEP 1: Role selection -->
      <div id="roleStep">
        <h3 class="fw-bold mb-1">Create Account</h3>
        <p class="text-muted mb-3" style="font-size:14px;">Select your role to continue</p>

        <div class="role-box" style="padding:30px;" onclick="selectRole(this, 'student')">
          <div style="font-size:48px; margin-bottom:10px;">👨‍🎓</div>
          <div style="font-size:24px; font-weight:700;">Student</div>
          <div style="font-size:12px; color:#666; margin-top:5px;">Learn new languages</div>
        </div>

        <div class="role-box" style="padding:30px;" onclick="selectRole(this, 'tutor')">
         <div style="font-size:48px; margin-bottom:10px;">👨‍🏫</div>
          <div style="font-size:24px; font-weight:700;">Tutor</div>
          <div style="font-size:12px; color:#666; margin-top:5px;">Share your knowledge</div>
        </div>

        <button type="button" class="btn-main mt-3" onclick="goNext()">Continue</button>
        <button type="button" class="btn btn-secondary w-100 mt-2" onclick="goHome()">Back to Home Page</button>
      </div>

      <!-- STEP 2: Form -->
      <div id="formStep" style="display:none;">

        <button type="button" class="btn-back" onclick="goBack()">← Back</button>
        <h4 class="fw-bold mb-3" id="formTitle"></h4>

        <div id="errBox"></div>

        <form action="signup_process.php" method="POST" enctype="multipart/form-data" id="signupForm" novalidate>
          <input type="hidden" name="role" id="roleSubmit">
          <input type="text" name="fullname" class="form-control mb-2" placeholder="Full Name" required>
          <input type="email" name="email" class="form-control mb-2" placeholder="Email Address" required>

          <!-- Password -->
          <div class="password-wrapper">
            <input type="password" name="password" id="passInput" class="form-control" placeholder="Password" oninput="liveCheck()" required>
            <span class="eye-icon" onclick="toggleVis('passInput', this)">
              <i class="bi bi-eye"></i>
            </span>
          </div>

          <div id="rulesBox">
            <div class="rule-item" id="r-len"><i class="bi bi-circle"></i> At least 8 characters</div>
            <div class="rule-item" id="r-up"><i class="bi bi-circle"></i> One uppercase letter</div>
            <div class="rule-item" id="r-num"><i class="bi bi-circle"></i> One number</div>
            <div class="rule-item" id="r-sp"><i class="bi bi-circle"></i> One special character (!@#$…)</div>
          </div>

          <div class="password-wrapper">
            <input type="password" name="confirm_password" id="confirmInput" class="form-control" placeholder="Confirm Password" oninput="checkMatch()" required>
            <span class="eye-icon" onclick="toggleVis('confirmInput', this)">
              <i class="bi bi-eye"></i>
            </span>
          </div>
          <div id="matchMsg"></div>

          <input type="tel" name="phone" id="phoneInput" class="form-control mb-2" placeholder="Phone Number" required>

          <!-- STUDENT FIELDS -->
          <div id="studentFields" style="display:none;">
            <hr class="my-3">
            <p class="text-muted mb-2" style="font-size:13px;">Languages You Want to Learn & Your Current Level</p>
            <div id="studentLanguagesContainer">
              <div class="language-item">
                <div class="row">
                  <label class="lang-checkbox">
                    <input type="checkbox" name="student_languages[]" value="English" onchange="toggleStudentProficiency(this, 'eng_student_proficiency')">
                    <span>🇬🇧 English</span>
                  </label>
                  <select name="student_proficiency_english" id="eng_student_proficiency" class="form-control proficiency-select" disabled>
                    <option value="">Select your level</option>
                    <option value="beginner">Beginner</option>
                    <option value="intermediate">Intermediate</option>
                    <option value="advanced">Advanced</option>
                    <option value="master">Master</option>
                  </select>
                </div>
              </div>

              <div class="language-item">
                <div class="row">
                  <label class="lang-checkbox">
                    <input type="checkbox" name="student_languages[]" value="Japanese" onchange="toggleStudentProficiency(this, 'jp_student_proficiency')">
                    <span>🇯🇵 Japanese</span>
                  </label>
                  <select name="student_proficiency_japanese" id="jp_student_proficiency" class="form-control proficiency-select" disabled>
                    <option value="">Select your level</option>
                    <option value="beginner">Beginner</option>
                    <option value="intermediate">Intermediate</option>
                    <option value="advanced">Advanced</option>
                    <option value="master">Master</option>
                  </select>
                </div>
              </div>

              <div class="language-item">
                <div class="row">
                  <label class="lang-checkbox">
                    <input type="checkbox" name="student_languages[]" value="Mandarin" onchange="toggleStudentProficiency(this, 'cn_student_proficiency')">
                    <span>🇨🇳 Mandarin</span>
                  </label>
                  <select name="student_proficiency_mandarin" id="cn_student_proficiency" class="form-control proficiency-select" disabled>
                    <option value="">Select your level</option>
                    <option value="beginner">Beginner</option>
                    <option value="intermediate">Intermediate</option>
                    <option value="advanced">Advanced</option>
                    <option value="master">Master</option>
                  </select>
                </div>
              </div>

              <div class="language-item">
                <div class="row">
                  <label class="lang-checkbox">
                    <input type="checkbox" name="student_languages[]" value="Malay" onchange="toggleStudentProficiency(this, 'my_student_proficiency')">
                    <span>🇲🇾 Malay</span>
                  </label>
                  <select name="student_proficiency_malay" id="my_student_proficiency" class="form-control proficiency-select" disabled>
                    <option value="">Select your level</option>
                    <option value="beginner">Beginner</option>
                    <option value="intermediate">Intermediate</option>
                    <option value="advanced">Advanced</option>
                    <option value="master">Master</option>
                  </select>
                </div>
              </div>

              <div class="language-item">
                <div class="row">
                  <label class="lang-checkbox">
                    <input type="checkbox" name="student_languages[]" value="Korean" onchange="toggleStudentProficiency(this, 'kr_student_proficiency')">
                    <span>🇰🇷 Korean</span>
                  </label>
                  <select name="student_proficiency_korean" id="kr_student_proficiency" class="form-control proficiency-select" disabled>
                    <option value="">Select your level</option>
                    <option value="beginner">Beginner</option>
                    <option value="intermediate">Intermediate</option>
                    <option value="advanced">Advanced</option>
                    <option value="master">Master</option>
                  </select>
                </div>
              </div>
            </div>

            <hr class="my-3">
            <p class="text-muted mb-2" style="font-size:13px;">Preferred Learning Mode</p>
            <div class="d-flex gap-3 mb-2">
              <div class="mode-option" onclick="toggleMode(this, 'online', 'student')">
                <input type="checkbox" name="student_learning_mode[]" value="online" style="display:none;">
                <i class="bi bi-laptop"></i> Online
              </div>
              <div class="mode-option" onclick="toggleMode(this, 'face_to_face', 'student')">
                <input type="checkbox" name="student_learning_mode[]" value="face_to_face" style="display:none;">
                <i class="bi bi-people"></i> Face to Face
              </div>
            </div>

            <div id="studentLocationBox" style="display:none; margin-top:10px;">
              <p class="text-muted mb-2" style="font-size:13px;">
                <img src="../assets/img/location.png" alt="Location" width="16" height="16">
                Preferred Location
              </p>
              <select name="student_location" class="form-control mb-2">
                <option value="">-- Select City --</option>
                <option value="Kuala Lumpur">Kuala Lumpur</option>
                <option value="Penang">Penang</option>
                <option value="Johor Bahru">Johor Bahru</option>
                <option value="Kota Kinabalu">Kota Kinabalu</option>
              </select>
            </div>
          </div>

          <!-- TUTOR FIELDS -->
          <div id="tutorFields" style="display:none;">
            <hr class="my-3">
            <p class="text-muted mb-2" style="font-size:13px;">Languages You Teach & Your Proficiency Level</p>
            <div id="studentLanguagesContainer"></div>
            <div id="tutorLanguagesContainer">
              <div class="language-item">
                <div class="row">
                  <label class="lang-checkbox">
                    <input type="checkbox" name="tutor_languages[]" value="English" onchange="toggleTutorProficiency(this, 'eng_tutor_proficiency')">
                    <span>🇬🇧 English</span>
                  </label>
                  <select name="tutor_proficiency_english" id="eng_tutor_proficiency" class="form-control proficiency-select" disabled>
                    <option value="">Select your proficiency</option>
                    <option value="beginner">Beginner (Can teach beginners only)</option>
                    <option value="intermediate">Intermediate (Can teach up to Intermediate)</option>
                    <option value="advanced">Advanced (Can teach up to Advanced)</option>
                    <option value="master">Master (Can teach ALL levels)</option>
                  </select>
                </div>
              </div>

              <div class="language-item">
                <div class="row">
                  <label class="lang-checkbox">
                    <input type="checkbox" name="tutor_languages[]" value="Japanese" onchange="toggleTutorProficiency(this, 'jp_tutor_proficiency')">
                    <span>🇯🇵 Japanese</span>
                  </label>
                  <select name="tutor_proficiency_japanese" id="jp_tutor_proficiency" class="form-control proficiency-select" disabled>
                    <option value="">Select your proficiency</option>
                    <option value="beginner">Beginner (Can teach beginners only)</option>
                    <option value="intermediate">Intermediate (Can teach up to Intermediate)</option>
                    <option value="advanced">Advanced (Can teach up to Advanced)</option>
                    <option value="master">Master (Can teach ALL levels)</option>
                  </select>
                </div>
              </div>

              <div class="language-item">
                <div class="row">
                  <label class="lang-checkbox">
                    <input type="checkbox" name="tutor_languages[]" value="Mandarin" onchange="toggleTutorProficiency(this, 'cn_tutor_proficiency')">
                    <span>🇨🇳 Mandarin</span>
                  </label>
                  <select name="tutor_proficiency_mandarin" id="cn_tutor_proficiency" class="form-control proficiency-select" disabled>
                    <option value="">Select your proficiency</option>
                    <option value="beginner">Beginner (Can teach beginners only)</option>
                    <option value="intermediate">Intermediate (Can teach up to Intermediate)</option>
                    <option value="advanced">Advanced (Can teach up to Advanced)</option>
                    <option value="master">Master (Can teach ALL levels)</option>
                  </select>
                </div>
              </div>

              <div class="language-item">
                <div class="row">
                  <label class="lang-checkbox">
                    <input type="checkbox" name="tutor_languages[]" value="Malay" onchange="toggleTutorProficiency(this, 'my_tutor_proficiency')">
                    <span>🇲🇾 Malay</span>
                  </label>
                  <select name="tutor_proficiency_malay" id="my_tutor_proficiency" class="form-control proficiency-select" disabled>
                    <option value="">Select your proficiency</option>
                    <option value="beginner">Beginner (Can teach beginners only)</option>
                    <option value="intermediate">Intermediate (Can teach up to Intermediate)</option>
                    <option value="advanced">Advanced (Can teach up to Advanced)</option>
                    <option value="master">Master (Can teach ALL levels)</option>
                  </select>
                </div>
              </div>

              <div class="language-item">
                <div class="row">
                  <label class="lang-checkbox">
                    <input type="checkbox" name="tutor_languages[]" value="Korean" onchange="toggleTutorProficiency(this, 'kr_tutor_proficiency')">
                    <span>🇰🇷 Korean</span>
                  </label>
                  <select name="tutor_proficiency_korean" id="kr_tutor_proficiency" class="form-control proficiency-select" disabled>
                    <option value="">Select your proficiency</option>
                    <option value="beginner">Beginner (Can teach beginners only)</option>
                    <option value="intermediate">Intermediate (Can teach up to Intermediate)</option>
                    <option value="advanced">Advanced (Can teach up to Advanced)</option>
                    <option value="master">Master (Can teach ALL levels)</option>
                  </select>
                </div>
              </div>
            </div>

            <hr class="my-3">
            <p class="text-muted mb-2" style="font-size:13px;">Teaching Mode</p>
            <div class="d-flex gap-3 mb-3">
              <div class="mode-option" onclick="toggleMode(this, 'online', 'tutor')">
                <input type="checkbox" name="tutor_teaching_mode[]" value="online" style="display:none;">
                <i class="bi bi-laptop"></i> Online
              </div>
              <div class="mode-option" onclick="toggleMode(this, 'face_to_face', 'tutor')">
                <input type="checkbox" name="tutor_teaching_mode[]" value="face_to_face" style="display:none;">
                <i class="bi bi-people"></i> Face to Face
              </div>
            </div>

            <div id="tutorLocationBox" style="display:none; margin-top:10px; margin-bottom:10px;">
              <p class="text-muted mb-2 d-flex align-items-center gap-1" style="font-size:13px;">
                <img src="../assets/img/location.png" alt="Location" width="16" height="16">
                Your Teaching Location
              </p>
              <select name="tutor_location" class="form-control mb-2">
                <option value="">-- Select City --</option>
                <option value="Kuala Lumpur">Kuala Lumpur</option>
                <option value="Penang">Penang</option>
                <option value="Johor Bahru">Johor Bahru</option>
                <option value="Kota Kinabalu">Kota Kinabalu</option>
              </select>
            </div>

            <input type="number" name="experience" class="form-control mb-2" placeholder="Years of Experience" min="0" required>
            <input type="text" name="rate" class="form-control mb-2" placeholder="Hourly Rate (RM)" required>
            <textarea name="bio" class="form-control mb-2" placeholder="Short bio about yourself" rows="3" required></textarea>
<!-- Profile Picture Section with X button inside input -->
<label class="form-label text-muted" style="font-size:13px;">
    Profile Picture <span class="text-danger">*</span>
    <small class="text-muted d-block">Please upload a clear photo of your face. Students need to see who they are booking with.</small>
</label>
<div style="position: relative; margin-bottom: 15px;">
    <input type="file" name="profile_pic" id="profilePicInput" class="form-control" accept="image/*" required style="padding-right: 40px;">
    <button type="button" id="clearProfilePicBtn" style="position: absolute; right: 10px; top: 50%; transform: translateY(-50%); background: none; border: none; color: #dc3545; font-size: 18px; cursor: pointer; display: none;">
        <i class="bi bi-x-circle-fill"></i>
    </button>
</div>
<div id="profilePicPreview" style="display:none; margin-top: 10px; margin-bottom: 15px;">
    <img id="profilePicPreviewImg" src="#" alt="Preview" style="width: 80px; height: 80px; object-fit: cover; border-radius: 10px; border: 1px solid #ddd;">
</div>
<div id="photoConfirmBox" style="display: none; margin-bottom: 15px; padding: 10px; background: #cdeaff; border: 1px solid #ffeeba; border-radius: 10px;">
    <label style="display: flex; align-items: center; gap: 10px; cursor: pointer;">
        <input type="checkbox" id="realPhotoConfirm" required>
        <span style="font-size: 13px;">
            I confirm that this is a <strong>real photo of myself</strong>.<br> I understand that using fake photos may result in account rejection in applying.
        </span>
    </label>
</div>
<label class="form-label text-muted" style="font-size:13px;">
    Certificates <span class="text-danger">*</span>
    <small class="text-muted d-block">Upload your teaching certificates (PDF, JPG, PNG)</small>
</label>

<div id="certificatesContainer">
    <div class="certificate-item" style="margin-bottom: 15px;">
        <div style="position: relative;">
            <input type="file" name="certificates[]" class="form-control certificate-input" accept=".pdf,.jpg,.jpeg,.png" required style="padding-right: 40px;">
            <button type="button" class="clear-cert-btn" style="position: absolute; right: 10px; top: 50%; transform: translateY(-50%); background: none; border: none; color: #dc3545; font-size: 18px; cursor: pointer; display: none;">
                <i class="bi bi-x-circle-fill"></i>
            </button>
        </div>
    </div>
</div>

<button type="button" id="addCertificateBtn" class="btn btn-sm btn-secondary mb-3" style="background:#e2e8f0; border:none; padding:5px 12px; border-radius:20px;">
    <i class="bi bi-plus"></i> Add Another Certificate
</button>
          <button type="submit" class="btn-main mt-3" id="submitBtn">Create Account</button>
        </form>
      </div>
    </div>
  </div>
</div>
<!-- Image Preview Modal -->
<div id="imageModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.8); z-index: 9999; align-items: center; justify-content: center; cursor: pointer;">
    <img id="modalImage" src="" alt="Full size preview" style="max-width: 90%; max-height: 90%; object-fit: contain; border-radius: 10px;">
    <button id="closeModalBtn" style="position: absolute; top: 20px; right: 30px; background: white; border: none; font-size: 30px; cursor: pointer; width: 40px; height: 40px; border-radius: 50%;">&times;</button>
</div>
<script src="https://cdn.jsdelivr.net/npm/intl-tel-input@18/build/js/intlTelInput.min.js"></script>

<script>
  let currentRole = '';
  let itiInstance = null;

  function selectRole(el, role) {
    currentRole = role;
    document.querySelectorAll('.role-box').forEach(b => b.classList.remove('active'));
    el.classList.add('active');
    const left = document.getElementById('signupLeft');
    left.style.backgroundImage = role === 'tutor'
      ? "url('../assets/img/tutor.jpg')"
      : "url('../assets/img/student.jpg')";
  }
  // ========== PROFILE PICTURE - X button inside input ==========
const profilePicInput = document.getElementById('profilePicInput');
const clearProfilePicBtn = document.getElementById('clearProfilePicBtn');
const profilePicPreview = document.getElementById('profilePicPreview');
const profilePicPreviewImg = document.getElementById('profilePicPreviewImg');

if (profilePicInput) {
    profilePicInput.addEventListener('change', function() {
        if (this.files && this.files[0]) {
            const reader = new FileReader();
            reader.onload = function(e) {
                profilePicPreviewImg.src = e.target.result;
                profilePicPreview.style.display = 'block';
                if (clearProfilePicBtn) clearProfilePicBtn.style.display = 'block';
            };
            reader.readAsDataURL(this.files[0]);
        }
    });
}

if (clearProfilePicBtn) {
    clearProfilePicBtn.addEventListener('click', function() {
        profilePicInput.value = '';
        profilePicPreview.style.display = 'none';
        profilePicPreviewImg.src = '#';
        clearProfilePicBtn.style.display = 'none';
    });
}

// Show confirmation checkbox when photo is selected
const photoConfirmBox = document.getElementById('photoConfirmBox');
const realPhotoConfirm = document.getElementById('realPhotoConfirm');
const imageModal = document.getElementById('imageModal');
const modalImage = document.getElementById('modalImage');
const closeModalBtn = document.getElementById('closeModalBtn');

if (profilePicInput) {
    profilePicInput.addEventListener('change', function() {
        if (this.files && this.files[0]) {
            const reader = new FileReader();
            reader.onload = function(e) {
                profilePicPreviewImg.src = e.target.result;
                profilePicPreview.style.display = 'block';
                if (clearProfilePicBtn) clearProfilePicBtn.style.display = 'block';
                // Show confirmation checkbox
                if (photoConfirmBox) photoConfirmBox.style.display = 'block';
            };
            reader.readAsDataURL(this.files[0]);
        } else {
            profilePicPreview.style.display = 'none';
            if (clearProfilePicBtn) clearProfilePicBtn.style.display = 'none';
            if (photoConfirmBox) photoConfirmBox.style.display = 'none';
            if (realPhotoConfirm) realPhotoConfirm.checked = false;
        }
    });
}

// Make preview image clickable to expand
if (profilePicPreviewImg) {
    profilePicPreviewImg.style.cursor = 'pointer';
    profilePicPreviewImg.style.transition = 'transform 0.2s';
    profilePicPreviewImg.addEventListener('mouseenter', function() {
        this.style.transform = 'scale(1.02)';
    });
    profilePicPreviewImg.addEventListener('mouseleave', function() {
        this.style.transform = 'scale(1)';
    });
    profilePicPreviewImg.addEventListener('click', function() {
        if (modalImage && imageModal) {
            modalImage.src = this.src;
            imageModal.style.display = 'flex';
        }
    });
}

// Close modal when clicking background or close button
if (imageModal) {
    imageModal.addEventListener('click', function(e) {
        if (e.target === imageModal || e.target === closeModalBtn) {
            imageModal.style.display = 'none';
        }
    });
}

if (closeModalBtn) {
    closeModalBtn.addEventListener('click', function() {
        imageModal.style.display = 'none';
    });
}

function initCertificateClear(input, clearBtn) {
    // Show clear button when file selected
    input.addEventListener('change', function() {
        if (this.files && this.files[0]) {
            clearBtn.style.display = 'block';
        } else {
            clearBtn.style.display = 'none';
        }
    });
    
    // Clear button functionality - clears the file input
    clearBtn.addEventListener('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        input.value = '';
        clearBtn.style.display = 'none';
    });
}

// Initialize existing certificates
document.querySelectorAll('.certificate-item').forEach(item => {
    const input = item.querySelector('.certificate-input');
    const clearBtn = item.querySelector('.clear-cert-btn');
    
    if (input && clearBtn) {
        initCertificateClear(input, clearBtn);
    }
});

// Add new certificate with X button inside
const addCertBtn = document.getElementById('addCertificateBtn');
if (addCertBtn) {
    const newAddBtn = addCertBtn.cloneNode(true);
    addCertBtn.parentNode.replaceChild(newAddBtn, addCertBtn);
    
    newAddBtn.addEventListener('click', function() {
        const container = document.getElementById('certificatesContainer');
        const newDiv = document.createElement('div');
        newDiv.className = 'certificate-item';
        newDiv.style.marginBottom = '15px';
        newDiv.innerHTML = `
            <div style="position: relative;">
                <input type="file" name="certificates[]" class="form-control certificate-input" accept=".pdf,.jpg,.jpeg,.png" style="padding-right: 40px;">
                <button type="button" class="clear-cert-btn" style="position: absolute; right: 10px; top: 50%; transform: translateY(-50%); background: none; border: none; color: #dc3545; font-size: 18px; cursor: pointer; display: none;">
                    <i class="bi bi-x-circle-fill"></i>
                </button>
            </div>
            <button type="button" class="remove-cert-row-btn btn btn-sm btn-outline-danger" style="margin-top: 5px; padding: 2px 8px;">Remove</button>
        `;
        container.appendChild(newDiv);
        
        const newInput = newDiv.querySelector('.certificate-input');
        const newClearBtn = newDiv.querySelector('.clear-cert-btn');
        const removeRowBtn = newDiv.querySelector('.remove-cert-row-btn');
        
        initCertificateClear(newInput, newClearBtn);
        
        if (removeRowBtn) {
            removeRowBtn.addEventListener('click', function() {
                newDiv.remove();
            });
        }
    });
}

  function goNext() {
    if (!currentRole) { alert('Please select a role first.'); return; }
    document.getElementById('roleStep').style.display = 'none';
    document.getElementById('formStep').style.display = 'block';
    document.getElementById('formTitle').textContent = currentRole === 'tutor' ? 'Tutor Sign Up' : 'Student Sign Up';
    document.getElementById('studentFields').style.display = currentRole === 'student' ? 'block' : 'none';
    document.getElementById('tutorFields').style.display = currentRole === 'tutor' ? 'block' : 'none';
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
    document.getElementById('roleSubmit').value = '';
    document.querySelectorAll('.role-box').forEach(b => b.classList.remove('active'));
    hideErr();
    document.getElementById('rulesBox').style.display = 'none';
    document.getElementById('matchMsg').textContent = '';
    document.getElementById('matchMsg').className = '';
  }

  function goHome() {
    window.location.href = '../index.html';
}

  function showErr(msg) {
    const b = document.getElementById('errBox');
    b.textContent = msg;
    b.style.display = 'block';
    b.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }
  function hideErr() { document.getElementById('errBox').style.display = 'none'; }

  function toggleVis(inputId, span) {
    const inp = document.getElementById(inputId);
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
    if (ok) { el.classList.add('pass'); ic.className = 'bi bi-check-circle-fill'; }
    else { el.classList.remove('pass'); ic.className = 'bi bi-circle'; }
  }

  function liveCheck() {
    const val = document.getElementById('passInput').value;
    document.getElementById('rulesBox').style.display = val.length > 0 ? 'block' : 'none';
    setRule('r-len', val.length >= 8);
    setRule('r-up', /[A-Z]/.test(val));
    setRule('r-num', /[0-9]/.test(val));
    setRule('r-sp', /[!@#$%^&*(),.?":{}|<>]/.test(val));
    checkMatch();
  }

  function checkMatch() {
    const p = document.getElementById('passInput').value;
    const c = document.getElementById('confirmInput').value;
    const m = document.getElementById('matchMsg');
    if (!c) { m.textContent = ''; m.className = ''; return; }
    if (p === c) { m.textContent = '✓ Passwords match'; m.className = 'ok'; }
    else { m.textContent = '✗ Passwords do not match'; m.className = 'fail'; }
  }

  function isValidEmail(email) {
    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
  }

  // Toggle student proficiency select
  function toggleStudentProficiency(checkbox, selectId) {
    const select = document.getElementById(selectId);
    if (checkbox.checked) {
      select.disabled = false;
      select.required = true;
    } else {
      select.disabled = true;
      select.required = false;
      select.value = '';
    }
  }

  // Toggle tutor proficiency select
  function toggleTutorProficiency(checkbox, selectId) {
    const select = document.getElementById(selectId);
    if (checkbox.checked) {
      select.disabled = false;
      select.required = true;
    } else {
      select.disabled = true;
      select.required = false;
      select.value = '';
    }
  }

  // Toggle mode selection (Online/Face to Face)
  function toggleMode(element, mode, role) {
    const checkbox = element.querySelector('input[type="checkbox"]');
    checkbox.checked = !checkbox.checked;
    
    if (checkbox.checked) {
      element.classList.add('selected');
    } else {
      element.classList.remove('selected');
    }
    
    // Show location box if Face to Face is selected
    if (role === 'student') {
      const ftfCheckbox = document.querySelector('#studentFields input[value="face_to_face"]');
      const locationBox = document.getElementById('studentLocationBox');
      locationBox.style.display = (ftfCheckbox && ftfCheckbox.checked) ? 'block' : 'none';
    } else {
      const ftfCheckbox = document.querySelector('#tutorFields input[value="face_to_face"]');
      const locationBox = document.getElementById('tutorLocationBox');
      locationBox.style.display = (ftfCheckbox && ftfCheckbox.checked) ? 'block' : 'none';
    }
  }document.getElementById('signupForm').addEventListener('submit', function(e) {
    hideErr();
    
    const fullname = document.querySelector('input[name="fullname"]').value.trim();
    const email = document.querySelector('input[name="email"]').value.trim();
    const pass = document.getElementById('passInput').value;
    const confirm = document.getElementById('confirmInput').value;

    if (!fullname || !email || !pass || !confirm) {
        e.preventDefault();
        showErr('Please fill all required fields.');
        return;
    }
    
    if (!isValidEmail(email)) {
        e.preventDefault();
        showErr('Please enter a valid email address.');
        return;
    }

    const len = pass.length >= 8;
    const up = /[A-Z]/.test(pass);
    const num = /[0-9]/.test(pass);
    const sp = /[!@#$%^&*(),.?":{}|<>]/.test(pass);

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

    // Student validation
    if (currentRole === 'student') {
        const langs = document.querySelectorAll('input[name="student_languages[]"]:checked');
        if (langs.length === 0) {
            e.preventDefault();
            showErr('Please select at least one language you want to learn.');
            return;
        }
        
        for (let lang of langs) {
            const langValue = lang.value;
            let selectId = '';
            if (langValue === 'English') selectId = 'eng_student_proficiency';
            else if (langValue === 'Japanese') selectId = 'jp_student_proficiency';
            else if (langValue === 'Mandarin') selectId = 'cn_student_proficiency';
            else if (langValue === 'Malay') selectId = 'my_student_proficiency';
            else if (langValue === 'Korean') selectId = 'kr_student_proficiency';
            
            const proficiency = document.getElementById(selectId).value;
            if (!proficiency) {
                e.preventDefault();
                showErr(`Please select your proficiency level for ${langValue}.`);
                return;
            }
        }
        
        const studentFTF = document.querySelector('#studentFields input[value="face_to_face"]');
        if (studentFTF && studentFTF.checked) {
            const loc = document.querySelector('select[name="student_location"]').value;
            if (!loc) {
                e.preventDefault();
                showErr('Please select your preferred city for Face to Face lessons.');
                return;
            }
        }
    }

    // Tutor validation
    if (currentRole === 'tutor') {
        const langs = document.querySelectorAll('input[name="tutor_languages[]"]:checked');
        if (langs.length === 0) {
            e.preventDefault();
            showErr('Please select at least one language you teach.');
            return;
        }
        
        for (let lang of langs) {
            const langValue = lang.value;
            let selectId = '';
            if (langValue === 'English') selectId = 'eng_tutor_proficiency';
            else if (langValue === 'Japanese') selectId = 'jp_tutor_proficiency';
            else if (langValue === 'Mandarin') selectId = 'cn_tutor_proficiency';
            else if (langValue === 'Malay') selectId = 'my_tutor_proficiency';
            else if (langValue === 'Korean') selectId = 'kr_tutor_proficiency';
            
            const proficiency = document.getElementById(selectId).value;
            if (!proficiency) {
                e.preventDefault();
                showErr(`Please select your proficiency level for ${langValue}.`);
                return;
            }
        }
        
        const teachingModes = document.querySelectorAll('input[name="tutor_teaching_mode[]"]:checked');
        if (teachingModes.length === 0) {
            e.preventDefault();
            showErr('Please select at least one teaching mode.');
            return;
        }
        
        const experience = document.querySelector('input[name="experience"]').value.trim();
        const rate = document.querySelector('input[name="rate"]').value.trim();
        const bio = document.querySelector('textarea[name="bio"]').value.trim();

        if (!experience) {
            e.preventDefault();
            showErr('Please enter your years of experience.');
            return;
        }
        if (!rate) {
            e.preventDefault();
            showErr('Please enter your hourly rate.');
            return;
        }
        if (!bio) {
            e.preventDefault();
            showErr('Please write a short bio about yourself.');
            return;
        }
        
        const tutorFTF = document.querySelector('#tutorFields input[value="face_to_face"]');
        if (tutorFTF && tutorFTF.checked) {
            const loc = document.querySelector('select[name="tutor_location"]').value;
            if (!loc) {
                e.preventDefault();
                showErr('Please select your teaching city for Face to Face sessions.');
                return;
            }
        }

         const profilePic = document.getElementById('profilePicInput');
          if (!profilePic.files || profilePic.files.length === 0) {
              e.preventDefault();
              showErr('Please upload a profile photo. Students need to see who they are booking with.');
              return;
          }
          
          const realPhotoConfirm = document.getElementById('realPhotoConfirm');
          if (!realPhotoConfirm || !realPhotoConfirm.checked) {
              e.preventDefault();
              showErr('Please confirm that you have uploaded a real photo of yourself. Students need to see who they are booking with.');
              return;
          }
        
        const certInputs = document.querySelectorAll('input[name="certificates[]"]');
        let hasCert = false;
        for (let i = 0; i < certInputs.length; i++) {
            if (certInputs[i].files && certInputs[i].files.length > 0) {
                hasCert = true;
                break;
            }
        }

        
        if (!hasCert) {
            e.preventDefault();
            showErr('Please upload at least one certificate for verification.');
            return;
        }

    }

    // ── ALL VALIDATION PASSED - NOW SHOW CONFIRMATION ──
    if (!confirm(`Are you sure you want to register with email: ${email}?`)) {
        e.preventDefault();
        return;
    }

    if (itiInstance) {
        document.getElementById('phoneInput').value = itiInstance.getNumber();
    }
});
</script>

<div id="toast" style="position: fixed; bottom: 30px; left: 50%; transform: translateX(-50%); background: #1e1e1e; color: white; padding: 12px 24px; border-radius: 8px; font-size: 14px; opacity: 0; transition: opacity 0.3s ease; z-index: 9999; white-space: nowrap;"></div>

<script>
function showToast(msg) {
  const toast = document.getElementById('toast');
  toast.textContent = msg;
  toast.style.opacity = '1';
  setTimeout(() => toast.style.opacity = '0', 3000);
}


// Show remove button for first certificate only if more than one
function updateRemoveButtons() {
    const items = document.querySelectorAll('.certificate-item');
    items.forEach((item, index) => {
        const removeBtn = item.querySelector('.remove-cert-btn');
        if (removeBtn) {
            removeBtn.style.display = items.length > 1 ? 'inline-block' : 'none';
        }
    });
}

<?php if (isset($_SESSION['error'])): ?>
  showToast("<?php echo addslashes($_SESSION['error']); unset($_SESSION['error']); ?>");
<?php endif; ?>

<?php if (isset($_SESSION['success'])): ?>
  showToast("<?php echo addslashes($_SESSION['success']); unset($_SESSION['success']); ?>");
  setTimeout(() => window.location.href = 'login.php', 3000);
<?php endif; ?>
</script>
</body>
</html>