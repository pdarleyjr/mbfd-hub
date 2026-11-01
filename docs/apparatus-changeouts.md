# Apparatus changeouts and operational alerts

Status: implementation contract, 2026-11-01. Read this with `docs/runbooks/apparatus-master-vehicle-link.md`,
`docs/daily-inspection-continuity.md` and `docs/apparatus-inventory-corrections.md`.

A firefighter only needs to say: what truck am I moving to, what did I take, what did I leave. It happens when they send
it. Nobody approves it. Fleet is told, and acknowledges having seen it. Admins can preschedule a swap, and that is all a
schedule does.

## 1. Rules that never bend

1. An `apparatuses` row is a physical truck. Vehicle number, VIN, meters, defects, inspections, service tickets and
   the master vehicle link never move. Only the unit identity moves: `unit_id`, `designation`, `name`, `slug`,
   `station_id`, `assignment`, `daily_checkout_requirement`, `daily_checkout_template`.
2. Vehicle numbers are strings. `002-16` is not `2-16`. Compare with `App\Support\Fleet\VehicleNumber::same`.
3. **The new truck is In Service. The old truck is Out of Service. Every swap, including a return, whatever either truck
   was before.** `old_truck_status_after` on an alert is not read.
4. A swap does not fix a truck. Tickets and defects stay open on the truck they are on.
5. The crew's work goes through. The only refusals are an ill-formed number, the truck the unit is already on, and the two
   conflicts in 4.4. A stale alert id, an item the list no longer has, or a changed list never stops them.
6. No item is controlled. Members have full control of what they took and what they left.
7. A checklist template is the expected list, not a custody record. `apparatus_changeout_items` records what a named person
   said happened at a named time, and never edits templates, overlays, inspections or defects.
8. Every state change goes through a service with server-side authorization. UI code never writes these tables.

## 2. Records

| Table | Purpose |
|---|---|
| `apparatus_operational_alerts` | One authoritative alert about a unit. Projected onto station pages, the apparatus profile and Fleet. |
| `apparatus_changeouts` | One move of a unit from truck A to truck B, with audit, idempotency and acknowledgement. |
| `apparatus_changeout_items` | What the crew took and left, one line per checklist item. |
| `apparatus_fleet_events` | Append-only history for both of the above. |

### Alert fields that matter

`unit_slug` is the unit (the slug moves between truck rows with the identity, and is unique and never null).
`apparatus_id` and `vehicle_number` are the truck when the alert was raised and do not change.

Categories: `upcoming_swap`, `maintenance`, `recall`, `service_interruption`, `general`. A unit has one active
`upcoming_swap` at a time. Status: `active` -> `completed` | `cancelled` | `closed`.
Schedule: `scheduled_date` (local date, no time zone), optional `scheduled_window` (`morning`, `afternoon`, `evening`),
optional `scheduled_at` (exact instant). A missing time stays missing. `planned_replacement_apparatus_id` is a suggestion the
wizard preselects, never a restriction.

An alert is visible on a station page when it is `active`, and its `station_id` or `additional_station_ids` holds that
station, and `ends_on` has not passed. An `upcoming_swap` alert does not expire by date: if the day passes it stays up, and
says the planned date has passed, until a changeout or Fleet closes it.

### Changeout fields

`status` is always `completed` and `handoff_status` always `complete`. `acknowledged_at` and `acknowledged_by_user_id` say Fleet
has seen it. `move_audit` holds `mode` (`start` or `handoff_only`), the before and after of both trucks, `old_truck_status`,
`new_truck_status`, `new_truck_record`, `displaced_unit`, `review_notes` and `corrections_left_on_truck`. The columns
`decided_*`, `quantity_moved`, `condition`, `item_class`, `verified_*` and `apparatus_defect_id` remain in the schema, unused.

### Lines

| handling | meaning | custody_status |
|---|---|---|
| `transfer` | Took it to the new truck. Every line's default, including on a return. | `moved` |
| `leave` | Left it on the old truck. | `left_behind` |

Each may carry a note. With no usable checklist there are no lines, and the crew's overall note stands.

### Where the equipment list comes from

`DailyCheckoutChecklistResolver::resolve($truck)`, effective checklist, checked for `usable`. Templates differ: v1 compartments
use `title`, v2 use `name`. Item ids may be positional, so use the ids the effective checklist reports. Officer fields are not
equipment lines.

### Known limit

`apparatus_inventory_changes` (approved checklist corrections) are keyed to the truck row and stay there, so a unit's
corrections do not follow it to the new truck. The move records the count in `move_audit.corrections_left_on_truck`
and Fleet sees it on the changeout page.

