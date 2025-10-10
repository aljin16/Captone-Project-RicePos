<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();
require_delivery_staff();
require_once __DIR__ . '/../classes/Database.php';

$pdo = Database::getInstance()->getConnection();
$userId = (int)($_SESSION['user_id'] ?? 0);

// Per-user delivery notification state (timestamp-based)
$pdo->exec(
    'CREATE TABLE IF NOT EXISTS delivery_notif_state (
        user_id INT PRIMARY KEY,
        last_read_at DATETIME NOT NULL DEFAULT "1970-01-01 00:00:00",
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        CONSTRAINT fk_deliv_state_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
);

function ensure_state_row(PDO $pdo, int $userId): void {
    $stmt = $pdo->prepare('INSERT IGNORE INTO delivery_notif_state (user_id) VALUES (:uid)');
    $stmt->execute([':uid' => $userId]);
}

function get_state(PDO $pdo, int $userId): array {
    $stmt = $pdo->prepare('SELECT last_read_at FROM delivery_notif_state WHERE user_id = :uid');
    $stmt->execute([':uid' => $userId]);
    $row = $stmt->fetch();
    return ['last_read_at' => $row ? $row['last_read_at'] : '1970-01-01 00:00:00'];
}

function set_last_read_to(PDO $pdo, int $userId, string $isoDateTime): void {
    $stmt = $pdo->prepare('UPDATE delivery_notif_state SET last_read_at = GREATEST(last_read_at, :ts) WHERE user_id = :uid');
    $stmt->execute([':ts' => $isoDateTime, ':uid' => $userId]);
}

function count_unread(PDO $pdo, int $userId, string $lastReadAt): int {
    $stmt = $pdo->prepare('SELECT COUNT(*)
        FROM delivery_orders d
        WHERE d.assigned_to = :uid
          AND COALESCE(d.updated_at, d.created_at) > :lr');
    $stmt->execute([':uid' => $userId, ':lr' => $lastReadAt]);
    return (int)$stmt->fetchColumn();
}

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

ensure_state_row($pdo, $userId);
$action = $_GET['action'] ?? $_POST['action'] ?? 'list';

try {
    if ($action === 'list') {
        $limit = isset($_GET['limit']) ? max(1, min(50, (int)$_GET['limit'])) : 20;
        $state = get_state($pdo, $userId);
        $stmt = $pdo->prepare('SELECT d.id, d.status, d.customer_name, d.customer_address, d.created_at, d.updated_at,
                                      s.transaction_id, s.total_amount,
                                      COALESCE(d.updated_at, d.created_at) AS event_time
                                FROM delivery_orders d
                                JOIN sales s ON s.id = d.sale_id
                               WHERE d.assigned_to = :uid
                               ORDER BY event_time DESC
                               LIMIT :lim');
        $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();
        $payload = array_map(function($r) use ($state) {
            $evt = $r['event_time'] ?? $r['created_at'];
            return [
                'id' => (int)$r['id'],
                'status' => $r['status'],
                'customer_name' => $r['customer_name'],
                'transaction_id' => $r['transaction_id'],
                'total_amount' => (float)$r['total_amount'],
                'event_time' => $evt,
                'read' => (strtotime($evt) <= strtotime($state['last_read_at']))
            ];
        }, $rows);
        $unread = count_unread($pdo, $userId, $state['last_read_at']);
        echo json_encode(['rows' => $payload, 'unread_count' => $unread], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($action === 'unread_count') {
        $state = get_state($pdo, $userId);
        $unread = count_unread($pdo, $userId, $state['last_read_at']);
        echo json_encode(['unread_count' => $unread], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($action === 'mark_read') {
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        if ($id > 0) {
            $stmt = $pdo->prepare('SELECT COALESCE(updated_at, created_at) AS evt FROM delivery_orders WHERE id = :id AND assigned_to = :uid');
            $stmt->execute([':id' => $id, ':uid' => $userId]);
            $evt = $stmt->fetchColumn();
            if ($evt) { set_last_read_to($pdo, $userId, $evt); }
        }
        $state = get_state($pdo, $userId);
        $unread = count_unread($pdo, $userId, $state['last_read_at']);
        echo json_encode(['ok' => true, 'unread_count' => $unread], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($action === 'mark_all_read') {
        $now = date('Y-m-d H:i:s');
        set_last_read_to($pdo, $userId, $now);
        echo json_encode(['ok' => true, 'unread_count' => 0], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode(['error' => 'unknown_action'], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'server_error', 'message' => $e->getMessage()]);
}


