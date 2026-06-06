<?php
session_start();
include 'config.php';
$assetBase = '../assets/img';
$ratingQueue = isset($_GET['queue']) ? true : false;
$nextBookingId = null;

if ($ratingQueue && isset($_SESSION['rating_queue']) && !empty($_SESSION['rating_queue'])) {
    $queue = $_SESSION['rating_queue'];
    $currentIndex = $_SESSION['rating_index'] ?? 0;
}

if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit(); }
$userID = $_SESSION['user_id'];
$bookingID = intval($_GET['id'] ?? 0);
if (!$bookingID) { header("Location: booking_status.php"); exit(); }
$stmt = $conn->prepare("
    SELECT b.*, 
           u.fullname AS tutor_name, 
           u.profile_pic AS tutor_pic, 
           u.email AS tutor_email,
           u.phone AS tutor_phone,
           tp.rate, tp.bio, tp.experience,
           GROUP_CONCAT(DISTINCT tl.language) AS tutor_languages,
           -- Get the LATEST payment info for display (any status except rejected for display)
           (SELECT amount FROM payments WHERE booking_id = b.id AND status != 'rejected' ORDER BY created_at DESC LIMIT 1) AS payment_amount,
           (SELECT payment_method FROM payments WHERE booking_id = b.id AND status != 'rejected' ORDER BY created_at DESC LIMIT 1) AS payment_method,
           (SELECT status FROM payments WHERE booking_id = b.id AND status != 'rejected' ORDER BY created_at DESC LIMIT 1) AS payment_status,
           (SELECT receipt_number FROM payments WHERE booking_id = b.id AND status != 'rejected' ORDER BY created_at DESC LIMIT 1) AS receipt_number,
           (SELECT receipt_url FROM payments WHERE booking_id = b.id AND status != 'rejected' ORDER BY created_at DESC LIMIT 1) AS receipt_url,
           (SELECT created_at FROM payments WHERE booking_id = b.id AND status != 'rejected' ORDER BY created_at DESC LIMIT 1) AS paid_at,
           -- Calculate TOTAL PAID amount from ALL payments (including rejected ones, since money was still deducted)
           (SELECT COALESCE(SUM(amount), 0) FROM payments WHERE booking_id = b.id) AS total_paid_amount,
           r.id AS rated, 
           r.rating AS my_rating, 
           r.comment AS my_comment,
           r.is_anonymous AS my_anonymous
    FROM bookings b
    JOIN users u ON b.tutor_id = u.id
    JOIN tutor_profiles tp ON b.tutor_id = tp.user_id
    LEFT JOIN tutor_languages tl ON b.tutor_id = tl.user_id
    LEFT JOIN ratings r ON r.booking_id = b.id AND r.student_id = ?
    WHERE b.id = ? AND b.student_id = ?
    GROUP BY b.id
");
$stmt->bind_param("iii", $userID, $bookingID, $userID);
$stmt->execute();
$b = $stmt->get_result()->fetch_assoc();
// Calculate total paid from ALL payments (regardless of status)
$totalPaidStmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) as total FROM payments WHERE booking_id = ?");
$totalPaidStmt->bind_param("i", $bookingID);
$totalPaidStmt->execute();
$totalPaidResult = $totalPaidStmt->get_result()->fetch_assoc();
$b['total_paid_amount'] = $totalPaidResult['total'] ?? 0;
$totalPaidStmt->close();

// Get ALL payments and calculate properly
$allPaymentsStmt = $conn->prepare("SELECT id, amount, actual_paid_amount, payment_method, status, created_at FROM payments WHERE booking_id = ? ORDER BY created_at ASC");
$allPaymentsStmt->bind_param("i", $bookingID);
$allPaymentsStmt->execute();
$paymentResults = $allPaymentsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$allPaymentsStmt->close();

// Build payment list - handle the case where actual_paid_amount represents a separate payment
$allPaymentsForDisplay = [];
$totalPaidSum = 0;

foreach ($paymentResults as $payment) {
    // If actual_paid_amount exists and is different from amount, treat it as a separate payment
    if (!empty($payment['actual_paid_amount']) && $payment['actual_paid_amount'] != $payment['amount']) {
        // Add the original payment (actual_paid_amount)
        $allPaymentsForDisplay[] = [
            'amount' => $payment['actual_paid_amount'],
            'payment_method' => 'online_banking', // or original method
            'status' => 'rejected',
            'created_at' => $payment['created_at'],
            'is_original' => true
        ];
        $totalPaidSum += $payment['actual_paid_amount'];
    }
    
    // Add the current payment
    $allPaymentsForDisplay[] = [
        'amount' => $payment['amount'],
        'payment_method' => $payment['payment_method'],
        'status' => $payment['status'],
        'created_at' => $payment['created_at'],
        'is_original' => false
    ];
    $totalPaidSum += $payment['amount'];
}

// Remove duplicates if needed (if both original and current are the same)
$uniquePayments = [];
foreach ($allPaymentsForDisplay as $payment) {
    $key = $payment['amount'] . '_' . $payment['created_at'];
    if (!isset($uniquePayments[$key])) {
        $uniquePayments[$key] = $payment;
    }
}
$allPaymentsForDisplay = array_values($uniquePayments);

// Update total paid amount
$b['total_paid_amount'] = $totalPaidSum;

