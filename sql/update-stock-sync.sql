CREATE TABLE IF NOT EXISTS stock_sync_log (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id BIGINT UNSIGNED NOT NULL,
  variation_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  action VARCHAR(40) NOT NULL,
  system_state VARCHAR(30) NOT NULL DEFAULT '',
  website_state VARCHAR(30) NOT NULL DEFAULT '',
  status VARCHAR(20) NOT NULL,
  detail VARCHAR(500) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL,
  KEY idx_product (product_id, variation_id),
  KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
