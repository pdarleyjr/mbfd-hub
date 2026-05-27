# MBFD Vacation Selection — Design Spec

> **Status**: APPROVED for implementation (autonomous mode, 2026-05-27)
> **Author**: Claude Opus 4.7 (1M context)
> **Date**: 2026-05-27
> **Project**: MBFD Hub — Vacation Selection Web App (V1 MVP)
> **Locked decisions** (from user, 2026-05-27):
> 1. V1 scope = Telestaff import + read-only board (no request workflow)
> 2. Host on GMKtec EVO-X2 (Ubuntu 26), exposed via existing `mbfdhub-gmktec` Cloudflare Tunnel
> 3. Subdomain: `vacation.mbfdhub.com`
> 4. PIN gate model: single shared department PIN, identical pattern to `bid.mbfdhub.com`
> 5. Stack: Next.js 15 + Hono + Drizzle + Postgres 16 + BullMQ + R2
> 6. V1 admin task: ONLY the import workflow (upload → map columns → preview → commit)
> 7. Flexible column-mapping importer (works for ANY Telestaff XLSX/CSV)
> **Design language**: MBFD `.impeccable.md` — Red-700 brand, Slate-850 admin, Stone-* warm neutrals, Plus Jakarta Sans + Source Sans 3, tabular-nums, professional easing, NO bouncy motion.

---

## 1. Purpose & Success Criteria

Replace the **FY25 Vacation Selection Master V6.xlsx** workflow's *display surface* with a
web application that ingests Telestaff exports of arbitrary size and renders the same
vacation board on desktop and mobile. V1 is intentionally read-only — the live request
workflow, approval engine, and admin overrides ship in later phases.

### V1 Success Criteria

| # | Criterion | How verified |
|---|-----------|--------------|
| S1 | App starts empty; admin uploads a Telestaff XLSX/CSV and the board populates with every member, leave entry, and code | End-to-end staging test with a real Telestaff export |
| S2 | Telestaff files up to 500 MB import without OOM, without HTTP timeouts, without losing rows | Stress test: synthetic 500 MB CSV in staging |
| S3 | The board renders 365 days × 221 members × 2 blocks on mobile without jank | 60 fps virtual-scroll on a stock iPhone in staging |
| S4 | Every imported row is preserved in Postgres + R2 with a SHA-256 hash | DB and R2 inspection after import |
| S5 | An admin with no coding skills can complete an import from scratch in under 5 minutes | Recorded walkthrough |
| S6 | The app cannot affect MBFDHub, ts-orchestrator, ScreenTinker, or any other GMKtec stack | Separate Docker network, separate Postgres, separate volumes, separate Cloudflare hostname |
| S7 | The PIN gate cannot be bypassed; no app route is reachable without the cookie | Pen-test of the CF Worker route |
| S8 | A bad import can be rolled back in one click without losing earlier imports | Re-run with `superseded_by_entry_id` marker, rollback button restores |

### Non-goals (V1)

- **Not** a request/approval workflow — Phase 2.
- **Not** a staffing capacity engine — Phase 2.
- **Not** a member-facing app yet — V1 is admin-only.
- **Not** an HR system or scheduling system — Telestaff remains source of truth.
- **Not** integrated with MBFDHub auth — single shared PIN only.
- **Not** publicly accessible or indexed.

---

## 2. Users & Use Cases (V1)

### Admin (Fire Chief, Deputy Chief, Operations DC, designated officer)

- Hit `vacation.mbfdhub.com`, enter shared department PIN
- Navigate to `/import`
- Upload Telestaff export file (XLSX or CSV, any size)
- See a preview of detected columns and proposed mapping
- Adjust column mappings if needed
- Adjust unknown work-code mappings (every `Description` not yet in `work_code_mappings` is queued for one-time decision)
- Click "Commit import" → background worker processes file, board populates
- Browse the resulting board at `/board` filtered by shift, rank, date range
- Optionally roll back the import from `/import/runs/{id}` if it was wrong

### Member / Public

- V1: Not addressed. Members cannot log in or take any action. PIN gate is open to anyone with the PIN, but there are no member-specific actions yet.

---

## 3. Domain Architecture

```
                           vacation.mbfdhub.com
                                    │
                  Cloudflare DNS → CF Tunnel "mbfdhub-gmktec"
                                    │
                       ┌─ Cloudflare Worker (PIN gate) ──┐
                       │  - Renders PIN form             │
                       │  - Validates PIN → HMAC cookie  │
                       │  - Proxies to tunnel after pass │
                       └────────────────┬────────────────┘
                                        │
                              GMKtec EVO-X2 (Ubuntu 26)
                                        │
            ┌──────────────────────────┼──────────────────────────┐
            │                          │                          │
       Next.js 15                  Hono API                   Postgres 16
       (Node SSR)                  (Node 22)                  (own DB only)
       /board, /import             /api/import/*
       /admin, /404                /api/board/*
            ▲                          │
            │                          ▼
            └──── HTTP same-origin ──> BullMQ ──> Redis 7
                                        │
                                        ▼
                                  R2 bucket (raw imports)
                                  mbfd-hub-laravel/vacation/*
```

