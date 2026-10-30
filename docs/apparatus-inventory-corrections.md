# Apparatus Inventory Corrections

Status: implementation contract (2026-10-23). Branch `feat/apparatus-inventory-corrections`.
Scope: Daily Checkout (`/daily`), admin (`/admin`), employee portal (`/employee`).
Take-Home Vehicle Inspections are out of scope and are not touched.

Guiding rule: members report what the checklist gets wrong; Logistics decides once; the
decision changes the next issued checklist for exactly the units it was meant for, with a
full audit trail and without touching history.

## 1. Where the inventory lives (as found at 216c9d84)

- The authoritative per-apparatus equipment list is the Daily Checkout checklist resolved by
  `App\Services\DailyCheckoutChecklistResolver`: a deploy-managed template file in
  `storage/checklists/{type}_checklist.json` chosen by configured template, family rule
  (engine / ladder / rescue) or identity override (E2 -> `engine2`, L3 -> `ladder3`).
  `checklist_version` = sha256 of the canonical JSON; submissions must match it.
- Production (2026-10-23): engine template = E1, E3, E4 (+ reserves E11, E21, E31, not on Daily
  Checkout); `engine2` = E2; `ladder1` = L1 (+ L11); `ladder3` = L3; `rescue` = all rescues;
  `fireboat6` (schema v2, session snapshots) = FB6.
- `ApparatusInventoryAllocation` is supply stock, not the checklist. No Google Sheet is read.
- Template item ids are positional (`front_cab-item-3`) and stable only inside one template:
  `engine` and `engine2` share 40 ids with the same name, 40 ids with different names.
- Defect continuity matches by `compartment_key`+`item_key`, then by names plus the
  server-owned aliases `legacyCompartmentNames` / `legacyItemNames` (InspectionPaperFields).

## 2. Architecture

One authoritative source stays authoritative: **effective checklist = baseline template +
ordered active approved changes for that apparatus** (a DB overlay). Template files are never
edited at runtime. With no active change the resolver output is byte-identical to before, so
deploying this feature changes no `checklist_version`.

```
template JSON --(resolver)--> baseline --(InventoryOverlay: active changes by id)--> effective checklist
                                                                                 \-> checklist_version (sha256)
```

### 2.1 Tables (all additive)

| Table | Purpose |
|---|---|
| `apparatus_inventory_corrections` | member report + review lifecycle (one row per report) |
| `apparatus_inventory_correction_events` | append-only audit trail and two-way thread (member / internal) |
| `apparatus_inventory_change_sets` | one approval applied to one or more units; idempotency key; per-unit results |
| `apparatus_inventory_changes` | the overlay: one operation on one apparatus; `active`/`superseded`/`reversed` |
| `apparatus_checklist_revisions` | snapshots of effective checklists retired by a change (grace for in-flight work) |

Correction status: `submitted` -> (`clarification_requested` <-> `submitted`) -> `applied` | `rejected`;
`applied` -> `reversed`. `lock_version` increments on every state change (optimistic concurrency).

### 2.2 Item identity

- `item_key` = the checklist item id. It never changes when an item is renamed, re-described,
  re-quantified or moved. Added items get `inv-c{correctionId}` (same key on every unit the
  same correction is applied to).
- `equipment_key` = cross-unit identity used by the "all apparatus carrying this item" scope:
  the item's explicit `equipmentKey` when present (added items: `eq:c{correctionId}`), otherwise
  `name:` + the normalized **baseline template** name (lower-case, punctuation removed, single
  spaces). Exact equality only; never fuzzy. A unit with two items of one key is `ambiguous`.
  A rename pins the baseline-derived key onto the item (`equipmentKey`), so identity survives.
- Defect history survives because moves append the old compartment name to the item's
  `legacyCompartmentNames` and renames append the old name to `legacyItemNames`.

### 2.3 Operations (applied in change id order; later wins per field)

| op | payload | effect |
|---|---|---|
| `remove` | - | item removed; an emptied compartment is dropped from the effective list |
| `modify` | any of `compartment_id`, `name`, `expectedQuantity`, `manufacturer`, `model`, `description` | fields set (null clears optional fields); move appends to the destination |
| `add` | `compartment_id`, `name`, optional `expectedQuantity`, `manufacturer`, `model`, `description` | new checkbox item |

Every change is validated by applying it and running the resolver's own schema validation
(`hasUnambiguousSubmissionSchema`). At resolve time a change that no longer applies (template
changed under it: item gone, destination gone, or the item id now carries a different
`equipment_key` = `identity_mismatch`) is skipped and logged; the checklist stays usable
(`overlay_conflicts`). If nothing applies, the template is served unchanged with its own hash.

