# TeleStaff Daily Roster

TeleStaff emails `Dailyroster.xlsx` to `info@mbfdhub.com`. The Hub turns each trusted export into a read-only member roster and feeds the 300 and Captain 5 cards for display. Members see `/daily-roster`; administrators see **System > Roster Imports**. There is no manual import in the normal path.

## Flow

1. Cloudflare Email Routing passes the message to Worker `mbfd-hub-inbound-email`, which signs it to `POST /api/v2/email/inbound`. No upstream mailbox exists; the Hub holds the only copy we control.
2. `InboundEmailAuthenticator` stores the row. In the same transaction `RosterImportRegistrar` classifies it and writes a `roster_imports` ledger row. Ordinary mail writes nothing and is unaffected.
3. `ProcessRosterImport` (queue `default`, after commit) claims the row, parses the workbook, runs `RosterAnomalyGuard`, and `RosterPublisher` moves the `roster_active_versions` pointer in one transaction.
4. `VerifyRosterImport` reads the roster back through `CurrentRosterReader`. Only then does `CleanupRosterImport` delete that email and attachment.
5. `daily-roster:reconcile` (every minute) repairs lost dispatches, expired leases, unverified publishes and pending cleanup. It never fabricates data.

## Frozen contract (all implementers)

### Source facts (from the real export, 2026-10-30)

- Sender `donotreply@miamibeachfl.gov`, subject `Roster Report`, attachment `Dailyroster.xlsx`, one sheet `Roster Report`. Delivery seen at 09:14 ET, so never hard-code 08:00.
- The duty date exists only in the print header: `Roster Report&B  [10/30/2026]`. The export time exists only in the footer: `&L10/30/2026 09:14:15&R`. Header and footer are in `xl/worksheets/sheet1.xml`. PhpSpreadsheet does not load them with `setReadDataOnly(true)`, so read them with `ZipArchive` + `XMLReader`.
- Division titles: `Miami Beach Fire Department / <division> / <shift label>`. The published block is `Suppression/Rescue` with `^[ABC] Shift$`. It ends at the next title or end of sheet, never at a blank row or a fixed row.
- Column header row: `Rank | ID | Name | (code, unlabelled) | From | Through | (hours, unlabelled)`. The section title is the first row after a header, with only the rank column filled.
- Blank rank continues the previous seat. Identical adjacent ranks are distinct seats. The first row of a seat may be an absence backfilled by the rows below.
- `-,-` is a suppressed vacancy, `?,?` an open vacancy. Vacancies have no ID and no code.

### Code classes

`availability` is one of `working | not_working | annotation | unknown | vacant`. Display raw code spelling verbatim on request; an unknown code is never treated as working.

| Base code | Availability | Category | Label | Evidence |
|---|---|---|---|---|
| SP | working | Scheduled | SP | Crew Planner |
| +OT, OT | working | Overtime | as printed | Crew Planner |
| 2WXON, 2WXEON (also with U+2927 prefix) | working | Exchange | base | Crew Planner, fill colour |
| ADONIS DAY (also `.`) | not_working | A-Day | A-Day | Crew Planner, seat structure |
| VACA, VAC (also `.`) | not_working | Vacation | base | Crew Planner, seat structure |
| EVAC | not_working | Leave | EVAC | Crew Planner, seat structure |
| 2WXOFF, 2WXEOFF (with `.` or U+2927) | not_working | Exchange off | base | Crew Planner, fill colour |
| ADMS | not_working | Leave | ADMS | Seat structure and fill colour only; meaning unconfirmed |
| MILNP | not_working | Leave | MILNP | Seat structure and fill colour |
| MILA (also `.`) | not_working | Military leave | MILA | Department, 2026-11-01 |
| AHTA (also `.`) | not_working | Accrued Holiday Time Annual | AHTA | Department, 2026-11-01 |
| BER (also `.`) | not_working | Bereavement leave | BER | Department, 2026-11-01 |
| EFLO (also `.`) | not_working | Emergency Floater | EFLO | Department, 2026-11-01 |
| SIC, SIC RVT | not_working | Sick | as printed | Crew Planner (SIC), seat structure |
| PENFML (`.`) | not_working | Leave | .PENFML | Fill colour, vacation-app wording |
| DAYOFF (`.`) | not_working | Day off | Day off | Seat structure |
| ^OOC, ^ROC, ^MOC, ^TOC | annotation | Incentive | as printed | Crew Planner |
| anything else (GWA, TRNADM, .PMS, +OTD15FI, ...) | unknown | Other | raw code | Not defined anywhere |

