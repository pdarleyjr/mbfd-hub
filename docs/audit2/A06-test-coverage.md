# A06 — MBFD Hub test architecture, E2E, regression, and release-acceptance audit

## Task result

| Field | Value |
| --- | --- |
| Task ID | A06 |
| Status | **COMPLETE — architecture audit; NOT a release approval** |
| Model | GPT-5.6 Terra High (per task brief) |
| Branch | `audit2/A06-test-coverage` |
| Base commit | `3cbea3c95b9bf4333b9830f9bcec749da7ff28eb` |
| Final commit | Uncommitted audit artifact at review time |
| Audit artifact | `docs/audit2/A06-test-coverage.md` |
| Scope | Source/configuration audit plus isolated local validation only. No application implementation, deployment, production access, or production data operation. |

### Required return summary

| Field | Result |
| --- | --- |
| Test files inventoried | 124 PHP (26 Unit, 95 Feature, 3 Integration), 11 Node, 12 Playwright specs, 2 Python |
| CI workflows reviewed | 16 YAML workflows, including the reusable Hub release gate and production-only workflows |
| Playwright coverage | 107 source test declarations; only the 29-test mocked Daily configuration is required by the shared release gate |
| PHP test coverage | 652 `test_*` methods in configured PHPUnit directories; default SQLite and 3 PostgreSQL integration files |
| Node test coverage | 47 `node:test` declarations in 11 files; **47/47 passed locally** |
| Authentication gaps | canonical lifecycle, generic/nonexistent handling, disabled/remember/timeout/recovery/revocation/global sign-out/Cloudflare contract |
| Authorization gaps | full persona/direct-route/navigation parity, role loss across all surfaces, queue-status authorization |
| Daily/PWA gaps | installed service-worker/restart/background sync, authentication ownership switch, real reauthentication and immutable provenance E2E |
| Mobile gaps | requested full viewport catalogue and repeatable critical workflow assertions are incomplete |
| Security gaps | named CSRF/IDOR/fixation/stale-session matrix and mutation-wide actor tamper tests are incomplete |
| Integration gaps | versioned disposable contracts for Snipe, Bid, Google, ScreenTinker, video, and webhooks |
| Performance gaps | deterministic query/N+1 and CI bundle/loopback budget gates |
| P0 missing tests | unified login; persona/actor tamper; actor-versus-subject; Daily service-worker ownership; realistic migration/invariant lane |
| P1 missing tests | Redis/queue, Reverb 101/channel, promoted browser suites, browser-quality fixture/viewport matrix, integration contracts |
| Required test-infrastructure tickets | `TEST-LOGIN-001` through `TEST-SEC-012`, listed in section 15 |
| Local CI reproduction plan | section 13; use exact-base isolated worktree plus disposable loopback services |
| Shared-file implications | only this new audit artifact is authored; implementation tickets will touch test/CI files later and need reconciliation with A01–A05 |
| Dependencies | disposable PostgreSQL/Redis, browser binaries, fake providers, seeded fixtures, CI artifact retention |
| Recommended implementation order | baseline → P0 identity/Daily/database → Redis/realtime/required E2E → integrations/responsive/performance |
| Unknowns | hosted result, PHP-dependent local run, final Daily Playwright status, external/runtime/physical acceptance |

## Evidence standard and boundary

- **CONFIRMED** means this audit directly inspected source/configuration or executed the stated local command against the stated base worktree.
- **REPORTED** means the task brief supplied the fact but this audit did not independently observe it (GitHub-hosted Actions minutes unavailable through 2026-09-01).
- **INFERRED** is an interpretation from the confirmed source; it is labelled as such.
- **UNKNOWN** means neither source nor a run artifact proves the claim.

The initially active checkout was `feature/mbfd-coding-controller` at `554958917da31595c3921636d21981dfc0ffc6ea`, with unrelated untracked content, and it is not descended from the requested base. This audit therefore used an isolated worktree at the exact requested base. It does not transfer any historic pass claim to that base.

## Executive finding

