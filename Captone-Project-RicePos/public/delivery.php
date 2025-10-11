<?php
session_start();
require_once __DIR__ . '/../classes/User.php';
require_once __DIR__ . '/../classes/Product.php';
require_once __DIR__ . '/../classes/Sale.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
// Resolve store origin coordinates server-side to ensure map uses precise address
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
// Block admin role from accessing Delivery
if ($user->isAdmin()) { header('Location: dashboard.php'); exit; }

$productObj = new Product();
$saleObj = new Sale();
$pdo = Database::getInstance()->getConnection();

// Handle delivery order (same stock sync as POS)
$saleSuccess = false; $saleError = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['process_delivery'])) {
    ensure_receipt_columns();
    $customer_name = trim($_POST['customer_name'] ?? '');
    $customer_phone = trim($_POST['customer_phone'] ?? '');
    $customer_address = trim($_POST['customer_address'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    $customer_email = trim($_POST['customer_email'] ?? '');

    if (!$customer_name || !$customer_address) {
        $saleError = 'Customer name and address are required.';
    } elseif ($customer_email !== '' && !filter_var($customer_email, FILTER_VALIDATE_EMAIL)) {
        $saleError = 'Please provide a valid email address or leave it empty.';
    } else {
        $items = []; $total = 0;
        foreach ($_POST['product_id'] as $i => $pid) {
            $pid = (int)$pid;
            // KG removed: force to 0, sacks only (whole numbers)
            $qty_kg = 0;
            $qty_sack = isset($_POST['quantity_sack'][$i]) ? (int)$_POST['quantity_sack'][$i] : 0;
            if ($qty_sack <= 0) { continue; }
            $product = $productObj->getById($pid); if (!$product) continue;
            if ($qty_sack > (int)$product['stock_sack']) { $saleError = 'Requested quantity exceeds stock for '.htmlspecialchars($product['name']); break; }
            $price = round($qty_sack * (float)($product['price_per_sack'] ?? 0));
            $items[] = ['product_id'=>$pid, 'quantity_kg'=>$qty_kg, 'quantity_sack'=>$qty_sack, 'price'=>$price];
            $total += $price;
        }
        // Strip commas from payment before converting to integer
        $payment = intval(str_replace(',', '', $_POST['payment'] ?? '0'));
        $change = $payment - $total;
        if (!$saleError && $total > 0 && $payment >= $total) {
            $pdo->beginTransaction();
            try {
                // Pass buyer info to sales table for consistency
                $buyerName = $customer_name !== '' ? $customer_name : null;
                $buyerEmail = $customer_email !== '' ? $customer_email : null;
                $txn = $saleObj->create($_SESSION['user_id'], $total, $payment, max($change,0), $items, $buyerName, $buyerEmail);
                // Link to delivery_orders
                $saleRow = $pdo->prepare('SELECT id FROM sales WHERE transaction_id = ?');
                $saleRow->execute([$txn]);
                $sid = $saleRow->fetchColumn();
                $lat = isset($_POST['customer_lat']) && $_POST['customer_lat'] !== '' ? (float)$_POST['customer_lat'] : null;
                $lng = isset($_POST['customer_lng']) && $_POST['customer_lng'] !== '' ? (float)$_POST['customer_lng'] : null;
                $routeJson = isset($_POST['route_json']) ? $_POST['route_json'] : null;
                $ins = $pdo->prepare('INSERT INTO delivery_orders (sale_id, customer_name, customer_phone, customer_address, notes, customer_lat, customer_lng, route_json, customer_email) VALUES (?,?,?,?,?,?,?,?,?)');
                $ins->execute([$sid, $customer_name, $customer_phone, $customer_address, $notes, $lat, $lng, $routeJson, $customer_email !== '' ? $customer_email : null]);
                if ($pdo->inTransaction()) { $pdo->commit(); }
                $saleSuccess = $txn; $_POST = [];
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                $saleError = 'Failed to save delivery order.';
            }
            // After DB transaction is closed, optionally send e-receipt. Do not affect transaction if email fails.
            if ($saleSuccess && $customer_email !== '' && filter_var($customer_email, FILTER_VALIDATE_EMAIL)) {
                try {
                    $stmt = $pdo->prepare('SELECT id, transaction_id, datetime, total_amount, payment, change_due, buyer_name FROM sales WHERE transaction_id = ?');
                    $stmt->execute([$saleSuccess]);
                    $sale = $stmt->fetch();
                    if ($sale) {
                        $itemsStmt = $pdo->prepare('SELECT si.quantity_kg, si.quantity_sack, si.price, p.name FROM sale_items si JOIN products p ON p.id = si.product_id WHERE si.sale_id = ?');
                        $itemsStmt->execute([$sale['id']]);
                        $itemsRows = $itemsStmt->fetchAll();
                        $html = build_receipt_html($sale, $itemsRows, $customer_name, true);
                        $sent = send_receipt_email($customer_email, 'Your RicePOS Receipt ' . $saleSuccess, $html, $customer_name);
                        if (!isset($_SESSION)) { session_start(); }
                        $_SESSION['email_notice'] = $sent
                            ? ('E-receipt sent to ' . $customer_email)
                            : ('E-receipt could not be sent to ' . $customer_email);
                    }
                } catch (Throwable $e) { /* swallow email errors */ }
            }
        } else if (!$saleError) {
            $saleError = $total <= 0 ? 'No items selected.' : 'Payment is less than total.';
        }
    }
}
$products = $productObj->getAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
    <title>Delivery - RicePOS</title>
    <?php $cssVer = @filemtime(__DIR__ . '/assets/css/style.css') ?: time(); ?>
    <link rel="stylesheet" href="assets/css/style.css?v=<?php echo htmlspecialchars((string)$cssVer, ENT_QUOTES); ?>">
    <link rel="stylesheet" href="assets/css/mobile-delivery.css">
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin=""/>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
    <style>
    :root{ --brand:#2d6cdf; --brand-600:#1e4fa3; --ink:#111827; --muted:#6b7280; --card:#ffffff; --bg:#f7f9fc; --line:#e5e7eb; }
    *, *::before, *::after { box-sizing: border-box; }
    html, body { height: 100%; }
    body { display: block; min-height: 100vh; margin: 0; background: var(--bg); color: var(--ink); overflow-x: hidden; }
    .main-content { padding: 2.5rem 2.5rem 2.5rem 2rem; background: var(--bg); min-height: 100vh; overflow-x: hidden; }
    .pos-layout { display: grid; grid-template-columns: 1fr 520px; gap: 1rem; }
    .product-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 1rem; }
    .product-card { background: var(--card); border: 1px solid var(--line); border-radius: 12px; padding: 0.8rem; display: flex; flex-direction: column; align-items: center; box-shadow: 0 8px 24px rgba(17,24,39,0.06); }
    .product-card .prod-img { width: 120px; height: 120px; object-fit: cover; border-radius: 10px; background: #f8fafc; box-shadow: 0 2px 8px rgba(0,0,0,0.06); }
    .product-card .prod-name { margin-top: 0.5rem; font-weight: 600; text-align: center; }
    .product-card .prod-meta { font-size: 0.9rem; color: #6b7280; }
    .product-card .prod-price { font-size: 0.95rem; margin-top: 0.2rem; }
    .product-card .prod-stock { font-size: 0.85rem; color: #6b7280; margin-top: 0.1rem; }
    /* Readable primary Add button for Delivery */
    .product-card .btn-add {
        background: #3b82f6; color: #ffffff;
        border: 1px solid #2563eb;
        padding: 0.5rem 0.85rem;
        margin-top: 0.5rem;
        display: inline-flex; align-items: center; gap: 0.4rem;
        font-size: 0.98rem; font-weight: 700; letter-spacing: 0.2px;
        border-radius: 10px; cursor: pointer;
        box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        transition: background .15s ease, border-color .15s ease, transform .06s ease;
    }
    .product-card .btn-add:hover { background: #2563eb; border-color: #1d4ed8; }
    .product-card .btn-add:active { transform: translateY(1px); }
    .product-card .btn-add:focus-visible { outline: 2px solid #bfdbfe; outline-offset: 2px; }
    .product-card .btn-add i { font-size: 1.1rem; }
    .cart-panel { background: var(--card); border: 1px solid var(--line); border-radius: 12px; padding: 0.8rem; height: fit-content; position: sticky; top: 1.2rem; box-shadow: 0 8px 24px rgba(17,24,39,0.06); }
    .cart-header { display:flex; align-items:center; justify-content: space-between; gap: 0.5rem; font-weight: 700; margin-bottom: 0.6rem; }
    .cart-header .muted { font-weight: 500; color: #6b7280; }
    .cart-items { display: flex; flex-direction: column; gap: 0.6rem; max-height: 40vh; overflow: auto; border-bottom: 1px dashed #e5e7eb; padding-bottom: 0.5rem; }
    .cart-row { display: grid; grid-template-columns: 44px 1fr 180px 110px 36px; gap: 0.6rem; align-items: center; }
    .cart-thumb { width: 44px; height: 44px; border-radius: 8px; object-fit: cover; background: #f8fafc; box-shadow: 0 1px 4px rgba(0,0,0,0.06); }
    .cart-name { font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .cart-qty { text-align: center; }
    .cart-qty label { display:block; font-size: 0.72rem; color: #6b7280; margin-bottom: 2px; }
    .cart-qty input { width: 100%; padding: 0.45rem; height: 38px; border:1px solid #dbeafe; border-radius: 0; text-align: center; font-weight: 600; }
    .cart-qty-input { width: 70px !important; height: 48px !important; border: 2px solid #cbd5e1 !important; border-radius: 0 !important; text-align: center; font-weight: 700; font-size: 1.1rem; padding: 0.5rem 0.3rem !important; }
    .cart-sub { text-align: right; font-weight: 700; }
    .totals { display: flex; flex-direction: column; gap: 0.5rem; margin-top: 0.6rem; }
    /* Hide spinners for number input (payment) */
    input[type=number]::-webkit-outer-spin-button,
    input[type=number]::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
    input[type=number] { -moz-appearance: textfield; }
    .tot-row { display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem; align-items: center; }
    .tot-row input { width: 100%; padding: 0.6rem; height: 40px; border:1px solid #dbeafe; border-radius:0; background:#f8fafc; text-align: center; font-weight: 700; }
    .cart-footer { margin-top: 0.75rem; border-top: 1px dashed #e5e7eb; padding-top: 0.75rem; display:flex; flex-direction: column; gap: 0.5rem; }
    .cta-btn { width: 100%; padding: 0.75rem 1rem; font-weight: 700; font-size: 1rem; border-radius: 10px; }
    .process-cta { background: linear-gradient(135deg,var(--brand),var(--brand-600)); color: #fff; border: 1px solid var(--brand-600); transition: background 0.18s, border-color 0.18s, transform 0.08s; }
    .process-cta:hover { background: linear-gradient(135deg,var(--brand-600),#1e40af); border-color: #1e40af; color: #fff; }
    .process-cta:active { transform: translateY(1px); }
    .process-cta:focus { outline: 2px solid #bfdbfe; outline-offset: 2px; }
    /* Customer toolbar layout (match mock: 2 columns, equal spacing) */
    .form-grid { display:grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem; }
    .form-grid .full { grid-column: span 2; }
    /* Pill-shaped controls - Enhanced with shadows and weight */
    input[type="text"], input[type="tel"], input[type="email"], .form-grid input[type="number"] {
        height: 56px; 
        padding: 0 1.1rem; 
        border: 2px solid #d1d5db; 
        border-radius: 9999px; 
        background: #fff; 
        width: 100%; 
        font-size: 1rem;
        font-weight: 500;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.04), 0 1px 2px rgba(0, 0, 0, 0.06);
        transition: all 0.2s ease;
    }
    
    input[type="text"]:hover, input[type="tel"]:hover, input[type="email"]:hover, .form-grid input[type="number"]:hover {
        border-color: #9ca3af;
        box-shadow: 0 4px 8px rgba(0, 0, 0, 0.08), 0 2px 4px rgba(0, 0, 0, 0.1);
    }
    
    input[type="text"]:focus, input[type="tel"]:focus, input[type="email"]:focus, .form-grid input[type="number"]:focus {
        outline: none;
        border-color: #3b82f6;
        box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1), 0 4px 12px rgba(59, 130, 246, 0.15);
    }
    
    textarea#customerAddress { 
        padding: 0.9rem 1.1rem; 
        border: 2px solid #d1d5db; 
        border-radius: 20px; 
        background: #fff; 
        width: 100%; 
        font-size: 1rem;
        font-weight: 500;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.04), 0 1px 2px rgba(0, 0, 0, 0.06);
        transition: all 0.2s ease;
        resize: vertical;
        min-height: 90px;
    }
    
    textarea#customerAddress:hover {
        border-color: #9ca3af;
        box-shadow: 0 4px 8px rgba(0, 0, 0, 0.08), 0 2px 4px rgba(0, 0, 0, 0.1);
    }
    
    textarea#customerAddress:focus {
        outline: none;
        border-color: #3b82f6;
        box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1), 0 4px 12px rgba(59, 130, 246, 0.15);
    }
    .map-wrap { margin-top: 0.8rem; background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding: 0.6rem; }
    #deliveryMap { width: 100%; height: 360px; border-radius: 10px; }
    .geo-suggest { position: relative; }
    .geo-results { position:absolute; z-index: 50; background:#fff; border:1px solid #e5e7eb; width:100%; border-radius:8px; margin-top:4px; box-shadow:0 6px 18px rgba(0,0,0,0.06); max-height: 220px; overflow:auto; }
    .geo-results div { padding: 8px 10px; cursor: pointer; }
    .geo-results div:hover { background:#f3f4f6; }
    .route-info { margin-top: 0.5rem; color:#111827; font-size: 1.05rem; font-weight: 700; }
    .route-info .dist { color:#1d4ed8; }
    .route-info .eta { color:#059669; }
    .route-info .weather { color:#b45309; }
    .swal2-actions .swal2-confirm.swal-ok { background:#16a34a; color:#fff; border-color:#15803d; }
    .swal2-actions .swal2-confirm.swal-ok:hover { background:#15803d; }
    .swal2-actions .swal2-cancel.swal-cancel { background:#eef2ff; color:#111827; border-color:#c7d2fe; }
    .swal2-actions .swal2-cancel.swal-cancel:hover { background:#e0e7ff; }
    /* Fullscreen processing overlay (same as POS) */
    .processing-overlay {
        position: fixed; inset: 0; z-index: 9999;
        display: none; align-items: center; justify-content: center;
        backdrop-filter: blur(6px);
        background: radial-gradient(1200px 800px at 50% -10%, rgba(29,78,216,0.20), rgba(255,255,255,0.0)),
                    linear-gradient(180deg, rgba(248,250,252,0.85), rgba(248,250,252,0.95));
    }
    .processing-overlay.show { display: flex; }
    .overlay-card {
        width: 92%; max-width: 420px; padding: 20px 22px; border-radius: 14px;
        background: #ffffff; border: 1px solid #e5e7eb; box-shadow: 0 18px 60px rgba(17,24,39,0.2);
        display: flex; flex-direction: column; align-items: center; gap: 12px;
    }
    .overlay-title { font-weight: 800; color:#111827; }
    .overlay-sub { color:#6b7280; font-size: 0.95rem; text-align:center; }
    .loader {
        width: 56px; height: 56px; border-radius: 50%;
        border: 6px solid rgba(37,99,235,0.2); border-top-color: #2563eb;
        animation: spin 0.9s linear infinite; margin: 6px 0 2px 0;
    }
    @keyframes spin { to { transform: rotate(360deg); } }
    
    /* Mobile Responsive Styles */
    @media(max-width:1200px){
        .pos-layout{ grid-template-columns:1fr 480px; gap:0.8rem; }
        .product-grid{ grid-template-columns:repeat(auto-fill, minmax(160px, 1fr)); }
    }
    
    @media(max-width:1024px){
        .pos-layout{ grid-template-columns:1fr; gap:1rem; }
        .cart-panel{ position:static; width:100%; max-width:100%; order:2; }
        .product-grid{ order:1; grid-template-columns:repeat(auto-fill, minmax(150px, 1fr)); gap:0.8rem; }
        .form-grid{ margin-bottom:1rem; }
    }
    
    @media(max-width:768px){
        .main-content{ padding:1rem 0.8rem; }
        
        /* Form fields stacked */
        .form-grid{ grid-template-columns:1fr; gap:0.8rem; }
        .form-grid input, .form-grid textarea{ 
            font-size:16px; 
            padding:0.75rem 1rem; 
            font-weight: 500;
            border-width: 2px;
        } /* 16px prevents zoom on iOS */
        .form-grid textarea{ min-height:90px; }
        
        /* Product grid 2 columns */
        .product-grid{ grid-template-columns:repeat(2, 1fr); gap:0.7rem; }
        .product-card{ padding:0.7rem; }
        .product-card .prod-img{ width:100px; height:100px; }
        .product-card .prod-name{ font-size:0.9rem; margin-top:0.4rem; }
        .product-card .prod-meta{ font-size:0.85rem; }
        .product-card .prod-price{ font-size:0.9rem; }
        .product-card .prod-stock{ font-size:0.8rem; }
        .product-card .btn-add{ width:100%; padding:0.6rem; font-size:0.95rem; min-height:44px; }
        
        /* Cart panel */
        .cart-panel{ padding:1rem; }
        .cart-header{ font-size:1rem; }
        .cart-items{ max-height:300px; }
        .cart-row{ grid-template-columns:40px 1fr; gap:0.5rem; row-gap:0.4rem; }
        .cart-thumb{ width:40px; height:40px; }
        .cart-name{ grid-column:2; font-size:0.9rem; }
        .cart-qty{ grid-column:1 / -1; margin-top:0.2rem; }
        .cart-qty label{ font-size:0.7rem; }
        .cart-qty input{ font-size:0.9rem; padding:0.4rem; height:36px; }
        .cart-sub{ grid-column:1 / -1; text-align:center; font-size:0.95rem; padding:0.3rem; background:#f9fafb; border-radius:6px; }
        .cart-row button.cart-remove{ grid-column:1 / -1; width:100%; margin-top:0.3rem; min-height:40px; }
        
        .totals{ gap:0.6rem; margin-top:0.8rem; }
        .tot-row{ gap:0.4rem; }
        .tot-row span{ font-size:0.95rem; }
        .tot-row input{ font-size:0.95rem; padding:0.65rem; height:44px; }
        
        .cta-btn{ font-size:0.95rem; padding:0.85rem; min-height:52px; }
        
        /* Map */
        .map-wrap{ padding:0.5rem; margin-top:0.6rem; }
        #deliveryMap{ height:280px; }
        .route-info{ font-size:0.95rem; margin-top:0.4rem; }
        
        /* Geo suggest */
        .geo-results{ max-height:180px; font-size:0.9rem; }
        .geo-results div{ padding:7px 9px; }
    }
    
    @media(max-width:640px){
        .main-content{ padding:0.8rem 0.6rem; }
        
        /* Product grid single column for very small screens */
        .product-grid{ grid-template-columns:1fr; gap:0.6rem; }
        .product-card{ padding:0.8rem; }
        .product-card .prod-img{ width:120px; height:120px; }
        .product-card .prod-name{ font-size:0.95rem; }
        
        /* Cart more compact */
        .cart-panel{ padding:0.8rem; }
        .cart-items{ max-height:250px; }
        .cart-stepper button{ width:44px; height:44px; font-size:1.4rem; }
        .cart-stepper input{ width:60px; height:44px; font-size:1rem; }
        
        /* Map */
        #deliveryMap{ height:240px; }
        .route-info{ font-size:0.9rem; }
    }
    
    @media(max-width:480px){
        .main-content{ padding:0.6rem 0.5rem; }
        .product-card .prod-img{ width:100%; height:auto; aspect-ratio:1; max-width:150px; margin:0 auto; }
        .cart-header{ flex-direction:column; align-items:flex-start; gap:0.5rem; }
        .cart-header button{ width:100%; }
        #deliveryMap{ height:200px; }
        
        /* Overlay */
        .overlay-card{ width:95%; padding:18px; }
        .overlay-title{ font-size:1.1rem; }
        .overlay-sub{ font-size:0.9rem; }
    }
    </style>
</head>
<body>
    <?php $activePage = 'delivery.php'; $pageTitle = 'Delivery'; include __DIR__ . '/../includes/sidebar.php'; include __DIR__ . '/../includes/header.php'; ?>
    <main class="main-content">
        <div class="container">
            
            <form method="post" id="deliveryForm" autocomplete="off">
                <div class="form-grid">
                    <input type="text" name="customer_name" placeholder="Customer Name" required>
                    <input type="email" name="customer_email" placeholder="Email (E-Receipt)" aria-label="Customer Email (optional)" autocomplete="email" inputmode="email">
                    <input type="tel" name="customer_phone" placeholder="Phone No.">
                    <input type="text" name="notes" placeholder="Notes">
                    <div class="geo-suggest full">
                        <textarea name="customer_address" id="customerAddress" placeholder="Full Address (type to search)" rows="3" required></textarea>
                        <div id="addrResults" class="geo-results" style="display:none;"></div>
                    </div>
                    <input type="hidden" name="customer_lat" id="customerLat">
                    <input type="hidden" name="customer_lng" id="customerLng">
                    <input type="hidden" name="route_json" id="routeJson">
                </div>
                <div class="pos-layout">
                    <section class="product-grid">
                        <?php foreach ($products as $p): if (isset($p['is_active']) && (int)$p['is_active'] === 0) { continue; } $img = !empty($p['image']) ? $p['image'] : 'assets/img/sack-placeholder.png'; ?>
                        <div class="product-card"
                             data-id="<?php echo (int)$p['id']; ?>"
                             data-name="<?php echo htmlspecialchars($p['name'], ENT_QUOTES); ?>"
                             data-pricekg="0"
                             data-pricesack="<?php echo $p['price_per_sack'] !== null ? (float)$p['price_per_sack'] : ''; ?>"
                             data-stockkg="0"
                             data-stocksack="<?php echo (float)$p['stock_sack']; ?>"
                             data-img="<?php echo htmlspecialchars($img, ENT_QUOTES); ?>">
                            <img src="<?php echo htmlspecialchars($img, ENT_QUOTES); ?>" alt="<?php echo htmlspecialchars($p['name'], ENT_QUOTES); ?>" class="prod-img">
                            <div class="prod-name"><?php echo htmlspecialchars($p['name']); ?></div>
                            <div class="prod-meta">Sack: <?php echo htmlspecialchars($p['category']); ?></div>
                            <div class="prod-price"><?php if ($p['price_per_sack'] !== null): ?>₱<?php echo number_format((float)$p['price_per_sack'], 0); ?> / sack<?php else: ?>—<?php endif; ?></div>
                            <div class="prod-stock">Sacks: <?php echo (float)$p['stock_sack']; ?></div>
                            <button type="button" class="btn-add" data-id="<?php echo (int)$p['id']; ?>"><i class='bx bx-plus'></i> Add to Cart</button>
                        </div>
                        <?php endforeach; ?>
                    </section>
                    <aside class="cart-panel">
                        <div class="cart-header">
                            <div>Delivery Cart <span class="muted" id="cartCount">(0)</span></div>
                            <button type="button" class="btn" id="clearCart"><i class='bx bx-trash'></i> Clear</button>
                        </div>
                        <div id="cartItems" class="cart-items"></div>
                        <div id="cartInputs" style="display:none;"></div>
                            <div class="totals">
                            <div class="tot-row"><span>Total</span><input type="text" id="total" name="total" readonly value="0"></div>
                            <div class="tot-row"><span>Payment</span><input type="text" inputmode="numeric" name="payment" id="payment" placeholder="0"></div>
                            <div class="tot-row"><span style="color: #dc2626;">Change</span><input type="text" id="change" name="change" readonly value="0"></div>
                        </div>
                        <div class="cart-footer">
                            <button type="submit" id="processDeliveryBtn" class="btn cta-btn process-cta"><i class='bx bx-send'></i> Review and Process Delivery</button>
                        </div>
                    </aside>
                </div>
                <div class="map-wrap">
                    <div id="deliveryMap"></div>
                    <div id="routeInfo" class="route-info"></div>
                </div>
                <input type="hidden" name="process_delivery" value="1">
            </form>
        </div>
    </main>
    <!-- Processing overlay (same as POS) -->
    <div id="processingOverlay" class="processing-overlay" aria-hidden="true">
        <div class="overlay-card">
            <div class="loader" role="status" aria-label="Loading"></div>
            <div class="overlay-title">Processing delivery…</div>
            <div class="overlay-sub">Saving order and preparing your receipt</div>
        </div>
    </div>
    <script>
    function showProcessingOverlay() {
        const ov = document.getElementById('processingOverlay');
        if (ov) { ov.classList.add('show'); }
        const btn = document.getElementById('processDeliveryBtn');
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = "<i class='bx bx-loader-alt bx-spin'></i> Processing…";
        }
    }
    // Map init
    const STORE = { lat: <?php echo json_encode($originLat); ?>, lng: <?php echo json_encode($originLng); ?> };
    const TILE_URL = <?php echo json_encode(LEAFLET_TILE_URL); ?>;
    const TILE_ATTR = <?php echo json_encode(LEAFLET_TILE_ATTRIB); ?>;
    let map, storeMarker, customerMarker, routeLayer;
    function initMap() {
        map = L.map('deliveryMap').setView([STORE.lat, STORE.lng], 13);
        L.tileLayer(TILE_URL, { attribution: TILE_ATTR, maxZoom: 19 }).addTo(map);
        storeMarker = L.marker([STORE.lat, STORE.lng], { title: 'Store Origin' }).addTo(map);
        routeLayer = L.geoJSON(null).addTo(map);
    }
    document.addEventListener('DOMContentLoaded', initMap);

    // Geocoding suggest
    const addrInput = document.getElementById('customerAddress');
    const addrResults = document.getElementById('addrResults');
    let suggestTimer = null;
    addrInput.addEventListener('input', function(){
        const q = addrInput.value.trim();
        if (suggestTimer) clearTimeout(suggestTimer);
        if (q.length < 3) { addrResults.style.display='none'; addrResults.innerHTML=''; return; }
        suggestTimer = setTimeout(async () => {
            try {
                const res = await fetch('geocode.php?q=' + encodeURIComponent(q));
                const data = await res.json();
                addrResults.innerHTML='';
                if (data && data.results && data.results.length) {
                    data.results.forEach(r => {
                        const div = document.createElement('div');
                        div.textContent = r.display_name;
                        div.addEventListener('click', () => selectAddress(r));
                        addrResults.appendChild(div);
                    });
                    addrResults.style.display = 'block';
                } else {
                    addrResults.style.display = 'none';
                }
            } catch(e) { addrResults.style.display='none'; }
        }, 300);
    });

    function selectAddress(r){
        addrInput.value = r.display_name;
        document.getElementById('customerLat').value = r.lat;
        document.getElementById('customerLng').value = r.lon;
        addrResults.style.display='none'; addrResults.innerHTML='';
        setCustomerLocation(parseFloat(r.lat), parseFloat(r.lon));
    }

    async function setCustomerLocation(lat, lng){
        if (customerMarker) { map.removeLayer(customerMarker); }
        customerMarker = L.marker([lat, lng], { title: 'Customer' }).addTo(map);
        if (routeLayer) { routeLayer.clearLayers(); }
        map.fitBounds(L.latLngBounds([ [STORE.lat, STORE.lng], [lat, lng] ]), { padding:[30,30] });

        // Fetch route
        try {
            const res = await fetch(`route.php?from=${STORE.lat},${STORE.lng}&to=${lat},${lng}`);
            const data = await res.json();
            if (data && data.geometry) {
                routeLayer.addData(data.geometry);
                const km = (data.distance_m/1000).toFixed(2);
                const mins = Math.round((data.duration_s/60));
                document.getElementById('routeInfo').innerHTML = `<span class="dist">Distance ${km} km</span> • <span class="eta">ETA ${mins} min</span>`;
                document.getElementById('routeJson').value = JSON.stringify(data);
            } else {
                document.getElementById('routeInfo').textContent = 'No route found.';
                document.getElementById('routeJson').value = '';
            }
        } catch(e){
            document.getElementById('routeInfo').textContent = 'Routing failed.';
            document.getElementById('routeJson').value = '';
        }
    }
    const cart = new Map();
    function renderCart(){
        const cartItemsEl=document.getElementById('cartItems'); const inputsEl=document.getElementById('cartInputs');
        cartItemsEl.innerHTML=''; inputsEl.innerHTML=''; let total=0; let count=0;
        cart.forEach((item)=>{
            const subtotal = Math.round(item.qtySack*(item.priceSack||0)); total += subtotal; count++;
            const row=document.createElement('div'); row.className='cart-row';
            row.innerHTML=`<img class="cart-thumb" src="${item.img}" alt="${item.name}">
                <div class="cart-name">${item.name}</div>
                <div class="cart-qty"><label>Sack</label>
                    <div class="cart-stepper">
                        <button type="button" class="cart-minus" data-id="${item.id}" ${item.qtySack <= 1 ? 'disabled' : ''}>−</button>
                        <input type="number" class="cart-qty-input" value="${item.qtySack}" min="1" max="${item.stockSack}" data-id="${item.id}">
                        <button type="button" class="cart-plus" data-id="${item.id}" ${item.qtySack >= item.stockSack ? 'disabled' : ''}>+</button>
                    </div>
                </div>
                <div class="cart-sub">₱${subtotal.toLocaleString('en-PH', { maximumFractionDigits: 0 })}</div>
                <button type="button" class="btn btn-delete cart-remove" data-id="${item.id}"><i class='bx bx-trash'></i></button>`;
            cartItemsEl.appendChild(row);
            const hidPid=document.createElement('input'); hidPid.type='hidden'; hidPid.name='product_id[]'; hidPid.value=String(item.id); inputsEl.appendChild(hidPid);
            const hidKg=document.createElement('input'); hidKg.type='hidden'; hidKg.name='quantity_kg[]'; hidKg.value='0'; inputsEl.appendChild(hidKg);
            const hidSack=document.createElement('input'); hidSack.type='hidden'; hidSack.name='quantity_sack[]'; hidSack.value=String(item.qtySack); inputsEl.appendChild(hidSack);
        });
        document.getElementById('total').value = total.toLocaleString('en-PH', { maximumFractionDigits: 0 });
        document.getElementById('cartCount').textContent = `(${count})`;
        cartItemsEl.querySelectorAll('.cart-plus').forEach((btn)=>{
            btn.addEventListener('click',(e)=>{ const pid=parseInt(e.target.dataset.id); const item=cart.get(pid); if(!item) return; const newQty=Math.min(item.stockSack, item.qtySack+1); item.qtySack=newQty; renderCart(); });
        });
        cartItemsEl.querySelectorAll('.cart-minus').forEach((btn)=>{
            btn.addEventListener('click',(e)=>{ const pid=parseInt(e.target.dataset.id); const item=cart.get(pid); if(!item) return; const newQty=Math.max(0, item.qtySack-1); if(newQty===0){ cart.delete(pid); } else { item.qtySack=newQty; } renderCart(); });
        });
        cartItemsEl.querySelectorAll('.cart-remove').forEach((b)=>{ b.addEventListener('click',(e)=>{ const pid=parseInt(e.currentTarget.dataset.id); cart.delete(pid); renderCart(); }); });
        
        // Add event listeners for manual quantity input
        cartItemsEl.querySelectorAll('.cart-qty-input').forEach((input) => {
            input.addEventListener('change', (e) => {
                const pid = parseInt(e.target.getAttribute('data-id'));
                const item = cart.get(pid);
                if (!item) return;
                
                let newQty = parseInt(e.target.value) || 1;
                newQty = Math.max(1, Math.min(item.stockSack, newQty));
                
                item.qtySack = newQty;
                renderCart();
            });
        });
    }
    document.getElementById('clearCart').addEventListener('click',()=>{ cart.clear(); renderCart(); });
    // Enforce whole numbers in payment input and add comma formatting
    const payEl = document.getElementById('payment');
    payEl.addEventListener('input', () => {
        const cleaned = (payEl.value || '').replace(/\D+/g, '');
        const formatted = cleaned ? parseInt(cleaned).toLocaleString('en-PH') : '';
        payEl.value = formatted;
        updateChangeCalculation();
    });
    // Guard & review submit
    document.getElementById('deliveryForm').addEventListener('submit', function(e){
        e.preventDefault();
        const name = (document.querySelector('input[name=customer_name]')?.value||'').trim();
        const email = (document.querySelector('input[name=customer_email]')?.value||'').trim();
        const addr = (document.getElementById('customerAddress')?.value||'').trim();
        const lat = document.getElementById('customerLat').value;
        const lng = document.getElementById('customerLng').value;
        if (!name || !addr) {
            Swal.fire({ icon:'warning', title:'Customer Info Required', text:'Please enter a valid name and address.' });
            return;
        }
        if (email && !/^\S+@\S+\.\S+$/.test(email)) {
            Swal.fire({ icon:'warning', title:'Invalid Email', text:'Please enter a valid email address or leave it empty.' });
            return;
        }
        if (!lat || !lng) {
            Swal.fire({ icon:'warning', title:'Locate Customer', text:'Please select the customer address from suggestions to pin an accurate location on the map.' });
            return;
        }
        // Parse total and payment by stripping commas
        const total = parseFloat((document.getElementById('total').value||'0').toString().replace(/,/g,''))||0;
        const payment = Math.floor(parseFloat((document.getElementById('payment').value||'0').toString().replace(/,/g,''))||0);
        if (total <= 0) { Swal.fire({icon:'warning', title:'No Items', text:'Please add at least one item.'}); return; }
        if (payment < total) { Swal.fire({icon:'error', title:'Insufficient Payment', text:'Payment is less than total.'}); return; }
        Swal.fire({
            title: 'Review and Process Delivery',
            html: `
                <div style="text-align:left;line-height:1.6">
                    <div><strong>Customer:</strong> ${name}${email ? `<br><strong>Email:</strong> ${email}` : '<br><em>No email provided</em>'}<br><strong>Address:</strong> ${addr}</div>
                    <div style=\"margin-top:6px\"><strong>Total:</strong> Php ${total.toLocaleString('en-PH', { maximumFractionDigits: 0 })}</div>
                    <div><strong>Payment:</strong> Php ${payment.toLocaleString('en-PH', { maximumFractionDigits: 0 })}</div>
                    <div><strong>Change:</strong> Php ${Math.max(0, payment-total).toLocaleString('en-PH', { maximumFractionDigits: 0 })}</div>
                </div>
            `,
            icon:'question',
            showCancelButton:true,
            confirmButtonText:'OK, Process',
            cancelButtonText:'Cancel',
            buttonsStyling:false,
            reverseButtons:true,
            customClass:{ confirmButton:'btn btn-add swal-ok', cancelButton:'btn swal-cancel' },
            showClass: { popup: 'animate__animated animate__fadeInDown' },
            hideClass: { popup: 'animate__animated animate__fadeOutUp' }
        }).then((res)=>{ if (res.isConfirmed) { showProcessingOverlay(); setTimeout(()=>{ e.target.submit(); }, 30); } });
    });

    function addToCartFromCard(cardEl) {
        const id = parseInt(cardEl.getAttribute('data-id'));
        const name = cardEl.getAttribute('data-name');
        const img = cardEl.getAttribute('data-img');
        const priceSack = cardEl.getAttribute('data-pricesack') !== '' ? parseFloat(cardEl.getAttribute('data-pricesack')) : null;
        const stockSack = parseFloat(cardEl.getAttribute('data-stocksack')) || 0;
        if (!cart.has(id)) {
            cart.set(id, { id, name, img, priceSack, stockSack, qtySack: 1 });
        } else {
            const item = cart.get(id);
            if (item.qtySack < stockSack) {
                item.qtySack += 1;
            }
        }
        renderCart();
    }

    
    

    document.addEventListener('click', function (e) {
        const addBtn = e.target.closest('.btn-add');
        if (addBtn) {
            const card = addBtn.closest('.product-card');
            addToCartFromCard(card);
        }
    });

    // Real-time change calculation
    function updateChangeCalculation() {
        const total = parseFloat((document.getElementById('total').value || '0').toString().replace(/,/g, '')) || 0;
        const payment = parseFloat((document.getElementById('payment').value || '0').toString().replace(/,/g, '')) || 0;
        const change = Math.max(0, payment - total);
        document.getElementById('change').value = change.toLocaleString('en-PH', { maximumFractionDigits: 0 });
    }

    // Override renderCart to include shortcuts and change calculation
    const originalRenderCart = renderCart;
    renderCart = function() {
        originalRenderCart();
        updatePaymentShortcuts();
        updateChangeCalculation();
        
        // Update all product displays
        document.querySelectorAll('.product-card').forEach(card => {
            const productId = parseInt(card.getAttribute('data-id'));
            updateProductDisplay(productId);
        });
    };

    // Add payment input listener for real-time change calculation
    document.getElementById('payment').addEventListener('input', updateChangeCalculation);
    </script>
    <?php if ($saleSuccess): ?>
    <script>
        // Show the same overlay before redirecting to receipt, like POS
        showProcessingOverlay();
        setTimeout(() => { window.location.href = 'receipt.php?txn=<?php echo urlencode($saleSuccess); ?>'; }, 50);
    </script>
    <?php elseif ($saleError): ?>
    <script>
        Swal.fire({ icon: 'error', title: 'Delivery Failed', text: '<?php echo $saleError; ?>' });
    </script>
    <?php endif; ?>
    <script src="assets/js/main.js"></script>
</body>
</html>

