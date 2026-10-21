-- ============================================================
-- Migration: Müşteri tablosu ekle + orders'a customer_id bağla
-- © 2025 OziyWeb
-- ============================================================

CREATE TABLE IF NOT EXISTS `customers` (
    `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name`            VARCHAR(300)  NOT NULL,
    `email`           VARCHAR(200)  NULL DEFAULT NULL,
    `phone`           VARCHAR(20)   NULL DEFAULT NULL,
    `id_no`           VARCHAR(20)   NULL DEFAULT NULL,
    `tax_no`          VARCHAR(20)   NULL DEFAULT NULL,
    `tax_office`      VARCHAR(200)  NULL DEFAULT NULL,
    `address`         TEXT          NULL DEFAULT NULL,
    `city`            VARCHAR(100)  NULL DEFAULT NULL,
    `district`        VARCHAR(100)  NULL DEFAULT NULL,
    `total_orders`    INT           NOT NULL DEFAULT 0,
    `total_spent`     DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    `first_order_at`  DATETIME      NULL DEFAULT NULL,
    `last_order_at`   DATETIME      NULL DEFAULT NULL,
    `notes`           TEXT          NULL DEFAULT NULL,
    `created_at`      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_customers_email`  (`email`),
    INDEX `idx_customers_phone`  (`phone`),
    INDEX `idx_customers_id_no`  (`id_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- orders tablosuna customer_id ekle
ALTER TABLE `orders`
    ADD COLUMN `customer_id` INT UNSIGNED NULL DEFAULT NULL AFTER `marketplace_order_id`,
    ADD INDEX `idx_orders_customer` (`customer_id`),
    ADD CONSTRAINT `fk_orders_customer` FOREIGN KEY (`customer_id`)
        REFERENCES `customers` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;
