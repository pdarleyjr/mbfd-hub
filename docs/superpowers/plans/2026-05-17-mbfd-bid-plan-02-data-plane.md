# Plan 02 — Data plane: schema, imports, read-only viewers

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** D1 schema in place for the full spec §6.1; admin can import a 2025 members CSV + a 2025 credentials XLSX; admin and developer can browse members, positions, and rules in read-only viewers. No live bid logic — just the data substrate.

**Architecture:** Drizzle migrations versioned in `apps/worker/migrations/` (SQLite DDL). All admin endpoints live on the worker behind a JWT `role=admin` middleware check. CSV/XLSX parsing happens in the worker with Zod row validation. Read-only viewers are React Server Components in `apps/web/app/admin/*` that hit the worker via a typed Hono RPC client. Audit log writes are flat (no hash chain yet — Plan 08).

**Tech Stack additions on top of Plan 01:** Drizzle ORM 0.36.x · drizzle-zod 0.5.x · drizzle-kit 0.28.x · `papaparse` 5.4.x · `xlsx` 0.20.x (already installed for tooling) · `ulid` 2.3.x · TanStack Table 8.20.x · shadcn `Table`, `Sheet`, `Badge` primitives.

---

## File map

```
apps/worker/
  drizzle.config.ts                      ← drizzle-kit config
  src/db/
    schema.ts                            ← Drizzle schema (members, certs, positions, rules, bid_*, audit, ai)
    index.ts                             ← drizzle(env.DB) helper + types
  src/lib/
    csv-parser.ts                        ← papaparse wrapper, returns rows + per-row errors
    xlsx-cred-parser.ts                  ← SheetJS wrapper for credentials sheet
    audit.ts                             ← write-only flat audit_log writer
    ulid.ts                              ← ulid wrapper
  src/routes/admin/
    middleware.ts                        ← role=admin JWT check
    members.ts                           ← POST /admin/members/import; GET /admin/members; GET /admin/members/:id
    credentials.ts                       ← POST /admin/credentials/import
    positions.ts                         ← GET /admin/positions; POST /admin/positions/clone-from-year/:src
    rules.ts                             ← GET /admin/rules
  migrations/
    0002_members_certs.sql               ← members, credentials, member_credentials
    0003_positions_rules.sql             ← position_templates, positions, rule_books, position_rules
    0004_bid_audit_ai.sql                ← bid_years, bid_sessions, bid_order, bids, audit_log, ai_advisories, snapshots, portal_writeback_queue
  seed/
    2026.ts                              ← seeds 2026 position template + rule book v2026.1 + reference credentials
    fixtures/
      2026_positions.json                ← 230-position template
      2026_rules.json                    ← rule book entries
      reference_credentials.json         ← 57 named credentials from FY25
  tests/
    db.schema.test.ts                    ← Drizzle schema introspection tests
    csv-parser.test.ts
    xlsx-cred-parser.test.ts
    audit.test.ts
    admin-members.test.ts
    admin-credentials.test.ts
    admin-positions.test.ts
    admin-rules.test.ts
    admin-middleware.test.ts

apps/web/
  app/admin/
    layout.tsx                           ← admin shell; redirects non-admin to /lobby
    page.tsx                             ← admin dashboard (just links to viewers in this plan)
    members/page.tsx                     ← paginated table
    members/[id]/page.tsx                ← member detail (certs)
    members/import/page.tsx              ← upload form
    credentials/import/page.tsx          ← upload form
    positions/page.tsx                   ← grouped by shift+station
    rules/page.tsx                       ← tree view, read-only
  lib/
    rpc-client.ts                        ← Hono typed client wrapper for worker /admin/* routes
  components/admin/
    DataTable.tsx                        ← TanStack-Table wrapped shadcn Table
    UploadForm.tsx                       ← shared upload form with preview
    ImportResults.tsx                    ← inserted/errored summary
  tests/
    e2e/admin-flow.spec.ts               ← upload CSV + XLSX, browse members/positions/rules

packages/shared/
  src/schemas/
    member-import.ts                     ← Zod for CSV row (matches personnel.csv export)
    credential-import.ts                 ← Zod for credentials XLSX row
    position.ts                          ← Zod for positions row
    rule-book.ts                         ← Zod for rule_book entries
```

---

## Source data reference

The 2025 export files referenced by tests/fixtures live in `D:/GitHub_Repos/MBFD_Hub/analysis/`:

| File | Use |
|---|---|
| `personnel.csv` | 238-row 2025 member roster (golden CSV import test) |
| `positions.csv` | 234-row 2025 position template (clone source for 2026) |
| `rules_points.csv` | wide-matrix rules (parsed → `2026_rules.json` fixture) |
| `bid_pick.csv` | 2025 historical picks (used as Plan 04 fixture, not here) |

The 2026 source-of-truth markdown lives in `D:/MBFD/Bid/2026 Bid Documents/` and is the basis for the `seed/fixtures/2026_*.json` files.

**Schema source of truth:** `docs/superpowers/specs/2026-05-17-mbfd-bid-webapp-design.md` §6.1.

---

## Task 1: Install Drizzle + configure migrations

