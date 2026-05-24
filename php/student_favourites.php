<?php
session_start();
include 'config.php';
$assetBase = '../assets/img';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$userID = $_SESSION['user_id'];

// Get student info
$stmt = $conn->prepare("SELECT * FROM users WHERE id = ? AND role = 'student'");
$stmt->bind_param("i", $userID);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
if (!$user) { header("Location: login.php"); exit(); }

$displayName = $user['fullname'];
$profilePic  = !empty($user['profile_pic'])
    ? '../uploads/profiles/' . $user['profile_pic']
    : $assetBase . '/profile-student.png';

// Get favourites with tutor details
$favourites = [];
$stmt = $conn->prepare("
    SELECT u.id, u.fullname, u.profile_pic, tp.rate, tp.bio, tp.experience,
           GROUP_CONCAT(DISTINCT tl.language) as languages,
           GROUP_CONCAT(DISTINCT ttm.mode) as teaching_modes,
           ul.location as location,
           ROUND(AVG(r.rating), 1) as avg_rating,
           COUNT(DISTINCT r.id) as review_count,
           sf.created_at as saved_at
    FROM student_favourites sf
    JOIN users u ON sf.tutor_id = u.id
    JOIN tutor_profiles tp ON u.id = tp.user_id
    LEFT JOIN tutor_languages tl ON u.id = tl.user_id
    LEFT JOIN tutor_teaching_modes ttm ON u.id = ttm.user_id
    LEFT JOIN user_locations ul ON u.id = ul.user_id
    LEFT JOIN ratings r ON u.id = r.tutor_id
    WHERE sf.student_id = ?
    GROUP BY u.id, sf.created_at
    ORDER BY sf.created_at DESC
");
$stmt->bind_param("i", $userID);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $favourites[] = $row;
}

