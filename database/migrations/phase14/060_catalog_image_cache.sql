CREATE TABLE IF NOT EXISTS catalog_image_checks (
 store_id INT UNSIGNED NOT NULL,
 url_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 result_json TEXT NOT NULL,
 checked_at DATETIME NOT NULL,
 PRIMARY KEY (store_id,url_hash), KEY image_check_age (store_id,checked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
