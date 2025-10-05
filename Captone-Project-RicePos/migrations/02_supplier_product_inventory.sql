-- Supplier Table
CREATE TABLE IF NOT EXISTS supplier (
    supplier_id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    contact VARCHAR(100),
    phone VARCHAR(30),
    email VARCHAR(100),
    address VARCHAR(255)
);

-- Product Table
CREATE TABLE IF NOT EXISTS product (
    product_id INT AUTO_INCREMENT PRIMARY KEY,
    supplier_id INT,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    price DECIMAL(10,2) NOT NULL,
    quantity_in_stock INT NOT NULL DEFAULT 0,
    low_stock_threshold INT NOT NULL DEFAULT 0,
    image VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (supplier_id) REFERENCES supplier(supplier_id)
);

-- Inventory Log Table
CREATE TABLE IF NOT EXISTS inventorylog (
    log_id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    user_id INT NOT NULL,
    change_type ENUM('add', 'remove', 'adjust') NOT NULL,
    log_date DATETIME DEFAULT CURRENT_TIMESTAMP,
    quantity_change INT NOT NULL,
    FOREIGN KEY (product_id) REFERENCES product(product_id),
    FOREIGN KEY (user_id) REFERENCES user(user_id)
); 

-- Stock movements table for Stock-In/Out recording (sacks only)
CREATE TABLE IF NOT EXISTS stock_movements (
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
);