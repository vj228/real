-- Marketing / traffic attribution (Hostinger MySQL 8+ compatible)
-- Run in phpMyAdmin or MySQL client connected to your database

CREATE TABLE IF NOT EXISTS marketing_page_visits (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    visited_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    http_method VARCHAR(10) NOT NULL DEFAULT 'GET',
    http_host VARCHAR(255) NOT NULL DEFAULT '',
    page_path VARCHAR(2048) NOT NULL DEFAULT '',
    query_string VARCHAR(2048) NOT NULL DEFAULT '',
    full_url VARCHAR(4096) NOT NULL DEFAULT '',
    referrer TEXT NULL,
    referrer_host VARCHAR(255) NULL,
    ip_address VARCHAR(45) NULL,
    geo_city VARCHAR(120) NULL,
    geo_region VARCHAR(120) NULL,
    geo_country VARCHAR(120) NULL,
    geo_country_code CHAR(2) NULL,
    user_agent TEXT NULL,
    device_category ENUM('desktop','mobile','tablet','bot','unknown') NOT NULL DEFAULT 'unknown',
    php_session_id VARCHAR(128) NULL,
    utm_source VARCHAR(255) NULL,
    utm_medium VARCHAR(255) NULL,
    utm_campaign VARCHAR(255) NULL,
    utm_content VARCHAR(255) NULL,
    utm_term VARCHAR(255) NULL,
    PRIMARY KEY (id),
    KEY idx_marketing_visits_time (visited_at),
    KEY idx_marketing_visits_ip (ip_address),
    KEY idx_marketing_visits_referrer_host (referrer_host),
    KEY idx_marketing_visits_path (page_path(191)),
    KEY idx_marketing_visits_utm_source (utm_source(64)),
    KEY idx_marketing_visits_host (http_host),
    KEY idx_marketing_geo_country (geo_country_code),
    KEY idx_marketing_geo_city (geo_city(64))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Existing DB without idx_marketing_visits_ip:
-- ALTER TABLE marketing_page_visits ADD KEY idx_marketing_visits_ip (ip_address);
