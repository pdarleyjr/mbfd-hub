# AI Response automation

The Hub posts one short automated reply, signed **Support Services**, when a member submits something in three areas:

| Area | What gets a reply | Where the member sees it |
|---|---|---|
| Vehicle | A daily checkout that records Missing, Damaged or other equipment findings; a member message on a vehicle issue thread | The checkout's issue conversation |
| Station requests | A new station request (equipment, supplies, appliance or facility maintenance) and a station supply request | The member's request, as an update from Support Services |
| Support Services | A member message to Support Services (My Messages) | The message thread |

Nothing else is touched: no EMS or medical inventory, Captain 5, narcotics, rosters, staffing, incidents, personnel or uniform workflows, training, IT tickets or email. Clean checkouts get no reply.

A reply means *received by the Hub*. It never means reviewed, approved, ordered, repaired or fixed. Every submission stays exactly where it was in the staff lists, with its status, priority and acknowledgment fields untouched. Staff keep answering, acknowledging and closing items the normal way. The feature sends **no email and no push**; the member gets the reply on the record itself and one in-app bell notification (new submissions only), sent through the normal "Updates on things I submitted" category with push and email masked off.

## The master switch

**Admin > AI Response Automation > AI Automations.** The large card at the top reads **AI AUTOMATIONS ON** or **OFF**.

- **Turn OFF** is one tap with no confirmation. It stops every automated acknowledgment, reply, backlog batch and model call at once, including work already queued or in progress. Manual Hub workflows are unaffected.
- **Turn ON** asks for confirmation. From then on, *new* submissions get replies. Nothing older is answered until someone presses **Process Pending Backlog**, and work stopped by an earlier OFF is never revived by itself.
- It starts **OFF** after installation. Every change is listed under **Switch history** (who, when, how many queued replies an OFF stopped).

Who can use it: super administrators, and anyone granted the `admin.ai_automation.manage` permission. The permission exists after deployment but is granted to nobody.

**Emergency, from the server** (same switch, recorded as "from the server"):

```bash
docker exec -u sail mbfd-hub-laravel php artisan ai-automation:switch off
```

To turn the whole feature off regardless of the admin switch, set `AI_AUTOMATION_HARD_DISABLED=true` in the production environment and restart the container. Remove it to hand control back to the admin switch.

### How OFF is enforced

The switch lives in the database (`ai_automation_settings`), never in cache, so deploys and restarts cannot reset it. Each ON or OFF increases an *epoch* number. Every queued reply remembers its epoch and is checked three times: when a worker picks it up, after any model call, and inside the database transaction that writes the reply, holding a lock on the switch row. An OFF therefore either lands before the write (and nothing is posted) or waits for that single in-flight write to finish. A reply queued before an OFF can never post after a later ON.

## What the replies say

Members see the sender as **Support Services**, styled like any Support Services reply (owner decision: no "AI" title). Staff screens show **Support Services (automated)** so the team can tell it apart. Replies come from **Response Playbooks** (editable, versioned). The defaults:

| Situation | Reply |
|---|---|
| A missing vehicle item (any item) | `Acknowledged. Please make sure the {item} hasn't been misplaced and isn't somewhere around the station, on another apparatus, or left on a spare truck during a recent truck swap. If it's still missing, call or text us before heading to Station 2 so we can confirm we have a spare ready.` |
| A missing battery | Same, also asking them to check chargers and other tools such as the station blower |
| Several missing items | One reply naming them ("kitty litter, spanner wrench and K-tool"), plus the battery reminder if one is a battery |
| A readiness or safety item (SCBA, radios, monitors, lighting, brakes, tires, pump, aerial, PPE, saws…) or anything marked significant | Same reply, plus `If this affects your unit's readiness, notify your officer and 300 right away.` |
| Damaged item | `Acknowledged. We'll follow up if needed.` (readiness items: `Acknowledged.` plus the readiness line) |
| Station request that reached the staff work list | `Acknowledged. Forwarded to the appropriate personnel.` |
| Anything else, including bunker gear drop-offs | `Acknowledged.` |
| Saturday or Sunday (Eastern) — vehicle and station submissions, and Support Services messages that say it's needed now | Adds `Support Services staff are off this weekend. If this can't wait, contact 300. Otherwise we'll follow up on the next weekday.` (replaces the readiness line, since 300 covers it) |

Why: missing equipment is usually misplaced, on another truck or left on a swap truck. The reply gets crews looking first and calling ahead before driving to Station 2, so staff can confirm a spare is actually on hand. Weekends have no administrative staff; 300, the on-shift Division Chief, has supply room access.

A conversation (a Support Services thread or a vehicle issue thread) gets at most one automated reply per 24 hours. Unit and place names such as "Engine 2" or "Fire Station 4" are ignored when looking for safety words. The word lists (readiness and safety items, expensive items, batteries, urgency words, bunker drop-off, exposure) are on the Playbooks screen, one term per line.

## Questions and the AI model

