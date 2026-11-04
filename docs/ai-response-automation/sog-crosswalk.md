# Support Services guidance: source crosswalk (INTERNAL)

Audience: editors, auditors and the engineers who maintain the AI Response knowledge library and Playbooks. Not for members. Nothing here may be quoted to members; the member-facing guide (`resources/ai/knowledge/mbfd-support-services-guidance.md`) carries no document titles, section numbers, phone numbers, names, unverified room locations or turnaround promises.

Prepared 2026-11-03. Source documents on `E:` were read only; nothing was copied into the repository.

## 1. Source inventory

| # | Source (read-only) | Version / date | Used for |
|---|---|---|---|
| 1 | `E:\Organized Files\MBFD\Policies & Procedures\SOG Analysis\SOG Files\800 Section\V6\MBFD_Coordinated_Review_20261002_R2\MBFD_Section_800.pdf` (64 pp, file dated 2026-10-02 17:48) | Policies 800.P01 to 800.P09, "ISSUE DATE: Not issued", "REVISED DATE: 10/02/2026". Division name in the draft is "Logistics". | Primary. P01 (asset control, acknowledgment, records), P02 Table 800.P02-4 and II (checkout and defects, after hours), P03 I and IV (equipment defects, radios), P04 (bunker gear), P07 (loans), P09 (facilities). P05 and P06 (SCBA, medical oxygen) read only to confirm they are escalate-only; their content was ignored as instructed. P08 (uniforms, printing) out of scope. |
| 2 | `E:\...\SOG Analysis\edited_support_services_sog.docx` | Older 2025 procedures, signed by the then Fire Chief. Same text exists as `SOG Files\100 Section\V1\Sources\Support Services SOG.md` (spot-checked, identical). | Bunker gear, equipment loans, equipment maintenance (damaged, lost, defective), apparatus maintenance and defect reporting, uniform ordering, reporting and after-hours instructions. |
| 3 | `E:\Organized Files\MBFD\Training\Driver Engineer\New DE Manual\08_Opus55_Review\08_Final\MBFD_Driver_Engineer_Manual_2026.pdf` (244 pp, "Departmental Review Copy") | 2026 edition | Only the crew-level inventory sentences (inventory suppression and rescue tools, tell the officer, tag, keep off the apparatus, hand to a named receiver, E1 to E4 change-over inventory). No mechanical procedures, limits or configuration values were used. |
| 4 | `C:/mbfd-ai-response/storage/checklists/*.json` (7 files: default, engine, engine2, ladder1, ladder3, rescue, fireboat6; 702 distinct item names) | repo worktree | Word-list tuning (section 6). |
| 5 | `C:/mbfd-ai-response/config/ai_automation.php`, `ResponseComposer.php`, `TermMatcher.php` | repo worktree (uncommitted) | Templates, current lists, and matching and ordering semantics. |
| 6 | `...\SOG Files\Table of Contents\MBFD_Complete_SOG_Review_2026-10-17.pdf` (1,071 pp, build MBFD-SOG-20261016-W2) | Contains 800.P01 to P09 at "REVISED DATE: 10/07/2026", status "Review candidate - not adopted" | Compared against item 1 only (sentence-level diff). Substantive differences from the 10/02 text for the policies used: none. Differences were page furniture, a "Review candidate - not adopted" banner, added regulatory notes (P04, P05, P06) and P02's weekly SCBA check moving to the Company Officer. None affects any article. If the owner prefers the 10/07 text as the reference, no article changes. |

Not read, deliberately: earlier 800 folders V1, V2, V4 and V5 (superseded by V6), the Plan, Source Intake, Closure Record and Reviewer Crosswalk files, the review-package zips, and the 34 MB to 62 MB evidence JSON files.

Status of the reference text: the 2026 text is unissued. Per the owner it is used as internal operational guidance. It is never cited to members, and no article says a rule is "required by policy".

## 2. Article crosswalk

"Hub" entries were verified by searching the repository (file named in the last column).

