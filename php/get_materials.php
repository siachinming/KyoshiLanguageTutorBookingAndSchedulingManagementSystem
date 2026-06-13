<?php
session_start();
include 'config.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$userID = $_SESSION['user_id'];
$action = $_GET['action'] ?? '';

if ($action === 'get_materials') {
    $language = $_GET['language'] ?? '';
    
    if (empty($language)) {
        // Get all materials grouped by language from student's confirmed bookings
        $query = "
            SELECT DISTINCT 
                lm.language,
                lm.id,
                lm.title,
                lm.description,
                lm.file_path,
                lm.file_type,
                lm.duration,
                sm.accessed_at,
                sm.downloaded_at,
                sm.is_completed,
                b.id as booking_id,
                b.booking_date,
                b.status
            FROM bookings b
            JOIN learning_materials lm ON lm.language = b.language
            LEFT JOIN student_materials sm ON sm.material_id = lm.id 
                AND sm.student_id = b.student_id 
                AND sm.booking_id = b.id
            WHERE b.student_id = ? 
                AND b.status IN ('confirmed', 'completed')
            ORDER BY lm.language, b.booking_date DESC
        ";
        
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $userID);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $materialsByLanguage = [];
        while ($row = $result->fetch_assoc()) {
            $lang = $row['language'];
            if (!isset($materialsByLanguage[$lang])) {
                $materialsByLanguage[$lang] = [];
            }
            $materialsByLanguage[$lang][] = $row;
        }
        
        echo json_encode(['success' => true, 'data' => $materialsByLanguage]);
    } else {
        // Get materials for specific language
        $query = "
            SELECT DISTINCT 
                lm.*,
                sm.accessed_at,
                sm.downloaded_at,
                sm.is_completed
            FROM learning_materials lm
            LEFT JOIN student_materials sm ON sm.material_id = lm.id AND sm.student_id = ?
            WHERE lm.language = ?
            ORDER BY lm.created_at DESC
        ";
        
        $stmt = $conn->prepare($query);
        $stmt->bind_param("is", $userID, $language);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $materials = [];
        while ($row = $result->fetch_assoc()) {
            $materials[] = $row;
        }
        
        echo json_encode(['success' => true, 'data' => $materials]);
    }
} 
elseif ($action === 'track_access') {
    $material_id = $_POST['material_id'] ?? 0;
    $booking_id = $_POST['booking_id'] ?? 0;
    $type = $_POST['type'] ?? 'view'; // 'view' or 'download'
    
    $column = $type === 'view' ? 'accessed_at' : 'downloaded_at';
    $query = "
        INSERT INTO student_materials (student_id, material_id, booking_id, $column)
        VALUES (?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE $column = NOW()
    ";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("iii", $userID, $material_id, $booking_id);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => $conn->error]);
    }
}
elseif ($action === 'get_classroom') {
    $booking_id = $_GET['booking_id'] ?? 0;
    
    // Check if classroom session exists
    $query = "SELECT * FROM classroom_sessions WHERE booking_id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $booking_id);
    $stmt->execute();
    $session = $stmt->get_result()->fetch_assoc();
    
    if (!$session) {
        // Create new classroom session
        $session_code = strtoupper(substr(md5(uniqid()), 0, 8));
        $meeting_link = "classroom.php?code=" . $session_code;
        
        $insert = "INSERT INTO classroom_sessions (booking_id, session_code, meeting_link) VALUES (?, ?, ?)";
        $stmt = $conn->prepare($insert);
        $stmt->bind_param("iss", $booking_id, $session_code, $meeting_link);
        $stmt->execute();
        
        $session = [
            'session_code' => $session_code,
            'meeting_link' => $meeting_link
        ];
    }
    
    echo json_encode(['success' => true, 'data' => $session]);
}
?>