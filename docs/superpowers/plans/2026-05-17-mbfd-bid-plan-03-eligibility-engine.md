# Plan 03 — Eligibility engine: rules, points, tie-break

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Deterministic TypeScript package `@mbfd/eligibility` that, given a Member + Position + PositionRule, returns whether the member is eligible, why (or why not), their points total, and sub-pool totals (SO, MO). Includes the full tie-break chain. 100% line and branch coverage with a golden replay test against the 2025 actual bid as the canonical regression suite.

**Architecture:** Pure functions in `packages/eligibility/`. No DB access — accepts plain objects. The worker and DO both depend on it via the monorepo workspace. The deterministic engine is the SOLE source of eligibility truth (per spec §11.2 — the AI explains, it does not decide).

**Tech stack additions:** `@mbfd/eligibility` workspace package. No new runtime deps (pure TypeScript).

**Cross-references:** Plan 02 (data plane) provides the DB schema and seed fixtures that describe the shape of `Member`, `PositionRule`, and `Credential` objects consumed here. Plan 06 (AI advisory) consumes the `reasons` array from `EligibilityResult`.

---

## File map

```
packages/eligibility/
  package.json
  tsconfig.json
  src/
    index.ts                        ← re-exports public API
    types.ts                        ← Member, Credential, PositionRule, EligibilityResult, PointsBreakdown
    operations-techs.ts             ← 6 paired Op/Tech cert names (const array)
    criteria/
      rank.ts                       ← rankSatisfied(member, rule): EligibilityReason
      paramedic.ts                  ← paramedicSatisfied(member, rule): EligibilityReason
      driver-engineer.ts            ← driverEngineerSatisfied(member, rule): EligibilityReason
      non-probationary.ts           ← nonProbationarySatisfied(member, rule): EligibilityReason
      certs.ts                      ← requiredCredsSatisfied(member, rule): EligibilityReason[]
    points/
      sum.ts                        ← computePoints(member, rule): PointsBreakdown
      so-pool.ts                    ← computeSoPoints(member, credNames): number
      mo-pool.ts                    ← computeMoPoints(member, credNames): number
    evaluate.ts                     ← evaluateEligibility(member, position, rule): EligibilityResult
    tie-break.ts                    ← compare(a, b, tieBreakChain): -1 | 0 | 1
  tests/
    fixtures/
      2025-members.json             ← 192-member subset from analysis/personnel.csv
      2025-rules.json               ← rule entries matching positions in bid_pick.csv
      2025-actual-bid.json          ← exported from analysis/bid_pick.csv (244 rows)
    unit/
      rank.test.ts
      paramedic.test.ts
      driver-engineer.test.ts
      non-probationary.test.ts
      certs.test.ts
      sum.test.ts
      so-pool.test.ts
      mo-pool.test.ts
      tie-break.test.ts
      evaluate.test.ts
    golden/
      2025-replay.test.ts           ← for every pick in bid_pick.csv, engine reports eligible
```

---

## Source data reference

| File | Use |
|------|-----|
| `D:/GitHub_Repos/MBFD_Hub/analysis/bid_pick.csv` | 244-row 2025 picks; golden replay source |
| `D:/GitHub_Repos/MBFD_Hub/analysis/personnel.csv` | 192-member roster; fixture source |
| `D:/GitHub_Repos/mbfd-bid/apps/worker/seed/fixtures/2026_rules.json` | 232 rule entries; type reference |
| `D:/GitHub_Repos/mbfd-bid/apps/worker/seed/fixtures/reference_credentials.json` | 57 credential names |
| `D:/MBFD/Bid/2026 Bid Documents/2026_Rules_and_Points.md` | Authoritative 2026 rules |

**Schema source of truth for types:** `docs/superpowers/specs/2026-05-17-mbfd-bid-webapp-design.md` §11.2.

---

## Task 1: Package scaffold

**Files:**
- Create: `packages/eligibility/package.json`
- Create: `packages/eligibility/tsconfig.json`
- Create: `packages/eligibility/src/index.ts` (empty re-export barrel)
- Modify: root `pnpm-workspace.yaml` (verify `packages/*` already covered)
- Modify: root `package.json` scripts (add `--filter @mbfd/eligibility` to test script)

- [ ] **Step 1: Create `packages/eligibility/package.json`**

```json
{
  "name": "@mbfd/eligibility",
  "version": "0.1.0",
  "private": true,
  "type": "module",
  "main": "./dist/index.js",
  "types": "./dist/index.d.ts",
  "exports": {
    ".": {
      "import": "./dist/index.js",
      "types": "./dist/index.d.ts"
    }
  },
  "scripts": {
    "build": "tsc",
    "test": "vitest run",
    "test:watch": "vitest",
    "test:coverage": "vitest run --coverage"
  },
  "devDependencies": {
    "@vitest/coverage-v8": "^1.6.0",
    "typescript": "^5.4.0",
    "vitest": "^1.6.0"
  }
}
```

- [ ] **Step 2: Create `packages/eligibility/tsconfig.json`**

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
    "sourceMap": true
  },
  "include": ["src/**/*.ts"],
  "exclude": ["node_modules", "dist", "tests"]
}
```

- [ ] **Step 3: Create `packages/eligibility/src/index.ts`** (empty barrel for now)

```ts
// Public API — populated in later tasks
export type {} from './types.js';
```

- [ ] **Step 4: Install deps and verify the package resolves**

```bash
pnpm install
pnpm --filter @mbfd/eligibility build
```

Expected: TypeScript emits `dist/index.js` with no errors.

- [ ] **Step 5: Commit**

```bash
git add packages/eligibility/package.json packages/eligibility/tsconfig.json packages/eligibility/src/index.ts pnpm-lock.yaml
git commit -m "feat(eligibility): scaffold @mbfd/eligibility package"
```

---

## Task 2: Types

**Files:**
- Create: `packages/eligibility/src/types.ts`
- Modify: `packages/eligibility/src/index.ts`
- Test: `packages/eligibility/tests/unit/types.test.ts`

- [ ] **Step 1: Write failing test**

```ts
// packages/eligibility/tests/unit/types.test.ts
import { describe, it, expectTypeOf } from 'vitest';
import type {
  Member,
  Credential,
  PositionRule,
  PointsItem,
  EligibilityReason,
  PointsBreakdown,
  EligibilityResult,
  TieBreakKey,
} from '../../src/types.js';

describe('types (structural)', () => {
  it('Member has required fields', () => {
    expectTypeOf<Member>().toHaveProperty('employeeId');
    expectTypeOf<Member>().toHaveProperty('rank');
    expectTypeOf<Member>().toHaveProperty('rscSeniority');
    expectTypeOf<Member>().toHaveProperty('rankSeniority');
    expectTypeOf<Member>().toHaveProperty('isProbationary');
    expectTypeOf<Member>().toHaveProperty('credentials');
  });

  it('PositionRule has requiredCriteria and pointsPreference and tieBreakChain', () => {
    expectTypeOf<PositionRule>().toHaveProperty('requiredCriteria');
    expectTypeOf<PositionRule>().toHaveProperty('pointsPreference');
    expectTypeOf<PositionRule>().toHaveProperty('tieBreakChain');
  });

  it('EligibilityResult has eligible, reasons, points, soPoints, moPoints', () => {
    expectTypeOf<EligibilityResult>().toHaveProperty('eligible');
    expectTypeOf<EligibilityResult>().toHaveProperty('reasons');
    expectTypeOf<EligibilityResult>().toHaveProperty('points');
    expectTypeOf<EligibilityResult>().toHaveProperty('soPoints');
    expectTypeOf<EligibilityResult>().toHaveProperty('moPoints');
  });

  it('TieBreakKey union is exhaustive', () => {
    type Expected = 'points' | 'so_points' | 'mo_points' | 'rsc_seniority' | 'rank_seniority';
    expectTypeOf<TieBreakKey>().toEqualTypeOf<Expected>();
  });
});
```

- [ ] **Step 2: Run test, expect FAIL** (`pnpm --filter @mbfd/eligibility test` → cannot resolve `types.js`).

- [ ] **Step 3: Implement `packages/eligibility/src/types.ts`**

```ts
// packages/eligibility/src/types.ts

/** Rank codes, ordered most-senior to least-senior for comparison. */
export type Rank = 'CHIEF' | 'DEP_CHIEF' | 'DC' | 'CPT' | 'LT' | 'FF';

/** A single credential the member holds. */
export interface Credential {
  /** Canonical name matching reference_credentials.json */
  name: string;
}

/**
 * Plain-object member record.  Comes from the DB or test fixtures.
 * Must NOT include any DB-specific fields (ids, timestamps).
 */
export interface Member {
  employeeId: string;
  firstName: string;
  lastName: string;
  rank: Rank;
  /** Lower number = more senior in the RSC seniority list. */
  rscSeniority: number;
  /**
   * Lower number = more senior within the rank.
   * Undefined for members whose rank seniority has not been recorded.
   */
  rankSeniority: number | undefined;
  isProbationary: boolean;
  credentials: Credential[];
}

/** One scoreable item in a rule's points-preference list. */
export interface PointsItem {
  points: number;
  credential: string;
  /** If true, this credential's points count only if its Operations-pair is also held. */
  requiresOpsPair: boolean;
}

/** What credentials are mandatory (not just preferred) for a position. */
export interface RequiredCriteria {
  /** One or more ranks that are acceptable (OR logic). */
  rank: Rank[];
  /** Credential names that the member MUST hold (AND logic). */
  credentials: string[];
  /** Structured custom gates — 'paramedic' | 'driver_engineer' | 'non_probationary'. */
  custom: Array<'paramedic' | 'driver_engineer' | 'non_probationary'>;
}

export interface PointsPreference {
  /** Hard cap on total scoreable points (0 = no cap). */
  max: number;
  items: PointsItem[];
}

export type TieBreakKey =
  | 'points'
  | 'so_points'
  | 'mo_points'
  | 'rsc_seniority'
  | 'rank_seniority';

/** A complete rule for one position in one rule-book version. */
export interface PositionRule {
  positionId: string;
  ruleBookVersion: string;
  requiredCriteria: RequiredCriteria;
  pointsPreference: PointsPreference;
  tieBreakChain: TieBreakKey[];
}

