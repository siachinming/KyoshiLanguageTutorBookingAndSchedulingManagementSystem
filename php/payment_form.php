<?php
session_start();
include 'config.php';
$assetBase = '../assets/img';

if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit(); }
$userID = $_SESSION['user_id'];

$bookingID = intval($_GET['booking_id'] ?? 0);
if (!$bookingID) { header("Location: booking_status.php"); exit(); }

// Get booking + payment info
$stmt = $conn->prepare("
    SELECT b.*, u.fullname AS tutor_name, u.profile_pic AS tutor_pic,
           tp.rate, p.id AS payment_id, p.status AS payment_status
    FROM bookings b
    JOIN users u ON b.tutor_id = u.id
    JOIN tutor_profiles tp ON b.tutor_id = tp.user_id
    LEFT JOIN payments p ON p.booking_id = b.id
    WHERE b.id = ? AND b.student_id = ? AND b.status = 'accepted'
");
$stmt->bind_param("ii", $bookingID, $userID);
$stmt->execute();
$b = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$b) { header("Location: booking_status.php"); exit(); }
if ($b['payment_status'] === 'verified') {
    header("Location: booking_detail.php?id=$bookingID");
    exit();
}

// Get student info
$stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
$stmt->bind_param("i", $userID);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

$displayName = $user['fullname'];
$profilePic  = !empty($user['profile_pic']) ? '../uploads/profiles/' . $user['profile_pic'] : $assetBase . '/profile-student.png';
$tutorPic    = !empty($b['tutor_pic']) ? '../uploads/profiles/' . $b['tutor_pic'] : $assetBase . '/profile-tutor.png';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $method = trim($_POST['payment_method'] ?? '');
    $amount = floatval($_POST['amount'] ?? 0);
    $notes  = trim($_POST['notes'] ?? '');

    if (!$method || $amount <= 0) {
        $error = 'Please fill in all payment details.';
    } else {
        // Handle proof upload
        $proofImage = null;
        if (isset($_FILES['proof_image']) && $_FILES['proof_image']['error'] === 0) {
            $allowed = ['image/jpeg','image/png','image/jpg','image/webp','application/pdf'];
            if (in_array($_FILES['proof_image']['type'], $allowed)) {
                $ext      = pathinfo($_FILES['proof_image']['name'], PATHINFO_EXTENSION);
                $filename = 'proof_' . $bookingID . '_' . time() . '.' . $ext;
                $dest     = '../uploads/payment_proofs/' . $filename;
                if (move_uploaded_file($_FILES['proof_image']['tmp_name'], $dest)) {
                    $proofImage = $filename;
                }
            } else {
                $error = 'Invalid file type. Please upload JPG, PNG, or PDF.';
            }
        }

        if (!isset($error)) {
            $receiptNo = 'RCP-' . date('Y') . '-' . str_pad(rand(1, 99999), 6, '0', STR_PAD_LEFT);

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
            $stmt->bind_param("iiidssss", $bookingID, $userID, $b['tutor_id'], $amount, $method, $receiptNo, $notes, $proofImage);
            $stmt->execute();
            $stmt->close();

            header("Location: booking_detail.php?id=$bookingID&paid=1");
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
    .container{width:min(1100px,calc(100% - 40px));margin:0 auto}

    .topbar{position:sticky;top:0;z-index:50;background:rgba(255,241,246,.86);backdrop-filter:blur(20px);border-bottom:1px solid rgba(231,90,155,.18);box-shadow:0 10px 30px rgba(201,79,134,.10)}
    .nav{min-height:78px;display:grid;grid-template-columns:190px minmax(0,1fr) auto;gap:16px;align-items:center;}
    .brand{display:flex;align-items:center;gap:10px}
    .brand img{width:44px;height:44px;object-fit:contain;border-radius:14px}
    .brand strong{display:block;font-size:18px;line-height:1.05}
    .brand span{display:block;margin-top:3px;font-size:11px;color:var(--muted);white-space:nowrap}
    .nav-links{display:flex;align-items:center;justify-content:center;gap:6px;background:rgba(255,255,255,.58);border:1px solid rgba(242,138,178,.18);border-radius:999px;padding:7px;overflow:auto;scrollbar-width:none;}
    .nav-links::-webkit-scrollbar{display:none}
    .nav-links a{flex:0 0 auto;padding:9px 12px;border-radius:999px;font-size:13px;font-weight:900;color:#6D4964;white-space:nowrap;transition:.18s ease}
    .nav-links a.active,.nav-links a:hover{background:linear-gradient(135deg,var(--hot-pink),var(--pink));color:#fff}
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

    /* METHOD CARDS */
    .method-grid{display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;margin-bottom:20px}
    .method-card{border:2px solid rgba(46,42,59,.10);border-radius:18px;padding:18px 12px;text-align:center;cursor:pointer;transition:.18s ease;background:white;position:relative}
    .method-card:hover{border-color:var(--pink);transform:translateY(-2px)}
    .method-card.selected{border-color:var(--hot-pink);background:rgba(231,90,155,.06);box-shadow:0 6px 20px rgba(231,90,155,.15)}
    .method-card .method-icon{font-size:28px;margin-bottom:8px;display:block}
    .method-card .method-name{font-size:13px;font-weight:900;color:var(--ink)}
    .method-card .method-desc{font-size:11px;color:var(--muted);margin-top:3px}
    .method-card input[type=radio]{position:absolute;opacity:0;width:0;height:0}
    .method-card .check{position:absolute;top:10px;right:10px;width:20px;height:20px;border-radius:50%;border:2px solid rgba(46,42,59,.15);background:white;display:grid;place-items:center;font-size:11px;transition:.15s ease}
    .method-card.selected .check{background:var(--hot-pink);border-color:var(--hot-pink);color:white}

    /* FORM */
    .form-group{margin-bottom:16px}
    .form-group label{display:block;font-size:13px;font-weight:900;color:#342635;margin-bottom:7px}
    .form-group label i{color:var(--hot-pink);margin-right:5px}
    .form-control{width:100%;padding:12px 16px;border:1px solid rgba(46,42,59,.12);border-radius:14px;outline:none;font-size:14px;color:#342635;background:rgba(255,255,255,.9);transition:.15s ease}
    .form-control:focus{border-color:var(--hot-pink);box-shadow:0 0 0 3px rgba(231,90,155,.12)}

    /* SUMMARY */
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
  </style>
</head>
<body>

<header class="topbar">
  <div class="container">
    <nav class="nav">
      <a href="student_dashboard.php" class="brand">
        <img src="<?= e($assetBase) ?>/logo.png" alt="Kyoshi">
        <div><strong>Kyoshi</strong><span>Student Learning Space</span></div>
      </a>
      <div class="nav-links">
        <a href="student_dashboard.php">Home</a>
        <a href="student_dashboard.php#preferences">Learning Goals</a>
        <a href="find_language.php">Find Language</a>
        <a href="booking_status.php" class="active">Bookings</a>
        <a href="student_dashboard.php#progress">Progress</a>
        <a href="student_dashboard.php#payments">Payments</a>
      </div>
      <div class="nav-actions">
        <div style="position:relative;">
          <button class="profile" onclick="toggleDropdown()" id="profileBtn">
            <img src="<?= e($profilePic) ?>" alt="Profile">
            <span><?= e($displayName) ?></span>
            <i class="bi bi-chevron-down" style="font-size:11px;margin-left:4px;"></i>
          </button>
          <div id="profileDropdown" style="display:none;position:absolute;top:calc(100% + 10px);right:0;background:white;border-radius:16px;box-shadow:0 18px 45px rgba(201,79,134,.2);border:1px solid rgba(242,138,178,.2);min-width:180px;overflow:hidden;z-index:100;">
            <a href="student_profile.php" style="display:flex;align-items:center;gap:10px;padding:14px 16px;font-size:14px;font-weight:700;color:#342635;" onmouseover="this.style.background='#FFF1F6'" onmouseout="this.style.background='white'"><i class="bi bi-person-circle" style="color:#E75A9B;"></i> My Profile</a>
            <a href="student_favourites.php" style="display:flex;align-items:center;gap:10px;padding:14px 16px;font-size:14px;font-weight:700;color:#342635;" onmouseover="this.style.background='#FFF1F6'" onmouseout="this.style.background='white'"><i class="bi bi-heart" style="color:#E75A9B;"></i> My Favourites</a>
            <hr style="margin:4px 0;border-color:rgba(242,138,178,.2);">
            <a href="logout.php" style="display:flex;align-items:center;gap:10px;padding:14px 16px;font-size:14px;font-weight:700;color:#dc2626;" onmouseover="this.style.background='#FFF1F6'" onmouseout="this.style.background='white'"><i class="bi bi-box-arrow-right"></i> Logout</a>
          </div>
        </div>
      </div>
    </nav>
  </div>
</header>

<div class="container">
  <div class="page-wrap">
    <a href="booking_detail.php?id=<?= $bookingID ?>" class="back-link"><i class="bi bi-arrow-left"></i> Back to Booking</a>

    <?php if (isset($error)): ?>
      <div class="error-box"><i class="bi bi-exclamation-circle"></i> <?= e($error) ?></div>
    <?php endif; ?>

    <div class="payment-grid">
      <!-- LEFT -->
      <div>
        <form method="POST" id="paymentForm" enctype="multipart/form-data">
          <!-- Payment Method -->
          <div class="card">
            <div class="card-title"><i class="bi bi-credit-card"></i> Select Payment Method</div>
            <div class="method-grid">
              <label class="method-card" onclick="selectMethod(this,'stripe')">
              <input type="radio" name="payment_method" value="stripe">
              <div class="check">✓</div>
              <span class="method-icon">💳</span>
              <div class="method-name">Credit / Debit Card</div>
              <div class="method-desc">Visa, Mastercard, FPX</div>
            </label>
              <label class="method-card" onclick="selectMethod(this,'online_banking')">
                <input type="radio" name="payment_method" value="online_banking">
                <div class="check">✓</div>
                <span class="method-icon">🏦</span>
                <div class="method-name">Online Banking</div>
                <div class="method-desc">FPX / Bank Transfer</div>
              </label>
              <label class="method-card" onclick="selectMethod(this,'duitnow')">
                <input type="radio" name="payment_method" value="duitnow">
                <div class="check">✓</div>
                <span class="method-icon">📱</span>
                <div class="method-name">DuitNow / TnG</div>
                <div class="method-desc">QR or mobile wallet</div>
              </label>
              <label class="method-card" onclick="selectMethod(this,'cash')">
                <input type="radio" name="payment_method" value="cash">
                <div class="check">✓</div>
                <span class="method-icon">💵</span>
                <div class="method-name">Cash</div>
                <div class="method-desc">Pay in person</div>
              </label>
              </div>

<!-- Payment Details (shows based on method) -->
<div id="payDetails" style="display:none;margin-top:16px;">
<div id="detailsStripe" style="display:none;padding:16px;border-radius:16px;background:rgba(99,91,255,.06);border:1px solid rgba(99,91,255,.15);">
  <p style="margin:0 0 12px;font-size:13px;font-weight:900;color:#4B44E0;">
    <i class="bi bi-shield-lock-fill"></i>
    Secure payment powered by Stripe
  </p>

  <div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:14px;">
    <span class="method-badge">💳 Visa</span>
    <span class="method-badge">💳 Mastercard</span>
    <span class="method-badge">🏦 FPX</span>
    <span class="method-badge">📱 GrabPay</span>
  </div>

  <button type="button"
    onclick="window.location.href='create_stripe_session.php?booking_id=<?= $bookingID ?>'"
    class="btn-primary">
    <i class="bi bi-credit-card"></i>
    Pay with Stripe
  </button>
</div>
  <!-- Online Banking Details -->
  <div id="detailsBanking" style="display:none;padding:16px;border-radius:16px;background:rgba(221,244,230,.5);border:1px solid rgba(45,106,66,.2);">
    <p style="margin:0 0 12px;font-size:13px;font-weight:900;color:#2D6A42;"><i class="bi bi-bank"></i> Transfer to this account:</p>
    <div style="display:grid;gap:8px;">
      <div style="display:flex;justify-content:space-between;font-size:13px;padding:8px 0;border-bottom:1px solid rgba(45,106,66,.15);">
        <span style="color:#4A7A55;font-weight:700;">Bank</span>
        <strong>Maybank</strong>
      </div>
      <div style="display:flex;justify-content:space-between;font-size:13px;padding:8px 0;border-bottom:1px solid rgba(45,106,66,.15);">
        <span style="color:#4A7A55;font-weight:700;">Account Name</span>
        <strong>Kyoshi Education Sdn Bhd</strong>
      </div>
      <div style="display:flex;justify-content:space-between;font-size:13px;padding:8px 0;">
        <span style="color:#4A7A55;font-weight:700;">Account Number</span>
        <strong style="letter-spacing:1px;">1234 5678 9012</strong>
      </div>
    </div>
    <p style="margin:12px 0 0;font-size:12px;color:#4A7A55;font-weight:700;"><i class="bi bi-info-circle"></i> Please use your booking ID <strong>#<?= $bookingID ?></strong> as the payment reference.</p>
  </div>

  <!-- DuitNow Details -->
  <div id="detailsDuitnow" style="display:none;padding:16px;border-radius:16px;background:rgba(221,244,230,.5);border:1px solid rgba(45,106,66,.2);text-align:center;">
    <p style="margin:0 0 12px;font-size:13px;font-weight:900;color:#2D6A42;"><i class="bi bi-qr-code"></i> Scan this QR to pay:</p>
    <img src="../assets/img/duitnow_qr.png" alt="DuitNow QR"
         style="width:180px;height:180px;object-fit:contain;border-radius:12px;border:2px solid rgba(45,106,66,.2);background:white;padding:8px;">
    <p style="margin:12px 0 0;font-size:13px;font-weight:900;color:#2D6A42;">Kyoshi Education Sdn Bhd</p>
    <p style="margin:4px 0 0;font-size:12px;color:#4A7A55;font-weight:700;"><i class="bi bi-info-circle"></i> Reference: <strong>#<?= $bookingID ?></strong></p>
  </div>

  <!-- Cash Details -->
  <div id="detailsCash" style="display:none;padding:16px;border-radius:16px;background:rgba(255,241,246,.6);border:1px solid rgba(242,138,178,.2);">
    <p style="margin:0 0 8px;font-size:13px;font-weight:900;color:var(--pink-dark);"><i class="bi bi-cash-coin"></i> Cash Payment Instructions:</p>
    <ul style="margin:0;padding-left:18px;font-size:13px;color:#6D4964;line-height:1.8;font-weight:700;">
      <li>Pay your tutor directly on the day of your session.</li>
      <li>Make sure you have the exact amount ready: <strong>RM <?= e($b['rate']) ?></strong></li>
      <li>Ask your tutor for a handwritten receipt after payment.</li>
      <li>No proof upload needed — your tutor will confirm receipt.</li>
    </ul>
  </div>

</div>
</div>

<!-- Amount -->

          <!-- Amount -->
          <div class="card">
            <div class="card-title"><i class="bi bi-cash-coin"></i> Payment Amount</div>
            <div class="form-group">
              <label><i class="bi bi-currency-dollar"></i> Amount (RM)</label>
              <input type="number" name="amount" id="amountInput" class="form-control"
                value="<?= e($b['rate']) ?>" min="1" step="0.01"
                placeholder="Enter amount" onchange="updateAmount(this.value)">
            </div>
            <div class="info-note">
              <i class="bi bi-info-circle"></i>
              Your payment will be reviewed by the tutor before being marked as verified.
              The session rate is <strong>RM <?= e($b['rate']) ?>/hr</strong>.
            </div>
          </div>

          <!-- Notes -->
          <div class="card">
            <div class="card-title"><i class="bi bi-chat-left-text"></i> Payment Notes <span style="font-weight:400;color:var(--muted);font-size:12px;">(optional)</span></div>
            <textarea name="notes" class="form-control" placeholder="e.g. Transferred via Maybank at 3pm..." style="min-height:80px;resize:vertical;"></textarea>
          </div>
        <!-- Proof Upload (not for cash) -->
            <div id="proofUploadGroup" style="display:none;margin-top:16px;">
            <div class="card" style="margin-bottom:0;">
                <div class="card-title"><i class="bi bi-upload"></i> Upload Payment Proof</div>
                <p style="margin:0 0 12px;font-size:13px;color:var(--muted);">Upload a screenshot or PDF of your transfer receipt.</p>
                <input type="file" name="proof_image" accept="image/*,.pdf"
                style="width:100%;padding:12px 16px;border:1px solid rgba(46,42,59,.12);border-radius:14px;font-size:13px;background:rgba(255,255,255,.9);cursor:pointer;">
                <p style="margin:8px 0 0;font-size:11px;color:var(--muted);font-weight:700;"><i class="bi bi-info-circle"></i> Accepted: JPG, PNG, PDF. Max 5MB.</p>
            </div>
            </div>
        </form>
      </div>

      <!-- RIGHT SUMMARY -->
      <div class="summary-card">
        <h3 style="margin:0 0 16px;font-size:18px;">Payment Summary</h3>
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
        <div class="amount-box">
          <p>Total Amount</p>
          <strong id="summaryAmount">RM <?= e($b['rate']) ?></strong>
        </div>
        <button class="btn-primary" style="margin-top:16px;" onclick="submitPayment()">
          <i class="bi bi-lock-fill"></i> Confirm Payment
        </button>
        <p style="font-size:11px;color:var(--muted);text-align:center;margin-top:12px;line-height:1.5;">
          <i class="bi bi-shield-check" style="color:var(--hot-pink);"></i>
          Your payment details are kept secure. The tutor will verify and confirm receipt.
        </p>
      </div>
    </div>
  </div>
</div>

<div class="toast" id="toast"></div>

<script>
  let selectedMethod = null;

  function selectMethod(el, val) {
    document.querySelectorAll('.method-card').forEach(c => c.classList.remove('selected'));
    el.classList.add('selected');
    selectedMethod = val;

    // Show payment details
    document.getElementById('payDetails').style.display = 'block';
    document.getElementById('detailsStripe').style.display   = val === 'stripe' ? 'block' : 'none';
    document.getElementById('detailsBanking').style.display = val === 'online_banking' ? 'block' : 'none';
    document.getElementById('detailsDuitnow').style.display = val === 'duitnow'         ? 'block' : 'none';
    document.getElementById('detailsCash').style.display    = val === 'cash'            ? 'block' : 'none';

    // Show proof upload only for non-cash
    document.getElementById('proofUploadGroup').style.display =
    (val === 'cash' || val === 'stripe') ? 'none' : 'block';
  }

  function updateAmount(val) {
    document.getElementById('summaryAmount').textContent = 'RM ' + parseFloat(val || 0).toFixed(2);
  }

  function submitPayment() {
    if (!selectedMethod) { showToast('Please select a payment method'); return; }
    const amount = parseFloat(document.getElementById('amountInput').value);
    if (!amount || amount <= 0) { showToast('Please enter a valid amount'); return; }
    document.getElementById('paymentForm').submit();
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
    const dd  = document.getElementById('profileDropdown');
    if (btn && dd && !btn.contains(e.target) && !dd.contains(e.target)) dd.style.display = 'none';
  });
</script>
</body>
</html>