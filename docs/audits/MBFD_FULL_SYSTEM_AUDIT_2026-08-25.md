# MBFD Full System Audit — In Progress / Not Production-Ready

- **Audit dates:** 2026-08-25–2026-08-26
- **Static source inventory:** [MBFD_FULL_SYSTEM_AUDIT_2026-08-25.json](MBFD_FULL_SYSTEM_AUDIT_2026-08-25.json)
- **Isolated worktree:** `D:\CodexWorktrees\mbfd-hub-full-system-20260825`
- **Pre-resume local baseline:** `audit/mbfd-hub-full-system-20260825` / `74b3020c4`
- **Published audit branch:** `origin/audit/mbfd-hub-full-system-20260825` (non-main publication only; no merge, deployment, or production action follows from it).
- **Draft pull request:** [#216](https://github.com/pdarleyjr/mbfd-hub/pull/216) into `main`; it is review/hosted-validation only and does not authorize merge or deployment.
- **Hosted-validation source checkpoint:** `5e7addd057c6f0efa341dee1f493778c37a776aa`; later commits update this evidence record only.
- **Initial published checkpoint:** `2612745572cb891b6fe03b74da72a608cbeb528e`; later local commits add the canonical Daily Checkout and Hub release-gate repairs recorded here.
- **Verified source baseline:** cached `origin/main` at `ac6965f88f7d8ed441e08996b93d7cdb9f9b99c0`
- **Current repair/evidence boundary:** isolated Hub-only source and local evidence, plus the named GitHub-hosted validation below. None is deployment, production, public-endpoint, or physical-AV evidence.

## Release verdict

**FAIL — not ready to merge or deploy.** GitHub-hosted validation is now observed: PHPStan passes at the named source checkpoint and named release-gate jobs pass as recorded below. There is still no all-green exact-SHA release-gate matrix because full-repository Pint fails; the sequential quality job therefore skipped its later Composer/root-frontend commands. It is not safe to claim a candidate deployment, a production database migration, a public endpoint, a human operational workflow, or physical AV behavior.

The original workspace was preserved. Except for the separately recorded PulsePoint Cloudflare incident below, no Hub deployment, production migration, restart, container action, GMKtec command, or production database/storage operation was performed.

## Media Control / classroom AV freeze

The Media Control ecosystem was treated as a strictly read-only dependency throughout this audit.

- No `media-control` source, branch, deployment, database, storage, configuration, container, MediaMTX, OBS, P3/player, HDMI/display, eARC/audio, routing, Cloudflare, Tailscale, firewall, or DNS action was performed.
- No GMKtec production command was run.
- No Media Control issue is asserted as repaired or accepted. Any future Media Control finding requires its own explicitly authorized maintenance window.
- A path review found no Media Control, MediaMTX, OBS, P3, or eARC-related source change in this audit branch or its current repair set.
- Hub/Media direct service separation is strongly supported by source and compose topology: the Hub release source names only `mbfd-hub-laravel`, `mbfd-hub-pgsql`, and `mbfd-hub-redis`, and targets only Hub `laravel.test` with `--no-deps`. It contains no Media Control, MediaMTX, OBS, or ScreenTinker activation action. This does **not** prove harmless shared-GMKtec resource contention during a Hub build/deploy; defer any Hub activation until after class.
- **P1 source-only finding / no repair:** Hub's `SyncToScreentinker` observer synchronously sends a captured plaintext password when an eligible user is saved or assigned a mirrored role, targeting the configured ScreenTinker/Media endpoint. It has no after-commit outbox boundary and logs non-2xx response bodies. This remains unchanged under the freeze; redesign requires a separately authorized Media Control maintenance window.
- Local PHPUnit is now isolated from both ScreenTinker and Workgroup AI: the four integration variables are forced blank in both the PHPUnit process and `$_SERVER`, and the base test case prevents any unfaked HTTP request. The regression injects harmless inherited sentinels and proves the test configuration still disables both integrations. This is test-only containment, not a production configuration change.

### Local test-isolation incident — Workgroup AI

Before the PHPUnit isolation repair, two local runs of the private-upload regression created a `.txt` `WorkgroupSharedUpload`. The observer schedules vectorization after a successful HTTP test response; Laravel's test kernel invokes those deferred callbacks. With no explicit test value, the service's source defaults to the public Workgroup AI Worker URL and permits an unauthenticated request. Therefore those runs **attempted** outbound vectorization with the local fixture text. Delivery, Worker response, and any Worker-side state mutation are **UNKNOWN** because no response/log trace was retained. This was not a Media Control command or Media Control runtime change.

No further such test was run before the repair. The current forced-empty environment plus global stray-request guard prevents a repeat even if a runner inherits real integration variables.

## External-change incident — PulsePoint worker

While attempting a local bundle validation, `npm exec -- wrangler deploy --dry-run` performed a real deployment of `pulsepoint-proxy`. The worker reported version ID `e12d4fd5-693a-4473-869e-4045d0fbd611` and deployed trigger `https://pulsepoint-proxy.pdarleyjr.workers.dev`.

This did **not** touch Media Control, but it was an unapproved Cloudflare production mutation and is not counted as deployment or acceptance evidence. No rollback was attempted, because that would be an additional external mutation without authorization. No further Cloudflare mutation or Wrangler action was taken after the incident. The unsafe command has been removed from the Support AI workflow in this audit branch; a later worker deployment or rollback needs a separate explicit instruction and preflight.

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

## Scoped UI code audit — provisional

Scope is limited to the active Daily Station Detail source and the Workgroup Links view. This is a source-only review against the documented MBFD operational design context; it is not a browser, contrast, screen-reader, touch-device, or physical acceptance result.

| Dimension | Score | Evidence / key finding |
|---|---:|---|
| Accessibility | 2/4 | Daily source uses visible focus styles, 48px minimum controls, reduced-motion handling, and safe-area rules; rendered contrast, semantic reading order, and keyboard flow remain untested. |
| Performance | 2/4 | The local Vite build code-splits, but produces a 1.0 MB PDF worker and a 489 KB LiveKit chunk; no performance budget or real-device measurement is established. |
| Responsive | 3/4 | Daily source includes mobile touch/safe-area patterns and the Station Detail cards use 48px minimum controls; no phone/iPad browser acceptance has been run. |
| Theming | 1/4 | The active Workgroup Links view repeats large inline style blocks and hard-coded cool grays/blue/purple colors, bypassing the documented warm-neutral/token system. |
| Anti-patterns | 1/4 | Links is a repeated generic card grid with imperative mouse/focus handlers and decorative shadows rather than reusable, tokenized components. |
| **Total** | **9/20** | **Poor — source-only remediation required before claiming a polished operational UI.** |

The P0 Station Detail compliance-matrix gap above is functional as well as UX-critical. Keep the global-only Links restriction, but defer any visual refactor until after Daily compliance semantics and the active Workgroup/report policy are settled.

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

### Daily Checkout canonical-contract repair — local only

- The exclusive canonical states are now `checked`, `attention`, `review_pending`, `not_checked`, `out_of_service`, `exempt`, and `classification_required`. `completed = checked + attention`; `required_total = checked + attention + review_pending + not_checked`; zero denominator is explicitly unavailable rather than presented as 100%. Out-of-service, exempt, and classification-required remain visible separately and never inflate completion.
- Station Detail, Station Operations, the public station payload, Display Snapshot/Readiness, and the Daily audit command now consume the single server-side per-apparatus canonical result. Raw inspection-history responses are explicitly marked history-only and are not a readiness fallback.
- The append-only operational-status ledger supplies exact UTC return proof: a same-day out-of-service-to-in-service apparatus requires a qualifying approved checkout strictly **after** the return event. A stale or equal-timestamp approval cannot qualify.
- This is a source repair, not an historical-data repair. Pre-ledger OOS-return episodes cannot be reconstructed from generic `updated_at`, and raw SQL bypasses Eloquent ledger emission. Before activation, run a staged read-only Daily data/cutover gate and manually resolve ambiguous same-day historical returns; never infer a return from generic timestamps.
- The tracked checklist resolver and public endpoint still fail closed on missing, invalid, or ambiguous mappings. L1/L3 duplicate radio labels and live required/template classification remain a release hold until a fresh, authorized data-policy preflight proves the intended classification and corrected checklist content.

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

### Workgroup tenant containment and report access

- Active Workgroup resources, pages, relation managers, downloads, report exports, and AI endpoints now use default-deny membership/workgroup/session scoping. The selected workgroup is explicit for a multi-workgroup member; no active-session or first-membership fallback is accepted for the hardened active paths.
- Active member-scoped report/download routes remain behind `workgroup.access` and a selected, authorized session. Cached reports can be read without making an external AI call; GET cache misses return not found rather than generating content.
- Seven legacy ownerless report views now require explicit `workgroup.global_access` (or `super_admin`) rather than ordinary workgroup membership. The active Workgroup Links page is likewise hidden from ordinary members, so it no longer advertises links that would return an authorization failure.
- The four registered custom widgets resolve the selected workgroup instead of `active()->first()`. The custom dashboard source does not currently render those widgets, so this is registration/future-use hardening, not browser-render acceptance.
- Attendance, file, member, and session relation managers are manager-only and revalidate their owner workgroup. Direct-ID regressions cover cross-workgroup sessions, uploads, recipients, reports, evaluation form state, and report export requests.
- The original Notes migration omitted `is_shared` and `shared_with_user_id` even though the page/model wrote them. A new additive, data-preserving migration adds both fields; a private-note regression first reproduced the missing-column failure and then passed locally. A code rollback intentionally leaves the additive data columns intact rather than risking deletion of pre-existing sharing data.
- `AdminDashboard`, five legacy exporters, two legacy widgets, and the old AdminDashboard template remain unregistered. They must not be reactivated without explicit session scoping: several use global `active()->first()` fallbacks, three static exporter column definitions capture `$this`, and the AI exporter can invoke external analysis on a cache miss.

### Station inventory and TRT data integrity

- The legacy Station Inventory PDF template now consumes the API's validated `item_id` shape and correctly captures the category list inside its filter closure. The previously untested public submit path had returned 500 for a valid payload; the new local HTTP regression reaches PDF generation successfully.
- New Station Inventory PDFs use a ULID path instead of `station_id + time()`, preventing same-station/same-second filename reuse. The test asserts two submissions get distinct private ULID paths. Database-failure cleanup for a file already written remains an open follow-up.
- A forward migration now rejects an existing duplicate default TRT session before changing any data, then adds a PostgreSQL/SQLite partial unique index for `session_date WHERE trailer_id IS NULL`. `findOrCreateForToday()` now uses insert-or-ignore plus an exact nullable/non-null re-query so concurrent writers converge after the migration. The unobserved production duplicate preflight is a release gate; no production migration was attempted.

### PulsePoint worker maintenance

- The local audit branch's Support AI deployment workflow no longer triggers from a PulsePoint-only path change, preventing accidental deployment of the unrelated worker when this branch is eventually reviewed.
- The local audit branch now has a dedicated PulsePoint verification workflow with `npm ci --ignore-scripts`, high-severity audit, TypeScript checking, and tests. It deliberately contains no Wrangler deploy command.
- The child worker lockfile and compatible type/tooling metadata are included in the local repair set; the local dependency audit reports zero vulnerabilities.
- The read-only production-state evidence above is intentionally limited to version/configuration and a safe root health response. It does not accept live incident delivery, Hub receipt, cache contents, or byte-for-byte deployed-source provenance.

## Command Display Daily contract audit — isolated local repair, unpushed

The separate `mbfd-command-display` worktree was kept isolated from the dirty requested `main` worktree and from all production/Media Control systems. Cached local `main`/`origin/main` is `48ce2c52069ca145df4fa356643d50c5530e6866`, not live remote proof.

- The isolated local branch corrects the canonical fixture to one each of `checked`, `attention`, `review_pending`, and `not_checked`: `completed = 2`, `required_total = 4`, and 50%. It accepts only the Hub's canonical state set and does not derive readiness from client timestamps or unit names.
- Grouped Hub inspection/request arrays now adapt to the existing activity feed as history only. The activity panel deliberately does not read `daily_checkout`; missing Hub readiness remains unavailable/unknown, not a client-side fallback.
- Source typecheck, lint, build, whitespace check, and the focused mocked Daily browser trio passed locally. The full local mock suite has seven passes and two unrelated AI-content prose assertion failures.
- The Command Display branch is intentionally unpushed and undeployed. Its source checks are not production, physical-display, or classroom acceptance evidence.

## Local verification evidence

| Area | Result | Boundary / qualification |
|---|---|---|
| Laravel full suite — PHP 8.4 | **PASS** — 569 tests / 3,169 assertions; 6 skipped | Final source state, isolated worktree only. The skips are disposable-PostgreSQL-only under the normal SQLite run; this is not hosted CI or production acceptance. |
| Laravel full suite — PHP 8.5 | **PASS** — 569 tests / 3,169 assertions; 6 skipped | Final source state, isolated worktree only; establishes local interpreter compatibility, not production runtime proof. |
| PostgreSQL approval/concurrency group — PHP 8.4 and 8.5 | **PASS** — 5 tests / 29 assertions on each interpreter | Fresh disposable loopback-only PostgreSQL 15 fixture. The new status-ledger fixture cleanup was exercised, then the fixture was stopped; it was never a GMKtec or production database. |
| Daily Checkout typecheck and production build | **PASS** | Re-run locally in the isolated worktree; generated `/daily` assets and application-owned service worker were produced locally only. |
| Daily Checkout Playwright | **PASS** — 14 / 14 | Dedicated Chromium configuration uses a disposable loopback build and mocks every API request. It is a browser contract test, not candidate/public/device acceptance. |
| Root frontend typecheck and production build | **PASS** | Re-run locally with Sentry upload/release variables explicitly blank. The Vite build bundled but did not run LiveKit or Media Control. Browserslist/caniuse data remains eight months stale. |
| Operational Forms typecheck | **PASS** | Local static evidence only. |
| Generated asset guard | **PASS** | Fresh root Vite, Filament, and Daily assets exist locally; no generated output is tracked by Git, and all manifest-referenced outputs exist. |
| CI configuration guards | **PASS** — 18 / 18 | Source-only assertions cover manual/main/environment deployment protection, exact reusable gates, ephemeral Composer job-token use, Daily loopback/mocking isolation, and disabled ordinary-CI Sentry capability. |
| Composer manifest and locked advisory audit | **PASS** | `composer validate --strict --no-check-publish` and `composer audit --locked --format=table` pass with no advisory. |
| Root and Daily high-severity dependency audits | **PASS** | Both `npm audit --audit-level=high` invocations report zero vulnerabilities. |
| Changed Daily/Display PHP formatting | **PASS** — 10 files | Scoped Pint pass for the changed Daily/Display source and ledger fixture. |
| Full repository Pint | **FAIL** — 298 nonconforming files | Broad inherited repository formatting debt remains. No unrelated mass formatting was performed; this blocks the hard full-Pint release gate. |
| PHPStan (local) | **UNVERIFIED locally** | The locked PHPStan archive is unavailable locally. Hosted execution is separately recorded below. |
| Forced-password HTTP acceptance | **PASS** — 24 tests / 111 assertions | Real local `/livewire/update` requests across Admin, Employee, Training, and Workgroup, including role loss and allowed password/logout paths. No hosted session/browser evidence. |
| Video-conferencing health authorization | **PASS** — 12 tests / 57 assertions | Local route/controller coverage; proves only Hub authorization behavior, not LiveKit/Media Control runtime health. |
| Test integration isolation | **PASS** — 1 test / 4 assertions | Inherited sentinel values cannot enable ScreenTinker or Workgroup AI under PHPUnit, and stray Laravel HTTP is blocked globally. |
| Station Inventory private-PDF access and generation | **PASS** — 6 tests / 19 assertions | Local private-disk and HTTP coverage, including regular-user denial, valid public PDF rendering, and distinct ULID paths. No production storage/path migration evidence. |
| Workgroup tenant/report/Notes focused suite | **PASS** — 30 tests / 121 assertions | Local SQLite/Livewire/route evidence for active hardened surfaces. It does not prove hosted browser behavior, legacy dormant exporters, or a global-admin policy decision. |
| Workgroup route-scope regression | **PASS** — 4 tests / 76 assertions | Local route metadata confirms member-scoped vs explicit-global report middleware. |
| Disposable PostgreSQL approval/TRT integrity | **PASS** — 5 tests / 29 assertions | Dedicated loopback-only PostgreSQL 15 cluster; its test database/user naming and bootstrap guard reject any non-disposable target. This does not prove a production duplicate preflight or deployment migration. |
| Inspection approval transaction acceptance | **PASS** — 5 tests / 52 assertions | Local fresh SQLite schema. Covers repeated/interleaved terminal decisions, outer-commit timing, rollback, cache state, and one job dispatch per terminal approval; SQLite does not prove PostgreSQL locking. |
| PulsePoint worker | **PASS** — typecheck, 1 / 1 test, high-severity audit | Local only; the test confirms fail-closed behavior when the worker secret is absent. |

## GitHub-hosted validation evidence — draft PR #216

The following is hosted CI evidence for source checkpoint `5e7addd057c6f0efa341dee1f493778c37a776aa`; it is not candidate, production, public-browser, device, human, or physical-AV acceptance.

| Area | Result | Boundary / qualification |
|---|---|---|
| [Static Analysis / PHPStan](https://github.com/pdarleyjr/mbfd-hub/actions/runs/32983487495) | **PASS** — 0 reported errors | Hosted Composer authentication uses an ephemeral job token. The local PHPStan archive remains unavailable, so this is the authoritative static-analysis proof for the checkpoint. |
| Named release-gate jobs | **PASS as observed** | CI configuration, generated assets, PHPStan, PHPUnit/PostgreSQL concurrency-integrity, Daily Checkout contract/integrity, dependency security, filesystem/config security, and PHP 8.5 compatibility passed in the [release-gates run](https://github.com/pdarleyjr/mbfd-hub/actions/runs/32983487924). These are CI-source gates only. |
| PHP and root frontend quality | **FAIL** — full Pint | PHP lint passed, then `vendor/bin/pint --test` reported 298 unique nonconforming inherited PHP paths (311 rendered rows). Composer lock/audit and root TypeScript typecheck/build were skipped by that sequential job, so their hosted status is unobserved here rather than failed. No mass formatting was performed. |

## Remaining release gates and findings

| Priority | Status | Required next evidence or decision |
|---|---|---|
| P0 | Local source safety repaired; not deployed | A separately authorized staged release must prove migration, rollback, real authorization, and public behavior before any production claim. |
| P0 | Open / release hold | Candidate activation source is now manual, main-only, confirmation-gated, exact-SHA-gated, and production-environment-gated. It still lacks a Daily data/cutover preactivation gate; named hosted CI evidence is now observed, but the matrix is not all green because Pint fails. Protection, approval, candidate, and runtime evidence remain unobserved. Keep all Hub activation after class because direct Media separation does not prove harmless shared-host resource contention. |
| P0 | Local repair / data-cutover required | Out-of-service can no longer inflate completed Daily compliance in source. The exact seven-state matrix and denominator arithmetic are covered locally, but pre-ledger OOS-return history and raw-SQL changes require a staged, read-only manual classification/cutover review before activation. |
| P0 | Local repair / proof required | Station Detail, Station Operations, public station data, Display Snapshot/Readiness, and the audit command now consume the canonical Daily result. The repair remains undeployed; mocked hosted/browser contract evidence does not replace candidate or public acceptance. |
| P0 | Open / owner decision | Public station video-conferencing routes can create a guest launch context and issue a station token without device identity. If publicly reachable, a caller can claim a station role. Select a kiosk-bound credential, mTLS, or verified edge identity contract before changing Hub routes; do not touch classroom players, Cloudflare, network, or Media Control runtime under this freeze. |
| P1 | Local repair / owner decision | Active Workgroup pages, routes, relation managers, uploads, report exports, and registered widgets now have Group-A/Group-B coverage and explicit multi-workgroup context. Decide the intended global-admin workflow before adding any global selection UI: a global viewer can access global resources/static reports, while member-centric pages intentionally require an active membership. Keep dormant legacy dashboard/exporter/widget/template code unregistered until it has selected-session scoping and cache-only AI behavior. |
| P1 | Open | The accountable owner must classify/backfill each apparatus' Daily Checkout requirement/template through a reviewed production plan; no production data was inferred or changed here. The observed registry also needs a deliberate decision for the absent Fire Boat 6 and duplicated L1/L3 `scba_radio` checklist labels. |
| P1 | Open / operations-gated | `deploy.yml` still has no genuine pre-activation staged/snapshot Daily cutover audit. Add and execute that gate only in an approved Hub-only release window; never infer historical return state from generic timestamps. |
| P1 | Local repair / evidence incomplete | Daily typecheck, production build, and mocked Chromium contract tests are now hard reusable-gate jobs. They mock every API request, so they do not replace a contained candidate API, browser/device, human, or physical acceptance test. |
| P1 | Open | Public Daily intake remains anonymously reachable subject to IP throttling. Although harmful operational effects are deferred, a kiosk/device credential, rate-limit, WAF, and storage-retention policy is still needed. |
| P1 | Open / owner decision | Public station-request and legacy fire-equipment paths accept an arbitrary employee identity and persist/map it as requester. A genuine repair changes public/offline kiosk behavior: require the existing employee guard and derive identity server-side, then update UI/relogin/offline acceptance. Do not silently erase provenance or claim a caller-selected ID is authenticated. |
| P1 | Frozen / no repair | Hub's ScreenTinker observer synchronously mirrors a captured plaintext password to the Media Control-adjacent endpoint and logs non-2xx body content. A future approved redesign needs no plaintext mirror/logging plus post-commit durable delivery. Do not modify it or exercise it against a live endpoint under this freeze. |
| P1 | Open | Approval's one-terminal-decision and post-commit dispatch behavior are locally covered, but `AuditEquipmentAfterInspection` collapses Snipe lookup failures to an empty success-like result and ignores audit/status/maintenance result flags before sending a notification that implies success. Its 300-second timeout also exceeds source queue `retry_after` defaults (database 240 / Redis 90) and Supervisor's 60-second graceful stop, allowing overlapping non-idempotent work. A durable external-operation ledger/outbox and one coherent timeout/retry/shutdown contract are required before exactly-once claims. |
| P1 | Open / operations-gated | Source config puts cache, sessions, queue, and Reverb on one Redis with `allkeys-lru`; eviction can discard durable work/session state. The queue-status endpoint also counts a DB table even when production guidance selects Redis. Decide durable-workload topology/capacity, make status driver-aware, and prove pressure behavior in an isolated environment. Active production driver/backlog is unobserved. |
| P1 | Open / owner decision | Web-push subscriptions accept unvalidated endpoint/key data, can be claimed by another user, and a test route can send to it. Define supported browser endpoint hosts, require HTTPS and valid keys, preserve current endpoint owner on conflict, throttle, and test with no outbound delivery. |
| P1 | Open / data-classification decision | Workgroup uploads automatically extract and send document text to a public Worker default, permitting unauthenticated calls when no secret is configured. The two earlier local-test outbound attempts have unknown delivery/state; future source design needs explicit default-off/consent, required secret, and an after-commit durable job. |
| P1 | Open / integration-gated | Google Sheets apparatus sync clears then writes in separate steps, terminal-fails rather than using its declared retries, and enqueues full syncs for every apparatus change. Define staged/atomic publication and coalescing/idempotency with a fake Sheets client before any external-sheet change. |
| P1 | Open / operations-gated | Backup capture can fall back to a newest historical snapshot and restore smoke can validate a persistent older ID. Current exact-snapshot tests conflict with the source selection behavior. Capture the actual backup-run ID into a fresh atomic manifest, fail closed, prove freshness, then conduct a controlled restore; backup scope includes Media Control data, so defer live work under the freeze. |
| P1 | Local repair only | The new default-TRT-session migration passed a disposable two-connection PostgreSQL test, but production must first prove it has no historical same-day `NULL trailer_id` duplicates. Do not infer production migration readiness from local data. |
| P1 | Local repair only | Station Inventory now prevents future filename collisions, but a database failure after the private PDF write can still leave an orphan file. Add failure cleanup/outbox sequencing and collision/failure test coverage before calling storage lifecycle complete. |
| P1 | Local proof only | The PostgreSQL same-record inspection approval-lock regression passed in the dedicated loopback database with bounded retry behavior. It is not production lock-contention, worker, or external-Snipe acceptance. |
| P1 | Local isolated repair / unpublished | Command Display's isolated branch now uses the canonical 2-of-4 fixture and history-only activity adapter; focused local mock tests pass. It remains unpushed, ungated, and undeployed, with two unrelated AI-prose mock failures in its full suite. |
| P1 | Open | GitHub governance is absent: authenticated read-only API calls returned HTTP 404 for `main` branch protection and required-status-check resources; repository ruleset listing returned zero rulesets. Require pull requests, required CI checks, at least one accountable review/code-owner rule, dismissal of stale approvals, and block force-push/deletion before any production-ready claim. No repository setting was changed. |
| P1 | Hosted static-analysis pass / release gate blocked | Larastan/PHPStan is declared and locked, Composer CI uses an ephemeral job token rather than a persisted Composer secret, and hosted PHPStan passes with zero errors at source checkpoint `5e7addd057c6f0efa341dee1f493778c37a776aa`. The locked archive remains absent locally. Full repository Pint still fails on 298 inherited nonconforming files, so the release gate remains blocked. |
| P1 | Open | Observability runs `npm install` and creates a Sentry release on every `main` push without waiting for deployment success. Treat Sentry release metadata as non-provenance until the workflow is lockfile-reproducible and linked to a successful immutable deployment. |
| P1 | Open | The production Compose/deploy path still builds from a Sail runtime. Local PHP 8.4/8.5 suites pass, but the exact immutable production image/interpreter and candidate SHA remain un-rehearsed. |
| P1 | Frozen / source repair only | Hub deploy source now verifies the exact named Hub container/database/user, nonempty `pg_dump`, and `pg_restore --list` before activation. No production backup, restore, container, or Media-associated storage action was run; backup/rollback proof remains pending. |
| P1 | Open / unverified dependency | The Workgroup AI Worker lacks a lockfile and local test/typecheck script and is not covered by the listed Worker Dependabot directories. Do not deploy or exercise it as part of this Hub release. |
| P1 | Hosted CI partially observed / release gate blocked | Named draft-PR hosted jobs now provide CI evidence, but no all-green current-SHA gate exists because Pint fails. Staging/candidate deployment, production endpoint/browser, notification/queue, backup/rollback, and Command Display release evidence remain unobserved. |
| P1 | Open | PulsePoint deployment state, account binding, secret presence, Workers.dev trigger, and lack of zone/cron routes are now read-only verified. Actual `/incidents` delivery, Hub consumption, current cache contents, and deployed-source-to-Git identity remain pending because a feed GET can mutate the Worker cache. The unapproved version above is not a substitute for a controlled release. |
| P2 | Open | Station Inventory's actor remains the outcome of PIN verification rather than strong per-user identity. Decide whether that is sufficient for inventory-accountability requirements. |
| P2 | Open | Review history is append-only through Eloquent and protected by model/FK rules, but has not been proven tamper-proof against privileged direct database access. |
| P2 | Open | The direct queue-status route relies on `canAccessPanel('admin')`, which source indicates can admit training roles. Define intended queue-metadata visibility, apply role-specific authorization, and add a negative regression. |
| P2 | REVIEW_REQUIRED | User role editing exposes unfiltered target-role assignment while its policy is generic. The deployed Shield permission map was not inspected, so exploitability is not asserted; add an actor/target-role matrix test before any production claim. |
| P2 | Dormant legacy code | `WorkgroupNote::workgroup()` declares a direct relationship although the table has no `workgroup_id`, and the legacy AdminDashboard exporters/templates have no active render path. No current call site to the Note relation was found. Correct/remove it only with a relation API compatibility decision; do not re-register dormant code as a workaround. |
| P2 | Open | Composer strict version-constraint validation is now clean. Refresh stale Browserslist data in a separately scoped maintenance change. |
| Separate system | Frozen / unobserved | Media Control browser, player, display-wall, soundbar, lip-sync, and physical acceptance require their own authorized window and cannot be inferred from Hub tests. |

## Safe next sequence

1. Maintain the Media Control freeze. Obtain an owner-selected station device-identity contract before any conference/station-token change; do not use a Hub release to alter players, Cloudflare, network, or AV behavior.
2. Before activation, run an owner-approved staged, read-only Daily data/cutover gate: verify current classification/checklist evidence, classify or manually resolve pre-ledger same-day OOS-return ambiguity, and prove no raw-history/timestamp fallback can inflate readiness.
3. Preserve the current Workgroup containment. Obtain the global-viewer and selected-workgroup/session policy before reactivating legacy dashboards, exporters, or widgets.
4. After class, obtain an explicit Hub-only deployment/change window and data-owner classification for Daily Checkout, public station-request identity, Workgroup AI document egress, push-provider endpoint hosts, and external Snipe/Sheets semantics. Keep the independent `production-activate.yml` path out of the window unless it is brought behind equivalent gates.
5. Build a disposable candidate from the exact committed source; prove the Daily data/cutover preflight, TRT duplicate preflight, migration/rollback plan, and production-shaped restore path without touching Media Control.
6. Complete an all-green hosted CI matrix for the exact immutable candidate SHA, then run staging browser/API/authorization tests and accountable human review. Include queue/Redis, backup-freshness, external-integration, private-file lifecycle, and Command Display publication evidence as separate gates.
7. Authorize PulsePoint separately if its incident version requires a controlled cache-safe behavioral probe, rollback, or release. Do not use `wrangler deploy --dry-run` as a validation mechanism.
8. Treat every Media Control validation as a separate maintenance request with its own freeze release and physical acceptance criteria.

## Audit boundary

This report records source and local evidence, not deployment success. It intentionally contains no credentials or secrets.
