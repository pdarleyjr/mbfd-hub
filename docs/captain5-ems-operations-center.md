# Captain 5 EMS Operations Center

Status: implementation contract (2026-10-23). Branch `feat/captain5-ems-operations-center`.
Builds on `docs/daily-inspection-continuity.md` (issues, corrective actions, messages,
notifications) and `docs/command-300-access.md` (300 profile + communications). It adds
no second issue engine: Captain 5 acts on the same `apparatus_defects` rows, through the
same `DefectCorrectiveActionService` / `IssueMessageService`, and Admin and Captain 5 see
the same live state.

## 1. Data sources (verified read-only against production 2026-10-23)

| Fact | Authoritative source |
|---|---|
| Captain 5 seat | `employee_bid_assignments` current verified v2 row, `unit_label` exactly `Captain 5` (one per A/B/C shift, `position_label` `Captain 5`). |
| EMS Captain | Same table, `unit_label` exactly `EMS` (D Shift, rank Captain). |
| Ranks | `employees.rank`, canonical strings: `Lieutenant`, `Captain`, `Division Chief`, `Deputy Fire Chief`, `Fire Chief`. No abbreviations. Civilian titles (e.g. `EMS Coordinator`, `Chief Plans Analyst #3`) never match. |
| Shift on duty | No staffing store exists. Duty day = 08:00–08:00 America/New_York (TeleStaff print boundary). A/B/C 24-hour rotation anchored at `captain5.shift_rotation.anchor_date` (2026-03-09 = A), checked against production inspection `shift` values (8 of 9 recorded dates match; the one mismatch is a single member-entered value). Config can disable it; when disabled the on-duty bid holder is "unknown". |
| Acting Captain 5 (out of class) | No reliable source: the Crew Planner TeleStaff parse is ephemeral and treats OOC/ROC/MOC/TOC codes as unverified. A member asks to be shown as acting Captain 5 and an administrator approves it (`captain5_acting_requests`, section 11). Display only. Never inferred from rank. |
| Medical equipment | No structured flag exists in the checklists. Verified mapping `config/captain5_medical_scope.php` keyed by `(checklist_type, item_key)` plus admin decisions in `captain5_item_classifications`. Anything not mapped is `unclassified` → not visible to Captain 5, queued for admin classification. |
| Current crew | Inspection submissions for the physical apparatus during the current duty day. If none, the unit-level channel is the issue's "latest update" shown at the next Daily Checkout of that unit. |

## 2. Access (`App\Services\Security\Captain5Access`)

One class decides everything, like `Command300Access`. Gate abilities (never the permission names):
`captain5-view` (page + scoped data), `captain5-act`, `captain5-admin`.

Account must be usable (`isAuthenticationAllowed()` and no pending password change).

No duty gating (follow-up 2026-10-23): everyone who may open the page may do every task on it.

| Source | Page, data and every task | Shown as the shift's Captain 5 |
|---|---|---|
| `super_admin` | yes (cannot be denied: protected recovery) | – |
| Admin panel (`admin.access` direct) | yes | – |
| Officer rank (Lt, Capt, DC, Deputy FC, FC) | yes | – |
| Captain 5 bid holder | yes | when their bid shift is on duty and no approved acting request is showing |
| EMS Captain bid holder | yes | – |
| Approved acting request (showing now) | only if another source above applies | yes, until its end time (display only) |
| Override `allow` | yes | – |
| Override `deny` | removes every source above except `super_admin` | |

- `captain5-admin` = active account with Admin Panel entitlement AND (`super_admin` OR direct `captain5.admin`). Configures overrides, approves acting requests and decides medical classification. Grants nothing in the Admin Panel by itself.
- Separated decisions in code: `canViewPage`, `canViewData`, `canAct`, `canDismiss` (all equal for eligible members), `canConfigure`, and the display-only `isShownCaptain5`.
- Enforced server-side on: `/daily/captain-5` shell (403 before the SPA), every `/api/captain5/*` route, Captain 5 communications API + attachment download, every issue mutation (service layer), message threads. UI hiding is cosmetic only.
- Never grants Admin Panel access, Fleet authority (`classifyImpact` out_of_service, service tickets), inspection approval/rejection, hold decisions or meter decisions.

