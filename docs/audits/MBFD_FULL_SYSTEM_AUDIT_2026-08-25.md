# MBFD Full System Audit — In Progress / Not Production-Ready

- **Audit dates:** 2026-08-25–2026-08-26
- **Static source inventory:** [MBFD_FULL_SYSTEM_AUDIT_2026-08-25.json](MBFD_FULL_SYSTEM_AUDIT_2026-08-25.json)
- **Isolated worktree:** `D:\CodexWorktrees\mbfd-hub-full-system-20260825`
- **Branch / audit checkpoint:** `audit/mbfd-hub-full-system-20260825` / `db284c7b9dfe3dee1a996222a0778f71a1b55df0`
- **Hub repair commit:** `2ac7645a47db0b0225f5dab6fbe3eb8f47101560` (local only; not pushed or deployed)
- **Verified source baseline:** cached `origin/main` at `ac6965f88f7d8ed441e08996b93d7cdb9f9b99c0`
- **Current resumed repair set:** local audit changes in this branch; not pushed or deployed.

## Release verdict

**FAIL — no production authorization.** The repairs and verification below are confined to the isolated Hub worktree and local disposable services. They do not prove a hosted CI run, a candidate deployment, a production database migration, a public endpoint, a human operational workflow, or physical AV behavior.

The original workspace was preserved. Except for the separately recorded PulsePoint Cloudflare incident below, no Hub deployment, production migration, restart, container action, GMKtec command, or production database/storage operation was performed.

## Media Control / classroom AV freeze

The Media Control ecosystem was treated as a strictly read-only dependency throughout this audit.

- No `media-control` source, branch, deployment, database, storage, configuration, container, MediaMTX, OBS, P3/player, HDMI/display, eARC/audio, routing, Cloudflare, Tailscale, firewall, or DNS action was performed.
- No GMKtec production command was run.
- No Media Control issue is asserted as repaired or accepted. Any future Media Control finding requires its own explicitly authorized maintenance window.
- A path review found no Media Control, MediaMTX, OBS, P3, or eARC-related source change in this audit branch or its current repair set.
- **P1 source-only finding / no repair:** Hub's `SyncToScreentinker` observer synchronously sends a captured plaintext password when an eligible user is saved or assigned a mirrored role, targeting the configured ScreenTinker/Media endpoint. It has no after-commit outbox boundary and logs non-2xx response bodies. This remains unchanged under the freeze; redesign requires a separately authorized Media Control maintenance window.
- Local PHPUnit is now isolated from both ScreenTinker and Workgroup AI: the four integration variables are forced blank in both the PHPUnit process and `$_SERVER`, and the base test case prevents any unfaked HTTP request. The regression injects harmless inherited sentinels and proves the test configuration still disables both integrations. This is test-only containment, not a production configuration change.

### Local test-isolation incident — Workgroup AI

Before the PHPUnit isolation repair, two local runs of the private-upload regression created a `.txt` `WorkgroupSharedUpload`. The observer schedules vectorization after a successful HTTP test response; Laravel's test kernel invokes those deferred callbacks. With no explicit test value, the service's source defaults to the public Workgroup AI Worker URL and permits an unauthenticated request. Therefore those runs **attempted** outbound vectorization with the local fixture text. Delivery, Worker response, and any Worker-side state mutation are **UNKNOWN** because no response/log trace was retained. This was not a Media Control command or Media Control runtime change.

No further such test was run before the repair. The current forced-empty environment plus global stray-request guard prevents a repeat even if a runner inherits real integration variables.

## External-change incident — PulsePoint worker

While attempting a local bundle validation, `npm exec -- wrangler deploy --dry-run` performed a real deployment of `pulsepoint-proxy`. The worker reported version ID `e12d4fd5-693a-4473-869e-4045d0fbd611` and deployed trigger `https://pulsepoint-proxy.pdarleyjr.workers.dev`.

