# MBFD Bid Web App — Master Implementation Plan Index

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement each plan task-by-task. Each sub-plan is independently executable.

**Goal:** Deliver a multi-year, fantasy-draft-style bid web app for the Miami Beach Fire Department, hosted on Cloudflare, with member self-service bidding, admin live control, AI advisory, full audit trail, and write-back to the existing employee portal.

**Spec:** [`docs/superpowers/specs/2026-05-17-mbfd-bid-webapp-design.md`](../specs/2026-05-17-mbfd-bid-webapp-design.md)

**Tech Stack** (locked in spec §4):
Next.js 15 App Router · React 19 · TypeScript · TailwindCSS · shadcn/ui · Zustand · TanStack Query · Hono · Drizzle ORM · Cloudflare Pages · Workers · Durable Objects · D1 · R2 · KV · Queues · AI Gateway → Anthropic Claude (Sonnet 4.6 + Opus 4.7) · Zod · Vitest · Playwright

**Design system:** `.impeccable.md` (Red-700 brand, Slate-850 admin dark, Stone-* warm neutrals, Plus Jakarta Sans + Source Sans 3 + JetBrains Mono, tabular-nums, professional easing) — treated as compile-time enforced.

---

## Why this is split into 9 plans

The spec covers 9 independently testable subsystems. Each plan produces working, deployable software on its own and is independently reviewable, executable, and shippable.

Per the writing-plans skill: **"Each plan should produce working, testable software on its own."**

---

## The 9 plans

| # | Plan | Phase in spec | Status | Goal |
|---|------|---------------|--------|------|
| 01 | **Foundation** — repo, deploy, PIN gate, auth | Build Phase 0 + 1 | ✅ **detailed** — start here | Deployable site at staging.bid.mbfdhub.com that gates by PIN, calls portal `/verify-credentials`, lands on a placeholder `/lobby` |
| 02 | **Data plane** — schema, imports, viewers | Build Phase 2 | 📋 high-level | D1 schema + member/cert CSV imports + read-only member/position/rule viewers |
| 03 | **Eligibility engine** — rules, points, tie-break | Build Phase 3 | 📋 high-level | Deterministic TypeScript evaluator + 100% covered golden tests against 2025 actuals |
| 04 | **Live bid core** — DO, WebSocket, draft UI | Build Phase 4 | 📋 high-level | Members can submit picks in a live event; UI matches spec §9 mockups |
| 05 | **Admin console** — pause/resume/force/skip/admin-bid/day-end/day-start | Build Phase 5 | 📋 high-level | Chiefs can run the bid live; step-up auth on every write; full audit log viewer |
| 06 | **AI integration** — advisory, forecast, dissent log | Build Phase 6 | 📋 high-level | Sonnet hot path + Opus deep dive, prompt-cached rulebook+roster, on-deck pre-fetch |
| 07 | **A-Day Phase 2** — Group/weekday picker | Build Phase 7 | 📋 high-level | Phase 2 sequential A-Day bid with group capacity invariants |
| 08 | **Audit, exports, portal write-back** | Build Phase 8 | 📋 high-level | R2 hash-chained JSONL audit + PDFs + Cloudflare Queue → employee portal action card |
| 09 | **Hardening & rehearsal** — pen test, load test, drill | Build Phase 9 | 📋 high-level | All P0/P1 issues closed, staging "kill the DO" drill green |

Plan 10 (live event day) is operational, not a build plan.

---

## Execution order

These are largely sequential by data dependency:

```
[01 Foundation] ──► [02 Data plane] ──► [03 Eligibility] ──► [04 Live bid core]
                                                                    │
                                  ┌─────────────────────────────────┤
                                  ▼                                 ▼
                          [05 Admin console]               [06 AI integration]
                                  │                                 │
                                  └────────────┬────────────────────┘
                                               ▼
                                       [07 A-Day Phase 2]
                                               │
                                               ▼
                                  [08 Audit/Exports/Portal]
                                               │
                                               ▼
                                  [09 Hardening & rehearsal]
                                               │
                                               ▼
                                  ▶ Live event day
```

Plans **05 and 06 can run in parallel** if you have two developers (admin UI lives in `/admin/*`, AI lives in worker + side panel — minimal collision).

---

## How to use this index

1. **Start by opening Plan 01** ([`2026-05-17-mbfd-bid-plan-01-foundation.md`](2026-05-17-mbfd-bid-plan-01-foundation.md)).
2. Each task in Plan 01 has full TDD detail (write test → fail → impl → pass → commit).
3. When Plan 01 is complete and deployed, ping me — I'll write Plan 02 in full detail.
4. Plans 02–09 currently exist as high-level task lists; full TDD detail comes just-in-time. This is intentional: the user has signaled "more info coming soon" and just-in-time detailing lets us absorb late requirements without rewriting plans we haven't yet executed.

---

## Cross-cutting standards every plan honors

These apply to **every** task in every plan. Reviewers reject PRs that violate any of them.

