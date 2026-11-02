CREATE TABLE IF NOT EXISTS stock_reply_queue (
  ticket_id INT UNSIGNED PRIMARY KEY,
  state VARCHAR(30) NOT NULL,
  deliver_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL,
  KEY idx_deliver_at (deliver_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
