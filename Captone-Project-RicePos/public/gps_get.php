<?php
header('Content-Type: application/json');

$file = __DIR__ . '/assets/gps/edgar.json';

if (!file_exists($file)) {
    echo json_encode(['error' => 'No location yet']);
    exit;
}

$raw = @file_get_contents($file);
if ($raw === false) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to read location']);
    exit;
}

$data = json_decode($raw, true);
if (!is_array($data)) {
    http_response_code(500);
    echo json_encode(['error' => 'Corrupt location data']);
    exit;
}

echo json_encode($data);
?>


