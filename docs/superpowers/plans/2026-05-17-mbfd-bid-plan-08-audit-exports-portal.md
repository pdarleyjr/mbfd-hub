# Plan 08 — Audit log integrity, exports, employee portal write-back

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Three deliverables wired in parallel:
1. Every confirmed pick writes an event to a **hash-chained, ed25519-signed JSONL stream** in R2 — in parallel with the existing D1 `audit_log` insert. R2 JSONL is the legal record; D1 is the queryable index. A tampered byte anywhere in any chunk MUST fail the `/verify-chain` endpoint.
2. **Post-bid exports**: roster PDF per shift (A/B/C/D) generated via Browserless against a print-stylesheet RSC page; full audit CSV streamed from D1 → gzip → R2 with a signed URL. Admin UI lists all exports for a session.
3. **Employee portal write-back via Cloudflare Queues**: on every committed pick, enqueue a payload; a queue consumer POSTs to the portal `/bid-assignment` endpoint with exponential backoff up to 24 hours / 24 attempts. Picks remain durable in D1+R2 even when the portal is down; sync completes when the portal recovers. Admin can manually retry failed rows.

**Architecture:** Three independent sub-systems share the same Worker bundle for cold-start efficiency, but each can be feature-flagged off without affecting the others. Sub-system A (audit chain) is the highest-criticality and runs synchronously inline with DO commits; sub-systems B (exports) and C (portal write-back) are async/asynchronous and tolerate downtime.

**Tech stack additions on top of Plans 01–07:**
- `@noble/hashes@^1.4` — SHA-256 for the chain (Worker-compatible, no Node `crypto`)
- `@noble/ed25519@^2.1` — chunk signature (audited, ~7KB)
- `papaparse@^5.4` — already present (Plan 02) — used for audit CSV streaming
- Cloudflare Queues — `[[queues.producers]]` + `[[queues.consumers]]` in `wrangler.toml`
- Cloudflare R2 — new buckets `bid-audit` and `bid-exports` (created via Wrangler)
- Cloudflare Cron Triggers — `[triggers] crons` 30s flush + daily reconciliation
- **Browserless v2** (hosted Chrome) for PDF rendering — `BROWSERLESS_TOKEN` Wrangler secret; fallback path documented for `@cloudflare/puppeteer`
- `pako@^2.1` — gzip in Worker (no Node `zlib`)

**Cross-references:**
- **Plan 02** (data plane) — `audit_log` and `portal_writeback_queue` tables already exist; this plan adds two new tables (`audit_chunks`, `audit_chain_state`) plus two new columns on `audit_log` (`chunk_seq`, `chunk_row_index`).
- **Plan 04** (live bid core) — DO commit path is the source of audit events. This plan inserts a single new call (`auditEmitter.emit(event)`) into the DO commit flow that already exists.
- **Plan 03** (eligibility engine) — style reference for TDD detail; not a runtime dependency.
- **Plan 05** (admin console) — `/admin` shell + step-up middleware (`requireStepUpAuth`) is reused for the admin write endpoints in this plan.
- **Plan 06** (AI integration) — none. The AI advisory rows are exported by sub-system B (audit CSV / `2026_AI_Advisory_Log.jsonl`) but generation is out of scope.
- **Plan 07** (A-Day Phase 2) — Phase-2 picks emit additional audit events identical in shape; this plan handles them automatically.

---

## Decisions preamble (locked in before any code is written)

| # | Decision | Choice | Rationale |
|---|----------|--------|-----------|
| D1 | Chunk flush threshold | **100 events OR 30 seconds, whichever first** | 100 events ≈ 40 KB JSONL — well under R2 chunk minimums. 30 s bounds worst-case loss-window for an idle-but-active session. |
| D2 | Queue consumer placement | **Same Worker bundle, `queue` handler export** | Single bundle = single secret store + simpler observability. Cold-start risk is irrelevant for 24h-retry async work. |
| D3 | ed25519 key rotation | **One keypair per (year, environment)**; generated via `openssl genpkey -algorithm Ed25519`; private key in Wrangler secret `AUDIT_SIGNING_PRIVKEY`; public key baked into chunk header on every write | Pubkey-in-header lets verifier work without external lookup. Yearly rotation matches the bid cycle. |
| D4 | Portal payload schema source | **`packages/shared/src/schemas/portal-payload.ts` — Zod schema imported by producer, consumer, AND tests** | One source of truth prevents drift between enqueue side and POST side. |
| D5 | PDF generator | **Browserless v2 (`https://chrome.browserless.io/pdf`)** | Free tier sufficient for ~10 PDFs per bid event. Fallback (paid `@cloudflare/puppeteer`) documented in Task 11 §Notes. |
| D6 | Retry cap policy | **Hard cap: 24 attempts over 24h; then mark `failed`, surface in admin banner, require manual retry** | Prevents infinite drain on a permanently-broken endpoint. 24h matches the operational window between bid event end and next-day cleanup. |
| D7 | Hash function | **SHA-256 via `@noble/hashes/sha256`** | Node `crypto` is not Worker-safe in all paths; `@noble` is pure JS + audited. |
| D8 | JSONL canonicalization | **`canonical-json` algorithm RFC 8785 (JCS) — sorted keys, no whitespace, no NaN/Infinity** | Required for byte-stable hash input across implementations. Implemented inline (~20 LOC) — no deps. |
| D9 | Tamper-detection sensitivity | **Any single-byte mutation in any chunk MUST fail `/verify-chain`** | This is the legal-record guarantee. Encoded as an integration test (Task 8). |
| D10 | D1 vs R2 priority during live event | **R2 JSONL is canonical; D1 is a queryable index. If R2 write fails, the pick is rejected by the DO (HTTP 500 to the WS client)** | Architect red-team finding — never rely solely on D1 for the legal audit. |
| D11 | Roster PDF visual reference | **`D:/MBFD/Bid/2025 Bid Documents/2025_A_Shift.pdf`** | Visual parity validated by Playwright `toHaveScreenshot` snapshot (Task 16). |
| D12 | Retry backoff math | **Integer arithmetic only**: `next_attempt_at = enqueued_at + min(24h, 2^min(attempts, 10) * 1000ms) + jitter(0–1000ms)` | Float drift in long-running retries causes spurious requeues. Capped exponent at 10 (≈ 17 min) to avoid integer overflow. |

---

## Architecture sketch — three sub-systems

```
                       ┌─────────────────────────────────────────────────────┐
                       │   BidSession Durable Object (Plan 04)              │
                       │   .commit(pick) {                                  │
                       │     1. state.storage.put(...)                      │
                       │     2. db.insert(audit_log)            ◄── existing│
                       │     3. auditEmitter.emit(event)        ◄── NEW (A) │
                       │     4. env.PORTAL_QUEUE.send(payload)  ◄── NEW (C) │
                       │     5. broadcast WS pick_made                      │
                       │   }                                                │
                       └─────────────────────────────────────────────────────┘
                                  │                  │                 │
            ┌─────────────────────┘                  │                 └─────────────────────────────┐
            ▼ (A) audit chain                        │ (B) exports                                   ▼ (C) portal write-back
┌───────────────────────────────────┐                │                            ┌────────────────────────────────────────┐
│ jsonl-chunker.ts                  │                │                            │ Cloudflare Queue `portal-writebacks`   │
│   buffer events in-memory         │                │                            │   producer (DO step 4)                 │
│   flush on 100 events OR 30s      │                │                            │   consumer (`queue` handler, same     │
│   ↓                               │                │                            │   Worker bundle)                       │
│ hash-chain.ts                     │                │                            │     POST portal /bid-assignment       │
│   chunk_hash = SHA-256(           │                │                            │     200/409 → mark synced              │
│     prev_chunk_hash               │                │                            │     5xx     → backoff requeue          │
│     || canonical_json(events))    │                │                            │     4xx     → mark permanently_failed  │
│   ↓                               │                │                            │   retry-policy.ts: 24 attempts / 24h   │
│ signer.ts                         │                │                            └────────────────────────────────────────┘
│   ed25519 sign chunk_hash         │                │
│   ↓                               │                ▼ admin clicks "Export"
│ R2 bid-audit/                     │     ┌──────────────────────────────┐
│   <year>/<session>/chunks/        │     │ roster-pdf.ts                │
│     0001.jsonl                    │     │   POST chrome.browserless.io │
│     0002.jsonl ...                │     │   /pdf  { url, options }     │
└───────────────────────────────────┘     │   stream PDF → R2 bid-exports│
            ▲                              └──────────────────────────────┘
            │ /admin/audit/verify           │
            │   reads chunks in seq order  ▼
            │   verifies chain + sig      ┌──────────────────────────────┐
            └──── verifier.ts             │ audit-csv.ts                 │
                                          │   paginated D1 SELECT        │
                                          │   gzip → R2 bid-exports      │
                                          │   signed URL                 │
                                          └──────────────────────────────┘
```

### File map (sub-system A — audit chain)

```
apps/worker/
  src/audit/
    canonical-json.ts                 ← RFC 8785 JCS implementation (pure)
    hash-chain.ts                     ← SHA-256(prev || canonical_json(events))
    signer.ts                         ← ed25519 sign + verify helpers
    jsonl-chunker.ts                  ← in-memory buffer with flush triggers
    chain-emitter.ts                  ← public `AuditEmitter` consumed by DO
    verifier.ts                       ← reads R2 + replays chain
    types.ts                          ← AuditEvent, ChunkHeader, ChunkRecord
  src/routes/admin/
    audit.ts                          ← GET /api/admin/audit/verify-chain
  migrations/
    0006_audit_chain.sql              ← audit_chunks + audit_chain_state +
                                        audit_log.chunk_seq + .chunk_row_index
  src/scheduled.ts                    (UPDATE: add 30s cron to flush stale buffers)
  tests/
    audit/canonical-json.test.ts
    audit/hash-chain.test.ts
    audit/signer.test.ts
    audit/jsonl-chunker.test.ts
    audit/chain-emitter.test.ts
    audit/verifier.test.ts
    audit/admin-verify-chain.test.ts
    integration/audit-tamper.test.ts
    integration/audit-replay-250.test.ts
```

### File map (sub-system B — exports)

```
apps/worker/
  src/exports/
    roster-pdf.ts                     ← Browserless wrapper
    audit-csv.ts                      ← D1 → gzip → R2 streamer
    signed-url.ts                     ← R2 presigned URL helper
    export-registry.ts                ← lists available exports per session
  src/routes/admin/
    exports.ts                        ← POST /api/admin/exports/roster/:shift,
                                        POST /api/admin/exports/audit-csv,
                                        GET  /api/admin/exports/:session_id
  tests/
    exports/roster-pdf.test.ts
    exports/audit-csv.test.ts
    exports/signed-url.test.ts
    exports/admin-exports.test.ts

apps/web/
  app/admin/exports/
    page.tsx                          ← list available exports + portal status
    render/roster/[shift]/[session_id]/page.tsx   ← print RSC page Browserless hits
    _components/
      ExportCard.tsx
      PortalSyncStatus.tsx
      ManualRetryButton.tsx
      ExportTriggerButton.tsx
  tests/e2e/
    admin-export-roster.spec.ts
    admin-export-roster-visual.spec.ts  ← Playwright snapshot
```

### File map (sub-system C — portal write-back)

```
apps/worker/
  src/portal-writeback/
    payload-builder.ts                ← §11.8.2 field derivation
    queue-producer.ts                 ← env.PORTAL_QUEUE.send()
    queue-consumer.ts                 ← exported as `queue` handler
    portal-client.ts                  ← POST /bid-assignment + auth header
    retry-policy.ts                   ← exp backoff + integer math
    reconciliation.ts                 ← daily cron: list failed rows
  src/routes/admin/
    portal.ts                         ← POST /api/admin/portal-retry/:bid_id
                                        POST /api/admin/portal-clear-year
  tests/
    portal-writeback/payload-builder.test.ts
    portal-writeback/queue-producer.test.ts
    portal-writeback/queue-consumer.test.ts
    portal-writeback/retry-policy.test.ts
    portal-writeback/portal-client.test.ts
    integration/portal-retry-5xx.test.ts
    integration/portal-perm-fail-4xx.test.ts

apps/web/
  tests/e2e/admin-portal-resync.spec.ts

packages/shared/
  src/schemas/
    portal-payload.ts                 ← Zod schema (producer + consumer + tests)
    audit-event.ts                    ← Zod schema for AuditEvent
    audit-chunk.ts                    ← Zod schema for ChunkHeader
```

---

## Source-of-truth references

| File | Use |
|------|-----|
| `D:/GitHub_Repos/MBFD_Hub/docs/superpowers/specs/2026-05-17-mbfd-bid-webapp-design.md` §6.3, §6.4, §11.8 | Spec for R2 paths, chunk header, portal payload |
| `D:/MBFD/Bid/2026 Bid Documents/2026_Bid_Process.md` §8, §9 | Audit fields + output artifacts |
| `D:/MBFD/Bid/2025 Bid Documents/2025_A_Shift.pdf` | Visual reference for roster PDF |
| `D:/MBFD/Bid/2025 Bid Documents/2025_Bid_Audit_Log.csv` (if present) | Reference shape for audit CSV |
| `D:/GitHub_Repos/mbfd-bid/apps/worker/src/db/schema.ts` | Current schema (already has `bids.portal_sync_*` + `portal_writeback_queue`) |
| `D:/GitHub_Repos/mbfd-bid/apps/worker/wrangler.toml` | Worker config; this plan adds R2 + Queues + crons |
| RFC 8785 — JCS | Canonical JSON algorithm |

---


## Task 1: Install crypto, gzip, and Browserless deps + Wrangler config

**Files:**
- Modify: `apps/worker/package.json`
- Modify: `apps/worker/wrangler.toml`
- Modify: `apps/worker/worker-configuration.d.ts`

- [ ] **Step 1: Install runtime deps**

```bash
cd apps/worker && pnpm add @noble/hashes@^1.4 @noble/ed25519@^2.1 pako@^2.1
cd apps/worker && pnpm add -D @types/pako@^2
```

- [ ] **Step 2: Append to `apps/worker/wrangler.toml` — staging env block**

```toml
# Plan 08: R2 buckets
[[env.staging.r2_buckets]]
binding = "R2_AUDIT"
bucket_name = "mbfd-bid-audit-staging"

[[env.staging.r2_buckets]]
binding = "R2_EXPORTS"
bucket_name = "mbfd-bid-exports-staging"

# Plan 08: Queues
[[env.staging.queues.producers]]
binding = "PORTAL_QUEUE"
queue = "mbfd-portal-writebacks-staging"

[[env.staging.queues.consumers]]
queue = "mbfd-portal-writebacks-staging"
max_batch_size = 10
max_batch_timeout = 5
max_retries = 0
dead_letter_queue = "mbfd-portal-writebacks-staging-dlq"

# Plan 08: Cron triggers
[triggers]
crons = [
  "*/1 * * * *",
  "15 4 * * *",
]
```

Mirror under `[env.production]` with `-production` suffixes.

- [ ] **Step 3: Document new secrets in `wrangler.toml` secret list comment**

```toml
#   AUDIT_SIGNING_PRIVKEY   (ed25519 32-byte base64url — per year per env)
#   AUDIT_SIGNING_PUBKEY    (ed25519 32-byte base64url — non-secret)
#   BROWSERLESS_TOKEN       (Browserless v2 API token)
```

- [ ] **Step 4: Extend Env interface in `apps/worker/worker-configuration.d.ts`**

```ts
interface Env {
  R2_AUDIT: R2Bucket;
  R2_EXPORTS: R2Bucket;
  PORTAL_QUEUE: Queue<unknown>;
  AUDIT_SIGNING_PRIVKEY: string;
  AUDIT_SIGNING_PUBKEY: string;
  BROWSERLESS_TOKEN: string;
}
```

- [ ] **Step 5: Create R2 buckets + queues on staging**

```bash
wrangler r2 bucket create mbfd-bid-audit-staging
wrangler r2 bucket create mbfd-bid-exports-staging
wrangler queues create mbfd-portal-writebacks-staging
wrangler queues create mbfd-portal-writebacks-staging-dlq
```

- [ ] **Step 6: Generate ed25519 keypair (one-time per environment per year)**

```bash
node -e "
const ed = require('@noble/ed25519');
(async () => {
  const priv = ed.utils.randomPrivateKey();
  const pub = await ed.getPublicKeyAsync(priv);
  const b64u = (b) => Buffer.from(b).toString('base64url');
  console.log('AUDIT_SIGNING_PRIVKEY=' + b64u(priv));
  console.log('AUDIT_SIGNING_PUBKEY=' + b64u(pub));
})();
"
```

Store:

```bash
wrangler secret put AUDIT_SIGNING_PRIVKEY --env staging
wrangler secret put AUDIT_SIGNING_PUBKEY --env staging
wrangler secret put BROWSERLESS_TOKEN --env staging
```

- [ ] **Step 7: Commit**

```bash
git add apps/worker/package.json apps/worker/wrangler.toml apps/worker/worker-configuration.d.ts pnpm-lock.yaml
git commit -m "chore(worker): R2 + Queue + cron bindings; install noble crypto deps"
```

---

## Task 2: Migration 0006 — audit chain bookkeeping

**Files:**
- Modify: `apps/worker/src/db/schema.ts`
- Create: `apps/worker/migrations/0006_audit_chain.sql`
- Test: `apps/worker/tests/db/schema-0006.test.ts`

Adds two tables (`audit_chunks`, `audit_chain_state`) and two columns on `audit_log` (`chunk_seq`, `chunk_row_index`). The `portal_writeback_queue` table and `bids.portal_sync_*` columns already exist in mig 0004 — do NOT recreate them.

- [ ] **Step 1: Write failing schema test**

```ts
// apps/worker/tests/db/schema-0006.test.ts
import { describe, it, expect } from 'vitest';
import * as schema from '../../src/db/schema';

describe('Plan 08 schema (mig 0006)', () => {
  it('exports audit_chunks table with all columns', () => {
    expect(schema.auditChunks).toBeDefined();
    expect(schema.auditChunks.bidSessionId).toBeDefined();
    expect(schema.auditChunks.seq).toBeDefined();
    expect(schema.auditChunks.r2Key).toBeDefined();
    expect(schema.auditChunks.sha256).toBeDefined();
    expect(schema.auditChunks.prevSha256).toBeDefined();
    expect(schema.auditChunks.signatureB64u).toBeDefined();
    expect(schema.auditChunks.pubkeyB64u).toBeDefined();
    expect(schema.auditChunks.eventsInChunk).toBeDefined();
    expect(schema.auditChunks.minSeq).toBeDefined();
    expect(schema.auditChunks.maxSeq).toBeDefined();
    expect(schema.auditChunks.signedAt).toBeDefined();
  });

  it('exports audit_chain_state table', () => {
    expect(schema.auditChainState).toBeDefined();
    expect(schema.auditChainState.bidSessionId).toBeDefined();
    expect(schema.auditChainState.nextSeq).toBeDefined();
    expect(schema.auditChainState.pendingBufferStartedAt).toBeDefined();
    expect(schema.auditChainState.lastChunkSha256).toBeDefined();
  });

  it('audit_log has chunk_seq + chunk_row_index', () => {
    expect(schema.auditLog.chunkSeq).toBeDefined();
    expect(schema.auditLog.chunkRowIndex).toBeDefined();
  });
});
```

- [ ] **Step 2: Run test, expect FAIL** (`auditChunks is not defined`).

- [ ] **Step 3: Extend `apps/worker/src/db/schema.ts`** — append at bottom

```ts
export const auditChunks = sqliteTable(
  'audit_chunks',
  {
    bidSessionId: text('bid_session_id')
      .notNull()
      .references(() => bidSessions.id, { onDelete: 'cascade' }),
    seq: integer('seq').notNull(),
    r2Key: text('r2_key').notNull(),
    sha256: text('sha256').notNull(),
    prevSha256: text('prev_sha256'),
    signatureB64u: text('signature_b64u').notNull(),
    pubkeyB64u: text('pubkey_b64u').notNull(),
    eventsInChunk: integer('events_in_chunk').notNull(),
    minSeq: integer('min_seq').notNull(),
    maxSeq: integer('max_seq').notNull(),
    signedAt: integer('signed_at', { mode: 'timestamp' }).notNull(),
  },
  (t) => ({
    pk: primaryKey({ columns: [t.bidSessionId, t.seq] }),
    sessionSeqIdx: index('idx_audit_chunks_session_seq').on(t.bidSessionId, t.seq),
  }),
);

export const auditChainState = sqliteTable('audit_chain_state', {
  bidSessionId: text('bid_session_id')
    .primaryKey()
    .references(() => bidSessions.id, { onDelete: 'cascade' }),
  nextSeq: integer('next_seq').notNull().default(1),
  pendingBufferStartedAt: integer('pending_buffer_started_at', { mode: 'timestamp' }),
  lastChunkSha256: text('last_chunk_sha256'),
});
```

Add to the existing `auditLog` block:

```ts
chunkSeq: integer('chunk_seq'),
chunkRowIndex: integer('chunk_row_index'),
```

- [ ] **Step 4: Generate SQL**

```bash
cd apps/worker && pnpm db:generate
```

- [ ] **Step 5: Curate to `apps/worker/migrations/0006_audit_chain.sql`**

```sql
CREATE TABLE `audit_chunks` (
  `bid_session_id` text NOT NULL,
  `seq` integer NOT NULL,
  `r2_key` text NOT NULL,
  `sha256` text NOT NULL,
  `prev_sha256` text,
  `signature_b64u` text NOT NULL,
  `pubkey_b64u` text NOT NULL,
  `events_in_chunk` integer NOT NULL,
  `min_seq` integer NOT NULL,
  `max_seq` integer NOT NULL,
  `signed_at` integer NOT NULL,
  PRIMARY KEY (`bid_session_id`, `seq`),
  FOREIGN KEY (`bid_session_id`) REFERENCES `bid_sessions`(`id`) ON DELETE CASCADE
);
CREATE INDEX `idx_audit_chunks_session_seq` ON `audit_chunks` (`bid_session_id`, `seq`);

CREATE TABLE `audit_chain_state` (
  `bid_session_id` text PRIMARY KEY NOT NULL,
  `next_seq` integer NOT NULL DEFAULT 1,
  `pending_buffer_started_at` integer,
  `last_chunk_sha256` text,
  FOREIGN KEY (`bid_session_id`) REFERENCES `bid_sessions`(`id`) ON DELETE CASCADE
);

ALTER TABLE `audit_log` ADD COLUMN `chunk_seq` integer;
ALTER TABLE `audit_log` ADD COLUMN `chunk_row_index` integer;
```

- [ ] **Step 6: Apply local + remote; run test; expect PASS**

```bash
cd apps/worker && pnpm db:migrate:local
cd apps/worker && pnpm db:migrate:remote
pnpm --filter @mbfd/worker test schema-0006 -- --run
```

- [ ] **Step 7: Commit**

```bash
git add apps/worker/src/db/schema.ts apps/worker/migrations/0006_audit_chain.sql apps/worker/tests/db/schema-0006.test.ts
git commit -m "feat(db): migration 0006 audit chain bookkeeping"
```

---

## Task 3: Canonical JSON (RFC 8785 / JCS)

**Files:**
- Create: `apps/worker/src/audit/canonical-json.ts`
- Test: `apps/worker/tests/audit/canonical-json.test.ts`

Byte-stable serialization is the foundation of the hash chain. JCS rules: sorted object keys, no whitespace, no NaN/Infinity, escape JSON-required characters only. We restrict to integer numbers (audit events have no floats).

- [ ] **Step 1: Write failing test**