| Article slug | Source section(s) | Old versus new resolution | Hub feature that replaces the paper or email step | Repo evidence |
|---|---|---|---|---|
| `acknowledgment-and-tone` | 2026 P01 III.C, V.B; owner decisions | 2026 wins: an automatic receipt confirms transmission only; the receiving owner acknowledges by recording owner, priority and next action. The AI reply is therefore described to members as receipt, not acceptance. | Support Services Messages inbox (admin) and My Messages (member) | `docs/employee-logistics-messaging.md` |
| `missing-low-value-consumables` | Owner decision (no document covers consumables). 2025 equipment maintenance and 2026 P03 I.A to D only for the "tell the officer if it affects response" boundary. | Owner template overrides the lost/damaged/stolen route for low-value items. See conflict C2. | Vehicle Checkout, condition Missing; Report inventory correction | `InventoryCorrectionForm.tsx` (hint: "Use Missing if it should be here but isn't") |
| `missing-dewalt-batteries-and-shared-tools` | Owner decision; checklist item names; 2026 P02 Table 800.P02-4 item 14 (do not use mismatched batteries or chargers) | No document conflict. The "hot, swollen or leaking" line is conservative general practice carried from P02 items 09 and 14. | Same as above | `storage/checklists/rescue_checklist.json`, `engine2_checklist.json` |
| `equipment-expensive-critical-or-damaged` | 2025 equipment maintenance; 2026 P03 I.A to D, P01 III.B, IV.F, V.B, V.E; DE Manual (tag, keep off apparatus, named receiver) | 2026 wins. The old Lost/Damaged/Stolen form, "driver logs in the apparatus logbook" and "return it to a member of Support Services" are replaced by "record in the electronic inspection, linked history". Verbal officer notice is kept in both. | Vehicle Checkout findings and issue history | `docs/apparatus-issue-history.md` |
| `repeat-issue-and-next-checkout` | 2026 P01 IV.D, V.B, V.C; P02 Table item 01 (handoff and open defects), II.E to F | 2026 wins: one authoritative history, no duplicate log, "an undocumented assurance is NOT VERIFIED". 2025 required logbook plus Daily Vehicle Inspection form. | Open-issue count, issue card, timeline, Report inventory correction, Report an unlisted item | `docs/apparatus-issue-history.md`, `InventoryCorrectionForm.tsx` |
| `station-request-and-facilities` | 2026 P09 I to III (station care, exhaust-recovery check, pole-hole protection, A/C vent filter needs, hazard first, no improvised repair); P01 Table 800.P01-2 (facilities repair) | 2026 only (the 2025 file has no facilities text). Hazard handling: direct notification first, request second. | Station Request wizard (Repair / Service or Equipment, room, priority, photo, "unusable / out of service"), Station Inventory Form, station Requests tab | `StationRequestWizard.tsx`, `FormsHub.tsx`, `StationDetailPage.tsx` |
| `bunker-gear-drop-off` | 2025 bunker gear maintenance and process; 2026 P04 II to V | See section 3. Triggers, Monday inspection, no station washing or home cleaning kept. Bin location, forms-in-radio-pocket, plastic-bag step, 48-hour pickup and pickup-room location removed. | Message Support Services with reason "Bunker gear cleaning request" | `EmployeeMessageReason.php`, `resources/views/welcome.blade.php` (tile text) |
| `hub-digital-workflow-crosswalk` | 2025 reporting instructions (email format, phone order, logbook), 2025 MDR by phone then email; 2026 P01 V.A to D, V.G, P02 II.E to F | 2026 wins where the Hub supports it. Phone and in-person urgent notice still required. | See article; every named screen verified | `MyMessages.php`, `ApparatusServiceRequestPage.php`, `FormsHub.tsx`, `welcome.blade.php` |
| `station2-and-weekend-handoff` | Owner decisions; 2025 After hours and Weekends protocol; 2026 P02 II.G | The owner's Station 2 and weekend lines are not in either document. After-hours service call rule (only 300 or the ranking officer; never call off-duty technicians) is consistent in both. See C3. | n/a (no Hub spares feature) | `config/ai_automation.php` templates |
| `contact-and-routing-roles` | 2025 contact order; 2026 P01 I, V.B, P02 II.G, P03 IV.A, P04 III.A | Roles only. 2026 role names kept (Logistics Captain, Logistics Division Chief, Fleet Operations Manager, Air Tech, PSCD). Hub label "Support Services" used with "also called Logistics". | n/a | `docs/employee-logistics-messaging.md` (naming note) |
| `when-to-escalate` | 2026 P01 III.B, V.A, V.E, V.I; P02 II.A to B; P03 II; P04 II.D, III.B; P09 II.A to D | 2026 only. Required verbal Company Officer and 300 notice, Risk Management, police and legal reports are never displaced. | n/a | n/a |
| `equipment-loans-public-education` (extra) | 2025 borrowing of tools and equipment; 2026 P07 I to III | Same rule in both. 2026 adds "no response is not approval", electronic record, and that physical return does not close the loan. The 2025 email address and written-log wording are dropped. See C8. | Message Support Services, reason Equipment (no dedicated loan form exists) | `EmployeeMessageReason.php` |

