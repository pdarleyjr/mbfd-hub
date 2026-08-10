# MBFD Hub Employee, Station / Vehicle, and Admin Audit

**Audit date:** August 10, 2026

**Production baseline:** `2ee234fd2fbc31803f8fe0e1b8ec1a68c9f738dc`

**Scope:** Employee Portal, Uniform / Equipment Requests, Station and Vehicle Forms, Fire Apparatus / PM, Admin Dashboard, SnipeIT, Google Sheets, security, reliability, and frontend performance.

## Executive result

The audit found and remediated several material integrity defects: an equipment-request endpoint that could queue a permanently invalid URL while telling the operator it succeeded; SnipeIT assets that could be certified as physically inspected despite having no matching checklist item; vehicle meter readings that updated the current apparatus but disappeared from inspection history; collision-prone inspection references; and unsafe shared-password administration paths. The affected code now has explicit regression coverage.

Production data was reviewed read-only before implementation. MBFD Hub had 237 employee portal records with no blank, malformed, or duplicate employee IDs. The tracked 229-person roster was fully represented with no name or rank mismatches. Eight additional live records need an HR owner to confirm because no newer authoritative roster was available. A mass reset to `Miamibeach!` is neither necessary nor recommended.

SnipeIT integration is **partial, not fleet-wide**: 19 of 26 apparatus records have SnipeIT mappings and all 19 mapped remote assets were verified by ID and tag. Seven apparatus records are unmapped. Employee portal identities are also not synchronized to SnipeIT: the portal has 237 employees, SnipeIT has 5 users, and the existing sync command targets the separate admin `users` table.

Google Sheets apparatus synchronization is configured and healthy. API authentication and spreadsheet metadata were verified, and all 26 Hub apparatus rows matched the designated sheet with no field mismatch. The write path is queued after database commit and already uses retry/backoff behavior.

## Scorecard

| Area | Score | Evidence and remaining constraint |
|---|---:|---|
| Accessibility | 17/20 | Primary daily controls were raised to 44 px; keyboard-capable native controls remain. A physical tablet/smartboard pass is still required. |
| Performance | 18/20 | Daily initial JS fell from 620.57 kB to 341.61 kB minified. The main application's largest executable chunk fell from about 778.94 kB to 420.05 kB. |
| Responsive / touch | 17/20 | Route-level lazy loading and 44 px primary targets were verified by build and code review. Physical device and Safari acceptance remain unobserved. |
| Security / access control | 18/20 | Employee IDs validated, public identifier disclosure removed, password flows hardened, sensitive legacy reads protected, and dependency advisories cleared. MFA and employee-to-Snipe identity linkage are not present. |
| Data and integration integrity | 18/20 | Form persistence, admin rendering, Snipe mappings, sheet parity, PM fields, stock locking, and permanent/offline failure behavior have regression coverage. Seven apparatus mappings remain a data-owner decision. |

**Overall:** 88/100 after remediation. No open P0 code defect was found in the audited surfaces. The remaining items are integration ownership and physical acceptance gates, not evidence that the observed automated checks failed.

## Findings and remediation

### Authentication and employee access

- **Verified:** 237 live employee records; zero blank, malformed, or duplicate employee IDs.
- **Verified:** all 229 records in `scripts/mbfd-personnel.csv` exist in production with matching names and ranks.
- **Needs owner confirmation:** employee IDs `18156`, `20487`, `16847`, `19952`, `16584`, `16573`, `19545`, and `21989` exist only in the live database relative to the tracked roster. They may be valid newer employees and were not altered.
- **Verified:** no employees are currently flagged `must_change_password`; hashes do not form a shared-password cluster, and no configured default matched.
- **Fixed:** personnel re-import no longer overwrites existing passwords. Existing users receive profile updates only.
- **Fixed:** new-user import requires an explicit new credential-output file, generates a unique 24-character temporary password per employee, avoids console disclosure, and writes with restrictive permissions where supported.
- **Fixed:** mass shared-password reset was removed. Reset now targets one employee, requires an explicit unique temporary password of at least 15 characters, and forces a change at next login.
- **Fixed:** create/reset/change password validation now requires at least 15 characters and rejects known-compromised values.
- **Fixed:** the public employee search response no longer exposes `employee_id`, which is also the login identifier.
- **Retained:** employee login already uses generic failure messaging, session regeneration, and a five-attempt rate limiter.

**Decision:** do not reset all employees to `Miamibeach!`. A shared known temporary password would reduce assurance, create an immediate credential-stuffing target, and is not supported by evidence of a compromise.