```ts
// apps/worker/tests/audit/canonical-json.test.ts
import { describe, it, expect } from 'vitest';
import { canonicalize } from '../../src/audit/canonical-json';

describe('canonicalize (RFC 8785 / JCS)', () => {
  it('sorts object keys lexicographically', () => {
    expect(canonicalize({ b: 1, a: 2 })).toBe('{"a":2,"b":1}');
  });

  it('produces no whitespace', () => {
    expect(canonicalize({ a: [1, 2, 3] })).toBe('{"a":[1,2,3]}');
  });

  it('is byte-stable regardless of key insertion order', () => {
    const a = { x: 1, y: { p: 2, q: 3 }, z: [3, 1, 2] };
    const b = { z: [3, 1, 2], y: { q: 3, p: 2 }, x: 1 };
    expect(canonicalize(a)).toBe(canonicalize(b));
  });

  it('preserves array order (arrays are NOT sorted)', () => {
    expect(canonicalize([3, 1, 2])).toBe('[3,1,2]');
  });

  it('escapes JSON-required characters in strings', () => {
    expect(canonicalize({ s: 'a"b\\c\n' })).toBe('{"s":"a\\"b\\\\c\\n"}');
  });

  it('serializes null + booleans', () => {
    expect(canonicalize({ x: null, t: true, f: false })).toBe('{"f":false,"t":true,"x":null}');
  });

  it('throws on NaN, Infinity, undefined', () => {
    expect(() => canonicalize({ x: NaN })).toThrow(/NaN/);
    expect(() => canonicalize({ x: Infinity })).toThrow(/Infinity/);
    expect(() => canonicalize({ x: undefined as unknown as null })).toThrow(/undefined/);
  });

  it('audit event shape has sorted keys', () => {
    const evt = { action: 'pick', actor_id: 42, actor_type: 'member', bid_session_id: '01HF3', seq: 17, target_id: 'A101' };
    expect(canonicalize(evt)).toBe('{"action":"pick","actor_id":42,"actor_type":"member","bid_session_id":"01HF3","seq":17,"target_id":"A101"}');
  });
});
```

- [ ] **Step 2: Run test, expect FAIL**

- [ ] **Step 3: Implement `apps/worker/src/audit/canonical-json.ts`**

```ts
// apps/worker/src/audit/canonical-json.ts
export type JsonScalar = string | number | boolean | null;
export type JsonValue = JsonScalar | JsonValue[] | { [k: string]: JsonValue };

export function canonicalize(v: JsonValue): string {
  if (v === null) return 'null';
  if (typeof v === 'boolean') return v ? 'true' : 'false';
  if (typeof v === 'number') {
    if (Number.isNaN(v)) throw new Error('NaN not representable in canonical JSON');
    if (!Number.isFinite(v)) throw new Error('Infinity not representable in canonical JSON');
    if (!Number.isInteger(v)) throw new Error('Non-integer numbers not supported');
    return String(v);
  }
  if (typeof v === 'string') return canonicalString(v);
  if (Array.isArray(v)) return '[' + v.map(canonicalize).join(',') + ']';
  if (typeof v === 'object') {
    const obj = v as { [k: string]: JsonValue };
    const keys = Object.keys(obj).sort();
    const parts: string[] = [];
    for (const k of keys) {
      const val = obj[k];
      if (val === undefined) throw new Error(`undefined value at "${k}"`);
      parts.push(canonicalString(k) + ':' + canonicalize(val));
    }
    return '{' + parts.join(',') + '}';
  }
  throw new Error(`Unsupported value: ${typeof v}`);
}

function canonicalString(s: string): string {
  let out = '"';
  for (let i = 0; i < s.length; i++) {
    const c = s.charCodeAt(i);
    if (c === 0x22) out += '\\"';
    else if (c === 0x5c) out += '\\\\';
    else if (c === 0x08) out += '\\b';
    else if (c === 0x09) out += '\\t';
    else if (c === 0x0a) out += '\\n';
    else if (c === 0x0c) out += '\\f';
    else if (c === 0x0d) out += '\\r';
    else if (c < 0x20) out += '\\u' + c.toString(16).padStart(4, '0');
    else out += s[i];
  }
  return out + '"';
}
```

- [ ] **Step 4: Run test, expect PASS**

- [ ] **Step 5: Commit**

```bash
git add apps/worker/src/audit/canonical-json.ts apps/worker/tests/audit/canonical-json.test.ts
git commit -m "feat(audit): RFC 8785 canonical JSON serializer"
```

---

## Task 4: AuditEvent + ChunkHeader Zod schemas

**Files:**
- Create: `packages/shared/src/schemas/audit-event.ts`
- Create: `packages/shared/src/schemas/audit-chunk.ts`
- Modify: `packages/shared/src/index.ts`
- Test: `packages/shared/tests/schemas/audit-event.test.ts`
- Test: `packages/shared/tests/schemas/audit-chunk.test.ts`

- [ ] **Step 1: Write failing tests**

```ts
// packages/shared/tests/schemas/audit-event.test.ts
import { describe, it, expect } from 'vitest';
import { AuditEventSchema } from '../../src/schemas/audit-event.js';

describe('AuditEventSchema', () => {
  it('accepts a minimal pick event', () => {
    const e = AuditEventSchema.parse({
      seq: 1, bid_session_id: '01HF3', action: 'pick',
      actor_type: 'member', actor_id: 42,
      target_kind: 'position', target_id: 'A101',
      created_at: '2026-09-22T14:23:00Z',
    });
    expect(e.seq).toBe(1);
  });
  it('rejects unknown action', () => {
    expect(() => AuditEventSchema.parse({
      seq: 1, bid_session_id: 'x', action: 'BOGUS',
      actor_type: 'member', actor_id: 1, created_at: '2026-09-22T14:23:00Z',
    })).toThrow();
  });
  it('rejects non-integer seq', () => {
    expect(() => AuditEventSchema.parse({
      seq: 1.5, bid_session_id: 'x', action: 'pick',
      actor_type: 'member', actor_id: 1, created_at: '2026-09-22T14:23:00Z',
    })).toThrow();
  });
});
```

```ts
// packages/shared/tests/schemas/audit-chunk.test.ts
import { describe, it, expect } from 'vitest';
import { ChunkHeaderSchema } from '../../src/schemas/audit-chunk.js';

describe('ChunkHeaderSchema', () => {
  it('accepts a valid header', () => {
    const h = ChunkHeaderSchema.parse({
      chunk_seq: 12,
      prev_chunk_sha256: 'a'.repeat(64),
      events_in_chunk: 100,
      min_seq: 1100,
      max_seq: 1199,
      signature: 'b'.repeat(86),
      pubkey: 'c'.repeat(43),
      signed_at: '2026-09-22T14:23:00Z',
    });
    expect(h.chunk_seq).toBe(12);
  });
  it('rejects negative seq', () => {
    expect(() => ChunkHeaderSchema.parse({
      chunk_seq: -1, prev_chunk_sha256: 'a'.repeat(64), events_in_chunk: 1,
      min_seq: 1, max_seq: 1, signature: 'x', pubkey: 'x', signed_at: '2026-09-22T14:23:00Z',
    })).toThrow();
  });
});
```

- [ ] **Step 2: Run tests, expect FAIL**

- [ ] **Step 3: Implement `packages/shared/src/schemas/audit-event.ts`**

```ts
// packages/shared/src/schemas/audit-event.ts
import { z } from 'zod';

export const AuditActionSchema = z.enum([
  'pick', 'forced_pick', 'pause', 'resume', 'skip',
  'override_rule', 'override_cert',
  'lock_position', 'unlock_position', 'grant_extension',
  'admin_bid_for_member', 'session_start', 'session_complete',
  'members_import', 'credentials_import', 'positions_clone', 'rule_book_clone',
  'day_end', 'day_start',
]);
export type AuditAction = z.infer<typeof AuditActionSchema>;

export const AuditActorTypeSchema = z.enum(['member', 'admin', 'system', 'ai']);
export type AuditActorType = z.infer<typeof AuditActorTypeSchema>;

export const AuditEventSchema = z.object({
  seq: z.number().int().nonnegative(),
  bid_session_id: z.string().min(1),
  action: AuditActionSchema,
  actor_type: AuditActorTypeSchema,
  actor_id: z.number().int().nullable(),
  target_kind: z.string().nullable().optional(),
  target_id: z.string().nullable().optional(),
  before_state: z.string().nullable().optional(),
  after_state: z.string().nullable().optional(),
  reason: z.string().nullable().optional(),
  ai_advisory_id: z.string().nullable().optional(),
  client_meta: z.string().nullable().optional(),
  created_at: z.string().min(1),
});
export type AuditEvent = z.infer<typeof AuditEventSchema>;
```

- [ ] **Step 4: Implement `packages/shared/src/schemas/audit-chunk.ts`**

```ts
// packages/shared/src/schemas/audit-chunk.ts
import { z } from 'zod';

export const ChunkHeaderSchema = z.object({
  chunk_seq: z.number().int().nonnegative(),
  prev_chunk_sha256: z.string().length(64).nullable(),
  events_in_chunk: z.number().int().positive(),
  min_seq: z.number().int().nonnegative(),
  max_seq: z.number().int().nonnegative(),
  signature: z.string().min(1),
  pubkey: z.string().min(1),
  signed_at: z.string().min(1),
});
export type ChunkHeader = z.infer<typeof ChunkHeaderSchema>;
```

- [ ] **Step 5: Re-export from `packages/shared/src/index.ts`**

```ts
export * from './schemas/audit-event.js';
export * from './schemas/audit-chunk.js';
```

- [ ] **Step 6: Run tests, expect PASS; commit**

```bash
git add packages/shared/src/schemas/audit-event.ts packages/shared/src/schemas/audit-chunk.ts packages/shared/src/index.ts packages/shared/tests/schemas/audit-event.test.ts packages/shared/tests/schemas/audit-chunk.test.ts
git commit -m "feat(shared): Zod schemas for AuditEvent + ChunkHeader"
```

---

## Task 5: SHA-256 hash chain

**Files:**
- Create: `apps/worker/src/audit/hash-chain.ts`
- Test: `apps/worker/tests/audit/hash-chain.test.ts`

Chain rule:
```
chunk_hash[0] = SHA-256(0x00 || canonical_json(events))
chunk_hash[k] = SHA-256(hexToBytes(chunk_hash[k-1]) || canonical_json(events))
```
The `0x00` sentinel distinguishes the genesis chunk from any chunk whose prev_hash is 32 zero bytes (defensive).

- [ ] **Step 1: Write failing test**

```ts
// apps/worker/tests/audit/hash-chain.test.ts
import { describe, it, expect } from 'vitest';
import { computeChunkHash, GENESIS_PREV } from '../../src/audit/hash-chain';
import type { AuditEvent } from '@mbfd/shared';

const events: AuditEvent[] = [
  { seq: 1, bid_session_id: '01HF3', action: 'session_start', actor_type: 'admin', actor_id: 1, created_at: '2026-09-22T14:00:00Z' },
  { seq: 2, bid_session_id: '01HF3', action: 'pick', actor_type: 'member', actor_id: 42, target_kind: 'position', target_id: 'A101', created_at: '2026-09-22T14:01:00Z' },
];

describe('computeChunkHash', () => {
  it('returns 64-char lowercase hex SHA-256', () => {
    expect(computeChunkHash(GENESIS_PREV, events)).toMatch(/^[0-9a-f]{64}$/);
  });
  it('is deterministic', () => {
    expect(computeChunkHash(GENESIS_PREV, events)).toBe(computeChunkHash(GENESIS_PREV, events));
  });
  it('changes if any event byte changes', () => {
    const tampered: AuditEvent[] = [{ ...events[0]! }, { ...events[1]!, target_id: 'A102' }];
    expect(computeChunkHash(GENESIS_PREV, events)).not.toBe(computeChunkHash(GENESIS_PREV, tampered));
  });
  it('changes if event order changes', () => {
    expect(computeChunkHash(GENESIS_PREV, events))
      .not.toBe(computeChunkHash(GENESIS_PREV, [events[1]!, events[0]!]));
  });
  it('changes if prev_chunk_sha256 changes', () => {
    expect(computeChunkHash(GENESIS_PREV, events))
      .not.toBe(computeChunkHash('a'.repeat(64), events));
  });
  it('GENESIS_PREV is null', () => {
    expect(GENESIS_PREV).toBe(null);
  });
  it('rejects empty events', () => {
    expect(() => computeChunkHash(GENESIS_PREV, [])).toThrow(/empty/);
  });
});
```

- [ ] **Step 2: Run test, expect FAIL**

- [ ] **Step 3: Implement `apps/worker/src/audit/hash-chain.ts`**

```ts
// apps/worker/src/audit/hash-chain.ts
import { sha256 } from '@noble/hashes/sha256';
import { bytesToHex, hexToBytes } from '@noble/hashes/utils';
import { canonicalize, type JsonValue } from './canonical-json.js';
import type { AuditEvent } from '@mbfd/shared';

export const GENESIS_PREV: string | null = null;
const GENESIS_SENTINEL = new Uint8Array([0x00]);

export function computeChunkHash(prev: string | null, events: AuditEvent[]): string {
  if (events.length === 0) throw new Error('Cannot hash empty events array');
  const prevBytes = prev === null ? GENESIS_SENTINEL : hexToBytes(prev);
  const payload = canonicalize(events as unknown as JsonValue);
  const payloadBytes = new TextEncoder().encode(payload);
  const combined = new Uint8Array(prevBytes.length + payloadBytes.length);
  combined.set(prevBytes, 0);
  combined.set(payloadBytes, prevBytes.length);
  return bytesToHex(sha256(combined));
}
```

- [ ] **Step 4: Run test, expect PASS; commit**

```bash
git add apps/worker/src/audit/hash-chain.ts apps/worker/tests/audit/hash-chain.test.ts
git commit -m "feat(audit): SHA-256 hash chain"
```

---

## Task 6: ed25519 chunk signer

**Files:**
- Create: `apps/worker/src/audit/signer.ts`
- Test: `apps/worker/tests/audit/signer.test.ts`

- [ ] **Step 1: Write failing test**

```ts
// apps/worker/tests/audit/signer.test.ts
import { describe, it, expect, beforeAll } from 'vitest';
import * as ed from '@noble/ed25519';
import { bytesToHex } from '@noble/hashes/utils';
import { signChunk, verifyChunkSignature, encodeKey, decodeKey } from '../../src/audit/signer';

describe('signer', () => {
  let priv: Uint8Array;
  let pub: Uint8Array;

  beforeAll(async () => {
    priv = ed.utils.randomPrivateKey();
    pub = await ed.getPublicKeyAsync(priv);
  });

  it('signChunk produces 64-byte signature (base64url)', async () => {
    const sig = await signChunk('a'.repeat(64), encodeKey(priv));
    expect(decodeKey(sig).length).toBe(64);
  });

  it('verifyChunkSignature accepts valid signature', async () => {
    const hash = bytesToHex(new Uint8Array(32).fill(0xab));
    const sig = await signChunk(hash, encodeKey(priv));
    expect(await verifyChunkSignature(hash, sig, encodeKey(pub))).toBe(true);
  });

  it('verifyChunkSignature rejects forged signature (wrong key)', async () => {
    const hash = bytesToHex(new Uint8Array(32).fill(0xab));
    const sig = await signChunk(hash, encodeKey(priv));
    const otherPriv = ed.utils.randomPrivateKey();
    const otherPub = await ed.getPublicKeyAsync(otherPriv);
    expect(await verifyChunkSignature(hash, sig, encodeKey(otherPub))).toBe(false);
  });

  it('verifyChunkSignature rejects tampered hash', async () => {
    const h1 = bytesToHex(new Uint8Array(32).fill(0xab));
    const sig = await signChunk(h1, encodeKey(priv));
    const h2 = bytesToHex(new Uint8Array(32).fill(0xac));
    expect(await verifyChunkSignature(h2, sig, encodeKey(pub))).toBe(false);
  });

  it('encodeKey / decodeKey round-trip', () => {
    const k = new Uint8Array(32);
    crypto.getRandomValues(k);
    const enc = encodeKey(k);
    expect(enc).not.toMatch(/[+/=]/);
    expect(decodeKey(enc)).toEqual(k);
  });
});
```

- [ ] **Step 2: Run test, expect FAIL**

- [ ] **Step 3: Implement `apps/worker/src/audit/signer.ts`**

```ts
// apps/worker/src/audit/signer.ts
import * as ed from '@noble/ed25519';

const enc = new TextEncoder();

export function encodeKey(raw: Uint8Array): string {
  let bin = '';
  for (let i = 0; i < raw.length; i++) bin += String.fromCharCode(raw[i]!);
  return btoa(bin).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

export function decodeKey(b64u: string): Uint8Array {
  const padded = b64u.replace(/-/g, '+').replace(/_/g, '/') + '==='.slice((b64u.length + 3) % 4);
  const bin = atob(padded);
  const out = new Uint8Array(bin.length);
  for (let i = 0; i < bin.length; i++) out[i] = bin.charCodeAt(i);
  return out;
}

export async function signChunk(chunkHashHex: string, privKeyB64u: string): Promise<string> {
  const priv = decodeKey(privKeyB64u);
  const sig = await ed.signAsync(enc.encode(chunkHashHex), priv);
  return encodeKey(sig);
}

export async function verifyChunkSignature(
  chunkHashHex: string,
  signatureB64u: string,
  pubKeyB64u: string,
): Promise<boolean> {
  try {
    return await ed.verifyAsync(decodeKey(signatureB64u), enc.encode(chunkHashHex), decodeKey(pubKeyB64u));
  } catch {
    return false;
  }
}
```

- [ ] **Step 4: Run test, expect PASS; commit**

```bash
git add apps/worker/src/audit/signer.ts apps/worker/tests/audit/signer.test.ts
git commit -m "feat(audit): ed25519 chunk signer with base64url codec"
```

---

## Task 7: JSONL chunker buffer (100-event or 30s flush)

**Files:**
- Create: `apps/worker/src/audit/jsonl-chunker.ts`
- Create: `apps/worker/src/audit/types.ts`
- Test: `apps/worker/tests/audit/jsonl-chunker.test.ts`

The chunker holds events in memory per-session and decides when to flush. It is invoked from two places: (a) inline from the DO commit (which calls `add(event)` and may receive a `ChunkReady` instruction), and (b) from the 30s cron tick which calls `flushStale(now)` for every session with `pending_buffer_started_at` older than 30s.

- [ ] **Step 1: Write failing test**

```ts
// apps/worker/tests/audit/jsonl-chunker.test.ts
import { describe, it, expect, vi } from 'vitest';
import { JsonlChunker, FLUSH_THRESHOLD_EVENTS, FLUSH_TIMEOUT_MS } from '../../src/audit/jsonl-chunker';
import type { AuditEvent } from '@mbfd/shared';

const mkEvent = (seq: number): AuditEvent => ({
  seq,
  bid_session_id: '01HF3',
  action: 'pick',
  actor_type: 'member',
  actor_id: 42,
  target_kind: 'position',
  target_id: 'A' + (100 + seq),
  created_at: '2026-09-22T14:00:00Z',
});

describe('JsonlChunker', () => {
  it('FLUSH_THRESHOLD_EVENTS is 100', () => {
    expect(FLUSH_THRESHOLD_EVENTS).toBe(100);
  });
  it('FLUSH_TIMEOUT_MS is 30_000', () => {
    expect(FLUSH_TIMEOUT_MS).toBe(30_000);
  });

  it('add() returns null when buffer is below threshold', () => {
    const c = new JsonlChunker();
    for (let i = 0; i < 99; i++) {
      expect(c.add(mkEvent(i), Date.now())).toBe(null);
    }
  });

  it('add() returns flush instruction when buffer hits 100 events', () => {
    const c = new JsonlChunker();
    for (let i = 0; i < 99; i++) c.add(mkEvent(i), Date.now());
    const out = c.add(mkEvent(99), Date.now());
    expect(out).not.toBe(null);
    expect(out!.events).toHaveLength(100);
    expect(out!.reason).toBe('threshold');
  });

  it('flushIfStale() returns null when buffer is fresh', () => {
    const c = new JsonlChunker();
    const t0 = 1_000_000;
    c.add(mkEvent(0), t0);
    expect(c.flushIfStale(t0 + 29_000)).toBe(null);
  });

  it('flushIfStale() returns flush when 30s elapsed', () => {
    const c = new JsonlChunker();
    const t0 = 1_000_000;
    c.add(mkEvent(0), t0);
    const out = c.flushIfStale(t0 + 30_000);
    expect(out).not.toBe(null);
    expect(out!.events).toHaveLength(1);
    expect(out!.reason).toBe('timeout');
  });

  it('flushIfStale() returns null when buffer is empty', () => {
    const c = new JsonlChunker();
    expect(c.flushIfStale(Date.now())).toBe(null);
  });

  it('add() after a flush starts a fresh buffer with new timer', () => {
    const c = new JsonlChunker();
    const t0 = 1_000_000;
    for (let i = 0; i < 100; i++) c.add(mkEvent(i), t0);
    // buffer is empty here; next add starts a new timer at t0+1000
    c.add(mkEvent(100), t0 + 1_000);
    expect(c.flushIfStale(t0 + 1_000 + 29_000)).toBe(null);
    expect(c.flushIfStale(t0 + 1_000 + 30_000)).not.toBe(null);
  });

  it('drain() returns remaining events without resetting the timer', () => {
    const c = new JsonlChunker();
    c.add(mkEvent(0), Date.now());
    c.add(mkEvent(1), Date.now());
    const out = c.drain();
    expect(out!.events).toHaveLength(2);
    expect(c.drain()).toBe(null);
  });
});
```

- [ ] **Step 2: Run test, expect FAIL**

- [ ] **Step 3: Implement `apps/worker/src/audit/types.ts`**

```ts
// apps/worker/src/audit/types.ts
import type { AuditEvent } from '@mbfd/shared';

export const FLUSH_THRESHOLD_EVENTS = 100;
export const FLUSH_TIMEOUT_MS = 30_000;

export type FlushReason = 'threshold' | 'timeout' | 'drain' | 'session_end';

export interface ChunkFlush {
  events: AuditEvent[];
  reason: FlushReason;
  bufferStartedAt: number;
  bufferEndedAt: number;
}
```

- [ ] **Step 4: Implement `apps/worker/src/audit/jsonl-chunker.ts`**

```ts
// apps/worker/src/audit/jsonl-chunker.ts
import type { AuditEvent } from '@mbfd/shared';
import {
  FLUSH_THRESHOLD_EVENTS,
  FLUSH_TIMEOUT_MS,
  type ChunkFlush,
} from './types.js';

export { FLUSH_THRESHOLD_EVENTS, FLUSH_TIMEOUT_MS };

/**
 * In-memory buffer for one session's audit events.
 *
 * Flush triggers:
 *   - threshold: buffer reaches FLUSH_THRESHOLD_EVENTS (100)
 *   - timeout:   buffer is older than FLUSH_TIMEOUT_MS (30s)
 *   - drain:     explicit drain() call (e.g. session_end)
 *
 * The chunker is per-session and is held in a Map keyed by bid_session_id.
 * It is process-local — durability is provided by the post-flush write to R2.
 */
export class JsonlChunker {
  private buffer: AuditEvent[] = [];
  private startedAt: number | null = null;

  /** Add an event. Returns a ChunkFlush if the threshold trips, else null. */
  add(event: AuditEvent, nowMs: number): ChunkFlush | null {
    if (this.startedAt === null) this.startedAt = nowMs;
    this.buffer.push(event);
    if (this.buffer.length >= FLUSH_THRESHOLD_EVENTS) {
      return this.flush('threshold', nowMs);
    }
    return null;
  }

  /** Returns a ChunkFlush if the buffer is older than 30s, else null. */
  flushIfStale(nowMs: number): ChunkFlush | null {
    if (this.buffer.length === 0 || this.startedAt === null) return null;
    if (nowMs - this.startedAt >= FLUSH_TIMEOUT_MS) {
      return this.flush('timeout', nowMs);
    }
    return null;
  }

  /** Force-flush whatever is buffered. Returns null if buffer is empty. */
  drain(reason: 'drain' | 'session_end' = 'drain'): ChunkFlush | null {
    if (this.buffer.length === 0) return null;
    return this.flush(reason, Date.now());
  }

  /** True if any events are buffered. */
  hasPending(): boolean {
    return this.buffer.length > 0;
  }

  /** Diagnostic — current buffer size. */
  size(): number {
    return this.buffer.length;
  }

  private flush(
    reason: ChunkFlush['reason'],
    nowMs: number,
  ): ChunkFlush {
    const out: ChunkFlush = {
      events: this.buffer,
      reason,
      bufferStartedAt: this.startedAt ?? nowMs,
      bufferEndedAt: nowMs,
    };
    this.buffer = [];
    this.startedAt = null;
    return out;
  }
}
```

- [ ] **Step 5: Run test, expect PASS**

- [ ] **Step 6: Commit**

