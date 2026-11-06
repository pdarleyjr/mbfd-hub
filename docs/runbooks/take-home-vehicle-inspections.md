# Take Home Vehicle Inspections and the Master Unit Vehicle roster

## What members see

- Footer link **Take Home Vehicle Inspection** on the Hub home page leads to
  `/take-home-vehicle-inspections` (any signed-in Hub account; no role or panel permission).
- **New inspection** (`/take-home-vehicle-inspections/new`) has four steps:
  1. Vehicle and driver: vehicle search (type the number and press Go), add a missing vehicle,
     editable vehicle details, driver from the personnel directory or "Driver not listed",
     inspector and time (automatic), odometer or a no-odometer exception.
  2. Condition: six checks (exterior body, windows & mirrors, tires & wheels, interior,
     dashboard / warning lights, exterior lights / signals). Each starts unanswered and must be
     Good, Issue found (description required, optional notes and photos) or Not checked.
  3. Photos: six required angles (front, rear, driver side, passenger side, interior, dashboard).
  4. Keys present, acceptable for use, comments, acknowledgment and handwritten signature.
- After submission the member returns to their own history with View and PDF links.
- **Edit** (`/take-home-vehicle-inspections/{reference}/edit`, the submitting member only, until
  Fleet clears it): correct the driver, odometer, the six checks, keys, acceptability and
  comments. The vehicle, photos and signature stay as submitted. The report and PDF then show
  the corrected answers with "Edited by NAME on DATE" and a before/after list of every change.

## Data rules

- Each submission is an immutable `take_home_vehicle_inspections` row with a vehicle snapshot;
  the model refuses deletes and any update other than the clear (archive) columns. A member's
  correction is a new `take_home_vehicle_inspection_revisions` row (changed fields with before
  and after, full corrected answers, who, when, optional reason; idempotent on
  `client_revision_id`). `TakeHomeVehicleInspection::effective()` lays the latest revision over
  the original for display. A correction that adds a new problem (new Issue found, newly not
  acceptable, newly flagged odometer) shows the dashboard notice again for 24 hours; other
  corrections never do. Photos are re-encoded JPEGs (no EXIF/GPS) on the
  private disk, served only through `/take-home-vehicle-inspections/photos/{uuid}`.
- Vehicle details edited during an inspection: blank master fields are filled (audited as
  `applied`); changes to existing values, and any plate/VIN already on another vehicle, are
  stored as `proposed` corrections in `unit_master_vehicle_changes` for Fleet.
- Members see only inspections they submitted. Fleet viewers (`super_admin`, or
  `admin.access` + `admin.fleet.view`/`admin.fleet.manage`) see all of them.
- Unsubmitted photo uploads older than 96 hours are removed daily by
  `fleet:prune-take-home-uploads`.

## Fleet administration

- **Clear** a handled inspection from `/admin/take-home-vehicle-inspections` (row action) or
  from its dashboard notice. Fleet viewers may clear. A cleared inspection leaves the active
  list and the dashboards, is listed under Visibility: Archived, can be restored there, and can
  no longer be edited by the member. Clears and restores are logged in `archive_history`.

- `/admin/unit-master-vehicles`: Export Excel / Export PDF (current filters and search, or all
  records), vehicle profile page with the last take-home inspection, history, proposed
  corrections (Accept / Reject for `admin.fleet.manage`) and change history.
- `/admin/take-home-vehicle-inspections`: read-only list with a "Needs attention" filter.

## FY27 fleet list reconciliation

The city's FY27 list (`FY 27 Fire Department Fleet Asset List.xlsx`, 69 assets in rows 2-70,
26 of them in hidden rows, 4 beyond the saved filter range) is compared against
`unit_master_vehicles` by `fleet:reconcile-fy27`:

```bash
php artisan fleet:reconcile-fy27 storage/app/private/fleet-imports/fy27_assets.csv
php artisan fleet:reconcile-fy27 storage/app/private/fleet-imports/fy27_assets.csv --apply --report=storage/app/private/fleet-imports/fy27-report.json
```

- Dry run by default. `--apply` runs in one transaction and is idempotent.
- Fills empty fields and new FY27 attributes (color, status letter, class), applies obvious
  model refinements, inserts assets missing from the Hub, and records every other
  contradiction (including assigned names) as a proposed correction. Nothing is deleted;
  Hub vehicles absent from FY27 are only listed.
- FY27 status letters (A/R/P) are stored verbatim. Their meaning is not yet confirmed by Fleet
  and they are not used for availability decisions.
- The staged CSV and JSON report contain personnel assignments: keep them on the server's
  private storage, never in git.
