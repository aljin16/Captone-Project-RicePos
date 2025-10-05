-- Delivery orders table to track deliveries associated with sales
CREATE TABLE IF NOT EXISTS delivery_orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sale_id INT NOT NULL,
    customer_name VARCHAR(120) NOT NULL,
    customer_phone VARCHAR(40) DEFAULT NULL,
    customer_address TEXT NOT NULL,
    notes VARCHAR(255) DEFAULT NULL,
    status ENUM('pending','out_for_delivery','delivered','cancelled') NOT NULL DEFAULT 'pending',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL,
    FOREIGN KEY (sale_id) REFERENCES sales(id)
);

CREATE INDEX IF NOT EXISTS idx_delivery_sale_id ON delivery_orders(sale_id);
CREATE INDEX IF NOT EXISTS idx_delivery_status ON delivery_orders(status);


