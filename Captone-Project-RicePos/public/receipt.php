<?php
session_start();
require_once __DIR__ . '/../classes/User.php';
require_once __DIR__ . '/../classes/Database.php';

$user = new User();
if (!$user->isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$txn = isset($_GET['txn']) ? $_GET['txn'] : '';
if (!$txn) { die('Missing transaction.'); }

$pdo = Database::getInstance()->getConnection();

// Fetch sale by transaction_id
$stmt = $pdo->prepare('SELECT id, transaction_id, user_id, datetime, total_amount, payment, change_due, buyer_name FROM sales WHERE transaction_id = ?');
$stmt->execute([$txn]);
$sale = $stmt->fetch();
if (!$sale) { die('Transaction not found.'); }

// Fetch items
$itemsStmt = $pdo->prepare('SELECT si.quantity_kg, si.quantity_sack, si.price, p.name, p.category FROM sale_items si JOIN products p ON p.id = si.product_id WHERE si.sale_id = ?');
$itemsStmt->execute([$sale['id']]);
$items = $itemsStmt->fetchAll();

// Fetch cashier name
$cashierName = isset($_SESSION['username']) ? $_SESSION['username'] : ('User #'.$sale['user_id']);
// Try get buyer name from sale or delivery order
$buyerName = $sale['buyer_name'] ?? null;
if (!$buyerName) {
    $delStmt = $pdo->prepare('SELECT customer_name FROM delivery_orders WHERE sale_id = ? LIMIT 1');
    $delStmt->execute([$sale['id']]);
    $buyerName = $delStmt->fetchColumn();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Receipt - <?php echo htmlspecialchars($sale['transaction_id']); ?></title>
    <?php $cssVer = @filemtime(__DIR__ . '/assets/css/style.css') ?: time(); ?>
    <link rel="stylesheet" href="assets/css/style.css?v=<?php echo $cssVer; ?>">
    <style>
        body { background:#f8fafc; font-family: 'Segoe UI', Arial, sans-serif; }
        .receipt { max-width: 380px; margin: 1rem auto; background:#fff; border:1px solid #e5e7eb; border-radius: 10px; padding: 1rem; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .receipt h3 { margin: 0 0 0.5rem 0; text-align: center; }
        .muted { color:#6b7280; font-size: 0.92rem; text-align: center; }
        .meta { font-size: 0.92rem; margin-top: 0.6rem; }
        .row { display:flex; justify-content: space-between; }
        .items { margin-top: 0.8rem; border-top: 1px dashed #e5e7eb; border-bottom: 1px dashed #e5e7eb; padding: 0.5rem 0; }
        .item { display:grid; grid-template-columns: 1fr auto; gap: 0.5rem; margin-bottom: 0.35rem; }
        .item .name { font-weight: 600; }
        .item .sub { color:#6b7280; font-size: 0.86rem; }
        .totals { margin-top: 0.6rem; }
        .totals .row { padding: 0.1rem 0; }
        .badge { display:inline-block; background:#fef3c7; color:#92400e; border:1px solid #fde68a; padding: 0.1rem 0.4rem; border-radius: 6px; font-size: 0.78rem; }
        .footer-note { margin-top: 0.8rem; text-align: center; font-size: 0.85rem; color:#6b7280; }
        .actions { display:flex; gap:0.5rem; margin-top: 0.8rem; }
        .btn { display:inline-flex; align-items:center; gap:0.35rem; padding:0.45rem 0.8rem; border:1px solid #d1d5db; border-radius:6px; background:#fff; cursor:pointer; text-decoration:none; color:#111827; }
        .btn:hover { background:#f3f4f6; }
        @media print { .actions { display:none; } body { background:#fff; } .receipt { box-shadow:none; border: none; } }
    </style>
</head>
<body>
    <div class="receipt">
        <?php if (!empty($_SESSION['email_notice'])): ?>
        <div style="background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;padding:8px 10px;border-radius:8px;margin-bottom:8px;">
            <?php echo htmlspecialchars($_SESSION['email_notice']); unset($_SESSION['email_notice']); ?>
        </div>
        <?php endif; ?>
        <?php if (!empty($_SESSION['email_debug'])): ?>
        <div style="background:#fff7ed;border:1px solid #fed7aa;color:#9a3412;padding:8px 10px;border-radius:8px;margin-bottom:8px;">
            Email debug: <?php echo htmlspecialchars($_SESSION['email_debug']); unset($_SESSION['email_debug']); ?>
        </div>
        <?php endif; ?>
        <h3>RicePOS Receipt</h3>
        <div class="muted">Transaction: <?php echo htmlspecialchars($sale['transaction_id']); ?></div>
        <div class="meta">
            <div class="row"><span>Date/Time</span><span><?php echo htmlspecialchars($sale['datetime']); ?></span></div>
            <div class="row"><span>Cashier</span><span><?php echo htmlspecialchars($cashierName); ?></span></div>
            <?php if ($buyerName): ?>
            <div class="row"><span>Buyer</span><span><?php echo htmlspecialchars($buyerName); ?></span></div>
            <?php endif; ?>
        </div>
        <div class="items">
            <?php foreach ($items as $it): ?>
            <div class="item">
                <div>
                    <div class="name"><?php echo htmlspecialchars($it['name']); ?></div>
                    <div class="sub">Category: <?php echo htmlspecialchars($it['category']); ?><?php if ($it['quantity_kg']>0): ?> • <?php echo number_format((float)$it['quantity_kg'],2); ?> kg<?php endif; ?><?php if ($it['quantity_sack']>0): ?> • <?php echo number_format((float)$it['quantity_sack'],2); ?> sack(s)<?php endif; ?></div>
                </div>
                 <div>Php <?php echo number_format((float)$it['price'],0); ?></div>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="totals">
            <div class="row"><strong>Total</strong><strong>₱<?php echo number_format((float)$sale['total_amount'],0); ?></strong></div>
            <div class="row"><span>Payment</span><span>₱<?php echo number_format((float)$sale['payment'],0); ?></span></div>
            <div class="row"><span>Change</span><span>₱<?php echo number_format((float)$sale['change_due'],0); ?></span></div>
        </div>
        <div class="footer-note">
            <span class="badge">Note</span> This is not official receipt. For reference only.
        </div>
        <div class="actions">
            <button class="btn" onclick="window.print()">🖨 Print</button>
            <a class="btn" href="pos.php">⬅ Back to POS</a>
        </div>
    </div>
</body>
</html>

