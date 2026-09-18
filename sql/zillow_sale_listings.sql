-- Zillow listings (cron/rapid.php). New DB: run CREATE only. Existing table: run ALTER block once.

CREATE TABLE IF NOT EXISTS zillow_sale_listings (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    last_fetched_at DATETIME NOT NULL,
    search_query VARCHAR(255) NOT NULL,
    zpid VARCHAR(32) NOT NULL,
    address VARCHAR(512) NOT NULL,
    detail_url VARCHAR(2048) NULL,
    list_price DECIMAL(14, 2) NULL,
    zestimate DECIMAL(14, 2) NULL,
    price_vs_zestimate_pct DECIMAL(7, 2) NULL,
    price_per_sqft DECIMAL(10, 2) NULL,
    property_tax_annual DECIMAL(12, 2) NULL,
    tax_assessed_value DECIMAL(14, 2) NULL,
    hoa_fee DECIMAL(10, 2) NULL,
    beds DECIMAL(4, 1) NULL,
    baths DECIMAL(4, 1) NULL,
    sqft INT UNSIGNED NULL,
    days_on_zillow INT UNSIGNED NULL,
    img_src VARCHAR(2048) NULL,
    images_json JSON NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    listing_agent_name VARCHAR(255) NULL,
    listing_agent_phone VARCHAR(64) NULL,
    listing_agent_email VARCHAR(255) NULL,
    listing_broker_name VARCHAR(255) NULL,
    listing_agent_source VARCHAR(32) NULL,
    listing_agent_raw_json LONGTEXT NULL,
    listing_agent_fetched_at DATETIME NULL,
    listing_agent_claimed TINYINT(1) NOT NULL DEFAULT 0,
    listing_agent_claim_agent_id INT NULL,
    listing_agent_claim_requested_at DATETIME NULL,
    sent_to_j2v TINYINT(1) NOT NULL DEFAULT 0,
    sent_to_j2v_at DATETIME NULL,
    j2v_file_url VARCHAR(2048) NULL,
    j2v_file_type VARCHAR(32) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_zpid (zpid),
    KEY idx_created_at (created_at),
    KEY idx_is_active (is_active),
    KEY idx_sent_to_j2v (sent_to_j2v),
    KEY idx_search_fetched (search_query, last_fetched_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Upgrade older tables (uncomment and run once; ignore duplicate-column errors):
/*
ALTER TABLE zillow_sale_listings
    ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER images_json,
    ADD COLUMN sent_to_j2v TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active,
    ADD COLUMN sent_to_j2v_at DATETIME NULL AFTER sent_to_j2v,
    ADD COLUMN j2v_file_url VARCHAR(2048) NULL AFTER sent_to_j2v_at,
    ADD COLUMN j2v_file_type VARCHAR(32) NULL AFTER j2v_file_url,
    ADD KEY idx_is_active (is_active),
    ADD KEY idx_sent_to_j2v (sent_to_j2v);
*/