### Container layout (Docker Compose)

| Service | Image base | Purpose |
|---------|-----------|---------|
| `vac-web` | `node:22-alpine` | Next.js 15 SSR server, port 3000 internal |
| `vac-api` | `node:22-alpine` | Hono API server, port 3001 internal |
| `vac-worker` | `node:22-alpine` | BullMQ consumer (parses Telestaff files) |
| `vac-postgres` | `postgres:16-alpine` | Database, internal only |
| `vac-redis` | `redis:7-alpine` | BullMQ broker, internal only |
| `vac-nginx` | `nginx:alpine` | Single reverse-proxy face for tunnel ingress, port 7090 host-bound |

All services share an internal `vac-net` bridge network. Only `vac-nginx` is bound to a host port (`127.0.0.1:7090`); everything else is network-internal. The Cloudflare Tunnel sidecar already running on GMKtec adds one ingress rule pointing at `http://127.0.0.1:7090`.

### Subdomain layout

| Path | Audience | Notes |
|------|----------|-------|
| `vacation.mbfdhub.com/` | Anyone | PIN form (single shared PIN); sets HMAC-signed `vac_pin` HTTP-only cookie on success |
| `vacation.mbfdhub.com/board` | After PIN | Read-only vacation board |
| `vacation.mbfdhub.com/import` | After PIN | Upload + preview + commit |
| `vacation.mbfdhub.com/import/runs` | After PIN | Import history + rollback |
| `vacation.mbfdhub.com/api/*` | After PIN | JSON API (server-side only) |

### Blast-radius isolation

- New Docker Compose stack, separate bridge network (`vac-net`)
- New Postgres instance (own container, own volume `vac-pgdata`)
- New Redis instance (own container, own volume `vac-redisdata`)
- New Cloudflare Tunnel **ingress rule** (added to existing `mbfdhub-gmktec` tunnel — does not create a new tunnel)
- New Cloudflare Worker for PIN gate (own worker, own KV namespace for PIN audit)
- R2 bucket `mbfd-hub-laravel` reused (already in user account), but new key prefix `vacation/imports/`
- No shared volumes, no shared DB, no shared network with MBFDHub/Nextcloud/OWUI/admin/ts-orchestrator

---

## 4. Tech Stack (locked)

| Layer | Choice | Rationale |
|-------|--------|-----------|
| Frontend framework | **Next.js 15 App Router** | Mature SSR, React 19, matches bid app |
| UI components | **shadcn/ui** (Radix primitives) | Accessible, themable, no-fork ownership |
| Styling | **Tailwind CSS** + tokens from `.impeccable.md` | Same design language as MBFDHub |
| Client state | **Zustand** (board filters) + **TanStack Query** (server data) | Lightweight, fits a read-only board |
| Board virtualization | **@tanstack/react-virtual** | 365×221×2 cells must scroll at 60fps on mobile |
| API framework | **Hono** on Node 22 | Edge-fast, type-safe, OpenAPI-native |
| File upload | **busboy** + R2 multipart (PutObject) | Streams uploads straight to R2 without loading to memory |
| CSV parsing | **csv-parse** (stream API) | Row-by-row, constant memory |
| XLSX parsing | **xlsx-stream-reader** | Streams worksheet rows from XLSX without loading the whole workbook |
| Queue | **BullMQ** + Redis 7 | Same as ts-orchestrator |
| Database | **Postgres 16** | Bulk COPY ingest, partial indexes, JSONB audit |
| ORM | **Drizzle ORM** | Type-safe SQL, same as bid app |
| Validation | **Zod** + **drizzle-zod** | Single schema for DB ↔ API ↔ client |
| Auth | **CF Worker PIN gate** + HMAC cookie | Same pattern as bid app |
| Deployment | **Docker Compose** on GMKtec | Mirrors ts-orchestrator pattern |
| Tunnel | Existing `mbfdhub-gmktec` Cloudflare Tunnel | New ingress rule only |

---

## 5. Data Model

Postgres 16, UUID PKs, TIMESTAMPTZ, JSONB for raw audit. Drizzle ORM.

### 5.1 Tables (eleven, grouped by concern)

```
ROSTER           CALENDAR           LEAVE              IMPORT AUDIT          PIN
───────          ─────────          ─────              ────────────          ───
members          calendar_days      leave_codes        import_runs           pin_audit
ranks (seed)     shift_blocks       work_code_         import_column_maps
a_day_groups                          mappings         import_run_rows
                                    leave_entries
```