## 3. Tables (all additive, PostgreSQL + SQLite)

- `captain5_duty_assignments`: id, `employee_profile_id` FK employees restrict, `duty_date` date, `starts_at`/`ends_at` timestampTz (default the 08:00–08:00 duty window), `shift_letter` char(1) null, `reason` text, `assigned_by_user_id` FK users, `revoked_at` null, `revoked_by_user_id` null, `revocation_reason` null, `client_request_id` string(64) unique null, timestamps. Partial unique `(duty_date) WHERE revoked_at IS NULL` (one acting Captain 5 per duty day). Rows are never deleted; revocation is an update of the revoke columns only. **Retired by the follow-up**: no code reads or writes it; the table and its rows stay as history and for rollback.
- `captain5_acting_requests`: see section 11.
- `captain5_access_overrides`: id, `employee_profile_id`, `effect` (`allow|deny`), `level` (`view|act`, null for deny), `reason`, `set_by_user_id`, `superseded_at` null, `superseded_by_user_id` null, timestamps. Partial unique `(employee_profile_id) WHERE superseded_at IS NULL`. History rows are immutable.
- `captain5_item_classifications`: id, `checklist_type`, `item_key`, `item_name`, `classification` (`medical|not_medical`), `reason`, `decided_by_user_id`, timestamps, unique (`checklist_type`,`item_key`).
- `captain5_handoff_notes`: id, `duty_date`, `author_user_id`, `author_authority` (snapshot), `body` text, `client_request_id` unique null, `acknowledged_by_user_id` null, `acknowledged_at` null, timestamps. Append-only except the acknowledge columns (set once).
- Communications (section 6): `command_communication_posts.audience` string(20) not null default `command_300` (`command_300|captain_5|both`), `deleted_at`/`deleted_by_user_id`/`deletion_reason`; `command_communication_channel_reads` (`user_id`, `channel`, `last_read_at`, unique pair).

## 4. Issue scope (`App\Services\Captain5\Captain5IssueScope`)

- Physical apparatus checklist type via `DailyCheckoutChecklistResolver::checklistTypeFor()`.
- `rescue` → every issue, except `compartment_key = scheduled_duties`.
- `engine`, `engine2`, `ladder1`, `ladder3` → only items classified `medical` (DB decision wins over config). Item key resolution: `item_key`; when null, a unique name match through `InspectionPaperFields::equipmentDefinitions()` (incl. legacy names); otherwise `unclassified`.
- Everything else (Air Truck, Fire Boat, command vehicles, unmapped) → out of scope.
- `unclassified` and config `ambiguous` items are never visible to Captain 5; they appear in the admin Medical Scope review list.
- Fleet keeps all mechanical/PM/non-medical authority; Captain 5 sees nothing outside the scope.

## 5. Actions (same services as the Admin Panel)

`DefectCorrectiveActionService::forCaptain5(Captain5ActorContext)` returns a copy whose
`run()` authorizes with `Captain5IssueAuthority` (scope + operation) instead of the reviewer
rule, records `details.actor_context` (`role=captain5`, authority source, assignment id,
duty date, shift letter) on the event, writes member messages with `author_context=captain5`,
enrolls the actor as participant (`enrollment_source=captain5`) and routes notifications through
`Captain5Notifier`. Reviewers keep the unchanged path.

| Captain 5 action | Service call |
|---|---|
| Acknowledge | `acknowledge` |
| Ordered (+ vendor ref, expected delivery) | `updateAction(action_pending, details)` |
| Update expected delivery / notes | `updateAction(current pending status, details)` |
| Available for pickup | `updateAction(replacement_available, pickup_instructions)` |
| Replaced / returned to service | `resolve(replacement_installed|spare_issued|repair_completed, notes)` — requires an explicit "verified in place" confirmation |
| Verify member's correction | `verifyCorrection` |
| Close — No Action Required | `dismiss` (any Captain 5 page user since the follow-up). Same reason rules and refusals as Logistics (significant damage, out of service / needs repair, repair or replacement arranged, open or completed service ticket); Captain 5 is told to ask Logistics to correct the severity. The reporter and participants are told once, with the reason. |
| Reopen | `reopen` |
| Message submitter / followers | `IssueMessageService::post` (issue thread) |
| Request clarification | `IssueMessageService::post(kind=clarification_requested)` on the originating inspection |

