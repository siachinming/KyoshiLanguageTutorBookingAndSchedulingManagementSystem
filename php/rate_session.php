<?php
session_start();
include 'config.php';
include 'check_login.php';
$assetBase = '../assets/img';

if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit(); }
$userID = $_SESSION['user_id'];


$bookingID = intval($_GET['id'] ?? 0);
if (!$bookingID) { header("Location: booking_status.php"); exit(); }

// Get booking — must belong to this student and be completed
$stmt = $conn->prepare("
    SELECT b.*, 
           u.fullname AS tutor_name, 
           u.profile_pic AS tutor_pic,
           tp.rate, tp.experience,
           GROUP_CONCAT(DISTINCT tl.language) AS tutor_languages,
           r.id AS rated, r.rating AS my_rating, r.comment AS my_comment, r.is_anonymous AS my_anonymous
    FROM bookings b
    JOIN users u ON b.tutor_id = u.id
    JOIN tutor_profiles tp ON b.tutor_id = tp.user_id
    LEFT JOIN tutor_languages tl ON b.tutor_id = tl.user_id
    LEFT JOIN ratings r ON r.booking_id = b.id AND r.student_id = ?
    WHERE b.id = ? AND b.student_id = ? AND b.status = 'completed'
    GROUP BY b.id
");
$stmt->bind_param("iii", $userID, $bookingID, $userID);
$stmt->execute();
$b = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$b) { header("Location: booking_status.php"); exit(); }

