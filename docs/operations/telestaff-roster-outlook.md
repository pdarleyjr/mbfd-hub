# TeleStaff 7-Day Roster Outlook

TeleStaff also emails a 7-day outlook to `info@mbfdhub.com`: seven separate emails, four times a day (about 07:00, 12:00, 19:00 and 00:00 ET). Each email carries one workbook, `Roster_Day_1.xlsx` through `Roster_Day_7.xlsx`. The sender and subject are the same as the 5-minute export (`donotreply@miamibeachfl.gov`, `Roster Report`), and so are the sheet and layout. `Day_1` is the calendar date the 5-minute export is sending, and `Day_2` to `Day_7` are the six days after it. Facts were read from the first real deliveries on 2026-11-02. Before this feature, the classifier rejected these emails as `near_miss, filename_mismatch`.

Members see one roster page. It opens exactly as before, on whatever day TeleStaff's 5-minute export is sending. With the outlook on:
- A day strip at the top of the page, above "Last updated", reaches the next six days. The owner chose the top on 2026-11-02. The roster still starts right under "Last updated", and the existing Previous and Next links at the bottom now also step into those days.
- Each day is an ordinary `?date=` link, so deep links and the Back button work.
- Outlook days carry an **Outlook** tag and a note with the time TeleStaff last updated them and that they may change. After 7 hours without an update, the note says it may be out of date.
- Outlook days do not poll.
- Days with no outlook yet show as "not available yet".

The Crew Planner sees the same days, labelled "Outlook, updated <time>, may change".

## Rules

- **The 5-minute export always wins.** Outlook data never replaces a roster the 5-minute export is keeping current. When a 5-minute export arrives for a date that is showing outlook data, it takes over even if its export time is earlier.
- **Outlook fills gaps.** It may fill a date that has no roster, replace an older outlook for that date, or replace the 5-minute export's roster for the current day only when that feed is stale (`RosterFreshnessResolver::isStale`). Outlook data showing for the current duty day counts like the 5-minute export everywhere: the 300 and Captain 5 pages, cards, move alerts and the Crew Planner. That was the owner's decision on 2026-11-02.
- **Integrations stay on the 08:00 to 08:00 duty day.** Only the page's default view follows the 5-minute export. Outlook dates never change what the page opens on.
- **A pinned date holds outlook data too.** It is skipped quietly, because the pin is an administrator's own decision.
- **Unchanged days write nothing.** A matching content hash only confirms the date. A deferred or older outlook stores no snapshot. Most of the 28 daily emails therefore end as `superseded` or `confirmed` and are removed like any other no-effect export.

## How it fits the existing pipeline

One email is one ledger row and one duty date, exactly as for the 5-minute export, so there is no fan-out and no new key.

| Piece | Change |
|---|---|
| `roster_imports.source` | New nullable column. `outlook` marks outlook rows; null is the 5-minute export. |
| `roster_active_versions.source` | New nullable column. `outlook` while a date is showing outlook data; null otherwise. |
| `TeleStaffMailClassifier::classifyOutlook` | Its own rules (`daily_roster.outlook.classifier`). Same authentication, recipient and attachment checks. The live rules are untouched. |
| `RosterImportRegistrar` | Checks the outlook rules first, only when the outlook is on. Otherwise mail is classified exactly as before. |
| `TeleStaffWorkbookParser::parse` | Optional third argument: how many days after the export time the duty date may be. It defaults to the existing 2 days; outlook rows pass 7. |
| `RosterPublisher` | Outlook rule and 5-minute takeover, under the same pointer lock. |
| `RosterAnomalyGuard` | A 5-minute export compares only with 5-minute rosters, so outlook data never becomes its yardstick. |
| `RosterFreshnessResolver` | "Next shift posted", arrivals that prove the feed is alive, and held exports all count only the 5-minute export. |
| Page and Crew Planner | Read `CurrentRosterReader::forDutyDate`, as before. `RosterView::$isOutlook` drives the label. |

Outlook rows use the same queue (`default`), job, timeouts, verification, cleanup, reconcile and retention. No scheduler or polling was added. Outlook pages do not poll; they refresh when you navigate.

## Storage estimate

28 ledger rows a day. Rows with no effect are removed 30 days after cleanup, so the ledger holds about 840 outlook rows at any time, against about 8,600 for the 5-minute export. A snapshot (about 20 sections and 120 assignments) is written only when a future day's content changes. Replaced versions age out after 30 days under the existing `replaced_snapshot_days`. No new table or file store.

## Turning it on and off

```
DAILY_ROSTER_OUTLOOK_MODE=shadow   # parse and check every outlook email, publish nothing
DAILY_ROSTER_OUTLOOK_MODE=publish  # fill the next days (needs DAILY_ROSTER_MODE=publish too)
DAILY_ROSTER_OUTLOOK_MODE=off      # default: Roster_Day mail is classified exactly as before
```

After changing it, run `php artisan config:cache` in the Hub container. Turning it off takes effect at once:
- New outlook mail is classified exactly as before.
- Mail already queued waits.
- Every date showing outlook data disappears from the page, the Crew Planner and seat access (`CurrentRosterReader` and `RosterSeatAccess` skip pointers marked `outlook`), so only the 5-minute export's rosters are read.

Nothing needs cleaning up. Turning it back on shows those dates again, and the 5-minute export replaces each one as it arrives.

The tests named `*Outlook*` under `tests/Feature/DailyRoster` and `tests/Unit/DailyRoster` cover the rules above.

In shadow mode, open **System > Roster Imports** and turn off "Hide exports that changed nothing". Outlook rows show "7-day outlook" under their duty date. Each one should end `shadowed` for its own date, with no hold reasons.

Outlook trouble (held, rejected or failed rows) stays in that ledger and is reported once a day to error monitoring. It never uses up the 5-minute export's admin alerts. Admins still get the 5-minute export's late and stale alerts even while the outlook covers the day.

When a current day the outlook covered is taken back by the 5-minute export, roster move alerts compare the two like any other change. That follows the owner's decision that outlook data for the current day counts like the 5-minute export.
