# Personnel forms, accident reports, documents, Fuel PINs and station cleaning

Release: November 2026 (branch `feat/personnel-directory-forms-20261105`).

## What members see

| Where | What |
| --- | --- |
| Employee Portal > Forms | Vehicle Accident Report, Supervisor's Report of Accident (supervisors), Interoffice Memorandum, My accident reports |
| Employee Portal > My Documents | Files shared with the member, their finished accident reports, memoranda sent to Support Services, completed Operational Forms, taskbooks |
| Employee Portal > Home | Fuel PIN in the bid details card (or a small "My details" card) |
| Station page (Daily Checkout) | Today's Station Cleaning card |

## Accident reports

- One record per accident: `accident_reports`. The employee involved, the reporter and the supervisor are separate columns.
- Vehicle Accident Report: the driver submits page 2 and picks the supervisor. Administrators are alerted at that moment
  (`accident_reports` notification category, the red row on Admin Overview > Personnel). The chosen supervisor is
  notified and completes page 1 on the same record. Then the official two-page PDF is produced.
- Supervisor's Report of Accident: a supervisor completes and signs it in one step.
- Supervisors: Lieutenant, Captain, Battalion/Division Chief, Deputy/Assistant Chief, Fire Chief (by rank), or anyone
  granted `personnel.accidents.supervise`. Nobody completes the supervisor's part of their own accident.
- Autosave uses a revision number; a stale save gets HTTP 409 with the current version. Starting and submitting are
  idempotent (client key; a repeated submit raises no second alert).
- Signatures are drawn on screen (PNG, `accident-reports` namespace). The PDF prints a line saying who signed in the
  Hub and when. A typed name is never presented as a signature.
- Hub submission does not deliver the report to Risk Management. The form's instructions are shown on every screen;
  download the PDF and deliver it.
- Every Hub administrator (admin panel access) opens Personnel > Accident Reports in full and can acknowledge the alert
  (records who saw it; the accident stays open). Owner decision 2026-11-05: an accident report is not a medical record.
  Follow-up, correcting an entry (old value kept in history, new PDF version) and resolving need
  `personnel.accidents.manage` (or super admin). `personnel.accidents.view` opens reports for non-admin accounts.
- The vehicle's Master Vehicle Inventory page lists its accident reports for administrators.

## PDFs

`scripts/personnel-forms/render.mjs` (pdf-lib, the same Node runtime as Operational Forms) prints values onto the
official PDFs in `resources/forms/<form>/<version>/`: `template.pdf` (the supplied City/MBFD form with its unusable
AcroForm widgets removed; the page content is unchanged), `layout.json` (measured positions) and `manifest.json`
(checksums the renderer verifies). Text that does not fit ends with "(continued on attached page)" and the rest
prints on a labelled continuation page; nothing is clipped. Change a layout with scripts/personnel-forms/tools/build_layouts.py (it rewrites the manifest checksums);
never hand-edit a layout or template without regenerating the manifest.

## Personnel documents

`personnel_documents` is the one record per stored file; profiles, My Documents, vehicle history and Support Services
messages point at it. Uploads are type-checked (`VehicleDocumentLibrary::inspectFile`), malware-scanned when the
scanner is configured, stored privately and hashed. A new upload is **not** shown to the member until "Show in
member's My Documents" is chosen. Every view, download and visibility change is recorded in
`personnel_document_events`. Files are served only through `/personnel-documents/{ulid}/view|download` after
`PersonnelAccess::canViewDocument`.

## Memorandum

- Addressed to anyone other than Support Services: produced on request and downloaded. Nothing is stored. "Email a
  copy to my City email" appears only when the Cloudflare sender is configured and the City email is verified; the
  Email Center keeps no body or attachment for `personnel_memorandum_copy`.
- Addressed to Support Services: starts a normal Support Services conversation (subject "Memo: ...") with the PDF
  attached as a personnel document the member also sees in My Documents. Support Services Automation treats it like
  any other member message.

## Fuel PINs

See `app/Services/Personnel/FuelIdImporter.php`. Import: `php artisan personnel:fuel-ids-import <file> --dry-run`,
then without `--dry-run`. The source PDF is kept privately as the source record; PINs are stored encrypted as strings.
Duplicate Employee IDs (e.g. 16845) and name mismatches are never assigned; resolve them on Personnel > Fuel ID Numbers.

## Station cleaning

`station_cleaning_tasks` holds the department schedule (seeded from "Station Clean Up Schedule", pages 85-86). Today's
card is computed at read time in America/New_York; no scheduled job. Station 2 alone gets the Friday Decon Room task.
Sunday has no rotating assignment. Edits (Stations > station > Edit cleaning schedule, `stations.cleaning.manage`) are
recorded in `station_cleaning_task_revisions`.

## Permissions (created by migrations, granted only through Employees & Access)

`personnel.documents.view|manage`, `personnel.accidents.view|manage|supervise`, `personnel.fuel_ids.view|manage`,
`stations.cleaning.manage`. Super admins hold all of them.
