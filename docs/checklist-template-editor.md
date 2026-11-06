# Daily Checkout Checklist Template Editor

Status: implementation contract (2026-11-06). Branch `claude/awesome-ritchie-ilqfoi`.
Scope: the Daily Checkout apparatus checklists (`/daily`) resolved by
`App\Services\DailyCheckoutChecklistResolver`. Take-Home Vehicle Inspections, station inventory,
TRT inventory and supply stock are out of scope and are not touched.

Goal: Logistics edits the equipment on a vehicle checklist (items, tools, quantities, brand /
manufacturer, model, description, notes) and where it lives (compartments, order, moves) from the
admin panel. No code change, no deploy. Every change is a reviewed, versioned, audited publication
that keeps working with everything that already depends on the checklist.

## 1. Constraints this design keeps (as found at 7d2c595)

| Dependency | What it relies on | How the editor keeps it |
|---|---|---|
| Resolver / `checklist_version` | sha256 of canonical effective checklist | With no published version the resolver reads the file exactly as before: byte-identical output, no hash changes on deploy. |
| Submissions, offline queue, open tabs | a submitted `checklist_version` must be current **or** a grace revision (`apparatus_checklist_revisions`, 168 h) | Publishing snapshots every affected unit's current effective checklist as a retired revision first (the same protocol an applied inventory correction uses), so in-flight work is accepted. |
| Inventory-correction overlay (`apparatus_inventory_changes`) | item ids (`item_key`) and `equipment_key` (explicit `equipmentKey` or `name:` + normalized name) | Item ids and compartment ids are immutable in the editor. A rename pins the previous derived identity in `equipmentKey`, so active changes do not hit `identity_mismatch`. Overlay conflicts that a publish would cause are shown before publishing. |
| Defect continuity (`InspectionPaperFields::equipmentDefinitions`) | ids, then names + `legacyCompartmentNames` / `legacyItemNames` | Renames append the old name to `legacyItemNames`; moves append the old compartment to the item's `legacyCompartmentNames`; compartment renames append to the compartment's `legacyCompartmentNames`. |
| Captain 5 medical scope (`MedicalScope`) | item ids of the **reviewed repository file** (`reviewed_version` hash in `config/captain5_medical_scope.php`) | `MedicalScope::baselineTemplate()` keeps reading the repository file on purpose. Because ids are never reused, existing classifications stay correct; new items get new ids and appear in the admin Medical Scope review as "new item" (the resolver feeds effective checklists to `MedicalScopeReview`). |
| PWA diagrams (`apparatusBlueprints.ts`) | compartment ids | Ids never change. A new compartment has no diagram zone and appears in the PWA's "other areas" list (existing behaviour). |
| PWA saved drafts | `checklist_base_version` | Same as a template deploy today: an older saved draft stays on the device and needs officer review. The publish dialog says so and shows how many units are affected. |
| Fire Boat 6 (schema v2) | sessions snapshot `checklist_hash` | Validated by the resolver's own v2 schema check; sessions keep their snapshot. `fields`, `recurringTasks`, `template_id` and `template_version` are preserved untouched. |
| Officer fields, daily schedule, `sourceDocument`, `title` | unchanged | Not editable here; preserved byte-for-byte. |

## 2. Data model (additive migration)

`daily_checkout_checklist_template_versions`

| column | notes |
|---|---|
| `id` | |
| `checklist_type` string(40), indexed | a configured `DailyCheckoutChecklistTemplate` value (not `pending`) |
| `status` string(20) | `draft` (at most one per type), `published` (at most one per type), `retired` (was published, then replaced or reverted), `discarded` |
| `version_number` unsigned int nullable | assigned on publish: max published number for the type + 1 |
| `checklist` json | full template JSON |
| `checklist_hash` char(64) | `DailyCheckoutChecklistResolver::versionOf($checklist)` |
| `based_on_hash` char(64) | hash of the live baseline when the draft was started |
| `based_on_version_id` nullable | the published version the draft started from (null = repository file) |
| `source_file_hash` char(64) nullable | hash of the repository file at draft start (file drift detection) |
| `change_summary` text nullable | required to publish |
| `lock_version` unsigned int default 0 | optimistic concurrency for draft saves |
| `created_by_user_id`, `updated_by_user_id`, `published_by_user_id`, `retired_by_user_id` | nullable FK users, null on delete |
| `published_at`, `retired_at`, `discarded_at` | nullable |
| timestamps | |

`daily_checkout_checklist_template_events` (append-only audit trail)

`id`, `checklist_type`, `template_version_id` nullable, `action` (`draft_started`, `draft_saved`,
`draft_imported`, `draft_restored`, `draft_discarded`, `published`, `reverted_to_file`),
`user_id` nullable, `details` json nullable, `created_at`.

## 3. Runtime resolution

