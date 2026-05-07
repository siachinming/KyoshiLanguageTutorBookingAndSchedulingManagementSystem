<?php
session_start();
include 'config.php';

if(
    !isset($_SESSION['user_id']) ||
    $_SESSION['role'] != 'tutor'
){
    header("Location: login.php");
    exit();
}

$userID = $_SESSION['user_id'];
$tutorQuery = mysqli_query($conn, "
    SELECT *
    FROM users
    WHERE id = '$userID'
");

$tutor = mysqli_fetch_assoc($tutorQuery);

$displayName = $tutor['fullname'];
$requestCountQuery = mysqli_query($conn, "
    SELECT COUNT(*) AS total
    FROM bookings
    WHERE tutor_id = '$userID'
    AND status = 'Pending'
");

$requestCount = mysqli_fetch_assoc($requestCountQuery);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Kyoshi Tutor Dashboard</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

  <style>
    :root{
      --bg:#f6fbff;
      --paper:#ffffff;
      --ink:#132238;
      --muted:#6b7d92;
      --blue:#36b8ff;
      --blue-soft:#eaf7ff;
      --line:#e4edf5;
      --shadow:0 16px 38px rgba(19,34,56,.06);
      --radius-xl:34px;
      --yellow:#fff4da;
      --green:#e6f8ee;
      --red:#ffe8e8;
      --purple:#f1ecff;
    }

    *{ box-sizing:border-box; }
    html{ scroll-behavior:smooth; }

    body{
      margin:0;
      font-family:"Segoe UI", Arial, sans-serif;
      color:var(--ink);
      background:
        radial-gradient(circle at top left, rgba(54,184,255,.10), transparent 24%),
        radial-gradient(circle at 92% 4%, rgba(255,224,163,.22), transparent 22%),
        var(--bg);
    }

    a{ text-decoration:none; color:inherit; }
    button,input{ font-family:inherit; }

    .container{
      width:min(1380px, calc(100% - 48px));
      margin:0 auto;
    }

    .top-shell{
      position:sticky;
      top:0;
      z-index:50;
      background:rgba(246,251,255,.86);
      backdrop-filter:blur(16px);
      border-bottom:1px solid rgba(228,237,245,.85);
    }

    .topnav{
      min-height:84px;
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:18px;
    }

    .brand{
      display:flex;
      align-items:center;
      gap:12px;
      flex:0 0 auto;
    }

    .brand img{
      width:62px;
      height:auto;
      display:block;
    }

    .brand-text strong{
      display:block;
      font-size:17px;
      line-height:1.1;
    }

    .brand-text span{
      display:block;
      margin-top:4px;
      font-size:12px;
      color:var(--muted);
    }

    .nav-links{
      display:flex;
      align-items:center;
      gap:10px;
      flex-wrap:wrap;
      justify-content:center;
    }

    .nav-links a{
      color:#5e7188;
      font-size:14px;
      font-weight:600;
      padding:10px 14px;
      border-radius:999px;
      transition:.18s ease;
    }

    .nav-links a:hover,
    .nav-links a.active{
      background:var(--blue-soft);
      color:#0f8ed8;
    }

    .nav-actions{
      display:flex;
      align-items:center;
      gap:12px;
      flex:0 0 auto;
    }

    .search{
      position:relative;
    }

    .search i{
      position:absolute;
      left:15px;
      top:50%;
      transform:translateY(-50%);
      color:#98a8b9;
      font-size:14px;
    }

    .search input{
      border:0;
      outline:none;
      width:250px;
      background:white;
      border-radius:999px;
      padding:12px 16px 12px 40px;
      box-shadow:0 8px 20px rgba(19,34,56,.05);
    }

    .icon-btn{
      border:0;
      background:white;
      width:44px;
      height:44px;
      border-radius:16px;
      box-shadow:0 8px 20px rgba(19,34,56,.05);
      cursor:pointer;
      position:relative;
      color:#425770;
      transition:.18s ease;
    }

    .icon-dot{
      position:absolute;
      width:8px;
      height:8px;
      border-radius:50%;
      background:#ff6b6b;
      top:11px;
      right:11px;
      border:2px solid #fff;
    }

    .profile{
      display:flex;
      align-items:center;
      gap:10px;
      border:0;
      padding:6px 10px 6px 6px;
      border-radius:999px;
      background:white;
      box-shadow:0 8px 20px rgba(19,34,56,.05);
      cursor:pointer;
      transition:.18s ease;
    }

    .profile img{
      width:34px;
      height:34px;
      object-fit:cover;
      border-radius:50%;
      display:block;
    }

    .profile span{
      font-size:13px;
      font-weight:700;
      color:#425770;
      white-space:nowrap;
    }

    .hero{
      padding:28px 0 10px;
    }

    .hero-grid{
      display:grid;
      grid-template-columns:1.25fr .75fr;
      gap:22px;
      align-items:stretch;
    }

    .hero-card{
      min-height:225px;
      border-radius:var(--radius-xl);
      background:
        linear-gradient(115deg, rgba(255,255,255,.96), rgba(255,255,255,.76)),
        url("../assets/img/herobg.png");
      background-size:cover;
      background-position:center;
      box-shadow:var(--shadow);
      padding:30px;
      display:flex;
      flex-direction:column;
      justify-content:space-between;
      overflow:hidden;
    }

    .eyebrow{
      display:flex;
      align-items:center;
      gap:10px;
      color:var(--muted);
      font-size:13px;
      font-weight:600;
    }

    .pulse{
      width:10px;
      height:10px;
      border-radius:50%;
      background:#32d47f;
      box-shadow:0 0 0 6px rgba(50,212,127,.13);
    }

    .hero-copy h1{
      margin:12px 0 0;
      font-size:clamp(34px, 5vw, 54px);
      line-height:.98;
      letter-spacing:-1.6px;
      font-weight:700;
    }

    .hero-copy p{
      margin:16px 0 0;
      max-width:570px;
      font-size:15px;
      line-height:1.55;
      color:#576a80;
    }

    .hero-actions{
      display:flex;
      flex-wrap:wrap;
      gap:10px;
      margin-top:24px;
    }

    .btn-primary,
    .btn-ghost,
    .btn-text{
      border:0;
      border-radius:999px;
      padding:11px 16px;
      font-size:13px;
      font-weight:700;
      cursor:pointer;
      transition:.18s ease;
    }

    .btn-primary{
      background:var(--blue);
      color:white;
      box-shadow:0 10px 24px rgba(54,184,255,.22);
    }

    .btn-ghost{
      background:#fff;
      color:#35516a;
      box-shadow:0 8px 20px rgba(19,34,56,.05);
    }

    .btn-text{
      background:transparent;
      color:#1a91ce;
      padding-left:0;
      padding-right:0;
    }

    .btn-primary:hover,.btn-ghost:hover,.btn-text:hover,.icon-btn:hover,.profile:hover{
      transform:translateY(-1px);
    }

    .hero-side{
      background:#fff;
      border-radius:var(--radius-xl);
      box-shadow:var(--shadow);
      padding:28px;
      display:flex;
      flex-direction:column;
      justify-content:space-between;
      min-height:225px;
    }

    .clock{
      font-size:44px;
      line-height:1;
      letter-spacing:-1.4px;
      font-weight:700;
    }

    .date-line{
      margin-top:8px;
      color:var(--muted);
      font-size:14px;
      line-height:1.45;
    }

    .side-note{
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:18px;
      margin-top:24px;
      padding:16px;
      border-radius:24px;
      background:linear-gradient(135deg, #eff8ff, #ffffff);
    }

    .side-note p{
      margin:0;
      font-size:13px;
      color:#4c6178;
      line-height:1.45;
    }

    .side-note strong{
      display:block;
      margin-bottom:4px;
      font-size:14px;
    }

    section.block{ margin-top:30px; }

    .section-head{
      display:flex;
      justify-content:space-between;
      align-items:end;
      gap:20px;
      margin-bottom:16px;
    }

    .section-head h2{
      margin:0;
      font-size:25px;
      letter-spacing:-.5px;
      font-weight:700;
    }

    .section-head p{
      margin:5px 0 0;
      color:var(--muted);
      font-size:14px;
    }

    .stats-scroll{
      display:flex;
      gap:16px;
      overflow-x:auto;
      padding:4px 2px 8px;
      scrollbar-width:thin;
    }

    .stat-card{
      flex:0 0 250px;
      min-height:155px;
      background:#fff;
      border-radius:30px;
      box-shadow:var(--shadow);
      padding:22px;
      position:relative;
      overflow:hidden;
    }

    .stat-card::after{
      content:"";
      position:absolute;
      width:96px;
      height:96px;
      border-radius:50%;
      right:-24px;
      bottom:-20px;
      background:var(--blue-soft);
    }

    .stat-card.yellow::after{ background:#fff1ca; }
    .stat-card.green::after{ background:#dcf7e7; }
    .stat-card.pink::after{ background:#ffe7ef; }
    .stat-card.purple::after{ background:#eee8ff; }

    .stat-card span{
      display:block;
      color:#63768d;
      font-size:13px;
      font-weight:600;
    }

    .stat-card strong{
      display:block;
      margin-top:12px;
      font-size:36px;
      line-height:1;
      letter-spacing:-1px;
    }

    .stat-card small{
      display:block;
      margin-top:14px;
      color:#4e637b;
      font-size:13px;
    }

    .two-col{
      display:grid;
      grid-template-columns:1.2fr .8fr;
      gap:22px;
      align-items:start;
    }

    .lower-grid{
      display:grid;
      grid-template-columns:1.05fr .95fr;
      gap:22px;
      align-items:start;
    }

    .three-grid{
      display:grid;
      grid-template-columns:repeat(3,minmax(0,1fr));
      gap:18px;
    }

    .panel{
      background:#fff;
      border-radius:var(--radius-xl);
      box-shadow:var(--shadow);
      padding:24px;
    }

    .panel-top{
      display:flex;
      justify-content:space-between;
      align-items:center;
      gap:16px;
      margin-bottom:16px;
    }

    .panel-top h3{
      margin:0;
      font-size:22px;
      letter-spacing:-.3px;
      font-weight:700;
    }

    .chips{
      display:flex;
      gap:8px;
      flex-wrap:wrap;
    }

    .chip{
      border:0;
      background:#eef8ff;
      color:#278ec7;
      border-radius:999px;
      padding:8px 13px;
      font-size:12px;
      font-weight:700;
      cursor:pointer;
    }

    .chip.active{
      background:var(--blue);
      color:#fff;
    }

    .list{
      display:flex;
      flex-direction:column;
      gap:12px;
    }

    .list-item{
      display:grid;
      grid-template-columns:auto 1fr auto;
      align-items:center;
      gap:16px;
      background:#fbfdff;
      border-radius:24px;
      padding:14px;
      transition:.18s ease;
    }

    .list-item:hover{
      background:#f3fbff;
      transform:translateY(-1px);
    }

    .avatar{
      width:56px;
      height:56px;
      object-fit:cover;
      border-radius:18px;
      background:#e8f5fb;
      display:block;
    }

    .item-main strong{
      display:block;
      font-size:15px;
    }

    .item-main p{
      margin:5px 0 0;
      color:#6b7d92;
      font-size:13px;
      line-height:1.4;
    }

    .item-actions{
      display:flex;
      align-items:center;
      gap:8px;
    }

    .status{
      display:inline-flex;
      align-items:center;
      padding:7px 11px;
      border-radius:999px;
      font-size:12px;
      font-weight:700;
      white-space:nowrap;
    }

    .status.pending{ background:var(--yellow); color:#9d6900; }
    .status.done{ background:var(--green); color:#178248; }
    .status.rejected{ background:var(--red); color:#bf3e3e; }
    .status.info{ background:var(--blue-soft); color:#178cca; }
    .status.purple{ background:var(--purple); color:#6f55c9; }

    .round-btn{
      border:0;
      width:38px;
      height:38px;
      border-radius:14px;
      background:#fff;
      box-shadow:0 8px 16px rgba(19,34,56,.05);
      color:#40546d;
      cursor:pointer;
    }

    .timeline{
      display:flex;
      flex-direction:column;
      gap:14px;
    }

    .timeline-item{
      display:flex;
      gap:14px;
      padding:14px 0;
      border-bottom:1px solid rgba(228,237,245,.9);
    }

    .timeline-item:last-child{ border-bottom:0; }

    .timeline-dot{
      width:12px;
      height:12px;
      border-radius:50%;
      background:var(--blue);
      box-shadow:0 0 0 6px rgba(54,184,255,.12);
      margin-top:6px;
      flex:0 0 auto;
    }

    .timeline-item time{
      display:block;
      color:#1a91ce;
      font-size:12px;
      font-weight:800;
      margin-bottom:4px;
    }

    .timeline-item p{
      margin:0;
      color:#43576e;
      font-size:14px;
      line-height:1.45;
    }

    .card-img{
      width:100%;
      height:125px;
      object-fit:cover;
      display:block;
      border-radius:24px;
      background:#eef7fc;
      margin-bottom:16px;
    }

    .course-card{
      background:#fff;
      border-radius:30px;
      box-shadow:var(--shadow);
      padding:20px;
      min-height:100%;
    }

    .course-card h3{
      margin:0;
      font-size:18px;
      letter-spacing:-.2px;
    }

    .course-card p{
      margin:8px 0 0;
      color:#6b7d92;
      font-size:13px;
      line-height:1.45;
    }

    .card-meta{
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:12px;
      margin-top:16px;
    }

    .schedule-list{
      display:flex;
      flex-direction:column;
      gap:12px;
      margin-top:8px;
    }

    .schedule-row{
      display:grid;
      grid-template-columns:150px 120px 1fr auto;
      gap:16px;
      align-items:center;
      padding:15px 16px;
      border-radius:22px;
      background:#fbfdff;
    }

    .schedule-row strong{
      font-size:15px;
    }

    .time-range{
      color:#55697f;
      font-size:14px;
    }

    .small-action{
      border:0;
      background:#edf8ff;
      color:#198eca;
      padding:9px 12px;
      border-radius:999px;
      font-size:12px;
      font-weight:700;
      cursor:pointer;
    }

    .earnings-panel{
      min-height:560px;
    }

    .earnings-summary{
      display:grid;
      grid-template-columns:repeat(3, minmax(0,1fr));
      gap:12px;
      margin-bottom:20px;
    }

    .earnings-box{
      background:#fbfdff;
      border-radius:22px;
      padding:16px;
    }

    .earnings-box span{
      display:block;
      font-size:12px;
      color:#74859a;
      font-weight:600;
    }

    .earnings-box strong{
      display:block;
      margin-top:8px;
      font-size:24px;
      letter-spacing:-.4px;
    }

    .earnings-visual{
      display:grid;
      grid-template-columns:58px 1fr;
      gap:14px;
      margin-top:10px;
    }

    .y-axis{
      height:320px;
      display:flex;
      flex-direction:column;
      justify-content:space-between;
      padding:18px 0 24px;
    }

    .y-axis span{
      font-size:11px;
      color:#7a8a9d;
      font-weight:700;
    }

    .chart-area{
      position:relative;
      height:320px;
      padding:18px 0 24px;
      border-bottom:1px solid #edf3f8;
    }

    .grid-line{
      position:absolute;
      left:0;
      right:0;
      border-top:1px dashed #e5eef6;
    }

    .g1{ top:18px; }
    .g2{ top:72px; }
    .g3{ top:126px; }
    .g4{ top:180px; }
    .g5{ top:234px; }
    .g6{ top:288px; }

    .bars{
      position:absolute;
      inset:18px 0 24px 0;
      display:flex;
      align-items:stretch;
      justify-content:space-between;
      gap:14px;
    }

    .bar-wrap{
      flex:1;
      min-width:54px;
      height:100%;
      display:flex;
      flex-direction:column;
      align-items:center;
      justify-content:flex-end;
      gap:10px;
    }

    .bar-stack{
      width:100%;
      height:calc(100% - 28px);
      display:flex;
      flex-direction:column;
      align-items:center;
      justify-content:flex-end;
    }

    .bar-badge{
      font-size:12px;
      color:#4f647b;
      font-weight:700;
      background:#f7fbff;
      padding:4px 8px;
      border-radius:999px;
      white-space:nowrap;
      box-shadow:0 3px 10px rgba(19,34,56,.04);
      margin-bottom:8px;
    }

    .bar{
      width:100%;
      max-width:46px;
      border-radius:999px 999px 12px 12px;
      background:linear-gradient(180deg, #39b9ff, #bfe9fb);
      cursor:pointer;
      transition:.18s ease;
      min-height:30px;
    }

    .bar:hover{ transform:translateY(-4px); }

    .bar-month{
      font-size:12px;
      color:#65788d;
      font-weight:700;
    }

    .earnings-table{
      margin-top:18px;
      display:grid;
      gap:10px;
    }

    .earnings-row{
      display:grid;
      grid-template-columns:84px 1fr 84px 90px;
      align-items:center;
      gap:12px;
      padding:11px 14px;
      border-radius:18px;
      background:#fbfdff;
      font-size:13px;
    }

    .earnings-row strong{
      font-size:13px;
    }

    .muted{
      color:#6e8094;
    }

    .drawer-backdrop{
      position:fixed;
      inset:0;
      background:rgba(19,34,56,.25);
      opacity:0;
      pointer-events:none;
      transition:.2s ease;
      z-index:80;
    }

    .drawer-backdrop.show{
      opacity:1;
      pointer-events:auto;
    }

    .drawer{
      position:fixed;
      top:0;
      right:0;
      height:100vh;
      width:min(440px, 92vw);
      background:#fff;
      box-shadow:-24px 0 60px rgba(19,34,56,.14);
      transform:translateX(102%);
      transition:.25s ease;
      z-index:81;
      overflow-y:auto;
      padding:26px;
    }

    .drawer.show{ transform:translateX(0); }

    .drawer-head{
      display:flex;
      justify-content:space-between;
      align-items:center;
      gap:14px;
      margin-bottom:20px;
    }

    .drawer-head h3{
      margin:0;
      font-size:24px;
      letter-spacing:-.4px;
    }

    .close-btn{
      border:0;
      width:42px;
      height:42px;
      border-radius:15px;
      background:#f3f8fc;
      cursor:pointer;
    }

    .drawer-card{
      background:#f8fcff;
      border-radius:24px;
      padding:18px;
      margin-bottom:14px;
    }

    .drawer-card small{
      display:block;
      color:#718299;
      font-weight:700;
      margin-bottom:8px;
    }

    .drawer-card p{
      margin:0;
      color:#455971;
      line-height:1.55;
      font-size:14px;
    }

    .drawer-actions{
      display:flex;
      gap:10px;
      margin-top:20px;
    }

    .drawer-actions button{
      flex:1;
      border:0;
      border-radius:999px;
      padding:12px 14px;
      font-weight:800;
      cursor:pointer;
    }

    .toast{
      position:fixed;
      left:50%;
      bottom:28px;
      transform:translate(-50%, 18px);
      opacity:0;
      pointer-events:none;
      z-index:90;
      background:#132238;
      color:#fff;
      border-radius:999px;
      padding:12px 18px;
      font-size:13px;
      font-weight:700;
      transition:.2s ease;
    }

    .toast.show{
      opacity:1;
      transform:translate(-50%,0);
    }

    @media (max-width: 1200px){
      .hero-grid,
      .two-col,
      .lower-grid{
        grid-template-columns:1fr;
      }

      .three-grid{
        grid-template-columns:repeat(2,minmax(0,1fr));
      }

      .topnav{
        flex-wrap:wrap;
        padding:10px 0;
      }

      .nav-links{
        order:3;
        width:100%;
        justify-content:flex-start;
        overflow:auto;
        white-space:nowrap;
        padding-bottom:4px;
      }
    }

    @media (max-width: 900px){
      .earnings-summary{
        grid-template-columns:1fr;
      }

      .earnings-row{
        grid-template-columns:1fr;
      }

      .schedule-row{
        grid-template-columns:1fr;
      }

      .earnings-visual{
        grid-template-columns:1fr;
      }

      .y-axis{
        display:none;
      }
    }

    @media (max-width: 760px){
      .container{
        width:min(100% - 24px, 100%);
      }

      .search{
        display:none;
      }

      .hero-card,.hero-side,.panel,.course-card,.stat-card{
        border-radius:24px;
      }

      .three-grid{
        grid-template-columns:1fr;
      }

      .list-item{
        grid-template-columns:auto 1fr;
      }

      .item-actions{
        grid-column:1 / -1;
        justify-content:flex-end;
      }

      .brand-text span,
      .profile span{
        display:none;
      }
    }
  </style>
</head>
<body>

  <div class="top-shell">
    <div class="container">
      <header class="topnav">
        <a href="tutor_dashboard.php" class="brand">
          <img src="../assets/img/Logo.png" alt="Kyoshi logo">
          <div class="brand-text">
            <strong>Kyoshi</strong>
            <span>Tutor studio</span>
          </div>
        </a>

        <nav class="nav-links">
          <a class="active" href="#overview">Overview</a>
          <a href="#requests">Requests</a>
          <a href="#schedule">Schedule</a>
          <a href="#materials">Materials</a>
          <a href="#earnings">Earnings</a>
          <a href="#reviews">Reviews</a>
        </nav>

        <div class="nav-actions">
          <div class="search">
            <i class="bi bi-search"></i>
            <input id="globalSearch" type="text" placeholder="Search student, booking, material...">
          </div>

          <button class="icon-btn" onclick="openDrawer('Notifications','Two booking requests are waiting. One session report is due after class.')">
            <i class="bi bi-bell"></i>
            <span class="icon-dot"></span>
          </button>

          <button class="profile" onclick="openDrawer('Tutor profile','Signed in as <?= htmlspecialchars($displayName) ?>. Later, this can show verification status and tutor information from database.')">
            <img src="../assets/img/tutor.jpg" alt="Tutor avatar">
            <span><?= htmlspecialchars($displayName) ?></span>
          </button>
        </div>
      </header>
    </div>
  </div>

  <main class="container">
    <section class="hero" id="overview">
      <div class="hero-grid">
        <article class="hero-card">
          <div class="hero-copy">
            <div class="eyebrow"><span class="pulse"></span><span>Teaching mode on</span></div>
            <h1>Your teaching day, in one place.</h1>
            <p>Two requests waiting. One English session at 3:30 PM. Materials, schedule, and progress are all here.</p>
          </div>

          <div class="hero-actions">
            <button class="btn-primary" onclick="scrollToSection('requests')">Review requests</button>
            <button class="btn-ghost" onclick="scrollToSection('schedule')">Set availability</button>
            <button class="btn-text" onclick="showToast('Session report opened')">Write report</button>
          </div>
        </article>

        <aside class="hero-side">
          <div>
            <div class="clock" id="clock">--:--</div>
            <div class="date-line" id="dateText">Loading date...</div>
          </div>

          <div class="side-note">
            <div>
              <strong>Next session</strong>
              <p>English Speaking · Kay Hueen · 3:30 PM</p>
            </div>
            <button class="btn-ghost" onclick="openDrawer('Next session','English Speaking with Kay Hueen at 3:30 PM. Mode: Online. Status: Confirmed.')">Open</button>
          </div>
        </aside>
      </div>
    </section>

    <section class="block">
      <div class="section-head">
        <div>
          <h2>Snapshot</h2>
          <p>Your tutoring day in one row.</p>
        </div>
      </div>

      <div class="stats-scroll">
        <article class="stat-card">
          <span>Booking requests</span>
          <strong><?= $requestCount['total'] ?></strong>
          <small>2 new today</small>
        </article>
        <article class="stat-card yellow">
          <span>Classes today</span>
          <strong>4</strong>
          <small>Next at 3:30 PM</small>
        </article>
        <article class="stat-card green">
          <span>Monthly earnings</span>
          <strong>RM 820</strong>
          <small>12 completed sessions</small>
        </article>
        <article class="stat-card pink">
          <span>Average rating</span>
          <strong>4.8</strong>
          <small>From 32 reviews</small>
        </article>
        <article class="stat-card purple">
          <span>Materials shared</span>
          <strong>16</strong>
          <small>PDF, notes, links</small>
        </article>
      </div>
    </section>

    <section class="block two-col" id="requests">
      <div class="panel">
        <div class="panel-top">
          <h3>Booking requests</h3>
          <div class="chips">
            <button class="chip active" data-filter="all">All</button>
            <button class="chip" data-filter="new">New</button>
            <button class="chip" data-filter="confirmed">Confirmed</button>
            <button class="chip" data-filter="report">Report due</button>
          </div>
        </div>

        <div class="list" data-filter-list>
          <div class="list-item new searchable">
            <img class="avatar" src="../assets/img/student.jpg" alt="Student">
            <div class="item-main">
              <strong>Chin Ming</strong>
              <p>English speaking · Tue 4:00 PM · Online</p>
            </div>
            <div class="item-actions">
              <span class="status pending">New</span>
              <button class="round-btn" onclick="showToast('Request accepted')"><i class="bi bi-check-lg"></i></button>
              <button class="round-btn" onclick="openDrawer('Booking request','Student: Chin Ming. Requested English speaking class on Tuesday 4:00 PM. You can accept or reject this request in PHP later.')"><i class="bi bi-arrow-right"></i></button>
            </div>
          </div>

          <div class="list-item new searchable">
            <img class="avatar" src="../assets/img/kk.jpg" alt="Student">
            <div class="item-main">
              <strong>Nur Aina</strong>
              <p>Presentation practice · Weekend slot requested</p>
            </div>
            <div class="item-actions">
              <span class="status pending">New</span>
              <button class="round-btn" onclick="showToast('Request accepted')"><i class="bi bi-check-lg"></i></button>
              <button class="round-btn" onclick="openDrawer('Booking request','Student: Nur Aina. Requested weekend presentation practice session.')"><i class="bi bi-arrow-right"></i></button>
            </div>
          </div>

          <div class="list-item confirmed searchable">
            <img class="avatar" src="../assets/img/english.webp" alt="Class">
            <div class="item-main">
              <strong>Kay Hueen</strong>
              <p>English speaking · Today 3:30 PM · Confirmed</p>
            </div>
            <div class="item-actions">
              <span class="status done">Confirmed</span>
              <button class="round-btn" onclick="openDrawer('Confirmed session','English speaking session with Kay Hueen. Add lesson report after class.')"><i class="bi bi-arrow-right"></i></button>
            </div>
          </div>

          <div class="list-item report searchable">
            <img class="avatar" src="../assets/img/malay.jpg" alt="Report">
            <div class="item-main">
              <strong>Taarunesh</strong>
              <p>Session completed · Lesson report due</p>
            </div>
            <div class="item-actions">
              <span class="status info">Report</span>
              <button class="round-btn" onclick="openDrawer('Session report','Write lesson summary, topics covered, and student feedback here.')"><i class="bi bi-pencil"></i></button>
            </div>
          </div>
        </div>
      </div>

      <aside class="panel">
        <div class="panel-top">
          <h3>Today</h3>
          <button class="btn-text" onclick="showToast('Schedule opened')">Schedule</button>
        </div>

        <div class="timeline">
          <div class="timeline-item">
            <span class="timeline-dot"></span>
            <div>
              <time>09:00</time>
              <p>Check new booking requests</p>
            </div>
          </div>
          <div class="timeline-item">
            <span class="timeline-dot"></span>
            <div>
              <time>15:30</time>
              <p>English speaking with Kay Hueen</p>
            </div>
          </div>
          <div class="timeline-item">
            <span class="timeline-dot"></span>
            <div>
              <time>18:00</time>
              <p>Upload presentation notes</p>
            </div>
          </div>
          <div class="timeline-item">
            <span class="timeline-dot"></span>
            <div>
              <time>21:00</time>
              <p>Submit session report</p>
            </div>
          </div>
        </div>
      </aside>
    </section>

    <section class="block lower-grid" id="schedule">
      <div class="panel">
        <div class="panel-top">
          <h3>Availability</h3>
          <button class="btn-ghost" onclick="showToast('Availability saved')">Save slots</button>
        </div>

        <div class="schedule-list">
          <div class="schedule-row">
            <strong>Monday</strong>
            <span class="status done">Free</span>
            <div class="time-range">9:00 AM - 6:00 PM</div>
            <button class="small-action" onclick="showToast('Monday slot edited')">Edit</button>
          </div>

          <div class="schedule-row">
            <strong>Tuesday</strong>
            <span class="status done">Free</span>
            <div class="time-range">10:00 AM - 6:00 PM</div>
            <button class="small-action" onclick="showToast('Tuesday slot edited')">Edit</button>
          </div>

          <div class="schedule-row">
            <strong>Wednesday</strong>
            <span class="status rejected">Unavailable</span>
            <div class="time-range">Not available for booking</div>
            <button class="small-action" onclick="showToast('Wednesday slot edited')">Edit</button>
          </div>

          <div class="schedule-row">
            <strong>Thursday</strong>
            <span class="status done">Free</span>
            <div class="time-range">2:00 PM - 8:00 PM</div>
            <button class="small-action" onclick="showToast('Thursday slot edited')">Edit</button>
          </div>

          <div class="schedule-row">
            <strong>Friday</strong>
            <span class="status done">Free</span>
            <div class="time-range">9:00 AM - 4:00 PM</div>
            <button class="small-action" onclick="showToast('Friday slot edited')">Edit</button>
          </div>

          <div class="schedule-row">
            <strong>Saturday</strong>
            <span class="status pending">Limited</span>
            <div class="time-range">10:00 AM - 1:00 PM</div>
            <button class="small-action" onclick="showToast('Saturday slot edited')">Edit</button>
          </div>

          <div class="schedule-row">
            <strong>Sunday</strong>
            <span class="status rejected">Unavailable</span>
            <div class="time-range">Rest day</div>
            <button class="small-action" onclick="showToast('Sunday slot edited')">Edit</button>
          </div>
        </div>
      </div>

      <div class="panel earnings-panel" id="earnings">
        <div class="panel-top">
          <h3>Earnings</h3>
          <button class="btn-text" onclick="showToast('Payout request opened')">Request payout</button>
        </div>

        <div class="earnings-summary">
          <div class="earnings-box">
            <span>This month</span>
            <strong>RM 820</strong>
          </div>
          <div class="earnings-box">
            <span>Completed sessions</span>
            <strong>12</strong>
          </div>
          <div class="earnings-box">
            <span>Pending payout</span>
            <strong>RM 210</strong>
          </div>
        </div>

        <div class="earnings-visual">
          <div class="y-axis">
            <span>RM 1000</span>
            <span>RM 800</span>
            <span>RM 600</span>
            <span>RM 400</span>
            <span>RM 200</span>
            <span>RM 0</span>
          </div>

          <div class="chart-area">
            <div class="grid-line g1"></div>
            <div class="grid-line g2"></div>
            <div class="grid-line g3"></div>
            <div class="grid-line g4"></div>
            <div class="grid-line g5"></div>
            <div class="grid-line g6"></div>

            <div class="bars">
              <div class="bar-wrap">
                <div class="bar-stack">
                  <div class="bar-badge">RM 320</div>
                  <div class="bar" style="height:32%;"></div>
                </div>
                <div class="bar-month">Jan</div>
              </div>

              <div class="bar-wrap">
                <div class="bar-stack">
                  <div class="bar-badge">RM 520</div>
                  <div class="bar" style="height:52%;"></div>
                </div>
                <div class="bar-month">Feb</div>
              </div>

              <div class="bar-wrap">
                <div class="bar-stack">
                  <div class="bar-badge">RM 480</div>
                  <div class="bar" style="height:48%;"></div>
                </div>
                <div class="bar-month">Mar</div>
              </div>

              <div class="bar-wrap">
                <div class="bar-stack">
                  <div class="bar-badge">RM 670</div>
                  <div class="bar" style="height:67%;"></div>
                </div>
                <div class="bar-month">Apr</div>
              </div>

              <div class="bar-wrap">
                <div class="bar-stack">
                  <div class="bar-badge">RM 760</div>
                  <div class="bar" style="height:76%;"></div>
                </div>
                <div class="bar-month">May</div>
              </div>

              <div class="bar-wrap">
                <div class="bar-stack">
                  <div class="bar-badge">RM 590</div>
                  <div class="bar" style="height:59%;"></div>
                </div>
                <div class="bar-month">Jun</div>
              </div>
            </div>
          </div>
        </div>

        <div class="earnings-table">
          <div class="earnings-row">
            <strong>Jan</strong>
            <div class="muted">5 sessions completed</div>
            <div>RM 320</div>
            <span class="status done">Paid</span>
          </div>

          <div class="earnings-row">
            <strong>Feb</strong>
            <div class="muted">8 sessions completed</div>
            <div>RM 520</div>
            <span class="status done">Paid</span>
          </div>

          <div class="earnings-row">
            <strong>Mar</strong>
            <div class="muted">7 sessions completed</div>
            <div>RM 480</div>
            <span class="status done">Paid</span>
          </div>

          <div class="earnings-row">
            <strong>Apr</strong>
            <div class="muted">10 sessions completed</div>
            <div>RM 670</div>
            <span class="status done">Paid</span>
          </div>

          <div class="earnings-row">
            <strong>May</strong>
            <div class="muted">11 sessions completed</div>
            <div>RM 760</div>
            <span class="status done">Paid</span>
          </div>

          <div class="earnings-row">
            <strong>Jun</strong>
            <div class="muted">9 sessions completed</div>
            <div>RM 590</div>
            <span class="status pending">Pending</span>
          </div>
        </div>
      </div>
    </section>

    <section class="block three-grid" id="materials">
      <article class="course-card searchable">
        <img class="card-img" src="../assets/img/english.webp" alt="Material">
        <h3>Speaking phrases</h3>
        <p>PDF notes for beginner conversation class.</p>
        <div class="card-meta">
          <span class="status done">Shared</span>
          <button class="btn-primary" onclick="showToast('Material updated')">Update</button>
        </div>
      </article>

      <article class="course-card searchable">
        <img class="card-img" src="../assets/img/login.jpg" alt="Material">
        <h3>Presentation checklist</h3>
        <p>Useful before student speaking assessment.</p>
        <div class="card-meta">
          <span class="status info">Draft</span>
          <button class="btn-primary" onclick="showToast('Material uploaded')">Upload</button>
        </div>
      </article>

      <article class="course-card searchable">
        <img class="card-img" src="../assets/img/herobg.png" alt="Material">
        <h3>Lesson summary form</h3>
        <p>Template for post-session reporting.</p>
        <div class="card-meta">
          <span class="status purple">Template</span>
          <button class="btn-primary" onclick="openDrawer('Lesson summary form','Use this template to write lesson summary, student progress, homework, and next lesson plan.')">Open</button>
        </div>
      </article>
    </section>

    <section class="block lower-grid" id="reviews" style="margin-bottom:60px;">
      <div class="panel">
        <div class="panel-top">
          <h3>Recent reviews</h3>
          <button class="btn-text" onclick="showToast('All reviews opened')">Open all</button>
        </div>

        <div class="list">
          <div class="list-item searchable">
            <img class="avatar" src="../assets/img/student.jpg" alt="Student">
            <div class="item-main">
              <strong>Chin Ming</strong>
              <p>Clear explanation and friendly class. ⭐⭐⭐⭐⭐</p>
            </div>
            <div class="item-actions">
              <span class="status done">5.0</span>
            </div>
          </div>

          <div class="list-item searchable">
            <img class="avatar" src="../assets/img/kk.jpg" alt="Student">
            <div class="item-main">
              <strong>Nur Aina</strong>
              <p>Helpful for presentation practice. ⭐⭐⭐⭐⭐</p>
            </div>
            <div class="item-actions">
              <span class="status done">4.8</span>
            </div>
          </div>
        </div>
      </div>

      <div class="panel">
        <div class="panel-top">
          <h3>Student progress</h3>
          <button class="btn-text" onclick="showToast('Progress notes opened')">Notes</button>
        </div>

        <div class="list">
          <div class="list-item searchable">
            <img class="avatar" src="../assets/img/student.jpg" alt="Student">
            <div class="item-main">
              <strong>Chin Ming</strong>
              <p>Participation is improving · Homework submitted regularly</p>
            </div>
            <div class="item-actions">
              <span class="status info">76%</span>
            </div>
          </div>

          <div class="list-item searchable">
            <img class="avatar" src="../assets/img/english.webp" alt="Student">
            <div class="item-main">
              <strong>Kay Hueen</strong>
              <p>Strong speaking confidence · Good class attendance</p>
            </div>
            <div class="item-actions">
              <span class="status info">88%</span>
            </div>
          </div>

          <div class="list-item searchable">
            <img class="avatar" src="../assets/img/kk.jpg" alt="Student">
            <div class="item-main">
              <strong>Nur Aina</strong>
              <p>Needs more practice in presentation structure</p>
            </div>
            <div class="item-actions">
              <span class="status info">69%</span>
            </div>
          </div>
        </div>
      </div>
    </section>
  </main>

  <div class="drawer-backdrop" id="drawerBackdrop" onclick="closeDrawer()"></div>
  <aside class="drawer" id="drawer">
    <div class="drawer-head">
      <h3 id="drawerTitle">Details</h3>
      <button class="close-btn" onclick="closeDrawer()"><i class="bi bi-x-lg"></i></button>
    </div>
    <div class="drawer-card">
      <small>Status</small>
      <p id="drawerText">Details appear here.</p>
    </div>
    <div class="drawer-actions">
  <a href="logout.php" style="flex:1;">
    <button class="btn-logout" style="width:100%;">
      Logout
    </button>
  </a>
</div>
  </aside>

  <div class="toast" id="toast">Saved</div>

  <script>
    const clock = document.getElementById("clock");
    const dateText = document.getElementById("dateText");

    function updateDateTime(){
      const now = new Date();

      clock.textContent = now.toLocaleTimeString("en-MY", {
        hour:"2-digit",
        minute:"2-digit"
      });

      dateText.textContent = now.toLocaleDateString("en-MY", {
        weekday:"long",
        day:"numeric",
        month:"long",
        year:"numeric"
      });
    }

    updateDateTime();
    setInterval(updateDateTime, 1000);

    function scrollToSection(id){
      document.getElementById(id).scrollIntoView({ behavior:"smooth", block:"start" });
    }

    const chips = document.querySelectorAll(".chip");
    const listItems = document.querySelectorAll("[data-filter-list] .list-item");

    chips.forEach(chip => {
      chip.addEventListener("click", () => {
        chips.forEach(c => c.classList.remove("active"));
        chip.classList.add("active");
        const filter = chip.dataset.filter;

        listItems.forEach(item => {
          item.style.display = filter === "all" || item.classList.contains(filter) ? "grid" : "none";
        });
      });
    });

    const searchInput = document.getElementById("globalSearch");
    if(searchInput){
      searchInput.addEventListener("input", () => {
        const value = searchInput.value.trim().toLowerCase();
        const searchable = document.querySelectorAll(".searchable");

        searchable.forEach(item => {
          item.style.display = item.innerText.toLowerCase().includes(value) ? "" : "none";
        });
      });
    }

    function openDrawer(title, text){
      document.getElementById("drawerTitle").textContent = title;
      document.getElementById("drawerText").textContent = text;
      document.getElementById("drawerBackdrop").classList.add("show");
      document.getElementById("drawer").classList.add("show");
    }

    function closeDrawer(){
      document.getElementById("drawerBackdrop").classList.remove("show");
      document.getElementById("drawer").classList.remove("show");
    }

    let toastTimer;
    function showToast(message){
      const toast = document.getElementById("toast");
      toast.textContent = message;
      toast.classList.add("show");
      clearTimeout(toastTimer);
      toastTimer = setTimeout(() => toast.classList.remove("show"), 1800);
    }
  </script>
</body>
</html>
