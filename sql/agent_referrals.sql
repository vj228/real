-- Agent partner referral MVP
-- Run once on Hostinger / local MySQL.

CREATE TABLE IF NOT EXISTS agents (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    referral_code VARCHAR(32) NOT NULL,
    name VARCHAR(120) NOT NULL DEFAULT '',
    email VARCHAR(255) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uq_agents_referral_code (referral_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS agent_referrals (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    agent_id INT UNSIGNED NOT NULL,
    referral_code VARCHAR(32) NOT NULL,
    listing_id BIGINT UNSIGNED NULL,
    visitor_key VARCHAR(64) NOT NULL,
    php_session_id VARCHAR(128) NULL,
    status ENUM(
        'visited',
        'walkthrough_uploaded',
        'renovation_inquiry',
        'qualified',
        'paid'
    ) NOT NULL DEFAULT 'visited',
    earnings_amount DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    tour_submission_id BIGINT UNSIGNED NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_agent_listing_visitor (agent_id, listing_id, visitor_key),
    KEY idx_agent_created (agent_id, created_at),
    KEY idx_referral_code (referral_code),
    KEY idx_status (status),
    KEY idx_tour_submission (tour_submission_id),
    CONSTRAINT fk_agent_referrals_agent
        FOREIGN KEY (agent_id) REFERENCES agents (id)
        ON DELETE CASCADE,
    CONSTRAINT fk_agent_referrals_listing
        FOREIGN KEY (listing_id) REFERENCES zillow_sale_listings (id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed demo partner (safe to re-run).
INSERT INTO agents (referral_code, name, email, is_active)
SELECT 'AGT102', 'Demo Agent', NULL, 1
WHERE NOT EXISTS (
    SELECT 1 FROM agents WHERE referral_code = 'AGT102'
);