The base has a substantial test *inventory* and a thoughtful isolated test bootstrap, but it is not yet a unified release-acceptance system. The required shared CI gate strongly covers PHP quality, database migration/integrity, the Daily contract, assets, dependency/security scans, and PHP 8.5 compatibility. It does **not** require most browser suites that exist in the tree. More importantly, none of the configured suites alone proves the required complete authentication lifecycle, role matrix, server-derived actor attribution across all operational submissions, real service-worker/background-sync behavior, Redis/queue restart behavior, or external integration contracts.

Consequently, the correct release posture is **NOT READY for a release claim based only on the current suite inventory**. The P0/P1 work below is intentionally implementation-ready but does not add tests in this task.

## 1. Current test inventory

### Confirmed inventory

| Surface | Files | Test declarations | What it materially covers |
| --- | ---: | ---: | --- |
| PHPUnit Unit | 26 | included in 652 PHP `test_*` methods | pure services, configuration contracts, controlled PDFs, Daily services, security helpers, video services |
| PHPUnit Feature | 95 | included above | Laravel routes, panels, APIs, authorization, operational forms, Daily, station requests, personnel, workgroups, video |
| PHPUnit Integration | 3 | included above | PostgreSQL lock/idempotency/partial-index behavior |
| Node `node:test` | 11 | 47 | CI configuration, changed-PHP Pint driver, station request client behavior, video behavior, targeted security source contracts |
| Playwright specs | 12 | 107 | Daily, Operational Forms, Personnel Requests, Station Requests, Workgroup, video, PWA and route regression |
| Python | 2 | 15 | monitor event filtering plus an Ollama proxy security source test; CI discovers only the 12 tests under `tests/Python` |

`phpunit.xml` defines Unit, Feature, and Integration suites. Its normal database is in-memory SQLite; `tests/bootstrap.php` denies non-loopback disposable PostgreSQL configuration and blanks external credentials/integrations. `Tests\\TestCase` prevents stray HTTP and process execution. That is a valuable safety property, but also means the ordinary PHP suite cannot prove live Snipe, Bid, Google, ScreenTinker, LiveKit, Cloudflare, Redis, queue-worker, or public-network behavior.

### Representative proven-by-source behavior

The following is **CONFIRMED as test source**, not a historic result claim:

- Daily: `DailyCheckoutIntegrityTest`, `DailyCheckoutInspectionSessionContractTest`, `DailyCheckoutPostgresIdempotencyRaceTest`, contract/activation/readiness command tests, and `daily-checkout-inspection.spec.ts` cover checklist/version validation, durable client IDs, duplicate receipt handling, contract snapshots, explicit abandonment, offline queue/reconnect, loss of response, and pending review.
- Station: API and Playwright tests cover canonical station/room/asset validation, idempotency UUIDs, room snapshots, redaction, station-request queue classification, signatures, direct route return-target validation, and Marine Station 6 locations.
- Authorization: panel, Training, Workgroup tenancy, role assignment, document/private-file, review, and public-redaction tests have real negative cases. Workgroup tests include forged Livewire cross-workgroup state and direct record attempts.
- Operational Forms: tests cover owner-scoped records/documents, revision conflicts, immutable PDFs, F-ROC import idempotency and provenance-oriented data handling. E2E covers form entry, F-ROC import, and generated PDF preview.
- Video: feature and Node tests cover employee-only entry, server-bound entry mode, rate limits, LiveKit token controls, Reverb configuration, media policy, and one multi-browser synthetic-media suite.
- Security: route throttles, private storage, signed station inventory URLs, public redaction, ID-like ownership boundaries, generated worker checks, and a configuration/secret-scan policy suite are present.

### Actual local execution in this audit

