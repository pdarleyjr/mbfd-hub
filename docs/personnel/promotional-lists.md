# Promotional lists

Official promotional eligibility lists (Lieutenant, Captain, Driver Engineer, Division Chief and others)
live in the Hub as data. They are managed in **Admin > Personnel > Promotional Lists** and shown, per
member, in the **Master Personnel Directory**.

Nothing in this document, the migrations, the seeders or the tests contains real list data. Official
results are loaded on the server with the import command below and are never committed to git.

## Model

| Table | Purpose |
|---|---|
| `promotional_lists` | One official list: `list_type` (`lieutenant`, `captain`, `driver_engineer`, `division_chief`, `other` + `type_label`), `title`, `effective_date`, `expires_on` (optional), `status` (`draft`, `active`, `expired`, `superseded`), `source_reference` (describes the source document), `notes`, `created_by`. UUID key, soft deletes. |
| `promotional_list_entries` | One candidate: `original_rank` (as printed, unique per list), `soi_id`, `last_name` / `first_name` (snapshots as printed), `scores` (text exactly as printed: `"82.00"`, `"P"`, `"-"`, `""`), `employee_id` (optional link to the personnel roster, unique per list), `status` (`eligible`, `promoted`, `removed`, `declined`, `expired`), `status_reason`, `status_changed_by/at`, and the materialized `current_rank`. |
| `promotional_list_events` | Append-only history. Rows cannot be updated or deleted (the model refuses). |

List types are configured in `config/promotional_lists.php`; add a track there without a migration.

## Current-rank rule

`current_rank` is stored, never typed in, and recomputed in the same transaction as every change:

* While a list is **in effect** (status `active` and not past `expires_on`), its **eligible** entries,
  ordered by `original_rank`, hold current ranks `1..N` with no gaps, duplicates or zeros.
* Entries that are not eligible, and every entry on a list that is not in effect (draft, expired,
  superseded, or active but past its expiry date), have a **null** current rank.
* The first printed rank may be missing. If rank 1 left the list, original rank 2 becomes current rank 1
  and everyone below shifts up. Returning an entry to eligible puts it back at its original-rank position.
* Each recompute that changes anything writes a `ranks_recomputed` event with the before and after of
  every affected entry.

`promotional_lists.status` is the stored value. A list that is `active` but past `expires_on` already
reads as **Expired** everywhere and ranks nobody; the daily command `personnel:expire-promotional-lists`
(scheduled 00:10 Eastern) then writes `expired` and the history line "Expiry date reached".
Use `draft` until the list's effective date; only active lists appear in the directory.

## Import

```
php artisan personnel:import-promotional-list {csv} --type= --title= --effective= [--expires=] --source= [--label=] [--status=active] [--apply]
```

* **Dry run by default.** It prints each row (create, unchanged, conflict), the personnel match, and the
  totals, and writes nothing. Add `--apply` to save.
* **CSV header (exact):** `original_rank,soi_id,last_name,first_name,written,oral,composite,seniority,education,max_promotional,veteran,total`.
  Lines starting with `#` and blank lines are ignored, so a header comment can say what is missing.
  Values are stored as text exactly as written. Maximum 500 rows, 1 MB.
* **Idempotent.** The list is found by type + effective date (for `other`, plus the label); an entry by
  SOI ID or original rank. Running the same import again changes nothing except one `imported` history line.
* **Never overwrites.** An existing entry that differs from the CSV is reported as a conflict and left
  alone; status, reasons, links and corrections made in the admin are kept. Edit entries in the admin.
* **Linking.** An entry is linked to a personnel record only when first + last name equal exactly one
  **active** employee (case, accent, punctuation and spacing are ignored; nothing fuzzy). Duplicate names,
  retired members, middle names and misspellings stay unlinked and are listed for review. A later re-run
  links an unlinked entry once the roster has one exact match. In the admin, **Link to personnel record**
  shows exact-name candidates but always requires an explicit pick.

## Permissions

* **Viewing** the list pages and the directory fields follows the Master Personnel Directory view
  permission (`personnel.directory.view`, `admin.personnel.view`, `admin.members.view`, or the legacy
  personnel managers). The uniform and PPE manager set is not a personnel manager and sees nothing.
* **Changing** anything needs the direct capability **`personnel.promotions.manage`** (or `super_admin`),
  on top of directory view. The migration only creates the permission: an administrator grants it
  per member in the access administration screen ("Promotional lists - manage"). Migrations never
  grant access, because the production deploy gate refuses permission changes on existing accounts.
* **Exporting** (Excel, PDF) needs directory export rights (`personnel.directory.export`, or manage, or
  the legacy personnel managers).
* Every service method re-reads the account and re-checks, so hidden buttons, stale sessions or forged
  Livewire calls cannot change a list. The console (import, expiry scheduler) is the only caller allowed
  without a user and is refused outside the console.

## Admin screens

* **Promotional Lists:** type, title, effective, expires, status, eligible count.
* **List detail:** entries table (current rank, original rank, name, linked personnel record, status with
  reason, scores). Actions: add entry, correct entry, link to personnel record, change entry status
  (reason required, for example "Promoted 2026-11-01"), change list status (reason required), history,
  export. Number columns in the Excel export are real numbers only where the printed value is numeric
  (`P`, `-` and names are text, so nothing can run as a formula).

## Master Personnel Directory

Four source fields, available in the column chooser (hidden until chosen), sortable, included in CSV,
Excel and PDF exports, and usable in saved views and custom personnel tables:

| Field | Shows |
|---|---|
| Promotional list | list type and title |
| Promotional rank | current rank (`Not ranked` when the entry is not eligible) |
| Promotional status | the entry status (Eligible, Promoted, Removed, Declined, Expired) |
| Promotional list expires | the list's expiry date, or "No expiry date" |

Only lists in effect are shown. A member on several lists in effect sees each one (newest effective date
first, labeled by list type); sorting uses the best rank. Unranked members always sort last. Filters:
**Promotional list** (type) and **On an active promotional list**. Display values come from one extra query
per request, so the page does not query per member.

## Responsive tables

The Master Personnel Directory and Master Vehicle Inventory use the full content width, compact cells,
wrapping headings, sticky selection/identity/actions columns, and secondary values stacked under a main
column (A-Day under Shift, bid position and division under Seat / unit, email match status under
Email; tag under Veh #, model and year under Make, assignment under Employee/Vehicle). Every stacked
value is still its own sortable, toggleable and exportable column; hidden-by-default columns are in the
column chooser, and the row detail (Details modal, vehicle profile) shows every field. The styles are in
`resources/views/filament/hooks/responsive-tables-styles.blade.php`, loaded only on those two pages by
`App\Providers\ResponsiveTablesServiceProvider`. The directory export adds a stacked companion
column whenever its main column is exported.
