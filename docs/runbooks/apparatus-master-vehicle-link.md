# Apparatus and the Master Vehicle Inventory: one vehicle, two views

Every fire apparatus is a physical vehicle, and every vehicle has one master record in
**Fleet > Master Vehicle Inventory**. That record keeps the vehicle's history: identity,
plate, fleet class, take-home inspections, documents (accidents, repairs, registration)
and every field change. **Fleet > Apparatus** is the operational view of the same vehicle
for the Fleet manager and mechanics: status, open work, PM, Daily Checkout and service.

`apparatuses.unit_master_vehicle_id` ties the two (unique: one apparatus per vehicle).
`master_link_status` is `linked` or `needs_review`; `master_link_note` says why a unit
needs review. Vehicle swaps move a unit's identity (designation, station) between truck
rows and never move the vehicle number, so links stay with the physical trucks.

## Who owns which field

| Field | Master column | Apparatus column | Rule |
|---|---|---|---|
| Vehicle # | `veh_number` | `vehicle_number` | Master. A master edit updates the apparatus. On the apparatus the number is set by the link ("Link vehicle record"); a number changed by any other writer moves the link to the vehicle with that number and never renames the master. |
| Make, model, year | `make`, `model`, `year` | same | Shared. Editable on either page; one save updates both rows in one transaction. |
| VIN | `serial_number` | `vin` | Shared, as above. |
| Plate, color, department, section, fleet class, category, driver, SunPass, ALS license, documents, accidents, take-home inspections, history | master only | - | The apparatus profile shows a summary and links to the record. |
| Designation, unit ID, class, station, assignment, location, status, Daily Checkout policy and template, meters, PM, service, notes, defects, tickets, swaps, inventory corrections | - | apparatus only | The master profile shows the unit, status, station and open work and links to it. |

**Conflicts.** After any save both rows hold the same shared values; the last committed
write wins and is recorded. When a unit is linked, the master value wins wherever the
master has one; a blank master field is filled from the apparatus. Every change on either
side is a row in `unit_master_vehicle_changes` (status `applied`) with the user and a
source: `apparatus_link` (linking and reconciliation), `apparatus_page` (edited on the
apparatus), `master_inventory` (edited on the master record). The vehicle profile's
Change history shows them.

**Matching is never guessed.** A unit links automatically only when exactly one master
vehicle has the same number (trimmed, case and spacing ignored; zeros and dashes kept, so
`002-16` is not `2-16`) and the VINs do not contradict each other. Otherwise the unit is
marked "Vehicle record needs review" with the reason, appears under the Apparatus list's
**Vehicle record review** tab, and Fleet decides with **More > Link vehicle record** on the
unit profile (needs edit rights on Apparatus and on the Master Vehicle Inventory).

A new apparatus whose vehicle number is not in the master inventory creates the master
record (record source `apparatus_page`, flagged "Needs review" for Fleet) and links it.

## Reconciliation command

```bash
php artisan fleet:link-master-vehicles --dry-run
php artisan fleet:link-master-vehicles --json=storage/app/fleet-link-report.json
```

- Re-checks every apparatus: links clean matches, repairs drifted shared fields to the
  master value (audited), and flags the rest for review. It never creates master records.
- Lists master vehicles in "Frontline and Reserve Fire Apparatus" that have no apparatus
  row, for Fleet to review (the same list as the **Apparatus review** tab below; vehicles
  marked "not an apparatus" are left out).
- Idempotent: a second run reports `Changes written: 0`. `--dry-run` writes nothing.

## Writers outside the Admin pages

The four Admin Create/Edit pages save inside one transaction with the counterpart write.
Other writers (master inventory import, PM backfill, seeders, the take-home lookup that
creates a master vehicle) still sync through the model observers, but if the counterpart
write fails the primary row is already saved; run the reconciliation command to repair.
`apparatus:backfill-pm-data` no longer overwrites the vehicle number, make, model or year
of a linked apparatus.

## One-time vehicle questions: crews fill the gaps, Fleet only sees real conflicts

Crews are asked, once, for the vehicle facts the records lack (vehicle number, VIN or hull
ID, plate, year, make, model). The server decides what is open on every request, and no
question blocks a Daily Checkout. Nothing is guessed: matching uses exact identifiers only.