This did **not** touch Media Control, but it was an unapproved Cloudflare production mutation and is not counted as deployment or acceptance evidence. No rollback was attempted, because that would be an additional external mutation without authorization. No further Cloudflare mutation or Wrangler action was taken after the incident. The unsafe command has been removed from the Support AI workflow in this local, unpushed audit branch; a later worker deployment or rollback needs a separate explicit instruction and preflight.

**Production impact: UNKNOWN / PENDING VERIFICATION.** The original CLI result was subsequently checked with Cloudflare read-only APIs and non-mutating endpoint methods, as documented below. That evidence proves deployment state, not healthy incident delivery.

### Read-only PulsePoint production-state evidence

Collected 2026-08-26 during this audit, without reading any secret value or changing Cloudflare configuration:

- Cloudflare confirms deployment `05a738b1-bdea-49f2-99c2-221db6a30048` created at `2026-08-26T03:00:14.795342Z` is the active 100%-traffic deployment of version `e12d4fd5-693a-4473-869e-4045d0fbd611`.
- The immediately previous 100%-traffic deployment is `8a999d8f-2ef0-429e-a230-51ff322e925b`, version `84ec9b22-5478-458e-b358-f7882ac613f9`, created at `2026-07-19T01:00:57.312356Z`.
- The Worker subdomain is `pdarleyjr.workers.dev`; the `pulsepoint-proxy` Workers.dev trigger is enabled. Across all three accessible Cloudflare zones there are no zone Worker routes for this script, and the script has no cron schedules.
- `PULSEPOINT_HASH_PASSWORD` is present as a `secret_text` binding in both the active and previous version. Its value was neither requested nor read.
- Cloudflare's current raw Worker download has response ETag `722ff87244c4dccfb009a3e144a69c2365c5d8169212e72c14b6f2879cb02b3c`; its extracted `index.js` module is 11,510 bytes with SHA-256 `61cea517e0c06b58773c9704c02cfff048197359c64459f93f1ff78497da4df4`. The downloaded active bundle contains the named secret, a fail-closed missing-secret guard, and no detected `PULSEPOINT_HASH_PASSWORD || …` fallback expression. This is evidence about the active bundle's observed structure, not a Git-commit mapping.
- The prior version's Cloudflare resource ETag is `6f7beffe2a720e12ec1978c84ff95ba87ab1c6945893776f0630521c51b1ff28`. It identifies that version resource but is not a Git SHA or a documented downloadable historical source artifact. No retained deploy-era local bundle, lockfile, or upload transcript establishes byte-for-byte source identity for either version.
- A cache-bypassing public `GET /` returned HTTP 200, `status: ok`, expected CORS, and `Cache-Control: no-store`. Non-GET `/incidents` checks returned the expected 405 (`HEAD`) and 204 (`OPTIONS`) without entering the feed path.
- A `GET /incidents` was deliberately **not** sent: the deployed Worker can call PulsePoint and write `caches.default`, so a cache miss is not strictly observational. Consequently, current incident-feed correctness, Hub receipt of live incident data, and whether an edge cache is currently masking a failure remain **UNKNOWN / PENDING**. The script's fixed Cache API key and the configuration change from prior TTL 15 to current TTL 30 make that distinction operationally material.