| Command | Result | Limits |
| --- | --- | --- |
| `composer validate --strict --no-check-publish` | **PASS** | validates manifest/lock consistency, not application behavior |
| `node --test` over all 11 Node files | **PASS: 47/47** | source and local fixture contracts; no browser/server runtime |
| `python.exe -m unittest discover -s tests/Python -p test_*.py -v` | **PASS: 12/12** | Python 3.13.1, explicit executable; does not include `tests/security/test_ollama_proxy.py` |
| `python.exe -m unittest discover -s tests/security -p test_*.py -v` | **PASS: 3/3** | explicit supplemental run; this directory is not in current CI discovery |
| `npm run typecheck` | **PASS** | root `tsconfig.json` includes only `resources/js/pump-simulator/**/*` |
| `npm run build` | **PASS** | root Vite production build; emitted assets are ignored generated output |
| `npm audit --audit-level=high` | **PASS: 0 vulnerabilities** | root lockfile dependency graph |
| Daily `npm ci`, `npm run typecheck`, isolated-output `npm run build`, `npm audit --audit-level=high` | **PASS** | Daily build emitted a local `sw.js`; it is a build artifact, not installed-PWA acceptance |
| `npx playwright test --config=playwright.daily-checkout.config.ts` | **NOT LOCALLY VERIFIED** | the isolated loopback run started all 29 Chromium tests but the command channel did not return its final pass/fail summary; no result was inferred |
| Composer dependency installation / PHP-dependent checks | **NOT LOCALLY VERIFIED** | locked installation remained incomplete with no `vendor/autoload.php` after a bounded >10 minute attempt, so installer was stopped in the disposable worktree; no PHP/Pint/PHPStan/Composer-audit result is claimed |

Any result not shown as PASS above remains **UNKNOWN** until an exact-base local run or hosted artifact is captured. No prior-agent report is counted as an execution result here.

## 2. Current CI inventory

All relevant workflow YAML files were inspected. Normal PR/push CI is main-branch scoped unless stated otherwise.

| Workflow | Trigger | Required / purpose | Runtime and important checks |
| --- | --- | --- | --- |
| `ci.yml` | push/PR to `main` | required caller | invokes `hub-release-gates.yml` |
| `hub-release-gates.yml` | reusable workflow | **required aggregate** | Node 22, PHP 8.4 and 8.5, PostgreSQL 16.13, quality, tests, Daily, assets, security, runtime compatibility |
| `06-static-analysis.yml` | push/PR to `main` | supplemental | PHP 8.4 PHPStan; Node root test glob; Python `tests/Python`; Actionlint |
| `generated-assets-guard.yml` | push/PR | supplemental | Node 22/PHP 8.4, root and Daily builds, Filament assets, video bundle and generated-asset guard |
| `02-gitleaks.yml` | push/PR | supplemental | full-history Gitleaks |
| `03-trivy-repo.yml` | push/PR + weekly | supplemental | Trivy filesystem and configuration HIGH/CRITICAL |
| `security.yml` | push/PR + weekly | supplemental | Composer/npm/Daily audit plus additional security reporting checks |
| `08-sbom.yml` | push `main` | supplemental | SPDX SBOM artifact |
| `lighthouse.yml` | successful approved deployment or dispatch | post-release | public production Lighthouse assertion enforcement |
| `observability.yml` | push `main` | post-main | Sentry release/source maps; secret-dependent |
| `prepare-production-image.yml` | dispatch | pre-activation | immutable image/SBOM/Trivy preparation |
| `production-activate.yml` / `deploy.yml` | explicit dispatch only | production-only | exact SHA/digest, backup, migration, health, runtime activation; outside local audit authority |
| `deploy-support-ai-worker.yml` | changed Worker paths or dispatch | separate Worker deployment | Node 24, Worker audit/dry-run/deploy; not applicable to this documentation-only change |
| `troubleshoot.yml` | dispatch | diagnostic | not a release quality gate |

### Shared required release gate

`hub-release-gates.yml` aggregates these hard gates: CI configuration, PHP/root frontend quality, PHPStan, Actionlint, PHPUnit + PostgreSQL, Daily contract/integrity, generated assets, dependency security, secret scanning, filesystem/configuration security, and PHP 8.5 compatibility. Repository-wide Pint debt is explicitly advisory (`continue-on-error`), while changed PHP Pint is hard.

PHP 8.4 is the ordinary hosted quality/test runtime; PHP 8.5 is a separate required production-runtime compatibility lane. Node is 22 for Hub gates (Node 24 only for the separate Cloudflare Worker deployment). PostgreSQL is `postgres:16.13-alpine`; ordinary PHP uses SQLite in memory and test-time array cache/session, sync queue, log broadcaster, and disabled external integrations.

### What CI does **not** normally execute

