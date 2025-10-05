-- Extend delivery_orders with geo columns for precise routing
ALTER TABLE delivery_orders
    ADD COLUMN IF NOT EXISTS customer_lat DECIMAL(10,7) NULL,
    ADD COLUMN IF NOT EXISTS customer_lng DECIMAL(10,7) NULL,
    ADD COLUMN IF NOT EXISTS route_json MEDIUMTEXT NULL; -- store last computed route polyline/geojson snapshot

CREATE INDEX IF NOT EXISTS idx_delivery_geo ON delivery_orders(customer_lat, customer_lng);