`baseCode` strips only a leading `.` or U+2927; `^` stays part of the code. `prefix` keeps what was stripped. A leading `.` on a code with no table entry stays `unknown`.

### Intervals

Wall clock in America/New_York on the duty date. `From` earlier than 08:00 moves to the next day. `Through` at or before `From` is the next day. `reportedHours` must equal elapsed or wall-clock hours within 0.05. The 2026-10-31 duty day is 25 elapsed hours and TeleStaff's reported value there is unobserved, so both must pass.

### Fail-closed codes (`TeleStaffWorkbookException::$errorCode`)

`file_too_large`, `not_a_zip`, `zip_entry_unsafe`, `zip_limit_exceeded`, `part_not_allowed`, `external_relationship`, `doctype_rejected`, `formula_rejected`, `sheet_mismatch`, `date_missing`, `generated_missing`, `generated_far_from_date`, `block_not_found`, `block_ambiguous`, `shift_unreadable`, `shift_calendar_mismatch`, `header_unrecognized`, `row_unrecognized`, `seat_without_rank`, `vacancy_has_id`, `id_without_code`, `id_malformed`, `hours_mismatch`, `interval_outside_window`, `section_missing_command_300`, `section_missing_captain_5`, `read_failed`.

A single unknown code is not a failure: it displays verbatim and raises a row-level review reason.

### Mail classifier (`TeleStaffMailClassifier`)

Cloudflare's header format has never been observed live, so the rule depends on neither `Authentication-Results` nor `ARC-Authentication-Results` alone. For each kind the Worker forwards the first header in raw order, plus `cloudflare_claims`: how many headers of that kind name `mx.cloudflare.net` as their authserv-id (after an optional `i=N;` for ARC, case-insensitive), counting headers it did not forward. A header over 4,096 characters is sent as `null` with `truncated: true`, never cut. `InboundEmailAuthenticator` stores these in `transport_evidence`.

A claim is a forwarded header naming that authserv-id. Every claim must pass, because a forged message always gets Cloudflare's real failing claim next to any injected passing one. That closes injection provided Cloudflare always adds at least one claim, which the first real delivery must confirm.

Checked in this order:

| Verdict | When |
|---|---|
| `rejected` | Any claim has `dmarc=fail`, `spf=fail`, `dkim=fail` with `header.d` or `header.i` on the sender's domain, or `dmarc=pass` with a `header.from` on another domain. |
| `needs_review` | No claim at all. Any value truncated. Any claim unparseable. A parsed claim with no results (`mx.cloudflare.net; none`). More than one claim of a kind. A counted claim that was not the header forwarded, or a forwarded claim that was not counted. No counts at all, as from an earlier Worker. |
| `needs_review` | On any claim: DMARC absent or not `pass`, `header.from` missing, or no `dkim=pass` with `header.d` or `header.i` aligned to the sender's domain (`dkim_not_aligned`, including an SPF-only pass). A `dkim=fail` naming no domain. |
| `needs_review` | Envelope sender missing or not aligned to the sender's domain. |
| `match` | Otherwise, with reason `authenticated_by_<kind>` for each claim. |

`spf=softfail` alone neither rejects nor matches. A `dkim=fail` for another domain is ignored, because relays sign too. Sender, subject, filename and attachment rules sit around this and are unchanged.

`roster_imports.classification.evidence` keeps the two results headers, `received_spf`, the envelope sender, the counts and `truncated`, which are sender and domain data with no member data, so the proof survives deletion of the email.

### State machine (`RosterImport::STATE_*`)