The local deploy safeguards are source-only: cached `origin/main` still contains the earlier broad Support AI trigger and unsafe dry-run command, and no GitHub push or repository-settings change has been made in this audit.

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
- `RedirectTrainingUsers` is now persisted with the existing forced-password middleware in the Admin Filament panel. This closes the reproduced case where a user who loaded Admin, then lost the Admin role, could otherwise issue a later Livewire update under the stale panel session.
- Real `/livewire/update` regressions cover Admin, Training, and Workgroup: an unrelated protected update is blocked while `must_change_password` is true; the allowed password-change update succeeds and removes the restriction; logout remains available. These are local HTTP/component tests, not hosted-browser acceptance.
- The inspection approval/rejection service now retries transient transaction conflicts three times. Its cache invalidation and follow-up job dispatches remain registered with `DB::afterCommit`; the new acceptance suite proves that they wait for the outer transaction and are suppressed by rollback.
- Station Inventory V2 now takes its signed-query actor over a forged request-body actor. This improves transport integrity but does not turn a shared PIN assertion into cryptographic individual identity.
- The Daily deployment workflow fails on the post-migration Daily Checkout audit. It is accurately documented as a post-migration audit, not a pre-activation gate: a staged snapshot/read-only preactivation gate is still required.
- The Employee Filament panel now persists the forced-password middleware. Actual Employee Livewire tests prove a flagged user cannot update the dashboard, can update only the password page, and can still log out; role-loss coverage now also exercises Training and Workgroup updates.
- The direct video-conferencing health route now requires `super_admin` or `admin`; local regression coverage proves no-role and training users receive 403 while an admin receives the health response. No LiveKit, conference, player, or Media Control runtime action was performed.
- The legacy direct Station Inventory PDF route now requires the same `super_admin`, `admin`, or `logistics_admin` role set as its existing API counterpart. A regular authenticated user receives 403; an inventory admin can still download the private PDF.

### Station inventory and TRT data integrity

- The legacy Station Inventory PDF template now consumes the API's validated `item_id` shape and correctly captures the category list inside its filter closure. The previously untested public submit path had returned 500 for a valid payload; the new local HTTP regression reaches PDF generation successfully.
- New Station Inventory PDFs use a ULID path instead of `station_id + time()`, preventing same-station/same-second filename reuse. The test asserts two submissions get distinct private ULID paths. Database-failure cleanup for a file already written remains an open follow-up.
- A forward migration now rejects an existing duplicate default TRT session before changing any data, then adds a PostgreSQL/SQLite partial unique index for `session_date WHERE trailer_id IS NULL`. `findOrCreateForToday()` now uses insert-or-ignore plus an exact nullable/non-null re-query so concurrent writers converge after the migration. The unobserved production duplicate preflight is a release gate; no production migration was attempted.

### PulsePoint worker maintenance

- The local audit branch's Support AI deployment workflow no longer triggers from a PulsePoint-only path change, preventing accidental deployment of the unrelated worker when this branch is eventually reviewed.
- The local audit branch now has a dedicated PulsePoint verification workflow with `npm ci --ignore-scripts`, high-severity audit, TypeScript checking, and tests. It deliberately contains no Wrangler deploy command.
- The child worker lockfile and compatible type/tooling metadata are included in the local repair set; the local dependency audit reports zero vulnerabilities.
- The read-only production-state evidence above is intentionally limited to version/configuration and a safe root health response. It does not accept live incident delivery, Hub receipt, cache contents, or byte-for-byte deployed-source provenance.

## Command Display Daily contract audit — separate, read-only

The separate `mbfd-command-display` worktree was inspected without source edits, deploys, network calls, or any Media Control action. The requested current-main worktree was already dirty and was preserved exactly; cached local `main`/`origin/main` is `48ce2c52069ca145df4fa356643d50c5530e6866`, not live remote proof.

- The candidate correctly consumes Hub `counts.daily_checkout` and the per-apparatus Daily matrix by `apparatus_id`, rather than deriving completion from client timestamps or unit names.
- **P1 semantic defect:** its dirty fixture/test treats `checked + attention + review_pending + not_checked` as `3 of 4` completed. Hub's authoritative service counts only approved `checked` and `attention`; that data must be `2 of 4`. The currently passing consumer test therefore demonstrates an incorrect policy result.
- Cached main has no committed Daily contract implementation. The candidate's Daily type/UI/fixture/test work is uncommitted, so it has no release or deployment evidence.
- Dormant `src/lib/readiness.ts` retains a divergent client-side readiness calculator with hard-coded weights. It is unreferenced today but must not become a fallback; missing Hub readiness must remain unavailable/unknown.
- The candidate's contract test is not wired into a deployment gate, has stale unused `big_ticket` schema expectation, and covers station detail but not the grid payload/fallback path. Hub API documentation also does not yet define the Daily schema or the rule that `review_pending` is not completed.

