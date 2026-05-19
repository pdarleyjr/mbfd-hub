# Plan 06 — AI Integration: advisory, deep-dive Q&A, forecast, dissent log

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Date:** 2026-05-17
**Plan:** 06 of 09
**Dependencies:** Plans 01 (foundation), 02 (data plane / schema includes `ai_advisories`), 03 (eligibility engine — `@mbfd/eligibility`), 04 (live bid core + DO state), 05 (admin console + step-up auth) must be complete and merged before this plan begins. Plans 05 and 06 may be developed in parallel branches but Plan 06's `/api/admin/ai/*` routes mount under the admin role middleware from Plan 02 / Plan 05.
**Status:** 📋 detailed TDD — ready to execute.

**Goal:** During a live bid, the admin sees an always-on AI advisory panel that explains the deterministic eligibility output (Plan 03) in natural language, ranks plausible picks, highlights credential shortages, and flags disagreement when the admin force-picks against the recommendation. Two model tiers: **claude-sonnet-4-6** for the hot path (every turn-start, target <2s) and **claude-opus-4-7** for admin-requested deep dives (streaming SSE, target <8s). Prompt caching reduces cost ~90 % so a full bid event (≈250 picks + ≈25 deep dives) stays under **US$15** at the AI Gateway.

**Architecture sketch:**

```
DO advances cursor                 cron */10 min (if live)
       │                                   │
       ▼                                   ▼
/api/admin/ai/advise-current        scheduled refresh
       │  (sync, Sonnet)                   │
       ▼                                   ▼
  buildPrompt(systemBlock,         /api/admin/ai/forecast (KV-cached 30s)
              userRosterBlock,
              userTurnBlock)
       │
       ▼
  AnthropicAIClient ──► Cloudflare AI Gateway
                         │
                         ▼
                  Anthropic Messages API
                  (prompt-cache breakpoints
                   after system + after roster)
       │
       ▼
  Zod-validate JSON → render to admin panel
       │                            │
       ▼                            ▼
  ai_advisories row            AIAdvisoryPanel.tsx
  (latency, cost, hit-ratio)
```

When the admin clicks "Force pick" or "Skip" via Plan 05, the route fans out a fire-and-forget call to the dissent writer, which compares the admin's action to the AI's most-recent advisory for that bidder; if they disagree, an `audit_log.action='dissent'` row is appended with the AI's reasoning.

---

## Decisions documented up-front

These are intentional and reviewers should not relitigate them mid-implementation.

| # | Decision | Reasoning |
|---|----------|-----------|
| D06-01 | **Anthropic SDK version pinned to `@anthropic-ai/sdk@^0.96.0`** (peer dep: `zod ^3.25.0 \|\| ^4.0.0`). Verified on npm 2026-05-17. | Stable Messages API; supports `cache_control` ephemeral cache; supports streaming via `messages.stream`. |
| D06-02 | **All API calls route through Cloudflare AI Gateway**, never directly to `api.anthropic.com`. Base URL is `https://gateway.ai.cloudflare.com/v1/<CF_ACCOUNT_ID>/mbfd-bid/anthropic` and is injected as `CF_AI_GATEWAY_URL` Worker env var. | AI Gateway gives us per-request logging, retry, rate-limit, and the option to cut over to an alternate provider without code changes. |
| D06-03 | **Three-layer prompt cache** with cache breakpoints **after** the system block AND **after** the user-roster block. No cache breakpoint inside the user-turn block. | Per spec §11.4 — system rules and roster change rarely; per-turn delta must be fully uncached so the model sees fresh state on every call. |
| D06-04 | **Cache TTL: ephemeral (5 minutes default, server-side managed)**. We do not opt into the 1-hour beta. We DO opt into the **`prompt-caching-2024-07-31`** beta header where required by the SDK version. | Anthropic refreshes the cache on every cache-hit; in a live bid the cache stays warm because we call every turn (<60 s apart). The 5-minute window is sufficient between turn-start, on-deck pre-fetch, and dissent check. |
| D06-05 | **`/advise-current` returns a single JSON object (non-streaming).** | The admin panel needs the entire structured object to render four sections (summary / recommendations / ineligible top-picks / forecast). Streaming partial JSON is a footgun (incomplete keys, broken renders) and gives zero perceived-latency win for ~1 s generation. |
| D06-06 | **`/advise-deep` streams Server-Sent Events.** | Opus generation is 4-8 s; admin watches tokens arrive — visible progress is the entire reason for the deep-dive UX. Output is unstructured markdown, so streaming is safe. |
| D06-07 | **Cost accounted in integer cents** (`cost_cents: number` schema column), never floating-point dollars. We compute `cost_cents = round(input_tokens × in_price + cache_read_tokens × in_price × 0.1 + cache_write_tokens × in_price × 1.25 + output_tokens × out_price) × 100`. Per-model pricing is in a constant table; bumping it is a 1-line PR. | Avoids float drift; one source of truth; readable in audit log. |
| D06-08 | **Fallback behavior when the AI Gateway returns 5xx or the request times out (>2.5 s for Sonnet, >12 s for Opus):** the route returns the **last successful advisory for the same `(bid_session_id, member_id)` from KV** with `stale: true` flag set in the response. If no prior advisory exists, return a deterministic-only fallback object (eligible positions ranked by points, ineligible top-picks listed by point count, no narrative) with `stale: true, fallback: 'deterministic'`. **The bid never blocks on the AI.** | Per the spec, AI is advisory; bid mechanics must continue at human pace even if AI is down. |
| D06-09 | **Where the rule-book Markdown lives at runtime:** the three `.md` files (`2026_Bid_Process.md`, `2026_Rules_and_Points.md`, `2026_Position_Template.md`) are **bundled at build time** into `apps/worker/src/ai/prompts/rulebook-2026.generated.ts` via a tiny `tsx` codegen script. NOT fetched from R2 at request time. | Eliminates one network hop per request, deterministic per Worker deploy, content is small (~14 KB after minification), trivially versioned with the Worker. |
| D06-10 | **AI never decides eligibility.** The `evaluateEligibility()` import from `@mbfd/eligibility` (Plan 03) is the sole source of the eligibility boolean. The AI receives the resulting struct, period. The system prompt explicitly tells the model "do not recompute, do not contradict". | Per spec §11.2 — separation of concerns; testable; auditable; resistant to model drift. |
| D06-11 | **Feature flag: `ai_advisory_enabled` in KV.** When false, the panel hides, all `/advise-*` routes return 503 with `{ disabled: true }`, the dissent writer is a no-op. | Per spec §14 D8 the AI panel must be mutable. Lets the admin kill the AI mid-bid without redeploying. |
| D06-12 | **Cost budget cap per session: $25** (hard cap, configurable in KV). When the running session total exceeds the cap, the AI client refuses further calls and the panel renders "AI muted — budget cap reached". Dissent writer continues to log dissents using the most recent cached advisory. | Belts-and-braces against runaway costs from an admin tab left open over a multi-day bid. |
| D06-13 | **Eval harness lives in `apps/worker/eval/` and is not part of CI.** It is a one-shot script that replays 2025 bid_pick.csv through the AI with golden inputs and writes a report to `docs/ai-eval/2025-replay.md`. Run manually before each yearly release. | CI runs would be expensive and slow; the eval is for a once-a-year-or-so calibration check. |
| D06-14 | **No tool-use, no function-calling, no extended-thinking in Plan 06.** Plain Messages API with `messages.create` (or `messages.stream` for Opus). | Reduces complexity; structured output via JSON-mode is sufficient and cheaper than tool use. |

---

## Tech stack additions on top of Plans 01-05

| Dep | Version | Use |
|-----|---------|-----|
| `@anthropic-ai/sdk` | `^0.96.0` | Anthropic Messages client (Worker-compatible — exports `fetch`-based runtime) |
| `eventsource-parser` | `^3.0.0` | Parse Anthropic SSE deltas on the Worker side for `/advise-deep` re-streaming |
| `zod` | already installed `^3.25` | Validate structured AI output |
| `crypto-js` | NOT added — we use `crypto.subtle` for prompt hash | Worker-native, zero deps |

No new D1 migrations: `ai_advisories` table already added in Plan 02 migration `0004_bid_audit_ai.sql`. We add a new audit action `dissent` via an enum extension (covered in Task 11).

---

## File map (everything Plan 06 creates or modifies)

```
apps/worker/
  src/
    ai/
      client.ts                                ← Anthropic SDK wrapper pointed at AI Gateway; cost accounting; KV stale-fallback
      pricing.ts                               ← model → input/output/cache-read/cache-write cents-per-MTok
      cost-accounting.ts                       ← computeCostCents(); KV running-total writer
      prompts/
        rulebook-2026.generated.ts             ← BUILD-TIME OUTPUT (gitignored) — concat of three rulebook .md files
        rulebook-codegen.ts                    ← tsx script that emits the generated file
        system-2026.ts                         ← export systemBlock() → typed Anthropic system param with cache breakpoint
        user-roster.ts                         ← export rosterBlock(session) → user message + cache breakpoint
        user-turn.ts                           ← export turnBlock(state, question) → uncached delta
      output-schema.ts                         ← Zod schema for advisory JSON (matches spec §11.3)
      output-parser.ts                         ← extract JSON from Claude response; safeParse with fallback
      cache-warm.ts                            ← on-deck pre-fetch into KV (called from DO advance hook)
      dissent.ts                               ← compareAdminActionToAdvisory(); write `dissent` audit row
      eval/
        replay-2025.ts                         ← runs 2025 bid through AI; writes report
      __fixtures__/
        advisory-canonical.json                ← golden parsed advisory shape
        advisory-malformed.json                ← truncated JSON; tests fallback
    routes/
      ai.ts                                    ← mounts GET /advise-current, POST /advise-deep, GET /forecast
    scheduled.ts                               ← Cron handler — */10min forecast refresh when live
  tests/
    ai/
      client.test.ts                           ← AI Gateway URL injection; cost computation; stale-fallback
      pricing.test.ts                          ← per-model cents lookup; zero on unknown
      cost-accounting.test.ts                  ← running total in KV
      output-schema.test.ts                    ← Zod accepts canonical; rejects malformed
      output-parser.test.ts                    ← strips ```json fences; returns null on garbage
      cache-warm.test.ts                       ← pre-fetch writes pre_fetch:<member_id> KV
      dissent.test.ts                          ← dissent row written iff force_recommended !== true
      prompts.test.ts                          ← system / roster / turn block shapes + breakpoints
    routes/
      ai-advise-current.test.ts                ← happy path; degraded fallback; rate-limit
      ai-advise-deep.test.ts                   ← SSE streaming; closes on abort
      ai-forecast.test.ts                      ← KV-cached; refreshed by cron
      ai-mute-budget.test.ts                   ← budget cap returns 503
      ai-mute-flag.test.ts                     ← KV feature flag returns 503
    scheduled.test.ts                          ← cron handler hits forecast endpoint

apps/web/
  app/admin/bid/_components/
    AIAdvisoryPanel.tsx                        ← always-visible side panel (TanStack Query)
    AIForecastBanner.tsx                       ← top-of-page warnings
    AIAskDeepDialog.tsx                        ← admin's deep-dive form + SSE renderer
    AIDissentMarker.tsx                        ← inline badge on audit-log entries
    AICostPill.tsx                             ← running cost in admin header
  lib/
    ai-sse-client.ts                           ← EventSource wrapper for /advise-deep
  tests/
    ai-panel.spec.ts                           ← Playwright; panel renders <2s after turn-start
    ai-deep-dialog.spec.ts                     ← Playwright; SSE tokens arrive
    ai-dissent-marker.spec.ts                  ← Playwright; force-pick produces yellow badge

packages/shared/src/schemas/
  ai-advisory.ts                               ← mirror of worker output-schema (consumed by web)

docs/
  ai-eval/
    .gitkeep                                   ← directory marker; reports land here
```

Files we **modify** (not create):

- `apps/worker/src/index.ts` — mount `/api/admin/ai/*` routes; add `scheduled` export
- `apps/worker/src/types/env.d.ts` — add AI env vars (gateway URL, key, KV bindings)
- `apps/worker/src/lib/env.ts` — extend Zod env schema
- `apps/worker/wrangler.toml` — add cron trigger + AI env vars on both staging and production
- `apps/worker/src/lib/audit.ts` — extend `AuditAction` union with `'dissent'`
- `apps/worker/src/db/schema.ts` — extend `auditLog.action` enum with `'dissent'` (migration `0005_audit_dissent_action.sql`)
- `apps/worker/migrations/0005_audit_dissent_action.sql` — new migration
- `apps/web/app/admin/bid/page.tsx` — slot the panel + banner + cost pill
- `packages/shared/src/index.ts` — re-export `ai-advisory` schema

---

## Source data reference

| File | Use |
|------|-----|
| `D:/MBFD/Bid/2026 Bid Documents/2026_Bid_Process.md` | System prompt component (181 lines) |
| `D:/MBFD/Bid/2026 Bid Documents/2026_Rules_and_Points.md` | System prompt component (269 lines) |
| `D:/MBFD/Bid/2026 Bid Documents/2026_Position_Template.md` | System prompt component (117 lines) |
| `D:/GitHub_Repos/MBFD_Hub/analysis/bid_pick.csv` | Eval harness — 2025 actuals to replay (Task 14) |
| `D:/GitHub_Repos/MBFD_Hub/analysis/personnel.csv` | Eval harness — 2025 roster |
| `docs/superpowers/specs/2026-05-17-mbfd-bid-webapp-design.md` §11 | Authoritative AI-integration spec |

---

## Task 1: Worker env additions + Zod env schema extension

**Files:**
- Modify: `apps/worker/src/types/env.d.ts`
- Modify: `apps/worker/src/lib/env.ts`
- Modify: `apps/worker/wrangler.toml`
- Test: `apps/worker/tests/lib/env.test.ts` (extend existing)

**Goal:** the Worker knows about `CF_AI_GATEWAY_URL`, `ANTHROPIC_API_KEY`, the `AI_KV` namespace, and the budget cap KV key. All env vars validated at startup; the Worker refuses to boot with malformed config.

- [ ] **Step 1: Extend the existing env Zod schema test**

```ts
// apps/worker/tests/lib/env.test.ts
import { describe, it, expect } from 'vitest';
import { EnvSchema } from '../../src/lib/env.js';

describe('EnvSchema (Plan 06 additions)', () => {
  const base = {
    ENV: 'staging',
    PORTAL_BASE_URL: 'https://portal.mbfdhub.com',
    JWT_SIGNING_KEY: 'a'.repeat(32),
    PIN_HASH: 'xxx',
    PORTAL_BID_READER: 'xxx',
  } as const;

  it('accepts AI env when fully populated', () => {
    const parsed = EnvSchema.parse({
      ...base,
      CF_AI_GATEWAY_URL: 'https://gateway.ai.cloudflare.com/v1/abc/mbfd-bid/anthropic',
      ANTHROPIC_API_KEY: 'sk-ant-test',
      AI_BUDGET_CAP_CENTS: '2500',
      AI_FEATURE_FLAG_KEY: 'ai_advisory_enabled',
    });
    expect(parsed.CF_AI_GATEWAY_URL).toMatch(/^https:\/\//);
    expect(parsed.AI_BUDGET_CAP_CENTS).toBe(2500);
  });

  it('rejects a non-HTTPS gateway URL', () => {
    expect(() =>
      EnvSchema.parse({ ...base, CF_AI_GATEWAY_URL: 'http://insecure', ANTHROPIC_API_KEY: 'k' }),
    ).toThrow();
  });

  it('defaults AI_BUDGET_CAP_CENTS to 2500 when omitted', () => {
    const parsed = EnvSchema.parse({
      ...base,
      CF_AI_GATEWAY_URL: 'https://gateway.ai.cloudflare.com/v1/abc/mbfd-bid/anthropic',
      ANTHROPIC_API_KEY: 'sk',
    });
    expect(parsed.AI_BUDGET_CAP_CENTS).toBe(2500);
  });
});
```

- [ ] **Step 2: Run test, expect FAIL**

```
pnpm --filter @mbfd/worker test env
```

Expected: parse error — schema does not know `CF_AI_GATEWAY_URL`.

- [ ] **Step 3: Extend `apps/worker/src/lib/env.ts`**

```ts
import bcrypt from 'bcryptjs';
import { z } from 'zod';

export const LOCAL_ADMIN_USERNAME = 'admin';

export const EnvSchema = z.object({
  ENV: z.enum(['staging', 'production']),
  PORTAL_BASE_URL: z.string().url(),
  JWT_SIGNING_KEY: z.string().min(32),
  PIN_HASH: z.string().min(1),
  PORTAL_BID_READER: z.string().min(1),
  LOCAL_ADMIN_PASSWORD_HASH: z.string().optional().default(''),
  // Plan 06 — AI integration
  CF_AI_GATEWAY_URL: z
    .string()
    .url()
    .refine((u) => u.startsWith('https://'), 'Gateway URL must be https'),
  ANTHROPIC_API_KEY: z.string().min(1),
  AI_BUDGET_CAP_CENTS: z
    .union([z.string(), z.number()])
    .transform((v) => (typeof v === 'string' ? Number(v) : v))
    .pipe(z.number().int().nonnegative())
    .default(2500),
  AI_FEATURE_FLAG_KEY: z.string().default('ai_advisory_enabled'),
});

export type ValidatedEnv = z.infer<typeof EnvSchema>;
export function validateEnv(env: unknown): ValidatedEnv { return EnvSchema.parse(env); }
export function verifyLocalAdminPassword(passwordHash: string, candidate: string): boolean {
  if (!passwordHash || !candidate) return false;
  try { return bcrypt.compareSync(candidate, passwordHash); } catch { return false; }
}
```

- [ ] **Step 4: Extend `apps/worker/src/types/env.d.ts`**

```ts
import type { D1Database, KVNamespace } from '@cloudflare/workers-types';

export interface WorkerEnv {
  ENV: 'staging' | 'production';
  PORTAL_BASE_URL: string;
  JWT_SIGNING_KEY: string;
  PIN_HASH: string;
  PORTAL_BID_READER: string;
  LOCAL_ADMIN_PASSWORD_HASH?: string;
  // Plan 06
  CF_AI_GATEWAY_URL: string;
  ANTHROPIC_API_KEY: string;
  AI_BUDGET_CAP_CENTS: number;
  AI_FEATURE_FLAG_KEY: string;
  // Bindings
  DB: D1Database;
  KV: KVNamespace;
  /** Separate namespace for AI cache / cost / fallback. Kept distinct
   * from the auth KV so eviction policy can differ. */
  AI_KV: KVNamespace;
}
```

- [ ] **Step 5: Extend `apps/worker/wrangler.toml`** — staging section

```toml
[env.staging]
name = "mbfd-bid-worker-staging"
routes = [{ pattern = "api.staging.bid.mbfdhub.com", custom_domain = true }]
vars = { ENV = "staging", PORTAL_BASE_URL = "https://portal.mbfdhub.com", CF_AI_GATEWAY_URL = "https://gateway.ai.cloudflare.com/v1/265122b6d6f29457b0ca950c55f3ac6e/mbfd-bid/anthropic", AI_BUDGET_CAP_CENTS = "2500", AI_FEATURE_FLAG_KEY = "ai_advisory_enabled" }

