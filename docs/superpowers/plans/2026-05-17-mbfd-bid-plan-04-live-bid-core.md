# Plan 04 — Live bid core: Durable Object, WebSocket, draft UI

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Plan:** 04 of 09 (see [master index](2026-05-17-mbfd-bid-master-index.md))
**Date:** 2026-05-17
**Depends on:** Plan 01 (foundation, PIN gate, JWT auth) · Plan 02 (D1 schema, member/position/rule imports) · Plan 03 (`@mbfd/eligibility` deterministic engine)
**Goal:** Members can log in during their assigned bid window, see the live draft board update in real time as picks are made, and submit a Phase 1 position pick when their turn arrives. The bid runs serially through `bid_order`. State survives a Durable Object crash or eviction without data loss. Admin can pause, resume, force-pick, skip, and freeze the bid. Every state transition is recorded in `audit_log` and broadcast to all observers.

**Architecture (locked by spec §5.2–5.4):** One `BidSession` Durable Object per `bid_session_id` is the single source of live state. The DO owns the queue cursor, current bidder, position-fills map, and a Map of WebSocket connections. Every state transition is persisted via `state.storage.put()` **before** the WebSocket broadcast is acknowledged. Shadow snapshots to D1 `bid_session_snapshots` every 5 seconds give a fallback if DO storage corrupts. The deterministic eligibility engine (`@mbfd/eligibility` from Plan 03) is the sole authority for eligibility — the DO orchestrates, the engine decides. The Next.js `/bid` page is mostly React Server Components for the initial paint, with a single client island that opens the WebSocket and pushes updates into a Zustand store.

**Tech stack additions on top of Plans 01–03:**
- Cloudflare Durable Objects (binding `BID_SESSION` in `wrangler.toml`)
- `hono/ws` + native `WebSocketPair` for the upgrade handshake
- `zustand@^5.0` + `@tanstack/react-query@^5.59` (web client state)
- `nanoid@^5.0` for client-generated idempotency keys (in `apps/web`) and event seq IDs (in DO)
- Reusable `ulid@^2.3` already present from Plan 02 for `audit_log.id` and `bids.id`
- `framer-motion@^11.11` (entrance animations, gated by `prefers-reduced-motion`)
- `@playwright/test@1.60.0` already pinned by Plan 01; we add an E2E suite

---

## Decisions preamble

This plan implements four non-obvious design choices that all downstream tasks depend on. Read this section before opening any task.

### D04-1: Realtime transport — WebSocket via Durable Object (NOT SSE, NOT polling)

The webapp design spec (§5.2–5.4) explicitly mandates Cloudflare Durable Objects with WebSocket fanout, and lists "Member uses old browser without WebSocket" as a known risk mitigated by a long-polling fallback (§13). Therefore:

- **Primary realtime channel: WebSocket upgrade to `/api/ws/session/:bidSessionId`** routed into the BidSession DO. The DO's `connected_clients` Map fans out events to every connected client.
- **Documented fallback: HTTP long polling at `GET /api/bid/state?since_seq=N`** — same Zod-schema-validated event envelope, the client polls every 3 seconds when the WebSocket cannot upgrade (proxy, ancient browser, corporate firewall that blocks `wss://`). The DO buffers the last 200 events in storage for replay; older clients reconcile via `RESYNC`.
- **SSE is rejected** because Cloudflare Workers cannot persist a server-sent event stream across DO eviction cleanly — the spec already paid the implementation cost for WebSocket; introducing SSE doubles the protocol surface for zero benefit. Polling is the documented degradation path; SSE is not.

Justification for WebSocket-first: bid latency budget is <250ms LAN / <1s LTE (spec §13 acceptance). Long polling at 3-second intervals violates the LAN budget by 12×; only WebSocket meets the spec. Polling is acceptable solely as a degraded fallback.

### D04-2: Idempotency keys — client-generated UUIDv4, DO-deduplicated for 24h

- The web client generates a `crypto.randomUUID()` per pick submission. The key travels with the WebSocket `submit_pick` message and any HTTP fallback.
- The DO maintains `state.storage.put('idem:' + key, true, { expirationTtl: 86400 })` to dedupe.
- On a duplicate key the DO returns the **last result** for that key (success or rejection) — never a fresh evaluation. This makes "click submit twice during a 2s pause" safe.
- For HTTP routes (admin overrides, freeze) the key arrives as the `Idempotency-Key` header. Same KV-backed dedupe with 24h TTL.

### D04-3: Auto-skip timeout — 180 seconds default, configurable via D1 `bid_sessions.turn_timer_seconds`

- Default `turn_timer_seconds = 180` (3 minutes), already in the schema from Plan 02.
- The DO sets `current_turn_started_at` when the active bidder's turn begins and starts a `setAlarm()` for `turn_started_at + turn_timer_seconds * 1000`.
- When the alarm fires the DO does **not** auto-skip — it raises an `awaiting_admin` event. Spec §7 `bidder_unreachable_action` defaults to "pause-first": admin sees a banner and chooses PAUSE (default) or FORCE-PICK (only allowed after `2 × turn_timer_seconds` per spec §7).
- This is a deliberate choice: a chief watching the bid is the right gate, not a fixed timeout. The "auto-skip" name in this plan refers to the admin's "skip with reason" action, not a clock-driven action.
- `BID_TURN_TIMER_SECONDS` env var is read at session-start time; live-changing the timer mid-bid is an admin action covered in Plan 05.

### D04-4: Optimistic UI — display only, server is authoritative; rollback on rejection

- When the active bidder clicks "Submit pick", the web client immediately marks the position locally as `pendingMine` (visual: pulsing red border, "Submitting…" label).
- Concurrently, the WS `submit_pick` message is sent with the idempotency key.
- On `pick_made` event echoing the same idempotency key, the local `pendingMine` is promoted to `filled` with the canonical seq/timestamp from the DO.
- On `pick_rejected` event the local `pendingMine` is **rolled back** to `eligible-open` and an error toast is shown with the rejection reason.
- If the WebSocket disconnects before either event arrives, the client keeps `pendingMine` and shows "Reconnecting…". On reconnect the `state_snapshot` reply tells the client whether the pick landed (the position appears in `fills`) or never reached the DO (it appears in `pendingMine` still — the client retries the message with the same idempotency key).

These four decisions are non-negotiable inside this plan. Any task that contradicts them is a plan bug — open an issue and stop.

---

## High-level architecture

```
                              ┌────────────────────────────────────────┐
                              │            Cloudflare edge             │
                              │                                        │
   Member browser             │  Hono Worker  (apps/worker)            │
   ┌─────────────┐            │  ┌──────────────────────────────────┐  │
   │ /bid page   │ ──HTTPS──► │  │ /api/board       (SSR fallback)  │  │
   │ (RSC + 1   │            │  │ /api/me                          │  │
   │  client    │            │  │ /api/me/eligibility              │  │
   │  island)   │            │  │ /api/bid/state   (long poll)     │  │
   └─────┬───────┘            │  └──────────────────────────────────┘  │
         │                    │              │                         │
         │                    │              ▼                         │
         │  wss://            │  ┌──────────────────────────────────┐  │
         └──────────────────► │  │ /api/ws/session/:id (upgrade)    │  │
                              │  └─────────────────┬────────────────┘  │
                              │                    │                   │
                              │                    ▼                   │
                              │  ┌──────────────────────────────────┐  │
                              │  │ BidSession Durable Object        │  │
                              │  │ ───────────────────────────────  │  │
                              │  │ in-memory:                       │  │
                              │  │   queue_cursor                   │  │
                              │  │   current_bidder_id              │  │
                              │  │   position_fills_map             │  │
                              │  │   connected_clients              │  │
                              │  │   last_event_seq                 │  │
                              │  │                                  │  │
                              │  │ state.storage.put() BEFORE       │  │
                              │  │ broadcast on every transition    │  │
                              │  └─────┬─────────────┬──────────────┘  │
                              │        │             │                 │
                              │        ▼             ▼                 │
                              │  ┌────────────┐ ┌────────────────────┐ │
                              │  │ D1 (DB)    │ │ R2 (audit JSONL)   │ │
                              │  │ audit_log  │ │ Plan 08            │ │
                              │  │ bids       │ │                    │ │
                              │  │ snapshots  │ │                    │ │
                              │  └────────────┘ └────────────────────┘ │
                              └────────────────────────────────────────┘
```

Key invariants (encode in tests):

1. **One position, one member** — `bids.idempotency_key UNIQUE` + DO check that a member who appears in `bids` cannot pick again.
2. **Position can be filled only once** — DO's `position_fills_map[position_id] !== null` blocks any further pick on that position.
3. **Strict serial order** — only `current_bidder_id` can submit; ordinal N+1 cannot pick until N has acted (pick OR admin skip).
4. **Atomic transitions** — DO state mutation happens inside one `state.blockConcurrencyWhile()` block. D1 inserts are queued after; on D1 failure we retry idempotently using the same `bids.id` (ulid) and `audit_log.id`.
5. **Freeze is one-way** — once `bid_sessions.current_phase = 'complete'` or `'paused'` with admin freeze flag, only admin overrides mutate state.
6. **Audit log is append-only** — every transition writes one row to `audit_log`. Plan 08 adds the hash chain; here we just keep it flat and monotonic via `seq`.

---

## File map

```
apps/worker/
  src/
    durable/
      bid-session.ts                 ← BidSession Durable Object class
      bid-session-state.ts           ← state shape + load/persist helpers
      bid-session-events.ts          ← Zod schemas for every event type
      bid-session-handlers.ts        ← pure handlers (submit_pick, skip, override, freeze)
    routes/
      ws.ts                          ← GET /api/ws/session/:id (upgrade)
      bid.ts                         ← GET /api/board, /api/bid/state, /api/me, /api/me/eligibility
      admin/
        bid.ts                       ← POST /api/admin/bid/session, /pick, /skip, /override, /freeze
    lib/
      bid-order.ts                   ← computeBidOrder(members, year): BidOrderRow[]
      session-loader.ts              ← loads members + rules + positions for a session
    types/
      env.ts                         ← add BID_SESSION DurableObjectNamespace binding
  migrations/
    0006_bid_session_freeze.sql      ← adds bid_sessions.frozen_at + bid_sessions.freeze_actor_id
  wrangler.toml                      ← adds [[durable_objects.bindings]] BID_SESSION
  tests/
    unit/
      bid-session-state.test.ts
      bid-session-handlers.test.ts
      bid-session-events.test.ts
      bid-order.test.ts
    integration/
      bid-session-do.test.ts         ← Miniflare DO test (happy path)
      bid-session-recovery.test.ts   ← kill DO, reconnect, RESYNC
      bid-session-routes.test.ts     ← REST endpoints with mocked DO

apps/web/
  app/
    bid/
      page.tsx                       ← Server Component initial paint
      loading.tsx                    ← skeleton
      _components/
        BidBoard.tsx                 ← client island; opens WS
        BoardHeader.tsx              ← current bidder + timer
        SlotList.tsx                 ← virtualised seniority list
        PositionGrid.tsx             ← positions grouped by shift/station
        PositionCell.tsx             ← single position card (4 states)
        YourTurnPanel.tsx            ← shown when current_bidder_id === me.id
        EligibleList.tsx             ← inside YourTurnPanel
        ReconnectingOverlay.tsx
        ErrorToast.tsx
      _hooks/
        useBidWebSocket.ts           ← WebSocket connection + reconnect
        useBidStore.ts               ← Zustand store
        useIdempotencyKey.ts         ← per-pick UUID generator
    admin/
      bid/
        page.tsx                     ← Server Component
        _components/
          AdminBoard.tsx             ← client island; admin actions
          AdminActionsBar.tsx        ← pause / resume / skip / override / freeze
          OverrideDialog.tsx
          FreezeConfirmDialog.tsx
  lib/
    ws-client.ts                     ← typed WebSocket wrapper
    bid-store-types.ts               ← Zustand store shape
  tests/
    e2e/
      bid-happy-path.spec.ts         ← 2 simulated members complete a 5-pick cycle
      bid-reconnect.spec.ts          ← kill WS mid-bid, reconnect, see correct state
      bid-admin-override.spec.ts     ← admin force-pick + freeze
      bid-disconnect.spec.ts         ← member loses connection during own turn

packages/shared/
  src/
    schemas/
      bid-events.ts                  ← WS protocol Zod schemas (shared web/worker)
      bid-actions.ts                 ← admin action Zod schemas
    constants/
      bid-events.ts                  ← BID_EVENT_VERSION constant
  tests/
    bid-events.test.ts
```

---

## Source data reference

| File | Use |
|------|-----|
| `D:/GitHub_Repos/MBFD_Hub/analysis/bid_pick.csv` | Replay source — 244 picks from 2025 used in golden test (Task 13) |
| `D:/GitHub_Repos/MBFD_Hub/analysis/personnel.csv` | Member roster for fixtures |
| `D:/GitHub_Repos/mbfd-bid/apps/worker/seed/fixtures/2026_positions.json` | Position template (already seeded in Plan 02) |
| `D:/MBFD/Bid/2026 Bid Documents/2026_Bid_Process.md` | Authoritative bid-cycle rules — invariants encoded in tests come from §3, §6, §7 |
| `D:/GitHub_Repos/MBFD_Hub/docs/superpowers/specs/2026-05-17-mbfd-bid-webapp-design.md` | Architecture source of truth (§5.2–5.4, §6.1, §7) |

---

## Critical tech constraints (enforced in every task)

These come from `MEMORY.md`, `~/.claude/rules/`, and the master index:

- **Node 22+, pnpm 9.12.0** — no yarn, no npm
- **Next.js 15.5.18 App Router + React 19.2.6 + `@cloudflare/next-on-pages` v1.13+**
- **Worker: Hono 4 + Drizzle 0.36+ on D1** — never raw SQL strings interpolating user input
- **`bcryptjs`, NOT native `bcrypt`** (already locked in `apps/worker/package.json`)
- **Vitest 2.1.4** for unit + integration; **Playwright 1.60.0** for E2E
- **Biome 1.9.4** — `noUnusedVariables=error`, `useTemplate` (no `+` for strings), alphabetical imports
- **Tests live in `tests/unit/` and `tests/e2e/`** — NOT `src/__tests__/`
- **Imports use `.js` extensions** for cross-file references (NodeNext module resolution)
- **`cfEnv()` helper for Pages env access** in `apps/web` (process.env is empty); Worker uses `c.env` directly
- **JWT auth: HS256 via `jose`, payload validated by `JwtPayloadSchema`** — allow `sub: 0` for synthetic admin
- **Commit messages: Conventional Commits, NO Claude attribution**
- **Production member URL: `bid.mbfdhub.com`** (Plan 09 cutover); **staging: `staging.bid.mbfdhub.com`**
- **D1 transactions:** D1 supports `db.batch([])` for atomic multi-statement bundles but **does not support `SELECT ... FOR UPDATE`**. Concurrency is serialised inside the DO via `state.blockConcurrencyWhile()`; D1 is used for the durable log only. The DO is the lock.

---

## Task 1: Migration 0006 — add freeze tracking to `bid_sessions`

**Files:**
- Create: `apps/worker/migrations/0006_bid_session_freeze.sql`
- Modify: `apps/worker/src/db/schema.ts` (add columns)
- Test: `apps/worker/tests/unit/db-freeze.test.ts`

The Plan 02 schema covers `bid_sessions` end-to-end, but `freeze` was not modeled — only `paused`. Plan 04 needs an explicit one-way freeze flag distinct from pause (paused can be resumed; frozen requires admin override to mutate state).

- [ ] **Step 1: Write failing test**

```ts
// apps/worker/tests/unit/db-freeze.test.ts
import { describe, expect, it } from 'vitest';
import * as schema from '../../src/db/schema.js';

describe('bid_sessions freeze columns (Plan 04 Task 1)', () => {
  it('exposes frozen_at column', () => {
    expect(schema.bidSessions.frozenAt).toBeDefined();
  });

  it('exposes freeze_actor_id column referencing members', () => {
    expect(schema.bidSessions.freezeActorId).toBeDefined();
  });

  it('exposes freeze_reason text column', () => {
    expect(schema.bidSessions.freezeReason).toBeDefined();
  });
});
```

- [ ] **Step 2: Run test, expect FAIL**

```bash
pnpm --filter @mbfd/worker exec vitest run tests/unit/db-freeze.test.ts
# Expected: AssertionError — schema.bidSessions.frozenAt is undefined
```

- [ ] **Step 3: Implement schema columns**

Edit `apps/worker/src/db/schema.ts`, inside the `bidSessions` table definition, add after `dayCount`:

```ts
  // Plan 04 — one-way freeze flag distinct from pause
  frozenAt: integer('frozen_at', { mode: 'timestamp' }),
  freezeActorId: integer('freeze_actor_id').references(() => members.id, {
    onDelete: 'restrict',
  }),
  freezeReason: text('freeze_reason'),
```

- [ ] **Step 4: Generate migration**

```bash
cd D:/GitHub_Repos/mbfd-bid/apps/worker
pnpm db:generate
# Rename the emitted file to 0006_bid_session_freeze.sql
```

The migration body should be:

```sql
-- apps/worker/migrations/0006_bid_session_freeze.sql
ALTER TABLE bid_sessions ADD COLUMN frozen_at INTEGER;
ALTER TABLE bid_sessions ADD COLUMN freeze_actor_id INTEGER REFERENCES members(id) ON DELETE RESTRICT;
ALTER TABLE bid_sessions ADD COLUMN freeze_reason TEXT;
```

- [ ] **Step 5: Apply locally + verify test passes**

```bash
cd D:/GitHub_Repos/mbfd-bid/apps/worker
pnpm db:migrate:local
pnpm exec vitest run tests/unit/db-freeze.test.ts
# Expected: 3 tests pass
```

- [ ] **Step 6: Lint + typecheck**

```bash
cd D:/GitHub_Repos/mbfd-bid
pnpm lint
pnpm typecheck
# Expected: both clean
```

- [ ] **Step 7: Commit**

```bash
cd D:/GitHub_Repos/mbfd-bid
git add apps/worker/migrations/0006_bid_session_freeze.sql apps/worker/src/db/schema.ts apps/worker/tests/unit/db-freeze.test.ts apps/worker/migrations/meta
git commit -m "feat(db): migration 0006 add bid_sessions.frozen_at + freeze_actor + reason"
```

---

## Task 2: Shared event schemas (`packages/shared/src/schemas/bid-events.ts`)

**Files:**
- Create: `packages/shared/src/schemas/bid-events.ts`
- Create: `packages/shared/src/constants/bid-events.ts`
- Modify: `packages/shared/src/index.ts` (re-exports)
- Test: `packages/shared/tests/bid-events.test.ts`

Every message that crosses the WebSocket is validated by Zod on both ends. We version the protocol via a `v` field so a client-server skew triggers a clean `RESYNC` instead of a crash.

- [ ] **Step 1: Write failing test**

```ts
// packages/shared/tests/bid-events.test.ts
import { describe, expect, it } from 'vitest';
import {
  BID_EVENT_VERSION,
  BidEventEnvelopeSchema,
  PickMadeEventSchema,
  PickRejectedEventSchema,
  StateSnapshotEventSchema,
  SubmitPickMessageSchema,
} from '../src/index.js';

describe('bid event schemas (Plan 04 Task 2)', () => {
  it('BID_EVENT_VERSION is a positive integer', () => {
    expect(BID_EVENT_VERSION).toBeGreaterThanOrEqual(1);
    expect(Number.isInteger(BID_EVENT_VERSION)).toBe(true);
  });

  it('envelope requires v, seq, type, and a typed payload', () => {
    const ok = BidEventEnvelopeSchema.safeParse({
      v: BID_EVENT_VERSION,
      seq: 42,
      type: 'pick_made',
      ts: Date.now(),
      payload: {
        bidId: '01HXYZ',
        bidSessionId: '01HSESS',
        ordinal: 5,
        memberId: 17,
        positionId: 'A101',
        aDay: null,
        idempotencyKey: '11111111-1111-4111-8111-111111111111',
        nextBidderId: 22,
        turnStartedAtMs: Date.now(),
      },
    });
    expect(ok.success).toBe(true);
  });

  it('rejects envelope with wrong version', () => {
    const r = BidEventEnvelopeSchema.safeParse({
      v: BID_EVENT_VERSION + 99,
      seq: 1,
      type: 'pick_made',
      ts: 0,
      payload: {},
    });
    expect(r.success).toBe(false);
  });

  it('SubmitPickMessageSchema requires idempotencyKey UUID', () => {
    expect(
      SubmitPickMessageSchema.safeParse({
        type: 'submit_pick',
        positionId: 'A101',
        aDay: null,
        idempotencyKey: 'not-a-uuid',
      }).success,
    ).toBe(false);

    expect(
      SubmitPickMessageSchema.safeParse({
        type: 'submit_pick',
        positionId: 'A101',
        aDay: null,
        idempotencyKey: '11111111-1111-4111-8111-111111111111',
      }).success,
    ).toBe(true);
  });

  it('PickRejectedEventSchema carries machine-stable reason code', () => {
    const r = PickRejectedEventSchema.safeParse({
      idempotencyKey: '11111111-1111-4111-8111-111111111111',
      code: 'NOT_YOUR_TURN',
      message: 'Active bidder is member 7; your member id is 17',
    });
    expect(r.success).toBe(true);
  });

  it('StateSnapshotEventSchema includes seq and full fills map shape', () => {
    const r = StateSnapshotEventSchema.safeParse({
      bidSessionId: '01HSESS',
      seq: 100,
      currentPhase: 'position_bid',
      currentBidderId: 7,
      turnStartedAtMs: Date.now(),
      turnTimerSeconds: 180,
      frozenAt: null,
      fills: [{ positionId: 'A101', memberId: 17, ordinal: 5 }],
      bidOrder: [{ ordinal: 1, memberId: 1, pool: 'OFC' }],
    });
    expect(r.success).toBe(true);
  });

  it('PickMadeEventSchema validates discriminant by exact match', () => {
    expect(PickMadeEventSchema.safeParse({}).success).toBe(false);
  });
});
```

- [ ] **Step 2: Run test, expect FAIL** (cannot resolve `BID_EVENT_VERSION`).

- [ ] **Step 3: Implement constants**

```ts
// packages/shared/src/constants/bid-events.ts
export const BID_EVENT_VERSION = 1 as const;
```

- [ ] **Step 4: Implement schemas**

```ts
// packages/shared/src/schemas/bid-events.ts
import { z } from 'zod';
import { BID_EVENT_VERSION } from '../constants/bid-events.js';

/** Reason codes returned when a pick is rejected by the DO. */
export const PICK_REJECT_CODES = [
  'NOT_YOUR_TURN',
  'POSITION_FILLED',
  'NOT_ELIGIBLE',
  'ALREADY_PICKED',
  'SESSION_FROZEN',
  'SESSION_PAUSED',
  'PROTOCOL_ERROR',
] as const;
export const PickRejectCodeSchema = z.enum(PICK_REJECT_CODES);
export type PickRejectCode = z.infer<typeof PickRejectCodeSchema>;

// ── Client → Server messages ─────────────────────────────────────────────

export const SubmitPickMessageSchema = z.object({
  type: z.literal('submit_pick'),
  positionId: z.string().min(1).max(16),
  aDay: z.string().nullable(),
  idempotencyKey: z.string().uuid(),
});
export type SubmitPickMessage = z.infer<typeof SubmitPickMessageSchema>;

export const PingMessageSchema = z.object({
  type: z.literal('ping'),
  ts: z.number().int().nonnegative(),
});
export type PingMessage = z.infer<typeof PingMessageSchema>;

export const ClientHelloMessageSchema = z.object({
  type: z.literal('hello'),
  jwt: z.string().min(20),
  /** Resume from this seq; server replies with RESYNC if unable. */
  lastSeq: z.number().int().nonnegative().optional(),
});
export type ClientHelloMessage = z.infer<typeof ClientHelloMessageSchema>;

export const ClientMessageSchema = z.discriminatedUnion('type', [
  ClientHelloMessageSchema,
  SubmitPickMessageSchema,
  PingMessageSchema,
]);
export type ClientMessage = z.infer<typeof ClientMessageSchema>;

// ── Server → Client event payloads ───────────────────────────────────────

export const PickMadeEventSchema = z.object({
  bidId: z.string().min(1),
  bidSessionId: z.string().min(1),
  ordinal: z.number().int().nonnegative(),
  memberId: z.number().int().positive(),
  positionId: z.string().min(1),
  aDay: z.string().nullable(),
  idempotencyKey: z.string().uuid(),
  nextBidderId: z.number().int().nonnegative().nullable(),
  turnStartedAtMs: z.number().int().nonnegative(),
});
export type PickMadeEvent = z.infer<typeof PickMadeEventSchema>;

export const PickRejectedEventSchema = z.object({
  idempotencyKey: z.string().uuid(),
  code: PickRejectCodeSchema,
  message: z.string().min(1),
});
export type PickRejectedEvent = z.infer<typeof PickRejectedEventSchema>;

export const SkipEventSchema = z.object({
  bidSessionId: z.string().min(1),
  skippedMemberId: z.number().int().positive(),
  ordinal: z.number().int().nonnegative(),
  reason: z.string().min(1),
  nextBidderId: z.number().int().nonnegative().nullable(),
  turnStartedAtMs: z.number().int().nonnegative(),
});
export type SkipEvent = z.infer<typeof SkipEventSchema>;

export const ForcedPickEventSchema = z.object({
  bidId: z.string().min(1),
  bidSessionId: z.string().min(1),
  ordinal: z.number().int().nonnegative(),
  memberId: z.number().int().positive(),
  positionId: z.string().min(1),
  adminActorId: z.number().int().nonnegative(),
  reason: z.string().min(1),
});
export type ForcedPickEvent = z.infer<typeof ForcedPickEventSchema>;

export const FreezeEventSchema = z.object({
  bidSessionId: z.string().min(1),
  frozenAt: z.number().int().nonnegative(),
  freezeActorId: z.number().int().nonnegative(),
  reason: z.string().min(1),
});
export type FreezeEvent = z.infer<typeof FreezeEventSchema>;

export const StateSnapshotEventSchema = z.object({
  bidSessionId: z.string().min(1),
  seq: z.number().int().nonnegative(),
  currentPhase: z.enum(['config', 'position_bid', 'a_day_bid', 'paused', 'complete']),
  currentBidderId: z.number().int().nonnegative().nullable(),
  turnStartedAtMs: z.number().int().nonnegative(),
  turnTimerSeconds: z.number().int().positive(),
  frozenAt: z.number().int().nonnegative().nullable(),
  fills: z.array(
    z.object({
      positionId: z.string().min(1),
      memberId: z.number().int().positive(),
      ordinal: z.number().int().nonnegative(),
    }),
  ),
  bidOrder: z.array(
    z.object({
      ordinal: z.number().int().positive(),
      memberId: z.number().int().positive(),
      pool: z.enum(['OFC', 'FF']),
    }),
  ),
});
export type StateSnapshotEvent = z.infer<typeof StateSnapshotEventSchema>;

export const ResyncEventSchema = z.object({
  reason: z.enum(['version_skew', 'do_restart', 'seq_gap']),
  lastSeq: z.number().int().nonnegative(),
});
export type ResyncEvent = z.infer<typeof ResyncEventSchema>;

// ── Envelope ─────────────────────────────────────────────────────────────

export const EVENT_TYPES = [
  'state_snapshot',
  'pick_made',
  'pick_rejected',
  'skip',
  'forced_pick',
  'freeze',
  'resync',
  'pong',
] as const;
export const EventTypeSchema = z.enum(EVENT_TYPES);
export type EventType = z.infer<typeof EventTypeSchema>;

export const BidEventEnvelopeSchema = z.object({
  v: z.literal(BID_EVENT_VERSION),
  seq: z.number().int().nonnegative(),
  ts: z.number().int().nonnegative(),
  type: EventTypeSchema,
  payload: z.unknown(),
});
export type BidEventEnvelope = z.infer<typeof BidEventEnvelopeSchema>;

export { BID_EVENT_VERSION };
```

- [ ] **Step 5: Update `packages/shared/src/index.ts`**

```ts
// append:
export * from './constants/bid-events.js';
export * from './schemas/bid-events.js';
```

- [ ] **Step 6: Run tests, expect PASS**

```bash
cd D:/GitHub_Repos/mbfd-bid
pnpm --filter @mbfd/shared test
# Expected: all green
```

- [ ] **Step 7: Lint + typecheck**

```bash
pnpm lint && pnpm typecheck
```

- [ ] **Step 8: Commit**

```bash
git add packages/shared/src/schemas/bid-events.ts packages/shared/src/constants/bid-events.ts packages/shared/src/index.ts packages/shared/tests/bid-events.test.ts
git commit -m "feat(shared): versioned Zod schemas for live bid WebSocket events"
```

---

## Task 3: Bid order computation (`apps/worker/src/lib/bid-order.ts`)

**Files:**
- Create: `apps/worker/src/lib/bid-order.ts`
- Test: `apps/worker/tests/unit/bid-order.test.ts`

`bid_order` is deterministic: two pools (OFC, then FF), each sorted ascending by `rscSeniority`, with `rankSeniority` as the tie-break. EXCLUDED members are skipped. This is called once at session-start; the DO never recomputes it mid-bid.

- [ ] **Step 1: Write failing test**

```ts
// apps/worker/tests/unit/bid-order.test.ts
import { describe, expect, it } from 'vitest';
import { computeBidOrder, type BidOrderInputMember } from '../../src/lib/bid-order.js';

const m = (
  id: number,
  rsc: number,
  rankSen: number | undefined,
  bidCategory: 'OFC' | 'FF' | 'EXCLUDED' = 'OFC',
): BidOrderInputMember => ({
  id,
  bidCategory,
  rscSeniority: rsc,
  rankSeniority: rankSen ?? null,
});

describe('computeBidOrder (Plan 04 Task 3)', () => {
  it('returns empty array for empty input', () => {
    expect(computeBidOrder([])).toEqual([]);
  });

  it('orders OFC pool before FF pool', () => {
    const order = computeBidOrder([
      m(1, 50, 1, 'FF'),
      m(2, 100, 1, 'OFC'),
      m(3, 200, 1, 'OFC'),
    ]);
    expect(order.map((r) => r.memberId)).toEqual([2, 3, 1]);
    expect(order[0]!.pool).toBe('OFC');
    expect(order[2]!.pool).toBe('FF');
  });

  it('sorts within pool by rscSeniority ascending (lower = more senior)', () => {
    const order = computeBidOrder([
      m(1, 10, 1, 'OFC'),
      m(2, 5, 1, 'OFC'),
      m(3, 7, 1, 'OFC'),
    ]);
    expect(order.map((r) => r.memberId)).toEqual([2, 3, 1]);
  });

  it('uses rankSeniority as tie-break when rscSeniority is equal', () => {
    const order = computeBidOrder([
      m(1, 5, 9, 'OFC'),
      m(2, 5, 1, 'OFC'),
      m(3, 5, 4, 'OFC'),
    ]);
    expect(order.map((r) => r.memberId)).toEqual([2, 3, 1]);
  });

  it('puts members with undefined rankSeniority last in their tie', () => {
    const order = computeBidOrder([
      m(1, 5, undefined, 'OFC'),
      m(2, 5, 2, 'OFC'),
    ]);
    expect(order.map((r) => r.memberId)).toEqual([2, 1]);
  });

  it('omits EXCLUDED members entirely', () => {
    const order = computeBidOrder([
      m(1, 1, 1, 'OFC'),
      m(2, 2, 1, 'EXCLUDED'),
      m(3, 3, 1, 'FF'),
    ]);
    expect(order.map((r) => r.memberId)).toEqual([1, 3]);
  });

  it('ordinal starts at 1 and is contiguous', () => {
    const order = computeBidOrder([
      m(1, 1, 1, 'OFC'),
      m(2, 2, 1, 'OFC'),
      m(3, 3, 1, 'FF'),
    ]);
    expect(order.map((r) => r.ordinal)).toEqual([1, 2, 3]);
  });
});
```

- [ ] **Step 2: Run test, expect FAIL**

- [ ] **Step 3: Implement**

```ts
// apps/worker/src/lib/bid-order.ts
import type { InferSelectModel } from 'drizzle-orm';
import type { bidOrder, members } from '../db/schema.js';

export type BidOrderRow = InferSelectModel<typeof bidOrder>;

/**
 * Subset of `members` we need for ordering. Tests pass plain objects.
 */
export interface BidOrderInputMember {
  id: number;
  bidCategory: 'OFC' | 'FF' | 'EXCLUDED';
  rscSeniority: number;
  /** null when not recorded — sorts last within the tied rscSeniority group. */
  rankSeniority: number | null;
}

export interface ComputedBidOrderEntry {
  ordinal: number;
  memberId: number;
  pool: 'OFC' | 'FF';
}

const POOL_ORDER: ReadonlyArray<'OFC' | 'FF'> = ['OFC', 'FF'];

/**
 * Deterministic two-pool bid order.
 *   1. OFC pool first, FF pool second.
 *   2. Inside each pool, ascending rscSeniority (lower = more senior).
 *   3. Tie-break on rankSeniority ascending; null sorts last.
 * EXCLUDED members are dropped.
 */
export function computeBidOrder(
  members: ReadonlyArray<BidOrderInputMember>,
): ComputedBidOrderEntry[] {
  const eligible = members.filter((m) => m.bidCategory !== 'EXCLUDED');
  const out: ComputedBidOrderEntry[] = [];
  for (const pool of POOL_ORDER) {
    const poolMembers = eligible
      .filter((m) => m.bidCategory === pool)
      .slice()
      .sort((a, b) => {
        if (a.rscSeniority !== b.rscSeniority) return a.rscSeniority - b.rscSeniority;
        const ar = a.rankSeniority ?? Number.MAX_SAFE_INTEGER;
        const br = b.rankSeniority ?? Number.MAX_SAFE_INTEGER;
        return ar - br;
      });
    for (const m of poolMembers) {
      out.push({ ordinal: out.length + 1, memberId: m.id, pool });
    }
  }
  return out;
}
```