**CONFIRMED:** the shared required gate runs only the mocked Daily Checkout Playwright suite. It does not call the existing Operational Forms, Personnel Requests, Station Requests, Video Conferencing, default PWA/regression, Workgroup, or admin-PWA Playwright configurations. It also does not call the package scripts for Operational Forms Node tests or Video Node tests as a named required job, although the all-file Node invocation in `06-static-analysis.yml` reaches root `tests/Node/*.test.mjs` only, not nested Node files.

This distinction is the central reason that the current E2E inventory is not the same thing as release evidence.

## 3. Coverage gaps

| Area | Existing source coverage | Missing acceptance proof | Severity |
| --- | --- | --- | --- |
| Unified login | employee intended URL, failure throttling, password-change redirect, guest panel redirect | root/canonical login flow, disabled account, generic nonexistent-ID response, session-ID regeneration, remember cookie, idle/absolute expiry, recovery/reset, revocation, sign-out-other/all-devices, Cloudflare reauthentication | P0/P1 |
| Authorization | roles/panels, Training denial, Workgroup tenancy, selected context, admin role assignment restrictions | one complete browser/API matrix for every required persona; hidden navigation and direct URL paired assertion; active-session role loss across every panel; queue-status permission | P0 |
| Actor attribution | Daily contract binds actor; station actor resolver rejects untrusted fields; signed inventory URL actor case | payload-tampering tests for Daily, station request/inspection and Operational Forms preparer at actual mutation endpoints; explicit actor-versus-subject cases | P0 |
| Daily/PWA | mocked browser queue/retry/reload and server contract tests | installed PWA restart, actual service-worker cache/background sync, user/account switch queue ownership, expired authenticated session, real device/browser split, immutable ledger provenance end-to-end | P0 |
| Database | SQLite full suite plus three PostgreSQL tests | migration upgrade from realistic prior schemas and supported rollback rehearsal; identity reconciliation and all FK/unique trigger invariants on PostgreSQL | P0/P1 |
| Redis/queue | test bootstrap deliberately uses array/sync | Redis session/cache, worker restart, queue durability/retry/dead-letter behavior | P1 |
| Mobile | selected 320/390/430/820/1180/1366/1440 and default 1920 targets | requested viewport matrix including 360, 768, 1024, 1280x720, 2560, 3840; keyboard paths and reliable tap/modal assertions across critical workflows | P1 |
| Browser quality | individual specs capture page errors or overflow inconsistently | reusable fixture failing unexpected console/page errors, failed assets, and unexpected 4xx/5xx for every required browser suite | P1 |
| Video/realtime | config, token/workflow tests, synthetic-media three-endpoint E2E | Reverb WebSocket 101 and channel authorization acceptance; LiveKit provider contract/reconnect against a disposable stub; no real external credentials required | P1 |
| Integrations | isolation tests, selected source contracts | versioned consumer/provider contracts for Snipe, Bid, Google, ScreenTinker, webhook signature/replay/error behavior | P1 |
| Performance | production post-deploy Lighthouse and asset-size limits | deterministic query-count/N+1 and server-response budgets in CI; mobile performance build/probe before deployment | P2 |

## 4. Requirements → test traceability matrix

The following target state is the acceptance matrix. `Existing` records only a relevant source seam, not a pass.