/**
 * One item in the reasons array of EligibilityResult.
 * Consumed by the AI advisory in Plan 06 to build natural-language explanations.
 */
export interface EligibilityReason {
  /** Machine-stable code, e.g. "RANK_REQUIRED", "CERT_MISSING", "TECH_WITHOUT_OPS". */
  code: string;
  /** Human-readable label, e.g. "Requires Captain rank". */
  label: string;
  /** True = this criterion is satisfied; false = the member fails it. */
  satisfied: boolean;
}

/** Detailed points breakdown returned alongside the boolean result. */
export interface PointsBreakdown {
  /** Total scored points (capped at pointsPreference.max). */
  total: number;
  /** SO sub-pool total (see operations-techs.ts for the credential set). */
  soTotal: number;
  /** MO sub-pool total (see mo-pool.ts for the credential set). */
  moTotal: number;
  /** Per-item breakdown: credential name → points awarded. */
  itemized: Array<{ credential: string; awarded: number; reason?: string }>;
}

/** The result returned by evaluateEligibility(). */
export interface EligibilityResult {
  /** True if and only if ALL required criteria are satisfied. */
  eligible: boolean;
  /** Array of reasons — always populated, satisfied + unsatisfied alike. */
  reasons: EligibilityReason[];
  /** Total preference points (0 if not eligible). */
  points: number;
  /** Special Ops sub-pool points (0 if not eligible). */
  soPoints: number;
  /** Marine Ops sub-pool points (0 if not eligible). */
  moPoints: number;
  /** Full breakdown for debugging or AI consumption. */
  breakdown: PointsBreakdown;
}
```

- [ ] **Step 4: Update `packages/eligibility/src/index.ts`**

```ts
export type {
  Rank,
  Credential,
  Member,
  PointsItem,
  RequiredCriteria,
  PointsPreference,
  TieBreakKey,
  PositionRule,
  EligibilityReason,
  PointsBreakdown,
  EligibilityResult,
} from './types.js';
```

- [ ] **Step 5: Run test, expect PASS** (`pnpm --filter @mbfd/eligibility test`).

- [ ] **Step 6: Commit**

```bash
git add packages/eligibility/src/types.ts packages/eligibility/src/index.ts packages/eligibility/tests/unit/types.test.ts
git commit -m "feat(eligibility): define Member, PositionRule, EligibilityResult types"
```

---

## Task 3: Operations/Technician registry

**Files:**
- Create: `packages/eligibility/src/operations-techs.ts`
- Test: `packages/eligibility/tests/unit/operations-techs.test.ts`

The six paired credentials documented in `2026_Rules_and_Points.md §8` are represented as a `const` array so that:
1. `sum.ts` can iterate them when computing the Ops-gates-Tech rule.
2. The AI advisory in Plan 06 can enumerate them for the explanation narrative.

- [ ] **Step 1: Write failing test**

```ts
// packages/eligibility/tests/unit/operations-techs.test.ts
import { describe, it, expect } from 'vitest';
import { OP_TECH_PAIRS, opCredNames, techCredNames } from '../../src/operations-techs.js';

describe('OP_TECH_PAIRS', () => {
  it('contains exactly 6 pairs', () => {
    expect(OP_TECH_PAIRS).toHaveLength(6);
  });

  it('each pair has ops and tech string', () => {
    for (const pair of OP_TECH_PAIRS) {
      expect(typeof pair.ops).toBe('string');
      expect(typeof pair.tech).toBe('string');
      expect(pair.ops.length).toBeGreaterThan(0);
      expect(pair.tech.length).toBeGreaterThan(0);
    }
  });

  it('opCredNames returns 6 unique ops credential names', () => {
    const names = opCredNames();
    expect(names).toHaveLength(6);
    expect(new Set(names).size).toBe(6);
  });

  it('techCredNames returns 6 unique tech credential names', () => {
    const names = techCredNames();
    expect(names).toHaveLength(6);
    expect(new Set(names).size).toBe(6);
  });

  it('Hazardous Materials pair is present with exact names from 2026 rulebook', () => {
    const hazmat = OP_TECH_PAIRS.find((p) => p.ops === 'Hazardous Materials Operations');
    expect(hazmat).toBeDefined();
    expect(hazmat!.tech).toBe('State Certified Hazardous Materials Technician');
  });

  it('Rope Rescue pair is present', () => {
    const rope = OP_TECH_PAIRS.find((p) => p.ops === 'Rope Rescue Operations');
    expect(rope).toBeDefined();
    expect(rope!.tech).toBe('Rope Rescue Technician');
  });
});
```

- [ ] **Step 2: Run test, expect FAIL**

- [ ] **Step 3: Implement `packages/eligibility/src/operations-techs.ts`**

```ts
// packages/eligibility/src/operations-techs.ts
// Source: 2026_Rules_and_Points.md §8
// These exact strings must match the credential names in reference_credentials.json
// and any rule's pointsPreference.items[].credential fields.

export interface OpTechPair {
  /** Operations-level credential name. */
  ops: string;
  /** Paired Technician-level credential name. */
  tech: string;
}

/**
 * The six Operations → Technician credential pairs.
 *
 * Rule: if a PointsItem has requiresOpsPair === true, the member MUST also hold
 * the corresponding ops credential, otherwise the tech points are not scored.
 *
 * Holding all 6 ops certs is a prerequisite to score ANY tech points
 * in rule blocks that carry the "Operations gates Technician" flag.
 */
export const OP_TECH_PAIRS: readonly OpTechPair[] = [
  {
    ops: 'Hazardous Materials Operations',
    tech: 'State Certified Hazardous Materials Technician',
  },
  {
    ops: 'Rope Rescue Operations',
    tech: 'Rope Rescue Technician',
  },
  {
    ops: 'Confined Space Operations',
    tech: 'Confined Space Technician',
  },
  {
    ops: 'Structural Collapse Operations',
    tech: 'Structural Collapse Technician',
  },
  {
    ops: 'Trench Rescue Operations',
    tech: 'Trench Rescue Technician',
  },
  {
    ops: 'Vehicle & Machinery Rescue Operations',
    tech: 'Vehicle & Machinery Rescue Technician',
  },
] as const;

/** Returns the list of all six Operations credential names. */
export function opCredNames(): string[] {
  return OP_TECH_PAIRS.map((p) => p.ops);
}

/** Returns the list of all six Technician credential names. */
export function techCredNames(): string[] {
  return OP_TECH_PAIRS.map((p) => p.tech);
}

/**
 * Given a tech credential name, returns the paired ops credential name,
 * or undefined if this tech cert has no registered pair.
 */
export function opsForTech(techName: string): string | undefined {
  return OP_TECH_PAIRS.find((p) => p.tech === techName)?.ops;
}

/**
 * Returns true if the member holds all 6 Operations-level credentials.
 * Used as the gate check in rule blocks with "Operations gates Technician".
 */
export function holdsAllOps(credentialNames: ReadonlySet<string>): boolean {
  return OP_TECH_PAIRS.every((p) => credentialNames.has(p.ops));
}
```

- [ ] **Step 4: Run test, expect PASS**

- [ ] **Step 5: Export from index**

```ts
// append to packages/eligibility/src/index.ts
export { OP_TECH_PAIRS, opCredNames, techCredNames, opsForTech, holdsAllOps } from './operations-techs.js';
```

- [ ] **Step 6: Commit**

```bash
git add packages/eligibility/src/operations-techs.ts packages/eligibility/src/index.ts packages/eligibility/tests/unit/operations-techs.test.ts
git commit -m "feat(eligibility): Op/Tech pair registry with gate helpers"
```

---

## Task 4: Required-criteria evaluators

**Files:**
- Create: `packages/eligibility/src/criteria/rank.ts`
- Create: `packages/eligibility/src/criteria/paramedic.ts`
- Create: `packages/eligibility/src/criteria/driver-engineer.ts`
- Create: `packages/eligibility/src/criteria/non-probationary.ts`
- Create: `packages/eligibility/src/criteria/certs.ts`
- Tests: `packages/eligibility/tests/unit/rank.test.ts`, `paramedic.test.ts`, `driver-engineer.test.ts`, `non-probationary.test.ts`, `certs.test.ts`

### Sub-task 4a: Rank evaluator

- [ ] **Step 1: Write failing test**

```ts
// packages/eligibility/tests/unit/rank.test.ts
import { describe, it, expect } from 'vitest';
import { rankSatisfied } from '../../src/criteria/rank.js';
import type { Member, RequiredCriteria } from '../../src/types.js';

const baseMember = (rank: Member['rank']): Member => ({
  employeeId: '99999',
  firstName: 'Test',
  lastName: 'User',
  rank,
  rscSeniority: 50,
  rankSeniority: 10,
  isProbationary: false,
  credentials: [],
});

describe('rankSatisfied', () => {
  it('FF satisfies FF-required position', () => {
    const r = rankSatisfied(baseMember('FF'), ['FF']);
    expect(r.satisfied).toBe(true);
    expect(r.code).toBe('RANK_OK');
  });

  it('LT satisfies LT-required position', () => {
    const r = rankSatisfied(baseMember('LT'), ['LT']);
    expect(r.satisfied).toBe(true);
  });

  it('FF fails LT-required position', () => {
    const r = rankSatisfied(baseMember('FF'), ['LT']);
    expect(r.satisfied).toBe(false);
    expect(r.code).toBe('RANK_REQUIRED');
    expect(r.label).toMatch(/Lieutenant/);
  });

  it('CPT satisfies CPT-required position', () => {
    expect(rankSatisfied(baseMember('CPT'), ['CPT']).satisfied).toBe(true);
  });

  it('DC fails CPT-required position (no upward substitution)', () => {
    // Division Chiefs bid their own pool; they cannot fill CPT slots in the
    // general bid. Rank satisfaction is exact match within the allowed list.
    expect(rankSatisfied(baseMember('DC'), ['CPT']).satisfied).toBe(false);
  });

  it('multiple allowed ranks — CPT satisfies [FF, LT, CPT]', () => {
    expect(rankSatisfied(baseMember('CPT'), ['FF', 'LT', 'CPT']).satisfied).toBe(true);
  });
});
```

- [ ] **Step 2: Run test, expect FAIL**

- [ ] **Step 3: Implement `packages/eligibility/src/criteria/rank.ts`**

```ts
// packages/eligibility/src/criteria/rank.ts
import type { Member, EligibilityReason, Rank } from '../types.js';

