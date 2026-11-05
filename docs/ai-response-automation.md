# Support Services Automation (automated replies)

When a member submits something in three areas, the Hub posts one short reply signed **Support Services**:

| Area | What gets a reply | Where the member sees it |
|---|---|---|
| Vehicle | A daily checkout with Missing, Damaged or other findings (Engine, Ladder, Rescue, reserve units); a member message on a vehicle issue thread | The checkout's issue conversation |
| Station requests | A new station request and a station supply request | The member's request, as an update from Support Services |
| Support Services | A member message in My Messages | The thread |

Nothing else is touched: no EMS or medical inventory, narcotics, Captain 5 workflows, rosters, staffing, incidents, personnel or uniform workflows, training or IT. Rescue and medical items on a vehicle checkout only get an acknowledgment and a "coordinate with Captain 5" referral inside that checkout conversation; no Captain 5 or EMS record is read or written. Repeat reports already answered get no reply (history only).

A live vehicle checkout with no new finding and no confirmed-again finding (and otherwise in scope: not a test, requester known) gets one fixed line in its own checkout thread: "Inspection received and recorded. No new issues reported." (template `clean_receipt`, editable on the Playbooks screen). It is deterministic: zero model calls, template path only. It follows the master switch exactly like every other reply (OFF or hard disable means no receipt; the switch is re-checked under lock). It is only ever written for a live row: backlog rows and the backlog scan skip a clean checkout (`no_findings`), so turning the switch on never answers old clean checkouts. The ledger row is `sent` with `template_key = clean_receipt`, but it sends no bell: the line is visible in the member's inspection thread and activity only. A checkout whose only findings are repeats already answered stays `repeat_only`.

A reply means *received*. It never means reviewed, approved, ordered, repaired or ready for service. Every submission stays where it was in the staff lists with its status, priority, acknowledgment and resolution fields untouched. The feature sends **no email and no push**; the member gets the reply on the record and one in-app bell notification (new submissions only). Members see the sender as **Support Services**, styled like any Support Services reply; staff screens show **Support Services (automated)**.

Everything is in **Admin > Support Services Automation**: Overview, Equipment Priorities, Knowledge & Instructions, Response Messages, Activity & History. Who can use it: super administrators and anyone granted `admin.ai_automation.manage` (grantable from the access screen). A plain-language guide for administrators is `docs/support-services-automation-admin-guide.md`.

## The master switch (Overview)

The large **AI AUTOMATIONS ON/OFF** card at the top. **Turn OFF** is one tap and stops every automated reply, backlog batch and AI model call at once, including queued and in-progress work; manual Hub workflows keep working. **Turn ON** asks for confirmation; only new submissions are answered until someone presses **Process Pending Backlog**, and work stopped by an earlier OFF is never revived by itself. Every change is in Switch history.

Emergency from the server: `docker exec -u sail mbfd-hub-laravel php artisan ai-automation:switch off`. `AI_AUTOMATION_HARD_DISABLED=true` disables the whole feature; `AI_AUTOMATION_MODEL_ENABLED=false` stops model calls but keeps the standard replies.

How OFF is enforced: the switch lives in the database (never cache). Every ON/OFF bumps an epoch; queued work is checked when a worker claims it, after any model call, and under a row lock inside the transaction that writes the reply, so nothing posts after OFF and a reply queued before an OFF never posts after a later ON. The bell is also suppressed if OFF lands right after a reply.

## Priorities decide the reply

Every item, request and message gets LOW, MEDIUM or HIGH from the equipment catalog (Equipment Priorities); the same priority drives the staff dashboards and staff alerts. Details: `docs/support-services-triage.md`.

Default replies (all editable in **Response Messages**; `{item}` is the item name from the checkout):