| Requirement | Existing | Required test / primary layer | Class |
| --- | --- | --- | --- |
| Root redirect and canonical `/login` | fallback route only; panel guest redirects | browser + feature guest/root/canonical assertions | P0 |
| Employee-ID good/bad/nonexistent, generic response, throttle | employee intended-login feature tests | feature + browser with distinguishability assertions | P0 |
| Session regeneration / intended destination | intended-path tests | feature inspects session ID before/after login + browser deep-link | P0 |
| Disabled, forced change, logout, remember | forced-change feature tests | full login lifecycle suite | P0 |
| Idle/absolute/recovery/revocation/other devices/Cloudflare | no identified acceptance suite | policy-backed integration/browser test plan | P1 |
| Personas and direct/hidden access | panel, Training, Workgroup tests | data-driven persona × route/API matrix | P0 |
| Role loss / escalation / queue status | selected Livewire role-removal tests | active session mutation and queue-status matrix | P0 |
| Actor cannot be supplied by browser | Daily contract + station resolver seams | endpoint mutation tamper tests, persisted attribution assertions | P0 |
| Actor differs from subject | operational fields exist | PPE/team/reviewer/assignee explicit tests | P0 |
| Daily online/offline/idempotency | broad mock/contract coverage | add account-switch, installed SW, reauth, provenance/ledger acceptance | P0 |
| Station canonical context | broad station API/browser coverage | canonical-record/deep-link/manual-change/device/PIN matrix | P1 |
| Admin CRUD/destructive rights | admin critical smoke and role tests | representative resource create/update/delete authorization matrix | P1 |
| Training / Workgroup / Services | meaningful feature tests | browser/API representative role flows, including navigation and direct URLs | P1 |
| Operational Forms records/documents | good owner/document/API/E2E coverage | actor/preparer tamper plus actor-subject scenarios | P0 |
| Reverb/LiveKit reconnect | source/config/workflow tests | isolated websocket 101/channel + provider/reconnect contract | P1 |
| migration/constraints/identity/ledger | PostgreSQL trio, Daily contract tests | versioned migration fixture + invariant matrix | P0 |
| Redis/session/cache/queue | intentionally absent | disposable Redis/worker integration lane | P1 |
| responsive/quality | partial viewports/individual checks | critical workflow × device matrix and universal browser-quality fixture | P1 |
| CSRF/IDOR/fixation/stale session | scattered route/ownership/security tests | named security regression suite, mapped to every protected mutation | P0 |

## 5. Unified Login test plan

Create a dedicated data-driven feature/browser suite with an isolated account fixture and a no-secret Cloudflare Access boundary stub.

1. Guest `/` redirect and named `/login` canonical redirect must resolve to the correct panel login without loops.
2. Employee-ID login: valid password succeeds; bad password and nonexistent employee ID have indistinguishable status/body/timing bands; missing/malformed ID is safe.
3. Assert per-account and per-IP throttles; prove a failed account cannot consume another account's shared-IP allowance.
4. Capture session ID before and after successful login and require regeneration. Deep link to a protected URL and assert the safe intended URL is restored; reject external/out-of-panel return URLs.
5. Cover disabled/terminated account (generic denial), force-password-change, password change, logout, remember-me persistence, idle expiry, absolute expiry, recovery/reset, revocation, sign out other devices, and sign out everywhere.
6. Cloudflare interaction must be a mock/stub contract: upstream reauthentication redirects preserve a safe return URL and never convert into application authentication. It is not a reason to use a live Access session in CI.

P0: items 1–4 and disabled/forced-change/logout. P1: persistent session, timeouts, recovery/revocation, other-device/global sign-out, and Cloudflare contract.

## 6. Authorization and actor-attribution test plan

Use one persona factory catalogue: regular employee, Admin, Super Admin, Training-only, Workgroup member A, Workgroup member B, reviewer, inventory admin, and queue-status-permitted/denied users. Drive the same route/API table in feature tests; run representative rows in browser tests.

- For each protected surface assert navigation visibility **and** direct URL/API response. Hidden navigation is not authorization.
- Mutate a live session's role/membership, then retry the existing page action, Livewire action, direct URL, and API request. Require denial and no state change.
- Include selected Workgroup context, explicit switch, absent context, forged query/state, cross-group direct record ID, and global role boundaries.
- For Daily, station request, station inspection, and Operational Forms, submit a payload with another employee ID/preparer/actor. Assert either server-derived authenticated actor persists or a mismatch is rejected before a record is written.
- Explicitly separate actor and subject: an officer submits PPE for another firefighter; a team member differs from preparer; reviewer differs from submitter; assignee differs from submitter. Assert all four persisted identifiers/roles are correct and immutable where required.

## 7. Daily / PWA test plan

The existing mocked Daily suite is valuable but declares `serviceWorkers: 'block'`; it cannot certify installed-PWA service-worker or background-sync behavior.

Build three lanes:

1. **Deterministic browser lane (P0):** current mock server plus network loss/restore, queue/retry/duplicate/lost-response, reload, checklist policy/review, UUID receipt, and console/network fixture.
2. **Service-worker lane (P0):** a local HTTPS-capable or Chromium-controlled test app with service workers enabled. Assert install/start, offline route/cache behavior, restart, background-sync or foreground retry policy, cache version upgrade, and no duplicate commit after response loss.
3. **Authenticated ownership lane (P0):** Jane queues, signs out/expires, John signs in, and Jane's records are not submitted/revealed; reauthenticate and test form preservation. Persisted contract/ledger provenance must bind actor/session/device policy and remain immutable.

