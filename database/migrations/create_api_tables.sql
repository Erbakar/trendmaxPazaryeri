-- ═══════════════════════════════════════════════════════
-- OzyConnect — API Tokens & Logs Tables
-- ═══════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS `api_tokens` (
    `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name`            VARCHAR(200) NOT NULL COMMENT 'Token açıklaması',
    `api_key`         VARCHAR(64) NOT NULL UNIQUE COMMENT 'Public API Key (hex)',
    `api_secret_hash` VARCHAR(255) NOT NULL COMMENT 'Bcrypt hashed secret',
    `permissions`     JSON NOT NULL COMMENT 'İzin listesi',
    `rate_limit`      INT UNSIGNED NOT NULL DEFAULT 60 COMMENT 'Dakikadaki max istek',
    `is_active`       TINYINT(1) NOT NULL DEFAULT 1,
    `ip_whitelist`    JSON NULL COMMENT 'İzin verilen IP listesi (null = tümü)',
    `last_used_at`    DATETIME NULL,
    `expires_at`      DATETIME NULL COMMENT 'NULL = süresiz',
    `created_by`      INT UNSIGNED NULL,
    `created_at`      DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_api_tokens_key` (`api_key`),
    INDEX `idx_api_tokens_active` (`is_active`),
    FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `api_logs` (
    `id`              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `api_token_id`    INT UNSIGNED NULL,
    `method`          VARCHAR(10) NOT NULL,
    `endpoint`        VARCHAR(500) NOT NULL,
    `status_code`     SMALLINT UNSIGNED NOT NULL DEFAULT 200,
    `response_time_ms` INT UNSIGNED NOT NULL DEFAULT 0,
    `ip_address`      VARCHAR(45) NOT NULL,
    `request_body`    TEXT NULL,
    `response_summary` VARCHAR(500) NULL,
    `created_at`      DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_api_logs_token` (`api_token_id`),
    INDEX `idx_api_logs_created` (`created_at`),
    INDEX `idx_api_logs_endpoint` (`endpoint`(100)),
    FOREIGN KEY (`api_token_id`) REFERENCES `api_tokens` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
