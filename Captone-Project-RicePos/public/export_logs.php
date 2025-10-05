<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();
require_once __DIR__ . '/../classes/Database.php';

$pdo = Database::getInstance()->getConnection();
$format = strtolower($_GET['format'] ?? 'csv');
$action = trim($_GET['action'] ?? '');
$productId = isset($_GET['product_id']) ? (int)$_GET['product_id'] : null;

$where = [];
$params = [];
if ($action !== '') { $where[] = 'action = :action'; $params[':action'] = $action; }
if ($productId) { $where[] = 'product_id = :pid'; $params[':pid'] = $productId; }
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$stmt = $pdo->prepare("SELECT created_at, username, action, product_id, product_name, stock_before_sack, stock_after_sack, details FROM inventory_activity_logs $whereSql ORDER BY created_at DESC, id DESC");
foreach ($params as $k => $v) { $stmt->bindValue($k, $v); }
$stmt->execute();
$rows = $stmt->fetchAll();

if ($format === 'xlsx') {
    // Simple XLSX via CSV content with Excel header fallback
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="inventory_logs.xlsx"');
} else {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="inventory_logs.csv"');
}

$out = fopen('php://output', 'w');
fputcsv($out, ['Date', 'User', 'Action', 'Product', 'Stock Before (sack)', 'Stock After (sack)', 'Details']);
foreach ($rows as $r) {
    $prod = $r['product_id'] ? ('#'.$r['product_id'].' '.$r['product_name']) : '';
    fputcsv($out, [
        $r['created_at'],
        $r['username'],
        $r['action'],
        $prod,
        $r['stock_before_sack'],
        $r['stock_after_sack'],
        $r['details'],
    ]);
}
fclose($out);
exit;


