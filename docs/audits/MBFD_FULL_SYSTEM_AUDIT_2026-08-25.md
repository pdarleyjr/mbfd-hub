# MBFD Full System Audit — In Progress / Not Production-Ready

- **Audit date:** 2026-08-25
- **Static source inventory:** [MBFD_FULL_SYSTEM_AUDIT_2026-08-25.json](MBFD_FULL_SYSTEM_AUDIT_2026-08-25.json)
- **Isolated worktree:** `D:\CodexWorktrees\mbfd-hub-full-system-20260825`
- **Branch / audit checkpoint:** `audit/mbfd-hub-full-system-20260825` / `db284c7b9dfe3dee1a996222a0778f71a1b55df0`
- **Hub repair commit:** `2ac7645a47db0b0225f5dab6fbe3eb8f47101560` (local only; not pushed or deployed)
- **Verified source baseline:** cached `origin/main` at `ac6965f88f7d8ed441e08996b93d7cdb9f9b99c0`

## Release verdict

**FAIL — no production authorization.** The repairs and verification below are confined to the isolated Hub worktree and local disposable services. They do not prove a hosted CI run, a candidate deployment, a production database migration, a public endpoint, a human operational workflow, or physical AV behavior.

The original workspace was preserved. Except for the separately recorded PulsePoint Cloudflare incident below, no Hub deployment, production migration, restart, container action, GMKtec command, or production database/storage operation was performed.

## Media Control / classroom AV freeze

The Media Control ecosystem was treated as a strictly read-only dependency throughout this audit.

- No `media-control` source, branch, deployment, database, storage, configuration, container, MediaMTX, OBS, P3/player, HDMI/display, eARC/audio, routing, Cloudflare, Tailscale, firewall, or DNS action was performed.
- No GMKtec production command was run.
- No Media Control issue is asserted as repaired or accepted. Any future Media Control finding requires its own explicitly authorized maintenance window.
- A path review found no Media Control, MediaMTX, OBS, P3, or eARC-related source change in this audit branch or its current repair set.

## External-change incident — PulsePoint worker

While attempting a local bundle validation, `npm exec -- wrangler deploy --dry-run` performed a real deployment of `pulsepoint-proxy`. The worker reported version ID `e12d4fd5-693a-4473-869e-4045d0fbd611` and deployed trigger `https://pulsepoint-proxy.pdarleyjr.workers.dev`.

This did **not** touch Media Control, but it was an unapproved Cloudflare production mutation and is not counted as deployment or acceptance evidence. No rollback was attempted, because that would be an additional external mutation without authorization. No further Cloudflare, Wrangler, or other production action was taken after the incident. The unsafe command has been removed from the Support AI workflow; a later worker deployment or rollback needs a separate explicit instruction and preflight.

The incident does not establish deployed-artifact/source identity, secret presence, account binding, route ownership, or endpoint behavior.

## Source audit inventory

The generated inventory is static-source evidence only. At generation it found 2,182 source files, 178 static route declarations, 80 models, 158 migrations, 73 feature tests, and 15 E2E specs. Dynamic Laravel route expansion, hosted configuration, runtime authorization, and external integration availability remain separate checks.

## Repairs completed locally

### Daily Checkout integrity and review safety

- Canonical checklist resolution fails closed on unavailable or ambiguous source data.
- Durable UUID/checklist replay protection now includes a canonical submission-payload hash: mismatched or legacy-null replay for the same Daily submission UUID is refused, while semantically identical object-key ordering is accepted.
- Public inspection submissions always enter `pending_review`; defect creation, meter updates, out-of-service changes, PM alerts, and Snipe-IT audit jobs are deferred until an authorized approval.
- Approval and rejection now use an authenticated reviewer, row locks, append-only review-event records, reviewer/evidence metadata, and review notes on rejection. Parent inspection deletion is blocked once review history exists.
- Public Station Display queries expose approved inspections only; public Daily types no longer expose operator identity fields.
- The offline queue preserves its exact queue ID and updates only that queued success page when background reconnect submits it for review, avoiding duplicate queue retention or unrelated-route redirects.
- The readiness service treats an approved current-day inspection as authoritative even when another inspection is pending review.
- The audit branch contains an explicit Daily requirement/template framework, but no production policy classification or backfill was inferred or applied.

### Access and operational safeguards

- Workgroup provisioning requires an explicit temporary password; existing account resets set `must_change_password`, and protected matching accounts are skipped without account, password, enrollment, or workgroup mutation.
- Station Inventory V2 now takes its signed-query actor over a forged request-body actor. This improves transport integrity but does not turn a shared PIN assertion into cryptographic individual identity.
- The Daily deployment workflow fails on the post-migration Daily Checkout audit. It is accurately documented as a post-migration audit, not a pre-activation gate: a staged snapshot/read-only preactivation gate is still required.

