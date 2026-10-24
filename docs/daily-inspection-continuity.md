# Daily Apparatus Inspection: Acceptance, Issue Continuity and Corrective Action

Status: implementation contract (2026-10-21). Branch `feat/daily-inspection-continuity`.
Scope: daily apparatus inspections (`/daily`, `/admin/inspections`, `/employee/my-vehicle-inspections`).
Take-Home Vehicle Inspections are out of scope and must not be touched.

Guiding rule: one inspection, one authoritative admin page, one persistent issue history
per physical vehicle, clear corrective actions, minimal approvals, complete accountability.

## 1. Audit summary (as found at 07973feb)

- v1 submissions are already auto-accepted (`review_status=approved`, `processing_status=accepted|accepted_with_exception`).
  Meter problems never hold an inspection; they quarantine the meter as five exception reasons
  (`baseline_unverified`, `baseline_missing`, `stale_baseline`, `rollback`, `implausible_increase`).
  The 500 mi / 36 h `implausible_increase` threshold produced every production exception so far.
- No member review flag, no significant-damage concept, no hold other than vehicle swap.
- Defect identity = apparatus id + compartment/item display names (+ checklist aliases). No stable key.
  `defect_dedup` unique (apparatus_id, compartment, item, resolved) breaks the second resolution of a recurring item.
  `ApparatusDefect::recordDefect` update branch overwrites origin inspection, notes, photo and resets status.
- Defect resolution is a bare `update()` in `DefectResource`: no event, no notification, no participants, no messages.
- Two competing admin detail pages: `/admin/inspections/{record}` (weak, but every notification/dashboard link lands here)
  and `/admin/apparatuses/{record}/inspections/{inspection}` (full review, approve/reject/PDF).
- Members see no reviewer feedback, no issue status updates, no notifications.
- Production: 14 inspections, 17 open defects, 2 legacy `pending_review` inspections (ids 10, 11) never processed.

## 2. Inspection acceptance state machine

Columns (existing): `review_status` (`pending_review|approved|rejected`), `processing_status`.
New `processing_status` value: `held_for_review`.

| State | review_status | processing_status | Meaning |
|---|---|---|---|
| Accepted | approved | accepted | normal |
| Accepted, follow-up | approved | accepted_with_exception | non-blocking open exception remains (legacy rows only after this change) |
| Needs approval | pending_review | held_for_review | at least one open blocking hold |
| Vehicle swap hold | pending_review | pending_vehicle_swap | unchanged contract |
| Legacy pending | pending_review | NULL | pre-processing payloads; legacy approve/reject path |
| Rejected | rejected | rejected | reviewer rejected; evidence retained |

`ApparatusInspection::displayStatus()` adds: `held_for_review` => "Needs approval", `rejected` => "Rejected".

### 2.1 Holds = blocking rows in `apparatus_inspection_exceptions`

New column `blocks_acceptance boolean not null default false`. A hold is an exception row with
`blocks_acceptance=true` and status not in (`resolved`,`dismissed`). Field values:

| field | reason | created when |
|---|---|---|
| `miles` / `engine_hours` | `rollback` | submitted < reference reading (see 2.2) |
| `member_review_flag` | `member_flagged` | payload `review_flag` present; metadata `{explanation, items:[{compartment_id,item_id,compartment,item}]}` |
| `significant_damage` | `new_significant_damage` | at least one item reported `Damaged` with `significant=true` that is a NEW defect or an escalation of an existing non-significant defect; metadata `{defect_ids:[...]}` |

Known damage already recorded as significant and confirmed again does NOT create a hold.
Routine Missing/Damaged equipment and previously reported unresolved issues never create a hold.

After processing: if any open blocking hold exists -> `pending_review`/`held_for_review`, else `approved`/`accepted`.
Defects, observations and participants are ALWAYS recorded at submission time, even when held
(they are facts; holding the inspection must not hide them from the next inspector).
Non-rollback meters are applied at submission; a held rollback meter is never applied until decided.

Release: `InspectionReviewService::releaseIfClear(ApparatusInspection $locked)` flips to
`approved`/`accepted` and appends a review event (`status=accepted`, `metadata.released_by`)
when no open blocking hold remains and the inspection is not swap-held. Called by every hold decision.

