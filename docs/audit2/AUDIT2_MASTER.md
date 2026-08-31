# MBFD Hub System Audit 2.0 — Master Reconciliation

**Task:** A07
**Evidence cut:** 2026-08-31 America/New_York
**Authoritative source:** `3cbea3c95b9bf4333b9830f9bcec749da7ff28eb`
**Authoritative deployed image:** `ghcr.io/pdarleyjr/mbfd-hub@sha256:31a541215b1f422cad2ed7b37932851c3d4ff257edd58496ab48e6f50268ea79`
**Historical Daily ledger activation:** `d832c2fb00e6680443f48ae0ac78b127bfe506c6` — immutable historical provenance; not reopened or rewritten.
**Disposition:** **STOP-SHIP for Unified Login implementation beyond isolated prerequisites.** The existing production release remains closed and was not changed.

## Evidence standard

- **CONFIRMED:** directly observed in the frozen source, a verified audit commit, a current read-only production query/inspection, or current GitHub metadata.
- **REPORTED:** present in an audit narrative but not independently committed or reproduced at this gate.
- **INFERRED:** a design or risk conclusion from confirmed evidence; it still needs implementation/runtime acceptance.
- **UNKNOWN:** unavailable, unsafe to observe read-only, or dependent on an owner decision.

Source, runtime, CI, browser, physical-device, external control-plane, and human acceptance are separate evidence classes. A pass in one does not make another green.

## Six-audit verification

| Audit | Branch / observed SHA | Base and scope check | Artifact state | Master classification |
|---|---|---|---|---|
| A01 Identity/Security | `audit2/A01-identity-security` / `1d73287d51e4098df57c24d40bb56ef655c8ba84` | direct child of baseline; only `docs/audit2/A01-identity-security.md` added | committed; read completely | CONFIRMED |
| A02 Domain/Functionality | `audit2/A02-domain-functionality` / `4ddcd52cbd68f4151605205cb703987216872005` | direct child; only intended artifact added | committed; read completely | CONFIRMED |
| A03 Daily/PWA/Station | `audit2/A03-daily-pwa-station` / `2b91848fbca73b4e3d405af382adcc9cae3d6c5c` | direct child; only intended artifact added | committed; read completely | CONFIRMED |
| A04 Mobile UX | `audit2/A04-mobile-ux` / `67f8eeefb219651fd9acc91b31415e6a16171e54` | direct child; only intended artifact added | committed; read completely | CONFIRMED |
| A05 Runtime/Integrations | `audit2/A05-runtime-integrations-performance` / `e73875896bbc44deb507c3252c7dc4d53c9e99e6` | direct child; only intended artifact added | committed; read completely | CONFIRMED |
| A06 Test Architecture | `audit2/A06-test-coverage` / baseline SHA | branch has no audit commit | full 35 KiB report exists untracked in its worktree and was read completely | REPORTED until committed |

No audit commit was merged into A07. A07 was created directly from the frozen production baseline.

## Current-state reconciliation

