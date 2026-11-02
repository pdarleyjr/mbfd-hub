# Supply Data: provenance audit

Status: written with the first implementation of Supply Data (2026-11-02). It records, for every figure, which tables, columns,
movement types and statuses it reads and why, how reversals and corrections net out, and the defects found in how historical
data is handled. **Nothing here changed any data or any existing service.** The calculation lives in
`app/Services/Ems/SupplyData/` and is the only place a Supply Data figure is computed.

Read this first: every figure is "distributed from the supply room", not "consumed". A stock movement says units left the room for
a unit. Nothing in the data says a crew used them on a patient.

## 1. Where each metric comes from

| Metric | Tables and columns | Rows that qualify | Why |
|---|---|---|---|
| `distributed` (per item, per unit) | `ems_stock_movements` (`supply_item_id`, `movement_type`, `quantity_base`, `request_id`, `corrects_movement_id`, `occurred_at`) joined to `ems_inventory_requests` (`apparatus_id`, `target_kind`, `target_label`) and `ems_supply_items` (`controlled_substance`) | `request_id` is set and `movement_type` is `issued` or `adjusted`; summed per (request, item); the pair counts when the sum is below zero (stock left) | The ledger is the record of stock leaving the room. `issued` is the deduction (negative). `adjusted` with a request id is only ever written by a correction (`RequestWorkflowService::correctOne`, links at line 703). The same rule is how `StockLedger::deductionsSinceCount` (lines 227-246) decides what is a deduction, so the two agree. |
| `distribution_events` | same query | the number of (request, item) pairs above, by effective time | Different items have different units, so ranking uses events, not quantities. |
| `requested` / `qty_requested` | `ems_inventory_request_lines` (`requested_qty`, `response_type`, `catalog_item_id`) of counted requests | `response_type = quantity`, `requested_qty > 0`, catalog item not controlled | A Yes/No answer (the narcotics box) is not a quantity. Same rule as `EmsStatistics` (`wantedQty()`). |
| `qty_recorded_issued` (fulfillment block only) | the same lines' `issued_qty` | same lines | A request-side number in the request's own units, so it can sit beside `qty_requested`. It is **not** the ledger figure (see finding F11). |
| `received` | `ems_stock_movements` | `movement_type = received` | Shown on the item page's replenishment list only. Never part of distributed. |
| `adjusted` (manual) | `ems_stock_movements` | `movement_type = adjusted` and `request_id IS NULL` | Written by `SupplyOrderService` (line 123). Never request-linked, so it can never be mistaken for a reversal. |
| `on_hand`, `basis`, `deductions_since_count` | `StockLedger::current()`, `lastCounts()`, `deductionsSinceCount()` | the ledger's own rule | Never recomputed. The only addition is the snapshot's `origin` (one query) to label an estimate. |
| `last_counted_at` | `StockLedger::lastCounts()` | newest `counted` line, only when its snapshot `origin` is not `estimate` | An estimate is not a count (F1). |
| `minimum`, `order_level` | `ems_supply_items.threshold_qty_base` in count units | the one stored order threshold | See section 6. |
| `needs_reorder`, `recommended_order` | `ReorderAdvisor::advise()` | an advisory exists for the item | Reused as is. `recommended_order` is the advisor's `suggested_packages`; unit and basis come with it. The legacy ORDER AMOUNT text is the advisor's own input and is not treated as policy here. |
| `variance` | `ems_inventory_snapshot_lines` (`quantity_base`, `value_source`, `through_movement_id`), `ems_inventory_snapshots` (`kind`, `origin`, `status`, `version`, `through_movement_id`, `finalized_at`), `ems_stock_movements` | see section 5 | Contract section 2. |
| `requests_submitted`, `acknowledged`, `reconciled`, `open`, `rejected`, `corrected`, `median_hours_to_reconcile` | `ems_inventory_requests` (`status`, `submitted_at`, `reviewed_at`, `returned_at`, `reconciled_at`, `revision_number`) | see section 3 | Cohort: requests submitted in the period. |
| `top_requested` (unit page) | request lines of counted requests | grouped by `catalog_item_id` and unit of measure; a line with no catalog identity stays separate per `bag_key` + `item_key` | Names are never merged. |

