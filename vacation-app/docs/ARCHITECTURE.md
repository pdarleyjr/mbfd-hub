# Architecture — MBFD Vacation Selection V1

See [docs/superpowers/specs/2026-05-27-mbfd-vacation-selection-design.md](../../docs/superpowers/specs/2026-05-27-mbfd-vacation-selection-design.md) for the design rationale.

## High level

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
                                  ┌─────┴─────┐
                                  │ vac-nginx │ 127.0.0.1:7090
                                  └────┬──────┘
                       ┌───────────────┼───────────────────┐
                       │               │                   │
                  vac-web          vac-api             vac-postgres
                  (Next.js 15)     (Hono on Node 22)   (Postgres 16)
                  port 3000        port 3001
                       │               │                   │
                       │               └──── BullMQ ───────┼─→ vac-redis
                       │                                   │     (Redis 7)
                       │                                   │
                       │                              vac-worker
                       │                              (BullMQ consumer)
                       │                                   │
                       │                                   ▼
                       │                              R2 (mbfd-hub-laravel)
                       │                              key prefix vacation/imports/
                       │
                       └── HTTP same-origin /api/* → vac-api
```

## Module boundaries

| Module | Owns | Doesn't know about |
|---|---|---|
| `packages/db` | Drizzle schema, migrations, rollback op | HTTP, queues, R2 |
| `packages/shared` | Zod contracts for API/UI | DB |
| `apps/api` | HTTP, R2 streaming, queue enqueue, SSE | parsing |
| `apps/worker` | File parsing, commit logic, supersede | HTTP |
| `apps/web` | UI, filters, state | DB, parsing |
| `apps/pin-gate` | PIN form, HMAC cookie, rate limit, proxy | the app itself |
| `infra/` | nginx, postgres init, compose stacks, CF Tunnel snippet | app code |

## Request flow — uploading a Telestaff file

1. User PUTs a multipart/form-data POST to `/api/imports` from the browser.
2. Worker `nginx` proxies to `vac-api:3001` with `proxy_request_buffering off`.
3. Hono route streams the body through busboy, hashing as it goes, and
   uploads to R2 using `@aws-sdk/lib-storage`'s `Upload` (5 MB multipart parts).
4. On finish the API inserts an `import_runs` row (status `uploaded`), enqueues
   a `parse-preview` job in BullMQ, and returns `{ runId, wasDuplicate }`.
5. The browser opens an `EventSource` to `/api/imports/:id/preview`.
6. The worker pulls the file from R2, streams it through `csv-parse` (or
   `xlsx-stream-reader`), samples 100 rows, infers a column mapping, and
   publishes a `preview_ready` event over Redis pub/sub. The SSE endpoint
   forwards it to the browser.
7. The browser displays the suggested mapping. The admin tweaks and clicks
   commit. POST `/api/imports/:id/commit` enqueues a `commit-import` job.
8. The worker streams the file again (R2 → CSV/XLSX parser → row loop),
   resolves the leave code per row, upserts the member and shift block,
   inserts a new `leave_entries` row, supersedes any prior active entry for
   the same `(member, block)`. On finish it sets `status='committed'` and
   stamps `parse_stats`.
9. The board page polls `/api/board` every 30s (and on filter change).

## Soft-supersede invariant

The active row for each `(member_id, shift_block_id)` is the one with
`superseded_by_entry_id IS NULL`. The partial unique index
`leave_entries_active_uk` enforces uniqueness ONLY on those rows, so:

- Re-importing flips the pointer; both rows continue to exist.
- Rollback reverses the pointer; partial unique index still happy.
- Audit trail is permanent — every cell history is queryable.

## Why not Cloudflare D1 + Workers (like bid.mbfdhub.com)?

The bid app uses D1 + DOs because the workload is small (< 300 members),
write-heavy at one moment, and benefits from edge fanout. The vacation app
has the opposite shape: large bulk imports of up to 5M rows, occasional
read-heavy board queries, and a worker that wants to use Postgres `COPY` and
streaming. Postgres on a single box is a better fit; D1's 100 MB request cap
would have ruled out the largest Telestaff exports anyway.

## Blast-radius isolation

Nothing here shares with MBFDHub or any other GMKtec stack:

| Resource | This app | Others |
|---|---|---|
| Docker network | `vac-net` | `mbfd-net`, `nextcloud-net`, `ts-orchestrator_ts-net` |
| Postgres | `vac-postgres` (own container, own volume) | MBFDHub MySQL, Nextcloud Postgres |
| Redis | `vac-redis` (own container, own volume) | (none other on this box) |
| Tunnel | `mbfdhub-gmktec` (shared, new ingress rule only) | shared |
| R2 bucket | `mbfd-hub-laravel` (shared bucket, new key prefix `vacation/`) | shared |
| Cloudflare Worker | `mbfd-vacation-pin-gate` | `mbfd-support-ai`, ScreenTinker, etc. |

## Phase 2 roadmap (not in V1)

Adds-only. No schema migration of existing tables.

- `vacation_requests` + `approval_decisions` + `waitlist_entries` + `staffing_rules`
- Member self-service surface gated by Employee Portal API auth
- An approval engine that produces `APPROVE | DENY | OVERRIDE | WAITLIST`
  reasons informed by `staffing_rules` and the current `leave_entries`
- Admin override + audit UI
- Computed A-day cycle generation (V1 only renders A-days that appear in the import)
- Manual cell editing, member roster editing, leave-code policy editing
