# Member replies on every workflow

Owner requirement (2026-11-05): a member can read and answer every reply staff send about anything they
submitted, and everyone who becomes part of that workflow can too.

## 1. Inventory

| Workflow | Thread storage | Member reads staff replies | Member replies | Participants |
|---|---|---|---|---|
| Vehicle checkout issue (defect, stretcher report) | `apparatus_issue_messages` (defect set) | Employee inspection page; Daily Checkout issue page (new) | Employee inspection page; Daily Checkout issue page (new) | `apparatus_defect_participants`: reporter, members who re-reported or acknowledged it on a later checkout of the same truck, stretcher reporters, repliers |
| Checkout general thread / clarification | `apparatus_issue_messages` (defect null) | Employee inspection page | Employee inspection page | Inspection owner |
| Equipment change request | `apparatus_inventory_correction_events` | Employee correction page | Employee correction page | Reporter + `apparatus_inventory_correction_participants` |
| Uniform / PPE request (incl. officer-submitted) | `personnel_request_updates.employee_visible_note` | `/employee/my-requests/{id}` | Same page | Beneficiary and the officer who submitted it (new) |
| Employee <-> Logistics message | `employee_logistics_messages` | My messages | My messages | The member |
| Hub support issue | `hub_support_ticket_updates.public_response` | `/support/issues/{id}` | Same page | Reporter |
| EMS supply request | `ems_inventory_request_messages` | Operational forms | Operational forms | Submitter |
| Station request (repair, equipment, fire equipment, big ticket) | `station_request_updates.public_note` + `submission_messages` (new) | Daily Checkout station page; `/employee/my-conversations/station_request/{id}` | Same (new) | Submitter |
| Station supply request | `public_response` + `submission_messages` (new) | Inventory count page; employee conversation page | Same (new) | Submitter |
| Apparatus service request | `apparatus_service_ticket_updates.public_note` + `submission_messages` (new) | Station page; Apparatus Service page; employee conversation page | Same (new) | Requesting member |

No staff-to-member reply channel exists, so nothing was added, for: narcotics checks (a rejection is answered by
resubmitting), take-home vehicle inspections, operational form records, station inspections, inventory counts,
vehicle changeouts and vehicle identification answers.

## 2. Member surfaces

### 2.1 Issue conversation (Daily Checkout)

- `GET /api/public/apparatus-issues/{defect}/history` now carries `conversation.participant` for the viewer.
- `GET|POST /api/public/apparatus-issues/{defect}/conversation` (`MemberIssueConversationController`). Body
  `{body (<= 4000), client_message_id (uuid)}`; throttle 20/min. Participants only
  (`IssueMessageService::followsDefect`), everyone else 403. Member-visible messages only, in and out.

### 2.2 Personnel requests

`PersonnelRequestParticipantAccess` (beneficiary or requesting officer) guards the detail page and the respond
endpoint. Supporting documents (upload and download) stay beneficiary-only. Both are notified of updates.

### 2.3 Station requests, supply requests, service tickets

- `GET|POST /api/public/submission-conversations/{type}/{id}`, type one of `station_request`,
  `station_supply_request`, `apparatus_service_ticket` (`SubmissionConversationController`). Same body, limits
  and idempotency as above; at most 10 member messages in a row before staff answer; archived records are closed.
- Each list row carries `member_conversation.participant` for the viewer.
- Employee page `/employee/my-conversations/{type}/{id}` (`MySubmissionConversation`).
- Staff answer from the record page: "Member conversation" action (`SubmissionConversationActions`).
  Nothing typed there is internal; internal notes stay in each record's own internal field.

## 3. Access

Access classes, not Gate abilities (Gate::before maps abilities to admin permissions):
`SubmissionConversationAccess`, `PersonnelRequestParticipantAccess`, plus the existing
`IssueMessageService::followsDefect` and `InventoryCorrectionAccess::owns`.

## 4. "Member replied, awaiting staff" (for dashboards)

Table `submission_reply_states`, one row per submission: `subject_type` (`App\Support\MemberReplies\ReplySubject`),
`subject_id`, `awaiting_staff_reply`, `last_member_reply_at`, `last_member_reply_user_id`, `last_staff_reply_at`.

Read it with `App\Services\MemberReplies\MemberReplyLedger`:

- `awaiting(?types)` - Builder of every submission waiting on staff, oldest member reply first;
- `isAwaiting(type, id)`;
- `stateFor(type, ids)` - bulk lookup keyed by id.

Or query `SubmissionReplyState::query()->awaitingStaff()->ofType([...])` and join on `subject_id`.

A member's member-visible message sets `awaiting_staff_reply = true`; a person on staff answering the member
(member-visible message, public note, public response, employee-visible note, decision) sets it false. Internal
notes and the automated Support Services reply never clear it. Writes run after commit and never fail the reply.

## 5. Notifications

Member replies reuse each workflow's staff alert (inspection dispatcher, personnel notifier, Hub support side
effects, EMS notifier, Logistics messaging; station requests, service tickets and supply requests use their
staff notification category). Staff replies on the three new threads alert the member under "updates on my
requests". In-app and push only; nothing new emails.
