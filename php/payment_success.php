<?php
session_start();
include 'config.php';
require_once '../vendor/autoload.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit(); }
$userID    = $_SESSION['user_id'];
$bookingID = intval($_GET['booking_id'] ?? 0);
$sessionId = $_GET['session_id'] ?? '';

\Stripe\Stripe::setApiKey('sk_test_51TVVHPAjFaJboEtiYlcEc1imL3qWgIBzGa87CvWHFlyuZrhOEA8kDxnS1J7LItiLJJzHKLsgGyg5DNI8oVaJ6KmD00UN9FQYC9');

try {
    $session = \Stripe\Checkout\Session::retrieve($sessionId);
    if ($session->payment_status === 'paid') {

        $amount = $session->amount_total / 100;

        // 1. Prevent duplicate payment insert
        $check = $conn->prepare("SELECT id FROM payments WHERE booking_id = ? AND payment_method = 'stripe' LIMIT 1");
        $check->bind_param("i", $bookingID);
        $check->execute();
        $existing = $check->get_result()->fetch_assoc();
        $check->close();

        if (!$existing) {

            $stmt = $conn->prepare("
                INSERT INTO payments (booking_id, student_id, amount, payment_method, status, created_at)
                VALUES (?, ?, ?, 'stripe', 'verified', NOW())
            ");
            $stmt->bind_param("iid", $bookingID, $userID, $amount);
            $stmt->execute();
            $stmt->close();
        }

        $stmt = $conn->prepare("
            UPDATE bookings 
            SET status = 'confirmed' 
            WHERE id = ? AND student_id = ?
        ");
        $stmt->bind_param("ii", $bookingID, $userID);
        $stmt->execute();
        $stmt->close();

        header("Location: booking_detail.php?id=$bookingID&paid=1");
        exit();
    }

} catch (Exception $e) {
    error_log("Stripe error: " . $e->getMessage());
}

header("Location: payment_form.php?booking_id=$bookingID&error=payment_failed");
exit();
?>