**Files:**
- Create: `apps/worker/drizzle.config.ts`
- Modify: `apps/worker/package.json` (add scripts + deps)
- Modify: `apps/worker/wrangler.toml` (no change but verify d1 binding `DB`)

- [ ] **Step 1: Add Drizzle deps**

```bash
cd apps/worker && pnpm add drizzle-orm@^0.36 drizzle-zod@^0.5 ulid@^2.3
cd apps/worker && pnpm add -D drizzle-kit@^0.28 @types/papaparse@^5
cd apps/worker && pnpm add papaparse@^5.4
```

- [ ] **Step 2: Create `apps/worker/drizzle.config.ts`**

```ts
import type { Config } from 'drizzle-kit';

export default {
  schema: './src/db/schema.ts',
  out: './migrations',
  dialect: 'sqlite',
  driver: 'd1-http',
  verbose: true,
  strict: true,
} satisfies Config;
```

- [ ] **Step 3: Add scripts to `apps/worker/package.json`**

```json
{
  "scripts": {
    "db:generate": "drizzle-kit generate",
    "db:migrate:local": "wrangler d1 migrations apply mbfd-bid-staging --local",
    "db:migrate:remote": "wrangler d1 migrations apply mbfd-bid-staging --remote --env staging",
    "db:seed:local": "tsx seed/2026.ts --local",
    "db:seed:remote": "tsx seed/2026.ts --remote"
  }
}
```

- [ ] **Step 4: Commit**

```bash
git add apps/worker/drizzle.config.ts apps/worker/package.json pnpm-lock.yaml
git commit -m "chore(worker): add drizzle-orm, drizzle-kit, papaparse, ulid"
```

---

## Task 2: Drizzle schema — members + credentials (mig 0002)

**Files:**
- Create: `apps/worker/src/db/schema.ts`
- Create: `apps/worker/src/db/index.ts`
- Create: `apps/worker/migrations/0002_members_certs.sql`
- Test: `apps/worker/tests/db.schema.test.ts`

- [ ] **Step 1: Write failing test**

```ts
// apps/worker/tests/db.schema.test.ts
import { describe, it, expect } from 'vitest';
import * as schema from '../src/db/schema';

describe('db schema (Plan 02)', () => {
  it('exports members table with required columns', () => {
    expect(schema.members).toBeDefined();
    expect(schema.members.employeeId).toBeDefined();
    expect(schema.members.rank).toBeDefined();
    expect(schema.members.bidCategory).toBeDefined();
    expect(schema.members.rscSeniority).toBeDefined();
  });
  it('exports credentials + member_credentials', () => {
    expect(schema.credentials).toBeDefined();
    expect(schema.memberCredentials).toBeDefined();
  });
});
```

- [ ] **Step 2: Run test, expect FAIL** (`pnpm --filter @mbfd/worker test db.schema` → cannot resolve schema).

- [ ] **Step 3: Implement `apps/worker/src/db/schema.ts`**

```ts
import { sqliteTable, integer, text, primaryKey, uniqueIndex } from 'drizzle-orm/sqlite-core';

export const members = sqliteTable('members', {
  id: integer('id').primaryKey({ autoIncrement: true }),
  employeeId: text('employee_id').notNull().unique(),
  firstName: text('first_name').notNull(),
  lastName: text('last_name').notNull(),
  rank: text('rank', { enum: ['FF', 'LT', 'CPT', 'DC', 'DEP_CHIEF', 'CHIEF'] }).notNull(),
  bidCategory: text('bid_category', { enum: ['OFC', 'FF', 'EXCLUDED'] }).notNull(),
  rscSeniority: integer('rsc_seniority').notNull(),
  rankSeniority: integer('rank_seniority'),
  hiredAt: text('hired_at'),
  promotedAt: text('promoted_at'),
  isProbationary: integer('is_probationary', { mode: 'boolean' }).notNull().default(false),
  createdAt: integer('created_at', { mode: 'timestamp' }).notNull(),
  updatedAt: integer('updated_at', { mode: 'timestamp' }).notNull(),
});

export const credentials = sqliteTable('credentials', {
  id: integer('id').primaryKey({ autoIncrement: true }),
  name: text('name').notNull().unique(),
  fyPointsDefault: integer('fy_points_default').notNull().default(0),
});

export const memberCredentials = sqliteTable(
  'member_credentials',
  {
    memberId: integer('member_id').notNull().references(() => members.id, { onDelete: 'cascade' }),
    credentialId: integer('credential_id').notNull().references(() => credentials.id, { onDelete: 'cascade' }),
    startDate: text('start_date'),
    expirationDate: text('expiration_date'),
  },
  (t) => ({ pk: primaryKey({ columns: [t.memberId, t.credentialId] }) }),
);
```

- [ ] **Step 4: Create `apps/worker/src/db/index.ts`**

```ts
import { drizzle, type DrizzleD1Database } from 'drizzle-orm/d1';
import * as schema from './schema';

export type DB = DrizzleD1Database<typeof schema>;
export function getDb(d1: D1Database): DB {
  return drizzle(d1, { schema });
}
export { schema };
```

- [ ] **Step 5: Run `pnpm db:generate` and verify it emits `0002_*.sql`. Manually rename/curate to `0002_members_certs.sql`.**