Intentionally not turned into articles: apparatus waxing schedule and weekly day-of-week tasks (2025 and 2026 P02), SCBA and air tech duties (P05), medical oxygen (P06), instrument calibration rotation (P03 III), uniform ordering and printing (P08), vehicle technical checks and driving (P02, DE Manual). They are out of scope, escalate-only, or mechanical. The waxing and weekday schedule is a reasonable later addition if the owner wants it; the checklists already carry a `dailySchedule` per unit.

## 3. Bunker gear: decisions

| Topic | 2025 | 2026 draft 800.P04 | In the guide |
|---|---|---|---|
| Turn-in triggers | fire conditions, heavy soil, chemicals, pesticides, bloodborne pathogens, repairs, any concern | same, plus "do not wait for visible damage" | kept |
| Monday inspection by officers | yes | yes | kept |
| No station machines, no home cleaning | yes | yes, plus no unassessed contaminated gear with clean supplies or in living spaces | kept |
| Where gear goes | named bin at a named station, forms beside it, plastic bag | "approved compatible containment and intake location"; "confirm current intake/pickup locations"; unknown hazardous contamination is not routine intake | not stated; staff confirm location |
| Notice to staff | email with reason, when left, next duty date, repairs | submit in the department platform with IDs, circumstances, location, time left, next duty date, repairs, replacement need; "a deposit alone is not an accepted service request" | member sends a Bunker gear cleaning request and includes reason, time, next duty date, repairs, replacement need |
| Turnaround | pick-up within 48 hours (weekend and holiday exceptions) | "Do not promise a fixed vendor turnaround" | removed |
| Pick-up location | named room off a tower entrance | not named | removed |
| Replacement | available at Support Services; after hours see 300 | request through Logistics Captain or Chief; after hours notify 300; availability verified; contaminated gear is not reused because no spare exists | officer and 300 roles only; no availability promise |
| Exposure or injury | not covered | report and clinical follow-up do not wait for the gear | kept |
| Assistant reply | n/a | n/a | "Acknowledged." only (owner) |

## 4. Safety and legal conflicts needing owner authorization

Nothing below was resolved by the guide in a way that lowers a safety or legal requirement. Each item is either already handled conservatively or needs a decision.

