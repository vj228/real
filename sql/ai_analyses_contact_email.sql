-- Contact email for renovation estimate notifications (no signup).
-- Run once on existing DBs.

ALTER TABLE ai_analyses
    ADD COLUMN contact_email VARCHAR(255) NULL AFTER video_title,
    ADD KEY idx_contact_email (contact_email);