An earlier active `modify` on the same apparatus+item whose touched fields are a subset of a new
`modify`'s fields (or any earlier `modify` when the new op is `remove`) is marked `superseded`,
except moves and renames: they stay active so every old compartment/name remains an alias and
defect continuity survives chained moves and renames (later change still wins per field).

### 2.4 Propagation scopes

| scope | candidates | default selection | authority |
|---|---|---|---|
| `unit` (default) | the reporting apparatus | it | reviewer |
| `family` | every usable apparatus of the same family (`engine*`, `ladder*`, `rescue`, `fireboat*`) | Daily Checkout units that are eligible | propagation authority |
| `equipment` | every usable apparatus whose effective checklist carries the same `equipment_key` | eligible Daily Checkout units | propagation authority |

Per-unit matching: on the same template, same `item_key` with same `equipment_key`; otherwise only
the single item with the same `equipment_key` (variant template ids are positional). Destination
compartment: on the same template the same id; on a variant only the single compartment with the
same normalized name (Ladder 1 and Ladder 3 reuse `b_comp_N` for different positions), else
`destination_missing`. A removal's preview shows how many open issues on that item stay open. Per-unit verdicts (`eligible` or an exclusion):
`item_not_found`, `ambiguous_item`, `destination_missing`, `invalid_result` (name clash),
`no_change` (already as requested), `unit_override` (another unit-scoped active change on this
item; excluded by default, may be included), `not_daily_checkout` (reserve; may be included),
`checklist_unavailable`. `add` cannot use `equipment` scope.

Reviewer authority = `InspectionReviewers::canReview`. Propagation authority (multi-unit) =
reviewer AND role `super_admin`, `admin` or `logistics_admin` (Fleet-only reviewers apply to
one unit). Server re-checks everything; the form's unit list is advisory.

### 2.5 Apply (one transaction)

1. Lock the correction; require status `submitted|clarification_requested` and the
   `lock_version` the reviewer saw (else "changed by someone else").
2. Lock candidate apparatus rows in ascending id (same lock inspections take).
3. Recompute the plan; the reviewer's `preview_fingerprint` (sha256 of every selected unit's
   effective `checklist_version` + match) must still match (else "inventory changed; review again").
4. Selected ids must be a subset of the includable candidates and contain the source unit.
5. Per unit: snapshot the current effective checklist into `apparatus_checklist_revisions`
   (retired now), insert the change, mark superseded changes, re-resolve and validate, snapshot
   the new revision. Any failure throws: nothing is written, no "applied" event, no notice.
6. Change set row (unique `idempotency_key`: a double click/replay returns the first result),
   correction -> `applied`, events `approved` + `applied` (with per-unit results).
7. Notifications are ledger rows in the transaction and sent after commit; a delivery failure
   never touches the committed inventory.

Reverse: refused while a later active change builds on an affected change (edits an item it added,
removes the item, or sets an overlapping field): "reverse #N on <unit> first". A change already
superseded is reversed without effect. Changes it had superseded come back (or stay superseded
by its successor). The reversal is refused if it would introduce a new overlay conflict.

### 2.6 In-flight work

- Checklist GET returns the effective checklist plus `checklist_base_version` (baseline hash)
  and `pending_corrections` (outside the hashed checklist).
- Submission: a `checklist_version` that is not current but matches an
  `apparatus_checklist_revisions` row of this apparatus retired within
  `daily_checkout.inventory_revision_grace_hours` (default 168) is validated and processed
  against that snapshot (queued offline submissions and open tabs keep working). The
  inspection keeps the version it was performed against.
- PWA draft restore: when a saved draft's `checklist_base_version` equals the current one, the
  only difference is the overlay, so answers are carried over by item id; items whose name or
  expected quantity changed, and new items, need a fresh observation. Otherwise the existing
  "different checklist version" behaviour is unchanged.
- History (inspection results, checklist evidence, PDFs, defects) is never rewritten.

## 3. Member API

`POST /api/public/apparatuses/{id}/inventory-corrections` (auth:sanctum, canonical member, throttle 30/min)

```json
{
  "client_submission_id": "uuid",
  "checklist_version": "64 hex (version the member is looking at)",
  "reason": "no_longer_carried|moved|replaced|wrong_quantity|wrong_description|new_item|other",
  "compartment_id": "front_cab",
  "item_id": "front_cab-item-5 (required except new_item)",
  "proposal": {
    "to_compartment_id": "comp_b2 (moved: this or to_location_text)",
    "to_location_text": "free text, max 200",
    "name": "max 160 (required for new_item)",
    "manufacturer": "max 120", "model": "max 120", "description": "max 500",
    "quantity": 1
  },
  "note": "max 1000 (required for other)",
  "photo": "optional data:image/...;base64",
  "inspection_draft_id": "optional uuid of the Daily Checkout draft"
}
```