| Area | Reconciled state | Evidence |
|---|---|---|
| Human identities | 30 Users, 237 Employees, zero exact User-to-Employee matches; 29 Users have no Employee ID and `FROC-TEST-001` matches no Employee | CONFIRMED production read-only A01; source rechecked |
| Credential algorithms | all 30 User hashes and all 237 Employee hashes are bcrypt; only algorithm family/count was queried | CONFIRMED current production read-only query |
| Human guards | `web/users` and `employee/employees` are separate session guards and password stores | CONFIRMED source |
| Login doors | `/admin/login`, `/employee/login`, `/training/login`, `/workgroups/login`; `/login` redirects to Admin | CONFIRMED source/browser audits |
| ScreenTinker | User password assignment deliberately captures plaintext in a `WeakMap` and synchronously posts it downstream | CONFIRMED source and production configuration/log evidence |
| Privileged reset | Workgroup manager can set a same-group User password (minimum 4); Admin User resource allows admin/super-admin password reset (minimum 6) without a target ceiling | CONFIRMED source and production role adjacency |
| Bid | Bid receives Employee ID and plaintext Employee password, sends both to Hub, and Hub verifies the Employee hash | CONFIRMED source; storage/logging inside Bid UNKNOWN |
| Actor attribution | Daily, Station Request, Station Inspection, fire-equipment, big-ticket, legacy inventory, TRT, and Station Inventory V2 have unauthenticated or browser-selected human attribution gaps | CONFIRMED source |
| Daily policy | all 26 apparatus are `unknown`; source fails closed and excludes them from required completion | CONFIRMED production/source |
| Offline | Daily and generic Dexie queues have no canonical actor affinity; service-worker POST fallback does not durably enqueue | CONFIRMED source |
| Session | Redis sessions, nominal 120-minute idle, no absolute timeout, framework remember default up to 400 days, no active-account state or authoritative session registry | CONFIRMED source/runtime |
| Workgroups | active-membership scoping, selected-context validation, and targeted anti-leakage tests are strong; global password reset is outside legitimate Workgroup authority | CONFIRMED source/test inventory |
| Runtime | exact image/source healthy on `/up`; `/health` 404; six failed jobs; `artisan serve`; one heterogeneous worker; no container resource limits | CONFIRMED current/read-only A05 evidence |
| Snipe-IT | user reconciliation is email-based and does not persist Snipe numeric IDs; duplicates/history cannot be guaranteed | CONFIRMED source; actual Snipe record state UNKNOWN |
| Mobile | Daily works at 390 px without overflow but is a tiny 3-column island at 3840 px; panel/table behavior and physical mobile acceptance remain UNKNOWN | CONFIRMED browser/source |
| CI | hosted Actions are currently available; multiple PR workflows completed successfully on 2026-08-31 | CONFIRMED current GitHub run metadata |

## Contradictions and resolutions

| Topic | Conflicting statements | Resolution |
|---|---|---|
| A06 completion | Brief called A06 finished; branch points to baseline and artifact is absent from Git | The full untracked report is usable as REPORTED planning input, but A06 is not a verified audit branch. Committing its sole artifact is gate `G-A06` before implementation branches are integrated. |
| Bid password boundary | A05 says no reusable password is sent “downstream”; A01/source says Bid receives and forwards it | The browser submits the human password to Bid, so the password crosses the Bid boundary and then the Hub bridge. This is CONFIRMED and stop-ship. Whether Bid stores/logs it is UNKNOWN. |
| Daily actor severity | A02 labeled the Daily issue Medium while A01/A03 classify the same forgeable actor/offline ownership boundary High/P0 | Master severity is **HIGH / STOP-SHIP** because the persisted human actor can be forged and later session-enabling would create cross-user offline attribution. |
| `/health` critical path | A05 grouped it among pre-release repairs; identity audits did not make it a login prerequisite | It does not block preview tooling or local identity development. It is **MUST FIX BEFORE UNIFIED LOGIN PILOT on an immutable candidate** because pilot rollback/monitoring needs an explicit dependency-health contract. `/up` alone remains liveness. |
| GitHub Actions | Access note says unavailable until September 1 | Current GitHub runs show hosted CI available on August 31. Every implementation ticket requires local equivalents plus hosted validation; current availability must be rechecked per branch. |
| Session encryption default | source defaults encryption on in production, but A01 observed runtime false | Runtime wins for current-state classification: production sessions are unencrypted. Implementation must test and explicitly set the target; source default is not proof of effective configuration. |
| Framework version | workspace guidance describes Laravel 11; the frozen `composer.json` requires `laravel/framework ^12.61.1` and production runs that release family | Exact source/lock/runtime govern implementation: use Laravel 12 contracts and tests. Do not silently plan against Laravel 11 documentation. |

## Confirmed Critical findings

1. **C-01 — ScreenTinker plaintext password propagation.** A canonical human password is recoverable in-process and transmitted downstream.
2. **C-02 — Workgroup-manager global credential takeover.** Scoped Workgroup authority can reset a same-group Super Admin's global credential.
3. **C-03 — Admin credential reset without target/delegation ceiling.** An Admin can reset an equal/stronger account without recent authentication, forced activation, or server policy.

## Confirmed High findings

