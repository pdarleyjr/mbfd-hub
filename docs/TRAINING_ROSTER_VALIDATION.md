# Training roster validation receipt

Candidate branch: `codex/training-roster`, based on Hub `e5210909da407aa9514d285e8a0486ebcb5a4046`.
Companion Bid bridge: `codex/training-roster-bridge`, based on `37af43bdd5c97a021a996723cec90781ca28d672`.
Validation date: 2026-10-15. This receipt is updated as checks finish; pending checks are not passes.

## Current public-entry and submission scope

The user confirmed the public hostname `rosters.mbfdhub.com`. Its homepage lists real reusable courses and open sessions, refreshes automatically, and permits anonymous start/reuse of today's roster under a course-row lock. Anonymous sessions/grants have no invented staff creator. Submitted, closed, certified and archived attendance is not publicly readable or selectable.

Submit roster is a separate approval operation: freshly entered existing Hub email/employee ID and password, active canonical account, completed password setup, current admin entitlement and direct `training.rosters.submit`; active super_admin retains full access. Existing audited admin capability controls manage the explicit permission. Validation is stateless and does not log the approver into the kiosk. `submitted` preserves approver/time and an immutable version; it does not assert OIC certification. Archived versions retain submission approval. Original attendance time and current handwritten signature collection time (`signature_signed_at`) remain distinct after correction.

Lost approval responses retain a request UUID, reviewed revision and minimal course/date/count summary for recovery with fresh credentials; browser storage contains neither credentials nor handwriting nor attendee lists. Completed attendance stays private during recovery.

Focused authorization validation on October 15 passed **16 tests / 86 assertions** using PHP 8.5.4: fresh canonical ID/email credentials, no session or User mutation, direct admin/submit grant separation and revocation, inherited-role denial, active super_admin, inactive/departed/mismatched identity and forced-password-change denial, generic errors, existing limiter policy, and actual audited Employees & Access checkbox grant/revoke. Evidence: `runtime/training-submission-authorization-tests-final.log` in the task directory. Pint passed the six support/UI/migration/test files. This result covers authorization support only; it does not qualify the integrated public selector, final submission lifecycle, updated exports or new browser flows.

## Earlier local evidence — before public-entry scope expansion

The following historical checks remain evidence for their recorded source and scope. They are not current-candidate passes for the later public selector, credential approval or signature-timestamp changes.

- Root TypeScript: `node node_modules/typescript/bin/tsc --noEmit`, exit 0.
- CI configuration and changed-PHP helper regressions: `node --test tests/Node/ci-configuration.test.mjs tests/Node/changed-php-files.test.mjs`, 24 passed, exit 0.
- Composer security audit after narrowly updating `symfony/http-foundation` to 7.4.21 and `symfony/yaml` to 8.1.9: exit 0, no advisories or abandoned packages. Other lock additions are PhpSpreadsheet 5.10 and its four dependencies.
- PHP syntax checks on newly implemented domain, administration, kiosk and export files passed. Full formatting/static analysis are tracked separately.
- Independent source review corrected launch reconnect handling, separate rate-limit buckets, trusted-proxy host ordering, and lifecycle-independent kiosk revocation.
- Original one-page Letter PDF was rendered and visually inspected before export design. Generated export fidelity is a separate pending check.
- Clean frontend dependency installation completed in the task-local C: validation mirror. Full Vite production build passed. The interrupted D: installation is not used as build evidence.
- The earlier browser contract run passed all 15 scenarios across desktop, tablet and iPhone WebKit (2.3 minutes, exit 0). Tablet handwriting uses actual Chromium touch events. Desktop/tablet/phone roster and signature screenshots were visually inspected. Evidence: `kiosk-browser-final-local.log` and the task-local browser output directory. These scenarios predate the public selector and fresh-credential submission flow.
- Disposable PostgreSQL 16.15 migration and concurrency gate passed (one test, ten assertions). Synthetic records were reconciled and the test-owned container removed. Evidence: `training-postgres-migrate.log` and `training-postgres-concurrency.log`.
- Export regression suite passed all 14 tests and 577 assertions (176 MB peak), covering count boundaries, preserved artifacts, signature integrity and extreme-length rows. Final PDF renders were visually inspected with complete OIC/footer sections and no observed clipping. XLSX visual inspection is tracked separately.
- Targeted assignment-provider suite passed nine tests and 16 assertions, including unknown Bid employment status with active canonical Hub identity and explicit inactive/departed exclusions. Evidence: `training-provider-eligibility.log`.
- Existing build-advisory classifier regression: 12 tests passed after restoring the unchanged approved package manifest and invoking Playwright directly from the workflow.

