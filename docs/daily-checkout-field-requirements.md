# Daily Checkout field requirement matrix

Audit baseline: `0fdd914d5922ab08a7d4c45adf404b160b0fc749`, 2026-09-26. Baseline rules describe source before the mobile refinement. The user explicitly authorized mileage to become optional end-to-end; unrelated operational checks remain required. Runtime and paper-document verification are separate from this source audit.

## Authority and findings

- The premise that ordinary apparatus have no extra fields is stale. Engine, E2, Ladder 1, Ladder 3 and Rescue use `officerChecklist`; `resources/js/daily-checkout/src/utils/api.ts:253` normalizes it into `ChecklistData.fields`. Fire Boat uses schema-v2 `fields` (`api.ts:235`). Only the generic default checklist has no extra fields.
- Current submissions send `processing_version: 1` (`resources/js/daily-checkout/src/components/InspectionWizard.tsx:522`). `app/Http/Controllers/Api/ApparatusController.php:1059` invokes `InspectionPaperFields`, which validates all issued IDs and requires an answer record even for optional fields (`app/Services/InspectionPaperFields.php:40-54`). Optional means nullable/empty value, not dropping its ID.
- Baseline paper `mileage` is required on all five ordinary templates, contradicting nullable top-level miles and the requested optional-reading UX. Make all five optional together, retain the mileage ID and mirror nullable top-level miles. Do not make pressures, fuel, DEF, identifiers or equipment observations optional simply because they occupy a details screen.
- Entered mileage has another baseline mismatch: the top-level API allows 2,147,483,647, while paper numeric fields require absolute values below 1,000,000,000 (`InspectionPaperFields.php:114-116`). Ordinary mileage controls and readiness therefore use the tighter existing paper limit of 999,999,999, with minimum zero and integer steps. Checklists without a paper mileage field retain the top-level limit. Server rules remain unchanged.
- Ladder 1 identifies `L1 CHECKOUT SHEET.pdf`, page 1, Mar 2016 (`storage/checklists/ladder1_checklist.json:399`); Ladder 3 identifies `L3 CHECKOUT SHEET.pdf`, page 1, Revised 09/02/18 (`storage/checklists/ladder3_checklist.json:402`). Actual checkout PDFs were not available in tracked repository files during this audit. Engine/E2/Rescue/Fire Boat lack `sourceDocument` metadata. Citations establish the issued repository contract, not fresh verification of paper originals. Operational reasons describe each field's function, not newly asserted department policy.

## Shared processing contract

| Field | Apparatus/checklist | Client rule | Server rule | Operational reason | Paper/source authority | Proposed requirement |
|---|---|---|---|---|---|---|
| Member, operator_name, rank, employee_id | All | Signed-in personnel required; read-only member/rank | Require employee; canonical member values replace submitted identity | Immutable attribution | Wizard:150; OfficerStep:17; Controller:295,301-302,347-349 | Required; populate from signed-in member |
| Physical apparatus / unit_number | All | Route-selected apparatus; read-only vehicle | Persisted identity from locked apparatus; unit_number nullable | Attribute inspection to actual asset | Controller:304,465-475 | Auto-filled; never fabricate a number |
| shift | All current processing | A/B/C required | Required for processing_version=1; A/B/C only | Shift attribution | OfficerStep:19; Controller:303 | Required once; synchronize Fire Boat field |
| engine_hours | All | Blank accepted; finite 0..9999999.9, at most one decimal | Nullable numeric 0..9999999.9, decimal:0,1 | Current meter evidence when available | MeterStep:47-56; Controller:305 | Optional; previous value reference only |
| miles | All | Blank accepted; integer 0..2147483647 | Nullable integer 0..2147483647; paper mileage must match | Current odometer evidence when available | MeterStep:47-56; Controller:306,468 | Optional; retain entered-value validation and synchronization |
| meter_baseline_token | All | Server-issued token forwarded | Nullable; unverified/stale/rollback/implausible reading raises exception instead of overwriting baseline | Meter review protection | Controller:300; ApparatusInspectionProcessingService:28-58 | Unchanged |
| officer_signature | All current processing | Required before submission | Required for processing_version=1; validated image storage | Signed completion | SubmitStep:61-71; Controller:341,971-978,1017-1024 | Required |
| client_submission_id / checklist_version | All | Stable UUID and issued checklist hash | Required UUID/hash; idempotency/current-version checks | Prevent duplicates and stale-contract submissions | Controller:297-298,350-353,393-404,447-456 | Required; no bypass |
| inspection_date | Ordinary templates defining date | Server-issued date auto-filled/read-only | Optional paper date validated if supplied; no Fire Boat session binding on v1 | Issued checkout date | Wizard:276-280; ChecklistFieldsStep:133; Controller:50-55,87; InspectionPaperFields:104-114 | Auto-filled; no manual date checkpoint |
| inspection_date; session ID/token/replay key | Fire Boat | Requires server-issued session and read-only duty date | Session identity/duty_date match; task set session-bound | Bind duties to issued session/member | Controller:338-340,701,1297-1305; Wizard:48-63 | Required and unchanged |
| Every compartment/item observation | All | Explicit observation; navigation is not confirmation | Full issued matrix; Present/Missing/Damaged; observed=true | Complete inspection evidence | inspectionProgress:3-13; InspectionPaperFields:57-77; Controller:1053-1214,1457-1636 | Required; explicit item/bulk confirmation only |
| Present write-in item value | Non-checkbox equipment | Required unless valueRequired=false | Same conditional requirement plus type validation | Requested identifier/value | inspectionProgress:3-6; InspectionPaperFields:69-73 | Preserve; item matrix below |
| Equipment notes / photo | All | Optional expanded details | Nullable notes <=2000; photo <=7000000 plus image validation | Supporting finding evidence | Controller:318-325,1017-1024 | Optional; preserve existing evidence |
| Defect records | Missing/Damaged observations | Derived from explicit issues | Exact corresponding defect records required | Defect continuity | Wizard:499-518; Controller:1179-1205,1608-1634 | Required when issue exists |
| Scheduled duty status/observation | Fire Boat due tasks | Every due task explicitly observed | Exact session-issued set plus observed=true | Date-specific duties | ChecklistFieldsStep:68-71; InspectionPaperFields:80-84; Controller:1305 | Required when due |

