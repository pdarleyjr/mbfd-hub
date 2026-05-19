# Plan 09 — Hardening & production cutover

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close every Critical and High severity finding from the security/code review; stand up isolated production Cloudflare resources (D1, KV, R2, Pages, Worker) on `bid.mbfdhub.com` and `api.bid.mbfdhub.com`; complete a rehearsal-day dry run; perform a clean cutover with a tested, written rollback path; monitor for 24h post-cutover.

**Architecture:** Not feature work — this is a hardening + cutover sprint. Inputs: a fully implemented and staging-deployed Plans 01–08 stack, plus the source-of-truth CSVs in `D:\GitHub_Repos\MBFD_Hub\analysis\` and `D:\MBFD\Bid\2026 Bid Documents\`. Outputs: hardened code + new prod resources + signed-off cutover + rollback playbook. Production is a *parallel* environment alongside staging — staging stays up for ongoing test work, prod is the live event surface.

**Tech stack (no new runtime deps; ops-heavy):** `wrangler` 3.x · `gh` CLI · `pnpm 9` · `playwright` (multi-context load) · `openssl` (key gen) · Cloudflare API · `dig` / `curl` · GitHub Actions secrets.

**Dependencies:** Plans 01 (foundation), 02 (data plane), 03 (eligibility), 04 (live bid DO), 05 (admin console), 06 (AI), 07 (A-Day), 08 (audit/exports/portal) must all be merged, deployed to staging, and have green CI before this plan begins.

---

## Decisions preamble (encode these before any task runs)

These are the load-bearing decisions for Plan 09. Every task respects them.

### D1 — Production lives parallel to staging, not on top of it

`staging.bid.mbfdhub.com` and `api.staging.bid.mbfdhub.com` stay alive throughout the live event for emergency triage and dry-runs. Production gets new resource IDs, new secrets, new GitHub Actions environment. We never re-point staging IDs at prod data.

### D2 — DNS / TLS — 4th-level subdomains need dedicated certs

`bid.mbfdhub.com` and `api.bid.mbfdhub.com` are 3rd-level hosts (under `mbfdhub.com`) so they are covered by `mbfdhub.com`'s Universal SSL wildcard for *.mbfdhub.com only if Universal SSL is upgraded to an Advanced Cert. **Cloudflare's default Universal SSL covers `*.mbfdhub.com` and `mbfdhub.com` but NOT `*.*.mbfdhub.com`**, so any 4th-level (`api.staging.bid.mbfdhub.com`, `api.bid.mbfdhub.com` IS 3rd-level under bid.mbfdhub.com which is itself 3rd-level — count it as 4th-level from the apex `mbfdhub.com`).

We rely on Cloudflare's auto-provisioned dedicated certs that ship with Workers Custom Domains and Pages Custom Domains. Both require:

1. `wrangler [pages] domain add` (or the equivalent Workers route with `custom_domain = true`)
2. Wait for cert issuance to complete (`STATUS: active`) before any production traffic
3. **Never** use the legacy `route = { pattern = "...", zone_name = "..." }` shape — it relies on Universal SSL and silently fails on 4th-level hosts

### D3 — Secret rotation cadence

- **Pre-cutover:** rotate ALL prod secrets so they differ from staging. Different `JWT_SIGNING_KEY`, different `BCRYPT_PEPPER` / `PIN_HASH`, different `AUDIT_SIGNING_PRIVKEY`, different `PORTAL_BID_*` service-account tokens issued by the portal team.
- **Post-event:** rotate `JWT_SIGNING_KEY`, `PIN_HASH`, and `AUDIT_SIGNING_PRIVKEY` again within 7 days.
- **On suspected compromise:** rotate within 1 hour; document in post-mortem.

### D4 — Backup retention

`wrangler d1 export` runs every 6 hours during the bid week, every 24h otherwise. Snapshots are written to `mbfd-bid-prod-backups/d1/<YYYY-MM-DD>/<HHMM>.sql`. Retention: 90 days. Restore is tested end-to-end against a throwaway D1 in Phase A so we know it works *before* we need it.

### D5 — Secrets never touch the repo or the conversation transcript

All raw secret values are produced by `openssl` in `mkdtemp` directories *outside* the repo, piped into `wrangler secret put` / `wrangler pages secret put` over stdin, and the temp file deleted in the same line. The plan **only** references secrets by name. If a value MUST be displayed to the operator, it is displayed via `Get-Random` once at the terminal and never echoed back.

### D6 — Pages secret deploy lag

`wrangler pages secret put NAME --project-name=mbfd-bid-web-prod` updates the secret store but does **not** apply to running deployments. Pages picks up the new value on the **next** deploy. Every task that sets a Pages secret therefore includes an explicit `wrangler pages deploy` step right after.

### D7 — Worker domain shape

Worker custom domains use `[[routes]]` with `custom_domain = true`. Pages custom domains are attached via `wrangler pages domain add` or the dashboard. We do not use legacy zone-routed Worker routes.

### D8 — No re-platforming

The web app stays on `@cloudflare/next-on-pages`. **Do not** migrate to `@opennextjs/cloudflare` mid-cutover — that path was evaluated and abandoned.

### D9 — Code freeze window

From the start of Phase E (cutover) through 24h post-cutover, no merges to `main` except for emergency hotfixes signed off by the operations chief. Hotfixes deploy to staging first, then go through the cutover replay path.

---

## File map (created or modified in this plan)

```
MBFD_Hub/bid-app/
├── apps/worker/
│   ├── wrangler.toml                                ← MODIFY: fill prod D1/KV ids, add prod routes
│   ├── src/
│   │   ├── middleware/
│   │   │   ├── rate-limit.ts                        ← NEW: per-IP + per-member KV sliding window
│   │   │   └── security-headers.ts                  ← NEW: CSP, HSTS, X-Frame-Options on /api/* responses
│   │   ├── routes/
│   │   │   └── admin/
│   │   │       └── health.ts                        ← NEW: /admin/health/prod-canary
│   │   └── scheduled.ts                             ← MODIFY: add D1 backup cron handler
│   └── tests/
│       ├── middleware/
│       │   ├── rate-limit.test.ts
│       │   └── security-headers.test.ts
│       └── integration/
│           └── d1-backup-restore.test.ts
├── apps/web/
│   ├── public/_headers                              ← NEW: CSP + security headers for Pages assets
│   ├── wrangler.jsonc                               ← MODIFY: add prod project name
│   └── tests/
│       └── load/
│           ├── playwright.load.config.ts
│           └── 100-concurrent-picks.spec.ts
├── scripts/
│   ├── seed-prod.ts                                 ← NEW: idempotent prod seed (members, positions, rules)
│   ├── rotate-prod-secrets.ps1                      ← NEW: one-shot secret rotation runbook
│   ├── d1-backup.ps1                                ← NEW: invoked by GH Actions cron
│   ├── d1-restore.ps1                               ← NEW: invoked manually during rollback
│   └── cutover-smoke.ps1                            ← NEW: 200-status check across all surfaces
├── .github/workflows/
│   ├── ci.yml                                       ← MODIFY: gate merge on E2E green
│   ├── deploy-staging.yml                           ← MODIFY: unchanged contract, just verified
│   ├── deploy-production.yml                        ← NEW: manual workflow_dispatch only
│   ├── d1-backup.yml                                ← NEW: cron every 6h during bid week
│   ├── dependency-audit.yml                         ← NEW: weekly pnpm audit
│   └── e2e-merge-gate.yml                           ← NEW: required check on main PRs
└── docs/
    ├── cutover-runbook.md                           ← NEW: ordered runbook for A-Day
    ├── rollback-runbook.md                          ← NEW: ordered runbook for restore-to-staging
    └── post-cutover-monitoring.md                   ← NEW: 24h watch list with dashboards/queries
```

---

## Source data reference

| File | Use in this plan |
|------|------------------|
| `D:\GitHub_Repos\MBFD_Hub\analysis\personnel.csv` | Prod seed: 238-row 2025 member roster (still the source-of-truth until 2026 export drops) |
| `D:\GitHub_Repos\MBFD_Hub\analysis\positions.csv` | Prod seed: clone target for the 2026 template |
| `D:\GitHub_Repos\MBFD_Hub\analysis\rules_points.csv` | Prod seed: rule book v2026.1 baseline |
| `D:\MBFD\Bid\2026 Bid Documents\2026_DELTA_from_2025.md` | Prod seed: deltas applied on top of the 2025 baseline |
| `D:\MBFD\Bid\2026 Bid Documents\2026_Position_Template.md` | Prod seed: authoritative 2026 position template |
| `D:\MBFD\Bid\2026 Bid Documents\2026_Rules_and_Points.md` | Prod seed: authoritative 2026 rules |
| `D:\MBFD\Bid\2026 Bid Documents\2026_Bid_Process.md` | Cutover decisions (A-Day mode, timers, exclusion list) |

---

# Phase A — Security & reliability hardening

These tasks land *before* a single production resource is provisioned. Goal: prove the staging surface is hardened, then clone the hardened code to prod.

---

## Task 1: Wrangler tail PII audit

**Goal:** Confirm that no Worker log line contains raw PII (employee ID, full name, raw IP, raw credential PDF text) under normal traffic.

**Files:**
- Modify: `apps/worker/src/lib/logger.ts` (if it exists; create if not — emit only `traceId`, `userId` ULID, `route`, `latencyMs`, `outcome`)
- Create: `docs/wrangler-tail-audit.md` (the audit checklist + findings)

- [ ] **Step 1: Run wrangler tail against staging for 10 minutes during a simulated test session**

```powershell
cd D:\GitHub_Repos\mbfd-bid\apps\worker
pnpm exec wrangler tail --env staging --format json | Tee-Object -FilePath ..\..\tail-staging-$(Get-Date -Format yyyyMMdd-HHmm).jsonl
```

In a second terminal, drive a happy-path login + lobby + pick via the staging UI (PIN 2300 → real test credentials → submit one pick).

- [ ] **Step 2: Grep the captured file for forbidden tokens**

```powershell
$tail = Get-Content ..\..\tail-staging-*.jsonl
# Must return ZERO hits:
$tail | Select-String -Pattern '\b\d{5}\b'              # 5-digit employee IDs
$tail | Select-String -Pattern '\d{1,3}(\.\d{1,3}){3}'  # raw IPv4
$tail | Select-String -Pattern 'PIN.*\d{4}'             # PIN in logs
$tail | Select-String -Pattern 'Authorization|Bearer'   # auth header leak
```

Expected: every command emits zero matches.

- [ ] **Step 3: If any match, identify the emitting `console.log`/`logger.info` call and replace with redacted form**

Example redaction:

```ts
// apps/worker/src/lib/logger.ts
import { ulid } from 'ulidx';
const traceId = ulid();
console.log(JSON.stringify({ traceId, userId: ctx.userUlid, route: req.url.pathname, latencyMs, outcome }));
// Never log: req.headers, body, employee_id, ip, credentials
```

- [ ] **Step 4: Re-run wrangler tail and verify clean**

Repeat step 1 and step 2. All four greps must return zero hits.

- [ ] **Step 5: Commit**

```bash
git add apps/worker/src/lib/logger.ts docs/wrangler-tail-audit.md
git commit -m "fix(worker): redact PII from structured logs; add tail audit log"
```

**Rollback if fails:** revert the logger.ts change; the prior implementation continues to emit whatever it did before.

---

## Task 2: CodeQL high-severity triage

**Goal:** Every Critical and High severity CodeQL alert on `main` is either fixed or closed with a documented suppression reason.

**Files:**
- Modify: source files flagged by CodeQL
- Create: `docs/codeql-triage.md` (one row per closed alert)

- [ ] **Step 1: Pull current CodeQL alerts via gh CLI**

```powershell
gh api -X GET "repos/$(gh repo view --json nameWithOwner -q .nameWithOwner)/code-scanning/alerts" `
  -F state=open -F severity=critical,high `
  --paginate > codeql-open-alerts.json
```

- [ ] **Step 2: For each alert, decide: fix, dismiss with reason, or transfer to backlog**

Allowed dismiss reasons in this plan: `false positive` (must have inline justification), `won't fix - test fixture`, `won't fix - tracked in issue #N`. **No** dismissals without a written reason.

```powershell
$alerts = Get-Content codeql-open-alerts.json | ConvertFrom-Json
foreach ($a in $alerts) {
  Write-Host "Alert $($a.number): $($a.rule.id) at $($a.most_recent_instance.location.path):$($a.most_recent_instance.location.start_line)"
}
```

- [ ] **Step 3: Fix the fixable ones; dismiss the rest with `gh api`**

```powershell
# Fix a real issue: edit the file, commit, push; CodeQL re-runs on the PR
# Dismiss a false positive:
gh api -X PATCH "repos/$(gh repo view --json nameWithOwner -q .nameWithOwner)/code-scanning/alerts/<NUMBER>" `
  -F state=dismissed -F dismissed_reason="false positive" `
  -F dismissed_comment="Justified inline; this is test fixture data, not exploited at runtime."
```

- [ ] **Step 4: Re-run CodeQL workflow to confirm clean**

```powershell
gh workflow run codeql.yml --ref main
gh run watch
```

Expected: workflow green, zero new Critical/High alerts.

- [ ] **Step 5: Commit the triage log**

```bash
git add docs/codeql-triage.md
git commit -m "docs(security): codeql high-severity triage for prod readiness"
```

**Rollback if fails:** no rollback needed — the triage log is doc-only; the code fixes are independent commits each with their own rollback.

---

## Task 3: Rate limit middleware on /auth/* (KV-backed sliding window)

**Goal:** Per-IP and per-employee-id rate limits on `/auth/login`, `/auth/refresh`, `/auth/step-up`. Defaults: 5 attempts per IP per minute, 10 attempts per employee_id per 15 minutes.

**Files:**
- Create: `apps/worker/src/middleware/rate-limit.ts`
- Create: `apps/worker/tests/middleware/rate-limit.test.ts`
- Modify: `apps/worker/src/routes/auth.ts` (apply middleware)

- [ ] **Step 1: Write the failing test**

```ts
// apps/worker/tests/middleware/rate-limit.test.ts
import { describe, it, expect, beforeEach } from 'vitest';
import { Miniflare } from 'miniflare';
import { rateLimitByIp } from '../../src/middleware/rate-limit';

describe('rateLimitByIp', () => {
  let mf: Miniflare;
  beforeEach(async () => {
    mf = new Miniflare({ modules: true, script: 'export default { fetch() { return new Response("ok"); } };', kvNamespaces: ['KV'] });
  });

  it('allows the first 5 requests in a minute', async () => {
    const kv = await mf.getKVNamespace('KV');
    for (let i = 0; i < 5; i++) {
      const result = await rateLimitByIp(kv, '1.2.3.4', 5, 60);
      expect(result.allowed).toBe(true);
    }
  });

  it('blocks the 6th request in a minute', async () => {
    const kv = await mf.getKVNamespace('KV');
    for (let i = 0; i < 5; i++) await rateLimitByIp(kv, '1.2.3.4', 5, 60);
    const result = await rateLimitByIp(kv, '1.2.3.4', 5, 60);
    expect(result.allowed).toBe(false);
    expect(result.retryAfterSec).toBeGreaterThan(0);
  });
});
```

- [ ] **Step 2: Run and confirm FAIL**

```powershell
cd D:\GitHub_Repos\mbfd-bid\apps\worker
pnpm test -- rate-limit.test.ts
```

Expected: cannot resolve `../../src/middleware/rate-limit`.

- [ ] **Step 3: Implement `apps/worker/src/middleware/rate-limit.ts`**

```ts
import { createHash } from 'node:crypto';

export type RateLimitResult = { allowed: boolean; retryAfterSec: number; remaining: number };

function hashKey(prefix: string, identifier: string): string {
  const h = createHash('sha256').update(identifier).digest('hex').slice(0, 16);
  return `rl:${prefix}:${h}`;
}

export async function slidingWindow(
  kv: KVNamespace,
  key: string,
  limit: number,
  windowSec: number,
): Promise<RateLimitResult> {
  const now = Math.floor(Date.now() / 1000);
  const raw = await kv.get(key);
  const timestamps: number[] = raw ? JSON.parse(raw) : [];
  const cutoff = now - windowSec;
  const recent = timestamps.filter((t) => t > cutoff);
  if (recent.length >= limit) {
    const oldest = recent[0] ?? now;
    return { allowed: false, retryAfterSec: Math.max(1, oldest + windowSec - now), remaining: 0 };
  }
  recent.push(now);
  await kv.put(key, JSON.stringify(recent), { expirationTtl: windowSec + 5 });
  return { allowed: true, retryAfterSec: 0, remaining: limit - recent.length };
}

export async function rateLimitByIp(
  kv: KVNamespace, ip: string, limit = 5, windowSec = 60,
): Promise<RateLimitResult> {
  return slidingWindow(kv, hashKey('ip', ip), limit, windowSec);
}

export async function rateLimitByEmployeeId(
  kv: KVNamespace, employeeId: string, limit = 10, windowSec = 900,
): Promise<RateLimitResult> {
  return slidingWindow(kv, hashKey('emp', employeeId), limit, windowSec);
}
```

- [ ] **Step 4: Run test, expect PASS**

```powershell
pnpm test -- rate-limit.test.ts
```

- [ ] **Step 5: Wire into `apps/worker/src/routes/auth.ts`**

```ts
// at top of the /auth/login handler:
import { rateLimitByIp, rateLimitByEmployeeId } from '../middleware/rate-limit';

const ip = c.req.header('cf-connecting-ip') ?? 'unknown';
const ipCheck = await rateLimitByIp(c.env.KV, ip);
if (!ipCheck.allowed) {
  return c.json({ error: 'rate_limited' }, 429, { 'Retry-After': String(ipCheck.retryAfterSec) });
}
const body = await c.req.json();
const empCheck = await rateLimitByEmployeeId(c.env.KV, body.employee_id);
if (!empCheck.allowed) {
  return c.json({ error: 'rate_limited' }, 429, { 'Retry-After': String(empCheck.retryAfterSec) });
}
```

- [ ] **Step 6: Integration test — 6 rapid /auth/login attempts to staging from one IP**

```powershell
for ($i = 1; $i -le 6; $i++) {
  curl -X POST https://api.staging.bid.mbfdhub.com/auth/login `
    -H "Content-Type: application/json" `
    -d '{"employee_id":"00000","password":"wrong"}' `
    -w "`nHTTP %{http_code}`n"
}
```

Expected: first 5 return 401 (bad creds), 6th returns 429 with `Retry-After` header.

- [ ] **Step 7: Commit**

```bash
git add apps/worker/src/middleware/rate-limit.ts apps/worker/src/routes/auth.ts apps/worker/tests/middleware/rate-limit.test.ts
git commit -m "feat(worker): KV-backed sliding-window rate limit on /auth/*"
```

**Rollback if fails:** revert the auth.ts changes; the middleware file can stay (it's inert without the wire-in).

---

## Task 4: CSP and security headers (Worker and Pages)

**Goal:** Every response (Worker API and Pages assets) carries CSP, HSTS, X-Content-Type-Options, X-Frame-Options, Referrer-Policy, and Permissions-Policy.

**Files:**
- Create: `apps/worker/src/middleware/security-headers.ts`
- Create: `apps/worker/tests/middleware/security-headers.test.ts`
- Modify: `apps/worker/src/index.ts` (wire global middleware)
- Create: `apps/web/public/_headers`

- [ ] **Step 1: Write the failing test**

```ts
// apps/worker/tests/middleware/security-headers.test.ts
import { describe, it, expect } from 'vitest';
import { applySecurityHeaders } from '../../src/middleware/security-headers';

describe('applySecurityHeaders', () => {
  it('sets the seven required headers', () => {
    const headers = new Headers();
    applySecurityHeaders(headers);
    expect(headers.get('content-security-policy')).toContain("default-src 'none'");
    expect(headers.get('strict-transport-security')).toContain('max-age=31536000');
    expect(headers.get('x-content-type-options')).toBe('nosniff');
    expect(headers.get('x-frame-options')).toBe('DENY');
    expect(headers.get('referrer-policy')).toBe('no-referrer');
    expect(headers.get('permissions-policy')).toContain('camera=()');
    expect(headers.get('cross-origin-opener-policy')).toBe('same-origin');
  });
});
```

- [ ] **Step 2: Run, expect FAIL**

```powershell
pnpm test -- security-headers.test.ts
```

- [ ] **Step 3: Implement `apps/worker/src/middleware/security-headers.ts`**

```ts
export function applySecurityHeaders(h: Headers): void {
  h.set('content-security-policy', "default-src 'none'; frame-ancestors 'none'");
  h.set('strict-transport-security', 'max-age=31536000; includeSubDomains; preload');
  h.set('x-content-type-options', 'nosniff');
  h.set('x-frame-options', 'DENY');
  h.set('referrer-policy', 'no-referrer');
  h.set('permissions-policy', 'camera=(), microphone=(), geolocation=(), payment=()');
  h.set('cross-origin-opener-policy', 'same-origin');
}
```

- [ ] **Step 4: Wire into Hono app in `apps/worker/src/index.ts`**

```ts
import { applySecurityHeaders } from './middleware/security-headers';

app.use('*', async (c, next) => {
  await next();
  applySecurityHeaders(c.res.headers);
});
```

- [ ] **Step 5: Create `apps/web/public/_headers` for Pages**

```
/*
  Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; connect-src 'self' https://api.bid.mbfdhub.com https://api.staging.bid.mbfdhub.com; font-src 'self' data:; frame-ancestors 'none'; form-action 'self'
  Strict-Transport-Security: max-age=31536000; includeSubDomains; preload
  X-Content-Type-Options: nosniff
  X-Frame-Options: DENY
  Referrer-Policy: no-referrer
  Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()
```

- [ ] **Step 6: Deploy to staging and verify**

```powershell
cd D:\GitHub_Repos\mbfd-bid\apps\worker
pnpm exec wrangler deploy --env staging
cd ..\web
pnpm deploy:staging
curl -sI https://api.staging.bid.mbfdhub.com/health | Select-String -Pattern "content-security-policy|strict-transport-security|x-frame-options"
curl -sI https://staging.bid.mbfdhub.com/ | Select-String -Pattern "content-security-policy|strict-transport-security|x-frame-options"
```

Expected: both responses include all configured headers.

- [ ] **Step 7: Commit**

```bash
git add apps/worker/src/middleware/security-headers.ts apps/worker/src/index.ts apps/worker/tests/middleware/security-headers.test.ts apps/web/public/_headers
git commit -m "feat(security): CSP + HSTS + frame/referrer policy across worker and pages"
```

**Rollback if fails:** revert the wire-in middleware in `index.ts` and rename `_headers` to `_headers.disabled`; redeploy both.

---

## Task 5: Secrets audit — prod ≠ staging

**Goal:** Inventory all current staging secrets and confirm none will be reused for production. Produce a checklist of secret names that need fresh values for prod.

**Files:**
- Create: `docs/secrets-inventory.md`
- Create: `scripts/rotate-prod-secrets.ps1` (skeleton; filled in Task 13)

- [ ] **Step 1: List staging worker secrets**

```powershell
cd D:\GitHub_Repos\mbfd-bid\apps\worker
pnpm exec wrangler secret list --env staging
```

- [ ] **Step 2: List staging Pages secrets**

```powershell
pnpm exec wrangler pages secret list --project-name=mbfd-bid-web-staging
```

- [ ] **Step 3: Produce `docs/secrets-inventory.md`**

```markdown
# Secrets inventory — staging → production

| Secret name | Surface | Staging set? | Prod requires fresh value? | Source |
|---|---|---|---|---|
| JWT_SIGNING_KEY | worker | yes | YES | `openssl rand -base64 32` |
| PIN_HASH | worker | yes | YES | bcrypt of new PIN provided by chiefs |
| PORTAL_BID_READER | worker | yes | YES | new service token from portal team |
| PORTAL_BID_WRITER | worker | yes | YES | new service token from portal team |
| ADMIN_EMPLOYEE_IDS | worker | yes | YES | new comma-separated list (drop test admins) |
| AUDIT_SIGNING_PRIVKEY | worker | yes | YES | `openssl genpkey -algorithm Ed25519` |
| AUDIT_SIGNING_PUBKEY | worker (vars) | yes | YES | derived from new privkey |
| ANTHROPIC_API_KEY | worker | yes | NO (same key, separate AI Gateway env) | reuse |
| BACKUP_R2_ACCESS_KEY_ID | worker | yes | YES | new R2 token |
| BACKUP_R2_SECRET_ACCESS_KEY | worker | yes | YES | new R2 token |
| NEXT_PUBLIC_WORKER_BASE | pages vars | https://api.staging... | https://api.bid... | static |
```

- [ ] **Step 4: For any secret marked "Prod requires fresh value? YES", confirm we have a generator or source**

Run a dry-run generator for each crypto-style secret (do **not** save the output — this is just to verify the command works):

```powershell
# Confirm we can generate fresh keys when the time comes:
openssl rand -base64 32 | Out-Null
openssl genpkey -algorithm Ed25519 -out $env:TEMP\dryrun.pem
Remove-Item $env:TEMP\dryrun.pem
```

- [ ] **Step 5: Commit the inventory**

```bash
git add docs/secrets-inventory.md
git commit -m "docs(security): secrets inventory for prod cutover"
```

**Rollback if fails:** doc-only — nothing to roll back.

---

## Task 6: D1 backup procedure (scheduled `wrangler d1 export` → R2)

**Goal:** Daily (every 6 hours during bid week) D1 snapshots to R2, with a tested restore path.

**Files:**
- Create: `scripts/d1-backup.ps1`
- Create: `scripts/d1-restore.ps1`
- Create: `.github/workflows/d1-backup.yml`
- Create: `apps/worker/tests/integration/d1-backup-restore.test.ts`

- [ ] **Step 1: Write `scripts/d1-backup.ps1`**

```powershell
#requires -Version 7
param(
  [Parameter(Mandatory)][string]$Env,         # staging | production
  [Parameter(Mandatory)][string]$DbName,      # mbfd-bid-staging | mbfd-bid-production
  [Parameter(Mandatory)][string]$BucketName   # mbfd-bid-staging-backups | mbfd-bid-prod-backups
)
$ErrorActionPreference = 'Stop'
$now = Get-Date -Format 'yyyy-MM-dd-HHmm'
$tmp = New-Item -ItemType Directory -Force -Path "$env:TEMP\d1-backup-$now"
$file = Join-Path $tmp "$DbName-$now.sql"
Write-Host "Exporting D1 $DbName ($Env) to $file"
pnpm exec wrangler d1 export $DbName --env $Env --remote --output $file
if ((Get-Item $file).Length -lt 1024) { throw "Backup file suspiciously small: $((Get-Item $file).Length) bytes" }
$key = "d1/$(Get-Date -Format 'yyyy-MM-dd')/$DbName-$now.sql"
Write-Host "Uploading to r2://$BucketName/$key"
pnpm exec wrangler r2 object put "$BucketName/$key" --file=$file --remote
Remove-Item -Recurse -Force $tmp
Write-Host "Backup OK: $key"
```

- [ ] **Step 2: Write `scripts/d1-restore.ps1`**

```powershell
#requires -Version 7
param(
  [Parameter(Mandatory)][string]$Env,
  [Parameter(Mandatory)][string]$DbName,
  [Parameter(Mandatory)][string]$BucketName,
  [Parameter(Mandatory)][string]$SnapshotKey   # e.g. d1/2026-05-17/mbfd-bid-production-2026-05-17-0600.sql
)
$ErrorActionPreference = 'Stop'
$tmp = New-Item -ItemType Directory -Force -Path "$env:TEMP\d1-restore-$(Get-Random)"
$file = Join-Path $tmp "snapshot.sql"
Write-Host "Downloading r2://$BucketName/$SnapshotKey"
pnpm exec wrangler r2 object get "$BucketName/$SnapshotKey" --file=$file --remote
Write-Host "Restoring into $DbName ($Env). THIS WILL APPEND — drop tables first if needed."
pnpm exec wrangler d1 execute $DbName --env $Env --remote --file=$file
Remove-Item -Recurse -Force $tmp
Write-Host "Restore complete."
```

- [ ] **Step 3: Test backup against staging**

```powershell
cd D:\GitHub_Repos\mbfd-bid\apps\worker
..\..\scripts\d1-backup.ps1 -Env staging -DbName mbfd-bid-staging -BucketName mbfd-bid-staging-backups
```

Expected: exits 0; new object appears under `r2://mbfd-bid-staging-backups/d1/<today>/...`.

- [ ] **Step 4: Test restore into a throwaway D1**

```powershell
pnpm exec wrangler d1 create mbfd-bid-restore-test
# capture the new database_id, add temporarily to wrangler.toml as env.restore_test
..\..\scripts\d1-restore.ps1 -Env restore_test -DbName mbfd-bid-restore-test -BucketName mbfd-bid-staging-backups -SnapshotKey "d1/$(Get-Date -Format yyyy-MM-dd)/mbfd-bid-staging-$(Get-Date -Format yyyy-MM-dd-HHmm).sql"
pnpm exec wrangler d1 execute mbfd-bid-restore-test --env restore_test --remote --command="SELECT COUNT(*) FROM members"
pnpm exec wrangler d1 delete mbfd-bid-restore-test
```

Expected: member count matches the staging count at backup time.

- [ ] **Step 5: Write the GitHub Actions cron**

```yaml
# .github/workflows/d1-backup.yml
name: D1 backup
on:
  schedule:
    - cron: '0 */6 * * *'   # every 6 hours
  workflow_dispatch:
    inputs:
      env:
        description: staging | production
        required: true
        default: staging
