CREATE TABLE IF NOT EXISTS product_media (
    product_id BIGINT UNSIGNED NOT NULL,
    variation_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    image_url VARCHAR(1000) NOT NULL,
    image_hash CHAR(64) NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY(product_id,variation_id),
    INDEX(image_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