## Hosted initial-candidate evidence

Hub PR 424 initial candidate `e8a9e6ad421af7449ce999e4c33d52004547cdc4`, CI run `38766294348`: PHP/root frontend quality, production asset build, generated assets, workflow lint, configuration, filesystem security and canonical AI gates passed. PHP 8.4 and 8.5 each reported 2,162 passing tests, 11 failing tests and one skip. Failures exposed two host-only cookie assertions, one invalid canonical-identity fixture, and eight PDF heading assertions. Those findings required corrections and reruns; this initial run was not release-qualified. Static analysis identified missing Eloquent type information, and the manifest hash guard correctly rejected an unnecessary npm script change. Neither failure is treated as an Actions billing outage.

Bid PR 270 candidate `bb0a30ae0ff426e6b51e7522c6d4274a85eb6bc3`: CI `38767586656` and CodeQL `38767586045` passed, including full typecheck, unit/integration, Workers runtime and Playwright E2E. Read-only production D1 migration guard passed all 72 existing migrations; this bridge adds none.

Live read-only bridge validation found assigned personnel with Bid employment status `unknown`. The follow-up in Bid PR 271 preserves that status and allows training availability only through the Hub's mandatory active canonical identity gate. It does not modify Bid employment or assignment data. Hub candidate `54248221071971a582ced55b46f0939e50b921cc` reduced PHPStan findings to one redundant nullsafe access, corrected in the next candidate; security/configuration/generated-asset gates passed.

## Expanded candidate local evidence

- Integrated HTTP/domain/administration checks passed **43 tests / 390 assertions**, including public catalog/start/open, anonymous creator, completed-record privacy, revision/idempotency and approval lifecycle. An additional non-JSON credential-flash privacy regression passed **one test / 13 assertions**. Logs: `public-roster-backend-tests.log`, `public-roster-privacy-test.log`.
- Authorization checks passed **16 tests / 86 assertions**, as detailed above.
- Browser contracts passed **45 scenarios** across desktop, tablet touch and iPhone WebKit, including discovery/refresh, signatures, fresh-credential approval, loss/reload recovery, closed-roster receipts and approval/poll races. After the final actionable-error and location-label change, all **12 focused catalog/ambiguity scenarios** passed again. TypeScript and Vite production compilation passed. Logs: `public-roster-browser-final.log`, `public-roster-browser-catalog-final.log`, `public-roster-vite-catalog-final.log`.
- Updated export tests passed **18 tests / 1,948 assertions**. PDF and actual LibreOffice-rendered XLSX pages were inspected for normal, overflow, maximum metadata and long-name cases, including submitted/archived approval and current-signature timestamps. Receipt: `export-submitted-validation-receipt.json`.
- PostgreSQL migration and locking checks passed **two tests / 29 assertions**, including two concurrent public starts blocked on the course-row lock and then reusing exactly one session. Test-owned database/container cleanup completed. Log: `public-roster-postgres-concurrency.log`.
- Full application PHPStan completed with no errors; scoped Pint and `git diff --check` passed. Log: `public-roster-phpstan.log`.
- Full combined npm build in the local validation mirror was not established: its separate policy-library build lacked `pdfjs-dist/cmaps`. The successful Vite build is not a substitute for the full hosted production build.

Required hosted gates on the exact revised candidate, full regression/PHP compatibility checks, immutable image qualification, fresh production restore rehearsal and live activation/verification remain pending. Earlier candidate CI and image results do not qualify this revised source.

## Custom classifications requested during release preparation

The user requested the real first course **Primary Screener**, classified as **STC**, and an option for authorized staff to add classifications. Existing course/session JSON fields now supply reusable suggestions; no registry table or separate management page is added. Public cards display and search labels. New input is whitespace-normalized and canonicalized against existing spelling, with 1–16 distinct labels of at most 80 characters. Existing authorization, audited updates and historical snapshots remain unchanged.

