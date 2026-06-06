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
$is_partial = isset($_GET['is_partial']) && $_GET['is_partial'] == '1';
$original_payment_id = isset($_GET['original_payment']) ? intval($_GET['original_payment']) : 0;

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
    
    // =============================================
    // PARTIAL PAYMENT - UPDATE ORIGINAL PAYMENT
    // =============================================
    if ($is_partial && $original_payment_id > 0) {
        // Get original payment details
        $origStmt = $conn->prepare("SELECT amount, actual_paid_amount, booking_id FROM payments WHERE id = ? AND student_id = ?");
        $origStmt->bind_param("ii", $original_payment_id, $student_id);
        $origStmt->execute();
        $origPayment = $origStmt->get_result()->fetch_assoc();
        $origStmt->close();
        
        if ($origPayment) {
            // Get the amount paid in this Stripe session
            $amount_paid_this_session = $session->amount_total / 100;
            $already_paid = $origPayment['actual_paid_amount'] ?? 0;
            $total_paid = $already_paid + $amount_paid_this_session;
            
            // UPDATE the original payment to VERIFIED status
            $updateOriginal = $conn->prepare("
                UPDATE payments 
                SET status = 'verified', 
                    actual_paid_amount = ?,
                    receipt_number = ?,
                    receipt_url = ?,
                    verified_at = NOW(),
                    notes = CONCAT(IFNULL(notes,''), ' | Remaining amount (RM " . number_format($amount_paid_this_session, 2) . ") paid via Stripe on " . date('Y-m-d H:i:s') . "')
                WHERE id = ?
            ");
            $updateOriginal->bind_param("dssi", $total_paid, $receiptNo, $receiptUrl, $original_payment_id);
            $updateOriginal->execute();
            $updateOriginal->close();
            
            // Update booking status to confirmed
            $updBooking = $conn->prepare("UPDATE bookings SET status = 'confirmed' WHERE id = ?");
            $updBooking->bind_param("i", $origPayment['booking_id']);
            $updBooking->execute();
            $updBooking->close();
            
            // Redirect to success page
            header("Location: booking_detail.php?id=" . $origPayment['booking_id'] . "&paid=1");
            exit();
        }
    }
    
    // =============================================
    // REGULAR PAYMENT - Create new payment record
    // =============================================
    foreach ($booking_ids as $booking_id) {
        $stmt = $conn->prepare("SELECT b.id, b.tutor_id, tp.rate FROM bookings b JOIN tutor_profiles tp ON b.tutor_id = tp.user_id WHERE b.id = ? AND b.student_id = ?");
        $stmt->bind_param("ii", $booking_id, $student_id);
        $stmt->execute();
        $booking = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$booking) continue;

        $amount_to_record = $booking['rate'];

        $chk = $conn->prepare("SELECT id FROM payments WHERE booking_id = ? AND student_id = ?");
        $chk->bind_param("ii", $booking_id, $student_id);
        $chk->execute();
        $existing = $chk->get_result()->fetch_assoc();
        $chk->close();

        if ($existing) {
            // Update existing payment
            $upd = $conn->prepare("UPDATE payments SET status='verified', amount=?, receipt_number=?, receipt_url=?, payment_method='stripe', verified_at=NOW() WHERE booking_id=? AND student_id=?");
            $upd->bind_param("dssii", $amount_to_record, $receiptNo, $receiptUrl, $booking_id, $student_id);
            $upd->execute();
            $upd->close();
        } else {
            // Insert new payment
            $ins = $conn->prepare("INSERT INTO payments (booking_id, student_id, tutor_id, amount, payment_method, status, receipt_number, receipt_url, created_at, verified_at) VALUES (?,?,?,?,'stripe','verified',?,?,NOW(),NOW())");
            $ins->bind_param("iiidss", $booking_id, $student_id, $booking['tutor_id'], $amount_to_record, $receiptNo, $receiptUrl);
            $ins->execute();
            $ins->close();
        }

        // Update booking status
        $updBooking = $conn->prepare("UPDATE bookings SET status='confirmed' WHERE id=? AND student_id=?");
        $updBooking->bind_param("ii", $booking_id, $student_id);
        $updBooking->execute();
        $updBooking->close();
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