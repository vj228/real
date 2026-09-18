-- Soft-deactivate outdated Zillow listings on the public site.
-- Run once on Hostinger / local.

ALTER TABLE zillow_sale_listings
    ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER images_json,
    ADD KEY idx_is_active (is_active);

-- Hide all current (outdated) listings from houses.php
UPDATE zillow_sale_listings SET is_active = 0;