1. **H-01 Identity reconciliation is not automatic:** zero exact links; name/email/fuzzy matching is prohibited.
2. **H-02 Two human guards/password stores:** session and authorization semantics are split.
3. **H-03 Bid human-password bridge:** human secret traverses another application.
4. **H-04 Forgeable or missing human Actor:** multiple operational write paths accept caller identity or none.
5. **H-05 Offline ownership gap:** queued work has no actor affinity or controlled cross-account state.
6. **H-06 Session lifecycle gap:** no account status, absolute ceiling, authoritative registry, or immediate security-version invalidation.
7. **H-07 Role delegation gap:** privilege deltas lack one formal actor/target/ceiling policy.
8. **H-08 Recovery gap:** no approved recovery proofing/channel/after-hours ownership.
9. **H-09 Same-origin API gap:** no stateful Sanctum middleware or canonical `/api/me/context`.
10. **H-10 Session confidentiality/scope:** production Redis payloads are unencrypted and the cookie spans `.mbfdhub.com`.
11. **H-11 Snipe identity gap:** email-only reconciliation can duplicate or detach numeric history.
12. **H-12 Release-test gap:** P0 login, actor, account-switch, service-worker, and realistic migration behavior lack required end-to-end gates; A06 itself is uncommitted.

## Functional and UX findings that must not be hidden by Unified Login

| Classification | Findings |
|---|---|
| PRE-EXISTING FUNCTIONAL DEFECT | `/health` 404; six failed jobs; Station Inspection failure notes may be dropped; legacy inventory lacks idempotency; Google clear-then-write partial-output risk; Snipe timeout/retry/mapping gaps |
| UNIFIED LOGIN REQUIRED CHANGE | User↔Employee link; canonical guard; panel convergence; `/api/me/context`; Employee credential retirement; intended redirect and common logout |
| SECURITY REMEDIATION | ScreenTinker/Bid password removal; reset escalation; formal delegation; status/revocation; actor derivation; cookie/session hardening |
| UX MODERNIZATION | canonical login brand, mobile shell, table strategies, accessible fields/signatures, 44 px targets, 4K data density, honest offline state |
| PERFORMANCE/INFRASTRUCTURE | production HTTP process, worker isolation, query/cache telemetry, resource limits, OPcache policy, proven redundant indexes |

## Infrastructure disposition

| Item | Disposition | Reason |
|---|---|---|
| ScreenTinker password path | MUST FIX BEFORE ANY CANONICAL CREDENTIAL APPLY | Canonicalizing while mirroring expands compromise impact. |
| Privileged reset paths | MUST FIX BEFORE ANY CANONICAL CREDENTIAL APPLY | Existing privilege escalation can capture the new master credential. |
| Bid password verifier | MUST FIX BEFORE CANONICAL LOGIN PILOT | Pilot credentials must not traverse Bid. |
| Identity/Snipe preview | MUST COMPLETE BEFORE SCHEMA APPLY | Exact mappings and numeric IDs/history must be proven first. |
| `/health` 404 | MUST FIX BEFORE IMMUTABLE PILOT | Liveness `/up` does not prove dependencies required by the pilot. |
| Six failed jobs | MUST CLASSIFY BEFORE PILOT; remediate/replay/discard only with approved runbook | PDF failures can obscure migration and notification behavior. No production replay is authorized here. |
| `artisan serve` | MUST FIX BEFORE FINAL RELEASE; SHOULD be in the pilot candidate | Production capacity/shutdown contract is inadequate. Do not couple it to early preview tooling. |
| Snipe numeric identity | MUST FIX BEFORE FINAL RELEASE and before any Snipe provisioning/SSO cutover | History/ID preservation is a hard program invariant. |
| Redis/session registry/encryption | MUST FIX BEFORE CANONICAL LOGIN PILOT | Required for revocation, expiry, and session confidentiality. |
| Reverb 101/private-channel auth | MUST FIX BEFORE FINAL RELEASE | Current process existence is not client acceptance. |
| Scheduler evidence/alerts | MUST FIX BEFORE FINAL RELEASE | Identity/status and cleanup jobs require observable execution. |
| Container limits / OPcache / redundant indexes / cache attribution | SHOULD FIX, not all on login critical path | Measure and schedule; avoid widening the stop-ship repair. |
| Cloudflare control-plane export | READ-ONLY EVIDENCE TICKET BEFORE FINAL RISK GATE | Current edge behavior is partial; no change is justified without fresh evidence. |

## Frozen architecture summary

