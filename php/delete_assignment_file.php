<?php
session_start();
include 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'tutor') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$data = json_decode(file_get_contents('php://input'), true);
$assignmentId = $data['assignment_id'] ?? 0;
$fileIndex = $data['file_index'] ?? -1;

if (!$assignmentId || $fileIndex < 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit();
}

// Get current assignment
$stmt = $conn->prepare("SELECT file_name, file_path, file_size, file_type FROM assignments WHERE id = ? AND tutor_id = ?");
$stmt->bind_param("ii", $assignmentId, $_SESSION['user_id']);
$stmt->execute();
$assignment = $stmt->get_result()->fetch_assoc();

if (!$assignment) {
    echo json_encode(['success' => false, 'message' => 'Assignment not found']);
    exit();
}

// Split into arrays
$fileNames = explode('|', $assignment['file_name']);
$filePaths = explode('|', $assignment['file_path']);
$fileSizes = explode('|', $assignment['file_size']);
$fileTypes = explode('|', $assignment['file_type']);

// Remove the selected file
if (isset($fileNames[$fileIndex])) {
    // Delete physical file
    $fileToDelete = '../uploads/assignments/' . $filePaths[$fileIndex];
    if (file_exists($fileToDelete)) {
        unlink($fileToDelete);
    }
    
    // Remove from arrays
    array_splice($fileNames, $fileIndex, 1);
    array_splice($filePaths, $fileIndex, 1);
    array_splice($fileSizes, $fileIndex, 1);
    array_splice($fileTypes, $fileIndex, 1);
    
    // Re-join
    $newFileNames = implode('|', $fileNames);
    $newFilePaths = implode('|', $filePaths);
    $newFileSizes = array_sum($fileSizes); // Total size
    $newFileTypes = implode('|', $fileTypes);
    
    // Update database
    $update = $conn->prepare("UPDATE assignments SET file_name = ?, file_path = ?, file_size = ?, file_type = ? WHERE id = ?");
    $update->bind_param("ssisi", $newFileNames, $newFilePaths, $newFileSizes, $newFileTypes, $assignmentId);
    
    if ($update->execute()) {
        echo json_encode(['success' => true, 'message' => 'File removed']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'File not found']);
}
?>