const RANK_LABEL: Record<Rank, string> = {
  CHIEF: 'Fire Chief',
  DEP_CHIEF: 'Deputy Fire Chief',
  DC: 'Division Chief',
  CPT: 'Captain',
  LT: 'Lieutenant',
  FF: 'Firefighter',
};

/**
 * Returns an EligibilityReason indicating whether the member's rank is in
 * the allowedRanks list for this position.
 * Matching is exact — ranks are not hierarchical for bid eligibility.
 */
export function rankSatisfied(member: Member, allowedRanks: Rank[]): EligibilityReason {
  const satisfied = allowedRanks.includes(member.rank);
  if (satisfied) {
    return {
      code: 'RANK_OK',
      label: `Rank ${RANK_LABEL[member.rank]} is eligible for this position`,
      satisfied: true,
    };
  }
  const required = allowedRanks.map((r) => RANK_LABEL[r]).join(' or ');
  return {
    code: 'RANK_REQUIRED',
    label: `Requires ${required}; member holds ${RANK_LABEL[member.rank]}`,
    satisfied: false,
  };
}
```

- [ ] **Step 4: Run test, expect PASS**

### Sub-task 4b: Paramedic, Driver Engineer, Non-probationary evaluators

- [ ] **Step 1: Write failing tests**

```ts
// packages/eligibility/tests/unit/paramedic.test.ts
import { describe, it, expect } from 'vitest';
import { paramedicSatisfied } from '../../src/criteria/paramedic.js';
import type { Member } from '../../src/types.js';

const withCreds = (names: string[]): Member => ({
  employeeId: '1', firstName: 'A', lastName: 'B', rank: 'FF',
  rscSeniority: 1, rankSeniority: 1, isProbationary: false,
  credentials: names.map((name) => ({ name })),
});

describe('paramedicSatisfied', () => {
  it('member with Paramedic cert satisfies', () => {
    const r = paramedicSatisfied(withCreds(['Paramedic']));
    expect(r.satisfied).toBe(true);
    expect(r.code).toBe('PARAMEDIC_OK');
  });

  it('member without Paramedic cert fails', () => {
    const r = paramedicSatisfied(withCreds(['EMT-Basic']));
    expect(r.satisfied).toBe(false);
    expect(r.code).toBe('PARAMEDIC_REQUIRED');
  });
});
```

```ts
// packages/eligibility/tests/unit/driver-engineer.test.ts
import { describe, it, expect } from 'vitest';
import { driverEngineerSatisfied } from '../../src/criteria/driver-engineer.js';
import type { Member } from '../../src/types.js';

const withCreds = (names: string[]): Member => ({
  employeeId: '1', firstName: 'A', lastName: 'B', rank: 'FF',
  rscSeniority: 1, rankSeniority: 1, isProbationary: false,
  credentials: names.map((name) => ({ name })),
});

describe('driverEngineerSatisfied', () => {
  it('holds Driver Engineer Qualified credential — satisfied', () => {
    expect(driverEngineerSatisfied(withCreds(['Driver Engineer Qualified'])).satisfied).toBe(true);
  });

  it('holds Fire Apparatus Ops + Fire Service Hydraulics — satisfied (dual-path)', () => {
    expect(driverEngineerSatisfied(withCreds([
      'Fire Apparatus Operations (FFP-1302)',
      'Fire Service Hydraulics (FFP1301)',
    ])).satisfied).toBe(true);
  });

  it('holds FL Pump Operator — satisfied (single-path)', () => {
    expect(driverEngineerSatisfied(withCreds(['Florida Pump Operator'])).satisfied).toBe(true);
  });

  it('holds only Fire Apparatus Ops (missing Hydraulics) — not satisfied', () => {
    expect(driverEngineerSatisfied(withCreds(['Fire Apparatus Operations (FFP-1302)'])).satisfied).toBe(false);
  });

  it('holds nothing — not satisfied', () => {
    expect(driverEngineerSatisfied(withCreds([])).satisfied).toBe(false);
  });
});
```

```ts
// packages/eligibility/tests/unit/non-probationary.test.ts
import { describe, it, expect } from 'vitest';
import { nonProbationarySatisfied } from '../../src/criteria/non-probationary.js';
import type { Member } from '../../src/types.js';

const member = (isProbationary: boolean): Member => ({
  employeeId: '1', firstName: 'A', lastName: 'B', rank: 'FF',
  rscSeniority: 1, rankSeniority: 1, isProbationary,
  credentials: [],
});

describe('nonProbationarySatisfied', () => {
  it('non-probationary member satisfies', () => {
    expect(nonProbationarySatisfied(member(false)).satisfied).toBe(true);
  });

  it('probationary member fails', () => {
    const r = nonProbationarySatisfied(member(true));
    expect(r.satisfied).toBe(false);
    expect(r.code).toBe('PROBATIONARY_RESTRICTED');
  });
});
```

- [ ] **Step 2: Run tests, expect FAIL**

- [ ] **Step 3: Implement `packages/eligibility/src/criteria/paramedic.ts`**

```ts
// packages/eligibility/src/criteria/paramedic.ts
import type { Member, EligibilityReason } from '../types.js';

/** Credential names that satisfy the Paramedic requirement (any one is sufficient). */
const PARAMEDIC_CREDS = new Set(['Paramedic']);

export function paramedicSatisfied(member: Member): EligibilityReason {
  const has = member.credentials.some((c) => PARAMEDIC_CREDS.has(c.name));
  return has
    ? { code: 'PARAMEDIC_OK', label: 'Holds active Paramedic credential', satisfied: true }
    : { code: 'PARAMEDIC_REQUIRED', label: 'Active Paramedic certification required', satisfied: false };
}
```

- [ ] **Step 4: Implement `packages/eligibility/src/criteria/driver-engineer.ts`**

```ts
// packages/eligibility/src/criteria/driver-engineer.ts
// Source: 2026_Rules_and_Points.md — three acceptable paths to DE qualification:
//   Path A: "Driver Engineer Qualified" (composite cert from the department)
//   Path B: Fire Apparatus Operations (FFP-1302) + Fire Service Hydraulics (FFP1301)
//   Path C: Florida Pump Operator (equivalent to both Path B components)
import type { Member, EligibilityReason } from '../types.js';

const DE_QUALIFIED = 'Driver Engineer Qualified';
const FAO = 'Fire Apparatus Operations (FFP-1302)';
const HYDRAULICS = 'Fire Service Hydraulics (FFP1301)';
const FL_PUMP = 'Florida Pump Operator';

export function driverEngineerSatisfied(member: Member): EligibilityReason {
  const names = new Set(member.credentials.map((c) => c.name));
  const satisfied =
    names.has(DE_QUALIFIED) ||
    (names.has(FAO) && names.has(HYDRAULICS)) ||
    names.has(FL_PUMP);

  return satisfied
    ? { code: 'DE_OK', label: 'Driver Engineer qualification satisfied', satisfied: true }
    : {
        code: 'DE_REQUIRED',
        label:
          'Driver Engineer qualification required (DE Qualified, OR Fire Apparatus Ops + Hydraulics, OR FL Pump Operator)',
        satisfied: false,
      };
}
```

- [ ] **Step 5: Implement `packages/eligibility/src/criteria/non-probationary.ts`**

```ts
// packages/eligibility/src/criteria/non-probationary.ts
import type { Member, EligibilityReason } from '../types.js';

export function nonProbationarySatisfied(member: Member): EligibilityReason {
  return member.isProbationary
    ? {
        code: 'PROBATIONARY_RESTRICTED',
        label: 'Position restricted to non-probationary members',
        satisfied: false,
      }
    : {
        code: 'NON_PROBATIONARY_OK',
        label: 'Member is non-probationary',
        satisfied: true,
      };
}
```

### Sub-task 4c: Required credentials evaluator

- [ ] **Step 1: Write failing test**

```ts
// packages/eligibility/tests/unit/certs.test.ts
import { describe, it, expect } from 'vitest';
import { requiredCredsSatisfied } from '../../src/criteria/certs.js';
import type { Member } from '../../src/types.js';

const withCreds = (names: string[]): Member => ({
  employeeId: '1', firstName: 'A', lastName: 'B', rank: 'FF',
  rscSeniority: 1, rankSeniority: 1, isProbationary: false,
  credentials: names.map((name) => ({ name })),
});

describe('requiredCredsSatisfied', () => {
  it('returns empty array when no credentials required', () => {
    const reasons = requiredCredsSatisfied(withCreds([]), []);
    expect(reasons).toHaveLength(0);
  });

  it('member holds the required cert — satisfied', () => {
    const reasons = requiredCredsSatisfied(
      withCreds(['Hazardous Materials Operations']),
      ['Hazardous Materials Operations'],
    );
    expect(reasons).toHaveLength(1);
    expect(reasons[0]!.satisfied).toBe(true);
    expect(reasons[0]!.code).toBe('CRED_OK');
  });

  it('member missing one of two required certs — one unsatisfied', () => {
    const reasons = requiredCredsSatisfied(
      withCreds(['Hazardous Materials Operations']),
      ['Hazardous Materials Operations', 'Merchant Mariner Credential (MMC)'],
    );
    expect(reasons).toHaveLength(2);
    const missing = reasons.find((r) => !r.satisfied);
    expect(missing).toBeDefined();
    expect(missing!.code).toBe('CRED_MISSING');
    expect(missing!.label).toMatch(/Merchant Mariner/);
  });

  it('returns one reason per required credential', () => {
    const required = ['Cred A', 'Cred B', 'Cred C'];
    const reasons = requiredCredsSatisfied(withCreds(['Cred A']), required);
    expect(reasons).toHaveLength(3);
  });
});
```

- [ ] **Step 2: Run test, expect FAIL**

- [ ] **Step 3: Implement `packages/eligibility/src/criteria/certs.ts`**

```ts
// packages/eligibility/src/criteria/certs.ts
import type { Member, EligibilityReason } from '../types.js';

/**
 * For each credential name in requiredCredentialNames, returns one EligibilityReason
 * indicating whether the member holds it.
 * Returns an empty array when requiredCredentialNames is empty.
 */
