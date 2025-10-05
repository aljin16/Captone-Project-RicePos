-- Helpful indexes for dashboard performance
CREATE INDEX IF NOT EXISTS idx_sales_datetime ON sales(datetime);
CREATE INDEX IF NOT EXISTS idx_sale_items_sale_id ON sale_items(sale_id);
CREATE INDEX IF NOT EXISTS idx_sale_items_product_id ON sale_items(product_id);

-- Optional view for daily sales
CREATE OR REPLACE VIEW v_daily_sales AS
SELECT DATE(datetime) AS sale_date,
       SUM(total_amount) AS total_sales,
       COUNT(*) AS transactions
FROM sales
GROUP BY DATE(datetime);

-- Optional view: sales by category (last 30 days)
CREATE OR REPLACE VIEW v_sales_by_category_30d AS
SELECT COALESCE(p.category,'Uncategorized') AS category,
       SUM(si.price) AS total
FROM sale_items si
JOIN sales s ON s.id = si.sale_id
JOIN products p ON p.id = si.product_id
WHERE s.datetime >= CURDATE() - INTERVAL 30 DAY
GROUP BY COALESCE(p.category,'Uncategorized');