permissions:
  contents: read
env:
  PNPM_VERSION: 9.12.0
  NODE_VERSION: 22.x
jobs:
  backup:
    runs-on: ubuntu-latest
    env:
      CLOUDFLARE_API_TOKEN: ${{ secrets.CLOUDFLARE_API_TOKEN }}
      CLOUDFLARE_ACCOUNT_ID: ${{ secrets.CLOUDFLARE_ACCOUNT_ID }}
    strategy:
      matrix:
        env: [staging, production]
    steps:
      - uses: actions/checkout@v4
      - uses: pnpm/action-setup@v4
        with: { version: '${{ env.PNPM_VERSION }}' }
      - uses: actions/setup-node@v4
        with:
          node-version: '${{ env.NODE_VERSION }}'
          cache: pnpm
      - run: pnpm install --frozen-lockfile
      - name: Run backup
        shell: pwsh
        run: |
          $db = if ("${{ matrix.env }}" -eq "production") { "mbfd-bid-production" } else { "mbfd-bid-staging" }
          $bucket = if ("${{ matrix.env }}" -eq "production") { "mbfd-bid-prod-backups" } else { "mbfd-bid-staging-backups" }
          ./scripts/d1-backup.ps1 -Env ${{ matrix.env }} -DbName $db -BucketName $bucket
