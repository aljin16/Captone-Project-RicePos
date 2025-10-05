<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();
require_once __DIR__ . '/../classes/Database.php';

$pdo = Database::getInstance()->getConnection();

// Ensure table exists (first-time safety)
$pdo->exec('CREATE TABLE IF NOT EXISTS inventory_activity_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    user_id INT NULL,
    username VARCHAR(100) NULL,
    action VARCHAR(50) NOT NULL,
    product_id INT NULL,
    product_name VARCHAR(255) NULL,
    details TEXT NULL,
    stock_before_kg DECIMAL(10,2) NULL,
    stock_before_sack DECIMAL(10,2) NULL,
    stock_after_kg DECIMAL(10,2) NULL,
    stock_after_sack DECIMAL(10,2) NULL,
    ip_address VARCHAR(64) NULL,
    user_agent VARCHAR(255) NULL,
    INDEX idx_created_at (created_at),
    INDEX idx_action (action),
    INDEX idx_product_id (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

$action = trim($_GET['action'] ?? '');
$productId = isset($_GET['product_id']) ? (int)$_GET['product_id'] : null;
$where = [];
$params = [];
if ($action !== '') { $where[] = 'action = :action'; $params[':action'] = $action; }
if ($productId) { $where[] = 'product_id = :pid'; $params[':pid'] = $productId; }
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$stmt = $pdo->prepare("SELECT created_at, username, action, product_id, product_name, stock_before_sack, stock_after_sack, details FROM inventory_activity_logs $whereSql ORDER BY created_at DESC, id DESC LIMIT 1000");
foreach ($params as $k => $v) { $stmt->bindValue($k, $v); }
$stmt->execute();
$rows = $stmt->fetchAll();

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory Logs Export</title>
    <style>
        @page { size: A4 landscape; margin: 16mm; }
        body { font-family: Arial, Helvetica, sans-serif; color: #0f172a; }
        h1 { font-size: 18px; margin: 0 0 6px; }
        .meta { font-size: 12px; color:#475569; margin-bottom: 12px; }
        table { width: 100%; border-collapse: collapse; font-size: 12px; }
        th, td { border: 1px solid #cbd5e1; padding: 6px 8px; vertical-align: top; }
        th { background: #f1f5f9; text-align: left; }
        .muted { color:#64748b; }
    </style>
</head>
<body>
    <h1>Inventory Activity Logs</h1>
    <div class="meta">
        Generated: <?php echo htmlspecialchars(date('Y-m-d H:i:s')); ?>
        <?php if ($action): ?> | Action: <?php echo htmlspecialchars($action); ?><?php endif; ?>
        <?php if ($productId): ?> | Product ID: #<?php echo (int)$productId; ?><?php endif; ?>
    </div>
    <table>
        <thead>
            <tr>
                <th style="white-space:nowrap;">Date/Time</th>
                <th>User</th>
                <th>Action</th>
                <th>Product</th>
                <th>Stock Before (sack)</th>
                <th>Stock After (sack)</th>
                <th>Details</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td style="white-space:nowrap; font-variant-numeric: tabular-nums;"><?php echo htmlspecialchars($r['created_at']); ?></td>
                    <td><?php echo htmlspecialchars($r['username'] ?: ''); ?></td>
                    <td><?php echo htmlspecialchars($r['action']); ?></td>
                    <td>
                        <?php if (!empty($r['product_id'])): ?>#<?php echo (int)$r['product_id']; ?> <?php echo htmlspecialchars($r['product_name'] ?? ''); ?><?php endif; ?>
                    </td>
                    <td><?php echo $r['stock_before_sack'] !== null ? (float)$r['stock_before_sack'] : ''; ?></td>
                    <td><?php echo $r['stock_after_sack'] !== null ? (float)$r['stock_after_sack'] : ''; ?></td>
                    <td><?php echo htmlspecialchars($r['details'] ?? ''); ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <script>window.print()</script>
</body>
</html>


