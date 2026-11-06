# Four medical inventory forms (form families)

Status: contract for the November 2026 release that splits the single Medical Inventory Request into four forms.
`docs/ems/ems-inventory-contract.md` stays the source of truth for tables, the ledger and access, and
`docs/ems/captain5-medical-inventory-workflow.md` for the Friday/Monday cycle. This file records what the families add.

## 1. The four families

One authoritative PHP definition, `App\Services\Ems\FormFamilies`, owns this table. Nothing else hard-codes a family's lists;
the frontend reads them from the API.

| Family key (stored) | Title (shown) | Short | Deep-link `form=` | Lists (source form keys, in tab order) | Default unit |
|---|---|---|---|---|---|
| `als-transport` | ALS Transport Inventory Form | ALS Transport | `als-transport-inventory` (legacy `medical-inventory-request` also opens it) | `airway-bag`, `als-med-bag`, `als-transport-vehicle`, `handtevy` | none (member picks) |
| `als-non-transport` | ALS Non Transport Inventory Form | ALS Non Transport | `als-non-transport-inventory` | `als-non-transport`, `handtevy` | none (member picks) |
| `fire-boat-6` | Fire Boat 6 Medical Inventory Form | Fire Boat 6 | `fire-boat-6-medical-inventory` | `fire-boat-6-als` | the one apparatus whose `DailyCheckoutChecklistResolver::checklistTypeFor()` is the Fire Boat 6 template; none if zero or several |
| `bls-detail` | BLS Detail Medical Inventory Form | BLS Detail | `bls-detail-medical-inventory` | `bls-detail-bag` | Detail / Special Event |

- A request belongs to exactly one family. `ems_inventory_requests.form_family` (string 40, nullable, indexed) stores it; the additive
  migration backfills existing rows to `als-transport`, and every reader treats `null` as `als-transport` (a rolled-back image writes null).
- The snapshot gains `form_family` and `form_family_title` (schema name unchanged, `mbfd.ems.mir_snapshot.v1`; the fields are additive).
  Old snapshots have neither and are shown as ALS Transport, titled "Medical Inventory Request" where the historical title is printed.
- Numbers stay `MIR-000123` (one counter for all families). Family identity is never derived from the number, the title or a label.
- `handtevy` is one source list used by two families. A line's identity is still `(request, bag_key, item_key)`; the request's family
  says which form it came from. Handtevy items are a separate list from the bags, so nothing is counted twice.
- The member picks the unit on every family; a default only preselects. Fire Boat 6 never needs a vehicle number.

## 2. Source lists

| Source form key | Tracked file | Source PDF |
|---|---|---|
| `airway-bag` | `airway_bag_inventory.json` | unchanged (the 2026-11-02 attachment is byte-identical, sha256 017c463c…) |
| `als-med-bag` | `als_med_bag_inventory.json` | unchanged (the "new" attachment is byte-identical, sha256 65eab0e2…) |
| `als-transport-vehicle` | `als_transport_vehicle_inventory.json` | unchanged (photo of the April 2021 sheet) |
| `handtevy` | `handtevy_inventory.json` | `handtevy_inventory.pdf` |
| `als-non-transport` | `als_non_transport_inventory.json` | `als_non_transport_medical_inventory.pdf` (Revised 1-2024) |
| `fire-boat-6-als` | `fire_boat_6_als_inventory.json` | `fire_boat_6_medical_inventory.pdf` (revised 7/2025) |
| `bls-detail-bag` | `bls_detail_bag_inventory.json` | `bls_detail_bag_medical_inventory.pdf` (revised 2020) |

All use schema `mbfd.ems.source_form.v1`. Additive fields the importer accepts (anything else in `flags` is dropped):

- item `flags`: existing `detail_airway_bag_only`, `rescue_only`, `refrigerated`, `controlled_substance`, `fill_if_text`, `fill_if_qty`;
  new `location_text` (where the item is kept, as printed, e.g. "In Handtevy", "Placed in BVM bag"), `qualifier_text` (a printed
  condition such as "Detail Airway Bags Only"), `color_band` (printed color/age group, e.g. "Grey", "Yellow (2 years)"), `cadence`
  (`monthly_first_tuesday` only), `search_terms` (up to 10 extra search words; search only, never identity).
- section `note` (a printed note shown under the section heading) and section `cadence`.
- `source.printed_header` (the header exactly as printed) and `source.review_notes` (provenance conflicts for a person to settle).