### 2.2 Meter rules (replaces the five-reason logic in `ApparatusInspectionProcessingService`)

Per meter (`miles` -> `current_miles`, `engine_hours` -> `current_engine_hours`), submitted value null => skip.
`initial` = value in a valid `meter_baseline_token` for this apparatus (form-load reading), else null.
`authoritative` = locked apparatus value.

1. authoritative null -> accept and set (first reading). No exception.
2. submitted >= authoritative -> accept; set authoritative if greater. No exception (no maximum-increase rule).
3. submitted < authoritative AND initial !== null AND submitted >= initial -> the fleet reading advanced after the
   form loaded (concurrent or late offline submission). Accept inspection, do NOT change authoritative,
   record `metadata.meter_outcomes[field]='superseded'` on the processing review event. No exception.
4. otherwise (submitted < authoritative and (no valid token or submitted < initial)) -> blocking `rollback` hold
   with `submitted_value`, `baseline_value=initial`, `authoritative_value`, metadata
   `{previous_inspection_id, previous_reading_at}` (latest accepted inspection of the same processed apparatus
   with a non-null reading for this field, if any).

`config/daily_checkout.php` maximum-increase keys are removed from decision logic (keep the file; delete the keys
and their env vars from code). Validation of malformed/negative/non-numeric/out-of-range values stays in the
request rules (`miles` integer 0..2147483647, `engine_hours` 0..9999999.9 one decimal).

### 2.3 Hold decisions

- Meter rollback: existing `ApparatusInspectionExceptionService::reconcile` actions.
  `accept_submitted` (lower value) now requires `confirm_meter_reset=true` and records
  `metadata.meter_reset=true` (explicit, audited baseline decrease; never silent).
  `correct` (verified value), `request_revision` (asks the member; hold stays open; member-visible message posted),
  `dismiss` (keep authoritative, reject questionable value; reason required). Afterwards call `releaseIfClear`.
- Member flag / significant damage: `InspectionReviewService::approve()` resolves them (status `resolved`,
  review event with reviewer note). Approve refuses while a meter hold is open
  ("Decide the meter reading first") and while swap-held.
- `InspectionReviewService::reject()`: marks all open holds `dismissed`, `review_status=rejected`,
  `processing_status=rejected`; meters from a held rollback are never applied; recorded defects remain
  (they are independent observations and keep their own lifecycle).
- Legacy pending (`processing_status` NULL): `approve`/`reject` delegate to the existing
  `ApparatusInspectionApprovalService` unchanged in behavior, except its `recordDefect` replay must
  go through the continuity service (no origin overwrite, no status reset).
- Approval never changes operational status. "Mark Out of Service" stays an explicit, separately authorized option
  (`Gate::authorize('update', $apparatus)`); resolving a defect never returns a unit to service.

### 2.4 Physical vehicle

New column `apparatus_inspections.processed_apparatus_id` (nullable FK apparatuses, nullOnDelete, indexed):
the physical truck whose meters/defects this inspection's findings were applied to. Set during `process()`
(= `apparatus_id`; for a decided swap = confirmed truck). Backfill = `apparatus_id` for processed rows.
Vehicle history and open-issue lookups use `COALESCE(processed_apparatus_id, apparatus_id)`.
`processed_apparatus_id` is NOT in the immutable-evidence list.

## 3. Schema (one additive migration set, owned by Agent B)

All additive; no table drops; safe on existing data; PostgreSQL and SQLite compatible.

