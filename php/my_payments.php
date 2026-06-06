<?php
session_start();
include 'config.php';
$assetBase = '../assets/img';

if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit(); }
$userID = $_SESSION['user_id'];

$stmt = $conn->prepare("SELECT * FROM users WHERE id = ? AND role = 'student'");
$stmt->bind_param("i", $userID);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
if (!$user) { header("Location: login.php"); exit(); }

$displayName = $user['fullname'];
$profilePic  = !empty($user['profile_pic']) ? '../uploads/profiles/' . $user['profile_pic'] : $assetBase . '/profile-student.png';

// Filters
$filterStatus = $_GET['status'] ?? 'all';
$filterFrom   = $_GET['date_from'] ?? '';
$filterTo     = $_GET['date_to']   ?? '';
$sortBy       = $_GET['sort']      ?? 'newest';

// Build filter conditions
$filterWhere  = "";
$filterParams = [];
$filterTypes  = "";

if ($filterStatus !== 'all' && in_array($filterStatus, ['pending','verified','rejected','disputed'])) {
    $filterWhere   .= " AND p.status = ?";
    $filterParams[] = $filterStatus;
    $filterTypes   .= "s";
} elseif ($filterStatus === 'awaiting') {
     $filterWhere .= " AND b.status = 'accepted' AND p.id IS NULL";
}

if ($filterFrom) {
    $filterWhere   .= " AND b.booking_date >= ?";
    $filterParams[] = $filterFrom;
    $filterTypes   .= "s";
}
if ($filterTo) {
    $filterWhere   .= " AND b.booking_date <= ?";
    $filterParams[] = $filterTo;
    $filterTypes   .= "s";
}

$orderBy = $sortBy === 'oldest'
    ? "ORDER BY b.booking_date ASC, b.booking_time ASC"
    : "ORDER BY b.booking_date DESC, b.booking_time DESC";
