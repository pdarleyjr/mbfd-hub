# Implementation Dependency Graph and Parallel Topology

## Hard dependency graph

```text
G-A06 commit missing audit evidence

B01 stop plaintext propagation + remove unsafe resets
  -> canonical credential apply allowed
  -> ScreenTinker replacement can never roll back to mirroring

B02 deterministic identity/Snipe preview
  + owner mapping/recovery/delegation/device decisions
  -> C01 User-Employee FK/status/security/session schema
  -> C03 Snipe numeric mapping apply design

B03 mobile contract foundation + Daily wide acceptance harness
  -> later shell/table/accessibility sweep

B01 + B02 + owner-approved mapping + C01
  -> D01 canonical hashes/login/session/account security
  -> D02 AuthenticatedMemberContext + stateful API + /api/me/context

D01 + D02
  -> E01 station/device context
  -> E02 Filament Employee/Admin/Training/Workgroup guard convergence
  -> H01 Bid delegated exchange

D01 + D02 + E01
  -> F01 Daily Actor + actor-affine offline ownership
  -> F02 Station Request/Inspection/Equipment Actor conversion
  -> F03 Inventory/TRT Actor/device conversion

E02 + F01/F02/F03
  -> G01 legacy human guard/login retirement
  -> G02 Personnel/Apparatus Service convergence
  -> G03 Operational Forms semantic/authorization repair
  -> G04 Video/other human surface convergence

C02 role delegation + D01 session revocation + all domain convergence
  -> H02 external identity/Snipe/ScreenTinker completion
  -> H03 runtime/queue/Reverb/scheduler/container remediation
  -> I01 full mobile/accessibility/performance sweep

all above + P0/P1 acceptance
  -> J01 Integration/Release Captain
  -> J02 independent Sol risk gate
  -> immutable image + backup/restore rehearsal
  -> one authorized production deployment
  -> canary/soak/physical/human acceptance
  -> closeout
```

## Shared-file ownership

| Shared surface | Sole owner during its wave |
|---|---|
| `User` password cast/observer registration and unsafe reset UI | B01 |
| Identity preview command/report schema | B02 |
| User/Employee schema, all identity/session migrations, model relationships | C01 |
| Role/delegation policies and role-management actions | C02 |
| Canonical login/logout/account-security middleware, `config/auth.php`, `config/session.php`, `routes/web.php` | D01 |
| `bootstrap/app.php`, `routes/api.php` stateful API group, `AuthenticatedMemberContext`, `/api/me/context` | D02; later F01 owns the one centralized domain-route stitch |
| Station/device principal and WorkContext | E01 |
| Filament panel providers and legacy Employee guard convergence | E02 |
| Daily React/PWA/Dexie and Apparatus Daily controllers | F01 |
| Station Request/Inspection/Equipment domain controllers/services/components | F02; no `routes/api.php` |
| Inventory/TRT controllers/services/components | F03; no `routes/api.php` |
| Root manifests/lockfiles | no worker unless its ticket explicitly requires it; Release Captain resolves necessary changes |
| Global shared shell | I01 after canonical auth; B03 owns only Daily station-selector/wide-layout foundation |
| Final integration, shared route stitch, conflicts, image and release evidence | J01 Release Captain |

Two workers never edit one shared file concurrently. Domain workers that need a central route change return a route manifest; F01/J01 applies the single centralized patch in merge order.

## Wave B — next parallel frontier

All three branch from `3cbea3c95b9bf4333b9830f9bcec749da7ff28eb`, use new isolated worktrees, have no merge/deploy authority, and must not touch production.

### B01 — Credential Propagation and Privileged Reset Stop-Ship

- **Model:** GPT-5.6 Sol; High; Codex Desktop; new clean chat; subagents off.
- **Branch:** `codex/B01-credential-reset-stopship`
- **Owns:** `app/Casts/HashedAndCaptured.php`, `app/Models/User.php` password cast only, `app/Observers/SyncToScreentinker.php`, observer registration in `AppServiceProvider`, password actions in `UserResource` and `WorkgroupMemberResource`, focused tests.
- **Outcome:** standard one-way hash, no ScreenTinker password egress, remove Workgroup global password action, disable operator-selected Admin reset until recovery policy exists.
- **Do not modify:** migrations, auth/session config, routes, User/Employee relationship, roles, downstream Media Control/ScreenTinker, Bid, Daily, lockfiles.
- **Dependencies:** none. Rollback must not restore mirroring.

### B02 — Identity Reconciliation Preview Infrastructure

- **Model:** GPT-5.6 Sol; High; Codex Desktop; new clean chat; subagents off.
- **Branch:** `codex/B02-identity-reconciliation-preview`
- **Owns:** new read-only command/service/DTO/exporter and fixtures/tests under explicitly named identity-reconciliation modules.
- **Outcome:** deterministic no-write CSV/JSON preview for every User/Employee, role/permission/Workgroup/external mapping seams, safe hash fingerprints, collision/drift blocking, zero secrets.
- **Do not modify:** existing models, migrations, auth/session/routes, passwords, production, Snipe records, lockfiles.
- **Dependencies:** frozen CSV columns and identity contract; owner mapping is input, not invented.

### B03 — Mobile Contract Foundation and Daily Wide Acceptance

