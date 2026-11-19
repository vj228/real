-- Dedicated open_house text so it can be edited without touching facts_json.
ALTER TABLE sms_home_facts
  ADD COLUMN open_house VARCHAR(500) NULL DEFAULT NULL AFTER facts_json;

-- Backfill from any existing JSON values.
UPDATE sms_home_facts
SET open_house = NULLIF(TRIM(JSON_UNQUOTE(JSON_EXTRACT(facts_json, '$.open_house'))), '')
WHERE JSON_EXTRACT(facts_json, '$.open_house') IS NOT NULL
  AND JSON_TYPE(JSON_EXTRACT(facts_json, '$.open_house')) <> 'NULL';
