<?php
// Mapping and routing configuration

// Store location (origin for deliveries). Update to your actual store location.
// Default center; will be overridden by STORE_ORIGIN_ADDRESS geocoding in UI if provided
define('STORE_ORIGIN_LAT', 14.5906); // fallback latitude
define('STORE_ORIGIN_LNG', 120.9810); // fallback longitude

// Preferred human-readable origin (will be geocoded on page load)
define('STORE_ORIGIN_ADDRESS', '1828 Cavite St, Santa Cruz, Manila, 1014 Metro Manila');

// Tile provider (Leaflet)
define('LEAFLET_TILE_URL', 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png');
define('LEAFLET_TILE_ATTRIB', '© OpenStreetMap contributors');

// Optional: Mapbox access token if you prefer Mapbox tiles/geocoding (leave empty to use OSM/Nominatim)
define('MAPBOX_ACCESS_TOKEN', '');

// Routing backend. Default to public OSRM demo. For production, host your own OSRM or use Mapbox Directions API.
define('OSRM_BASE_URL', 'https://router.project-osrm.org');

// Geocoding backend. If MAPBOX_ACCESS_TOKEN is set, we can use Mapbox; else use Nominatim (public OSM search API)
define('GEOCODER', empty(MAPBOX_ACCESS_TOKEN) ? 'nominatim' : 'mapbox');

// Geocoder scoping/prioritization (NCR + Bulacan + Caloocan vicinity)
// Country code (ISO 3166-1 alpha2)
define('GEOCODER_COUNTRY', 'ph');

// Viewbox covering Metro Manila and Bulacan vicinity
// Format: left_lon,top_lat,right_lon,bottom_lat
define('GEOCODER_VIEWBOX', '120.90,15.30,121.20,14.30');

// If true, Nominatim results are restricted to the viewbox; if false, the box is a bias only
define('GEOCODER_BOUNDED', true);

// Weather configuration
define('WEATHER_PROVIDER', 'openweathermap'); // options: 'openmeteo', 'openweathermap'
define('WEATHER_API_KEY', '3194d23a46b8092639d066470e1a442b'); // Or paste your key string here
define('WEATHER_UNITS', 'metric'); // 'metric' (C), 'imperial' (F)
define('WEATHER_LANG', 'en');
// Optional secondary provider for aggregation (improves accuracy)
define('WEATHERAPI_API_KEY', '726ba1a31076402797324112251008');

// Optional map weather overlay toggles (require OpenWeatherMap key)
define('WEATHER_MAP_OVERLAYS', true);
?>


<?php
// SMTP / Email configuration (use Gmail SMTP with an App Password)
// IMPORTANT: Replace placeholders below with your Gmail SMTP settings
if (!defined('SMTP_HOST')) {
    define('SMTP_HOST', 'smtp.gmail.com');
}
if (!defined('SMTP_PORT')) {
    define('SMTP_PORT', 587); // 587 for TLS, 465 for SSL
}
if (!defined('SMTP_SECURE')) {
    define('SMTP_SECURE', 'tls'); // 'tls' or 'ssl'
}
if (!defined('SMTP_USERNAME')) {
    define('SMTP_USERNAME', 'aljeansinohin05@gmail.com'); // set your Gmail address
}
if (!defined('SMTP_PASSWORD')) {
    define('SMTP_PASSWORD', 'vdxnekbnqyxpaweq'); // Gmail App Password (no spaces)
}
if (!defined('SMTP_FROM_EMAIL')) {
    define('SMTP_FROM_EMAIL', SMTP_USERNAME);
}
if (!defined('SMTP_FROM_NAME')) {
    define('SMTP_FROM_NAME', 'Cedrics Grain Center');
}
?>

<?php
// Application Base URL for building absolute links in emails (e.g., password reset)
// Example for XAMPP on LAN: 'http://192.168.100.23/RicePos/Captone-Project-RicePos/public'
// Leave empty to auto-detect from the current HTTP request.
if (!defined('APP_BASE_URL')) {
    define('APP_BASE_URL', 'http://192.168.100.23/RicePos/Captone-Project-RicePos/public');
    // ipv4 lan address change it to if needed
}

// During testing you can disable password reset throttling (set to true)
if (!defined('PASSWORD_RESET_THROTTLE_DISABLED')) {
    define('PASSWORD_RESET_THROTTLE_DISABLED', true);
}
?>

