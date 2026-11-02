# Supply Data: analytics contract (v2)

Status: v1 was agreed before implementation (2026-11-02). v2 is what the backend actually returns; the backend owner (Agents E+F) keeps this
file current, and any shape change must be made here first and mirrored in
`resources/js/daily-checkout/src/components/captain5/ems/supplyData/types.ts`. How each figure is derived, and the data defects found,
is in `docs/ems/supply-data-provenance.md`.

## Changes from v1 (read this first, Agents G and H)

Keys that existed in v1 keep their names and meaning unless listed here. Additions are additive.

1. **Envelope.** Every JSON payload below is the `data` member of the standard EMS envelope `{ "ok": true, "data": {...}, "meta": {} }`.
   Failures are `{ "ok": false, "error": { "code", "message", "fields" } }`: 401 `unauthenticated`, 403 `forbidden`, 404 `not_found`,
   422 `validation_failed` (`error.fields` names the parameter), 429 from the throttle. Responses send `Cache-Control: private, no-store`.
2. **New query parameter `limit`** (1 to 50, default 10): the length of `top_items` (overview), `top_requested` and `top_distributed` (unit page).
   The export defaults it to 50.
3. **`unit` also accepts `unassigned`**, and unit rows carry `kind: unit | detail | unassigned` and `labels` (every name the unit's requests carried
   in the period, newest first). A `unassigned` row is a Rescue or Other-unit request whose truck row was deleted; it is never folded into `detail`.
4. **One order threshold.** The data model stores a single order level per item. `minimum` is that level in count units and `order_level` is the
   same number (kept so v1 code keeps working). Status `near_minimum` was removed because no second threshold exists:
   `status` is `zero | below_minimum | ok | unknown`.
5. **Stock rows** add `baseline_origin` (`count_form | direct_edit | workbook | estimate | null`), `estimated` (bool), `units_resolved` (bool),
   `recommended_order_unit` and `recommended_order_basis`. `last_counted_at` is `null` for an estimate (an estimate is not a count).
   `on_hand` and `deductions_since_count` are `null` when the item was never counted (a real 0 stays 0).
   `recommended_order` is the reorder advisor's `suggested_packages` in `recommended_order_unit` (null when the package size is unknown).
6. **`fulfillment.submitted` is the number of COUNTED requests** (not cancelled, sent back, or replaced), so a corrected request is one.
   New: `completed_other_workflow` (counted, neither open nor reconciled), `all_submissions` (every status). `qty_requested` and `qty_distributed` add
   request-line units of measure and are shown as totals of counts only; per-item `distributed` comes from the ledger and can differ.
7. **`count_accuracy`** adds `counted_lines`, `exact_matches` (null when nothing is comparable) and `items_compared`; `items[]` has a fixed shape (below).
8. **`data_quality`** adds `movements_on_uncounted_requests`, `uncounted_items`, `negative_balances`, `items_without_minimum`, `requests_without_unit`,
   `trucks_with_more_than_one_unit_name`, `estimates_far_above_order_level[]` and `insufficient_data[]` (structured notes); `notes[]` stays the human text.
9. **New overview members:** `scope_notes[]` (which filters narrow which sections) and `filter_options.categories[]`.
10. **Item page:** `balance` carries the stock-row fields above, `distribution` adds `change_pct`, `counts[]` rows are `{counted_at, verified, estimated, expected,
    variance, variance_pct, basis_note}` (an estimate shows in `estimated`, never in `verified`), `movements[]` adds `corrects_movement_id`.
11. **Unit page:** `unit` adds `labels`; `summary` adds `items_distributed`; `top_requested[]` adds `unit` and its `item_key` is a stable identity string.
12. **Export** is `GET /ems/api/supply-data/export.xlsx`; sheets are Summary, Supplies, Units, Stock, Fulfillment, Count accuracy and "About this export".
13. `SupplyDataFilters::fromArray(array $input, ?CarbonImmutable $now = null)` (the optional clock is for tests) and unit and item narrowing helpers
    (`forUnit`, `forItem`) were added; `SupplyDataReport` is resolved from the container.

## 1. Purpose and boundaries

Supply Data answers, for Captain 5 (React, Captain 5 page tab **Supply Data**) and EMS administrators (Filament **Medical Supply Data**, route
`/admin/medical-statistics` kept):

- What needs attention now (below minimum, zero, estimated, not counted recently, reorder).
- What is moving most from the supply room, and to which units.
- How each unit's requests and replenishment look over time.
- Physical count accuracy, request fulfillment, data quality.

Hard rules:

1. **Read only.** No analytics code path writes to any `ems_*` table, takes the stock lock, or calls a workflow service that mutates state. Tests assert row
   counts, the stock lock value and the SQL are unchanged.