Controlled substances are excluded everywhere: the movement query joins `ems_supply_items.controlled_substance = false`, stock and
counts read only active non-controlled items, request lines join `ems_catalog_items.controlled_substance`, and the controller
answers 404 for a controlled item id (`StockLedger::record` already refuses to write one).

## 2. Reversals and corrections

- A correction (`RequestWorkflowService::correctOne`, lines 681-740) writes a **new** row and never edits the old one. For a quantity
  change it writes `adjusted` (`quantity_base` = units put back, positive; or more units taken, negative) with `request_id`,
  `request_line_id` and `corrects_movement_id` = the issuance it offsets. For a wrong item it writes `adjusted` on the old item and
  a new `issued` on the right item, both with `corrects_movement_id` set. So **`issued` rows can also carry `corrects_movement_id`**:
  that is a replacement issuance, not a reversal, and it counts as a real distribution for its own item.
- Supply Data therefore never uses `corrects_movement_id` to decide *whether* a row counts. It nets everything request-linked per
  (request, item) with one SUM. A fully reversed issuance sums to zero and is not an event; a partly corrected one keeps its
  net; a wrong-item correction moves the units from one item's pair to the other's.
- **Dating.** A correction is dated by the issuance it corrects (`COALESCE(corrected.occurred_at, own occurred_at)` for `adjusted`
  rows). Reversing last week's issuance this week therefore nets last week to zero instead of making this week negative. Test:
  `test_a_correction_in_a_later_period_does_not_change_the_period_of_the_issuance`.
- **Production shape (MIR-000001).** `php artisan ems:return-forms-to-captain5` (`RequestWorkflowService::returnToForms`, lines 387-415)
  corrects every issuance of an older-workflow form to zero. On production that left 7 `issued` rows (sum -38) and 7 `adjusted` rows
  (sum +38) that point at them, all with the request id, and the form back in `reviewed`. Net distributed is 0 and
  `distribution_events` is 0; the request still counts as submitted. Test: `test_a_reversed_issuance_nets_to_zero_like_mir_000001_on_production`.
- **Idempotency keys** only matter for replays: a reconciled line's movement key is `reconcile:r{request}:l{line}`
  (`RequestWorkflowService.php:472`), tied to no user and no client key, so a line deducts at most once and cannot appear twice in
  the ledger. Correction keys include the actor and the client key (`movementKey`, lines 341-346). Supply Data reads rows and
  never needs the keys; it relies on the unique `idempotency_key` column to know no row is duplicated.
- Movements linked to a request that is cancelled, sent back or replaced would still be counted in distributed (the stock did
  leave). They are surfaced as `data_quality.movements_on_uncounted_requests` (expected 0). Issuances with no request are
  `data_quality.unattributed_movements` (expected 0) and are never attributed to a unit.

## 3. Request lifecycle: which statuses count, and no request twice

Statuses (`InventoryRequest`): `submitted`, `reviewed`, `prepared`, `partially_issued`, `issued`, `closed`, `cancelled`, `returned`,
`reconciled`, `superseded`.

- **Counted** = every status except `cancelled`, `returned`, `superseded` (same rule as `EmsStatistics.php:56`). Those three are
  exactly the states in which the same supplies would be asked for twice or not at all: a member's correction is a **new row**
  (`revision_of_request_id`, `revision_number`, `MIR-n-R2`) and the row it replaces becomes `superseded` in the same transaction
  (`RequestSubmissionService.php:319`), so a chain of corrections contributes one request, the live revision. A request the member
  cancelled (only possible before anything was issued) or that Captain 5 sent back and is still waiting contributes none.
- **Cohort.** Request metrics use the requests *submitted* in the period (`submitted_at`, Eastern day bounds). A request submitted on
  Oct 30 and reconciled Nov 2 belongs to Oct 30's period for every request metric. Distribution is dated by the stock movement.
- `fulfillment.submitted` = counted. `acknowledged` = counted with `reviewed_at` (reject clears it, `RequestWorkflowService.php:109`).
  `reconciled` = `status = reconciled`. `open` = `submitted`, `reviewed`, `prepared`. `completed_other_workflow` = the rest
  (`partially_issued`, `issued`, `closed`, older Issue/Close workflow), so `submitted = open + reconciled + completed_other_workflow`.
  `rejected` = any cohort request with `returned_at` (sent back at least once, including originals since superseded). `corrected` =
  revisions in the cohort (`revision_number > 1`). `all_submissions` = every status, for transparency.