### 3.1 `apparatus_inspections`
- `review_flag_explanation` text null
- `review_flag_items` json null  (array of `{compartment_id, item_id, compartment, item}`)
- `processed_apparatus_id` (see 2.4)
- These two review_flag columns ARE immutable submitted evidence (add to the model's immutable list).

### 3.2 `apparatus_inspection_exceptions`
- `blocks_acceptance` boolean default false; index (`status`,`blocks_acceptance`)

### 3.3 `apparatus_defects`
- `compartment_key` string(128) null, `item_key` string(128) null  (stable checklist ids, e.g. `front_cab`, `front_cab-item-1`)
- `severity` string(16) not null default `routine` (`routine|significant`)
- `action_status` string(32) not null default `reported`
  (`reported|acknowledged|action_pending|replacement_available|repair_scheduled|resolved|dismissed`)
- `action_details` json null  (current fulfillment snapshot: `parts_ordered`, `vendor_reference`, `expected_delivery`,
  `repair_appointment`, `assigned_to`, `assigned_department`, `pickup_instructions`)
- `first_reported_inspection_id` FK null, `first_reported_by_user_id` FK users null, `first_reported_at` timestamp null
- `last_observed_inspection_id` FK null, `last_observed_at` timestamp null
- `acknowledged_at`, `acknowledged_by_user_id`, `resolved_by_user_id`, `resolution_type` string(32) null
  (`replacement_installed|spare_issued|repair_completed|item_located|report_corrected|other`)
- `recurrence_of_defect_id` FK self null (new failure episode after a verified resolution)
- Replace unique `defect_dedup (apparatus_id, compartment, item, resolved)` with partial unique indexes:
  `(apparatus_id, compartment, item) WHERE resolved = false` and
  `(apparatus_id, compartment_key, item_key) WHERE resolved = false AND item_key IS NOT NULL`.
  (Prod has 0 duplicate unresolved triples, verified.)
- Backfill: `action_status` from `status` (`resolved`->`resolved`; `acknowledged`->`acknowledged`; `in_progress`->`action_pending`; else `reported`);
  `first_reported_inspection_id`/`first_reported_by_user_id`/`first_reported_at` from the earliest observation, else
  `apparatus_inspection_id`/`created_at`; `last_observed_*` from latest observation.
- Model hook: `action_status` is the lifecycle source of truth. When `action_status` is dirty:
  `resolved = in(resolved, dismissed)`, `status = resolved ? 'resolved' : (action_status === 'reported' ? 'open' : 'in_progress')`.
  `resolved = true` therefore only means "no longer open". HOW an issue ended is `action_status`: `resolved` (fixed) versus
  `dismissed`, which people read as "Closed — no action required" and which must never be labelled, filtered or counted as
  Resolved (`DefectActionStatus::closureKind()`, `ApparatusDefect::fixed()` / `closedNoAction()`).
  When legacy code sets `status`/`resolved` directly, derive `action_status` (`resolved` -> `resolved`, unresolved -> keep or `reported`).
  `apparatus_inspection_id` keeps meaning "origin inspection" and is never overwritten after creation.

### 3.4 New tables (append-only models: updating/deleting throws LogicException except where noted)

`apparatus_defect_events`
- id, `apparatus_defect_id` FK restrict, `apparatus_inspection_id` FK null, `actor_user_id` FK users null,
  `event_type` string(40), `from_status` string(32) null, `to_status` string(32) null,
  `member_message` text null (member-visible text), `internal_note` text null, `details` json null,
  `idempotency_key` string(100) null UNIQUE, timestamps. Index (`apparatus_defect_id`,`created_at`).
- event_type: `reported, observed_again, appears_corrected, escalated, acknowledged, action_updated,
  replacement_available, repair_scheduled, resolved, dismissed, reopened, correction_verified, ticket_linked, impact_classified`.

`apparatus_issue_messages`
- id, `apparatus_inspection_id` FK null, `apparatus_defect_id` FK null (at least one required; CHECK on pgsql),
  `author_user_id` FK users null, `visibility` string(16) (`member|internal`), `body` text,
  `client_message_id` uuid null UNIQUE, `corrects_message_id` FK self null (append-only corrections), timestamps.
  Index (`apparatus_defect_id`,`created_at`), (`apparatus_inspection_id`,`created_at`).
- Thread semantics: general inspection thread = inspection set, defect null. Issue thread = defect set
  (inspection = context where posted, optional).

`apparatus_defect_participants`
- id, `apparatus_defect_id` FK cascade, `user_id` FK users cascade, `employee_id` FK null,
  `enrollment_source` string(24) (`reported|confirmed|verified_corrected|replied|assigned`),
  `first_inspection_id` FK null, `last_inspection_id` FK null, `first_participated_at`, `last_participated_at`, timestamps.
  UNIQUE (`apparatus_defect_id`,`user_id`). Mutable (last_* updated) but never deleted by app code.

`inspection_notification_deliveries`
- id, `event_key` string(150), `recipient_user_id` FK users cascade, `notification_type` string(80),
  `status` string(16) default `queued`, timestamps. UNIQUE (`event_key`,`recipient_user_id`).

## 4. Services and ownership

| Service | Owner | Responsibility |
|---|---|---|
| `App\Services\Defects\DefectContinuityService` | B | identity resolution, record findings for an inspection, participants enrollment, origin/last-observed bookkeeping, open findings for checkout |
| `App\Services\ApparatusInspectionProcessingService` | A | meter rules, holds, delegates findings to B, sets processed_apparatus_id |
| `App\Services\Inspections\InspectionReviewService` | A | approve / reject / requestClarification / releaseIfClear |
| `App\Services\ApparatusInspectionExceptionService` | A | meter reconcile changes + releaseIfClear |
| `App\Services\Defects\DefectCorrectiveActionService` | E | acknowledge, updateAction, markReplacementAvailable, scheduleRepair, resolve, dismiss, reopen, verifyCorrection, classifyImpact, linkTicket |
| `App\Services\Inspections\IssueMessageService` | E | post / list threads with authorization + visibility |
| `App\Services\Inspections\InspectionNotificationDispatcher` | E | ledger-deduplicated, after-commit, queued notifications |

### 4.1 `DefectContinuityService` (B) - exact API used by A

```php
/** Called inside the submission transaction; apparatus row already locked. */
public function recordFindings(
    ApparatusInspection $inspection,
    Apparatus $physicalApparatus,
    array $observations,          // inspection results compartments (+ scheduled duties pseudo-compartment)
    array $definitions,           // InspectionPaperFields::equipmentDefinitions($checklist)
    array $evidence,              // pending_effects (photo paths for defects)
): FindingsResult;

final class FindingsResult {   // readonly
    /** @var list<int> */ public array $newDefectIds;
    /** @var list<int> */ public array $confirmedDefectIds;
    /** @var list<int> */ public array $appearsCorrectedDefectIds;
    /** @var list<int> */ public array $significantNewOrEscalatedDefectIds;
}
```
Identity resolution order for each submitted item: (1) unresolved defect with same
`compartment_key`+`item_key` on the physical apparatus; (2) existing name/alias match (current logic,
exactly-one-definition rule); on a name match backfill the keys. Never create two observations for one
defect in one inspection (dedupe in memory; guards `defect_inspection_observation_unique`).
- New defect: `first_reported_*`, keys, `severity` from item `significant`, event `reported`, participant `reported`.
- Existing + Missing/Damaged: observation `confirmed_again`, `last_observed_*`, event `observed_again`
  (no status reset; `action_status` unchanged), participant `confirmed`; if `significant` and defect severity
  routine -> severity significant + event `escalated` + included in significantNewOrEscalated.
- Existing + Present: observation `appears_corrected`, event `appears_corrected`, participant `verified_corrected`.
  Never auto-resolves.
- Resolved prior episode + new Missing/Damaged: new defect with `recurrence_of_defect_id`.
- `ApparatusDefect::recordDefect` legacy update branch must stop overwriting origin/notes/photo/status
  (route it through the same rules).
- `openFindings(Apparatus $a, array $checklist): array` for the checklist API: adds `compartment_id`, `item_id`,
  `action_status`, `action_label`, `severity`, `first_reported_at`, `latest_member_update` (latest member-visible
  message or event member_message, text + at) to the existing `open_findings` shape (keep existing keys).

### 4.2 `InspectionReviewService` (A)

```php
public function approve(int $inspectionId, User $reviewer, ?string $note = null, bool $markOutOfService = false): ApparatusInspection;
public function reject(int $inspectionId, User $reviewer, string $reason): ApparatusInspection;
public function requestClarification(int $inspectionId, User $reviewer, string $message, ?string $clientMessageId = null): ApparatusInspection;
public function releaseIfClear(ApparatusInspection $lockedInspection, ?User $actor = null): bool;
```
Lock order everywhere: inspection -> apparatus (ascending id if two) -> exceptions -> defects (ascending id).
Postgres 55P03 retry wrapper reused from `ApparatusInspectionApprovalService`.
Authorization: `Gate::forUser($reviewer)->authorize('approve', $inspection)`.
`requestClarification` posts a member-visible message via `IssueMessageService` (E) and notifies the submitter.

### 4.3 `DefectCorrectiveActionService` (E)

All methods: `(int $defectId, User $actor, array $data, ?string $idempotencyKey = null): ApparatusDefect`.
Lock the defect row; authorize `Gate::forUser($actor)->authorize('manageCorrectiveAction', $defect)`;
validate; append exactly one `apparatus_defect_events` row (idempotency_key unique => retry is a no-op);
optional `member_message` also creates a member-visible `apparatus_issue_messages` row; dispatch notifications after commit.
- `acknowledge(data: member_message?)`
- `updateAction(data: action_status in [action_pending, replacement_available, repair_scheduled], details{...}, member_message?, internal_note?)`
- `resolve(data: resolution_type, resolution_notes (required), member_message?)` -> `action_status=resolved`, `resolved_by_user_id`, notify participants once (event key `defect:{id}:resolved:{event_id}`).
- `dismiss(data: reason)` = the action named **Close — No Action Required** (stored values unchanged: `action_status=dismissed`, event `dismissed`).
  Rules enforced here for every door (admin page, Captain 5):
  - `reason` is required, trimmed, at least 15 characters, and not a placeholder (`n/a`, `na`, `none`, `test`, `.`, `-`, "no action required", one repeated word...). See `DefectNoActionClosure::reasonProblem()`.
  - Refused (plain-language message, nothing written) while the issue is significant damage, `operational_impact` is `out_of_service` or `needs_repair`,
    repair or replacement is already arranged (`repair_scheduled`, `replacement_available`, or order/vendor/appointment details on record), or a service ticket is open
    (`NoActionClosureGuard`). Advice: the impact has an audited path (Classify impact, then close); severity has none ("Resolve it once repaired, or ask Fleet to correct the report"); Captain 5 is told to ask Logistics.
  - The event records the actor (`actor_user_id`), time (`created_at`), `from_status`, and `details.reason` / `details.previous_action_status`; the reason is printed on the issue card, the issue timeline, the Defects history, the member inspection page, the PDF and the Captain 5 history.
  - The reporter and every participant are ALWAYS notified with the reason (`InspectionNotificationDispatcher::issueClosedNoAction`, key `defect:{id}:closed:{event}`;
    from Captain 5 the single sender is `Captain5Notifier` with key `captain5:defect:{id}:closed:{event}` and the same recipient set), so nobody is told twice.
  - Reopen works on it like on a resolved issue (`reopen(data: reason)`: same row, event `reopened`, preserves history and the original close reason in `previous_resolution_notes`).
  `resolve()` no longer accepts `resolution_type=report_corrected` for NEW resolutions ("the report was wrong" is Close — No Action Required); old rows keep the value and its label.
- `verifyCorrection(data: notes)` = resolve with `resolution_type=item_located` default when latest observation is `appears_corrected`.
- `classifyImpact(data: operational_impact)` existing values; `out_of_service` additionally authorizes `update` on apparatus and sets status explicitly.
- `linkTicket` reuses `ApparatusDefectServiceTicketService`.
Ticket completion does NOT auto-resolve a defect (resolution requires an explicit decision).

### 4.4 Policies / authorization

- `ApparatusInspectionPolicy::approve/reject`: existing roles (`super_admin`, `admin`, `logistics_admin`) OR
  `User::canManageFleet()`-equivalent (`admin.access` + `admin.fleet.manage`), aligning Filament with the API capability.
- New `ApparatusDefectPolicy::manageCorrectiveAction` = same rule as approve.
- Messages: admins (same rule) read/write all, including internal. A member may read/post member-visible messages:
  general inspection thread -> only the inspection owner (`EmployeeInspectionHistoryService::owns`);
  issue thread -> participants of that defect or owner of an inspection that observed it.
  Internal notes are never returned by any member-facing projection, API, PDF or notification.
- Every server action re-checks authorization and that the target defect belongs to the inspection's
  physical apparatus (forged ids rejected with 403/404).

### 4.5 Notifications (E)

Existing architecture: Laravel database notifications (Filament format), queued on the `notifications` queue,
WebPush/mail only where `user_notification_subscriptions` already enables them. No new vendor or broker.
- Ledger: insert into `inspection_notification_deliveries` (unique event_key+recipient) inside the transaction;
  dispatch queued notification in `DB::afterCommit`. Failure of delivery never rolls back the domain change.
- Kinds and recipients:
  - `inspection_review_required` (hold created) -> fleet/logistics reviewers. Link: canonical inspection page `#approval`.
  - `issue_new` (first report only) -> no push; appears in dashboard queue (AdminAlertEvent stays).
  - `issue_escalated` (significant) -> reviewers.
  - `issue_member_update` (member-visible message or status with member_message) -> participants (excluding the author).
  - `inspection_message` / `clarification_requested` -> inspection owner.
  - `member_reply` -> reviewers who participated in that thread, else reviewers.
  - `issue_resolved` -> all participants + assigned admin, once per resolution event; inactive users skipped;
    multiple user accounts of the same employee collapse to one (most recent active user).
  - Closed with no action required (`dismissed`) -> the same people, always (kind `issue_member_update`, wording "closed with no action required ... It was not repaired or replaced"), once per event.
  - New significant damage holds the checkout, so reviewers get ONE `inspection_review_required` message that names the damaged items; `issue_escalated` is sent only when the checkout was not held.
- Member links: `MyVehicleInspectionView` URL for the related inspection (employee panel) with `#issue-{id}`.
  Admin links: `InspectionResource::getUrl('view', ['record' => id])` with anchor.
- No daily repeat alert for a known unresolved issue (`observed_again` never notifies).

## 5. UI

### 5.1 Canonical admin page (C): `/admin/inspections/{record}` (`filament.admin.resources.inspections.view`)
Custom Filament page (keep class `App\Filament\Resources\InspectionResource\Pages\ViewInspection`), blade using
`hub-insp-*` classes from `resources/css/filament/hub-inspection-views.css` and `--hub-*` tokens only.
Sections in order: A header (designation, physical vehicle number, reference, date/time ET, member name/rank,
employee id if `can('viewAny', Employee)`), shift, station, acceptance status badge, open holds count, open issues count);
B actions (only applicable: Approve, Request clarification, Reply to member, Reject, Download PDF, Print);
C issues requiring attention (holds first: meter rollback decision card with previous/submitted/difference/previous
date+reference/member/vehicle/explanation; member flag; significant damage; then one card per defect observed in
this inspection grouped by defect with first-reported date+member, observation trail, current action status,
contextual actions, issue thread with member/internal toggle); D full original checklist by compartment with
photos/notes/signature (submitted values, never current defect status); E activity timeline (review events,
defect events, messages, swap decisions; internal entries labelled "Internal"); F related vehicle history
(last 10 inspections of the same physical apparatus + link to apparatus).
Action names `approveInspection` / `rejectInspection` retained. The apparatus-scoped page redirects to canonical.
All entry points (dashboard widget, notifications, apparatus relation managers, station checkouts, exceptions
resource, defect resource, overview partial) link to the canonical URL (with `#issue-{id}` / `#approval`).
Responsive: 320..1440, no horizontal overflow, 44px targets, visible focus, no hover-only, status text + color.

