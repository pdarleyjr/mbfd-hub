# Roster move alerts and member cards

Owner request, 2026-11-01. The owner asked for this to be designed and built without a question round, so the calls marked **builder's call** are open to change.

## Why

A member can be moved to another unit in TeleStaff mid-shift and not know until someone tells them. Once TeleStaff sends the current date, the Hub sees every published change, so it can tell the member directly. The roster page should also answer "who is this person" without leaving the page.

## Part 1: In-shift move alerts

### Goals

- A member on duty gets a push and an in-app alert when TeleStaff changes where or when they work for the rest of this shift.
- Nothing is sent before the member's shift. The shift is the 08:00 to 08:00 duty day.
- Each member can turn it off, per channel, in Alert settings.
- No email of any kind. The category does not offer that channel.

### Non-goals

- No alert about tomorrow's roster or any other date.
- No alert about someone else's move.
- Nothing grants or removes access: the roster stays display only.

### What counts as on duty

A member is on duty when the roster they were last shown (the baseline) has a working row for them covering this moment. Someone who starts at 20:00 and is moved at 15:00 gets no alert, because that is beforehand. Someone who finished at 20:00 gets none at 21:00. **Builder's call:** this follows the member's own hours inside the 08:00 to 08:00 window, which is the owner's "during that member's shift".

### What counts as a change

For each on-duty member, compare the rows on both rosters that are not over yet: unit, seat rank and times.

- A different unit or seat, or a new unit later in the shift: alert.
- A change of hours on the same seat, or being taken off the rest of the shift (such as marked sick): alert. **Builder's call:** a release or hold-over affects the member the same way a move does.
- A status code change with the same unit and hours, certifications, annotation rows, row order, or an edit to hours already worked: no alert.
- Rows with an unrecognized code count as placement, so a code the Hub does not know cannot look like a removal.

### How it runs

A small table holds one baseline per duty date: the roster last compared.

1. A verified publish queues `DetectRosterMoves`, and `daily-roster:reconcile` runs the same check every minute, so a lost job or an administrator's rollback is still covered.
2. With the baseline row locked, compare the baseline with the roster now showing, move the baseline, and queue the alerts after commit.
3. Only the current duty date is ever compared. The first roster of the day only sets the baseline.

A baseline rather than a per-import diff means exports processed out of order cannot send a member an old move, a change undone before the check runs sends nothing, and a repeat run sends nothing.

### Guards against a false alarm

- **Unverified roster:** waits until the read-back passes.
- **Manual transcription:** a roster typed from screenshots may differ from TeleStaff's by a typing slip, so any comparison with one on either side only moves the baseline. When TeleStaff starts sending the current date, replacing a manual roster alerts nobody.
- **Too many at once:** more than `DAILY_ROSTER_MOVE_ALERTS_MAX_MEMBERS` (default 20) changed members in one comparison looks like a bad export, not a real move. No member alert goes, and administrators get one alert per duty date. **Builder's call** on the number.
- **Kill switch:** `DAILY_ROSTER_MOVE_ALERTS_ENABLED=false` keeps the baseline moving and sends nothing.

### The message

- Push title, the part that shows on a lock screen: `Roster move: Rescue 3 from 13:00`. It carries a unit and a time, never a name or a reason. The body is the standard "Open MBFD Hub for details."
- In-app: the same title, then `Was:` and `Now:` lines with unit, seat and times. It opens the Daily Roster.

## Part 2: Member card

Selecting a member's name on the roster opens a card, for anyone who can already read the roster.

- **This roster:** each row for that member on the roster on screen: unit, seat, status and times.
- **TeleStaff certifications:** every certification the export prints for them, as printed.
- **Current bid:** term, shift, station, unit, position, A-Day and assignment type, from the same single current term the personnel directory uses.
- **Hub profile:** name, rank, station and city email.

**Builder's call:** the phone number stays out. It was not asked for by name, it may be personal, and nothing else member-facing shows it. Account status, last login and permissions stay out as well.

The endpoint only answers for an ID on the roster being viewed, so it cannot be walked to read the whole personnel list. It is throttled, never cached, and a departed member shows TeleStaff data only. Vacancies have no card. The card closes and clears when access ends.

## Part 3: The top of the page

Owner request while this was being built: remove the legend, the search and filter card and everything else from the top, keep only the last update, and start the roster as high as possible.

- Above the roster: **Last updated**, plus a warning only when one applies (roster late, stale, under review, offline, or an earlier date on screen). A member must not mistake an old roster for today's.
- Search, the status filter, and Expand all and Collapse all are gone, with their code.
- **Builder's call:** date links, Refresh, Export PDF, the legend, the summary and the other times move under the roster rather than being deleted, because members still need to reach earlier rosters and read a status color.

## Part 4: Getting alerts to phones

Owner request on release: turn push on for members with the installed app, and have future members start with it on.

- **What a site cannot do:** iPhones and Android require the person holding the phone to tap Allow once. No server setting can enroll a device.
- **What was already there:** the installed app shows a one-tap card on every signed-in page (`push-sync.ts`), and turns alerts on by itself when permission was already given.
- **Changed:** "Not now" brings the card back after a day instead of two weeks.
- **Changed:** seeded settings rows nobody chose no longer leave a member with push off. The earlier migration only covered accounts active that day, so people who activated later kept rows with push off.
- **Builder's call:** no prompt on the first tap anywhere. A reflex "Don't Allow" on the system dialog is permanent on an iPhone unless the member finds the setting, so the card, whose "Not now" costs nothing, stays in front of it.

## Part 5: A queue outage

The queue is Redis, so it can be down while the database is up. Alerts the queue refuses are kept in `notification_dispatch_retries` and offered again every minute. Roster move alerts are written there inside the baseline's own transaction, because nothing else would ever raise them again.

## Limits to know

- A push needs a phone that turned on alerts. On 2026-11-01 there were 14 enrolled devices across the Hub and 36 of the 81 people on the sampled roster had an active account. Everyone else gets nothing until they activate and enroll; those with an account still get the in-app copy.
- The Hub learns of a move at the next export, so an alert is at most one export interval behind TeleStaff. On today's once-a-day schedule it is effectively off until TeleStaff exports more often.