## Local verification evidence

| Area | Result | Boundary / qualification |
|---|---|---|
| Laravel full suite | **PASS** — 516 tests / 2,822 assertions; 3 skipped | Re-run during the resumed audit with forced-empty ScreenTinker/Workgroup AI configuration and global stray-Laravel-HTTP prevention. This is local source/test evidence only, not hosted CI or production acceptance. |
| Daily integrity / review tests on local PostgreSQL | **PASS** — 16 tests / 115 assertions; 14 tests / 110 assertions | Fresh disposable PostgreSQL cluster only; all migrations including the three new migrations applied. |
| Daily frontend typecheck and production build | **PASS** | Local build only. |
| Daily Checkout Playwright | **PASS** — 12 / 12 | Dedicated local Daily configuration; includes queued reconnect/pending-review coverage. |
| Root frontend typecheck and production build | **PASS** | Local build only; Browserslist/caniuse data is eight months stale. |
| Forced-password HTTP acceptance | **PASS** — 24 tests / 111 assertions | Real local `/livewire/update` requests across Admin, Employee, Training, and Workgroup, including role loss and allowed password/logout paths. No hosted session/browser evidence. |
| Video-conferencing health authorization | **PASS** — 12 tests / 57 assertions | Local route/controller coverage; proves only Hub authorization behavior, not LiveKit/Media Control runtime health. |
| Test integration isolation | **PASS** — 1 test / 4 assertions | Inherited sentinel values cannot enable ScreenTinker or Workgroup AI under PHPUnit, and stray Laravel HTTP is blocked globally. |
| Station Inventory private-PDF access and generation | **PASS** — 6 tests / 19 assertions | Local private-disk and HTTP coverage, including regular-user denial, valid public PDF rendering, and distinct ULID paths. No production storage/path migration evidence. |
| TRT default-session integrity | **PASS** — 3 tests / 8 assertions | Local SQLite schema proves the partial NULL uniqueness constraint and same-day public-submit convergence. A two-connection PostgreSQL race remains unrun. |
| Inspection approval transaction acceptance | **PASS** — 5 tests / 52 assertions | Local fresh SQLite schema. Covers repeated/interleaved terminal decisions, outer-commit timing, rollback, cache state, and one job dispatch per terminal approval; SQLite does not prove PostgreSQL locking. |
| PostgreSQL inspection decision locking | **NOT RUN** — 1 skipped | Syntax checked locally. The regression uses two connections and only runs with the explicit disposable-PostgreSQL test marker; it was correctly skipped under local SQLite. |
| CI configuration guards | **PASS** — 10 / 10 | Source-only assertions, including isolation of PulsePoint from Support AI deploy paths, refusal of deployment capability in the PulsePoint verification workflow, and execution of this regression suite by CI. |
| PulsePoint worker | **PASS** — typecheck, 1 / 1 test, high-severity audit | Local only; the test confirms fail-closed behavior when the worker secret is absent. |
| Root production dependency audit | **PASS** — no high-severity vulnerabilities | `npm audit --omit=dev --audit-level=high`. |
| Composer locked dependency audit | **PASS** — no advisories | Local lockfile evidence only. |
| Composer strict manifest validation | **NOT CLEAN** | Exit 1 solely for existing constraint warnings: exact `agence104/livekit-server-sdk` 1.3.5 and unbound `laravel/sanctum` and `sentry/sentry-laravel` constraints. |

## Remaining release gates and findings

