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
- Clean frontend dependency installation completed in the task-local C: validation mirror. Full Vite production build passed. The interrupted D: installation is not used as build evidence.
- Final browser contract run passed all 15 scenarios across desktop, tablet and iPhone WebKit (2.3 minutes, exit 0). Tablet handwriting uses actual Chromium touch events. Desktop/tablet/phone roster and signature screenshots were visually inspected. Evidence: `kiosk-browser-final-local.log` and the task-local browser output directory.
- Disposable PostgreSQL 16.15 migration and concurrency gate passed (one test, ten assertions). Synthetic records were reconciled and the test-owned container removed. Evidence: `training-postgres-migrate.log` and `training-postgres-concurrency.log`.
- Export regression suite passed all 14 tests and 577 assertions (176 MB peak), covering count boundaries, preserved artifacts, signature integrity and extreme-length rows. Final PDF renders were visually inspected with complete OIC/footer sections and no observed clipping. XLSX visual inspection is tracked separately.
- Targeted assignment-provider suite passed nine tests and 16 assertions, including unknown Bid employment status with active canonical Hub identity and explicit inactive/departed exclusions. Evidence: `training-provider-eligibility.log`.
- Existing build-advisory classifier regression: 12 tests passed after restoring the unchanged approved package manifest and invoking Playwright directly from the workflow.

## Hosted initial-candidate evidence

Hub PR 424 initial candidate `e8a9e6ad421af7449ce999e4c33d52004547cdc4`, CI run `38766294348`: PHP/root frontend quality, production asset build, generated assets, workflow lint, configuration, filesystem security and canonical AI gates passed. PHP 8.4 and 8.5 each reported 2,162 passing tests, 11 failing tests and one skip. Failures exposed two host-only cookie assertions, one invalid canonical-identity fixture, and eight PDF heading assertions. These are being corrected and rerun; this run is not release-qualified. Static analysis identified missing Eloquent type information, and the manifest hash guard correctly rejected an unnecessary npm script change. Neither failure is treated as an Actions billing outage.

Bid PR 270 candidate `bb0a30ae0ff426e6b51e7522c6d4274a85eb6bc3`: CI `38767586656` and CodeQL `38767586045` passed, including full typecheck, unit/integration, Workers runtime and Playwright E2E. Read-only production D1 migration guard passed all 72 existing migrations; this bridge adds none.

Live read-only bridge validation found assigned personnel with Bid employment status `unknown`. The follow-up in Bid PR 271 preserves that status and allows training availability only through the Hub's mandatory active canonical identity gate. It does not modify Bid employment or assignment data. Hub candidate `54248221071971a582ced55b46f0939e50b921cc` reduced PHPStan findings to one redundant nullsafe access, corrected in the next candidate; security/configuration/generated-asset gates passed.

## Running or pending

- Final candidate database-backed HTTP/domain/Livewire tests, historical assignment provider tests and existing personnel-signature regression.
- Required hosted release gates on the exact candidate, including PHPStan, security, existing regressions, PHP compatibility, generated assets and image qualification.

## Published-bid clarification and final local checks

The user's shift reminder clarified that sign-in must use the recent 2026–2027 bid selection. Live read-only comparison found 226 validated published Hub assignments (218 awards and eight retained assignments); 54 differed from older operational shifts and 21 had no comparable operational shift. The provider now uses the existing published Hub result for term/shift/provenance, with the authenticated bridge supplying person names/rank/employment only. No personnel or bid assignment records were modified.

The rewritten provider passed 15 focused tests and 35 assertions, including publication/supersession, New York day boundaries, term advancement, selected versus older operational shift, retained assignments and fail-closed provenance. The screen-only reminder uses the published term dynamically and is absent from roster/export templates.

Final PDF and actual LibreOffice-rendered XLSX pages passed visual inspection for normal, overflow, long-name and maximum metadata cases. The post-page-break targeted regression passed one test/24 assertions; the maximum metadata regression passed one test/seven assertions. The full export suite had previously passed 14 tests/577 assertions before those two narrow fixes. Final hosted regression remains the release gate.

Local Windows dependency/class loading has shown substantial filesystem latency. Unfinished processes and partial logs are not accepted as successful checks. Task-local PHP configuration enables required extensions without altering global configuration. All test data is synthetic and confined to local disposable storage/databases; the supplied credential file was not loaded into tests.

## Evidence boundaries and release status

The browser contract harness serves the real built kiosk with test-owned API responses. It proves browser behavior, not a production persistence workflow. PHPUnit exercises real application HTTP/Livewire/database paths. PostgreSQL race tests separately exercise locking. These layers do not substitute for each other.

Hosted Actions was observed executing current repository jobs. Use the attached-instruction fallback only when the exact candidate has an evidenced provider/billing block; never waive executed test/security failures or fabricate GitHub statuses. The existing workflows remain authoritative and now include kiosk browser checks.

The user explicitly authorized live activation and creation of `roster.mbfdhub.com`. The Hub remains unactivated while final gates run. Production preflight verified the current GMKtec immutable baseline and existing Cloudflare tunnel; the proposed DNS record, ingress addition and cache bypass preserve existing routes. Activation requires companion Bid bridge deployment, exact-candidate gates, an isolated backup/restore/migration rehearsal and authenticated browser acceptance. Physical touchscreen/stylus and printer acceptance remains an operator check after delivery. See `TRAINING_ROSTER_DESIGN.md` for the full design and rollback boundaries.
