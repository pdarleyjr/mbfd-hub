-- 0002_staffing_engine.sql
-- Adds tables + columns that back the Daily Shift Staffing Guidelines
-- (v1.13, 12/1/25) decision engine: leave hours/assignment first-class,
-- member station + certifications for Marine Station and AT/DE
-- pairing rules, and a singleton staffing_rules table with an audit log.

-- 1) leave_entries gains first-class `hours` and `assignment` columns
ALTER TABLE "leave_entries"
  ADD COLUMN IF NOT EXISTS "hours" numeric(6,2),
  ADD COLUMN IF NOT EXISTS "assignment" text;

-- Backfill from the Telestaff raw JSONB so existing rows light up
-- without re-importing. (`Hours` arrives as a JSON number; cast to
-- numeric; `Assignment` is a free-text bucket like "Shift Assignment"
-- or "Days Assignment".)
UPDATE "leave_entries"
SET "hours" = NULLIF((raw_telestaff_row ->> 'Hours'), '')::numeric
WHERE "hours" IS NULL
  AND raw_telestaff_row ? 'Hours';

UPDATE "leave_entries"
SET "assignment" = NULLIF(raw_telestaff_row ->> 'Assignment', '')
WHERE "assignment" IS NULL
  AND raw_telestaff_row ? 'Assignment';

CREATE INDEX IF NOT EXISTS "leave_entries_member_hours_idx"
  ON "leave_entries" ("member_id", "leave_code_id");

-- 2) members gain station + certifications (admin-managed; Telestaff
-- doesn't export these).
ALTER TABLE "members"
  ADD COLUMN IF NOT EXISTS "station" text,
  ADD COLUMN IF NOT EXISTS "certifications" jsonb NOT NULL DEFAULT '[]'::jsonb;

CREATE INDEX IF NOT EXISTS "members_station_idx" ON "members" ("station");

-- 3) staffing_rules — singleton row holding the configurable thresholds
-- from the guidelines doc. Admin UI updates `rules_json`; an
-- application-side default ensures the engine has values until an
-- admin opens the page for the first time.
CREATE TABLE IF NOT EXISTS "staffing_rules" (
  "id" uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  "singleton" boolean NOT NULL DEFAULT true,
  "rules_json" jsonb NOT NULL,
  "updated_at" timestamptz NOT NULL DEFAULT now(),
  "updated_by_pin_hash" text,
  CONSTRAINT "staffing_rules_singleton_uk" UNIQUE ("singleton")
);

INSERT INTO "staffing_rules" ("rules_json")
VALUES (
  '{
    "minDailyStaffing": 51,
    "airTechTrigger": 52,
    "minPreScheduleStaffing": 53,
    "airTechNoLeaveUntilLocalHour": 20,
    "marineFirefighterOffCap": 2,
    "exchangeShiftsCapPerYear": 10,
    "exchangeHoursCapPerYear": 240,
    "rankPairingRules": {
      "FF":    ["FF", "FF-DE"],
      "FF-DE": ["FF-DE", "FF"],
      "LT":    ["LT"],
      "CAPT":  ["CAPT"],
      "DC":    ["DC", "CAPT"],
      "DDC":   ["DDC", "DC", "CAPT"],
      "CHIEF": ["CHIEF", "DDC", "DC"]
    },
    "aDayExchangePairingRules": {
      "officers": ["LT", "CAPT", "DC", "DDC", "CHIEF"],
      "firefighters": ["FF", "FF-DE"]
    },
    "exchangeLeaveCodes": ["XOFF", "EON"],
    "marineStationKey": "M6",
    "stationOptions": ["S1", "S2", "S3", "S4", "S5", "M6", "FLOAT", "HQ", "OTHER"],
    "certificationOptions": ["DE", "AT", "M6_QUAL", "M6_CAPT_QUAL", "PARAMEDIC", "PROMO_CAPT", "PROMO_LT"]
  }'::jsonb
)
ON CONFLICT ("singleton") DO NOTHING;

-- 4) Audit log of every rule change so we know who changed what when.
CREATE TABLE IF NOT EXISTS "staffing_rules_audit" (
  "id" uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  "changed_at" timestamptz NOT NULL DEFAULT now(),
  "changed_by_pin_hash" text,
  "previous_rules_json" jsonb,
  "new_rules_json" jsonb NOT NULL
);

CREATE INDEX IF NOT EXISTS "staffing_rules_audit_changed_at_idx"
  ON "staffing_rules_audit" ("changed_at" DESC);