| Priority | Status | Required next evidence or decision |
|---|---|---|
| P0 | Local source safety repaired; not deployed | A separately authorized staged release must prove migration, rollback, real authorization, and public behavior before any production claim. |
| P0 | Open / owner decision | Public station video-conferencing routes can create a guest launch context and issue a station token without device identity. If publicly reachable, a caller can claim a station role. Select a kiosk-bound credential, mTLS, or verified edge identity contract before changing Hub routes; do not touch classroom players, Cloudflare, network, or Media Control runtime under this freeze. |
| P1 | Open / safe containment available | Workgroup pages, API AI endpoints, reports, attendance, and direct IDs do not consistently prove that member, session, product, upload, and workgroup belong to the same tenant. Scope every record through `session.workgroup_id`, preserve existing global-admin behavior pending an owner decision, add Group-A/Group-B negative tests, and choose an explicit active-workgroup selector for multi-membership users. |
| P1 | Open | The accountable owner must classify/backfill each apparatus' Daily Checkout requirement/template through a reviewed production plan; no production data was inferred or changed here. The observed registry also needs a deliberate decision for the absent Fire Boat 6 and duplicated L1/L3 `scba_radio` checklist labels. |
| P1 | Open | Replace the post-migration Daily audit with a genuine pre-activation, staged/snapshot, read-only release gate. |
| P1 | Open | Public Daily intake remains anonymously reachable subject to IP throttling. Although harmful operational effects are deferred, a kiosk/device credential, rate-limit, WAF, and storage-retention policy is still needed. |
| P1 | Open / owner decision | Public station-request and legacy fire-equipment paths accept an arbitrary employee identity and persist/map it as requester. A genuine repair changes public/offline kiosk behavior: require the existing employee guard and derive identity server-side, then update UI/relogin/offline acceptance. Do not silently erase provenance or claim a caller-selected ID is authenticated. |
| P1 | Frozen / no repair | Hub's ScreenTinker observer synchronously mirrors a captured plaintext password to the Media Control-adjacent endpoint and logs non-2xx body content. A future approved redesign needs no plaintext mirror/logging plus post-commit durable delivery. Do not modify it or exercise it against a live endpoint under this freeze. |
| P1 | Open | Approval's one-terminal-decision and post-commit dispatch behavior are locally covered, but `AuditEquipmentAfterInspection` collapses Snipe lookup failures to an empty success-like result and ignores audit/status/maintenance result flags before sending a notification that implies success. Its 300-second timeout also exceeds source queue `retry_after` defaults (database 240 / Redis 90) and Supervisor's 60-second graceful stop, allowing overlapping non-idempotent work. A durable external-operation ledger/outbox and one coherent timeout/retry/shutdown contract are required before exactly-once claims. |
| P1 | Open / operations-gated | Source config puts cache, sessions, queue, and Reverb on one Redis with `allkeys-lru`; eviction can discard durable work/session state. The queue-status endpoint also counts a DB table even when production guidance selects Redis. Decide durable-workload topology/capacity, make status driver-aware, and prove pressure behavior in an isolated environment. Active production driver/backlog is unobserved. |
| P1 | Open / owner decision | Web-push subscriptions accept unvalidated endpoint/key data, can be claimed by another user, and a test route can send to it. Define supported browser endpoint hosts, require HTTPS and valid keys, preserve current endpoint owner on conflict, throttle, and test with no outbound delivery. |
| P1 | Open / data-classification decision | Workgroup uploads automatically extract and send document text to a public Worker default, permitting unauthenticated calls when no secret is configured. The two earlier local-test outbound attempts have unknown delivery/state; future source design needs explicit default-off/consent, required secret, and an after-commit durable job. |
| P1 | Open / integration-gated | Google Sheets apparatus sync clears then writes in separate steps, terminal-fails rather than using its declared retries, and enqueues full syncs for every apparatus change. Define staged/atomic publication and coalescing/idempotency with a fake Sheets client before any external-sheet change. |
| P1 | Open / operations-gated | Backup capture can fall back to a newest historical snapshot and restore smoke can validate a persistent older ID. Current exact-snapshot tests conflict with the source selection behavior. Capture the actual backup-run ID into a fresh atomic manifest, fail closed, prove freshness, then conduct a controlled restore; backup scope includes Media Control data, so defer live work under the freeze. |
| P1 | Local repair only | The new default-TRT-session migration must first prove production has no historical same-day `NULL trailer_id` duplicates, and its two-connection PostgreSQL race regression is not yet run. Do not infer production migration readiness from SQLite. |
| P1 | Local repair only | Station Inventory now prevents future filename collisions, but a database failure after the private PDF write can still leave an orphan file. Add failure cleanup/outbox sequencing and collision/failure test coverage before calling storage lifecycle complete. |
| P1 | Open | The PostgreSQL two-connection locking regression is checked in but has not run in a disposable PostgreSQL environment. Do not infer real production lock-contention acceptance from the local SQLite suite. |
| P1 | Open | Command Display has no committed, gated, semantically correct Daily contract. Correct the `review_pending` completion fixture, remove/prohibit the divergent client policy fallback, document/version the Hub schema, cover grid and detail payloads, and enforce the contract test before release. |
| P1 | Open | GitHub governance is absent: authenticated read-only API calls returned HTTP 404 for `main` branch protection and required-status-check resources; repository ruleset listing returned zero rulesets. Require pull requests, required CI checks, at least one accountable review/code-owner rule, dismissal of stale approvals, and block force-push/deletion before any production-ready claim. No repository setting was changed. |
| P1 | Open | Hosted CI, staging/candidate deployment, production endpoint/browser evidence, notification/queue evidence, backup/rollback evidence, and Command Display release evidence remain unobserved. |
| P1 | Open | PulsePoint deployment state, account binding, secret presence, Workers.dev trigger, and lack of zone/cron routes are now read-only verified. Actual `/incidents` delivery, Hub consumption, current cache contents, and deployed-source-to-Git identity remain pending because a feed GET can mutate the Worker cache. The unapproved version above is not a substitute for a controlled release. |
| P2 | Open | Station Inventory's actor remains the outcome of PIN verification rather than strong per-user identity. Decide whether that is sufficient for inventory-accountability requirements. |
| P2 | Open | Review history is append-only through Eloquent and protected by model/FK rules, but has not been proven tamper-proof against privileged direct database access. |
| P2 | Open | The direct queue-status route relies on `canAccessPanel('admin')`, which source indicates can admit training roles. Define intended queue-metadata visibility, apply role-specific authorization, and add a negative regression. |
| P2 | REVIEW_REQUIRED | User role editing exposes unfiltered target-role assignment while its policy is generic. The deployed Shield permission map was not inspected, so exploitability is not asserted; add an actor/target-role matrix test before any production claim. |
| P2 | Open | Resolve Composer's strict version-constraint warnings and refresh stale Browserslist data in a scoped maintenance change. |
| Separate system | Frozen / unobserved | Media Control browser, player, display-wall, soundbar, lip-sync, and physical acceptance require their own authorized window and cannot be inferred from Hub tests. |

