<?php
session_start();
include 'config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location:login.php");
    exit();
}

$userID = $_SESSION['user_id'];

$query = mysqli_query($conn, "
    SELECT *
    FROM users
    WHERE id = '$userID' AND role='student'
");

$user = mysqli_fetch_assoc($query);

$displayName = $user['fullname'];
$profilePic = $user['profile_pic'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Kyoshi Student Dashboard</title>
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
    button,input,select,textarea{ font-family:inherit; }

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

    section.block{
      margin-top:30px;
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

    .right-action{
      color:#1b91cd;
      font-weight:700;
      font-size:14px;
      white-space:nowrap;
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
      grid-template-columns:1.25fr .75fr;
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

    .card-meta strong{
      font-size:20px;
      letter-spacing:-.3px;
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

    .progress-line{
      display:grid;
      gap:14px;
    }

    .progress-item strong{
      display:flex;
      justify-content:space-between;
      gap:12px;
      font-size:13px;
      margin-bottom:8px;
    }

    .track{
      height:11px;
      border-radius:999px;
      background:#eef5fb;
      overflow:hidden;
    }

    .fill{
      height:100%;
      border-radius:999px;
      background:linear-gradient(90deg,#36b8ff,#9ee4ff);
    }

    .calendar-grid{
      display:grid;
      grid-template-columns:repeat(7,1fr);
      gap:8px;
    }

    .calendar-day{
      min-height:76px;
      padding:10px;
      background:#fbfdff;
      border-radius:18px;
    }

    .calendar-day span{
      display:block;
      font-size:12px;
      color:#71869c;
      font-weight:700;
      margin-bottom:8px;
    }

    .calendar-day small{
      display:inline-block;
      background:#eaf7ff;
      color:#178cca;
      border-radius:999px;
      padding:5px 8px;
      font-size:11px;
      font-weight:700;
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
        <a href="student_dashboard.php" class="brand">
          <img src="../assets/img/Logo.png" alt="Kyoshi logo">
          <div class="brand-text">
            <strong>Kyoshi</strong>
            <span>Student space</span>
          </div>
        </a>

        <nav class="nav-links">
          <a class="active" href="#overview">Overview</a>
          <a href="#discover">Find Tutors</a>
          <a href="#bookings">Bookings</a>
          <a href="#materials">Materials</a>
          <a href="#progress">Progress</a>
          <a href="#favourites">Favourites</a>
        </nav>

        <div class="nav-actions">
          <div class="search">
            <i class="bi bi-search"></i>
            <input id="globalSearch" type="text" placeholder="Search tutor, language, class...">
          </div>

          <button class="icon-btn" onclick="openDrawer('Notifications','Your Japanese booking is confirmed. One payment proof is waiting for admin verification.')">
            <i class="bi bi-bell"></i>
            <span class="icon-dot"></span>
          </button>

            <button class="profile"
                onclick="openDrawer('Student profile','Signed in as <?= htmlspecialchars($displayName) ?>')">

                <img src="../assets/img/student.jpg" alt="Student avatar">
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
            <div class="eyebrow"><span class="pulse"></span><span>Next class is ready</span></div>
            <h1>Learn without the tab chaos.</h1>
            <p>Japanese at 4:00 PM. Payment is verified. Brain not included.</p>
          </div>

          <div class="hero-actions">
            <button class="btn-primary" onclick="scrollToSection('bookings')">View booking</button>
            <button class="btn-ghost" onclick="scrollToSection('discover')">Find tutor</button>
            <button class="btn-text" onclick="showToast('Opening learning materials')">Open notes</button>
          </div>
        </article>

        <aside class="hero-side">
          <div>
            <div class="clock" id="clock">--:--</div>
            <div class="date-line" id="dateText">Loading date...</div>
          </div>

          <div class="side-note">
            <div>
              <strong>Today</strong>
              <p>Japanese speaking class · 4:00 PM · Online</p>
            </div>
            <button class="btn-ghost" onclick="openDrawer('Today session','Japanese speaking class with Haruka Tan at 4:00 PM. Mode: Online. Status: Confirmed.')">Open</button>
          </div>
        </aside>
      </div>
    </section>

    <section class="block">
      <div class="section-head">
        <div>
          <h2>Snapshot</h2>
          <p>Small numbers, less stress.</p>
        </div>
      </div>

      <div class="stats-scroll">
        <article class="stat-card">
          <span>Upcoming classes</span>
          <strong>3</strong>
          <small>Next one today</small>
        </article>
        <article class="stat-card yellow">
          <span>Pending payment</span>
          <strong>1</strong>
          <small>Waiting admin check</small>
        </article>
        <article class="stat-card green">
          <span>Completed lessons</span>
          <strong>12</strong>
          <small>This semester</small>
        </article>
        <article class="stat-card pink">
          <span>Favourite tutors</span>
          <strong>5</strong>
          <small>Ready to rebook</small>
        </article>
        <article class="stat-card purple">
          <span>Materials</span>
          <strong>18</strong>
          <small>Notes and links</small>
        </article>
      </div>
    </section>

    <section class="block three-grid" id="discover">
      <article class="course-card searchable">
        <img class="card-img" src="../assets/img/japanese.webp" alt="Japanese class">
        <h3>Japanese Beginner</h3>
        <p>Haruka Tan · Speaking practice and daily phrases.</p>
        <div class="card-meta">
          <strong>RM 45</strong>
          <button class="btn-primary" onclick="openDrawer('Japanese Beginner','Tutor: Haruka Tan. Available: Mon, Wed, Fri. You can connect this card to tutor_profile.php later.')">View</button>
        </div>
      </article>

      <article class="course-card searchable">
        <img class="card-img" src="../assets/img/english.webp" alt="English class">
        <h3>English Speaking</h3>
        <p>Daniel Lee · Presentation, conversation, confidence.</p>
        <div class="card-meta">
          <strong>RM 50</strong>
          <button class="btn-primary" onclick="openDrawer('English Speaking','Tutor: Daniel Lee. Good for speaking practice and presentation preparation.')">View</button>
        </div>
      </article>

      <article class="course-card searchable">
        <img class="card-img" src="../assets/img/mandarin.png" alt="Mandarin class">
        <h3>Mandarin Basics</h3>
        <p>Alicia Wong · Tone practice and beginner vocabulary.</p>
        <div class="card-meta">
          <strong>RM 48</strong>
          <button class="btn-primary" onclick="openDrawer('Mandarin Basics','Tutor: Alicia Wong. Beginner-friendly Mandarin class.')">View</button>
        </div>
      </article>
    </section>

    <section class="block two-col" id="bookings">
      <div class="panel">
        <div class="panel-top">
          <h3>My bookings</h3>
          <div class="chips">
            <button class="chip active" data-filter="all">All</button>
            <button class="chip" data-filter="confirmed">Confirmed</button>
            <button class="chip" data-filter="pending">Pending</button>
            <button class="chip" data-filter="completed">Completed</button>
          </div>
        </div>

        <div class="list" data-filter-list>
          <div class="list-item confirmed searchable">
            <img class="avatar" src="../assets/img/japanese.webp" alt="Japanese">
            <div class="item-main">
              <strong>Japanese Speaking</strong>
              <p>Haruka Tan · Today · 4:00 PM · Online</p>
            </div>
            <div class="item-actions">
              <span class="status done">Confirmed</span>
              <button class="round-btn" onclick="openDrawer('Japanese Speaking','Confirmed session with Haruka Tan. You can add reschedule, cancel, and payment logic in PHP later.')"><i class="bi bi-arrow-right"></i></button>
            </div>
          </div>

          <div class="list-item pending searchable">
            <img class="avatar" src="../assets/img/english.webp" alt="English">
            <div class="item-main">
              <strong>English Presentation</strong>
              <p>Daniel Lee · Waiting for tutor response</p>
            </div>
            <div class="item-actions">
              <span class="status pending">Pending</span>
              <button class="round-btn" onclick="openDrawer('English Presentation','This booking is waiting for tutor approval.')"><i class="bi bi-arrow-right"></i></button>
            </div>
          </div>

          <div class="list-item completed searchable">
            <img class="avatar" src="../assets/img/malay.jpg" alt="Malay">
            <div class="item-main">
              <strong>Malay Writing</strong>
              <p>Completed · Rate tutor available</p>
            </div>
            <div class="item-actions">
              <span class="status info">Rate</span>
              <button class="round-btn" onclick="openDrawer('Malay Writing','Session completed. Student can rate and review tutor here.')"><i class="bi bi-arrow-right"></i></button>
            </div>
          </div>
        </div>
      </div>

      <aside class="panel">
        <div class="panel-top">
          <h3>Today</h3>
          <button class="btn-text" onclick="showToast('Calendar opened')">Calendar</button>
        </div>

        <div class="timeline">
          <div class="timeline-item">
            <span class="timeline-dot"></span>
            <div>
              <time>10:00</time>
              <p>Review Japanese vocabulary notes</p>
            </div>
          </div>

          <div class="timeline-item">
            <span class="timeline-dot"></span>
            <div>
              <time>16:00</time>
              <p>Japanese speaking class</p>
            </div>
          </div>

          <div class="timeline-item">
            <span class="timeline-dot"></span>
            <div>
              <time>20:30</time>
              <p>Upload payment proof for English class</p>
            </div>
          </div>
        </div>
      </aside>
    </section>

    <section class="block lower-grid" id="materials">
      <div class="panel">
        <div class="panel-top">
          <h3>Learning materials</h3>
          <button class="btn-text" onclick="showToast('Materials page opened')">Open all</button>
        </div>

        <div class="list">
          <div class="list-item searchable">
            <img class="avatar" src="../assets/img/japanese.webp" alt="Material">
            <div class="item-main">
              <strong>Japanese particles cheat sheet</strong>
              <p>PDF · Uploaded by Haruka Tan</p>
            </div>
            <div class="item-actions">
              <span class="status done">New</span>
              <button class="round-btn" onclick="showToast('Downloaded demo file')"><i class="bi bi-download"></i></button>
            </div>
          </div>

          <div class="list-item searchable">
            <img class="avatar" src="../assets/img/english.webp" alt="Material">
            <div class="item-main">
              <strong>Presentation phrase list</strong>
              <p>Notes · English speaking class</p>
            </div>
            <div class="item-actions">
              <span class="status info">Notes</span>
              <button class="round-btn" onclick="showToast('Opened notes')"><i class="bi bi-eye"></i></button>
            </div>
          </div>

          <div class="list-item searchable">
            <img class="avatar" src="../assets/img/malay.jpg" alt="Material">
            <div class="item-main">
              <strong>Malay essay structure</strong>
              <p>PDF · Writing support</p>
            </div>
            <div class="item-actions">
              <span class="status purple">Saved</span>
              <button class="round-btn" onclick="showToast('Saved to materials')"><i class="bi bi-bookmark"></i></button>
            </div>
          </div>
        </div>
      </div>

      <div class="panel" id="progress">
        <div class="panel-top">
          <h3>Progress</h3>
          <button class="btn-text" onclick="showToast('Progress report opened')">Report</button>
        </div>

        <div class="progress-line">
          <div class="progress-item">
            <strong><span>Attendance</span><span>86%</span></strong>
            <div class="track"><div class="fill" style="width:86%;"></div></div>
          </div>
          <div class="progress-item">
            <strong><span>Japanese speaking</span><span>64%</span></strong>
            <div class="track"><div class="fill" style="width:64%;"></div></div>
          </div>
          <div class="progress-item">
            <strong><span>Homework submitted</span><span>72%</span></strong>
            <div class="track"><div class="fill" style="width:72%;"></div></div>
          </div>
          <div class="progress-item">
            <strong><span>Tutor feedback</span><span>Good</span></strong>
            <div class="track"><div class="fill" style="width:78%;"></div></div>
          </div>
        </div>
      </div>
    </section>

    <section class="block panel" id="favourites" style="margin-bottom:60px;">
      <div class="panel-top">
        <h3>Favourite tutors</h3>
        <button class="btn-ghost" onclick="showToast('Compare tutors opened')">Compare</button>
      </div>

      <div class="list">
        <div class="list-item searchable">
          <img class="avatar" src="../assets/img/tutor.jpg" alt="Tutor">
          <div class="item-main">
            <strong>Daniel Lee</strong>
            <p>English · RM 50 · Available tomorrow</p>
          </div>
          <div class="item-actions">
            <span class="status done">Saved</span>
            <button class="round-btn" onclick="showToast('Tutor added to comparison')"><i class="bi bi-star-fill"></i></button>
          </div>
        </div>

        <div class="list-item searchable">
          <img class="avatar" src="../assets/img/jb.jpg" alt="Tutor">
          <div class="item-main">
            <strong>Alicia Wong</strong>
            <p>Mandarin · RM 48 · Weekend slots</p>
          </div>
          <div class="item-actions">
            <span class="status done">Saved</span>
            <button class="round-btn" onclick="showToast('Tutor added to comparison')"><i class="bi bi-star-fill"></i></button>
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
    <small>Details</small>
    <p id="drawerText"></p>

    <div style="margin-top:20px;">
        <a href="logout.php"
        style="display:block; text-align:center; background:#ff4d4d; color:white; padding:12px; border-radius:999px; font-weight:700; text-decoration:none;">
        Logout
        </a>
    </div>
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