| From | To | Rule |
|---|---|---|
| none | `classified` / `rejected` / `needs_review` | Written in the inbound transaction. No row for ordinary mail. |
| `classified` | `queued` | After-commit dispatch, conditional update. |
| `queued`, due `retryable_failed` | `parsing` | `UPDATE ... WHERE id AND state IN (...)` must affect 1 row; attempts + 1, lease set. |
| `queued` | `duplicate` | Same attachment hash as a live import. |
| `parsing` | `validated` / `needs_review` / `permanent_failed` / `retryable_failed` | Workbook exceptions are permanent. I/O problems retry with `processing.backoff_minutes`. |
| `validated` | `shadowed` | `mode=shadow`. Terminal until reprocessed. |
| `validated` | `published` / `superseded` / `duplicate` / `needs_review` | One transaction; pointer row locked with `lockForUpdate`. |
| `published` | `verified` | Read-back. Mismatch goes to `needs_review` and `report()`. |
| `verified`, `duplicate`, `superseded` | `cleaned` | Only with `cleanup_enabled`. `outcome` keeps what happened. |
| `validated` | `retryable_failed` / `permanent_failed` | A publish fault or a crash after validation. |
| `needs_review` | `superseded` | A newer passing export for the same date published. |
| `needs_review` | `published` | Re-checking a failed read-back, without re-parsing. |
| `rejected`, `shadowed`, `permanent_failed`, `retryable_failed`, `needs_review` | `queued` | Administrator or operator reprocessing, with an audit event. |

Publish decision, in order: pinned date holds (`needs_review`); hash equals active snapshot confirms `last_confirmed_at` (`duplicate`, outcome `confirmed`); hash equals an inactive snapshot and generated time above effective re-points (`published`); new hash and generated time above effective creates a version and re-points (`published`); equal generated time with a different hash holds (`needs_review`); older generated time stores the snapshot unpointed (`superseded`). A future date gets its own pointer and becomes current on its own at 08:00, because readers derive the date. Past dates never touch another date's pointer.

### Cleanup

Delete only when: mode is `publish`, `cleanup_enabled` is on, the ledger row is `verified` or a `duplicate`/`superseded` whose chain reaches a `verified` or `cleaned` import within 10 steps (a `superseded` row also needs its own snapshot), the inbound row's message-id hash and attachment hash match the ledger, and no `outbound_emails.parent_inbound_email_id` references it (`kept_replied`). An attested import cleans like any other, because the ledger keeps its evidence. Order: mark `pending`; in one transaction copy paths and delete the row, set `row_deleted`; delete the file, where already missing counts as success; set `done`, state `cleaned`. `InboundEmailResource::canDelete` stays false. Rejected, held and failed mail is never deleted. A redelivered message whose ledger row is cleaned is acknowledged without storing it.

### Interfaces

