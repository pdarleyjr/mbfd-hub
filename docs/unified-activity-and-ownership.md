# Unified submissions: equipment ownership, activity, member resolution, stretcher reports

Status: binding design for the November 2026 release. It extends, and where stated replaces,
`docs/captain5-ems-operations-center.md` section 4, `docs/daily-inspection-continuity.md` rule 3 and
`docs/ai-response-automation.md` ("clean checkouts get no reply").

One submission, one authoritative record, one responsible work queue per issue, many synchronized views.
No new ticket table competes with `apparatus_defects`, `station_requests` or the other source records.

## 1. Equipment ownership (one routing decision)

Every equipment issue (`apparatus_defects`) has exactly one responsible owner:

| Owner | Value | Queue |
|---|---|---|
| Captain 5 | `captain5` | Captain 5 workspace open issues |
| Logistics / Support Services (Admin) | `logistics` | Admin Overview, Defects, apparatus pages |

Ownership follows the equipment, never the apparatus type. A Rescue carries DeWalt tools, SCBA, rope and
water-rescue gear (Logistics) and medical gear (Captain 5). An Engine's LifePak is Captain 5's.

### Stored decision

Additive columns on `apparatus_defects`:

- `responsible_owner` varchar(16) null: `captain5` | `logistics`. Null only before the first projection.
- `owner_basis` varchar(32) null: why. `medical_item`, `not_medical`, `unclassified`, `not_equipment`,
  `stretcher_report`, `manual`.
- `owner_set_at` timestamptz null, `owner_set_by_user_id` null (null = the system).

`App\Services\Defects\DefectOwnership` is the only writer and the only resolver:

1. `basis = manual` (a person moved it) is kept until a person moves it again.
2. A standalone stretcher report (`compartment_key = stretcher_report`) is always `captain5`.
3. Otherwise `Captain5IssueScope::classify()` decides from the inspected unit's checklist type and the stable
   `item_key`, through `MedicalScope` (reviewed mapping in `config/captain5_medical_scope.php` + admin decisions in
   `captain5_item_classifications`): `medical` -> `captain5`; everything else -> `logistics`.
4. `unclassified` (ambiguous, new inventory item, unreviewed template, legacy row without key) -> `logistics`,
   listed in the Captain 5 Operations "Medical scope review" so an administrator decides. Never both, never dropped.

`MedicalScope::MEDICAL_ONLY_TYPES` now includes `rescue` and `fireboat6` (reviewed item by item; stretcher,
stretcher charging, the stretcher spare battery and restraints are medical; DeWalt saws, SCBA, TIC, rope and
water-rescue gear are not). There is no "whole Rescue is Captain 5" rule any more.

Consumers read the stored owner (SQL-expressible), falling back to `DefectOwnership::resolve()` for an
unprojected row:

- Captain 5 open/cleared queues and every Captain 5 permission check (`Captain5IssueScope::inScope`).
- Admin Overview `ApparatusDefectSource` excludes `responsible_owner = 'captain5'` from its work rows.
  The issue stays visible in apparatus, station, inspection and Defects history for authorized staff.
- `ApparatusDefectObserver` admin alert, `Captain5Notifier`, `InspectionNotificationDispatcher::issueEscalated`.
- AI replies pick Captain 5 wording when the owner is `captain5`, and Support Services wording when a catalog
  "Captain 5" item is owned by Logistics (`ResponseComposer::ownedBy`, reason codes `owner_captain5` /
  `owner_logistics`). Air tech, fleet, station and 300 handlers are kept.
- Member issue history never shows `owner_transferred`: routing between Captain 5 and Logistics is staff-only.

Priority (Low/Medium/High in `support_triage_levels`) is a separate concept and is unchanged.

### Transfers

`DefectOwnership::transfer()` records an `owner_transferred` event (`details.from`, `details.to`,
`details.basis`, actor context) under the defect row lock, with the same idempotency/state-token rules as
corrective actions. Captain 5 "Clear, this is for Logistics" and "Bring back" are now real transfers
(they also keep writing `captain5_cleared_at` so the previous image still reads them). Logistics reviewers get
"Send to Captain 5" / "Take for Logistics" on the issue panel. Automatic re-projection after an administrator
classifies an item records `owner_transferred` with `details.basis` and no actor. Old events and messages stay.

