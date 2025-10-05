<?php
require_once __DIR__ . '/Database.php';

class ActivityLog
{
    private $pdo;

    public function __construct()
    {
        $this->pdo = Database::getInstance()->getConnection();
        $this->ensureTable();
    }

    private function ensureTable(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS inventory_activity_logs (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }

    public function log(array $data): void
    {
        $sql = 'INSERT INTO inventory_activity_logs
                (user_id, username, action, product_id, product_name, details, stock_before_kg, stock_before_sack, stock_after_kg, stock_after_sack, ip_address, user_agent)
                VALUES (:user_id, :username, :action, :product_id, :product_name, :details, :sbkg, :sbsack, :sakkg, :sacks, :ip, :ua)';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':user_id' => $data['user_id'] ?? (isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null),
            ':username' => $data['username'] ?? (isset($_SESSION['username']) ? (string)$_SESSION['username'] : null),
            ':action' => (string)($data['action'] ?? 'unknown'),
            ':product_id' => $data['product_id'] ?? null,
            ':product_name' => $data['product_name'] ?? null,
            ':details' => $data['details'] ?? null,
            ':sbkg' => $data['stock_before_kg'] ?? null,
            ':sbsack' => $data['stock_before_sack'] ?? null,
            ':sakkg' => $data['stock_after_kg'] ?? null,
            ':sacks' => $data['stock_after_sack'] ?? null,
            ':ip' => $data['ip'] ?? ($_SERVER['REMOTE_ADDR'] ?? null),
            ':ua' => $data['ua'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? null),
        ]);
    }
}


