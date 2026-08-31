# Implementation Tickets

Every worker starts a new clean Codex Desktop chat, uses an isolated worktree, branches from the exact declared base, has **merge authority NONE** and **deploy authority NONE**, preserves unrelated work, and returns a completion manifest with final SHA/tree, changed files, tests/exit codes, hosted runs, migrations/data risks, rollback, unresolved gates, and explicit confirmation that production was not changed.

## B01 — Credential propagation and privileged reset stop-ship

- **Model/config:** GPT-5.6 Sol, High, subagents off.
- **Branch/worktree/base:** `codex/B01-credential-reset-stopship`; `D:\CodexWorktrees\mbfd-hub-b01-credential-reset`; `3cbea3c95b9bf4333b9830f9bcec749da7ff28eb`.
- **Problem/evidence/severity:** CONFIRMED Critical ScreenTinker plaintext capture; Workgroup manager global reset; Admin equal/stronger reset.
- **Dependencies:** none; first merge in program.
- **Owned files/modules:** `app/Casts/HashedAndCaptured.php`; password cast lines in `app/Models/User.php`; `app/Observers/SyncToScreentinker.php`; observer/event registration only in `app/Providers/AppServiceProvider.php`; reset/password-creation actions in `app/Filament/Resources/UserResource.php` and `app/Filament/Resources/Workgroup/WorkgroupMemberResource.php`; focused tests.
- **Shared interfaces:** User password assignment remains Laravel one-way hashing; no downstream provisioning replacement in this ticket.
- **DO NOT MODIFY:** migrations; User/Employee relationships; auth/session/routes/panel providers; roles; Bid; Daily; Media Control/ScreenTinker code or production; dependencies/lockfiles.
- **Outcome:** use standard `hashed` cast; no capture API/observer/egress; remove Workgroup password action; remove operator-selected Admin reset and any Workgroup new-user password handling that violates the same rule, replacing account creation only with disabled/pending behavior if it can be done without schema change, otherwise block it explicitly for C02.
- **Acceptance:** repository search finds no recoverable/captured User plaintext path; HTTP fake proves no password outbound; direct Livewire action cannot invoke removed resets; normal password change hashes once; rollback cannot restore mirroring.
- **Tests/local CI:** TDD negative tests; Composer validate; changed-file Pint; PHPStan affected paths; focused PHPUnit then full isolated PHP suite; Node CI configuration; Gitleaks/secret scan; relevant build only if source requires it.
- **Hosted:** CI, Static Analysis, Gitleaks, Trivy, Generated Assets Guard as applicable.
- **Security/mobile/data:** Critical security fix; no UI credential dead end may silently create a known password; no migration/data write.
- **Rollback:** revert UI removal only if a safer activation path exists; never restore plaintext cast/observer.
- **Merge order:** first.

## B02 — Deterministic identity reconciliation preview