$user = $conn->query("SELECT * FROM users WHERE id = $userID")->fetch_assoc();
$displayName = $user['fullname'];
if (!empty($user['profile_pic']) && file_exists('../uploads/profiles/' . $user['profile_pic'])) {
    $profilePic = '../uploads/profiles/' . $user['profile_pic'];
} else {
    $profilePic = $assetBase . '/profile.png';
}
$tutorPic    = !empty($b['tutor_pic']) ? '../uploads/profiles/' . $b['tutor_pic'] : $assetBase . '/profile-tutor.png';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($b['rated'])) {

    $rating      = intval($_POST['rating'] ?? 0);
    $comment     = trim($_POST['comment'] ?? '');
    $isAnonymous = isset($_POST['is_anonymous']) ? 1 : 0;

    if ($rating >= 1 && $rating <= 5) {

        $stmt = $conn->prepare("
            INSERT INTO ratings
            (booking_id, student_id, tutor_id, rating, comment, is_anonymous, created_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");

        if (!$stmt) {
            die("Prepare failed: " . $conn->error);
        }

        $stmt->bind_param(
            "iiiisi",
            $bookingID,
            $userID,
            $b['tutor_id'],
            $rating,
            $comment,
            $isAnonymous
        );

        if (!$stmt->execute()) {
            die("Execute failed: " . $stmt->error);
        }

        $stmt->close();

$nextIds = $_GET['next'] ?? '';
if (!empty($nextIds)) {
    $nextIdArray = explode(',', $nextIds);
    if (!empty($nextIdArray)) {
        $firstNextId = intval($nextIdArray[0]);
        $remainingNextIds = array_slice($nextIdArray, 1);
        $nextParam = !empty($remainingNextIds) ? '&next=' . implode(',', $remainingNextIds) : '';
        header("Location: rate_session.php?id=" . $firstNextId . "&from_chain=1" . $nextParam);
        exit();
    }
} else {
    header("Location: booking_status.php?rated=1");
}
exit();
    }
}


function e($v){ return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
<meta http-equiv="Pragma" content="no-cache">
<meta http-equiv="Expires" content="0">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Rate Session · Kyoshi</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
  <link rel="stylesheet" href="../css/style.css">
  <style>
    :root{
      --cream:#FFF1F6;--paper:rgba(255,255,255,.88);--ink:#342635;--muted:#7B6178;
      --pink:#F28AB2;--pink-dark:#C94F86;--hot-pink:#E75A9B;
      --shadow:0 18px 45px rgba(201,79,134,.16);--radius-xl:32px;--radius-lg:24px;
    }
    *{box-sizing:border-box}html{scroll-behavior:smooth}
    body{margin:0;min-height:100vh;font-family:"Segoe UI",Arial,sans-serif;color:var(--ink);
      background:linear-gradient(120deg,rgba(255,241,246,.74),rgba(255,203,220,.30)),
      url("<?= e($assetBase) ?>/background3.jpg") center/cover fixed no-repeat;}
    body::before{content:"";position:fixed;inset:0;pointer-events:none;z-index:-1;
      background:radial-gradient(circle at 7% 10%,rgba(231,90,155,.32),transparent 24%),
      radial-gradient(circle at 90% 8%,rgba(255,195,216,.42),transparent 26%)}
    a{text-decoration:none;color:inherit}button,input,select,textarea{font-family:inherit}
    .container{width:min(640px,calc(100% - 40px));margin:0 auto;padding:28px 0 60px}

    .topbar{position:sticky;top:0;z-index:50;background:rgba(255,241,246,.86);backdrop-filter:blur(20px);border-bottom:1px solid rgba(231,90,155,.18);box-shadow:0 10px 30px rgba(201,79,134,.10)}
    .nav{min-height:78px;display:grid;grid-template-columns:190px minmax(0,1fr) auto;gap:16px;align-items:center;}
    .brand{display:flex;align-items:center;gap:10px}
    .brand img{width:44px;height:44px;object-fit:contain;border-radius:14px}
    .brand strong{display:block;font-size:18px;line-height:1.05}
    .brand span{display:block;margin-top:3px;font-size:11px;color:var(--muted);white-space:nowrap}
    .nav-links{display:flex;align-items:center;justify-content:center;gap:6px;border:1px solid rgba(242,138,178,.18);border-radius:999px;padding:7px;overflow:auto;scrollbar-width:none;}
    .nav-links::-webkit-scrollbar{display:none}
    .nav-links a{flex:0 0 auto;padding:9px 12px;border-radius:999px;font-size:13px;font-weight:900;color:#6D4964;white-space:nowrap;transition:.18s ease}
    .nav-links a.active,.nav-links a:hover{background:linear-gradient(135deg,var(--hot-pink),var(--pink));color:#fff}
    .nav-actions{display:flex;align-items:center;gap:10px}
    .profile{display:flex;align-items:center;gap:9px;border-radius:999px;padding:6px 12px 6px 6px;font-weight:900;color:#7A3D65;border:1px solid rgba(46,42,59,.08);background:rgba(255,255,255,.88);cursor:pointer}
    .profile img{width:34px;height:34px;object-fit:cover;border-radius:50%}

    .back-link{display:inline-flex;align-items:center;gap:6px;color:var(--pink-dark);font-weight:900;font-size:13px;padding:9px 16px;border-radius:999px;background:rgba(255,255,255,.78);border:1px solid rgba(46,42,59,.08);transition:.18s ease;margin-bottom:24px;}
    .back-link:hover{transform:translateY(-1px)}

    .card{background:var(--paper);border:1px solid rgba(255,255,255,.55);box-shadow:var(--shadow);border-radius:var(--radius-xl);padding:28px;margin-bottom:16px}
    .card-title{font-size:16px;font-weight:900;margin:0 0 18px;display:flex;align-items:center;gap:8px;color:#342635}
    .card-title i{color:var(--hot-pink)}

    /* Tutor card */
    .tutor-row{display:flex;align-items:center;gap:14px;padding:16px;border-radius:18px;background:rgba(255,241,246,.5);border:1px solid rgba(242,138,178,.15);margin-bottom:20px}
    .tutor-row img{width:60px;height:60px;object-fit:cover;border-radius:14px;flex:0 0 auto}
    .tutor-row h3{margin:0 0 3px;font-size:16px;font-weight:900}
    .tutor-row p{margin:0;font-size:12px;color:var(--muted)}

    /* Session summary */
    .session-pills{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:20px}
    .pill{padding:6px 14px;border-radius:999px;font-size:12px;font-weight:900;background:rgba(255,255,255,.8);border:1px solid rgba(46,42,59,.10);color:var(--muted)}
    .pill i{color:var(--hot-pink);margin-right:4px}

    /* Stars */
    .star-section{margin-bottom:20px}
    .star-section label{display:block;font-size:13px;font-weight:900;color:#342635;margin-bottom:10px}
    .star-row{display:flex;gap:10px}
    .star-btn{
      width:48px;height:48px;border-radius:14px;
      border:2px solid rgba(46,42,59,.10);background:white;
      font-size:24px;cursor:pointer;transition:.15s ease;
      display:grid;place-items:center;
    }
    .star-btn:hover{transform:scale(1.12);border-color:#FFB800}
    .star-btn.active{border-color:#FFB800;background:rgba(255,184,0,.12);transform:scale(1.08)}
    .star-label{margin-top:8px;font-size:12px;font-weight:900;color:var(--muted);min-height:18px;transition:.15s ease}

    /* Comment */
    .comment-section{margin-bottom:20px}
    .comment-section label{display:block;font-size:13px;font-weight:900;color:#342635;margin-bottom:8px}
    textarea.form-control{width:100%;padding:12px 16px;border:1px solid rgba(46,42,59,.12);border-radius:14px;outline:none;font-size:13px;color:#342635;background:rgba(255,255,255,.9);resize:vertical;min-height:100px;transition:.15s ease}
    textarea.form-control:focus{border-color:var(--hot-pink);box-shadow:0 0 0 3px rgba(231,90,155,.12)}

    /* Anonymous toggle */
    .anon-toggle{
      display:flex;align-items:center;gap:12px;
      padding:14px 18px;border-radius:16px;
      background:rgba(255,255,255,.7);border:1.5px solid rgba(46,42,59,.10);
      cursor:pointer;transition:.18s ease;margin-bottom:20px;
      user-select:none;
    }
    .anon-toggle:hover{border-color:rgba(231,90,155,.3);background:rgba(255,241,246,.6)}
    .anon-toggle.active{border-color:var(--hot-pink);background:rgba(255,241,246,.8)}
    .anon-toggle input[type=checkbox]{display:none}
    .anon-icon{
      width:38px;height:38px;border-radius:12px;
      background:rgba(255,241,246,.8);border:1px solid rgba(242,138,178,.2);
      display:grid;place-items:center;font-size:18px;flex:0 0 auto;
      transition:.15s ease;
    }
    .anon-toggle.active .anon-icon{background:linear-gradient(135deg,var(--hot-pink),var(--pink));color:white;border-color:var(--pink)}
    .anon-text strong{display:block;font-size:13px;font-weight:900;color:#342635;margin-bottom:2px}
    .anon-text span{font-size:12px;color:var(--muted);line-height:1.4}
    .anon-check{margin-left:auto;width:20px;height:20px;border-radius:6px;border:2px solid rgba(46,42,59,.15);background:white;display:grid;place-items:center;flex:0 0 auto;transition:.15s ease}
    .anon-toggle.active .anon-check{background:linear-gradient(135deg,var(--hot-pink),var(--pink));border-color:var(--pink);color:white}

    /* Preview */
    .preview-box{
      padding:14px 18px;border-radius:14px;
      background:rgba(221,211,255,.3);border:1px solid rgba(167,123,232,.2);
      font-size:13px;color:#5A3D7A;font-weight:700;
      margin-bottom:20px;display:flex;align-items:center;gap:10px;
      line-height:1.5;
    }
    .preview-box i{font-size:16px;flex:0 0 auto}

    /* Submit */
    .btn-submit{
      width:100%;padding:14px;border-radius:999px;border:none;
      background:linear-gradient(135deg,var(--hot-pink),var(--pink));
      color:white;font-size:14px;font-weight:900;cursor:pointer;
      box-shadow:0 8px 20px rgba(231,90,155,.28);
      display:flex;align-items:center;justify-content:center;gap:8px;
      transition:.18s ease;
    }
    .btn-submit:hover{transform:translateY(-2px);box-shadow:0 12px 28px rgba(231,90,155,.35)}
    .btn-submit:disabled{opacity:.6;cursor:not-allowed;transform:none}

    /* Already rated */
    .rated-box{padding:20px;border-radius:18px;background:rgba(221,211,255,.3);border:1px solid rgba(167,123,232,.2)}
    .star-display{display:flex;gap:4px;margin:10px 0}
    .star-display i{color:#FFB800;font-size:22px}
    .anon-badge{display:inline-flex;align-items:center;gap:5px;padding:4px 12px;border-radius:999px;font-size:11px;font-weight:900;background:rgba(255,241,246,.8);color:var(--pink-dark);border:1px solid rgba(242,138,178,.2);margin-top:8px}

    .toast{position:fixed;left:50%;bottom:28px;transform:translate(-50%,18px);opacity:0;pointer-events:none;z-index:99;background:#8E3F70;color:#fff;border-radius:999px;padding:12px 18px;font-size:13px;font-weight:900;transition:.2s ease}
    .toast.show{opacity:1;transform:translate(-50%,0)}

    @media(max-width:768px){
      .nav{grid-template-columns:1fr auto}
      .nav-links{display:none}
    }
  </style>
</head>
<body>

<header class="topbar">
  <div class="container" style="width:min(1440px,calc(100% - 40px));margin:0 auto;padding:0">
    <nav class="nav">
      <button class="hamburger-menu" id="hamburgerBtn">
    <i class="bi bi-list"></i>
</button>
      <a href="student_dashboard.php" class="brand">
        <img src="<?= e($assetBase) ?>/logo.png" alt="Kyoshi">
        <div><strong>Kyoshi</strong><span>Student Learning Space</span></div>
      </a>
        <div class="nav-links">
          <a href="student_dashboard.php">Home</a>
          <a  href="find_language.php">Find Language</a>
          <a href="booking_status.php">My Bookings</a>
          <a class="active" href="my_payments.php">My Payments</a>
          <a href="my_materials.php">My Materials</a>
          <a href="my_assignments.php">My Assignments</a>
        </div>
      <div class="nav-actions">
        <div style="position:relative;">
          <button class="profile" onclick="toggleDropdown()" id="profileBtn">
            <img src="<?= e($profilePic) ?>" alt="Profile">
            <span><?= e($displayName) ?></span>
            <i class="bi bi-chevron-down" style="font-size:11px;margin-left:4px;"></i>
          </button>
          <div id="profileDropdown" style="display:none;position:absolute;top:calc(100% + 10px);right:0;background:white;border-radius:16px;box-shadow:0 18px 45px rgba(201,79,134,.2);border:1px solid rgba(242,138,178,.2);min-width:180px;overflow:hidden;z-index:100;">
            <a href="student_profile.php" style="display:flex;align-items:center;gap:10px;padding:14px 16px;font-size:14px;font-weight:700;color:#342635;" onmouseover="this.style.background='#FFF1F6'" onmouseout="this.style.background='white'"><i class="bi bi-person-circle" style="color:#E75A9B;"></i> My Profile</a>
            <a href="student_favourites.php" style="display:flex;align-items:center;gap:10px;padding:14px 16px;font-size:14px;font-weight:700;color:#342635;" onmouseover="this.style.background='#FFF1F6'" onmouseout="this.style.background='white'"><i class="bi bi-heart" style="color:#E75A9B;"></i> My Favourites</a>
            <hr style="margin:4px 0;border-color:rgba(242,138,178,.2);">
            <a href="logout.php" style="display:flex;align-items:center;gap:10px;padding:14px 16px;font-size:14px;font-weight:700;color:#dc2626;" onmouseover="this.style.background='#FFF1F6'" onmouseout="this.style.background='white'"><i class="bi bi-box-arrow-right"></i> Logout</a>
          </div>
        </div>
      </div>
    </nav>
  </div>
</header>
  <div class="nav-overlay" id="navOverlay"></div>

<div class="container">
  <a href="booking_status.php" class="back-link"><i class="bi bi-arrow-left"></i><span>Back to My Bookings</span></a>

  <!-- PAGE HEADING -->
  <div style="text-align:center;margin-bottom:28px;">
    <div style="font-size:40px;margin-bottom:8px;">⭐</div>
    <h1 style="margin:0 0 6px;font-size:26px;letter-spacing:-.5px;">Rate Your Session</h1>
    <p style="margin:0;color:var(--muted);font-size:14px;">Your feedback helps tutors improve and helps other students choose.</p>
  </div>

  <!-- TUTOR + SESSION INFO -->
  <div class="card">
    <div class="tutor-row">
      <img src="<?= e($tutorPic) ?>" alt="<?= e($b['tutor_name']) ?>">
      <div>
        <h3><?= e($b['tutor_name']) ?></h3>
        <p><?= e($b['experience']) ?> yrs exp · RM <?= e($b['rate']) ?>/hr · <?= e($b['tutor_languages']) ?></p>
      </div>
    </div>
    <div class="session-pills">
      <span class="pill"><i class="bi bi-translate"></i><?= e($b['language']) ?></span>
      <span class="pill"><i class="bi bi-calendar3"></i><?= date('D, d M Y', strtotime($b['booking_date'])) ?></span>
      <span class="pill"><i class="bi bi-clock"></i><?= date('g:i A', strtotime($b['booking_time'])) ?></span>
      <span class="pill"><?= $b['learning_mode'] === 'online' ? '💻 Online' : '🤝 Face to Face' ?></span>
    </div>
  </div>

  <!-- RATING FORM / ALREADY RATED -->
  <div class="card">
    <?php if (!empty($b['rated'])): ?>
      <!-- Already rated -->
      <div class="card-title"><i class="bi bi-star-fill"></i> Your Rating</div>
      <div class="rated-box">
  <div style="display:flex;align-items:flex-start;gap:12px;">
    <img src="<?= $b['my_anonymous'] ? e($assetBase).'/profile-student.png' : e($profilePic) ?>" 
         style="width:44px;height:44px;object-fit:cover;border-radius:50%;flex:0 0 auto;border:2px solid rgba(242,138,178,.3);">
    <div style="flex:1;">
      <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;">
        <strong style="font-size:14px;color:#342635;">
          <?= $b['my_anonymous'] ? 'Anonymous Student' : e($displayName) ?>
        </strong>
        <?php if ($b['my_anonymous']): ?>
          <span style="padding:2px 10px;border-radius:999px;font-size:11px;font-weight:900;background:rgba(255,241,246,.8);color:var(--pink-dark);border:1px solid rgba(242,138,178,.2);">
            <i class="bi bi-incognito"></i> Anonymous
          </span>
        <?php endif; ?>
      </div>
      <div class="star-display" style="margin-bottom:8px;">
        <?php for($i=1;$i<=5;$i++): ?>
          <i class="bi bi-star<?= $i <= $b['my_rating'] ? '-fill' : '' ?>"></i>
        <?php endfor; ?>
        <span style="margin-left:6px;font-size:13px;font-weight:900;color:#342635;"><?= $b['my_rating'] ?>/5</span>
      </div>
      <?php if ($b['my_comment']): ?>
        <p style="margin:0;font-size:13px;color:var(--muted);font-style:italic;line-height:1.5;">"<?= e($b['my_comment']) ?>"</p>
      <?php endif; ?>
      <p style="margin:8px 0 0;font-size:11px;color:var(--muted);"><?= date('d M Y', strtotime($b['booking_date'])) ?> · <?= e($b['language']) ?></p>
    </div>
  </div>
</div>
    <?php else: ?>
      <div class="card-title"><i class="bi bi-star"></i> Leave a Review</div>
      <form method="POST" id="ratingForm">

        <!-- Stars -->
        <div class="star-section">
          <label>How would you rate this session?</label>
          <div class="star-row" id="starRow">
            <?php for($i=1;$i<=5;$i++): ?>
              <button type="button" class="star-btn" data-val="<?= $i ?>" onclick="setRating(<?= $i ?>)">⭐</button>
            <?php endfor; ?>
          </div>
          <div class="star-label" id="starLabel">Tap a star to rate</div>
          <input type="hidden" name="rating" id="ratingInput" value="0">
        </div>

        <!-- Comment -->
        <div class="comment-section">
          <label><i class="bi bi-chat-left-text" style="color:var(--hot-pink);margin-right:5px;"></i> Your Review <span style="font-weight:400;color:var(--muted);">(optional)</span></label>
          <textarea class="form-control" name="comment" id="commentInput"
            placeholder="Share what you liked, what helped you learn, or any suggestions for the tutor..."></textarea>
        </div>

        <!-- Anonymous toggle -->
        <div class="anon-toggle" id="anonToggle" onclick="toggleAnon()">
  <input type="checkbox" name="is_anonymous" id="anonCheckbox" hidden>
          <div class="anon-icon"><i class="bi bi-incognito"></i></div>
          <div class="anon-text">
            <strong>Post anonymously</strong>
            <span>Your name will be hidden from the tutor and other students.</span>
          </div>
          <div class="anon-check" id="anonCheck"><i class="bi bi-check" style="font-size:13px;display:none;"></i></div>
            </div>

        <!-- Preview -->
        <div class="preview-box" id="previewBox">
          <i class="bi bi-eye"></i>
          <span id="previewText">Your Review Name will appear as <strong><?= e($displayName) ?></strong></span>
        </div>

        <button type="submit" name="submit_rating" class="btn-submit" id="submitBtn" disabled>
          <i class="bi bi-star-fill"></i> Submit Rating
        </button><br>
        <?php if (isset($_GET['from_chain'])): ?>
<div style="padding:12px 16px;border-radius:14px;background:rgba(221,211,255,.3);border:1px solid rgba(167,123,232,.2);font-size:13px;color:#5A3D7A;font-weight:700;margin-bottom:16px;text-align:center;">
  <i class="bi bi-arrow-right-circle"></i> Previous rating submitted! Rate this session next.
</div>
<?php endif; ?>
      </form>
    <?php endif; ?>
  </div>
</div>

<div class="toast" id="toast"></div>

<script>
  const starLabels = ['','😕 Poor','😐 Fair','🙂 Good','😊 Great','🤩 Excellent!'];
  const displayName = <?= json_encode($displayName) ?>;
  let isAnon = false;

  function setRating(val) {
    document.getElementById('ratingInput').value = val;
    document.querySelectorAll('.star-btn').forEach((btn, i) => {
      btn.classList.toggle('active', i < val);
    });
    document.getElementById('starLabel').textContent = starLabels[val] || '';
    document.getElementById('starLabel').style.color = '#E75A9B';
    checkSubmit();
  }

  function toggleAnon() {
    const toggle = document.getElementById('anonToggle');
    const cb = document.getElementById('anonCheckbox');
    const check = document.getElementById('anonCheck');
    const icon = check.querySelector('i');

    cb.checked = !cb.checked;

    toggle.classList.toggle('active', cb.checked);
    icon.style.display = cb.checked ? '' : 'none';

    isAnon = cb.checked;

    updatePreview();
}

  function updatePreview() {
    const text = document.getElementById('previewText');
    if (isAnon) {
      text.innerHTML = 'Your review will appear as <strong>Anonymous</strong>';
    } else {
      text.innerHTML = 'Your review will appear as <strong>' + displayName + '</strong>';
    }
  }

  function checkSubmit() {
    const rating = parseInt(document.getElementById('ratingInput').value);
    document.getElementById('submitBtn').disabled = rating < 1;
  }

  let toastTimer;
  function showToast(msg) {
    const t = document.getElementById('toast');
    t.textContent = msg; t.classList.add('show');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => t.classList.remove('show'), 2500);
  }
  function toggleDropdown() {
    const d = document.getElementById('profileDropdown');
    d.style.display = d.style.display === 'none' ? 'block' : 'none';
  }
  document.addEventListener('click', function(e) {
    const btn = document.getElementById('profileBtn');
    const dd  = document.getElementById('profileDropdown');
    if (btn && dd && !btn.contains(e.target) && !dd.contains(e.target)) dd.style.display = 'none';
  });

document.getElementById('ratingForm')?.addEventListener('submit', function(e) {
    const rating = parseInt(document.getElementById('ratingInput').value);
    if (rating < 1) {
        e.preventDefault();
        showToast('Please select a star rating first!');
        return;
    }
    const btn = document.getElementById('submitBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Submitting...';
});
</script>

<script src="../js/nav.js"></script>
<script>
history.pushState(null, null, location.href);
window.addEventListener('popstate', function() {
    window.location.href = 'login.php';
});
</script>

</body>
</html>