```
repository file ─┐
                 ├─ ChecklistTemplateRepository::baseline(type) ─▶ baseline ─▶ InventoryOverlay ─▶ effective checklist
published row ───┘   (published row wins; else file, exactly as before)
```

- `ChecklistTemplateRepository` (scoped binding: flushed per request and per queued job) looks up
  the published row for the type on the apparatus's connection. Table existence is checked with
  `Schema::hasTable` (safe inside a Postgres transaction) and remembered on the instance; a
  missing table (candidate database before migration) means "file".
- The resolver output shape does not change. Load errors keep their names (`missing`,
  `unreadable`, `invalid_json`, `invalid_schema`); `path` stays the repository file path.
- Other readers of the template files: `EquipmentRuleService::checklistItemNames()` (support
  triage catalog) reads the live baseline through the repository. `MedicalScope::baselineTemplate()`
  stays on the file (see section 1).

## 4. Editing rules (server-enforced in `ChecklistTemplateEditor`)

Editable item fields: `name` (required, ≤160, unique within its compartment), `expectedQuantity`
(blank or integer 1–999), `manufacturer` (brand, ≤120), `model` (≤120), `description` (≤500),
`note` (≤500), location (compartment) and order. Editable compartment fields: display name (the
existing `title` or `name` key, whichever the template uses; unique, ≤120) and order.

- Every other key on an item or compartment (`inputType`, `snipeit_match`, `valueRequired`,
  `status`, `equipmentKey`, legacy aliases, …) is preserved from the draft; the form never carries it.
- Existing item and compartment ids never change and are never reused. Positional ids
  (`{compartment}-item-{n}`, items without an explicit id) are materialized at their current
  position when a draft starts, which is the id they already resolve to.
- New items get `{compartment_id}-i{8 random [a-z0-9]}` (unique against every id ever seen for the
  type: file, all versions), `inputType: checkbox`. New compartments get a slug id
  `custom_{slug}` (unique likewise).
- Aliases are computed against the draft's base (the live baseline when the draft started):
  renamed item → base name in `legacyItemNames` and `equipmentKey` pinned to the base identity;
  moved item → base compartment name in the item's `legacyCompartmentNames`; renamed compartment →
  base name in the compartment's `legacyCompartmentNames`. Renaming back removes nothing that the
  base already had.
- A compartment can be deleted only when empty. Removing an item removes it from the next issued
  checklist; open defects stay open (the preview counts them).
- The result must pass `DailyCheckoutChecklistResolver::isSubmittable()` plus the field rules above.

## 5. Publish protocol (one transaction)

1. Lock the draft (`lock_version` must match what the editor saw) and the type's published row.
2. The live baseline hash must equal the draft's `based_on_hash` (else "this checklist was published
   by someone else; start a new draft").
3. Validate. Recompute the impact preview; its `fingerprint` (sha256 over every affected unit's
   current and resulting `checklist_version` and conflicts) must equal the one the admin confirmed.
4. Lock every apparatus currently resolving to the type, ascending id (same order inspections use).
   Per unit: resolve, `ChecklistRevisionStore::remember(..., retire: true)`.
5. Previous published row → `retired`; draft → `published` with `version_number`, summary,
   publisher. Flush the repository.
6. Per unit: resolve again; it must be usable; `remember(..., retire: false)`.
7. Audit event `published` with the diff counts and per-unit before/after versions.

"Revert to repository file" uses the same protocol with no new published row.
"Restore version N" copies that version's checklist into a new draft (published only through the
normal publish flow).

## 6. Access

- View (read-only templates, history, diffs): `InventoryCorrectionAccess::canReview()`.
- Edit drafts, import, publish, revert, discard: `InventoryCorrectionAccess::canPropagate()`
  (super_admin, admin, logistics_admin reviewers): a template change reaches every unit on it.

## 7. Admin UI

`Fleet > Checklist templates` (Filament resource on the version model).

- Index: one card/row per template with source (repository file / published vN), compartments,
  items, units using it, open draft (by, when), last publication (by, when, summary), file-drift flag.
- Editor `/admin/checklist-templates/{type}`: compartments as collapsible, reorderable sections;
  items as reorderable rows (name, quantity, brand, model, description, note, location select).
  Header actions: Save draft, Preview & publish (diff + per-unit impact + required summary +
  confirmation), Discard draft, Export JSON, Import JSON (into the draft, validated), Revert to
  repository file. History: every published version with publisher, date, summary, diff against
  its predecessor, View JSON and Restore as draft.

## 8. Commands

- `php artisan checklist-templates:status` — source, versions, drafts, drift per type.
- `php artisan checklist-templates:export {type} [--write]` — prints the live baseline JSON; with
  `--write` replaces `storage/checklists/{type}_checklist.json` so a developer can commit the
  published version back into the repository.
