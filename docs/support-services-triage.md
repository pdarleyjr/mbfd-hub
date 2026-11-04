# Support Services equipment priorities (LOW / MEDIUM / HIGH)

One priority decides how a vehicle finding, station request or supply request is treated everywhere: the staff dashboards (badge, filter, sort), staff alerts (each person's Low/Medium/High choices) and the automated Support Services reply. There is no second classification anywhere else.

## Where it comes from

`support_equipment_rules` is the equipment catalog administrators edit in **Admin > Support Services Automation > Equipment Priorities**. Each rule has a name, other names people use, a category, a priority, who handles it (Support Services, Air Tech, Captain 5, Fleet, Station, Officer & 300), an optional custom member reply, PPE / expensive / readiness flags, when to contact 300, and an optional vehicle-type exception. Every change is versioned (`support_equipment_rule_revisions`, Restore previous) and bumps the catalog version (`ai_automation_settings.triage_policy_version`). `php artisan ai-automation:seed` adds the default catalog built from the checklist item names (never overwrites edits).

`App\Services\SupportTriage\CatalogEquipmentClassifier` applies, highest first:

1. Safety hazard or readiness: the record is flagged significant or out of service, or the text has an approved hazard phrase (uncontrolled leak, sparking, smoke, burning smell, exposed wires, gas leak or smell, carbon monoxide, injury, unsafe, out of service). Narrow whole phrases only; "smoke detector" is not a hazard.
2. HIGH protective equipment: complete SCBA, facepiece/mask, hood, helmet, bunker coat, bunker pants, other designated structural PPE.
3. The item's own rule (a vehicle-type exception beats an everywhere rule).
4. MEDIUM categories (expensive or specialized tools, gas meters and detection, Lifepaks and expensive Rescue equipment, SCBA components and cylinders; a cylinder leak stays MEDIUM with removal-from-use guidance unless the danger words above apply).
5. LOW categories (consumables, hand tools and hardware, interchangeable batteries, routine equipment).
6. Anything not recognized: MEDIUM and "Not classified yet" (never silently LOW). Unclear SCBA items are never downgraded.

Broad words such as "light", "engine", "fire", "monitor" or "gear" never decide a priority on their own, and unit or place names ("Engine 2", "Fire Station 4") are ignored. A message that only says bunker gear was dropped off for cleaning is LOW and gets "Acknowledged."

## The stored projection

`support_triage_levels` keeps one row per open record: the level the catalog produced, an optional staff override for that one record ("Change priority" on the dashboard), the effective level and the catalog version. A defect flagged significant or out of service always reads HIGH; otherwise the staff override wins, then the catalog. The record's own severity, operational impact, action status and a member's chosen station-request urgency are never changed.

`App\Services\SupportTriage\TriageProjector` refreshes a row when a checkout records findings (before reviewer alerts), when a defect's severity or impact changes, and when a station or supply request is created or changed. After catalog edits, **Update open issues** on the Equipment Priorities screen shows a read-only preview (counts per level before and after, overrides kept) and then re-checks open records in the background without sending any notification. `php artisan support-triage:backfill [--dry-run]` projects every open record (first deploy). Projection is staff-facing and runs whether the member-facing automation switch is ON or OFF.

## Staff alerts by priority

Notification Settings > **Support Services alerts: priority levels** has three independent boxes (Low, Medium, High), all on by default (`user_support_alert_levels` stores only exceptions). They filter the optional triage alerts: a new defect alert to reviewers and the new station request alert. Approvals, safety holds, member replies and updates on things a person submitted always notify. Preferences never change a record's priority, and the dashboards always list all work.

## Member replies

The reply engine reads the same level (the defect's stored level when present), see `docs/ai-response-automation.md`.
