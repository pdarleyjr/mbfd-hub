# Apparatus changeouts and operational alerts

Status: implementation contract, 2026-10-31. Read this with `docs/runbooks/apparatus-master-vehicle-link.md`,
`docs/daily-inspection-continuity.md` and `docs/apparatus-inventory-corrections.md`.

A firefighter should only need to know: what truck am I leaving, what truck am I moving to, what is coming with
me, what am I leaving behind, is it done. Everything else on this page is the system's job.

## 1. Rules that never bend

1. An `apparatuses` row is a physical truck. Vehicle number, VIN, meters, defects, inspections, service tickets and
   the master vehicle link never move. Only the unit identity moves: `unit_id`, `designation`, `name`, `slug`,
   `station_id`, `assignment`, `daily_checkout_requirement`, `daily_checkout_template`.
2. Vehicle numbers are strings. `002-16` is not `2-16`. Compare with `App\Support\Fleet\VehicleNumber::same`.
3. A swap does not fix a truck. Tickets and defects stay open on the old truck until their own workflow closes them.
4. A planned changeout is not a completed swap. Nothing about an apparatus changes until a changeout commits.
5. A checklist template is the expected list, not a custody record. There is no physical inventory store in the Hub.
   `apparatus_changeout_items` records what a named person said happened at a named time, and never edits templates,
   overlays, inspections or defects as a side effect.
6. Controlled items are never marked moved by default. They end `pending_verification` until a person with authority
   records the separate accountability step.
7. Every state change goes through a service with server-side authorization. UI code never writes these tables.
8. Old PWA clients keep working: only new routes and new optional response keys. Nothing here touches workers,
   manifest identity, or the checklist hash.

## 2. Records

| Table | Purpose |
|---|---|
| `apparatus_operational_alerts` | One authoritative alert about a unit. Projected onto station pages, the apparatus profile and Fleet. |
| `apparatus_changeouts` | One move of a unit from truck A to truck B, with status, audit and idempotency. |
| `apparatus_changeout_items` | The equipment handoff lines for one changeout. |
| `apparatus_fleet_events` | Append-only history for both of the above. |

`App\Models\ApparatusOperationalAlert`, `App\Models\ApparatusChangeout`, `App\Models\ApparatusChangeoutItem`,
`App\Models\ApparatusFleetEvent`.

### Alert fields that matter

`unit_slug` is the unit (the slug moves between truck rows with the identity, and is unique and never null).
`apparatus_id` and `vehicle_number` are the truck when the alert was raised and do not change.

Categories: `upcoming_swap`, `maintenance`, `recall`, `service_interruption`, `general`.
Status: `active` -> `completed` | `cancelled` | `closed`.
Schedule: `scheduled_date` (local date, no time zone), optional `scheduled_window` (`morning`, `afternoon`, `evening`),
optional `scheduled_at` (exact instant, only when Fleet actually gave one). A missing time stays missing.

An alert is visible on a station page when it is `active`, and its `station_id` or `additional_station_ids` holds that
station, and `ends_on` has not passed. An `upcoming_swap` alert does not expire by date: if the day passes it stays
up, and says the planned date has passed, until a changeout or Fleet closes it.

### Changeout status

| status | meaning |
|---|---|
| `completed` | The identity is on the new truck (or was already, see 4.4). |
| `pending_fleet` | Saved with all equipment choices; only the truck decision is waiting on Fleet. No apparatus changed. |
| `rejected` | Fleet said no. No apparatus changed. The record stays. |
| `cancelled` | The submitter or Fleet withdrew it before a decision. |

`handoff_status`: `complete` (every line settled) or `pending_verification` (one or more controlled lines or
`needs_review` lines are open).

### Item handling and custody

| handling | meaning | custody_status |
|---|---|---|
| `transfer` | Moved in full, or `quantity_moved` of it | `moved` |
| `leave` | Stays on the old truck | `left_behind` |
| `review` | Not moved, not certain | `needs_review` |
| any, on a controlled line | Separate accountability | `pending_verification` until verified, then `verified` |

`condition` is `ok` by default, or `missing` or `damaged`. Missing or damaged files an ordinary open defect on the
truck the item was on, through `DefectContinuityService::recordHandoffFinding`, linked by `apparatus_defect_id`. A
missing item is never `moved`.

