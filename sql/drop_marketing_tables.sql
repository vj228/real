-- Remove unused marketing visit / CTA tracking.
-- Run once on production after deploying code that no longer uses these tables.
-- If fk_home_offer_marketing_visit was already dropped, skip the ALTER lines.

-- ALTER TABLE home_offer_form_submissions DROP FOREIGN KEY fk_home_offer_marketing_visit;
-- ALTER TABLE home_offer_form_submissions DROP COLUMN marketing_visit_id;

DROP TABLE IF EXISTS marketing_cta_clicks;
DROP TABLE IF EXISTS marketing_page_visits;