### 5.2 Dashboard (C) - `StationOperationsHubWidget`
Four groups: Inspections requiring approval (open holds + legacy pending); New or escalated issues
(`action_status=reported` or escalated in last 7d unacknowledged); Open corrective actions (acknowledged ..
repair_scheduled); Recently completed inspections (informational). Counts are distinct defects / inspections.

### 5.3 Daily Checkout (D)
- Item shows "Previously reported: Missing - awaiting verification" (or current action label, e.g.
  "Replacement available") with first-reported date; member must still record the current observation.
  Marking Present on a known issue shows "Logistics will verify this correction".
- Damaged item: optional "Significant damage" checkbox -> `significant: true` on the item and the matching defect.
- Review & Sign: "Flag this inspection for further review" checkbox + required explanation (max 1000) +
  optional item picker (checked items: flagged items). Payload field:
  `review_flag?: { explanation: string; items: {compartment_id: string; item_id: string}[] }`.
  Help text verbatim from the directive.
- Previously reported items summarized on Review & Sign.
- Offline: additive optional fields inside the existing queued payload; no queue schema bump; server treats all new fields optional.

### 5.4 Employee portal (D)
`MyVehicleInspectionView`: acceptance status with reason (Needs approval / Accepted / Rejected + member-visible reason);
per-issue current action status + member-visible updates + reply box; general inspection thread with reply;
clarification requests highlighted. Uses `EmployeeInspectionHistoryService::detail()` (extend with member-safe projection).

