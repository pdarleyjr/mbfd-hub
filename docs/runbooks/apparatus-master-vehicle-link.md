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
  row, for Fleet to review.
- Idempotent: a second run reports `Changes written: 0`. `--dry-run` writes nothing.

## Writers outside the Admin pages

The four Admin Create/Edit pages save inside one transaction with the counterpart write.
Other writers (master inventory import, PM backfill, seeders, the take-home lookup that
creates a master vehicle) still sync through the model observers, but if the counterpart
write fails the primary row is already saved; run the reconciliation command to repair.
`apparatus:backfill-pm-data` no longer overwrites the vehicle number, make, model or year
of a linked apparatus.