## Safe next sequence

1. Maintain the Media Control freeze. Obtain an owner-selected station device-identity contract before any conference/station-token change; do not use a Hub release to alter players, Cloudflare, network, or AV behavior.
2. Apply and test the Hub-only Workgroup tenancy containment against Group-A/Group-B fixtures, then obtain decisions on global roles, member report visibility, and multi-workgroup selection.
3. Obtain an explicit Hub-only deployment/change window and data-owner classification for Daily Checkout, public station-request identity, Workgroup AI document egress, push-provider endpoint hosts, and external Snipe/Sheets semantics.
4. Build a disposable candidate from the exact committed source; prove the TRT duplicate preflight, migration, rollback, and two-connection PostgreSQL races against a production-shaped database backup without touching Media Control.
5. Run hosted CI and staging browser/API/authorization tests, then obtain accountable human review acceptance. Include queue/Redis, backup-freshness, external-integration, and private-file lifecycle evidence as separate gates.
6. Authorize PulsePoint separately if its incident version requires a controlled cache-safe behavioral probe, rollback, or release. Do not use `wrangler deploy --dry-run` as a validation mechanism.
7. Treat every Media Control validation as a separate maintenance request with its own freeze release and physical acceptance criteria.

## Audit boundary

This report records source and local evidence, not deployment success. It intentionally contains no credentials or secrets.