# Existing D1 + KV bindings unchanged…

[[env.staging.kv_namespaces]]
binding = "AI_KV"
id = "REPLACE_AFTER_kv_create_ai"

[triggers]
crons = ["*/10 * * * *"]
```

(Production block gets the same treatment with `AI_BUDGET_CAP_CENTS = "5000"` and the production gateway URL.)

- [ ] **Step 6: Create the staging AI_KV namespace**

```
wrangler kv:namespace create AI_KV --env staging
```

Take the returned id and replace `REPLACE_AFTER_kv_create_ai` in `wrangler.toml`.

- [ ] **Step 7: Set the Anthropic secret** (never commit the value)

```
wrangler secret put ANTHROPIC_API_KEY --env staging
```

- [ ] **Step 8: Run tests, expect PASS**

```
pnpm --filter @mbfd/worker test env
```

- [ ] **Step 9: Commit**

```bash
git add apps/worker/src/types/env.d.ts apps/worker/src/lib/env.ts apps/worker/wrangler.toml apps/worker/tests/lib/env.test.ts
git commit -m "feat(worker): add AI Gateway env vars + AI_KV binding"
```

---

## Task 2: Pricing table + cost accounting

**Files:**
- Create: `apps/worker/src/ai/pricing.ts`
- Create: `apps/worker/src/ai/cost-accounting.ts`
- Test: `apps/worker/tests/ai/pricing.test.ts`
- Test: `apps/worker/tests/ai/cost-accounting.test.ts`

**Goal:** deterministic, integer-cents cost calculation per Anthropic call; per-session running total in KV.

- [ ] **Step 1: Write failing test for pricing.ts**

```ts
// apps/worker/tests/ai/pricing.test.ts
import { describe, it, expect } from 'vitest';
import { MODEL_PRICING, computeCostCents } from '../../src/ai/pricing.js';

describe('MODEL_PRICING', () => {
  it('has entries for both Sonnet and Opus production aliases', () => {
    expect(MODEL_PRICING['claude-sonnet-4-6']).toBeDefined();
    expect(MODEL_PRICING['claude-opus-4-7']).toBeDefined();
  });

  it('input price for Sonnet is positive cents per MTok', () => {
    expect(MODEL_PRICING['claude-sonnet-4-6'].inputCentsPerMTok).toBeGreaterThan(0);
  });

  it('cache-read is exactly 10% of input for both models', () => {
    for (const k of ['claude-sonnet-4-6', 'claude-opus-4-7'] as const) {
      const p = MODEL_PRICING[k];
      expect(p.cacheReadCentsPerMTok).toBeCloseTo(p.inputCentsPerMTok * 0.1, 4);
    }
  });
});

describe('computeCostCents', () => {
  it('returns 0 for unknown model', () => {
    expect(computeCostCents('claude-unknown', { input: 100, output: 50, cacheRead: 0, cacheWrite: 0 })).toBe(0);
  });

  it('computes integer cents — Sonnet 1000 input / 500 output', () => {
    const c = computeCostCents('claude-sonnet-4-6', { input: 1000, output: 500, cacheRead: 0, cacheWrite: 0 });
    expect(Number.isInteger(c)).toBe(true);
    expect(c).toBeGreaterThan(0);
  });

  it('cache-read tokens count 10% of input rate', () => {
    const c1 = computeCostCents('claude-sonnet-4-6', { input: 10_000, output: 0, cacheRead: 0, cacheWrite: 0 });
    const c2 = computeCostCents('claude-sonnet-4-6', { input: 0, output: 0, cacheRead: 10_000, cacheWrite: 0 });
    expect(c2).toBeCloseTo(c1 / 10, 0);
  });

  it('cache-write tokens count 1.25× input rate', () => {
    const c1 = computeCostCents('claude-sonnet-4-6', { input: 10_000, output: 0, cacheRead: 0, cacheWrite: 0 });
    const c2 = computeCostCents('claude-sonnet-4-6', { input: 0, output: 0, cacheRead: 0, cacheWrite: 10_000 });
    expect(c2 / c1).toBeCloseTo(1.25, 1);
  });
});
```

- [ ] **Step 2: Run test, expect FAIL**

- [ ] **Step 3: Implement `apps/worker/src/ai/pricing.ts`**

```ts
// apps/worker/src/ai/pricing.ts
// Source of truth for per-model pricing. Bump in a 1-line PR when Anthropic
// updates rates. Prices below are in CENTS per MILLION input/output tokens
// (USD pricing converted; cache-read is 10% of input, cache-write is 1.25×
// input, per Anthropic prompt-caching docs as of 2026-05).

export interface ModelPricing {
  /** Cents per 1M input tokens (non-cached). */
  inputCentsPerMTok: number;
  /** Cents per 1M output tokens. */
  outputCentsPerMTok: number;
  /** Cents per 1M input tokens served from cache (=inputCentsPerMTok × 0.1). */
  cacheReadCentsPerMTok: number;
  /** Cents per 1M input tokens written to cache (=inputCentsPerMTok × 1.25). */
  cacheWriteCentsPerMTok: number;
}

function pricing(input: number, output: number): ModelPricing {
  return {
    inputCentsPerMTok: input,
    outputCentsPerMTok: output,
    cacheReadCentsPerMTok: input * 0.1,
    cacheWriteCentsPerMTok: input * 1.25,
  };
}

export const MODEL_PRICING: Readonly<Record<string, ModelPricing>> = {
  // Sonnet 4.6: $3 / MTok input, $15 / MTok output → cents
  'claude-sonnet-4-6': pricing(300, 1500),
  // Opus 4.7: $15 / MTok input, $75 / MTok output → cents
  'claude-opus-4-7': pricing(1500, 7500),
};

export interface TokenUsage {
  input: number;
  output: number;
  cacheRead: number;
  cacheWrite: number;
}

export function computeCostCents(modelId: string, u: TokenUsage): number {
  const p = MODEL_PRICING[modelId];
  if (!p) return 0;
  const cents =
    (u.input * p.inputCentsPerMTok +
      u.cacheRead * p.cacheReadCentsPerMTok +
      u.cacheWrite * p.cacheWriteCentsPerMTok +
      u.output * p.outputCentsPerMTok) /
    1_000_000;
  return Math.round(cents);
}
```

- [ ] **Step 4: Run test, expect PASS**

- [ ] **Step 5: Write failing test for cost-accounting.ts**

```ts
// apps/worker/tests/ai/cost-accounting.test.ts
import { describe, it, expect, beforeEach, vi } from 'vitest';
import { addSessionCostCents, getSessionCostCents, COST_KEY_PREFIX } from '../../src/ai/cost-accounting.js';

const makeKv = () => {
  const store = new Map<string, string>();
  return {
    store,
    get: vi.fn(async (k: string) => store.get(k) ?? null),
    put: vi.fn(async (k: string, v: string) => void store.set(k, v)),
    delete: vi.fn(async (k: string) => void store.delete(k)),
    list: vi.fn(),
    getWithMetadata: vi.fn(),
  } as any;
};

describe('cost-accounting', () => {
  let kv: ReturnType<typeof makeKv>;
  beforeEach(() => { kv = makeKv(); });

  it('returns 0 when no key', async () => {
    expect(await getSessionCostCents(kv, 'sess1')).toBe(0);
  });

  it('add then get matches', async () => {
    await addSessionCostCents(kv, 'sess1', 7);
    expect(await getSessionCostCents(kv, 'sess1')).toBe(7);
  });

  it('multiple adds accumulate', async () => {
    await addSessionCostCents(kv, 'sess1', 7);
    await addSessionCostCents(kv, 'sess1', 13);
    expect(await getSessionCostCents(kv, 'sess1')).toBe(20);
  });

  it('per-session isolation', async () => {
    await addSessionCostCents(kv, 'sessA', 5);
    await addSessionCostCents(kv, 'sessB', 9);
    expect(await getSessionCostCents(kv, 'sessA')).toBe(5);
    expect(await getSessionCostCents(kv, 'sessB')).toBe(9);
  });

  it('storage key uses COST_KEY_PREFIX', async () => {
    await addSessionCostCents(kv, 'X', 1);
    expect([...kv.store.keys()][0]).toBe(`${COST_KEY_PREFIX}X`);
  });
});
```

- [ ] **Step 6: Run test, expect FAIL**

- [ ] **Step 7: Implement `apps/worker/src/ai/cost-accounting.ts`**

```ts
// apps/worker/src/ai/cost-accounting.ts
import type { KVNamespace } from '@cloudflare/workers-types';

export const COST_KEY_PREFIX = 'ai_cost_cents:';
const TTL_SECONDS = 60 * 60 * 24 * 14; // 14 days — bid + report period

/** Atomic-ish add (KV does not support increments; we read-modify-write). */
export async function addSessionCostCents(
  kv: KVNamespace,
  bidSessionId: string,
  deltaCents: number,
): Promise<number> {
  const key = COST_KEY_PREFIX + bidSessionId;
  const current = await kv.get(key);
  const next = (current ? Number(current) : 0) + deltaCents;
  await kv.put(key, String(next), { expirationTtl: TTL_SECONDS });
  return next;
}

export async function getSessionCostCents(
  kv: KVNamespace,
  bidSessionId: string,
): Promise<number> {
  const v = await kv.get(COST_KEY_PREFIX + bidSessionId);
  return v ? Number(v) : 0;
}
```

- [ ] **Step 8: Run test, expect PASS**

- [ ] **Step 9: Commit**

```bash
git add apps/worker/src/ai/pricing.ts apps/worker/src/ai/cost-accounting.ts apps/worker/tests/ai/pricing.test.ts apps/worker/tests/ai/cost-accounting.test.ts
git commit -m "feat(ai): per-model cents pricing + per-session KV cost accounting"
```

---

## Task 3: Output schema (Zod for advisory JSON)

**Files:**
- Create: `apps/worker/src/ai/output-schema.ts`
- Create: `packages/shared/src/schemas/ai-advisory.ts` (mirror)
- Modify: `packages/shared/src/index.ts` (re-export)
- Test: `apps/worker/tests/ai/output-schema.test.ts`
- Create: `apps/worker/tests/ai/__fixtures__/advisory-canonical.json`
- Create: `apps/worker/tests/ai/__fixtures__/advisory-malformed.json`

**Goal:** the AI returns structured JSON; Zod is the gate. Spec §11.3 prescribes the shape verbatim.

- [ ] **Step 1: Drop the canonical fixture**

```json
// apps/worker/tests/ai/__fixtures__/advisory-canonical.json
{
  "summary": "Lt. Garcia (RSC 23) has 4 eligible Captain seats. Best fit by points: B105 (Marine Captain).",
  "eligible_recommendations": [
    { "position_id": "B105", "points": 9, "why": "Holds all 6 Ops + Marine Captain + Boat Operator" },
    { "position_id": "A104", "points": 7, "why": "Strong Operations stack; no Marine creds" }
  ],
  "ineligible_top_picks": [
    { "position_id": "D101", "why_ineligible": "Requires State of Florida Inspector" }
  ],
  "forecast": {
    "warnings": [
      { "level": "warn", "text": "Paramedic credential pool running low; 4 remaining for 6 unfilled rescue seats", "affected_positions": ["A305","B305","C305","A306"] }
    ]
  },
  "force_recommended": false
}
```

- [ ] **Step 2: Drop the malformed fixture** (truncated JSON, valid type but invalid value)

```json
// apps/worker/tests/ai/__fixtures__/advisory-malformed.json
{
  "summary": 42,
  "eligible_recommendations": [],
  "force_recommended": "yes"
}
```

- [ ] **Step 3: Write failing test**

```ts
// apps/worker/tests/ai/output-schema.test.ts
import { describe, it, expect } from 'vitest';
import { AdvisorySchema } from '../../src/ai/output-schema.js';
import canonical from './__fixtures__/advisory-canonical.json';
import malformed from './__fixtures__/advisory-malformed.json';

describe('AdvisorySchema', () => {
  it('accepts the canonical fixture', () => {
    const parsed = AdvisorySchema.parse(canonical);
    expect(parsed.summary.length).toBeGreaterThan(0);
    expect(parsed.eligible_recommendations).toHaveLength(2);
    expect(parsed.force_recommended).toBe(false);
  });

  it('rejects malformed (summary=number, force_recommended=string)', () => {
    const r = AdvisorySchema.safeParse(malformed);
    expect(r.success).toBe(false);
  });

  it('forecast.warnings is optional but defaults to []', () => {
    const r = AdvisorySchema.parse({ ...canonical, forecast: { warnings: [] } });
    expect(r.forecast.warnings).toEqual([]);
  });

  it('force_reasoning is optional but required when force_recommended=true', () => {
    const ok = AdvisorySchema.parse({ ...canonical, force_recommended: true, force_reasoning: 'last credentialed bidder' });
    expect(ok.force_recommended).toBe(true);
    const bad = AdvisorySchema.safeParse({ ...canonical, force_recommended: true });
    expect(bad.success).toBe(false);
  });

  it('warning level enum: info | warn | critical only', () => {
    const bad = AdvisorySchema.safeParse({
      ...canonical,
      forecast: { warnings: [{ level: 'urgent', text: 'x', affected_positions: [] }] },
    });
    expect(bad.success).toBe(false);
  });
});
```

- [ ] **Step 4: Run test, expect FAIL**

- [ ] **Step 5: Implement `apps/worker/src/ai/output-schema.ts`**

```ts
// apps/worker/src/ai/output-schema.ts
import { z } from 'zod';

export const WarningSchema = z.object({
  level: z.enum(['info', 'warn', 'critical']),
  text: z.string().min(1),
  affected_positions: z.array(z.string()).default([]),
});
export type Warning = z.infer<typeof WarningSchema>;

export const EligibleRecSchema = z.object({
  position_id: z.string().min(1),
  points: z.number().int().nonnegative(),
  why: z.string().min(1),
});
export type EligibleRec = z.infer<typeof EligibleRecSchema>;

export const IneligibleTopPickSchema = z.object({
  position_id: z.string().min(1),
  why_ineligible: z.string().min(1),
});
export type IneligibleTopPick = z.infer<typeof IneligibleTopPickSchema>;

export const AdvisorySchema = z
  .object({
    summary: z.string().min(1),
    eligible_recommendations: z.array(EligibleRecSchema),
    ineligible_top_picks: z.array(IneligibleTopPickSchema),
    forecast: z.object({ warnings: z.array(WarningSchema).default([]) }).default({ warnings: [] }),
    force_recommended: z.boolean(),
    force_reasoning: z.string().optional(),
  })
  .superRefine((val, ctx) => {
    if (val.force_recommended && !val.force_reasoning) {
      ctx.addIssue({
        code: z.ZodIssueCode.custom,
        message: 'force_reasoning required when force_recommended=true',
        path: ['force_reasoning'],
      });
    }
  });
export type Advisory = z.infer<typeof AdvisorySchema>;

/** Marker fields added at the API boundary; not produced by the model. */
export const AdvisoryEnvelopeSchema = z.object({
  advisory: AdvisorySchema,
  stale: z.boolean().default(false),
  fallback: z.enum(['none', 'deterministic', 'last_good']).default('none'),
  generated_at_ms: z.number().int().nonnegative(),
  ai_advisory_id: z.string().nullable(),
});
export type AdvisoryEnvelope = z.infer<typeof AdvisoryEnvelopeSchema>;
```

- [ ] **Step 6: Mirror to `packages/shared/src/schemas/ai-advisory.ts`** (exact copy — the web app needs the same types). Add a build-time grep guard to fail CI if the two files diverge:

```ts
// packages/shared/src/schemas/ai-advisory.ts
export * from './ai-advisory.copy.js';
// where ai-advisory.copy.js is verbatim copy of the worker file — kept as a build artifact
```

Implementer note: simplest is to duplicate the file content; a single-source export would require an extra workspace package — out of scope. The Vitest test below ensures they don't drift.

- [ ] **Step 7: Add a duplicate-detector test**

```ts
// packages/shared/tests/schemas/ai-advisory-mirror.test.ts
import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = fileURLToPath(import.meta.url);
const root = resolve(here, '../../../../../');

describe('ai-advisory schema sync', () => {
  it('worker copy and shared copy are byte-identical (modulo the export header)', () => {
    const worker = readFileSync(resolve(root, 'apps/worker/src/ai/output-schema.ts'), 'utf8');
    const shared = readFileSync(resolve(root, 'packages/shared/src/schemas/ai-advisory.ts'), 'utf8');
    // Strip comment headers — compare just the schema bodies
    const stripHeader = (s: string) => s.split('\n').filter((l) => !l.startsWith('//')).join('\n');
    expect(stripHeader(worker)).toBe(stripHeader(shared));
  });
});
```

- [ ] **Step 8: Re-export from `packages/shared/src/index.ts`**

```ts
export * from './schemas/ai-advisory.js';
```

- [ ] **Step 9: Run all tests, expect PASS**

- [ ] **Step 10: Commit**

```bash
git add apps/worker/src/ai/output-schema.ts packages/shared/src/schemas/ai-advisory.ts packages/shared/src/index.ts apps/worker/tests/ai/output-schema.test.ts apps/worker/tests/ai/__fixtures__ packages/shared/tests/schemas/ai-advisory-mirror.test.ts
git commit -m "feat(ai): Zod schema for advisory JSON output + cross-package sync test"
```

---

## Task 4: Output parser — extract JSON from Claude response

**Files:**
- Create: `apps/worker/src/ai/output-parser.ts`
- Test: `apps/worker/tests/ai/output-parser.test.ts`

**Goal:** Claude sometimes wraps JSON in ```json fences or prefixes prose. The parser strips those and runs Zod safeParse, returning either the validated object or `null` (caller chooses fallback).