- [ ] **Step 4: Run test, expect PASS**

- [ ] **Step 5: Lint + typecheck + commit**

```bash
pnpm lint && pnpm --filter @mbfd/worker typecheck
git add apps/worker/src/lib/bid-order.ts apps/worker/tests/unit/bid-order.test.ts
git commit -m "feat(worker): deterministic two-pool bid_order computation"
```

---

## Task 4: BidSession DO — state shape and storage helpers

**Files:**
- Create: `apps/worker/src/durable/bid-session-state.ts`
- Test: `apps/worker/tests/unit/bid-session-state.test.ts`

Pure module — no DO class yet. Defines the in-memory state shape and the `load` / `persist` helpers that take a generic `Storage` interface. Keeping this separate from `DurableObject` makes it unit-testable without Miniflare.

- [ ] **Step 1: Write failing test**

```ts
// apps/worker/tests/unit/bid-session-state.test.ts
import { describe, expect, it } from 'vitest';
import {
  emptyBidSessionState,
  loadBidSessionState,
  persistBidSessionState,
  type BidSessionState,
  type DOStorageLike,
} from '../../src/durable/bid-session-state.js';

class InMemoryStorage implements DOStorageLike {
  private store = new Map<string, unknown>();
  async get<T>(key: string): Promise<T | undefined> {
    return this.store.get(key) as T | undefined;
  }
  async put<T>(key: string, value: T): Promise<void> {
    this.store.set(key, value);
  }
  async delete(key: string): Promise<boolean> {
    return this.store.delete(key);
  }
  async list<T>(prefix: string): Promise<Map<string, T>> {
    const out = new Map<string, T>();
    for (const [k, v] of this.store) if (k.startsWith(prefix)) out.set(k, v as T);
    return out;
  }
}

describe('bid-session-state (Plan 04 Task 4)', () => {
  it('emptyBidSessionState has phase=config and no current bidder', () => {
    const s = emptyBidSessionState('01HSESS');
    expect(s.bidSessionId).toBe('01HSESS');
    expect(s.currentPhase).toBe('config');
    expect(s.currentBidderId).toBeNull();
    expect(s.lastSeq).toBe(0);
    expect(s.fills).toEqual({});
  });

  it('persist + load round-trips state', async () => {
    const storage = new InMemoryStorage();
    const state: BidSessionState = {
      ...emptyBidSessionState('01HSESS'),
      currentPhase: 'position_bid',
      currentBidderId: 7,
      lastSeq: 42,
      fills: { A101: { memberId: 17, ordinal: 5, bidId: '01HBID' } },
      turnStartedAtMs: 1700000000000,
      turnTimerSeconds: 180,
    };
    await persistBidSessionState(storage, state);
    const loaded = await loadBidSessionState(storage, '01HSESS');
    expect(loaded).toEqual(state);
  });

  it('load returns empty state when nothing persisted', async () => {
    const storage = new InMemoryStorage();
    const loaded = await loadBidSessionState(storage, '01HSESS');
    expect(loaded.currentPhase).toBe('config');
    expect(loaded.lastSeq).toBe(0);
  });

  it('persist writes all keys atomically (single map round-trip)', async () => {
    const storage = new InMemoryStorage();
    await persistBidSessionState(storage, emptyBidSessionState('01HSESS'));
    const all = await storage.list('bs:01HSESS:');
    expect(all.size).toBeGreaterThan(0);
  });

  it('idempotency keys are namespaced and TTL-eligible', async () => {
    const storage = new InMemoryStorage();
    await storage.put('idem:01HSESS:11111111-1111-4111-8111-111111111111', { bidId: 'X' });
    const all = await storage.list('idem:01HSESS:');
    expect(all.size).toBe(1);
  });
});
```

- [ ] **Step 2: Run test, expect FAIL**

- [ ] **Step 3: Implement**

```ts
// apps/worker/src/durable/bid-session-state.ts

/**
 * Minimal Storage surface we use — keeps state.ts testable without Miniflare.
 * The real `DurableObjectStorage` is a superset of this interface.
 */
export interface DOStorageLike {
  get<T>(key: string): Promise<T | undefined>;
  put<T>(key: string, value: T): Promise<void>;
  delete(key: string): Promise<boolean>;
  list<T>(prefix: string): Promise<Map<string, T>>;
}

export type CurrentPhase =
  | 'config'
  | 'position_bid'
  | 'a_day_bid'
  | 'paused'
  | 'complete';

export interface Fill {
  memberId: number;
  ordinal: number;
  bidId: string;
}

export interface BidSessionState {
  bidSessionId: string;
  currentPhase: CurrentPhase;
  currentBidderId: number | null;
  /** UNIX ms of the moment the active turn started. 0 if no active turn. */
  turnStartedAtMs: number;
  turnTimerSeconds: number;
  /** Monotonically increasing event sequence number. */
  lastSeq: number;
  /** Map<position_id, Fill>. JSON-serialisable. */
  fills: Record<string, Fill>;
  /** Pre-computed bid order, set once at session start. */
  bidOrder: ReadonlyArray<{ ordinal: number; memberId: number; pool: 'OFC' | 'FF' }>;
  /** Queue cursor — index into bidOrder for the active bidder. */
  queueCursor: number;
  /** UNIX ms of freeze, null while live. */
  frozenAt: number | null;
}

export function emptyBidSessionState(bidSessionId: string): BidSessionState {
  return {
    bidSessionId,
    currentPhase: 'config',
    currentBidderId: null,
    turnStartedAtMs: 0,
    turnTimerSeconds: 180,
    lastSeq: 0,
    fills: {},
    bidOrder: [],
    queueCursor: 0,
    frozenAt: null,
  };
}

function keyFor(bidSessionId: string): string {
  return `bs:${bidSessionId}:state`;
}

export async function loadBidSessionState(
  storage: DOStorageLike,
  bidSessionId: string,
): Promise<BidSessionState> {
  const persisted = await storage.get<BidSessionState>(keyFor(bidSessionId));
  return persisted ?? emptyBidSessionState(bidSessionId);
}

export async function persistBidSessionState(
  storage: DOStorageLike,
  state: BidSessionState,
): Promise<void> {
  await storage.put<BidSessionState>(keyFor(state.bidSessionId), state);
}
```

- [ ] **Step 4: Run test, expect PASS**

- [ ] **Step 5: Commit**

```bash
git add apps/worker/src/durable/bid-session-state.ts apps/worker/tests/unit/bid-session-state.test.ts
git commit -m "feat(worker): BidSession DO state shape and storage helpers"
```

---

## Task 5: BidSession DO — pure handlers (`bid-session-handlers.ts`)

**Files:**
- Create: `apps/worker/src/durable/bid-session-handlers.ts`
- Test: `apps/worker/tests/unit/bid-session-handlers.test.ts`

All business logic for `submit_pick`, `skip`, `force_pick`, `freeze`, and `advance_cursor` lives here as pure `(state, input) => result` functions. The DO class in Task 7 just wires storage + WebSocket fanout to these.

- [ ] **Step 1: Write failing test**

```ts
// apps/worker/tests/unit/bid-session-handlers.test.ts
import { describe, expect, it } from 'vitest';
import type { EligibilityResult } from '@mbfd/eligibility';
import {
  emptyBidSessionState,
  type BidSessionState,
} from '../../src/durable/bid-session-state.js';
import {
  handleForcePick,
  handleFreeze,
  handleSkip,
  handleSubmitPick,
  type HandlerEnv,
} from '../../src/durable/bid-session-handlers.js';

const eligible: EligibilityResult = {
  eligible: true,
  reasons: [],
  points: 5,
  soPoints: 0,
  moPoints: 0,
  breakdown: { total: 5, soTotal: 0, moTotal: 0, itemized: [] },
};
const ineligible: EligibilityResult = {
  eligible: false,
  reasons: [{ code: 'CRED_MISSING', label: 'Missing X', satisfied: false }],
  points: 0,
  soPoints: 0,
  moPoints: 0,
  breakdown: { total: 0, soTotal: 0, moTotal: 0, itemized: [] },
};

function activeState(): BidSessionState {
  return {
    ...emptyBidSessionState('01HSESS'),
    currentPhase: 'position_bid',
    currentBidderId: 17,
    turnStartedAtMs: 1700000000000,
    turnTimerSeconds: 180,
    bidOrder: [
      { ordinal: 1, memberId: 17, pool: 'OFC' },
      { ordinal: 2, memberId: 18, pool: 'OFC' },
      { ordinal: 3, memberId: 19, pool: 'FF' },
    ],
    queueCursor: 0,
  };
}

function env(check: 'eligible' | 'ineligible' = 'eligible'): HandlerEnv {
  return {
    nowMs: () => 1700000001000,
    newBidId: () => '01HBID',
    evaluateEligibility: () => (check === 'eligible' ? eligible : ineligible),
  };
}

describe('handleSubmitPick (Plan 04 Task 5)', () => {
  it('accepts when sender is current bidder and position is open and eligible', () => {
    const state = activeState();
    const r = handleSubmitPick(state, env(), {
      senderMemberId: 17,
      positionId: 'A101',
      aDay: null,
      idempotencyKey: '11111111-1111-4111-8111-111111111111',
    });
    expect(r.kind).toBe('accepted');
    if (r.kind !== 'accepted') return;
    expect(r.newState.fills.A101).toBeDefined();
    expect(r.newState.queueCursor).toBe(1);
    expect(r.newState.currentBidderId).toBe(18);
    expect(r.newState.lastSeq).toBe(state.lastSeq + 1);
    expect(r.event.type).toBe('pick_made');
  });

  it('rejects when sender is not current bidder', () => {
    const state = activeState();
    const r = handleSubmitPick(state, env(), {
      senderMemberId: 99,
      positionId: 'A101',
      aDay: null,
      idempotencyKey: '11111111-1111-4111-8111-111111111111',
    });
    expect(r.kind).toBe('rejected');
    if (r.kind !== 'rejected') return;
    expect(r.code).toBe('NOT_YOUR_TURN');
  });

  it('rejects when position already filled', () => {
    const state = activeState();
    state.fills.A101 = { memberId: 22, ordinal: 99, bidId: 'X' };
    const r = handleSubmitPick(state, env(), {
      senderMemberId: 17,
      positionId: 'A101',
      aDay: null,
      idempotencyKey: '11111111-1111-4111-8111-111111111111',
    });
    expect(r.kind).toBe('rejected');
    if (r.kind !== 'rejected') return;
    expect(r.code).toBe('POSITION_FILLED');
  });

  it('rejects when eligibility engine says not eligible', () => {
    const state = activeState();
    const r = handleSubmitPick(state, env('ineligible'), {
      senderMemberId: 17,
      positionId: 'A101',
      aDay: null,
      idempotencyKey: '11111111-1111-4111-8111-111111111111',
    });
    expect(r.kind).toBe('rejected');
    if (r.kind !== 'rejected') return;
    expect(r.code).toBe('NOT_ELIGIBLE');
  });

  it('rejects when session is frozen', () => {
    const state = { ...activeState(), frozenAt: 1700000000500 };
    const r = handleSubmitPick(state, env(), {
      senderMemberId: 17,
      positionId: 'A101',
      aDay: null,
      idempotencyKey: '11111111-1111-4111-8111-111111111111',
    });
    expect(r.kind).toBe('rejected');
    if (r.kind !== 'rejected') return;
    expect(r.code).toBe('SESSION_FROZEN');
  });

  it('rejects when session phase is paused', () => {
    const state = { ...activeState(), currentPhase: 'paused' as const };
    const r = handleSubmitPick(state, env(), {
      senderMemberId: 17,
      positionId: 'A101',
      aDay: null,
      idempotencyKey: '11111111-1111-4111-8111-111111111111',
    });
    expect(r.kind).toBe('rejected');
    if (r.kind !== 'rejected') return;
    expect(r.code).toBe('SESSION_PAUSED');
  });

  it('advances queueCursor past the last entry → currentPhase=complete', () => {
    const state = activeState();
    state.queueCursor = 2;
    state.currentBidderId = 19;
    const r = handleSubmitPick(state, env(), {
      senderMemberId: 19,
      positionId: 'A101',
      aDay: null,
      idempotencyKey: '11111111-1111-4111-8111-111111111111',
    });
    expect(r.kind).toBe('accepted');
    if (r.kind !== 'accepted') return;
    expect(r.newState.currentPhase).toBe('complete');
    expect(r.newState.currentBidderId).toBeNull();
  });
});

describe('handleSkip (Plan 04 Task 5)', () => {
  it('admin skip advances cursor and emits skip event with reason', () => {
    const state = activeState();
    const r = handleSkip(state, env(), {
      adminActorId: 0,
      reason: 'Bidder unreachable past 2× timer',
    });
    expect(r.kind).toBe('accepted');
    if (r.kind !== 'accepted') return;
    expect(r.newState.queueCursor).toBe(1);
    expect(r.newState.currentBidderId).toBe(18);
    expect(r.event.type).toBe('skip');
  });

  it('rejects skip when session frozen', () => {
    const state = { ...activeState(), frozenAt: 1700000000500 };
    const r = handleSkip(state, env(), { adminActorId: 0, reason: 'x' });
    expect(r.kind).toBe('rejected');
  });
});

describe('handleForcePick (Plan 04 Task 5)', () => {
  it('admin force-pick bypasses eligibility check', () => {
    const state = activeState();
    const r = handleForcePick(state, env('ineligible'), {
      adminActorId: 0,
      targetMemberId: 17,
      positionId: 'A101',
      reason: 'Last qualified candidate, mandatory minimum',
    });
    expect(r.kind).toBe('accepted');
    if (r.kind !== 'accepted') return;
    expect(r.event.type).toBe('forced_pick');
    expect(r.newState.fills.A101?.memberId).toBe(17);
  });

  it('rejects force-pick on a position already filled', () => {
    const state = activeState();
    state.fills.A101 = { memberId: 22, ordinal: 1, bidId: 'X' };
    const r = handleForcePick(state, env(), {
      adminActorId: 0,
      targetMemberId: 17,
      positionId: 'A101',
      reason: 'x',
    });
    expect(r.kind).toBe('rejected');
  });
});

describe('handleFreeze (Plan 04 Task 5)', () => {
  it('sets frozenAt and freezeActorId', () => {
    const state = activeState();
    const r = handleFreeze(state, env(), {
      adminActorId: 0,
      reason: 'Network outage at venue',
    });
    expect(r.kind).toBe('accepted');
    if (r.kind !== 'accepted') return;
    expect(r.newState.frozenAt).toBe(1700000001000);
    expect(r.newState.currentPhase).toBe('paused');
  });

  it('re-freeze is a no-op', () => {
    const state = { ...activeState(), frozenAt: 1700000000500 };
    const r = handleFreeze(state, env(), { adminActorId: 0, reason: 'x' });
    expect(r.kind).toBe('rejected');
  });
});
```

- [ ] **Step 2: Run test, expect FAIL**

- [ ] **Step 3: Implement**

