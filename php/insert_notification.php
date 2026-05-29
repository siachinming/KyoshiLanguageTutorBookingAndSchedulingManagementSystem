<?php
function insertNotification($conn, $userId, $title, $message, $type = 'general', $link = null) {
    // Insert into database only
    $stmt = $conn->prepare("INSERT INTO notifications (user_id, title, message, type, link, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
    $stmt->bind_param("issss", $userId, $title, $message, $type, $link);
    $result = $stmt->execute();
    $stmt->close();
    
    return $result;
}
?>