```php
// Parser
App\Services\DailyRoster\Parsing\TeleStaffWorkbookParser::parse(string $absolutePath, string $sourceSha256): TeleStaffRosterReport  // throws TeleStaffWorkbookException
App\Services\DailyRoster\Parsing\TeleStaffCodebook::classify(string $rawCode): TeleStaffCodeClassification
    // readonly: rawCode, baseCode, prefix, availability, category, displayLabel

// Transport
App\Services\DailyRoster\TeleStaffMailClassifier::classify(InboundEmail $email): TeleStaffMailClassification
    // readonly: verdict 'match'|'needs_review'|'rejected'|'ordinary', reasons list<string>
App\Services\DailyRoster\RosterImportRegistrar::register(InboundEmail $email): ?RosterImport   // inside the inbound transaction
App\Services\DailyRoster\RosterImportRegistrar::isCleanedRedelivery(string $messageId): bool
App\Services\DailyRoster\RosterImportCleanup::run(RosterImport $import): void                  // idempotent

// Domain
App\Jobs\DailyRoster\{ProcessRosterImport, VerifyRosterImport, CleanupRosterImport}            // constructor (int $importId), $tries = 1
App\Services\DailyRoster\RosterPublisher::publish(RosterImport $import, TeleStaffRosterReport $report): void
App\Services\DailyRoster\RosterAnomalyGuard::check(RosterImport $import, TeleStaffRosterReport $report): list<string>  // hold reasons
App\Services\DailyRoster\RosterPublicationVerifier::verify(RosterImport $import): bool
App\Services\DailyRoster\RosterRollbackService::activate(User $actor, string $dutyDate, int $snapshotId, string $reason): void
App\Services\DailyRoster\RosterRollbackService::release(User $actor, string $dutyDate, string $reason): void
App\Services\DailyRoster\RosterImportOperations::retry(RosterImport $import, ?User $actor): void      // failed or held, source file still present, mail check passed; else retry_needs_attestation
App\Services\DailyRoster\RosterImportOperations::retryAttested(RosterImport $import, ?User $actor, ?string $reason = null): void  // rejected, held by the mail check, or layout_changed; records operator_attested, keeps evidence
App\Services\DailyRoster\RosterImportOperations::retryCleanup(RosterImport $import, ?User $actor): void  // cleanup_state failed; resets attempts and state
App\Services\DailyRoster\RosterStateRecorder::transition(RosterImport $import, string $to, array $attributes = [], ?string $reason = null, array $context = [], ?int $actorId = null): RosterImport

// Read model (the only way consumers read the roster)
App\Services\DailyRoster\CurrentRosterReader::current(?CarbonInterface $at = null): ?RosterView      // exact current duty date only
App\Services\DailyRoster\CurrentRosterReader::forDutyDate(string $dutyDate): ?RosterView
App\Services\DailyRoster\CurrentRosterReader::latestBefore(CarbonInterface $at): ?RosterView         // stale display only; never for command cards
App\Services\DailyRoster\CurrentRosterReader::sectionsForRole(?CarbonInterface $at, string $role): list<RosterSectionView>  // by position; more than one means conflict on the cards
App\Services\DailyRoster\CurrentRosterReader::availableDutyDates(int $limit = 14): list<string>
App\Services\DailyRoster\RosterFreshnessResolver::status(?CarbonInterface $at = null): RosterFreshness
    // readonly: state 'current'|'awaiting'|'delayed'|'needs_review'|'none', dutyDate, asOf, version string
App\Services\DailyRoster\RosterView       // readonly: snapshot (RosterSnapshot), sections (list<RosterSectionView>), asOf
App\Services\DailyRoster\RosterSectionView // readonly: section (RosterSection), assignments (list<RosterAssignment>)

// Access (no new permission; reuses admin.system.view / admin.system.manage for admin actions)
App\Services\Security\DailyRosterAccess::canView(?User $user): bool

// Command display (never used for authorization or routing)
App\Services\DailyRoster\OnDutyOfficerDisplayResolver::resolve(string $role, ?CarbonInterface $at = null): OnDutyOfficerDisplay
```

Flags are in `config/daily_roster.php`: `mode` (`off|shadow|publish`), `cleanup_enabled`, `card_display_enabled`. Defaults are the safe state.

### Non-negotiables

- `Captain5DutyRoster` reads the bid seat only. Shift Captain 5 alerts follow the roster through `OnDutyCaptain5Recipients`, and only reach someone who can use the Captain 5 page.
- By the owner's decision of 2026-11-01, working the 300 or Captain 5 seat on the accepted roster opens that page for the duty day (`RosterSeatAccess`, `DAILY_ROSTER_SEAT_ACCESS_ENABLED`). It is read live and never stored: nothing writes a role or permission from a spreadsheet, and it never opens the Admin Panel or Captain 5 settings. An administrator's Deny on Captain 5 beats it. Migrations grant nothing.
- No real names, IDs or the real workbook in the repo. The repo is public and gitleaks scans history. Fixtures are synthetic (`tests/Support/TeleStaffRosterWorkbookFactory.php`).
- No workbook text, names or IDs in logs, exceptions, metrics or `roster_events`. Row numbers and codes only.
- Tests use `HubTimezone::today()` or frozen time, are parallel-safe, and run on SQLite and Postgres.
- Never change a service worker, manifest, `package.json`, or `InboundEmailResource::canDelete`.

## Operations

### What normal looks like

