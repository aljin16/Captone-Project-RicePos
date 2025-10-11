<?php
// Public order tracking by transaction ID
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../classes/Database.php';

$pdo = Database::getInstance()->getConnection();
$txn = isset($_GET['txn']) ? trim((string)$_GET['txn']) : '';
$result = null; $items = [];

if ($txn !== '') {
    // Find sale and delivery (only show sales that have delivery orders)
    $stmt = $pdo->prepare('SELECT s.id as sale_id, s.transaction_id, s.datetime, s.total_amount,
                                  d.id as delivery_id, d.status, d.assigned_to, d.notes, d.updated_at,
                                  d.customer_lat, d.customer_lng, d.proof_image, d.delivered_at,
                                  d.failed_reason, d.created_at, d.customer_name, d.customer_phone
                             FROM sales s
                        INNER JOIN delivery_orders d ON d.sale_id = s.id
                            WHERE s.transaction_id = ?
                            LIMIT 1');
    $stmt->execute([$txn]);
    $result = $stmt->fetch();
    if ($result) {
        // Items summary
        $it = $pdo->prepare('SELECT p.name, p.category, si.quantity_kg, si.quantity_sack, si.price
                               FROM sale_items si
                               JOIN products p ON p.id = si.product_id
                              WHERE si.sale_id = ?');
        $it->execute([$result['sale_id']]);
        $items = $it->fetchAll();
        // Delivery staff name (no contact info)
        if (!empty($result['assigned_to'])) {
            $u = $pdo->prepare('SELECT username FROM users WHERE id = ?');
            $u->execute([$result['assigned_to']]);
            $result['delivery_staff_name'] = $u->fetchColumn();
        }
    }
}

// Status mapping for timeline
$statusStages = [
    'pending' => 1,
    'picked_up' => 2,
    'in_transit' => 3,
    'out_for_delivery' => 3,
    'delivered' => 4,
    'failed' => 4,
    'cancelled' => 4
];

$currentStage = isset($result['status']) ? ($statusStages[$result['status']] ?? 1) : 0;
$deliveryStatus = $result['status'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Track Order<?php echo $txn?(' - '.htmlspecialchars($txn)) : ''; ?> | RicePOS</title>
    <?php $cssVer = @filemtime(__DIR__ . '/assets/css/style.css') ?: time(); ?>
    <link rel="stylesheet" href="assets/css/style.css?v=<?php echo $cssVer; ?>">
    
    <!-- Leaflet for maps -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    
    <!-- Font Awesome for icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" />
    
    <style>
        :root {
            --color-pending: #facc15;
            --color-in-progress: #3b82f6;
            --color-delivered: #10b981;
            --color-failed: #ef4444;
            --shadow-soft: 0 4px 20px rgba(0,0,0,0.08);
            --shadow-hover: 0 8px 30px rgba(0,0,0,0.12);
        }
        
        body { 
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            margin: 0;
            padding: 0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .track-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 2rem 1rem;
        }
        
        /* Header */
        .track-header {
            text-align: center;
            color: #fff;
            margin-bottom: 2rem;
        }
        
        .track-header h1 {
            font-size: 2.5rem;
            font-weight: 800;
            margin: 0 0 0.5rem 0;
            text-shadow: 0 2px 10px rgba(0,0,0,0.2);
        }
        
        .track-header p {
            font-size: 1.1rem;
            opacity: 0.95;
            margin: 0;
        }
        
        /* Search Card */
        .search-card {
            background: #fff;
            border-radius: 20px;
            padding: 2rem;
            box-shadow: var(--shadow-soft);
            margin-bottom: 1.5rem;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        
        .search-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-hover);
        }
        
        .search-form {
            display: flex;
            gap: 1rem;
            align-items: center;
        }
        
        .search-form input {
            flex: 1;
            height: 56px;
            padding: 0 1.5rem;
            border: 2px solid #e5e7eb;
            border-radius: 14px;
            font-size: 1.05rem;
            transition: all 0.3s ease;
        }
        
        .search-form input:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.1);
        }
        
        .search-form button {
            height: 56px;
            padding: 0 2rem;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: #fff;
            border: none;
            border-radius: 14px;
            font-size: 1.05rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .search-form button:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.4);
        }
        
        .search-info {
            margin-top: 1rem;
            color: #6b7280;
            font-size: 0.95rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        /* Dashboard Summary Cards */
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .summary-card {
            background: #fff;
            border-radius: 20px;
            padding: 1.5rem;
            box-shadow: var(--shadow-soft);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        
        .summary-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--card-color), var(--card-color-light));
        }
        
        .summary-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-hover);
        }
        
        .summary-card.blue { --card-color: #3b82f6; --card-color-light: #60a5fa; }
        .summary-card.green { --card-color: #10b981; --card-color-light: #34d399; }
        .summary-card.amber { --card-color: #f59e0b; --card-color-light: #fbbf24; }
        .summary-card.red { --card-color: #ef4444; --card-color-light: #f87171; }
        
        .summary-icon {
            width: 56px;
            height: 56px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.8rem;
            margin-bottom: 1rem;
            background: linear-gradient(135deg, var(--card-color), var(--card-color-light));
            color: #fff;
        }
        
        .summary-label {
            font-size: 0.9rem;
            color: #6b7280;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 0.5rem;
        }
        
        .summary-value {
            font-size: 1.8rem;
            font-weight: 800;
            color: #1f2937;
            margin-bottom: 0.25rem;
        }
        
        .summary-sub {
            font-size: 0.85rem;
            color: #9ca3af;
        }
        
        /* Timeline Progress Tracker */
        .timeline-card {
            background: #fff;
            border-radius: 20px;
            padding: 2.5rem;
            box-shadow: var(--shadow-soft);
            margin-bottom: 2rem;
        }
        
        .timeline-title {
            font-size: 1.5rem;
            font-weight: 800;
            color: #1f2937;
            margin-bottom: 2rem;
            text-align: center;
        }
        
        .timeline {
            position: relative;
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin: 2rem 0;
            padding: 0 2rem;
        }
        
        .timeline-line {
            position: absolute;
            top: 50%;
            left: 10%;
            right: 10%;
            height: 4px;
            background: #e5e7eb;
            transform: translateY(-50%);
            z-index: 1;
        }
        
        .timeline-progress {
            position: absolute;
            top: 0;
            left: 0;
            height: 100%;
            background: linear-gradient(90deg, #10b981, #3b82f6);
            transition: width 1s ease;
            border-radius: 4px;
        }
        
        .timeline-step {
            position: relative;
            z-index: 2;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 1rem;
            flex: 1;
        }
        
        .timeline-icon {
            width: 70px;
            height: 70px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.8rem;
            background: #fff;
            border: 4px solid #e5e7eb;
            transition: all 0.5s ease;
            position: relative;
        }
        
        .timeline-step.active .timeline-icon,
        .timeline-step.completed .timeline-icon {
            border-color: #3b82f6;
            background: linear-gradient(135deg, #3b82f6, #8b5cf6);
            color: #fff;
            animation: pulse 2s infinite;
        }
        
        .timeline-step.completed .timeline-icon {
            background: linear-gradient(135deg, #10b981, #059669);
            border-color: #10b981;
            animation: none;
        }
        
        @keyframes pulse {
            0%, 100% { transform: scale(1); box-shadow: 0 0 0 0 rgba(59, 130, 246, 0.7); }
            50% { transform: scale(1.05); box-shadow: 0 0 0 10px rgba(59, 130, 246, 0); }
        }
        
        .timeline-label {
            font-size: 0.95rem;
            font-weight: 700;
            color: #6b7280;
            text-align: center;
            transition: color 0.3s ease;
        }
        
        .timeline-step.active .timeline-label,
        .timeline-step.completed .timeline-label {
            color: #1f2937;
        }
        
        .timeline-time {
            font-size: 0.8rem;
            color: #9ca3af;
            text-align: center;
        }
        
        /* Map Section */
        .map-card {
            background: #fff;
            border-radius: 20px;
            padding: 2rem;
            box-shadow: var(--shadow-soft);
            margin-bottom: 2rem;
        }
        
        /* Custom map marker styling */
        .custom-marker {
            background: transparent !important;
            border: none !important;
        }
        
        .truck-marker {
            background: transparent !important;
            border: none !important;
            border-radius: 0 !important;
            box-shadow: none !important;
        }
        
        .truck-marker img {
            background: transparent !important;
            mix-blend-mode: multiply;
            filter: drop-shadow(0 2px 4px rgba(0,0,0,0.3));
        }
        
        .leaflet-marker-icon {
            transition: transform 0.3s ease;
        }
        
        .leaflet-marker-icon:hover {
            transform: scale(1.1);
        }
        
        .map-title {
            font-size: 1.5rem;
            font-weight: 800;
            color: #1f2937;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        #deliveryMap {
            height: 450px;
            border-radius: 14px;
            box-shadow: inset 0 2px 8px rgba(0,0,0,0.1);
        }
        
        .map-legend {
            display: flex;
            gap: 1.5rem;
            margin-top: 1rem;
            flex-wrap: wrap;
        }
        
        .legend-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.9rem;
            color: #6b7280;
        }
        
        .legend-dot {
            width: 12px;
            height: 12px;
            border-radius: 50%;
        }
        
        /* Order Details */
        .details-card {
            background: #fff;
            border-radius: 20px;
            padding: 2rem;
            box-shadow: var(--shadow-soft);
            margin-bottom: 2rem;
        }
        
        .details-title {
            font-size: 1.5rem;
            font-weight: 800;
            color: #1f2937;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        .details-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
        }
        
        .detail-item {
            padding: 1rem;
            background: #f9fafb;
            border-radius: 12px;
            border-left: 4px solid #3b82f6;
        }
        
        .detail-label {
            font-size: 0.85rem;
            color: #6b7280;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 0.5rem;
        }
        
        .detail-value {
            font-size: 1.1rem;
            font-weight: 700;
            color: #1f2937;
        }
        
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            border-radius: 999px;
            font-weight: 700;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .status-badge.pending { background: #fef3c7; color: #92400e; }
        .status-badge.picked_up { background: #dbeafe; color: #1e3a8a; }
        .status-badge.in_transit,
        .status-badge.out_for_delivery { background: #bfdbfe; color: #1e40af; }
        .status-badge.delivered { background: #d1fae5; color: #065f46; }
        .status-badge.failed { background: #fee2e2; color: #991b1b; }
        .status-badge.cancelled { background: #f3f4f6; color: #4b5563; }
        
        /* Items List */
        .items-list {
            margin-top: 1.5rem;
            border-top: 2px dashed #e5e7eb;
            padding-top: 1.5rem;
        }
        
        .item-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1rem;
            background: #f9fafb;
            border-radius: 10px;
            margin-bottom: 0.75rem;
            transition: background 0.2s ease;
        }
        
        .item-row:hover {
            background: #f3f4f6;
        }
        
        .item-info {
            flex: 1;
        }
        
        .item-name {
            font-weight: 700;
            color: #1f2937;
            font-size: 1.05rem;
            margin-bottom: 0.25rem;
        }
        
        .item-details {
            font-size: 0.9rem;
            color: #6b7280;
        }
        
        .item-price {
            font-size: 1.2rem;
            font-weight: 800;
            color: #10b981;
        }
        
        /* Proof of Delivery */
        .proof-card {
            background: #fff;
            border-radius: 20px;
            padding: 2rem;
            box-shadow: var(--shadow-soft);
            margin-bottom: 2rem;
        }
        
        .proof-image {
            width: 100%;
            max-width: 500px;
            height: auto;
            border-radius: 14px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            margin: 1rem auto;
            display: block;
        }
        
        .verified-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem 1.5rem;
            background: linear-gradient(135deg, #10b981, #059669);
            color: #fff;
            border-radius: 999px;
            font-weight: 700;
            margin-top: 1rem;
        }
        
        /* Feedback Section */
        .feedback-card {
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            border-radius: 20px;
            padding: 2rem;
            box-shadow: var(--shadow-soft);
            margin-bottom: 2rem;
        }
        
        .feedback-title {
            font-size: 1.5rem;
            font-weight: 800;
            color: #78350f;
            margin-bottom: 1rem;
            text-align: center;
        }
        
        .star-rating {
            display: flex;
            justify-content: center;
            gap: 0.5rem;
            margin-bottom: 1.5rem;
        }
        
        .star {
            font-size: 2.5rem;
            color: #d1d5db;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        
        .star:hover,
        .star.active {
            color: #fbbf24;
            transform: scale(1.2);
        }
        
        .feedback-input {
            width: 100%;
            padding: 1rem;
            border: 2px solid #f59e0b;
            border-radius: 12px;
            font-size: 1rem;
            font-family: inherit;
            resize: vertical;
            min-height: 100px;
            margin-bottom: 1rem;
        }
        
        .feedback-btn {
            width: 100%;
            padding: 1rem 2rem;
            background: linear-gradient(135deg, #f59e0b, #d97706);
            color: #fff;
            border: none;
            border-radius: 12px;
            font-size: 1.1rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .feedback-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(245, 158, 11, 0.4);
        }
        
        /* Not Found */
        .not-found {
            background: #fff;
            border-radius: 20px;
            padding: 3rem;
            text-align: center;
            box-shadow: var(--shadow-soft);
        }
        
        .not-found-icon {
            font-size: 4rem;
            color: #ef4444;
            margin-bottom: 1rem;
        }
        
        .not-found h2 {
            font-size: 1.8rem;
            color: #1f2937;
            margin-bottom: 0.5rem;
        }
        
        .not-found p {
            color: #6b7280;
            font-size: 1.1rem;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .track-header h1 { font-size: 1.8rem; }
            .search-form { flex-direction: column; }
            .search-form input,
            .search-form button { width: 100%; }
            .timeline { flex-direction: column; gap: 2rem; padding: 0; }
            .timeline-line { display: none; }
            .summary-grid { grid-template-columns: 1fr; }
            .details-grid { grid-template-columns: 1fr; }
            #deliveryMap { height: 300px; }
        }
        
        /* Loading Animation */
        .loading {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid rgba(255,255,255,.3);
            border-radius: 50%;
            border-top-color: #fff;
            animation: spin 1s ease-in-out infinite;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        /* Pulse animation for tracking button */
        @keyframes pulse-once {
            0% { transform: scale(1); box-shadow: 0 0 0 0 rgba(59, 130, 246, 0.7); }
            50% { transform: scale(1.15); box-shadow: 0 0 0 15px rgba(59, 130, 246, 0); }
            100% { transform: scale(1); box-shadow: 0 0 0 0 rgba(59, 130, 246, 0); }
        }
        
        .pulse-once {
            animation: pulse-once 1s ease-out;
        }
        
        /* Live update indicator */
        .live-indicator {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            background: rgba(16, 185, 129, 0.1);
            border-radius: 999px;
            font-size: 0.9rem;
            font-weight: 700;
            color: #059669;
            border: 2px solid #10b981;
        }
        
        .live-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: #10b981;
            animation: pulse 2s infinite;
        }
        
        /* Responsive button for mobile */
        @media (max-width: 768px) {
            .map-card > div:first-child {
                flex-direction: column;
                align-items: flex-start !important;
                gap: 1rem;
            }
            
            .map-card button {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
</head>
<body>
    <div class="track-container">
        <!-- Header -->
        <div class="track-header">
            <h1>🚚 Track Your Delivery</h1>
            <p>Real-time updates for your rice delivery</p>
            <?php if ($result && ($deliveryStatus === 'in_transit' || $deliveryStatus === 'out_for_delivery')): ?>
            <div style="margin-top: 1rem;">
                <span class="live-indicator">
                    <span class="live-dot"></span>
                    LIVE TRACKING ACTIVE
                </span>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- Search Card -->
        <div class="search-card">
            <form class="search-form" method="get" action="track_order.php" autocomplete="off">
                <input type="text" name="txn" placeholder="Enter your transaction number (e.g. TXN2025...)" 
                       value="<?php echo htmlspecialchars($txn); ?>" required>
                <button type="submit">
                    <i class="fas fa-search"></i>
                    Track Order
                </button>
            </form>
            <div class="search-info">
                <i class="fas fa-info-circle"></i>
                Enter your transaction ID to track your delivery in real-time
            </div>
        </div>
        
        <?php if ($txn !== '' && !$result): ?>
            <!-- Not Found -->
            <div class="not-found">
                <div class="not-found-icon">
                    <i class="fas fa-search"></i>
                </div>
                <h2>Order Not Found</h2>
                <p>No delivery found for transaction <strong><?php echo htmlspecialchars($txn); ?></strong></p>
                <p style="margin-top: 1rem; font-size: 0.95rem;">Please check your transaction ID and try again.</p>
            </div>
        <?php endif; ?>
        
        <?php if ($result): ?>
            <?php
            // Calculate ETA and distance (mock data for now)
            $eta = 'Calculating...';
            $distance = 'N/A';
            
            if ($deliveryStatus === 'in_transit' || $deliveryStatus === 'out_for_delivery') {
                $eta = '15-25 mins';
                $distance = '3.4 km';
            } elseif ($deliveryStatus === 'delivered') {
                $eta = 'Delivered';
                $distance = 'Completed';
            } elseif ($deliveryStatus === 'picked_up') {
                $eta = '30-40 mins';
                $distance = '5.2 km';
            }
            ?>
            
            <!-- Dashboard Summary Cards -->
            <div class="summary-grid">
                <div class="summary-card blue">
                    <div class="summary-icon">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="summary-label">Estimated Time</div>
                    <div class="summary-value"><?php echo htmlspecialchars($eta); ?></div>
                    <div class="summary-sub">Expected delivery time</div>
                </div>
                
                <div class="summary-card green">
                    <div class="summary-icon">
                        <i class="fas fa-map-marker-alt"></i>
                    </div>
                    <div class="summary-label">Distance</div>
                    <div class="summary-value"><?php echo htmlspecialchars($distance); ?></div>
                    <div class="summary-sub">From delivery location</div>
                </div>
                
                <div class="summary-card amber">
                    <div class="summary-icon">
                        <i class="fas fa-receipt"></i>
                    </div>
                    <div class="summary-label">Order ID</div>
                    <div class="summary-value">#<?php echo htmlspecialchars($result['delivery_id']); ?></div>
                    <div class="summary-sub"><?php echo htmlspecialchars($result['transaction_id']); ?></div>
                </div>
                
                <div class="summary-card <?php 
                    if ($deliveryStatus === 'delivered') {
                        echo 'green';
                    } elseif ($deliveryStatus === 'failed' || $deliveryStatus === 'cancelled') {
                        echo 'red';
                    } else {
                        echo 'blue';
                    }
                ?>">
                    <div class="summary-icon">
                        <i class="fas <?php 
                            if ($deliveryStatus === 'delivered') {
                                echo 'fa-check-circle';
                            } elseif ($deliveryStatus === 'failed' || $deliveryStatus === 'cancelled') {
                                echo 'fa-times-circle';
                            } else {
                                echo 'fa-truck';
                            }
                        ?>"></i>
                    </div>
                    <div class="summary-label">Current Status</div>
                    <div class="summary-value" style="font-size: 1.3rem;"><?php echo ucfirst(str_replace('_', ' ', $deliveryStatus)); ?></div>
                    <div class="summary-sub">Last updated <?php 
                        $updatedTime = strtotime($result['updated_at'] ?? $result['created_at']);
                        $diff = time() - $updatedTime;
                        if ($diff < 60) echo 'just now';
                        elseif ($diff < 3600) echo floor($diff/60) . ' mins ago';
                        else echo floor($diff/3600) . ' hours ago';
                    ?></div>
                </div>
            </div>
            
            <!-- Visual Timeline Progress -->
            <div class="timeline-card">
                <div class="timeline-title">📦 Delivery Progress</div>
                
                <div class="timeline">
                    <div class="timeline-line">
                        <div class="timeline-progress" style="width: <?php echo ($currentStage / 4 * 100); ?>%;"></div>
                    </div>
                    
                    
                    <!-- Step 1: Order Placed -->
                    <div class="timeline-step <?php echo ($currentStage >= 1) ? 'completed' : ''; ?>">
                        <div class="timeline-icon">
                            <i class="fas fa-shopping-cart"></i>
                        </div>
                        <div class="timeline-label">Order Placed</div>
                        <div class="timeline-time"><?php echo date('M d, h:i A', strtotime($result['created_at'])); ?></div>
                    </div>
                    
                    <!-- Step 2: Preparing -->
                    <div class="timeline-step <?php 
                        if ($currentStage >= 2) {
                            echo ($currentStage === 2) ? 'active' : 'completed';
                        } else {
                            echo '';
                        }
                    ?>">
                        <div class="timeline-icon">
                            <i class="fas fa-box-open"></i>
                        </div>
                        <div class="timeline-label">Preparing</div>
                        <div class="timeline-time">
                            <?php 
                            if ($deliveryStatus === 'picked_up') {
                                echo date('M d, h:i A', strtotime($result['updated_at'])); 
                            } elseif ($currentStage >= 2) {
                                echo 'Processing';
                            } else {
                                echo 'Pending';
                            }
                            ?>
                        </div>
                    </div>
                    
                    <!-- Step 3: Out for Delivery -->
                    <div class="timeline-step <?php 
                        if ($currentStage >= 3) {
                            echo ($currentStage === 3) ? 'active' : 'completed';
                        } else {
                            echo '';
                        }
                    ?>">
                        <div class="timeline-icon">
                            <i class="fas fa-shipping-fast"></i>
                        </div>
                        <div class="timeline-label">Out for Delivery</div>
                        <div class="timeline-time">
                            <?php 
                            if ($deliveryStatus === 'in_transit' || $deliveryStatus === 'out_for_delivery') {
                                echo date('M d, h:i A', strtotime($result['updated_at']));
                            } elseif ($currentStage >= 3) {
                                echo 'In Transit';
                            } else {
                                echo 'Waiting';
                            }
                            ?>
                        </div>
                    </div>
                    
                    <!-- Step 4: Delivered -->
                    <div class="timeline-step <?php 
                        if ($deliveryStatus === 'delivered') {
                            echo 'completed';
                        } elseif ($deliveryStatus === 'failed' || $deliveryStatus === 'cancelled') {
                            echo 'completed';
                        } else {
                            echo '';
                        }
                    ?>">
                        <div class="timeline-icon">
                            <i class="fas <?php 
                                if ($deliveryStatus === 'delivered') {
                                    echo 'fa-check-circle';
                                } elseif ($deliveryStatus === 'failed') {
                                    echo 'fa-times-circle';
                                } else {
                                    echo 'fa-home';
                                }
                            ?>"></i>
                        </div>
                        <div class="timeline-label">
                            <?php 
                            if ($deliveryStatus === 'delivered') {
                                echo 'Delivered ✅';
                            } elseif ($deliveryStatus === 'failed') {
                                echo 'Failed ❌';
                            } elseif ($deliveryStatus === 'cancelled') {
                                echo 'Cancelled';
                            } else {
                                echo 'Pending';
                            }
                            ?>
                        </div>
                        <div class="timeline-time">
                            <?php 
                            if ($deliveryStatus === 'delivered' && !empty($result['delivered_at'])) {
                                echo date('M d, h:i A', strtotime($result['delivered_at']));
                            } elseif ($deliveryStatus === 'failed' || $deliveryStatus === 'cancelled') {
                                echo date('M d, h:i A', strtotime($result['updated_at']));
                            } else {
                                echo 'Soon';
                            }
                            ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Live Map -->
            <?php if (!empty($result['customer_lat']) && !empty($result['customer_lng'])): ?>
            <div class="map-card">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                    <div class="map-title" style="margin-bottom: 0;">
                        <i class="fas fa-map-marked-alt"></i>
                        Live Tracking Map
                    </div>
                    <?php if ($deliveryStatus === 'in_transit' || $deliveryStatus === 'out_for_delivery'): ?>
                    <button onclick="trackMyRider()" style="padding: 0.75rem 1.5rem; background: linear-gradient(135deg, #3b82f6, #1d4ed8); color: white; border: none; border-radius: 12px; font-weight: 700; cursor: pointer; display: flex; align-items: center; gap: 0.5rem; transition: all 0.3s ease;">
                        <i class="fas fa-crosshairs"></i>
                        Track My Rider
                    </button>
                    <?php endif; ?>
                </div>
                <div id="deliveryMap"></div>
                <div class="map-legend">
                    <div class="legend-item">
                        <div class="legend-dot" style="background: #10b981;"></div>
                        <span>Delivery Location</span>
                    </div>
                    <div class="legend-item">
                        <div class="legend-dot" style="background: #3b82f6;"></div>
                        <span>Delivery Vehicle</span>
                    </div>
                    <div class="legend-item">
                        <div class="legend-dot" style="background: #f59e0b;"></div>
                        <span>Store Location</span>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Smart Notifications / Updates Timeline -->
            <div class="details-card" style="background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%);">
                <div class="details-title" style="color: #1e40af;">
                    <i class="fas fa-bell"></i>
                    Delivery Updates
                </div>
                
                <div style="display: flex; flex-direction: column; gap: 1rem; margin-top: 1rem;">
                    <?php
                    // Generate smart notifications based on status
                    $notifications = [];
                    
                    $notifications[] = [
                        'icon' => 'fa-shopping-cart',
                        'color' => '#10b981',
                        'title' => 'Order Placed!',
                        'message' => 'Your order has been received and is being prepared. 📦',
                        'time' => $result['created_at']
                    ];
                    
                    if ($currentStage >= 2) {
                        $notifications[] = [
                            'icon' => 'fa-box-open',
                            'color' => '#3b82f6',
                            'title' => 'Order is being packed! 📦',
                            'message' => 'Our team is carefully preparing your rice delivery.',
                            'time' => $result['updated_at'] ?? $result['created_at']
                        ];
                    }
                    
                    if ($deliveryStatus === 'in_transit' || $deliveryStatus === 'out_for_delivery') {
                        $notifications[] = [
                            'icon' => 'fa-shipping-fast',
                            'color' => '#f59e0b',
                            'title' => 'Rider is on the way! 🚚',
                            'message' => "Your delivery is approximately {$distance} away. Hang tight!",
                            'time' => $result['updated_at']
                        ];
                    }
                    
                    if ($deliveryStatus === 'delivered') {
                        $notifications[] = [
                            'icon' => 'fa-check-circle',
                            'color' => '#10b981',
                            'title' => 'Delivered! Thank you 💚',
                            'message' => 'Your order has been successfully delivered. Enjoy your rice!',
                            'time' => $result['delivered_at'] ?? $result['updated_at']
                        ];
                    }
                    
                    if ($deliveryStatus === 'failed') {
                        $notifications[] = [
                            'icon' => 'fa-exclamation-triangle',
                            'color' => '#ef4444',
                            'title' => 'Delivery Issue',
                            'message' => !empty($result['failed_reason']) ? htmlspecialchars($result['failed_reason']) : 'Unfortunately, we could not complete the delivery. Please contact support.',
                            'time' => $result['updated_at']
                        ];
                    }
                    
                    // Display notifications in reverse order (newest first)
                    foreach (array_reverse($notifications) as $notif):
                    ?>
                    <div style="display: flex; gap: 1rem; padding: 1rem; background: white; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); border-left: 4px solid <?php echo $notif['color']; ?>;">
                        <div style="width: 48px; height: 48px; border-radius: 50%; background: <?php echo $notif['color']; ?>; color: white; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                            <i class="fas <?php echo $notif['icon']; ?>" style="font-size: 1.3rem;"></i>
                        </div>
                        <div style="flex: 1;">
                            <div style="font-weight: 700; color: #1f2937; font-size: 1.05rem; margin-bottom: 0.25rem;">
                                <?php echo $notif['title']; ?>
                            </div>
                            <div style="color: #6b7280; font-size: 0.95rem; margin-bottom: 0.5rem;">
                                <?php echo $notif['message']; ?>
                            </div>
                            <div style="color: #9ca3af; font-size: 0.85rem;">
                                <i class="far fa-clock"></i>
                                <?php echo date('M d, Y h:i A', strtotime($notif['time'])); ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <!-- Order Details -->
            <div class="details-card">
                <div class="details-title">
                    <i class="fas fa-info-circle"></i>
                    Order Details
                </div>
                
                <div class="details-grid">
                    <div class="detail-item">
                        <div class="detail-label">Transaction ID</div>
                        <div class="detail-value"><?php echo htmlspecialchars($result['transaction_id']); ?></div>
                    </div>
                    
                    <div class="detail-item">
                        <div class="detail-label">Order Date</div>
                        <div class="detail-value"><?php echo date('M d, Y h:i A', strtotime($result['datetime'])); ?></div>
                    </div>
                    
                    <div class="detail-item">
                        <div class="detail-label">Total Amount</div>
                        <div class="detail-value">₱<?php echo number_format((float)$result['total_amount'], 2); ?></div>
                    </div>
                    
                    <div class="detail-item">
                        <div class="detail-label">Delivery Status</div>
                        <div class="detail-value">
                            <span class="status-badge <?php echo htmlspecialchars($deliveryStatus); ?>">
                                <?php echo ucfirst(str_replace('_', ' ', $deliveryStatus)); ?>
                            </span>
                        </div>
                    </div>
                    
                    <div class="detail-item">
                        <div class="detail-label">Delivery Staff</div>
                        <div class="detail-value"><?php echo htmlspecialchars($result['delivery_staff_name'] ?? 'Unassigned'); ?></div>
                    </div>
                    
                    <?php if (!empty($result['notes'])): ?>
                    <div class="detail-item">
                        <div class="detail-label">Remarks</div>
                        <div class="detail-value"><?php echo htmlspecialchars($result['notes']); ?></div>
                    </div>
                    <?php endif; ?>
                </div>
                
                <!-- Items List -->
                <div class="items-list">
                    <h3 style="margin-bottom: 1rem; color: #1f2937;">📦 Order Items</h3>
                    <?php foreach ($items as $it): ?>
                    <div class="item-row">
                        <div class="item-info">
                            <div class="item-name"><?php echo htmlspecialchars($it['name']); ?></div>
                            <div class="item-details">
                                Category: <?php echo htmlspecialchars($it['category']); ?>
                                <?php if ($it['quantity_kg'] > 0): ?>
                                    • <?php echo number_format((float)$it['quantity_kg'], 2); ?> kg
                                <?php endif; ?>
                                <?php if ($it['quantity_sack'] > 0): ?>
                                    • <?php echo number_format((float)$it['quantity_sack'], 2); ?> sack(s)
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="item-price">₱<?php echo number_format((float)$it['price'], 0); ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <!-- Proof of Delivery -->
            <?php if ($deliveryStatus === 'delivered' && !empty($result['proof_image'])): ?>
            <div class="proof-card">
                <div class="details-title">
                    <i class="fas fa-camera"></i>
                    Proof of Delivery
                </div>
                <img src="uploads/proof_of_delivery/<?php echo htmlspecialchars($result['proof_image']); ?>" 
                     alt="Proof of Delivery" class="proof-image">
                <div style="text-align: center;">
                    <span class="verified-badge">
                        <i class="fas fa-check-circle"></i>
                        Verified Delivery by <?php echo htmlspecialchars($result['delivery_staff_name'] ?? 'Staff'); ?>
                    </span>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Feedback Section (only show after delivery) -->
            <?php if ($deliveryStatus === 'delivered'): ?>
            <div class="feedback-card">
                <div class="feedback-title">⭐ Rate Your Delivery Experience</div>
                <div class="star-rating">
                    <i class="fas fa-star star" data-rating="1"></i>
                    <i class="fas fa-star star" data-rating="2"></i>
                    <i class="fas fa-star star" data-rating="3"></i>
                    <i class="fas fa-star star" data-rating="4"></i>
                    <i class="fas fa-star star" data-rating="5"></i>
                </div>
                <textarea class="feedback-input" id="feedbackComment" placeholder="Tell us about your experience (optional)..."></textarea>
                <button class="feedback-btn" onclick="submitFeedback()">
                    <i class="fas fa-paper-plane"></i>
                    Submit Feedback
                </button>
            </div>
            <?php endif; ?>
            
        <?php endif; ?>
    </div>
    
    <script>
        // Auto-refresh for live updates (only if delivery is active)
        <?php if ($deliveryStatus === 'in_transit' || $deliveryStatus === 'out_for_delivery' || $deliveryStatus === 'picked_up'): ?>
        let autoRefreshInterval = setInterval(function() {
            // Silently refresh the page every 30 seconds for live updates
            const currentScrollPos = window.pageYOffset;
            fetch(window.location.href)
                .then(res => res.text())
                .then(html => {
                    // Parse the new content
                    const parser = new DOMParser();
                    const newDoc = parser.parseFromString(html, 'text/html');
                    
                    // Update status-related elements without full page reload
                    const newStatus = newDoc.querySelector('.summary-card:nth-child(4) .summary-value');
                    const oldStatus = document.querySelector('.summary-card:nth-child(4) .summary-value');
                    
                    if (newStatus && oldStatus && newStatus.textContent !== oldStatus.textContent) {
                        // Status changed - full reload for dramatic effect
                        location.reload();
                    }
                })
                .catch(err => console.log('Auto-refresh failed:', err));
        }, 30000); // Refresh every 30 seconds
        
        // Stop auto-refresh when page is hidden (battery saving)
        document.addEventListener('visibilitychange', function() {
            if (document.hidden) {
                clearInterval(autoRefreshInterval);
            } else {
                // Resume if status is still active
                location.reload();
            }
        });
        <?php endif; ?>
        
        // Star rating functionality
        let selectedRating = 0;
        const stars = document.querySelectorAll('.star');
        
        stars.forEach(star => {
            star.addEventListener('click', function() {
                selectedRating = parseInt(this.dataset.rating);
                updateStars();
            });
            
            star.addEventListener('mouseenter', function() {
                const rating = parseInt(this.dataset.rating);
                stars.forEach((s, idx) => {
                    if (idx < rating) {
                        s.classList.add('active');
                    } else {
                        s.classList.remove('active');
                    }
                });
            });
        });
        
        document.querySelector('.star-rating')?.addEventListener('mouseleave', updateStars);
        
        function updateStars() {
            stars.forEach((s, idx) => {
                if (idx < selectedRating) {
                    s.classList.add('active');
                } else {
                    s.classList.remove('active');
                }
            });
        }
        
        async function submitFeedback() {
            if (selectedRating === 0) {
                alert('Please select a rating ⭐');
                return;
            }
            
            const comment = document.getElementById('feedbackComment').value;
            const btn = document.querySelector('.feedback-btn');
            const originalHTML = btn.innerHTML;
            
            // Show loading state
            btn.disabled = true;
            btn.innerHTML = '<span class="loading"></span> Submitting...';
            
            try {
                const formData = new FormData();
                formData.append('txn', '<?php echo htmlspecialchars($result['transaction_id'] ?? '', ENT_QUOTES); ?>');
                formData.append('rating', selectedRating);
                formData.append('comment', comment);
                
                const response = await fetch('submit_tracking_feedback.php', {
                    method: 'POST',
                    body: formData
                });
                
                const data = await response.json();
                
                if (data.success) {
                    alert(data.message);
                    
                    // Disable form after successful submission
                    stars.forEach(s => s.style.pointerEvents = 'none');
                    document.getElementById('feedbackComment').disabled = true;
                    btn.innerHTML = '<i class="fas fa-check"></i> Thank You!';
                    btn.style.opacity = '0.6';
                } else {
                    throw new Error(data.message || 'Failed to submit feedback');
                }
            } catch (error) {
                alert('Error: ' + error.message);
                btn.disabled = false;
                btn.innerHTML = originalHTML;
            }
        }
        
        // Track My Rider button function
        function trackMyRider() {
            if (vehicleMarker && map) {
                const pos = vehicleMarker.getLatLng();
                map.setView(pos, 16, {
                    animate: true,
                    duration: 1
                });
                vehicleMarker.openPopup();
                
                // Add a pulse effect
                vehicleMarker.getElement()?.classList.add('pulse-once');
                setTimeout(() => {
                    vehicleMarker.getElement()?.classList.remove('pulse-once');
                }, 2000);
            }
        }
        
        // Initialize map
        <?php if ($result && !empty($result['customer_lat']) && !empty($result['customer_lng'])): ?>
        const customerLat = <?php echo $result['customer_lat']; ?>;
        const customerLng = <?php echo $result['customer_lng']; ?>;
        
        // Initialize map
        const map = L.map('deliveryMap').setView([customerLat, customerLng], 13);
        
        // Add tile layer
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '© OpenStreetMap contributors',
            maxZoom: 19
        }).addTo(map);
        
        // Custom marker icons - smaller, cleaner vector design
        const deliveryIcon = L.divIcon({
            html: '<div style="position: relative;"><div style="background: #10b981; color: white; width: 32px; height: 32px; border-radius: 50% 50% 50% 0; transform: rotate(-45deg); display: flex; align-items: center; justify-content: center; box-shadow: 0 2px 8px rgba(16,185,129,0.5); border: 2px solid white;"><i class="fas fa-map-marker-alt" style="transform: rotate(45deg); font-size: 16px;"></i></div></div>',
            className: 'custom-marker',
            iconSize: [32, 32],
            iconAnchor: [16, 32],
            popupAnchor: [0, -32]
        });
        
        const truckIcon = L.icon({
            iconUrl: 'assets/img/intransit_marker.gif',
            iconSize: [40, 40],
            iconAnchor: [20, 20],
            popupAnchor: [0, -20],
            className: 'truck-marker'
        });
        
        const storeIcon = L.divIcon({
            html: '<div style="position: relative;"><div style="background: #f59e0b; color: white; width: 32px; height: 32px; border-radius: 50% 50% 50% 0; transform: rotate(-45deg); display: flex; align-items: center; justify-content: center; box-shadow: 0 2px 8px rgba(245,158,11,0.5); border: 2px solid white;"><i class="fas fa-store" style="transform: rotate(45deg); font-size: 14px;"></i></div></div>',
            className: 'custom-marker',
            iconSize: [32, 32],
            iconAnchor: [16, 32],
            popupAnchor: [0, -32]
        });
        
        // Add destination marker
        const destMarker = L.marker([customerLat, customerLng], { icon: deliveryIcon })
            .addTo(map)
            .bindPopup('<b>Delivery Location</b><br><?php echo htmlspecialchars($result['customer_name']); ?>');
        
        // Add store marker (example coordinates - adjust as needed)
        const storeLat = 14.624105;
        const storeLng = 120.987809;
        L.marker([storeLat, storeLng], { icon: storeIcon })
            .addTo(map)
            .bindPopup('<b>RicePOS Store</b><br>Our main location');
        
        // Add delivery vehicle marker (if in transit)
        let vehicleMarker = null;
        <?php if ($deliveryStatus === 'in_transit' || $deliveryStatus === 'out_for_delivery'): ?>
            // Start with a position between store and customer
            const midLat = (storeLat + customerLat) / 2;
            const midLng = (storeLng + customerLng) / 2;
            vehicleMarker = L.marker([midLat, midLng], { icon: truckIcon })
                .addTo(map)
                .bindPopup('<b>Delivery Vehicle</b><br>En route to your location');
            
            // Fit bounds to show all markers
            const bounds = L.latLngBounds([
                [storeLat, storeLng],
                [customerLat, customerLng],
                [midLat, midLng]
            ]);
            map.fitBounds(bounds, { padding: [50, 50] });
            
            // Simulate live GPS updates (fetch from gps_get.php)
            function updateVehiclePosition() {
                fetch('gps_get.php?_=' + Date.now())
                    .then(res => res.json())
                    .then(data => {
                        if (data && data.lat && data.lng && !data.error) {
                            if (vehicleMarker) {
                                vehicleMarker.setLatLng([data.lat, data.lng]);
                                // Update ETA based on distance
                                const distance = map.distance([data.lat, data.lng], [customerLat, customerLng]);
                                const distanceKm = (distance / 1000).toFixed(1);
                                const etaMins = Math.ceil(distance / 1000 * 3); // rough estimate
                                
                                // Update summary cards if they exist
                                const distanceCard = document.querySelector('.summary-card.green .summary-value');
                                const etaCard = document.querySelector('.summary-card.blue .summary-value');
                                if (distanceCard) distanceCard.textContent = distanceKm + ' km';
                                if (etaCard) etaCard.textContent = etaMins + ' mins';
                            }
                        }
                    })
                    .catch(err => console.log('GPS update failed:', err));
            }
            
            // Update every 10 seconds
            setInterval(updateVehiclePosition, 10000);
            updateVehiclePosition(); // Initial call
        <?php else: ?>
            // Just show store and destination
            const bounds = L.latLngBounds([
                [storeLat, storeLng],
                [customerLat, customerLng]
            ]);
            map.fitBounds(bounds, { padding: [50, 50] });
        <?php endif; ?>
        <?php endif; ?>
    </script>
</body>
</html>