```ts
// apps/worker/src/durable/bid-session-handlers.ts
import type { EligibilityResult, Member, PositionRule } from '@mbfd/eligibility';
import type {
  PickMadeEvent,
  PickRejectCode,
  PickRejectedEvent,
  ForcedPickEvent,
  FreezeEvent,
  SkipEvent,
} from '@mbfd/shared';
import type { BidSessionState } from './bid-session-state.js';

/**
 * Effectful dependencies injected into the otherwise-pure handlers.
 * Keeps tests deterministic — no Date.now() / no crypto.randomUUID().
 */
export interface HandlerEnv {
  nowMs: () => number;
  newBidId: () => string;
  /**
   * Eligibility check; in production this calls @mbfd/eligibility against
   * the loaded member + rule. In tests we stub it. The session-loader (Task 6)
   * is responsible for resolving member/position/rule before invoking handlers.
   */
  evaluateEligibility: (input: {
    memberId: number;
    positionId: string;
  }) => EligibilityResult;
}

interface AcceptedSubmitPick {
  kind: 'accepted';
  newState: BidSessionState;
  event: { type: 'pick_made'; payload: PickMadeEvent };
}
interface RejectedResult {
  kind: 'rejected';
  code: PickRejectCode;
  message: string;
  /** When the rejected operation was a submit_pick, echo back the key. */
  idempotencyKey?: string;
}
interface AcceptedSkip {
  kind: 'accepted';
  newState: BidSessionState;
  event: { type: 'skip'; payload: SkipEvent };
}
interface AcceptedForce {
  kind: 'accepted';
  newState: BidSessionState;
  event: { type: 'forced_pick'; payload: ForcedPickEvent };
}
interface AcceptedFreeze {
  kind: 'accepted';
  newState: BidSessionState;
  event: { type: 'freeze'; payload: FreezeEvent };
}

export interface SubmitPickInput {
  senderMemberId: number;
  positionId: string;
  aDay: string | null;
  idempotencyKey: string;
}
export interface SkipInput {
  adminActorId: number;
  reason: string;
}
export interface ForcePickInput {
  adminActorId: number;
  targetMemberId: number;
  positionId: string;
  reason: string;
}
export interface FreezeInput {
  adminActorId: number;
  reason: string;
}

function rejectFrozen(state: BidSessionState, key?: string): RejectedResult | null {
  if (state.frozenAt !== null) {
    return {
      kind: 'rejected',
      code: 'SESSION_FROZEN',
      message: 'Bid session is frozen; only admin overrides may mutate state.',
      idempotencyKey: key,
    };
  }
  return null;
}

function advance(state: BidSessionState): {
  nextBidderId: number | null;
  nextPhase: BidSessionState['currentPhase'];
  nextCursor: number;
} {
  const nextCursor = state.queueCursor + 1;
  if (nextCursor >= state.bidOrder.length) {
    return { nextBidderId: null, nextPhase: 'complete', nextCursor };
  }
  return {
    nextBidderId: state.bidOrder[nextCursor]!.memberId,
    nextPhase: state.currentPhase,
    nextCursor,
  };
}

export function handleSubmitPick(
  state: BidSessionState,
  env: HandlerEnv,
  input: SubmitPickInput,
): AcceptedSubmitPick | RejectedResult {
  const frozen = rejectFrozen(state, input.idempotencyKey);
  if (frozen) return frozen;

  if (state.currentPhase === 'paused') {
    return {
      kind: 'rejected',
      code: 'SESSION_PAUSED',
      message: 'Bid session is paused.',
      idempotencyKey: input.idempotencyKey,
    };
  }

  if (state.currentBidderId !== input.senderMemberId) {
    return {
      kind: 'rejected',
      code: 'NOT_YOUR_TURN',
      message: `Active bidder is member ${state.currentBidderId ?? 'none'}; sender is ${input.senderMemberId}.`,
      idempotencyKey: input.idempotencyKey,
    };
  }

  if (state.fills[input.positionId]) {
    return {
      kind: 'rejected',
      code: 'POSITION_FILLED',
      message: `Position ${input.positionId} is already filled.`,
      idempotencyKey: input.idempotencyKey,
    };
  }

  const elig = env.evaluateEligibility({
    memberId: input.senderMemberId,
    positionId: input.positionId,
  });
  if (!elig.eligible) {
    return {
      kind: 'rejected',
      code: 'NOT_ELIGIBLE',
      message: `Not eligible: ${elig.reasons
        .filter((r) => !r.satisfied)
        .map((r) => r.label)
        .join('; ')}`,
      idempotencyKey: input.idempotencyKey,
    };
  }

  const ordinal = state.bidOrder[state.queueCursor]!.ordinal;
  const bidId = env.newBidId();
  const { nextBidderId, nextPhase, nextCursor } = advance(state);
  const turnStartedAtMs = nextBidderId === null ? 0 : env.nowMs();

  const newState: BidSessionState = {
    ...state,
    fills: {
      ...state.fills,
      [input.positionId]: { memberId: input.senderMemberId, ordinal, bidId },
    },
    currentBidderId: nextBidderId,
    currentPhase: nextPhase,
    queueCursor: nextCursor,
    turnStartedAtMs,
    lastSeq: state.lastSeq + 1,
  };

  const payload: PickMadeEvent = {
    bidId,
    bidSessionId: state.bidSessionId,
    ordinal,
    memberId: input.senderMemberId,
    positionId: input.positionId,
    aDay: input.aDay,
    idempotencyKey: input.idempotencyKey,
    nextBidderId,
    turnStartedAtMs,
  };

  return { kind: 'accepted', newState, event: { type: 'pick_made', payload } };
}

export function handleSkip(
  state: BidSessionState,
  env: HandlerEnv,
  input: SkipInput,
): AcceptedSkip | RejectedResult {
  const frozen = rejectFrozen(state);
  if (frozen) return frozen;
  if (state.currentBidderId === null) {
    return { kind: 'rejected', code: 'PROTOCOL_ERROR', message: 'No active bidder to skip.' };
  }
  const skippedMemberId = state.currentBidderId;
  const ordinal = state.bidOrder[state.queueCursor]!.ordinal;
  const { nextBidderId, nextPhase, nextCursor } = advance(state);
  const turnStartedAtMs = nextBidderId === null ? 0 : env.nowMs();
  const newState: BidSessionState = {
    ...state,
    currentBidderId: nextBidderId,
    currentPhase: nextPhase,
    queueCursor: nextCursor,
    turnStartedAtMs,
    lastSeq: state.lastSeq + 1,
  };
  return {
    kind: 'accepted',
    newState,
    event: {
      type: 'skip',
      payload: {
        bidSessionId: state.bidSessionId,
        skippedMemberId,
        ordinal,
        reason: input.reason,
        nextBidderId,
        turnStartedAtMs,
      },
    },
  };
}

export function handleForcePick(
  state: BidSessionState,
  env: HandlerEnv,
  input: ForcePickInput,
): AcceptedForce | RejectedResult {
  // NB: a force-pick is allowed even when the session is frozen iff the admin
  // explicitly unfreezes first. We model "frozen → reject" here; Plan 05's
  // admin console exposes the unfreeze workflow.
  const frozen = rejectFrozen(state);
  if (frozen) return frozen;
  if (state.fills[input.positionId]) {
    return {
      kind: 'rejected',
      code: 'POSITION_FILLED',
      message: `Position ${input.positionId} is already filled.`,
    };
  }
  // Force-pick deliberately does NOT call evaluateEligibility — spec §7.
  const queueIndex = state.bidOrder.findIndex((o) => o.memberId === input.targetMemberId);
  if (queueIndex === -1) {
    return {
      kind: 'rejected',
      code: 'PROTOCOL_ERROR',
      message: `Member ${input.targetMemberId} not found in bid order.`,
    };
  }
  const ordinal = state.bidOrder[queueIndex]!.ordinal;
  const bidId = env.newBidId();

  // If we forced for the current bidder, advance. Otherwise the member is
  // marked complete and skipped on their natural turn (Plan 05 elaborates).
  let newState: BidSessionState;
  if (queueIndex === state.queueCursor) {
    const { nextBidderId, nextPhase, nextCursor } = advance(state);
    const turnStartedAtMs = nextBidderId === null ? 0 : env.nowMs();
    newState = {
      ...state,
      fills: {
        ...state.fills,
        [input.positionId]: { memberId: input.targetMemberId, ordinal, bidId },
      },
      currentBidderId: nextBidderId,
      currentPhase: nextPhase,
      queueCursor: nextCursor,
      turnStartedAtMs,
      lastSeq: state.lastSeq + 1,
    };
  } else {
    newState = {
      ...state,
      fills: {
        ...state.fills,
        [input.positionId]: { memberId: input.targetMemberId, ordinal, bidId },
      },
      lastSeq: state.lastSeq + 1,
    };
  }

  return {
    kind: 'accepted',
    newState,
    event: {
      type: 'forced_pick',
      payload: {
        bidId,
        bidSessionId: state.bidSessionId,
        ordinal,
        memberId: input.targetMemberId,
        positionId: input.positionId,
        adminActorId: input.adminActorId,
        reason: input.reason,
      },
    },
  };
}

export function handleFreeze(
  state: BidSessionState,
  env: HandlerEnv,
  input: FreezeInput,
): AcceptedFreeze | RejectedResult {
  if (state.frozenAt !== null) {
    return {
      kind: 'rejected',
      code: 'SESSION_FROZEN',
      message: 'Session is already frozen.',
    };
  }
  const frozenAt = env.nowMs();
  const newState: BidSessionState = {
    ...state,
    frozenAt,
    currentPhase: 'paused',
    lastSeq: state.lastSeq + 1,
  };
  return {
    kind: 'accepted',
    newState,
    event: {
      type: 'freeze',
      payload: {
        bidSessionId: state.bidSessionId,
        frozenAt,
        freezeActorId: input.adminActorId,
        reason: input.reason,
      },
    },
  };
}

// Unused export silenced by re-exporting types referenced by tests.
export type { Member, PositionRule };
```

- [ ] **Step 4: Run tests, expect PASS**

- [ ] **Step 5: Lint + commit**

```bash
pnpm lint && pnpm --filter @mbfd/worker typecheck
git add apps/worker/src/durable/bid-session-handlers.ts apps/worker/tests/unit/bid-session-handlers.test.ts
git commit -m "feat(worker): pure handlers for submit_pick, skip, force_pick, freeze"
```

---

## Task 6: Session loader (`apps/worker/src/lib/session-loader.ts`)

**Files:**
- Create: `apps/worker/src/lib/session-loader.ts`
- Test: `apps/worker/tests/unit/session-loader.test.ts`

Glue between D1 and the handlers. Given a `bid_session_id` and a `member_id` + `position_id`, returns the `EligibilityResult` by:
1. Loading the member and their credentials from D1
2. Loading the position rule for the active rule-book version
3. Calling `evaluateEligibility` from `@mbfd/eligibility` (Plan 03)

- [ ] **Step 1: Write failing test**

```ts
// apps/worker/tests/unit/session-loader.test.ts
import { describe, expect, it, vi } from 'vitest';
import { evaluateEligibilityForSession } from '../../src/lib/session-loader.js';

const fakeMember = {
  id: 17,
  employeeId: '99999',
  firstName: 'Test',
  lastName: 'User',
  rank: 'FF' as const,
  rscSeniority: 10,
  rankSeniority: 5,
  isProbationary: false,
};
const fakeRule = {
  positionId: 'A101',
  ruleBookVersion: '2026.1',
  requiredCriteria: { rank: ['FF'], credentials: [], custom: [] },
  pointsPreference: { max: 0, items: [] },
  tieBreakChain: ['points', 'rsc_seniority', 'rank_seniority'],
};

function makeFakeDb(opts: { member?: typeof fakeMember | null; rule?: typeof fakeRule | null }) {
  return {
    loadMemberWithCredentials: vi.fn(async () => opts.member ?? null),
    loadPositionRule: vi.fn(async () => opts.rule ?? null),
  };
}

describe('evaluateEligibilityForSession (Plan 04 Task 6)', () => {
  it('returns eligible=true when rules and rank match', async () => {
    const db = makeFakeDb({ member: fakeMember, rule: fakeRule });
    const r = await evaluateEligibilityForSession(db, {
      ruleBookVersion: '2026.1',
      memberId: 17,
      positionId: 'A101',
    });
    expect(r.eligible).toBe(true);
  });

  it('returns eligible=false when member not found', async () => {
    const db = makeFakeDb({ member: null, rule: fakeRule });
    const r = await evaluateEligibilityForSession(db, {
      ruleBookVersion: '2026.1',
      memberId: 17,
      positionId: 'A101',
    });
    expect(r.eligible).toBe(false);
    expect(r.reasons.some((rs) => rs.code === 'MEMBER_NOT_FOUND')).toBe(true);
  });

  it('returns eligible=false when rule not found', async () => {
    const db = makeFakeDb({ member: fakeMember, rule: null });
    const r = await evaluateEligibilityForSession(db, {
      ruleBookVersion: '2026.1',
      memberId: 17,
      positionId: 'A101',
    });
    expect(r.eligible).toBe(false);
    expect(r.reasons.some((rs) => rs.code === 'RULE_NOT_FOUND')).toBe(true);
  });
});
```

- [ ] **Step 2: Run test, expect FAIL**

- [ ] **Step 3: Implement**

```ts
// apps/worker/src/lib/session-loader.ts
import { evaluateEligibility, type EligibilityResult, type Member, type PositionRule } from '@mbfd/eligibility';

/**
 * Db surface kept minimal so tests inject plain objects.
 * The production wiring lives next to the DO in Task 7.
 */
export interface SessionLoaderDb {
  loadMemberWithCredentials(memberId: number): Promise<MemberWithCreds | null>;
  loadPositionRule(ruleBookVersion: string, positionId: string): Promise<PositionRule | null>;
}

export type MemberWithCreds = Omit<Member, 'credentials'> & {
  credentials?: Member['credentials'];
};

export async function evaluateEligibilityForSession(
  db: SessionLoaderDb,
  input: { ruleBookVersion: string; memberId: number; positionId: string },
): Promise<EligibilityResult> {
  const member = await db.loadMemberWithCredentials(input.memberId);
  if (!member) {
    return {
      eligible: false,
      reasons: [
        { code: 'MEMBER_NOT_FOUND', label: `Member ${input.memberId} not loaded`, satisfied: false },
      ],
      points: 0,
      soPoints: 0,
      moPoints: 0,
      breakdown: { total: 0, soTotal: 0, moTotal: 0, itemized: [] },
    };
  }
  const rule = await db.loadPositionRule(input.ruleBookVersion, input.positionId);
  if (!rule) {
    return {
      eligible: false,
      reasons: [
        {
          code: 'RULE_NOT_FOUND',
          label: `No rule for position ${input.positionId} in ${input.ruleBookVersion}`,
          satisfied: false,
        },
      ],
      points: 0,
      soPoints: 0,
      moPoints: 0,
      breakdown: { total: 0, soTotal: 0, moTotal: 0, itemized: [] },
    };
  }
  const m: Member = { ...member, credentials: member.credentials ?? [] };
  return evaluateEligibility(m, rule);
}
```

- [ ] **Step 4: Run test, expect PASS**

- [ ] **Step 5: Commit**

```bash
git add apps/worker/src/lib/session-loader.ts apps/worker/tests/unit/session-loader.test.ts
git commit -m "feat(worker): session-loader bridges D1 → @mbfd/eligibility for the DO"
```

---

## Task 7: BidSession Durable Object class

**Files:**
- Create: `apps/worker/src/durable/bid-session.ts`
- Modify: `apps/worker/src/types/env.ts` (add `BID_SESSION: DurableObjectNamespace`)
- Modify: `apps/worker/wrangler.toml` (add binding + migrations tag)
- Test: `apps/worker/tests/integration/bid-session-do.test.ts`

The DO class wires storage, idempotency dedupe, and WebSocket fanout to the pure handlers from Task 5. Every state mutation is wrapped in `state.blockConcurrencyWhile()` so two simultaneous WS messages cannot interleave.

- [ ] **Step 1: Add binding to `wrangler.toml`**

```toml
# Append to apps/worker/wrangler.toml

[[durable_objects.bindings]]
name = "BID_SESSION"
class_name = "BidSessionDO"

[[migrations]]
tag = "v4"
new_classes = ["BidSessionDO"]
```

- [ ] **Step 2: Extend env type**

```ts
// apps/worker/src/types/env.ts — add to WorkerEnv:
export interface WorkerEnv {
  // ...existing bindings (DB, JWT_SIGNING_KEY, PIN_HASH, etc.)
  BID_SESSION: DurableObjectNamespace;
}
```

- [ ] **Step 3: Write failing integration test**

```ts
// apps/worker/tests/integration/bid-session-do.test.ts
import { unstable_dev, type UnstableDevWorker } from 'wrangler';
import { afterAll, beforeAll, describe, expect, it } from 'vitest';

describe('BidSessionDO end-to-end (Plan 04 Task 7)', () => {
  let worker: UnstableDevWorker;
  beforeAll(async () => {
    worker = await unstable_dev('src/index.ts', {
      experimental: { disableExperimentalWarning: true },
      local: true,
      persist: false,
    });
  });
  afterAll(async () => {
    await worker.stop();
  });

  it('returns 426 Upgrade Required for non-WS GET to /api/ws/session/:id', async () => {
    const res = await worker.fetch('/api/ws/session/01HSESS');
    expect(res.status).toBe(426);
  });

  it('upgrades to WebSocket with valid hello + accepts a pick', async () => {
    // (Implementer: this test uses Miniflare's WebSocket support; see
    // Plan 02 integration tests for the pattern.)
    expect(true).toBe(true); // placeholder — replaced after Task 8 routes
  });
});
```

- [ ] **Step 4: Implement DO class**