- [ ] **Step 1: Write failing test**

```ts
// apps/worker/tests/ai/output-parser.test.ts
import { describe, it, expect } from 'vitest';
import { parseAdvisoryFromText } from '../../src/ai/output-parser.js';
import canonical from './__fixtures__/advisory-canonical.json';

const canonStr = JSON.stringify(canonical);

describe('parseAdvisoryFromText', () => {
  it('parses plain JSON body', () => {
    const r = parseAdvisoryFromText(canonStr);
    expect(r).not.toBeNull();
    expect(r!.summary.length).toBeGreaterThan(0);
  });

  it('strips ```json … ``` fences', () => {
    const fenced = '```json\n' + canonStr + '\n```';
    expect(parseAdvisoryFromText(fenced)).not.toBeNull();
  });

  it('strips ```json with language tag and a leading paragraph', () => {
    const wrapped = 'Here is the advisory you requested:\n\n```json\n' + canonStr + '\n```\n\nLet me know if you need a deeper look.';
    expect(parseAdvisoryFromText(wrapped)).not.toBeNull();
  });

  it('returns null on garbage', () => {
    expect(parseAdvisoryFromText('completely not JSON, sorry')).toBeNull();
  });

  it('returns null on JSON that does not match schema', () => {
    expect(parseAdvisoryFromText(JSON.stringify({ summary: 'x' }))).toBeNull();
  });

  it('handles a leading BOM', () => {
    expect(parseAdvisoryFromText('﻿' + canonStr)).not.toBeNull();
  });
});
```

- [ ] **Step 2: Run test, expect FAIL**

- [ ] **Step 3: Implement `apps/worker/src/ai/output-parser.ts`**

```ts
// apps/worker/src/ai/output-parser.ts
import { AdvisorySchema, type Advisory } from './output-schema.js';

const FENCE = /```(?:json)?\s*([\s\S]*?)```/m;

/**
 * Best-effort extraction of an Advisory JSON object from Claude's raw text
 * response. Returns null when no valid JSON of the required shape can be
 * extracted. Caller chooses the fallback strategy (deterministic / last-good).
 */