| Situation | Reply |
|---|---|
| LOW missing item (kitty litter, hand tools, hardware, consumables) | Please check around the station and other apparatus for the {item} first. If nothing turns up, call or text before coming by Station 2 so we can check on a replacement. |
| Several LOW missing items | Same, naming up to three items ("A, B and C", then "and other items"); adds the battery reminder if one is a battery |
| Missing DeWalt battery | Check the chargers and other DeWalt tools, including the station blower. If it's still missing, call or text before coming by Station 2 so we can check on a replacement. |
| Other missing battery | Check the chargers and other tools, including the station blower, to make sure the {item} wasn't left behind. If it's still missing, call or text before coming by Station 2 so we can check on a replacement. |
| LOW damaged item | Acknowledged. We'll follow up if needed. |
| MEDIUM expensive or specialized tool | Acknowledged. Call or text before coming by Station 2 so we can check whether a replacement is available. (+ "If it's needed right away, notify your officer and coordinate through 300." when the member says it's urgent) |
| MEDIUM gas meter / detection | Acknowledged. Coordinate with the on-duty Air Tech to have the gas meter or detector checked or swapped out. Notify your officer and contact 300 if immediate assistance or after-hours coordination is needed. |
| MEDIUM SCBA component or cylinder (Air Tech) | Acknowledged. Coordinate with the on-duty Air Tech to have the item checked or swapped out. Notify your officer and contact 300 if immediate assistance or after-hours coordination is needed. |
| Cylinder leak | Acknowledged. Remove the leaking cylinder from use and contact the on-duty Air Tech to have it assessed or swapped out. Notify your officer, and coordinate through 300 if immediate or after-hours assistance is needed. |
| Lifepak and expensive Rescue equipment (Captain 5, MEDIUM) | Acknowledged. Coordinate with Captain 5 to arrange a replacement or pickup. Confirm availability before coming by. |
| Splints, backboards and similar (Captain 5, LOW) | Acknowledged. Contact Captain 5 to coordinate a replacement and pickup. |
| HIGH PPE (complete SCBA, facepiece, hood, helmet, bunker coat or pants) | Acknowledged. Notify your officer immediately and coordinate with Support Services or 300 for further direction. |
| Hazard or readiness (flagged significant or out of service, sparking, uncontrolled leak, gas smell…) | Acknowledged. Notify your officer and 300 right away. (station hazards: "Acknowledged. If there is an immediate hazard, notify your officer and 300 right away.") |
| Station request on the staff work list | Acknowledged. Forwarded to the appropriate personnel. |
| Supply request, bunker gear drop-off, anything else | Acknowledged. |
| An item with its own message in Equipment Priorities | That message |

Weekend line, Saturday and Sunday Eastern time, only for routine Support Services replies to submissions from the last 48 hours (never for old backlog items, Air Tech, Captain 5 or HIGH replies, which already route to 300): "Support Services staff are off this weekend. If this can't wait, contact 300. Otherwise we'll follow up on the next weekday."

One reply per checkout, driven by its highest-priority finding. A conversation gets at most one routine automated reply per two hours; a new HIGH or hazard report is still answered.

## Knowledge & Instructions and the AI model

Plain-language instruction articles by topic: Add, Edit, Preview, Publish, Restore previous; import Word (.docx), PDF or text (up to 5 MB, text only, the file is not stored). "Use this in every automated answer" marks standing facts (weekends and 300, calling ahead to Station 2) sent with every AI answer; "Example messages this should answer" lets the preview prove an article is found; "Test a message" shows which instructions would be used and why. Changes are live immediately.

Standard reports ("Missing Lifepak", "SCBA cylinder leaking", "AC not working") are answered by the messages above with **no AI call**. The AI (Cloudflare Workers AI, `@cf/meta/llama-3.3-70b-instruct-fp8-fast`) is used only for a member's question, or free text the catalog can't classify, when a published article clearly matches; never for HIGH, hazard or PPE items, never for the backlog, never to say "Acknowledged.". Retrieval stays in the Hub database (keyword and synonym scoring from the equipment catalog, plus PostgreSQL full-text ranking); only the matched article text and the member's words go to Cloudflare.

Answers are checked before sending: 1-3 short sentences, no greetings or promises, no document names, contacts or links, numbers only from the article, and the answer must stick to words from the article or the member's message; anything failing becomes "Acknowledged.".

Model choice (benchmark of 27 anonymized samples, 2026-11-03): Llama 3.3 70B fast 85% correct, 1.0 s typical, about $0.21 per 1,000 answers; gpt-oss-20b 74%; gpt-oss-120b 59%; Qwen3 30B 56%; Gemma 4 26B 26%. Re-run with `php artisan ai-automation:benchmark --model=... --run` before switching (`AI_AUTOMATION_MODEL`). Prices are per model in `config/ai_automation.php`; an unpriced model is refused. Hard caps: 100 calls a day and $10 a month, reserved before each call. Production settings (values only in the server environment): `AI_AUTOMATION_MODEL_ENABLED`, `AI_AUTOMATION_CF_ACCOUNT_ID`, `AI_AUTOMATION_CF_API_TOKEN` (dedicated token `mbfd-hub-ai-response-2026-11-03`, id `be014d3f92c830d518dc2671f0f7a0c1`, Workers AI Read and Write only). Expected use: a few questions a day, well under the free daily Workers AI allowance.

## Historical backlog (Overview)

**Preview Pending Backlog** (background, sends nothing) counts older unanswered submissions by area, why others are excluded, and the exact replies. **Process Pending Backlog** (switch ON only) sends one reply per submission in batches of 10 about every 20 seconds, templates only, no notifications, re-checking each record first; OFF or Pause stops it and Resume continues. Optional age limit `AI_AUTOMATION_BACKLOG_MAX_AGE_DAYS` (default none).

## Activity & History

Every decision: when, area, record, live or backlog, priority, sent / skipped / stopped by OFF / failed, the exact reply, whether a standard message or the AI produced it, the instructions used, and that the item stays with staff. Exportable.

## Operations

- Queue: supervisord program `ai-automation-worker` (`--queue=ai-automation,ai-backlog --tries=1 --timeout=80`); production Redis `retry_after` is 90 s. Ledger `ai_intake_events` (unique per source event, at most three attempts). `ai-automation:sweep` every five minutes (no-op while OFF).
- Deploy steps: migrations (additive), then `php artisan ai-automation:seed` (messages, starter instructions, equipment catalog; never overwrites edits), then `php artisan support-triage:backfill` (priorities for open records, no notifications). The switch stays OFF until an administrator turns it on.
- Rollback: turn the switch OFF, redeploy the previous image digest. Older code ignores the new tables. Replies already posted stay visible; the older pages label them differently (vehicle threads "Crew member", station requests "System", Support Services threads a member-style bubble "Member · Support Services"). Staff queues are unaffected; no database rollback needed.

## Verifying after a release

1. Overview shows OFF. Equipment Priorities lists the seeded catalog; "Not classified yet" shows the few ambiguous items.
2. Try a message: Kitty Litter (LOW), DeWalt battery, gas meter, SCBA cylinder leak, helmet, Lifepak on Rescue; check the priority and reply.
3. Turn ON; one real or test submission per area gets one Support Services reply; staff lists still show it unanswered; no email.
4. Turn OFF; nothing more posts. Preview the backlog, process a small batch, review, then the rest.
