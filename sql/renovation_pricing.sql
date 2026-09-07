-- Renovation work-code pricing (used by Gemini analysis → PHP cost calc).
-- New DB / Hostinger: run this file once.

CREATE TABLE IF NOT EXISTS renovation_pricing (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    code VARCHAR(64) NOT NULL,
    title VARCHAR(128) NOT NULL,
    estimate_low INT UNSIGNED NOT NULL,
    estimate_high INT UNSIGNED NOT NULL,
    category VARCHAR(32) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_code (code),
    KEY idx_active_sort (is_active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO renovation_pricing (code, title, estimate_low, estimate_high, category, sort_order) VALUES
    ('paint_room', 'Paint room', 800, 2500, 'general', 10),
    ('drywall_repair', 'Drywall repair', 400, 1800, 'general', 20),
    ('floor_refinish', 'Refinish floors', 1500, 4500, 'general', 30),
    ('floor_replace', 'Replace flooring', 3500, 9000, 'general', 40),
    ('baseboard_replace', 'Replace baseboards', 400, 1500, 'general', 50),
    ('countertop_replace', 'Replace countertop', 2500, 6000, 'kitchen', 60),
    ('cabinet_refinish', 'Refinish cabinets', 2000, 5000, 'kitchen', 70),
    ('cabinet_replace', 'Replace cabinets', 8000, 18000, 'kitchen', 80),
    ('backsplash_replace', 'Replace backsplash', 800, 2500, 'kitchen', 90),
    ('sink_replace', 'Replace sink', 400, 1200, 'kitchen', 100),
    ('faucet_replace', 'Replace faucet', 250, 800, 'kitchen', 110),
    ('range_hood_replace', 'Replace range hood', 600, 2200, 'kitchen', 120),
    ('bath_vanity_replace', 'Replace vanity', 1200, 3500, 'bathroom', 130),
    ('bath_countertop_replace', 'Replace bath countertop', 800, 2500, 'bathroom', 140),
    ('toilet_replace', 'Replace toilet', 350, 900, 'bathroom', 150),
    ('shower_update', 'Update shower', 2500, 8000, 'bathroom', 160),
    ('tub_replace', 'Replace tub', 2000, 6500, 'bathroom', 170),
    ('bath_tile_replace', 'Replace bath tile', 2000, 7000, 'bathroom', 180),
    ('light_fixture_replace', 'Replace light fixtures', 200, 900, 'general', 190),
    ('door_replace', 'Replace door', 400, 1500, 'general', 200),
    ('closet_update', 'Update closet', 800, 3000, 'bedroom', 210)
ON DUPLICATE KEY UPDATE
    title = VALUES(title),
    estimate_low = VALUES(estimate_low),
    estimate_high = VALUES(estimate_high),
    category = VALUES(category),
    sort_order = VALUES(sort_order),
    is_active = 1;
