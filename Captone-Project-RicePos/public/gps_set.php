<?php
// Simple endpoint to receive live GPS updates from Edgar's phone
// Security: lightweight token check. Change $TOKEN to something private.

header('Content-Type: application/json');

$TOKEN = 'edgar-123'; // TODO: change this to a strong secret and keep private

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$token = $_POST['token'] ?? '';
if ($token !== $TOKEN) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$lat = isset($_POST['lat']) ? (float)$_POST['lat'] : null;
$lng = isset($_POST['lng']) ? (float)$_POST['lng'] : null;
$accuracy = isset($_POST['accuracy']) ? (float)$_POST['accuracy'] : null;
$sourceTs = isset($_POST['ts']) ? (int)$_POST['ts'] : null; // optional ms timestamp from device

if ($lat === null || $lng === null) {
    http_response_code(400);
    echo json_encode(['error' => 'lat and lng required']);
    exit;
}

$payload = [
    'driver' => 'Edgar',
    'vehicle' => 'Toyota Hilux',
    'lat' => $lat,
    'lng' => $lng,
    'accuracy_m' => $accuracy,
    'updated_at' => time(),
    'device_ts' => $sourceTs,
    'source' => 'beacon',
];

$dir = __DIR__ . '/assets/gps';
if (!is_dir($dir)) {
    @mkdir($dir, 0775, true);
}
$file = $dir . '/edgar.json';

$ok = @file_put_contents($file, json_encode($payload, JSON_PRETTY_PRINT));
if ($ok === false) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to persist location']);
    exit;
}

echo json_encode(['ok' => true, 'saved' => $payload]);
?>


