-- Attach referral attribution to tour submissions.
-- Run after sql/agent_referrals.sql (and house_tour_submissions exists).

ALTER TABLE house_tour_submissions
    ADD COLUMN referral_code VARCHAR(32) NULL AFTER contact_email,
    ADD COLUMN agent_referral_id BIGINT UNSIGNED NULL AFTER referral_code,
    ADD KEY idx_tour_referral_code (referral_code),
    ADD KEY idx_tour_agent_referral (agent_referral_id);