### Code quality
- **TDD**: Test → Fail → Implement → Pass → Refactor → Commit. Every step.
- **Type safety**: `strict: true`, `noUncheckedIndexedAccess: true` in `tsconfig.base.json`. No `any`. No `@ts-ignore` without a tracking issue.
- **Zod-first**: Every API boundary (request body, response, env var) validated by Zod.
- **DRY/YAGNI**: Build only what the current spec section requires.
- **Immutability**: Default to `readonly` types and structuralClone for nested state. No `mutateInPlace` outside hot paths.
- **Small files**: 200–400 lines target, 800 max. Split by responsibility.

### UI (impeccable rules)
- All Critical Rules from `.impeccable.md` Table 13 enforced via Tailwind CSS lint rules (custom plugin) — violations fail CI.
- **Red-700** is the only red. **No cold grays** — Stone-* only. Numeric data has `font-variant-numeric: tabular-nums`. Touch targets ≥ 44×44px. `prefers-reduced-motion` gates every transition.
- Plus Jakarta Sans (headings), Source Sans 3 (body), JetBrains Mono (IDs) — variable fonts via Fontsource (self-hosted, no Google Fonts CDN at runtime).

### Tech stack best practices
- **React Server Components first**. Mark `'use client'` only when needed (Zustand, WebSocket, interactivity).
- **Edge runtime by default** on Next.js routes. Node runtime only when a npm package forces it.
- **Suspense + streaming** for above-the-fold data. Loading UI via `loading.tsx`.
- **`use server`** actions for non-RT mutations (rule edits, imports). Worker REST for everything WS-adjacent.
- **Drizzle migrations** versioned in `apps/worker/migrations/`. Never edit a migration after merge.
- **Hono middleware order**: cors → pin-gate → auth → role-check → handler.
- **WebSocket protocol versioned**: every message has `v: number`; mismatched clients see `RESYNC`.

### Security (spec §12)
- Secrets only via Wrangler (`wrangler secret put NAME`). Never in `wrangler.toml`. Never in source. CI uses GitHub Actions secrets.
- JWT 8h TTL, HS256, `fresh_auth_at` claim. Step-up re-auth on admin writes.
- All admin writes pass through `requireStepUpAuth(req, maxAgeSec = 300)` middleware.
- Idempotency keys on every state-changing client request; replay returns last result.
- CSRF: SameSite=strict cookies + double-submit token on `POST`.
- Rate limits per-IP and per-member, configurable via KV.

### Observability
- Every Worker handler emits a structured log line with `traceId`, `userId`, `route`, `latencyMs`, `outcome`.
- Cloudflare Logpush → R2 bucket `mbfd-bid-logs` (kept 90 days).
- AI calls log `model`, `prompt_hash`, `latency_ms`, `cost_cents`, `cache_hit_ratio`.

### Testing pyramid
- **Unit** (Vitest): every pure function, every reducer, every Zod schema.
- **Integration** (Vitest + Miniflare): every Worker route with mocked D1/R2/KV.
- **E2E** (Playwright): one happy-path test per user journey (PIN → login → pick).
- **Coverage gate**: 80% lines, 80% branches in CI. Failing coverage blocks merge.

### Git hygiene
- Conventional commits: `feat:`, `fix:`, `refactor:`, `docs:`, `test:`, `chore:`, `perf:`, `ci:`.
- One concern per commit. Bundles are rejected at review.
- Branch protection on `main`: require PR, passing CI, 1 review minimum, linear history.

### Cloudflare project layout (decided in Plan 01)
- New subdirectory inside this repo: `MBFD_Hub/bid-app/`
- Monorepo with pnpm workspaces:
  - `bid-app/apps/web/` — Next.js 15 (Cloudflare Pages)
  - `bid-app/apps/worker/` — Hono Worker + Durable Object
  - `bid-app/packages/shared/` — Zod schemas, TypeScript types, constants
- Single `bid-app/wrangler.toml` at app root; Pages deploys from `apps/web/.vercel/output`.

---

## Open items that affect later plans (not blocking Plan 01)

These should be answered during the build, before they become a Plan 4+ blocker. Default behavior is in spec §14.

- D2 Dual-chief approval mode default — **default off** (spec)
- D3 Public read-only viewer during event — **off in v1**
- D6 Auto-place probationaries / SWAT medic / paramedic students — **via lock-position admin action**
- D7 Loss of phone-and-phone-tree backup — **same as D1 resolution: pause-first**
- D8 AI advisory always-on vs admin-toggle — **always-on; mute in UI**
- D9 Credential PDF re-parse cadence — **per session start (admin click)**
- D10 Bid year clone — **clone 2025 with §13 deltas applied**

---

## Status updates expected from the implementing engineer

After each plan completes, write a 1-paragraph "Plan N complete" note in `docs/superpowers/plans/STATUS.md` (create if absent) noting:
- Plan number + title
- Date completed
- Anything that deviated from the plan and why
- Anything Plans N+1+ should account for that wasn't anticipated