| # | Conflict | Sources | Current handling | Decision needed |
|---|---|---|---|---|
| C1 | The AI "Acknowledged." is an automatic receipt. The draft says an automated submission is not accepted responsibility and the reporting officer must escalate through 300 and Logistics supervision when no owner acknowledgment arrives. A member may read "Acknowledged." as staff acceptance. | 2026 P01 III.C, V.B | Guide article says it is receipt only and that the officer can follow up. | Confirm that the AI reply is not meant to satisfy the Logistics acknowledgment duty, and that the wording in the guide is acceptable. |
| C2 | Replacement requests require a Lost/Damaged/Stolen record endorsed by the Company Officer and the Suppression Division Chief (300). The owner's routine-missing template sends members to check the station and call or text before Station 2, with no endorsement. | 2025 equipment maintenance; 2026 P03 I.B | Applied only to low-value consumables, tool batteries and interchangeable items. Everything else is acknowledgment only and the guide sends it to the Company Officer. | Authorize an exemption from the endorsement for the items in the consumable, tool-battery and interchangeable lists (or add the step). |
| C3 | Station 2 as the replacement contact point and 300 checking for a spare on weekends do not appear in either document. The draft limits 300's after-hours role to coverage and apparatus service calls, and says to verify current intake and pickup locations. | 2026 P02 II.G, P04 III.D; owner decisions | Used exactly as the owner wrote. | Confirm Station 2 stays correct, and that asking 300 to check spare availability on weekends is an authorized duty for noncritical needs. Re-verify at the review date. |
| C4 | Logbook and email retirement. The 2025 rule requires logbook entries, a detailed email to the Support Services mailbox and a Mechanical Defect Report by phone then email. The unissued draft says no duplicate handwritten log or spreadsheet. The guide tells members no duplicate log or email is needed for an item already in the Vehicle Checkout. | 2025 apparatus maintenance and reporting instructions; 2026 P01 V.C, P02 II.E | Voice and in-person urgent notice is kept in the guide. Paper backup during a Hub outage is kept. | Authorize telling members the logbook and email duplicates are no longer required, since the 2026 text is not issued. If not authorized, delete the first bullet of `hub-digital-workflow-crosswalk`. |
| C5 | "Designated platform." The draft requires PPE service requests, equipment defects and loan requests in the department-approved platform with specific fields (garment and asset IDs, time left, next duty date, repairs). The Hub message form has a reason and free text only. | 2026 P04 V.A, P01 V.A, P07 II.A | Guide asks members to put the fields in the message text. | Confirm Message Support Services (and Station Request, Apparatus Service) are the designated platforms, or add structured fields. |
| C6 | Who submits a facilities request. The draft says the Station Officer submits and verifies closure. The Hub lets any member start a Station Request, and the wizard offers a "Critical" priority. The draft also says submission never replaces direct notification. | 2026 P09 II.B, P01 Table 800.P01-2 | Guide says tell the Station Officer first and that the form does not replace it. | Confirm who may submit, and whether a Critical request should alert a person. |
| C7 | Contamination or exposure text inside a bunker drop-off message is classed routine in `ResponseComposer::forMessage` (the drop-off check runs before the safety check and uses tier routine, so no human flag). The draft says exposure reporting and clinical follow-up must not wait for the gear. | 2026 P04 II.D, III.B | Reply is "Acknowledged." either way. | Engineering fix recommended (section 7, item 2): evaluate safety terms first, or raise the tier when a safety term also matches. No owner decision beyond approving that. |
| C8 | Equipment loans. The draft requires a request through the approved platform, routed through the chain of command, with a Logistics Captain decision. The Hub has no loan form. | 2026 P07 II.A | Guide: send a Support Services message (Equipment), tell the officer, and wait for a yes. | Confirm this is acceptable, or remove the article. |
| C9 | Reference text is unissued. The same revisions also appear as "Review candidate - not adopted" in the 10/17 compilation. The draft names the division "Logistics"; the Hub says "Support Services". | 2026 text | Used as internal guidance only; never cited; "Support Services, also called Logistics". | Confirm the naming and that nothing in the guide should be read as a requirement. |

Preserved everywhere (no decision needed): urgent verbal Company Officer and 300 notice; Risk Management, police and legal reports remain separate and required; safety replacement is not delayed for paperwork; protective action never waits for a form or acknowledgment.

## 5. Hub features verified in the repository