- `median_hours_to_reconcile` = median of `reconciled_at - submitted_at` over reconciled cohort requests, `null` when none.

## 4. Apparatus and unit identity through vehicle changeouts

- `apparatuses` rows are **physical trucks**. A changeout (`docs/apparatus-changeouts.md` lines 13, 37, 110) moves the unit's identity
  (`unit_id`, `designation`, `name`, `slug`) from one truck row to another: the new truck takes the unit's identity and the old truck takes
  the new truck's previous one. The slug is the unit key for alerts and changeouts; it moves between rows.
- A request records the choice made on the form: `apparatus_id` (the truck row, `nullOnDelete`) and a frozen `target_label`
  (`EmsUnitDirectory.php:42`: `name`, else `designation`, else `unit_id`, as the row was at that moment). Request columns are frozen
  (`InventoryRequest::FROZEN`), so this record never moves.
- **Rule used here:** a request, and every movement linked to it, belongs to `unit:{apparatus_id}` as recorded on the request, and is
  labelled with the `target_label` the request carried (newest request in the period wins; all labels are listed in `labels`). It is
  never re-resolved through today's apparatus row, which after a changeout names a different unit. Test:
  `test_attribution_follows_the_truck_named_on_the_request_through_a_changeout`.
- Keys: `unit:{apparatus_id}`, `detail` (Detail / Special Event, no truck) and `unassigned` (a rescue or other-unit request whose truck
  row has since been deleted). Consequence, stated honestly: after a changeout a unit's later requests appear under the new truck row,
  so one unit can show as two rows. `data_quality.trucks_with_more_than_one_unit_name` counts trucks that carried two unit names in
  the period (F7).

## 5. Estimate versus counted baselines, and the variance rule

- `InventorySnapshot::origin`: `count_form` (Monday form), `direct_edit` (one number typed on the master inventory), `workbook`
  (starting counts), `estimate` (best guesses, 44 items, loaded 2026-11-01), `null` (older admin counts). `kind`: `physical_count`,
  `reconciliation` (calculated), `baseline`.
- `StockLedger::baselines()` (lines 114-151) takes, per item, the newest **final** snapshot line that has a number, by snapshot
  `version`. On-hand = that number + every movement with `id > through_movement_id`, where the cut-off is the line's own
  `through_movement_id` or else the snapshot's (`'through' => $row->line_through ?? $row->through_movement_id`, line 142). Movement
  **ids**, not clock times, decide what is "after", so a movement is counted once even when clocks tie or a receipt is backdated.
- **estimated** = the newest numbered line of the item belongs to a snapshot with `origin = estimate`. Such a balance is labelled
  `estimate`, has no `last_counted_at`, no `days_of_supply`, and is never a baseline or a counted value for variance.
- **Variance** is produced only for a finished physical count (`kind = physical_count`, `origin` not `estimate`, line `value_source =
  counted`) whose previous baseline (the numbered final line with the next lower `version`) is itself `value_source = counted`
  and not an estimate. Then `expected = previous.quantity_base + SUM(movements WHERE id > previous cut-off AND id <= this count's
  cut-off)` and `variance = counted - expected`. A movement made after the count was typed but before it was finalized has an id above
  the line's cut-off and is excluded, exactly as `InventoryCountService::saveLines` intends (lines 86-91). Otherwise the row says why:
  first number on record, previous balance was an estimate, previous balance was calculated.
- `deductionsSinceCount` (`StockLedger.php:227-246`): `issued` plus request-linked `adjusted` after the newest counted line's cut-off,
  `max(0, -total)`, so a net-positive correction hides rather than going negative. Null (not 0) is reported for an item never counted.
- On production no physical count exists after the estimate, so `count_accuracy` is `comparable_counts = 0` with the note
  "No physical count has a verified baseline to compare against yet." and `coverage_pct = null`.

## 6. Items, units and package conversions

- Item identity is `ems_supply_items.id`. `source_row_key` is unique; `name` is **not** unique, and nothing here ever merges or matches by
  name. Two active items can share a name and stay two rows (`test_distinct_items_are_never_merged_by_name`).
