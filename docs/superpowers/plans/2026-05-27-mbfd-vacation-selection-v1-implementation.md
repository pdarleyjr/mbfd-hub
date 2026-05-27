# MBFD Vacation Selection V1 — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship a V1 vacation-selection web app at `vacation.mbfdhub.com` that ingests Telestaff XLSX/CSV exports (up to 500 MB) and renders a read-only vacation board on desktop and mobile, gated by a single shared department PIN, hosted on the GMKtec EVO-X2 server.

**Architecture:** Pnpm monorepo with four apps (`web`, `api`, `worker`, `pin-gate`) and two shared packages (`db`, `shared`). Next.js 15 + Hono + Drizzle + Postgres 16 + BullMQ + R2. Docker Compose on GMKtec, Cloudflare Tunnel ingress, Cloudflare Worker PIN gate.

**Tech Stack:** TypeScript 5.7, Node 22, Next.js 15, React 19, shadcn/ui, Tailwind CSS, Hono, Drizzle ORM, Postgres 16, BullMQ, Redis 7, busboy, csv-parse, xlsx-stream-reader, @aws-sdk/client-s3 (for R2), Zod, Vitest, Playwright, Cloudflare Workers.

**Spec:** [docs/superpowers/specs/2026-05-27-mbfd-vacation-selection-design.md](../specs/2026-05-27-mbfd-vacation-selection-design.md)

**Repo target:** `vacation-app/` at MBFD_Hub repo root; also pushed to standalone `github.com/pdarleyjr/mbfd-vacation` for clean deploy clones.

---

## File map

```
vacation-app/
├── pnpm-workspace.yaml           T01
├── package.json                  T01
├── tsconfig.base.json            T01
├── .gitignore                    T01
├── .env.example                  T01
├── README.md                     T01
├── apps/
│   ├── web/                      T17–T21 (Next.js 15)
│   ├── api/                      T04–T10  (Hono)
│   ├── worker/                   T11–T16 (BullMQ)
│   └── pin-gate/                 T22     (CF Worker)
├── packages/
│   ├── db/                       T02     (Drizzle)
│   └── shared/                   T03     (Zod)
├── infra/
│   ├── docker-compose.yml        T23
│   ├── docker-compose.prod.yml   T23
│   ├── nginx/default.conf        T23
│   ├── postgres/init.sql         T24
│   └── cloudflared/ingress-snippet.yml  T25
├── scripts/
│   ├── stress-fixture.ts         T26
│   ├── seed-dev.ts               T26
│   └── deploy.sh                 T26
├── tests/
│   ├── unit/                     T27 (Vitest)
│   ├── integration/              T28 (Vitest + Testcontainers)
│   └── e2e/                      T29 (Playwright)
└── docs/
    ├── DEPLOYMENT.md             T30
    ├── ADMIN-GUIDE.md            T30
    └── ARCHITECTURE.md           T30
```

---

## Tasks

### T01: Scaffold monorepo

**Files:**
- Create: `vacation-app/package.json`
- Create: `vacation-app/pnpm-workspace.yaml`
- Create: `vacation-app/tsconfig.base.json`
- Create: `vacation-app/.gitignore`
- Create: `vacation-app/.env.example`
- Create: `vacation-app/README.md`
- Create: `vacation-app/.npmrc`
- Create: `vacation-app/.editorconfig`

- [ ] Create `vacation-app/` directory at repo root.
- [ ] Write `pnpm-workspace.yaml` listing `apps/*` and `packages/*`.
- [ ] Write root `package.json` with workspace scripts (`build`, `dev`, `test`, `lint`, `typecheck`) that fan out to workspaces.
- [ ] Write `tsconfig.base.json` with `strict`, `noUncheckedIndexedAccess`, `moduleResolution: "bundler"`.
- [ ] Write `.gitignore` (node_modules, .next, dist, .env, coverage, *.log).
- [ ] Write `.env.example` with all required env vars, no real secrets.
- [ ] Write `README.md` (one page: what it is, how to dev, where to deploy, links to spec/plan/docs).
- [ ] Commit: `feat(vacation): monorepo scaffold`.

