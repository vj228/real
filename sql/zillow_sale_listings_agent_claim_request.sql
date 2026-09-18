-- Pending listing-agent claim requests (partner code → listing).

ALTER TABLE zillow_sale_listings
    ADD COLUMN listing_agent_claim_agent_id INT NULL AFTER listing_agent_claimed,
    ADD COLUMN listing_agent_claim_requested_at DATETIME NULL AFTER listing_agent_claim_agent_id;