**Where a unit is asked.** Every apparatus except inactive units and test units (`ZZ-*`):
a unit that does a Daily Checkout (policy Required) is asked in the checkout; the Captain 5
unit on the Captain 5 page; every other unit that belongs to a station on that **station
page** in the Daily app (a "One-time vehicle information" card, carried in the existing
station detail data as `vehicle_identification_units`). Answers post to
`POST /api/public/apparatuses/{id}/vehicle-identification`; any active member with an
employee profile may answer for a checkout or station-page unit, Fleet managers for any
asked unit, and the Captain 5 unit needs Captain 5 act access.

**A unit linked to a vehicle record** (the record is the source of truth):

| The record says | What happens |
|---|---|
| nothing for that field (blank, or a placeholder such as N/A, NA, NONE, -, 0, UNKNOWN, X) | The crew's value is written at once, attributed to the member, source `daily_checkout`, note "Reported in Daily Checkout by NAME" (or "on the station page"). No proposal, no Fleet alert. Applies to VIN/HIN, plate, year, make, model; never the vehicle number (the link defines it). |
| the same value | Confirmed, the question closes. |
| a different value, and a pending proposed correction holds the crew's value (for example Captain 5: FY27 plate XK4560 against XD1904) | The proposal is accepted automatically as the member ("Confirmed by NAME ..."), the question closes, no alert. Answering XD1904 instead just confirms it. |
| a different value, nothing pending matches | A real conflict: the value is proposed once (never duplicated) and Fleet gets one alert. The record is not changed. |
| a VIN/HIN or plate that another vehicle record already holds | Always a conflict. Nothing is written. |

**A unit not linked yet** (rules checked in this order):

1. The VIN/HIN equals exactly one record no other unit holds, and the unit's own number and
   VIN agree: linked.
2. The vehicle number equals exactly one such record and the same record's plate (or VIN)
   matches: linked.
3. Anything partial, contradicting or ambiguous (a plate alone, a number with a different
   VIN, a record already linked elsewhere): the unit is flagged for review with what the
   crew reported, and Fleet is alerted. Never linked, never duplicated.
4. No record at all has that VIN/HIN, number or plate, and the crew gave a VIN/HIN or a
   vehicle number: a new record is created (record source `daily_checkout`, flagged "Needs
   review", history note "Created from Daily Checkout, pending Fleet verification") and
   linked. Fleet is told so it can verify; no further step is needed for the link.
5. Otherwise the questions stay open.

Replaying a submission changes nothing (idempotent by `client_submission_id`). The crew's
answers, the decisions and any acceptance are in the apparatus **Identification** tab
history and the vehicle record's change history.

## Apparatus review: vehicle records with no apparatus

**Fleet > Master Vehicle Inventory > Apparatus review** (tab with a count) lists records in
"Frontline and Reserve Fire Apparatus" whose category is Vehicle (or blank), that no
apparatus is linked to, and that Fleet has not marked "not an apparatus". For each:

- **Create apparatus record** (needs create on Apparatus and edit on the vehicle record).
  The modal is prefilled from the record (vehicle #, year, make, model, VIN are read-only
  and stay shared; the class description suggests the type). Fleet enters the designation
  (required) and chooses station, status (default Available) and Daily Checkout policy
  (default Unknown). The apparatus is created and linked in one transaction, the vehicle's
  change history records who did it (source `master_inventory`), and the new apparatus
  profile opens. An apparatus is never created without this click.
- **Not an apparatus** (support or staff vehicle, optional note). Sets
  `not_apparatus_at`, `not_apparatus_by_user_id`, `not_apparatus_note` on the record and
  adds a change-history entry. The record leaves the worklist and the
  `fleet:link-master-vehicles` "master only" list. **Undo not an apparatus** (on the row
  and the vehicle profile) puts it back and is also recorded.

## Old Captain 5 Tahoe identity

Captain 5 was linked to vehicle 22530 (2022 Ford F-250). Its apparatus row used to carry a
Chevrolet Tahoe identity with VIN `1GNSKEEGXPR511447`. That VIN is history only: it is kept
in vehicle 22530's change history and is in no master record. No system action is needed;
Fleet may confirm separately whether that Tahoe still exists.