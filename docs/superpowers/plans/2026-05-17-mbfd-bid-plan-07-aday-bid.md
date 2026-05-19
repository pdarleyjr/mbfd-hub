# Plan 07 — A-Day bid — Phase 2 sequential

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** After Phase 1 (position bid, Plan 04) completes, the bid transitions to Phase 2: each member, in their Phase-2 turn, picks an A-Day. A/B/C-shift members pick one of four Combat Groups (G1–G4); D-shift members pick a weekday (MON–SUN). Group capacity invariants are enforced atomically inside the DO: exactly 5 officers per group on A/B/C, 18–19 total members per group. After all A-Day picks are recorded, the DO transitions to `complete`.

**Architecture:** Adds two phase values to the Plan 04 DO state machine — `a_day_bid` and `a_day_complete` — without forking the DO class. Reuses the Plan 04 WS protocol with three new versioned message types. A new pure package `@mbfd/a-day` owns the capacity engine and the officer invariant; the DO orchestrates and the package decides, mirroring the eligibility-engine boundary from Plan 03. A new D1 table `a_day_picks` records every pick with idempotency, forced flag, and admin actor. Phase 2 bid order is computed deterministically when the DO transitions and is independent of Phase 1 order (admin-configurable: by shift then seniority, or reuse Phase 1 order).

**Tech stack additions:** New monorepo package `@mbfd/a-day`. Reuses Plan 04 stack (Hono Worker, Durable Object, WS, Zustand, TanStack Query). No new external dependencies.

**Cross-references:**
- **REQUIRED** Plans 01 (foundation), 02 (data plane), 03 (eligibility engine), 04 (live bid core, DO + WS).
- **OPTIONAL** Plans 05 (admin console — supplies the force-override UI shell), 06 (AI advisory — receives the new `ADayInvariantSnapshot` shape for advisory context).
- **CONSUMED BY** Plans 08 (audit/exports/portal write-back — exports `a_day_picks` rows alongside Phase 1 `bids`), 09 (hardening — adds Phase 1 → Phase 2 transition to the "kill the DO" drill).

---

## Decisions preamble

These are decisions locked in by this plan, recorded so they do not get re-litigated during implementation:

1. **Package boundary.** A-Day is a separate package `@mbfd/a-day`, NOT part of `@mbfd/eligibility`. Reason: different domain (per-group capacity arithmetic and an officer-count invariant), different inputs (Phase-1 results + member rank), different consumers (the DO Phase-2 handler only), and a different test discipline (state-machine + integer-arithmetic tests, no rule-book replay). Sharing a package with eligibility would force both to grow toward each other and dilute the eligibility engine's "given a (member, position, rule), is it valid?" focus.

