<?php
// report_issue.php
session_start();
include 'config.php';
include 'insert_notification.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$student_id = $_SESSION['user_id'];
$booking_id = intval($_GET['booking_id'] ?? 0);
$issue_type = $_POST['issue_type'] ?? '';
$message = $_POST['message'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Create dispute record
    $stmt = $conn->prepare("
        INSERT INTO disputes (booking_id, student_id, issue_type, message, status, created_at)
        VALUES (?, ?, ?, ?, 'pending', NOW())
    ");
    $stmt->bind_param("iiss", $booking_id, $student_id, $issue_type, $message);
    $stmt->execute();
    
    // Notify admin
    insertNotification($conn, 1, "⚠️ New Dispute Reported", 
        "Student reported issue for booking #$booking_id: $issue_type",
        "dispute", "admin_disputes.php");
    
    header("Location: booking_detail.php?id=$booking_id&reported=1");
    exit();
}
?>

<!-- Report Issue Form HTML -->
<form method="POST" action="">
    <input type="hidden" name="booking_id" value="<?= $booking_id ?>">
    <h3>Report Issue with Session</h3>
    <select name="issue_type" required>
        <option value="">Select issue type</option>
        <option value="tutor_no_show">Tutor didn't attend</option>
        <option value="technical_issues">Technical issues</option>
        <option value="wrong_materials">Wrong materials provided</option>
        <option value="other">Other</option>
    </select>
    <textarea name="message" placeholder="Describe your issue..." required></textarea>
    <button type="submit">Submit Report</button>
</form>