Do not claim a mock `/api/**` result proves deployed service-worker behavior, Cloudflare behavior, or physical device acceptance.

## 8. Mobile / responsive test plan

Required viewport catalogue: `320x568`, `360x800`, `390x844`, `430x932`, `768x1024`, `1024x768`, `1280x720`, `1440x900`, `1920x1080`, `2560x1440`, `3840x2160`.

Use a rational matrix rather than every test at every size:

| Device class | Required critical flows |
| --- | --- |
| 320/360/390/430 touch phone | login, Daily submit/retry, station request, employee PPE/forms, key admin action |
| 768/1024 touch tablet | Daily, operational-form PDF/editor, Workgroup switch/evaluation, video controls |
| 1280/1440 desktop | all release-critical workflows, role navigation, administration |
| 1920/2560/3840 desktop | full viewport smoke: route load, no overflow, assets, usable navigation and modals |

Every critical row should assert document overflow, visible/non-clipped primary actions, 44px-or-better touch target where applicable, modal bounds, keyboard/focus path, form completion, and targeted soft-keyboard-sensitive fields. Preserve screenshots/traces only as failure evidence, not as a pass substitute.

## 9. Integration test plan

Create versioned contract fixtures/adapters; do not issue production writes in CI.

| Integration | Contract/smoke design |
| --- | --- |
| Snipe | recorded schema fixture + mock HTTP server; authenticated read/failed response/idempotent reconciliation cases |
| Bid | local fake verifying credential, timeout, malformed and redacted response behavior; no live roster write |
| Google | fake Sheets/Drive client asserting exact request shape and disabled feature no-op |
| ScreenTinker | local webhook server tests for signed/authenticated payload, redaction, retry and disabled boundary |
| Video / LiveKit / Reverb | disposable token/provider contract, websocket channel authorization and reconnect without cloud credentials |
| inbound webhooks | signature, timestamp/replay, idempotency, payload-size/type limits, retry classification and audit record |

## 10. Performance test plan

- Preserve current Lighthouse as a post-activation/public acceptance gate; it has hard FCP, LCP, interactive, CLS, TBT and transfer/count thresholds, but is not PR runtime proof.
- Add deterministic unit/feature query budgets around representative high-risk pages/API responses using `DB::listen`; flag a query-count increase above an explicitly reviewed baseline. This is the N+1 regression gate.
- Add CI bundle-budget comparison against committed baseline metadata (not network timing) for root and Daily assets.
- Add local loopback response budgets only for stable endpoints with seeded fixtures; measure a percentile across a small fixed run and make gross regression P2, not normal scheduling jitter a hard P0.
- Add a mobile Lighthouse/local trace smoke with deterministic assets; retain production public Lighthouse as independent evidence.

## 11. Security regression plan

Organize named tests by property, not only by controller:

- CSRF: every browser-session mutation rejects absent/invalid token; intended webhook exceptions validate signatures and replay protection.
- authentication and session: generic login failures, fixation, stale/expired/revoked session, remember cookie shape and logout invalidation.
- authorization/IDOR: persona × resource owner/other-owner/direct-ID matrix; assert HTTP result and database immutability.
- privilege escalation: forged actor, role/membership loss, hidden navigation/direct route parity, panel/Livewire/API boundaries.
- confidentiality: password/secret/employee ID/redaction assertions in API, rendered HTML, errors, logs/telemetry fixture and generated assets.
- API: unauthenticated and wrong-role routes, rate limit, payload-size/type/UUID validation, signed URL scope/expiry/retargeting.

## 12. Final release-acceptance suite