```

- [ ] **Step 6: Trigger the workflow manually and verify**

```powershell
gh workflow run d1-backup.yml -F env=staging
gh run watch
```

- [ ] **Step 7: Commit**

```bash
git add scripts/d1-backup.ps1 scripts/d1-restore.ps1 .github/workflows/d1-backup.yml
git commit -m "feat(ops): D1 backup cron to R2 with tested restore script"
```

**Rollback if fails:** disable the workflow via `gh workflow disable d1-backup.yml`; manual nightly backups via the script remain available.

---

## Task 7: Dependency audit (pnpm audit)

**Goal:** Zero high or critical vulnerabilities in production dependencies. Weekly automated re-check.

**Files:**
- Create: `.github/workflows/dependency-audit.yml`
- Modify: `package.json` (overrides for any pinned advisories)

- [ ] **Step 1: Run baseline audit**

```powershell
cd D:\GitHub_Repos\mbfd-bid
pnpm audit --prod --audit-level=high
```

- [ ] **Step 2: For each finding, run `pnpm audit --fix` or pin an override**

```jsonc
// package.json
{
  "pnpm": {
    "overrides": {
      "vulnerable-pkg": "^1.2.3"
    }
  }
}
```

After overrides, run `pnpm install` and re-audit.

- [ ] **Step 3: Verify clean**

```powershell
pnpm audit --prod --audit-level=high
```

Expected: `No known vulnerabilities found`.

- [ ] **Step 4: Add weekly workflow**

```yaml
# .github/workflows/dependency-audit.yml
name: Dependency audit
on:
  schedule:
    - cron: '0 13 * * 1'   # Mondays 13:00 UTC
  workflow_dispatch:
permissions: { contents: read, issues: write }
env:
  PNPM_VERSION: 9.12.0
  NODE_VERSION: 22.x
jobs:
  audit:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: pnpm/action-setup@v4
        with: { version: '${{ env.PNPM_VERSION }}' }
      - uses: actions/setup-node@v4
        with:
          node-version: '${{ env.NODE_VERSION }}'
          cache: pnpm
      - run: pnpm install --frozen-lockfile
      - run: pnpm audit --prod --audit-level=high
```

- [ ] **Step 5: Commit**

```bash
git add .github/workflows/dependency-audit.yml package.json pnpm-lock.yaml
git commit -m "chore(deps): zero high+critical advisories; weekly audit workflow"
```

**Rollback if fails:** if a pinned override breaks runtime, drop the override and file a tracked issue; the audit workflow continues to report but won't block merges (it's separate from `ci.yml`).

---

## Task 8: E2E merge gate (every PR must pass full E2E)

**Goal:** Branch protection on `main` requires the Playwright E2E job to pass before merge.

**Files:**
- Modify: `.github/workflows/ci.yml` (already runs E2E, just ensure it's a required check)
- Create: `.github/workflows/e2e-merge-gate.yml` (named status check for branch protection)

- [ ] **Step 1: Confirm `ci.yml` E2E job runs on `pull_request`**

The current `ci.yml` has `on: pull_request: branches: [main]` and a job named `e2e`. Good — it's already a check.

- [ ] **Step 2: Add branch protection rule via gh CLI**

```powershell
$owner = (gh repo view --json owner -q .owner.login)
$repo  = (gh repo view --json name -q .name)
gh api -X PUT "repos/$owner/$repo/branches/main/protection" `
  -F required_status_checks.strict=true `
  -F 'required_status_checks.contexts[]=Lint + Typecheck' `
  -F 'required_status_checks.contexts[]=Unit + Integration' `
  -F 'required_status_checks.contexts[]=Playwright E2E' `
  -F 'required_pull_request_reviews.required_approving_review_count=1' `
  -F 'required_pull_request_reviews.dismiss_stale_reviews=true' `
  -F enforce_admins=true `
  -F 'restrictions=' `
  -F allow_force_pushes=false `
  -F allow_deletions=false `
  -F required_linear_history=true
```

- [ ] **Step 3: Verify**

```powershell
gh api "repos/$owner/$repo/branches/main/protection" | ConvertFrom-Json | Format-List
```

Expected: `required_status_checks.contexts` lists all three; `enforce_admins.enabled = True`.

- [ ] **Step 4: Open a deliberately failing PR to confirm gate works**

```powershell
git checkout -b test/e2e-gate
# break a test on purpose
git commit -am "test(meta): break a test to confirm E2E gate blocks merge"
git push -u origin test/e2e-gate
gh pr create --fill
# wait for CI; the PR should show "Required" red on the E2E check
gh pr view --json mergeStateStatus -q .mergeStateStatus
```

Expected: `BLOCKED` until the test passes.

- [ ] **Step 5: Clean up the test PR**

```powershell
gh pr close --delete-branch
git checkout main
git reset --hard origin/main
```

- [ ] **Step 6: Commit (no source change — branch protection is API-only)**

Nothing to commit; document the rule in `docs/branch-protection.md`:

```markdown
# Branch protection on `main`
- Required reviews: 1
- Required checks: Lint + Typecheck · Unit + Integration · Playwright E2E
- Enforced for admins
- Linear history required
- Configured via gh API in Plan 09 Task 8
```

```bash
git add docs/branch-protection.md
git commit -m "docs(ci): record main branch protection rules"
```

**Rollback if fails:** `gh api -X DELETE "repos/$owner/$repo/branches/main/protection"` removes the rule entirely; do not do this except in a genuine emergency.

---

# Phase B — Production resource provisioning

Everything in this phase creates new Cloudflare resources for production. **No traffic** is routed yet — that happens in Phase E.

---

## Task 9: Create production D1 database

**Goal:** A new D1 database `mbfd-bid-production` with all migrations applied. **Empty of data** — seeding happens in Phase C.

**Files:**
- Modify: `apps/worker/wrangler.toml` (replace the `REPLACE_AFTER_PRODUCTION_d1_CREATE` placeholder)

- [ ] **Step 1: Create the database**

```powershell
cd D:\GitHub_Repos\mbfd-bid\apps\worker
pnpm exec wrangler d1 create mbfd-bid-production
```

Expected output includes a `database_id` like `xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx`. Capture it.

- [ ] **Step 2: Update `apps/worker/wrangler.toml`**

```toml
[[env.production.d1_databases]]
binding = "DB"
database_name = "mbfd-bid-production"
database_id = "<paste the new database_id here>"
migrations_dir = "./migrations"
```