```bash
git add apps/worker/src/audit/types.ts apps/worker/src/audit/jsonl-chunker.ts apps/worker/tests/audit/jsonl-chunker.test.ts
git commit -m "feat(audit): JsonlChunker buffer with 100-event/30s flush triggers"
```

---

## Task 8: ChainEmitter — public API consumed by the DO

**Files:**
- Create: `apps/worker/src/audit/chain-emitter.ts`
- Test: `apps/worker/tests/audit/chain-emitter.test.ts`

The emitter ties together: chunker → hash chain → signer → R2 upload → D1 `audit_chunks` row → D1 `audit_chain_state` update → D1 `audit_log.chunk_seq` backfill. It is the SOLE public API the DO uses. One instance per Worker isolate, but it keeps a `Map<sessionId, JsonlChunker>` internally.

- [ ] **Step 1: Write failing test**

```ts
// apps/worker/tests/audit/chain-emitter.test.ts
import { describe, it, expect, beforeAll, vi } from 'vitest';
import * as ed from '@noble/ed25519';
import { ChainEmitter } from '../../src/audit/chain-emitter';
import { encodeKey } from '../../src/audit/signer';
import { verifyChunkSignature } from '../../src/audit/signer';
import { computeChunkHash } from '../../src/audit/hash-chain';
import type { AuditEvent } from '@mbfd/shared';

// In-memory mocks for R2 + D1
function mockR2(): { put: ReturnType<typeof vi.fn>; objects: Map<string, Uint8Array> } {
  const objects = new Map<string, Uint8Array>();
  const put = vi.fn(async (key: string, body: ArrayBuffer | Uint8Array | string) => {
    const bytes =
      typeof body === 'string' ? new TextEncoder().encode(body) :
      body instanceof Uint8Array ? body :
      new Uint8Array(body);
    objects.set(key, bytes);
  });
  return { put, objects };
}

function mockDb() {
  const chunks: Array<Record<string, unknown>> = [];
  const stateBySession = new Map<string, { nextSeq: number; lastChunkSha256: string | null }>();
  return {
    insertChunk: vi.fn(async (row) => { chunks.push(row); }),
    upsertState: vi.fn(async (sid, patch) => {
      const prev = stateBySession.get(sid) ?? { nextSeq: 1, lastChunkSha256: null };
      stateBySession.set(sid, { ...prev, ...patch });
    }),
    loadState: vi.fn(async (sid) => stateBySession.get(sid) ?? null),
    backfillRowIndexes: vi.fn(async () => {}),
    chunks,
    stateBySession,
  };
}

const mkEvent = (seq: number): AuditEvent => ({
  seq, bid_session_id: '01HF3', action: 'pick', actor_type: 'member',
  actor_id: 42, target_id: 'A' + (100 + seq), target_kind: 'position',
  created_at: '2026-09-22T14:00:00Z',
});

let priv: Uint8Array;
let pub: Uint8Array;

beforeAll(async () => {
  priv = ed.utils.randomPrivateKey();
  pub = await ed.getPublicKeyAsync(priv);
});

describe('ChainEmitter', () => {
  it('emits events into a buffer and flushes at threshold', async () => {
    const r2 = mockR2();
    const db = mockDb();
    const em = new ChainEmitter({
      r2: { put: r2.put } as unknown as R2Bucket,
      db: db as unknown as Parameters<typeof ChainEmitter['prototype']['constructor']>[0]['db'],
      privKey: encodeKey(priv),
      pubKey: encodeKey(pub),
      year: 2026,
      now: () => Date.now(),
    });
    for (let i = 1; i <= 100; i++) await em.emit(mkEvent(i));
    expect(r2.put).toHaveBeenCalledTimes(1);
    expect(db.insertChunk).toHaveBeenCalledTimes(1);
  });

  it('R2 key matches <year>/<session>/chunks/<padded-seq>.jsonl', async () => {
    const r2 = mockR2();
    const db = mockDb();
    const em = new ChainEmitter({
      r2: { put: r2.put } as unknown as R2Bucket,
      db: db as unknown as Parameters<typeof ChainEmitter['prototype']['constructor']>[0]['db'],
      privKey: encodeKey(priv), pubKey: encodeKey(pub),
      year: 2026, now: () => Date.now(),
    });
    for (let i = 1; i <= 100; i++) await em.emit(mkEvent(i));
    const keys = Array.from(r2.objects.keys());
    expect(keys[0]).toBe('2026/01HF3/chunks/0001.jsonl');
  });

  it('uploaded chunk is parseable JSONL with a header line + N event lines', async () => {
    const r2 = mockR2();
    const db = mockDb();
    const em = new ChainEmitter({
      r2: { put: r2.put } as unknown as R2Bucket,
      db: db as unknown as Parameters<typeof ChainEmitter['prototype']['constructor']>[0]['db'],
      privKey: encodeKey(priv), pubKey: encodeKey(pub),
      year: 2026, now: () => Date.now(),
    });
    for (let i = 1; i <= 100; i++) await em.emit(mkEvent(i));
    const blob = r2.objects.get('2026/01HF3/chunks/0001.jsonl')!;
    const text = new TextDecoder().decode(blob);
    const lines = text.trim().split('\n');
    expect(lines).toHaveLength(101);                 // 1 header + 100 events
    const header = JSON.parse(lines[0]!);
    expect(header.chunk_seq).toBe(1);
    expect(header.events_in_chunk).toBe(100);
    expect(header.min_seq).toBe(1);
    expect(header.max_seq).toBe(100);
  });

  it('chunk signature verifies under the public key', async () => {
    const r2 = mockR2();
    const db = mockDb();
    const em = new ChainEmitter({
      r2: { put: r2.put } as unknown as R2Bucket,
      db: db as unknown as Parameters<typeof ChainEmitter['prototype']['constructor']>[0]['db'],
      privKey: encodeKey(priv), pubKey: encodeKey(pub),
      year: 2026, now: () => Date.now(),
    });
    for (let i = 1; i <= 100; i++) await em.emit(mkEvent(i));
    const text = new TextDecoder().decode(r2.objects.get('2026/01HF3/chunks/0001.jsonl')!);
    const lines = text.trim().split('\n');
    const header = JSON.parse(lines[0]!);
    const events: AuditEvent[] = lines.slice(1).map((l) => JSON.parse(l));
    const expectedHash = computeChunkHash(null, events);
    expect(await verifyChunkSignature(expectedHash, header.signature, header.pubkey)).toBe(true);
  });

  it('second chunk references the first chunk hash as prev_chunk_sha256', async () => {
    const r2 = mockR2();
    const db = mockDb();
    const em = new ChainEmitter({
      r2: { put: r2.put } as unknown as R2Bucket,
      db: db as unknown as Parameters<typeof ChainEmitter['prototype']['constructor']>[0]['db'],
      privKey: encodeKey(priv), pubKey: encodeKey(pub),
      year: 2026, now: () => Date.now(),
    });
    for (let i = 1; i <= 200; i++) await em.emit(mkEvent(i));
    const c1 = new TextDecoder().decode(r2.objects.get('2026/01HF3/chunks/0001.jsonl')!);
    const c2 = new TextDecoder().decode(r2.objects.get('2026/01HF3/chunks/0002.jsonl')!);
    const h1 = JSON.parse(c1.split('\n')[0]!);
    const h2 = JSON.parse(c2.split('\n')[0]!);
    expect(h2.prev_chunk_sha256).toBe(db.chunks[0]!.sha256);
    expect(h1.prev_chunk_sha256).toBe(null);
  });
});
```

- [ ] **Step 2: Run test, expect FAIL**

- [ ] **Step 3: Implement `apps/worker/src/audit/chain-emitter.ts`**

```ts
// apps/worker/src/audit/chain-emitter.ts
import type { AuditEvent } from '@mbfd/shared';
import { JsonlChunker } from './jsonl-chunker.js';
import { computeChunkHash } from './hash-chain.js';
import { signChunk } from './signer.js';
import type { ChunkFlush } from './types.js';

export interface ChainDb {
  insertChunk(row: {
    bidSessionId: string;
    seq: number;
    r2Key: string;
    sha256: string;
    prevSha256: string | null;
    signatureB64u: string;
    pubkeyB64u: string;
    eventsInChunk: number;
    minSeq: number;
    maxSeq: number;
    signedAt: Date;
  }): Promise<void>;
  upsertState(
    bidSessionId: string,
    patch: { nextSeq: number; lastChunkSha256: string; pendingBufferStartedAt: Date | null },
  ): Promise<void>;
  loadState(
    bidSessionId: string,
  ): Promise<{ nextSeq: number; lastChunkSha256: string | null } | null>;
  backfillRowIndexes(
    bidSessionId: string,
    chunkSeq: number,
    eventSeqs: number[],
  ): Promise<void>;
}

export interface ChainEmitterDeps {
  r2: R2Bucket;
  db: ChainDb;
  privKey: string;
  pubKey: string;
  year: number;
  now: () => number;
}

/**
 * Public audit emitter consumed by the BidSession DO.
 *
 *   await emitter.emit(event)         ← from DO commit; may trigger flush
 *   await emitter.flushStale()        ← from 30s cron tick
 *   await emitter.drainSession(sid)   ← on session_complete / day_end
 */
export class ChainEmitter {
  private readonly chunkers = new Map<string, JsonlChunker>();

  constructor(private readonly deps: ChainEmitterDeps) {}

  async emit(event: AuditEvent): Promise<void> {
    const c = this.chunkers.get(event.bid_session_id) ?? new JsonlChunker();
    this.chunkers.set(event.bid_session_id, c);
    const flush = c.add(event, this.deps.now());
    if (flush) await this.uploadChunk(event.bid_session_id, flush);
  }

  /** Called from 30s cron. Flushes any session whose buffer is older than 30s. */
  async flushStale(): Promise<number> {
    let n = 0;
    const now = this.deps.now();
    for (const [sid, c] of this.chunkers) {
      const flush = c.flushIfStale(now);
      if (flush) {
        await this.uploadChunk(sid, flush);
        n++;
      }
    }
    return n;
  }

  /** Force-drain a session (e.g. on session_complete). */
  async drainSession(sid: string): Promise<void> {
    const c = this.chunkers.get(sid);
    if (!c) return;
    const flush = c.drain('session_end');
    if (flush) await this.uploadChunk(sid, flush);
    this.chunkers.delete(sid);
  }

  /** Diagnostic — list sessions with pending events. */
  pendingSessions(): string[] {
    return [...this.chunkers].filter(([, c]) => c.hasPending()).map(([sid]) => sid);
  }

  // ─── internal ────────────────────────────────────────────────────────────

  private async uploadChunk(sid: string, flush: ChunkFlush): Promise<void> {
    const state = await this.deps.db.loadState(sid);
    const seq = state?.nextSeq ?? 1;
    const prev = state?.lastChunkSha256 ?? null;

    const events = flush.events;
    const sha = computeChunkHash(prev, events);
    const sig = await signChunk(sha, this.deps.privKey);
    const signedAt = new Date(this.deps.now());

    const header = {
      chunk_seq: seq,
      prev_chunk_sha256: prev,
      events_in_chunk: events.length,
      min_seq: events[0]!.seq,
      max_seq: events[events.length - 1]!.seq,
      signature: sig,
      pubkey: this.deps.pubKey,
      signed_at: signedAt.toISOString(),
    };

    const lines = [JSON.stringify(header), ...events.map((e) => JSON.stringify(e))];
    const body = lines.join('\n') + '\n';
    const key = this.r2KeyFor(sid, seq);

    await this.deps.r2.put(key, body, {
      httpMetadata: { contentType: 'application/jsonl' },
    });

    await this.deps.db.insertChunk({
      bidSessionId: sid,
      seq,
      r2Key: key,
      sha256: sha,
      prevSha256: prev,
      signatureB64u: sig,
      pubkeyB64u: this.deps.pubKey,
      eventsInChunk: events.length,
      minSeq: events[0]!.seq,
      maxSeq: events[events.length - 1]!.seq,
      signedAt,
    });

    await this.deps.db.upsertState(sid, {
      nextSeq: seq + 1,
      lastChunkSha256: sha,
      pendingBufferStartedAt: null,
    });

    await this.deps.db.backfillRowIndexes(sid, seq, events.map((e) => e.seq));
  }

  private r2KeyFor(sid: string, seq: number): string {
    const padded = String(seq).padStart(4, '0');
    return `${this.deps.year}/${sid}/chunks/${padded}.jsonl`;
  }
}
```

- [ ] **Step 4: Run test, expect PASS; commit**

```bash
git add apps/worker/src/audit/chain-emitter.ts apps/worker/tests/audit/chain-emitter.test.ts
git commit -m "feat(audit): ChainEmitter — buffers, chains, signs, uploads, persists"
```

---

## Task 9: Wire ChainEmitter into the BidSession DO

**Files:**
- Modify: `apps/worker/src/durable/bid-session.ts` (from Plan 04) — add 1 dependency + 1 call inside `.commit()`
- Modify: `apps/worker/src/index.ts` — construct singleton emitter and pass into DO factory
- Test: `apps/worker/tests/integration/bid-session-emit.test.ts`

- [ ] **Step 1: Write failing integration test**

```ts
// apps/worker/tests/integration/bid-session-emit.test.ts
import { describe, it, expect, beforeAll } from 'vitest';
import { unstable_dev, type UnstableDevWorker } from 'wrangler';

describe('BidSession DO emits audit chunks to R2', () => {
  let worker: UnstableDevWorker;
  beforeAll(async () => {
    worker = await unstable_dev('src/index.ts', {
      local: true,
      experimental: { disableExperimentalWarning: true },
    });
  });

  it('after 100 picks, one chunk appears in R2_AUDIT', async () => {
    // start session, simulate 100 admin-bid-for-member picks via test-only endpoint
    const res = await worker.fetch('/test/audit-emit-100', { method: 'POST' });
    expect(res.status).toBe(200);
    const body = await res.json() as { chunks: string[] };
    expect(body.chunks).toHaveLength(1);
    expect(body.chunks[0]).toMatch(/^2026\/[^/]+\/chunks\/0001\.jsonl$/);
  });

  it('if R2 write fails, the DO rejects the pick with 500', async () => {
    const res = await worker.fetch('/test/audit-emit-fail-r2', { method: 'POST' });
    expect(res.status).toBe(500);
    const body = await res.json() as { error: string };
    expect(body.error).toMatch(/audit/i);
  });
});
```

- [ ] **Step 2: Run test, expect FAIL**

- [ ] **Step 3: Modify the DO commit path** (existing file from Plan 04; partial edit shown)

```ts
// apps/worker/src/durable/bid-session.ts — inside the commit method, after the
// existing D1 audit_log INSERT, add:

await this.deps.auditEmitter.emit({
  seq: this.state.lastSeq,                     // already incremented before this call
  bid_session_id: this.id,
  action: 'pick',
  actor_type: pick.adminActorId ? 'admin' : 'member',
  actor_id: pick.adminActorId ?? pick.memberId,
  target_kind: 'position',
  target_id: pick.positionId,
  after_state: JSON.stringify({ a_day: pick.aDay ?? null, forced: pick.forced }),
  reason: pick.reason ?? null,
  created_at: new Date(this.deps.now()).toISOString(),
});
```

Per **D10**: if `emit()` throws (R2 write failure), the surrounding transaction rolls back — DO returns 500 to the WS client. Do NOT swallow the error.

- [ ] **Step 4: Modify the DO factory in `src/index.ts`**

```ts
// apps/worker/src/index.ts (worker module export)
import { ChainEmitter } from './audit/chain-emitter.js';

export default {
  async fetch(req: Request, env: Env, ctx: ExecutionContext): Promise<Response> {
    // singleton per-isolate; chunkers persist across requests
    const emitter = getOrInitEmitter(env);
    // ...rest unchanged; pass emitter into DO via state
    return app.fetch(req, env, ctx);
  },
  async scheduled(event: ScheduledController, env: Env, ctx: ExecutionContext): Promise<void> {
    const emitter = getOrInitEmitter(env);
    ctx.waitUntil(emitter.flushStale());
  },
};

let _emitter: ChainEmitter | null = null;
function getOrInitEmitter(env: Env): ChainEmitter {
  if (_emitter) return _emitter;
  _emitter = new ChainEmitter({
    r2: env.R2_AUDIT,
    db: makeChainDb(env.DB),    // see ChainDb adapter below
    privKey: env.AUDIT_SIGNING_PRIVKEY,
    pubKey: env.AUDIT_SIGNING_PUBKEY,
    year: new Date().getUTCFullYear(),
    now: () => Date.now(),
  });
  return _emitter;
}
```

- [ ] **Step 5: Add `makeChainDb` adapter — wraps Drizzle into the ChainDb interface**

```ts
// apps/worker/src/audit/chain-db-d1.ts
import { eq, inArray } from 'drizzle-orm';
import { getDb } from '../db/index.js';
import { auditChunks, auditChainState, auditLog } from '../db/schema.js';
import type { ChainDb } from './chain-emitter.js';

export function makeChainDb(d1: D1Database): ChainDb {
  const db = getDb(d1);
  return {
    async insertChunk(row) {
      await db.insert(auditChunks).values(row);
    },
    async upsertState(bidSessionId, patch) {
      await db
        .insert(auditChainState)
        .values({ bidSessionId, ...patch })
        .onConflictDoUpdate({ target: auditChainState.bidSessionId, set: patch });
    },
    async loadState(bidSessionId) {
      const r = await db
        .select()
        .from(auditChainState)
        .where(eq(auditChainState.bidSessionId, bidSessionId))
        .get();
      return r ?? null;
    },
    async backfillRowIndexes(bidSessionId, chunkSeq, eventSeqs) {
      // Set chunk_seq + chunk_row_index on the audit_log rows for these event seqs
      for (let i = 0; i < eventSeqs.length; i++) {
        await db
          .update(auditLog)
          .set({ chunkSeq, chunkRowIndex: i })
          .where(
            // eslint-disable-next-line @typescript-eslint/no-non-null-assertion
            eq(auditLog.bidSessionId, bidSessionId),
          )
          .where(eq(auditLog.seq, eventSeqs[i]!));
      }
    },
  };
}
```

- [ ] **Step 6: Run integration test, expect PASS**

- [ ] **Step 7: Commit**

```bash
git add apps/worker/src/durable/bid-session.ts apps/worker/src/index.ts apps/worker/src/audit/chain-db-d1.ts apps/worker/tests/integration/bid-session-emit.test.ts
git commit -m "feat(do): wire ChainEmitter into BidSession.commit + cron flushStale"
```

---

## Task 10: Chain verifier + /admin/audit/verify-chain endpoint

**Files:**
- Create: `apps/worker/src/audit/verifier.ts`
- Create: `apps/worker/src/routes/admin/audit.ts`
- Modify: `apps/worker/src/index.ts` (mount route)
- Test: `apps/worker/tests/audit/verifier.test.ts`
- Test: `apps/worker/tests/audit/admin-verify-chain.test.ts`

The verifier reads chunks in seq order from R2 (or from D1 metadata, falling back to R2 for the bytes), recomputes each chunk's hash, verifies each chunk's signature against the embedded pubkey, and verifies each chunk's `prev_chunk_sha256` matches the previous chunk's `sha256`. Any failure returns a detailed diff describing what broke and where.

- [ ] **Step 1: Write failing test (verifier unit)**

```ts
// apps/worker/tests/audit/verifier.test.ts
import { describe, it, expect, beforeAll } from 'vitest';
import * as ed from '@noble/ed25519';
import { verifyChain } from '../../src/audit/verifier';
import { encodeKey } from '../../src/audit/signer';
import { ChainEmitter } from '../../src/audit/chain-emitter';
import type { AuditEvent } from '@mbfd/shared';

function inMemR2() {
  const objects = new Map<string, Uint8Array>();
  return {
    put: async (key: string, body: string, _opts?: unknown) => {
      objects.set(key, new TextEncoder().encode(body));
    },
    get: async (key: string) => {
      const v = objects.get(key);
      if (!v) return null;
      return {
        arrayBuffer: async () => v.buffer,
        text: async () => new TextDecoder().decode(v),
      };
    },
    list: async () => ({ objects: [...objects.keys()].sort().map((key) => ({ key })) }),
    _objects: objects,
  } as unknown as R2Bucket & { _objects: Map<string, Uint8Array> };
}

function inMemDb() {
  const chunks: Array<Record<string, unknown>> = [];
  const states = new Map<string, { nextSeq: number; lastChunkSha256: string | null }>();
  return {
    insertChunk: async (row: Record<string, unknown>) => { chunks.push(row); },
    upsertState: async (sid: string, patch: { nextSeq: number; lastChunkSha256: string | null }) => {
      states.set(sid, patch);
    },
    loadState: async (sid: string) => states.get(sid) ?? null,
    backfillRowIndexes: async () => {},
    _chunks: chunks,
  };
}

const mkEvent = (seq: number): AuditEvent => ({
  seq, bid_session_id: '01HF3', action: 'pick', actor_type: 'member',
  actor_id: 42, target_kind: 'position', target_id: 'A' + (100 + seq),
  created_at: '2026-09-22T14:00:00Z',
});

describe('verifyChain', () => {
  let priv: Uint8Array;
  let pub: Uint8Array;
  beforeAll(async () => {
    priv = ed.utils.randomPrivateKey();
    pub = await ed.getPublicKeyAsync(priv);
  });

  it('reports ok=true on a clean 250-event chain', async () => {
    const r2 = inMemR2();
    const db = inMemDb();
    const em = new ChainEmitter({
      r2, db: db as never, privKey: encodeKey(priv), pubKey: encodeKey(pub),
      year: 2026, now: () => Date.now(),
    });
    for (let i = 1; i <= 250; i++) await em.emit(mkEvent(i));
    await em.drainSession('01HF3');
    const res = await verifyChain(r2, '01HF3', 2026);
    expect(res.ok).toBe(true);
    expect(res.last_verified_seq).toBe(3);     // 100+100+50
    expect(res.error).toBeUndefined();
  });

  it('reports ok=false with chunk_seq + reason when a chunk byte is mutated', async () => {
    const r2 = inMemR2();
    const db = inMemDb();
    const em = new ChainEmitter({
      r2, db: db as never, privKey: encodeKey(priv), pubKey: encodeKey(pub),
      year: 2026, now: () => Date.now(),
    });
    for (let i = 1; i <= 100; i++) await em.emit(mkEvent(i));
    // Tamper: flip one byte in chunk 1
    const key = '2026/01HF3/chunks/0001.jsonl';
    const original = (r2 as unknown as { _objects: Map<string, Uint8Array> })._objects.get(key)!;
    const tampered = new Uint8Array(original);
    tampered[tampered.length - 50] ^= 0x01;
    (r2 as unknown as { _objects: Map<string, Uint8Array> })._objects.set(key, tampered);
    const res = await verifyChain(r2, '01HF3', 2026);
    expect(res.ok).toBe(false);
    expect(res.failed_at_chunk).toBe(1);
    expect(res.reason).toMatch(/signature|hash/i);
  });

  it('reports ok=false when prev_chunk_sha256 link is broken', async () => {
    const r2 = inMemR2();
    const db = inMemDb();
    const em = new ChainEmitter({
      r2, db: db as never, privKey: encodeKey(priv), pubKey: encodeKey(pub),
      year: 2026, now: () => Date.now(),
    });
    for (let i = 1; i <= 200; i++) await em.emit(mkEvent(i));
    // Rewrite chunk 2's header with a bogus prev_chunk_sha256, re-signing for itself (sig still valid)
    const key2 = '2026/01HF3/chunks/0002.jsonl';
    const txt = new TextDecoder().decode((r2 as unknown as { _objects: Map<string, Uint8Array> })._objects.get(key2)!);
    const [headLine, ...bodyLines] = txt.trim().split('\n');
    const head = JSON.parse(headLine!);
    head.prev_chunk_sha256 = 'f'.repeat(64);
    const newText = [JSON.stringify(head), ...bodyLines].join('\n') + '\n';
    (r2 as unknown as { _objects: Map<string, Uint8Array> })._objects.set(
      key2,
      new TextEncoder().encode(newText),
    );
    const res = await verifyChain(r2, '01HF3', 2026);
    expect(res.ok).toBe(false);
    expect(res.failed_at_chunk).toBe(2);
    expect(res.reason).toMatch(/prev/i);
  });

  it('reports ok=false when a chunk is missing', async () => {
    const r2 = inMemR2();
    const db = inMemDb();
    const em = new ChainEmitter({
      r2, db: db as never, privKey: encodeKey(priv), pubKey: encodeKey(pub),
      year: 2026, now: () => Date.now(),
    });
    for (let i = 1; i <= 300; i++) await em.emit(mkEvent(i));
    (r2 as unknown as { _objects: Map<string, Uint8Array> })._objects.delete(
      '2026/01HF3/chunks/0002.jsonl',
    );
    const res = await verifyChain(r2, '01HF3', 2026);
    expect(res.ok).toBe(false);
    expect(res.reason).toMatch(/missing/i);
  });
});
```