export function requiredCredsSatisfied(
  member: Member,
  requiredCredentialNames: string[],
): EligibilityReason[] {
  const held = new Set(member.credentials.map((c) => c.name));
  return requiredCredentialNames.map((name) => {
    const satisfied = held.has(name);
    return satisfied
      ? { code: 'CRED_OK', label: `Holds required: ${name}`, satisfied: true }
      : { code: 'CRED_MISSING', label: `Missing required: ${name}`, satisfied: false };
  });
}
```

- [ ] **Step 4: Run all criteria tests, expect PASS**

- [ ] **Step 5: Commit**

```bash
git add packages/eligibility/src/criteria/ packages/eligibility/tests/unit/rank.test.ts packages/eligibility/tests/unit/paramedic.test.ts packages/eligibility/tests/unit/driver-engineer.test.ts packages/eligibility/tests/unit/non-probationary.test.ts packages/eligibility/tests/unit/certs.test.ts
git commit -m "feat(eligibility): required-criteria evaluators (rank, paramedic, DE, non-prob, certs)"
```

---

## Task 5: Points engine

**Files:**
- Create: `packages/eligibility/src/points/sum.ts`
- Test: `packages/eligibility/tests/unit/sum.test.ts`

The points engine sums `PointsItem` values for credentials held by the member. Critical rules:
1. If `requiresOpsPair === true` on a PointsItem (i.e. it is a Technician cert), the member must ALSO hold the corresponding Operations cert; otherwise 0 points are awarded for that item.
2. If the rule uses the blanket "all 6 ops required before any tech counts" mode (`requiresAllOps === true`), the member must hold all 6 Operations certs before any Technician points are awarded.
3. Total is capped at `pointsPreference.max` (if max > 0).

- [ ] **Step 1: Write failing test**

```ts
// packages/eligibility/tests/unit/sum.test.ts
import { describe, it, expect } from 'vitest';
import { computePoints } from '../../src/points/sum.js';
import type { Member, PositionRule } from '../../src/types.js';

const member = (credNames: string[]): Member => ({
  employeeId: '1', firstName: 'A', lastName: 'B', rank: 'FF',
  rscSeniority: 1, rankSeniority: 1, isProbationary: false,
  credentials: credNames.map((name) => ({ name })),
});

const rule = (items: PositionRule['pointsPreference']['items'], max = 0): PositionRule => ({
  positionId: 'TEST',
  ruleBookVersion: '2026.1',
  requiredCriteria: { rank: ['FF'], credentials: [], custom: [] },
  pointsPreference: { max, items },
  tieBreakChain: ['points', 'rsc_seniority', 'rank_seniority'],
});

describe('computePoints', () => {
  it('returns 0 for member with no matching credentials', () => {
    const r = computePoints(member([]), rule([{ points: 2, credential: 'Car Seat Technician', requiresOpsPair: false }]));
    expect(r.total).toBe(0);
  });

  it('awards points for a matching plain credential (requiresOpsPair false)', () => {
    const r = computePoints(
      member(['Car Seat Technician']),
      rule([{ points: 2, credential: 'Car Seat Technician', requiresOpsPair: false }]),
    );
    expect(r.total).toBe(2);
  });

  it('awards tech points only when ops pair is also held', () => {
    const items = [
      { points: 1, credential: 'Hazardous Materials Operations', requiresOpsPair: false },
      { points: 1, credential: 'State Certified Hazardous Materials Technician', requiresOpsPair: true },
    ];
    // Member holds both — should get 2
    const r1 = computePoints(member(['Hazardous Materials Operations', 'State Certified Hazardous Materials Technician']), rule(items));
    expect(r1.total).toBe(2);
    // Member holds only tech — tech points suppressed
    const r2 = computePoints(member(['State Certified Hazardous Materials Technician']), rule(items));
    expect(r2.total).toBe(0);
  });

  it('caps total at pointsPreference.max when max > 0', () => {
    const items = [
      { points: 3, credential: 'Cred A', requiresOpsPair: false },
      { points: 3, credential: 'Cred B', requiresOpsPair: false },
    ];
    const r = computePoints(member(['Cred A', 'Cred B']), rule(items, 5));
    expect(r.total).toBe(5);
  });

  it('max=0 means no cap — awards full sum', () => {
    const items = [
      { points: 3, credential: 'Cred A', requiresOpsPair: false },
      { points: 3, credential: 'Cred B', requiresOpsPair: false },
    ];
    const r = computePoints(member(['Cred A', 'Cred B']), rule(items, 0));
    expect(r.total).toBe(6);
  });

  it('itemized breakdown lists awarded amount per credential', () => {
    const items = [{ points: 2, credential: 'Car Seat Technician', requiresOpsPair: false }];
    const r = computePoints(member(['Car Seat Technician']), rule(items));
    expect(r.itemized).toContainEqual(expect.objectContaining({ credential: 'Car Seat Technician', awarded: 2 }));
  });

  it('itemized lists 0 for tech held without ops pair', () => {
    const items = [
      { points: 1, credential: 'State Certified Hazardous Materials Technician', requiresOpsPair: true },
    ];
    const r = computePoints(member(['State Certified Hazardous Materials Technician']), rule(items));
    const item = r.itemized.find((i) => i.credential === 'State Certified Hazardous Materials Technician');
    expect(item?.awarded).toBe(0);
    expect(item?.reason).toMatch(/Operations/);
  });
});
```

- [ ] **Step 2: Run test, expect FAIL**

- [ ] **Step 3: Implement `packages/eligibility/src/points/sum.ts`**

```ts
// packages/eligibility/src/points/sum.ts
import type { Member, PositionRule, PointsBreakdown } from '../types.js';
import { opsForTech } from '../operations-techs.js';

/**
 * Computes the points breakdown for a member against a position's points-preference.
 *
 * Scoring rules:
 *   1. For each PointsItem, check if the member holds the credential.
 *   2. If requiresOpsPair === true, also verify the member holds the paired
 *      Operations credential (looked up via opsForTech). If the ops cert is
 *      missing, award 0 for this item and record a reason.
 *   3. Sum awarded points; cap at pointsPreference.max if max > 0.
 *
 * This function does NOT set soTotal or moTotal — those are computed by the
 * so-pool.ts and mo-pool.ts modules and stitched together in evaluate.ts.
 */
export function computePoints(member: Member, rule: PositionRule): PointsBreakdown {
  const heldCreds = new Set(member.credentials.map((c) => c.name));
  const itemized: PointsBreakdown['itemized'] = [];
  let rawTotal = 0;

  for (const item of rule.pointsPreference.items) {
    if (!heldCreds.has(item.credential)) {
      itemized.push({ credential: item.credential, awarded: 0 });
      continue;
    }

    if (item.requiresOpsPair) {
      const requiredOps = opsForTech(item.credential);
      if (requiredOps !== undefined && !heldCreds.has(requiredOps)) {
        itemized.push({
          credential: item.credential,
          awarded: 0,
          reason: `Technician cert requires paired Operations cert: ${requiredOps}`,
        });
        continue;
      }
    }

    itemized.push({ credential: item.credential, awarded: item.points });
    rawTotal += item.points;
  }

  const max = rule.pointsPreference.max;
  const total = max > 0 ? Math.min(rawTotal, max) : rawTotal;

  return { total, soTotal: 0, moTotal: 0, itemized };
}
```

- [ ] **Step 4: Run test, expect PASS**

- [ ] **Step 5: Commit**

```bash
git add packages/eligibility/src/points/sum.ts packages/eligibility/tests/unit/sum.test.ts
git commit -m "feat(eligibility): points engine with Op/Tech gate enforcement"
```

---

## Task 6: SO and MO sub-pool calculators

**Files:**
- Create: `packages/eligibility/src/points/so-pool.ts`
- Create: `packages/eligibility/src/points/mo-pool.ts`
- Tests: `packages/eligibility/tests/unit/so-pool.test.ts`, `mo-pool.test.ts`

SO and MO pools are fixed credential sets defined in `2026_Rules_and_Points.md §9`.

- [ ] **Step 1: Write failing tests**

```ts
// packages/eligibility/tests/unit/so-pool.test.ts
import { describe, it, expect } from 'vitest';
import { computeSoPoints, SO_CREDENTIAL_NAMES } from '../../src/points/so-pool.js';
import type { Member } from '../../src/types.js';

const member = (credNames: string[]): Member => ({
  employeeId: '1', firstName: 'A', lastName: 'B', rank: 'FF',
  rscSeniority: 1, rankSeniority: 1, isProbationary: false,
  credentials: credNames.map((name) => ({ name })),
});

describe('SO sub-pool', () => {
  it('SO_CREDENTIAL_NAMES includes all 6 Ops certs', () => {
    expect(SO_CREDENTIAL_NAMES).toContain('Hazardous Materials Operations');
    expect(SO_CREDENTIAL_NAMES).toContain('Rope Rescue Operations');
    expect(SO_CREDENTIAL_NAMES).toContain('Confined Space Operations');
    expect(SO_CREDENTIAL_NAMES).toContain('Structural Collapse Operations');
    expect(SO_CREDENTIAL_NAMES).toContain('Trench Rescue Operations');
    expect(SO_CREDENTIAL_NAMES).toContain('Vehicle & Machinery Rescue Operations');
  });

  it('SO_CREDENTIAL_NAMES includes all 6 Tech certs', () => {
    expect(SO_CREDENTIAL_NAMES).toContain('State Certified Hazardous Materials Technician');
    expect(SO_CREDENTIAL_NAMES).toContain('Rope Rescue Technician');
  });

  it('SO_CREDENTIAL_NAMES includes Drone Operator', () => {
    expect(SO_CREDENTIAL_NAMES).toContain('Drone Operator Qualified-Part 107 sUAS');
  });

  it('member with all 6 ops + all 6 tech + drone scores 13', () => {
    const allSO = [...SO_CREDENTIAL_NAMES];
    const score = computeSoPoints(member(allSO));
    expect(score).toBe(13);
  });

  it('member with only 3 ops certs scores 3', () => {
    const score = computeSoPoints(member([
      'Hazardous Materials Operations',
      'Rope Rescue Operations',
      'Confined Space Operations',
    ]));
    expect(score).toBe(3);
  });

  it('member with no SO creds scores 0', () => {
    expect(computeSoPoints(member(['Car Seat Technician']))).toBe(0);
  });
});
```

```ts
// packages/eligibility/tests/unit/mo-pool.test.ts
import { describe, it, expect } from 'vitest';
import { computeMoPoints, MO_CREDENTIAL_NAMES } from '../../src/points/mo-pool.js';
import type { Member } from '../../src/types.js';