// Auto-end stuck meeting logs for this booking
$autoEndStmt = $conn->prepare("
    UPDATE meeting_logs ml
    JOIN bookings b ON ml.booking_id = b.id
    SET ml.leave_time = DATE_ADD(CONCAT(b.booking_date, ' ', b.booking_time), INTERVAL 2 HOUR),
        ml.duration_minutes = TIMESTAMPDIFF(MINUTE, ml.join_time, DATE_ADD(CONCAT(b.booking_date, ' ', b.booking_time), INTERVAL 2 HOUR))
    WHERE ml.booking_id = ? 
    AND ml.leave_time IS NULL
    AND CONCAT(b.booking_date, ' ', b.booking_time) < DATE_SUB(NOW(), INTERVAL 2 HOUR)
");
$autoEndStmt->bind_param("i", $bookingID);
$autoEndStmt->execute();

if (!$b) { header("Location: booking_status.php"); exit(); }

$user = $conn->query("SELECT * FROM users WHERE id = $userID")->fetch_assoc();
$displayName = $user['fullname'];
$profilePic  = !empty($user['profile_pic']) ? '../uploads/profiles/' . $user['profile_pic'] : $assetBase . '/profile-student.png';
$tutorPic    = !empty($b['tutor_pic']) ? '../uploads/profiles/' . $b['tutor_pic'] : $assetBase . '/profile-tutor.png';
$payStatus   = $b['payment_status'] ?? null;
$payMethod   = $b['payment_method'] ?? '';
$bookStatus  = $b['status'];
$paymentState = 'unpaid';

if (!empty($payStatus)) {

    if ($payStatus === 'failed') {
        $paymentState = 'failed';

    } elseif (in_array($payStatus, ['verified', 'completed', 'approved', 'paid'])) {
    $paymentState = 'paid';

    } elseif (in_array($payStatus, ['pending'])) {
        $paymentState = 'processing';
    }

} else {

    // fallback if no payment row yet
    if ($payMethod === 'cash') {
        $paymentState = 'cash';
    }
}

$displayState = $bookStatus;

// Check if session has already passed (cannot reschedule)
$session_datetime = strtotime($b['booking_date'] . ' ' . $b['booking_time']);
$is_future_session = $session_datetime > time();
// Check if there's an active dispute that needs admin
$disputeStmt = $conn->prepare("
    SELECT d.*, 
           CASE 
               WHEN d.issue_type IN ('tutor_no_show', 'student_no_show', 'harassment', 'fraud') THEN 'serious'
               ELSE 'minor'
           END as severity
    FROM disputes d
    WHERE d.booking_id = ? AND d.status = 'pending'
    ORDER BY d.created_at DESC
    LIMIT 1
");
$disputeStmt->bind_param("i", $bookingID);
$disputeStmt->execute();
$activeDispute = $disputeStmt->get_result()->fetch_assoc();

$is_serious_dispute = ($activeDispute && $activeDispute['severity'] === 'serious');
$is_minor_dispute = ($activeDispute && $activeDispute['severity'] === 'minor');

// For minor disputes, show different message
if ($is_minor_dispute && $bookStatus !== 'disputed') {
    $bookStatus = 'confirmed'; // Keep as confirmed
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_rating']) && $b['status'] === 'completed' && !$b['rated']) {
    $rating      = intval($_POST['rating'] ?? 0);
    $comment     = trim($_POST['comment'] ?? '');
    $isAnonymous = isset($_POST['is_anonymous']) ? 1 : 0;
    if ($rating >= 1 && $rating <= 5) {
        $stmt = $conn->prepare("INSERT INTO ratings (booking_id, student_id, tutor_id, rating, comment, is_anonymous, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
        $stmt->bind_param("iiiisi", $bookingID, $userID, $b['tutor_id'], $rating, $comment, $isAnonymous);
        $stmt->execute();
        $stmt->close();
        
        // Check if there's a rating queue
        if (isset($_SESSION['rating_queue']) && !empty($_SESSION['rating_queue'])) {
            $queue = $_SESSION['rating_queue'];
            $currentIndex = ($_SESSION['rating_index'] ?? 0) + 1;
            
            if ($currentIndex < count($queue)) {
                $_SESSION['rating_index'] = $currentIndex;
                $nextId = $queue[$currentIndex];
                header("Location: booking_detail.php?id=" . $nextId . "#rate&queue=1");
                exit();
            } else {
                unset($_SESSION['rating_queue']);
                unset($_SESSION['rating_index']);
                header("Location: booking_status.php?rated_all=1");
                exit();
            }
        }
        
        header("Location: booking_detail.php?id=$bookingID&rated=1");
        exit();
    }
}

function e($v){ return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }
function statusCfg($s) {
    $map = [
        'pending'    => ['label'=>'Pending',    'icon'=>'bi-hourglass-split',       'bg'=>'rgba(255,217,199,.74)', 'color'=>'#A35F3F', 'desc'=>'Waiting for tutor to approve your booking.'],
        'accepted'   => ['label'=>'Accepted',   'icon'=>'bi-check-circle',          'bg'=>'rgba(216,236,255,.78)', 'color'=>'#1A5FA8', 'desc'=>'Tutor accepted! Please make payment to confirm your session.'],
        'confirmed'  => ['label'=>'Confirmed',  'icon'=>'bi-check-circle-fill',     'bg'=>'rgba(215,238,219,.78)', 'color'=>'#3D7047', 'desc'=>'Payment verified! Your session is confirmed.'],
        'rescheduled'=> ['label'=>'Rescheduled','icon'=>'bi-calendar-plus',         'bg'=>'rgba(255,241,200,.78)', 'color'=>'#A06B00', 'desc'=>'Your reschedule request is waiting for tutor approval.'],
        'completed'  => ['label'=>'Completed',  'icon'=>'bi-patch-check-fill',      'bg'=>'rgba(221,211,255,.78)', 'color'=>'#7648B8', 'desc'=>'Session completed. Don\'t forget to rate your tutor!'],
        'cancelled'  => ['label'=>'Cancelled',  'icon'=>'bi-x-circle-fill',         'bg'=>'rgba(255,200,200,.78)', 'color'=>'#C94F4F', 'desc'=>'This booking was cancelled.'],
        'disputed'   => ['label'=>'Disputed',   'icon'=>'bi-exclamation-triangle-fill', 'bg'=>'rgba(255,200,200,.78)', 'color'=>'#C94F4F', 'desc'=>'This session has been reported and is under admin review.'],
    ];
    return $map[$s] ?? $map['pending'];
}
$cfg = statusCfg($displayState);
$stateOrder = ['pending'=>0, 'accepted'=>1, 'confirmed'=>2, 'rescheduled'=>2, 'completed'=>3, 'disputed'=>4];
$currentOrder = $stateOrder[$displayState] ?? 0;
$steps = [
    ['label'=>'Requested', 'icon'=>'bi-send'],
    ['label'=>'Accepted',  'icon'=>'bi-check2'],
    ['label'=>'Confirmed', 'icon'=>'bi-patch-check'],
    ['label'=>'Completed', 'icon'=>'bi-trophy'],
];

$error_message = $_SESSION['error_message'] ?? '';
$success_message = $_SESSION['success_message'] ?? '';
unset($_SESSION['error_message']);
unset($_SESSION['success_message']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Booking Details · Kyoshi</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
  <style>
    /* [Keep all your existing CSS styles here - they are the same] */
    :root{
      --cream:#FFF1F6;--paper:rgba(255,255,255,.88);--ink:#342635;--muted:#7B6178;
      --pink:#F28AB2;--pink-dark:#C94F86;--hot-pink:#E75A9B;
      --shadow:0 18px 45px rgba(201,79,134,.16);--radius-xl:32px;--radius-lg:24px;
    }
    .success-toast {
    background: #28a745;
}

    /* Ensure dropdown appears above everything */
#profileDropdown {
    position: absolute;
    top: calc(100% + 10px);
    right: 0;
    background: white;
    border-radius: 16px;
    box-shadow: 0 18px 45px rgba(0,0,0,0.2);
    border: 1px solid rgba(242,138,178,.2);
    min-width: 200px;
    overflow: hidden;
    z-index: 10000 !important;
}

/* Make sure the profile button has proper positioning */
.nav-actions {
    position: relative;
}

.profile {
    position: relative;
    z-index: 10001;
}
    *{box-sizing:border-box}html{scroll-behavior:smooth}
    body{margin:0;min-height:100vh;font-family:"Segoe UI",Arial,sans-serif;color:var(--ink);
      background:linear-gradient(120deg,rgba(255,241,246,.74),rgba(255,203,220,.30)),
      url("<?= e($assetBase) ?>/background3.jpg") center/cover fixed no-repeat;}
    body::before{content:"";position:fixed;inset:0;pointer-events:none;z-index:-1;
      background:radial-gradient(circle at 7% 10%,rgba(231,90,155,.32),transparent 24%),
      radial-gradient(circle at 90% 8%,rgba(255,195,216,.42),transparent 26%)}
    a{text-decoration:none;color:inherit}button,input,select,textarea{font-family:inherit}
    .container{width:min(1440px,calc(100% - 40px));margin:0 auto}
    .inner{width:min(860px,100%);margin:0 auto}

    .topbar{position:sticky;top:0;z-index:50;background:rgba(255,241,246,.86);backdrop-filter:blur(20px);border-bottom:1px solid rgba(231,90,155,.18);box-shadow:0 10px 30px rgba(201,79,134,.10)}
    .nav{min-height:78px;display:grid;grid-template-columns:190px minmax(0,1fr) 360px;gap:16px;align-items:center;}
    .brand{display:flex;align-items:center;gap:10px}
    .brand img{width:44px;height:44px;object-fit:contain;border-radius:14px}
    .brand strong{display:block;font-size:18px;line-height:1.05}
    .brand span{display:block;margin-top:3px;font-size:11px;color:var(--muted);white-space:nowrap}
    .nav-links{display:flex;align-items:center;justify-content:center;gap:6px;border-radius:999px;padding:7px;overflow:auto;scrollbar-width:none;}
    .nav-links::-webkit-scrollbar{display:none}
    .nav-links a{flex:0 0 auto;padding:9px 12px;border-radius:999px;font-size:13px;font-weight:900;color:#6D4964;white-space:nowrap;transition:.18s ease}
    .nav-links a.active,.nav-links a:hover{background:linear-gradient(135deg,var(--hot-pink),var(--pink));color:#fff}
    .nav-actions{display:flex;align-items:center;justify-content:flex-end;gap:10px;min-width:0}
    .search{position:relative;flex:1 1 auto;min-width:0}
    .search i{position:absolute;left:14px;top:50%;transform:translateY(-50%);color:#91899F}
    .search input{width:100%;border:1px solid rgba(46,42,59,.10);background:rgba(255,255,255,.88);outline:none;border-radius:999px;padding:12px 14px 12px 38px;box-shadow:0 10px 26px rgba(201,79,134,.10)}
    .icon-btn{width:44px;height:44px;border-radius:16px;color:#7A4A68;position:relative;flex:0 0 auto;border:1px solid rgba(46,42,59,.08);background:rgba(255,255,255,.88);cursor:pointer;display:grid;place-items:center}
    .dot{position:absolute;top:10px;right:10px;width:8px;height:8px;border-radius:50%;background:#E17C91}
    .profile{display:flex;align-items:center;gap:9px;border-radius:999px;padding:6px 12px 6px 6px;font-weight:900;color:#7A3D65;border:1px solid rgba(46,42,59,.08);background:rgba(255,255,255,.88);cursor:pointer}
    .profile img{width:34px;height:34px;object-fit:cover;border-radius:50%}

    .page-wrap{padding:28px 0 60px}
    .back-link{display:inline-flex;align-items:center;gap:6px;color:var(--pink-dark);font-weight:900;font-size:13px;padding:9px 16px;border-radius:999px;background:rgba(255,255,255,.78);border:1px solid rgba(46,42,59,.08);transition:.18s ease;margin-bottom:20px;}
    .back-link:hover{transform:translateY(-1px)}

    .status-banner{border-radius:20px;padding:18px 22px;display:flex;align-items:flex-start;gap:14px;margin-bottom:16px;border:1px solid}
    .status-banner .s-icon{width:46px;height:46px;border-radius:14px;display:grid;place-items:center;font-size:22px;flex:0 0 auto;background:rgba(255,255,255,.55)}
    .status-banner strong{display:block;font-size:15px;font-weight:900;margin-bottom:4px}
    .status-banner p{margin:0;font-size:13px;line-height:1.5;opacity:.9}

    .progress-wrap{background:var(--paper);border:1px solid rgba(255,255,255,.55);box-shadow:0 6px 20px rgba(201,79,134,.08);border-radius:20px;padding:18px 20px;margin-bottom:16px;overflow-x:auto}
    .progress-steps{display:flex;align-items:center;min-width:400px}
    .p-step{flex:1;display:flex;flex-direction:column;align-items:center;position:relative}
    .p-step:not(:last-child)::after{content:"";position:absolute;top:15px;left:calc(50% + 15px);right:calc(-50% + 15px);height:2px;background:rgba(46,42,59,.10);z-index:0}
    .p-step.done::after{background:linear-gradient(90deg,var(--pink),rgba(242,138,178,.3))}
    .p-dot{width:30px;height:30px;border-radius:50%;border:2px solid rgba(46,42,59,.12);background:white;display:grid;place-items:center;font-size:11px;font-weight:900;color:#9080a0;margin-bottom:6px;position:relative;z-index:1;transition:.2s ease}
    .p-step.done .p-dot{background:linear-gradient(135deg,var(--hot-pink),var(--pink));color:white;border-color:var(--pink)}
    .p-step.active .p-dot{background:linear-gradient(135deg,var(--hot-pink),var(--pink));color:white;border-color:var(--pink);box-shadow:0 4px 12px rgba(231,90,155,.3)}
    .p-label{font-size:10px;font-weight:900;color:var(--muted);text-align:center;white-space:nowrap}
    .p-step.active .p-label{color:var(--hot-pink)}
    .p-step.done .p-label{color:var(--pink-dark)}

    .card{background:var(--paper);border:1px solid rgba(255,255,255,.55);box-shadow:var(--shadow);border-radius:var(--radius-xl);padding:24px;margin-bottom:16px}
    .card-title{font-size:15px;font-weight:900;margin:0 0 16px;display:flex;align-items:center;gap:8px;color:#342635}
    .card-title i{color:var(--hot-pink)}

    .tutor-row{display:flex;align-items:center;gap:16px}
    .tutor-row img{width:68px;height:68px;object-fit:cover;border-radius:18px;background:#eee;flex:0 0 auto}
    .tutor-row h3{margin:0 0 4px;font-size:17px}
    .tutor-row p{margin:0;color:var(--muted);font-size:13px}
    .view-profile{display:inline-flex;align-items:center;gap:5px;margin-top:8px;padding:6px 14px;border-radius:999px;background:rgba(231,90,155,.1);color:var(--hot-pink);font-size:12px;font-weight:900;border:1px solid rgba(231,90,155,.2)}

    .detail-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
    .detail-item{background:rgba(255,241,246,.5);border:1px solid rgba(242,138,178,.15);border-radius:14px;padding:14px}
    .detail-item .dlabel{font-size:11px;font-weight:900;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:5px}
    .detail-item .dval{font-size:14px;font-weight:900;color:var(--ink)}
    .tags-row{display:flex;flex-wrap:wrap;gap:5px;margin-top:4px}
    .tag{padding:3px 9px;border-radius:999px;font-size:11px;font-weight:900;background:rgba(221,244,230,.6);color:#2D6A42}

    .pay-box{border-radius:16px;padding:16px 20px;margin-bottom:12px}
    .pay-box.paid{background:rgba(215,238,219,.5);border:1px solid rgba(45,106,66,.2)}
    .pay-box.unpaid{background:rgba(255,217,199,.4);border:1px solid rgba(163,95,63,.2)}
    .pay-box.review{background:rgba(221,211,255,.4);border:1px solid rgba(118,72,184,.2)}
    .pay-box.cancelled{background:rgba(255,200,200,.4);border:1px solid rgba(201,79,134,.2)}
    .pay-row{display:flex;justify-content:space-between;align-items:center;font-size:13px;padding:7px 0;border-bottom:1px solid rgba(46,42,59,.06)}
    .pay-row:last-child{border-bottom:none}
    .pay-row .pl{color:var(--muted);font-weight:700}
    .pay-row .pv{font-weight:900}

    .cancel-info{border-radius:16px;padding:16px 20px;background:rgba(255,200,200,.4);border:1px solid rgba(201,79,134,.2)}
    .cancel-who{display:inline-flex;align-items:center;gap:6px;padding:5px 12px;border-radius:999px;font-size:12px;font-weight:900;margin-bottom:10px}
    .cancel-who.student{background:rgba(255,217,199,.7);color:#A35F3F}
    .cancel-who.tutor{background:rgba(221,211,255,.7);color:#7648B8}
    .cancel-who.admin{background:rgba(255,200,200,.7);color:#C94F4F}
    .cancel-reason{font-size:13px;color:#7A3030;font-weight:700;line-height:1.5}

    .action-bar{display:flex;gap:10px;flex-wrap:wrap;margin-top:14px}
    .btn-primary{padding:11px 22px;border-radius:999px;border:none;background:linear-gradient(135deg,var(--hot-pink),var(--pink));color:white;font-size:13px;font-weight:900;cursor:pointer;box-shadow:0 8px 20px rgba(231,90,155,.28);text-decoration:none;display:inline-flex;align-items:center;gap:7px;transition:.18s ease}
    .btn-primary:hover{transform:translateY(-1px)}
    .btn-secondary{padding:11px 22px;border-radius:999px;border:1px solid rgba(46,42,59,.12);background:white;color:#7A5570;font-size:13px;font-weight:900;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;gap:7px;transition:.18s ease}
    .btn-secondary:hover{transform:translateY(-1px)}
    .btn-danger{padding:11px 22px;border-radius:999px;border:1px solid rgba(201,79,134,.3);background:white;color:var(--pink-dark);font-size:13px;font-weight:900;cursor:pointer;display:inline-flex;align-items:center;gap:7px;transition:.18s ease}
    .btn-danger:hover{background:rgba(255,241,246,.8)}

    .star-row{display:flex;gap:8px;margin:12px 0}
    .star-btn{width:40px;height:40px;border-radius:12px;border:2px solid rgba(46,42,59,.10);background:white;font-size:20px;cursor:pointer;transition:.15s ease;display:grid;place-items:center}
    .star-btn.active{border-color:#FFB800;background:rgba(255,184,0,.1)}
    .star-display{display:flex;gap:4px}
    .star-display i{color:#FFB800;font-size:18px}

    .info-note{padding:12px 16px;border-radius:14px;background:rgba(221,211,255,.3);border:1px solid rgba(167,123,232,.2);font-size:13px;color:#6D4964;font-weight:700;line-height:1.5}

    .modal-overlay {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.6);
        z-index: 9999;
        display: none;
        align-items: center;
        justify-content: center;
    }

    .modal-overlay.active {
        display: flex;
    }

    .modal-box {
        background: white;
        border-radius: 24px;
        padding: 28px;
        width: 500px;
        max-width: 90%;
        max-height: 85vh;
        overflow-y: auto;
        box-shadow: 0 20px 40px rgba(0, 0, 0, 0.2);
        animation: modalFadeIn 0.3s ease;
    }

    @keyframes modalFadeIn {
        from {
            opacity: 0;
            transform: translateY(-30px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    .modal-box h3 {
        margin: 0 0 8px 0;
        color: #1d3156;
        font-size: 20px;
    }

    .modal-box p {
        margin: 0 0 20px 0;
        color: #64748b;
        font-size: 14px;
    }

    .modal-actions {
        display: flex;
        gap: 12px;
        justify-content: flex-end;
        margin-top: 20px;
        padding-top: 16px;
        border-top: 1px solid #eef2f7;
    }

    .toast{position:fixed;left:50%;bottom:28px;transform:translate(-50%,18px);opacity:0;pointer-events:none;z-index:99;background:#8E3F70;color:#fff;border-radius:999px;padding:12px 18px;font-size:13px;font-weight:900;transition:.2s ease}
    .toast.show{opacity:1;transform:translate(-50%,0)}

    @media(max-width:768px){
      .detail-grid{grid-template-columns:1fr}
      .nav{grid-template-columns:1fr auto}
      .nav-links{display:none}
    }

    .star-btn { font-size:24px; width:44px; height:44px; }
#starRow { gap:10px; margin:14px 0; }
.card-title { font-size:16px; }
.star-display i { font-size:20px; }

    .completed-grid{
  display:grid;
  grid-template-columns:1fr 1fr;
  gap:16px;
  align-items:start;
}

.error-toast {
    position: fixed;
    top: 20px;
    left: 50%;
    transform: translateX(-50%);
    background: #dc3545;
    color: white;
    padding: 12px 24px;
    border-radius: 999px;
    font-size: 13px;
    font-weight: 900;
    z-index: 9999;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    animation: slideDown 0.3s ease;
}

.success-toast {
    background: #28a745;
}

@keyframes slideDown {
    from {
        top: -50px;
        opacity: 0;
    }
    to {
        top: 20px;
        opacity: 1;
    }
}

@media(max-width:768px){
  .completed-grid{
    grid-template-columns:1fr;
  }
}

/* Force dropdown to be on top and visible */
.nav-actions {
    position: relative;
    z-index: 99999;
}

#profileDropdown {
    display: none;
    position: absolute !important;
    top: calc(100% + 10px) !important;
    right: 0 !important;
    background: white !important;
    border-radius: 16px !important;
    box-shadow: 0 18px 45px rgba(0,0,0,0.2) !important;
    border: 1px solid rgba(242,138,178,.2) !important;
    min-width: 200px !important;
    overflow: hidden !important;
    z-index: 999999 !important;
}

#profileDropdown a {
    display: flex !important;
    align-items: center !important;
    gap: 10px !important;
    padding: 12px 16px !important;
    text-decoration: none !important;
    color: #342635 !important;
    font-size: 14px !important;
    font-weight: 700 !important;
    background: white !important;
}

#profileDropdown a:hover {
    background: #FFF1F6 !important;
}

#profileDropdown hr {
    margin: 4px 0 !important;
    border-color: rgba(242,138,178,.2) !important;
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
          <a  href="find_language.php">Find Language</a>
          <a class="active" href="booking_status.php">My Bookings</a>
          <a href="my_payments.php">My Payments</a>
          <a href="my_materials.php">My Materials</a>
          <a href="my_assignments.php">My Assignments</a>
        </div>
        <div class="nav-actions" style="display:flex;align-items:center;justify-content:flex-end;gap:10px;margin-left:auto;">
          <div style="position:relative;">
            <button class="profile" onclick="toggleDropdown(event)" id="profileBtn">
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
<?php if ($error_message): ?>
<div class="error-toast" id="errorToast">
    <i class="bi bi-exclamation-triangle-fill"></i> 
    <?= htmlspecialchars($error_message) ?>
</div>
<script>
    setTimeout(() => {
        const toast = document.getElementById('errorToast');
        if(toast) toast.style.display = 'none';
    }, 5000);
</script>
<?php endif; ?>

<?php if ($success_message): ?>
<div class="error-toast success-toast" id="successToast">
    <i class="bi bi-check-circle-fill"></i> 
    <?= htmlspecialchars($success_message) ?>
</div>
<script>
    setTimeout(() => {
        const toast = document.getElementById('successToast');
        if(toast) toast.style.display = 'none';
    }, 5000);
</script>
<?php endif; ?>
<?php 
// Check if session has ended and needs confirmation
$class_time = strtotime($b['booking_date'] . ' ' . $b['booking_time']);
$current_time = time();
$is_past_class = $class_time < $current_time;
$is_confirmed = ($bookStatus === 'confirmed');
$is_completed = ($bookStatus === 'completed');
$hours_passed = round(($current_time - $class_time) / 3600, 1);
$role = $_SESSION['role']; // 'student'

// Get completion status from session_completion table
$completionStmt = $conn->prepare("
    SELECT tutor_confirmed, student_confirmed FROM session_completion 
    WHERE booking_id = ?
");
$completionStmt->bind_param("i", $bookingID);
$completionStmt->execute();
$completion = $completionStmt->get_result()->fetch_assoc();

$tutor_confirmed = $completion['tutor_confirmed'] ?? false;
$student_confirmed = $completion['student_confirmed'] ?? false;
$both_confirmed = ($tutor_confirmed && $student_confirmed);
?>
<div class="container">
  <div class="page-wrap">
    <a href="booking_status.php" class="back-link"><i class="bi bi-arrow-left"></i> Back to My Bookings</a>

<!-- Show resolved dispute message -->
<?php if (isset($_GET['resolved']) && $_GET['resolved'] == 1): ?>
<div class="status-banner" style="background:#d4edda; border-left:4px solid #28a745; margin-bottom:16px;">
    <div class="s-icon" style="color:#28a745"><i class="bi bi-check-circle-fill"></i></div>
    <div style="flex:1;">
        <strong style="color:#28a745">Issue Resolved ✓</strong>
        <p style="margin:0;">The tutor has resolved the issue. You can now continue with your learning journey.</p>
    </div>
</div>
<?php endif; ?>

<?php 
// Show special banner for minor disputes - EVEN IF BOOKING IS NOT DISPUTED
if ($is_minor_dispute && $activeDispute): 
    $issue_labels = [
        'tutor_no_show' => 'Tutor Did Not Attend',
        'student_no_show' => 'Student Did Not Attend',
        'wrong_materials' => 'Wrong Materials Provided',
        'other' => 'Other Issue'
    ];
    $issueLabel = $issue_labels[$activeDispute['issue_type']] ?? ucfirst(str_replace('_', ' ', $activeDispute['issue_type']));
    
    // Different guidance based on issue type
    $guidance = '';
    if ($activeDispute['issue_type'] === 'wrong_materials') {
        $guidance = 'The tutor will upload the correct materials. Please check the Learning Materials section.';
    } elseif ($activeDispute['issue_type'] === 'technical_issues') {
        $guidance = 'The tutor will help you resolve the technical issue. Please message them to discuss.';
    } elseif ($activeDispute['issue_type'] === 'other') {
        $guidance = 'The tutor has been notified and will contact you to resolve this issue.';
    } else {
        $guidance = 'The tutor has been notified. Please discuss and resolve this issue together.';
    }
?>
<div class="status-banner" style="background:rgba(255,241,200,.78);border:1px solid #f59e0b33;align-items:center; margin-bottom:16px;">
    <div class="s-icon" style="color:#f59e0b"><i class="bi bi-chat-dots"></i></div>
    <div style="flex:1;">
        <strong style="color:#f59e0b">Issue Reported: <?= $issueLabel ?></strong>
        <p style="margin:0;"><?= $guidance ?></p>
        <p style="margin:5px 0 0; font-size:12px; color:#856404;">
            <i class="bi bi-clock-history"></i> If not resolved within 48 hours, it will be auto-escalated to admin for review.
        </p>
    </div>
    <div style="margin-left: auto;">
        <button onclick="contactTutor(<?= $b['tutor_id'] ?>, '<?= e($b['tutor_name']) ?>', '<?= e($b['tutor_phone']) ?>', '<?= e($displayName) ?>', <?= $bookingID ?>, '<?= e($b['language']) ?>', '<?= $activeDispute['issue_type'] ?>')" 
                style="background:#25D366;color:white;padding:8px 20px;border:none;border-radius:30px;cursor:pointer;font-size:13px;font-weight:900;display:inline-flex;align-items:center;gap:8px;">
            <i class="bi bi-whatsapp"></i> Message Tutor
        </button>
    </div>
</div>
<?php elseif ($bookStatus === 'disputed' && $is_serious_dispute): ?>
<div class="status-banner" style="background:rgba(255,200,200,.78);border:1px solid #dc354533;align-items:center; margin-bottom:16px;">
    <div class="s-icon" style="color:#dc3545"><i class="bi bi-exclamation-triangle-fill"></i></div>
    <div style="flex:1;">
        <strong style="color:#dc3545">Serious Issue - Under Admin Review</strong>
        <p style="margin:0;">Your report has been escalated to admin. This involves a no-show or serious matter.</p>
        <p style="margin:5px 0 0; font-size:12px;">Our team will review within 2-3 business days. Payment is on hold pending review.</p>
    </div>
</div>
<?php endif; ?>

<!-- EXISTING STATUS BANNER STARTS HERE -->
<div class="status-banner" style="background:<?= $cfg['bg'] ?>;border:1px solid <?= $cfg['color'] ?>33;align-items:center;">
    <div class="s-icon" style="color:<?= $cfg['color'] ?>"><i class="bi <?= $cfg['icon'] ?>"></i></div>
    <div style="flex:1;">
        <strong style="color:<?= $cfg['color'] ?>"><?= $cfg['label'] ?></strong>
        <p style="color:<?= $cfg['color'] ?>;margin:0;">
            <?php 
            if ($bookStatus !== 'disputed' && $is_past_class && $is_confirmed && !$is_completed && !$student_confirmed){
                echo '⚠️ Session has ended. Please confirm your attendance.';
            } elseif ($is_completed) {
                echo '✨ Session completed. Thank you for attending!';
            } elseif ($is_confirmed) {
                echo '✨ Payment verified! Your session is confirmed.';
            } else {
                echo $cfg['desc'];
            }
            ?>
        </p>
        <?php if ($is_completed && ($b['auto_completed'] ?? false)): ?>
        <small style="color:<?= $cfg['color'] ?>; opacity:0.8;">(Auto-completed after 24 hours)</small>
        <?php endif; ?>
    </div>

    <!-- Attendance Confirmation Buttons -->
    <?php if ($is_past_class && $is_confirmed && !$is_completed && !$student_confirmed): ?>
    <div style="display:flex;gap:8px;flex-shrink:0; flex-wrap:wrap;">
        <form method="POST" action="confirm_session.php" style="margin:0;">
            <input type="hidden" name="booking_id" value="<?= $bookingID ?>">
            <input type="hidden" name="action" value="confirm">
            <button type="submit" style="background:linear-gradient(135deg,#28a745,#20c997);color:white;padding:10px 18px;border:none;border-radius:999px;cursor:pointer;font-weight:900;font-size:13px;white-space:nowrap;display:inline-flex;align-items:center;gap:6px;">
                <i class="bi bi-check-lg"></i> I Attended
            </button>
        </form>
        <form method="POST" action="confirm_session.php" style="margin:0;">
            <input type="hidden" name="booking_id" value="<?= $bookingID ?>">
            <input type="hidden" name="action" value="no_show">
            <button type="submit" style="background:#f59e0b;color:white;padding:10px 18px;border:none;border-radius:999px;cursor:pointer;font-weight:900;font-size:13px;white-space:nowrap;display:inline-flex;align-items:center;gap:6px;">
                <i class="bi bi-x-circle"></i> I Did Not Attend
            </button>
        </form>
    </div>
    <?php elseif ($is_past_class && $is_confirmed && !$is_completed && $student_confirmed): ?>
    <div style="display:flex;gap:8px;flex-shrink:0;">
        <div style="background:#d4edda;padding:10px 18px;border-radius:999px;color:#155724;">
            <i class="bi bi-check-circle-fill"></i> You confirmed attendance. Waiting for tutor...
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Report Issue Button - Shows for confirmed sessions AND auto-completed sessions (within 7 days) -->
    <?php 
    $show_report = false;
    
    if ($is_confirmed && !$is_completed) {
        // Normal confirmed session - always show report button
        $show_report = true;
    } elseif ($is_completed && ($b['auto_completed'] ?? false)) {
        // Auto-completed session - show report button for 7 days
        $days_since_complete = (time() - strtotime($b['completed_at'])) / 86400;
        if ($days_since_complete <= 7) {
            $show_report = true;
        }
    }
    
    if ($show_report):
    ?>
    <div style="display:flex;gap:8px;flex-shrink:0; margin-left: 10px;">
        <button onclick="showReportIssue(<?= $bookingID ?>)" style="background:#dc3545;color:white;padding:10px 18px;border:none;border-radius:999px;cursor:pointer;font-weight:900;font-size:13px;white-space:nowrap;display:inline-flex;align-items:center;gap:6px;">
            <i class="bi bi-exclamation-triangle"></i> Report Issue
        </button>
    </div>
    <?php endif; ?>
</div>

<!-- ========== END COMPLETION SECTION ========== -->

    <?php if ($bookStatus !== 'cancelled'): ?>
    <div class="progress-wrap">
      <div class="progress-steps">
        <?php foreach ($steps as $i => $step): ?>
          <div class="p-step <?= $i < $currentOrder ? 'done' : ($i === $currentOrder ? 'active' : '') ?>">
            <div class="p-dot"><?= $i < $currentOrder ? '✓' : ($i + 1) ?></div>
            <div class="p-label"><?= $step['label'] ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <div class="card">
  <div class="card-title"><i class="bi bi-calendar-check"></i> Booking Details</div>
  
<div style="display:flex;gap:24px;align-items:flex-start;">

  <!-- LEFT: Tutor -->
  <div style="flex:0 0 300px;display:flex;flex-direction:column;">
    <p style="margin:0 0 12px;font-size:11px;font-weight:900;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;">Tutor</p>
    <div style="background:rgba(255,241,246,.5);border:1px solid rgba(242,138,178,.15);border-radius:16px;padding:16px;height:100%;">
      <div style="display:flex;align-items:center;gap:12px;margin-bottom:14px;">
        <img src="<?= e($tutorPic) ?>" style="width:60px;height:60px;object-fit:cover;border-radius:14px;background:#eee;flex-shrink:0;">
        <div style="margin-bottom:28px">
          <strong style="display:block;font-size:15px;margin-bottom:3px;"><?= e($b['tutor_name']) ?></strong>
          <span style="display:block;font-size:12px;color:var(--muted);"><?= e($b['experience']) ?> yrs exp · RM <?= e($b['rate']) ?>/hr</span>
          <span style="display:block;font-size:12px;color:var(--muted);margin-top:2px;"><?= e($b['tutor_languages']) ?></span>
        </div>
      </div>
      <a href="tutor_profile.php?id=<?= $b['tutor_id'] ?>"  class="view-profile" style="margin-top:auto;">
        <i class="bi bi-arrow-up-right"></i> View Profile
      </a>
    </div>
  </div>

  <!-- RIGHT: Session -->
  <div style="flex:1;min-width:0;">
    <p style="margin:0 0 12px;font-size:11px;font-weight:900;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;">Session</p>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
        
        <div class="detail-item">
          <div class="dlabel">Language</div>
          <div class="dval"><?= e($b['language']) ?></div>
        </div>

        <div class="detail-item">
          <div class="dlabel">Date & Time</div>
          <div class="dval"><?= date('D, d M Y', strtotime($b['booking_date'])) ?></div>
          <div style="font-size:13px;color:var(--muted);font-weight:700;margin-top:2px;"><?= date('g:i A', strtotime($b['booking_time'])) ?></div>
        </div>

        <div class="detail-item">
          <div class="dlabel">Learning Mode</div>
          <div class="dval"><?= $b['learning_mode']==='online'?' Online':' Face to Face' ?></div>
          <?php if (!empty($b['meeting_location'])): ?>
            <div style="font-size:12px;color:var(--muted);margin-top:3px;"><i class="bi bi-geo-alt"></i> <?= e($b['meeting_location']) ?></div>
          <?php endif; ?>
        </div>

        <div class="detail-item">
    <div class="dlabel">Focus Areas</div>
    <?php if (!empty($b['focus'])): ?>
        <div class="tags-row">
            <?php foreach(explode(',',$b['focus']) as $f): if(trim($f)): ?>
                <span class="tag"><?= e(trim($f)) ?></span>
            <?php endif; endforeach; ?>
        </div>
    <?php else: ?>
        <div class="dval">—</div>
    <?php endif; ?>
</div>

<div class="detail-item">
    <div class="dlabel">Proficiency Level</div>
    <div class="dval">
        <?php 
        $level = $b['proficiency_level'] ?? 'beginner';
        $levelLabels = [
            'beginner' => 'Beginner',
            'intermediate' => 'Intermediate',
            'advanced' => 'Advanced',
            'master' => 'Master'
        ];
        echo $levelLabels[$level] ?? ucfirst($level);
        ?>
    </div>
</div>

        <?php if (!empty($b['notes'])): ?>
        <div class="detail-item">
          <div class="dlabel">Notes</div>
          <div class="dval" style="font-weight:700;font-size:13px;line-height:1.5;"><?= e($b['notes']) ?></div>
        </div>
        <?php endif; ?>

      </div>
    </div>

  </div>
</div>

    <!-- PAYMENT -->
    <div class="card">
      <div class="card-title"><i class="bi bi-credit-card"></i> Payment</div>
            <?php if ($bookStatus === 'pending'): ?>
            <div class="info-note">
            <i class="bi bi-info-circle"></i> Payment will be available once the tutor accepts your booking.
            </div>  
            <?php elseif ($bookStatus === 'rescheduled'): ?>
    <div class="pay-box review">
        <div class="pay-row">
            <span class="pl">Status</span>
            <span class="pv" style="color:#A06B00;">
                <i class="bi bi-calendar-plus"></i> Reschedule Requested
            </span>
        </div>
        <div class="pay-row">
            <span class="pl">Amount</span>
            <span class="pv">
                RM <?= e(number_format($b['payment_amount'] ?? $b['rate'], 2)) ?>
            </span>
        </div>
        <?php if (!empty($b['payment_method'])): ?>
        <div class="pay-row">
            <span class="pl">Method</span>
            <span class="pv"><?= e(ucwords(str_replace('_', ' ', $b['payment_method']))) ?></span>
        </div>
        <?php endif; ?>
    </div>
    <div class="info-note">
        <i class="bi bi-info-circle"></i> Your reschedule request has been sent. Waiting for tutor to approve the new schedule.
    </div>
     <div class="action-bar" style="margin-top:16px;">
        <button onclick="openCancelRescheduleModal()" class="btn-danger" style="background:linear-gradient(135deg,#f59e0b,#e67e22);color:white;">
            <i class="bi bi-x-circle"></i> Cancel Reschedule Request
        </button>
    </div>
    <?php elseif ($bookStatus === 'accepted'): ?>

  <?php if ($paymentState === 'processing'): ?>
    <div class="pay-box review">
      <div class="pay-row"><span class="pl">Status</span><span class="pv" style="color:#A06B00;"><i class="bi bi-hourglass-split"></i> Payment Under Review</span></div>
      <div class="pay-row"><span class="pl">Amount</span><span class="pv">RM <?= e(number_format($b['payment_amount'] ?? 0,2)) ?></span></div>
      <?php if (!empty($b['payment_method'])): ?>
      <div class="pay-row"><span class="pl">Method</span><span class="pv"><?= e(ucwords(str_replace('_',' ',$b['payment_method']))) ?></span></div>
      <?php endif; ?>
      <?php if (!empty($b['paid_at'])): ?>
      <div class="pay-row"><span class="pl">Submitted On</span><span class="pv"><?= date('d M Y, g:i A', strtotime($b['paid_at'])) ?></span></div>
      <?php endif; ?>
    </div>
    <div class="info-note"><i class="bi bi-info-circle"></i> Your payment is being verified. Session will be confirmed shortly.</div>

  <?php elseif ($paymentState === 'failed'): ?>
    <div class="pay-box cancelled">
      <div class="pay-row"><span class="pl">Status</span><span class="pv" style="color:#C94F4F;"><i class="bi bi-x-circle-fill"></i> Payment Failed</span></div>
    </div>
    <div class="action-bar">
      <a href="payment_form.php?booking_id=<?= $b['id'] ?>" class="btn-primary"><i class="bi bi-credit-card"></i> Retry Payment</a>
    </div>

  <?php else: ?>
    <div class="pay-box unpaid">
      <div class="pay-row"><span class="pl">Status</span><span class="pv" style="color:#A35F3F;"><i class="bi bi-clock"></i> Awaiting Payment</span></div>
      <div class="pay-row"><span class="pl">Amount Due</span><span class="pv" style="color:var(--hot-pink);font-size:18px;">RM <?= e($b['rate']) ?></span></div>
    </div>
    <div class="info-note" style="margin-bottom:12px;"><i class="bi bi-info-circle"></i> After payment, admin will verify and confirm your session within 1–2 business days.</div>
    <div class="action-bar">
      <a href="payment_form.php?booking_id=<?= $b['id'] ?>" class="btn-primary"><i class="bi bi-credit-card"></i> Pay Now</a>
      <button onclick="openCancelModal()" class="btn-action ghost" style="background:linear-gradient(135deg,#E75A9B,#F28AB2);color:white;">
    <i class="bi bi-x-circle"></i> Cancel Booking
</button>
    </div>
  <?php endif; ?>
 <?php elseif ($bookStatus === 'confirmed'): ?>

<div class="pay-box paid">
    <div class="pay-row">
        <span class="pl">Status</span>
        <span class="pv" style="color:#3D7047;">
            <i class="bi bi-check-circle-fill"></i>
            Payment Paid & Verified
        </span>
    </div>

    <div class="pay-row">
        <span class="pl">Total Paid</span>
        <span class="pv" style="font-size: 16px; color: #28a745; font-weight: 900;">
            RM <?= number_format($b['total_paid_amount'] ?? 0, 2) ?>
        </span>
    </div>

<!-- Payment Breakdown - Show ALL payments -->
<div class="pay-row" style="flex-direction: column; align-items: flex-start; gap: 8px;">
    <span class="pl">Payment Breakdown</span>
    <div style="width: 100%;">
        <?php 
        $displayTotal = 0;
        foreach ($allPaymentsForDisplay as $index => $payment): 
            $displayTotal += $payment['amount'];
            $statusBadge = '';
            if ($payment['status'] === 'verified') {
                $statusBadge = '<span style="color: #28a745; font-size: 10px;">✓ Verified</span>';
            } elseif ($payment['status'] === 'rejected' || ($payment['is_original'] ?? false)) {
                $statusBadge = '<span style="color: #dc2626; font-size: 10px;">⚠️ Rejected</span>';
            } elseif ($payment['status'] === 'pending') {
                $statusBadge = '<span style="color: #f59e0b; font-size: 10px;">⏳ Pending</span>';
            }
            
            // Determine method display
            $methodDisplay = $payment['payment_method'];
            if (($payment['is_original'] ?? false)) {
                $methodDisplay = 'Online Banking';
            }
        ?>
        <div style="display: flex; justify-content: space-between; font-size: 12px; padding: 4px 0; border-bottom: 1px dashed rgba(0,0,0,0.05);">
            <span>
                <i class="bi bi-credit-card"></i> 
                Payment <?= $index + 1 ?> (<?= ucwords(str_replace('_', ' ', $methodDisplay)) ?>)
                <?= $statusBadge ?>
            </span>
            <span style="font-weight: 700;">RM <?= number_format($payment['amount'], 2) ?></span>
        </div>
        <div style="font-size: 10px; color: #666; margin-bottom: 8px;">
            <i class="bi bi-clock"></i> <?= date('d M Y, g:i A', strtotime($payment['created_at'])) ?>
        </div>
        <?php endforeach; ?>
    </div>
    <div style="margin-top: 8px; padding-top: 8px; border-top: 2px solid rgba(0,0,0,0.1); display: flex; justify-content: space-between; width: 100%;">
        <span style="font-weight: 900;">Total Paid</span>
        <span style="font-weight: 900; color: #28a745;">RM <?= number_format($displayTotal, 2) ?></span>
    </div>
</div>

    <div class="pay-row">
        <span class="pl">Method (Last)</span>
        <span class="pv">
            <?php 
            $method = $b['payment_method'] ?? '';
            if (!empty($method)) {
                echo ucwords(str_replace('_', ' ', $method));
            } else {
                echo '<span style="color: #999;">Not selected</span>';
            }
            ?>
        </span>
    </div>

    <?php if (!empty($b['paid_at'])): ?>
    <div class="pay-row">
        <span class="pl">Last Paid On</span>
        <span class="pv"><?= date('d M Y, g:i A', strtotime($b['paid_at'])) ?></span>
    </div>
    <?php endif; ?>
    
    <!-- ADD CANCELLATION POLICY INFO BOX -->
    </div>
</div>

<!-- ACTION BAR FOR CONFIRMED SESSIONS -->
<div class="action-bar" style="margin-top:16px;">
    <?php if ($is_future_session): ?>
        <a href="reschedule_booking.php?id=<?= $bookingID ?>" class="btn-primary">
            <i class="bi bi-calendar-plus"></i> Reschedule Session
        </a>
    <?php else: ?>
        <span class="btn-secondary" style="opacity:0.6; cursor:not-allowed;">
            <i class="bi bi-calendar-x"></i> Cannot Cancel (Session Ended)
        </span>
    <?php endif; ?>
    
    <?php if ($paymentState === 'paid'): ?>
        <?php if ($b['payment_method'] === 'stripe'): ?>
            <a href="receipt_stripe.php?booking_id=<?= $b['id'] ?>&action=pdf" class="btn-secondary">
                <i class="bi bi-download"></i> Download Receipt
            </a>
            <a href="receipt_stripe.php?booking_id=<?= $b['id'] ?>&action=email" class="btn-secondary">
                <i class="bi bi-envelope"></i> Email Receipt
            </a>
        <?php else: ?>
            <a href="receipt.php?booking_id=<?= $b['id'] ?>&action=pdf" class="btn-secondary">
                <i class="bi bi-download"></i> Download Receipt
            </a>
            <a href="receipt.php?booking_id=<?= $b['id'] ?>&action=email" class="btn-secondary">
                <i class="bi bi-envelope"></i> Email Receipt
            </a>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php elseif ($bookStatus === 'completed'): ?>

<?php if($paymentState === 'paid'): ?>
<div class="pay-box paid">
    <div class="pay-row">
        <span class="pl">Status</span>
        <span class="pv" style="color:#3D7047;">
            <i class="bi bi-check-circle-fill"></i> Payment Verified
        </span>
    </div>
    <div class="pay-row">
        <span class="pl">Total Paid</span>
        <span class="pv" style="font-size: 16px; color: #28a745; font-weight: 900;">
            RM <?= number_format($b['total_paid_amount'] ?? 0, 2) ?>
        </span>
    </div>
    
    <!-- Payment Breakdown if multiple payments -->
    <?php 
    $breakdownStmt = $conn->prepare("SELECT amount, payment_method, created_at FROM payments WHERE booking_id = ? AND status = 'verified' ORDER BY created_at ASC");
    $breakdownStmt->bind_param("i", $bookingID);
    $breakdownStmt->execute();
    $allPayments = $breakdownStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    if (count($allPayments) > 1):
    ?>
    <div class="pay-row" style="flex-direction: column; align-items: flex-start; gap: 8px;">
        <span class="pl">Payment Breakdown</span>
        <div style="width: 100%;">
            <?php foreach ($allPayments as $index => $payment): ?>
            <div style="display: flex; justify-content: space-between; font-size: 12px; padding: 4px 0; border-bottom: 1px dashed rgba(0,0,0,0.05);">
                <span>
                    <i class="bi bi-credit-card"></i> 
                    Payment <?= $index + 1 ?> (<?= ucwords(str_replace('_', ' ', $payment['payment_method'])) ?>)
                </span>
                <span style="font-weight: 700;">RM <?= number_format($payment['amount'], 2) ?></span>
            </div>
            <div style="font-size: 10px; color: #666; margin-bottom: 4px;">
                <i class="bi bi-clock"></i> <?= date('d M Y, g:i A', strtotime($payment['created_at'])) ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
    
    <div class="pay-row">
        <span class="pl">Method (Last)</span>
        <span class="pv"><?= e(ucwords(str_replace('_', ' ', $b['payment_method'] ?? ''))) ?></span>
    </div>
    <?php if (!empty($b['receipt_number'])): ?>
    <div class="pay-row">
        <span class="pl">Receipt No.</span>
        <span class="pv"><?= e($b['receipt_number']) ?></span>
    </div>
    <?php endif; ?>
    <?php if (!empty($b['paid_at'])): ?>
    <div class="pay-row">
        <span class="pl">Last Paid On</span>
        <span class="pv"><?= date('d M Y, g:i A', strtotime($b['paid_at'])) ?></span>
    </div>
    <?php endif; ?>
</div>

<?php elseif($paymentState === 'processing'): ?>

<div class="pay-box review">
  <div class="pay-row">
    <span class="pl">Status</span>
    <span class="pv" style="color:#A06B00;">
      <i class="bi bi-hourglass-split"></i>
      Waiting For Verification
    </span>
  </div>
</div>

<div class="info-note">
<i class="bi bi-info-circle"></i>
Admin will verify your payment within 1–2 business days.
</div>

<?php elseif($paymentState === 'cash'): ?>

<div class="pay-box unpaid">
  <div class="pay-row">
    <span class="pl">Status</span>
    <span class="pv" style="color:#A35F3F;">
      <i class="bi bi-cash"></i>
      Cash Payment
    </span>
  </div>
</div>

<div class="info-note">
<i class="bi bi-info-circle"></i>
Payment made directly during session.
</div>

<?php elseif ($paymentState === 'failed'): ?>
<div class="pay-box cancelled">
  <div class="pay-row">
    <span class="pl">Status</span>
    <span class="pv" style="color:#C94F4F;"><i class="bi bi-x-circle-fill"></i> Payment Failed</span>
  </div>
</div>

<?php else: ?>
<div class="pay-box unpaid">
  <div class="pay-row">
    <span class="pl">Status</span>
    <span class="pv" style="color:#A35F3F;"><i class="bi bi-clock"></i> No Payment Record</span>
  </div>
</div>

<?php endif; ?>

<?php if ($paymentState === 'paid'): ?>
<div class="action-bar" style="margin-top:16px;">
  <?php if ($b['payment_method'] === 'stripe'): ?>
    <a href="receipt_stripe.php?booking_id=<?= $b['id'] ?>&action=pdf" class="btn-secondary">
      <i class="bi bi-download"></i> Download Receipt
    </a>
    <a href="receipt_stripe.php?booking_id=<?= $b['id'] ?>&action=email" class="btn-secondary">
      <i class="bi bi-envelope"></i> Email Receipt
    </a>
  <?php else: ?>
    <a href="receipt.php?booking_id=<?= $b['id'] ?>&action=pdf" class="btn-secondary">
      <i class="bi bi-download"></i> Download Receipt
    </a>
    <a href="receipt.php?booking_id=<?= $b['id'] ?>&action=email" class="btn-secondary">
      <i class="bi bi-envelope"></i> Email Receipt
    </a>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php elseif ($bookStatus === 'disputed'): ?>

<?php if ($is_serious_dispute): ?>
<div class="pay-box review">
    <div class="pay-row">
        <span class="pl">Status</span>
        <span class="pv" style="color:#C94F4F;">
            <i class="bi bi-exclamation-triangle-fill"></i> Under Admin Review
        </span>
    </div>
    <div class="pay-row">
        <span class="pl">Amount</span>
        <span class="pv">
            RM <?= e(number_format($b['payment_amount'] ?? $b['rate'], 2)) ?>
        </span>
    </div>
    <?php if (!empty($b['payment_method'])): ?>
    <div class="pay-row">
        <span class="pl">Method</span>
        <span class="pv"><?= e(ucwords(str_replace('_', ' ', $b['payment_method']))) ?></span>
    </div>
    <?php endif; ?>
    <?php if (!empty($b['paid_at'])): ?>
    <div class="pay-row">
        <span class="pl">Paid On</span>
        <span class="pv"><?= date('d M Y, g:i A', strtotime($b['paid_at'])) ?></span>
    </div>
    <?php endif; ?>
</div>
<div class="info-note" style="background:rgba(255,200,200,.4); border-left-color:#C94F4F;">
    <i class="bi bi-exclamation-triangle-fill" style="color:#C94F4F;"></i> 
    <strong>⚠️ Serious Issue - Under Admin Review</strong><br>
    Your report has been escalated to admin. This involves a no-show or serious matter.<br>
    Admin will review within 2-3 business days. Payment is on hold pending review.
</div>

<?php else: ?>
<div class="pay-box review">
    <div class="pay-row">
        <span class="pl">Status</span>
        <span class="pv" style="color:#f59e0b;">
            <i class="bi bi-chat-dots"></i> Issue Reported - Pending Resolution
        </span>
    </div>
    <div class="pay-row">
        <span class="pl">Amount</span>
        <span class="pv">
            RM <?= e(number_format($b['payment_amount'] ?? $b['rate'], 2)) ?>
        </span>
    </div>
    <?php if (!empty($b['payment_method'])): ?>
    <div class="pay-row">
        <span class="pl">Method</span>
        <span class="pv"><?= e(ucwords(str_replace('_', ' ', $b['payment_method']))) ?></span>
    </div>
    <?php endif; ?>
    <?php if (!empty($b['paid_at'])): ?>
    <div class="pay-row">
        <span class="pl">Paid On</span>
        <span class="pv"><?= date('d M Y, g:i A', strtotime($b['paid_at'])) ?></span>
    </div>
    <?php endif; ?>
</div>

<div class="info-note" style="background:rgba(255,241,200,.4); border-left-color:#f59e0b;">
    <i class="bi bi-chat-dots" style="color:#f59e0b;"></i> 
    <strong>📝 Issue Reported - Please Resolve with Tutor</strong><br>
    <?php if ($activeDispute): ?>
        <strong>Issue:</strong> <?= ucfirst(str_replace('_', ' ', $activeDispute['issue_type'])) ?><br>
        <strong>Your message:</strong> "<?= e($activeDispute['message']) ?>"<br><br>
    <?php endif; ?>
    The tutor has been notified. Please discuss and resolve this issue together within 48 hours.<br>
    If not resolved within 48 hours, it will be auto-escalated to admin for review.
    
    <div style="margin-top: 15px;">
        <button onclick="contactTutor(<?= $b['tutor_id'] ?>, '<?= e($b['tutor_name']) ?>', '<?= e($b['tutor_phone']) ?>', '<?= e($displayName) ?>', <?= $bookingID ?>, '<?= e($b['language']) ?>')" 
                style="background:#25D366;color:white;padding:10px 20px;border:none;border-radius:30px;cursor:pointer;font-size:13px;font-weight:900;display:inline-flex;align-items:center;gap:8px;">
            <i class="bi bi-whatsapp" style="font-size:16px;"></i> 
            Message Tutor on WhatsApp
        </button>
    </div>
</div>
<?php endif; ?>

      <?php elseif ($bookStatus === 'cancelled'): ?>
        <?php
          $cancelledBy  = $b['cancelled_by'] ?? 'student';
          $cancelReason = $b['cancel_reason'] ?? 'No reason provided.';
          $whoLabel = match($cancelledBy) {
            'tutor'  => '🎓 Cancelled by Tutor',
            'admin'  => '🛡️ Cancelled by Admin',
            default  => '👤 Cancelled by You',
          };
        ?>
        <div class="cancel-info">
          <div class="cancel-who <?= e($cancelledBy) ?>"><?= $whoLabel ?></div>
          <div class="cancel-reason"><i class="bi bi-chat-left-text"></i> Reason: <?= e($cancelReason) ?></div>
        </div>
      <?php endif; ?>
  
    </div><?php if ($b['learning_mode'] === 'online' && ($bookStatus === 'confirmed' || $bookStatus === 'completed')): 
    // Calculate session times
    $session_start = strtotime($b['booking_date'] . ' ' . $b['booking_time']);
    $session_end = $session_start + (2 * 3600);
    $current_time = time();
    $is_session_ended = ($current_time > $session_end);
    $is_session_ongoing = ($current_time >= $session_start && $current_time <= $session_end);
    $is_session_future = ($current_time < $session_start);
?><div class="card">
    <div class="card-title"><i class="bi bi-camera-video-fill"></i> Online Session</div>
    
    <?php if (empty($b['meeting_link']) && ($b['status'] == 'confirmed')): ?>
        <div class="info-note" style="text-align: center;">
            <i class="bi bi-exclamation-triangle-fill" style="color: #f59e0b;"></i>
            <strong>Meeting link not yet available</strong><br>
            The tutor will provide the meeting link before the session starts.
            <?php if (!empty($b['tutor_phone'])): ?>
                <div style="margin-top: 10px;">
                    <button onclick="contactTutor(<?= $b['tutor_id'] ?>, '<?= e($b['tutor_name']) ?>', '<?= e($b['tutor_phone']) ?>', '<?= e($displayName) ?>', <?= $bookingID ?>, '<?= e($b['language']) ?>', 'meeting_link')" class="btn-secondary">
                        <i class="bi bi-whatsapp"></i> Contact Tutor for Link
                    </button>
                </div>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <!-- Join Meeting Button -->
        <?php if (!$is_session_ended): ?>
        <div style="background: linear-gradient(135deg, rgba(231,90,155,0.1), rgba(242,138,178,0.05)); border-radius: 16px; padding: 16px; margin-bottom: 16px;">
            <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 15px;">
                <div>
                    <strong style="font-size: 14px;">Ready for your session?</strong>
                    <?php if ($is_session_future): ?>
                        <p style="font-size: 12px; color: #64748b; margin: 5px 0 0;">
                            <i class="bi bi-clock"></i> Session starts on <?= date('d M Y, g:i A', $session_start) ?>
                        </p>
                    <?php elseif ($is_session_ongoing): ?>
                        <p style="font-size: 12px; color: #28a745; margin: 5px 0 0;">
                            <i class="bi bi-play-circle-fill"></i> Session is happening NOW!
                        </p>
                    <?php endif; ?>
                </div>
                <button onclick="checkAndJoinMeeting(<?= $bookingID ?>, '<?= urlencode($b['meeting_link']) ?>')" 
                    class="btn-primary" style="background: linear-gradient(135deg, #28a745, #20c997); padding: 10px 24px; width: auto;">
                    <i class="bi bi-camera-video-fill"></i> Join Meeting
                </button>
            </div>
        </div>
        <?php else: ?>
        <div style="background: rgba(200,200,200,0.1); border-radius: 16px; padding: 16px; margin-bottom: 16px; text-align: center; border: 1px dashed #ccc;">
            <i class="bi bi-clock-history" style="font-size: 28px; color: #999;"></i>
            <p style="font-size: 13px; color: #666; margin-top: 8px;">
                <strong>Session has ended</strong><br>
                This session was held on <?= date('d M Y, g:i A', $session_start) ?>
            </p>
        </div>
        <?php endif; ?>
        
        <!-- Meeting Activity -->
        <div style="margin-bottom: 16px;">
            <strong style="font-size: 13px;">Attendance Record</strong>
            <div style="background: #f8fafc; border-radius: 12px; padding: 12px; margin-top: 8px;">
                <?php
                $logsStmt = $conn->prepare("SELECT * FROM meeting_logs WHERE booking_id = ? ORDER BY join_time DESC");
                $logsStmt->bind_param("i", $bookingID);
                $logsStmt->execute();
                $logs = $logsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
                
                if (!empty($logs)):
                ?>
                    <?php foreach ($logs as $log): ?>
                    <div style="font-size: 12px; padding: 6px 0; border-bottom: 1px solid #eef2f7;">
                        <i class="bi bi-person-circle"></i> <strong><?= ucfirst(e($log['participant_role'])) ?></strong>
                        joined: <?= date('d M Y, g:i A', strtotime($log['join_time'])) ?>
                        <?php if ($log['leave_time']): ?>
                            - left: <?= date('g:i A', strtotime($log['leave_time'])) ?>
                            <span style="color: #28a745;">(<?= $log['duration_minutes'] ?> min)</span>
                        <?php else: ?>
                            <span style="color: #f59e0b;">(Active)</span>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p style="font-size: 12px; color: #64748b; margin: 0;">No meeting activity recorded yet.</p>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- End Session Button -->
        <?php if (!$is_session_ended || $is_session_ongoing): ?>
        <div>
            <strong style="font-size: 13px;"><i class="bi bi-door-closed"></i> End Session</strong>
            <div style="background: #f8fafc; border-radius: 12px; padding: 12px; margin-top: 8px;">
                <p style="font-size: 12px; color: #64748b; margin-bottom: 10px;">
                    After finishing your session, click below to record your leave time.
                </p>
                
                <?php
                $hasActiveSession = false;
                $activeCheck = $conn->prepare("SELECT id FROM meeting_logs WHERE booking_id = ? AND leave_time IS NULL LIMIT 1");
                $activeCheck->bind_param("i", $bookingID);
                $activeCheck->execute();
                $hasActiveSession = $activeCheck->get_result()->num_rows > 0;
                
                if ($hasActiveSession):
                ?>
                <button onclick="recordMeetingLeave(<?= $bookingID ?>)" class="btn-outline" style="width: auto; padding: 8px 20px; background: #dc3545; color: white; border: none; border-radius: 30px; cursor: pointer;">
                    <i class="bi bi-box-arrow-right"></i> End Session & Record Leave
                </button>
                <?php else: ?>
                <button disabled style="width: auto; padding: 8px 20px; background: #6c757d; color: white; border: none; border-radius: 30px; opacity: 0.6;">
                    <i class="bi bi-check-circle"></i> No Active Session
                </button>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <?php if ($bookStatus === 'confirmed' && !$is_completed): ?>
        <p style="font-size: 12px; color: #f59e0b; margin-top: 10px;">
            <i class="bi bi-exclamation-triangle"></i> Please confirm your attendance above if you attended.
        </p>
        <?php endif; ?>
        
    <?php endif; ?>
</div>
                
<!-- Face-to-Face Session Section -->
<?php endif; ?>
<?php if ($b['learning_mode'] === 'face_to_face' && ($bookStatus === 'confirmed' || $bookStatus === 'completed')): ?>
<div class="card">
    <div class="card-title">
        <i class="bi bi-pin-map-fill"></i> 
        Face-to-Face Session
    </div>
    
    <!-- Meeting Location -->
    <div style="margin-bottom: 20px;">
        <div style="background: linear-gradient(135deg, rgba(231,90,155,0.08), rgba(242,138,178,0.04)); border-radius: 16px; padding: 18px; border: 1px solid rgba(231,90,155,0.12);">
            <div style="display: flex; align-items: flex-start; gap: 12px;">
                <div style="width: 44px; height: 44px; background: rgba(231,90,155,0.1); border-radius: 14px; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                    <i class="bi bi-geo-alt" style="font-size: 22px; color: #E75A9B;"></i>
                </div>
                <div style="flex: 1;">
                    <div style="font-size: 13px; font-weight: 600; color: #E75A9B; margin-bottom: 6px;">Meeting Location</div>
                    <?php if (!empty($b['meeting_location'])): ?>
                        <div style="font-size: 14px; font-weight: 500; color: #342635; line-height: 1.5; margin-bottom: 12px;">
                            <?= nl2br(e($b['meeting_location'])) ?>
                        </div>
                        <a href="https://www.google.com/maps/search/?api=1&query=<?= urlencode($b['meeting_location']) ?>" 
                           target="_blank" 
                           style="display: inline-flex; align-items: center; gap: 6px; padding: 6px 14px; background: #E75A9B; color: white; border-radius: 20px; font-size: 12px; font-weight: 500; text-decoration: none;">
                            <i class="bi bi-map"></i> Open in Maps
                        </a>
                    <?php else: ?>
                        <div style="font-size: 13px; color: #f59e0b; display: flex; align-items: center; gap: 6px;">
                            <i class="bi bi-exclamation-triangle-fill"></i> No location provided
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Attendance Proof -->
    <div>
        <div style="background: #f8fafc; border-radius: 16px; padding: 18px; border: 1px solid #eef2f7;">
            <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 12px;">
                <i class="bi bi-camera" style="font-size: 18px; color: #E75A9B;"></i>
                <span style="font-size: 13px; font-weight: 600; color: #342635;">Attendance Proof</span>
            </div>
            <?php
            $proofStmt = $conn->prepare("SELECT * FROM attendance_proofs WHERE booking_id = ? AND user_role = 'tutor' ORDER BY uploaded_at DESC");
            $proofStmt->bind_param("i", $bookingID);
            $proofStmt->execute();
            $proofs = $proofStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            ?>
            
            <?php if (!empty($proofs)): ?>
                <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                    <?php foreach ($proofs as $proof): ?>
                    <a href="../uploads/proofs/<?= e($proof['file_path']) ?>" target="_blank" 
                       style="display: flex; align-items: center; gap: 8px; background: #e8f5e9; padding: 8px 12px; border-radius: 10px; text-decoration: none;">
                        <i class="bi bi-image" style="color: #2e7d32;"></i>
                        <span style="font-size: 12px; color: #2e7d32;">View Proof</span>
                        <i class="bi bi-box-arrow-up-right" style="font-size: 10px; color: #2e7d32;"></i>
                    </a>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div style="display: flex; align-items: center; gap: 8px;">
                    <i class="bi bi-clock" style="color: #cbd5e1;"></i>
                    <span style="font-size: 12px; color: #94a3b8;">Waiting for tutor to upload proof</span>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>
<?php if ($b['status'] === 'completed'): ?>
<div class="completed-grid">

<div class="card" id="rate">
  <div class="card-title"><i class="bi bi-star"></i> Rate Your Session</div>
  <?php if ($b['rated']): ?>
    <div style="padding:16px;border-radius:14px;background:rgba(221,211,255,.3);border:1px solid rgba(167,123,232,.2);">
      <p style="margin:0 0 8px;font-size:13px;font-weight:900;color:#7648B8;">You rated this session:</p>
      <div class="star-display">
        <?php for($i=1;$i<=5;$i++): ?>
          <i class="bi bi-star<?= $i<=$b['my_rating']?'-fill':'' ?>"></i>
        <?php endfor; ?>
      </div>
      <?php if ($b['my_comment']): ?>
        <p style="margin:10px 0 0;font-size:13px;color:var(--muted);font-style:italic;">"<?= e($b['my_comment']) ?>"</p>
      <?php endif; ?>
      <?php if ($b['my_anonymous']): ?>
        <div style="display:inline-flex;align-items:center;gap:5px;margin-top:8px;padding:4px 12px;border-radius:999px;font-size:11px;font-weight:900;background:rgba(255,241,246,.8);color:var(--pink-dark);border:1px solid rgba(242,138,178,.2);">
          <i class="bi bi-incognito"></i> Posted anonymously
        </div>
      <?php else: ?>
        <div style="display:inline-flex;align-items:center;gap:5px;margin-top:8px;padding:4px 12px;border-radius:999px;font-size:11px;font-weight:900;background:rgba(215,238,219,.5);color:#3D7047;border:1px solid rgba(45,106,66,.2);">
          <i class="bi bi-person-check"></i> Posted as <?= e($displayName) ?>
        </div>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <form method="POST">
      <p style="margin:0 0 10px;font-size:15px;font-weight:700;color:var(--ink);">How was your session with <strong><?= e($b['tutor_name']) ?></strong>?</p>
      <div class="star-row" id="starRow">
        <?php for($i=1;$i<=5;$i++): ?>
          <button type="button" class="star-btn" data-val="<?= $i ?>" onclick="setRating(<?= $i ?>)">⭐</button>
        <?php endfor; ?>
      </div>
      <input type="hidden" name="rating" id="ratingInput" value="0">
      <textarea name="comment" placeholder="Share your experience (optional)..." style="width:100%;padding:14px 16px;border:1px solid rgba(46,42,59,.12);border-radius:14px;outline:none;font-size:14px;resize:vertical;min-height:100px;margin:14px 0;color:var(--ink);"></textarea>

      <!-- Anonymous toggle -->
<label id="anonToggle" style="display:flex;align-items:center;gap:12px;padding:10px 14px;border-radius:14px;border:1px solid rgba(46,42,59,.10);background:rgba(255,255,255,.7);cursor:pointer;margin-bottom:12px;">
  <input type="checkbox" name="is_anonymous" id="anonCheckbox" style="display:none;">
  <i class="bi bi-incognito" style="font-size:18px;color:var(--muted);transition:.15s ease;" id="anonIcon"></i>
  <div>
    <strong style="display:block;font-size:13px;font-weight:900;color:#342635;">Post anonymously</strong>
    <span style="font-size:12px;color:var(--muted);">Your name will be hidden from the tutor and other students.</span>
  </div>
  <div id="anonCheck" style="margin-left:auto;width:20px;height:20px;border-radius:6px;border:2px solid rgba(46,42,59,.15);background:white;display:grid;place-items:center;flex-shrink:0;transition:.15s ease;"></div>
</label>

      <div id="previewBox" style="padding:10px 14px;border-radius:12px;background:rgba(221,211,255,.2);border:1px solid rgba(167,123,232,.15);font-size:12px;color:#6D4964;font-weight:700;margin-bottom:14px;">
        <i class="bi bi-eye"></i> Will appear as: <strong id="previewName"><?= e($displayName) ?></strong>
      </div>

      <button type="submit" name="submit_rating" class="btn-primary"><i class="bi bi-star-fill"></i> Submit Rating</button>
    </form>
  <?php endif; ?>
</div>
<div class="card" style="display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;padding:32px 24px;">
  <div style="width:64px;height:64px;border-radius:20px;background:rgba(231,90,155,.1);display:grid;place-items:center;margin-bottom:16px;">
    <i class="bi bi-arrow-repeat" style="font-size:28px;color:var(--hot-pink);"></i>
  </div>
  <h3 style="margin:0 0 8px;font-size:18px;font-weight:900;">Book Again</h3>
  <p style="margin:0 0 20px;font-size:14px;color:var(--muted);line-height:1.6;max-width:260px;">Had a great session? Book <strong><?= e($b['tutor_name']) ?></strong> again with the same settings.</p>
  <form method="POST" action="booking_form.php?tutor_id=<?= $b['tutor_id'] ?>">
    <input type="hidden" name="prefill_lang" value="<?= e($b['language']) ?>">
    <input type="hidden" name="prefill_mode" value="<?= e($b['learning_mode']) ?>">
    <input type="hidden" name="prefill_focus" value="<?= e($b['focus']) ?>">
    <button type="submit" class="btn-primary" style="padding:12px 28px;"><i class="bi bi-arrow-repeat"></i> Rebook This Tutor</button>
  </form>
</div>    
</div>
<?php endif; ?>
   
    <?php if (
    $displayState === 'pending'
): ?>

<div class="action-bar">
    <button class="btn-danger" onclick="openCancelModal()">
        <i class="bi bi-x-circle"></i>
        Cancel Booking
    </button>
</div>

<?php endif; ?>
  </div>
</div>

<!-- Cancel Reschedule Modal (for rescheduled status) -->
<div class="modal-overlay" id="cancelRescheduleModal">
    <div class="modal-box">
        <h3><i class="bi bi-exclamation-triangle" style="color: #f59e0b;"></i> Cancel Reschedule Request</h3>
        <p>Are you sure you want to cancel this reschedule request? Your booking will go back to confirmed status with the original date and time.</p>
        <div class="modal-actions">
            <button type="button" onclick="closeCancelRescheduleModal()" style="padding:10px 20px;border-radius:999px;border:1px solid rgba(46,42,59,.12);background:none;color:#7A5570;font-size:13px;font-weight:900;cursor:pointer;">Keep Request</button>
            <button type="button" onclick="submitCancelReschedule()" style="background:linear-gradient(135deg,#f59e0b,#e67e22);color:white;padding:10px 20px;border-radius:999px;border:none;font-size:13px;font-weight:900;cursor:pointer;">
                Yes, Cancel Request
            </button>
        </div>
    </div>
</div>

<div class="modal-overlay" id="cancelModal">
    <div class="modal-box">
        <h3><i class="bi bi-exclamation-triangle" style="color: #dc2626;"></i> Cancel Booking</h3>
        <p>Please select a reason for cancelling:</p>
        <form id="cancelForm" method="POST" action="cancel_booking.php">
            <input type="hidden" name="booking_id" id="cancel_booking_id" value="<?= $bookingID ?>">
            
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

<div class="toast" id="toast"></div>
<?php include 'nav_search_modal.php'; ?>
<script src="../js/search_modal.js"></script>
<script>
function toggleDropdown(event) {
    if (event) {
        event.stopPropagation();
    }
    const dropdown = document.getElementById('profileDropdown');
    if (dropdown) {
        if (dropdown.style.display === 'block') {
            dropdown.style.display = 'none';
        } else {
            dropdown.style.display = 'block';
        }
    }
    return false;
}

// Close dropdown when clicking outside
document.addEventListener('click', function(e) {
    const dropdown = document.getElementById('profileDropdown');
    const profileBtn = document.getElementById('profileBtn');
    
    if (!dropdown || !profileBtn) return;
    
    if (profileBtn.contains(e.target)) {
        return;
    }
    
    if (dropdown.style.display === 'block' && !dropdown.contains(e.target)) {
        dropdown.style.display = 'none';
    }
});

// ========== REST OF YOUR FUNCTIONS ==========
const studentName = <?= json_encode($displayName) ?>;

const anonCb = document.getElementById('anonCheckbox');
if (anonCb) {
    anonCb.addEventListener('change', function() {
        const anonActive = this.checked;
        const toggle = document.getElementById('anonToggle');
        const check = document.getElementById('anonCheck');
        const icon = document.getElementById('anonIcon');
        if (toggle) toggle.style.borderColor = anonActive ? 'var(--hot-pink)' : 'rgba(46,42,59,.10)';
        if (toggle) toggle.style.background = anonActive ? 'rgba(255,241,246,.8)' : 'rgba(255,255,255,.7)';
        if (check) check.style.background = anonActive ? 'linear-gradient(135deg,#E75A9B,#F28AB2)' : 'white';
        if (check) check.style.borderColor = anonActive ? 'var(--pink)' : 'rgba(46,42,59,.15)';
        if (check) check.innerHTML = anonActive ? '<i class="bi bi-check" style="font-size:13px;color:white;"></i>' : '';
        if (icon) icon.style.color = anonActive ? 'var(--hot-pink)' : 'var(--muted)';
        const previewName = document.getElementById('previewName');
        if (previewName) previewName.textContent = anonActive ? 'Anonymous Student' : studentName;
    });
}

function setRating(val) {
    document.getElementById('ratingInput').value = val;
    document.querySelectorAll('.star-btn').forEach((btn, i) => btn.classList.toggle('active', i < val));
}

function openCancelModal() { document.getElementById('cancelModal').classList.add('active'); }
function closeCancelModal() {
    const modal = document.getElementById('cancelModal');
    if (modal) modal.classList.remove('active');
    const form = document.getElementById('cancelForm');
    if (form) form.reset();
    const otherText = document.getElementById('otherReasonText');
    if (otherText) otherText.style.display = 'none';
}
function openCancelRescheduleModal() { document.getElementById('cancelRescheduleModal').classList.add('active'); }
function closeCancelRescheduleModal() { document.getElementById('cancelRescheduleModal').classList.remove('active'); }
function submitCancelReschedule() {
    const bookingId = <?= $bookingID ?>;
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

document.addEventListener('DOMContentLoaded', function() {
    const otherRadio = document.getElementById('otherReasonRadio');
    const otherText = document.getElementById('otherReasonText');
    if (otherRadio) otherRadio.addEventListener('change', function() {
        if (otherText) otherText.style.display = this.checked ? 'block' : 'none';
    });
});

let toastTimer;
function showToast(msg) {
    const t = document.getElementById('toast');
    if (t) {
        t.textContent = msg;
        t.classList.add('show');
        clearTimeout(toastTimer);
        toastTimer = setTimeout(() => t.classList.remove('show'), 2500);
    }
}

function openCancelModalWithRefund(refundType) {
    const cancelForm = document.getElementById('cancelForm');
    if (cancelForm) {
        cancelForm.setAttribute('data-refund', refundType);
        let refundInput = document.getElementById('refund_type_input');
        if (!refundInput) {
            refundInput = document.createElement('input');
            refundInput.type = 'hidden';
            refundInput.name = 'refund_type';
            refundInput.id = 'refund_type_input';
            cancelForm.appendChild(refundInput);
        }
        refundInput.value = refundType;
        const modalTitle = document.querySelector('#cancelModal h3');
        const modalMessage = document.querySelector('#cancelModal p');
        if (refundType === 'full') {
            if (modalTitle) modalTitle.innerHTML = '<i class="bi bi-cash-stack" style="color: #28a745;"></i> Cancel Booking (Full Refund)';
            if (modalMessage) modalMessage.innerHTML = '✅ You will receive a <strong style="color: #28a745;">FULL REFUND</strong> because you are cancelling more than 24 hours before the session.<br><br>Please select a reason for cancelling:';
        } else {
            if (modalTitle) modalTitle.innerHTML = '<i class="bi bi-exclamation-triangle" style="color: #f59e0b;"></i> Cancel Booking (No Refund)';
            if (modalMessage) modalMessage.innerHTML = '<strong style="color: #f59e0b;">⚠️ WARNING: No refund will be issued</strong> because you are cancelling less than 24 hours before the session.<br><br>Please select a reason for cancelling:';
        }
    }
    document.getElementById('cancelModal').classList.add('active');
}function showReportIssue(bookingId) {
    // Check if session is online or face-to-face
    const isOnline = document.querySelector('.detail-item .dval')?.innerText === 'Online' || 
                     document.body.innerText.includes('Online');
    
    const modal = document.createElement('div');
    modal.id = 'reportModal';
    modal.className = 'modal-overlay active';
    modal.innerHTML = `
        <div class="modal-box">
            <h3><i class="bi bi-exclamation-triangle"></i> Report Issue</h3>
            <p>Please describe the issue you experienced:</p>
            <form id="reportForm" method="POST" action="report_issue.php" enctype="multipart/form-data">
                <input type="hidden" name="booking_id" value="${bookingId}">
                <div class="form-group" style="margin-bottom: 15px;">
                    <label style="display:block; font-weight:700; margin-bottom:8px;">Issue Type <span style="color:red;">*</span></label>
                    <select name="issue_type" id="reportIssueType" required style="width:100%; padding:10px; border-radius:8px; border:1px solid #ddd;">
                        <option value="">Select issue type</option>
                        <option value="tutor_no_show">Tutor didn't show up</option>
                        <option value="wrong_materials">Wrong materials provided</option>
                        <option value="other">Other issue</option>
                    </select>
                </div>
                <div class="form-group" style="margin-bottom: 15px;">
                    <label style="display:block; font-weight:700; margin-bottom:8px;">Description <span style="color:red;">*</span></label>
                    <textarea name="message" placeholder="Please describe in detail what happened..." required style="width:100%; padding:10px; border-radius:8px; border:1px solid #ddd; min-height:100px;"></textarea>
                </div>
                <div class="form-group" id="proofRequiredGroup" style="margin-bottom: 15px; display: none;">
                    <label style="display:block; font-weight:700; margin-bottom:8px;">
                        Upload Proof <span style="color:red;" id="proofRequiredStar">*</span>
                    </label>
                    <input type="file" name="proof" id="proofFile" accept="image/jpeg,image/png,application/pdf" style="width:100%; padding:8px;">
                    <small style="color:#666; display:block; margin-top:5px;" id="proofHint">
                        ${isOnline ? 
                            '⚠️ <strong>REQUIRED:</strong> Upload screenshot showing you waited (e.g., waiting room, error message, chat history)' : 
                            '⚠️ <strong>REQUIRED:</strong> Upload photo at the meeting location as proof you waited'}
                    </small>
                </div>
                <div class="modal-actions">
                    <button type="button" onclick="closeReportModal()" class="btn-secondary" style="padding:10px 20px; border-radius:30px; background:#64748b; color:white; border:none; cursor:pointer;">Cancel</button>
                    <button type="submit" class="btn-primary" style="padding:10px 20px; border-radius:30px; background:#dc2626; color:white; border:none; cursor:pointer;">Submit Report</button>
                </div>
            </form>
        </div>
    `;
    document.body.appendChild(modal);
    
    // Add change listener to show/hide proof requirement
    const issueTypeSelect = document.getElementById('reportIssueType');
    const proofGroup = document.getElementById('proofRequiredGroup');
    const proofFile = document.getElementById('proofFile');
    const proofRequiredStar = document.getElementById('proofRequiredStar');
    
    if (issueTypeSelect) {
        issueTypeSelect.addEventListener('change', function() {
            if (this.value === 'tutor_no_show') {
                proofGroup.style.display = 'block';
                proofFile.setAttribute('required', 'required');
                proofRequiredStar.style.display = 'inline';
            } else {
                proofGroup.style.display = 'none';
                proofFile.removeAttribute('required');
                proofRequiredStar.style.display = 'none';
            }
        });
    }
}

// Helper function to check if element contains text (used for learning mode detection)
function closeReportModal() {
    const modal = document.getElementById('reportModal');
    if (modal) modal.remove();
}

function recordMeetingLeave(bookingId) {
    if (confirm('Are you sure you want to end this session? This will record your leave time.')) {
        window.location.href = `record_meeting_leave.php?booking_id=${bookingId}`;
    }
}

function checkAndJoinMeeting(bookingId, meetingLink) {
    window.location.href = `join_meeting.php?booking_id=${bookingId}&link=${encodeURIComponent(meetingLink)}`;
}

function contactTutor(tutorId, tutorName, tutorPhone, studentName, bookingId, language, issueType) {
    console.log("Contact Tutor called with:", {tutorId, tutorName, tutorPhone, studentName, bookingId, language, issueType});
    
    // Check if phone number exists
    if (!tutorPhone || tutorPhone === '') {
        showToast('Tutor phone number not available. Please contact support.');
        return;
    }
    
    // Clean the phone number (remove spaces, dashes, etc.)
    let cleanPhone = tutorPhone.toString().replace(/[^0-9+]/g, '');
    
    // Ensure it starts with country code (add 60 for Malaysia if no +)
    if (!cleanPhone.startsWith('+') && !cleanPhone.startsWith('60') && cleanPhone.startsWith('0')) {
        cleanPhone = '60' + cleanPhone.substring(1);
    }
    
    // Build message
    let message = `Hello ${tutorName},\n\n`;
    message += `I'm ${studentName} regarding our ${language} session (Booking #${bookingId}).\n\n`;
    
    if (issueType === 'meeting_link') {
        message += `Could you please provide the meeting link for our session? Thank you!`;
    } else {
        message += `Issue: ${issueType}\n\nPlease help resolve this. Thank you!`;
    }
    
    const encodedMessage = encodeURIComponent(message);
    const whatsappUrl = `https://wa.me/${cleanPhone}?text=${encodedMessage}`;
    
    console.log("Opening WhatsApp URL:", whatsappUrl);
    window.open(whatsappUrl, '_blank');
}

const cancelModal = document.getElementById('cancelModal');
if (cancelModal) cancelModal.addEventListener('click', function(e) { if (e.target === this) closeCancelModal(); });

const cancelForm = document.getElementById('cancelForm');
if (cancelForm) {
    cancelForm.addEventListener('submit', function(e) {
        const selectedReason = document.querySelector('input[name="cancel_reason"]:checked');
        if (!selectedReason) { e.preventDefault(); alert('Please select a reason for cancellation'); return; }
        let cancelReason = selectedReason.value;
        if (selectedReason.value === 'Other') {
            const otherText = document.getElementById('otherReasonText').value.trim();
            if (!otherText) { e.preventDefault(); alert('Please specify your reason'); return; }
            cancelReason = 'Other: ' + otherText;
            selectedReason.value = cancelReason;
        }
        const refundType = this.getAttribute('data-refund');
        if (refundType) {
            let refundInput = document.getElementById('refund_type_input');
            if (!refundInput) {
                refundInput = document.createElement('input');
                refundInput.type = 'hidden';
                refundInput.name = 'refund_type';
                refundInput.id = 'refund_type_input';
                this.appendChild(refundInput);
            }
            refundInput.value = refundType;
        }
    });
}
</script>
</body>
</html>