- [ ] **Step 3: Apply all migrations**

```powershell
pnpm exec wrangler d1 migrations apply mbfd-bid-production --remote --env production
```

Expected: every migration 0001 through 0006 reports `OK`.

- [ ] **Step 4: Verify schema**

```powershell
pnpm exec wrangler d1 execute mbfd-bid-production --env production --remote --command="SELECT name FROM sqlite_master WHERE type='table' ORDER BY name"
```

Expected: lists `members`, `credentials`, `member_credentials`, `positions`, `position_templates`, `rule_books`, `position_rules`, `bid_years`, `bid_sessions`, `bid_order`, `bids`, `audit_log`, `ai_advisories`, `snapshots`, `portal_writeback_queue`.

- [ ] **Step 5: Commit**

```bash
git add apps/worker/wrangler.toml
git commit -m "feat(prod): create mbfd-bid-production D1 + apply all migrations"
```

**Rollback if fails:** `pnpm exec wrangler d1 delete mbfd-bid-production`; revert the wrangler.toml change.

---

## Task 10: Create production KV namespace

**Goal:** A new KV namespace `mbfd-bid-prod-sessions` for prod session storage and rate-limit counters.

**Files:**
- Modify: `apps/worker/wrangler.toml`

- [ ] **Step 1: Create the namespace**

```powershell
pnpm exec wrangler kv namespace create mbfd-bid-prod-sessions
```

Expected: returns an `id` like `xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx`.

- [ ] **Step 2: Update `apps/worker/wrangler.toml`**

```toml
[[env.production.kv_namespaces]]
binding = "KV"
id = "<paste the new namespace id here>"
```

- [ ] **Step 3: Smoke-test the binding**

```powershell
pnpm exec wrangler kv key put --remote --env production --binding=KV "smoke-test" "ok"
pnpm exec wrangler kv key get --remote --env production --binding=KV "smoke-test"
pnpm exec wrangler kv key delete --remote --env production --binding=KV "smoke-test"
```

Expected: put → ok; get → "ok"; delete → ok.

- [ ] **Step 4: Commit**

```bash
git add apps/worker/wrangler.toml
git commit -m "feat(prod): create mbfd-bid-prod-sessions KV namespace"
```

**Rollback if fails:** `pnpm exec wrangler kv namespace delete --namespace-id=<id>`; revert wrangler.toml.

---

## Task 11: Create production R2 buckets

**Goal:** Two new R2 buckets: `mbfd-bid-prod-uploads` (cert PDFs, roster PDFs, CSV exports) and `mbfd-bid-prod-backups` (D1 snapshots).

**Files:**
- Modify: `apps/worker/wrangler.toml`

- [ ] **Step 1: Create the buckets**

```powershell
pnpm exec wrangler r2 bucket create mbfd-bid-prod-uploads
pnpm exec wrangler r2 bucket create mbfd-bid-prod-backups
```

- [ ] **Step 2: Add bindings to `apps/worker/wrangler.toml`**

```toml
[[env.production.r2_buckets]]
binding = "UPLOADS"
bucket_name = "mbfd-bid-prod-uploads"

[[env.production.r2_buckets]]
binding = "BACKUPS"
bucket_name = "mbfd-bid-prod-backups"
```

- [ ] **Step 3: Smoke-test**

```powershell
"hello" | Out-File -FilePath $env:TEMP\smoke.txt -NoNewline
pnpm exec wrangler r2 object put "mbfd-bid-prod-uploads/smoke-test.txt" --file=$env:TEMP\smoke.txt --remote
pnpm exec wrangler r2 object get "mbfd-bid-prod-uploads/smoke-test.txt" --file=$env:TEMP\smoke-out.txt --remote
Get-Content $env:TEMP\smoke-out.txt
pnpm exec wrangler r2 object delete "mbfd-bid-prod-uploads/smoke-test.txt" --remote
Remove-Item $env:TEMP\smoke*.txt
```

Expected: round-trip returns `hello`.

- [ ] **Step 4: Commit**

```bash
git add apps/worker/wrangler.toml
git commit -m "feat(prod): create mbfd-bid-prod-uploads + mbfd-bid-prod-backups R2 buckets"
```

**Rollback if fails:** `pnpm exec wrangler r2 bucket delete <name>` after emptying; revert wrangler.toml.

---

## Task 12: Create production Pages project

**Goal:** A new Pages project `mbfd-bid-web-prod` ready to receive deployments. **Not yet** attached to a custom domain — DNS happens in Task 16.

**Files:**
- Modify: `apps/web/wrangler.jsonc` (note: Pages projects are managed via dashboard or pages CLI, not wrangler.jsonc; this file controls *which* project a `wrangler pages deploy` targets)

- [ ] **Step 1: Create the project**

```powershell
cd D:\GitHub_Repos\mbfd-bid\apps\web
pnpm exec wrangler pages project create mbfd-bid-web-prod --production-branch=main
```

Expected: `Successfully created the 'mbfd-bid-web-prod' project.`

- [ ] **Step 2: Verify**

```powershell
pnpm exec wrangler pages project list | Select-String mbfd-bid-web-prod
```

- [ ] **Step 3: Note that `wrangler.jsonc` currently hardcodes the staging project name**

The current file has `"name": "mbfd-bid-web-staging"`. We need a way to deploy to either project. Two options:
- **(a)** Use `--project-name` CLI flag at deploy time (chosen — keeps wrangler.jsonc env-agnostic).
- **(b)** Have two wrangler.jsonc files. Rejected — duplication.

No change to `wrangler.jsonc` required. The production deploy workflow will pass `--project-name=mbfd-bid-web-prod`.

- [ ] **Step 4: No commit needed in step 3; commit nothing yet**

(The wrangler.jsonc stays as-is.)

**Rollback if fails:** `pnpm exec wrangler pages project delete mbfd-bid-web-prod`.

---

## Task 13: Set production secrets (worker + Pages)

**Goal:** Every secret in `docs/secrets-inventory.md` marked "Prod requires fresh value? YES" is set, with a value generated by the operator in a temp directory and **never written to the repo or echoed back**.

**Files:**
- Create: `scripts/rotate-prod-secrets.ps1`

- [ ] **Step 1: Write `scripts/rotate-prod-secrets.ps1`**

```powershell
#requires -Version 7
<#
Sets all production worker + Pages secrets from freshly-generated values.
Run ONCE before cutover. Each value lives in a per-run temp file deleted in the
same line.
#>
param(
  [switch]$DryRun
)
$ErrorActionPreference = 'Stop'
$tmp = New-Item -ItemType Directory -Force -Path "$env:TEMP\mbfd-secrets-$(Get-Random)"
try {
  function Set-WorkerSecret {
    param([string]$Name, [string]$Value)
    if ($DryRun) { Write-Host "[DRY] would set worker secret $Name (${($Value.Length)} chars)"; return }
    $f = Join-Path $tmp "$Name.txt"
    $Value | Out-File -FilePath $f -NoNewline -Encoding ascii
    Get-Content $f | pnpm exec wrangler secret put $Name --env production
    Remove-Item $f
  }
  function Set-PagesSecret {
    param([string]$Name, [string]$Value)
    if ($DryRun) { Write-Host "[DRY] would set pages secret $Name (${($Value.Length)} chars)"; return }
    $f = Join-Path $tmp "$Name.txt"
    $Value | Out-File -FilePath $f -NoNewline -Encoding ascii
    Get-Content $f | pnpm exec wrangler pages secret put $Name --project-name=mbfd-bid-web-prod
    Remove-Item $f
  }

  Push-Location D:\GitHub_Repos\mbfd-bid\apps\worker

  # 1. JWT_SIGNING_KEY: 32 random bytes, base64url
  $jwt = (openssl rand -base64 32).Trim()
  Set-WorkerSecret -Name JWT_SIGNING_KEY -Value $jwt

  # 2. PIN_HASH: bcrypt of new PIN (operator must paste the plaintext PIN once at the prompt)
  $pin = Read-Host -AsSecureString "Enter NEW prod PIN (numeric, set by chiefs)"
  $pinPlain = [System.Net.NetworkCredential]::new('', $pin).Password
  $pinHash = node -e "const b=require('bcryptjs'); process.stdout.write(b.hashSync(process.argv[1], 12))" -- $pinPlain
  Set-WorkerSecret -Name PIN_HASH -Value $pinHash
  Remove-Variable pinPlain

  # 3. Portal service-account tokens — operator pastes the values issued by the portal team
  $reader = Read-Host -AsSecureString "Paste prod PORTAL_BID_READER token"
  Set-WorkerSecret -Name PORTAL_BID_READER -Value ([System.Net.NetworkCredential]::new('', $reader).Password)
  $writer = Read-Host -AsSecureString "Paste prod PORTAL_BID_WRITER token"
  Set-WorkerSecret -Name PORTAL_BID_WRITER -Value ([System.Net.NetworkCredential]::new('', $writer).Password)

  # 4. ADMIN_EMPLOYEE_IDS — operator pastes comma-separated list
  $admins = Read-Host "Paste prod ADMIN_EMPLOYEE_IDS (comma separated, NO whitespace)"
  Set-WorkerSecret -Name ADMIN_EMPLOYEE_IDS -Value $admins

  # 5. AUDIT_SIGNING_PRIVKEY (Ed25519)
  $keyPath = Join-Path $tmp "audit-priv.pem"
  openssl genpkey -algorithm Ed25519 -out $keyPath
  $priv = (Get-Content $keyPath -Raw).Trim()
  Set-WorkerSecret -Name AUDIT_SIGNING_PRIVKEY -Value $priv
  $pubPath = Join-Path $tmp "audit-pub.pem"
  openssl pkey -in $keyPath -pubout -out $pubPath
  $pub = (Get-Content $pubPath -Raw).Trim()
  Set-WorkerSecret -Name AUDIT_SIGNING_PUBKEY -Value $pub
  Remove-Item $keyPath, $pubPath

  # 6. Anthropic API key — reuse staging key for now (separate AI Gateway env tag)
  Write-Host "ANTHROPIC_API_KEY: skipping (will reuse staging key with prod env tag in AI Gateway)"

  Pop-Location
}
finally {
  Remove-Item -Recurse -Force $tmp
}
```

- [ ] **Step 2: Run a dry run first**

```powershell
cd D:\GitHub_Repos\mbfd-bid
.\scripts\rotate-prod-secrets.ps1 -DryRun
```

Expected: prints `[DRY] would set worker secret ...` for each secret; no Cloudflare API calls.

- [ ] **Step 3: Run for real**

```powershell
.\scripts\rotate-prod-secrets.ps1
```

For each prompt, the operator pastes the value once. Values are never printed.

- [ ] **Step 4: Verify the secrets are set (names only, never values)**

```powershell
cd apps\worker
pnpm exec wrangler secret list --env production
```

Expected: lists the 7 secret names. No values displayed.

- [ ] **Step 5: Pages secrets — there are currently none Pages-side (worker handles auth). If we add any (e.g., a build-time NEXT_PUBLIC_WORKER_BASE override), set them here**

```powershell
cd ..\web
# example only — NEXT_PUBLIC_* are public, not secret, and are set as build-time env vars in the deploy workflow
pnpm exec wrangler pages secret list --project-name=mbfd-bid-web-prod
```

Expected: empty list. Good.

- [ ] **Step 6: Commit the runbook (script, NOT the secrets)**

```bash
git add scripts/rotate-prod-secrets.ps1
git commit -m "feat(ops): one-shot prod secret rotation runbook"
```

**Rollback if fails:** `pnpm exec wrangler secret delete <NAME> --env production` for each; the worker will fall back to env defaults (and refuse to start where the secret is mandatory).

---

## Task 14: DNS — add CNAME for `bid.mbfdhub.com`

**Goal:** `bid.mbfdhub.com` resolves to `mbfd-bid-web-prod.pages.dev` via a Cloudflare CNAME. Universal SSL covers it once Pages domain is attached.

**Files:**
- No repo changes; Cloudflare dashboard or `gh secret` for the API token already set.

- [ ] **Step 1: Verify zone access**

```powershell
$zone = curl -s -H "Authorization: Bearer $env:CLOUDFLARE_API_TOKEN" `
  "https://api.cloudflare.com/client/v4/zones?name=mbfdhub.com" | ConvertFrom-Json
