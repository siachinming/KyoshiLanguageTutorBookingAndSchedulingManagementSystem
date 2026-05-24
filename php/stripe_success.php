<?php
session_start();
include 'config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$student_id  = $_SESSION['user_id'];
$session_id  = $_GET['session_id'] ?? '';
$booking_ids = array_map('intval', explode(',', $_GET['booking_ids'] ?? ''));

if (empty($booking_ids) || empty($session_id)) {
    header("Location: my_payments.php?error=invalid_stripe_callback");
    exit();
}

require_once '../vendor/autoload.php';

try {
    \Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);

    $session = \Stripe\Checkout\Session::retrieve($session_id);

    if ($session->payment_status !== 'paid') {
        header("Location: my_payments.php?error=payment_not_completed");
        exit();
    }

    // Get receipt info from Stripe
    $receiptNo     = $session->payment_intent;
    $paymentIntent = \Stripe\PaymentIntent::retrieve($session->payment_intent);
    $charge        = \Stripe\Charge::retrieve($paymentIntent->latest_charge);
    $receiptUrl    = $charge->receipt_url;

    foreach ($booking_ids as $booking_id) {
        $stmt = $conn->prepare("SELECT b.id, b.tutor_id, tp.rate FROM bookings b JOIN tutor_profiles tp ON b.tutor_id = tp.user_id WHERE b.id = ? AND b.student_id = ?");
        $stmt->bind_param("ii", $booking_id, $student_id);
        $stmt->execute();
        $booking = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$booking) continue;

        $amount   = $booking['rate'];
        $tutor_id = $booking['tutor_id'];

        $chk = $conn->prepare("SELECT id FROM payments WHERE booking_id = ? AND student_id = ?");
        $chk->bind_param("ii", $booking_id, $student_id);
        $chk->execute();
        $existing = $chk->get_result()->fetch_assoc();
        $chk->close();

        if ($existing) {
            $upd = $conn->prepare("UPDATE payments SET status='verified', amount=?, receipt_number=?, receipt_url=?, payment_method='card', created_at=NOW() WHERE booking_id=? AND student_id=?");
            $upd->bind_param("dssii", $amount, $receiptNo, $receiptUrl, $booking_id, $student_id);
            $upd->execute();
            $upd->close();
        } else {
            $ins = $conn->prepare("INSERT INTO payments (booking_id, student_id, tutor_id, amount, payment_method, status, receipt_number, receipt_url, created_at) VALUES (?,?,?,?,'stripe','verified',?,?,NOW())");
            $ins->bind_param("iiidss", $booking_id, $student_id, $tutor_id, $amount, $receiptNo, $receiptUrl);
            $ins->execute();
            $ins->close();
        }

        $upd = $conn->prepare("UPDATE bookings SET status='confirmed' WHERE id=? AND student_id=?");
        $upd->bind_param("ii", $booking_id, $student_id);
        $upd->execute();
        $upd->close();
    }

    if (count($booking_ids) > 1) {
        header("Location: my_payments.php?success=stripe_payment_completed&count=" . count($booking_ids));
    } else {
        header("Location: booking_detail.php?id=" . $booking_ids[0] . "&paid=1");
    }
    exit();

} catch (Exception $e) {
    error_log("Stripe Success Error: " . $e->getMessage());
    header("Location: my_payments.php?error=stripe_verification_failed&msg=" . urlencode($e->getMessage()));
    exit();
}
?>