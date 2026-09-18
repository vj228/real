-- Whether the listing agent has claimed this listing (unlocks phone/email on public house page).

ALTER TABLE zillow_sale_listings
    ADD COLUMN listing_agent_claimed TINYINT(1) NOT NULL DEFAULT 0 AFTER listing_agent_fetched_at;