## 3. Who can do what

| Action | Who |
|---|---|
| See alerts on a station page | Any signed-in member who can open the station page |
| Change trucks, for any unit, on any day | Active member with an employee profile, or a Fleet manager |
| Acknowledge a change | `User::canManageFleetSwaps()` (`acknowledge` through the admin capability gate is `admin.fleet.manage`) |
| Create, edit, cancel or close alerts; preschedule swaps | `canManageFleetSwaps()` |
| View admin pages, the dashboard issue and the banners | `admin.fleet.view` through the admin capability gate |

## 4. The move

### 4.1 Which truck

Any truck is allowed. The context lists same-type trucks with a number: `candidates` are the reserve trucks
(`daily_checkout_requirement` is `reserve`), in any status; `other_vehicles` are the rest, with `listed_as`.

- **A truck holding another frontline unit** trades identities: that unit's identity goes onto the old truck, and the alert says
  "R 4 was on truck 17502 and is now listed on truck 17503, which is Out of Service. Please review."
- **A typed number with a row** uses that row. If two records share it, the first that is not the current truck, flagged for review.
- **A typed number with no row** makes one (`ChangeoutNewTruck`), identity `designation` Reserve, `unit_id` `R-<num>`, `slug`
  `r<num>`, `name` `<TYPE> <num>`, requirement `reserve`, template `pending`, the unit's type. One master vehicle with that number
  and no apparatus: built and linked from it. No master: one is created with `needs_fleet_review`, source `daily_checkout`, and a
  history note naming the member. Anything else (two masters, a master marked not an apparatus): the row is left needing a link.
  Every case is flagged in the alert.

### 4.2 What a commit writes, in one transaction

1. Lock the alert, then both truck rows in ascending id. A retry that waited on these locks returns the first result.
2. Refuse if `expected_vehicle_number` or `expected_unit_slug` no longer holds the unit. A truck keeps its number after a move, so
   the slug is what proves the unit is still there.
3. Save the changeout and its lines, from the truck the unit is on.
4. `VehicleSwapService::moveUnitForChangeout($from, $to)`: the new truck takes the unit's identity and is In Service. The old truck
   takes the new truck's previous identity and is Out of Service. Number-derived reserve identities are rewritten to the truck
   carrying them, whole numbers only (`R-1033` on truck 17503 becomes `R-17503`, `r1033` becomes `r17503`, `R-11033` stays), and
   `reserve-<id>` follows the row. Both trucks are at the station the unit is at, until Fleet moves the old one. The list the crew
   was working from stays accepted on the old truck for the checklist grace window, so a checkout queued on a phone still posts there.
5. Complete the alert when it fits (4.3).
6. After commit: clear `DisplaySnapshotService` and station caches, and notify Fleet (section 7). Tickets and defects are not touched.

### 4.3 Alerts

An active `upcoming_swap` alert for this unit raised from this truck is completed by any changeout from that truck, whichever truck
they chose. A changeout for a different unit never closes it, and neither does a move from another truck. An alert id that is stale,
closed, of another kind or about another unit is ignored.

If an admin already moved the unit by editing the apparatus rows, so the alert's truck no longer holds it, its alert offers the
`handoff` action: `handoff_only` mode records what the crew took and left against the existing pair, moves no identity, marks the
truck they left Out of Service, and completes the alert.

### 4.4 Conflicts

| Situation | Answer |
|---|---|
| Same `client_submission_id`, same payload | 200 with the first result, `replayed: true` |
| Same `client_submission_id`, different payload | 409 `CHANGEOUT_REPLAY_CONFLICT` |
| Unit no longer on `expected_vehicle_number` or `expected_unit_slug` | 409 `CHANGEOUT_STALE` with `current_vehicle_number`, `current_apparatus_id`, `unit_designation`. Nothing written. |

### 4.5 At Daily Checkout

The checkout's vehicle step has the assigned truck preset. Choosing another truck, or "Not listed", shows only **Record this vehicle
change**, which opens the wizard with that truck chosen, and the checkout cannot continue until the change is recorded. After the
change the checkout comes back on the new truck. New submissions always send the assigned truck in `physical_vehicle`. A unit with no
vehicle number on file has nothing to differ from: its crew names the vehicle and carries on, and nothing moves.

A phone still on the old app can have a queued checkout whose `physical_vehicle` names another truck, with or without `swap_answer`
and `note`. It is never stranded and never waits for anyone. `App\Services\Fleet\CheckoutVehicleReport` decides, against the locked row,
inside the checkout's own transaction:

| The checkout names | What happens |
|---|---|
| The row's own truck, including a row that just changed out | Nothing moves. Processed on that row. |
| Another truck | `ApparatusChangeoutService::recordFromCheckout`: a swap through the same commit path as a submit, with no equipment lines and the note "Reported at Daily Checkout by NAME. No equipment list was recorded." plus the member's note. New truck In Service, old truck Out of Service, trade and new-record rules, alert completion, review notes and the one notification (after commit), all as usual. Findings and meters go on the reported truck, judged against a meter baseline issued for it. |
| The truck the row's unit already moved to in the grace window | Nothing moves again. Findings and meters go on that truck. |
| A third truck, from a row whose unit has moved away and which is no longer a checkout unit | Nothing moves, because that row holds no unit the crew was checking. Findings go on the reported truck, or on the row with a flagged note if that number has no record. |
| An ill-formed number | Processed on the assigned truck, with a flagged note on its review history (`vehicle_flagged`). |

The changeout's `client_submission_id` is a uuid5 of the checkout's, and a replay of the checkout returns at its own idempotency check,
so a replay creates nothing. If anything fails, the checkout and the swap roll back together. The ordinary holds (meter rollback, member
flag, new significant damage) still apply to the checkout itself and are decided by a reviewer as usual. Inspection `apparatus_id`
stays the route row and `processed_apparatus_id` is the truck the findings went on. The move appears on the inspection's timeline as
`vehicle_changed`.

The old report is gone: no hold, no `pending_vehicle_swap`, no decision page. The table `apparatus_vehicle_swap_requests` stays in the
schema, retired and unused. Production had 0 rows and 0 held inspections when it was retired.

## 5. Going back

`previous_changeout` in the context is the newest changeout of this unit. Picking the truck it left sets
`return_of_changeout_id` on the server, and the candidate reads `is_return`. Each line's `previous` shows what happened last time, but
does not change its default: every line starts as took. The earlier record is never edited. Chains work because each return points
only to the changeout it reverses.

## 6. API (auth:sanctum, prefix `/api/public`, throttle 30/min on writes)

`GET stations/{station}/operational-alerts`: `alerts[]` with `id`, `category`, `category_label`, `title`, `message`, `reason`,
`instructions`, `unit_designation`, `vehicle_number`, `current_vehicle_number`, `current_apparatus_id`, `schedule_label`,
`scheduled_date`, `scheduled_window`, `scheduled_at`, `date_passed`, and

- `workflow { state: planned | vehicle_changed, label, changeout_id: null }`
- `action { kind: start | handoff | none, label, apparatus_id, alert_id, changeout_id: null }`

`GET apparatuses/{apparatus}/changeout-context?alert_id=`

```
unit { apparatus_id, designation, slug, vehicle_number, station_id, station_label, status }
alert { id, title, reason, schedule_label, vehicle_number, planned_replacement_apparatus_id, changeout_id } | null
mode start | handoff_only
candidates [ { apparatus_id, vehicle_number, label, location, status, is_return } ]
other_vehicles [ { apparatus_id, vehicle_number, label, location, status, listed_as } ]
equipment { checklist_version, usable, compartments [ { id, name, items [ { item_key, name, expected_quantity, previous { handling, note } | null } ] } ] }
previous_changeout { id, from_apparatus_id, from_vehicle_number, to_vehicle_number, effective_at, moved, left_behind [ { name, note } ], submitted_by_name } | null
open_issues_on_truck
```

`POST apparatuses/{apparatus}/changeouts`

```
client_submission_id   uuid, required
expected_vehicle_number   required, may be null
expected_unit_slug   required
alert_id   optional
to_apparatus_id   optional
to_vehicle_number   optional
checklist_version   optional
exceptions   [ { item_key, handling: leave | transfer, note? } ]
note   optional
```

Name the truck with `to_apparatus_id` or `to_vehicle_number`; `to_apparatus_id` wins if both are sent. Any line not in `exceptions`
is took. Unknown fields and unknown items are ignored. `201` for a new record, `200` for a replay, both `{ changeout, replayed }`.

```
changeout { id, status: completed, unit_designation, from_vehicle_number, to_vehicle_number, to_apparatus_id, station_id, effective_at,
  old_truck_status: Out of Service, alert_id, alert_completed, return_of_changeout_id, submitted_by_name, moved, left_behind,
  next_daily_checkout_path, message, new_truck_record, displaced_unit, lines? [ { id, item_key, name, compartment, handling, custody_status, note } ] }
```

`moved` and `left_behind` are counts. `message` reads "R 44 is now on truck 1033. Truck 17503 is marked Out of Service."

