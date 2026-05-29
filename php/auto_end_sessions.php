<?php
// auto_end_sessions.php - Run this file via cron job or manually
// This script automatically ends meeting sessions that are past their class time

session_start();
include 'config.php';

// Enable error logging
error_log("Auto-end sessions script started at " . date('Y-m-d H:i:s'));

// Update all stuck meeting logs that are past their class end time
$autoEndStmt = $conn->prepare("
    UPDATE meeting_logs ml
    JOIN bookings b ON ml.booking_id = b.id
    SET 
        ml.leave_time = DATE_ADD(CONCAT(b.booking_date, ' ', b.booking_time), INTERVAL 2 HOUR),
        ml.duration_minutes = TIMESTAMPDIFF(MINUTE, ml.join_time, DATE_ADD(CONCAT(b.booking_date, ' ', b.booking_time), INTERVAL 2 HOUR))
    WHERE 
        ml.leave_time IS NULL
        AND CONCAT(b.booking_date, ' ', b.booking_time) < DATE_SUB(NOW(), INTERVAL 2 HOUR)
");

$autoEndStmt->execute();
$affected_rows = $autoEndStmt->affected_rows;

if ($affected_rows > 0) {
    error_log("Auto-ended $affected_rows stuck sessions at " . date('Y-m-d H:i:s'));
    echo "✅ Auto-ended $affected_rows stuck sessions\n";
} else {
    echo "ℹ️ No stuck sessions found at " . date('Y-m-d H:i:s') . "\n";
}

// Also fix any sessions with leave_time = '0000-00-00 00:00:00'
$fixNullStmt = $conn->prepare("
    UPDATE meeting_logs ml
    JOIN bookings b ON ml.booking_id = b.id
    SET 
        ml.leave_time = DATE_ADD(CONCAT(b.booking_date, ' ', b.booking_time), INTERVAL 2 HOUR),
        ml.duration_minutes = TIMESTAMPDIFF(MINUTE, ml.join_time, DATE_ADD(CONCAT(b.booking_date, ' ', b.booking_time), INTERVAL 2 HOUR))
    WHERE 
        ml.leave_time = '0000-00-00 00:00:00'
        AND CONCAT(b.booking_date, ' ', b.booking_time) < NOW()
");

$fixNullStmt->execute();
$null_fixed = $fixNullStmt->affected_rows;

if ($null_fixed > 0) {
    echo "✅ Fixed $null_fixed records with zero leave_time\n";
}

?>