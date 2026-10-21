-- Marketplace Soru & Cevap tablosu
CREATE TABLE IF NOT EXISTS `questions` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `marketplace_id` INT UNSIGNED NOT NULL,
    `marketplace_question_id` VARCHAR(100) NOT NULL COMMENT 'Pazaryerindeki soru ID',
    `product_id` INT UNSIGNED DEFAULT NULL COMMENT 'Yerel ürün ID (eşleşirse)',
    `marketplace_product_id` VARCHAR(100) DEFAULT NULL COMMENT 'Pazaryerindeki ürün ID',
    `product_name` VARCHAR(500) DEFAULT NULL,
    `product_image_url` VARCHAR(1000) DEFAULT NULL,
    `question_text` TEXT NOT NULL,
    `question_date` DATETIME NOT NULL,
    `customer_name` VARCHAR(200) DEFAULT NULL,
    `answer_text` TEXT DEFAULT NULL,
    `answer_date` DATETIME DEFAULT NULL,
    `status` VARCHAR(30) NOT NULL DEFAULT 'waiting' COMMENT 'waiting, answered, rejected',
    `is_public` TINYINT(1) NOT NULL DEFAULT 1,
    `extra_data_json` TEXT DEFAULT NULL,
    `synced_at` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_q_marketplace` (`marketplace_id`),
    INDEX `idx_q_status` (`status`),
    INDEX `idx_q_date` (`question_date`),
    INDEX `idx_q_product` (`product_id`),
    INDEX `idx_q_mp_qid` (`marketplace_question_id`),
    UNIQUE KEY `uk_q_mp_qid` (`marketplace_id`, `marketplace_question_id`),
    CONSTRAINT `fk_q_marketplace` FOREIGN KEY (`marketplace_id`)
        REFERENCES `marketplaces`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;
