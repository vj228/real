-- Drop legacy intake/home-offer submissions table.
-- Run once on production after deploying code that no longer uses it.

DROP TABLE IF EXISTS home_offer_form_submissions;
