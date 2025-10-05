<?php
require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json');

$q = trim($_GET['q'] ?? '');
if ($q === '') {
    echo json_encode(['error' => 'Missing query']);
    exit;
}

function http_get_json(string $url, array $headers = []): array {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    if (!empty($headers)) curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_USERAGENT, 'RicePOS-Geocoder/1.0 (+contact admin)');
    $res = curl_exec($ch);
    $err = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($err || $code >= 400 || $res === false) {
        return ['error' => 'Geocoding request failed'];
    }
    $data = json_decode($res, true);
    if ($data === null) return ['error' => 'Invalid geocoder response'];
    return $data;
}

if (GEOCODER === 'mapbox' && MAPBOX_ACCESS_TOKEN) {
    $url = 'https://api.mapbox.com/geocoding/v5/mapbox.places/' . rawurlencode($q) . '.json?limit=5&access_token=' . urlencode(MAPBOX_ACCESS_TOKEN);
    // Prefer results around NCR/Bulacan by adding proximity to store center if available
    if (defined('STORE_ORIGIN_LAT') && defined('STORE_ORIGIN_LNG')) {
        $url .= '&proximity=' . rawurlencode(STORE_ORIGIN_LNG . ',' . STORE_ORIGIN_LAT);
    }
    if (defined('GEOCODER_COUNTRY') && GEOCODER_COUNTRY) {
        $url .= '&country=' . rawurlencode(GEOCODER_COUNTRY);
    }
    if (defined('GEOCODER_VIEWBOX') && GEOCODER_VIEWBOX) {
        // Mapbox bbox: minLon,minLat,maxLon,maxLat
        // Our VIEWBOX is left_lon,top_lat,right_lon,bottom_lat → convert to minLon,minLat,maxLon,maxLat
        $parts = explode(',', GEOCODER_VIEWBOX);
        if (count($parts) === 4) {
            $left = (float)$parts[0];
            $top = (float)$parts[1];
            $right = (float)$parts[2];
            $bottom = (float)$parts[3];
            $minLon = min($left, $right);
            $maxLon = max($left, $right);
            $minLat = min($bottom, $top);
            $maxLat = max($bottom, $top);
            $url .= '&bbox=' . rawurlencode($minLon . ',' . $minLat . ',' . $maxLon . ',' . $maxLat);
        }
    }
    $data = http_get_json($url);
    if (isset($data['features'])) {
        $results = array_map(function($f) {
            return [
                'display_name' => $f['place_name'] ?? '',
                'lat' => isset($f['center'][1]) ? $f['center'][1] : null,
                'lon' => isset($f['center'][0]) ? $f['center'][0] : null,
            ];
        }, $data['features']);
        echo json_encode(['results' => $results]);
        exit;
    }
    echo json_encode(['results' => []]);
    exit;
}

// Default: Nominatim with scoping to NCR/Bulacan/Caloocan area
$params = [
    'format' => 'json',
    'limit' => 8,
    'q' => $q,
];
if (defined('GEOCODER_COUNTRY') && GEOCODER_COUNTRY) {
    $params['countrycodes'] = GEOCODER_COUNTRY;
}
if (defined('GEOCODER_VIEWBOX') && GEOCODER_VIEWBOX) {
    $params['viewbox'] = GEOCODER_VIEWBOX;
    if (defined('GEOCODER_BOUNDED') && GEOCODER_BOUNDED) {
        $params['bounded'] = 1; // restrict to viewbox
    }
}
// Prefer shop origin with a small circular bias if available
if (defined('STORE_ORIGIN_LAT') && defined('STORE_ORIGIN_LNG')) {
    $params['lat'] = STORE_ORIGIN_LAT;
    $params['lon'] = STORE_ORIGIN_LNG;
}
$url = 'https://nominatim.openstreetmap.org/search?' . http_build_query($params);
function to_results($data, $fallbackLabel) {
    $results = [];
    foreach ($data as $r) {
        if (!isset($r['lat']) || !isset($r['lon'])) continue;
        $results[] = [
            'display_name' => $r['display_name'] ?? $fallbackLabel,
            'lat' => (float)$r['lat'],
            'lon' => (float)$r['lon'],
        ];
    }
    return $results;
}

$data = http_get_json($url, ['Accept: application/json']);
if (is_array($data) && count($data) > 0) {
    echo json_encode(['results' => to_results($data, $q)]);
    exit;
}

// Fallback #1: if bounded, retry without bounding to widen search
if (!empty($params['bounded'])) {
    unset($params['bounded']);
    $url2 = 'https://nominatim.openstreetmap.org/search?' . http_build_query($params);
    $data2 = http_get_json($url2, ['Accept: application/json']);
    if (is_array($data2) && count($data2) > 0) {
        echo json_encode(['results' => to_results($data2, $q)]);
        exit;
    }
}

// Fallback #2: normalize common abbreviations and ensure city context
$norm = $q;
$norm = preg_replace('/\bSt\.?\b/i', 'Street', $norm);
$norm = preg_replace('/\bBrgy\.?\b/i', 'Barangay', $norm);
if (stripos($norm, 'Manila') === false) { $norm .= ', Manila'; }
$params['q'] = $norm;
$url3 = 'https://nominatim.openstreetmap.org/search?' . http_build_query($params);
$data3 = http_get_json($url3, ['Accept: application/json']);
if (is_array($data3) && count($data3) > 0) {
    echo json_encode(['results' => to_results($data3, $norm)]);
    exit;
}

echo json_encode(['results' => []]);
?>