- [ ] **Step 6: Run test, expect PASS**

- [ ] **Step 7: Commit**

```bash
git add apps/worker/src/db apps/worker/migrations/0002_*.sql apps/worker/tests/db.schema.test.ts
git commit -m "feat(db): add Drizzle schema + migration 0002 (members, credentials, member_credentials)"
```

---

## Task 3: Drizzle schema — positions + rules (mig 0003)

**Files:**
- Modify: `apps/worker/src/db/schema.ts`
- Create: `apps/worker/migrations/0003_positions_rules.sql`
- Modify: `apps/worker/tests/db.schema.test.ts`

- [ ] **Step 1: Extend schema test**

```ts
it('exports position_templates, positions, rule_books, position_rules', () => {
  expect(schema.positionTemplates).toBeDefined();
  expect(schema.positions).toBeDefined();
  expect(schema.ruleBooks).toBeDefined();
  expect(schema.positionRules).toBeDefined();
});
it('positions table has compound natural key (id, templateVersion)', () => {
  expect(schema.positions.id).toBeDefined();
  expect(schema.positions.templateVersion).toBeDefined();
});
```

- [ ] **Step 2: Run test, expect FAIL**

- [ ] **Step 3: Extend `apps/worker/src/db/schema.ts`**

```ts
export const positionTemplates = sqliteTable('position_templates', {
  version: text('version').primaryKey(),
  effectiveYear: integer('effective_year').notNull(),
  notes: text('notes'),
});

export const positions = sqliteTable(
  'positions',
  {
    id: text('id').notNull(),  // "A101" — natural key
    templateVersion: text('template_version').notNull().references(() => positionTemplates.version),
    shift: text('shift', { enum: ['A', 'B', 'C', 'D'] }).notNull(),
    station: text('station').notNull(),
    division: text('division', { enum: ['Combat', 'Rescue', 'Prevention', 'Training', 'Support Services'] }).notNull(),
    unit: text('unit').notNull(),
    rankRequired: text('rank_required', { enum: ['FF', 'LT', 'CPT', 'DC'] }).notNull(),
    positionName: text('position_name').notNull(),
    isFloating: integer('is_floating', { mode: 'boolean' }).notNull().default(false),
    isVacantByDesign: integer('is_vacant_by_design', { mode: 'boolean' }).notNull().default(false),
    isExcludedFromCount: integer('is_excluded_from_count', { mode: 'boolean' }).notNull().default(false),
  },
  (t) => ({ pk: primaryKey({ columns: [t.id, t.templateVersion] }) }),
);

export const ruleBooks = sqliteTable('rule_books', {
  version: text('version').primaryKey(),
  effectiveYear: integer('effective_year').notNull(),
  notes: text('notes'),
});

export const positionRules = sqliteTable('position_rules', {
  id: integer('id').primaryKey({ autoIncrement: true }),
  ruleBookVersion: text('rule_book_version').notNull().references(() => ruleBooks.version),
  positionId: text('position_id').notNull(),
  templateVersion: text('template_version').notNull(),
  requiredCriteriaJson: text('required_criteria').notNull(),
  pointsPreferenceJson: text('points_preference').notNull(),
  tieBreakChainJson: text('tie_break_chain').notNull(),
  notes: text('notes'),
});
```

- [ ] **Step 4: Run `pnpm db:generate` → curate to `0003_positions_rules.sql`.**

- [ ] **Step 5: Run test, expect PASS**

- [ ] **Step 6: Commit**

```bash
git add apps/worker/src/db/schema.ts apps/worker/migrations/0003_*.sql apps/worker/tests/db.schema.test.ts
git commit -m "feat(db): add migration 0003 (position_templates, positions, rule_books, position_rules)"
```

---

## Task 4: Drizzle schema — bid_*, audit, AI (mig 0004)

**Files:**
- Modify: `apps/worker/src/db/schema.ts`
- Create: `apps/worker/migrations/0004_bid_audit_ai.sql`
- Modify: `apps/worker/tests/db.schema.test.ts`

- [ ] **Step 1: Extend schema test**

```ts
it('exports bid_years, bid_sessions, bid_order, bids, audit_log, ai_advisories, snapshots, writeback queue', () => {
  expect(schema.bidYears).toBeDefined();
  expect(schema.bidSessions).toBeDefined();
  expect(schema.bidOrder).toBeDefined();
  expect(schema.bids).toBeDefined();
  expect(schema.auditLog).toBeDefined();
  expect(schema.aiAdvisories).toBeDefined();
  expect(schema.bidSessionSnapshots).toBeDefined();
  expect(schema.portalWritebackQueue).toBeDefined();
});
it('bid_sessions has multi-day fields (scheduled_resume_at, expected_duration_days, day_count)', () => {
  expect(schema.bidSessions.scheduledResumeAt).toBeDefined();
  expect(schema.bidSessions.expectedDurationDays).toBeDefined();
  expect(schema.bidSessions.dayCount).toBeDefined();
});
it('bids has portal write-back tracking columns', () => {
  expect(schema.bids.portalSyncStatus).toBeDefined();
  expect(schema.bids.portalSyncAttempts).toBeDefined();
});
```

- [ ] **Step 2: Run test, expect FAIL**

