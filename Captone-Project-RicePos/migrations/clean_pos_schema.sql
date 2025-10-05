-- Drop existing tables if they exist to avoid column mismatch errors
DROP TABLE IF EXISTS sale_items;
DROP TABLE IF EXISTS sales;
DROP TABLE IF EXISTS products;
DROP TABLE IF EXISTS users;

-- USERS TABLE
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(100) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('admin', 'staff') NOT NULL DEFAULT 'staff',
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active', -- Account status
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_login DATETIME NULL -- Last login timestamp
);

-- PRODUCTS TABLE
CREATE TABLE IF NOT EXISTS products (
    id INT AUTO_INCREMENT PRIMARY KEY, -- Product ID
    name VARCHAR(100) NOT NULL,        -- Product name
    price_per_kg DECIMAL(10,2) NOT NULL, -- Price per kilogram
    price_per_sack DECIMAL(10,2) DEFAULT NULL, -- Price per sack
    stock_kg DECIMAL(10,2) NOT NULL DEFAULT 0, -- Stock in kilograms
    stock_sack DECIMAL(10,2) NOT NULL DEFAULT 0, -- Stock in sacks
    category VARCHAR(50),              -- Product category (e.g., Dinorado, Jasmine, 25kg, 50kg)
    low_stock_threshold DECIMAL(10,2) NOT NULL DEFAULT 10, -- Low stock warning threshold
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- SALES TABLE
CREATE TABLE IF NOT EXISTS sales (
    id INT AUTO_INCREMENT PRIMARY KEY,
    transaction_id VARCHAR(30) NOT NULL UNIQUE,
    user_id INT NOT NULL,
    datetime DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    total_amount DECIMAL(10,2) NOT NULL,
    payment DECIMAL(10,2) NOT NULL,
    change_due DECIMAL(10,2) NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

-- SALE ITEMS TABLE
CREATE TABLE IF NOT EXISTS sale_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sale_id INT NOT NULL,
    product_id INT NOT NULL,
    quantity_kg DECIMAL(10,2) DEFAULT 0,
    quantity_sack DECIMAL(10,2) DEFAULT 0,
    price DECIMAL(10,2) NOT NULL,
    FOREIGN KEY (sale_id) REFERENCES sales(id),
    FOREIGN KEY (product_id) REFERENCES products(id)
); 