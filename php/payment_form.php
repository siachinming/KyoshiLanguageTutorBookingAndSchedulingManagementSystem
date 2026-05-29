<?php
session_start();
include 'config.php';
$assetBase = '../assets/img';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$userID = $_SESSION['user_id'];

// Handle both single and multiple booking IDs
$booking_ids = [];

// Check for multiple bookings
if (isset($_GET['booking_ids']) && is_array($_GET['booking_ids'])) {
    $booking_ids = array_map('intval', $_GET['booking_ids']);
} 
// Check for single booking
elseif (isset($_GET['booking_id'])) {
    $booking_ids = [intval($_GET['booking_id'])];
}
// Check POST data
elseif (isset($_POST['booking_ids']) && is_array($_POST['booking_ids'])) {
    $booking_ids = array_map('intval', $_POST['booking_ids']);
}
elseif (isset($_POST['booking_id'])) {
    $booking_ids = [intval($_POST['booking_id'])];
}

if (empty($booking_ids)) {
    header("Location: booking_status.php?error=no_booking_selected");
    exit();
}

// Get all bookings info
$placeholders = implode(',', array_fill(0, count($booking_ids), '?'));
$types = str_repeat('i', count($booking_ids));

$stmt = $conn->prepare("
    SELECT b.*, u.fullname AS tutor_name, u.profile_pic AS tutor_pic,
           tp.rate, p.id AS payment_id, p.status AS payment_status
    FROM bookings b
    JOIN users u ON b.tutor_id = u.id
    JOIN tutor_profiles tp ON b.tutor_id = tp.user_id
    LEFT JOIN payments p ON p.booking_id = b.id
    WHERE b.id IN ($placeholders) AND b.student_id = ? AND b.status IN ('accepted','confirmed')
");
$all_params = array_merge($booking_ids, [$userID]);
$all_types = $types . 'i';
$stmt->bind_param($all_types, ...$all_params);
$stmt->execute();
$bookings = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

if (empty($bookings)) {
    header("Location: booking_status.php?error=bookings_not_found");
    exit();
}

// Check if any payment is already verified
foreach ($bookings as $booking) {
    if ($booking['payment_status'] === 'verified') {
        header("Location: booking_detail.php?id=" . $booking['id']);
        exit();
    }
}

// Calculate total amount
// Calculate total amount - use total_amount from bookings if available, otherwise use rate
$total_amount = 0;
foreach ($bookings as $booking) {
    if (isset($booking['total_amount']) && $booking['total_amount'] > 0) {
        $total_amount += $booking['total_amount'];
    } else {
        $total_amount += $booking['rate'];
    }
}
$is_multi = count($bookings) > 1;
$first_booking = $bookings[0];

// Get student info
$stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
$stmt->bind_param("i", $userID);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

$displayName = $user['fullname'];
$profilePic = !empty($user['profile_pic']) ? '../uploads/profiles/' . $user['profile_pic'] : $assetBase . '/profile-student.png';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $method = trim($_POST['payment_method'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    
    if (!$method) {
        $error = 'Please select a payment method.';
    } else {
        // Handle proof upload
        $proofImage = null;
        if (isset($_FILES['proof_image']) && $_FILES['proof_image']['error'] === 0) {
            $allowed = ['image/jpeg','image/png','image/jpg','image/webp','application/pdf'];
            if (in_array($_FILES['proof_image']['type'], $allowed)) {
                $ext = pathinfo($_FILES['proof_image']['name'], PATHINFO_EXTENSION);
                $filename = 'proof_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
                $dest = '../uploads/payment_proofs/' . $filename;
                if (move_uploaded_file($_FILES['proof_image']['tmp_name'], $dest)) {
                    $proofImage = $filename;
                }
            } else {
                $error = 'Invalid file type. Please upload JPG, PNG, or PDF.';
            }
        }
        
        // Check if proof is required
        $requires_proof = in_array($method, ['online_banking', 'duitnow']);
        if ($requires_proof && !$proofImage) {
            $error = 'Payment proof is required for this method.';
        }
        
        // Check cash for online sessions
        if ($method === 'cash') {
            foreach ($bookings as $booking) {
                if ($booking['learning_mode'] === 'online') {
                    $error = 'Cash payment is not allowed for online sessions.';
                    break;
                }
            }
        }
        
        if (!isset($error)) {
            $receiptNo = 'RCP-' . date('Y') . '-' . str_pad(rand(1, 99999), 6, '0', STR_PAD_LEFT);
            
            // Insert/Update payments for each booking
            foreach ($bookings as $booking) {
                $stmt = $conn->prepare("
                    INSERT INTO payments (booking_id, student_id, tutor_id, amount, payment_method, status, receipt_number, notes, proof_image, created_at)
                    VALUES (?, ?, ?, ?, ?, 'pending', ?, ?, ?, NOW())
                    ON DUPLICATE KEY UPDATE
                        amount = VALUES(amount),
                        payment_method = VALUES(payment_method),
                        status = 'pending',
                        receipt_number = VALUES(receipt_number),
                        notes = VALUES(notes),
                        proof_image = VALUES(proof_image),
                        created_at = NOW()
                ");
                $stmt->bind_param("iiidssss", $booking['id'], $userID, $booking['tutor_id'], $booking['rate'], $method, $receiptNo, $notes, $proofImage);
                $stmt->execute();
                $stmt->close();
            }
            
            if ($is_multi) {
                header("Location: my_payments.php?success=payments_submitted");
            } else {
                header("Location: booking_detail.php?id=" . $bookings[0]['id'] . "&paid=1");
            }
            exit();
        }
    }
}

function e($v){ return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Payment · Kyoshi</title>
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
    a{text-decoration:none;color:inherit}button,input,select,textarea{font-family:inherit}
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
    .back-link{display:inline-flex;align-items:center;gap:6px;color:var(--pink-dark);font-weight:900;font-size:13px;padding:9px 16px;border-radius:999px;background:rgba(255,255,255,.78);border:1px solid rgba(46,42,59,.08);transition:.18s ease;margin-bottom:20px;}
    .back-link:hover{transform:translateY(-1px)}

    .payment-grid{display:grid;grid-template-columns:1fr 360px;gap:24px;align-items:start}
    .card{background:var(--paper);border:1px solid rgba(255,255,255,.55);box-shadow:var(--shadow);border-radius:var(--radius-xl);padding:24px;margin-bottom:16px}
    .card-title{font-size:16px;font-weight:900;margin:0 0 16px;display:flex;align-items:center;gap:8px}
    .card-title i{color:var(--hot-pink)}
    .method-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;}
    .method-card{border:2px solid rgba(46,42,59,.10);border-radius:18px;padding:18px 12px;text-align:center;cursor:pointer;transition:.18s ease;background:white;position:relative}
    .method-card:hover{border-color:var(--pink);transform:translateY(-2px)}
    .method-card.selected{border-color:var(--hot-pink);background:rgba(231,90,155,.06);box-shadow:0 6px 20px rgba(231,90,155,.15)}
    .method-card .method-icon{font-size:28px;margin-bottom:8px;display:block}
    .method-card .method-name{font-size:13px;font-weight:900;color:var(--ink)}
    .method-card .method-desc{font-size:11px;color:var(--muted);margin-top:3px}
    .method-card input[type=radio]{position:absolute;opacity:0;width:0;height:0}
    .method-card .check{position:absolute;top:10px;right:10px;width:20px;height:20px;border-radius:50%;border:2px solid rgba(46,42,59,.15);background:white;display:grid;place-items:center;font-size:11px;transition:.15s ease}
    .method-card.selected .check{background:var(--hot-pink);border-color:var(--hot-pink);color:white}

    .form-group{margin-bottom:16px}
    .form-group label{display:block;font-size:13px;font-weight:900;color:#342635;margin-bottom:7px}
    .form-group label i{color:var(--hot-pink);margin-right:5px}
    .form-control{width:100%;padding:12px 16px;border:1px solid rgba(46,42,59,.12);border-radius:14px;outline:none;font-size:14px;color:#342635;background:rgba(255,255,255,.9);transition:.15s ease}
    .form-control:focus{border-color:var(--hot-pink);box-shadow:0 0 0 3px rgba(231,90,155,.12)}

    .summary-card{background:var(--paper);border:1px solid rgba(255,255,255,.55);box-shadow:var(--shadow);border-radius:var(--radius-xl);padding:24px;position:sticky;top:96px}
    .summary-row{display:flex;justify-content:space-between;align-items:center;padding:10px 0;border-bottom:1px solid rgba(46,42,59,.06);font-size:13px}
    .summary-row:last-of-type{border-bottom:none}
    .summary-label{color:var(--muted);font-weight:700}
    .summary-val{font-weight:900}
    .amount-box{margin-top:16px;padding:16px;border-radius:18px;background:linear-gradient(135deg,rgba(231,90,155,.10),rgba(255,195,216,.15));border:1px solid rgba(242,138,178,.22);text-align:center}
    .amount-box p{margin:0 0 4px;font-size:12px;color:var(--muted);font-weight:700}
    .amount-box strong{font-size:32px;color:var(--hot-pink);font-weight:900}

    .btn-primary{padding:14px 28px;border-radius:999px;border:none;background:linear-gradient(135deg,var(--hot-pink),var(--pink));color:white;font-size:14px;font-weight:900;cursor:pointer;box-shadow:0 8px 20px rgba(231,90,155,.28);width:100%;transition:.18s ease;display:flex;align-items:center;justify-content:center;gap:8px}
    .btn-primary:hover{transform:translateY(-1px)}

    .error-box{padding:12px 16px;border-radius:14px;background:rgba(255,200,200,.5);border:1px solid rgba(201,79,134,.2);color:#C94F4F;font-size:13px;font-weight:700;margin-bottom:16px}

    .info-note{padding:12px 16px;border-radius:14px;background:rgba(221,211,255,.3);border:1px solid rgba(167,123,232,.2);font-size:13px;color:#6D4964;font-weight:700;margin-top:12px;line-height:1.5}

    .toast{position:fixed;left:50%;bottom:28px;transform:translate(-50%,18px);opacity:0;pointer-events:none;z-index:99;background:#8E3F70;color:#fff;border-radius:999px;padding:12px 18px;font-size:13px;font-weight:900;transition:.2s ease}
    .toast.show{opacity:1;transform:translate(-50%,0)}

    @media(max-width:900px){.payment-grid{grid-template-columns:1fr}}
    @media(max-width:600px){.method-grid{grid-template-columns:1fr};.nav{grid-template-columns:1fr auto};.nav-links{grid-column:1/-1}}
    .method-card.disabled{pointer-events:none;border:2px dashed #ccc;background:#f5f5f5;}  
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
          <a href="booking_status.php">My Bookings</a>
          <a class="active" href="my_payments.php">My Payments</a>
          <a href="my_materials.php">My Materials</a>
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

<div class="container">
  <div class="page-wrap">
    <?php if ($is_multi): ?>
      <a href="my_payments.php" class="back-link"><i class="bi bi-arrow-left"></i> Back to Payments</a>
    <?php else: ?>
      <a href="booking_detail.php?id=<?= $first_booking['id'] ?>" class="back-link"><i class="bi bi-arrow-left"></i> Back to Booking</a>
    <?php endif; ?>

    <?php if (isset($error)): ?>
      <div class="error-box"><i class="bi bi-exclamation-circle"></i> <?= e($error) ?></div>
    <?php endif; ?>

    <div class="payment-grid">
      <!-- LEFT -->
      <div>
        <form method="POST" id="paymentForm" enctype="multipart/form-data">
          <!-- Hidden inputs for all booking IDs -->
          <?php foreach ($bookings as $booking): ?>
            <input type="hidden" name="booking_ids[]" value="<?= $booking['id'] ?>">
          <?php endforeach; ?>
          
          <!-- Payment Method -->
          <div class="card">
            <div class="card-title"><i class="bi bi-credit-card"></i> Select Payment Method</div>
            <?php $hasOnlineSession = false; foreach ($bookings as $booking) { if ($booking['learning_mode'] === 'online') $hasOnlineSession = true; } ?>
            <div class="method-grid">
              <label class="method-card" onclick="selectMethod(this,'stripe')">
                <input type="radio" name="payment_method" value="stripe">
                <div class="check">✓</div>
                <span class="method-icon">💳</span>
                <div class="method-name">Credit / Debit Card</div>
                <div class="method-desc">Visa, Mastercard</div>
              </label>
              <label class="method-card" onclick="selectMethod(this,'online_banking')">
                <input type="radio" name="payment_method" value="online_banking">
                <div class="check">✓</div>
                <span class="method-icon">🏦</span>
                <div class="method-name">Online Banking</div>
                <div class="method-desc">FPX, Bank Transfer</div>
              </label>
              <label class="method-card" onclick="selectMethod(this,'duitnow')">
                <input type="radio" name="payment_method" value="duitnow">
                <div class="check">✓</div>
                <span class="method-icon">📱</span>
                <div class="method-name">DuitNow</div>
                <div class="method-desc">QR, mobile wallet</div>
              </label>
            </div>
            
            <div id="payDetails" style="display:none;margin-top:20px;padding-top:20px;border-top:1px solid rgba(46,42,59,.08);">
              <div id="detailsStripe" style="display:none;padding:16px;border-radius:16px;background:rgba(99,91,255,.06);border:1px solid rgba(99,91,255,.15);text-align:center;">
                <p style="margin:0 0 12px;font-size:13px;font-weight:900;color:#4B44E0;"><i class="bi bi-shield-lock-fill"></i> Secure payment powered by Stripe</p>
                <button type="button" onclick="submitStripe()" class="btn-primary"><i class="bi bi-credit-card"></i> Pay with Card</button>
              </div>
              <div id="detailsBanking" style="display:none;padding:16px;border-radius:16px;background:rgba(221,244,230,.5);border:1px solid rgba(45,106,66,.2);text-align:center;">
                <p style="margin:0 0 12px;font-size:13px;font-weight:900;color:#2D6A42;"><i class="bi bi-bank"></i> Transfer to this account:</p>
                <div style="display:grid;gap:8px;">
                  <div style="display:flex;justify-content:space-between;font-size:13px;padding:8px 0;border-bottom:1px solid rgba(45,106,66,.15);">
                    <span style="color:#4A7A55;font-weight:700;">Bank</span><strong>Maybank</strong>
                  </div>
                  <div style="display:flex;justify-content:space-between;font-size:13px;padding:8px 0;border-bottom:1px solid rgba(45,106,66,.15);">
                    <span style="color:#4A7A55;font-weight:700;">Account Name</span><strong>Kyoshi Education Sdn Bhd</strong>
                  </div>
                  <div style="display:flex;justify-content:space-between;font-size:13px;padding:8px 0;">
                    <span style="color:#4A7A55;font-weight:700;">Account Number</span><strong style="letter-spacing:1px;">1234 5678 9012</strong>
                  </div>
                </div>
                <p style="margin:12px 0 0;font-size:12px;color:#4A7A55;font-weight:700;"><i class="bi bi-info-circle"></i> Use booking ID(s) as reference.</p>
              </div>
              <div id="detailsDuitnow" style="display:none;padding:16px;border-radius:16px;background:rgba(221,244,230,.5);border:1px solid rgba(45,106,66,.2);text-align:center;">
                <p style="margin:0 0 12px;font-size:13px;font-weight:900;color:#2D6A42;"><i class="bi bi-qr-code"></i> Scan this QR to pay:</p>
                <img src="../assets/img/duitnow_qr.png" alt="DuitNow QR" style="width:180px;height:180px;object-fit:contain;border-radius:12px;border:2px solid rgba(45,106,66,.2);background:white;padding:8px;">
                <p style="margin:12px 0 0;font-size:13px;font-weight:900;color:#2D6A42;">Kyoshi Education Sdn Bhd</p>
                <p style="margin:4px 0 0;font-size:12px;color:#4A7A55;font-weight:700;">Reference: Use booking ID(s)</p>
              </div>
            </div>
          </div>
          
          <div class="card">
            <div class="card-title"><i class="bi bi-cash-coin"></i> Payment Amount</div>
            <div style="text-align:center;padding:16px;border-radius:16px;background:linear-gradient(135deg,rgba(231,90,155,.10),rgba(255,195,216,.15));border:1px solid rgba(242,138,178,.22);">
              <p style="margin:0 0 4px;font-size:12px;color:var(--muted);font-weight:700;"><?= $is_multi ? 'Total Amount' : 'Session Rate' ?></p>
              <strong style="font-size:36px;color:var(--hot-pink);font-weight:900;">RM <?= number_format($total_amount, 2) ?></strong>
              <?php if ($is_multi): ?>
                <p style="margin:8px 0 0;font-size:12px;color:var(--muted);"><?= count($bookings) ?> sessions total</p>
              <?php else: ?>
                <p style="margin:8px 0 0;font-size:12px;color:var(--muted);">per hour · non-refundable after session</p>
              <?php endif; ?>
            </div>
            <div class="info-note" id="paymentStatusMsg" style="margin-top:12px;">
              <i class="bi bi-info-circle"></i> Select a payment method above to continue.
            </div>
          </div>

          <div id="proofUploadGroup" style="display:none;margin-top:16px;">
            <div class="card" style="margin-bottom:20px;">
              <div class="card-title"><i class="bi bi-upload"></i> Upload Payment Proof</div>
              <p style="margin:0 0 12px;font-size:13px;color:var(--muted);">(Required for Online Banking & DuitNow only)</p>
              <input type="file" name="proof_image" accept="image/*,.pdf" style="width:100%;padding:12px 16px;border:1px solid rgba(46,42,59,.12);border-radius:14px;font-size:13px;background:rgba(255,255,255,.9);cursor:pointer;">
              <p style="margin:8px 0 0;font-size:11px;color:var(--muted);font-weight:700;"><i class="bi bi-info-circle"></i> Accepted: JPG, PNG, PDF. Max 5MB.</p>
            </div>
          </div>

          <div class="card">
            <div class="card-title"><i class="bi bi-chat-left-text"></i> Payment Notes <span style="font-weight:400;color:var(--muted);font-size:12px;">(optional)</span></div>
            <textarea name="notes" class="form-control" placeholder="e.g. Transferred via Maybank at 3pm..." style="min-height:80px;resize:vertical;"></textarea>
          </div>
        </form>
      </div>

      <!-- RIGHT SUMMARY -->
      <div class="summary-card">
        <h3 style="margin:0 0 16px;font-size:18px;"><?= $is_multi ? 'Payment Summary (' . count($bookings) . ' sessions)' : 'Payment Summary' ?></h3>
        
        <?php if ($is_multi): ?>
          <?php foreach ($bookings as $booking): ?>
          <div class="summary-row" style="flex-direction:column;align-items:flex-start;padding:8px 0;">
            <div style="display:flex;justify-content:space-between;width:100%;">
              <span class="summary-label"><?= e($booking['language']) ?> with <?= e($booking['tutor_name']) ?></span>
              <span class="summary-val">RM <?= number_format($booking['rate'], 2) ?></span>
            </div>
            <div style="font-size:11px;color:var(--muted);">
              <?= date('d M Y, g:i A', strtotime($booking['booking_date'] . ' ' . $booking['booking_time'])) ?>
            </div>
          </div>
          <?php endforeach; ?>
          <div class="summary-row" style="border-top:1px solid rgba(46,42,59,.1);margin-top:8px;padding-top:12px;">
            <span class="summary-label" style="font-weight:900;">Total Amount</span>
            <span class="summary-val" style="font-size:20px;color:var(--hot-pink);">RM <?= number_format($total_amount, 2) ?></span>
          </div>
        <?php else: ?>
          <?php $b = $first_booking; ?>
          <div class="summary-row">
            <span class="summary-label">Tutor</span>
            <span class="summary-val"><?= e($b['tutor_name']) ?></span>
          </div>
          <div class="summary-row">
            <span class="summary-label">Language</span>
            <span class="summary-val"><?= e($b['language']) ?></span>
          </div>
          <div class="summary-row">
            <span class="summary-label">Date</span>
            <span class="summary-val"><?= date('d M Y', strtotime($b['booking_date'])) ?></span>
          </div>
          <div class="summary-row">
            <span class="summary-label">Time</span>
            <span class="summary-val"><?= date('g:i A', strtotime($b['booking_time'])) ?></span>
          </div>
          <div class="summary-row">
            <span class="summary-label">Mode</span>
            <span class="summary-val"><?= $b['learning_mode']==='online'?'💻 Online':'🤝 Face to Face' ?></span>
          </div>
        <?php endif; ?>
        
        <div class="amount-box">
          <p>Total Amount</p>
          <strong id="summaryAmount">RM <?= number_format($total_amount, 2) ?></strong>
        </div>
        
        <button class="btn-primary" style="margin-top:16px;" onclick="submitPayment()">
          <i class="bi bi-lock-fill"></i> Confirm Payment
        </button>
        
        <p id="paymentInfo" style="font-size:11px;color:var(--muted);text-align:center;margin-top:12px;line-height:1.5;"></p>
      </div>
    </div>
  </div>
</div>

<div class="toast" id="toast"></div>

<script>
  let selectedMethod = null;
  let isMulti = <?= json_encode($is_multi) ?>;
  let totalAmount = <?= $total_amount ?>;
  let firstBookingId = <?= $first_booking['id'] ?>;

  function submitPayment() {
    if (!selectedMethod) {
        showToast('Please select a payment method');
        return;
    }

    if (selectedMethod === 'cash' && <?= json_encode($hasOnlineSession) ?>) {
        showToast('Cash payment is not allowed for online sessions');
        return;
    }

    if (selectedMethod === 'stripe') {
        submitStripe();
        return;
    }

    if ((selectedMethod === 'online_banking' || selectedMethod === 'duitnow')) {
        const file = document.querySelector('input[name="proof_image"]').files[0];
        if (!file) {
            showToast('Please upload payment proof');
            return;
        }
    }

    document.getElementById('paymentForm').submit();
  }

  function selectMethod(el, val) {
    document.querySelectorAll('.method-card').forEach(c => c.classList.remove('selected'));
    el.classList.add('selected');
    selectedMethod = val;
    el.querySelector('input').checked = true;

    document.getElementById('payDetails').style.display = 'block';
    document.getElementById('detailsStripe').style.display = val === 'stripe' ? 'block' : 'none';
    document.getElementById('detailsBanking').style.display = val === 'online_banking' ? 'block' : 'none';
    document.getElementById('detailsDuitnow').style.display = val === 'duitnow' ? 'block' : 'none';

    document.getElementById('proofUploadGroup').style.display = (val === 'cash' || val === 'stripe') ? 'none' : 'block';

    const paymentInfo = document.getElementById('paymentInfo');
    const paymentStatusMsg = document.getElementById('paymentStatusMsg');

    if (val === 'stripe') {
        paymentInfo.innerHTML = '<i class="bi bi-shield-check"></i> Card → Instant confirmation';
        paymentStatusMsg.innerHTML = '<i class="bi bi-info-circle"></i> Card payment will be confirmed immediately after successful payment';
    } else if (val === 'online_banking' || val === 'duitnow') {
        paymentInfo.innerHTML = '<i class="bi bi-shield-check"></i> Banking / DuitNow → Admin verification required';
        paymentStatusMsg.innerHTML = '<i class="bi bi-info-circle"></i> Upload payment proof for admin verification';
    } else if (val === 'cash') {
        paymentInfo.innerHTML = '<i class="bi bi-shield-check"></i> Cash → Tutor confirms after session';
        paymentStatusMsg.innerHTML = '<i class="bi bi-info-circle"></i> Pay tutor during session. No payment proof required';
    }
  }

  function submitStripe() {
    if (isMulti) {
        // For multiple bookings, send all booking IDs to Stripe
        const bookingIds = <?= json_encode(array_column($bookings, 'id')) ?>;
        window.location.href = 'create_stripe_session.php?booking_ids=' + bookingIds.join(',');
    } else {
        window.location.href = 'create_stripe_session.php?booking_id=' + firstBookingId;
    }
  }

  let toastTimer;
  function showToast(msg) {
    const t = document.getElementById('toast');
    t.textContent = msg; t.classList.add('show');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => t.classList.remove('show'), 2000);
  }

  function toggleDropdown() {
    const d = document.getElementById('profileDropdown');
    d.style.display = d.style.display === 'none' ? 'block' : 'none';
  }

  document.addEventListener('click', function(e) {
    const btn = document.getElementById('profileBtn');
    const dd = document.getElementById('profileDropdown');
    if (btn && dd && !btn.contains(e.target) && !dd.contains(e.target)) dd.style.display = 'none';
  });
</script>
</body>
</html>