<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../classes/Database.php';
require_login();
require_delivery_staff();

$pdo = Database::getInstance()->getConnection();

$uid = $_SESSION['user_id'] ?? 0;

// Handle status change quick actions
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id'], $_POST['status'])) {
    $id = (int)$_POST['id'];
    $status = $_POST['status'];
    $allowed = ['picked_up','in_transit','delivered','failed'];
    if (in_array($status, $allowed, true)) {
        // Only if assigned to current user
        $chk = $pdo->prepare('SELECT 1 FROM delivery_orders WHERE id = ? AND assigned_to = ?');
        $chk->execute([$id, $uid]);
        if ($chk->fetchColumn()) {
            $upd = $pdo->prepare('UPDATE delivery_orders SET status = ?, updated_at = NOW(), delivered_at = CASE WHEN ? = "delivered" THEN NOW() ELSE delivered_at END WHERE id = ?');
            if ($upd->execute([$status, $status, $id])) { $message = 'Status updated.'; } else { $message = 'Update failed.'; }
        } else { $message = 'Not assigned to you.'; }
    }
}

// Fetch assigned deliveries
$rows = [];
$stmt = $pdo->prepare("SELECT d.id, d.customer_name, d.customer_phone, d.customer_address, d.status, d.created_at, s.transaction_id, s.total_amount
                       FROM delivery_orders d
                       JOIN sales s ON s.id = d.sale_id
                       WHERE d.assigned_to = ?
                       ORDER BY d.created_at DESC");
$stmt->execute([$uid]);
$rows = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Deliveries - RicePOS</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
    .card-list{ display:grid; grid-template-columns: 1fr; gap:0.6rem; }
    .dcard{ background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:0.8rem; display:flex; gap:0.6rem; align-items:flex-start; }
    .dmeta{ font-size:0.9rem; color:#374151; }
    .badge{ display:inline-block; padding: 0.1rem 0.4rem; border-radius: 6px; font-size: 0.78rem; border:1px solid transparent; }
    .b-pending{ background:#fee2e2; color:#991b1b; border-color:#fecaca; }
    .b-picked{ background:#fef9c3; color:#92400e; border-color:#fde68a; }
    .b-transit{ background:#dbeafe; color:#1e40af; border-color:#bfdbfe; }
    .b-delivered{ background:#dcfce7; color:#166534; border-color:#bbf7d0; }
    .b-failed{ background:#ffe4e6; color:#9f1239; border-color:#fecdd3; }
    .actions{ margin-left:auto; display:flex; gap:0.4rem; flex-wrap:wrap; }
    .btn.small{ padding:0.35rem 0.6rem; font-size:0.85rem; }
    </style>
</head>
<body>
    <?php $activePage = 'delivery_staff.php'; $pageTitle = 'My Deliveries'; include __DIR__ . '/../includes/sidebar.php'; include __DIR__ . '/../includes/header.php'; ?>
    <main class="main-content">
        <?php if ($message): ?><div class="muted" style="margin-bottom:0.6rem;"><?php echo htmlspecialchars($message); ?></div><?php endif; ?>
        <div class="card-list">
            <?php foreach ($rows as $d): $cls = $d['status']==='pending'?'b-pending':($d['status']==='picked_up'?'b-picked':($d['status']==='in_transit'?'b-transit':($d['status']==='delivered'?'b-delivered':'b-failed'))); ?>
            <div class="dcard">
                <div>
                    <div style="font-weight:700;">#<?php echo (int)$d['id']; ?> • TXN <?php echo htmlspecialchars($d['transaction_id']); ?></div>
                    <div class="dmeta">Customer: <strong><?php echo htmlspecialchars($d['customer_name']); ?></strong> • ₱<?php echo number_format((float)$d['total_amount'],0); ?></div>
                    <div class="dmeta">Phone: <?php echo htmlspecialchars($d['customer_phone'] ?: '-'); ?></div>
                    <div class="dmeta">Address: <?php echo htmlspecialchars($d['customer_address']); ?></div>
                    <div class="dmeta">Date: <?php echo htmlspecialchars($d['created_at']); ?></div>
                    <div class="dmeta">Status: <span class="badge <?php echo $cls; ?>"><?php echo htmlspecialchars($d['status']); ?></span></div>
                    <div class="dmeta"><a href="receipt.php?txn=<?php echo urlencode($d['transaction_id']); ?>" target="_blank">View Receipt</a></div>
                </div>
                <form method="post" class="actions">
                    <input type="hidden" name="id" value="<?php echo (int)$d['id']; ?>">
                    <button name="status" value="picked_up" type="submit" class="btn small">Picked Up</button>
                    <button name="status" value="in_transit" type="submit" class="btn small">In Transit</button>
                    <button name="status" value="delivered" type="submit" class="btn small">Delivered</button>
                    <button name="status" value="failed" type="submit" class="btn small">Failed</button>
                </form>
            </div>
            <?php endforeach; ?>
            <?php if (!$rows): ?>
                <div class="muted">No assigned deliveries.</div>
            <?php endif; ?>
        </div>
    </main>
    <script src="assets/js/main.js"></script>
</body>
</html>


