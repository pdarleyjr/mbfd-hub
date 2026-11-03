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
  10 pages per run and at most `run_budget_seconds` (20 s). Every row is applied idempotently (upsert by `source_call_id`).
  **Backlog:** a run that stops with `hasMore=true` (page cap or time budget) stores `backlog = true`; the next run then
  resumes exactly at `(cursor_updated_since, cursor_after_id)` with **no** overlap (otherwise more than 2,000 rows inside
  one overlap window would be re-read forever). The cursor only moves forward by `(updatedSince, afterId)`. While a
  backlog remains the feed is not fresh: the page reports it as delayed, "Catching up with PulsePoint calls".
* **Merge housekeeping:** every 5 minutes, and immediately at the end of any run in which a delta gave a
  previously provisional call its real `external_id`, look up (lookup mode, ≤100 ids per request, paged) calls that are
  still provisional (`external_id` null) and either were last seen in the past 24 h **or are still current** (section
  `active`, not closed or disappeared) however old, so a copy Command folded away while the Hub could not read the
  feed is still retired. A `missing` id is a provisional copy Command folded into
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
* A unit added to a call while the Hub could not read the feed across 08:00 is credited at the delivery time, so it can
  land on the next duty date; this is rare and logged as basis `unit_added`.

## 5. Status and availability (per unit, evaluated at request time)

Feed is **fresh** when the last successful sync is ≤ 150 s old **and** `source_state = live`.

1. **Current calls** = rows in `unit_status_call_units` whose call is `section = active`, `closed_at`,
   `disappeared_at`, `merged_at`, `duplicate_of` all null, and whose unit `status` is not `Cleared From Incident`.
   **Age limit:** a call whose `COALESCE(received_at, first_seen_at)` is older than `current_call_max_age_hours` (12) is
   not current. A unit whose only open calls are aged out shows `unknown` / "Not confirmed" with the note "Call open
   more than 12 hours in PulsePoint", never `available`.
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
* Board, data and runs use `SignedOutBackgroundRefresh:web` in place of `auth:web`: a signed-out request carrying
  `X-Hub-Background-Refresh: 1` gets `401 {"error":"signed_out"}` (no-store, private) instead of the login redirect;
  every other signed-out request is handed to the normal `auth` middleware and redirects as before.
* The built board is cached for `board_cache_seconds` (10 s), keyed on the last successful sync, feed state, backlog,
  roster snapshot and a 10-second clock bucket; the ETag is part of the cached payload.
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
                  indicatorColor: 'rgb(..)'|null, confirmed: bool|null, stale: bool, note: string|null, observedAt: ISO|null },
                  // confirmed is null whenever there is no call (available, not_staffed, no_active_call, unknown); only a unit
                  // on a call has true/false, and the UI appends "(unconfirmed)" only for false
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

## 10. v1.1 changes (owner direction, 2026-11-02 evening)

### 10.1 Stuck and phantom assignments (automatic; nothing to clear by hand)