### Where the equipment list comes from

`DailyCheckoutChecklistResolver::resolve($truck->refresh())`, effective checklist, checked for `usable`. Templates differ:
v1 compartments use `title`, v2 use `name`. Item ids may be positional, so use the ids the effective checklist reports.
Only some items have `expectedQuantity`: null stays null, and is never treated as 1. Partial quantities are only offered
when a quantity is known.

Templates have no controlled flag, so `config/fleet_changeouts.php` holds the name patterns: `narcotic`, `med box`.
Rescue officer fields that match (Narcotic Book, Narcotic Seal #) become controlled lines with keys `field:<id>`, since the
narcotics box is not a compartment item. High-value items (LifePak, LUCAS, TIC, gas meters, Toughbook) are ordinary lines: the
Hub has no serial record to verify them against, and does not pretend to.

### Known limit

`apparatus_inventory_changes` (approved checklist corrections) are keyed to the truck row and stay there, so a unit's
corrections do not follow it to the new truck. The move records the count in `move_audit.corrections_left_on_truck`
and Fleet sees it on the changeout page. On 2026-10-31 production has no such rows on a real unit.

## 3. Who can do what

| Action | Who |
|---|---|
| See alerts on a station page | Any signed-in member who can open the station page |
| Start a changeout, submit equipment choices | Active member with an employee profile, or a Fleet manager (same rule as `VehicleIdentificationController::authorizeUnit`) |
| Commit a changeout directly | The cases in 4.2 only |
| Decide a `pending_fleet` changeout | `User::canManageFleetSwaps()` |
| Verify a controlled line | `User::canManageFleetSwaps()`, or `Captain5Access::canAct` for rescue equipment |
| Create, edit, cancel or close alerts; schedule changeouts | `canManageFleetSwaps()` |
| View admin pages | `admin.fleet.view` through the admin capability gate |

Controlled line detail is shown to the member doing the handoff by name only. There is no quantity, location or
serial for controlled items in any member payload.

## 4. The move

### 4.1 Replacement truck choices

`eligible` means all of: has a vehicle number, is not the current truck, same `type` as the unit, status is `Available`
or `Reserve`, `daily_checkout_requirement` is `reserve`, no open service ticket, and no other unit has a pending or planned move onto it. A truck in
`Maintenance`, `Out of Service` or `In Service` is listed under other vehicles, which needs Fleet. A number that is not on
the list is accepted as text and validated by `VehicleNumber::isWellFormed`; it needs Fleet.

### 4.2 Direct commit versus Fleet decision

Direct, with no approval queue, when any of these is true:

- the submitter is a Fleet manager and the truck is known, same type and `reserve`;
- the unit has an active `upcoming_swap` alert that fits this move (below), the truck is eligible, and the alert's day has
  arrived in Eastern time (`scheduled_date`, or the date of `scheduled_at`; an alert with neither has no wait). Fleet
  authorized the changeout when it scheduled it. Before that day a member's submission waits for Fleet. There is no station
  tie: members float, and every move is audited;
- it is a return to the truck this unit last left (see section 5), and that truck is eligible, meaning Fleet has released it.

Anything else is `pending_fleet`: the work is saved, Fleet gets one alert through the existing "Vehicle swap reported"
category, and the crew sees that it is waiting.

A changeout never displaces a frontline unit. A truck whose requirement is not `reserve` refuses a direct commit and an
approval with `CHANGEOUT_TRUCK_UNAVAILABLE`, for Fleet too. Trades stay with the Daily Checkout swap report (`VehicleSwapService::decide`).

An alert attaches to a move, and completes from it, only when its `apparatus_id` is the truck being left and its planned
replacement is empty or is the destination. Otherwise a named `alert_id` is a 422, and one the server would have found by itself
simply does not attach.

### 4.3 What a commit writes, in one transaction

1. Lock the changeout's alert, then both truck rows in ascending id (the order `VehicleSwapService` uses). A retry that
   waited on these locks returns the first result.
2. Refuse if the submitted `expected_vehicle_number` or `expected_unit_slug` no longer holds the unit, or the replacement
   changed. A truck keeps its number after a move, so the slug is what proves the unit is still there. See 4.5.
3. `VehicleSwapService::moveUnitForChangeout`: trade identities. The new truck takes the unit's identity and location. The
   old truck takes the new truck's previous identity. Number-derived reserve identities are rewritten to the truck carrying
   them, whole numbers only (`R-1033` on truck 17503 becomes `R-17503`, `RESCUE 1033` becomes `RESCUE 17503`, `r1033` becomes
   `r17503`, `R-11033` stays), and `reserve-<id>` follows the row.
   A status describes a truck, so a failure never travels. The new truck keeps `Maintenance` or `Out of Service` if it has one;
   otherwise it takes the unit's status, and a unit leaving a failed truck is `In Service`. The old truck's status is Fleet's
   choice on approval, else the alert's `old_truck_status_after`, else `Maintenance` if it has an open service ticket, else the
   status the new truck had, with a failure there reading `Available`. Its station and location are where the swap happened,
   until Fleet moves it. The list the crew was working from stays accepted on the old truck for the checklist grace window, so
   a checkout queued on a phone still posts there.
4. Items and events.
5. If `alert_id` is set, the alert's `unit_slug` matches, and `handoff_status` is `complete`, complete the alert. A
   `pending_verification` handoff leaves the alert active and says what is left. Completing the last line closes it.
6. After commit: clear `DisplaySnapshotService` and station caches. Tickets and defects are not touched.

### 4.4 The unit already moved

If Fleet approved a Daily Checkout swap report first (`VehicleSwapService::decide`), the identity is already on the new
truck. An alert on that unit then offers **Record equipment handoff**, which creates a changeout with the existing
truck pair and no identity move, and completes the alert the same way. `decide` never closes an alert by itself.

### 4.5 Conflicts

| Situation | Answer |
|---|---|
| Same `client_submission_id`, same payload | 200 with the first result, `replayed: true` |
| Same `client_submission_id`, different payload | 409 `CHANGEOUT_REPLAY_CONFLICT` |
| Unit no longer on `expected_vehicle_number` | 409 `CHANGEOUT_STALE` with the current truck, and nothing written. The client keeps the member's work and offers to review. |
| Replacement no longer eligible | 409 `CHANGEOUT_TRUCK_UNAVAILABLE` with fresh choices |
| A `pending_fleet` row already exists for the unit | 409 `CHANGEOUT_ALREADY_PENDING` with its id and summary |

## 5. Going back

`previous_changeout` in the context response is the newest `completed` changeout where this unit left a truck, plus
what moved, what stayed, and open lines. When the member picks that truck, `return_of_changeout_id` is set by the server.
Defaults for a return: lines moved last time are `transfer` (they go back); lines left behind last time show as already
on the truck, as `leave`, with last time's note; lines that were `needs_review` or `pending_verification` start as `review`
and must be confirmed. The earlier record is never edited. Chains work because each return points only to the changeout it
reverses.

## 6. API (auth:sanctum, prefix `/api/public`, throttle 30/min on writes)

`GET stations/{station}/operational-alerts`

```json
{ "alerts": [ {
  "id": 12, "category": "upcoming_swap", "category_label": "Upcoming vehicle change",
  "title": "Upcoming Apparatus Changeout: Rescue 44", "message": "...", "reason": "Exhaust repair",
  "instructions": null, "unit_designation": "R 44", "vehicle_number": "17503",
  "current_vehicle_number": "17503", "current_apparatus_id": 52,
  "schedule_label": "Sunday, November 1, afternoon", "scheduled_date": "2026-11-01", "scheduled_window": "afternoon", "scheduled_at": null,
  "date_passed": false,
  "workflow": { "state": "planned|vehicle_changed|awaiting_fleet|awaiting_verification", "label": "Planned", "changeout_id": null },
  "action": { "kind": "start|handoff|view|none", "label": "Start Changeout", "apparatus_id": 52, "alert_id": 12, "changeout_id": null }
} ] }
```

`GET apparatuses/{apparatus}/changeout-context?alert_id=12`

```json
{
  "unit": { "apparatus_id": 52, "designation": "R 44", "vehicle_number": "17503", "station_id": 32, "station_label": "Station 4", "status": "In Service" },
  "alert": { "id": 12, "title": "...", "reason": "Exhaust repair", "schedule_label": "..." },
  "mode": "start|handoff_only",
  "candidates": [ { "apparatus_id": 57, "vehicle_number": "1033", "label": "Rescue 1033", "location": "Station 2", "status": "Available", "direct": true, "is_return": false } ],
  "other_vehicles": [ { "apparatus_id": 58, "vehicle_number": "1034", "label": "Rescue 1034", "location": "Station 2", "status": "In Service" } ],
  "equipment": { "checklist_version": "64 hex", "usable": true, "compartments": [ { "id": "front_cab", "name": "Front Cab", "items": [
      { "item_key": "front_cab-item-3", "name": "Portable radio", "expected_quantity": 2, "controlled": false, "default_handling": "transfer", "previous": null }
  ] } ] },
  "open_issues_on_truck": 3,
  "previous_changeout": { "id": 7, "from_vehicle_number": "17503", "to_vehicle_number": "1033", "effective_at": "2026-11-01T20:10:00Z", "moved": 40, "left_behind": [ { "name": "...", "note": "..." } ], "open": [ { "name": "...", "status": "needs_review" } ], "submitted_by_name": "..." },
  "pending_changeout": null,
  "can_fleet_commit": false
}
```

`POST apparatuses/{apparatus}/changeouts`

```json
{
  "client_submission_id": "uuid",
  "expected_vehicle_number": "17503",
  "expected_unit_slug": "r44",
  "alert_id": 12,
  "to_apparatus_id": 57,
  "to_vehicle_number": null,
  "checklist_version": "64 hex",
  "exceptions": [ { "item_key": "front_cab-item-3", "handling": "leave", "quantity": null, "condition": "ok", "note": "Spare stays with the truck" } ],
  "note": null
}
```

`expected_unit_slug` is required: it is `unit.slug` from the context. `checklist_version` is required whenever `exceptions` is
not empty. Every checklist line not named in `exceptions` is its default. `201` for a new record, `200` for a replay.

```json
{ "changeout": { "id": 8, "status": "completed|pending_fleet", "handoff_status": "complete|pending_verification",
  "unit_designation": "R 44", "from_vehicle_number": "17503", "to_vehicle_number": "1033", "effective_at": "...",
  "moved": 40, "left_behind": 1, "open": 0, "alert_completed": true, "message": "R 44 is now on truck 1033." }, "replayed": false }
```

`GET changeouts/{changeout}` returns the same summary with lines, to the submitter, members of the unit's station page, and Fleet.
`POST changeouts/{changeout}/lines/{line}/verify` `{ "note": "..." }` for those with authority (see section 3).

Errors follow the Hub shape, `{ "message": "...", "code": "CHANGEOUT_STALE", ... }`, and messages say what happened, that the
work is safe, and what to do next.

## 7. Notifications

Alert creation can notify through `NotificationCategoryRegistry` key `fleet_operational_alerts` (bell by default, phone off,
eligible: active members), only when `send_notification` is on and only once (`notified_at`). A `pending_fleet` changeout
uses the existing `vehicle_swap_reports` category and `NotificationDispatchService`. There is no new preference store.

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
context(Apparatus $unit, User $actor, ?int $alertId = null): array                     // section 6 shape

// App\Services\Fleet\ApparatusChangeoutService
submit(Apparatus $unit, User $actor, array $payload): array                            // ['changeout' => ApparatusChangeout, 'created' => bool, 'replayed' => bool]
decide(int $changeoutId, User $actor, string $decision, ?string $note = null,          // 'approve' | 'reject'
       ?int $mappedApparatusId = null, ?string $oldTruckStatus = null): ApparatusChangeout
cancel(int $changeoutId, User $actor, ?string $note = null): ApparatusChangeout
verifyLine(int $changeoutId, int $lineId, User $actor, ?string $note = null): ApparatusChangeout
canVerify(User $user, ApparatusChangeout $changeout): bool
summary(ApparatusChangeout $changeout, ?User $viewer = null, bool $withLines = false): array  // section 6 shape
// Refusals throw App\Services\Fleet\ChangeoutConflictException(code, message, array $context); the controller maps it to 409.

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

Redeploy the previous image. Old code ignores the new tables. Identity moves already made remain true facts on the apparatus
rows and old code reads them normally. Never reverse the migration on production.
