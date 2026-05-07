<?php
session_start();
include "config.php";

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
    header("Location: ../login.php");
    exit();
}


$displayName = $_SESSION['fullname'] ?? 'Admin';

$tutorQuery = mysqli_query($conn,
    "SELECT COUNT(*) AS total
     FROM users
     WHERE role='tutor'
     AND status='pending'"
);

$tutorData = mysqli_fetch_assoc($tutorQuery);
$pendingTutors = $tutorData['total'];

$studentQuery = mysqli_query($conn,
    "SELECT COUNT(*) AS total
     FROM users
     WHERE role='student'"
);

$studentData = mysqli_fetch_assoc($studentQuery);
$totalStudents = $studentData['total'];

?>


<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Kyoshi Admin Dashboard</title>
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
      --radius-lg:26px;
      --radius-md:20px;
      --yellow:#fff4da;
      --green:#e6f8ee;
      --red:#ffe8e8;
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

    /* top nav */

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
      width:240px;
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

    /* page heading */

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
      min-height:220px;
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
      max-width:560px;
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
    .btn-logout{
  display:flex;
  justify-content:center;
  align-items:center;
  text-decoration:none;
  width:100%;
  border-radius:999px;
  padding:12px 14px;
  font-weight:800;
  font-size:13px;
  background:#ff3b3b;
  color:white;
  box-shadow:0 10px 24px rgba(255,59,59,.25);
  transition:.2s ease;
}

