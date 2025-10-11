-- Enhanced tracking columns for improved customer tracking experience
-- Migration: 10_enhanced_tracking_columns.sql
-- 
-- NOTE: proof_image, delivered_at, and failed_reason already exist from migrations 06 and 09
-- This migration only adds NEW columns for customer feedback feature

-- Add customer_rating column (NEW - for 5-star rating system)
ALTER TABLE delivery_orders
    ADD COLUMN customer_rating TINYINT DEFAULT NULL COMMENT 'Customer rating 1-5 stars';

-- Add customer_feedback column (NEW - for customer comments)
ALTER TABLE delivery_orders
    ADD COLUMN customer_feedback TEXT DEFAULT NULL COMMENT 'Customer feedback comment';

-- Add feedback_submitted_at column (NEW - timestamp for feedback)
ALTER TABLE delivery_orders
    ADD COLUMN feedback_submitted_at DATETIME DEFAULT NULL COMMENT 'When feedback was submitted';

-- Create indexes for faster feedback queries
CREATE INDEX idx_delivery_rating ON delivery_orders(customer_rating);
CREATE INDEX idx_delivery_feedback_date ON delivery_orders(feedback_submitted_at);

