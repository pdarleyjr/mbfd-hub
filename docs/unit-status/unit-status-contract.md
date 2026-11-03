# Unit Status (`/unit-status`) — Hub contract v1

**Status:** v1, 2026-11-02. Source feed: mbfd-command `docs/UNIT_STATUS_SOURCE_FEED_CONTRACT.md` (Command owns PulsePoint
calls; the Hub owns navigation, roster, counting and the page). Spec: owner directive "MBFD Hub — Unit Status / CAD-style
live apparatus board".

A read-only member page listing all 16 apparatus under Stations 1, 2, 3, 4 and 6, each with its PulsePoint call status,
current call, runs this duty shift, lifetime runs since tracking began, and the crew on the truck from the published
TeleStaff roster. **PulsePoint is advisory, not dispatch.** The page never claims otherwise, never invents a status, a
name or a count, and never shows green "Available" unless the facts in section 5 all hold.

## 1. Units and order (config `unit_status.stations`, `App\Support\UnitStatus\UnitRegistry`)

| Station | Code | Name | PulsePoint designation | TeleStaff `unit_label` |
| --- | --- | --- | --- | --- |
| 1 | L1 | Ladder 1 | L1 | Ladder 1 |
| 1 | E1 | Engine 1 | E1 | Engine 1 |
| 1 | R1 | Rescue 1 | R1 | Rescue 1 |
| 1 | R11 | Rescue 11 | R11 | Rescue 11 |
| 2 | E2 | Engine 2 | E2 | Engine 2 |
| 2 | R2 | Rescue 2 | R2 | Rescue 2 |
| 2 | R22 | Rescue 22 | R22 | Rescue 22 |
| 2 | CPT5 | Captain 5 | CPT5 | Captain 5 |
| 2 | 300 | 300 | 300 | Division Chief 300 |
| 3 | L3 | Ladder 3 | L3 | Ladder 3 |
| 3 | E3 | Engine 3 | E3 | Engine 3 |
| 3 | R3 | Rescue 3 | R3 | Rescue 3 |
| 4 | E4 | Engine 4 | E4 | Engine 4 |
| 4 | R4 | Rescue 4 | R4 | Rescue 4 |
| 4 | R44 | Rescue 44 | R44 | Rescue 44 |
| 6 | FB6 | Fireboat 6 | FB6 | Fire Boat 6 |

Exactly 16 rows, always, in this order, even when the feed or roster is empty. No Station 5.

Matching is **exact after normalization**, never substring or prefix: PulsePoint designation → uppercase, drop one leading
`?`, drop every character outside `A-Z0-9` (so `?r 1` → `R1`), then compare to the designation list. A leading `?` marks
the observation **unconfirmed**. `R1` never matches `R11`, `R2` never `R22`, `R4` never `R44`, `E1` never `L1`, `CPT5`
never `300`. TeleStaff `unit_label` → trim, collapse whitespace, case-insensitive exact compare. Other designations
(`CMD1`, `G1`, `810`, `R6`, ...) and sections (`Air Truck 810`, floats, pools) are ignored. Aliases are config, not fleet
master data; `Apparatus`/`Station` rows are never edited for this feature.

## 2. Source sync (`php artisan unit-status:sync`, scheduled every minute, `withoutOverlapping`, `onOneServer`)

Every minute, not every 30 s: production runs the scheduler as a `schedule:run; sleep 60` loop, and a sub-minute
task would hold `schedule:run` to the end of each minute so the loop skipped every other minute for all jobs. The page
itself refreshes every 30 s, and the feed counts as fresh for 150 s (one missed run tolerated).

* `App\Services\UnitStatus\CommandSourceFeedClient` calls the private Command feed at `config('unit_status.source.url')`
  (default `http://cmd-api:8200`, reachable only on the Docker network) with the dedicated read token
  `UNIT_STATUS_SOURCE_TOKEN`. `http` is allowed only for hosts listed in `unit_status.source.private_hosts`
  (default `['cmd-api']`); any other host must be `https`. Connect timeout 3 s, total 8 s, no redirects, no retries
  inside one run. Body never logged. Unknown `schemaVersion` = unavailable. Each call row is validated on its own;
  a malformed row is skipped and counted in the sync state, never fatal.
* **Circuit breaker:** after 3 consecutive failures the job skips the network for `min(30 s × 2^(n-3), 300 s)`. The
  page then shows the feed as unreachable with the last good time; cached data stays visible but marked stale.
