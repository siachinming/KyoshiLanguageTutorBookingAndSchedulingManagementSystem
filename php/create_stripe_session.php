<?php
session_start();
include 'config.php';
require_once '../vendor/autoload.php';

if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit(); }
$userID = $_SESSION['user_id'];

$booking_ids = [];
if (isset($_GET['booking_ids'])) {
    $booking_ids = array_map('intval', explode(',', $_GET['booking_ids']));
} elseif (isset($_GET['booking_id'])) {
    $booking_ids = [intval($_GET['booking_id'])];
}

if (empty($booking_ids)) {
    header("Location: my_payments.php?error=no_booking_selected");
    exit();
}

$placeholders = implode(',', array_fill(0, count($booking_ids), '?'));
$types = str_repeat('i', count($booking_ids));

$stmt = $conn->prepare("
    SELECT b.id, b.language, b.booking_date, b.tutor_id, b.status,
           u.fullname AS tutor_name, tp.rate
    FROM bookings b
    JOIN users u ON b.tutor_id = u.id
    JOIN tutor_profiles tp ON b.tutor_id = tp.user_id
    WHERE b.id IN ($placeholders) AND b.student_id = ? AND b.status IN ('accepted','confirmed')
");
$all_params = array_merge($booking_ids, [$userID]);
$stmt->bind_param($types . 'i', ...$all_params);
$stmt->execute();
$bookings = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

if (empty($bookings)) {
    header("Location: my_payments.php?error=no_bookings_found");
    exit();
}

// Check none already paid
foreach ($bookings as $booking) {
    $chk = $conn->prepare("SELECT id FROM payments WHERE booking_id=? AND student_id=? AND status='verified'");
    $chk->bind_param("ii", $booking['id'], $userID);
    $chk->execute();
    if ($chk->get_result()->fetch_assoc()) {
        header("Location: my_payments.php?error=already_paid");
        exit();
    }
    $chk->close();
}

$total_amount = array_sum(array_column($bookings, 'rate'));
$amount_cents = (int) round($total_amount * 100);
$is_multi     = count($bookings) > 1;
$first        = $bookings[0];

try {
    \Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);

    $session = \Stripe\Checkout\Session::create([
        'payment_method_types' => ['card'],
        'line_items' => [[
            'price_data' => [
                'currency'     => 'myr',
                'unit_amount'  => $amount_cents,
                'product_data' => [
                    'name'        => $is_multi ? 'Multiple Language Sessions' : $first['language'] . ' Lesson',
                    'description' => $is_multi
                        ? count($bookings) . ' sessions · RM ' . number_format($total_amount, 2)
                        : 'Booking #' . $first['id'] . ' · ' . date('d M Y', strtotime($first['booking_date'])),
                ],
            ],
            'quantity' => 1,
        ]],
        'mode'        => 'payment',
        'success_url' => 'http://localhost/Kyoshi/php/stripe_success.php?session_id={CHECKOUT_SESSION_ID}&booking_ids=' . implode(',', $booking_ids),
        'cancel_url'  => 'http://localhost/Kyoshi/php/my_payments.php?cancelled=1',
        'metadata'    => [
            'student_id'  => $userID,
            'booking_ids' => implode(',', $booking_ids),
        ],
    ]);

    header("Location: " . $session->url);
    exit();

} catch (Exception $e) {
    error_log("Stripe Error: " . $e->getMessage());
    header("Location: my_payments.php?error=stripe_error&msg=" . urlencode($e->getMessage()));
    exit();
}
?>