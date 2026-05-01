-- Add geography columns for existing installs (run once)

ALTER TABLE marketing_page_visits
    ADD COLUMN geo_city VARCHAR(120) NULL AFTER ip_address,
    ADD COLUMN geo_region VARCHAR(120) NULL AFTER geo_city,
    ADD COLUMN geo_country VARCHAR(120) NULL AFTER geo_region,
    ADD COLUMN geo_country_code CHAR(2) NULL AFTER geo_country,
    ADD KEY idx_marketing_geo_country (geo_country_code);
