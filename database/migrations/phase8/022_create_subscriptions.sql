-- Faz 8 / 022 — Abonelik katmanı (store-bağlı ama BaseModel-tenant-scope DEĞİL; admin cross-store yönetir,
-- merchant kendi verisini açık WHERE store_id ile okur). subscriptions + subscription_history +
-- subscription_invoices + payments.
-- DİKKAT: subscription_invoices = SaaS abonelik faturası (platform→mağaza). GİB merchant→alıcı `invoices`
-- tablosuyla ALAKASI YOKTUR; ayrı tablo/model/route. Numara öneki SUB-.

CREATE TABLE IF NOT EXISTS `subscriptions` (
  `id`                   int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `store_id`             int(10) UNSIGNED NOT NULL,
  `package_id`           int(10) UNSIGNED NOT NULL,
  `status`               enum('trial','active','passive','suspended','expired','cancelled') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'trial',
  `billing_cycle`        enum('monthly','yearly') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'monthly',
  `trial_ends_at`        datetime DEFAULT NULL,
  `current_period_start` datetime DEFAULT NULL,
  `current_period_end`   datetime DEFAULT NULL,
  `started_at`           datetime DEFAULT NULL,
  `cancelled_at`         datetime DEFAULT NULL,
  `created_by`           int(10) UNSIGNED DEFAULT NULL,
  `created_at`           datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at`           datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sub_store` (`store_id`),
  KEY `idx_sub_package` (`package_id`),
  KEY `idx_sub_status_period` (`status`, `current_period_end`),
  KEY `idx_sub_status_trial` (`status`, `trial_ends_at`),
  CONSTRAINT `fk_sub_store`   FOREIGN KEY (`store_id`)   REFERENCES `stores` (`id`)   ON DELETE CASCADE  ON UPDATE CASCADE,
  CONSTRAINT `fk_sub_package` FOREIGN KEY (`package_id`) REFERENCES `packages` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `subscription_history` (
  `id`              int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `store_id`        int(10) UNSIGNED NOT NULL,
  `subscription_id` int(10) UNSIGNED NOT NULL,
  `old_package_id`  int(10) UNSIGNED DEFAULT NULL,
  `new_package_id`  int(10) UNSIGNED DEFAULT NULL,
  `old_status`      varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `new_status`      varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `action`          enum('provision','upgrade','downgrade','renew','suspend','cancel','reactivate','expire') COLLATE utf8mb4_unicode_ci NOT NULL,
  `changed_by`      int(10) UNSIGNED DEFAULT NULL,
  `reason`          varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at`      datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_subhist_store` (`store_id`, `created_at`),
  KEY `idx_subhist_sub` (`subscription_id`),
  CONSTRAINT `fk_subhist_store` FOREIGN KEY (`store_id`)        REFERENCES `stores` (`id`)        ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_subhist_sub`   FOREIGN KEY (`subscription_id`) REFERENCES `subscriptions` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `subscription_invoices` (
  `id`              int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `store_id`        int(10) UNSIGNED NOT NULL,
  `subscription_id` int(10) UNSIGNED NOT NULL,
  `number`          varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `period_start`    date DEFAULT NULL,
  `period_end`      date DEFAULT NULL,
  `amount`          decimal(10,2) NOT NULL DEFAULT 0.00,
  `currency`        char(3) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'TRY',
  `status`          enum('draft','sent','paid','overdue','void') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `due_at`          datetime DEFAULT NULL,
  `paid_at`         datetime DEFAULT NULL,
  `created_at`      datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at`      datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_subinv_number` (`number`),
  KEY `idx_subinv_store` (`store_id`),
  KEY `idx_subinv_sub` (`subscription_id`),
  KEY `idx_subinv_status_due` (`status`, `due_at`),
  CONSTRAINT `fk_subinv_store` FOREIGN KEY (`store_id`)        REFERENCES `stores` (`id`)        ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_subinv_sub`   FOREIGN KEY (`subscription_id`) REFERENCES `subscriptions` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `payments` (
  `id`                      int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `store_id`                int(10) UNSIGNED NOT NULL,
  `subscription_invoice_id` int(10) UNSIGNED NOT NULL,
  `method`                  enum('havale','eft','manual','card') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'havale',
  `amount`                  decimal(10,2) NOT NULL DEFAULT 0.00,
  `currency`                char(3) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'TRY',
  `status`                  enum('pending','confirmed','rejected') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `receipt_path`            varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `bank_ref`                varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `note`                    text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `confirmed_by`            int(10) UNSIGNED DEFAULT NULL,
  `confirmed_at`            datetime DEFAULT NULL,
  `created_at`              datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at`              datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_pay_store` (`store_id`),
  KEY `idx_pay_invoice` (`subscription_invoice_id`),
  KEY `idx_pay_status` (`status`),
  CONSTRAINT `fk_pay_store`   FOREIGN KEY (`store_id`)                REFERENCES `stores` (`id`)                ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_pay_invoice` FOREIGN KEY (`subscription_invoice_id`) REFERENCES `subscription_invoices` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
