<?php
session_start();
include 'config.php';
$assetBase = '../assets/img';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$userID = $_SESSION['user_id'];

$stmt = $conn->prepare("SELECT * FROM users WHERE id = ? AND role = 'student'");
$stmt->bind_param("i", $userID);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

if (!$user) { header("Location: login.php"); exit(); }

$displayName = $user['fullname'];
$profilePic  = !empty($user['profile_pic'])
    ? '../uploads/profiles/' . $user['profile_pic']
    : $assetBase . '/profile-student.png';

function e($v){ return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }

// Parse IDs from URL (max 3)
$rawIds = isset($_GET['ids']) ? $_GET['ids'] : '';
$ids    = array_slice(
    array_filter(array_map('intval', explode(',', $rawIds))),
    0, 3
);

if (count($ids) < 2) {
    header("Location: find_language.php");
    exit();
}

// Fetch tutors
$tutors = [];
foreach ($ids as $tid) {
    $stmt = $conn->prepare("
        SELECT u.id, u.fullname, u.profile_pic, tp.rate, tp.bio, tp.experience,
               GROUP_CONCAT(DISTINCT tl.language ORDER BY tl.language SEPARATOR ', ') as languages,
               GROUP_CONCAT(DISTINCT ttm.mode SEPARATOR ', ') as teaching_modes,
               ul.location,
               ROUND(AVG(r.rating),1) as rating,
               COUNT(DISTINCT r.id) as review_count
        FROM users u
        JOIN tutor_profiles tp ON u.id = tp.user_id
        LEFT JOIN tutor_languages tl ON u.id = tl.user_id
        LEFT JOIN tutor_teaching_modes ttm ON u.id = ttm.user_id
        LEFT JOIN user_locations ul ON u.id = ul.user_id
        LEFT JOIN ratings r ON u.id = r.tutor_id
        WHERE u.id = ? AND u.role = 'tutor' AND u.status = 'approved'
        GROUP BY u.id
    ");
    $stmt->bind_param("i", $tid);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if ($row) $tutors[] = $row;
}

if (count($tutors) < 2) {
    header("Location: find_language.php");
    exit();
}

// Go back URL — keep the language filter if referrer had one
$backUrl = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : 'search_tutors.php';

// Determine which tutor has the best value for each metric (for highlighting)
$lowestRate   = min(array_column($tutors,'rate'));
$highestRating= max(array_column($tutors,'rating'));
$highestExp   = max(array_column($tutors,'experience'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Compare Tutors · Kyoshi</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
  <style>
    :root{
      --cream:#FFF1F6; --paper:rgba(255,255,255,.88); --ink:#342635; --muted:#7B6178;
      --pink:#F28AB2; --pink-dark:#C94F86; --hot-pink:#E75A9B;
      --lavender:#EAD7FF; --peach:#FFD0DD; --mint:#DDF4E3; --sky:#D8ECFF;
      --shadow:0 18px 45px rgba(201,79,134,.16); --shadow-soft:0 10px 26px rgba(201,79,134,.10);
      --radius-xl:32px; --radius-lg:24px;
      --best:#DDF4E3; --best-text:#2D6A3F;
    }
    *{box-sizing:border-box} html{scroll-behavior:smooth}
    body{
      margin:0; min-height:100vh; font-family:"Segoe UI",Arial,sans-serif; color:var(--ink);
      background:linear-gradient(120deg,rgba(255,241,246,.74),rgba(255,203,220,.30)),
        url("<?= e($assetBase) ?>/background3.jpg") center/cover fixed no-repeat;
    }
    body::before{content:"";position:fixed;inset:0;pointer-events:none;z-index:-1;
      background:radial-gradient(circle at 7% 10%,rgba(231,90,155,.32),transparent 24%),
        radial-gradient(circle at 90% 8%,rgba(255,195,216,.42),transparent 26%),
        radial-gradient(circle at 55% 95%,rgba(234,215,255,.30),transparent 28%)}
    a{text-decoration:none;color:inherit} button,input{font-family:inherit}
    .container{width:min(1440px, calc(100% - 40px)); margin:0 auto}

    .topbar{
      position:sticky; top:0; z-index:50;
      background:rgba(255,241,246,.86);
      backdrop-filter:blur(20px);
      border-bottom:1px solid rgba(231,90,155,.18);
      box-shadow:0 10px 30px rgba(201,79,134,.10);
    }
    .nav{
      min-height:78px;
      display:grid;
      grid-template-columns:160px 1fr 320px;
      gap:16px;
      align-items:center;
    }
    .brand{display:flex; align-items:center; gap:10px; min-width:0}
    .brand img{width:44px; height:44px; object-fit:contain; border-radius:14px}
    .brand strong{display:block; font-size:18px; line-height:1.05}
    .brand span{display:block; margin-top:3px; font-size:11px; color:var(--muted); white-space:nowrap}

    .nav-links{
      display:flex; align-items:center; justify-content:center; gap:6px;
      overflow:auto; scrollbar-width:none;
      
    }
    .nav-links::-webkit-scrollbar{display:none}
    .nav-links a{flex:0 0 auto; padding:9px 12px; border-radius:999px; font-size:13px; font-weight:900; color:#6D4964; white-space:nowrap; transition:.18s ease}
    .nav-links a.active,.nav-links a:hover{background:linear-gradient(135deg, var(--hot-pink), var(--pink)); color:#fff; box-shadow:0 8px 18px rgba(231,90,155,.28)}

    .nav-actions{display:flex; align-items:center; justify-content:flex-end; gap:10px; min-width:0}

    .icon-btn,.profile{border:1px solid rgba(46,42,59,.08); background:rgba(255,255,255,.88); box-shadow:var(--shadow-soft); cursor:pointer}
    .icon-btn{width:44px; height:44px; border-radius:16px; color:#7A4A68; position:relative; flex:0 0 auto}
    .dot{position:absolute; top:10px; right:10px; width:8px; height:8px; border-radius:50%; background:#E17C91}
    .profile{display:flex; align-items:center; gap:9px; border-radius:999px; padding:6px 12px 6px 6px; font-weight:900; color:#7A3D65; flex:0 0 auto; max-width:150px}
    .profile img{width:34px; height:34px; object-fit:cover; border-radius:50%}
    .profile span{max-width:86px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap}

    /* PAGE */
    .page-header{padding:28px 0 20px;display:flex;justify-content:space-between;align-items:flex-end;gap:16px;flex-wrap:wrap}
    .page-header h1{margin:0;font-size:clamp(26px,4vw,38px);letter-spacing:-1px}
    .page-header p{margin:6px 0 0;color:var(--muted);font-size:14px}
    .back-link{display:inline-flex;align-items:center;gap:6px;color:var(--pink-dark);font-weight:900;font-size:13px;padding:9px 16px;border-radius:999px;background:rgba(255,255,255,.78);border:1px solid rgba(46,42,59,.08);transition:.18s ease;white-space:nowrap}
    .back-link:hover{transform:translateY(-1px)}

    /* LEGEND */
    .legend{display:flex;align-items:center;gap:10px;margin-bottom:20px;flex-wrap:wrap}
    .legend-item{display:flex;align-items:center;gap:6px;font-size:12px;font-weight:700;color:var(--muted)}
    .legend-dot{width:10px;height:10px;border-radius:50%}

    /* COMPARE TABLE */
    .compare-wrap{background:var(--paper);border:1px solid rgba(255,255,255,.55);box-shadow:var(--shadow);border-radius:var(--radius-xl);overflow:hidden;margin-bottom:48px}

    /* HEADER ROW — tutor profiles */
    .compare-header{display:grid;grid-template-columns:200px repeat(<?= count($tutors) ?>,1fr);border-bottom:1px solid rgba(46,42,59,.08)}
    .compare-header-label{padding:24px 20px;font-weight:900;font-size:13px;color:var(--muted);display:flex;align-items:flex-end}
    .compare-header-tutor{padding:24px 20px;text-align:center;border-left:1px solid rgba(46,42,59,.06);position:relative}
    .compare-header-tutor img{width:80px;height:80px;object-fit:cover;border-radius:22px;margin-bottom:12px;background:#eee}
    .compare-header-tutor h3{margin:0;font-size:17px;letter-spacing:-.3px}
    .compare-header-tutor .sub{color:var(--muted);font-size:13px;margin-top:4px}
    .compare-header-tutor .btn-book{display:inline-block;margin-top:14px;padding:10px 20px;border-radius:999px;background:linear-gradient(135deg,var(--hot-pink),var(--pink));color:#fff;font-size:13px;font-weight:900;border:none;cursor:pointer;transition:.18s ease}
    .compare-header-tutor .btn-book:hover{transform:translateY(-1px);box-shadow:0 8px 20px rgba(231,90,155,.28)}

    /* SECTION ROWS */
    .compare-section-title{grid-column:1/-1;padding:14px 20px;background:rgba(242,138,178,.08);font-size:12px;font-weight:900;color:var(--pink-dark);letter-spacing:.5px;text-transform:uppercase;border-top:1px solid rgba(46,42,59,.06)}
    .compare-row{display:grid;grid-template-columns:200px repeat(<?= count($tutors) ?>,1fr);border-top:1px solid rgba(46,42,59,.06)}
    .compare-row:hover{background:rgba(255,241,246,.5)}
    .compare-cell-label{padding:16px 20px;font-size:13px;font-weight:900;color:#6D5A6A;display:flex;align-items:center;gap:8px}
    .compare-cell{padding:16px 20px;border-left:1px solid rgba(46,42,59,.06);font-size:14px;text-align:center;display:flex;align-items:center;justify-content:center;flex-direction:column;gap:4px}
    .compare-cell.best{background:var(--best)}
    .best-badge{display:inline-flex;align-items:center;gap:4px;padding:3px 8px;border-radius:999px;background:rgba(45,106,63,.15);color:var(--best-text);font-size:11px;font-weight:900}
    .compare-cell strong{font-size:16px;font-weight:900}
    .compare-cell .na{color:#bbb;font-style:italic;font-size:13px}
    .stars{display:flex;gap:2px;justify-content:center}
    .stars i{font-size:14px}
    .mode-tag{display:inline-flex;padding:4px 10px;border-radius:999px;font-size:12px;font-weight:700;background:rgba(216,236,255,.8);color:#2D5F8A;margin:2px}
    .lang-tag-sm{display:inline-flex;padding:4px 10px;border-radius:999px;font-size:12px;font-weight:700;background:rgba(242,138,178,.18);color:var(--pink-dark);margin:2px}

    .toast{position:fixed;left:50%;bottom:28px;transform:translate(-50%,18px);opacity:0;pointer-events:none;z-index:99;background:#8E3F70;color:#fff;border-radius:999px;padding:12px 18px;font-size:13px;font-weight:900;transition:.2s ease}
    .toast.show{opacity:1;transform:translate(-50%,0)}

    .top-header {
    display: flex;
    align-items: center;
    justify-content: space-between;

    padding: 20px 30px;   
    margin-bottom: 20px;
    }


    .header-left,
    .header-right {
    width: 120px;
    }

    /* CENTER CONTENT */
    .header-center {
    text-align: center;
    flex: 1;
    }

    .header-center h1 {
    margin: 0;
    font-size: 26px;
    font-weight: 700;
    }

    .header-center p {
    margin: 4px 0 0;
    font-size: 14px;
    color: #777;
    }

    /* BACK BUTTON */
    .back-link {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    text-decoration: none;
    font-weight: 600;
    color: #333;
    }

    @media(max-width:768px){
      .compare-header,.compare-row{grid-template-columns:120px repeat(<?= count($tutors) ?>,1fr)}
      .compare-cell-label,.compare-header-label{font-size:11px;padding:12px 10px}
      .compare-cell,.compare-header-tutor{padding:12px 8px;font-size:13px}
      .nav{grid-template-columns:1fr auto}
      .nav-links{display:none}
    }
  </style>
</head>
<body>

<header class="topbar">
  <div class="container">
    <nav class="nav">
        <a href="student_dashboard.php" class="brand">
          <img src="<?= e($assetBase) ?>/logo.png" alt="Kyoshi logo">
          <div>
            <strong>Kyoshi</strong>
            <span>Student Learning Space</span>
          </div>
        </a>

        <div class="nav-links">
                <a href="student_dashboard.php">Home</a>
                <a href="find_language.php" class="active">Find Language</a>
                <a href="booking_status.php">My Bookings</a>
                <a href="my_payments.php">My Payments</a>
                <a href="my_materials.php">My Materials</a>
                <a href="my_assignments.php">My Assignments</a>
            </div>
        <div class="nav-actions" style="display:flex;align-items:center;justify-content:flex-end;gap:10px;margin-left:auto;">
          <div style="position:relative;">
            <button class="profile" onclick="toggleDropdown()" id="profileBtn">
              <img src="<?= e($profilePic) ?>" alt="Student profile">
              <span><?= e($displayName) ?></span>
              <i class="bi bi-chevron-down" style="font-size:11px; margin-left:4px;"></i>
            </button>
            <div id="profileDropdown" style="display:none;position:absolute;top:calc(100% + 10px);right:0;background:white;border-radius:16px;box-shadow:0 18px 45px rgba(201,79,134,.2);border:1px solid rgba(242,138,178,.2);min-width:180px;overflow:hidden;z-index:100;">
              <a href="student_profile.php" style="display:flex;align-items:center;gap:10px;padding:14px 16px;font-size:14px;font-weight:700;color:#342635;transition:.15s ease;" onmouseover="this.style.background='#FFF1F6'" onmouseout="this.style.background='white'">
                <i class="bi bi-person-circle" style="color:#E75A9B;"></i> My Profile
              </a>
              <a href="my_progress.php" style="display:flex;align-items:center;gap:10px;padding:14px 16px;font-size:14px;font-weight:700;color:#342635;transition:.15s ease;" onmouseover="this.style.background='#FFF1F6'" onmouseout="this.style.background='white'">
  <i class="bi bi-bar-chart-steps" style="color:#E75A9B;"></i> My Progress
</a>
              <a href="student_favourites.php" style="display:flex;align-items:center;gap:10px;padding:14px 16px;font-size:14px;font-weight:700;color:#342635;transition:.15s ease;" onmouseover="this.style.background='#FFF1F6'" onmouseout="this.style.background='white'">
                <i class="bi bi-heart" style="color:#E75A9B;"></i> My Favourites
              </a>
              <hr style="margin:4px 0;border-color:rgba(242,138,178,.2);">
              <a href="logout.php" style="display:flex;align-items:center;gap:10px;padding:14px 16px;font-size:14px;font-weight:700;color:#dc2626;transition:.15s ease;" onmouseover="this.style.background='#FFF1F6'" onmouseout="this.style.background='white'">
                <i class="bi bi-box-arrow-right"></i> Logout
              </a>
            </div>
          </div>
        </div>
      </nav>
  </div>
</header>

<main class="container">
 <div class="top-header">

  <!-- BACK BUTTON (LEFT) -->
  <div class="header-left">
    <a href="search_tutors.php<?= isset($_GET['lang']) ? '?lang='.urlencode($_GET['lang']) : '' ?>" class="back-link">
      <i class="bi bi-arrow-left"></i>
      <span>Back</span>
    </a>
  </div>

  <!-- TITLE (CENTER) -->
  <div class="header-center">
    <h1>
      Tutor Comparison
    </h1>

    <p>
      Comparing <?= count($tutors) ?> tutor<?= count($tutors) !== 1 ? 's' : '' ?> side by side
    </p>
  </div>

  <!-- EMPTY RIGHT (FOR BALANCE) -->
  <div class="header-right"></div>

</div>

  <div class="compare-wrap">

    <!-- TUTOR HEADER PROFILES -->
    <div class="compare-header">
      <div class="compare-header-label">Tutor</div>
      <?php foreach ($tutors as $t):
        $pic = !empty($t['profile_pic'])
            ? '../uploads/profiles/' . $t['profile_pic']
            : $assetBase . '/profile-tutor.png';
      ?>
        <div class="compare-header-tutor">
          <img src="<?= e($pic) ?>" alt="<?= e($t['fullname']) ?>">
          <h3><?= e($t['fullname']) ?></h3>
          <div class="sub"><?= $t['experience'] ? e($t['experience']).' yrs experience' : 'Experience N/A' ?></div>
          <a href="tutor_profile.php?id=<?= $t['id'] ?>" class="btn-book">View Profile</a>
        </div>
      <?php endforeach; ?>
    </div>

    <!-- SECTION: PRICING -->
    <div style="display:grid;grid-template-columns:200px repeat(<?= count($tutors) ?>,1fr);">
      <div class="compare-section-title"><i class="bi bi-cash-coin" style="margin-right:6px;"></i>Pricing</div>
    </div>
    <div class="compare-row">
      <div class="compare-cell-label"><i class="bi bi-tag"></i> Rate per Hour</div>
      <?php foreach ($tutors as $t): ?>
        <div class="compare-cell <?= $t['rate']==$lowestRate ? 'best' : '' ?>">
          <strong>RM <?= e($t['rate']) ?></strong>
          <?php if ($t['rate']==$lowestRate): ?><span class="best-badge"><i class="bi bi-check2"></i> Best price</span><?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>

    <!-- SECTION: RATING & REVIEWS -->
    <div style="display:grid;grid-template-columns:200px repeat(<?= count($tutors) ?>,1fr);">
      <div class="compare-section-title"><i class="bi bi-star-fill" style="margin-right:6px;"></i>Rating & Reviews</div>
    </div>
    <div class="compare-row">
      <div class="compare-cell-label"><i class="bi bi-star"></i> Rating</div>
      <?php foreach ($tutors as $t): ?>
        <div class="compare-cell <?= $t['rating']==$highestRating && $t['rating']>0 ? 'best' : '' ?>">
          <?php if ($t['rating']): ?>
            <div class="stars">
              <?php for($i=1;$i<=5;$i++): ?>
                <i class="bi bi-star<?= $i<=round($t['rating'])?'-fill':'' ?>" style="color:<?= $i<=round($t['rating'])?'#FFB800':'#ddd' ?>;"></i>
              <?php endfor; ?>
            </div>
            <strong><?= e($t['rating']) ?> / 5</strong>
            <?php if ($t['rating']==$highestRating): ?><span class="best-badge"><i class="bi bi-trophy"></i> Top rated</span><?php endif; ?>
          <?php else: ?>
            <span class="na">No ratings yet</span>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
    <div class="compare-row">
      <div class="compare-cell-label"><i class="bi bi-chat-square-text"></i> Reviews</div>
      <?php foreach ($tutors as $t): ?>
        <div class="compare-cell">
          <?php if ($t['review_count']): ?>
            <strong><?= e($t['review_count']) ?></strong>
            <span style="color:var(--muted);font-size:12px;">review<?= $t['review_count']!=1?'s':'' ?></span>
          <?php else: ?>
            <span class="na">No reviews</span>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>

    <!-- SECTION: LANGUAGES -->
    <div style="display:grid;grid-template-columns:200px repeat(<?= count($tutors) ?>,1fr);">
      <div class="compare-section-title"><i class="bi bi-globe2" style="margin-right:6px;"></i>Languages</div>
    </div>
    <div class="compare-row">
      <div class="compare-cell-label"><i class="bi bi-translate"></i> Teaches</div>
      <?php foreach ($tutors as $t): ?>
        <div class="compare-cell" style="flex-wrap:wrap;">
          <?php if ($t['languages']): ?>
            <?php foreach (array_filter(array_map('trim',explode(',',$t['languages']))) as $lang): ?>
              <span class="lang-tag-sm"><?= e($lang) ?></span>
            <?php endforeach; ?>
          <?php else: ?>
            <span class="na">N/A</span>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>

    <!-- SECTION: TEACHING MODE -->
    <div style="display:grid;grid-template-columns:200px repeat(<?= count($tutors) ?>,1fr);">
      <div class="compare-section-title"><i class="bi bi-laptop" style="margin-right:6px;"></i>Teaching Mode</div>
    </div>
    <div class="compare-row">
      <div class="compare-cell-label"><i class="bi bi-camera-video"></i> Mode</div>
      <?php foreach ($tutors as $t): ?>
        <div class="compare-cell" style="flex-wrap:wrap;">
          <?php if ($t['teaching_modes']): ?>
            <?php foreach (array_filter(array_map('trim',explode(',',$t['teaching_modes']))) as $mode): ?>
              <span class="mode-tag"><?= e(str_replace('_',' ',ucwords($mode,'_'))) ?></span>
            <?php endforeach; ?>
          <?php else: ?>
            <span class="na">N/A</span>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>

    <!-- SECTION: EXPERIENCE & BIO -->
    <div style="display:grid;grid-template-columns:200px repeat(<?= count($tutors) ?>,1fr);">
      <div class="compare-section-title"><i class="bi bi-person-badge" style="margin-right:6px;"></i>Experience & Background</div>
    </div>
    <div class="compare-row">
      <div class="compare-cell-label"><i class="bi bi-briefcase"></i> Experience</div>
      <?php foreach ($tutors as $t): ?>
        <div class="compare-cell <?= $t['experience']==$highestExp && $t['experience']>0 ? 'best' : '' ?>">
          <?php if ($t['experience']): ?>
            <strong><?= e($t['experience']) ?> yrs</strong>
            <?php if ($t['experience']==$highestExp): ?><span class="best-badge"><i class="bi bi-award"></i> Most experienced</span><?php endif; ?>
          <?php else: ?>
            <span class="na">N/A</span>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
    <div class="compare-row">
      <div class="compare-cell-label"><i class="bi bi-geo-alt"></i> Location</div>
      <?php foreach ($tutors as $t): ?>
        <div class="compare-cell">
          <?= $t['location'] ? e($t['location']) : '<span class="na">N/A</span>' ?>
        </div>
      <?php endforeach; ?>
    </div>
    <div class="compare-row" style="align-items:start;">
      <div class="compare-cell-label" style="padding-top:20px;"><i class="bi bi-card-text"></i> About</div>
      <?php foreach ($tutors as $t): ?>
        <div class="compare-cell" style="text-align:left;align-items:flex-start;padding:16px 20px;color:var(--muted);font-size:13px;line-height:1.55;">
          <?= $t['bio'] ? e(mb_strimwidth($t['bio'],0,160,'...')) : '<span class="na">No bio provided</span>' ?>
        </div>
      <?php endforeach; ?>
    </div>

    <!-- SECTION: BOOK -->
    <div style="display:grid;grid-template-columns:200px repeat(<?= count($tutors) ?>,1fr);">
      <div class="compare-section-title"><i class="bi bi-calendar-check" style="margin-right:6px;"></i>Book a Session</div>
    </div>
    <div class="compare-row">
      <div class="compare-cell-label"><i class="bi bi-arrow-right-circle"></i> Action</div>
      <?php foreach ($tutors as $t): ?>
        <div class="compare-cell">
          <a href="tutor_profile.php?id=<?= $t['id'] ?>"
            style="padding:11px 22px;border-radius:999px;background:linear-gradient(135deg,var(--hot-pink),var(--pink));color:#fff;font-size:13px;font-weight:900;border:none;cursor:pointer;transition:.18s ease;display:inline-block;box-shadow:0 8px 20px rgba(231,90,155,.22);">
            Book <?= e(explode(' ',$t['fullname'])[0]) ?>
          </a>
        </div>
      <?php endforeach; ?>
    </div>

  </div><!-- /compare-wrap -->
</main>

<div class="toast" id="toast"></div>

<script>
  function showToast(msg){
    const t=document.getElementById('toast');
    t.textContent=msg; t.classList.add('show');
    setTimeout(()=>t.classList.remove('show'),1800);
  }
  function toggleDropdown(){
    const d=document.getElementById('profileDropdown');
    d.style.display=d.style.display==='none'?'block':'none';
  }
  document.addEventListener('click',function(e){
    const btn=document.getElementById('profileBtn');
    const dd=document.getElementById('profileDropdown');
    if(btn&&dd&&!btn.contains(e.target)&&!dd.contains(e.target)) dd.style.display='none';
  });
</script>
</body>
</html>