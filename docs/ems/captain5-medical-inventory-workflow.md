# Captain 5 medical inventory: Friday forms, Monday counts

Status: contract for the November 2026 release. It replaces the Prepare/Issue/Close and Stock/Counts/Reconcile/Reorder screens on the Captain 5 page.
`docs/ems/ems-inventory-contract.md` stays the source of truth for tables, the ledger and access; this file records what changed.

## 1. The cycle

- **Friday.** Apparatus submit forms. Captain 5 acknowledges or rejects each one, selects acknowledged forms and reconciles them. Reconciling deducts from the supply room and archives the forms.
- **Monday.** Captain 5 types what is on each shelf. Those numbers become the new stock and order recommendations are worked out from them. Admin → EMS gets the report.

## 2. Request statuses

New: `returned` (rejected, waiting for the member), `reconciled` (deducted and archived), `superseded` (replaced by a corrected revision).

| View | Statuses |
|---|---|
| Active | `submitted`, `reviewed`, `prepared`, `returned` |
| Archived | `reconciled`, `superseded`, `issued`, `partially_issued`, `closed`, `cancelled` |

- **Acknowledged** means `reviewed_at` is set (`reviewed_by_user_id`, `reviewed_by_name`). It changes no stock and keeps the form in Active. A request already `reviewed` before this release counts as acknowledged.
- **Reconcile eligibility:** status `submitted|reviewed|prepared`, acknowledged, and no line has `issued_qty > 0`. A request with any older issuance can never be reconciled, so a request is deducted by exactly one path. `issued` and `partially_issued` records stay in Archived as older records.
- **Reject** needs the same status and no issuance, plus a reason (3 to 500 characters). It sets `returned` and clears the acknowledgment, notifies the member, and changes no stock.
- **Revision.** The member resubmits with `revises: <uuid>`. A new request row is created (`revision_of_request_id`, `revision_number`, number `MIR-000004-R2`), the old row becomes `superseded` with an event, and the new row starts unacknowledged. Snapshots stay immutable; history is the chain of rows.
- Legacy staff endpoints (`prepare`, `issue`, `not-issued`, `close`) refuse `returned`, `reconciled` and `superseded` with 409. `correct` also works on `reconciled`, because the deduction is stored as an ordinary `issued` event plus an `issued` movement. Admin's Correct action is the recovery path.

## 3. Reconciliation

One transaction: request row locks in id order → stock lock (the same order the older issue path uses, so the two can never deadlock) → state check on all → one `issued` movement per line → events → status. Any failure rolls back everything.

- **Movement key** is `reconcile:r{request}:l{line}`, not tied to a user or a client key, so a line can only ever deduct once. A retry with the same `Idempotency-Key` replays the stored result; a second person or a new key gets 409 `already_reconciled`.
- **Quantity** defaults to the requested quantity. Captain 5 can lower it to what was actually taken. `0` records "Not taken" and deducts nothing.
- **Resolving a line to an item:** the person's choice → the `ems_catalog_supply_links` row for the line's catalog item → the single active non-controlled supply item with that catalog identity → otherwise `needs_item`. Names are never compared.
- **Units:** blank/`each`/the item's base unit deducts as typed; the item's package unit multiplies by an explicit `units_per_package`; anything else is `needs_unit` and Captain 5 types the units to deduct. Nothing is guessed.
- **Controlled and Yes/No lines** have no stock effect, as before.
- **Remembering:** choosing an item or marking "Not a supply room item" writes `ems_catalog_supply_links` (source `person`), so the same line resolves itself next time. A `reviewed` row (from the tracked mapping file) can be replaced by a person; an importer never replaces a `person` row.
- **Blocking and warning:** an unresolved line blocks with 422 `needs_correction`. A stock change since the preview gives 409 `stale_preview`. A result below zero needs `accept_low_stock: true`, and the preview shows it. An item with no count yet is deducted in the ledger and shown as a warning; the next count sets its number.

## 4. Live quantity and the red indicator

