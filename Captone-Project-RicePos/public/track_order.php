<?php
// Public order tracking by transaction ID
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../classes/Database.php';

$pdo = Database::getInstance()->getConnection();
$txn = isset($_GET['txn']) ? trim((string)$_GET['txn']) : '';
$result = null; $items = [];

if ($txn !== '') {
    // Find sale and delivery (no sensitive address exposed)
    $stmt = $pdo->prepare('SELECT s.id as sale_id, s.transaction_id, s.datetime, s.total_amount,
                                  d.id as delivery_id, d.status, d.assigned_to, d.notes, d.updated_at
                             FROM sales s
                        LEFT JOIN delivery_orders d ON d.sale_id = s.id
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Track Order<?php echo $txn?(' - '.htmlspecialchars($txn)) : ''; ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        body{ background:#f8fafc; }
        .track-wrap{ max-width:860px; margin:1rem auto; padding:1rem; }
        .card{ background:#fff; border:1px solid #e5e7eb; border-radius:14px; padding:1rem; box-shadow:0 2px 8px rgba(0,0,0,0.05); }
        .search{ display:flex; gap:0.5rem; }
        .search input{ flex:1 1 auto; height:46px; padding:0.65rem 1rem; border:1px solid #e5e7eb; border-radius:12px; font-size:1rem; }
        .search button{ height:46px; padding:0.65rem 1rem; border-radius:12px; border:1px solid #2563eb; background:#2563eb; color:#fff; font-weight:700; cursor:pointer; }
        .muted{ color:#6b7280; }
        .status{ display:inline-block; padding:0.25rem 0.6rem; border-radius:999px; font-weight:700; font-size:0.85rem; }
        .s-pending{ background:#fee2e2; color:#991b1b; }
        .s-picked_up{ background:#fef3c7; color:#92400e; }
        .s-in_transit{ background:#dbeafe; color:#1e40af; }
        .s-delivered{ background:#dcfce7; color:#166534; }
        .s-failed{ background:#fee2e2; color:#991b1b; }
        .items{ margin-top:0.8rem; border-top:1px dashed #e5e7eb; border-bottom:1px dashed #e5e7eb; padding:0.6rem 0; }
        .item{ display:grid; grid-template-columns: 1fr auto; gap:0.5rem; padding:0.25rem 0; }
        .item .sub{ color:#6b7280; font-size:0.86rem; }
        .grid{ display:grid; grid-template-columns: repeat(auto-fit, minmax(240px,1fr)); gap:0.8rem; }
    </style>
    <meta name="robots" content="noindex,nofollow">
</head>
<body>
    <div class="track-wrap">
        <div class="card" style="margin-bottom:0.8rem;">
            <form class="search" method="get" action="track_order.php" autocomplete="off">
                <input type="text" name="txn" placeholder="Enter transaction number (e.g. TXN2025...)" value="<?php echo htmlspecialchars($txn); ?>" required>
                <button type="submit">Track</button>
            </form>
            <div class="muted" style="margin-top:0.4rem;">No address shown. Only non-sensitive details.</div>
        </div>

        <?php if ($txn !== '' && !$result): ?>
            <div class="card">No order found for transaction <strong><?php echo htmlspecialchars($txn); ?></strong>.</div>
        <?php endif; ?>

        <?php if ($result): ?>
        <div class="card">
            <div class="grid">
                <div>
                    <div style="font-weight:800; font-size:1.1rem;">Transaction</div>
                    <div class="muted"><?php echo htmlspecialchars($result['transaction_id']); ?></div>
                </div>
                <div>
                    <div style="font-weight:800; font-size:1.1rem;">Date/Time</div>
                    <div class="muted"><?php echo htmlspecialchars($result['datetime']); ?></div>
                </div>
                <div>
                    <div style="font-weight:800; font-size:1.1rem;">Total Amount</div>
                    <div class="muted">₱<?php echo number_format((float)$result['total_amount'],0); ?></div>
                </div>
                <div>
                    <div style="font-weight:800; font-size:1.1rem;">Delivery Status</div>
                    <?php $s = $result['status'] ?: 'pending'; ?>
                    <div><span class="status s-<?php echo htmlspecialchars($s); ?>"><?php echo htmlspecialchars($s); ?></span></div>
                </div>
                <div>
                    <div style="font-weight:800; font-size:1.1rem;">Delivery Staff</div>
                    <div class="muted"><?php echo htmlspecialchars($result['delivery_staff_name'] ?? 'Unassigned'); ?></div>
                </div>
                <?php if (!empty($result['notes'])): ?>
                <div>
                    <div style="font-weight:800; font-size:1.1rem;">Remarks</div>
                    <div class="muted"><?php echo htmlspecialchars($result['notes']); ?></div>
                </div>
                <?php endif; ?>
            </div>

            <div class="items">
                <?php foreach ($items as $it): ?>
                <div class="item">
                    <div>
                        <div style="font-weight:600;"><?php echo htmlspecialchars($it['name']); ?></div>
                        <div class="sub">Category: <?php echo htmlspecialchars($it['category']); ?><?php if ($it['quantity_kg']>0): ?> • <?php echo number_format((float)$it['quantity_kg'],2); ?> kg<?php endif; ?><?php if ($it['quantity_sack']>0): ?> • <?php echo number_format((float)$it['quantity_sack'],2); ?> sack(s)<?php endif; ?></div>
                    </div>
                    <div>₱<?php echo number_format((float)$it['price'],0); ?></div>
                </div>
                <?php endforeach; ?>
            </div>

            <?php if (!empty($result['updated_at'])): ?>
            <div class="muted">Last updated: <?php echo htmlspecialchars($result['updated_at']); ?></div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>


