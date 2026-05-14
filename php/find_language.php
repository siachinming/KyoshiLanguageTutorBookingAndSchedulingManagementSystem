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

if (!$user) {
    header("Location: login.php");
    exit();
}

$displayName = $user['fullname'];
$profilePic  = !empty($user['profile_pic'])
    ? '../uploads/profiles/' . $user['profile_pic']
    : $assetBase . '/profile-student.png';

function e($value) {
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

$languageQuery = $conn->query("
    SELECT 
        tl.language,
        COUNT(DISTINCT tl.user_id) AS tutor_count,
        MIN(tp.rate) AS min_price
    FROM tutor_languages tl
    JOIN users u ON tl.user_id = u.id
    JOIN tutor_profiles tp ON tp.user_id = u.id
    WHERE u.role = 'tutor' 
      AND u.status = 'approved'
    GROUP BY tl.language
");

$langData = [];
while ($row = $languageQuery->fetch_assoc()) {
    $langData[$row['language']] = $row;
}

$languages = [
    ['img'=>'japanese.webp','language'=>'Japanese','level'=>'Beginner → Advanced','desc'=>'Daily conversation, basic phrases, kanji, and speaking confidence.','tag'=>'Most booked','color'=>'#FFD0DD'],
    ['img'=>'english.webp','language'=>'English','level'=>'All levels','desc'=>'Presentation practice, grammar, confidence building.','tag'=>'Recommended','color'=>'#D8ECFF'],
    ['img'=>'mandarin.png','language'=>'Mandarin','level'=>'Beginner → HSK','desc'=>'Tone practice and sentence patterns.','tag'=>'Beginner friendly','color'=>'#DDF4E3'],
    ['img'=>'korean.jpg','language'=>'Korean','level'=>'Starter → TOPIK','desc'=>'Hangul, pronunciation, K-drama phrases.','tag'=>'Trending','color'=>'#EAD7FF'],
    ['img'=>'malay.jpg','language'=>'Malay','level'=>'All levels','desc'=>'Formal and informal Malay communication.','tag'=>'Local favourite','color'=>'#FFE4CC'],
];

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Find Language · Kyoshi</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
  <style>
    :root{
      --cream:#FFF1F6;
      --paper:rgba(255,255,255,.88);
      --ink:#342635;
      --muted:#7B6178;
      --pink:#F28AB2;
      --pink-dark:#C94F86;
      --hot-pink:#E75A9B;
      --purple:#A77BE8;
      --lavender:#EAD7FF;
      --peach:#FFD0DD;
      --mint:#DDF4E3;
      --sky:#D8ECFF;
      --shadow:0 18px 45px rgba(201,79,134,.16);
      --shadow-soft:0 10px 26px rgba(201,79,134,.10);
      --radius-xl:32px;
      --radius-lg:24px;
    }
    *{box-sizing:border-box}
    html{scroll-behavior:smooth}
    body{
      margin:0; min-height:100vh;
      font-family:"Segoe UI",Arial,sans-serif;
      color:var(--ink);
      background:
        linear-gradient(120deg,rgba(255,241,246,.74),rgba(255,203,220,.30)),
        url("<?= e($assetBase) ?>/background3.jpg") center/cover fixed no-repeat;
    }
    body::before{
      content:""; position:fixed; inset:0; pointer-events:none; z-index:-1;
      background:
        radial-gradient(circle at 7% 10%,rgba(231,90,155,.32),transparent 24%),
        radial-gradient(circle at 90% 8%,rgba(255,195,216,.42),transparent 26%),
        radial-gradient(circle at 55% 95%,rgba(234,215,255,.30),transparent 28%);
    }
    a{text-decoration:none;color:inherit}
    button,input{font-family:inherit}
    .container{width:min(1440px,calc(100% - 40px));margin:0 auto}

    /* ── TOPBAR ── */
    .topbar{position:sticky;top:0;z-index:50;background:rgba(255,241,246,.86);backdrop-filter:blur(20px);border-bottom:1px solid rgba(231,90,155,.18);box-shadow:0 10px 30px rgba(201,79,134,.10)}
    .nav{min-height:78px;display:grid;grid-template-columns:190px minmax(0,1fr) 360px;gap:16px;align-items:center}
    .brand{display:flex;align-items:center;gap:10px;min-width:0}
    .brand img{width:44px;height:44px;object-fit:contain;border-radius:14px}
    .brand strong{display:block;font-size:18px;line-height:1.05}
    .brand span{display:block;margin-top:3px;font-size:11px;color:var(--muted);white-space:nowrap}
    .nav-links{display:flex;align-items:center;justify-content:center;gap:6px;background:rgba(255,255,255,.58);border:1px solid rgba(242,138,178,.18);border-radius:999px;padding:7px;overflow:auto;scrollbar-width:none;box-shadow:inset 0 1px 0 rgba(255,255,255,.70)}
    .nav-links::-webkit-scrollbar{display:none}
    .nav-links a{flex:0 0 auto;padding:9px 12px;border-radius:999px;font-size:13px;font-weight:900;color:#6D4964;white-space:nowrap;transition:.18s ease}
    .nav-links a.active,.nav-links a:hover{background:linear-gradient(135deg,var(--hot-pink),var(--pink));color:#fff;box-shadow:0 8px 18px rgba(231,90,155,.28)}
    .nav-actions{display:flex;align-items:center;justify-content:flex-end;gap:10px;min-width:0}
    .icon-btn,.profile{border:1px solid rgba(46,42,59,.08);background:rgba(255,255,255,.88);box-shadow:var(--shadow-soft);cursor:pointer}
    .icon-btn{width:44px;height:44px;border-radius:16px;color:#7A4A68;position:relative;flex:0 0 auto;display:grid;place-items:center}
    .profile{display:flex;align-items:center;gap:9px;border-radius:999px;padding:6px 12px 6px 6px;font-weight:900;color:#7A3D65;flex:0 0 auto;max-width:150px}
    .profile img{width:34px;height:34px;object-fit:cover;border-radius:50%}
    .profile span{max-width:86px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
    .dot{position:absolute;top:10px;right:10px;width:8px;height:8px;border-radius:50%;background:#E17C91}

    /* ── PAGE HERO ── */
    .page-hero{padding:40px 0 28px;text-align:center}
    .page-hero .eyebrow{display:inline-flex;align-items:center;gap:8px;padding:8px 16px;border-radius:999px;background:rgba(255,255,255,.78);color:#6D4964;font-size:13px;font-weight:900;margin-bottom:18px}
    .page-hero h1{margin:0;font-size:clamp(36px,5vw,58px);line-height:.96;letter-spacing:-2px}
    .page-hero p{margin:14px auto 0;max-width:540px;color:var(--muted);line-height:1.6;font-size:15px}

    /* ── LANGUAGE GRID ── */
    .lang-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:20px;padding:8px 0 48px}
    .lang-card{border-radius:var(--radius-xl);background:var(--paper);border:1px solid rgba(255,255,255,.55);box-shadow:var(--shadow);overflow:hidden;display:flex;flex-direction:column;transition:.22s ease;cursor:pointer;text-decoration:none;color:inherit}
    .lang-card:hover{transform:translateY(-4px);box-shadow:0 28px 60px rgba(201,79,134,.22)}
    .lang-card-img{position:relative;height:180px;overflow:hidden}
    .lang-card-img img{width:100%;height:100%;object-fit:cover;display:block;transition:.3s ease}
    .lang-card:hover .lang-card-img img{transform:scale(1.04)}
    .lang-card-img .overlay{position:absolute;inset:0;background:linear-gradient(to bottom,transparent 40%,rgba(52,38,53,.5))}
    .lang-card-img .badge{position:absolute;top:14px;left:14px;padding:6px 12px;border-radius:999px;background:rgba(255,255,255,.92);font-size:12px;font-weight:900;color:var(--pink-dark)}
    .lang-card-img .tutor-count{position:absolute;bottom:14px;right:14px;padding:6px 12px;border-radius:999px;background:rgba(255,255,255,.92);font-size:12px;font-weight:900;color:#342635}
    .lang-body{padding:20px;flex:1;display:flex;flex-direction:column;gap:10px}
    .lang-body h3{margin:0;font-size:22px;letter-spacing:-.5px}
    .lang-level{display:inline-flex;padding:5px 10px;border-radius:999px;font-size:12px;font-weight:900;background:rgba(242,138,178,.18);color:var(--pink-dark)}
    .lang-body p{margin:0;color:var(--muted);line-height:1.5;font-size:14px;flex:1}
    .lang-footer{display:flex;justify-content:space-between;align-items:center;gap:12px;padding-top:4px}
    .lang-price{font-weight:900;font-size:18px;color:var(--ink)}
    .btn-view{padding:10px 20px;border-radius:999px;background:linear-gradient(135deg,var(--hot-pink),var(--pink));color:#fff;font-size:13px;font-weight:900;border:0;cursor:pointer;transition:.18s ease;white-space:nowrap}
    .btn-view:hover{transform:translateY(-1px);box-shadow:0 10px 22px rgba(231,90,155,.30)}

    /* ── TOAST ── */
    .toast{position:fixed;left:50%;bottom:28px;transform:translate(-50%,18px);opacity:0;pointer-events:none;z-index:99;background:#8E3F70;color:#fff;border-radius:999px;padding:12px 18px;font-size:13px;font-weight:900;transition:.2s ease}
    .toast.show{opacity:1;transform:translate(-50%,0)}

    @media(max-width:1024px){.lang-grid{grid-template-columns:repeat(2,1fr)}}
    @media(max-width:640px){
      .lang-grid{grid-template-columns:1fr}
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
        <img src="<?= e($assetBase) ?>/logo.png" alt="Kyoshi">
        <div><strong>Kyoshi</strong><span>Student Learning Space</span></div>
      </a>
      <div class="nav-links">
        <a href="student_dashboard.php">Home</a>
        <a href="student_dashboard.php#preferences">Learning Goals</a>
        <a class="active" href="find_language.php">Find Language</a>
        <a href="booking_status.php">Bookings</a>
        <a href="student_dashboard.php#progress">Progress</a>
        <a href="student_dashboard.php#payments">Payments</a>
      </div>
      <div class="nav-actions">
        <button class="icon-btn" onclick="showToast('Notifications coming soon')"><i class="bi bi-bell"></i><span class="dot"></span></button>
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

<main class="container">
  <div class="page-hero">
    <div class="eyebrow"><i class="bi bi-globe2"></i> Choose your language</div>
    <h1>What do you want<br>to learn today?</h1>
    <p>Pick a language to browse tutors, compare prices, and book your first session.</p>
  </div>

  <div class="lang-grid">
    <?php foreach ($languages as $card): 

    $lang = $card['language'];
    $data = $langData[$lang] ?? null;

    $tutorCount = $data['tutor_count'] ?? 0;
    $minPrice   = $data['min_price'] ?? 0;

    ?>
      <a href="search_tutors.php?lang=<?= urlencode($card['language']) ?>" class="lang-card">
        <div class="lang-card-img">
          <img src="<?= e($assetBase) ?>/<?= e($card['img']) ?>" alt="<?= e($card['language']) ?>">
          <div class="overlay"></div>
          <span class="badge"><?= e($card['tag']) ?></span>
          <span class="tutor-count"><i class="bi bi-people-fill"></i><?= $tutorCount ?> tutors</span>
        </div>
        <div class="lang-body">
          <div>
            <h3><?= e($card['language']) ?></h3>
            <span class="lang-level"><?= e($card['level']) ?></span>
          </div>
          <p><?= e($card['desc']) ?></p>
          <div class="lang-footer">
            <span class="lang-price"><?= $minPrice ? 'From RM '.$minPrice : 'N/A' ?>/hr</span>
            <button class="btn-view">Find Tutors →</button>
          </div>
        </div>
      </a>
    <?php endforeach; ?>
  </div>
</main>

<div class="toast" id="toast"></div>

<script>
  function showToast(msg){
    const t = document.getElementById('toast');
    t.textContent = msg; t.classList.add('show');
    setTimeout(()=>t.classList.remove('show'),1800);
  }
  function toggleDropdown(){
    const d = document.getElementById('profileDropdown');
    d.style.display = d.style.display==='none'?'block':'none';
  }
  document.addEventListener('click',function(e){
    const btn=document.getElementById('profileBtn');
    const dd=document.getElementById('profileDropdown');
    if(!btn.contains(e.target)&&!dd.contains(e.target)) dd.style.display='none';
  });
</script>
</body>
</html>