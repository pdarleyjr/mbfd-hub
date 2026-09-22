# Daily Checkout Astra Candidate, 2026-09-22

## Decision and Provenance

NOT GO-LIVE READY: the full PHP suite exhausted its configured 256 MiB limit
in `FinfoMimeTypeDetector` (exit 255, empty JUnit output). PostgreSQL, PHP 8.4,
Trivy, exact-source image acceptance, and production/hardware acceptance remain
unverified. The candidate is not authorized for activation.

- Repository: `pdarleyjr/mbfd-hub`.
- Branch: `copilot/daily-checkout-final-ui-20260922`.
- ASTRA_START_SHA: `5c41fb5aab910041eb0a757b3e34ac3dc73be364`.
- Worktree: `D:/GitHub_Repos/_astra-worktrees/daily-checkout-final-ui-20260922`.
- SOL_FINAL_MAIN_SHA: not checked; local release acceptance has not been reached.
- FINAL_SHA / deployed image: none; no integration or production activation.
- Tools: Node 22.22.3, PHP 8.5.4, explicitly invoked Python 3.13.1.
- GitHub Actions: NOT USED. No workflow dispatch, rerun, polling, or edits.
- Sol retains main integration and production authority. No production data,
  services, storage, migrations, authentication, Cloudflare, or other repositories
  were changed. No blueprint geometry, paper checklist, or dependency changes.

## Product Changes

- Verified blueprints remain primary, with view-local touch controls and compact
  Other areas instead of duplicate dropdown/full mapped-area navigation.
- Rescue uses authoritative named areas without invented geometry.
- Existing findings and newly reported issues have separate markers/counts.
  Quick Checklist separates remaining observations from attention items.
- Canonical readiness determines the next required action. Review preserves
  paper write-ins, scheduled duties, physical identity, and signature evidence.
- Owner/version-bound IndexedDB drafts retain large photos and queued work.
  Legacy keys are removed only after durable migration; failed reads block entry.
  Accepted queues clear only matching draft answers in the same transaction.
- Automatic processing displays Accepted or Follow-up needed; genuine legacy
  approval behavior and immutable review history remain unchanged.
- Responsive tab fit, narrow-zone targets, offline banner flow, review/signature
  feedback, and Fire Boat terminology are corrected.

## Local Validation

Browser tests used loopback builds, synthetic identities, and mocked APIs only.
No real operational inspection was submitted for acceptance.

| Gate | Result |
| --- | --- |
| Root/Daily types and builds | PASS, including final persistence fix |
| Filament assets and generated-asset guard | PASS |
| CI configuration / changed-PHP helper tests | PASS: 19 / 5 tests |
| Changed PHP Pint / full PHPStan | PASS: 4 files / no errors |
| Composer validate/audit; root/Daily npm audit | PASS, no reported vulnerabilities |
| Email worker npm audit | High/critical threshold passed; 2 existing moderate advisories |
| Filament status and approval | PASS: 8 tests, 84 assertions |
| Checkout/backend contracts | 245/246 initially passed; missing built Daily page caused one failure; that test passed after building (19 assertions) |
| Full PHP non-PostgreSQL suite | FAIL: exit 255, 256 MiB exhaustion; earlier test errors were not summarized before abort; no final count claimed |
| Python restricted identity bridge | PASS: 6 tests, captured exit 0; interpreter proved with PYTHON_CAPTURE_OK |
| Full browser matrix | 340 passed, 7 intentional skips, 4 failures; three obsolete toast assertions corrected; all four focused reruns passed |
| E1/E2/E3/E4/L1/L3/FB6 profile matrix | PASS: 77 cases across all 11 configured viewports, 320 through 3840 px |
| Rescue, typed paper fields, owner isolation | Passed responsive and WebKit matrix cases |
| Real-worker large-photo offline lifecycle | Final normal-settings repeats: 3/3 passed; original startup stall unresolved; diagnostic tracing produced two context-teardown timeouts after successful submission |
| Legacy migration / failed-read protection / restore | PASS: 3 focused browser cases |
| Changed-source secret scan and diff check | PASS |

Coverage percentage was not collected. No physical CF-20, phone, or tablet
acceptance is claimed. Screenshots were personally inspected for Engine,
Ladder, Fire Boat, Rescue, review, phone, CF-20-sized and desktop layouts.

## Evidence and Remaining Gates

Local `astra-*.log` files and `test-results/astra-*` retain results/screenshots.
Key sets: `astra-final`, `astra-tabs-final`, `astra-touch-review`,
`astra-pwa-repeat`, `astra-pwa-final-repeat`, and `astra-full-php-result.json`.
Generated evidence and deployment assets are not committed.

PostgreSQL 16.13 was attempted only in a uniquely named local WSL container,
`astra-daily-test-pg-20260922-55497`, bound to 127.0.0.1:55497 with tmpfs data.
It reached ready, then received an unexplained shutdown request before Windows
PHP connected. No application migration completed. Do not infer a source defect
from this infrastructure failure or weaken the loopback database guard.

Before integration: classify/reproduce full-suite errors under the required
toolchains, complete guarded PostgreSQL and Trivy checks, establish bounded
offline startup stability, and obtain exact-source image evidence. Only then
reconcile Sol/main/runtime once and obtain explicit release authority. Deployment
still requires backups, rollback, safe storage, validated migrations, authenticated
browser acceptance, and separate hardware acceptance where applicable.