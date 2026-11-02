-- Optional: the application creates this table automatically if permitted.
-- Existing business data, keys, products and settings are preserved.
CREATE TABLE IF NOT EXISTS bot_cache (
    cache_key CHAR(64) PRIMARY KEY,
    payload MEDIUMTEXT NOT NULL,
    expires_at DATETIME NOT NULL,
    INDEX (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