| Feature | Evidence |
|---|---|
| Home tile "Message Support Services" (description mentions bunker gear cleaning), opens `/employee/messages?compose=1` | `resources/views/welcome.blade.php` |
| Home tile "Station / Vehicles / Equipment" (station forms, Vehicle Checkouts) | `resources/views/welcome.blade.php` |
| My Messages page, nav group "My work"; reasons: General question, Equipment, Uniforms, Supplies, Bunker gear cleaning request | `app/Filament/Employee/Pages/MyMessages.php`, `app/Services/EmployeeMessaging/EmployeeMessageReason.php` |
| Forms Hub cards: Station Request, Station Inventory Form, Station Inspection, TRT Trailer Inventory | `resources/js/daily-checkout/src/components/FormsHub.tsx` |
| Station Request: type Repair / Service or Equipment, room or station-wide, priority Low / Normal / High / Critical, photo, "The item or area is unusable / out of service"; updates in the station's Requests tab | `.../forms/StationRequestWizard.tsx` |
| Vehicle Checkout: open-issue summary, issue cards with status and timeline; Report inventory correction; Report an unlisted item | `docs/apparatus-issue-history.md`, `.../InventoryCorrectionForm.tsx` |
| Apparatus Service (Employee Portal, nav group "Request"); Urgent priority does not change unit status | `app/Filament/Employee/Pages/ApparatusServiceRequestPage.php` |
| No equipment-loan form, no Lost/Damaged/Stolen form, no spares or stock feature | repo-wide search found none |

## 6. Word list recommendations (`config/ai_automation.php`)

How the engine uses the lists (from `ResponseComposer` and `TermMatcher`): matching is whole word or phrase after lower-casing, with punctuation other than hyphens turned into spaces. Plurals do not match unless the term has a trailing `*` ("key" does not match "keys"; "spanner" does not match "Spanners"). For checkouts, `safety_terms` then `expensive_terms` are tested on item name plus note first; only then are the missing-item classes tested on the item name, in this order: DeWalt (term plus the word battery), tool battery, consumable, interchangeable. So anything in `safety_terms` always beats a consumable or interchangeable term. For free-text messages the order is bunker drop-off, safety, expensive, then question detection and retrieval. Station requests use `safety_terms` only.

### 6.1 `safety_terms`: add (no removals)

Keep all current terms. Append (grouped for the editor; every term is justified by a checklist item or document line):