const member = (credNames: string[]): Member => ({
  employeeId: '1', firstName: 'A', lastName: 'B', rank: 'FF',
  rscSeniority: 1, rankSeniority: 1, isProbationary: false,
  credentials: credNames.map((name) => ({ name })),
});

describe('MO sub-pool', () => {
  it('MO_CREDENTIAL_NAMES includes MMC, IADRS, Open Water Diver, PSD, Hazmat, Car Seat', () => {
    expect(MO_CREDENTIAL_NAMES).toContain('Merchant Mariner Credential (MMC)');
    expect(MO_CREDENTIAL_NAMES).toContain('IADRS Swim Evaluation');
    expect(MO_CREDENTIAL_NAMES).toContain('Open Water Diver Certified');
    expect(MO_CREDENTIAL_NAMES).toContain('Certified Public Safety Diver');
    expect(MO_CREDENTIAL_NAMES).toContain('Hazardous Materials Operations');
    expect(MO_CREDENTIAL_NAMES).toContain('Car Seat Technician');
  });

  it('member with all MO creds scores 6', () => {
    const score = computeMoPoints(member([...MO_CREDENTIAL_NAMES]));
    expect(score).toBe(6);
  });

  it('member with MMC + IADRS scores 2', () => {
    expect(computeMoPoints(member(['Merchant Mariner Credential (MMC)', 'IADRS Swim Evaluation']))).toBe(2);
  });

  it('member with no MO creds scores 0', () => {
    expect(computeMoPoints(member([]))).toBe(0);
  });
});
```

- [ ] **Step 2: Run tests, expect FAIL**

- [ ] **Step 3: Implement `packages/eligibility/src/points/so-pool.ts`**

```ts
// packages/eligibility/src/points/so-pool.ts
// Source: 2026_Rules_and_Points.md §9
// SO pool = six Ops + six Tech + Drone Operator.
// Each credential is worth 1 point in this pool regardless of position-specific
// point values; the pool is used only for tie-breaking, not scoring.
import type { Member } from '../types.js';
import { opCredNames, techCredNames } from '../operations-techs.js';

export const SO_CREDENTIAL_NAMES: readonly string[] = [
  ...opCredNames(),
  ...techCredNames(),
  'Drone Operator Qualified-Part 107 sUAS',
] as const;

/**
 * Returns the member's SO (Special Operations) sub-pool score.
 * Each SO credential held counts as 1 point.
 */
export function computeSoPoints(member: Member): number {
  const held = new Set(member.credentials.map((c) => c.name));
  const soSet = new Set(SO_CREDENTIAL_NAMES);
  let score = 0;
  for (const name of held) {
    if (soSet.has(name)) score++;
  }
  return score;
}
```

- [ ] **Step 4: Implement `packages/eligibility/src/points/mo-pool.ts`**

```ts
// packages/eligibility/src/points/mo-pool.ts
// Source: 2026_Rules_and_Points.md §9
// MO pool = MMC + IADRS + Open Water Diver + PSD + Hazmat Ops/Awareness + Car Seat.
// Hazmat Ops or Hazmat Awareness each count (either satisfies).
import type { Member } from '../types.js';

export const MO_CREDENTIAL_NAMES: readonly string[] = [
  'Merchant Mariner Credential (MMC)',
  'IADRS Swim Evaluation',
  'Open Water Diver Certified',
  'Certified Public Safety Diver',
  'Hazardous Materials Operations',
  'Car Seat Technician',
] as const;

// Hazmat Awareness is an alternate for Hazmat Operations in the MO pool.
const MO_ALTERNATES: ReadonlyMap<string, string> = new Map([
  ['Hazmat Awareness Level', 'Hazardous Materials Operations'],
]);

/**
 * Returns the member's MO (Marine Operations) sub-pool score.
 * Each MO credential held counts as 1 point.
 * Hazmat Awareness can substitute for Hazmat Operations (OR logic).
 */
export function computeMoPoints(member: Member): number {
  const held = new Set(member.credentials.map((c) => c.name));
  // Expand alternates so 'Hazmat Awareness Level' also counts for the MO slot
  for (const [alternate, canonical] of MO_ALTERNATES) {
    if (held.has(alternate)) held.add(canonical);
  }
  const moSet = new Set(MO_CREDENTIAL_NAMES);
  let score = 0;
  for (const name of held) {
    if (moSet.has(name)) score++;
  }
  return score;
}
```

- [ ] **Step 5: Run tests, expect PASS**

- [ ] **Step 6: Commit**

```bash
git add packages/eligibility/src/points/so-pool.ts packages/eligibility/src/points/mo-pool.ts packages/eligibility/tests/unit/so-pool.test.ts packages/eligibility/tests/unit/mo-pool.test.ts
git commit -m "feat(eligibility): SO and MO sub-pool calculators"
```

---

## Task 7: Tie-break chain

**Files:**
- Create: `packages/eligibility/src/tie-break.ts`
- Test: `packages/eligibility/tests/unit/tie-break.test.ts`

The tie-break chain is a sorted list of `TieBreakKey` values from the position rule. The `compare` function applies each key in order until a non-zero result is found.

Sorting conventions per key:
- `points`: higher is better (descending).
- `so_points`: higher is better (descending).
- `mo_points`: higher is better (descending).
- `rsc_seniority`: lower number = more senior (ascending).
- `rank_seniority`: lower number = more senior (ascending).

- [ ] **Step 1: Write failing test**

```ts
// packages/eligibility/tests/unit/tie-break.test.ts
import { describe, it, expect } from 'vitest';
import { compare } from '../../src/tie-break.js';
import type { EligibilityResult, TieBreakKey } from '../../src/types.js';

const result = (overrides: Partial<{
  points: number; soPoints: number; moPoints: number;
  rscSeniority: number; rankSeniority: number;
}>): EligibilityResult & { rscSeniority: number; rankSeniority: number } => ({
  eligible: true,
  reasons: [],
  points: 0,
  soPoints: 0,
  moPoints: 0,
  breakdown: { total: 0, soTotal: 0, moTotal: 0, itemized: [] },
  rscSeniority: 50,
  rankSeniority: 10,
  ...overrides,
});

const chain: TieBreakKey[] = ['points', 'so_points', 'rsc_seniority', 'rank_seniority'];

describe('compare', () => {
  it('a has more points than b → a wins (-1)', () => {
    expect(compare(result({ points: 5 }), result({ points: 3 }), chain)).toBe(-1);
  });

  it('b has more points than a → b wins (1)', () => {
    expect(compare(result({ points: 2 }), result({ points: 8 }), chain)).toBe(1);
  });

  it('equal points, a has lower rsc_seniority → a wins (-1)', () => {
    expect(compare(result({ points: 5, rscSeniority: 10 }), result({ points: 5, rscSeniority: 20 }), chain)).toBe(-1);
  });

  it('equal points, equal rsc_seniority, a has lower rank_seniority → a wins (-1)', () => {
    expect(compare(
      result({ points: 5, rscSeniority: 10, rankSeniority: 2 }),
      result({ points: 5, rscSeniority: 10, rankSeniority: 5 }),
      chain,
    )).toBe(-1);
  });

  it('completely equal → returns 0', () => {
    expect(compare(result({ points: 5, rscSeniority: 10, rankSeniority: 2 }),
                   result({ points: 5, rscSeniority: 10, rankSeniority: 2 }), chain)).toBe(0);
  });

  it('so_points key is used when chain includes it', () => {
    const soChain: TieBreakKey[] = ['points', 'so_points', 'rsc_seniority', 'rank_seniority'];
    const a = result({ points: 5, soPoints: 10, rscSeniority: 20 });
    const b = result({ points: 5, soPoints: 7, rscSeniority: 5 });
    // a has higher SO points → a wins before rsc_seniority is consulted
    expect(compare(a, b, soChain)).toBe(-1);
  });

  it('mo_points key is used when chain includes it', () => {
    const moChain: TieBreakKey[] = ['points', 'mo_points', 'rsc_seniority', 'rank_seniority'];
    const a = result({ points: 4, moPoints: 6, rscSeniority: 30 });
    const b = result({ points: 4, moPoints: 6, rscSeniority: 5 });
    // equal MO, b has lower rsc → b wins
    expect(compare(a, b, moChain)).toBe(1);
  });

  it('stability: compare(a, b) === -compare(b, a) for unequal cases', () => {
    const a = result({ points: 7 });
    const b = result({ points: 3 });
    expect(compare(a, b, chain)).toBe(-compare(b, a, chain));
  });
});
```

- [ ] **Step 2: Run test, expect FAIL**

- [ ] **Step 3: Implement `packages/eligibility/src/tie-break.ts`**

```ts
// packages/eligibility/src/tie-break.ts
import type { EligibilityResult, TieBreakKey } from './types.js';

/**
 * Extended result type that carries seniority values alongside the
 * EligibilityResult so compare() has access to them without needing the
 * full Member object.
 */
export interface ComparableResult extends EligibilityResult {
  /** RSC seniority of the member (lower = more senior). */
  rscSeniority: number;
  /**
   * Rank seniority of the member (lower = more senior within rank).
   * Use Number.MAX_SAFE_INTEGER when undefined so undefined sorts last.
   */
  rankSeniority: number;
}

/**
 * Compares two eligible members for a specific position using the position's
 * tieBreakChain.
 *
 * Returns:
 *   -1  if a should be offered the position before b
 *    0  if they are perfectly tied across all keys
 *    1  if b should be offered the position before a
 *
 * Sorting conventions:
 *   points, so_points, mo_points → higher is better (descending)
 *   rsc_seniority, rank_seniority → lower is more senior (ascending)
 */
