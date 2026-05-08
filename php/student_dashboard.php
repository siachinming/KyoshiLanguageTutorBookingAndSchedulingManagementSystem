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

if (!$user) {
    header("Location: login.php");
    exit();
}

$displayName = $user['fullname'];
$profilePic  = !empty($user['profile_pic'])
    ? '../uploads/profiles/' . $user['profile_pic']
    : $assetBase . '/profile-student.png';

// Get student preferred languages
$stmt = $conn->prepare("SELECT language FROM student_preferences WHERE user_id = ?");
$stmt->bind_param("i", $userID);
$stmt->execute();
$prefResult = $stmt->get_result();
$preferredLanguages = [];
while ($row = $prefResult->fetch_assoc()) {
    $preferredLanguages[] = $row['language'];
}

// Get student learning modes
$stmt = $conn->prepare("SELECT mode FROM student_learning_modes WHERE user_id = ?");
$stmt->bind_param("i", $userID);
$stmt->execute();
$modeResult = $stmt->get_result();
$preferredModes = [];
while ($row = $modeResult->fetch_assoc()) {
    $preferredModes[] = $row['mode'];
}

// Get recommended tutors matching student's languages AND learning mode
$recommendedTutors = [];
if (!empty($preferredLanguages)) {
    $langPlaceholders = implode(',', array_fill(0, count($preferredLanguages), '?'));
    $types = str_repeat('s', count($preferredLanguages));

    if (!empty($preferredModes)) {
        $modePlaceholders = implode(',', array_fill(0, count($preferredModes), '?'));
        $modeTypes = str_repeat('s', count($preferredModes));

        $stmt = $conn->prepare("
            SELECT DISTINCT u.id, u.fullname, u.profile_pic, tp.rate, tp.bio,
                   GROUP_CONCAT(DISTINCT tl.language) as languages,
                   GROUP_CONCAT(DISTINCT ttm.mode) as teaching_modes
            FROM users u
            JOIN tutor_profiles tp ON u.id = tp.user_id
            JOIN tutor_languages tl ON u.id = tl.user_id
            JOIN tutor_teaching_modes ttm ON u.id = ttm.user_id
            WHERE u.role = 'tutor' 
            AND u.status = 'approved'
            AND tl.language IN ($langPlaceholders)
            AND ttm.mode IN ($modePlaceholders)
            GROUP BY u.id
            ORDER BY tp.rate ASC
            LIMIT 6
        ");
        $stmt->bind_param($types . $modeTypes, ...[...$preferredLanguages, ...$preferredModes]);
    } else {
        $stmt = $conn->prepare("
            SELECT DISTINCT u.id, u.fullname, u.profile_pic, tp.rate, tp.bio,
                   GROUP_CONCAT(DISTINCT tl.language) as languages,
                   GROUP_CONCAT(DISTINCT ttm.mode) as teaching_modes
            FROM users u
            JOIN tutor_profiles tp ON u.id = tp.user_id
            JOIN tutor_languages tl ON u.id = tl.user_id
            LEFT JOIN tutor_teaching_modes ttm ON u.id = ttm.user_id
            WHERE u.role = 'tutor'
            AND u.status = 'approved'
            AND tl.language IN ($langPlaceholders)
            GROUP BY u.id
            ORDER BY tp.rate ASC
            LIMIT 6
        ");
        $stmt->bind_param($types, ...$preferredLanguages);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $recommendedTutors[] = $row;
    }
}
// Get this student's favourites from DB
$favouriteIds = [];
$stmt = $conn->prepare("SELECT tutor_id FROM student_favourites WHERE student_id = ?");
$stmt->bind_param("i", $userID);
$stmt->execute();
$favResult = $stmt->get_result();
while ($row = $favResult->fetch_assoc()) {
    $favouriteIds[] = $row['tutor_id'];
}

// Get all tutors for search modal
$allTutors = [];
$stmt = $conn->prepare("
    SELECT u.id, u.fullname, u.profile_pic, tp.rate, tp.bio, tp.experience,
           GROUP_CONCAT(DISTINCT tl.language) as languages,
           GROUP_CONCAT(DISTINCT ttm.mode) as teaching_modes,
           ul.location as location,
           ROUND(AVG(r.rating), 1) as rating,
           COUNT(r.id) as review_count
    FROM users u
    JOIN tutor_profiles tp ON u.id = tp.user_id
    LEFT JOIN tutor_languages tl ON u.id = tl.user_id
    LEFT JOIN tutor_teaching_modes ttm ON u.id = ttm.user_id
    LEFT JOIN user_locations ul ON u.id = ul.user_id
    LEFT JOIN ratings r ON u.id = r.tutor_id
    WHERE u.role = 'tutor' AND u.status = 'approved'
    GROUP BY u.id
    ORDER BY u.fullname ASC
");
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $allTutors[] = $row;
}

// Get bookings for this student (JOIN users to get tutor name)
$bookings = [];
$stmt = $conn->prepare("
    SELECT b.id, b.language, b.booking_date, b.booking_time, b.status,
           u.fullname AS tutor_name, u.profile_pic AS tutor_pic
    FROM bookings b
    JOIN users u ON b.tutor_id = u.id
    WHERE b.student_id = ?
    ORDER BY b.booking_date DESC, b.booking_time DESC
");
$stmt->bind_param("i", $userID);
$stmt->execute();
$bookingResult = $stmt->get_result();
while ($row = $bookingResult->fetch_assoc()) {
    $bookings[] = $row;
}

// Get payments for this student's bookings
$payments = [];
$stmt = $conn->prepare("
    SELECT p.id, p.amount, p.payment_method, p.payment_status, p.created_at,
           b.language, b.booking_date
    FROM payments p
    JOIN bookings b ON p.booking_id = b.id
    WHERE b.student_id = ?
    ORDER BY p.created_at DESC
");
$stmt->bind_param("i", $userID);
$stmt->execute();
$paymentResult = $stmt->get_result();
while ($row = $paymentResult->fetch_assoc()) {
    $payments[] = $row;
}

// Summary counts from real DB data
$upcomingCount = 0;
foreach ($bookings as $b) {
    if (in_array($b['status'], ['confirmed', 'pending']) && $b['booking_date'] >= date('Y-m-d')) {
        $upcomingCount++;
    }
}
$completedCount = 0;
foreach ($bookings as $b) {
    if ($b['status'] === 'completed') {
        $completedCount++;
    }
}

$summaryCards = [
    ['label' => 'Upcoming Classes',  'value' => $upcomingCount,   'note' => $upcomingCount  ? 'Classes scheduled' : 'No upcoming classes', 'icon' => 'bi-calendar-event',   'tone' => 'lavender'],
    ['label' => 'Learning Streak',   'value' => '0 days',         'note' => 'Start learning!',                                              'icon' => 'bi-lightning-charge', 'tone' => 'peach'],
    ['label' => 'Completed Lessons', 'value' => $completedCount,  'note' => 'This semester',                                                'icon' => 'bi-check2-circle',    'tone' => 'mint'],
    ['label' => 'Saved Tutors',      'value' => count($recommendedTutors), 'note' => 'Ready to rebook',                                     'icon' => 'bi-heart',            'tone' => 'sky'],
];

$languageCards = [
    ['img' => 'japanese.webp', 'language' => 'Japanese', 'level' => 'Beginner', 'desc' => 'Daily conversation, basic phrases, and speaking confidence.',      'price' => 'RM 45', 'tag' => 'Most booked'],
    ['img' => 'english.webp',  'language' => 'English',  'level' => 'Speaking', 'desc' => 'Presentation practice, conversation, and confidence building.',     'price' => 'RM 50', 'tag' => 'Recommended'],
    ['img' => 'mandarin.png',  'language' => 'Mandarin', 'level' => 'Basics',   'desc' => 'Tone practice, beginner vocabulary, and simple sentence patterns.', 'price' => 'RM 48', 'tag' => 'Beginner friendly'],
    ['img' => 'korean.jpg',    'language' => 'Korean',   'level' => 'Starter',  'desc' => 'Hangul, pronunciation, and simple daily expressions.',              'price' => 'RM 46', 'tag' => 'New'],
];

// Helper: map booking status to CSS class
function statusClass($status) {
    $map = [
        'confirmed' => 'confirmed',
        'pending'   => 'pending',
        'completed' => 'review',
        'cancelled' => 'pending',
    ];
    return $map[$status] ?? 'pending';
}

// Helper: map payment status to CSS class
function paymentStatusClass($status) {
    $map = [
        'verified' => 'verified',
        'pending'  => 'pending',
        'failed'   => 'pending',
    ];
    return $map[$status] ?? 'pending';
}

function e($value) {
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Kyoshi Student Dashboard</title>
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
      --purple-dark:#7648B8;
      --lavender:#EAD7FF;
      --peach:#FFD0DD;
      --mint:#DDF4E3;
      --sky:#D8ECFF;
      --rose:#FFC3D8;
      --line:rgba(46,42,59,.12);
      --shadow:0 18px 45px rgba(201,79,134,.16);
      --shadow-soft:0 10px 26px rgba(201,79,134,.10);
      --radius-xl:32px;
      --radius-lg:24px;
      --radius-md:18px;
    }

    *{box-sizing:border-box}
    html{scroll-behavior:smooth}
    body{
      margin:0;
      min-height:100vh;
      font-family:"Segoe UI", Arial, sans-serif;
      color:var(--ink);
      background:
        linear-gradient(120deg, rgba(255,241,246,.74), rgba(255,203,220,.30)),
        url("<?= e($assetBase) ?>/background3.jpg") center/cover fixed no-repeat;
    }
    body::before{
      content:"";
      position:fixed;
      inset:0;
      pointer-events:none;
      z-index:-1;
      background:
        radial-gradient(circle at 7% 10%, rgba(231,90,155,.32), transparent 24%),
        radial-gradient(circle at 90% 8%, rgba(255,195,216,.42), transparent 26%),
        radial-gradient(circle at 55% 95%, rgba(234,215,255,.30), transparent 28%);
    }

    a{text-decoration:none;color:inherit}
    button,input{font-family:inherit}
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
      grid-template-columns:190px minmax(0,1fr) 360px;
      gap:16px;
      align-items:center;
    }
    .brand{display:flex; align-items:center; gap:10px; min-width:0}
    .brand img{width:44px; height:44px; object-fit:contain; border-radius:14px}
    .brand strong{display:block; font-size:18px; line-height:1.05}
    .brand span{display:block; margin-top:3px; font-size:11px; color:var(--muted); white-space:nowrap}

    .nav-links{
      display:flex; align-items:center; justify-content:center; gap:6px;
      background:rgba(255,255,255,.58);
      border:1px solid rgba(242,138,178,.18);
      border-radius:999px; padding:7px;
      overflow:auto; scrollbar-width:none;
      box-shadow:inset 0 1px 0 rgba(255,255,255,.70);
    }
    .nav-links::-webkit-scrollbar{display:none}
    .nav-links a{flex:0 0 auto; padding:9px 12px; border-radius:999px; font-size:13px; font-weight:900; color:#6D4964; white-space:nowrap; transition:.18s ease}
    .nav-links a.active,.nav-links a:hover{background:linear-gradient(135deg, var(--hot-pink), var(--pink)); color:#fff; box-shadow:0 8px 18px rgba(231,90,155,.28)}

    .nav-actions{display:flex; align-items:center; justify-content:flex-end; gap:10px; min-width:0}
    .search{position:relative; flex:1 1 auto; min-width:0}
    .search i{position:absolute; left:14px; top:50%; transform:translateY(-50%); color:#91899F}
    .search input{width:100%; border:1px solid rgba(46,42,59,.10); background:rgba(255,255,255,.88); outline:none; border-radius:999px; padding:12px 14px 12px 38px; box-shadow:var(--shadow-soft)}
    .icon-btn,.profile{border:1px solid rgba(46,42,59,.08); background:rgba(255,255,255,.88); box-shadow:var(--shadow-soft); cursor:pointer}
    .icon-btn{width:44px; height:44px; border-radius:16px; color:#7A4A68; position:relative; flex:0 0 auto}
    .dot{position:absolute; top:10px; right:10px; width:8px; height:8px; border-radius:50%; background:#E17C91}
    .profile{display:flex; align-items:center; gap:9px; border-radius:999px; padding:6px 12px 6px 6px; font-weight:900; color:#7A3D65; flex:0 0 auto; max-width:150px}
    .profile img{width:34px; height:34px; object-fit:cover; border-radius:50%}
    .profile span{max-width:86px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap}

    .glass{background:var(--paper); border:1px solid rgba(255,255,255,.55); box-shadow:var(--shadow)}
    .hero{padding:22px 0 16px}
    .hero-grid{display:grid; grid-template-columns:1.25fr .75fr; gap:20px; align-items:stretch}
    .hero-card,.hero-side,.panel,.stat-card,.language-card,.tutor-card{border-radius:var(--radius-xl)}
    .hero-card{min-height:240px; padding:28px; display:flex; flex-direction:column; justify-content:space-between; position:relative; overflow:hidden}
    .hero-card::after{content:""; position:absolute; width:220px; height:220px; border-radius:50%; right:-70px; bottom:-80px; background:linear-gradient(135deg, rgba(231,90,155,.32), rgba(255,195,216,.50))}
    .eyebrow{width:max-content; display:inline-flex; align-items:center; gap:9px; padding:8px 12px; border-radius:999px; background:rgba(255,255,255,.78); color:#6D4964; font-size:12px; font-weight:900}
    .pulse{width:10px; height:10px; border-radius:50%; background:var(--pink); box-shadow:0 0 0 6px rgba(231,90,155,.18)}
    .hero-copy{position:relative; z-index:1}
    .hero-copy h1{margin:16px 0 0; font-size:clamp(34px,5vw,54px); line-height:.96; letter-spacing:-1.8px; max-width:780px}
    .hero-copy p{margin:14px 0 0; max-width:680px; color:#7A5570; line-height:1.58; font-size:15px}
    .hero-actions{display:flex; flex-wrap:wrap; gap:10px; margin-top:22px; position:relative; z-index:1}
    .btn-primary,.btn-soft,.btn-link,.mini-btn{border:0; cursor:pointer; font-weight:900; transition:.18s ease}
    .btn-primary,.btn-soft,.btn-link{border-radius:999px; padding:12px 16px; font-size:13px}
    .btn-primary{background:linear-gradient(135deg, var(--hot-pink), var(--pink)); color:#fff; box-shadow:0 12px 24px rgba(231,90,155,.28)}
    .btn-soft{background:rgba(255,255,255,.78); color:#7A3D65; border:1px solid rgba(46,42,59,.08)}
    .btn-link{background:transparent; color:var(--pink-dark); padding-left:0}
    .btn-primary:hover,.btn-soft:hover,.btn-link:hover,.mini-btn:hover,.icon-btn:hover,.profile:hover,.pref-chip:hover{transform:translateY(-1px)}

    .hero-side{min-height:240px; padding:24px; display:flex; flex-direction:column; justify-content:space-between}
    .clock{font-size:42px; line-height:1; font-weight:900; letter-spacing:-1.4px}
    .date-line{margin-top:9px; color:var(--muted); font-size:14px}
    .next-card{margin-top:18px; border-radius:24px; padding:18px; background:linear-gradient(135deg, rgba(221,211,255,.72), rgba(255,255,255,.68)); border:1px solid rgba(242,138,178,.18)}
    .next-card span{display:block; color:#645B76; font-size:12px; font-weight:900}
    .next-card strong{display:block; margin-top:8px; font-size:18px}
    .next-card p{margin:8px 0 0; color:#746C81; line-height:1.45}

    .section{margin-top:20px}
    .section-head{display:flex; justify-content:space-between; align-items:end; gap:18px; margin-bottom:15px}
    .section-head h2,.panel-top h3{margin:0; letter-spacing:-.5px}
    .section-head h2{font-size:24px}
    .section-head p,.panel-top p{margin:6px 0 0; color:var(--muted)}
    .section-head a,.panel-top a{font-weight:900; color:var(--pink-dark)}

    .overview-grid{display:grid; grid-template-columns:repeat(4, minmax(0,1fr)); gap:18px}
    .stat-card{min-height:168px; padding:20px; position:relative; overflow:hidden}
    .stat-card::after{content:""; position:absolute; right:-28px; bottom:-28px; width:112px; height:112px; border-radius:50%; opacity:.78}
    .stat-card.lavender::after{background:var(--lavender)}
    .stat-card.peach::after{background:var(--peach)}
    .stat-card.mint::after{background:var(--mint)}
    .stat-card.sky::after{background:var(--sky)}
    .stat-icon{width:52px; height:52px; display:grid; place-items:center; border-radius:18px; background:rgba(124,101,184,.11); color:var(--pink-dark); font-size:22px}
    .stat-card span{display:block; margin-top:22px; color:#645C73; font-size:14px; font-weight:900}
    .stat-card strong{display:block; margin-top:10px; font-size:36px; line-height:1}
    .stat-card small{display:block; margin-top:14px; color:#736B80; font-weight:700}

    .preferences-section .panel {
    display: inline-block;
    width: auto;}
    .panel{padding:24px}
    .panel-top{display:flex; justify-content:space-between; align-items:center; gap:16px; margin-bottom:16px}
    .panel-top h3{font-size:22px}

    .prefs-box{display:flex; flex-direction:column; gap:16px}
    .pref-copy{color:#6E667D; line-height:1.55; font-size:14px}
    .pref-list{display:flex; flex-wrap:wrap; gap:10px}
    .pref-chip{border:1px solid rgba(46,42,59,.10); background:rgba(255,255,255,.82); color:#7A5570; padding:11px 16px; border-radius:999px; font-size:13px; font-weight:900; cursor:pointer; transition:.18s ease}
    .pref-chip.active{background:linear-gradient(135deg, var(--hot-pink), var(--pink)); color:#fff; border-color:var(--pink); box-shadow:0 10px 20px rgba(242,138,178,.30)}
    .selected-note{min-height:22px; color:#6E667D; font-size:14px; font-weight:700}
    .recommend-separator{height:1px; background:rgba(46,42,59,.08); margin:20px 0 18px}
    .recommend-head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 16px;
}
.recommend-head h4 { margin: 0; font-size: 20px; letter-spacing: -.4px; }
.recommend-head a { font-weight: 900; color: var(--pink-dark); font-size: 13px; }
.recommend-grid {
    display: flex;
    flex-direction: row;
    gap: 16px;
    overflow-x: auto;
    padding-bottom: 8px;
    scrollbar-width: none;
    justify-content: flex-start;
}
.recommend-grid::-webkit-scrollbar { display: none; }

.tutor-card-new {
    flex: 0 0 200px;
    width: 200px;
    min-width: 200px;
    background: rgba(255, 241, 246, 0.9);
    border: 1px solid rgba(46, 42, 59, 0.10);
    border-radius: 20px;
    overflow: hidden;
    box-shadow: 0 6px 18px rgba(201, 79, 134, 0.10);
}

/* Taller image */
.tutor-card-new .img-wrapper {
    position: relative;
    width: 100%;
    height: 180px;
}

.tutor-card-new .img-wrapper img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
    border-radius: 0;
    background: #f1f1f1;
}
.tutor-card-new .fav-btn {
    position: absolute;
    top: 8px;
    right: 8px;
    background: rgba(255,255,255,0.88);
    border: none;
    border-radius: 50%;
    width: 30px;
    height: 30px;
    cursor: pointer;
    font-size: 13px;
    display: grid;
    place-items: center;
    box-shadow: 0 2px 8px rgba(0,0,0,0.12);
}

/* Card body */
.tutor-card-new .tutor-card-body {
    padding: 12px;
    display: flex;
    flex-direction: column;
    gap: 4px;
}
.tutor-card-new h5 {
    margin: 0;
    font-size: 14px;
    font-weight: 900;
    color: var(--ink);
}
.tutor-card-new .meta {
    margin: 0;
    font-size: 12px;
    color: var(--muted);
}
.tutor-card-new .view-btn {
    display: block;
    margin-top: 10px;
    text-align: center;
    background: linear-gradient(135deg, var(--hot-pink), var(--pink));
    color: #fff;
    border-radius: 999px;
    padding: 8px 10px;
    font-size: 12px;
    font-weight: 900;
    box-shadow: 0 6px 14px rgba(231, 90, 155, 0.25);
    transition: .18s ease;
}
.tutor-card-new .view-btn:hover { transform: translateY(-1px); }
    .tutor-card{padding:16px; background:rgba(255,241,246,.82); border:1px solid rgba(46,42,59,.08); display:flex; gap:14px; align-items:flex-start}
    .tutor-card img{width:64px; height:64px; object-fit:cover; border-radius:20px; flex:0 0 auto; background:#f1f1f1}
    .tutor-card strong{display:block; font-size:16px}
    .tutor-card .meta{display:block; margin-top:5px; color:var(--muted); font-size:13px; line-height:1.35}
    .tutor-card .mini-text{display:block; margin-top:8px; color:#6D647C; font-size:13px; line-height:1.45}
    .tutor-card .action-row{display:flex; justify-content:space-between; align-items:center; gap:10px; margin-top:12px}
    .tag{display:inline-flex; padding:7px 10px; border-radius:999px; background:rgba(242,138,178,.22); color:var(--pink-dark); font-size:12px; font-weight:900}
    .empty-state{padding:24px; border-radius:24px; background:rgba(255,241,246,.82); border:1px dashed rgba(46,42,59,.16); color:#6D647C; text-align:center; font-weight:700}

    .language-grid{display:grid; grid-template-columns:repeat(4, minmax(0,1fr)); gap:18px}
    .language-card{padding:18px; min-height:100%; overflow:hidden; position:relative}
    .language-card img{width:100%; height:140px; object-fit:cover; display:block; border-radius:24px; background:#eee; margin-bottom:16px}
    .language-tag{display:inline-flex; padding:7px 11px; border-radius:999px; background:rgba(242,138,178,.22); color:var(--pink-dark); font-size:12px; font-weight:900; margin-bottom:10px}
    .language-card h3{margin:0; font-size:20px; letter-spacing:-.4px}
    .language-card p{margin:8px 0 0; color:var(--muted); line-height:1.45; font-size:14px}
    .card-bottom{display:flex; justify-content:space-between; align-items:center; gap:12px; margin-top:16px}
    .price{font-weight:900; font-size:20px}

    .main-grid{display:grid; grid-template-columns:1.45fr .75fr; gap:20px; align-items:start}
    .split-grid{display:grid; grid-template-columns:1.05fr .95fr; gap:20px; align-items:start}
    .chips{display:flex; flex-wrap:wrap; gap:8px}
    .chip{border:1px solid rgba(46,42,59,.10); background:rgba(255,255,255,.78); color:#7A5570; padding:9px 14px; border-radius:999px; font-size:12px; font-weight:900; cursor:pointer}
    .chip.active{background:linear-gradient(135deg, var(--hot-pink), var(--pink)); color:#fff; border-color:var(--pink)}

    .booking-list,.material-list,.favourite-list,.progress-list,.timeline{display:flex; flex-direction:column; gap:14px}
    .booking-item{display:grid; grid-template-columns:minmax(270px, 1.15fr) minmax(220px, .85fr) auto; align-items:center; gap:18px; padding:16px; border-radius:26px; background:rgba(255,241,246,.82); border:1px solid rgba(46,42,59,.10)}
    .person-line{display:flex; align-items:center; gap:14px; min-width:0}
    .person-line img,.material-item img,.favourite-item img{width:58px; height:58px; object-fit:cover; border-radius:18px; background:#f1f1f1; flex:0 0 auto}
    .person-line strong,.material-item strong,.favourite-item strong{display:block; font-size:16px}
    .person-line span,.lesson-info span,.material-item span,.favourite-item span{display:block; margin-top:6px; color:var(--muted); line-height:1.35}
    .lesson-info strong{display:block; font-size:15px}
    .booking-actions{display:flex; align-items:center; justify-content:flex-end; gap:8px; flex-wrap:wrap}
    .status{display:inline-flex; justify-content:center; align-items:center; min-width:118px; padding:9px 12px; border-radius:999px; font-size:12px; font-weight:900; white-space:nowrap}
    .status.confirmed,.status.verified{background:rgba(215,238,219,.78); color:#3D7047}
    .status.pending{background:rgba(255,217,199,.74); color:#A35F3F}
    .status.review{background:rgba(221,211,255,.78); color:var(--pink-dark)}
    .mini-btn{width:42px; height:42px; display:grid; place-items:center; border-radius:15px; background:rgba(255,255,255,.84); color:#7A4A68; box-shadow:var(--shadow-soft)}

    .timeline-item{display:grid; grid-template-columns:72px 1fr; gap:14px; padding:15px; border-radius:22px; background:rgba(255,241,246,.80); border:1px solid rgba(46,42,59,.08)}
    .timeline-time{padding:8px 10px; height:max-content; border-radius:999px; text-align:center; background:rgba(242,138,178,.22); color:var(--pink-dark); font-weight:900; font-size:12px}
    .timeline-item strong{display:block}
    .timeline-item p{margin:6px 0 0; color:var(--muted); line-height:1.45}

    .material-item,.favourite-item{display:grid; grid-template-columns:auto 1fr auto; align-items:center; gap:14px; padding:14px; border-radius:22px; background:rgba(255,241,246,.80); border:1px solid rgba(46,42,59,.08)}
    .progress-item{padding:16px; border-radius:22px; background:rgba(255,241,246,.80); border:1px solid rgba(46,42,59,.08)}
    .progress-title{display:flex; justify-content:space-between; gap:14px; font-weight:900; margin-bottom:10px}
    .track{height:12px; border-radius:999px; background:rgba(221,211,255,.48); overflow:hidden}
    .fill{height:100%; width:var(--w); border-radius:999px; background:linear-gradient(90deg, var(--hot-pink), var(--pink), var(--peach))}

    .payment-table{overflow:auto}
    table{width:100%; border-collapse:separate; border-spacing:0 12px}
    th{text-align:left; font-size:13px; color:var(--muted); padding:0 14px 4px}
    td{background:rgba(255,241,246,.80); padding:15px 14px; border-top:1px solid rgba(46,42,59,.08); border-bottom:1px solid rgba(46,42,59,.08)}
    td:first-child{border-left:1px solid rgba(46,42,59,.08); border-radius:16px 0 0 16px}
    td:last-child{border-right:1px solid rgba(46,42,59,.08); border-radius:0 16px 16px 0}

    .toast{position:fixed; left:50%; bottom:28px; transform:translate(-50%, 18px); opacity:0; pointer-events:none; z-index:99; background:#8E3F70; color:#fff; border-radius:999px; padding:12px 18px; font-size:13px; font-weight:900; transition:.2s ease}
    .toast.show{opacity:1; transform:translate(-50%,0)}

    @media (max-width:1280px){
      .nav{grid-template-columns:170px minmax(0,1fr) 320px}
      .hero-grid,.main-grid,.split-grid{grid-template-columns:1fr}
      .overview-grid,.language-grid{grid-template-columns:repeat(2,minmax(0,1fr))}
    }
    @media (max-width:980px){
      .nav{grid-template-columns:1fr auto; min-height:auto; padding:10px 0}
      .nav-links{grid-column:1 / -1; grid-row:2; width:100%; justify-content:flex-start}
      .search{display:none}
      .booking-item{grid-template-columns:1fr}
      .booking-actions{justify-content:flex-start}
    }
    @media (max-width:760px){
      .container{width:min(100% - 22px, 100%)}
      .profile span,.brand span{display:none}
      .overview-grid,.language-grid{grid-template-columns:1fr}
      .hero-copy h1{font-size:35px}
      .material-item,.favourite-item,.tutor-card{grid-template-columns:1fr; display:block}
      .tutor-card img{margin-bottom:12px}
      .hero-card,.hero-side,.panel,.stat-card,.language-card{border-radius:24px}
    }

    .star-rating{display:flex;gap:3px;cursor:pointer}
    .star-rating i{font-size:16px;color:#ddd;transition:.15s}
    .star-rating i.filled{color:#FFB800}
    .star-rating:hover i{color:#FFB800}
    .star-rating i:hover ~ i{color:#ddd}
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
          <a class="active" href="#overview">Overview</a>
          <a href="#preferences">Learning Goals</a>
          <a href="find_language.php">Find Language</a>
          <a href="#bookings">Bookings</a>
          <a href="#progress">Progress</a>
          <a href="#payments">Payments</a>
        </div>

        <div class="nav-actions">
          <div class="search">
            <i class="bi bi-search"></i>
            <input id="globalSearch" type="text" placeholder="Search language..."
              onclick="openSearch()" readonly style="cursor:pointer;">
          </div>
          <button class="icon-btn" onclick="showToast('Notifications coming soon')"><i class="bi bi-bell"></i><span class="dot"></span></button>
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
    <section class="hero" id="overview">
      <div class="hero-grid">
        <article class="hero-card glass">
          <div class="hero-copy">
            <div class="eyebrow"><span class="pulse"></span><span>Student dashboard</span></div>
            <h1>Good morning, <?= e($displayName) ?>. Your language journey is ready.</h1>
            <p>Choose the languages you want to learn, get tutor recommendations, manage bookings, and track your progress all in one place.</p>
          </div>
          <div class="hero-actions">
            <button class="btn-primary" onclick="scrollToSection('preferences')">Choose Languages</button>
            <button class="btn-soft" onclick="scrollToSection('find-language')">Find Language</button>
            <button class="btn-link" onclick="scrollToSection('bookings')">View bookings</button>
          </div>
        </article>

        <aside class="hero-side glass">
          <div>
            <div class="clock" id="clock">--:--</div>
            <div class="date-line" id="dateText">Loading date...</div>
          </div>
          <?php 
            $nextBooking = null;
            foreach ($bookings as $b) {
                if ($b['status'] === 'confirmed' && $b['booking_date'] >= date('Y-m-d')) {
                    $nextBooking = $b;
                    break;
                }
            }
          ?>
          <div class="next-card">
            <span>Next confirmed lesson</span>
            <?php if ($nextBooking): ?>
              <strong><?= e($nextBooking['language']) ?> · <?= e(date('g:i A', strtotime($nextBooking['booking_time']))) ?></strong>
              <p><?= e($nextBooking['tutor_name']) ?> · <?= e(date('d M Y', strtotime($nextBooking['booking_date']))) ?></p>
            <?php else: ?>
              <strong>No upcoming lessons</strong>
              <p>Book a tutor to get started!</p>
            <?php endif; ?>
          </div>
        </aside>
      </div>
    </section>
        <section class="section">
      <div class="section-head">
        <div>
          <h2>Student Snapshot</h2>
          <p>Quick overview of your classes, progress, and saved tutors.</p>
        </div>
        <a href="#progress">View progress</a>
      </div>

      <div class="overview-grid">
        <?php foreach ($summaryCards as $card): ?>
          <article class="stat-card glass <?= e($card['tone']) ?> searchable">
            <div class="stat-icon"><i class="bi <?= e($card['icon']) ?>"></i></div>
            <span><?= e($card['label']) ?></span>
            <strong><?= e($card['value']) ?></strong>
            <small><?= e($card['note']) ?></small>
          </article>
        <?php endforeach; ?>
      </div>
    </section>
    <section class="section preferences-section" id="preferences">
    <div class="panel glass">

        <!-- HEADER: title left, View All right -->
        <div class="recommend-head">
            <h4>Recommended Tutors</h4>
            <a href="#find-language">View All</a>
        </div>

        <!-- CARDS ROW -->
        <div class="recommend-grid">
            <?php foreach ($recommendedTutors as $tutor): ?>
            <?php
                $tutorPic = !empty($tutor['profile_pic'])
                    ? '../uploads/profiles/' . $tutor['profile_pic']
                    : $assetBase . '/profile-tutor.png';
            ?>
            <div class="tutor-card-new">
                <div class="img-wrapper">
                    <img src="<?= e($tutorPic) ?>" alt="<?= e($tutor['fullname']) ?>">
                    <button type="button" class="fav-btn"
                    onclick="toggleFav(<?= $tutor['id'] ?>, this)"
                    style="
                        position:absolute; top:8px; right:8px;
                        background: <?= in_array($tutor['id'], $favouriteIds) ? 'rgba(255,220,235,0.95)' : 'rgba(255,255,255,0.88)' ?>;
                        color: <?= in_array($tutor['id'], $favouriteIds) ? '#E75A9B' : '#aaa' ?>;
                        border:none; border-radius:50%; width:30px; height:30px;
                        cursor:pointer; font-size:13px; display:grid; place-items:center;
                        box-shadow:0 2px 8px rgba(0,0,0,0.12);
                        transition: transform .2s ease;">
                    ❤
                </button>
                </div>
                <div class="tutor-card-body">
                    <h5><?= e($tutor['fullname']) ?></h5>
                    <p class="meta"><?= e($tutor['languages']) ?> · RM <?= e($tutor['rate']) ?>/hr</p>
                    <a href="tutor_profile.php?id=<?= $tutor['id'] ?>" class="view-btn">View Details</a>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

    </div>
</section>        

    <section class="section" id="find-language">
      <div class="section-head">
        <div>
          <h2>Find Language</h2>
          <p>Choose the language you want to learn, then compare suitable tutors.</p>
        </div>
        <a href="#preferences">Recommend tutor</a>
      </div>

      <div class="language-grid">
        <?php foreach ($languageCards as $card): ?>
          <article class="language-card glass searchable">
            <img src="<?= e($assetBase) ?>/<?= e($card['img']) ?>" alt="<?= e($card['language']) ?>">
            <div class="language-tag"><?= e($card['tag']) ?></div>
            <h3><?= e($card['language']) ?> · <?= e($card['level']) ?></h3>
            <p><?= e($card['desc']) ?></p>
            <div class="card-bottom">
              <div class="price"><?= e($card['price']) ?></div>
              <button class="btn-primary" onclick="showToast('<?= e($card['language']) ?> tutors opened')">View tutors</button>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    </section>

    <section class="section main-grid" id="bookings">
      <div class="panel glass">
        <div class="panel-top">
          <div>
            <h3>My Bookings</h3>
            <p>Confirmed, pending, and completed lessons.</p>
          </div>
          <div class="chips">
            <button class="chip active" data-filter="all">All</button>
            <button class="chip" data-filter="confirmed">Confirmed</button>
            <button class="chip" data-filter="pending">Pending</button>
            <button class="chip" data-filter="review">Completed</button>
          </div>
        </div>

        <div class="booking-list" id="bookingList">
          <?php if (empty($bookings)): ?>
            <div class="empty-state">You have no bookings yet. Find a tutor to get started!</div>
          <?php else: ?>
            <?php foreach ($bookings as $booking):
              $css = statusClass($booking['status']);
              $tutorBookingPic = !empty($booking['tutor_pic'])
                  ? '../uploads/profiles/' . $booking['tutor_pic']
                  : $assetBase . '/profile-tutor.png';
            ?>
              <div class="booking-item <?= e($css) ?> searchable">
                <div class="person-line">
                  <img src="<?= e($tutorBookingPic) ?>" alt="<?= e($booking['tutor_name']) ?>">
                  <div>
                    <strong><?= e($booking['language']) ?> Lesson</strong>
                    <span><?= e($booking['tutor_name']) ?> · <?= e(date('d M Y', strtotime($booking['booking_date']))) ?> at <?= e(date('g:i A', strtotime($booking['booking_time']))) ?></span>
                  </div>
                </div>
                <div class="lesson-info">
                  <strong><?= e(ucfirst($booking['status'])) ?></strong>
                  <span>Booking #<?= e($booking['id']) ?></span>
                </div>
                <div class="booking-actions">
                  <span class="status <?= e($css) ?>"><?= e(ucfirst($booking['status'])) ?></span>
                  <button class="mini-btn" onclick="showToast('Booking details opened')"><i class="bi bi-arrow-right"></i></button>
                </div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>

      <!-- Saved Tutors sidebar (uses correct DB field names) -->
      <aside class="panel glass" id="favourites">
        <div class="panel-top">
          <div>
            <h3>Saved Tutors</h3>
            <p>Tutors matching your language preferences.</p>
          </div>
          <a href="#find-language">Find more</a>
        </div>

        <div class="favourite-list">
          <?php if (empty($recommendedTutors)): ?>
            <div class="empty-state">No saved tutors yet. Set your language preferences above!</div>
          <?php else: ?>
            <?php foreach (array_slice($recommendedTutors, 0, 3) as $tutor):
              $favPic = !empty($tutor['profile_pic'])
                  ? '../uploads/profiles/' . $tutor['profile_pic']
                  : $assetBase . '/profile-tutor.png';
            ?>
              <div class="favourite-item searchable">
                <img src="<?= e($favPic) ?>" alt="<?= e($tutor['fullname']) ?>">
                <div>
                  <strong><?= e($tutor['fullname']) ?></strong>
                  <span><?= e($tutor['languages'] ?? '—') ?> · RM <?= e($tutor['rate']) ?>/hr</span>
                </div>
                <button class="mini-btn" onclick="showToast('Tutor added to comparison')"><i class="bi bi-star-fill"></i></button>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </aside>
    </section>

    <section class="section" id="progress">
      <div class="panel glass">
        <div class="panel-top">
          <div>
            <h3>Learning Progress</h3>
            <p>Progress tracking will be available once your tutor submits feedback.</p>
          </div>
        </div>
        <div class="empty-state">No progress data yet. Complete a lesson to see your progress here.</div>
      </div>
    </section>

    <section class="section" id="payments">
      <div class="panel glass">
        <div class="panel-top">
          <div>
            <h3>Payment Status</h3>
            <p>Track payment proof and verified class payments.</p>
          </div>
        </div>

        <div class="payment-table">
          <?php if (empty($payments)): ?>
            <div class="empty-state">No payment records found.</div>
          <?php else: ?>
            <table>
              <thead>
                <tr>
                  <th>Class</th>
                  <th>Amount</th>
                  <th>Method</th>
                  <th>Date</th>
                  <th>Status</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($payments as $payment):
                  $pCss = paymentStatusClass($payment['payment_status']);
                ?>
                  <tr class="searchable">
                    <td><?= e($payment['language']) ?></td>
                    <td>RM <?= e(number_format($payment['amount'], 2)) ?></td>
                    <td><?= e($payment['payment_method']) ?></td>
                    <td><?= e(date('d M Y', strtotime($payment['created_at']))) ?></td>
                    <td><span class="status <?= e($pCss) ?>"><?= e(ucfirst($payment['payment_status'])) ?></span></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        </div>
      </div>
    </section>

    <div style="height:38px;"></div>
  </main>

  <div class="toast" id="toast">Saved</div>

<script>
  // 1. Global variables FIRST
  let activeFilters = { langs: [], modes: [], locations: [], rating: 0 };
  let activeRatingBtn = null;
  let toastTimer;

  // 2. toggleFav
  function toggleFav(tutorId, btn) {
      const formData = new FormData();
      formData.append('tutor_id', tutorId);
      fetch('toggle_favourite.php', {
          method: 'POST',
          body: formData
      })
      .then(res => res.text())
      .then(response => {
          response = response.trim();
          if (response === 'added') {
              btn.style.color = '#E75A9B';
              btn.style.background = 'rgba(255, 220, 235, 0.95)';
              showToast('Added to favourites ❤');
          } else if (response === 'removed') {
              btn.style.color = '#aaa';
              btn.style.background = 'rgba(255,255,255,0.88)';
              showToast('Removed from favourites');
          }
          btn.style.transform = 'scale(1.4)';
          setTimeout(() => btn.style.transform = 'scale(1)', 200);
      });
  }

  // 3. Clock
  const clock = document.getElementById("clock");
  const dateText = document.getElementById("dateText");
  function updateDateTime(){
      const now = new Date();
      clock.textContent = now.toLocaleTimeString("en-MY", { hour:"2-digit", minute:"2-digit" });
      dateText.textContent = now.toLocaleDateString("en-MY", { weekday:"long", day:"numeric", month:"long", year:"numeric" });
  }
  updateDateTime();
  setInterval(updateDateTime, 1000);

  // 4. Scroll
  function scrollToSection(id){
      const el = document.getElementById(id);
      if(el) el.scrollIntoView({ behavior:"smooth", block:"start" });
  }

  // 5. Booking filter chips
  const chips = document.querySelectorAll(".chip");
  const bookingItems = document.querySelectorAll("#bookingList .booking-item");
  chips.forEach(chip => {
      chip.addEventListener("click", () => {
          chips.forEach(c => c.classList.remove("active"));
          chip.classList.add("active");
          const filter = chip.dataset.filter;
          bookingItems.forEach(item => {
              item.style.display = filter === "all" || item.classList.contains(filter) ? "grid" : "none";
          });
      });
  });

  // 6. Toast
  function showToast(message){
      const toast = document.getElementById("toast");
      toast.textContent = message;
      toast.classList.add("show");
      clearTimeout(toastTimer);
      toastTimer = setTimeout(() => toast.classList.remove("show"), 1800);
  }

  // 7. Profile dropdown
  function toggleDropdown(){
      const d = document.getElementById('profileDropdown');
      d.style.display = d.style.display === 'none' ? 'block' : 'none';
  }
  document.addEventListener('click', function(e){
      const btn = document.getElementById('profileBtn');
      const dropdown = document.getElementById('profileDropdown');
      if (!btn.contains(e.target) && !dropdown.contains(e.target)) {
          dropdown.style.display = 'none';
      }
  });

  // 8. Search modal
  function openSearch(){
      document.getElementById('searchModal').style.display = 'block';
      setTimeout(() => document.getElementById('tutorSearchInput').focus(), 100);
  }
  function closeSearch(){
      document.getElementById('searchModal').style.display = 'none';
  }

  // 9. Filter functions
  function toggleFilterPanel() {
      const panel = document.getElementById('filterPanel');
      const isOpen = panel.style.display !== 'none';
      panel.style.display = isOpen ? 'none' : 'block';
  }

  function toggleFilterChip(el, type) {
      const val = el.dataset.value;
      const isActive = el.classList.contains('chip-active');
      if (isActive) {
          el.classList.remove('chip-active');
          el.style.background = 'white';
          el.style.color = '#7A5570';
          el.style.borderColor = 'rgba(46,42,59,.12)';
          if (type === 'lang')     activeFilters.langs     = activeFilters.langs.filter(v => v !== val);
          if (type === 'mode')     activeFilters.modes     = activeFilters.modes.filter(v => v !== val);
          if (type === 'location') activeFilters.locations = activeFilters.locations.filter(v => v !== val);
      } else {
          el.classList.add('chip-active');
          el.style.background = 'linear-gradient(135deg,#E75A9B,#F28AB2)';
          el.style.color = 'white';
          el.style.borderColor = '#E75A9B';
          if (type === 'lang')     activeFilters.langs.push(val);
          if (type === 'mode')     activeFilters.modes.push(val);
          if (type === 'location') activeFilters.locations.push(val);
      }
      updateFilterDot();
  }

  function checkLocationFilter() {
      const f2fActive = document.getElementById('f2fChip').classList.contains('chip-active');
      document.getElementById('locationFilterBox').style.display = f2fActive ? 'block' : 'none';
      if (!f2fActive) {
          activeFilters.locations = [];
          document.querySelectorAll('#locationFilterChips .filter-chip').forEach(b => {
              b.classList.remove('chip-active');
              b.style.background = 'white'; b.style.color = '#7A5570'; b.style.borderColor = 'rgba(46,42,59,.12)';
          });
      }
  }

  function updateFilterDot() {
      const from = parseFloat(document.getElementById('priceFrom').value) || 0;
      const to   = parseFloat(document.getElementById('priceTo').value) || 200;
      const hasFilters = activeFilters.langs.length > 0
          || activeFilters.modes.length > 0
          || activeFilters.locations.length > 0
          || activeFilters.rating > 0
          || from > 0 || to < 200;
      document.getElementById('filterDot').style.display = hasFilters ? 'block' : 'none';
  }

  function setRating(el, val) {
      if (activeRatingBtn === el) {
          el.classList.remove('chip-active');
          el.style.background = 'white'; el.style.color = '#7A5570'; el.style.borderColor = 'rgba(46,42,59,.12)';
          activeFilters.rating = 0;
          activeRatingBtn = null;
      } else {
          if (activeRatingBtn) {
              activeRatingBtn.classList.remove('chip-active');
              activeRatingBtn.style.background = 'white'; activeRatingBtn.style.color = '#7A5570'; activeRatingBtn.style.borderColor = 'rgba(46,42,59,.12)';
          }
          el.classList.add('chip-active');
          el.style.background = 'linear-gradient(135deg,#E75A9B,#F28AB2)';
          el.style.color = 'white'; el.style.borderColor = '#E75A9B';
          activeFilters.rating = val;
          activeRatingBtn = el;
      }
      updateFilterDot();
      filterTutors();
  }

  function clearFilters() {
      activeFilters = { langs: [], modes: [], locations: [], rating: 0 };
      document.getElementById('priceFrom').value = 0;
      document.getElementById('priceTo').value   = 200;
      document.getElementById('locationFilterBox').style.display = 'none';
      document.querySelectorAll('.filter-chip').forEach(b => {
          b.classList.remove('chip-active');
          b.style.background = 'white';
          b.style.color = '#7A5570';
          b.style.borderColor = 'rgba(46,42,59,.12)';
      });
      if (activeRatingBtn) {
          activeRatingBtn.classList.remove('chip-active');
          activeRatingBtn.style.background = 'white';
          activeRatingBtn.style.color = '#7A5570';
          activeRatingBtn.style.borderColor = 'rgba(46,42,59,.12)';
          activeRatingBtn = null;
      }
      updateFilterDot();
      filterTutors();
  }

  function filterTutors() {
      const val = document.getElementById('tutorSearchInput').value.toLowerCase().trim();
      const fromPrice = parseFloat(document.getElementById('priceFrom').value) || 0;
      const toPrice   = parseFloat(document.getElementById('priceTo').value) || 200;

      const items = document.querySelectorAll('.search-tutor-item');
      let visibleCount = 0;

      items.forEach(item => {
          const langs    = (item.dataset.lang || '').split(',').map(l => l.trim().toLowerCase()).filter(Boolean);
          const modes    = (item.dataset.mode || '').split(',').map(m => m.trim().toLowerCase()).filter(Boolean);
          const location = (item.dataset.location || '').toLowerCase().trim();
          const rate     = parseFloat(item.dataset.rate || 0);
          const rating   = parseFloat(item.dataset.rating || 0);

          const searchMatch   = val === '' || langs.some(l => l.includes(val));
          const priceMatch    = rate >= fromPrice && rate <= toPrice;
          const langMatch     = activeFilters.langs.length === 0 || activeFilters.langs.some(fl => langs.some(l => l.includes(fl)));
          const modeMatch     = activeFilters.modes.length === 0 || activeFilters.modes.some(fm => modes.some(m => m.includes(fm)));
          const locationMatch = activeFilters.locations.length === 0 || activeFilters.locations.some(loc => location.includes(loc));
          const ratingMatch   = activeFilters.rating === 0 || rating >= activeFilters.rating;

          const show = searchMatch && priceMatch && langMatch && modeMatch && locationMatch && ratingMatch;
          item.style.display = show ? 'flex' : 'none';
          if (show) visibleCount++;
      });

      const rc = document.getElementById('resultCount');
      if (rc) rc.textContent = visibleCount + ' tutor' + (visibleCount !== 1 ? 's' : '') + ' found';
  }
</script>
<div id="searchModal" style="display:none;position:fixed;inset:0;background:rgba(52,38,53,.5);backdrop-filter:blur(6px);z-index:200;padding:60px 20px;overflow-y:auto;">
  <div style="max-width:700px;margin:0 auto;background:white;border-radius:28px;padding:28px;box-shadow:0 30px 60px rgba(201,79,134,.2);position:relative;">

    <div style="display:flex;align-items:center;gap:10px;margin-bottom:14px;">
      <div style="position:relative;flex:1;">
        <i class="bi bi-search" style="position:absolute;left:14px;top:50%;transform:translateY(-50%);color:#91899F;"></i>
        <input type="text" id="tutorSearchInput" placeholder="Search by language..."
          style="width:100%;padding:14px 14px 14px 40px;border:1px solid rgba(46,42,59,.12);border-radius:999px;outline:none;font-size:15px;box-sizing:border-box;"
          oninput="filterTutors()">
      </div>
      <div style="position:relative;flex:0 0 auto;">
        <button onclick="toggleFilterPanel()" id="filterBtn"
          style="width:44px;height:44px;border-radius:14px;border:1px solid rgba(242,138,178,.3);background:white;cursor:pointer;font-size:18px;color:#E75A9B;position:relative;" title="Filters">
          <i class="bi bi-sliders"></i>
          <span id="filterDot" style="display:none;position:absolute;top:8px;right:8px;width:8px;height:8px;border-radius:50%;background:#E75A9B;"></span>
        </button>

        <div id="filterPanel" style="display:none;position:absolute;top:52px;right:0;width:380px;max-height:70vh;overflow-y:auto;background:white;border-radius:20px;padding:20px;box-shadow:0 20px 50px rgba(52,38,53,.2);z-index:400;border:1px solid rgba(242,138,178,.22);">
          <!-- Header -->
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;">
            <strong style="font-size:15px;color:#342635;">Filter Tutors</strong>
            <button onclick="toggleFilterPanel()" style="background:none;border:none;font-size:18px;cursor:pointer;color:#7A5570;">✕</button>
          </div>

        <div style="margin-bottom:18px;">
        <p style="margin:0 0 10px;font-size:13px;font-weight:900;color:#342635;">
            <i class="bi bi-cash-coin" style="color:#E75A9B;margin-right:5px;"></i> Price Range (per Hour)
        </p>
        <div style="display:flex;align-items:center;gap:10px;">
            <div style="flex:1;position:relative;">
            <span style="position:absolute;left:12px;top:50%;transform:translateY(-50%);font-size:13px;color:#9080a0;font-weight:700;">RM</span>
            <input type="number" id="priceFrom" min="0" max="200" value="0" placeholder="0"
                oninput="filterTutors()"
                style="width:100%;padding:10px 10px 10px 34px;border:1px solid rgba(46,42,59,.12);border-radius:12px;outline:none;font-size:14px;font-weight:700;color:#342635;">
            </div>
            <span style="color:#9080a0;font-size:13px;flex-shrink:0;">to</span>
            <div style="flex:1;position:relative;">
            <span style="position:absolute;left:12px;top:50%;transform:translateY(-50%);font-size:13px;color:#9080a0;font-weight:700;">RM</span>
            <input type="number" id="priceTo" min="0" max="200" value="200" placeholder="200"
                oninput="filterTutors()"
                style="width:100%;padding:10px 10px 10px 34px;border:1px solid rgba(46,42,59,.12);border-radius:12px;outline:none;font-size:14px;font-weight:700;color:#342635;">
            </div>
        </div>
        </div>
          <div style="margin-bottom:18px;">
            <p style="margin:0 0 10px;font-size:13px;font-weight:900;color:#342635;"><i class="bi bi-globe2" style="color:#E75A9B;margin-right:5px;"></i> Language</p>
            <div style="display:flex;flex-wrap:wrap;gap:8px;" id="langFilterChips">
              <?php foreach (['Japanese','English','Mandarin','Korean','Malay'] as $fl): ?>
                <button type="button" class="filter-chip" onclick="toggleFilterChip(this,'lang');filterTutors();" data-value="<?= strtolower($fl) ?>" style="padding:8px 14px;border-radius:999px;border:1px solid rgba(46,42,59,.12);background:white;font-size:13px;font-weight:700;color:#7A5570;cursor:pointer;transition:.15s ease;"><?= $fl ?></button>
              <?php endforeach; ?>
            </div>
          </div>
          <hr style="border:none;border-top:1px solid rgba(242,138,178,.18);margin:0 0 14px;">
          <!-- Teaching Mode -->
          <div style="margin-bottom:18px;">
            <p style="margin:0 0 10px;font-size:13px;font-weight:900;"><i class="bi bi-laptop" style="color:#E75A9B;margin-right:5px;"></i> Teaching mode</p>
            <div style="display:flex;flex-wrap:wrap;gap:8px;" id="modeFilterChips">
              <button type="button" class="filter-chip" onclick="toggleFilterChip(this,'mode');filterTutors();" data-value="online" style="padding:8px 14px;border-radius:999px;border:1px solid rgba(46,42,59,.12);background:white;font-size:13px;font-weight:700;color:#7A5570;cursor:pointer;transition:.15s ease;">💻 Online</button>
              <button type="button" class="filter-chip" id="f2fChip" onclick="toggleFilterChip(this,'mode');checkLocationFilter();filterTutors();" data-value="face_to_face" style="padding:8px 14px;border-radius:999px;border:1px solid rgba(46,42,59,.12);background:white;font-size:13px;font-weight:700;color:#7A5570;cursor:pointer;transition:.15s ease;">🤝 Face to Face</button>
            </div>
          </div>
          <hr style="border:none;border-top:1px solid rgba(242,138,178,.18);margin:0 0 14px;">
        <div style="margin-bottom:18px;">
        <p style="margin:0 0 10px;font-size:13px;font-weight:900;color:#342635;">
            <i class="bi bi-star-fill" style="color:#E75A9B;margin-right:5px;"></i> Rating
        </p>
        <div style="display:flex;gap:8px;" id="ratingFilterChips">
            <button type="button" class="filter-chip" onclick="setRating(this,4)" data-value="4"
            style="padding:8px 14px;border-radius:999px;border:1px solid rgba(46,42,59,.12);background:white;font-size:13px;font-weight:700;color:#7A5570;cursor:pointer;transition:.15s ease;">
            ⭐ 4 & up
            </button>
            <button type="button" class="filter-chip" onclick="setRating(this,3)" data-value="3"
            style="padding:8px 14px;border-radius:999px;border:1px solid rgba(46,42,59,.12);background:white;font-size:13px;font-weight:700;color:#7A5570;cursor:pointer;transition:.15s ease;">
            ⭐ 3 & up
            </button>
            <button type="button" class="filter-chip" onclick="setRating(this,2)" data-value="2"
            style="padding:8px 14px;border-radius:999px;border:1px solid rgba(46,42,59,.12);background:white;font-size:13px;font-weight:700;color:#7A5570;cursor:pointer;transition:.15s ease;">
            ⭐ 2 & up
            </button>
        </div>
        </div>
          <!-- Location -->
          <div id="locationFilterBox" style="display:none;">
            <hr style="border:none;border-top:1px solid rgba(242,138,178,.18);margin:0 0 14px;">
            <div style="margin-bottom:18px;">
              <p style="margin:0 0 10px;font-size:13px;font-weight:900;color:#342635;"><i class="bi bi-geo-alt" style="color:#E75A9B;margin-right:5px;"></i> Location</p>
              <div style="display:flex;flex-wrap:wrap;gap:8px;" id="locationFilterChips">
                <?php foreach (['Kuala Lumpur','Penang','Johor Bahru','Kota Kinabalu'] as $city): ?>
                  <button type="button" class="filter-chip" onclick="toggleFilterChip(this,'location');filterTutors();" data-value="<?= strtolower($city) ?>" style="padding:8px 14px;border-radius:999px;border:1px solid rgba(46,42,59,.12);background:white;font-size:13px;font-weight:700;color:#7A5570;cursor:pointer;transition:.15s ease;"><?= $city ?></button>
                <?php endforeach; ?>
              </div>
            </div>
          </div>
          <!-- Footer -->
          <div style="display:flex;justify-content:space-between;align-items:center;padding-top:14px;border-top:1px solid rgba(242,138,178,.18);">
            <button onclick="clearFilters()" style="background:none;border:1px solid rgba(46,42,59,.12);color:#7A5570;font-size:13px;font-weight:900;cursor:pointer;padding:10px 18px;border-radius:999px;">✕ Clear all</button>
            <button onclick="toggleFilterPanel()" style="background:linear-gradient(135deg,#E75A9B,#F28AB2);border:none;color:white;font-size:13px;font-weight:900;cursor:pointer;padding:10px 22px;border-radius:999px;">Apply</button>
          </div>
        </div>
      </div>
      <button onclick="closeSearch()"
        style="width:44px;height:44px;border-radius:14px;border:1px solid rgba(46,42,59,.1);background:white;cursor:pointer;font-size:18px;flex:0 0 auto;">✕</button>
    </div>

    <p id="resultCount" style="font-size:12px;color:#9080a0;font-weight:700;margin:0 0 10px;"></p>

    <div id="tutorSearchResults" style="display:flex;flex-direction:column;gap:12px;">
      <?php foreach ($allTutors as $tutor):
        $tutorPic = !empty($tutor['profile_pic'])
            ? '../uploads/profiles/' . $tutor['profile_pic']
            : $assetBase . '/profile-tutor.png';
      ?>
        <div class="search-tutor-item"
        data-name="<?= e(strtolower($tutor['fullname'])) ?>"
        data-lang="<?= e(strtolower($tutor['languages'] ?? '')) ?>"
        data-mode="<?= e(strtolower($tutor['teaching_modes'] ?? '')) ?>"
        data-location="<?= e(strtolower($tutor['location'] ?? '')) ?>"
        data-rate="<?= e($tutor['rate'] ?? 0) ?>"
        data-rating="<?= e($tutor['rating'] ?? 0) ?>"
        style="display:flex;align-items:center;gap:14px;padding:14px;border-radius:20px;background:rgba(255,241,246,.8);border:1px solid rgba(242,138,178,.15);">
        <img src="<?= e($tutorPic) ?>" style="width:56px;height:56px;border-radius:16px;object-fit:cover;background:#eee;flex:0 0 auto;">
        <div style="flex:1;min-width:0;">
            <strong style="display:block;"><?= e($tutor['fullname']) ?></strong>
            <span style="display:block;color:#7B6178;font-size:13px;margin-top:4px;">
            <?= e($tutor['languages'] ?? 'No language set') ?> · RM <?= e($tutor['rate']) ?>/hr
            <?php if (!empty($tutor['teaching_modes'])): ?> · <?= e($tutor['teaching_modes']) ?><?php endif; ?>
            <?php if (!empty($tutor['location'])): ?> · <?= e($tutor['location']) ?><?php endif; ?>
            <?php if (!empty($tutor['rating'])): ?> · ⭐ <?= e($tutor['rating']) ?> (<?= e($tutor['review_count']) ?>)<?php endif; ?>
            </span>
        </div>
        <a href="tutor_profile.php?id=<?= $tutor['id'] ?>"
            style="padding:10px 18px;border-radius:999px;background:linear-gradient(135deg,#E75A9B,#F28AB2);color:white;font-size:13px;font-weight:700;white-space:nowrap;flex-shrink:0;">
            View
        </a>
        </div>
      <?php endforeach; ?>
    </div>

  </div>
</div>
</body>
</html>