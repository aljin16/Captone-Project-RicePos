<?php
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/ActivityLog.php';
class Product {
    private $pdo;
    private $logger;
    public function __construct() {
        $this->pdo = Database::getInstance()->getConnection();
        $this->logger = new ActivityLog();
        // Ensure visibility column exists (idempotent)
        try {
            $this->pdo->query('SELECT is_active FROM products LIMIT 1');
        } catch (\Throwable $e) {
            try {
                $this->pdo->exec('ALTER TABLE products ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1');
            } catch (\Throwable $e2) { /* ignore */ }
        }
        // Ensure profit_per_sack column exists (idempotent)
        try {
            $this->pdo->query('SELECT profit_per_sack FROM products LIMIT 1');
        } catch (\Throwable $e) {
            try {
                $this->pdo->exec('ALTER TABLE products ADD COLUMN profit_per_sack INT NULL DEFAULT NULL');
            } catch (\Throwable $e2) { /* ignore */ }
        }
    }
    public function getAll() {
        $stmt = $this->pdo->query('SELECT * FROM products');
        return $stmt->fetchAll();
    }
    public function getById($id) {
        $stmt = $this->pdo->prepare('SELECT * FROM products WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch();
    }
    public function add($name, $price_kg, $price_sack, $stock_kg, $stock_sack, $category, $low_stock_threshold, $image = null, $profit_per_sack = null) {
        // Auto-compute is_active based on initial stock
        $stmt = $this->pdo->prepare('INSERT INTO products (name, price_per_kg, price_per_sack, profit_per_sack, stock_kg, stock_sack, category, low_stock_threshold, image, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, CASE WHEN ? <= 0 THEN 0 ELSE 1 END)');
        $ok = $stmt->execute([$name, $price_kg, $price_sack, $profit_per_sack, $stock_kg, $stock_sack, $category, $low_stock_threshold, $image, $stock_sack]);
        if ($ok) {
            $pid = (int)$this->pdo->lastInsertId();
            $this->logger->log([
                'action' => 'product_add',
                'product_id' => $pid,
                'product_name' => $name,
                'details' => 'Added product',
                'stock_before_kg' => 0,
                'stock_before_sack' => 0,
                'stock_after_kg' => $stock_kg,
                'stock_after_sack' => $stock_sack,
            ]);
        }
        return $ok;
    }
    public function update($id, $name, $price_kg, $price_sack, $stock_kg, $stock_sack, $category, $low_stock_threshold, $image = null, $profit_per_sack = null) {
        $before = $this->getById($id);
        if ($image) {
            $stmt = $this->pdo->prepare('UPDATE products SET name=?, price_per_kg=?, price_per_sack=?, profit_per_sack=?, stock_kg=?, stock_sack=?, category=?, low_stock_threshold=?, image=?, is_active = CASE WHEN ? <= 0 THEN 0 ELSE is_active END WHERE id=?');
            $ok = $stmt->execute([$name, $price_kg, $price_sack, $profit_per_sack, $stock_kg, $stock_sack, $category, $low_stock_threshold, $image, $stock_sack, $id]);
        } else {
            $stmt = $this->pdo->prepare('UPDATE products SET name=?, price_per_kg=?, price_per_sack=?, profit_per_sack=?, stock_kg=?, stock_sack=?, category=?, low_stock_threshold=?, is_active = CASE WHEN ? <= 0 THEN 0 ELSE is_active END WHERE id=?');
            $ok = $stmt->execute([$name, $price_kg, $price_sack, $profit_per_sack, $stock_kg, $stock_sack, $category, $low_stock_threshold, $stock_sack, $id]);
        }
        if ($ok) {
            $this->logger->log([
                'action' => 'product_update',
                'product_id' => (int)$id,
                'product_name' => $name,
                'details' => 'Updated product info',
                'stock_before_kg' => $before['stock_kg'] ?? null,
                'stock_before_sack' => $before['stock_sack'] ?? null,
                'stock_after_kg' => $stock_kg,
                'stock_after_sack' => $stock_sack,
            ]);
        }
        return $ok;
    }
    public function updateStock($id, $stock_kg, $stock_sack) {
        // Also auto-hide when stock_sack <= 0
        $before = $this->getById($id);
        $stmt = $this->pdo->prepare('UPDATE products SET stock_kg=?, stock_sack=?, is_active = CASE WHEN ? <= 0 THEN 0 ELSE is_active END WHERE id=?');
        $ok = $stmt->execute([$stock_kg, $stock_sack, $stock_sack, $id]);
        if ($ok) {
            $this->logger->log([
                'action' => 'stock_update',
                'product_id' => (int)$id,
                'product_name' => $before['name'] ?? null,
                'details' => 'Adjusted stock',
                'stock_before_kg' => $before['stock_kg'] ?? null,
                'stock_before_sack' => $before['stock_sack'] ?? null,
                'stock_after_kg' => $stock_kg,
                'stock_after_sack' => $stock_sack,
            ]);
        }
        return $ok;
    }
    public function delete($id) {
        $before = $this->getById($id);
        $stmt = $this->pdo->prepare('DELETE FROM products WHERE id=?');
        $ok = $stmt->execute([$id]);
        if ($ok) {
            $this->logger->log([
                'action' => 'product_delete',
                'product_id' => (int)$id,
                'product_name' => $before['name'] ?? null,
                'details' => 'Deleted product',
                'stock_before_kg' => $before['stock_kg'] ?? null,
                'stock_before_sack' => $before['stock_sack'] ?? null,
                'stock_after_kg' => 0,
                'stock_after_sack' => 0,
            ]);
        }
        return $ok;
    }
    public function isLowStock($id) {
        $product = $this->getById($id);
        return $product && ($product['stock_kg'] <= $product['low_stock_threshold']);
    }
    public function setActive($id, $active) {
        $active = $active ? 1 : 0;
        $stmt = $this->pdo->prepare('UPDATE products SET is_active=? WHERE id=?');
        $ok = $stmt->execute([$active, $id]);
        if ($ok) {
            $p = $this->getById($id);
            $this->logger->log([
                'action' => $active ? 'product_activate' : 'product_hide',
                'product_id' => (int)$id,
                'product_name' => $p['name'] ?? null,
                'details' => $active ? 'Activated for POS/display' : 'Hidden from POS/display',
                'stock_before_kg' => null,
                'stock_before_sack' => null,
                'stock_after_kg' => $p['stock_kg'] ?? null,
                'stock_after_sack' => $p['stock_sack'] ?? null,
            ]);
        }
        return $ok;
    }
} 