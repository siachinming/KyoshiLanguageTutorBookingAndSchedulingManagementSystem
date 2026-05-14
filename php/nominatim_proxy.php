<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$query = trim($_GET['q'] ?? '');
if (!$query) {
    echo json_encode([]);
    exit();
}

$url = 'https://nominatim.openstreetmap.org/search?format=json&limit=5&countrycodes=my&q=' . urlencode($query);

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_USERAGENT, 'KyoshiTutoringApp/1.0 (student booking)');
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
$response = curl_exec($ch);
curl_close($ch);

echo $response ?: json_encode([]);