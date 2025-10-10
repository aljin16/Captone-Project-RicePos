<?php
session_start();
require_once __DIR__ . '/../classes/User.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../includes/config.php';
// Resolve store origin coordinates server-side for precise map center
$originLat = (float)STORE_ORIGIN_LAT;
$originLng = (float)STORE_ORIGIN_LNG;
if (defined('STORE_ORIGIN_ADDRESS') && STORE_ORIGIN_ADDRESS) {
    $params = [
        'format' => 'json',
        'limit' => 1,
        'q' => STORE_ORIGIN_ADDRESS,
        'countrycodes' => defined('GEOCODER_COUNTRY') ? GEOCODER_COUNTRY : 'ph',
    ];
    if (defined('GEOCODER_VIEWBOX') && GEOCODER_VIEWBOX) {
        $params['viewbox'] = GEOCODER_VIEWBOX;
    }
    $url = 'https://nominatim.openstreetmap.org/search?' . http_build_query($params);
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'RicePOS-Origin-Geocoder/1.0');
    $res = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($res, true);
    if (is_array($data) && isset($data[0]['lat'], $data[0]['lon'])) {
        $originLat = (float)$data[0]['lat'];
        $originLng = (float)$data[0]['lon'];
    }
}
$user = new User();
if (!$user->isLoggedIn()) { header('Location: index.php'); exit; }
// Role of the current user (page is viewable by any authenticated role)
$role = $_SESSION['role'] ?? '';

$pdo = Database::getInstance()->getConnection();

// Handle status updates
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_delivery_status'])) {
    // Only admin can update statuses; staff are view-only
    if ($role !== 'admin') {
        $message = 'Unauthorized: only admin can update delivery status.';
    } else {
        $deliveryId = (int)($_POST['delivery_id'] ?? 0);
        $newStatus = $_POST['status'] ?? '';
        $allowed = ['pending','out_for_delivery','delivered','cancelled'];
        if ($deliveryId > 0 && in_array($newStatus, $allowed, true)) {
            $upd = $pdo->prepare('UPDATE delivery_orders SET status = ?, updated_at = NOW() WHERE id = ?');
            if ($upd->execute([$newStatus, $deliveryId])) {
                $message = 'Status updated.';
                // Log delivery status change in activity logs
                try {
                    require_once __DIR__ . '/../classes/ActivityLog.php';
                    $logger = new ActivityLog();
                    $logger->log([
                        'action' => 'delivery_status',
                        'details' => 'Delivery #'.$deliveryId.' status changed to '.$newStatus
                    ]);
                } catch (Throwable $e) { /* ignore log failure */ }
            } else { $message = 'Update failed.'; }
        } else { $message = 'Invalid request.'; }
    }
}

