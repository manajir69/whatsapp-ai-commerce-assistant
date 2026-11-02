-- Demo Commerce Bot — database schema
-- MySQL 5.7+ / MariaDB 10.3+

SET NAMES utf8mb4;

-- ======================================================
-- ১. পণ্যের আয়না (WooCommerce থেকে টেনে আনা)
-- ======================================================
CREATE TABLE IF NOT EXISTS products (
  id            BIGINT UNSIGNED NOT NULL,          -- Woo product id
  variation_id  BIGINT UNSIGNED NOT NULL DEFAULT 0,-- 0 = parent / simple
  parent_id     BIGINT UNSIGNED NOT NULL DEFAULT 0,
  type          VARCHAR(20)  NOT NULL DEFAULT 'simple',
  sku           VARCHAR(100) NOT NULL DEFAULT '',
  name          VARCHAR(500) NOT NULL,
  attributes    TEXT NULL,                         -- JSON: {"Color":"Black-Large-60cm"}
  price         VARCHAR(50)  NOT NULL DEFAULT '',
  permalink     VARCHAR(500) NOT NULL DEFAULT '',
  categories    VARCHAR(500) NOT NULL DEFAULT '',
  description   MEDIUMTEXT NULL,
  woo_stock     VARCHAR(20)  NOT NULL DEFAULT '',  -- Woo-তে এখন কী আছে
  embedding     MEDIUMTEXT NULL,                   -- JSON array
  synced_at     DATETIME NOT NULL,
  PRIMARY KEY (id, variation_id),
  KEY idx_sku (sku),
  KEY idx_parent (parent_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ======================================================
-- ২. Stock DB — bot-এর নিজস্ব সত্য
-- ======================================================
CREATE TABLE IF NOT EXISTS stock_state (
  product_id   BIGINT UNSIGNED NOT NULL,
  variation_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  state        ENUM('unknown','in_stock','out_of_stock') NOT NULL DEFAULT 'unknown',
  checked_at   DATETIME NULL,
  valid_until  DATETIME NULL,        -- in_stock হলে checked_at + N দিন
  note         VARCHAR(500) NOT NULL DEFAULT '',
  source       VARCHAR(40)  NOT NULL DEFAULT '',   -- owner / sync / auto
  updated_at   DATETIME NOT NULL,
  PRIMARY KEY (product_id, variation_id),
  KEY idx_state (state),
  KEY idx_valid (valid_until)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ======================================================
-- ৩. Knowledge DB — bot যা শিখেছে
-- ======================================================
CREATE TABLE IF NOT EXISTS knowledge (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  question    VARCHAR(1000) NOT NULL,
  answer      TEXT NOT NULL,
  scope       ENUM('product','category','global') NOT NULL DEFAULT 'global',
  scope_ref   VARCHAR(200) NOT NULL DEFAULT '',   -- product id / category slug
  embedding   MEDIUMTEXT NULL,
  source      VARCHAR(40) NOT NULL DEFAULT 'owner', -- owner / woo / seed
  hits        INT UNSIGNED NOT NULL DEFAULT 0,
  expires_at  DATE NULL,                          -- দাম/অফার জাতীয় তথ্যের মেয়াদ
  approved    TINYINT(1) NOT NULL DEFAULT 1,
  created_at  DATETIME NOT NULL,
  KEY idx_scope (scope, scope_ref)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ======================================================
-- ৪. কথোপকথন
-- ======================================================
CREATE TABLE IF NOT EXISTS conversations (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  wa_id         VARCHAR(30) NOT NULL,             -- customer phone
  name          VARCHAR(200) NOT NULL DEFAULT '',
  is_owner      TINYINT(1) NOT NULL DEFAULT 0,
  last_product  BIGINT UNSIGNED NOT NULL DEFAULT 0,
  last_variation BIGINT UNSIGNED NOT NULL DEFAULT 0,
  handoff       TINYINT(1) NOT NULL DEFAULT 0,    -- 1 = মানুষ সামলাচ্ছে, bot চুপ
  owner_seen_id BIGINT UNSIGNED NOT NULL DEFAULT 0, -- last inbound message read in dashboard
  created_at    DATETIME NOT NULL,
  last_at       DATETIME NOT NULL,
  UNIQUE KEY uq_wa (wa_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS messages (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  conv_id    INT UNSIGNED NOT NULL,
  direction  ENUM('in','out') NOT NULL,
  body       TEXT NOT NULL,
  meta       TEXT NULL,
  wa_msg_id  VARCHAR(120) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL,
  KEY idx_conv (conv_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ======================================================
-- ৫. টিকিট — মালিকের উত্তরের অপেক্ষায়
-- ======================================================
CREATE TABLE IF NOT EXISTS tickets (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  conv_id      INT UNSIGNED NOT NULL,
  kind         ENUM('stock','info','handoff') NOT NULL DEFAULT 'stock',
  product_id   BIGINT UNSIGNED NOT NULL DEFAULT 0,
  variation_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  question     TEXT NOT NULL,
  status       ENUM('queued','sent_owner','answered','closed') NOT NULL DEFAULT 'queued',
  owner_msg_id VARCHAR(120) NOT NULL DEFAULT '',
  answer       TEXT NULL,
  created_at   DATETIME NOT NULL,
  sent_at      DATETIME NULL,
  answered_at  DATETIME NULL,
  nudged_at    DATETIME NULL,
  KEY idx_status (status),
  KEY idx_prod (product_id, variation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ======================================================
-- ৬. অডিট লগ — প্রতিটা পরিবর্তনের হিসাব
-- ======================================================
CREATE TABLE IF NOT EXISTS audit_log (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  action     VARCHAR(60) NOT NULL,
  detail     TEXT NULL,
  before_val VARCHAR(200) NOT NULL DEFAULT '',
  after_val  VARCHAR(200) NOT NULL DEFAULT '',
  dry_run    TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  KEY idx_action (action, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
  k VARCHAR(60) PRIMARY KEY,
  v TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Customer database / CRM. Marketing permission must be set explicitly.
CREATE TABLE IF NOT EXISTS customer_profiles (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  conv_id INT UNSIGNED NOT NULL,
  wa_id VARCHAR(30) NOT NULL,
  name VARCHAR(200) NOT NULL DEFAULT '',
  location_text VARCHAR(1000) NOT NULL DEFAULT '',
  location_lat VARCHAR(40) NOT NULL DEFAULT '',
  location_lng VARCHAR(40) NOT NULL DEFAULT '',
  location_source VARCHAR(30) NOT NULL DEFAULT '',
  product_interest TEXT NULL,
  last_query TEXT NULL,
  first_seen DATETIME NOT NULL,
  last_seen DATETIME NOT NULL,
  message_count INT UNSIGNED NOT NULL DEFAULT 0,
  marketing_status ENUM('unknown','opted_in','opted_out') NOT NULL DEFAULT 'unknown',
  consent_at DATETIME NULL,
  consent_request_sent_at DATETIME NULL,
  consent_request_message_id VARCHAR(120) NOT NULL DEFAULT '',
  consent_response_at DATETIME NULL,
  consent_response VARCHAR(30) NOT NULL DEFAULT '',
  consent_source VARCHAR(40) NOT NULL DEFAULT '',
  tags VARCHAR(500) NOT NULL DEFAULT '',
  notes TEXT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  UNIQUE KEY uq_customer_conv (conv_id),
  UNIQUE KEY uq_customer_wa (wa_id),
  KEY idx_customer_last (last_seen),
  KEY idx_customer_marketing (marketing_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS marketing_campaign_log (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id BIGINT UNSIGNED NOT NULL,
  wa_id VARCHAR(30) NOT NULL,
  delivery_mode VARCHAR(30) NOT NULL,
  message_text TEXT NOT NULL,
  template_name VARCHAR(200) NOT NULL DEFAULT '',
  wa_msg_id VARCHAR(120) NOT NULL DEFAULT '',
  status VARCHAR(30) NOT NULL,
  detail VARCHAR(500) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL,
  KEY idx_campaign_customer (customer_id,created_at),
  KEY idx_campaign_status (status,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS marketing_campaign_queue (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  batch_key VARCHAR(40) NOT NULL,
  customer_id BIGINT UNSIGNED NOT NULL,
  delivery_mode VARCHAR(30) NOT NULL,
  message_text TEXT NOT NULL,
  template_name VARCHAR(200) NOT NULL DEFAULT '',
  template_language VARCHAR(20) NOT NULL DEFAULT '',
  status VARCHAR(30) NOT NULL DEFAULT 'queued',
  created_at DATETIME NOT NULL,
  processed_at DATETIME NULL,
  KEY idx_campaign_queue (status,id),
  KEY idx_campaign_batch (batch_key,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS customer_product_queries (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id BIGINT UNSIGNED NOT NULL,
  message_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  product_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  variation_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  product_name VARCHAR(500) NOT NULL DEFAULT '',
  sku VARCHAR(100) NOT NULL DEFAULT '',
  query_text TEXT NOT NULL,
  created_at DATETIME NOT NULL,
  UNIQUE KEY uq_customer_query_message (customer_id,message_id),
  KEY idx_customer_query (customer_id,created_at),
  KEY idx_customer_product (product_id,variation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Owner may answer early; the customer receives the confirmed stock result at the promised time.
CREATE TABLE IF NOT EXISTS stock_reply_queue (
  ticket_id INT UNSIGNED PRIMARY KEY,
  state VARCHAR(30) NOT NULL,
  deliver_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL,
  KEY idx_deliver_at (deliver_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

CREATE TABLE IF NOT EXISTS api_usage (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  kind VARCHAR(20) NOT NULL, provider VARCHAR(30) NOT NULL, model VARCHAR(150) NOT NULL,
  input_tokens INT UNSIGNED NOT NULL DEFAULT 0, cached_tokens INT UNSIGNED NOT NULL DEFAULT 0,
  output_tokens INT UNSIGNED NOT NULL DEFAULT 0, total_tokens INT UNSIGNED NOT NULL DEFAULT 0,
  estimated_usd DECIMAL(12,8) NOT NULL DEFAULT 0, created_at DATETIME NOT NULL,
  KEY idx_created(created_at), KEY idx_kind(kind)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS reply_feedback (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  message_id BIGINT UNSIGNED NOT NULL DEFAULT 0, conv_id INT UNSIGNED NOT NULL DEFAULT 0,
  customer_question TEXT NOT NULL, bot_reply TEXT NOT NULL, preferred_reply TEXT NOT NULL,
  created_at DATETIME NOT NULL, KEY idx_conv(conv_id), KEY idx_message(message_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