- Live quantity is the ledger's existing calculation, in base units.
- `count_unit` (`each|box|case|pack|bag`) is what the person types. It is `each`, or equals `package_unit` with a known `units_per_package`. The API rejects anything else, so a count can always convert.
- Display value = base ÷ factor in the count unit, one decimal at most when it does not divide evenly.
- **Red indicator** = deductions since the item's newest **counted** snapshot line (`value_source = counted`, final): the sum of `issued` movements plus `adjusted` movements that belong to a request (corrections), both with `id >` that line's `through_movement_id`. Computed from the ledger, so it survives refreshes, devices and shifts, and a calculated reconciliation snapshot never resets it. Items never counted show no red number.
- **Direct edit** of the on-hand number creates a one-line final `physical_count` snapshot with `origin = direct_edit`. It is a physical count, not a movement, so it resets the red number for that item only.

## 5. Monday count

`quantity_to_order = max(0, required_on_hand - actual_count)`, where `required_on_hand` is `threshold_qty_base`.

- Worked in base units, shown in the count unit, rounded **up** to a whole count unit.
- No threshold: "No order level set". Never a guess.
- Historical `order_amount_text` / `order_qty_packages` are not used for this number.
- Blank stays uncounted and a typed 0 is a count of zero.
- The draft is one room-wide `draft` snapshot, resumable by any Captain 5. The server is the source of truth; the device keeps unsent entries until the server confirms them.
- Finalize stamps every in-scope active item: counted lines get their numbers and order; uncounted lines are `not_counted` with a null quantity and leave stock untouched. `counted_items`, `uncounted_items` and `order_items` are stored on the snapshot, and a report with uncounted items is labeled Partial. The submit call must state `expected_uncounted`, so the person has seen what was missed.

Test vectors (required, counted → order): 10, 5 → 5; 10, 8 → 2; 10, 10 → none; 10, 15 → none; 25, 0 → 25. Box item, 250 per box, required 500: 1 box → order 1 box; 0 boxes → order 2 boxes. Box item, 50 per box, required 25: 0 boxes → order 1 box.

## 6. API (session `auth:web`, CSRF, envelope `{ok,data,meta}`, `Idempotency-Key` on every write)

Base `/ems/api`. `view` for reads, `act` for writes.

### Forms

- `GET /requests?state=active|archived` adds to the existing filters. Each row adds `acknowledged` (bool), `acknowledged_at`, `acknowledged_by`, `can_reconcile`, `can_reject`, `can_acknowledge`, `return_reason`, `returned_at`, `reconciled_at`, `reconciled_by`, `revision_number`, `items_preview` (first 3: `{label, quantity, unit}`) and `quantity_total`.
- `GET /requests/{uuid}` adds the same plus `revisions: [{id, number, status, status_label, submitted_at, is_current}]`.
- `POST /requests/{uuid}/acknowledge` returns the detail. Acknowledging twice is a no-op.
- `POST /requests/{uuid}/reject` `{reason}` returns the detail.
- `GET /forms/reconcile/preview?ids=<uuid,uuid>` (max 50) returns:
  ```
  { last_movement_id, blocked,
    forms: [{id, number, target_label, submitter_name, submitted_at, eligible, ineligible_reason}],
    lines: [{line_id, request_id, number, target_label, label, variant_label, requested_qty, unit, quantity, quantity_base,
             state: 'ready'|'needs_item'|'needs_unit'|'no_stock_effect'|'not_stocked', message,
             item: {id, name, location_code, count_unit}|null}],
    totals: [{supply_item_id, name, location_code, count_unit, units_per_count_unit, deduct_base, before_base, after_base, low, not_counted}] }
  ```
- `POST /forms/reconcile` `{request_ids:[uuid], expected_last_movement_id:int, accept_low_stock?:bool, decisions?:[{line_id, quantity?:int, supply_item_id?:int, quantity_base?:int, not_stocked?:bool}]}` returns `{batch, reconciled:[{id, number}], movement_count, deducted:[{supply_item_id, name, deduct_base}]}`. Errors: 409 `stale_preview`, 409 `already_reconciled`, 409 `not_eligible`, 409 `low_stock`, 422 `needs_correction` (`fields.lines` lists line ids).

### Master inventory