Nothing to do. **System > Roster Imports** shows each email ending at **Complete, email removed**. Members see **Data current** on `/daily-roster`. Hold states tell you the reason in words.

### Rollout flags

Set in `/opt/mbfd/mbfd-hub/.env`, then rebuild the config cache and restart the workers (`php artisan config:cache`, `php artisan queue:restart`).

| Step | Flags | Proves |
|---|---|---|
| 1. Shadow | `DAILY_ROSTER_MODE=shadow` (default) | Real mail classifies and parses. Nothing published or deleted. Read the first real delivery's **Mail check evidence**: this is where Cloudflare's real header format, that it always adds a claim, and the `d=` value get confirmed. |
| 2. Publish | `DAILY_ROSTER_MODE=publish` | Members see the roster. |
| 3. Cards | `DAILY_ROSTER_CARD_DISPLAY_ENABLED=true` | 300 and Captain 5 cards read the roster for display. |
| 4. Cleanup | `DAILY_ROSTER_CLEANUP_ENABLED=true` | The verified email and attachment are removed. |

After flipping to publish, **Retry** today's shadowed import under **System > Roster Imports**, because a shadowed import stays where it is until retried. Members see today's roster, and its email is cleaned up once cleanup is on.

Enabling cleanup also sweeps earlier rows. `daily-roster:reconcile` picks up every `verified` import and every `duplicate` or `superseded` one backed by a verified import, however old, and deletes those emails and attachments within minutes. Check the list before turning it on.

`DAILY_ROSTER_MODE=off` returns inbound mail to exactly its earlier behaviour. Each flag can be reversed without a deploy. Reverse order to back out.

The Worker is separate: `cloudflare-email-worker` needs its own `wrangler deploy`. It only adds fields the Hub ignores until this release is live, so deploy it first. Until it is live, genuine TeleStaff mail lands in **Needs review** because the transport evidence is missing, and is never published.

### Code colors and PDF export

Code colors match the TeleStaff online roster, read on 2026-10-30. They are defined once in `RosterStatusPresenter::TONE_COLORS` and mirrored in the `--hub-roster-*` tokens, and a test keeps the two equal. TeleStaff's red and pink are darkened about 10 percent, because white text on the originals misses WCAG AA. Color is display only and never decides who counts as working. `.PMS` and `HOOT` keep TeleStaff's color but still read **Not recognized**, because the Hub does not classify them.

**Export PDF** on the roster page downloads the entire roster for the date on screen as landscape Letter, ignoring any search or collapsed sections, with its date, shift, times and any warning. It holds what the Hub publishes, the Suppression/Rescue block, not TeleStaff's other divisions. Access is the same as the page, it is never cached, and each member is limited to 10 exports a minute.

### Training Crew Planner

The Crew Planner reads this roster and nothing else, through `TrainingRosterDutySource` and `CurrentRosterReader::forDutyDate()`. It has no upload and no parser of its own. A date with no accepted export has no staff: the planner says why and offers a provisional list, labelled wherever the plan goes, and never borrows another day's roster. Working intervals, the A-Day and overtime rule, and identity by exact employee ID behave as they do for the 300 card. Codes and leave reasons are never copied into a plan. When a newer export changes the duty of someone on a plan, the plan keeps its list and waits for the planner to acknowledge the change before it can be exported or sent.

### When TeleStaff posts the next shift early

A duty day runs 08:00 to 08:00. As soon as an export for the next duty date is accepted, `/daily-roster` opens on it, tagged **Next shift**, with a note saying when it starts and a link to the shift on duty. The PDF follows the page. Everything else stays on the duty day on the clock until 08:00: the 300 and Captain 5 cards and pages, and the shift Captain 5 alerts. Inside a duty day they change hands at the times the roster prints, for example 20:00 on a split seat. `RosterFreshnessResolver::pageStatus()` is the page's answer, and `status()` is everyone else's.

Corrections TeleStaff makes to the shift on duty arrive only while its report still covers that shift. Once the report moves to the next date, the shift on duty stays as last exported.

### Who the roster lets in