$zoneId = $zone.result[0].id
Write-Host "Zone id: $zoneId"
```

- [ ] **Step 2: Create the CNAME (proxied through Cloudflare)**

```powershell
curl -X POST "https://api.cloudflare.com/client/v4/zones/$zoneId/dns_records" `
  -H "Authorization: Bearer $env:CLOUDFLARE_API_TOKEN" `
  -H "Content-Type: application/json" `
  -d '{"type":"CNAME","name":"bid","content":"mbfd-bid-web-prod.pages.dev","proxied":true,"ttl":1}'
```

Expected: returns `success: true` with a new record id.

- [ ] **Step 3: Attach the custom domain to the Pages project**

```powershell
cd D:\GitHub_Repos\mbfd-bid\apps\web
pnpm exec wrangler pages domain add bid.mbfdhub.com --project-name=mbfd-bid-web-prod
```

- [ ] **Step 4: Poll for cert issuance (may take 1–15 minutes)**

```powershell
do {
  $status = pnpm exec wrangler pages domain list --project-name=mbfd-bid-web-prod | Select-String "bid.mbfdhub.com"
  Write-Host $status
  Start-Sleep -Seconds 30
} until ($status -match 'active')
```

Expected: eventually prints `active`.

- [ ] **Step 5: Verify DNS resolves and serves Cloudflare**

```powershell
dig bid.mbfdhub.com +short
# Expect Cloudflare IPs
curl -sI https://bid.mbfdhub.com/ | Select-String -Pattern "HTTP|server"
```

Expected: HTTP 200 (Pages serves a placeholder until our deploy lands), server header is `cloudflare`.

- [ ] **Step 6: Commit a tracking note**

```bash
git add docs/dns-prod.md
git commit -m "docs(prod): record bid.mbfdhub.com DNS + Pages domain attachment"
```

`docs/dns-prod.md`:

```markdown
# Prod DNS records

| Record | Type | Target | Proxied | TTL | Cert issuer | Notes |
|---|---|---|---|---|---|---|
| bid.mbfdhub.com | CNAME | mbfd-bid-web-prod.pages.dev | yes | auto | Cloudflare dedicated (auto) | Pages custom domain |
| api.bid.mbfdhub.com | Worker custom_domain | mbfd-bid-worker-production | n/a | n/a | Cloudflare dedicated (auto) | wrangler routes[] custom_domain=true |
```

**Rollback if fails:** `pnpm exec wrangler pages domain remove bid.mbfdhub.com --project-name=mbfd-bid-web-prod` and delete the DNS record via the Cloudflare API.

---

## Task 15: DNS — add Worker custom domain for `api.bid.mbfdhub.com`

**Goal:** `api.bid.mbfdhub.com` routes to the prod worker. Dedicated cert auto-provisioned. **No** legacy `route = { pattern, zone_name }` shape.

**Files:**
- Modify: `apps/worker/wrangler.toml` (already has `routes = [{ pattern = "api.bid.mbfdhub.com", custom_domain = true }]` from Task 9 prep — verify)

- [ ] **Step 1: Verify the wrangler.toml entry is correct**

```powershell
Select-String -Path D:\GitHub_Repos\mbfd-bid\apps\worker\wrangler.toml -Pattern "api.bid.mbfdhub.com"
```

Expected: matches `routes = [{ pattern = "api.bid.mbfdhub.com", custom_domain = true }]` under `[env.production]`. If not, edit it now.

- [ ] **Step 2: Deploy the worker (this triggers Cloudflare to provision the custom domain + cert)**

```powershell
cd D:\GitHub_Repos\mbfd-bid\apps\worker
pnpm exec wrangler deploy --env production
```

Expected output includes a line like `Routes: api.bid.mbfdhub.com (custom domain)` and `Pending` cert status that flips to `Active` within ~15 min.

- [ ] **Step 3: Poll for cert provisioning**

```powershell
do {
  $resp = curl -sI https://api.bid.mbfdhub.com/health
  Write-Host $resp
  Start-Sleep -Seconds 30
} until ($resp -match '^HTTP/[\d.]+ 200')
```

Expected: eventually serves HTTP 200 with the worker's `/health` response.

- [ ] **Step 4: Confirm cert is dedicated, not Universal**

```powershell
openssl s_client -connect api.bid.mbfdhub.com:443 -servername api.bid.mbfdhub.com </dev/null 2>$null | openssl x509 -noout -subject -issuer -dates
```

Expected: `subject=CN = api.bid.mbfdhub.com` (or covers it). If it's `subject=CN = *.mbfdhub.com` that's fine too; what we **must not** see is a cert that doesn't cover this host at all (which is the 4th-level Universal SSL trap).

- [ ] **Step 5: Test an endpoint**

```powershell
curl -s https://api.bid.mbfdhub.com/health
```

Expected: `{"ok":true}` or similar 200 body.

- [ ] **Step 6: Commit (wrangler.toml updated already in Task 9; this task is a deploy step)**

If any tweak to wrangler.toml was needed:

```bash
git add apps/worker/wrangler.toml
git commit -m "feat(prod): bind api.bid.mbfdhub.com as Worker custom domain"
```

**Rollback if fails:** remove the `routes` entry for production in wrangler.toml, re-deploy with `--env production`. Cloudflare will release the custom domain. DNS untouched.

---

## Task 16: First prod deploy (web + worker, no traffic yet)

**Goal:** Both surfaces deployed and reachable, but DNS for `bid.mbfdhub.com` already points to the new project so this is effectively the moment new traffic *could* land. We don't switch any user-facing redirects yet.

**Files:**
- Create: `.github/workflows/deploy-production.yml`

- [ ] **Step 1: Write the prod deploy workflow**

```yaml
# .github/workflows/deploy-production.yml
name: Deploy production
on:
  workflow_dispatch:
    inputs:
      confirm:
        description: 'Type PRODUCTION to confirm'
        required: true
permissions:
  contents: read
  deployments: write
env:
  PNPM_VERSION: 9.12.0
  NODE_VERSION: 22.x
jobs:
  guard:
    runs-on: ubuntu-latest
    steps:
      - run: |
          if [ "${{ inputs.confirm }}" != "PRODUCTION" ]; then
            echo "Confirmation string mismatch"; exit 1
          fi
  deploy-worker:
    runs-on: ubuntu-latest
    needs: guard
    environment: { name: production, url: https://api.bid.mbfdhub.com }
    env:
      CLOUDFLARE_API_TOKEN: ${{ secrets.CLOUDFLARE_API_TOKEN }}
      CLOUDFLARE_ACCOUNT_ID: ${{ secrets.CLOUDFLARE_ACCOUNT_ID }}
    steps:
      - uses: actions/checkout@v4
      - uses: pnpm/action-setup@v4
        with: { version: '${{ env.PNPM_VERSION }}' }
      - uses: actions/setup-node@v4
        with:
          node-version: '${{ env.NODE_VERSION }}'
          cache: pnpm
      - run: pnpm install --frozen-lockfile
      - run: pnpm --filter @mbfd/shared build
      - run: pnpm exec wrangler d1 migrations apply mbfd-bid-production --remote --env production
        working-directory: apps/worker
      - run: pnpm exec wrangler deploy --env production
        working-directory: apps/worker
  deploy-web:
    runs-on: ubuntu-latest
    needs: deploy-worker
    environment: { name: production, url: https://bid.mbfdhub.com }
    env:
      CLOUDFLARE_API_TOKEN: ${{ secrets.CLOUDFLARE_API_TOKEN }}
      CLOUDFLARE_ACCOUNT_ID: ${{ secrets.CLOUDFLARE_ACCOUNT_ID }}
      NEXT_PUBLIC_WORKER_BASE: https://api.bid.mbfdhub.com
    steps:
      - uses: actions/checkout@v4
      - uses: pnpm/action-setup@v4
        with: { version: '${{ env.PNPM_VERSION }}' }
      - uses: actions/setup-node@v4
        with:
          node-version: '${{ env.NODE_VERSION }}'
          cache: pnpm
      - run: pnpm install --frozen-lockfile
      - run: pnpm --filter @mbfd/shared build
      - working-directory: apps/web
        run: |
          pnpm exec next-on-pages
          pnpm exec wrangler pages deploy .vercel/output/static --project-name=mbfd-bid-web-prod --branch=main
```

- [ ] **Step 2: Trigger the workflow**

```powershell
gh workflow run deploy-production.yml -F confirm=PRODUCTION
gh run watch
```

Expected: both jobs green.

- [ ] **Step 3: Smoke**

```powershell
curl -s https://api.bid.mbfdhub.com/health
curl -sI https://bid.mbfdhub.com/
```

Expected: worker health returns 200; web returns 200 and the PIN gate page.

- [ ] **Step 4: Commit**

```bash
git add .github/workflows/deploy-production.yml
git commit -m "feat(ops): manual workflow_dispatch deploy to production"
```

**Rollback if fails:** if the deploy succeeds but the worker is broken, `gh workflow run deploy-production.yml` with the previous green commit hash (Actions allows specifying a ref). If a deploy half-succeeds, see Phase F rollback procedure.

---

# Phase C — Migration from staging

## Task 17: Production seed (members, positions, rules)

**Goal:** Run a single idempotent seed script against the prod D1 that loads the 2026 member roster, position template, and rule book. Same shape as the staging seed but with prod bindings.

**Files:**
- Create: `scripts/seed-prod.ts`
- Modify: `apps/worker/package.json` (add `seed:prod` script)

- [ ] **Step 1: Write `scripts/seed-prod.ts`**

```ts
// scripts/seed-prod.ts
// Run with: pnpm --filter @mbfd/worker seed:prod
//
// Same logic as apps/worker/seed/2026.ts but reads from the 2026 source-of-truth
// markdown files, and targets the production D1 via wrangler --env production.
//
// IDEMPOTENT: uses INSERT OR IGNORE / upsert on natural keys.

import { execSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const ANALYSIS = 'D:/GitHub_Repos/MBFD_Hub/analysis';
const BID_DOCS = 'D:/MBFD/Bid/2026 Bid Documents';

// 1. Validate inputs exist
for (const p of [`${ANALYSIS}/personnel.csv`, `${BID_DOCS}/2026_Position_Template.md`, `${BID_DOCS}/2026_Rules_and_Points.md`, `${BID_DOCS}/2026_DELTA_from_2025.md`]) {
  readFileSync(p);
}

// 2. Re-use the existing staging seed code path but with --env production
//    The staging seed lives at apps/worker/seed/2026.ts and accepts --env flag.
console.log('Running seed against production D1...');
execSync('pnpm exec tsx seed/2026.ts --env production --remote', {
  cwd: 'D:/GitHub_Repos/mbfd-bid/apps/worker',
  stdio: 'inherit',
});

// 3. Verify counts
const checks = [
  ['members',     '>= 230'],
  ['credentials', '>= 50'],
  ['positions',   '>= 230'],
  ['position_rules', '>= 230'],
];
for (const [table, expected] of checks) {
  const out = execSync(`pnpm exec wrangler d1 execute mbfd-bid-production --env production --remote --command="SELECT COUNT(*) AS n FROM ${table}"`, {
    cwd: 'D:/GitHub_Repos/mbfd-bid/apps/worker',
    encoding: 'utf8',
  });
  console.log(`${table}: ${out.trim()} (expected ${expected})`);
}
```

- [ ] **Step 2: Add to `apps/worker/package.json`**

```json
{
  "scripts": {
    "seed:prod": "tsx ../../scripts/seed-prod.ts"
  }
}
```

- [ ] **Step 3: Run the seed**

```powershell
cd D:\GitHub_Repos\mbfd-bid
pnpm --filter @mbfd/worker seed:prod
```

Expected: prints `members: 238`, `positions: 234`, etc. Re-running it MUST be idempotent — running twice produces the same counts (the INSERT OR IGNORE on natural keys).

- [ ] **Step 4: Re-run to verify idempotency**

```powershell
pnpm --filter @mbfd/worker seed:prod
```

Expected: same counts, no `UNIQUE constraint failed` errors.

- [ ] **Step 5: Commit**

```bash
git add scripts/seed-prod.ts apps/worker/package.json
git commit -m "feat(prod): idempotent production seed for members + positions + rules"
```

**Rollback if fails:** `wrangler d1 execute mbfd-bid-production --env production --remote --command="DELETE FROM members; DELETE FROM positions; DELETE FROM position_rules; DELETE FROM credentials"` and re-run. The seed is safe to re-run.

---

## Task 18: Prod smoke test — PIN → login → admin → eligible-positions

**Goal:** A scripted end-to-end smoke against the prod surface that exercises every critical path. Must pass before Phase D rehearsal.