```php
// Respiratory, PPE, accountability (checklists: Gloves, Sleeves, N-95 Masks, Safety Glasses, Safety vests, Tyvek Suit, Driver Gear, Officer Gear, F-I Gear, Passports, PAR Tags, Ladder Belts, Scott Pack Respirator, ballistic vests)
'respirator', 'scott pack', 'n95', 'n-95', 'tyvek', 'tyvex', 'safety glasses', 'goggles', 'vest*', 'ballistic', 'gear',
'glove*', 'boot*', 'helmet*', 'sleeves', 'earplug*', 'ladder belt*', 'passport*', 'par tag*', 'accountability',
// Lighting (owner: lighting is safety; checklists: Lights, Flashlight(s), Light Towers, Portable Lights, Dive Light, Siren/NAV lights)
'light', 'lights', 'lighting', 'flashlight*', 'flash light*', 'headlamp', 'floodlight', 'light tower*', 'portable light*', 'dive light',
// Radios and marine electronics (checklists: Crew Radio, FI Radio, Voice Amp, Raymarine, FLIR, VHF radio test)
'radios', 'portables', 'vhf', 'motorola', 'voice amp*', 'raymarine', 'flir', 'radar', 'sonar',
// Monitors and detection (checklists: SENSIT #, Drager Box, Dreager Gas Meter, RAD Alert 50, Radialert, CO/H2S 3500 PAK, MultiRAE in documents)
'sensit', 'drager', 'dreager', 'multirae*', 'h2s', 'rad alert', 'radalert', 'radialert', 'radiation', 'calibrat*', 'hot stick',
// Medical and EMS items (out of scope; acknowledgment only) (checklists: Med Box, Airway Bag, Life-Pak, Auto Pulse, Handtevy, Duodote, Stretcher, Stair Chair, KED, Backboard, C-Collars, Splints, Traction Splint, Head Beds, Sharps, Boo Boo Box, OB Kits, MCI Kit, Sterile Water, Patient Harness)
'med box', 'med supply', 'medical', 'trauma', 'handtevy', 'autopulse', 'auto pulse', 'life-pak', 'life pak', 'lifepak', 'duodote',
'stretcher', 'stair chair', 'stairchair', 'ked', 'backboard*', 'scoop', 'c-collar*', 'collar*', 'splint*', 'traction',
'head bed*', 'headbed*', 'sharps', 'boo-boo', 'boo boo', 'ob kit*', 'mci', 'sterile', 'patient*',
// Vehicle readiness (checklists: Brake PSI #, PSI - Front/Rear Tire, Air Pressure, Fuel %, DEF %, Engine Temp, Oil Pressure, Plymovent)
'tires', 'tyres', 'wheels', 'brake*', 'psi', 'air pressure', 'tire pressure', 'fuel', 'diesel', 'def',
'engine oil', 'oil level', 'oil pressure', 'overheat*', 'plymovent', 'exhaust',
// Hose, appliances, foam, extinguishers, scene safety (checklists: hose and nozzle sets, Gated Wye, Storz adapters, Siamese, Reducer, Double Male/Female, Foam Eductor, Street Manifold, Gaskets, Hose Clamps, Strainer, CO2/Dry Chem/Wet Water Extinguisher, Flares & Emergency Triangles, Triangular Markers)
'hose*', 'nozzle*', 'wye', 'storz', 'siamese', 'reducer', 'increaser', 'double male', 'double female', 'eductor', 'manifold',
'coupling*', 'gasket*', 'strainer', 'hose clamp*', 'foam', 'extinguisher*', 'flare*', 'triangle*',
// Rescue, cutting and forcible-entry tools (checklists: SawZall, Dewalt K-Saw, Band Saw, Angle Grinder, Air Chisel, Impact Wrench, Hydraulic Rabbit Tool/K Tool, TNT, Paratech, Res-Q Jacks/Struts, Glassmaster, Halligan, Irons, axes, Sledge, Bolt Cutters, Crowbar, Pry Bar, Come-a-long, Power Winch, Cribbing)
'sawzall', 'reciprocating', 'k-saw', 'k saw', 'chainsaw', 'chain saw', 'bandsaw', 'band saw', 'grinder*', 'chisel',
'impact*', 'hydraulic*', 'rabbit', 'k-tool', 'k tool', 'tnt', 'paratech', 'res-q', 'resq', 'strut*',
'glassmaster', 'glass master', 'center punch', 'halligan', 'irons', 'axe*', 'sledge*', 'cutter*', 'crowbar', 'pry bar*',
'come-a-long', 'come a long', 'winch', 'cribbing',
// Water rescue and marine (checklists: Torpedo Buoys, Throw Bags, Life Ring, PFDs, Dive Mask & Snorkel, Fins, SCUBA, Bilge Pumps, Anchor, Manual CO SEA FIRE)
'buoy*', 'torpedo', 'throw bag*', 'life ring', 'dive', 'scuba', 'snorkel', 'fins', 'bilge', 'anchor', 'seafire', 'sea fire', 'pfd*',
// Station and hazard conditions (documents: exhaust recovery, pole-hole protection, hazards; checklists: Sprinkler Kit/Gas Shut Off, Hydraulic/Manual Gas Squeeze Off, Leak Bag, Plug & Dike, Green Bucket (Decon), Gas Cans)
'sprinkler*', 'gas shut*', 'squeeze off', 'squeeze-off', 'leak bag', 'dike', 'decon', 'hazmat', 'hazardous',
'sewage', 'sewer', 'flood*', 'pole hole', 'pole-hole', 'gas can*', 'fuel can*', 'asbestos',
```

Notes on current terms (no removal recommended):

* `fluid` is kept despite colliding with the checklist item "Fluid Absorbent", because bodily-fluid exposure matters more. That one item will receive "Acknowledged." and cannot reach the consumable reply. Acceptable.
* `fire` (and `engine`, `ladder`) over-trigger on unit and place names. See section 7, item 1.
* Fire Watch Forms, Fire Violation Forms and Fire Violation Book contain `fire` and will always be acknowledgment-only. Acceptable.

### 6.2 `expensive_terms`: add (no removals)

```php
'ipads', 'tablets', 'laptops', 'computers', 'tvs', 'generators', 'chargers', 'battery chargers', 'drones',
'keys', 'lock box', 'lockbox', 'gas card', 'fuel card', 'toughbook', 'cad', 'clicker', 'garage door',
'supervac', 'super vac', 'electric fan', 'gas fan', 'ppv fan', 'fans', 'honda generator',
```