With `DAILY_ROSTER_SEAT_ACCESS_ENABLED=true`, anyone with a working row in the `Division Chief 300` or `Captain 5` section of the accepted roster for the duty day on the clock can use that page from 08:00 to 08:00, including everyone on a split seat. The same route opens what each page depends on: 300 messages, Department Updates, Morning Lineup command controls and Command Board sign-in; Captain 5 issues, handoff, messages and EMS inventory. Narcotics review goes only to whoever the roster has on the seat at that moment. Bid holders, chiefs and administrators do not depend on the roster.

`daily-roster:reconcile` records each change of seat holders as a `seat_holders_changed` event, with Hub user IDs only. It also alerts administrators once when someone on the seat, today or next shift, has not finished setting up an account, or would get the page from the roster alone, without a rank, bid seat or grant. The second alert is usually an ordinary overtime ride, but it is how a bad or forged export gets noticed: such an export can name anyone until a genuine one replaces it. Set the variable to `false`, then `php artisan config:cache`, to close this route at once.

### Sending more often

The pipeline is built for exports every few minutes. When TeleStaff's schedule changes, add this to `/opt/mbfd/mbfd-hub/.env`, then rebuild the config cache and restart the workers:

```bash
DAILY_ROSTER_EXPECTED_INTERVAL_MINUTES=5
```

Nothing else changes, and sending more often works without it. The setting only tells the Hub what silence means: after 3 missed exports (`DAILY_ROSTER_STALE_AFTER_MISSED`) members see **Update delayed** with the last time heard, and administrators get one alert per outage. It recovers by itself on the next export. Without it, nothing is ever called stale and the 10:00 ET rule decides lateness.

What each export costs, at any frequency:

| The export | What happens |
|---|---|
| Changes nothing (almost all of them) | Parsed once, compared by hash, recorded, email removed. No new roster is stored. Open pages move **Last updated** in place and download no roster. |
| Changes one seat | One new immutable snapshot. Open pages replace that seat only. Scroll, search and open sections stay, and the spot fades from yellow so it can be found. |
| Adds or moves a section, or changes over half the seats | Open pages take the whole roster, in place, without reloading. |
| Held or failed | The newest copy stays open for review and older copies are marked superseded. One alert and one error report per kind per day, however many arrive. |

Open pages ask every 60 seconds whether anything changed, and only while visible. That question does not grow with the roster.

Reading the page: **Last updated** is the last export the Hub accepted, **Report generated** is when TeleStaff made it, and **Roster last changed** is when the people on it last changed. The last line appears only when it differs from **Last updated**.

Under **System > Roster Imports**, exports that changed nothing are hidden by default. Clear **Hide exports that changed nothing** to see them.

### What gets removed, and when

`daily-roster:prune` runs daily at 03:37 ET, in batches. `--dry-run` counts without changing anything.

| What | After | Setting |
|---|---|---|
| Finished exports that changed nothing, with their history | 30 days | `DAILY_ROSTER_UNCHANGED_EXPORT_DAYS` |
| Roster versions that were replaced. This is the rollback window. | 30 days | `DAILY_ROSTER_REPLACED_SNAPSHOT_DAYS` |
| Held or failed emails that a later verified export overtook | 14 days | `DAILY_ROSTER_OVERTAKEN_EMAIL_DAYS` |

An email counts as overtaken only once a later export was accepted: a newer one for the same duty date, or any later one for mail that was never read. A held copy that merely finished does not count. Copies of a mail check hold collapse only when the reason is identical, so a different problem stays open for a person.

These always stay: the roster that was showing for each date, the ledger row for every published change, anything still held and never overtaken, and rejected mail.

The ledger row is also what recognises a redelivered message. Cloudflare's retries arrive within hours, far inside the 30 days. If one somehow arrived later it would be processed again and end as a duplicate, with no effect on the roster.

### When something is wrong

