<?php session_start();

include 'config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/Kyoshi/vendor/autoload.php';

if (!isset($_SESSION['user_id'])) { 
    header("Location: login.php"); 
    exit(); 
}

$userID    = $_SESSION['user_id'];
$bookingID = intval($_GET['booking_id'] ?? 0);

if (!$bookingID) {
    die("Missing booking ID");
}

$stmt = $conn->prepare("
    SELECT b.*, u.fullname AS tutor_name, tp.rate
    FROM bookings b
    JOIN users u ON b.tutor_id = u.id
    LEFT JOIN tutor_profiles tp ON b.tutor_id = tp.user_id
    WHERE b.id = ? AND b.student_id = ?
");

$stmt->bind_param("ii", $bookingID, $userID);
$stmt->execute();

$result = $stmt->get_result();

if ($result->num_rows === 0) {
    die("❌ No booking found. Check database.");
}

$booking = $result->fetch_assoc();
$stmt->close();


// ✅ NOW booking exists → safe to use
$rate = $booking['rate'] ?? 0;

if ($rate <= 0) {
    die("Invalid rate from database");
}

$amount = (int) round($rate * 100);


\Stripe\Stripe::setApiKey('sk_test_51TVVHPAjFaJboEtiYlcEc1imL3qWgIBzGa87CvWHFlyuZrhOEA8kDxnS1J7LItiLJJzHKLsgGyg5DNI8oVaJ6KmD00UN9FQYC9');


$session = \Stripe\Checkout\Session::create([
    'payment_method_types' => ['card', 'fpx', 'grabpay'],
    'line_items' => [[
        'price_data' => [
            'currency'     => 'myr',
            'unit_amount'  => $amount,
            'product_data' => [
                'name'        => $booking['language'] . ' Lesson with ' . $booking['tutor_name'],
                'description' => 'Booking #' . $bookingID . ' · ' . date('d M Y', strtotime($booking['booking_date'])),
            ],
        ],
        'quantity' => 1,
    ]],
    'mode'        => 'payment',
    'success_url' => 'http://localhost/Kyoshi/php/payment_success.php?booking_id=' . $bookingID . '&session_id={CHECKOUT_SESSION_ID}',
    'cancel_url'  => 'http://localhost/Kyoshi/php/payment_form.php?booking_id=' . $bookingID,
    'metadata'    => ['booking_id' => $bookingID, 'student_id' => $userID],
]);

header("Location: " . $session->url);
exit(); ?>