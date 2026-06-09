<?php
session_start();
include 'config.php';
include 'check_login.php';
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
if (!empty($user['profile_pic']) && file_exists('../uploads/profiles/' . $user['profile_pic'])) {
    $profilePic = '../uploads/profiles/' . $user['profile_pic'];
} else {
    $profilePic = $assetBase . '/profile.png';
}
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
    ['img' => 'japanese.webp', 'language' => 'Japanese', 'level' => 'Native / Fluent', 'desc' => 'Experienced in teaching all levels from JLPT N5 to N1', 'tag' => 'Most requested', 'color' => '#FFD0DD'],
    ['img' => 'english.webp', 'language' => 'English', 'level' => 'IELTS Certified', 'desc' => 'Business English, conversation, exam preparation', 'tag' => 'Certified', 'color' => '#D8ECFF'],
    ['img' => 'mandarin.png', 'language' => 'Mandarin', 'level' => 'HSK Advanced', 'desc' => 'Specialized in HSK preparation and business Mandarin', 'tag' => 'Expert', 'color' => '#DDF4E3'],
    ['img' => 'korean.jpg', 'language' => 'Korean', 'level' => 'TOPIK Level 6', 'desc' => 'Focus on TOPIK exam and daily conversation', 'tag' => 'Popular', 'color' => '#EAD7FF'],
    ['img' => 'malay.jpg', 'language' => 'Malay', 'level' => 'Native Speaker', 'desc' => 'Formal Malay, casual conversation, exam prep', 'tag' => 'Local expert', 'color' => '#FFE4CC'],
];

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
<meta http-equiv="Pragma" content="no-cache">
<meta http-equiv="Expires" content="0">
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

/* FLEXIBLE NAVIGATION */
.nav {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 0 16px;
    min-height: 70px;
    position: relative;
}

.brand {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-shrink: 0;
}

.brand img {
    width: 40px;
    height: 40px;
    object-fit: contain;
    border-radius: 12px;
}

.brand strong {
    display: block;
    font-size: 16px;
    line-height: 1.2;
}

.brand span {
    display: block;
    margin-top: 2px;
    font-size: 10px;
    color: var(--muted);
    white-space: nowrap;
}

.nav-links {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 4px;
    flex: 1;
}

.nav-links a {
    padding: 8px 12px;
    border-radius: 999px;
    font-size: 13px;
    font-weight: 700;
    color: #6D4964;
    white-space: nowrap;
    transition: .18s ease;
}

.nav-links a.active,
.nav-links a:hover {
    background: linear-gradient(135deg, var(--hot-pink), var(--pink));
    color: #fff;
}

.nav-actions {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 8px;
    flex-shrink: 0;
    margin-left: auto;
}

/* Profile button */
.profile {
    display: flex;
    align-items: center;
    gap: 8px;
    border-radius: 999px;
    padding: 4px 10px 4px 5px;
    font-weight: 700;
    background: rgba(255,255,255,.88);
    border: 1px solid rgba(46,42,59,.08);
    cursor: pointer;
}

.profile img {
    width: 32px;
    height: 32px;
    object-fit: cover;
    border-radius: 50%;
}

