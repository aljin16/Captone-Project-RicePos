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
    /* ============================================
       MODERN MINIMALIST DELIVERY MANAGEMENT
       Clean, professional, mobile-first design
       ============================================ */
    
    /* Base Styles */
    *, *::before, *::after { box-sizing: border-box; }
    html, body { height: 100%; }
    body { display: block; min-height: 100vh; margin: 0; background: #f8fafc; overflow-x: hidden; }
    .main-content { background: #f8fafc; min-height: 100vh; overflow-x: hidden; }
    
    /* Typography */
    .section-title {
        font-size: 1.25rem;
        font-weight: 700;
        color: #1e293b;
        margin-bottom: 0.75rem;
        letter-spacing: -0.025em;
    }
    
    .muted-text {
        color: #64748b;
        font-size: 0.9rem;
    }
    
    .weather { color: #ea580c; font-weight: 600; }
    
    /* ============================================
       UNIFIED DASHBOARD (3-in-1: Map + Weather + GPS)
       ============================================ */
    
    .unified-dashboard {
        display: grid;
        grid-template-columns: 70% 30%;
        gap: 1rem;
        margin-bottom: 1.5rem;
        background: white;
        border-radius: 20px;
        padding: 1rem;
        box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        border: 1px solid #e2e8f0;
    }
    
    /* Map Container (Left 70%) */
    .map-container {
        display: flex;
        flex-direction: column;
        background: white;
        border-radius: 16px;
        overflow: hidden;
    }
    
    .map-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 0.75rem 1rem;
        background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
        border-bottom: 1px solid #e2e8f0;
    }
    
    .map-header .section-title {
        margin: 0;
        font-size: 1.1rem;
        display: flex;
        align-items: center;
        gap: 0.5rem;
        color: #1e293b;
    }
    
    .map-actions {
        display: flex;
        gap: 0.5rem;
    }
    
    .btn-icon {
        width: 36px;
        height: 36px;
        border-radius: 8px;
        border: 1px solid #e2e8f0;
        background: white;
        color: #475569;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        transition: all 0.2s ease;
        font-size: 1.1rem;
    }
    
    .btn-icon:hover {
        background: #22c55e;
        color: white;
        border-color: #22c55e;
        transform: translateY(-1px);
    }
    
    .unified-map {
        width: 100%;
        height: 500px;
        background: #f8fafc;
        border-radius: 0;
    }
    
    /* Sidebar Panel (Right 30%) */
    .sidebar-panel {
        display: flex;
        flex-direction: column;
        gap: 1rem;
        overflow-y: auto;
        max-height: 650px;
    }
    
    /* Mini Cards */
    .mini-card {
        background: white;
        border-radius: 16px;
        padding: 1rem;
        box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        border: 1px solid #e2e8f0;
        transition: all 0.3s ease;
    }
    
    .mini-card:hover {
        box-shadow: 0 4px 16px rgba(0,0,0,0.1);
        transform: translateY(-2px);
    }
    
    .mini-card-header {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        margin-bottom: 1rem;
        padding-bottom: 0.75rem;
        border-bottom: 1px solid #f1f5f9;
    }
    
    .mini-icon {
        width: 40px;
        height: 40px;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.25rem;
        flex-shrink: 0;
    }
    
    .weather-icon {
        background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
        color: white;
    }
    
    .gps-icon {
        background: linear-gradient(135deg, #22c55e 0%, #16a34a 100%);
        color: white;
    }
    
    .mini-title {
        flex: 1;
    }
    
    .mini-title h3 {
        margin: 0;
        font-size: 0.95rem;
        font-weight: 700;
        color: #1e293b;
    }
    
    .mini-subtitle {
        margin: 0.25rem 0 0;
        font-size: 0.75rem;
        color: #64748b;
    }
    
    .btn-icon-sm {
        width: 28px;
        height: 28px;
        border-radius: 6px;
        border: 1px solid #e2e8f0;
        background: #f8fafc;
        color: #475569;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        transition: all 0.2s ease;
        font-size: 1rem;
        flex-shrink: 0;
    }
    
    .btn-icon-sm:hover {
        background: #3b82f6;
        color: white;
        border-color: #3b82f6;
    }
    
    /* Current Weather Display */
    .current-weather {
        text-align: center;
        padding: 0.75rem 0;
        margin-bottom: 0.75rem;
    }
    
    .current-temp {
        font-size: 2.5rem;
        font-weight: 700;
        color: #3b82f6;
        line-height: 1;
    }
    
    .current-condition {
        font-size: 0.85rem;
        color: #64748b;
        margin-top: 0.25rem;
        text-transform: capitalize;
    }
    
    /* Compact Weather Forecast */
    .weather-forecast-compact {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 0.5rem;
    }
    
    .weather-day-compact {
        background: #f8fafc;
        border-radius: 8px;
        padding: 0.5rem 0.25rem;
        text-align: center;
        transition: all 0.2s ease;
        border: 1px solid #e2e8f0;
    }
    
    .weather-day-compact:hover {
        background: white;
        transform: translateY(-2px);
        box-shadow: 0 2px 8px rgba(59, 130, 246, 0.15);
    }
    
    .weather-day-label {
        font-size: 0.7rem;
        font-weight: 700;
        color: #64748b;
        text-transform: uppercase;
        margin-bottom: 0.25rem;
    }
    
    .weather-icon-sm {
        width: 32px;
        height: 32px;
        margin: 0.25rem auto;
    }
    
    .weather-temp-sm {
        font-size: 0.8rem;
        font-weight: 700;
        color: #1e293b;
        margin-top: 0.25rem;
    }
    
    /* Driver Compact Card */
    .driver-compact {
        display: flex;
        gap: 0.75rem;
        padding: 0.75rem;
        background: #f8fafc;
        border-radius: 10px;
        margin-bottom: 0.75rem;
        border: 1px solid #e2e8f0;
    }
    
    .driver-avatar-sm {
        width: 48px;
        height: 48px;
        border-radius: 10px;
        background: #dcfce7;
        border: 2px solid #22c55e;
        overflow: hidden;
        flex-shrink: 0;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    
    .driver-avatar-sm img {
        width: 100%;
        height: 100%;
        object-fit: contain;
    }
    
    .driver-info-sm {
        flex: 1;
        min-width: 0;
    }
    
    .driver-name-sm {
        font-size: 0.875rem;
        font-weight: 700;
        color: #1e293b;
        margin-bottom: 0.25rem;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    
    .driver-plate-sm {
        font-size: 0.75rem;
        color: #059669;
        background: #dcfce7;
        padding: 0.15rem 0.5rem;
        border-radius: 4px;
        display: inline-block;
        font-weight: 600;
        margin-bottom: 0.25rem;
    }
    
    .driver-location-sm {
        font-size: 0.75rem;
        color: #64748b;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    
    /* Quick Actions */
    .quick-actions {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 0.5rem;
    }
    
    .action-btn {
        padding: 0.5rem;
        border-radius: 8px;
        border: 1px solid #e2e8f0;
        background: white;
        color: #475569;
        cursor: pointer;
        transition: all 0.2s ease;
        font-size: 1.1rem;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    
    .action-btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0,0,0,0.1);
    }
    
    .track-btn:hover {
        background: #22c55e;
        color: white;
        border-color: #22c55e;
    }
    
    .call-btn:hover {
        background: #3b82f6;
        color: white;
        border-color: #3b82f6;
    }
    
    .maps-btn:hover {
        background: #f59e0b;
        color: white;
        border-color: #f59e0b;
    }
    
    .setup-btn:hover {
        background: #8b5cf6;
        color: white;
        border-color: #8b5cf6;
    }
    
    .btn.small {
        padding: 0.5rem 1rem;
        font-size: 0.875rem;
        background: #3b82f6;
        border: 1px solid #3b82f6;
        color: #fff;
        border-radius: 8px;
        cursor: pointer;
        transition: all 0.2s ease;
        font-weight: 600;
    }
    
    .btn.small:hover {
        background: #2563eb;
        border-color: #2563eb;
    }
    
    /* Filters Section */
    .filters {
        display: flex;
        gap: 0.75rem;
        align-items: center;
        flex-wrap: wrap;
        margin-bottom: 1.5rem;
        padding: 1rem;
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
    }
    
    .filter-group {
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }
    
    .filters label {
        font-weight: 600;
        color: #475569;
        font-size: 0.9rem;
        white-space: nowrap;
    }
    
    .filters label[for="statusFilter"] {
        position: static;
        width: auto;
        height: auto;
        clip: auto;
        overflow: visible;
    }
    
    .filters select,
    .filters input[type="text"] {
        padding: 0.5rem 0.75rem;
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        background: #fff;
        font-size: 0.9rem;
        color: #1e293b;
        transition: all 0.2s ease;
        height: 42px;
    }
    
    .filters select {
        min-width: 180px;
    }
    
    .filters input[type="text"] {
        flex: 1;
        min-width: 250px;
    }
    
    .filters select:focus,
    .filters input:focus {
        outline: none;
        border-color: #3b82f6;
        box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
    }
    
    .filters .btn {
        padding: 0.5rem 1.25rem;
        background: #3b82f6;
        color: #fff;
        border: 1px solid #3b82f6;
        border-radius: 8px;
        font-weight: 600;
        cursor: pointer;
        height: 42px;
        white-space: nowrap;
        transition: all 0.2s ease;
        font-size: 0.9rem;
    }
    
    .filters .btn:hover {
        background: #2563eb;
        border-color: #2563eb;
        transform: translateY(-1px);
    }
    
    .route-summary {
        margin-top: 0.75rem;
        padding: 0.75rem;
        background: #f8fafc;
        border-radius: 8px;
        color: #1e293b;
        font-size: 0.9rem;
        font-weight: 600;
        text-align: center;
    }
    
    .route-summary .dist {
        color: #3b82f6;
    }
    
    .route-summary .eta {
        color: #059669;
    }
    
    /* Table Styles */
    .table-card {
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 16px;
        overflow: hidden;
        box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        margin-bottom: 1.5rem;
    }
    
    .table-scroll {
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }
    
    .user-table {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0;
        min-width: 920px;
    }
    
    .user-table thead th {
        position: sticky;
        top: 0;
        background: #f8fafc;
        color: #475569;
        font-weight: 700;
        font-size: 0.875rem;
        letter-spacing: 0.025em;
        text-align: left;
        padding: 1rem;
        border-bottom: 2px solid #e2e8f0;
        text-transform: uppercase;
    }
    
    .user-table tbody td {
        padding: 1rem;
        border-bottom: 1px solid #f1f5f9;
        color: #1e293b;
        background: #fff;
        vertical-align: middle;
        font-size: 0.9rem;
    }
    
    .user-table tbody tr:hover td {
        background: #f8fafc;
    }
    
    .user-table tbody tr:last-child td {
        border-bottom: none;
    }
    
    .user-table tbody td:nth-child(1) {
        color: #64748b;
        font-variant-numeric: tabular-nums;
        font-weight: 600;
    }
    
    .user-table tbody td:nth-child(8) {
        text-align: right;
        font-variant-numeric: tabular-nums;
        font-weight: 600;
    }
    
    .user-table tbody td form {
        display: flex;
        gap: 0.5rem;
        align-items: center;
        flex-wrap: wrap;
    }
    
    .user-table tbody td select,
    .user-table tbody td button {
        padding: 0.4rem 0.75rem;
        border: 1px solid #e2e8f0;
        border-radius: 6px;
        font-size: 0.875rem;
    }
    
    .user-table tbody td button {
        background: #3b82f6;
        color: #fff;
        border-color: #3b82f6;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.2s ease;
    }
    
    .user-table tbody td button:hover {
        background: #2563eb;
    }
    
    /* Badges */
    .badge {
        display: inline-block;
        padding: 0.35rem 0.75rem;
        border-radius: 6px;
        font-size: 0.8rem;
        font-weight: 600;
        border: 1px solid;
        text-transform: capitalize;
    }
    
    .b-pending {
        background: #fef3c7;
        color: #92400e;
        border-color: #fde68a;
    }
    
    .b-transit {
        background: #dbeafe;
        color: #1e40af;
        border-color: #93c5fd;
    }
    
    .b-delivered {
        background: #dcfce7;
        color: #166534;
        border-color: #86efac;
    }
    
    .b-cancelled {
        background: #f1f5f9;
        color: #475569;
        border-color: #cbd5e1;
    }
    
    /* Pagination */
    .pagination {
        display: flex;
        gap: 0.5rem;
        justify-content: center;
        margin-top: 1.5rem;
    }
    
    .pagination a {
        padding: 0.5rem 0.875rem;
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        text-decoration: none;
        color: #475569;
        font-weight: 600;
        transition: all 0.2s ease;
    }
    
    .pagination a:hover {
        background: #f8fafc;
        border-color: #cbd5e1;
    }
    
    .pagination .active {
        background: #3b82f6;
        color: #fff;
        border-color: #3b82f6;
    }
    
    /* Mobile Responsive */
    @media (max-width: 1200px) {
        .unified-dashboard {
            grid-template-columns: 65% 35%;
        }
        
        .weather-forecast-compact {
            grid-template-columns: repeat(2, 1fr);
        }
    }
    
    @media (max-width: 1024px) {
        .unified-dashboard {
            grid-template-columns: 1fr;
            gap: 1rem;
        }
        
        .sidebar-panel {
            max-height: none;
            flex-direction: row;
            gap: 1rem;
        }
        
        .mini-card {
            flex: 1;
        }
        
        .weather-forecast-compact {
            grid-template-columns: repeat(3, 1fr);
        }
        
        .unified-map {
            height: 400px;
        }
        
        .filters {
            flex-direction: column;
            align-items: stretch;
        }
        
        .filter-group {
            width: 100%;
            flex-direction: column;
            align-items: stretch;
        }
        
        .filters select,
        .filters input[type="text"],
        .filters .btn {
            width: 100%;
            min-width: 100%;
        }
    }
    
    @media (max-width: 768px) {
        .section-title {
            font-size: 1rem;
        }
        
        .sidebar-panel {
            flex-direction: column;
        }
        
        .mini-card {
            padding: 0.875rem;
        }
        
        .current-temp {
            font-size: 2rem;
        }
        
        .weather-forecast-compact {
            grid-template-columns: repeat(3, 1fr);
        }
        
        .quick-actions {
            grid-template-columns: repeat(2, 1fr);
        }
        
        .unified-map {
            height: 350px;
        }
        
        .user-table {
            font-size: 0.85rem;
        }
        
        .user-table thead th,
        .user-table tbody td {
            padding: 0.75rem 0.5rem;
        }
    }
    
    @media (max-width: 640px) {
        .unified-dashboard {
            padding: 0.75rem;
        }
        
        .map-header {
            padding: 0.5rem 0.75rem;
        }
        
        .map-header .section-title {
            font-size: 0.95rem;
        }
        
        .unified-map {
            height: 300px;
        }
        
        .weather-forecast-compact {
            grid-template-columns: repeat(2, 1fr);
        }
        
        .current-temp {
            font-size: 1.75rem;
        }
        
        .table-scroll::after {
            content: '← Scroll →';
            display: block;
            text-align: center;
            padding: 0.75rem;
            color: #94a3af;
            font-size: 0.85rem;
            background: #f8fafc;
        }
    }
    
    @media (max-width: 480px) {
        .weather-forecast-compact {
            grid-template-columns: 1fr;
        }
        
        .quick-actions {
            grid-template-columns: repeat(4, 1fr);
        }
        
        .action-btn {
            font-size: 1rem;
            padding: 0.4rem;
        }
        
        .unified-map {
            height: 250px;
        }
    }
    </style>
</head>
<body>
    <?php $activePage = 'delivery_management.php'; $pageTitle = 'Delivery Management'; include __DIR__ . '/../includes/sidebar.php'; include __DIR__ . '/../includes/header.php'; ?>
    <main class="main-content">
        
        <?php if ($message): ?>
        <div style="background: #dbeafe; border: 1px solid #93c5fd; color: #1e40af; padding: 0.75rem 1rem; border-radius: 10px; margin-bottom: 1rem; font-weight: 600;">
            <?php echo htmlspecialchars($message); ?>
        </div>
        <?php endif; ?>
        
        <!-- Unified Dashboard: Map + Weather + GPS (3-in-1 View) -->
        <div class="unified-dashboard">
            <!-- Left: Interactive Map (70%) -->
            <div class="map-container">
                <div class="map-header">
                    <h2 class="section-title">
                        <i class='bx bx-map'></i> Live Delivery Map
                    </h2>
                    <div class="map-actions">
                        <button class="btn-icon" onclick="refreshGPSData()" title="Refresh GPS">
                            <i class='bx bx-refresh'></i>
                        </button>
                    </div>
                </div>
                <div id="mgmtMap" class="unified-map"></div>
                <div id="mgmtSummary" class="route-summary"></div>
                <div id="routeWeatherSummary" class="muted" style="margin-top:0.5rem;"></div>
            </div>

            <!-- Right: Weather + GPS Stacked (30%) -->
            <div class="sidebar-panel">
                <!-- Weather Panel (Compact) -->
                <div class="mini-card weather-mini">
                    <div class="mini-card-header">
                        <div class="mini-icon weather-icon">
                            <i class='bx bxs-sun'></i>
                        </div>
                        <div class="mini-title">
                            <h3>Weather</h3>
                            <p id="realtimeDateTime" class="mini-subtitle"></p>
                        </div>
                        <button class="btn-icon-sm" id="refreshOriginWeather" title="Refresh Weather">
                            <i class='bx bx-refresh'></i>
                        </button>
                    </div>
                    <div id="currentWeather" class="current-weather">
                        <div class="current-temp">--°</div>
                        <div class="current-condition">Loading...</div>
                    </div>
                    <div id="originWeatherDaily" class="weather-forecast-compact"></div>
                </div>

                <!-- GPS Info Panel (Compact) -->
                <div class="mini-card gps-mini">
                    <div class="mini-card-header">
                        <div class="mini-icon gps-icon">
                            <i class='bx bxs-location-plus'></i>
                        </div>
                        <div class="mini-title">
                            <h3>GPS Tracker</h3>
                            <p id="gpsStatus" class="mini-subtitle">⏳ Waiting...</p>
                        </div>
                        <button class="btn-icon-sm" id="refreshGPS" title="Refresh GPS">
                            <i class='bx bx-refresh'></i>
                        </button>
                    </div>
                    
                    <div class="driver-compact">
                        <div class="driver-avatar-sm">
                            <img src="assets/img/dce3d07b96a346beabc6721b41ba045c-removebg-preview.png" alt="Edgar">
                        </div>
                        <div class="driver-info-sm">
                            <div class="driver-name-sm">Edgar - Toyota Hilux</div>
                            <div class="driver-plate-sm">ABC-123</div>
                            <div id="gpsLocationText" class="driver-location-sm">📍 Resolving...</div>
                        </div>
                    </div>

                    <div class="quick-actions">
                        <button class="action-btn track-btn" onclick="trackRider('edgar')" title="Track">
                            <i class='bx bx-navigation'></i>
                        </button>
                        <button class="action-btn call-btn" onclick="contactRider('edgar')" title="Call">
                            <i class='bx bx-phone'></i>
                        </button>
                        <button class="action-btn maps-btn" onclick="openGoogleMaps()" title="Google Maps">
                            <i class='bx bx-map'></i>
                        </button>
                        <button class="action-btn setup-btn" onclick="showGPSInstructions()" title="Setup">
                            <i class='bx bx-info-circle'></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <!-- Delivery Filters -->
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
                        <form method="post" style="display:flex; gap:0.5rem; align-items:center; flex-wrap: wrap;">
                            <input type="hidden" name="delivery_id" value="<?php echo (int)$d['id']; ?>">
                            <select name="status" style="padding:0.4rem 0.75rem; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 0.875rem; background: #fff;">
                                <option value="pending" <?php echo $d['status']==='pending'?'selected':''; ?>>Pending</option>
                                <option value="out_for_delivery" <?php echo $d['status']==='out_for_delivery'?'selected':''; ?>>In Transit</option>
                                <option value="delivered" <?php echo $d['status']==='delivered'?'selected':''; ?>>Delivered</option>
                                <option value="cancelled" <?php echo $d['status']==='cancelled'?'selected':''; ?>>Cancelled</option>
                            </select>
                            <button type="submit" name="update_delivery_status" style="padding:0.4rem 0.75rem; background:#3b82f6; color:#fff; border:1px solid #3b82f6; border-radius:6px; font-size:0.875rem; font-weight:600; cursor:pointer;">
                                <i class='bx bx-check'></i> Update
                            </button>
                        </form>
                        <button style="margin-top:0.5rem; padding:0.4rem 0.75rem; background:#fff; color:#475569; border:1px solid #e2e8f0; border-radius:6px; font-size:0.875rem; font-weight:600; cursor:pointer; display:inline-flex; align-items:center; gap:0.25rem;" onclick="focusDelivery(<?php echo (int)$d['id']; ?>)">
                            <i class='bx bx-map'></i> View Route
                        </button>
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
         return `<img src="assets/img/${file}" alt="${type}" class="weather-forecast-icon" style="display:block; margin: 0 auto;" />`;
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
        const currentWeatherDiv = document.getElementById('currentWeather');
        
        panel.innerHTML = '<span class="muted" style="grid-column: 1/-1; text-align: center;">Loading...</span>';
        try {
            // Use Open-Meteo for current + forecast
            const url = `https://api.open-meteo.com/v1/forecast?latitude=${STORE.lat}&longitude=${STORE.lng}&current=temperature_2m,weathercode&daily=temperature_2m_max,temperature_2m_min,precipitation_probability_max,weathercode&timezone=Asia/Manila`;
            const om = await fetch(url, { headers: { 'Accept': 'application/json' }}).then(r=>r.json());
            
            // Update current weather
            if (om && om.current) {
                const currentTemp = Math.round(om.current.temperature_2m || 0);
                const currentCode = om.current.weathercode;
                const currentType = mapWeatherCodeToType(currentCode);
                const conditions = {
                    'sunny': 'Clear Sky',
                    'partly-cloudy-day': 'Partly Cloudy',
                    'cloudy': 'Cloudy',
                    'rainy': 'Rainy',
                    'snowy': 'Snow',
                    'thunderstorms': 'Thunderstorms'
                };
                currentWeatherDiv.innerHTML = `
                    <div class="current-temp">${currentTemp}°C</div>
                    <div class="current-condition">${conditions[currentType] || 'Unknown'}</div>
                `;
            }
            
            // Update forecast (show 3 days)
            const times = (om && om.daily && om.daily.time) ? om.daily.time : [];
            const tmaxs = (om && om.daily && om.daily.temperature_2m_max) ? om.daily.temperature_2m_max : [];
            const tmins = (om && om.daily && om.daily.temperature_2m_min) ? om.daily.temperature_2m_min : [];
            const codes = (om && om.daily && om.daily.weathercode) ? om.daily.weathercode : [];
            
            const cards = times.slice(0,3).map((t,i)=>{
                const day = new Date(t+'T00:00:00').toLocaleDateString(undefined,{weekday:'short'});
                const tmax = Math.round(tmaxs[i]||0);
                const tmin = Math.round(tmins[i]||0);
                const wcode = codes[i];
                const type = mapWeatherCodeToType(wcode);
                const iconMap = {
                    'sunny': 'sunny.png',
                    'partly-cloudy-day': 'cloudy.png',
                    'cloudy': 'cloudy.png',
                    'rainy': 'rainy.png',
                    'snowy': 'cloudy.png',
                    'thunderstorms': 'rainy.png'
                };
                const iconFile = iconMap[type] || 'cloudy.png';
                return `<div class="weather-day-compact">
                    <div class="weather-day-label">${day}</div>
                    <img src="assets/img/${iconFile}" alt="${type}" class="weather-icon-sm" />
                    <div class="weather-temp-sm">${tmax}°/${tmin}°</div>
                </div>`;
            }).join('');
            panel.innerHTML = cards || '<span class="muted" style="grid-column: 1/-1;">No forecast</span>';
        } catch(e) {
            panel.innerHTML = '<span class="muted" style="grid-column: 1/-1;">Failed to load</span>';
            currentWeatherDiv.innerHTML = `
                <div class="current-temp">--°</div>
                <div class="current-condition">Unavailable</div>
            `;
        }
    }
    // Real-time date and time function
    function updateDateTime() {
        const now = new Date();
        const timeString = now.toLocaleTimeString('en-US', {
            hour: '2-digit',
            minute: '2-digit',
            timeZone: 'Asia/Manila'
        });
        const dateElement = document.getElementById('realtimeDateTime');
        if (dateElement) {
            dateElement.textContent = `Updated: ${timeString}`;
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