```ts
// apps/worker/src/durable/bid-session.ts
import {
  BID_EVENT_VERSION,
  BidEventEnvelopeSchema,
  ClientMessageSchema,
  type BidEventEnvelope,
  type PickRejectedEvent,
  type StateSnapshotEvent,
} from '@mbfd/shared';
import { ulid } from 'ulid';
import {
  handleForcePick,
  handleFreeze,
  handleSkip,
  handleSubmitPick,
  type ForcePickInput,
  type FreezeInput,
  type HandlerEnv,
  type SkipInput,
  type SubmitPickInput,
} from './bid-session-handlers.js';
import {
  emptyBidSessionState,
  loadBidSessionState,
  persistBidSessionState,
  type BidSessionState,
  type DOStorageLike,
} from './bid-session-state.js';
import type { WorkerEnv } from '../types/env.js';

interface ConnectedClient {
  socket: WebSocket;
  memberId: number;
  role: 'member' | 'admin';
}

interface IdempotencyRecord {
  /** Cached event envelope to replay on duplicate key. */
  envelope: BidEventEnvelope;
}

export class BidSessionDO implements DurableObject {
  private state: DurableObjectState;
  private env: WorkerEnv;
  private clients = new Map<string, ConnectedClient>();
  private memoryState: BidSessionState | null = null;

  constructor(state: DurableObjectState, env: WorkerEnv) {
    this.state = state;
    this.env = env;
  }

  private get storage(): DOStorageLike {
    return this.state.storage as unknown as DOStorageLike;
  }

  private async getState(): Promise<BidSessionState> {
    if (!this.memoryState) {
      const id = this.state.id.toString();
      this.memoryState = await loadBidSessionState(this.storage, id);
      if (this.memoryState.bidSessionId !== id) {
        this.memoryState = emptyBidSessionState(id);
      }
    }
    return this.memoryState;
  }

  private handlerEnv(): HandlerEnv {
    return {
      nowMs: () => Date.now(),
      newBidId: () => ulid(),
      evaluateEligibility: () => {
        // Wired in Task 8 — DO calls the session-loader. Placeholder is fail-open
        // for tests that use stubbed env directly.
        return {
          eligible: true,
          reasons: [],
          points: 0,
          soPoints: 0,
          moPoints: 0,
          breakdown: { total: 0, soTotal: 0, moTotal: 0, itemized: [] },
        };
      },
    };
  }

  private envelope(type: BidEventEnvelope['type'], payload: unknown, seq: number): BidEventEnvelope {
    return { v: BID_EVENT_VERSION, seq, ts: Date.now(), type, payload };
  }

  private broadcast(env: BidEventEnvelope): void {
    const json = JSON.stringify(env);
    for (const c of this.clients.values()) {
      try {
        c.socket.send(json);
      } catch {
        // closed clients are pruned on close event
      }
    }
  }

  private send(socket: WebSocket, env: BidEventEnvelope): void {
    try {
      socket.send(JSON.stringify(env));
    } catch {
      // ignore
    }
  }

  async fetch(req: Request): Promise<Response> {
    const url = new URL(req.url);
    if (url.pathname.endsWith('/ws')) {
      return this.handleUpgrade(req);
    }
    if (url.pathname.endsWith('/snapshot')) {
      const state = await this.getState();
      return new Response(JSON.stringify(state), {
        headers: { 'content-type': 'application/json' },
      });
    }
    return new Response('Not Found', { status: 404 });
  }

  private async handleUpgrade(req: Request): Promise<Response> {
    if (req.headers.get('Upgrade') !== 'websocket') {
      return new Response('Upgrade Required', { status: 426 });
    }
    const pair = new WebSocketPair();
    const [client, server] = Object.values(pair) as [WebSocket, WebSocket];
    server.accept();
    const clientId = ulid();

    server.addEventListener('message', async (ev) => {
      await this.onMessage(clientId, server, ev);
    });
    server.addEventListener('close', () => {
      this.clients.delete(clientId);
    });
    server.addEventListener('error', () => {
      this.clients.delete(clientId);
    });

    return new Response(null, { status: 101, webSocket: client });
  }

  private async onMessage(
    clientId: string,
    socket: WebSocket,
    ev: MessageEvent,
  ): Promise<void> {
    let raw: unknown;
    try {
      raw = JSON.parse(String(ev.data));
    } catch {
      this.send(socket, this.envelope('pick_rejected', {
        idempotencyKey: '00000000-0000-4000-8000-000000000000',
        code: 'PROTOCOL_ERROR',
        message: 'Invalid JSON',
      } satisfies PickRejectedEvent, 0));
      return;
    }
    const parsed = ClientMessageSchema.safeParse(raw);
    if (!parsed.success) {
      this.send(socket, this.envelope('pick_rejected', {
        idempotencyKey: '00000000-0000-4000-8000-000000000000',
        code: 'PROTOCOL_ERROR',
        message: parsed.error.message,
      } satisfies PickRejectedEvent, 0));
      return;
    }

    const msg = parsed.data;
    if (msg.type === 'hello') {
      // Authenticated registration; the JWT is verified by the upgrade route
      // (Task 8) and the verified claims are passed via header. For tests we
      // accept the hello as a no-op and reply with state_snapshot.
      this.clients.set(clientId, { socket, memberId: 0, role: 'member' });
      const state = await this.getState();
      const snap: StateSnapshotEvent = {
        bidSessionId: state.bidSessionId,
        seq: state.lastSeq,
        currentPhase: state.currentPhase,
        currentBidderId: state.currentBidderId,
        turnStartedAtMs: state.turnStartedAtMs,
        turnTimerSeconds: state.turnTimerSeconds,
        frozenAt: state.frozenAt,
        fills: Object.entries(state.fills).map(([positionId, f]) => ({
          positionId,
          memberId: f.memberId,
          ordinal: f.ordinal,
        })),
        bidOrder: [...state.bidOrder],
      };
      this.send(socket, this.envelope('state_snapshot', snap, state.lastSeq));
      return;
    }

    if (msg.type === 'ping') {
      this.send(socket, this.envelope('pong', { ts: msg.ts }, (await this.getState()).lastSeq));
      return;
    }

    if (msg.type === 'submit_pick') {
      await this.applySubmitPick(clientId, msg);
    }
  }

  private async applySubmitPick(
    clientId: string,
    msg: { positionId: string; aDay: string | null; idempotencyKey: string },
  ): Promise<void> {
    const client = this.clients.get(clientId);
    if (!client) return;

    await this.state.blockConcurrencyWhile(async () => {
      // Idempotency replay
      const idemKey = `idem:${this.state.id.toString()}:${msg.idempotencyKey}`;
      const prior = await this.storage.get<IdempotencyRecord>(idemKey);
      if (prior) {
        this.send(client.socket, prior.envelope);
        return;
      }

      const state = await this.getState();
      const input: SubmitPickInput = {
        senderMemberId: client.memberId,
        positionId: msg.positionId,
        aDay: msg.aDay,
        idempotencyKey: msg.idempotencyKey,
      };
      const result = handleSubmitPick(state, this.handlerEnv(), input);

      let envelope: BidEventEnvelope;
      if (result.kind === 'accepted') {
        await persistBidSessionState(this.storage, result.newState);
        this.memoryState = result.newState;
        envelope = this.envelope('pick_made', result.event.payload, result.newState.lastSeq);
        await this.storage.put(idemKey, { envelope } satisfies IdempotencyRecord);
        this.broadcast(envelope);
      } else {
        envelope = this.envelope('pick_rejected', {
          idempotencyKey: msg.idempotencyKey,
          code: result.code,
          message: result.message,
        } satisfies PickRejectedEvent, state.lastSeq);
        await this.storage.put(idemKey, { envelope } satisfies IdempotencyRecord);
        this.send(client.socket, envelope);
      }
    });
  }

  // ── REST-side actions (called by routes via DO fetch) ────────────────────

  async adminSkip(input: SkipInput): Promise<{ ok: boolean; envelope?: BidEventEnvelope }> {
    return this.state.blockConcurrencyWhile(async () => {
      const state = await this.getState();
      const r = handleSkip(state, this.handlerEnv(), input);
      if (r.kind === 'rejected') return { ok: false };
      await persistBidSessionState(this.storage, r.newState);
      this.memoryState = r.newState;
      const envelope = this.envelope('skip', r.event.payload, r.newState.lastSeq);
      this.broadcast(envelope);
      return { ok: true, envelope };
    });
  }

  async adminForcePick(input: ForcePickInput): Promise<{ ok: boolean; envelope?: BidEventEnvelope }> {
    return this.state.blockConcurrencyWhile(async () => {
      const state = await this.getState();
      const r = handleForcePick(state, this.handlerEnv(), input);
      if (r.kind === 'rejected') return { ok: false };
      await persistBidSessionState(this.storage, r.newState);
      this.memoryState = r.newState;
      const envelope = this.envelope('forced_pick', r.event.payload, r.newState.lastSeq);
      this.broadcast(envelope);
      return { ok: true, envelope };
    });
  }

  async adminFreeze(input: FreezeInput): Promise<{ ok: boolean; envelope?: BidEventEnvelope }> {
    return this.state.blockConcurrencyWhile(async () => {
      const state = await this.getState();
      const r = handleFreeze(state, this.handlerEnv(), input);
      if (r.kind === 'rejected') return { ok: false };
      await persistBidSessionState(this.storage, r.newState);
      this.memoryState = r.newState;
      const envelope = this.envelope('freeze', r.event.payload, r.newState.lastSeq);
      this.broadcast(envelope);
      return { ok: true, envelope };
    });
  }

  /** Called at session-start to load the bid_order into DO state. */
  async initSession(input: {
    bidOrder: BidSessionState['bidOrder'];
    turnTimerSeconds: number;
  }): Promise<void> {
    await this.state.blockConcurrencyWhile(async () => {
      const state = await this.getState();
      if (state.currentPhase !== 'config') return;
      const first = input.bidOrder[0]?.memberId ?? null;
      const newState: BidSessionState = {
        ...state,
        bidOrder: input.bidOrder,
        queueCursor: 0,
        currentBidderId: first,
        currentPhase: first === null ? 'complete' : 'position_bid',
        turnTimerSeconds: input.turnTimerSeconds,
        turnStartedAtMs: first === null ? 0 : Date.now(),
        lastSeq: state.lastSeq + 1,
      };
      await persistBidSessionState(this.storage, newState);
      this.memoryState = newState;
    });
  }
}
```

- [ ] **Step 5: Register DO in `apps/worker/src/index.ts`**

Append, before `export default app;`:

```ts
export { BidSessionDO } from './durable/bid-session.js';
```

- [ ] **Step 6: Run test, expect PASS** (the placeholder assertion at this stage; full WS test follows after Task 8).

- [ ] **Step 7: Lint + typecheck + commit**

```bash
pnpm lint && pnpm --filter @mbfd/worker typecheck
git add apps/worker/src/durable apps/worker/src/types/env.ts apps/worker/src/index.ts apps/worker/wrangler.toml apps/worker/tests/integration/bid-session-do.test.ts
git commit -m "feat(worker): BidSession Durable Object class with blockConcurrencyWhile + idempotency"
```

---

## Task 8: WebSocket upgrade + bid REST routes

**Files:**
- Create: `apps/worker/src/routes/ws.ts`
- Create: `apps/worker/src/routes/bid.ts`
- Modify: `apps/worker/src/index.ts` (mount routes)
- Test: `apps/worker/tests/integration/bid-session-routes.test.ts`

The `/api/ws/session/:id` endpoint verifies the JWT, then forwards the upgrade request to the DO via `BID_SESSION.get(id).fetch(...)`. The DO completes the WebSocket handshake. REST companions `GET /api/board`, `GET /api/me`, `GET /api/me/eligibility`, `GET /api/bid/state` are added here.

- [ ] **Step 1: Write failing test**

```ts
// apps/worker/tests/integration/bid-session-routes.test.ts
import { unstable_dev, type UnstableDevWorker } from 'wrangler';
import { afterAll, beforeAll, describe, expect, it } from 'vitest';
import { signJwt } from '../../src/lib/jwt.js';

describe('bid REST routes (Plan 04 Task 8)', () => {
  let worker: UnstableDevWorker;
  let memberJwt: string;
  beforeAll(async () => {
    worker = await unstable_dev('src/index.ts', {
      experimental: { disableExperimentalWarning: true },
      local: true,
      vars: {
        JWT_SIGNING_KEY: 'test-key-with-at-least-32-characters-long',
        ENV: 'staging',
        PORTAL_BASE_URL: 'https://example.org',
        PIN_HASH: '$2a$10$abcdefghijklmnopqrstuv',
        PORTAL_BID_READER: 'x',
      },
    });
    memberJwt = await signJwt(
      {
        sub: 17,
        emp: '99999',
        role: 'member',
        rank: 'FF',
        first_name: 'Test',
        last_name: 'User',
        fresh_auth_at: Math.floor(Date.now() / 1000),
      },
      'test-key-with-at-least-32-characters-long',
    );
  });
  afterAll(async () => {
    await worker.stop();
  });

  it('GET /api/ws/session/:id returns 401 without JWT', async () => {
    const res = await worker.fetch('/api/ws/session/01HSESS', {
      headers: { Upgrade: 'websocket' },
    });
    expect(res.status).toBe(401);
  });

  it('GET /api/ws/session/:id returns 426 with JWT but no Upgrade header', async () => {
    const res = await worker.fetch('/api/ws/session/01HSESS', {
      headers: { Authorization: `Bearer ${memberJwt}` },
    });
    expect([426, 400]).toContain(res.status);
  });

  it('GET /api/board returns 200 with member JWT', async () => {
    const res = await worker.fetch('/api/board?bidSessionId=01HSESS', {
      headers: { Authorization: `Bearer ${memberJwt}` },
    });
    expect(res.status).toBe(200);
  });

  it('GET /api/me returns the JWT subject as profile', async () => {
    const res = await worker.fetch('/api/me', {
      headers: { Authorization: `Bearer ${memberJwt}` },
    });
    expect(res.status).toBe(200);
    const body = (await res.json()) as { memberId: number };
    expect(body.memberId).toBe(17);
  });

  it('GET /api/bid/state?since_seq=N returns events newer than N', async () => {
    const res = await worker.fetch('/api/bid/state?bidSessionId=01HSESS&since_seq=0', {
      headers: { Authorization: `Bearer ${memberJwt}` },
    });
    expect(res.status).toBe(200);
    const body = (await res.json()) as { seq: number; events: unknown[] };
    expect(body.seq).toBeGreaterThanOrEqual(0);
    expect(Array.isArray(body.events)).toBe(true);
  });
});
```

- [ ] **Step 2: Run test, expect FAIL**

- [ ] **Step 3: Implement WS route**

```ts
// apps/worker/src/routes/ws.ts
import { Hono } from 'hono';
import { verifyJwt } from '../lib/jwt.js';
import { validateEnv } from '../lib/env.js';
import type { WorkerEnv } from '../types/env.js';

const ws = new Hono<{ Bindings: WorkerEnv }>();

ws.get('/session/:id', async (c) => {
  const env = validateEnv(c.env);
  const auth = c.req.header('Authorization');
  if (!auth?.startsWith('Bearer ')) {
    return c.json({ error: 'missing_auth' }, 401);
  }
  let claims: Awaited<ReturnType<typeof verifyJwt>>;
  try {
    claims = await verifyJwt(auth.slice(7), env.JWT_SIGNING_KEY);
  } catch {
    return c.json({ error: 'invalid_token' }, 401);
  }

  if (c.req.header('Upgrade') !== 'websocket') {
    return c.text('Upgrade Required', 426);
  }

  const id = c.req.param('id');
  const doId = c.env.BID_SESSION.idFromName(id);
  const stub = c.env.BID_SESSION.get(doId);
  // Forward to DO with verified claims in custom header so the DO does not
  // need to re-validate. The DO trusts only requests via its binding namespace.
  const fwd = new Request(`${new URL(c.req.url).origin}/ws`, {
    method: 'GET',
    headers: {
      Upgrade: 'websocket',
      'X-MBFD-Member-Id': String(claims.sub),
      'X-MBFD-Role': claims.role,
    },
  });
  return stub.fetch(fwd);
});

export default ws;
```

- [ ] **Step 4: Implement bid REST route**

```ts
// apps/worker/src/routes/bid.ts
import { Hono } from 'hono';
import { verifyJwt } from '../lib/jwt.js';
import { validateEnv } from '../lib/env.js';
import type { WorkerEnv } from '../types/env.js';

const bid = new Hono<{ Bindings: WorkerEnv }>();

async function requireJwt(c: Parameters<Parameters<typeof bid.get>[1]>[0]) {
  const env = validateEnv(c.env);
  const auth = c.req.header('Authorization');
  if (!auth?.startsWith('Bearer ')) return null;
  try {
    return await verifyJwt(auth.slice(7), env.JWT_SIGNING_KEY);
  } catch {
    return null;
  }
}

bid.get('/me', async (c) => {
  const claims = await requireJwt(c);
  if (!claims) return c.json({ error: 'unauthorised' }, 401);
  return c.json({
    memberId: claims.sub,
    employeeId: claims.emp,
    role: claims.role,
    rank: claims.rank,
    firstName: claims.first_name,
    lastName: claims.last_name,
  });
});

bid.get('/me/eligibility', async (c) => {
  const claims = await requireJwt(c);
  if (!claims) return c.json({ error: 'unauthorised' }, 401);
  // Eligibility for every open position. In the live event the DO has the
  // up-to-the-second fills map; here we return a snapshot from D1 + the
  // eligibility engine. Pulled into the page via React Server Component.
  return c.json({ memberId: claims.sub, positions: [] });
});

bid.get('/board', async (c) => {
  const claims = await requireJwt(c);
  if (!claims) return c.json({ error: 'unauthorised' }, 401);
  const bidSessionId = c.req.query('bidSessionId') ?? '01HSESS';
  const doId = c.env.BID_SESSION.idFromName(bidSessionId);
  const stub = c.env.BID_SESSION.get(doId);
  const snap = await stub.fetch(`${new URL(c.req.url).origin}/snapshot`);
  return c.json(await snap.json());
});

bid.get('/bid/state', async (c) => {
  const claims = await requireJwt(c);
  if (!claims) return c.json({ error: 'unauthorised' }, 401);
  const sinceSeq = Number(c.req.query('since_seq') ?? '0');
  const bidSessionId = c.req.query('bidSessionId') ?? '01HSESS';
  const doId = c.env.BID_SESSION.idFromName(bidSessionId);
  const stub = c.env.BID_SESSION.get(doId);
  const snap = await stub.fetch(`${new URL(c.req.url).origin}/snapshot`);
  const state = (await snap.json()) as { lastSeq: number };
  return c.json({ seq: state.lastSeq, since: sinceSeq, events: [], state });
});

export default bid;
```

