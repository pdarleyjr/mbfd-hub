# Narcotics check (member photo to Captain 5)

Status: implementation contract (2026-10-30). Lane C of the 2026-10-30 release. Builds on
`docs/captain5-ems-operations-center.md` (access, roster) and the EMS inventory API patterns
(`App\Http\Controllers\Ems`, envelope, `EmsException`). It adds no second inbox engine, no second
notification path and no new permission.

This feature records that a member sent a photo and that Captain 5 approved or rejected it. It does
not claim, and nothing in it establishes, compliance with any controlled-substance regulation or
department policy. The photo-retention window is a local setting the department chooses.

## 1. What the member sees

Employee Portal > Forms > **Narcotics check** card (shown to every active member with a personnel
profile; hidden when `narcotics_checks.enabled` is false). One screen:

1. **Rescue unit** (required): a drop-down with R1, R11, R2, R22, R3, R4, R44, FB6 and Other, in that order.
   Choosing **Other** shows a required text box ("Which unit?", 1 to 40 characters, trimmed, one line);
   choosing any listed unit hides it and clears what was typed. The list is defined once, on the server
   (`narcotics_checks.rescue_units` in `config/narcotics_checks.php`); the form reads it from
   `GET /api/narcotics-checks/form` and does not keep its own copy (a built-in list is used only if that
   answer lacks one, so the form still opens; the server accepts nothing it does not list). Sending
   without a unit puts the reason next to the field and moves focus there; the server's own wording for a
   refused unit (422 `fields`) appears in the same place. The inputs are 16 px so iOS does not zoom.
2. **Seal number** (required): a single-line text box right after Rescue unit and before the photo. Free
   text on purpose (seals mix digits, letters, dashes and spaces): trimmed (ordinary and no-break spaces),
   1 to 40 characters counted as characters, one line, no control, zero-width or bidirectional characters
   (the same rule as the Other unit text). It is stored exactly as typed after trimming (case is kept). The
   length is `narcotics_checks.seal_number_max_length`, sent in the form answer (the form keeps 40 only as a
   fallback when the answer lacks it). Errors behave like the unit's: the reason sits next to the field,
   linked with `aria-describedby`, and focus moves to the first missing field.
3. **Take photo** (primary) or **Upload photo**. Phones and tablets open the system camera
   (`<input accept="image/*" capture="environment">`); computers use an in-page `getUserMedia` camera
   when the page is secure and a camera exists. Permission denied, no camera, plain HTTP or an
   unsupported browser all fall back to the upload picker with a plain-language reason.
4. A sharp preview (tap to zoom), **Retake photo** / **Choose a different photo**, an optional note
   (500 characters), **Send to Captain 5**, a progress bar.
5. "Narcotics check sent to Captain 5." appears only after the server has stored the photo and the
   database row (HTTP 201). Until then the photo stays on screen.
6. **My narcotics checks** lists their own checks: Sent to Captain 5 / Approved / Rejected, the rescue
   unit and seal number ("Rescue unit: R22", "Seal number: ZX-4471"; "Not recorded" on a check sent before
   they were asked for), the rejection explanation if one was given, the photo, history, and **Send a new
   photo** on a rejected check. The new photo starts with the rejected check's unit and seal number already
   filled in; both can be changed.

Browser-side processing (`resources/js/operational-forms/narcotics`): EXIF orientation fixed exactly once
(a 730-byte probe learns whether the browser already does it), long edge bounded to 3200 px, never
enlarged, pixels re-drawn so EXIF/GPS are gone, WebP when the browser really encodes it (the result type
is checked) else JPEG, quality ladder 0.86/0.80/0.74 (WebP) or 0.90/0.84/0.78 (JPEG) stepping down only
while the file is over 3 MB. AVIF is deliberately not attempted (slow on station devices, server may not
read it). If the browser cannot decode or encode, the original is sent and the server re-encodes it.
The pure decisions are unit tested (`tests/Node/operational-forms/narcotics-image.test.mjs`).

Unsaved-work safety: the photo, the unit, the seal number and the note are held in memory only (never in
IndexedDB). The form reports `dirty -> sending -> saved` to the Hub busy registry
(`markBusy('narcotics-check')`); `failed` stays unsafe until sent or discarded; the page also has
`data-mbfd-unsaved-work-guard`. Leaving the form in-app and `beforeunload` ask first. A unit the member
picks and a seal number they type count as work like the note does; a value that was only prefilled
(answering a rejected check) does not. Double taps and retries reuse one `client_submission_id`; changing
the photo, the unit, the seal number or the note starts a new one.

## 2. Access (`App\Services\Narcotics\NarcoticsCheckAccess`)

No Gate ability (Gate::before passes any super_admin). One class decides:

| Action | Who |
|---|---|
| submit / list own / read own photo | active account, not forced to change password, with an employee profile |
| review (inbox, archive, approve, reject, read any photo) | **Captain 5 only**: a Captain 5 bid seat holder (any shift), the EMS Captain seat holder, whoever the TeleStaff roster has working the Captain 5 seat at this moment (not the rest of that duty day), a person an administrator allowed in Captain 5 Operations ("allow" override), or super_admin |

Officers by rank, Fleet reviewers and Admin Panel administrators can open the Captain 5 page but are
**not** reviewers here. A Captain 5 "deny" override removes review (not for super_admin). No person
is named in code; the seat data is the existing `Captain5Access` decision. No permission row is
created or granted by this feature.

## 3. Data model and state machine

`narcotics_checks`: `uuid` (public id), `status` (`pending|approved|rejected`), submitter user/employee
plus name/rank snapshots, `submitted_at`, `member_note`, `rescue_unit` + `rescue_unit_other` + `seal_number` (see below),
`client_submission_id` (unique),
`resubmission_of_id` (unique, self FK), image columns (`image_disk`, `image_path`, `preview_path`,
`thumb_path`, `image_mime`, width/height/bytes, `image_sha256`, `images_purged_at`), decision columns
(`decided_at`, `decided_by_*` snapshots, `decision_note`), `archived_at`.
`narcotics_check_events`: append-only audit (`submitted`, `viewed`, `approved`, `rejected`,
`images_purged`) with actor snapshots and from/to status.

```
member sends -> pending --Approve & Archive--> approved  (archived_at set)
                        --Reject (+optional explanation)--> rejected (archived_at set)
rejected --member sends a new photo--> NEW pending row (resubmission_of_id); the rejected row is never edited
```

- **Rescue unit** (`2026_10_30_170000_add_rescue_unit_to_narcotics_checks`, additive, both nullable
  `string(40)`): `rescue_unit` is the option chosen (R1 ... FB6, or `Other`); `rescue_unit_other` is the
  member's text and is stored only when the option is `Other` (a listed unit never keeps text, whatever the
  client sent). Every new submission must carry a unit, validated on the server against
  `App\Support\Narcotics\RescueUnits` (the choice must be one of the nine options, compared exactly; for
  Other the text is trimmed, required, at most 40 characters, one line, no control or invisible formatting
  characters, and stored as typed). Violations are 422 `validation_failed` with `fields.rescue_unit` or
  `fields.rescue_unit_other`. Checks sent before this existed stay NULL and display as "Not recorded".
  The `submitted` audit row snapshots it (`payload.rescue_unit = {choice, other, label}`). A rejected
  check is never edited; its answer is a new row with its own unit.
- **Seal number** (`2026_10_30_180000_add_seal_number_to_narcotics_checks`, additive, nullable
  `string(40)`): required on every new submission and validated on the server
  (`App\Support\Narcotics\SealNumber`: trimmed, 1 to `seal_number_max_length` characters, one line, no
  control or invisible formatting characters; nothing is restricted to digits). Violations are 422
  `validation_failed` with `fields.seal_number`. Stored exactly as typed after trimming. Checks sent before
  this existed stay NULL and display as "Not recorded". The `submitted` audit row snapshots it
  (`payload.seal_number`). It never appears in a notification.
- "Archived" is not a third status: a decided check leaves the pending inbox (`status = pending`
  and `archived_at IS NULL`) and stays in the searchable archive with who decided and when.
- Each transition runs in one transaction: `SELECT ... FOR UPDATE`, then `UPDATE ... WHERE status =
  'pending'`. Two Captain 5 users acting at once produce one decision, one audit row, one member
  notification. The loser asking for the same outcome gets the recorded decision back
  (`meta.changed = false`); asking for the opposite gets 409 `already_decided` and nothing changes.
- One rejected check can be answered once (unique `resubmission_of_id`); a retried resubmission with
  the same submission id is replayed, not refused.
- At most `narcotics_checks.max_pending_per_member` (10) checks wait at once.

## 4. Photo pipeline and storage (server)

`NarcoticsImageProcessor`: size cap (15 MB), file type from its signature (JPEG, PNG, WebP; HEIC only
when Imagick can decode it), script markers (`<?php`, `<script`, `<html`, ...) refuse a polyglot,
header pixel cap (40 MP) and memory estimate before decoding, EXIF orientation applied by a built-in
parser (no ext-exif needed), transparency flattened to white, long edge bounded to 3200, minimum 480.
The picture is decoded and **re-encoded from pixels**, so all metadata and any appended bytes are gone.
Smaller of WebP and JPEG is kept; full (<= 3200), preview (1280) and thumb (360) share one format.
`NarcoticsImageStore` writes to the **private** disk (`filesystems.private`) under
`narcotics-checks/YYYY/MM/<random uuid>/{full,preview,thumb}.<ext>`; all-or-nothing, cleaned up when
the database write fails or loses a race. No public disk, symlink or URL exists for these files.