### 5.2 Schema (Drizzle, abbreviated)

```ts
// packages/db/src/schema.ts

export const ranks = pgTable('ranks', {
  id: uuid('id').primaryKey().defaultRandom(),
  code: text('code').notNull().unique(), // 'CAPT', 'LT', 'FF', 'PROB', 'DC'
  label: text('label').notNull(),
  sortOrder: integer('sort_order').notNull(),
  isOfficer: boolean('is_officer').notNull().default(false),
});

export const aDayGroups = pgTable('a_day_groups', {
  id: uuid('id').primaryKey().defaultRandom(),
  code: text('code').notNull().unique(), // 'A1', 'A2', 'A3', 'A4', etc.
  label: text('label').notNull(),
});

export const members = pgTable('members', {
  id: uuid('id').primaryKey().defaultRandom(),
  employeeId: text('employee_id').notNull().unique(),
  badgeNumber: text('badge_number'),
  lastName: text('last_name').notNull(),
  firstName: text('first_name').notNull(),
  hireDate: date('hire_date'),
  rankId: uuid('rank_id').references(() => ranks.id),
  shift: text('shift'), // 'A', 'B', 'C', or null (staff)
  aDayGroupId: uuid('a_day_group_id').references(() => aDayGroups.id),
  isProbationary: boolean('is_probationary').notNull().default(false),
  isActive: boolean('is_active').notNull().default(true),
  sourceImportRunId: uuid('source_import_run_id').references(() => importRuns.id),
  createdAt: timestamp('created_at', { withTimezone: true }).notNull().defaultNow(),
  updatedAt: timestamp('updated_at', { withTimezone: true }).notNull().defaultNow(),
}, (t) => [
  index('members_shift_lastname_idx').on(t.shift, t.lastName),
  index('members_active_idx').on(t.isActive),
]);

export const calendarDays = pgTable('calendar_days', {
  id: uuid('id').primaryKey().defaultRandom(),
  date: date('date').notNull().unique(),
  fiscalYear: integer('fiscal_year').notNull(),       // MBFD FY = Oct–Sep
  calendarYear: integer('calendar_year').notNull(),
  dayOfWeek: integer('day_of_week').notNull(),        // 0=Sun
  payPeriod: integer('pay_period'),                    // 1-26
}, (t) => [
  index('calendar_days_fy_idx').on(t.fiscalYear),
]);

export const shiftBlocks = pgTable('shift_blocks', {
  id: uuid('id').primaryKey().defaultRandom(),
  calendarDayId: uuid('calendar_day_id').notNull().references(() => calendarDays.id),
  blockIndex: integer('block_index').notNull(), // 0 = AM (08:00-20:00), 1 = PM (20:00-08:00 next)
  startAt: timestamp('start_at', { withTimezone: true }).notNull(),
  endAt: timestamp('end_at', { withTimezone: true }).notNull(),
}, (t) => [
  uniqueIndex('shift_blocks_day_block_uk').on(t.calendarDayId, t.blockIndex),
  index('shift_blocks_start_idx').on(t.startAt),
]);

export const leaveCodes = pgTable('leave_codes', {
  id: uuid('id').primaryKey().defaultRandom(),
  code: text('code').notNull().unique(),  // 'V', 'FH', 'EF', 'AH', 'A', 'S', etc.
  label: text('label').notNull(),
  description: text('description'),
  uiColor: text('ui_color').notNull().default('#78716C'), // stone-600 default
  countsAgainstVacationBalance: boolean('counts_against_vacation_balance').notNull().default(false),
  countsAgainstFloatingBalance: boolean('counts_against_floating_balance').notNull().default(false),
  countsAgainstDailyVacationCapacity: boolean('counts_against_daily_vacation_capacity').notNull().default(false),
  countsAgainstTotalOffCapacity: boolean('counts_against_total_off_capacity').notNull().default(true),
  countsAgainstMinimumStaffing: boolean('counts_against_minimum_staffing').notNull().default(false),
  isADayMarker: boolean('is_a_day_marker').notNull().default(false),
});

export const workCodeMappings = pgTable('work_code_mappings', {
  id: uuid('id').primaryKey().defaultRandom(),
  telestaffDescription: text('telestaff_description').notNull().unique(),
  leaveCodeId: uuid('leave_code_id').notNull().references(() => leaveCodes.id),
  createdAt: timestamp('created_at', { withTimezone: true }).notNull().defaultNow(),
});

export const leaveEntries = pgTable('leave_entries', {
  id: uuid('id').primaryKey().defaultRandom(),
  memberId: uuid('member_id').notNull().references(() => members.id),
  shiftBlockId: uuid('shift_block_id').notNull().references(() => shiftBlocks.id),
  leaveCodeId: uuid('leave_code_id').notNull().references(() => leaveCodes.id),
  sourceImportRunId: uuid('source_import_run_id').notNull().references(() => importRuns.id),
  supersededByEntryId: uuid('superseded_by_entry_id').references(() => leaveEntries.id),
  rawTelestaffRow: jsonb('raw_telestaff_row').notNull(),
  createdAt: timestamp('created_at', { withTimezone: true }).notNull().defaultNow(),
}, (t) => [
  uniqueIndex('leave_entries_active_uk')
    .on(t.memberId, t.shiftBlockId)
    .where(sql`superseded_by_entry_id IS NULL`),
  index('leave_entries_block_code_idx').on(t.shiftBlockId, t.leaveCodeId),
  index('leave_entries_member_idx').on(t.memberId),
]);

export const importRuns = pgTable('import_runs', {
  id: uuid('id').primaryKey().defaultRandom(),
  fileName: text('file_name').notNull(),
  fileSize: bigint('file_size', { mode: 'number' }).notNull(),
  fileSha256: text('file_sha256').notNull(),
  r2Key: text('r2_key').notNull(),
  uploadedAt: timestamp('uploaded_at', { withTimezone: true }).notNull().defaultNow(),
  uploadedByPinHash: text('uploaded_by_pin_hash'), // session fingerprint only — no identity
  status: text('status').notNull().default('uploaded'),
  // status: 'uploaded' | 'parsing' | 'preview_ready' | 'committing' | 'committed' | 'failed' | 'rolled_back'
  columnMappingJson: jsonb('column_mapping_json'),
  parseStats: jsonb('parse_stats'), // {totalRows, parsedRows, errorRows, newMembers, newCodes, ...}
  errorMessage: text('error_message'),
  startedAt: timestamp('started_at', { withTimezone: true }),
  finishedAt: timestamp('finished_at', { withTimezone: true }),
});

export const importColumnMaps = pgTable('import_column_maps', {
  id: uuid('id').primaryKey().defaultRandom(),
  name: text('name').notNull().unique(),
  mappingJson: jsonb('mapping_json').notNull(),
  createdAt: timestamp('created_at', { withTimezone: true }).notNull().defaultNow(),
});

export const importRunRows = pgTable('import_run_rows', {
  id: uuid('id').primaryKey().defaultRandom(),
  importRunId: uuid('import_run_id').notNull().references(() => importRuns.id),
  rowIndex: integer('row_index').notNull(),
  rawRowJson: jsonb('raw_row_json').notNull(),
  parsedStatus: text('parsed_status').notNull(),
  // 'ok' | 'skipped' | 'error'
  errorMessage: text('error_message'),
}, (t) => [
  index('import_run_rows_run_idx').on(t.importRunId),
  index('import_run_rows_status_idx').on(t.parsedStatus),
]);

export const pinAudit = pgTable('pin_audit', {
  id: uuid('id').primaryKey().defaultRandom(),
  ip: text('ip'),
  userAgent: text('user_agent'),
  outcome: text('outcome').notNull(), // 'success' | 'failure' | 'rate_limited'
  attemptedAt: timestamp('attempted_at', { withTimezone: true }).notNull().defaultNow(),
}, (t) => [
  index('pin_audit_attempted_idx').on(t.attemptedAt),
]);
```