`GET changeouts/{changeout}` returns the same summary with `lines`, to the submitter, active members with a profile, and Fleet.

Errors follow the Hub shape, `{ "message": "...", "code": "CHANGEOUT_STALE", ... }`, and messages say what happened, that the work is
safe, and what to do next.

## 7. Telling Fleet

On every committed changeout, including `handoff_only`:

- **Notification.** One, through `NotificationDispatchService`, category `vehicle_swap_reports` (shown as "Truck changed"; the stored key does not change), event key
  `apparatus_changeout:<id>:committed`, deep link `/admin/apparatus-changeouts/<id>`, after commit and best effort. A failure never
  fails the swap. There is no new preference store.
- **Admin dashboard.** `App\Services\Dashboards\Sources\Vehicle\VehicleChangeoutSource`, type `vehicle_changeout`, vehicle lane, grouped under
  the truck the unit moved onto. One issue per unacknowledged change, severity medium, stage `awaiting_response`, plus changes
  acknowledged in the operational day as resolved. Title "R 44 changed trucks: 17503 to 1033". Actions: Acknowledge (bulk) and the
  link to the changeout page.
- **Apparatus page.** `VehicleChangesBanner` above the list, with Acknowledge and View for those allowed, and a "Vehicle changes" tab whose
  count is the sidebar badge (`FleetQueueCounts`, one memoized query shared across the page). On the profile, the same banner for
  changes involving this truck, on both trucks. The banner adds one query per page and none per row.
- **Vehicle changeouts.** Read-only history plus Acknowledge; the badge is the unacknowledged count. Took and left lines are shown plainly.

Alert creation can also notify through key `fleet_operational_alerts`, only when `send_notification` is on and only once.

## 8. Reconciling a Fleet status report

`php artisan fleet:apply-status-report --file=database/data/fleet-status-report-2026-10-30.json --dry-run`

Compare-and-set per field against the values the plan was built on. Report rows are matched on vehicle number only, and a row
with an unmatched number is listed, never created. A value changed after the report's date (evidence: a status event newer than
the report) is left alone and listed as a discrepancy. `--apply` writes in one transaction with source attribution in
`unit_master_vehicle_changes`, writes the before values to `storage/app/fleet-status-reports/`, and `--revert=<file>` restores
them with the same compare-and-set. Report rows are not the whole fleet: a truck not in the file is never touched.

## 9. Service signatures (the seams between UI and domain)

```php
// App\Services\Fleet\ApparatusChangeoutPlanner (read only)
context(Apparatus $unit, ?int $alertId = null): array                                  // section 6 shape

// App\Services\Fleet\ApparatusChangeoutService
submit(Apparatus $unit, User $actor, array $payload): array                            // ['changeout' => ApparatusChangeout, 'created' => bool, 'replayed' => bool]
acknowledge(int $changeoutId, User $actor): ApparatusChangeout                         // Fleet managers, idempotent
summary(ApparatusChangeout $changeout, bool $withLines = false): array                 // section 6 shape
recordFromCheckout(Apparatus $unit, User $actor, string $inspectionSubmissionId, string $reportedNumber, string $reporterName, ?string $memberNote): array  // section 4.5, caller holds the transaction
// Refusals throw App\Services\Fleet\ChangeoutConflictException(code, message, array $context); the controller maps it to 409.

// App\Services\Fleet\VehicleSwapService
moveUnitForChangeout(Apparatus $from, Apparatus $to): array                            // caller holds the transaction and both locks

// App\Services\Fleet\ApparatusOperationalAlertService
create(array $data, ?User $actor): ApparatusOperationalAlert                           // $actor null only from the console
update(ApparatusOperationalAlert $alert, array $data, User $actor): ApparatusOperationalAlert
cancel(ApparatusOperationalAlert $alert, User $actor, ?string $note = null): ApparatusOperationalAlert
close(ApparatusOperationalAlert $alert, User $actor, ?string $note = null): ApparatusOperationalAlert
completeFromChangeout(ApparatusOperationalAlert $alert, ApparatusChangeout $changeout, ?User $actor): bool  // refuses a mismatched unit, idempotent
forStation(int $stationId, ?User $viewer): array                                       // section 6 `alerts`
activeForUnit(string $unitSlug): Collection
```

## 10. Rollback

Redeploy the previous image. Old code ignores the new columns. Identity moves and statuses already made remain true facts on the
apparatus rows and old code reads them normally. `2026_11_01_090000` only adds columns and drops the unused one-waiting-decision index;
its `down` restores that index. Never reverse a migration on production.
