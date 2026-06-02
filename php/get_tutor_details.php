<?php
include 'config.php';

$tutor_id = intval($_GET['tutor_id'] ?? 0);

if ($tutor_id <= 0) {
    echo json_encode([]);
    exit();
}

$result = $conn->query("
    SELECT u.id, u.fullname, u.email, u.phone, u.profile_pic,
           tp.experience, tp.rate, tp.bio,
           GROUP_CONCAT(DISTINCT CONCAT(tl.language, ' (', tl.proficiency_level, ')') SEPARATOR ', ') as languages
    FROM users u
    LEFT JOIN tutor_profiles tp ON u.id = tp.user_id
    LEFT JOIN tutor_languages tl ON u.id = tl.user_id
    WHERE u.id = $tutor_id AND u.role = 'tutor'
    GROUP BY u.id
");

$tutor = $result->fetch_assoc();

header('Content-Type: application/json');
echo json_encode($tutor);
?>