Abbreviated paths: `Controller` = `app/Http/Controllers/Api/ApparatusController.php`; services = `app/Services/`; components = `resources/js/daily-checkout/src/components/`; `inspectionProgress` = `resources/js/daily-checkout/src/utils/inspectionProgress.ts`. Line numbers refer to baseline source.

## Issued detail fields

Client rules: `api.ts:235-260`, `ChecklistFieldsStep.tsx:56-65,126-150`, `inspectionProgress.ts:20-27`. Server rules: `InspectionPaperFields.php:40-54,102-118`, plus Fire Boat `ApparatusController.php:1223-1312`. Supplied text must be non-whitespace and <=2000 characters; numbers finite with absolute value <1,000,000,000; percentages 0..100; dates real; checkbox values boolean. Browser controls do not independently enforce every server bound. Missing `required` means optional. Every issued ID must still be included.

| Field | Apparatus/checklist | Baseline client rule | Baseline server rule | Operational reason | Paper/source authority | Proposed requirement |
|---|---|---|---|---|---|---|
| `inspection_date` (Date) | engine | Optional date | Optional date; include ID | Record Date | `storage/checklists/engine_checklist.json:34` | Auto-filled read-only |
| `mileage` (Mileage) | engine | Required number | Required number; include ID; exact miles match | Current odometer, when available | `storage/checklists/engine_checklist.json:35` | Optional; mirror nullable miles |
| `vehicle_num` (Vehicle #) | engine | Required text | Required text; include ID; exact apparatus match | Record Vehicle # | `storage/checklists/engine_checklist.json:36` | Required, auto-filled read-only |
| `engine_temp` (Engine Temp) | engine | Required text | Required text; include ID | Record Engine Temp | `storage/checklists/engine_checklist.json:37` | Required; preserve |
| `oil_pressure` (Oil Pressure) | engine | Required text | Required text; include ID | Record Oil Pressure | `storage/checklists/engine_checklist.json:38` | Required; preserve |
| `fuel` (Fuel) | engine | Required text | Required text; include ID | Record Fuel | `storage/checklists/engine_checklist.json:39` | Required; preserve |
| `air_pressure_front` (Air Pressure / Front) | engine | Required number | Required number; include ID | Record Air Pressure / Front | `storage/checklists/engine_checklist.json:40` | Required; preserve |
| `air_pressure_rear` (Air Pressure / Rear) | engine | Required number | Required number; include ID | Record Air Pressure / Rear | `storage/checklists/engine_checklist.json:41` | Required; preserve |
| `toughbook_num` (Toughbook #) | engine | Optional text | Optional text; include ID | Record Toughbook # | `storage/checklists/engine_checklist.json:42` | Optional; preserve |
| `cad_num` (CAD #) | engine | Optional text | Optional text; include ID | Record CAD # | `storage/checklists/engine_checklist.json:43` | Optional; preserve |
| `sensit_num` (SENSIT #) | engine | Optional text | Optional text; include ID | Record SENSIT # | `storage/checklists/engine_checklist.json:44` | Optional; preserve |
| `scba_1` (SCBA #1) | engine | Optional text | Optional text; include ID | Record SCBA #1 | `storage/checklists/engine_checklist.json:45` | Optional; preserve |
| `scba_2` (SCBA #2) | engine | Optional text | Optional text; include ID | Record SCBA #2 | `storage/checklists/engine_checklist.json:46` | Optional; preserve |
| `scba_3` (SCBA #3) | engine | Optional text | Optional text; include ID | Record SCBA #3 | `storage/checklists/engine_checklist.json:47` | Optional; preserve |
| `scba_4` (SCBA #4) | engine | Optional text | Optional text; include ID | Record SCBA #4 | `storage/checklists/engine_checklist.json:48` | Optional; preserve |
| `radio_oic` (Crew Radio# OIC) | engine | Optional text | Optional text; include ID | Record Crew Radio# OIC | `storage/checklists/engine_checklist.json:49` | Optional; preserve |
| `radio_de` (DE Radio#) | engine | Optional text | Optional text; include ID | Record DE Radio# | `storage/checklists/engine_checklist.json:50` | Optional; preserve |
| `radio_fi1` (FI Radio# 1) | engine | Optional text | Optional text; include ID | Record FI Radio# 1 | `storage/checklists/engine_checklist.json:51` | Optional; preserve |
| `radio_fi2` (FI Radio# 2) | engine | Optional text | Optional text; include ID | Record FI Radio# 2 | `storage/checklists/engine_checklist.json:52` | Optional; preserve |
| `plymovent_trans` (Plymovent Trans Working? (Y/N)) | engine | Required text | Required text; include ID | Record Plymovent Trans Working? (Y/N) | `storage/checklists/engine_checklist.json:53` | Required; preserve |
| `scba_5` (SCBA #5) | engine | Optional text | Optional text; include ID | Record SCBA #5 | `storage/checklists/engine_checklist.json:54` | Optional; preserve |
| `radio_fi3` (FI Radio# 3) | engine | Optional text | Optional text; include ID | Record FI Radio# 3 | `storage/checklists/engine_checklist.json:55` | Optional; preserve |
| `voice_amp_num` (Voice Amp# (Front Cab)) | engine | Optional text | Optional text; include ID | Record Voice Amp# (Front Cab) | `storage/checklists/engine_checklist.json:56` | Optional; preserve |
| `checkout_type` (Daily Check Out / Change Over) | engine | Optional text | Optional text; include ID | Record Daily Check Out / Change Over | `storage/checklists/engine_checklist.json:57` | Optional; preserve |
| `changeover_equipment_removed` (Change Over - equipment removed) | engine | Optional text | Optional text; include ID | Record Change Over - equipment removed | `storage/checklists/engine_checklist.json:58` | Optional; preserve |
| `new_damage_description` (New Damages - description) | engine | Optional text | Optional text; include ID | Record New Damages - description | `storage/checklists/engine_checklist.json:59` | Optional; preserve |
| `new_damage_location` (New Damages - location on apparatus) | engine | Optional text | Optional text; include ID | Record New Damages - location on apparatus | `storage/checklists/engine_checklist.json:60` | Optional; preserve |
| `inspection_date` (Date) | engine2 | Optional date | Optional date; include ID | Record Date | `storage/checklists/engine2_checklist.json:34` | Auto-filled read-only |
| `mileage` (Mileage) | engine2 | Required number | Required number; include ID; exact miles match | Current odometer, when available | `storage/checklists/engine2_checklist.json:35` | Optional; mirror nullable miles |
| `vehicle_num` (Vehicle #) | engine2 | Required text | Required text; include ID; exact apparatus match | Record Vehicle # | `storage/checklists/engine2_checklist.json:36` | Required, auto-filled read-only |
| `engine_temp` (Engine Temp) | engine2 | Required text | Required text; include ID | Record Engine Temp | `storage/checklists/engine2_checklist.json:37` | Required; preserve |
| `oil_pressure` (Oil Pressure) | engine2 | Required text | Required text; include ID | Record Oil Pressure | `storage/checklists/engine2_checklist.json:38` | Required; preserve |
| `fuel` (Fuel) | engine2 | Required text | Required text; include ID | Record Fuel | `storage/checklists/engine2_checklist.json:39` | Required; preserve |
| `def` (DEF) | engine2 | Optional text | Optional text; include ID | Record DEF | `storage/checklists/engine2_checklist.json:40` | Optional; preserve |
| `air_pressure_front` (Air Pressure / Front) | engine2 | Required number | Required number; include ID | Record Air Pressure / Front | `storage/checklists/engine2_checklist.json:41` | Required; preserve |
| `air_pressure_rear` (Air Pressure / Rear) | engine2 | Required number | Required number; include ID | Record Air Pressure / Rear | `storage/checklists/engine2_checklist.json:42` | Required; preserve |
| `toughbook_num` (Toughbook #) | engine2 | Optional text | Optional text; include ID | Record Toughbook # | `storage/checklists/engine2_checklist.json:43` | Optional; preserve |
| `cad_num` (CAD #) | engine2 | Optional text | Optional text; include ID | Record CAD # | `storage/checklists/engine2_checklist.json:44` | Optional; preserve |
| `sensit_num` (SENSIT #) | engine2 | Optional text | Optional text; include ID | Record SENSIT # | `storage/checklists/engine2_checklist.json:45` | Optional; preserve |
| `scba_1` (SCBA #1) | engine2 | Optional text | Optional text; include ID | Record SCBA #1 | `storage/checklists/engine2_checklist.json:46` | Optional; preserve |
| `scba_2` (SCBA #2) | engine2 | Optional text | Optional text; include ID | Record SCBA #2 | `storage/checklists/engine2_checklist.json:47` | Optional; preserve |
| `scba_3` (SCBA #3) | engine2 | Optional text | Optional text; include ID | Record SCBA #3 | `storage/checklists/engine2_checklist.json:48` | Optional; preserve |
| `scba_4` (SCBA #4) | engine2 | Optional text | Optional text; include ID | Record SCBA #4 | `storage/checklists/engine2_checklist.json:49` | Optional; preserve |
| `radio_oic` (Crew Radio# OIC) | engine2 | Optional text | Optional text; include ID | Record Crew Radio# OIC | `storage/checklists/engine2_checklist.json:50` | Optional; preserve |
| `radio_de` (DE Radio#) | engine2 | Optional text | Optional text; include ID | Record DE Radio# | `storage/checklists/engine2_checklist.json:51` | Optional; preserve |
| `radio_fi1` (FI Radio# 1) | engine2 | Optional text | Optional text; include ID | Record FI Radio# 1 | `storage/checklists/engine2_checklist.json:52` | Optional; preserve |
| `radio_fi2` (FI Radio# 2) | engine2 | Optional text | Optional text; include ID | Record FI Radio# 2 | `storage/checklists/engine2_checklist.json:53` | Optional; preserve |
| `plymovent_trans` (Plymovent Trans Working? (Y/N)) | engine2 | Required text | Required text; include ID | Record Plymovent Trans Working? (Y/N) | `storage/checklists/engine2_checklist.json:54` | Required; preserve |
| `scba_5` (SCBA #5) | engine2 | Optional text | Optional text; include ID | Record SCBA #5 | `storage/checklists/engine2_checklist.json:55` | Optional; preserve |
| `checkout_type` (Daily Check Out / Change Over) | engine2 | Optional text | Optional text; include ID | Record Daily Check Out / Change Over | `storage/checklists/engine2_checklist.json:56` | Optional; preserve |
| `changeover_equipment_removed` (Change Over - equipment removed) | engine2 | Optional text | Optional text; include ID | Record Change Over - equipment removed | `storage/checklists/engine2_checklist.json:57` | Optional; preserve |
| `new_damage_description` (New Damages - description) | engine2 | Optional text | Optional text; include ID | Record New Damages - description | `storage/checklists/engine2_checklist.json:58` | Optional; preserve |
| `new_damage_location` (New Damages - location on apparatus) | engine2 | Optional text | Optional text; include ID | Record New Damages - location on apparatus | `storage/checklists/engine2_checklist.json:59` | Optional; preserve |
| `inspection_date` (Date) | ladder1 | Optional date | Optional date; include ID | Record Date | `storage/checklists/ladder1_checklist.json:4` | Auto-filled read-only |
| `mileage` (Mileage) | ladder1 | Required number | Required number; include ID; exact miles match | Current odometer, when available | `storage/checklists/ladder1_checklist.json:5` | Optional; mirror nullable miles |
| `vehicle_num` (Vehicle #) | ladder1 | Required text | Required text; include ID; exact apparatus match | Record Vehicle # | `storage/checklists/ladder1_checklist.json:6` | Required, auto-filled read-only |
| `engine_temp` (Engine Temp) | ladder1 | Optional text | Optional text; include ID | Record Engine Temp | `storage/checklists/ladder1_checklist.json:7` | Optional; preserve |
| `oil_pressure` (Oil Pressure) | ladder1 | Optional text | Optional text; include ID | Record Oil Pressure | `storage/checklists/ladder1_checklist.json:8` | Optional; preserve |
| `fuel` (Fuel) | ladder1 | Required percentage | Required percentage; include ID | Record Fuel | `storage/checklists/ladder1_checklist.json:9` | Required; preserve |
| `air_pressure_front` (Air Pressure / Front) | ladder1 | Required number | Required number; include ID | Record Air Pressure / Front | `storage/checklists/ladder1_checklist.json:10` | Required; preserve |
| `air_pressure_rear` (Air Pressure / Rear) | ladder1 | Required number | Required number; include ID | Record Air Pressure / Rear | `storage/checklists/ladder1_checklist.json:11` | Required; preserve |
| `pto_hours` (PTO Hours) | ladder1 | Optional number | Optional number; include ID | Record PTO Hours | `storage/checklists/ladder1_checklist.json:12` | Optional; preserve |
| `aerial_meter` (Aerial Meter) | ladder1 | Optional number | Optional number; include ID | Record Aerial Meter | `storage/checklists/ladder1_checklist.json:13` | Optional; preserve |
| `air_tank_pressure` (Air Tank Pressure) | ladder1 | Optional number | Optional number; include ID | Record Air Tank Pressure | `storage/checklists/ladder1_checklist.json:14` | Optional; preserve |
| `cad_num` (CAD #) | ladder1 | Optional text | Optional text; include ID | Record CAD # | `storage/checklists/ladder1_checklist.json:15` | Optional; preserve |
| `radio_oic` (Crew Radio # - OIC) | ladder1 | Optional text | Optional text; include ID | Record Crew Radio # - OIC | `storage/checklists/ladder1_checklist.json:16` | Optional; preserve |
| `radio_fi3` (Crew Radio # - FI #3) | ladder1 | Optional text | Optional text; include ID | Record Crew Radio # - FI #3 | `storage/checklists/ladder1_checklist.json:17` | Optional; preserve |
| `checkout_type` (Daily Check Out / Change Over) | ladder1 | Optional text | Optional text; include ID | Record Daily Check Out / Change Over | `storage/checklists/ladder1_checklist.json:18` | Optional; preserve |
| `changeover_equipment_removed` (Change Over - equipment removed) | ladder1 | Optional text | Optional text; include ID | Record Change Over - equipment removed | `storage/checklists/ladder1_checklist.json:19` | Optional; preserve |
| `new_damage_description` (New Damages - description) | ladder1 | Optional text | Optional text; include ID | Record New Damages - description | `storage/checklists/ladder1_checklist.json:20` | Optional; preserve |
| `new_damage_location` (New Damages - location on apparatus) | ladder1 | Optional text | Optional text; include ID | Record New Damages - location on apparatus | `storage/checklists/ladder1_checklist.json:21` | Optional; preserve |
| `inspection_date` (Date) | ladder3 | Optional date | Optional date; include ID | Record Date | `storage/checklists/ladder3_checklist.json:4` | Auto-filled read-only |
| `mileage` (Mileage) | ladder3 | Required number | Required number; include ID; exact miles match | Current odometer, when available | `storage/checklists/ladder3_checklist.json:5` | Optional; mirror nullable miles |
| `vehicle_num` (Vehicle #) | ladder3 | Required text | Required text; include ID; exact apparatus match | Record Vehicle # | `storage/checklists/ladder3_checklist.json:6` | Required, auto-filled read-only |
| `engine_temp` (Engine Temp) | ladder3 | Optional text | Optional text; include ID | Record Engine Temp | `storage/checklists/ladder3_checklist.json:7` | Optional; preserve |
| `oil_pressure` (Oil Pressure) | ladder3 | Optional text | Optional text; include ID | Record Oil Pressure | `storage/checklists/ladder3_checklist.json:8` | Optional; preserve |
| `fuel` (Fuel) | ladder3 | Required percentage | Required percentage; include ID | Record Fuel | `storage/checklists/ladder3_checklist.json:9` | Required; preserve |
| `def` (DEF) | ladder3 | Required percentage | Required percentage; include ID | Record DEF | `storage/checklists/ladder3_checklist.json:10` | Required; preserve |
| `aerial_master` (Aerial Master) | ladder3 | Optional text | Optional text; include ID | Record Aerial Master | `storage/checklists/ladder3_checklist.json:11` | Optional; preserve |
| `air_tank_pressure` (Air Tank Pressure) | ladder3 | Optional number | Optional number; include ID | Record Air Tank Pressure | `storage/checklists/ladder3_checklist.json:12` | Optional; preserve |
| `air_pressure_front` (Air Pressure / Front) | ladder3 | Required number | Required number; include ID | Record Air Pressure / Front | `storage/checklists/ladder3_checklist.json:13` | Required; preserve |
| `air_pressure_rear` (Air Pressure / Rear) | ladder3 | Required number | Required number; include ID | Record Air Pressure / Rear | `storage/checklists/ladder3_checklist.json:14` | Required; preserve |
| `toughbook` (Toughbook) | ladder3 | Optional text | Optional text; include ID | Record Toughbook | `storage/checklists/ladder3_checklist.json:15` | Optional; preserve |
| `cad_num` (CAD #) | ladder3 | Optional text | Optional text; include ID | Record CAD # | `storage/checklists/ladder3_checklist.json:16` | Optional; preserve |
| `radio_oic` (Radio # - OIC) | ladder3 | Optional text | Optional text; include ID | Record Radio # - OIC | `storage/checklists/ladder3_checklist.json:17` | Optional; preserve |
| `personnel_oic` (Personnel - OIC) | ladder3 | Optional text | Optional text; include ID | Record Personnel - OIC | `storage/checklists/ladder3_checklist.json:18` | Optional; preserve |
| `personnel_de` (Personnel - DE) | ladder3 | Optional text | Optional text; include ID | Record Personnel - DE | `storage/checklists/ladder3_checklist.json:19` | Optional; preserve |
| `personnel_ff1` (Personnel - FF #1) | ladder3 | Optional text | Optional text; include ID | Record Personnel - FF #1 | `storage/checklists/ladder3_checklist.json:20` | Optional; preserve |
| `personnel_ff2` (Personnel - FF #2) | ladder3 | Optional text | Optional text; include ID | Record Personnel - FF #2 | `storage/checklists/ladder3_checklist.json:21` | Optional; preserve |
| `checkout_type` (Daily Check Out / Change Over) | ladder3 | Optional text | Optional text; include ID | Record Daily Check Out / Change Over | `storage/checklists/ladder3_checklist.json:22` | Optional; preserve |
| `changeover_equipment_removed` (Change Over - equipment removed) | ladder3 | Optional text | Optional text; include ID | Record Change Over - equipment removed | `storage/checklists/ladder3_checklist.json:23` | Optional; preserve |
| `new_damage_description` (New Damages - description) | ladder3 | Optional text | Optional text; include ID | Record New Damages - description | `storage/checklists/ladder3_checklist.json:24` | Optional; preserve |
| `new_damage_location` (New Damages - location on apparatus) | ladder3 | Optional text | Optional text; include ID | Record New Damages - location on apparatus | `storage/checklists/ladder3_checklist.json:25` | Optional; preserve |
| `lock_box_key` (Lock Box Key) | rescue | Optional checkbox | Optional checkbox; include ID | Record Lock Box Key | `storage/checklists/rescue_checklist.json:34` | Optional; preserve |
| `elevator_key` (Elevator Key) | rescue | Optional checkbox | Optional checkbox; include ID | Record Elevator Key | `storage/checklists/rescue_checklist.json:35` | Optional; preserve |
| `mileage` (Mileage) | rescue | Required number | Required number; include ID; exact miles match | Current odometer, when available | `storage/checklists/rescue_checklist.json:36` | Optional; mirror nullable miles |
| `laptop_num` (Dell Laptop #) | rescue | Optional text | Optional text; include ID | Record Dell Laptop # | `storage/checklists/rescue_checklist.json:37` | Optional; preserve |
| `toughbook_num` (Toughbook #) | rescue | Optional text | Optional text; include ID | Record Toughbook # | `storage/checklists/rescue_checklist.json:38` | Optional; preserve |
| `psi_front` (PSI – Front Tire #) | rescue | Required number | Required number; include ID | Record PSI – Front Tire # | `storage/checklists/rescue_checklist.json:39` | Required; preserve |
| `psi_rear` (PSI – Rear Tire #) | rescue | Required number | Required number; include ID | Record PSI – Rear Tire # | `storage/checklists/rescue_checklist.json:40` | Required; preserve |
| `brake_psi` (Brake PSI #) | rescue | Required number | Required number; include ID | Record Brake PSI # | `storage/checklists/rescue_checklist.json:41` | Required; preserve |
| `narcotic_book` (Narcotic Book) | rescue | Optional checkbox | Optional checkbox; include ID | Record Narcotic Book | `storage/checklists/rescue_checklist.json:42` | Optional; preserve |
| `narcotic_seal` (Narcotic Seal #) | rescue | Required text | Required text; include ID | Record Narcotic Seal # | `storage/checklists/rescue_checklist.json:43` | Required; preserve |
| `m_cylinder_psi` (M Cylinder PSI) | rescue | Optional number | Optional number; include ID | Record M Cylinder PSI | `storage/checklists/rescue_checklist.json:44` | Optional; preserve |
| `vehicle_log_book` (Vehicle Log Book) | rescue | Optional checkbox | Optional checkbox; include ID | Record Vehicle Log Book | `storage/checklists/rescue_checklist.json:45` | Optional; preserve |
| `fuel_card` (Fuel Card) | rescue | Optional checkbox | Optional checkbox; include ID | Record Fuel Card | `storage/checklists/rescue_checklist.json:46` | Optional; preserve |
| `fuel_percent` (Fuel %) | rescue | Required percentage | Required percentage; include ID | Record Fuel % | `storage/checklists/rescue_checklist.json:47` | Required; preserve |
| `def_percent` (DEF %) | rescue | Required percentage | Required percentage; include ID | Record DEF % | `storage/checklists/rescue_checklist.json:48` | Required; preserve |
| `cad_logged_in` (CAD Logged In) | rescue | Optional checkbox | Optional checkbox; include ID | Record CAD Logged In | `storage/checklists/rescue_checklist.json:49` | Optional; preserve |
| `tire_pressure_gauge` (Tire Pressure Gauge) | rescue | Optional checkbox | Optional checkbox; include ID | Record Tire Pressure Gauge | `storage/checklists/rescue_checklist.json:50` | Optional; preserve |
| `inspection_date` (Date) | fireboat6 | Required date | Required date; include ID | Record Date | `storage/checklists/fireboat6_checklist.json:8` | Auto-filled read-only; session-bound required |
| `fb6-shift` (Shift) | fireboat6 | Required HTML selector | Optional paper value; top-level shift required | Record Shift | `storage/checklists/fireboat6_checklist.json:9` | Collect required shift once; synchronize paper value |
| `fb6-high-low-tide` (High Low Tide) | fireboat6 | Optional text | Optional text; include ID | Record High Low Tide | `storage/checklists/fireboat6_checklist.json:10` | Optional; preserve |
| `fb6-driver-raymarine` (Driver Raymarine) | fireboat6 | Optional checkbox | Optional checkbox; include ID | Record Driver Raymarine | `storage/checklists/fireboat6_checklist.json:11` | Optional; preserve |
| `fb6-eng-raymarine` (ENG Raymarine) | fireboat6 | Optional checkbox | Optional checkbox; include ID | Record ENG Raymarine | `storage/checklists/fireboat6_checklist.json:12` | Optional; preserve |
| `fb6-ofc-raymarine` (OFC Raymarine) | fireboat6 | Optional checkbox | Optional checkbox; include ID | Record OFC Raymarine | `storage/checklists/fireboat6_checklist.json:13` | Optional; preserve |
| `fb6-raymarine-radar-flir-sonar-transducer` (Raymarine Radar / FLIR / Sonar Transducer) | fireboat6 | Optional checkbox | Optional checkbox; include ID | Record Raymarine Radar / FLIR / Sonar Transducer | `storage/checklists/fireboat6_checklist.json:14` | Optional; preserve |
| `fb6-port-engine-hours` (Port Engine Hours) | fireboat6 | Optional number | Optional number; include ID | Record Port Engine Hours | `storage/checklists/fireboat6_checklist.json:15` | Optional; preserve |
| `fb6-star-engine-hours` (STAR Engine Hours) | fireboat6 | Optional number | Optional number; include ID | Record STAR Engine Hours | `storage/checklists/fireboat6_checklist.json:16` | Optional; preserve |
| `fb6-generator-hours` (Generator Hours) | fireboat6 | Optional number | Optional number; include ID | Record Generator Hours | `storage/checklists/fireboat6_checklist.json:17` | Optional; preserve |
| `fb6-mbfd-radio-test` (MBFD Radio Test) | fireboat6 | Optional checkbox | Optional checkbox; include ID | Record MBFD Radio Test | `storage/checklists/fireboat6_checklist.json:18` | Optional; preserve |
| `fb6-vhf-radio-test` (VHF Radio Test) | fireboat6 | Optional checkbox | Optional checkbox; include ID | Record VHF Radio Test | `storage/checklists/fireboat6_checklist.json:19` | Optional; preserve |
| `fb6-scba-id-1` (SCBA ID#) | fireboat6 | Optional text | Optional text; include ID | Record SCBA ID# | `storage/checklists/fireboat6_checklist.json:20` | Optional; preserve |
| `fb6-scba-id-2` (SCBA ID#) | fireboat6 | Optional text | Optional text; include ID | Record SCBA ID# | `storage/checklists/fireboat6_checklist.json:21` | Optional; preserve |
| `fb6-scba-id-3` (SCBA ID#) | fireboat6 | Optional text | Optional text; include ID | Record SCBA ID# | `storage/checklists/fireboat6_checklist.json:22` | Optional; preserve |
| `fb6-scba-id-4` (SCBA ID#) | fireboat6 | Optional text | Optional text; include ID | Record SCBA ID# | `storage/checklists/fireboat6_checklist.json:23` | Optional; preserve |
| `fb6-driver-radio-last-four-serial` (Driver Radio - Last 4 Digits of Motorola Serial Number) | fireboat6 | Optional text | Optional text; include ID | Record Driver Radio - Last 4 Digits of Motorola Serial Number | `storage/checklists/fireboat6_checklist.json:24` | Optional; preserve |
| `fb6-engineer` (Engineer Radio - Last 4 Digits of Motorola Serial Number) | fireboat6 | Optional text | Optional text; include ID | Record Engineer Radio - Last 4 Digits of Motorola Serial Number | `storage/checklists/fireboat6_checklist.json:25` | Optional; preserve |
| `fb6-officer` (Officer Radio - Last 4 Digits of Motorola Serial Number) | fireboat6 | Optional text | Optional text; include ID | Record Officer Radio - Last 4 Digits of Motorola Serial Number | `storage/checklists/fireboat6_checklist.json:26` | Optional; preserve |
| `fb6-deck-hand` (Deck Hand Radio - Last 4 Digits of Motorola Serial Number) | fireboat6 | Optional text | Optional text; include ID | Record Deck Hand Radio - Last 4 Digits of Motorola Serial Number | `storage/checklists/fireboat6_checklist.json:27` | Optional; preserve |
| `fb6-driver-crew-signoff` (Driver crew signoff (paper footer; text record)) | fireboat6 | Optional text | Optional text; include ID | Record Driver crew signoff (paper footer; text record) | `storage/checklists/fireboat6_checklist.json:28` | Optional; preserve |
| `fb6-engineer-crew-signoff` (Engineer crew signoff (paper footer; text record)) | fireboat6 | Optional text | Optional text; include ID | Record Engineer crew signoff (paper footer; text record) | `storage/checklists/fireboat6_checklist.json:29` | Optional; preserve |
| `fb6-officer-crew-signoff` (Officer crew signoff (paper footer; text record)) | fireboat6 | Optional text | Optional text; include ID | Record Officer crew signoff (paper footer; text record) | `storage/checklists/fireboat6_checklist.json:30` | Optional; preserve |
| `fb6-deck-hand-crew-signoff` (Deck Hand crew signoff (paper footer; text record)) | fireboat6 | Optional text | Optional text; include ID | Record Deck Hand crew signoff (paper footer; text record) | `storage/checklists/fireboat6_checklist.json:31` | Optional; preserve |

## Equipment write-in requirements

All items below still require explicit Present/Missing/Damaged observation. Expected quantity remains issued metadata, not a fabricated measurement. All checkbox equipment retains the complete-observation rule above.

| Field | Apparatus/checklist | Client rule | Server rule | Operational reason | Paper/source authority | Proposed requirement |
|---|---|---|---|---|---|---|
| `comp_4-item-1` (CO ID#) | ladder1 / b_comp_4 | Typed value required when Present | Typed value required when Present; observed=true | Record CO ID# | `storage/checklists/ladder1_checklist.json:170` | Preserve |
| `comp_4-item-2` (H2S ID#) | ladder1 / b_comp_4 | Typed value required when Present | Typed value required when Present; observed=true | Record H2S ID# | `storage/checklists/ladder1_checklist.json:171` | Preserve |
| `front_cab-item-26` (Plymovent Transmitter Working? (Y / N)) | ladder1 / front_cab | Typed value required when Present | Typed value required when Present; observed=true | Record Plymovent Transmitter Working? (Y / N) | `storage/checklists/ladder1_checklist.json:263` | Preserve |
| `scba_radio-item-1` (SCBA #1) | ladder1 / scba_radio | Typed value required when Present | Typed value required when Present; observed=true | Record SCBA #1 | `storage/checklists/ladder1_checklist.json:388` | Preserve |
| `scba_radio-item-2` (SCBA #2) | ladder1 / scba_radio | Typed value required when Present | Typed value required when Present; observed=true | Record SCBA #2 | `storage/checklists/ladder1_checklist.json:389` | Preserve |
| `scba_radio-item-3` (SCBA #3) | ladder1 / scba_radio | Typed value required when Present | Typed value required when Present; observed=true | Record SCBA #3 | `storage/checklists/ladder1_checklist.json:390` | Preserve |
| `scba_radio-item-4` (SCBA #4) | ladder1 / scba_radio | Typed value required when Present | Typed value required when Present; observed=true | Record SCBA #4 | `storage/checklists/ladder1_checklist.json:391` | Preserve |
| `scba_radio-item-5` (SCBA #5) | ladder1 / scba_radio | Typed value required when Present | Typed value required when Present; observed=true | Record SCBA #5 | `storage/checklists/ladder1_checklist.json:392` | Preserve |
| `scba_radio-item-6` (Crew Radio # - DE) | ladder1 / scba_radio | Typed value required when Present | Typed value required when Present; observed=true | Record Crew Radio # - DE | `storage/checklists/ladder1_checklist.json:393` | Preserve |
| `scba_radio-item-7` (Crew Radio # - FI #1) | ladder1 / scba_radio | Typed value required when Present | Typed value required when Present; observed=true | Record Crew Radio # - FI #1 | `storage/checklists/ladder1_checklist.json:394` | Preserve |
| `scba_radio-item-8` (Crew Radio # - FI #2) | ladder1 / scba_radio | Typed value required when Present | Typed value required when Present; observed=true | Record Crew Radio # - FI #2 | `storage/checklists/ladder1_checklist.json:395` | Preserve |
| `co_3500pak_num` (CO 3500PAK #) | ladder3 / b_comp_3 | Optional typed value | Optional typed value; observed=true | Record CO 3500PAK # | `storage/checklists/ladder3_checklist.json:188` | Preserve |
| `h2s_3500pak_num` (H2S 3500 PAK#) | ladder3 / b_comp_3 | Optional typed value | Optional typed value; observed=true | Record H2S 3500 PAK# | `storage/checklists/ladder3_checklist.json:189` | Preserve |
| `scba_radio-item-1` (SCBA #1) | ladder3 / scba_radio | Typed value required when Present | Typed value required when Present; observed=true | Record SCBA #1 | `storage/checklists/ladder3_checklist.json:392` | Preserve |
| `scba_radio-item-2` (SCBA #2) | ladder3 / scba_radio | Typed value required when Present | Typed value required when Present; observed=true | Record SCBA #2 | `storage/checklists/ladder3_checklist.json:393` | Preserve |
| `scba_radio-item-3` (SCBA #3) | ladder3 / scba_radio | Typed value required when Present | Typed value required when Present; observed=true | Record SCBA #3 | `storage/checklists/ladder3_checklist.json:394` | Preserve |
| `scba_radio-item-4` (SCBA #4) | ladder3 / scba_radio | Typed value required when Present | Typed value required when Present; observed=true | Record SCBA #4 | `storage/checklists/ladder3_checklist.json:395` | Preserve |
| `scba_radio-item-5` (Radio # - DE) | ladder3 / scba_radio | Typed value required when Present | Typed value required when Present; observed=true | Record Radio # - DE | `storage/checklists/ladder3_checklist.json:396` | Preserve |
| `scba_radio-item-6` (Radio # - FI #1) | ladder3 / scba_radio | Typed value required when Present | Typed value required when Present; observed=true | Record Radio # - FI #1 | `storage/checklists/ladder3_checklist.json:397` | Preserve |
| `scba_radio-item-7` (Radio # - FI #2) | ladder3 / scba_radio | Typed value required when Present | Typed value required when Present; observed=true | Record Radio # - FI #2 | `storage/checklists/ladder3_checklist.json:398` | Preserve |
| `compartment_d-item-4` (SCBAs) | rescue / compartment_d | Typed value required when Present | Typed value required when Present; observed=true | Record SCBAs | `storage/checklists/rescue_checklist.json:70` | Preserve |
| `compartment_d-item-13` (LP CO Monitor Serial #) | rescue / patient_compartment | Typed value required when Present | Typed value required when Present; observed=true | Record LP CO Monitor Serial # | `storage/checklists/rescue_checklist.json:192` | Preserve |
| `radio_numbers-item-1` (Radio - R. Lt.) | rescue / radio_numbers | Optional typed value | Optional typed value; observed=true | Record Radio - R. Lt. | `storage/checklists/rescue_checklist.json:212` | Preserve |
| `radio_numbers-item-2` (Radio - F1A) | rescue / radio_numbers | Optional typed value | Optional typed value; observed=true | Record Radio - F1A | `storage/checklists/rescue_checklist.json:213` | Preserve |
| `radio_numbers-item-3` (Radio - F1B) | rescue / radio_numbers | Optional typed value | Optional typed value; observed=true | Record Radio - F1B | `storage/checklists/rescue_checklist.json:214` | Preserve |

## Smallest changes and verification risks

1. Make only five ordinary paper `mileage` definitions optional. Retain every stable ID, geometry, assignment, entered-value rule and exact paper-mileage/top-level-miles equality. Checklist hashes change naturally; drafts/queues must use existing version-conflict behavior, never silent payload rewriting.
2. Label readings optional and never initialize new readings from previous values. Empty meters must pass client readiness and actual server submission. Preserve baseline-token handling, rollback review and exceptions.
3. Collect shift once and mirror Fire Boat's paper answer. Optional paper shift does not override required processing shift. Keep known date/vehicle automatic and read-only, without inventing missing source data.
4. Keep required details visible, optional details in disclosure. Ordinary checklists have mandatory operational fields; removing Details wholesale would weaken/block the contract. Retain optional answer records while collapsed. Fire Boat still requires session/date, shift and due-duty observations.
5. Test blank mileage across all five templates, invalid supplied values/mileage mismatch, required non-meter fields/signature/shift, required Present write-ins versus optional Rescue radios/Ladder 3 detector IDs, unchanged defect mapping. Baseline `tests/Feature/Services/InspectionPaperFieldsTest.php:95` expects null mileage rejection; replace that example with a genuinely required field and add blank-meter acceptance.
6. Route-mocked browser tests cannot prove server acceptance, session binding, idempotency, meter exceptions or actor protection. Run API integrity/session/automatic-processing and paper-field suites separately. Queue/reconnect must preserve answers and same-member ownership; different-member restoration must fail. Collapse must never create fresh answer state or implicitly observe an item.
7. HTML required attributes alone are insufficient. Invalid optional percentages/types and whitespace still require rejection. Inspect the complete payload including nullable paper mileage, not just top-level meter properties.

This matrix is source evidence. Test execution and authenticated runtime/browser results must be reported separately.