Observed: PulsePoint left a Water Rescue (received 2:16 PM) "active" for hours with six units marked `?` (not confirmed)
at Dispatched, although every one of them had cleared. The owner confirmed none were on it. A `?` unit can also be a
real dispatch for a few seconds (`?E4` became `E4` six seconds later). Rules, applied when deciding a unit's current
calls (they never change Command's data):

1. **Unconfirmed units are never shown.** A `?` designation never places a unit on a call, never shows a status and
   never earns a run (`unit_status.unconfirmed_grace_minutes`, default 0). Evidence (Command history, Oct 31-Nov 2):
   every orange "Dispatched" PulsePoint ever reported belonged to a `?` unit (7 of 7); no confirmed unit was ever
   reported Dispatched, and none went backwards in 178 status histories. The collector reads list colors, and
   PulsePoint draws `?` units orange, which the collector names "Dispatched". A real dispatch is confirmed within
   seconds (`?E4` became `E4` six seconds later), so the unit appears then.
2. **Stalled dispatch.** A confirmed unit whose status is still Dispatched, Acknowledged or blank on a call received more
   than `unit_status.stalled_dispatch_minutes` (10) ago is not on that call. A unit that really responds shows Enroute
   (green) or later within minutes.
3. **Superseded.** A unit listed on two current calls is on the newer one only (received time, then first seen).
4. The 12-hour age limit stays as it is.

A unit whose calls all drop out under rules 1-3 gets its normal availability (Available when the feed is fresh, the
roster is current and it has crew), because PulsePoint itself shows nothing more about it. **Unconfirmed runs are never
counted or shown** anywhere (no "+1 unconfirmed").

### 10.2 One compact row on every device; details in one pop-up card

Each unit row shows only: unit (code, name), status chip, and when on a call its PulsePoint incident ID (`#2601372003`,
or "ID pending"). The whole row opens the unit's pop-up card (the single `<dialog>`); there is no inline expansion. The
pop-up card shows: status and last verified time; runs this shift; the current call (ID, type, address, received ET,
unit status, Open in PulsePoint); the calls the unit ran this shift (time, type, ID, its last status); its busiest day
(most runs in one duty day, with the date); all-time runs since tracking began; and today's crew in short form.

### 10.3 Short crew names (pop-up)

`{rank abbreviation} {surname}`: surname is the text before the comma of TeleStaff's "Last, First" name (so "Yacum Jr"
stays whole); a leading "." on a rank is dropped. Abbreviations: Firefighter → FF; Firefighter DE → DE; Lieutenant and
Combat Lieutenant → Lt.; Captain, Combat Captain, Captain PM and Marine Captain → Capt.; Division Chief → DC; Battalion
Chief → BC; any other rank is shown as written. No rank → surname only.

### 10.4 Shift totals at the top

Four small cards for the current duty shift (08:00-08:00), confirmed non-voided runs only:
* **Runs this shift**: rescue + combat.
* **Rescue**: runs by R1, R11, R2, R22, R3, R4, R44, plus CPT5, 300 and FB6 runs on medical calls.
* **Combat**: runs by E1, E2, E3, E4, L1, L3, plus CPT5, 300 and FB6 runs on any other call.
  "Medical" = the call type matches Command's medical rule (`medical|medic|ems|cardiac|breathing|overdose|stroke|
  seizure|unconscious|injury|sick|expedite|interfacility`, case-insensitive).
* **Busiest unit**: the rescue or combat unit (any of the 16) with the most runs this shift, with its count; ties list
  every tied unit; none yet → "No runs yet this shift".

A small note near the cards: "Counts began Nov 2, 2026 at 8:00 AM and keep adding up from there."

### 10.5 View-model additions

`board.totals = { total, rescue, combat, busiest: { codes: [..], runs } | null }`, `board.tracking.note`;
per unit `incidentLabel` (primary current call's `idLabel` or null) and `profile = { shiftRuns, lifetimeRuns,
busiestDay: { runs, date, label } | null, todayCalls: [ { attributedAt, timeLabel, callType, idLabel, externalId,
unitStatus, current, sourceUrl } ] }`; crew entries gain `short` ("Lt. Benson"). Unconfirmed counters are removed from
the payload.

### 10.6 Professional status wording (owner direction)

The board never shows PulsePoint's raw words or the word "unconfirmed". Display labels (family and indicator color
unchanged):

| PulsePoint | Board label |
| --- | --- |
| Dispatched, Acknowledged | Dispatched |
| Enroute | En Route |
| Staged | Staged |
| On Scene, Available On Scene | On Scene |
| Transport | Transporting |
| Transport Arrived | At Hospital |
| Cleared From Incident | (not on the call) |
| blank, confirmed unit, within 10 minutes | Assigned |
| no current call | Available / No active call / Not staffed / Not confirmed (section 5) |

Guarantees against a repeat of the "Dispatched (unconfirmed)" display: `?` units are removed server-side before any
status is built (10.1); the label table above is the only source of status text; the views have no unconfirmed suffix;
and a regression test renders the page, the fragment and the JSON from a feed containing `?` units and fails if
"unconfirmed" (any case) or a `?`-prefixed designation appears anywhere in them. The pop-up's "other units" list shows
only confirmed units.