// Main query — add tutor_profiles for rate fallback
$stmt = $conn->prepare("
    SELECT
        p.id            AS payment_id,
        p.amount,
        p.payment_method,
        p.status        AS payment_status,
        p.receipt_number,
        p.notes,
        p.rejection_type,
        p.actual_paid_amount,
        p.created_at    AS paid_at,
        p.proof_image,
                p.refund_status,        
        p.refund_receipt_number,
        b.id            AS booking_id,
        b.language,
        b.tutor_id,
        b.booking_date,
        b.booking_time,
        b.learning_mode,
        b.status        AS booking_status,
        COALESCE(b.total_amount, tp.rate) AS total_amount,
        u.fullname      AS tutor_name,
        u.profile_pic   AS tutor_pic
    FROM bookings b
    JOIN users u ON b.tutor_id = u.id
    JOIN tutor_profiles tp ON b.tutor_id = tp.user_id
    LEFT JOIN payments p ON b.id = p.booking_id
    WHERE b.student_id = ?
      AND (
          p.status IN ('verified','failed','disputed','pending','rejected')
          OR
          (b.status = 'accepted' AND p.id IS NULL)
          OR
          (b.status = 'disputed' AND p.id IS NULL)
      )
      $filterWhere
    $orderBy
");

$allParams  = array_merge([$userID], $filterParams);
$allTypes   = "i" . $filterTypes;
$stmt->bind_param($allTypes, ...$allParams);
$stmt->execute();
$payments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// DEBUG - Show all payments
echo "<!-- RAW PAYMENTS COUNT: " . count($payments) . " -->";
foreach ($payments as $debug_p) {
    echo "<!-- Payment: ID={$debug_p['payment_id']}, Status={$debug_p['payment_status']}, BookingID={$debug_p['booking_id']}, Amount={$debug_p['amount']} -->";
}
// Combine partial payments (rejected + verified) into one verified payment
$combined_payments = [];
$processed_bookings = [];

foreach ($payments as $p) {
    $booking_id = $p['booking_id'];
    
    // If this booking already has a verified payment, combine with rejected payment
    if ($p['payment_status'] === 'verified') {
        // Look for a rejected payment for the same booking
        $rejected_payment = null;
        $total_paid = $p['amount'];
        
        foreach ($payments as $rp) {
            if ($rp['booking_id'] == $booking_id && $rp['payment_status'] === 'rejected') {
                $rejected_payment = $rp;
                $total_paid += $rp['actual_paid_amount'] ?? $rp['amount'];
                break;
            }
        }
        
        if ($rejected_payment) {
            // Create a combined payment entry
            $combined = $p;
            $combined['amount'] = $total_paid;  // Total paid = 23.50 + 21.50 = 45.00
            $combined['payment_method'] = 'stripe';  // Show as Stripe
            $combined['notes'] = "Payment completed in two parts: Partial payment (RM " . number_format($rejected_payment['actual_paid_amount'] ?? $rejected_payment['amount'], 2) . ") + Remaining payment (RM " . number_format($p['amount'], 2) . ")";
            $combined_payments[] = $combined;
            $processed_bookings[] = $booking_id;
        } else {
            $combined_payments[] = $p;
            $processed_bookings[] = $booking_id;
        }
    } 
    // Skip rejected payments that have a verified payment (they will be combined above)
    elseif ($p['payment_status'] === 'rejected' && in_array($booking_id, $processed_bookings)) {
        continue;
    }
    else {
        $combined_payments[] = $p;
    }
}

$payments = $combined_payments;
$stmt->close();

$cntStmt = $conn->prepare("
    SELECT p.status, COUNT(*) as cnt, SUM(p.amount) as total
    FROM payments p
    WHERE p.student_id = ?
    GROUP BY p.status

    UNION ALL

    SELECT 'pending_booking' as status, COUNT(*) as cnt, SUM(tp.rate) as total
    FROM bookings b
    JOIN tutor_profiles tp ON b.tutor_id = tp.user_id
    WHERE b.student_id = ?
      AND b.status = 'accepted'
      AND NOT EXISTS (SELECT 1 FROM payments WHERE booking_id = b.id)
    
    UNION ALL
    
    SELECT 'disputed_booking' as status, COUNT(*) as cnt, SUM(b.total_amount) as total
    FROM bookings b
    WHERE b.student_id = ?
      AND b.status = 'disputed'
      AND NOT EXISTS (SELECT 1 FROM payments WHERE booking_id = b.id)
");
$cntStmt->bind_param("iii", $userID, $userID, $userID);
$cntStmt->execute();
$cntResult = $cntStmt->get_result();

$counts = ['all'=>0,'pending'=>0,'verified'=>0,'failed'=>0,'rejected'=>0,'disputed'=>0,'pending_booking'=>0,'disputed_booking'=>0];
$totals = ['pending'=>0,'verified'=>0,'failed'=>0,'rejected'=>0,'disputed'=>0,'pending_booking'=>0,'disputed_booking'=>0];

while ($row = $cntResult->fetch_assoc()) {
    $status = $row['status'];
    if (isset($counts[$status])) {
        $counts[$status] = (int)$row['cnt'];
        if (isset($totals[$status])) $totals[$status] = (float)$row['total'];
    }
}

$counts['all'] = $counts['pending'] + $counts['verified'] + $counts['failed'] + $counts['disputed'] + $counts['pending_booking'];


// Get awaiting payments (bookings with no payment record)
$awaitingStmt = $conn->prepare("
    SELECT 
        COUNT(*) as cnt,
        SUM(b.total_amount) as total
    FROM bookings b
    WHERE b.student_id = ? 
    AND b.status = 'accepted'
    AND NOT EXISTS (SELECT 1 FROM payments WHERE booking_id = b.id)
");
$awaitingStmt->bind_param("i", $userID);
$awaitingStmt->execute();
$awaitingResult = $awaitingStmt->get_result()->fetch_assoc();

$counts['awaiting'] = $awaitingResult['cnt'] ?? 0;
$totals['awaiting'] = $awaitingResult['total'] ?? 0;
$counts['all'] += $counts['awaiting'];
function e($v){ return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }

function paymentCfg($s) {
    return [
        'verified' => ['label'=>'Verified',   'icon'=>'bi-check-circle-fill', 'bg'=>'rgba(215,238,219,.78)', 'color'=>'#3D7047'],
        'pending'  => ['label'=>'Pending',     'icon'=>'bi-hourglass-split',   'bg'=>'rgba(255,241,200,.78)', 'color'=>'#A06B00'],
                'rejected' => ['label'=>'Rejected',    'icon'=>'bi-x-circle-fill',     'bg'=>'rgba(255,200,200,.78)', 'color'=>'#C94F4F'],
        'failed'   => ['label'=>'Failed',      'icon'=>'bi-x-circle-fill',     'bg'=>'rgba(255,200,200,.78)', 'color'=>'#C94F4F'],
    ][$s] ?? ['label'=>'Unknown','icon'=>'bi-question','bg'=>'#eee','color'=>'#999'];
}

function methodIcon($m) {
    $map = ['stripe'=>'💳','online_banking'=>'🏦','duitnow'=>'📱','cash'=>'💵'];
    return $map[strtolower($m)] ?? '💳';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>My Payments · Kyoshi</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
  <style>
    :root{
      --cream:#FFF1F6;--paper:rgba(255,255,255,.88);--ink:#342635;--muted:#7B6178;
      --pink:#F28AB2;--pink-dark:#C94F86;--hot-pink:#E75A9B;
      --shadow:0 18px 45px rgba(201,79,134,.16);--radius-xl:32px;--radius-lg:24px;
    }

    /* Dispute Modal - Lower z-index */
#disputeModal {
    z-index: 100 !important;
}

#disputeModal .modal-container {
    z-index: 101 !important;
}

/* Override any higher z-index elements that should be above */
.bulk-action-bar {
    z-index: 200 !important;
}

.toast {
    z-index: 300 !important;
}

/* Make sure dropdown stays above */
#profileDropdown {
    z-index: 1000 !important;
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

    /* PAGE */
    .back-link{display:inline-flex;align-items:center;gap:6px;color:var(--pink-dark);font-weight:900;font-size:13px;padding:9px 16px;border-radius:999px;background:rgba(255,255,255,.78);border:1px solid rgba(46,42,59,.08);transition:.18s ease;margin-bottom:20px;}
    .back-link:hover{transform:translateY(-1px)}

    /* SUMMARY CARDS */
    .summary-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:20px;margin-bottom:24px}
    .sum-card{background:var(--paper);border:1px solid rgba(255,255,255,.55);box-shadow:var(--shadow);border-radius:20px;padding:18px 22px;display:flex;align-items:center;gap:16px}
    .sum-icon{width:48px;height:48px;border-radius:16px;display:grid;place-items:center;font-size:20px;flex-shrink:0}
    .sum-icon.verified{background:rgba(215,238,219,.6);color:#3D7047}
    .sum-icon.pending{background:rgba(255,241,200,.6);color:#A06B00}
    .sum-icon.failed{background:rgba(255,200,200,.6);color:#C94F4F}
    .sum-label{font-size:13px;font-weight:700;color:var(--muted);display:block;margin-bottom:5px}
    .sum-amount{font-size:20px;font-weight:900;color:var(--ink);display:block;line-height:1}
    .sum-count{font-size:12px;color:var(--muted);margin-top:4px;display:block}

    /* FILTER BAR */
    .filter-bar{background:var(--paper);border:1px solid rgba(255,255,255,.55);box-shadow:0 6px 20px rgba(201,79,134,.08);border-radius:var(--radius-lg);padding:20px 24px;margin-bottom:20px;display:flex;flex-wrap:wrap;gap:16px;align-items:flex-end;}
    .filter-group{display:flex;flex-direction:column;gap:6px;flex:1;min-width:150px}
    .filter-group label{font-size:12px;font-weight:900;color:var(--muted);text-transform:uppercase;letter-spacing:.5px}
    .filter-select,.filter-input{padding:11px 15px;border:1px solid rgba(46,42,59,.12);border-radius:14px;outline:none;font-size:14px;font-weight:700;color:var(--ink);background:rgba(255,255,255,.9)}
    .filter-select:focus,.filter-input:focus{border-color:var(--hot-pink);box-shadow:0 0 0 3px rgba(231,90,155,.12)}
    .btn-reset{padding:11px 22px;border-radius:999px;border:1px solid rgba(46,42,59,.12);background:none;color:var(--muted);font-size:14px;font-weight:900;cursor:pointer;white-space:nowrap;align-self:flex-end}
    .btn-reset:hover{background:rgba(255,255,255,.88)}

    /* BULK SELECT TOOLBAR */
    .bulk-toolbar{background:var(--paper);border:1px solid rgba(242,138,178,.25);box-shadow:0 6px 20px rgba(201,79,134,.10);border-radius:var(--radius-lg);padding:16px 24px;margin-bottom:20px;display:flex;flex-wrap:wrap;align-items:center;gap:16px;}
    .bulk-toolbar .tb-group{display:flex;align-items:center;gap:12px;flex:1;flex-wrap:wrap}
    .select-all-btn{padding:10px 20px;border-radius:999px;font-size:13px;font-weight:900;cursor:pointer;border:1px solid rgba(231,90,155,.3);background:rgba(255,241,246,.7);color:var(--pink-dark);transition:.15s ease;display:inline-flex;align-items:center;gap:8px}
    .select-all-btn.active{background:linear-gradient(135deg,var(--hot-pink),var(--pink));color:#fff;border-color:transparent}
    .tb-count{font-size:13px;color:var(--muted);font-weight:700}
    .tb-count strong{color:var(--ink);font-size:16px}

    /* PAYMENT CARDS - BIGGER */
    .payment-list{display:flex;flex-direction:column;gap:20px;padding-bottom:100px}
    .payment-card{background:var(--paper);border:1px solid rgba(255,255,255,.55);box-shadow:0 8px 24px rgba(201,79,134,.10);border-radius:var(--radius-lg);padding:28px 32px;transition:.18s ease;position:relative;}
    .payment-card:hover{transform:translateY(-3px);box-shadow:var(--shadow)}
    .payment-card.selected{border-color:var(--hot-pink);box-shadow:0 0 0 3px rgba(231,90,155,.18),var(--shadow)}

    .pay-top{display:flex;align-items:center;gap:20px;margin-bottom:20px}
    .tutor-img{width:70px;height:70px;object-fit:cover;border-radius:20px;background:#eee;flex-shrink:0}
    .pay-top-info{flex:1}
    .pay-top-info h4{margin:0 0 6px;font-size:18px;font-weight:900}
    .pay-top-info .sub{margin:0;color:var(--muted);font-size:14px}
    .status-badge{display:inline-flex;align-items:center;gap:8px;padding:10px 18px;border-radius:999px;font-size:13px;font-weight:900;white-space:nowrap;flex-shrink:0}

    .pay-body{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:20px}
    .pay-item{background:rgba(255,241,246,.5);border:1px solid rgba(242,138,178,.12);border-radius:16px;padding:14px 18px}
    .pay-item .lbl{font-size:11px;font-weight:900;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px}
    .pay-item .val{font-size:15px;font-weight:900;color:var(--ink)}
    .pay-item .val.amount{color:var(--hot-pink);font-size:22px}

    .pay-actions{display:flex;gap:12px;flex-wrap:wrap;padding-top:18px;border-top:1px solid rgba(46,42,59,.06)}
    .btn-action{padding:11px 22px;border-radius:999px;font-size:13px;font-weight:900;cursor:pointer;display:inline-flex;align-items:center;gap:8px;transition:.15s ease;text-decoration:none;border:none;white-space:nowrap}
    .btn-action:hover{transform:translateY(-2px)}
    .btn-action.primary{background:linear-gradient(135deg,var(--hot-pink),var(--pink));color:white;box-shadow:0 6px 14px rgba(231,90,155,.25)}
    .btn-action.ghost{background:white;color:var(--pink-dark);border:1px solid rgba(201,79,134,.25)}
    .btn-action.purple{background:rgba(221,211,255,.8);color:#7648B8;border:1px solid rgba(167,123,232,.2)}
    .btn-action.muted{background:rgba(255,255,255,.7);color:var(--muted);border:1px solid rgba(46,42,59,.10);cursor:default}

    .empty-state{padding:60px 30px;border-radius:var(--radius-lg);background:var(--paper);border:1px dashed rgba(46,42,59,.16);color:#6D647C;text-align:center;font-weight:700}
    .empty-state i{font-size:48px;color:rgba(231,90,155,.3);display:block;margin-bottom:16px}
          /* Image Modal Styles */
      #imageModal .modal-overlay {
          position: fixed;
          top: 0;
          left: 0;
          width: 100%;
          height: 100%;
          background: rgba(0,0,0,0.8);
          z-index: 3000;
          display: flex;
          align-items: center;
          justify-content: center;
          display: none;
      }

      #imageModal img {
          max-width: 90%;
          max-height: 80vh;
          object-fit: contain;
      }

      /* Make sure this exists for the image modal */
#imageModal {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.85);
    z-index: 3000;
    display: none;
    align-items: center;
    justify-content: center;
}

#imageModal .modal-content {
    background: transparent;
    max-width: 90%;
    max-height: 90%;
    position: relative;
}

#imageModal img {
    max-width: 100%;
    max-height: 80vh;
    object-fit: contain;
    border-radius: 8px;
}

.close-image-btn {
    position: absolute;
    top: -40px;
    right: 0;
    background: #dc2626;
    color: white;
    border: none;
    width: 35px;
    height: 35px;
    border-radius: 50%;
    cursor: pointer;
    font-size: 20px;
    display: flex;
    align-items: center;
    justify-content: center;
}
    /* STICKY BULK ACTION BAR */
    .bulk-action-bar{position:fixed;bottom:0;left:0;right:0;z-index:60;padding:0 20px 20px;pointer-events:none;transition:.3s ease}
    .bulk-action-inner{max-width:1200px;margin:0 auto;background:rgba(52,38,53,.96);backdrop-filter:blur(20px);border-radius:var(--radius-lg);padding:18px 28px;display:flex;align-items:center;gap:20px;flex-wrap:wrap;box-shadow:0 -4px 40px rgba(201,79,134,.3);border:1px solid rgba(231,90,155,.3);pointer-events:all;transform:translateY(120%);transition:.32s cubic-bezier(.34,1.56,.64,1);opacity:0}
    .bulk-action-bar.visible .bulk-action-inner{transform:translateY(0);opacity:1}
    .bulk-info{flex:1}
    .bulk-info .bi-line{font-size:13px;color:rgba(255,255,255,.6);margin-bottom:4px}
    .bulk-info .b-count{font-size:18px;font-weight:900;color:#fff}
    .bulk-info .b-amount{font-size:14px;color:var(--pink);font-weight:700;margin-left:8px}
    .bulk-btns{display:flex;gap:12px;flex-wrap:wrap}
    .bulk-btn{padding:12px 26px;border-radius:999px;font-size:14px;font-weight:900;cursor:pointer;display:inline-flex;align-items:center;gap:8px;transition:.18s ease;border:none;white-space:nowrap}
    .bulk-btn:hover{transform:translateY(-2px)}
    .bulk-btn.pay{background:linear-gradient(135deg,#7648B8,#A47DE0);color:#fff}
    .bulk-btn.download{background:linear-gradient(135deg,var(--hot-pink),var(--pink));color:#fff}
    .bulk-btn.clear{background:rgba(255,255,255,.12);color:rgba(255,255,255,.7);border:1px solid rgba(255,255,255,.15)}
    .bulk-btn.clear:hover{background:rgba(255,255,255,.2)}

    .toast{position:fixed;left:50%;bottom:28px;transform:translate(-50%,18px);opacity:0;pointer-events:none;z-index:99;background:#8E3F70;color:#fff;border-radius:999px;padding:14px 22px;font-size:14px;font-weight:900;transition:.2s ease}
    .toast.show{opacity:1;transform:translate(-50%,0)}

    .selection-mode .payment-card{cursor:pointer}
    .selection-mode .payment-card:hover{transform:translateY(-3px);box-shadow:var(--shadow)}
    .selection-mode .payment-card.selected{border:2px solid var(--hot-pink);background:rgba(255,167,211,0.9)}

    .selection-mode-indicator{position:fixed;bottom:0;left:0;right:0;background:linear-gradient(135deg,#1a1a2e,#16213e);color:white;padding:20px 30px;display:flex;justify-content:space-between;align-items:center;box-shadow:0 -4px 20px rgba(0,0,0,0.3);z-index:1000;animation:slideUp 0.3s ease-out}
    @keyframes slideUp{from{transform:translateY(100%)}to{transform:translateY(0)}}
    .selection-mode-indicator .cancel-btn{background:rgba(255,255,255,0.15);border:none;color:white;padding:12px 24px;border-radius:40px;cursor:pointer;font-size:14px;font-weight:700;transition:all 0.2s}
    .selection-mode-indicator .cancel-btn:hover{transform:translateY(-2px);background:rgba(255,23,23,0.3)}
    .selection-mode-indicator .cancel-btn:last-child{background:#28a745}
    .selection-mode-indicator .cancel-btn:last-child:hover{background:#34ce57}

    @media(max-width:900px){
      .summary-grid{grid-template-columns:1fr}
      .pay-body{grid-template-columns:1fr 1fr}
      .nav{flex-wrap:wrap}
      .nav-links{order:3;width:100%;margin-top:12px}
      .brand strong{font-size:20px}
      .brand img{width:46px;height:46px}
      .profile img{width:38px;height:38px}
    }
    @media(max-width:600px){
      .pay-body{grid-template-columns:1fr}
      .bulk-btns{width:100%}
      .nav-links a{padding:8px 16px;font-size:13px}
    }
  </style>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
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
          <a  href="booking_status.php">My Bookings</a>
          <a class="active" href="my_payments.php">My Payments</a>
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

<div class="container" style="padding:24px 0 60px;">

  <div style="position:relative;text-align:center;margin-bottom:20px;">
    <a href="student_dashboard.php" class="back-link" style="position:absolute;left:0;top:50%;transform:translateY(-50%);margin:0;">
      <i class="bi bi-arrow-left"></i> Back
    </a>
    <h1 style="margin:0;font-size:32px;letter-spacing:-.6px;">My Payments</h1>
    <p style="margin:8px 0 0;color:var(--muted);font-size:15px;">Track all your payment records and download receipts.</p>
  </div>


  <!-- CONSOLIDATED REMAINING PAYMENT (for bulk underpaid bookings) -->
<?php 
// Check for pending refunds (overpaid but not yet refunded)
$pending_refund_stmt = $conn->prepare("
    SELECT COUNT(*) as count, SUM(actual_paid_amount - amount) as total
    FROM payments 
    WHERE student_id = ? 
    AND status = 'verified' 
    AND refund_status = 'pending' 
    AND actual_paid_amount > amount
");
$pending_refund_stmt->bind_param("i", $userID);
$pending_refund_stmt->execute();
$pending_refund = $pending_refund_stmt->get_result()->fetch_assoc();
$pending_refund_count = $pending_refund['count'] ?? 0;
$pending_refund_total = $pending_refund['total'] ?? 0;
?>

<?php if ($pending_refund_count > 0): ?>
<div style="background: linear-gradient(135deg, #fef3c7, #fffbeb); border: 1px solid #f59e0b; border-radius: 16px; padding: 16px 20px; margin-bottom: 20px;">
    <div style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
        <i class="bi bi-cash-stack" style="font-size: 24px; color: #f59e0b;"></i>
        <div>
            <strong style="color: #d97706;">Pending Refund</strong>
            <p style="margin: 0; font-size: 13px;">You have <strong><?= $pending_refund_count ?></strong> overpayment(s) being processed.</p>
        </div>
        <div style="margin-left: auto;">
            <span style="background: #f59e0b; color: white; padding: 6px 12px; border-radius: 20px; font-weight: 700;">
                RM <?= number_format($pending_refund_total, 2) ?>
            </span>
        </div>
    </div>
    <p style="margin: 10px 0 0 0; font-size: 12px; color: #92400e;">
        <i class="bi bi-clock-history"></i> 
        Refunds are typically processed within 3-5 business days. You'll receive an email confirmation once processed.
    </p>
</div>
<?php endif; ?>
<?php
// Check for consolidated remaining payments from bulk underpaid
$remainingStmt = $conn->prepare("
    SELECT p.*, p.notes 
    FROM payments p
    WHERE p.student_id = ? 
    AND p.status = 'pending'
    AND p.payment_method = 'remaining_balance'
    AND p.notes LIKE '%REMAINING BALANCE for bookings:%'
");
$remainingStmt->bind_param("i", $userID);
$remainingStmt->execute();
$remainingPayments = $remainingStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$remainingStmt->close();

foreach ($remainingPayments as $rp) {
    // Extract booking IDs from notes
    preg_match('/REMAINING BALANCE for bookings?:? ?([\d,]+)/', $rp['notes'], $matches);
    $booking_ids = isset($matches[1]) ? explode(',', $matches[1]) : [];
    
    // Get booking details to show summary
    $bookingDetails = [];
    $totalExpected = 0;
    if (!empty($booking_ids)) {
        $ids = implode(',', array_map('intval', $booking_ids));
        $detailQuery = $conn->query("
            SELECT b.id, b.language, u.fullname as tutor_name, tp.rate
            FROM bookings b
            JOIN users u ON b.tutor_id = u.id
            JOIN tutor_profiles tp ON b.tutor_id = tp.user_id
            WHERE b.id IN ($ids)
        ");
        while ($detail = $detailQuery->fetch_assoc()) {
            $bookingDetails[] = $detail;
            $totalExpected += $detail['rate'];
        }
    }
    
    $remaining_amount = $rp['amount'];
?>
<div class="payment-card" style="background: linear-gradient(135deg, #fef3c7, #fffbeb); border: 2px solid #f59e0b; margin-bottom: 20px;">
    <div class="pay-top">
        <div class="pay-top-info" style="flex: 1;">
            <h4 style="color: #f59e0b; margin: 0 0 8px 0;">
                <i class="bi bi-cash-stack"></i> Complete Your Bulk Payment
            </h4>
            <p style="margin: 0 0 5px 0;"><strong>You have a remaining balance of <span style="color: #dc2626; font-size: 18px;">RM <?= number_format($remaining_amount, 2) ?></span></strong></p>
            <p style="margin: 0 0 5px 0;">This covers <strong><?= count($booking_ids) ?></strong> booking(s):</p>
            <div style="margin-top: 8px; padding-left: 10px;">
                <?php foreach ($bookingDetails as $index => $bd): ?>
                    <div style="font-size: 13px; margin-bottom: 4px;">
                        • <?= e($bd['language']) ?> with <?= e($bd['tutor_name']) ?> - RM <?= number_format($bd['rate'], 2) ?>
                    </div>
                <?php endforeach; ?>
            </div>
            <p style="margin-top: 8px; font-size: 12px; color: #666;">Total expected: RM <?= number_format($totalExpected, 2) ?> | Already paid: RM <?= number_format($totalExpected - $remaining_amount, 2) ?></p>
        </div>
    </div>
    <div class="pay-actions" style="margin-top: 15px;">
        <a href="payment_form.php?payment_id=<?= $rp['id'] ?>&type=bulk_remaining&booking_ids=<?= implode(',', $booking_ids) ?>" class="btn-primary" style="background: linear-gradient(135deg, #f59e0b, #dc2626);">
            <i class="bi bi-cash-stack"></i> Pay Remaining Balance (RM <?= number_format($remaining_amount, 2) ?>)
        </a>
    </div>
</div>
<?php } ?>
  <!-- FILTER BAR -->
  <form method="GET" class="filter-bar">
    <div class="filter-group">
      <label><i class="bi bi-funnel"></i> Status</label>
      <select name="status" class="filter-select" onchange="this.form.submit()">
    <option value="all" <?= $filterStatus==='all'?'selected':'' ?>>All (<?= $counts['all'] ?>)</option>
    <option value="awaiting" <?= $filterStatus==='awaiting'?'selected':'' ?>>Awaiting Payment (<?= $counts['awaiting'] ?>)</option>
    <option value="pending" <?= $filterStatus==='pending'?'selected':'' ?>>Pending Verification (<?= $counts['pending'] ?>)</option>
    <option value="verified" <?= $filterStatus==='verified'?'selected':'' ?>>Verified (<?= $counts['verified'] ?>)</option>
    <option value="rejected" <?= $filterStatus==='rejected'?'selected':'' ?>>Rejected (<?= $counts['rejected'] ?? 0 ?>)</option>  <!-- ADD THIS -->
    <option value="disputed" <?= $filterStatus==='disputed'?'selected':'' ?>>Disputed (<?= $counts['disputed'] ?? 0 ?>)</option>
</select>
    </div>
    <div class="filter-group">
      <label><i class="bi bi-calendar3"></i> From</label>
      <input type="date" name="date_from" class="filter-input" value="<?= e($filterFrom) ?>" onchange="this.form.submit()">
    </div>
    <div class="filter-group">
      <label><i class="bi bi-calendar3"></i> To</label>
      <input type="date" name="date_to" class="filter-input" value="<?= e($filterTo) ?>" onchange="this.form.submit()">
    </div>
    <div class="filter-group">
      <label><i class="bi bi-sort-down"></i> Sort</label>
      <select name="sort" class="filter-select" onchange="this.form.submit()">
        <option value="newest" <?= $sortBy==='newest'?'selected':'' ?>>Newest First</option>
        <option value="oldest" <?= $sortBy==='oldest'?'selected':'' ?>>Oldest First</option>
      </select>
    </div>
    <a href="my_payments.php" class="btn-reset"><i class="bi bi-x"></i> Reset</a>
  </form>
  
  <div style="display: flex; justify-content: flex-end; margin-bottom: 20px; align-items: center; gap: 12px; flex-wrap: wrap;">
   <span style="font-size: 12px; color: var(--muted);">
        <i class="bi bi-info-circle"></i> Only awaiting payments can be selected
    </span>  
  <button class="btn-primary" onclick="toggleSelectionMode()" id="selectModeBtn" style="background: linear-gradient(135deg, var(--hot-pink), var(--pink)); padding: 12px 28px; border-radius: 40px; border: none; color: white; font-weight: 800; cursor: pointer; display: flex; align-items: center; gap: 8px; font-size: 14px;">
      <i class="bi bi-check2-square"></i> Select More to Pay
    </button>
  </div>

  <!-- PAYMENT LIST -->
  <div class="payment-list" id="paymentList">
    <?php if (empty($payments)): ?>
      <div class="empty-state">
        <i class="bi bi-receipt"></i>
        No payment records found.<br>
        <a href="booking_status.php" style="color:var(--hot-pink);font-weight:900;margin-top:12px;display:inline-block;">View Details →</a>
      </div>
    <?php else: ?>
      <?php foreach ($payments as $p):
      
        if (!$p['payment_id']): 
          $tPic = !empty($p['tutor_pic']) ? '../uploads/profiles/' . $p['tutor_pic'] : $assetBase . '/profile-tutor.png';
      ?><div class="payment-card" 
    id="card-booking-<?= $p['booking_id'] ?>"
    data-booking-id="<?= $p['booking_id'] ?>"
    data-amount="<?= $p['total_amount'] ?>"
    data-method="">
    
<!-- ONLY show checkbox for awaiting/pending payments, NOT verified ones -->
<?php if (empty($p['payment_status'])): ?>
<div style="position: absolute; top: 15px; left: 15px; z-index: 15;">
    <input type="checkbox" 
           id="chk-booking-<?= $p['booking_id'] ?>"
           class="pay-checkbox" 
           data-type="failed"
           data-booking-id="<?= $p['booking_id'] ?>"
           data-amount="<?= $p['total_amount'] ?>"
           onchange="onCheckboxChange(this)"
           style="width: 20px; height: 20px; cursor: pointer; display: none;">
</div>
<?php endif; ?>

    <div class="pay-top">
            <img src="<?= e($tPic) ?>" class="tutor-img" alt="<?= e($p['tutor_name']) ?>">
            <div class="pay-top-info">
              <h4><?= e($p['language']) ?> with <?= e($p['tutor_name']) ?></h4>
              <p class="sub">
                <i class="bi bi-calendar3"></i> Session: <?= date('D, d M Y', strtotime($p['booking_date'])) ?>
                &nbsp;·&nbsp;
                <i class="bi bi-clock"></i> <?= date('g:i A', strtotime($p['booking_time'])) ?>
                &nbsp;·&nbsp;
                <?= $p['learning_mode']==='online'?'💻 Online':'🤝 Face to Face' ?>
              </p>
            </div>
            <span class="status-badge" style="background:#fff3e0;color:#e67e22;">
              <i class="bi bi-hourglass-split"></i> Awaiting Payment
            </span>
          </div>
        
         <div class="pay-actions">
            <?php if ($p['payment_status'] !== 'rejected'): ?>
                <a href="booking_detail.php?id=<?= $p['booking_id'] ?>" class="btn-action primary">
                    <i class="bi bi-eye"></i> View Detals
                </a>
            <?php endif; ?>
            <a href="payment_form.php?booking_id=<?= $p['booking_id'] ?>" class="btn-action purple">
              <i class="bi bi-credit-card"></i> Pay Now
            </a>
          </div>
        </div>
      <?php 
        continue;
        endif;
        
        // Regular payment display
        $cfg = paymentCfg($p['payment_status']);
        $tPic = !empty($p['tutor_pic']) ? '../uploads/profiles/' . $p['tutor_pic'] : $assetBase . '/profile-tutor.png';
        $methodLabel = ucwords(str_replace('_', ' ', $p['payment_method'] ?? ''));
        $methodEmoji = methodIcon($p['payment_method'] ?? '');
        $isStripe = ($p['payment_method'] === 'stripe');
        $isOnlineBanking = in_array($p['payment_method'], ['online_banking', 'duitnow']);
        $isCash = ($p['payment_method'] === 'cash');
        $hasProof = !empty($p['proof_image']);
        $isFaceToFace = ($p['learning_mode'] === 'face_to_face');
        
        $statusIcon = 'bi-hourglass-split';
        $statusBg = 'rgba(255,241,200,.78)';
        $statusColor = '#A06B00';
        $statusLabel = 'Pending';
        
        if ($p['payment_status'] === 'verified') {
          $statusLabel = 'Verified';
          $statusIcon = 'bi-check-circle-fill';
          $statusBg = 'rgba(215,238,219,.78)';
          $statusColor = '#3D7047';
        } elseif ($p['payment_status'] === 'failed') {
          $statusLabel = 'Failed';
          $statusIcon = 'bi-x-circle-fill';
          $statusBg = 'rgba(255,200,200,.78)';
          $statusColor = '#C94F4F';
        } elseif ($p['payment_status'] === 'disputed') {
          $statusLabel = 'Disputed - Under Review';
          $statusIcon = 'bi-exclamation-triangle-fill';
          $statusBg = 'rgba(255,200,200,.78)';
          $statusColor = '#C94F4F';
          } elseif ($p['payment_status'] === 'rejected') {  // ← ADD THIS
          $statusLabel = 'Rejected';
          $statusIcon = 'bi-x-circle-fill';
          $statusBg = 'rgba(255,200,200,.78)';
          $statusColor = '#C94F4F';
        } elseif ($p['payment_status'] === 'pending') {
          if ($isStripe) {
            $statusLabel = 'Processing';
            $statusIcon = 'bi-arrow-repeat';
  
          } elseif ($isOnlineBanking && $hasProof) {
            $statusLabel = 'Waiting Verification';
            $statusIcon = 'bi-clock-history';
          } elseif ($isOnlineBanking && !$hasProof) {
            $statusLabel = 'Pending Payment';
            $statusIcon = 'bi-hourglass-split';
          } elseif ($isCash && $isFaceToFace) {
            $statusLabel = 'Pay Cash to Tutor';
            $statusIcon = 'bi-cash-stack';
          }
        }
      ?>
        <div class="payment-card" 
          id="card-<?= $p['payment_id'] ?>" 
          data-payment-id="<?= $p['payment_id'] ?>" 
          data-booking-id="<?= $p['booking_id'] ?>" 
          data-amount="<?= $p['amount'] ?>"
          data-method="<?= $p['payment_method'] ?>">
           <div style="position: absolute; top: 15px; left: 15px; z-index: 15;">
        <input type="checkbox" 
               id="chk-<?= $p['payment_id'] ?>" 
               class="pay-checkbox" 
               data-type="<?= ($p['payment_status'] === 'pending') ? 'failed' : 'verified' ?>"
               data-booking-id="<?= $p['booking_id'] ?>"
              <?php 
              $checkbox_amount = $p['amount'];
              if ($p['payment_status'] === 'rejected' && $p['actual_paid_amount'] > 0 && $p['actual_paid_amount'] < $p['amount']) {
                  $checkbox_amount = $p['amount'] - $p['actual_paid_amount'];
              }
              ?>
              data-amount="<?= $checkbox_amount ?>"
               data-receipt="receipt.php?booking_id=<?= $p['booking_id'] ?>"
               onchange="onCheckboxChange(this)"
               style="width: 20px; height: 20px; cursor: pointer; display: none;">
    </div>
    
          <div class="pay-top">
            <img src="<?= e($tPic) ?>" class="tutor-img" alt="<?= e($p['tutor_name']) ?>">
            <div class="pay-top-info">
              <h4><?= e($p['language']) ?> with <?= e($p['tutor_name']) ?></h4>
              <p class="sub">
                <i class="bi bi-calendar3"></i> Session: <?= date('D, d M Y', strtotime($p['booking_date'])) ?>
                &nbsp;·&nbsp;
                <i class="bi bi-clock"></i> <?= date('g:i A', strtotime($p['booking_time'])) ?>
                &nbsp;·&nbsp;
                <?= $p['learning_mode']==='online'?'💻 Online':'🤝 Face to Face' ?>
              </p>
            </div>
            <span class="status-badge" style="background:<?= $statusBg ?>;color:<?= $statusColor ?>;">
              <i class="bi <?= $statusIcon ?>"></i> <?= $statusLabel ?>
            </span>
          </div>
          
          <div class="pay-actions">
            <?php if ($p['payment_status'] !== 'rejected'): ?>
                <a href="booking_detail.php?id=<?= $p['booking_id'] ?>" class="btn-action primary">
                    <i class="bi bi-eye"></i> View Details
                </a>
            <?php endif; ?>

<?php if ($p['payment_status'] === 'verified'): ?>
    <?php 
    $overpaid = ($p['actual_paid_amount'] ?? 0) - $p['amount'];
    $bank_details_provided = strpos($p['notes'] ?? '', 'REFUND BANK DETAILS:') !== false;
    ?>

    <?php if ($overpaid > 0 && isset($p['refund_status']) && $p['refund_status'] == 'pending' && !$bank_details_provided): ?>
        <!-- Show button only if no bank details yet -->
        <div>
            <div style="display: flex; flex-direction: column; gap: 10px;">
                <button class="btn-action purple" onclick="provideBankDetails(<?= $p['payment_id'] ?>, <?= $overpaid ?>)" style="width: auto;">
                    Provide Bank Details for Refund
                </button>
            </div>
        </div>
    <?php elseif ($overpaid > 0 && isset($p['refund_status']) && $p['refund_status'] == 'pending' && $bank_details_provided): ?>
        <!-- Bank details already submitted, waiting for admin -->
        <div>
            <i class="bi bi-check-circle" style="color: #059669;"></i>
            <span style="font-weight: 700;">Bank Details Submitted</span>
            <p style="margin: 5px 0 0 0; font-size: 13px;">Your bank details have been received. The refund will be processed within 3-5 business days.</p>
        </div>
    <?php elseif ($overpaid > 0 && isset($p['refund_status']) && $p['refund_status'] == 'completed'): ?>
        <!-- Refund fully processed by admin -->
        <div>
            <i class="bi bi-check-circle" style="color: #059669;"></i>
            <span style="font-weight: 700;">Refund Processed:</span>
            <span>RM <?= number_format($overpaid, 2) ?> has been refunded.</span>
            <?php if (!empty($p['refund_receipt_number'])): ?>
                <br><small>Refund Receipt: <?= e($p['refund_receipt_number']) ?></small>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- Normal verified actions (download receipt, etc.) -->
    <?php if ($p['payment_method'] === 'stripe'): ?>
        <a href="receipt_stripe.php?booking_id=<?= $p['booking_id'] ?>&action=pdf" class="btn-action ghost">
            <i class="bi bi-download"></i> Download Receipt
        </a>
        <a href="receipt_stripe.php?booking_id=<?= $p['booking_id'] ?>&action=email" class="btn-action ghost">
            <i class="bi bi-envelope"></i> Email Receipt
        </a>
    <?php else: ?>
        <a href="receipt.php?booking_id=<?= $p['booking_id'] ?>&action=pdf" class="btn-action ghost">
            <i class="bi bi-download"></i> Download Receipt
        </a>
        <a href="receipt.php?booking_id=<?= $p['booking_id'] ?>&action=email" class="btn-action ghost">
            <i class="bi bi-envelope"></i> Email Receipt
        </a>
    <?php endif; ?>
            <?php elseif ($p['payment_status'] === 'pending'): ?>
              <?php if ($isStripe): ?>
                <a href="payment_form.php?booking_id=<?= $p['booking_id'] ?>&method=stripe" class="btn-action purple">
                  <i class="bi bi-credit-card"></i> Pay with Card
                </a>
                <button class="btn-action ghost" onclick="cancelPayment(<?= $p['payment_id'] ?>, <?= $p['booking_id'] ?>)">
                  <i class="bi bi-x-circle"></i> Cancel
                </button>
              <?php elseif ($isOnlineBanking && $hasProof): ?>
                <span class="btn-action muted">
                  <i class="bi bi-hourglass-split"></i> Awaiting Admin Verification
                </span>
                <a href="../uploads/payment_proofs/<?= e($p['proof_image']) ?>" target="_blank" class="btn-action ghost">
                  <i class="bi bi-image"></i> View My Proof
                </a>
              <?php elseif ($isOnlineBanking && !$hasProof): ?>
                <button class="btn-action ghost" onclick="cancelPayment(<?= $p['payment_id'] ?>, <?= $p['booking_id'] ?>)">
                  <i class="bi bi-x-circle"></i> Cancel
                </button>
              <?php elseif ($isCash && $isFaceToFace): ?>
                <span class="btn-action muted">
                  <i class="bi bi-cash-stack"></i> Pay RM <?= number_format($p['amount'], 2) ?> cash to tutor during class
                </span>
                <button class="btn-action ghost" onclick="cancelPayment(<?= $p['payment_id'] ?>, <?= $p['booking_id'] ?>)">
                  <i class="bi bi-x-circle"></i> Cancel
                </button>
              <?php else: ?>
                <span class="btn-action muted">
                  <i class="bi bi-info-circle"></i> Contact Support
                </span>
              <?php endif; ?>
              <?php elseif ($p['payment_status'] === 'rejected'): 
    $paid_amount = $p['actual_paid_amount'] ?? 0;
    $expected_amount = $p['amount']; 
    $remaining_amount = 0;
    $can_partial_pay = false;
    $booking_is_cancelled = ($p['booking_status'] == 'cancelled');
    
    // Only underpaid payments can pay remaining (booking still active)
    if (($p['rejection_type'] == 'wrong_amount' || $p['rejection_type'] == 'underpaid' || $p['rejection_type'] == 'underpaid_bulk') && $paid_amount < $expected_amount && $paid_amount > 0) {
        $remaining_amount = $expected_amount - $paid_amount;
        $can_partial_pay = true;
    }
?>
    <div style="display: flex; gap: 12px; flex-wrap: wrap;">
        <!-- View Details - ALWAYS show -->
         <button class="btn-action ghost" onclick="showPaymentDetails(
    <?= $p['payment_id'] ?>, 
    '<?= addslashes($p['notes'] ?? 'No reason provided') ?>', 
    '<?= $paid_amount ?>', 
    '<?= $expected_amount ?>',
    '<?= addslashes($methodLabel) ?>', 
    '<?= date('d M Y, g:i A', strtotime($p['paid_at'])) ?>',
    '<?= date('d M Y, g:i A', strtotime($p['booking_date'] . ' ' . $p['booking_time'])) ?>',
    '<?= addslashes($p['tutor_name']) ?>',
    '<?= addslashes($p['language']) ?>',
    '<?= $p['rejection_type'] ?? 'other' ?>',
    '<?= $p['booking_status'] ?>',
    '<?= $p['tutor_id'] ?>'
)">
            <i class="bi bi-info-circle"></i> View Details
        </button>
        
        <!-- Only show Pay Remaining for UNDERPAID (booking still active) -->
        <?php if ($can_partial_pay): ?>
            <a href="payment_form.php?payment_id=<?= $p['payment_id'] ?>&type=partial" class="btn-action purple">
                <i class="bi bi-cash"></i> Pay Remaining RM <?= number_format($remaining_amount, 2) ?>
            </a>
        <?php endif; ?>
                
        <!-- Money Already Deducted? - Always show for disputes -->
        <button class="btn-action ghost" onclick="reportPaymentIssue(<?= $p['payment_id'] ?>, <?= $p['booking_id'] ?>, <?= $paid_amount ?>)">
            <i class="bi bi-exclamation-triangle"></i> Money Already Deducted?
        </button>
    </div>

            <?php elseif ($p['payment_status'] === 'disputed'): ?>
              <span class="btn-action muted">
                <i class="bi bi-clock-history"></i> Admin is reviewing your dispute
              </span>
            <?php endif; ?>

            <?php if ($hasProof && $p['payment_status'] !== 'pending'): 
                $proof_file = '../uploads/payment_proofs/' . $p['proof_image'];
                $is_pdf = strtolower(pathinfo($p['proof_image'], PATHINFO_EXTENSION)) === 'pdf';
            ?>
                <?php if ($is_pdf): ?>
                    <button class="btn-action ghost" onclick="viewProofFile('<?= e($proof_file) ?>', true)" style="cursor: pointer;">
                        <i class="bi bi-file-earmark-pdf"></i> View PDF Proof
                    </button>
                <?php else: ?>
                    <button class="btn-action ghost" onclick="viewProofImage('<?= e($proof_file) ?>')" style="cursor: pointer;">
                        <i class="bi bi-image"></i> View Proof
                    </button>
                <?php endif; ?>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <!-- STICKY BULK ACTION BAR - MOVED OUTSIDE PAYMENT LIST -->
  <div id="bulkBar" class="bulk-action-bar">
    <div class="bulk-action-inner">
      <div class="bulk-info">
        <div class="bi-line">Selected items</div>
        <div>
          <span class="b-count" id="barCount">0 items</span>
          <span class="b-amount" id="barAmount"></span>
        </div>
      </div>
      <div class="bulk-btns">
        <button class="bulk-btn clear" onclick="clearAll()">
          <i class="bi bi-x-lg"></i> Clear
        </button>
        <button class="bulk-btn pay" id="barPayBtn" onclick="proceedToBulkPayment()" style="display:none">
          <i class="bi bi-credit-card"></i> Pay Selected
        </button>
      </div>
    </div>
  </div>

</div>

<div class="toast" id="toast"></div>

<script>
// State
const selected = { failed: new Set(), verified: new Set() };
let selectionMode = false;
let selectedPayments = new Set();

function toggleCard(paymentId) {
  const chk = document.getElementById('chk-' + paymentId);
  if (!chk) return;
  chk.checked = !chk.checked;
  onCheckboxChange(chk);
}
function onCheckboxChange(chk) {
  const type = chk.dataset.type;
  const bookingId = chk.dataset.bookingId;
  const card = chk.closest('.payment-card');

  // Only allow selection of failed payments
  if (type !== 'failed') {
    chk.checked = false;
    showToast('Can only select pending or failed payments');
    return;
  }

  if (chk.checked) {
    selected[type].add(bookingId);
    card.classList.add('selected');
  } else {
    selected[type].delete(bookingId);
    card.classList.remove('selected');
  }
  updateUI();
}

function toggleSelectAll(type) {
  const checkboxes = document.querySelectorAll(`.pay-checkbox[data-type="${type}"]`);
  const allChecked = [...checkboxes].every(c => c.checked);

  checkboxes.forEach(chk => {
    chk.checked = !allChecked;
    const card = chk.closest('.payment-card');
    if (!allChecked) {
      selected[type].add(chk.dataset.bookingId);
      card.classList.add('selected');
    } else {
      selected[type].delete(chk.dataset.bookingId);
      card.classList.remove('selected');
    }
  });

  const btn = document.getElementById('selectAll' + (type === 'failed' ? 'Failed' : 'Verified'));
  if (btn) {
    btn.classList.toggle('active', !allChecked);
    btn.querySelector('i').className = !allChecked ? 'bi bi-check-square' : 'bi bi-square';
  }
  updateUI();
}

function viewProofImage(imageSrc) {
    const modal = document.getElementById('imageModal');
    const img = document.getElementById('fullImage');
    
    if (modal && img) {
        img.src = imageSrc;
        modal.style.display = 'flex';
        
        // Close when clicking outside the image container
        modal.onclick = function(e) {
            if (e.target === modal) {
                closeImageModal();
            }
        };
    }
}

function closeImageModal() {
    const modal = document.getElementById('imageModal');
    if (modal) {
        modal.style.display = 'none';
        document.getElementById('fullImage').src = '';
    }
}

// Close with Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeImageModal();
    }
});

function clearAll() {
  document.querySelectorAll('.pay-checkbox').forEach(chk => {
    chk.checked = false;
    chk.closest('.payment-card').classList.remove('selected');
  });
  selected.failed.clear();
  selected.verified.clear();
  ['selectAllFailed','selectAllVerified'].forEach(id => {
    const btn = document.getElementById(id);
    if (btn) {
      btn.classList.remove('active');
      btn.querySelector('i').className = 'bi bi-square';
    }
  });
  updateUI();
}

function updateUI() {
  const totalCount = selected.failed.size + selected.verified.size;
  const tbCount = document.getElementById('tbCount');
  if (tbCount) {
    tbCount.style.display = totalCount > 0 ? 'block' : 'none';
    document.getElementById('tbCountNum').textContent = totalCount;
  }

  const bar = document.getElementById('bulkBar');
  if (bar) {
    bar.classList.toggle('visible', totalCount > 0);
    document.getElementById('barCount').textContent = totalCount + ' item' + (totalCount !== 1 ? 's' : '');
  }

  let failedTotal = 0;
  document.querySelectorAll('.pay-checkbox[data-type="failed"]:checked').forEach(chk => {
    failedTotal += parseFloat(chk.dataset.amount || 0);
  });
  
  const barAmount = document.getElementById('barAmount');
  if (barAmount) {
    barAmount.textContent = selected.failed.size > 0 ? '· RM ' + failedTotal.toFixed(2) + ' to pay' : '';
  }
  
  const barPayBtn = document.getElementById('barPayBtn');
  if (barPayBtn) {
    barPayBtn.style.display = selected.failed.size > 0 ? 'inline-flex' : 'none';
  }
}
function toggleSelectionMode() {
    selectionMode = !selectionMode;
    const container = document.querySelector('.payment-list');
    const btn = document.getElementById('selectModeBtn');
    const checkboxes = document.querySelectorAll('.pay-checkbox[data-type="failed"]');  // ← CHANGED: Only awaiting payment checkboxes
    
    if (selectionMode) {
        container.classList.add('selection-mode');
        btn.innerHTML = '<i class="bi bi-x-lg"></i> Cancel Selection';
        btn.style.background = 'linear-gradient(135deg, #dc3545, #c82333)';
        // Show only awaiting payment checkboxes
        checkboxes.forEach(cb => cb.style.display = 'block');
        
        document.querySelectorAll('.payment-card').forEach(card => {
            card.style.cursor = 'pointer';
        });
    } else {
        container.classList.remove('selection-mode');
        btn.innerHTML = '<i class="bi bi-check2-square"></i> Select More to Pay';
        btn.style.background = 'linear-gradient(135deg, var(--hot-pink), var(--pink))';
        // Hide all checkboxes and uncheck them
        checkboxes.forEach(cb => {
            cb.style.display = 'none';
            cb.checked = false;
        });
        // Clear selections
        selected.failed.clear();
        selected.verified.clear();
        selectedPayments.clear();
        // Remove selected class from all cards
        document.querySelectorAll('.payment-card').forEach(card => {
            card.classList.remove('selected');
            card.style.cursor = '';
        });
        updateUI();
        const bar = document.getElementById('bulkBar');
        if (bar) bar.classList.remove('visible');
    }
}function showPaymentDetails(paymentId, notes, paidAmount, expectedAmount, method, paidAt, bookingDate, tutorName, language, rejectionType, bookingStatus, tutorId) {
    let guidanceHtml = '';
    let actionButtons = '';
    let statusColor = '';
    let statusIcon = '';
    
    // Handle based on rejection type
    if (rejectionType === 'wrong_amount' || rejectionType === 'underpaid' || rejectionType === 'underpaid_bulk') {
        const remaining = parseFloat(expectedAmount) - parseFloat(paidAmount);
        statusColor = '#f59e0b';
        statusIcon = 'bi-exclamation-triangle-fill';
        guidanceHtml = `
            <div style="background: #fef3c7; padding: 15px; border-radius: 12px; margin-top: 10px; border-left: 4px solid #f59e0b;">
                <p style="margin: 0 0 8px 0;"><strong><i class="bi bi-cash"></i> Underpaid - Action Required:</strong></p>
                <p style="margin: 0;">You paid <strong>RM ${parseFloat(paidAmount).toFixed(2)}</strong> but should pay <strong>RM ${parseFloat(expectedAmount).toFixed(2)}</strong>.</p>
                <p style="margin: 8px 0 0 0;">Please pay the remaining amount to confirm your booking.</p>
            </div>
        `;
        actionButtons = `
            <a href="payment_form.php?payment_id=${paymentId}&type=partial" class="btn-action purple" style="display: inline-flex; align-items: center; gap: 8px;">
                <i class="bi bi-cash"></i> Pay Remaining RM ${remaining.toFixed(2)}
            </a>
            <button class="btn-action ghost" onclick="closeSwalAndReportIssue(${paymentId}, ${paymentId}, ${paidAmount})" style="display: inline-flex; align-items: center; gap: 8px;">
                <i class="bi bi-exclamation-triangle"></i> Money Already Deducted?
            </button>
        `;
    } 
    else if (rejectionType === 'overpaid') {
        const refundAmount = parseFloat(paidAmount) - parseFloat(expectedAmount);
        statusColor = '#059669';
        statusIcon = 'bi-cash-stack';
        guidanceHtml = `
            <div style="background: #d1fae5; padding: 15px; border-radius: 12px; margin-top: 10px; border-left: 4px solid #059669;">
                <p style="margin: 0 0 8px 0;"><strong><i class="bi bi-cash-stack"></i> Overpaid - Refund Processing:</strong></p>
                <p style="margin: 0;">You paid <strong>RM ${parseFloat(paidAmount).toFixed(2)}</strong> but should pay <strong>RM ${parseFloat(expectedAmount).toFixed(2)}</strong>.</p>
                <p style="margin: 8px 0 0 0;">Overpaid amount: <strong style="color: #059669;">RM ${refundAmount.toFixed(2)}</strong></p>
                <p style="margin: 8px 0 0 0;">Your booking is <strong>confirmed</strong>. The refund is being processed.</p>
                <p style="margin: 5px 0 0 0; font-size: 12px;">Refunds typically take 3-5 business days.</p>
            </div>
        `;
        actionButtons = `
            <span class="btn-action muted" style="display: inline-flex; align-items: center; gap: 8px;">
                <i class="bi bi-clock-history"></i> Refund Processing
            </span>
            <button class="btn-action ghost" onclick="closeSwalAndReportIssue(${paymentId}, ${paymentId}, ${paidAmount})" style="display: inline-flex; align-items: center; gap: 8px;">
                <i class="bi bi-exclamation-triangle"></i> Money Already Deducted?
            </button>
        `;
    } 
    else if (rejectionType === 'invalid_proof') {
        statusColor = '#dc2626';
        statusIcon = 'bi-camera';
        guidanceHtml = `
            <div style="background: #fef3c7; padding: 15px; border-radius: 12px; margin-top: 10px; border-left: 4px solid #f59e0b;">
                <p style="margin: 0 0 8px 0;"><strong><i class="bi bi-camera"></i> Invalid Payment Proof:</strong></p>
                <p style="margin: 0;">Your uploaded payment proof was unclear or does not show the required information.</p>
                <p style="margin: 10px 0 5px 0;"><strong>What you can do:</strong></p>
                <ul style="margin: 0 0 0 20px;">
                    <li>If money was deducted, click <strong>"Money Already Deducted?"</strong> below to dispute</li>
                    <li>Book a new session with the same tutor</li>
                </ul>
            </div>
        `;
        actionButtons = `
            <a href="booking_form.php?tutor_id=${tutorId}" class="btn-action primary" style="display: inline-flex; align-items: center; gap: 8px;">
                <i class="bi bi-calendar-plus"></i> Book New Session with ${tutorName}
            </a>
            <button class="btn-action ghost" onclick="closeSwalAndReportIssue(${paymentId}, ${paymentId}, ${paidAmount})" style="display: inline-flex; align-items: center; gap: 8px;">
                <i class="bi bi-exclamation-triangle"></i> Money Already Deducted?
            </button>
        `;
    } 
    else if (rejectionType === 'unrelated_proof') {
        statusColor = '#dc2626';
        statusIcon = 'bi-file-earmark-x';
        guidanceHtml = `
            <div style="background: #fef3c7; padding: 15px; border-radius: 12px; margin-top: 10px; border-left: 4px solid #f59e0b;">
                <p style="margin: 0 0 8px 0;"><strong><i class="bi bi-file-earmark-x"></i> Unrelated Payment Proof:</strong></p>
                <p style="margin: 0;">The screenshot you uploaded does not match this payment transaction.</p>
                <p style="margin: 10px 0 5px 0;"><strong>What you can do:</strong></p>
                <ul style="margin: 0 0 0 20px;">
                    <li>If money was deducted, click <strong>"Money Already Deducted?"</strong> below to dispute</li>
                    <li>Book a new session with the same tutor</li>
                </ul>
            </div>
        `;
        actionButtons = `
            <a href="booking_form.php?tutor_id=${tutorId}" class="btn-action primary" style="display: inline-flex; align-items: center; gap: 8px;">
                <i class="bi bi-calendar-plus"></i> Book New Session with ${tutorName}
            </a>
            <button class="btn-action ghost" onclick="closeSwalAndReportIssue(${paymentId}, ${paymentId}, ${paidAmount})" style="display: inline-flex; align-items: center; gap: 8px;">
                <i class="bi bi-exclamation-triangle"></i> Money Already Deducted?
            </button>
        `;
    } 
    else {
        // Other rejection type
        statusColor = '#dc2626';
        statusIcon = 'bi-x-circle-fill';
        guidanceHtml = `
            <div style="background: #fee2e2; padding: 15px; border-radius: 12px; margin-top: 10px; border-left: 4px solid #dc2626;">
                <p style="margin: 0 0 8px 0;"><strong><i class="bi bi-info-circle"></i> Payment Rejected:</strong></p>
                <p style="margin: 0;">Your payment was rejected for the following reason:</p>
                <p style="margin: 8px 0 0 15px; color: #991b1b;">${notes || 'No specific reason provided.'}</p>
                <p style="margin: 10px 0 0 0;"><strong>What you can do:</strong></p>
                <ul style="margin: 0 0 0 20px;">
                    <li>If money was deducted, click <strong>"Money Already Deducted?"</strong> below to dispute</li>
                    <li>Book a new session with the same tutor</li>
                </ul>
            </div>
        `;
        actionButtons = `
            <a href="booking_form.php?tutor_id=${tutorId}" class="btn-action primary" style="display: inline-flex; align-items: center; gap: 8px;">
                <i class="bi bi-calendar-plus"></i> Book New Session with ${tutorName}
            </a>
            <button class="btn-action ghost" onclick="closeSwalAndReportIssue(${paymentId}, ${paymentId}, ${paidAmount})" style="display: inline-flex; align-items: center; gap: 8px;">
                <i class="bi bi-exclamation-triangle"></i> Money Already Deducted?
            </button>
        `;
    }
    
    // Build and show the modal
    Swal.fire({
        title: 'Payment Details',
        html: `
            <div style="text-align: left; max-height: 550px; overflow-y: auto; padding-right: 5px;">
                <div style="background: ${statusColor}10; padding: 12px; border-radius: 12px; margin-bottom: 15px; border: 1px solid ${statusColor}30;">
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <i class="bi ${statusIcon}" style="color: ${statusColor}; font-size: 24px;"></i>
                        <div>
                            <p style="margin: 0; font-weight: 700; color: ${statusColor};">Payment Status: Rejected</p>
                            <p style="margin: 0; font-size: 12px; color: #666;">Reason: ${rejectionType.replace(/_/g, ' ').toUpperCase()}</p>
                        </div>
                    </div>
                </div>
                
                <div style="background: #f8f9fa; padding: 12px; border-radius: 12px; margin-bottom: 15px;">
                    <p style="margin: 0 0 5px 0;"><strong>Payment ID:</strong> #${paymentId}</p>
                    <p style="margin: 0 0 5px 0;"><strong>Booking:</strong> ${language} with ${tutorName}</p>
                    <p style="margin: 0;"><strong>Session Date:</strong> ${bookingDate}</p>
                </div>
                
                <div style="background: #fff; padding: 12px; border-radius: 12px; margin-bottom: 15px; border: 1px solid #e2e8f0;">
                    <p style="margin: 0 0 5px 0;"><strong>Amount You Paid:</strong> <span style="font-size: 18px; color: #E75A9B; font-weight: bold;">RM ${parseFloat(paidAmount).toFixed(2)}</span></p>
                    <p style="margin: 0 0 5px 0;"><strong>Expected Amount:</strong> RM ${parseFloat(expectedAmount).toFixed(2)}</p>
                    <p style="margin: 0;"><strong>Payment Method:</strong> ${method}</p>
                    <p style="margin: 5px 0 0 0;"><strong>Paid On:</strong> ${paidAt}</p>
                </div>
                
                <div style="background: #fee2e2; padding: 12px; border-radius: 12px; margin-bottom: 15px;">
                    <p style="margin: 0 0 5px 0;"><strong><i class="bi bi-chat-left-text"></i> Admin's Rejection Note:</strong></p>
                    <p style="margin: 0; color: #991b1b; font-size: 13px;">${notes || 'No additional notes provided.'}</p>
                </div>
                
                ${guidanceHtml}
                
                <div style="margin-top: 20px; padding-top: 15px; border-top: 1px solid #e2e8f0; display: flex; flex-wrap: wrap; gap: 10px; justify-content: center;">
                    ${actionButtons}
                </div>
                
                <hr style="margin: 15px 0;">
                <p style="font-size: 11px; color: #94a3b8; text-align: center;">
                    <i class="bi bi-headset"></i> Need help? Contact support@kyoshi.com
                </p>
            </div>
        `,
        icon: 'info',
        confirmButtonColor: '#E75A9B',
        confirmButtonText: 'Close',
        width: '550px',
        showCloseButton: true,
        showConfirmButton: true
    });
}

// Helper function to close Swal and open dispute modal
function closeSwalAndReportIssue(paymentId, bookingId, amount) {
    Swal.close();
    setTimeout(() => {
        reportPaymentIssue(paymentId, bookingId, amount);
    }, 100);
}

function hideSelectionIndicator() {
  const indicator = document.getElementById('selectionIndicator');
  if (indicator) indicator.style.display = 'none';
}

function toggleCardSelection(id) {
  const card = document.getElementById('card-' + id) || document.getElementById('card-booking-' + id);
  if (!card) return;
  
  if (selectedPayments.has(String(id))) {
    selectedPayments.delete(String(id));
    card.classList.remove('selected');
  } else {
    selectedPayments.add(String(id));
    card.classList.add('selected');
  }
  updateSelectionUI();
}

function clearSelection() {
  selectedPayments.clear();
  document.querySelectorAll('.payment-card.selected').forEach(card => card.classList.remove('selected'));
  updateSelectionUI();
  if (selectedPayments.size === 0 && selectionMode) {
    const indicator = document.getElementById('selectionIndicator');
    if (indicator) {
      document.getElementById('selectedCount').innerText = '0';
      document.getElementById('selectedAmount').innerHTML = '';
    }
  }
}

function updateSelectionUI() {
  const count = selectedPayments.size;
  const indicator = document.getElementById('selectionIndicator');
  if (indicator) {
    document.getElementById('selectedCount').innerText = count;
    let totalAmount = 0;
    selectedPayments.forEach(paymentId => {
      const card = document.getElementById(`card-${paymentId}`);
      if (card && card.dataset.amount) totalAmount += parseFloat(card.dataset.amount || 0);
    });
    document.getElementById('selectedAmount').innerHTML = totalAmount > 0 ? `· RM ${totalAmount.toFixed(2)}` : '';
  }
}
function proceedToBulkPayment() {
    const selectedCheckboxes = document.querySelectorAll('.pay-checkbox:checked');
    
    if (selectedCheckboxes.length === 0) {
        showToast('Please select at least one payment to proceed');
        return;
    }
    
    const bookingIds = [];
    selectedCheckboxes.forEach(chk => {
        const bookingId = chk.dataset.bookingId;
        if (bookingId && !bookingIds.includes(bookingId)) {
            bookingIds.push(bookingId);
        }
    });
    
    if (bookingIds.length === 0) return;
    
    let totalAmount = 0;
    selectedCheckboxes.forEach(chk => {
        totalAmount += parseFloat(chk.dataset.amount || 0);
    });
    
    Swal.fire({
        title: 'Confirm Payment',
        html: `You are about to pay <strong>RM ${totalAmount.toFixed(2)}</strong> for <strong>${bookingIds.length}</strong> session(s).`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Proceed',
        confirmButtonColor: '#28a745'
    }).then((result) => {
        if (result.isConfirmed) {
            if (bookingIds.length === 1) {
                window.location.href = 'payment_form.php?booking_id=' + bookingIds[0];
            } else {
                window.location.href = 'payment_form.php?' + bookingIds.map(id => 'booking_ids[]=' + id).join('&');
            }
        }
    });
}

function bulkDownload() {
  const checkboxes = document.querySelectorAll('.pay-checkbox[data-type="verified"]:checked');
  if (!checkboxes.length) return;
  showToast('Opening ' + checkboxes.length + ' receipt(s)…');
  [...checkboxes].forEach((chk, i) => setTimeout(() => window.open(chk.dataset.receipt, '_blank'), i * 350));
}

let toastTimer;
function showToast(msg) {
  const t = document.getElementById('toast');
  t.textContent = msg;
  t.classList.add('show');
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => t.classList.remove('show'), 2200);
}

function toggleDropdown() {
  const d = document.getElementById('profileDropdown');
  d.style.display = d.style.display === 'none' ? 'block' : 'none';
}

function cancelPayment(paymentId, bookingId) {
  if (confirm('Are you sure you want to cancel this payment?')) {
    fetch('cancel_payment.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ payment_id: paymentId, booking_id: bookingId })
    })
    .then(response => response.json())
    .then(data => {
      if (data.success) {
        showToast('Payment cancelled successfully');
        setTimeout(() => location.reload(), 1500);
      } else {
        showToast('Error: ' + data.message, true);
      }
    })
    .catch(error => showToast('Error cancelling payment', true));
  }
}

document.addEventListener('click', function(e) {
  const btn = document.getElementById('profileBtn');
  const dd = document.getElementById('profileDropdown');
  if (btn && dd && !btn.contains(e.target) && !dd.contains(e.target)) dd.style.display = 'none';
});

let currentDisputeData = {};
function reportPaymentIssue(paymentId, bookingId, amount) {
    currentDisputeData = { paymentId, bookingId, amount };
    
    // Check if elements exist before setting values
    const paymentIdInput = document.getElementById('disputePaymentId');
    const bookingIdInput = document.getElementById('disputeBookingId');
    
    if (paymentIdInput) paymentIdInput.value = paymentId;
    if (bookingIdInput) bookingIdInput.value = bookingId;
    
    const modal = document.getElementById('disputeModal');
    if (modal) modal.style.display = 'flex';
    
    // Clear form
    const descField = document.getElementById('disputeDescription');
    if (descField) descField.value = '';
    
    const dateField = document.getElementById('preferredDateTime');
    if (dateField) dateField.value = '';
    
    const rescheduleDiv = document.getElementById('rescheduleDateDiv');
    if (rescheduleDiv) rescheduleDiv.style.display = 'none';
    
    const bankDiv = document.getElementById('bankDetailsDiv');
    if (bankDiv) bankDiv.style.display = 'none';
    
    // Uncheck all radio buttons
    document.querySelectorAll('input[name="resolution"]').forEach(radio => radio.checked = false);
    
    // Clear file input
    const fileInput = document.getElementById('proofImage');
    if (fileInput) fileInput.value = '';
    
    const previewDiv = document.getElementById('imagePreview');
    if (previewDiv) previewDiv.style.display = 'none';
}

function closeDisputeModal() {
    document.getElementById('disputeModal').style.display = 'none';
}

// Update the radio button change handler - REPLACE the existing one
document.addEventListener('DOMContentLoaded', function() {
    const radios = document.querySelectorAll('input[name="resolution"]');
    const bankDiv = document.getElementById('bankDetailsDiv');
    const rescheduleDiv = document.getElementById('rescheduleDateDiv');
    
    radios.forEach(radio => {
        radio.addEventListener('change', function() {
            // Reset all optional divs
            if (bankDiv) bankDiv.style.display = 'none';
            if (rescheduleDiv) rescheduleDiv.style.display = 'none';
            
            // Show appropriate div based on selection
            if (this.value === 'reschedule') {
                if (rescheduleDiv) rescheduleDiv.style.display = 'block';
                // Make datetime field required
                const datetimeInput = document.getElementById('preferredDateTime');
                if (datetimeInput) datetimeInput.required = true;
            } else if (this.value === 'refund') {
                if (bankDiv) bankDiv.style.display = 'block';
                // Make datetime not required
                const datetimeInput = document.getElementById('preferredDateTime');
                if (datetimeInput) datetimeInput.required = false;
            } else {
                // Complete - hide both
                const datetimeInput = document.getElementById('preferredDateTime');
                if (datetimeInput) datetimeInput.required = false;
            }
        });
    });
});
function submitDispute() {
    const resolution = document.querySelector('input[name="resolution"]:checked');
    const description = document.getElementById('disputeDescription').value;
    const preferredDateTime = document.getElementById('preferredDateTime').value;
    
    if (!resolution) {
        showToast('Please select what you would like to happen', true);
        return;
    }
    
    if (!description.trim()) {
        showToast('Please describe what happened', true);
        return;
    }
    
    if (resolution.value === 'reschedule' && !preferredDateTime) {
        showToast('Please select a preferred new date and time for reschedule', true);
        return;
    }
    
    // Build dispute data - issue_type is now FIXED to 'money_deducted'
    const disputeData = {
        payment_id: currentDisputeData.paymentId,
        booking_id: currentDisputeData.bookingId,
        amount: currentDisputeData.amount,
        issue_type: 'money_deducted',  // FIXED - no need to ask student
        resolution_requested: resolution.value,
        description: description,
        preferred_datetime: preferredDateTime || null
    };
    
    // Submit dispute
    fetch('report_payment_issue.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(disputeData)
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            Swal.fire({
                icon: 'success',
                title: 'Dispute Submitted!',
                text: data.message,
                confirmButtonColor: '#E75A9B'
            }).then(() => {
                location.reload();
            });
        } else {
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: data.message,
                confirmButtonColor: '#dc2626'
            });
        }
    })
    .catch(error => {
        console.error('Error:', error);
        Swal.fire({
            icon: 'error',
            title: 'Error',
            text: 'Error submitting dispute. Please try again.',
            confirmButtonColor: '#dc2626'
        });
    });
}

let selectedProofFile = null;


// Add this new function for handling proof files
function viewProofFile(filePath, isPDF) {
    if (isPDF) {
        window.open(filePath, '_blank');
    } else {
        viewProofImage(filePath);
    }
}

function previewProof(input) {
    const previewDiv = document.getElementById('imagePreview');
    const fileNameSpan = document.getElementById('fileName');
    
    if (input.files && input.files[0]) {
        const file = input.files[0];
        
        if (file.size > 5 * 1024 * 1024) {
            showToast('File is too large. Maximum size is 5MB.', true);
            input.value = '';
            return;
        }
        
        const allowedTypes = ['image/jpeg', 'image/png', 'application/pdf'];
        if (!allowedTypes.includes(file.type)) {
            showToast('Only JPG, PNG, or PDF files are allowed.', true);
            input.value = '';
            return;
        }
        
        selectedProofFile = file;
        fileNameSpan.textContent = file.name;
        previewDiv.style.display = 'block';
    }
}

function clearProof() {
    document.getElementById('proofImage').value = '';
    document.getElementById('imagePreview').style.display = 'none';
    selectedProofFile = null;
}
// Show/hide bank details when resolution changes
document.addEventListener('DOMContentLoaded', function() {
    const radios = document.querySelectorAll('input[name="resolution"]');
    const bankDiv = document.getElementById('bankDetailsDiv');
    const rescheduleDiv = document.getElementById('rescheduleDateDiv');
    
    radios.forEach(radio => {
        radio.addEventListener('change', function() {
            if (this.value === 'reschedule') {
                rescheduleDiv.style.display = 'block';
                if (bankDiv) bankDiv.style.display = 'none';
            } else if (this.value === 'refund') {
                rescheduleDiv.style.display = 'none';
                if (bankDiv) bankDiv.style.display = 'block';
            } else {
                rescheduleDiv.style.display = 'none';
                if (bankDiv) bankDiv.style.display = 'none';
            }
        });
    });
});
function submitDisputeWithProof() {
    const resolution = document.querySelector('input[name="resolution"]:checked');
    const description = document.getElementById('disputeDescription').value;
    const preferredDateTime = document.getElementById('preferredDateTime').value;
    const proofFile = document.getElementById('proofImage').files[0];
    
    // Get bank details if refund is requested
    let bankDetails = {};
    if (resolution && resolution.value === 'refund') {
        const bankName = document.getElementById('bank_name')?.value || '';
        const bankAccountNumber = document.getElementById('bank_account_number')?.value || '';
        const bankAccountName = document.getElementById('bank_account_name')?.value || '';
        
        if (!bankName || !bankAccountNumber || !bankAccountName) {
            showToast('Please provide all bank account details for refund', true);
            return;
        }
        
        bankDetails = {
            bank_name: bankName,
            bank_account_number: bankAccountNumber,
            bank_account_name: bankAccountName
        };
    }
    
    if (!resolution) {
        showToast('Please select what you would like to happen', true);
        return;
    }
    
    if (!description.trim()) {
        showToast('Please describe what happened', true);
        return;
    }
    
    // IMPORTANT: Check for reschedule and preferred date/time
    if (resolution.value === 'reschedule') {
        if (!preferredDateTime) {
            showToast('Please select a preferred new date and time for reschedule', true);
            return;
        }
        // Format the date/time nicely
        const formattedDateTime = new Date(preferredDateTime);
        if (isNaN(formattedDateTime.getTime())) {
            showToast('Please select a valid date and time', true);
            return;
        }
    }
    
    if (resolution.value === 'refund' && !proofFile) {
        showToast('Please upload proof of deduction (bank statement/screenshot)', true);
        return;
    }
    
    // Show loading
    Swal.fire({
        title: 'Submitting...',
        text: 'Please wait while we submit your dispute',
        allowOutsideClick: false,
        didOpen: () => {
            Swal.showLoading();
        }
    });
    
    const formData = new FormData();
    formData.append('payment_id', currentDisputeData.paymentId);
    formData.append('booking_id', currentDisputeData.bookingId);
    formData.append('amount', currentDisputeData.amount);
    formData.append('resolution_requested', resolution.value);
    formData.append('description', description);
    
    // IMPORTANT: Add preferred datetime for reschedule
    if (resolution.value === 'reschedule' && preferredDateTime) {
        formData.append('preferred_datetime', preferredDateTime);
    }
    
    if (proofFile) {
        formData.append('proof_image', proofFile);
    }
    
    // Add bank details if refund
    if (resolution.value === 'refund') {
        formData.append('bank_name', bankDetails.bank_name);
        formData.append('bank_account_number', bankDetails.bank_account_number);
        formData.append('bank_account_name', bankDetails.bank_account_name);
    }
    
    fetch('report_payment_issue.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.text())
    .then(text => {
        console.log("Raw response:", text);
        try {
            const data = JSON.parse(text);
            if (data.success) {
                Swal.fire({
                    icon: 'success',
                    title: 'Dispute Submitted!',
                    html: data.message,
                    confirmButtonColor: '#E75A9B'
                }).then(() => {
                    location.reload();
                });
            } else {
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: data.message,
                    confirmButtonColor: '#dc2626'
                });
            }
        } catch(e) {
            console.error("JSON parse error:", e);
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: 'Server error. Please try again later.',
                confirmButtonColor: '#dc2626'
            });
        }
    })
    .catch(error => {
        console.error('Fetch error:', error);
        Swal.fire({
            icon: 'error',
            title: 'Network Error',
            text: 'Please check your internet connection and try again.',
            confirmButtonColor: '#dc2626'
        });
    });
}
</script><!-- Enhanced Dispute Modal with Proof Upload -->
<div id="disputeModal" class="modal-overlay" style="display:none;">
    <div class="modal-container" style="max-width: 600px;">
        <div class="modal-header" style="padding: 20px 24px; border-bottom: 1px solid rgba(0,0,0,0.1); display: flex; justify-content: space-between; align-items: center;">
            <h3 style="margin:0; color:#dc2626;">
                <i class="bi bi-exclamation-triangle-fill"></i> Money Already Deducted?
            </h3>
            <button class="close-modal" onclick="closeDisputeModal()" style="background:none; border:none; font-size:24px; cursor:pointer;">&times;</button>
        </div>
        <div class="modal-body" style="padding: 24px;">
            <form id="disputeForm" enctype="multipart/form-data">
                <input type="hidden" id="disputePaymentId" name="payment_id">
                <input type="hidden" id="disputeBookingId" name="booking_id">
                
                <div style="margin-bottom: 20px; background: #fef3c7; padding: 12px; border-radius: 12px;">
                    <p style="margin: 0; font-size: 13px; color: #92400e;">
                        <i class="bi bi-info-circle"></i> 
                        Your payment was rejected, but you confirmed money was deducted from your account.
                        Please provide proof of deduction so we can verify and help you.
                    </p>
                </div>
                
                <div style="margin-bottom: 20px;">
                    <label style="display:block; font-weight:700; margin-bottom:8px;">What would you like to happen?</label>
                    <div style="display: flex; flex-direction: column; gap: 12px;">
                        <label style="display: flex; align-items: center; padding: 12px; border: 2px solid #e2e8f0; border-radius: 12px; cursor: pointer;">
                            <input type="radio" name="resolution" value="refund" required style="margin-right: 12px;">
                            <div>
                                <strong style="color: #059669;">Full Refund</strong><br>
                                <small style="color:#666;">I want my money back</small>
                            </div>
                        </label>
                        <label style="display: flex; align-items: center; padding: 12px; border: 2px solid #e2e8f0; border-radius: 12px; cursor: pointer;">
                            <input type="radio" name="resolution" value="reschedule" style="margin-right: 12px;">
                            <div>
                                <strong style="color: #f59e0b;">Reschedule Booking</strong><br>
                                <small style="color:#666;">I still want the session at a different time</small>
                            </div>
                        </label>
                        <label style="display: flex; align-items: center; padding: 12px; border: 2px solid #e2e8f0; border-radius: 12px; cursor: pointer;">
                            <input type="radio" name="resolution" value="complete" style="margin-right: 12px;">
                            <div>
                                <strong style="color: #8b5cf6;">Complete Current Booking</strong><br>
                                <small style="color:#666;">Just confirm my existing booking</small>
                            </div>
                        </label>
                    </div>
                </div>
                
                <!-- Bank details section for refund -->
                <div id="bankDetailsDiv" style="display:none; margin-bottom: 20px; padding: 15px; background: #f0fdf4; border-radius: 12px; border-left: 4px solid #059669;">
                    <label style="display:block; font-weight:700; margin-bottom: 8px;">
                        <i class="bi bi-bank"></i> Bank Account Details for Refund
                    </label>
                    <p style="font-size: 12px; color: #666; margin-bottom: 12px;">
                        Please provide your bank account details so we can process your refund.
                    </p>
                    <div style="margin-bottom: 10px;">
                        <input type="text" id="bank_name" name="bank_name" class="form-control" placeholder="Bank Name (e.g., Maybank, CIMB)" style="width: 100%; padding: 10px; border-radius: 8px; border: 1px solid #ddd;">
                    </div>
                    <div style="margin-bottom: 10px;">
                        <input type="text" id="bank_account_number" name="bank_account_number" class="form-control" placeholder="Account Number" style="width: 100%; padding: 10px; border-radius: 8px; border: 1px solid #ddd;">
                    </div>
                    <div>
                        <input type="text" id="bank_account_name" name="bank_account_name" class="form-control" placeholder="Account Holder Name" style="width: 100%; padding: 10px; border-radius: 8px; border: 1px solid #ddd;">
                    </div>
                </div>
                
                <div id="rescheduleDateDiv" style="display:none; margin-bottom: 20px;">
                    <label style="display:block; font-weight:700; margin-bottom:8px;">Preferred New Date & Time</label>
                    <input type="datetime-local" id="preferredDateTime" name="preferred_datetime" style="width:100%; padding:12px; border-radius:12px; border:1px solid #ddd;">
                </div>
                
                <!-- PROOF UPLOAD SECTION -->
                <div style="margin-bottom: 20px;">
                    <label style="display:block; font-weight:700; margin-bottom:8px;">Upload Bank Statement / Payment Proof <span style="color:red;">*</span></label>
                    <div style="border: 2px dashed #e2e8f0; border-radius: 12px; padding: 20px; text-align: center; cursor: pointer;" 
                         onclick="document.getElementById('proofImage').click()"
                         onmouseover="this.style.borderColor='#E75A9B'; this.style.background='#fff1f6'"
                         onmouseout="this.style.borderColor='#e2e8f0'; this.style.background='transparent'">
                        <i class="bi bi-cloud-upload" style="font-size: 48px; color: #E75A9B;"></i>
                        <p style="margin: 10px 0 5px; font-size: 14px;"><strong>Click to upload proof</strong></p>
                        <p style="margin: 0; font-size: 12px; color: #666;">Upload screenshot showing the deduction from your bank account</p>
                        <p style="margin: 5px 0 0; font-size: 11px; color: #999;">Accepted: JPG, PNG, PDF (Max 5MB)</p>
                    </div>
                    <input type="file" id="proofImage" name="proof_image" accept="image/jpeg,image/png,application/pdf" style="display:none;" onchange="previewProof(this)">
                    <div id="imagePreview" style="margin-top: 10px; display: none;">
                        <div style="background: #f0fdf4; padding: 10px; border-radius: 8px; display: flex; align-items: center; gap: 10px;">
                            <i class="bi bi-check-circle-fill" style="color: #059669;"></i>
                            <span id="fileName" style="font-size: 13px;"></span>
                            <button type="button" onclick="clearProof()" style="margin-left: auto; background: none; border: none; color: #dc2626; cursor: pointer;">
                                <i class="bi bi-x-circle"></i> Remove
                            </button>
                        </div>
                    </div>
                </div>
                
                <div style="margin-bottom: 20px;">
                    <label style="display:block; font-weight:700; margin-bottom:8px;">Describe what happened</label>
                    <textarea id="disputeDescription" name="description" rows="4" required style="width:100%; padding:12px; border-radius:12px; border:1px solid #ddd;" 
                              placeholder="Please describe:
• When did you make the payment?
• What amount was deducted?
• Did you receive any confirmation from your bank?
• Any transaction/reference number?"></textarea>
                </div>
            </form>
        </div>
        <div class="modal-footer" style="padding: 16px 24px; border-top: 1px solid rgba(0,0,0,0.1); display: flex; justify-content: flex-end; gap: 12px;">
            <button onclick="closeDisputeModal()" style="padding:10px 24px; border-radius:30px; background:#64748b; color:white; border:none; cursor:pointer;">Cancel</button>
            <button onclick="submitDisputeWithProof()" style="padding:10px 24px; border-radius:30px; background:#dc2626; color:white; border:none; cursor:pointer;">
                <i class="bi bi-send"></i> Submit Dispute with Proof
            </button>
        </div>
    </div>
</div>
<style>
.modal-overlay {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.5);
    z-index: 2000;
    display: flex;
    align-items: center;
    justify-content: center;
    display: none;
}
.modal-container {
    background: white;
    border-radius: 24px;
    max-width: 550px;
    width: 90%;
    max-height: 90vh;
    overflow: hidden;
    display: flex;
    flex-direction: column;
}

.modal-body {
    padding: 24px;
    overflow-y: auto;
    max-height: calc(90vh - 130px);
    flex: 1;
}

.modal-header {
    padding: 20px 24px;
    border-bottom: 1px solid rgba(0,0,0,0.1);
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-shrink: 0;
}

.modal-footer {
    padding: 16px 24px;
    border-top: 1px solid rgba(0,0,0,0.1);
    display: flex;
    justify-content: flex-end;
    gap: 12px;
    flex-shrink: 0;
}

#disputeForm {
    display: flex;
    flex-direction: column;
    gap: 20px;
}