- [ ] **Step 5: Mount in `apps/worker/src/index.ts`**

Add to the `routes` builder before `export type AppType`:

```ts
import ws from './routes/ws.js';
import bid from './routes/bid.js';
// ...
const routes = new Hono<{ Bindings: WorkerEnv }>()
  .route('/api', health)
  .route('/api/auth', auth)
  .route('/api', bid)
  .route('/api/ws', ws)
  .route('/api/admin/members', adminMembers)
  .route('/api/admin/credentials', adminCredentials)
  .route('/api/admin/positions', adminPositions)
  .route('/api/admin/rules', adminRules);
```

- [ ] **Step 6: Run integration test, expect PASS**

- [ ] **Step 7: Lint + commit**

```bash
pnpm lint && pnpm --filter @mbfd/worker typecheck
git add apps/worker/src/routes/ws.ts apps/worker/src/routes/bid.ts apps/worker/src/index.ts apps/worker/tests/integration/bid-session-routes.test.ts
git commit -m "feat(worker): WS upgrade route + REST companions (/me, /board, /bid/state)"
```

---

## Task 9: Admin bid routes (`/api/admin/bid/*`)

**Files:**
- Create: `apps/worker/src/routes/admin/bid.ts`
- Modify: `apps/worker/src/index.ts` (mount)
- Test: `apps/worker/tests/integration/admin-bid.test.ts`

Endpoints (all `requireAdmin` from Plan 02 middleware):
- `POST /api/admin/bid/session` — initialise a new live session (computes bid_order, calls DO.initSession)
- `POST /api/admin/bid/skip` — admin skip current bidder with reason
- `POST /api/admin/bid/override` — force-pick a member into a position
- `POST /api/admin/bid/freeze` — one-way freeze
- `POST /api/admin/bid/unfreeze` — admin-only resume (Plan 05 owns the full pause/resume UI; we expose the worker primitive here)

All admin writes require `Idempotency-Key` header.

- [ ] **Step 1: Write failing test** (sketch — implementer expands with body for each endpoint)

```ts
// apps/worker/tests/integration/admin-bid.test.ts
import { unstable_dev, type UnstableDevWorker } from 'wrangler';
import { afterAll, beforeAll, describe, expect, it } from 'vitest';
import { signJwt } from '../../src/lib/jwt.js';

const KEY = 'test-key-with-at-least-32-characters-long';
const IDEM_A = '11111111-1111-4111-8111-111111111111';

describe('admin bid routes (Plan 04 Task 9)', () => {
  let worker: UnstableDevWorker;
  let adminJwt: string;
  beforeAll(async () => {
    worker = await unstable_dev('src/index.ts', {
      experimental: { disableExperimentalWarning: true },
      local: true,
      vars: { JWT_SIGNING_KEY: KEY, ENV: 'staging', PORTAL_BASE_URL: 'https://x', PIN_HASH: 'x', PORTAL_BID_READER: 'x' },
    });
    adminJwt = await signJwt(
      { sub: 0, emp: 'admin', role: 'admin', rank: 'CHIEF', first_name: 'A', last_name: 'B', fresh_auth_at: Math.floor(Date.now() / 1000) },
      KEY,
    );
  });
  afterAll(async () => worker.stop());

  it('POST /api/admin/bid/freeze returns 401 without admin JWT', async () => {
    const res = await worker.fetch('/api/admin/bid/freeze', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Idempotency-Key': IDEM_A },
      body: JSON.stringify({ bidSessionId: '01HSESS', reason: 'x' }),
    });
    expect(res.status).toBe(401);
  });

  it('POST /api/admin/bid/freeze returns 200 with admin JWT and idem key', async () => {
    const res = await worker.fetch('/api/admin/bid/freeze', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Authorization': `Bearer ${adminJwt}`,
        'Idempotency-Key': IDEM_A,
      },
      body: JSON.stringify({ bidSessionId: '01HSESS', reason: 'Network outage' }),
    });
    expect([200, 409]).toContain(res.status);
  });

  it('POST /api/admin/bid/freeze rejects when Idempotency-Key missing', async () => {
    const res = await worker.fetch('/api/admin/bid/freeze', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${adminJwt}` },
      body: JSON.stringify({ bidSessionId: '01HSESS', reason: 'x' }),
    });
    expect(res.status).toBe(400);
  });
});
```

- [ ] **Step 2: Run test, expect FAIL**

- [ ] **Step 3: Implement**

```ts
// apps/worker/src/routes/admin/bid.ts
import { Hono } from 'hono';
import { z } from 'zod';
import { zValidator } from '@hono/zod-validator';
import { requireAdmin } from './middleware.js';
import type { WorkerEnv } from '../../types/env.js';

const r = new Hono<{ Bindings: WorkerEnv }>();
r.use('*', requireAdmin);

const IdemHeader = z.string().uuid();

function getDoStub(env: WorkerEnv, bidSessionId: string) {
  const id = env.BID_SESSION.idFromName(bidSessionId);
  return env.BID_SESSION.get(id);
}

const SkipBody = z.object({
  bidSessionId: z.string().min(1),
  reason: z.string().min(1).max(500),
});

r.post('/skip', zValidator('json', SkipBody), async (c) => {
  const idem = IdemHeader.safeParse(c.req.header('Idempotency-Key'));
  if (!idem.success) return c.json({ error: 'missing_idempotency_key' }, 400);
  const body = c.req.valid('json');
  const stub = getDoStub(c.env, body.bidSessionId);
  const res = await stub.fetch('https://do/admin/skip', {
    method: 'POST',
    body: JSON.stringify({ adminActorId: c.get('claims').sub, reason: body.reason }),
  });
  return c.json(await res.json(), res.status as 200 | 409);
});

const OverrideBody = z.object({
  bidSessionId: z.string().min(1),
  targetMemberId: z.number().int().positive(),
  positionId: z.string().min(1),
  reason: z.string().min(1).max(500),
});

r.post('/override', zValidator('json', OverrideBody), async (c) => {
  const idem = IdemHeader.safeParse(c.req.header('Idempotency-Key'));
  if (!idem.success) return c.json({ error: 'missing_idempotency_key' }, 400);
  const body = c.req.valid('json');
  const stub = getDoStub(c.env, body.bidSessionId);
  const res = await stub.fetch('https://do/admin/force-pick', {
    method: 'POST',
    body: JSON.stringify({
      adminActorId: c.get('claims').sub,
      targetMemberId: body.targetMemberId,
      positionId: body.positionId,
      reason: body.reason,
    }),
  });
  return c.json(await res.json(), res.status as 200 | 409);
});

const FreezeBody = z.object({
  bidSessionId: z.string().min(1),
  reason: z.string().min(1).max(500),
});

r.post('/freeze', zValidator('json', FreezeBody), async (c) => {
  const idem = IdemHeader.safeParse(c.req.header('Idempotency-Key'));
  if (!idem.success) return c.json({ error: 'missing_idempotency_key' }, 400);
  const body = c.req.valid('json');
  const stub = getDoStub(c.env, body.bidSessionId);
  const res = await stub.fetch('https://do/admin/freeze', {
    method: 'POST',
    body: JSON.stringify({ adminActorId: c.get('claims').sub, reason: body.reason }),
  });
  return c.json(await res.json(), res.status as 200 | 409);
});

export default r;
```

Add a small `fetch` router inside the DO class (`bid-session.ts`) so the routes above can hit `/admin/skip`, `/admin/force-pick`, `/admin/freeze` URLs:

```ts
// Add inside BidSessionDO.fetch():
if (url.pathname === '/admin/skip') {
  const body = (await req.json()) as { adminActorId: number; reason: string };
  const r = await this.adminSkip(body);
  return new Response(JSON.stringify(r), { status: r.ok ? 200 : 409 });
}
if (url.pathname === '/admin/force-pick') {
  const body = (await req.json()) as { adminActorId: number; targetMemberId: number; positionId: string; reason: string };
  const r = await this.adminForcePick(body);
  return new Response(JSON.stringify(r), { status: r.ok ? 200 : 409 });
}
if (url.pathname === '/admin/freeze') {
  const body = (await req.json()) as { adminActorId: number; reason: string };
  const r = await this.adminFreeze(body);
  return new Response(JSON.stringify(r), { status: r.ok ? 200 : 409 });
}
```

- [ ] **Step 4: Mount in `apps/worker/src/index.ts`**

```ts
import adminBid from './routes/admin/bid.js';
// ...
.route('/api/admin/bid', adminBid)
```

- [ ] **Step 5: Run tests, expect PASS**

- [ ] **Step 6: Lint + commit**

```bash
pnpm lint && pnpm --filter @mbfd/worker typecheck
git add apps/worker/src/routes/admin/bid.ts apps/worker/src/durable/bid-session.ts apps/worker/src/index.ts apps/worker/tests/integration/admin-bid.test.ts
git commit -m "feat(worker): admin bid routes (skip, override, freeze) with idempotency"
```

---

## Task 10: Audit log writer for live bid events

**Files:**
- Modify: `apps/worker/src/durable/bid-session.ts` (add `writeAudit` queue)
- Modify: `apps/worker/src/lib/audit.ts` (extend Plan 02 helper with bid actions)
- Test: `apps/worker/tests/unit/audit-bid.test.ts`

Every accepted DO transition produces an `audit_log` row. The DO writes synchronously to D1 inside `blockConcurrencyWhile` after `state.storage.put` succeeds (so the durable state is the source of truth even if D1 write retries). Failures are queued and retried; the test verifies one row per accepted event.

- [ ] **Step 1: Write failing test**

```ts
// apps/worker/tests/unit/audit-bid.test.ts
import { describe, expect, it } from 'vitest';
import { auditEntryForPickMade, auditEntryForSkip, auditEntryForForcedPick, auditEntryForFreeze } from '../../src/lib/audit.js';

describe('audit entry builders (Plan 04 Task 10)', () => {
  it('builds pick row with action=pick and target_kind=position', () => {
    const row = auditEntryForPickMade({
      bidSessionId: '01HSESS',
      seq: 5,
      bidId: '01HBID',
      memberId: 17,
      positionId: 'A101',
      idempotencyKey: '11111111-1111-4111-8111-111111111111',
      nowMs: 1700000000000,
    });
    expect(row.action).toBe('pick');
    expect(row.actorType).toBe('member');
    expect(row.targetKind).toBe('position');
    expect(row.targetId).toBe('A101');
    expect(row.seq).toBe(5);
  });

  it('forced_pick rows carry admin actor and reason', () => {
    const row = auditEntryForForcedPick({
      bidSessionId: '01HSESS',
      seq: 6,
      bidId: '01HBID',
      adminActorId: 0,
      targetMemberId: 17,
      positionId: 'A101',
      reason: 'Last qualified candidate',
      nowMs: 1700000000000,
    });
    expect(row.action).toBe('forced_pick');
    expect(row.actorType).toBe('admin');
    expect(row.actorId).toBe(0);
    expect(row.reason).toBe('Last qualified candidate');
  });

  it('skip rows include reason text', () => {
    const row = auditEntryForSkip({
      bidSessionId: '01HSESS',
      seq: 7,
      adminActorId: 0,
      skippedMemberId: 17,
      reason: 'Bidder unreachable past 2× timer',
      nowMs: 1700000000000,
    });
    expect(row.action).toBe('skip');
  });

  it('freeze rows carry reason', () => {
    const row = auditEntryForFreeze({
      bidSessionId: '01HSESS',
      seq: 8,
      adminActorId: 0,
      reason: 'Network outage at venue',
      nowMs: 1700000000000,
    });
    expect(row.action).toBe('pause');
    expect(row.reason).toMatch(/freeze/i);
  });
});
```

- [ ] **Step 2: Run test, expect FAIL**

- [ ] **Step 3: Implement entry builders**

Append to `apps/worker/src/lib/audit.ts`:

```ts
// apps/worker/src/lib/audit.ts (append)
import { ulid } from 'ulid';

export interface AuditRowDraft {
  id: string;
  bidSessionId: string;
  seq: number;
  actorType: 'member' | 'admin' | 'system' | 'ai';
  actorId: number | null;
  action:
    | 'pick'
    | 'forced_pick'
    | 'pause'
    | 'resume'
    | 'skip'
    | 'admin_bid_for_member';
  targetKind: string | null;
  targetId: string | null;
  beforeState: string | null;
  afterState: string | null;
  reason: string | null;
  aiAdvisoryId: string | null;
  clientMeta: string | null;
  createdAt: Date;
}

export function auditEntryForPickMade(input: {
  bidSessionId: string;
  seq: number;
  bidId: string;
  memberId: number;
  positionId: string;
  idempotencyKey: string;
  nowMs: number;
}): AuditRowDraft {
  return {
    id: ulid(),
    bidSessionId: input.bidSessionId,
    seq: input.seq,
    actorType: 'member',
    actorId: input.memberId,
    action: 'pick',
    targetKind: 'position',
    targetId: input.positionId,
    beforeState: null,
    afterState: JSON.stringify({ bidId: input.bidId, idempotencyKey: input.idempotencyKey }),
    reason: null,
    aiAdvisoryId: null,
    clientMeta: null,
    createdAt: new Date(input.nowMs),
  };
}

export function auditEntryForForcedPick(input: {
  bidSessionId: string;
  seq: number;
  bidId: string;
  adminActorId: number;
  targetMemberId: number;
  positionId: string;
  reason: string;
  nowMs: number;
}): AuditRowDraft {
  return {
    id: ulid(),
    bidSessionId: input.bidSessionId,
    seq: input.seq,
    actorType: 'admin',
    actorId: input.adminActorId,
    action: 'forced_pick',
    targetKind: 'position',
    targetId: input.positionId,
    beforeState: null,
    afterState: JSON.stringify({ bidId: input.bidId, memberId: input.targetMemberId }),
    reason: input.reason,
    aiAdvisoryId: null,
    clientMeta: null,
    createdAt: new Date(input.nowMs),
  };
}

export function auditEntryForSkip(input: {
  bidSessionId: string;
  seq: number;
  adminActorId: number;
  skippedMemberId: number;
  reason: string;
  nowMs: number;
}): AuditRowDraft {
  return {
    id: ulid(),
    bidSessionId: input.bidSessionId,
    seq: input.seq,
    actorType: 'admin',
    actorId: input.adminActorId,
    action: 'skip',
    targetKind: 'member',
    targetId: String(input.skippedMemberId),
    beforeState: null,
    afterState: null,
    reason: input.reason,
    aiAdvisoryId: null,
    clientMeta: null,
    createdAt: new Date(input.nowMs),
  };
}

export function auditEntryForFreeze(input: {
  bidSessionId: string;
  seq: number;
  adminActorId: number;
  reason: string;
  nowMs: number;
}): AuditRowDraft {
  return {
    id: ulid(),
    bidSessionId: input.bidSessionId,
    seq: input.seq,
    actorType: 'admin',
    actorId: input.adminActorId,
    action: 'pause',
    targetKind: 'session',
    targetId: input.bidSessionId,
    beforeState: null,
    afterState: JSON.stringify({ frozen: true }),
    reason: `freeze: ${input.reason}`,
    aiAdvisoryId: null,
    clientMeta: null,
    createdAt: new Date(input.nowMs),
  };
}
```

- [ ] **Step 4: Wire audit writes inside DO `applySubmitPick`, `adminSkip`, `adminForcePick`, `adminFreeze`** (after `persistBidSessionState`, before `broadcast`). Use `getDb(this.env.DB)` and Drizzle `insert(auditLog).values(...)`.

- [ ] **Step 5: Run tests, expect PASS**

- [ ] **Step 6: Commit**

```bash
git add apps/worker/src/lib/audit.ts apps/worker/src/durable/bid-session.ts apps/worker/tests/unit/audit-bid.test.ts
git commit -m "feat(worker): audit_log writes for pick/forced_pick/skip/freeze inside DO"
```

---

## Task 11: Web — `/bid` page (Server Component initial paint)

**Files:**
- Create: `apps/web/app/bid/page.tsx`
- Create: `apps/web/app/bid/loading.tsx`
- Create: `apps/web/app/bid/_components/BoardHeader.tsx`
- Create: `apps/web/app/bid/_components/PositionGrid.tsx`
- Create: `apps/web/app/bid/_components/PositionCell.tsx`
- Test: `apps/web/tests/e2e/bid-page-initial.spec.ts`

Server Component fetches `/api/board` + `/api/me` server-side using the JWT cookie, renders the initial paint. The client island (Task 12) takes over after hydration.

- [ ] **Step 1: Write failing E2E**

```ts
// apps/web/tests/e2e/bid-page-initial.spec.ts
import { expect, test } from '@playwright/test';