- **User/Employee:** `User` is the only human security principal. `Employee` remains the operational personnel record. Final link is `users.employee_profile_id` unique FK to `employees.id`; existing Employee-domain foreign keys remain untouched.
- **Canonical login:** `GET/POST /login`, Employee ID + User password, one `web` session, generic failure, safe relative intended target, rate limits, restricted must-change state, and approved recovery only.
- **Session:** server-assigned context class; explicit idle/absolute ceilings; per-session registry and revocation; no 400-day framework remember token; 5-minute sensitive-security reauth.
- **Identity context:** immutable request-scoped `AuthenticatedMemberContext`; `/api/me/context` is a minimal same-origin no-store DTO and never an authorization oracle.
- **Actor:** server-derived User and Employee IDs. Subject/beneficiary/reviewer/assignee/context stay separately typed and authorized.
- **Workgroups:** preserve active membership and selected-context scoping; Workgroup authority never mutates global credentials or global roles.
- **Station/device:** human identity answers who; station context answers where; device principal answers what. PIN can remain a station capability during transition but never identifies a human.
- **Daily/offline:** completed queues bind to original actor affinity, stop on account mismatch/401/419, preserve work, and submit only after current server authentication and idempotency checks.
- **External credentials:** no human password leaves Hub or is mirrored. Bid uses a short-lived one-time audience-bound code/assertion. ScreenTinker uses a service principal/federation/passwordless provisioning. Snipe uses persisted numeric mapping plus SSO/service provisioning.
- **Mobile:** one cross-stack contract, 44 px targets, associated fields, intentional tables/dialogs, bounded wide layouts, and the full required viewport matrix.

Detailed contracts are in `IDENTITY_ARCHITECTURE.md`; ownership and order are in `IMPLEMENTATION_GRAPH.md` and `IMPLEMENTATION_TICKETS.md`.

## Owner-policy decisions required

1. Approve the exact User↔Employee mapping ledger for all 30 Users and classify `FROC-TEST-001`, non-human/service/test identities, historical Employee IDs, and every credential conflict.
2. Approve who may grant/remove each role and Workgroup/domain authority, including super-admin creation, last-super-admin custody, self-action, equal/stronger targets, and break-glass.
3. Approve account-status ownership and reactivation process. Technical states are `pending_activation`, `active`, and `disabled`; termination remains an Employee/HR fact, not invented by authentication code.
4. Approve recovery proofing, recovery contact, after-hours staffing, notification, retention, and break-glass custody. No operator-selected permanent password is allowed.
5. Prove whether managed City workstations and enrolled phones provide trustworthy posture. Unproven devices use the conservative shared/unmanaged profile.
6. Classify which operational approvals need the 15-minute recent-auth window; security administration always uses 5 minutes.
7. Decide public-read policy for Daily/station data; human writes remain authenticated regardless.
8. Classify all 26 `unknown` apparatus with source/effective date/template/station. No implementation agent may infer the policy.
9. Approve offline evidence retention/encryption and managed-device requirements.
10. Approve the monitored dual-run/rollback observation window before Employee guard/password columns and legacy routes are removed.

## Unresolved UNKNOWNs

- A06 committed artifact/immutable SHA.
- Owner-approved identity mappings and credential-conflict choices.
- Current Snipe numeric user records, duplicates, history, and SSO readiness.
- ScreenTinker downstream password storage/history and supported federation/provisioning contract.
- Bid support for code exchange/federation and whether historical logs contain credentials.
- Recovery contacts/process and device-posture signal.
- Current Cloudflare Access policy export, tunnel ingress, WAF/rate limits, WebSocket policy, and canonical-host coverage.
- Current backup age and a successful production-shaped restore rehearsal.
- Failed-job root causes and authorized disposition.
- Reverb 101/private-channel, provider integrations, authenticated latency, physical phone/tablet/4K, screen-reader, and firefighter acceptance.

## Gate conclusion

The next safe work is the three-lane Wave B in `IMPLEMENTATION_GRAPH.md`: remove credential propagation/reset escalation, build a no-write identity reconciliation preview, and establish the isolated mobile/responsive acceptance foundation. No schema apply, canonical login rollout, production mutation, Media Control change, or main merge is authorized by this report.