- **Model:** GPT-5.6 Terra; High; Codex Desktop; new clean chat; subagents off.
- **Branch:** `codex/B03-daily-responsive-foundation`
- **Owns:** Daily station selector/card wide layout, Daily-only responsive tokens required for that screen, Playwright viewport/browser-quality helpers and tests for login/Daily selector.
- **Outcome:** intentional 320–3840 behavior, 44 px/focus/no-overflow assertions, current 390 behavior retained, 4K postage-stamp defect removed.
- **Do not modify:** login/auth behavior, global Filament layouts, forms/actor/offline queue/service worker, API routes, backend models/migrations, manifests/lockfiles unless an existing command strictly requires no content change.
- **Dependencies:** frozen mobile matrix only.

These three are safe concurrently because B01 owns a narrow backend security seam, B02 adds isolated read-only tooling without editing models/schema, and B03 owns a bounded Daily presentation/test seam. They share no application source file and make no assumptions about the future schema/login implementation.

## Following waves

### Wave C — foundations after B review

1. **C01 Canonical identity/session schema:** surrogate User→Employee FK, account status/security version, session registry/persistent credential/device schema, production-shaped migration fixtures. Sole migration/model owner.
2. **C02 RoleAssignmentPolicy and recovery-safe administration:** owner-approved delegation; direct-action policy tests; activation-only replacement for reset. No schema edits.
3. **C04 Runtime pilot prerequisites:** `/health`, failed-job diagnosis/runbook, production HTTP candidate contract. No production replay/restart/deploy.

Owner must approve the mapping/delegation/recovery inputs before C01 apply logic or C02 final policy is accepted.

### Wave D — canonical authentication and context

1. **D01 Canonical credential/login/session/account security:** Employee-ID login, bcrypt transition, context expiry, revocation/session UI, logout, must-change; sole auth/session/web-route owner.
2. **D02 Identity Context/stateful API:** `AuthenticatedMemberContext`, Sanctum stateful middleware, `/api/me/context`, 401/419 contract; sole bootstrap/API-context owner.
3. **C03 Snipe numeric identity reconciliation:** non-production export mapping and collision proof; no create/update on ambiguity.

### Wave E — context and panel convergence

1. **E01 Station/device WorkContext and transitional PIN capability.**
2. **E02 Employee/Admin/Training/Workgroups canonical guard convergence; preserve Workgroup isolation.**
3. **TEST-E2E promote non-Daily browser suites and Redis/realtime lanes**, without changing domain behavior.

### Wave F — operational Actor/offline conversion

1. **F01 Daily canonical Actor, actor-affine Dexie/service-worker sync, centralized API route stitch.**
2. **F02 Station Request/Inspection/Equipment Actor/Subject repair.**
3. **F03 Station Inventory v1/v2 and TRT Actor/device/idempotency repair.**

Merge order: F02 then F03 then F01 central route stitch, with all P0 actor/account-switch tests green.

### Wave G — remaining human surfaces and legacy retirement

1. Personnel Requests/Apparatus Service and remaining Employee workflows.
2. Operational Forms per-field Actor/Subject/preparer/reviewer classification and PDF/job acceptance.
3. Video conferencing and remaining APIs/panels.
4. Legacy Employee guard/login POST disable, observation, then later column/config removal. Never combine disable and destructive removal in one unrehearsed release.

### Wave H — integrations and runtime

1. Bid one-time delegated assertion and old verifier retirement.
2. ScreenTinker passwordless/service-principal acceptance and Snipe numeric/SSO completion; downstream changes separately authorized.
3. Queue isolation, scheduler alerts, Reverb 101/private channels, production HTTP server, resource limits, backup/restore and observability.
4. Fresh read-only Cloudflare control-plane evidence; changes only if evidence and separate authority require them.

### Wave I — full mobile/accessibility/performance sweep

Canonical shell adapters, per-table strategy, fields/signatures/dialogs, target/focus/reduced motion, all viewports, query/bundle budgets, physical phone/tablet/4K and firefighter/admin acceptance.

### Wave J — integration and release

Release Captain merges sequentially into a new integration branch, fixes only integration defects, runs the complete acceptance matrix, freezes a candidate, and hands it to the independent Sol risk gate. Any finding-driven source change creates a new candidate and repeats affected gates. Only explicit owner authorization opens backup/restore, maintenance, deployment, canary, soak, and closeout.

## Release Captain configuration

- GPT-5.6 Terra, High, Codex Desktop, new clean chat, subagents off initially.
- Authority: integration branch, sequential merges, integration fixes, full validation, immutable candidate, evidence package.
- Production authority: **none until** independent risk gate passes and owner explicitly authorizes the maintenance window/deployment.
- Must preserve the known-good image and historical Daily ledger and use restricted backup/restore/rollback proof.

## Independent final risk gate

- GPT-5.6 Sol, High, new clean chat, read-only, subagents off.
- Mission: **Find a concrete reason this integrated MBFD Hub release should NOT be deployed.**
- Review auth, authorization, mapping, password handling, sessions, offline ownership, privilege escalation, migrations/data history, integrations, secrets, runtime, backup/rollback, and all unobserved gates.
- It cannot deploy, merge, waive gates, or turn missing evidence green.