2. **One calculation home.** `App\Services\Ems\SupplyData\SupplyDataReport` computes every figure. Controllers, the Filament page, exports and React only
   present its output. On-hand quantities come only from `StockLedger`; reorder advice only from `ReorderAdvisor`.
3. **Distributed is not consumed.** "Distributed" is quantity that left the supply room for a unit according to request-linked stock movements.
   Nothing here claims patient use.
4. **Estimates stay estimates.** A balance whose newest numbered snapshot line came from an `origin = estimate` snapshot is labelled `estimate`; it is never
   called verified or counted, never gets `days_of_supply`, and count variance is never computed against it.
5. **Honest emptiness.** `null` means "cannot be determined"; `0` means a real zero. Sparse data returns explicit `insufficient_data` notes rather than
   fabricated trends.

## 2. Metric definitions (authoritative wording is `SupplyDataReport::DEFINITIONS`)

| Key | Definition |
|---|---|
| requested | Quantity asked for on counted requests. A request is counted unless it was cancelled, sent back (returned) or replaced by its correction (superseded). Yes/No answers and controlled items are not quantities. |
| distributed | Net quantity that left the supply room for a unit: request-linked `issued` movements (sign flipped) plus the request-linked `adjusted` correction rows, netted per (request, item). A fully reversed issuance is 0. Dated by the issuance, so a late correction does not move it to another period. Shown in the item's count unit (single units when the unit's size is not stated). |
| distribution_events | Number of distinct (request, item) pairs with a positive net distributed quantity. Used to rank items. |
| received | `received` movements (orders). Item page only. |
| adjusted | `adjusted` movements with no request (manual). Corrections on a request are netted into distributed. |
| on_hand | `StockLedger` current quantity with its basis: `counted`, `calculated` (since a count), `estimate`, `none`. |
| verified | A number from a physical count. An estimate is never verified. |
| estimated | The newest number for the item is a best-guess estimate, not a physical count. |
| variance | `verified count - expected`, where expected = previous baseline + every movement with `previous_cutoff < id <= this count's cutoff`. Only when the previous baseline was itself a counted number (not an estimate, not calculated). Otherwise `null` with `basis_note`. |
| days_of_supply | `on_hand / average distributed per day` over the period. `null` unless the balance is not an estimate, on-hand is above 0, the average is above 0 and there were at least 3 distribution events. An estimate of distribution pace, not use. |
| not_counted_recently | No physical count in the last 10 days, or never. Estimates are not counts. |
| needs_reorder | The reorder advisor recommends an order (quantity below the item's order level). A recommendation is not an order. |

## 3. HTTP API (under the existing `ems/api` prefix, staff middleware `EnsureEmsAccess:view`, read only, `throttle:60,1,ems-supply-data`)

Common query parameters (validated server-side; invalid gives 422 naming the field):

- `period`: `30d` (default) | `90d` | `12m` | `custom`. Eastern calendar days; `30d` is today and the 29 days before it. `12m` runs from the same date a year ago plus one day.
- `from`, `to`: `Y-m-d` Eastern, required when `period=custom`, `from <= to`, span at most 400 days inclusive, `to` not after today. Ignored for the other periods.
- `unit`: `unit:{apparatus_id}`, `detail` or `unassigned` (optional). On the unit page the unit comes from the path and this parameter is ignored.
- `item`: supply item id (optional; must be an active or inactive non-controlled item). On the item page the item comes from the path.
- `category`: supply item category (optional, at most 80 characters).
- `limit`: 1 to 50, default 10.

Scope of the filters: `unit` narrows requests and distribution; `item` and `category` narrow supplies, stock and counts. Request totals and fulfillment follow the
period and unit only (`scope_notes` says so).

### 3.1 `GET /ems/api/supply-data` overview

```json
{
  "label": "Distributed from the supply room — not patient use",
  "generated_at": "2026-11-02T12:00:00-05:00",
  "timezone": "America/New_York",
  "period": { "key": "30d", "from": "2026-10-04", "to": "2026-11-02", "days": 30,
              "previous": { "from": "2026-09-04", "to": "2026-10-03" } },
  "filters": { "unit": null, "item": null, "category": null },
  "scope_notes": [],
  "attention": { "below_minimum": 3, "zero_stock": 1, "estimated": 44, "not_counted_recently": 50, "needs_reorder": 4 },
  "headline": { "distribution_events": 12, "previous_distribution_events": 9, "change_pct": 33.3,
                "items_distributed": 7, "units_supplied": 4, "requests_submitted": 6, "requests_reconciled": 5 },
  "trend": [ { "week_start": "2026-10-04", "distribution_events": 3, "requests": 2 } ],
  "top_items": [ { "item_id": 12, "name": "Gloves, nitrile (L)", "count_unit": "box", "category": null,
                   "distribution_events": 5, "distributed": 9, "units_supplied": 3,
                   "last_distributed_at": "2026-10-30T10:12:00-04:00",
                   "previous_distribution_events": 2, "direction": "up" } ],
  "units": [ { "unit_key": "unit:45", "apparatus_id": 45, "kind": "unit", "label": "Rescue 2", "labels": ["Rescue 2"],
               "requests": 2, "distribution_events": 6, "items_distributed": 5,
               "last_replenished_at": "2026-10-30T10:12:00-04:00" } ],
  "stock": [ { "item_id": 12, "name": "…", "category": null, "location": "B4", "count_unit": "box",
               "on_hand": 14, "basis": "counted|calculated|estimate|none", "baseline_origin": "count_form",
               "last_counted_at": "2026-10-27T08:00:00-04:00", "deductions_since_count": 3,
               "minimum": 10, "order_level": 10, "status": "zero|below_minimum|ok|unknown",
               "needs_reorder": true, "recommended_order": 6, "recommended_order_unit": "case", "recommended_order_basis": "rounded_up_to_packages",
               "avg_daily_distributed": 0.3, "days_of_supply": 46.7, "estimated": false, "units_resolved": true } ],
  "fulfillment": { "submitted": 6, "acknowledged": 5, "rejected": 1, "corrected": 0, "reconciled": 5, "open": 1,
                   "completed_other_workflow": 0, "all_submissions": 7, "median_hours_to_reconcile": 70.5,
                   "qty_requested": 40, "qty_distributed": 36 },
  "count_accuracy": { "counted_lines": 0, "comparable_counts": 0, "exact_matches": null, "items_compared": 0, "coverage_pct": null,
                      "items": [ { "item_id": 12, "name": "…", "counted_at": "…", "counted": 85, "expected": 90, "variance": -5, "variance_pct": -5.6 } ],
                      "note": "No physical count has a verified baseline to compare against yet." },
  "data_quality": { "unattributed_movements": 0, "movements_on_uncounted_requests": 0, "estimated_items": 44, "uncounted_items": 0,
                    "unresolved_conversions": 0, "negative_balances": 0, "items_without_minimum": 0, "requests_without_unit": 0,
                    "trucks_with_more_than_one_unit_name": 0,
                    "estimates_far_above_order_level": [ { "item_id": 7, "name": "Disposable Razors", "on_hand": 341, "count_unit": "case", "times_order_level": 341 } ],
                    "insufficient_data": [ { "section": "distribution", "message": "…" } ],
                    "notes": ["…"] },
  "definitions": { "distributed": "…" },
  "filter_options": { "categories": [] }
}
```

Notes:

- `direction`: `up | down | flat | new` (`new` = no events in the previous period). `change_pct` is `null` when the previous value is 0.
- `trend` is weekly (weeks start Sunday, Eastern; key `week_start`) up to 120 days and monthly (key `month_start`) for `12m` and longer custom ranges. Zero-activity
  buckets are included; the first and last buckets are clipped to the period.
- `stock` lists every active non-controlled item (zero, below minimum, unknown first, then by name). `top_items` is the first `limit` items by `distribution_events`.
- `units` has one row per unit with a counted request or a distribution in the period, most events first.
- `count_accuracy.items` is the 25 largest differences; `coverage_pct` is items compared divided by tracked items, `null` when nothing is comparable.
- `fulfillment` figures are over the requests submitted in the period. `submitted = open + reconciled + completed_other_workflow`.

### 3.2 `GET /ems/api/supply-data/items/{item}` one supply (404 for an unknown or controlled item)

```json
{
  "label": "…", "generated_at": "…", "timezone": "America/New_York",
  "item": { "item_id": 12, "name": "…", "category": null, "location": "B4", "count_unit": "box", "package_unit": "box",
            "package_size": 100, "controlled": false, "active": true, "units_resolved": true },
  "balance": { "on_hand": 14, "basis": "counted", "baseline_origin": "count_form", "estimated": false, "last_counted_at": "…",
               "deductions_since_count": 3, "minimum": 10, "order_level": 10, "status": "ok", "needs_reorder": false,
               "recommended_order": null, "recommended_order_unit": null, "recommended_order_basis": null,
               "days_of_supply": 46.7, "avg_daily_distributed": 0.3 },
  "period": { "…": "as overview" }, "filters": { "…": "as overview" },
  "distribution": { "distributed": 9, "distribution_events": 5, "previous_distribution_events": 2, "change_pct": 150.0,
                    "avg_weekly": 2.1, "median_weekly": 2.0, "last_distributed_at": "…" },
  "trend": [ { "week_start": "2026-10-04", "distributed": 3, "distribution_events": 2 } ],
  "units": [ { "unit_key": "unit:45", "label": "Rescue 2", "distribution_events": 3, "distributed": 5, "share_pct": 55.6 } ],
  "replenishment": [ { "occurred_at": "…", "type": "received|adjusted", "quantity": 10, "reason": "…" } ],
  "counts": [ { "counted_at": "…", "verified": 14, "estimated": null, "expected": 15, "variance": -1, "variance_pct": -6.7, "basis_note": null } ],
  "movements": [ { "id": 99, "occurred_at": "…", "type": "issued", "quantity": -2, "unit_label": "Rescue 2",
                   "request_number": "MIR-000004", "reason": null, "corrects_movement_id": null, "reversed": false } ],
  "movements_truncated": false
}
```

`median_weekly` is `null` for monthly periods. `movements` are the newest 50 in the period, `quantity` in count units, signed as in the ledger. `replenishment` has
receipts and manual adjustments only (corrections on a request are not replenishment). `counts` is the item's numbered history (newest 20, estimates and workbook
baselines included and labelled); an estimate appears in `estimated`, never in `verified`. No member or staff names appear on this page.