Reconciliation: `php artisan defects:project-ownership --dry-run` prints old (Rescue-wide) versus new owner per
open issue, misrouted Rescue items, medical items on Engines/Ladders, cleared-but-open Captain 5 items, and rows
without item keys (`--summary` prints the counts only). Without `--dry-run` it writes owners idempotently and quietly
(no `owner_transferred` event, no alert). A cleared Captain 5 issue becomes `logistics` / `manual`. `--verify` fails
unless every open issue has a stored owner and no clear waits to be adopted.

Release (`.github/workflows/deploy.yml`, "Migrate and activate prebuilt Hub image"): in maintenance mode, after the
verified pre-activation `pg_dump` and `migrate --force`, the new image's one-off container writes the full per-issue
dry run to `storage/app/private/release-evidence/ownership-dry-run-<sha>.txt` on the host (the Actions log is public
and shows counts only), projects, and runs `--verify` before the container switch; after the switch the running image
runs `--pending` (rows the previous image's workers wrote meanwhile) and `--verify` again. The scheduled `--pending`
job stays the safety net for a rollback window.

Until a clear made by the previous image is adopted, the Logistics queues (Admin Overview and Captain 5 "Sent to
Logistics") read it as Logistics' work (`DefectOwnership::whereUnadoptedClear`), so it is never in neither queue. A unit
whose checklist type has no medical scope is `logistics` / `not_medical`, not `unclassified`.

## 2. Quiet routine inspections

- A checkout with no new finding saves, appears in history and in My Activity, creates no issue and no hold.
- With automation ON the member gets the deterministic receipt "Inspection received and recorded. No new issues
  reported." (template `clean_receipt`, zero model calls, no bell). With automation OFF the success screen shows
  the truthful confirmation and nothing pretends to be Support Services.
- `daily_checkout_every_submission` is opt-in: default bell OFF. Saved preference rows are untouched.
- Known open issue observed again: history only, unless it escalates or carries a member message.

## 3. Member resolution

### Vehicle issues

`POST /api/public/apparatus-issues/{defect}/member-correction`
`{ client_action_id: uuid, outcome: found|replaced|repaired|corrected|no_longer_deficient, note?: string<=1000 }`

- Eligible: any active member with an employee profile (the reporter or anyone who found the item).
- Closes immediately (`resolved`, `details.member_resolution = true`, actor = member) only when the issue is
  routine: severity `routine`, impact `unclassified`/`non_blocking`, not a stretcher report, issue type not
  `damaged`. Otherwise records `correction_reported` ("Correction reported, awaiting verification") and the owner
  verifies with the existing "Verify correction" action. Apparatus service status is never changed.
- Response `{ data: { result: resolved|awaiting_verification, status_label, message } }`. Retries with the same
  `client_action_id` return the first result. A closed issue returns 409 `ISSUE_CLOSED`.
- Findings payloads carry `member_correction: { eligible, closes_immediately, pending: {at}|null }`.

### Resolved by a checkout, and how long resolved issues stay in view

- **Auto-close.** A later checkout that marks a previously reported item Present closes the issue in the same
  transaction when it is routine (`DefectMemberCorrection::closesImmediately`) and `captain5.auto_close_on_checkout`
  (default on) is set: `action_status = resolved`, `resolved_by_user_id` = the inspector, `resolution_type = item_located`,
  note "Resolved by {Name} during {Unit} checkout", and one `resolved_by_checkout` event (`details.member_resolution`,
  `source = checkout`, `inspection_id`, `observation_id`; idempotency key `defect:{id}:resolved_by_checkout:{inspection}`).
  The observation row is still `appears_corrected`. The defect row is locked and open, so a retried submission closes
  nothing twice. Significant, out-of-service/blocking, damaged, service-ticketed and stretcher issues keep the old path:
  `appears_corrected` event, issue stays open, "Needs verification". The apparatus' service status is never changed and
  no push is sent for an auto-close.
- **Vocabulary.** Closed by a checkout: "Resolved by {Name} during {Unit} checkout · {time}". Closed by Mark resolved
  or staff: "Resolved by {Name} · {time}" (`App\Support\Fleet\DefectResolutionLine`). Waiting: "Needs verification
  — {Name} reported it present/fixed {time}"; safety-critical ones say so. `reported` now reads "New — not yet
  acknowledged". Captain 5's "Clear, this is for Logistics" is now "Send to Logistics" (action names unchanged).
- **Retention.** Fixed issues stay on the Captain 5 workspace ("Recently resolved", not open, not counted) and the
  Admin issue board for `dashboards.resolved_display_hours` (default 72, env `DASHBOARD_RESOLVED_DISPLAY_HOURS`), then
  drop off; they remain in History and the issue record. Closed-with-no-action rows keep the operational-day rule on the board.
- **Clear.** Staff can clear a resolved issue early: `apparatus_defects.resolution_cleared_at/_by_user_id` plus a
  page-only `resolution_cleared` event (in `ApparatusDefectEvent::PAGE_ONLY_EVENTS`: never shown to members, never part of
  an open-screen state token, not in Latest Activity). Captain 5 uses action `clear_resolution` on
  `/api/captain5/issues/{id}/actions`; Logistics/admin use the `clear_resolution` action on the board
  (`ApparatusDefectSource`). Both go through `DefectCorrectiveActionService::clearResolution`: idempotent, no
  notification, the resolution is untouched; reopening resets it.
- **Latest Activity** lists `appears_corrected` ("Needs verification", action required) and `resolved_by_checkout`
  ("Resolved during checkout").

### Station requests

`POST /api/public/station-requests/{id}/member-resolve`
`{ client_action_id: uuid, outcome: fixed|no_longer_needed|received|other, note?: string<=1000 }`

- Eligible: active member with an employee profile. A note is required when the member is not the requester.
- Moves an open request to `completed` with `status_detail = member_resolved`, appends one
  `station_request_updates` row (`metadata.event = member_resolved`, `changed_by_user_id` = the member),
  runs the normal side effects, notifies the requester (when someone else resolved it) and station staff.
- Staff may reopen a member-resolved request (the only exception to "terminal stays terminal").

## 4. Stretcher reports

`POST /api/public/apparatuses/{apparatus}/stretcher-issues` (multipart)
`{ client_submission_id: uuid, problem: loading|armrest|battery|charger|restraint|mechanical|other,
   description: 3..1000, unusable: bool, photo?: image }`

- One row per report in `stretcher_issue_reports` (reporter, problem, text, photo). No inspection is created.
- Creates, or attaches to, the open defect `compartment_key = stretcher_report`, `item_key = stretcher:{problem}`
  on that apparatus. Owner is always `captain5`. Attaching adds an `observed_again` event and enrolls the reporter.
- `unusable = true` marks severity `significant` and tells the EMS Captain as well as the on-duty Captain 5.
- Never touches Friday medical inventory, Monday counts, supply-room deductions or EMS orders.

## 5. Activity

### My Activity (Employee portal)

Domain adapters project the member's own records (canonical FKs only, never typed names): inspections,
equipment issues they reported or joined, stretcher reports, station requests, personnel/uniform requests,
inventory corrections, Logistics messages. The compact card shows up to 10 parent rows; "View all activity"
opens a searchable page.

- Unread = a meaningful human update newer than the member's `member_activity_reads.seen_at` for that item.
  Automated receipts never raise the unread badge. Opening an item through the card records it as seen.
- Open = the underlying record is operationally open. Unread and open are independent.
- Compact-card retention (presentation only, `config/activity.php`): routine 48 h, newly closed 72 h, open until
  closed.

### Latest Activity (Admin Overview)

A card directly under the All / Apparatus / Stations / Personnel chips. Newest meaningful event first from
`apparatus_defect_events`, `apparatus_issue_messages`, `station_request_updates`, `personnel_request_updates`,
`apparatus_inventory_correction_events`, `employee_logistics_messages`. It honors the chip, labels the actor
(member, Support Services, Captain 5, automated) and links to the authoritative record. Captain 5-owned items show
read-only with a "Captain 5" tag. It refreshes with visibility-aware polling and never appears in wall display.
