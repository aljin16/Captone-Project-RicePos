<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();
require_once __DIR__ . '/../classes/Database.php';

$pdo = Database::getInstance()->getConnection();

// Provide Server-Sent Events (SSE) with last 10 recent logs
if (!isset($_GET['poll'])) {
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
}

$since = isset($_GET['since']) ? (int)$_GET['since'] : 0;
$stmt = $since > 0
    ? $pdo->prepare('SELECT id, created_at, user_id, username, action, product_id, product_name, stock_before_sack, stock_after_sack, details FROM inventory_activity_logs WHERE id > :since ORDER BY id DESC LIMIT 20')
    : $pdo->prepare('SELECT id, created_at, user_id, username, action, product_id, product_name, stock_before_sack, stock_after_sack, details FROM inventory_activity_logs ORDER BY id DESC LIMIT 5');
if ($since > 0) { $stmt->bindValue(':since', $since, PDO::PARAM_INT); }
$stmt->execute();
$rows = $stmt->fetchAll();

$actions = [
    'product_add' => 'success',
    'product_update' => 'warning',
    'product_delete' => 'danger',
    'stock_update' => 'info',
    'stock_sale' => 'primary',
    'product_activate' => 'success',
    'product_hide' => 'secondary',
    'delivery_status' => 'info',
];

ob_start();
foreach ($rows as $log) {
    $color = $actions[$log['action']] ?? 'secondary';
    echo '<tr data-id="'.(int)$log['id'].'">';
    echo '<td>'.htmlspecialchars($log['created_at']).'</td>';
    $user = $log['username'] ?: ('User#'.($log['user_id'] ?? '-'));
    echo '<td>'.htmlspecialchars($user).'</td>';
    echo '<td><span class="badge badge-'.$color.'">'.htmlspecialchars($log['action']).'</span></td>';
    $prod = $log['product_id'] ? ('#'.(int)$log['product_id'].' '.htmlspecialchars($log['product_name'] ?? '')) : '-';
    echo '<td>'.$prod.'</td>';
    echo '<td>'.(($log['stock_before_sack'] !== null ? (float)$log['stock_before_sack'] : '-')).' sack(s)</td>';
    echo '<td>'.(($log['stock_after_sack'] !== null ? (float)$log['stock_after_sack'] : '-')).' sack(s)</td>';
    echo '<td class="log-details">'.htmlspecialchars($log['details'] ?? '').'</td>';
    echo '</tr>';
}
$html = ob_get_clean();

$format = isset($_GET['format']) ? $_GET['format'] : 'html';
if ($format === 'summary') {
    $rowsPayload = array_map(function($r) {
        return [
            'id' => (int)$r['id'],
            'created_at' => $r['created_at'],
            'user_id' => $r['user_id'],
            'username' => $r['username'],
            'action' => $r['action'],
            'product_id' => $r['product_id'],
            'product_name' => $r['product_name'],
        ];
    }, $rows);
    $payload = json_encode(['rows' => $rowsPayload], JSON_UNESCAPED_UNICODE);
} else {
    $payload = json_encode(['html' => $html], JSON_UNESCAPED_UNICODE);
}

if (isset($_GET['poll'])) {
    header('Content-Type: application/json');
    echo $payload;
    exit;
}

echo "data: $payload\n\n";
flush();


