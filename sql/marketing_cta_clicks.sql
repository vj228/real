-- CTA click events — marketing_visit_id (exact page view) + ip_address (fallback / cohort)
-- Prerequisites: sql/marketing_page_visits.sql (FK references marketing_page_visits.id)

CREATE TABLE IF NOT EXISTS marketing_cta_clicks (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    clicked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    cta_id VARCHAR(64) NOT NULL COMMENT 'Slug from data-cta-id',
    page_path VARCHAR(2048) NOT NULL DEFAULT '',
    http_host VARCHAR(255) NOT NULL DEFAULT '',
    ip_address VARCHAR(45) NULL COMMENT 'Client IP at beacon (same normalization as marketing_page_visits)',
    marketing_visit_id BIGINT UNSIGNED NULL COMMENT 'marketing_page_visits.id for this pageload',
    referrer VARCHAR(4096) NULL,
    PRIMARY KEY (id),
    KEY idx_marketing_cta_time (cta_id, clicked_at),
    KEY idx_marketing_cta_day (clicked_at, cta_id),
    KEY idx_marketing_cta_ip (ip_address, clicked_at),
    KEY idx_marketing_cta_visit (marketing_visit_id),
    CONSTRAINT fk_marketing_cta_visit
        FOREIGN KEY (marketing_visit_id) REFERENCES marketing_page_visits(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
