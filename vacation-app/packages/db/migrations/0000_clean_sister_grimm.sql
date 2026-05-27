CREATE TABLE IF NOT EXISTS "ranks" (
	"id" uuid PRIMARY KEY DEFAULT gen_random_uuid() NOT NULL,
	"code" text NOT NULL,
	"label" text NOT NULL,
	"sort_order" integer NOT NULL,
	"is_officer" boolean DEFAULT false NOT NULL,
	CONSTRAINT "ranks_code_unique" UNIQUE("code")
);
--> statement-breakpoint
CREATE TABLE IF NOT EXISTS "a_day_groups" (
	"id" uuid PRIMARY KEY DEFAULT gen_random_uuid() NOT NULL,
	"code" text NOT NULL,
	"label" text NOT NULL,
	CONSTRAINT "a_day_groups_code_unique" UNIQUE("code")
);
--> statement-breakpoint
CREATE TABLE IF NOT EXISTS "members" (
	"id" uuid PRIMARY KEY DEFAULT gen_random_uuid() NOT NULL,
	"employee_id" text NOT NULL,
	"badge_number" text,
	"last_name" text NOT NULL,
	"first_name" text NOT NULL,
	"hire_date" date,
	"rank_id" uuid,
	"shift" text,
	"a_day_group_id" uuid,
	"is_probationary" boolean DEFAULT false NOT NULL,
	"is_active" boolean DEFAULT true NOT NULL,
	"source_import_run_id" uuid,
	"created_at" timestamp with time zone DEFAULT now() NOT NULL,
	"updated_at" timestamp with time zone DEFAULT now() NOT NULL,
	CONSTRAINT "members_employee_id_unique" UNIQUE("employee_id")
);
--> statement-breakpoint
CREATE TABLE IF NOT EXISTS "calendar_days" (
	"id" uuid PRIMARY KEY DEFAULT gen_random_uuid() NOT NULL,
	"date" date NOT NULL,
	"fiscal_year" integer NOT NULL,
	"calendar_year" integer NOT NULL,
	"day_of_week" integer NOT NULL,
	"pay_period" integer,
	CONSTRAINT "calendar_days_date_unique" UNIQUE("date")
);
--> statement-breakpoint
CREATE TABLE IF NOT EXISTS "shift_blocks" (
	"id" uuid PRIMARY KEY DEFAULT gen_random_uuid() NOT NULL,
	"calendar_day_id" uuid NOT NULL,
	"block_index" integer NOT NULL,
	"start_at" timestamp with time zone NOT NULL,
	"end_at" timestamp with time zone NOT NULL
);
--> statement-breakpoint
CREATE TABLE IF NOT EXISTS "leave_codes" (
	"id" uuid PRIMARY KEY DEFAULT gen_random_uuid() NOT NULL,
	"code" text NOT NULL,
	"label" text NOT NULL,
	"description" text,
	"ui_color" text DEFAULT '#78716C' NOT NULL,
	"counts_against_vacation_balance" boolean DEFAULT false NOT NULL,
	"counts_against_floating_balance" boolean DEFAULT false NOT NULL,
	"counts_against_daily_vacation_capacity" boolean DEFAULT false NOT NULL,
	"counts_against_total_off_capacity" boolean DEFAULT true NOT NULL,
	"counts_against_minimum_staffing" boolean DEFAULT false NOT NULL,
	"is_a_day_marker" boolean DEFAULT false NOT NULL,
	CONSTRAINT "leave_codes_code_unique" UNIQUE("code")
);
--> statement-breakpoint
CREATE TABLE IF NOT EXISTS "work_code_mappings" (
	"id" uuid PRIMARY KEY DEFAULT gen_random_uuid() NOT NULL,
	"telestaff_description" text NOT NULL,
	"leave_code_id" uuid NOT NULL,
	"created_at" timestamp with time zone DEFAULT now() NOT NULL,
	CONSTRAINT "work_code_mappings_telestaff_description_unique" UNIQUE("telestaff_description")
);
--> statement-breakpoint
CREATE TABLE IF NOT EXISTS "import_runs" (
	"id" uuid PRIMARY KEY DEFAULT gen_random_uuid() NOT NULL,
	"file_name" text NOT NULL,
	"file_size" bigint NOT NULL,
	"file_sha256" text NOT NULL,
	"r2_key" text NOT NULL,
	"uploaded_at" timestamp with time zone DEFAULT now() NOT NULL,
	"uploaded_by_pin_hash" text,
	"status" text DEFAULT 'uploaded' NOT NULL,
	"column_mapping_json" jsonb,
	"work_code_decisions_json" jsonb,
	"parse_stats" jsonb,
	"error_message" text,
	"started_at" timestamp with time zone,
	"finished_at" timestamp with time zone
);
--> statement-breakpoint
CREATE TABLE IF NOT EXISTS "leave_entries" (
	"id" uuid PRIMARY KEY DEFAULT gen_random_uuid() NOT NULL,
	"member_id" uuid NOT NULL,
	"shift_block_id" uuid NOT NULL,
	"leave_code_id" uuid NOT NULL,
	"source_import_run_id" uuid NOT NULL,
	"superseded_by_entry_id" uuid,
	"raw_telestaff_row" jsonb NOT NULL,
	"created_at" timestamp with time zone DEFAULT now() NOT NULL
);
--> statement-breakpoint
CREATE TABLE IF NOT EXISTS "import_column_maps" (
	"id" uuid PRIMARY KEY DEFAULT gen_random_uuid() NOT NULL,
	"name" text NOT NULL,
	"mapping_json" jsonb NOT NULL,
	"created_at" timestamp with time zone DEFAULT now() NOT NULL,
	CONSTRAINT "import_column_maps_name_unique" UNIQUE("name")
);
--> statement-breakpoint
CREATE TABLE IF NOT EXISTS "import_run_rows" (
	"id" uuid PRIMARY KEY DEFAULT gen_random_uuid() NOT NULL,
	"import_run_id" uuid NOT NULL,
	"row_index" integer NOT NULL,
	"raw_row_json" jsonb NOT NULL,
	"parsed_status" text NOT NULL,
	"error_message" text
);
--> statement-breakpoint
CREATE TABLE IF NOT EXISTS "pin_audit" (
	"id" uuid PRIMARY KEY DEFAULT gen_random_uuid() NOT NULL,
	"ip" text,
	"user_agent" text,
	"outcome" text NOT NULL,
	"attempted_at" timestamp with time zone DEFAULT now() NOT NULL
);
--> statement-breakpoint
DO $$ BEGIN
 ALTER TABLE "members" ADD CONSTRAINT "members_rank_id_ranks_id_fk" FOREIGN KEY ("rank_id") REFERENCES "public"."ranks"("id") ON DELETE no action ON UPDATE no action;