- Quantities are stored in base units (`quantity_base`). The count unit is `count_unit` (`each`, `box`, `case`, `pack`, `bag`) with
  `package_unit` and `units_per_package`. Supply Data shows quantities in the count unit only when that conversion is a stated fact:
  `count_unit = each`, or `package_unit = count_unit` with `units_per_package >= 1` (the same condition `CountMath::assertConvertible`
  enforces on writes, lines 36-42). Otherwise quantities stay in single units, `units_resolved = false`, and the item is counted in
  `data_quality.unresolved_conversions`. `CountMath::perCountUnit` (lines 25-28) would silently use 1 here; it is not reused for display
  of an unresolved item (F8).
- **One threshold.** The data model stores a single order threshold per item, `threshold_qty_base` ("order when fewer than"; `CountMath`
  calls it `required_qty_base`). The contract's `minimum` and `order_level` are therefore the same number. No separate "minimum" or
  "near minimum" policy exists, so none is invented. `status`: `unknown` (never counted), `zero` (quantity <= 0), `below_minimum`
  (quantity < threshold), otherwise `ok`.
- `category` is `ems_catalog_items.category` when the supply item has a catalog link and that category is set, else `null`. Workbook items
  are mostly unlinked, so categories are sparse (`filter_options.categories` lists what exists).

## 7. Time

- Persisted instants are UTC (`timestampTz`). Reporting days and weeks are Eastern (`App\Support\HubTimezone::NAME`, also
  `StockLedger::TIMEZONE`, `ReportWriter::TIMEZONE`).
- `SupplyDataFilters` converts Eastern calendar days to UTC instants with `startOfDay()` per day, so a daylight-saving day is 23 or 25
  hours long and no event moves to the wrong day. Weekly buckets start on Sunday at Eastern midnight; the bucket boundaries are
  bound parameters (04:00Z before the change, 05:00Z after), and the database assigns the bucket with a `CASE` on them, which works
  on PostgreSQL and SQLite. Tests: `SupplyDataFiltersTest::test_buckets_and_period_edges_are_right_across_the_daylight_saving_change`
  and `SupplyDataReportTest::test_the_daylight_saving_change_puts_events_in_the_right_eastern_week` (a movement at 11:59 pm Saturday and
  at midnight Sunday on both sides of the 2026-11-01 change).
- "Today" is the Eastern date; a custom range may not end after it and may not exceed 400 days; the previous period is the same number
  of days immediately before.

## 8. What the production data will show today (read-only facts from the lead, 2026-11-02)

| Figure | Value |
|---|---|
| Movements | 7 `issued` (-38) and 7 `adjusted` (+38) reversing them, all request-linked, all MIR-000001 |
| Requests | 3 `submitted` and 1 `reviewed` |
| Net distributed, `distribution_events` | 0 and 0. The four requests still show as submitted and open. |
| Estimates | 44 items with an `origin = estimate` baseline; none counted since |
| `count_accuracy` | no comparable counts, note shown, coverage `null` |
| `days_of_supply` | `null` for every estimated item |
| `last_counted_at` | `null` for every estimated item |

## 9. Findings: defects in how historical data is handled (nothing was changed)

- **F1. An estimate presents as "counted" in the ledger API.** `StockLedger::current()` returns `basis = counted` and the label
  "Counted {date}" whenever the newest line is `value_source = counted` and nothing moved since (`StockLedger.php:273-278`), and
  `SupplyRoomQuery::present()` passes it through (`SupplyRoomQuery.php:118-120`). `lastCounts()` returns the estimate's date as the item's
  "last count", with only an `estimated` flag (line 212). The Captain 5 screens add an Estimate tag from that flag, but any consumer
  that reads `basis` or the date alone will call a guess a count. Supply Data reports `basis = estimate`, no last count date, and never uses it.