- [ ] **Step 3: Extend schema** — full bid_*, audit, AI tables matching spec §6.1. (Implementer will write all column definitions; reviewer will verify against spec verbatim.)

- [ ] **Step 4: Run `pnpm db:generate` → curate to `0004_bid_audit_ai.sql`.**

- [ ] **Step 5: Run test, expect PASS**

- [ ] **Step 6: Commit**

```bash
git add apps/worker/src/db/schema.ts apps/worker/migrations/0004_*.sql apps/worker/tests/db.schema.test.ts
git commit -m "feat(db): add migration 0004 (bid_*, audit_log, ai_advisories, snapshots, writeback queue)"
```

---

## Task 5: Apply migrations to staging + integration smoke test

**Files:**
- Test: `apps/worker/tests/db.integration.test.ts`

- [ ] **Step 1: Write integration test using `@miniflare/d1` in-memory**

```ts
// apps/worker/tests/db.integration.test.ts
import { describe, it, expect, beforeAll } from 'vitest';
import { unstable_dev, type UnstableDevWorker } from 'wrangler';

describe('D1 migrations apply cleanly', () => {
  let worker: UnstableDevWorker;
  beforeAll(async () => {
    worker = await unstable_dev('src/index.ts', {
      experimental: { disableExperimentalWarning: true },
      local: true,
      d1Databases: [{ binding: 'DB', database_name: 'mbfd-bid-staging' }],
    });
  });
  it('all 4 migrations apply', async () => {
    const res = await worker.fetch('/health');
    expect(res.status).toBe(200);
  });
});
```

- [ ] **Step 2: Apply locally**

```bash
cd apps/worker && pnpm db:migrate:local
```

- [ ] **Step 3: Apply to staging remote** (uses CF token from Wrangler env):

```bash
cd apps/worker && pnpm db:migrate:remote
```

- [ ] **Step 4: Run test, expect PASS**

- [ ] **Step 5: Commit**

```bash
git add apps/worker/tests/db.integration.test.ts
git commit -m "test(db): D1 migration apply integration test (local + remote)"
```

---

## Task 6: Shared schemas for CSV/XLSX row validation

**Files:**
- Create: `packages/shared/src/schemas/member-import.ts`
- Create: `packages/shared/src/schemas/credential-import.ts`
- Create: `packages/shared/src/schemas/position.ts`
- Create: `packages/shared/src/schemas/rule-book.ts`
- Modify: `packages/shared/src/index.ts` (re-exports)
- Test: `packages/shared/tests/schemas/member-import.test.ts` + sibling test files

- [ ] **Step 1: Write failing tests for each schema** (one happy row + one invalid row per schema)

Example for `member-import.test.ts`:

```ts
import { describe, it, expect } from 'vitest';
import { MemberImportRowSchema } from '../../src/schemas/member-import.js';

describe('MemberImportRowSchema', () => {
  it('accepts a valid 2025 personnel.csv row (Sola, Jesus — Division Chief OFC)', () => {
    const parsed = MemberImportRowSchema.parse({
      employee_id: '14335',
      last_name: 'Sola',
      first_name: 'Jesus',
      current_rank: 'Division Chief',
      bid_rank: 'Division Chief',
      bid_category: 'OFC',
      bid: 'Include',
      rsc_seniority: '4',
      hired_at: '10/18/1993',
    });
    expect(parsed.bidCategory).toBe('OFC');
    expect(parsed.rank).toBe('DC');
    expect(parsed.rscSeniority).toBe(4);
  });
  it('rejects unknown rank', () => {
    expect(() => MemberImportRowSchema.parse({ employee_id: 'X', last_name: 'X', first_name: 'X', current_rank: 'BOGUS', bid_category: 'OFC', bid: 'Include', rsc_seniority: '1' })).toThrow();
  });
  it('coerces "Exclude" bid → bidCategory EXCLUDED', () => {
    const r = MemberImportRowSchema.parse({ employee_id: '1', last_name: 'A', first_name: 'B', current_rank: 'Fire Chief', bid_category: '0', bid: 'Exclude', rsc_seniority: '1' });
    expect(r.bidCategory).toBe('EXCLUDED');
  });
});
```

- [ ] **Step 2: Run tests, expect FAIL**

- [ ] **Step 3: Implement schemas**

```ts
// packages/shared/src/schemas/member-import.ts
import { z } from 'zod';
import { RANKS } from '../constants/ranks.js';

const RANK_FROM_LABEL: Record<string, typeof RANKS[number]> = {
  'Firefighter': 'FF',
  'Lieutenant': 'LT',
  'Captain': 'CPT',
  'Division Chief': 'DC',
  'Deputy Fire Chief': 'DEP_CHIEF',
  'Fire Chief': 'CHIEF',
};

export const MemberImportRowSchema = z
  .object({
    employee_id: z.string().min(1),
    last_name: z.string().min(1),
    first_name: z.string().min(1),
    current_rank: z.string(),
    bid_rank: z.string().optional(),
    bid_category: z.string(),
    bid: z.enum(['Include', 'Exclude']),
    rsc_seniority: z.union([z.string(), z.number()]).transform(Number),
    hired_at: z.string().optional(),
    promoted_at: z.string().optional(),
  })
  .passthrough()
  .transform((row) => {
    const rank = RANK_FROM_LABEL[row.current_rank];
    if (!rank) throw new Error(`Unknown rank "${row.current_rank}"`);
    const bidCategory = row.bid === 'Exclude' ? 'EXCLUDED' : row.bid_category === 'OFC' ? 'OFC' : 'FF';
    return {
      employeeId: row.employee_id,
      lastName: row.last_name,
      firstName: row.first_name,
      rank,
      bidCategory,
      rscSeniority: row.rsc_seniority,
      hiredAt: row.hired_at,
      promotedAt: row.promoted_at,
    };
  });
export type MemberImportRow = z.infer<typeof MemberImportRowSchema>;
```

