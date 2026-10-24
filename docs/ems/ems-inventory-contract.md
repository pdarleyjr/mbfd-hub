# EMS inventory: medical inventory requests and the Captain 5 supply room

Status: contract for the 2026-10 "Enterprise Navigation, EMS Inventory & Administrative Modernization" release.
Owner: EMS / Captain 5. This file is the source of truth for the data model and the rules below; code must match it.

## 1. What this adds

1. **Medical Inventory Request** (member form, Employee Portal → Forms). One combined Airway Bag + ALS Med Bag/Box request.
   A member taps the quantities a bag needs and submits once. Nothing typed is mandatory, there are no submission windows or
   quotas, and a partial request is valid. Blank stays blank (never stored as 0).
2. **Rescue Inventory** (Captain 5 tab and Admin → EMS). Every request is one durable record that Captain 5 and EMS Admin work
   from: review, prepare, issue (partially or fully), correct, close, message. History is visible across shifts.
3. **Medical Supply Room** (Captain 5 tab and Admin → EMS). The Captain 5 supply room catalogue imported from the
   2021 order sheet, physical counts by location, an append-only stock-movement ledger, a reconcile-since-last-count
   preview, reorder advisories and "order placed" records.
4. **Medical Data / Statistics** (Admin → EMS). Request analytics from the transactional tables, labeled as requests, not consumption.

## 2. Source documents and provenance

Tracked extractions live in `database/data/ems/` (see `EXTRACTION_VALIDATION.md` there):

| File | Source PDF | Schema |
|---|---|---|
| `airway_bag_inventory.json` | `Airway_bag_inventory.pdf` | `mbfd.ems.source_form.v1` |
| `als_med_bag_inventory.json` | `ALS_Med_bag_inventory.pdf` (3/2023, "Totals can include amounts in Bobo Bag.") | `mbfd.ems.source_form.v1` |
| `captain5_supply_room_inventory.json` | `captain_5_supply_room_inventory.pdf` (Revised 11/2021, 5 pages) | `mbfd.ems.supply_room_source.v1` |

Rules:
- Every imported row keeps its verbatim source text (`source_label`, `min_text`, `order_level_text`, supplier cells, ...) and a
  `source_ref` (page/column/row). Normalized numbers are separate columns and may be null.
- Each source file's `sha256` is stored with the imported form version. Re-importing the same sha256 is a no-op.
- Supplier names, prices and price dates are **historical and unverified** (2021-2023). The UI labels them so.
- Variants are never flattened: "IV 14G/16G 2 EACH" is two items sharing `variant_group`.

## 3. Identity rules

- **Catalog items** (`ems_catalog_items.key`) are stable slugs. Items on different sources share a catalog item only when the
  extraction asserted it explicitly (`catalog_ref` in the tracked JSON; older documents may say `catalog_key`, and the
  importer accepts both, as it does `item_ref` / `item_key`). Nothing is ever matched by name similarity at runtime.
  Aliases are stored for search only.
- **Form items** (`ems_source_form_items.item_key`) are unique within a form version and stable across re-imports.
- Request lines and snapshots copy the label, compartment, min text and unit **at submission time**, so renames never change history.

## 4. Tables (hand-written, additive migrations; SQLite for tests, PostgreSQL in production)

All timestamps `timestampTz`. All "actor" columns are `user_id` FKs (`nullOnDelete`) plus a name/rank snapshot where shown to people.

### Reference data
- `ems_catalog_items`: id, key (unique), name, category, aliases (json), refrigerated (bool), controlled_substance (bool),
  has_expiration (bool, nullable = unknown), active, timestamps.
- `ems_source_forms`: id, key (`airway-bag` | `als-med-bag`), version_label, title, file_name, sha256 (unique with key), notes (json),
  imported_at, active (one active version per key), timestamps.
