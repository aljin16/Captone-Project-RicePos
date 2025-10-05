<?php
require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json');

$from = trim($_GET['from'] ?? ''); // "lat,lng"
$to = trim($_GET['to'] ?? '');     // "lat,lng"

if (!$from || !$to) { echo json_encode(['error' => 'Missing coordinates']); exit; }

[$fromLat, $fromLng] = array_map('floatval', explode(',', $from));
[$toLat, $toLng] = array_map('floatval', explode(',', $to));

// OSRM expects lng,lat order
$coords = $fromLng . ',' . $fromLat . ';' . $toLng . ',' . $toLat;
$url = rtrim(OSRM_BASE_URL, '/') . '/route/v1/driving/' . $coords . '?overview=full&geometries=geojson';

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_USERAGENT, 'RicePOS-Router/1.0');
$res = curl_exec($ch);
$err = curl_error($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($err || $code >= 400 || $res === false) {
    echo json_encode(['error' => 'Routing request failed']);
    exit;
}

$data = json_decode($res, true);
if (!$data || empty($data['routes'][0])) {
    echo json_encode(['error' => 'No route found']);
    exit;
}

$route = $data['routes'][0];
echo json_encode([
    'distance_m' => $route['distance'] ?? null,
    'duration_s' => $route['duration'] ?? null,
    'geometry' => $route['geometry'] ?? null,
    'waypoints' => $data['waypoints'] ?? null,
]);
?>