### 5.3 Seed data (idempotent, runs on every container start)

```sql
INSERT INTO ranks (code, label, sort_order, is_officer) VALUES
  ('DC',   'Division Chief',   1, true),
  ('CAPT', 'Captain',          2, true),
  ('LT',   'Lieutenant',       3, true),
  ('FF',   'Firefighter',      4, false),
  ('PROB', 'Probationary',     5, false)
ON CONFLICT (code) DO NOTHING;

INSERT INTO leave_codes (code, label, ui_color,
   counts_against_vacation_balance, counts_against_floating_balance,
   counts_against_daily_vacation_capacity, counts_against_minimum_staffing,
   is_a_day_marker)
VALUES
  ('V',  'Vacation',                '#B91C1C', true,  false, true,  true,  false),
  ('VP', 'Vacation Prescheduled',   '#B91C1C', true,  false, true,  true,  false),
  ('EV', 'Emergency Vacation',      '#B91C1C', true,  false, true,  true,  false),
  ('FH', 'Floating Holiday',        '#D97706', false, true,  true,  true,  false),
  ('F',  'Birthday / FML Float',    '#D97706', false, true,  true,  true,  false),
  ('AH', 'Alternate Holiday',       '#D97706', false, true,  true,  true,  false),
  ('EF', 'Emergency Floater',       '#A16207', false, true,  false, true,  false),
  ('A',  'A-Day / R-Day',           '#0369A1', false, false, false, false, true),
  ('S',  'Sick',                    '#374151', false, false, false, false, false),
  ('SIC','Sick (Telestaff)',        '#374151', false, false, false, false, false),
  ('HO', 'Holiday Off',             '#16A34A', false, false, false, false, false),
  ('XOFF','Exchange Off',           '#7C3AED', false, false, false, false, false),
  ('EON','Exchange On',             '#16A34A', false, false, false, false, false),
  ('OOC','Out of Class',            '#A8A29E', false, false, false, false, false),
  ('MOC','Medic OOC',               '#A8A29E', false, false, false, false, false),
  ('ROC','Rescue OOC',              '#A8A29E', false, false, false, false, false),
  ('TOC','Training OOC',            '#A8A29E', false, false, false, false, false)
ON CONFLICT (code) DO NOTHING;
```

