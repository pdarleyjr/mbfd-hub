# Apparatus issue history on the Daily Checkout

Binding design for what a member sees and records about existing problems during a daily inspection, on every apparatus.
It extends `docs/daily-inspection-continuity.md`. `apparatus_defects`, `apparatus_defect_events` and `apparatus_defect_observations` stay the one issue history: nothing here is a second tracker.

## 1. What a member gets

- At the top of the inspection: how many issues are open, with a tap through to each one's compartment and item.
- Beside the item an issue belongs to, or in **Other recorded findings** when it matches no current item. An unmatched issue is never dropped and never attached to a guess.
- A compact card: item and location, description, current status, who first reported it and when, the latest action. Expanding it loads the full timeline.
- Earlier closed issues, on demand and paged.
- Status comes from `action_status`, read live. It never comes from an old inspection snapshot, and answers on the form are never touched when it refreshes.
- Offline shows the last snapshot, labelled with when it was last updated. It is never presented as live.

## 2. Member API

All under `routes/api.php`, group `public` (`auth:sanctum`, `canonical.api`). Allowed for Fleet managers and active members with an employee profile (the `ApparatusChangeoutController::authorizeMember` rule).

### `GET /api/public/apparatuses/{apparatus}/findings`

```jsonc
{
  "as_of": "2026-11-01T14:00:00-04:00",
  "open": [IssueSummary],     // every unresolved defect on this physical apparatus, oldest first
  "notes": [ConcernNote],     // the latest 20 informational notes, newest first
  "closed_count": 12
}
```

### `GET /api/public/apparatuses/{apparatus}/findings/closed?cursor=`

`{ "data": [IssueSummary], "next_cursor": string|null }`: closed issues newest first, 20 per page, cursor paginated.

### `GET /api/public/apparatus-issues/{defect}/history`

`{ "issue": IssueSummary, "timeline": [TimelineEntry] }`: oldest first. A photo is served only through the authorized route below.

### `GET /api/public/apparatus-issues/{defect}/photo` and `.../observations/{observation}/photo`, `GET /api/public/inspection-concerns/{concern}/photo`

Streams the stored image with the same authorization. Never expose a storage path.

### Shapes

```ts
interface IssueSummary {
  id: number;
  compartment: string; item: string;           // current names, resolved like openFindings()
  compartment_id: string | null; item_id: string | null;
  issue_type: string;
  severity: 'routine' | 'significant';
  description: string | null;                  // the original report (apparatus_defects.notes), never rewritten
  reporter_name: string | null;
  first_reported_at: string | null;
  action_status: string;
  action_label: string;                        // DefectActionStatus::label
  closure_kind: 'resolved' | 'closed_no_action' | null;
  latest_action: { label: string; actor_name: string | null; at: string | null; text: string | null } | null;
  last_observation: string | null;
  last_observed_at: string | null;
  service_status: string | null;
  recurrence_of_defect_id: number | null;
  history_count: number;
}
interface TimelineEntry {
  id: string;                                  // "event-12", "message-4"
  kind: 'event' | 'message';
  type: string;                                // event_type, or "update" for a message
  label: string;                               // DefectEventNarrative::label
  lines: string[];                             // DefectEventNarrative::lines
  text: string | null;                         // member_message, observation notes or message body
  actor_name: string | null;
  at: string | null;
  photo_url: string | null;
}
interface ConcernNote {
  id: number; description: string; location: string | null;
  reporter_name: string | null; reported_at: string | null;
  inspection_reference: string | null; photo_url: string | null;
}
```

### Visibility rules

Names of reporters and people who acted are shown on purpose (owner decision 2026-11-01). Everything else stays allowlisted:

- Build entries only from these fields. Never serialize an event's `details` or `action_details`, `internal_note`, `resolution_notes` on the defect, or any path.
- `lines` come only from `DefectEventNarrative::lines`.
- Messages: `ApparatusIssueMessage::memberVisible()->fromStaff()` only, the same set `latestMemberUpdates` already shows the whole crew. A member's reply and any internal message are never shown here.
- `ApparatusDefectEvent::PAGE_ONLY_EVENTS` are left out.
- A missing actor is `null`, and the screen prints "Not recorded". Nothing is invented.

