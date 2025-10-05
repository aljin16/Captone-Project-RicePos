<?php
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Product.php';
require_once __DIR__ . '/ActivityLog.php';
class Sale {
    private $pdo;
    private $logger;
    public function __construct() {
        $this->pdo = Database::getInstance()->getConnection();
        $this->logger = new ActivityLog();
    }
    public function generateTransactionId() {
        return 'TXN' . date('YmdHis') . rand(100,999);
    }
    public function create($user_id, $total, $payment, $change, $items, ?string $buyerName = null, ?string $buyerEmail = null) {
        $transaction_id = $this->generateTransactionId();
        $startedTx = false;
        try {
            if (!$this->pdo->inTransaction()) {
                $this->pdo->beginTransaction();
                $startedTx = true;
            }
            $hasBuyer = ($buyerName !== null || $buyerEmail !== null);
            if ($hasBuyer) {
                try {
                    $stmt = $this->pdo->prepare('INSERT INTO sales (transaction_id, user_id, total_amount, payment, change_due, buyer_name, buyer_email) VALUES (?, ?, ?, ?, ?, ?, ?)');
                    $stmt->execute([$transaction_id, $user_id, $total, $payment, $change, $buyerName, $buyerEmail]);
                } catch (\Throwable $e) {
                    // Fallback if columns don't exist yet
                    $stmt = $this->pdo->prepare('INSERT INTO sales (transaction_id, user_id, total_amount, payment, change_due) VALUES (?, ?, ?, ?, ?)');
                    $stmt->execute([$transaction_id, $user_id, $total, $payment, $change]);
                }
            } else {
                $stmt = $this->pdo->prepare('INSERT INTO sales (transaction_id, user_id, total_amount, payment, change_due) VALUES (?, ?, ?, ?, ?)');
                $stmt->execute([$transaction_id, $user_id, $total, $payment, $change]);
            }
            $sale_id = $this->pdo->lastInsertId();
            $productObj = new Product();
            foreach ($items as $item) {
                $stmt2 = $this->pdo->prepare('INSERT INTO sale_items (sale_id, product_id, quantity_kg, quantity_sack, price) VALUES (?, ?, ?, ?, ?)');
                $stmt2->execute([$sale_id, $item['product_id'], $item['quantity_kg'], $item['quantity_sack'], $item['price']]);
                // Deduct stock
                $product = $productObj->getById($item['product_id']);
                $new_kg = $product['stock_kg'] - $item['quantity_kg'];
                $new_sack = $product['stock_sack'] - $item['quantity_sack'];
                $productObj->updateStock($item['product_id'], $new_kg, $new_sack);
                // Log movement as sale
                $this->logger->log([
                    'action' => 'stock_sale',
                    'product_id' => (int)$item['product_id'],
                    'product_name' => $product['name'] ?? null,
                    'details' => 'Sold quantities from POS',
                    'stock_before_kg' => $product['stock_kg'] ?? null,
                    'stock_before_sack' => $product['stock_sack'] ?? null,
                    'stock_after_kg' => $new_kg,
                    'stock_after_sack' => $new_sack,
                ]);
            }
            // No extra summary log; rely on stock_sale item logs for notifications
            if ($startedTx && $this->pdo->inTransaction()) {
                $this->pdo->commit();
            }
            return $transaction_id;
        } catch (\Throwable $e) {
            if ($startedTx && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
} 