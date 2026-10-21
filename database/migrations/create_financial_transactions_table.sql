-- Financial Transactions table for marketplace settlements
CREATE TABLE IF NOT EXISTS `financial_transactions` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `marketplace_id` INT UNSIGNED NOT NULL,
    `transaction_type` VARCHAR(50) NOT NULL COMMENT 'sale,refund,commission,payment,stoppage,wire_transfer,invoice,other',
    `transaction_date` DATETIME NOT NULL,
    `order_number` VARCHAR(100) DEFAULT NULL,
    `description` VARCHAR(500) DEFAULT NULL,
    `gross_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `commission_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `net_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `currency` VARCHAR(5) NOT NULL DEFAULT 'TRY',
    `status` VARCHAR(30) DEFAULT 'completed',
    `external_id` VARCHAR(100) DEFAULT NULL COMMENT 'Marketplace transaction ID',
    `extra_data_json` TEXT DEFAULT NULL,
    `synced_at` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_ft_marketplace` (`marketplace_id`),
    INDEX `idx_ft_type` (`transaction_type`),
    INDEX `idx_ft_date` (`transaction_date`),
    INDEX `idx_ft_external` (`external_id`),
    CONSTRAINT `fk_ft_marketplace` FOREIGN KEY (`marketplace_id`) REFERENCES `marketplaces`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;