### 5.4 Derived data (not stored)

- **Vacation hours used per member** = `SUM(12)` over `leave_entries` joined to `leave_codes` where `counts_against_vacation_balance = true`, member-scoped.
- **Vacation hours remaining** = `vacation_allowance(years_of_service) - hours_used`. `vacation_allowance` is a SQL function (see `infra/postgres/init.sql`).
- **Daily on-duty count** = `(SELECT COUNT(*) FROM members WHERE shift = $1) - (off entries for that shift block)`.

These are computed via materialized views refreshed at the end of each import.

---

## 6. Import Pipeline (the most important V1 surface)

### 6.1 Flow

```
[Admin browser]                                    [GMKtec containers]
      │                                                    │
      │ POST /api/imports        multipart, streamed       │
      ├───────────────────────────────────────────────────►│
      │                                          [vac-api: busboy]
      │                                          stream to R2 PutObject
      │                                          while computing SHA-256
      │                                          ┌─────────────────────┐
      │                                          │ writes import_runs  │
      │ ◄────────── { runId } ───────────────────│ status='uploaded'   │
      │                                          └─────────────────────┘
      │                                                    │
      │ GET /api/imports/{id}/preview (SSE)                │
      ├───────────────────────────────────────────────────►│
      │                                          [vac-worker (BullMQ)]
      │                                          - download from R2
      │                                          - detect file type
      │                                          - stream rows
      │                                          - infer column types
      │                                          - sample first 100
      │                                          - check FK candidates
      │ ◄──── { columns, sample, suggested map } ─────────┤
      │      status='preview_ready'                       │
      │                                                    │
      │ POST /api/imports/{id}/commit                     │
      │   { columnMapping, workCodeDecisions }            │
      ├───────────────────────────────────────────────────►│
      │                                          [vac-worker]
      │                                          - validate mapping
      │                                          - parse all rows
      │                                          - upsert members
      │                                          - upsert shift_blocks (lazy)
      │                                          - mark old leave_entries
      │                                            for affected blocks
      │                                            as superseded
      │                                          - bulk insert new entries
      │                                            via COPY FROM STDIN
      │                                          - refresh matviews
      │ ◄────────── { committed, stats } ──────────────────┤
      │                                          status='committed'
```

### 6.2 Streaming guarantees

| Concern | Mitigation |
|---------|------------|
| HTTP timeout on huge upload | Upload streams to R2 directly via busboy + R2 multipart PutObject; API returns `runId` immediately after upload, parsing happens in background |
| RAM blow-up on a 500 MB XLSX | `xlsx-stream-reader` emits one row at a time; we never hold the whole workbook in memory |
| Postgres lock contention on bulk insert | Use `COPY FROM STDIN BINARY` for `leave_entries` insert; wraps in a single transaction per import |
| Re-import overwriting good data | Old entries soft-supersede (`superseded_by_entry_id` set); rollback restores them by reversing the supersede flag |
| Mid-import crash | BullMQ retries with exponential backoff; partially-inserted entries are tagged with the failed `import_run_id` and removed on retry |
| Adversarial file | File size hard-capped at 1 GB; file MIME sniffed; row count hard-capped at 5,000,000; failed sniffs → 400 reject |

### 6.3 Column-mapping inference (the "no coding skills" admin path)

After the worker parses the first 100 rows it produces a `suggested mapping` by matching column header patterns (case-insensitive substring + Levenshtein):

| Target field | Header patterns we look for |
|--------------|-----------------------------|
| `employee_id` | `emp`, `id`, `payroll`, `pernr`, `pers` |
| `last_name` | `last`, `surname` |
| `first_name` | `first`, `given` |
| `rank` / `position` | `rank`, `position`, `title`, `class` |
| `shift` | `shift`, `platoon`, `crew` |
| `a_day_group` | `aday`, `r-day`, `cycle`, `group` |
| `hire_date` | `hire`, `seniority`, `start` |
| `event_datetime` | `date`, `start`, `from`, `time` |
| `event_end_datetime` | `end`, `to`, `thru` |
| `event_description` | `code`, `description`, `work code`, `paycode`, `event` |