| Class | Gate |
| --- | --- |
| **P0 release blocker** | manifest/lock integrity; changed-PHP Pint; PHPStan; root/Daily build/typecheck; migration upgrade and PostgreSQL constraints; full PHP suite; authentication baseline; authorization/persona/direct-access matrix; actor-attribution tamper; Daily idempotency/ledger/offline ownership; service startup/local HTTP; required security regressions; critical phone/tablet/desktop workflow usability |
| **P1 release blocker** | PHP 8.5 lane; Redis/session/cache and queue-restart behavior; Reverb 101/channel authorization; Daily service-worker/install/restart; versioned integration contracts; secret/dependency/filesystem scans; Docker image runtime/health; public HTTP and post-activation Lighthouse; backup/rollback rehearsal where a migration or release changes runtime data |
| **P2 should pass** | complete responsive sweep, broad CRUD, operational-form/document variants, video synthetic-media flow, query/bundle budgets, noncritical performance and observability checks |
| **Informational** | screenshots, advisory repository-wide Pint debt, non-blocking performance warnings, coverage trend and exploratory reports |

No source/configuration test may replace required human operational acceptance where physical equipment or third-party production behavior is in scope.

## 13. Local CI fallback plan

**REPORTED:** GitHub Actions is unavailable under the task brief; no hosted run was waited on or represented as green.

Run only in an isolated worktree and disposable services:

1. `composer validate --strict --no-check-publish`; locked Composer install; `composer audit --locked`; changed-file Pint and full advisory Pint; PHPStan.
2. `npm ci --ignore-scripts --legacy-peer-deps`; root typecheck/build/audit; install and typecheck/build/audit `resources/js/daily-checkout` separately.
3. `php artisan test --exclude-group=postgres` in the enforced SQLite/array/sync isolation environment.
4. Start an explicitly named loopback-only PostgreSQL 16 disposable container/database (`mbfd_hub_test_*`, `mbfd_test_*`) and run `php artisan test --group=postgres`; never target a shared database.
5. Install only required Playwright browsers, then run Daily mock E2E and the newly required Operational Forms, Personnel, Station, Video, admin-PWA, Workgroup and responsive suites serially with loopback base URLs and no inherited integrations.
6. Run all Node test files recursively and both explicit Python suites with the bound interpreter. Run Actionlint, Gitleaks, Trivy and image inspection where installed; otherwise mark exact gates **NOT LOCALLY VERIFIED**.
7. For release candidates, use a unique Compose project with no production volumes/ports/configuration and verify image labels, non-root runtime, health, local/public HTTP, Reverb, database, Redis and queue. Production activation, backup/rollback, canary, and physical acceptance require separate owner authorization.

## 14. Release-blocking criteria

Block release if any P0/P1 gate is missing, failing, executed against the wrong SHA/environment, or only claimed without an artifact. Also block on unknown migration/rollback integrity, wrong-actor persistence, any cross-user/cross-workgroup state change, unbounded duplicate submission, missing critical viewport usability, or unavailable runtime/physical acceptance required by the release scope.

The existing aggregate CI job is necessary but not sufficient until the unrequired E2E suites and missing P0/P1 cases are promoted into the required matrix.

## 15. Proposed implementation tickets

| Priority | Ticket | Acceptance outcome |
| --- | --- | --- |
| P0 | `TEST-LOGIN-001` Unified authentication lifecycle matrix | all login/session/timeout/recovery/revocation requirements have feature/browser evidence |
| P0 | `TEST-AUTHZ-002` Persona, direct-route, and active-session role-loss matrix | shared data-driven authorization harness; navigation and direct access both asserted |
| P0 | `TEST-ACTOR-003` Server-derived actor and actor-vs-subject regression suite | Daily/station/forms payload tampering cannot change actor; distinct subject/reviewer/assignee preserved |
| P0 | `TEST-DAILY-004` Service-worker and account-ownership acceptance lane | installed/restarted offline PWA behavior and Jane-to-John queue isolation proven locally |
| P0 | `TEST-DB-005` Migration fixture and PostgreSQL invariant lane | realistic prior-schema upgrade, supported rollback rehearsal, FK/unique/identity/ledger invariants |
| P1 | `TEST-REDIS-006` Disposable Redis/session/queue restart lane | actual cache/session/worker/retry behavior independent of array/sync PHPUnit defaults |
| P1 | `TEST-E2E-007` Promote existing non-Daily browser suites into required CI | Operations, Personnel, Station, Video, Workgroup, admin-PWA and responsive suites have stable loopback jobs |
| P1 | `TEST-BROWSER-008` Shared browser-quality fixture and viewport catalogue | unexpected console/page/network/assets failures and critical device workflow regressions fail consistently |
| P1 | `TEST-REALTIME-009` Reverb/LiveKit disposable contracts | websocket 101/channel authorization/token/reconnect proof without production credentials |
| P1 | `TEST-INTEGRATION-010` Versioned adapter/provider contracts | Snipe, Bid, Google, ScreenTinker and webhook behavior proven with fakes/fixtures |
| P2 | `TEST-PERF-011` Query, asset and stable local response budgets | non-flaky N+1, gross bundle and response-regression protection |
| P2 | `TEST-SEC-012` Named CSRF/IDOR/session security matrix | every protected mutation maps to a repeatable negative regression test |