- **Model/config:** GPT-5.6 Sol, High, subagents off.
- **Branch/worktree/base:** `codex/B02-identity-reconciliation-preview`; `D:\CodexWorktrees\mbfd-hub-b02-identity-preview`; baseline SHA.
- **Problem/evidence/severity:** CONFIRMED High: 30 Users, 237 Employees, zero exact links; unsafe auto-merge risk.
- **Dependencies:** `IDENTITY_ARCHITECTURE.md`; owner ledger optional input, never inferred.
- **Owned files/modules:** new `app/Console/Commands/AuditIdentityReconciliation.php`, new isolated `app/Services/IdentityReconciliation/*`, `app/Data/IdentityReconciliation/*`, safe exporter, new tests/fixtures. Exact names may match local convention but must stay under one module.
- **Shared interfaces:** read-only access to User/Employee/Spatie/Workgroup tables; CSV columns include every field required by the architecture.
- **DO NOT MODIFY:** existing User/Employee models; migrations; passwords; auth/session/routes; Snipe records/API; production; dependencies/lockfiles.
- **Outcome:** `scan/propose` dry-run writes only an operator-selected local artifact path, never DB; deterministic normalized output; keyed fingerprints rather than hashes; explicit `LINK/CREATE_USER/QUARANTINE/BLOCKED`; snapshot/drift token.
- **Acceptance:** every User and Employee accounted for; exact owner Employee ID only; names/emails never auto-link; collision/historical/test/service/external disagreement/credential conflict block; rerun byte-stable; secrets/session/auth material absent.
- **Tests/local CI:** TDD golden fixtures for duplicates, whitespace/leading zeros, unmatched Users, multiple Users, bcrypt/unsupported algorithms, external collisions, role/Workgroup preservation, deterministic sort, secret denylist; changed Pint/PHPStan; PHPUnit SQLite/PostgreSQL read-only lane; Gitleaks.
- **Hosted:** CI/Static Analysis/security workflows; attach sanitized preview fixture artifact if workflow permits without production data.
- **Security/mobile/data:** data-minimization; output permissions and retention documented; no PII-rich artifact committed.
- **Rollback:** delete local preview artifact; database unchanged.
- **Merge order:** after/independent of B01; required before C01 apply design.

## B03 — Daily wide responsive foundation and acceptance harness

- **Model/config:** GPT-5.6 Terra, High, subagents off.
- **Branch/worktree/base:** `codex/B03-daily-responsive-foundation`; `D:\CodexWorktrees\mbfd-hub-b03-daily-responsive`; baseline SHA.
- **Problem/evidence/severity:** CONFIRMED P1: Daily is a tiny 3-column island at 3840; responsive/quality checks are fragmented.
- **Dependencies:** `MOBILE_ACCEPTANCE_MATRIX.csv`.
- **Owned files/modules:** `StationListPage.tsx`, `StationCard.tsx`, Daily-only CSS/tokens needed by those components, Playwright shared browser-quality/viewport helpers and login/Daily selector specs/config. Reuse existing dependencies.
- **Shared interfaces:** no API shape change; station card routes/accessible names remain stable.
- **DO NOT MODIFY:** authentication/login behavior; backend; global Filament shell; Daily forms, identity, offline/Dexie/service worker; API routes; manifests/lockfiles.
- **Outcome:** bounded 1/2/3/4/5-column behavior through 3840, max display workspace, bounded typography/spacing, no horizontal overflow, 44 px/focus behavior, reusable console/network/overflow fixture.
- **Acceptance:** 320/360/390/430/768/1024/1280/1440/1920/2560/3840 tests; current 390 task flow retained; 3840 no postage-stamp island; zero unexpected console/page/network failure; visual evidence reviewed.
- **Tests/local CI:** Daily install/typecheck/build/audit; targeted and existing Daily Playwright; root build only if shared test config requires it; final diff/secret scan.
- **Hosted:** required CI plus a browser job if currently required; if existing CI omits this suite, return exact promotion manifest for TEST-E2E, do not edit shared workflow without ownership.
- **Security/mobile/data:** presentation-only; no public data expansion or behavior change.
- **Rollback:** component/CSS revert; no data impact.
- **Merge order:** independent first wave; before I01.

## C01 — Canonical identity and session schema

- **Problem/evidence/severity:** High; no FK/status/security/session registry.
- **Dependencies:** B01, B02, owner-approved mapping states, A06 commit.
- **Owned files/modules:** all User/Employee identity/session/device migrations; User/Employee relationships/status casts only; registry/persistent-login/device models and factories; migration fixtures.
- **Shared interfaces:** exact fields in Identity Architecture; no login implementation.
- **DO NOT MODIFY:** routes/controllers/panels/domain FKs/legacy Employee auth behavior, integrations, frontend, lockfiles.
- **Outcome:** additive FK/status/security/session/device foundation; no production apply.
- **Acceptance/tests:** realistic PostgreSQL upgrade, idempotent constraints, collision refusal, rollback rehearsal, all roles/permissions/Workgroups/domain FK counts invariant; SQLite compatibility where supported.
- **Security/mobile/data/rollback:** restrict-delete; no PII-heavy registry; down/forward and restore plan; merge before D01/D02.