export function compare(
  a: ComparableResult,
  b: ComparableResult,
  tieBreakChain: TieBreakKey[],
): -1 | 0 | 1 {
  for (const key of tieBreakChain) {
    let diff: number;

    switch (key) {
      case 'points':
        diff = b.points - a.points; // descending
        break;
      case 'so_points':
        diff = b.soPoints - a.soPoints; // descending
        break;
      case 'mo_points':
        diff = b.moPoints - a.moPoints; // descending
        break;
      case 'rsc_seniority':
        diff = a.rscSeniority - b.rscSeniority; // ascending (lower = more senior)
        break;
      case 'rank_seniority':
        diff = a.rankSeniority - b.rankSeniority; // ascending
        break;
    }

    if (diff < 0) return -1;
    if (diff > 0) return 1;
  }
  return 0;
}

/**
 * Convenience: sorts an array of ComparableResult objects in bid-order
 * (position 0 is the highest-priority member for the position).
 * Returns a new array; does not mutate.
 */
export function sortByTieBreak(
  results: ComparableResult[],
  tieBreakChain: TieBreakKey[],
): ComparableResult[] {
  return [...results].sort((a, b) => compare(a, b, tieBreakChain));
}
```

- [ ] **Step 4: Run test, expect PASS**

- [ ] **Step 5: Export from index**

```ts
// append to packages/eligibility/src/index.ts
export type { ComparableResult } from './tie-break.js';
export { compare, sortByTieBreak } from './tie-break.js';
```

- [ ] **Step 6: Commit**

```bash
git add packages/eligibility/src/tie-break.ts packages/eligibility/src/index.ts packages/eligibility/tests/unit/tie-break.test.ts
git commit -m "feat(eligibility): tie-break chain comparator (points → SO/MO → RSC → rank)"
```

---

## Task 8: Main evaluate entry point

**Files:**
- Create: `packages/eligibility/src/evaluate.ts`
- Test: `packages/eligibility/tests/unit/evaluate.test.ts`

`evaluateEligibility` wires all criteria and points modules together into one call.

- [ ] **Step 1: Write failing test**

```ts
// packages/eligibility/tests/unit/evaluate.test.ts
import { describe, it, expect } from 'vitest';
import { evaluateEligibility } from '../../src/evaluate.js';
import type { Member, PositionRule } from '../../src/types.js';

const lt: Member = {
  employeeId: '20731',
  firstName: 'Peter',
  lastName: 'Darley',
  rank: 'LT',
  rscSeniority: 75,
  rankSeniority: 20,
  isProbationary: false,
  credentials: [
    { name: 'Hazardous Materials Operations' },
    { name: 'Rope Rescue Operations' },
    { name: 'Confined Space Operations' },
    { name: 'Structural Collapse Operations' },
    { name: 'Trench Rescue Operations' },
    { name: 'Vehicle & Machinery Rescue Operations' },
    { name: 'State Certified Hazardous Materials Technician' },
    { name: 'Rope Rescue Technician' },
    { name: 'Confined Space Technician' },
    { name: 'Structural Collapse Technician' },
    { name: 'Trench Rescue Technician' },
    { name: 'Vehicle & Machinery Rescue Technician' },
    { name: 'Drone Operator Qualified-Part 107 sUAS' },
    { name: 'Paramedic' },
  ],
};

const rescueLtRule: PositionRule = {
  positionId: 'A205',
  ruleBookVersion: '2026.1',
  requiredCriteria: {
    rank: ['LT'],
    credentials: [],
    custom: ['paramedic', 'non_probationary'],
  },
  pointsPreference: {
    max: 13,
    items: [
      { points: 1, credential: 'Hazardous Materials Operations', requiresOpsPair: false },
      { points: 1, credential: 'Rope Rescue Operations', requiresOpsPair: false },
      { points: 1, credential: 'Confined Space Operations', requiresOpsPair: false },
      { points: 1, credential: 'Structural Collapse Operations', requiresOpsPair: false },
      { points: 1, credential: 'Trench Rescue Operations', requiresOpsPair: false },
      { points: 1, credential: 'Vehicle & Machinery Rescue Operations', requiresOpsPair: false },
      { points: 1, credential: 'State Certified Hazardous Materials Technician', requiresOpsPair: true },
      { points: 1, credential: 'Rope Rescue Technician', requiresOpsPair: true },
      { points: 1, credential: 'Confined Space Technician', requiresOpsPair: true },
      { points: 1, credential: 'Structural Collapse Technician', requiresOpsPair: true },
      { points: 1, credential: 'Trench Rescue Technician', requiresOpsPair: true },
      { points: 1, credential: 'Vehicle & Machinery Rescue Technician', requiresOpsPair: true },
      { points: 1, credential: 'Drone Operator Qualified-Part 107 sUAS', requiresOpsPair: false },
    ],
  },
  tieBreakChain: ['points', 'so_points', 'rsc_seniority', 'rank_seniority'],
};

const cptRule: PositionRule = {
  positionId: 'A201',
  ruleBookVersion: '2026.1',
  requiredCriteria: { rank: ['CPT'], credentials: [], custom: [] },
  pointsPreference: { max: 0, items: [] },
  tieBreakChain: ['points', 'rsc_seniority', 'rank_seniority'],
};

describe('evaluateEligibility', () => {
  it('LT with Paramedic is eligible for Rescue LT position', () => {
    const result = evaluateEligibility(lt, rescueLtRule);
    expect(result.eligible).toBe(true);
  });

  it('eligible member scores 13 points (6 ops + 6 tech + 1 drone, capped at 13)', () => {
    const result = evaluateEligibility(lt, rescueLtRule);
    expect(result.points).toBe(13);
  });

  it('eligible member scores 13 SO points', () => {
    const result = evaluateEligibility(lt, rescueLtRule);
    expect(result.soPoints).toBe(13);
  });

  it('reasons array contains at least one entry per required criterion', () => {
    const result = evaluateEligibility(lt, rescueLtRule);
    expect(result.reasons.length).toBeGreaterThan(0);
    expect(result.reasons.every((r) => typeof r.code === 'string')).toBe(true);
  });

  it('all reasons satisfied = true for fully eligible member', () => {
    const result = evaluateEligibility(lt, rescueLtRule);
    expect(result.reasons.every((r) => r.satisfied)).toBe(true);
  });

  it('LT fails CPT-required position — eligible false', () => {
    const result = evaluateEligibility(lt, cptRule);
    expect(result.eligible).toBe(false);
    expect(result.points).toBe(0);
    expect(result.soPoints).toBe(0);
  });

  it('ineligible result has at least one unsatisfied reason', () => {
    const result = evaluateEligibility(lt, cptRule);
    expect(result.reasons.some((r) => !r.satisfied)).toBe(true);
  });

  it('probationary LT fails non_probationary custom gate', () => {
    const probMember: Member = { ...lt, isProbationary: true };
    const result = evaluateEligibility(probMember, rescueLtRule);
    expect(result.eligible).toBe(false);
    expect(result.reasons.find((r) => r.code === 'PROBATIONARY_RESTRICTED')).toBeDefined();
  });

  it('FF without Paramedic fails paramedic custom gate', () => {
    const ff: Member = { ...lt, rank: 'FF', credentials: [] };
    const ffRule: PositionRule = {
      ...rescueLtRule,
      positionId: 'A206',
      requiredCriteria: { rank: ['FF'], credentials: [], custom: ['paramedic'] },
    };
    const result = evaluateEligibility(ff, ffRule);
    expect(result.eligible).toBe(false);
    expect(result.reasons.find((r) => r.code === 'PARAMEDIC_REQUIRED')).toBeDefined();
  });

  it('returns zero points for ineligible member even if credentials match', () => {
    // LT bidding on CPT position — ineligible; must score 0 even if they
    // happen to hold creds that match the points list.
    const result = evaluateEligibility(lt, cptRule);
    expect(result.points).toBe(0);
    expect(result.soPoints).toBe(0);
    expect(result.moPoints).toBe(0);
  });
});
```

- [ ] **Step 2: Run test, expect FAIL**

- [ ] **Step 3: Implement `packages/eligibility/src/evaluate.ts`**

```ts
// packages/eligibility/src/evaluate.ts
import type { Member, PositionRule, EligibilityResult, EligibilityReason } from './types.js';
import { rankSatisfied } from './criteria/rank.js';
import { paramedicSatisfied } from './criteria/paramedic.js';
import { driverEngineerSatisfied } from './criteria/driver-engineer.js';
import { nonProbationarySatisfied } from './criteria/non-probationary.js';
import { requiredCredsSatisfied } from './criteria/certs.js';
import { computePoints } from './points/sum.js';
import { computeSoPoints } from './points/so-pool.js';
import { computeMoPoints } from './points/mo-pool.js';

/**
 * Evaluates whether a member is eligible for a position given its rule.
 *
 * Algorithm:
 *   1. Evaluate all required criteria (rank, credentials, custom gates).
 *   2. If any criterion is not satisfied, eligible = false.
 *   3. Compute points breakdown only when eligible = true
 *      (ineligible members score 0 by convention).
 *   4. Compute SO and MO sub-pool scores (always from the fixed credential sets,
 *      independent of the position's points-preference list).
 *
 * Pure function — no I/O, no Date.now(), no state.
 */
