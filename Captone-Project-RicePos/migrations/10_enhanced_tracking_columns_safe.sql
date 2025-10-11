-- Enhanced tracking columns for improved customer tracking experience
-- Migration: 10_enhanced_tracking_columns_safe.sql
-- This version uses stored procedures to safely check column existence before adding

-- Temporary stored procedure to add column only if it doesn't exist
DELIMITER $$

DROP PROCEDURE IF EXISTS AddColumnIfNotExists$$
CREATE PROCEDURE AddColumnIfNotExists(
    IN tableName VARCHAR(128),
    IN columnName VARCHAR(128),
    IN columnDefinition VARCHAR(1000)
)
BEGIN
    DECLARE columnExists INT DEFAULT 0;
    
    SELECT COUNT(*) INTO columnExists
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = tableName
        AND COLUMN_NAME = columnName;
    
    IF columnExists = 0 THEN
        SET @ddl = CONCAT('ALTER TABLE ', tableName, ' ADD COLUMN ', columnName, ' ', columnDefinition);
        PREPARE stmt FROM @ddl;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END$$

DELIMITER ;

-- Add columns using the safe procedure
CALL AddColumnIfNotExists('delivery_orders', 'proof_image', 
    "VARCHAR(255) DEFAULT NULL COMMENT 'Filename of delivery proof photo'");

CALL AddColumnIfNotExists('delivery_orders', 'delivered_at', 
    "DATETIME DEFAULT NULL COMMENT 'Exact timestamp of delivery'");

CALL AddColumnIfNotExists('delivery_orders', 'failed_reason', 
    "VARCHAR(255) DEFAULT NULL COMMENT 'Reason if delivery failed'");

CALL AddColumnIfNotExists('delivery_orders', 'customer_rating', 
    "TINYINT DEFAULT NULL COMMENT 'Customer rating 1-5 stars'");

CALL AddColumnIfNotExists('delivery_orders', 'customer_feedback', 
    "TEXT DEFAULT NULL COMMENT 'Customer feedback comment'");

CALL AddColumnIfNotExists('delivery_orders', 'feedback_submitted_at', 
    "DATETIME DEFAULT NULL COMMENT 'When feedback was submitted'");

-- Add indexes if they don't exist (MySQL will error if they exist, that's OK)
CREATE INDEX idx_delivery_rating ON delivery_orders(customer_rating);
CREATE INDEX idx_delivery_feedback_date ON delivery_orders(feedback_submitted_at);

-- Clean up the stored procedure
DROP PROCEDURE IF EXISTS AddColumnIfNotExists;