Acknowledged, Ordered and Available for pickup never close an issue. Optimistic concurrency:
every mutation carries `expected_state` = `{action_status}:{last_event_id}`; a mismatch returns 409
with the current issue. Every mutation carries an idempotency key (retry = no-op).

## 6. Shared 300 + Captain 5 communications

One `command_communication_posts` row per post with `audience`; it appears in each feed whose
channel the audience includes. 300 feed = `command_300|both` (Command300Access). Captain 5 feed =
`captain_5|both` (Captain5Access view). Admins (Filament page) choose the audience; 300 members
post `command_300`; Captain 5 members post `captain_5`; replies inherit the parent's audience.
Controlled delete = soft delete with actor, reason and audit, by the author or a communications
administrator; attachments of deleted posts are no longer served. Read state per channel.
Immediate notification: `captain_5|both` posts by administrators notify the on-duty Captain 5 only
(acting → on-duty bid holder → all Captain 5 bid holders when the on-duty person cannot be
resolved). Everyone else eligible sees the post and the unread count.

## 7. Notifications (`Captain5Notifier`, same ledger + `InspectionEventNotification`)

Event keys start with `captain5:`; `inspections:retry-notifications` routes them back here.
- New in-scope findings of one inspection → on-duty Captain 5 (one notification per inspection).
- New significant damage in scope → on-duty Captain 5 and EMS Captain.
- Captain 5 issue update / resolution → original reporter, current-duty-day crew of that unit,
  participants who replied or were assigned, other Captain 5 actors on the issue; never the actor,
  never every former observer. One per person per event.
- Member reply on an in-scope issue → Captain 5 actors on that issue (in addition to the existing reviewer routing; reviewers excluded from the Captain 5 copy).
- Notification failure is logged and never rolls back the issue change.

## 8. Audit visibility

The same `apparatus_defect_events` rows (actor, time, from→to, details incl. actor_context) are
shown in: Admin inspection page timeline and issue cards, Apparatus defect history, the member's
`MyVehicleInspectionView`, and the Captain 5 page. Labels say "Captain 5 (acting)" etc. from the
snapshot, never from current roles.

## 9. Rollback

All migrations additive. Redeploy the previous image; old code ignores the new tables/columns
(`audience` defaults to `command_300`, so the old 300 feed keeps showing only 300 posts — Captain 5
only posts would become visible to 300 under old code, so roll back communications by hiding them
with `captain5.communications_enabled=false` first if needed).

## 10. As built (2026-10-23)

- **Access**: `Captain5Access::decide()` as in section 2. Reviewers (`InspectionReviewers::canReview`) using the
  workspace act through the unchanged Logistics path; everyone else with `canAct` acts as Captain 5.
  The station-list probe `GET /api/captain5/summary` is outside the view gate on purpose and returns only
  `{"access":{"view":false}}` to ineligible members.
- **Acting assignments** (retired by section 11) refused self-assignment, denied members, departed members and
  members without an active Hub account.
- **Notifications** to "the on-duty Captain 5" are filtered through `Captain5Access` at send time (a denied or
  disabled person is skipped; fallback = eligible seat holders). Captain 5 participants are found from the audit
  snapshots (`details.actor_context.role = captain5`, `author_context = captain5`). Retrying one ledger row
  rebuilds and sends only that row.
- **Messages**: Captain 5 authorship is opt-in (`as_captain5`, set only by the Captain 5 API), so an officer who
  holds a Captain 5 role still replies as a crew member on their own inspection. Captain 5 writes to an
  inspection's general thread only for Rescue units; Engine/Ladder conversations happen on the medical issue.
  Hold/rejection reasons shown to members stay Logistics messages.
- **Scope**: candidates include issues whose physical truck OR inspected unit is a Rescue/Engine/Ladder (vehicle
  swaps, reserves). The open queue scans in id-descending chunks (in-scope items can never be pushed out by Fleet
  items); above 500 in-scope open issues the page shows a notice and the server logs
  `captain5.open_issue_queue_truncated`. The medical mapping is honoured only while the template matches its
  `reviewed_version`; an unreviewed template change makes every item of it unclassified (admin review).