(implementer writes the parallel files for `credential-import.ts`, `position.ts`, `rule-book.ts`.)

- [ ] **Step 4: Re-export from `packages/shared/src/index.ts`**

```ts
export * from './schemas/member-import.js';
export * from './schemas/credential-import.js';
export * from './schemas/position.js';
export * from './schemas/rule-book.js';
```

- [ ] **Step 5: Run tests, expect PASS**

- [ ] **Step 6: Commit**

```bash
git add packages/shared/src/schemas packages/shared/src/index.ts packages/shared/tests/schemas
git commit -m "feat(shared): Zod schemas for member/credential/position/rule imports"
```

---

## Task 7: CSV parser wrapper (papaparse + Zod per-row)

**Files:**
- Create: `apps/worker/src/lib/csv-parser.ts`
- Test: `apps/worker/tests/csv-parser.test.ts`

- [ ] **Step 1: Write failing test**

```ts
import { describe, it, expect } from 'vitest';
import { parseCsv } from '../src/lib/csv-parser';
import { MemberImportRowSchema } from '@mbfd/shared';

const FIXTURE = `employee_id,last_name,first_name,current_rank,bid_category,bid,rsc_seniority
14335,Sola,Jesus,Division Chief,OFC,Include,4
99999,Test,Bad,BOGUS,OFC,Include,5`;

describe('parseCsv', () => {
  it('returns ok rows and per-row errors', async () => {
    const result = await parseCsv(FIXTURE, MemberImportRowSchema);
    expect(result.ok).toHaveLength(1);
    expect(result.errors).toHaveLength(1);
    expect(result.errors[0].rowNumber).toBe(2);
  });
});
```

- [ ] **Step 2: Run test, expect FAIL**

- [ ] **Step 3: Implement `csv-parser.ts`**

```ts
import Papa from 'papaparse';
import type { ZodSchema } from 'zod';

export type CsvParseResult<T> = {
  ok: T[];
  errors: { rowNumber: number; raw: unknown; message: string }[];
};

export async function parseCsv<T>(input: string, schema: ZodSchema<T>): Promise<CsvParseResult<T>> {
  const parsed = Papa.parse<Record<string, unknown>>(input, {
    header: true,
    skipEmptyLines: true,
    transformHeader: (h) => h.trim().toLowerCase().replace(/\s+/g, '_'),
  });
  const ok: T[] = [];
  const errors: CsvParseResult<T>['errors'] = [];
  parsed.data.forEach((row, i) => {
    const result = schema.safeParse(row);
    if (result.success) ok.push(result.data);
    else errors.push({ rowNumber: i + 2, raw: row, message: result.error.message });
  });
  return { ok, errors };
}
```

- [ ] **Step 4: Run test, expect PASS. Add a second test using the real `analysis/personnel.csv` golden file (Vitest can read from project root) — expect ≥ 95% of rows accepted.**

- [ ] **Step 5: Commit**

```bash
git add apps/worker/src/lib/csv-parser.ts apps/worker/tests/csv-parser.test.ts
git commit -m "feat(worker): CSV parser with per-row Zod validation"
```

---

## Task 8: XLSX credentials parser (SheetJS)

**Files:**
- Create: `apps/worker/src/lib/xlsx-cred-parser.ts`
- Test: `apps/worker/tests/xlsx-cred-parser.test.ts`

- [ ] **Step 1: Write failing test**

A small fixture xlsx is built inline in the test using `XLSX.utils.book_new()`; for the golden test it loads `D:/MBFD/Bid/2025 Bid Documents/eligible/2025 Bid position requirements and points.xlsx`.

- [ ] **Step 2: Run test, expect FAIL**

- [ ] **Step 3: Implement xlsx-cred-parser**

```ts
import * as XLSX from 'xlsx';
import type { CredentialImportRow } from '@mbfd/shared';
import { CredentialImportRowSchema } from '@mbfd/shared';

export function parseCredentialsXlsx(buf: ArrayBuffer): { ok: CredentialImportRow[]; errors: { rowNumber: number; message: string }[] } {
  const wb = XLSX.read(buf, { type: 'array' });
  const sheet = wb.Sheets[wb.SheetNames[0]];
  const rows = XLSX.utils.sheet_to_json<Record<string, unknown>>(sheet, { defval: '' });
  const ok: CredentialImportRow[] = [];
  const errors: { rowNumber: number; message: string }[] = [];
  rows.forEach((raw, i) => {
    const r = CredentialImportRowSchema.safeParse(raw);
    if (r.success) ok.push(r.data);
    else errors.push({ rowNumber: i + 2, message: r.error.message });
  });
  return { ok, errors };
}
```

