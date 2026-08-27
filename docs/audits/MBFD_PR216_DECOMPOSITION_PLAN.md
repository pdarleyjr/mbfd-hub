# MBFD PR #216 Decomposition Plan

## Purpose and fixed boundary

This is a source-decomposition plan for the committed audit branch
`audit/mbfd-hub-full-system-20260825`. The current source checkpoint before
this documentation-only update is `62e4749e89246eb62fce24012bbda2d87f135317`.
The original published umbrella and follow-on checkpoints remain historical
context; this continuation does not reset, squash, or discard them. It is not
merge, deployment, production-migration, Cloudflare, or physical-AV
authorization.

The inspected local merge base is cached `origin/main` at
`ac6965f88f7d8ed441e08996b93d7cdb9f9b99c0`. At the current source checkpoint,
the committed range contains 47 linear commits and 242 files
(`+30,190/-2,594`); `git diff --check` reported no whitespace errors.
`.audit-postgres/` remains untracked local evidence and must neither be staged
nor copied into any child PR.

Strict exclusions:

- Do not change, deploy, test, restart, configure, or infer acceptance for
  Media Control, MediaMTX, OBS, P3/player, eARC/audio, screens, receivers,
  Cloudflare tunnel/routing, or any physical AV system.
- Do not include the PulsePoint Worker or run any Wrangler command in a
  Hub-only candidate. Its source, deployment, rollback, and cache-safe
  behavior probe require separate authorization.
- Do not include `production-activate.yml` in a Hub source release. It can
  change Redis/R2 operational state and is not the candidate deployment path.
- Do not deploy the fail-closed Command Display token change until the Hub
  `DISPLAY_API_TOKEN` and the caller's `X-Display-Token` continuity have been
  independently verified. Otherwise non-OPTIONS `/api/display/*` calls fail.

The changed-path review found no committed Media Control, MediaMTX,
ScreenTinker, eARC, or P3 path change. Audit inventory text and PHPUnit
isolation references are documentation/test containment, not Media Control
work.

## Executed clean candidate branches

The following candidates were rebuilt manually in clean worktrees rather than
cherry-picked from the mixed umbrella range. All remain draft and unmerged;
none authorizes a deployment.