- **Medical classification decisions** are append-only history (`superseded_at`), like overrides.
- **Communications**: `CommandCommunicationAccess` is the single channel decision (300 = `Command300Access`,
  Captain 5 = `Captain5Access` view + kill switch). Admin posts default to "300 only". Removal: author or
  super_admin / direct `admin.communications.manage` (Admin Panel entitlement required); reason required; audit
  row in `command_communication_audit_events`; tombstone in feeds; attachments of removed or other-audience posts
  answer 404. Admin replies in a thread that includes Captain 5 also notify the on-duty Captain 5.
- **PWA**: service worker cache `mbfd-checkout-v11` (activation drops v10). Message attachments are never stored by
  the service worker.
- **Duty day** uses wall-clock 08:00 America/New_York (DST-safe).
- **Known policy choice**: Captain 5 messages do not push a notification to Logistics; Logistics sees them on the
  canonical inspection page and issue history (labelled "Captain 5 · Name").

## 11. Follow-up (2026-10-23): no duty gating, acting requests, Department Updates

- **No duty gating.** Every officer (Lt and up), Division Chief, Chief, member with Admin Panel rights, Captain 5
  or EMS Captain seat holder, and anyone with an `allow` override can do every task on the Captain 5 page, on
  any shift. A `deny` override still removes access (except `super_admin`). The "on-duty authority" branch,
  the `captain5-manage-assignments` gate, the `/api/captain5/assignments*` and `/api/captain5/people` routes,
  `Captain5AssignmentService` and `Captain5DutyAssignment` are removed. Overrides are now `automatic | allow |
  deny`; stored `allow` rows of either old level read as `allow`.
- **Who is shown as Captain 5.** The page header shows "<Shift> Shift Captain 5: <name>" (A green, B blue,
  C red) from data: an approved acting request that is showing, otherwise the bid seat holder for the shift on
  duty (rotation, 08:00 boundary). It is display only and never changes permissions or who an action is
  attributed to (attribution is always the signed-in member; the audit snapshot also records
  `shown_captain5` and `acting_request_id`). New-findings notifications go to the person shown.