function e($v) { return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>My Favourites · Kyoshi</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
  <style>
    :root{
      --cream:#FFF1F6; --paper:rgba(255,255,255,.88); --ink:#342635; --muted:#7B6178;
      --pink:#F28AB2; --pink-dark:#C94F86; --hot-pink:#E75A9B; --purple:#A77BE8;
      --lavender:#EAD7FF; --peach:#FFD0DD; --mint:#DDF4E3; --sky:#D8ECFF;
      --line:rgba(46,42,59,.12); --shadow:0 18px 45px rgba(201,79,134,.16);
      --shadow-soft:0 10px 26px rgba(201,79,134,.10);
      --radius-xl:32px; --radius-lg:24px; --radius-md:18px;
    }
    *{box-sizing:border-box} html{scroll-behavior:smooth}
    body{margin:0;min-height:100vh;font-family:"Segoe UI",Arial,sans-serif;color:var(--ink);
      background:linear-gradient(120deg,rgba(255,241,246,.74),rgba(255,203,220,.30)),
        url("<?= e($assetBase) ?>/background3.jpg") center/cover fixed no-repeat;}
    body::before{content:"";position:fixed;inset:0;pointer-events:none;z-index:-1;
      background:radial-gradient(circle at 7% 10%,rgba(231,90,155,.32),transparent 24%),
        radial-gradient(circle at 90% 8%,rgba(255,195,216,.42),transparent 26%),
        radial-gradient(circle at 55% 95%,rgba(234,215,255,.30),transparent 28%);}
    a{text-decoration:none;color:inherit} button,input{font-family:inherit}
    .container{width:min(1440px,calc(100% - 40px));margin:0 auto}

    /* ── TOPBAR (identical to dashboard) ── */
    .topbar{position:sticky;top:0;z-index:50;background:rgba(255,241,246,.86);backdrop-filter:blur(20px);border-bottom:1px solid rgba(231,90,155,.18);box-shadow:0 10px 30px rgba(201,79,134,.10);}
    .nav{min-height:78px;display:grid;grid-template-columns:190px minmax(0,1fr) 360px;gap:16px;align-items:center;}
    .brand{display:flex;align-items:center;gap:10px;min-width:0}
    .brand img{width:44px;height:44px;object-fit:contain;border-radius:14px}
    .brand strong{display:block;font-size:18px;line-height:1.05}
    .brand span{display:block;margin-top:3px;font-size:11px;color:var(--muted);white-space:nowrap}
    .nav-links{display:flex;align-items:center;justify-content:center;gap:6px;background:rgba(255,255,255,.58);border:1px solid rgba(242,138,178,.18);border-radius:999px;padding:7px;overflow:auto;scrollbar-width:none;box-shadow:inset 0 1px 0 rgba(255,255,255,.70);}
    .nav-links::-webkit-scrollbar{display:none}
    .nav-links a{flex:0 0 auto;padding:9px 12px;border-radius:999px;font-size:13px;font-weight:900;color:#6D4964;white-space:nowrap;transition:.18s ease}
    .nav-links a.active,.nav-links a:hover{background:linear-gradient(135deg,var(--hot-pink),var(--pink));color:#fff;box-shadow:0 8px 18px rgba(231,90,155,.28)}
    .nav-actions{display:flex;align-items:center;justify-content:flex-end;gap:10px;min-width:0}
    .search{position:relative;flex:1 1 auto;min-width:0}
    .search i{position:absolute;left:14px;top:50%;transform:translateY(-50%);color:#91899F}
    .search input{width:100%;border:1px solid rgba(46,42,59,.10);background:rgba(255,255,255,.88);outline:none;border-radius:999px;padding:12px 14px 12px 38px;box-shadow:var(--shadow-soft)}
    .icon-btn,.profile{border:1px solid rgba(46,42,59,.08);background:rgba(255,255,255,.88);box-shadow:var(--shadow-soft);cursor:pointer}
    .icon-btn{width:44px;height:44px;border-radius:16px;color:#7A4A68;position:relative;flex:0 0 auto;display:grid;place-items:center}
    .dot{position:absolute;top:10px;right:10px;width:8px;height:8px;border-radius:50%;background:#E17C91}
    .profile{display:flex;align-items:center;gap:9px;border-radius:999px;padding:6px 12px 6px 6px;font-weight:900;color:#7A3D65;flex:0 0 auto;max-width:150px}
    .profile img{width:34px;height:34px;object-fit:cover;border-radius:50%}
    .profile span{max-width:86px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}

    /* ── PAGE CONTENT ── */
    .glass{background:var(--paper);border:1px solid rgba(255,255,255,.55);box-shadow:var(--shadow)}
    .page-header{padding:28px 0 8px}
    .breadcrumb{display:flex;align-items:center;gap:8px;font-size:13px;color:var(--muted);margin-bottom:12px}
    .breadcrumb a{color:var(--pink-dark);font-weight:700}
    .page-header h1{margin:0;font-size:clamp(28px,4vw,44px);letter-spacing:-1.2px}
    .page-header p{margin:8px 0 0;color:var(--muted);font-size:15px}

    /* Stats bar */
    .stats-bar{display:flex;gap:16px;flex-wrap:wrap;margin:20px 0}
    .stat-pill{background:var(--paper);border:1px solid rgba(255,255,255,.55);box-shadow:var(--shadow-soft);border-radius:999px;padding:10px 20px;display:flex;align-items:center;gap:8px;font-weight:900;font-size:14px}
    .stat-pill i{color:var(--hot-pink)}

    /* Filter bar */
    .filter-bar{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:22px}
    .filter-chip{border:1px solid rgba(46,42,59,.12);background:rgba(255,255,255,.82);color:#7A5570;padding:10px 16px;border-radius:999px;font-size:13px;font-weight:900;cursor:pointer;transition:.18s ease}
    .filter-chip.active,.filter-chip:hover{background:linear-gradient(135deg,var(--hot-pink),var(--pink));color:#fff;border-color:var(--pink);box-shadow:0 8px 18px rgba(231,90,155,.22)}
    .sort-select{border:1px solid rgba(46,42,59,.12);background:rgba(255,255,255,.88);border-radius:999px;padding:10px 16px;font-size:13px;font-weight:700;color:#7A5570;outline:none;cursor:pointer;margin-left:auto}

    /* Favourites grid */
    .favs-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:20px}

    /* Tutor favourite card */
    .fav-card{border-radius:var(--radius-xl);overflow:hidden;transition:.22s ease;position:relative}
    .fav-card:hover{transform:translateY(-4px);box-shadow:0 28px 56px rgba(201,79,134,.22)}
    .fav-card-img{position:relative;height:200px;overflow:hidden}
    .fav-card-img img{width:100%;height:100%;object-fit:cover;display:block;transition:.3s ease}
    .fav-card:hover .fav-card-img img{transform:scale(1.04)}
    .fav-card-img .overlay{position:absolute;inset:0;background:linear-gradient(to bottom,transparent 40%,rgba(52,38,53,.6))}
    .fav-card-img .badges{position:absolute;top:12px;left:12px;display:flex;gap:6px;flex-wrap:wrap}
    .badge{padding:5px 10px;border-radius:999px;font-size:11px;font-weight:900;backdrop-filter:blur(8px)}
    .badge-lang{background:rgba(255,255,255,.88);color:#7A3D65}
    .badge-mode{background:rgba(231,90,155,.88);color:#fff}
    .remove-fav-btn{position:absolute;top:12px;right:12px;width:34px;height:34px;border-radius:50%;border:none;background:rgba(255,255,255,.9);cursor:pointer;display:grid;place-items:center;font-size:14px;color:#E75A9B;box-shadow:0 4px 12px rgba(0,0,0,.15);transition:.18s ease}
    .remove-fav-btn:hover{background:#fff;transform:scale(1.1)}
    .fav-card-body{padding:18px}
    .fav-card-body h3{margin:0;font-size:18px;letter-spacing:-.4px}
    .fav-card-meta{margin:6px 0 0;color:var(--muted);font-size:13px;display:flex;align-items:center;gap:6px;flex-wrap:wrap}
    .fav-card-meta .sep{color:rgba(46,42,59,.25)}
    .rating-row{display:flex;align-items:center;gap:5px;margin:10px 0 0}
    .stars{display:flex;gap:2px}
    .stars i{font-size:13px}
    .rating-val{font-weight:900;font-size:14px;color:#342635}
    .rating-count{font-size:12px;color:var(--muted)}
    .fav-card-bio{margin:10px 0 0;font-size:13px;color:#6D647C;line-height:1.5;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
    .rate-row{display:flex;align-items:center;justify-content:space-between;margin:14px 0 0;padding:12px 0 0;border-top:1px solid rgba(46,42,59,.08)}
    .rate-price{font-size:22px;font-weight:900;color:var(--ink)}
    .rate-price small{font-size:12px;color:var(--muted);font-weight:700}
    .card-actions{display:flex;gap:8px;margin-top:14px}
    .btn-book{flex:1;padding:11px 14px;border-radius:999px;border:none;background:linear-gradient(135deg,var(--hot-pink),var(--pink));color:#fff;font-size:13px;font-weight:900;cursor:pointer;transition:.18s ease;box-shadow:0 8px 18px rgba(231,90,155,.26)}
    .btn-book:hover{transform:translateY(-1px);box-shadow:0 12px 24px rgba(231,90,155,.32)}
    .btn-view{flex:1;padding:11px 14px;border-radius:999px;border:1px solid rgba(46,42,59,.10);background:rgba(255,255,255,.84);color:#7A3D65;font-size:13px;font-weight:900;cursor:pointer;transition:.18s ease;text-align:center}
    .btn-view:hover{transform:translateY(-1px);background:#fff}
    .btn-rate{width:42px;height:42px;border-radius:14px;border:1px solid rgba(242,138,178,.3);background:rgba(255,241,246,.9);color:var(--hot-pink);font-size:16px;cursor:pointer;display:grid;place-items:center;flex:0 0 auto;transition:.18s ease}
    .btn-rate:hover{background:var(--pink);color:#fff;transform:translateY(-1px)}

    /* Empty state */
    .empty-favourites{text-align:center;padding:80px 20px;border-radius:var(--radius-xl)}
    .empty-favourites .icon{font-size:64px;color:rgba(231,90,155,.3);margin-bottom:20px}
    .empty-favourites h3{margin:0;font-size:26px;letter-spacing:-.5px}
    .empty-favourites p{margin:10px 0 24px;color:var(--muted);font-size:15px}
    .btn-primary{background:linear-gradient(135deg,var(--hot-pink),var(--pink));color:#fff;padding:13px 24px;border-radius:999px;border:none;font-size:14px;font-weight:900;cursor:pointer;box-shadow:0 12px 24px rgba(231,90,155,.28);transition:.18s ease}
    .btn-primary:hover{transform:translateY(-2px)}

    /* Rating modal */
    .modal-overlay{position:fixed;inset:0;background:rgba(52,38,53,.52);backdrop-filter:blur(6px);z-index:300;display:none;place-items:center}
    .modal-overlay.open{display:grid}
    .modal-box{background:#fff;border-radius:28px;padding:32px;max-width:420px;width:90%;box-shadow:0 30px 60px rgba(201,79,134,.22);position:relative}
    .modal-box h3{margin:0 0 6px;font-size:22px;letter-spacing:-.5px}
    .modal-box p{margin:0 0 22px;color:var(--muted);font-size:14px}
    .star-rate{display:flex;gap:8px;justify-content:center;margin-bottom:22px}
    .star-rate i{font-size:36px;color:#e0d0e8;cursor:pointer;transition:.15s}
    .star-rate i.lit{color:#FFB800}
    .modal-textarea{width:100%;border:1px solid rgba(46,42,59,.12);border-radius:16px;padding:14px;font-size:14px;outline:none;resize:vertical;min-height:90px;box-sizing:border-box;color:var(--ink)}
    .modal-actions{display:flex;gap:10px;margin-top:16px}
    .modal-cancel{flex:1;padding:12px;border-radius:999px;border:1px solid rgba(46,42,59,.12);background:rgba(255,255,255,.9);color:#7A5570;font-weight:900;cursor:pointer;font-size:13px}
    .modal-submit{flex:2;padding:12px;border-radius:999px;border:none;background:linear-gradient(135deg,var(--hot-pink),var(--pink));color:#fff;font-weight:900;cursor:pointer;font-size:13px;box-shadow:0 8px 18px rgba(231,90,155,.26)}
    .close-modal{position:absolute;top:16px;right:16px;width:34px;height:34px;border-radius:50%;border:none;background:rgba(242,138,178,.15);color:var(--pink-dark);cursor:pointer;font-size:16px;display:grid;place-items:center}

    /* Toast */
    .toast{position:fixed;left:50%;bottom:28px;transform:translate(-50%,18px);opacity:0;pointer-events:none;z-index:500;background:#8E3F70;color:#fff;border-radius:999px;padding:12px 22px;font-size:13px;font-weight:900;transition:.2s ease}
    .toast.show{opacity:1;transform:translate(-50%,0)}

    @media(max-width:1280px){.nav{grid-template-columns:170px minmax(0,1fr) 320px}}
    @media(max-width:980px){.nav{grid-template-columns:1fr auto;min-height:auto;padding:10px 0}.nav-links{grid-column:1/-1;grid-row:2;width:100%}.search{display:none}}
    @media(max-width:760px){.container{width:min(100% - 22px,100%)}.profile span,.brand span{display:none}.favs-grid{grid-template-columns:1fr}}
  </style>
</head>
<body>

<!-- ── TOPBAR ── -->
<header class="topbar">
  <div class="container">
    <nav class="nav">
      <a href="student_dashboard.php" class="brand">
        <img src="<?= e($assetBase) ?>/logo.png" alt="Kyoshi logo">
        <div><strong>Kyoshi</strong><span>Student Learning Space</span></div>
      </a>
      <div class="nav-links">
                    <a href="student_dashboard.php">Home</a>
                    <a href="find_language.php">Find Language</a>
                    <a href="booking_status.php">My Bookings</a>
                    <a href="my_payments.php">My Payments</a>
                    <a class="active" href="my_materials.php">My Materials</a>
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

<!-- ── MAIN ── -->
<main class="container">
  <div class="page-header">
    <div class="breadcrumb">
      <a href="student_dashboard.php">Home</a>
      <i class="bi bi-chevron-right" style="font-size:10px;"></i>
      <span>My Favourites</span>
    </div>
    <h1>My Favourite Tutors</h1>
    <p>Your saved tutors — book a session or leave a rating anytime.</p>
  </div>

  <!-- Stats bar -->
  <div class="stats-bar">
    <div class="stat-pill"><i class="bi bi-heart-fill"></i> <?= count($favourites) ?> Saved Tutor<?= count($favourites) !== 1 ? 's' : '' ?></div>
    <?php
      $langs = [];
      foreach ($favourites as $f) {
          if (!empty($f['languages'])) {
              foreach (explode(',', $f['languages']) as $l) $langs[] = trim($l);
          }
      }
      $langs = array_unique($langs);
    ?>
  </div>

  <!-- Filter bar -->
  <?php if (!empty($favourites)): ?>
  <div class="filter-bar">
    <button class="filter-chip active" data-filter="all" onclick="filterFavs(this,'all')">All Tutors</button>
    <?php foreach ($langs as $lang): ?>
      <button class="filter-chip" data-filter="<?= strtolower(e($lang)) ?>" onclick="filterFavs(this,'<?= strtolower(e($lang)) ?>')"><?= e($lang) ?></button>
    <?php endforeach; ?>
    <select class="sort-select" onchange="sortFavs(this.value)">
      <option value="saved">Recently Saved</option>
      <option value="rate_asc">Price: Low to High</option>
      <option value="rate_desc">Price: High to Low</option>
      <option value="rating">Highest Rated</option>
    </select>
  </div>
  <?php endif; ?>

  <!-- Cards grid -->
  <?php if (empty($favourites)): ?>
    <div class="empty-favourites glass">
      <div class="icon"><i class="bi bi-heart"></i></div>
      <h3>No favourites yet</h3>
      <p>Save tutors you love to book them quickly next time.</p>
      <a href="student_dashboard.php"><button class="btn-primary"><i class="bi bi-search" style="margin-right:6px;"></i>Find Tutors</button></a>
    </div>
  <?php else: ?>
    <div class="favs-grid" id="favsGrid">
      <?php foreach ($favourites as $tutor):
        $pic = !empty($tutor['profile_pic'])
            ? '../uploads/profiles/' . $tutor['profile_pic']
            : $assetBase . '/profile-tutor.png';
        $langs_arr = !empty($tutor['languages']) ? explode(',', $tutor['languages']) : [];
        $modes_arr = !empty($tutor['teaching_modes']) ? explode(',', $tutor['teaching_modes']) : [];
        $stars_filled = round($tutor['avg_rating'] ?? 0);
      ?>
      <article class="fav-card glass"
        data-lang="<?= e(strtolower($tutor['languages'] ?? '')) ?>"
        data-rate="<?= e($tutor['rate'] ?? 0) ?>"
        data-rating="<?= e($tutor['avg_rating'] ?? 0) ?>">

        <div class="fav-card-img">
          <img src="<?= e($pic) ?>" alt="<?= e($tutor['fullname']) ?>">
          <div class="overlay"></div>
          <div class="badges">
            <?php foreach (array_slice($langs_arr, 0, 2) as $l): ?>
              <span class="badge badge-lang"><?= e(trim($l)) ?></span>
            <?php endforeach; ?>
            <?php foreach (array_slice($modes_arr, 0, 1) as $m): ?>
              <span class="badge badge-mode"><?= e(trim($m) === 'face_to_face' ? '🤝 F2F' : '💻 Online') ?></span>
            <?php endforeach; ?>
          </div>
          <button class="remove-fav-btn" onclick="removeFav(<?= $tutor['id'] ?>, this)" title="Remove from favourites">
            <i class="bi bi-heart-fill"></i>
          </button>
        </div>

        <div class="fav-card-body">
          <h3><?= e($tutor['fullname']) ?></h3>
          <div class="fav-card-meta">
            <?php if (!empty($tutor['experience'])): ?>
              <span><i class="bi bi-briefcase" style="color:var(--pink-dark);font-size:11px;"></i> <?= e($tutor['experience']) ?> yrs exp</span>
              <span class="sep">·</span>
            <?php endif; ?>
            <?php if (!empty($tutor['location'])): ?>
              <span><i class="bi bi-geo-alt" style="color:var(--pink-dark);font-size:11px;"></i> <?= e($tutor['location']) ?></span>
            <?php endif; ?>
          </div>

          <?php if ($tutor['avg_rating']): ?>
          <div class="rating-row">
            <div class="stars">
              <?php for ($s = 1; $s <= 5; $s++): ?>
                <i class="bi bi-star<?= $s <= $stars_filled ? '-fill' : '' ?>" style="color:<?= $s <= $stars_filled ? '#FFB800' : '#ddd' ?>;"></i>
              <?php endfor; ?>
            </div>
            <span class="rating-val"><?= e($tutor['avg_rating']) ?></span>
            <span class="rating-count">(<?= e($tutor['review_count']) ?> review<?= $tutor['review_count'] != 1 ? 's' : '' ?>)</span>
          </div>
          <?php else: ?>
          <div class="rating-row" style="color:var(--muted);font-size:13px;">No ratings yet</div>
          <?php endif; ?>

          <?php if (!empty($tutor['bio'])): ?>
            <p class="fav-card-bio"><?= e($tutor['bio']) ?></p>
          <?php endif; ?>

          <div class="rate-row">
            <div class="rate-price">RM <?= e($tutor['rate']) ?><small>/hr</small></div>
            <span style="font-size:12px;color:var(--muted);font-weight:700;">
              Saved <?= date('d M', strtotime($tutor['saved_at'])) ?>
            </span>
          </div>

          <div class="card-actions">
            <button class="btn-book" onclick="openBooking(<?= $tutor['id'] ?>, '<?= e($tutor['fullname']) ?>')">
              <i class="bi bi-calendar-plus" style="margin-right:5px;"></i>Book
            </button>
            <a href="tutor_profile.php?id=<?= $tutor['id'] ?>" class="btn-view">
              <i class="bi bi-person" style="margin-right:5px;"></i>View 
            </a>
          </div>
        </div>
      </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div style="height:48px;"></div>
</main>

<!-- ── RATING MODAL ── -->
<div class="modal-overlay" id="rateModal">
  <div class="modal-box">
    <button class="close-modal" onclick="closeRateModal()">✕</button>
    <h3>Rate Your Tutor</h3>
    <p id="rateModalSub">Share your experience</p>
    <div class="star-rate" id="starRate">
      <i class="bi bi-star-fill" data-v="1" onclick="setRating(1)"></i>
      <i class="bi bi-star-fill" data-v="2" onclick="setRating(2)"></i>
      <i class="bi bi-star-fill" data-v="3" onclick="setRating(3)"></i>
      <i class="bi bi-star-fill" data-v="4" onclick="setRating(4)"></i>
      <i class="bi bi-star-fill" data-v="5" onclick="setRating(5)"></i>
    </div>
    <textarea class="modal-textarea" id="rateReview" placeholder="Write a short review (optional)..."></textarea>
    <input type="hidden" id="rateTutorId">
    <div class="modal-actions">
      <button class="modal-cancel" onclick="closeRateModal()">Cancel</button>
      <button class="modal-submit" onclick="submitRating()"><i class="bi bi-send" style="margin-right:6px;"></i>Submit Rating</button>
    </div>
  </div>
</div>

<!-- ── REMOVE CONFIRM MODAL ── -->
<div class="modal-overlay" id="removeModal">
  <div class="modal-box" style="text-align:center;">
    <div style="font-size:48px;color:var(--hot-pink);margin-bottom:16px;">💔</div>
    <h3>Remove Favourite?</h3>
    <p id="removeModalSub" style="color:var(--muted);margin:8px 0 24px;">This tutor will be removed from your saved list.</p>
    <div class="modal-actions">
      <button class="modal-cancel" onclick="closeRemoveModal()">Keep</button>
      <button class="modal-submit" style="background:linear-gradient(135deg,#e74c3c,#c0392b);" onclick="confirmRemove()">Remove</button>
    </div>
  </div>
</div>

<div class="toast" id="toast"></div>

<script>
  let currentRating = 0;
  let removeTarget = { id: null, el: null };
  let notifOpen = false;

  // ── Toast
  function showToast(msg) {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.classList.add('show');
    setTimeout(() => t.classList.remove('show'), 2200);
  }

  // ── Dropdown
  function toggleDropdown() {
    const d = document.getElementById('profileDropdown');
    d.style.display = d.style.display === 'none' ? 'block' : 'none';
  }
  document.addEventListener('click', e => {
    const btn = document.getElementById('profileBtn');
    const dd = document.getElementById('profileDropdown');
    if (!btn.contains(e.target) && !dd.contains(e.target)) dd.style.display = 'none';
  });

  // ── Notifications
  function toggleNotifications() {
    notifOpen = !notifOpen;
    const dd = document.getElementById('notifDropdown');
    dd.style.display = notifOpen ? 'block' : 'none';
    if (notifOpen) loadNotifications();
  }
  function loadNotifications() {
    fetch('get_notifications.php').then(r => r.json()).then(data => {
      const dot = document.getElementById('notifDot');
      const list = document.getElementById('notifList');
      dot.style.display = data.count > 0 ? 'block' : 'none';
      if (!data.notifications.length) { list.innerHTML = '<div style="padding:20px;text-align:center;color:#9080a0;font-size:13px;">No notifications yet.</div>'; return; }
      list.innerHTML = data.notifications.map(n => `
        <div onclick="markRead(${n.id},this)" style="padding:14px 16px;border-bottom:1px solid rgba(242,138,178,.08);cursor:pointer;background:${n.is_read?'white':'rgba(255,241,246,.6)'};">
          <div style="display:flex;align-items:flex-start;gap:10px;">
            <div style="width:8px;height:8px;border-radius:50%;background:${n.is_read?'transparent':'#E75A9B'};flex-shrink:0;margin-top:5px;"></div>
            <div><strong style="display:block;font-size:13px;">${n.title}</strong>
            <p style="margin:3px 0 0;font-size:12px;color:#7B6178;">${n.message}</p>
            <span style="display:block;margin-top:4px;font-size:11px;color:#aaa;">${timeAgo(n.created_at)}</span></div>
          </div>
        </div>`).join('');
    });
  }
  function markRead(id, el) {
    fetch('mark_notification_read.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id})});
    loadNotifications();
  }
  function markAllRead() {
    fetch('mark_notification_read.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id:0})}).then(()=>loadNotifications());
  }
  function timeAgo(d) {
    const diff = Math.floor((new Date()-new Date(d))/1000);
    if (diff<60) return 'Just now'; if (diff<3600) return Math.floor(diff/60)+'m ago';
    if (diff<86400) return Math.floor(diff/3600)+'h ago'; return Math.floor(diff/86400)+'d ago';
  }
  function checkUnread() {
    fetch('get_notifications.php').then(r=>r.json()).then(data=>{
      const dot=document.getElementById('notifDot'); if(dot) dot.style.display=data.count>0?'block':'none';
    });
  }
  checkUnread(); setInterval(checkUnread, 60000);
  document.addEventListener('click', e => {
    const bell = document.getElementById('bellBtn'), dd = document.getElementById('notifDropdown');
    if (bell && dd && !bell.contains(e.target) && !dd.contains(e.target)) { dd.style.display='none'; notifOpen=false; }
  });

  // ── Filter favourites
  function filterFavs(btn, filter) {
    document.querySelectorAll('.filter-chip').forEach(c => c.classList.remove('active'));
    btn.classList.add('active');
    document.querySelectorAll('#favsGrid .fav-card').forEach(card => {
      card.style.display = (filter === 'all' || (card.dataset.lang || '').includes(filter)) ? '' : 'none';
    });
  }

  // ── Sort
  function sortFavs(val) {
    const grid = document.getElementById('favsGrid');
    const cards = [...grid.querySelectorAll('.fav-card')];
    cards.sort((a, b) => {
      if (val === 'rate_asc')  return parseFloat(a.dataset.rate) - parseFloat(b.dataset.rate);
      if (val === 'rate_desc') return parseFloat(b.dataset.rate) - parseFloat(a.dataset.rate);
      if (val === 'rating')    return parseFloat(b.dataset.rating) - parseFloat(a.dataset.rating);
      return 0;
    });
    cards.forEach(c => grid.appendChild(c));
  }

  // ── Remove favourite
  function removeFav(tutorId, btn) {
    removeTarget = { id: tutorId, el: btn.closest('.fav-card') };
    document.getElementById('removeModal').classList.add('open');
  }
  function closeRemoveModal() {
    document.getElementById('removeModal').classList.remove('open');
    removeTarget = { id: null, el: null };
  }
  function confirmRemove() {
    if (!removeTarget.id) return;
    const fd = new FormData(); fd.append('tutor_id', removeTarget.id);
    fetch('toggle_favourite.php', { method:'POST', body:fd }).then(r=>r.text()).then(res => {
      if (res.trim() === 'removed') {
        removeTarget.el.style.transition = 'all .3s ease';
        removeTarget.el.style.opacity = '0';
        removeTarget.el.style.transform = 'scale(.9)';
        setTimeout(() => { removeTarget.el.remove(); showToast('Removed from favourites'); }, 300);
      }
      closeRemoveModal();
    });
  }

  // ── Book button
  function openBooking(tutorId, name) {
    window.location.href = 'booking_form.php?tutor_id=' + tutorId;
  }

  // ── Rating modal
  function openRateModal(tutorId, name) {
    currentRating = 0;
    document.getElementById('rateTutorId').value = tutorId;
    document.getElementById('rateModalSub').textContent = 'How was your session with ' + name + '?';
    document.getElementById('rateReview').value = '';
    updateStars(0);
    document.getElementById('rateModal').classList.add('open');
  }
  function closeRateModal() { document.getElementById('rateModal').classList.remove('open'); }
  function setRating(val) { currentRating = val; updateStars(val); }
  function updateStars(val) {
    document.querySelectorAll('#starRate i').forEach((s, i) => {
      s.classList.toggle('lit', i < val);
    });
  }
  function submitRating() {
    if (!currentRating) { showToast('Please select a star rating!'); return; }
    const fd = new FormData();
    fd.append('tutor_id', document.getElementById('rateTutorId').value);
    fd.append('rating', currentRating);
    fd.append('review', document.getElementById('rateReview').value);
    fetch('submit_rating.php', { method:'POST', body:fd }).then(r=>r.text()).then(() => {
      closeRateModal(); showToast('Rating submitted! Thank you ⭐');
    });
  }

  // Hover star effect
  document.querySelectorAll('#starRate i').forEach((star, idx) => {
    star.addEventListener('mouseenter', () => updateStars(idx+1));
    star.addEventListener('mouseleave', () => updateStars(currentRating));
  });

  // Close modals on overlay click
  document.getElementById('rateModal').addEventListener('click', e => { if(e.target===e.currentTarget) closeRateModal(); });
  document.getElementById('removeModal').addEventListener('click', e => { if(e.target===e.currentTarget) closeRemoveModal(); });
</script>
</body>
</html>