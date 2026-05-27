-- 0003_indexes_and_cascades.sql
-- Performance + integrity follow-ups uncovered by the deep-debug review:
-- 1. Rollback's WHERE superseded_by_entry_id = ANY(...) was a sequential
--    scan of the entire leave_entries table.
-- 2. Deleting an import_runs row left orphan import_run_rows because the
--    FK lacked ON DELETE CASCADE.

CREATE INDEX IF NOT EXISTS "leave_entries_superseded_by_idx"
  ON "leave_entries" ("superseded_by_entry_id")
  WHERE "superseded_by_entry_id" IS NOT NULL;

-- Drop and re-create the FK with ON DELETE CASCADE. Safe even if the
-- constraint name differs across environments.
DO $$
DECLARE
  fk_name text;
BEGIN
  SELECT conname INTO fk_name
  FROM pg_constraint
  WHERE conrelid = 'import_run_rows'::regclass
    AND contype = 'f'
    AND pg_get_constraintdef(oid) LIKE '%REFERENCES import_runs%';
  IF fk_name IS NOT NULL THEN
    EXECUTE format('ALTER TABLE import_run_rows DROP CONSTRAINT %I', fk_name);
  END IF;
END$$;

ALTER TABLE "import_run_rows"
  ADD CONSTRAINT "import_run_rows_import_run_id_import_runs_id_fk"
  FOREIGN KEY ("import_run_id") REFERENCES "import_runs" ("id")
  ON DELETE CASCADE;