The admin sees the suggestion in a side-by-side preview UI (left: detected columns, right: target fields). Anything ambiguous shows a dropdown. Anything unmapped is left as "ignore". The admin can **save the mapping as a named template** so the next import auto-picks it.

### 6.4 Unknown work-code review

Every distinct `event_description` in the file that does **not** already match a `work_code_mappings` row is presented as an inline question:

```
"FloatingHoliday" — what is this?
[ V Vacation ] [ FH Floating Holiday ] [ AH Alt Holiday ] [ Skip this row ] [ Create new code ]
```

The admin picks an answer per unknown description before commit. Each choice writes a row to `work_code_mappings` so it's auto-resolved on the next import.

### 6.5 Idempotency

Files are content-addressed by SHA-256. Re-uploading the same file → API returns the existing `runId` with a `wasDuplicate: true` flag. Admin can re-preview or re-commit without re-uploading the bytes.

### 6.6 Rollback

A committed import can be rolled back from `/import/runs/{id}`:

1. Find all `leave_entries` with `source_import_run_id = $1`
2. For each, find the entry it superseded (if any) and clear that entry's `superseded_by_entry_id`
3. Soft-delete the entries from this run (mark them as superseded by a synthetic "rollback" row)
4. Refresh matviews
5. Mark the import run `status='rolled_back'`

No row is ever physically deleted — the audit trail is permanent.

---

## 7. Board UI (read-only, mobile-first)

### 7.1 Layout

```
┌────────────────────────────────────────────────────────────────────────┐
│  vacation.mbfdhub.com                       Shift: [A] [B] [C]  Rank ▾│  ← top bar (sticky)
├────────────────────────────────────────────────────────────────────────┤
│                       ◄── Mar 27 – Apr 9, 2026 ──►                     │  ← week scroller
│ ┌────────────┬─────┬─────┬─────┬─────┬─────┬─────┬─────┬─────┬─────┐ │
│ │            │ M27 │ M28 │ M29 │ M30 │ M31 │ A 1 │ A 2 │ A 3 │ A 4 │ │  ← date header
│ │            │  TH │  F  │ SAT │ SUN │  M  │  T  │  W  │  TH │  F  │ │
│ ├────────────┼─────┼─────┼─────┼─────┼─────┼─────┼─────┼─────┼─────┤ │
│ │ Smith   FF │ V V │ V V │ . . │ . . │ A A │ . . │ . . │ . . │ . . │ │  ← member row
│ │ Jones   LT │ . . │ . . │ A A │ . . │ . . │ V V │ V V │ . . │ . . │ │
│ │ Lopez   FF │ A A │ . . │ . . │ . . │ . . │ . . │ . . │ FH FH│ . . │ │
│ └────────────┴─────┴─────┴─────┴─────┴─────┴─────┴─────┴─────┴─────┘ │
└────────────────────────────────────────────────────────────────────────┘
                                                  ▲
                          each cell = 2 chars: AM block | PM block
                          colored per leave_codes.ui_color
                          hover/tap → popover with member, datetime, raw row
```

### 7.2 Virtualization

`@tanstack/react-virtual` with horizontal + vertical virtualization. Only the visible viewport renders. 365 days × 221 members = 80,665 cells total, but only ~600–1,200 are ever in the DOM at once. Tested target: 60 fps scroll on iPhone 13 Safari.

### 7.3 Filters (V1)

- Shift (multi-select: A / B / C / Staff)
- Rank (multi-select: from the `ranks` table)
- Date range (defaults to the current 4-week window from `now()`)
- Show only members with leave (toggle)

Filters live in Zustand state, mirrored to URL query params, so a filtered view is shareable.

### 7.4 Cell popover

Tapping any cell opens a Radix Popover with:

- Member: name, employee ID, rank, shift, A-day group
- Block: date + 08:00–20:00 or 20:00–08:00
- Leave code: badge + label
- Source: `import_run_id` (link to the import that placed it)
- Raw Telestaff row (JSON, collapsed by default)

### 7.5 Mobile adaptations

- Below `sm:` (640px) the member-name column collapses to last name + 2-letter rank
- Below `sm:` block cells become 18px wide (vs 28px on desktop)
- Date header sticks under the top bar
- Member column sticks to the left edge
- `prefers-reduced-motion: reduce` disables the staggered cell-reveal animation
- 44px minimum touch targets on all interactive elements (per `.impeccable.md`)
- Safe-area-inset padding on iOS standalone

### 7.6 Empty state

When the DB has zero `leave_entries`:

```
┌────────────────────────────────────────────────────────────┐
│                                                            │
│                    ╳   No data imported yet                │
│                                                            │
│            Upload your first Telestaff export to           │
│            populate the vacation board.                    │
│                                                            │
│                  [ Go to Import →  ]                       │
│                                                            │
└────────────────────────────────────────────────────────────┘
```

---

## 8. PIN Gate (Cloudflare Worker)

### 8.1 Worker behavior

```ts
// apps/pin-gate/src/index.ts (sketch)
export default {
  async fetch(req: Request, env: Env): Promise<Response> {
    const url = new URL(req.url);
    if (url.pathname === '/__pin/submit') return handlePinSubmit(req, env);
    if (url.pathname === '/__pin/logout') return handlePinLogout(req, env);

    const cookie = req.headers.get('cookie') ?? '';
    const cookiePair = parseCookie(cookie, 'vac_pin');

    if (await isCookieValid(cookiePair, env)) {
      // proxy to tunnel hostname
      return proxyToOrigin(req, env);
    }

    return renderPinForm(req);
  },
};
```

### 8.2 Cookie

- Name: `vac_pin`
- Format: `${nonce}.${expiryEpochSec}.${HMAC_SHA256(nonce + "|" + expiryEpochSec, PIN_SIGNING_SECRET)}`
- `HttpOnly`, `Secure`, `SameSite=Lax`, `Path=/`, 14-day expiry
- The PIN itself is never in the cookie

### 8.3 Rate limiting

Cloudflare Workers KV stores `pin:attempt:{ip}` with a 15-minute TTL. After 5 failed attempts from one IP, the worker returns 429 for 15 minutes. Every attempt is logged to `pin_audit` (the worker POSTs to a webhook on the API).

### 8.4 PIN secret + rotation

- `PIN_VALUE` and `PIN_SIGNING_SECRET` are Worker secrets (set via `wrangler secret put`)
- Rotation: deploy worker with new `PIN_VALUE`; invalidates existing sessions on `PIN_SIGNING_SECRET` change

---

## 9. Deployment

### 9.1 Directory layout

```
vacation-app/                              ← new top-level dir at repo root
├── apps/
│   ├── web/                              ← Next.js 15 App Router
│   ├── api/                              ← Hono + busboy + R2 client
│   ├── worker/                           ← BullMQ consumer
│   └── pin-gate/                         ← Cloudflare Worker
├── packages/
│   ├── db/                               ← Drizzle schema, migrations, seed
│   └── shared/                           ← Zod types shared across apps
├── infra/
│   ├── docker-compose.yml                ← dev (postgres, redis, web, api, worker)
│   ├── docker-compose.prod.yml           ← prod overlay (nginx, healthchecks)
│   ├── nginx/
│   │   └── default.conf                  ← reverse proxy at :7090
│   ├── postgres/
│   │   ├── init.sql                      ← seed leave_codes, ranks; vacation_allowance fn
│   │   └── extensions.sql                ← citext, pg_trgm
│   └── cloudflared/
│       └── ingress-snippet.yml           ← drop-in for mbfdhub-gmktec tunnel config
├── scripts/
│   ├── deploy.sh                         ← idempotent re-deploy from git pull
│   ├── seed-dev.ts                       ← generate small synthetic Telestaff fixture
│   └── stress-fixture.ts                 ← generate 500MB synthetic CSV
├── tests/
│   ├── unit/                             ← parsers, mapping, supersede
│   ├── integration/                      ← API contract tests against test Postgres
│   └── e2e/                              ← Playwright: full import + board render
├── docs/
│   ├── DEPLOYMENT.md
│   ├── ADMIN-GUIDE.md
│   └── ARCHITECTURE.md
├── pnpm-workspace.yaml
├── package.json
├── tsconfig.base.json
├── .env.example
├── .gitignore
└── README.md
```

### 9.2 First deploy on GMKtec

1. SSH to `gmktec` (via `C:\Program Files\OpenSSH\ssh.exe`)
2. `mkdir -p /opt/mbfd-vacation && cd /opt/mbfd-vacation`
3. `git clone https://github.com/pdarleyjr/mbfd-vacation.git .` (or pull subtree)
4. `cp .env.example .env` and fill in the R2 credentials, Postgres password, and webhook secret. All values are kept out of git; see `.env.example` for the full list and `docs/DEPLOYMENT.md` for where to pull each value from.
5. `docker compose -f infra/docker-compose.yml -f infra/docker-compose.prod.yml up -d --build`
6. Append the Cloudflare Tunnel ingress rule from `infra/cloudflared/ingress-snippet.yml` to the existing `mbfdhub-gmktec` tunnel config
7. Restart `cloudflared` service
8. Add DNS CNAME `vacation.mbfdhub.com` → `<tunnel-id>.cfargotunnel.com` (via Cloudflare API)
9. Deploy the PIN-gate Worker:
   - `cd apps/pin-gate && npx wrangler deploy`
   - `npx wrangler secret put PIN_VALUE` (enter PIN)
   - `npx wrangler secret put PIN_SIGNING_SECRET` (random)
   - `npx wrangler secret put PIN_AUDIT_WEBHOOK_SECRET`