- 201 `{ "id", "reference": "IC-000123", "status": "submitted", "duplicate": false }`;
  same `client_submission_id` + same payload -> 200 with `"duplicate": true`;
  same id + different payload -> 409 `INVENTORY_CORRECTION_REPLAY_CONFLICT`.
- 422 when the item/compartment is not on the checklist version given (current or a grace revision).
- Inspection submission accepts optional `inventory_draft_id` (uuid); on store, the member's
  corrections with that draft id on that apparatus are linked to the inspection.

Checklist GET additions: `checklist_base_version`, `pending_corrections: [{ id, compartment_id,
item_id, reason, reason_label, status, reported_at, mine }]`. Items may carry `manufacturer`,
`model`, `description`, `equipmentKey`, `legacyCompartmentNames`, `legacyItemNames`.

## 4. Admin and member UI

- Admin: `Fleet > Inventory corrections` (filtered queue, open by default; badge = open count),
  correction page with before -> after, member proposal vs final decision, impact preview,
  approve / modify-and-approve / reject (member-visible reason) / request clarification / reply /
  internal note / reverse. Linked from the canonical inspection page and the apparatus profile
  (corrections + effective inventory changes).
- Member: "Report inventory correction" inside an expanded checklist item and "Report an
  unlisted item" per compartment (Daily Checkout, offline queue). Pending marker on items.
  Status, questions, replies, decisions and the applied result on the employee portal
  (`My Vehicle Inspections` > Inventory corrections > correction page, reply box).

## 5. Notifications (InspectionNotificationDispatcher)

`inventory_correction_submitted` -> reviewers; `inventory_correction_update` -> reporter
(clarification, Logistics reply, approved/applied, rejected, reversed); `inventory_correction_reply`
-> reviewers in the thread (else all). Ledger keys `correction:{id}:...`; retried by
`inspections:retry-notifications`.

## 5a. Limits (abuse resistance)

- Per member: at most 10 open corrections per apparatus and 40 reports per 24 h; a repeat of an
  open report (same item or unlisted name, same reason) returns the existing report.
- Member text in names/descriptions is plain text (`<` and `>` refused); notifications strip markup.
- Checklist markers: at most the 100 newest open reports; another member's report is shown only as
  "pending" (no id, reason, time or name). Member replies: 50 per correction; idempotency keys are
  matched only for the same actor and correction, after authorization.
- Someone else's correction page or photo is a 404.

## 6. Rollback

All migrations are additive. Rollback = redeploy the previous image. Old code ignores the new
tables, so every unit falls back to its baseline template (approved changes stop showing until
re-deployed; nothing is lost). `down()` drops only the new tables.

## 7. Equipment Change Requests (admin home card and decision flow, 2026-10-30)

"Equipment change request" is the administrator's name for an inventory correction; there is one
record and one set of tables. Nothing new is stored and no migration is involved.

- **Member side.** The Daily Checkout "Report inventory correction" form is the entry point. A report
  is `submitted` (shown as **Pending approval**) and never changes the checklist. It records the
  apparatus (`apparatus_id`), the equipment (`item_key`, `equipment_key`), the old value
  (`target_snapshot`, now including the destination compartment's name for a move), the proposed value
  (`proposal`), the submitter and the optional note. Final states are shown as **Approved** and **Rejected**.
- **Nothing is dropped.** A repeat of the same report (same item, reason, proposal and note) still merges into
  the open one. A report that proposes something different is a new request, so Logistics always reviews what the
  member last asked for.
- **No automatic decisions.** Only `InventoryCorrectionService::reject()` / `approve()` set a final status, and both
  require a reviewer (`InventoryCorrectionAccess::canReview`). A refused send (HTTP 4xx) is not a decision; the
  Daily Checkout says "Your report was not sent ... Nothing was rejected: Logistics has not seen it".
- **Admin home card.** `EquipmentChangeRequestsWidget` (Logistics Dashboard, not on the wall display): the pending
  count, the newest five with before -> after, the reporter, and a link to the exact request. It only reads;
  decisions are made on the request page (`InventoryCorrectionResource` view), which opens with a
  "What would change" before/after table, the apparatus ID and equipment ID.
- **Decisions.** Approve = one transaction (section 2.5) that adds an overlay change for the selected apparatus only,
  re-resolves and verifies the next checklist, and snapshots the replaced one. Reject = status change only; the
  message to the member is optional (a bare rejection still notifies "The checklist was not changed"). Both require the
  `lock_version` the reviewer saw, are idempotent per key, and a second administrator is told it was already decided.
- **Notifications** reuse `inventory_correction_submitted` (reviewers) and `inventory_correction_update` (member).