2. **Phase-2 bid order policy.** Default: same order as Phase 1 (reuses Phase 1's `bid_order` rows by ordinal). Admin can override via `bid_sessions.config_json.a_day_bid_order = 'by_shift_then_seniority' | 'phase_1_order'`. `by_shift_then_seniority` groups members by shift (A, B, C, D in that fixed order) then sorts each shift by `rsc_seniority` ascending. Both modes are pure functions of the bid_session and Phase 1 picks — no admin-data-entry phase.

3. **D-shift capacity model.** No hard cap by default. Each D-shift weekday is advisory-only (no count constraint). Admin can opt in by setting `bid_sessions.config_json.d_shift_weekday_caps = { "MON": 2, "WED": 2, "FRI": 5, ... }`. Missing keys mean "no cap". The capacity engine and invariant checker apply caps uniformly — D-shift simply provides empty/missing caps by default.

4. **Vacant Phase-1 positions.** Phase 1 may complete with vacancies (A215, B215, C215 by design per the 2025 forensic; admin can also intentionally leave others vacant via `lock-position`/`skip`). Vacancies do NOT prevent the Phase 2 transition. Vacant slots roll over to next bid year per Plan 08's archival rules; they do not consume an A-Day group seat.

5. **Group capacity defaults for A/B/C.** Each group: **`min=18`, `max=19`, `officersRequired=5`**. These values live in `bid_sessions.config_json.a_day_group_capacities` so they can be tuned per year without code change. The invariant checker treats `officersRequired` as exact-match (not min/max) — exactly 5, no more, no fewer.

6. **Union President / Excluded members.** Members with `bid_category = 'EXCLUDED'` who hold Phase-1 positions (e.g., A701 Union President) are assigned an A-Day group administratively in Phase 0 (pre-bid), not via Phase 2. Their A-Day pick is pre-seeded into `a_day_picks` with `forced=true` and `admin_actor_id` set when the bid_session is created, NOT during Phase 2. Phase 2 does not give them a turn.

7. **No Group 4 special case.** Per `2026_Bid_Process.md §5` and the 2025 forensic, all four combat groups follow the same officer (5) and capacity (18–19) rules. There is no "Group 4 holds Union President without count" special case for Phase 2; that exception is fully resolved by decision 6 (UP is pre-seeded out-of-band).

8. **Officer rank set.** "Officer" for the 5/group invariant = any rank in `{ DC, CPT, LT }`. Members with rank `DEP_CHIEF` or `CHIEF` are excluded from the bid entirely, and `FF` is not an officer. This matches `2026_Bid_Process.md §1` Officer Pool definition.

9. **Atomic pick semantics.** A Phase-2 pick is a single DO operation: validate → update `a_day_picks_map` in DO memory → `state.storage.put` → D1 INSERT → broadcast. Same ordering as Plan 04 Phase 1 (persist before broadcast). Idempotency keys are scoped per session per member — a member picks A-Day exactly once, so the idempotency key gate prevents accidental double-clicks but does not need a TTL.

10. **No undo after lock.** Phase 2 picks use the same 5-second client-side undo toast as Phase 1 (the optimistic UI delay). After the toast expires, the pick is committed server-side and cannot be unwound by the member. Admin-only override path remains available (Task 13).

11. **Admin override is the only way to break the officer invariant.** A non-admin pick that would result in `officers != 5` on any group is rejected by the DO with a structured `REJECT` message. Admin force-override (Task 13) is a separate code path that bypasses the invariant check, logs the actor, and requires a reason — same pattern as Plan 05's `force-pick`.

12. **Phase complete trigger.** Phase 2 is complete when every Phase-1 bid (i.e., every row in `bids` for the session) has a matching row in `a_day_picks`. Vacant Phase-1 positions contribute neither row. When `a_day_picks.count == bids.count` for the session, the DO emits `phase_changed { from: 'a_day_bid', to: 'complete' }` and persists `current_phase = 'complete'`. No separate `a_day_complete` intermediate state — it collapses to `complete`.

> **Correction to Plan-07 master-index sketch:** The original sketch listed `a_day_complete` as a distinct phase. Decision 12 supersedes that — there is only `a_day_bid` → `complete`. The master index will be updated as part of Task 16's verification.

---

## Architecture sketch

```
  Plan 04 BidSession DO  (extended, not forked)
  =====================
  current_phase: 'config' | 'position_bid' | 'a_day_bid' | 'paused' | 'complete'
                                  ▲                                 ▲
                                  └── transition on Phase 1 done ───┘

  Phase 1 last-pick handler (existing, from Plan 04)
        │
        │ on success, check phase_complete predicate
        ▼
  ┌────────────────────────────────────────────────────────────────┐
  │ transitionToPhase2(state)                                       │
  │   1. compute bidOrderPhase2(state, config)                      │
  │   2. a_day_state = initADayState(phase1_picks, group_caps, …)   │
  │   3. state.storage.put({ current_phase: 'a_day_bid', … })       │
  │   4. broadcast { type: 'phase_changed', from: 'position_bid',   │
  │                  to: 'a_day_bid', bidOrderPhase2 }              │
  │   5. broadcast { type: 'turn_started', member_id: order[0] }    │
  └────────────────────────────────────────────────────────────────┘
        │
        ▼
  ┌────────────────────────────────────────────────────────────────┐
  │ submitADayPick (new WS message + REST POST)                     │
  │   1. validate JWT.member_id === current_bidder_id               │
  │   2. validate phase === 'a_day_bid'                             │
  │   3. validate idempotency key (per-session per-member)          │
  │   4. lookup member's shift from phase 1 pick                    │
  │   5. call @mbfd/a-day.canPick({ member, aDay, state }) → result │
  │   6. if !result.ok → broadcast REJECT(member, reason)           │
  │   7. else: applyPick(); state.storage.put; D1 INSERT;           │
  │           broadcast pick_made_a_day                             │
  │   8. advance queue cursor; if done → transitionToComplete()     │
  └────────────────────────────────────────────────────────────────┘

  @mbfd/a-day  (pure, no I/O, no Date.now)
  ============
  src/types.ts                          types & enums
  src/groups.ts                         A/B/C group constants + D-shift weekdays
  src/capacity.ts                       capacity arithmetic (count/decrement/full)
  src/officer-invariant.ts              "exactly 5 officers / group" checker
  src/can-pick.ts                       composes capacity + invariant + shift rules
  src/order.ts                          Phase-2 bid order generator (two modes)
  src/state.ts                          initADayState + applyPick (pure)
  src/index.ts                          public barrel

  Audit (Plan 08 consumes)
  ========================
  audit_log row for every a_day_pick (action='a_day_pick' | 'forced_a_day_pick')
```

---

## File map

```
packages/a-day/
  package.json
  tsconfig.json
  vitest.config.ts
  src/
    index.ts                    ← public API barrel
    types.ts                    ← ADayGroupId, Weekday, ADayPick, ADayState,
                                  GroupCapacityConfig, CapacityMeter,
                                  PickValidation, …
    groups.ts                   ← const arrays/maps for groups & weekdays
    capacity.ts                 ← computeCapacityMeter, isGroupFull
    officer-invariant.ts        ← validateOfficerInvariant, projectedOfficers
    can-pick.ts                 ← canPick(aDayState, member, aDay) → PickValidation
    state.ts                    ← initADayState, applyPick (pure transforms)
    order.ts                    ← phase2BidOrder (two strategies)
  tests/
    unit/
      groups.test.ts
      capacity.test.ts
      officer-invariant.test.ts
      can-pick.test.ts
      state.test.ts
      order.test.ts

apps/worker/src/durable/
  bid-session.ts                (UPDATE: add a_day_bid phase + transitions;
                                  inject @mbfd/a-day; new handler submitADayPick)
  bid-session-protocol.ts       (UPDATE: 3 new versioned WS messages)
  bid-session-storage.ts        (UPDATE: persist & rehydrate a_day_state)
apps/worker/src/routes/
  bid/
    a-day-pick.ts               ← POST /api/bid/a-day-pick (REST fallback for non-WS clients)
    a-day-state.ts              ← GET /api/bid/a-day-state (SSR + reconnect snapshot)
  admin/
    force-a-day.ts              ← POST /api/admin/bid-session/:id/force-a-day
                                   (step-up auth, audit log, AI dissent log)
apps/worker/migrations/
  0006_a_day_picks.sql          ← new table + indexes; backfill enum on bid_sessions.current_phase
apps/worker/src/db/
  schema.ts                     (UPDATE: add a_day_picks Drizzle table)

apps/web/app/me/
  page.tsx                      (UPDATE: render <ADayPicker /> when phase === 'a_day_bid' && my turn)
  loading.tsx                   (UPDATE: skeleton for picker)
apps/web/app/draft/_components/
  ADayPicker.tsx                ← 4 group cards or 7 weekday cards
  ADayConfirmDialog.tsx         ← 5-second undo toast (re-uses Plan 04 pattern)
  ADayCapacityMeter.tsx         ← reusable bar component (used inside cards)
  ADayInvariantBadge.tsx        ← "5 OFC ✓" badge with tooltip
apps/web/lib/
  a-day-client.ts               ← typed WS message helpers
apps/web/app/admin/bid/_components/
  AdminADayForceDialog.tsx      ← chief override UI

packages/shared/src/schemas/
  a-day.ts                      ← Zod schemas for ADayGroupId, Weekday, WS msgs

tests/
  apps/worker/tests/integration/
    a-day-transition.test.ts            ← Phase 1 complete → Phase 2 starts
    a-day-capacity.test.ts              ← capacity decrements + REJECT on full
    a-day-officer-invariant.test.ts     ← REJECT when next pick breaks 5/group
    a-day-idempotency.test.ts           ← duplicate pick returns same result
    a-day-admin-force.test.ts           ← admin override bypasses invariant
    a-day-d-shift.test.ts               ← weekday picker + no-cap default
    a-day-vacant-positions.test.ts      ← Phase 1 vacancies do not block Phase 2
    a-day-complete.test.ts              ← last pick → phase=complete
  apps/web/tests/e2e/
    a-day-pick-happy-path.spec.ts       ← single member completes Phase 2
    a-day-full-bid-simulation.spec.ts   ← 12 simulated members run Phase 1 + 2
```

---

## Source data reference

| File | Use |
|------|-----|
| `D:/MBFD/Bid/2026 Bid Documents/2026_Bid_Process.md` §5 | A-Day mechanics (4 groups, 5 OFC/group, 18–19/group, D-shift weekday) |
| `D:/GitHub_Repos/MBFD_Hub/analysis/2025_MBFD_Bid_Forensic_Analysis.md` Parts V & IX | Empirical 2025 totals (G1=19, G2=18, G3=19, G4=18 per shift; D-shift Fri 5 / Wed 2 / Mon 1) |
| `D:/GitHub_Repos/MBFD_Hub/docs/superpowers/specs/2026-05-17-mbfd-bid-webapp-design.md` §9.6 | UI mockup for Phase 2 picker |
| `D:/GitHub_Repos/MBFD_Hub/docs/superpowers/specs/2026-05-17-mbfd-bid-webapp-design.md` §5.2 | DO state shape including `current_phase` enum |
| `D:/GitHub_Repos/MBFD_Hub/docs/superpowers/specs/2026-05-17-mbfd-bid-webapp-design.md` §6.1 | `bids.a_day` column shape — `"G1".."G4"` or `"MON".."SUN"` |

---

## Task 1: `@mbfd/a-day` package scaffold

**Files:**
- Create: `packages/a-day/package.json`
- Create: `packages/a-day/tsconfig.json`
- Create: `packages/a-day/vitest.config.ts`
- Create: `packages/a-day/src/index.ts` (empty barrel)
- Modify: root `pnpm-workspace.yaml` (verify `packages/*` already covered — it should be after Plan 03 Task 1)

- [ ] **Step 1: Create `packages/a-day/package.json`**

```json
{
  "name": "@mbfd/a-day",
  "version": "0.1.0",
  "private": true,
  "type": "module",
  "main": "./dist/index.js",
  "types": "./dist/index.d.ts",
  "exports": {
    ".": {
      "types": "./dist/index.d.ts",
      "default": "./dist/index.js"
    }
  },
  "scripts": {
    "build": "tsc -b",
    "dev": "tsc -b --watch",
    "test": "vitest run",
    "test:watch": "vitest",
    "test:coverage": "vitest run --coverage",
    "typecheck": "tsc -b --noEmit"
  },
  "dependencies": {
    "@mbfd/eligibility": "workspace:*"
  },
  "devDependencies": {
    "@vitest/coverage-v8": "2.1.4",
    "typescript": "5.6.3",
    "vitest": "2.1.4"
  }
}
```

> The dependency on `@mbfd/eligibility` is for the shared `Member` and `Rank` types only. Re-importing them keeps the type graph consistent and avoids drift.

- [ ] **Step 2: Create `packages/a-day/tsconfig.json`**

```json
{
  "compilerOptions": {
    "target": "ES2022",
    "module": "NodeNext",
    "moduleResolution": "NodeNext",
    "strict": true,
    "noUncheckedIndexedAccess": true,
    "exactOptionalPropertyTypes": true,
    "outDir": "./dist",
    "rootDir": "./src",
    "declaration": true,
    "declarationMap": true,
    "sourceMap": true,
    "composite": true
  },
  "include": ["src/**/*.ts"],
  "exclude": ["node_modules", "dist", "tests"],
  "references": [{ "path": "../eligibility" }]
}
```

- [ ] **Step 3: Create `packages/a-day/vitest.config.ts`**

```ts
import { defineConfig } from 'vitest/config';

export default defineConfig({
  test: {
    include: ['tests/**/*.test.ts'],
    coverage: {
      provider: 'v8',
      include: ['src/**/*.ts'],
      exclude: ['src/index.ts'],
      thresholds: {
        lines: 100,
        branches: 100,
        functions: 100,
        statements: 100,
      },
      reporter: ['text', 'lcov'],
    },
  },
});
```

- [ ] **Step 4: Create empty barrel `packages/a-day/src/index.ts`**

```ts
// Public API — populated in later tasks.
export {};
```

- [ ] **Step 5: Install and verify**

```bash
pnpm install
pnpm --filter @mbfd/a-day build
```

Expected: `dist/index.js` emitted with no errors.

- [ ] **Step 6: Commit**

```bash
git add packages/a-day/package.json packages/a-day/tsconfig.json packages/a-day/vitest.config.ts packages/a-day/src/index.ts pnpm-lock.yaml
git commit -m "feat(a-day): scaffold @mbfd/a-day package"
```

---

## Task 2: Types

**Files:**
- Create: `packages/a-day/src/types.ts`
- Modify: `packages/a-day/src/index.ts`
- Test: `packages/a-day/tests/unit/types.test.ts`

- [ ] **Step 1: Write the failing test**

```ts
// packages/a-day/tests/unit/types.test.ts
import { describe, it, expectTypeOf } from 'vitest';
import type {
  ADayGroupId,
  Weekday,
  ADayValue,
  Shift,
  GroupCapacityConfig,
  WeekdayCapacityConfig,
  CapacityMeter,
  OfficerInvariantSnapshot,
  ADayPick,
  ADayState,
  PickValidation,
  Phase2BidOrderStrategy,
} from '../../src/types.js';

describe('a-day types (structural)', () => {
  it('ADayGroupId is one of four literals', () => {
    expectTypeOf<ADayGroupId>().toEqualTypeOf<'G1' | 'G2' | 'G3' | 'G4'>();
  });

  it('Weekday union covers all 7 days in ISO order', () => {
    expectTypeOf<Weekday>().toEqualTypeOf<
      'MON' | 'TUE' | 'WED' | 'THU' | 'FRI' | 'SAT' | 'SUN'
    >();
  });

  it('ADayValue is the union of GroupId and Weekday', () => {
    expectTypeOf<ADayValue>().toEqualTypeOf<ADayGroupId | Weekday>();
  });

  it('Shift includes A, B, C, D', () => {
    expectTypeOf<Shift>().toEqualTypeOf<'A' | 'B' | 'C' | 'D'>();
  });

  it('GroupCapacityConfig has min, max, officersRequired', () => {
    expectTypeOf<GroupCapacityConfig>().toHaveProperty('min');
    expectTypeOf<GroupCapacityConfig>().toHaveProperty('max');
    expectTypeOf<GroupCapacityConfig>().toHaveProperty('officersRequired');
  });

  it('CapacityMeter has total, max, officers, officersRequired, isFull', () => {
    expectTypeOf<CapacityMeter>().toHaveProperty('total');
    expectTypeOf<CapacityMeter>().toHaveProperty('max');
    expectTypeOf<CapacityMeter>().toHaveProperty('officers');
    expectTypeOf<CapacityMeter>().toHaveProperty('officersRequired');
    expectTypeOf<CapacityMeter>().toHaveProperty('isFull');
  });

  it('ADayPick has memberId, shift, aDay, pickedAtMs, forced, adminActorId', () => {
    expectTypeOf<ADayPick>().toHaveProperty('memberId');
    expectTypeOf<ADayPick>().toHaveProperty('shift');
    expectTypeOf<ADayPick>().toHaveProperty('aDay');
    expectTypeOf<ADayPick>().toHaveProperty('pickedAtMs');
    expectTypeOf<ADayPick>().toHaveProperty('forced');
    expectTypeOf<ADayPick>().toHaveProperty('adminActorId');
  });

  it('PickValidation discriminated union has ok or rejected variants', () => {
    type OkVariant = Extract<PickValidation, { ok: true }>;
    type RejectVariant = Extract<PickValidation, { ok: false }>;
    expectTypeOf<OkVariant>().toHaveProperty('ok');
    expectTypeOf<RejectVariant>().toHaveProperty('reasonCode');
    expectTypeOf<RejectVariant>().toHaveProperty('reasonLabel');
  });

  it('Phase2BidOrderStrategy is one of two literals', () => {
    expectTypeOf<Phase2BidOrderStrategy>().toEqualTypeOf<
      'phase_1_order' | 'by_shift_then_seniority'
    >();
  });
});
```

- [ ] **Step 2: Run test, expect FAIL** (`pnpm --filter @mbfd/a-day test` → cannot resolve `types.js`).

- [ ] **Step 3: Implement `packages/a-day/src/types.ts`**

```ts
// packages/a-day/src/types.ts
import type { Member, Rank } from '@mbfd/eligibility';

/** Combat group identifiers, used by A/B/C shifts. */
export type ADayGroupId = 'G1' | 'G2' | 'G3' | 'G4';

/** Day-of-week identifiers, used by D-shift. */
export type Weekday = 'MON' | 'TUE' | 'WED' | 'THU' | 'FRI' | 'SAT' | 'SUN';

/** A single A-Day value: either a combat group (A/B/C) or a weekday (D). */
export type ADayValue = ADayGroupId | Weekday;

/** The four shifts in the bid. */
export type Shift = 'A' | 'B' | 'C' | 'D';

/** Capacity rules for one of the four combat groups on a single shift. */
export interface GroupCapacityConfig {
  /** Minimum members in the group at Phase 2 completion (default 18). */
  min: number;
  /** Maximum members in the group at any time (default 19). */
  max: number;
  /**
   * Exact officer count required at Phase 2 completion (default 5).
   * Enforced as exact-match, not a range.
   */
  officersRequired: number;
}

/** Capacity rules for one weekday on D-shift. Missing key = no cap. */
export interface WeekdayCapacityConfig {
  /** Maximum members on this weekday. Undefined means no cap. */
  max: number | undefined;
}

/** Live count of an A-Day slot. */
export interface CapacityMeter {
  /** Total members currently assigned to this A-Day. */
  total: number;
  /** Max permitted; undefined means uncapped (D-shift default). */
  max: number | undefined;
  /** Officers (DC/CPT/LT) currently assigned. */
  officers: number;
  /**
   * Exact officers required at completion.
   * Undefined for D-shift (no per-weekday officer rule).
   */
  officersRequired: number | undefined;
  /** True if total === max (i.e., cannot accept another pick). */
  isFull: boolean;
}

/**
 * Computed snapshot of how the officer invariant looks for a given (shift, group)
 * AFTER a hypothetical pick is applied. Used by canPick() to report dry-run results.
 */
export interface OfficerInvariantSnapshot {
  shift: Shift;
  group: ADayGroupId;
  /** Officers currently assigned to this group. */
  currentOfficers: number;
  /** Officers IF the candidate pick is applied. */
  projectedOfficers: number;
  /** Exact required (default 5). */
  required: number;
  /**
   * True if, after applying the pick AND considering the remaining bid order
   * (members yet to pick on this shift), the invariant CAN still be satisfied.
   */
  feasible: boolean;
  /** Human-readable explanation for the picker UI / admin log. */
  explanation: string;
}

/** One Phase-2 A-Day assignment. */
export interface ADayPick {
  /** Member primary key. */
  memberId: number;
  /** Member's Phase 1 shift (read from the Phase 1 pick). */
  shift: Shift;
  /** Selected A-Day value. */
  aDay: ADayValue;
  /** Server-supplied timestamp at pick time (ms since epoch). */
  pickedAtMs: number;
  /** True only when an admin used the force-a-day endpoint. */
  forced: boolean;
  /** Member id of the admin who forced this pick. Null for normal picks. */
  adminActorId: number | null;
}

/**
 * The pure-data Phase-2 state held by the DO. Constructed once at transition
 * via initADayState(); evolved by applyPick().
 */
export interface ADayState {
  /** Group capacities keyed by shift then group. */
  groupCaps: Readonly<Record<Exclude<Shift, 'D'>, Readonly<Record<ADayGroupId, GroupCapacityConfig>>>>;
  /** D-shift weekday caps; missing keys mean no cap. */
  weekdayCaps: Readonly<Partial<Record<Weekday, WeekdayCapacityConfig>>>;
  /**
   * All picks so far, keyed by member id for O(1) idempotency check.
   * memberId to ADayPick.
   */
  picksByMember: ReadonlyMap<number, ADayPick>;
  /** Phase-2 ordered list of member ids; cursor advances on each pick. */
  bidOrder: readonly number[];
  /** Index into bidOrder of the next member to pick. */
  cursor: number;
  /**
   * Phase 1 picks keyed by member id, used to look up shift and detect
   * vacant positions. Read-only snapshot.
   */
  phase1ByMember: ReadonlyMap<number, { positionId: string; shift: Shift }>;
  /**
   * Member roster keyed by id, used by the invariant checker to look up rank.
   */
  membersById: ReadonlyMap<number, Member>;
}

/**
 * Discriminated union returned by canPick(). The DO uses the `ok` flag to
 * decide whether to apply the pick or broadcast a REJECT.
 */
export type PickValidation =
  | {
      ok: true;
      /** Capacity meter for the picked A-Day AFTER the pick. */
      projectedMeter: CapacityMeter;
      /** Officer-invariant snapshot (omitted for D-shift). */
      officerSnapshot?: OfficerInvariantSnapshot;
    }
  | {
      ok: false;
      /** Machine-stable rejection code. */
      reasonCode:
        | 'NOT_YOUR_TURN'
        | 'PHASE_NOT_R_DAY_BID'
        | 'NO_PHASE_1_PICK'
        | 'ALREADY_PICKED'
        | 'GROUP_FULL'
        | 'WEEKDAY_FULL'
        | 'OFFICER_INVARIANT_VIOLATED'
        | 'INVALID_R_DAY_FOR_SHIFT'
        | 'UNKNOWN_MEMBER';
      /** Human-readable label for toast/audit. */
      reasonLabel: string;
      /** Optional structured detail (e.g., projected officers count). */
      detail?: Readonly<Record<string, string | number | boolean>>;
    };

/** Strategy for computing Phase 2 bid order. */
export type Phase2BidOrderStrategy = 'phase_1_order' | 'by_shift_then_seniority';

/** Re-export the eligibility Rank for convenience. */
export type { Rank };
```

- [ ] **Step 4: Update `packages/a-day/src/index.ts`**

```ts
// packages/a-day/src/index.ts
export type {
  ADayGroupId,
  Weekday,
  ADayValue,
  Shift,
  GroupCapacityConfig,
  WeekdayCapacityConfig,
  CapacityMeter,
  OfficerInvariantSnapshot,
  ADayPick,
  ADayState,
  PickValidation,
  Phase2BidOrderStrategy,
  Rank,
} from './types.js';
```

- [ ] **Step 5: Run test, expect PASS**

```bash
pnpm --filter @mbfd/a-day test
```

- [ ] **Step 6: Commit**

```bash
git add packages/a-day/src/types.ts packages/a-day/src/index.ts packages/a-day/tests/unit/types.test.ts
git commit -m "feat(a-day): define ADayState, PickValidation, CapacityMeter types"
```

---

## Task 3: Groups & weekdays constants

**Files:**
- Create: `packages/a-day/src/groups.ts`
- Test: `packages/a-day/tests/unit/groups.test.ts`

The `groups.ts` module provides the canonical ordered lists of group ids and weekdays plus a helper to map a Rank to "is officer".

- [ ] **Step 1: Write failing test**

```ts
// packages/a-day/tests/unit/groups.test.ts
import { describe, it, expect } from 'vitest';
import {
  COMBAT_GROUPS,
  WEEKDAYS,
  OFFICER_RANKS,
  isOfficer,
  isCombatGroup,
  isWeekday,
  isValidADayForShift,
  DEFAULT_GROUP_CAPACITY,
} from '../../src/groups.js';

describe('COMBAT_GROUPS', () => {
  it('is exactly G1, G2, G3, G4 in order', () => {
    expect(COMBAT_GROUPS).toEqual(['G1', 'G2', 'G3', 'G4']);
  });
});

describe('WEEKDAYS', () => {
  it('is exactly MON..SUN in ISO order', () => {
    expect(WEEKDAYS).toEqual(['MON', 'TUE', 'WED', 'THU', 'FRI', 'SAT', 'SUN']);
  });
});

describe('OFFICER_RANKS', () => {
  it('contains DC, CPT, LT only', () => {
    expect(new Set(OFFICER_RANKS)).toEqual(new Set(['DC', 'CPT', 'LT']));
    expect(OFFICER_RANKS).toHaveLength(3);
  });
});

describe('isOfficer', () => {
  it('true for DC, CPT, LT', () => {
    expect(isOfficer('DC')).toBe(true);
    expect(isOfficer('CPT')).toBe(true);
    expect(isOfficer('LT')).toBe(true);
  });

  it('false for FF', () => {
    expect(isOfficer('FF')).toBe(false);
  });

  it('false for excluded ranks DEP_CHIEF, CHIEF (they do not bid)', () => {
    expect(isOfficer('DEP_CHIEF')).toBe(false);
    expect(isOfficer('CHIEF')).toBe(false);
  });
});

describe('isCombatGroup', () => {
  it('true for G1-G4', () => {
    for (const g of ['G1', 'G2', 'G3', 'G4']) {
      expect(isCombatGroup(g)).toBe(true);
    }
  });

  it('false for weekdays and unknown', () => {
    expect(isCombatGroup('MON')).toBe(false);
    expect(isCombatGroup('G5')).toBe(false);
    expect(isCombatGroup('')).toBe(false);
  });
});

describe('isWeekday', () => {
  it('true for MON..SUN', () => {
    for (const d of ['MON', 'TUE', 'WED', 'THU', 'FRI', 'SAT', 'SUN']) {
      expect(isWeekday(d)).toBe(true);
    }
  });

  it('false for groups and unknown', () => {
    expect(isWeekday('G1')).toBe(false);
    expect(isWeekday('FUN')).toBe(false);
  });
});

describe('isValidADayForShift', () => {
  it('A/B/C shift accepts only group ids', () => {
    expect(isValidADayForShift('A', 'G1')).toBe(true);
    expect(isValidADayForShift('B', 'G3')).toBe(true);
    expect(isValidADayForShift('C', 'G4')).toBe(true);
    expect(isValidADayForShift('A', 'MON')).toBe(false);
  });

  it('D shift accepts only weekdays', () => {
    expect(isValidADayForShift('D', 'MON')).toBe(true);
    expect(isValidADayForShift('D', 'FRI')).toBe(true);
    expect(isValidADayForShift('D', 'G1')).toBe(false);
  });
});

describe('DEFAULT_GROUP_CAPACITY', () => {
  it('has min=18, max=19, officersRequired=5', () => {
    expect(DEFAULT_GROUP_CAPACITY).toEqual({
      min: 18,
      max: 19,
      officersRequired: 5,
    });
  });
});
```

- [ ] **Step 2: Run test, expect FAIL**

- [ ] **Step 3: Implement `packages/a-day/src/groups.ts`**

```ts
// packages/a-day/src/groups.ts
import type {
  ADayGroupId,
  Weekday,
  Shift,
  GroupCapacityConfig,
  Rank,
} from './types.js';

/** Canonical ordered list of combat groups, used for UI rendering and iteration. */
export const COMBAT_GROUPS: readonly ADayGroupId[] = Object.freeze([
  'G1',
  'G2',
  'G3',
  'G4',
]);

/** Canonical ordered list of weekdays (ISO order: Monday first). */
export const WEEKDAYS: readonly Weekday[] = Object.freeze([
  'MON',
  'TUE',
  'WED',
  'THU',
  'FRI',
  'SAT',
  'SUN',
]);

/** Officer ranks (those that count toward the 5/group invariant). */
export const OFFICER_RANKS: readonly Rank[] = Object.freeze(['DC', 'CPT', 'LT']);

/** Default capacity rule for a combat group: 18-19 members, exactly 5 officers. */
export const DEFAULT_GROUP_CAPACITY: GroupCapacityConfig = Object.freeze({
  min: 18,
  max: 19,
  officersRequired: 5,
});

/** Returns true if the given rank counts toward the officer invariant. */
export function isOfficer(rank: Rank): boolean {
  return OFFICER_RANKS.includes(rank);
}

/** Type guard: returns true if the value is a known combat group id. */
export function isCombatGroup(value: string): value is ADayGroupId {
  return (COMBAT_GROUPS as readonly string[]).includes(value);
}

/** Type guard: returns true if the value is a known weekday. */
export function isWeekday(value: string): value is Weekday {
  return (WEEKDAYS as readonly string[]).includes(value);
}

/**
 * Returns true if the given A-Day value is structurally valid for the shift.
 * - A/B/C: must be a combat group id
 * - D: must be a weekday
 */
export function isValidADayForShift(shift: Shift, aDay: string): boolean {
  if (shift === 'D') return isWeekday(aDay);
  return isCombatGroup(aDay);
}
```

- [ ] **Step 4: Export from `index.ts`**

```ts
// append to packages/a-day/src/index.ts
export {
  COMBAT_GROUPS,
  WEEKDAYS,
  OFFICER_RANKS,
  DEFAULT_GROUP_CAPACITY,
  isOfficer,
  isCombatGroup,
  isWeekday,
  isValidADayForShift,
} from './groups.js';
```

- [ ] **Step 5: Run test, expect PASS**

- [ ] **Step 6: Commit**

```bash
git add packages/a-day/src/groups.ts packages/a-day/src/index.ts packages/a-day/tests/unit/groups.test.ts
git commit -m "feat(a-day): COMBAT_GROUPS, WEEKDAYS, isOfficer helpers"
```

---

## Task 4: Capacity engine

**Files:**
- Create: `packages/a-day/src/capacity.ts`
- Test: `packages/a-day/tests/unit/capacity.test.ts`

The capacity engine answers: "Given the current set of A-Day picks, what does the capacity meter look like for (shift, group) or (shift=D, weekday)?" The function is pure — it takes the state, not the live DO.

- [ ] **Step 1: Write the failing test**

```ts
// packages/a-day/tests/unit/capacity.test.ts
import { describe, it, expect } from 'vitest';
import {
  computeCapacityMeter,
  computeAllMeters,
  isGroupFull,
} from '../../src/capacity.js';
import type { ADayState, ADayPick, Member } from '../../src/index.js';
import { DEFAULT_GROUP_CAPACITY } from '../../src/groups.js';

const officer = (id: number, rank: 'LT' | 'CPT' | 'DC' = 'LT'): Member => ({
  employeeId: String(id),
  firstName: 'O', lastName: String(id),
  rank, rscSeniority: id, rankSeniority: id,
  isProbationary: false, credentials: [],
});

const ff = (id: number): Member => ({
  employeeId: String(id),
  firstName: 'F', lastName: String(id),
  rank: 'FF', rscSeniority: id, rankSeniority: id,
  isProbationary: false, credentials: [],
});

function buildState(picks: ADayPick[], members: Member[]): ADayState {
  return {
    groupCaps: {
      A: { G1: { ...DEFAULT_GROUP_CAPACITY }, G2: { ...DEFAULT_GROUP_CAPACITY }, G3: { ...DEFAULT_GROUP_CAPACITY }, G4: { ...DEFAULT_GROUP_CAPACITY } },
      B: { G1: { ...DEFAULT_GROUP_CAPACITY }, G2: { ...DEFAULT_GROUP_CAPACITY }, G3: { ...DEFAULT_GROUP_CAPACITY }, G4: { ...DEFAULT_GROUP_CAPACITY } },
      C: { G1: { ...DEFAULT_GROUP_CAPACITY }, G2: { ...DEFAULT_GROUP_CAPACITY }, G3: { ...DEFAULT_GROUP_CAPACITY }, G4: { ...DEFAULT_GROUP_CAPACITY } },
    },
    weekdayCaps: {},
    picksByMember: new Map(picks.map((p) => [p.memberId, p])),
    bidOrder: [],
    cursor: 0,
    phase1ByMember: new Map(),
    membersById: new Map(members.map((m) => [Number(m.employeeId), m])),
  };
}

describe('computeCapacityMeter — A/B/C groups', () => {
  it('empty group returns total=0, officers=0, isFull=false', () => {
    const s = buildState([], []);
    const m = computeCapacityMeter(s, 'A', 'G1');
    expect(m.total).toBe(0);
    expect(m.officers).toBe(0);
    expect(m.max).toBe(19);
    expect(m.officersRequired).toBe(5);
    expect(m.isFull).toBe(false);
  });

  it('counts only picks matching the shift+group', () => {
    const picks: ADayPick[] = [
      { memberId: 1, shift: 'A', aDay: 'G1', pickedAtMs: 1, forced: false, adminActorId: null },
      { memberId: 2, shift: 'A', aDay: 'G2', pickedAtMs: 2, forced: false, adminActorId: null },
      { memberId: 3, shift: 'B', aDay: 'G1', pickedAtMs: 3, forced: false, adminActorId: null },
    ];
    const s = buildState(picks, [officer(1), officer(2), officer(3)]);
    expect(computeCapacityMeter(s, 'A', 'G1').total).toBe(1);
    expect(computeCapacityMeter(s, 'A', 'G2').total).toBe(1);
    expect(computeCapacityMeter(s, 'B', 'G1').total).toBe(1);
    expect(computeCapacityMeter(s, 'C', 'G1').total).toBe(0);
  });

  it('officers count incremented only for DC/CPT/LT picks', () => {
    const picks: ADayPick[] = [
      { memberId: 1, shift: 'A', aDay: 'G1', pickedAtMs: 1, forced: false, adminActorId: null },
      { memberId: 2, shift: 'A', aDay: 'G1', pickedAtMs: 2, forced: false, adminActorId: null },
      { memberId: 3, shift: 'A', aDay: 'G1', pickedAtMs: 3, forced: false, adminActorId: null },
    ];
    const s = buildState(picks, [officer(1, 'CPT'), ff(2), officer(3, 'LT')]);
    const m = computeCapacityMeter(s, 'A', 'G1');
    expect(m.total).toBe(3);
    expect(m.officers).toBe(2);
  });

  it('isFull true when total === max', () => {
    const picks: ADayPick[] = Array.from({ length: 19 }, (_, i) => ({
      memberId: i + 1, shift: 'A' as const, aDay: 'G1' as const,
      pickedAtMs: i, forced: false, adminActorId: null,
    }));
    const members = picks.map((p) => ff(p.memberId));
    const s = buildState(picks, members);
    expect(computeCapacityMeter(s, 'A', 'G1').isFull).toBe(true);
  });

  it('unknown member is counted toward total but NOT toward officers', () => {
    // Defensive: if membersById lacks the entry, we still count the pick total
    // but cannot attribute officer-ness without rank data.
    const picks: ADayPick[] = [
      { memberId: 99, shift: 'A', aDay: 'G1', pickedAtMs: 1, forced: false, adminActorId: null },
    ];
    const s = buildState(picks, []);
    const m = computeCapacityMeter(s, 'A', 'G1');
    expect(m.total).toBe(1);
    expect(m.officers).toBe(0);
  });
});

describe('computeCapacityMeter — D shift weekdays', () => {
  it('no cap by default — max is undefined and isFull is always false', () => {
    const picks: ADayPick[] = [
      { memberId: 1, shift: 'D', aDay: 'FRI', pickedAtMs: 1, forced: false, adminActorId: null },
      { memberId: 2, shift: 'D', aDay: 'FRI', pickedAtMs: 2, forced: false, adminActorId: null },
    ];
    const s = buildState(picks, [officer(1, 'CPT'), officer(2, 'LT')]);
    const m = computeCapacityMeter(s, 'D', 'FRI');
    expect(m.total).toBe(2);
    expect(m.max).toBeUndefined();
    expect(m.officersRequired).toBeUndefined();
    expect(m.isFull).toBe(false);
  });

  it('respects configured weekday max when set', () => {
    const s = buildState(
      [
        { memberId: 1, shift: 'D', aDay: 'FRI', pickedAtMs: 1, forced: false, adminActorId: null },
        { memberId: 2, shift: 'D', aDay: 'FRI', pickedAtMs: 2, forced: false, adminActorId: null },
      ],
      [officer(1), officer(2)],
    );
    const sWithCaps: ADayState = { ...s, weekdayCaps: { FRI: { max: 2 } } };
    const m = computeCapacityMeter(sWithCaps, 'D', 'FRI');
    expect(m.total).toBe(2);
    expect(m.max).toBe(2);
    expect(m.isFull).toBe(true);
  });
});

describe('isGroupFull', () => {
  it('false when total < max', () => {
    const s = buildState([], []);
    expect(isGroupFull(s, 'A', 'G1')).toBe(false);
  });

  it('true when total === max', () => {
    const picks: ADayPick[] = Array.from({ length: 19 }, (_, i) => ({
      memberId: i + 1, shift: 'A' as const, aDay: 'G1' as const,
      pickedAtMs: i, forced: false, adminActorId: null,
    }));
    const s = buildState(picks, picks.map((p) => ff(p.memberId)));
    expect(isGroupFull(s, 'A', 'G1')).toBe(true);
  });
});

describe('computeAllMeters', () => {
  it('returns 12 group meters + N weekday meters', () => {
    const s = buildState([], []);
    const meters = computeAllMeters(s);
    // 3 shifts * 4 groups = 12 + 7 weekdays = 19
    expect(meters.groups.length).toBe(12);
    expect(meters.weekdays.length).toBe(7);
  });

  it('each group meter has shift, group, meter fields', () => {
    const s = buildState([], []);
    const meters = computeAllMeters(s);
    for (const m of meters.groups) {
      expect(m.shift).toMatch(/^[ABC]$/);
      expect(m.group).toMatch(/^G[1-4]$/);
      expect(typeof m.meter.total).toBe('number');
    }
  });
});
```

- [ ] **Step 2: Run test, expect FAIL**

- [ ] **Step 3: Implement `packages/a-day/src/capacity.ts`**

```ts
// packages/a-day/src/capacity.ts
import type {
  ADayState,
  Shift,
  ADayGroupId,
  Weekday,
  CapacityMeter,
} from './types.js';
import { COMBAT_GROUPS, WEEKDAYS, isOfficer } from './groups.js';

/**
 * Returns the capacity meter for one (shift, aDay) pair.
 * - For A/B/C shifts: aDay must be one of G1..G4.
 * - For D shift: aDay must be a weekday; the cap is taken from state.weekdayCaps.
 *
 * Pure: does not mutate state.
 */
export function computeCapacityMeter(
  state: ADayState,
  shift: Shift,
  aDay: ADayGroupId | Weekday,
): CapacityMeter {
  let total = 0;
  let officers = 0;

  for (const pick of state.picksByMember.values()) {
    if (pick.shift !== shift) continue;
    if (pick.aDay !== aDay) continue;
    total++;
    const member = state.membersById.get(pick.memberId);
    if (member && isOfficer(member.rank)) {
      officers++;
    }
  }

  if (shift === 'D') {
    const weekdayCap = state.weekdayCaps[aDay as Weekday];
    const max = weekdayCap?.max;
    return {
      total,
      max,
      officers,
      officersRequired: undefined,
      isFull: max !== undefined && total >= max,
    };
  }

  // A / B / C
  const cap = state.groupCaps[shift as Exclude<Shift, 'D'>][aDay as ADayGroupId];
  return {
    total,
    max: cap.max,
    officers,
    officersRequired: cap.officersRequired,
    isFull: total >= cap.max,
  };
}

/**
 * Returns true if the (shift, group) is at or above its max capacity.
 * Convenience wrapper over computeCapacityMeter for the common gate check.
 */
export function isGroupFull(
  state: ADayState,
  shift: Exclude<Shift, 'D'>,
  group: ADayGroupId,
): boolean {
  return computeCapacityMeter(state, shift, group).isFull;
}

/**
 * Returns all 12 A/B/C group meters plus all 7 weekday meters in one pass.
 * Used by the UI to render the full picker board and by the AI advisory
 * to summarize current capacity in prompts.
 */
export function computeAllMeters(state: ADayState): {
  groups: Array<{ shift: Exclude<Shift, 'D'>; group: ADayGroupId; meter: CapacityMeter }>;
  weekdays: Array<{ weekday: Weekday; meter: CapacityMeter }>;
} {
  const groups: Array<{ shift: Exclude<Shift, 'D'>; group: ADayGroupId; meter: CapacityMeter }> = [];
  for (const shift of ['A', 'B', 'C'] as const) {
    for (const group of COMBAT_GROUPS) {
      groups.push({ shift, group, meter: computeCapacityMeter(state, shift, group) });
    }
  }
  const weekdays = WEEKDAYS.map((weekday) => ({
    weekday,
    meter: computeCapacityMeter(state, 'D', weekday),
  }));
  return { groups, weekdays };
}
```

- [ ] **Step 4: Export from `index.ts`**

```ts
// append to packages/a-day/src/index.ts
export { computeCapacityMeter, isGroupFull, computeAllMeters } from './capacity.js';
```

- [ ] **Step 5: Run test, expect PASS**

- [ ] **Step 6: Commit**

```bash
git add packages/a-day/src/capacity.ts packages/a-day/src/index.ts packages/a-day/tests/unit/capacity.test.ts
git commit -m "feat(a-day): capacity engine (computeCapacityMeter, isGroupFull, computeAllMeters)"
```

---

## Task 5: Officer invariant checker

**Files:**
- Create: `packages/a-day/src/officer-invariant.ts`
- Test: `packages/a-day/tests/unit/officer-invariant.test.ts`

The officer invariant: "Every A/B/C group ends with exactly 5 officers." A pick is rejected up front only if it would push the group's officer count above 5, OR if accepting it would make it impossible to reach 5 in another group given the remaining bidders on that shift.

Feasibility check (the harder half): for shift S and group G, after a hypothetical pick:
- Let `O(S, g)` = current officers in group g on shift S, post-pick.
- Let `R(S)` = remaining officers on shift S yet to pick (members in bidOrder past the cursor whose Phase 1 shift == S and isOfficer(rank)).
- Sum across groups of `max(0, officersRequired - O(S, g))` must be `<= R(S)` AND `O(S, g) <= officersRequired` for every group g.

That is: enough remaining officers to fill the shortfalls, AND no group is already over.

- [ ] **Step 1: Write failing test**

```ts
// packages/a-day/tests/unit/officer-invariant.test.ts
import { describe, it, expect } from 'vitest';
import {
  projectedOfficers,
  validateOfficerInvariant,
} from '../../src/officer-invariant.js';
import type { ADayState, ADayPick, Member } from '../../src/index.js';
import { DEFAULT_GROUP_CAPACITY } from '../../src/groups.js';

const officer = (id: number): Member => ({
  employeeId: String(id), firstName: 'O', lastName: String(id),
  rank: 'LT', rscSeniority: id, rankSeniority: id,
  isProbationary: false, credentials: [],
});

const ff = (id: number): Member => ({
  employeeId: String(id), firstName: 'F', lastName: String(id),
  rank: 'FF', rscSeniority: id, rankSeniority: id,
  isProbationary: false, credentials: [],
});

const baseCaps = {
  A: { G1: { ...DEFAULT_GROUP_CAPACITY }, G2: { ...DEFAULT_GROUP_CAPACITY }, G3: { ...DEFAULT_GROUP_CAPACITY }, G4: { ...DEFAULT_GROUP_CAPACITY } },
  B: { G1: { ...DEFAULT_GROUP_CAPACITY }, G2: { ...DEFAULT_GROUP_CAPACITY }, G3: { ...DEFAULT_GROUP_CAPACITY }, G4: { ...DEFAULT_GROUP_CAPACITY } },
  C: { G1: { ...DEFAULT_GROUP_CAPACITY }, G2: { ...DEFAULT_GROUP_CAPACITY }, G3: { ...DEFAULT_GROUP_CAPACITY }, G4: { ...DEFAULT_GROUP_CAPACITY } },
} as const;

function buildState(opts: {
  picks: ADayPick[];
  members: Member[];
  bidOrder: number[];
  cursor: number;
  phase1Shifts: ReadonlyMap<number, 'A' | 'B' | 'C' | 'D'>;
}): ADayState {
  return {
    groupCaps: baseCaps,
    weekdayCaps: {},
    picksByMember: new Map(opts.picks.map((p) => [p.memberId, p])),
    bidOrder: opts.bidOrder,
    cursor: opts.cursor,
    phase1ByMember: new Map(
      [...opts.phase1Shifts].map(([id, shift]) => [id, { positionId: `${shift}999`, shift }]),
    ),
    membersById: new Map(opts.members.map((m) => [Number(m.employeeId), m])),
  };
}

describe('projectedOfficers', () => {
  it('returns current count when candidate is a firefighter', () => {
    const s = buildState({
      picks: [
        { memberId: 1, shift: 'A', aDay: 'G1', pickedAtMs: 1, forced: false, adminActorId: null },
      ],
      members: [officer(1), ff(99)],
      bidOrder: [], cursor: 0, phase1Shifts: new Map(),
    });
    expect(projectedOfficers(s, 'A', 'G1', 99)).toBe(1);
  });

  it('returns current+1 when candidate is an officer', () => {
    const s = buildState({
      picks: [
        { memberId: 1, shift: 'A', aDay: 'G1', pickedAtMs: 1, forced: false, adminActorId: null },
      ],
      members: [officer(1), officer(99)],
      bidOrder: [], cursor: 0, phase1Shifts: new Map(),
    });
    expect(projectedOfficers(s, 'A', 'G1', 99)).toBe(2);
  });
});

describe('validateOfficerInvariant — basic cases', () => {
  it('officer joining a group with 4 officers → projected=5, feasible=true', () => {
    const fourOfficers: ADayPick[] = [10, 11, 12, 13].map((id) => ({
      memberId: id, shift: 'A' as const, aDay: 'G1' as const,
      pickedAtMs: id, forced: false, adminActorId: null,
    }));
    const members = [officer(10), officer(11), officer(12), officer(13), officer(99)];
    const s = buildState({
      picks: fourOfficers,
      members,
      bidOrder: [99, 100, 101], cursor: 0,
      phase1Shifts: new Map([[99, 'A'], [100, 'A'], [101, 'A']]),
    });
    const r = validateOfficerInvariant(s, 'A', 'G1', 99);
    expect(r.feasible).toBe(true);
    expect(r.projectedOfficers).toBe(5);
    expect(r.required).toBe(5);
  });

  it('officer joining a group already at 5 officers → projected=6, feasible=false', () => {
    const fiveOfficers: ADayPick[] = [10, 11, 12, 13, 14].map((id) => ({
      memberId: id, shift: 'A' as const, aDay: 'G1' as const,
      pickedAtMs: id, forced: false, adminActorId: null,
    }));
    const s = buildState({
      picks: fiveOfficers,
      members: [officer(10), officer(11), officer(12), officer(13), officer(14), officer(99)],
      bidOrder: [99], cursor: 0, phase1Shifts: new Map([[99, 'A']]),
    });
    const r = validateOfficerInvariant(s, 'A', 'G1', 99);
    expect(r.feasible).toBe(false);
    expect(r.projectedOfficers).toBe(6);
    expect(r.explanation).toMatch(/exceeds the maximum/i);
  });

  it('firefighter joining any group does not affect officer feasibility', () => {
    const s = buildState({
      picks: [],
      members: [ff(99)],
      bidOrder: [99], cursor: 0, phase1Shifts: new Map([[99, 'A']]),
    });
    const r = validateOfficerInvariant(s, 'A', 'G1', 99);
    expect(r.feasible).toBe(true);
    expect(r.projectedOfficers).toBe(0);
  });
});

describe('validateOfficerInvariant — feasibility across remaining bidders', () => {
  it('returns infeasible when accepting this pick leaves another group unable to reach 5', () => {
    // Scenario: A-shift, 3 groups already at 5 officers, G4 at 0 officers.
    // Only 4 officers remain in the bid order. If the current candidate (an officer)
    // joins G1, then only 3 officers remain — G4 cannot reach 5 → infeasible.
    const picksAt5 = (group: 'G1' | 'G2' | 'G3') =>
      [10, 11, 12, 13, 14].map((base) => ({
        memberId: base + (group === 'G1' ? 0 : group === 'G2' ? 100 : 200),
        shift: 'A' as const,
        aDay: group,
        pickedAtMs: 1, forced: false, adminActorId: null,
      }));
    const allPicks = [...picksAt5('G1'), ...picksAt5('G2'), ...picksAt5('G3')];
    const memberIds = allPicks.map((p) => p.memberId);
    const remainingOfficerIds = [500, 501, 502, 503]; // only 4 officers left to bid
    const candidateId = 500;
    const members = [
      ...memberIds.map((id) => officer(id)),
      ...remainingOfficerIds.map((id) => officer(id)),
    ];
    const s = buildState({
      picks: allPicks,
      members,
      bidOrder: remainingOfficerIds,
      cursor: 0,
      phase1Shifts: new Map(remainingOfficerIds.map((id) => [id, 'A' as const])),
    });
    // Candidate tries to take G1 (already at 5) — direct rejection (over max)
    // Try G4 — needs 5 officers; only 4 remaining including candidate; G4 will end at 4 → infeasible.
    // To test feasibility (not max-over) we put G1, G2, G3 each at 4 instead of 5
    // so they still need one more, plus G4 needs 5.
    // Total remaining required = 1+1+1+5 = 8, but only 4 officers left → infeasible.
    const picksAt4 = (group: 'G1' | 'G2' | 'G3') =>
      [10, 11, 12, 13].map((base) => ({
        memberId: base + (group === 'G1' ? 0 : group === 'G2' ? 100 : 200),
        shift: 'A' as const, aDay: group, pickedAtMs: 1, forced: false, adminActorId: null,
      }));
    const at4Picks = [...picksAt4('G1'), ...picksAt4('G2'), ...picksAt4('G3')];
    const s2 = buildState({
      picks: at4Picks,
      members: [...at4Picks.map((p) => officer(p.memberId)), ...remainingOfficerIds.map((id) => officer(id))],
      bidOrder: remainingOfficerIds,
      cursor: 0,
      phase1Shifts: new Map(remainingOfficerIds.map((id) => [id, 'A' as const])),
    });
    const r = validateOfficerInvariant(s2, 'A', 'G1', candidateId);
    // After pick, G1 has 5, but G2/G3 still need 1 each (=2), G4 needs 5 → total 7;
    // remaining officers after this pick = 3 → infeasible.
    expect(r.feasible).toBe(false);
    expect(r.explanation).toMatch(/insufficient officers remaining/i);
  });

  it('feasible when remaining officers exactly equals the shortfall', () => {
    // 3 groups at 5/5, G4 at 4/5. Candidate is an officer for G4. No more officers remaining.
    const at5 = (group: 'G1' | 'G2' | 'G3' | 'G4', count: number) =>
      Array.from({ length: count }, (_, i) => ({
        memberId: i + (group === 'G1' ? 100 : group === 'G2' ? 200 : group === 'G3' ? 300 : 400),
        shift: 'A' as const, aDay: group, pickedAtMs: 1, forced: false, adminActorId: null,
      }));
    const picks = [...at5('G1', 5), ...at5('G2', 5), ...at5('G3', 5), ...at5('G4', 4)];
    const candidateId = 999;
    const s = buildState({
      picks,
      members: [...picks.map((p) => officer(p.memberId)), officer(candidateId)],
      bidOrder: [candidateId], cursor: 0, phase1Shifts: new Map([[candidateId, 'A']]),
    });
    const r = validateOfficerInvariant(s, 'A', 'G4', candidateId);
    expect(r.feasible).toBe(true);
  });
});
```

- [ ] **Step 2: Run test, expect FAIL**

- [ ] **Step 3: Implement `packages/a-day/src/officer-invariant.ts`**

```ts
// packages/a-day/src/officer-invariant.ts
import type {
  ADayState,
  Shift,
  ADayGroupId,
  OfficerInvariantSnapshot,
} from './types.js';
import { COMBAT_GROUPS, isOfficer } from './groups.js';

/**
 * Returns the projected officer count for (shift, group) if `candidateId`
 * picks that group. If the candidate's rank is not an officer rank, the count
 * is unchanged.
 */
export function projectedOfficers(
  state: ADayState,
  shift: Exclude<Shift, 'D'>,
  group: ADayGroupId,
  candidateId: number,
): number {
  let current = 0;
  for (const pick of state.picksByMember.values()) {
    if (pick.shift !== shift || pick.aDay !== group) continue;
    const member = state.membersById.get(pick.memberId);
    if (member && isOfficer(member.rank)) current++;
  }
  const candidate = state.membersById.get(candidateId);
  const candidateIsOfficer = candidate ? isOfficer(candidate.rank) : false;
  return candidateIsOfficer ? current + 1 : current;
}

/**
 * Per-group officer counts on a given shift, given a hypothetical pick by candidateId.
 * Used both for projection and for the feasibility look-ahead.
 */
function shiftOfficerCounts(
  state: ADayState,
  shift: Exclude<Shift, 'D'>,
  hypothetical?: { candidateId: number; group: ADayGroupId },
): Record<ADayGroupId, number> {
  const counts: Record<ADayGroupId, number> = { G1: 0, G2: 0, G3: 0, G4: 0 };
  for (const pick of state.picksByMember.values()) {
    if (pick.shift !== shift) continue;
    const g = pick.aDay as ADayGroupId;
    const member = state.membersById.get(pick.memberId);
    if (member && isOfficer(member.rank)) counts[g]++;
  }
  if (hypothetical) {
    const candidate = state.membersById.get(hypothetical.candidateId);
    if (candidate && isOfficer(candidate.rank)) {
      counts[hypothetical.group]++;
    }
  }
  return counts;
}

/**
 * Returns the number of officers still ahead in the bid order whose Phase 1 shift
 * equals `shift`, EXCLUDING the candidateId (since the candidate is being applied now).
 */
function remainingOfficersOnShift(
  state: ADayState,
  shift: Exclude<Shift, 'D'>,
  candidateId: number,
): number {
  let count = 0;
  for (let i = state.cursor; i < state.bidOrder.length; i++) {
    const id = state.bidOrder[i];
    if (id === undefined || id === candidateId) continue;
    const member = state.membersById.get(id);
    const phase1 = state.phase1ByMember.get(id);
    if (!member || !phase1) continue;
    if (phase1.shift !== shift) continue;
    if (isOfficer(member.rank)) count++;
  }
  return count;
}

/**
 * Validates that accepting a hypothetical pick for `candidateId` on
 * (shift, group) leaves the officer-per-group invariant satisfiable.
 *
 * Returns an OfficerInvariantSnapshot with `feasible` indicating whether
 * the invariant CAN still be met by completion of Phase 2.
 *
 * Two reasons for infeasibility:
 *   (a) Direct overflow — this pick would push the group above officersRequired.
 *   (b) Look-ahead shortfall — even after this pick, the sum of remaining
 *       officer shortfalls across all groups exceeds the number of officers
 *       still to bid on this shift.
 */
export function validateOfficerInvariant(
  state: ADayState,
  shift: Exclude<Shift, 'D'>,
  group: ADayGroupId,
  candidateId: number,
): OfficerInvariantSnapshot {
  const required = state.groupCaps[shift][group].officersRequired;
  const current = shiftOfficerCounts(state, shift)[group];
  const projected = projectedOfficers(state, shift, group, candidateId);

  // Case (a): direct overflow
  if (projected > required) {
    return {
      shift,
      group,
      currentOfficers: current,
      projectedOfficers: projected,
      required,
      feasible: false,
      explanation: `Pick exceeds the maximum of ${required} officers in ${shift}-shift ${group} (would be ${projected}).`,
    };
  }

  // Case (b): look-ahead feasibility
  const postCounts = shiftOfficerCounts(state, shift, { candidateId, group });
  const remaining = remainingOfficersOnShift(state, shift, candidateId);
  let shortfall = 0;
  for (const g of COMBAT_GROUPS) {
    const need = required - postCounts[g];
    if (need < 0) {
      // Some other group already over — should not happen if we always check on pick,
      // but report it explicitly.
      return {
        shift,
        group,
        currentOfficers: current,
        projectedOfficers: projected,
        required,
        feasible: false,
        explanation: `Officer count in ${shift}-shift ${g} is already above ${required}.`,
      };
    }
    shortfall += need;
  }
  if (shortfall > remaining) {
    return {
      shift,
      group,
      currentOfficers: current,
      projectedOfficers: projected,
      required,
      feasible: false,
      explanation: `Accepting this pick leaves ${shortfall} officer slots to fill on ${shift}-shift but only ${remaining} officers remain in the bid order (insufficient officers remaining).`,
    };
  }

  return {
    shift,
    group,
    currentOfficers: current,
    projectedOfficers: projected,
    required,
    feasible: true,
    explanation: `Pick keeps ${shift}-shift ${group} at ${projected}/${required} officers; remaining bid order can still satisfy all groups.`,
  };
}
```

- [ ] **Step 4: Export from `index.ts`**

```ts
// append to packages/a-day/src/index.ts
export {
  projectedOfficers,
  validateOfficerInvariant,
} from './officer-invariant.js';
```

- [ ] **Step 5: Run test, expect PASS**

- [ ] **Step 6: Commit**

```bash
git add packages/a-day/src/officer-invariant.ts packages/a-day/src/index.ts packages/a-day/tests/unit/officer-invariant.test.ts
git commit -m "feat(a-day): officer invariant checker with feasibility look-ahead"
```

---

## Task 6: `canPick` — the single source-of-truth validator

**Files:**
- Create: `packages/a-day/src/can-pick.ts`
- Test: `packages/a-day/tests/unit/can-pick.test.ts`

`canPick` is the only function the DO calls to validate a Phase-2 pick. It composes:
1. Member known? (membersById lookup)
2. Phase 1 pick present? (phase1ByMember lookup; otherwise NO_PHASE_1_PICK)
3. Already picked Phase 2? (picksByMember.has → ALREADY_PICKED)
4. A-Day structurally valid for shift? (isValidADayForShift)
5. Capacity check: group/weekday not full
6. Officer invariant feasible (A/B/C only)

Returns `PickValidation` with full snapshot data so the DO can broadcast either an ACCEPT (with meter) or REJECT (with reasonCode).

- [ ] **Step 1: Write failing test**

```ts
// packages/a-day/tests/unit/can-pick.test.ts
import { describe, it, expect } from 'vitest';
import { canPick } from '../../src/can-pick.js';
import type { ADayState, ADayPick, Member } from '../../src/index.js';
import { DEFAULT_GROUP_CAPACITY } from '../../src/groups.js';

const ff = (id: number): Member => ({
  employeeId: String(id), firstName: 'F', lastName: String(id),
  rank: 'FF', rscSeniority: id, rankSeniority: id,
  isProbationary: false, credentials: [],
});

const lt = (id: number): Member => ({
  employeeId: String(id), firstName: 'L', lastName: String(id),
  rank: 'LT', rscSeniority: id, rankSeniority: id,
  isProbationary: false, credentials: [],
});

const baseCaps = {
  A: { G1: { ...DEFAULT_GROUP_CAPACITY }, G2: { ...DEFAULT_GROUP_CAPACITY }, G3: { ...DEFAULT_GROUP_CAPACITY }, G4: { ...DEFAULT_GROUP_CAPACITY } },
  B: { G1: { ...DEFAULT_GROUP_CAPACITY }, G2: { ...DEFAULT_GROUP_CAPACITY }, G3: { ...DEFAULT_GROUP_CAPACITY }, G4: { ...DEFAULT_GROUP_CAPACITY } },
  C: { G1: { ...DEFAULT_GROUP_CAPACITY }, G2: { ...DEFAULT_GROUP_CAPACITY }, G3: { ...DEFAULT_GROUP_CAPACITY }, G4: { ...DEFAULT_GROUP_CAPACITY } },
} as const;

function buildState(opts: Partial<{
  picks: ADayPick[];
  members: Member[];
  bidOrder: number[];
  cursor: number;
  phase1: Array<[number, { positionId: string; shift: 'A' | 'B' | 'C' | 'D' }]>;
  weekdayCaps: Record<string, { max: number | undefined }>;
}> = {}): ADayState {
  return {
    groupCaps: baseCaps,
    weekdayCaps: (opts.weekdayCaps ?? {}) as never,
    picksByMember: new Map((opts.picks ?? []).map((p) => [p.memberId, p])),
    bidOrder: opts.bidOrder ?? [],
    cursor: opts.cursor ?? 0,
    phase1ByMember: new Map(opts.phase1 ?? []),
    membersById: new Map((opts.members ?? []).map((m) => [Number(m.employeeId), m])),
  };
}

describe('canPick — gate cases', () => {
  it('UNKNOWN_MEMBER when membersById lacks the candidate', () => {
    const s = buildState({});
    const r = canPick(s, 999, 'G1');
    expect(r.ok).toBe(false);
    if (!r.ok) expect(r.reasonCode).toBe('UNKNOWN_MEMBER');
  });

  it('NO_PHASE_1_PICK when member has no Phase 1 record', () => {
    const s = buildState({ members: [ff(1)] });
    const r = canPick(s, 1, 'G1');
    expect(r.ok).toBe(false);
    if (!r.ok) expect(r.reasonCode).toBe('NO_PHASE_1_PICK');
  });

  it('ALREADY_PICKED when member already has a Phase 2 pick', () => {
    const s = buildState({
      members: [ff(1)],
      phase1: [[1, { positionId: 'A101', shift: 'A' }]],
      picks: [
        { memberId: 1, shift: 'A', aDay: 'G1', pickedAtMs: 1, forced: false, adminActorId: null },
      ],
    });
    const r = canPick(s, 1, 'G2');
    expect(r.ok).toBe(false);
    if (!r.ok) expect(r.reasonCode).toBe('ALREADY_PICKED');
  });

  it('INVALID_R_DAY_FOR_SHIFT — A-shift member picks weekday', () => {
    const s = buildState({
      members: [ff(1)],
      phase1: [[1, { positionId: 'A101', shift: 'A' }]],
    });
    const r = canPick(s, 1, 'FRI');
    expect(r.ok).toBe(false);
    if (!r.ok) expect(r.reasonCode).toBe('INVALID_R_DAY_FOR_SHIFT');
  });

  it('INVALID_R_DAY_FOR_SHIFT — D-shift member picks group', () => {
    const s = buildState({
      members: [ff(1)],
      phase1: [[1, { positionId: 'D101', shift: 'D' }]],
    });
    const r = canPick(s, 1, 'G1');
    expect(r.ok).toBe(false);
    if (!r.ok) expect(r.reasonCode).toBe('INVALID_R_DAY_FOR_SHIFT');
  });

  it('GROUP_FULL — A1 already at 19 members', () => {
    const fullPicks: ADayPick[] = Array.from({ length: 19 }, (_, i) => ({
      memberId: i + 100, shift: 'A' as const, aDay: 'G1' as const,
      pickedAtMs: i, forced: false, adminActorId: null,
    }));
    const members = [...fullPicks.map((p) => ff(p.memberId)), ff(1)];
    const s = buildState({
      members,
      phase1: [
        [1, { positionId: 'A101', shift: 'A' }],
        ...fullPicks.map((p) => [p.memberId, { positionId: 'A101', shift: 'A' as const }] as const),
      ],
      picks: fullPicks,
    });
    const r = canPick(s, 1, 'G1');
    expect(r.ok).toBe(false);
    if (!r.ok) expect(r.reasonCode).toBe('GROUP_FULL');
  });

  it('WEEKDAY_FULL — FRI cap set to 2 and already 2 picks', () => {
    const picks: ADayPick[] = [
      { memberId: 10, shift: 'D', aDay: 'FRI', pickedAtMs: 1, forced: false, adminActorId: null },
      { memberId: 11, shift: 'D', aDay: 'FRI', pickedAtMs: 2, forced: false, adminActorId: null },
    ];
    const s = buildState({
      members: [ff(10), ff(11), ff(1)],
      phase1: [
        [1, { positionId: 'D101', shift: 'D' }],
        [10, { positionId: 'D101', shift: 'D' }],
        [11, { positionId: 'D101', shift: 'D' }],
      ],
      picks,
      weekdayCaps: { FRI: { max: 2 } },
    });
    const r = canPick(s, 1, 'FRI');
    expect(r.ok).toBe(false);
    if (!r.ok) expect(r.reasonCode).toBe('WEEKDAY_FULL');
  });

  it('OFFICER_INVARIANT_VIOLATED — 6th officer in a group of 5', () => {
    const fivePicks: ADayPick[] = [10, 11, 12, 13, 14].map((id) => ({
      memberId: id, shift: 'A' as const, aDay: 'G1' as const,
      pickedAtMs: id, forced: false, adminActorId: null,
    }));
    const s = buildState({
      members: [
        ...fivePicks.map((p) => lt(p.memberId)),
        lt(99),
      ],
      phase1: [
        [99, { positionId: 'A101', shift: 'A' }],
        ...fivePicks.map((p) => [p.memberId, { positionId: 'A101', shift: 'A' as const }] as const),
      ],
      picks: fivePicks,
    });
    const r = canPick(s, 99, 'G1');
    expect(r.ok).toBe(false);
    if (!r.ok) expect(r.reasonCode).toBe('OFFICER_INVARIANT_VIOLATED');
  });
});

describe('canPick — happy paths', () => {
  it('FF on A-shift picks an empty group — ok with projectedMeter', () => {
    const s = buildState({
      members: [ff(1)],
      phase1: [[1, { positionId: 'A105', shift: 'A' }]],
    });
    const r = canPick(s, 1, 'G2');
    expect(r.ok).toBe(true);
    if (r.ok) {
      expect(r.projectedMeter.total).toBe(1);
      expect(r.projectedMeter.officers).toBe(0);
      expect(r.officerSnapshot).toBeDefined();
    }
  });

  it('D-shift FF picks FRI (no cap) — ok, officerSnapshot omitted', () => {
    const s = buildState({
      members: [ff(1)],
      phase1: [[1, { positionId: 'D101', shift: 'D' }]],
    });
    const r = canPick(s, 1, 'FRI');
    expect(r.ok).toBe(true);
    if (r.ok) {
      expect(r.officerSnapshot).toBeUndefined();
      expect(r.projectedMeter.max).toBeUndefined();
    }
  });
});
```

- [ ] **Step 2: Run test, expect FAIL**

- [ ] **Step 3: Implement `packages/a-day/src/can-pick.ts`**

```ts
// packages/a-day/src/can-pick.ts
import type {
  ADayState,
  ADayValue,
  PickValidation,
  Shift,
  ADayGroupId,
  Weekday,
} from './types.js';
import {
  isCombatGroup,
  isValidADayForShift,
} from './groups.js';
import { computeCapacityMeter } from './capacity.js';
import { validateOfficerInvariant } from './officer-invariant.js';

/**
 * Validates a candidate Phase-2 pick. Returns a discriminated union that the DO
 * uses to either apply the pick or broadcast a REJECT with structured detail.
 *
 * Order of checks matters — earlier rejections are cheaper and more informative:
 *   1. UNKNOWN_MEMBER (rare; programmer error)
 *   2. NO_PHASE_1_PICK (member never bid in Phase 1)
 *   3. ALREADY_PICKED (member already completed Phase 2)
 *   4. INVALID_R_DAY_FOR_SHIFT (UI bug or malicious client)
 *   5. GROUP_FULL / WEEKDAY_FULL (capacity)
 *   6. OFFICER_INVARIANT_VIOLATED (look-ahead)
 *
 * Note: NOT_YOUR_TURN and PHASE_NOT_R_DAY_BID are checked by the DO itself,
 * since they are session-level concerns that don't belong in this pure function.
 */
export function canPick(
  state: ADayState,
  memberId: number,
  aDay: ADayValue,
): PickValidation {
  const member = state.membersById.get(memberId);
  if (!member) {
    return {
      ok: false,
      reasonCode: 'UNKNOWN_MEMBER',
      reasonLabel: `Member ${memberId} not found in the bid session roster.`,
    };
  }

  const phase1 = state.phase1ByMember.get(memberId);
  if (!phase1) {
    return {
      ok: false,
      reasonCode: 'NO_PHASE_1_PICK',
      reasonLabel: `Member ${memberId} has no Phase 1 pick recorded; cannot pick A-Day until Phase 1 is complete for them.`,
    };
  }

  if (state.picksByMember.has(memberId)) {
    return {
      ok: false,
      reasonCode: 'ALREADY_PICKED',
      reasonLabel: `Member ${memberId} has already submitted a Phase 2 A-Day pick.`,
    };
  }

  const shift: Shift = phase1.shift;
  if (!isValidADayForShift(shift, aDay)) {
    return {
      ok: false,
      reasonCode: 'INVALID_R_DAY_FOR_SHIFT',
      reasonLabel:
        shift === 'D'
          ? `D-shift members must pick a weekday (MON-SUN); got "${aDay}".`
          : `${shift}-shift members must pick a combat group (G1-G4); got "${aDay}".`,
    };
  }

  // Capacity check
  const meter = computeCapacityMeter(state, shift, aDay);
  if (meter.isFull) {
    return {
      ok: false,
      reasonCode: shift === 'D' ? 'WEEKDAY_FULL' : 'GROUP_FULL',
      reasonLabel:
        shift === 'D'
          ? `Weekday ${aDay} on D-shift is full (${meter.total}/${meter.max}).`
          : `Group ${aDay} on ${shift}-shift is full (${meter.total}/${meter.max}).`,
      detail: { total: meter.total, max: meter.max ?? -1 },
    };
  }

  // Officer-invariant check (A/B/C only)
  if (shift !== 'D' && isCombatGroup(aDay)) {
    const snapshot = validateOfficerInvariant(
      state,
      shift,
      aDay as ADayGroupId,
      memberId,
    );
    if (!snapshot.feasible) {
      return {
        ok: false,
        reasonCode: 'OFFICER_INVARIANT_VIOLATED',
        reasonLabel: snapshot.explanation,
        detail: {
          projectedOfficers: snapshot.projectedOfficers,
          required: snapshot.required,
        },
      };
    }
    // Projected meter post-pick
    const projectedMeter = {
      ...meter,
      total: meter.total + 1,
      officers: snapshot.projectedOfficers,
      isFull: meter.total + 1 >= (meter.max ?? Number.POSITIVE_INFINITY),
    };
    return { ok: true, projectedMeter, officerSnapshot: snapshot };
  }

  // D-shift accept
  const projectedMeter = {
    ...meter,
    total: meter.total + 1,
    isFull: meter.max !== undefined && meter.total + 1 >= meter.max,
  };
  return { ok: true, projectedMeter };
}
```

- [ ] **Step 4: Export from `index.ts`**

```ts
// append to packages/a-day/src/index.ts
export { canPick } from './can-pick.js';
```

- [ ] **Step 5: Run test, expect PASS**

- [ ] **Step 6: Commit**

```bash
git add packages/a-day/src/can-pick.ts packages/a-day/src/index.ts packages/a-day/tests/unit/can-pick.test.ts
git commit -m "feat(a-day): canPick — composed pick validator with structured rejection codes"
```

---

## Task 7: State init + applyPick

**Files:**
- Create: `packages/a-day/src/state.ts`
- Test: `packages/a-day/tests/unit/state.test.ts`

`initADayState` builds the initial Phase-2 state from Phase 1 results, member roster, group caps, and bid order. `applyPick` returns a new state with the pick recorded and cursor advanced. Both are pure — no I/O, no clock.

- [ ] **Step 1: Write failing test**

```ts
// packages/a-day/tests/unit/state.test.ts
import { describe, it, expect } from 'vitest';
import { initADayState, applyPick } from '../../src/state.js';
import type { Member } from '../../src/index.js';
import { DEFAULT_GROUP_CAPACITY } from '../../src/groups.js';

const ff = (id: number): Member => ({
  employeeId: String(id), firstName: 'F', lastName: String(id),
  rank: 'FF', rscSeniority: id, rankSeniority: id,
  isProbationary: false, credentials: [],
});

describe('initADayState', () => {
  it('builds bidOrder from phase 1 picks (in given order)', () => {
    const state = initADayState({
      phase1Picks: [
        { memberId: 1, positionId: 'A101', shift: 'A' },
        { memberId: 2, positionId: 'B105', shift: 'B' },
      ],
      members: [ff(1), ff(2)],
      bidOrder: [1, 2],
      groupCaps: {
        A: { G1: DEFAULT_GROUP_CAPACITY, G2: DEFAULT_GROUP_CAPACITY, G3: DEFAULT_GROUP_CAPACITY, G4: DEFAULT_GROUP_CAPACITY },
        B: { G1: DEFAULT_GROUP_CAPACITY, G2: DEFAULT_GROUP_CAPACITY, G3: DEFAULT_GROUP_CAPACITY, G4: DEFAULT_GROUP_CAPACITY },
        C: { G1: DEFAULT_GROUP_CAPACITY, G2: DEFAULT_GROUP_CAPACITY, G3: DEFAULT_GROUP_CAPACITY, G4: DEFAULT_GROUP_CAPACITY },
      },
      weekdayCaps: {},
    });
    expect(state.bidOrder).toEqual([1, 2]);
    expect(state.cursor).toBe(0);
    expect(state.picksByMember.size).toBe(0);
    expect(state.phase1ByMember.get(1)?.shift).toBe('A');
  });

  it('does not include members without Phase 1 picks (vacant-or-skipped)', () => {
    const state = initADayState({
      phase1Picks: [{ memberId: 1, positionId: 'A101', shift: 'A' }],
      members: [ff(1), ff(2)],
      bidOrder: [1, 2],
      groupCaps: {
        A: { G1: DEFAULT_GROUP_CAPACITY, G2: DEFAULT_GROUP_CAPACITY, G3: DEFAULT_GROUP_CAPACITY, G4: DEFAULT_GROUP_CAPACITY },
        B: { G1: DEFAULT_GROUP_CAPACITY, G2: DEFAULT_GROUP_CAPACITY, G3: DEFAULT_GROUP_CAPACITY, G4: DEFAULT_GROUP_CAPACITY },
        C: { G1: DEFAULT_GROUP_CAPACITY, G2: DEFAULT_GROUP_CAPACITY, G3: DEFAULT_GROUP_CAPACITY, G4: DEFAULT_GROUP_CAPACITY },
      },
      weekdayCaps: {},
    });
    // bidOrder still 1,2 because the caller's order is authoritative; but phase1ByMember
    // only has the entry for member 1.
    expect(state.phase1ByMember.has(1)).toBe(true);
    expect(state.phase1ByMember.has(2)).toBe(false);
  });

  it('seeds pre-seeded picks (e.g., Union President) without advancing the cursor', () => {
    const state = initADayState({
      phase1Picks: [
        { memberId: 1, positionId: 'A101', shift: 'A' },
        { memberId: 99, positionId: 'A701', shift: 'A' },
      ],
      members: [ff(1), ff(99)],
      bidOrder: [1],
      preSeededPicks: [
        { memberId: 99, shift: 'A', aDay: 'G4', pickedAtMs: 0, forced: true, adminActorId: 1 },
      ],
      groupCaps: {
        A: { G1: DEFAULT_GROUP_CAPACITY, G2: DEFAULT_GROUP_CAPACITY, G3: DEFAULT_GROUP_CAPACITY, G4: DEFAULT_GROUP_CAPACITY },
        B: { G1: DEFAULT_GROUP_CAPACITY, G2: DEFAULT_GROUP_CAPACITY, G3: DEFAULT_GROUP_CAPACITY, G4: DEFAULT_GROUP_CAPACITY },
        C: { G1: DEFAULT_GROUP_CAPACITY, G2: DEFAULT_GROUP_CAPACITY, G3: DEFAULT_GROUP_CAPACITY, G4: DEFAULT_GROUP_CAPACITY },
      },
      weekdayCaps: {},
    });
    expect(state.picksByMember.size).toBe(1);
    expect(state.picksByMember.get(99)?.forced).toBe(true);
    expect(state.bidOrder).toEqual([1]);
    expect(state.cursor).toBe(0);
  });
});

describe('applyPick', () => {
  it('returns new state with pick recorded and cursor advanced', () => {
    const initial = initADayState({
      phase1Picks: [
        { memberId: 1, positionId: 'A101', shift: 'A' },
        { memberId: 2, positionId: 'A105', shift: 'A' },
      ],
      members: [ff(1), ff(2)],
      bidOrder: [1, 2],
      groupCaps: {
        A: { G1: DEFAULT_GROUP_CAPACITY, G2: DEFAULT_GROUP_CAPACITY, G3: DEFAULT_GROUP_CAPACITY, G4: DEFAULT_GROUP_CAPACITY },
        B: { G1: DEFAULT_GROUP_CAPACITY, G2: DEFAULT_GROUP_CAPACITY, G3: DEFAULT_GROUP_CAPACITY, G4: DEFAULT_GROUP_CAPACITY },
        C: { G1: DEFAULT_GROUP_CAPACITY, G2: DEFAULT_GROUP_CAPACITY, G3: DEFAULT_GROUP_CAPACITY, G4: DEFAULT_GROUP_CAPACITY },
      },
      weekdayCaps: {},
    });
    const next = applyPick(initial, {
      memberId: 1, shift: 'A', aDay: 'G1',
      pickedAtMs: 100, forced: false, adminActorId: null,
    });
    expect(next.cursor).toBe(1);
    expect(next.picksByMember.get(1)?.aDay).toBe('G1');
    // Immutability: original is unchanged
    expect(initial.cursor).toBe(0);
    expect(initial.picksByMember.size).toBe(0);
  });

  it('skips cursor past pre-seeded members already picked', () => {
    const initial = initADayState({
      phase1Picks: [
        { memberId: 1, positionId: 'A101', shift: 'A' },
        { memberId: 99, positionId: 'A701', shift: 'A' },
        { memberId: 2, positionId: 'A105', shift: 'A' },
      ],
      members: [ff(1), ff(99), ff(2)],
      bidOrder: [1, 99, 2],
      preSeededPicks: [
        { memberId: 99, shift: 'A', aDay: 'G4', pickedAtMs: 0, forced: true, adminActorId: 1 },
      ],
      groupCaps: {
        A: { G1: DEFAULT_GROUP_CAPACITY, G2: DEFAULT_GROUP_CAPACITY, G3: DEFAULT_GROUP_CAPACITY, G4: DEFAULT_GROUP_CAPACITY },
        B: { G1: DEFAULT_GROUP_CAPACITY, G2: DEFAULT_GROUP_CAPACITY, G3: DEFAULT_GROUP_CAPACITY, G4: DEFAULT_GROUP_CAPACITY },
        C: { G1: DEFAULT_GROUP_CAPACITY, G2: DEFAULT_GROUP_CAPACITY, G3: DEFAULT_GROUP_CAPACITY, G4: DEFAULT_GROUP_CAPACITY },
      },
      weekdayCaps: {},
    });
    const next = applyPick(initial, {
      memberId: 1, shift: 'A', aDay: 'G1',
      pickedAtMs: 100, forced: false, adminActorId: null,
    });
    // After member 1 picks, cursor should jump from 0 to 2 (skipping member 99 who is pre-seeded).
    expect(next.cursor).toBe(2);
  });
});
```

- [ ] **Step 2: Run test, expect FAIL**

- [ ] **Step 3: Implement `packages/a-day/src/state.ts`**

```ts
// packages/a-day/src/state.ts
import type {
  ADayState,
  ADayPick,
  Shift,
  GroupCapacityConfig,
  WeekdayCapacityConfig,
} from './types.js';
import type { Member } from '@mbfd/eligibility';

export interface InitADayStateInput {
  /**
   * One entry per non-vacant Phase 1 pick. Vacant positions (e.g., A215) are
   * simply absent.
   */
  phase1Picks: ReadonlyArray<{ memberId: number; positionId: string; shift: Shift }>;
  /** Full member roster for the session. */
  members: readonly Member[];
  /**
   * Deterministic Phase-2 bid order (member ids). Computed by the caller via
   * `phase2BidOrder()` from order.ts.
   */
  bidOrder: readonly number[];
  /**
   * Optional pre-seeded picks (e.g., Union President assigned pre-bid).
   * These appear in picksByMember from the start; the cursor will skip
   * over them on advance.
   */
  preSeededPicks?: readonly ADayPick[];
  groupCaps: Readonly<Record<Exclude<Shift, 'D'>, Readonly<Record<'G1' | 'G2' | 'G3' | 'G4', GroupCapacityConfig>>>>;
  weekdayCaps: Readonly<Partial<Record<'MON' | 'TUE' | 'WED' | 'THU' | 'FRI' | 'SAT' | 'SUN', WeekdayCapacityConfig>>>;
}

/**
 * Builds the initial Phase-2 ADayState. Pure: no I/O, no clock.
 */
export function initADayState(input: InitADayStateInput): ADayState {
  const membersById = new Map<number, Member>(
    input.members.map((m) => [Number(m.employeeId), m]),
  );
  const phase1ByMember = new Map<number, { positionId: string; shift: Shift }>(
    input.phase1Picks.map((p) => [p.memberId, { positionId: p.positionId, shift: p.shift }]),
  );
  const picksByMember = new Map<number, ADayPick>();
  for (const pre of input.preSeededPicks ?? []) {
    picksByMember.set(pre.memberId, pre);
  }
  // Skip cursor past any pre-seeded members at the head of the bidOrder.
  let cursor = 0;
  while (cursor < input.bidOrder.length && picksByMember.has(input.bidOrder[cursor] as number)) {
    cursor++;
  }
  return {
    groupCaps: input.groupCaps,
    weekdayCaps: input.weekdayCaps,
    picksByMember,
    bidOrder: input.bidOrder,
    cursor,
    phase1ByMember,
    membersById,
  };
}

/**
 * Returns a new state with the pick recorded and the cursor advanced past any
 * already-picked members (handles pre-seeded entries interleaved in bidOrder).
 *
 * Does NOT validate — call canPick() first. applyPick is a state transition,
 * not a guarded one.
 */
export function applyPick(state: ADayState, pick: ADayPick): ADayState {
  const picksByMember = new Map(state.picksByMember);
  picksByMember.set(pick.memberId, pick);

  let cursor = state.cursor;
  // Advance past the just-picked member, then skip any already-picked entries.
  while (cursor < state.bidOrder.length) {
    const id = state.bidOrder[cursor];
    if (id === undefined) break;
    if (picksByMember.has(id)) {
      cursor++;
    } else {
      break;
    }
  }
  return {
    ...state,
    picksByMember,
    cursor,
  };
}

/**
 * Returns the next member id to bid, or undefined if Phase 2 is complete.
 * Convenience function for the DO's turn-management code.
 */
export function nextBidder(state: ADayState): number | undefined {
  if (state.cursor >= state.bidOrder.length) return undefined;
  return state.bidOrder[state.cursor];
}

/**
 * Returns true if every member in bidOrder has a pick recorded.
 */
export function isPhase2Complete(state: ADayState): boolean {
  return state.cursor >= state.bidOrder.length;
}
```

- [ ] **Step 4: Export from `index.ts`**

```ts
// append to packages/a-day/src/index.ts
export { initADayState, applyPick, nextBidder, isPhase2Complete } from './state.js';
export type { InitADayStateInput } from './state.js';
```

- [ ] **Step 5: Run test, expect PASS**

- [ ] **Step 6: Commit**

```bash
git add packages/a-day/src/state.ts packages/a-day/src/index.ts packages/a-day/tests/unit/state.test.ts
git commit -m "feat(a-day): initADayState + applyPick (pure state transitions)"
```

---

## Task 8: Phase-2 bid order generator

**Files:**
- Create: `packages/a-day/src/order.ts`
- Test: `packages/a-day/tests/unit/order.test.ts`

Two strategies, selected by `config_json.a_day_bid_order`:
- `phase_1_order`: reuse Phase 1's `bid_order` rows in the same ordinal sequence (default).
- `by_shift_then_seniority`: group by shift in fixed order A, B, C, D then sort each shift by `rsc_seniority` ascending.

Both strategies exclude members whose Phase 1 result is "no pick" (skipped / vacant). Both exclude pre-seeded members (Union President etc.) so the cursor never lands on them.

- [ ] **Step 1: Write failing test**

```ts
// packages/a-day/tests/unit/order.test.ts
import { describe, it, expect } from 'vitest';
import { phase2BidOrder } from '../../src/order.js';
import type { Member } from '../../src/index.js';

const m = (id: number, rsc: number): Member => ({
  employeeId: String(id), firstName: 'M', lastName: String(id),
  rank: 'FF', rscSeniority: rsc, rankSeniority: rsc,
  isProbationary: false, credentials: [],
});

describe('phase2BidOrder — strategy phase_1_order', () => {
  it('returns the phase 1 bid order, filtering out members without phase 1 picks', () => {
    const order = phase2BidOrder({
      strategy: 'phase_1_order',
      phase1Order: [10, 20, 30],
      phase1Picks: [
        { memberId: 10, shift: 'A', positionId: 'A101' },
        { memberId: 30, shift: 'B', positionId: 'B105' },
      ],
      members: [m(10, 1), m(20, 2), m(30, 3)],
      preSeededMemberIds: [],
    });
    expect(order).toEqual([10, 30]);
  });

  it('excludes pre-seeded member ids', () => {
    const order = phase2BidOrder({
      strategy: 'phase_1_order',
      phase1Order: [10, 20, 30],
      phase1Picks: [
        { memberId: 10, shift: 'A', positionId: 'A101' },
        { memberId: 20, shift: 'A', positionId: 'A701' },
        { memberId: 30, shift: 'B', positionId: 'B105' },
      ],
      members: [m(10, 1), m(20, 2), m(30, 3)],
      preSeededMemberIds: [20],
    });
    expect(order).toEqual([10, 30]);
  });
});

describe('phase2BidOrder — strategy by_shift_then_seniority', () => {
  it('groups by shift in order A, B, C, D and sorts each by rsc_seniority ascending', () => {
    const order = phase2BidOrder({
      strategy: 'by_shift_then_seniority',
      phase1Order: [50, 10, 30, 20, 40],
      phase1Picks: [
        { memberId: 10, shift: 'C', positionId: 'C101' },
        { memberId: 20, shift: 'A', positionId: 'A105' },
        { memberId: 30, shift: 'B', positionId: 'B105' },
        { memberId: 40, shift: 'D', positionId: 'D101' },
        { memberId: 50, shift: 'A', positionId: 'A101' },
      ],
      members: [m(10, 30), m(20, 10), m(30, 20), m(40, 40), m(50, 5)],
      preSeededMemberIds: [],
    });
    // A: 50 (rsc 5), 20 (rsc 10); B: 30 (rsc 20); C: 10 (rsc 30); D: 40 (rsc 40)
    expect(order).toEqual([50, 20, 30, 10, 40]);
  });

  it('ties on rsc_seniority broken by rankSeniority ascending', () => {
    const ma = (id: number, rsc: number, rank: number): Member => ({
      ...m(id, rsc), rankSeniority: rank,
    });
    const order = phase2BidOrder({
      strategy: 'by_shift_then_seniority',
      phase1Order: [10, 20],
      phase1Picks: [
        { memberId: 10, shift: 'A', positionId: 'A101' },
        { memberId: 20, shift: 'A', positionId: 'A105' },
      ],
      members: [ma(10, 5, 10), ma(20, 5, 3)],
      preSeededMemberIds: [],
    });
    expect(order).toEqual([20, 10]);
  });
});
```

- [ ] **Step 2: Run test, expect FAIL**

- [ ] **Step 3: Implement `packages/a-day/src/order.ts`**

```ts
// packages/a-day/src/order.ts
import type { Phase2BidOrderStrategy, Shift } from './types.js';
import type { Member } from '@mbfd/eligibility';

export interface Phase2BidOrderInput {
  strategy: Phase2BidOrderStrategy;
  /** Phase 1's bid_order, in ordinal sequence. */
  phase1Order: readonly number[];
  /** Every non-vacant Phase 1 pick. */
  phase1Picks: ReadonlyArray<{ memberId: number; shift: Shift; positionId: string }>;
  /** Full member roster (for rsc_seniority / rank_seniority lookup). */
  members: readonly Member[];
  /** Members whose A-Day is pre-seeded out-of-band (Union President, etc.). */
  preSeededMemberIds: readonly number[];
}

const SHIFT_RANK: Record<Shift, number> = { A: 0, B: 1, C: 2, D: 3 };

/**
 * Computes the Phase-2 bid order.
 *
 * Members included:
 *   - Have a Phase 1 pick recorded (vacancies/skipped members are excluded).
 *   - Are NOT in the pre-seeded list (UP and similar).
 *
 * Ordering:
 *   - phase_1_order: preserve the Phase 1 ordinal sequence (default).
 *   - by_shift_then_seniority: group by shift A/B/C/D, sort each by rsc_seniority
 *     then rank_seniority ascending.
 *
 * Pure function; deterministic given identical inputs.
 */
export function phase2BidOrder(input: Phase2BidOrderInput): number[] {
  const preSeeded = new Set(input.preSeededMemberIds);
  const phase1ByMember = new Map(
    input.phase1Picks.map((p) => [p.memberId, p]),
  );
  const memberById = new Map(input.members.map((m) => [Number(m.employeeId), m]));

  const eligibleIds = input.phase1Order.filter(
    (id) => phase1ByMember.has(id) && !preSeeded.has(id),
  );

  if (input.strategy === 'phase_1_order') {
    return eligibleIds;
  }

  // by_shift_then_seniority
  const withMeta = eligibleIds
    .map((id) => {
      const phase1 = phase1ByMember.get(id);
      const member = memberById.get(id);
      if (!phase1 || !member) return undefined;
      return {
        id,
        shift: phase1.shift,
        rsc: member.rscSeniority,
        rank: member.rankSeniority ?? Number.MAX_SAFE_INTEGER,
      };
    })
    .filter((x): x is { id: number; shift: Shift; rsc: number; rank: number } => x !== undefined);

  withMeta.sort((a, b) => {
    if (SHIFT_RANK[a.shift] !== SHIFT_RANK[b.shift]) {
      return SHIFT_RANK[a.shift] - SHIFT_RANK[b.shift];
    }
    if (a.rsc !== b.rsc) return a.rsc - b.rsc;
    return a.rank - b.rank;
  });
  return withMeta.map((x) => x.id);
}
```

- [ ] **Step 4: Export from `index.ts`**

```ts
// append to packages/a-day/src/index.ts
export { phase2BidOrder } from './order.js';
export type { Phase2BidOrderInput } from './order.js';
```

- [ ] **Step 5: Run test, expect PASS**

- [ ] **Step 6: Commit**

```bash
git add packages/a-day/src/order.ts packages/a-day/src/index.ts packages/a-day/tests/unit/order.test.ts
git commit -m "feat(a-day): phase2BidOrder generator (two strategies)"
```

---

## Task 9: Schema migration — `a_day_picks` table

**Files:**
- Create: `apps/worker/migrations/0006_a_day_picks.sql`
- Modify: `apps/worker/src/db/schema.ts` (Drizzle table)
- Test: `apps/worker/tests/integration/a-day-schema.test.ts`

The `a_day_picks` table is a per-session, per-member A-Day record. It mirrors the Phase 1 `bids` table shape so Plan 08's exporter can union them. The `bids.a_day` column from migration 0004 is REPURPOSED as a lookup field but writes go through `a_day_picks` for clear audit separation.

> **Decision recap:** `bid_sessions.current_phase` is a TEXT column with no SQLite CHECK constraint (per migration 0004), so the new `a_day_bid` enum value requires NO DDL change. We only need to add the new table.

- [ ] **Step 1: Write failing test**

```ts
// apps/worker/tests/integration/a-day-schema.test.ts
import { describe, it, expect, beforeAll } from 'vitest';
import { drizzle } from 'drizzle-orm/d1';
import { createTestEnv } from '../helpers/miniflare.js';
import { aDayPicks } from '../../src/db/schema.js';

let env: Awaited<ReturnType<typeof createTestEnv>>;
beforeAll(async () => {
  env = await createTestEnv();
});

describe('a_day_picks schema', () => {
  it('table exists with expected columns', async () => {
    const db = drizzle(env.DB);
    const rows = await db.run(
      // SQLite catalog query
      'PRAGMA table_info(a_day_picks)' as never,
    );
    const cols = rows.results.map((r: { name: string }) => r.name);
    expect(cols).toContain('id');
    expect(cols).toContain('bid_session_id');
    expect(cols).toContain('member_id');
    expect(cols).toContain('shift');
    expect(cols).toContain('a_day');
    expect(cols).toContain('picked_at');
    expect(cols).toContain('forced');
    expect(cols).toContain('admin_actor_id');
    expect(cols).toContain('reason');
    expect(cols).toContain('idempotency_key');
  });

  it('unique constraint on (bid_session_id, member_id)', async () => {
    const db = drizzle(env.DB);
    const sessionId = 'sess-test-1';
    await db.insert(aDayPicks).values({
      id: 'rd-1', bidSessionId: sessionId, memberId: 1,
      shift: 'A', aDay: 'G1', pickedAtMs: 1, forced: false,
      adminActorId: null, reason: null, idempotencyKey: 'k1',
    });
    await expect(
      db.insert(aDayPicks).values({
        id: 'rd-2', bidSessionId: sessionId, memberId: 1,
        shift: 'A', aDay: 'G2', pickedAtMs: 2, forced: false,
        adminActorId: null, reason: null, idempotencyKey: 'k2',
      }),
    ).rejects.toThrow();
  });

  it('unique constraint on idempotency_key', async () => {
    const db = drizzle(env.DB);
    await db.insert(aDayPicks).values({
      id: 'rd-3', bidSessionId: 'sess-other', memberId: 2,
      shift: 'B', aDay: 'G1', pickedAtMs: 1, forced: false,
      adminActorId: null, reason: null, idempotencyKey: 'idem-dup',
    });
    await expect(
      db.insert(aDayPicks).values({
        id: 'rd-4', bidSessionId: 'sess-other', memberId: 3,
        shift: 'B', aDay: 'G2', pickedAtMs: 2, forced: false,
        adminActorId: null, reason: null, idempotencyKey: 'idem-dup',
      }),
    ).rejects.toThrow();
  });

  it('current_phase accepts a_day_bid value', async () => {
    const db = drizzle(env.DB);
    // bid_sessions.current_phase is unconstrained TEXT — just inserting the value
    // must succeed. This documents the contract.
    await db.run(`UPDATE bid_sessions SET current_phase = 'a_day_bid' WHERE id = 'sess-test-1'` as never);
    const after = await db.all(`SELECT current_phase FROM bid_sessions WHERE id = 'sess-test-1'` as never);
    expect((after.results[0] as { current_phase: string }).current_phase).toBe('a_day_bid');
  });
});
```

- [ ] **Step 2: Run test, expect FAIL** (table does not exist yet).

- [ ] **Step 3: Create `apps/worker/migrations/0006_a_day_picks.sql`**

```sql
CREATE TABLE `a_day_picks` (
    `id` text PRIMARY KEY NOT NULL,
    `bid_session_id` text NOT NULL,
    `member_id` integer NOT NULL,
    `shift` text NOT NULL,
    `a_day` text NOT NULL,
    `picked_at` integer NOT NULL,
    `forced` integer DEFAULT 0 NOT NULL,
    `admin_actor_id` integer,
    `reason` text,
    `idempotency_key` text NOT NULL,
    FOREIGN KEY (`bid_session_id`) REFERENCES `bid_sessions`(`id`) ON UPDATE no action ON DELETE cascade,
    FOREIGN KEY (`member_id`) REFERENCES `members`(`id`) ON UPDATE no action ON DELETE restrict,
    FOREIGN KEY (`admin_actor_id`) REFERENCES `members`(`id`) ON UPDATE no action ON DELETE restrict
);
--> statement-breakpoint
CREATE UNIQUE INDEX `a_day_picks_session_member_unique` ON `a_day_picks` (`bid_session_id`, `member_id`);
--> statement-breakpoint
CREATE UNIQUE INDEX `a_day_picks_idempotency_key_unique` ON `a_day_picks` (`idempotency_key`);
--> statement-breakpoint
CREATE INDEX `idx_a_day_picks_session_shift_aday` ON `a_day_picks` (`bid_session_id`, `shift`, `a_day`);
--> statement-breakpoint
CREATE INDEX `idx_a_day_picks_member` ON `a_day_picks` (`member_id`);
```

- [ ] **Step 4: Add Drizzle table to `apps/worker/src/db/schema.ts`**

```ts
// append to apps/worker/src/db/schema.ts
import { integer, sqliteTable, text, uniqueIndex, index } from 'drizzle-orm/sqlite-core';

export const aDayPicks = sqliteTable(
  'a_day_picks',
  {
    id: text('id').primaryKey().notNull(),
    bidSessionId: text('bid_session_id').notNull().references(() => bidSessions.id, { onDelete: 'cascade' }),
    memberId: integer('member_id').notNull().references(() => members.id, { onDelete: 'restrict' }),
    shift: text('shift').notNull(),     // 'A' | 'B' | 'C' | 'D'
    aDay: text('a_day').notNull(),      // 'G1'..'G4' | 'MON'..'SUN'
    pickedAtMs: integer('picked_at').notNull(),
    forced: integer('forced', { mode: 'boolean' }).default(false).notNull(),
    adminActorId: integer('admin_actor_id').references(() => members.id, { onDelete: 'restrict' }),
    reason: text('reason'),
    idempotencyKey: text('idempotency_key').notNull(),
  },
  (table) => ({
    sessionMemberUnique: uniqueIndex('a_day_picks_session_member_unique').on(table.bidSessionId, table.memberId),
    idempotencyUnique: uniqueIndex('a_day_picks_idempotency_key_unique').on(table.idempotencyKey),
    sessionShiftAday: index('idx_a_day_picks_session_shift_aday').on(table.bidSessionId, table.shift, table.aDay),
    memberIdx: index('idx_a_day_picks_member').on(table.memberId),
  }),
);
```

- [ ] **Step 5: Apply migration to test DB**

```bash
pnpm --filter @mbfd-bid/worker wrangler d1 migrations apply mbfd-bid --local
```

- [ ] **Step 6: Run test, expect PASS**

- [ ] **Step 7: Commit**

```bash
git add apps/worker/migrations/0006_a_day_picks.sql apps/worker/src/db/schema.ts apps/worker/tests/integration/a-day-schema.test.ts
git commit -m "feat(db): add a_day_picks table + Drizzle definition"
```

---

## Task 10: Shared Zod schemas for A-Day

**Files:**
- Create: `packages/shared/src/schemas/a-day.ts`
- Modify: `packages/shared/src/index.ts` (export new schemas)
- Test: `packages/shared/tests/unit/a-day-schemas.test.ts`

Shared Zod schemas are consumed by both `apps/worker` (request validation) and `apps/web` (typed WS client). Reusing one source-of-truth eliminates client/server drift.

- [ ] **Step 1: Write failing test**

```ts
// packages/shared/tests/unit/a-day-schemas.test.ts
import { describe, it, expect } from 'vitest';
import {
  ADayGroupIdSchema,
  WeekdaySchema,
  ADayValueSchema,
  ShiftSchema,
  SubmitADayPickRequestSchema,
  ADayPickMadeMessageSchema,
  PhaseChangedMessageSchema,
  ADayRejectMessageSchema,
} from '../../src/schemas/a-day.js';

describe('ADayGroupIdSchema', () => {
  it('accepts G1-G4', () => {
    for (const v of ['G1', 'G2', 'G3', 'G4']) {
      expect(ADayGroupIdSchema.safeParse(v).success).toBe(true);
    }
  });
  it('rejects G5', () => {
    expect(ADayGroupIdSchema.safeParse('G5').success).toBe(false);
  });
});

describe('WeekdaySchema', () => {
  it('accepts MON-SUN', () => {
    for (const v of ['MON', 'TUE', 'WED', 'THU', 'FRI', 'SAT', 'SUN']) {
      expect(WeekdaySchema.safeParse(v).success).toBe(true);
    }
  });
});

describe('SubmitADayPickRequestSchema', () => {
  it('accepts a valid request', () => {
    const r = SubmitADayPickRequestSchema.safeParse({
      v: 1,
      bidSessionId: 'sess-2026-01',
      aDay: 'G2',
      idempotencyKey: '550e8400-e29b-41d4-a716-446655440000',
    });
    expect(r.success).toBe(true);
  });

  it('rejects unknown aDay value', () => {
    const r = SubmitADayPickRequestSchema.safeParse({
      v: 1, bidSessionId: 'x', aDay: 'XYZ', idempotencyKey: 'k',
    });
    expect(r.success).toBe(false);
  });

  it('rejects when v != 1', () => {
    const r = SubmitADayPickRequestSchema.safeParse({
      v: 2, bidSessionId: 'x', aDay: 'G1', idempotencyKey: 'k',
    });
    expect(r.success).toBe(false);
  });
});

describe('ADayPickMadeMessageSchema', () => {
  it('accepts a server-broadcast pick-made message', () => {
    const r = ADayPickMadeMessageSchema.safeParse({
      type: 'a_day_pick_made',
      v: 1,
      seq: 41,
      memberId: 123,
      shift: 'A',
      aDay: 'G2',
      pickedAtMs: 1717000000000,
      forced: false,
      adminActorId: null,
      nextMemberId: 124,
      meters: {
        groups: [],
        weekdays: [],
      },
    });
    expect(r.success).toBe(true);
  });
});

describe('PhaseChangedMessageSchema', () => {
  it('accepts position_bid → a_day_bid', () => {
    const r = PhaseChangedMessageSchema.safeParse({
      type: 'phase_changed',
      v: 1,
      from: 'position_bid',
      to: 'a_day_bid',
      bidOrderPhase2: [1, 2, 3],
    });
    expect(r.success).toBe(true);
  });

  it('accepts a_day_bid → complete', () => {
    const r = PhaseChangedMessageSchema.safeParse({
      type: 'phase_changed', v: 1, from: 'a_day_bid', to: 'complete',
    });
    expect(r.success).toBe(true);
  });
});

describe('ADayRejectMessageSchema', () => {
  it('accepts a structured reject', () => {
    const r = ADayRejectMessageSchema.safeParse({
      type: 'a_day_reject', v: 1, memberId: 5, reasonCode: 'GROUP_FULL',
      reasonLabel: 'Group G1 on A-shift is full (19/19).',
    });
    expect(r.success).toBe(true);
  });
});
```

- [ ] **Step 2: Run test, expect FAIL**

- [ ] **Step 3: Implement `packages/shared/src/schemas/a-day.ts`**

```ts
// packages/shared/src/schemas/a-day.ts
import { z } from 'zod';

export const ADayGroupIdSchema = z.enum(['G1', 'G2', 'G3', 'G4']);
export type ADayGroupId = z.infer<typeof ADayGroupIdSchema>;

export const WeekdaySchema = z.enum(['MON', 'TUE', 'WED', 'THU', 'FRI', 'SAT', 'SUN']);
export type Weekday = z.infer<typeof WeekdaySchema>;

export const ADayValueSchema = z.union([ADayGroupIdSchema, WeekdaySchema]);
export type ADayValue = z.infer<typeof ADayValueSchema>;

export const ShiftSchema = z.enum(['A', 'B', 'C', 'D']);
export type Shift = z.infer<typeof ShiftSchema>;

/** Reason codes shared with @mbfd/a-day's PickValidation rejection branch. */
export const ADayRejectReasonCodeSchema = z.enum([
  'NOT_YOUR_TURN',
  'PHASE_NOT_R_DAY_BID',
  'NO_PHASE_1_PICK',
  'ALREADY_PICKED',
  'GROUP_FULL',
  'WEEKDAY_FULL',
  'OFFICER_INVARIANT_VIOLATED',
  'INVALID_R_DAY_FOR_SHIFT',
  'UNKNOWN_MEMBER',
]);

/** Capacity meter payload shared in WS messages. */
export const CapacityMeterPayloadSchema = z.object({
  total: z.number().int().nonnegative(),
  max: z.number().int().nonnegative().optional(),
  officers: z.number().int().nonnegative(),
  officersRequired: z.number().int().nonnegative().optional(),
  isFull: z.boolean(),
});

/** Snapshot of all capacity meters, used by board UI and AI advisory. */
export const MetersBundleSchema = z.object({
  groups: z.array(
    z.object({
      shift: z.enum(['A', 'B', 'C']),
      group: ADayGroupIdSchema,
      meter: CapacityMeterPayloadSchema,
    }),
  ),
  weekdays: z.array(
    z.object({
      weekday: WeekdaySchema,
      meter: CapacityMeterPayloadSchema,
    }),
  ),
});

/** CLIENT → SERVER: submit an A-Day pick (WS or REST body). */
export const SubmitADayPickRequestSchema = z.object({
  v: z.literal(1),
  bidSessionId: z.string().min(1),
  aDay: ADayValueSchema,
  idempotencyKey: z.string().uuid(),
});
export type SubmitADayPickRequest = z.infer<typeof SubmitADayPickRequestSchema>;

/** SERVER → CLIENT: pick made, broadcast to all. */
export const ADayPickMadeMessageSchema = z.object({
  type: z.literal('a_day_pick_made'),
  v: z.literal(1),
  seq: z.number().int().nonnegative(),
  memberId: z.number().int().positive(),
  shift: ShiftSchema,
  aDay: ADayValueSchema,
  pickedAtMs: z.number().int().nonnegative(),
  forced: z.boolean(),
  adminActorId: z.number().int().nullable(),
  /** Next member id whose turn starts, or null if Phase 2 is complete. */
  nextMemberId: z.number().int().positive().nullable(),
  meters: MetersBundleSchema,
});

/** SERVER → CLIENT: phase transition. */
export const PhaseChangedMessageSchema = z.object({
  type: z.literal('phase_changed'),
  v: z.literal(1),
  from: z.enum(['config', 'position_bid', 'a_day_bid', 'paused']),
  to: z.enum(['position_bid', 'a_day_bid', 'paused', 'complete']),
  /** Populated only when to === 'a_day_bid'. */
  bidOrderPhase2: z.array(z.number().int().positive()).optional(),
});

/** SERVER → CLIENT: pick rejected. Sent only to the submitter's connection. */
export const ADayRejectMessageSchema = z.object({
  type: z.literal('a_day_reject'),
  v: z.literal(1),
  memberId: z.number().int().positive(),
  reasonCode: ADayRejectReasonCodeSchema,
  reasonLabel: z.string(),
  detail: z.record(z.string(), z.union([z.string(), z.number(), z.boolean()])).optional(),
});

/** Discriminated union of all Phase-2 server-to-client messages. */
export const ADayServerMessageSchema = z.discriminatedUnion('type', [
  ADayPickMadeMessageSchema,
  PhaseChangedMessageSchema,
  ADayRejectMessageSchema,
]);
export type ADayServerMessage = z.infer<typeof ADayServerMessageSchema>;
```

- [ ] **Step 4: Export from `packages/shared/src/index.ts`**

```ts
// append to packages/shared/src/index.ts
export {
  ADayGroupIdSchema,
  WeekdaySchema,
  ADayValueSchema,
  ShiftSchema,
  ADayRejectReasonCodeSchema,
  CapacityMeterPayloadSchema,
  MetersBundleSchema,
  SubmitADayPickRequestSchema,
  ADayPickMadeMessageSchema,
  PhaseChangedMessageSchema,
  ADayRejectMessageSchema,
  ADayServerMessageSchema,
} from './schemas/a-day.js';
export type {
  ADayGroupId as ADayGroupIdType,
  Weekday as WeekdayType,
  ADayValue as ADayValueType,
  Shift as ShiftType,
  SubmitADayPickRequest,
  ADayServerMessage,
} from './schemas/a-day.js';
```

- [ ] **Step 5: Run test, expect PASS**

- [ ] **Step 6: Commit**

```bash
git add packages/shared/src/schemas/a-day.ts packages/shared/src/index.ts packages/shared/tests/unit/a-day-schemas.test.ts
git commit -m "feat(shared): Zod schemas for A-Day requests and WS messages"
```

---

## Task 11: Extend Plan 04 DO — phase transition + submitADayPick handler

**Files:**
- Modify: `apps/worker/src/durable/bid-session.ts` (UPDATE — add phase transition, handler, integrate `@mbfd/a-day`)
- Modify: `apps/worker/src/durable/bid-session-protocol.ts` (UPDATE — new message types)
- Modify: `apps/worker/src/durable/bid-session-storage.ts` (UPDATE — persist `a_day_state`)
- Test: `apps/worker/tests/integration/a-day-transition.test.ts`
- Test: `apps/worker/tests/integration/a-day-capacity.test.ts`

> **Codebase context:** Plan 04 owns the DO scaffold. This task ADDS to it. Search the file for the phase 1 last-pick handler (`async function handlePositionPick(...)`) — its `if (allPositionsFilled) { … }` block is the natural place to insert `await this.transitionToPhase2()`.

### Sub-task 11a: WS protocol extension

- [ ] **Step 1: Edit `apps/worker/src/durable/bid-session-protocol.ts`**

Append to the existing client→server message union:

```ts
// apps/worker/src/durable/bid-session-protocol.ts (append to existing union)
import { SubmitADayPickRequestSchema } from '@mbfd/shared';

export const ClientMessageSchema = z.discriminatedUnion('type', [
  // ... existing Plan 04 messages
  SubmitADayPickClientMessageSchema,
]);

export const SubmitADayPickClientMessageSchema = z.object({
  type: z.literal('submit_a_day_pick'),
  v: z.literal(1),
  aDay: z.union([
    z.enum(['G1', 'G2', 'G3', 'G4']),
    z.enum(['MON', 'TUE', 'WED', 'THU', 'FRI', 'SAT', 'SUN']),
  ]),
  idempotencyKey: z.string().uuid(),
});
```

Append to the server→client union the three message schemas from Task 10 (`ADayPickMadeMessageSchema`, `PhaseChangedMessageSchema`, `ADayRejectMessageSchema`).

### Sub-task 11b: Storage layer extension

- [ ] **Step 1: Add A-Day state persistence helpers**

```ts
// apps/worker/src/durable/bid-session-storage.ts (append)
import type { ADayState, ADayPick } from '@mbfd/a-day';

/** Persisted snapshot shape — Map types serialized to arrays. */
interface PersistedADayState {
  groupCaps: ADayState['groupCaps'];
  weekdayCaps: ADayState['weekdayCaps'];
  picks: ADayPick[];                                   // serialized picksByMember
  bidOrder: number[];
  cursor: number;
  phase1: Array<[number, { positionId: string; shift: 'A' | 'B' | 'C' | 'D' }]>;
}

const RDAY_STATE_KEY = 'a_day_state.v1';

export async function persistADayState(
  storage: DurableObjectStorage,
  state: ADayState,
): Promise<void> {
  const snap: PersistedADayState = {
    groupCaps: state.groupCaps,
    weekdayCaps: state.weekdayCaps,
    picks: [...state.picksByMember.values()],
    bidOrder: [...state.bidOrder],
    cursor: state.cursor,
    phase1: [...state.phase1ByMember.entries()],
  };
  await storage.put(RDAY_STATE_KEY, snap);
}

export async function loadADayState(
  storage: DurableObjectStorage,
  membersById: ReadonlyMap<number, import('@mbfd/eligibility').Member>,
): Promise<ADayState | null> {
  const snap = await storage.get<PersistedADayState>(RDAY_STATE_KEY);
  if (!snap) return null;
  return {
    groupCaps: snap.groupCaps,
    weekdayCaps: snap.weekdayCaps,
    picksByMember: new Map(snap.picks.map((p) => [p.memberId, p])),
    bidOrder: snap.bidOrder,
    cursor: snap.cursor,
    phase1ByMember: new Map(snap.phase1),
    membersById,
  };
}
```

### Sub-task 11c: Phase transition

- [ ] **Step 1: Write failing test**

```ts
// apps/worker/tests/integration/a-day-transition.test.ts
import { describe, it, expect, beforeEach } from 'vitest';
import { createTestEnv, startTestSession, fillAllPositions } from '../helpers/miniflare.js';

let env: Awaited<ReturnType<typeof createTestEnv>>;
beforeEach(async () => {
  env = await createTestEnv();
});

describe('Phase 1 → Phase 2 transition', () => {
  it('DO transitions to a_day_bid when last position is filled', async () => {
    const session = await startTestSession(env, { memberCount: 4, positionCount: 4 });
    await fillAllPositions(env, session.id, session.bidOrder);
    const state = await env.DO_BID_SESSION.get(env.DO_BID_SESSION.idFromName(session.id))
      .fetch('https://do/state')
      .then((r) => r.json() as Promise<{ current_phase: string }>);
    expect(state.current_phase).toBe('a_day_bid');
  });

  it('broadcasts phase_changed { from: position_bid, to: a_day_bid }', async () => {
    const session = await startTestSession(env, { memberCount: 4, positionCount: 4 });
    const ws = await openTestWs(env, session.id, session.bidOrder[0]);
    const messages: unknown[] = [];
    ws.addEventListener('message', (e) => messages.push(JSON.parse(e.data)));
    await fillAllPositions(env, session.id, session.bidOrder);
    await waitFor(() =>
      messages.some(
        (m) => typeof m === 'object' && m && (m as { type: string }).type === 'phase_changed'
          && (m as { to: string }).to === 'a_day_bid',
      ),
    );
  });

  it('computes Phase 2 bid order using phase_1_order strategy by default', async () => {
    const session = await startTestSession(env, {
      memberCount: 4, positionCount: 4,
      configJson: {}, // no a_day_bid_order key
    });
    await fillAllPositions(env, session.id, session.bidOrder);
    const order = await env.DO_BID_SESSION.get(env.DO_BID_SESSION.idFromName(session.id))
      .fetch('https://do/a-day-order')
      .then((r) => r.json() as Promise<{ order: number[] }>);
    expect(order.order).toEqual(session.bidOrder);
  });

  it('Phase 1 vacancies do not block transition', async () => {
    const session = await startTestSession(env, { memberCount: 3, positionCount: 4 });
    // fill only 3 positions, leave 1 vacant via admin lock
    await fillPositions(env, session.id, session.bidOrder.slice(0, 3));
    await adminLockVacant(env, session.id, 'B215');
    const state = await env.DO_BID_SESSION.get(env.DO_BID_SESSION.idFromName(session.id))
      .fetch('https://do/state')
      .then((r) => r.json() as Promise<{ current_phase: string }>);
    expect(state.current_phase).toBe('a_day_bid');
  });
});
```

(See `apps/worker/tests/helpers/miniflare.ts` from Plan 04 for `createTestEnv`, `startTestSession`, `fillAllPositions`, `openTestWs`, `waitFor`. Add `fillPositions` and `adminLockVacant` helpers in this task if absent.)

- [ ] **Step 2: Run test, expect FAIL** (transition not yet implemented)

- [ ] **Step 3: Implement the transition in `apps/worker/src/durable/bid-session.ts`**

Add inside the existing `BidSession` class:

```ts
// apps/worker/src/durable/bid-session.ts (additions to existing class)
import {
  initADayState,
  applyPick,
  canPick,
  nextBidder,
  isPhase2Complete,
  computeAllMeters,
  phase2BidOrder,
  DEFAULT_GROUP_CAPACITY,
  type ADayState,
  type Phase2BidOrderStrategy,
} from '@mbfd/a-day';
import { persistADayState, loadADayState } from './bid-session-storage.js';

export class BidSession {
  // ... existing fields ...
  private aDayState: ADayState | null = null;

  /**
   * Called by handlePositionPick after the LAST Phase 1 pick (or after admin
   * sets a vacancy and the predicate "all open positions filled or locked"
   * becomes true).
   */
  private async transitionToPhase2(): Promise<void> {
    if (this.currentPhase !== 'position_bid') return;
    const config = this.session.configJson ?? {};
    const strategy: Phase2BidOrderStrategy =
      config.a_day_bid_order === 'by_shift_then_seniority'
        ? 'by_shift_then_seniority'
        : 'phase_1_order';

    const phase1Picks = [...this.positionFillsMap.entries()]
      .filter((entry): entry is [string, number] => entry[1] !== null)
      .map(([positionId, memberId]) => ({
        memberId,
        positionId,
        shift: this.positionShiftMap.get(positionId)!,
      }));

    const order = phase2BidOrder({
      strategy,
      phase1Order: this.phase1BidOrder,
      phase1Picks,
      members: [...this.membersById.values()],
      preSeededMemberIds: this.preSeededADayMemberIds,
    });

    const groupCaps = (config.a_day_group_capacities as ADayState['groupCaps'] | undefined) ?? {
      A: { G1: DEFAULT_GROUP_CAPACITY, G2: DEFAULT_GROUP_CAPACITY, G3: DEFAULT_GROUP_CAPACITY, G4: DEFAULT_GROUP_CAPACITY },
      B: { G1: DEFAULT_GROUP_CAPACITY, G2: DEFAULT_GROUP_CAPACITY, G3: DEFAULT_GROUP_CAPACITY, G4: DEFAULT_GROUP_CAPACITY },
      C: { G1: DEFAULT_GROUP_CAPACITY, G2: DEFAULT_GROUP_CAPACITY, G3: DEFAULT_GROUP_CAPACITY, G4: DEFAULT_GROUP_CAPACITY },
    };
    const weekdayCaps = (config.d_shift_weekday_caps as ADayState['weekdayCaps'] | undefined) ?? {};

    this.aDayState = initADayState({
      phase1Picks, members: [...this.membersById.values()],
      bidOrder: order, preSeededPicks: this.preSeededADayPicks,
      groupCaps, weekdayCaps,
    });

    // Persist BEFORE broadcast (spec §5.3)
    this.currentPhase = 'a_day_bid';
    this.currentBidderId = nextBidder(this.aDayState) ?? null;
    await this.state.storage.put({
      current_phase: this.currentPhase,
      current_bidder_id: this.currentBidderId,
    });
    await persistADayState(this.state.storage, this.aDayState);

    const seq = this.nextSeq();
    this.broadcast({
      type: 'phase_changed',
      v: 1,
      from: 'position_bid',
      to: 'a_day_bid',
      bidOrderPhase2: [...order],
    });
    if (this.currentBidderId !== null) {
      this.broadcast({
        type: 'turn_started',
        v: 1,
        memberId: this.currentBidderId,
        endsAtMs: Date.now() + this.session.turnTimerSeconds * 1000,
      });
    }
    await this.appendAudit({
      seq, actorType: 'system', actorId: null, action: 'session_phase_changed',
      targetKind: 'phase', targetId: 'a_day_bid', beforeState: null,
      afterState: { current_phase: 'a_day_bid' }, reason: null,
    });
  }
}
```

- [ ] **Step 4: Re-run test, expect PASS**

- [ ] **Step 5: Commit**

```bash
git add apps/worker/src/durable/ apps/worker/tests/integration/a-day-transition.test.ts
git commit -m "feat(do): transition position_bid → a_day_bid (initADayState + broadcast)"
```

---

## Task 12: DO `submitADayPick` handler

**Files:**
- Modify: `apps/worker/src/durable/bid-session.ts` (UPDATE — add handler)
- Test: `apps/worker/tests/integration/a-day-capacity.test.ts`
- Test: `apps/worker/tests/integration/a-day-officer-invariant.test.ts`
- Test: `apps/worker/tests/integration/a-day-idempotency.test.ts`
- Test: `apps/worker/tests/integration/a-day-d-shift.test.ts`
- Test: `apps/worker/tests/integration/a-day-complete.test.ts`

### Sub-task 12a: capacity gate

- [ ] **Step 1: Write failing test**

```ts
// apps/worker/tests/integration/a-day-capacity.test.ts
import { describe, it, expect, beforeEach } from 'vitest';
import { createTestEnv, startTestSession, fillAllPositions, submitADayPick } from '../helpers/miniflare.js';

let env: Awaited<ReturnType<typeof createTestEnv>>;
beforeEach(async () => {
  env = await createTestEnv();
});

describe('A-Day capacity gate', () => {
  it('decrements capacity meter on each pick', async () => {
    const session = await startTestSession(env, { memberCount: 4, positionCount: 4, allShift: 'A' });
    await fillAllPositions(env, session.id, session.bidOrder);
    // first member picks G1
    const r1 = await submitADayPick(env, session.id, session.bidOrder[0], 'G1');
    expect(r1.type).toBe('a_day_pick_made');
    expect(r1.meters.groups.find((g) => g.shift === 'A' && g.group === 'G1')!.meter.total).toBe(1);
  });

  it('rejects with GROUP_FULL when group at max', async () => {
    // craft a session where G1 is already at 19/19
    const session = await startTestSession(env, { memberCount: 21, positionCount: 21, allShift: 'A', g1PreFill: 19 });
    await fillAllPositions(env, session.id, session.bidOrder);
    const member = session.bidOrder[session.g1PreFill];
    const r = await submitADayPick(env, session.id, member, 'G1');
    expect(r.type).toBe('a_day_reject');
    expect(r.reasonCode).toBe('GROUP_FULL');
  });
});
```

- [ ] **Step 2: Run test, expect FAIL**

- [ ] **Step 3: Implement handler in `BidSession`**

```ts
// apps/worker/src/durable/bid-session.ts (additions)
import { SubmitADayPickRequestSchema } from '@mbfd/shared';

private async handleSubmitADayPick(
  socket: WebSocket,
  rawMsg: unknown,
  ctx: { memberId: number; bidSessionId: string },
): Promise<void> {
  // Session-level gates
  if (this.currentPhase !== 'a_day_bid') {
    this.sendToSocket(socket, {
      type: 'a_day_reject', v: 1, memberId: ctx.memberId,
      reasonCode: 'PHASE_NOT_R_DAY_BID',
      reasonLabel: `Phase 2 is not active (current_phase=${this.currentPhase}).`,
    });
    return;
  }
  if (ctx.memberId !== this.currentBidderId) {
    this.sendToSocket(socket, {
      type: 'a_day_reject', v: 1, memberId: ctx.memberId,
      reasonCode: 'NOT_YOUR_TURN',
      reasonLabel: `It is not your turn (current bidder = ${this.currentBidderId}).`,
    });
    return;
  }
  const parsed = SubmitADayPickRequestSchema.safeParse(rawMsg);
  if (!parsed.success) {
    // Treat malformed as invalid input — emit reject.
    this.sendToSocket(socket, {
      type: 'a_day_reject', v: 1, memberId: ctx.memberId,
      reasonCode: 'INVALID_R_DAY_FOR_SHIFT',
      reasonLabel: 'Malformed A-Day pick request.',
    });
    return;
  }

  // Idempotency
  const idemKey = parsed.data.idempotencyKey;
  const existing = this.idempotencyCache.get(idemKey);
  if (existing !== undefined) {
    this.sendToSocket(socket, existing);
    return;
  }

  if (!this.aDayState) {
    this.sendToSocket(socket, {
      type: 'a_day_reject', v: 1, memberId: ctx.memberId,
      reasonCode: 'PHASE_NOT_R_DAY_BID',
      reasonLabel: 'Phase 2 state is not initialized.',
    });
    return;
  }

  const validation = canPick(this.aDayState, ctx.memberId, parsed.data.aDay);
  if (!validation.ok) {
    const rejectMsg = {
      type: 'a_day_reject' as const, v: 1 as const, memberId: ctx.memberId,
      reasonCode: validation.reasonCode, reasonLabel: validation.reasonLabel,
      detail: validation.detail,
    };
    this.idempotencyCache.set(idemKey, rejectMsg);
    this.sendToSocket(socket, rejectMsg);
    return;
  }

  // Apply pick
  const now = Date.now();
  const phase1 = this.aDayState.phase1ByMember.get(ctx.memberId)!;
  const pick = {
    memberId: ctx.memberId,
    shift: phase1.shift,
    aDay: parsed.data.aDay,
    pickedAtMs: now,
    forced: false,
    adminActorId: null,
  };
  this.aDayState = applyPick(this.aDayState, pick);

  // Persist BEFORE broadcast
  await this.state.storage.transaction(async (txn) => {
    await persistADayState(txn, this.aDayState!);
    await txn.put('current_bidder_id', nextBidder(this.aDayState!) ?? null);
  });
  // D1 insert (async, queued)
  this.ctx.waitUntil(this.insertADayPick(pick, idemKey));

  const seq = this.nextSeq();
  this.currentBidderId = nextBidder(this.aDayState) ?? null;

  const broadcast = {
    type: 'a_day_pick_made' as const, v: 1 as const, seq,
    memberId: pick.memberId, shift: pick.shift, aDay: pick.aDay,
    pickedAtMs: pick.pickedAtMs, forced: false, adminActorId: null,
    nextMemberId: this.currentBidderId,
    meters: computeAllMeters(this.aDayState),
  };
  this.idempotencyCache.set(idemKey, broadcast);
  this.broadcast(broadcast);

  // Complete?
  if (isPhase2Complete(this.aDayState)) {
    await this.transitionToComplete();
  } else if (this.currentBidderId !== null) {
    this.broadcast({
      type: 'turn_started', v: 1, memberId: this.currentBidderId,
      endsAtMs: Date.now() + this.session.turnTimerSeconds * 1000,
    });
  }
}

private async insertADayPick(
  pick: { memberId: number; shift: string; aDay: string; pickedAtMs: number; forced: boolean; adminActorId: number | null },
  idempotencyKey: string,
): Promise<void> {
  await this.db.insert(aDayPicks).values({
    id: crypto.randomUUID(),
    bidSessionId: this.session.id,
    memberId: pick.memberId,
    shift: pick.shift,
    aDay: pick.aDay,
    pickedAtMs: pick.pickedAtMs,
    forced: pick.forced,
    adminActorId: pick.adminActorId,
    reason: null,
    idempotencyKey,
  });
  await this.appendAudit({
    seq: this.nextSeq(), actorType: 'member', actorId: pick.memberId,
    action: 'a_day_pick', targetKind: 'a_day', targetId: pick.aDay,
    beforeState: null, afterState: pick, reason: null,
  });
}

private async transitionToComplete(): Promise<void> {
  this.currentPhase = 'complete';
  await this.state.storage.put({ current_phase: 'complete', completed_at: Date.now() });
  this.broadcast({
    type: 'phase_changed', v: 1,
    from: 'a_day_bid', to: 'complete',
  });
  await this.appendAudit({
    seq: this.nextSeq(), actorType: 'system', actorId: null,
    action: 'session_complete', targetKind: 'session', targetId: this.session.id,
    beforeState: null, afterState: null, reason: null,
  });
}
```

- [ ] **Step 4: Re-run capacity test, expect PASS**

### Sub-task 12b: officer invariant

- [ ] **Step 1: Write failing test**

```ts
// apps/worker/tests/integration/a-day-officer-invariant.test.ts
import { describe, it, expect, beforeEach } from 'vitest';
import { createTestEnv, startTestSession, fillAllPositions, submitADayPick } from '../helpers/miniflare.js';

let env: Awaited<ReturnType<typeof createTestEnv>>;
beforeEach(async () => { env = await createTestEnv(); });

describe('A-Day officer invariant', () => {
  it('rejects 6th officer in same group', async () => {
    const session = await startTestSession(env, {
      memberCount: 6, positionCount: 6,
      allShift: 'A', allRank: 'LT',
    });
    await fillAllPositions(env, session.id, session.bidOrder);
    for (let i = 0; i < 5; i++) {
      const r = await submitADayPick(env, session.id, session.bidOrder[i], 'G1');
      expect(r.type).toBe('a_day_pick_made');
    }
    const sixth = await submitADayPick(env, session.id, session.bidOrder[5], 'G1');
    expect(sixth.type).toBe('a_day_reject');
    expect(sixth.reasonCode).toBe('OFFICER_INVARIANT_VIOLATED');
  });

  it('rejects pick that leaves another group unfillable', async () => {
    // 4 LTs left; 3 groups already at 5; G4 at 0. Each remaining LT picks G1
    // → infeasible because G4 can no longer reach 5.
    // (helper sets up the pre-state via direct DO storage primer.)
    const session = await primeOfficerInvariantScenario(env);
    const r = await submitADayPick(env, session.id, session.bidOrder[0], 'G1');
    expect(r.type).toBe('a_day_reject');
    expect(r.reasonCode).toBe('OFFICER_INVARIANT_VIOLATED');
  });
});
```

- [ ] **Step 2: Run test, expect PASS** (handler already calls `canPick` which composes the invariant check)

### Sub-task 12c: idempotency

- [ ] **Step 1: Write failing test**

```ts
// apps/worker/tests/integration/a-day-idempotency.test.ts
import { describe, it, expect, beforeEach } from 'vitest';
import { createTestEnv, startTestSession, fillAllPositions, submitADayPick } from '../helpers/miniflare.js';

let env: Awaited<ReturnType<typeof createTestEnv>>;
beforeEach(async () => { env = await createTestEnv(); });

describe('A-Day idempotency', () => {
  it('replay with same idempotency_key returns the original response', async () => {
    const session = await startTestSession(env, { memberCount: 1, positionCount: 1, allShift: 'A' });
    await fillAllPositions(env, session.id, session.bidOrder);
    const key = crypto.randomUUID();
    const r1 = await submitADayPick(env, session.id, session.bidOrder[0], 'G1', { idempotencyKey: key });
    const r2 = await submitADayPick(env, session.id, session.bidOrder[0], 'G1', { idempotencyKey: key });
    expect(r2).toEqual(r1);
  });

  it('new idempotency_key on already-picked member returns ALREADY_PICKED', async () => {
    const session = await startTestSession(env, { memberCount: 1, positionCount: 1, allShift: 'A' });
    await fillAllPositions(env, session.id, session.bidOrder);
    await submitADayPick(env, session.id, session.bidOrder[0], 'G1');
    const r2 = await submitADayPick(env, session.id, session.bidOrder[0], 'G2');
    expect(r2.type).toBe('a_day_reject');
    expect(r2.reasonCode).toBe('ALREADY_PICKED');
  });
});
```

- [ ] **Step 2: Run test, expect PASS** (idempotencyCache + canPick gate handles both)

### Sub-task 12d: D-shift weekday picker

- [ ] **Step 1: Write failing test**

```ts
// apps/worker/tests/integration/a-day-d-shift.test.ts
import { describe, it, expect, beforeEach } from 'vitest';
import { createTestEnv, startTestSession, fillAllPositions, submitADayPick } from '../helpers/miniflare.js';

let env: Awaited<ReturnType<typeof createTestEnv>>;
beforeEach(async () => { env = await createTestEnv(); });

describe('D-shift weekday picker', () => {
  it('accepts FRI with no cap', async () => {
    const session = await startTestSession(env, { memberCount: 2, positionCount: 2, allShift: 'D' });
    await fillAllPositions(env, session.id, session.bidOrder);
    const r = await submitADayPick(env, session.id, session.bidOrder[0], 'FRI');
    expect(r.type).toBe('a_day_pick_made');
  });

  it('rejects FRI when admin cap reached', async () => {
    const session = await startTestSession(env, {
      memberCount: 3, positionCount: 3, allShift: 'D',
      configJson: { d_shift_weekday_caps: { FRI: { max: 1 } } },
    });
    await fillAllPositions(env, session.id, session.bidOrder);
    await submitADayPick(env, session.id, session.bidOrder[0], 'FRI');
    const r = await submitADayPick(env, session.id, session.bidOrder[1], 'FRI');
    expect(r.type).toBe('a_day_reject');
    expect(r.reasonCode).toBe('WEEKDAY_FULL');
  });
});
```

### Sub-task 12e: phase complete

- [ ] **Step 1: Write failing test**

```ts
// apps/worker/tests/integration/a-day-complete.test.ts
import { describe, it, expect, beforeEach } from 'vitest';
import { createTestEnv, startTestSession, fillAllPositions, submitADayPick, waitFor } from '../helpers/miniflare.js';

let env: Awaited<ReturnType<typeof createTestEnv>>;
beforeEach(async () => { env = await createTestEnv(); });

describe('Phase 2 completion', () => {
  it('DO transitions to complete after last pick', async () => {
    const session = await startTestSession(env, { memberCount: 2, positionCount: 2, allShift: 'A' });
    await fillAllPositions(env, session.id, session.bidOrder);
    await submitADayPick(env, session.id, session.bidOrder[0], 'G1');
    await submitADayPick(env, session.id, session.bidOrder[1], 'G2');
    const state = await env.DO_BID_SESSION.get(env.DO_BID_SESSION.idFromName(session.id))
      .fetch('https://do/state').then((r) => r.json() as Promise<{ current_phase: string }>);
    expect(state.current_phase).toBe('complete');
  });

  it('broadcasts phase_changed { from: a_day_bid, to: complete }', async () => {
    const session = await startTestSession(env, { memberCount: 1, positionCount: 1, allShift: 'A' });
    const ws = await openTestWs(env, session.id, session.bidOrder[0]);
    const events: unknown[] = [];
    ws.addEventListener('message', (e) => events.push(JSON.parse(e.data)));
    await fillAllPositions(env, session.id, session.bidOrder);
    await submitADayPick(env, session.id, session.bidOrder[0], 'G1');
    await waitFor(() =>
      events.some((e) => (e as { type: string }).type === 'phase_changed' && (e as { to: string }).to === 'complete'),
    );
  });
});
```

- [ ] **Step 2: Run all integration tests, expect PASS**

- [ ] **Step 3: Commit**

```bash
git add apps/worker/src/durable/bid-session.ts apps/worker/tests/integration/a-day-*.test.ts
git commit -m "feat(do): submitADayPick handler — validate, persist, broadcast, complete"
```

---

## Task 13: REST routes (SSR snapshot + non-WS fallback)

**Files:**
- Create: `apps/worker/src/routes/bid/a-day-pick.ts`
- Create: `apps/worker/src/routes/bid/a-day-state.ts`
- Create: `apps/worker/src/routes/admin/force-a-day.ts`
- Modify: `apps/worker/src/index.ts` (UPDATE — wire routes)
- Test: `apps/worker/tests/integration/a-day-rest.test.ts`
- Test: `apps/worker/tests/integration/a-day-admin-force.test.ts`

### Sub-task 13a: POST /api/bid/a-day-pick (REST fallback)

This is used by clients that lose WS but want to submit a pick (and to make the API testable without WS plumbing).

- [ ] **Step 1: Write failing test**

```ts
// apps/worker/tests/integration/a-day-rest.test.ts
import { describe, it, expect, beforeEach } from 'vitest';
import { createTestEnv, startTestSession, fillAllPositions, callRest } from '../helpers/miniflare.js';

let env: Awaited<ReturnType<typeof createTestEnv>>;
beforeEach(async () => { env = await createTestEnv(); });

describe('POST /api/bid/a-day-pick', () => {
  it('200 on valid pick', async () => {
    const session = await startTestSession(env, { memberCount: 1, positionCount: 1, allShift: 'A' });
    await fillAllPositions(env, session.id, session.bidOrder);
    const memberId = session.bidOrder[0];
    const res = await callRest(env, 'POST', '/api/bid/a-day-pick', {
      v: 1, bidSessionId: session.id, aDay: 'G1', idempotencyKey: crypto.randomUUID(),
    }, { jwtMemberId: memberId });
    expect(res.status).toBe(200);
    expect((await res.json() as { type: string }).type).toBe('a_day_pick_made');
  });

  it('400 on bad payload', async () => {
    const res = await callRest(env, 'POST', '/api/bid/a-day-pick', { v: 1, bidSessionId: 'x', aDay: 'GZ', idempotencyKey: 'no' });
    expect(res.status).toBe(400);
  });

  it('401 on missing JWT', async () => {
    const res = await callRest(env, 'POST', '/api/bid/a-day-pick', {
      v: 1, bidSessionId: 'sess-1', aDay: 'G1', idempotencyKey: crypto.randomUUID(),
    }, { skipJwt: true });
    expect(res.status).toBe(401);
  });

  it('403 when member is not the current bidder', async () => {
    const session = await startTestSession(env, { memberCount: 2, positionCount: 2, allShift: 'A' });
    await fillAllPositions(env, session.id, session.bidOrder);
    const res = await callRest(env, 'POST', '/api/bid/a-day-pick', {
      v: 1, bidSessionId: session.id, aDay: 'G1', idempotencyKey: crypto.randomUUID(),
    }, { jwtMemberId: session.bidOrder[1] }); // not their turn
    expect(res.status).toBe(403);
  });
});
```

- [ ] **Step 2: Run test, expect FAIL**

- [ ] **Step 3: Implement `apps/worker/src/routes/bid/a-day-pick.ts`**

```ts
// apps/worker/src/routes/bid/a-day-pick.ts
import { Hono } from 'hono';
import { SubmitADayPickRequestSchema } from '@mbfd/shared';
import { requireMemberAuth } from '../../middleware/auth.js';
import type { Env } from '../../env.js';

const app = new Hono<{ Bindings: Env }>();

app.post('/a-day-pick', requireMemberAuth(), async (c) => {
  const body = await c.req.json().catch(() => null);
  const parsed = SubmitADayPickRequestSchema.safeParse(body);
  if (!parsed.success) {
    return c.json({ error: 'invalid_payload', issues: parsed.error.issues }, 400);
  }
  const auth = c.get('memberAuth');
  const doId = c.env.DO_BID_SESSION.idFromName(parsed.data.bidSessionId);
  const stub = c.env.DO_BID_SESSION.get(doId);
  const doResp = await stub.fetch('https://do/submit-a-day-pick', {
    method: 'POST',
    headers: { 'content-type': 'application/json' },
    body: JSON.stringify({
      memberId: auth.memberId,
      bidSessionId: parsed.data.bidSessionId,
      aDay: parsed.data.aDay,
      idempotencyKey: parsed.data.idempotencyKey,
    }),
  });
  const json = await doResp.json() as { type: string; reasonCode?: string };
  if (json.type === 'a_day_reject') {
    if (json.reasonCode === 'NOT_YOUR_TURN' || json.reasonCode === 'PHASE_NOT_R_DAY_BID') {
      return c.json(json, 403);
    }
    return c.json(json, 409);
  }
  return c.json(json, 200);
});

export default app;
```

> The DO exposes an internal `https://do/submit-a-day-pick` fetch endpoint that calls `handleSubmitADayPick` with a synthetic socket (writes the response back as the fetch body instead of a WS frame). This pattern matches Plan 04 Task 5 for the WS upgrade route.

- [ ] **Step 4: Re-run test, expect PASS**

### Sub-task 13b: GET /api/bid/a-day-state (snapshot)

- [ ] **Step 1: Write failing test**

```ts
// append to apps/worker/tests/integration/a-day-rest.test.ts
describe('GET /api/bid/a-day-state', () => {
  it('returns current phase, my-turn flag, and meters', async () => {
    const session = await startTestSession(env, { memberCount: 2, positionCount: 2, allShift: 'A' });
    await fillAllPositions(env, session.id, session.bidOrder);
    const res = await callRest(env, 'GET', `/api/bid/a-day-state?session=${session.id}`,
      null, { jwtMemberId: session.bidOrder[0] });
    expect(res.status).toBe(200);
    const body = await res.json() as {
      currentPhase: string;
      isMyTurn: boolean;
      eligibleADays: string[];
      meters: { groups: Array<{ shift: string; group: string }> };
    };
    expect(body.currentPhase).toBe('a_day_bid');
    expect(body.isMyTurn).toBe(true);
    expect(body.eligibleADays).toEqual(expect.arrayContaining(['G1', 'G2', 'G3', 'G4']));
    expect(body.meters.groups.length).toBe(12);
  });

  it('isMyTurn=false when it is another member\'s turn', async () => {
    const session = await startTestSession(env, { memberCount: 2, positionCount: 2, allShift: 'A' });
    await fillAllPositions(env, session.id, session.bidOrder);
    const res = await callRest(env, 'GET', `/api/bid/a-day-state?session=${session.id}`,
      null, { jwtMemberId: session.bidOrder[1] });
    const body = await res.json() as { isMyTurn: boolean };
    expect(body.isMyTurn).toBe(false);
  });
});
```

- [ ] **Step 2: Run test, expect FAIL**

- [ ] **Step 3: Implement `apps/worker/src/routes/bid/a-day-state.ts`**

```ts
// apps/worker/src/routes/bid/a-day-state.ts
import { Hono } from 'hono';
import { requireMemberAuth } from '../../middleware/auth.js';
import { COMBAT_GROUPS, WEEKDAYS } from '@mbfd/a-day';
import { canPick } from '@mbfd/a-day';
import type { Env } from '../../env.js';

const app = new Hono<{ Bindings: Env }>();

app.get('/a-day-state', requireMemberAuth(), async (c) => {
  const sessionId = c.req.query('session');
  if (!sessionId) return c.json({ error: 'session_required' }, 400);
  const auth = c.get('memberAuth');
  const doId = c.env.DO_BID_SESSION.idFromName(sessionId);
  const stub = c.env.DO_BID_SESSION.get(doId);
  const stateResp = await stub.fetch('https://do/a-day-snapshot');
  const snapshot = await stateResp.json() as {
    currentPhase: string;
    currentBidderId: number | null;
    aDayState: import('@mbfd/a-day').ADayState | null;
  };
  if (snapshot.currentPhase !== 'a_day_bid' || !snapshot.aDayState) {
    return c.json({
      currentPhase: snapshot.currentPhase,
      isMyTurn: false, eligibleADays: [], meters: { groups: [], weekdays: [] },
    });
  }

  const isMyTurn = snapshot.currentBidderId === auth.memberId;
  const phase1 = snapshot.aDayState.phase1ByMember.get(auth.memberId);
  const candidates = phase1?.shift === 'D' ? WEEKDAYS : COMBAT_GROUPS;
  const eligibleADays = candidates.filter((aDay) =>
    canPick(snapshot.aDayState!, auth.memberId, aDay).ok,
  );

  const { computeAllMeters } = await import('@mbfd/a-day');
  return c.json({
    currentPhase: snapshot.currentPhase,
    isMyTurn,
    shift: phase1?.shift ?? null,
    eligibleADays,
    meters: computeAllMeters(snapshot.aDayState),
  });
});

export default app;
```

- [ ] **Step 4: Re-run test, expect PASS**

- [ ] **Step 5: Commit**

```bash
git add apps/worker/src/routes/bid/ apps/worker/src/index.ts apps/worker/tests/integration/a-day-rest.test.ts
git commit -m "feat(routes): POST /api/bid/a-day-pick + GET /api/bid/a-day-state"
```

---

## Task 14: Admin force-a-day route

**Files:**
- Create: `apps/worker/src/routes/admin/force-a-day.ts`
- Modify: `apps/worker/src/durable/bid-session.ts` (add `forceADayPick` handler that bypasses invariant)
- Test: `apps/worker/tests/integration/a-day-admin-force.test.ts`

The admin force path bypasses the officer invariant and the capacity-full gate (admin can intentionally push a group to 6 officers or 20 members if operationally required). It still rejects malformed input and `ALREADY_PICKED`. Every force is audited with `forced=true` and `reason` populated; an AI dissent log entry is created (Plan 06 consumes this).

- [ ] **Step 1: Write failing test**

```ts
// apps/worker/tests/integration/a-day-admin-force.test.ts
import { describe, it, expect, beforeEach } from 'vitest';
import { createTestEnv, startTestSession, fillAllPositions, submitADayPick, callRest } from '../helpers/miniflare.js';

let env: Awaited<ReturnType<typeof createTestEnv>>;
beforeEach(async () => { env = await createTestEnv(); });

describe('POST /api/admin/bid-session/:id/force-a-day', () => {
  it('chief can force a member into a group that would violate officer invariant', async () => {
    const session = await startTestSession(env, { memberCount: 6, positionCount: 6, allShift: 'A', allRank: 'LT' });
    await fillAllPositions(env, session.id, session.bidOrder);
    for (let i = 0; i < 5; i++) {
      await submitADayPick(env, session.id, session.bidOrder[i], 'G1');
    }
    // Sixth member is rejected by the normal path
    const rejected = await submitADayPick(env, session.id, session.bidOrder[5], 'G1');
    expect(rejected.reasonCode).toBe('OFFICER_INVARIANT_VIOLATED');
    // Admin force
    const res = await callRest(env, 'POST', `/api/admin/bid-session/${session.id}/force-a-day`, {
      memberId: session.bidOrder[5], aDay: 'G1', reason: 'manning hole; chief approved 6-officer group',
    }, { jwtMemberId: 'chief-1', adminRole: 'chief', freshAuth: true });
    expect(res.status).toBe(200);
    expect((await res.json() as { type: string }).type).toBe('a_day_pick_made');
  });

  it('audit row recorded with forced=true and reason', async () => {
    const session = await startTestSession(env, { memberCount: 1, positionCount: 1, allShift: 'A' });
    await fillAllPositions(env, session.id, session.bidOrder);
    await callRest(env, 'POST', `/api/admin/bid-session/${session.id}/force-a-day`, {
      memberId: session.bidOrder[0], aDay: 'G2', reason: 'test override',
    }, { jwtMemberId: 'chief-1', adminRole: 'chief', freshAuth: true });
    const audit = await callRest(env, 'GET',
      `/api/admin/audit?session=${session.id}&action=a_day_pick`, null,
      { jwtMemberId: 'chief-1', adminRole: 'chief' });
    const rows = await audit.json() as Array<{ reason: string; after_state: { forced: boolean } }>;
    expect(rows.some((r) => r.reason === 'test override' && r.after_state.forced === true)).toBe(true);
  });

  it('403 when caller lacks chief role', async () => {
    const session = await startTestSession(env, { memberCount: 1, positionCount: 1, allShift: 'A' });
    await fillAllPositions(env, session.id, session.bidOrder);
    const res = await callRest(env, 'POST', `/api/admin/bid-session/${session.id}/force-a-day`, {
      memberId: session.bidOrder[0], aDay: 'G1', reason: 'unauthorized',
    }, { jwtMemberId: 'member-1', adminRole: 'member', freshAuth: true });
    expect(res.status).toBe(403);
  });

  it('401 when fresh_auth_at older than 5 minutes (step-up auth)', async () => {
    const session = await startTestSession(env, { memberCount: 1, positionCount: 1, allShift: 'A' });
    await fillAllPositions(env, session.id, session.bidOrder);
    const res = await callRest(env, 'POST', `/api/admin/bid-session/${session.id}/force-a-day`, {
      memberId: session.bidOrder[0], aDay: 'G1', reason: 'no fresh auth',
    }, { jwtMemberId: 'chief-1', adminRole: 'chief', freshAuth: false });
    expect(res.status).toBe(401);
  });

  it('still rejects ALREADY_PICKED even with force', async () => {
    const session = await startTestSession(env, { memberCount: 1, positionCount: 1, allShift: 'A' });
    await fillAllPositions(env, session.id, session.bidOrder);
    await submitADayPick(env, session.id, session.bidOrder[0], 'G1');
    const res = await callRest(env, 'POST', `/api/admin/bid-session/${session.id}/force-a-day`, {
      memberId: session.bidOrder[0], aDay: 'G2', reason: 'cannot double-pick',
    }, { jwtMemberId: 'chief-1', adminRole: 'chief', freshAuth: true });
    expect(res.status).toBe(409);
    expect((await res.json() as { reasonCode: string }).reasonCode).toBe('ALREADY_PICKED');
  });
});
```

- [ ] **Step 2: Run test, expect FAIL**

- [ ] **Step 3: Implement `apps/worker/src/routes/admin/force-a-day.ts`**

```ts
// apps/worker/src/routes/admin/force-a-day.ts
import { Hono } from 'hono';
import { z } from 'zod';
import { requireStepUpAuth } from '../../middleware/step-up-auth.js';
import { requireRole } from '../../middleware/role.js';
import { ADayValueSchema } from '@mbfd/shared';
import type { Env } from '../../env.js';

const ForceADayBodySchema = z.object({
  memberId: z.number().int().positive(),
  aDay: ADayValueSchema,
  reason: z.string().min(8).max(500),
});

const app = new Hono<{ Bindings: Env }>();

app.post(
  '/bid-session/:id/force-a-day',
  requireRole('chief'),
  requireStepUpAuth({ maxAgeSec: 300 }),
  async (c) => {
    const sessionId = c.req.param('id');
    const body = await c.req.json().catch(() => null);
    const parsed = ForceADayBodySchema.safeParse(body);
    if (!parsed.success) {
      return c.json({ error: 'invalid_payload', issues: parsed.error.issues }, 400);
    }
    const admin = c.get('memberAuth');
    const doId = c.env.DO_BID_SESSION.idFromName(sessionId);
    const stub = c.env.DO_BID_SESSION.get(doId);
    const doResp = await stub.fetch('https://do/force-a-day-pick', {
      method: 'POST',
      headers: { 'content-type': 'application/json' },
      body: JSON.stringify({
        memberId: parsed.data.memberId,
        aDay: parsed.data.aDay,
        reason: parsed.data.reason,
        adminActorId: admin.memberId,
      }),
    });
    const json = await doResp.json() as { type: string; reasonCode?: string };
    if (json.type === 'a_day_reject') {
      // Forces still cannot pick for an already-picked member; map to 409.
      return c.json(json, 409);
    }
    return c.json(json, 200);
  },
);

export default app;
```

- [ ] **Step 4: Add `handleForceADayPick` to `BidSession`**

```ts
// apps/worker/src/durable/bid-session.ts (additions)
private async handleForceADayPick(input: {
  memberId: number; aDay: import('@mbfd/a-day').ADayValue;
  reason: string; adminActorId: number;
}): Promise<unknown> {
  if (this.currentPhase !== 'a_day_bid' || !this.aDayState) {
    return { type: 'a_day_reject', v: 1, memberId: input.memberId,
      reasonCode: 'PHASE_NOT_R_DAY_BID', reasonLabel: 'Phase 2 is not active.' };
  }
  // Force cannot override ALREADY_PICKED (re-pick is a separate "swap" admin
  // action, out of scope for this plan).
  if (this.aDayState.picksByMember.has(input.memberId)) {
    return { type: 'a_day_reject', v: 1, memberId: input.memberId,
      reasonCode: 'ALREADY_PICKED', reasonLabel: 'Member has already submitted an A-Day pick.' };
  }
  const phase1 = this.aDayState.phase1ByMember.get(input.memberId);
  if (!phase1) {
    return { type: 'a_day_reject', v: 1, memberId: input.memberId,
      reasonCode: 'NO_PHASE_1_PICK', reasonLabel: 'Member has no Phase 1 pick.' };
  }
  // Bypass capacity + officer invariant — admin force overrides.
  const pick = {
    memberId: input.memberId, shift: phase1.shift, aDay: input.aDay,
    pickedAtMs: Date.now(), forced: true, adminActorId: input.adminActorId,
  };
  this.aDayState = applyPick(this.aDayState, pick);
  this.currentBidderId = nextBidder(this.aDayState) ?? null;
  await this.state.storage.transaction(async (txn) => {
    await persistADayState(txn, this.aDayState!);
    await txn.put('current_bidder_id', this.currentBidderId);
  });
  this.ctx.waitUntil(this.insertADayPick(pick, crypto.randomUUID()));
  await this.appendAudit({
    seq: this.nextSeq(), actorType: 'admin', actorId: input.adminActorId,
    action: 'forced_a_day_pick', targetKind: 'a_day', targetId: input.aDay,
    beforeState: null, afterState: pick, reason: input.reason,
  });

  const broadcast = {
    type: 'a_day_pick_made' as const, v: 1 as const, seq: this.nextSeq(),
    memberId: pick.memberId, shift: pick.shift, aDay: pick.aDay,
    pickedAtMs: pick.pickedAtMs, forced: true, adminActorId: pick.adminActorId,
    nextMemberId: this.currentBidderId,
    meters: computeAllMeters(this.aDayState),
  };
  this.broadcast(broadcast);
  if (isPhase2Complete(this.aDayState)) await this.transitionToComplete();
  return broadcast;
}
```

- [ ] **Step 5: Re-run tests, expect PASS**

- [ ] **Step 6: Commit**

```bash
git add apps/worker/src/routes/admin/force-a-day.ts apps/worker/src/durable/bid-session.ts apps/worker/tests/integration/a-day-admin-force.test.ts
git commit -m "feat(admin): force-a-day route bypasses invariants (chief + step-up auth)"
```

---

## Task 15: Web UI — `/me` Phase 2 picker

**Files:**
- Modify: `apps/web/app/me/page.tsx`
- Modify: `apps/web/app/me/loading.tsx`
- Create: `apps/web/app/draft/_components/ADayPicker.tsx`
- Create: `apps/web/app/draft/_components/ADayConfirmDialog.tsx`
- Create: `apps/web/app/draft/_components/ADayCapacityMeter.tsx`
- Create: `apps/web/app/draft/_components/ADayInvariantBadge.tsx`
- Create: `apps/web/lib/a-day-client.ts`
- Test: `apps/web/tests/unit/ADayPicker.test.tsx`
- Test: `apps/web/tests/unit/ADayCapacityMeter.test.tsx`
- Test: `apps/web/tests/e2e/a-day-pick-happy-path.spec.ts`

### Sub-task 15a: capacity meter component

- [ ] **Step 1: Write failing test**

```tsx
// apps/web/tests/unit/ADayCapacityMeter.test.tsx
import { render, screen } from '@testing-library/react';
import { describe, it, expect } from 'vitest';
import { ADayCapacityMeter } from '../../app/draft/_components/ADayCapacityMeter.js';

describe('ADayCapacityMeter', () => {
  it('renders total/max as tabular-nums', () => {
    render(<ADayCapacityMeter total={17} max={19} />);
    const el = screen.getByText('17 / 19');
    expect(el.className).toMatch(/tabular-nums/);
  });

  it('shows "no cap" for undefined max (D-shift)', () => {
    render(<ADayCapacityMeter total={3} max={undefined} />);
    expect(screen.getByText(/no cap/i)).toBeInTheDocument();
  });

  it('progress bar width matches percentage', () => {
    render(<ADayCapacityMeter total={9} max={18} dataTestId="meter" />);
    const bar = screen.getByTestId('meter-bar');
    expect(bar.style.width).toBe('50%');
  });

  it('progress bar is full Red-700 when total == max', () => {
    render(<ADayCapacityMeter total={19} max={19} dataTestId="meter" />);
    const bar = screen.getByTestId('meter-bar');
    expect(bar.className).toMatch(/bg-red-700/);
  });
});
```

- [ ] **Step 2: Run test, expect FAIL**

- [ ] **Step 3: Implement `apps/web/app/draft/_components/ADayCapacityMeter.tsx`**

```tsx
// apps/web/app/draft/_components/ADayCapacityMeter.tsx
'use client';

interface Props {
  total: number;
  max: number | undefined;
  dataTestId?: string;
}

export function ADayCapacityMeter({ total, max, dataTestId }: Props): JSX.Element {
  if (max === undefined) {
    return (
      <div className="text-stone-700 text-sm" data-testid={dataTestId}>
        <span className="tabular-nums font-mono">{total}</span>{' '}
        <span className="text-stone-500">(no cap)</span>
      </div>
    );
  }
  const pct = Math.min(100, Math.round((total / max) * 100));
  const full = total >= max;
  return (
    <div data-testid={dataTestId}>
      <div className="flex justify-between text-sm">
        <span className="tabular-nums font-mono text-stone-800">{total} / {max}</span>
      </div>
      <div className="h-2 bg-stone-200 rounded mt-1 overflow-hidden">
        <div
          data-testid={dataTestId ? `${dataTestId}-bar` : undefined}
          className={full ? 'h-2 bg-red-700' : 'h-2 bg-red-500'}
          style={{ width: `${pct}%`, transition: 'width 200ms ease' }}
        />
      </div>
    </div>
  );
}
```

### Sub-task 15b: invariant badge

- [ ] **Step 1: Write failing test**

```tsx
// apps/web/tests/unit/ADayInvariantBadge.test.tsx
import { render, screen } from '@testing-library/react';
import { describe, it, expect } from 'vitest';
import { ADayInvariantBadge } from '../../app/draft/_components/ADayInvariantBadge.js';

describe('ADayInvariantBadge', () => {
  it('renders "5 OFC ✓" when officers === required', () => {
    render(<ADayInvariantBadge officers={5} required={5} />);
    expect(screen.getByText(/5 OFC/)).toBeInTheDocument();
    expect(screen.getByText(/✓/)).toBeInTheDocument();
  });

  it('renders count with subtle warning when officers < required', () => {
    render(<ADayInvariantBadge officers={3} required={5} />);
    const el = screen.getByText(/3 \/ 5 OFC/);
    expect(el.className).toMatch(/text-stone-600/);
  });

  it('renders nothing for D-shift (required undefined)', () => {
    const { container } = render(<ADayInvariantBadge officers={2} required={undefined} />);
    expect(container.firstChild).toBeNull();
  });
});
```

- [ ] **Step 2: Implement `apps/web/app/draft/_components/ADayInvariantBadge.tsx`**

```tsx
// apps/web/app/draft/_components/ADayInvariantBadge.tsx
'use client';
interface Props { officers: number; required: number | undefined; }

export function ADayInvariantBadge({ officers, required }: Props): JSX.Element | null {
  if (required === undefined) return null;
  if (officers === required) {
    return (
      <span className="text-xs font-medium text-emerald-700 tabular-nums" aria-label={`Officer invariant satisfied: ${officers} of ${required}`}>
        {officers} OFC ✓
      </span>
    );
  }
  return (
    <span className="text-xs text-stone-600 tabular-nums" aria-label={`${officers} of ${required} officers`}>
      {officers} / {required} OFC
    </span>
  );
}
```

### Sub-task 15c: confirm dialog (5-second undo)

- [ ] **Step 1: Write failing test**

```tsx
// apps/web/tests/unit/ADayConfirmDialog.test.tsx
import { render, screen, fireEvent, waitFor, act } from '@testing-library/react';
import { describe, it, expect, vi } from 'vitest';
import { ADayConfirmDialog } from '../../app/draft/_components/ADayConfirmDialog.js';

describe('ADayConfirmDialog', () => {
  it('shows selected A-Day and Undo button', () => {
    render(<ADayConfirmDialog aDay="G2" onCommit={() => {}} onUndo={() => {}} />);
    expect(screen.getByText(/Group 2/i)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /undo/i })).toBeInTheDocument();
  });

  it('calls onCommit automatically after 5 seconds', async () => {
    vi.useFakeTimers();
    const onCommit = vi.fn();
    render(<ADayConfirmDialog aDay="FRI" onCommit={onCommit} onUndo={() => {}} />);
    act(() => { vi.advanceTimersByTime(5000); });
    await waitFor(() => expect(onCommit).toHaveBeenCalledTimes(1));
    vi.useRealTimers();
  });

  it('cancels commit when Undo clicked', async () => {
    vi.useFakeTimers();
    const onCommit = vi.fn();
    const onUndo = vi.fn();
    render(<ADayConfirmDialog aDay="G1" onCommit={onCommit} onUndo={onUndo} />);
    fireEvent.click(screen.getByRole('button', { name: /undo/i }));
    act(() => { vi.advanceTimersByTime(5000); });
    expect(onCommit).not.toHaveBeenCalled();
    expect(onUndo).toHaveBeenCalledTimes(1);
    vi.useRealTimers();
  });
});
```

- [ ] **Step 2: Implement `apps/web/app/draft/_components/ADayConfirmDialog.tsx`**

```tsx
// apps/web/app/draft/_components/ADayConfirmDialog.tsx
'use client';
import { useEffect, useRef, useState } from 'react';

const LABEL: Record<string, string> = {
  G1: 'Group 1', G2: 'Group 2', G3: 'Group 3', G4: 'Group 4',
  MON: 'Monday', TUE: 'Tuesday', WED: 'Wednesday', THU: 'Thursday',
  FRI: 'Friday', SAT: 'Satuaday', SUN: 'Sunday',
};

interface Props {
  aDay: string;
  onCommit: () => void;
  onUndo: () => void;
  /** Override for tests; defaults to 5000ms. */
  delayMs?: number;
}

export function ADayConfirmDialog({ aDay, onCommit, onUndo, delayMs = 5000 }: Props): JSX.Element {
  const [remaining, setRemaining] = useState(delayMs);
  const undone = useRef(false);
  useEffect(() => {
    const t0 = Date.now();
    const tick = setInterval(() => {
      const r = Math.max(0, delayMs - (Date.now() - t0));
      setRemaining(r);
      if (r === 0) clearInterval(tick);
    }, 100);
    const commit = setTimeout(() => {
      if (!undone.current) onCommit();
    }, delayMs);
    return () => { clearInterval(tick); clearTimeout(commit); };
  }, [delayMs, onCommit]);

  return (
    <div role="status" aria-live="polite" className="fixed bottom-6 right-6 bg-stone-900 text-stone-50 rounded shadow-lg px-4 py-3 flex items-center gap-4">
      <div>
        <div className="font-semibold">{LABEL[aDay] ?? aDay} selected</div>
        <div className="text-xs text-stone-300 tabular-nums">Locking in {(remaining / 1000).toFixed(1)}s</div>
      </div>
      <button
        type="button"
        className="px-3 py-1.5 bg-red-700 hover:bg-red-800 rounded text-white text-sm font-medium"
        onClick={() => { undone.current = true; onUndo(); }}
      >
        Undo
      </button>
    </div>
  );
}
```

### Sub-task 15d: ADayPicker

- [ ] **Step 1: Write failing test**

```tsx
// apps/web/tests/unit/ADayPicker.test.tsx
import { render, screen, fireEvent } from '@testing-library/react';
import { describe, it, expect, vi } from 'vitest';
import { ADayPicker } from '../../app/draft/_components/ADayPicker.js';

const mockState = {
  shift: 'A' as const,
  eligibleADays: ['G1', 'G3'],
  meters: {
    groups: [
      { shift: 'A' as const, group: 'G1' as const, meter: { total: 14, max: 19, officers: 4, officersRequired: 5, isFull: false } },
      { shift: 'A' as const, group: 'G2' as const, meter: { total: 19, max: 19, officers: 5, officersRequired: 5, isFull: true } },
      { shift: 'A' as const, group: 'G3' as const, meter: { total: 12, max: 19, officers: 3, officersRequired: 5, isFull: false } },
      { shift: 'A' as const, group: 'G4' as const, meter: { total: 18, max: 19, officers: 5, officersRequired: 5, isFull: false } },
    ],
    weekdays: [],
  },
};

describe('ADayPicker — A/B/C shift', () => {
  it('renders 4 group cards', () => {
    render(<ADayPicker state={mockState} onPick={() => {}} />);
    expect(screen.getAllByTestId(/group-card-/)).toHaveLength(4);
  });

  it('full group has FULL badge and is disabled', () => {
    render(<ADayPicker state={mockState} onPick={() => {}} />);
    const card = screen.getByTestId('group-card-G2');
    expect(card.querySelector('[data-state="full"]')).toBeTruthy();
    expect(card.querySelector('button')?.disabled).toBe(true);
  });

  it('group not in eligibleADays is disabled with invariant tooltip', () => {
    render(<ADayPicker state={mockState} onPick={() => {}} />);
    // G4 not in eligibleADays (perhaps invariant block on this user)
    const card = screen.getByTestId('group-card-G4');
    expect(card.querySelector('button')?.disabled).toBe(true);
    expect(card.querySelector('[title]')?.getAttribute('title') ?? '').toMatch(/invariant|officer/i);
  });

  it('clicking an eligible group calls onPick', () => {
    const onPick = vi.fn();
    render(<ADayPicker state={mockState} onPick={onPick} />);
    fireEvent.click(screen.getByTestId('group-card-G1').querySelector('button')!);
    expect(onPick).toHaveBeenCalledWith('G1');
  });
});

describe('ADayPicker — D-shift', () => {
  const dState = {
    shift: 'D' as const,
    eligibleADays: ['MON', 'WED', 'FRI'],
    meters: {
      groups: [],
      weekdays: [
        { weekday: 'MON' as const, meter: { total: 1, max: undefined, officers: 0, officersRequired: undefined, isFull: false } },
        { weekday: 'TUE' as const, meter: { total: 0, max: undefined, officers: 0, officersRequired: undefined, isFull: false } },
        { weekday: 'WED' as const, meter: { total: 2, max: undefined, officers: 0, officersRequired: undefined, isFull: false } },
        { weekday: 'THU' as const, meter: { total: 0, max: undefined, officers: 0, officersRequired: undefined, isFull: false } },
        { weekday: 'FRI' as const, meter: { total: 5, max: undefined, officers: 0, officersRequired: undefined, isFull: false } },
        { weekday: 'SAT' as const, meter: { total: 0, max: undefined, officers: 0, officersRequired: undefined, isFull: false } },
        { weekday: 'SUN' as const, meter: { total: 0, max: undefined, officers: 0, officersRequired: undefined, isFull: false } },
      ],
    },
  };

  it('renders 7 weekday cards', () => {
    render(<ADayPicker state={dState} onPick={() => {}} />);
    expect(screen.getAllByTestId(/weekday-card-/)).toHaveLength(7);
  });

  it('TUE not in eligibleADays is disabled', () => {
    render(<ADayPicker state={dState} onPick={() => {}} />);
    expect(screen.getByTestId('weekday-card-TUE').querySelector('button')?.disabled).toBe(true);
  });
});
```

- [ ] **Step 2: Implement `apps/web/app/draft/_components/ADayPicker.tsx`**

```tsx
// apps/web/app/draft/_components/ADayPicker.tsx
'use client';
import { COMBAT_GROUPS, WEEKDAYS } from '@mbfd/a-day';
import { ADayCapacityMeter } from './ADayCapacityMeter.js';
import { ADayInvariantBadge } from './ADayInvariantBadge.js';

interface Meter {
  total: number; max: number | undefined;
  officers: number; officersRequired: number | undefined;
  isFull: boolean;
}

interface ADayState {
  shift: 'A' | 'B' | 'C' | 'D';
  eligibleADays: readonly string[];
  meters: {
    groups: Array<{ shift: 'A' | 'B' | 'C'; group: 'G1' | 'G2' | 'G3' | 'G4'; meter: Meter }>;
    weekdays: Array<{ weekday: typeof WEEKDAYS[number]; meter: Meter }>;
  };
}

const GROUP_LABEL: Record<string, string> = { G1: 'Group 1', G2: 'Group 2', G3: 'Group 3', G4: 'Group 4' };
const WD_LABEL: Record<string, string> = { MON: 'Monday', TUE: 'Tuesday', WED: 'Wednesday', THU: 'Thursday', FRI: 'Friday', SAT: 'Satuaday', SUN: 'Sunday' };

export function ADayPicker({
  state,
  onPick,
}: { state: ADayState; onPick: (aDay: string) => void }): JSX.Element {
  if (state.shift === 'D') {
    return (
      <div className="grid grid-cols-2 md:grid-cols-7 gap-3">
        {WEEKDAYS.map((wd) => {
          const meter = state.meters.weekdays.find((m) => m.weekday === wd)?.meter
            ?? { total: 0, max: undefined, officers: 0, officersRequired: undefined, isFull: false };
          const eligible = state.eligibleADays.includes(wd);
          const reason = eligible ? '' : meter.isFull ? 'Weekday is at capacity' : 'Not eligible for this weekday';
          return (
            <div key={wd} data-testid={`weekday-card-${wd}`} className="border border-stone-200 rounded-lg p-3 bg-stone-50">
              <div className="text-sm font-semibold mb-2">{WD_LABEL[wd]}</div>
              <ADayCapacityMeter total={meter.total} max={meter.max} dataTestId={`meter-${wd}`} />
              <button
                type="button"
                className="w-full mt-3 py-2 px-3 rounded bg-red-700 hover:bg-red-800 text-white text-sm font-medium disabled:bg-stone-300 disabled:cursor-not-allowed"
                disabled={!eligible}
                title={reason}
                onClick={() => onPick(wd)}
              >
                {eligible ? 'Select' : meter.isFull ? 'FULL' : 'N/A'}
              </button>
            </div>
          );
        })}
      </div>
    );
  }

  return (
    <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
      {COMBAT_GROUPS.map((g) => {
        const m = state.meters.groups.find((x) => x.shift === state.shift && x.group === g)?.meter
          ?? { total: 0, max: 19, officers: 0, officersRequired: 5, isFull: false };
        const eligible = state.eligibleADays.includes(g);
        const reason = eligible
          ? ''
          : m.isFull
            ? 'Group is at capacity'
            : 'Officer invariant would be violated by this pick';
        return (
          <div key={g} data-testid={`group-card-${g}`} className="border border-stone-200 rounded-lg p-3 bg-stone-50">
            <div className="flex items-center justify-between mb-2">
              <div className="text-sm font-semibold">{GROUP_LABEL[g]}</div>
              <ADayInvariantBadge officers={m.officers} required={m.officersRequired} />
            </div>
            <ADayCapacityMeter total={m.total} max={m.max} dataTestId={`meter-${g}`} />
            {m.isFull && <div data-state="full" className="mt-2 text-xs text-red-700 font-medium">FULL</div>}
            <button
              type="button"
              className="w-full mt-3 py-2 px-3 rounded bg-red-700 hover:bg-red-800 text-white text-sm font-medium disabled:bg-stone-300 disabled:cursor-not-allowed"
              disabled={!eligible}
              title={reason}
              onClick={() => onPick(g)}
            >
              {eligible ? 'Select' : m.isFull ? 'FULL' : 'Blocked'}
            </button>
          </div>
        );
      })}
    </div>
  );
}
```

### Sub-task 15e: page wiring + WS client

- [ ] **Step 1: Implement `apps/web/lib/a-day-client.ts`**

```ts
// apps/web/lib/a-day-client.ts
import { SubmitADayPickRequestSchema, type SubmitADayPickRequest } from '@mbfd/shared';

/** Send a Phase-2 pick via the open WS connection (preferred) or REST fallback. */
export async function sendADayPick(opts: {
  ws: WebSocket | null;
  sessionId: string;
  aDay: string;
  idempotencyKey: string;
}): Promise<void> {
  const payload: SubmitADayPickRequest = SubmitADayPickRequestSchema.parse({
    v: 1,
    bidSessionId: opts.sessionId,
    aDay: opts.aDay,
    idempotencyKey: opts.idempotencyKey,
  });
  if (opts.ws && opts.ws.readyState === WebSocket.OPEN) {
    opts.ws.send(JSON.stringify({ type: 'submit_a_day_pick', ...payload }));
    return;
  }
  // REST fallback
  const res = await fetch('/api/bid/a-day-pick', {
    method: 'POST',
    headers: { 'content-type': 'application/json' },
    body: JSON.stringify(payload),
  });
  if (!res.ok) {
    throw new Error(`A-Day pick failed: ${res.status} ${await res.text()}`);
  }
}
```

- [ ] **Step 2: Update `apps/web/app/me/page.tsx`**

```tsx
// apps/web/app/me/page.tsx
import { Suspense } from 'react';
import { fetchMeServer } from '@/lib/server/me';
import { fetchADayStateServer } from '@/lib/server/a-day-state';
import { ADayClient } from './_components/ADayClient';
import { ProfileCard } from '@/app/draft/_components/ProfileCard';

export default async function MePage() {
  const [me, aDayState] = await Promise.all([fetchMeServer(), fetchADayStateServer()]);
  return (
    <main className="max-w-4xl mx-auto px-4 py-6">
      <ProfileCard me={me} />
      <Suspense fallback={<div className="mt-6 text-stone-500">Loading A-Day picker...</div>}>
        <ADayClient initialState={aDayState} me={me} />
      </Suspense>
    </main>
  );
}
```

- [ ] **Step 3: Implement `apps/web/app/me/_components/ADayClient.tsx`**

```tsx
// apps/web/app/me/_components/ADayClient.tsx
'use client';
import { useEffect, useRef, useState } from 'react';
import { ADayPicker } from '@/app/draft/_components/ADayPicker';
import { ADayConfirmDialog } from '@/app/draft/_components/ADayConfirmDialog';
import { sendADayPick } from '@/lib/a-day-client';

interface ADayState {
  currentPhase: string;
  isMyTurn: boolean;
  shift: 'A' | 'B' | 'C' | 'D' | null;
  eligibleADays: string[];
  meters: { groups: unknown[]; weekdays: unknown[] };
}

export function ADayClient({
  initialState,
  me,
}: { initialState: ADayState; me: { sessionId: string; memberId: number } }): JSX.Element {
  const [state, setState] = useState(initialState);
  const [pending, setPending] = useState<string | null>(null);
  const wsRef = useRef<WebSocket | null>(null);

  useEffect(() => {
    const ws = new WebSocket(`${location.origin.replace(/^http/, 'ws')}/api/ws/session?session=${me.sessionId}`);
    wsRef.current = ws;
    ws.addEventListener('message', (e) => {
      const msg = JSON.parse(e.data) as { type: string };
      if (msg.type === 'a_day_pick_made' || msg.type === 'phase_changed') {
        // Refetch snapshot — simpler than mirroring state from WS messages.
        fetch(`/api/bid/a-day-state?session=${me.sessionId}`)
          .then((r) => r.json() as Promise<ADayState>)
          .then(setState);
      }
    });
    return () => ws.close();
  }, [me.sessionId]);

  if (state.currentPhase !== 'a_day_bid') {
    return <div className="mt-6 text-stone-700">Phase 2 has not started yet.</div>;
  }
  if (!state.isMyTurn) {
    return <div className="mt-6 text-stone-700">Waiting for your turn to pick your A-Day.</div>;
  }

  return (
    <section className="mt-6">
      <h2 className="text-lg font-semibold mb-3 text-stone-900">Phase 2 - Choose your A-Day</h2>
      <ADayPicker state={state as never} onPick={(aDay) => setPending(aDay)} />
      {pending && (
        <ADayConfirmDialog
          aDay={pending}
          onUndo={() => setPending(null)}
          onCommit={async () => {
            await sendADayPick({
              ws: wsRef.current, sessionId: me.sessionId, aDay: pending,
              idempotencyKey: crypto.randomUUID(),
            });
            setPending(null);
          }}
        />
      )}
    </section>
  );
}
```

- [ ] **Step 4: Run all unit tests, expect PASS**

```bash
pnpm --filter @mbfd-bid/web test
```

- [ ] **Step 5: Commit**

```bash
git add apps/web/app/me/ apps/web/app/draft/_components/ADay*.tsx apps/web/lib/a-day-client.ts apps/web/tests/unit/ADay*.test.tsx
git commit -m "feat(web): /me Phase 2 A-Day picker UI (4-group / 7-weekday cards + undo)"
```

### Sub-task 15f: E2E test

- [ ] **Step 1: Write Playwright E2E spec**

```ts
// apps/web/tests/e2e/a-day-pick-happy-path.spec.ts
import { test, expect } from '@playwright/test';

test('member completes Phase 1 then picks an A-Day Group', async ({ page, context }) => {
  await context.addCookies([{ name: 'pin_session', value: 'test', url: 'http://localhost:3000' }]);
  await page.goto('/login');
  await page.getByLabel('Employee ID').fill('20731');
  await page.getByLabel('Password').fill('test-pass');
  await page.getByRole('button', { name: 'Sign in' }).click();
  await page.waitForURL('**/lobby');

  // Phase 1 — single-member test session, member picks position then progresses
  await page.goto('/draft');
  await page.getByRole('button', { name: 'Your turn' }).click();
  await page.getByText('A105').click();
  await page.getByRole('button', { name: 'Confirm pick' }).click();
  // Wait for transition
  await page.waitForSelector('text=Phase 2 - Choose your A-Day');

  // Phase 2 — pick Group 2
  await page.goto('/me');
  await page.getByTestId('group-card-G2').getByRole('button').click();
  await page.getByRole('button', { name: 'Undo' }); // appears
  // Wait for auto-commit (5s)
  await page.waitForTimeout(5500);

  // Verify final state
  const stateRes = await page.request.get('/api/bid/a-day-state?session=test-session');
  const state = await stateRes.json() as { currentPhase: string };
  expect(state.currentPhase).toBe('complete');
});
```

- [ ] **Step 2: Run E2E test, expect PASS**

```bash
pnpm --filter @mbfd-bid/web test:e2e a-day-pick-happy-path.spec.ts
```

- [ ] **Step 3: Commit**

```bash
git add apps/web/tests/e2e/a-day-pick-happy-path.spec.ts
git commit -m "test(e2e): Phase 1 → Phase 2 happy path"
```

---

## Task 16: Full 12-member Phase 1 + Phase 2 simulation

**Files:**
- Create: `apps/web/tests/e2e/a-day-full-bid-simulation.spec.ts`
- Create: `apps/worker/tests/integration/a-day-vacant-positions.test.ts`

This is the end-to-end acceptance test. Twelve simulated members are seeded across A/B/C/D shifts with realistic rank distribution (5 officers + 7 firefighters); the test drives Phase 1 + Phase 2 entirely through the API and asserts:
1. Final officers-per-group on each of A/B/C equals exactly 5.
2. Total members per group is between 18 and 19 (or whatever was loaded for the small test pool — adjusted via test caps).
3. Every member has a row in `a_day_picks`.
4. Vacant Phase 1 positions still allowed Phase 2 to run.

- [ ] **Step 1: Write failing simulation**

```ts
// apps/web/tests/e2e/a-day-full-bid-simulation.spec.ts
import { test, expect } from '@playwright/test';
import { setupTestSession, simulateMember } from './helpers/sim';

test('12-member end-to-end Phase 1 + Phase 2', async ({ request }) => {
  // Use 4-officer caps so 12 members can satisfy the invariant with a small pool.
  const session = await setupTestSession(request, {
    memberCount: 12,
    shifts: { A: 5, B: 5, C: 0, D: 2 },
    ranks: { CPT: 1, LT: 4, FF: 7 },
    groupCaps: {
      A: { G1: { min: 3, max: 3, officersRequired: 1 }, G2: { min: 2, max: 2, officersRequired: 1 }, G3: { min: 0, max: 0, officersRequired: 0 }, G4: { min: 0, max: 0, officersRequired: 0 } },
      B: { G1: { min: 3, max: 3, officersRequired: 1 }, G2: { min: 2, max: 2, officersRequired: 1 }, G3: { min: 0, max: 0, officersRequired: 0 }, G4: { min: 0, max: 0, officersRequired: 0 } },
      C: { G1: { min: 0, max: 0, officersRequired: 0 }, G2: { min: 0, max: 0, officersRequired: 0 }, G3: { min: 0, max: 0, officersRequired: 0 }, G4: { min: 0, max: 0, officersRequired: 0 } },
    },
  });

  // Phase 1: each member picks any open position
  for (const member of session.members) {
    await simulateMember(request, session.id, member, { phase: 1 });
  }
  // Phase 2: each member picks the first eligible A-Day
  for (const member of session.members) {
    await simulateMember(request, session.id, member, { phase: 2 });
  }

  const final = await request.get(`/api/admin/bid-session/${session.id}/final`).then((r) => r.json() as Promise<{
    aGroups: Record<'G1' | 'G2' | 'G3' | 'G4', { officers: number; total: number }>;
    bGroups: Record<'G1' | 'G2' | 'G3' | 'G4', { officers: number; total: number }>;
    aDayPicksTotal: number;
  }>);

  expect(final.aGroups.G1).toEqual({ officers: 1, total: 3 });
  expect(final.aGroups.G2).toEqual({ officers: 1, total: 2 });
  expect(final.bGroups.G1).toEqual({ officers: 1, total: 3 });
  expect(final.bGroups.G2).toEqual({ officers: 1, total: 2 });
  expect(final.aDayPicksTotal).toBe(12);
});
```

- [ ] **Step 2: Vacant-positions integration test**

```ts
// apps/worker/tests/integration/a-day-vacant-positions.test.ts
import { describe, it, expect, beforeEach } from 'vitest';
import { createTestEnv, startTestSession, fillPositions, lockVacant } from '../helpers/miniflare.js';

let env: Awaited<ReturnType<typeof createTestEnv>>;
beforeEach(async () => { env = await createTestEnv(); });

describe('Vacant Phase 1 positions', () => {
  it('Phase 2 starts even when 1 position is locked vacant', async () => {
    const session = await startTestSession(env, { memberCount: 3, positionCount: 4, allShift: 'A' });
    await fillPositions(env, session.id, session.bidOrder); // fills 3
    await lockVacant(env, session.id, 'A215');             // 4th locked vacant
    const state = await env.DO_BID_SESSION.get(env.DO_BID_SESSION.idFromName(session.id))
      .fetch('https://do/state').then((r) => r.json() as Promise<{ current_phase: string }>);
    expect(state.current_phase).toBe('a_day_bid');
  });

  it('vacant member is NOT in Phase 2 bid order', async () => {
    const session = await startTestSession(env, { memberCount: 3, positionCount: 4, allShift: 'A' });
    await fillPositions(env, session.id, session.bidOrder);
    await lockVacant(env, session.id, 'A215');
    const order = await env.DO_BID_SESSION.get(env.DO_BID_SESSION.idFromName(session.id))
      .fetch('https://do/a-day-order').then((r) => r.json() as Promise<{ order: number[] }>);
    expect(order.order.length).toBe(3);
  });
});
```

- [ ] **Step 3: Run all tests + coverage**

```bash
pnpm --filter @mbfd/a-day test:coverage
pnpm --filter @mbfd-bid/worker test
pnpm --filter @mbfd-bid/web test
pnpm --filter @mbfd-bid/web test:e2e
```

Expected: all PASS; coverage 100% on `@mbfd/a-day` `src/`.

- [ ] **Step 4: Update master index**

```bash
# In docs/superpowers/plans/2026-05-17-mbfd-bid-master-index.md
# - mark Plan 07 status as "✅ detailed"
# - confirm there is no separate a_day_complete phase (only a_day_bid → complete)
```

- [ ] **Step 5: Final commit**

```bash
git add apps/web/tests/e2e/a-day-full-bid-simulation.spec.ts apps/worker/tests/integration/a-day-vacant-positions.test.ts docs/superpowers/plans/2026-05-17-mbfd-bid-master-index.md
git commit -m "test(a-day): 12-member end-to-end simulation + vacant-positions coverage"
```

---

## Verification checklist

Run all of the following before declaring Plan 07 complete. Each line must be checked.

### Build + tests
- [ ] `pnpm --filter @mbfd/a-day build` — no TypeScript errors
- [ ] `pnpm --filter @mbfd/a-day test:coverage` — 100% lines / branches / functions / statements on `src/**/*.ts`
- [ ] `pnpm --filter @mbfd-bid/worker test` — all integration tests PASS
- [ ] `pnpm --filter @mbfd-bid/web test` — all unit tests PASS
- [ ] `pnpm --filter @mbfd-bid/web test:e2e` — `a-day-pick-happy-path.spec.ts` and `a-day-full-bid-simulation.spec.ts` PASS
- [ ] `pnpm -w typecheck` — no errors in any workspace

### Behavior
- [ ] Phase 1 completion (all positions filled OR locked-vacant) auto-triggers transition to `a_day_bid`
- [ ] `phase_changed` WS message includes `bidOrderPhase2` array of member ids
- [ ] First `turn_started` after transition arrives within 250ms of the last Phase 1 pick (Miniflare timing)
- [ ] Capacity meter updates within 250ms of a pick on all connected clients
- [ ] A/B/C invariant: every group ends at exactly 5 officers (or `officersRequired`, if overridden via config)
- [ ] A/B/C capacity: every group ends with `total ∈ [min, max]` (default 18-19)
- [ ] D-shift weekday picker shows all 7 weekdays with `(no cap)` by default
- [ ] Admin can configure `d_shift_weekday_caps` and clients see caps in meters
- [ ] An ineligible group is visually disabled with a tooltip stating the specific block (`Group is at capacity` or `Officer invariant would be violated by this pick`)
- [ ] Admin force-a-day bypasses both capacity and officer invariant; audit row carries `forced=true` and `reason` populated
- [ ] Admin force-a-day still rejects ALREADY_PICKED (no double-picks even with force)
- [ ] Step-up auth enforced on `/api/admin/bid-session/:id/force-a-day` (fresh_auth_at within 5 minutes)
- [ ] Idempotency: replay of the same `idempotencyKey` returns the same response
- [ ] Vacant Phase 1 positions (admin-locked) do not block Phase 2 transition
- [ ] Pre-seeded picks (Union President) appear in `a_day_picks` before Phase 2 starts AND are skipped in the Phase 2 bid order
- [ ] After last A-Day pick, DO transitions to `complete` and broadcasts `phase_changed { to: 'complete' }`
- [ ] DO `state.storage.put()` happens BEFORE the WS broadcast for every Phase 2 mutation (verified by a fault-injection test reused from Plan 04)
- [ ] D1 `a_day_picks` row written async via `ctx.waitUntil`; broadcast is not blocked on D1 latency

### UI / impeccable rules
- [ ] All capacity numbers use `font-variant-numeric: tabular-nums`
- [ ] All buttons in `ADayPicker` and `ADayConfirmDialog` are ≥ 44×44px (tested via Playwright `boundingBox()`)
- [ ] No cold gray — all neutral surfaces use `bg-stone-*`
- [ ] Red-700 is the only red used in the picker
- [ ] `prefers-reduced-motion` gate disables the meter progress-bar `transition`
- [ ] 5-second Undo toast appears bottom-right on every confirmed selection

### Audit / Plan 08 hooks
- [ ] Every Phase 2 pick produces an `audit_log` row with `action = 'a_day_pick'` (or `'forced_a_day_pick'`)
- [ ] `before_state` is null (new pick); `after_state` is the full `ADayPick` JSON
- [ ] Phase transition emits one audit row with `actor_type='system'` and `action='session_phase_changed'`
- [ ] Session completion emits one audit row with `action='session_complete'`

### Open question resolution
- [ ] Decision 7 (Group 4 special case): confirmed there is no Group 4 special case in 2026; documented in decisions preamble
- [ ] Decision 12 (no `a_day_complete` intermediate state): master index updated; only `complete` is the terminal phase

---

## Rollback procedure

If Plan 07 must be reverted before launch (e.g., production Phase 2 transition fails under load), use this procedure to roll back without losing Phase 1 picks.

### Step 1: Disable Phase 2 transition flag

Add a server-side feature flag check at the top of `transitionToPhase2()`:

```ts
if (!this.env.ENABLE_PHASE_2 || this.env.ENABLE_PHASE_2 === 'false') {
  // Stop in position_bid; admin must export Phase 1 manually and manage Phase 2 offline.
  this.currentPhase = 'paused';
  await this.state.storage.put({ current_phase: 'paused' });
  this.broadcast({ type: 'paused', v: 1, reason: 'Phase 2 disabled; running Phase 2 offline.' });
  return;
}
```

Set `wrangler secret put ENABLE_PHASE_2 --env production` to `"false"`. New transitions will pause instead.

### Step 2: Drain in-flight Phase 2 picks

If Phase 2 is already in progress when the rollback is decided:

```bash
pnpm --filter @mbfd-bid/worker wrangler tail --search "a_day_pick"
# Verify no picks are arriving; if they are, pause via admin console first.
```

Then through the admin console issue `pause` (Plan 05). The DO halts.

### Step 3: Export current state

Run the audit exporter (Plan 08 dependency) to dump:
- All `bids` rows (Phase 1 picks — preserved)
- All `a_day_picks` rows (whatever Phase 2 picks made it)
- The DO storage snapshot

```bash
pnpm --filter @mbfd-bid/worker run export-session -- --session=<session-id> --out=./rollback-snapshot
```

### Step 4: Revert code (preserve schema)

```bash
git revert <commit-range-of-plan-07>
git push origin main
# Wrangler redeploys via CI
```

> Do NOT revert migration `0006_a_day_picks.sql`. It stays. Rolling back the migration would lose any Phase 2 picks already accepted; preserving the table costs nothing and allows the rollback to be undone.

### Step 5: Re-issue Phase 2 offline

The chief reviews the rollback snapshot, runs the manual A-Day allocation (per `2026_Bid_Process.md §5`), and re-enters the final groups into the post-bid roster (Plan 08 portal write-back) via the admin override REST endpoints.

### Step 6: Forward path (when ready to roll forward again)

1. Toggle `ENABLE_PHASE_2 = "true"` in Wrangler secrets.
2. Re-deploy the reverted code.
3. The DO's `transitionToPhase2()` will resume the next time a Phase-1 bid completes.
4. Existing `a_day_picks` rows are picked up by `loadADayState()` on rehydration — no replay needed.

---

## Open questions tracked to next round

These are NOT blockers for Plan 07 execution. They are flagged here so the engineer can raise them if they become relevant during implementation, and so Plans 08 and 09 know to address them.

1. **Pre-seeded UP fixture source.** Where does the list of pre-seeded `ADayPick` entries (Union President, paramedic students) come from in the worker? Plan 02 (data plane) seeded `bid_sessions.config_json.pre_seeded_a_day_picks` as a stub but did not specify the shape. Resolved here as `Array<ADayPick>` JSON in `config_json`; Plan 02's seed script must be updated to populate it before Plan 07's tests can run against real session data.
2. **Re-pick / swap.** Spec §11 mentions a future "admin can let two members swap A-Days" feature. Out of scope for Plan 07; surfaced here so Plan 09 includes a known-unimplemented marker. The current force-a-day path explicitly returns ALREADY_PICKED rather than overwriting.
3. **AI advisory on A-Day picks.** Plan 06 mentions AI forecast every 10 picks. Phase 2 is shorter (one pick per member; ~230 picks total) so the 10-pick cadence still applies. Plan 06's advisory shape must be extended with an `ADayInvariantSnapshot` field — the structure is defined here in Task 2 so Plan 06 can consume it.
4. **R2 hash-chained audit shape.** Plan 08 hashes audit rows into JSONL. Confirm that `forced_a_day_pick` and `a_day_pick` actions appear in the canonical action enum before Plan 08 starts.
5. **`a_day_complete` phase removal in spec.** Spec §6.1 lists `current_phase: enum('config','position_bid','a_day_bid','paused','complete')` — already correct. The master index sketch's `a_day_complete` reference is the only place that needs updating (Task 16 Step 4).

