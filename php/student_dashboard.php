<?php
session_start();
include 'config.php';
$assetBase = '../assets/img';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$userID = $_SESSION['user_id'];

$stmtNotif = $conn->prepare("SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = 0");
$stmtNotif->bind_param("i", $userID);
$stmtNotif->execute();
$unreadNotifCount = $stmtNotif->get_result()->fetch_assoc()['count'];

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
            LIMIT 4
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
// Get all tutors for search modal - UPDATED with availability
$allTutors = [];
$stmt = $conn->prepare("
    SELECT u.id, u.fullname, u.profile_pic, tp.rate, tp.bio, tp.experience,
           GROUP_CONCAT(DISTINCT tl.language) as languages,
           GROUP_CONCAT(DISTINCT ttm.mode) as teaching_modes,
           ul.location as location,
           ROUND(AVG(r.rating), 1) as rating,
           COUNT(r.id) as review_count,
           GROUP_CONCAT(DISTINCT CONCAT(ta.day_of_week, '|', TIME_FORMAT(ta.start_time, '%H:%i'), '|', TIME_FORMAT(ta.end_time, '%H:%i'))) as availability,
           GROUP_CONCAT(DISTINCT 
               CASE 
                   WHEN TIME(ta.start_time) < '12:00:00' THEN 'morning'
                   WHEN TIME(ta.start_time) < '18:00:00' THEN 'afternoon'
                   ELSE 'evening'
               END
           ) as time_slots
    FROM users u
    JOIN tutor_profiles tp ON u.id = tp.user_id
    LEFT JOIN tutor_languages tl ON u.id = tl.user_id
    LEFT JOIN tutor_teaching_modes ttm ON u.id = ttm.user_id
    LEFT JOIN user_locations ul ON u.id = ul.user_id
    LEFT JOIN ratings r ON u.id = r.tutor_id
    LEFT JOIN tutor_availability ta ON u.id = ta.tutor_id
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
    SELECT p.id, p.amount, p.payment_method, p.status, p.created_at,
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

$firstName = explode(' ', trim($displayName))[0];
// Get top tutor ID for the view button
$stmtTopTutorId = $conn->prepare("
    SELECT u.id
    FROM users u
    JOIN bookings b ON b.tutor_id = u.id
    WHERE u.role = 'tutor' AND b.status = 'completed'
    GROUP BY u.id
    ORDER BY COUNT(b.id) DESC
    LIMIT 1
");
$stmtTopTutorId->execute();
$topTutorIdResult = $stmtTopTutorId->get_result()->fetch_assoc();
$topTutorId = $topTutorIdResult['id'] ?? 0;
// Get booked count for this month
$monthStart = date('Y-m-01');
$monthEnd = date('Y-m-t');

$stmtBooked = $conn->prepare("SELECT COUNT(*) AS cnt FROM bookings WHERE student_id = ? AND created_at BETWEEN ? AND ?");
$stmtBooked->bind_param("iss", $userID, $monthStart, $monthEnd);
$stmtBooked->execute();
$bookedCount = $stmtBooked->get_result()->fetch_assoc()['cnt'];

// Get distinct languages count from ALL completed bookings
$stmtLang = $conn->prepare("SELECT COUNT(DISTINCT language) AS cnt FROM bookings WHERE student_id = ? AND status = 'completed'");
$stmtLang->bind_param("i", $userID);
$stmtLang->execute();
$langCount = $stmtLang->get_result()->fetch_assoc()['cnt'];
// ── SCHEDULE: upcoming bookings for calendar dots + list ──
$stmtSched = $conn->prepare("
    SELECT b.id, b.language, b.booking_date, b.booking_time, b.status, b.learning_mode,
           u.fullname AS tutor_name
    FROM bookings b
    JOIN users u ON b.tutor_id = u.id
    WHERE b.student_id = ?
      AND b.booking_date >= CURDATE()
      AND b.status NOT IN ('cancelled','completed')
    ORDER BY b.booking_date ASC, b.booking_time ASC
    LIMIT 5
");
$stmtSched->bind_param("i", $userID);
$stmtSched->execute();
$schedBookings = $stmtSched->get_result()->fetch_all(MYSQLI_ASSOC);

// Build JSON for JS: { "2026-05-15": [{...}, {...}], ... }
$schedByDate = [];
foreach ($schedBookings as $sb) {
    $schedByDate[$sb['booking_date']][] = [
        'id'       => $sb['id'],
        'language' => $sb['language'],
        'time'     => date('g:i A', strtotime($sb['booking_time'])),
        'tutor'    => $sb['tutor_name'],
        'status'   => $sb['status'],
        'mode'     => $sb['learning_mode'],
    ];
}
$schedJSON = json_encode($schedByDate);
$calMonthJS = (int)date('n') - 1; // 0-indexed for JS
$calYearJS  = (int)date('Y');

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
    * {
  -webkit-user-select: none;
  -moz-user-select: none;
  -ms-user-select: none;
  user-select: none;
}

/* Allow text selection only on input fields and textareas */
input, textarea, [contenteditable="true"] {
  -webkit-user-select: text;
  -moz-user-select: text;
  -ms-user-select: text;
  user-select: text;
}

/* Optional: Allow selection on specific elements if needed */
.selection-allowed {
  -webkit-user-select: text;
  -moz-user-select: text;
  -ms-user-select: text;
  user-select: text;
}
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
    .search{position:relative; flex:1 1 auto; min-width:150px;}
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
.hero-grid-new {
  display: grid;
  grid-template-columns: 1fr 300px;
  gap: 20px;
  margin-bottom: 20px;
}

.hero-card-small {
  background: var(--paper);
  border: 1px solid rgba(255,255,255,.55);
  border-radius: var(--radius-xl);
  padding: 28px 40px;
  position: relative;
  overflow: hidden;
}

.hero-card-small::after {
  content: "";
  position: absolute;
  width: 150px;
  height: 150px;
  border-radius: 50%;
  right: -40px;
  bottom: -40px;
  background: linear-gradient(135deg, rgba(231,90,155,.32), rgba(255,195,216,.50));
}

.hero-card-small h2 {
  margin: 12px 0 0;
  font-size: 55px;
  line-height: 1.2;
  letter-spacing: -0.5px;
}

.hero-card-small p {
  margin: 12px 0 0;
  color: #7A5570;
  line-height: 1.5;
  font-size: 20px;
}

.stats-row {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.stat-card-small {
  background: var(--paper);
  border: 1px solid rgba(255,255,255,.55);
  border-radius: var(--radius-lg);
  padding: 16px 16px;
  display: flex;
  align-items: center;
  gap: 12px;
  transition: transform 0.2s ease;
}

.stat-card-small:hover {
  transform: translateY(-2px);
}

.stat-icon-small {
  width: 30px;
  height: 30px;
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: 14px;
  background: rgba(231,90,155,.12);
  color: var(--hot-pink);
  font-size: 20px;
  flex-shrink: 0;
}

.stat-content {
  flex: 1;
}

.stat-label {
  display: block;
  font-size: 12px;
  color: var(--muted);
  font-weight: 600;
  letter-spacing: 0.3px;
}

.stat-number {
  display: block;
  font-size: 28px;
  font-weight: 800;
  color: var(--ink);
  line-height: 1.2;
  margin: 4px 0 2px;
}

.stat-period {
  display: block;
  font-size: 10px;
  color: var(--muted);
}

/* Responsive */
@media (max-width: 900px) {
  .hero-grid-new {
    grid-template-columns: 1fr;
  }
  
  .stats-row {
    flex-direction: row;
  }
  
  .stat-card-small {
    flex: 1;
  }
}

@media (max-width: 600px) {
  .stats-row {
    flex-direction: column;
  }
}
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
    /* ── SCHEDULE WIDGET ── */
.schedule-widget {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
    margin-top: 20px;
    margin-bottom: 20px;
}

.schedule-cal-panel, .schedule-upcoming-panel {
    background: var(--paper);
    border: 1px solid rgba(255,255,255,.55);
    border-radius: var(--radius-xl);
    box-shadow: var(--shadow);
    padding: 22px;
}

.sch-cal-header {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 8px;
  margin-bottom: 15px;
}

.sch-cal-title {
  font-size: 16px;
  font-weight: 800;
  color: var(--ink);
}

.sch-month-wrapper {
  display: flex;
  justify-content: center;
  width: 100%;
}

.sch-cal-nav {
  display: flex;
  align-items: center;
  gap: 12px;
  background: rgba(255,255,255,.7);
  padding: 5px 12px;
  border-radius: 40px;
}

.sch-cal-nav button {
  background: none;
  border: none;
  font-size: 18px;
  cursor: pointer;
  color: var(--pink-dark);
  font-weight: bold;
  padding: 0 8px;
}

.sch-cal-nav button:hover {
  color: var(--hot-pink);
}

#schMonthLabel {
  font-size: 13px;
  font-weight: 700;
  color: var(--ink);
  min-width: 100px;
  text-align: center;
}
.sch-cal-nav button:hover { background: var(--hot-pink); color: white; border-color: var(--hot-pink); }
.sch-cal-nav span { font-size: 12px; font-weight: 700; color: var(--muted); min-width: 80px; text-align: center; }
.sch-cal-grid { display: grid; grid-template-columns: repeat(7,1fr); gap: 3px; }
.sch-day-name { font-size: 9px; text-align: center; color: var(--muted); font-weight: 700; text-transform: uppercase; padding: 4px 0; letter-spacing: .4px; }
.sch-day {
    font-size: 12px; text-align: center; padding: 7px 3px;
    border-radius: 8px; cursor: pointer; color: var(--muted);
    position: relative; transition: .13s ease; font-weight: 500;
}
.sch-day:hover { background: rgba(242,138,178,.15); color: var(--ink); }
.sch-day.today {
    background: linear-gradient(135deg, var(--hot-pink), var(--pink));
    color: white; font-weight: 900;
    box-shadow: 0 4px 12px rgba(231,90,155,.3);
}
.sch-day.has-booking::after {
    content: '';
    position: absolute; bottom: 3px; left: 50%; transform: translateX(-50%);
    width: 4px; height: 4px; border-radius: 50%;
    background: var(--pink-dark);
}
.sch-day.today.has-booking::after { background: rgba(255,255,255,.7); }
.sch-day.other-month { opacity: .28; }
.sch-day.selected {
    background: rgba(242,138,178,.2);
    color: var(--pink-dark); font-weight: 700;
    border: 1.5px solid rgba(231,90,155,.35);
}
.sch-day.selected.today {
    background: linear-gradient(135deg, var(--hot-pink), var(--pink));
    color: white; border-color: transparent;
}

/* Today badge */
.today-badge {
    display: flex; align-items: center; gap: 10px;
    background: linear-gradient(135deg, rgba(231,90,155,.08), rgba(242,138,178,.06));
    border: 1px solid rgba(242,138,178,.2);
    border-radius: 14px; padding: 10px 14px;
    margin-bottom: 14px;
}
.today-badge-dot {
    width: 8px; height: 8px; border-radius: 50%;
    background: var(--hot-pink); flex-shrink: 0;
    box-shadow: 0 0 0 4px rgba(231,90,155,.15);
}
.today-badge-text { font-size: 12px; font-weight: 700; color: var(--ink); }
.today-badge-date { font-size: 11px; color: var(--muted); margin-top: 1px; }

/* Booking popup */
.sch-day-detail {
    margin-top: 14px;
    border-radius: 14px;
    overflow: hidden;
    border: 1px solid rgba(242,138,178,.18);
    transition: .2s ease;
}
.sch-day-detail-header {
    padding: 10px 14px;
    background: linear-gradient(135deg, rgba(231,90,155,.1), rgba(242,138,178,.06));
    font-size: 12px; font-weight: 900; color: var(--pink-dark);
    border-bottom: 1px solid rgba(242,138,178,.15);
}
.sch-booking-item {
    display: flex; align-items: center; gap: 10px;
    padding: 10px 14px;
    border-bottom: 1px solid rgba(242,138,178,.08);
    background: rgba(255,255,255,.7);
    cursor: pointer; transition: .12s ease;
}
.sch-booking-item:last-child { border-bottom: none; }
.sch-booking-item:hover { background: rgba(255,241,246,.8); }
.sch-booking-dot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }
.sch-booking-dot.confirmed { background: #3B8BE0; }
.sch-booking-dot.pending { background: #EF9F27; }
.sch-booking-dot.accepted { background: #9B59B6; }
.sch-booking-dot.completed { background: #52A43C; }
.sch-booking-time { font-size: 10px; font-weight: 700; color: var(--muted); min-width: 52px; }
.sch-booking-info strong { font-size: 12px; font-weight: 700; color: var(--ink); display: block; }
.sch-booking-info span { font-size: 10px; color: var(--muted); }
.sch-empty { padding: 16px; text-align: center; font-size: 12px; color: var(--muted); font-weight: 600; }

/* Upcoming panel */
.sch-upcoming-header {
    display: flex; align-items: center; justify-content: space-between;
    margin-bottom: 14px;
}
.sch-upcoming-title { font-size: 15px; font-weight: 900; color: var(--ink); }
.sch-upcoming-link { font-size: 12px; color: var(--pink-dark); font-weight: 700; }
.sch-session-item {
    display: flex; align-items: center; gap: 12px;
    padding: 11px 0;
    border-bottom: 1px solid rgba(242,138,178,.1);
}
.sch-session-item:last-child { border-bottom: none; padding-bottom: 0; }
.sch-session-date {
  min-width: 55px;
  text-align: center;
  background: rgba(255,241,246,.9);
  border: 1px solid rgba(242,138,178,.18);
  border-radius: 10px;
  padding: 6px 4px;
  flex-shrink: 0;
}

.session-month {
  font-size: 9px;
  font-weight: 800;
  color: var(--hot-pink);
  text-transform: uppercase;
  letter-spacing: 0.5px;
  margin-bottom: 2px;
}

.sch-session-date .day-num {
  font-size: 18px;
  font-weight: 900;
  color: var(--hot-pink);
  line-height: 1;
}

.sch-session-date .day-name {
  font-size: 8px;
  color: var(--muted);
  font-weight: 700;
  text-transform: uppercase;
  margin-top: 2px;
}
.sch-session-body strong { font-size: 13px; font-weight: 700; color: var(--ink); display: block; }
.sch-session-body span { font-size: 11px; color: var(--muted); display: block; margin-top: 2px; }
.sch-session-badge {
    font-size: 10px; padding: 3px 9px; border-radius: 6px; font-weight: 700; white-space: nowrap; flex-shrink: 0;margin-left: auto;
}
.sch-session-badge.confirmed { background: #E0EFFF; color: #2060A0; }
.sch-session-badge.pending   { background: #FFF5DF; color: #996600; }
.sch-session-badge.accepted  { background: #F0E8FF; color: #7030B8; }

@media (max-width: 900px) {
    .schedule-widget { grid-template-columns: 1fr; }
}

/* Current Time & Date */
.current-datetime {
  text-align: left;
  padding: 12px;
  margin-bottom: 20px;
  border-radius: 20px;
  border: 1px solid rgba(242,138,178,.15);
}

.current-time {
  font-size: 32px;
  font-weight: 800;
  color: black;
  letter-spacing: 1px;
}

.current-date {
  font-size: 13px;
  color: var(--muted);
  margin-top: 5px;
  font-weight: 500;
}

/* Self-Paced Learning Styles */
.hub-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 20px;
  flex-wrap: wrap;
  gap: 12px;
}

.hub-header h3 {
  margin: 0;
  font-size: 20px;
}

.hub-header p {
  margin: 4px 0 0;
  font-size: 12px;
  color: var(--muted);
}

.learning-stats {
  display: flex;
  gap: 15px;
  background: rgba(231,90,155,.1);
  padding: 6px 15px;
  border-radius: 30px;
  font-size: 12px;
  font-weight: 700;
}

.learning-stats i {
  margin-right: 4px;
  color: var(--hot-pink);
}

.learning-card {
  display: flex;
  gap: 16px;
  background: rgba(255,241,246,.7);
  border-radius: 24px;
  padding: 18px;
  margin-bottom: 16px;
  transition: all 0.2s ease;
  border-left: 4px solid;
}

.learning-card.business {
  border-left-color: #2E7D64;
}

.learning-card.casual {
  border-left-color: #E87A5D;
}

.learning-card:hover {
  transform: translateX(5px);
  background: rgba(255,241,246,.95);
}

.learning-icon {
  font-size: 48px;
  flex-shrink: 0;
}

.learning-content {
  flex: 1;
}

.learning-content h4 {
  margin: 0 0 6px;
  font-size: 18px;
  font-weight: 800;
}

.learning-content p {
  margin: 0 0 10px;
  font-size: 12px;
  color: var(--muted);
}

.learning-progress {
  margin-bottom: 10px;
}

.progress-bar {
  height: 6px;
  background: rgba(46,42,59,.1);
  border-radius: 10px;
  overflow: hidden;
  margin-bottom: 5px;
}

.progress-fill {
  height: 100%;
  background: linear-gradient(90deg, var(--pink), var(--hot-pink));
  border-radius: 10px;
}

.progress-text {
  font-size: 11px;
  color: var(--muted);
  font-weight: 600;
}

.learning-modules-list {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.module-tag {
  font-size: 10px;
  padding: 3px 10px;
  background: rgba(231,90,155,.1);
  border-radius: 20px;
  color: var(--hot-pink);
}

.module-tag.locked {
  background: rgba(123,97,120,.1);
  color: var(--muted);
}

.learn-btn {
  background: linear-gradient(135deg, var(--hot-pink), var(--pink));
  color: white;
  border: none;
  border-radius: 30px;
  padding: 8px 20px;
  font-size: 12px;
  font-weight: 700;
  cursor: pointer;
  white-space: nowrap;
  height: 40px;
  margin-top: auto;
}

.learn-btn:hover {
  transform: scale(1.02);
  box-shadow: 0 4px 12px rgba(231,90,155,.3);
}

/* Weekly Friends Challenge */
.weekly-friends-challenge {
  background: linear-gradient(135deg, rgba(231,90,155,.06), rgba(242,138,178,.03));
  border-radius: 20px;
  padding: 16px;
  margin: 20px 0;
  border: 1px solid rgba(242,138,178,.15);
}

.challenge-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 16px;
  flex-wrap: wrap;
  gap: 12px;
}

.challenge-header h4 {
  margin: 0;
  font-size: 15px;
}

.challenge-header p {
  margin: 4px 0 0;
  font-size: 11px;
  color: var(--muted);
}

.invite-friends-btn {
  background: linear-gradient(135deg, var(--hot-pink), var(--pink));
  color: white;
  border: none;
  border-radius: 30px;
  padding: 8px 16px;
  font-size: 12px;
  font-weight: 700;
  cursor: pointer;
  display: flex;
  align-items: center;
  gap: 6px;
}

.challenge-leaderboard {
  margin-bottom: 16px;
}

.challenge-item {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 8px 12px;
  background: rgba(255,255,255,.5);
  border-radius: 14px;
  margin-bottom: 6px;
}

.challenge-item.rank-1 {
  background: linear-gradient(135deg, rgba(255,215,0,.12), rgba(255,215,0,.04));
  border-left: 3px solid #FFD700;
}

.challenge-item.rank-2 {
  background: linear-gradient(135deg, rgba(192,192,192,.12), rgba(192,192,192,.04));
  border-left: 3px solid #C0C0C0;
}

.challenge-item.rank-3 {
  background: linear-gradient(135deg, rgba(205,127,50,.12), rgba(205,127,50,.04));
  border-left: 3px solid #CD7F32;
}

.rank-badge {
  font-weight: 900;
  width: 40px;
  font-size: 14px;
}

.friend-name {
  flex: 1;
  font-weight: 600;
  font-size: 13px;
}

.friend-xp {
  font-weight: 800;
  color: var(--hot-pink);
  font-size: 12px;
}

.friend-status {
  font-size: 10px;
  color: var(--muted);
}

/* Weekly Goal */
.weekly-goal {
  margin-top: 12px;
  padding-top: 12px;
  border-top: 1px solid rgba(242,138,178,.15);
}

.goal-text {
  display: flex;
  justify-content: space-between;
  font-size: 11px;
  margin-bottom: 8px;
  font-weight: 600;
}

.goal-bar {
  height: 8px;
  background: rgba(46,42,59,.1);
  border-radius: 10px;
  overflow: hidden;
  margin-bottom: 8px;
}

.goal-fill {
  height: 100%;
  background: linear-gradient(90deg, #FFD700, #FFA500);
  border-radius: 10px;
}

.goal-reward {
  font-size: 10px;
  color: var(--muted);
  text-align: center;
}

.goal-reward i {
  color: var(--hot-pink);
}

/* Daily Quick Practice */
.daily-quick-practice {
  background: linear-gradient(135deg, rgba(231,90,155,.08), rgba(242,138,178,.04));
  border-radius: 20px;
  padding: 14px;
  margin-top: 8px;
}

.quick-header {
  display: flex;
  justify-content: space-between;
  margin-bottom: 12px;
  font-size: 12px;
  font-weight: 700;
}

.quick-timer {
  color: var(--hot-pink);
  font-size: 10px;
}

.quick-options {
  display: flex;
  gap: 10px;
}

.quick-btn {
  flex: 1;
  background: white;
  border: 1px solid rgba(242,138,178,.3);
  border-radius: 12px;
  padding: 8px;
  font-size: 11px;
  font-weight: 700;
  color: var(--ink);
  cursor: pointer;
  transition: all 0.2s;
}

.quick-btn i {
  margin-right: 5px;
  color: var(--hot-pink);
}

.quick-btn:hover {
  background: var(--hot-pink);
  color: white;
}

@media (max-width: 768px) {
  .learning-card {
    flex-direction: column;
    text-align: center;
  }
  .learn-btn {
    width: 100%;
  }
  .quick-options {
    flex-direction: column;
  }
}
/* Tutor Stats Styles */
.tutor-stats-header {
  margin-bottom: 16px;
}

.tutor-stats-header h4 {
  margin: 0 0 4px;
  font-size: 18px;
}

.stats-subtitle {
  font-size: 11px;
  color: var(--muted);
}

.top-tutor-card {
  background: linear-gradient(135deg, rgba(231,90,155,.1), rgba(242,138,178,.05));
  border-radius: 20px;
  padding: 16px;
  margin-bottom: 16px;
  border: 1px solid rgba(242,138,178,.2);
}

.top-tutor-badge {
  background: linear-gradient(135deg, #FFD700, #FFA500);
  color: #fff;
  font-size: 10px;
  font-weight: 800;
  padding: 4px 10px;
  border-radius: 20px;
  display: inline-block;
  margin-bottom: 12px;
}

.top-tutor-info {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 15px;
}

.top-tutor-info img {
  width: 50px;
  height: 50px;
  border-radius: 50%;
  object-fit: cover;
}

.top-tutor-info h5 {
  margin: 0 0 4px;
  font-size: 15px;
}

.top-tutor-rating {
  font-size: 11px;
  color: var(--muted);
}

.top-tutor-stats {
  display: flex;
  justify-content: space-around;
  margin-bottom: 15px;
  padding: 10px 0;
  border-top: 1px solid rgba(242,138,178,.15);
  border-bottom: 1px solid rgba(242,138,178,.15);
}

.stat-item {
  text-align: center;
}

.stat-number {
  display: block;
  font-size: 18px;
  font-weight: 800;
  color: var(--hot-pink);
}

.stat-label {
  font-size: 10px;
  color: var(--muted);
}

.view-tutor-btn {
  display: block;
  text-align: center;
  background: rgba(231,90,155,.1);
  border-radius: 30px;
  padding: 8px;
  font-size: 12px;
  font-weight: 700;
  color: var(--hot-pink);
  transition: all 0.2s;
}

.view-tutor-btn:hover {
  background: var(--hot-pink);
  color: white;
}

.quick-stats-summary {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.quick-stat {
  display: flex;
  align-items: center;
  gap: 12px;
  background: rgba(255,241,246,.6);
  border-radius: 14px;
  padding: 10px 12px;
}

.quick-stat i {
  font-size: 22px;
  color: var(--hot-pink);
}

.quick-stat strong {
  display: block;
  font-size: 16px;
  font-weight: 800;
}

.quick-stat span {
  font-size: 10px;
  color: var(--muted);
}

.empty-state-small {
  text-align: center;
  padding: 30px 20px;
  background: rgba(255,241,246,.6);
  border-radius: 16px;
  font-size: 12px;
  color: var(--muted);
}

@media (max-width: 900px) {
  .preferences-section .panel > div {
    grid-template-columns: 1fr !important;
    gap: 30px !important;
  }
}

/* Top 3 Tutors Ranking Styles */
.tutor-ranking-list {
  margin-bottom: 20px;
}

.tutor-rank-item {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 14px;
  background: rgba(255, 241, 246, 0.6);
  border-radius: 20px;
  margin-bottom: 10px;
  transition: all 0.2s ease;
}

.tutor-rank-item:hover {
  background: rgba(255, 241, 246, 0.9);
  transform: translateX(4px);
}

.tutor-rank-item.gold {
  background: linear-gradient(135deg, rgba(255, 215, 0, 0.15), rgba(255, 215, 0, 0.05));
  border-left: 4px solid #FFD700;
}

.tutor-rank-item.silver {
  background: linear-gradient(135deg, rgba(192, 192, 192, 0.15), rgba(192, 192, 192, 0.05));
  border-left: 4px solid #C0C0C0;
}

.tutor-rank-item.bronze {
  background: linear-gradient(135deg, rgba(205, 127, 50, 0.15), rgba(205, 127, 50, 0.05));
  border-left: 4px solid #CD7F32;
}

.rank-number {
  font-size: 32px;
  font-weight: 800;
  width: 55px;
  text-align: center;
}

.rank-avatar img {
  width: 50px;
  height: 50px;
  border-radius: 50%;
  object-fit: cover;
}

.rank-info {
  flex: 1;
}

.rank-name {
  font-weight: 800;
  font-size: 15px;
  color: var(--ink);
}

.rank-rating {
  font-size: 11px;
  color: var(--muted);
  margin-top: 3px;
}

.rank-lessons {
  text-align: right;
}

.rank-lessons strong {
  display: block;
  font-size: 20px;
  font-weight: 800;
  color: var(--hot-pink);
}

.rank-lessons span {
  font-size: 10px;
  color: var(--muted);
}

/* Two column layout */
.preferences-section .panel > div {
  display: grid;
  grid-template-columns: 1.8fr 1fr;
  gap: 24px;
  align-items: start;
}

@media (max-width: 900px) {
  .preferences-section .panel > div {
    grid-template-columns: 1fr !important;
  }
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
          <a class="active" href="student_dashboard.php">Home</a>
          <a href="find_language.php">Find Language</a>
          <a href="booking_status.php">My Bookings</a>
          <a href="my_payments.php">My Payments</a>
          <a href="my_materials.php">My Materials</a>
        </div>

        <div class="nav-actions">
          <div class="search">
            <i class="bi bi-search"></i>
            <input id="globalSearch" type="text" placeholder="Search language..."
              onclick="openSearch()" readonly style="cursor:pointer;">
          </div>
          <div style="position:relative;">
          <button class="icon-btn" onclick="toggleNotifications()" id="bellBtn">
    <i class="bi bi-bell"></i>
    <span class="dot" id="notifDot" style="display: <?php echo $unreadNotifCount > 0 ? 'block' : 'none'; ?>;"></span>
</button>

          <!-- Notification dropdown -->
          <div id="notifDropdown" style="display:none;position:absolute;top:calc(100% + 10px);right:0;background:white;border-radius:20px;box-shadow:0 18px 45px rgba(201,79,134,.2);border:1px solid rgba(242,138,178,.2);width:320px;overflow:hidden;z-index:100;">
            <div style="display:flex;justify-content:space-between;align-items:center;padding:14px 16px;border-bottom:1px solid rgba(242,138,178,.15);">
              <strong style="font-size:14px;color:#342635;">Notifications</strong>
              <button onclick="markAllRead()" style="background:none;border:none;color:#E75A9B;font-size:12px;font-weight:900;cursor:pointer;">Mark all read</button>
            </div>
            <div id="notifList" style="max-height:320px;overflow-y:auto;">
              <div style="padding:20px;text-align:center;color:#9080a0;font-size:13px;">Loading...</div>
            </div>
          </div>
        </div>
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
    <section class="hero" id="overview">
  <div class="hero-grid-new">
    <!-- Left: Smaller Hero Card -->
    <article class="hero-card-small glass">
      <div class="hero-copy">
        <div class="eyebrow"><span class="pulse"></span><span>Student dashboard</span></div>
        <h2>Good <span id="dynamicGreeting"></span>, <?= e($firstName) ?> ! <span id="greetingEmoji"></h2>
        <p>Your language journey is ready. Continue where you left off.</p>
      </div><br>
      <div class="hero-actions">
        <button class="btn-primary" onclick="window.location='find_language.php'">Find a tutor</button>
        <button class="btn-soft" onclick="window.location='booking_status.php'">My bookings</button>
      </div>
    </article>
    <div class="stats-row">
  <!-- Stat 1: Sessions Booked This Month -->
  <div class="stat-card-small">
    <div class="stat-icon-small"><i class="bi bi-calendar-check"></i></div>
    <div class="stat-content">
      <span class="stat-label">Sessions booked</span>
      <strong class="stat-number">
        <?php 
        $monthStart = date('Y-m-01');
        $monthEnd = date('Y-m-t');
        $stmtBooked = $conn->prepare("SELECT COUNT(*) AS cnt FROM bookings WHERE student_id = ? AND created_at BETWEEN ? AND ?");
        $stmtBooked->bind_param("iss", $userID, $monthStart, $monthEnd);
        $stmtBooked->execute();
        $bookedCount = $stmtBooked->get_result()->fetch_assoc()['cnt'];
        echo (int)$bookedCount;
        ?>
      </strong>
      <small class="stat-period">this month</small>
    </div>
  </div>
  
  <!-- Stat 2: Completed Classes This Month -->
  <div class="stat-card-small">
    <div class="stat-icon-small"><i class="bi bi-check-circle"></i></div>
    <div class="stat-content">
      <span class="stat-label">Completed</span>
      <strong class="stat-number">
        <?php 
        $stmtCompleted = $conn->prepare("SELECT COUNT(*) AS cnt FROM bookings WHERE student_id = ? AND status = 'completed' AND booking_date BETWEEN ? AND ?");
        $stmtCompleted->bind_param("iss", $userID, $monthStart, $monthEnd);
        $stmtCompleted->execute();
        $completedCountMonth = $stmtCompleted->get_result()->fetch_assoc()['cnt'];
        echo (int)$completedCountMonth;
        ?>
      </strong>
      <small class="stat-period">classes this month</small>
    </div>
  </div>
  
  <!-- Stat 3: Languages Learned This Month -->
  <div class="stat-card-small">
    <div class="stat-icon-small"><i class="bi bi-chat-dots"></i></div>
    <div class="stat-content">
      <span class="stat-label">
        Learned 
      </span>
      <strong class="stat-number">
        <?php 
        // Count distinct languages THIS MONTH
        $stmtLangCount = $conn->prepare("SELECT COUNT(DISTINCT language) AS cnt FROM bookings WHERE student_id = ? AND status = 'completed' AND booking_date BETWEEN ? AND ?");
        $stmtLangCount->bind_param("iss", $userID, $monthStart, $monthEnd);
        $stmtLangCount->execute();
        $langCountMonth = $stmtLangCount->get_result()->fetch_assoc()['cnt'];
        echo (int)$langCountMonth;
        ?>
      </strong>
      <small class="stat-period">languages this month <br>  <?php 
      // Get distinct languages from completed bookings THIS MONTH
      $stmtLangList = $conn->prepare("SELECT DISTINCT language FROM bookings WHERE student_id = ? AND status = 'completed' AND booking_date BETWEEN ? AND ?");
      $stmtLangList->bind_param("iss", $userID, $monthStart, $monthEnd);
      $stmtLangList->execute();
      $langListResult = $stmtLangList->get_result();
      $languagesThisMonth = [];
      while ($row = $langListResult->fetch_assoc()) {
          $languagesThisMonth[] = $row['language'];
      }
      
      if (count($languagesThisMonth) > 0) {
          echo '(' . implode(', ', $languagesThisMonth) . ')';
      } else {
          echo '(None)';
      }
      ?></small>
    </div>
  </div>
</div>
  </div>
  <!-- ── SCHEDULE WIDGET ── -->
<div class="schedule-widget">

  <!-- LEFT: Mini Calendar -->
  <div class="schedule-cal-panel">
    <div class="sch-cal-header">
  <span class="sch-cal-title" style="margin-bottom:20px;">My Schedule</span> 
  <div class="sch-month-wrapper">
    <div class="sch-cal-nav" style="margin-bottom:20px;">
      <button id="schPrev">‹</button>
      <span id="schMonthLabel"></span>
      <button id="schNext">›</button>
    </div>
  </div>
</div>
    <div class="sch-cal-grid" id="schGrid"></div>
    <div class="sch-day-detail" id="schDayDetail" style="display:none;"></div>
  </div>

  <!-- RIGHT: Today + Upcoming -->
  <div class="schedule-upcoming-panel">
    <!-- Today badge -->
    <!-- Current Time & Date -->
<div class="current-datetime">
  <div class="current-time" id="currentTime"></div>
  <div class="current-date" id="currentDate"></div>
</div>
<div class="sch-upcoming-header">
  <span class="sch-upcoming-title">Upcoming Sessions</span>
  <a href="booking_status.php" class="sch-upcoming-link">View all →</a>
</div><hr>

<?php if (empty($schedBookings)): ?>
  <div class="sch-empty">No upcoming sessions.<br>
    <a href="find_language.php" style="color:var(--pink-dark);font-weight:700;">Find a tutor →</a>
  </div>
<?php else: ?>
  <?php 
  $displayCount = 0;
  $currentMonth = '';
  foreach ($schedBookings as $sb):
    $displayCount++;
    if ($displayCount > 4) break; // Only show first 3
    
    $modeIcon = $sb['learning_mode'] === 'online' ? '💻' : '🤝';
    $badgeClass = in_array($sb['status'], ['confirmed','pending','accepted']) ? $sb['status'] : 'pending';
    $badgeLabel = ucfirst($sb['status']);
    
    // Get month name
    $sessionMonth = date('M', strtotime($sb['booking_date']));
    $sessionDay = date('d', strtotime($sb['booking_date']));
    $sessionWeekday = date('D', strtotime($sb['booking_date']));
  ?>
  <div class="sch-session-item">
    <div class="sch-session-date">
      <div class="session-month"><?= $sessionMonth ?></div>
      <div class="day-num"><?= $sessionDay ?></div>
      <div class="day-name"><?= $sessionWeekday ?></div>
    </div>
    <div class="sch-session-body">
      <strong><?= e($sb['language']) ?></strong>
      <span><?= date('g:i A', strtotime($sb['booking_time'])) ?> · <?= e($sb['tutor_name']) ?> · <?= $modeIcon ?></span>
    </div>
    <span class="sch-session-badge <?= $badgeClass ?>"><?= $badgeLabel ?></span>
  </div>
  <?php endforeach; ?>
  
<?php endif; ?>

</div>
</section>
        <section class="section preferences-section" id="preferences">
      <div class="panel glass">
        <!-- TWO COLUMN LAYOUT inside the panel -->
        <div style="display: grid; grid-template-columns: 1.6fr 1fr; gap: 24px;">
          
          <!-- LEFT SIDE: Recommended Tutors -->
          <div>
            <div class="recommend-head">
              <h4>🎓 Recommended Tutors</h4>
              <a href="search_tutors.php">View All →</a>
            </div>
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

          <div>
            <div class="tutor-stats-header">
              <h4>🏆 Top Tutors Ranking</h4>
              <span class="stats-subtitle">Most lessons taught this month</span>
            </div>

            <?php 
            // Get top 3 tutors by lessons taught this month
            $stmtTopTutors = $conn->prepare("
    SELECT 
        u.id,
        u.fullname,
        u.profile_pic,

        COUNT(DISTINCT b.id) AS total_sessions,

        ROUND(COALESCE(AVG(DISTINCT r.rating),0),1) AS avg_rating

    FROM users u
    JOIN tutor_profiles tp 
        ON u.id = tp.user_id

    LEFT JOIN bookings b 
        ON b.tutor_id = u.id
        AND b.status='completed'
        AND MONTH(b.booking_date)=MONTH(CURDATE())
        AND YEAR(b.booking_date)=YEAR(CURDATE())

    LEFT JOIN ratings r 
        ON r.tutor_id=u.id

    WHERE u.role='tutor'
    AND u.status='approved'

    GROUP BY u.id
    ORDER BY total_sessions DESC
    LIMIT 3
");
        
            $stmtTopTutors->execute();
            $topTutors = $stmtTopTutors->get_result()->fetch_all(MYSQLI_ASSOC);
            ?>

            <div class="tutor-ranking-list">
              <?php if (!empty($topTutors)): ?>
                <?php foreach ($topTutors as $index => $tutor): 
                  $rank = $index + 1;
                  if ($rank == 1) {
                    $medal = '🥇';
                    $medalColor = 'gold';
                  } elseif ($rank == 2) {
                    $medal = '🥈';
                    $medalColor = 'silver';
                  } else {
                    $medal = '🥉';
                    $medalColor = 'bronze';
                  }
                ?>
                <div class="tutor-rank-item <?= $medalColor ?>">
                  <div class="rank-number"><?= $medal ?></div>
                  <div class="rank-avatar">
                    <img src="<?= !empty($tutor['profile_pic']) ? '../uploads/profiles/' . $tutor['profile_pic'] : $assetBase . '/profile-tutor.png' ?>" alt="<?= e($tutor['fullname']) ?>">
                  </div>
                  <div class="rank-info">
                    <div class="rank-name"><?= e($tutor['fullname']) ?></div>
                    <div class="rank-rating">★ <?= $tutor['avg_rating'] ?></div>
                  </div>
                  <div class="rank-lessons">
                    <strong><?= $tutor['total_sessions'] ?></strong>
                    <span>lesson<?= $tutor['total_sessions'] != 1 ? 's' : '' ?></span>
                  </div>
                </div>
                <?php endforeach; ?>
              <?php else: ?>
                <div class="empty-state-small">No tutor data available yet.</div>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>
    </section>

  

    <div style="height:38px;"></div>
  </main>

  <div class="toast" id="toast">Saved</div>

<script>
  // 1. Global variables FIRST
  let activeFilters = { langs: [], modes: [], locations: [], days: [], timeslots: [], rating: 0 };
  let activeRatingBtn = null;
  let toastTimer;
  let notifOpen = false;
  
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

// 9. Filter functions - IMPROVED VERSION
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
        if (type === 'day')      activeFilters.days      = activeFilters.days.filter(v => v !== val);
        if (type === 'timeslot') activeFilters.timeslots = activeFilters.timeslots.filter(v => v !== val);
    } else {
        el.classList.add('chip-active');
        el.style.background = 'linear-gradient(135deg,#E75A9B,#F28AB2)';
        el.style.color = 'white';
        el.style.borderColor = '#E75A9B';
        if (type === 'lang')     activeFilters.langs.push(val);
        if (type === 'mode')     activeFilters.modes.push(val);
        if (type === 'location') activeFilters.locations.push(val);
        if (type === 'day')      activeFilters.days.push(val);
        if (type === 'timeslot') activeFilters.timeslots.push(val);
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
    const to   = parseFloat(document.getElementById('priceTo').value) || 100;
    const hasFilters = activeFilters.langs.length > 0
        || activeFilters.modes.length > 0
        || activeFilters.locations.length > 0
        || activeFilters.days?.length > 0
        || activeFilters.timeslots?.length > 0
        || activeFilters.rating > 0
        || from > 0 || to < 100;
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
    activeFilters = { langs: [], modes: [], locations: [], days: [], timeslots: [], rating: 0 };
    document.getElementById('priceFrom').value = 0;
    document.getElementById('priceTo').value   = 100;
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

// IMPROVED filterTutors function - now checks availability and uses STARTS WITH for language
function filterTutors() {
    const searchVal = document.getElementById('tutorSearchInput').value.toLowerCase().trim();
    const fromPrice = parseFloat(document.getElementById('priceFrom').value) || 0;
    const toPrice   = parseFloat(document.getElementById('priceTo').value) || 100;

    const items = document.querySelectorAll('.search-tutor-item');
    let visibleCount = 0;

    items.forEach(item => {
        const langs    = (item.dataset.lang || '').split(',').map(l => l.trim().toLowerCase()).filter(Boolean);
        const modes    = (item.dataset.mode || '').split(',').map(m => m.trim().toLowerCase()).filter(Boolean);
        const location = (item.dataset.location || '').toLowerCase().trim();
        const rate     = parseFloat(item.dataset.rate || 0);
        const rating   = parseFloat(item.dataset.rating || 0);
        const availability = item.dataset.availability || '';
        
        // IMPROVED: Search matches if ANY language STARTS WITH the search term
        let searchMatch = false;
        if (searchVal === '') {
            searchMatch = true;
        } else {
            for (let lang of langs) {
                if (lang.startsWith(searchVal)) {
                    searchMatch = true;
                    break;
                }
            }
        }
        
        const priceMatch    = rate >= fromPrice && rate <= toPrice;
        const langMatch     = activeFilters.langs.length === 0 || activeFilters.langs.some(fl => langs.some(l => l.startsWith(fl)));
        const modeMatch     = activeFilters.modes.length === 0 || activeFilters.modes.some(fm => modes.some(m => m.includes(fm)));
        const locationMatch = activeFilters.locations.length === 0 || activeFilters.locations.some(loc => location.includes(loc));
        const ratingMatch   = activeFilters.rating === 0 || rating >= activeFilters.rating;
        
        // Availability filter
        let availabilityMatch = true;
        if (activeFilters.days && activeFilters.days.length > 0) {
            availabilityMatch = activeFilters.days.some(day => availability.includes(day));
        }

        const show = searchMatch && priceMatch && langMatch && modeMatch && locationMatch && ratingMatch && availabilityMatch;
        item.style.display = show ? 'flex' : 'none';
        if (show) visibleCount++;
    });

    const rc = document.getElementById('resultCount');
    if (rc) rc.textContent = visibleCount + ' tutor' + (visibleCount !== 1 ? 's' : '') + ' found';
}

// ── NOTIFICATIONS SYSTEM ─

function toggleNotifications() {
    notifOpen = !notifOpen;
    const dd = document.getElementById('notifDropdown');
    dd.style.display = notifOpen ? 'block' : 'none';
    if (notifOpen) loadNotifications();
}

function loadNotifications() {
    fetch('get_notifications.php')
        .then(r => r.json())
        .then(data => {
            const dot = document.getElementById('notifDot');
            const list = document.getElementById('notifList');

            // Update red dot based on unread count
            if (dot) {
                if (data.count > 0) {
                    dot.style.display = 'block';
                    // Add animation for visibility
                    dot.style.animation = 'pulse 1s infinite';
                } else {
                    dot.style.display = 'none';
                    dot.style.animation = 'none';
                }
            }

            if (!data.notifications || data.notifications.length === 0) {
                list.innerHTML = '<div style="padding:20px;text-align:center;color:#9080a0;font-size:13px;">No notifications yet.</div>';
                return;
            }

            list.innerHTML = data.notifications.map(n => `
                <div onclick="markRead(${n.id}, this, '${n.link || ''}')"
                    style="padding:14px 16px;border-bottom:1px solid rgba(242,138,178,.08);cursor:pointer;
                           background:${n.is_read ? 'white' : 'rgba(255,241,246,.6)'};transition:.15s ease;"
                    onmouseover="this.style.background='#FFF1F6'"
                    onmouseout="this.style.background='${n.is_read ? 'white' : 'rgba(255,241,246,.6)'}'">
                    <div style="display:flex;align-items:flex-start;gap:10px;">
                        <div style="width:8px;height:8px;border-radius:50%;
                                    background:${n.is_read ? 'transparent' : '#E75A9B'};
                                    flex-shrink:0;margin-top:5px;"></div>
                        <div style="flex:1;min-width:0;">
                            <strong style="display:block;font-size:13px;color:#342635;">${escapeHtml(n.title)}</strong>
                            <p style="margin:3px 0 0;font-size:12px;color:#7B6178;line-height:1.4;">${escapeHtml(n.message)}</p>
                            <span style="display:block;margin-top:4px;font-size:11px;color:#aaa;">${timeAgo(n.created_at)}</span>
                        </div>
                    </div>
                </div>
            `).join('');
        })
        .catch(() => {
            document.getElementById('notifList').innerHTML =
                '<div style="padding:20px;text-align:center;color:#9080a0;font-size:13px;">Could not load notifications.</div>';
        });
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function markRead(id, el, link) {
    const formData = new FormData();
    formData.append('id', id);

    fetch('mark_notification_read.php', {
        method: 'POST',
        body: formData
    }).then(() => {
        // After marking as read, check unread count again
        checkUnreadCount();
        if (notifOpen) {
            loadNotifications();
        }
    });

    el.style.background = 'white';

    const unreadDot = el.querySelector('[style*="border-radius:50%"]');
    if (unreadDot) {
        unreadDot.style.background = 'transparent';
    }

    if (link) {
        window.location.href = link;
    }
}

function markAllRead() {
    const formData = new FormData();
    formData.append('id', 0);
    fetch('mark_notification_read.php', { method: 'POST', body: formData })
        .then(() => {
            checkUnreadCount();
            if (notifOpen) {
                loadNotifications();
            }
        });
}

function timeAgo(dateStr) {
    const diff = Math.floor((new Date() - new Date(dateStr)) / 1000);
    if (diff < 60) return 'Just now';
    if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
    if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
    return Math.floor(diff / 86400) + 'd ago';
}

// Single checkUnreadCount function - this updates the red dot
function checkUnreadCount() {
    fetch('get_notifications.php', {
        method: 'GET',
        headers: {
            'Cache-Control': 'no-cache',
            'Pragma': 'no-cache'
        }
    })
    .then(response => {
        if (!response.ok) throw new Error('Network response was not ok');
        return response.json();
    })
    .then(data => {
        const dot = document.getElementById('notifDot');
        
        if (dot) {
            if (data.count > 0) {
                dot.style.display = 'block';
                dot.style.animation = 'pulse 1s infinite';
            } else {
                dot.style.display = 'none';
                dot.style.animation = 'none';
            }
        }
        
        // If notification dropdown is currently open, refresh the content too
        if (notifOpen) {
            loadNotifications();
        }
    })
    .catch(error => {
        console.error('Error checking notifications:', error);
    });
}

// Auto-check function that runs on page load and periodically
function startAutoNotificationCheck() {
    // Check immediately when page loads
    checkUnreadCount();
    
    // Check every 10 seconds for new notifications
    setInterval(checkUnreadCount, 10000);
    
    // Check when window regains focus (user returns to tab)
    window.addEventListener('focus', function() {
        checkUnreadCount();
    });
    
    // Check when page becomes visible again after being hidden
    document.addEventListener('visibilitychange', function() {
        if (!document.hidden) {
            checkUnreadCount();
        }
    });
}

// Add CSS animation for the dot if not already added
if (!document.querySelector('#notification-dot-style')) {
    const style = document.createElement('style');
    style.id = 'notification-dot-style';
    style.textContent = `
        @keyframes pulse {
            0% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.5; transform: scale(1.3); }
            100% { opacity: 1; transform: scale(1); }
        }
        #notifDot {
            transition: all 0.3s ease;
        }
    `;
    document.head.appendChild(style);
}

// Start auto-checking when page loads
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', startAutoNotificationCheck);
} else {
    startAutoNotificationCheck();
}

// Close dropdown when clicking outside
document.addEventListener('click', function(e) {
    const bell = document.getElementById('bellBtn');
    const dd = document.getElementById('notifDropdown');
    if (bell && dd && !bell.contains(e.target) && !dd.contains(e.target)) {
        dd.style.display = 'none';
        notifOpen = false;
    }
});
    
</script>
<script>
// Dynamic greeting based on user's local time
function getTimeSlot($time) {
    $hour = date('H', strtotime($time));
    if ($hour < 12) return 'morning';
    if ($hour < 18) return 'afternoon';
    return 'evening';
}

function updateGreeting() {
    const now = new Date();
    const hour = now.getHours();
    let greeting = '';
    let emoji = '';
    
    if (hour >= 5 && hour < 12) {
        greeting = 'Morning';
        emoji = '🌅';
    } else if (hour >= 12 && hour < 18) {
        greeting = 'Afternoon';
        emoji = '☕';
    } else if (hour >= 18 && hour < 22) {
        greeting = 'Evening';
        emoji = '🌆';
    } else {
        greeting = 'Night';
        emoji = '🌃';
    }
    
    const greetingSpan = document.getElementById('dynamicGreeting');
    const emojiSpan = document.getElementById('greetingEmoji');
    
    if (greetingSpan) {
        greetingSpan.textContent = greeting;
    }
    if (emojiSpan) {
        emojiSpan.textContent = emoji;
    }
}

// Call it when page loads
updateGreeting();
</script>
<script>
  // ── SCHEDULE CALENDAR ──
const schedData = <?= $schedJSON ?>;
let schY = <?= $calYearJS ?>, schM = <?= $calMonthJS ?>;
const schMonths = ['January','February','March','April','May','June','July','August','September','October','November','December'];
const schDays = ['S','M','T','W','T','F','S'];
// Update current time and date automatically every minute
function updateCurrentDateTime() {
  const now = new Date();
  
  // Format time like "10:17 PM" - updates every minute
  const timeStr = now.toLocaleTimeString('en-MY', { hour: '2-digit', minute: '2-digit' });
  
  // Format date like "Wednesday, 13 May 2026"
  const dateStr = now.toLocaleDateString('en-MY', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
  
  const timeEl = document.getElementById('currentTime');
  const dateEl = document.getElementById('currentDate');
  
  if (timeEl) timeEl.textContent = timeStr;
  if (dateEl) dateEl.textContent = dateStr;
}

// Update immediately when page loads
updateCurrentDateTime();

// Update automatically every minute (60000 milliseconds = 1 minute)
setInterval(updateCurrentDateTime, 60000);



// Update every minute
setInterval(updateCurrentDateTime, 60000);
function renderSchCal() {
  document.getElementById('schMonthLabel').textContent = schMonths[schM].slice(0,3) + ' ' + schY;
  const fd  = new Date(schY, schM, 1).getDay();
  const dim = new Date(schY, schM + 1, 0).getDate();
  const dip = new Date(schY, schM, 0).getDate();
  const td  = new Date();

  let h = schDays.map(d => `<div class="sch-day-name">${d}</div>`).join('');

  for (let i = 0; i < fd; i++) {
    h += `<div class="sch-day other-month">${dip - fd + 1 + i}</div>`;
  }
  for (let d = 1; d <= dim; d++) {
    const dateStr = schY + '-' + String(schM+1).padStart(2,'0') + '-' + String(d).padStart(2,'0');
    const isToday = d === td.getDate() && schM === td.getMonth() && schY === td.getFullYear();
    const hasB    = schedData[dateStr] && schedData[dateStr].length > 0;
    h += `<div class="sch-day${isToday?' today':''}${hasB?' has-booking':''}"
              onclick="schSelectDay('${dateStr}', this)">${d}</div>`;
  }
  const rem = 42 - fd - dim;
  for (let d = 1; d <= Math.min(rem, 7); d++) {
    h += `<div class="sch-day other-month">${d}</div>`;
  }

  document.getElementById('schGrid').innerHTML = h;
  
  // Auto-select today's date after rendering
  const todayDateStr = td.getFullYear() + '-' + String(td.getMonth()+1).padStart(2,'0') + '-' + String(td.getDate()).padStart(2,'0');
  const todayElement = document.querySelector('.sch-day.today');
  if (todayElement) {
    schSelectDay(todayDateStr, todayElement);
  } else {
    // If today is not in current month, clear detail
    document.getElementById('schDayDetail').style.display = 'none';
  }
}
function schSelectDay(dateStr, el) {
  const detail = document.getElementById('schDayDetail');
  
  // Remove selected class from all days
  document.querySelectorAll('.sch-day.selected').forEach(e => e.classList.remove('selected'));
  el.classList.add('selected');

  const bookings = schedData[dateStr];
  const label = new Date(dateStr + 'T00:00:00').toLocaleDateString('en-MY', { weekday:'long', day:'numeric', month:'long' });
    
  if (!bookings || bookings.length === 0) {
    detail.innerHTML = `<div class="sch-day-detail-header">📅 ${label}</div>
      <div class="sch-empty">No sessions on this day.</div>`;
  } else {
    const items = bookings.map(b => `
      <div class="sch-booking-item" onclick="window.location='booking_status.php'">
        <div class="sch-booking-dot ${b.status}"></div>
        <div class="sch-booking-time">${b.time}</div>
        <div class="sch-booking-info">
          <strong>${b.language}</strong>
          <span>${b.tutor} · ${b.mode === 'online' ? '💻 Online' : '🤝 Face to face'}</span>
        </div>
      </div>`).join('');
    detail.innerHTML = `<div class="sch-day-detail-header">📅 ${label} — ${bookings.length} session${bookings.length>1?'s':''}</div>${items}`;
  }
  detail.setAttribute('data-current-date', dateStr);
  detail.style.display = 'block';
}


document.getElementById('schPrev').onclick = () => { schM--; if(schM<0){schM=11;schY--;} renderSchCal(); };
document.getElementById('schNext').onclick = () => { schM++; if(schM>11){schM=0;schY++;} renderSchCal(); };
renderSchCal();
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
            <input type="number" id="priceFrom" min="0" max="100" value="0" placeholder="0"
                oninput="filterTutors()"
                style="width:100%;padding:10px 10px 10px 34px;border:1px solid rgba(46,42,59,.12);border-radius:12px;outline:none;font-size:14px;font-weight:700;color:#342635;">
            </div>
            <span style="color:#9080a0;font-size:13px;flex-shrink:0;">to</span>
            <div style="flex:1;position:relative;">
            <span style="position:absolute;left:12px;top:50%;transform:translateY(-50%);font-size:13px;color:#9080a0;font-weight:700;">RM</span>
            <input type="number" id="priceTo" min="0" max="100" value="100" placeholder="100"
                oninput="filterTutors()"
                style="width:100%;padding:10px 10px 10px 34px;border:1px solid rgba(46,42,59,.12);border-radius:12px;outline:none;font-size:14px;font-weight:700;color:#342635;">
            </div>
        </div>
        </div>
        <!-- Availability Filter - Add this inside #filterPanel before the footer -->
        <hr style="border:none;border-top:1px solid rgba(242,138,178,.18);margin:14px 0;">
        <div style="margin-bottom:18px;">
            <p style="margin:0 0 10px;font-size:13px;font-weight:900;color:#342635;">
                <i class="bi bi-calendar-week" style="color:#E75A9B;margin-right:5px;"></i> Available Day
            </p>
            <div style="display:flex;flex-wrap:wrap;gap:8px;" id="dayFilterChips">
                <button type="button" class="filter-chip" onclick="toggleFilterChip(this,'day');filterTutors();" data-value="monday" style="padding:8px 14px;border-radius:999px;border:1px solid rgba(46,42,59,.12);background:white;font-size:13px;font-weight:700;color:#7A5570;cursor:pointer;transition:.15s ease;">Monday</button>
                <button type="button" class="filter-chip" onclick="toggleFilterChip(this,'day');filterTutors();" data-value="tuesday" style="padding:8px 14px;border-radius:999px;border:1px solid rgba(46,42,59,.12);background:white;font-size:13px;font-weight:700;color:#7A5570;cursor:pointer;transition:.15s ease;">Tuesday</button>
                <button type="button" class="filter-chip" onclick="toggleFilterChip(this,'day');filterTutors();" data-value="wednesday" style="padding:8px 14px;border-radius:999px;border:1px solid rgba(46,42,59,.12);background:white;font-size:13px;font-weight:700;color:#7A5570;cursor:pointer;transition:.15s ease;">Wednesday</button>
                <button type="button" class="filter-chip" onclick="toggleFilterChip(this,'day');filterTutors();" data-value="thursday" style="padding:8px 14px;border-radius:999px;border:1px solid rgba(46,42,59,.12);background:white;font-size:13px;font-weight:700;color:#7A5570;cursor:pointer;transition:.15s ease;">Thursday</button>
                <button type="button" class="filter-chip" onclick="toggleFilterChip(this,'day');filterTutors();" data-value="friday" style="padding:8px 14px;border-radius:999px;border:1px solid rgba(46,42,59,.12);background:white;font-size:13px;font-weight:700;color:#7A5570;cursor:pointer;transition:.15s ease;">Friday</button>
                <button type="button" class="filter-chip" onclick="toggleFilterChip(this,'day');filterTutors();" data-value="saturday" style="padding:8px 14px;border-radius:999px;border:1px solid rgba(46,42,59,.12);background:white;font-size:13px;font-weight:700;color:#7A5570;cursor:pointer;transition:.15s ease;">Saturday</button>
                <button type="button" class="filter-chip" onclick="toggleFilterChip(this,'day');filterTutors();" data-value="sunday" style="padding:8px 14px;border-radius:999px;border:1px solid rgba(46,42,59,.12);background:white;font-size:13px;font-weight:700;color:#7A5570;cursor:pointer;transition:.15s ease;">Sunday</button>
            </div><hr>
        <!-- Time Slot Filter - Add this after the day filter chips -->
<div style="margin-bottom:18px;">
    <p style="margin:0 0 10px;font-size:13px;font-weight:900;color:#342635;">
        <i class="bi bi-clock" style="color:#E75A9B;margin-right:5px;"></i> Available Time
    </p>
    <div style="display:flex;flex-wrap:wrap;gap:8px;" id="timeSlotFilterChips">
        <button type="button" class="filter-chip" onclick="toggleFilterChip(this,'timeslot');filterTutors();" data-value="morning" style="padding:8px 14px;border-radius:999px;border:1px solid rgba(46,42,59,.12);background:white;font-size:13px;font-weight:700;color:#7A5570;cursor:pointer;transition:.15s ease;">
            🌅 Morning (6AM - 12PM)
        </button>
        <button type="button" class="filter-chip" onclick="toggleFilterChip(this,'timeslot');filterTutors();" data-value="afternoon" style="padding:8px 14px;border-radius:999px;border:1px solid rgba(46,42,59,.12);background:white;font-size:13px;font-weight:700;color:#7A5570;cursor:pointer;transition:.15s ease;">
            ☀️ Afternoon (12PM - 6PM)
        </button>
        <button type="button" class="filter-chip" onclick="toggleFilterChip(this,'timeslot');filterTutors();" data-value="evening" style="padding:8px 14px;border-radius:999px;border:1px solid rgba(46,42,59,.12);background:white;font-size:13px;font-weight:700;color:#7A5570;cursor:pointer;transition:.15s ease;">
            🌙 Evening/Night (6PM onwards)
        </button>
    </div>
</div><hr style="border:none;border-top:1px solid rgba(242,138,178,.18);margin:14px 0;">
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
    data-availability="<?= e(strtolower($tutor['availability'] ?? '')) ?>"
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