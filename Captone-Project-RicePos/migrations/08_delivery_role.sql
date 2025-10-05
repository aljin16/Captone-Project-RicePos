-- Delivery role start
ALTER TABLE `users` MODIFY `role` ENUM('admin','staff','delivery') NOT NULL DEFAULT 'staff';

CREATE TABLE `deliveries` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `sale_id` INT NOT NULL,
  `delivery_person_id` INT,
  `status` ENUM('Pending', 'Out for Delivery', 'Delivered', 'Failed') NOT NULL DEFAULT 'Pending',
  `remarks` TEXT,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`sale_id`) REFERENCES `sales`(`id`),
  FOREIGN KEY (`delivery_person_id`) REFERENCES `users`(`id`)
);
-- Delivery role end