* **Cursor:** delta mode from `(cursor_updated_since − 120 s overlap)`; first run starts at `tracking_starts_at − 12 h`
  (to pick up calls received before tracking began that were still running). Pages until `hasMore=false`, at most
  10 pages per run. Every row is applied idempotently (upsert by `source_call_id`).
* **Merge housekeeping:** every 5 minutes, look up (lookup mode, ≤100 ids) calls that are still provisional
  (`external_id` null) and were last seen in the past 24 h. A `missing` id is a provisional copy Command folded into
  another call: set `merged_at` and void its runs (`void_reason = merged`). A row with `duplicateOf` is never credited
  and any credit it had is voided (`void_reason = duplicate`). When two Hub call rows carry the same `external_id`, the
  older-created one is treated as merged.
* **Coverage gaps:** each successful sync records the feed `health.state`. A period where it is not `live` opens a row in
  `unit_status_coverage_gaps` (`reason = feed_<state>`), closed when `live` returns. A Hub→Command outage is **not** a
  gap (Command keeps every call for 90 days and the Hub catches up), but it is reported on the page as delayed while it
  lasts.

## 3. Tables (additive migrations only)

* `unit_status_calls` — one row per Command source call: `source_call_id` (PK), `external_id` (index), `duplicate_of`,
  `call_type`, `address`, `lat`, `lng`, `location_quality`, `received_at`, `section`, `source_status`, `closed_at`,
  `disappeared_at`, `first_seen_at`, `last_seen_at`, `source_updated_at`, `source_url`, `merged_at`, `synced_at`,
  timestamps.
* `unit_status_call_units` — our units on a call: `source_call_id`, `unit_code`, `designation` (as PulsePoint shows it),
  `status`, `confirmed` (bool: seen at least once without `?`), `first_seen_on_call_at`, `status_observed_at`,
  timestamps; unique `(source_call_id, unit_code)`.
* `unit_status_runs` — the **credit ledger**: `unit_code`, `source_call_id`, `external_id`, `attributed_at`,
  `attribution_basis` (`received` | `first_seen` | `unit_added`), `duty_date` (date), `confirmed` (bool), `provenance`
  (`live` | `backfill`), `voided_at`, `void_reason`, timestamps; **unique `(unit_code, source_call_id)`**. Never deleted.
* `unit_status_sync_state` — single row (`id = 1`): `tracking_starts_at` (written once, never reset), `first_synced_at`,
  `cursor_updated_since`, `cursor_after_id`, `last_attempt_at`, `last_success_at`, `last_error_class`,
  `consecutive_failures`, `circuit_open_until`, `source_state`, `source_health` (json), `source_checked_at`,
  `last_merge_check_at`, `rows_skipped_total`.
* `unit_status_coverage_gaps` — `started_at`, `ended_at` (null while open), `reason`.

## 4. Counting ("Runs")

**Definition shown in every profile:** "A run is a PulsePoint call that listed this unit (an observed assignment). It is
not proof the unit responded. Each call counts once per unit; status changes, repeat updates and page refreshes never
add runs."

* Credit is created the first time a non-duplicate, non-merged call lists the unit (any status, including blank).
* `attributed_at`: when the call is new to the Hub, `received_at` (basis `received`) or, if PulsePoint gave none,
  `first_seen_at` (basis `first_seen`). When the Hub already had the call and the unit appears later, the call's
  `last_seen_at` from that delivery (basis `unit_added`). Later updates never move it.
* `duty_date = DutyCalendar::dutyDate(attributed_at)`; the shift count is runs whose `duty_date` is the current duty
  date (08:00–08:00 America/New_York, DST-aware via `DutyCalendar::window()`; never "now − 24 h").