10. Bind the Worker to the route `vacation.mbfdhub.com/*` in Cloudflare dashboard or via `wrangler routes`

### 9.3 Update path

```bash
ssh gmktec
cd /opt/mbfd-vacation
git pull
docker compose -f infra/docker-compose.yml -f infra/docker-compose.prod.yml up -d --build
```

### 9.4 Backup

- Postgres: `pg_dump` weekly to R2 (key prefix `vacation/backups/pg/`)
- R2: bucket lifecycle keeps imports forever; no expiry rule
- A nightly cron in `vac-worker` runs the backup

---

## 10. Testing

### 10.1 Unit (Vitest)

- CSV parser: edge cases (BOM, CRLF, quoted commas, empty lines)
- XLSX parser: edge cases (date serial numbers, formula cells, merged cells)
- Column-mapping inference: each pattern table entry has a positive + negative test
- Soft-supersede: re-import marks old entries correctly; rollback restores them
- Shift-block bisection: a 24-hour vacation maps to exactly 2 entries
- `vacation_allowance` SQL function: <10 yrs → 144, 10–20 → 204, 20+ → 264

### 10.2 Integration (Vitest + Testcontainers Postgres)

- Full import flow against a real Postgres in a container
- Re-import idempotency (same hash → same runId)
- Concurrent imports don't collide (lock test)
- 5,000-row fixture import end-to-end < 30s

### 10.3 E2E (Playwright)

- PIN gate rejects wrong PIN, allows correct PIN
- Empty-state board redirects to /import with CTA
- Upload → preview → commit → board renders correctly
- Rollback restores the prior state
- Board scrolls 365 days × 221 members at 60 fps (Playwright trace)
- Mobile viewport (iPhone 13) — sticky headers, popovers work

### 10.4 Coverage target

- Unit + integration: ≥ 80% line coverage on `packages/db`, `apps/api`, `apps/worker`
- E2E: every page in `apps/web`, every state in the import workflow

### 10.5 Stress fixture

`scripts/stress-fixture.ts` generates a synthetic Telestaff CSV with N members × 365 days × 2 blocks. Configurable N up to 5,000. Default N=300 for CI, N=1000 for staging. Saved to R2 under `vacation/fixtures/`.

---

## 11. Security

- **PIN gate**: HMAC-signed cookie, KV-based rate limit, 5-fail lockout, audit log
- **R2 access**: server-only, never exposed to browser
- **Postgres**: not exposed beyond `vac-net` bridge
- **CSP**: strict on all pages, no inline scripts
- **HSTS**: enforced by Cloudflare
- **Upload validation**: MIME sniff, size cap (1 GB), row cap (5M), structural validation before commit
- **SQL injection**: Drizzle parameterized everywhere; no string concatenation
- **PII in logs**: Telestaff row content is stored in JSONB but never logged to stdout
- **Secrets**: `.env` not in git; Wrangler secrets for the Worker; Postgres password rotated on first deploy

---

## 12. Out of scope (explicit)

For V1. Each is a clearly-bounded Phase 2 effort.

- Vacation request submission (member self-service)
- Approval workflow (admin approve/deny/override/waitlist)
- Staffing capacity engine (the rules from the workbook analysis)
- Computed A-day cycle generation (V1 only renders A-days that appear in the import)
- Member-facing balance display
- Notifications (email/push/SMS)
- Filament/MBFDHub auth integration
- Public read-only view
- Per-member PINs / Employee Portal API integration
- Reporting/exports beyond what an admin can already see on `/board`
- Manual cell editing
- Member roster editing
- Leave-code policy editor UI

---

## 13. Phase 2 preview (not in V1)

The schema and architecture are already shaped so Phase 2 only adds tables and code paths — never restructures. Phase 2 will add:

- `vacation_requests`, `approval_decisions`, `waitlist_entries`, `staffing_rules`, `qualifications`, `member_qualifications`
- A request-checker service that consumes `staffing_rules` + current `leave_entries` and returns `APPROVE | DENY | OVERRIDE | WAITLIST` with a human-readable reason
- A member self-service surface gated by Employee Portal API auth (or per-member PIN)
- A staffing-rule editor for the admin
- The "approve / deny / override" admin UI overlay on the existing board

V1 is built such that none of this requires schema migration of existing tables — only new tables and new endpoints.
