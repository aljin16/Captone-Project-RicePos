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
    $delStmt = $pdo->query("SELECT status, COUNT(*) AS cnt FROM delivery_orders GROUP BY status");
    foreach ($delStmt->fetchAll() as $row) {
        $status = $row['status'];
        if (isset($deliveryCounts[$status])) { $deliveryCounts[$status] = (int)$row['cnt']; }
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
    <style>
    :root{ --ink:#111827; --muted:#6b7280; --bg:#f7f9fc; --card:#fff; --line:#e5e7eb; --brand:#2d6cdf; --brand-600:#1e4fa3; }
    *,*::before,*::after{ box-sizing:border-box; }
    html,body{ height:100%; }
    body { display: block; min-height: 100vh; margin: 0; background: var(--bg); color: var(--ink); overflow-x:hidden; }
    @media (max-width: 700px) { 
        .main-content { padding: 1.2rem 0.5rem 1.2rem 0.5rem; } 
        .section-grid { grid-template-columns: 1fr; gap: 0.6rem; }
        .kpi-grid { grid-template-columns: 1fr; gap: 0.6rem; }
    }

    /* DSS styles */
    .kpi-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 0.6rem; margin-bottom: 0.6rem; align-items: stretch; }
    .kpi-card { background:var(--card); border:1px solid var(--line); border-radius:12px; padding: 1rem; box-shadow: 0 2px 10px rgba(0,0,0,0.04); transition: transform 0.2s ease, box-shadow 0.2s ease; }
    .kpi-card:hover { transform: translateY(-2px); box-shadow: 0 4px 20px rgba(0,0,0,0.08); }
    .kpi-header { display: flex; align-items: center; gap: 0.75rem; margin-bottom: 0.5rem; }
    .kpi-icon { width: 40px; height: 40px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 20px; }
    .kpi-title { color:#6b7280; font-size: 0.9rem; font-weight: 600; }
    .kpi-value { font-size:1.6rem; font-weight:700; margin-top: 0.2rem; }
    
    /* KPI card color themes */
    .kpi-sales { background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%); border-color: #bae6fd; }
    .kpi-sales .kpi-icon { background: #0ea5e9; color: white; }
    
    .kpi-summary { background: linear-gradient(135deg, #fefce8 0%, #e0f2fe 100%); border-color: #fde68a; }
    .kpi-summary .kpi-icon { background: linear-gradient(135deg,#f59e0b,#2563eb); color: white; }
    
    .kpi-deliveries { background: linear-gradient(135deg, #eef2ff 0%, #e0f2fe 55%, #ecfeff 100%); border-color: #c7d2fe; }
    .kpi-deliveries .kpi-icon { background: linear-gradient(135deg, #2563eb, #06b6d4); color: #fff; }
    .kpi-deliveries .badge { border:1px solid transparent; }
    .kpi-deliveries .badge-pending { background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%); color:#7f1d1d; border-color:#fecaca; }
    .kpi-deliveries .badge-transit { background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%); color:#1e3a8a; border-color:#bfdbfe; }
    .kpi-deliveries .badge-delivered { background: linear-gradient(135deg, #dcfce7 0%, #bbf7d0 100%); color:#065f46; border-color:#bbf7d0; }
    .section-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 0.6rem; margin-bottom: 0.6rem; align-items: start; }
    .left-col { display:flex; flex-direction:column; gap:0.6rem; }
    .left-subgrid { display:grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 0.6rem; }
    .card { background:var(--card); border:1px solid var(--line); border-radius:12px; padding: 1rem; box-shadow: 0 2px 10px rgba(0,0,0,0.04); }
    .card h4 { margin: 0 0 0.5rem 0; font-size: 1.05rem; }
    .list { list-style: none; padding:0; margin:0; }
    .list li { display:flex; justify-content: space-between; padding: 0.5rem 0; border-bottom: 1px dashed #e5e7eb; }
    .list li:last-child { border-bottom: none; }
    .muted { color:#6b7280; }
    .chips { display:grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 0.35rem; margin-top: 0.3rem; }
    .chip { display:inline-flex; align-items:center; gap:0.35rem; padding:0.28rem 0.5rem; border-radius:999px; font-weight:600; font-size:0.82rem; border:1px solid var(--line); background:#f8fafc; }
    .chip .icon { display:inline-flex; width:18px; height:18px; align-items:center; justify-content:center; border-radius:50%; color:#fff; font-size:12px; }
    .chip.up .icon { background:#16a34a; }
    .chip.down .icon { background:#dc2626; }
    .chip.neutral .icon { background:#2563eb; }
    /* Staff performance panel */
    .perf-card { background:var(--card); border:1px solid var(--line); border-radius:12px; padding: 0.75rem; margin-top: 0.8rem; }
    .perf-head { display:flex; align-items:center; justify-content: space-between; gap:0.5rem; margin-bottom:0.5rem; }
    .perf-title { margin:0; font-size:1rem; font-weight:700; }
    .perf-date-chips { display:flex; flex-wrap:wrap; gap:0.35rem; }
    .perf-chip { padding:0.28rem 0.55rem; border-radius:999px; border:1px solid #e5e7eb; background:#fff; font-weight:600; font-size:0.82rem; cursor:pointer; }
    .perf-chip.active { background:#eef2ff; border-color:#c7d2fe; color:#1e40af; }
    .perf-table { width:100%; border-collapse: collapse; }
    .perf-table th, .perf-table td { padding: 8px 10px; border-bottom: 1px dashed #e5e7eb; text-align:left; font-size: 0.92rem; }
    .perf-table th { color:#6b7280; font-weight:700; }
    .perf-table tr:last-child td { border-bottom: none; }
    .pill { display:inline-block; padding:2px 8px; border-radius:999px; background:#f1f5f9; border:1px solid #e2e8f0; font-size:0.78rem; color:#334155; }

    
    </style>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
</head>
<body>
    <?php $activePage = 'dashboard.php'; $pageTitle = 'Dashboard'; include __DIR__ . '/../includes/sidebar.php'; include __DIR__ . '/../includes/header.php'; ?>
    <main class="main-content" id="main-content">
        
        <div class="kpi-grid">
            <div class="kpi-card kpi-summary">
                <div class="kpi-header">
                    <div class="kpi-icon"><i class='bx bx-calendar-star'></i></div>
                    <div class="kpi-title">Sales Summary</div>
                </div>
                <div class="kpi-value" style="font-size:1.05rem; line-height:1.6;">
                    <div>Today: <strong>₱<?php echo number_format((float)$daily['total'], 2); ?></strong> <span class="muted">(<?php echo (int)$daily['txns']; ?>)</span></div>
                    <div>This Week: <strong>₱<?php echo number_format((float)$weekly['total'], 2); ?></strong> <span class="muted">(<?php echo (int)$weekly['txns']; ?>)</span></div>
                    <div>This Month: <strong>₱<?php echo number_format((float)$monthly['total'], 2); ?></strong> <span class="muted">(<?php echo (int)$monthly['txns']; ?>)</span></div>
                </div>
            </div>
            <div class="kpi-card kpi-deliveries">
                <div class="kpi-header">
                    <div class="kpi-icon"><i class='bx bx-package'></i></div>
                    <div class="kpi-title">Deliveries</div>
                </div>
                <div class="kpi-value">
                    <span class="badge badge-pending">Pending: <?php echo (int)$deliveryCounts['pending']; ?></span>
                    <span class="badge badge-transit" style="margin-left:6px;">In Transit: <?php echo (int)$deliveryCounts['out_for_delivery']; ?></span>
                    <span class="badge badge-delivered" style="margin-left:6px;">Delivered: <?php echo (int)$deliveryCounts['delivered']; ?></span>
                </div>
            </div>
        </div>

        <div class="section-grid">
            <div class="left-col">
                <div class="card">
                <h4>Sales Trend (Last 30 Days)</h4>
                <canvas id="trendChart" height="140"></canvas>
                <div style="margin-top:0.5rem;">
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
                <div style="display:flex;align-items:center;justify-content:space-between;gap:0.6rem;flex-wrap:wrap;">
                    <h4 style="margin:0;">Sales by Product % (<?php echo (int)$prodRangeDays; ?> Days)</h4>
                    <div class="chips">
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
                <canvas id="productChart" height="130"></canvas>
                <div class="muted" style="margin-top:0.3rem;">
                    <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>
                        Double‑ring: Outer = Revenue • Inner = Gross Profit.
                    <?php else: ?>
                        Use this to prioritize procurement and promotions; heavier slices drive more revenue.
                    <?php endif; ?>
                </div>
                <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>
                <div style="margin-top:0.35rem;display:flex;gap:0.6rem;align-items:center;flex-wrap:wrap;">
                    <span style="display:inline-flex;align-items:center;gap:6px;">
                        <span style="width:12px;height:12px;border-radius:2px;background:#93c5fd;display:inline-block;border:1px solid #60a5fa;"></span>
                        <span>Revenue (outer)</span>
                    </span>
                    <span style="display:inline-flex;align-items:center;gap:6px;">
                        <span style="width:12px;height:12px;border-radius:2px;background:#22c55e;display:inline-block;border:1px solid #16a34a;"></span>
                        <span>Gross Profit 🔒 (inner)</span>
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
            data: { labels: niceLabels, datasets: [
                { label: 'Sales (₱)', data: values, backgroundColor: '#93c5fd', borderRadius: 6 },
                { label: '7-day avg', data: <?php echo json_encode($rolling7); ?>, type: 'line', borderColor: '#2563eb', backgroundColor: 'transparent', tension: 0.35, pointRadius: 0 }
            ] },
            options: { responsive: true, plugins: { legend: { position: 'bottom' }, tooltip: { callbacks: { label: ctx => ctx.dataset.type==='line' ? '7d avg: ₱' + ctx.parsed.y.toFixed(2) : '₱' + ctx.parsed.y.toFixed(2) } } }, scales: { x: { grid: { display: false }, ticks: { autoSkip: true, maxTicksLimit: 10 } }, y: { beginAtZero: true } } }
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
                plugins: {
                    legend: { position: 'bottom' },
                    tooltip: {
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
                    ctx.fillStyle = '#111827';
                    const totalRevenue = productValues.reduce((a,b)=>a+b,0);
                    if (isAdmin) {
                        ctx.font = '700 14px Segoe UI, Arial';
                        ctx.fillText('Revenue', cx, cy - 8);
                        ctx.font = '700 16px Segoe UI, Arial';
                        ctx.fillText('₱'+totalRevenue.toLocaleString(undefined,{maximumFractionDigits:0}), cx, cy + 10);
                        const totalProfit = productProfitValues.reduce((a,b)=>a+b,0);
                        ctx.fillStyle = '#166534';
                        ctx.font = '700 13px Segoe UI, Arial';
                        ctx.fillText('Profit ₱'+totalProfit.toLocaleString(undefined,{maximumFractionDigits:0}), cx, cy + 30);
                    } else {
                        ctx.font = '700 14px Segoe UI, Arial';
                        ctx.fillText('Revenue', cx, cy - 4);
                        ctx.font = '700 16px Segoe UI, Arial';
                        ctx.fillText('₱'+totalRevenue.toLocaleString(undefined,{maximumFractionDigits:0}), cx, cy + 14);
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
                    const ctx = chart.ctx; ctx.save(); ctx.font = '600 11px Segoe UI, Arial'; ctx.fillStyle = '#111827';
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
                datasets: [{ data: [<?php echo (int)$risk['zero']; ?>, <?php echo (int)$risk['low']; ?>, <?php echo (int)$deliveryCounts['pending']; ?>], backgroundColor: ['#ef4444','#f59e0b','#60a5fa'], borderRadius: 6 }]
            },
            options: { responsive: true, plugins: { legend: { display: false } }, scales: { x: { grid:{ display:false }}, y:{ beginAtZero:true, ticks:{ precision:0 } } } }
        });
    }

    </script>
    <script src="assets/js/main.js"></script>
</body>
</html> 