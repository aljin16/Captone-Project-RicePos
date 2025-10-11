<?php
session_start();
require_once __DIR__ . '/../classes/User.php';
require_once __DIR__ . '/../classes/Database.php';
$user = new User();
if (!$user->isLoggedIn()) {
    header('Location: index.php');
    exit;
}

// Redirect delivery staff to their own dashboard
if (isset($_SESSION['role']) && $_SESSION['role'] === 'delivery_staff') {
    header('Location: delivery_dashboard.php');
    exit;
}

$pdo = Database::getInstance()->getConnection();

// KPIs: total sales and transactions
$totalsStmt = $pdo->query("SELECT COALESCE(SUM(total_amount),0) AS total_sales, COUNT(*) AS txn_count FROM sales");
$totals = $totalsStmt->fetch();

// Daily, weekly, monthly summaries
$daily = $pdo->query("SELECT COALESCE(SUM(total_amount),0) AS total, COUNT(*) AS txns FROM sales WHERE DATE(datetime)=CURDATE()")->fetch();
$weekly = $pdo->query("SELECT COALESCE(SUM(total_amount),0) AS total, COUNT(*) AS txns FROM sales WHERE YEARWEEK(datetime,1)=YEARWEEK(CURDATE(),1)")->fetch();
$monthly = $pdo->query("SELECT COALESCE(SUM(total_amount),0) AS total, COUNT(*) AS txns FROM sales WHERE DATE_FORMAT(datetime,'%Y-%m')=DATE_FORMAT(CURDATE(),'%Y-%m')")->fetch();

// Sales trend (last 30 days)
$trendStmt = $pdo->query("SELECT DATE(datetime) as d, SUM(total_amount) as total FROM sales WHERE datetime >= CURDATE() - INTERVAL 29 DAY GROUP BY DATE(datetime) ORDER BY d");
$trend = $trendStmt->fetchAll();
// Normalize dates to include zeros
$trendMap = [];
foreach ($trend as $row) { $trendMap[$row['d']] = (float)$row['total']; }
$dates = [];$values = [];
for ($i=29; $i>=0; $i--) {
    $date = date('Y-m-d', strtotime("-{$i} days"));
    $dates[] = $date;
    $values[] = isset($trendMap[$date]) ? $trendMap[$date] : 0.0;
}

// DSS analytics derived from trend
$rolling7 = [];
for ($i = 0; $i < count($values); $i++) {
    $start = max(0, $i-6);
    $slice = array_slice($values, $start, $i-$start+1);
    $avg = count($slice) ? array_sum($slice)/count($slice) : 0.0;
    $rolling7[] = $avg;
}
$last7 = array_slice($values, -7);
$prev7 = array_slice($values, -14, 7);
$sum7 = array_sum($last7);
$sumPrev7 = array_sum($prev7);
$growth7 = ($sumPrev7 > 0) ? (($sum7 - $sumPrev7) / $sumPrev7) * 100.0 : null;
// Peak day in last 30 days
$peakIdx = array_keys($values, max($values));
$peakIdx = $peakIdx ? $peakIdx[0] : null;
$peakDate = $peakIdx !== null ? $dates[$peakIdx] : null;
$peakAmount = $peakIdx !== null ? $values[$peakIdx] : 0;

// AOV (last 30 days)
$aovRow = $pdo->query("SELECT COALESCE(SUM(total_amount),0) AS total, COUNT(*) AS txns FROM sales WHERE datetime >= CURDATE() - INTERVAL 30 DAY")->fetch();
$aov = ($aovRow && (int)$aovRow['txns'] > 0) ? ((float)$aovRow['total'] / (int)$aovRow['txns']) : 0.0;

// Yesterday and averages
$yesterday = $pdo->query("SELECT COALESCE(SUM(total_amount),0) AS total, COUNT(*) AS txns FROM sales WHERE DATE(datetime)=CURDATE()-INTERVAL 1 DAY")->fetch();
$avg7 = $sum7 / 7.0;
$avg30 = array_sum($values) / 30.0;
$projMonth = $avg30 * (int)date('t');

// Inventory risk summary
$risk = ['zero' => 0, 'low' => 0];
try {
    $riskRow = $pdo->query("SELECT SUM(CASE WHEN stock_sack <= 0 THEN 1 ELSE 0 END) AS zero_cnt, SUM(CASE WHEN stock_sack > 0 AND stock_sack <= low_stock_threshold THEN 1 ELSE 0 END) AS low_cnt FROM products")->fetch();
    if ($riskRow) { $risk['zero'] = (int)$riskRow['zero_cnt']; $risk['low'] = (int)$riskRow['low_cnt']; }
} catch (\Throwable $e) { /* ignore if table missing */ }

// Sales by category (last 30 days)
// Sales by product (last 30 days) – top slices for cleaner pie
// Allow range chips (7/30/90 days) for the product donuts
$prodRangeDays = isset($_GET['prod_range']) && in_array((int)$_GET['prod_range'], [7,30,90], true)
    ? (int)$_GET['prod_range']
    : 30;
