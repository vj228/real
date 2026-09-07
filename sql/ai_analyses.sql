-- AI renovation analyses (Gemini). Links to zillow_sale_listings.
-- New DB / Hostinger: run this file once.
-- If you still have yhome_ai_analyses: RENAME TABLE yhome_ai_analyses TO ai_analyses;

CREATE TABLE IF NOT EXISTS ai_analyses (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    listing_id BIGINT UNSIGNED NOT NULL,
    job_id VARCHAR(80) NOT NULL,
    youtube_url VARCHAR(2048) NULL,
    video_id VARCHAR(64) NULL,
    video_title VARCHAR(512) NULL,
    contact_email VARCHAR(255) NULL,
    model VARCHAR(64) NULL,
    images_used INT UNSIGNED NULL,
    total_low INT UNSIGNED NOT NULL DEFAULT 0,
    total_high INT UNSIGNED NOT NULL DEFAULT 0,
    rooms_json JSON NOT NULL,
    gemini_raw_json JSON NULL,
    analyzed_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_job_id (job_id),
    KEY idx_listing_id (listing_id),
    KEY idx_listing_analyzed (listing_id, analyzed_at),
    KEY idx_contact_email (contact_email),
    CONSTRAINT fk_yai_listing
        FOREIGN KEY (listing_id) REFERENCES zillow_sale_listings (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
