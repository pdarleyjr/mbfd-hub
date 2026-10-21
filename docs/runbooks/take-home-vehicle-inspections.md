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

## Data rules

- Each submission is an immutable `take_home_vehicle_inspections` row with a vehicle snapshot;
  the model refuses updates and deletes. Photos are re-encoded JPEGs (no EXIF/GPS) on the
  private disk, served only through `/take-home-vehicle-inspections/photos/{uuid}`.
- Vehicle details edited during an inspection: blank master fields are filled (audited as
  `applied`); changes to existing values, and any plate/VIN already on another vehicle, are
  stored as `proposed` corrections in `unit_master_vehicle_changes` for Fleet.
- Members see only inspections they submitted. Fleet viewers (`super_admin`, or
  `admin.access` + `admin.fleet.view`/`admin.fleet.manage`) see all of them.
- Unsubmitted photo uploads older than 96 hours are removed daily by
  `fleet:prune-take-home-uploads`.

## Fleet administration

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