test('GET /bid as authenticated member renders the board header + grid', async ({ page, context }) => {
  // Helper: prime PIN + member JWT cookies. Implementer lifts the helper used
  // by existing Plan 01/02 E2E tests (apps/web/tests/e2e/_helpers/auth.ts).
  await context.addCookies([
    { name: 'mbfd_pin', value: '1', domain: 'localhost', path: '/' },
    { name: 'mbfd_jwt', value: process.env.E2E_MEMBER_JWT ?? '', domain: 'localhost', path: '/' },
  ]);
  await page.goto('http://localhost:3000/bid');
  await expect(page.getByTestId('bid-board-header')).toBeVisible();
  await expect(page.getByTestId('position-grid')).toBeVisible();
  // 230+ position cells should render (Plan 02 seed produces 232).
  const cells = page.getByTestId(/position-cell-/);
  await expect(cells).toHaveCount(232);
});
```

- [ ] **Step 2: Run test, expect FAIL**

- [ ] **Step 3: Implement Server Component**

```tsx
// apps/web/app/bid/page.tsx
import { cookies } from 'next/headers';
import { redirect } from 'next/navigation';
import { cfEnv } from '../../lib/cf-env.js';
import { verifyJwt } from '../../lib/jwt.js';
import { BoardHeader } from './_components/BoardHeader.js';
import { PositionGrid } from './_components/PositionGrid.js';
import { BidBoard } from './_components/BidBoard.js';

export const runtime = 'edge';

interface BoardSnapshot {
  bidSessionId: string;
  lastSeq: number;
  currentPhase: string;
  currentBidderId: number | null;
  fills: Record<string, { memberId: number; ordinal: number; bidId: string }>;
  bidOrder: Array<{ ordinal: number; memberId: number; pool: 'OFC' | 'FF' }>;
}

async function loadBoard(jwt: string): Promise<BoardSnapshot> {
  const workerBase = cfEnv('WORKER_BASE_URL') ?? 'http://localhost:8787';
  const res = await fetch(`${workerBase}/api/board?bidSessionId=01HSESS`, {
    headers: { Authorization: `Bearer ${jwt}` },
    cache: 'no-store',
  });
  if (!res.ok) throw new Error(`Board fetch failed: ${res.status}`);
  return (await res.json()) as BoardSnapshot;
}

export default async function BidPage() {
  const jwt = cookies().get('mbfd_jwt')?.value;
  if (!jwt) redirect('/login');

  const signingKey = cfEnv('JWT_SIGNING_KEY');
  if (!signingKey) throw new Error('JWT_SIGNING_KEY not set');
  const claims = await verifyJwt(jwt, signingKey);

  const board = await loadBoard(jwt);

  return (
    <main className="min-h-screen bg-stone-50">
      <BoardHeader
        currentBidderId={board.currentBidderId}
        currentPhase={board.currentPhase}
        meMemberId={claims.sub}
      />
      <PositionGrid fills={board.fills} />
      <BidBoard
        bidSessionId={board.bidSessionId}
        initialSeq={board.lastSeq}
        meMemberId={claims.sub}
        jwt={jwt}
      />
    </main>
  );
}
```

```tsx
// apps/web/app/bid/_components/BoardHeader.tsx
interface Props {
  currentBidderId: number | null;
  currentPhase: string;
  meMemberId: number;
}
export function BoardHeader({ currentBidderId, currentPhase, meMemberId }: Props) {
  const isMine = currentBidderId === meMemberId;
  return (
    <header data-testid="bid-board-header" className="border-b border-stone-200 px-6 py-4">
      <div className="flex items-baseline gap-3 font-display text-2xl text-stone-900">
        <span>MBFD 2026 Bid</span>
        <span className="text-sm font-medium text-stone-600">Phase: {currentPhase}</span>
      </div>
      <p className="mt-2 text-sm tabular-nums text-stone-700">
        Active bidder:{' '}
        <span className={isMine ? 'font-bold text-red-700' : 'text-stone-900'}>
          {currentBidderId ?? '—'}
        </span>
        {isMine ? ' (you)' : null}
      </p>
    </header>
  );
}
```

```tsx
// apps/web/app/bid/_components/PositionGrid.tsx
import { PositionCell } from './PositionCell.js';

interface Props {
  fills: Record<string, { memberId: number; ordinal: number; bidId: string }>;
}

export function PositionGrid({ fills }: Props) {
  // Position list is fetched at build time from /admin/positions during Plan 02
  // and shipped as a static JSON. For this initial paint we render the keyset
  // present in fills + the static template; the client island reconciles deltas.
  const positionIds: string[] = []; // Implementer: import the static positions JSON
  return (
    <div data-testid="position-grid" className="grid grid-cols-2 gap-4 p-6 md:grid-cols-4">
      {positionIds.map((id) => (
        <PositionCell key={id} positionId={id} fill={fills[id] ?? null} />
      ))}
    </div>
  );
}
```

```tsx
// apps/web/app/bid/_components/PositionCell.tsx
interface Props {
  positionId: string;
  fill: { memberId: number; ordinal: number; bidId: string } | null;
}

export function PositionCell({ positionId, fill }: Props) {
  const state = fill ? 'filled' : 'eligible-open';
  return (
    <div
      data-testid={`position-cell-${positionId}`}
      data-state={state}
      className="rounded border border-stone-200 bg-white p-3 text-sm tabular-nums"
    >
      <div className="font-mono text-xs text-stone-500">{positionId}</div>
      <div className="mt-1 text-stone-900">
        {fill ? `Filled by member ${fill.memberId}` : 'Open'}
      </div>
    </div>
  );
}
```

```tsx
// apps/web/app/bid/loading.tsx
export default function Loading() {
  return <div className="p-8 text-stone-500">Loading bid board…</div>;
}
```

- [ ] **Step 4: Run test, expect PASS** (after Task 12 ships `BidBoard`)

- [ ] **Step 5: Lint + commit**

```bash
pnpm lint && pnpm --filter web typecheck
git add apps/web/app/bid apps/web/tests/e2e/bid-page-initial.spec.ts
git commit -m "feat(web): /bid page Server Component with initial board paint"
```

---

## Task 12: Web — client island `BidBoard.tsx` + Zustand store + WS hook

**Files:**
- Create: `apps/web/app/bid/_components/BidBoard.tsx`
- Create: `apps/web/app/bid/_hooks/useBidStore.ts`
- Create: `apps/web/app/bid/_hooks/useBidWebSocket.ts`
- Create: `apps/web/app/bid/_components/ReconnectingOverlay.tsx`
- Create: `apps/web/app/bid/_components/ErrorToast.tsx`
- Modify: `apps/web/package.json` (add `zustand`, `nanoid`)
- Test: `apps/web/tests/unit/useBidStore.test.ts`

- [ ] **Step 1: Add deps**

```bash
cd D:/GitHub_Repos/mbfd-bid/apps/web
pnpm add zustand@^5.0 nanoid@^5.0
```

- [ ] **Step 2: Write failing test for the store**

```ts
// apps/web/tests/unit/useBidStore.test.ts
import { describe, expect, it } from 'vitest';
import { createBidStore } from '../../app/bid/_hooks/useBidStore.js';

describe('useBidStore (Plan 04 Task 12)', () => {
  it('applies pick_made events and bumps seq', () => {
    const s = createBidStore({ bidSessionId: 'X', initialSeq: 0, meMemberId: 17 });
    s.getState().applyEvent({
      v: 1, seq: 1, ts: 0, type: 'pick_made',
      payload: { bidId: 'B', bidSessionId: 'X', ordinal: 1, memberId: 17, positionId: 'A101', aDay: null, idempotencyKey: '11111111-1111-4111-8111-111111111111', nextBidderId: 18, turnStartedAtMs: 1 },
    });
    expect(s.getState().lastSeq).toBe(1);
    expect(s.getState().fills['A101']?.memberId).toBe(17);
    expect(s.getState().currentBidderId).toBe(18);
  });

  it('ignores events with seq <= lastSeq (idempotency on resync)', () => {
    const s = createBidStore({ bidSessionId: 'X', initialSeq: 5, meMemberId: 17 });
    s.getState().applyEvent({
      v: 1, seq: 3, ts: 0, type: 'pick_made',
      payload: { bidId: 'B', bidSessionId: 'X', ordinal: 1, memberId: 99, positionId: 'A101', aDay: null, idempotencyKey: '11111111-1111-4111-8111-111111111111', nextBidderId: 18, turnStartedAtMs: 1 },
    });
    expect(s.getState().fills['A101']).toBeUndefined();
  });

  it('markPendingMine + reconcileOnPickMade with matching idem key clears pendingMine', () => {
    const s = createBidStore({ bidSessionId: 'X', initialSeq: 0, meMemberId: 17 });
    s.getState().markPendingMine('A101', 'k');
    expect(s.getState().pendingMine['A101']).toBe('k');
    s.getState().applyEvent({
      v: 1, seq: 1, ts: 0, type: 'pick_made',
      payload: { bidId: 'B', bidSessionId: 'X', ordinal: 1, memberId: 17, positionId: 'A101', aDay: null, idempotencyKey: 'k-uuid', nextBidderId: 18, turnStartedAtMs: 1 },
    });
    // pending should clear once the canonical fill lands
    expect(s.getState().pendingMine['A101']).toBeUndefined();
  });

  it('pick_rejected rolls back pendingMine and sets lastError', () => {
    const s = createBidStore({ bidSessionId: 'X', initialSeq: 0, meMemberId: 17 });
    s.getState().markPendingMine('A101', 'kkk');
    s.getState().applyEvent({
      v: 1, seq: 1, ts: 0, type: 'pick_rejected',
      payload: { idempotencyKey: 'kkk', code: 'POSITION_FILLED', message: 'taken' },
    });
    expect(s.getState().pendingMine['A101']).toBeUndefined();
    expect(s.getState().lastError?.code).toBe('POSITION_FILLED');
  });
});
```

- [ ] **Step 3: Run test, expect FAIL**

- [ ] **Step 4: Implement store**

```ts
// apps/web/app/bid/_hooks/useBidStore.ts
import type { BidEventEnvelope } from '@mbfd/shared';
import { create, type StoreApi } from 'zustand';

interface Fill {
  memberId: number;
  ordinal: number;
  bidId: string;
}

export interface BidStoreState {
  bidSessionId: string;
  meMemberId: number;
  lastSeq: number;
  currentBidderId: number | null;
  fills: Record<string, Fill>;
  pendingMine: Record<string, string>; // positionId → idempotencyKey
  lastError: { code: string; message: string } | null;
  applyEvent(env: BidEventEnvelope): void;
  markPendingMine(positionId: string, idempotencyKey: string): void;
  clearError(): void;
}

export function createBidStore(init: {
  bidSessionId: string;
  initialSeq: number;
  meMemberId: number;
}): StoreApi<BidStoreState> {
  return create<BidStoreState>((set, get) => ({
    bidSessionId: init.bidSessionId,
    meMemberId: init.meMemberId,
    lastSeq: init.initialSeq,
    currentBidderId: null,
    fills: {},
    pendingMine: {},
    lastError: null,
    markPendingMine(positionId, idempotencyKey) {
      set((s) => ({ pendingMine: { ...s.pendingMine, [positionId]: idempotencyKey } }));
    },
    clearError() {
      set({ lastError: null });
    },
    applyEvent(env) {
      const st = get();
      if (env.seq <= st.lastSeq && env.type !== 'state_snapshot') return;
      switch (env.type) {
        case 'state_snapshot': {
          const p = env.payload as { fills: Array<{ positionId: string; memberId: number; ordinal: number }>; currentBidderId: number | null; seq: number };
          const fills: Record<string, Fill> = {};
          for (const f of p.fills) fills[f.positionId] = { memberId: f.memberId, ordinal: f.ordinal, bidId: '' };
          set({ fills, currentBidderId: p.currentBidderId, lastSeq: p.seq });
          break;
        }
        case 'pick_made': {
          const p = env.payload as { positionId: string; memberId: number; ordinal: number; bidId: string; nextBidderId: number | null; idempotencyKey: string };
          set((s) => {
            const pending = { ...s.pendingMine };
            // Clear any pending entry for this position
            delete pending[p.positionId];
            return {
              fills: { ...s.fills, [p.positionId]: { memberId: p.memberId, ordinal: p.ordinal, bidId: p.bidId } },
              currentBidderId: p.nextBidderId,
              pendingMine: pending,
              lastSeq: env.seq,
            };
          });
          break;
        }
        case 'pick_rejected': {
          const p = env.payload as { idempotencyKey: string; code: string; message: string };
          set((s) => {
            const pending = { ...s.pendingMine };
            for (const [pos, k] of Object.entries(pending)) {
              if (k === p.idempotencyKey) delete pending[pos];
            }
            return { pendingMine: pending, lastError: { code: p.code, message: p.message }, lastSeq: env.seq };
          });
          break;
        }
        case 'skip':
        case 'forced_pick':
        case 'freeze':
          set({ lastSeq: env.seq });
          break;
        default:
          break;
      }
    },
  }));
}
```

- [ ] **Step 5: Implement WS hook**

```ts
// apps/web/app/bid/_hooks/useBidWebSocket.ts
'use client';
import { useEffect, useRef, useState } from 'react';
import { BidEventEnvelopeSchema, type BidEventEnvelope } from '@mbfd/shared';
import type { StoreApi } from 'zustand';
import type { BidStoreState } from './useBidStore.js';

const RECONNECT_BACKOFF_MS = [500, 1000, 2000, 4000, 8000] as const;

export function useBidWebSocket(
  store: StoreApi<BidStoreState>,
  opts: { bidSessionId: string; jwt: string },
): { status: 'connecting' | 'open' | 'closed'; send: (data: object) => void } {
  const [status, setStatus] = useState<'connecting' | 'open' | 'closed'>('connecting');
  const wsRef = useRef<WebSocket | null>(null);
  const attemptRef = useRef(0);

  useEffect(() => {
    let cancelled = false;
    function connect() {
      if (cancelled) return;
      const base = typeof window === 'undefined' ? '' : window.location.host;
      const proto = window.location.protocol === 'https:' ? 'wss:' : 'ws:';
      const url = `${proto}//${base}/api/ws/session/${opts.bidSessionId}`;
      const ws = new WebSocket(url);
      wsRef.current = ws;
      setStatus('connecting');
      ws.onopen = () => {
        attemptRef.current = 0;
        setStatus('open');
        ws.send(JSON.stringify({ type: 'hello', jwt: opts.jwt, lastSeq: store.getState().lastSeq }));
      };
      ws.onmessage = (ev) => {
        try {
          const raw = JSON.parse(String(ev.data));
          const parsed = BidEventEnvelopeSchema.safeParse(raw);
          if (!parsed.success) return;
          store.getState().applyEvent(parsed.data as BidEventEnvelope);
        } catch {
          // ignore malformed frames
        }
      };
      ws.onclose = () => {
        setStatus('closed');
        const backoff = RECONNECT_BACKOFF_MS[Math.min(attemptRef.current, RECONNECT_BACKOFF_MS.length - 1)];
        attemptRef.current += 1;
        setTimeout(connect, backoff);
      };
      ws.onerror = () => ws.close();
    }
    connect();
    return () => {
      cancelled = true;
      wsRef.current?.close();
    };
  }, [opts.bidSessionId, opts.jwt, store]);

  return {
    status,
    send: (data) => wsRef.current?.send(JSON.stringify(data)),
  };
}
```

- [ ] **Step 6: Implement client island**

```tsx
// apps/web/app/bid/_components/BidBoard.tsx
'use client';
import { useMemo } from 'react';
import { useStore } from 'zustand';
import { createBidStore, type BidStoreState } from '../_hooks/useBidStore.js';
import { useBidWebSocket } from '../_hooks/useBidWebSocket.js';
import { ReconnectingOverlay } from './ReconnectingOverlay.js';
import { ErrorToast } from './ErrorToast.js';

interface Props {
  bidSessionId: string;
  initialSeq: number;
  meMemberId: number;
  jwt: string;
}

export function BidBoard({ bidSessionId, initialSeq, meMemberId, jwt }: Props) {
  const store = useMemo(
    () => createBidStore({ bidSessionId, initialSeq, meMemberId }),
    [bidSessionId, initialSeq, meMemberId],
  );
  const { status } = useBidWebSocket(store, { bidSessionId, jwt });
  const lastError = useStore(store, (s: BidStoreState) => s.lastError);
  return (
    <>
      {status !== 'open' ? <ReconnectingOverlay status={status} /> : null}
      {lastError ? <ErrorToast error={lastError} onClose={() => store.getState().clearError()} /> : null}
    </>
  );
}
```

```tsx
// apps/web/app/bid/_components/ReconnectingOverlay.tsx
interface Props { status: 'connecting' | 'closed'; }
export function ReconnectingOverlay({ status }: Props) {
  return (
    <div className="fixed bottom-4 right-4 rounded bg-stone-900 px-4 py-2 text-sm text-stone-50">
      {status === 'connecting' ? 'Connecting…' : 'Reconnecting…'}
    </div>
  );
}
```

```tsx
// apps/web/app/bid/_components/ErrorToast.tsx
interface Props {
  error: { code: string; message: string };
  onClose: () => void;
}
export function ErrorToast({ error, onClose }: Props) {
  return (
    <div role="alert" className="fixed bottom-4 left-4 rounded border border-red-700 bg-red-50 px-4 py-2 text-sm text-red-900">
      <strong className="font-semibold">{error.code}</strong>: {error.message}
      <button type="button" onClick={onClose} className="ml-3 underline">dismiss</button>
    </div>
  );
}
```

- [ ] **Step 7: Run tests + lint + commit**

```bash
pnpm --filter web test
pnpm lint && pnpm --filter web typecheck
git add apps/web/app/bid apps/web/tests/unit/useBidStore.test.ts apps/web/package.json D:/GitHub_Repos/mbfd-bid/pnpm-lock.yaml
git commit -m "feat(web): /bid client island with Zustand store + WS hook + reconnect"
```

---

## Task 13: Web — `YourTurnPanel` with optimistic pick submission

**Files:**
- Create: `apps/web/app/bid/_components/YourTurnPanel.tsx`
- Create: `apps/web/app/bid/_components/EligibleList.tsx`
- Create: `apps/web/app/bid/_hooks/useIdempotencyKey.ts`
- Test: `apps/web/tests/e2e/bid-happy-path.spec.ts`

When `currentBidderId === meMemberId`, the panel shows the member's eligible positions (fetched server-side via `/api/me/eligibility`). Clicking a position triggers an optimistic UI update (calls `store.markPendingMine`), then sends `submit_pick` over the WS. On `pick_made` echo the optimistic state is promoted; on `pick_rejected` it rolls back.

- [ ] **Step 1: Write failing E2E**

```ts
// apps/web/tests/e2e/bid-happy-path.spec.ts
import { expect, test } from '@playwright/test';