* Calls attributed before `tracking_starts_at` get **no** credit. `provenance = backfill` when `attributed_at` is before
  `first_synced_at` (read from Command's stored record), else `live`.
* `confirmed = false` while the unit has only ever appeared with `?`; it flips to true (never back) once seen without.
  The headline numbers count **confirmed, non-voided** runs; unconfirmed ones are shown separately in the profile.
* `tracking_starts_at` comes from `UNIT_STATUS_TRACKING_STARTS_AT` (default `2026-11-02 08:00` America/New_York), is
  stored on first sync and is then fixed: deploys, restarts, outages and calendar rollovers never reset a count.
* A unit listed twice on one call counts once (unique key). Each of our units on a multi-unit call is credited
  separately.

## 5. Status and availability (per unit, evaluated at request time)

Feed is **fresh** when the last successful sync is ≤ 150 s old **and** `source_state = live`.

1. **Current calls** = rows in `unit_status_call_units` whose call is `section = active`, `closed_at`,
   `disappeared_at`, `merged_at`, `duplicate_of` all null, and whose unit `status` is not `Cleared From Incident`.
   Ordered by `received_at` desc, then `source_call_id` (deterministic). More than one → `conflict = true` and every
   call is shown; the row shows the newest one with "+1 more call".
2. With a current call: `availability.key = on_call`, label = the unit's PulsePoint status word
   (blank → "Assigned"), `confirmed` from the unit row, `stale = !fresh`.
3. No current call and fresh feed:
   * roster published for today and the unit has working crew now → **`available`** ("Available", green).
   * roster published for today, unit listed, no working crew now → `not_staffed` ("Not staffed", neutral).
   * roster not current for today, or unit section missing → `no_active_call` ("No active call", neutral), with
     note "Crew not confirmed".
4. Not fresh → `unknown` ("Not confirmed", warning) with note "PulsePoint feed delayed" / "unavailable" / "Hub cannot
   reach the feed", plus the last good time. Never green.

Status families (text always shown; the PulsePoint color is an indicator only, never the text color):

| PulsePoint word | family | indicator color |
| --- | --- | --- |
| Dispatched, Acknowledged | dispatched | rgb(245,124,0) |
| Enroute | enroute | rgb(0,204,0) |
| Staged, On Scene, Available On Scene | on_scene | rgb(204,0,0) |
| Transport | transport | rgb(255,193,7) |
| Transport Arrived | arrived | rgb(25,118,210) |
| Cleared From Incident | cleared | rgb(73,73,73) |
| blank or other | unknown | none |

## 6. Crew (TeleStaff)

* Only through `CurrentRosterReader::current($now)` and `RosterFreshnessResolver::status($now)`. No workbook or email
  is read. No fallback to yesterday's roster.
* Crew on the truck now = assignments of the unit's section with `kind = employee`, `availability = working`, and
  `starts_at ≤ now < ends_at` (a null bound is open). One entry per person (dedupe by employee id internally; the id is
  **never** output). Shown as `rank` (`rank_label` ?? `seat_rank`) + `display_name`. Later reliefs this shift are
  listed in the profile with their times. Open vacancies count as "Vacancy". Leave, annotation, unknown and suppressed
  rows are never shown, nor are codes, categories, review reasons or certifications.
* Roster state `current` → crew shown with "as of"; `delayed` with today's snapshot → crew shown, marked "Roster
  delayed" with last update; no snapshot for today → no names, "Roster not posted yet — personnel not confirmed".

## 7. Access, routes, caching

* Page access = `App\Services\Security\DailyRosterAccess::canView($user)` (names are roster data). Feature flag
  `UNIT_STATUS_ENABLED` (default false): off → routes answer 404 and the menu entry is hidden.
* `GET /unit-status` (`unit-status.show`): `[CanonicalHostRedirect, auth:web]`, full page.
* `GET /unit-status/board` (`unit-status.board`): `[auth:web, throttle:60,1]`, the board HTML fragment for refresh,
  with `ETag` / `If-None-Match` → 304.
* `GET /unit-status/data` (`unit-status.data`): `[auth:web, throttle:60,1]`, the JSON in section 8, with ETag.
* `GET /unit-status/units/{code}/runs?page=N` (`unit-status.runs`): `[auth:web, throttle:60,1]`, recent run history,
  10 per page, newest first; unknown code → 404.
* Every response: `Cache-Control: no-store, private`; unauthorized → 403 with the same header. Service workers must
  not cache these paths (they are not in any allowlist; verify).
* Menu: one entry "Unit Status" in `HubShellNavigation` `moreNavigation`, next to Daily Roster, gated by flag + access.

## 8. View model (`App\Services\UnitStatus\UnitStatusBoard::build(?CarbonInterface $now): array`)

One builder serves the page, the fragment and the JSON so the numbers never diverge.

```text
{
  schemaVersion: 1,
  generatedAt: ISO,
  etag: string,                      // sha1 of the payload without generatedAt
  live: bool,                        // fresh feed AND roster current: only then is the "24-Hour Live Availability" subtitle shown
  advisory: "PulsePoint is advisory. Verify with dispatch.",
  source: { state: live|delayed|unavailable|starting|unreachable|not_configured, label, message,
            lastSourceReadAt: ISO|null, lastSyncAt: ISO|null, fresh: bool },
  roster: { state: current|delayed|awaiting|none|needs_review, label, message, dutyDate, shift: 'A'|'B'|'C'|null, asOf: ISO|null },
  dutyWindow: { dutyDate: 'Y-m-d', startsAt: ISO, endsAt: ISO, label: 'Nov 2, 8:00 AM – Nov 3, 8:00 AM ET', shift },
  tracking: { startsAt: ISO, startsLabel, firstSyncedAt: ISO|null, definition: string,
              openGap: bool, gaps: [{ startedAt, endedAt|null, reason, label }] },   // last 10
  stations: [ { number: 1, label: 'Station 1', units: [Unit, ...] }, ... ]           // 5 stations, 16 units
}

Unit {
  code, name, station, domId: 'unit-r1',
  availability: { key: available|on_call|no_active_call|not_staffed|unknown, label, family, tone: success|source|neutral|warning,
                  indicatorColor: 'rgb(..)'|null, confirmed: bool, stale: bool, note: string|null, observedAt: ISO|null },
  assignmentSummary: string,          // e.g. "Medical Emergency – 1230 Ocean Ave" or "No active call" / "Not confirmed"
  conflict: bool,
  currentCalls: [ { sourceCallId, externalId|null, idLabel: '#2601241652'|'ID pending', callType, address,
                    receivedAt|null, receivedLabel: '4:58 PM ET'|null, unitStatus, unitFamily, indicatorColor|null,
                    unitConfirmed: bool, sourceStatus, lastSeenAt, lastSeenLabel, sourceUrl|null,
                    location: { lat, lng, quality: 'approximate' }|null,
                    otherUnits: [ { designation, status } ] } ],
  runs: { shift: int, shiftUnconfirmed: int, lifetime: int, lifetimeUnconfirmed: int, backfilledLifetime: int },
  crew: { state: confirmed|delayed|not_posted|not_listed|none_working|hidden, label,
          onTruck: [ { rank|null, name } ], later: [ { rank|null, name, from: '19:00', through: '08:00' } ],
          vacancies: int, asOf: ISO|null, note: string|null }
}
```

All timestamps ISO-8601 UTC; labels formatted in America/New_York with "ET". Text is plain; Blade escapes it.

## 9. UI (resources/views/unit-status/*, resources/css/unit-status.css, resources/js/unit-status.ts)

* Full Hub page like `daily-roster/show.blade.php`: `<x-hub.head />`, `<x-hub.shell module="Unit Status" ...>`,
  Plus Jakarta Sans, tokens from `resources/css/mbfd-theme.css` only (`rgb(var(--hub-header))` etc.), no new palette.
* Heading "Unit Status"; subtitle "24-Hour Live Availability" only when `live`; otherwise a quiet status line
  ("Feed delayed — last update 4:58 PM ET"). One persistent short line: "PulsePoint is advisory. Verify with dispatch."
* Desktop (≥ 900 px): one white table, columns Unit | Availability / Status | Current Assignment / Call Status |
  This Shift (header "24h Runs" with visually-hidden "since 8:00 AM") | Personnel on Truck (This Shift) | Profile.
  Full-width medium-gray station header rows (`--hub-surface-muted`/`--hub-n-200` band, bold label); unit rows white with
  1 px soft dividers; no colored row backgrounds.
* Each row: a disclosure button (chevron + unit code, `aria-expanded`, `aria-controls`) expands an inline profile
  panel; "View profile" opens a native `<dialog>` with the full profile (same Blade partial, `variant=full`), focus
  returns to the trigger on close, Escape closes, no nested modal.
* Status chip: `available` = success token chip; source statuses = white chip, dark ink text, 8 px dot / left border in
  the PulsePoint color; `unknown` = warning token chip; neutral = neutral chip. Unconfirmed adds "(unconfirmed)".
* < 900 px: rows become grouped cards under station headers (same order and content, no horizontal scroll), 44 px
  touch targets, visible focus, `prefers-reduced-motion` respected.
* Refresh: `fetch('/unit-status/board', {cache:'no-store'})` with `If-None-Match`, every 30 s while visible, paused when
  hidden (`visibilitychange`), immediate refresh on return, `AbortController` per request, exponential backoff on
  error (max 5 min). Swap the fragment while preserving which rows are expanded and keeping an open dialog open
  (re-render its content). Never reload the page; no service-worker changes.
* Profile (full): designator, name, station, status + last verified time; each current call (ID or "ID pending",
  type, received ET, address, unit phase, last seen, "Open in PulsePoint" link with `rel="noopener noreferrer"`,
  "Approximate location" only when present); Calls this shift; Lifetime since {startsLabel}; counting definition;
  unconfirmed counts; coverage gaps; crew now + later reliefs + vacancies + roster as-of; recent runs (loaded from
  `/unit-status/units/{code}/runs`, "Show more").