export function evaluateEligibility(member: Member, rule: PositionRule): EligibilityResult {
  const reasons: EligibilityReason[] = [];

  // 1a. Rank check
  reasons.push(rankSatisfied(member, rule.requiredCriteria.rank));

  // 1b. Required credentials (AND — all must be held)
  reasons.push(...requiredCredsSatisfied(member, rule.requiredCriteria.credentials));

  // 1c. Custom gates
  for (const gate of rule.requiredCriteria.custom) {
    switch (gate) {
      case 'paramedic':
        reasons.push(paramedicSatisfied(member));
        break;
      case 'driver_engineer':
        reasons.push(driverEngineerSatisfied(member));
        break;
      case 'non_probationary':
        reasons.push(nonProbationarySatisfied(member));
        break;
    }
  }

  const eligible = reasons.every((r) => r.satisfied);

  if (!eligible) {
    return {
      eligible: false,
      reasons,
      points: 0,
      soPoints: 0,
      moPoints: 0,
      breakdown: { total: 0, soTotal: 0, moTotal: 0, itemized: [] },
    };
  }

  // 2. Points
  const breakdown = computePoints(member, rule);
  const soPoints = computeSoPoints(member);
  const moPoints = computeMoPoints(member);

  return {
    eligible: true,
    reasons,
    points: breakdown.total,
    soPoints,
    moPoints,
    breakdown: { ...breakdown, soTotal: soPoints, moTotal: moPoints },
  };
}
```

- [ ] **Step 4: Run test, expect PASS**

- [ ] **Step 5: Export from index**

```ts
// append to packages/eligibility/src/index.ts
export { evaluateEligibility } from './evaluate.js';
```

- [ ] **Step 6: Commit**

```bash
git add packages/eligibility/src/evaluate.ts packages/eligibility/src/index.ts packages/eligibility/tests/unit/evaluate.test.ts
git commit -m "feat(eligibility): evaluateEligibility — main entry wiring all criteria + points"
```

---

## Task 9: 2025 fixture export

**Files:**
- Create: `packages/eligibility/tests/fixtures/2025-members.json`
- Create: `packages/eligibility/tests/fixtures/2025-rules.json`
- Create: `packages/eligibility/tests/fixtures/2025-actual-bid.json`
- Create: `packages/eligibility/scripts/export-fixtures.ts` (one-time script, not in CI)

The fixtures are derived from:
- `D:/GitHub_Repos/MBFD_Hub/analysis/personnel.csv` → `2025-members.json`
- `D:/GitHub_Repos/mbfd-bid/apps/worker/seed/fixtures/2026_rules.json` (with 2025 overrides) → `2025-rules.json`
- `D:/GitHub_Repos/MBFD_Hub/analysis/bid_pick.csv` → `2025-actual-bid.json`

- [ ] **Step 1: Create `packages/eligibility/scripts/export-fixtures.ts`**

```ts
// packages/eligibility/scripts/export-fixtures.ts
// Run once: npx tsx packages/eligibility/scripts/export-fixtures.ts
// Reads analysis CSV files and writes JSON fixtures for use in tests.
// Not part of the build or CI — run manually when source data changes.
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const ANALYSIS_DIR = path.resolve(__dirname, '../../../analysis');
const FIXTURE_DIR = path.resolve(__dirname, '../tests/fixtures');

// --- Parse personnel.csv ---
const personnelRaw = fs.readFileSync(path.join(ANALYSIS_DIR, 'personnel.csv'), 'utf8');
const [personnelHeader, ...personnelRows] = personnelRaw.split('\n').filter(Boolean);
const personnelCols = personnelHeader!.split(',').map((c) => c.trim().toLowerCase().replace(/\s+/g, '_'));

const RANK_MAP: Record<string, string> = {
  'firefighter': 'FF',
  'lieutenant': 'LT',
  'captain': 'CPT',
  'division chief': 'DC',
  'deputy fire chief': 'DEP_CHIEF',
  'fire chief': 'CHIEF',
};

const members = personnelRows
  .map((row) => {
    const vals = row.split(',').map((v) => v.trim().replace(/^"|"$/g, ''));
    const obj: Record<string, string> = {};
    personnelCols.forEach((col, i) => { obj[col] = vals[i] ?? ''; });
    const rank = RANK_MAP[obj['current_rank']?.toLowerCase() ?? ''];
    if (!rank) return null;
    if (obj['bid'] === 'Exclude') return null;
    return {
      employeeId: obj['employee_id'],
      firstName: obj['first_name'],
      lastName: obj['last_name'],
      rank,
      rscSeniority: Number(obj['rsc_seniority'] ?? 9999),
      rankSeniority: obj['rank_seniority'] ? Number(obj['rank_seniority']) : undefined,
      isProbationary: obj['is_probationary']?.toLowerCase() === 'true',
      credentials: [] as { name: string }[], // credential data not in this CSV
    };
  })
  .filter(Boolean);

fs.writeFileSync(path.join(FIXTURE_DIR, '2025-members.json'), JSON.stringify(members, null, 2));
console.log(`Wrote ${members.length} members`);

