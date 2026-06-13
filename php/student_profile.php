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
$successMsg = '';
$errorMsg = '';

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
$cacheBuster = time(); // Add cache-busting parameter
if (!empty($user['profile_pic']) && file_exists('../uploads/profiles/' . $user['profile_pic'])) {
    $profilePic = '../uploads/profiles/' . $user['profile_pic'] . '?v=' . $cacheBuster;
} else {
    $profilePic = $assetBase . '/profile.png';
}
// Get preferred languages
$preferredLanguages = [];
$result = $conn->query("SELECT language FROM student_preferences WHERE user_id = $userID");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $preferredLanguages[] = $row['language'];
    }
}

// Get proficiency levels for each language
$languageProficiency = [];
$stmt = $conn->prepare("SELECT language, proficiency_level FROM student_language_proficiency WHERE student_id = ?");
$stmt->bind_param("i", $userID);
$stmt->execute();
$profResult = $stmt->get_result();
while ($row = $profResult->fetch_assoc()) {
    $languageProficiency[$row['language']] = $row['proficiency_level'];
}

// Get learning modes
$preferredModes = [];
$result = $conn->query("SELECT mode FROM student_learning_modes WHERE user_id = $userID");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $preferredModes[] = $row['mode'];
    }
}

// Get bookings for learning progress
$bookings = [];
$stmt = $conn->prepare("
    SELECT b.id, b.language, b.booking_date, b.status,
           u.fullname AS tutor_name
    FROM bookings b JOIN users u ON b.tutor_id = u.id
    WHERE b.student_id = ? ORDER BY b.booking_date DESC
");
$stmt->bind_param("i", $userID);
$stmt->execute();
$bRes = $stmt->get_result();
while ($row = $bRes->fetch_assoc()) {
    $bookings[] = $row;
}

$completedCount = count(array_filter($bookings, fn($b) => $b['status'] === 'completed'));
$totalSessions  = count($bookings);

// Handle POST request
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_pic_only') {
        header('Content-Type: application/json');
        if (!empty($_FILES['profile_pic']['name'])) {
            $ext = strtolower(pathinfo($_FILES['profile_pic']['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg','jpeg','png','webp','gif'];
            if (in_array($ext, $allowed)) {
                $upload_dir = '../uploads/profiles/';
                if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
                $filename = 'student_' . $userID . '_' . time() . '.' . $ext;
                if (move_uploaded_file($_FILES['profile_pic']['tmp_name'], $upload_dir . $filename)) {
                    $stmt = $conn->prepare("UPDATE users SET profile_pic=? WHERE id=?");
                    $stmt->bind_param("si", $filename, $userID);
                    $stmt->execute();
                    echo json_encode(['success' => true, 'filename' => $filename]);
                    exit();
                }
            }
        }
        echo json_encode(['success' => false, 'error' => 'Upload failed']);
        exit();
    }

if ($action === 'update_profile') {
    // Handle profile pic upload FIRST (before checking for changes)
    $fullname = trim($_POST['fullname'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $phone    = trim($_POST['phone'] ?? '');
    $newPic   = $user['profile_pic'];
    $hasChanges = false;
    
    // Check photo upload FIRST
    if (!empty($_FILES['profile_pic']['name'])) {
        $upload_dir = '../uploads/profiles/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
        $ext = strtolower(pathinfo($_FILES['profile_pic']['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg','jpeg','png','webp','gif'];
        if (in_array($ext, $allowed)) {
            $filename = 'student_' . $userID . '_' . time() . '.' . $ext;
            if (move_uploaded_file($_FILES['profile_pic']['tmp_name'], $upload_dir . $filename)) {
                $newPic = $filename;
                $hasChanges = true;
            }
        } else {
            $errorMsg = 'Invalid file type. Please use JPG, PNG, WebP, or GIF.';
        }
    }
    
    // Then check if personal info changed
    if ($fullname !== $user['fullname']) $hasChanges = true;
    if ($email !== $user['email']) $hasChanges = true;
    if ($phone !== ($user['phone'] ?? '')) $hasChanges = true;
    
    // Check if languages changed
    $newLanguages = $_POST['languages'] ?? [];
    $oldLanguages = $preferredLanguages;
    if (array_diff($newLanguages, $oldLanguages) || array_diff($oldLanguages, $newLanguages)) {
        $hasChanges = true;
    }
    
    // Check if modes changed
    $newModes = $_POST['modes'] ?? [];
    $oldModes = $preferredModes;
    if (array_diff($newModes, $oldModes) || array_diff($oldModes, $newModes)) {
        $hasChanges = true;
    }
    
    // Check if proficiency levels changed - FIXED VERSION
    $newLevels = [];
    if (isset($_POST['language_levels'])) {
        foreach ($_POST['language_levels'] as $jsonData) {
            $data = json_decode($jsonData, true);
            if ($data && isset($data['language']) && isset($data['level'])) {
                $newLevels[$data['language']] = $data['level'];
            }
        }
    }
    
    // Compare proficiency levels properly
    // Check if any language's proficiency level changed
    foreach ($newLanguages as $lang) {
        $oldLevel = $languageProficiency[$lang] ?? 'beginner';
        $newLevel = $newLevels[$lang] ?? 'beginner';
        if ($oldLevel !== $newLevel) {
            $hasChanges = true;
            break;
        }
    }
    
    // Also check if any languages were removed (proficiency data would be deleted)
    foreach ($oldLanguages as $lang) {
        if (!in_array($lang, $newLanguages)) {
            $hasChanges = true;
            break;
        }
    }
    
    if (!$hasChanges && empty($errorMsg)) {
        $errorMsg = 'No changes were made to your profile.';
    } elseif (empty($errorMsg)) {
        // Update user table
        $stmt = $conn->prepare("UPDATE users SET fullname=?, email=?, phone=?, profile_pic=? WHERE id=?");
        $stmt->bind_param("ssssi", $fullname, $email, $phone, $newPic, $userID);
        $stmt->execute();
        
        // Delete old preferences
        $conn->query("DELETE FROM student_preferences WHERE user_id = $userID");
        $conn->query("DELETE FROM student_learning_modes WHERE user_id = $userID");
        
        // Insert new languages
        $langs = $_POST['languages'] ?? [];
        foreach ($langs as $lang) {
            $s = $conn->prepare("INSERT INTO student_preferences (user_id, language) VALUES (?, ?)");
            $s->bind_param("is", $userID, $lang);
            $s->execute();
        }
        
        // Insert new modes
        $modes = $_POST['modes'] ?? [];
        foreach ($modes as $mode) {
            $s = $conn->prepare("INSERT INTO student_learning_modes (user_id, mode) VALUES (?, ?)");
            $s->bind_param("is", $userID, $mode);
            $s->execute();
        }
        
        // Insert proficiency levels - FIXED: Save for ALL selected languages
        $conn->query("DELETE FROM student_language_proficiency WHERE student_id = $userID");
        $profStmt = $conn->prepare("INSERT INTO student_language_proficiency (student_id, language, proficiency_level) VALUES (?, ?, ?)");
        
        // Save proficiency for ALL selected languages (default to 'beginner' if not explicitly set)
        foreach ($langs as $lang) {
            $level = $newLevels[$lang] ?? 'beginner';
            $profStmt->bind_param("iss", $userID, $lang, $level);
            $profStmt->execute();
        }
        $profStmt->close();
        
        header("Location: student_profile.php?success=1&t=" . time());
        exit();
    }
}


    if ($action === 'change_password') {
    $current = $_POST['current_password'] ?? '';
    $new     = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if (!password_verify($current, $user['password'])) {
        $errorMsg = 'Current password is incorrect.';
    } elseif ($new === $current) {
        $errorMsg = 'New password must be different from your current password.';
    } elseif (strlen($new) < 8) {
        $errorMsg = 'New password must be at least 8 characters.';
    } elseif (!preg_match('/[0-9]/', $new)) {
        $errorMsg = 'New password must contain at least one number.';
    } elseif (!preg_match('/[A-Z]/', $new)) {
        $errorMsg = 'New password must contain at least one uppercase letter.';
    } elseif (!preg_match('/[!@#$%^&*]/', $new)) {
        $errorMsg = 'New password must contain at least one special character (!@#$%^&*).';
    } elseif ($new !== $confirm) {
        $errorMsg = 'Passwords do not match.';
    } else {
        // ... rest of code
            $hashed = password_hash($new, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE users SET password=? WHERE id=?");
            $stmt->bind_param("si", $hashed, $userID);
            $stmt->execute();
           require_once 'insert_notification.php';
            insertNotification(
                $conn, 
                $userID, 
                'Password Changed', 
                'Your password was successfully changed. If you did not make this change, please contact support immediately.',
                'security',
                'forgot_password.php'
            );
            
            // ── Send confirmation email (not reset link!) ──
            require_once 'send_mail.php';
            sendPasswordChangedEmail($user['email'], $user['fullname']);
            
            header("Location: student_profile.php?success=pw");
            exit();
        }
    }
}

if (isset($_GET['success'])) {
    if ($_GET['success'] == 1) {
        $successMsg = 'Profile updated successfully!';
        $stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->bind_param("i", $userID);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $displayName = $user['fullname'];
        $profilePic  = !empty($user['profile_pic']) ? '../uploads/profiles/'.$user['profile_pic'] . '?v=' . time() : $assetBase.'/profile-student.png';
        
        $preferredLanguages = [];
        $result = $conn->query("SELECT language FROM student_preferences WHERE user_id = $userID");
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $preferredLanguages[] = $row['language'];
            }
        }
        
        $preferredModes = [];
        $result = $conn->query("SELECT mode FROM student_learning_modes WHERE user_id = $userID");
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $preferredModes[] = $row['mode'];
            }
        }
    } elseif ($_GET['success'] == 'pw') {
        $successMsg = 'Password updated! A confirmation email has been sent to ' . e($user['email']) . '.';
    }
}

function e($v) { 
    return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); 
}

$allLanguages = ['Japanese', 'English', 'Mandarin', 'Korean', 'Malay'];
$allModes = ['online', 'face_to_face'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
<meta http-equiv="Pragma" content="no-cache">
<meta http-equiv="Expires" content="0">
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>My Profile · Kyoshi</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
  <link rel="stylesheet" href="../css/style.css">
  <style>
    :root{
      --cream:#FFF1F6; --paper:rgba(255,255,255,.88); --ink:#342635; --muted:#7B6178;
      --pink:#F28AB2; --pink-dark:#C94F86; --hot-pink:#E75A9B; --purple:#A77BE8;
      --lavender:#EAD7FF; --peach:#FFD0DD; --mint:#DDF4E3; --sky:#D8ECFF;
      --shadow:0 18px 45px rgba(201,79,134,.16); --shadow-soft:0 10px 26px rgba(201,79,134,.10);
      --radius-xl:32px; --radius-lg:24px; --radius-md:18px;
    }
    *{box-sizing:border-box} html{scroll-behavior:smooth}
    body{margin:0;min-height:100vh;font-family:"Segoe UI",Arial,sans-serif;color:var(--ink);
      background:linear-gradient(120deg,rgba(255,241,246,.74),rgba(255,203,220,.30)),
        url("<?= e($assetBase) ?>/background3.jpg") center/cover fixed no-repeat;}
    a{text-decoration:none;color:inherit} button,input,textarea,select{font-family:inherit}
    .container{width:min(1440px,calc(100% - 40px));margin:0 auto}

    .topbar{position:sticky;top:0;z-index:50;background:#FFF1F6;backdrop-filter:blur(20px);border-bottom:1px solid rgba(231,90,155,.18);box-shadow:0 10px 30px rgba(201,79,134,.10);}
    .nav{min-height:78px;display:grid;grid-template-columns:190px minmax(0,1fr) 360px;gap:16px;align-items:center;}
    .brand{display:flex;align-items:center;gap:10px;min-width:0}
    .brand img{width:44px;height:44px;object-fit:contain;border-radius:14px}
    .brand strong{display:block;font-size:18px;line-height:1.05}
    .brand span{display:block;margin-top:3px;font-size:11px;color:var(--muted);white-space:nowrap}
    .nav-links{display:flex;align-items:center;justify-content:center;gap:6px;border:1px solid rgba(242,138,178,.18);border-radius:999px;padding:7px;overflow:auto;scrollbar-width:none;box-shadow:inset 0 1px 0 rgba(255,255,255,.70);}
    .nav-links::-webkit-scrollbar{display:none}
    .nav-links a{flex:0 0 auto;padding:9px 12px;border-radius:999px;font-size:13px;font-weight:900;color:#6D4964;white-space:nowrap;transition:.18s ease}
    .nav-links a.active,.nav-links a:hover{background:linear-gradient(135deg,var(--hot-pink),var(--pink));color:#fff;box-shadow:0 8px 18px rgba(231,90,155,.28)}
    .nav-actions{display:flex;align-items:center;justify-content:flex-end;gap:10px;min-width:0}
    .profile-nav{display:flex;align-items:center;gap:9px;border-radius:999px;padding:6px 12px 6px 6px;font-weight:900;color:#7A3D65;border:1px solid rgba(46,42,59,.08);background:rgba(255,255,255,.88);cursor:pointer;}
    .profile-nav img{width:34px;height:34px;object-fit:cover;border-radius:50%}
    .profile-nav span{max-width:86px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}

    .page-wrap{padding:28px 0 48px}
    .breadcrumb{display:flex;align-items:center;gap:8px;font-size:13px;color:var(--muted);margin-bottom:20px}
    .breadcrumb a{color:var(--pink-dark);font-weight:700}
    .tabs{display:flex;gap:6px;background:rgba(255,255,255,.58);border:1px solid rgba(242,138,178,.18);border-radius:999px;padding:7px;width:max-content;margin-bottom:24px;box-shadow:inset 0 1px 0 rgba(255,255,255,.70);}
    .tab{padding:10px 18px;border-radius:999px;font-size:13px;font-weight:900;color:#6D4964;cursor:pointer;border:none;background:transparent;transition:.18s ease}
    .tab.active,.tab:hover{background:linear-gradient(135deg,var(--hot-pink),var(--pink));color:#fff;box-shadow:0 8px 18px rgba(231,90,155,.28)}

    /* Left Sidebar - Normal (No Glass) */
    .profile-layout{
        display:grid;
        grid-template-columns:320px 1fr;
        gap:24px;
        align-items:start;
    }
    .profile-sidebar{
        background:white;
        border-radius:var(--radius-xl);
        padding:28px;
        text-align:center;
        position:relative;
        box-shadow:0 2px 8px rgba(0,0,0,.04);
        border:1px solid rgba(46,42,59,.08);
    }
    
    /* Right Side - Glass Effect */
    .glass-card{
        background:var(--paper);
        border:1px solid rgba(255,255,255,.55);
        box-shadow:var(--shadow);
        border-radius:var(--radius-xl);
        overflow:hidden;
    }
    .form-panel{
        padding:28px 32px;
        margin-bottom:0;
        background:transparent;
    }
    .form-panel h3{margin:0 0 6px;font-size:22px;letter-spacing:-.5px}
    .form-panel .sub{color:var(--muted);font-size:14px;margin:0 0 24px}
    .form-grid{display:grid;grid-template-columns:1fr 1fr;gap:18px}
    .form-group{display:flex;flex-direction:column;gap:6px}
    .form-group label{font-size:13px;font-weight:900;color:#6D4964}
    .form-group input,.form-group textarea,.form-group select{border:1px solid rgba(46,42,59,.12);border-radius:14px;padding:12px 14px;font-size:14px;outline:none;color:var(--ink);background:rgba(255,255,255,.88);transition:.15s ease}
    .form-group input:focus,.form-group textarea:focus{border-color:var(--hot-pink);box-shadow:0 0 0 3px rgba(231,90,155,.12)}
    .section-divider{height:1px;background:rgba(46,42,59,.08);margin:24px 0}
    .section-label{font-size:14px;font-weight:900;color:#6D4964;margin:0 0 12px}
    .chip-group{display:flex;flex-wrap:wrap;gap:10px}
    .pref-chip{border:1px solid rgba(46,42,59,.10);background:rgba(255,255,255,.82);color:#7A5570;padding:10px 16px;border-radius:999px;font-size:13px;font-weight:900;cursor:pointer;transition:.18s ease}
    .pref-chip.active{
        background:linear-gradient(135deg,var(--hot-pink),var(--pink)) !important;
        color:#fff !important;
        border-color:var(--pink) !important;
        box-shadow:0 8px 18px rgba(231,90,155,.22);
    }
    
    .form-panel.disabled{
        opacity:0.75;
    }
    .form-panel.disabled input,
    .form-panel.disabled textarea,
    .form-panel.disabled button.pref-chip,
    .form-panel.disabled label.avatar-edit{
        pointer-events:none;
    }
    .form-panel.disabled input,
    .form-panel.disabled textarea{
        background:#f8f8f8;
        color:#888;
    }
    .form-panel.disabled .pref-chip{
        pointer-events:none;
        opacity:0.7;
        cursor:default;
    }
    
    .edit-actions{
        display:none;
        margin-top:20px;
        padding-top:20px;
        border-top:1px solid rgba(231,90,155,.12);
        justify-content:center;
        gap:20px;
    }
    .edit-actions .btn-primary,
    .edit-actions .btn-outline{
        width:180px;
        padding:13px 20px;
    }
    
    .avatar-wrap{position:relative;width:120px;height:120px;margin:0 auto 18px}
    .avatar-wrap img{width:120px;height:120px;border-radius:50%;object-fit:cover;border:4px solid white;box-shadow:0 12px 28px rgba(201,79,134,.2)}
    .avatar-edit{position:absolute;bottom:4px;right:4px;width:34px;height:34px;border-radius:50%;background:linear-gradient(135deg,var(--hot-pink),var(--pink));border:2px solid white;color:#fff;cursor:pointer;display:grid;place-items:center;font-size:14px;box-shadow:0 4px 12px rgba(231,90,155,.3)}
    .sidebar-name{font-size:22px;font-weight:900;letter-spacing:-.5px;margin:0}
    .sidebar-role{display:inline-flex;align-items:center;gap:6px;padding:6px 14px;background:rgba(242,138,178,.18);color:var(--pink-dark);border-radius:999px;font-size:12px;font-weight:900;margin:8px 0 0}
    .sidebar-stats{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:22px;text-align:left}
    .s-stat{background:rgba(255,241,246,.7);border-radius:18px;padding:14px}
    .s-stat span{display:block;font-size:11px;color:var(--muted);font-weight:700}
    .s-stat strong{display:block;font-size:22px;font-weight:900;margin-top:4px;letter-spacing:-.5px}
    .sidebar-btns{display:flex;flex-direction:column;gap:10px;margin-top:22px}
    .btn-primary{background:linear-gradient(135deg,var(--hot-pink),var(--pink));color:#fff;padding:13px 20px;border-radius:999px;border:none;font-size:13px;font-weight:900;cursor:pointer;box-shadow:0 8px 18px rgba(231,90,155,.26);transition:.18s ease;width:100%}
    .btn-primary:hover{transform:translateY(-1px)}
    .btn-outline{background:rgba(255,255,255,.84);color:#7A3D65;padding:13px 20px;border-radius:999px;border:1px solid rgba(46,42,59,.10);font-size:13px;font-weight:900;cursor:pointer;transition:.18s ease;width:100%}
    .btn-outline:hover{transform:translateY(-1px);background:#fff}
    
    .lang-proficiency-row{
        display:flex;
        align-items:center;
        gap:15px;
        margin-bottom:15px;
        padding:12px;
        background:rgba(255,241,246,.5);
        border-radius:16px;
    }
    .lang-proficiency-row > div:first-child{
        min-width:100px;
        font-weight:700;
    }
    
    .toast{position:fixed;left:50%;bottom:28px;transform:translate(-50%,18px);opacity:0;pointer-events:none;z-index:500;background:#8E3F70;color:#fff;border-radius:999px;padding:12px 22px;font-size:13px;font-weight:900;transition:.2s ease}
    .toast.show{opacity:1;transform:translate(-50%,0)}
    #passwordMatchHint {
    font-size: 12px;
    margin-top: 5px;
    display: block;
}
    @media(max-width:900px){.profile-layout{grid-template-columns:1fr}}
    @media(max-width:980px){.nav{grid-template-columns:1fr auto;min-height:auto;padding:10px 0}.nav-links{grid-column:1/-1;grid-row:2;width:100%}}
    @media(max-width:760px){.container{width:min(100% - 22px,100%)}.profile-nav span,.brand span{display:none}.}
        /* Responsive Design */
    @media(max-width: 1100px) {
        .nav {
            grid-template-columns: 160px minmax(0, 1fr) 280px;
            gap: 12px;
        }
        .nav-links a {
            padding: 8px 10px;
            font-size: 12px;
        }
    }
    
    @media(max-width: 980px) {
        .nav {
            grid-template-columns: 1fr auto;
            min-height: auto;
            padding: 12px 0;
            gap: 16px;
        }
        .nav-links {
            grid-column: 1 / -1;
            grid-row: 2;
            width: 100%;
            justify-content: flex-start;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            padding: 8px;
        }
        .nav-actions {
            justify-content: flex-end;
        }
        .brand span {
            display: none;
        }
        .profile-nav span {
            max-width: 120px;
        }
            .profile-nav i.bi-chevron-down {
        display: none;
    }
    }
    
    @media(max-width: 900px) {
        .container {
            width: calc(100% - 24px);
            padding: 0 12px;
        }
        
        .profile-layout {
            grid-template-columns: 1fr;
            gap: 20px;
        }
        
        .profile-sidebar {
            position: relative;
            top: 0;
            padding: 24px;
        }
        
        .form-panel {
            padding: 20px;
        }
        
        .form-grid {
            grid-template-columns: 1fr;
            gap: 14px;
        }
        
        .chip-group {
            gap: 8px;
        }
        
        .pref-chip {
            padding: 8px 14px;
            font-size: 12px;
        }
        
        .tabs {
            flex-wrap: wrap;
            width: 100%;
        }
        
        .tab {
            flex: 1;
            text-align: center;
            padding: 10px 12px;
            font-size: 12px;
        }
        
        .sidebar-stats {
            grid-template-columns: 1fr 1fr;
            gap: 10px;
        }
        
        .s-stat strong {
            font-size: 18px;
        }
        
        .lang-proficiency-row {
            flex-direction: column;
            align-items: flex-start;
            gap: 10px;
        }
        
        .lang-proficiency-row > div:first-child {
            min-width: auto;
        }
        
        .chip-group {
            width: 100%;
        }
        
        .edit-actions {
            flex-direction: column;
            gap: 12px;
        }
        
        .edit-actions .btn-primary,
        .edit-actions .btn-outline {
            width: 100%;
        }
        
        .breadcrumb {
            margin-bottom: 16px;
            font-size: 12px;
        }
        
        .page-wrap {
            padding: 20px 0 40px;
        }
    }
    
    @media(max-width: 600px) {
        .brand strong {
            font-size: 15px;
        }
        
        .nav-links a {
            padding: 6px 10px;
            font-size: 11px;
        }
        
        .profile-nav {
            padding: 5px 10px 5px 5px;
        }
        
        .profile-nav img {
            width: 28px;
            height: 28px;
        }
        
        .profile-nav span {
            max-width: 80px;
            font-size: 12px;
        }
        
        .avatar-wrap {
            width: 100px;
            height: 100px;
        }
        
        .avatar-wrap img {
            width: 100px;
            height: 100px;
        }
        
        .sidebar-name {
            font-size: 20px;
        }
        
        .form-panel h3 {
            font-size: 18px;
        }
        
        .form-panel .sub {
            font-size: 12px;
        }
        
        .pref-chip {
            padding: 6px 12px;
            font-size: 11px;
        }
        
        .btn-primary, .btn-outline {
            padding: 11px 16px;
            font-size: 12px;
        }
    }
    
    @media(max-width: 480px) {
        .container {
            width: calc(100% - 16px);
        }
        
        .profile-sidebar {
            padding: 20px;
        }
        
        .sidebar-stats .s-stat {
            padding: 10px;
        }
        
        .s-stat strong {
            font-size: 16px;
        }
        
        .s-stat span {
            font-size: 10px;
        }
        
        .pref-chip {
            padding: 6px 10px;
            font-size: 10px;
        }
    }
  </style>
<link rel="stylesheet" href="style.css">
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
        <div><strong>Kyoshi</strong><span>Student Learning Space</span></div>
      </a>
      <div class="nav-links">
        <a href="student_dashboard.php">Home</a>
        <a href="find_language.php">Find Language</a>
        <a href="booking_status.php">My Bookings</a>
        <a href="my_payments.php">My Payments</a>
        <a href="my_materials.php">My Materials</a>
        <a href="my_assignments.php">My Assignments</a>

      </div>
      <div class="nav-actions">
        <div style="position:relative;">
          <button class="profile-nav" onclick="toggleDropdown()" id="profileBtn">
            <img src="<?= e($profilePic) ?>" alt="Student profile">
            <span><?= e($displayName) ?></span>
            <i class="bi bi-chevron-down" style="font-size:11px; margin-left:4px;"></i>
          </button>
          <div id="profileDropdown" style="display:none;position:absolute;top:calc(100% + 10px);right:0;background:white;border-radius:16px;box-shadow:0 18px 45px rgba(201,79,134,.2);border:1px solid rgba(242,138,178,.2);min-width:180px;overflow:hidden;z-index:100;">
            <a href="student_profile.php" style="display:flex;align-items:center;gap:10px;padding:14px 16px;font-size:14px;font-weight:700;color:#342635;"><i class="bi bi-person-circle" style="color:#E75A9B;"></i> My Profile</a>
            <a href="my_progress.php" style="display:flex;align-items:center;gap:10px;padding:14px 16px;font-size:14px;font-weight:700;color:#342635;"><i class="bi bi-bar-chart-steps" style="color:#E75A9B;"></i> My Progress</a>
            <a href="student_favourites.php" style="display:flex;align-items:center;gap:10px;padding:14px 16px;font-size:14px;font-weight:700;color:#342635;"><i class="bi bi-heart" style="color:#E75A9B;"></i> My Favourites</a>
            <hr style="margin:4px 0;border-color:rgba(242,138,178,.2);">
            <a href="logout.php" style="display:flex;align-items:center;gap:10px;padding:14px 16px;font-size:14px;font-weight:700;color:#dc2626;"><i class="bi bi-box-arrow-right"></i> Logout</a>
          </div>
        </div>
      </div>
    </nav>
  </div>
</header>
  <div class="nav-overlay" id="navOverlay"></div>
<main class="container">
  <div class="page-wrap">
    <div class="breadcrumb">
      <a href="student_dashboard.php">Home</a>
      <i class="bi bi-chevron-right" style="font-size:10px;"></i>
      <span>My Profile</span>
    </div>

    <div class="tabs">
      <button class="tab active" onclick="switchTab('profile',this)"><i class="bi bi-person" style="margin-right:6px;"></i>Profile</button>
      <button class="tab" onclick="switchTab('password',this)"><i class="bi bi-shield-lock" style="margin-right:6px;"></i>Security</button>
    </div>

    <div id="tab-profile">
      <div class="profile-layout">
        <!-- Left Sidebar - Normal (No Glass) -->
        <aside class="profile-sidebar">
          <div class="avatar-wrap">
            <img src="<?= e($profilePic) ?>" alt="Profile" id="previewImg">
            <label for="picInput" class="avatar-edit" title="Change photo">
              <i class="bi bi-camera"></i>
            </label>
          </div>
          <h2 class="sidebar-name"><?= e($displayName) ?></h2>
          <span class="sidebar-role"><i class="bi bi-mortarboard-fill"></i> Student</span>
          <div class="sidebar-stats">
            <div class="s-stat"><span>Sessions</span><strong><?= $totalSessions ?></strong></div>
            <div class="s-stat"><span>Completed</span><strong><?= $completedCount ?></strong></div>
            <div class="s-stat"><span>Languages</span><strong><?= count($preferredLanguages) ?: '—' ?></strong></div>
            <div class="s-stat"><span>Member Since</span><strong><?= date('Y', strtotime($user['created_at'] ?? 'now')) ?></strong></div>
          </div>
          <div class="sidebar-btns">
            <button type="button" class="btn-primary" onclick="enableEditMode()"><i class="bi bi-pencil" style="margin-right:7px;"></i>Edit Profile</button>
            <button class="btn-outline" onclick="switchTab('password', document.querySelectorAll('.tab')[1])"><i class="bi bi-shield-lock" style="margin-right:7px;"></i>Change Password</button>
          </div>
        </aside>

        <!-- Right Side - Glass Effect -->
        <div class="glass-card">
          <form method="POST" enctype="multipart/form-data" id="profileForm" action="">
            <input type="hidden" name="action" value="update_profile">
            <input type="file" id="picInput" name="profile_pic" accept="image/*" style="display:none;" onchange="previewPhoto(this)">

            <div class="form-panel disabled" id="personalPanel">
              <h3>Personal Information</h3>
              <p class="sub">Update your name and details.</p>
              <div class="form-grid">
                <div class="form-group">
                  <label><i class="bi bi-person" style="color:var(--hot-pink);margin-right:5px;"></i>Full Name</label>
                  <input type="text" name="fullname" disabled value="<?= e($user['fullname']) ?>" required>
                </div>
                <div class="form-group">
                  <label><i class="bi bi-envelope" style="color:var(--hot-pink);margin-right:5px;"></i>Email Address</label>
                  <input type="email" name="email" disabled value="<?= e($user['email']) ?>" required>
                </div>
                <div class="form-group">
                  <label><i class="bi bi-phone" style="color:var(--hot-pink);margin-right:5px;"></i>Contact Number</label>
                  <input type="tel" name="phone" disabled value="<?= e($user['phone'] ?? '') ?>">
                </div>
              </div>
            </div>

            <div class="form-panel disabled" id="prefPanel">
              <h3>Learning Preferences</h3>
              <p class="sub">Select the languages you want to learn and set your proficiency for each.</p>

              <p class="section-label"><i class="bi bi-globe2" style="color:var(--hot-pink);margin-right:6px;"></i>Languages I want to learn</p>
              <div class="chip-group" id="langChips">
                <?php foreach ($allLanguages as $lang): ?>
                  <button type="button" class="pref-chip <?= in_array($lang, $preferredLanguages) ? 'active' : '' ?>" onclick="togglePrefChip(this,'langHidden','<?= e($lang) ?>')">
                    <?= e($lang) ?>
                  </button>
                <?php endforeach; ?>
              </div>
              <div id="langHidden"></div>
              <?php foreach ($preferredLanguages as $lang): ?>
                <input type="hidden" name="languages[]" value="<?= e($lang) ?>" data-val="<?= e($lang) ?>">
              <?php endforeach; ?>

              <div class="section-divider"></div>

              <p class="section-label"><i class="bi bi-laptop" style="color:var(--hot-pink);margin-right:6px;"></i>Preferred Learning Mode</p>
              <div class="chip-group" id="modeChips">
                <button type="button" class="pref-chip <?= in_array('online',$preferredModes)?'active':'' ?>" onclick="togglePrefChip(this,'modeHidden','online')">Online</button>
                <button type="button" class="pref-chip <?= in_array('face_to_face',$preferredModes)?'active':'' ?>" onclick="togglePrefChip(this,'modeHidden','face_to_face')">Face to Face</button>
              </div>
              <div id="modeHidden"></div>
              <?php foreach ($preferredModes as $mode): ?>
                <input type="hidden" name="modes[]" value="<?= e($mode) ?>" data-val="<?= e($mode) ?>">
              <?php endforeach; ?>

              <div class="section-divider"></div>

              <p class="section-label"><i class="bi bi-graph-up" style="color:var(--hot-pink);margin-right:6px;"></i>My Language Proficiency</p>
              <div id="proficiencyContainer">
                <?php foreach ($preferredLanguages as $lang): 
                    $currentLevel = $languageProficiency[$lang] ?? 'beginner';
                ?>
                <div class="lang-proficiency-row" data-lang="<?= e($lang) ?>">
                  <div><?= e($lang) ?></div>
                  <div class="chip-group" style="flex:1;">
                    <button type="button" class="pref-chip level-chip <?= $currentLevel === 'beginner' ? 'active' : '' ?>" data-lang="<?= e($lang) ?>" data-level="beginner" onclick="selectLanguageLevel(this, '<?= e($lang) ?>', 'beginner')">Beginner</button>
                    <button type="button" class="pref-chip level-chip <?= $currentLevel === 'intermediate' ? 'active' : '' ?>" data-lang="<?= e($lang) ?>" data-level="intermediate" onclick="selectLanguageLevel(this, '<?= e($lang) ?>', 'intermediate')">Intermediate</button>
                    <button type="button" class="pref-chip level-chip <?= $currentLevel === 'advanced' ? 'active' : '' ?>" data-lang="<?= e($lang) ?>" data-level="advanced" onclick="selectLanguageLevel(this, '<?= e($lang) ?>', 'advanced')">Advanced</button>
                    <button type="button" class="pref-chip level-chip <?= $currentLevel === 'master' ? 'active' : '' ?>" data-lang="<?= e($lang) ?>" data-level="master" onclick="selectLanguageLevel(this, '<?= e($lang) ?>', 'master')">Master</button>
                  </div>
                </div>
                <?php endforeach; ?>
              </div>
              <div id="languageProficiencyData"></div>

            </div>

            <div class="edit-actions" id="editActions">
              <button type="submit" class="btn-primary"><i class="bi bi-check2" style="margin-right:7px;"></i>Save Changes</button>
              <button type="button" class="btn-outline" onclick="discardChanges()"><i class="bi bi-x-lg" style="margin-right:7px;"></i>Discard</button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <div id="tab-password" style="display:none;">
      <div class="glass-card">
        <div class="form-panel">
          <form method="POST">
            <input type="hidden" name="action" value="change_password">
            <h3>Change Password</h3>
            <p class="sub">Choose a strong password to protect your account.</p>
            <div class="form-group" style="margin-bottom:16px;">
              <label>Current Password</label>
              <div style="position:relative;">
                <input type="password" name="current_password" id="curPwd" required style="width:100%;padding-right:44px;">
                <button type="button" onclick="togglePwd('curPwd','eyeCur')" style="position:absolute;right:12px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;"><i class="bi bi-eye" id="eyeCur"></i></button>
              </div>
            </div>
            <div class="form-group" style="margin-bottom:6px;">
              <label>New Password</label>
              <div style="position:relative;">
                <input type="password" name="new_password" id="newPwd" required oninput="checkStrength(this.value); checkPasswordMatch()" style="width:100%;padding-right:44px;">
                <button type="button" onclick="togglePwd('newPwd','eyeNew')" style="position:absolute;right:12px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;"><i class="bi bi-eye" id="eyeNew"></i></button>
              </div>
              <div class="password-strength" id="strengthBars"><div class="ps-bar" id="bar1"></div><div class="ps-bar" id="bar2"></div><div class="ps-bar" id="bar3"></div></div>
              <span class="ps-hint" id="strengthHint">Enter a password</span>
            </div>
            <div class="form-group" style="margin-bottom:24px;">
              <label>Confirm New Password</label>
              <div style="position:relative;">
                  <input type="password" name="confirm_password" id="conPwd" required style="width:100%;padding-right:44px;" onkeyup="checkPasswordMatch()">
                  <button type="button" onclick="togglePwd('conPwd','eyeCon')" style="position:absolute;right:12px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;"><i class="bi bi-eye" id="eyeCon"></i></button>
              </div>
              <span id="passwordMatchHint" style="font-size:12px; margin-top:5px; display:block;"></span>
          </div>
            <button type="submit" class="btn-primary" style="width:100%;"><i class="bi bi-shield-check"></i> Update Password</button>
          </form>
        </div>
      </div>
    </div>
  </div>
</main>

<div class="toast" id="toast"></div>

<script>
  function switchTab(name, btn) {
    document.getElementById('tab-profile').style.display = name === 'profile' ? 'block' : 'none';
    document.getElementById('tab-password').style.display = name === 'password' ? 'block' : 'none';
    document.querySelectorAll('.tab').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
  }

  function togglePrefChip(btn, containerId, value) {
    const container = document.getElementById(containerId);
    const name = containerId === 'langHidden' ? 'languages[]' : 'modes[]';
    const isActive = btn.classList.contains('active');
    
    if (isActive) {
      btn.classList.remove('active');
      container.querySelectorAll(`input[value="${value}"]`).forEach(el => el.remove());
      // Remove proficiency row when language is deselected
      const profRow = document.querySelector(`.lang-proficiency-row[data-lang="${value}"]`);
      if (profRow) profRow.remove();
    } else {
      btn.classList.add('active');
      const inp = document.createElement('input');
      inp.type = 'hidden';
      inp.name = name;
      inp.value = value;
      container.appendChild(inp);
      // Add proficiency row when language is selected
      addProficiencyRow(value);
    }
  }

    function checkPasswordMatch() {
    const newPwd = document.getElementById('newPwd');
    const confirmPwd = document.getElementById('conPwd');
    
    // Only run if both elements exist (they only exist in password tab)
    if (!newPwd || !confirmPwd) return;
    
    const matchHint = document.getElementById('passwordMatchHint');
    if (!matchHint) return;
    
    if (confirmPwd.value === '') {
        matchHint.innerHTML = '';
        matchHint.style.color = '';
    } else if (newPwd.value === confirmPwd.value) {
        matchHint.innerHTML = '<i class="bi bi-check-circle"></i> Passwords match!';
        matchHint.style.color = '#28a745';
    } else {
        matchHint.innerHTML = '<i class="bi bi-x-circle"></i> Passwords do not match';
        matchHint.style.color = '#dc2626';
    }
}

function addProficiencyRow(language) {
    const container = document.getElementById('proficiencyContainer');
    // Check if row already exists
    if (container.querySelector(`.lang-proficiency-row[data-lang="${language}"]`)) return;
    
    // Get existing proficiency level from PHP data or from existing hidden inputs
    let currentLevel = 'beginner';
    
    // Check PHP data
    <?php foreach ($languageProficiency as $lang => $level): ?>
        if (language === '<?= e($lang) ?>') currentLevel = '<?= e($level) ?>';
    <?php endforeach; ?>
    
    // Check if we already have a proficiency selection for this language in the form
    const existingProficiency = document.querySelector(`input[name="language_levels[]"][data-lang="${language}"]`);
    if (existingProficiency) {
        try {
            const existingData = JSON.parse(existingProficiency.value);
            if (existingData.level) currentLevel = existingData.level;
        } catch(e) {}
    }
    
    const row = document.createElement('div');
    row.className = 'lang-proficiency-row';
    row.setAttribute('data-lang', language);
    row.innerHTML = `
        <div style="min-width:100px;font-weight:700;">${language}</div>
        <div class="chip-group" style="flex:1;">
            <button type="button" class="pref-chip level-chip ${currentLevel === 'beginner' ? 'active' : ''}" data-lang="${language}" data-level="beginner" onclick="selectLanguageLevel(this, '${language}', 'beginner')">Beginner</button>
            <button type="button" class="pref-chip level-chip ${currentLevel === 'intermediate' ? 'active' : ''}" data-lang="${language}" data-level="intermediate" onclick="selectLanguageLevel(this, '${language}', 'intermediate')">Intermediate</button>
            <button type="button" class="pref-chip level-chip ${currentLevel === 'advanced' ? 'active' : ''}" data-lang="${language}" data-level="advanced" onclick="selectLanguageLevel(this, '${language}', 'advanced')">Advanced</button>
            <button type="button" class="pref-chip level-chip ${currentLevel === 'master' ? 'active' : ''}" data-lang="${language}" data-level="master" onclick="selectLanguageLevel(this, '${language}', 'master')">Master</button>
        </div>
    `;
    container.appendChild(row);
    
    // Ensure the proficiency level is saved in the form
    if (!existingProficiency) {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'language_levels[]';
        input.value = JSON.stringify({ language: language, level: currentLevel });
        input.setAttribute('data-lang', language);
        document.getElementById('languageProficiencyData').appendChild(input);
    }
}

document.getElementById('profileForm').addEventListener('submit', function() {
    const submitBtn = this.querySelector('button[type="submit"]');
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="bi bi-hourglass-split"></i> Saving...';
});

  function selectLanguageLevel(btn, language, level) {
    const row = btn.closest('.lang-proficiency-row');
    row.querySelectorAll('.level-chip').forEach(chip => chip.classList.remove('active'));
    btn.classList.add('active');
    const container = document.getElementById('languageProficiencyData');
    const existing = container.querySelector(`input[data-lang="${language}"]`);
    if (existing) existing.remove();
    const input = document.createElement('input');
    input.type = 'hidden';
    input.name = 'language_levels[]';
    input.value = JSON.stringify({ language: language, level: level });
    input.setAttribute('data-lang', language);
    container.appendChild(input);
  }

  function previewPhoto(input) {
    if (!input.files || !input.files[0]) return;
    
    const file = input.files[0];
    const reader = new FileReader();
    
    reader.onload = function(e) {
        // Update the preview image immediately
        document.getElementById('previewImg').src = e.target.result;
        
        // Also update the profile image in the navigation bar
        const navProfileImg = document.querySelector('.profile-nav img');
        if (navProfileImg) {
            navProfileImg.src = e.target.result;
        }
    };
    reader.readAsDataURL(file);
    
    // Upload to server
    const formData = new FormData();
    formData.append('profile_pic', file);
    formData.append('action', 'update_pic_only');
    
    fetch('student_profile.php', { 
        method: 'POST', 
        body: formData 
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            showToast('Profile photo updated successfully!');
            
            // Add timestamp to prevent caching
            const timestamp = new Date().getTime();
            const newSrc = '../uploads/profiles/' + data.filename + '?v=' + timestamp;
            
            // Update both images with the server filename to ensure persistence after refresh
            document.getElementById('previewImg').src = newSrc;
            const navProfileImg = document.querySelector('.profile-nav img');
            if (navProfileImg) {
                navProfileImg.src = newSrc;
            }
        } else {
            showToast('Upload failed: ' + (data.error || 'Unknown error'));
            // Revert to original image if upload failed
            location.reload();
        }
    })
    .catch(error => {
        showToast('Upload failed: Network error');
        console.error('Error:', error);
    });
}

  function togglePwd(inputId, iconId) {
    const inp = document.getElementById(inputId);
    const icon = document.getElementById(iconId);
    if (inp.type === 'password') { inp.type = 'text'; icon.className = 'bi bi-eye-slash'; }
    else { inp.type = 'password'; icon.className = 'bi bi-eye'; }
  }

  function checkStrength(val) {
    const b1 = document.getElementById('bar1'), b2 = document.getElementById('bar2'), b3 = document.getElementById('bar3');
    const hint = document.getElementById('strengthHint');
    let score = 0;
    
    // Length check
    if (val.length >= 8) score++;
    // Has numbers
    if (/[0-9]/.test(val)) score++;
    // Has uppercase AND special characters
    if (/[A-Z]/.test(val) && /[!@#$%^&*]/.test(val)) score++;
    
    [b1,b2,b3].forEach(b => b.className='ps-bar');
    
    if (score === 0) { 
        b1.classList.add('w'); 
        hint.textContent='Weak - need at least 8 characters'; 
    }
    else if (score === 1) { 
        b1.classList.add('w'); 
        b2.classList.add('w'); 
        hint.textContent='Weak - add numbers and special characters'; 
    }
    else if (score === 2) { 
        b1.classList.add('m'); 
        b2.classList.add('m'); 
        hint.textContent='Medium - add uppercase and special characters (!@#$%^&*)'; 
    }
    else if (score === 3) { 
        b1.classList.add('s'); 
        b2.classList.add('s'); 
        b3.classList.add('s'); 
        hint.textContent='Strong password ✓'; 
    }
}

  function showToast(msg) { const t = document.getElementById('toast'); t.textContent = msg; t.classList.add('show'); setTimeout(() => t.classList.remove('show'), 3000); }
  function toggleDropdown() { const d = document.getElementById('profileDropdown'); d.style.display = d.style.display === 'none' ? 'block' : 'none'; }
  document.addEventListener('click', e => { const btn = document.getElementById('profileBtn'); const dd = document.getElementById('profileDropdown'); if (btn && dd && !btn.contains(e.target) && !dd.contains(e.target)) dd.style.display = 'none'; });

  function enableEditMode() {
    document.getElementById('personalPanel').classList.remove('disabled');
    document.getElementById('prefPanel').classList.remove('disabled');
    document.getElementById('editActions').style.display = 'flex';
    document.querySelectorAll('#profileForm input').forEach(el => el.disabled = false);
    document.getElementById('picInput').disabled = false;
    document.querySelectorAll('.pref-chip').forEach(chip => { chip.style.pointerEvents = 'auto'; chip.style.opacity = '1'; });
    showToast('Edit mode enabled — you can now change your details');
  }

  function discardChanges() { if (confirm('Discard all changes?')) location.reload(); }

  document.querySelectorAll('.pref-chip').forEach(chip => { chip.style.pointerEvents = 'none'; chip.style.opacity = '0.7'; });

  <?php if ($successMsg): ?> showToast("<?= e($successMsg) ?>"); <?php endif; ?>
  <?php if ($errorMsg): ?> showToast("<?= addslashes(htmlspecialchars_decode(e($errorMsg))) ?>"); <?php endif; ?>
</script>

<script src="../js/nav.js"></script>
<script>
history.pushState(null, null, location.href);
window.addEventListener('popstate', function() {
    window.location.href = 'login.php';
});
</script>

</body>
</html>