-- Add workflow tracking columns to delivery_orders (MySQL/MariaDB compatible)
-- Run these statements one by one. It's OK if some fail with "Duplicate column name".

-- Add picked_up_at timestamp
ALTER TABLE delivery_orders ADD COLUMN picked_up_at DATETIME NULL;

-- Add delivered_at timestamp
ALTER TABLE delivery_orders ADD COLUMN delivered_at DATETIME NULL;

-- Add failed_reason
ALTER TABLE delivery_orders ADD COLUMN failed_reason VARCHAR(255) NULL;

-- Add updated_at (last status change)
ALTER TABLE delivery_orders ADD COLUMN updated_at DATETIME NULL;

-- Note: If a column already exists, MySQL will throw error 1060. That's expected; proceed to the next.

