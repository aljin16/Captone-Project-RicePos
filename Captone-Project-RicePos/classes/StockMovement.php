<?php
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Product.php';
require_once __DIR__ . '/ActivityLog.php';

class StockMovement
{
    /** @var PDO */
    private $pdo;
    /** @var ActivityLog */
    private $logger;

    public function __construct()
    {
        $this->pdo = Database::getInstance()->getConnection();
        $this->logger = new ActivityLog();
        $this->ensureTable();
    }

    private function ensureTable(): void
    {
        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS stock_movements (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                movement_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                product_id INT NOT NULL,
                user_id INT NULL,
                type ENUM('in','out') NOT NULL,
                quantity_sack DECIMAL(10,2) NOT NULL,
                supplier VARCHAR(150) NULL,
                reference_no VARCHAR(100) NULL,
                reason VARCHAR(100) NULL,
                notes TEXT NULL,
                INDEX idx_product_date (product_id, movement_date),
                INDEX idx_type_date (type, movement_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    public function recordStockIn(int $productId, float $quantitySack, ?string $movementDate = null, ?string $supplier = null, ?string $referenceNo = null, ?string $notes = null): bool
    {
        if ($quantitySack <= 0) {
            throw new InvalidArgumentException('Quantity must be greater than zero.');
        }
        $movementDate = $movementDate ?: date('Y-m-d H:i:s');
        $startedTx = false;
        try {
            if (!$this->pdo->inTransaction()) {
                $this->pdo->beginTransaction();
                $startedTx = true;
            }

            $productObj = new Product();
            $before = $productObj->getById($productId);
            if (!$before) {
                throw new RuntimeException('Product not found.');
            }
            $newSack = (float)$before['stock_sack'] + $quantitySack;
            $newKg = (float)($before['stock_kg'] ?? 0);
            $productObj->updateStock($productId, $newKg, $newSack);

            $stmt = $this->pdo->prepare('INSERT INTO stock_movements (movement_date, product_id, user_id, type, quantity_sack, supplier, reference_no, reason, notes) VALUES (?, ?, ?, \'in\', ?, ?, ?, NULL, ?)');
            $stmt->execute([
                $movementDate,
                $productId,
                $_SESSION['user_id'] ?? null,
                $quantitySack,
                $supplier,
                $referenceNo,
                $notes
            ]);

            $this->logger->log([
                'action' => 'stock_in',
                'product_id' => $productId,
                'product_name' => $before['name'] ?? null,
                'details' => trim('Stock-In'.($supplier?" from $supplier":'').($referenceNo?" (Ref: $referenceNo)":'')),
                'stock_before_kg' => $before['stock_kg'] ?? null,
                'stock_before_sack' => $before['stock_sack'] ?? null,
                'stock_after_kg' => $newKg,
                'stock_after_sack' => $newSack,
            ]);

            if ($startedTx && $this->pdo->inTransaction()) {
                $this->pdo->commit();
            }
            return true;
        } catch (\Throwable $e) {
            if ($startedTx && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function recordStockOut(int $productId, float $quantitySack, ?string $movementDate = null, ?string $reason = null, ?string $notes = null): bool
    {
        if ($quantitySack <= 0) {
            throw new InvalidArgumentException('Quantity must be greater than zero.');
        }
        $movementDate = $movementDate ?: date('Y-m-d H:i:s');
        $startedTx = false;
        try {
            if (!$this->pdo->inTransaction()) {
                $this->pdo->beginTransaction();
                $startedTx = true;
            }

            $productObj = new Product();
            $before = $productObj->getById($productId);
            if (!$before) {
                throw new RuntimeException('Product not found.');
            }
            $available = (float)$before['stock_sack'];
            if ($quantitySack > $available) {
                throw new RuntimeException('Quantity exceeds available stock.');
            }
            $newSack = $available - $quantitySack;
            $newKg = (float)($before['stock_kg'] ?? 0);
            $productObj->updateStock($productId, $newKg, $newSack);

            $stmt = $this->pdo->prepare('INSERT INTO stock_movements (movement_date, product_id, user_id, type, quantity_sack, supplier, reference_no, reason, notes) VALUES (?, ?, ?, \'out\', ?, NULL, NULL, ?, ?)');
            $stmt->execute([
                $movementDate,
                $productId,
                $_SESSION['user_id'] ?? null,
                $quantitySack,
                $reason,
                $notes
            ]);

            $this->logger->log([
                'action' => 'stock_out',
                'product_id' => $productId,
                'product_name' => $before['name'] ?? null,
                'details' => trim('Stock-Out'.($reason?" ($reason)":'')),
                'stock_before_kg' => $before['stock_kg'] ?? null,
                'stock_before_sack' => $before['stock_sack'] ?? null,
                'stock_after_kg' => $newKg,
                'stock_after_sack' => $newSack,
            ]);

            if ($startedTx && $this->pdo->inTransaction()) {
                $this->pdo->commit();
            }
            return true;
        } catch (\Throwable $e) {
            if ($startedTx && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
}