| Pull request | Candidate and base | Commit | Scope / observed local evidence |
|---|---|---|---|
| [#217](https://github.com/pdarleyjr/mbfd-hub/pull/217) | Station Inventory V2 signature guard → `main` | `ca0414b0` | Eight paths: central middleware/route guard, actor resolver, controller, and focused tests. Syntax, candidate Pint, and whitespace checks passed; full Laravel suite was not locally runnable in that clean worktree. |
| [#218](https://github.com/pdarleyjr/mbfd-hub/pull/218) | Generic test-runtime isolation → `main` | `b36a3edd` | Eight harness-only paths: comment-only PHPUnit sentinel, HTTP/process blocking, and disposable PostgreSQL guard. Syntax, candidate Pint, XML, sentinel, and guard checks passed; no feature configuration was included. |
| [#219](https://github.com/pdarleyjr/mbfd-hub/pull/219) | Workgroup AI hardening → #218 | `ce2fb3ec` | Seven Workgroup-only paths: explicit config-backed enable/URL/secret, fail-closed service, authenticated requests, and regression coverage. Focused PHP 8.5 harness passed 12 tests / 97 assertions plus syntax, Pint, XML, diff, fallback, and secret checks. |

GitHub Actions is temporarily unavailable because the user reported exhausted
minutes. It remains enabled. The current local-equivalent evidence does not
turn any candidate into a hosted-CI pass; PHPStan, PostgreSQL, Trivy, and
Actionlint remain explicitly unverified where their locked tool/service is not
available locally. At the umbrella source checkpoint, the materialized local
non-PostgreSQL suite passed 604 tests / 3,361 assertions on each PHP 8.4.6 and
PHP 8.5.4 interpreter; that proves neither a child PR's hosted matrix nor a
deployment.

## Exhaustive subsystem allocation

The path specifications below are exhaustive for the committed range. A
directory specification means every changed file beneath that directory;
brace lists enumerate the remaining files in that subsystem. A path belongs
to one primary release owner even where its tests exercise another subsystem.

| Subsystem | Changed-path allocation | Release meaning |
|---|---|---|
| Audit evidence and inventory | `docs/audits/{MBFD_FULL_SYSTEM_AUDIT_2026-08-25.md,MBFD_FULL_SYSTEM_AUDIT_2026-08-25.json,DAILY_CHECKOUT_PRODUCTION_APPARATUS_MATRIX_2026-08-25.md}`, `scripts/audit/generate-full-system-inventory.mjs` | Evidence only; never bundle it with runtime behavior. The JSON is the largest single artifact and should remain an audit snapshot. |
| Daily Checkout contract, review, and canonical consumers | `app/Console/Commands/AuditDailyCheckoutReadiness.php`, `app/Enums/DailyCheckout*.php`, `app/Filament/Resources/ApparatusResource.php`, `app/Filament/Resources/ApparatusResource/**`, `app/Filament/Resources/InspectionResource/**`, `app/Filament/Widgets/StationOperationsHubWidget.php`, `app/Http/Controllers/Api/{ApparatusController,StationController}.php`, `app/Http/Resources/Public/PublicApparatusResource.php`, `app/Models/{Apparatus,ApparatusInspection,ApparatusInspectionReviewEvent,ApparatusOperationalStatusEvent}.php`, `app/Observers/ApparatusObserver.php`, `app/Policies/ApparatusInspectionPolicy.php`, `app/Services/{ApparatusInspectionApprovalService,DailyCheckoutChecklistResolver,DailyCheckoutComplianceService}.php`, `app/Services/Display/**`, `routes/api.php`, `resources/js/daily-checkout/**`, `resources/views/filament/resources/apparatus-resource/pages/view-inspection.blade.php`, `resources/views/filament/widgets/**`, Daily/API/Display/Apparatus/Inspection/Console/Service unit and integration tests, and `tests/e2e/daily-checkout-inspection.spec.ts` | One stacked feature, not one cherry-pick. It changes public intake, approval timing, Daily completion semantics, display-facing projections, and offline behavior. |
| Daily schema | `database/migrations/2026_08_25_000001_*` through `2026_08_25_000007_*`, plus `2026_08_26_140000_create_apparatus_operational_status_events_table.php` | Ordered, additive data contract for the Daily stack; see migration order below. |
| Command Display API guard | `.env.example`, `config/services.php`, `app/Http/Middleware/EnsureDisplayToken.php`, Display token/read-only/redaction/snapshot tests | Separate security/API rollout. It is not proof of Command Display browser/device/physical-display acceptance. |
| Forced-password, panel, training, and provisioning safety | `app/Console/Commands/ProvisionWorkgroupMembers.php`, `app/Filament/Pages/SetPasswordPage.php`, `resources/views/filament/pages/set-password.blade.php`, `app/Http/Middleware/{ForceFilamentPasswordChange,ForcePasswordChange,ForcePasswordChangeMiddleware}.php`, `app/Providers/Filament/{AdminPanelProvider,EmployeePanelProvider,TrainingPanelProvider}.php`, `app/Filament/Training/**`, `resources/views/filament/employee/pages/dashboard.blade.php`, `app/Policies/TrainingTodoPolicy.php`, relevant User/model and forced-password/training/provisioning tests | Independent Hub access hardening. The provisioning command must not run as a deployment side effect. |
| Endpoint access hardening | `app/Http/Middleware/EnsureVideoConferenceHealthAccess.php`, the corresponding `routes/web.php` hunks, `app/Http/Controllers/Api/StationInventoryController.php`, `resources/views/pdf/station-inventory.blade.php`, `tests/Feature/{SecurityHardeningRoutesTest,PrivateFileStorageTest,VideoConferencing/ConferenceAccessTest}.php` | Hub authorization only. Do not use it to claim LiveKit, player, or Media Control runtime health. |
| Station Inventory provenance and storage | `app/Data/StationActors/**`, `app/Services/StationActors/**`, `app/Http/Controllers/Api/StationInventoryV2Controller.php`, Station Inventory V2/actor/submission-storage tests | Independent from Daily. Signed URL actor fields take precedence over forged request-body fields, but this is not a device-identity solution. |
| TRT integrity | `app/Models/TrtInventorySession.php`, `database/migrations/2026_08_26_120000_enforce_one_default_trt_session_per_day.php`, TRT feature/integration tests | Independent data-integrity change with an explicit production duplicate preflight. |
| Workgroup tenant containment and reports | `app/Filament/Resources/Workgroup/**`, `app/Filament/Workgroup/**`, `resources/views/filament-workgroup/**`, `resources/views/filament/workgroup/**`, `app/Http/Controllers/Workgroup/**`, Workgroup-related hunks in `ReportExportController.php`, `routes/web.php`, `bootstrap/app.php`, `app/Http/Middleware/{EnsureGlobalWorkgroupAccess,EnsureWorkgroupPanelAccess}.php`, `app/Providers/Filament/WorkgroupPanelProvider.php`, `app/Support/Workgroups/**`, `app/Services/Workgroup/EvaluationService.php`, `app/Models/WorkgroupNote.php`, all `tests/Feature/Workgroup*`, Workgroup relation/notes/report/widget tests, and `tests/e2e/workgroup-evaluations.spec.ts` | Independent security/data-boundary feature. Keep dormant legacy dashboards, exporters, widgets, and templates unregistered. |
| Workgroup Notes schema | `database/migrations/2026_08_26_130000_add_sharing_columns_to_workgroup_notes_table.php` | Additive, deliberately non-destructive downgrade. |
| Bid integration | `app/Support/BidApiUrl.php`, `app/Filament/Admin/Pages/BidAccessPin.php`, `app/Filament/Employee/Pages/MyBidCertificationsPage.php`, `tests/Unit/Support/BidApiUrlTest.php` | Small external-integration correction; do not bury it in password or Daily work. |
| PulsePoint Worker | `cloudflare-worker/pulsepoint-proxy/**`, `.github/workflows/verify-pulsepoint-proxy.yml`, PulsePoint-related portions of `deploy-support-ai-worker.yml` | Separate Cloudflare Worker PR only. No Hub release should deploy it. |
| Test harness and browser configuration | `.env.testing.example`, `phpunit.xml`, `tests/bootstrap.php`, `tests/TestCase.php`, `tests/Feature/{TestEnvironmentIsolationTest,DisposablePostgresBootstrapGuardTest}.php`, `tests/e2e/support/test-environment.ts`, `tests/e2e/{auth.setup,debug-admin,mbfd-full-verification,operational-forms.setup,personnel-requests.setup}.ts`, and `playwright*.ts` | Must be extracted by hunk: some configs are harness-only while Daily/forced-password/Workgroup specs remain with their feature PRs. |
| CI, deployment, dependencies, and static analysis | `.github/dependabot.yml`, `.github/workflows/{06-static-analysis,ci,deploy,hub-release-gates,lighthouse,observability,production-activate,security,deploy-support-ai-worker}.yml`, `composer.{json,lock}`, `package.json`, `phpstan-baseline.neon`, `scripts/ci/guard-generated-assets.mjs`, `tests/Node/ci-configuration.test.mjs` | Final release-governance layer. It depends on the extracted source and test paths it names. `production-activate.yml` and Worker deployment material stay excluded from a Hub candidate. |

## Follow-on continuation allocation

The following paths were added or repaired after the original remote umbrella
head. They are not a reason to merge the umbrella monolith; each belongs to a
small review unit and retains the same no-deployment/Media boundary.

| Follow-on unit | Paths | Dependency and extraction rule |
|---|---|---|
| Candidate-scoped PHP formatting | `scripts/ci/changed-php-files.mjs`, `tests/Node/changed-php-files.test.mjs`, `package.json`, `.github/workflows/{ci,deploy,hub-release-gates}.yml`, `tests/Node/ci-configuration.test.mjs`, and the three targeted Pint-only source-format changes | Extract before runtime candidates. It requires the reusable gate input/caller changes together; it keeps full-repository Pint advisory until a dedicated formatting-only PR. |
| Station Inventory storage and queue visibility | `app/Http/Controllers/Api/{StationInventoryController,Admin/QueueStatusController}.php`, `tests/Feature/{Api/StationInventorySubmissionStorageTest,AdminPwaRoutesTest}.php` | A focused Hub authorization/storage PR. It is independent of Daily and must retain the database-failure cleanup and role-negative tests. Queue driver-aware status remains a later operations unit. |
| User role assignment | `app/Filament/Resources/UserResource.php`, `tests/Feature/Filament/UserRoleAssignmentAuthorizationTest.php` | A small default-deny authorization PR. It must retain lower-admin actor/target-role matrix coverage and needs a deployed Shield-policy review before activation. |
| Workgroup AI default-off egress | `config/workgroup.php`, `.env{,.testing}.example`, `phpunit.xml`, `tests/bootstrap.php`, `tests/Feature/{TestEnvironmentIsolationTest,Services/WorkgroupAIServiceConfigurationTest}.php` | Extract as a source containment PR. It is not the durable consent/outbox/Worker solution and must not trigger a Worker deployment. |
| Web Push registration | `app/Http/Controllers/Api/PushSubscriptionController.php`, `config/webpush.php`, `routes/api.php`, `.env{,.testing}.example`, `phpunit.xml`, `tests/bootstrap.php`, `tests/Feature/{Api/PushSubscriptionControllerTest,TestEnvironmentIsolationTest}.php`, `tests/e2e/support/test-environment.ts` | Independent authenticated API hardening. The approved provider host list is an owner configuration decision; the default is fail-closed. |
| Daily preactivation gate | `app/Console/Commands/AuditDailyCheckoutPreactivation.php`, `app/Services/{DailyCheckoutPreactivationManifest,DailyCheckoutChecklistEvidenceInspector,DailyCheckoutComplianceService}.php`, `tests/Feature/Console/AuditDailyCheckoutPreactivationTest.php` | Stack on the Daily schema/canonical contract. It never runs on a default connection and is only releasable with an owner-approved manifest and genuine read-only candidate snapshot. |
| Documentation continuation | `docs/audits/{MBFD_FULL_SYSTEM_AUDIT_2026-08-25.md,MBFD_PR216_DECOMPOSITION_PLAN.md}` | Evidence-only final update after the matching source/test checkpoint is known. It must retain the prior PulsePoint incident and all unresolved gates. |

## Migration order and cross-dependencies

1. **Daily foundation:** `000001` daily requirement, `000002` client
   submission UUID, `000003` Daily template, then `000004` checklist version.
   The resolver, models, public API, and offline queue depend on these columns.
2. **Daily review:** `000005` canonical payload hash, `000006` pending effects,
   then `000007` review events. Approval/rejection behavior depends on the
   foundation and must be released with all three review migrations.
3. **TRT:** `120000` is independent, but its migration aborts if historical
   default-session duplicates exist. Its model uses insert-or-ignore plus an
   exact nullable/non-null re-query and therefore depends on the partial index.
4. **Workgroup Notes:** `130000` must precede any deployed code that reads or
   writes the sharing columns.
5. **Daily operational ledger:** `140000` depends on the Daily compliance
   model/service and must be present before apparatus status writes are exposed;
   the status observer writes the ledger in the model transaction.

Cross-dependencies that prohibit arbitrary extraction:

- Daily review is stacked on Daily foundation; canonical Daily consumers are
  stacked on both. The final Display Snapshot/Readiness, Station API, Station
  Operations widget, Daily UI, and readiness command must consume the same
  canonical result.
- The Daily browser CI gate depends on both the Daily source and
  `hub-release-gates.yml`; it cannot be extracted as a standalone test commit.
- Workgroup source is independent of Daily behavior, but the two late PHPStan
  cleanup commits contain hunks for both. Split those hunks into their owning
  PRs rather than choosing one subsystem.
- Station Inventory V2 actor integrity depends on the new resolver/data class;
  it is separate from the legacy Station Inventory PDF access/storage repair.
- Forced-password middleware and panel configuration belong together. A page
  or route-only extraction leaves Livewire update paths inconsistent.
- Command Display token enforcement is independent of the canonical Daily
  projection, but requires out-of-repository caller continuity before release.

## Planned child-PR sequence — historical plan

| Sequence | Candidate PR | Status | Preconditions and verification focus |
|---:|---|---|---|
| 0 | Test-runtime isolation | Independent; merge first | Proves inherited integration values cannot enable outbound requests and disposable PostgreSQL is loopback-only. |
| 1 | Forced-password and provisioning safety | Independent | Test Admin/Employee/Training/Workgroup Livewire updates, password-change exception, logout, and protected-role provisioning skips. Do not invoke provisioning in production. |
| 2 | Hub endpoint authorization | Independent | Test conference-health role denial and Station Inventory PDF role/private-disk behavior only. |
| 3 | Station Inventory provenance and storage | Independent | Test signed actor precedence, station-item scoping, valid PDF generation, and distinct ULID paths. |
| 4 | TRT default-session integrity | Independent but data-gated | Run a read-only production duplicate preflight before considering migration. |
| 5 | Workgroup tenant containment | Independent but policy-gated | Confirm selected-workgroup/session and global-viewer policy; run direct-ID, relation-manager, report, upload, and cached-report regressions. |
| 6 | Daily Checkout foundation | Stacked root | Obtain accountable owner classification/template policy before release; run resolver, API, queue, and browser contract tests. |
| 7 | Daily review and deferred effects | Stacked on 6 | Run approval action, transaction, and PostgreSQL lock/idempotency tests. |
| 8 | Canonical Daily compliance and consumers | Stacked on 6-7 | Run seven-state matrix, ledger, public station contract, display/readiness, Station Operations, and Daily browser tests. |
| 9 | Command Display fail-closed API guard | Independent but external-config-gated | Verify token continuity before production exposure; otherwise defer this PR. |
| 10 | Bid API URL normalization | Independent | Run its unit test and review the intended production/staging host mapping. |
| 11 | Static analysis, CI, and Hub-only release gates | Stacked on the selected runtime PRs | Run hosted reusable gates against the exact candidate SHA. Keep operational activation and Worker deployment out. |
| 12 | PulsePoint Worker hardening | Strictly separate | Worker-only locked install, typecheck, and unit test; no deployment. |
| 13 | Audit evidence | Independent, last | Regenerate/review only when the final candidate and observed evidence are known. |

The executed branch sequence above refines this plan without changing its
release boundary: #218 supplies the generic harness root, #217 is an
independent Station Inventory candidate, and #219 is the Workgroup AI slice
stacked on #218. Web Push was deliberately not bundled into #219.

### Follow-on child-PR order — remaining work

1. Candidate-scoped Pint and reusable-gate wiring. Run the Node helper tests and
   PHP candidate-path Pint. Do not make the historic full-repository Pint debt
   a functional PR change.
2. Station Inventory failure cleanup plus queue-status authorization, then user
   role assignment. These are independent, small authorization/storage units.
3. #219 executes Workgroup AI default-off egress only. Keep Web Push
   registration hardening in its own owner-configuration candidate; neither
   candidate requires a Media Control action.
4. Daily preactivation gate stacked after the Daily foundation/review/canonical
   PRs. Run it only on an owner-approved, read-only candidate snapshot—not on
   production—and retain its explicit Reserve/unresolved-state failure.
5. Fold the final evidence update into the audit/documentation PR after the
   temporary GitHub Actions capacity limit clears and exact-SHA hosted CI is
   available. Do not state that a source-only check is a deployment result.

## Commit extraction limits

Do **not** cherry-pick these checkpoint commits as units; each mixes multiple
candidate PRs:

- `0e607342b`, `db284c7b9`, `2ac7645a4`, `74b3020c4`, `659e7dfbc`.

The Daily follow-up commits are a dependency chain, not standalone PRs:

- `1a5a4411e`, `7aab4d60b`, `d3188ad60`, `fe3fa8583`, `ea159cc3e`,
  `4aafc0809`, `2f5d63f30`, `841bb92d8`, `cb9474754`.

The following commits must be split by hunk into their owning feature PRs:

- `5f19522f2` spans forced-password, Daily, and Workgroup code.
- `5f2f5ef60` spans Daily and Workgroup code.

These are late-stage tooling/evidence commits, not functional extraction
boundaries:

- CI/dependency: `8c14dc975`, `890959510`, `03bdf0b9a`, `2cbdf7b10`,
  `792490d06`.
- PHPStan baseline cleanup: `3ce350a1e`, `5e7addd05`; apply only after the
  corresponding source fixes are present.
- Cumulative audit narrative: `e8b5e69ad`, `3b50afa3f`, `261274557`,
  `752a5acbd`, `a63a42d7e`, `23d9a19e5`; squash/rewrite as one final evidence
  update.

The constrained follow-on commits have these extraction boundaries:

- `a399f4061` (Station Inventory cleanup and queue-status authorization),
  `ae0155c9b` (role assignment), `b189c59c1` (Workgroup AI default-off),
  `3993a12bb` (Web Push), and `ef82652a3` (Daily preactivation) are
  individually reviewable **only with their tests/configuration in the same
  commit**. They may be transplanted as focused commits after their declared
  prerequisites are present.
- `cb72b370b` and `14282c4b9` are one CI/Pint extraction pair; do not take
  the latter format-only commit without the former policy. Its three formatted
  files can instead be included in their owning feature PRs when reconstructing
  the stack.

Create each child branch from a refreshed `main`, transplant only its owned
paths/hunks, and make a new focused commit. Do not use `git cherry-pick` on a
checkpoint merely because it applies cleanly.

## Rollback implications

- Daily migrations `000001`-`000007` expose destructive downs after new
  submissions, hashes, pending effects, or review events exist. Use a verified
  backup restore or forward corrective release, not blind `migrate:rollback`.
- The Workgroup Notes and operational-status-ledger migrations deliberately
  leave their additive schema/evidence in place on code rollback. This is the
  intended data-preserving behavior.
- The TRT index can technically be removed, but doing so removes the race
  guard. A rollback must not silently re-enable duplicate defaults.
- A code rollback from pending-review semantics can reintroduce immediate
  operational effects or misinterpret newly created records. Daily rollback
  needs an explicit application/data compatibility plan.
- Access-control rollback reopens protected surfaces. Treat it as a security
  decision, not routine recovery.
- The Hub deploy workflow's `pg_dump` plus `pg_restore --list` is basic backup
  integrity evidence, not a restore rehearsal or automated rollback proof.

## Exact extraction verification

For every child branch, before opening a PR:

```bash
git diff --check origin/main...HEAD
git diff --name-only origin/main...HEAD
git diff --stat origin/main...HEAD
composer validate --strict --no-check-publish
```

Run only the tests owned by that child PR first, then the relevant aggregate:

```bash
php artisan test tests/Feature/TestEnvironmentIsolationTest.php tests/Feature/DisposablePostgresBootstrapGuardTest.php
php artisan test tests/Feature/Filament/ForcedPasswordChangeTest.php tests/Feature/Filament/EmployeeForcedPasswordChangeTest.php
php artisan test tests/Feature/Api/StationInventoryV2SignedUrlTest.php tests/Feature/Api/StationInventorySubmissionStorageTest.php tests/Feature/TrtInventorySessionIntegrityTest.php
php artisan test tests/Feature/WorkgroupTenancyBoundaryTest.php tests/Feature/WorkgroupReportAccessTest.php tests/Feature/WorkgroupNotesAndSharedUploadsAuthorizationTest.php
php artisan test tests/Feature/Api/DailyCheckoutIntegrityTest.php tests/Feature/Console/AuditDailyCheckoutReadinessTest.php
```

For a Daily/approval/TRT candidate, run the PostgreSQL group only against an
explicit disposable loopback database:

```bash
MBFD_ALLOW_DISPOSABLE_POSTGRES=1 REQUIRE_POSTGRES_INTEGRATION=true \
php artisan test --group=postgres
```

For CI/deployment extraction, require the exact candidate SHA to pass the
reusable `hub-release-gates.yml` matrix: CI configuration, Pint, PHPStan,
SQLite/PHPUnit, disposable PostgreSQL, Daily browser contract, generated
assets, dependency audit, secret scan, filesystem/config scan, and PHP 8.5
compatibility. Run the Worker package only in its separate PR:

```bash
cd cloudflare-worker/pulsepoint-proxy
npm ci --ignore-scripts
npm run typecheck
npm test
```

No command above authorizes a production deployment. Before a Hub candidate is
activated, independently record: Daily owner classification/template evidence,
pre-ledger OOS-return cutover resolution, TRT duplicate preflight, Command
Display token continuity if that guard is included, a production-shaped restore
rehearsal, staged Hub API/browser authorization evidence, and accountable human
approval. None of those gates is Media Control acceptance.

## Current blockers to a Hub release

The audit records that PR #216 is **FAIL / not ready to merge or deploy**.
Hosted PHPStan evidence applies only to earlier source checkpoints. GitHub
Actions is temporarily unavailable because the user reported exhausted minutes,
so current exact-SHA hosted validation is **not observed**, not green or
waived. Candidate-scoped Pint currently passes all 179 changed PHP files, while
the broad repository audit has 292 inherited nonconforming paths. Do not
attribute the external Actions-capacity condition to a source or security
finding.

Even after hosted CI resumes, the following remain release blockers for the
affected child PRs: Daily data classification/template ownership, historical
OOS-return cutover review, TRT duplicate preflight, Command Display token
continuity, restore rehearsal/rollback proof, staged public/API/browser
authorization evidence, and owner-configured GitHub governance. The classroom
has ended, but that does not authorize a Hub deployment: direct service
separation does not prove harmless shared-host resource contention, and Media
Control remains frozen. Build a fresh, exact-SHA, Hub-only candidate from the
selected child PRs; do not deploy the mixed audit branch as a substitute.