When a member asks a question (in a Support Services message, on a vehicle issue thread, or in a station request) and a published Knowledge Library article matches, Cloudflare Workers AI (`@cf/openai/gpt-oss-120b`) writes a 1-3 sentence answer from that article plus every article marked **Use in every AI answer**. The answer is checked before it is sent: short, no greetings or promises, no document names, contact details or links, numbers must come from the article, and most of its words must come from the article. Anything that fails becomes `Acknowledged.`. Questions about readiness or safety items never go to the model. The historical backlog never uses the model.

Spend limits: 100 calls a day and $10 a month, reserved before each call (`AI_AUTOMATION_MODEL_DAILY_CALLS`, `AI_AUTOMATION_MODEL_MONTHLY_USD`). Today's calls and the month's spend are on the AI Automations page. Production settings (values only in the server environment): `AI_AUTOMATION_MODEL_ENABLED=true`, `AI_AUTOMATION_CF_ACCOUNT_ID`, `AI_AUTOMATION_CF_API_TOKEN` — a dedicated Cloudflare token (id `be014d3f92c830d518dc2671f0f7a0c1`, name `mbfd-hub-ai-response-2026-11-03`) with only Workers AI Read and Write on the MBFD account. Set `AI_AUTOMATION_MODEL_ENABLED=false` to stop model calls while keeping playbook replies.
## Knowledge Library

**Admin > AI Response Automation > Knowledge Library.** Short Support Services guidance articles in Markdown. Edit in the browser or upload a `.md`/`.txt` file, **Save draft**, **Preview**, **Submit for review**, **Approve & publish**, or **Withdraw**. Publishing takes effect immediately; no code deployment. Every version is kept with its editor, publisher and dates. The *Internal source note* records which procedure or owner decision an article came from and is never shown to members.

The starter guide is `resources/ai/knowledge/mbfd-support-services-guidance.md`; `php artisan ai-automation:seed` imports it (and the Playbooks defaults) once and never overwrites anything an administrator has edited. The internal source map is `docs/ai-response-automation/sog-crosswalk.md`.

**Use in every AI answer** marks standing facts (weekends and 300, calling ahead to Station 2, looking for missing items first) that every AI-written answer must follow; the starter guide's "How Support Services Handles Submissions" article is marked this way. Update it whenever how you work changes. Articles are only used to answer a member's question (not a report). Only published, member-safe articles inside their review date are used; withdrawn, stale or unmatched articles mean the member gets `Acknowledged.`. Library content stays in the Hub database; there is no external index to keep in sync.

## Historical backlog

Older submissions that never got a staff or assistant response:

1. **Preview Pending Backlog** counts them by area, shows why others are excluded (already answered, closed, test data, out of scope…), the oldest date and the exact replies that would go out. Nothing is sent.
2. Turn AI automations **ON**, then **Process Pending Backlog**. Batches of 10 run about every 20 seconds, one reply per submission, templates only (no model), no notifications. Every record is re-checked just before its reply, so anything answered by staff since the preview is skipped.
3. **OFF** (or **Pause**) stops it at once; the button then reads **Resume Pending Backlog** and continues where it stopped. Nothing is ever answered twice.

Start with the preview, process, and spot-check a few replies in Activity and on the member's screen before letting it finish.

## Activity

Every automated decision: when, area, record, live or backlog, sent / skipped / stopped by OFF / failed, the exact reply text, risk, why, and which playbook version or library articles were used. The underlying submission always stays with staff. **Test a response** previews a made-up submission without saving or sending anything.

## Operations

- Queue: dedicated supervisord program `ai-automation-worker` (`queue:work --queue=ai-automation,ai-backlog --tries=1 --timeout=80`), so it never delays operational forms or notifications. Retries are tracked on the ledger row (`ai_intake_events`), at most three attempts.
- Safety net: `ai-automation:sweep` every five minutes re-queues stalled work while ON and does nothing while OFF.
- Tables: `ai_automation_settings`, `ai_automation_switch_events`, `ai_intake_events` (unique per source event), `ai_backlog_runs`, `ai_model_calls`, `ai_automation_playbooks` + revisions, `ai_knowledge_articles` + versions. Migrations are additive.
- Rollback: turn the switch OFF, then redeploy the previous image digest. Older code ignores the new tables and keeps working. Replies already posted stay visible: on vehicle threads they read as an unlabelled message, on station requests as an update from "System", and in Support Services threads the older page draws them like a member bubble labelled "Member". Staff queues are unaffected. The new columns are nullable and the migrations are additive, so no database rollback is needed.
- Permission: `admin.ai_automation.manage` can be granted from the normal member access administration screen.

## Verifying after a release

1. Switch shows OFF. Activity is empty.
2. Turn ON. Submit a test checkout with a missing routine item on a test unit, a test station request and a Support Services message from a test account; each gets one reply from Support Services on the member's screen, the staff lists still show them as unanswered, and no email is sent.
3. Turn OFF; submit again; nothing is posted and Activity shows nothing new.
4. Preview the backlog; process a small batch only after reviewing the preview.