**Files:**
- Create: `scripts/cutover-smoke.ps1`

- [ ] **Step 1: Write the smoke script**

```powershell
#requires -Version 7
<#
Cutover smoke test. Runs against prod by default.
Expects env: SMOKE_PIN, SMOKE_MEMBER_EMP_ID, SMOKE_MEMBER_PASSWORD, SMOKE_ADMIN_EMP_ID, SMOKE_ADMIN_PASSWORD
#>
param(
  [string]$WebBase = 'https://bid.mbfdhub.com',
  [string]$ApiBase = 'https://api.bid.mbfdhub.com'
)
$ErrorActionPreference = 'Stop'

function Assert-200 { param([string]$Url) $r = curl -sI $Url; if ($r -notmatch 'HTTP/[\d.]+ 200') { throw "$Url did not return 200: $r" }; Write-Host "OK $Url" }

# 1. PIN gate page reachable
Assert-200 "$WebBase/"

# 2. Worker health
Assert-200 "$ApiBase/health"

# 3. PIN check
$pinResp = curl -s -X POST "$WebBase/api/pin" -H "Content-Type: application/json" -d "{`"pin`":`"$env:SMOKE_PIN`"}" -c $env:TEMP\smoke-cookies.txt
if ($pinResp -notmatch 'ok') { throw "PIN check failed: $pinResp" }
Write-Host "OK PIN check"

# 4. Member login
$loginResp = curl -s -X POST "$ApiBase/auth/login" -b $env:TEMP\smoke-cookies.txt -c $env:TEMP\smoke-cookies.txt `
  -H "Content-Type: application/json" `
  -d "{`"employee_id`":`"$env:SMOKE_MEMBER_EMP_ID`",`"password`":`"$env:SMOKE_MEMBER_PASSWORD`"}"
if ($loginResp -notmatch 'role') { throw "Member login failed: $loginResp" }
Write-Host "OK member login"

# 5. Lobby page renders for the member
Assert-200 "$WebBase/lobby"

# 6. Admin login
Remove-Item $env:TEMP\smoke-cookies.txt -ErrorAction SilentlyContinue
$adminResp = curl -s -X POST "$ApiBase/auth/login" -c $env:TEMP\smoke-cookies.txt `
  -H "Content-Type: application/json" `
  -d "{`"employee_id`":`"$env:SMOKE_ADMIN_EMP_ID`",`"password`":`"$env:SMOKE_ADMIN_PASSWORD`"}"
if ($adminResp -notmatch '"role":"admin"') { throw "Admin login did not return admin role: $adminResp" }
Write-Host "OK admin login (role=admin)"

# 7. Admin members list returns >= 230
$members = curl -s "$ApiBase/admin/members?limit=1" -b $env:TEMP\smoke-cookies.txt | ConvertFrom-Json
if ($members.total -lt 230) { throw "Members count too low: $($members.total)" }
Write-Host "OK admin/members total = $($members.total)"

# 8. Eligibility — pick a few sample positions
$positions = curl -s "$ApiBase/admin/positions?limit=5" -b $env:TEMP\smoke-cookies.txt | ConvertFrom-Json
foreach ($p in $positions.rows) {
  $elig = curl -s "$ApiBase/admin/positions/$($p.id)/eligible?limit=3" -b $env:TEMP\smoke-cookies.txt | ConvertFrom-Json
  Write-Host "OK eligible for $($p.id): $($elig.count) members"
}

Remove-Item $env:TEMP\smoke-cookies.txt -ErrorAction SilentlyContinue
Write-Host "ALL SMOKE CHECKS PASSED"
```

- [ ] **Step 2: Set the env vars locally (test credentials provided by chiefs)**

```powershell
$env:SMOKE_PIN = "<prod PIN>"
$env:SMOKE_MEMBER_EMP_ID = "<test member id>"
$env:SMOKE_MEMBER_PASSWORD = "<test password>"
$env:SMOKE_ADMIN_EMP_ID = "<admin id>"
$env:SMOKE_ADMIN_PASSWORD = "<admin password>"
```

- [ ] **Step 3: Run**

```powershell
.\scripts\cutover-smoke.ps1
```

Expected: `ALL SMOKE CHECKS PASSED`.

- [ ] **Step 4: Commit (no values committed)**

```bash
git add scripts/cutover-smoke.ps1
git commit -m "feat(ops): cutover smoke test (PIN -> login -> admin -> eligibility)"
```

**Rollback if fails:** the script is read-only against prod data — no rollback. If a step fails, fix the underlying surface and re-run.

---

# Phase D — Rehearsal day (A-Day-1 dry run)

## Task 19: Two-real-test-member end-to-end

**Goal:** Two on-shift volunteers (one OFC, one FF) run the live bid flow against prod from their actual phones. Capture screen recordings; log every issue.

**Files:**
- Create: `docs/rehearsal-day-report.md`

- [ ] **Step 1: Schedule with operations chief**

Email the operations chief 7 days before rehearsal-day-1:
- Need 2 volunteers, 1 OFC + 1 FF.
- Time slot: 30 minutes.
- They log in with their *real* portal credentials against `bid.mbfdhub.com`.
- We screen-record (audio off) for the post-mortem.

- [ ] **Step 2: Confirm chairs prepped — admin will run an empty mock session**

The admin (on a desktop) creates a throwaway bid session in prod:
- Year: `2026-rehearsal`
- Only 4 positions: 1 OFC LT slot, 1 FF combat slot, 1 FF rescue slot, 1 marine slot
- Both volunteers added to bid_order

- [ ] **Step 3: Volunteers run the flow**

Capture:
- Time-to-first-pick (target: < 60s from PIN page load to confirmed pick)
- Any UI confusion ("what does this button do?")
- Any network failure (Wi-Fi drop → reconnect should be seamless)
- Pick confirms correctly in admin view

- [ ] **Step 4: Capture findings in `docs/rehearsal-day-report.md`**

```markdown
# Rehearsal Day 1 — <DATE>

## Participants
- Volunteer A: OFC, station X
- Volunteer B: FF, station Y
- Admin: <name>

## Timeline
| time | event | outcome |
|---|---|---|
| 10:00 | session created | OK |
| 10:02 | A logged in | OK, 12s |
| 10:03 | A picked LT slot | OK |
| 10:05 | B logged in | issue: PIN field auto-completed wrong → typed manually |
| ... | | |

## Issues
1. **HIGH**: ... (file gh issue + label `rehearsal`)
2. **MEDIUM**: ...

## Sign-off
- Operations chief: ____________________
- IT lead: ____________________
```

- [ ] **Step 5: File issues**

For each HIGH/MEDIUM issue, open a GitHub issue tagged `rehearsal` and `blocker-for-cutover` (if HIGH).

```powershell
gh issue create --title "<one-liner>" --body "Found during rehearsal day 1. <details>" --label rehearsal,blocker-for-cutover
```

- [ ] **Step 6: Commit the report**

```bash
git add docs/rehearsal-day-report.md
git commit -m "docs(rehearsal): A-Day-1 findings + sign-off"
```

**Rollback if fails:** if a HIGH issue blocks cutover, that's a separate fix-and-re-rehearse cycle. The plan does not auto-cutover on rehearsal failure.

---

## Task 20: Concurrency load test — 100 simultaneous picks

**Goal:** Drive 100 concurrent Playwright browser contexts through the pick flow against prod and confirm the worker + DO sustain it.

**Files:**
- Create: `apps/web/tests/load/playwright.load.config.ts`
- Create: `apps/web/tests/load/100-concurrent-picks.spec.ts`

- [ ] **Step 1: Write the load config**

```ts
// apps/web/tests/load/playwright.load.config.ts
import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
  testDir: '.',
  workers: 100,
  fullyParallel: true,
  timeout: 60_000,
  use: {
    baseURL: process.env.LOAD_BASE_URL ?? 'https://bid.mbfdhub.com',
    storageState: undefined,
    ignoreHTTPSErrors: false,
    trace: 'retain-on-failure',
  },
  reporter: [['list'], ['html', { outputFolder: 'playwright-report-load', open: 'never' }]],
  projects: [{ name: 'load', use: { ...devices['Desktop Chrome'] } }],
});
```

- [ ] **Step 2: Write the spec**

```ts
// apps/web/tests/load/100-concurrent-picks.spec.ts
import { test, expect } from '@playwright/test';

// Each worker logs in as a unique test member.
// Pre-seed step: create 100 members `loadtest-001` .. `loadtest-100` in prod
// with simple known passwords (run once via scripts/load-test-seed.ts; not committed).

const SLOT = test.info().workerIndex; // 0..99
const EMP_ID = `loadtest-${String(SLOT + 1).padStart(3, '0')}`;
const PWD = 'load-test-only-NOT-real';

test(`worker ${SLOT}: pin -> login -> view lobby`, async ({ page }) => {
  const t0 = Date.now();
  await page.goto('/');
  await page.fill('input[name="pin"]', process.env.SMOKE_PIN ?? '0000');
  await page.click('button[type="submit"]');
  await expect(page).toHaveURL(/\/login$/);

  await page.fill('input[name="employee_id"]', EMP_ID);
  await page.fill('input[name="password"]', PWD);
  await page.click('button[type="submit"]');
  await expect(page).toHaveURL(/\/lobby$/, { timeout: 15_000 });

  const t1 = Date.now();
  console.log(`worker ${SLOT}: pin->lobby took ${t1 - t0}ms`);
  expect(t1 - t0).toBeLessThan(15_000);
});
```

- [ ] **Step 3: Seed the load-test members (one-time, scripted)**

```powershell
# scripts/load-test-seed.ps1 (gitignored)
# Generates 100 throwaway members with predictable passwords in prod D1.
# Removed by scripts/load-test-cleanup.ps1 after the test.
```

The seed adds rows like:

```sql
INSERT INTO members (employee_id, first_name, last_name, rank, bid_category, rsc_seniority, ...)
VALUES ('loadtest-001', 'Load', 'Test001', 'FF', 'FF', 9000, ...);
```

- [ ] **Step 4: Run the load test**

```powershell
cd D:\GitHub_Repos\mbfd-bid\apps\web
$env:LOAD_BASE_URL = 'https://bid.mbfdhub.com'
$env:SMOKE_PIN = '<prod PIN>'
pnpm exec playwright test --config=tests/load/playwright.load.config.ts
```

Expected:
- All 100 workers reach `/lobby` within 15s.
- Worker logs show no 5xx errors during the run (`wrangler tail --env production --status error`).
- Worker p95 latency (visible in CF dashboard) < 1s.

- [ ] **Step 5: Clean up load-test data**

```powershell
pnpm exec wrangler d1 execute mbfd-bid-production --env production --remote `
  --command="DELETE FROM members WHERE employee_id LIKE 'loadtest-%'"
```

- [ ] **Step 6: Commit the spec (NOT the seed script with credentials)**

```bash
git add apps/web/tests/load/playwright.load.config.ts apps/web/tests/load/100-concurrent-picks.spec.ts
git commit -m "test(load): 100-context concurrent pin-to-lobby smoke"
```

**Rollback if fails:** if the test exposes a deadlock or DO saturation, file a `blocker-for-cutover` issue and do **not** proceed to Phase E until fixed.

---

## Task 21: Failover drill — simulated Worker outage

**Goal:** Prove that when the prod worker fails (we simulate by disabling deployment), the user-facing surface degrades gracefully (Pages serves a maintenance page; PIN check still gates).

**Files:**
- Create: `apps/web/app/maintenance/page.tsx`
- Modify: `apps/web/middleware.ts` (route to /maintenance on worker 5xx)

- [ ] **Step 1: Write the maintenance page**

```tsx
// apps/web/app/maintenance/page.tsx
export const runtime = 'edge';
export default function MaintenancePage() {
  return (
    <main className="min-h-screen bg-stone-50 flex items-center justify-center p-8">
      <div className="max-w-md text-center">
        <h1 className="text-3xl font-bold text-red-700">Bid system temporarily unavailable</h1>
        <p className="mt-4 text-stone-700">
          We are restoring service. Please refresh in 60 seconds. If you have an in-progress pick, your selection is preserved.
        </p>
        <p className="mt-2 text-sm text-stone-500">Contact your shift commander if this persists more than 5 minutes.</p>
      </div>
    </main>
  );
}
```

- [ ] **Step 2: Modify middleware to detect worker 5xx (probe at request time)**

The probe pattern: on each Pages middleware invocation, if the last 3 calls to `${WORKER_BASE}/health` returned 5xx (cached in cookies for 10s), redirect to `/maintenance`. Keep this **simple** — full circuit breakers are out of scope.

