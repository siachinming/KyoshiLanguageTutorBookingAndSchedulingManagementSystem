<?php
error_reporting(0);
ini_set('display_errors', 0);

session_start();

// Include config - use absolute path to avoid issues
$config_path = __DIR__ . '/config.php';
if (!file_exists($config_path)) {
    echo json_encode(['success' => false, 'message' => 'Config file not found']);
    exit();
}
include $config_path;

header('Content-Type: application/json');

// Check login
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'tutor') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

// Get input
$input = file_get_contents('php://input');
$data = json_decode($input, true);

$submission_id = isset($data['submission_id']) ? intval($data['submission_id']) : 0;
$grade = isset($data['grade']) ? $data['grade'] : '';
$feedback = isset($data['feedback']) ? $data['feedback'] : '';

if (!$submission_id) {
    echo json_encode(['success' => false, 'message' => 'Invalid submission ID']);
    exit();
}

// First, check if the submission exists
$check = $conn->prepare("SELECT id FROM assignment_submissions WHERE id = ?");
$check->bind_param("i", $submission_id);
$check->execute();
$result = $check->get_result();

if ($result->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'Submission not found in database']);
    exit();
}

// Check if grade column exists, if not add it
$check_column = $conn->query("SHOW COLUMNS FROM assignment_submissions LIKE 'grade'");
if ($check_column->num_rows === 0) {
    $conn->query("ALTER TABLE assignment_submissions ADD COLUMN grade VARCHAR(50) NULL");
    $conn->query("ALTER TABLE assignment_submissions ADD COLUMN feedback TEXT NULL");
    $conn->query("ALTER TABLE assignment_submissions ADD COLUMN graded_at DATETIME NULL");
}

// Update the grade
$stmt = $conn->prepare("UPDATE assignment_submissions SET grade = ?, feedback = ?, graded_at = NOW() WHERE id = ?");
$stmt->bind_param("ssi", $grade, $feedback, $submission_id);

if ($stmt->execute()) {
    echo json_encode(['success' => true, 'message' => 'Grade saved successfully!']);
} else {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $stmt->error]);
}

$stmt->close();
$conn->close();
?>