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
$profilePic = !empty($user['profile_pic'])
    ? '../uploads/profiles/' . $user['profile_pic']
    : $assetBase . '/profile-student.png';

// FIRST: Check if learning_materials table exists and has data
$tableCheck = $conn->query("SHOW TABLES LIKE 'learning_materials'");
$hasMaterialsTable = ($tableCheck && $tableCheck->num_rows > 0);

// Get ALL learning materials from database (regardless of bookings for testing)
$allMaterials = [];
if ($hasMaterialsTable) {
    $result = $conn->query("
    SELECT 
        lm.*,
        u.fullname AS tutor_name,
        b.language,
        b.booking_date,
        b.booking_time,
        m.meet_link AS meeting_link
    FROM learning_materials lm
    LEFT JOIN users u ON lm.tutor_id = u.id
    LEFT JOIN bookings b ON lm.booking_id = b.id
    LEFT JOIN meetings m ON lm.booking_id = m.booking_id
    ORDER BY lm.uploaded_at DESC
");
    if ($result && $result->num_rows > 0) {
       while ($row = $result->fetch_assoc()) {
    $lang = $row['language'] ?? 'General';

    if (!isset($allMaterials[$lang])) {
        $allMaterials[$lang] = [];
    }

    $allMaterials[$lang][] = $row;
}
    }
}

// Get confirmed bookings (for active classrooms only)
$stmt = $conn->prepare("
    SELECT DISTINCT 
        b.id as booking_id,
        b.language,
        b.booking_date,
        b.booking_time,
        b.status,
        b.learning_mode,
        u.fullname as tutor_name,
        u.id as tutor_id
    FROM bookings b
    JOIN users u ON b.tutor_id = u.id
    WHERE b.student_id = ? 
        AND b.status IN ('confirmed', 'completed')
    ORDER BY b.booking_date DESC
");
$stmt->bind_param("i", $userID);
$stmt->execute();
$myBookings = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get unique languages from bookings (for filtering materials)
$bookedLanguages = [];
foreach ($myBookings as $booking) {
    if (!in_array($booking['language'], $bookedLanguages)) {
        $bookedLanguages[] = $booking['language'];
    }
}

// DECISION: Show materials based on bookings OR show all materials for testing
$showAllMaterialsForTesting = true; // SET TO false AFTER TESTING

if ($showAllMaterialsForTesting) {
    // Show all materials regardless of bookings
    $languages = array_keys($allMaterials);
    $materialsByLanguage = $allMaterials;
} else {
    // Only show materials for languages the student has booked
    $languages = $bookedLanguages;
    $materialsByLanguage = [];
    foreach ($bookedLanguages as $lang) {
        $materialsByLanguage[$lang] = $allMaterials[$lang] ?? [];
    }
}

// Get active classrooms (confirmed upcoming bookings)
$activeClassrooms = [];
foreach ($myBookings as $booking) {
    if ($booking['status'] === 'confirmed') {
        $activeClassrooms[] = [
            'booking_id' => $booking['booking_id'],
            'language' => $booking['language'],
            'tutor_name' => $booking['tutor_name'],
            'booking_date' => $booking['booking_date'],
            'booking_time' => $booking['booking_time']
        ];
    }
}

$firstName = explode(' ', trim($displayName))[0];

// Helper function for escaping
function e($value) {
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Learning Materials - Kyoshi Student</title>
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
            
            grid-template-columns: 190px 1fr;
            gap:16px;
            align-items:center;
        }
        .brand{display:flex; align-items:center; gap:10px; min-width:0}
        .brand img{width:44px; height:44px; object-fit:contain; border-radius:14px}
        .brand strong{display:block; font-size:18px; line-height:1.05}
        .brand span{display:block; margin-top:3px; font-size:11px; color:var(--muted); white-space:nowrap}

        .nav-links{
            display:flex; align-items:center; justify-content:center; gap:6px;
            border-radius:999px; padding:7px;
            overflow:auto; scrollbar-width:none;
            box-shadow:inset 0 1px 0 rgba(255,255,255,.70);
            justify-self: center;
        }
        .nav-links::-webkit-scrollbar{display:none}
        .nav-links a{flex:0 0 auto; padding:9px 12px; border-radius:999px; font-size:13px; font-weight:900; color:#6D4964; white-space:nowrap; transition:.18s ease}
        .nav-links a.active,.nav-links a:hover{background:linear-gradient(135deg, var(--hot-pink), var(--pink)); color:#fff; box-shadow:0 8px 18px rgba(231,90,155,.28)}

        .nav-actions {
    display: flex;
    justify-content: flex-end;
    align-items: center;
    gap: 12px;
    margin-left: auto;
}
        .search{position:relative; flex:1 1 auto; min-width:0}
        .search i{position:absolute; left:14px; top:50%; transform:translateY(-50%); color:#91899F;font-size:14px;}
        .search input{width:100%; border:1px solid rgba(46,42,59,.10); background:rgba(255,255,255,.88); outline:none; border-radius:999px; padding:12px 14px 12px 38px; box-shadow:var(--shadow-soft)}
        .icon-btn,.profile{border:1px solid rgba(46,42,59,.08); background:rgba(255,255,255,.88); box-shadow:var(--shadow-soft); cursor:pointer}
        .icon-btn{width:36px; height:36px; border-radius:16px; color:#7A4A68; position:relative; flex:0 0 auto}
        .dot{position:absolute; top:10px; right:10px; width:8px; height:8px; border-radius:50%; background:#E17C91}
        .profile{display:flex; align-items:center; gap:9px; border-radius:999px; padding:6px 12px 6px 6px; font-weight:900; color:#7A3D65; flex:0 0 auto; max-width:150px}
        .profile img{width:34px; height:34px; object-fit:cover; border-radius:50%}
        .profile span{max-width:86px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap}

        .glass{background:var(--paper); border:1px solid rgba(255,255,255,.55); box-shadow:var(--shadow)}
        
        .section{margin-top:20px}
        .section-head{display:flex; justify-content:space-between; align-items:end; gap:18px; margin-bottom:15px}
        .section-head h2,.panel-top h3{margin:0; letter-spacing:-.5px}
        .section-head h2{font-size:24px}
        .section-head p,.panel-top p{margin:6px 0 0; color:var(--muted)}
        
        .panel{padding:24px}
        .panel-top{display:flex; justify-content:space-between; align-items:center; gap:16px; margin-bottom:16px}
        .panel-top h3{font-size:22px}

        /* Materials Styles */
        .language-tabs {
            display: flex;
            gap: 12px;
            margin-bottom: 30px;
            border-bottom: 2px solid rgba(231,90,155,.2);
            padding-bottom: 15px;
            overflow-x: auto;
        }

        .lang-tab {
            padding: 12px 28px;
            background: transparent;
            border: none;
            border-radius: 40px;
            font-size: 16px;
            font-weight: 700;
            color: var(--muted);
            cursor: pointer;
            transition: all 0.2s;
            white-space: nowrap;
        }

        .lang-tab.active {
            background: linear-gradient(135deg, var(--hot-pink), var(--pink));
            color: white;
            box-shadow: 0 4px 12px rgba(231,90,155,.3);
        }

        .materials-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 24px;
        }

        .material-card {
            background: rgba(255,255,255,.95);
            border-radius: 24px;
            padding: 20px;
            transition: all 0.3s ease;
            border: 1px solid rgba(231,90,155,.1);
        }

        .material-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow);
            border-color: rgba(231,90,155,.3);
        }

        .material-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 15px;
        }

        .material-icon {
            width: 55px;
            height: 55px;
            background: rgba(231,90,155,.1);
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .material-icon i {
            font-size: 30px;
            color: var(--hot-pink);
        }

        .material-info {
            flex: 1;
        }

        .material-info h4 {
            font-size: 18px;
            margin-bottom: 5px;
        }

        .material-type {
            font-size: 11px;
            color: var(--muted);
            text-transform: uppercase;
            font-weight: 700;
        }

        .material-description {
            color: var(--muted);
            font-size: 13px;
            line-height: 1.5;
            margin-bottom: 15px;
        }

        .material-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            font-size: 12px;
            color: var(--muted);
        }

        .material-actions {
            display: flex;
            gap: 10px;
        }

        .btn-download, .btn-preview {
            flex: 1;
            padding: 10px;
            border-radius: 12px;
            font-weight: 700;
            font-size: 13px;
            cursor: pointer;
            transition: all 0.2s;
            text-align: center;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }

        .btn-download {
            background: linear-gradient(135deg, var(--hot-pink), var(--pink));
            color: white;
            border: none;
        }

        .btn-preview {
            background: white;
            color: var(--hot-pink);
            border: 1px solid var(--hot-pink);
        }

        .btn-download:hover, .btn-preview:hover {
            transform: translateY(-2px);
        }

        .empty-state {
            text-align: center;
            padding: 60px;
            background: rgba(255,241,246,.8);
            border-radius: 30px;
        }

        .empty-state i {
            font-size: 64px;
            color: var(--muted);
            margin-bottom: 20px;
        }

        .empty-state h3 {
            margin-bottom: 10px;
        }

        .empty-state p {
            color: var(--muted);
            margin-bottom: 20px;
        }

        .active-classrooms {
            background: linear-gradient(135deg, rgba(167,123,232,.15), rgba(231,90,155,.08));
            border-radius: var(--radius-xl);
            padding: 25px;
            margin-bottom: 30px;
            border: 1px solid rgba(167,123,232,.2);
        }

        .active-classrooms h3 {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 20px;
        }

        .classrooms-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
        }

        .classroom-card {
            background: white;
            border-radius: 20px;
            padding: 20px;
            display: flex;
            align-items: center;
            gap: 15px;
            transition: transform 0.2s;
        }

        .classroom-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 25px rgba(0,0,0,.1);
        }

        .classroom-icon {
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, var(--purple), var(--hot-pink));
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .classroom-icon i {
            font-size: 28px;
            color: white;
        }

        .classroom-info {
            flex: 1;
        }

        .classroom-info h4 {
            margin-bottom: 5px;
        }

        .classroom-info p {
            font-size: 12px;
            color: var(--muted);
        }

        .enter-btn {
            background: linear-gradient(135deg, var(--purple), var(--purple-dark));
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 30px;
            cursor: pointer;
            font-weight: 700;
            transition: transform 0.2s;
        }

        .enter-btn:hover {
            transform: scale(1.05);
        }

        .toast {
            position: fixed;
            bottom: 30px;
            left: 50%;
            transform: translateX(-50%);
            background: var(--ink);
            color: white;
            padding: 12px 24px;
            border-radius: 40px;
            font-weight: 700;
            z-index: 1000;
            opacity: 0;
            transition: opacity 0.3s;
            pointer-events: none;
        }

        .toast.show {
            opacity: 1;
        }

        @media (max-width:1280px){
            .nav{grid-template-columns:170px minmax(0,1fr) 320px}
        }
        @media (max-width:980px){
            .nav{grid-template-columns:1fr auto; min-height:auto; padding:10px 0}
            .nav-links{grid-column:1 / -1; grid-row:2; width:100%; justify-content:flex-start}
            .search{display:none}
        }
        @media (max-width:760px){
            .container{width:min(100% - 22px, 100%)}
            .profile span,.brand span{display:none}
            .materials-grid{grid-template-columns:1fr}
            .classrooms-grid{grid-template-columns:1fr}
        }
        .btn-download {
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.profile-dropdown{
    display:none;
    position:absolute;
    top:calc(100% + 10px);
    right:0;
    background:white;
    border-radius:16px;
    box-shadow:0 18px 45px rgba(201,79,134,.2);
    border:1px solid rgba(242,138,178,.2);
    min-width:180px;
    overflow:hidden;
    z-index:100;
}

.profile-dropdown a{
    display:flex;
    align-items:center;
    gap:10px;
    padding:14px 16px;
    font-size:14px;
    font-weight:700;
    color:#342635;
    text-decoration:none;
    transition:.15s ease;
}

.profile-dropdown a:hover{
    background:#FFF1F6;
}

.profile-dropdown i{
    color:#E75A9B;
}

.profile-dropdown hr{
    margin:4px 0;
    border-color:rgba(242,138,178,.2);
}

.profile-dropdown .logout{
    color:#dc2626;
}
.btn-download i {
    font-size: 13px;
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

    <main class="container">
        <div class="section">
            <div class="panel glass">
                <div class="panel-top">
                    <div>
                        <h3><i class="bi bi-journal-bookmark-fill"></i> My Learning Materials</h3>
                        <p>Access all your learning resources from confirmed bookings.</p>
                    </div>
                </div>

                <?php if (!empty($activeClassrooms)): ?>
                <div class="active-classrooms">
                    <h3><i class="bi bi-camera-reels-fill"></i> Live Classrooms</h3>
                    <div class="classrooms-grid">
                        <?php foreach ($activeClassrooms as $classroom): ?>
                        <div class="classroom-card">
                            <div class="classroom-icon">
                                <i class="bi bi-google"></i>
                            </div>
                            <div class="classroom-info">
                                <h4><?= e($classroom['language']) ?> Session</h4>
                                <p>with <?= e($classroom['tutor_name']) ?></p>
                                <p><?= date('d M Y, h:i A', strtotime($classroom['booking_date'] . ' ' . $classroom['booking_time'])) ?></p>
                            </div>
                            <button class="enter-btn" onclick="startGoogleMeet('<?= e($classroom['language']) ?>')">
                                <i class="bi bi-google"></i> Start Meet
                            </button>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (empty($allMaterials)): ?>
                <div class="empty-state">
                    <i class="bi bi-folder2-open"></i>
                    <h3>No Materials Available Yet</h3>
                    <p>
                        Your learning materials will appear here once your tutors upload them.
                        <br><br>
                        Keep attending your sessions to unlock more resources.
                    </p>
                                        <a href="find_language.php" class="btn-download" style="display: inline-block; padding: 12px 24px; width: auto;">
                        <i class="bi bi-search"></i> Book a Session
                    </a>
                </div>
                <?php else: ?>

                <div class="language-tabs" id="languageTabs">
                    <?php foreach ($languages as $index => $lang): ?>
                    <button class="lang-tab <?= $index === 0 ? 'active' : '' ?>" onclick="filterByLanguage('<?= e($lang) ?>', this)">
                        <?= e($lang) ?> (<?= count($materialsByLanguage[$lang]) ?>)
                    </button>
                    <?php endforeach; ?>
                </div>

                <div id="materialsContainer">
                    <!-- Materials will be loaded here dynamically -->
                </div>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <div id="toast" class="toast"></div>

    <script>
        // Materials data from PHP
        const materialsData = <?= json_encode($materialsByLanguage) ?>;
        const MATERIAL_BASE = '/Kyoshi/uploads/materials/';
        
        function showToast(message) {
            const toast = document.getElementById('toast');
            toast.textContent = message;
            toast.classList.add('show');
            setTimeout(() => {
                toast.classList.remove('show');
            }, 3000);
        }

        function startGoogleMeet(language) {
            window.open('https://meet.google.com/new', '_blank');
            showToast('Opening Google Meet for ' + language + ' class!');
        }

        function filterByLanguage(language, element) {
            document.querySelectorAll('.lang-tab').forEach(tab => {
                tab.classList.remove('active');
            });
            element.classList.add('active');
            displayMaterials(language);
        }

        function displayMaterials(language) {
            const container = document.getElementById('materialsContainer');
            const materials = materialsData[language] || [];
            
            if (materials.length === 0) {
                container.innerHTML = `
                    <div class="empty-state">
                        <i class="bi bi-file-earmark-text"></i>
                        <h3>No Materials Available</h3>
                        <p>Materials for ${language} will be added soon.</p>
                    </div>
                `;
                return;
            }
            
            container.innerHTML = `
                <div class="materials-grid">
                    ${materials.map(material => `
                        <div class="material-card">
                            <div class="material-header">
                                <div class="material-icon">
                                    <i class="${getMaterialIcon(material.file_type)}"></i>
                                </div>
                                <div class="material-info">
                                    <h4>${escapeHtml(material.title)}</h4>
                                    <span class="material-type">${material.file_type.toUpperCase()}</span>
                                </div>
                            </div>
                            <div class="material-description">
                                ${escapeHtml(material.description || 'No description available')}
                            </div>
                            <div class="material-meta">
    <span>
        <i class="bi bi-person"></i> Tutor: ${escapeHtml(material.tutor_name || 'Unknown')}
    </span>
</div>

<div class="material-meta">
    <span>
        <i class="bi bi-book"></i> Class: ${escapeHtml(material.language || 'N/A')}
    </span>
</div>

${material.meeting_link ? `
<div class="material-meta">
    <span>
        <i class="bi bi-camera-video"></i> 
        <a href="${material.meeting_link}" target="_blank">Join Meeting</a>
    </span>
</div>
` : ''}
                            <div class="material-actions">
                                <button class="btn-download" onclick="downloadMaterial('${material.file_path}', ${material.id})">
                                    <i class="bi bi-download"></i> Download
                                </button>
                                <button class="btn-preview" onclick="previewMaterial('${material.file_path}')">
                                    <i class="bi bi-eye"></i> Preview
                                </button>
                            </div>
                        </div>
                    `).join('')}
                </div>
            `;
        }

        function getMaterialIcon(fileType) {
            const icons = {
                'pdf': 'bi bi-file-pdf-fill',
                'video': 'bi bi-camera-reels-fill',
                'audio': 'bi bi-headphones',
                'document': 'bi bi-file-text-fill',
                'presentation': 'bi bi-easel-fill'
            };
            return icons[fileType] || 'bi bi-file-earmark';
        }

        function formatDate(dateString) {
            const date = new Date(dateString);
            return date.toLocaleDateString('en-MY', { day: 'numeric', month: 'short', year: 'numeric' });
        }

        function downloadMaterial(filePath, materialId) {
        const link = document.createElement('a');
        link.href = MATERIAL_BASE + filePath;
        link.download = filePath;

        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);

        showToast('Download started!');
    }

        function previewMaterial(filePath) {
            window.open(MATERIAL_BASE + filePath, '_blank');
            showToast('Opening...');
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        function toggleDropdown() {
    const dropdown = document.getElementById("profileDropdown");
    dropdown.style.display = dropdown.style.display === "block" ? "none" : "block";
}

// close when clicking outside
document.addEventListener("click", function(event) {
    const btn = document.getElementById("profileBtn");
    const dropdown = document.getElementById("profileDropdown");

    if (!btn.contains(event.target) && !dropdown.contains(event.target)) {
        dropdown.style.display = "none";
    }
});

       <?php if (!empty($languages)): ?>
displayMaterials('<?= addslashes($languages[0]) ?>');
<?php endif; ?>
    </script>
</body>
</html>