```ts
// apps/web/middleware.ts — add to existing logic
async function probeWorker(workerBase: string): Promise<boolean> {
  try {
    const r = await fetch(`${workerBase}/health`, { method: 'GET', signal: AbortSignal.timeout(2000) });
    return r.ok;
  } catch { return false; }
}
// ...
const isHealthy = await probeWorker(WORKER_BASE);
if (!isHealthy && url.pathname !== '/maintenance') {
  return NextResponse.rewrite(new URL('/maintenance', url));
}
```

- [ ] **Step 3: Simulate worker outage on staging (do NOT do this on prod)**

```powershell
cd D:\GitHub_Repos\mbfd-bid\apps\worker
pnpm exec wrangler deploy --env staging --dry-run   # just confirms build OK
# Force a 500 by deploying a broken version to STAGING ONLY:
git checkout -b drill/break-worker-temporarily
# edit src/routes/health.ts to throw
git commit -am "drill: force 500 on /health (staging only, REVERT)"
git push -u origin drill/break-worker-temporarily
pnpm exec wrangler deploy --env staging
```

- [ ] **Step 4: Verify the maintenance page is served**

```powershell
curl -sI https://staging.bid.mbfdhub.com/lobby
# Expect 200 with a body containing "temporarily unavailable"
```

- [ ] **Step 5: Restore staging**

```powershell
git checkout main
pnpm exec wrangler deploy --env staging
git push origin --delete drill/break-worker-temporarily
git branch -D drill/break-worker-temporarily
```

- [ ] **Step 6: Commit the maintenance code (this is real production code)**

```bash
git add apps/web/app/maintenance/page.tsx apps/web/middleware.ts
git commit -m "feat(web): maintenance fallback when worker /health is 5xx"
```

**Rollback if fails:** revert the middleware change; the maintenance page becomes dead code but harmless.

---

# Phase E — Cutover day (A-Day)

## Task 22: Freeze staging (T-24h)

**Goal:** No deploys to staging during the 24 hours before cutover. Anyone who pushes to `main` gets a CI warning.

**Files:**
- Modify: `.github/workflows/deploy-staging.yml` (gate behind an env var)

- [ ] **Step 1: Add a freeze flag**

```yaml
# .github/workflows/deploy-staging.yml — add at top of each job's `if:`
jobs:
  deploy-worker:
    if: ${{ vars.STAGING_FROZEN != 'true' }}
    # ...
```

- [ ] **Step 2: Set the var at freeze time**

```powershell
gh variable set STAGING_FROZEN --body "true"
```

- [ ] **Step 3: Verify**

```powershell
gh variable list | Select-String STAGING_FROZEN
```

- [ ] **Step 4: Commit**

```bash
git add .github/workflows/deploy-staging.yml
git commit -m "ci(staging): freezable deploys via STAGING_FROZEN var"
```

**Unfreeze step (T+24h post-cutover):** `gh variable set STAGING_FROZEN --body "false"`.

**Rollback if fails:** unfreeze immediately if the freeze blocks a critical fix.

---

## Task 23: Final data sync (T-2h)

**Goal:** Re-run the prod seed against the latest source-of-truth (in case any roster updates landed in the bid documents between rehearsal and cutover).

**Files:** none changed. Operational task.

- [ ] **Step 1: Compare source files against last seed**

```powershell
git -C D:\MBFD\Bid log -1 --format="%ci %H" -- "2026 Bid Documents"
git -C D:\GitHub_Repos\MBFD_Hub log -1 --format="%ci %H" -- analysis
```

If either source moved since the last `seed:prod` run, proceed; otherwise skip.

- [ ] **Step 2: Take a pre-sync backup**

```powershell
cd D:\GitHub_Repos\mbfd-bid
.\scripts\d1-backup.ps1 -Env production -DbName mbfd-bid-production -BucketName mbfd-bid-prod-backups
```

Note the resulting `r2://mbfd-bid-prod-backups/d1/...` key — that's the rollback target if cutover fails.

- [ ] **Step 3: Run the seed**

```powershell
pnpm --filter @mbfd/worker seed:prod
```

- [ ] **Step 4: Re-run the smoke**

```powershell
.\scripts\cutover-smoke.ps1
```

Expected: `ALL SMOKE CHECKS PASSED`.

- [ ] **Step 5: Snapshot the post-sync state**

```powershell
.\scripts\d1-backup.ps1 -Env production -DbName mbfd-bid-production -BucketName mbfd-bid-prod-backups
```

This is the "T-2h baseline" backup. Document its R2 key in `docs/cutover-runbook.md`.

**Rollback if fails:** restore from the pre-sync backup using `scripts/d1-restore.ps1`.

---

## Task 24: Cutover deploy + DNS confirm

**Goal:** Final clean deploy of `main` to production. DNS already points correctly from Tasks 14–15 — this step just ensures the latest code is live.

**Files:** none changed. Operational task.

- [ ] **Step 1: Confirm `main` is green**

```powershell
gh run list --branch main --limit 1
```

Expected: most recent CI run is `success`.

- [ ] **Step 2: Trigger prod deploy**

```powershell
gh workflow run deploy-production.yml -F confirm=PRODUCTION
gh run watch
```

Expected: both `deploy-worker` and `deploy-web` jobs green within 10 minutes.

- [ ] **Step 3: Verify endpoints**

```powershell
curl -sI https://bid.mbfdhub.com/                | Select-String -Pattern "HTTP"
curl -s    https://api.bid.mbfdhub.com/health    | ConvertFrom-Json
curl -sI https://bid.mbfdhub.com/_next/static/   | Select-String -Pattern "HTTP"
```

Expected: 200, `{ok:true}`, 200.

- [ ] **Step 4: Run the smoke once more**

```powershell
.\scripts\cutover-smoke.ps1
```

Expected: `ALL SMOKE CHECKS PASSED`.

- [ ] **Step 5: Tag the release**

```powershell
$tag = "prod-cutover-$(Get-Date -Format yyyy-MM-dd)"
git tag -a $tag -m "Production cutover: $tag"
git push origin $tag
```

- [ ] **Step 6: Announce in the ops channel**

> Production live at https://bid.mbfdhub.com — release tag `prod-cutover-YYYY-MM-DD`. Smoke green. Entering 24h watch.

**Rollback if fails:** see Phase F.

---

## Task 25: Cutover sign-off checklist

**Goal:** A written list of confirmations from the operations chief, IT lead, and lead engineer before declaring cutover complete.

**Files:**
- Create: `docs/cutover-signoff.md`

- [ ] **Step 1: Write the checklist**

```markdown
# Cutover sign-off — <DATE>

Tick each. The cutover is COMPLETE when every box has an initial.

## Operations chief (___)
- [ ] PIN distributed to chiefs (sealed envelope or 1Password share)
- [ ] Admin credentials verified working on bid.mbfdhub.com
- [ ] Phone tree confirmed; backup paper roster in chief's possession
- [ ] Member quickstart PDF emailed to all members

## IT lead (___)
- [ ] DNS records confirmed (bid + api.bid)
- [ ] Both certs `active`
- [ ] Backups proven to restore
- [ ] On-call rotation pager active for 72h

## Lead engineer (___)
- [ ] `main` deployed; release tag pushed
- [ ] Smoke green (output saved)
- [ ] Wrangler tail open and clean for 10 min
- [ ] Cloudflare alerts wired (error rate, latency, AI cost)
- [ ] Rollback runbook printed and on the operations chief's desk

## Time of go-live
- Declared at: ____________
- Initials of all three above required to declare go-live.
```

- [ ] **Step 2: Commit**

```bash
git add docs/cutover-signoff.md
git commit -m "docs(cutover): sign-off checklist for go-live"
```

**Rollback if fails:** if any checkbox cannot be ticked, halt cutover. Either fix and re-attempt, or invoke Phase F rollback.

---

# Phase F — Rollback procedure

This phase is the **written contract** for restoring the prior state if cutover fails. It is tested in Task 27 *before* Task 24 runs.

---

## Task 26: Write the rollback runbook

**Goal:** A step-by-step doc that an operations-savvy non-author can execute under stress.

**Files:**
- Create: `docs/rollback-runbook.md`

- [ ] **Step 1: Write the runbook**

```markdown
# Rollback runbook — `bid.mbfdhub.com`

> Triggers: prod worker error rate > 5% for 5 minutes; prod write-back queue stalled > 10 min; admin declares rollback verbally.

## Owner
- IT lead executes; operations chief authorizes.

## Pre-flight (1 min)
1. Open Cloudflare dashboard; confirm zone `mbfdhub.com` is reachable.
2. Open the most recent `r2://mbfd-bid-prod-backups/d1/...` key in `docs/cutover-runbook.md`.
3. Confirm `gh auth status` returns logged in.

## Step 1: Stop the bleed (30s)
Force the prod worker to maintenance-only mode by pushing a "503 everywhere" build:

```powershell
git checkout -b rollback/$(Get-Date -Format yyyyMMdd-HHmm)
# edit apps/worker/src/index.ts: at top of every route, return c.text('Maintenance', 503)
git commit -am "rollback: force 503 on prod worker"
git push -u origin HEAD
gh workflow run deploy-production.yml -F confirm=PRODUCTION
```

## Step 2: Re-point DNS to staging (5 min)
We do NOT delete the prod DNS; we change the target.

```powershell
$zoneId = (curl -s -H "Authorization: Bearer $env:CLOUDFLARE_API_TOKEN" `
  "https://api.cloudflare.com/client/v4/zones?name=mbfdhub.com" | ConvertFrom-Json).result[0].id

