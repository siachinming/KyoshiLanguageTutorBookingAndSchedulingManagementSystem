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

function e($v){ return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }

// Get tutor ID from URL
$tutorID = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$tutorID) { header("Location: find_language.php"); exit(); }

$stmt = $conn->prepare("
    SELECT u.id, u.fullname, u.profile_pic, u.phone,
           tp.experience, tp.rate, tp.bio, tp.language_certificate,
           ul.location,
           ROUND(AVG(r.rating), 1) as avg_rating,
           COUNT(DISTINCT r.id) as review_count,
           GROUP_CONCAT(DISTINCT tq.qualification_name SEPARATOR ' | ') as qualifications,
           GROUP_CONCAT(DISTINCT tc.certificate_name SEPARATOR ' | ') as certificates
    FROM users u
    JOIN tutor_profiles tp ON u.id = tp.user_id
    LEFT JOIN user_locations ul ON u.id = ul.user_id AND ul.location_type = 'teaching'
    LEFT JOIN ratings r ON u.id = r.tutor_id
    LEFT JOIN tutor_qualifications tq ON u.id = tq.tutor_id
    LEFT JOIN tutor_certificates tc ON u.id = tc.tutor_id AND tc.status = 'approved'
    WHERE u.id = ? AND u.role = 'tutor' AND u.status = 'approved'
    GROUP BY u.id
");
$stmt->bind_param("i", $tutorID);
$stmt->execute();
$tutor = $stmt->get_result()->fetch_assoc();
if (!$tutor) { header("Location: find_language.php"); exit(); }

// Get tutor languages
$stmt = $conn->prepare("SELECT language, proficiency_level FROM tutor_languages WHERE user_id = ?");
$stmt->bind_param("i", $tutorID);
$stmt->execute();
$langResult = $stmt->get_result();
$languages = [];
while ($row = $langResult->fetch_assoc()) {
    $languages[] = $row['language'];
}

// Get tutor teaching modes
$stmt = $conn->prepare("SELECT mode FROM tutor_teaching_modes WHERE user_id = ?");
$stmt->bind_param("i", $tutorID);
$stmt->execute();
$modeResult = $stmt->get_result();
$modes = [];
while ($row = $modeResult->fetch_assoc()) {
    $modes[] = $row['mode'];
}

// Get tutor availability from structured table
$availStmt = $conn->prepare("
    SELECT day_of_week, start_time, end_time 
    FROM tutor_availability 
    WHERE tutor_id = ? 
    ORDER BY FIELD(day_of_week, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday')
");
$availStmt->bind_param("i", $tutorID);
$availStmt->execute();
$availability = $availStmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get reviews
$stmt = $conn->prepare("
    SELECT r.rating, r.comment, r.created_at, r.is_anonymous,
           u.fullname as student_name, u.profile_pic as student_pic
    FROM ratings r
    JOIN users u ON r.student_id = u.id
    WHERE r.tutor_id = ?
    ORDER BY r.created_at DESC
");
$stmt->bind_param("i", $tutorID);
$stmt->execute();
$reviews = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Check if already favourited
$stmt = $conn->prepare("SELECT id FROM student_favourites WHERE student_id = ? AND tutor_id = ?");
$stmt->bind_param("ii", $userID, $tutorID);
$stmt->execute();
$isFav = $stmt->get_result()->fetch_assoc() ? true : false;

$tutorPic = !empty($tutor['profile_pic'])
    ? '../uploads/profiles/' . $tutor['profile_pic']
    : $assetBase . '/profile-tutor.png';

$stars = $tutor['avg_rating'] ? round($tutor['avg_rating']) : 0;

// Format availability for display
$availabilityText = '';
if (!empty($availability)) {
    $days = [];
    foreach ($availability as $slot) {
        $days[] = $slot['day_of_week'] . ': ' . date('g:i A', strtotime($slot['start_time'])) . ' - ' . date('g:i A', strtotime($slot['end_time']));
    }
    $availabilityText = implode(', ', array_slice($days, 0, 3));
    if (count($days) > 3) {
        $availabilityText .= ' +' . (count($days) - 3) . ' more';
    }
}

// Format phone for display
$displayPhone = !empty($tutor['phone']) ? $tutor['phone'] : 'No phone number';
$whatsappNumber = '';
if (!empty($tutor['phone'])) {
    $phone_raw = preg_replace('/[^0-9]/', '', $tutor['phone']);
    if (substr($phone_raw, 0, 1) == '0') {
        $whatsappNumber = '60' . substr($phone_raw, 1);
    } else {
        $whatsappNumber = $phone_raw;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= e($tutor['fullname']) ?> · Kyoshi</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
  <style>
    :root{
      --cream:#FFF1F6; --paper:rgba(255,255,255,.88); --ink:#342635; --muted:#7B6178;
      --pink:#F28AB2; --pink-dark:#C94F86; --hot-pink:#E75A9B;
      --lavender:#EAD7FF; --peach:#FFD0DD; --mint:#DDF4E3; --sky:#D8ECFF;
      --shadow:0 18px 45px rgba(201,79,134,.16); --shadow-soft:0 10px 26px rgba(201,79,134,.10);
      --radius-xl:32px; --radius-lg:24px;
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
    a{text-decoration:none;color:inherit} button,input,textarea{font-family:inherit}
    .container{width:min(1440px,calc(100% - 40px));margin:0 auto}

    /* TOPBAR */
    .topbar{position:sticky;top:0;z-index:50;background:rgba(255,241,246,.86);backdrop-filter:blur(20px);border-bottom:1px solid rgba(231,90,155,.18);box-shadow:0 10px 30px rgba(201,79,134,.10)}
    .nav{min-height:78px;display:grid;grid-template-columns:190px minmax(0,1fr) 360px;gap:16px;align-items:center}
    .brand{display:flex;align-items:center;gap:10px;min-width:0}
    .brand img{width:44px;height:44px;object-fit:contain;border-radius:14px}
    .brand strong{display:block;font-size:18px;line-height:1.05}
    .brand span{display:block;margin-top:3px;font-size:11px;color:var(--muted);white-space:nowrap}
    .nav-links{display:flex;align-items:center;justify-content:center;gap:6px;border-radius:999px;padding:7px;overflow:auto;scrollbar-width:none;box-shadow:inset 0 1px 0 rgba(255,255,255,.70)}
    .nav-links::-webkit-scrollbar{display:none}
    .nav-links a{flex:0 0 auto;padding:9px 12px;border-radius:999px;font-size:13px;font-weight:900;color:#6D4964;white-space:nowrap;transition:.18s ease}
    .nav-links a.active,.nav-links a:hover{background:linear-gradient(135deg,var(--hot-pink),var(--pink));color:#fff;box-shadow:0 8px 18px rgba(231,90,155,.28)}
    .nav-actions{display:flex;align-items:center;justify-content:flex-end;gap:10px;min-width:0}
    .icon-btn,.profile{border:1px solid rgba(46,42,59,.08);background:rgba(255,255,255,.88);box-shadow:var(--shadow-soft);cursor:pointer}
    .icon-btn{width:44px;height:44px;border-radius:16px;color:#7A4A68;position:relative;flex:0 0 auto;display:grid;place-items:center}
    .dot{position:absolute;top:10px;right:10px;width:8px;height:8px;border-radius:50%;background:#E17C91}
    .profile{display:flex;align-items:center;gap:9px;border-radius:999px;padding:6px 12px 6px 6px;font-weight:900;color:#7A3D65;flex:0 0 auto;max-width:150px}
    .profile img{width:34px;height:34px;object-fit:cover;border-radius:50%}
    .profile span{max-width:86px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}

    /* LAYOUT */
    .page{padding:28px 0 48px}
    .back-link{display:inline-flex;align-items:center;gap:6px;color:var(--pink-dark);font-weight:900;font-size:13px;padding:9px 16px;border-radius:999px;background:rgba(255,255,255,.78);border:1px solid rgba(46,42,59,.08);margin-bottom:20px;transition:.18s ease}
    .back-link:hover{transform:translateY(-1px)}
    .profile-grid{display:grid;grid-template-columns:1fr 340px;gap:24px;align-items:start}

    /* MAIN CARD */
    .main-card{background:var(--paper);border:1px solid rgba(255,255,255,.55);box-shadow:var(--shadow);border-radius:var(--radius-xl);overflow:hidden}
    .tutor-hero{display:flex;gap:24px;padding:28px;align-items:flex-start}
    .tutor-hero img{width:100px;height:100px;object-fit:cover;border-radius:24px;flex:0 0 auto;background:#eee;box-shadow:var(--shadow-soft)}
    .tutor-hero-info{flex:1;min-width:0}
    .tutor-hero-info h1{margin:0 0 6px;font-size:28px;letter-spacing:-.6px}
    .tutor-rating{display:flex;align-items:center;gap:6px;margin-bottom:12px}
    .tutor-rating span{font-size:14px;font-weight:700;color:#8B6914}
    .tags{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:12px}
    .tag{padding:6px 12px;border-radius:999px;font-size:12px;font-weight:900}
    .tag-pink{background:rgba(242,138,178,.20);color:var(--pink-dark)}
    .tag-blue{background:rgba(216,236,255,.80);color:#2E5D8E}
    .tag-green{background:rgba(221,244,227,.80);color:#2E7042}
    .tag-purple{background:rgba(221,211,255,.80);color:#5B3E9B}
    .tutor-meta-row{display:flex;gap:20px;flex-wrap:wrap;margin-bottom:8px}
    .meta-item{display:flex;align-items:center;gap:6px;font-size:13px;color:var(--muted);font-weight:700}
    .meta-item i{color:var(--pink-dark);font-size:15px}

    /* SECTION */
    .section{padding:0 28px 24px}
    .section-title{font-size:16px;font-weight:900;color:var(--ink);margin:0 0 12px;padding-top:20px;border-top:1px solid rgba(46,42,59,.08)}
    .bio-text{color:var(--muted);line-height:1.7;font-size:15px;margin:0}

    /* REVIEWS */
    .review-item{padding:16px;border-radius:20px;background:rgba(255,241,246,.80);border:1px solid rgba(46,42,59,.08);margin-bottom:12px}
    .review-header{display:flex;align-items:center;gap:12px;margin-bottom:10px}
    .review-header img{width:40px;height:40px;border-radius:50%;object-fit:cover;background:#eee}
    .review-name{font-weight:900;font-size:14px}
    .review-date{font-size:12px;color:var(--muted);margin-top:2px}
    .review-stars{display:flex;gap:2px;margin-left:auto}
    .review-comment{color:var(--muted);font-size:14px;line-height:1.55}
    .empty-reviews{padding:24px;text-align:center;color:var(--muted);font-weight:700;border-radius:16px;background:rgba(255,241,246,.60);border:1px dashed rgba(46,42,59,.12)}

    /* SIDEBAR */
    .sidebar-card{background:var(--paper);border:1px solid rgba(255,255,255,.55);box-shadow:var(--shadow);border-radius:var(--radius-xl);padding:24px;margin-bottom:20px}
    .price-display{font-size:32px;font-weight:900;letter-spacing:-1px;color:var(--ink);margin-bottom:4px}
    .price-label{font-size:13px;color:var(--muted);font-weight:700;margin-bottom:20px}
    .action-row{display:flex;gap:12px;margin-bottom:20px;align-items:center}
    .btn-book{flex:1;padding:14px 20px;border-radius:999px;background:linear-gradient(135deg,var(--hot-pink),var(--pink));color:#fff;font-size:14px;font-weight:900;border:none;cursor:pointer;box-shadow:0 8px 20px rgba(231,90,155,.28);transition:.18s ease;text-align:center}
    .btn-book:hover{transform:translateY(-2px);box-shadow:0 12px 25px rgba(231,90,155,.35)}
    .btn-fav{width:48px;height:48px;border-radius:50%;background:rgba(255,255,255,.88);color:var(--pink-dark);font-size:18px;border:1.5px solid rgba(242,138,178,.40);cursor:pointer;transition:.18s ease;display:flex;align-items:center;justify-content:center;flex-shrink:0}
    .btn-fav:hover{transform:translateY(-2px);background:rgba(255,241,246,.9)}
    .btn-fav.active{background:linear-gradient(135deg,var(--hot-pink),var(--pink));color:#fff;border-color:var(--pink)}
    .btn-wa{width:48px;height:48px;border-radius:50%;background:#25D366;color:#fff;display:flex;align-items:center;justify-content:center;text-decoration:none;font-size:18px;transition:.2s;flex-shrink:0}
    .btn-wa:hover{transform:translateY(-2px);opacity:0.9}
    .divider{height:1px;background:rgba(46,42,59,.08);margin:20px 0}
    .info-row{display:flex;justify-content:space-between;align-items:center;padding:10px 0;border-bottom:1px solid rgba(46,42,59,.06);font-size:14px}
    .info-row:last-child{border-bottom:none}
    .info-row span:first-child{color:var(--muted);font-weight:700}
    .info-row span:last-child{font-weight:900;color:var(--ink);text-align:right;max-width:60%}

    /* TOAST */
    .toast{position:fixed;left:50%;bottom:28px;transform:translate(-50%,18px);opacity:0;pointer-events:none;z-index:99;background:#8E3F70;color:#fff;border-radius:999px;padding:12px 18px;font-size:13px;font-weight:900;transition:.2s ease}
    .toast.show{opacity:1;transform:translate(-50%,0)}

    @media(max-width:1024px){.profile-grid{grid-template-columns:1fr}}
    @media(max-width:640px){
      .nav{grid-template-columns:1fr auto}
      .nav-links{display:none}
      .tutor-hero{flex-direction:column}
      .tutor-hero img{width:90px;height:90px}
      .action-row{gap:8px}
      .btn-fav,.btn-wa{width:42px;height:42px;font-size:16px}
      .btn-book{padding:12px 16px;font-size:13px}
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
  <div class="page">

    <a href="javascript:history.back()" class="back-link">
      <i class="bi bi-arrow-left"></i> Back
    </a>

    <div class="profile-grid">

      <!-- LEFT: Main Info -->
      <div>
        <div class="main-card">

          <!-- HERO -->
          <div class="tutor-hero">
            <img src="<?= e($tutorPic) ?>" alt="<?= e($tutor['fullname']) ?>">
            <div class="tutor-hero-info">
              <h1><?= e($tutor['fullname']) ?></h1>

              <!-- Stars -->
              <div class="tutor-rating">
                <?php for($i=1;$i<=5;$i++): ?>
                  <i class="bi bi-star<?= $i<=$stars?'-fill':'' ?>" style="color:<?= $i<=$stars?'#FFB800':'#ddd' ?>;font-size:16px;"></i>
                <?php endfor; ?>
                <span>
                  <?= $tutor['avg_rating'] ? e($tutor['avg_rating']) : '0.0' ?>
                  (<?= $tutor['review_count'] ?> reviews)
                </span>
              </div>


              <!-- Meta Row with Experience and Phone -->
              <div class="tutor-meta-row">
                <?php if($tutor['experience']): ?>
                  <div class="meta-item"><i class="bi bi-briefcase"></i> <?= e($tutor['experience']) ?> years experience</div>
                <?php endif; ?>
                <?php if(!empty($tutor['phone'])): ?>
                  <div class="meta-item"><i class="bi bi-telephone-fill"></i> <?= e($tutor['phone']) ?></div>
                <?php endif; ?>
              </div>
            </div>
          </div>

          <!-- BIO -->
          <?php if($tutor['bio']): ?>
          <div class="section">
            <p class="section-title">About</p>
            <p class="bio-text"><?= nl2br(e($tutor['bio'])) ?></p>
          </div>
          <?php endif; ?>

  
<?php if(!empty($tutor['qualifications'])): ?>
    <div class="section">
        <p class="section-title">Qualifications</p>
        <div style="display:flex;flex-direction:column;gap:10px;">
            <?php 
            $quals = explode(' | ', $tutor['qualifications']);
            foreach($quals as $qual): 
            ?>
                <div style="display:flex;align-items:center;gap:10px;padding:12px;border-radius:16px;background:rgba(221,244,227,.60);border:1px solid rgba(46,160,87,.18);">
                    <i class="bi bi-patch-check-fill" style="color:#2E7042;font-size:20px;flex:0 0 auto;"></i>
                    <span style="font-weight:600;color:#2E7042;font-size:13px;"><?= e($qual) ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

          <!-- AVAILABILITY (from structured table) -->
          <?php if(!empty($availability)): ?>
            <div class="section">
                <p class="section-title">Availability Schedule</p>
                <div style="display:flex;flex-direction:column;gap:8px;">
                    <?php foreach($availability as $slot): ?>
                    <div style="display:flex;align-items:center;gap:10px;padding:10px 14px;border-radius:16px;background:rgba(216,236,255,.60);border:1px solid rgba(46,93,160,.18);">
                        <i class="bi bi-calendar-check" style="color:#2E5D8E;font-size:18px;"></i>
                        <span style="font-weight:700;color:#2E5D8E;font-size:13px;">
                            <?= e($slot['day_of_week']) ?>: 
                            <?= date('g:i A', strtotime($slot['start_time'])) ?> - 
                            <?= date('g:i A', strtotime($slot['end_time'])) ?>
                        </span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
          <?php endif; ?>

          <!-- REVIEWS -->
          <div class="section">
            <p class="section-title">Reviews (<?= $tutor['review_count'] ?>)</p>
            <?php if(empty($reviews)): ?>
              <div class="empty-reviews">No reviews yet — be the first to book and review!</div>
            <?php else: ?>
              <?php foreach($reviews as $review):
                $studentPic = !empty($review['student_pic'])
                    ? '../uploads/profiles/' . $review['student_pic']
                    : $assetBase . '/profile-student.png';
                $rStars = round($review['rating']);
              ?>
              <div class="review-item">
                <div class="review-header">
                  <?php $isAnon = ($review['is_anonymous'] ?? 0); ?>
                  <img src="<?= $isAnon ? e($assetBase).'/profile-student.png' : e($studentPic) ?>" 
                       alt="<?= $isAnon ? 'Anonymous' : e($review['student_name']) ?>">
                  <div>
                    <div class="review-name">
                      <?= $isAnon ? 'Anonymous Student' : e($review['student_name']) ?>
                    </div>
                    <div class="review-date"><?= date('d M Y', strtotime($review['created_at'])) ?></div>
                  </div>
                  <div class="review-stars">
                    <?php for($i=1;$i<=5;$i++): ?>
                      <i class="bi bi-star<?= $i<=$rStars?'-fill':'' ?>" style="color:<?= $i<=$rStars?'#FFB800':'#ddd' ?>;font-size:13px;"></i>
                    <?php endfor; ?>
                  </div>
                </div>
                <?php if($review['comment']): ?>
                  <p class="review-comment"><?= e($review['comment']) ?></p>
                <?php endif; ?>
              </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>

        </div>
      </div>

      <!-- RIGHT: Sidebar -->
      <div>
        <div class="sidebar-card">
          <div class="price-display">RM <?= e($tutor['rate']) ?></div>
          <div class="price-label">per hour</div>

          <div class="action-row">
            <a href="booking_form.php?tutor_id=<?= $tutor['id'] ?>" class="btn-book">Book Now</a>
            <button class="btn-fav <?= $isFav ? 'active' : '' ?>" id="favBtn" onclick="toggleFav()">
              <i class="bi <?= $isFav ? 'bi-heart-fill' : 'bi-heart' ?>"></i>
            </button>
            <?php if($whatsappNumber): ?>
              <a href="https://wa.me/<?= $whatsappNumber ?>?text=<?= urlencode("Hi {$tutor['fullname']}, I'm interested in your tutoring service!") ?>" 
                 target="_blank" class="btn-wa">
                <i class="bi bi-whatsapp"></i>
              </a>
            <?php endif; ?>
          </div>

          <div class="divider"></div>

          <div class="info-row">
            <span>Experience</span>
            <span><?= $tutor['experience'] ? e($tutor['experience']).' years' : 'Not specified' ?></span>
          </div>
          <div class="info-row">
            <span>Languages</span>
            <span><?= e(implode(', ', $languages)) ?></span>
          </div>
          <div class="info-row">
            <span>Teaching Mode</span>
            <span><?= e(implode(', ', array_map(fn($m) => str_replace('_',' ',ucfirst($m)), $modes))) ?></span>
          </div>
          <?php if($tutor['location']): ?>
          <div class="info-row">
            <span>Location</span>
            <span><?= e($tutor['location']) ?></span>
          </div>
          <?php endif; ?>
          <div class="info-row">
            <span>Rating</span>
            <span><?= $tutor['avg_rating'] ? e($tutor['avg_rating']).' / 5.0' : 'No reviews yet' ?></span>
          </div>
          <?php if(!empty($tutor['qualifications'])): ?>
          <div class="info-row">
              <span>Qualifications</span>
              <span><?= e(str_replace(' | ', ', ', $tutor['qualifications'])) ?></span>
          </div>
          <?php endif; ?>
          <?php if(!empty($availability)): ?>
          <div class="info-row">
            <span>Availability</span>
            <span><?= e($availabilityText) ?></span>
          </div>
          <?php endif; ?>
        </div>
      </div>

    </div>
  </div>
</main>

<div class="toast" id="toast"></div>

<script>
  function toggleFav() {
    const btn = document.getElementById('favBtn');
    const tutorId = <?= $tutorID ?>;
    const formData = new FormData();
    formData.append('tutor_id', tutorId);

    fetch('toggle_favourite.php', { method:'POST', body:formData })
    .then(res => res.text())
    .then(data => {
        data = data.trim();
        if (data === 'added') {
            btn.classList.add('active');
            btn.innerHTML = '<i class="bi bi-heart-fill"></i>';
            showToast('Added to favourites ❤');
        } else if (data === 'removed') {
            btn.classList.remove('active');
            btn.innerHTML = '<i class="bi bi-heart"></i>';
            showToast('Removed from favourites');
        } else {
            showToast('Something went wrong');
        }
        btn.style.transform = 'scale(1.05)';
        setTimeout(() => btn.style.transform = 'scale(1)', 200);
    })
    .catch(error => {
        showToast('Error: ' + error);
    });
  }

  function showToast(msg) {
    const t = document.getElementById('toast');
    t.textContent = msg; t.classList.add('show');
    setTimeout(() => t.classList.remove('show'), 1800);
  }

  function toggleDropdown() {
    const d = document.getElementById('profileDropdown');
    d.style.display = d.style.display === 'none' ? 'block' : 'none';
  }

  document.addEventListener('click', function(e) {
    const btn = document.getElementById('profileBtn');
    const dd = document.getElementById('profileDropdown');
    if (btn && dd && !btn.contains(e.target) && !dd.contains(e.target)) dd.style.display = 'none';
  });
</script>
</body>
</html>