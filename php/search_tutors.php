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

if (!$user) { header("Location: login.php"); exit(); }

$displayName = $user['fullname'];
if (!empty($user['profile_pic']) && file_exists('../uploads/profiles/' . $user['profile_pic'])) {
    $profilePic = '../uploads/profiles/' . $user['profile_pic'];
} else {
    $profilePic = $assetBase . '/profile.png';
}
function e($v){ return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }

// Get selected language from URL
$selectedLang = isset($_GET['lang']) ? trim($_GET['lang']) : '';
// Fetch all tutors (optionally filtered by language) - ONLY SHOW TUTORS WITH AVAILABILITY
$allTutors = [];
if ($selectedLang !== '') {
    $stmt = $conn->prepare("
        SELECT u.id, u.fullname, u.profile_pic, tp.rate, tp.bio, tp.experience,
               GROUP_CONCAT(DISTINCT tl.language ORDER BY tl.language) as languages,
               GROUP_CONCAT(DISTINCT ttm.mode) as teaching_modes,
               ul.location,
               ROUND(AVG(r.rating),1) as rating,
               COUNT(DISTINCT r.id) as review_count,
               GROUP_CONCAT(DISTINCT ta.day_of_week) as availability
        FROM users u
        JOIN tutor_profiles tp ON u.id = tp.user_id
        JOIN tutor_languages tl ON u.id = tl.user_id
        LEFT JOIN tutor_teaching_modes ttm ON u.id = ttm.user_id
        LEFT JOIN user_locations ul ON u.id = ul.user_id
        LEFT JOIN ratings r ON u.id = r.tutor_id
        INNER JOIN tutor_availability ta ON u.id = ta.tutor_id
        WHERE u.role = 'tutor' AND u.status = 'approved'
          AND tl.language = ?
        GROUP BY u.id
        ORDER BY tp.rate ASC
    ");
    $stmt->bind_param("s", $selectedLang);
} else {
    $stmt = $conn->prepare("
        SELECT u.id, u.fullname, u.profile_pic, tp.rate, tp.bio, tp.experience,
               GROUP_CONCAT(DISTINCT tl.language ORDER BY tl.language) as languages,
               GROUP_CONCAT(DISTINCT ttm.mode) as teaching_modes,
               ul.location,
               ROUND(AVG(r.rating),1) as rating,
               COUNT(DISTINCT r.id) as review_count,
               GROUP_CONCAT(DISTINCT ta.day_of_week) as availability
        FROM users u
        JOIN tutor_profiles tp ON u.id = tp.user_id
        LEFT JOIN tutor_languages tl ON u.id = tl.user_id
        LEFT JOIN tutor_teaching_modes ttm ON u.id = ttm.user_id
        LEFT JOIN user_locations ul ON u.id = ul.user_id
        LEFT JOIN ratings r ON u.id = r.tutor_id
        INNER JOIN tutor_availability ta ON u.id = ta.tutor_id
        WHERE u.role = 'tutor' AND u.status = 'approved'
        GROUP BY u.id
        ORDER BY tp.rate ASC
    ");
}
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $allTutors[] = $row;
}

$favTutors = [];

$stmt = $conn->prepare("SELECT tutor_id FROM student_favourites WHERE student_id = ?");
$stmt->bind_param("i", $userID);
$stmt->execute();
$res = $stmt->get_result();

while ($row = $res->fetch_assoc()) {
    $favTutors[] = $row['tutor_id'];
}

$allLanguages = ['Japanese','English','Mandarin','Korean','Malay'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
<meta http-equiv="Pragma" content="no-cache">
<meta http-equiv="Expires" content="0">
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Search Tutors<?= $selectedLang ? ' · '.$selectedLang : '' ?> · Kyoshi</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
  <link rel="stylesheet" href="../css/style.css">
  <style>
    :root{
      --cream:#FFF1F6; --paper:rgba(255,255,255,.88); --ink:#342635; --muted:#7B6178;
      --pink:#F28AB2; --pink-dark:#C94F86; --hot-pink:#E75A9B;
      --lavender:#EAD7FF; --peach:#FFD0DD; --mint:#DDF4E3; --sky:#D8ECFF;
      --shadow:0 18px 45px rgba(201,79,134,.16); --shadow-soft:0 10px 26px rgba(201,79,134,.10);
      --radius-xl:32px; --radius-lg:24px;
    }
    *{box-sizing:border-box} 
    
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
    .container{width:min(1440px,calc(100% - 40px));margin:0 auto}

    /* TOPBAR */
    .topbar{position:sticky;top:0;z-index:50;background:rgba(255,241,246,.86);backdrop-filter:blur(20px);border-bottom:1px solid rgba(231,90,155,.18);box-shadow:0 10px 30px rgba(201,79,134,.10)}
    .nav{min-height:78px;display:grid;grid-template-columns:190px minmax(0,1fr) 360px;gap:16px;align-items:center}
    .brand{display:flex;align-items:center;gap:10px;min-width:0}
    .brand img{width:44px;height:44px;object-fit:contain;border-radius:14px}
    .brand strong{display:block;font-size:18px;line-height:1.05}
    .brand span{display:block;margin-top:3px;font-size:11px;color:var(--muted);white-space:nowrap}
    .nav-links{display:flex;align-items:center;justify-content:center;gap:6px;padding:7px;overflow:auto;scrollbar-width:none;box-shadow:inset 0 1px 0 rgba(255,255,255,.70)}
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

    /* PAGE HEADER */
    .page-header{padding:28px 0 20px;display:flex;justify-content:space-between;align-items:flex-end;gap:20px;flex-wrap:wrap;margin-bottom: 10px;}
    .page-header h1{margin:0;font-size:clamp(26px,4vw,40px);letter-spacing:-1px;line-height:1.1}
    .page-header p{margin:6px 0 0;color:var(--muted);font-size:14px}
    .back-link{display:inline-flex;align-items:center;gap:6px;color:var(--pink-dark);font-weight:900;font-size:13px;padding:9px 16px;border-radius:999px;background:rgba(255,255,255,.78);border:1px solid rgba(46,42,59,.08);transition:.18s ease}
    .back-link:hover{transform:translateY(-1px)}

    /* TOOLBAR - SEARCH, SORT, FILTER ON SAME LINE */
.toolbar{
    display: flex;
    gap: 12px;
    align-items: center;
    margin-bottom: 20px;
}
.search-wrap{
    flex: 2;
    position: relative;
}
.search-wrap i{
    position: absolute;
    left: 16px;
    top: 50%;
    transform: translateY(-50%);
    color: #91899F;
}
.search-wrap input{
    width: 100%;
    height: 48px;
    padding: 0 16px 0 42px;
    border: 1px solid rgba(46,42,59,.10);
    border-radius: 999px;
    background: rgba(255,255,255,.88);
    outline: none;
    font-size: 14px;
    box-shadow: var(--shadow-soft);
}
.sort-select{
    flex: 1;
    height: 48px;
    padding: 0 16px;
    border: 1px solid rgba(46,42,59,.10);
    border-radius: 999px;
    background: rgba(255,255,255,.88);
    font-size: 13px;
    font-weight: 700;
    color: #7A5570;
    cursor: pointer;
    outline: none;
    box-shadow: var(--shadow-soft);
}
.filter-toggle{
    width: 48px;
    height: 48px;
    border-radius: 14px;
    border: 1px solid rgba(242,138,178,.3);
    background: rgba(255,255,255,.88);
    cursor: pointer;
    font-size: 18px;
    color: #E75A9B;
    position: relative;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: var(--shadow-soft);
}

    /* LANG TABS */
    .lang-tabs{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:20px}
    .lang-tab{
    display: inline-block;
    padding: 10px 24px;
    border-radius: 999px;
    border: 1px solid rgba(46,42,59,.10);
    background: rgba(255,255,255,.78);
    color: #7A5570;
    font-size: 13px;
    font-weight: 900;
    cursor: pointer;
    transition: .18s ease;
    text-decoration: none;
}
    .lang-tab:hover{transform:translateY(-1px)}
    .lang-tab.active{background:linear-gradient(135deg,var(--hot-pink),var(--pink));color:#fff;border-color:var(--pink);box-shadow:0 8px 18px rgba(231,90,155,.28)}

    /* SEARCH + FILTER BAR */
    .search-bar{display:flex;gap:10px;align-items:center;margin-bottom:20px;flex-wrap:wrap}
    .search-wrap{position:relative;flex:1;min-width:220px}
    .search-wrap i{position:absolute;left:14px;top:50%;transform:translateY(-50%);color:#91899F}
    .search-wrap input{width:100%;padding:13px 14px 13px 40px;border:1px solid rgba(46,42,59,.10);background:rgba(255,255,255,.88);border-radius:999px;outline:none;font-size:14px;box-shadow:var(--shadow-soft)}
    .filter-toggle{width:44px;height:44px;border-radius:14px;border:1px solid rgba(242,138,178,.3);background:rgba(255,255,255,.88);cursor:pointer;font-size:18px;color:#E75A9B;position:relative;display:grid;place-items:center;box-shadow:var(--shadow-soft)}
    .filter-dot{display:none;position:absolute;top:8px;right:8px;width:8px;height:8px;border-radius:50%;background:#E75A9B}

    /* INLINE FILTER PANEL */
    .filter-panel{display:none;background:var(--paper);border:1px solid rgba(242,138,178,.22);border-radius:var(--radius-lg);padding:20px;margin-bottom:20px;box-shadow:var(--shadow-soft)}
    .filter-panel.open{display:block}
    .filter-row{display:flex;gap:24px;flex-wrap:wrap}
    .filter-group{flex:1;min-width:160px}
    .filter-group label{display:block;font-size:12px;font-weight:900;color:#342635;margin-bottom:8px}
    .filter-chips{display:flex;flex-wrap:wrap;gap:6px}
    .fchip{padding:7px 13px;border-radius:999px;border:1px solid rgba(46,42,59,.12);background:white;font-size:12px;font-weight:700;color:#7A5570;cursor:pointer;transition:.15s ease}
    .fchip.active{background:linear-gradient(135deg,#E75A9B,#F28AB2);color:white;border-color:#E75A9B}
    .price-row{display:flex;align-items:center;gap:8px}
    .price-input{position:relative;flex:1}
    .price-input span{position:absolute;left:10px;top:50%;transform:translateY(-50%);font-size:12px;color:#9080a0;font-weight:700}
    .price-input input{width:100%;padding:9px 9px 9px 28px;border:1px solid rgba(46,42,59,.12);border-radius:10px;outline:none;font-size:13px;font-weight:700;color:#342635}
    .filter-footer{display:flex;justify-content:flex-end;gap:10px;margin-top:16px;padding-top:14px;border-top:1px solid rgba(242,138,178,.18)}
    .btn-clear{padding:9px 18px;border-radius:999px;border:1px solid rgba(46,42,59,.12);background:none;color:#7A5570;font-size:13px;font-weight:900;cursor:pointer}
    .btn-apply{padding:9px 22px;border-radius:999px;border:none;background:linear-gradient(135deg,#E75A9B,#F28AB2);color:white;font-size:13px;font-weight:900;cursor:pointer}

    /* RESULTS HEADER */
    .results-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;flex-wrap:wrap;gap:10px}
    .result-count{font-size:13px;color:var(--muted);font-weight:700}
    .compare-bar-btn{padding:10px 20px;border-radius:999px;background:linear-gradient(135deg,var(--hot-pink),var(--pink));color:#fff;border:none;font-size:13px;font-weight:900;cursor:pointer;display:none;transition:.18s ease;box-shadow:0 8px 20px rgba(231,90,155,.28)}
    .compare-bar-btn:hover{transform:translateY(-1px)}
    .compare-bar-btn.visible{display:inline-flex;align-items:center;gap:6px}

    /* TUTOR GRID */
    .tutor-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:18px;padding-bottom:48px}
    .tutor-card{background:var(--paper);border:1px solid rgba(255,255,255,.55);box-shadow:var(--shadow-soft);border-radius:var(--radius-lg);overflow:hidden;display:flex;flex-direction:column;transition:.22s ease}
    .tutor-card:hover{transform:translateY(-3px);box-shadow:var(--shadow)}
    .tutor-card-top{padding:18px 18px 14px;display:flex;gap:14px;align-items:flex-start}
    .tutor-card-top img{width:68px;height:68px;object-fit:cover;border-radius:18px;flex:0 0 auto;background:#eee}
    .tutor-name{font-size:16px;font-weight:900;margin:0 0 5px}
    .tutor-meta{color:var(--muted);font-size:13px;line-height:1.4}
    .tutor-rating{display:flex;align-items:center;gap:4px;margin-top:6px;font-size:13px;font-weight:700;color:#8B6914}
    .tutor-langs{display:flex;flex-wrap:wrap;gap:6px;padding:0 18px 14px}
    .lang-tag{padding:5px 10px;border-radius:999px;background:rgba(242,138,178,.18);color:var(--pink-dark);font-size:11px;font-weight:900}
    .tutor-bio{padding:0 18px 14px;color:var(--muted);font-size:13px;line-height:1.5;flex:1}
    .tutor-card-bottom{padding:14px 18px;border-top:1px solid rgba(46,42,59,.06);display:flex;justify-content:space-between;align-items:center;gap:10px}
    .tutor-price{font-weight:900;font-size:18px}
    .tutor-actions{display:flex;gap:8px;align-items:center}
    .btn-view{padding:9px 18px;border-radius:999px;background:linear-gradient(135deg,var(--hot-pink),var(--pink));color:#fff;font-size:13px;font-weight:900;border:none;cursor:pointer;transition:.18s ease;text-decoration:none;display:inline-block}
    .btn-view:hover{transform:translateY(-1px)}
    .compare-checkbox{width:36px;height:36px;border-radius:12px;border:1.5px solid rgba(46,42,59,.14);background:rgba(255,255,255,.88);cursor:pointer;display:grid;place-items:center;font-size:16px;transition:.18s ease;color:#9080a0}
    .compare-checkbox.selected{background:linear-gradient(135deg,var(--hot-pink),var(--pink));border-color:var(--pink);color:white;box-shadow:0 6px 14px rgba(231,90,155,.28)}
/* HEART BUTTON */
.fav-btn{
    width: 36px;
    height: 36px;
    border-radius: 12px;
    border: 1.5px solid rgba(46,42,59,.14);
    background: white;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    font-size: 16px;
    color: #aaa;
    transition: .2s;
}
.fav-btn.active{
    background: linear-gradient(135deg, #ff6b9d, #ff8fb1);
    color: white;
    border-color: #ff6b9d;
    box-shadow: 0 6px 14px rgba(231,90,155,.3);
}
.fav-btn:hover{
    transform: scale(1.05);
}


    /* EMPTY STATE */
    .empty-state{grid-column:1/-1;text-align:center;padding:48px 24px;background:rgba(255,241,246,.82);border:1px dashed rgba(46,42,59,.16);border-radius:var(--radius-lg);color:var(--muted);font-weight:700}
    .empty-state i{display:block;font-size:40px;margin-bottom:12px;color:var(--pink)}

    /* COMPARE FLOAT BAR */
    .compare-float{position:fixed;bottom:28px;left:50%;transform:translateX(-50%) translateY(80px);opacity:0;pointer-events:none;z-index:60;background:white;border-radius:999px;padding:12px 20px;box-shadow:0 20px 50px rgba(52,38,53,.22);border:1px solid rgba(242,138,178,.22);display:flex;align-items:center;gap:14px;transition:.25s cubic-bezier(.34,1.56,.64,1);white-space:nowrap}
    .compare-float.show{opacity:1;transform:translateX(-50%) translateY(0);pointer-events:all}
    .compare-float-label{font-size:13px;font-weight:900;color:#342635}
    .compare-avatars{display:flex;gap:-6px}
    .compare-avatar{width:32px;height:32px;border-radius:50%;border:2px solid white;object-fit:cover;background:#eee;margin-left:-6px}
    .compare-avatar:first-child{margin-left:0}
    .btn-compare-go{padding:10px 20px;border-radius:999px;background:linear-gradient(135deg,var(--hot-pink),var(--pink));color:#fff;border:none;font-size:13px;font-weight:900;cursor:pointer}
    .btn-compare-clear{padding:10px 14px;border-radius:999px;background:rgba(46,42,59,.06);color:#7A5570;border:none;font-size:13px;font-weight:900;cursor:pointer}

    /* TOAST */
    .toast{position:fixed;left:50%;bottom:100px;transform:translate(-50%,18px);opacity:0;pointer-events:none;z-index:99;background:#8E3F70;color:#fff;border-radius:999px;padding:12px 18px;font-size:13px;font-weight:900;transition:.2s ease}
    .toast.show{opacity:1;transform:translate(-50%,0)}
    .content-wrapper {
    padding: 0 20px; /* left + right spacing */
}
    @media(max-width:1100px){.tutor-grid{grid-template-columns:repeat(2,1fr)}}
    @media(max-width:640px){
      .tutor-grid{grid-template-columns:1fr}
      .nav{grid-template-columns:1fr auto}
      .nav-links{display:none}
    }


.controls-section {
  background: rgba(255,255,255,.4);
  padding: 14px;
  border-radius: 20px;
  margin-bottom: 20px;
}
.top-header{
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin: 28px 0 24px;
    gap: 20px;
}
.header-left{
    flex-shrink: 0;
}
.header-center{
    flex: 1;
    text-align: center;
}
.header-center h1{
    margin: 0;
    font-size: clamp(28px, 4vw, 42px);
    line-height: 1.1;
}
.header-center p{
    margin-top: 8px;
    font-size: 14px;
    color: var(--muted);
}
.header-right{
    flex-shrink: 0;
    width: 80px;
}
.back-link{
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 10px 20px;
    border-radius: 999px;
    background: white;
    color: var(--pink-dark);
    font-weight: 800;
    font-size: 13px;
    box-shadow: var(--shadow-soft);
    transition: 0.2s;
}
.back-link:hover{
    transform: translateX(-3px);
}
/* ========== RESPONSIVE FIXES FOR 900px AND BELOW ========== */
@media (max-width: 900px) {
    /* Fix container padding */
    .container {
        width: 100%;
        padding-left: 16px;
        padding-right: 16px;
    }
    
    /* Fix toolbar layout - KEEP ON SAME LINE */
    .toolbar {
        display: flex;
        flex-direction: row;
        align-items: center;
        gap: 8px;
        margin-bottom: 16px;
        flex-wrap: nowrap;
    }
    
    .search-wrap {
        flex: 2;
        min-width: 0;
    }
    
    .search-wrap input {
        height: 44px;
        font-size: 14px;
        width: 100%;
    }
    
    .sort-select {
        flex: 1;
        min-width: 0;
        height: 44px;
        padding: 0 8px;
        font-size: 12px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    
    .filter-toggle {
        flex: 0 0 auto;
        width: 44px;
        height: 44px;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    
    /* Fix language tabs - horizontal scroll */
    .lang-scroll {
        display: flex;
        overflow-x: auto;
        white-space: nowrap;
        -webkit-overflow-scrolling: touch;
        gap: 10px;
        padding-bottom: 8px;
        margin-bottom: 16px;
        scrollbar-width: none;
    }
    
    .lang-scroll::-webkit-scrollbar {
        display: none;
    }
    
    .lang-tab {
        flex: 0 0 auto;
        padding: 8px 18px;
        font-size: 13px;
    }
    
    /* Fix filter panel on mobile - slides up from bottom */
    .filter-panel {
        position: fixed;
        top: auto;
        bottom: 0;
        left: 0;
        right: 0;
        z-index: 1000;
        border-radius: 24px 24px 0 0;
        max-height: 80vh;
        overflow-y: auto;
        margin-bottom: 0;
        padding: 20px;
        transform: translateY(100%);
        transition: transform 0.3s ease;
    }
    
    .filter-panel.open {
        transform: translateY(0);
    }
    
    /* Add overlay when filter is open */
    .filter-overlay {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0,0,0,0.5);
        z-index: 999;
        display: none;
    }
    
    .filter-overlay.open {
        display: block;
    }
    
    /* Filter rows stack vertically */
    .filter-row {
        flex-direction: column;
        gap: 16px;
    }
    
    .filter-group {
        width: 100%;
    }
    
    .filter-chips {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
    }
    
    .fchip {
        padding: 8px 14px;
        font-size: 12px;
    }
    
    .price-row {
        display: flex;
        gap: 10px;
    }
    
    /* Fix tutor grid - 2 columns on tablet */
    .tutor-grid {
        grid-template-columns: repeat(2, 1fr);
        gap: 14px;
    }
    
   .top-header{
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin: 28px 0 24px;
    gap: 20px;
}
.header-left{
    flex-shrink: 0;
}
.header-center{
    flex: 1;
    text-align: center;
}
.header-center h1{
    margin: 0;
    font-size: clamp(28px, 4vw, 42px);
    line-height: 1.1;
}
.header-center p{
    margin-top: 8px;
    font-size: 14px;
    color: var(--muted);
}
.header-right{
    flex-shrink: 0;
    width: 80px;
}
.back-link{
    display: inline-flex;
    align-items: center;
    gap: 6px;
    top:20px;
    border-radius: 999px;
    background: white;
    color: var(--pink-dark);
    font-weight: 800;
    font-size: 13px;
    box-shadow: var(--shadow-soft);
    transition: 0.2s;
}
.back-link:hover{
    transform: translateX(-3px);
}
    
    /* Fix results header */
    .results-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 8px;
    }
    
    /* Fix compare float bar on mobile */
    .compare-float {
        left: 16px;
        right: 16px;
        transform: translateX(0) translateY(80px);
        width: calc(100% - 32px);
        border-radius: 20px;
        white-space: normal;
        flex-wrap: wrap;
        justify-content: center;
        padding: 12px 16px;
        bottom: 16px;
    }
    
    .compare-float.show {
        transform: translateX(0) translateY(0);
    }
    
    .compare-avatars {
        order: 1;
    }
    
    .compare-float-label {
        order: 2;
        font-size: 12px;
    }
    
    .btn-compare-go, .btn-compare-clear {
        order: 3;
        padding: 8px 16px;
        font-size: 12px;
    }
    
    /* Fix profile dropdown on mobile */
    .profile span {
        display: none;
    }
    
    .profile {
        padding: 6px;
    }
}

/* ========== FOR 600px AND BELOW (MOBILE) - STILL SAME LINE ========== */
@media (max-width: 600px) {
    /* Keep toolbar on same line but smaller */
    .toolbar {
        gap: 6px;
    }
    
    .search-wrap input {
        height: 40px;
        font-size: 13px;
    }
    
    .sort-select {
        height: 40px;
        font-size: 11px;
        padding: 0 6px;
    }
    
    .filter-toggle {
        width: 40px;
        height: 40px;
    }
    
    /* Single column tutor grid */
    .tutor-grid {
        grid-template-columns: 1fr;
        gap: 16px;
    }
    
    /* Adjust card padding */
    .tutor-card-top {
        padding: 14px 14px 10px;
    }
    
    .tutor-card-top img {
        width: 56px;
        height: 56px;
    }
    
    .tutor-name {
        font-size: 15px;
    }
    
    .tutor-meta {
        font-size: 11px;
    }
    
    .tutor-langs {
        padding: 0 14px 10px;
    }
    
    .lang-tag {
        font-size: 9px;
        padding: 4px 8px;
    }
    
    .tutor-bio {
        padding: 0 14px 10px;
        font-size: 12px;
    }
    
    .tutor-card-bottom {
        padding: 10px 14px;
        flex-wrap: wrap;
    }
    
    .tutor-price {
        font-size: 16px;
    }
    
    .tutor-actions {
        gap: 6px;
    }
    
    .fav-btn, .compare-checkbox {
        width: 32px;
        height: 32px;
        font-size: 14px;
    }
    
    .btn-view {
        padding: 7px 14px;
        font-size: 12px;
    }
    
    /* Language tabs smaller */
    .lang-tab {
        padding: 6px 14px;
        font-size: 12px;
    }
}

/* ========== FIX FOR 900px AND BELOW (HEADER + TABLE) ========== */
@media (max-width: 900px) {
    /* Fix top header */
    .top-header {
        flex-direction: column;
        gap: 16px;
        text-align: center;
        margin-top:0;
    }
    
    .header-left, .header-right {
        width: auto;
    }
    
    .header-left {
        order: 1;
        align-self: flex-start;
    }
    
    .header-center {
        order: 2;
    }
    
    .header-right {
        display: none;
    }
    
    .back-link {
        padding: 8px 16px;
        font-size: 12px;
    }
    
    /* Fix compare table - make it scroll horizontally */
    .compare-wrap {
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }
    
    /* Set minimum width for table to enable horizontal scroll */
    .compare-header,
    .compare-row,
    div[style*="grid-template-columns:200px"] {
        min-width: 600px;
    }
    
    /* Make first column sticky */
    .compare-header-label,
    .compare-cell-label,
    .compare-section-title {
        position: sticky;
        left: 0;
        background: var(--paper);
        z-index: 5;
    }
    
    /* Adjust section titles */
    .compare-section-title {
        font-size: 11px;
        padding: 10px 16px;
    }
    
    /* Adjust cell padding */
    .compare-cell-label,
    .compare-header-label {
        font-size: 11px;
        padding: 12px 10px;
    }
    
    .compare-cell,
    .compare-header-tutor {
        padding: 12px 8px;
        font-size: 12px;
    }
    
    /* Smaller tutor images */
    .compare-header-tutor img {
        width: 60px;
        height: 60px;
    }
    
    .compare-header-tutor h3 {
        font-size: 14px;
    }
    
    .btn-book {
        padding: 6px 12px;
        font-size: 11px;
    }
}

/* ========== FOR 600px AND BELOW ========== */
@media (max-width: 600px) {
    .top-header {
        padding: 12px 0;
    }
    
    .header-center h1 {
        font-size: 22px;
    }
    
    .header-center p {
        font-size: 12px;
    }
    
    .back-link span {
        display: none;
    }
    
    .back-link {
        padding: 8px 12px;
    }
    
    .back-link i {
        font-size: 16px;
    }
    
    /* Smaller table cells */
    .compare-header-label,
    .compare-cell-label {
        min-width: 100px;
        font-size: 10px;
        padding: 10px 8px;
    }
    
    .compare-cell {
        font-size: 11px;
        padding: 10px 6px;
    }
    
    .compare-header-tutor img {
        width: 50px;
        height: 50px;
    }
    
    .compare-header-tutor h3 {
        font-size: 12px;
    }
    
    .sub {
        font-size: 10px;
    }
    
    .btn-book {
        padding: 5px 10px;
        font-size: 10px;
    }
    
    .lang-tag-sm, .mode-tag {
        font-size: 9px;
        padding: 2px 6px;
    }
} 
/* ========== ADD FILTER OVERLAY ELEMENT ========== */
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
</header>
  <div class="nav-overlay" id="navOverlay"></div>

<main class="container">
<div class="content-wrapper"></div>
<div class="top-header">

    <!-- BACK -->
    <div class="header-left">
        <a href="find_language.php" class="back-link">
            <i class="bi bi-arrow-left"></i>
            <span>Back</span>
        </a>
    </div>

    <div class="header-center">
        <h1>
            <?= $selectedLang 
                ? 'Tutors for <span style="color:var(--hot-pink)">'.e($selectedLang).'</span>' 
                : 'All Tutors' ?>
        </h1>

        <p>
            Pick up to 3 tutors using the <strong>+</strong> button to compare
        </p>
    </div>
    <div class="header-right"></div>

</div>
<div class="toolbar">

    <!-- SEARCH -->
    <div class="search-wrap">
        <i class="bi bi-search"></i>

        <input 
            type="text"
            id="searchInput"
            placeholder="Search language..."
            oninput="filterCards()">
    </div>

    <!-- SORT -->
    <select id="sortSelect" class="sort-select" onchange="sortCards()">
        <option value="default">Sort By</option>
        <option value="low-high">Price: Low → High</option>
        <option value="high-low">Price: High → Low</option>
        <option value="rating">Top Rated</option>
        <option value="name">Name: A → Z</option>
    </select>

    <!-- FILTER -->
    <button class="filter-toggle" onclick="toggleFilter()" title="Filters">
        <i class="bi bi-sliders"></i>
        <span class="filter-dot" id="filterDot"></span>
    </button>

</div>


<div class="lang-scroll">

    <a href="search_tutors.php"
       class="lang-tab <?= $selectedLang==='' ? 'active' : '' ?>">
       All 
    </a>

    <?php foreach ($allLanguages as $lang): ?>
        <a href="search_tutors.php?lang=<?= urlencode($lang) ?>"
           class="lang-tab <?= $selectedLang===$lang ? 'active' : '' ?>">
           <?= e($lang) ?>
        </a>
    <?php endforeach; ?>
</div>

</div>
  <!-- Filter Panel -->
  <div class="filter-panel" id="filterPanel">
    <div class="filter-row">
      <div class="filter-group">
        <label><i class="bi bi-cash-coin" style="color:#E75A9B;margin-right:4px;"></i> Price Range (RM/hr)</label>
        <div class="price-row">
          <div class="price-input"><span>RM</span><input type="number" id="priceFrom" value="0" min="0" max="500" oninput="filterCards()"></div>
          <span style="color:#9080a0;font-size:13px">to</span>
          <div class="price-input"><span>RM</span><input type="number" id="priceTo" value="500" min="0" max="500" oninput="filterCards()"></div>
        </div>
      </div>
      <div class="filter-group">
        <label><i class="bi bi-laptop" style="color:#E75A9B;margin-right:4px;"></i> Teaching Mode</label>
        <div class="filter-chips">
          <button class="fchip" data-type="mode" data-value="online" onclick="toggleFChip(this);filterCards();">💻 Online</button>
          <button class="fchip" data-type="mode" data-value="face_to_face" onclick="toggleFChip(this);filterCards();">🤝 Face to Face</button>
        </div>
      </div>
      <div class="filter-group" id="locationGroup" style="display:none;">
    <label>
        <i class="bi bi-geo-alt-fill" style="color:#E75A9B;margin-right:4px;"></i>
        Location
    </label>

    <div class="filter-chips">
        <button class="fchip" data-type="location" data-value="Kuala Lumpur" onclick="toggleLocation(this)">Kuala Lumpur</button>
        <button class="fchip" data-type="location" data-value="Penang" onclick="toggleLocation(this)">Penang</button>
        <button class="fchip" data-type="location" data-value="Johor Bahru" onclick="toggleLocation(this)">Johor Bahru</button>
        <button class="fchip" data-type="location" data-value="Kota Kinabalu" onclick="toggleLocation(this)">Kota Kinabalu</button>
    </div>
    </div>
      <div class="filter-group">
        <label><i class="bi bi-star-fill" style="color:#E75A9B;margin-right:4px;"></i> Minimum Rating</label>
        <div class="filter-chips">
          <button class="fchip" data-type="rating" data-value="4" onclick="toggleRating(this);filterCards();">⭐ 4+</button>
          <button class="fchip" data-type="rating" data-value="3" onclick="toggleRating(this);filterCards();">⭐ 3+</button>
          <button class="fchip" data-type="rating" data-value="2" onclick="toggleRating(this);filterCards();">⭐ 2+</button>
        </div>
      </div>
    </div>
    <div class="filter-footer">
      <button class="btn-clear" onclick="clearFilters()">Clear all</button>
      <button class="btn-apply" onclick="toggleFilter()">Apply</button>
    </div>
  </div>

<!-- Add this HTML right after the filter panel div (before closing main) -->
<div id="filterOverlay" class="filter-overlay" onclick="toggleFilter()"></div>
  <!-- Results Header -->
  <div class="results-header">
    <span class="result-count" id="resultCount"><?= count($allTutors) ?> tutor<?= count($allTutors) !== 1 ? 's' : '' ?> found</span>
  </div>

  <!-- Tutor Grid -->
  <div class="tutor-grid" id="tutorGrid">
    <?php if (empty($allTutors)): ?>
      <div class="empty-state">
        <i class="bi bi-person-x"></i>
        No tutors found for <strong><?= e($selectedLang) ?></strong> yet.<br>
        <a href="find_language.php" style="color:var(--pink-dark);font-weight:900;margin-top:10px;display:inline-block;">Try another language →</a>
      </div>
    <?php else: ?>
      <?php foreach ($allTutors as $tutor):
        $pic = !empty($tutor['profile_pic'])
            ? '../uploads/profiles/' . $tutor['profile_pic']
            : $assetBase . '/profile-tutor.png';
        $langArr = array_filter(array_map('trim', explode(',', $tutor['languages'] ?? '')));
        $stars = $tutor['rating'] ? round($tutor['rating']) : 0;
      ?>
        <div class="tutor-card"
          data-id="<?= $tutor['id'] ?>"
          data-name="<?= e(strtolower($tutor['fullname'])) ?>"
          data-lang="<?= e(strtolower($tutor['languages'] ?? '')) ?>"
          data-mode="<?= e(strtolower($tutor['teaching_modes'] ?? '')) ?>"
          data-rate="<?= e($tutor['rate'] ?? 0) ?>"
          data-rating="<?= e($tutor['rating'] ?? 0) ?>"
          data-pic="<?= e($pic) ?>"
          data-fullname="<?= e($tutor['fullname']) ?>"
          data-location="<?= e(strtolower($tutor['location'] ?? '')) ?>">
          <div class="tutor-card-top">
            <img src="<?= e($pic) ?>" alt="<?= e($tutor['fullname']) ?>">
            <div style="flex:1;min-width:0;">
              <p class="tutor-name"><?= e($tutor['fullname']) ?></p>
              <div class="tutor-meta">
                <?php if ($tutor['experience']): ?><?= e($tutor['experience']) ?> years experience <?php endif; ?>
        
              </div>
              <div class="tutor-rating">
                <?php for($i=1;$i<=5;$i++): ?>
                    <i class="bi bi-star<?= $i<=$stars?'-fill':'' ?>" style="color:<?= $i<=$stars?'#FFB800':'#ddd' ?>;font-size:13px;"></i>
                <?php endfor; ?>
                <span style="margin-left:4px;">
                    <?php if($tutor['rating']): ?>
                        <?= e($tutor['rating']) ?> (<?= e($tutor['review_count']) ?> reviews)
                    <?php else: ?>
                        No reviews yet
                    <?php endif; ?>
                </span>
            </div>
            </div>
          </div>

          <?php if (!empty($langArr)): ?>
            <div class="tutor-langs">
              <?php foreach ($langArr as $l): ?>
                <span class="lang-tag"><?= e($l) ?></span>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>

          <?php if ($tutor['bio']): ?>
            <div class="tutor-bio"><?= e(mb_strimwidth($tutor['bio'], 0, 100, '...')) ?></div>
          <?php endif; ?>

          <div class="tutor-card-bottom">
            <span class="tutor-price">RM <?= e($tutor['rate']) ?>/hr</span>
            <div class="tutor-actions">
            <?php $isFav = in_array((int)$tutor['id'], array_map('intval', $favTutors)); ?>

            <button 
            class="fav-btn <?= $isFav ? 'active' : '' ?>" 
            onclick="toggleFavourite(this)" 
            data-id="<?= $tutor['id'] ?>">

            <i class="bi <?= $isFav ? 'bi-heart-fill' : 'bi-heart' ?>"></i>
            </button>
              <button class="compare-checkbox" onclick="toggleCompare(this)" title="Add to compare" data-id="<?= $tutor['id'] ?>">
                <i class="bi bi-plus"></i>
              </button>
              <a href="tutor_profile.php?id=<?= $tutor['id'] ?>" class="btn-view">View</a>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
  
</main>

<!-- Compare Float Bar -->
<div class="compare-float" id="compareFloat">
  <div class="compare-avatars" id="compareAvatars"></div>
  <span class="compare-float-label" id="compareLabel">0 selected</span>
  <button class="btn-compare-go" onclick="goCompare()"><i class="bi bi-bar-chart-line"></i> Compare Now</button>
  <button class="btn-compare-clear" onclick="clearCompare()">Cancel</button>
</div>

<div class="toast" id="toast"></div>
<script>
// Prevent back button from showing page after logout
history.pushState(null, null, location.href);
window.addEventListener('popstate', function() {
    window.location.href = 'login.php';
});
</script>
<script>
  let activeMode = [];
  let activeRating = 0;
  let activeLocation = [];
  let activeRatingBtn = null;

  function toggleFilter(){
    document.getElementById('filterPanel').classList.toggle('open');
  }

  function toggleFChip(el){
    el.classList.toggle('active');
    const val = el.dataset.value;

    if (el.dataset.type === "mode") {
        if (el.classList.contains('active')) {
        if (!activeMode.includes(val)) activeMode.push(val);
        } else {
        activeMode = activeMode.filter(v => v !== val);
        }
    }

    const locationGroup = document.getElementById('locationGroup');
    if (activeMode.includes('face_to_face')) {
        locationGroup.style.display = 'block';
    } else {
        locationGroup.style.display = 'none';
        activeLocation = [];
        document.querySelectorAll('[data-type="location"]').forEach(b => b.classList.remove('active'));
    }

    updateFilterDot();
    }

  function toggleRating(el){
    if (activeRatingBtn === el) {
      el.classList.remove('active');
      activeRating = 0;
      activeRatingBtn = null;
    } else {
      if (activeRatingBtn) activeRatingBtn.classList.remove('active');
      el.classList.add('active');
      activeRating = parseFloat(el.dataset.value);
      activeRatingBtn = el;
    }
    updateFilterDot();
  }
function toggleLocation(el){
  el.classList.toggle('active');
  const val = el.dataset.value;

  if (el.classList.contains('active')) {
    if (!activeLocation.includes(val)) activeLocation.push(val);
  } else {
    activeLocation = activeLocation.filter(v => v !== val);
  }

  filterCards();
}

  function updateFilterDot(){
    const from = parseFloat(document.getElementById('priceFrom').value)||0;
    const to   = parseFloat(document.getElementById('priceTo').value)||500;
    const has  = activeMode.length>0 || activeRating>0 || from>0 || to<500;
    document.getElementById('filterDot').style.display = has ? 'block' : 'none';
  }

  function clearFilters(){
    activeMode = []; activeRating = 0; activeRatingBtn = null;
    document.getElementById('priceFrom').value = 0;
    document.getElementById('priceTo').value   = 500;
    document.querySelectorAll('.fchip').forEach(b => b.classList.remove('active'));
    updateFilterDot();
    filterCards();
  }

  function filterCards(){
    const q     = (document.getElementById('searchInput').value || '').toLowerCase().trim();
    const from  = parseFloat(document.getElementById('priceFrom').value)||0;
    const to    = parseFloat(document.getElementById('priceTo').value)||500;
    const cards = document.querySelectorAll('.tutor-card');
    let count   = 0;

    cards.forEach(card => {
      const name   = card.dataset.name || '';
      const lang   = card.dataset.lang || '';
      const modes  = (card.dataset.mode||'').split(',').map(m=>m.trim()).filter(Boolean);
      const rate   = parseFloat(card.dataset.rate||0);
      const rating = parseFloat(card.dataset.rating||0);
      const location = (card.dataset.location || '').toLowerCase();
      const searchOk = q==='' || name.includes(q) || lang.includes(q);
      const priceOk  = rate>=from && rate<=to;
      const modeOk   = activeMode.length===0 || activeMode.some(m=>modes.includes(m));
      const ratingOk = activeRating===0 || rating>=activeRating;
      const locationOk =
                        !activeMode.includes('face_to_face') ||
                        activeLocation.length === 0 ||
                        activeLocation.some(loc => location.includes(loc.toLowerCase()));
      const show = searchOk && priceOk && modeOk && ratingOk && locationOk;
      card.style.display = show ? 'flex' : 'none';
      if (show) count++;
    });

    document.getElementById('resultCount').textContent = count + ' tutor' + (count!==1?'s':'') + ' found';
    updateFilterDot();
  }

  function sortCards(){
    const val  = document.getElementById('sortSelect').value;
    const grid = document.getElementById('tutorGrid');
    const cards = Array.from(grid.querySelectorAll('.tutor-card'));

    cards.sort((a, b) => {
      if (val === 'low-high')  return parseFloat(a.dataset.rate) - parseFloat(b.dataset.rate);
      if (val === 'high-low')  return parseFloat(b.dataset.rate) - parseFloat(a.dataset.rate);
      if (val === 'rating')    return parseFloat(b.dataset.rating||0) - parseFloat(a.dataset.rating||0);
      if (val === 'name')      return a.dataset.name.localeCompare(b.dataset.name);
      return 0;
    });

    cards.forEach(card => grid.appendChild(card));
  }

  // Compare logic (max 3)
  let compareList = [];

  function toggleCompare(btn){
    const card = btn.closest('.tutor-card');
    const id   = card.dataset.id;
    const name = card.dataset.fullname;
    const pic  = card.dataset.pic;
    const idx  = compareList.findIndex(t=>t.id===id);

    if (idx > -1) {
      compareList.splice(idx,1);
      btn.classList.remove('selected');
      btn.innerHTML = '<i class="bi bi-plus"></i>';
    } else {
      if (compareList.length >= 3){ showToast('Maximum 3 tutors for comparison'); return; }
      compareList.push({id, name, pic});
      btn.classList.add('selected');
      btn.innerHTML = '<i class="bi bi-check2"></i>';
    }
    updateCompareBar();
  }

  function updateCompareBar(){
    const bar     = document.getElementById('compareFloat');
    const label   = document.getElementById('compareLabel');
    const avatars = document.getElementById('compareAvatars');
    label.textContent = compareList.length + ' tutor' + (compareList.length!==1?'s':'') + ' selected';
    avatars.innerHTML = compareList.map(t=>`<img class="compare-avatar" src="${t.pic}" alt="${t.name}" title="${t.name}">`).join('');
    bar.classList.toggle('show', compareList.length >= 2);
  }

  function clearCompare(){
    compareList = [];
    document.querySelectorAll('.compare-checkbox').forEach(btn=>{
      btn.classList.remove('selected');
      btn.innerHTML = '<i class="bi bi-plus"></i>';
    });
    updateCompareBar();
  }

  function goCompare(){
    if (compareList.length < 2){ showToast('Select at least 2 tutors to compare'); return; }
    window.location.href = 'compare_tutors.php?ids=' + compareList.map(t=>t.id).join(',');
  }

  function showToast(msg){
    const t = document.getElementById('toast');
    t.textContent = msg; t.classList.add('show');
    setTimeout(()=>t.classList.remove('show'), 1800);
  }

  function toggleDropdown(){
    const d = document.getElementById('profileDropdown');
    d.style.display = d.style.display==='none' ? 'block' : 'none';
  }

  document.addEventListener('click', function(e){
    const btn = document.getElementById('profileBtn');
    const dd  = document.getElementById('profileDropdown');
    if (btn && dd && !btn.contains(e.target) && !dd.contains(e.target)) dd.style.display='none';
    const fp  = document.getElementById('filterPanel');
    const ft  = document.querySelector('.filter-toggle');
    if (fp && ft && fp.classList.contains('open') && !fp.contains(e.target) && !ft.contains(e.target)){
      fp.classList.remove('open');
    }

  });

function toggleFavourite(btn){
  const id = btn.dataset.id;

  fetch('toggle_favourite.php', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/x-www-form-urlencoded'
    },
    body: 'tutor_id=' + id
  })
  .then(res => res.text())
  .then(data => {

    if (data === 'added'){
      btn.classList.add('active');
      btn.innerHTML = '<i class="bi bi-heart-fill"></i>';
    } 
    else if (data === 'removed'){
      btn.classList.remove('active');
      btn.innerHTML = '<i class="bi bi-heart"></i>';
    }
    });
          }
</script>
<script src="../js/nav.js"></script>
</body>
</html>