.modal-body::-webkit-scrollbar {
    width: 6px;
}

.modal-body::-webkit-scrollbar-track {
    background: #f1f1f1;
    border-radius: 3px;
}

.modal-body::-webkit-scrollbar-thumb {
    background: #e397ba;
    border-radius: 3px;
}

.modal-body::-webkit-scrollbar-thumb:hover {
    background: #e08bb1;
}


</style>
<?php include 'nav_search_modal.php'; ?>
<script src="../js/search_modal.js"></script>

<div id="imageModal" style="display: none;">
    <div class="modal-content" style="background: transparent; position: relative;">
        <button class="close-image-btn" onclick="closeImageModal()">&times;</button>
        <img id="fullImage" src="" alt="Payment Proof">
    </div>
</div>
<script>function provideBankDetails(paymentId, refundAmount) {
    Swal.fire({
        title: 'Bank Account Details for Refund',
        html: `
            <div style="text-align: left;">
                <p>Refund amount: <strong style="color: #059669;">RM ${parseFloat(refundAmount).toFixed(2)}</strong></p>
                <p style="margin-bottom: 15px; font-size: 13px; color: #666;">Please provide your bank account details to receive the refund.</p>
                <div class="form-group" style="margin-bottom: 12px;">
                    <label>Bank Name</label>
                    <input type="text" id="refund_bank_name" class="form-control" placeholder="e.g., Maybank, CIMB, Public Bank" style="width: 100%; padding: 10px; border-radius: 8px; border: 1px solid #ddd;">
                </div>
                <div class="form-group" style="margin-bottom: 12px;">
                    <label>Account Number</label>
                    <input type="text" id="refund_account_number" class="form-control" placeholder="Your bank account number" style="width: 100%; padding: 10px; border-radius: 8px; border: 1px solid #ddd;">
                </div>
                <div class="form-group" style="margin-bottom: 12px;">
                    <label>Account Holder Name</label>
                    <input type="text" id="refund_account_name" class="form-control" placeholder="Name as per bank account" style="width: 100%; padding: 10px; border-radius: 8px; border: 1px solid #ddd;">
                </div>
            </div>
        `,
        icon: 'info',
        showCancelButton: true,
        confirmButtonColor: '#059669',
        confirmButtonText: 'Submit Bank Details',
        cancelButtonText: 'Cancel',
        preConfirm: () => {
            const bankName = document.getElementById('refund_bank_name').value;
            const bankAccount = document.getElementById('refund_account_number').value;
            const bankAccountName = document.getElementById('refund_account_name').value;
            
            if (!bankName || !bankAccount || !bankAccountName) {
                Swal.showValidationMessage('Please fill in all bank account details');
                return false;
            }
            
            return { bankName, bankAccount, bankAccountName };
        }
    }).then((result) => {
        if (result.isConfirmed) {
            fetch('submit_bank_details.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    payment_id: paymentId,
                    refund_amount: refundAmount,
                    bank_name: result.value.bankName,
                    bank_account_number: result.value.bankAccount,
                    bank_account_name: result.value.bankAccountName
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Bank Details Submitted',
                        text: 'Your bank details have been submitted. Refund will be processed within 3-5 business days.',
                        confirmButtonColor: '#E75A9B'
                    }).then(() => {
                        location.reload();
                    });
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: data.message,
                        confirmButtonColor: '#dc2626'
                    });
                }
            });
        }
    });
}</script>

</body>
</html>