Delivery: `GET /api/narcotics-checks/{uuid}/image/{thumb|preview|full}` returns bytes only to the
submitting member or a reviewer, **404 for everyone else** (and for unknown, purged or disabled).
`Cache-Control: private, no-store`, `nosniff`, `Content-Security-Policy: ... sandbox`, `noindex`,
CORP same-origin. Browser JSON never contains a path, disk or hash.

All routes are under `/api/`: the Daily Checkout service worker never stores `/api/` responses, so a
photo is never cached on a shared device (`tests` assert this prefix).

## 5. Routes (`routes/narcotics.php`, web middleware: session + CSRF)

Member (`auth:web` + `EnsureEmployeeAuthenticated`): `GET form` (limits, accepted picture types and the
rescue-unit settings: `rescue_units` = every option in order with `Other` last, `rescue_unit_other_option`,
`rescue_unit_other_max_length`, and `seal_number_max_length`), `GET mine`, `GET mine/{uuid}`,
`POST /` (multipart `photo`, `client_submission_id`, `rescue_unit`, `rescue_unit_other?` (only read when
`rescue_unit` is `Other`), `seal_number`, `note?`, `resubmission_of?`; throttle 12/min). Check JSON carries
`rescue_unit` (what people read: "R22", or the typed text; null when not recorded) plus
`rescue_unit_choice` and `rescue_unit_other` (the stored values, used to prefill a resubmission), and
`seal_number` (as typed; null when not recorded).
Shared: `GET {uuid}/image/{variant}`. Captain 5: `GET staff/meta` (plain `review:false` for
others, drives the tab), and behind `EnsureNarcoticsReviewer`: `GET staff/checks?scope=inbox|archive&status&q&date_from&date_to&page`,
`GET staff/checks/{uuid}` (records one `viewed` audit row per reviewer), `POST .../approve`,
`POST .../reject {explanation?}`. All responses use the EMS `{ok,data,meta}` / `{ok:false,error}` envelope.

## 6. Notifications

Existing architecture only: `inspection_notification_deliveries` ledger + `InspectionEventNotification`,
retried by `inspections:retry-notifications` (event keys `narcotics:check:{id}:submitted`,
`narcotics:check:{id}:decided:{eventId}`).

| Kind | Category | To | Text |
|---|---|---|---|
| `narcotics_check_submitted` | `captain5_ems` | the on-duty Captain 5 (all Captain 5 seat holders when none resolves), only people who still pass `canReview`, never the sender | in-app: "<member> sent a narcotics check (R22). Open Captain 5 to review it." (a second try reads "(R22, sent again after a rejection)"; no parentheses on a check without a unit; the seal number is never in it) |
| `narcotics_check_decision` | member request updates | the submitting member only | approved / "did not accept ... open MBFD Hub to see why" (the explanation is never in the notification) |

Push (lock screen) and email text for both kinds is the fixed "MBFD Hub update / Open MBFD Hub for details."
No note, explanation, photo, path, rescue unit, seal number or member name leaves the app in a notification;
the unit appears only in the in-app body, which is behind sign-in, and the seal number in none.

## 7. Retention and lifecycle

`narcotics-checks:prune-images [--days=N] [--force]` (scheduled daily 04:00 ET, only when
`NARCOTICS_CHECK_IMAGE_RETENTION_DAYS` > 0) deletes the photo **files** of decided checks older than
the window, sets `images_purged_at`, writes an `images_purged` audit row. Pending checks are never
purged; the record, decision and audit stay. Default 0 = keep. The window must come from department
policy; this feature does not assert what that is.

## 8. Captain 5 UI

`/daily/captain-5?tab=narcotics[&check=<uuid>]`: **Narcotics Checks** tab (badge = checks to review)
and a "N narcotics checks are waiting" callout above the workspace, for reviewers only (the tab is
built from `staff/meta`). Inbox oldest first; Archive with Approved/Rejected chips, member/unit/seal number/note
search and Eastern date range. Each row shows "Rescue unit: R22" and "Seal number: ZX-4471" (quiet on old
checks). A check opens in a sheet with a sharp inline preview, **Zoom** (full size, +/-, Fit, drag, double
click), the rescue unit and the seal number ("Not recorded" on old checks), the member's note, **Approve & Archive** (one action, no confirm) and
**Reject** (optional explanation form), history, and links between a rejected check and its answer.

## 9. Rollback

The migrations are additive and create no permission (the rescue-unit one adds two nullable columns and the
seal-number one adds one; their `down()` refuses to run outside local/testing, and old code ignores the columns). Old code ignores the tables, the config file and
the `narcotics_*` ledger rows (an old retry command would treat a stuck `narcotics:` row as a plain
inspection key and give up after its attempts). Photos and records are never deleted by a rollback.
`NARCOTICS_CHECKS_ENABLED=false` hides the card and closes every endpoint without touching data.
