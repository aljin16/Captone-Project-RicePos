<?php
require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json');
$DEBUG = isset($_GET['debug']);

// Input: lat, lng (fallback to store origin); units/lang from config
$lat = isset($_GET['lat']) ? (float)$_GET['lat'] : (float)STORE_ORIGIN_LAT;
$lng = isset($_GET['lng']) ? (float)$_GET['lng'] : (float)STORE_ORIGIN_LNG;

function http_get_json_strict(string $url): ?array {
    static $stage = 0;
    global $LAST_HTTP_DEBUG;
    $LAST_HTTP_DEBUG = $LAST_HTTP_DEBUG ?? [];
    // Attempt 1: strict TLS/SSL
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'RicePOS-Weather/1.0');
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($code >= 200 && $code < 400 && $res !== false) {
        $d = json_decode($res, true);
        return is_array($d) ? $d : null;
    }
    $LAST_HTTP_DEBUG[] = ['attempt' => ++$stage, 'mode' => 'curl_strict', 'http_code' => $code, 'error' => $err];
    // Attempt 2: relaxed verify (dev environments sometimes lack CA bundle)
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'RicePOS-Weather/1.0');
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($code >= 200 && $code < 400 && $res !== false) {
        $d = json_decode($res, true);
        return is_array($d) ? $d : null;
    }
    $LAST_HTTP_DEBUG[] = ['attempt' => ++$stage, 'mode' => 'curl_relaxed', 'http_code' => $code, 'error' => $err];
    // Attempt 3: file_get_contents fallback
    $ctx = stream_context_create(['http' => ['header' => "User-Agent: RicePOS-Weather/1.0\r\n"], 'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
    $res = @file_get_contents($url, false, $ctx);
    if ($res !== false) {
        $d = json_decode($res, true);
        return is_array($d) ? $d : null;
    }
    $LAST_HTTP_DEBUG[] = ['attempt' => ++$stage, 'mode' => 'fopen_fallback', 'http_code' => null, 'error' => 'no response'];
    return null;
}

// Primary: OpenWeather OneCall
function fetch_openweather(float $lat, float $lng): ?array {
    if (!WEATHER_API_KEY) return null;
    $params = http_build_query([
        'lat' => $lat,
        'lon' => $lng,
        'appid' => WEATHER_API_KEY,
        'units' => WEATHER_UNITS,
        'lang' => WEATHER_LANG,
        'exclude' => 'minutely',
    ]);
    $url = 'https://api.openweathermap.org/data/3.0/onecall?' . $params;
    return http_get_json_strict($url);
}

// Secondary: WeatherAPI.com forecast
function fetch_weatherapi(float $lat, float $lng): ?array {
    if (!WEATHERAPI_API_KEY) return null;
    $q = $lat . ',' . $lng;
    $params = http_build_query([
        'key' => WEATHERAPI_API_KEY,
        'q' => $q,
        'days' => 7,
        'aqi' => 'no',
        'alerts' => 'yes'
    ]);
    $url = 'https://api.weatherapi.com/v1/forecast.json?' . $params;
    return http_get_json_strict($url);
}

function normalize_openweather(array $d): array {
    return [
        'source' => 'openweather',
        'lat' => $d['lat'] ?? null,
        'lon' => $d['lon'] ?? null,
        'current' => $d['current'] ?? null,
        'hourly' => array_slice($d['hourly'] ?? [], 0, 12),
        'daily' => array_slice($d['daily'] ?? [], 0, 7),
        'alerts' => $d['alerts'] ?? [],
    ];
}

function normalize_weatherapi(array $d): array {
    $cur = $d['current'] ?? [];
    $fc = $d['forecast']['forecastday'] ?? [];
    $hourly = [];
    foreach ($fc as $day) {
        foreach ($day['hour'] ?? [] as $h) { $hourly[] = $h; }
    }
    $hourly = array_slice($hourly, 0, 12);
    $daily = array_map(function($day){
        return [
            'dt' => strtotime($day['date'] . ' 12:00:00'),
            'temp' => [ 'min' => $day['day']['mintemp_c'] ?? null, 'max' => $day['day']['maxtemp_c'] ?? null ],
            'pop' => ($day['day']['daily_chance_of_rain'] ?? 0)/100,
            'weather' => [['description' => $day['day']['condition']['text'] ?? '', 'icon' => null]],
        ];
    }, array_slice($fc, 0, 7));
    $alerts = ($d['alerts']['alert'] ?? []);
    return [
        'source' => 'weatherapi',
        'lat' => $d['location']['lat'] ?? null,
        'lon' => $d['location']['lon'] ?? null,
        'current' => [
            'dt' => isset($cur['last_updated_epoch']) ? $cur['last_updated_epoch'] : null,
            'temp' => $cur['temp_c'] ?? null,
            'feels_like' => $cur['feelslike_c'] ?? null,
            'humidity' => $cur['humidity'] ?? null,
            'wind_speed' => isset($cur['wind_kph']) ? round($cur['wind_kph']/3.6,2) : null,
            'pop' => $daily[0]['pop'] ?? 0,
            'weather' => ['description' => $cur['condition']['text'] ?? '', 'icon' => null],
        ],
        'hourly' => array_map(function($h){
            return [
                'dt' => $h['time_epoch'] ?? null,
                'temp' => $h['temp_c'] ?? null,
                'pop' => ($h['chance_of_rain'] ?? 0)/100,
                'weather' => [['description' => $h['condition']['text'] ?? '', 'icon' => null]],
            ];
        }, $hourly),
        'daily' => $daily,
        'alerts' => $alerts,
    ];
}

function aggregate_weather(?array $ow, ?array $wa): array {
    $sources = [];
    if ($ow) $sources[] = normalize_openweather($ow);
    if ($wa) $sources[] = normalize_weatherapi($wa);
    if (empty($sources)) return ['error' => 'No weather source available'];
    // Simple aggregation: prefer OW for base data, blend POP and temp by average if both present
    $base = $sources[0];
    $other = $sources[1] ?? null;
    if ($other) {
        // Blend current
        if (isset($base['current']['temp']) && isset($other['current']['temp'])) {
            $base['current']['temp'] = round(($base['current']['temp'] + $other['current']['temp'])/2, 1);
        }
        if (isset($base['current']['feels_like']) && isset($other['current']['feels_like'])) {
            $base['current']['feels_like'] = round(($base['current']['feels_like'] + $other['current']['feels_like'])/2, 1);
        }
        if (isset($base['current']['pop']) && isset($other['current']['pop'])) {
            $base['current']['pop'] = round(($base['current']['pop'] + $other['current']['pop'])/2, 2);
        }
        // Blend hourly POP/Temp
        $len = min(count($base['hourly']), count($other['hourly']));
        for ($i=0; $i<$len; $i++) {
            if (isset($base['hourly'][$i]['temp']) && isset($other['hourly'][$i]['temp'])) {
                $base['hourly'][$i]['temp'] = round(($base['hourly'][$i]['temp'] + $other['hourly'][$i]['temp'])/2, 1);
            }
            if (isset($base['hourly'][$i]['pop']) && isset($other['hourly'][$i]['pop'])) {
                $base['hourly'][$i]['pop'] = round(($base['hourly'][$i]['pop'] + $other['hourly'][$i]['pop'])/2, 2);
            }
        }
        // Blend daily POP
        $dlen = min(count($base['daily']), count($other['daily']));
        for ($i=0; $i<$dlen; $i++) {
            if (isset($base['daily'][$i]['temp']['min']) && isset($other['daily'][$i]['temp']['min'])) {
                $base['daily'][$i]['temp']['min'] = round(($base['daily'][$i]['temp']['min'] + $other['daily'][$i]['temp']['min'])/2, 1);
            }
            if (isset($base['daily'][$i]['temp']['max']) && isset($other['daily'][$i]['temp']['max'])) {
                $base['daily'][$i]['temp']['max'] = round(($base['daily'][$i]['temp']['max'] + $other['daily'][$i]['temp']['max'])/2, 1);
            }
            if (isset($base['daily'][$i]['pop']) && isset($other['daily'][$i]['pop'])) {
                $base['daily'][$i]['pop'] = round(($base['daily'][$i]['pop'] + $other['daily'][$i]['pop'])/2, 2);
            }
        }
        // Merge alerts
        $base['alerts'] = array_merge($base['alerts'] ?? [], $other['alerts'] ?? []);
    }
    $base['sources_used'] = array_column($sources, 'source');
    return $base;
}

// Fetch and aggregate
if (WEATHER_PROVIDER === 'openmeteo') {
    // Open-Meteo free API (no key). OneCall-like via forecast endpoint
    // Docs: https://open-meteo.com/en/docs
    $params = http_build_query([
        'latitude' => $lat,
        'longitude' => $lng,
        'current' => 'temperature_2m,apparent_temperature,relative_humidity_2m,wind_speed_10m,precipitation',
        'current_weather' => true,
        'hourly' => 'time,temperature_2m,precipitation_probability,apparent_temperature,wind_speed_10m,weathercode',
        'daily' => 'time,temperature_2m_max,temperature_2m_min,precipitation_probability_max,precipitation_sum,weathercode,sunrise,sunset',
        'timezone' => 'Asia/Manila',
    ]);
    $url = 'https://api.open-meteo.com/v1/forecast?' . $params;
    $om = http_get_json_strict($url);
    if (!$om) { 
        if ($DEBUG) {
            global $LAST_HTTP_DEBUG; 
            echo json_encode(['error' => 'Open-Meteo request failed', 'url' => $url, 'debug' => $LAST_HTTP_DEBUG]); 
        } else {
            echo json_encode(['error' => 'Open-Meteo request failed']);
        }
        exit; 
    }

    // Normalize to our schema
    $cw = $om['current_weather'] ?? [];
    $current = [
        'dt' => isset($cw['time']) ? strtotime($cw['time']) : time(),
        'temp' => $cw['temperature'] ?? ($om['current']['temperature_2m'] ?? null),
        'feels_like' => $om['current']['apparent_temperature'] ?? null,
        'humidity' => $om['current']['relative_humidity_2m'] ?? null,
        'wind_speed' => isset($cw['windspeed']) ? round(($cw['windspeed'])/3.6,2) : (isset($om['current']['wind_speed_10m']) ? round(($om['current']['wind_speed_10m'])/3.6,2) : null),
        'pop' => isset($om['hourly']['precipitation_probability'][0]) ? (($om['hourly']['precipitation_probability'][0])/100) : 0,
        'weather' => ['code' => $cw['weathercode'] ?? null, 'is_day' => $cw['is_day'] ?? null],
    ];
    // Hourly next 12
    $hourly = [];
    if (!empty($om['hourly'])) {
        $times = $om['hourly']['time'] ?? [];
        for ($i=0; $i < min(12, count($times)); $i++) {
            $hourly[] = [
                'dt' => strtotime($times[$i]),
                'temp' => $om['hourly']['temperature_2m'][$i] ?? null,
                'pop' => isset($om['hourly']['precipitation_probability'][$i]) ? (($om['hourly']['precipitation_probability'][$i]) / 100) : 0,
                'weather' => [['code' => $om['hourly']['weathercode'][$i] ?? null]],
            ];
        }
    }
    // Daily next 7
    $daily = [];
    if (!empty($om['daily'])) {
        $times = $om['daily']['time'] ?? [];
        for ($i=0; $i < min(7, count($times)); $i++) {
            $daily[] = [
                'dt' => strtotime($times[$i] . ' 12:00:00'),
                'temp' => [
                    'min' => $om['daily']['temperature_2m_min'][$i] ?? null,
                    'max' => $om['daily']['temperature_2m_max'][$i] ?? null,
                ],
                'pop' => isset($om['daily']['precipitation_probability_max'][$i]) ? (($om['daily']['precipitation_probability_max'][$i]) / 100) : 0,
                'weather' => [['code' => $om['daily']['weathercode'][$i] ?? null]],
                'sunrise' => isset($om['daily']['sunrise'][$i]) ? strtotime($om['daily']['sunrise'][$i]) : null,
                'sunset' => isset($om['daily']['sunset'][$i]) ? strtotime($om['daily']['sunset'][$i]) : null,
            ];
        }
    }
    echo json_encode([
        'source' => 'openmeteo',
        'lat' => $lat,
        'lon' => $lng,
        'timezone' => 'Asia/Manila',
        'current' => $current,
        'hourly' => $hourly,
        'daily' => $daily,
        'alerts' => [],
        'sources_used' => ['openmeteo']
    ]);
    exit;
}

// default aggregation if provider not openmeteo
$ow = fetch_openweather($lat, $lng);
$wa = fetch_weatherapi($lat, $lng);
$agg = aggregate_weather($ow, $wa);
echo json_encode($agg);
?>