### 3.3 `GET /ems/api/supply-data/units/{unitKey}` one unit (`unit:{id}`, `detail` or `unassigned`; 404 for a malformed key or a truck that is not on file)

```json
{
  "label": "…", "generated_at": "…", "timezone": "America/New_York",
  "unit": { "unit_key": "unit:45", "apparatus_id": 45, "label": "Rescue 2", "labels": ["Rescue 2"], "kind": "unit|detail|unassigned" },
  "period": { "…": "as overview" }, "filters": { "…": "as overview" },
  "summary": { "requests": 2, "requests_reconciled": 2, "qty_requested": 12, "distributed_events": 6, "items_distributed": 5,
               "last_replenished_at": "…" },
  "top_requested": [ { "item_key": "catalog:12|each", "label": "…", "unit": "each", "qty_requested": 4, "requests": 2 } ],
  "top_distributed": [ { "item_id": 12, "name": "…", "count_unit": "box", "distributed": 5, "distribution_events": 3 } ],
  "trend": [ { "week_start": "…", "requests": 1, "distribution_events": 3 } ],
  "requests": [ { "number": "MIR-000004", "uuid": "…", "submitted_at": "…", "status": "reconciled",
                  "lines": 6, "qty_requested": 12, "reconciled_at": "…" } ]
}
```