### Uniform and employee equipment workflow

- **Fixed:** `Uniform` can now be created and updated through Filament without mass-assignment errors and validates enumerated sizes, nonnegative stock, and nonnegative cost.
- **Fixed:** assignments may link to a tracked uniform inventory row through `uniform_id`.
- **Fixed:** tracked issuance runs in a database transaction, locks the inventory row, rejects insufficient stock, creates the employee assignment, and decrements inventory atomically.
- **Fixed:** admin equipment requests now select the employee portal identity rather than the unrelated admin-user identity.
- **Fixed:** employee request submission no longer fails when one optional admin role has not been seeded. The request reaches users with any present logistics/admin role.
- **Verified:** portal submission preserves employee attribution and request text, creates `Pending` status, and creates the logistics-admin database notification.
- **Verified:** admin actions implement `Pending` → `Ordered` → `Ready for Pickup` → `Completed`, plus `Declined` and `Reopen`, with employee notifications and archive state.

### Station forms and offline behavior

- **P1 fixed:** Equipment Request used the queue type `fire_equipment_request`, which replayed to a nonexistent `/api/admin/fire_equipment_request` endpoint and still displayed success. A matching public write endpoint now validates and stores the full request in the admin model.
- **Fixed:** stolen-item requests require a police case number. Item quantities, reasons, priority, signatures, and images are preserved.
- **Fixed:** admin image rendering accepts stored paths or validated legacy image data only; invalid/external sources are rejected and URLs are escaped.
- **Fixed:** Station Inspection, Equipment Request, and TRT submissions first attempt a direct online request. Only network failures, offline state, HTTP 429, and server failures are queued. Permanent 4xx validation failures are shown to the operator and are not replayed forever.
- **Fixed:** the UI now distinguishes `Submitted` from `Saved Offline` instead of reporting every queued operation as completed.
- **Fixed:** anonymous Big Ticket and legacy Station Inventory submissions store `created_by = null` instead of falsely attributing them to admin user 1.
- **Fixed:** legacy Station Inventory and Big Ticket list/PDF reads require authenticated admin access; public submission/category endpoints remain available to the station workflow.

### Truck Check-out, Fire Apparatus, PM, and history

- **Verified:** 26 apparatus records; no duplicate vehicle numbers, slugs, or unit IDs. Vehicle number and slug are populated for every record; one optional `unit_id` is null.
- **Verified:** checkout selection uses the unique apparatus slug and posts to the numeric apparatus primary key.
- **Fixed:** the client-supplied unit number can no longer overwrite authoritative identity; the server records the apparatus vehicle number.
- **Fixed:** `engine_hours` and `miles` are stored on each inspection as well as updating current apparatus counters, preserving historical PM evidence.
- **Fixed:** inspection references are generated from the committed database ID and protected by a unique database index, removing the same-day count race.
- **Fixed:** apparatus defects link back to the originating inspection with issue type and report date.
- **Fixed:** Fire Apparatus and Inspection admin views referenced nonexistent `mileage`/legacy fields and an unavailable edit page. They now display the actual meter/history fields and valid actions.
- **Fixed:** a nested inspection URL now constrains the inspection to its parent apparatus.
- **Verified:** 18 apparatus records have a current/baseline PM meter or date and none were due at audit time. The remaining eight may represent non-meter assets and were not relabeled without fleet-owner input.

### SnipeIT

- **Verified:** 19 of 26 apparatus records map to SnipeIT; every mapped remote ID exists and its asset tag matches. Six mapped apparatus have 205 assigned child assets in total.
- **Fixed:** an unmatched SnipeIT manifest asset is skipped and logged. It is never audited, marked present, moved to repair/missing, or given a maintenance record without a matching submitted checklist item.
- **Verified:** production status labels are `4 = Out for Repair` and `5 = Missing`; the IDs are now environment-configurable.
- **Gap:** seven apparatus records have no SnipeIT asset mapping, so Truck Check-out cannot update SnipeIT for those records.
- **Gap:** the existing `snipeit:sync-users` command synchronizes admin users, not the 237 employee portal profiles. No employee email/Snipe user ID mapping exists.

**Recommendation:** create a governed `employees.snipeit_user_id` mapping, reconcile by immutable employee number, and add a preview-only reconciliation command before enabling writes. For apparatus, have Fleet/Logistics classify the seven unmapped records, map only inventory-bearing vehicles, and require a unique Snipe asset ID/tag before enabling audit jobs.