# bid.mbfdhub.com → staging Pages
$bidRec = (curl -s -H "Authorization: Bearer $env:CLOUDFLARE_API_TOKEN" `
  "https://api.cloudflare.com/client/v4/zones/$zoneId/dns_records?name=bid.mbfdhub.com" | ConvertFrom-Json).result[0]
curl -X PATCH "https://api.cloudflare.com/client/v4/zones/$zoneId/dns_records/$($bidRec.id)" `
  -H "Authorization: Bearer $env:CLOUDFLARE_API_TOKEN" -H "Content-Type: application/json" `
  -d '{"content":"mbfd-bid-web-staging.pages.dev"}'

# api.bid.mbfdhub.com is a Worker custom_domain — re-point by updating staging worker to also claim it:
# In apps/worker/wrangler.toml under [env.staging], temporarily add:
#   routes = [
#     { pattern = "api.staging.bid.mbfdhub.com", custom_domain = true },
#     { pattern = "api.bid.mbfdhub.com", custom_domain = true },
#   ]
# Then `pnpm exec wrangler deploy --env staging`.
```

## Step 3: Replay prod picks into staging (varies)
If any picks were committed in prod before rollback, they exist in `r2://mbfd-bid-prod-uploads/<year>/<session>/chunks/`.

```powershell
# 1. Download all chunks
pnpm exec wrangler r2 object list mbfd-bid-prod-uploads --prefix "2026/" --remote
# 2. For each chunk file, POST events to staging /admin/replay endpoint (idempotent)
# 3. Verify staging audit log matches prod for the affected session
```

The replay is idempotent — each event carries an `event_id` UUID; the staging audit writer skips duplicates.

## Step 4: Verify staging is now serving prod traffic
```powershell
curl -sI https://bid.mbfdhub.com/ | Select-String -Pattern "HTTP"  # should be 200, served by staging Pages
curl -s https://api.bid.mbfdhub.com/health  # should be 200, served by staging worker
```

## Step 5: Notify
- Ops chief calls the chiefs.
- Email to all members: "We have rolled the bid to backup infrastructure. Continue at https://bid.mbfdhub.com — no action needed."

## Post-rollback
- Open a `post-mortem-YYYY-MM-DD.md` in `docs/`.
- Hold a 24h review meeting.
- Decide: re-attempt cutover (after fixes) OR run remainder of event on staging-as-prod.
```

- [ ] **Step 2: Commit**

```bash
git add docs/rollback-runbook.md
git commit -m "docs(ops): rollback runbook for cutover failure"
```

**Rollback if fails:** doc-only; no runtime impact.

---

## Task 27: Rehearse the rollback (before Phase E)

**Goal:** Walk every step of `docs/rollback-runbook.md` against staging-as-fake-prod. Time each step.

**Files:** none changed. Operational task.

- [ ] **Step 1: Pre-arrange**

- Pick a 2h window with no other test activity.
- Operations chief is informed but not present (we want to test that the runbook is self-sufficient).

- [ ] **Step 2: Set up a fake "prod"**

Temporarily attach `bid.mbfdhub.com` to a throwaway Pages project so the rollback target is honest. Then start the timer.

- [ ] **Step 3: Walk every step**

Execute Step 1 → Step 5 of the runbook. Note times in `docs/rollback-rehearsal-log.md`:

```markdown
# Rollback rehearsal log — <DATE>
| Step | Time elapsed | Issue |
|---|---|---|
| Pre-flight | 0:00–0:30 | OK |
| Step 1 (503) | 0:30–1:10 | OK |
| Step 2 (DNS) | 1:10–6:20 | DNS prop took 4m, longer than expected — flag for runbook update |
| Step 3 (replay) | 6:20–18:00 | OK; 3 chunks replayed |
| Step 4 (verify) | 18:00–18:30 | OK |
| Step 5 (notify) | 18:30–19:00 | OK |
| Total | 19 min | Acceptable; target <30 min |
```

- [ ] **Step 4: Update the runbook with any findings**

If the rehearsal exposed missing steps or unclear wording, edit `docs/rollback-runbook.md` and re-commit.

- [ ] **Step 5: Restore prod**

Re-point DNS back to prod; redeploy prod worker (un-force-503).

- [ ] **Step 6: Commit the rehearsal log**

```bash
git add docs/rollback-rehearsal-log.md docs/rollback-runbook.md
git commit -m "docs(ops): rollback runbook rehearsal log + corrections"
```

**Rollback if fails:** if the rehearsal fails — i.e., we can't restore prod within 30 minutes — Plan 09 is **blocked**. The cutover does not proceed until a rerun is clean.

---

# Phase G — Post-cutover monitoring

## Task 28: Post-cutover monitoring playbook

**Goal:** A doc the on-call engineer follows for the 24 hours after cutover. Each dashboard, each query, each escalation threshold.

**Files:**
- Create: `docs/post-cutover-monitoring.md`

- [ ] **Step 1: Write the playbook**

```markdown
# Post-cutover monitoring playbook (24h window)

## Watch dashboards
1. **Cloudflare Workers analytics** (mbfd-bid-worker-production)
   - URL: https://dash.cloudflare.com/<account>/workers/services/view/mbfd-bid-worker-production/production/observability
   - Watch: request rate, error rate, CPU time, p95 latency
2. **Cloudflare Pages deployments** (mbfd-bid-web-prod)
   - Watch: deploy status (should be steady; no surprise deploys during freeze)
3. **D1 dashboard**
   - Watch: queries/sec, slow queries, storage growth
4. **R2 dashboard** (mbfd-bid-prod-uploads + -backups)
   - Watch: object count growth (audit chunks should grow ~1/30s during active bid)
5. **AI Gateway** (Anthropic)
   - Watch: tokens, cost (alert > $50/event)

## Watch queries (run every 15 min)

### Active picks per minute
```sql
-- Run via: wrangler d1 execute mbfd-bid-production --env production --remote --command="..."
SELECT strftime('%Y-%m-%d %H:%M', created_at, 'unixepoch') AS minute, COUNT(*) AS picks
FROM bids
WHERE created_at > strftime('%s', 'now', '-1 hour')
GROUP BY minute
ORDER BY minute DESC;
```

### Portal writeback queue depth
```sql
SELECT portal_sync_status, COUNT(*) AS n
FROM bids
WHERE created_at > strftime('%s', 'now', '-1 day')
GROUP BY portal_sync_status;
```
Healthy: 99%+ `synced`, < 1% `pending` or `failed`. **Escalate** if `failed > 0.5%`.

### Audit chunk integrity
```sql
SELECT seq, prev_chunk_hash IS NOT NULL AS chained
FROM snapshots
ORDER BY seq DESC LIMIT 5;
```
Run `curl -s https://api.bid.mbfdhub.com/admin/verify-chain` (requires admin JWT) and expect `{ok:true, last_verified_seq:N}`. **Escalate** if `ok:false`.

## Escalation thresholds (page IT lead)

| Metric | Healthy | Warn | Page |
|---|---|---|---|
| Worker error rate | < 0.5% | 0.5–1% | > 1% |
| Worker p95 latency | < 500ms | 500ms–1s | > 1s |
| Pick-confirm round-trip | < 500ms | 500ms–1s | > 1s |
| Portal sync `failed` | 0 | 1–5 | > 5 |
| Audit chain verify | ok | n/a | not ok |
| AI Gateway cost | < $30 | $30–50 | > $50 |
| D1 storage growth | linear | ~10% spike | > 25% spike |
| R2 audit chunks behind | 0 | 1–5 chunks | > 5 chunks behind |

## Communication channels
- Primary: ops Slack channel `#mbfd-bid-live`
- Backup: SMS rotation (3 engineers)
- Failsafe: phone tree managed by operations chief

## End-of-window step
After 24h with no `Page`-level escalation:
1. Tag release as `prod-cutover-stable`.
2. Unfreeze staging: `gh variable set STAGING_FROZEN --body "false"`.
3. Hold a 30-min post-cutover retrospective.
```

- [ ] **Step 2: Commit**

```bash
git add docs/post-cutover-monitoring.md
git commit -m "docs(ops): 24h post-cutover monitoring playbook"
```

**Rollback if fails:** doc-only.

---

## Final cutover checklist

A single condensed list, printed and on the operations chief's desk on A-Day:

```
[ ] Phase A complete: hardening landed on main (Tasks 1-8)
    [ ] wrangler tail clean
    [ ] CodeQL: 0 Critical, 0 High open
    [ ] rate-limit middleware live on staging
    [ ] CSP + security headers live on staging
    [ ] secrets inventory signed off
    [ ] D1 backup proven (backup + restore round-trip)
    [ ] pnpm audit clean
    [ ] E2E merge gate enforced on main

[ ] Phase B complete: prod resources provisioned (Tasks 9-16)
    [ ] mbfd-bid-production D1 exists; all migrations applied
    [ ] mbfd-bid-prod-sessions KV exists
    [ ] mbfd-bid-prod-uploads + -backups R2 exist
    [ ] mbfd-bid-web-prod Pages project exists
    [ ] All prod secrets set (worker + Pages)
    [ ] DNS: bid.mbfdhub.com CNAME -> Pages prod (cert active)
    [ ] DNS: api.bid.mbfdhub.com Worker custom_domain (cert active)
    [ ] First prod deploy succeeded

[ ] Phase C complete: data ready (Tasks 17-18)
    [ ] seed:prod ran cleanly; idempotent on re-run
    [ ] cutover-smoke.ps1 GREEN

[ ] Phase D complete: rehearsed (Tasks 19-21)
    [ ] A-Day-1 dry run with 2 real members signed off
    [ ] 100-concurrent-picks load test GREEN
    [ ] Maintenance fallback page tested

[ ] Phase F rehearsed BEFORE Phase E (Task 27)
    [ ] Rollback walk-through completed in < 30 min

[ ] Phase E executed (Tasks 22-25)
    [ ] Staging frozen 24h prior
    [ ] T-2h baseline backup taken; key recorded
    [ ] Final seed run; smoke GREEN
    [ ] Prod deploy GREEN; release tag pushed
    [ ] Cutover sign-off checklist initialed by ops chief + IT lead + lead engineer

[ ] Phase G live (Task 28)
    [ ] Monitoring playbook on the on-call's screen
    [ ] 24h watch window started
    [ ] Cloudflare alerts wired
```

---

## Rollback checklist (printed alongside the cutover checklist)

```
TRIGGER: error rate > 5% for 5 min OR ops chief verbal call OR write-back queue > 10 min stalled

[ ] Step 1: push 503 worker (rollback/<timestamp> branch); deploy
[ ] Step 2: re-point bid.mbfdhub.com DNS to staging Pages
[ ] Step 2: re-point api.bid.mbfdhub.com to staging worker (add to routes[])
[ ] Step 3: replay prod audit chunks from R2 into staging
[ ] Step 4: verify https://bid.mbfdhub.com serves staging surface
[ ] Step 5: notify chiefs + members
[ ] Post: open post-mortem doc; schedule 24h review
```

---

## Acceptance criteria

- [ ] All Critical and High CodeQL alerts on `main` are either fixed or dismissed with documented reason
- [ ] `wrangler tail` against staging during a 10-minute simulated session produces zero raw PII (5-digit IDs, IPv4, PIN, Authorization headers)
- [ ] Rate limit middleware blocks the 6th /auth/login attempt within 60s from a single IP (HTTP 429 with Retry-After)
- [ ] CSP, HSTS, X-Frame-Options, X-Content-Type-Options, Referrer-Policy, Permissions-Policy, COOP all present on every Worker and Pages response
- [ ] `docs/secrets-inventory.md` exists and is signed off by IT lead
- [ ] D1 backup + restore round-trip proven against a throwaway database (row count matches)
- [ ] `pnpm audit --prod --audit-level=high` returns clean on `main`
- [ ] Branch protection on `main` requires Lint, Unit+Integration, and Playwright E2E checks; enforced for admins
- [ ] `mbfd-bid-production` D1 created; all migrations applied; schema matches staging
- [ ] `mbfd-bid-prod-sessions` KV namespace created and round-trip smoked
- [ ] `mbfd-bid-prod-uploads` and `mbfd-bid-prod-backups` R2 buckets created and round-trip smoked
- [ ] `mbfd-bid-web-prod` Pages project created
- [ ] All prod worker secrets and Pages secrets set; values differ from staging where required (see inventory)
- [ ] `bid.mbfdhub.com` CNAME proxied to `mbfd-bid-web-prod.pages.dev`; cert status `active`
- [ ] `api.bid.mbfdhub.com` registered as Worker `custom_domain = true`; cert status `active`
- [ ] First prod deploy succeeded via `deploy-production.yml`
- [ ] `seed:prod` idempotent on re-run; counts match `analysis/personnel.csv` row count ±5
- [ ] `cutover-smoke.ps1` GREEN against prod
- [ ] A-Day-1 dry run with two real members documented and signed off
- [ ] 100-concurrent-picks load test: all 100 workers reach `/lobby` in < 15s; worker error rate < 0.5% during run
- [ ] Maintenance fallback page tested on staging
- [ ] Rollback runbook rehearsed end-to-end in < 30 min
- [ ] Cutover sign-off checklist initialed by all three roles
- [ ] Post-cutover monitoring playbook live for 24h with no `Page`-level escalation

---

## Notes for the engineer

- **Universal SSL ≠ Advanced Cert.** The `*.mbfdhub.com` Universal cert covers `bid.mbfdhub.com` (3rd level) but does NOT cover any 4th-level under it. That's why both `bid.mbfdhub.com` and `api.bid.mbfdhub.com` must go through the Workers/Pages custom-domain flow, which provisions a dedicated cert per host. Don't try to be clever with the zone's wildcard.
- **Pages secrets don't apply mid-flight.** Always do `wrangler pages secret put` immediately followed by a `wrangler pages deploy` to a no-op tag of `main`. Otherwise the new secret sits in the store while the running deployment keeps the old one.
- **Do not migrate to @opennextjs/cloudflare.** That path was evaluated against this app's RSC + middleware shape and abandoned. We are on `@cloudflare/next-on-pages` and we stay on it.
- **The R2 audit log is the legal record, not D1.** During rollback, D1 can be dropped and re-seeded from R2 chunks. Never the other way around. Re-read Plan 08 Notes for the engineer if this is unclear.
- **Staging stays alive through the bid.** That is the rollback target. Don't disable any staging resources during Phase E.
- **Secret hygiene during this plan.** Every `wrangler secret put NAME` invocation in this document pipes from a temp file in `$env:TEMP` that is deleted in the same line. If you find yourself echoing a value into the terminal, stop and use the `rotate-prod-secrets.ps1` flow instead.
- **The biggest risk is the cert provisioning delay.** Cloudflare typically issues a custom-domain cert in 2–15 minutes, but can take up to 24 hours on a bad day. Do Tasks 14 and 15 at least 48 hours before Phase E, not the morning of.
- **The second-biggest risk is the portal team's service-account tokens.** Those are issued by a different team; coordinate at least 1 week in advance. Both `PORTAL_BID_READER` and `PORTAL_BID_WRITER` need to be live in the portal's allowlist for the prod worker's outbound IP range.
- **Conventional commits, no Claude attribution.** Every commit message in this plan starts with a conventional type. No `Co-Authored-By` lines.
- **No emojis in source files or commit messages.** This is an MBFD project rule.
