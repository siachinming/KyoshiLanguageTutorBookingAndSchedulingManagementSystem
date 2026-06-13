<?php
// admin_payment_reminder.php - Send URGENT email reminders to admin about pending payments
// Runs daily via cron-job.org

// Prevent direct access from browsers (only allow cron-job.org)
$allowedUserAgents = ['cron-job.org', 'Kyoshi-Cron'];
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
$isCron = false;
foreach ($allowedUserAgents as $allowed) {
    if (stripos($userAgent, $allowed) !== false) {
        $isCron = true;
        break;
    }
}

// Allow command line execution
if (php_sapi_name() === 'cli') {
    $isCron = true;
}

// If not cron, show simple response (prevent abuse)
if (!$isCron) {
    header('HTTP/1.0 403 Forbidden');
    echo "Access denied. This script is for automated tasks only.";
    exit();
}

require_once '../config.php';
require_once '../send_mail.php';

// Set execution time limit
set_time_limit(60);
ini_set('max_execution_time', 60);

// Log file for debugging
$logFile = '../logs/admin_payment_reminder.log';
$logDir = dirname($logFile);
if (!is_dir($logDir)) {
    mkdir($logDir, 0777, true);
}

function logMessage($message) {
    global $logFile;
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[$timestamp] $message\n";
    file_put_contents($logFile, $logEntry, FILE_APPEND);
}

logMessage("=== Admin Payment Reminder Started ===");

$today = date('Y-m-d');
$tomorrow = date('Y-m-d', strtotime('+1 day'));
$dayAfter = date('Y-m-d', strtotime('+2 days'));

// Get pending payments with URGENCY levels based on booking date
$stmt = $conn->prepare("
    SELECT 
        p.id,
        p.amount,
        p.payment_method,
        p.created_at,
        b.id as booking_id,
        b.booking_date,
        b.booking_time,
        b.language,
        b.learning_mode,
        s.fullname as student_name,
        s.email as student_email,
        s.phone as student_phone,
        t.fullname as tutor_name,
        t.email as tutor_email,
        CASE 
            WHEN b.booking_date = ? THEN 'URGENT_TODAY'
            WHEN b.booking_date = ? THEN 'URGENT_TOMORROW'
            WHEN b.booking_date <= ? THEN 'CRITICAL'
            ELSE 'NORMAL'
        END as urgency_level
    FROM payments p
    JOIN bookings b ON p.booking_id = b.id
    JOIN users s ON b.student_id = s.id
    JOIN users t ON b.tutor_id = t.id
    WHERE p.status = 'pending'
    ORDER BY 
        CASE 
            WHEN b.booking_date = ? THEN 1
            WHEN b.booking_date = ? THEN 2
            WHEN b.booking_date <= ? THEN 3
            ELSE 4
        END,
        b.booking_date ASC
");
$stmt->bind_param("ssssss", $today, $tomorrow, $today, $today, $tomorrow, $today);
$stmt->execute();
$pendingPayments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$pendingCount = count($pendingPayments);

// Count urgent payments
$urgentToday = 0;
$urgentTomorrow = 0;
$critical = 0;

foreach ($pendingPayments as $payment) {
    if ($payment['urgency_level'] == 'URGENT_TODAY') $urgentToday++;
    elseif ($payment['urgency_level'] == 'URGENT_TOMORROW') $urgentTomorrow++;
    elseif ($payment['urgency_level'] == 'CRITICAL') $critical++;
}

logMessage("Pending payments: $pendingCount (Urgent today: $urgentToday, Urgent tomorrow: $urgentTomorrow, Critical: $critical)");

if ($pendingCount > 0) {
    // Get admin email(s)
    $stmt = $conn->prepare("
        SELECT email, fullname 
        FROM users 
        WHERE role = 'admin' AND status = 'approved'
    ");
    $stmt->execute();
    $admins = $stmt->get_result();
    
    // Check when last reminder was sent (to avoid spamming)
    $lastReminderFile = '../logs/last_payment_reminder.txt';
    $lastSent = file_exists($lastReminderFile) ? file_get_contents($lastReminderFile) : '';
    $todayDate = date('Y-m-d');
    
    // Send reminder only once per day (unless CRITICAL payments exist)
    $forceSend = ($critical > 0 || $urgentToday > 0);
    
    if ($lastSent != $todayDate || $forceSend) {
        $sentCount = 0;
        
        // Send email to each admin using the URGENT function
        while ($admin = $admins->fetch_assoc()) {
            $sent = sendAdminPaymentReminderUrgent(
                $admin['email'], 
                $admin['fullname'], 
                $pendingCount,
                $urgentToday,
                $urgentTomorrow,
                $critical,
                $pendingPayments
            );
            
            if ($sent) {
                $sentCount++;
                logMessage("Reminder sent to admin: {$admin['email']}");
            } else {
                logMessage("Failed to send reminder to: {$admin['email']}");
            }
        }
        
        // Update last sent timestamp
        file_put_contents($lastReminderFile, $todayDate);
        logMessage("Payment reminder sent to {$sentCount} admin(s)");
    } else {
        logMessage("Reminder already sent today. Skipping...");
    }
} else {
    logMessage("No pending payments. No reminder needed.");
}

logMessage("=== Admin Payment Reminder Completed ===\n");

// Return response for cron-job.org
header('Content-Type: application/json');
echo json_encode([
    'success' => true,
    'pending_count' => $pendingCount,
    'urgent_today' => $urgentToday,
    'urgent_tomorrow' => $urgentTomorrow,
    'critical' => $critical,
    'message' => $pendingCount > 0 ? "Reminder sent for {$pendingCount} payments" : "No pending payments",
    'timestamp' => date('Y-m-d H:i:s')
]);
?>