### Google Sheets

- **Verified:** sync is enabled, the credential file exists, spreadsheet and tab are configured, and API metadata access succeeds.
- **Verified:** 26 expected Hub rows equal the 26 designated sheet rows with no mismatch.
- **Verified:** no failed or pending Google Sheet sync jobs were present at audit time.
- **Verified in code:** model updates dispatch after commit; manual admin synchronization exists; the job uses bounded retry/backoff consistent with Sheets API quota guidance.

### Admin dashboard and backend

- **Verified:** all 304 application routes are named; 114 are admin routes. Public attempts to read protected admin APIs return authentication failures rather than data.
- **Verified:** a server-render smoke test covers the dashboard and 13 critical Employee, Uniform, Equipment Request, Apparatus, Inspection, Fire Equipment, Station Inspection, Station, and TRT admin surfaces.
- **Fixed:** public employee identifiers and legacy station report reads were exposed more broadly than required.
- **Fixed:** the test environment now explicitly uses in-memory SQLite for the application and web-push data and supplies a nonsecret test-only application key, eliminating accidental workstation MySQL coupling.
- **Fixed:** `league/commonmark` was upgraded from 2.8.3 to 2.9.1 to clear six newly published parser advisories.
- **Observed:** the five production failed jobs are older `GenerateOperationalFormPdf` failures from July 21, 2026. They are unrelated to the audited request/checkout synchronization, but should be archived or retried after document-owner review.

## Performance and scalability

- Daily routes and large form wizards now load through `React.lazy`/dynamic imports.
- Daily initial JavaScript: **620.57 kB → 341.61 kB minified** and **179.07 kB → 109.94 kB gzip** (about 45% and 39% reductions respectively).
- Main application: PDF generation now loads only when Export is pressed; the prior 778.94 kB main bundle was replaced by chunks whose largest executable JS chunk is 420.05 kB.
- npm audits are clean in both package roots.
- Queue-dependent integrations retain explicit attempts and backoff. Queue worker timeout must remain lower than Redis `retry_after` to avoid duplicate processing.

## Verification evidence

- PHPUnit: **281 tests, 1,220 assertions, all passing** after formatting and the dependency security upgrade.
- Added focused coverage for personnel imports, public directory redaction, public Fire Equipment requests, portal-to-admin employee requests, uniform stock issuance, SnipeIT unmatched-asset safety, critical apparatus defect linkage, and critical admin page rendering.
- TypeScript: root and daily-checkout typechecks pass.
- Node operational-forms regression: 1 test passes.
- Production builds: root and daily-checkout pass.
- Security: root npm, daily npm, and locked Composer audit report zero known advisories after remediation.
- Laravel: configuration, route, and Blade view caches compile successfully.
- Formatting: all 37 changed PHP files pass Pint; repository-wide Pint remains a separate pre-existing backlog.

## Research basis

- [NIST SP 800-63B](https://pages.nist.gov/800-63-4/sp800-63b.html): long passwords, blocklists, rate limiting, and no arbitrary periodic reset absent compromise.
- [OWASP Authentication Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Authentication_Cheat_Sheet.html): generic authentication errors, throttling, and enumeration resistance.
- [SnipeIT Hardware Audit API](https://snipe-it.readme.io/reference/hardware-audit-by-id) and [Hardware List API](https://snipe-it.readme.io/reference/hardware-list): asset audit and assigned-asset behavior.
- [Google Sheets API usage limits](https://developers.google.com/workspace/sheets/api/limits): bounded retries and exponential backoff for quota/server failures.
- [Vite feature guide](https://vite.dev/guide/features.html): dynamic imports create lazy chunks and Vite preloads their shared dependencies.
- [Laravel 12 queue guidance](https://laravel.com/docs/12.x/queues): retry/timeout coordination and backoff.
- [WCAG 2.2 target size guidance](https://www.w3.org/WAI/WCAG22/Understanding/target-size-minimum): 24 px minimum at Level AA; the audited primary actions use the stronger 44 px enhanced target.

## Remaining acceptance gates

These are intentionally not reported as verified by automated tests:

1. HR confirmation of the eight live-only employee records.
2. Fleet/Logistics classification and mapping of the seven SnipeIT-unmapped apparatus records.
3. Governance/design approval for employee-to-SnipeIT identity synchronization.
4. Physical tablet/smartboard, camera/signature, printer/PDF, Safari, and poor-connectivity/offline acceptance.
5. Authenticated live-browser traversal after the release, including cleanup of any isolated audit fixture.