- `ems_source_form_items`: id, source_form_id, item_key (unique per form), catalog_item_id (nullable), section_key, section_label,
  parent_section_key, label, source_label, min_text, min_qty (nullable int), min_unit, min_applies_to, variant_group, variant_label,
  response_type (`quantity` | `yes_no`), flags (json: detail_airway_bag_only, rescue_only, refrigerated, controlled_substance),
  notes, needs_review, review_reason, source_ref (json), sort.

### Requests
- `ems_inventory_requests`: id, uuid (unique, route key), number (unique, `MIR-000123`), status, submitted_by_user_id,
  submitted_by_employee_id, submitter_name, submitter_rank, submitted_at (server time),
  target_kind (`rescue_unit` | `other_unit` | `detail_event`), apparatus_id (nullable FK `apparatuses`), target_label (snapshot),
  event_assignment (nullable, max 160), member_note (nullable, max 2000), client_submission_id (uuid, unique; idempotency),
  source_form_versions (json: key → sha256/version), snapshot (json, immutable), snapshot_sha256,
  operational_form_record_id (nullable; the member's Files copy), reviewed_at/by, closed_at/by, close_reason, timestamps.
- `ems_inventory_request_lines`: id, request_id, source_form_item_id (nullable), catalog_item_id (nullable), item_key, label,
  section_label, variant_label, min_text, unit (snapshot), response_type, requested_qty (nullable int; null = not requested),
  requested_flag (nullable bool for yes_no), prepared_qty (nullable), issued_qty (int, default 0; derived from issuances),
  line_status (`open` | `prepared` | `partially_issued` | `issued` | `not_issued`), not_issued_reason, sort.
  Only lines with a requested quantity > 0 or a yes_no answer are stored.
- `ems_inventory_request_events`: id, request_id, request_line_id (nullable), actor_user_id, actor_name, actor_rank, actor_context
  (`member` | `captain5` | `ems_admin` | `system`), action, from_status, to_status, payload (json), created_at. Append-only (model refuses update/delete).
- `ems_inventory_request_messages`: id, request_id, author_user_id, author_name, author_rank, author_context, body, client_message_id
  (unique), created_at. Visible to the requester and to Captain 5 / EMS Admin.

### Supply room
- `ems_storage_locations`: id, code (unique, e.g. `A2`, `VEND`, `G1`), label, sort, active.
- `ems_supply_items`: id, catalog_item_id (nullable), location_id, source_row_key (unique, nullable for admin-added rows), name,
  bag_alias_text, base_unit (default `each`), package_unit (nullable), units_per_package (nullable int),
  packages_per_case (nullable int), threshold_qty_base (nullable int), threshold_source_text, order_amount_text,
  order_qty_packages (nullable int), suppliers (json, historical/unverified), item_numbers (json), refrigerated, controlled_substance,
  has_expiration (nullable), needs_review, review_reasons (json), source (json, the verbatim row), active, timestamps.
  A conversion factor is used only when it is explicit (imported from the text or entered by an authorized person). A null factor means
  "unknown", and anything that needs it is flagged, never guessed.
- `ems_supply_item_events`: append-only audit of threshold/packaging/location edits (who, when, before/after, reason).
- `ems_inventory_snapshots`: id, uuid, version (unique, increasing), kind (`physical_count` | `reconciliation` | `baseline`),
  status (`draft` | `final`), started_by/at, finalized_by/at, finalized_name, cutoff_at (the instant the snapshot represents),
  previous_snapshot_id, idempotency_key (unique, nullable), notes, timestamps. A `final` snapshot is immutable.
- `ems_inventory_snapshot_lines`: id, snapshot_id, supply_item_id, location_code, item_name (snapshots), quantity_base (nullable),
  entry (json: cases/boxes/each as typed), value_source (`counted` | `calculated` | `not_counted`), warnings (json). Unique (snapshot_id, supply_item_id).
- `ems_stock_movements`: id, uuid, supply_item_id, movement_type (`issued` | `received` | `adjusted`), quantity_base (signed int; issued < 0,
  received > 0), occurred_at, actor_user_id, actor_name, reason, request_id / request_line_id (nullable), supply_order_id (nullable),
  idempotency_key (unique), created_at. Append-only. Corrections are new compensating rows that reference the corrected row.
- `ems_supply_orders`: id, supply_item_id, status (`placed` | `received` | `cancelled`), packages, quantity_base (nullable if the
  factor is unknown), supplier_code, item_number, placed_by/at, received_by/at, notes. "Reorder recommended" is computed; an order
  exists only when a person records that it was placed.

## 5. Request lifecycle

`submitted → reviewed → prepared → partially_issued → issued → closed`, plus `cancelled`.

- Any Captain 5 / EMS Admin action on a `submitted` request records `reviewed` first (one event, no extra click).
- `prepared`: quantities staged (prepared_qty per line); nothing leaves stock.
- Issuing a line quantity writes one `ems_stock_movements` row (`issued`, negative) **only when** the line maps to exactly one chosen
  supply-room item; otherwise the issuance is recorded on the line/event with `stock_effect = unmatched` and reported in reconciliation.
  `issued_qty` is the sum of issuances. Over-issuing beyond requested is allowed only with a reason (recorded).
- `issued` when every stored line is `issued` or `not_issued` (with a reason). `partially_issued` otherwise once any quantity is issued.
- `closed` is set by a person with a reason (e.g. "Issued in full", "Close — No Action Required"); a closed request can be reopened
  (event). `cancelled` only by the requester before anything was issued.
- Corrections (wrong quantity issued, wrong item) are compensating movements + an event, never edits of history.
- Every transition is one DB transaction with a row lock on the request and an idempotency key from the client.

## 6. Stock rules

- **Requests never change stock.** Only confirmed issuance, receipt, adjustment, counts and reconciliations do.
- Current quantity of an item = the value in the latest `final` snapshot line + Σ movements with `occurred_at > snapshot.cutoff_at`.
  It is labeled with its basis: "Counted <date>" or "Calculated since count <date>". A `not_counted` or `calculated` value is never shown as a count.
- **Reconcile supplies issued since last inventory** (preview → confirm):
  previous snapshot value, issued, received, adjustments, calculated quantity, threshold, warnings (no previous count, unknown
  conversion, unmatched issuances, negative result). Confirm writes a `reconciliation` snapshot with `value_source = calculated`, its
  idempotency key = hash(previous snapshot id, last movement id included). Confirming twice returns the same snapshot; no movement is counted twice.
- **Physical count**: draft by location, resumable; finalize makes it immutable and attributes it to the member and time.
  Typed entries may be cases/boxes/each; conversion uses only explicit factors, otherwise the line is flagged and the base quantity is left null.
- **Reorder advisory**: active item with a known threshold whose current quantity is below it. Shows item, location, current quantity and basis,
  threshold (and the original threshold text), suggested order (the source's order amount, else threshold − current rounded up to whole packages
  when the factor is known), package unit, supplier code + item number (unverified). Items with an unknown threshold are listed separately for review.
- **Controlled substances** (e.g. the Rescue-only "NARCS BOX Y / N") are recorded on requests as yes/no only, excluded from supply-room stock and
  advisories, and shown with the note that narcotics are restocked through the department's controlled-substance procedure.
  Refrigerated items carry a "Refrigerated" tag.

## 7. Access

| Who | Can |
|---|---|
| Any Employee Portal member | submit requests; see own requests, messages and the PDF copy (Forms → records) |
| `captain5-view` (Captain5Access) | read Rescue Inventory and Supply Room |
| `captain5-act` | review/prepare/issue/close/message; counts; reconcile; record orders/receipts |
| `captain5-admin` or `admin.ems.manage` | edit thresholds, packaging units, locations; resolve review flags |
| `admin.ems.view` | Admin → EMS pages read-only, exports |
| `admin.ems.manage` | same actions as captain5-act from the admin panel |

`admin.ems.view` / `admin.ems.manage` are new direct permissions (AdminCapabilityGate prefix `admin.ems`), listed in the Admin capability editor.
Server-side checks on every endpoint; the UI only hides what the server refuses anyway.

## 8. Notifications

One notification per new request to the on-duty Captain 5 (Captain5Notifier recipients) and to `admin.ems.manage` holders, through the
existing `inspection_notification_deliveries` ledger (event key `ems:request:{id}:submitted`, unique per recipient) and
`InspectionEventNotification`. Messages notify the other side once per message. Status changes notify the requester only for issued/closed.

## 9. As built (backend, branch feat/ems-core)

Everything above is implemented as written except the points listed under "Deviations and additions". The services live in
`App\Services\Ems\*`, the models in `App\Models\Ems\*`, the JSON API in `routes/ems.php` (required from `routes/web.php` with one line).
The Filament pages and the React screens are built on these services and on the API below.

### 9.1 Deviations and additions (and why)

- **Admin access is not implied by `admin.access`.** `Captain5Access` admits every holder of `admin.access` as a plain "administrator", so
  every Admin-panel user already passes `captain5-view` and `captain5-act`. If `EmsAccess` simply OR-ed that with `admin.ems.*`, `admin.ems.view`
  could never be read-only. `EmsAccess` therefore ignores the bare "administrator" source and admits through Captain 5 only for real Captain 5
  reasons (officer rank, Captain 5 / EMS Captain seat, explicit allow, Fleet reviewer, super administrator). Admin-panel users get EMS access
  from their grants: `admin.ems.view` reads; `admin.ems.manage` (or the direct `captain5.admin` grant) reads, acts and reconfigures.
  The `admin.ems.*` grants only count together with direct `admin.access`. A person who is both an officer and has `admin.ems.view` keeps the officer's rights.
- **Inclusion in a snapshot is by movement id, not by `occurred_at`.** A snapshot stores `through_movement_id` (the newest movement it
  contains) and current quantity = the item's newest numbered final snapshot line + the sum of movements with `id > through_movement_id`.
  The contract's `occurred_at > cutoff_at` rule can miss or double count a movement when clocks tie or a receipt is backdated.
  `cutoff_at` is kept as the human-readable time. Every stock write first takes one lock row (`ems_counters.stock`), so a movement can never
  slip between a snapshot and its cutoff.
- **Per item baselines.** "Latest snapshot" is per item: the newest final snapshot line that has a number for that item. A count of one
  location therefore does not erase the calculated values of the others. Lines with a null number (`not_counted`) never override an older number.
- **`version` is assigned when a snapshot is finalized** (drafts have none), so version order is finalization order.
- **Extra columns:** `ems_inventory_snapshots.scope_location_code`, `.through_movement_id`; `ems_inventory_requests.cancelled_at/by`,
  `.cancel_reason`, `.last_activity_at`; `ems_inventory_request_lines.bag_key` (`airway-bag` | `als-med-bag`), `.flags`;
  `ems_inventory_request_events.idempotency_key`; `ems_stock_movements.corrects_movement_id`; `ems_supply_items.section_key`,
  `.qty_per_package_text`; `ems_supply_orders.placed_by_name`, `.cancelled_*`, `.idempotency_key`; new tables `ems_counters`
  (MIR number sequence and stock lock) and `ems_idempotency_keys`.
- **MIR numbers** come from the `ems_counters` row `mir` (the UPDATE holds the row lock until commit), so concurrent submissions on PostgreSQL
  and SQLite never share a number, and a rolled back submission leaves no gap.
- **Empty submissions.** Every typed field is optional, but a request with no requested item AND no note is refused ("Add at least one item to
  request, or write a note, before you send."), because it would give Captain 5 nothing to act on. The unit (or Detail / Special Event) is a pick,
  not typed text, and is required. A quantity of 0 or blank is dropped (never stored as 0); 1 to 999 whole numbers only. A Yes/No item
  stores the answer; "No" is stored as a line already marked not issued ("Answered No") so it never blocks completion.
- **Request status.** `issued` needs every line issued or marked not issued AND at least one unit issued. A request where every line is
  "not issued" stays `reviewed` (or `prepared`) until a person closes it with a reason.
- **Reconciliation** writes a line for every stock-tracked item (`calculated` when a previous number exists, otherwise `not_counted` with
  warning `no_previous_count`). If nothing was recorded since the newest final snapshot there is nothing to reconcile and that snapshot is
  returned (`unchanged: true`), so confirming twice never creates a second snapshot.
- **Corrections** target one issuance event: `new_quantity` (0..999), optional `supply_item_id` (wrong item, or attaching an item to an unmatched
  issuance) and a required reason. Stock effects are `adjusted` movements that point at the corrected movement; the original rows and events
  are untouched.
- **Unmatched issuances.** An issuance maps to stock only when the line has exactly one active, non-controlled supply item with
  the same catalog identity (or the person chose one) AND the line's unit is "each"/blank or equals the item's package unit with an explicit
  `units_per_package`. Otherwise the event is stored with `stock_effect = unmatched` and a note, and it is listed in the reconciliation preview.
  Controlled items and Yes/No lines have `stock_effect = none`.
- **Importer.** Same source sha256 + same extraction content is a no-op. Same sha256 with corrected extraction content upserts reference rows by
  their stable keys. A new sha256 becomes a new active version and the old one is deactivated but kept. Fields edited by a person (recorded in
  `ems_supply_item_events`) are never overwritten. The supply room source is recorded in `ems_source_forms` under key `captain5-supply-room`.
  `packages_per_case` is never imported (it is set by an authorized person).

### 9.1a Review fixes (second pass)

Data integrity
- **Count baselines are per line.** `ems_inventory_snapshot_lines.through_movement_id` (migration `2026_10_24_100400`) is the newest movement that existed when the
  person typed that count (stamped under the stock lock). Current quantity and reconciliation use the line's value (fallback: the snapshot's). Typing 10 on Monday,
  issuing 2 on Tuesday and finalizing on Friday gives 8. Re-sending an unchanged entry (draft autosave) keeps its baseline; retyping a number moves it.
  "Nothing to reconcile" now means no item that has a previous number has had a movement since it.
- **Reconciliation confirm is bound to the preview** (`expected_last_movement_id`, 409 `stale_preview`).
- **Movement keys** carry the person and request (`issue:u{user}:r{request}:{key}:...`, hashed when long). Replaying a key for a different item, type, quantity or line
  is refused (409 `idempotency_mismatch`). The `stock` lock row is recreated if missing and the lock fails loudly if it cannot be taken.
- **Limits.** Converted quantities, orders and adjustments are capped at 10,000,000 base units (422 `quantity_too_large`).
- **"boxes" counts** multiply by `units_per_package` only when the package unit is box, pack or bag; a case-packaged item flags `unknown_conversion`.
- **Unmatched issuances** list the current (corrected) quantity and include corrections that still end unmatched.
- **Importer:** a duplicate `item_key` / `row_key` in one document fails the whole import with a clear message; rows or items missing from a newer extraction are
  flagged `needs_review` ("Not in the latest source document") and never deleted; a re-import that changes a conversion or threshold writes a `Source import` audit row
  (these do not count as person edits).
- **History keys:** request lines, events, messages, snapshot lines and movements use `restrictOnDelete` to their parents; `corrects_movement_id` has a foreign key;
  `down()` of every EMS migration throws outside local and testing. `apparatus_id`, `submitted_by_*` (request) and `catalog_item_id`, `flags` (line) are frozen;
  `InventoryRequest` and `InventorySnapshot` use explicit `$fillable`.
- **Other:** a fully issued line cannot be marked not issued; the message idempotency uuid includes the request id; `ems:prune-idempotency` deletes keys older than 30 days
  (scheduled daily 03:50 Eastern).

Security
- Staff-addressed notifications (new request, member replies) are filtered through `EmsAccess::canView` when queued, so people who lost access stop receiving them.
- A Captain 5 deny override also removes access that came from an Admin grant (super administrators are unaffected).
- Named throttle buckets: `ems-member`, `ems-submit`, `ems-message`, `ems-cancel`, `ems-staff-read`, `ems-staff-write`, `ems-staff-config`. There is no daily submission quota.
  A member can send 5 messages in a row on one request before Captain 5 replies (429 `awaiting_reply`). At most one unread EMS message notification per request and person.
- Members see a `reason` in history only for not issued, closed and cancelled.
- Every query-string and body input goes through `EmsValidator` (arrays, over-long text and bad dates give a 422 in the standard envelope). Any unexpected failure returns the
  envelope with HTTP 500 (`server_error`) and is logged with route, user id and exception class, never the request body.
- The Files copy is only re-queued while the submitter still has a personnel profile. Session writes without a CSRF token get 419.

### 9.2 Member Files copy

On submission (after commit) the queued, `ShouldBeUnique` job `CreateMedicalInventoryRequestCopy` (queue `operational-forms`) calls
`MemberCopyService::ensure()`: ONE `operational_form_records` row (`form_type = medical_inventory_request`, status `completed`, title
`Medical Inventory Request MIR-000123 · R2`) owned by the submitting employee, and ONE `operational_form_documents` row holding a DomPDF letter PDF
of the immutable snapshot on the private disk (`operational-form-uploads/ems-requests/...`), streamed only by the existing owner-scoped
`employee.forms.api.documents.*` endpoints. If the record or file is missing, opening the request (`GET .../requests/{id}`) queues the same job
(on-demand regeneration; it only restores the file, never changes the content). The record is read-only: `PATCH`, `generate` and `complete`
on it return 409 `read_only`, and the F-ROC import only matches `froc_log_001_ff`. The Admin forms list labels it "Medical Inventory Request".

### 9.3 JSON API

Envelope: `{"ok": true, "data": ..., "meta": {...}}` or `{"ok": false, "error": {"code", "message", "fields"}}`. Responses are `Cache-Control: private, no-store`.
Every write takes an idempotency key (`Idempotency-Key` header or `idempotency_key` field, 8 to 100 characters of `A-Za-z0-9._:-`); a retry returns the
first result with `meta.replayed = true` and a failed attempt leaves no key behind. Lists are paginated (`page`, `per_page`; `meta` carries the totals).

Member (session, `EnsureEmployeeAuthenticated`, CSRF), base `/employee/forms/api/ems`, names `employee.forms.api.ems.*`:

| Method and path | Name | Purpose |
|---|---|---|
| GET `/form` | `form` | active airway + ALS forms with sections, items, min text, flags; unit directory; limits |
| POST `/requests` | `requests.store` | submit (`client_submission_id` uuid, `target{kind, apparatus_id?, event_assignment?}`, `member_note?`, `lines[{form, item_key, quantity? or flag?}]`); 201 new, 200 replay |
| GET `/requests` | `requests.index` | my requests |
| GET `/requests/{uuid}` | `requests.show` | lines, status, member-visible history, messages, Files copy status and links |
| POST `/requests/{uuid}/messages` | `requests.messages.store` | `body` |
| POST `/requests/{uuid}/cancel` | `requests.cancel` | only the requester, only before anything was issued; optional `reason` |

Staff (session `auth:web`, middleware `EnsureEmsAccess:view|act|configure`), base `/ems/api`, names `ems.api.*`:

| Method and path | Name | Needs |
|---|---|---|
| GET `/meta` | `meta` | view (permissions of the caller, statuses, units, close reasons) |
| GET `/requests` (`status` csv, `unit` id or `detail`, `target_kind`, `date_from`, `date_to`, `q`) | `requests.index` | view |
| GET `/requests/{uuid}` | `requests.show` | view |
| POST `/requests/{uuid}/review` `/prepare` `/issue` `/not-issued` `/close` `/reopen` `/correct` `/messages` | `requests.*` | act |
| GET `/supply/items` (`q`, `location`, `below_threshold`, `needs_review`, `controlled`, `active`), `/supply/items/{id}`, `/supply/locations` | `supply.items.*`, `supply.locations` | view |
| PATCH `/supply/items/{id}` (threshold, packaging, location, review flag; `reason` required) | `supply.items.update` | configure |
| GET `/supply/counts`; POST `/supply/counts`; PUT `/supply/counts/{uuid}/lines`; POST `/supply/counts/{uuid}/finalize`; DELETE `/supply/counts/{uuid}` (draft only) | `supply.counts.*` | view / act |
| GET `/supply/reconciliation` (preview, returns `last_movement_id`); POST `/supply/reconciliation` (confirm; body `expected_last_movement_id` from the preview is required, 409 `stale_preview` if stock changed since) | `supply.reconciliation.*` | view / act |
| GET `/supply/advisories` | `supply.advisories` | view |
| GET `/supply/orders`; POST `/supply/orders`; POST `/supply/orders/{id}/receive` `/cancel` | `supply.orders.*` | view / act |
| POST `/supply/adjustments` (signed units, reason) | `supply.adjustments.store` | act |
| GET `/supply/movements`, `/supply/snapshots`, `/supply/snapshots/{uuid}` | `supply.movements`, `supply.snapshots.*` | view |
| GET `/supply/snapshots/{uuid}/export/xlsx` and `/export/pdf` | `supply.snapshots.export.xlsx`, `supply.snapshots.export.pdf` | view (same files as the Admin page, `Cache-Control: private, no-store`) |
| GET `/statistics` (`apparatus_id`, `target_kind`, `date_from`, `date_to`) | `statistics` | view |

### 9.4 Statistics definitions (requests, not consumption)

All figures are read from the request tables and labeled "Requests, not consumption". They describe what was requested and what was issued
against requests; they do not measure what crews used. Dates are Eastern time; the date range is inclusive.

- `requests_submitted`: requests submitted in the range, in any status (cancelled included).
- `requests_cancelled`: requests the member cancelled.
- `requests_counted`: submitted minus cancelled. Every figure below uses only counted requests.
- `lines_requested`: items asked for (quantity of 1 or more, or a Yes answer). A "No" answer is not counted.
- `qty_requested`: sum of requested quantities (Yes/No answers are not quantities).
- `yes_answers`: items answered Yes (for example the narcotics box).
- `qty_issued`: sum of issued quantities on quantity lines, after corrections.
- `qty_outstanding`: requested minus issued (never below zero per item) for items not marked not issued, on requests that are neither closed nor cancelled; a Yes answer counts 1.
- `requests_per_week`: counted requests / weeks in the range, where weeks = days in the range / 7 (at least one day). No start date: from the first request; no end date: until now.
- `top_requested_items`: top ten by requested quantity (Yes counts 1), then by number of requests, then by name; with issued quantity and request count.
- `monthly_trend`: counted requests, requested and issued quantity by calendar month of submission; empty months in between are shown as zero.
- `detail_special_event`: counted Detail / Special Event requests, how many have no typed event text, and the typed events grouped case-insensitively.
- `by_unit`: requests, requested and issued quantity per unit (and Detail / Special Event).

### 9.5 Notifications

`EmsNotifier` (event keys `ems:request:{id}:submitted`, `ems:message:{id}`, `ems:request:{id}:status:{eventId}`) uses the same ledger and
queue pattern as `Captain5Notifier`. Recipients of a new request: the on-duty Captain 5 (`Captain5DutyRoster::notificationRecipients()`, else the Captain 5
seat holders, only people who still pass `Captain5Access`) plus EMS Admins (direct `admin.ems.manage` or super administrator, active account).
The actor is never notified. `redeliver()` is ready; `inspections:retry-notifications` must route keys starting with `ems:` to it (one line in
`RetryInspectionNotifications`, which is outside this branch's scope).

## 10. Admin -> EMS screens (as built, branch feat/ems-admin-ui)

Navigation group **EMS** (after Fleet, collapsed, no badges) in the Admin panel, three screens. Every screen reads through the services in
section 9 and every write calls the same service Captain 5 uses, as the signed-in person. Hiding a button is cosmetic: each Filament action and
each Livewire method re-checks `EmsAccess` (`view` to read, `act` to act, `configure` for item settings), and the service checks again.
The page-level check (`EmsPanelAccess`) answers a super administrator from loaded roles without queries, because the sidebar asks on every admin
page; everyone else goes through `EmsAccess` unchanged.

| Screen | Class | Notes |
|---|---|---|
| Rescue Inventory (sort 10, `/admin/rescue-inventory`) | `InventoryRequestResource` | List and view only; no create, edit or delete. Default filter: open statuses. Header actions Export Excel and Export PDF use the current filters and search. The view page offers Review, Prepare quantities, Issue, Mark not issued (per line), Close, Reopen, Correct an issuance and Message the member. Close reasons are `RequestWorkflowService::CLOSE_REASONS` plus a typed reason. |
| Medical Supply Room (sort 20, `/admin/medical-supply-room`) | `MedicalSupplyRoom` | Tabs: Stock (Filament table, item details and settings, controlled items listed apart), Counts, Reconcile, Reorder (advice, orders), Movements, Snapshots (view, Excel, PDF). |
| Medical Data / Statistics (sort 30, `/admin/medical-statistics`) | `MedicalDataStatistics` | Built only from `EmsStatistics::report()`; each metric shows its written definition. The files are built from the same `StatisticsView` as the screen. Default range: last 90 days, Eastern time. |

- **Idempotency.** Each modal chooses its idempotency key when it opens, so a double click or a retry after a refusal is one write. A refusal
  (`EmsException`) shows its plain message and keeps the modal open.
- **Reconciliation.** The page remembers the `last_movement_id` of the preview it is showing and passes it to `ReconciliationService::confirm()`
  as a third argument. A `stale_preview` refusal refreshes the preview with a friendly message.
- **Exports.** `App\Services\Ems\Exports\ReportWriter` writes workbooks with explicit cell types (text is always a string cell, counts are numbers) and
  puts a leading apostrophe on text that starts with `=`, `+`, `-`, `@`, a control character or the fullwidth forms of those. PDFs are DomPDF
  landscape with repeated header rows; the request list PDF is cut at 1,500 rows and says so (the workbook always has every row).

## 11. Captain 5 tabs (as built, branch feat/ems-captain5-tabs)

The Daily Checkout app's Captain 5 page (`/daily/captain-5`) gains two tabs built only on the staff API above. No second ticket engine and no new tables.

- **Rescue Inventory** (`?tab=rescue`; `&request=<uuid>` opens that request; `&view=stats` opens Unit statistics). Open = submitted, reviewed, prepared or partly issued; chips Issued, Closed (includes cancelled) and All. The tab badge is `meta.total` of a one-row list with the four open statuses, refreshed on focus and once a minute while the page is visible. `EmsLinks::staff()` produces exactly this URL.
- **Supply Room** (`?tab=supply`; `&section=stock|counts|reconcile|reorder|movements|snapshots`).
- **Who sees the tabs.** Only people for whom `GET /ems/api/meta` says `permissions.view`. The Captain 5 page also admits people the EMS API does not (an Admin Panel user with no EMS grant), so the tabs are never shown from the page gate alone. A deep link keeps its tab while access is being checked and falls back to Today only after a 403 or `view: false`.
- **Writes.** Every write sends an `Idempotency-Key`: one key per user action, sent again unchanged on a retry of the same content and replaced after the server confirmed. A 409 refreshes the sheet and keeps what was typed; `stale_preview` shows the message and reloads the reconciliation preview.
- **Corrections** start from the quantity an issuance stands at now, found by replaying `issue_corrected` events (`payload.corrects_event_id`, `new_quantity`) over the original `issued` event.
- **Downloads.** Snapshots download through the two export routes in section 9.3. They are plain links, outside the Daily service worker's `/daily/` scope, and the worker never stores `/ems/api/` responses.
- **Tests.** `tests/Node/ems-captain5-logic.test.mjs` (pure helpers), `tests/e2e/captain5-ems.spec.ts` (mocked API, project `captain5-ems-chromium`), `tests/Feature/Ems/EmsSnapshotExportApiTest.php` and `EmsRetryNotificationsTest.php`.