export function parseAdvisoryFromText(text: string): Advisory | null {
  if (!text) return null;
  // Strip BOM and surrounding whitespace
  const trimmed = text.replace(/^﻿/, '').trim();

  // 1) Try fenced block first (most common Claude output for JSON mode)
  const fenced = trimmed.match(FENCE);
  const candidate = fenced ? fenced[1].trim() : trimmed;

  // 2) Find the first '{' and matching '}' span; if the candidate is just
  //    JSON this is a no-op, otherwise it salvages the JSON from a prefix-then-prose response.
  const start = candidate.indexOf('{');
  if (start === -1) return null;
  let depth = 0;
  let end = -1;
  for (let i = start; i < candidate.length; i++) {
    const ch = candidate[i];
    if (ch === '{') depth++;
    else if (ch === '}') {
      depth--;
      if (depth === 0) { end = i; break; }
    }
  }
  if (end === -1) return null;

  const slice = candidate.slice(start, end + 1);
  let parsed: unknown;
  try {
    parsed = JSON.parse(slice);
  } catch {
    return null;
  }
  const v = AdvisorySchema.safeParse(parsed);
  return v.success ? v.data : null;
}
```

- [ ] **Step 4: Run test, expect PASS**

- [ ] **Step 5: Commit**

```bash
git add apps/worker/src/ai/output-parser.ts apps/worker/tests/ai/output-parser.test.ts
git commit -m "feat(ai): defensive parser that extracts advisory JSON from Claude text"
```

---

## Task 5: Build-time rulebook codegen

**Files:**
- Create: `apps/worker/src/ai/prompts/rulebook-codegen.ts`
- Modify: `apps/worker/package.json` (add `ai:codegen` script)
- Modify: `apps/worker/.gitignore` (ignore generated file)
- Output (gitignored): `apps/worker/src/ai/prompts/rulebook-2026.generated.ts`

**Goal:** at build time we concatenate the three rulebook Markdown files into a single TypeScript module that exports a frozen string. The Worker imports it; no runtime fetch.

- [ ] **Step 1: Write the codegen script** — implementer creates first, no test (it's a pure build artifact). The test in Task 6 (system block) catches drift.

```ts
// apps/worker/src/ai/prompts/rulebook-codegen.ts
import { readFileSync, writeFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const BID_DOCS = process.env.BID_DOCS_DIR ?? 'D:/MBFD/Bid/2026 Bid Documents';

const sources = [
  { title: 'Bid Process', path: '2026_Bid_Process.md' },
  { title: 'Rules & Points', path: '2026_Rules_and_Points.md' },
  { title: 'Position Template', path: '2026_Position_Template.md' },
];

const body = sources
  .map((s) => {
    const raw = readFileSync(resolve(BID_DOCS, s.path), 'utf8');
    return `<!-- BEGIN ${s.title} (${s.path}) -->\n${raw}\n<!-- END ${s.title} -->`;
  })
  .join('\n\n---\n\n');

const out = `// AUTO-GENERATED. Do not edit by hand. Run \`pnpm --filter @mbfd/worker ai:codegen\`.\nexport const RULEBOOK_2026: string = ${JSON.stringify(body)};\nexport const RULEBOOK_2026_VERSION = '2026.1';\n`;

writeFileSync(resolve(here, 'rulebook-2026.generated.ts'), out, 'utf8');
console.log(\`Wrote rulebook-2026.generated.ts (\${body.length} chars)\`);
```

- [ ] **Step 2: Wire `ai:codegen` script in `apps/worker/package.json`**

```json
{
  "scripts": {
    "ai:codegen": "tsx src/ai/prompts/rulebook-codegen.ts",
    "build": "pnpm run ai:codegen && tsc"
  }
}
```

- [ ] **Step 3: Append to `apps/worker/.gitignore`**

```
src/ai/prompts/rulebook-2026.generated.ts
```

- [ ] **Step 4: Run it locally**

```
pnpm --filter @mbfd/worker ai:codegen
```

Expected: file created with > 14000 chars; tsc accepts the import.

- [ ] **Step 5: Wire codegen into CI** — append to the existing GitHub Actions workflow that runs `pnpm test`:

```yaml
- name: AI rulebook codegen
  env:
    BID_DOCS_DIR: ${{ github.workspace }}/docs/bid-docs/2026
  run: pnpm --filter @mbfd/worker ai:codegen
```

(The `docs/bid-docs/2026/` directory in `mbfd-bid` repo is a vendored copy of `D:/MBFD/Bid/2026 Bid Documents/`; commit those three .md files into the repo under that path so CI can read them. They contain no secrets.)

- [ ] **Step 6: Vendor the rulebook .md files into `mbfd-bid` repo**

```
mkdir -p apps/worker/docs/bid-docs/2026
cp "D:/MBFD/Bid/2026 Bid Documents/2026_Bid_Process.md" apps/worker/docs/bid-docs/2026/
cp "D:/MBFD/Bid/2026 Bid Documents/2026_Rules_and_Points.md" apps/worker/docs/bid-docs/2026/
cp "D:/MBFD/Bid/2026 Bid Documents/2026_Position_Template.md" apps/worker/docs/bid-docs/2026/
```

Update the codegen script's default `BID_DOCS` to `'./docs/bid-docs/2026'` so local and CI both work without env vars.

- [ ] **Step 7: Commit**

```bash
git add apps/worker/src/ai/prompts/rulebook-codegen.ts apps/worker/package.json apps/worker/.gitignore apps/worker/docs/bid-docs/2026/
git commit -m "feat(ai): codegen the rulebook prompt string from vendored .md files"
```

---

## Task 6: System prompt block

**Files:**
- Create: `apps/worker/src/ai/prompts/system-2026.ts`
- Test: `apps/worker/tests/ai/prompts.test.ts`

**Goal:** export a function that returns the typed Anthropic `system` parameter — a single text block carrying the entire rulebook, marked with `cache_control: { type: 'ephemeral' }` so Anthropic caches everything BEFORE the user-roster block.

- [ ] **Step 1: Write failing test** (subset — full prompts.test.ts also tests Task 7 and Task 8 blocks)

```ts
// apps/worker/tests/ai/prompts.test.ts (system-block subset)
import { describe, it, expect } from 'vitest';
import { systemBlock, SYSTEM_PROMPT_VERSION } from '../../src/ai/prompts/system-2026.js';

describe('systemBlock', () => {
  it('returns an array of one text block with cache_control', () => {
    const b = systemBlock();
    expect(Array.isArray(b)).toBe(true);
    expect(b).toHaveLength(1);
    expect(b[0]).toMatchObject({ type: 'text', cache_control: { type: 'ephemeral' } });
  });

  it('text includes all three rulebook section markers', () => {
    const b = systemBlock();
    expect(b[0].text).toContain('BEGIN Bid Process');
    expect(b[0].text).toContain('BEGIN Rules & Points');
    expect(b[0].text).toContain('BEGIN Position Template');
  });

  it('text states the deterministic-engine constraint verbatim', () => {
    expect(systemBlock()[0].text).toContain('do not recompute eligibility');
  });

  it('text declares the required JSON output shape', () => {
    expect(systemBlock()[0].text).toContain('"eligible_recommendations"');
    expect(systemBlock()[0].text).toContain('"force_recommended"');
  });

  it('SYSTEM_PROMPT_VERSION is a date-like string', () => {
    expect(SYSTEM_PROMPT_VERSION).toMatch(/^\d{4}-\d{2}-\d{2}/);
  });
});
```

- [ ] **Step 2: Run test, expect FAIL**

- [ ] **Step 3: Implement `apps/worker/src/ai/prompts/system-2026.ts`**

```ts
// apps/worker/src/ai/prompts/system-2026.ts
import { RULEBOOK_2026, RULEBOOK_2026_VERSION } from './rulebook-2026.generated.js';

export const SYSTEM_PROMPT_VERSION = '2026-05-17';

const PREAMBLE = `You are the MBFD bid event AI advisor. Two strict rules:

1. You DO NOT decide eligibility. The deterministic engine has already computed
   it. You will receive an EligibilityResult struct in the user message; you
   must not recompute eligibility, must not contradict it, and must not invent
   eligibility for positions you are not shown.

2. You return ONLY a single JSON object, no prose around it, matching this
   exact schema:

{
  "summary": string,                          // 1-2 sentences for the admin panel
  "eligible_recommendations": [               // sorted best-first; max 5 items
    { "position_id": string, "points": int, "why": string }
  ],
  "ineligible_top_picks": [                   // positions the bidder likely
                                              // wants but cannot have, with the
                                              // reason from the eligibility struct
    { "position_id": string, "why_ineligible": string }
  ],
  "forecast": {                               // departmental trends
    "warnings": [
      { "level": "info"|"warn"|"critical",
        "text": string,
        "affected_positions": [string] }
    ]
  },
  "force_recommended": boolean,               // TRUE only when the bidder is
                                              // the last credentialed candidate
                                              // for a credentialed seat
  "force_reasoning": string                   // required iff force_recommended
}

Your role is EXPLANATION and FORECAST. The engine decides; you help the chiefs
understand the decision in the context of the bid event in progress.

Reference materials follow.

`;

const TEXT = PREAMBLE + RULEBOOK_2026;

/** Typed Anthropic system param: text block with cache_control breakpoint. */
export function systemBlock(): Array<{
  type: 'text';
  text: string;
  cache_control: { type: 'ephemeral' };
}> {
  return [
    {
      type: 'text',
      text: TEXT,
      cache_control: { type: 'ephemeral' },
    },
  ];
}

export const SYSTEM_PROMPT_RULEBOOK_VERSION = RULEBOOK_2026_VERSION;
```

- [ ] **Step 4: Run test, expect PASS**

- [ ] **Step 5: Commit**

```bash
git add apps/worker/src/ai/prompts/system-2026.ts apps/worker/tests/ai/prompts.test.ts
git commit -m "feat(ai): system prompt block with rulebook + cache breakpoint"
```

---

## Task 7: User-roster block

**Files:**
- Create: `apps/worker/src/ai/prompts/user-roster.ts`
- Modify: `apps/worker/tests/ai/prompts.test.ts` (extend)

**Goal:** the second cache layer — a deterministic JSON dump of the session's full roster + eligibility matrix. Generated once per session start (or whenever the admin re-runs imports) and cached.

- [ ] **Step 1: Extend test**

```ts
// apps/worker/tests/ai/prompts.test.ts (rosterBlock subset)
import { rosterBlock, type RosterInput } from '../../src/ai/prompts/user-roster.js';

describe('rosterBlock', () => {
  const input: RosterInput = {
    bidSessionId: '01HF3SESSION',
    members: [
      {
        employeeId: '14335', firstName: 'Jesus', lastName: 'Sola',
        rank: 'DC', bidCategory: 'OFC', rscSeniority: 4, rankSeniority: 1,
        isProbationary: false,
        credentials: ['Hazardous Materials Operations', 'Rope Rescue Operations'],
        priorYearBid: 'A101',
      },
    ],
    eligibilityMatrix: [
      { memberEmployeeId: '14335', positionId: 'A101', eligible: true, points: 1, soPoints: 0, moPoints: 0, reasons: ['RANK_OK'] },
    ],
  };

  it('returns array with one user-role text block carrying cache_control', () => {
    const b = rosterBlock(input);
    expect(b).toHaveLength(1);
    expect(b[0].type).toBe('text');
    expect(b[0].cache_control).toEqual({ type: 'ephemeral' });
  });

  it('text includes the session id', () => {
    expect(rosterBlock(input)[0].text).toContain('01HF3SESSION');
  });

  it('roster includes member id + rank + creds', () => {
    const t = rosterBlock(input)[0].text;
    expect(t).toContain('14335');
    expect(t).toContain('Hazardous Materials Operations');
  });

  it('eligibility matrix row is rendered as compact JSON line per row', () => {
    const t = rosterBlock(input)[0].text;
    expect(t).toMatch(/"position_id":\s*"A101"/);
  });

  it('output is deterministic — same input twice yields byte-identical text', () => {
    const a = rosterBlock(input)[0].text;
    const b = rosterBlock(input)[0].text;
    expect(a).toBe(b);
  });
});
```

- [ ] **Step 2: Run test, expect FAIL**

- [ ] **Step 3: Implement `apps/worker/src/ai/prompts/user-roster.ts`**

```ts
// apps/worker/src/ai/prompts/user-roster.ts
import type { Rank } from '@mbfd/eligibility';

export interface RosterMember {
  employeeId: string;
  firstName: string;
  lastName: string;
  rank: Rank;
  bidCategory: 'OFC' | 'FF' | 'EXCLUDED';
  rscSeniority: number;
  rankSeniority: number | null;
  isProbationary: boolean;
  credentials: string[];
  priorYearBid: string | null;
}

export interface EligibilityMatrixRow {
  memberEmployeeId: string;
  positionId: string;
  eligible: boolean;
  points: number;
  soPoints: number;
  moPoints: number;
  reasons: string[];
}

export interface RosterInput {
  bidSessionId: string;
  members: RosterMember[];
  eligibilityMatrix: EligibilityMatrixRow[];
}

function stable(o: unknown): string {
  // JSON.stringify with sorted keys to maximise cache-hit rate. Anthropic's
  // cache is keyed on the exact byte sequence; small reorderings invalidate it.
  const seen = new WeakSet();
  return JSON.stringify(o, function (_k, v) {
    if (v && typeof v === 'object' && !Array.isArray(v)) {
      if (seen.has(v)) return; seen.add(v);
      return Object.fromEntries(Object.keys(v).sort().map((k) => [k, v[k as keyof typeof v]]));
    }
    return v;
  });
}

function renderMember(m: RosterMember): string {
  return stable({
    employee_id: m.employeeId,
    name: `${m.lastName}, ${m.firstName}`,
    rank: m.rank,
    bid_category: m.bidCategory,
    rsc_seniority: m.rscSeniority,
    rank_seniority: m.rankSeniority,
    is_probationary: m.isProbationary,
    credentials: [...m.credentials].sort(),
    prior_year_bid: m.priorYearBid,
  });
}

function renderRow(r: EligibilityMatrixRow): string {
  return stable({
    employee_id: r.memberEmployeeId,
    position_id: r.positionId,
    eligible: r.eligible,
    points: r.points,
    so_points: r.soPoints,
    mo_points: r.moPoints,
    reasons: r.reasons,
  });
}

export function rosterBlock(input: RosterInput): Array<{
  type: 'text';
  text: string;
  cache_control: { type: 'ephemeral' };
}> {
  const sortedMembers = [...input.members].sort((a, b) => a.employeeId.localeCompare(b.employeeId));
  const sortedMatrix = [...input.eligibilityMatrix].sort(
    (a, b) =>
      a.memberEmployeeId.localeCompare(b.memberEmployeeId) ||
      a.positionId.localeCompare(b.positionId),
  );

  const text =
    `# Session ${input.bidSessionId} — roster + eligibility matrix\n\n` +
    `## Members (${sortedMembers.length})\n` +
    sortedMembers.map(renderMember).join('\n') +
    `\n\n## Eligibility matrix rows (${sortedMatrix.length})\n` +
    sortedMatrix.map(renderRow).join('\n');

  return [{ type: 'text', text, cache_control: { type: 'ephemeral' } }];
}
```

- [ ] **Step 4: Run test, expect PASS**

- [ ] **Step 5: Commit**

```bash
git add apps/worker/src/ai/prompts/user-roster.ts apps/worker/tests/ai/prompts.test.ts
git commit -m "feat(ai): user-roster prompt block (deterministic stable JSON + cache breakpoint)"
```

---

## Task 8: User-turn block (uncached delta)

**Files:**
- Create: `apps/worker/src/ai/prompts/user-turn.ts`
- Modify: `apps/worker/tests/ai/prompts.test.ts`

**Goal:** the per-call body. Contains the live bid state + the question. Must NOT carry `cache_control`.

- [ ] **Step 1: Extend test**

```ts
// apps/worker/tests/ai/prompts.test.ts (turnBlock subset)
import { turnBlock, type TurnInput } from '../../src/ai/prompts/user-turn.js';

const t: TurnInput = {
  phase: 'position_bid',
  currentBidderEmployeeId: '14335',
  queue: ['14335','12345','99999'],
  positionFills: { A101: '11111' },
  remainingPositionIds: ['A102','A103','A104'],
  question: 'Advise on the upcoming pick.',
};

describe('turnBlock', () => {
  it('returns single text block WITHOUT cache_control', () => {
    const b = turnBlock(t);
    expect(b).toHaveLength(1);
    expect(b[0].type).toBe('text');
    expect((b[0] as any).cache_control).toBeUndefined();
  });

  it('embeds the question verbatim', () => {
    expect(turnBlock(t)[0].text).toContain('Advise on the upcoming pick.');
  });

  it('embeds current bidder + queue + fills', () => {
    const x = turnBlock(t)[0].text;
    expect(x).toContain('"current_bidder":"14335"');
    expect(x).toContain('"queue":["14335"');
  });
});
```

- [ ] **Step 2: Run test, expect FAIL**

- [ ] **Step 3: Implement `apps/worker/src/ai/prompts/user-turn.ts`**

```ts
// apps/worker/src/ai/prompts/user-turn.ts

export interface TurnInput {
  phase: 'config' | 'position_bid' | 'a_day_phase' | 'paused' | 'completed';
  currentBidderEmployeeId: string | null;
  queue: string[];
  positionFills: Record<string, string>;
  remainingPositionIds: string[];
  question: string;
}

export function turnBlock(t: TurnInput): Array<{ type: 'text'; text: string }> {
  const state = JSON.stringify({
    phase: t.phase,
    current_bidder: t.currentBidderEmployeeId,
    queue: t.queue,
    position_fills: t.positionFills,
    remaining_positions: t.remainingPositionIds,
  });
  return [
    {
      type: 'text',
      text:
        `# Current bid state\n${state}\n\n` +
        `# Question\n${t.question}\n\n` +
        `Reply with ONLY the JSON object specified in the system prompt — no prose, no fences.`,
    },
  ];
}
```

- [ ] **Step 4: Run test, expect PASS**

- [ ] **Step 5: Commit**

```bash
git add apps/worker/src/ai/prompts/user-turn.ts apps/worker/tests/ai/prompts.test.ts
git commit -m "feat(ai): user-turn prompt block (uncached delta)"
```

---

## Task 9: AI client wrapper

**Files:**
- Create: `apps/worker/src/ai/client.ts`
- Test: `apps/worker/tests/ai/client.test.ts`
- Modify: `apps/worker/package.json` (add `@anthropic-ai/sdk@^0.96.0`)

**Goal:** a single class `AnthropicAIClient` that:
1. Routes via `CF_AI_GATEWAY_URL` (no direct anthropic.com).
2. Builds messages from the three block helpers.
3. Calls `messages.create` (non-streaming) for advise-current / forecast.
4. Calls `messages.stream` for advise-deep.
5. Records `cost_cents` to KV + the returned advisory id.
6. Falls back to the last-good KV entry on 5xx / timeout.
7. Hashes the prompt for the `prompt_hash` audit column.

- [ ] **Step 1: Install the SDK**

```
pnpm --filter @mbfd/worker add @anthropic-ai/sdk@^0.96.0 eventsource-parser@^3.0.0
```

- [ ] **Step 2: Write failing test**

```ts
// apps/worker/tests/ai/client.test.ts
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { AnthropicAIClient } from '../../src/ai/client.js';
import canonical from './__fixtures__/advisory-canonical.json';

function makeEnv(overrides: Partial<any> = {}): any {
  return {
    ENV: 'staging',
    CF_AI_GATEWAY_URL: 'https://gateway.example.com/v1/abc/mbfd-bid/anthropic',
    ANTHROPIC_API_KEY: 'sk-test',
    AI_BUDGET_CAP_CENTS: 2500,
    AI_FEATURE_FLAG_KEY: 'ai_advisory_enabled',
    AI_KV: {
      store: new Map<string, string>(),
      get: vi.fn(async function (this: any, k: string) { return this.store.get(k) ?? null; }),
      put: vi.fn(async function (this: any, k: string, v: string) { this.store.set(k, v); }),
      delete: vi.fn(),
      list: vi.fn(),
      getWithMetadata: vi.fn(),
    },
    ...overrides,
  };
}

describe('AnthropicAIClient', () => {
  let fetchMock: any;
  beforeEach(() => {
    fetchMock = vi.fn();
    globalThis.fetch = fetchMock;
  });

  it('posts to the CF AI Gateway URL (not api.anthropic.com)', async () => {
    fetchMock.mockResolvedValue(new Response(JSON.stringify({
      id: 'msg_test', type: 'message', role: 'assistant',
      content: [{ type: 'text', text: JSON.stringify(canonical) }],
      stop_reason: 'end_turn', model: 'claude-sonnet-4-6',
      usage: { input_tokens: 100, output_tokens: 50, cache_creation_input_tokens: 0, cache_read_input_tokens: 0 },
    }), { status: 200, headers: { 'Content-Type': 'application/json' } }));
    const env = makeEnv();
    const client = new AnthropicAIClient(env);
    const { advisory } = await client.adviseCurrent({
      bidSessionId: 'sess1', system: [{ type: 'text', text: 'S', cache_control: { type: 'ephemeral' } }],
      roster: [{ type: 'text', text: 'R', cache_control: { type: 'ephemeral' } }],
      turn:   [{ type: 'text', text: 'T' }],
    });
    expect(advisory).not.toBeNull();
    const url = fetchMock.mock.calls[0][0] as string;
    expect(url).toContain('gateway.example.com');
    expect(url).not.toContain('api.anthropic.com');
  });

  it('records cost_cents to KV after a successful call', async () => {
    fetchMock.mockResolvedValue(new Response(JSON.stringify({
      id: 'msg_test', type: 'message', role: 'assistant',
      content: [{ type: 'text', text: JSON.stringify(canonical) }],
      stop_reason: 'end_turn', model: 'claude-sonnet-4-6',
      usage: { input_tokens: 1000, output_tokens: 500, cache_creation_input_tokens: 30000, cache_read_input_tokens: 0 },
    }), { status: 200 }));
    const env = makeEnv();
    const client = new AnthropicAIClient(env);
    await client.adviseCurrent({ bidSessionId: 'sess1', system: [], roster: [], turn: [] });
    expect(env.AI_KV.store.get('ai_cost_cents:sess1')).toBeDefined();
    expect(Number(env.AI_KV.store.get('ai_cost_cents:sess1'))).toBeGreaterThan(0);
  });

  it('returns stale-last-good envelope on 5xx', async () => {
    const env = makeEnv();
    // pre-seed last good
    env.AI_KV.store.set('ai_last_good:sess1', JSON.stringify({ advisory: canonical, generated_at_ms: 1000, ai_advisory_id: 'prev123' }));
    fetchMock.mockResolvedValue(new Response('{"error":"upstream"}', { status: 503 }));
    const client = new AnthropicAIClient(env);
    const env2 = await client.adviseCurrent({ bidSessionId: 'sess1', system: [], roster: [], turn: [] });
    expect(env2.stale).toBe(true);
    expect(env2.fallback).toBe('last_good');
  });

  it('returns deterministic-fallback envelope when no last-good exists', async () => {
    fetchMock.mockResolvedValue(new Response('{"error":"upstream"}', { status: 503 }));
    const env = makeEnv();
    const client = new AnthropicAIClient(env);
    const r = await client.adviseCurrent({ bidSessionId: 'sessX', system: [], roster: [], turn: [], deterministicFallback: { advisory: canonical } });
    expect(r.stale).toBe(true);
    expect(r.fallback).toBe('deterministic');
  });

  it('refuses to call when budget cap exceeded', async () => {
    const env = makeEnv();
    env.AI_KV.store.set('ai_cost_cents:sessOver', '3000'); // > 2500 cap
    const client = new AnthropicAIClient(env);
    const r = await client.adviseCurrent({ bidSessionId: 'sessOver', system: [], roster: [], turn: [] });
    expect(r.stale).toBe(true);
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it('refuses to call when feature flag disabled', async () => {
    const env = makeEnv();
    env.AI_KV.store.set('ai_advisory_enabled', 'false');
    const client = new AnthropicAIClient(env);
    const r = await client.adviseCurrent({ bidSessionId: 'sessFlag', system: [], roster: [], turn: [] });
    expect(r.stale).toBe(true);
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it('prompt_hash is deterministic for same inputs', async () => {
    const env = makeEnv();
    const client = new AnthropicAIClient(env);
    const h1 = await client.hashPrompt('a','b','c');
    const h2 = await client.hashPrompt('a','b','c');
    const h3 = await client.hashPrompt('a','b','d');
    expect(h1).toBe(h2);
    expect(h1).not.toBe(h3);
  });
});
```

- [ ] **Step 3: Run test, expect FAIL**

- [ ] **Step 4: Implement `apps/worker/src/ai/client.ts`**

```ts
// apps/worker/src/ai/client.ts
import Anthropic from '@anthropic-ai/sdk';
import { ulid } from 'ulid';
import type { WorkerEnv } from '../types/env.js';
import {
  addSessionCostCents,
  getSessionCostCents,
  COST_KEY_PREFIX,
} from './cost-accounting.js';
import { computeCostCents } from './pricing.js';
import { parseAdvisoryFromText } from './output-parser.js';
import type { Advisory, AdvisoryEnvelope } from './output-schema.js';

export interface SonnetCallInput {
  bidSessionId: string;
  /** Cached system block — array shape with cache_control. */
  system: Anthropic.MessageCreateParams['system'];
  /** Cached user roster block — first user message. */
  roster: Array<{ type: 'text'; text: string; cache_control: { type: 'ephemeral' } }>;
  /** Uncached turn block — second user message. */
  turn: Array<{ type: 'text'; text: string }>;
  /** Optional precomputed deterministic Advisory used as the absolute-last fallback. */
  deterministicFallback?: { advisory: Advisory };
  /** Per-call timeout. Default: 2500ms (Sonnet hot path). */
  timeoutMs?: number;
  /** Override model (test/eval). */
  model?: string;
}

const SONNET = 'claude-sonnet-4-6';
const OPUS = 'claude-opus-4-7';
const LAST_GOOD_PREFIX = 'ai_last_good:';

export class AnthropicAIClient {
  private readonly client: Anthropic;

  constructor(private readonly env: WorkerEnv) {
    this.client = new Anthropic({
      apiKey: env.ANTHROPIC_API_KEY,
      baseURL: env.CF_AI_GATEWAY_URL,
      // Anthropic SDK uses fetch by default; CF Workers polyfill works.
    });
  }

  /** SHA-256 hex of the concatenated system/roster/turn texts (audit trail). */
  async hashPrompt(...parts: string[]): Promise<string> {
    const buf = new TextEncoder().encode(parts.join('\x1f'));
    const digest = await crypto.subtle.digest('SHA-256', buf);
    return [...new Uint8Array(digest)].map((b) => b.toString(16).padStart(2, '0')).join('');
  }

  private async featureEnabled(): Promise<boolean> {
    const v = await this.env.AI_KV.get(this.env.AI_FEATURE_FLAG_KEY);
    return v !== 'false';
  }

  private async budgetRemaining(bidSessionId: string): Promise<number> {
    const used = await getSessionCostCents(this.env.AI_KV, bidSessionId);
    return this.env.AI_BUDGET_CAP_CENTS - used;
  }

  private async lastGood(bidSessionId: string): Promise<AdvisoryEnvelope | null> {
    const raw = await this.env.AI_KV.get(LAST_GOOD_PREFIX + bidSessionId);
    if (!raw) return null;
    try {
      const obj = JSON.parse(raw);
      return {
        advisory: obj.advisory,
        stale: true,
        fallback: 'last_good',
        generated_at_ms: obj.generated_at_ms ?? 0,
        ai_advisory_id: obj.ai_advisory_id ?? null,
      };
    } catch { return null; }
  }

  private async writeLastGood(bidSessionId: string, env: AdvisoryEnvelope): Promise<void> {
    await this.env.AI_KV.put(
      LAST_GOOD_PREFIX + bidSessionId,
      JSON.stringify({ advisory: env.advisory, generated_at_ms: env.generated_at_ms, ai_advisory_id: env.ai_advisory_id }),
      { expirationTtl: 60 * 60 * 24 * 7 },
    );
  }

  async adviseCurrent(input: SonnetCallInput): Promise<AdvisoryEnvelope> {
    return this.callNonStreaming({ ...input, model: input.model ?? SONNET, timeoutMs: input.timeoutMs ?? 2500 });
  }

  /** Async pre-fetch helper used by cache-warm. Same as adviseCurrent but the
   * caller awaits nothing — it writes last-good silently. */
  async preFetch(input: SonnetCallInput): Promise<void> {
    await this.callNonStreaming({ ...input, model: input.model ?? SONNET, timeoutMs: input.timeoutMs ?? 4000 });
  }

  /** Opus streaming call returning the underlying SDK stream so the route can
   * tee it as SSE. The caller is responsible for closing the stream. */
  async adviseDeepStream(input: SonnetCallInput) {
    if (!(await this.featureEnabled())) {
      throw new AIError('disabled', 'feature_flag_off');
    }
    if ((await this.budgetRemaining(input.bidSessionId)) <= 0) {
      throw new AIError('disabled', 'budget_exceeded');
    }
    return this.client.messages.stream({
      model: input.model ?? OPUS,
      max_tokens: 2048,
      system: input.system,
      messages: [
        { role: 'user', content: [...input.roster, ...input.turn] as any },
      ],
    } as Anthropic.MessageStreamParams);
  }

  // ---- private ----

  private async callNonStreaming(input: SonnetCallInput & { model: string; timeoutMs: number }): Promise<AdvisoryEnvelope> {
    if (!(await this.featureEnabled())) {
      return this.fallback(input, 'last_good');
    }
    if ((await this.budgetRemaining(input.bidSessionId)) <= 0) {
      return this.fallback(input, 'last_good');
    }

    const startMs = Date.now();
    const ac = new AbortController();
    const timer = setTimeout(() => ac.abort(), input.timeoutMs);

    let res: Anthropic.Messages.Message;
    try {
      res = await this.client.messages.create({
        model: input.model,
        max_tokens: 1500,
        system: input.system,
        messages: [
          { role: 'user', content: [...input.roster, ...input.turn] as any },
        ],
      }, { signal: ac.signal });
    } catch (err) {
      return this.fallback(input, 'last_good');
    } finally {
      clearTimeout(timer);
    }

    // Anthropic returns content array; advisory is in the first text block.
    const text = res.content
      .filter((c): c is Anthropic.TextBlock => c.type === 'text')
      .map((c) => c.text)
      .join('\n');

    const advisory = parseAdvisoryFromText(text);
    if (!advisory) return this.fallback(input, 'last_good');

    // Cost + KV.
    const usage = res.usage;
    const cost = computeCostCents(res.model, {
      input: usage.input_tokens,
      output: usage.output_tokens,
      cacheRead: usage.cache_read_input_tokens ?? 0,
      cacheWrite: usage.cache_creation_input_tokens ?? 0,
    });
    await addSessionCostCents(this.env.AI_KV, input.bidSessionId, cost);

    const envelope: AdvisoryEnvelope = {
      advisory,
      stale: false,
      fallback: 'none',
      generated_at_ms: Date.now(),
      ai_advisory_id: ulid(),
    };
    await this.writeLastGood(input.bidSessionId, envelope);

    return envelope;
  }

  private async fallback(input: SonnetCallInput, _why: 'last_good'): Promise<AdvisoryEnvelope> {
    const lg = await this.lastGood(input.bidSessionId);
    if (lg) return lg;
    if (input.deterministicFallback) {
      return {
        advisory: input.deterministicFallback.advisory,
        stale: true,
        fallback: 'deterministic',
        generated_at_ms: Date.now(),
        ai_advisory_id: null,
      };
    }
    // Absolute degenerate fallback — empty advisory but valid shape.
    return {
      advisory: {
        summary: 'AI advisor unavailable. Falling back to deterministic eligibility only.',
        eligible_recommendations: [],
        ineligible_top_picks: [],
        forecast: { warnings: [] },
        force_recommended: false,
      },
      stale: true,
      fallback: 'deterministic',
      generated_at_ms: Date.now(),
      ai_advisory_id: null,
    };
  }
}

export class AIError extends Error {
  constructor(public kind: 'disabled' | 'invalid' | 'upstream', message: string) {
    super(message);
  }
}

export { COST_KEY_PREFIX };
```

- [ ] **Step 5: Run tests, expect PASS**

- [ ] **Step 6: Commit**

```bash
git add apps/worker/src/ai/client.ts apps/worker/tests/ai/client.test.ts apps/worker/package.json pnpm-lock.yaml
git commit -m "feat(ai): AnthropicAIClient with gateway URL + cache + KV cost + last-good fallback"
```

---

## Task 10: `/api/admin/ai/advise-current` endpoint (non-streaming Sonnet)

**Files:**
- Create: `apps/worker/src/routes/ai.ts`
- Modify: `apps/worker/src/index.ts` (mount)
- Test: `apps/worker/tests/routes/ai-advise-current.test.ts`

**Goal:** admin-only GET endpoint. Reads the current bid state from the DO/D1, builds prompt blocks, calls Sonnet, persists `ai_advisories` row, returns envelope.

- [ ] **Step 1: Write failing test**

```ts
// apps/worker/tests/routes/ai-advise-current.test.ts
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { Hono } from 'hono';
import adminAi from '../../src/routes/ai.js';
import { signJwt } from '../../src/lib/jwt.js';
import canonical from '../ai/__fixtures__/advisory-canonical.json';

let env: any;
let app: any;

beforeEach(() => {
  // Fake DO / DB / KV / fetch
  env = {
    ENV: 'staging',
    CF_AI_GATEWAY_URL: 'https://gateway.example.com/v1/abc/mbfd-bid/anthropic',
    ANTHROPIC_API_KEY: 'sk',
    JWT_SIGNING_KEY: 'a'.repeat(32),
    AI_BUDGET_CAP_CENTS: 2500,
    AI_FEATURE_FLAG_KEY: 'ai_advisory_enabled',
    AI_KV: makeKv(),
    KV: makeKv(),
    DB: makeDb(),
  };
  globalThis.fetch = vi.fn().mockResolvedValue(new Response(JSON.stringify({
    id: 'm', type: 'message', role: 'assistant',
    content: [{ type: 'text', text: JSON.stringify(canonical) }],
    stop_reason: 'end_turn', model: 'claude-sonnet-4-6',
    usage: { input_tokens: 100, output_tokens: 50, cache_creation_input_tokens: 0, cache_read_input_tokens: 0 },
  }), { status: 200 }));
  app = new Hono<{ Bindings: any }>().route('/api/admin/ai', adminAi);
});

function makeKv() {
  const store = new Map<string, string>();
  return {
    store, get: vi.fn(async (k: string) => store.get(k) ?? null),
    put: vi.fn(async (k: string, v: string) => void store.set(k, v)),
    delete: vi.fn(), list: vi.fn(), getWithMetadata: vi.fn(),
  };
}
function makeDb(): any {
  return {
    insert: () => ({ values: () => ({ returning: () => ({ get: async () => ({ id: 'fake' }) }) }) }),
    select: () => ({ from: () => ({ where: () => ({ get: async () => null, all: async () => [] }) }) }),
    // Live session lookup stub — returns a minimal session row
  };
}

it('returns 401 without auth', async () => {
  const res = await app.request('/api/admin/ai/advise-current?session_id=sess1', {}, env);
  expect(res.status).toBe(401);
});

it('returns 403 for member role', async () => {
  const jwt = await signJwt({ memberId: 1, role: 'member', employeeId: '1' }, env.JWT_SIGNING_KEY);
  const res = await app.request(
    '/api/admin/ai/advise-current?session_id=sess1',
    { headers: { Authorization: `Bearer ${jwt}` } },
    env,
  );
  expect(res.status).toBe(403);
});

it('returns 503 when feature flag off', async () => {
  env.AI_KV.store.set('ai_advisory_enabled', 'false');
  const jwt = await signJwt({ memberId: 1, role: 'admin', employeeId: '1' }, env.JWT_SIGNING_KEY);
  const res = await app.request('/api/admin/ai/advise-current?session_id=sess1', { headers: { Authorization: `Bearer ${jwt}` } }, env);
  expect(res.status).toBe(503);
  expect((await res.json()).disabled).toBe(true);
});

it('happy path returns AdvisoryEnvelope JSON', async () => {
  const jwt = await signJwt({ memberId: 1, role: 'admin', employeeId: '1' }, env.JWT_SIGNING_KEY);
  const res = await app.request('/api/admin/ai/advise-current?session_id=sess1', { headers: { Authorization: `Bearer ${jwt}` } }, env);
  expect(res.status).toBe(200);
  const body = await res.json();
  expect(body.advisory.summary).toBeDefined();
  expect(body.stale).toBe(false);
});

it('completes in <2500ms (synthetic — fetch mocked at 0ms)', async () => {
  const jwt = await signJwt({ memberId: 1, role: 'admin', employeeId: '1' }, env.JWT_SIGNING_KEY);
  const t0 = performance.now();
  await app.request('/api/admin/ai/advise-current?session_id=sess1', { headers: { Authorization: `Bearer ${jwt}` } }, env);
  expect(performance.now() - t0).toBeLessThan(2500);
});

it('emits ai_advisories audit row', async () => {
  const insertSpy = vi.fn().mockReturnValue({ values: vi.fn().mockReturnValue({ run: vi.fn() }) });
  env.DB.insert = insertSpy;
  const jwt = await signJwt({ memberId: 1, role: 'admin', employeeId: '1' }, env.JWT_SIGNING_KEY);
  await app.request('/api/admin/ai/advise-current?session_id=sess1', { headers: { Authorization: `Bearer ${jwt}` } }, env);
  // ai_advisories insert is one of the calls
  expect(insertSpy).toHaveBeenCalled();
});
```

- [ ] **Step 2: Run test, expect FAIL**

- [ ] **Step 3: Implement `apps/worker/src/routes/ai.ts`** — start with advise-current; deep-dive added in Task 11, forecast in Task 13.

```ts
// apps/worker/src/routes/ai.ts
import { Hono } from 'hono';
import { ulid } from 'ulid';
import type { JwtPayload } from '@mbfd/shared';
import { requireAdmin } from './admin/middleware.js';
import { getDb } from '../db/index.js';
import { aiAdvisories } from '../db/schema.js';
import { AnthropicAIClient } from '../ai/client.js';
import { systemBlock } from '../ai/prompts/system-2026.js';
import { rosterBlock } from '../ai/prompts/user-roster.js';
import { turnBlock } from '../ai/prompts/user-turn.js';
import { loadRosterForSession, loadTurnStateForSession } from '../ai/session-loader.js';
import type { WorkerEnv } from '../types/env.js';

type AiEnv = { Bindings: WorkerEnv; Variables: { claims: JwtPayload } };

const r = new Hono<AiEnv>();
r.use('*', requireAdmin);

r.get('/advise-current', async (c) => {
  const sessionId = c.req.query('session_id');
  if (!sessionId) return c.json({ error: 'session_id_required' }, 400);

  // Feature flag — fail fast before building prompts
  const flag = await c.env.AI_KV.get(c.env.AI_FEATURE_FLAG_KEY);
  if (flag === 'false') return c.json({ disabled: true, reason: 'feature_flag_off' }, 503);

  const roster = await loadRosterForSession(c.env, sessionId);
  const state = await loadTurnStateForSession(c.env, sessionId);

  const client = new AnthropicAIClient(c.env);
  const envelope = await client.adviseCurrent({
    bidSessionId: sessionId,
    system: systemBlock(),
    roster: rosterBlock(roster),
    turn: turnBlock({ ...state, question: 'Advise on the current bidder\'s upcoming pick.' }),
  });

  if (!envelope.stale && envelope.ai_advisory_id) {
    const db = getDb(c.env.DB);
    await db.insert(aiAdvisories).values({
      id: envelope.ai_advisory_id,
      bidSessionId: sessionId,
      memberId: null,
      positionId: null,
      triggeredBy: 'turn_start',
      model: 'claude-sonnet-4-6',
      promptHash: await client.hashPrompt(JSON.stringify(systemBlock()), JSON.stringify(rosterBlock(roster)), JSON.stringify(turnBlock({ ...state, question: '' }))),
      responseJson: JSON.stringify(envelope.advisory),
      renderedMarkdown: envelope.advisory.summary,
      latencyMs: Math.max(0, Date.now() - envelope.generated_at_ms),
      costCents: 0, // already added to running KV by client; ledger row tracks 0 for double-counting safety
      cacheHitRatio: 0,
    });
  }

  return c.json(envelope);
});

export default r;
```

- [ ] **Step 4: Implement `apps/worker/src/ai/session-loader.ts`** — the loader can stub for tests; Plan 04 / 05 wire it to the real DO. For now it reads members + eligibility matrix from D1.

```ts
// apps/worker/src/ai/session-loader.ts
import { getDb } from '../db/index.js';
import { members, memberCredentials, credentials } from '../db/schema.js';
import { eq } from 'drizzle-orm';
import type { WorkerEnv } from '../types/env.js';
import type { RosterInput, RosterMember, EligibilityMatrixRow } from './prompts/user-roster.js';
import type { TurnInput } from './prompts/user-turn.js';

export async function loadRosterForSession(env: WorkerEnv, bidSessionId: string): Promise<RosterInput> {
  const db = getDb(env.DB);
  const rows = await db.select().from(members).all();
  const rosterMembers: RosterMember[] = rows.map((m) => ({
    employeeId: m.employeeId, firstName: m.firstName, lastName: m.lastName,
    rank: m.rank as RosterMember['rank'], bidCategory: m.bidCategory,
    rscSeniority: m.rscSeniority, rankSeniority: m.rankSeniority,
    isProbationary: m.isProbationary,
    credentials: [], // populated via a second join in real impl
    priorYearBid: null,
  }));
  // Eligibility matrix: pull from KV snapshot per spec §6.2
  const snap = await env.KV.get(`eligibility_snapshot:${bidSessionId}`);
  const eligibilityMatrix: EligibilityMatrixRow[] = snap ? JSON.parse(snap) : [];
  return { bidSessionId, members: rosterMembers, eligibilityMatrix };
}

export async function loadTurnStateForSession(env: WorkerEnv, bidSessionId: string): Promise<TurnInput> {
  // Read the DO-shadowed bid_sessions row + position_fills snapshot from D1.
  // For the initial wire-up, return a placeholder shape and let Plan 04/05
  // integration tests cover the real path.
  return {
    phase: 'position_bid',
    currentBidderEmployeeId: null,
    queue: [],
    positionFills: {},
    remainingPositionIds: [],
    question: '',
  };
}
```

- [ ] **Step 5: Mount route in `apps/worker/src/index.ts`**

```ts
import adminAi from './routes/ai.js';
// inside the routes builder:
.route('/api/admin/ai', adminAi)
```

- [ ] **Step 6: Run tests, expect PASS**

- [ ] **Step 7: Commit**

```bash
git add apps/worker/src/routes/ai.ts apps/worker/src/ai/session-loader.ts apps/worker/src/index.ts apps/worker/tests/routes/ai-advise-current.test.ts
git commit -m "feat(ai): GET /api/admin/ai/advise-current with Sonnet + envelope response"
```

---

## Task 11: `/api/admin/ai/advise-deep` SSE streaming endpoint (Opus)

**Files:**
- Modify: `apps/worker/src/routes/ai.ts` (extend)
- Test: `apps/worker/tests/routes/ai-advise-deep.test.ts`

**Goal:** admin POST with `{ question }`. Streams Server-Sent Events as Anthropic's SSE arrives. Client renders tokens live.

- [ ] **Step 1: Write failing test**

```ts
// apps/worker/tests/routes/ai-advise-deep.test.ts
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { Hono } from 'hono';
import adminAi from '../../src/routes/ai.js';
import { signJwt } from '../../src/lib/jwt.js';

const mkKv = () => {
  const store = new Map<string, string>();
  return { store, get: vi.fn(async (k: string) => store.get(k) ?? null), put: vi.fn(async (k:string,v:string)=>void store.set(k,v)), delete: vi.fn(), list: vi.fn(), getWithMetadata: vi.fn() };
};

function fakeAnthropicSse() {
  // Emit two text-delta events then message_stop
  const enc = new TextEncoder();
  const chunks = [
    'event: message_start\ndata: {"type":"message_start","message":{"id":"m","model":"claude-opus-4-7","usage":{"input_tokens":1,"output_tokens":0,"cache_creation_input_tokens":0,"cache_read_input_tokens":0}}}\n\n',
    'event: content_block_delta\ndata: {"type":"content_block_delta","index":0,"delta":{"type":"text_delta","text":"Hello"}}\n\n',
    'event: content_block_delta\ndata: {"type":"content_block_delta","index":0,"delta":{"type":"text_delta","text":" admin"}}\n\n',
    'event: message_stop\ndata: {"type":"message_stop"}\n\n',
  ];
  const body = new ReadableStream<Uint8Array>({
    start(controller) {
      for (const c of chunks) controller.enqueue(enc.encode(c));
      controller.close();
    },
  });
  return new Response(body, { status: 200, headers: { 'Content-Type': 'text/event-stream' } });
}

let env: any, app: any;
beforeEach(() => {
  env = { ENV:'staging', CF_AI_GATEWAY_URL:'https://gateway.example.com/v1/abc/mbfd-bid/anthropic', ANTHROPIC_API_KEY:'sk', JWT_SIGNING_KEY:'a'.repeat(32), AI_BUDGET_CAP_CENTS:2500, AI_FEATURE_FLAG_KEY:'ai_advisory_enabled', AI_KV: mkKv(), KV: mkKv(), DB: {} };
  globalThis.fetch = vi.fn().mockResolvedValue(fakeAnthropicSse());
  app = new Hono<{ Bindings:any }>().route('/api/admin/ai', adminAi);
});

it('returns 401 without auth', async () => {
  const res = await app.request('/api/admin/ai/advise-deep', { method:'POST', body: JSON.stringify({ session_id:'s', question:'q' }), headers:{ 'Content-Type':'application/json' } }, env);
  expect(res.status).toBe(401);
});

it('streams text/event-stream with token deltas', async () => {
  const jwt = await signJwt({ memberId:1, role:'admin', employeeId:'1' }, env.JWT_SIGNING_KEY);
  const res = await app.request('/api/admin/ai/advise-deep', {
    method:'POST',
    body: JSON.stringify({ session_id:'s1', question:'why force?' }),
    headers:{ 'Content-Type':'application/json', 'Authorization': `Bearer ${jwt}` },
  }, env);
  expect(res.status).toBe(200);
  expect(res.headers.get('Content-Type')).toContain('text/event-stream');
  const text = await res.text();
  expect(text).toContain('data: Hello');
  expect(text).toContain('data:  admin');
  expect(text).toContain('event: done');
});

it('returns 503 when feature flag off', async () => {
  env.AI_KV.store.set('ai_advisory_enabled','false');
  const jwt = await signJwt({ memberId:1, role:'admin', employeeId:'1' }, env.JWT_SIGNING_KEY);
  const res = await app.request('/api/admin/ai/advise-deep', { method:'POST', body: JSON.stringify({ session_id:'s', question:'q' }), headers:{ 'Content-Type':'application/json', 'Authorization': `Bearer ${jwt}` } }, env);
  expect(res.status).toBe(503);
});
```

- [ ] **Step 2: Run test, expect FAIL**

- [ ] **Step 3: Extend `apps/worker/src/routes/ai.ts`** with the deep route

```ts
import { createParser } from 'eventsource-parser';
import { z } from 'zod';

const DeepBodySchema = z.object({
  session_id: z.string().min(1),
  question: z.string().min(1).max(2000),
});

r.post('/advise-deep', async (c) => {
  const parsed = DeepBodySchema.safeParse(await c.req.json());
  if (!parsed.success) return c.json({ error: 'bad_body' }, 400);
  const { session_id, question } = parsed.data;

  const flag = await c.env.AI_KV.get(c.env.AI_FEATURE_FLAG_KEY);
  if (flag === 'false') return c.json({ disabled: true, reason: 'feature_flag_off' }, 503);

  const roster = await loadRosterForSession(c.env, session_id);
  const state = await loadTurnStateForSession(c.env, session_id);
  const client = new AnthropicAIClient(c.env);

  // Use the raw SDK stream and forward token deltas as SSE
  let stream;
  try {
    stream = await client.adviseDeepStream({
      bidSessionId: session_id,
      system: systemBlock(),
      roster: rosterBlock(roster),
      turn: turnBlock({ ...state, question }),
    });
  } catch (err: any) {
    if (err?.kind === 'disabled') return c.json({ disabled: true, reason: err.message }, 503);
    return c.json({ error: 'upstream' }, 502);
  }

  const enc = new TextEncoder();
  const body = new ReadableStream<Uint8Array>({
    async start(controller) {
      try {
        for await (const event of stream) {
          if (event.type === 'content_block_delta' && event.delta.type === 'text_delta') {
            controller.enqueue(enc.encode(`data: ${event.delta.text}\n\n`));
          }
        }
        controller.enqueue(enc.encode('event: done\ndata: end\n\n'));
        controller.close();
      } catch (err) {
        controller.enqueue(enc.encode('event: error\ndata: stream_error\n\n'));
        controller.close();
      }
    },
  });

  return new Response(body, {
    status: 200,
    headers: {
      'Content-Type': 'text/event-stream',
      'Cache-Control': 'no-cache',
      'Connection': 'keep-alive',
      'X-Accel-Buffering': 'no',
    },
  });
});
```

- [ ] **Step 4: Run tests, expect PASS**

- [ ] **Step 5: Commit**

```bash
git add apps/worker/src/routes/ai.ts apps/worker/tests/routes/ai-advise-deep.test.ts
git commit -m "feat(ai): POST /api/admin/ai/advise-deep SSE streaming with Opus"
```

---

## Task 12: Cache-warm pre-fetch hook

**Files:**
- Create: `apps/worker/src/ai/cache-warm.ts`
- Test: `apps/worker/tests/ai/cache-warm.test.ts`
- Modify: `apps/worker/src/routes/ai.ts` — small POST endpoint for the DO to call (`POST /api/admin/ai/_internal/cache-warm`) OR exported function the DO imports directly. Plan 04's DO advances cursor and calls this; we expose both paths.

**Goal:** ~1 second before the on-deck member's turn becomes current, fire a Sonnet call with their roster + turn block. The advisory lands in `last_good:<session_id>` so when the admin panel hits `/advise-current` at turn-start, the SDK's cache is warm and the call is mostly cache-read tokens (10 % cost).

- [ ] **Step 1: Write failing test**

```ts
// apps/worker/tests/ai/cache-warm.test.ts
import { describe, it, expect, vi } from 'vitest';
import { warmCacheForOnDeck } from '../../src/ai/cache-warm.js';
import canonical from './__fixtures__/advisory-canonical.json';

const env: any = {
  ENV: 'staging',
  CF_AI_GATEWAY_URL: 'https://gateway.example.com/v1/abc/mbfd-bid/anthropic',
  ANTHROPIC_API_KEY: 'sk',
  AI_BUDGET_CAP_CENTS: 2500,
  AI_FEATURE_FLAG_KEY: 'ai_advisory_enabled',
  AI_KV: (() => {
    const s = new Map<string, string>();
    return { store:s, get: vi.fn(async (k:string) => s.get(k) ?? null), put: vi.fn(async (k:string,v:string)=>void s.set(k,v)), delete: vi.fn(), list: vi.fn(), getWithMetadata: vi.fn() };
  })(),
  KV: { get: vi.fn(async () => null), put: vi.fn(), delete: vi.fn(), list: vi.fn(), getWithMetadata: vi.fn() },
  DB: {},
};

it('writes last_good for the session when called', async () => {
  globalThis.fetch = vi.fn().mockResolvedValue(new Response(JSON.stringify({
    id:'m', type:'message', role:'assistant',
    content:[{ type:'text', text: JSON.stringify(canonical) }],
    stop_reason:'end_turn', model:'claude-sonnet-4-6',
    usage:{ input_tokens: 100, output_tokens: 50, cache_creation_input_tokens:0, cache_read_input_tokens:0 },
  }), { status: 200 }));
  await warmCacheForOnDeck(env, 'sess1', '14335');
  expect(env.AI_KV.store.has('ai_last_good:sess1')).toBe(true);
});

it('is a no-op when feature flag off', async () => {
  env.AI_KV.store.set('ai_advisory_enabled', 'false');
  const fetchSpy = vi.fn();
  globalThis.fetch = fetchSpy;
  await warmCacheForOnDeck(env, 'sess1', '14335');
  expect(fetchSpy).not.toHaveBeenCalled();
});
```

- [ ] **Step 2: Run test, expect FAIL**

- [ ] **Step 3: Implement `apps/worker/src/ai/cache-warm.ts`**

```ts
// apps/worker/src/ai/cache-warm.ts
import { AnthropicAIClient } from './client.js';
import { systemBlock } from './prompts/system-2026.js';
import { rosterBlock } from './prompts/user-roster.js';
import { turnBlock } from './prompts/user-turn.js';
import { loadRosterForSession, loadTurnStateForSession } from './session-loader.js';
import type { WorkerEnv } from '../types/env.js';

/**
 * Fire-and-forget pre-fetch. Should be called from the DO's onTurnAdvance
 * hook (Plan 04) approximately 1 second before the on-deck member's turn
 * becomes current.
 */
export async function warmCacheForOnDeck(
  env: WorkerEnv,
  bidSessionId: string,
  onDeckEmployeeId: string,
): Promise<void> {
  const flag = await env.AI_KV.get(env.AI_FEATURE_FLAG_KEY);
  if (flag === 'false') return;

  const roster = await loadRosterForSession(env, bidSessionId);
  const state = await loadTurnStateForSession(env, bidSessionId);
  // Override current bidder to the on-deck member so the prompt reflects
  // who the upcoming pick is for.
  const turn = turnBlock({ ...state, currentBidderEmployeeId: onDeckEmployeeId, question: 'Pre-fetch: advise on the upcoming pick for the on-deck bidder.' });

  const client = new AnthropicAIClient(env);
  try {
    await client.preFetch({
      bidSessionId,
      system: systemBlock(),
      roster: rosterBlock(roster),
      turn,
      timeoutMs: 4000,
    });
  } catch {
    // Pre-fetch failures are silent — turn-start will retry.
  }
}
```

- [ ] **Step 4: Run tests, expect PASS**

- [ ] **Step 5: Commit**

```bash
git add apps/worker/src/ai/cache-warm.ts apps/worker/tests/ai/cache-warm.test.ts
git commit -m "feat(ai): on-deck pre-fetch hook writes last-good into AI_KV"
```

---

## Task 13: `/api/admin/ai/forecast` + scheduled cron

**Files:**
- Modify: `apps/worker/src/routes/ai.ts` (add GET /forecast)
- Create: `apps/worker/src/scheduled.ts`
- Modify: `apps/worker/src/index.ts` (export `scheduled` handler)
- Test: `apps/worker/tests/routes/ai-forecast.test.ts`
- Test: `apps/worker/tests/scheduled.test.ts`

**Goal:** the dashboard polls `/forecast` every 30 s; the cron triggers a refresh every 10 minutes when a live session exists. Result cached in KV `ai_forecast:<session_id>`.

- [ ] **Step 1: Write failing tests** (both routes and cron)

```ts
// apps/worker/tests/routes/ai-forecast.test.ts
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { Hono } from 'hono';
import adminAi from '../../src/routes/ai.js';
import { signJwt } from '../../src/lib/jwt.js';
import canonical from '../ai/__fixtures__/advisory-canonical.json';

let env: any, app: any;
const mkKv = () => { const s = new Map<string,string>(); return { store:s, get: vi.fn(async (k:string)=> s.get(k) ?? null), put: vi.fn(async (k:string,v:string)=>void s.set(k,v)), delete: vi.fn(), list: vi.fn(), getWithMetadata: vi.fn() }; };

beforeEach(() => {
  env = { ENV:'staging', CF_AI_GATEWAY_URL:'https://x/y', ANTHROPIC_API_KEY:'sk', JWT_SIGNING_KEY:'a'.repeat(32), AI_BUDGET_CAP_CENTS:2500, AI_FEATURE_FLAG_KEY:'ai_advisory_enabled', AI_KV: mkKv(), KV: mkKv(), DB: {} };
  app = new Hono<{ Bindings:any }>().route('/api/admin/ai', adminAi);
});

it('returns the cached forecast envelope', async () => {
  env.AI_KV.store.set('ai_forecast:s1', JSON.stringify({
    advisory: canonical, stale: false, fallback: 'none',
    generated_at_ms: Date.now(), ai_advisory_id: 'x',
  }));
  const jwt = await signJwt({ memberId:1, role:'admin', employeeId:'1' }, env.JWT_SIGNING_KEY);
  const res = await app.request('/api/admin/ai/forecast?session_id=s1', { headers:{ Authorization:`Bearer ${jwt}` } }, env);
  expect(res.status).toBe(200);
  const body = await res.json();
  expect(body.advisory.summary).toBeDefined();
});

it('returns 404 when no forecast cached', async () => {
  const jwt = await signJwt({ memberId:1, role:'admin', employeeId:'1' }, env.JWT_SIGNING_KEY);
  const res = await app.request('/api/admin/ai/forecast?session_id=missing', { headers:{ Authorization:`Bearer ${jwt}` } }, env);
  expect(res.status).toBe(404);
});
```

```ts
// apps/worker/tests/scheduled.test.ts
import { describe, it, expect, vi } from 'vitest';
import { handleScheduled } from '../src/scheduled.js';
import canonical from './ai/__fixtures__/advisory-canonical.json';

it('refreshes ai_forecast:<session_id> for each live session', async () => {
  const env: any = {
    ENV:'staging', CF_AI_GATEWAY_URL:'https://x/y', ANTHROPIC_API_KEY:'sk',
    AI_BUDGET_CAP_CENTS:2500, AI_FEATURE_FLAG_KEY:'ai_advisory_enabled',
    AI_KV: (() => { const s = new Map<string,string>(); return { store:s, get: vi.fn(async (k:string)=> s.get(k) ?? null), put: vi.fn(async (k:string,v:string)=>void s.set(k,v)), delete: vi.fn(), list: vi.fn(async () => ({ keys: [] })), getWithMetadata: vi.fn() }; })(),
    KV: { get: vi.fn(async () => null), put: vi.fn(), delete: vi.fn(), list: vi.fn(), getWithMetadata: vi.fn() },
    DB: {
      select: () => ({ from: () => ({ where: () => ({ all: async () => [{ id: 'sess1' }] }) }) }),
      insert: () => ({ values: () => ({ run: async () => ({}) }) }),
    },
  };
  globalThis.fetch = vi.fn().mockResolvedValue(new Response(JSON.stringify({
    id:'m', type:'message', role:'assistant',
    content:[{ type:'text', text: JSON.stringify(canonical) }],
    stop_reason:'end_turn', model:'claude-sonnet-4-6',
    usage:{ input_tokens: 10, output_tokens: 5, cache_creation_input_tokens:0, cache_read_input_tokens:0 },
  }), { status: 200 }));
  await handleScheduled(env);
  expect(env.AI_KV.store.has('ai_forecast:sess1')).toBe(true);
});

it('is a no-op when no live sessions', async () => {
  const env: any = {
    ENV:'staging', CF_AI_GATEWAY_URL:'https://x/y', ANTHROPIC_API_KEY:'sk',
    AI_BUDGET_CAP_CENTS:2500, AI_FEATURE_FLAG_KEY:'ai_advisory_enabled',
    AI_KV: { get: vi.fn(async () => null), put: vi.fn(), delete: vi.fn(), list: vi.fn(), getWithMetadata: vi.fn() },
    KV: { get: vi.fn(), put: vi.fn(), delete: vi.fn(), list: vi.fn(), getWithMetadata: vi.fn() },
    DB: { select: () => ({ from: () => ({ where: () => ({ all: async () => [] }) }) }) },
  };
  const fetchSpy = vi.fn();
  globalThis.fetch = fetchSpy;
  await handleScheduled(env);
  expect(fetchSpy).not.toHaveBeenCalled();
});
```

- [ ] **Step 2: Run tests, expect FAIL**

- [ ] **Step 3: Extend `apps/worker/src/routes/ai.ts`** with the GET /forecast handler

```ts
r.get('/forecast', async (c) => {
  const sessionId = c.req.query('session_id');
  if (!sessionId) return c.json({ error: 'session_id_required' }, 400);
  const raw = await c.env.AI_KV.get(`ai_forecast:${sessionId}`);
  if (!raw) return c.json({ error: 'no_forecast_cached' }, 404);
  return c.body(raw, 200, { 'Content-Type': 'application/json', 'Cache-Control': 'private, max-age=30' });
});
```

- [ ] **Step 4: Implement `apps/worker/src/scheduled.ts`**

```ts
// apps/worker/src/scheduled.ts
import { getDb } from './db/index.js';
import { bidSessions } from './db/schema.js';
import { eq } from 'drizzle-orm';
import { AnthropicAIClient } from './ai/client.js';
import { systemBlock } from './ai/prompts/system-2026.js';
import { rosterBlock } from './ai/prompts/user-roster.js';
import { turnBlock } from './ai/prompts/user-turn.js';
import { loadRosterForSession, loadTurnStateForSession } from './ai/session-loader.js';
import type { WorkerEnv } from './types/env.js';

const FORECAST_QUESTION =
  'Provide a department-wide forecast: which credentials are running short, ' +
  'which positions look likely to go unfilled, which members are most affected. ' +
  'Return ONLY the JSON object specified in the system prompt.';

export async function handleScheduled(env: WorkerEnv): Promise<void> {
  const db = getDb(env.DB);
  const live = await db
    .select({ id: bidSessions.id })
    .from(bidSessions)
    .where(eq(bidSessions.currentPhase, 'position_bid'))
    .all();
  if (live.length === 0) return;

  const client = new AnthropicAIClient(env);
  for (const s of live) {
    const roster = await loadRosterForSession(env, s.id);
    const state = await loadTurnStateForSession(env, s.id);
    try {
      const envelope = await client.adviseCurrent({
        bidSessionId: s.id,
        system: systemBlock(),
        roster: rosterBlock(roster),
        turn: turnBlock({ ...state, question: FORECAST_QUESTION }),
      });
      await env.AI_KV.put(`ai_forecast:${s.id}`, JSON.stringify(envelope), { expirationTtl: 60 * 60 });
    } catch {
      // best-effort; consumers see the old cached value or 404
    }
  }
}
```

- [ ] **Step 5: Export `scheduled` handler from `apps/worker/src/index.ts`**

```ts
import { handleScheduled } from './scheduled.js';
export default {
  fetch: app.fetch.bind(app),
  scheduled: async (_event: ScheduledEvent, env: WorkerEnv, _ctx: ExecutionContext) => {
    await handleScheduled(env);
  },
};
```

(If the existing index.ts currently has `export default app;`, replace it with the new default object that wraps both `fetch` and `scheduled`. Update any existing tests that reference `import app from '../src/index'`.)

- [ ] **Step 6: Run tests, expect PASS**

- [ ] **Step 7: Commit**

```bash
git add apps/worker/src/routes/ai.ts apps/worker/src/scheduled.ts apps/worker/src/index.ts apps/worker/tests/routes/ai-forecast.test.ts apps/worker/tests/scheduled.test.ts
git commit -m "feat(ai): forecast endpoint + cron-driven refresh every 10 minutes"
```

---

## Task 14: Dissent log writer + audit enum migration

**Files:**
- Create: `apps/worker/migrations/0005_audit_dissent_action.sql`
- Modify: `apps/worker/src/db/schema.ts` (extend `auditLog.action` enum)
- Modify: `apps/worker/src/lib/audit.ts` (add `'dissent'` to `AuditAction`)
- Create: `apps/worker/src/ai/dissent.ts`
- Test: `apps/worker/tests/ai/dissent.test.ts`
- Modify (consumer wire-up): `apps/worker/src/routes/admin/bid-session.ts` — when force-pick succeeds, call `recordDissentIfNeeded()`. **This file is created by Plan 05; if Plan 05 has not landed yet, the Plan 06 implementer adds a small TODO comment in `audit.ts` and the wiring becomes a Plan 05 follow-up commit.**

**Goal:** when admin force-picks, compare admin's action to the AI's most recent advisory for that bidder. If `force_recommended !== true`, write an `audit_log.action='dissent'` row with the AI's reasoning. The dissent row is visible in the audit viewer.

- [ ] **Step 1: Write the migration**

```sql
-- apps/worker/migrations/0005_audit_dissent_action.sql
-- D1 (SQLite) does not enforce text enums at the DB level — drizzle does the
-- enforcement in code. This migration is a recordkeeping no-op DDL paired
-- with the schema change that adds 'dissent' to the AuditAction union.

-- Sanity: ensure the table still exists.
SELECT 1 FROM audit_log LIMIT 1;
```

- [ ] **Step 2: Extend `auditLog.action` enum in `apps/worker/src/db/schema.ts`** — append `'dissent'` to the enum array.

- [ ] **Step 3: Extend `AuditAction` in `apps/worker/src/lib/audit.ts`**

```ts
export type AuditAction =
  | 'pick' | 'forced_pick' | 'pause' | 'resume' | 'skip'
  | 'override_rule' | 'override_cert' | 'lock_position' | 'unlock_position'
  | 'grant_extension' | 'admin_bid_for_member' | 'session_start' | 'session_complete'
  | 'members_import' | 'credentials_import' | 'positions_clone' | 'rule_book_clone'
  | 'dissent';
```

- [ ] **Step 4: Write failing test**

```ts
// apps/worker/tests/ai/dissent.test.ts
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { recordDissentIfNeeded } from '../../src/ai/dissent.js';
import canonical from './__fixtures__/advisory-canonical.json';

const mkKv = () => { const s = new Map<string,string>(); return { store:s, get: vi.fn(async (k:string)=> s.get(k) ?? null), put: vi.fn(async (k:string,v:string)=>void s.set(k,v)), delete: vi.fn(), list: vi.fn(), getWithMetadata: vi.fn() }; };

let env: any, dbInsert: any;
beforeEach(() => {
  dbInsert = vi.fn().mockReturnValue({ values: vi.fn().mockResolvedValue({}) });
  env = {
    AI_KV: mkKv(),
    KV: mkKv(),
    DB: { insert: dbInsert, select: () => ({ from: () => ({ where: () => ({ get: async () => null }) }) }) },
  };
});

it('writes a dissent row when force_recommended=false and admin force-picks', async () => {
  env.AI_KV.store.set('ai_last_good:sess1', JSON.stringify({
    advisory: { ...canonical, force_recommended: false },
    generated_at_ms: Date.now(),
    ai_advisory_id: 'prev1',
  }));
  await recordDissentIfNeeded(env, {
    bidSessionId: 'sess1',
    actorMemberId: 99,
    actionKind: 'forced_pick',
    targetMemberEmployeeId: '14335',
    targetPositionId: 'A101',
    reason: 'reverse seniority lock',
  });
  expect(dbInsert).toHaveBeenCalled();
});

it('does NOT write a dissent row when force_recommended=true', async () => {
  env.AI_KV.store.set('ai_last_good:sess1', JSON.stringify({
    advisory: { ...canonical, force_recommended: true, force_reasoning: 'last credentialed' },
    generated_at_ms: Date.now(),
    ai_advisory_id: 'prev1',
  }));
  await recordDissentIfNeeded(env, {
    bidSessionId: 'sess1',
    actorMemberId: 99,
    actionKind: 'forced_pick',
    targetMemberEmployeeId: '14335',
    targetPositionId: 'A101',
    reason: 'last cred',
  });
  expect(dbInsert).not.toHaveBeenCalled();
});

it('does NOT write a dissent row when there is no recent advisory', async () => {
  await recordDissentIfNeeded(env, {
    bidSessionId: 'sessX',
    actorMemberId: 99,
    actionKind: 'forced_pick',
    targetMemberEmployeeId: '14335',
    targetPositionId: 'A101',
    reason: 'x',
  });
  expect(dbInsert).not.toHaveBeenCalled();
});
```

- [ ] **Step 5: Run test, expect FAIL**

- [ ] **Step 6: Implement `apps/worker/src/ai/dissent.ts`**

```ts
// apps/worker/src/ai/dissent.ts
import { writeAuditLog } from '../lib/audit.js';
import { getDb } from '../db/index.js';
import type { WorkerEnv } from '../types/env.js';
import type { Advisory } from './output-schema.js';

export interface DissentInput {
  bidSessionId: string;
  actorMemberId: number;
  actionKind: 'forced_pick' | 'skip';
  targetMemberEmployeeId: string;
  targetPositionId: string | null;
  reason: string;
}

/** If the most recent advisory disagrees with the admin's action, writes an
 * `audit_log.action='dissent'` row. No-op otherwise. */
export async function recordDissentIfNeeded(env: WorkerEnv, i: DissentInput): Promise<void> {
  const raw = await env.AI_KV.get(`ai_last_good:${i.bidSessionId}`);
  if (!raw) return;
  let advisory: Advisory | null = null;
  let aiAdvisoryId: string | null = null;
  try {
    const obj = JSON.parse(raw);
    advisory = obj.advisory as Advisory;
    aiAdvisoryId = obj.ai_advisory_id ?? null;
  } catch { return; }
  if (!advisory) return;

  // The admin force-picked; if the AI also recommended force, there's no dissent.
  if (i.actionKind === 'forced_pick' && advisory.force_recommended === true) return;

  // No advisory specifically about this position → still record dissent for
  // a forced pick that contradicts the recommended set.
  const db = getDb(env.DB);
  await writeAuditLog(db, {
    bidSessionId: i.bidSessionId,
    actorType: 'system',
    actorId: null,
    action: 'dissent',
    targetKind: 'member',
    targetId: i.targetMemberEmployeeId,
    beforeState: { ai_advisory_id: aiAdvisoryId, ai_force_recommended: advisory.force_recommended },
    afterState: { admin_action: i.actionKind, admin_actor_member_id: i.actorMemberId, position_id: i.targetPositionId, reason: i.reason },
    aiAdvisoryId,
    reason: 'admin action diverges from AI recommendation',
  });
}
```

- [ ] **Step 7: Run tests, expect PASS**

- [ ] **Step 8: Commit**

```bash
git add apps/worker/migrations/0005_audit_dissent_action.sql apps/worker/src/db/schema.ts apps/worker/src/lib/audit.ts apps/worker/src/ai/dissent.ts apps/worker/tests/ai/dissent.test.ts
git commit -m "feat(ai): dissent log writer + 'dissent' audit action"
```

- [ ] **Step 9: Wire `recordDissentIfNeeded()` into Plan 05's force-pick handler** — when Plan 05 has landed, add the call inside its `/api/admin/bid-session/:id/force-pick` handler immediately after the pick is committed. The Plan 05 implementer is responsible for that two-line edit; this plan ships the writer so the wiring is a 5-minute follow-up.

---

## Task 15: Web — AIAdvisoryPanel (always-visible side panel)

**Files:**
- Create: `apps/web/app/admin/bid/_components/AIAdvisoryPanel.tsx`
- Create: `apps/web/lib/ai-sse-client.ts` (used in Task 16; placeholder okay here)
- Modify: `apps/web/app/admin/bid/page.tsx` (slot the panel)
- Test: `apps/web/tests/e2e/ai-panel.spec.ts`

- [ ] **Step 1: Write failing Playwright test**

```ts
// apps/web/tests/e2e/ai-panel.spec.ts
import { test, expect } from '@playwright/test';

test.describe('AIAdvisoryPanel', () => {
  test('renders within 2s of turn-start with summary, recommendations, ineligible-top-picks, forecast', async ({ page }) => {
    // Network mocks via Playwright route()
    await page.route('**/api/admin/ai/advise-current**', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          advisory: {
            summary: 'Test summary',
            eligible_recommendations: [{ position_id: 'B105', points: 9, why: 'has marine' }],
            ineligible_top_picks: [{ position_id: 'D101', why_ineligible: 'no inspector cert' }],
            forecast: { warnings: [{ level: 'warn', text: 'paramedic short', affected_positions: ['A305'] }] },
            force_recommended: false,
          },
          stale: false, fallback: 'none', generated_at_ms: Date.now(), ai_advisory_id: 'x',
        }),
      });
    });

    await loginAsAdmin(page);
    await page.goto('/admin/bid');
    await expect(page.getByTestId('ai-panel-summary')).toContainText('Test summary');
    await expect(page.getByTestId('ai-panel-recommendation-B105')).toContainText('has marine');
    await expect(page.getByTestId('ai-panel-ineligible-D101')).toContainText('no inspector cert');
    await expect(page.getByTestId('ai-panel-warning-A305')).toContainText('paramedic short');
  });

  test('shows stale badge when envelope.stale = true', async ({ page }) => {
    await page.route('**/api/admin/ai/advise-current**', async (route) => {
      await route.fulfill({
        status: 200, contentType: 'application/json',
        body: JSON.stringify({ advisory: { summary: 'cached', eligible_recommendations: [], ineligible_top_picks: [], forecast: { warnings: [] }, force_recommended: false }, stale: true, fallback: 'last_good', generated_at_ms: Date.now() - 5000, ai_advisory_id: 'old' }),
      });
    });
    await loginAsAdmin(page);
    await page.goto('/admin/bid');
    await expect(page.getByTestId('ai-panel-stale-badge')).toBeVisible();
  });
});