### T02: packages/db (Drizzle schema + migrations + seed)

**Files:**
- Create: `vacation-app/packages/db/package.json`
- Create: `vacation-app/packages/db/tsconfig.json`
- Create: `vacation-app/packages/db/drizzle.config.ts`
- Create: `vacation-app/packages/db/src/index.ts`
- Create: `vacation-app/packages/db/src/schema/ranks.ts`
- Create: `vacation-app/packages/db/src/schema/a-day-groups.ts`
- Create: `vacation-app/packages/db/src/schema/members.ts`
- Create: `vacation-app/packages/db/src/schema/calendar-days.ts`
- Create: `vacation-app/packages/db/src/schema/shift-blocks.ts`
- Create: `vacation-app/packages/db/src/schema/leave-codes.ts`
- Create: `vacation-app/packages/db/src/schema/work-code-mappings.ts`
- Create: `vacation-app/packages/db/src/schema/leave-entries.ts`
- Create: `vacation-app/packages/db/src/schema/import-runs.ts`
- Create: `vacation-app/packages/db/src/schema/import-column-maps.ts`
- Create: `vacation-app/packages/db/src/schema/import-run-rows.ts`
- Create: `vacation-app/packages/db/src/schema/pin-audit.ts`
- Create: `vacation-app/packages/db/src/schema/index.ts`
- Create: `vacation-app/packages/db/src/client.ts`
- Create: `vacation-app/packages/db/src/seed.ts`

- [ ] Install: `drizzle-orm`, `drizzle-kit`, `postgres`, `pg`, `@types/pg`.
- [ ] Define every table from the spec §5.2 in its own file (one table per file = small focused files).
- [ ] Wire `schema/index.ts` to re-export everything.
- [ ] Write `client.ts` exporting a `getDb(connectionString)` factory that returns a Drizzle client.
- [ ] Write `seed.ts` that idempotently inserts the seed `ranks` and `leave_codes` from spec §5.3.
- [ ] Configure `drizzle.config.ts` for migration generation against `DATABASE_URL`.
- [ ] Generate initial migration: `pnpm db:generate`.
- [ ] Commit: `feat(vacation/db): drizzle schema + seed`.

### T03: packages/shared (Zod types)

**Files:**
- Create: `vacation-app/packages/shared/package.json`
- Create: `vacation-app/packages/shared/tsconfig.json`
- Create: `vacation-app/packages/shared/src/column-mapping.ts`
- Create: `vacation-app/packages/shared/src/import-state.ts`
- Create: `vacation-app/packages/shared/src/board-cell.ts`
- Create: `vacation-app/packages/shared/src/work-code-decision.ts`
- Create: `vacation-app/packages/shared/src/index.ts`

- [ ] Install: `zod`.
- [ ] Define `ColumnMappingSchema` (target → source-header pairs).
- [ ] Define `ImportStateSchema` (matches `import_runs.status` enum + payload shapes per state).
- [ ] Define `BoardCellSchema` (member, block, code, raw row).
- [ ] Define `WorkCodeDecisionSchema` (description → existing code id | new code spec | skip).
- [ ] Export inferred TS types from each schema.
- [ ] Commit: `feat(vacation/shared): zod contracts`.

### T04: apps/api skeleton (Hono + R2 client + health)

**Files:**
- Create: `vacation-app/apps/api/package.json`
- Create: `vacation-app/apps/api/tsconfig.json`
- Create: `vacation-app/apps/api/Dockerfile`
- Create: `vacation-app/apps/api/src/main.ts`
- Create: `vacation-app/apps/api/src/env.ts`
- Create: `vacation-app/apps/api/src/r2.ts`
- Create: `vacation-app/apps/api/src/queue.ts`
- Create: `vacation-app/apps/api/src/routes/health.ts`