## 6. API additions (A for submit, E for messages)

- Submit: `review_flag` (array: `explanation` required_with, string max 1000, regex non-blank; `items` array max 50 of
  `{compartment_id,item_id}` validated against the resolved checklist); `compartments.*.items.*.significant` boolean;
  `defects.*.significant` boolean (must match its item). Unknown flag item refs -> 422.
  Payload hash includes the new fields automatically (canonical JSON of validated data) - verify replay stability.
- Messages are Livewire actions (admin + employee panels); no new public JSON API required.

## 7. Testing gates
PHPUnit (sqlite) for all suites; `--group=postgres` on disposable PostgreSQL in CI (`hub-release-gates.yml`);
Daily Checkout `npm run typecheck` + Playwright `playwright.daily-checkout.config.ts`; Node contract tests.
Required scenarios: directive section 18 + section 20 scenarios A-J.

## 7a. As built (deviations from the original contract)

- Reviewer rule: `App\Support\Fleet\InspectionReviewers::canReview` (active account; super_admin always; a user
  with direct `admin.access` also needs direct `admin.fleet.manage`, matching `AdminCapabilityGate`; otherwise
  roles `admin|logistics_admin`). Policies, corrective actions, message visibility and reviewer notifications all use it.
- Message attribution is a write-time snapshot: `apparatus_issue_messages.author_context` (`logistics|member`,
  migration `2026_10_22_100400`), so later role changes never relabel history. Checkout "latest update" uses only
  Logistics messages and event `member_message` text.