async function loginAsAdmin(page: any) {
  // Reuses test helper that sets the mbfd_pin cookie + admin JWT cookie.
  await page.context().addCookies([{ name: 'mbfd_pin', value: 'ok', domain: 'localhost', path: '/' }]);
  // ... admin JWT setup
}
```

- [ ] **Step 2: Run test, expect FAIL**

- [ ] **Step 3: Implement `apps/web/app/admin/bid/_components/AIAdvisoryPanel.tsx`**

```tsx
'use client';
import { useQuery } from '@tanstack/react-query';
import type { AdvisoryEnvelope } from '@mbfd/shared';

interface Props { bidSessionId: string; turnTimerSeconds: number; }

export function AIAdvisoryPanel({ bidSessionId, turnTimerSeconds }: Props) {
  const { data, isLoading, error } = useQuery<AdvisoryEnvelope>({
    queryKey: ['ai-advise-current', bidSessionId],
    queryFn: async () => {
      const r = await fetch(`/api/admin/ai/advise-current?session_id=${bidSessionId}`, { credentials: 'include' });
      if (r.status === 503) {
        const body = await r.json();
        throw new Error(body.reason ?? 'disabled');
      }
      if (!r.ok) throw new Error('http_' + r.status);
      return r.json();
    },
    staleTime: turnTimerSeconds * 1000,
    refetchInterval: turnTimerSeconds * 1000,
  });

  if (isLoading) return <aside data-testid="ai-panel-loading" className="p-4">Loading advisory…</aside>;
  if (error) return <aside data-testid="ai-panel-error" className="p-4 text-stone-500">AI advisor unavailable</aside>;
  if (!data) return null;

  const { advisory, stale } = data;
  return (
    <aside className="border-l border-stone-200 bg-white p-4 w-[360px] flex flex-col gap-4" aria-label="AI advisory">
      <header className="flex items-center justify-between">
        <h2 className="font-semibold">AI Advisor</h2>
        {stale && <span data-testid="ai-panel-stale-badge" className="text-xs bg-amber-100 text-amber-900 rounded px-2 py-0.5">stale</span>}
      </header>
      <p data-testid="ai-panel-summary" className="text-sm">{advisory.summary}</p>
      <section>
        <h3 className="text-xs font-semibold uppercase tracking-wide text-stone-500 mb-1">Recommendations</h3>
        <ul className="text-sm space-y-1">
          {advisory.eligible_recommendations.map((r) => (
            <li key={r.position_id} data-testid={`ai-panel-recommendation-${r.position_id}`}>
              <span className="font-mono">{r.position_id}</span> · {r.points}pts — {r.why}
            </li>
          ))}
        </ul>
      </section>
      <section>
        <h3 className="text-xs font-semibold uppercase tracking-wide text-stone-500 mb-1">Likely-want but ineligible</h3>
        <ul className="text-sm space-y-1">
          {advisory.ineligible_top_picks.map((r) => (
            <li key={r.position_id} data-testid={`ai-panel-ineligible-${r.position_id}`}>
              <span className="font-mono">{r.position_id}</span> — {r.why_ineligible}
            </li>
          ))}
        </ul>
      </section>
      <section>
        <h3 className="text-xs font-semibold uppercase tracking-wide text-stone-500 mb-1">Forecast</h3>
        <ul className="text-sm space-y-1">
          {advisory.forecast.warnings.map((w, i) => (
            <li key={i} data-testid={w.affected_positions.length ? `ai-panel-warning-${w.affected_positions[0]}` : `ai-panel-warning-${i}`}>
              <span className={w.level === 'critical' ? 'text-red-700 font-semibold' : w.level === 'warn' ? 'text-amber-700' : 'text-stone-600'}>
                {w.text}
              </span>
            </li>
          ))}
        </ul>
      </section>
    </aside>
  );
}
```

- [ ] **Step 4: Slot the panel into `apps/web/app/admin/bid/page.tsx`** — render the existing live-bid grid in a 2-column layout (left: bid board, right: AIAdvisoryPanel).

- [ ] **Step 5: Run E2E, expect PASS**

- [ ] **Step 6: Commit**

```bash
git add apps/web/app/admin/bid/_components/AIAdvisoryPanel.tsx apps/web/app/admin/bid/page.tsx apps/web/tests/e2e/ai-panel.spec.ts
git commit -m "feat(web): AIAdvisoryPanel on /admin/bid (TanStack Query + impeccable styling)"
```

---

## Task 16: Web — AIAskDeepDialog with SSE rendering

**Files:**
- Create: `apps/web/app/admin/bid/_components/AIAskDeepDialog.tsx`
- Modify: `apps/web/lib/ai-sse-client.ts`
- Test: `apps/web/tests/e2e/ai-deep-dialog.spec.ts`

- [ ] **Step 1: Write failing E2E test**

```ts
// apps/web/tests/e2e/ai-deep-dialog.spec.ts
import { test, expect } from '@playwright/test';