- [ ] **Step 4: Run test, expect PASS**

- [ ] **Step 5: Commit**

```bash
git add apps/worker/src/lib/xlsx-cred-parser.ts apps/worker/tests/xlsx-cred-parser.test.ts
git commit -m "feat(worker): credentials XLSX parser (SheetJS + Zod)"
```

---

## Task 9: Admin role middleware

**Files:**
- Create: `apps/worker/src/routes/admin/middleware.ts`
- Test: `apps/worker/tests/admin-middleware.test.ts`

- [ ] **Step 1: Write failing test**

```ts
import { describe, it, expect } from 'vitest';
import { Hono } from 'hono';
import { requireAdmin } from '../src/routes/admin/middleware';
import { signJwt } from '../src/lib/jwt';

describe('requireAdmin middleware', () => {
  it('returns 401 when no Authorization', async () => {
    const app = new Hono().use('*', requireAdmin).get('/x', (c) => c.text('ok'));
    const res = await app.request('/x');
    expect(res.status).toBe(401);
  });
  it('returns 403 for role=member JWT', async () => {
    const jwt = await signJwt({ memberId: 1, role: 'member', employeeId: '1' }, 'test-key');
    const app = new Hono().use('*', requireAdmin).get('/x', (c) => c.text('ok'));
    const res = await app.request('/x', { headers: { Authorization: `Bearer ${jwt}` } });
    expect(res.status).toBe(403);
  });
  it('passes through for role=admin JWT', async () => {
    const jwt = await signJwt({ memberId: 1, role: 'admin', employeeId: '1' }, 'test-key');
    const app = new Hono().use('*', requireAdmin).get('/x', (c) => c.text('ok'));
    const res = await app.request('/x', { headers: { Authorization: `Bearer ${jwt}` } });
    expect(res.status).toBe(200);
  });
});
```

- [ ] **Step 2: Run test, expect FAIL**

- [ ] **Step 3: Implement middleware**

```ts
import type { MiddlewareHandler } from 'hono';
import { verifyJwt } from '../../lib/jwt.js';

export const requireAdmin: MiddlewareHandler = async (c, next) => {
  const auth = c.req.header('Authorization');
  if (!auth?.startsWith('Bearer ')) return c.json({ error: 'missing_auth' }, 401);
  const token = auth.slice(7);
  try {
    const claims = await verifyJwt(token, c.env.JWT_SIGNING_KEY);
    if (claims.role !== 'admin') return c.json({ error: 'forbidden' }, 403);
    c.set('claims', claims);
    await next();
  } catch {
    return c.json({ error: 'invalid_token' }, 401);
  }
};
```

- [ ] **Step 4: Run test, expect PASS. Commit.**

```bash
git add apps/worker/src/routes/admin/middleware.ts apps/worker/tests/admin-middleware.test.ts
git commit -m "feat(worker): admin role middleware (role=admin JWT required)"
```

---

## Task 10: Admin route — member import endpoint

**Files:**
- Create: `apps/worker/src/routes/admin/members.ts`
- Test: `apps/worker/tests/admin-members.test.ts`
- Modify: `apps/worker/src/index.ts` (mount router)

- [ ] **Step 1: Write failing test** (multipart upload of a tiny CSV; admin JWT; expects 200 with `{inserted, errors}` body)

- [ ] **Step 2: Run test, expect FAIL**

- [ ] **Step 3: Implement endpoint**

```ts
import { Hono } from 'hono';
import { requireAdmin } from './middleware.js';
import { parseCsv } from '../../lib/csv-parser.js';
import { MemberImportRowSchema } from '@mbfd/shared';
import { getDb } from '../../db/index.js';
import { members } from '../../db/schema.js';
import { writeAudit } from '../../lib/audit.js';

const r = new Hono<{ Bindings: Env }>();
r.use('*', requireAdmin);

r.post('/import', async (c) => {
  const form = await c.req.formData();
  const file = form.get('file');
  if (!(file instanceof File)) return c.json({ error: 'file_required' }, 400);
  const text = await file.text();
  const { ok, errors } = await parseCsv(text, MemberImportRowSchema);
  const db = getDb(c.env.DB);
  const now = new Date();
  const inserted: string[] = [];
  for (const row of ok) {
    const res = await db
      .insert(members)
      .values({ ...row, createdAt: now, updatedAt: now })
      .onConflictDoUpdate({
        target: members.employeeId,
        set: { ...row, updatedAt: now },
      })
      .returning({ employeeId: members.employeeId });
    inserted.push(...res.map((r) => r.employeeId));
  }
  await writeAudit(db, {
    action: 'members_import',
    actorType: 'admin',
    actorId: c.get('claims').memberId,
    afterState: JSON.stringify({ inserted: inserted.length, errors: errors.length }),
  });
  return c.json({ inserted: inserted.length, errors });
});

r.get('/', async (c) => {
  const db = getDb(c.env.DB);
  const all = await db.select().from(members).all();
  return c.json({ members: all });
});

r.get('/:id', async (c) => {
  const id = Number(c.req.param('id'));
  const db = getDb(c.env.DB);
  const m = await db.select().from(members).where(eq(members.id, id)).get();
  if (!m) return c.json({ error: 'not_found' }, 404);
  return c.json({ member: m });
});

export default r;
```