// Filters and pagination
$statusFilter = $_GET['status'] ?? '';
$q = trim($_GET['q'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 10; $offset = ($page-1)*$perPage;

$where = []; $params = [];
if ($statusFilter && in_array($statusFilter, ['pending','out_for_delivery','delivered','cancelled'], true)) { $where[] = 'd.status = ?'; $params[] = $statusFilter; }
if ($q !== '') { $where[] = '(d.customer_name LIKE ? OR d.customer_address LIKE ? OR d.notes LIKE ? OR s.transaction_id LIKE ?)'; $params[] = "%$q%"; $params[] = "%$q%"; $params[] = "%$q%"; $params[] = "%$q%"; }
$whereSql = $where ? ('WHERE '.implode(' AND ', $where)) : '';

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM delivery_orders d JOIN sales s ON s.id = d.sale_id $whereSql");
$countStmt->execute($params); $totalRows = (int)$countStmt->fetchColumn(); $totalPages = (int)ceil($totalRows / $perPage);

$sql = "SELECT d.id, d.created_at, d.customer_name, d.customer_phone, d.customer_address, d.notes, d.status, s.transaction_id, s.total_amount
        FROM delivery_orders d
        JOIN sales s ON s.id = d.sale_id
        $whereSql
        ORDER BY d.created_at DESC
        LIMIT $perPage OFFSET $offset";
$stmt = $pdo->prepare($sql); $stmt->execute($params); $rows = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
    <title>Delivery Management - RicePOS</title>
    <?php $cssVer = @filemtime(__DIR__ . '/assets/css/style.css') ?: time(); ?>
    <link rel="stylesheet" href="assets/css/style.css?v=<?php echo htmlspecialchars((string)$cssVer, ENT_QUOTES); ?>">
    <link rel="stylesheet" href="assets/css/mobile-delivery.css">
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin=""/>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
    <style>
    .weather { color:#b45309; font-weight:700; }
    *, *::before, *::after { box-sizing: border-box; }
    html, body { height: 100%; }
    body { display: block; min-height: 100vh; margin: 0; background: #f4f6fb; overflow-x: hidden; }
    /* Rely on shared .main-content styles in assets/css/style.css for consistent layout */
    .main-content { background: #f4f6fb; min-height: 100vh; overflow-x: hidden; }
    .filters { 
        display: grid; 
        grid-template-columns: auto 200px 1fr auto; 
        gap: 0.6rem; 
        margin: 0.8rem 0; 
        align-items: center; 
        width: 100%;
        padding: 0.3rem 0;
    }
    .filters input, .filters select { 
        padding: 0.4rem 0.8rem; 
        border: 1px solid #d1d5db; 
        border-radius: 999px; 
        background: #fff; 
        font-size: 0.88rem; 
        height: 36px;
        box-sizing: border-box;
        transition: all 0.2s ease;
    }
    .filters input:focus, .filters select:focus {
        border-color: #3b82f6;
        box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        outline: none;
    }
    .filters select { 
        min-width: 180px; 
        width: 200px; 
        -webkit-appearance: none; 
        -moz-appearance: none; 
        appearance: none; 
        background-position: right 12px center; 
        background-repeat: no-repeat; 
    }
    .filters input[type="text"] { 
        width: 100%; 
        min-width: 320px; 
    }
    .filters .btn { 
        padding: 0.35rem 0.8rem; 
        background: #3b82f6; 
        color: #fff; 
        border: none; 
        border-radius: 999px; 
        font-weight: 700; 
        cursor: pointer; 
        height: 36px;
        white-space: nowrap;
        box-sizing: border-box;
        transition: background 0.15s ease;
        font-size: 0.85rem;
    }
    .filters .btn:hover { background: #2563eb; }
    .filter-group { display: contents; }
    /* Top quick actions */
    .top-actions { display:flex; gap:0.5rem; flex-wrap:wrap; align-items:center; margin:0.3rem 0 0.2rem; }
    .btn-outline { background:#e5e7eb; color:#111827; border:1px solid #d1d5db; border-radius:999px; padding:0.4rem 0.8rem; font-size:0.88rem; font-weight:800; cursor:pointer; transition:all .15s ease; }
    .btn-outline:hover { background:#dbeafe; border-color:#bfdbfe; }
    /* Visually hide the Status label but keep it accessible */
    .filters label[for="statusFilter"]{
        position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0,0,0,0); white-space: nowrap; border: 0;
    }
    .badge { display:inline-block; padding: 0.1rem 0.4rem; border-radius: 6px; font-size: 0.78rem; border:1px solid transparent; }
    .b-pending { background:#fee2e2; color:#991b1b; border-color:#fecaca; }
    .b-transit { background:#dbeafe; color:#1e40af; border-color:#bfdbfe; }
    .b-delivered { background:#dcfce7; color:#166534; border-color:#bbf7d0; }
    .b-cancelled { background:#e5e7eb; color:#374151; border-color:#d1d5db; }
    .pagination { display:flex; gap:0.3rem; margin-top: 0.8rem; }
    .pagination a { padding:0.3rem 0.6rem; border:1px solid #d1d5db; border-radius:6px; text-decoration:none; color:#111827; }
    .pagination .active { background:#e5e7eb; }
     /* Modern table styles */
     .table-card { background:#fff; border:1px solid #e5e7eb; border-radius:14px; box-shadow: 0 6px 18px rgba(17,24,39,0.05); overflow: hidden; }
     .table-scroll { overflow:auto; }
     .user-table { width:100%; border-collapse: separate; border-spacing:0; min-width: 920px; }
    .user-table thead th { position: sticky; top:0; background: linear-gradient(180deg,#f8fafc 0%, #eef2ff 100%); color:#1f2937; font-weight:800; font-size:0.95rem; letter-spacing:0.3px; text-align:left; padding:0.9rem 1rem; border-bottom:1px solid #e5e7eb; }
     .user-table thead th:first-child { border-top-left-radius: 14px; }
     .user-table thead th:last-child { border-top-right-radius: 14px; }
    .user-table tbody td { padding:0.85rem 1rem; border-bottom:1px solid #eef2f7; color:#111827; background:#fff; vertical-align: top; }
    .user-table tbody tr:nth-child(odd) td { background:#fcfdff; }
    .user-table tbody tr:hover td { background:#f7fbff; }
     .user-table tbody tr:last-child td { border-bottom:none; }
     .user-table tbody td:nth-child(1) { color:#475569; font-variant-numeric: tabular-nums; }
     .user-table tbody td:nth-child(2) { color:#475569; }
     .user-table tbody td:nth-child(7) { text-align:right; font-variant-numeric: tabular-nums; }
     .user-table tbody td:nth-child(9) { white-space: nowrap; }
     .table-toolbar { display:flex; align-items:center; justify-content:space-between; gap:0.6rem; margin: 0.5rem 0 0.6rem; }
    .map-panel { margin-top: 0.8rem; background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding: 0.6rem; }
    #mgmtMap { width: 100%; height: 360px; border-radius: 10px; }
    .route-summary { margin-top: 0.4rem; color:#111827; font-size: 1.05rem; font-weight: 700; }
    .route-summary .dist { color:#1d4ed8; }
    .route-summary .eta { color:#059669; }
    .btn.small { padding: 0.25rem 0.6rem; font-size: 0.9rem; }
    .weather-forecast-icon { animation:none; filter:none; border-radius:12px; background:#fff; padding:4px; box-shadow:0 1px 4px rgba(0,0,0,0.06); }
     .weather-forecast-icon:hover {
         transform: scale(1.1) rotate(5deg);
         filter: drop-shadow(0 8px 24px rgba(44,108,223,0.35));
    }
    @keyframes floatIcon {
         0% { transform: translateY(0) scale(1) rotate(0deg); filter: drop-shadow(0 6px 16px rgba(44,108,223,0.25)); }
         50% { transform: translateY(-12px) scale(1.05) rotate(2deg); filter: drop-shadow(0 16px 32px rgba(44,108,223,0.3)); }
         100% { transform: translateY(0) scale(1) rotate(0deg); filter: drop-shadow(0 6px 16px rgba(44,108,223,0.25)); }
     }
     .weather-card { background:#fff; border-radius:12px; box-shadow:0 2px 8px rgba(0,0,0,0.04); padding:0.6rem 0.5rem; display:flex; flex-direction:column; align-items:center; min-width:0; border:1px solid #eef2ff; transition:none; overflow:hidden; }
     .weather-card::before { display:none; }
     .weather-card:hover {
         transform: translateY(-4px);
         box-shadow: 0 12px 40px rgba(44,108,223,0.18), 0 4px 12px rgba(0,0,0,0.08);
     }
     .weather-day { font-size: 0.9rem; font-weight: 700; color:#374151; margin-bottom: 0.4rem; text-transform: uppercase; letter-spacing: 0.4px; }
     .weather-temp { font-size: 1rem; font-weight: 800; color:#1e40af; margin:0.2rem 0; }
     .weather-rain { font-size: 0.85rem; color:#6b7280; font-weight:600; background:#eef2ff; padding:0.15rem 0.5rem; border-radius:999px; border:1px solid #e0e7ff; }
     .weather-grid { display:grid; grid-template-columns: repeat(5, 1fr); gap:0.6rem; position:relative; z-index:1; }

    /* Compact weather/GPS panels - neutral backgrounds, smaller footprint */
    #originWeatherPanel, #gpsLocationPanel { background:#fff !important; border:1px solid #e5e7eb !important; border-radius:14px !important; box-shadow:0 4px 12px rgba(17,24,39,0.06) !important; }
    #originWeatherPanel > svg, #gpsLocationPanel > svg { display:none !important; }
    #gpsRidersList { max-height: 160px !important; }
    .rider-avatar { width:36px; height:36px; font-size:1.2rem; }
    .rider-actions .btn { padding:0.25rem 0.6rem; font-size:0.8rem; border-radius:10px; }
     .gps-rider-card {
         background: linear-gradient(135deg, rgba(255,255,255,0.95), rgba(255,255,255,0.85));
         border-radius: 16px;
         box-shadow: 0 4px 20px rgba(34,197,94,0.12), 0 2px 8px rgba(0,0,0,0.05);
         padding: 1rem;
         margin-bottom: 0.8rem;
         border: 1px solid rgba(34,197,94,0.08);
         transition: all 0.3s ease;
         position: relative;
         overflow: hidden;
     }
     .gps-rider-card::before {
         content: '';
         position: absolute;
         top: 0;
         left: 0;
         right: 0;
         height: 3px;
         background: linear-gradient(90deg, #22c55e, #16a34a, #15803d);
         border-radius: 16px 16px 0 0;
     }
     .gps-rider-card:hover {
         transform: translateY(-2px);
         box-shadow: 0 8px 30px rgba(34,197,94,0.18), 0 4px 12px rgba(0,0,0,0.08);
     }
     .rider-info {
         display: flex;
         align-items: center;
         gap: 1rem;
         margin-bottom: 0.8rem;
     }
     .rider-avatar {
         font-size: 2rem;
         width: 50px;
         height: 50px;
         display: flex;
         align-items: center;
         justify-content: center;
         background: linear-gradient(135deg, rgba(34,197,94,0.1), rgba(34,197,94,0.05));
         border-radius: 50%;
         border: 2px solid rgba(34,197,94,0.2);
     }
     .rider-details {
         flex: 1;
     }
     .rider-name {
         font-size: 1rem;
         font-weight: 600;
         color: #1f2937;
         margin-bottom: 0.2rem;
     }
     .rider-status {
         font-size: 0.9rem;
         font-weight: 500;
         color: #374151;
         margin-bottom: 0.2rem;
     }
     .rider-location {
         font-size: 0.85rem;
         color: #6b7280;
         font-weight: 500;
     }
     .rider-vehicle {
         font-size: 0.8rem;
         color: #059669;
         font-weight: 600;
         background: rgba(5,150,105,0.1);
         padding: 0.2rem 0.5rem;
         border-radius: 8px;
         margin-top: 0.3rem;
         display: inline-block;
     }
      .rider-avatar-img { width: 100%; height: 100%; object-fit: contain; display: block; }
      .rider-actions {
         display: flex;
         gap: 0.5rem;
         justify-content: flex-end;
     }
     .rider-actions .btn {
         padding: 0.3rem 0.8rem;
         font-size: 0.85rem;
         background: #22c55e;
         border-color: #22c55e;
         color: white;
     }
     .rider-actions .btn:hover {
         background: #16a34a;
         border-color: #16a34a;
    }
    
    /* Mobile Responsive Styles */
    @media(max-width:1024px){
        .filters{ grid-template-columns:1fr; gap:0.8rem; }
        .filters .filter-group{ width:100%; }
        .filters select{ width:100%; min-width:100%; }
        .filters input[type="text"]{ min-width:100%; }
        .filters .btn{ width:100%; }
        .weather-grid{ grid-template-columns:repeat(4, 1fr); gap:0.8rem; }
    }
    
    @media(max-width:768px){
        /* Weather and GPS panels stacked */
        .weather-grid{ grid-template-columns:repeat(3, 1fr); gap:0.6rem; }
        .weather-card{ padding:0.8rem 0.6rem; }
        .weather-day{ font-size:0.9rem; }
        .weather-temp{ font-size:1rem; }
        .weather-rain{ font-size:0.85rem; padding:0.15rem 0.5rem; }
        .weather-forecast-icon{ width:40px !important; height:40px !important; }
        
        /* GPS Rider Card */
        .gps-rider-card{ padding:0.8rem; }
        .rider-info{ flex-direction:column; align-items:flex-start; gap:0.6rem; }
        .rider-avatar{ width:40px; height:40px; font-size:1.5rem; }
        .rider-name{ font-size:0.95rem; }
        .rider-status{ font-size:0.85rem; }
        .rider-location{ font-size:0.8rem; }
        .rider-vehicle{ font-size:0.75rem; }
        .rider-actions{ flex-direction:column; width:100%; }
        .rider-actions .btn{ width:100%; justify-content:center; min-height:44px; }
        
        /* Table card */
        .table-card{ overflow-x:auto; -webkit-overflow-scrolling:touch; }
        .user-table{ min-width:920px; font-size:0.9rem; }
        .user-table thead th{ padding:0.7rem 0.8rem; font-size:0.85rem; }
        .user-table tbody td{ padding:0.7rem 0.8rem; font-size:0.85rem; }
        .user-table tbody td form{ flex-wrap:wrap; }
        .user-table tbody td select, .user-table tbody td button{ font-size:0.85rem; padding:0.3rem 0.4rem; }
        
        /* Map panel */
        #mgmtMap{ height:300px; }
        .route-summary{ font-size:0.95rem; }
        
        /* Pagination */
        .pagination{ flex-wrap:wrap; justify-content:center; }
        .pagination a{ padding:0.4rem 0.7rem; font-size:0.9rem; }
    }
    
    @media(max-width:640px){
        .weather-grid{ grid-template-columns:repeat(2, 1fr); gap:0.5rem; }
        .weather-card{ padding:0.7rem 0.5rem; }
        .weather-day{ font-size:0.85rem; }
        .weather-temp{ font-size:0.95rem; }
        .weather-rain{ font-size:0.8rem; }
        .weather-forecast-icon{ width:36px !important; height:36px !important; }
        
        /* GPS panel */
        .rider-actions .btn{ font-size:0.85rem; padding:0.5rem; }
        
        /* Filters fully stacked */
        .filters{ padding:0.4rem 0; }
        .filter-group{ flex-direction:column; align-items:stretch; height:auto; }
        .filter-group label{ margin-bottom:0.3rem; }
        
        /* Map smaller */
        #mgmtMap{ height:250px; }
    }
    
    @media(max-width:480px){
        .weather-grid{ grid-template-columns:1fr; gap:0.5rem; }
        .weather-card{ padding:0.8rem; }
        .weather-forecast-icon{ width:44px !important; height:44px !important; }
        
        /* Table: show horizontal scroll hint */
        .table-card::after{ content:'← Scroll →'; display:block; text-align:center; padding:0.5rem; color:#9ca3af; font-size:0.85rem; }
        
        /* Rider card even more compact */
        .rider-name{ font-size:0.9rem; }
        .rider-status, .rider-location{ font-size:0.8rem; }
    }
    </style>
</head>
<body>
    <?php $activePage = 'delivery_management.php'; $pageTitle = 'Delivery Management'; include __DIR__ . '/../includes/sidebar.php'; include __DIR__ . '/../includes/header.php'; ?>
    <main class="main-content">
        
        <?php if ($message): ?><div class="muted" style="margin-bottom:0.5rem;"><?php echo htmlspecialchars($message); ?></div><?php endif; ?>
        <div class="top-actions">
            <button class="btn-outline" type="button" onclick="document.getElementById('originWeatherPanel').scrollIntoView({behavior:'smooth', block:'start'});">Weather forecast</button>
            <button class="btn-outline" type="button" onclick="document.getElementById('gpsLocationPanel').scrollIntoView({behavior:'smooth', block:'start'});">gps live location</button>
        </div>
                 <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; margin-bottom: 1.2rem;">
             <!-- Weather Forecast Panel -->
             <div id="originWeatherPanel" class="card" style="box-shadow:0 8px 32px rgba(44,108,223,0.10),0 1.5px 0 rgba(255,255,255,0.4);border-radius:18px;overflow:hidden;position:relative;background:linear-gradient(120deg,rgba(44,108,223,0.13) 0%,rgba(255,255,255,0.85) 100%), url('https://www.transparenttextures.com/patterns/cubes.png');border:2.5px solid rgba(44,108,223,0.13);box-shadow:0 8px 32px rgba(44,108,223,0.13),0 1.5px 0 rgba(255,255,255,0.4),0 0 32px 0 rgba(44,108,223,0.08);">
            <!-- Decorative SVG top right -->
            <svg style="position:absolute;top:-30px;right:-30px;width:120px;height:120px;z-index:0;opacity:0.22;pointer-events:none;" viewBox="0 0 120 120"><defs><radialGradient id="g1" cx="60" cy="60" r="60" gradientUnits="userSpaceOnUse"><stop offset="0%" stop-color="#2d6cdf"/><stop offset="100%" stop-color="#fff" stop-opacity="0"/></radialGradient></defs><circle cx="60" cy="60" r="60" fill="url(#g1)"/></svg>
            <!-- Decorative SVG bottom left -->
            <svg style="position:absolute;bottom:-30px;left:-30px;width:100px;height:100px;z-index:0;opacity:0.16;pointer-events:none;" viewBox="0 0 100 100"><defs><radialGradient id="g2" cx="50" cy="50" r="50" gradientUnits="userSpaceOnUse"><stop offset="0%" stop-color="#e0e7ff"/><stop offset="100%" stop-color="#fff" stop-opacity="0"/></radialGradient></defs><circle cx="50" cy="50" r="50" fill="url(#g2)"/></svg>
            <div style="position:relative;z-index:1;display:flex;align-items:center;justify-content:space-between;margin-bottom:0.7rem;">
                      <div>
                          <strong style="font-size:1.15rem;letter-spacing:0.5px;">7-Day Weather Forecast</strong>
                          <div id="realtimeDateTime" style="font-size:0.9rem;color:#6b7280;margin-top:0.2rem;font-weight:500;"></div>
                          <div id="originWeatherLocation" class="muted" style="font-size:0.9rem;color:#374151;margin-top:0.15rem;">📍 Resolving location…</div>
                      </div>
                <button class="btn small" id="refreshOriginWeather">Refresh</button>
            </div>
                 <div id="originWeatherDaily" class="weather-grid"></div>
            <div id="originWeatherError" class="muted" style="margin-top:0.5rem;position:relative;z-index:1;"></div>
             </div>

             <!-- GPS Live Location Panel -->
             <div id="gpsLocationPanel" class="card" style="box-shadow:0 8px 32px rgba(34,197,94,0.10),0 1.5px 0 rgba(255,255,255,0.4);border-radius:18px;overflow:hidden;position:relative;background:linear-gradient(120deg,rgba(34,197,94,0.13) 0%,rgba(255,255,255,0.85) 100%), url('https://www.transparenttextures.com/patterns/cubes.png');border:2.5px solid rgba(34,197,94,0.13);box-shadow:0 8px 32px rgba(34,197,94,0.13),0 1.5px 0 rgba(255,255,255,0.4),0 0 32px 0 rgba(34,197,94,0.08);">
                 <!-- Decorative SVG top right -->
                 <svg style="position:absolute;top:-30px;right:-30px;width:120px;height:120px;z-index:0;opacity:0.22;pointer-events:none;" viewBox="0 0 120 120"><defs><radialGradient id="g3" cx="60" cy="60" r="60" gradientUnits="userSpaceOnUse"><stop offset="0%" stop-color="#22c55e"/><stop offset="100%" stop-color="#fff" stop-opacity="0"/></radialGradient></defs><circle cx="60" cy="60" r="60" fill="url(#g3)"/></svg>
                 <!-- Decorative SVG bottom left -->
                 <svg style="position:absolute;bottom:-30px;left:-30px;width:100px;height:100px;z-index:0;opacity:0.16;pointer-events:none;" viewBox="0 0 100 100"><defs><radialGradient id="g4" cx="50" cy="50" r="50" gradientUnits="userSpaceOnUse"><stop offset="0%" stop-color="#bbf7d0"/><stop offset="100%" stop-color="#fff" stop-opacity="0"/></radialGradient></defs><circle cx="50" cy="50" r="50" fill="url(#g4)"/></svg>
                 <div style="position:relative;z-index:1;display:flex;align-items:center;justify-content:space-between;margin-bottom:0.7rem;">
                     <div>
                        <strong style="font-size:1.15rem;letter-spacing:0.5px;">GPS Live Location</strong>
                        <div id="gpsStatus" style="font-size:0.9rem;color:#6b7280;margin-top:0.2rem;font-weight:500;">⏳ Waiting for GPS...</div>
                     </div>
                     <button class="btn small" id="refreshGPS" style="background: #22c55e; border-color: #22c55e;">Refresh</button>
                 </div>
                                   <div id="gpsRidersList" style="position:relative;z-index:1;max-height:300px;overflow-y:auto;">
                      <div class="gps-rider-card">
                          <div class="rider-info">
                               <div class="rider-avatar"><img src="assets/img/dce3d07b96a346beabc6721b41ba045c-removebg-preview.png" alt="Toyota Hilux" class="rider-avatar-img"></div>
                              <div class="rider-details">
                                  <div class="rider-name">Edgar - Toyota Hilux</div>
                                   <div class="rider-status"><strong>Plate Number:</strong> ABC-123</div>
                                  <div class="rider-location" id="gpsLocationText">📍 Resolving location…</div>
                                  <div class="rider-vehicle">🚚 Toyota Hilux - Plate: ABC-123</div>
                              </div>
                          </div>
                          <div class="rider-actions">
                              <button class="btn small" onclick="trackRider('edgar')">Track Live</button>
                              <button class="btn small" onclick="contactRider('edgar')">Call</button>
                              <button class="btn small" onclick="openGoogleMaps()" style="background: #4285f4; border-color: #4285f4;">🗺️ Maps</button>
                              <button class="btn small" onclick="showGPSInstructions()">GPS Setup</button>
                          </div>
                      </div>
                  </div>
                 <div id="gpsError" class="muted" style="margin-top:0.5rem;position:relative;z-index:1;"></div>
             </div>
        </div>
        <form method="get" class="filters">
            <div class="filter-group">
                <label for="statusFilter" style="font-weight:700; color:#374151;">Status:</label>
                <select name="status" id="statusFilter">
                    <option value="">All Statuses</option>
                    <option value="pending" <?php echo $statusFilter==='pending'?'selected':''; ?>>Pending</option>
                    <option value="out_for_delivery" <?php echo $statusFilter==='out_for_delivery'?'selected':''; ?>>In Transit</option>
                    <option value="delivered" <?php echo $statusFilter==='delivered'?'selected':''; ?>>Delivered</option>
                    <option value="cancelled" <?php echo $statusFilter==='cancelled'?'selected':''; ?>>Cancelled</option>
                </select>
            </div>
            <input type="text" name="q" placeholder="Search by name, address or TXN" value="<?php echo htmlspecialchars($q); ?>">
            <button class="btn" type="submit">Filter</button>
        </form>

        <div class="map-panel">
            <div id="mgmtMap"></div>
            <div id="mgmtSummary" class="route-summary"></div>
            <div id="routeWeatherSummary" class="muted" style="margin-top:0.5rem;"></div>
        </div>
        <div class="table-card">
          <div class="table-scroll">
        <table class="user-table">
            <thead><tr><th>ID</th><th>Date</th><th>Transaction</th><th>Customer</th><th>Phone</th><th>Address</th><th>Notes</th><th>Total</th><th>Status</th><?php echo ($role==='admin')?'<th>Actions</th>':''; ?></tr></thead>
            <tbody>
            <?php foreach ($rows as $d): ?>
                <tr>
                    <td><?php echo (int)$d['id']; ?></td>
                    <td><?php echo htmlspecialchars($d['created_at']); ?></td>
                    <td><a href="receipt.php?txn=<?php echo urlencode($d['transaction_id']); ?>" target="_blank"><?php echo htmlspecialchars($d['transaction_id']); ?></a></td>
                    <td><?php echo htmlspecialchars($d['customer_name']); ?></td>
                    <td><?php echo htmlspecialchars($d['customer_phone']); ?></td>
                    <td><?php echo htmlspecialchars($d['customer_address']); ?></td>
                    <td><?php echo htmlspecialchars($d['notes'] ?? ''); ?></td>
                    <td>₱<?php echo number_format((float)$d['total_amount'],2); ?></td>
                    <td>
                        <?php
                            $cls = $d['status']==='pending'?'b-pending':($d['status']==='out_for_delivery'?'b-transit':($d['status']==='delivered'?'b-delivered':'b-cancelled'));
                        ?>
                        <span class="badge <?php echo $cls; ?>"><?php echo htmlspecialchars($d['status']); ?></span>
                    </td>
                    <?php if ($role==='admin'): ?>
                    <td>
                        <form method="post" style="display:flex; gap:6px; align-items:center;">
                            <input type="hidden" name="delivery_id" value="<?php echo (int)$d['id']; ?>">
                            <select name="status" class="btn" style="padding:0.35rem 0.5rem;">
                                <option value="pending" <?php echo $d['status']==='pending'?'selected':''; ?>>Pending</option>
                                <option value="out_for_delivery" <?php echo $d['status']==='out_for_delivery'?'selected':''; ?>>In Transit</option>
                                <option value="delivered" <?php echo $d['status']==='delivered'?'selected':''; ?>>Delivered</option>
                                <option value="cancelled" <?php echo $d['status']==='cancelled'?'selected':''; ?>>Cancelled</option>
                            </select>
                            <button type="submit" name="update_delivery_status" class="btn">Update</button>
                        </form>
                        <button class="btn" onclick="focusDelivery(<?php echo (int)$d['id']; ?>)"><i class='bx bx-map'></i> Route</button>
                    </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
          </div>
        </div>

        <?php if ($totalPages > 1): ?>
        <div class="pagination">
            <?php for ($p=1; $p<=$totalPages; $p++):
                $qs = http_build_query(array_merge($_GET, ['page'=>$p]));
                $active = $p === $page ? 'active' : '';
            ?>
                <a class="<?php echo $active; ?>" href="?<?php echo htmlspecialchars($qs); ?>"><?php echo $p; ?></a>
            <?php endfor; ?>
        </div>
        <?php endif; ?>
    </main>
    <script>
    const TILE_URL = <?php echo json_encode(LEAFLET_TILE_URL); ?>;
    const TILE_ATTR = <?php echo json_encode(LEAFLET_TILE_ATTRIB); ?>;
    const STORE = { lat: <?php echo json_encode($originLat); ?>, lng: <?php echo json_encode($originLng); ?> };
    let mgmtMap, mgmtLayer, storeMarker;
    let deliveries = [
        <?php
        // fetch geo-enhanced deliveries for quick focus; re-run the same query but include lat/lng
        $geoStmt = $pdo->prepare("SELECT d.id, d.customer_name, d.customer_address, d.customer_lat, d.customer_lng FROM delivery_orders d JOIN sales s ON s.id = d.sale_id $whereSql ORDER BY d.created_at DESC LIMIT $perPage OFFSET $offset");
        $geoStmt->execute($params);
        $geoRows = $geoStmt->fetchAll();
        $first = true;
        foreach ($geoRows as $g) {
            $lat = isset($g['customer_lat']) ? (float)$g['customer_lat'] : null;
            $lng = isset($g['customer_lng']) ? (float)$g['customer_lng'] : null;
            echo ($first?'':',') . json_encode([
                'id'=>(int)$g['id'],
                'name'=>$g['customer_name'],
                'address'=>$g['customer_address'],
                'lat'=>$lat,
                'lng'=>$lng
            ]);
            $first = false;
        }
        ?>
    ];

    function initMgmtMap(){
        mgmtMap = L.map('mgmtMap').setView([STORE.lat, STORE.lng], 12);
        L.tileLayer(TILE_URL, { attribution: TILE_ATTR, maxZoom: 19 }).addTo(mgmtMap);
        mgmtLayer = L.layerGroup().addTo(mgmtMap);
        storeMarker = L.marker([STORE.lat, STORE.lng], { title: 'Store' }).addTo(mgmtMap);
        // plot existing markers
        deliveries.forEach(d => {
            if (d.lat && d.lng) {
                L.marker([d.lat, d.lng], { title: d.name }).addTo(mgmtLayer).bindPopup(`<strong>${d.name}</strong><br>${d.address}`);
            }
        });
         }

     // Weather icon as image from assets/img
     function getWeatherIconImg(type) {
         const map = {
             'sunny': 'sunny.png',
             'partly-cloudy-day': 'cloudy.png',
             'cloudy': 'cloudy.png',
             'rainy': 'rainy.png',
             'snowy': 'cloudy.png',
             'thunderstorms': 'rainy.png'
         };
         const file = map[type] || 'cloudy.png';
         return `<img src="assets/img/${file}" alt="${type}" width="36" height="36" style="display:block;" />`;
     }

     // Map Open-Meteo weather codes to our icon types
     function mapWeatherCodeToType(code) {
         const c = Number(code);
         if (c === 0) return 'sunny';
         if ([1,2].includes(c)) return 'partly-cloudy-day';
         if ([3,45,48].includes(c)) return 'cloudy';
         if ((c >= 51 && c <= 57) || (c >= 61 && c <= 67) || (c >= 80 && c <= 82)) return 'rainy';
         if ((c >= 71 && c <= 77) || (c >= 85 && c <= 86)) return 'snowy';
         if (c === 95 || c === 96 || c === 99) return 'thunderstorms';
         return 'cloudy';
     }

     // Map generic text (e.g., from OpenWeather) to icon type
     function mapTextToType(text) {
         const t = String(text || '').toLowerCase();
         if (!t) return 'cloudy';
         if (t.includes('thunder')) return 'thunderstorms';
         if (t.includes('storm')) return 'thunderstorms';
         if (t.includes('snow') || t.includes('sleet') || t.includes('hail')) return 'snowy';
         if (t.includes('rain') || t.includes('drizzle') || t.includes('shower')) return 'rainy';
         if (t.includes('clear') || t.includes('sun')) return 'sunny';
         if (t.includes('cloud') || t.includes('overcast') || t.includes('fog') || t.includes('mist')) return 'cloudy';
         return 'cloudy';
     }

    async function fetchOriginWeather() {
        const panel = document.getElementById('originWeatherDaily');
        const errorDiv = document.getElementById('originWeatherError');
        panel.innerHTML = '<span class="muted">Loading...</span>';
        errorDiv.textContent = '';
        try {
            // Use Open-Meteo directly for reliability (no API key required)
            const url = `https://api.open-meteo.com/v1/forecast?latitude=${STORE.lat}&longitude=${STORE.lng}&daily=temperature_2m_max,temperature_2m_min,precipitation_probability_max,weathercode&timezone=Asia/Manila`;
            const om = await fetch(url, { headers: { 'Accept': 'application/json' }}).then(r=>r.json());
            const times = (om && om.daily && om.daily.time) ? om.daily.time : [];
            const tmaxs = (om && om.daily && om.daily.temperature_2m_max) ? om.daily.temperature_2m_max : [];
            const tmins = (om && om.daily && om.daily.temperature_2m_min) ? om.daily.temperature_2m_min : [];
            const pops = (om && om.daily && om.daily.precipitation_probability_max) ? om.daily.precipitation_probability_max : [];
            const codes = (om && om.daily && om.daily.weathercode) ? om.daily.weathercode : [];
            const cards = times.slice(0,7).map((t,i)=>{
                const day = new Date(t+'T00:00:00').toLocaleDateString(undefined,{weekday:'short'});
                const tmax = Math.round(tmaxs[i]||0);
                const tmin = Math.round(tmins[i]||0);
                const pop = Math.round(pops[i]||0);
                const wcode = codes[i];
                const type = mapWeatherCodeToType(wcode);
                const icon = `<div class="weather-forecast-icon" style="width:52px;height:52px;display:flex;align-items:center;justify-content:center;">${getWeatherIconImg(type)}</div>`;
                return `<div class="weather-card"><div class="weather-day">${day}</div>${icon}<div class="weather-temp">${tmax}° / ${tmin}°C</div><div class="weather-rain">${pop}% rain</div></div>`;
            }).join('');
            panel.innerHTML = cards || '<span class="muted">No forecast</span>';
             // Update readable location via reverse geocoding
             try {
                 const gRes = await fetch(`https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat=${STORE.lat}&lon=${STORE.lng}&zoom=12&addressdetails=1`, { headers: { 'Accept': 'application/json' }});
                 const g = await gRes.json();
                 const a = g.address || {};
                 const parts = [a.village || a.suburb || a.barangay, a.town || a.city || a.municipality, a.state || a.region || a.province].filter(Boolean);
                 const loc = parts.length ? parts.join(', ') : (g.display_name || `${STORE.lat.toFixed(3)}, ${STORE.lng.toFixed(3)}`);
                 const locEl = document.getElementById('originWeatherLocation');
                 if (locEl) locEl.textContent = `📍 ${loc}`;
             } catch {}
            // Done
        } catch(e) {
            panel.innerHTML = '<span class="muted">No forecast</span>';
            errorDiv.textContent = '';
        }
    }
    // Real-time date and time function
    function updateDateTime() {
        const now = new Date();
        const options = {
            weekday: 'long',
            year: 'numeric',
            month: 'long',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit',
            timeZone: 'Asia/Manila'
        };
        const dateTimeString = now.toLocaleDateString('en-US', options);
        const dateTimeElement = document.getElementById('realtimeDateTime');
        if (dateTimeElement) {
            dateTimeElement.textContent = dateTimeString;
        }
    }

    // Update date and time (throttled when tab hidden)
    function startDateTimeUpdate() {
        updateDateTime(); // Initial update
        let clockId = null;
        function start(){ if (clockId) return; clockId = setInterval(updateDateTime, document.hidden ? 5000 : 1000); }
        function stop(){ if (clockId) { clearInterval(clockId); clockId = null; } }
        document.addEventListener('visibilitychange', ()=>{ stop(); start(); });
        start();
    }

         // GPS Live Location Functions
     let riderMarkers = {};
     let riderPositions = {
         'edgar': { 
             lat: 14.5547, 
             lng: 121.0244, 
             name: 'Edgar', 
             vehicle: 'Toyota Hilux', 
             plate: 'ABC-123', 
             status: 'In Transit', 
             delivery: '#123', 
             phone: '+63 991 512 0853',
             gmapsLink: 'https://maps.app.goo.gl/2Y3uafofWVNi6Wq69?g_st=afm',
             fbLink: 'https://www.facebook.com/bytcbj/'
         }
     };

     async function fetchLiveGPS(recenter=false) {
         try {
             const res = await fetch('gps_get.php?_=' + Date.now(), { cache: 'no-store' });
             const data = await res.json();
             if (!data || data.error) {
                 document.getElementById('gpsStatus').textContent = '⚠️ ' + (data && data.error ? data.error : 'No GPS data');
                 return;
             }
             const rider = riderPositions['edgar'];
             rider.lat = parseFloat(data.lat);
             rider.lng = parseFloat(data.lng);
             rider.accuracy = data.accuracy_m;
             rider.updated_at = data.updated_at;
             rider.gmapsLink = `https://www.google.com/maps?q=${rider.lat},${rider.lng}`;
             updateRiderMarkers();
             const now = Date.now()/1000;
             const when = rider.updated_at ? new Date(rider.updated_at * 1000).toLocaleTimeString() : new Date().toLocaleTimeString();
             const acc = rider.accuracy != null ? ` • ±${Math.round(rider.accuracy)}m` : '';
             const age = rider.updated_at ? (now - rider.updated_at) : 9999;
             const stale = age > 20;
             document.getElementById('gpsStatus').textContent = `${stale ? '🟡' : '🟢'} Edgar's Location • ${when}${acc}${stale ? ' • (stale)' : ''}`;
             if (recenter && mgmtMap) {
                 mgmtMap.setView([rider.lat, rider.lng], Math.max(14, mgmtMap.getZoom()));
                 if (riderMarkers['edgar']) riderMarkers['edgar'].openPopup();
             }

             // Reverse geocode to human-readable place
             updateHumanPlace(rider.lat, rider.lng);
         } catch (e) {
             document.getElementById('gpsStatus').textContent = '⚠️ GPS fetch failed';
         }
     }

     async function updateHumanPlace(lat, lng) {
         const el = document.getElementById('gpsLocationText');
         if (!el) return;
         try {
             const url = `https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat=${lat}&lon=${lng}&zoom=14&addressdetails=1`;
             const res = await fetch(url, { headers: { 'Accept': 'application/json' } });
             const d = await res.json();
             if (d && (d.display_name || (d.address && (d.address.village || d.address.town || d.address.city || d.address.state)))) {
                 const a = d.address || {};
                 const parts = [a.village || a.suburb || a.barangay, a.town || a.city || a.municipality, a.state || a.region || a.province]
                     .filter(Boolean);
                 el.textContent = '📍 ' + (parts.length ? parts.join(', ') : (d.display_name || 'Current location'));
             } else {
                 el.textContent = '📍 Current location';
             }
         } catch(e) {
             el.textContent = '📍 Current location';
         }
     }

     function updateRiderMarkers() {
         Object.keys(riderPositions).forEach(riderId => {
             const rider = riderPositions[riderId];
             const icon = L.divIcon({
                 className: 'rider-marker',
                 html: `<div style="background: ${rider.status === 'Available' ? '#ef4444' : rider.status === 'In Transit' ? '#22c55e' : '#f59e0b'}; 
                               width: 20px; height: 20px; border-radius: 50%; border: 3px solid white; 
                               box-shadow: 0 2px 8px rgba(0,0,0,0.3); display: flex; align-items: center; 
                               justify-content: center; font-size: 12px; color: white; font-weight: bold;">
                               ${rider.status === 'Available' ? 'A' : rider.status === 'In Transit' ? 'T' : 'P'}
                        </div>`,
                 iconSize: [20, 20],
                 iconAnchor: [10, 10]
             });

             if (!riderMarkers[riderId]) {
                 riderMarkers[riderId] = L.marker([rider.lat, rider.lng], { icon })
                     .bindPopup(`<strong>${rider.name}</strong><br>Status: ${rider.status}${rider.delivery ? '<br>Delivery: ' + rider.delivery : ''}`)
                     .addTo(mgmtMap);
             } else {
                 riderMarkers[riderId].setLatLng([rider.lat, rider.lng]);
                 riderMarkers[riderId].setIcon(icon);
             }
         });
     }

     function trackRider(riderId) {
         const rider = riderPositions[riderId];
         if (rider && mgmtMap) {
             mgmtMap.setView([rider.lat, rider.lng], 15);
             riderMarkers[riderId]?.openPopup();
             
             // Show route from store to rider if rider is not at store
             if (rider.lat !== STORE.lat || rider.lng !== STORE.lng) {
                 mgmtLayer.clearLayers();
                 L.marker([STORE.lat, STORE.lng], { title: 'Store' }).addTo(mgmtLayer);
                 L.marker([rider.lat, rider.lng], { title: `${rider.name} - ${rider.vehicle}` }).addTo(mgmtLayer);
                 
                 // Draw route line
                 const routeLine = L.polyline([[STORE.lat, STORE.lng], [rider.lat, rider.lng]], {
                     color: '#22c55e',
                     weight: 3,
                     opacity: 0.8,
                     dashArray: '10, 5'
                 }).addTo(mgmtLayer);
                 
                 document.getElementById('mgmtSummary').textContent = `Tracking ${rider.name} - ${rider.vehicle} (${rider.plate}) - ${rider.status}`;
             }
         }
     }

      function contactRider(riderId) {
         const rider = riderPositions[riderId];
         if (rider) {
              // Use live human-readable location from the panel if available
              const liveLoc = (document.getElementById('gpsLocationText')?.textContent || '').replace(/^📍\s*/, '') || 'Resolving location…';
             Swal.fire({
                 title: `Contact ${rider.name}`,
                 html: `
                     <div style="text-align: left; margin: 1rem 0;">
                         <p><strong>Driver:</strong> ${rider.name}</p>
                          <p><strong>Vehicle:</strong> ${rider.vehicle}</p>
                          <p><strong>Plate Number:</strong> ${rider.plate}</p>
                         <p><strong>Status:</strong> ${rider.status}</p>
                          ${rider.delivery ? `<p><strong>Current Delivery:</strong> ${rider.delivery}</p>` : ''}
                          <p><strong>Phone (Dito SIM):</strong> ${rider.phone}</p>
                          <p><strong>Location:</strong> ${liveLoc}</p>
                         <p><strong>Google Maps:</strong> <a href="${rider.gmapsLink}" target="_blank" style="color: #22c55e;">View Live Location</a></p>
                         <p><strong>Facebook:</strong> <a href="${rider.fbLink}" target="_blank" style="color: #1877f2;">Video Call</a></p>
                     </div>
                 `,
                 icon: 'info',
                 showCancelButton: true,
                 showDenyButton: true,
                 confirmButtonText: '📞 Call Now',
                 denyButtonText: '📱 Video Call',
                 cancelButtonText: 'Cancel',
                 confirmButtonColor: '#22c55e',
                 denyButtonColor: '#1877f2'
             }).then((result) => {
                 if (result.isConfirmed) {
                     // Initiate phone call
                     window.open(`tel:${rider.phone}`, '_self');
                 } else if (result.isDenied) {
                     // Open Facebook for video call
                     window.open(rider.fbLink, '_blank');
                 }
             });
         }
     }

     function openGoogleMaps() {
         const rider = riderPositions['edgar'];
         if (rider && rider.gmapsLink) {
             window.open(rider.gmapsLink, '_blank');
         }
     }

     function showGPSInstructions() {
         Swal.fire({
             title: 'GPS Setup Instructions for Edgar',
             html: `
                 <div style="text-align: left; margin: 1rem 0; font-size: 0.9rem;">
                     <h4 style="color: #22c55e; margin-bottom: 1rem;">📱 How to Set Up Live GPS Tracking</h4>
                     
                     <div style="background: #f0f9ff; padding: 1rem; border-radius: 8px; margin-bottom: 1rem; border-left: 4px solid #22c55e;">
                         <h5 style="color: #1e40af; margin-bottom: 0.5rem;">Option 1: Google Maps Location Sharing</h5>
                         <ol style="margin: 0; padding-left: 1.2rem;">
                             <li>Open Google Maps on Edgar's phone</li>
                             <li>Tap his profile picture → Location sharing</li>
                             <li>Tap "Share location" → "Until you turn this off"</li>
                             <li>Share with your email/phone number</li>
                         </ol>
                     </div>
                     
                     <div style="background: #fef3c7; padding: 1rem; border-radius: 8px; margin-bottom: 1rem; border-left: 4px solid #f59e0b;">
                         <h5 style="color: #92400e; margin-bottom: 0.5rem;">Option 2: WhatsApp Location Sharing</h5>
                         <ol style="margin: 0; padding-left: 1.2rem;">
                             <li>Open WhatsApp on Edgar's phone</li>
                             <li>Go to your chat → Attach → Location</li>
                             <li>Tap "Share live location" → 8 hours</li>
                             <li>Send the live location</li>
                         </ol>
                     </div>
                     
                     <div style="background: #ecfdf5; padding: 1rem; border-radius: 8px; margin-bottom: 1rem; border-left: 4px solid #059669;">
                         <h5 style="color: #065f46; margin-bottom: 0.5rem;">Option 3: Find My Friends App</h5>
                         <ol style="margin: 0; padding-left: 1.2rem;">
                             <li>Download "Find My Friends" app</li>
                             <li>Create account and add you as friend</li>
                             <li>Enable location sharing permanently</li>
                             <li>You can track his location anytime</li>
                         </ol>
                     </div>
                     
                     <div style="background: #fef2f2; padding: 1rem; border-radius: 8px; border-left: 4px solid #ef4444;">
                         <h5 style="color: #991b1b; margin-bottom: 0.5rem;">⚠️ Important Notes:</h5>
                         <ul style="margin: 0; padding-left: 1.2rem;">
                             <li>Ensure Edgar's phone has GPS enabled</li>
                             <li>Keep phone charged during deliveries</li>
                             <li>Use mobile data or WiFi for real-time updates</li>
                             <li>Set location accuracy to "High"</li>
                         </ul>
                     </div>
                     
                     <div style="background: #e0e7ff; padding: 1rem; border-radius: 8px; margin-top: 1rem; border-left: 4px solid #3b82f6;">
                         <h5 style="color: #1e40af; margin-bottom: 0.5rem;">📞 Contact Edgar:</h5>
                         <p style="margin: 0;"><strong>Phone (Dito SIM):</strong> +63 991 512 0853</p>
                         <p style="margin: 0;"><strong>Vehicle:</strong> Toyota Hilux - ABC-123</p>
                         <p style="margin: 0;"><strong>Google Maps:</strong> <a href="https://maps.app.goo.gl/2Y3uafofWVNi6Wq69?g_st=afm" target="_blank" style="color: #22c55e;">View Live Location</a></p>
                         <p style="margin: 0;"><strong>Facebook:</strong> <a href="https://www.facebook.com/bytcbj/" target="_blank" style="color: #1877f2;">Video Call</a></p>
                     </div>
                 </div>
             `,
             width: '600px',
             confirmButtonText: 'Got it!',
             confirmButtonColor: '#22c55e',
             showCloseButton: true
         });
     }

     function refreshGPSData() {
         const el = document.getElementById('gpsStatus');
         el.textContent = '⏳ Updating...';
         fetchLiveGPS(true);
     }

    // Start GPS tracking (visibility-aware)
    function startGPSTracking() {
        updateRiderMarkers();
        fetchLiveGPS();
        let gpsId = null;
        function start(){ if (gpsId) return; gpsId = setInterval(fetchLiveGPS, document.hidden ? 12000 : 3000); }
        function stop(){ if (gpsId) { clearInterval(gpsId); gpsId = null; } }
        document.addEventListener('visibilitychange', ()=>{ stop(); start(); });
        start();
    }

    document.getElementById('refreshOriginWeather').addEventListener('click', fetchOriginWeather);
     document.getElementById('refreshGPS').addEventListener('click', refreshGPSData);
    document.addEventListener('DOMContentLoaded', fetchOriginWeather);
    document.addEventListener('DOMContentLoaded', initMgmtMap);
     document.addEventListener('DOMContentLoaded', startDateTimeUpdate);
     document.addEventListener('DOMContentLoaded', startGPSTracking);

    async function fetchWeatherForRoute(coords) {
        // Sample every ~10th point for efficiency
        const points = coords.filter((_,i) => i % Math.max(1, Math.floor(coords.length/8)) === 0);
        const weatherResults = await Promise.all(points.map(async pt => {
            try {
                const res = await fetch(`weather.php?lat=${pt[1]}&lng=${pt[0]}`);
                const data = await res.json();
                return { lat: pt[1], lng: pt[0], weather: data.current };
            } catch { return null; }
        }));
        return weatherResults.filter(Boolean);
    }

    async function showRouteWeatherOnMap(coords, weatherResults) {
        // Remove old weather markers
        if (window.routeWeatherMarkers) window.routeWeatherMarkers.forEach(m => mgmtMap.removeLayer(m));
        window.routeWeatherMarkers = [];
        weatherResults.forEach(wr => {
            if (!wr.weather) return;
            let type = 'cloudy';
            // Prefer Open-Meteo code if available
            if (wr.weather.weather && typeof wr.weather.weather.code !== 'undefined') {
                type = mapWeatherCodeToType(wr.weather.weather.code);
            } else if (Array.isArray(wr.weather.weather)) {
                const w0 = wr.weather.weather[0] || {};
                type = mapTextToType(w0.main || w0.description || '');
            }
            const html = `<div style="width:32px;height:32px;display:flex;align-items:center;justify-content:center;background:#fff;border-radius:8px;border:1px solid #e5e7eb;box-shadow:0 2px 8px rgba(0,0,0,0.1)">
                <img src="assets/img/${type==='sunny'?'sunny.png':(type==='rainy'?'rainy.png':'cloudy.png')}" alt="${type}" width="24" height="24" />
            </div>`;
            const icon = L.divIcon({ html, className: 'route-weather-icon', iconSize: [32,32], iconAnchor: [16,16] });
            const marker = L.marker([wr.lat, wr.lng], { icon }).addTo(mgmtMap)
                .bindPopup(`<strong>${Math.round(wr.weather.temp)}°C</strong><br>${wr.weather.weather && wr.weather.weather.description ? wr.weather.weather.description : ''}`);
            window.routeWeatherMarkers.push(marker);
        });
        // Show summary
        const summaryDiv = document.getElementById('routeWeatherSummary');
        const temps = weatherResults.map(wr => wr.weather && wr.weather.temp).filter(Boolean);
        const minT = Math.min(...temps), maxT = Math.max(...temps);
        summaryDiv.innerHTML = `<span class="weather">Route weather: ${Math.round(minT)}°C to ${Math.round(maxT)}°C</span>`;
    }

    async function focusDelivery(id){
        const d = deliveries.find(x => x.id === id);
        if (!d || !d.lat || !d.lng) {
            Swal && Swal.fire && Swal.fire({ icon:'info', title:'No location', text:'This delivery has no pinned location yet.'});
            return;
        }
        // fit bounds and draw route
        mgmtLayer.clearLayers();
        L.marker([d.lat, d.lng], { title: d.name }).addTo(mgmtLayer).bindPopup(`<strong>${d.name}</strong><br>${d.address}`).openPopup();
        mgmtMap.fitBounds(L.latLngBounds([ [STORE.lat, STORE.lng], [d.lat, d.lng] ]), { padding:[30,30] });
        try {
            const res = await fetch(`route.php?from=${STORE.lat},${STORE.lng}&to=${d.lat},${d.lng}`);
            const data = await res.json();
            if (data && data.geometry) {
                const geoLayer = L.geoJSON(data.geometry).addTo(mgmtLayer);
                const km = (data.distance_m/1000).toFixed(2);
                const mins = Math.round(data.duration_s/60);
                document.getElementById('mgmtSummary').innerHTML = `<span class="dist">Distance ${km} km</span> • <span class="eta">ETA ${mins} min</span>`;
                // Weather along route
                const coords = data.geometry.coordinates;
                const weatherResults = await fetchWeatherForRoute(coords);
                showRouteWeatherOnMap(coords, weatherResults);
            } else {
                document.getElementById('mgmtSummary').textContent = 'No route found.';
                document.getElementById('routeWeatherSummary').textContent = '';
            }
        } catch(e){
            document.getElementById('mgmtSummary').textContent = 'Routing failed.';
            document.getElementById('routeWeatherSummary').textContent = '';
        }
    }
    </script>
    <script src="assets/js/main.js"></script>
</body>
</html>