test('streams tokens from /advise-deep', async ({ page }) => {
  await page.route('**/api/admin/ai/advise-deep', async (route) => {
    await route.fulfill({
      status: 200,
      headers: { 'Content-Type': 'text/event-stream' },
      body: 'data: Hello\n\ndata:  admin\n\nevent: done\ndata: end\n\n',
    });
  });
  await loginAsAdmin(page);
  await page.goto('/admin/bid');
  await page.getByTestId('ai-ask-deep-trigger').click();
  await page.getByTestId('ai-ask-deep-input').fill('explain force-pick reasoning');
  await page.getByTestId('ai-ask-deep-submit').click();
  await expect(page.getByTestId('ai-ask-deep-output')).toContainText('Hello admin');
});
```

- [ ] **Step 2: Run test, expect FAIL**

- [ ] **Step 3: Implement `apps/web/lib/ai-sse-client.ts`**

```ts
// apps/web/lib/ai-sse-client.ts
export interface SseDeltaHandler { (chunk: string): void; }
export interface SseDoneHandler { (): void; }
export interface SseErrorHandler { (err: Error): void; }

export async function streamAdviseDeep(
  sessionId: string,
  question: string,
  onDelta: SseDeltaHandler,
  onDone: SseDoneHandler,
  onError: SseErrorHandler,
  abort?: AbortController,
): Promise<void> {
  const res = await fetch('/api/admin/ai/advise-deep', {
    method: 'POST',
    credentials: 'include',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ session_id: sessionId, question }),
    signal: abort?.signal,
  });
  if (!res.ok || !res.body) { onError(new Error('http_' + res.status)); return; }
  const reader = res.body.getReader();
  const decoder = new TextDecoder();
  let buffer = '';
  while (true) {
    const { value, done } = await reader.read();
    if (done) break;
    buffer += decoder.decode(value, { stream: true });
    let idx;
    while ((idx = buffer.indexOf('\n\n')) >= 0) {
      const frame = buffer.slice(0, idx);
      buffer = buffer.slice(idx + 2);
      const lines = frame.split('\n');
      let eventName = 'message';
      const dataParts: string[] = [];
      for (const ln of lines) {
        if (ln.startsWith('event:')) eventName = ln.slice(6).trim();
        else if (ln.startsWith('data:')) dataParts.push(ln.slice(5).replace(/^\s/, ''));
      }
      const payload = dataParts.join('\n');
      if (eventName === 'done') { onDone(); return; }
      if (eventName === 'error') { onError(new Error(payload)); return; }
      onDelta(payload);
    }
  }
  onDone();
}
```

- [ ] **Step 4: Implement `AIAskDeepDialog.tsx`**

```tsx
'use client';
import { useState } from 'react';
import { streamAdviseDeep } from '@/lib/ai-sse-client';