// --- Parse bid_pick.csv ---
const bidRaw = fs.readFileSync(path.join(ANALYSIS_DIR, 'bid_pick.csv'), 'utf8');
const [bidHeader, ...bidRows] = bidRaw.split('\n').filter(Boolean);
const bidCols = bidHeader!.split(',').map((c) => c.trim().toLowerCase().replace(/[\s#]+/g, '_'));

const picks = bidRows.map((row) => {
  const vals = row.split(',').map((v) => v.trim().replace(/^"|"$/g, ''));
  const obj: Record<string, string> = {};
  bidCols.forEach((col, i) => { obj[col] = vals[i] ?? ''; });
  return {
    bidNumber: Number(obj['bid__']),
    employeeId: obj['emp_id'],
    positionId: obj['position__'],
    aDayPositionId: obj['a-day_pick'],
    lastName: obj['last_name'],
    firstName: obj['first_name'],
    rank: RANK_MAP[obj['current_rank']?.toLowerCase() ?? ''] ?? obj['current_rank'],
    bidCategory: obj['bid_category'],
  };
}).filter((p) => p.employeeId);

fs.writeFileSync(path.join(FIXTURE_DIR, '2025-actual-bid.json'), JSON.stringify(picks, null, 2));
console.log(`Wrote ${picks.length} bid picks`);
```

- [ ] **Step 2: Run script**

```bash
npx tsx packages/eligibility/scripts/export-fixtures.ts
```

Expected output: `Wrote 192 members`, `Wrote 244 bid picks`.

- [ ] **Step 3: Copy `2026_rules.json` as `2025-rules.json` baseline**

```bash
cp D:/GitHub_Repos/mbfd-bid/apps/worker/seed/fixtures/2026_rules.json packages/eligibility/tests/fixtures/2025-rules.json
```

- [ ] **Step 4: Commit fixtures and script**

```bash
git add packages/eligibility/tests/fixtures/ packages/eligibility/scripts/
git commit -m "test(eligibility): export 2025 bid fixtures (members, picks, rules)"
```

---

## Task 10: 2025 replay golden test

**Files:**
- Create: `packages/eligibility/tests/golden/2025-replay.test.ts`

This is the regression bedrock. For every pick in `2025-actual-bid.json`, the test asserts that the engine reports the chosen position as eligible for the member at the time of pick. It does NOT assert that the engine would have made the same choice — humans make choices, the engine confirms feasibility.

Coverage goal: zero false negatives (the engine never marks an actually-made pick as ineligible).

- [ ] **Step 1: Write the golden test**

```ts
// packages/eligibility/tests/golden/2025-replay.test.ts
import { describe, it, expect } from 'vitest';
import { evaluateEligibility } from '../../src/evaluate.js';
import type { Member, PositionRule } from '../../src/types.js';

// Fixtures written by scripts/export-fixtures.ts
import members2025 from '../fixtures/2025-members.json' assert { type: 'json' };
import picks2025 from '../fixtures/2025-actual-bid.json' assert { type: 'json' };
import rules2025 from '../fixtures/2025-rules.json' assert { type: 'json' };

// Index members and rules for O(1) lookup
const memberByEmpId = new Map<string, Member>(
  (members2025 as Member[]).map((m) => [m.employeeId, m]),
);
const ruleByPositionId = new Map<string, PositionRule>(
  (rules2025 as PositionRule[]).map((r) => [r.positionId, r]),
);

// Positions that are excluded from the eligibility engine check because
// they are pre-filled by external process (Division Chief slots, Union
// President, Paramedic Student slots) or are administrative holds.
const SKIP_POSITION_PREFIXES = ['D5', 'D501', 'D502', 'D503', 'D504', 'D505'];
const EXCLUDED_CATEGORY = 'EXCLUDED';

describe('2025 bid replay — zero false negatives', () => {
  it('engine has rules for every position that appears in the bid picks', () => {
    const missingRules: string[] = [];
    for (const pick of picks2025 as Array<{ positionId: string }>) {
      if (SKIP_POSITION_PREFIXES.some((p) => pick.positionId.startsWith(p))) continue;
      if (!ruleByPositionId.has(pick.positionId)) {
        missingRules.push(pick.positionId);
      }
    }
    // Log any missing for visibility; do not fail the suite (some admin positions may be absent)
    if (missingRules.length > 0) {
      console.warn(`Positions without rules: ${[...new Set(missingRules)].join(', ')}`);
    }
  });

  it('for every pick, the engine reports eligible = true', () => {
    const falseNegatives: Array<{
      bidNumber: number;
      employeeId: string;
      positionId: string;
      reasons: string;
    }> = [];

    for (const pick of picks2025 as Array<{
      bidNumber: number;
      employeeId: string;
      positionId: string;
      bidCategory: string;
    }>) {
      // Skip excluded members (Division Chiefs, Union President)
      if (pick.bidCategory === EXCLUDED_CATEGORY) continue;
      // Skip A-Day/admin pre-fill positions
      if (SKIP_POSITION_PREFIXES.some((p) => pick.positionId.startsWith(p))) continue;

      const member = memberByEmpId.get(pick.employeeId);
      const rule = ruleByPositionId.get(pick.positionId);

      // Missing fixture data — warn and skip, don't fail
      if (!member || !rule) continue;

      const result = evaluateEligibility(member, rule);
      if (!result.eligible) {
        falseNegatives.push({
          bidNumber: pick.bidNumber,
          employeeId: pick.employeeId,
          positionId: pick.positionId,
          reasons: result.reasons
            .filter((r) => !r.satisfied)
            .map((r) => r.label)
            .join('; '),
        });
      }
    }

    if (falseNegatives.length > 0) {
      console.error('FALSE NEGATIVES (engine says ineligible for an actual 2025 pick):');
      for (const fn of falseNegatives) {
        console.error(`  Bid #${fn.bidNumber}: emp=${fn.employeeId} pos=${fn.positionId} — ${fn.reasons}`);
      }
    }

    expect(falseNegatives).toHaveLength(0);
  });

  it('engine runs in under 100ms for the full 244-pick set', () => {
    const start = performance.now();
    for (const pick of picks2025 as Array<{
      employeeId: string;
      positionId: string;
      bidCategory: string;
    }>) {
      if (pick.bidCategory === EXCLUDED_CATEGORY) continue;
      if (SKIP_POSITION_PREFIXES.some((p) => pick.positionId.startsWith(p))) continue;
      const member = memberByEmpId.get(pick.employeeId);
      const rule = ruleByPositionId.get(pick.positionId);
      if (!member || !rule) continue;
      evaluateEligibility(member, rule);
    }
    const elapsed = performance.now() - start;
    expect(elapsed).toBeLessThan(100);
  });
});
```

- [ ] **Step 2: Run the golden test**

```bash
pnpm --filter @mbfd/eligibility test tests/golden/2025-replay.test.ts
```

Expected: zero false negatives. If any appear, the fixture data or rule mapping is incomplete — fix the fixtures (add credentials to member objects from the XLSX credential data) rather than adjusting engine logic.

- [ ] **Step 3: Document any fixture gaps found**

If credentials are missing from `2025-members.json` (the export script did not pull them from the credentials XLSX), run a supplementary script that joins `personnel.csv` with `2025 Bid position requirements and points.xlsx` to populate each member's `credentials` array.

```ts
// packages/eligibility/scripts/enrich-member-creds.ts
// Read the XLSX credential matrix and add credential arrays to 2025-members.json.
// Column structure: first col = employee_id, subsequent cols = credential names with
// values "Yes" / "" to indicate whether the member holds that credential.
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const FIXTURE_DIR = path.resolve(__dirname, '../tests/fixtures');
const XLSX_PATH = 'D:/MBFD/Bid/2025 Bid Documents/eligible/2025 Bid position requirements and points.xlsx';

// dynamic import so script can be run with tsx without changing package type
const XLSX = await import('xlsx');
const wb = XLSX.read(fs.readFileSync(XLSX_PATH), { type: 'buffer' });
const sheet = wb.Sheets[wb.SheetNames[0]!]!;
const rows = XLSX.utils.sheet_to_json<Record<string, string>>(sheet, { defval: '' });

// Build map: employeeId → string[] of credential names held
const credsByEmpId = new Map<string, string[]>();
for (const row of rows) {
  const empId = String(row['employee_id'] ?? row['Emp Id'] ?? '').trim();
  if (!empId) continue;
  const creds: string[] = [];
  for (const [col, val] of Object.entries(row)) {
    if (col.toLowerCase() === 'employee_id' || col.toLowerCase() === 'emp id') continue;
    if (String(val).trim().toLowerCase() === 'yes') creds.push(col.trim());
  }
  credsByEmpId.set(empId, creds);
}

const members = JSON.parse(fs.readFileSync(path.join(FIXTURE_DIR, '2025-members.json'), 'utf8'));
for (const m of members) {
  const creds = credsByEmpId.get(m.employeeId) ?? [];
  m.credentials = creds.map((name: string) => ({ name }));
}
fs.writeFileSync(path.join(FIXTURE_DIR, '2025-members.json'), JSON.stringify(members, null, 2));
console.log(`Enriched ${members.length} members with credential data`);
```

Run:
```bash
npx tsx packages/eligibility/scripts/enrich-member-creds.ts
```

Re-run the golden test. Iterate until false negatives reach 0.

- [ ] **Step 4: Commit passing golden test**

```bash
git add packages/eligibility/tests/golden/2025-replay.test.ts packages/eligibility/scripts/enrich-member-creds.ts packages/eligibility/tests/fixtures/2025-members.json packages/eligibility/tests/fixtures/2025-actual-bid.json packages/eligibility/tests/fixtures/2025-rules.json
git commit -m "test(eligibility): 2025 bid replay golden test — zero false negatives"
```

---

## Task 11: Coverage gate

**Files:**
- Modify: `packages/eligibility/package.json` (add coverage config)
- Create: `packages/eligibility/vitest.config.ts`

Target: 100% lines, 100% branches on all `src/` files. This is higher than the project-wide 80% floor because this package is the sole eligibility authority.

- [ ] **Step 1: Write test that fails if coverage drops below threshold**

Vitest enforces thresholds at the runner level; the test itself just runs the suite and the CI step fails on uncovered branches.

- [ ] **Step 2: Create `packages/eligibility/vitest.config.ts`**

```ts
// packages/eligibility/vitest.config.ts
import { defineConfig } from 'vitest/config';

export default defineConfig({
  test: {
    include: ['tests/**/*.test.ts'],
    coverage: {
      provider: 'v8',
      include: ['src/**/*.ts'],
      exclude: ['src/index.ts'], // barrel file — no executable branches
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

- [ ] **Step 3: Update `packages/eligibility/package.json` coverage script**

```json
{
  "scripts": {
    "test:coverage": "vitest run --coverage --reporter=verbose"
  }
}
```

- [ ] **Step 4: Run coverage and verify**

```bash
pnpm --filter @mbfd/eligibility test:coverage
```

Expected: all thresholds pass. If any branch is uncovered, add a targeted test for that specific branch (e.g., `opsForTech` with an unknown tech name → undefined, `holdsAllOps` with partial ops set, `sortByTieBreak` with an empty array).

- [ ] **Step 5: Add branch-covering micro-tests**

```ts
// packages/eligibility/tests/unit/evaluate.test.ts — append

it('member with required cred missing from requiredCriteria list fails', () => {
  const rule: PositionRule = {
    positionId: 'X611',
    ruleBookVersion: '2026.1',
    requiredCriteria: {
      rank: ['FF'],
      credentials: ['Merchant Mariner Credential (MMC)', 'IADRS Swim Evaluation'],
      custom: [],
    },
    pointsPreference: { max: 0, items: [] },
    tieBreakChain: ['points', 'mo_points', 'rsc_seniority', 'rank_seniority'],
  };
  const ff: Member = {
    employeeId: '55555', firstName: 'X', lastName: 'Y', rank: 'FF',
    rscSeniority: 100, rankSeniority: 50, isProbationary: false,
    credentials: [{ name: 'Merchant Mariner Credential (MMC)' }], // missing IADRS
  };
  const result = evaluateEligibility(ff, rule);
  expect(result.eligible).toBe(false);
  expect(result.reasons.find((r) => r.code === 'CRED_MISSING' && r.label.includes('IADRS'))).toBeDefined();
});

it('driver_engineer custom gate is evaluated when present', () => {
  const deRule: PositionRule = {
    positionId: 'X102',
    ruleBookVersion: '2026.1',
    requiredCriteria: { rank: ['FF'], credentials: [], custom: ['driver_engineer'] },
    pointsPreference: { max: 0, items: [] },
    tieBreakChain: ['rsc_seniority', 'rank_seniority'],
  };
  const ff: Member = {
    employeeId: '44444', firstName: 'A', lastName: 'B', rank: 'FF',
    rscSeniority: 1, rankSeniority: 1, isProbationary: false, credentials: [],
  };
  expect(evaluateEligibility(ff, deRule).eligible).toBe(false);
  expect(evaluateEligibility(
    { ...ff, credentials: [{ name: 'Driver Engineer Qualified' }] },
    deRule,
  ).eligible).toBe(true);
});
```

- [ ] **Step 6: Re-run coverage, expect all thresholds pass**

- [ ] **Step 7: Commit**

```bash
git add packages/eligibility/vitest.config.ts packages/eligibility/package.json packages/eligibility/tests/unit/evaluate.test.ts
git commit -m "test(eligibility): 100% coverage gate (lines, branches, functions)"
```

---

## Acceptance criteria

- [ ] `pnpm --filter @mbfd/eligibility test` — all unit + golden tests pass
- [ ] `pnpm --filter @mbfd/eligibility test:coverage` — 100% lines and branches on `src/**/*.ts`
- [ ] `evaluateEligibility(member, rule)` completes in < 5ms on a single call (enforced by the performance test in Task 10)
- [ ] Full 244-pick replay completes in < 100ms (enforced by the performance assertion in Task 10)
- [ ] Zero false negatives: every 2025 actual bid pick is reported eligible by the engine
- [ ] `reasons` array is always populated (satisfied + unsatisfied alike) for AI consumption in Plan 06
- [ ] All 6 Op/Tech pairs from `2026_Rules_and_Points.md §8` are registered in `operations-techs.ts`
- [ ] SO and MO sub-pool credentials match `2026_Rules_and_Points.md §9` definitions
- [ ] `compare(a, b, chain)` satisfies strict antisymmetry: `compare(a, b) === -compare(b, a)`
- [ ] No mutations — all functions return new objects; no input modified in place
- [ ] No I/O, Date.now(), Math.random() calls in any `src/` file
- [ ] All imports use `.js` extensions (NodeNext module resolution)
- [ ] No emojis introduced in source files or commit messages

## Notes for the engineer

- **Credential name precision**: The strings in `operations-techs.ts`, `so-pool.ts`, and `mo-pool.ts` must exactly match `reference_credentials.json` names — including spacing, capitalization, and parentheses. Any mismatch silently scores 0. When debugging golden test failures, print the member's credential names and compare character-by-character.
- **Tech-without-Ops vs all-Ops-gate**: Some rule blocks require ALL 6 ops before ANY tech counts (the "Operations gates Technician" blanket rule). The current design enforces this per-item via `requiresOpsPair` in `PointsItem`. Rule fixtures must set `requiresOpsPair: true` on every Technician item in those blocks — this is a data concern, not an engine concern.
- **Hazmat Awareness alternative**: Hazmat Awareness Level can substitute for Hazmat Operations in the MO pool but NOT in the SO pool. The MO pool handles this via the `MO_ALTERNATES` map in `mo-pool.ts`.
- **Float Captain rule**: This position uses BOTH SO + MO sub-pool points as a combined tie-breaker (`tieBreakChain: ['points', 'so_points', 'mo_points', 'rsc_seniority', 'rank_seniority']`). The compare function handles this correctly because both `so_points` and `mo_points` are keys in the chain — no special-casing needed.
- **Division Chief positions** (XX211 slots): these are pre-allocated by the admin before the bid session opens. The engine can still evaluate them, but the golden test skips them since no member actually bids competitively for them.
- **AI advisory integration (Plan 06)**: The `reasons` array is the primary input to the Claude Sonnet narrative generator. Keep `code` values stable — they are referenced in Plan 06's prompt templates. Never rename a code after Plan 06 is shipped.
- **Performance**: The hot path is `evaluateEligibility` inside the Durable Object on every pick. The full bitmap (280 × 230 = 64,400 evaluations) must finish in < 100ms on a Worker. The current implementation is O(C) per call where C is credential count per member (~20 on average) — this is comfortably within budget.