.profile span {
    font-size: 13px;
    max-width: 90px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

/* Hamburger button */

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
@media (max-width: 980px) {
    .nav {
        display: flex !important;
        flex-direction: row !important;
        align-items: center !important;
        justify-content: space-between !important;
        padding: 8px 12px !important;
        min-height: 56px !important;
        width: 100% !important;
    }
    
    /* Hamburger - FAR LEFT */
    .hamburger-menu {
        display: flex !important;
        order: 0 !important;
        margin: 0 !important;
        padding: 0 !important;
    }
    
    /* Brand - CENTER (between hamburger and profile) */
    .brand {
        order: 1 !important;
        margin: 0 auto !important;
        position: absolute !important;
        left: 50% !important;
        transform: translateX(-50%) !important;
    }
    
    /* Profile - FAR RIGHT */
    .nav-actions {
        order: 2 !important;
        margin: 0 !important;
        margin-left: auto !important;
    }
    
    .brand img {
        width: 40px !important;
        height: 40px !important;
    }
    
    .brand strong {
        font-size: 13px !important;
    }
    
    .brand span {
        font-size: 7px !important;
    }
    
    .nav-links {
        display: none !important;
        position: absolute !important;
        top: 100% !important;
        left: 0 !important;
        right: 0 !important;
        width: 100% !important;
        background: white !important;
        flex-direction: column !important;
        padding: 16px !important;
        gap: 8px !important;
        box-shadow: 0 20px 30px rgba(0,0,0,0.15) !important;
        border-radius: 0 0 20px 20px !important;
        z-index: 1000 !important;
    }
    
    .nav-links.show {
        display: flex !important;
    }
    
    .nav-links a {
        padding: 12px 16px !important;
        font-size: 15px !important;
        border-radius: 12px !important;
        width: 100% !important;
    }
    
    /* Hide profile text on mobile */
    .profile span {
        display: none !important;
    }
    
    .profile i.bi-chevron-down {
        display: none !important;
    }
    
    .profile {
        padding: 6px !important;
        background: rgba(255,255,255,.88) !important;
        border-radius: 50% !important;
        width: 36px !important;
        height: 36px !important;
        display: flex !important;
        align-items: center !important;
        justify-content: center !important;
    }
    
    .profile img {
        width: 28px !important;
        height: 28px !important;
        margin: 0 !important;
    }

    .nav-overlay.show {
    width:400px;
}
}
    /* ========== HAMBURGER MENU FOR FIND LANGUAGE PAGE ========== */
.hamburger-menu {
    display: none;
    background: none;
    border: none;
    font-size: 28px;
    cursor: pointer;
    color: #7A3D65;
    width: 40px;
    height: 40px;
    align-items: center;
    justify-content: center;
    border-radius: 12px;
}

.search-icon-btn {
    display: none;
}


.nav-overlay.show {
    display: block;
}



@media (max-width: 980px) {
    .nav {
        display: flex !important;
        flex-wrap: wrap !important;
        align-items: center !important;
        justify-content: space-between !important;
        position: relative !important;
        width:100%;
        min-height: 60px !important;
    }
    
    .profile span {
        display: none !important;
    }
    
    .profile i.bi-chevron-down {
        display: none !important;
    }
    
    /* Make profile button smaller - only show icon */
    .profile {
        padding: 6px !important;
        background: rgba(255,255,255,.88) !important;
        border-radius: 50% !important;
        width: 40px !important;
        height: 40px !important;
        display: flex !important;

        align-items: center !important;
        justify-content: center !important;
    }
    
    .profile img {
        width: 32px !important;
        height: 32px !important;
        left:20px !important;
        margin: 0 !important;
    }

    .hamburger-menu {
        display: flex !important;
        position: absolute !important;
        left:-12px !important;
        top: 50% !important;
        transform: translateY(-50%) !important;
        margin: 0 !important;
        z-index: 10 !important;
    }
    
    .brand {
        position: absolute !important;
        left: 50% !important;
        transform: translateX(-50%) !important;
        top: 50% !important;
        transform: translate(-50%, -50%) !important;
        margin: 0 !important;
    }
    
    .brand strong {
        font-size: 14px !important;
        display: none !important;
    }
    
    .brand span {
        font-size: 8px !important;
        display: none !important;
    }
    
    .brand img {
        width: 60px !important;
        height: 60px !important;
    }
    
    .nav-actions {
                position: absolute !important;
        right: -12px !important;
        top: 50% !important;
        transform: translateY(-50%) !important;
        margin: 0 !important;
    }
    
    .nav-links {
        display: none !important;
        position: fixed !important;
        top: 100% !important;
        left: 0 !important;
        right: 0 !important;
        width: 100% !important;
        background: white !important;
        flex-direction: column !important;
        padding: 16px !important;
        gap: 8px !important;
        box-shadow: 0 20px 30px rgba(0,0,0,0.15) !important;
        border-radius: 0 0 20px 20px !important;
        z-index: 1000 !important;
    }
    
    .nav-links.show {
        display: flex !important;
    }

    #profileDropdown {
        position: absolute !important;
        top: calc(100% + 10px) !important;
        right: -6px !important;
        left: auto !important;
        bottom: auto !important;
        width: auto !important;
        min-width: 200px !important;
        border-radius: 16px !important;
    }
    
    .nav-links a {
        padding: 12px 16px !important;
        font-size: 15px !important;
        border-radius: 12px !important;
        width: 100% !important;
    }
    
    .lang-grid {
        grid-template-columns: 1fr !important;
    }
}
  </style>
</head>
<body>

<header class="topbar">
  <div class="container">
          <nav class="nav">
            <button class="hamburger-menu" id="hamburgerBtn">
    <i class="bi bi-list"></i>
</button>
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
  <div class="nav-overlay" id="navOverlay"></div>
</header>

<main class="container">
  <div class="page-hero">
    <h1>What do you want to learn today?</h1>
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
            <h3><?= e($card['language']) ?></h3><br>
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
  // ========== HAMBURGER MENU ==========
const hamburgerBtn = document.getElementById('hamburgerBtn');
const navLinks = document.querySelector('.nav-links');
const navOverlay = document.getElementById('navOverlay');

if (hamburgerBtn && navLinks) {
    hamburgerBtn.addEventListener('click', function(e) {
        e.stopPropagation();
        navLinks.classList.toggle('show');
        if (navOverlay) navOverlay.classList.toggle('show');
    });
}

if (navOverlay) {
    navOverlay.addEventListener('click', function() {
        navLinks.classList.remove('show');
        navOverlay.classList.remove('show');
    });
}

// Close menu when clicking a link
if (navLinks) {
    navLinks.querySelectorAll('a').forEach(link => {
        link.addEventListener('click', function() {
            navLinks.classList.remove('show');
            if (navOverlay) navOverlay.classList.remove('show');
        });
    });
}
</script>
<script>
// Prevent back button from showing page after logout
history.pushState(null, null, location.href);
window.addEventListener('popstate', function() {
    window.location.href = 'login.php';
});
</script>
</body>
</html>