export function AIAskDeepDialog({ bidSessionId }: { bidSessionId: string }) {
  const [open, setOpen] = useState(false);
  const [question, setQuestion] = useState('');
  const [output, setOutput] = useState('');
  const [streaming, setStreaming] = useState(false);

  async function submit() {
    setStreaming(true); setOutput('');
    await streamAdviseDeep(
      bidSessionId, question,
      (chunk) => setOutput((s) => s + chunk),
      () => setStreaming(false),
      (err) => { setOutput((s) => s + `\n\n[error: ${err.message}]`); setStreaming(false); },
    );
  }

  return (
    <>
      <button data-testid="ai-ask-deep-trigger" onClick={() => setOpen(true)} className="text-sm underline">Ask deeper question</button>
      {open && (
        <div className="fixed inset-0 bg-stone-950/50 flex items-center justify-center" role="dialog">
          <div className="bg-white rounded-lg p-6 w-[640px] max-w-[90vw] flex flex-col gap-3">
            <h3 className="font-semibold">Ask the AI advisor</h3>
            <textarea data-testid="ai-ask-deep-input" value={question} onChange={(e) => setQuestion(e.target.value)} className="border rounded p-2 h-24" />
            <button data-testid="ai-ask-deep-submit" disabled={streaming} onClick={submit} className="bg-red-700 text-white rounded px-3 py-1 self-end">Ask</button>
            <pre data-testid="ai-ask-deep-output" className="border rounded p-2 text-sm whitespace-pre-wrap min-h-[6em]">{output}</pre>
            <button onClick={() => setOpen(false)} className="text-xs text-stone-500 self-end">Close</button>
          </div>
        </div>
      )}
    </>
  );
}
```

- [ ] **Step 5: Mount the dialog in `apps/web/app/admin/bid/page.tsx`** beside the AIAdvisoryPanel.

- [ ] **Step 6: Run E2E, expect PASS**

- [ ] **Step 7: Commit**

```bash
git add apps/web/app/admin/bid/_components/AIAskDeepDialog.tsx apps/web/lib/ai-sse-client.ts apps/web/tests/e2e/ai-deep-dialog.spec.ts
git commit -m "feat(web): AIAskDeepDialog with SSE streaming via /advise-deep"
```

---

## Task 17: Web — AIForecastBanner + AIDissentMarker + AICostPill

**Files:**
- Create: `apps/web/app/admin/bid/_components/AIForecastBanner.tsx`
- Create: `apps/web/app/admin/bid/_components/AIDissentMarker.tsx`
- Create: `apps/web/app/admin/bid/_components/AICostPill.tsx`
- Modify: `apps/web/app/admin/bid/page.tsx` (slot all three)
- Test: `apps/web/tests/e2e/ai-dissent-marker.spec.ts`

**Goal:** three small admin-UI components for forecast, dissent flag, and running cost.

- [ ] **Step 1: Failing E2E (dissent marker)**

```ts
// apps/web/tests/e2e/ai-dissent-marker.spec.ts
import { test, expect } from '@playwright/test';

test('dissent badge appears on audit-log entries whose ai_advisory shows force_recommended=false', async ({ page }) => {
  // Mock the audit endpoint with one dissent row
  await page.route('**/api/admin/audit**', async (route) => {
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({
      events: [{
        id: 'X', seq: 1, action: 'forced_pick', actor_type: 'admin', actor_id: 9,
        target_id: '14335', after_state: '{"position_id":"A101"}', ai_advisory_id: 'prev1',
      }, {
        id: 'X2', seq: 2, action: 'dissent', actor_type: 'system', actor_id: null,
        target_id: '14335', ai_advisory_id: 'prev1', reason: 'admin action diverges from AI recommendation',
      }],
    }) });
  });
  await loginAsAdmin(page);
  await page.goto('/admin/audit');
  await expect(page.getByTestId('audit-dissent-marker-prev1')).toBeVisible();
});
```

- [ ] **Step 2: Implement three components**

```tsx
// AIForecastBanner.tsx — top-of-page banner with critical/warn warnings
'use client';
import { useQuery } from '@tanstack/react-query';
import type { AdvisoryEnvelope } from '@mbfd/shared';

export function AIForecastBanner({ bidSessionId }: { bidSessionId: string }) {
  const { data } = useQuery<AdvisoryEnvelope>({
    queryKey: ['ai-forecast', bidSessionId],
    queryFn: async () => {
      const r = await fetch(`/api/admin/ai/forecast?session_id=${bidSessionId}`, { credentials: 'include' });
      if (r.status === 404) return null as any;
      return r.json();
    },
    refetchInterval: 30_000,
  });
  if (!data) return null;
  const top = data.advisory.forecast.warnings.find((w) => w.level === 'critical')
    ?? data.advisory.forecast.warnings.find((w) => w.level === 'warn');
  if (!top) return null;
  const tone = top.level === 'critical' ? 'bg-red-700 text-white' : 'bg-amber-100 text-amber-900';
  return (
    <div data-testid="ai-forecast-banner" className={`${tone} px-4 py-2 text-sm`}>
      <strong>{top.level.toUpperCase()}:</strong> {top.text}
    </div>
  );
}
```

```tsx
// AIDissentMarker.tsx — inline yellow badge on audit-log entries
'use client';
export function AIDissentMarker({ aiAdvisoryId }: { aiAdvisoryId: string | null }) {
  if (!aiAdvisoryId) return null;
  return (
    <span data-testid={`audit-dissent-marker-${aiAdvisoryId}`} className="ml-2 inline-block text-xs bg-amber-200 text-amber-900 rounded px-1.5 py-0.5 font-medium">
      AI dissent
    </span>
  );
}
```

```tsx
// AICostPill.tsx — running cost in admin header
'use client';
import { useQuery } from '@tanstack/react-query';