- [ ] **Step 4: Mount in `src/index.ts`**

```ts
import adminMembers from './routes/admin/members.js';
app.route('/admin/members', adminMembers);
```

- [ ] **Step 5: Run tests (including a golden test that imports `analysis/personnel.csv` to in-memory D1 and verifies ≥ 230 rows landed). Expect PASS.**

- [ ] **Step 6: Commit**

```bash
git add apps/worker/src/routes/admin/members.ts apps/worker/src/index.ts apps/worker/tests/admin-members.test.ts
git commit -m "feat(worker): POST /admin/members/import + GET /admin/members"
```

---

## Task 11: Admin route — credentials import endpoint

Same shape as Task 10 but uses `parseCredentialsXlsx`. Endpoints:

- `POST /admin/credentials/import` (multipart xlsx)
- `GET  /admin/credentials` (paginated)

- [ ] Tests, implementation, mount, commit (same TDD pattern).

```bash
git commit -m "feat(worker): POST /admin/credentials/import + GET /admin/credentials"
```

---

## Task 12: Admin route — positions + rules read-only viewers

**Endpoints:**
- `GET /admin/positions?template_version=2026.1`
- `POST /admin/positions/clone-from-year/:src_year` (deep-copies prior template to new version)
- `GET /admin/rules?rule_book_version=2026.1`

- [ ] Tests for each, implementations, mount, commit.

```bash
git commit -m "feat(worker): admin positions + rules viewers and clone-from-year endpoint"
```

---

## Task 13: Audit log helper (flat — no hash chain yet)

**Files:**
- Create: `apps/worker/src/lib/audit.ts`
- Test: `apps/worker/tests/audit.test.ts`

- [ ] **Step 1: Test that `writeAudit(db, entry)` inserts a row with monotonic seq per session.**

- [ ] **Step 2: Implement using ulid for id and `MAX(seq)+1` lookup scoped to session.**

- [ ] **Step 3: Commit.**

```bash
git commit -m "feat(worker): flat audit_log writer (hash chain in Plan 08)"
```

---

## Task 14: Seed script for 2026 template + rule book + reference credentials

**Files:**
- Create: `apps/worker/seed/2026.ts`
- Create: `apps/worker/seed/fixtures/2026_positions.json`
- Create: `apps/worker/seed/fixtures/2026_rules.json`
- Create: `apps/worker/seed/fixtures/reference_credentials.json`
- Test: `apps/worker/tests/seed.test.ts`

- [ ] **Step 1: Generate the fixtures**

`2026_positions.json` is generated by transforming `analysis/positions.csv` with the 2026 delta from `D:/MBFD/Bid/2026 Bid Documents/2026_Position_Template.md`:
- All `XX5xx` positions → `XX6xx` (station 5 → station 6 rename)
- Station 4 marine flag stripped
- Station 6 reduced to 3 marine slots (FBO/Marine FF/Post)

`2026_rules.json` is hand-derived from `2026_Rules_and_Points.md`.

`reference_credentials.json` is the 57-credential list from the FY25 credentials sheet, with default fy_points.

- [ ] **Step 2: Implement seed script** — reads fixtures, inserts via Drizzle, idempotent (uses `onConflictDoNothing`).

- [ ] **Step 3: Test that running `db:seed:local` twice yields the same row counts** (idempotent).

- [ ] **Step 4: Run `pnpm db:seed:remote` against staging.**

- [ ] **Step 5: Commit.**

```bash
git commit -m "feat(seed): 2026 position template + rule book v2026.1 + reference credentials"
```

---

## Task 15: Hono RPC type export + web client wrapper

**Files:**
- Modify: `apps/worker/src/index.ts` (export `AppType`)
- Create: `apps/web/lib/rpc-client.ts`
- Test: `apps/web/tests/rpc-client.test.ts` (mocked fetch)

- [ ] **Step 1: Export `AppType = typeof app` from worker.**

- [ ] **Step 2: Web client uses `hc<AppType>(workerBaseUrl, { headers: { Authorization: \`Bearer ${jwt}\` } })`.**

- [ ] **Step 3: Test.**

- [ ] **Step 4: Commit.**

```bash
git commit -m "feat(web): typed Hono RPC client for /admin routes"
```

---

## Task 16: Web — admin layout + auth gate

**Files:**
- Create: `apps/web/app/admin/layout.tsx` (Server Component; verifies JWT cookie has role=admin; otherwise redirect to /lobby)
- Create: `apps/web/app/admin/page.tsx` (admin dashboard with links to viewers)
- Test: `apps/web/tests/e2e/admin-layout.spec.ts`

- [ ] **Step 1: Write E2E that hits `/admin` with member-role JWT → 302 to /lobby.**

- [ ] **Step 2: Implement.**

- [ ] **Step 3: Commit.**

```bash
git commit -m "feat(web): /admin layout with role=admin gate"
```

---

## Task 17: Web — members viewer (paginated table)

