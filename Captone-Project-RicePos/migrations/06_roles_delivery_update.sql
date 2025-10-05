-- Unified schema update for roles and delivery features
-- Run this once in your ricepos database (phpMyAdmin or MySQL CLI)

-- 1) Users role enum: add sales_staff and delivery_staff, set default to sales_staff
ALTER TABLE users MODIFY COLUMN role ENUM('admin','sales_staff','delivery_staff') NOT NULL DEFAULT 'sales_staff';

-- 2) Update any legacy 'staff' to 'sales_staff' for consistency
UPDATE users SET role = 'sales_staff' WHERE role = 'staff';

-- 3) Delivery orders table creation or alteration
CREATE TABLE IF NOT EXISTS delivery_orders (
  id INT AUTO_INCREMENT PRIMARY KEY,
  sale_id INT NOT NULL,
  customer_name VARCHAR(120) NOT NULL,
  customer_phone VARCHAR(40) DEFAULT NULL,
  customer_address TEXT NOT NULL,
  notes VARCHAR(255) DEFAULT NULL,
  status ENUM('pending','picked_up','in_transit','delivered','failed','cancelled') NOT NULL DEFAULT 'pending',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT NULL,
  assigned_to INT DEFAULT NULL,
  customer_lat DECIMAL(10,7) DEFAULT NULL,
  customer_lng DECIMAL(10,7) DEFAULT NULL,
  route_json MEDIUMTEXT DEFAULT NULL,
  customer_email VARCHAR(190) DEFAULT NULL,
  proof_image VARCHAR(255) DEFAULT NULL,
  delivered_at DATETIME DEFAULT NULL,
  failed_reason VARCHAR(255) DEFAULT NULL,
  CONSTRAINT fk_delivery_sale FOREIGN KEY (sale_id) REFERENCES sales(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 3b) If table exists, widen enum and add columns as needed (errors on duplicates are OK to ignore)
ALTER TABLE delivery_orders MODIFY COLUMN status ENUM('pending','picked_up','in_transit','delivered','failed','cancelled') NOT NULL DEFAULT 'pending';
ALTER TABLE delivery_orders ADD COLUMN assigned_to INT NULL;
ALTER TABLE delivery_orders ADD COLUMN customer_lat DECIMAL(10,7) NULL;
ALTER TABLE delivery_orders ADD COLUMN customer_lng DECIMAL(10,7) NULL;
ALTER TABLE delivery_orders ADD COLUMN route_json MEDIUMTEXT NULL;
ALTER TABLE delivery_orders ADD COLUMN customer_email VARCHAR(190) NULL;
ALTER TABLE delivery_orders ADD COLUMN proof_image VARCHAR(255) NULL;
ALTER TABLE delivery_orders ADD COLUMN delivered_at DATETIME NULL;
ALTER TABLE delivery_orders ADD COLUMN failed_reason VARCHAR(255) NULL;

-- 4) Helpful indices
CREATE INDEX idx_delivery_sale_id ON delivery_orders(sale_id);
CREATE INDEX idx_delivery_status ON delivery_orders(status);
CREATE INDEX idx_delivery_assigned_to ON delivery_orders(assigned_to);