.btn-logout:hover{
  background:#e60000;
  transform:translateY(-1px);
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
      min-height:220px;
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

    /* common sections */

    section.block{
      margin-top:28px;
    }

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

    .section-head .right-action{
      color:#1b91cd;
      font-weight:700;
      font-size:14px;
      white-space:nowrap;
    }

    /* horizontal stats */

    .stats-scroll{
      display:flex;
      gap:16px;
      overflow-x:auto;
      padding:4px 2px 6px;
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

    /* queue + activity */

    .two-col{
      display:grid;
      grid-template-columns:1.25fr .75fr;
      gap:22px;
      align-items:start;
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

    .queue-list{
      display:flex;
      flex-direction:column;
      gap:12px;
    }

    .queue-item{
      display:grid;
      grid-template-columns:auto 1fr auto;
      align-items:center;
      gap:16px;
      background:#fbfdff;
      border-radius:24px;
      padding:14px;
      transition:.18s ease;
    }

    .queue-item:hover{
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

    .queue-main strong{
      display:block;
      font-size:15px;
    }

    .queue-main p{
      margin:5px 0 0;
      color:#6b7d92;
      font-size:13px;
      line-height:1.4;
    }

    .queue-actions{
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

    /* payment cards */

    .payment-grid{
      display:grid;
      grid-template-columns:repeat(3, minmax(0,1fr));
      gap:18px;
    }

    .payment-card{
      background:#fff;
      border-radius:30px;
      box-shadow:var(--shadow);
      padding:20px;
    }

    .payment-card img{
      width:100%;
      height:122px;
      object-fit:cover;
      display:block;
      border-radius:24px;
      background:#eef7fc;
      margin-bottom:16px;
    }

    .line{
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:14px;
      margin:8px 0;
      font-size:14px;
    }

    .line span{
      color:#74859a;
    }

    .amount{
      margin-top:10px;
      font-size:24px;
      font-weight:700;
      letter-spacing:-.5px;
    }

    .pay-actions{
      display:flex;
      gap:10px;
      margin-top:16px;
    }

    .pay-actions button{
      flex:1;
      border:0;
      border-radius:999px;
      padding:11px 12px;
      font-weight:700;
      font-size:13px;
      cursor:pointer;
    }

    .approve{ background:var(--blue); color:#fff; }
    .soft{ background:#edf8ff; color:#228cc5; }

    /* lower sections */
    .lower-grid{
      display:grid;
      grid-template-columns:1.05fr .95fr;
      gap:22px;
      align-items:start;
    }

    .chart-panel{
      min-height:390px;
    }

    .bars{
      height:250px;
      display:flex;
      align-items:flex-end;
      justify-content:space-between;
      gap:14px;
      padding-top:14px;
    }

    .bar-wrap{
      flex:1;
      display:flex;
      flex-direction:column;
      align-items:center;
      gap:10px;
    }

    .bar{
      width:100%;
      max-width:44px;
      border-radius:999px 999px 12px 12px;
      background:linear-gradient(180deg, #39b9ff, #bfe9fb);
      cursor:pointer;
      transition:.18s ease;
      min-height:30px;
    }

    .bar:hover{ transform:translateY(-4px); }
    .bar-wrap span{
      font-size:12px;
      color:#65788d;
      font-weight:700;
    }

    .people-list{
      display:flex;
      flex-direction:column;
      gap:14px;
    }

    .person{
      display:flex;
      align-items:center;
      gap:13px;
      background:#fbfdff;
      border-radius:22px;
      padding:12px;
    }

    .person img{
      width:52px;
      height:52px;
      object-fit:cover;
      border-radius:18px;
      display:block;
    }

    .person strong{
      display:block;
      font-size:14px;
    }

    .person span{
      display:block;
      margin-top:4px;
      font-size:12px;
      color:#6e8094;
    }

    .tag{
      margin-left:auto;
      background:#eef8ff;
      color:#198eca;
      padding:8px 11px;
      border-radius:999px;
      font-size:12px;
      font-weight:800;
      white-space:nowrap;
    }

    .notes-panel .queue-item{
      background:#f9fcff;
    }

    /* drawer + toast */

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

    /* responsive */

    @media (max-width: 1200px){
      .hero-grid,
      .two-col,
      .lower-grid{
        grid-template-columns:1fr;
      }

      .payment-grid{
        grid-template-columns:repeat(2, minmax(0,1fr));
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

    @media (max-width: 760px){
      .container{
        width:min(100% - 24px, 100%);
      }

      .search{
        display:none;
      }

      .hero-card,.hero-side,.panel,.payment-card,.stat-card{
        border-radius:24px;
      }

      .payment-grid{
        grid-template-columns:1fr;
      }

      .queue-item{
        grid-template-columns:auto 1fr;
      }

      .queue-actions{
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
        <a href="admin_dashboard.php" class="brand">
          <img src="../assets/img/Logo.png" alt="Kyoshi logo">
          <div class="brand-text">
            <strong>Kyoshi</strong>
            <span>Admin space</span>
          </div>
        </a>

        <nav class="nav-links">
          <a class="active" href="#overview">Overview</a>
          <a href="#queue">Review Queue</a>
          <a href="#payments">Payments</a>
          <a href="#bookings">Bookings</a>
          <a href="#users">Users</a>
          <a href="#reports">Reports</a>
          <a href="#settings">Settings</a>
        </nav>

        <div class="nav-actions">
          <div class="search">
            <i class="bi bi-search"></i>
            <input id="globalSearch" type="text" placeholder="Search tutor, payment, booking...">
          </div>

          <button class="icon-btn" onclick="openDrawer('Notifications','New tutor applications, payment proof uploads, and booking updates are waiting for review.')">
            <i class="bi bi-bell"></i>
            <span class="icon-dot"></span>
          </button>

          <button class="profile" onclick="openDrawer('Admin profile','Signed in as <?php echo htmlspecialchars($displayName); ?>. Later, this can be connected to your PHP session and database.')">
            <img src="../assets/img/student.jpg" alt="Admin avatar">
            <span><?php echo htmlspecialchars($displayName); ?></span>
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
            <div class="eyebrow"><span class="pulse"></span><span>Everything is live</span></div>
            <h1>Admin</h1>
            <p>Short queue, clean records, no mysterious screenshots. A good day.</p>
          </div>

          <div class="hero-actions">
            <button class="btn-primary" onclick="scrollToSection('queue')">Review queue</button>
            <button class="btn-ghost" onclick="scrollToSection('payments')">Check payments</button>
            <button class="btn-text" onclick="showToast('Export report is a demo action for now')">Export report</button>
          </div>
        </article>

        <aside class="hero-side">
          <div>
            <div class="clock" id="clock">--:--</div>
            <div class="date-line" id="dateText">Loading date...</div>
          </div>

          <div class="side-note">
            <div>
              <strong>Quick note</strong>
              <p>6 tutor applications and 9 payment proofs are waiting today.</p>
            </div>
            <button class="btn-ghost" onclick="openDrawer('Quick note','Tutor applications and payment proofs are waiting for review. This card can later pull real counts from your database.')">Open</button>
          </div>
        </aside>
      </div>
    </section>

    <section class="block">
      <div class="section-head">
        <div>
          <h2>Snapshot</h2>
          <p>Only the numbers you probably care about.</p>
        </div>
      </div>

      <div class="stats-scroll">
        <article class="stat-card">
          <span>Pending tutors</span>
          <strong><?php echo $pendingTutors; ?></strong>
          <small>2 new today</small>
        </article>
        <article class="stat-card yellow">
          <span>Payment checks</span>
          <strong>9</strong>
          <small>Proofs waiting</small>
        </article>
        <article class="stat-card green">
          <span>Bookings today</span>
          <strong>14</strong>
          <small>8 completed</small>
        </article>
        <article class="stat-card pink">
          <span>Active students</span>
          <strong><?php echo $totalStudents; ?></strong>
          <small>This week</small>
        </article>
        <article class="stat-card">
          <span>Monthly sales</span>
          <strong>RM 2.4k</strong>
          <small>Manual payment</small>
        </article>
      </div>
    </section>

    <section class="block two-col" id="queue">
      <div class="panel">
        <div class="panel-top">
          <h3>Review queue</h3>
          <div class="chips">
            <button class="chip active" data-filter="all">All</button>
            <button class="chip" data-filter="tutor">Tutors</button>
            <button class="chip" data-filter="payment">Payments</button>
            <button class="chip" data-filter="booking">Bookings</button>
          </div>
        </div>

        <div class="queue-list" id="queueList">
          <div class="queue-item tutor searchable">
            <img class="avatar" src="../assets/img/japanese.webp" alt="Tutor">
            <div class="queue-main">
              <strong>Haruka Tan</strong>
              <p>Japanese tutor application · Certificate uploaded</p>
            </div>
            <div class="queue-actions">
              <span class="status pending">Pending</span>
              <button class="round-btn" onclick="openDrawer('Haruka Tan','Japanese tutor application. Review document, tutor bio, hourly rate, and language level before approval.')"><i class="bi bi-arrow-right"></i></button>
            </div>
          </div>

          <div class="queue-item payment searchable">
            <img class="avatar" src="../assets/img/student.jpg" alt="Student">
            <div class="queue-main">
              <strong>Chin Ming</strong>
              <p>Payment proof · Japanese Beginner · RM 45.00</p>
            </div>
            <div class="queue-actions">
              <span class="status pending">Need check</span>
              <button class="round-btn" onclick="openDrawer('Payment proof','Student: Chin Ming. Booking: Japanese Beginner. Amount: RM 45.00. Check proof image before verifying.')"><i class="bi bi-arrow-right"></i></button>
            </div>
          </div>

          <div class="queue-item tutor searchable">
            <img class="avatar" src="../assets/img/korean.jpg" alt="Tutor">
            <div class="queue-main">
              <strong>Kim Jisoo</strong>
              <p>Korean tutor verification · Waiting for admin</p>
            </div>
            <div class="queue-actions">
              <span class="status pending">Pending</span>
              <button class="round-btn" onclick="openDrawer('Kim Jisoo','Korean tutor verification. Certificate and tutor profile are ready for review.')"><i class="bi bi-arrow-right"></i></button>
            </div>
          </div>

          <div class="queue-item booking searchable">
            <img class="avatar" src="../assets/img/english.webp" alt="Booking">
            <div class="queue-main">
              <strong>Kay Hueen</strong>
              <p>Booking confirmed · English speaking class</p>
            </div>
            <div class="queue-actions">
              <span class="status done">Confirmed</span>
              <button class="round-btn" onclick="openDrawer('Booking confirmed','English speaking class has been confirmed. This area can later show the full booking details.')"><i class="bi bi-arrow-right"></i></button>
            </div>
          </div>

          <div class="queue-item payment searchable">
            <img class="avatar" src="../assets/img/malay.jpg" alt="Payment">
            <div class="queue-main">
              <strong>Taarunesh</strong>
              <p>Malay Writing · Payment rejected</p>
            </div>
            <div class="queue-actions">
              <span class="status rejected">Rejected</span>
              <button class="round-btn" onclick="openDrawer('Rejected payment','Payment proof was rejected. Later you can add a rejection reason form here.')"><i class="bi bi-arrow-right"></i></button>
            </div>
          </div>
        </div>
      </div>

      <aside class="panel">
        <div class="panel-top">
          <h3>Today</h3>
          <a href="#reports" class="right-action">View all</a>
        </div>

        <div class="timeline">
          <div class="timeline-item">
            <span class="timeline-dot"></span>
            <div>
              <time>09:30</time>
              <p>2 tutor applications received</p>
            </div>
          </div>

          <div class="timeline-item">
            <span class="timeline-dot"></span>
            <div>
              <time>11:10</time>
              <p>Payment proof uploaded</p>
            </div>
          </div>

          <div class="timeline-item">
            <span class="timeline-dot"></span>
            <div>
              <time>14:00</time>
              <p>Booking report exported</p>
            </div>
          </div>

          <div class="timeline-item">
            <span class="timeline-dot"></span>
            <div>
              <time>16:40</time>
              <p>New session marked completed</p>
            </div>
          </div>
        </div>
      </aside>
    </section>

    <section class="block" id="payments" style="margin-top:34px; margin-bottom:42px;">
      <div class="section-head">
        <div>
          <h2>Payment review</h2>
          <p>Small queue. Big responsibility.</p>
        </div>

        <button class="btn-ghost" onclick="showToast('Payment report prepared')">Prepare report</button>
      </div>

      <div class="payment-grid">
        <article class="payment-card searchable">
          <img src="../assets/img/penang.jpg" alt="Payment proof preview">
          <div class="line"><span>Student</span><strong>Chin Ming</strong></div>
          <div class="line"><span>Class</span><strong>Japanese</strong></div>
          <div class="amount">RM 45.00</div>
          <div class="pay-actions">
            <button class="approve" onclick="showToast('Payment approved')">Approve</button>
            <button class="soft" onclick="openDrawer('Payment proof','Demo preview only. Later you can connect this button to uploaded payment proof from your database.')">View</button>
          </div>
        </article>

        <article class="payment-card searchable">
          <img src="../assets/img/english.webp" alt="Payment proof preview">
          <div class="line"><span>Student</span><strong>Kay Hueen</strong></div>
          <div class="line"><span>Class</span><strong>English</strong></div>
          <div class="amount">RM 50.00</div>
          <div class="pay-actions">
            <button class="approve" onclick="showToast('Payment approved')">Approve</button>
            <button class="soft" onclick="openDrawer('Payment proof','English class payment proof preview.')">View</button>
          </div>
        </article>

        <article class="payment-card searchable">
          <img src="../assets/img/malay.jpg" alt="Payment proof preview">
          <div class="line"><span>Student</span><strong>Taarunesh</strong></div>
          <div class="line"><span>Class</span><strong>Malay</strong></div>
          <div class="amount">RM 40.00</div>
          <div class="pay-actions">
            <button class="approve" onclick="showToast('Payment approved')">Approve</button>
            <button class="soft" onclick="openDrawer('Payment proof','Malay class payment proof preview.')">View</button>
          </div>
        </article>
      </div>
    </section>

    <section class="block lower-grid" id="bookings">
      <div class="panel chart-panel">
        <div class="panel-top">
          <h3>Weekly bookings</h3>
          <button class="btn-text" onclick="showToast('Chart is static in the HTML version')">Details</button>
        </div>

        <div class="bars">
          <div class="bar-wrap"><div class="bar" style="height:92px;"></div><span>Mon</span></div>
          <div class="bar-wrap"><div class="bar" style="height:142px;"></div><span>Tue</span></div>
          <div class="bar-wrap"><div class="bar" style="height:76px;"></div><span>Wed</span></div>
          <div class="bar-wrap"><div class="bar" style="height:168px;"></div><span>Thu</span></div>
          <div class="bar-wrap"><div class="bar" style="height:126px;"></div><span>Fri</span></div>
          <div class="bar-wrap"><div class="bar" style="height:196px;"></div><span>Sat</span></div>
          <div class="bar-wrap"><div class="bar" style="height:112px;"></div><span>Sun</span></div>
        </div>
      </div>

      <div class="panel" id="users">
        <div class="panel-top">
          <h3>Recently joined</h3>
          <a href="#reports" class="right-action">Open</a>
        </div>

        <div class="people-list">
          <div class="person searchable">
            <img src="../assets/img/student.jpg" alt="User">
            <div>
              <strong>Chin Ming</strong>
              <span>Student · Japanese</span>
            </div>
            <div class="tag">Student</div>
          </div>

          <div class="person searchable">
            <img src="../assets/img/tutor.jpg" alt="User">
            <div>
              <strong>Daniel Lee</strong>
              <span>Tutor · English</span>
            </div>
            <div class="tag">Tutor</div>
          </div>

          <div class="person searchable">
            <img src="../assets/img/jb.jpg" alt="User">
            <div>
              <strong>Alicia Wong</strong>
              <span>Tutor · Mandarin</span>
            </div>
            <div class="tag">Tutor</div>
          </div>

          <div class="person searchable">
            <img src="../assets/img/kk.jpg" alt="User">
            <div>
              <strong>Nur Aina</strong>
              <span>Student · Malay</span>
            </div>
            <div class="tag">Student</div>
          </div>
        </div>
      </div>
    </section>

    <section class="block panel notes-panel" id="reports" style="margin-top:30px; margin-bottom:42px;">
      <div class="panel-top">
        <h3>System notes</h3>
        <button class="btn-ghost" onclick="showToast('Note saved')">Save note</button>
      </div>

      <div class="queue-list">
        <div class="queue-item searchable">
          <img class="avatar" src="../assets/img/mandarin.png" alt="Note">
          <div class="queue-main">
            <strong>Mandarin demand is up</strong>
            <p>More students searched for Mandarin tutors this week.</p>
          </div>
          <div class="queue-actions">
            <span class="status done">Insight</span>
          </div>
        </div>

        <div class="queue-item searchable">
          <img class="avatar" src="../assets/img/login.jpg" alt="Note">
          <div class="queue-main">
            <strong>Password reset page</strong>
            <p>Keep this clean and simple when converting to PHP.</p>
          </div>
          <div class="queue-actions">
            <span class="status pending">Todo</span>
          </div>
        </div>
      </div>
    </section>

    <section class="block panel" id="settings" style="margin-bottom:60px;">
      <div class="panel-top">
        <h3>Quick actions</h3>
        <button class="btn-text" onclick="showToast('More actions coming later')">More</button>
      </div>

      <div class="hero-actions">
        <button class="btn-primary" onclick="showToast('Tutor verification opened')">Verify tutors</button>
        <button class="btn-ghost" onclick="showToast('Payment screen opened')">Check payments</button>
        <button class="btn-ghost" onclick="showToast('Report export queued')">Export reports</button>
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
  <a href="logout.php" class="btn-logout">Logout</a>
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
    const queueItems = document.querySelectorAll("#queueList .queue-item");

    chips.forEach(chip => {
      chip.addEventListener("click", () => {
        chips.forEach(c => c.classList.remove("active"));
        chip.classList.add("active");
        const filter = chip.dataset.filter;

        queueItems.forEach(item => {
          item.style.display = filter === "all" || item.classList.contains(filter) ? "grid" : "none";
        });
      });
    });

    const searchInput = document.getElementById("globalSearch");

    searchInput.addEventListener("input", () => {
      const value = searchInput.value.trim().toLowerCase();
      const searchable = document.querySelectorAll(".searchable");

      searchable.forEach(item => {
        item.style.display = item.innerText.toLowerCase().includes(value) ? "" : "none";
      });
    });

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