Why: checklist items Lock Box Key, Elevator Keys, Fire Alarm Keys (plural does not match `key`), Gas Card, Toughbook, CAD, Garage Door Clicker, SuperVac Battery Fan, Electric Fan, Gas Fan, Honda Generator, Fans. Station appliances (washers, refrigerators and so on) were deliberately not added: station requests never read this list, and adding them would suppress how-to answers for free-text messages.

### 6.3 `consumable_terms`: replace

```php
'terms' => [
    'kitty litter', 'oil dry', 'absorbent', 'absorbent pads',
    'paper towel', 'paper towels', 'towels', 'shop towels', 'rags', 'wipes',
    'trash bag', 'trash bags', 'garbage bag', 'garbage bags',
    'zip ties', 'zip tie', 'tape', 'duct tape', 'caution tape', 'safety tape', 'flagging tape',
    'aa battery', 'aa batteries', 'aaa battery', 'aaa batteries', 'd battery', 'd batteries',
    'chalk', 'permanent marker', 'permanent markers', 'sharpie', 'sharpies', 'pen', 'pens', 'grease pencil',
    'hand sanitizer', 'sanitizer', 'soap', 'degreaser', 'windex', 'glass cleaner',
    'cups', 'cup', 'lubricant', 'silicone', 'bar oil', 'chain oil', 'bar chain oil',
    'out of service tag', 'out of service tags', 'oos tag', 'oos tags',
],
```

Removed: `marker`, `markers` (would match the checklist item "Triangular Markers", a road-hazard device); `flares`, `road flares` (scene safety, now in `safety_terms`); `light sticks`, `glow sticks`, `cyalume` (lighting class, and no checklist item uses them). Added from checklist items: Kitty Litter, Absorbent / Absorbent Can / Absorbent Refill / Absorbent Bucket / Absorbent Pads, Yellow Safety Tape/Duct Tape, Caution Tape, Safety Tape, Water Cooler/Cups, Lubricant and Silicone, Marine Lubricant, Bar Chain Oil, Hyd Out of Service Tags. Not added: Vinegar, Bleach, Sanizide, SpeedyCleanz, Alcohol, Peroxide, Sterile Water (EMS and infection-control items; they fall to plain acknowledgment).

### 6.4 `dewalt_battery_terms`: add one term

```php
'terms' => ['dewalt battery', 'dewalt batteries', 'dewalt 20v', 'dewalt 60v', 'dewalt flexvolt', 'flexvolt', 'dewalt'],
```

The engine already requires the word battery or batteries in the item name, so keep the bare `dewalt`. Important finding: with the current checklists this template cannot fire. The only DeWalt items are "Dewalt K-Saw with Spare battery" and "Dewalt Reciprocating Saw (UASI#)" (rescue). Both contain `saw`, which is a safety term tested first, so they receive "Acknowledged." and a human flag. That is the conservative outcome and is recommended. To make the reminder reachable, add a separate checklist item named for the battery (for example "DeWalt spare battery") through the inventory-correction overlay, rather than loosening the saw rule. Needs owner agreement because the saw itself is a rescue tool.

### 6.5 `tool_battery_terms`: replace

```php
'terms' => [
    'tool battery', 'tool batteries', 'drill battery', 'drill batteries',
    'milwaukee battery', 'milwaukee batteries', 'm18 battery', 'm18 batteries',
    'makita battery', 'makita batteries',
],
```

Removed: `battery pack` and `spare battery`. The checklists use "Spare Battery" for the stretcher (after "Stretcher Charging"), "LUCAS Device W/ Spare Battery", "TIC w/ Spare Battery" and SCBA or radio packs, all medical or safety items that must not get the "check another tool" reply. Added: plurals and `m18` for the checklist item "M18 Batteries" (engine 2) and the Milwaukee tool set it serves. "SawZall 20 Volt and Batteries" is a saw and is acknowledgment-only.

### 6.6 `interchangeable_terms`: replace