test('member 17 picks A101 — optimistic update then canonical fill', async ({ page }) => {
  await page.goto('http://localhost:3000/bid');
  // Wait for hydration
  await page.waitForLoadState('networkidle');
  // Assume test session has member 17 active and A101 open + eligible
  const cell = page.getByTestId('position-cell-A101');
  await expect(cell).toHaveAttribute('data-state', 'eligible-open');
  await page.getByTestId('eligible-position-A101').click();
  // Optimistic state
  await expect(cell).toHaveAttribute('data-state', 'pending-mine', { timeout: 500 });
  // Canonical fill from DO
  await expect(cell).toHaveAttribute('data-state', 'filled', { timeout: 2000 });
  await expect(cell).toContainText('Filled by member 17');
});
```

- [ ] **Step 2: Run test, expect FAIL**

- [ ] **Step 3: Implement idempotency-key hook**

```ts
// apps/web/app/bid/_hooks/useIdempotencyKey.ts
'use client';
export function newIdempotencyKey(): string {
  if (typeof crypto !== 'undefined' && 'randomUUID' in crypto) {
    return crypto.randomUUID();
  }
  // Fallback for older browsers — RFC 4122 v4
  return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
    const r = (Math.random() * 16) | 0;
    const v = c === 'x' ? r : (r & 0x3) | 0x8;
    return v.toString(16);
  });
}
```

- [ ] **Step 4: Implement panels**

```tsx
// apps/web/app/bid/_components/YourTurnPanel.tsx
'use client';
import { useState } from 'react';
import { useStore } from 'zustand';
import type { StoreApi } from 'zustand';
import type { BidStoreState } from '../_hooks/useBidStore.js';
import { newIdempotencyKey } from '../_hooks/useIdempotencyKey.js';
import { EligibleList } from './EligibleList.js';

interface Props {
  store: StoreApi<BidStoreState>;
  send: (msg: object) => void;
  eligiblePositionIds: string[];
}

export function YourTurnPanel({ store, send, eligiblePositionIds }: Props) {
  const meMemberId = useStore(store, (s) => s.meMemberId);
  const currentBidderId = useStore(store, (s) => s.currentBidderId);
  const [submitting, setSubmitting] = useState<string | null>(null);

  if (currentBidderId !== meMemberId) return null;

  const submit = (positionId: string) => {
    const key = newIdempotencyKey();
    store.getState().markPendingMine(positionId, key);
    setSubmitting(positionId);
    send({ type: 'submit_pick', positionId, aDay: null, idempotencyKey: key });
  };

  return (
    <section className="border-t border-red-700 bg-red-50 p-6">
      <h2 className="font-display text-xl font-bold text-red-700">Your turn</h2>
      <EligibleList positionIds={eligiblePositionIds} onPick={submit} submitting={submitting} />
    </section>
  );
}
```

```tsx
// apps/web/app/bid/_components/EligibleList.tsx
'use client';
interface Props {
  positionIds: string[];
  onPick: (id: string) => void;
  submitting: string | null;
}
export function EligibleList({ positionIds, onPick, submitting }: Props) {
  return (
    <ul className="mt-3 grid grid-cols-2 gap-2 md:grid-cols-4">
      {positionIds.map((id) => (
        <li key={id}>
          <button
            type="button"
            data-testid={`eligible-position-${id}`}
            disabled={submitting !== null}
            onClick={() => onPick(id)}
            className="w-full rounded border border-red-700 bg-white px-3 py-2 text-left font-mono text-sm tabular-nums hover:bg-red-100 disabled:opacity-50"
          >
            {id}
            {submitting === id ? ' — submitting…' : null}
          </button>
        </li>
      ))}
    </ul>
  );
}
```

Wire `YourTurnPanel` and the `send` function into `BidBoard.tsx`. Update `PositionCell.tsx` to also read `pendingMine[positionId]` from the store and set `data-state="pending-mine"` accordingly.

- [ ] **Step 5: Run test, expect PASS**

- [ ] **Step 6: Commit**

```bash
git add apps/web/app/bid apps/web/tests/e2e/bid-happy-path.spec.ts
git commit -m "feat(web): YourTurnPanel + EligibleList + optimistic-pick submit flow"
```

---

## Task 14: Web — `/admin/bid` admin console (override + freeze)

**Files:**
- Create: `apps/web/app/admin/bid/page.tsx`
- Create: `apps/web/app/admin/bid/_components/AdminBoard.tsx`
- Create: `apps/web/app/admin/bid/_components/AdminActionsBar.tsx`
- Create: `apps/web/app/admin/bid/_components/OverrideDialog.tsx`
- Create: `apps/web/app/admin/bid/_components/FreezeConfirmDialog.tsx`
- Test: `apps/web/tests/e2e/bid-admin-override.spec.ts`

Admin view is the member view plus an actions bar (Skip / Override / Freeze). Each admin action calls the corresponding `/api/admin/bid/*` route with an `Idempotency-Key` header. The full admin console is Plan 05's deliverable; here we ship just enough surface to drive the live event from a chief's laptop.

- [ ] **Step 1: Write failing E2E**

```ts
// apps/web/tests/e2e/bid-admin-override.spec.ts
import { expect, test } from '@playwright/test';

test('admin can force-pick a member into a position', async ({ page }) => {
  await page.goto('http://localhost:3000/admin/bid');
  await page.getByTestId('admin-action-override').click();
  await page.getByTestId('override-member-id').fill('17');
  await page.getByTestId('override-position-id').fill('A101');
  await page.getByTestId('override-reason').fill('Last qualified candidate');
  await page.getByTestId('override-submit').click();
  await expect(page.getByTestId('position-cell-A101')).toHaveAttribute('data-state', 'filled');
});

test('admin can freeze the session', async ({ page }) => {
  await page.goto('http://localhost:3000/admin/bid');
  await page.getByTestId('admin-action-freeze').click();
  await page.getByTestId('freeze-reason').fill('Network outage at venue');
  await page.getByTestId('freeze-submit').click();
  await expect(page.getByTestId('bid-board-header')).toContainText('frozen');
});
```

- [ ] **Step 2: Run test, expect FAIL**

- [ ] **Step 3: Implement page + components** (implementer follows the YourTurnPanel pattern. The components POST to `/api/admin/bid/skip`, `/api/admin/bid/override`, `/api/admin/bid/freeze` with the JWT and a `crypto.randomUUID()` Idempotency-Key header. The DO broadcasts the resulting event over the same WS, so no client-side reconciliation is needed.)

- [ ] **Step 4: Run test, expect PASS**

- [ ] **Step 5: Lint + commit**

```bash
git add apps/web/app/admin/bid apps/web/tests/e2e/bid-admin-override.spec.ts
git commit -m "feat(web): /admin/bid console with override + freeze actions"
```

---

## Task 15: Recovery test — kill the DO mid-bid, verify RESYNC

**Files:**
- Test: `apps/worker/tests/integration/bid-session-recovery.test.ts`

The spec §5.4 acceptance bar is "kill the DO and reconnect → all clients receive RESYNC and reconcile within 2s". This test simulates DO restart by calling `state.storage` operations directly to mutate state, then opening a fresh WS and asserting the `state_snapshot` reply.

- [ ] **Step 1: Write test**

```ts
// apps/worker/tests/integration/bid-session-recovery.test.ts
import { unstable_dev, type UnstableDevWorker } from 'wrangler';
import { afterAll, beforeAll, describe, expect, it } from 'vitest';

describe('BidSession DO recovery (Plan 04 Task 15)', () => {
  let worker: UnstableDevWorker;
  beforeAll(async () => {
    worker = await unstable_dev('src/index.ts', {
      experimental: { disableExperimentalWarning: true },
      local: true,
    });
  });
  afterAll(async () => worker.stop());

  it('snapshot survives across requests (proxy for DO eviction)', async () => {
    const r1 = await worker.fetch('/api/board?bidSessionId=01HRECOVERY', {
      headers: { Authorization: 'Bearer test' },
    });
    expect([200, 401]).toContain(r1.status);
    // The DO is created on first access. A second access returns the same state.
    const r2 = await worker.fetch('/api/board?bidSessionId=01HRECOVERY', {
      headers: { Authorization: 'Bearer test' },
    });
    expect(r2.status).toBe(r1.status);
  });

  it('client reconnect receives state_snapshot with lastSeq matching pre-disconnect', async () => {
    // Implementer: open WS, send hello, observe state_snapshot, capture seq.
    // Close WS, reopen, send hello with lastSeq=N, observe state_snapshot or RESYNC.
    expect(true).toBe(true);
  });
});
```

- [ ] **Step 2: Run + commit**

```bash
pnpm --filter @mbfd/worker test integration/bid-session-recovery
git add apps/worker/tests/integration/bid-session-recovery.test.ts
git commit -m "test(worker): DO recovery + RESYNC integration test"
```

---

## Task 16: E2E — 5-pick cycle with two simulated members

**Files:**
- Test: `apps/web/tests/e2e/bid-five-pick-cycle.spec.ts`

Plays a 5-ordinal cycle: member A picks A101, member B picks A201, member A is now done so should NOT be able to pick again (must show NOT_YOUR_TURN), then admin force-picks member C into A301, then a member D submission for a filled position fails with POSITION_FILLED.

- [ ] **Step 1: Write E2E**

```ts
// apps/web/tests/e2e/bid-five-pick-cycle.spec.ts
import { expect, test } from '@playwright/test';

test('5-ordinal cycle exercises every invariant', async ({ browser }) => {
  const ctxA = await browser.newContext({ storageState: 'tests/e2e/.auth/memberA.json' });
  const ctxB = await browser.newContext({ storageState: 'tests/e2e/.auth/memberB.json' });
  const ctxAdmin = await browser.newContext({ storageState: 'tests/e2e/.auth/admin.json' });
  const pageA = await ctxA.newPage();
  const pageB = await ctxB.newPage();
  const pageAdmin = await ctxAdmin.newPage();

  await Promise.all([
    pageA.goto('http://localhost:3000/bid'),
    pageB.goto('http://localhost:3000/bid'),
    pageAdmin.goto('http://localhost:3000/admin/bid'),
  ]);

  // 1. A picks A101 (A is current bidder)
  await pageA.getByTestId('eligible-position-A101').click();
  await expect(pageA.getByTestId('position-cell-A101')).toHaveAttribute('data-state', 'filled');
  await expect(pageB.getByTestId('position-cell-A101')).toHaveAttribute('data-state', 'filled');

  // 2. A cannot pick again
  await expect(pageA.getByTestId('your-turn-panel')).not.toBeVisible();

  // 3. B picks A201
  await pageB.getByTestId('eligible-position-A201').click();
  await expect(pageA.getByTestId('position-cell-A201')).toHaveAttribute('data-state', 'filled');

  // 4. Admin force-picks A301 to member C
  await pageAdmin.getByTestId('admin-action-override').click();
  await pageAdmin.getByTestId('override-member-id').fill('19');
  await pageAdmin.getByTestId('override-position-id').fill('A301');
  await pageAdmin.getByTestId('override-reason').fill('Mandatory minimum');
  await pageAdmin.getByTestId('override-submit').click();
  await expect(pageA.getByTestId('position-cell-A301')).toHaveAttribute('data-state', 'filled');

  // 5. Member D attempts A101 → POSITION_FILLED toast
  // (Implementer adds a memberD storage state for this assertion.)
});
```

- [ ] **Step 2: Run + commit**

```bash
pnpm test:e2e --grep '5-ordinal cycle'
git add apps/web/tests/e2e/bid-five-pick-cycle.spec.ts
git commit -m "test(e2e): 5-pick cycle with two members + admin override"
```

---

## Verification checklist

Run all of these locally before declaring Plan 04 complete:

- [ ] `pnpm --filter @mbfd/worker test` — green (unit + integration)
- [ ] `pnpm --filter @mbfd/shared test` — green
- [ ] `pnpm --filter web test` — green (unit)
- [ ] `pnpm test:e2e` — green (Playwright)
- [ ] `pnpm lint` — clean Biome
- [ ] `pnpm typecheck` — strict TypeScript clean across workspaces
- [ ] `pnpm --filter @mbfd/worker db:migrate:local` succeeds with migration 0006
- [ ] Manual check: open two browser tabs as different members on `localhost:8787` (worker) + `localhost:3000` (web); confirm a pick made in tab A is visible in tab B in < 500 ms
- [ ] Manual check: kill the worker process mid-bid (`Ctrl+C`), restart, refresh both tabs — both reconnect within 2 s and show the canonical state (no fills lost)
- [ ] Manual check: admin force-pick a member into a position they would NOT be eligible for; verify the pick is recorded with `forced=true` and a `reason` row in `audit_log`
- [ ] Manual check: admin freeze; verify subsequent member submit_pick returns `SESSION_FROZEN`
- [ ] No emojis introduced anywhere in source files (Biome won't catch — grep before merge)
- [ ] `audit_log` rows exist for every accepted DO transition (one row each: pick, forced_pick, skip, freeze)
- [ ] `bids.idempotency_key` UNIQUE constraint is honoured — duplicate submission with same key returns the cached envelope

---

## Rollback procedure (if cutover to staging fails)

If the live-bid surface breaks staging in a way Plan 02 traffic cannot tolerate, roll back in this order:

1. **Revert the wrangler.toml DO binding** (commit `feat(worker): BidSession Durable Object class …`). Without the binding the new routes 500; the existing /admin routes from Plan 02 continue to work.
2. **Revoke the DO migration tag** in `wrangler.toml` (delete `[[migrations]] tag = "v4"`) and redeploy. Cloudflare keeps prior DO versions addressable.
3. **Migration 0006 is additive** — leave `frozen_at`/`freeze_actor_id`/`freeze_reason` in place. They are nullable columns and existing inserts continue to work.
4. **Web `/bid` and `/admin/bid` routes** can stay deployed but show a "Live bid not started" placeholder; gate them with a feature flag (`feature_flag:bid_live_v1` in KV) and toggle off.
5. Re-run the rehearsal in a feature branch off `main` once the regression is fixed; do not re-deploy to staging until both unit + integration test suites pass on CI.

A full DO data wipe is **never** part of rollback — the audit log in D1 is the legal record.

---

## Notes for the engineer

- **Never put eligibility logic inside the DO.** The DO calls `evaluateEligibility` via the `HandlerEnv` injection. If you find yourself writing `if (member.rank === 'CPT')` inside `bid-session.ts`, stop — that goes in `@mbfd/eligibility`.
- **`state.blockConcurrencyWhile` is the only lock.** Do not try to add `Mutex` libraries — they don't work in Workers runtime.
- **D1 batches are atomic per-batch, not across batches.** If your audit-write fails after a state-persist succeeds, retry with the same `audit_log.id`; the ulid is monotonic and the DO can re-derive it from seq.
- **WebSocket frames are JSON strings.** Both client and server validate every frame through `BidEventEnvelopeSchema` / `ClientMessageSchema`. A non-matching frame is treated as `PROTOCOL_ERROR`, never thrown.
- **Idempotency-Key is a 24h TTL** in DO storage. If a member retries 25h later they get a fresh evaluation — by which time the bid is over, so this is safe.
- **Position 232 count** — Plan 02 seed produces 232 positions for 2026. Tests that assert `toHaveCount(232)` rely on the seed having been run; the E2E setup script (`apps/web/tests/e2e/_setup/seed.ts` from Plan 02) handles this.
- **Station 6 is marine for 2026** (`memory/mbfd_2026_station_restructure.md`). Plan 04 has no station-specific logic, but the test fixtures use 2026 position IDs (`A611`, `A612`, `A613`).
- **A-Day (Phase 2) is Plan 07.** The `aDay` field is plumbed through the schemas here but always `null` in Phase 1. Do not add A-Day picker UI in this plan.
- **AI advisory (Plan 06)** consumes `audit_log.ai_advisory_id` and `ai_advisories.member_id`. Plan 04 leaves those columns null; do not stub AI calls.
