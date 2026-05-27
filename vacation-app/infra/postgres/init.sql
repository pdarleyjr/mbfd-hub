-- Idempotent init script for the vacation database.
-- Runs after extensions.sql on a fresh container.
--
-- Drizzle's migrations create the tables; this file adds:
--   1. vacation_allowance(years_of_service) function
--   2. Convenience views (refreshed by the worker after commit)
--
-- Re-running this file is safe (CREATE OR REPLACE / IF NOT EXISTS).

-- ── Functions ──────────────────────────────────────────────────────

-- Hours per fiscal year by years of service.
-- < 10 yrs → 144 (6 × 24-hour shifts)
-- 10-20    → 204 (8.5)
-- 20+      → 264 (11)
CREATE OR REPLACE FUNCTION vacation_allowance(years_of_service integer)
RETURNS integer LANGUAGE sql IMMUTABLE AS $$
  SELECT CASE
    WHEN years_of_service IS NULL THEN 144
    WHEN years_of_service < 10 THEN 144
    WHEN years_of_service < 20 THEN 204
    ELSE 264
  END
$$;

-- Years of service from hire_date as of a reference date.
CREATE OR REPLACE FUNCTION years_of_service(hire_date date, as_of date DEFAULT current_date)
RETURNS integer LANGUAGE sql IMMUTABLE AS $$
  SELECT CASE
    WHEN hire_date IS NULL THEN NULL
    ELSE EXTRACT(YEAR FROM age(as_of, hire_date))::int
  END
$$;