```php
'terms' => [
    'spanner', 'spanners', 'spanner wrench', 'hydrant wrench', 'door wedge', 'door chock',
    'broom', 'brooms', 'squeegee', 'squeegees', 'shovel', 'shovels', 'bucket', 'buckets', 'mop', 'mops',
    'traffic cone', 'cone', 'cones', 'mallet', 'rubber mallet', 'screwdriver', 'pliers',
    'tarp', 'tarps', 'salvage cover', 'salvage covers',
    'extension cord', 'extension cords', 'cord reel', 'bungee', 'clipboard', 'water cooler',
],
```

Removed, with reason: `wrench` (matches "1/2 Impact Wrench"), `hammer` (matches "Hammer Drill"), `wedge` and `chock` (stabilization wedges and chocks), `wheel chock` (contains `wheel`, a safety term, so it was unreachable), `bolt cutter`, `pry bar`, `flathead axe` (forcible-entry tools, now safety), `pike pole hook`, `utility rope bag` (rope gear), `gated wye`, `double female`, `double male`, `reducer`, `increaser`, `adapter` (hose appliances and hydrant adapters, now safety), `strap`, `ratchet strap` (webbing and patient restraints). Added plurals to match checklist wording (Spanners, Squeegee, Shovel, Tarps, Salvage Covers, Standard Extension Cords, Buckets) plus Clipboard and Water Cooler. "Green Bucket (Decon)" is caught first by `decon`.

### 6.7 `urgency_terms`: replace

```php
'terms' => ['asap', 'urgent', 'urgently', 'immediately', 'right away', 'right now', 'need it now', 'need one now', 'need them now', 'needed now', 'cannot wait', 'today', 'tonight', 'tomorrow', 'this shift', 'this weekend'],
```

Only affects the weekend line and only on routine missing items, so the added terms carry no safety risk.

### 6.8 `bunker_dropoff_terms`: replace

```php
'terms' => [
    'bunker gear', 'bunker coat', 'bunker pants', 'turnout gear', 'turnouts', 'my gear',
    'gear dropped off', 'dropped off my gear', 'dropped off gear', 'left my gear', 'left gear',
    'gear for cleaning', 'gear for repair', 'gear to be cleaned', 'gear for pickup', 'gear for pick up',
    'gray bin', 'grey bin', 'gear bin',
],
```

The current generic phrases (`dropped off`, `drop off`, `dropped my`, `left my`, `left the`, `for cleaning`, `for pickup`, `for pick up`, `to be cleaned`, `for repair`) match unrelated messages such as "left my radio at Station 2", and because the drop-off check runs before the safety check, those messages get a routine reply with no human flag. The new terms are gear-qualified. The bin terms are detection only (members describe where they left gear); nothing member-facing repeats a location.

## 7. Engine observations for the Lead (not changed by this work)

1. Unit and place names. `engine`, `ladder` and `fire` are safety terms matched against whole messages and station-request text, so "Engine 2", "Ladder 3" and "Fire Station 2" force acknowledgment-only. Recommend removing unit designators (`engine|ladder|rescue|e|l|r` followed by digits) and the phrases "fire station", "fire rescue", "fire department", "fire shop" from the text before matching `safety_terms`. This keeps the lists conservative and restores how-to answers.
2. Bunker drop-off tier (conflict C7). In `forMessage` the drop-off check precedes the safety check and uses tier routine. Evaluate `safety_terms` first, or raise the tier to elevated when both match.
3. Bunker and station questions. Because `bunker` and `gear` are safety terms, a bunker how-to question can never reach retrieval. That matches the owner's rule (acknowledgment only), so the `bunker-gear-drop-off` article is reference content for staff and for the library until the owner decides otherwise.
4. Plural handling. `TermMatcher` does not singularize. Either keep plural or starred forms in the lists (as above), or add simple plural folding.
5. DeWalt reachability: see 6.4.

## 8. Maintenance

Review date on every article is 2027-05-01. Re-verify at review: Station 2 as the contact point; the Hub screen names (Message Support Services, Vehicle Checkout, Station Request, Apparatus Service); whether the 2026 text has been issued (then conflicts C4, C5, C9 may close); and the checklist item names behind the word lists.