`item_key` is `catalog:{catalog_item_id}|{unit}` for a catalog item and `line:{bag_key}|{item_key}|{unit}` for a form line without one; names are never merged.
`requests` is the newest 50 counted requests of the unit in the period; link to the existing request detail (`/ems/api/requests/{uuid}`), never duplicate it.
A unit with no activity is a page of zeros and empty lists, labelled with the truck's name.

### 3.4 `GET /ems/api/supply-data/export.xlsx` overview as Excel, same filters

Sheets: Summary, Supplies, Units, Stock, Fulfillment, Count accuracy, About this export. The About sheet states the interval, the comparison period, the filters,
the generated time, the label, the scope notes, every definition, the data notes and the provenance. Every estimated balance carries "Estimated" in the Stock
sheet's Basis column. Totals equal the JSON for the same parameters. File name `MBFD - Supply Data - YYYY-MM-DD.xlsx`.

## 4. PHP service interface (consumed by the controller, Filament page and exporter)

```php
namespace App\Services\Ems\SupplyData;

final class SupplyDataFilters {            // immutable, validated
    public static function fromArray(array $input, ?CarbonImmutable $now = null): self; // throws ValidationException
    public function cacheKey(): string;
    public function describe(): string;     // human text for exports
    public function forUnit(string $unitKey): self;
    public function forItem(int $itemId): self;
}
final class SupplyDataReport {              // resolve with app(SupplyDataReport::class)
    public const LABEL = 'Distributed from the supply room — not patient use';
    public const DEFINITIONS = [ /* key => sentence */ ];
    public function overview(SupplyDataFilters $filters): array;
    public function item(SupplyItem $item, SupplyDataFilters $filters): array;
    public function unit(string $unitKey, SupplyDataFilters $filters): array; // throws ModelNotFoundException for an unknown truck or key
}
// App\Services\Ems\Exports\SupplyDataExporter: spec(array $overview, SupplyDataFilters, CarbonInterface): ReportSpec, xlsx(...), filename(CarbonInterface).
```

The Filament page should call the report and exporter directly rather than the HTTP API. Query budget: the overview runs 21 queries with one stock cut-off among the
current baselines and 3 more for each further distinct cut-off; the item page about 15; the unit page about 7.

## 5. Access

`EnsureEmsAccess:view` (same as the existing statistics endpoint). Read access never grants inventory edit, count, reconcile or order rights; every write route
still refuses a view-only user (tested). A Captain 5 deny override blocks these endpoints. Controlled substances are excluded from every figure, as in the ledger.
No patient data and no member personal data appear: requests are identified by their MIR number and uuid and the unit label only.
