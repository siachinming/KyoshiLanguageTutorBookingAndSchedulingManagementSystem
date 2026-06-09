<?php
session_start();
include 'config.php';
require_once '../vendor/autoload.php';

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Debug function
function debug_stripe($msg) {
    error_log("[STRIPE DEBUG] " . $msg);
}

debug_stripe("=== CREATE_STRIPE_SESSION START ===");
debug_stripe("GET params: " . print_r($_GET, true));

// Check if user is logged in
if (!isset($_SESSION['user_id'])) { 
    header("Location: login.php"); 
    exit(); 
}
$userID = $_SESSION['user_id'];

// Check if Stripe key is defined
if (!defined('STRIPE_SECRET_KEY')) {
    debug_stripe("ERROR: STRIPE_SECRET_KEY not defined in config.php");
    die("Stripe configuration error: API key missing. Please check config.php");
}

debug_stripe("STRIPE_SECRET_KEY found");

// Initialize variables
$booking_ids = [];
$is_partial = isset($_GET['is_partial']) && $_GET['is_partial'] == '1';
$partial_amount = isset($_GET['amount']) ? floatval($_GET['amount']) : 0;
$original_payment_id = isset($_GET['original_payment']) ? intval($_GET['original_payment']) : 0;

debug_stripe("is_partial: " . ($is_partial ? 'yes' : 'no'));
debug_stripe("partial_amount: " . $partial_amount);

// Get booking IDs from request
if (isset($_GET['booking_ids'])) {
    $booking_ids = array_map('intval', explode(',', $_GET['booking_ids']));
} elseif (isset($_GET['booking_id'])) {
    $booking_ids = [intval($_GET['booking_id'])];
}

debug_stripe("booking_ids from GET: " . implode(',', $booking_ids));

if (empty($booking_ids)) {
    debug_stripe("ERROR: No booking IDs found in GET");
    header("Location: my_payments.php?error=no_booking_selected");
    exit();
}

// FIRST: Try to get booking info with more relaxed conditions
$placeholders = implode(',', array_fill(0, count($booking_ids), '?'));
$types = str_repeat('i', count($booking_ids));

// Remove the status restriction temporarily to debug
$stmt = $conn->prepare("
    SELECT b.id, b.language, b.booking_date, b.tutor_id, b.status,
           u.fullname AS tutor_name, tp.rate
    FROM bookings b
    JOIN users u ON b.tutor_id = u.id
    JOIN tutor_profiles tp ON b.tutor_id = tp.user_id
    WHERE b.id IN ($placeholders) AND b.student_id = ?
");
$all_params = array_merge($booking_ids, [$userID]);
$stmt->bind_param($types . 'i', ...$all_params);
$stmt->execute();
$result = $stmt->get_result();
$bookings = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

debug_stripe("Found " . count($bookings) . " bookings (without status filter)");

if (empty($bookings)) {
    debug_stripe("ERROR: No bookings found for IDs: " . implode(',', $booking_ids));
    echo "<h2>Debug Information</h2>";
    echo "<p>No bookings found. Please check:</p>";
    echo "<ul>";
    echo "<li>Booking ID: " . implode(',', $booking_ids) . "</li>";
    echo "<li>Student ID: " . $userID . "</li>";
    echo "</ul>";
    echo '<p><a href="my_payments.php">Go back</a></p>';
    exit();
}

// Show booking status for debugging
foreach ($bookings as $booking) {
    debug_stripe("Booking ID: {$booking['id']}, Status: {$booking['status']}, Rate: {$booking['rate']}");
}

// Check if any booking is already paid (but allow partial payments)
$already_paid = false;
foreach ($bookings as $booking) {
    $chk = $conn->prepare("SELECT id, status FROM payments WHERE booking_id=? AND student_id=? AND status='verified'");
    $chk->bind_param("ii", $booking['id'], $userID);
    $chk->execute();
    $payment_result = $chk->get_result();
    if ($payment_result->fetch_assoc()) {
        debug_stripe("Booking {$booking['id']} already has a verified payment");
        // For partial payment, we allow it if there's a remaining amount
        if (!$is_partial) {
            $already_paid = true;
        }
    }
    $chk->close();
}

if ($already_paid && !$is_partial) {
    debug_stripe("ERROR: Booking already paid");
    header("Location: my_payments.php?error=already_paid");
    exit();
}

// Calculate total amount
if ($is_partial && $partial_amount > 0) {
    $total_amount = $partial_amount;
} else {
    $total_amount = array_sum(array_column($bookings, 'rate'));
}

$amount_cents = (int) round($total_amount * 100);
$is_multi = count($bookings) > 1;
$first = $bookings[0];

debug_stripe("total_amount: " . $total_amount);
debug_stripe("amount_cents: " . $amount_cents);

// Build description
if ($is_partial) {
    $description = "Partial payment (remaining balance) for Booking #{$booking_ids[0]} - RM " . number_format($partial_amount, 2);
} else {
    $description = $is_multi 
        ? count($bookings) . ' sessions · RM ' . number_format($total_amount, 2)
        : 'Booking #' . $first['id'] . ' · ' . date('d M Y', strtotime($first['booking_date']));
}

// URLs - make sure they match your folder name (kyoshi or Kyoshi)
$success_url = 'http://kyoshitutor.site/kyoshi/php/stripe_success.php?session_id={CHECKOUT_SESSION_ID}&booking_ids=' . implode(',', $booking_ids) . '&is_partial=' . ($is_partial ? '1' : '0') . '&original_payment=' . $original_payment_id;
$cancel_url = 'http://kyoshitutor.site/php/my_payments.php?cancelled=1';

debug_stripe("success_url: " . $success_url);

try {
    // Set Stripe API key
    \Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);
    
    // Create Stripe checkout session
    $session = \Stripe\Checkout\Session::create([
        'payment_method_types' => ['card'],
        'line_items' => [[
            'price_data' => [
                'currency'     => 'myr',
                'unit_amount'  => $amount_cents,
                'product_data' => [
                    'name'        => $is_partial ? 'Partial Payment - ' . $first['language'] . ' Lesson' : ($is_multi ? 'Multiple Language Sessions' : $first['language'] . ' Lesson'),
                    'description' => $description,
                ],
            ],
            'quantity' => 1,
        ]],
        'mode'        => 'payment',
        'success_url' => $success_url,
        'cancel_url'  => $cancel_url,
        'metadata'    => [
            'student_id'  => $userID,
            'booking_ids' => implode(',', $booking_ids),
            'is_partial'  => $is_partial ? '1' : '0',
            'original_payment_id' => $original_payment_id,
            'partial_amount' => $partial_amount
        ],
    ]);
    
    debug_stripe("SUCCESS! Redirecting to Stripe");
    
    // Redirect to Stripe checkout
    header("Location: " . $session->url);
    exit();

} catch (Exception $e) {
    $error_msg = $e->getMessage();
    debug_stripe("ERROR: " . $error_msg);
    
    echo "<h2>Stripe Error</h2>";
    echo "<p><strong>Error:</strong> " . htmlspecialchars($error_msg) . "</p>";
    echo "<h3>Debug Info:</h3>";
    echo "<ul>";
    echo "<li>Booking IDs: " . implode(',', $booking_ids) . "</li>";
    echo "<li>Is Partial: " . ($is_partial ? 'Yes' : 'No') . "</li>";
    echo "<li>Amount: RM " . $total_amount . "</li>";
    echo "<li>Amount in cents: " . $amount_cents . "</li>";
    echo "</ul>";
    echo '<p><a href="my_payments.php">Go back to My Payments</a></p>';
    exit();
}
?>