## C02 — RoleAssignmentPolicy and recovery-safe account administration

- **Problem/evidence/severity:** Critical/High fragmented delegation and undefined recovery.
- **Dependencies:** B01; owner delegation and recovery policy. If owner decisions absent, implement deny-by-default technical safe default only and remain BLOCKED for final policy.
- **Owned:** new policies/services/audit events; role/account Filament actions after B01; tests.
- **DO NOT MODIFY:** schema/migrations, auth routes/session config, Workgroup content scoping, downstream integrations.
- **Outcome:** one delta policy; no equal/stronger/self/out-of-delegation/last-admin action; activation not known password.
- **Tests:** exhaustive actor-target-delta, forged Livewire/direct action, stale reauth, disable/role loss hooks; hosted/local full PHP gates.
- **Rollback/merge:** feature-flag or policy rollback cannot reopen B01 reset paths; merge after B01 and before D01 final account security.

## C03 — Snipe numeric identity reconciliation

- **Problem/evidence/severity:** High; email-only sync risks duplicates/history.
- **Dependencies:** B02 output and non-production Snipe export; C01 owns any mapping migration.
- **Owned:** Snipe read-only reconciliation service/command, mock contracts, mapping proposal; later source update only after C01 schema is merged.
- **DO NOT MODIFY:** live Snipe, create/update asset/user behavior during preview, auth, Media Control, credentials.
- **Outcome:** exact numeric-ID mapping and collision report; fail closed on ambiguity; break-glass retained.
- **Tests:** recorded sanitized fixtures, duplicate email/employee number/numeric ID, timeout/retry, no-create assertion, history identifiers.
- **Rollback/merge:** preview only initially; mapping apply serialized after C01.

## C04 — Pilot runtime prerequisites

- **Problem/evidence/severity:** `/health` 404, failed jobs unknown, `artisan serve`; P1 release/pilot risk.
- **Dependencies:** none for diagnosis; no production mutation.
- **Owned:** health registration/config/tests; failed-job root-cause diagnostics/runbook; image/Supervisor/web-server files if replacement is evidence-backed; runtime tests.
- **DO NOT MODIFY:** auth/identity/domain behavior, production jobs/containers, dependencies unless a concrete server implementation requires a separately reviewed change.
- **Outcome:** distinct liveness/readiness; approved failed-job disposition plan; isolated production HTTP candidate with graceful shutdown/load/rollback.
- **Tests:** image runtime in unique Compose project, no production mounts/ports, DB/Redis fakes, `/up`/`/health`, worker/scheduler/Reverb coexistence.
- **Rollback/merge:** immutable-image rollback; merge before pilot candidate, not before B preview work.

## D01 — Canonical login, credential transition, sessions and account security

- **Problem/evidence/severity:** High program core; two guards, unsafe persistence, no revocation.
- **Dependencies:** B01/B02, C01/C02, owner mapping/recovery/device policy.
- **Owned:** `config/auth.php`, `config/session.php`, canonical auth controllers/requests/middleware/services/views, `routes/web.php`, session registry/persistent login UX, logout/security routes, login-focused tests. Sole auth/web-route owner.
- **DO NOT MODIFY:** `bootstrap/app.php`, `routes/api.php`, domain controllers/PWA, panel convergence, external integrations, domain FKs.
- **Outcome:** exact `/login`/`logout` contract; approved bcrypt transition; context expiry/revocation; restricted must-change; generic errors; safe intended path.
- **Tests:** RA-002–010 and credential migration tests on SQLite/PostgreSQL/Redis/browser; all existing auth/forced-change tests.
- **Rollback:** cohort/feature flags and preserved legacy hashes during approved window; never restore B01 defects.
- **Merge:** before D02/E/F/H01.