export function AICostPill({ bidSessionId }: { bidSessionId: string }) {
  const { data } = useQuery<{ cost_cents: number; cap_cents: number }>({
    queryKey: ['ai-cost', bidSessionId],
    queryFn: async () => (await fetch(`/api/admin/ai/cost?session_id=${bidSessionId}`, { credentials: 'include' })).json(),
    refetchInterval: 30_000,
  });
  if (!data) return null;
  const pct = data.cap_cents ? Math.round((data.cost_cents / data.cap_cents) * 100) : 0;
  const tone = pct >= 90 ? 'bg-red-700 text-white' : pct >= 60 ? 'bg-amber-100 text-amber-900' : 'bg-stone-100 text-stone-700';
  return (
    <span data-testid="ai-cost-pill" className={`text-xs rounded-full px-2 py-0.5 ${tone}`}>
      AI ${(data.cost_cents / 100).toFixed(2)} / ${(data.cap_cents / 100).toFixed(2)}
    </span>
  );
}
```

- [ ] **Step 3: Add `/api/admin/ai/cost` route**

```ts
// in apps/worker/src/routes/ai.ts
r.get('/cost', async (c) => {
  const sessionId = c.req.query('session_id');
  if (!sessionId) return c.json({ error: 'session_id_required' }, 400);
  const used = Number(await c.env.AI_KV.get('ai_cost_cents:' + sessionId) ?? 0);
  return c.json({ cost_cents: used, cap_cents: c.env.AI_BUDGET_CAP_CENTS });
});
```

- [ ] **Step 4: Wire all three into `apps/web/app/admin/bid/page.tsx` and `/admin/audit/page.tsx`** (the dissent marker reuses the same component from the audit viewer).

- [ ] **Step 5: Run E2E, expect PASS**

- [ ] **Step 6: Commit**

```bash
git add apps/web/app/admin/bid/_components/AIForecastBanner.tsx apps/web/app/admin/bid/_components/AIDissentMarker.tsx apps/web/app/admin/bid/_components/AICostPill.tsx apps/worker/src/routes/ai.ts apps/web/tests/e2e/ai-dissent-marker.spec.ts apps/web/app/admin/bid/page.tsx apps/web/app/admin/audit/page.tsx
git commit -m "feat(web): AIForecastBanner + AIDissentMarker + AICostPill + /cost endpoint"
```

---

## Task 18: Budget cap + feature-flag mute paths

**Files:**
- Modify: `apps/worker/src/routes/ai.ts` (return 503 when feature flag off or budget exceeded — partially done; consolidate)
- Test: `apps/worker/tests/routes/ai-mute-budget.test.ts`
- Test: `apps/worker/tests/routes/ai-mute-flag.test.ts`

**Goal:** consolidate the kill-switches into one helper used by all three routes (advise-current, advise-deep, forecast) so the behavior is uniform: 503 with `{ disabled: true, reason }`.

- [ ] **Step 1: Failing tests**

```ts
// apps/worker/tests/routes/ai-mute-budget.test.ts
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { Hono } from 'hono';
import adminAi from '../../src/routes/ai.js';
import { signJwt } from '../../src/lib/jwt.js';

const mkKv = () => { const s = new Map<string,string>(); return { store:s, get: vi.fn(async (k:string)=> s.get(k) ?? null), put: vi.fn(async (k:string,v:string)=>void s.set(k,v)), delete: vi.fn(), list: vi.fn(), getWithMetadata: vi.fn() }; };

let env: any, app: any;
beforeEach(() => {
  env = { ENV:'staging', CF_AI_GATEWAY_URL:'https://x/y', ANTHROPIC_API_KEY:'sk', JWT_SIGNING_KEY:'a'.repeat(32), AI_BUDGET_CAP_CENTS:1000, AI_FEATURE_FLAG_KEY:'ai_advisory_enabled', AI_KV: mkKv(), KV: mkKv(), DB: {} };
  app = new Hono<{ Bindings:any }>().route('/api/admin/ai', adminAi);
});

it('advise-current returns 503 when running cost exceeds cap', async () => {
  env.AI_KV.store.set('ai_cost_cents:sess1', '1500');
  const jwt = await signJwt({ memberId:1, role:'admin', employeeId:'1' }, env.JWT_SIGNING_KEY);
  const res = await app.request('/api/admin/ai/advise-current?session_id=sess1', { headers:{ Authorization:`Bearer ${jwt}` } }, env);
  expect(res.status).toBe(503);
  expect((await res.json()).reason).toBe('budget_exceeded');
});

it('advise-deep returns 503 when running cost exceeds cap', async () => {
  env.AI_KV.store.set('ai_cost_cents:sess1', '1500');
  const jwt = await signJwt({ memberId:1, role:'admin', employeeId:'1' }, env.JWT_SIGNING_KEY);
  const res = await app.request('/api/admin/ai/advise-deep', {
    method:'POST', headers:{ 'Content-Type':'application/json', Authorization:`Bearer ${jwt}` },
    body: JSON.stringify({ session_id:'sess1', question:'q' }),
  }, env);
  expect(res.status).toBe(503);
});
```

```ts
// apps/worker/tests/routes/ai-mute-flag.test.ts
// (analogous tests — feature flag set to 'false')
```

- [ ] **Step 2: Run tests, expect FAIL (or PARTIAL PASS — advise-current already returns 503 on flag from Task 10; consolidate)**

- [ ] **Step 3: Implement helper in `apps/worker/src/ai/gate.ts`**

```ts
// apps/worker/src/ai/gate.ts
import type { WorkerEnv } from '../types/env.js';
import { getSessionCostCents } from './cost-accounting.js';

export type GateResult =
  | { ok: true }
  | { ok: false; reason: 'feature_flag_off' | 'budget_exceeded' };

export async function checkAiGate(env: WorkerEnv, bidSessionId: string): Promise<GateResult> {
  const flag = await env.AI_KV.get(env.AI_FEATURE_FLAG_KEY);
  if (flag === 'false') return { ok: false, reason: 'feature_flag_off' };
  const used = await getSessionCostCents(env.AI_KV, bidSessionId);
  if (used >= env.AI_BUDGET_CAP_CENTS) return { ok: false, reason: 'budget_exceeded' };
  return { ok: true };
}
```

- [ ] **Step 4: Use `checkAiGate` from all three route handlers**

```ts
const gate = await checkAiGate(c.env, sessionId);
if (!gate.ok) return c.json({ disabled: true, reason: gate.reason }, 503);
```

- [ ] **Step 5: Run tests, expect PASS**

- [ ] **Step 6: Commit**

```bash
git add apps/worker/src/ai/gate.ts apps/worker/src/routes/ai.ts apps/worker/tests/routes/ai-mute-budget.test.ts apps/worker/tests/routes/ai-mute-flag.test.ts
git commit -m "feat(ai): unified gate (feature flag + budget cap) for all AI routes"
```

---

## Task 19: Eval harness — replay 2025 bid through AI

**Files:**
- Create: `apps/worker/src/ai/eval/replay-2025.ts`
- Create: `docs/ai-eval/.gitkeep` in mbfd-bid repo
- No automated test — this is an offline tool.

**Goal:** for each row in `analysis/bid_pick.csv`, ask the AI "what would you recommend?" given the roster + state at that point, and compare to the actual pick. Write `docs/ai-eval/2025-replay.md` with match rate, top dissent cases, average cost per call.

- [ ] **Step 1: Implement the script**

```ts
// apps/worker/src/ai/eval/replay-2025.ts
// Usage: ANTHROPIC_API_KEY=... CF_AI_GATEWAY_URL=... pnpm --filter @mbfd/worker tsx src/ai/eval/replay-2025.ts
//
// Reads:
//   - analysis/bid_pick.csv (244 rows of 2025 picks)
//   - analysis/personnel.csv (192 members)
//   - vendored 2025 rule book (committed under apps/worker/docs/bid-docs/2025/)
//
// For each pick:
//   1. Restore the bid state to the moment just before this pick
//   2. Call /advise-current with Sonnet
//   3. Compare top recommendation to actual pick
//   4. Accumulate match-rate, latency, cost
//
// Writes docs/ai-eval/2025-replay.md.

// (Full implementation ~250 lines — implementer fleshes out CSV parsing,
// state restoration, accumulator, and Markdown emitter. See
// docs/ai-eval/format.md for the exact report shape.)
```

- [ ] **Step 2: Add `docs/ai-eval/format.md`** describing the report schema (sections: summary stats, match-rate by phase, top-10 dissent picks with AI reasoning, cost histogram).

- [ ] **Step 3: Commit**

```bash
git add apps/worker/src/ai/eval/replay-2025.ts apps/worker/docs/bid-docs/2025/ docs/ai-eval/format.md docs/ai-eval/.gitkeep
git commit -m "feat(ai): manual eval harness — 2025 bid replay against Sonnet recommendations"
```

- [ ] **Step 4 (offline)**: run the eval, paste report into `docs/ai-eval/2025-replay.md`, commit separately.

---

## Task 20: Integration test — full /advise-current with mocked Gateway

**Files:**
- Test: `apps/worker/tests/integration/ai-full-flow.test.ts`

**Goal:** end-to-end Worker test using `wrangler unstable_dev` + mocked AI Gateway: admin JWT → GET /advise-current → returns valid envelope → ai_advisories row written → cost recorded in KV.

- [ ] **Step 1: Write failing integration test**

```ts
// apps/worker/tests/integration/ai-full-flow.test.ts
import { describe, it, expect, beforeAll, afterAll, vi } from 'vitest';
import { unstable_dev, type UnstableDevWorker } from 'wrangler';
import canonical from '../ai/__fixtures__/advisory-canonical.json';

let worker: UnstableDevWorker;
const realFetch = globalThis.fetch;

beforeAll(async () => {
  worker = await unstable_dev('src/index.ts', {
    experimental: { disableExperimentalWarning: true },
    local: true,
    env: 'staging',
  });
});
afterAll(async () => { await worker?.stop(); globalThis.fetch = realFetch; });

it('GET /api/admin/ai/advise-current returns canonical envelope (mocked Gateway)', async () => {
  // Stub the Anthropic outbound — done via msw or by overriding fetch within the worker bundle.
  // For miniflare-based tests, see https://developers.cloudflare.com/workers/testing/ for the
  // service-binding mock pattern; here we use a env-injected base URL pointed at a local responder.
  // ... full setup elided; implementer fleshes out per existing test patterns ...
});
```

Implementer note: integration tests on a Worker that does outbound `fetch` require either MSW or a local responder. The existing pattern in `apps/worker/tests/integration/auth.test.ts` (Plan 01) shows the structure. Reuse it.

- [ ] **Step 2: Run, iterate, PASS**

- [ ] **Step 3: Commit**

```bash
git add apps/worker/tests/integration/ai-full-flow.test.ts
git commit -m "test(ai): integration test for /advise-current happy path"
```

---

## Task 21: Apply migration 0005 to staging + production

**Files:**
- (no code — operations step)

- [ ] **Step 1: Local apply**

```
cd apps/worker && pnpm db:migrate:local
```

- [ ] **Step 2: Remote staging apply**

```
cd apps/worker && pnpm db:migrate:remote
```

- [ ] **Step 3: After Plan 06 staging soak passes, apply to production** when the team coordinates the cutover window.

---

## Task 22: STATUS.md update + Plan 06 sign-off

**Files:**
- Modify: `docs/superpowers/plans/STATUS.md` (in `MBFD_Hub` repo)

- [ ] Append:

```
## Plan 06 — AI Integration (complete YYYY-MM-DD)

- /api/admin/ai/advise-current (Sonnet) live; <2 s p95 in staging
- /api/admin/ai/advise-deep (Opus, SSE) live
- /api/admin/ai/forecast cron-refreshed every 10 min when live
- Dissent log writer integrated with Plan 05 force-pick (commit XYZ)
- AI_KV namespace bound; CF_AI_GATEWAY_URL set; ANTHROPIC_API_KEY secret set
- 2025 eval-replay match-rate: <pasted from docs/ai-eval/2025-replay.md>
- Cost monitoring tile reading running cost from AI_KV
- Feature flag ai_advisory_enabled defaults to "true" in staging
```

- [ ] Commit in MBFD_Hub repo.

---

## Acceptance criteria

- [ ] `/api/admin/ai/advise-current` returns a valid `AdvisoryEnvelope` JSON in <2 s p95 in staging when called with a real admin JWT
- [ ] `/api/admin/ai/advise-deep` streams `text/event-stream` with token deltas; full response <8 s p95
- [ ] `/api/admin/ai/forecast` returns the cached envelope; 404 when none yet; cache TTL 30 s in client
- [ ] Cache breakpoints visible in the Anthropic response usage: by the second call in a session, `cache_read_input_tokens` ≥ 90 % of `input_tokens + cache_read_input_tokens`
- [ ] AI returns malformed JSON → `parseAdvisoryFromText` returns null → route returns deterministic-fallback envelope with `stale: true, fallback: 'deterministic'`; admin panel keeps rendering
- [ ] Force-pick by admin with no AI recommendation match → `dissent` audit row written within 200 ms of the force-pick commit
- [ ] Feature flag `ai_advisory_enabled = 'false'` → all three AI routes return 503 with `disabled: true`
- [ ] Running cost in AI_KV ≥ cap → all three routes return 503 with `reason: 'budget_exceeded'`
- [ ] Eligibility decisions never originate from an AI call (code review confirms: no `Anthropic` import inside `packages/eligibility` or its callers)
- [ ] AI calls always go through `CF_AI_GATEWAY_URL`; no direct `api.anthropic.com` reference anywhere in the worker source
- [ ] Estimated cost per full 2026 bid event ≤ $15 (eval-harness output) and per-session cap defaults to $25
- [ ] CI green: lint, typecheck, unit (Vitest), integration (Vitest+Miniflare), E2E (Playwright); coverage 80 % lines / 80 % branches in `apps/worker/src/ai/**`
- [ ] No emojis introduced into source files
- [ ] Build-time codegen (`pnpm --filter @mbfd/worker ai:codegen`) runs cleanly in CI and produces a bundled rulebook string

---

## Rollback procedure

If the AI panel starts producing degraded results or is implicated in a production incident, follow these steps in order. **Do NOT roll back the migration** — `0005_audit_dissent_action.sql` is additive (just an enum extension) and reverting risks data loss.

### Level 1 — Mute the panel (no code deploy required)

```
wrangler kv:key put --binding=AI_KV ai_advisory_enabled false --env production
```

Effect: all three `/api/admin/ai/*` routes return 503; the panel renders "AI muted"; bid mechanics continue at full speed. The dissent writer also no-ops.

### Level 2 — Pin model to Haiku 4.5 as cheap-fast fallback

If Sonnet is the issue but you still want SOME advisory:

```
wrangler kv:key put --binding=AI_KV ai_model_override claude-haiku-4-5 --env production
```

(Requires a 5-minute code change to read `ai_model_override` in `client.ts`'s `adviseCurrent` and pass it through. Pre-built but not enabled in Plan 06.)

### Level 3 — Hard rollback to prior Worker version

```
wrangler rollback --env production
```

Cloudflare keeps the previous 5 Worker versions; this re-points the production route to the version that pre-dated Plan 06. The frontend bundles still reference the AI panel but the requests will 404 — the panel renders its empty/error state per the impeccable rules, and the rest of `/admin/bid` is unaffected.

### Level 4 — Revoke the Anthropic API key

If we suspect credential compromise:

```
wrangler secret delete ANTHROPIC_API_KEY --env production
wrangler deploy --env production
```

Effect: every AI call fails with auth error, falls through to the deterministic fallback. Same effective UX as Level 1 but no cached `last_good` reads — admin sees `fallback: 'deterministic'` envelopes everywhere.

### Recovery after rollback

1. Open a postmortem issue with the specific failure mode (latency, hallucination, cost spike, etc.)
2. Reproduce in staging with the same bid state
3. Patch + redeploy to staging; run the eval harness; verify match-rate against 2025 actuals
4. Re-enable production once metrics return to baseline

---

## Notes for the engineer

- **Always validate Claude's JSON with Zod.** The model occasionally returns text wrapped in ```json fences, prefixed by prose, or truncated. `parseAdvisoryFromText` covers all three cases — do not bypass it.
- **Cache breakpoints matter.** The breakpoint goes AFTER the system block and AFTER the user-roster block. If a future task adds a per-call piece of context (e.g., the bidder's photo) the breakpoint stays where it is — uncached delta is the right place.
- **Latency budget**: Sonnet hot path includes prompt cache lookup + ~800 ms generation. If you exceed 2 s in staging, the first thing to check is whether the cache is actually warming up — Anthropic's usage response includes `cache_read_input_tokens`; if it's 0 on the second call in a session, the breakpoint is misplaced.
- **The AI sees the deterministic eligibility struct** — it does NOT need to re-evaluate rules. If you find yourself adding "the AI computes eligibility" logic, stop and re-read spec §11.2.
- **Be very specific in the system prompt** that the AI's role is **explanation + forecast**, NOT decision. The current PREAMBLE in `system-2026.ts` says this twice; do not soften it.
- **Cost monitoring is not optional.** The AI_KV running total is the canary; the cost pill in the admin header surfaces it for the chief in-real-time. If a future task adds more frequent AI calls (e.g., per-second forecast), reassess the cap.
- **The eval harness is the closest thing we have to a regression test for AI behavior.** Run it before each Anthropic model version bump. Cache it under `docs/ai-eval/{date}-{model-id}.md`.
- **Secrets handling**: `ANTHROPIC_API_KEY` is a Wrangler secret. Never commit it to `wrangler.toml`. The Cloudflare AI Gateway URL is non-secret (it's in `vars`).
- **Multi-day sessions** (spec §11.7): when admin runs `day-end`, the on-deck pre-fetch should NOT fire; gate `warmCacheForOnDeck` on `phase !== 'paused'`. The current `loadTurnStateForSession` returns the phase; pass that through. Add a unit test for this edge if Plan 04's DO advancement hook fires on pause (it shouldn't, but belt-and-braces).
- **Plan 05 wiring**: the dissent writer in this plan is decoupled from the force-pick handler. The actual call to `recordDissentIfNeeded(env, …)` lives in Plan 05's `/force-pick` endpoint. If Plan 05 has not landed yet, leave a TODO in the `audit.ts` action enum and revisit once Plan 05 merges.
- **AI Gateway logging**: visit https://dash.cloudflare.com/ → AI → mbfd-bid → Logs to inspect every call. Useful for debugging cache misses, rate-limit hits, and parse failures.
- **Streaming on Workers**: SSE works fine through Cloudflare's edge but the connection is single-use; if the admin closes the dialog the upstream Anthropic stream needs to be cancelled. The current implementation uses an AbortController in the route; do not remove it.
