<?php
session_start();
include 'config.php';
include 'check_login.php';
$assetBase = '../assets/img';
// AUTO-CANCEL UNPAID BOOKINGS (Run on every page load)
// ============================================================
// Cancel accepted bookings where:
// 1. Session date has passed
// 2. No verified payment exists
$autoCancelQuery = "
    UPDATE bookings b
    LEFT JOIN payments p ON b.id = p.booking_id AND p.status = 'verified'
    SET b.status = 'cancelled',
        b.cancel_reason = 'Payment not received before session time'
    WHERE b.status = 'accepted'
    AND b.booking_date < CURDATE()
    AND p.id IS NULL
";
$conn->query($autoCancelQuery);

// Also cancel same-day sessions where time has passed
$autoCancelTodayQuery = "
    UPDATE bookings b
    LEFT JOIN payments p ON b.id = p.booking_id AND p.status = 'verified'
    SET b.status = 'cancelled',
        b.cancel_reason = 'Payment not received before session time'
    WHERE b.status = 'accepted'
    AND b.booking_date = CURDATE()
    AND b.booking_time < CURTIME()
    AND p.id IS NULL
";
$conn->query($autoCancelTodayQuery);

// ============================================================
// INSERT NOTIFICATIONS FOR AUTO-CANCELLED BOOKINGS
// ============================================================
$conn->query("
    INSERT INTO notifications (user_id, title, message, type, link, created_at)
    SELECT 
        b.student_id,
        'Session Cancelled',
        CONCAT('Your ', b.language, ' session on ', 
               DATE_FORMAT(b.booking_date, '%W, %d %M %Y'), ' at ', 
               TIME_FORMAT(b.booking_time, '%h:%i %p'), 
               ' has been cancelled because payment was not received before the session. Please book a new session.'),
        'auto_cancelled',
        CONCAT('booking_status.php?id=', b.id),
        NOW()
    FROM bookings b
    WHERE b.status = 'cancelled' 
    AND b.cancel_reason = 'Payment not received before session time'
    AND NOT EXISTS (
        SELECT 1 FROM notifications n 
        WHERE n.type = 'auto_cancelled' 
        AND n.user_id = b.student_id
        AND n.link LIKE CONCAT('%', b.id, '%')
    )
");

require_once '../vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Get cancelled bookings that haven't been emailed yet
$cancelledBookings = $conn->query("
    SELECT b.*, u.fullname as student_name, u.email as student_email
    FROM bookings b
    JOIN users u ON b.student_id = u.id
    WHERE b.status = 'cancelled' 
    AND b.cancel_reason = 'Payment not received before session time'
    AND NOT EXISTS (
        SELECT 1 FROM notifications n 
        WHERE n.type = 'auto_cancelled_email' 
        AND n.user_id = b.student_id
        AND n.link LIKE CONCAT('%', b.id, '%')
    )
");

while ($booking = $cancelledBookings->fetch_assoc()) {
    try {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = SMTP_USER;
        $mail->Password = SMTP_PASS;
        $mail->SMTPSecure = 'tls';
        $mail->Port = 587;
        $mail->setFrom('sohisabella87@gmail.com', 'Kyoshi');
        $mail->addAddress($booking['student_email'], $booking['student_name']);
        $mail->isHTML(true);
        $mail->Subject = 'Your Session Has Been Cancelled - Kyoshi';
        
        $bookingDate = date('l, F j, Y', strtotime($booking['booking_date']));
        $bookingTime = date('g:i A', strtotime($booking['booking_time']));
        
        $mail->Body = "
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: auto; background: #f9f9f9; border-radius: 20px; padding: 30px;'>
            <div style='text-align: center;'>
                <h1 style='color: #dc2626;'>Session Cancelled ❌</h1>
            </div>
            <div style='background: white; border-radius: 16px; padding: 20px; margin-top: 20px;'>
                <p>Dear <strong>{$booking['student_name']}</strong>,</p>
                <p>Your {$booking['language']} session scheduled for:</p>
                <div style='background: #f0f0f0; border-radius: 12px; padding: 15px; margin: 15px 0;'>
                    <p><strong>Date:</strong> {$bookingDate}</p>
                    <p><strong>Time:</strong> {$bookingTime}</p>
                    <p><strong>Tutor:</strong> " . ($booking['tutor_name'] ?? 'Your tutor') . "</p>
                </div>
                <p>has been <strong style='color: #dc2626;'>CANCELLED</strong> because payment was not received before the session time.</p>
                <p>To avoid this in the future, please complete payment as soon as your tutor accepts the booking.</p>
                <div style='text-align: center; margin-top: 25px;'>
                    <a href='http://kyoshitutor.site/php/find_language.php' 
                       style='display: inline-block; padding: 12px 30px; background: linear-gradient(135deg, #E75A9B, #F28AB2); 
                              color: white; text-decoration: none; border-radius: 30px; font-weight: bold;'>
                        Book a New Session
                    </a>
                </div>
            </div>
            <p style='font-size: 12px; color: #999; text-align: center; margin-top: 20px;'>
                © " . date('Y') . " Kyoshi - Language Learning Platform
            </p>
        </div>";
        
        $mail->send();
        
        // Mark as emailed (insert notification record to track)
        $conn->query("
            INSERT INTO notifications (user_id, title, message, type, link, created_at)
            VALUES ({$booking['student_id']}, 'Cancellation Email Sent', 
                    'An email notification about your cancelled session has been sent.', 
                    'auto_cancelled_email', 
                    'booking_status.php?id={$booking['id']}', NOW())
        ");
        
    } catch (Exception $e) {
        error_log("Auto-cancel email failed: " . $mail->ErrorInfo);
    }
}

if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit(); }
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
$filterStatus  = $_GET['status'] ?? 'all';
$filterDateFrom = isset($_GET['date_from']) && !empty($_GET['date_from']) ? $_GET['date_from'] : '';
$filterDateTo   = isset($_GET['date_to']) && !empty($_GET['date_to']) ? $_GET['date_to'] : '';
$sortBy = $_GET['sort'] ?? 'booked_newest';
$filterLanguage = $_GET['language'] ?? 'all';
$filterLearningMode = $_GET['learning_mode'] ?? 'all';

$where = "WHERE b.student_id = ?";
$params = [$userID];
$types  = "i";

if ($filterStatus !== 'all' && in_array($filterStatus, ['pending','accepted','confirmed','completed','cancelled','rescheduled','disputed'])) {
    $where .= " AND b.status = ?";
    $params[] = $filterStatus;
    $types .= "s";
}
if ($filterDateFrom) {
    $where .= " AND b.booking_date >= ?";
    $params[] = $filterDateFrom;
    $types .= "s";
}
if ($filterDateTo) {
    $where .= " AND b.booking_date <= ?";
    $params[] = $filterDateTo;
    $types .= "s";
}
// ADD LANGUAGE FILTER
if ($filterLanguage !== 'all') {
    $where .= " AND b.language = ?";
    $params[] = $filterLanguage;
    $types .= "s";
}
// ADD LEARNING MODE FILTER
if ($filterLearningMode !== 'all') {
    $where .= " AND b.learning_mode = ?";
    $params[] = $filterLearningMode;
    $types .= "s";
}


$orderBy = "ORDER BY b.created_at DESC";

if ($sortBy === 'booked_newest') {
    $orderBy = "ORDER BY b.created_at DESC";
} elseif ($sortBy === 'booked_oldest') {
    $orderBy = "ORDER BY b.created_at ASC";
} elseif ($sortBy === 'session_soonest') {
    $orderBy = "ORDER BY b.booking_date ASC, b.booking_time ASC";
} elseif ($sortBy === 'session_latest') {
    $orderBy = "ORDER BY b.booking_date DESC, b.booking_time DESC";
} elseif ($sortBy === 'price_low') {
    $orderBy = "ORDER BY tp.rate ASC";
} elseif ($sortBy === 'price_high') {
    $orderBy = "ORDER BY tp.rate DESC";
} elseif ($sortBy === 'language') {
    $orderBy = "ORDER BY b.language ASC";
}

$stmt = $conn->prepare("
    SELECT b.id, b.language, b.learning_mode, b.booking_date, b.booking_time,
           b.status, b.focus, b.created_at, b.tutor_id,
           u.fullname AS tutor_name, u.profile_pic AS tutor_pic, tp.rate,
           MAX(p.status) AS payment_status,
           MAX(p.amount) AS payment_amount,
           CASE WHEN r.id IS NOT NULL THEN 1 ELSE 0 END AS rated
    FROM bookings b
    JOIN users u ON b.tutor_id = u.id
    JOIN tutor_profiles tp ON b.tutor_id = tp.user_id
    LEFT JOIN payments p ON p.booking_id = b.id
    LEFT JOIN ratings r ON r.booking_id = b.id AND r.student_id = ?
    $where
    GROUP BY b.id
    $orderBy
");
array_unshift($params, $userID);
$types = "i" . $types;
$stmt->bind_param($types, ...$params);
$stmt->execute();
$bookings = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Counts
$countStmt = $conn->prepare("SELECT status, COUNT(*) as cnt FROM bookings WHERE student_id = ? GROUP BY status");
$countStmt->bind_param("i", $userID);
$countStmt->execute();
$countResult = $countStmt->get_result();
$counts = ['all'=>0,'pending'=>0,'accepted'=>0,'confirmed'=>0,'rescheduled'=>0,'completed'=>0,'cancelled'=>0,'disputed'=>0];
while ($row = $countResult->fetch_assoc()) {
    $counts[$row['status']] = $row['cnt'];
    $counts['all'] += $row['cnt'];
}

// Get distinct languages for filter dropdown
$langFilterStmt = $conn->prepare("
    SELECT DISTINCT language 
    FROM bookings 
    WHERE student_id = ? 
    ORDER BY language ASC
");
$langFilterStmt->bind_param("i", $userID);
$langFilterStmt->execute();
$availableLanguages = $langFilterStmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get distinct learning modes for filter dropdown
$modeFilterStmt = $conn->prepare("
    SELECT DISTINCT learning_mode 
    FROM bookings 
    WHERE student_id = ? 
    ORDER BY learning_mode ASC
");
$modeFilterStmt->bind_param("i", $userID);
$modeFilterStmt->execute();
$availableModes = $modeFilterStmt->get_result()->fetch_all(MYSQLI_ASSOC);
function e($v){ return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }
function statusCfg($s) {
    $s = strtolower(trim($s));

    return [
        'pending' => [
            'label' => 'Pending',
            'icon' => 'bi-hourglass-split',
            'bg' => 'rgba(255,217,199,.74)',
            'color' => '#A35F3F'
        ],
        'accepted' => [
            'label' => 'Accepted',
            'icon' => 'bi-check-circle',
            'bg' => 'rgba(216,236,255,.78)',
            'color' => '#1A5FA8'
        ],
        'confirmed' => [
            'label' => 'Confirmed',
            'icon' => 'bi-shield-check',
            'bg' => 'rgba(215,238,219,.78)',
            'color' => '#3D7047'
        ],
        'rescheduled' => [
            'label' => 'Rescheduled',
            'icon' => 'bi-calendar-plus',
            'bg' => 'rgba(255,241,200,.78)',
            'color' => '#A06B00'
        ],
        'completed' => [
            'label' => 'Completed',
            'icon' => 'bi-patch-check-fill',
            'bg' => 'rgba(221,211,255,.78)',
            'color' => '#7648B8'
        ],
        'disputed' => [
            'label' => 'Disputed',
            'icon' => 'bi-exclamation-triangle-fill',
            'bg' => 'rgba(255,200,200,.78)',
            'color' => '#C94F4F'
        ],
        'cancelled' => [
            'label' => 'Cancelled',
            'icon' => 'bi-x-circle-fill',
            'bg' => 'rgba(255,200,200,.78)',
            'color' => '#C94F4F'
        ],
    ][$s] ?? [
        'label' => 'Unknown',
        'icon' => 'bi-question',
        'bg' => '#eee',
        'color' => '#999'
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
<meta http-equiv="Pragma" content="no-cache">
<meta http-equiv="Expires" content="0">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>My Bookings · Kyoshi</title>
  <link rel="stylesheet" href="../css/style.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
  <style>
    :root{
      --cream:#FFF1F6;--paper:rgba(255,255,255,.88);--ink:#342635;--muted:#7B6178;
      --pink:#F28AB2;--pink-dark:#C94F86;--hot-pink:#E75A9B;
      --shadow:0 18px 45px rgba(201,79,134,.16);--radius-xl:32px;--radius-lg:24px;
    }
    *{box-sizing:border-box}html{scroll-behavior:smooth}
    body{margin:0;min-height:100vh;font-family:"Segoe UI",Arial,sans-serif;color:var(--ink);
      background:linear-gradient(120deg,rgba(255,241,246,.74),rgba(255,203,220,.30)),
      url("<?= e($assetBase) ?>/background3.jpg") center/cover fixed no-repeat;}
    body::before{content:"";position:fixed;inset:0;pointer-events:none;z-index:-1;
      background:radial-gradient(circle at 7% 10%,rgba(231,90,155,.32),transparent 24%),
      radial-gradient(circle at 90% 8%,rgba(255,195,216,.42),transparent 26%)}
    a{text-decoration:none;color:inherit}button,input,select{font-family:inherit}
    .container{width:min(1440px,calc(100% - 40px));margin:0 auto}

    .topbar{position:sticky;top:0;z-index:50;background:rgba(255,241,246,.86);backdrop-filter:blur(20px);border-bottom:1px solid rgba(231,90,155,.18);box-shadow:0 10px 30px rgba(201,79,134,.10)}
    .nav{min-height:78px;display:grid;grid-template-columns:190px minmax(0,1fr) 360px;gap:16px;align-items:center;}
    .brand{display:flex;align-items:center;gap:10px}
    .brand img{width:44px;height:44px;object-fit:contain;border-radius:14px}
    .brand strong{display:block;font-size:18px;line-height:1.05}
    .brand span{display:block;margin-top:3px;font-size:11px;color:var(--muted);white-space:nowrap}
    .nav-links{display:flex;align-items:center;justify-content:center;gap:6px;;border-radius:999px;padding:7px;overflow:auto;scrollbar-width:none;}
    .nav-links::-webkit-scrollbar{display:none}
    .nav-links a{flex:0 0 auto;padding:9px 12px;border-radius:999px;font-size:13px;font-weight:900;color:#6D4964;white-space:nowrap;transition:.18s ease}
    .nav-links a.active,.nav-links a:hover{background:linear-gradient(135deg,var(--hot-pink),var(--pink));color:#fff;box-shadow:0 8px 18px rgba(231,90,155,.28)}
    .nav-actions{display:flex;align-items:center;gap:10px}
    .profile{display:flex;align-items:center;gap:9px;border-radius:999px;padding:6px 12px 6px 6px;font-weight:900;color:#7A3D65;border:1px solid rgba(46,42,59,.08);background:rgba(255,255,255,.88);cursor:pointer}
    .profile img{width:34px;height:34px;object-fit:cover;border-radius:50%}

    .page-wrap{padding:28px 0 60px}
    .back-link{display:inline-flex;gap:6px;color:var(--pink-dark);font-weight:900;font-size:13px;padding:9px 16px;border-radius:999px;background:rgba(255,255,255,.78);border:1px solid rgba(46,42,59,.08);transition:.18s ease;margin-bottom:20px;}
    .back-link:hover{transform:translateY(-1px)}
    .page-head{margin-bottom:20px}
    .page-head h1{margin:0 0 4px;font-size:28px;letter-spacing:-.6px}
    .page-head p{margin:0;color:var(--muted);font-size:14px}

    /* FILTERS */
    .filter-bar{background:var(--paper);border:1px solid rgba(255,255,255,.55);box-shadow:0 6px 20px rgba(201,79,134,.08);justify-content:center;border-radius:var(--radius-lg);padding:18px 20px;margin-bottom:20px;display:flex;flex-wrap:wrap;gap:14px;align-items:center;margin-top:20px;}
    .filter-group{display:flex;flex-direction:column;gap:5px;flex:1;min-width:160px}
    .filter-group label{font-size:11px;font-weight:900;color:var(--muted);text-transform:uppercase;letter-spacing:.5px}
    .filter-select,.filter-input{padding:10px 14px;border:1px solid rgba(46,42,59,.12);border-radius:12px;outline:none;font-size:13px;font-weight:700;color:var(--ink);background:rgba(255,255,255,.9);transition:.15s ease}
    .filter-select:focus,.filter-input:focus{border-color:var(--hot-pink);box-shadow:0 0 0 3px rgba(231,90,155,.12)}
    .filter-counts{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
    .count-pill{padding:6px 14px;border-radius:999px;font-size:12px;font-weight:900;background:rgba(255,255,255,.8);border:1px solid rgba(46,42,59,.10);color:var(--muted)}
    .count-pill.active{background:linear-gradient(135deg,var(--hot-pink),var(--pink));color:white;border-color:var(--pink)}
    .btn-reset{
    width:100px;
    height:42px;

    display:flex;
    align-items:center;
    justify-content:center;
    gap:6px;

    padding:0;
    border-radius:999px;
    border:1px solid rgba(46,42,59,.12);

    background:none;
    color:var(--muted);

    font-size:13px;
    font-weight:900;

    cursor:pointer;
    white-space:nowrap;
    transition:.18s ease;
    margin-bottom: 5px;
}

.btn-reset:hover{
    background:rgba(255,255,255,.88);
}
    .filter-right{
    margin-left:auto;
    display:flex;
    flex-direction:column;
    align-items:flex-end;
    gap:8px;
    }

    .sort-select{
        min-width:80px;
    }

    /* CARDS */
    .booking-cards{display:flex;flex-direction:column;gap:16px}
    .booking-card{
    background:var(--paper);border:1px solid rgba(255,255,255,.55);
    box-shadow:0 8px 24px rgba(201,79,134,.10);border-radius:var(--radius-lg);
    padding:20px 24px;transition:.18s ease;
    }
    .booking-card:hover{transform:translateY(-2px);box-shadow:var(--shadow)}

    /* TOP ROW: tutor info + status badge */
    .card-top{display:flex;align-items:center;gap:14px;margin-bottom:14px}
    .tutor-img{width:56px;height:56px;object-fit:cover;border-radius:14px;background:#eee;flex-shrink:0}
    .card-top-info{flex:1;min-width:0}
    .card-top-info h4{margin:0 0 3px;font-size:15px;font-weight:900}
    .card-top-info .sub{margin:0;color:var(--muted);font-size:12px;line-height:1.4}
    .status-badge{display:inline-flex;align-items:center;gap:5px;padding:7px 13px;border-radius:999px;font-size:11px;font-weight:900;white-space:nowrap;flex-shrink:0}

    /* MID ROW: tags */
    .card-tags{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:14px}
    .tag{padding:4px 10px;border-radius:999px;font-size:11px;font-weight:900}
    .tag.mode{background:rgba(221,211,255,.5);color:#7648B8}
    .tag.focus{background:rgba(221,244,230,.6);color:#2D6A42}
    .tag.pay-ok{background:rgba(215,238,219,.78);color:#3D7047}
    .tag.pay-no{background:rgba(255,217,199,.74);color:#A35F3F}

    /* BOTTOM ROW: left meta + right actions */
    .card-bottom{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;padding-top:14px;border-top:1px solid rgba(46,42,59,.06)}
    .card-meta{font-size:12px;color:var(--muted);font-weight:700;display:flex;align-items:center;gap:12px;flex-wrap:wrap}
    .card-meta strong{color:var(--hot-pink);font-size:14px}
    .card-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center}

    /* ACTION BUTTONS — all same size/shape */
    .btn-action{padding:9px 18px;border-radius:999px;font-size:12px;font-weight:900;cursor:pointer;display:inline-flex;align-items:center;gap:6px;transition:.15s ease;text-decoration:none;border:none;white-space:nowrap}
    .btn-action:hover{transform:translateY(-1px)}
    .btn-action.primary{background:linear-gradient(135deg,var(--hot-pink),var(--pink));color:white;box-shadow:0 6px 14px rgba(231,90,155,.25)}
    .btn-action.ghost{background:white;color:var(--pink-dark);border:1px solid rgba(201,79,134,.25)}
    .btn-action.purple{background:rgba(221,211,255,.8);color:#7648B8;border:1px solid rgba(167,123,232,.2)}
    .btn-action.muted{background:rgba(255,255,255,.7);color:var(--muted);border:1px solid rgba(46,42,59,.10);cursor:default}
    .btn-action.muted:hover{transform:none}

    .booking-right{display:flex;flex-direction:column;align-items:flex-end;gap:6px;min-width:110px}
    .status-badge{display:inline-flex;align-items:center;gap:5px;padding:7px 12px;border-radius:999px;font-size:11px;font-weight:900;white-space:nowrap}
    .booking-date-label{font-size:11px;font-weight:700;color:var(--muted);text-align:right}
    .booking-rate{font-size:13px;font-weight:900;color:var(--hot-pink)}
    .payment-label{font-size:11px;font-weight:900;padding:4px 10px;border-radius:999px}
    .payment-label.paid{background:rgba(215,238,219,.78);color:#3D7047}
    .payment-label.unpaid{background:rgba(255,217,199,.74);color:#A35F3F}

    .empty-state{padding:48px 24px;border-radius:var(--radius-lg);background:var(--paper);border:1px dashed rgba(46,42,59,.16);color:#6D647C;text-align:center;font-weight:700}
    .empty-state i{font-size:40px;color:rgba(231,90,155,.3);display:block;margin-bottom:12px}

    .toast{position:fixed;left:50%;bottom:28px;transform:translate(-50%,18px);opacity:0;pointer-events:none;z-index:99;background:#8E3F70;color:#fff;border-radius:999px;padding:12px 18px;font-size:13px;font-weight:900;transition:.2s ease}
    .toast.show{opacity:1;transform:translate(-50%,0)}

    .modal-overlay{position:fixed;inset:0;background:rgba(52,38,53,.5);backdrop-filter:blur(6px);z-index:200;display:none;place-items:center;}
    .modal-overlay.show{display:grid}
    .modal-box{background:white;border-radius:24px;padding:28px;max-width:560px;width:calc(100% - 40px);box-shadow:0 30px 60px rgba(201,79,134,.2);max-height:80vh;overflow-y:auto;}
    .modal-box h3{margin:0 0 10px;font-size:18px}
    .modal-box p{margin:0 0 20px;color:var(--muted);font-size:14px;line-height:1.5}
    .modal-actions{display:flex;gap:10px;justify-content:flex-end}
    .search{position:relative;flex:1 1 auto;min-width:0}
    .search i{position:absolute;left:14px;top:50%;transform:translateY(-50%);color:#91899F}
    .search input{width:100%;border:1px solid rgba(46,42,59,.10);background:rgba(255,255,255,.88);outline:none;border-radius:999px;padding:12px 14px 12px 38px;box-shadow:0 10px 26px rgba(201,79,134,.10)}
    .icon-btn{width:44px;height:44px;border-radius:16px;color:#7A4A68;position:relative;flex:0 0 auto;border:1px solid rgba(46,42,59,.08);background:rgba(255,255,255,.88);box-shadow:0 10px 26px rgba(201,79,134,.10);cursor:pointer;display:grid;place-items:center}
    .dot{position:absolute;top:10px;right:10px;width:8px;height:8px;border-radius:50%;background:#E17C91}
    .nav-actions{display:flex;align-items:center;justify-content:flex-end;gap:10px;min-width:0}
    @media(max-width:768px){
      .booking-card{grid-template-columns:1fr;gap:12px}
      .booking-right{align-items:flex-start;flex-direction:row;flex-wrap:wrap}
      .nav{grid-template-columns:1fr auto}
      .nav-links{grid-column:1/-1}
    }

    /* Checkboxes hidden by default, shown in select-mode */
.card-checkbox { display: none; }
.select-mode-reschedule .checkbox-reschedule,
.select-mode-cancel     .checkbox-cancel,
.select-mode-rate       .checkbox-rate { display: block; }

/* Active state for select-mode buttons */
.select-mode-btn { 
  padding:9px 18px;border-radius:999px;font-size:12px;font-weight:900;
  cursor:pointer;display:inline-flex;align-items:center;gap:6px;
  border:1.5px solid rgba(46,42,59,.14);background:rgba(255,255,255,.85);
  color:var(--muted);transition:.15s ease;
}
.select-mode-btn:hover { background:white; color:var(--ink); }
.select-mode-btn.active-reschedule { background:rgba(215,238,219,.9); color:#3D7047; border-color:#3D7047; }
.select-mode-btn.active-cancel     { background:rgba(255,200,200,.9); color:#C94F4F; border-color:#C94F4F; }
.select-mode-btn.active-rate       { background:rgba(221,211,255,.9); color:#7648B8; border-color:#7648B8; }
  .card-checkbox{
  display:none;
  width:18px;
  height:18px;
  margin-top:22px;
  accent-color:#E75A9B;
  cursor:pointer;
  flex-shrink:0;
}

.booking-row{
    display:flex;
    align-items:stretch;
    gap:12px;
    margin-bottom:16px;
}

.checkbox-slot{
    width:0;
    overflow:hidden;
    flex-shrink:0;
    display:flex;
    justify-content:center;
    transition:.2s ease;
}

.select-mode-reschedule .checkbox-slot,
.select-mode-cancel .checkbox-slot,
.select-mode-rate .checkbox-slot{
    width:24px;
    padding-top:22px;
}

.card-checkbox{
    display:none;
    width:18px;
    height:18px;
    accent-color:#E75A9B;
    cursor:pointer;
    margin:0;
}

.select-mode-reschedule .checkbox-reschedule,
.select-mode-cancel .checkbox-cancel,
.select-mode-rate .checkbox-rate{
    display:block;
}

/* ========== FIX FOR 900px AND BELOW - BOOKING STATUS ========== */
@media (max-width: 900px) {
    /* Fix filter bar - stack vertically */
    .filter-bar {
        flex-direction: column;
        align-items: stretch;
        gap: 12px;
    }
    
    .filter-group {
        width: 100%;
        min-width: auto;
    }
    
    .filter-right {
        width: 100%;
        margin-left: 0;
        align-items: stretch;
    }
    
    .sort-select {
        width: 100%;
    }
    
    .btn-reset {
        width: 100%;
        margin-top: 5px;
    }
    
    /* Fix booking cards layout */
    .booking-card {
        padding: 16px;
    }
    
    /* Fix card top section */
    .card-top {
        flex-wrap: wrap;
    }
    
    .tutor-img {
        width: 48px;
        height: 48px;
    }
    
    .card-top-info h4 {
        font-size: 14px;
    }
    
    .status-badge {
        font-size: 10px;
        padding: 5px 10px;
    }
    
    /* Fix card tags */
    .card-tags {
        gap: 5px;
    }
    
    .tag {
        font-size: 9px;
        padding: 3px 8px;
    }
    
    /* Fix card bottom - stack vertically */
    .card-bottom {
        flex-direction: column;
        align-items: flex-start;
        gap: 12px;
    }
    
    .card-meta {
        width: 100%;
        justify-content: space-between;
    }
    
    .card-actions {
        width: 100%;
        justify-content: flex-start;
    }
    
    /* Fix action buttons */
    .btn-action {
        padding: 7px 14px;
        font-size: 11px;
    }
    
    /* Fix select mode buttons */
    .select-mode-btn {
        padding: 7px 14px;
        font-size: 11px;
        flex: 1;
        text-align: center;
        justify-content: center;
    }
    
    div[style*="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px"] {
        flex-direction: column;
    }
    
    /* Fix bulk bar */
    #bulkBar {
        flex-wrap: wrap;
        text-align: center;
        top: 70px;
    }
    
    #bulkBar > div {
        flex-wrap: wrap;
        justify-content: center;
    }
    
    /* Fix modal on mobile */
    .modal-box {
        width: calc(100% - 30px);
        padding: 20px;
        margin: 10px;
    }
    
    .modal-actions {
        flex-wrap: wrap;
    }
    
    .modal-actions button,
    .modal-actions a {
        flex: 1;
        text-align: center;
        justify-content: center;
    }
    
    /* Fix page head */
    .page-head {
        margin-top: 10px !important;
    }
    
    .page-head h1 {
        font-size: 24px;
    }
    
    .back-link {
        position: relative !important;
        transform: none !important;
        margin-bottom: 15px !important;
        display: inline-flex;
        width: fit-content;
    }
    
    /* Fix radio buttons and textareas in modal */
    #cancelModal .modal-box label {
        font-size: 13px;
    }
    
    #cancelModal .modal-box textarea {
        font-size: 12px;
    }
    
    /* Fix booking row layout */
    .booking-row {
        gap: 8px;
    }
    
    .checkbox-slot {
        padding-top: 16px;
    }
}

/* Date Range Row - Same line on desktop */
.date-range-row {
    display: flex;
    gap: 12px;
    align-items: flex-end;
    flex: 1;
}

.date-range-row .filter-group {
    flex: 1;
    margin-bottom: 0;
}

/* Mobile: Full width stacked */
@media (max-width: 768px) {
    .date-range-row {
        flex-direction: column;
        gap: 10px;
        width: 100%;
    }
    
    .date-range-row .filter-group {
        width: 100%;
    }
    
    .date-range-row .filter-input {
        width: 100%;
        box-sizing: border-box;
    }
}

/* For tablet (between 769px and 1024px) - keep side by side but smaller */
@media (min-width: 769px) and (max-width: 1024px) {
    .date-range-row {
        gap: 8px;
    }
}




/* ========== FOR 600px AND BELOW ========== */
@media (max-width: 600px) {
    .page-head h1 {
        font-size: 20px;
    }
    
    .back-link span {
        display: none;
    }
    
    .back-link {
        padding: 6px 12px;
    }
    
    .card-top {
        flex-direction: column;
        align-items: flex-start;
    }
    
    .card-top-info {
        width: 100%;
    }
    
    .status-badge {
        align-self: flex-start;
    }
    
    .tutor-img {
        width: 44px;
        height: 44px;
    }
    
    .btn-action {
        padding: 6px 12px;
        font-size: 10px;
    }
    
    .select-mode-btn {
        padding: 6px 12px;
        font-size: 10px;
    }
    
    .tag {
        font-size: 8px;
        padding: 2px 6px;
    }
    
    .card-meta strong {
        font-size: 12px;
    }
    
    .filter-select, .filter-input {
        font-size: 12px;
        padding: 8px 12px;
    }
    
    .filter-group label {
        font-size: 10px;
    }
}

.back-link span {
    display: inline;
}

@media (max-width: 600px) {
    .back-link span {
        display: none;
    }
    
    .back-link {
        padding: 8px 12px;
    }
}

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
          <a  href="find_language.php">Find Language</a>
          <a class="active" href="booking_status.php">My Bookings</a>
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
<div class="container">
<?php if (isset($_GET['cancelled'])): ?>
<script>window.addEventListener('DOMContentLoaded',()=>showToast('Booking cancelled successfully.'));</script>
<?php endif; ?>
<div class="page-head" style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 15px; margin-bottom: 20px; position: relative;">
    <!-- BACK BUTTON (LEFT) -->
    <a href="student_dashboard.php" class="back-link" style="margin:0;">
        <i class="bi bi-arrow-left"></i>
        <span>Back</span>
    </a>

    <!-- TITLE (CENTER) -->
    <div style="text-align: center; flex: 1;">
        <h1 style="margin: 2px; ;">My Bookings</h1>
        <p style="margin:5px 0 0;color:var(--muted);font-size:14px;">Track all your session requests and their status.</p>
    </div>

    <!-- EMPTY RIGHT FOR BALANCE -->
    <div style="width: 20px;"></div>
</div>
    <!-- FILTER BAR -->
<form method="GET" class="filter-bar">
    <div class="filter-group">
        <label><i class="bi bi-funnel"></i> Status</label>
        <select name="status" class="filter-select" onchange="this.form.submit()">
            <option value="all" <?= $filterStatus==='all'?'selected':'' ?>>All (<?= $counts['all'] ?>)</option>
            <option value="pending"   <?= $filterStatus==='pending'?'selected':'' ?>>Pending (<?= $counts['pending'] ?>)</option>
            <option value="accepted" <?= $filterStatus==='accepted'?'selected':'' ?>>Accepted (<?= $counts['accepted'] ?>)</option>
            <option value="confirmed" <?= $filterStatus==='confirmed'?'selected':'' ?>>Confirmed (<?= $counts['confirmed'] ?>)</option>
            <option value="completed" <?= $filterStatus==='completed'?'selected':'' ?>>Completed (<?= $counts['completed'] ?>)</option>
            <option value="rescheduled" <?= $filterStatus==='rescheduled'?'selected':'' ?>>Rescheduled (<?= $counts['rescheduled'] ?>)</option>
            <option value="disputed" <?= $filterStatus==='disputed'?'selected':'' ?>>Disputed (<?= $counts['disputed'] ?? 0 ?>)</option>
            <option value="cancelled" <?= $filterStatus==='cancelled'?'selected':'' ?>>Cancelled (<?= $counts['cancelled'] ?>)</option>
        </select>
    </div>
    
    <!-- NEW: Language Filter -->
    <div class="filter-group">
        <label><i class="bi bi-translate"></i> Language</label>
        <select name="language" class="filter-select" onchange="this.form.submit()">
            <option value="all" <?= $filterLanguage==='all'?'selected':'' ?>>All Languages</option>
            <?php foreach ($availableLanguages as $lang): ?>
                <option value="<?= e($lang['language']) ?>" <?= $filterLanguage===$lang['language']?'selected':'' ?>>
                    <?= e($lang['language']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    
    <!-- NEW: Learning Mode Filter -->
    <div class="filter-group">
        <label><i class="bi bi-laptop"></i> Learning Mode</label>
        <select name="learning_mode" class="filter-select" onchange="this.form.submit()">
            <option value="all" <?= $filterLearningMode==='all'?'selected':'' ?>>All Modes</option>
            <?php foreach ($availableModes as $mode): ?>
                <option value="<?= e($mode['learning_mode']) ?>" <?= $filterLearningMode===$mode['learning_mode']?'selected':'' ?>>
                    <?= $mode['learning_mode'] === 'online' ? '💻 Online' : '🤝 Face to Face' ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="date-range-row">
        <div class="filter-group">
            <label><i class="bi bi-calendar3"></i> From</label>
            <input type="date" name="date_from" class="filter-input" value="<?= e($_GET['date_from'] ?? '') ?>" onchange="this.form.submit()">
        </div>
        <div class="filter-group">
            <label><i class="bi bi-calendar3"></i> To</label>
            <input type="date" name="date_to" class="filter-input" value="<?= e($_GET['date_to'] ?? '') ?>" onchange="this.form.submit()">
        </div>
    </div>
    
    
    <div class="filter-group">
        <label><i class="bi bi-sort-down"></i> Sort By</label>
        <select name="sort" class="filter-select" onchange="this.form.submit()">
            <optgroup label="Booked On">
                <option value="booked_newest" <?= $sortBy==='booked_newest'?'selected':'' ?>>Newest Booking First</option>
                <option value="booked_oldest" <?= $sortBy==='booked_oldest'?'selected':'' ?>>Oldest Booking First</option>
            </optgroup>
            <optgroup label="Session Date">
                <option value="session_soonest" <?= $sortBy==='session_soonest'?'selected':'' ?>>Soonest Session First</option>
                <option value="session_latest"  <?= $sortBy==='session_latest'?'selected':'' ?>>Latest Session First</option>
            </optgroup>
            <optgroup label="Price">
                <option value="price_low"  <?= $sortBy==='price_low'?'selected':'' ?>>Lowest Price</option>
                <option value="price_high" <?= $sortBy==='price_high'?'selected':'' ?>>Highest Price</option>
            </optgroup>
            <optgroup label="Other">
                <option value="language" <?= $sortBy==='language'?'selected':'' ?>>Language A–Z</option>
            </optgroup>
        </select>
    </div>
    
    <a href="booking_status.php" class="btn-reset">
        <i class="bi bi-x"></i> Reset
    </a>
</form>
    <div id="bulkBar" style="display:none;position:sticky;top:90px;z-index:40;
  background:linear-gradient(135deg,#E75A9B,#F28AB2);border-radius:999px;
  padding:12px 20px;margin-bottom:16px;align-items:center;
  justify-content:center;gap:12px;box-shadow:0 8px 24px rgba(231,90,155,.3);">
  <span id="bulkCount" style="color:white;font-weight:900;font-size:13px;">0 selected</span>
<div style="display:flex;gap:8px;flex-wrap:wrap;justify-content:center;">
    <button id="bulkRateBtn"    onclick="bulkAction('rate')"       class="btn-action ghost" style="background:white;display:none;"><i class="bi bi-star"></i> Rate Selected</button>
    <button id="bulkReschedBtn" onclick="bulkAction('reschedule')" class="btn-action ghost" style="background:white;display:none;"><i class="bi bi-calendar-plus"></i> Reschedule Selected</button>
    <button id="bulkCancelBtn"  onclick="bulkAction('cancel')"     class="btn-action ghost" style="background:white;display:none;"><i class="bi bi-x-circle"></i> Cancel Selected</button>
    <button onclick="clearSelection()" class="btn-action ghost" style="background:rgba(255,255,255,.3);color:white;border-color:rgba(255,255,255,.4);">✕ Clear</button>
  </div>
</div>
<!-- SELECT MODE BAR -->
<div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px;align-items:center;justify-content:center;">
  <span style="font-size:12px;font-weight:900;color:black;justify-content:center;">Press button to select more for </span>
  <button type="button" class="select-mode-btn" id="btnSelectReschedule" onclick="toggleSelectMode('reschedule')">
    <i class="bi bi-calendar-plus"></i> Rescheduling
  </button>
  <button type="button" class="select-mode-btn" id="btnSelectRate" onclick="toggleSelectMode('rate')">
    <i class="bi bi-star"></i> Rating
  </button>
</div>
    <div class="booking-cards">
  <?php if (empty($bookings)): ?>
    <div class="empty-state">
      <i class="bi bi-calendar-x"></i>
      No bookings found.<br>
      <a href="search_tutors.php" style="color:var(--hot-pink);font-weight:900;margin-top:8px;display:inline-block;">Find a tutor →</a>
    </div>
  <?php else: ?>
   <?php foreach ($bookings as $b):
  $cfg = statusCfg($b['status']);
  $tutorPic = !empty($b['tutor_pic']) ? '../uploads/profiles/' . $b['tutor_pic'] : $assetBase . '/profile-tutor.png';
  // Determine which mode this card belongs to
  // Determine which mode this card belongs to
$checkboxClass = '';
// Check if session is in the future for rescheduling
$session_datetime = strtotime($b['booking_date'] . ' ' . $b['booking_time']);
$is_future_session = $session_datetime > time();

if (in_array($b['status'], ['confirmed']) && $is_future_session) {
    $checkboxClass = 'checkbox-reschedule';  // Only future confirmed sessions can be rescheduled
}
elseif (in_array($b['status'], ['pending','accepted'])) {
    $checkboxClass = 'checkbox-cancel';  // Pending/Accepted can be cancelled
}
elseif ($b['status'] === 'completed' && !$b['rated']) {
    $checkboxClass = 'checkbox-rate';  // Completed but not rated can be rated
}
  $bulkable = !empty($checkboxClass);
?>
<div class="booking-row">
<div class="checkbox-slot">
<?php if ($bulkable): ?>
    <input type="checkbox"
    class="card-checkbox <?= $checkboxClass ?>"
    data-id="<?= $b['id'] ?>"
    data-status="<?= e($b['status']) ?>"
    data-rated="<?= (int)$b['rated'] ?>"
     data-tutor="<?= e($b['tutor_name']) ?>"
    data-lang="<?= e($b['language']) ?>"
    data-date="<?= e(date('D, d M Y', strtotime($b['booking_date']))) ?>"
    data-time="<?= e(date('g:i A', strtotime($b['booking_time']))) ?>">
<?php endif; ?>
</div>
  <div class="booking-card" style="flex:1;">

      <!-- TOP: tutor + status -->
      <div class="card-top">
        <img src="<?= e($tutorPic) ?>" class="tutor-img" alt="<?= e($b['tutor_name']) ?>">
        <div class="card-top-info">
          <h4><?= e($b['language']) ?> with <?= e($b['tutor_name']) ?></h4>
          <p class="sub">
            <i class="bi bi-calendar3"></i> <?= date('D, d M Y', strtotime($b['booking_date'])) ?>
            &nbsp;·&nbsp;
             <?= date('g:i A', strtotime($b['booking_time'])) ?>
          </p>
          <p class="sub" style="margin-top: 5px; font-size: 11px; color: var(--muted);">
            <i class="bi bi-clock-history"></i> Booked on <?= date('D, d M Y \a\t g:i A', strtotime($b['created_at'])) ?>
        </p>
        </div>
        <span class="status-badge" style="background:<?= $cfg['bg'] ?>;color:<?= $cfg['color'] ?>;">
          <i class="bi <?= $cfg['icon'] ?>"></i> <?= $cfg['label'] ?>
        </span>
      </div>

      <!-- After the status badge, add completion badge for confirmed past sessions -->
<?php 
$class_time = strtotime($b['booking_date'] . ' ' . $b['booking_time']);
$current_time = time();
$is_past_class = $class_time < $current_time;
$is_confirmed = ($b['status'] === 'confirmed');
$is_completed = ($b['status'] === 'completed');
?>

      <div class="card-tags">
    <span class="tag mode"><?= $b['learning_mode']==='online'?'💻 Online':'🤝 Face to Face' ?></span>
    
    <?php if ($b['focus']): foreach(explode(',',$b['focus']) as $f): ?>
        <span class="tag focus"><?= e(trim($f)) ?></span>
    <?php endforeach; endif; ?>

<?php if ($b['status'] === 'accepted'): ?>
    <?php 
    // Check if there's ANY payment (including pending/rejected)
    $has_any_payment = false;
    $payment_check = $conn->prepare("SELECT id, status, amount FROM payments WHERE booking_id = ? ORDER BY created_at DESC LIMIT 1");
    $payment_check->bind_param("i", $b['id']);
    $payment_check->execute();
    $latest_payment = $payment_check->get_result()->fetch_assoc();
    $payment_check->close();
    
    if ($latest_payment):
        $has_any_payment = true;
        if ($latest_payment['status'] === 'verified'): ?>
            <span class="tag pay-ok"><i class="bi bi-check-circle"></i> Payment Verified</span>
        <?php elseif ($latest_payment['status'] === 'pending'): ?>
            <span class="tag pay-no"><i class="bi bi-hourglass"></i> Payment Processing (RM <?= number_format($latest_payment['amount'], 2) ?>)</span>
        <?php elseif ($latest_payment['status'] === 'rejected'): ?>
            <span class="tag pay-no"><i class="bi bi-exclamation-triangle"></i> Payment Rejected - Please pay remaining</span>
        <?php else: ?>
            <span class="tag pay-no"><i class="bi bi-clock"></i> Awaiting Payment</span>
        <?php endif;
    else: ?>
        <span class="tag pay-no"><i class="bi bi-clock"></i> Awaiting Payment</span>
    <?php endif; ?>
<?php endif; ?>
</div>


      <!-- BOTTOM: meta + actions -->
      <div class="card-bottom">
               <div class="card-meta">
            <?php 
            if (in_array($b['status'], ['confirmed', 'completed', 'rescheduled']) || !empty($b['payment_amount'])): ?>
                <strong>RM <?= e(number_format($b['payment_amount'] ?? $b['rate'], 2)) ?></strong>
            <?php else: ?>
                <strong>RM <?= e($b['rate']) ?>/hr</strong>
            <?php endif; ?>
        </div>

<?php if ($is_confirmed && $is_past_class && !$is_completed): ?>
<div style="margin-top: 12px; background: white; border-radius: 12px; padding: 12px 16px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); border: 1px solid #e8e8e8; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px;">
    <div style="display: flex; align-items: center; gap: 12px;">
        <div style="background: #ff9800; width: 36px; height: 36px; border-radius: 50%; display: flex; align-items: center; justify-content: center;">
            <i class="bi bi-hourglass-split" style="color: white; font-size: 16px;"></i>
        </div>
        <div>
            <div style="font-weight: bold; color: #333; font-size: 13px;">Awaiting Confirmation</div>
            <div style="color: #999; font-size: 11px;">Your session has ended</div>
        </div>
    </div>
    <div style="display: flex; gap: 8px;">
        <a href="booking_detail.php?id=<?= $b['id'] ?>" style="background: linear-gradient(135deg, #28a745, #20c997); color: white; padding: 6px 18px; border-radius: 30px; text-decoration: none; font-weight: 600; font-size: 12px;">
            <i class="bi bi-check-lg"></i> View 
        </a>
        <button onclick="showReportIssue(<?= $b['id'] ?>)" style="background: #f8f9fa; color: #dc3545; padding: 6px 18px; border-radius: 30px; border: 1px solid #dee2e6; cursor: pointer; font-weight: 600; font-size: 12px;">
            <i class="bi bi-exclamation-triangle"></i> Report
        </button>
    </div>
</div>
<?php endif; ?>

                <div class="card-actions">
        <a href="booking_detail.php?id=<?= $b['id'] ?>" class="btn-action primary">
            <i class="bi bi-eye"></i> View Details
        </a>

    <?php if ($b['status'] === 'pending'): ?>
    <button class="btn-action ghost" onclick="confirmCancel(<?= $b['id'] ?>, 'pending')">
        <i class="bi bi-x-circle"></i> Cancel
    </button>
<?php elseif ($b['status'] === 'accepted'): ?>
    <?php 
    // Get the latest payment for this booking to check status
    $payment_check = $conn->prepare("SELECT id, status, amount, actual_paid_amount FROM payments WHERE booking_id = ? ORDER BY created_at DESC LIMIT 1");
    $payment_check->bind_param("i", $b['id']);
    $payment_check->execute();
    $latest_payment = $payment_check->get_result()->fetch_assoc();
    $payment_check->close();
    
    $has_pending = ($latest_payment && $latest_payment['status'] === 'pending');
    $has_rejected = ($latest_payment && $latest_payment['status'] === 'rejected');
    $rejected_amount = ($latest_payment && $latest_payment['actual_paid_amount']) ? $latest_payment['actual_paid_amount'] : 0;
    $remaining = $b['rate'] - $rejected_amount;
    ?>
    
    <?php if ($has_pending): ?>
        <span class="btn-action muted">
            <i class="bi bi-hourglass-split"></i> Payment Under Review (RM <?= number_format($latest_payment['amount'], 2) ?>)
        </span>
    <?php elseif ($has_rejected && $rejected_amount > 0 && $rejected_amount < $b['rate']): ?>
        <a href="payment_form.php?booking_id=<?= $b['id'] ?>&type=remaining" class="btn-action purple">
            <i class="bi bi-cash"></i> Pay Remaining RM <?= number_format($remaining, 2) ?>
        </a>
        <button class="btn-action ghost" onclick="confirmCancel(<?= $b['id'] ?>, 'accepted')">
            <i class="bi bi-x-circle"></i> Cancel
        </button>
    <?php elseif ($b['payment_status'] === 'failed'): ?>
        <a href="payment_form.php?booking_id=<?= $b['id'] ?>" class="btn-action purple">
            <i class="bi bi-credit-card"></i> Retry Payment
        </a>
        <button class="btn-action ghost" onclick="confirmCancel(<?= $b['id'] ?>, 'accepted')">
            <i class="bi bi-x-circle"></i> Cancel
        </button>
    <?php elseif ($b['payment_status'] === 'verified'): ?>
        <span class="btn-action muted">
            <i class="bi bi-check-circle"></i> Awaiting Confirmation
        </span>
    <?php else: ?>
        <a href="payment_form.php?booking_id=<?= $b['id'] ?>" class="btn-action primary">
            <i class="bi bi-credit-card"></i> Pay Now
        </a>
        <button class="btn-action ghost" onclick="confirmCancel(<?= $b['id'] ?>, 'accepted')">
            <i class="bi bi-x-circle"></i> Cancel
        </button>
    <?php endif; ?>

<?php elseif ($b['status'] === 'confirmed'): ?>
    <?php 
    // Check if session is in the future (not ended)
    $session_datetime = strtotime($b['booking_date'] . ' ' . $b['booking_time']);
    $current_time = time();
    $is_future_session = $session_datetime > $current_time;
    $hoursUntilSession = ($session_datetime - $current_time) / 3600;
    ?>



<?php elseif ($b['status'] === 'rescheduled'): ?>
    <div style="display: flex; gap: 8px; flex-wrap: wrap;">
        <span class="btn-action muted">
            <i class="bi bi-calendar-check"></i> Reschedule Requested
        </span>
        <button class="btn-action ghost" onclick="cancelRescheduleRequest(<?= $b['id'] ?>)">
            <i class="bi bi-x-circle"></i> Cancel Reschedule Request
        </button>
    </div>

<?php elseif ($b['status'] === 'disputed'): ?>
    <div style="display: flex; gap: 8px; flex-wrap: wrap;">
        <span class="btn-action muted" style="background:rgba(255,200,200,.78); color:#C94F4F;">
            <i class="bi bi-exclamation-triangle-fill"></i> Under Review
        </span>
    </div>
<?php elseif ($b['status'] === 'completed'): ?>
    <?php if ($b['rated']): ?>
        <span class="btn-action muted"><i class="bi bi-star-fill"></i> Rated</span>
    <?php else: ?>
        <a href="rate_session.php?id=<?= $b['id'] ?>" class="btn-action purple">
    <i class="bi bi-star"></i> Rate Session
</a>
    <?php endif; ?>

<?php endif; ?>

        </div>
      </div>

    </div>
    </div>
    <?php endforeach; ?>
  <?php endif; ?>
  
</div>

<!-- CANCEL MODAL WITH REASONS -->
<div class="modal-overlay" id="cancelModal">
  <div class="modal-box">
    <h3><i class="bi bi-exclamation-triangle" style="color: #dc2626;"></i> Cancel Booking</h3>
    <p>Please select a reason for cancelling:</p>
    <form id="cancelForm" method="POST" action="cancel_booking.php">
      <input type="hidden" name="booking_id" id="cancel_booking_id">
      <input type="hidden" name="refund_type" id="refund_type">
      
      <div style="display: flex; flex-direction: column; gap: 12px; margin-bottom: 20px;">
        <label style="display: flex; align-items: center; gap: 10px; cursor: pointer;">
          <input type="radio" name="cancel_reason" value="Schedule conflict" required>
          <span>I'm not available at that time</span>
        </label>
        
        <label style="display: flex; align-items: center; gap: 10px; cursor: pointer;">
          <input type="radio" name="cancel_reason" value="Found another tutor">
          <span>Found another tutor</span>
        </label>
        
        <label style="display: flex; align-items: center; gap: 10px; cursor: pointer;">
          <input type="radio" name="cancel_reason" value="Change of plans">
          <span>Change of plans / No longer needed</span>
        </label>
                
        <label style="display: flex; align-items: center; gap: 10px; cursor: pointer;">
          <input type="radio" name="cancel_reason" value="Change learning mode">
          <span>Want to change learning mode</span>
        </label>
        
        <label style="display: flex; flex-direction: column; gap: 8px; margin-top: 8px;">
          <div style="display: flex; align-items: center; gap: 10px;">
            <input type="radio" name="cancel_reason" value="Other" id="otherReasonRadio">
            <span>Other:</span>
          </div>
          <textarea name="other_reason" id="otherReasonText" 
            style="width: 100%; padding: 8px 12px; border: 1px solid #e2e8f0; border-radius: 8px; 
                   font-family: inherit; font-size: 13px; resize: vertical; display: none;"
            placeholder="Please specify your reason..."></textarea>
        </label>
      </div>
      
      <div class="modal-actions">
        <button type="button" onclick="closeCancelModal()" style="padding:10px 20px;border-radius:999px;border:1px solid rgba(46,42,59,.12);background:none;color:#7A5570;font-size:13px;font-weight:900;cursor:pointer;">Keep Booking</button>
        <button type="submit" name="submit_cancel" style="background:linear-gradient(135deg,#E75A9B,#F28AB2);color:white;padding:10px 20px;border-radius:999px;border:none;font-size:13px;font-weight:900;cursor:pointer;">
          Yes, Cancel
        </button>
      </div>
    </form>
  </div>
</div>

<div class="modal-overlay" id="rescheduleModal">
  <div class="modal-box">
    <h3 id="rescheduleModalTitle"><i class="bi bi-calendar-plus" style="color: #FFB800;"></i> Reschedule Sessions</h3>
    <div id="rescheduleModalContent"></div>
    <div class="modal-actions" id="rescheduleModalActions"></div>
  </div>
</div>

<div class="modal-overlay" id="ratingModal">
  <div class="modal-box">
    <h3 id="ratingModalTitle"><i class="bi bi-star-fill" style="color: #FFB800;"></i> Rate Sessions</h3>
    <div id="ratingModalContent"></div>
    <div class="modal-actions" id="ratingModalActions"></div>
  </div>
</div>
<script>
let toastTimer;
let activeMode = null; 

function showToast(msg) {
  const t = document.getElementById('toast');
  t.textContent = msg; t.classList.add('show');
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => t.classList.remove('show'), 1800);
}

function toggleDropdown() {
  const d = document.getElementById('profileDropdown');
  d.style.display = d.style.display === 'none' ? 'block' : 'none';
}

document.addEventListener('click', function(e) {
  const btn = document.getElementById('profileBtn');
  const dd  = document.getElementById('profileDropdown');
  if (btn && dd && !btn.contains(e.target) && !dd.contains(e.target)) dd.style.display = 'none';
  if (e.target === document.getElementById('cancelModal')) closeCancelModal();
});

function confirmCancel(id, status, isFullRefund = null) {
  // Store booking ID
  document.getElementById('cancel_booking_id').value = id;
  
  // Update modal title and message based on status and refund eligibility
  const modalTitle = document.querySelector('#cancelModal h3');
  const modalMessage = document.querySelector('#cancelModal p');
  
  if (status === 'confirmed') {
      if (isFullRefund) {
          modalTitle.innerHTML = '<i class="bi bi-cash-stack" style="color: #28a745;"></i> Cancel Confirmed Booking (Full Refund)';
          if (modalMessage) {
              modalMessage.innerHTML = '✅ You will receive a <strong style="color: #28a745;">FULL REFUND</strong> because you are cancelling more than 24 hours before the session.<br><br>Please select a reason for cancelling:';
          }
          // Store refund eligibility
          document.getElementById('cancelForm').setAttribute('data-refund', 'full');
      } else {
          modalTitle.innerHTML = '<i class="bi bi-exclamation-triangle" style="color: #f59e0b;"></i> Cancel Confirmed Booking (No Refund)';
          if (modalMessage) {
              modalMessage.innerHTML = '<strong style="color: #f59e0b;">WARNING: No refund will be issued</strong> because you are cancelling less than 24 hours before the session.<br><br>Please select a reason for cancelling:';
          }
          // Store refund eligibility
          document.getElementById('cancelForm').setAttribute('data-refund', 'none');
      }
  } else {
      modalTitle.innerHTML = '<i class="bi bi-exclamation-triangle" style="color: #dc2626;"></i> Cancel Booking';
      if (modalMessage) {
          modalMessage.innerHTML = 'Please select a reason for cancelling:';
      }
      document.getElementById('cancelForm').removeAttribute('data-refund');
  }
  
  document.getElementById('cancelModal').classList.add('show');
}

function closeCancelModal() {
  document.getElementById('cancelModal').classList.remove('show');
  document.getElementById('cancelForm').reset();
  document.getElementById('otherReasonText').style.display = 'none';
}

// Show/hide other reason textarea
document.addEventListener('DOMContentLoaded', function() {
  const otherRadio = document.getElementById('otherReasonRadio');
  const otherText = document.getElementById('otherReasonText');
  if (otherRadio && otherText) {
    otherRadio.addEventListener('change', function() {
      otherText.style.display = this.checked ? 'block' : 'none';
    });
  }
});

// Handle form submission
document.getElementById('cancelForm')?.addEventListener('submit', function(e) {
  const selectedReason = document.querySelector('input[name="cancel_reason"]:checked');
  if (!selectedReason) {
    e.preventDefault();
    alert('Please select a reason for cancellation');
    return;
  }
  
  let cancelReason = selectedReason.value;
  if (selectedReason.value === 'Other') {
    const otherText = document.getElementById('otherReasonText').value.trim();
    if (!otherText) {
      e.preventDefault();
      alert('Please specify your reason');
      return;
    }
    cancelReason = 'Other: ' + otherText;
  }
  
  // Update the cancel_reason value
  selectedReason.value = cancelReason;
  
  // Set refund type if available
  const refundType = document.getElementById('cancelForm').getAttribute('data-refund');
  if (refundType) {
    document.getElementById('refund_type').value = refundType;
  }
});

// ── SELECT MODE ──
function toggleSelectMode(mode) {
  const body = document.body;
  const btnMap = {
    reschedule: 'btnSelectReschedule',
    cancel:     'btnSelectCancel',
    rate:       'btnSelectRate'
  };

  if (activeMode === mode) {
    // Toggle off
    body.classList.remove('select-mode-' + mode);
    document.getElementById(btnMap[mode]).classList.remove('active-' + mode);
    activeMode = null;
    clearSelection();
    return;
  }

  // Clear previous mode
  if (activeMode) {
    body.classList.remove('select-mode-' + activeMode);
    document.getElementById(btnMap[activeMode]).classList.remove('active-' + activeMode);
    clearSelection();
  }

  // Apply new mode
  activeMode = mode;
  body.classList.add('select-mode-' + mode);
  document.getElementById(btnMap[mode]).classList.add('active-' + mode);
}

// ── BULK BAR ──
function updateBulkBar() {
  const checked = [...document.querySelectorAll('.card-checkbox:checked')];
  const bar = document.getElementById('bulkBar');
  if (checked.length === 0) { bar.style.display = 'none'; return; }
  bar.style.display = 'flex';
  document.getElementById('bulkCount').textContent = checked.length + ' selected';

  document.getElementById('bulkRateBtn').style.display    = activeMode === 'rate'        ? '' : 'none';
  document.getElementById('bulkReschedBtn').style.display = activeMode === 'reschedule'  ? '' : 'none';
  document.getElementById('bulkCancelBtn').style.display  = activeMode === 'cancel'      ? '' : 'none';
}

function clearSelection() {
  document.querySelectorAll('.card-checkbox:checked').forEach(c => c.checked = false);
  document.getElementById('bulkBar').style.display = 'none';
}

function bulkAction(type) {
  const checked = [...document.querySelectorAll('.card-checkbox:checked')];

  if (type === 'rate') {
    const ids = checked.map(c => c.dataset.id);
    if (ids.length === 1) {
        window.location.href = 'booking_detail.php?id=' + ids[0] + '#rate';
    } else {
        const rateList = checked.map(c => `
            <div style="padding:12px 14px;background:rgba(221,211,255,.3);border-radius:12px;
                 margin-bottom:8px;border:1px solid rgba(167,123,232,.15);">
                <div style="font-size:13px;font-weight:900;color:#342635;margin-bottom:10px;">
                    <i class="bi bi-star-fill" style="color:#FFB800;"></i>
                    ${c.dataset.lang} with ${c.dataset.tutor}
                </div>
                <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                    <div style="flex:1;min-width:120px;padding:8px 12px;border-radius:10px;
                         background:rgba(255,241,246,.8);border:1px solid rgba(242,138,178,.2);">
                        <div style="font-size:10px;font-weight:900;color:#C94F86;
                             text-transform:uppercase;letter-spacing:.5px;margin-bottom:3px;">
                            Session
                        </div>
                        <div style="font-size:12px;font-weight:700;color:#342635;">
                            <i class="bi bi-calendar3"></i> ${c.dataset.date}
                        </div>
                        <div style="font-size:12px;font-weight:700;color:#342635;margin-top:2px;">
                            <i class="bi bi-clock"></i> ${c.dataset.time}
                        </div>
                    </div>
                    <div style="font-size:18px;color:#FFB800;font-weight:900;">→</div>
                    <div style="flex:1;min-width:120px;padding:8px 12px;border-radius:10px;
                         background:rgba(255,184,0,.1);border:1px dashed rgba(255,184,0,.4);">
                        <div style="font-size:10px;font-weight:900;color:#A06B00;
                             text-transform:uppercase;letter-spacing:.5px;margin-bottom:3px;">
                            Your Rating
                        </div>
                        <div style="font-size:16px;color:#FFB800;letter-spacing:2px;">
                            ☆ ☆ ☆ ☆ ☆
                        </div>
                        <div style="font-size:11px;color:#7B6178;margin-top:2px;">
                            on next page
                        </div>
                    </div>
                </div>
            </div>
        `).join('');

         document.getElementById('ratingModalContent').innerHTML = `
            <p style="margin:0 0 10px;font-size:13px;color:var(--muted);">
                Rating <strong>${ids.length} completed session${ids.length > 1 ? 's' : ''}</strong> 
                <br> You'll rate each one in order
            </p>
            ${rateList}
        `;
        
        // When "Start Rating" is clicked, redirect to rate_chain.php with the IDs
        document.getElementById('ratingModalActions').innerHTML = `
            <button onclick="closeRatingModal()" style="padding:10px 20px;border-radius:999px;
             border:1px solid rgba(46,42,59,.12);background:none;color:#7A5570;
             font-size:13px;font-weight:900;cursor:pointer;">Cancel</button>
            <a href="rate_chain.php?ids=${ids.join(',')}" style="padding:10px 20px;border-radius:999px;border:none;
             background:linear-gradient(135deg,#E75A9B,#F28AB2);color:white;
             font-size:13px;font-weight:900;text-decoration:none;display:inline-flex;
             align-items:center;gap:6px;">
             <i class="bi bi-star-fill"></i> Start Rating
            </a>`;
        document.getElementById('ratingModal').classList.add('show');
    }
    return;
} else if (type === 'reschedule') {
    const ids = checked.map(c => c.dataset.id);
    if (ids.length === 1) {
        window.location.href = 'reschedule_booking.php?id=' + ids[0];
    } else {
        const firstUrl = 'reschedule_booking.php?id=' + ids[0] + '&next=' + ids.slice(1).join(',');
        
        const sessionList = checked.map(c => `
            <div style="padding:12px 14px;background:rgba(255,241,246,.7);border-radius:12px;
                 margin-bottom:8px;border:1px solid rgba(242,138,178,.15);">
                <div style="font-size:13px;font-weight:900;color:#342635;margin-bottom:10px;">
                    <i class="bi bi-translate" style="color:var(--hot-pink);"></i> 
                    ${c.dataset.lang} with ${c.dataset.tutor}
                </div>
                <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                    <div style="flex:1;min-width:120px;padding:8px 12px;border-radius:10px;
                         background:rgba(255,200,200,.4);border:1px solid rgba(163,95,63,.2);">
                        <div style="font-size:10px;font-weight:900;color:#A35F3F;
                             text-transform:uppercase;letter-spacing:.5px;margin-bottom:3px;">
                            Current
                        </div>
                        <div style="font-size:12px;font-weight:700;color:#342635;">
                            <i class="bi bi-calendar3"></i> ${c.dataset.date}
                        </div>
                        <div style="font-size:12px;font-weight:700;color:#342635;margin-top:2px;">
                            <i class="bi bi-clock"></i> ${c.dataset.time}
                        </div>
                    </div>
                    <div style="font-size:18px;color:var(--hot-pink);font-weight:900;">→</div>
                    <div style="flex:1;min-width:120px;padding:8px 12px;border-radius:10px;
                         background:rgba(215,238,219,.5);border:1px solid rgba(45,106,66,.2);
                         border-style:dashed;">
                        <div style="font-size:10px;font-weight:900;color:#3D7047;
                             text-transform:uppercase;letter-spacing:.5px;margin-bottom:3px;">
                            New
                        </div>
                        <div style="font-size:12px;font-weight:700;color:#3D7047;">
                            <i class="bi bi-calendar-plus"></i> To be selected
                        </div>
                        <div style="font-size:11px;color:#7B6178;margin-top:2px;">
                            on next page
                        </div>
                    </div>
                </div>
            </div>
        `).join('');

        document.getElementById('rescheduleModalContent').innerHTML = `
            <p style="margin:0 0 10px;font-size:13px;color:var(--muted);">
                Rescheduling <strong>${ids.length} booking${ids.length > 1 ? 's' : ''}</strong> 
                <br> You'll pick a new date and time for each one
            </p>
            ${sessionList}
        `;
        document.getElementById('rescheduleModalActions').innerHTML = `
            <button onclick="closeRescheduleModal()" style="padding:10px 20px;border-radius:999px;
             border:1px solid rgba(46,42,59,.12);background:none;color:#7A5570;
             font-size:13px;font-weight:900;cursor:pointer;">Cancel</button>
            <a href="${firstUrl}" style="padding:10px 20px;border-radius:999px;border:none;
             background:linear-gradient(135deg,#E75A9B,#F28AB2);color:white;
             font-size:13px;font-weight:900;text-decoration:none;display:inline-flex;
             align-items:center;gap:6px;">
             <i class="bi bi-calendar-plus"></i> Start Rescheduling
            </a>`;
        document.getElementById('rescheduleModal').classList.add('show');
    }

  } else if (type === 'cancel') {
    const ids = checked.map(c => c.dataset.id);
    if (ids.length === 1) {
        confirmCancel(ids[0], checked[0].dataset.status);
    } else {
        // Set the booking IDs as comma-separated in the hidden field
        document.getElementById('cancel_booking_id').value = ids.join(',');
        
        // Change modal title to indicate multiple bookings
        const modalTitle = document.querySelector('#cancelModal h3');
        modalTitle.innerHTML = '<i class="bi bi-exclamation-triangle" style="color: #dc2626;"></i> Cancel ' + ids.length + ' Bookings?';
        
        // Store original title to restore later
        const originalTitle = '<i class="bi bi-exclamation-triangle" style="color: #dc2626;"></i> Cancel Booking';
        
        // Add a data attribute to indicate bulk cancel
        document.getElementById('cancelForm').setAttribute('data-bulk', 'true');
        
        // Show the modal
        document.getElementById('cancelModal').classList.add('show');
        
        // Restore title when modal closes
        const restoreTitle = function() {
            document.querySelector('#cancelModal h3').innerHTML = originalTitle;
            document.getElementById('cancelForm').removeAttribute('data-bulk');
            document.getElementById('cancelModal').removeEventListener('hidden', restoreTitle);
        };
        document.getElementById('cancelModal').addEventListener('hidden', restoreTitle);
    }
}
}

document.addEventListener('change', e => {
  if (e.target.classList.contains('card-checkbox')) updateBulkBar();
});
</script>
<?php include 'nav_search_modal.php'; ?>
<script src="../js/search_modal.js"></script>
<script>
function closeRatingModal() {
    document.getElementById('ratingModal').classList.remove('show');
}

function closeCancelModal() {
    document.getElementById('cancelModal').classList.remove('show');
    document.getElementById('cancelForm').reset();
    document.getElementById('otherReasonText').style.display = 'none';
}
function closeRescheduleModal() {
    document.getElementById('rescheduleModal').classList.remove('show');
}

function cancelRescheduleRequest(bookingId) {
    if (confirm('Cancel your reschedule request? The booking will go back to confirmed status at the original date/time.')) {
        var form = document.createElement('form');
        form.method = 'POST';
        form.action = 'cancel_reschedule_request.php';
        
        var input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'booking_id';
        input.value = bookingId;
        
        form.appendChild(input);
        document.body.appendChild(form);
        form.submit();
    }
}

function showReportIssue(bookingId) {
    const modal = document.createElement('div');
    modal.id = 'reportModal';
    modal.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:1000;display:flex;align-items:center;justify-content:center;';
    modal.innerHTML = `
        <div style="background:white;border-radius:20px;padding:25px;max-width:500px;width:90%;">
            <h3 style="margin-bottom:15px;">Report Issue</h3>
            <form method="POST" action="report_issue.php">
                <input type="hidden" name="booking_id" value="${bookingId}">
                <div style="margin-bottom:15px;">
                    <label style="display:block;margin-bottom:5px;font-weight:bold;">Issue Type</label>
                    <select name="issue_type" required style="width:100%;padding:10px;border-radius:10px;border:1px solid #ddd;">
                        <option value="">Select issue type</option>
                        <option value="tutor_no_show">Tutor didn't attend</option>
                        <option value="technical_issues">Technical issues</option>
                        <option value="wrong_materials">Wrong materials provided</option>
                        <option value="other">Other</option>
                    </select>
                </div>
                <div style="margin-bottom:15px;">
                    <label style="display:block;margin-bottom:5px;font-weight:bold;">Description</label>
                    <textarea name="message" rows="4" required style="width:100%;padding:10px;border-radius:10px;border:1px solid #ddd;" placeholder="Please describe your issue..."></textarea>
                </div>
                <div style="display:flex;gap:10px;">
                    <button type="submit" style="background:#E75A9B;color:white;padding:10px 20px;border:none;border-radius:30px;cursor:pointer;">Submit Report</button>
                    <button type="button" onclick="closeReportModal()" style="background:#ccc;color:#333;padding:10px 20px;border:none;border-radius:30px;cursor:pointer;">Cancel</button>
                </div>
            </form>
        </div>
    `;
    document.body.appendChild(modal);
}

function closeReportModal() {
    const modal = document.getElementById('reportModal');
    if (modal) modal.remove();
}
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