## D02 — AuthenticatedMemberContext and same-origin API

- **Problem/evidence/severity:** High; no canonical server context/stateful SPA API.
- **Dependencies:** C01 and D01 interfaces.
- **Owned:** `AuthenticatedMemberContext`, context value objects/authorizers, `bootstrap/app.php` stateful middleware, minimal `/api/me/context` and its centralized route block, tests.
- **DO NOT MODIFY:** web login/session policy, panels, domain mutation controllers, frontend queues, external APIs.
- **Outcome:** fail-closed immutable context; minimal no-store DTO; 401/419/CSRF/origin contract.
- **Tests:** spoofed context, disabled/version/expiry, role loss, cross-origin, cache headers, no sensitive fields, query budget.
- **Rollback/merge:** disable context endpoint/feature; merge after D01 contract and before domain conversions.

## E01 — Station/device WorkContext

- **Problem/evidence/severity:** High dependency for station/Daily; PIN/device/human conflated.
- **Dependencies:** C01 device schema, D01/D02.
- **Owned:** StationWorkContext/device-principal services, enrollment/lease policy implementation, transitional PIN adapter, tests.
- **DO NOT MODIFY:** human login, domain forms/controllers, central API routes, Employee home-station data semantics.
- **Outcome:** source/precedence/locked/editable/expiry; human overlay separate; lower trust cannot override.
- **Tests:** deep link/device/assignment/selection/default/manual precedence, cross-station denial, revoke/expiry, PIN cannot assert human.
- **Rollback/merge:** keep current PIN capability behind transition; merge before F wave.

## E02 — Filament guard convergence

- **Problem/evidence/severity:** High; four login islands and Employee guard.
- **Dependencies:** D01/D02, C02.
- **Owned:** panel providers, Employee middleware/login redirects, panel auth adapters, navigation destinations, representative panel tests.
- **DO NOT MODIFY:** canonical routes/config, User/Employee schema, Workgroup access/context internals except adapter calls, domain controllers, global UI redesign.
- **Outcome:** one `web` User session across Admin/Employee/Training/Workgroups; legacy login GET compatibility redirects; policy semantics preserved.
- **Tests:** personas, direct routes, forced change, role loss mid-session, Workgroup A/B leakage, intended path, mobile panel boundary.
- **Rollback/merge:** cohort flag and short dual-run; no new Employee sessions after cutover flag; before G01 retirement.

## F01 — Daily Actor and actor-affine offline/PWA

- **Problem/evidence/severity:** High/P0 forgeable actor and cross-account queue.
- **Dependencies:** D01/D02/E01; F02/F03 route manifests.
- **Owned:** Apparatus Daily controllers/services, Daily React identity UI, Dexie migrations/state machine, service worker/update/quota, central `routes/api.php` domain-auth stitch, Daily tests.
- **DO NOT MODIFY:** owner apparatus classifications/ledger history, Station Request/Inspection/Inventory controller internals owned by F02/F03, auth/session core, panel providers.
- **Outcome:** server Actor, UUID/hash idempotency, Jane/John isolation, controlled reauth, durable restart, honest queued states, policy fail-closed.
- **Tests:** RA-019–025 plus all existing Daily integrity/PostgreSQL/Playwright; installed SW lane.
- **Rollback/merge:** preserve completed local evidence; compatibility route telemetry; merge after F02/F03, then apply central routes once.

## F02 — Station Request, Inspection and Equipment semantics