- Rejection: `reject($id, $reviewer, $reason, ?$internalNote)`; `$reason` is posted member-visible,
  `$internalNote` stays on the review event.
- Meter baseline tokens carry `issued_at` and expire after `daily_checkout.meter_baseline_max_age_hours` (168);
  an expired token is unverified, so a lower reading becomes a rollback hold instead of "superseded".
  Lowering the fleet meter via `accept_submitted` or `correct` requires `confirm_meter_reset=true`.
- Flag and significant-damage holds are decided only by Approve/Reject; `reconcile()` refuses them with a validation message.
- Notifications use one class (`InspectionEventNotification`) with `InspectionNotificationKind`; ledger rows carry
  `attempts` (migration `2026_10_22_100300`) and `inspections:retry-notifications` (every 10 minutes) re-sends rows
  still `queued` after 10 minutes, at most 3 attempts.
- Resolution type defaults to `other` in admin modals; resolution notes are always required.
- Test suite: `tests/TestCase::tearDown` resets Livewire's static redirector stack, which leaked one Application per
  403/404 Livewire assertion and pushed the full suite past the 512 MB limit.

## 8. Rollback
All migrations additive. Rollback = redeploy previous immutable image (`deploy.yml` with prior SHA/digest).
New columns/tables are ignored by the old code except: old code reads `defect_dedup`-free schema fine (it never
relied on the index for correctness); `processing_status=held_for_review|rejected` rows display as "Pending review"
in old code and can be approved by the legacy approval path (pending_effects is null => legacy branch, no effects
re-applied). Migration `down()` methods restore the original unique index only if no duplicates exist.