AMOUNT is `min_text`/`min_qty`/`min_unit`; "Fill if <" is `flags.fill_if_text`/`fill_if_qty`; NEED is what the member types. Blank is not zero,
and no par or threshold ever becomes a request by itself. `source_label` is always verbatim (misspellings included); `label` may be a cleaned
display name that never changes the product, dose, size or pack.

## 3. Member API (base `/employee/forms/api/ems`)

- `GET /form` with no parameter answers exactly as before for the ALS Transport family (so a page loaded before this release keeps working),
  plus the fields below. `GET /form?family=<key>` answers for that family; an unknown key is 422 `unknown_family`.
  - `family`: `{key, title, short_title, form_key, description, bag_keys: string[], default_target: 'detail' | 'unit:<id>' | null}`
  - `families`: `[{key, title, short_title, form_key, description}]` in the table's order.
  - `forms[]` are that family's lists. Each section adds `note` and `cadence` (null when absent); each item adds `source_label`.
- `POST /requests` accepts optional `family` (absent = `als-transport`, so queued requests from older pages still land correctly).
  Every line's `form` must be one of that family's lists (422 `unknown_item` otherwise). With `revises`, the family is the returned
  request's family; a different `family` is 422 `family_mismatch`.
- `GET /requests` rows and `GET /requests/{uuid}` add `form_family`, `form_family_title`, `form_family_short`.
- `MAX_LINES` becomes 900 (the ALS Transport family lists about 500 rows); validation stays bounded.

## 4. Staff API (base `/ems/api`)

- Request rows and detail add `form_family`, `form_family_title`, `form_family_short`.
- `GET /requests?family=<key>` filters (null rows count as `als-transport`). `GET /meta` adds `families: [{key, title, short_title}]`.
- Reconcile preview `forms[]` and `lines[]` add `form_family_short`. Reconciliation itself is unchanged and family-blind: lines resolve by
  person choice → catalog supply link → single catalog match, as before; mixed-family batches are allowed.
- `GET /statistics` adds `by_family: [{family, title, requests, qty_requested, qty_issued}]` and accepts `family`.

## 5. Links

- `EmsLinks::memberForm(?string $family = null)` returns `/employee/forms?form=<form_key>`; no argument keeps the legacy
  `?form=medical-inventory-request` URL. `EmsLinks::MEMBER_FORM_KEY` keeps its value.
- `OperationalForms::emsDeepLink()` returns `{view, request, family}`: any family `form_key` (or the legacy key, meaning ALS Transport) opens that form.
- Employee Portal → Forms shows four cards, Home → Rescue Forms shows four links, and Station 6's page (station number 6 only) links to the Fire Boat 6 form.
- A returned form opens in its own family. Notifications name the family title.

## 6. Member copy

The Files record keeps `form_type = medical_inventory_request`. New titles read `<Family title> MIR-000123 · <unit>`; the PDF heading is the
snapshot's family title (old snapshots keep "Medical Inventory Request"). Existing records are never retitled.

## 7. Device drafts and outbox

- The ALS Transport draft keeps the legacy IndexedDB key (the employee id), so a draft typed before the release opens in ALS Transport and a
  rolled-back page still finds it. Other families use `<employee id>|<family key>`. Stored version stays 1; `family` is an optional field.
- Outbox payloads carry `family`; an entry without it is ALS Transport. Switching forms never moves entries between families.

## 8. Release order

Deploy the image, run the migration (additive), then `php artisan ems:import-sources --dry-run` (the three existing lists must report
`unchanged`; the four new lists report `new_version`), then run it for real. Until the import runs, the three new families answer
"not available yet" and ALS Transport works as before. Rollback is the previous image; never reverse the migration or delete imported rows.

What a rollback to the image before this release looks like (data stays safe; the column is nullable and the old code ignores it):
- Requests sent on the three new forms stay in the database, but the old screens show them without a form name: Captain 5 shows the raw list
  key (for example `fire-boat-6-als`) as the group heading, and a regenerated Files copy is titled "Medical Inventory Request".
- A queued request from a new form that carries lines is refused by the old server (it does not know the new list keys) and stays in the
  member's outbox until the release is restored; one with only a note would be accepted as an ALS Transport request.
- Re-deploying this release afterwards needs nothing else: the lists, requests and column are already there.
