<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../classes/Database.php';
require_login();
require_delivery_staff();

$pdo = Database::getInstance()->getConnection();
$uid = $_SESSION['user_id'] ?? 0;

// Fetch delivery staff stats
$stats = [
    'assigned' => 0,
    'picked_up' => 0,
    'in_transit' => 0,
    'delivered_today' => 0,
    'pending' => 0,
    'failed' => 0
];

// Assigned to me
$stmt = $pdo->prepare("SELECT COUNT(*) FROM delivery_orders WHERE assigned_to = ?");
$stmt->execute([$uid]);
$stats['assigned'] = (int)$stmt->fetchColumn();

// By status for my deliveries
$stmt = $pdo->prepare("SELECT status, COUNT(*) as cnt FROM delivery_orders WHERE assigned_to = ? GROUP BY status");
$stmt->execute([$uid]);
foreach ($stmt->fetchAll() as $row) {
    $s = $row['status'];
    $cnt = (int)$row['cnt'];
    if ($s === 'picked_up') $stats['picked_up'] = $cnt;
    elseif ($s === 'in_transit') $stats['in_transit'] = $cnt;
    elseif ($s === 'pending') $stats['pending'] = $cnt;
    elseif ($s === 'failed') $stats['failed'] = $cnt;
}

// Delivered today
$stmt = $pdo->prepare("SELECT COUNT(*) FROM delivery_orders WHERE assigned_to = ? AND status = 'delivered' AND DATE(delivered_at) = CURDATE()");
$stmt->execute([$uid]);
$stats['delivered_today'] = (int)$stmt->fetchColumn();