- **F2. Estimate lines are written as `counted`.** `SupplyEstimateLoader.php:170` stores `value_source = counted` on purpose ("work as
  usual"). A filter on `value_source = counted` alone includes estimates; the reliable discriminator is the snapshot's `origin`.
- **F3. "By the case or box" was applied literally and some estimates read far too high.** Using the tracked file
  `database/data/ems/supply_room_estimates_20261101.json` (the same file production loaded), the estimated quantity in single units over
  the item's own order level: Disposable Razors 341 cases x 720 = 245,520 (341 times its order level), Tyvek Sleeves 700 boxes x 100 =
  70,000 (70x), AMBU EKG Electrodes 70 cases x 40 = 2,800 (70x), Cold Packs 97 x 24 (48x), Ammonia Wipes 36 x 30 (36x), AA Batteries 84 x 24
  (28x), Lancettes 43 x 100 (21.5x), AAA Batteries 28 x 24 (14x), Tyvek Suit XL 14 x 25 (14x). Supply Data lists every estimate at 10x its
  order level or more in `data_quality.estimates_far_above_order_level` (a heuristic, not a verdict) and says so in `notes`.
- **F4. Several package sizes in the estimates are inferred, not stated.** From the file's own `basis` text: a *case* size from the sheet is
  used as the *box* size (Cold Packs 24, Tyvek Sleeves 100, Tyvek Suit XL 25, 5 x 9 Abdominal Pads 20 per pack); Disposable Razors' size comes
  from "the sheet's order level of 10 packs has no pack size, so 1 case is used" (720); Chewable Aspirin 81 mg has "no box size; one box
  treated as one unit" (`units_per_package = 1`); AAA / AA Batteries and Autopulse Lifeband take the box size from the order level text; Ammonia
  Wipes carries the review reason "5 x 30 = 150 but order amount states 1500U". Lancettes and 4 x 4 Gauze Sponges carry no note about where their size came from.
  None of these is a verified conversion; the item rows keep `needs_review` and the estimate stays an estimate.
- **F5. 18-gauge versus 19-gauge needle.** The workbook's Count sheet row 63 reads "Syringe Needle 18 ga." with 97, but the Inventory sheet and the Hub
  item are "Syringe Needle 19 ga." (same shelf B4). The workbook importer correctly applied no count (`supply_room_workbook_2026.json:27271-27274`,
  `docs/ems/supply-room-workbook-exceptions.md:92`). The estimates file then loaded **97** for "Syringe Needle 19 ga."
  (`supply_room_estimates_20261101.json:185-193`) with the review reason "The Count sheet calls this 18 ga. and the Inventory sheet calls it 19 ga.
  Confirm the gauge". So the number the importer refused to trust now sits on the 19 ga item as an estimate, and the Hub has no 18 ga needle item.
  The two are separate items everywhere in Supply Data; nothing is merged. Captain 5 should confirm which gauge is on the shelf.
- **F6. The older statistics page relabels deleted trucks as Detail.** `ems_inventory_requests.apparatus_id` is `nullOnDelete`
  (migration `2026_10_24_100100`, line 29), and `EmsStatistics::byUnit` groups every null `apparatus_id` as `detail`
  (`EmsStatistics.php:193`), even for a Rescue or Other-unit request. Supply Data keeps them as `unassigned` and counts them in
  `data_quality.requests_without_unit`.
- **F7. A unit's history splits across truck rows after a changeout.** Requests keep the truck row they named (correct, frozen), but the unit
  moved to another row, so the unit appears under two `unit:{id}` keys. A cross-truck unit view would need the changeout table
  (`apparatus_changeouts.unit_slug`); the request does not store the slug. Not fixed here.
- **F8. `CountMath::perCountUnit` silently treats a missing size as 1** (`CountMath.php:25-28`). Writes are guarded by `assertConvertible`; reads
  and displays are not. An item counted by the box with no stated size would display wrong numbers elsewhere. Supply Data refuses to convert it.
- **F9. The estimates loader finds items by name.** `SupplyEstimateLoader::item()` (line 113) looks up exactly one active item with the given
  `name` and aborts otherwise. Names are not unique in the schema, so this is safe only while the catalogue has no duplicate names.
- **F10. `qty_issued` on request lines is not ledger quantity.** `applyReconciledLine` (lines 477-479) adds the quantity to the line's
  `issued_qty` whether or not the line moved stock (`not_stocked`, `no_stock_effect`, controlled). `EmsStatistics` sums it as "issued". Supply Data's
  per-item distributed comes from the ledger; the fulfillment block labels the request-side sum separately.
- **F11. Request-line units are mixed.** `qty_requested` and `qty_recorded_issued` in the fulfillment block add different units of measure (bags, boxes,
  each). They are totals of counts, not a quantity of anything, and the export says so.
- No other defect was found in reversal, correction or timezone handling: `EmsStatistics` weekly arithmetic is calendar-day exact across the
  daylight-saving change (`CarbonImmutable::diffInDays` between Eastern midnights).

## 10. Performance

- Every aggregate is `GROUP BY` in the database over a bounded period; no full history is loaded and there is no per-row query. The overview
  runs a fixed number of queries: **21** with one stock cut-off among the current baselines, plus 3 for each further distinct cut-off
  (`StockLedger::movementSums` and `deductionsSinceCount` query once per distinct `through` value, and the reorder advisor repeats the
  ledger read). The item page runs about 15, the unit page about 7. Each HTTP request adds roughly 11 framework queries for sign-in and
  permission checks. Tests assert the count does not change when rows grow (`SupplyDataApiTest`).
- Indexes the queries rely on (all exist): `ems_stock_movements` PK, `(supply_item_id, id)`, `occurred_at`, `corrects_movement_id`;
  `ems_inventory_requests` PK, `submitted_at`, `status`, `(apparatus_id, submitted_at)`; `ems_inventory_request_lines` `(request_id, sort)`,
  unique `(request_id, bag_key, item_key)`, `catalog_item_id`; `ems_inventory_snapshots` unique `version`, `(status, version)`;
  `ems_inventory_snapshot_lines` `supply_item_id`, unique `(snapshot_id, supply_item_id)`.
- The movement-to-request join goes through `ems_inventory_requests` by primary key after the `occurred_at` range scan, so the absence of an index
  on `ems_stock_movements.request_id` (PostgreSQL does not index foreign keys) is not used by these queries. The `finalized_at` filter on the
  few dozen snapshots needs no index. **No index migration is added**; at this data size EXPLAIN would show index range scans and tiny sorts. EXPLAIN
  plans could not be produced here because no PostgreSQL was reachable locally (no credentials, no `pdo_pgsql` in the local PHP, Docker
  stopped); the CI `postgres` group runs `tests/Integration/SupplyDataPostgresTest.php` against a disposable PostgreSQL 16. If the movements table
  grows to millions of rows, an index on `(request_id)` and on `(movement_type, occurred_at)` would be the first thing to add (additive migration,
  no permissions in migrations).
- No cache. There is no measured need at this size, and a stale number in a stock report is worse than a slow one.

## 11. Review fixes (2026-11-02, after the independent audit)

- **Unit keys.** The distribution query now groups by unit key (`UnitKey::ordinalSql`), so one `unassigned` row covers every request whose truck is gone, whether it named a rescue or an other unit. Previously two target kinds produced two partial rows that overwrote each other.
- **Stock is room-wide.** The pace behind `avg_daily_distributed` and `days_of_supply` is always the whole department's distribution; a unit filter narrows only the request, distribution and trend sections.
- **Retired items.** The stock query bypasses the active filter only for an item asked for by id, and such a row is excluded from the attention and data-quality counters.
- **Time zone independence.** `Instant` binds every instant as UTC. On PostgreSQL the placeholder is `CAST(? AS timestamptz)` with an ISO-8601 string ending `+00:00`, so a comparison never depends on the session `TimeZone`. Week and month boundaries are Eastern midnights computed in PHP and compared as exact instants, which is the same result `AT TIME ZONE 'America/New_York'` would give and also works on SQLite. `tests/Integration/SupplyDataPostgresTest.php` runs the same period under UTC, Tokyo and Pacific session zones and asserts identical output. `config/database.php` is untouched.
- **Whole weeks.** `SupplyDataFilters::completeBucketIndexes()` marks the weekly buckets that are not clipped by the period; only those feed `median_weekly`.
- **Correction chains.** `corrected` counts counted requests with `revision_number > 1`.
- **Out of scope, noted:** `/ems/api/supply/items/{item}` (the older SupplyRoomController route) uses `whereNumber('item')` with an `int` action parameter, so a 20-digit id probably overflows the same way the Supply Data route did before F6. It was not changed.