- **Problem/evidence/severity:** High public Actor impersonation/missing inspector and ambiguous requester semantics.
- **Dependencies:** D02/E01 and classified Actor/Subject rows.
- **Owned:** those three domain controllers/requests/services/models/components and focused tests; no central API routes.
- **DO NOT MODIFY:** `routes/api.php`, auth/session, inventory/TRT, Daily apparatus/offline, historical rows/signatures, station policy data.
- **Outcome:** server Actor; beneficiary/reviewer/attestation distinct; canonical station; legacy fields retained as provenance; failure notes verified/fixed if confirmed by failing test.
- **Tests:** anonymous/forged/cross-station/idempotency/signature/reviewer/history/mobile; return route manifest.
- **Rollback/merge:** additive fields/events; no destructive backfill; merge before F01 stitch.

## F03 — Station Inventory and TRT Actor/device/idempotency

- **Problem/evidence/severity:** High signed browser actor, public nullable actors, no idempotency.
- **Dependencies:** D02/E01 and matrix classification.
- **Owned:** inventory v1/v2/TRT controllers/services/components/Dexie queue pieces specific to these forms and tests; no central API routes.
- **DO NOT MODIFY:** human auth, station request/inspection/equipment, Daily apparatus queue, existing PDFs/audit rows, central routes.
- **Outcome:** canonical human overlay + device/station capability; v1 retire/gate plan; canonical station ID; client UUID/hash; no PIN-as-human.
- **Tests:** forged signed actor, PIN-only denial for human record, cross-station, retry/response loss, PDF provenance, mobile.
- **Rollback/merge:** retain PIN transition and history; merge before F01 stitch.

## G01 — Legacy human guard and login retirement

- **Problem/evidence/severity:** program cleanup after proven convergence.
- **Dependencies:** E02 plus every human domain migrated; observed zero legacy use for owner-approved window.
- **Owned:** legacy Employee login POST disable/remove, guard/provider/config cleanup coordinated with D01 owner, legacy cookies/tokens, later authentication-column retirement migration under migration-owner review.
- **DO NOT MODIFY:** Employee operational records/FKs/history; no same-release destructive removal without rehearsal.
- **Outcome:** no normal separate human login or Employee password verification; compatibility GET redirects only during declared window.
- **Tests:** zero-use telemetry, all panels/deep links, rollback without password propagation, migration/restore.

## G02 — Personnel and apparatus-service convergence

- **Problem/evidence/severity:** legacy guard on otherwise strong Actor/beneficiary patterns.
- **Dependencies:** E02/D02.
- **Owned:** Personnel Request, apparatus-service Employee pages/controllers/services/tests.
- **Outcome:** context Actor while preserving beneficiary/requester and all snapshots/history.
- **Tests:** actor differs from beneficiary, ownership, officer/reviewer, documents/notifications, mobile.

## G03 — Operational Forms semantic and authorization repair

- **Problem/evidence/severity:** runtime authorization and Actor/preparer/subject meaning unproven; failed PDFs.
- **Dependencies:** field classification, D02/E02, C04 queue evidence.
- **Owned:** Operational Forms pages/requests/services/jobs/documents/imports/tests.
- **Outcome:** per-form explicit Actor/Subject/preparer/reviewer; secure document/PDF/import/history/delete; no hidden defects.
- **Tests:** owner/non-owner/admin, actor tamper, import preview/apply, revision conflict, PDF retry/idempotency/private storage/mobile.

## G04 — Video and remaining human surfaces

- **Problem/evidence/severity:** Employee guard and physical/realtime gaps.
- **Dependencies:** D02/E01/E02.
- **Owned:** video human entry/control adapters and remaining inventoried human surfaces, not Media Control.
- **Outcome:** canonical User/Employee context; device/station separate; LiveKit/Reverb abilities preserved.
- **Tests:** token subject, command step-up, station identity, webhook, reconnect, synthetic and physical acceptance.

## H01 — Bid delegated identity exchange

