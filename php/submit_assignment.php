<?php
session_start();
header('Content-Type: application/json');
include 'config.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Please login first']);
    exit();
}

$userID = $_SESSION['user_id'];
$assignment_id = $_POST['assignment_id'] ?? 0;
$submission_text = trim($_POST['submission_text'] ?? '');
$submission_text = !empty($submission_text) ? $submission_text : null;

// Verify assignment exists and belongs to student's booking
$checkStmt = $conn->prepare("
    SELECT a.*, b.student_id 
    FROM assignments a 
    JOIN bookings b ON a.booking_id = b.id 
    WHERE a.id = ? AND b.student_id = ?
");
$checkStmt->bind_param("ii", $assignment_id, $userID);
$checkStmt->execute();
$assignment = $checkStmt->get_result()->fetch_assoc();

if (!$assignment) {
    echo json_encode(['success' => false, 'error' => 'Assignment not found or not authorized']);
    exit();
}

// Check if already submitted
$checkSubStmt = $conn->prepare("SELECT id FROM assignment_submissions WHERE assignment_id = ? AND student_id = ?");
$checkSubStmt->bind_param("ii", $assignment_id, $userID);
$checkSubStmt->execute();
if ($checkSubStmt->get_result()->num_rows > 0) {
    echo json_encode(['success' => false, 'error' => 'You have already submitted this assignment']);
    exit();
}

// Handle file upload
$file_name = null;
$file_path = null;
$file_size = null;
$file_type = null;

if (isset($_FILES['submission_file']) && $_FILES['submission_file']['error'] === UPLOAD_ERR_OK) {
    $uploadDir = '../uploads/assignments/submission/';
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }
    
    $file = $_FILES['submission_file'];
    $fileExt = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowedExts = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png', 'txt', 'zip', 'mp3', 'mp4', 'wav', 'ogg', 'mov', 'avi', 'mkv'];
    
    if (!in_array($fileExt, $allowedExts)) {
        echo json_encode(['success' => false, 'error' => 'File type not allowed']);
        exit();
    }
    
    if ($file['size'] > 50 * 1024 * 1024) {
        echo json_encode(['success' => false, 'error' => 'File too large (max 50MB)']);
        exit();
    }
    
    $newFileName = time() . '_' . bin2hex(random_bytes(8)) . '.' . $fileExt;
    $newFilePath = $uploadDir . $newFileName;
    
    if (move_uploaded_file($file['tmp_name'], $newFilePath)) {
        $file_name = $file['name'];
        $file_path = $newFileName;  // Save only the filename
        $file_size = $file['size'];
        $file_type = $file['type'];
    } else {
        echo json_encode(['success' => false, 'error' => 'Failed to upload file']);
        exit();
    }
}

// Insert submission
$insertStmt = $conn->prepare("
    INSERT INTO assignment_submissions 
    (assignment_id, student_id, tutor_id, booking_id, submission_text, file_name, file_path, file_size, file_type, status, submitted_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'submitted', NOW())
");

$tutor_id = $assignment['tutor_id'];
$booking_id = $assignment['booking_id'];

$insertStmt->bind_param("iiiisssss", 
    $assignment_id, 
    $userID, 
    $tutor_id,
    $booking_id,
    $submission_text,
    $file_name,
    $file_path,
    $file_size,
    $file_type
);

if ($insertStmt->execute()) {
    // Send notification to tutor
    $notifStmt = $conn->prepare("
        INSERT INTO notifications (user_id, type, title, message, link, created_at)
        VALUES (?, 'submission', 'New Assignment Submission', 
        'A student has submitted an assignment: " . addslashes($assignment['title']) . "', 
        'assignment_overview.php', NOW())
    ");
    $notifStmt->bind_param("i", $tutor_id);
    $notifStmt->execute();
    
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $conn->error]);
}
?>