## Required implementation order

1. Freeze and exercise the existing required local CI baseline at each candidate SHA.
2. Implement P0 login, authorization/actor, Daily ownership/service-worker, and migration/ledger tests.
3. Add disposable Redis and realtime contracts; promote existing E2E suites into required loopback CI.
4. Add integration, responsive/browser-quality, and performance gates.
5. Only then use the final P0/P1 suite as a release input together with independent image, backup/rollback, runtime, public and human acceptance evidence.

## Unknowns and dependencies

- **UNKNOWN:** a hosted Actions result for this base SHA; the task brief reports Actions unavailable.
- **UNKNOWN until locally run:** full PHP/SQLite suite, PostgreSQL group, PHPStan, Pint, Composer full audit, the final result of the 29-test Daily Playwright command, and Docker/image/runtime checks.
- **UNKNOWN:** exact production Cloudflare Access behavior, real LiveKit/Reverb service behavior, Redis/queue durability, external provider contract versions, public HTTP, and physical acceptance. These require their own authorized environments and must not be inferred from this document.
- The planned test implementation depends on disposable PostgreSQL/Redis, local browser binaries, fake provider servers/fixtures, stable seeded data, and CI artifact retention. It must preserve test isolation and must never import production credentials or write production systems.

## Temporary local CI validation

GitHub Actions:

- **REPORTED unavailable** due to the `pdarleyjr` Actions-minute limit in the task brief. No unavailable hosted gate was marked passed.

Repository stack:

- Laravel/PHPUnit/PHPStan/Pint; React/Vite/TypeScript; Node `node:test`; Playwright; PostgreSQL/SQLite; Docker/Compose; limited Python monitor tests.

Applicable workflows reviewed:

- all workflow YAML under `.github/workflows`, with `hub-release-gates.yml`, `ci.yml`, static analysis, generated assets, security, Trivy, Gitleaks, Lighthouse, preparation and activation workflows specifically mapped above.

Checks executed:

- PASS — Composer manifest/lock validation.
- PASS — all Node test files: 47/47.
- PASS — Python suites: 12/12 monitor tests and 3/3 Ollama-proxy security tests using `C:\\Users\\Peter Darley\\AppData\\Local\\Programs\\Python\\Python313\\python.exe` (Python 3.13.1; capture verified).
- PASS — root TypeScript typecheck.
- PASS — root and Daily production builds; Daily emitted a service-worker asset to an isolated test-results directory.
- PASS — root and Daily NPM audit: 0 high-or-higher vulnerabilities.

Functional/smoke validation:

- PARTIAL — Node and Python source-contract tests executed; the Daily loopback browser command began 29 tests but did not return a final status; PHP/application/runtime smoke remains subject to the unobserved gates listed above.

Final diff review:

- Required before handoff; only this audit artifact is authorized to be tracked.

Checks not reproducible locally at the time of this source audit:

- NOT LOCALLY VERIFIED — GitHub-hosted aggregate/PR checks, hosted action services, production activation, public Lighthouse and secret-dependent Sentry release.
- NOT LOCALLY VERIFIED — PHP/Pint/PHPStan/Composer audit/full PHPUnit/PostgreSQL group because the bounded isolated Composer install never completed; the final Daily Playwright result because the command channel did not return it.

Result:

- PASS — this architecture audit identifies the local/hosted validation structure and does not represent unexecuted tests as passing. It is not a release approval.