EXCEPTION
 WHEN duplicate_object THEN null;
END $$;
--> statement-breakpoint
DO $$ BEGIN
 ALTER TABLE "members" ADD CONSTRAINT "members_a_day_group_id_a_day_groups_id_fk" FOREIGN KEY ("a_day_group_id") REFERENCES "public"."a_day_groups"("id") ON DELETE no action ON UPDATE no action;
EXCEPTION
 WHEN duplicate_object THEN null;
END $$;
--> statement-breakpoint
DO $$ BEGIN
 ALTER TABLE "shift_blocks" ADD CONSTRAINT "shift_blocks_calendar_day_id_calendar_days_id_fk" FOREIGN KEY ("calendar_day_id") REFERENCES "public"."calendar_days"("id") ON DELETE no action ON UPDATE no action;
EXCEPTION
 WHEN duplicate_object THEN null;
END $$;
--> statement-breakpoint
DO $$ BEGIN
 ALTER TABLE "work_code_mappings" ADD CONSTRAINT "work_code_mappings_leave_code_id_leave_codes_id_fk" FOREIGN KEY ("leave_code_id") REFERENCES "public"."leave_codes"("id") ON DELETE no action ON UPDATE no action;
EXCEPTION
 WHEN duplicate_object THEN null;
END $$;
--> statement-breakpoint
DO $$ BEGIN
 ALTER TABLE "leave_entries" ADD CONSTRAINT "leave_entries_member_id_members_id_fk" FOREIGN KEY ("member_id") REFERENCES "public"."members"("id") ON DELETE no action ON UPDATE no action;
