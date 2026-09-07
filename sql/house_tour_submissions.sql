-- User house-tour submissions from the public landing page (queue for admin processing).
-- New DB / Hostinger: run this file once.

CREATE TABLE IF NOT EXISTS house_tour_submissions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    listing_id BIGINT UNSIGNED NOT NULL,
    contact_email VARCHAR(255) NOT NULL,
    source ENUM('youtube', 'upload') NOT NULL,
    youtube_url VARCHAR(2048) NULL,
    original_filename VARCHAR(255) NULL,
    stored_path VARCHAR(512) NULL,
    status ENUM('pending', 'processing', 'processed', 'failed') NOT NULL DEFAULT 'pending',
    job_id VARCHAR(80) NULL,
    error_message VARCHAR(512) NULL,
    processed_at DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_listing_status (listing_id, status, created_at),
    KEY idx_status_created (status, created_at),
    KEY idx_contact_email (contact_email),
    CONSTRAINT fk_tour_sub_listing
        FOREIGN KEY (listing_id) REFERENCES zillow_sale_listings (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
