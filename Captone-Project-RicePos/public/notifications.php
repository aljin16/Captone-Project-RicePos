<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();
require_once __DIR__ . '/../classes/Database.php';

// Only admins see/operate the notification panel as per header gating
if (!is_admin()) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'forbidden']);
    exit;
}

$pdo = Database::getInstance()->getConnection();
$userId = (int)($_SESSION['user_id'] ?? 0);

// Ensure per-user state table
$pdo->exec(
    'CREATE TABLE IF NOT EXISTS activity_log_user_state (
        user_id INT PRIMARY KEY,
        last_read_log_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        last_cleared_log_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        CONSTRAINT fk_state_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
);

function ensure_state_row(PDO $pdo, int $userId): void {
    $stmt = $pdo->prepare('INSERT IGNORE INTO activity_log_user_state (user_id) VALUES (:uid)');
    $stmt->execute([':uid' => $userId]);
}

ensure_state_row($pdo, $userId);

function get_state(PDO $pdo, int $userId): array {
    $stmt = $pdo->prepare('SELECT last_read_log_id, last_cleared_log_id FROM activity_log_user_state WHERE user_id = :uid');
    $stmt->execute([':uid' => $userId]);
    $row = $stmt->fetch();
    if (!$row) { return ['last_read_log_id' => 0, 'last_cleared_log_id' => 0]; }
    return [
        'last_read_log_id' => (int)$row['last_read_log_id'],
        'last_cleared_log_id' => (int)$row['last_cleared_log_id'],
    ];
}

function get_max_log_id(PDO $pdo): int {
    $max = (int)$pdo->query('SELECT IFNULL(MAX(id),0) FROM inventory_activity_logs')->fetchColumn();
    return $max;
}

function count_unread(PDO $pdo, int $lastRead, int $lastCleared): int {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM inventory_activity_logs WHERE id > :lr AND id > :lc');
    $stmt->execute([':lr' => $lastRead, ':lc' => $lastCleared]);
    return (int)$stmt->fetchColumn();
}

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
$action = $_GET['action'] ?? $_POST['action'] ?? 'list';

try {
    if ($action === 'list') {
        $sinceId = isset($_GET['since_id']) ? max(0, (int)$_GET['since_id']) : 0;
        $limit = isset($_GET['limit']) ? max(1, min(50, (int)$_GET['limit'])) : 20;
        $state = get_state($pdo, $userId);
        $sinceCutoff = max($sinceId, $state['last_cleared_log_id']);
        $stmt = $pdo->prepare('SELECT id, created_at, user_id, username, action, product_id, product_name FROM inventory_activity_logs WHERE id > :since ORDER BY id DESC LIMIT :lim');
        $stmt->bindValue(':since', $sinceCutoff, PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();
        $lastRead = $state['last_read_log_id'];
        $payloadRows = array_map(function($r) use ($lastRead) {
            return [
                'id' => (int)$r['id'],
                'created_at' => $r['created_at'],
                'user_id' => $r['user_id'],
                'username' => $r['username'],
                'action' => $r['action'],
                'product_id' => $r['product_id'],
                'product_name' => $r['product_name'],
                'read' => ((int)$r['id'] <= $lastRead),
            ];
        }, $rows);
        $maxId = get_max_log_id($pdo);
        $unread = count_unread($pdo, $state['last_read_log_id'], $state['last_cleared_log_id']);
        echo json_encode([
            'rows' => $payloadRows,
            'unread_count' => $unread,
            'max_id' => $maxId,
            'last_read_log_id' => $state['last_read_log_id'],
            'last_cleared_log_id' => $state['last_cleared_log_id'],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($action === 'unread_count') {
        $state = get_state($pdo, $userId);
        $unread = count_unread($pdo, $state['last_read_log_id'], $state['last_cleared_log_id']);
        echo json_encode(['unread_count' => $unread], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($action === 'mark_read') {
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        if ($id > 0) {
            $stmt = $pdo->prepare('UPDATE activity_log_user_state SET last_read_log_id = GREATEST(last_read_log_id, :id) WHERE user_id = :uid');
            $stmt->execute([':id' => $id, ':uid' => $userId]);
        }
        $state = get_state($pdo, $userId);
        $unread = count_unread($pdo, $state['last_read_log_id'], $state['last_cleared_log_id']);
        echo json_encode(['ok' => true, 'unread_count' => $unread], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($action === 'mark_all_read') {
        $maxId = get_max_log_id($pdo);
        $stmt = $pdo->prepare('UPDATE activity_log_user_state SET last_read_log_id = :max WHERE user_id = :uid');
        $stmt->execute([':max' => $maxId, ':uid' => $userId]);
        echo json_encode(['ok' => true, 'unread_count' => 0, 'last_id' => $maxId], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($action === 'clear_all') {
        $maxId = get_max_log_id($pdo);
        $stmt = $pdo->prepare('UPDATE activity_log_user_state SET last_cleared_log_id = :max, last_read_log_id = GREATEST(last_read_log_id, :max) WHERE user_id = :uid');
        $stmt->execute([':max' => $maxId, ':uid' => $userId]);
        echo json_encode(['ok' => true, 'unread_count' => 0, 'last_id' => $maxId], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode(['error' => 'unknown_action'], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'server_error', 'message' => $e->getMessage()]);
}