**Files:**
- Create: `apps/web/app/admin/members/page.tsx` (Server Component; fetches via RPC client)
- Create: `apps/web/components/admin/DataTable.tsx` (TanStack-Table wrapper around shadcn Table)
- Create: `apps/web/app/admin/members/[id]/page.tsx`
- Test: `apps/web/tests/e2e/admin-members.spec.ts`

- [ ] **Step 1: Write E2E happy path (login as admin → /admin/members → see ≥230 rows).**

- [ ] **Step 2: Implement Server Component + DataTable.**

- [ ] **Step 3: Implement member detail page (renders certs list with expiry).**

- [ ] **Step 4: Commit.**

```bash
git commit -m "feat(web): /admin/members viewer + member detail"
```

---

## Task 18: Web — positions + rules viewers

**Files:**
- Create: `apps/web/app/admin/positions/page.tsx` (grouped by shift+station)
- Create: `apps/web/app/admin/rules/page.tsx` (tree view, read-only)
- Test: `apps/web/tests/e2e/admin-positions-rules.spec.ts`

- [ ] **Step 1: E2E renders 230+ positions grouped + rules tree.**

- [ ] **Step 2: Implement.**

- [ ] **Step 3: Commit.**

```bash
git commit -m "feat(web): /admin/positions + /admin/rules read-only viewers"
```

---

## Task 19: Web — import upload UIs

**Files:**
- Create: `apps/web/app/admin/members/import/page.tsx`
- Create: `apps/web/app/admin/credentials/import/page.tsx`
- Create: `apps/web/components/admin/UploadForm.tsx`
- Create: `apps/web/components/admin/ImportResults.tsx`
- Test: `apps/web/tests/e2e/admin-import.spec.ts`

- [ ] **Step 1: E2E uploads `analysis/personnel.csv` → 230+ inserted; bad row reported.**

- [ ] **Step 2: Implement multipart form via Server Action + RPC client.**

- [ ] **Step 3: Commit.**

```bash
git commit -m "feat(web): /admin/members/import + /admin/credentials/import upload flows"
```

---

## Task 20: Wire admin promotion mechanism for the rehearsal

**Goal:** Until Plan 05 (Admin console) lands, we need a way to promote a specific employee_id to admin role so the rehearsal can use the import endpoints.

**Files:**
- Modify: `apps/worker/src/lib/portal-client.ts` — on `verifyCredentials`, if portal returns `is_admin: true`, mint JWT with `role: 'admin'`.
- Add: ENV var `ADMIN_EMPLOYEE_IDS` (comma-separated; staging only, sourced from Wrangler secret) — if portal doesn't yet expose `is_admin`, fall back to this list.
- Test: `apps/worker/tests/auth-admin-promotion.test.ts`

- [ ] **Step 1: Test that login with employee_id in `ADMIN_EMPLOYEE_IDS` returns role=admin.**

- [ ] **Step 2: Implement.**

- [ ] **Step 3: Set the secret on staging.**

- [ ] **Step 4: Commit.**

```bash
git commit -m "feat(auth): admin promotion via ADMIN_EMPLOYEE_IDS allow-list (rehearsal scaffolding)"
```

---

## Task 21: STATUS.md update + Plan 02 sign-off

**Files:**
- Modify: `docs/STATUS.md` (in the `MBFD_Hub` repo)

- [ ] Append Plan 02 completion record + new watch-items surfaced during review.
- [ ] Commit in MBFD_Hub repo.

---

## Acceptance criteria

- [ ] All 4 migrations apply cleanly to a fresh local D1 and to remote staging D1
- [ ] `db:seed:local` and `db:seed:remote` are idempotent (running twice does not duplicate rows)
- [ ] CSV import of `analysis/personnel.csv` lands ≥ 230 members with ≤ 5 row errors (Fire Chief / Excluded rows expected to be EXCLUDED bidCategory)
- [ ] XLSX cred import of `2025 Bid position requirements and points.xlsx` lands ≥ 95 % of credentials
- [ ] `/admin/*` routes return 403 to member-role JWTs and 401 to no auth
- [ ] `/admin/members`, `/admin/members/:id`, `/admin/positions`, `/admin/rules` all render server-side
- [ ] All Drizzle queries are parameterized; no string interpolation into SQL
- [ ] All committed changes pass CI (lint, typecheck, unit + integration, E2E) — no skips beyond the existing W14
- [ ] No emojis introduced into source files

## Notes for the engineer

- **Drizzle SQLite** uses `integer` for booleans and timestamps. Use `mode: 'boolean'` / `mode: 'timestamp'`.
- **JSON columns** in D1 are stored as TEXT. Parse with Zod at the application boundary.
- **`audit_log`** in this plan is the flat shape; hash chain + R2 JSONL are Plan 08.
- **No bid logic in this plan.** If a task wants to add eligibility, point computation, or pick handling, push it to Plan 03/04.
- **Credentials list** — use the 57-name reference list from `2025 Bid position requirements and points.xlsx`. Don't invent credential names; if an import row references one not in the seed, log it as a row error.
- **Station 6** (not Station 5) for 2026 marine — single source of truth is `D:/MBFD/Bid/2026 Bid Documents/2026_Position_Template.md`.