// Recent deliveries (last 10)
$stmt = $pdo->prepare("SELECT d.id, d.customer_name, d.customer_address, d.status, d.created_at, s.transaction_id, s.total_amount
                       FROM delivery_orders d
                       JOIN sales s ON s.id = d.sale_id
                       WHERE d.assigned_to = ?
                       ORDER BY d.created_at DESC
                       LIMIT 10");
$stmt->execute([$uid]);
$recentDeliveries = $stmt->fetchAll();

// Unassigned deliveries (available to claim)
$stmt = $pdo->query("SELECT d.id, d.customer_name, d.customer_address, d.created_at, s.transaction_id, s.total_amount
                     FROM delivery_orders d
                     JOIN sales s ON s.id = d.sale_id
                     WHERE d.assigned_to IS NULL AND d.status = 'pending'
                     ORDER BY d.created_at DESC
                     LIMIT 5");
$availableDeliveries = $stmt->fetchAll();

// Today's completed deliveries value
$stmt = $pdo->prepare("SELECT COALESCE(SUM(s.total_amount), 0) as total
                       FROM delivery_orders d
                       JOIN sales s ON s.id = d.sale_id
                       WHERE d.assigned_to = ? AND d.status = 'delivered' AND DATE(d.delivered_at) = CURDATE()");
$stmt->execute([$uid]);
$todayValue = (float)$stmt->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Delivery Dashboard - RicePOS</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
    :root{ --brand:#2563eb; --success:#16a34a; --warn:#f59e0b; --danger:#dc2626; }
    .kpi-grid{ display:grid; grid-template-columns:repeat(auto-fit, minmax(180px,1fr)); gap:0.8rem; margin-bottom:1rem; }
    .kpi-card{ background:#fff; border:1px solid #e5e7eb; border-radius:14px; padding:1rem; box-shadow:0 4px 12px rgba(0,0,0,0.05); transition:transform 0.2s ease, box-shadow 0.2s ease; }
    .kpi-card:hover{ transform:translateY(-2px); box-shadow:0 8px 20px rgba(0,0,0,0.1); }
    .kpi-header{ display:flex; align-items:center; gap:0.6rem; margin-bottom:0.4rem; }
    .kpi-icon{ width:40px; height:40px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:20px; color:#fff; }
    .kpi-label{ font-size:0.85rem; color:#6b7280; font-weight:600; }
    .kpi-value{ font-size:1.8rem; font-weight:800; color:#111827; }
    .kpi-card.blue .kpi-icon{ background:linear-gradient(135deg,#3b82f6,#2563eb); }
    .kpi-card.green .kpi-icon{ background:linear-gradient(135deg,#22c55e,#16a34a); }
    .kpi-card.orange .kpi-icon{ background:linear-gradient(135deg,#fb923c,#f59e0b); }
    .kpi-card.red .kpi-icon{ background:linear-gradient(135deg,#f87171,#dc2626); }
    .kpi-card.gray .kpi-icon{ background:linear-gradient(135deg,#9ca3af,#6b7280); }
    .section-grid{ display:grid; grid-template-columns:1fr 1fr; gap:1rem; }
    @media(max-width:900px){ .section-grid{ grid-template-columns:1fr; } }
    .card{ background:#fff; border:1px solid #e5e7eb; border-radius:14px; padding:1rem; box-shadow:0 4px 12px rgba(0,0,0,0.05); }
    .card h3{ margin:0 0 0.8rem 0; font-size:1.1rem; color:#111827; }
    .delivery-item{ display:flex; justify-content:space-between; align-items:center; padding:0.6rem; border-bottom:1px solid #f3f4f6; }
    .delivery-item:last-child{ border-bottom:none; }
    .delivery-info{ flex:1; }
    .delivery-name{ font-weight:700; color:#111827; }
    .delivery-meta{ font-size:0.85rem; color:#6b7280; }
    .badge{ display:inline-block; padding:0.2rem 0.5rem; border-radius:6px; font-size:0.75rem; font-weight:600; }
    .badge.pending{ background:#fee2e2; color:#991b1b; }
    .badge.picked{ background:#fef3c7; color:#92400e; }
    .badge.transit{ background:#dbeafe; color:#1e40af; }
    .badge.delivered{ background:#dcfce7; color:#166534; }
    .badge.failed{ background:#fef2f2; color:#991b1b; }
    .btn-claim{ padding:0.4rem 0.8rem; background:#2563eb; color:#fff; border:none; border-radius:8px; font-weight:600; cursor:pointer; }
    .btn-claim:hover{ background:#1e40af; }
    .empty-state{ text-align:center; padding:2rem; color:#9ca3af; }
    </style>
</head>
<body>
    <?php $activePage = 'delivery_dashboard.php'; $pageTitle = 'Delivery Dashboard'; include __DIR__ . '/../includes/sidebar.php'; include __DIR__ . '/../includes/header.php'; ?>
    <script>
    // Auto-refresh every 30 seconds
    setTimeout(function(){ location.reload(); }, 30000);
    </script>
    <main class="main-content">
        <div class="kpi-grid">
            <div class="kpi-card blue">
                <div class="kpi-header">
                    <div class="kpi-icon"><i class='bx bx-package'></i></div>
                    <div class="kpi-label">Assigned</div>
                </div>
                <div class="kpi-value"><?php echo $stats['assigned']; ?></div>
            </div>
            <div class="kpi-card orange">
                <div class="kpi-header">
                    <div class="kpi-icon"><i class='bx bx-time'></i></div>
                    <div class="kpi-label">Pending</div>
                </div>
                <div class="kpi-value"><?php echo $stats['pending']; ?></div>
            </div>
            <div class="kpi-card blue">
                <div class="kpi-header">
                    <div class="kpi-icon"><i class='bx bx-car'></i></div>
                    <div class="kpi-label">In Transit</div>
                </div>
                <div class="kpi-value"><?php echo $stats['in_transit']; ?></div>
            </div>
            <div class="kpi-card green">
                <div class="kpi-header">
                    <div class="kpi-icon"><i class='bx bx-check-circle'></i></div>
                    <div class="kpi-label">Delivered Today</div>
                </div>
                <div class="kpi-value"><?php echo $stats['delivered_today']; ?></div>
            </div>
            <div class="kpi-card green">
                <div class="kpi-header">
                    <div class="kpi-icon"><i class='bx bx-money'></i></div>
                    <div class="kpi-label">Today's Value</div>
                </div>
                <div class="kpi-value">₱<?php echo number_format($todayValue, 0); ?></div>
            </div>
            <?php if ($stats['failed'] > 0): ?>
            <div class="kpi-card red">
                <div class="kpi-header">
                    <div class="kpi-icon"><i class='bx bx-error'></i></div>
                    <div class="kpi-label">Failed</div>
                </div>
                <div class="kpi-value"><?php echo $stats['failed']; ?></div>
            </div>
            <?php endif; ?>
        </div>

        <div class="section-grid">
            <div class="card">
                <h3>Recent Deliveries</h3>
                <?php if (count($recentDeliveries) > 0): ?>
                    <?php foreach ($recentDeliveries as $d): 
                        $badgeClass = $d['status']==='pending'?'pending':($d['status']==='picked_up'?'picked':($d['status']==='in_transit'?'transit':($d['status']==='delivered'?'delivered':'failed')));
                    ?>
                    <div class="delivery-item">
                        <div class="delivery-info">
                            <div class="delivery-name">#<?php echo $d['id']; ?> - <?php echo htmlspecialchars($d['customer_name']); ?></div>
                            <div class="delivery-meta"><?php echo htmlspecialchars($d['customer_address']); ?> • ₱<?php echo number_format((float)$d['total_amount'], 0); ?></div>
                            <div class="delivery-meta">TXN: <?php echo htmlspecialchars($d['transaction_id']); ?> • <?php echo htmlspecialchars($d['created_at']); ?></div>
                        </div>
                        <span class="badge <?php echo $badgeClass; ?>"><?php echo htmlspecialchars($d['status']); ?></span>
                    </div>
                    <?php endforeach; ?>
                    <div style="margin-top:0.8rem; text-align:center;">
                        <a href="delivery_staff.php" class="btn">View All Deliveries</a>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class='bx bx-package' style="font-size:3rem;"></i>
                        <p>No deliveries assigned yet</p>
                    </div>
                <?php endif; ?>
            </div>

            <div class="card">
                <h3>Available Deliveries</h3>
                <?php if (count($availableDeliveries) > 0): ?>
                    <?php foreach ($availableDeliveries as $d): ?>
                    <div class="delivery-item">
                        <div class="delivery-info">
                            <div class="delivery-name">#<?php echo $d['id']; ?> - <?php echo htmlspecialchars($d['customer_name']); ?></div>
                            <div class="delivery-meta"><?php echo htmlspecialchars($d['customer_address']); ?> • ₱<?php echo number_format((float)$d['total_amount'], 0); ?></div>
                            <div class="delivery-meta">TXN: <?php echo htmlspecialchars($d['transaction_id']); ?></div>
                        </div>
                        <form method="post" action="delivery_staff.php" style="display:inline;">
                            <input type="hidden" name="claim_id" value="<?php echo $d['id']; ?>">
                            <button type="submit" class="btn-claim">Claim</button>
                        </form>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-state">
                        <i class='bx bx-check-circle' style="font-size:3rem;"></i>
                        <p>No available deliveries to claim</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>
    <script src="assets/js/main.js"></script>
</body>
</html>