Long histories: the list endpoint must not load events per defect (use one query or subselects, as `DefectStatusAttribution` does). The timeline is loaded only when a card is opened.

## 3. Concerns, damage and notes

Sent with the checkout, on every apparatus, in `concerns` (max 20; a malformed one fails validation like any other field):

```ts
interface ConcernSubmission {
  client_id: string;            // uuid
  kind: 'note' | 'issue';
  description: string;          // required, up to 2000
  location?: string | null;     // up to 255
  photo?: string | null;        // data URL, same limits as a defect photo
  existing_issue_id?: number | null;
}
```

Stored one row each in the append-only `apparatus_inspection_concerns`: apparatus (the physical row the checkout settled on), inspection, reporter user, `reporter_name` snapshot, kind, description, location, `photo_path`, optional `apparatus_defect_id`.

- **`note`**: only the row. It is not a defect, has no status and never affects readiness or a hold.
- **`issue`, new**: goes through `DefectContinuityService::recordFindings` as a pseudo compartment `{id: 'member-concerns', name: location or 'Member-reported concerns', items: [{id: 'concern-<client_id>', name: <first 120 characters>, status: 'Damaged', notes, photo_path}]}`, so it creates the defect, events, observation, participant and notifications the same way a checklist finding does. It has no checklist item, so members see it under Other recorded findings.
- **`issue` with `existing_issue_id`**: an unresolved defect on this physical apparatus gets one observation (`confirmed_again`) and one `observed_again` event carrying the note. An id that is closed, or on another apparatus, is treated as a new issue rather than refused, so the report is never lost.

Everything runs inside `ApparatusInspectionProcessingService::process`, in the submission transaction, so a retry cannot duplicate it.

## 4. Tasks are completed, equipment is present

Scheduled duties are procedures. New submissions send `Completed` or `Not Completed`. `Present`, `Missing` and `Damaged` stay valid for a queued older payload and keep their meaning in history. A duty sent as `Completed` or `Not Completed` never opens or changes an equipment defect. Equipment items keep Present, Missing and Damaged.

## 5. Refresh

`useLiveFindings`: fetch on open, on window focus, on reconnect, and every 60 seconds while visible and online. Results are kept outside the autosaved draft, so a refresh cannot change an answer. The last good response is kept per apparatus and member in IndexedDB `cachedData`, removed with the rest of the member's data at sign-out, with its time shown when offline.

## 6. Fire Boat 6

A boat has no City fleet number. `VehicleIdentificationFields::usesFleetNumber` is the one rule, and it is false only for a boat. Nothing asks for, requires or records a number for one, a number sent by an older phone is ignored, and its hull ID, registration, year, builder and model are optional. It never gets a vehicle change. Do not give FB6 a placeholder number to get past a rule: fix the rule.

Why this is written down: from September to 2026-11-01 production issued seven FB6 sessions to six members and recorded no inspection. The vehicle requirement stopped every one on the device, so the server never saw an attempt and logged nothing. Tests used a made-up number for the boat and stayed green. `tests/e2e/fb6-inspection-real.spec.ts` now runs the boat with nothing mocked.

An open session belongs to its member and device. It never blocks anyone else, and the same member can start on another device the same day. Only that member's own open session from an earlier duty date stands in their way, and it clears when it expires.

The template (`storage/checklists/fireboat6_checklist.json`, version 2026-11) groups its daily readings under headings that stay on screen. Existing field ids never change. Sessions issued earlier stay on the snapshot they were issued with, so they show the older form until they expire.

Open question for the owner: the temperature unit. No FB6 inspection convention records one, so the fields hold the number shown on the gauge and claim no unit. Adding `"unit"` to those two fields is enough once it is known. Readings refuse only what cannot be a reading (below zero, fuel above 100%). No operating limits are configured because none are established.

SCUBA is diving equipment and airpacks (SCBA) are breathing apparatus, so they are separate fields. The count of SCUBA sets aboard is not established, so `fb6-scuba-ids` is one list rather than a fixed number of boxes. Radio numbers and the last four of the Motorola serial are kept as separate identifiers.