The classification/domain/real-form/filter checks passed **13 tests / 100 assertions**. Two additional mixed-invalid-input datasets passed **two tests / 16 assertions**. The revised public catalog passed all **11 public-roster HTTP tests** in an earlier combined run whose only failure was a malformed-classification validation error subsequently corrected and covered by these focused reruns. Browser class-card/category search checks passed **12 scenarios** across desktop/tablet/iPhone; TypeScript and Vite passed. Custom-label export checks passed **two tests / 85 assertions**. Final native PDF and actual LibreOffice XLSX print previews were inspected: STC with 16 attendees fits one page; maximum-length labels remain complete on both pages with signatures, OIC details and footers. Receipt: `export-classification-validation-receipt.json`. Scoped Pint passed all ten changed PHP/Blade files and `git diff --check` passed. Full PHPStan passed with one worker after the initial parallel run exhausted the Windows paging file. Final hosted checks remain pending.

Candidate `04efe11c7ff611e4d378ed7c3f0a223cabdc59e1` completed twelve hosted CI jobs successfully, including both full PHP lanes and PostgreSQL concurrency, before CI `38787995510` was deliberately cancelled for the newly requested classification scope. Its complete production Docker build and pinned SBOM/HIGH-CRITICAL vulnerability plus secret scan passed locally. Those are historical source/image results; they do not qualify the later classification revision or a merged release image.

## Published-bid clarification and earlier export checks

The user's shift reminder clarified that sign-in must use the recent 2026–2027 bid selection. Live read-only comparison found 226 validated published Hub assignments (218 awards and eight retained assignments); 54 differed from older operational shifts and 21 had no comparable operational shift. The provider now uses the existing published Hub result for term/shift/provenance, with the authenticated bridge supplying person names/rank/employment only. No personnel or bid assignment records were modified.

The rewritten provider passed 15 focused tests and 35 assertions, including publication/supersession, New York day boundaries, term advancement, selected versus older operational shift, retained assignments and fail-closed provenance. The screen-only reminder uses the published term dynamically and is absent from roster/export templates.

Before the public-submission expansion, PDF and actual LibreOffice-rendered XLSX pages passed visual inspection for normal, overflow, long-name and maximum metadata cases. The post-page-break targeted regression passed one test/24 assertions; the maximum metadata regression passed one test/seven assertions. The full export suite had previously passed 14 tests/577 assertions before those two narrow fixes. Those observations do not establish acceptance of the later submission-status and signature-timestamp rendering changes; updated validation is pending above.

Local Windows dependency/class loading has shown substantial filesystem latency. Unfinished processes and partial logs are not accepted as successful checks. Task-local PHP configuration enables required extensions without altering global configuration. All test data is synthetic and confined to local disposable storage/databases; the supplied credential file was not loaded into tests.

## Evidence boundaries and release status

The browser contract harness serves the real built kiosk with test-owned API responses. It proves browser behavior, not a production persistence workflow. PHPUnit exercises real application HTTP/Livewire/database paths. PostgreSQL race tests separately exercise locking. These layers do not substitute for each other.

Hosted Actions was observed executing current repository jobs. Use the attached-instruction fallback only when the exact candidate has an evidenced provider/billing block; never waive executed test/security failures or fabricate GitHub statuses. The existing workflows remain authoritative and now include kiosk browser checks.

The user explicitly authorized live activation and creation of `rosters.mbfdhub.com`. The Hub roster feature remains unactivated while the expanded candidate's gates run. Production preflight verified the GMKtec immutable baseline and existing Cloudflare tunnel; proposed DNS, ingress and cache changes preserve existing routes. The companion Bid bridge was deployed separately; that deployment does not qualify this new Hub flow. Hub activation requires exact-candidate gates, immutable image qualification, isolated backup/restore/migration rehearsal and actual public-entry plus fresh-authorized-submission browser acceptance. Physical touchscreen/stylus and printer acceptance remains an operator check after delivery. See `TRAINING_ROSTER_DESIGN.md` for the full design and rollback boundaries.
