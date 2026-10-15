# Training roster validation receipt

Candidate branch: `codex/training-roster`, based on Hub `e5210909da407aa9514d285e8a0486ebcb5a4046`.
Companion Bid bridge: `codex/training-roster-bridge`, based on `37af43bdd5c97a021a996723cec90781ca28d672`.
Validation date: 2026-10-15. This receipt is updated as checks finish; pending checks are not passes.

## Completed local checks

- Root TypeScript: `node node_modules/typescript/bin/tsc --noEmit`, exit 0.
- CI configuration and changed-PHP helper regressions: `node --test tests/Node/ci-configuration.test.mjs tests/Node/changed-php-files.test.mjs`, 24 passed, exit 0.
- Composer security audit after narrowly updating `symfony/http-foundation` to 7.4.21 and `symfony/yaml` to 8.1.9: exit 0, no advisories or abandoned packages. Other lock additions are PhpSpreadsheet 5.10 and its four dependencies.
- PHP syntax checks on newly implemented domain, administration, kiosk and export files passed. Full formatting/static analysis are tracked separately.
- Independent source review corrected launch reconnect handling, separate rate-limit buckets, trusted-proxy host ordering, and lifecycle-independent kiosk revocation.
- Original one-page Letter PDF was rendered and visually inspected before export design. Generated export fidelity is a separate pending check.

## Running or pending

- Real database-backed HTTP/domain/Livewire tests, historical assignment provider tests, existing personnel-signature regression, and disposable PostgreSQL concurrency.
- Export count matrix, private file/hash verification, immutable versions, rendered PDF and spreadsheet visual inspection.
- Actual kiosk bundle browser contract checks in Chromium desktop/tablet and iPhone WebKit, including accessibility, sticky action, handwriting, duplicate-response recovery and reset.
- Completed clean dependency installation and full production build. An early Vite attempt while npm was still extracting packages failed to resolve `@fluentui/react-icons`; this is not a passed build.
- Required hosted release gates on the exact candidate, including PHPStan, security, existing regressions, PHP compatibility, generated assets and image qualification.

Local Windows dependency/class loading has shown substantial filesystem latency. Unfinished processes and partial logs are not accepted as successful checks. Task-local PHP configuration enables required extensions without altering global configuration. All test data is synthetic and confined to local disposable storage/databases; the supplied credential file was not loaded into tests.

## Evidence boundaries and release status

The browser contract harness serves the real built kiosk with test-owned API responses. It proves browser behavior, not a production persistence workflow. PHPUnit exercises real application HTTP/Livewire/database paths. PostgreSQL race tests separately exercise locking. These layers do not substitute for each other.

Hosted Actions was observed executing current repository jobs. Use the attached-instruction fallback only when the exact candidate has an evidenced provider/billing block; never waive executed test/security failures or fabricate GitHub statuses. The existing workflows remain authoritative and now include kiosk browser checks.

This is a draft implementation, not an activated service. No production database migration, deployment, DNS/Tunnel/Access/cache rule change, staff permission assignment or live attendance write has been performed. Activation requires companion Bid bridge deployment, current infrastructure readback, exact-candidate gates, an isolated backup/restore/migration rehearsal, explicit deployment scope, and authenticated operator plus physical touchscreen/print acceptance. See `TRAINING_ROSTER_DESIGN.md` for the full design and rollback boundaries.
