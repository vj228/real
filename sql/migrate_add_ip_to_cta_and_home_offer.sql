-- Add ip_address alongside marketing_visit_id (hybrid attribution).
-- Run once if your tables already exist without ip_address.

ALTER TABLE marketing_cta_clicks
    ADD COLUMN ip_address VARCHAR(45) NULL COMMENT 'Client IP at click' AFTER http_host,
    ADD KEY idx_marketing_cta_ip (ip_address, clicked_at);

ALTER TABLE home_offer_form_submissions
    ADD COLUMN ip_address VARCHAR(45) NULL COMMENT 'Client IP at submit' AFTER marketing_visit_id,
    ADD KEY idx_home_offer_ip (ip_address);