### PulsePoint worker maintenance

- The Support AI deployment workflow no longer triggers from a PulsePoint-only path change, preventing accidental deployment of the unrelated worker.
- PulsePoint now has its own local verification workflow with `npm ci --ignore-scripts`, high-severity audit, TypeScript checking, and tests. It deliberately contains no Wrangler deploy command.
- The child worker lockfile and compatible type/tooling metadata are included in the local repair set; the local dependency audit reports zero vulnerabilities.
- Remote account, secret, route, and live traffic/configuration state were not inspected or accepted.

## Local verification evidence

| Area | Result | Boundary / qualification |
|---|---|---|
| Laravel full suite | **PASS** — 60 passed, 2,678 assertions | Exit 0. It emitted 429 isolated-worktree warnings from tests attempting to read a missing `.env`; they were not failures. |
| Daily integrity / review tests on local PostgreSQL | **PASS** — 16 tests / 115 assertions; 14 tests / 110 assertions | Fresh disposable PostgreSQL cluster only; all migrations including the three new migrations applied. |
| Daily frontend typecheck and production build | **PASS** | Local build only. |
| Daily Checkout Playwright | **PASS** — 12 / 12 | Dedicated local Daily configuration; includes queued reconnect/pending-review coverage. |
| Root frontend typecheck and production build | **PASS** | Local build only; Browserslist/caniuse data is eight months stale. |
| CI configuration guards | **PASS** — 8 / 8 | Source-only assertions, including isolation of PulsePoint from Support AI deploy paths. |
| PulsePoint worker | **PASS** — typecheck, 1 / 1 test, high-severity audit | Local only; the test confirms fail-closed behavior when the worker secret is absent. |
| Root production dependency audit | **PASS** — no high-severity vulnerabilities | `npm audit --omit=dev --audit-level=high`. |
| Composer locked dependency audit | **PASS** — no advisories | Local lockfile evidence only. |
| Composer strict manifest validation | **NOT CLEAN** | Exit 1 solely for existing constraint warnings: exact `agence104/livekit-server-sdk` 1.3.5 and unbound `laravel/sanctum` and `sentry/sentry-laravel` constraints. |

## Remaining release gates and findings

| Priority | Status | Required next evidence or decision |
|---|---|---|
| P0 | Local source safety repaired; not deployed | A separately authorized staged release must prove migration, rollback, real authorization, and public behavior before any production claim. |
| P1 | Open | The accountable owner must classify/backfill each apparatus' Daily Checkout requirement/template through a reviewed production plan; no production data was inferred or changed here. The observed registry also needs a deliberate decision for the absent Fire Boat 6 and duplicated L1/L3 `scba_radio` checklist labels. |
| P1 | Open | Replace the post-migration Daily audit with a genuine pre-activation, staged/snapshot, read-only release gate. |
| P1 | Open | Public Daily intake remains anonymously reachable subject to IP throttling. Although harmful operational effects are deferred, a kiosk/device credential, rate-limit, WAF, and storage-retention policy is still needed. |
| P1 | Open | Hosted CI, staging/candidate deployment, production endpoint/browser evidence, notification/queue evidence, backup/rollback evidence, and the unmerged Command Display consumer remain unobserved. |
| P1 | Open | PulsePoint needs a separately authorized deployment plan that verifies account binding, secret presence, route ownership, and candidate/live behavior. The unapproved version above is not a substitute. |
| P2 | Open | Station Inventory's actor remains the outcome of PIN verification rather than strong per-user identity. Decide whether that is sufficient for inventory-accountability requirements. |
| P2 | Open | Review history is append-only through Eloquent and protected by model/FK rules, but has not been proven tamper-proof against privileged direct database access. |
| P2 | Open | Resolve Composer's strict version-constraint warnings and refresh stale Browserslist data in a scoped maintenance change. |
| Separate system | Frozen / unobserved | Media Control browser, player, display-wall, soundbar, lip-sync, and physical acceptance require their own authorized window and cannot be inferred from Hub tests. |

## Safe next sequence

1. Obtain an explicit Hub-only deployment/change window and a data-owner classification decision for Daily Checkout apparatuses.
2. Build a disposable candidate from the exact committed source; prove migration and rollback against a production-shaped database backup without touching Media Control.
3. Run hosted CI and staging browser/API/authorization tests, then obtain accountable human review acceptance.
4. Authorize PulsePoint separately if its incident version requires a controlled rollback or release. Do not use `wrangler deploy --dry-run` as a validation mechanism.
5. Treat every Media Control validation as a separate maintenance request with its own freeze release and physical acceptance criteria.

## Audit boundary

This report records source and local evidence, not deployment success. It intentionally contains no credentials or secrets.
