-- Listing agent contact fields on zillow_sale_listings.
-- Run once after is_active migration (or on any existing table).

ALTER TABLE zillow_sale_listings
    ADD COLUMN listing_agent_name VARCHAR(255) NULL AFTER is_active,
    ADD COLUMN listing_agent_phone VARCHAR(64) NULL AFTER listing_agent_name,
    ADD COLUMN listing_agent_email VARCHAR(255) NULL AFTER listing_agent_phone,
    ADD COLUMN listing_broker_name VARCHAR(255) NULL AFTER listing_agent_email,
    ADD COLUMN listing_agent_source VARCHAR(32) NULL AFTER listing_broker_name,
    ADD COLUMN listing_agent_raw_json LONGTEXT NULL AFTER listing_agent_source,
    ADD COLUMN listing_agent_fetched_at DATETIME NULL AFTER listing_agent_raw_json;