- [ ] Install: `hono`, `@hono/node-server`, `@aws-sdk/client-s3`, `bullmq`, `ioredis`, `pino`.
- [ ] `env.ts`: parse env via Zod (DATABASE_URL, REDIS_URL, R2_* vars, WEBHOOK_SECRET).
- [ ] `r2.ts`: S3 client configured for the R2 jurisdiction endpoint; helpers `putStream(key, stream)` and `getStream(key)`.
- [ ] `queue.ts`: BullMQ `Queue` instance for `imports` queue.
- [ ] `routes/health.ts`: GET `/health` returns `{ok: true, db, redis, r2}` checks.
- [ ] `main.ts`: assembles Hono app, mounts health, listens on `PORT` (default 3001).
- [ ] Dockerfile: multi-stage build, Node 22 alpine, non-root user, healthcheck.
- [ ] Commit: `feat(vacation/api): hono skeleton + health`.

### T05: POST /api/imports (streaming upload to R2)

**Files:**
- Create: `vacation-app/apps/api/src/routes/imports-upload.ts`
- Modify: `vacation-app/apps/api/src/main.ts` (mount the route)
- Create: `vacation-app/tests/unit/api/imports-upload.test.ts`

- [ ] Install: `busboy`, `@types/busboy`, `vitest`.
- [ ] Write failing test: POST a multipart body, assert the route returns `{runId, wasDuplicate: false}` and the file lands in R2 (mock client).
- [ ] Implement: busboy streams the file part directly through a SHA-256 transform into R2 PutObject (multipart). On finish, insert `import_runs` row with `status='uploaded'` and enqueue a `parse-preview` BullMQ job.
- [ ] Idempotency: if a row exists with the same `file_sha256`, return that `runId` and `wasDuplicate: true` (don't re-upload).
- [ ] Test passes.
- [ ] Commit: `feat(vacation/api): streaming upload to R2`.

### T06: GET /api/imports/:id/preview (SSE)

**Files:**
- Create: `vacation-app/apps/api/src/routes/imports-preview.ts`
- Modify: `vacation-app/apps/api/src/main.ts`

- [ ] Implement an SSE endpoint that streams progress from a Redis pub/sub channel `import:{id}:progress` while the worker parses.
- [ ] On worker completion, the final SSE event carries `{columns, sampleRows, suggestedMapping, unknownDescriptions}` and the connection closes.
- [ ] Reconnection handled by EventSource; server replays last-known state from `import_runs.parse_stats`.
- [ ] Commit: `feat(vacation/api): SSE preview stream`.

### T07: POST /api/imports/:id/commit

**Files:**
- Create: `vacation-app/apps/api/src/routes/imports-commit.ts`
- Modify: `vacation-app/apps/api/src/main.ts`

- [ ] Body validated against `ColumnMappingSchema` + `WorkCodeDecisionSchema[]`.
- [ ] Persist mapping + decisions on the `import_runs` row.
- [ ] Enqueue `commit-import` BullMQ job; respond with `{queued: true}`.
- [ ] Commit: `feat(vacation/api): commit endpoint`.

### T08: POST /api/imports/:id/rollback

**Files:**
- Create: `vacation-app/apps/api/src/routes/imports-rollback.ts`
- Modify: `vacation-app/apps/api/src/main.ts`

- [ ] Reverses supersede flags for entries sourced from this run; marks run as `rolled_back`.
- [ ] Single transaction; refreshes any materialized views.
- [ ] Returns `{rolledBack: true, restoredCount}`.
- [ ] Commit: `feat(vacation/api): rollback endpoint`.

### T09: GET /api/board + GET /api/imports/runs

**Files:**
- Create: `vacation-app/apps/api/src/routes/board.ts`
- Create: `vacation-app/apps/api/src/routes/imports-list.ts`
- Modify: `vacation-app/apps/api/src/main.ts`

- [ ] `/api/board` accepts query params `shift`, `rank`, `from`, `to`, `onlyWithLeave`. Returns members + their leave entries for the date range. Server-side paginated by member.
- [ ] `/api/imports/runs` returns the recent import runs with status + stats.
- [ ] Commit: `feat(vacation/api): board + import-runs queries`.

### T10: POST /api/__pin/audit-webhook

**Files:**
- Create: `vacation-app/apps/api/src/routes/pin-audit-webhook.ts`
- Modify: `vacation-app/apps/api/src/main.ts`

- [ ] Validates `Authorization: Bearer ${WEBHOOK_SECRET}` from the CF Worker.
- [ ] Inserts into `pin_audit`.
- [ ] Returns 204.
- [ ] Commit: `feat(vacation/api): pin audit webhook`.

### T11: apps/worker skeleton

**Files:**
- Create: `vacation-app/apps/worker/package.json`
- Create: `vacation-app/apps/worker/tsconfig.json`
- Create: `vacation-app/apps/worker/Dockerfile`
- Create: `vacation-app/apps/worker/src/main.ts`
- Create: `vacation-app/apps/worker/src/env.ts`
- Create: `vacation-app/apps/worker/src/jobs/parse-preview.ts`
- Create: `vacation-app/apps/worker/src/jobs/commit-import.ts`

- [ ] Install: `bullmq`, `ioredis`, `csv-parse`, `xlsx-stream-reader`, `@aws-sdk/client-s3`, `pg`, `drizzle-orm`.
- [ ] `main.ts`: starts a BullMQ Worker on `imports` queue with concurrency 2.
- [ ] Stubs for both job handlers (T12-T16 fill them in).
- [ ] Dockerfile mirrors `apps/api` pattern.
- [ ] Commit: `feat(vacation/worker): bullmq skeleton`.

### T12: CSV stream parser

**Files:**
- Create: `vacation-app/apps/worker/src/parse/csv.ts`
- Create: `vacation-app/tests/unit/worker/parse-csv.test.ts`

- [ ] Test: parses a multi-line CSV with quoted commas, BOM, CRLF; emits one record per row; emits header separately.
- [ ] Implement: `parseCsv(stream): AsyncIterable<{ header: string[], rows: AsyncIterable<Record<string, string>> }>` using `csv-parse` stream API.
- [ ] Commit: `feat(vacation/worker): csv stream parser`.

### T13: XLSX stream parser

**Files:**
- Create: `vacation-app/apps/worker/src/parse/xlsx.ts`
- Create: `vacation-app/tests/unit/worker/parse-xlsx.test.ts`

- [ ] Test: parses an XLSX with a date column (Excel serial), a formula cell, an empty row; emits clean records.
- [ ] Implement: `parseXlsx(stream)` using `xlsx-stream-reader`. Convert Excel date serials → ISO via `dayjs`.
- [ ] Commit: `feat(vacation/worker): xlsx stream parser`.

### T14: Column-mapping inference

**Files:**
- Create: `vacation-app/apps/worker/src/parse/infer-mapping.ts`
- Create: `vacation-app/tests/unit/worker/infer-mapping.test.ts`

- [ ] Test: given header `["Emp ID","Last","First","Start","End","Work Code"]` infers correct mapping; given ambiguous headers leaves them unmapped.
- [ ] Implement: substring + Levenshtein patterns per spec §6.3.
- [ ] Commit: `feat(vacation/worker): column mapping inference`.

### T15: commit-import job

**Files:**
- Create: `vacation-app/apps/worker/src/jobs/commit-import.ts` (fill in)
- Create: `vacation-app/apps/worker/src/commit/upsert-members.ts`
- Create: `vacation-app/apps/worker/src/commit/ensure-blocks.ts`
- Create: `vacation-app/apps/worker/src/commit/upsert-leave-entries.ts`
- Create: `vacation-app/apps/worker/src/commit/refresh-views.ts`

- [ ] Download file from R2 to a temp stream.
- [ ] Parse using CSV or XLSX detector.
- [ ] For each row:
  - Resolve member by `employee_id`; if not exists, insert with the rank/shift/A-day group inferred from the row (`upsert-members.ts`).
  - Resolve shift block by `(date, block_index)`; lazy-create if missing (`ensure-blocks.ts`).
  - Resolve leave code by `work_code_mappings`; if `event_description` is in `WorkCodeDecisionSchema[]`, apply that decision (creating a new code if needed).
  - Mark any active `leave_entries` row for the same `(member_id, shift_block_id)` as superseded.
  - Stage the new `leave_entries` row for COPY.
- [ ] After all rows: `COPY leave_entries FROM STDIN BINARY` in a single transaction.
- [ ] Refresh materialized views.
- [ ] Update `import_runs.status='committed'` + `parse_stats`.
- [ ] Publish completion to Redis pub/sub for SSE listeners.
- [ ] Commit: `feat(vacation/worker): commit-import job`.

### T16: rollback logic (worker-side helper)

**Files:**
- Create: `vacation-app/apps/worker/src/commit/rollback.ts`

- [ ] Implements the rollback SQL in spec §6.6 inside a transaction.
- [ ] Called from `apps/api/src/routes/imports-rollback.ts` directly (not via queue — rollback is cheap and synchronous).
- [ ] Move the function to a shared place (`packages/db/src/operations/rollback.ts`) so both api and worker can import it.
- [ ] Commit: `refactor(vacation/db): extract rollback to packages/db`.

### T17: apps/web skeleton + design tokens + shadcn setup

**Files:**
- Create: `vacation-app/apps/web/package.json`
- Create: `vacation-app/apps/web/tsconfig.json`
- Create: `vacation-app/apps/web/next.config.mjs`
- Create: `vacation-app/apps/web/Dockerfile`
- Create: `vacation-app/apps/web/postcss.config.mjs`
- Create: `vacation-app/apps/web/tailwind.config.ts`
- Create: `vacation-app/apps/web/src/app/layout.tsx`
- Create: `vacation-app/apps/web/src/app/page.tsx`
- Create: `vacation-app/apps/web/src/app/globals.css`
- Create: `vacation-app/apps/web/src/lib/utils.ts`
- Create: `vacation-app/apps/web/components.json` (shadcn config)
- Create: `vacation-app/apps/web/src/components/ui/button.tsx`
- Create: `vacation-app/apps/web/src/components/ui/card.tsx`
- Create: `vacation-app/apps/web/src/components/ui/badge.tsx`
- Create: `vacation-app/apps/web/src/components/ui/dialog.tsx`
- Create: `vacation-app/apps/web/src/components/ui/dropdown-menu.tsx`
- Create: `vacation-app/apps/web/src/components/ui/select.tsx`
- Create: `vacation-app/apps/web/src/components/ui/sonner.tsx`

- [ ] Install: `next@15`, `react@19`, `tailwindcss`, `@tanstack/react-query`, `@tanstack/react-virtual`, `zustand`, `lucide-react`, `clsx`, `tailwind-merge`, `class-variance-authority`, Radix UI primitives, `sonner`.
- [ ] Tailwind config maps `.impeccable.md` tokens (Red-700 brand, Slate-850 admin, Stone-* neutrals, Plus Jakarta Sans + Source Sans 3, tabular-nums).
- [ ] `globals.css` imports the two Google Fonts with `display: swap` and `prefers-reduced-motion` media block.
- [ ] Initialize the shadcn primitives we need (button, card, badge, dialog, dropdown-menu, select, sonner).
- [ ] `layout.tsx` renders the top bar with brand, nav (Board / Import / Runs), and respects safe-area-insets.
- [ ] `page.tsx` redirects to `/board`.
- [ ] Commit: `feat(vacation/web): next.js skeleton + impeccable tokens`.

### T18: /board empty-state + layout shell

**Files:**
- Create: `vacation-app/apps/web/src/app/board/page.tsx`
- Create: `vacation-app/apps/web/src/app/board/empty-state.tsx`

- [ ] If `GET /api/board` returns zero members and zero leave entries, render the empty state from spec §7.6 with a CTA to `/import`.
- [ ] Otherwise render the board (T20 wires that up).
- [ ] Commit: `feat(vacation/web): board empty state`.

### T19: /import page (upload → preview → commit)

**Files:**
- Create: `vacation-app/apps/web/src/app/import/page.tsx`
- Create: `vacation-app/apps/web/src/app/import/upload-zone.tsx`
- Create: `vacation-app/apps/web/src/app/import/preview.tsx`
- Create: `vacation-app/apps/web/src/app/import/column-mapper.tsx`
- Create: `vacation-app/apps/web/src/app/import/unknown-codes-resolver.tsx`
- Create: `vacation-app/apps/web/src/lib/api.ts`

- [ ] `api.ts`: fetch helpers with typed responses.
- [ ] Upload zone: drag-and-drop + click-to-pick; uses `fetch` with multipart body; shows byte progress (XHR `progress` event).
- [ ] Preview: opens an `EventSource` to `/api/imports/:id/preview`; shows live progress; on `preview_ready` event renders the next step.
- [ ] Column mapper: side-by-side detected columns ↔ target fields, dropdowns with the suggested defaults pre-selected, "save as template" toggle.
- [ ] Unknown-codes resolver: list of unrecognized `event_description` values with a per-item dropdown of existing codes + "create new" option.
- [ ] Commit button → POST `/api/imports/:id/commit` → toast on success → redirect to `/import/runs/:id`.
- [ ] Commit: `feat(vacation/web): import wizard`.

### T20: /board virtualized grid + popover

**Files:**
- Create: `vacation-app/apps/web/src/app/board/board-grid.tsx`
- Create: `vacation-app/apps/web/src/app/board/cell.tsx`
- Create: `vacation-app/apps/web/src/app/board/filter-bar.tsx`
- Create: `vacation-app/apps/web/src/app/board/use-board-filters.ts`

- [ ] Filter bar: Shift A/B/C/Staff multi-select, Rank multi-select, date-range picker; state in Zustand mirrored to URL.
- [ ] Board grid: 2D virtualization using `@tanstack/react-virtual` (rows = members, cols = shift blocks).
- [ ] Each cell renders a 2-char code badge styled with `leave_codes.ui_color`.
- [ ] Sticky member column + sticky date header.
- [ ] Tap/hover opens a Radix Popover with member, block, code, source `import_run_id`, raw row.
- [ ] Mobile breakpoint: smaller cells, last-name + 2-letter rank.
- [ ] `prefers-reduced-motion`: disable cell-stagger animation.
- [ ] Commit: `feat(vacation/web): virtualized board`.

### T21: /import/runs history + rollback

**Files:**
- Create: `vacation-app/apps/web/src/app/import/runs/page.tsx`
- Create: `vacation-app/apps/web/src/app/import/runs/[id]/page.tsx`
- Create: `vacation-app/apps/web/src/app/import/runs/run-row.tsx`

- [ ] List page: paginated runs with status, file name, uploaded at, rows committed.
- [ ] Detail page: stats + rollback button with confirm dialog.
- [ ] Rollback: POST `/api/imports/:id/rollback`, toast result, refresh.
- [ ] Commit: `feat(vacation/web): import runs + rollback`.

### T22: apps/pin-gate (Cloudflare Worker)

**Files:**
- Create: `vacation-app/apps/pin-gate/package.json`
- Create: `vacation-app/apps/pin-gate/tsconfig.json`
- Create: `vacation-app/apps/pin-gate/wrangler.toml`
- Create: `vacation-app/apps/pin-gate/src/index.ts`
- Create: `vacation-app/apps/pin-gate/src/pin-form.html.ts`
- Create: `vacation-app/apps/pin-gate/src/sign.ts`

- [ ] Install: `@cloudflare/workers-types`, `wrangler` (dev only).
- [ ] `wrangler.toml`: name `mbfd-vacation-pin-gate`, route `vacation.mbfdhub.com/*`, KV binding `PIN_AUDIT_KV`, secrets `PIN_VALUE`, `PIN_SIGNING_SECRET`, `PIN_AUDIT_WEBHOOK_SECRET`, var `ORIGIN_URL` (the tunnel hostname).
- [ ] `pin-form.html.ts`: self-contained HTML page styled with impeccable tokens (no external CSS).
- [ ] `sign.ts`: HMAC-SHA256 helpers using `crypto.subtle`.
- [ ] `index.ts`: route handler (PIN submit, cookie verify, proxy, rate limit via KV, audit POST to API).
- [ ] Commit: `feat(vacation/pin-gate): cloudflare worker`.

### T23: infra/docker-compose + nginx

**Files:**
- Create: `vacation-app/infra/docker-compose.yml`
- Create: `vacation-app/infra/docker-compose.prod.yml`
- Create: `vacation-app/infra/nginx/Dockerfile`
- Create: `vacation-app/infra/nginx/default.conf`

- [ ] `docker-compose.yml` (dev): postgres, redis, api, worker, web; ports exposed to host only for dev.
- [ ] `docker-compose.prod.yml` (overlay): adds nginx; binds nginx to `127.0.0.1:7090`; removes dev port mappings.
- [ ] `nginx/default.conf`: routes `/api/*` → `vac-api:3001`, everything else → `vac-web:3000`. Sets `X-Forwarded-*` headers. Buffers tuned for large uploads. Body size 1G.
- [ ] All services on `vac-net` bridge.
- [ ] Healthchecks on every service.
- [ ] Commit: `feat(vacation/infra): docker compose dev + prod`.

### T24: postgres init.sql + extensions

**Files:**
- Create: `vacation-app/infra/postgres/init.sql`
- Create: `vacation-app/infra/postgres/extensions.sql`

- [ ] `extensions.sql`: enables `citext`, `pg_trgm`.
- [ ] `init.sql`: creates `vacation_allowance(years_of_service int)` function returning hours, the seed inserts for `ranks` and `leave_codes`, and any materialized views.
- [ ] Both scripts mounted into postgres container `/docker-entrypoint-initdb.d`.
- [ ] Commit: `feat(vacation/infra): postgres init + seed`.

### T25: cloudflared ingress snippet

**Files:**
- Create: `vacation-app/infra/cloudflared/ingress-snippet.yml`

- [ ] Single ingress block:
  ```yaml
  - hostname: vacation.mbfdhub.com
    service: http://127.0.0.1:7090
    originRequest:
      noTLSVerify: true
  ```
- [ ] DEPLOYMENT.md tells the operator where to splice this into the existing `mbfdhub-gmktec` tunnel config.
- [ ] Commit: `feat(vacation/infra): cloudflared ingress snippet`.

### T26: scripts (stress-fixture, seed-dev, deploy)

**Files:**
- Create: `vacation-app/scripts/stress-fixture.ts`
- Create: `vacation-app/scripts/seed-dev.ts`
- Create: `vacation-app/scripts/deploy.sh`

- [ ] `stress-fixture.ts`: generates a CSV with N members × 365 days × 2 blocks; writes to `./fixtures/telestaff-stress-{N}.csv`.
- [ ] `seed-dev.ts`: small dev fixture (50 members, 30 days).
- [ ] `deploy.sh`: idempotent — `git pull && docker compose ... up -d --build`.
- [ ] Commit: `feat(vacation/scripts): stress fixture + seed + deploy`.

### T27: unit tests

**Files:**
- Create: `vacation-app/tests/unit/parsers/csv.test.ts` (already from T12)
- Create: `vacation-app/tests/unit/parsers/xlsx.test.ts` (already from T13)
- Create: `vacation-app/tests/unit/mapping/infer-mapping.test.ts` (already from T14)
- Create: `vacation-app/tests/unit/db/vacation-allowance.test.ts`
- Create: `vacation-app/tests/unit/db/supersede.test.ts`

- [ ] Aggregate the tests from earlier tasks under `tests/unit/` and add the two new DB-logic tests.
- [ ] Run `pnpm test:unit` — all pass.
- [ ] Commit: `test(vacation): unit suite green`.

### T28: integration tests (Testcontainers Postgres)

**Files:**
- Create: `vacation-app/tests/integration/import-full-flow.test.ts`
- Create: `vacation-app/tests/integration/reimport-idempotency.test.ts`
- Create: `vacation-app/tests/integration/concurrent-imports.test.ts`
- Create: `vacation-app/tests/integration/setup.ts`

- [ ] Install: `@testcontainers/postgresql`, `testcontainers`.
- [ ] `setup.ts`: spins up a postgres container per test file, runs migrations + seed.
- [ ] Tests assert: full upload→commit flow inserts correct rows; re-importing same file is no-op; two simultaneous imports of different files commit cleanly.
- [ ] Commit: `test(vacation): integration suite`.

### T29: E2E tests (Playwright)

**Files:**
- Create: `vacation-app/tests/e2e/playwright.config.ts`
- Create: `vacation-app/tests/e2e/pin-gate.spec.ts`
- Create: `vacation-app/tests/e2e/empty-state.spec.ts`
- Create: `vacation-app/tests/e2e/import-happy-path.spec.ts`
- Create: `vacation-app/tests/e2e/rollback.spec.ts`
- Create: `vacation-app/tests/e2e/mobile.spec.ts`

- [ ] Install: `@playwright/test`.
- [ ] Config: projects for desktop chromium + iPhone 13 emulation.
- [ ] Specs cover each major flow from spec §10.3.
- [ ] Commit: `test(vacation): e2e suite`.

### T30: docs

**Files:**
- Create: `vacation-app/docs/DEPLOYMENT.md`
- Create: `vacation-app/docs/ADMIN-GUIDE.md`
- Create: `vacation-app/docs/ARCHITECTURE.md`

- [ ] DEPLOYMENT.md: first-deploy steps from spec §9.2, env vars, where each value comes from, troubleshooting.
- [ ] ADMIN-GUIDE.md: screenshot-free walkthrough for the no-coding-skills admin: how to upload a file, map columns, resolve unknown codes, commit, roll back.
- [ ] ARCHITECTURE.md: diagrams + module boundaries.
- [ ] Commit: `docs(vacation): deployment + admin + architecture`.

### T31: GitHub repo + push

**Files:**
- None new — uses `gh` CLI.

- [ ] `gh repo create pdarleyjr/mbfd-vacation --private --description "MBFD Vacation Selection Web App"`.
- [ ] Add as remote subtree from `MBFD_Hub/vacation-app/` OR mirror via `git subtree push` (DEPLOYMENT.md documents the chosen approach).
- [ ] Push.
- [ ] Update memory file with the deploy state.
- [ ] Commit: `chore(vacation): publish to standalone repo`.

### T32: memory update for next session

**Files:**
- Create or update: `C:\Users\Peter Darley\.claude\projects\d--GitHub-Repos-MBFD-Hub\memory\project_mbfd_vacation_app.md`
- Modify: `C:\Users\Peter Darley\.claude\projects\d--GitHub-Repos-MBFD-Hub\memory\MEMORY.md`

- [ ] Record: V1 scope, hosting target, subdomain, PIN model, stack, repo path, what's deployed vs not.
- [ ] Add MEMORY.md index entry.
- [ ] Commit: `chore(memory): record vacation v1 build state`.

---

## Self-Review

**Spec coverage check:**
- §1 Success criteria → all covered by tests in T27–T29 + the import pipeline in T05–T16.
- §2 Users → admin paths are T19, T21; PIN gate covers entry (T22).
- §3 Architecture → infra (T23–T25), pin gate (T22), apps (T04–T22).
- §4 Stack → installed in each app/package task.
- §5 Data model → T02 covers all 11 tables, seed in T24.
- §6 Import pipeline → T05–T08 (API), T11–T16 (worker), T19 (UI).
- §7 Board UI → T17–T20.
- §8 PIN gate → T22, with audit hook in T10.
- §9 Deployment → T23–T26, T30 (docs), T31 (repo).
- §10 Testing → T27–T29.
- §11 Security → woven through tasks (Zod env, JWT/HMAC cookie, parameterized queries, body size cap in T23).
- §12 Out-of-scope → respected, no rogue features in plan.
- §13 Phase 2 → not implemented (correct).

**Placeholder scan:** no "TBD" / "implement later" / "appropriate error handling" placeholders.

**Type consistency:** T05 returns `{runId, wasDuplicate}`; T06 references `runId` as the path param; T07 uses the same. T15 references `WorkCodeDecisionSchema` defined in T03. Consistent.

**Type signatures:**
- `parseCsv(stream)` / `parseXlsx(stream)` in T12/T13 return the same shape.
- `getDb(connectionString)` in T02 is the only DB factory used everywhere.
- BullMQ queue name `imports` is identical in T04, T05, T11.

Plan ready.

---

## Execution

User is in autonomous mode. Executing inline via `superpowers:executing-plans` (one task at a time, commit after each, skipping the inter-task review gate).