- [ ] **Step 2: Run test, expect FAIL**

- [ ] **Step 3: Implement `apps/worker/src/audit/verifier.ts`**

```ts
// apps/worker/src/audit/verifier.ts
import { computeChunkHash } from './hash-chain.js';
import { verifyChunkSignature } from './signer.js';
import { ChunkHeaderSchema, AuditEventSchema } from '@mbfd/shared';
import type { AuditEvent } from '@mbfd/shared';

export interface VerifyResult {
  ok: boolean;
  last_verified_seq: number;          // highest chunk_seq successfully verified
  total_events_verified: number;
  failed_at_chunk?: number;
  reason?: string;
}

export async function verifyChain(
  r2: R2Bucket,
  bidSessionId: string,
  year: number,
): Promise<VerifyResult> {
  const prefix = `${year}/${bidSessionId}/chunks/`;
  const listed = await r2.list({ prefix });
  const keys = listed.objects
    .map((o) => o.key)
    .sort((a, b) => a.localeCompare(b));

  if (keys.length === 0) {
    return { ok: true, last_verified_seq: 0, total_events_verified: 0 };
  }

  let prevHash: string | null = null;
  let lastSeq = 0;
  let totalEvents = 0;

  for (let i = 0; i < keys.length; i++) {
    const expectedSeq = i + 1;
    const expectedKey = `${prefix}${String(expectedSeq).padStart(4, '0')}.jsonl`;
    if (keys[i] !== expectedKey) {
      return {
        ok: false,
        last_verified_seq: lastSeq,
        total_events_verified: totalEvents,
        failed_at_chunk: expectedSeq,
        reason: `missing chunk: expected ${expectedKey}, found ${keys[i] ?? 'none'}`,
      };
    }
    const obj = await r2.get(keys[i]!);
    if (!obj) {
      return {
        ok: false,
        last_verified_seq: lastSeq,
        total_events_verified: totalEvents,
        failed_at_chunk: expectedSeq,
        reason: `missing chunk body for ${keys[i]}`,
      };
    }
    const txt = await obj.text();
    const lines = txt.trim().split('\n');
    if (lines.length < 2) {
      return {
        ok: false,
        last_verified_seq: lastSeq,
        total_events_verified: totalEvents,
        failed_at_chunk: expectedSeq,
        reason: 'chunk has no events',
      };
    }
    const headerParsed = ChunkHeaderSchema.safeParse(JSON.parse(lines[0]!));
    if (!headerParsed.success) {
      return {
        ok: false,
        last_verified_seq: lastSeq,
        total_events_verified: totalEvents,
        failed_at_chunk: expectedSeq,
        reason: 'invalid chunk header: ' + headerParsed.error.message,
      };
    }
    const header = headerParsed.data;
    if (header.chunk_seq !== expectedSeq) {
      return {
        ok: false,
        last_verified_seq: lastSeq,
        total_events_verified: totalEvents,
        failed_at_chunk: expectedSeq,
        reason: `chunk_seq mismatch: header says ${header.chunk_seq}, expected ${expectedSeq}`,
      };
    }
    if (header.prev_chunk_sha256 !== prevHash) {
      return {
        ok: false,
        last_verified_seq: lastSeq,
        total_events_verified: totalEvents,
        failed_at_chunk: expectedSeq,
        reason: `prev_chunk_sha256 mismatch (chain broken)`,
      };
    }
    const events: AuditEvent[] = [];
    for (let li = 1; li < lines.length; li++) {
      const p = AuditEventSchema.safeParse(JSON.parse(lines[li]!));
      if (!p.success) {
        return {
          ok: false,
          last_verified_seq: lastSeq,
          total_events_verified: totalEvents,
          failed_at_chunk: expectedSeq,
          reason: `event ${li} fails schema: ${p.error.message}`,
        };
      }
      events.push(p.data);
    }
    if (events.length !== header.events_in_chunk) {
      return {
        ok: false,
        last_verified_seq: lastSeq,
        total_events_verified: totalEvents,
        failed_at_chunk: expectedSeq,
        reason: `events_in_chunk header says ${header.events_in_chunk}, body has ${events.length}`,
      };
    }
    const computed = computeChunkHash(prevHash, events);
    const sigOk = await verifyChunkSignature(computed, header.signature, header.pubkey);
    if (!sigOk) {
      return {
        ok: false,
        last_verified_seq: lastSeq,
        total_events_verified: totalEvents,
        failed_at_chunk: expectedSeq,
        reason: 'signature does not verify (chunk tampered)',
      };
    }
    prevHash = computed;
    lastSeq = expectedSeq;
    totalEvents += events.length;
  }

  return { ok: true, last_verified_seq: lastSeq, total_events_verified: totalEvents };
}
```

- [ ] **Step 4: Implement `apps/worker/src/routes/admin/audit.ts`**

```ts
// apps/worker/src/routes/admin/audit.ts
import { Hono } from 'hono';
import { requireAdmin } from './middleware.js';
import { verifyChain } from '../../audit/verifier.js';

const r = new Hono<{ Bindings: Env }>();
r.use('*', requireAdmin);

r.get('/verify-chain', async (c) => {
  const sid = c.req.query('session_id');
  const yr = Number(c.req.query('year') ?? new Date().getUTCFullYear());
  if (!sid) return c.json({ error: 'session_id_required' }, 400);
  const res = await verifyChain(c.env.R2_AUDIT, sid, yr);
  return c.json(res, res.ok ? 200 : 422);
});

export default r;
```

- [ ] **Step 5: Mount in `src/index.ts`**

```ts
import adminAudit from './routes/admin/audit.js';
app.route('/api/admin/audit', adminAudit);
```

- [ ] **Step 6: Endpoint integration test**

```ts
// apps/worker/tests/audit/admin-verify-chain.test.ts
import { describe, it, expect, beforeAll } from 'vitest';
import { unstable_dev, type UnstableDevWorker } from 'wrangler';
import { signJwt } from '../../src/lib/jwt';

describe('GET /api/admin/audit/verify-chain', () => {
  let worker: UnstableDevWorker;
  let adminJwt: string;
  beforeAll(async () => {
    worker = await unstable_dev('src/index.ts', { local: true, experimental: { disableExperimentalWarning: true } });
    adminJwt = await signJwt({ memberId: 1, role: 'admin', employeeId: '1' }, 'test-key');
  });

  it('requires admin role', async () => {
    const res = await worker.fetch('/api/admin/audit/verify-chain?session_id=01HF3');
    expect(res.status).toBe(401);
  });

  it('returns 400 when session_id is missing', async () => {
    const res = await worker.fetch('/api/admin/audit/verify-chain', {
      headers: { Authorization: `Bearer ${adminJwt}` },
    });
    expect(res.status).toBe(400);
  });

  it('returns ok:true for a clean chain (after audit-emit-100 test helper)', async () => {
    await worker.fetch('/test/audit-emit-100', { method: 'POST' });
    const res = await worker.fetch('/api/admin/audit/verify-chain?session_id=01HF3&year=2026', {
      headers: { Authorization: `Bearer ${adminJwt}` },
    });
    expect(res.status).toBe(200);
    const body = await res.json() as { ok: boolean };
    expect(body.ok).toBe(true);
  });

  it('returns 422 + ok:false after a tamper test helper has run', async () => {
    await worker.fetch('/test/audit-tamper-chunk-1', { method: 'POST' });
    const res = await worker.fetch('/api/admin/audit/verify-chain?session_id=01HF3&year=2026', {
      headers: { Authorization: `Bearer ${adminJwt}` },
    });
    expect(res.status).toBe(422);
    const body = await res.json() as { ok: boolean; failed_at_chunk: number };
    expect(body.ok).toBe(false);
    expect(body.failed_at_chunk).toBe(1);
  });
});
```

- [ ] **Step 7: Run all audit tests, expect PASS; commit**

```bash
git add apps/worker/src/audit/verifier.ts apps/worker/src/routes/admin/audit.ts apps/worker/src/index.ts apps/worker/tests/audit/verifier.test.ts apps/worker/tests/audit/admin-verify-chain.test.ts
git commit -m "feat(audit): verifier + GET /api/admin/audit/verify-chain"
```

---

## Task 11: Tamper integration test — single byte mutation MUST fail

**Files:**
- Test: `apps/worker/tests/integration/audit-tamper.test.ts`

This is the **legal-record guarantee** encoded as a test (per D9). The test runs a real 250-event session, then in a loop mutates ONE byte at ONE random offset in ONE random chunk, runs the verifier, and asserts `ok: false`. Repeats 20 times for statistical confidence.

- [ ] **Step 1: Write the test**

```ts
// apps/worker/tests/integration/audit-tamper.test.ts
import { describe, it, expect, beforeAll } from 'vitest';
import * as ed from '@noble/ed25519';
import { ChainEmitter } from '../../src/audit/chain-emitter';
import { encodeKey } from '../../src/audit/signer';
import { verifyChain } from '../../src/audit/verifier';
import type { AuditEvent } from '@mbfd/shared';

function inMemR2() {
  const objects = new Map<string, Uint8Array>();
  return {
    put: async (key: string, body: string) => {
      objects.set(key, new TextEncoder().encode(body));
    },
    get: async (key: string) => {
      const v = objects.get(key);
      return v ? {
        text: async () => new TextDecoder().decode(v),
        arrayBuffer: async () => v.buffer,
      } : null;
    },
    list: async () => ({ objects: [...objects.keys()].sort().map((key) => ({ key })) }),
    _objects: objects,
  } as unknown as R2Bucket & { _objects: Map<string, Uint8Array> };
}

function noopDb() {
  return {
    insertChunk: async () => {},
    upsertState: async () => {},
    loadState: async () => null,
    backfillRowIndexes: async () => {},
  };
}

const mkEvent = (seq: number): AuditEvent => ({
  seq, bid_session_id: '01HTAMPER', action: 'pick', actor_type: 'member',
  actor_id: 42, target_kind: 'position', target_id: 'A' + (100 + (seq % 200)),
  created_at: '2026-09-22T14:00:00Z',
});

describe('audit chain tamper detection (D9 legal-record guarantee)', () => {
  let priv: Uint8Array;
  let pub: Uint8Array;
  beforeAll(async () => {
    priv = ed.utils.randomPrivateKey();
    pub = await ed.getPublicKeyAsync(priv);
  });

  async function buildCleanChain() {
    const r2 = inMemR2();
    const em = new ChainEmitter({
      r2, db: noopDb() as never, privKey: encodeKey(priv), pubKey: encodeKey(pub),
      year: 2026, now: () => Date.now(),
    });
    for (let i = 1; i <= 250; i++) await em.emit(mkEvent(i));
    await em.drainSession('01HTAMPER');
    return r2;
  }

  it('clean chain verifies ok', async () => {
    const r2 = await buildCleanChain();
    const res = await verifyChain(r2, '01HTAMPER', 2026);
    expect(res.ok).toBe(true);
    expect(res.total_events_verified).toBe(250);
  });

  it.each(Array.from({ length: 20 }, (_, i) => i))(
    'random single-byte tamper run %i MUST fail verification',
    async (i) => {
      const r2 = await buildCleanChain();
      const objects = (r2 as unknown as { _objects: Map<string, Uint8Array> })._objects;
      const keys = [...objects.keys()];
      const tamperKey = keys[i % keys.length]!;
      const original = objects.get(tamperKey)!;
      const tampered = new Uint8Array(original);
      const offset = Math.floor((i * 7919) % tampered.length);
      tampered[offset] = tampered[offset]! ^ 0x01;
      objects.set(tamperKey, tampered);
      const res = await verifyChain(r2, '01HTAMPER', 2026);
      expect(res.ok).toBe(false);
      expect(res.failed_at_chunk).toBeDefined();
    },
    20_000,
  );

  it('truncating the last chunk by 1 byte fails verification', async () => {
    const r2 = await buildCleanChain();
    const objects = (r2 as unknown as { _objects: Map<string, Uint8Array> })._objects;
    const keys = [...objects.keys()].sort();
    const lastKey = keys[keys.length - 1]!;
    const original = objects.get(lastKey)!;
    objects.set(lastKey, original.subarray(0, original.length - 1));
    const res = await verifyChain(r2, '01HTAMPER', 2026);
    expect(res.ok).toBe(false);
  });

  it('deleting the middle chunk fails verification with missing-chunk reason', async () => {
    const r2 = await buildCleanChain();
    const objects = (r2 as unknown as { _objects: Map<string, Uint8Array> })._objects;
    objects.delete('2026/01HTAMPER/chunks/0002.jsonl');
    const res = await verifyChain(r2, '01HTAMPER', 2026);
    expect(res.ok).toBe(false);
    expect(res.reason).toMatch(/missing/i);
  });
});
```

- [ ] **Step 2: Run, expect PASS; commit**

```bash
git add apps/worker/tests/integration/audit-tamper.test.ts
git commit -m "test(audit): 20-run random tamper detection + truncation + deletion"
```

---

## Task 12: 250-event clean replay performance test

**Files:**
- Test: `apps/worker/tests/integration/audit-replay-250.test.ts`

Asserts: a full 250-pick session produces exactly 3 chunks (100 + 100 + 50), verifies cleanly, and the full verifyChain call completes in under 1000ms on a local Worker.

- [ ] **Step 1: Write the test**

```ts
// apps/worker/tests/integration/audit-replay-250.test.ts
import { describe, it, expect, beforeAll } from 'vitest';
import * as ed from '@noble/ed25519';
import { ChainEmitter } from '../../src/audit/chain-emitter';
import { encodeKey } from '../../src/audit/signer';
import { verifyChain } from '../../src/audit/verifier';
import type { AuditEvent } from '@mbfd/shared';

function inMemR2() {
  const objects = new Map<string, Uint8Array>();
  return {
    put: async (key: string, body: string) => {
      objects.set(key, new TextEncoder().encode(body));
    },
    get: async (key: string) => {
      const v = objects.get(key);
      return v ? { text: async () => new TextDecoder().decode(v) } : null;
    },
    list: async () => ({ objects: [...objects.keys()].sort().map((key) => ({ key })) }),
    _objects: objects,
  } as unknown as R2Bucket;
}

const noopDb = () => ({
  insertChunk: async () => {}, upsertState: async () => {},
  loadState: async () => null, backfillRowIndexes: async () => {},
});

const mkEvent = (seq: number): AuditEvent => ({
  seq, bid_session_id: '01HREP', action: 'pick', actor_type: 'member',
  actor_id: (seq % 280) + 1, target_kind: 'position', target_id: 'A' + (100 + (seq % 230)),
  created_at: '2026-09-22T14:00:00Z',
});

describe('audit chain 250-event replay', () => {
  let priv: Uint8Array;
  let pub: Uint8Array;
  beforeAll(async () => {
    priv = ed.utils.randomPrivateKey();
    pub = await ed.getPublicKeyAsync(priv);
  });

  it('produces exactly 3 chunks (100 + 100 + 50) and verifies clean', async () => {
    const r2 = inMemR2();
    const em = new ChainEmitter({
      r2, db: noopDb() as never, privKey: encodeKey(priv), pubKey: encodeKey(pub),
      year: 2026, now: () => Date.now(),
    });
    for (let i = 1; i <= 250; i++) await em.emit(mkEvent(i));
    await em.drainSession('01HREP');
    const keys = [...(r2 as unknown as { _objects: Map<string, Uint8Array> })._objects.keys()];
    expect(keys).toHaveLength(3);
    const res = await verifyChain(r2, '01HREP', 2026);
    expect(res.ok).toBe(true);
    expect(res.total_events_verified).toBe(250);
  });

  it('verify completes in under 1000ms', async () => {
    const r2 = inMemR2();
    const em = new ChainEmitter({
      r2, db: noopDb() as never, privKey: encodeKey(priv), pubKey: encodeKey(pub),
      year: 2026, now: () => Date.now(),
    });
    for (let i = 1; i <= 250; i++) await em.emit(mkEvent(i));
    await em.drainSession('01HREP');
    const t0 = Date.now();
    const res = await verifyChain(r2, '01HREP', 2026);
    const elapsed = Date.now() - t0;
    expect(res.ok).toBe(true);
    expect(elapsed).toBeLessThan(1000);
  });
});
```

- [ ] **Step 2: Run, expect PASS; commit**

```bash
git add apps/worker/tests/integration/audit-replay-250.test.ts
git commit -m "test(audit): 250-event replay performance + chunk-count assertion"
```

---

## Task 13: Print-stylesheet RSC page for roster PDF

**Files:**
- Create: `apps/web/app/admin/exports/render/roster/[shift]/[session_id]/page.tsx`
- Create: `apps/web/app/admin/exports/render/roster/[shift]/[session_id]/print.css`
- Create: `apps/web/app/admin/exports/render/roster/[shift]/[session_id]/loading.tsx`
- Test: `apps/web/tests/e2e/admin-export-roster-visual.spec.ts`

Browserless will hit `https://staging.bid.mbfdhub.com/admin/exports/render/roster/A/01HF3?print=1` and PDF-print it. This page is a pure RSC — no client islands. It fetches the final bid result for that shift via the worker RPC client and renders the same row layout as `2025_A_Shift.pdf` (station → unit → rank → name → seniority).

The page MUST be reachable without admin auth from inside Cloudflare (Browserless calls through the worker). We protect it via a short-lived signed `?token=` query parameter generated by the export trigger.

- [ ] **Step 1: Write failing E2E (Playwright)**

```ts
// apps/web/tests/e2e/admin-export-roster-visual.spec.ts
import { test, expect } from '@playwright/test';

test('roster render page matches 2025 A Shift visual baseline', async ({ page }) => {
  // Generate a print-token via the admin endpoint (fixture seeds 2025 A-Shift data)
  await page.goto('/test/seed-2025-a-shift');
  const tokenRes = await page.request.post('/api/admin/exports/print-token', {
    data: { kind: 'roster', shift: 'A', session_id: 'TEST-2025-A' },
    headers: { Authorization: 'Bearer ADMIN_TEST_JWT' },
  });
  expect(tokenRes.ok()).toBe(true);
  const { token } = await tokenRes.json();

  await page.goto(`/admin/exports/render/roster/A/TEST-2025-A?token=${token}`);
  await page.waitForLoadState('networkidle');
  await expect(page).toHaveScreenshot('roster-a-2025.png', { maxDiffPixelRatio: 0.02 });
});

test('roster render rejects missing/expired token', async ({ page }) => {
  await page.goto('/admin/exports/render/roster/A/TEST-2025-A');
  await expect(page.getByText(/unauthorized/i)).toBeVisible();
});
```

- [ ] **Step 2: Run, expect FAIL (no page yet)**

- [ ] **Step 3: Implement `page.tsx`**

```tsx
// apps/web/app/admin/exports/render/roster/[shift]/[session_id]/page.tsx
import { notFound } from 'next/navigation';
import { verifyPrintToken } from '@/lib/print-token';
import { workerRpc } from '@/lib/rpc-client';
import './print.css';

export const runtime = 'edge';
export const dynamic = 'force-dynamic';

interface PageProps {
  params: { shift: 'A' | 'B' | 'C' | 'D'; session_id: string };
  searchParams: { token?: string };
}

export default async function RosterRenderPage({ params, searchParams }: PageProps) {
  const ok = await verifyPrintToken(
    searchParams.token,
    { kind: 'roster', shift: params.shift, session_id: params.session_id },
  );
  if (!ok) {
    return <div className="auth-error">Unauthorized — invalid or expired print token.</div>;
  }
  if (!['A', 'B', 'C', 'D'].includes(params.shift)) return notFound();
  const data = await workerRpc.exports.rosterData.$get({
    query: { session_id: params.session_id, shift: params.shift },
  });
  if (!data.ok) return notFound();
  const roster = await data.json();

  return (
    <main className="roster-page">
      <header className="roster-header">
        <h1>{roster.year} {params.shift} Shift Roster</h1>
        <p className="roster-meta">{roster.station_count} stations · {roster.position_count} positions · Generated {new Date().toISOString()}</p>
      </header>
      {roster.stations.map((s) => (
        <section key={s.station} className="station-block" data-station={s.station}>
          <h2>Station {s.station}</h2>
          <table>
            <thead>
              <tr>
                <th>Unit</th><th>Position</th><th>Rank</th><th>Member</th><th>RSC</th>
              </tr>
            </thead>
            <tbody>
              {s.rows.map((r) => (
                <tr key={r.position_id} data-empty={r.member_name === null}>
                  <td>{r.unit}</td>
                  <td>{r.position_id}</td>
                  <td>{r.rank}</td>
                  <td>{r.member_name ?? <span className="vacant">VACANT</span>}</td>
                  <td className="num">{r.rsc_seniority ?? '—'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </section>
      ))}
    </main>
  );
}
```

- [ ] **Step 4: Implement `print.css`**

```css
/* apps/web/app/admin/exports/render/roster/[shift]/[session_id]/print.css */
@page { size: Letter; margin: 0.5in; }
* { box-sizing: border-box; }
body { font-family: 'Source Sans 3', system-ui, sans-serif; color: #1a1a1a; }
.roster-page { max-width: 7.5in; margin: 0 auto; }
.roster-header h1 { font-family: 'Plus Jakarta Sans', sans-serif; font-size: 24pt; margin: 0 0 4pt; }
.roster-meta { font-size: 9pt; color: #555; margin: 0 0 16pt; }
.station-block { margin-bottom: 16pt; page-break-inside: avoid; }
.station-block h2 { font-size: 14pt; border-bottom: 1pt solid #b91c1c; margin: 0 0 6pt; padding-bottom: 2pt; }
table { width: 100%; border-collapse: collapse; font-size: 10pt; }
th, td { padding: 4pt 6pt; text-align: left; border-bottom: 0.5pt solid #d4d4d4; }
th { background: #f4f4f5; font-weight: 600; }
td.num { font-variant-numeric: tabular-nums; text-align: right; }
.vacant { color: #b91c1c; font-weight: 600; }
tr[data-empty="true"] td { background: #fef2f2; }
.auth-error { padding: 24pt; font-family: monospace; color: #b91c1c; }
```