- **Problem/evidence/severity:** High password crossing.
- **Dependencies:** D01/D02, Bid owner/staging, key/replay contract.
- **Owned:** Hub assertion/code endpoints and middleware plus Bid consumer in its separately authorized repository; sole owner for those integration routes.
- **DO NOT MODIFY:** human password handling except removing old endpoint after acceptance; no live Bid mutation without authority.
- **Outcome:** <=60-second one-time audience-bound exchange; old verifier 410/404 after monitored cutover.
- **Tests:** expiry/replay/audience/issuer/signature/redirect/disabled/role loss/no-password payload; dual-run telemetry.
- **Rollback:** re-enable assertion version, never password verifier.

## H02 — ScreenTinker and Snipe completion

- **Problem/evidence/severity:** Critical/High external identity risks.
- **Dependencies:** B01, C03, downstream owner authorization.
- **Owned:** Hub passwordless/service-principal provisioning adapters and persisted Snipe numeric mapping; downstream work isolated in owning repos.
- **Outcome:** no password mirroring; exact IDs/history; break-glass Snipe admin; least privilege/rotation.
- **Tests:** contract fakes, collisions, no-create-on-ambiguity, egress assertions, staging SSO/provisioning.
- **Rollback:** service/federation version rollback only; never mirroring or email-only create.

## H03 — Runtime, queue, realtime, scheduler and container hardening

- **Problem/evidence/severity:** P1 release reliability.
- **Dependencies:** C04 and integrated domain jobs.
- **Owned:** queue topology/job contracts, scheduler alerts, Reverb/runtime config, production HTTP/image/resource controls, health/observability tests.
- **Outcome:** isolated workers, bounded retry/idempotency, 101/private channels, graceful web runtime, reviewed limits, explicit health.
- **Tests:** disposable Compose, Redis/PostgreSQL, restart/failure/load/shutdown, no production mounts/ports, rollback.

## I01 — Full mobile, accessibility and performance sweep

- **Problem/evidence/severity:** P1/P2 cross-stack consistency and acceptance.
- **Dependencies:** canonical shell and all domain convergence; B03 foundation.
- **Owned:** global shell adapters, tokens, table/dialog/field/signature primitives, per-resource responsive changes, browser matrix, query/bundle budgets.
- **Outcome:** frozen mobile contract at all viewports; WCAG 2.2 AA evidence plus operational 44 px rule; useful large-display density; no style-only rewrite.
- **Tests:** all `MOBILE_ACCEPTANCE_MATRIX.csv`, keyboard/zoom/reduced motion/screen-reader/manual, physical firefighter/admin/4K acceptance.
- **Rollback:** component-level; no security/domain semantic regression.

## J01 — Integration and Release Captain

- **Model/config:** GPT-5.6 Terra, High, new clean Codex Desktop chat, subagents off initially.
- **Branch:** new `codex/integration-unified-login-release-<date>` from owner-approved main SHA; never invent the base.
- **Dependencies:** all selected ticket SHAs and manifests; no unresolved P0/P1.
- **Authority:** sequential merges, integration-only fixes, full validation, immutable candidate/evidence. No deployment until owner authorization after J02.
- **Outcome:** one frozen source/tree/image candidate; local/hosted matrix; backups/rollback/canary plan.
- **Tests:** all RA P0/P1, exact CI, image, restore rehearsal; candidate changes invalidate prior approval.
- **Rollback:** known-good immutable image/data/config; preserve historical Daily ledger; no destructive reset/cleanup.

## J02 — Independent Sol risk gate

- **Model/config:** GPT-5.6 Sol, High, new clean chat, read-only, subagents off.
- **Mission:** find a concrete reason the exact J01 candidate should not deploy.
- **Scope:** auth, authorization, mapping, passwords, sessions, offline ownership, escalation, migrations/history, integrations, secrets, runtime, backup/rollback and all unobserved acceptance.
- **Authority:** no writes, merge, waiver, deployment or production mutation.
- **Outcome:** blocking findings with exact evidence or a bounded no-blocker conclusion tied to candidate SHA/tree/digest. Any fix returns to J01 and creates a new candidate.