- **Acting requests** (`captain5_acting_requests`): `kind` `act|end`, `employee_profile_id`,
  `requested_by_user_id`, `duration` `12h|24h|custom`, `shift_letter`, `ends_at`, `status`
  `pending|approved|rejected|withdrawn`, `decided_by_user_id`, `decided_at`, `decision_note`, `starts_at`,
  `ended_at`, `ended_by_request_id` (self FK), `client_request_id` unique. Decided rows are immutable except
  `ended_at` / `ended_by_request_id`.
  - "Acting as Captain 5 today? Click here." on the page: 12 hours (ends at the next 08:00 or 20:00), 24 hours
    (ends at the next day's 08:00) or a custom end (15 minutes to 72 hours ahead).
  - Captain 5 administrators (`canConfigure`) are notified and approve or decline (a note is required to
    decline) on the page or in Admin > Captain 5 Operations. Nobody approves their own request except a
    super admin. An expired request cannot be approved.
  - Approval shows the member until `ends_at`, then the display ends on its own. Another member can ask to take
    over (approval ends the earlier one) and anyone on the page can ask to remove it (`kind=end`), both with
    approval. A requester can withdraw a pending request; a new request replaces their earlier pending one.
  - Acting never grants access to someone who cannot otherwise use the page.
- **Messages vs Department Updates.** The Messages tab (on both pages) says: "Post and receive messages, files,
  etc. Messages can only be received and sent by admin personnel, Capt. 5 and 300." The separate "Department
  Updates" panel posts a home page notice everyone sees.
  - Rule (set by the user): anyone who can use the Captain 5 page or the 300 page, any Chief or Division Chief
    (Division Chief, Deputy Fire Chief, Fire Chief), a Fleet Operations Manager / `admin.fleet.manage`, and any
    member with Admin Panel rights, on an active account with no pending password change. One server rule,
    `CommandDepartmentUpdatePublisher::canPost`, checked on every call from either page (not the page gate).
    Posts publish immediately to Everyone with no push or in-app broadcast; administrators edit, pin, notify
    or archive them in the Admin Panel as usual; the author can take their own post down (archived, kept as
    history).
  - Attribution: `author_name` and `author_rank` are filled from the signed-in account when any Department
    Update is created (page or Admin Panel), never from the request, and cannot be changed afterwards (the
    model refuses). The home page, archive, detail page, Admin Panel and the page feeds show "Posted by
    <rank> <name>". Older updates show the account name.
  - It reuses `department_updates` (new nullable `origin` `command_300|captain_5` and unique
    `client_request_id`), the private local disk and the existing home page, archive and detail views. Text is
    escaped into paragraphs. One picture (JPEG/PNG/WebP up to 5 MB) and one document (PDF, DOCX, XLSX, PPTX,
    TXT, CSV) per post; a schedule is posted as an attached document or picture.
  - Routes: `GET|POST /api/command-communications/department-updates`, `POST .../{id}/take-down`, and the same
    under `/api/captain5/department-updates`.
- **Naming.** The station-list card and page for the Shift Division Chief seat are titled "300" with the badge
  "Shift Division Chief". The home page Station / Vehicles / Equipment card reads "Station forms, Vehicle
  Checkouts, Video Conferencing".
- **Rollback.** The migration is additive. Old code ignores `captain5_acting_requests` and the two new
  `department_updates` columns; posts made from the pages stay ordinary published Department Updates.

## 12. Shift 300 display and acting 300 (2026-10-24)

- **Shift 300 line.** The 300 page and the 300 card on the stations list show
  "<Shift> Shift 300: <rank> <name>" (A green, B blue, C red; the shift letter is always in the text). It comes
  from `Captain5DutyRoster::command300OnDuty()`: an approved acting 300 request that is showing, otherwise the
  verified current bid seat `unit_label` "300" whose bid shift is the on-duty rotation shift (08:00 boundary,
  2026-03-09 = A). Production has one Division Chief seat per A/B/C. When nobody resolves, the page says
  "Shift 300 not set"; it never guesses from rank.
- **One engine for both roles.** `captain5_acting_requests.role` (`captain_5` default | `command_300`), added by
  the additive migration `2026_10_24_120000_add_role_to_captain5_acting_requests` (one column with a default and an
  index; it creates, grants and changes no permission or role). `Captain5ActingService`, `Captain5Notifier`
  (same kinds and `captain5:acting:{id}:*` ledger keys, so the notification evaluator is unchanged; wording and
  the linked page follow the role), the request history and the Admin > Captain 5 Operations queue (one list,
  labelled by role) cover both. Takeover and end act only within the request's role.
- **Who may ask for acting 300:** anyone `Command300Access` allows: Captains, Division Chiefs, the 300 bid seat,
  administrators and explicit `command.300.access` grants. **Who approves acting 300 (the user's rule):** any
  administrator, i.e. an active account with Admin Panel rights (super_admin or a direct `admin.access` grant).
  **Acting Captain 5** keeps its rule: Captain 5 administrators (super_admin, or Admin Panel plus
  `captain5.admin`). Both live in `ActingApprovers`; the notifier asks only the approvers of the request's role, and
  the alert category "Acting Captain 5 and 300 requests" is open to anyone who may approve either. Nobody approves
  their own request except a super admin. Same durations as acting Captain 5 (12 hours to the next 08:00
  or 20:00, 24 hours to the next day's 08:00, or a custom end 15 minutes to 72 hours ahead). An expired request
  cannot be approved; the display ends on its own at `ends_at`.
- **Display only.** Being shown as acting 300 grants no access and changes nothing else; forgetting to use it
  affects nothing.
- **API:** `GET /api/command-300/shift` → `{on_duty, acting}`; `POST /api/command-300/acting-requests`
  (`kind`, `duration`, `ends_at`, `client_request_id`) and `/{id}/approve|reject|withdraw`, behind
  `access-command-300`. The 300 routes decide only 300 requests and the Captain 5 routes only Captain 5 requests
  (another role's id answers 404); the route, never the request body, sets the role.
- **Rollback.** Old code ignores the `role` column, but it would treat a showing acting 300 row as an acting
  Captain 5. Before rolling back, end any showing acting 300 request (Admin > Captain 5 Operations, or approve an
  end request).