| You see | Means | Do |
|---|---|---|
| **Update delayed** on the page, alert at 10:00 ET | No export for this duty day | Check TeleStaff's scheduled report. Members still see the last good roster, marked. Nothing to repair in the Hub. Until a first roster has ever been published, the page reads as awaiting and no alert goes. |
| **Update delayed** while a roster is showing (only with a cadence set) | TeleStaff has stopped sending. One alert per outage. | Check TeleStaff's scheduled report. It clears by itself on the next export. |
| System Health shows **cleanup failed** | An email could not be removed after 5 attempts. It is still in the inbox. | Fix the cause the import's reason names, then use **Retry email cleanup**. The count clears once that row is no longer failed. |
| **Needs review** with "TeleStaff changed the report layout" | The table's column labels or spacing differ from the last accepted export | Open the import and read the findings. If TeleStaff really changed the report and the roster parsed correctly, use **Confirm genuine and retry**: the new layout becomes the one later exports are compared with. If the parser misread it, update the parser first. |
| **Needs review**, mail check reasons | Cloudflare's result was missing, unreadable, ambiguous, or did not prove the sender | Open **Mail check evidence**. Confirm with the source, then use **Confirm genuine and retry**. **Retry** is refused here. |
| **Rejected by the mail check** | A look-alike message, or authentication failed. It has its own alert. | Leave it unless you can confirm it is genuine, then use **Confirm genuine and retry**. The email stays in the inbox. |
| **Failed** | Workbook failed closed; the reason names the check | Retry only if the cause was temporary. The last good roster stays up. |
| Cards say **roster needs review** | The 300 or Captain 5 section could not name one person at that moment | Read that section on the Daily Roster. It corrects itself on the next good export. |

### Actions

All need `admin.system.manage` and write a `roster_events` row. Open an import under **System > Roster Imports**:

- **Retry** puts a failed or held import back through the pipeline. The stored attachment must still exist. It is not offered, and is refused, for mail the mail check did not pass.
- **Confirm genuine and retry** is the only way past the mail check, and appears only for rejected imports, imports held by the mail check, and imports held for a new layout. It needs a reason and a confirmation. It records `operator_attested` and your reason, keeps the original evidence, and sends the import back through every other roster check. A new layout then becomes the baseline. The import cleans up like any other afterwards, because the ledger keeps the evidence.
- **Retry email cleanup** appears when a cleanup has run out of attempts, while cleanup is on. It resets the attempts and tries again.
- **Show this version** points the duty date at that snapshot and pins it. Newer exports for that date are held until released. Nothing is deleted. Use this for rollback.
- **Release pinned date** lets the next export publish again. It does not republish by itself.
- **Check for stuck imports** runs `daily-roster:reconcile` now. It also runs every minute.

For mail received before the Worker change, or a row already rejected or held by the mail check, an operator can run this after confirming the message is genuine. The email must still match the configured sender, subject, filename and attachment shape exactly:

```bash
docker exec mbfd-hub-laravel php artisan daily-roster:import-inbound <inbound_email_id> --operator-attested
```

### Answering "what happened to this email?"

The ledger outlives the email, for the windows above. Find it by `attachment_sha256`, `correlation_id` or received time in `roster_imports`, with history in `roster_events`. Neither holds member names; those live in the snapshot tables, which stay after the email is gone, so the roster shown for a past date can always be shown again.

### Idle sign-out

Automatic refreshes on the roster page and the 300 and Captain 5 cards send `X-Hub-Background-Refresh: 1`, so they do not count as activity. A screen left unattended still reaches the idle limit, and the roster then leaves the screen. The Refresh button is real activity.

### Retention

- Cleanup removes the `inbound_emails` row and its stored attachment from the live system. Backups keep their own copies until the backup rotation expires them (14 daily, 8 weekly, 12 monthly), so "removed" means removed from the live Hub.
- There is no upstream mailbox. Cloudflare Email Routing only passes the message through. The City's sent copy is out of reach.
- The department confirmed on 2026-10-30 that no retention rule applies to these emails. If one is adopted, turn `DAILY_ROSTER_CLEANUP_ENABLED` off until cleanup honours it.

### Backing out a release

Roll back the Hub image normally. The tables are additive and an earlier image ignores them, so do not reverse the migration. Published rosters stay in the database. Mail arriving under the earlier image lands in the ordinary inbox and can be replayed later with `daily-roster:import-inbound`.