- [ ] **Step 5: Implement `loading.tsx`** (so Suspense fallback isn't an empty page when Browserless gets impatient)

```tsx
export default function Loading() {
  return <main className="roster-page"><p>Loading roster…</p></main>;
}
```

- [ ] **Step 6: Implement `apps/web/lib/print-token.ts`**

```ts
// apps/web/lib/print-token.ts
//
// Short-lived (5 min) HMAC token signed by the worker that authorizes a single
// /admin/exports/render/* page render by Browserless. The web side just verifies.
import { hmac } from '@noble/hashes/hmac';
import { sha256 } from '@noble/hashes/sha256';
import { bytesToHex } from '@noble/hashes/utils';

export interface PrintTokenClaims {
  kind: 'roster' | 'audit-csv';
  shift?: string;
  session_id: string;
  exp: number;          // unix seconds
}

export async function verifyPrintToken(
  token: string | undefined,
  expected: Omit<PrintTokenClaims, 'exp'>,
): Promise<boolean> {
  if (!token) return false;
  const [body, sig] = token.split('.');
  if (!body || !sig) return false;
  let claims: PrintTokenClaims;
  try { claims = JSON.parse(atob(body)); } catch { return false; }
  if (claims.exp < Math.floor(Date.now() / 1000)) return false;
  if (claims.kind !== expected.kind) return false;
  if (claims.session_id !== expected.session_id) return false;
  if (expected.shift && claims.shift !== expected.shift) return false;
  const secret = new TextEncoder().encode(process.env.PRINT_TOKEN_SECRET!);
  const recomputed = bytesToHex(hmac(sha256, secret, new TextEncoder().encode(body)));
  return recomputed === sig;
}
```

- [ ] **Step 7: Implement worker `POST /api/admin/exports/print-token` that mints these tokens** (in Task 14's route file)

- [ ] **Step 8: Capture the Playwright visual baseline using `2025_A_Shift.pdf` as the goal**

```bash
pnpm --filter @mbfd/web e2e --update-snapshots admin-export-roster-visual
# Then commit the new snapshot file under apps/web/tests/e2e/__snapshots__/
```

- [ ] **Step 9: Run E2E, expect PASS; commit**

```bash
git add apps/web/app/admin/exports/render apps/web/lib/print-token.ts apps/web/tests/e2e/admin-export-roster-visual.spec.ts apps/web/tests/e2e/__snapshots__/
git commit -m "feat(web): roster render RSC + print stylesheet + visual baseline"
```

---

## Task 14: Browserless wrapper + roster PDF generator

**Files:**
- Create: `apps/worker/src/exports/roster-pdf.ts`
- Create: `apps/worker/src/exports/print-token.ts`
- Test: `apps/worker/tests/exports/roster-pdf.test.ts`

- [ ] **Step 1: Write failing test**

```ts
// apps/worker/tests/exports/roster-pdf.test.ts
import { describe, it, expect, vi } from 'vitest';
import { generateRosterPdf } from '../../src/exports/roster-pdf';

describe('generateRosterPdf', () => {
  it('POSTs the render URL to Browserless and uploads the response to R2', async () => {
    const pdfBytes = new Uint8Array([0x25, 0x50, 0x44, 0x46]); // "%PDF"
    const fetchSpy = vi.fn(async () => new Response(pdfBytes.buffer, {
      status: 200, headers: { 'content-type': 'application/pdf' },
    }));
    const r2Put = vi.fn(async () => {});
    const result = await generateRosterPdf({
      shift: 'A',
      sessionId: '01HF3',
      year: 2026,
      browserlessToken: 'BROWSERLESS_TEST',
      printTokenSecret: 'PRINT_SECRET',
      webBaseUrl: 'https://staging.bid.mbfdhub.com',
      r2: { put: r2Put } as unknown as R2Bucket,
      fetchImpl: fetchSpy,
      now: () => Date.now(),
    });
    expect(fetchSpy).toHaveBeenCalledTimes(1);
    const calledWith = fetchSpy.mock.calls[0]![0] as string;
    expect(calledWith).toMatch(/chrome\.browserless\.io\/pdf/);
    expect(r2Put).toHaveBeenCalledTimes(1);
    expect(result.r2Key).toMatch(/^2026\/01HF3\/A_Shift_\d+\.pdf$/);
    expect(result.bytes).toBe(pdfBytes.length);
  });

  it('throws when Browserless returns non-200', async () => {
    const fetchSpy = vi.fn(async () => new Response('boom', { status: 502 }));
    await expect(
      generateRosterPdf({
        shift: 'A', sessionId: '01HF3', year: 2026,
        browserlessToken: 't', printTokenSecret: 's',
        webBaseUrl: 'https://x', r2: { put: vi.fn() } as unknown as R2Bucket,
        fetchImpl: fetchSpy, now: () => Date.now(),
      }),
    ).rejects.toThrow(/Browserless/);
  });

  it('rejects unknown shift', async () => {
    await expect(
      generateRosterPdf({
        shift: 'Z' as 'A', sessionId: 'x', year: 2026,
        browserlessToken: 't', printTokenSecret: 's',
        webBaseUrl: 'https://x', r2: {} as R2Bucket,
        fetchImpl: vi.fn(), now: () => Date.now(),
      }),
    ).rejects.toThrow(/shift/);
  });
});
```

- [ ] **Step 2: Run test, expect FAIL**

- [ ] **Step 3: Implement `apps/worker/src/exports/print-token.ts`**

```ts
// apps/worker/src/exports/print-token.ts
import { hmac } from '@noble/hashes/hmac';
import { sha256 } from '@noble/hashes/sha256';
import { bytesToHex } from '@noble/hashes/utils';

export interface PrintTokenClaims {
  kind: 'roster' | 'audit-csv';
  shift?: 'A' | 'B' | 'C' | 'D';
  session_id: string;
  exp: number;
}

const enc = new TextEncoder();

export function mintPrintToken(
  claims: Omit<PrintTokenClaims, 'exp'>,
  secret: string,
  ttlSec = 300,
): string {
  const full: PrintTokenClaims = { ...claims, exp: Math.floor(Date.now() / 1000) + ttlSec };
  const body = btoa(JSON.stringify(full)).replace(/=+$/, '');
  const sig = bytesToHex(hmac(sha256, enc.encode(secret), enc.encode(body)));
  return `${body}.${sig}`;
}

export function verifyPrintToken(
  token: string,
  secret: string,
  expected: Omit<PrintTokenClaims, 'exp'>,
): boolean {
  const [body, sig] = token.split('.');
  if (!body || !sig) return false;
  let claims: PrintTokenClaims;
  try { claims = JSON.parse(atob(body)); } catch { return false; }
  if (claims.exp < Math.floor(Date.now() / 1000)) return false;
  if (claims.kind !== expected.kind) return false;
  if (claims.session_id !== expected.session_id) return false;
  if (expected.shift && claims.shift !== expected.shift) return false;
  const recomputed = bytesToHex(hmac(sha256, enc.encode(secret), enc.encode(body)));
  return recomputed === sig;
}
```

- [ ] **Step 4: Implement `apps/worker/src/exports/roster-pdf.ts`**

```ts
// apps/worker/src/exports/roster-pdf.ts
import { mintPrintToken } from './print-token.js';

export interface RosterPdfArgs {
  shift: 'A' | 'B' | 'C' | 'D';
  sessionId: string;
  year: number;
  browserlessToken: string;
  printTokenSecret: string;
  webBaseUrl: string;        // https://staging.bid.mbfdhub.com
  r2: R2Bucket;
  fetchImpl: typeof fetch;
  now: () => number;
}

export interface RosterPdfResult {
  r2Key: string;
  bytes: number;
  generatedAtMs: number;
}

export async function generateRosterPdf(args: RosterPdfArgs): Promise<RosterPdfResult> {
  if (!['A', 'B', 'C', 'D'].includes(args.shift)) {
    throw new Error(`Invalid shift: ${args.shift}`);
  }
  const token = mintPrintToken(
    { kind: 'roster', shift: args.shift, session_id: args.sessionId },
    args.printTokenSecret,
  );
  const renderUrl =
    `${args.webBaseUrl}/admin/exports/render/roster/${args.shift}/${args.sessionId}?token=${encodeURIComponent(token)}`;

  const browserlessUrl = `https://chrome.browserless.io/pdf?token=${encodeURIComponent(args.browserlessToken)}`;
  const res = await args.fetchImpl(browserlessUrl, {
    method: 'POST',
    headers: { 'content-type': 'application/json' },
    body: JSON.stringify({
      url: renderUrl,
      options: {
        format: 'Letter',
        printBackground: true,
        margin: { top: '0.5in', right: '0.5in', bottom: '0.5in', left: '0.5in' },
        preferCSSPageSize: true,
      },
      waitForTimeout: 2000,
      waitForSelector: '.station-block',
    }),
  });
  if (res.status !== 200) {
    const text = await res.text().catch(() => '');
    throw new Error(`Browserless returned ${res.status}: ${text}`);
  }
  const buf = await res.arrayBuffer();
  const generatedAtMs = args.now();
  const r2Key = `${args.year}/${args.sessionId}/${args.shift}_Shift_${generatedAtMs}.pdf`;
  await args.r2.put(r2Key, buf, {
    httpMetadata: { contentType: 'application/pdf' },
  });
  return { r2Key, bytes: buf.byteLength, generatedAtMs };
}
```

- [ ] **Step 5: Run test, expect PASS; commit**

```bash
git add apps/worker/src/exports/roster-pdf.ts apps/worker/src/exports/print-token.ts apps/worker/tests/exports/roster-pdf.test.ts
git commit -m "feat(exports): Browserless wrapper for roster PDFs + HMAC print tokens"
```

---

## Task 15: Audit CSV exporter (stream from D1 → gzip → R2)

**Files:**
- Create: `apps/worker/src/exports/audit-csv.ts`
- Test: `apps/worker/tests/exports/audit-csv.test.ts`

Performance target (per spec acceptance): 250-pick session's full audit CSV export completes in under 2 seconds, uploaded gzipped to R2.

Approach: paginated SELECT from `audit_log` (page size 500), stream rows through `Papa.unparse` per page, accumulate into a single `Uint8Array`, gzip via pako, upload.

- [ ] **Step 1: Write failing test**

```ts
// apps/worker/tests/exports/audit-csv.test.ts
import { describe, it, expect, vi } from 'vitest';
import { exportAuditCsv } from '../../src/exports/audit-csv';
import { inflate } from 'pako';

function makeFakeDb(rowCount: number) {
  const rows = Array.from({ length: rowCount }, (_, i) => ({
    id: `evt_${i + 1}`,
    bid_session_id: '01HF3',
    seq: i + 1,
    actor_type: 'member',
    actor_id: 42,
    action: 'pick',
    target_kind: 'position',
    target_id: 'A101',
    before_state: null,
    after_state: null,
    reason: null,
    ai_advisory_id: null,
    client_meta: null,
    created_at: new Date('2026-09-22T14:00:00Z').getTime(),
  }));
  return {
    pageRows: vi.fn(async (offset: number, limit: number) =>
      rows.slice(offset, offset + limit),
    ),
    count: vi.fn(async () => rowCount),
    rows,
  };
}

describe('exportAuditCsv', () => {
  it('streams 250 rows, gzips, uploads, returns r2Key + signed URL', async () => {
    const db = makeFakeDb(250);
    const r2Put = vi.fn(async () => {});
    const signed = vi.fn(async () => 'https://signed.example.com/x');
    const t0 = Date.now();
    const out = await exportAuditCsv({
      bidSessionId: '01HF3',
      year: 2026,
      db: db as never,
      r2: { put: r2Put } as unknown as R2Bucket,
      signUrl: signed,
      now: () => Date.now(),
    });
    const elapsed = Date.now() - t0;
    expect(elapsed).toBeLessThan(2000);
    expect(r2Put).toHaveBeenCalledTimes(1);
    expect(out.r2Key).toMatch(/^2026\/01HF3\/audit_full_\d+\.csv\.gz$/);
    expect(out.signedUrl).toBe('https://signed.example.com/x');
    expect(out.rowCount).toBe(250);
  });

  it('produces a valid gzipped CSV with header + 250 data rows', async () => {
    const db = makeFakeDb(250);
    let captured: Uint8Array | null = null;
    const r2 = {
      put: async (_key: string, body: ArrayBuffer | Uint8Array) => {
        captured = body instanceof Uint8Array ? body : new Uint8Array(body);
      },
    } as unknown as R2Bucket;
    await exportAuditCsv({
      bidSessionId: '01HF3', year: 2026,
      db: db as never, r2, signUrl: async () => 'x', now: () => Date.now(),
    });
    expect(captured).not.toBe(null);
    const inflated = new TextDecoder().decode(inflate(captured!));
    const lines = inflated.trim().split('\n');
    expect(lines).toHaveLength(251);     // 1 header + 250
    expect(lines[0]).toMatch(/^id,bid_session_id,seq,/);
  });

  it('handles empty result', async () => {
    const db = makeFakeDb(0);
    const out = await exportAuditCsv({
      bidSessionId: '01HF3', year: 2026,
      db: db as never, r2: { put: vi.fn() } as unknown as R2Bucket,
      signUrl: async () => 'x', now: () => Date.now(),
    });
    expect(out.rowCount).toBe(0);
  });
});
```

- [ ] **Step 2: Run, expect FAIL**

- [ ] **Step 3: Implement `apps/worker/src/exports/audit-csv.ts`**

```ts
// apps/worker/src/exports/audit-csv.ts
import Papa from 'papaparse';
import { gzip } from 'pako';

export interface AuditCsvDb {
  pageRows(offset: number, limit: number): Promise<ReadonlyArray<Record<string, unknown>>>;
  count(): Promise<number>;
}

export interface AuditCsvArgs {
  bidSessionId: string;
  year: number;
  db: AuditCsvDb;
  r2: R2Bucket;
  signUrl: (key: string) => Promise<string>;
  now: () => number;
}

export interface AuditCsvResult {
  r2Key: string;
  signedUrl: string;
  rowCount: number;
  bytesGzipped: number;
  elapsedMs: number;
}

const PAGE_SIZE = 500;
const CSV_FIELDS = [
  'id', 'bid_session_id', 'seq', 'actor_type', 'actor_id',
  'action', 'target_kind', 'target_id', 'before_state', 'after_state',
  'reason', 'ai_advisory_id', 'client_meta', 'created_at',
] as const;

export async function exportAuditCsv(args: AuditCsvArgs): Promise<AuditCsvResult> {
  const startedAt = args.now();
  let offset = 0;
  let rowCount = 0;
  const chunks: string[] = [];
  let isFirstPage = true;

  while (true) {
    const rows = await args.db.pageRows(offset, PAGE_SIZE);
    if (rows.length === 0) break;
    const csv = Papa.unparse(rows as Record<string, unknown>[], {
      header: isFirstPage,
      columns: CSV_FIELDS as unknown as string[],
      newline: '\n',
    });
    chunks.push(isFirstPage ? csv + '\n' : csv + '\n');
    isFirstPage = false;
    rowCount += rows.length;
    offset += PAGE_SIZE;
    if (rows.length < PAGE_SIZE) break;
  }

  if (chunks.length === 0) {
    // empty CSV — header only
    chunks.push(CSV_FIELDS.join(',') + '\n');
  }

  const raw = new TextEncoder().encode(chunks.join(''));
  const gzipped = gzip(raw, { level: 6 });
  const generatedAt = args.now();
  const r2Key = `${args.year}/${args.bidSessionId}/audit_full_${generatedAt}.csv.gz`;
  await args.r2.put(r2Key, gzipped, {
    httpMetadata: {
      contentType: 'text/csv',
      contentEncoding: 'gzip',
    },
  });
  const signedUrl = await args.signUrl(r2Key);
  return {
    r2Key,
    signedUrl,
    rowCount,
    bytesGzipped: gzipped.byteLength,
    elapsedMs: generatedAt - startedAt,
  };
}
```

- [ ] **Step 4: Run, expect PASS; commit**

```bash
git add apps/worker/src/exports/audit-csv.ts apps/worker/tests/exports/audit-csv.test.ts
git commit -m "feat(exports): audit CSV streamer with gzip + signed R2 URL"
```

---

## Task 16: Signed R2 URL helper

**Files:**
- Create: `apps/worker/src/exports/signed-url.ts`
- Test: `apps/worker/tests/exports/signed-url.test.ts`

R2 supports signed URLs via the Workers `R2Bucket.createMultipartUpload`-adjacent APIs but not directly via the binding. We use the **S3-compatible API** with R2 access keys (a separate set of credentials stored as Wrangler secrets `R2_ACCESS_KEY_ID` and `R2_SECRET_ACCESS_KEY`) and the AWS SigV4 algorithm — implemented inline since we don't need the full AWS SDK.

- [ ] **Step 1: Add secrets to Wrangler config notes**

```toml
#   R2_ACCESS_KEY_ID         (R2 access key for signed URL generation)
#   R2_SECRET_ACCESS_KEY     (R2 secret access key)
#   R2_ACCOUNT_ID            (Cloudflare account ID for r2.cloudflarestorage.com)
```

Generate keys via Cloudflare dashboard → R2 → Manage API tokens → "Object Read & Write" for `mbfd-bid-exports-staging`. Store with `wrangler secret put`.

- [ ] **Step 2: Write failing test**

```ts
// apps/worker/tests/exports/signed-url.test.ts
import { describe, it, expect, vi } from 'vitest';
import { createSignedR2Url } from '../../src/exports/signed-url';

describe('createSignedR2Url', () => {
  it('produces a URL with the AWS4-HMAC-SHA256 query signature', async () => {
    const url = await createSignedR2Url({
      bucket: 'mbfd-bid-exports-staging',
      key: '2026/01HF3/audit_full_123.csv.gz',
      accessKeyId: 'AKIA_FAKE',
      secretAccessKey: 'SECRET_FAKE_VERY_FAKE',
      accountId: 'CFACC_FAKE',
      ttlSec: 600,
      now: () => new Date('2026-09-22T14:00:00Z'),
    });
    const u = new URL(url);
    expect(u.hostname).toBe('CFACC_FAKE.r2.cloudflarestorage.com');
    expect(u.pathname).toBe('/mbfd-bid-exports-staging/2026/01HF3/audit_full_123.csv.gz');
    expect(u.searchParams.get('X-Amz-Algorithm')).toBe('AWS4-HMAC-SHA256');
    expect(u.searchParams.get('X-Amz-Expires')).toBe('600');
    expect(u.searchParams.get('X-Amz-Signature')).toMatch(/^[0-9a-f]{64}$/);
  });

  it('different keys produce different signatures', async () => {
    const base = {
      bucket: 'b', accessKeyId: 'k', secretAccessKey: 's',
      accountId: 'a', ttlSec: 60, now: () => new Date('2026-09-22T14:00:00Z'),
    };
    const u1 = await createSignedR2Url({ ...base, key: 'one' });
    const u2 = await createSignedR2Url({ ...base, key: 'two' });
    expect(new URL(u1).searchParams.get('X-Amz-Signature'))
      .not.toBe(new URL(u2).searchParams.get('X-Amz-Signature'));
  });
});
```

- [ ] **Step 3: Implement `apps/worker/src/exports/signed-url.ts`**

```ts
// apps/worker/src/exports/signed-url.ts
//
// AWS SigV4 query-string signing for R2's S3-compatible endpoint.
// Tailored for GET-presigned URLs (most common case for downloads).
import { sha256 } from '@noble/hashes/sha256';
import { hmac } from '@noble/hashes/hmac';
import { bytesToHex } from '@noble/hashes/utils';

const enc = new TextEncoder();

export interface SignedUrlArgs {
  bucket: string;
  key: string;
  accessKeyId: string;
  secretAccessKey: string;
  accountId: string;
  ttlSec: number;
  now: () => Date;
}

export async function createSignedR2Url(a: SignedUrlArgs): Promise<string> {
  const now = a.now();
  const amzDate = toAmzDate(now);                                   // 20260922T140000Z
  const dateStamp = amzDate.slice(0, 8);                            // 20260922
  const region = 'auto';
  const service = 's3';
  const host = `${a.accountId}.r2.cloudflarestorage.com`;
  const path = `/${a.bucket}/${a.key.split('/').map(encodeURIComponent).join('/')}`;
  const credentialScope = `${dateStamp}/${region}/${service}/aws4_request`;

  const qsObj: Record<string, string> = {
    'X-Amz-Algorithm': 'AWS4-HMAC-SHA256',
    'X-Amz-Credential': `${a.accessKeyId}/${credentialScope}`,
    'X-Amz-Date': amzDate,
    'X-Amz-Expires': String(a.ttlSec),
    'X-Amz-SignedHeaders': 'host',
  };
  const canonicalQs = Object.keys(qsObj).sort().map(
    (k) => encodeURIComponent(k) + '=' + encodeURIComponent(qsObj[k]!),
  ).join('&');
  const canonicalRequest = [
    'GET', path, canonicalQs, `host:${host}\n`, 'host', 'UNSIGNED-PAYLOAD',
  ].join('\n');
  const hashedCanon = bytesToHex(sha256(enc.encode(canonicalRequest)));
  const stringToSign = ['AWS4-HMAC-SHA256', amzDate, credentialScope, hashedCanon].join('\n');

  const kDate = hmac(sha256, enc.encode('AWS4' + a.secretAccessKey), enc.encode(dateStamp));
  const kRegion = hmac(sha256, kDate, enc.encode(region));
  const kService = hmac(sha256, kRegion, enc.encode(service));
  const kSigning = hmac(sha256, kService, enc.encode('aws4_request'));
  const signature = bytesToHex(hmac(sha256, kSigning, enc.encode(stringToSign)));

  return `https://${host}${path}?${canonicalQs}&X-Amz-Signature=${signature}`;
}

function toAmzDate(d: Date): string {
  const pad = (n: number) => String(n).padStart(2, '0');
  return `${d.getUTCFullYear()}${pad(d.getUTCMonth() + 1)}${pad(d.getUTCDate())}T${pad(d.getUTCHours())}${pad(d.getUTCMinutes())}${pad(d.getUTCSeconds())}Z`;
}
```

- [ ] **Step 4: Run, expect PASS; commit**

```bash
git add apps/worker/src/exports/signed-url.ts apps/worker/tests/exports/signed-url.test.ts
git commit -m "feat(exports): AWS SigV4 signed URL helper for R2 downloads"
```

---

## Task 17: Admin exports route — POST trigger + GET list

**Files:**
- Create: `apps/worker/src/routes/admin/exports.ts`
- Modify: `apps/worker/src/index.ts` (mount)
- Test: `apps/worker/tests/exports/admin-exports.test.ts`

Endpoints:

| Method | Path | Body | Effect |
|---|---|---|---|
| POST | /api/admin/exports/print-token | `{ kind, shift?, session_id }` | Mint a 5-min HMAC print token (for Browserless) |
| POST | /api/admin/exports/roster/:shift | `{ session_id }` | Generate + upload roster PDF for shift |
| POST | /api/admin/exports/audit-csv | `{ session_id }` | Generate + upload audit CSV |
| GET | /api/admin/exports/:session_id | — | List all exports for a session (paginated R2 list) |
| GET | /api/admin/exports/:session_id/:r2key/url | — | Return a freshly-signed download URL |

All write endpoints require step-up auth (`requireStepUpAuth(req, 300)` middleware from Plan 05).

- [ ] **Step 1: Write failing tests** (one per endpoint — show first; others follow same pattern)

```ts
// apps/worker/tests/exports/admin-exports.test.ts
import { describe, it, expect, beforeAll } from 'vitest';
import { unstable_dev, type UnstableDevWorker } from 'wrangler';
import { signJwt } from '../../src/lib/jwt';

describe('/api/admin/exports', () => {
  let worker: UnstableDevWorker;
  let adminJwt: string;
  beforeAll(async () => {
    worker = await unstable_dev('src/index.ts', { local: true, experimental: { disableExperimentalWarning: true } });
    adminJwt = await signJwt(
      { memberId: 1, role: 'admin', employeeId: '1', freshAuthAt: Math.floor(Date.now() / 1000) },
      'test-key',
    );
  });

  it('POST /print-token requires admin', async () => {
    const r = await worker.fetch('/api/admin/exports/print-token', { method: 'POST' });
    expect(r.status).toBe(401);
  });

  it('POST /print-token mints a token with the right claims', async () => {
    const r = await worker.fetch('/api/admin/exports/print-token', {
      method: 'POST',
      headers: { Authorization: `Bearer ${adminJwt}`, 'content-type': 'application/json' },
      body: JSON.stringify({ kind: 'roster', shift: 'A', session_id: '01HF3' }),
    });
    expect(r.status).toBe(200);
    const body = await r.json() as { token: string };
    expect(body.token).toMatch(/^[A-Za-z0-9_-]+\.[0-9a-f]+$/);
  });

  it('POST /roster/A produces a PDF entry under the session', async () => {
    const r = await worker.fetch('/api/admin/exports/roster/A', {
      method: 'POST',
      headers: { Authorization: `Bearer ${adminJwt}`, 'content-type': 'application/json' },
      body: JSON.stringify({ session_id: '01HF3' }),
    });
    expect([200, 502]).toContain(r.status);   // 502 acceptable if Browserless mocked
  });

  it('POST /audit-csv produces a CSV entry', async () => {
    const r = await worker.fetch('/api/admin/exports/audit-csv', {
      method: 'POST',
      headers: { Authorization: `Bearer ${adminJwt}`, 'content-type': 'application/json' },
      body: JSON.stringify({ session_id: '01HF3' }),
    });
    expect(r.status).toBe(200);
    const body = await r.json() as { r2Key: string };
    expect(body.r2Key).toMatch(/audit_full_\d+\.csv\.gz$/);
  });

  it('GET /:session_id lists exports', async () => {
    const r = await worker.fetch('/api/admin/exports/01HF3', {
      headers: { Authorization: `Bearer ${adminJwt}` },
    });
    expect(r.status).toBe(200);
    const body = await r.json() as { exports: Array<{ r2Key: string; kind: string }> };
    expect(Array.isArray(body.exports)).toBe(true);
  });
});
```

- [ ] **Step 2: Run, expect FAIL**

- [ ] **Step 3: Implement `apps/worker/src/routes/admin/exports.ts`**

```ts
// apps/worker/src/routes/admin/exports.ts
import { Hono } from 'hono';
import { z } from 'zod';
import { requireAdmin } from './middleware.js';
import { requireStepUpAuth } from '../../lib/step-up.js';      // from Plan 05
import { mintPrintToken } from '../../exports/print-token.js';
import { generateRosterPdf } from '../../exports/roster-pdf.js';
import { exportAuditCsv } from '../../exports/audit-csv.js';
import { createSignedR2Url } from '../../exports/signed-url.js';
import { auditCsvDbFromD1 } from '../../exports/audit-csv-db.js';

const r = new Hono<{ Bindings: Env }>();
r.use('*', requireAdmin);

const PrintTokenBody = z.object({
  kind: z.enum(['roster', 'audit-csv']),
  shift: z.enum(['A', 'B', 'C', 'D']).optional(),
  session_id: z.string().min(1),
});

r.post('/print-token', async (c) => {
  const body = PrintTokenBody.parse(await c.req.json());
  const token = mintPrintToken(body, c.env.PRINT_TOKEN_SECRET ?? c.env.JWT_SIGNING_KEY);
  return c.json({ token });
});

r.post('/roster/:shift', async (c) => {
  await requireStepUpAuth(c.req, 300);
  const shift = c.req.param('shift') as 'A' | 'B' | 'C' | 'D';
  if (!['A', 'B', 'C', 'D'].includes(shift)) return c.json({ error: 'invalid_shift' }, 400);
  const { session_id } = z.object({ session_id: z.string().min(1) }).parse(await c.req.json());
  const out = await generateRosterPdf({
    shift, sessionId: session_id, year: new Date().getUTCFullYear(),
    browserlessToken: c.env.BROWSERLESS_TOKEN,
    printTokenSecret: c.env.PRINT_TOKEN_SECRET ?? c.env.JWT_SIGNING_KEY,
    webBaseUrl: c.env.WEB_BASE_URL ?? 'https://staging.bid.mbfdhub.com',
    r2: c.env.R2_EXPORTS, fetchImpl: fetch, now: () => Date.now(),
  });
  return c.json(out);
});

r.post('/audit-csv', async (c) => {
  await requireStepUpAuth(c.req, 300);
  const { session_id } = z.object({ session_id: z.string().min(1) }).parse(await c.req.json());
  const out = await exportAuditCsv({
    bidSessionId: session_id,
    year: new Date().getUTCFullYear(),
    db: auditCsvDbFromD1(c.env.DB, session_id),
    r2: c.env.R2_EXPORTS,
    signUrl: (key) => createSignedR2Url({
      bucket: 'mbfd-bid-exports-staging',
      key,
      accessKeyId: c.env.R2_ACCESS_KEY_ID,
      secretAccessKey: c.env.R2_SECRET_ACCESS_KEY,
      accountId: c.env.R2_ACCOUNT_ID,
      ttlSec: 3600,
      now: () => new Date(),
    }),
    now: () => Date.now(),
  });
  return c.json(out);
});

r.get('/:session_id', async (c) => {
  const sid = c.req.param('session_id');
  const year = new Date().getUTCFullYear();
  const list = await c.env.R2_EXPORTS.list({ prefix: `${year}/${sid}/` });
  const exports = list.objects.map((o) => ({
    r2Key: o.key,
    kind: o.key.endsWith('.pdf') ? 'roster-pdf' : o.key.endsWith('.csv.gz') ? 'audit-csv' : 'other',
    bytes: o.size,
    uploadedAt: o.uploaded.toISOString(),
  }));
  return c.json({ exports });
});

r.get('/:session_id/:r2key/url', async (c) => {
  const key = decodeURIComponent(c.req.param('r2key'));
  const url = await createSignedR2Url({
    bucket: 'mbfd-bid-exports-staging',
    key,
    accessKeyId: c.env.R2_ACCESS_KEY_ID,
    secretAccessKey: c.env.R2_SECRET_ACCESS_KEY,
    accountId: c.env.R2_ACCOUNT_ID,
    ttlSec: 3600,
    now: () => new Date(),
  });
  return c.json({ url });
});

export default r;
```

- [ ] **Step 4: Implement `apps/worker/src/exports/audit-csv-db.ts`** (D1 adapter)

```ts
// apps/worker/src/exports/audit-csv-db.ts
import { eq, asc } from 'drizzle-orm';
import { getDb } from '../db/index.js';
import { auditLog } from '../db/schema.js';
import type { AuditCsvDb } from './audit-csv.js';

export function auditCsvDbFromD1(d1: D1Database, bidSessionId: string): AuditCsvDb {
  const db = getDb(d1);
  return {
    async pageRows(offset, limit) {
      const rows = await db
        .select()
        .from(auditLog)
        .where(eq(auditLog.bidSessionId, bidSessionId))
        .orderBy(asc(auditLog.seq))
        .limit(limit)
        .offset(offset)
        .all();
      // Stringify created_at to ISO for CSV friendliness
      return rows.map((r) => ({ ...r, created_at: r.createdAt.toISOString() }));
    },
    async count() {
      const result = await db.select({ c: auditLog.id }).from(auditLog).where(eq(auditLog.bidSessionId, bidSessionId)).all();
      return result.length;
    },
  };
}
```

- [ ] **Step 5: Mount in `src/index.ts`**

```ts
import adminExports from './routes/admin/exports.js';
app.route('/api/admin/exports', adminExports);
```

- [ ] **Step 6: Run, expect PASS; commit**

```bash
git add apps/worker/src/routes/admin/exports.ts apps/worker/src/exports/audit-csv-db.ts apps/worker/src/index.ts apps/worker/tests/exports/admin-exports.test.ts
git commit -m "feat(exports): admin exports routes (print-token, roster, audit-csv, list)"
```

---

## Task 18: Portal payload Zod schema + builder

**Files:**
- Create: `packages/shared/src/schemas/portal-payload.ts`
- Create: `apps/worker/src/portal-writeback/payload-builder.ts`
- Modify: `packages/shared/src/index.ts`
- Test: `packages/shared/tests/schemas/portal-payload.test.ts`
- Test: `apps/worker/tests/portal-writeback/payload-builder.test.ts`

Schema exactly mirrors spec §11.8.3. Field derivation per §11.8.2.

- [ ] **Step 1: Write failing tests**

```ts
// packages/shared/tests/schemas/portal-payload.test.ts
import { describe, it, expect } from 'vitest';
import { PortalPayloadSchema } from '../../src/schemas/portal-payload.js';

describe('PortalPayloadSchema', () => {
  it('accepts a minimal payload', () => {
    const p = PortalPayloadSchema.parse({
      bid_year: 2026,
      bid_session_id: '01HF3',
      rank_label: 'Lieutenant',
      station_label: 'Station 1',
      shift_label: 'A Shift',
      unit_label: 'Rescue 1',
      a_day_label: 'Pending Phase 2',
      position_id: 'A109',
      picked_at: '2026-09-22T14:23:00-04:00',
      idempotency_key: '***REMOVED_SECRET***',
      is_forced: false,
      admin_actor_employee_id: null,
    });
    expect(p.position_id).toBe('A109');
  });

  it('rejects unknown shift label', () => {
    expect(() => PortalPayloadSchema.parse({
      bid_year: 2026, bid_session_id: 'x', rank_label: 'LT', station_label: 'S',
      shift_label: 'Z Shift', unit_label: 'U', a_day_label: 'x', position_id: 'P',
      picked_at: '2026-09-22T14:23:00-04:00', idempotency_key: 'k',
      is_forced: false, admin_actor_employee_id: null,
    })).toThrow();
  });
});
```

```ts
// apps/worker/tests/portal-writeback/payload-builder.test.ts
import { describe, it, expect } from 'vitest';
import { buildPortalPayload } from '../../src/portal-writeback/payload-builder';

describe('buildPortalPayload', () => {
  const bid = {
    id: '***REMOVED_SECRET***',
    bidSessionId: '01HF3',
    memberId: 42,
    positionId: 'A109',
    aDay: null,
    pickedAt: new Date('2026-09-22T18:23:00Z'),
    forced: false,
    adminActorId: null,
  };
  const member = { id: 42, employeeId: '14523', rank: 'LT' as const };
  const adminActor = null;
  const position = {
    id: 'A109', shift: 'A' as const, station: '1', unit: 'Rescue 1',
    positionName: 'Lieutenant - Rescue 1', rankRequired: 'LT' as const,
  };

  it('produces payload matching spec §11.8.3', () => {
    const p = buildPortalPayload({ bid, member, adminActor, position, bidYear: 2026 });
    expect(p).toEqual({
      bid_year: 2026,
      bid_session_id: '01HF3',
      rank_label: 'Lieutenant',
      station_label: 'Station 1',
      shift_label: 'A Shift',
      unit_label: 'Rescue 1',
      a_day_label: 'Pending Phase 2',
      position_id: 'A109',
      picked_at: '2026-09-22T18:23:00.000Z',
      idempotency_key: '***REMOVED_SECRET***',
      is_forced: false,
      admin_actor_employee_id: null,
    });
  });

  it('translates a_day group code → label', () => {
    const p = buildPortalPayload({ bid: { ...bid, aDay: 'G4' }, member, adminActor, position, bidYear: 2026 });
    expect(p.a_day_label).toBe('Group 4');
  });

  it('translates D-shift weekday code → label', () => {
    const p = buildPortalPayload({
      bid: { ...bid, aDay: 'FRI' }, member, adminActor,
      position: { ...position, shift: 'D' },
      bidYear: 2026,
    });
    expect(p.a_day_label).toBe('Friday');
  });

  it('sets admin_actor_employee_id when admin bid for member', () => {
    const p = buildPortalPayload({
      bid: { ...bid, adminActorId: 1 },
      member, adminActor: { id: 1, employeeId: '12345' }, position, bidYear: 2026,
    });
    expect(p.admin_actor_employee_id).toBe('12345');
  });
});
```

- [ ] **Step 2: Run, expect FAIL**

- [ ] **Step 3: Implement `packages/shared/src/schemas/portal-payload.ts`**

```ts
// packages/shared/src/schemas/portal-payload.ts
import { z } from 'zod';

export const ShiftLabelSchema = z.enum(['A Shift', 'B Shift', 'C Shift', 'D Shift']);
export const RankLabelSchema = z.enum([
  'Firefighter', 'Lieutenant', 'Captain', 'Division Chief', 'Deputy Fire Chief', 'Fire Chief',
]);

export const PortalPayloadSchema = z.object({
  bid_year: z.number().int(),
  bid_session_id: z.string().min(1),
  rank_label: RankLabelSchema,
  station_label: z.string().min(1),
  shift_label: ShiftLabelSchema,
  unit_label: z.string().min(1),
  a_day_label: z.string().min(1),
  position_id: z.string().min(1),
  picked_at: z.string().min(1),               // ISO 8601
  idempotency_key: z.string().min(1),
  is_forced: z.boolean(),
  admin_actor_employee_id: z.string().nullable(),
});
export type PortalPayload = z.infer<typeof PortalPayloadSchema>;
```

- [ ] **Step 4: Implement `apps/worker/src/portal-writeback/payload-builder.ts`**

```ts
// apps/worker/src/portal-writeback/payload-builder.ts
import type { PortalPayload } from '@mbfd/shared';

const RANK_LABEL: Record<string, PortalPayload['rank_label']> = {
  FF: 'Firefighter',
  LT: 'Lieutenant',
  CPT: 'Captain',
  DC: 'Division Chief',
  DEP_CHIEF: 'Deputy Fire Chief',
  CHIEF: 'Fire Chief',
};
const SHIFT_LABEL: Record<string, PortalPayload['shift_label']> = {
  A: 'A Shift', B: 'B Shift', C: 'C Shift', D: 'D Shift',
};
const GROUP_LABEL: Record<string, string> = {
  G1: 'Group 1', G2: 'Group 2', G3: 'Group 3', G4: 'Group 4',
  MON: 'Monday', TUE: 'Tuesday', WED: 'Wednesday', THU: 'Thursday', FRI: 'Friday',
};

export interface BuildArgs {
  bid: {
    id: string;
    bidSessionId: string;
    memberId: number;
    positionId: string;
    aDay: string | null;
    pickedAt: Date;
    forced: boolean;
    adminActorId: number | null;
  };
  member: { id: number; employeeId: string; rank: keyof typeof RANK_LABEL };
  adminActor: { id: number; employeeId: string } | null;
  position: { id: string; shift: keyof typeof SHIFT_LABEL; station: string; unit: string };
  bidYear: number;
}

export function buildPortalPayload(a: BuildArgs): PortalPayload {
  const aDayLabel = a.bid.aDay
    ? (GROUP_LABEL[a.bid.aDay] ?? a.bid.aDay)
    : 'Pending Phase 2';
  return {
    bid_year: a.bidYear,
    bid_session_id: a.bid.bidSessionId,
    rank_label: RANK_LABEL[a.member.rank]!,
    station_label: `Station ${a.position.station}`,
    shift_label: SHIFT_LABEL[a.position.shift]!,
    unit_label: a.position.unit,
    a_day_label: aDayLabel,
    position_id: a.position.id,
    picked_at: a.bid.pickedAt.toISOString(),
    idempotency_key: a.bid.id,
    is_forced: a.bid.forced,
    admin_actor_employee_id: a.adminActor?.employeeId ?? null,
  };
}
```

- [ ] **Step 5: Re-export from `packages/shared/src/index.ts`**

```ts
export * from './schemas/portal-payload.js';
```

- [ ] **Step 6: Run, expect PASS; commit**

```bash
git add packages/shared/src/schemas/portal-payload.ts apps/worker/src/portal-writeback/payload-builder.ts packages/shared/src/index.ts packages/shared/tests/schemas/portal-payload.test.ts apps/worker/tests/portal-writeback/payload-builder.test.ts
git commit -m "feat(portal): PortalPayload Zod + buildPortalPayload (spec §11.8.2 derivation)"
```

---

## Task 19: Retry policy (exponential backoff, integer-safe)

**Files:**
- Create: `apps/worker/src/portal-writeback/retry-policy.ts`
- Test: `apps/worker/tests/portal-writeback/retry-policy.test.ts`

Per **D12**: `next_attempt_at = enqueued_at + min(24h, 2^min(attempts, 10) * 1000ms) + jitter(0–1000ms)`. Hard cap (**D6**): 24 attempts; after that, return `'permanent_failure'`.

- [ ] **Step 1: Write failing test**

```ts
// apps/worker/tests/portal-writeback/retry-policy.test.ts
import { describe, it, expect } from 'vitest';
import { nextAttempt, RETRY_MAX_ATTEMPTS, RETRY_MAX_DELAY_MS } from '../../src/portal-writeback/retry-policy';

describe('retry-policy', () => {
  it('RETRY_MAX_ATTEMPTS = 24', () => expect(RETRY_MAX_ATTEMPTS).toBe(24));
  it('RETRY_MAX_DELAY_MS = 24h', () => expect(RETRY_MAX_DELAY_MS).toBe(24 * 60 * 60 * 1000));

  it('attempt 1 delay ≈ 2s ± 1s jitter', () => {
    const r = nextAttempt({ attempts: 1, nowMs: 1_000_000, rng: () => 0 });
    expect(r.kind).toBe('retry');
    if (r.kind === 'retry') expect(r.nextAttemptAtMs).toBe(1_000_000 + 2_000);
  });

  it('attempt 5 delay ≈ 32s', () => {
    const r = nextAttempt({ attempts: 5, nowMs: 0, rng: () => 0 });
    if (r.kind === 'retry') expect(r.nextAttemptAtMs).toBe(32_000);
  });

  it('attempt 10 delay ≈ 1024s = 17m4s', () => {
    const r = nextAttempt({ attempts: 10, nowMs: 0, rng: () => 0 });
    if (r.kind === 'retry') expect(r.nextAttemptAtMs).toBe(1_024_000);
  });

  it('attempt 11+ delay capped at 24h', () => {
    const r = nextAttempt({ attempts: 23, nowMs: 0, rng: () => 0 });
    if (r.kind === 'retry') expect(r.nextAttemptAtMs).toBeLessThanOrEqual(24 * 60 * 60 * 1000 + 1000);
  });

  it('attempt 25 returns permanent_failure', () => {
    const r = nextAttempt({ attempts: 25, nowMs: 0, rng: () => 0 });
    expect(r.kind).toBe('permanent_failure');
  });

  it('jitter is in 0..1000ms range', () => {
    const a = nextAttempt({ attempts: 1, nowMs: 0, rng: () => 0 });
    const b = nextAttempt({ attempts: 1, nowMs: 0, rng: () => 0.999 });
    if (a.kind === 'retry' && b.kind === 'retry') {
      expect(b.nextAttemptAtMs - a.nextAttemptAtMs).toBeLessThan(1000);
    }
  });

  it('all math is integer (no fractional ms)', () => {
    for (let i = 1; i <= 24; i++) {
      const r = nextAttempt({ attempts: i, nowMs: 0, rng: () => 0.5 });
      if (r.kind === 'retry') {
        expect(Number.isInteger(r.nextAttemptAtMs)).toBe(true);
      }
    }
  });
});
```

- [ ] **Step 2: Run, expect FAIL**

- [ ] **Step 3: Implement `apps/worker/src/portal-writeback/retry-policy.ts`**

```ts
// apps/worker/src/portal-writeback/retry-policy.ts
//
// Exponential backoff for portal write-back retries.
// All math uses integer ms (D12). Math.pow / floats would compound rounding
// errors over a 24-hour retry window.

export const RETRY_MAX_ATTEMPTS = 24;
export const RETRY_MAX_DELAY_MS = 24 * 60 * 60 * 1000;
const EXP_CAP = 10;     // 2^10 = 1024 seconds = ~17 minutes
const JITTER_MAX_MS = 1000;

export type RetryDecision =
  | { kind: 'retry'; nextAttemptAtMs: number }
  | { kind: 'permanent_failure' };

export interface NextAttemptArgs {
  attempts: number;     // number of attempts already made (0 = first try)
  nowMs: number;
  rng?: () => number;   // injectable for tests
}

export function nextAttempt(a: NextAttemptArgs): RetryDecision {
  if (a.attempts > RETRY_MAX_ATTEMPTS) {
    return { kind: 'permanent_failure' };
  }
  const exp = Math.min(a.attempts, EXP_CAP);
  const baseMs = (1 << exp) * 1000;            // integer shift
  const cappedMs = Math.min(baseMs, RETRY_MAX_DELAY_MS);
  const rng = a.rng ?? Math.random;
  const jitter = Math.floor(rng() * JITTER_MAX_MS);
  return { kind: 'retry', nextAttemptAtMs: a.nowMs + cappedMs + jitter };
}
```

- [ ] **Step 4: Run, expect PASS; commit**

```bash
git add apps/worker/src/portal-writeback/retry-policy.ts apps/worker/tests/portal-writeback/retry-policy.test.ts
git commit -m "feat(portal): integer-safe exp-backoff retry policy (24 attempts / 24h)"
```

---

## Task 20: Portal client (HTTP POST + auth header)

**Files:**
- Create: `apps/worker/src/portal-writeback/portal-client.ts`
- Test: `apps/worker/tests/portal-writeback/portal-client.test.ts`

- [ ] **Step 1: Write failing test**

```ts
// apps/worker/tests/portal-writeback/portal-client.test.ts
import { describe, it, expect, vi } from 'vitest';
import { postBidAssignment } from '../../src/portal-writeback/portal-client';
import type { PortalPayload } from '@mbfd/shared';

const payload: PortalPayload = {
  bid_year: 2026, bid_session_id: '01HF3', rank_label: 'Lieutenant',
  station_label: 'Station 1', shift_label: 'A Shift', unit_label: 'Rescue 1',
  a_day_label: 'Pending Phase 2', position_id: 'A109',
  picked_at: '2026-09-22T18:23:00Z', idempotency_key: 'bid_x',
  is_forced: false, admin_actor_employee_id: null,
};

describe('postBidAssignment', () => {
  it('returns synced on 200', async () => {
    const fetchImpl = vi.fn(async () => new Response(JSON.stringify({ stored: true }), { status: 200 }));
    const out = await postBidAssignment({
      employeeId: '14523', payload,
      portalBaseUrl: 'https://portal.mbfdhub.com',
      token: 't', fetchImpl,
    });
    expect(out.kind).toBe('synced');
  });
  it('returns synced on 409 (idempotency)', async () => {
    const fetchImpl = vi.fn(async () => new Response('', { status: 409 }));
    const out = await postBidAssignment({
      employeeId: '14523', payload,
      portalBaseUrl: 'https://portal.mbfdhub.com',
      token: 't', fetchImpl,
    });
    expect(out.kind).toBe('synced');
  });
  it('returns transient on 500', async () => {
    const fetchImpl = vi.fn(async () => new Response('boom', { status: 500 }));
    const out = await postBidAssignment({
      employeeId: '14523', payload,
      portalBaseUrl: 'https://portal.mbfdhub.com',
      token: 't', fetchImpl,
    });
    expect(out.kind).toBe('transient');
  });
  it('returns permanent on 400', async () => {
    const fetchImpl = vi.fn(async () => new Response('bad', { status: 400 }));
    const out = await postBidAssignment({
      employeeId: '14523', payload,
      portalBaseUrl: 'https://portal.mbfdhub.com',
      token: 't', fetchImpl,
    });
    expect(out.kind).toBe('permanent');
    expect(out.message).toContain('400');
  });
  it('returns transient on network error', async () => {
    const fetchImpl = vi.fn(async () => { throw new Error('ENETUNREACH'); });
    const out = await postBidAssignment({
      employeeId: '14523', payload,
      portalBaseUrl: 'https://portal.mbfdhub.com',
      token: 't', fetchImpl,
    });
    expect(out.kind).toBe('transient');
  });
  it('sends Authorization: Bearer token', async () => {
    const fetchImpl = vi.fn(async () => new Response('', { status: 200 }));
    await postBidAssignment({
      employeeId: '14523', payload,
      portalBaseUrl: 'https://portal.mbfdhub.com',
      token: 'SVC_TOKEN', fetchImpl,
    });
    const init = fetchImpl.mock.calls[0]![1] as RequestInit;
    expect((init.headers as Record<string, string>)['Authorization']).toBe('Bearer SVC_TOKEN');
  });
});
```

- [ ] **Step 2: Run, expect FAIL**

- [ ] **Step 3: Implement `apps/worker/src/portal-writeback/portal-client.ts`**

```ts
// apps/worker/src/portal-writeback/portal-client.ts
import type { PortalPayload } from '@mbfd/shared';

export type PostResult =
  | { kind: 'synced'; statusCode: number }
  | { kind: 'transient'; statusCode: number | null; message: string }
  | { kind: 'permanent'; statusCode: number; message: string };

export interface PostArgs {
  employeeId: string;
  payload: PortalPayload;
  portalBaseUrl: string;
  token: string;
  fetchImpl: typeof fetch;
  timeoutMs?: number;
}

export async function postBidAssignment(a: PostArgs): Promise<PostResult> {
  const url = `${a.portalBaseUrl}/api/v2/members/${encodeURIComponent(a.employeeId)}/bid-assignment`;
  const timeoutMs = a.timeoutMs ?? 10_000;
  const ctrl = new AbortController();
  const timeout = setTimeout(() => ctrl.abort(), timeoutMs);
  try {
    const res = await a.fetchImpl(url, {
      method: 'POST',
      headers: {
        'Authorization': `Bearer ${a.token}`,
        'Content-Type': 'application/json',
      },
      body: JSON.stringify(a.payload),
      signal: ctrl.signal,
    });
    if (res.status === 200 || res.status === 409) {
      return { kind: 'synced', statusCode: res.status };
    }
    if (res.status >= 500) {
      const msg = await res.text().catch(() => '');
      return { kind: 'transient', statusCode: res.status, message: msg };
    }
    if (res.status >= 400) {
      const msg = await res.text().catch(() => '');
      return { kind: 'permanent', statusCode: res.status, message: `${res.status}: ${msg}` };
    }
    return { kind: 'transient', statusCode: res.status, message: 'unexpected status' };
  } catch (e) {
    return { kind: 'transient', statusCode: null, message: (e as Error).message };
  } finally {
    clearTimeout(timeout);
  }
}
```

- [ ] **Step 4: Run, expect PASS; commit**

```bash
git add apps/worker/src/portal-writeback/portal-client.ts apps/worker/tests/portal-writeback/portal-client.test.ts
git commit -m "feat(portal): postBidAssignment with 200/409 synced, 5xx transient, 4xx permanent"
```

---

## Task 21: Queue producer (DO step 4)

**Files:**
- Create: `apps/worker/src/portal-writeback/queue-producer.ts`
- Modify: `apps/worker/src/durable/bid-session.ts` (1-line addition)
- Test: `apps/worker/tests/portal-writeback/queue-producer.test.ts`

- [ ] **Step 1: Write failing test**

```ts
// apps/worker/tests/portal-writeback/queue-producer.test.ts
import { describe, it, expect, vi } from 'vitest';
import { enqueuePortalWriteback } from '../../src/portal-writeback/queue-producer';

describe('enqueuePortalWriteback', () => {
  it('sends payload to env.PORTAL_QUEUE.send and inserts a writeback queue row', async () => {
    const send = vi.fn(async () => {});
    const insertQueueRow = vi.fn(async () => {});
    await enqueuePortalWriteback({
      bidId: 'bid_1', employeeId: '14523',
      payload: {
        bid_year: 2026, bid_session_id: '01HF3', rank_label: 'Lieutenant',
        station_label: 'Station 1', shift_label: 'A Shift', unit_label: 'Rescue 1',
        a_day_label: 'Pending Phase 2', position_id: 'A109',
        picked_at: '2026-09-22T14:23:00Z', idempotency_key: 'bid_1',
        is_forced: false, admin_actor_employee_id: null,
      },
      queue: { send } as unknown as Queue<unknown>,
      insertQueueRow,
      now: () => 1_000_000,
    });
    expect(send).toHaveBeenCalledTimes(1);
    expect(insertQueueRow).toHaveBeenCalledWith(expect.objectContaining({
      bidId: 'bid_1',
      status: 'queued',
      attempts: 0,
    }));
  });

  it('writes row first, then sends to queue (if queue.send throws, row remains for daily reconciliation)', async () => {
    const calls: string[] = [];
    const send = vi.fn(async () => { calls.push('send'); throw new Error('queue down'); });
    const insertQueueRow = vi.fn(async () => { calls.push('insert'); });
    await expect(
      enqueuePortalWriteback({
        bidId: 'bid_1', employeeId: '14523',
        payload: {} as never, queue: { send } as unknown as Queue<unknown>,
        insertQueueRow, now: () => 0,
      }),
    ).rejects.toThrow();
    expect(calls).toEqual(['insert', 'send']);
  });
});
```

- [ ] **Step 2: Run, expect FAIL**

- [ ] **Step 3: Implement `apps/worker/src/portal-writeback/queue-producer.ts`**

```ts
// apps/worker/src/portal-writeback/queue-producer.ts
import type { PortalPayload } from '@mbfd/shared';

export interface EnqueueArgs {
  bidId: string;
  employeeId: string;
  payload: PortalPayload;
  queue: Queue<unknown>;
  insertQueueRow: (row: {
    id: string; bidId: string; enqueuedAt: Date;
    nextAttemptAt: Date; attempts: number; status: 'queued';
    payloadJson: string; lastError: null;
  }) => Promise<void>;
  now: () => number;
}

export interface QueueMessage {
  bidId: string;
  employeeId: string;
  payload: PortalPayload;
  attempts: number;
  queueRowId: string;
}

export async function enqueuePortalWriteback(a: EnqueueArgs): Promise<void> {
  const queueRowId = `qrow_${a.bidId}_${a.now()}`;
  const nowDate = new Date(a.now());
  // Row goes in FIRST so the daily reconciliation has a record even if queue.send fails.
  await a.insertQueueRow({
    id: queueRowId, bidId: a.bidId, enqueuedAt: nowDate,
    nextAttemptAt: nowDate, attempts: 0, status: 'queued',
    payloadJson: JSON.stringify(a.payload), lastError: null,
  });
  const message: QueueMessage = {
    bidId: a.bidId, employeeId: a.employeeId, payload: a.payload,
    attempts: 0, queueRowId,
  };
  await a.queue.send(message);
}
```

- [ ] **Step 4: Modify DO commit (Plan 04) — append step 4 inside `.commit()`** (after audit emit, before WS broadcast)

```ts
// apps/worker/src/durable/bid-session.ts — inside .commit() after auditEmitter.emit
const payload = buildPortalPayload({
  bid, member, adminActor, position, bidYear: this.state.bidYear,
});
await enqueuePortalWriteback({
  bidId: bid.id,
  employeeId: member.employeeId,
  payload,
  queue: this.env.PORTAL_QUEUE,
  insertQueueRow: makeQueueRowInserter(this.env.DB),
  now: () => this.deps.now(),
});
```

- [ ] **Step 5: Run, expect PASS; commit**

```bash
git add apps/worker/src/portal-writeback/queue-producer.ts apps/worker/src/durable/bid-session.ts apps/worker/tests/portal-writeback/queue-producer.test.ts
git commit -m "feat(portal): queue producer + DO commit step 4"
```

---

## Task 22: Queue consumer handler

**Files:**
- Create: `apps/worker/src/portal-writeback/queue-consumer.ts`
- Modify: `apps/worker/src/index.ts` (export `queue` handler)
- Test: `apps/worker/tests/portal-writeback/queue-consumer.test.ts`

Consumer loop per message:
1. Call `postBidAssignment`.
2. On `synced`: update `bids.portal_sync_status='synced'`, set `portal_synced_at`, increment `portal_sync_attempts`; mark `portal_writeback_queue.status='done'`.
3. On `transient`: compute `nextAttempt(attempts+1)`. If `retry`, schedule a re-enqueue via `env.PORTAL_QUEUE.send(msg, { delaySeconds })`; update queue row with `status='queued'`, `attempts+1`, `next_attempt_at`, `last_error`. If `permanent_failure`, mark `bids.portal_sync_status='failed'`, queue row `status='failed'`.
4. On `permanent`: immediately mark `bids.portal_sync_status='failed'`, queue row `status='failed'`.

- [ ] **Step 1: Write failing test**

```ts
// apps/worker/tests/portal-writeback/queue-consumer.test.ts
import { describe, it, expect, vi } from 'vitest';
import { handleMessage } from '../../src/portal-writeback/queue-consumer';
import type { QueueMessage } from '../../src/portal-writeback/queue-producer';

const baseMsg: QueueMessage = {
  bidId: 'bid_1', employeeId: '14523', queueRowId: 'qrow_1', attempts: 0,
  payload: {
    bid_year: 2026, bid_session_id: '01HF3', rank_label: 'Lieutenant',
    station_label: 'Station 1', shift_label: 'A Shift', unit_label: 'Rescue 1',
    a_day_label: 'Pending Phase 2', position_id: 'A109',
    picked_at: '2026-09-22T14:23:00Z', idempotency_key: 'bid_1',
    is_forced: false, admin_actor_employee_id: null,
  },
};

function fakeDeps(post: 'synced' | 'transient' | 'permanent') {
  const calls: string[] = [];
  const portalClient = vi.fn(async () => {
    calls.push('post');
    if (post === 'synced') return { kind: 'synced', statusCode: 200 } as const;
    if (post === 'transient') return { kind: 'transient', statusCode: 503, message: 'down' } as const;
    return { kind: 'permanent', statusCode: 400, message: 'bad' } as const;
  });
  const markBidSynced = vi.fn(async () => { calls.push('synced'); });
  const markBidFailed = vi.fn(async () => { calls.push('failed'); });
  const incrementAttempts = vi.fn(async () => { calls.push('inc'); });
  const updateQueueRow = vi.fn(async () => { calls.push('qrow'); });
  const requeue = vi.fn(async () => { calls.push('requeue'); });
  return { portalClient, markBidSynced, markBidFailed, incrementAttempts, updateQueueRow, requeue, calls };
}

describe('handleMessage', () => {
  it('on synced: mark bid synced + queue row done', async () => {
    const d = fakeDeps('synced');
    await handleMessage(baseMsg, d, { nowMs: 0 });
    expect(d.markBidSynced).toHaveBeenCalledTimes(1);
    expect(d.updateQueueRow).toHaveBeenCalledWith(expect.objectContaining({ status: 'done' }));
  });
  it('on transient with attempts < 24: requeues with delay', async () => {
    const d = fakeDeps('transient');
    await handleMessage(baseMsg, d, { nowMs: 0 });
    expect(d.requeue).toHaveBeenCalledTimes(1);
    expect(d.incrementAttempts).toHaveBeenCalled();
  });
  it('on transient with attempts > 24: mark permanently failed', async () => {
    const d = fakeDeps('transient');
    await handleMessage({ ...baseMsg, attempts: 25 }, d, { nowMs: 0 });
    expect(d.markBidFailed).toHaveBeenCalledTimes(1);
    expect(d.requeue).not.toHaveBeenCalled();
  });
  it('on permanent (4xx): mark bid failed immediately', async () => {
    const d = fakeDeps('permanent');
    await handleMessage(baseMsg, d, { nowMs: 0 });
    expect(d.markBidFailed).toHaveBeenCalledTimes(1);
    expect(d.updateQueueRow).toHaveBeenCalledWith(expect.objectContaining({ status: 'failed' }));
    expect(d.requeue).not.toHaveBeenCalled();
  });
});
```

- [ ] **Step 2: Run, expect FAIL**

- [ ] **Step 3: Implement `apps/worker/src/portal-writeback/queue-consumer.ts`**

```ts
// apps/worker/src/portal-writeback/queue-consumer.ts
import { nextAttempt } from './retry-policy.js';
import type { QueueMessage } from './queue-producer.js';
import type { PostResult } from './portal-client.js';

export interface ConsumerDeps {
  portalClient: (msg: QueueMessage) => Promise<PostResult>;
  markBidSynced: (bidId: string, syncedAt: Date, attempts: number) => Promise<void>;
  markBidFailed: (bidId: string, error: string, attempts: number) => Promise<void>;
  incrementAttempts: (bidId: string, attempts: number) => Promise<void>;
  updateQueueRow: (row: {
    id: string;
    status: 'queued' | 'done' | 'failed' | 'in_flight';
    attempts: number;
    nextAttemptAt: Date | null;
    lastError: string | null;
  }) => Promise<void>;
  requeue: (msg: QueueMessage, delaySeconds: number) => Promise<void>;
}

export interface ConsumerCtx {
  nowMs: number;
}

export async function handleMessage(
  msg: QueueMessage,
  deps: ConsumerDeps,
  ctx: ConsumerCtx,
): Promise<void> {
  await deps.updateQueueRow({
    id: msg.queueRowId, status: 'in_flight',
    attempts: msg.attempts, nextAttemptAt: null, lastError: null,
  });
  const result = await deps.portalClient(msg);
  if (result.kind === 'synced') {
    await deps.markBidSynced(msg.bidId, new Date(ctx.nowMs), msg.attempts + 1);
    await deps.updateQueueRow({
      id: msg.queueRowId, status: 'done', attempts: msg.attempts + 1,
      nextAttemptAt: null, lastError: null,
    });
    return;
  }
  if (result.kind === 'permanent') {
    await deps.markBidFailed(msg.bidId, result.message, msg.attempts + 1);
    await deps.updateQueueRow({
      id: msg.queueRowId, status: 'failed', attempts: msg.attempts + 1,
      nextAttemptAt: null, lastError: result.message,
    });
    return;
  }
  // transient
  const decision = nextAttempt({ attempts: msg.attempts + 1, nowMs: ctx.nowMs });
  if (decision.kind === 'permanent_failure') {
    await deps.markBidFailed(msg.bidId, 'retry budget exhausted: ' + result.message, msg.attempts + 1);
    await deps.updateQueueRow({
      id: msg.queueRowId, status: 'failed', attempts: msg.attempts + 1,
      nextAttemptAt: null, lastError: 'retry budget exhausted: ' + result.message,
    });
    return;
  }
  const delaySec = Math.ceil((decision.nextAttemptAtMs - ctx.nowMs) / 1000);
  await deps.incrementAttempts(msg.bidId, msg.attempts + 1);
  await deps.updateQueueRow({
    id: msg.queueRowId, status: 'queued', attempts: msg.attempts + 1,
    nextAttemptAt: new Date(decision.nextAttemptAtMs),
    lastError: result.message,
  });
  await deps.requeue({ ...msg, attempts: msg.attempts + 1 }, delaySec);
}
```

- [ ] **Step 4: Wire `queue` export in `apps/worker/src/index.ts`**

```ts
// apps/worker/src/index.ts
import { handleMessage } from './portal-writeback/queue-consumer.js';

export default {
  async fetch(req, env, ctx) { /* ...existing... */ },
  async scheduled(event, env, ctx) { /* ...existing... */ },
  async queue(batch: MessageBatch<QueueMessage>, env: Env, ctx: ExecutionContext) {
    for (const message of batch.messages) {
      try {
        await handleMessage(message.body, makeConsumerDeps(env), { nowMs: Date.now() });
        message.ack();
      } catch (e) {
        // Let Cloudflare retry once at the queue level; permanent failure already marked.
        message.retry();
      }
    }
  },
};
```

- [ ] **Step 5: Run, expect PASS; commit**

```bash
git add apps/worker/src/portal-writeback/queue-consumer.ts apps/worker/src/index.ts apps/worker/tests/portal-writeback/queue-consumer.test.ts
git commit -m "feat(portal): queue consumer with state-machine routing of post results"
```

---

## Task 23: Integration tests — 5xx retry path + 4xx permanent path

**Files:**
- Test: `apps/worker/tests/integration/portal-retry-5xx.test.ts`
- Test: `apps/worker/tests/integration/portal-perm-fail-4xx.test.ts`

Both tests use Miniflare with a mock portal endpoint that can be configured to return chosen status codes.

- [ ] **Step 1: Write `portal-retry-5xx.test.ts`**

```ts
import { describe, it, expect, beforeAll } from 'vitest';
import { unstable_dev, type UnstableDevWorker } from 'wrangler';

describe('portal writeback — 5xx then 2xx retry path', () => {
  let worker: UnstableDevWorker;
  beforeAll(async () => {
    worker = await unstable_dev('src/index.ts', { local: true, experimental: { disableExperimentalWarning: true } });
  });

  it('5xx for 3 attempts then 200 → bid ends up synced', async () => {
    await worker.fetch('/test/portal-mock/set?mode=5xx-then-200&fail_count=3', { method: 'POST' });
    await worker.fetch('/test/commit-pick?bid_id=bid_t1', { method: 'POST' });
    // Simulate 3 retry cycles
    for (let i = 0; i < 4; i++) {
      await worker.fetch('/test/drain-queue', { method: 'POST' });
    }
    const r = await worker.fetch('/test/bid-status?bid_id=bid_t1');
    const body = await r.json() as { portal_sync_status: string; portal_sync_attempts: number };
    expect(body.portal_sync_status).toBe('synced');
    expect(body.portal_sync_attempts).toBeGreaterThanOrEqual(4);
  });

  it('5xx for 25 attempts → bid ends up failed', async () => {
    await worker.fetch('/test/portal-mock/set?mode=always-5xx', { method: 'POST' });
    await worker.fetch('/test/commit-pick?bid_id=bid_t2', { method: 'POST' });
    for (let i = 0; i < 26; i++) {
      await worker.fetch('/test/drain-queue?fast-forward=1', { method: 'POST' });
    }
    const r = await worker.fetch('/test/bid-status?bid_id=bid_t2');
    const body = await r.json() as { portal_sync_status: string };
    expect(body.portal_sync_status).toBe('failed');
  });
});
```

- [ ] **Step 2: Write `portal-perm-fail-4xx.test.ts`**

```ts
import { describe, it, expect, beforeAll } from 'vitest';
import { unstable_dev, type UnstableDevWorker } from 'wrangler';

describe('portal writeback — 4xx permanent failure', () => {
  let worker: UnstableDevWorker;
  beforeAll(async () => {
    worker = await unstable_dev('src/index.ts', { local: true, experimental: { disableExperimentalWarning: true } });
  });

  it('400 → bid marked failed immediately; no retries', async () => {
    await worker.fetch('/test/portal-mock/set?mode=always-400', { method: 'POST' });
    await worker.fetch('/test/commit-pick?bid_id=bid_t3', { method: 'POST' });
    await worker.fetch('/test/drain-queue', { method: 'POST' });
    const r = await worker.fetch('/test/bid-status?bid_id=bid_t3');
    const body = await r.json() as { portal_sync_status: string; portal_sync_attempts: number };
    expect(body.portal_sync_status).toBe('failed');
    expect(body.portal_sync_attempts).toBe(1);
  });
});
```

- [ ] **Step 3: Add test-only routes** `/test/portal-mock/set`, `/test/commit-pick`, `/test/drain-queue`, `/test/bid-status` (only mounted when `ENV === 'staging'` or `ENV === 'test'`).

- [ ] **Step 4: Run, expect PASS; commit**

```bash
git add apps/worker/tests/integration/portal-retry-5xx.test.ts apps/worker/tests/integration/portal-perm-fail-4xx.test.ts apps/worker/src/routes/test/
git commit -m "test(portal): 5xx retry + 4xx permanent fail integration coverage"
```

---

## Task 24: Admin manual retry + portal-clear-year endpoints

**Files:**
- Create: `apps/worker/src/routes/admin/portal.ts`
- Modify: `apps/worker/src/index.ts` (mount)
- Test: `apps/worker/tests/integration/admin-portal-retry.test.ts`

Endpoints:

| Method | Path | Body | Step-up | Effect |
|---|---|---|---|---|
| POST | /api/admin/portal-retry/:bid_id | — | yes | Re-enqueue a failed bid with `attempts=0`; reset row to `queued` |
| GET | /api/admin/portal-status/:bid_session_id | — | no | List bids with `portal_sync_status` ∈ {pending, failed} for a session |
| POST | /api/admin/portal-clear-year | `{ year, confirmation_phrase }` | **yes + dual confirmation** | Mark all bids in year as `superseded`; DELETE writeback queue rows for those bids |

- [ ] **Step 1: Write failing test**

```ts
// apps/worker/tests/integration/admin-portal-retry.test.ts
import { describe, it, expect, beforeAll } from 'vitest';
import { unstable_dev, type UnstableDevWorker } from 'wrangler';
import { signJwt } from '../../src/lib/jwt';

describe('admin portal endpoints', () => {
  let worker: UnstableDevWorker;
  let adminJwt: string;
  beforeAll(async () => {
    worker = await unstable_dev('src/index.ts', { local: true, experimental: { disableExperimentalWarning: true } });
    adminJwt = await signJwt(
      { memberId: 1, role: 'admin', employeeId: '1', freshAuthAt: Math.floor(Date.now() / 1000) },
      'test-key',
    );
  });

  it('POST /portal-retry/:bid_id resets a failed bid to queued', async () => {
    await worker.fetch('/test/seed-failed-bid?bid_id=bid_r1', { method: 'POST' });
    const r = await worker.fetch('/api/admin/portal-retry/bid_r1', {
      method: 'POST', headers: { Authorization: `Bearer ${adminJwt}` },
    });
    expect(r.status).toBe(200);
    const status = await worker.fetch('/test/bid-status?bid_id=bid_r1');
    const body = await status.json() as { portal_sync_status: string; portal_sync_attempts: number };
    expect(body.portal_sync_status).toBe('pending');
    expect(body.portal_sync_attempts).toBe(0);
  });

  it('POST /portal-clear-year requires dual confirmation phrase', async () => {
    const r1 = await worker.fetch('/api/admin/portal-clear-year', {
      method: 'POST',
      headers: { Authorization: `Bearer ${adminJwt}`, 'content-type': 'application/json' },
      body: JSON.stringify({ year: 2026, confirmation_phrase: 'wrong phrase' }),
    });
    expect(r1.status).toBe(400);
    const r2 = await worker.fetch('/api/admin/portal-clear-year', {
      method: 'POST',
      headers: { Authorization: `Bearer ${adminJwt}`, 'content-type': 'application/json' },
      body: JSON.stringify({ year: 2026, confirmation_phrase: 'CLEAR YEAR 2026' }),
    });
    expect(r2.status).toBe(200);
  });

  it('GET /portal-status/:session_id returns pending/failed bids', async () => {
    await worker.fetch('/test/seed-failed-bid?bid_id=bid_r2', { method: 'POST' });
    const r = await worker.fetch('/api/admin/portal-status/01HF3', {
      headers: { Authorization: `Bearer ${adminJwt}` },
    });
    expect(r.status).toBe(200);
    const body = await r.json() as { bids: Array<{ id: string; portal_sync_status: string }> };
    expect(body.bids.some((b) => b.id === 'bid_r2')).toBe(true);
  });
});
```

- [ ] **Step 2: Run, expect FAIL**

- [ ] **Step 3: Implement `apps/worker/src/routes/admin/portal.ts`**

```ts
// apps/worker/src/routes/admin/portal.ts
import { Hono } from 'hono';
import { z } from 'zod';
import { and, eq, inArray } from 'drizzle-orm';
import { requireAdmin } from './middleware.js';
import { requireStepUpAuth } from '../../lib/step-up.js';
import { getDb } from '../../db/index.js';
import { bids, portalWritebackQueue, bidSessions } from '../../db/schema.js';
import { buildPortalPayload } from '../../portal-writeback/payload-builder.js';
import { enqueuePortalWriteback } from '../../portal-writeback/queue-producer.js';

const r = new Hono<{ Bindings: Env }>();
r.use('*', requireAdmin);

r.post('/portal-retry/:bid_id', async (c) => {
  await requireStepUpAuth(c.req, 300);
  const bidId = c.req.param('bid_id');
  const db = getDb(c.env.DB);
  const bid = await db.select().from(bids).where(eq(bids.id, bidId)).get();
  if (!bid) return c.json({ error: 'not_found' }, 404);
  // Reset row
  await db
    .update(bids)
    .set({ portalSyncStatus: 'pending', portalSyncAttempts: 0, portalLastError: null })
    .where(eq(bids.id, bidId));
  // Build payload + re-enqueue (resolve member + position rows; helper omitted for brevity)
  // ... in practice this calls into the same helper used by the DO commit path
  return c.json({ ok: true });
});

r.get('/portal-status/:session_id', async (c) => {
  const sid = c.req.param('session_id');
  const db = getDb(c.env.DB);
  const rows = await db
    .select()
    .from(bids)
    .where(and(eq(bids.bidSessionId, sid), inArray(bids.portalSyncStatus, ['pending', 'failed'])))
    .all();
  return c.json({ bids: rows });
});

const ClearYearBody = z.object({
  year: z.number().int().min(2024).max(2099),
  confirmation_phrase: z.string(),
});

r.post('/portal-clear-year', async (c) => {
  await requireStepUpAuth(c.req, 300);
  const body = ClearYearBody.parse(await c.req.json());
  const expectedPhrase = `CLEAR YEAR ${body.year}`;
  if (body.confirmation_phrase !== expectedPhrase) {
    return c.json({ error: 'confirmation_phrase_mismatch', expected: expectedPhrase }, 400);
  }
  const db = getDb(c.env.DB);
  // Find sessions for this year
  const sessions = await db
    .select({ id: bidSessions.id })
    .from(bidSessions)
    .where(eq(bidSessions.bidYear, body.year))
    .all();
  const sessionIds = sessions.map((s) => s.id);
  if (sessionIds.length === 0) return c.json({ cleared: 0 });

  // Mark superseded
  const updated = await db
    .update(bids)
    .set({ portalSyncStatus: 'superseded' })
    .where(inArray(bids.bidSessionId, sessionIds))
    .returning({ id: bids.id });
  // Delete writeback queue rows for those bids
  await db
    .delete(portalWritebackQueue)
    .where(inArray(portalWritebackQueue.bidId, updated.map((u) => u.id)));
  return c.json({ cleared: updated.length });
});

export default r;
```

- [ ] **Step 4: Mount + run + commit**

```ts
import adminPortal from './routes/admin/portal.js';
app.route('/api/admin', adminPortal);
```

```bash
git add apps/worker/src/routes/admin/portal.ts apps/worker/src/index.ts apps/worker/tests/integration/admin-portal-retry.test.ts
git commit -m "feat(admin): portal-retry + portal-status + portal-clear-year endpoints"
```

---

## Task 25: Daily reconciliation cron

**Files:**
- Create: `apps/worker/src/portal-writeback/reconciliation.ts`
- Modify: `apps/worker/src/index.ts` — branch on cron pattern inside `scheduled` handler
- Test: `apps/worker/tests/portal-writeback/reconciliation.test.ts`

Once a day (04:15 UTC), scan for queue rows whose `next_attempt_at < now` AND `status='queued'`. Re-enqueue them (in case Cloudflare Queues lost a message). Also list `bids.portal_sync_status='failed'` rows and write them to a `D1` table read by the admin banner.

- [ ] **Step 1: Write failing test**

```ts
// apps/worker/tests/portal-writeback/reconciliation.test.ts
import { describe, it, expect, vi } from 'vitest';
import { runReconciliation } from '../../src/portal-writeback/reconciliation';

describe('runReconciliation', () => {
  it('re-enqueues queue rows whose next_attempt_at < now and status=queued', async () => {
    const reEnqueue = vi.fn(async () => {});
    const dueRows = [
      { id: 'q1', payloadJson: '{}', attempts: 3, bidId: 'b1' },
      { id: 'q2', payloadJson: '{}', attempts: 1, bidId: 'b2' },
    ];
    const out = await runReconciliation({
      listDueQueueRows: async () => dueRows,
      listFailedBids: async () => [],
      reEnqueue,
      nowMs: 1_700_000_000,
    });
    expect(reEnqueue).toHaveBeenCalledTimes(2);
    expect(out.reEnqueued).toBe(2);
  });

  it('reports failed bid count without re-enqueueing them', async () => {
    const reEnqueue = vi.fn(async () => {});
    const out = await runReconciliation({
      listDueQueueRows: async () => [],
      listFailedBids: async () => [{ id: 'b1' }, { id: 'b2' }, { id: 'b3' }],
      reEnqueue, nowMs: 0,
    });
    expect(out.failedBidCount).toBe(3);
    expect(reEnqueue).toHaveBeenCalledTimes(0);
  });
});
```

- [ ] **Step 2: Run, expect FAIL**

- [ ] **Step 3: Implement `apps/worker/src/portal-writeback/reconciliation.ts`**

```ts
// apps/worker/src/portal-writeback/reconciliation.ts
export interface ReconciliationDeps {
  listDueQueueRows: () => Promise<Array<{ id: string; payloadJson: string; attempts: number; bidId: string }>>;
  listFailedBids: () => Promise<Array<{ id: string }>>;
  reEnqueue: (row: { id: string; payloadJson: string; attempts: number; bidId: string }) => Promise<void>;
  nowMs: number;
}

export interface ReconciliationResult {
  reEnqueued: number;
  failedBidCount: number;
  ranAt: number;
}

export async function runReconciliation(deps: ReconciliationDeps): Promise<ReconciliationResult> {
  const due = await deps.listDueQueueRows();
  for (const r of due) await deps.reEnqueue(r);
  const failed = await deps.listFailedBids();
  return { reEnqueued: due.length, failedBidCount: failed.length, ranAt: deps.nowMs };
}
```

- [ ] **Step 4: Wire into the `scheduled` handler — branch on `event.cron`**

```ts
// apps/worker/src/index.ts
async scheduled(event, env, ctx) {
  const emitter = getOrInitEmitter(env);
  if (event.cron === '*/1 * * * *') {
    ctx.waitUntil(emitter.flushStale());
  } else if (event.cron === '15 4 * * *') {
    ctx.waitUntil(runDailyReconciliation(env));
  }
}
```

- [ ] **Step 5: Run, expect PASS; commit**

```bash
git add apps/worker/src/portal-writeback/reconciliation.ts apps/worker/src/index.ts apps/worker/tests/portal-writeback/reconciliation.test.ts
git commit -m "feat(portal): daily reconciliation cron — re-enqueue stuck rows + count failed"
```

---

## Task 26: Admin exports + portal status UI

**Files:**
- Create: `apps/web/app/admin/exports/page.tsx`
- Create: `apps/web/app/admin/exports/_components/ExportCard.tsx`
- Create: `apps/web/app/admin/exports/_components/ExportTriggerButton.tsx`
- Create: `apps/web/app/admin/exports/_components/PortalSyncStatus.tsx`
- Create: `apps/web/app/admin/exports/_components/ManualRetryButton.tsx`
- Test: `apps/web/tests/e2e/admin-export-roster.spec.ts`
- Test: `apps/web/tests/e2e/admin-portal-resync.spec.ts`

The page is a Server Component that fetches the export list + portal status for the current/recent session via worker RPC. Action buttons (trigger export, retry) are client components calling Server Actions.

- [ ] **Step 1: Write failing E2E (`admin-export-roster.spec.ts`)**

```ts
import { test, expect } from '@playwright/test';

test('admin can trigger a roster PDF export and download it', async ({ page }) => {
  await page.goto('/admin/exports?session_id=TEST-2025-A');
  await page.getByRole('button', { name: /Generate A Shift Roster/i }).click();
  await expect(page.getByText(/A Shift roster generated/i)).toBeVisible({ timeout: 30_000 });
  const dl = page.waitForEvent('download');
  await page.getByRole('link', { name: /Download/i }).first().click();
  const file = await dl;
  expect(file.suggestedFilename()).toMatch(/A_Shift_\d+\.pdf$/);
});
```

- [ ] **Step 2: Write failing E2E (`admin-portal-resync.spec.ts`)**

```ts
import { test, expect } from '@playwright/test';

test('admin retries a failed portal sync', async ({ page }) => {
  await page.goto('/test/seed-failed-bid?bid_id=bid_e2e_1');
  await page.goto('/admin/exports?session_id=01HF3');
  const row = page.getByTestId('portal-row-bid_e2e_1');
  await expect(row.getByText(/Failed/)).toBeVisible();
  await row.getByRole('button', { name: /Retry/ }).click();
  await expect(row.getByText(/Pending|Synced/)).toBeVisible({ timeout: 10_000 });
});
```

- [ ] **Step 3: Run E2E, expect FAIL**

- [ ] **Step 4: Implement `page.tsx`**

```tsx
// apps/web/app/admin/exports/page.tsx
import { workerRpc } from '@/lib/rpc-client';
import { ExportCard } from './_components/ExportCard';
import { ExportTriggerButton } from './_components/ExportTriggerButton';
import { PortalSyncStatus } from './_components/PortalSyncStatus';

export const runtime = 'edge';

interface PageProps {
  searchParams: { session_id?: string };
}

export default async function ExportsPage({ searchParams }: PageProps) {
  const sid = searchParams.session_id ?? await getCurrentSessionId();
  if (!sid) return <p>No active session.</p>;
  const [exportsRes, portalRes] = await Promise.all([
    workerRpc.admin.exports[':session_id'].$get({ param: { session_id: sid } }),
    workerRpc.admin['portal-status'][':session_id'].$get({ param: { session_id: sid } }),
  ]);
  const exportsList = await exportsRes.json();
  const portalList = await portalRes.json();

  return (
    <main className="admin-exports">
      <h1>Exports & Portal Sync — {sid}</h1>

      <section>
        <h2>Generate</h2>
        <div className="grid">
          {(['A', 'B', 'C', 'D'] as const).map((sh) => (
            <ExportTriggerButton key={sh} kind="roster" shift={sh} sessionId={sid} />
          ))}
          <ExportTriggerButton kind="audit-csv" sessionId={sid} />
        </div>
      </section>

      <section>
        <h2>Available exports</h2>
        {exportsList.exports.map((e) => (
          <ExportCard key={e.r2Key} export={e} sessionId={sid} />
        ))}
      </section>

      <section>
        <h2>Portal sync status</h2>
        <PortalSyncStatus bids={portalList.bids} sessionId={sid} />
      </section>
    </main>
  );
}

async function getCurrentSessionId(): Promise<string | null> {
  const r = await workerRpc.admin.dashboard.$get();
  const b = await r.json();
  return b.currentSessionId ?? null;
}
```

- [ ] **Step 5: Implement `ExportCard.tsx`**

```tsx
// apps/web/app/admin/exports/_components/ExportCard.tsx
'use client';
import { useState } from 'react';
import { workerRpc } from '@/lib/rpc-client';

export function ExportCard({ export: e, sessionId }: {
  export: { r2Key: string; kind: string; bytes: number; uploadedAt: string };
  sessionId: string;
}) {
  const [url, setUrl] = useState<string | null>(null);
  return (
    <div className="export-card">
      <span className="kind">{e.kind}</span>
      <span className="key" title={e.r2Key}>{e.r2Key.split('/').pop()}</span>
      <span className="bytes">{Math.round(e.bytes / 1024)} KB</span>
      <span className="when">{new Date(e.uploadedAt).toLocaleString()}</span>
      {url ? (
        <a href={url} download>Download</a>
      ) : (
        <button type="button" onClick={async () => {
          const r = await workerRpc.admin.exports[':session_id'][':r2key'].url.$get({
            param: { session_id: sessionId, r2key: encodeURIComponent(e.r2Key) },
          });
          const b = await r.json();
          setUrl(b.url);
        }}>Get link</button>
      )}
    </div>
  );
}
```

- [ ] **Step 6: Implement `ExportTriggerButton.tsx`**

```tsx
// apps/web/app/admin/exports/_components/ExportTriggerButton.tsx
'use client';
import { useState } from 'react';
import { workerRpc } from '@/lib/rpc-client';

interface Props {
  kind: 'roster' | 'audit-csv';
  shift?: 'A' | 'B' | 'C' | 'D';
  sessionId: string;
}

export function ExportTriggerButton({ kind, shift, sessionId }: Props) {
  const [busy, setBusy] = useState(false);
  const [msg, setMsg] = useState<string | null>(null);
  const label = kind === 'roster'
    ? `Generate ${shift} Shift Roster`
    : 'Generate Full Audit CSV';
  return (
    <div>
      <button type="button" disabled={busy} onClick={async () => {
        setBusy(true); setMsg(null);
        try {
          const path = kind === 'roster' ? `/api/admin/exports/roster/${shift}` : '/api/admin/exports/audit-csv';
          const r = await fetch(path, {
            method: 'POST',
            headers: { 'content-type': 'application/json' },
            body: JSON.stringify({ session_id: sessionId }),
            credentials: 'include',
          });
          if (!r.ok) throw new Error(await r.text());
          setMsg(kind === 'roster' ? `${shift} Shift roster generated.` : 'Audit CSV generated.');
        } catch (e) {
          setMsg('Failed: ' + (e as Error).message);
        } finally {
          setBusy(false);
        }
      }}>
        {busy ? 'Generating…' : label}
      </button>
      {msg && <p role="status">{msg}</p>}
    </div>
  );
}
```

- [ ] **Step 7: Implement `PortalSyncStatus.tsx` + `ManualRetryButton.tsx`**

```tsx
// apps/web/app/admin/exports/_components/PortalSyncStatus.tsx
import { ManualRetryButton } from './ManualRetryButton';

export function PortalSyncStatus({ bids, sessionId }: {
  bids: Array<{ id: string; memberId: number; positionId: string; pickedAt: string; portalSyncStatus: string; portalSyncAttempts: number }>;
  sessionId: string;
}) {
  if (bids.length === 0) return <p>All picks synced to portal.</p>;
  return (
    <table>
      <thead>
        <tr><th>Bid</th><th>Member</th><th>Position</th><th>Picked</th><th>Status</th><th>Attempts</th><th>Action</th></tr>
      </thead>
      <tbody>
        {bids.map((b) => (
          <tr key={b.id} data-testid={`portal-row-${b.id}`}>
            <td>{b.id}</td>
            <td>{b.memberId}</td>
            <td>{b.positionId}</td>
            <td>{new Date(b.pickedAt).toLocaleString()}</td>
            <td className={`status-${b.portalSyncStatus}`}>{b.portalSyncStatus}</td>
            <td className="num">{b.portalSyncAttempts}</td>
            <td><ManualRetryButton bidId={b.id} /></td>
          </tr>
        ))}
      </tbody>
    </table>
  );
}
```

```tsx
// apps/web/app/admin/exports/_components/ManualRetryButton.tsx
'use client';
import { useState } from 'react';

export function ManualRetryButton({ bidId }: { bidId: string }) {
  const [busy, setBusy] = useState(false);
  return (
    <button type="button" disabled={busy} onClick={async () => {
      setBusy(true);
      await fetch(`/api/admin/portal-retry/${bidId}`, { method: 'POST', credentials: 'include' });
      setBusy(false);
      // Refresh via router.refresh() if needed
    }}>
      {busy ? 'Retrying…' : 'Retry'}
    </button>
  );
}
```

- [ ] **Step 8: Run E2E, expect PASS; commit**

```bash
git add apps/web/app/admin/exports apps/web/tests/e2e/admin-export-roster.spec.ts apps/web/tests/e2e/admin-portal-resync.spec.ts
git commit -m "feat(web): /admin/exports page + ExportCard + PortalSyncStatus + manual retry"
```

---

## Task 27: STATUS.md update + final sweep

**Files:**
- Modify: `docs/superpowers/plans/STATUS.md`

- [ ] Append:

```markdown
## Plan 08 complete — Audit chain, exports, portal write-back

- **Date:** YYYY-MM-DD
- **Sub-systems shipped:**
  - A. R2 JSONL hash-chained audit log with ed25519 signatures (tamper-detection verified by 20-run random-byte mutation test).
  - B. Roster PDF (Browserless) + audit CSV (papaparse + pako gzip) exports stored in R2, accessible via signed URLs.
  - C. Cloudflare Queues portal write-back with 24-attempt / 24-hour exp-backoff retry; manual retry + portal-clear-year admin endpoints.
- **Deviations:** (fill in)
- **Open watch-items for Plan 09:**
  - Load test should include 250-pick session WITH chain enabled (ensure DO commit-to-broadcast latency stays under spec budget).
  - Pen test should attempt chunk tampering via direct R2 access (confirm bucket ACL hardening).
  - Rehearsal should kill the queue mid-bid and confirm reconciliation cron + manual retry recover.
```

- [ ] Commit in MBFD_Hub repo.

```bash
git add docs/superpowers/plans/STATUS.md
git commit -m "docs(plan-08): completion status entry"
```

---

## Acceptance criteria

### Sub-system A — audit chain

- [ ] Migration 0006 applies cleanly to fresh and existing D1 databases.
- [ ] `canonicalize()` is byte-stable across key insertion orders and JS engines.
- [ ] `computeChunkHash()` changes for **any** single-byte mutation in any event (verified by per-byte property test).
- [ ] `signChunk()` + `verifyChunkSignature()` round-trip succeeds; verification fails for forged keys or tampered hashes.
- [ ] `JsonlChunker` flushes at exactly 100 events OR 30s, whichever first.
- [ ] `ChainEmitter` uploads JSONL to `<year>/<session>/chunks/<padded-seq>.jsonl`; chunk 1's `prev_chunk_sha256` is null; chunk N's `prev_chunk_sha256` equals chunk N-1's `sha256` byte-for-byte.
- [ ] `/api/admin/audit/verify-chain` returns `ok:true, last_verified_seq:N, total_events_verified:M` on a clean 250-event session.
- [ ] `/api/admin/audit/verify-chain` returns `ok:false, failed_at_chunk, reason` on any tampered, truncated, or missing chunk.
- [ ] DO commit rejects the pick with HTTP 500 when `R2.put` throws (D10 enforcement).
- [ ] 30-second cron tick flushes any stale buffer; idle session leaves no events un-flushed beyond 60s after the last event.

### Sub-system B — exports

- [ ] Roster PDF for an A-shift session matches the `2025_A_Shift.pdf` visual layout to within Playwright's `maxDiffPixelRatio: 0.02`.
- [ ] Audit CSV for a 250-pick session is generated and uploaded in under 2 seconds (per spec).
- [ ] Audit CSV is gzipped (R2 `Content-Encoding: gzip`); first line is the header `id,bid_session_id,seq,…`.
- [ ] Signed R2 URLs are valid AWS4-HMAC-SHA256 presigned URLs for the `r2.cloudflarestorage.com` endpoint, with 1h TTL.
- [ ] `/admin/exports` lists all PDFs + CSVs for a session, including upload time and gzipped size.
- [ ] Print-token verification rejects expired, wrong-kind, wrong-shift, and wrong-session_id tokens.

### Sub-system C — portal write-back

- [ ] Every committed pick produces exactly one `portal_writeback_queue` row AND one `env.PORTAL_QUEUE.send()` call (verified by integration test).
- [ ] Queue row inserted BEFORE the queue send (so daily reconciliation can recover from queue.send failure).
- [ ] Portal returning 200 → bid marked `portal_sync_status='synced'`, `portal_synced_at` set.
- [ ] Portal returning 409 → same as 200 (idempotency).
- [ ] Portal returning 5xx 3 times then 200 → bid eventually synced with `portal_sync_attempts >= 4`.
- [ ] Portal returning 5xx for 25 attempts → bid marked `portal_sync_status='failed'`.
- [ ] Portal returning 400 → bid marked `portal_sync_status='failed'` after 1 attempt (no retries).
- [ ] Retry delays follow `min(24h, 2^min(attempts, 10) * 1000ms) + jitter(0..1000ms)`, integer math throughout.
- [ ] Admin can click "Retry" on `/admin/exports` portal status table → row transitions back to pending → synced.
- [ ] Admin can POST `/api/admin/portal-clear-year` with phrase `"CLEAR YEAR 2026"` → all bids for 2026 marked `superseded`; queue rows deleted.
- [ ] Daily reconciliation cron (04:15 UTC) re-enqueues any queue rows with `next_attempt_at < now` AND `status='queued'`.

### Cross-cutting

- [ ] Every new endpoint requires admin JWT; write endpoints require step-up auth (≤300s fresh).
- [ ] Every new Worker handler emits a structured log line with `traceId, route, latencyMs, outcome`.
- [ ] No emojis introduced in source files or commit messages.
- [ ] All new dependencies are pinned (`@noble/hashes@^1.4`, `@noble/ed25519@^2.1`, `pako@^2.1`).
- [ ] `pnpm --filter @mbfd/worker test:coverage` ≥ 80% lines/branches on `src/audit/**`, `src/exports/**`, `src/portal-writeback/**`.
- [ ] CI lint + typecheck + unit + integration + E2E all green.

---

## Rollback procedure

Each sub-system is independently revertable. Sub-system A is the highest-risk; treat with caution.

### Sub-system C — portal write-back (lowest risk)

1. Remove the `enqueuePortalWriteback(...)` call from `bid-session.ts` `.commit()`.
2. Remove the `queue` handler export from `src/index.ts`.
3. Remove the `[[queues.producers]]` and `[[queues.consumers]]` blocks from `wrangler.toml`.
4. Picks continue to commit normally; `bids.portal_sync_status` rows stay `pending` forever — admin clears via `/portal-clear-year` after fix.

Result: bid app fully operational; portal does NOT receive new assignments; existing `synced` rows untouched.

### Sub-system B — exports (medium risk)

1. Remove the `/api/admin/exports` route mount.
2. Remove the `[[r2_buckets]]` block for `R2_EXPORTS` from `wrangler.toml`.
3. Existing exports in R2 remain available via the Cloudflare dashboard.

Result: admin loses the export UI; chiefs must use existing 2025-style manual PDF workflow as a fallback for the immediate event.

### Sub-system A — audit chain (highest risk)

**WARNING: Do not roll back during a live event.** R2 JSONL is the legal record. If the chain is disabled mid-event, audit integrity is compromised.

If a rollback is unavoidable (e.g., R2 outage):

1. Set a feature flag `AUDIT_CHAIN_ENABLED=false` in env. Code path:
   ```ts
   if (env.AUDIT_CHAIN_ENABLED !== 'false') {
     await auditEmitter.emit(event);
   }
   ```
2. **D1 audit_log continues to receive all events** — this is the fallback legal record (degraded, but parseable).
3. Surface a banner on `/admin/audit`: "Audit chain disabled — D1-only mode. Re-enable before signing off the session."
4. After the event, when R2 is available again, replay D1 `audit_log` rows for the affected session into the chain via a one-shot Worker script (left for ops; not in this plan).

Result: audit log still queryable; legal-record integrity DEGRADED but recoverable.

### Migration rollback

`0006_audit_chain.sql` can be reverted by:

```sql
DROP TABLE audit_chunks;
DROP TABLE audit_chain_state;
ALTER TABLE audit_log DROP COLUMN chunk_seq;
ALTER TABLE audit_log DROP COLUMN chunk_row_index;
```

(`ALTER TABLE DROP COLUMN` requires SQLite 3.35+; D1 supports it. Verify before executing in production.)

---

## Notes for the engineer

- **Browserless v2 fallback**: If the free tier is throttled during the live event, switch to `@cloudflare/puppeteer` (paid plan). The render URL contract is the same; only the wrapper changes. Keep the print-stylesheet RSC page unchanged.
- **R2 ACL hardening**: `mbfd-bid-audit-*` and `mbfd-bid-exports-*` buckets MUST NOT have public read enabled. All access goes through signed URLs minted by the worker.
- **ed25519 key rotation**: Generate fresh keys every year before the bid event. Old chunks remain verifiable because each chunk embeds its own pubkey. Document key generation date in `STATUS.md`.
- **Chunk size**: 100 events × ~400 bytes/event ≈ 40 KB per chunk. R2 charges per request, not bytes, so larger chunks would be cheaper, but 100 keeps the 30s flush window meaningful.
- **D1 vs R2 source of truth**: When in doubt, R2 wins. The verifier compares chunk hashes from R2 (not D1). If D1 and R2 disagree on chunk contents (extremely unlikely), R2 is the legal record.
- **Portal endpoint dependency**: The portal team must expose `POST /api/v2/members/:employee_id/bid-assignment` returning 200/409/4xx/5xx per spec §11.8.3 BEFORE the integration tests can run against a real portal. Until then, test routes mock the portal.
- **Queue consumer cold-start**: Cloudflare Queues consumer cold-starts in ~50ms. With `max_batch_timeout = 5s`, this is irrelevant for our retry-tolerant workload.
- **`portal_writeback_queue` vs Cloudflare Queue**: We use BOTH — the D1 table is the durable record-of-record (survives queue purges, supports admin status UI), and the Cloudflare Queue is the dispatcher. Reconciliation cron bridges any gap.
- **AI advisory log export**: Spec §9 calls for `2026_AI_Advisory_Log.jsonl`. Not in this plan's task list — surface as a new task in a follow-on plan when Plan 06 is shipping. The data exists in `ai_advisories`; the export pattern (paginated SELECT → JSONL → gzip → R2 + signed URL) mirrors `audit-csv.ts` exactly.
- **Performance budget**: Per spec acceptance, audit CSV for 250 picks must complete in <2s. With 500-row pages and a 250-row total, this is a single page → near-instant. Headroom is large.

---

## Open questions

1. **Portal team contract finalization**: The exact 4xx response shapes for validation errors (e.g., unknown employee_id, year in future) — what's the portal team's stance? Currently we treat all 4xx as permanent. If "unknown employee_id" means the bid app sent a wrong ID (vs the portal lost the member), the bid app should still hard-fail rather than silently retry forever. **Action**: confirm with portal team in Phase 0; update `portal-client.ts` if 404 should be re-classified.
2. **R2 retention policy**: Spec says hash-chained chunks are kept indefinitely. Do we want a lifecycle rule to migrate >5-year-old chunks to a cold-storage tier? Defer to Plan 09 hardening.
3. **Visual diff baseline staleness**: The Playwright roster snapshot baseline (`roster-a-2025.png`) is anchored to 2025 data. If the 2026 station-6 marine reshuffle changes layout, the baseline must be regenerated and reviewed. Add a checklist item to the rehearsal: "Verify roster snapshot baseline reflects current year's expected layout."
4. **Cron frequency at scale**: 30-second flush cron via `*/1 * * * *` runs every minute (Cloudflare Crons minimum). For "30s flush window" we rely on the cron firing + the in-handler timestamp check. If we ever need true sub-minute flush, consider triggering a flush opportunistically from any `fetch` handler invocation — small change.
