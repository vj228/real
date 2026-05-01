-- Intake submission + affordability report snapshot.
-- Matches form `affordability-form` fields (intake.php) + JS calculateReport() output.
--
-- (1) New database: run the CREATE TABLE block below only.
--
-- (2) Existing installs that created this table without report_* columns:
--     run the ALTER TABLE ... block at the bottom (once). Ignore duplicate-column errors
--     if any column already exists, or remove those lines before running.

CREATE TABLE IF NOT EXISTS home_offer_form_submissions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    marketing_visit_id BIGINT UNSIGNED NULL COMMENT 'marketing_page_visits.id',
    ip_address VARCHAR(45) NULL COMMENT 'Client IP at submit (same normalization as marketing_page_visits)',
    property_address VARCHAR(2048) NOT NULL,
    annual_income DECIMAL(14, 2) NOT NULL,
    monthly_debt DECIMAL(12, 2) NOT NULL,
    offer_price DECIMAL(14, 2) NOT NULL,
    down_payment DECIMAL(14, 2) NOT NULL,
    monthly_hoa DECIMAL(12, 2) NULL,
    interest_rate_percent DECIMAL(5, 2) NULL,
    property_tax_rate_percent DECIMAL(5, 3) NULL,
    credit_score_range VARCHAR(20) NOT NULL,
    email VARCHAR(255) NOT NULL,
    report_estimated_home_price DECIMAL(14, 2) NULL,
    report_monthly_mortgage INT NULL,
    report_monthly_property_tax INT NULL,
    report_monthly_insurance INT NULL,
    report_monthly_true_cost INT NULL,
    report_monthly_flex_cash INT NULL,
    report_affordability_score TINYINT UNSIGNED NULL,
    PRIMARY KEY (id),
    KEY idx_home_offer_created (created_at),
    KEY idx_home_offer_marketing_visit (marketing_visit_id),
    KEY idx_home_offer_ip (ip_address),
    KEY idx_home_offer_email (email(128)),
    KEY idx_home_offer_credit (credit_score_range),
    KEY idx_home_offer_address_prefix (property_address(191)),
    KEY idx_home_offer_report_score (report_affordability_score),
    KEY idx_home_offer_estimated_home (report_estimated_home_price),
    CONSTRAINT fk_home_offer_marketing_visit
        FOREIGN KEY (marketing_visit_id) REFERENCES marketing_page_visits(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Upgrade path: legacy table without report_* columns (run once if needed)
-- ---------------------------------------------------------------------------

/*
ALTER TABLE home_offer_form_submissions
    ADD COLUMN report_estimated_home_price DECIMAL(14, 2) NULL AFTER email,
    ADD COLUMN report_monthly_mortgage INT NULL AFTER report_estimated_home_price,
    ADD COLUMN report_monthly_property_tax INT NULL AFTER report_monthly_mortgage,
    ADD COLUMN report_monthly_insurance INT NULL AFTER report_monthly_property_tax,
    ADD COLUMN report_monthly_true_cost INT NULL AFTER report_monthly_insurance,
    ADD COLUMN report_monthly_flex_cash INT NULL AFTER report_monthly_true_cost,
    ADD COLUMN report_affordability_score TINYINT UNSIGNED NULL AFTER report_monthly_flex_cash,
    ADD KEY idx_home_offer_report_score (report_affordability_score),
    ADD KEY idx_home_offer_estimated_home (report_estimated_home_price);
*/

-- Add ip_address to existing DB: sql/migrate_add_ip_to_cta_and_home_offer.sql

-- Legacy cleanup (optional; run only DROPs for columns that still exist):
--
-- ALTER TABLE home_offer_form_submissions DROP COLUMN report_payload_json;
--
-- ALTER TABLE home_offer_form_submissions
--     DROP COLUMN report_decision_line,
--     DROP COLUMN report_decision_tone,
--     DROP COLUMN report_decision_message,
--     DROP COLUMN report_insight_message;
