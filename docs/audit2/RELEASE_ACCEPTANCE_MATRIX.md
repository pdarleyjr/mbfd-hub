# Release Acceptance Matrix

This matrix defines required evidence for the final integrated candidate. It does not mark unchanged, unrun, or hosted-only gates as passed. Every result is tied to candidate commit SHA, tree SHA, image digest, environment, command, exit code, and retained artifact.

## Gate states

- **PASS:** exact-candidate evidence observed and independently reviewable.
- **FAIL:** executed and acceptance not met.
- **BLOCKED:** prerequisite, authority, environment, or evidence unavailable.
- **NOT LOCALLY VERIFIED:** applicable hosted/external/physical gate not safely reproducible locally.

Any P0 or P1 FAIL/BLOCKED/unknown at release time blocks production. A06 must first commit its audit artifact; its current narrative is REPORTED.

## Required matrix

| ID | Class | Area | Acceptance | Primary evidence | Local equivalent | Hosted/final evidence |
|---|---|---|---|---|---|---|
| RA-001 | P0 | Candidate identity | frozen source SHA/tree and immutable image OCI revision/digest agree | Git/OCI inspection | `git rev-parse`; isolated image inspect | prepare workflow artifacts and digest |
| RA-002 | P0 | Login root | `/` and every protected deep link resolve to one `/login` without loop | feature + browser | isolated Laravel/Playwright | required hosted browser job |
| RA-003 | P0 | Login failures | valid Employee ID works; wrong/nonexistent/unlinked/disabled are generic and bounded-equivalent | data-driven feature/browser | SQLite plus PostgreSQL fixture | hosted PHP/browser |
| RA-004 | P0 | Rate limiting | account+IP and IP limits enforced without cross-account shared-IP denial | feature clock/rate tests | isolated app cache | hosted PHP |
| RA-005 | P0 | Session fixation | session ID and CSRF rotate on login; attacker-provided session not retained | feature assertion | array/Redis lanes | hosted PHP |
| RA-006 | P0 | Intended URL | only signed/session relative allowlisted paths restore; external/encoded bypass rejected | feature/browser | loopback browser | hosted browser |
| RA-007 | P0 | Restricted activation | must-change session reaches only change/logout/assets; completion revokes others | feature/browser | isolated roles | hosted PHP/browser |
| RA-008 | P1 | Persistence/expiry | each context enforces idle/absolute/persistent and privileged override boundaries | time-travel integration | disposable Redis/session registry | hosted service job |
| RA-009 | P1 | Revocation | disable, password/recovery change, role loss, individual/all-device sign-out deny next request and realtime/API | Redis/Reverb integration | disposable Redis + app workers | hosted services |
| RA-010 | P1 | Recovery | approved single-use expiring generic flow; replay/race/contact failure/break-glass tested | feature/browser + owner SOP | fake notification provider | hosted PHP; human SOP exercise |
| RA-011 | P0 | Authorization | persona x navigation x direct URL/API/Livewire table denies unauthorized state changes | data-driven feature/browser | seeded roles/workgroups | hosted PHP/browser |
| RA-012 | P0 | Privilege escalation | Workgroup cannot mutate global security; actors cannot target self/equal/stronger or grant beyond delegation; last Super Admin protected | policy/Livewire negative tests | SQLite/PostgreSQL | hosted PHP |
| RA-013 | P0 | Workgroup isolation | A/B context, forged IDs/state, removal mid-session, downloads/AI/reports remain isolated | existing plus browser matrix | isolated fixtures | hosted PHP/browser |
| RA-014 | P0 | Actor forgery | payload name/User/Employee ID cannot override Daily/station/inventory/forms Actor | endpoint mutation tests | SQLite/PostgreSQL | hosted PHP |
| RA-015 | P0 | Actor vs subject | requester/beneficiary/reviewer/assignee/preparer persist independently and correctly | domain feature/browser | seeded personnel fixtures | hosted PHP/browser |
| RA-016 | P0 | Identity preview | deterministic no-secret report covers every User/Employee; collisions block; names never auto-link | golden fixtures + production-shaped copy | command dry-run | hosted PHP artifact |
| RA-017 | P0 | Identity apply | preserves User IDs, Employee FKs, roles, permissions, Workgroups/history; idempotent and drift-blocking | PostgreSQL migration fixture | disposable PostgreSQL 16.13 | hosted PostgreSQL job |
| RA-018 | P0 | Credential migration | bcrypt copied without plaintext/double-hash; conflicts block; old sessions rotate | unit/integration | production-shaped sanitized fixture | hosted PHP/PostgreSQL |
| RA-019 | P0 | Daily online | authenticated checkout persists server Actor, context, UUID/hash receipt and pending review | feature/browser | Daily mock + Laravel | hosted Daily/browser |
| RA-020 | P0 | Daily offline ownership | Jane queue/logout/John login cannot post or disclose; original reauth resumes without data loss | service-worker/auth E2E | Chromium with SW enabled | hosted browser artifact |
| RA-021 | P0 | Daily restart/idempotency | offline completion survives app/browser restart; response loss/retry posts once and returns receipt | SW/browser + DB | loopback HTTPS-capable browser | hosted browser/PostgreSQL |
| RA-022 | P0 | Session expiry during offline | 401/419/403/mismatch stop retry, preserve work, and require correct Actor reauth | browser state-machine tests | local SW + auth app | hosted browser |
| RA-023 | P0 | Daily policy/provenance | `unknown` remains fail-closed; owner classifications only; `d832c2fb...` and review ledger unchanged | command/DB assertions | PostgreSQL copy | hosted PostgreSQL; owner ledger review |
| RA-024 | P1 | Station/device context | precedence, source, locked/editable, cross-station room/asset denial, PIN/device separation | feature/browser | device fake + DB | hosted PHP/browser |
| RA-025 | P1 | PWA lifecycle | install, first offline route, cache version/update-ready, quota failure, standalone, foreground/background retry | browser/device | Chromium SW lane | hosted browser + physical phone/tablet |
| RA-026 | P0 | Responsive critical paths | required phone/tablet/desktop flows complete with no clipped action/overflow | Playwright matrix | all listed viewports | hosted browser |
| RA-027 | P1 | Accessibility | labels, headings, name/role/value, focus, keyboard, target size, errors, zoom, reduced motion; tool plus manual | browser + manual | axe signal and assertions | independent manual audit |
| RA-028 | P1 | 4K intent | Daily/dashboard/table use bounded useful density; no tiny centered island; physical legibility | visual + physical | 3840 emulation | physical 4K acceptance |
| RA-029 | P1 | PostgreSQL | migrations, FK/unique/checks, concurrency/idempotency, realistic upgrade and supported rollback | integration | disposable PostgreSQL 16.13 | hosted service job |
| RA-030 | P1 | Redis | encrypted session compatibility, registry, cache/session separation, TTL, restart/failover and revocation | integration | disposable Redis 7.4 | hosted service job |
| RA-031 | P1 | Queue | six failures dispositioned; PDF/summary/notification queues durable, idempotent, timed, retried/dead-lettered and restart-safe | jobs/worker integration | isolated workers/storage | hosted service job; approved production runbook |
| RA-032 | P1 | Scheduler | every required schedule runs with locks, failure alert and duration evidence | schedule integration | isolated scheduler/Redis | candidate soak evidence |
| RA-033 | P1 | Reverb | valid 101, private-channel authorization, role loss, reconnect, Redis path | websocket integration | loopback Reverb/Redis | hosted/isolated image + canary |
| RA-034 | P1 | Bid | password never enters Bid; one-time assertion success/expiry/replay/audience/issuer/signature/role-loss and old endpoint retirement | provider/consumer contract | local fake Bid | staging acceptance |
| RA-035 | P1 | ScreenTinker | no capture/outbound password; service/federation contract and rollback that cannot restore mirroring | source/egress/contract tests | HTTP fake/local downstream fake | separately authorized downstream staging |
| RA-036 | P1 | Snipe-IT | persisted numeric IDs, duplicate/collision block, no history loss, break-glass, no Hub password | reconciliation contract | recorded sanitized fixture/mock server | non-production export acceptance |
| RA-037 | P1 | Google | request contract, timeout/retry, atomic/staged update or recovery from clear/write failure | fake client | local HTTP/client fake | staging provider smoke if authorized |
| RA-038 | P1 | Webhooks/LiveKit | signature, replay, idempotency, limits, retry classification, token/reconnect | fake provider/contract | local fake + synthetic media | authorized staging/physical acceptance |
| RA-039 | P1 | Health/runtime | `/up` liveness and `/health` dependency readiness are explicit; production HTTP server has load/shutdown/health contract | image runtime | unique Compose project/no prod mounts/ports | immutable candidate proof |
| RA-040 | P1 | Container security | non-root, writable paths, no secrets baked, resource/PID limits reviewed, SBOM and High/Critical scan | image inspect/Trivy | isolated Docker if available | prepare workflow artifacts |
| RA-041 | P1 | Cloudflare | fresh read-only Access/tunnel/WAF/WebSocket evidence; origin/Laravel auth remains authoritative | control-plane export review | NOT LOCALLY VERIFIED | independent read-only gate |
| RA-042 | P1 | Backup/restore | restricted backup, integrity/list check, full restore rehearsal, migration rollback and application read proof | restore artifact | isolated restore target | release-captain supervised rehearsal |
| RA-043 | P0 | Security scans | Gitleaks, dependency audit, Trivy repo/config/image, static analysis and changed-PHP formatting pass | commands/artifacts | local tools where installed | current required workflows |
| RA-044 | P0 | Application suites | Composer validate, PHPStan, PHP 8.4/8.5, PHPUnit SQLite/PostgreSQL, Node/Python, typechecks/builds pass | exit logs | repository commands | required hosted aggregate |
| RA-045 | P1 | Browser suite promotion | Daily plus Operational Forms, Personnel, Station, Video, Workgroup, Admin/PWA suites are required, not inventory-only | Playwright artifacts | serial loopback configs | hosted required jobs |
| RA-046 | P2 | Performance | query-count/N+1, bundle budgets, stable loopback response and mobile Lighthouse within approved baselines | measurements | seeded local candidate | hosted/public post-activation Lighthouse |
| RA-047 | P1 | Rollback | source/image/config/data rollback avoids password mirroring and restores known-good service | rehearsal | isolated candidate | captain evidence |
| RA-048 | P1 | Canary/soak/human | staged canary, logs/metrics, stability soak, firefighter mobile, admin desktop, 4K, integration and physical acceptance | signed checklist | NOT LOCALLY VERIFIED | production maintenance window only |

## Current CI disposition

GitHub Actions is currently available: multiple CI, Static Analysis, Generated Assets Guard, Gitleaks, and Trivy PR runs completed successfully on 2026-08-31. This is availability evidence, not evidence for a future candidate. Every implementation branch must:

1. inspect applicable workflows;
2. run the strongest local equivalent;
3. push and obtain hosted results while available;
4. retain a local evidence package if hosted availability changes;
5. never weaken or disable a gate because it is unavailable.

## Final release sequence

Integrated candidate → exact local/hosted P0/P1 suite → independent Sol security/risk gate → immutable build/SBOM/scan → restricted backup and restore rehearsal → explicit owner authorization/maintenance window → progressive canary → soak → physical/human acceptance → closeout. Any candidate change after the risk gate creates a new candidate and invalidates approval.