- `GET /supply/master` returns:
  ```
  { sections: [{code, label, item_count}],
    items: [{id, name, aliases:[string], location_code, location_label, section_key, section_label, base_unit, count_unit, count_unit_label, count_unit_label_plural,
             units_per_count_unit, package_unit, units_per_package, on_hand_base, on_hand, deducted_base, deducted, counted_at, counted_by,
             required_base, order_rule_text, refrigerated, needs_review, review_reasons:[string], config_problem}],
    inactive: [{id, name, location_code}],
    draft: {id, started_at, started_by, counted}|null,
    last_count: {id, finalized_at, finalized_name}|null,
    can_edit: bool }
  ```
  `on_hand` and `deducted` are in count units and may be null/0. Items with no location get `location_code: null`; sections end with `{code: null, label: 'No shelf assigned'}` when any exist. Controlled items are excluded.
- `POST /supply/master/items` `{name, location_code?, new_location_label?, count_unit, units_per_package?, threshold?, on_hand?}` returns one item in the master shape (201).
- `PATCH /supply/master/items/{id}` any of `{name, location_code, new_location_label, count_unit, units_per_package, threshold, active}` returns one item. `threshold` is in count units; null clears it.
- `POST /supply/master/items/{id}/count` `{on_hand:int}` (count units) returns one item.

### Count form

- `POST /supply/count-form/start` returns `{count: {id, started_at, started_by, entries: {"<itemId>": int}}, resumed}` plus the master `sections` and `items`.
- `PUT /supply/count-form/{uuid}/entries` `{entries:[{supply_item_id, on_hand:int|null}]}` (null clears) returns `{saved, entries}`.
- `GET /supply/count-form/{uuid}/review` returns `{counted, uncounted:[{id, name, location_code}], orders:[{supply_item_id, name, location_code, on_hand, order, unit_label}], config_problems:[{id, name, problem}]}`.
- `POST /supply/count-form/{uuid}/submit` `{expected_uncounted:int}` returns `{id, finalized_at, counted_items, uncounted_items, order_items}`. A mismatch gives 409 `uncounted_changed`.
- `DELETE /supply/counts/{uuid}` (existing) discards a draft.

## 7. Member API additions

Base `/employee/forms/api/ems`.

- `POST /requests` accepts optional `revises: <uuid>`. Refused unless the request is the member's own and `returned`; 409 `cannot_revise` otherwise.
- `GET /requests/{uuid}` adds `can_revise`, `return_reason`, `returned_at`, `returned_by`, `revision_number`, `revision_of` (uuid|null), `replaced_by` (uuid|null).
- `GET /requests` rows add `can_revise` and `return_reason`.
- Member-visible history adds `rejected` (with its reason), `reconciled` and `revised`.

## 8. Captain 5 page

Tabs: Today, Issues, Truck Changes, **Submitted Inventory Forms** (`?tab=rescue`), **Master Supply Room Inventory** (`?tab=supply`), Narcotics Checks, Messages, Department Updates, History. Replacements is gone; `?tab=replacements` falls back to Today. The URL ids stay `rescue` and `supply` because notification links already point at them. `&count=1` on the supply tab opens the count form, so the browser back button works.

## 10. Returning a day's forms to Captain 5

`php artisan ems:return-forms-to-captain5 --date=YYYY-MM-DD` is a dry run; `--apply` writes. The day is Eastern time.

- Forms never worked are already on Submitted Inventory Forms and are left alone.
- A form issued under the older workflow has each issuance corrected to zero with ordinary compensating rows, keeps its acknowledgment, and returns to Active, so reconciling it is the one deduction that stands.
- It runs **before** the workbook baseline. Once a count covers an older issuance the command refuses, because that count already reflects the shelf and a compensating row after it would add stock that was never returned.

The first use was Friday, October 30, 2026, on the owner's instruction: MIR-000001 had been issued under the older workflow before any count existed, so its movements had never reached a stock number.

## 9. Workbook cutover

`php artisan ems:apply-supply-workbook` is a dry run. `--apply` writes one final `baseline` snapshot (`origin = workbook`, `idempotency_key = workbook:<sha256>`) under the stock lock. Movements recorded before it stay in history and are not subtracted from the supplied count. Re-running is a no-op. An item that already has a counted line is never overwritten. See `docs/ems/supply-room-workbook-exceptions.md`.