$prodStmt = $pdo->prepare("SELECT p.id, p.name, SUM(si.price) as total, p.profit_per_sack,
                         SUM(si.quantity_sack) AS sacks_sold
                         FROM sale_items si
                         JOIN sales s ON s.id=si.sale_id
                         JOIN products p ON p.id=si.product_id
                         WHERE s.datetime >= CURDATE() - INTERVAL :days DAY
                         GROUP BY p.id, p.name, p.profit_per_sack
                         ORDER BY total DESC");
$prodStmt->execute([':days' => $prodRangeDays]);
$prodRows = $prodStmt->fetchAll();
$topLimit = 8; $sumOthers = 0; $productLabels = []; $productValues = []; $productProfitValues = []; $sumOthersProfit = 0; $productSacks = []; $sumOthersSacks = 0;
foreach ($prodRows as $i => $r) {
    $grossProfit = isset($r['profit_per_sack']) && $r['profit_per_sack'] !== null ? ((int)$r['profit_per_sack']) * ((int)($r['sacks_sold'] ?? 0)) : 0;
    if ($i < $topLimit) { $productLabels[] = $r['name']; $productValues[] = (float)$r['total']; $productProfitValues[] = (float)$grossProfit; $productSacks[] = (int)($r['sacks_sold'] ?? 0); }
    else { $sumOthers += (float)$r['total']; $sumOthersProfit += (float)$grossProfit; $sumOthersSacks += (int)($r['sacks_sold'] ?? 0); }
}
if ($sumOthers > 0) { $productLabels[] = 'Others'; $productValues[] = $sumOthers; $productProfitValues[] = $sumOthersProfit; $productSacks[] = $sumOthersSacks; }

// Delivery status counts
$deliveryCounts = [
    'pending' => 0,
    'out_for_delivery' => 0,
    'delivered' => 0,
];
try {
    // Get all delivery statuses and map them correctly
    $delStmt = $pdo->query("SELECT status, COUNT(*) AS cnt FROM delivery_orders GROUP BY status");
    $allStatuses = $delStmt->fetchAll();
    
    // Debug: Uncomment the line below to see actual status values in your database
    // error_log("Delivery statuses in DB: " . print_r($allStatuses, true));
    
    foreach ($allStatuses as $row) {
        $status = $row['status'];
        $count = (int)$row['cnt'];
        
        // Map various status values to our display categories
        if ($status === 'pending') {
            $deliveryCounts['pending'] += $count;
        } elseif (in_array($status, ['out_for_delivery', 'in_transit', 'picked_up'], true)) {
            // All "in transit" variations go to out_for_delivery count
            $deliveryCounts['out_for_delivery'] += $count;
        } elseif ($status === 'delivered') {
            $deliveryCounts['delivered'] += $count;
        }
        // Note: 'failed' and 'cancelled' are not displayed on dashboard
    }
} catch (\Throwable $e) {
    // table may not exist yet; keep zeros
}

// Top 5 best-selling items (by revenue, last 30 days)
$topStmt = $pdo->query("SELECT p.name, SUM(si.price) as revenue
                        FROM sale_items si
                        JOIN sales s ON s.id=si.sale_id
                        JOIN products p ON p.id=si.product_id
                        WHERE s.datetime >= CURDATE() - INTERVAL 30 DAY
                        GROUP BY p.id, p.name
                        ORDER BY revenue DESC
                        LIMIT 5");
$topRows = $topStmt->fetchAll();

// Staff performance removed to streamline dashboard
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RicePOS Dashboard</title>
    <?php $cssVer = @filemtime(__DIR__ . '/assets/css/style.css') ?: '1'; ?>
    <link rel="stylesheet" href="assets/css/style.css?v=<?php echo htmlspecialchars((string)$cssVer, ENT_QUOTES); ?>">
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="manifest" href="manifest.webmanifest">
    <meta name="theme-color" content="#2d6cdf">
    
    <!-- Modern Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    
    <style>
    /* Modern Clean Design System */
    :root {
        --ink: #0f172a;
        --ink-light: #1e293b;
        --muted: #64748b;
        --muted-light: #94a3b8;
        --bg: #f8fafc;
        --card: #ffffff;
        --border: #e2e8f0;
        --brand: #3b82f6;
        --brand-light: #60a5fa;
        --brand-dark: #2563eb;
        --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
        --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.08), 0 2px 4px -2px rgba(0, 0, 0, 0.04);
        --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.08), 0 4px 6px -4px rgba(0, 0, 0, 0.04);
        --shadow-hover: 0 20px 25px -5px rgba(0, 0, 0, 0.08), 0 8px 10px -6px rgba(0, 0, 0, 0.04);
        --radius: 1rem;
        --radius-lg: 1.25rem;
        --spacing: 1.5rem;
    }
    
    *, *::before, *::after {
        box-sizing: border-box;
        margin: 0;
        padding: 0;
    }
    
    html, body {
        height: 100%;
        font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
        -webkit-font-smoothing: antialiased;
        -moz-osx-font-smoothing: grayscale;
    }
    
    body {
        display: block;
        min-height: 100vh;
        margin: 0;
        background: 
            linear-gradient(135deg, rgba(59, 130, 246, 0.03) 0%, transparent 50%),
            linear-gradient(225deg, rgba(6, 182, 212, 0.03) 0%, transparent 50%),
            linear-gradient(135deg, #f8fafc 0%, #e0f2fe 50%, #f0f9ff 100%);
        background-attachment: fixed;
        color: var(--ink);
        overflow-x: hidden;
        font-size: 0.9375rem;
        line-height: 1.6;
        position: relative;
    }
    
    body::before {
        content: '';
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background-image: 
            radial-gradient(circle at 20% 50%, rgba(59, 130, 246, 0.05) 0%, transparent 50%),
            radial-gradient(circle at 80% 80%, rgba(6, 182, 212, 0.05) 0%, transparent 50%),
            radial-gradient(circle at 40% 20%, rgba(147, 197, 253, 0.03) 0%, transparent 50%);
        pointer-events: none;
        z-index: 0;
    }
    
    /* Main Content Layout */
    .main-content {
        padding: 2rem;
        max-width: 1600px;
        margin: 0 auto;
        position: relative;
        z-index: 1;
    }
    
    /* Performance Overview - Combined Card */
    .performance-overview-card {
        background: linear-gradient(135deg, #ffffff 0%, #f0f9ff 100%);
        border: 1px solid rgba(147, 197, 253, 0.3);
        border-radius: var(--radius-lg);
        padding: 2rem;
        box-shadow: 0 4px 6px -1px rgba(59, 130, 246, 0.1), 0 2px 4px -2px rgba(59, 130, 246, 0.06);
        margin-bottom: var(--spacing);
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        position: relative;
        overflow: hidden;
    }
    
    .performance-overview-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 4px;
        background: linear-gradient(90deg, #3b82f6, #06b6d4, #3b82f6);
        background-size: 200% 100%;
        animation: shimmer 3s linear infinite;
    }
    
    @keyframes shimmer {
        0% { background-position: -200% 0; }
        100% { background-position: 200% 0; }
    }
    
    .performance-overview-card:hover {
        box-shadow: 0 10px 15px -3px rgba(59, 130, 246, 0.15), 0 4px 6px -4px rgba(59, 130, 246, 0.1);
        transform: translateY(-2px);
    }
    
    .overview-header {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        margin-bottom: 1.75rem;
        padding-bottom: 1rem;
        border-bottom: 2px solid var(--border);
    }
    
    .overview-header i {
        font-size: 1.5rem;
        color: var(--brand);
    }
    
    .overview-header h3 {
        margin: 0;
        font-size: 1.25rem;
        font-weight: 700;
        color: var(--ink);
        letter-spacing: -0.02em;
    }
    
    .overview-grid {
        display: grid;
        grid-template-columns: 1fr auto 1fr;
        gap: 2.5rem;
        align-items: start;
    }
    
    .overview-section {
        display: flex;
        flex-direction: column;
        gap: 1.25rem;
    }
    
    .section-header {
        display: flex;
        align-items: center;
        gap: 0.75rem;
    }
    
    .section-icon {
        width: 40px;
        height: 40px;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 20px;
        color: white;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
    }
    
    .sales-icon {
        background: linear-gradient(135deg, #60a5fa, #3b82f6);
        box-shadow: 0 4px 14px rgba(59, 130, 246, 0.4);
    }
    
    .delivery-icon {
        background: linear-gradient(135deg, #22d3ee, #06b6d4);
        box-shadow: 0 4px 14px rgba(6, 182, 212, 0.4);
    }
    
    .section-title {
        font-size: 0.875rem;
        font-weight: 600;
        color: var(--muted);
        text-transform: uppercase;
        letter-spacing: 0.05em;
    }
    
    .overview-divider {
        width: 1px;
        height: 100%;
        background: linear-gradient(to bottom, transparent, var(--border) 10%, var(--border) 90%, transparent);
        align-self: stretch;
    }
    
    .metrics-list {
        display: flex;
        flex-direction: column;
        gap: 1rem;
    }
    
    .metric-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 0.75rem 0;
        border-bottom: 1px solid #f1f5f9;
        transition: all 0.3s ease;
    }
    
    .metric-row:last-child {
        border-bottom: none;
    }
    
    .metric-row:hover {
        background: linear-gradient(135deg, #f0f9ff, #f8fafc);
        padding-left: 0.75rem;
        padding-right: 0.75rem;
        margin: 0 -0.75rem;
        border-radius: 0.5rem;
        box-shadow: 0 2px 6px rgba(59, 130, 246, 0.08);
    }
    
    .metric-label {
        font-size: 0.9375rem;
        font-weight: 500;
        color: var(--ink-light);
    }
    
    .metric-value-group {
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }
    
    .metric-value {
        font-size: 1.125rem;
        font-weight: 700;
        color: var(--ink);
    }
    
    .metric-count {
        font-size: 0.875rem;
        font-weight: 500;
        color: var(--muted-light);
    }
    
    .delivery-badge {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 44px;
        padding: 0.375rem 0.875rem;
        border-radius: 9999px;
        font-size: 0.875rem;
        font-weight: 700;
        transition: all 0.3s ease;
    }
    
    .delivery-badge:hover {
        transform: scale(1.05);
    }
    
    .delivery-badge.badge-pending {
        background: linear-gradient(135deg, #fee2e2, #fecaca);
        color: #991b1b;
        border: 1px solid #f87171;
        box-shadow: 0 2px 8px rgba(239, 68, 68, 0.2);
    }
    
    .delivery-badge.badge-transit {
        background: linear-gradient(135deg, #dbeafe, #bfdbfe);
        color: #1e40af;
        border: 1px solid #60a5fa;
        box-shadow: 0 2px 8px rgba(59, 130, 246, 0.2);
    }
    
    .delivery-badge.badge-delivered {
        background: linear-gradient(135deg, #d1fae5, #a7f3d0);
        color: #166534;
        border: 1px solid #6ee7b7;
        box-shadow: 0 2px 8px rgba(34, 197, 94, 0.2);
    }
    
    /* Sales Metric Row Enhancements */
    .sales-metric-row {
        padding: 1rem 0.75rem !important;
        border-bottom: 1px solid #dbeafe !important;
        background: linear-gradient(135deg, rgba(255, 255, 255, 0.5), rgba(239, 246, 255, 0.3));
        border-radius: 0.75rem !important;
        margin-bottom: 0.75rem !important;
    }
    
    .sales-metric-row:last-child {
        margin-bottom: 0 !important;
    }
    
    .sales-metric-row:hover {
        background: linear-gradient(135deg, #eff6ff, #dbeafe) !important;
        box-shadow: 0 4px 12px rgba(59, 130, 246, 0.15) !important;
        transform: translateX(4px);
    }
    
    /* Sales Period Icons */
    .sales-period-icon {
        width: 36px;
        height: 36px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 18px;
        color: white;
        transition: all 0.3s ease;
    }
    
    .today-icon {
        background: linear-gradient(135deg, #60a5fa, #3b82f6);
        box-shadow: 0 4px 12px rgba(59, 130, 246, 0.4);
        animation: today-pulse 2s ease-in-out infinite;
    }
    
    @keyframes today-pulse {
        0%, 100% {
            transform: scale(1);
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.4);
        }
        50% {
            transform: scale(1.05);
            box-shadow: 0 6px 16px rgba(59, 130, 246, 0.6);
        }
    }
    
    .week-icon {
        background: linear-gradient(135deg, #8b5cf6, #7c3aed);
        box-shadow: 0 4px 12px rgba(139, 92, 246, 0.4);
        animation: week-rotate 3s ease-in-out infinite;
    }
    
    @keyframes week-rotate {
        0%, 100% {
            transform: rotate(0deg);
        }
        50% {
            transform: rotate(5deg);
        }
    }
    
    .month-icon {
        background: linear-gradient(135deg, #06b6d4, #0891b2);
        box-shadow: 0 4px 12px rgba(6, 182, 212, 0.4);
        animation: month-flip 4s ease-in-out infinite;
    }
    
    @keyframes month-flip {
        0%, 100% {
            transform: rotateY(0deg);
        }
        50% {
            transform: rotateY(180deg);
        }
    }
    
    .sales-metric-row:hover .sales-period-icon {
        transform: scale(1.15);
    }
    
    .sales-metric-row:hover .today-icon {
        animation: today-pulse-fast 1s ease-in-out infinite;
    }
    
    @keyframes today-pulse-fast {
        0%, 100% {
            transform: scale(1.15);
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.4);
        }
        50% {
            transform: scale(1.25);
            box-shadow: 0 8px 20px rgba(59, 130, 246, 0.6);
        }
    }
    
    /* Delivery Metric Row Enhancements */
    .delivery-metric-row {
        padding: 1rem 0.75rem !important;
        border-bottom: 1px solid #e0f2fe !important;
        background: linear-gradient(135deg, rgba(255, 255, 255, 0.5), rgba(240, 249, 255, 0.3));
        border-radius: 0.75rem !important;
        margin-bottom: 0.75rem !important;
    }
    
    .delivery-metric-row:last-child {
        margin-bottom: 0 !important;
    }
    
    .delivery-metric-row:hover {
        background: linear-gradient(135deg, #f0f9ff, #e0f2fe) !important;
        box-shadow: 0 4px 12px rgba(59, 130, 246, 0.15) !important;
        transform: translateX(4px);
    }
    
    .metric-label-with-icon {
        display: flex;
        align-items: center;
        gap: 0.75rem;
    }
    
    /* Delivery Status Icons */
    .delivery-status-icon {
        width: 36px;
        height: 36px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 18px;
        color: white;
        transition: all 0.3s ease;
    }
    
    .pending-icon {
        background: linear-gradient(135deg, #fb923c, #f97316);
        box-shadow: 0 4px 12px rgba(251, 146, 60, 0.4);
        animation: pulse-pending 2s ease-in-out infinite;
    }
    
    @keyframes pulse-pending {
        0%, 100% {
            transform: scale(1);
            box-shadow: 0 4px 12px rgba(251, 146, 60, 0.4);
        }
        50% {
            transform: scale(1.05);
            box-shadow: 0 6px 16px rgba(251, 146, 60, 0.6);
        }
    }
    
    .transit-icon {
        background: transparent;
        box-shadow: none;
        animation: truck-move 3s ease-in-out infinite;
        overflow: visible;
        position: relative;
        border: none;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    
    /* Truck GIF Styling */
    .truck-gif {
        width: 40px;
        height: 40px;
        object-fit: contain;
        pointer-events: none;
        filter: drop-shadow(0 2px 4px rgba(0, 0, 0, 0.15));
        transition: all 0.3s ease;
    }
    
    @keyframes truck-move {
        0%, 100% {
            transform: translateX(0px);
        }
        25% {
            transform: translateX(3px);
        }
        50% {
            transform: translateX(-3px) rotate(-2deg);
        }
        75% {
            transform: translateX(3px) rotate(2deg);
        }
    }
    
    .transit-icon i {
        animation: truck-bounce 1s ease-in-out infinite;
    }
    
    @keyframes truck-bounce {
        0%, 100% {
            transform: translateY(0px);
        }
        50% {
            transform: translateY(-2px);
        }
    }
    
    .delivered-icon {
        background: linear-gradient(135deg, #34d399, #10b981);
        box-shadow: 0 4px 12px rgba(34, 197, 94, 0.4);
        animation: check-pulse 2s ease-in-out infinite;
    }
    
    @keyframes check-pulse {
        0%, 100% {
            transform: scale(1) rotate(0deg);
        }
        50% {
            transform: scale(1.1) rotate(5deg);
        }
    }
    
    .delivery-metric-row:hover .delivery-status-icon {
        transform: scale(1.15);
    }
    
    .delivery-metric-row:hover .transit-icon {
        animation: truck-move-fast 1s ease-in-out infinite;
    }
    
    .delivery-metric-row:hover .truck-gif {
        filter: drop-shadow(0 4px 8px rgba(59, 130, 246, 0.4));
        transform: scale(1.15);
    }
    
    @keyframes truck-move-fast {
        0%, 100% {
            transform: translateX(0px) scale(1.15);
        }
        25% {
            transform: translateX(5px) scale(1.15);
        }
        50% {
            transform: translateX(-5px) rotate(-3deg) scale(1.15);
        }
        75% {
            transform: translateX(5px) rotate(3deg) scale(1.15);
        }
    }
    
    /* Badge Count Animation */
    .badge-count {
        display: inline-block;
        animation: count-fade-in 0.5s ease-out;
    }
    
    @keyframes count-fade-in {
        from {
            opacity: 0;
            transform: scale(0.8);
        }
        to {
            opacity: 1;
            transform: scale(1);
        }
    }
    
    .delivery-badge:hover .badge-count {
        animation: count-bounce 0.5s ease;
    }
    
    @keyframes count-bounce {
        0%, 100% {
            transform: scale(1);
        }
        50% {
            transform: scale(1.2);
        }
    }
    
    /* KPI Grid - Top Metrics */
    .kpi-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
        gap: var(--spacing);
        margin-bottom: var(--spacing);
    }
    
    .kpi-card {
        background: var(--card);
        border: 1px solid var(--border);
        border-radius: var(--radius-lg);
        padding: 1.75rem;
        box-shadow: var(--shadow-md);
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        position: relative;
        overflow: hidden;
    }
    
    .kpi-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 3px;
        background: linear-gradient(90deg, var(--brand), var(--brand-light));
        opacity: 0;
        transition: opacity 0.3s ease;
    }
    
    .kpi-card:hover {
        transform: translateY(-4px);
        box-shadow: var(--shadow-hover);
        border-color: var(--brand-light);
    }
    
    .kpi-card:hover::before {
        opacity: 1;
    }
    
    .kpi-header {
        display: flex;
        align-items: center;
        gap: 1rem;
        margin-bottom: 1.25rem;
    }
    
    .kpi-icon {
        width: 48px;
        height: 48px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 22px;
        background: linear-gradient(135deg, var(--brand), var(--brand-light));
        color: white;
        box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
    }
    
    .kpi-title {
        color: var(--muted);
        font-size: 0.875rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.05em;
    }
    
    .kpi-value {
        font-size: 1.125rem;
        font-weight: 600;
        color: var(--ink);
        line-height: 1.8;
    }
    
    .kpi-value strong {
        font-weight: 700;
        color: var(--ink);
    }
    
    .kpi-value .muted {
        color: var(--muted-light);
        font-weight: 500;
        font-size: 0.875rem;
    }
    
    /* Delivery Badges */
    .badge {
        display: inline-flex;
        align-items: center;
        padding: 0.5rem 1rem;
        border-radius: 9999px;
        font-size: 0.8125rem;
        font-weight: 600;
        margin: 0.25rem;
        border: none;
        transition: all 0.3s ease;
    }
    
    .badge-pending {
        background: #fef2f2;
        color: #991b1b;
        border: 1px solid #fecaca;
    }
    
    .badge-transit {
        background: #eff6ff;
        color: #1e40af;
        border: 1px solid #bfdbfe;
    }
    
    .badge-delivered {
        background: #f0fdf4;
        color: #166534;
        border: 1px solid #bbf7d0;
    }
    
    .badge:hover {
        transform: translateY(-1px);
        box-shadow: var(--shadow-sm);
    }
    
    /* Section Grid */
    .section-grid {
        display: grid;
        grid-template-columns: 1.2fr 1fr;
        gap: var(--spacing);
        margin-bottom: var(--spacing);
    }
    
    .left-col {
        display: flex;
        flex-direction: column;
        gap: var(--spacing);
    }
    
    .left-subgrid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
        gap: var(--spacing);
    }
    
    /* Card Styles */
    .card {
        background: linear-gradient(135deg, #ffffff 0%, #fefefe 100%);
        border: 1px solid var(--border);
        border-radius: var(--radius-lg);
        padding: 1.75rem;
        box-shadow: var(--shadow-md);
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        height: 100%;
        position: relative;
    }
    
    .card::after {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        border-radius: var(--radius-lg);
        background: linear-gradient(135deg, rgba(59, 130, 246, 0.02) 0%, rgba(6, 182, 212, 0.02) 100%);
        opacity: 0;
        transition: opacity 0.3s ease;
        pointer-events: none;
    }
    
    .card:hover {
        box-shadow: var(--shadow-lg);
        transform: translateY(-2px);
    }
    
    .card:hover::after {
        opacity: 1;
    }
    
    .card h4 {
        margin: 0 0 1.25rem 0;
        font-size: 1.125rem;
        font-weight: 700;
        background: linear-gradient(135deg, #0f172a, #3b82f6);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
        letter-spacing: -0.01em;
        position: relative;
        z-index: 1;
    }
    
    /* List Styles */
    .list {
        list-style: none;
        padding: 0;
        margin: 0;
    }
    
    .list li {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 0.875rem 0;
        border-bottom: 1px solid var(--border);
        transition: all 0.3s ease;
    }
    
    .list li:last-child {
        border-bottom: none;
    }
    
    .list li:hover {
        background: linear-gradient(135deg, #f0f9ff, #f8fafc);
        padding-left: 0.5rem;
        padding-right: 0.5rem;
        margin: 0 -0.5rem;
        border-radius: 0.5rem;
        box-shadow: 0 1px 3px rgba(59, 130, 246, 0.1);
    }
    
    .list li span:first-child {
        color: var(--ink-light);
        font-weight: 500;
    }
    
    .list li strong {
        font-weight: 700;
        color: var(--ink);
    }
    
    .muted {
        color: var(--muted);
        font-size: 0.875rem;
        line-height: 1.6;
    }
    
    /* Chips & Metrics */
    .chips {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
        gap: 0.75rem;
        margin-top: 1rem;
    }
    
    .chip {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        padding: 0.625rem 1rem;
        border-radius: 0.75rem;
        font-weight: 600;
        font-size: 0.8125rem;
        border: 1px solid var(--border);
        background: var(--card);
        transition: all 0.3s ease;
        box-shadow: var(--shadow-sm);
    }
    
    .chip:hover {
        transform: translateY(-2px);
        box-shadow: var(--shadow-md);
    }
    
    .chip .icon {
        display: inline-flex;
        width: 20px;
        height: 20px;
        align-items: center;
        justify-content: center;
        border-radius: 50%;
        color: #fff;
        font-size: 11px;
        font-weight: 700;
    }
    
    .chip.up {
        background: linear-gradient(135deg, #ecfdf5, #d1fae5);
        border-color: #6ee7b7;
        box-shadow: 0 2px 8px rgba(34, 197, 94, 0.15);
    }
    
    .chip.up .icon {
        background: linear-gradient(135deg, #10b981, #059669);
        box-shadow: 0 2px 4px rgba(34, 197, 94, 0.3);
    }
    
    .chip.up:hover {
        background: linear-gradient(135deg, #d1fae5, #a7f3d0);
    }
    
    .chip.down {
        background: linear-gradient(135deg, #fef2f2, #fecaca);
        border-color: #f87171;
        box-shadow: 0 2px 8px rgba(239, 68, 68, 0.15);
    }
    
    .chip.down .icon {
        background: linear-gradient(135deg, #ef4444, #dc2626);
        box-shadow: 0 2px 4px rgba(239, 68, 68, 0.3);
    }
    
    .chip.down:hover {
        background: linear-gradient(135deg, #fecaca, #fca5a5);
    }
    
    .chip.neutral {
        background: linear-gradient(135deg, #eff6ff, #dbeafe);
        border-color: #60a5fa;
        box-shadow: 0 2px 8px rgba(59, 130, 246, 0.15);
    }
    
    .chip.neutral .icon {
        background: linear-gradient(135deg, #3b82f6, #2563eb);
        box-shadow: 0 2px 4px rgba(59, 130, 246, 0.3);
    }
    
    .chip.neutral:hover {
        background: linear-gradient(135deg, #dbeafe, #bfdbfe);
    }
    
    /* Performance Chips */
    .perf-chip {
        display: inline-flex;
        padding: 0.5rem 1rem;
        border-radius: 0.75rem;
        border: 1px solid var(--border);
        background: linear-gradient(135deg, #ffffff, #f8fafc);
        font-weight: 600;
        font-size: 0.8125rem;
        cursor: pointer;
        transition: all 0.3s ease;
        text-decoration: none;
        color: var(--ink-light);
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
    }
    
    .perf-chip:hover {
        background: linear-gradient(135deg, #f0f9ff, #e0f2fe);
        border-color: var(--brand-light);
        color: var(--brand);
        transform: translateY(-2px);
        box-shadow: 0 4px 8px rgba(59, 130, 246, 0.2);
    }
    
    .perf-chip.active {
        background: linear-gradient(135deg, #dbeafe, #bfdbfe);
        border-color: #3b82f6;
        color: #1e40af;
        box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
    }
    
    /* Chart Container */
    canvas {
        margin: 0.5rem 0;
    }
    
    /* Responsive Design */
    @media (max-width: 1024px) {
        .section-grid {
            grid-template-columns: 1fr;
        }
        
        .overview-grid {
            gap: 2rem;
        }
    }
    
    @media (max-width: 768px) {
        .main-content {
            padding: 1rem;
        }
        
        .performance-overview-card {
            padding: 1.5rem;
        }
        
        .overview-header {
            margin-bottom: 1.25rem;
        }
        
        .overview-header h3 {
            font-size: 1.125rem;
        }
        
        .overview-grid {
            grid-template-columns: 1fr;
            gap: 1.5rem;
        }
        
        .overview-divider {
            width: 100%;
            height: 1px;
            background: linear-gradient(to right, transparent, var(--border) 10%, var(--border) 90%, transparent);
        }
        
        .metric-value {
            font-size: 1rem;
        }
        
        .delivery-status-icon,
        .sales-period-icon {
            width: 32px;
            height: 32px;
            font-size: 16px;
        }
        
        .truck-gif {
            width: 32px;
            height: 32px;
        }
        
        .delivery-metric-row,
        .sales-metric-row {
            padding: 0.875rem 0.625rem !important;
        }
        
        .metric-label-with-icon {
            gap: 0.5rem;
        }
        
        .kpi-grid {
            grid-template-columns: 1fr;
            gap: 1rem;
        }
        
        .section-grid {
            grid-template-columns: 1fr;
            gap: 1rem;
        }
        
        .left-subgrid {
            grid-template-columns: 1fr;
        }
        
        .chips {
            grid-template-columns: 1fr;
            gap: 0.5rem;
        }
        
        .card {
            padding: 1.25rem;
        }
        
        .kpi-card {
            padding: 1.25rem;
        }
    }
    </style>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
</head>
<body>
    <?php $activePage = 'dashboard.php'; $pageTitle = 'Dashboard'; include __DIR__ . '/../includes/sidebar.php'; include __DIR__ . '/../includes/header.php'; ?>
    <main class="main-content" id="main-content">
        
        <!-- Performance Overview - Combined Card -->
        <div class="performance-overview-card">
            <div class="overview-header">
                <i class='bx bx-bar-chart-alt-2'></i>
                <h3>Performance Overview</h3>
                </div>
            
            <div class="overview-grid">
                <!-- Sales Summary Section -->
                <div class="overview-section">
                    <div class="section-header">
                        <div class="section-icon sales-icon">
                            <i class='bx bx-wallet'></i>
                </div>
                        <span class="section-title">Sales Summary</span>
            </div>
                    <div class="metrics-list">
                        <div class="metric-row sales-metric-row">
                            <div class="metric-label-with-icon">
                                <div class="sales-period-icon today-icon">
                                    <i class='bx bx-calendar-event'></i>
                </div>
                                <span class="metric-label">Today</span>
                            </div>
                            <div class="metric-value-group">
                                <span class="metric-value">₱<?php echo number_format((float)$daily['total'], 2); ?></span>
                                <span class="metric-count">(<?php echo (int)$daily['txns']; ?>)</span>
                            </div>
                        </div>
                        <div class="metric-row sales-metric-row">
                            <div class="metric-label-with-icon">
                                <div class="sales-period-icon week-icon">
                                    <i class='bx bx-calendar-week'></i>
                                </div>
                                <span class="metric-label">This Week</span>
                            </div>
                            <div class="metric-value-group">
                                <span class="metric-value">₱<?php echo number_format((float)$weekly['total'], 2); ?></span>
                                <span class="metric-count">(<?php echo (int)$weekly['txns']; ?>)</span>
                            </div>
                        </div>
                        <div class="metric-row sales-metric-row">
                            <div class="metric-label-with-icon">
                                <div class="sales-period-icon month-icon">
                                    <i class='bx bx-calendar-alt'></i>
                                </div>
                                <span class="metric-label">This Month</span>
                            </div>
                            <div class="metric-value-group">
                                <span class="metric-value">₱<?php echo number_format((float)$monthly['total'], 2); ?></span>
                                <span class="metric-count">(<?php echo (int)$monthly['txns']; ?>)</span>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Vertical Divider -->
                <div class="overview-divider"></div>
                
                <!-- Deliveries Section -->
                <div class="overview-section">
                    <div class="section-header">
                        <div class="section-icon delivery-icon">
                            <i class='bx bx-package'></i>
                        </div>
                        <span class="section-title">Deliveries</span>
                    </div>
                    <div class="metrics-list">
                        <div class="metric-row delivery-metric-row">
                            <div class="metric-label-with-icon">
                                <div class="delivery-status-icon pending-icon">
                                    <i class='bx bx-time-five'></i>
                                </div>
                                <span class="metric-label">Pending</span>
                            </div>
                            <span class="delivery-badge badge-pending">
                                <span class="badge-count"><?php echo (int)$deliveryCounts['pending']; ?></span>
                            </span>
                        </div>
                        <div class="metric-row delivery-metric-row">
                            <div class="metric-label-with-icon">
                                <div class="delivery-status-icon transit-icon">
                                    <img src="assets/img/intransit_marker.gif" alt="Truck" class="truck-gif">
                                </div>
                                <span class="metric-label">In Transit</span>
                            </div>
                            <span class="delivery-badge badge-transit">
                                <span class="badge-count"><?php echo (int)$deliveryCounts['out_for_delivery']; ?></span>
                            </span>
                        </div>
                        <div class="metric-row delivery-metric-row">
                            <div class="metric-label-with-icon">
                                <div class="delivery-status-icon delivered-icon">
                                    <i class='bx bx-check-circle'></i>
                                </div>
                                <span class="metric-label">Delivered</span>
                            </div>
                            <span class="delivery-badge badge-delivered">
                                <span class="badge-count"><?php echo (int)$deliveryCounts['delivered']; ?></span>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="section-grid">
            <div class="left-col">
                <div class="card">
                <h4>Sales Trend (Last 30 Days)</h4>
                <canvas id="trendChart" height="140"></canvas>
                    <div class="chips">
                        <span class="chip <?php echo ($growth7!==null && $growth7>=0)?'up':'down'; ?>">
                            <span class="icon"><?php echo ($growth7!==null && $growth7>=0)? '▲':'▼'; ?></span>
                            7‑day: ₱<?php echo number_format($sum7,0); ?> (<?php echo ($growth7>=0?'+':''); echo number_format($growth7?:0,1); ?>%)
                        </span>
                        <span class="chip neutral"><span class="icon">★</span> Peak: <?php echo $peakDate ? htmlspecialchars(date('M d', strtotime($peakDate))) : '–'; ?> (₱<?php echo number_format($peakAmount,0); ?>)</span>
                        <span class="chip neutral"><span class="icon">Ø</span> Avg7: ₱<?php echo number_format($avg7,0); ?></span>
                        <span class="chip neutral"><span class="icon">Ø</span> Avg30: ₱<?php echo number_format($avg30,0); ?></span>
                        <span class="chip neutral"><span class="icon">→</span> Proj <?php echo date('M'); ?>: ₱<?php echo number_format($projMonth,0); ?></span>
                </div>
                </div>
                <div class="left-subgrid">
                    <div class="card">
                        <h4>Top 5 Best-selling Items (30 Days)</h4>
                        <ul class="list">
                        <?php foreach ($topRows as $row): ?>
                            <li>
                                <span><?php echo htmlspecialchars($row['name']); ?></span>
                                <span><strong>₱<?php echo number_format((float)$row['revenue'], 2); ?></strong></span>
                            </li>
                        <?php endforeach; ?>
                        </ul>
                    </div>
                    <div class="card">
                        <h4>Operational Alerts</h4>
                        <ul class="list">
                            <li><span>Out of stock items</span><span><strong><?php echo (int)$risk['zero']; ?></strong></span></li>
                            <li><span>Low stock (≤ threshold)</span><span><strong><?php echo (int)$risk['low']; ?></strong></span></li>
                            <li><span>Pending Deliveries</span><span><strong><?php echo (int)$deliveryCounts['pending']; ?></strong></span></li>
                        </ul>
                        <canvas id="opsChart" height="130" style="margin-top:0.4rem;"></canvas>
                        <div class="muted" style="margin-top:0.4rem;">Address alerts to avoid lost sales and delays.</div>
                    </div>
                </div>
            </div>
            <div class="card">
                <div style="display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap; margin-bottom: 1rem;">
                    <h4 style="margin: 0;">Sales by Product % (<?php echo (int)$prodRangeDays; ?> Days)</h4>
                    <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                        <?php
                        $rangeChips = [7=>'7d',30=>'30d',90=>'90d'];
                        foreach ($rangeChips as $d=>$label) {
                            $qs = http_build_query(array_merge($_GET, ['prod_range'=>$d]));
                            $active = ($d === $prodRangeDays) ? 'active' : '';
                            echo '<a class="perf-chip '.$active.'" href="?'.$qs.'#main-content">'.$label.'</a>';
                        }
                        ?>
                    </div>
                </div>
                <canvas id="productChart" height="140"></canvas>
                <div class="muted" style="margin-top: 1rem;">
                    <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>
                        Double‑ring: Outer = Revenue • Inner = Gross Profit.
                    <?php else: ?>
                        Use this to prioritize procurement and promotions; heavier slices drive more revenue.
                    <?php endif; ?>
                </div>
                <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>
                <div style="margin-top: 1rem; display: flex; gap: 1rem; align-items: center; flex-wrap: wrap; padding-top: 1rem; border-top: 1px solid var(--border);">
                    <span style="display: inline-flex; align-items: center; gap: 0.5rem;">
                        <span style="width: 14px; height: 14px; border-radius: 0.25rem; background: #93c5fd; display: inline-block; border: 1px solid #60a5fa;"></span>
                        <span style="font-size: 0.875rem; color: var(--ink-light);">Revenue (outer)</span>
                    </span>
                    <span style="display: inline-flex; align-items: center; gap: 0.5rem;">
                        <span style="width: 14px; height: 14px; border-radius: 0.25rem; background: #22c55e; display: inline-block; border: 1px solid #16a34a;"></span>
                        <span style="font-size: 0.875rem; color: var(--ink-light);">Gross Profit 🔒 (inner)</span>
                    </span>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        
    </main>
    <script>
    const dates = <?php echo json_encode($dates); ?>;
    const values = <?php echo json_encode($values); ?>;
    const productLabels = <?php echo json_encode($productLabels); ?>;
    const productValues = <?php echo json_encode($productValues); ?>;
    const productProfitValues = <?php echo (isset($_SESSION['role']) && $_SESSION['role'] === 'admin') ? json_encode($productProfitValues) : 'null'; ?>;
    const productSacks = <?php echo json_encode($productSacks); ?>;

    // Trend chart: bars + 7-day moving average line
    const trendCtx = document.getElementById('trendChart');
    if (trendCtx) {
        const niceLabels = dates.map(d => (new Date(d+'T00:00:00')).toLocaleDateString(undefined, { month: 'short', day: 'numeric' }));
        new Chart(trendCtx, {
            type: 'bar',
            data: { 
                labels: niceLabels, 
                datasets: [
                    { 
                        label: 'Sales (₱)', 
                        data: values, 
                        backgroundColor: 'rgba(96, 165, 250, 0.7)',
                        hoverBackgroundColor: 'rgba(59, 130, 246, 0.9)',
                        borderRadius: 8,
                        borderSkipped: false
                    },
                    { 
                        label: '7-day avg', 
                        data: <?php echo json_encode($rolling7); ?>, 
                        type: 'line', 
                        borderColor: '#2563eb', 
                        backgroundColor: 'transparent', 
                        tension: 0.4, 
                        pointRadius: 0,
                        borderWidth: 2
                    }
                ] 
            },
            options: { 
                responsive: true,
                maintainAspectRatio: true,
                plugins: { 
                    legend: { 
                        position: 'bottom',
                        labels: {
                            padding: 15,
                            font: { family: 'Inter, sans-serif', size: 12, weight: '600' },
                            color: '#64748b',
                            usePointStyle: true,
                            pointStyle: 'circle'
                        }
                    }, 
                    tooltip: { 
                        backgroundColor: 'rgba(15, 23, 42, 0.9)',
                        padding: 12,
                        titleColor: '#fff',
                        bodyColor: '#e2e8f0',
                        titleFont: { family: 'Inter, sans-serif', size: 13, weight: '600' },
                        bodyFont: { family: 'Inter, sans-serif', size: 12 },
                        borderColor: '#475569',
                        borderWidth: 1,
                        cornerRadius: 8,
                        callbacks: { 
                            label: ctx => ctx.dataset.type==='line' ? '7d avg: ₱' + ctx.parsed.y.toFixed(2) : '₱' + ctx.parsed.y.toFixed(2) 
                        } 
                    } 
                }, 
                scales: { 
                    x: { 
                        grid: { display: false },
                        border: { display: false },
                        ticks: { 
                            autoSkip: true, 
                            maxTicksLimit: 10,
                            font: { family: 'Inter, sans-serif', size: 11 },
                            color: '#94a3b8'
                        } 
                    }, 
                    y: { 
                        beginAtZero: true,
                        border: { display: false },
                        grid: { 
                            color: '#f1f5f9',
                            drawTicks: false
                        },
                        ticks: {
                            font: { family: 'Inter, sans-serif', size: 11 },
                            color: '#94a3b8',
                            padding: 8
                        }
                    } 
                } 
            }
        });
    }
    // Product percentage doughnut (revenue + profit ring for admins)
    const prodCtx = document.getElementById('productChart');
    if (prodCtx) {
        const baseColors = ['#60a5fa','#34d399','#f472b6','#fbbf24','#a78bfa','#f87171','#22c55e','#f59e0b','#67e8f9','#fde68a'];
        function shade(hex, percent) {
            const num = parseInt(hex.slice(1), 16);
            let r = (num >> 16) & 0xff, g = (num >> 8) & 0xff, b = num & 0xff;
            r = Math.min(255, Math.max(0, Math.round(r + (percent/100)*255)));
            g = Math.min(255, Math.max(0, Math.round(g + (percent/100)*255)));
            b = Math.min(255, Math.max(0, Math.round(b + (percent/100)*255)));
            const toHex = (v)=>('0'+v.toString(16)).slice(-2);
            return '#'+toHex(r)+toHex(g)+toHex(b);
        }
        const revenueColors = baseColors.slice(0, productLabels.length).map(c => shade(c, +15)); // lighter
        const profitColors  = baseColors.slice(0, productLabels.length).map(c => shade(c, -15)); // darker
        const datasets = [ { label: 'Revenue', data: productValues, backgroundColor: revenueColors } ];
        const isAdmin = Array.isArray(productProfitValues);
        if (isAdmin) {
            datasets.push({ label: 'Gross Profit 🔒', data: productProfitValues, backgroundColor: profitColors });
        }
        new Chart(prodCtx, {
            type: 'doughnut',
            data: { labels: productLabels, datasets },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: { 
                        position: 'bottom',
                        labels: {
                            padding: 15,
                            font: { family: 'Inter, sans-serif', size: 12, weight: '600' },
                            color: '#64748b',
                            usePointStyle: true,
                            pointStyle: 'circle',
                            boxWidth: 10,
                            boxHeight: 10
                        }
                    },
                    tooltip: {
                        backgroundColor: 'rgba(15, 23, 42, 0.95)',
                        padding: 14,
                        titleColor: '#fff',
                        bodyColor: '#e2e8f0',
                        titleFont: { family: 'Inter, sans-serif', size: 13, weight: '700' },
                        bodyFont: { family: 'Inter, sans-serif', size: 12, lineHeight: 1.6 },
                        borderColor: '#475569',
                        borderWidth: 1,
                        cornerRadius: 10,
                        callbacks: {
                            label: (ctx) => {
                                const idx = ctx.dataIndex;
                                const revenue = productValues[idx] || 0;
                                const profit = isAdmin ? (productProfitValues[idx] || 0) : null;
                                const sacks = productSacks[idx] || 0;
                                const dsLabel = ctx.dataset.label;
                                const val = ctx.parsed || 0;
                                const sum = (ctx.dataset.data || []).reduce((a,b)=>a+b,0) || 1;
                                const pct = (val/sum*100).toFixed(1);
                                const margin = isAdmin && revenue > 0 ? ((profit/revenue)*100).toFixed(1)+'%' : null;
                                let line = `${dsLabel} • ${ctx.label}: ₱${val.toFixed(2)} (${pct}%)`;
                                if (isAdmin) { line += `\nProfit: ₱${(profit||0).toFixed(2)} • Margin: ${margin||'0%'} • Sacks: ${sacks}`; }
                                else { line += `\nSacks: ${sacks}`; }
                                return line;
                            }
                        }
                    }
                },
                cutout: isAdmin ? '55%' : '60%'
            },
            plugins: [{
                id: 'centerLabels',
                afterDraw(chart, args, opts) {
                    const { ctx, chartArea: { width, height } } = chart;
                    const cx = chart.getDatasetMeta(0).data[0]?.x || chart.width/2;
                    const cy = chart.getDatasetMeta(0).data[0]?.y || chart.height/2;
                    ctx.save();
                    ctx.textAlign = 'center';
                    ctx.fillStyle = '#0f172a';
                    const totalRevenue = productValues.reduce((a,b)=>a+b,0);
                    if (isAdmin) {
                        ctx.font = '600 13px Inter, sans-serif';
                        ctx.fillStyle = '#64748b';
                        ctx.fillText('Revenue', cx, cy - 10);
                        ctx.font = '700 17px Inter, sans-serif';
                        ctx.fillStyle = '#0f172a';
                        ctx.fillText('₱'+totalRevenue.toLocaleString(undefined,{maximumFractionDigits:0}), cx, cy + 10);
                        const totalProfit = productProfitValues.reduce((a,b)=>a+b,0);
                        ctx.fillStyle = '#16a34a';
                        ctx.font = '600 12px Inter, sans-serif';
                        ctx.fillText('Profit ₱'+totalProfit.toLocaleString(undefined,{maximumFractionDigits:0}), cx, cy + 28);
                    } else {
                        ctx.font = '600 13px Inter, sans-serif';
                        ctx.fillStyle = '#64748b';
                        ctx.fillText('Revenue', cx, cy - 8);
                        ctx.font = '700 18px Inter, sans-serif';
                        ctx.fillStyle = '#0f172a';
                        ctx.fillText('₱'+totalRevenue.toLocaleString(undefined,{maximumFractionDigits:0}), cx, cy + 12);
                    }
                    ctx.restore();
                }
            },{
                id: 'top3Labels',
                afterDatasetsDraw(chart, args, opts) {
                    const ds0 = chart.getDatasetMeta(0);
                    if (!ds0 || !ds0.data) return;
                    // Compute top 3 indices by revenue
                    const pairs = productValues.map((v,i)=>({v,i})).sort((a,b)=>b.v-a.v).slice(0,3);
                    const ctx = chart.ctx; 
                    ctx.save(); 
                    ctx.font = '600 11px Inter, sans-serif'; 
                    ctx.fillStyle = '#1e293b';
                    pairs.forEach(({i})=>{
                        const arc = ds0.data[i]; if (!arc) return;
                        const angle = (arc.startAngle + arc.endAngle)/2;
                        const r = (arc.outerRadius + arc.innerRadius)/2;
                        const x = arc.x + Math.cos(angle) * (r + 16);
                        const y = arc.y + Math.sin(angle) * (r + 16);
                        const label = productLabels[i] + ' ' + Math.round((productValues[i]/productValues.reduce((a,b)=>a+b,1))*100) + '%';
                        ctx.textAlign = angle > Math.PI/2 && angle < 3*Math.PI/2 ? 'end' : 'start';
                        ctx.fillText(label, x, y);
                    });
                    ctx.restore();
                }
            }]
        });

        // Hover sync: highlight both rings for same index
        prodCtx.addEventListener('mousemove', (evt) => {
            const chart = Chart.getChart(prodCtx);
            if (!chart) return;
            const points = chart.getElementsAtEventForMode(evt, 'nearest', { intersect: true }, true);
            if (points.length) {
                const idx = points[0].index;
                const active = isAdmin ? [{datasetIndex:0,index:idx},{datasetIndex:1,index:idx}] : [{datasetIndex:0,index:idx}];
                chart.setActiveElements(active);
                chart.update();
            } else {
                chart.setActiveElements([]);
                chart.update();
            }
        });
    }

    // Operational alerts mini-chart
    const opsCtx = document.getElementById('opsChart');
    if (opsCtx) {
        new Chart(opsCtx, {
            type: 'bar',
            data: {
                labels: ['Out of stock', 'Low stock', 'Pending deliveries'],
                datasets: [{ 
                    data: [<?php echo (int)$risk['zero']; ?>, <?php echo (int)$risk['low']; ?>, <?php echo (int)$deliveryCounts['pending']; ?>], 
                    backgroundColor: ['rgba(239, 68, 68, 0.7)', 'rgba(245, 158, 11, 0.7)', 'rgba(96, 165, 250, 0.7)'],
                    hoverBackgroundColor: ['rgba(239, 68, 68, 0.9)', 'rgba(245, 158, 11, 0.9)', 'rgba(96, 165, 250, 0.9)'],
                    borderRadius: 8,
                    borderSkipped: false
                }]
            },
            options: { 
                responsive: true,
                maintainAspectRatio: true,
                plugins: { 
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: 'rgba(15, 23, 42, 0.9)',
                        padding: 12,
                        titleColor: '#fff',
                        bodyColor: '#e2e8f0',
                        titleFont: { family: 'Inter, sans-serif', size: 13, weight: '600' },
                        bodyFont: { family: 'Inter, sans-serif', size: 12 },
                        borderColor: '#475569',
                        borderWidth: 1,
                        cornerRadius: 8
                    }
                }, 
                scales: { 
                    x: { 
                        grid: { display: false },
                        border: { display: false },
                        ticks: {
                            font: { family: 'Inter, sans-serif', size: 10 },
                            color: '#94a3b8'
                        }
                    }, 
                    y: { 
                        beginAtZero: true,
                        border: { display: false },
                        grid: {
                            color: '#f1f5f9',
                            drawTicks: false
                        },
                        ticks: { 
                            precision: 0,
                            font: { family: 'Inter, sans-serif', size: 11 },
                            color: '#94a3b8',
                            padding: 8
                        } 
                    } 
                } 
            }
        });
    }

    </script>
    <script src="assets/js/main.js"></script>
</body>
</html> 