EXCEPTION
 WHEN duplicate_object THEN null;
END $$;
--> statement-breakpoint
DO $$ BEGIN
 ALTER TABLE "leave_entries" ADD CONSTRAINT "leave_entries_shift_block_id_shift_blocks_id_fk" FOREIGN KEY ("shift_block_id") REFERENCES "public"."shift_blocks"("id") ON DELETE no action ON UPDATE no action;
EXCEPTION
 WHEN duplicate_object THEN null;
END $$;
--> statement-breakpoint
DO $$ BEGIN
 ALTER TABLE "leave_entries" ADD CONSTRAINT "leave_entries_leave_code_id_leave_codes_id_fk" FOREIGN KEY ("leave_code_id") REFERENCES "public"."leave_codes"("id") ON DELETE no action ON UPDATE no action;
EXCEPTION
 WHEN duplicate_object THEN null;
END $$;
--> statement-breakpoint
DO $$ BEGIN
 ALTER TABLE "leave_entries" ADD CONSTRAINT "leave_entries_source_import_run_id_import_runs_id_fk" FOREIGN KEY ("source_import_run_id") REFERENCES "public"."import_runs"("id") ON DELETE no action ON UPDATE no action;
EXCEPTION
 WHEN duplicate_object THEN null;
END $$;
--> statement-breakpoint
DO $$ BEGIN
 ALTER TABLE "import_run_rows" ADD CONSTRAINT "import_run_rows_import_run_id_import_runs_id_fk" FOREIGN KEY ("import_run_id") REFERENCES "public"."import_runs"("id") ON DELETE no action ON UPDATE no action;
EXCEPTION
 WHEN duplicate_object THEN null;
END $$;
--> statement-breakpoint
CREATE INDEX IF NOT EXISTS "members_shift_lastname_idx" ON "members" USING btree ("shift","last_name");--> statement-breakpoint
CREATE INDEX IF NOT EXISTS "members_active_idx" ON "members" USING btree ("is_active");--> statement-breakpoint
CREATE INDEX IF NOT EXISTS "calendar_days_fy_idx" ON "calendar_days" USING btree ("fiscal_year");--> statement-breakpoint
CREATE UNIQUE INDEX IF NOT EXISTS "shift_blocks_day_block_uk" ON "shift_blocks" USING btree ("calendar_day_id","block_index");--> statement-breakpoint
CREATE INDEX IF NOT EXISTS "shift_blocks_start_idx" ON "shift_blocks" USING btree ("start_at");--> statement-breakpoint
CREATE INDEX IF NOT EXISTS "import_runs_status_idx" ON "import_runs" USING btree ("status");--> statement-breakpoint
CREATE INDEX IF NOT EXISTS "import_runs_uploaded_at_idx" ON "import_runs" USING btree ("uploaded_at");--> statement-breakpoint
CREATE INDEX IF NOT EXISTS "import_runs_sha_idx" ON "import_runs" USING btree ("file_sha256");--> statement-breakpoint
CREATE UNIQUE INDEX IF NOT EXISTS "leave_entries_active_uk" ON "leave_entries" USING btree ("member_id","shift_block_id") WHERE "leave_entries"."superseded_by_entry_id" IS NULL;--> statement-breakpoint
CREATE INDEX IF NOT EXISTS "leave_entries_block_code_idx" ON "leave_entries" USING btree ("shift_block_id","leave_code_id");--> statement-breakpoint
CREATE INDEX IF NOT EXISTS "leave_entries_member_idx" ON "leave_entries" USING btree ("member_id");--> statement-breakpoint
CREATE INDEX IF NOT EXISTS "leave_entries_source_idx" ON "leave_entries" USING btree ("source_import_run_id");--> statement-breakpoint
CREATE INDEX IF NOT EXISTS "import_run_rows_run_idx" ON "import_run_rows" USING btree ("import_run_id");--> statement-breakpoint
CREATE INDEX IF NOT EXISTS "import_run_rows_status_idx" ON "import_run_rows" USING btree ("parsed_status");--> statement-breakpoint
CREATE INDEX IF NOT EXISTS "pin_audit_attempted_idx" ON "pin_audit" USING btree ("attempted_at");