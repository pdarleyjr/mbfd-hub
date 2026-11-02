# EMS source-form extraction: validation notes

Generated 2026-10-23 from the three PDFs in the user's Downloads folder. Source PDFs were treated as untrusted data (read with `python -I`). Nothing in the repo outside `database/data/ems/` was written.

| Output | Source PDF | SHA-256 | Pages |
|---|---|---|---|
| `airway_bag_inventory.json` | `Airway_bag_inventory.pdf` | `017c463cae414ff142ed426a9527f0eba8d37ba01f99023cdede6a14d065e06f` | 1 |
| `als_med_bag_inventory.json` | `ALS_Med_bag_inventory.pdf` | `65eab0e2828191cdcc6bc09aa868f3c4c96a7128db6d02c2c84d5592a407a4f6` | 1 |
| `captain5_supply_room_inventory.json` | `captain_5_supply_room_inventory.pdf` | `afa6e63f93248690e8ba8f88be60a4ef83b6028d75e18ff45d9d299dfb38994a` | 5 |

Conventions used in all three files: UTF-8, 2-space indent, whitespace runs inside cell text collapsed to a single space (the only normalisation applied to `*_text` / `source_label` / `min_text`), originals kept verbatim next to every normalised value, nothing computed that the source text does not state.

## 1. Counts

### Airway bag

- Sections: 7; items (after variant expansion): 90; flagged `needs_review`: 8.
- Items per section: `airway-top` 5; `airway-left` 5; `airway-right` 5; `airway-inner` 6; `airway-main` 8; `airway-bvm-setup` 8; `airway-roll` 53.

### ALS med bag

- Sections: 5; items (after variant expansion): 106; flagged `needs_review`: 5.
- Items per section: `als-trauma` 7; `als-iv-supplies` 18; `als-medications` 31; `als-miscellaneous` 48; `als-rescue-only` 2.

### Captain 5 supply room

- Pages: 5. Table data rows: **295**, flagged `needs_review`: 56.
- Rows per page (parsed): p1: 73, p2: 72, p3: 74, p4: 69, p5: 7.
- Rows per section: `supplies` 102; `medications` 31; `supplies-after-unlabeled-band` 107; `stored-with-captain-5` 5; `stored-on-racks` 8; `accessories-grey-cabinet` 36; `narcotics-500-501` 6.
- Independent cross-check from the rendered images (count of horizontal black table rules in the ITEM column at 300 dpi, rows = gaps between rules, band rows included): p1 73, p2 74, p3 74, p4 75, p5 8 table body rows. Parsed data rows plus band rows: p1 73+0, p2 72+2 (MEDICATIONS band + empty red band), p3 74+0, p4 69+6 (STORED WITH CAPTAIN 5, empty yellow band, STORED ON RACKS, empty blue band, ACCESSORYS IN GREY CABINET, thin empty grey band under it), p5 7+1 (500/501 NARCOTICS). All five pages agree.
- Header/footer lines on every page (legend, 'Miami Beach Fire Rescue / EMS Inventory', print stamp, 'Page N of 5') are in `source`, not in `rows`.

## 2. Flagged / ambiguous items

### 2a. Bag forms (`needs_review: true`)

| File | item_ref | Raw source text | Min text | Why flagged |
|---|---|---|---|---|
| airway | `airway.bvm.bvm-with-adult-pedi-mask` | BVM w/ adult/pedi mask | 1 | Unclear whether MIN 1 means one BVM that includes both adult and pedi masks, or one of each; no '(each)' marker on the row. |
| airway | `airway.roll.mac-2` | Mac #2,3,4 | 1 | Row lists sizes 2,3,4 with min '1' and no '(each)'; unclear whether 1 total or 1 per size. |
| airway | `airway.roll.mac-3` | Mac #2,3,4 | 1 | Row lists sizes 2,3,4 with min '1' and no '(each)'; unclear whether 1 total or 1 per size. |
| airway | `airway.roll.mac-4` | Mac #2,3,4 | 1 | Row lists sizes 2,3,4 with min '1' and no '(each)'; unclear whether 1 total or 1 per size. |
| airway | `airway.roll.miller-0` | Miller #0,1,2,3, | 1(each) | Source size list ends with a trailing comma ('Miller #0,1,2,3,'); a further size (e.g. 4) may have been cut off. Only 0-3 are recorded. |
| airway | `airway.roll.miller-1` | Miller #0,1,2,3, | 1(each) | Source size list ends with a trailing comma ('Miller #0,1,2,3,'); a further size (e.g. 4) may have been cut off. Only 0-3 are recorded. |
| airway | `airway.roll.miller-2` | Miller #0,1,2,3, | 1(each) | Source size list ends with a trailing comma ('Miller #0,1,2,3,'); a further size (e.g. 4) may have been cut off. Only 0-3 are recorded. |
| airway | `airway.roll.miller-3` | Miller #0,1,2,3, | 1(each) | Source size list ends with a trailing comma ('Miller #0,1,2,3,'); a further size (e.g. 4) may have been cut off. Only 0-3 are recorded. |
| als | `als.misc.syringe-1-3-10-12-1cc` | SYRINGE 1cc / 3cc / 10 or 12cc | 3 | Row lists three syringe sizes with min '3' and no '(each)'; unclear whether 3 total or 3 per size. |
| als | `als.misc.syringe-1-3-10-12-3cc` | SYRINGE 1cc / 3cc / 10 or 12cc | 3 | Row lists three syringe sizes with min '3' and no '(each)'; unclear whether 3 total or 3 per size. |
| als | `als.misc.syringe-1-3-10-12-10-or-12cc` | SYRINGE 1cc / 3cc / 10 or 12cc | 3 | Row lists three syringe sizes with min '3' and no '(each)'; unclear whether 3 total or 3 per size. |
| als | `als.misc.surge-n95-surge` | SURGE/N95 | 2 EACH | 'SURGE' is not defined on the form (possibly a surgical/surge mask); recorded as printed. |
| als | `als.misc.surge-n95-n95` | SURGE/N95 | 2 EACH | 'SURGE' is not defined on the form (possibly a surgical/surge mask); recorded as printed. |

Not flagged but worth knowing (recorded in item `notes`):

- Airway `MAIN COMPARTMENT` and `BVM BAG SET UP` header rows each carry a stray `1` in the MIN cell. Confirmed in the PDF: black (0,0,0) glyph at x=237.7 on a black-filled cell (y=480.7 and y=622.2). Treated as headers; the `1` is recorded in the section `notes`, not as an item. `LEFT/RIGHT/INNER COMPARTMENT` MIN cells are black-filled with no glyph.
- Airway `Detail Airway Bags Only- (1) Manual Suction Device` is the yellow merged note row; stored as one item (qty 1, `detail_airway_bag_only: true`, `min_text` `(1)`).
- Airway `Igel Size 3,4,5` is the last row of the left column and therefore sits under `BVM BAG SET UP` by position only.
- Airway `AIRWAY ROLL` (red header, right column) is modelled as `kind: compartment` (it is a physical roll) rather than a category.
- ALS right-column rows `RACEMIC EPI` .. `NORMAL SALINE 1000CCC` are in `als-medications` (no header of their own; they follow the left MEDICATIONS block in reading order). `RACEMIC EPI` is on the same table row as the `TRAUMA` header (table row 2).
- ALS banner is clipped in the rendered image after `Bobo Bag`; the trailing period exists only in the text layer. The ALS note says `Bobo Bag`; the supply room lists `BOO-BOO Bag` (possible same item, not linked).
- `min_unit` stays `null` for `EACH`/`(each)` because that word qualifies variants (`min_applies_to: each_variant`), it is not a unit.
- Label-only typo corrections (source text untouched in `source_label`): Safety Glassess -> Safety Glasses; Meconium Aspiratpor -> Meconium Aspirator; Quik-trach -> Quick-Trach (airway adult row; the pedi row already says Quick); STETHESCOPE -> Stethoscope; STERIL WATER -> Sterile Water; MAGELL FORCEPS -> Magill Forceps; SOLU-MEDEROL -> Solu-Medrol; NORMAL SALINE 1000CCC -> Normal Saline 1000 cc. `MADDET`, `SURGE`, `Vomex`, `Band Aides`, `Capnoline` kept as printed.

### 2b. Supply room rows (`needs_review: true`)

Reason families (a row can have more than one): order level: 21; arithmetic: 9; qty-per-package text: 8; order-amount unit: 7; location (LOC) cell blank: 4; order-level unit: 4; order amount: 3; text layer holds two stacked strings in the order-level cell: 2; primary text contains: 1; secondary price-date text reads: 1; secondary and tertiary cells hold the same long text (: 1; order level unit: 1; item # cell is a contact note rather than a catalog number: 1.

| row_key | LOC | Item (verbatim) | ORDER LEVEL | QTY PER | ORDER AMOUNT | Reasons |
|---|---|---|---|---|---|---|
| `sr.p1.r05` | A2 | Ammonia Wipes | < 1CS | 30U/CS | 5 CS = 1500U | arithmetic: 5 x 30 = 150 but order amount states 1500U |
| `sr.p1.r15` | (blank) | Assure Prism Glucose Test Strips | < 5BX | 50U/BX | 20BX=1000U | location (LOC) cell blank |
| `sr.p1.r17` | A3 | 1" Tape- | < 20U | 12/BX | 12BX X 10CS = 120U | arithmetic: 12 x 12 = 144 but order amount states 120U (inner part '10case' not used) |
| `sr.p1.r18` | A3 | 1/2" Tape | < 20 | 288CS | 1CS = 288U | order level '< 20' has no unit / qty-per-package text '288CS' lacks the '/' separator used elsewhere; read as 288 per case |
| `sr.p1.r20` | A3 | Bandaids 1 x 3 (small) | < 5BX | 50/BX | 1CS X 60BX = 600U | arithmetic: 1 x 60 x 50 = 3000 but order amount states 600U |
| `sr.p1.r26` | A4 | 5 X 9 Abdominal Pads STERI PADS | < 10BX | 20/PK | 1CS X 20PK = 400U | order-level unit 'box' conflicts with qty-per-package unit 'pack' |
| `sr.p1.r30` | A5 | Cold Packs ICE PACKS | < 2BX | 24/CS | 6CS = 144U | order-level unit 'box' conflicts with qty-per-package unit 'case' |
| `sr.p1.r34` | A5 | 4" Ace Elastic Bandage | < 20U | 50/CS | 2CS X 10BX =200U | arithmetic: 2 x 50 = 100 but order amount states 200U (inner part '10box' not used) |
| `sr.p1.r35` | A5 | 6" Ace Elastic Bandage | < 20U | 50/CS | 2CS X 10BX =200U | arithmetic: 2 x 50 = 100 but order amount states 200U (inner part '10box' not used) |
| `sr.p1.r36` | A5 | Padded Patient Restraints | < 20U | 48CS | 1CS = 48U | qty-per-package text '48CS' lacks the '/' separator used elsewhere; read as 48 per case |
| `sr.p1.r51` | B4 | 1 cc syringes w/o needles | < 25U | 100BX | 1BX = 100U | qty-per-package text '100BX' lacks the '/' separator used elsewhere; read as 100 per box |
| `sr.p1.r67` | B5 | Mini-drip Solution Set (60 Drop) | < 50U | 50/CS | 2CS = 200U | arithmetic: 2 x 50 = 100 but order amount states 200U |
| `sr.p1.r72` | C2 | Disposable Razors | < 10PKs | 720/CS | 1CS = 720U | order-level unit 'pack' conflicts with qty-per-package unit 'case' |
| `sr.p2.r02` | C3 | SANIZIDE | < 8 | 6/CS | 4CS = 24U | order level '< 8' has no unit |
| `sr.p2.r04` | C3 | Peroxide | < 12U | 12/BX | 3CS = 36U | order-amount unit 'case' differs from qty-per-package unit 'box' |
| `sr.p2.r08` | C4 | LIFESTAR Nitrile Gloves (M) | < 20U | 10/CS | 5CS = 50U | text layer holds two stacked strings in the order-level cell ('< 20U' and '< 40U'); rendered image shows '< 20U'; the other string is occluded. Admin should confirm the intended order level |
| `sr.p2.r09` | C4 | LIFESTAR Nitrile Gloves (L) | BOXES | 10/CS | 5CS = 50U | order level 'BOXES' has no numeric threshold |
| `sr.p2.r11` | C5 | LIFESTAR Nitrile Gloves (XXL) | BOXES | 10/CS | 5CS = 50U | order level 'BOXES' has no numeric threshold |
| `sr.p2.r25` | E2 | LATEX GLOVES XXL BOXES | < 20U | 10/CS | 4CS = 40U | primary text contains '122..00CS' (doubled characters); likely 122.00CS (12.20BX x 10); raw text kept |
| `sr.p2.r26` | E3 | LATEX GLOVES L BOXES | BOXES | 10/CS | 4CS = 40U | text layer holds two stacked strings in the order-level cell ('BOXES' and '< 20U'); rendered image shows 'BOXES'; the other string is occluded. Admin should confirm the intended order level / order level 'BOXES' has no numeric threshold |
| `sr.p2.r27` | E3 | LATEX GLOVES XL BOXES | BOXES | 10/CS | 4CS = 40U | order level 'BOXES' has no numeric threshold |
| `sr.p2.r29` | E4 | LATEX GLOVES M BOXES | BOXES | 10/CS | 4CS = 40U | order level 'BOXES' has no numeric threshold |
| `sr.p2.r39` | VEND | Cardizem 50mg (REFRIGERATED PERFERRED ) | < 5 Units | 10/BX | 2PK = 20U | order-amount unit 'pack' differs from qty-per-package unit 'box' |
| `sr.p2.r40` | VEND | Chewable Aspirin 81 mg | < 5 Units | EA | 20U | secondary price-date text reads '7/222' (extra digit); likely '7/22'; raw text kept |
| `sr.p2.r45` | VEND | EZ-IO (Adult) | < 10 Units | 5/BX | 2BX =10U | secondary and tertiary cells hold the same long text ('TELLEFLEX SOLE SORCE (ARROW)') that overflows the cell and is visually clipped in the tertiary cell; text layer used; vendor spellings 'TELLEFLEX'/'SORCE' kept as printed |
| `sr.p2.r57` | VEND | Pedi Dextrose 25% (PREFILLED) | <10 Units | 10/BX | 3PK = 30U | order-amount unit 'pack' differs from qty-per-package unit 'box' |
| `sr.p2.r60` | VEND | Xylocaine 100mg (PREFILLED) LIDOCAINE | < 5 Units | 10/PK | 1BX = 10U | order-amount unit 'box' differs from qty-per-package unit 'pack' |
| `sr.p2.r69` | G2 | Smart Capnoline NC/O2- Adult | < 20U | 100/BX | 1CS = 100U | order-amount unit 'case' differs from qty-per-package unit 'box' |
| `sr.p2.r70` | G2 | Smart Capnoline NC/O2- Pedi | < 20U | 25/BX | 2CS = 50U | order-amount unit 'case' differs from qty-per-package unit 'box' |
| `sr.p2.r71` | G3 | LUCAS 3 CUPS | <3 | 3/BX | 1BX=12U | order level '<3' has no unit / arithmetic: 1 x 3 = 3 but order amount states 12U |
| `sr.p3.r06` | H1A | CURAPLEX Manual Suction Pump 500mmHG | < 1 | EA | 2U | order level '< 1' has no unit |
| `sr.p3.r07` | H1A | CURAPLEX Canister for Manual Suction 500mmHG | < 2 | EA | 2U | order level '< 2' has no unit |
| `sr.p3.r64` | H5B | Vinegar 64 oz or 1 Gallon | < 3U | 4/CS | 4CS = 24U | arithmetic: 4 x 4 = 16 but order amount states 24U |
| `sr.p3.r66` | I2A | Face Shields w/ masks SPLASH SHIELDS | < 25U | 25/BX | 1CS =100U | order-amount unit 'case' differs from qty-per-package unit 'box' |
| `sr.p3.r67` | I2A | N95 Respirator Masks | < 50U | 20/BX | 2CS X 12BXs = 240U | arithmetic: 2 x 12 x 20 = 480 but order amount states 240U |
| `sr.p4.r05` | I3A | Obstetrical Kits | < 4 Kits | EA | 4U | order level unit 'Kits' is not box/case/pack/bag/each (kept as unit_text) |
| `sr.p4.r06` | I4A | Barf Bags VOMEX CONVENIENCE BAGS | < 50 U | 250/CS | 2CS = 500U | item # cell is a contact note rather than a catalog number |
| `sr.p4.r12` | I5A | Red Bio Bags 23 x 23 (sml) | < 300 | 300/CS | 1CS = 300CS | order level '< 300' has no unit / order amount '1CS = 300CS' result is '300CS', not units (U) |
| `sr.p4.r13` | I5A | Red Bio Bags 55 gal | < 100 | 100/CS | 1CS = 100CS | order level '< 100' has no unit / order amount '1CS = 100CS' result is '100CS', not units (U) |
| `sr.p4.r16` | I2B | Autopulse Lifeband | < 3 (1 box) |  | 3CS = 9U | order level '< 3 (1 box)' has no unit class (parenthetical '(1 box)') |
| `sr.p4.r27` | Shelf | Tyvek Sleeves | < 10BX | 100/CS | 10CS = 1000U | order-level unit 'box' conflicts with qty-per-package unit 'case' |
| `sr.p4.r36` | O | LifePak 15 Auto BP Hose | <2 | EA | 2U | order level '<2' has no unit |
| `sr.p4.r60` | (blank) | ASSURE Glucometers Control ( Test ) Solution | As Needed | EA | As Needed | location (LOC) cell blank |
| `sr.p4.r61` | (blank) | ASSURE Glucometers | As Needed | EA | As Needed | location (LOC) cell blank |
| `sr.p4.r64` | (blank) | LSU SUCTION BATTERY | <1U | EA | 3U | location (LOC) cell blank |
| `sr.p4.r66` | J3 | AAA Batteries | < 2 bx/ 48 U | 24 | 48 U | qty-per-package text '24' has no unit |
| `sr.p4.r67` | A3 | AA Batteries | < 3 bx/ 72 U | 4 | 3 bx/ 72 U | qty-per-package text '4' has no unit |
| `sr.p4.r68` | A3 | C Batteries | < 12 u/ 1 Bx | 12 | 24 U/ 2 bx | qty-per-package text '12' has no unit |
| `sr.p4.r69` | A3 | D Batteries | < 12 U/ 1 Bx | 12 | 24 U/ 2 Bx | qty-per-package text '12' has no unit |
| `sr.p5.r01` | A3 | 9Volt Batteries | < 6 | 12 | 24 U/ 2 Bx | order level '< 6' has no unit / qty-per-package text '12' has no unit |
| `sr.p5.r02` | O | Lock, Drug Cynch | < 100 | 100 / bg | 400 | order level '< 100' has no unit / order amount '400' has no unit |
| `sr.p5.r03` | LOCK | Morphine Sulphate (Narcotics Locker) | < 20 |  | 50 Units | order level '< 20' has no unit |
| `sr.p5.r04` | LOCK | Ketamine (Narcotics Locker) | < 20 |  |  | order level '< 20' has no unit |
| `sr.p5.r05` | LOCK | Versed (Narcotics Locker) | < 20 |  | 50 Units | order level '< 20' has no unit |
| `sr.p5.r06` | LOCK | Fentanyl (Narcotics Locker) | < 20 |  | 50 Units | order level '< 20' has no unit |
| `sr.p5.r07` | LOCK | Lorazepam (CAPT 5 Refrigerator) ATIVAN | < 20 |  | 25 units | order level '< 20' has no unit |

Overlapping-text cells (resolved from the image, never guessed):

| Cell | Text layer (raw, interleaved) | What the text layer decomposes to | Rendered image shows | Recorded |
|---|---|---|---|---|
| p2 `LIFESTAR Nitrile Gloves (M)` ORDER LEVEL | `<< 2400UU` | two stacked strings `< 20U` and `< 40U` (0.8 pt apart, both fill-mode text) | `< 20U` | `< 20U`, flagged: the `< 40U` string is occluded in the render |
| p2 `LATEX GLOVES L BOXES` ORDER LEVEL | `B<O 2X0EUS` | two stacked strings `BOXES` and `< 20U` | `BOXES` | `BOXES`, flagged: the `< 20U` string is occluded in the render |

Five order-level cells read just `BOXES` (Nitrile L and XXL, Latex L, XL and M): no number, so `parsed.threshold` is `null` and the rows are flagged. The other odd strings in the brief are not overlap: the doubled characters in `122..00CS` (Latex XXL row) and the extra digit in `7/222` (Chewable Aspirin) are printed that way in the image.

Other judgement calls on the supply room:

- **Supplier legend**: the printed legend has exactly four entries (BoundTree BT, Cardinal Health Supplies CHS, Cardinal Health Pharmaceuticals CHP, Midwest Medical Supply MMS). **Henry Schein / `HS` is NOT in the printed legend** although `HS` appears in 133 supplier cells. The brief said the legend lists Henry Schein; the rendered legend (400 dpi crop) does not. `source.supplier_legend` holds only the four printed entries; `HS` and the other cell-only vendors (STRYKER, TELEFLEX/TELLEFLEX, ZOLL, VOMEX, Grainger, EMARKET/E-MARKET, FISHER SFTY.) are listed in `source.undeclared_supplier_codes_in_cells`. MMS never appears in a cell; CHS appears once (Lock, Drug Cynch).
- **Print stamp**: `10/23/2026 7:29 PM` (top right of every page) is the print/export time, not a revision date; `revised_label` is `11/2021` (page 1 only).
- **Sections**: the form has no header above the first block; labelled bands are `MEDICATIONS` (p2), `STORED WITH CAPTAIN 5`, `STORED ON RACKS`, `ACCESSORYS IN GREY CABINET` [sic] (p4), `500/501 NARCOTICS` (p5). An unlabeled empty red band after the Medications block (p2, between Xylocaine and Suction Catheters French #6) starts `supplies-after-unlabeled-band`. Empty yellow/blue/grey bands after labelled blocks are not sections. The `9Volt Batteries` row at the top of p5 continues `accessories-grey-cabinet`.
- **`bag_alias_text`** is taken from text colour: the trailing red or blue UPPERCASE words in the ITEM cell (e.g. `STERI PADS`, `LASIX`, `N / C`). Parenthetical notes such as `(PREFILLED)`, `(REFRIGERATED PERFERRED )`, colour tags such as `(BLUE)`/`GREEN`, mixed-case red annotations (`BVMs for Adults and Child`, `BVM for Infants and Neonates`, `(Small/ Pedi / Infant)`) and the teal/green device tags (`AED ONLY`, `LIFEPACK ONLY`, `LIFEPACK & AED`) stay in `item_text`. `item_cell_text` always holds the whole ITEM cell verbatim. `BUTTERFLIES/Scalp` is the one mixed-case alias accepted.
- **`DUEL COMBI PADS`**: `DU` and `EL` are different colours (two text runs); joined without a space. Spelling `DUEL` is as printed.
- **Merged supplier cell**: on the five battery rows (AAA, AA, C, D, 9Volt) `EMARKET` is centred across a cell merged over PRIMARY:TERTIARY; recorded in `primary_text` with an extraction note.
- **EZ-IO (Adult)**: SECONDARY and TERTIARY hold the same overflowing text `TELLEFLEX SOLE SORCE (ARROW)`; the tertiary copy is clipped in the render (`LLEFLEX SOLE SORCE (ARRO`). Recorded from the text layer, flagged.
- **Barf Bags** ITEM # cell is a vendor contact note (a name and a phone number) rather than a catalog number; kept verbatim, `item_numbers` is empty, flagged.
- **Blank LOC**: p1 `Assure Prism Glucose Test Strips`; p4 `ASSURE Glucometers Control ( Test ) Solution`, `ASSURE Glucometers`, `LSU SUCTION BATTERY`.
- **Order-level units**: `U`/`Units`/`u` -> `each` (base unit); `BX/box/BOXES` -> `box`, `CS` -> `case`, `PK/Pks` -> `pack`, `Kits` and `(1 box)` leave `unit` null (raw in `unit_text`). Compound levels keep the explicit second value in `threshold.alt` (e.g. `< 2 bx/ 48 U`).
- **Unit / arithmetic flags are checks only**: a row is flagged when ORDER LEVEL unit, QTY PER unit and ORDER AMOUNT unit disagree (e.g. `< 2BX` with `24/CS`), or when the amount arithmetic implied by the row's own printed numbers disagrees with the printed total (e.g. Ammonia Wipes `30U/CS` with `5 CS = 1500U`; N95 `20/BX` with `2CS X 12BXs = 240U`). The check result is never stored as parsed data.
- **Irregular QTY PER text**: `288CS`, `100BX`, `48CS` (no slash) read as N per that unit and flagged. `1/CS=40Pks` -> case with `inner_packages {count 40, unit pack}`. Bare numbers (`24`, `4`, `12`) -> `base_units_per_package` with unit null, flagged.
- **Order-amount results in non-unit terms**: `1CS = 300CS`, `1CS = 100CS` (Red Bio Bags) flagged; `5CS = 100PKs` (AMBU electrodes) kept in `result_unit_text`, not flagged (pack threshold, consistent).
- **Flags**: `refrigerated` true for `Cardizem 50mg (REFRIGERATED PERFERRED )` (source says *preferred*) and `Lorazepam (CAPT 5 Refrigerator)`. `controlled_substance` true for the five drug rows under `500/501 NARCOTICS` (Morphine, Ketamine, Versed, Fentanyl, Lorazepam); the section heading is the only evidence, no schedule is printed. `Lock, Drug Cynch` in that section is not a drug (false). `has_expiration` is `null` everywhere: the form contains no expiration notes.
- Prices, price dates and supplier ranks are recorded as printed (`price_text`, `price_date_text`) and are historical/unverified. `price_text` is the first price-looking token; the full cell is always in `raw`.

## 3. Variant-expansion decisions

| Form | Source row (verbatim) | Min text | Expanded to | min_applies_to | Reason |
|---|---|---|---|---|---|
| airway | Igel Size 3,4,5 | 1 (each) | Size 3, Size 4, Size 5 | `each_variant` | Combined row 'Igel Size 3,4,5 \| 1 (each)' expanded to one item per size; min applies to each size. |
| airway | Suction cath 6Fr-18Fr | 1(each) | 6 Fr, 8 Fr, 10 Fr, 12 Fr, 14 Fr, 16 Fr, 18 Fr | `each_variant` | Range '6Fr-18Fr' expanded to 6/8/10/12/14/16/18 Fr because the supply-room form lists exactly those seven sizes (G1 'Suction Catheters French #6..#18') as separate rows. |
| airway | Mac #2,3,4 | 1 | Mac #2, Mac #3, Mac #4 | `unclear` | Expanded per listed size; min_applies_to unclear because the row has no '(each)' marker (compare 'Miller #0,1,2,3, 1(each)'). REVIEW: Row lists sizes 2,3,4 with min '1' and no '(each)'; unclear whether 1 total or 1 per size. |
| airway | Miller #0,1,2,3, | 1(each) | Miller #0, Miller #1, Miller #2, Miller #3 | `each_variant` | Trailing comma preserved in source_label/variant_group. Supply room stocks Miller 0-4. REVIEW: Source size list ends with a trailing comma ('Miller #0,1,2,3,'); a further size (e.g. 4) may have been cut off. Only 0-3 are recorded. |
| airway | Magill forceps(adult/pedi) | 1(each) | Adult, Pedi | `each_variant` |  |
| airway | Tube holder(adult/pedi) | 1(each) | Adult, Pedi | `each_variant` |  |
| als | IV 14G/16G | 2 EACH | 14G, 16G | `each_variant` | Combined row expanded to IV 14G and IV 16G; '2 EACH' applies to each gauge. |
| als | IV 18G/20G/22G/24G | 4 EACH | 18G, 20G, 22G, 24G | `each_variant` | Combined row expanded to four gauges; '4 EACH' applies to each gauge. |
| als | MINI DRIP / MAXI DRIP | 2 EACH | Mini Drip, Maxi Drip | `each_variant` | Combined row expanded; '2 EACH' applies to each set type. |
| als | ZOFRAN | 2 IV / 6 ORAL | IV, ORAL | `item` | Cell '2 IV / 6 ORAL' expanded: IV minimum 2 (oral minimum 6 is the separate Zofran Oral item). min_text keeps the full cell text. |
| als | NEEDLES 19G/21G/23G/25G | 2 EACH | 19G, 21G, 23G, 25G | `each_variant` | Combined row expanded to four gauges; '2 EACH' applies to each gauge. |
| als | TAPE 1/2" - 1" - 3" | 1 EACH | 1/2", 1", 3" | `each_variant` | Combined row expanded to 1/2", 1" and 3" tape; '1 EACH' applies to each width. |
| als | ACE BANDAGE 4"/6" | 4 EACH | 4", 6" | `each_variant` | Combined row expanded to 4" and 6"; '4 EACH' applies to each size. |
| als | KLING 6"/4"/2" | 4 EACH | 6", 4", 2" | `each_variant` | Combined row expanded to 6", 4" and 2"; '4 EACH' applies to each size. |
| als | 2X2 / 4X4 / 5X9 STERI PADS | 5 EACH | 2x2, 4x4, 5x9 | `each_variant` | Combined row expanded to 2x2, 4x4 and 5x9; '5 EACH' applies to each size. |
| als | SYRINGE 1cc / 3cc / 10 or 12cc | 3 | 1cc, 3cc, 10 or 12cc | `unclear` | '10 or 12cc' kept as one variant (either size). REVIEW: Row lists three syringe sizes with min '3' and no '(each)'; unclear whether 3 total or 3 per size. |
| als | SURGE/N95 | 2 EACH | SURGE, N95 | `each_variant` | Combined row expanded to SURGE and N95; '2 EACH' applies to each. REVIEW: 'SURGE' is not defined on the form (possibly a surgical/surge mask); recorded as printed. |

Rows deliberately **not** expanded: size ranges that are part of a product name (`Air-Trach blade 2.5-3.5` etc.), separate source rows that merely share a stem (`DEXTROSE 25%` / `50%`, `SODIUM BICARB 4.2%` / `8.4%`, `ARM BOARD 9"` / `18"`, `BAND AIDES SMALL` / `LARGE`, `EPI 1:10,000` / `1:1000`, `ET tube 2.5..9.0`, `NPA`, `OPA`), and `BVM w/ adult/pedi mask` (no `(each)`, flagged).

`Suction cath 6Fr-18Fr 1(each)` was expanded to 6/8/10/12/14/16/18 Fr because the supply-room form (G1, page 2) lists exactly those seven sizes as separate rows `Suction Catheters French #6/#8/#10/#12/#14/#16/#18`.

## 4. Cross-form catalog links asserted

Shared `catalog_ref` values between files. Confidence **high** = same product name (spelling/plural/size-qualifier differences only) or exact size match; **medium** = wording differs materially but a printed alias or an exact size/gauge series supports the link. Medium links deserve a quick admin look.

| catalog_ref | Airway bag | ALS bag | Supply room | Confidence |
|---|---|---|---|---|
| `ace-bandage-4in` |  | ACE BANDAGE 4"/6" | 4" Ace Elastic Bandage | high |
| `ace-bandage-6in` |  | ACE BANDAGE 4"/6" | 6" Ace Elastic Bandage | high |
| `adenocard-12mg` |  | ADENOCARD 12 MG | Adenocard 12 mg (PREFILLED) | high |
| `adult-quick-trach` | Adult Quik-trach |  | Adult Quick Trach | high |
| `alcohol-prep` |  | ALCOHOL PREPS | Alcohol prep wipes | high |
| `amiodarone` |  | AMIODARONE | Amiodarone 150 mg (PREFILLED) | high |
| `arm-board-18in` |  | ARM BOARD 18" | Armboard 18" | high |
| `arm-board-9in` |  | ARM BOARD 9" | Armboard 9" | high |
| `ars-decomp-14ga-needle` |  | ARS Decomp 14ga Needle | IV Catheters 14 ga. (3.25") DECOMPRESS | medium |
| `asherman-chest-seal` |  | ASHERMAN CHEST SEAL | Asherman Chest Seals CHEST SEALS | high |
| `aspirin` |  | ASPIRIN | Chewable Aspirin 81 mg | high |
| `atropine` |  | ATROPINE | Atropine 1 mg (PREFILLED) | high |
| `band-aid-large` |  | BAND AIDES LARGE | Bandaids 4 1/2 x 2 (large) | high |
| `band-aid-small` |  | BAND AIDES SMALL | Bandaids 1 x 3 (small) | high |
| `benadryl` |  | BENADRYL | Benadryl 50 mg | high |
| `betadine-prep` |  | BETADINE PREPS | Betadine prep wipes | high |
| `bite-stick` | Bite stick | BITE STICK | Bite Sticks | high |
| `bougie-10fr` | Bougie 10Fr |  | Bougie Introducer 10Fr | high |
| `bougie-15fr` | Bougie 15Fr |  | Bougie Introducer 15Fr | high |
| `bp-cuff-adult` | BP CUFF(ADULT) | BP CUFF (ADULT) |  | high |
| `burn-dressing-4x4` |  | BURN DRESSING 4X4 | Burn Gel 4" x 4" Dressing BURN DRESSING | high |
| `burn-gel-face-mask` |  | Burn Gel Face Mask | Burn Gel Face Masks | high |
| `burn-gel-small-packet` |  | BURN GEL SMALL PACKET | Burn Gel 3.5 g Dose Packets SML BURN GEL | medium |
| `calcium-chloride` |  | CALCIUM CHLORIDE | Calcium Chloride 10% (PREFILLED) | high |
| `cardene` |  | CARDENE | Nicardipine 25mg CARDENE | high |
| `cardizem` |  | CARDIZEM (REFRIGERATED) | Cardizem 50mg (REFRIGERATED PERFERRED ) | high |
| `cat-tourniquet` |  | CAT TOURNIQUET | Combat Application Tourniquet (CAT) | medium |
| `cpap` | CPAP |  | CPAP (new) | medium |
| `d5w-50cc` |  | D5W 50 CC BAG | D5w 50 cc 5% D extrose | high |
| `dextrose-25` |  | DEXTROSE 25% | Pedi Dextrose 25% (PREFILLED) | high |
| `dextrose-50` |  | DEXTROSE 50% | Adult Dextrose 50% D/50 (PREFILLED) | high |
| `dopamine` |  | DOPAMINE | Dopamine 400/250 (PREMIXED PERFERRED) | high |
| `epi-1-1000` |  | EPI 1:1000 | Epinephrine 1: 1000 | high |
| `epi-1-10000` |  | EPI 1:10,000 | Epinephrine 1:10,000 (PREFILLED) | high |
| `et-madd-device` | ET tube MADD Device |  | Endotracheal Atomization Device / ET MADD (MADgic) | medium |
| `etomidate` |  | ETOMIDATE | Amidate 40mg ETOMIDATE | high |
| `eye-cover` |  | EYE COVER | Eye Covers | high |
| `ez-set-21ga-butterfly` |  | EZ SET 21GA (Butterfly) | EZ Set IV 21 ga. BUTTERFLIES/Scalp | medium |
| `glucagon` |  | GLUCAGON | Glucagon 1 mg | high |
| `glucometer` |  | GLUCOMETER | ASSURE Glucometers | medium |
| `glucose-gel` |  | GLUCOSE GEL | Glucose Gel (oral) 15GM GLUTOSE | high |
| `ice-pack` |  | ICE PACKS | Cold Packs ICE PACKS | medium |
| `igel-3` | Igel Size 3,4,5 |  | I-gel Size 3 (Resus Pack) | medium |
| `igel-4` | Igel Size 3,4,5 |  | I-gel Size 4 (Resus Pack) | medium |
| `igel-5` | Igel Size 3,4,5 |  | I-gel Size 5 (Resus Pack) | medium |
| `iv-catheter-16g` |  | IV 14G/16G | IV Cath Insyte Autoguard BC 16 ga. 1.16 inch | medium |
| `iv-catheter-18g` |  | IV 18G/20G/22G/24G | IV Cath Insyte Autoguard BC 18 ga.1.16 inch W/ WINGS | medium |
| `iv-catheter-20g` |  | IV 18G/20G/22G/24G | IV Cath Insyte Autoguard BC 20 ga.1.16 inch W/ WINGS | medium |
| `iv-catheter-22g` |  | IV 18G/20G/22G/24G | IV Cath Insyte Autoguard BC 22 ga.1.00 inch W/ WINGS | medium |
| `iv-catheter-24g` |  | IV 18G/20G/22G/24G | IV Cath Insyte Autoguard BC 24 ga. .75 inch W/ WINGS | medium |
| `kling-2in` |  | KLING 6"/4"/2" | 2" Kling- Stretch Gauze Bandage | high |
| `kling-4in` |  | KLING 6"/4"/2" | 4" Kling- Stretch Gauze Bandage | high |
| `kling-6in` |  | KLING 6"/4"/2" | 6" Kling- Stretch Gauze Bandage | high |
| `lancets` |  | LANCETS | Lancettes | medium |
| `lasix` |  | LASIX | Furosemide 40 mg (PREFILLED) LASIX | high |
| `lidocaine` |  | LIDOCAINE | Xylocaine 100mg (PREFILLED) LIDOCAINE | high |
| `mag-sulfate` |  | MAG SULFATE | Magnesium Sulfate 50% (PREFILLED) | high |
| `magill-forceps-adult` | Magill forceps(adult/pedi) |  | Forceps Magill Adult | high |
| `magill-forceps-pedi` | Magill forceps(adult/pedi) |  | Forceps Magill Pedi | high |
| `maxi-drip-set` |  | MINI DRIP / MAXI DRIP | Maxi-drip Solution Set (10 Drop) | medium |
| `meconium-aspirator` | Meconium Aspiratpor |  | Meconium Aspirator, Clear | high |
| `mini-drip-set` |  | MINI DRIP / MAXI DRIP | Mini-drip Solution Set (60 Drop) | medium |
| `multi-trauma-dressing` |  | MULTI TRAUMA DRESSING | 12 X 30 Multi-Trauma Dressing | medium |
| `n95-respirator` |  | SURGE/N95 | N95 Respirator Masks | medium |
| `narcan` |  | NARCAN | Naloxone 2mg (PREFILLED) NARCAN | high |
| `nasal-cannula-adult` | Nasal cannula adult |  | Nasal Cannula (Adult) (Curaplex) N / C | medium |
| `nasal-cannula-pedi` | Nasal cannula pedi |  | Nasal Cannula (Pedi) (Curaplex) PEDI N/C | medium |
| `nasal-maddet` |  | NASAL MADDET | Nasal Drug Delivery Device MADDET | medium |
| `nebulizer-mask-adult` | Nebulizer adult |  | Nebulizer Mask (Adult) NEBU | medium |
| `nebulizer-mask-pedi` | Nebulizer pedi |  | Nebulizer Mask (Pedi) PEDI NEBU | medium |
| `needle-19g` |  | NEEDLES 19G/21G/23G/25G | Syringe Needle 19 ga. | medium |
| `needle-21g` |  | NEEDLES 19G/21G/23G/25G | Syringe Needle 21 ga. | medium |
| `needle-23g` |  | NEEDLES 19G/21G/23G/25G | Syringe Needle 23ga. | medium |
| `needle-25g` |  | NEEDLES 19G/21G/23G/25G | Syringe Needle 25ga. | medium |
| `non-rebreather-infant` | Non-rebreather infant |  | Non-rebreather mask (Infant) INF NRB | medium |
| `non-rebreather-pedi` | Non-rebreather pedi |  | Non-rebreather mask (Pedi) PEDI NRB | medium |
| `normal-saline-1000` |  | NORMAL SALINE 1000CCC | Normal Saline 1000 ml | high |
| `npa-12` | NPA #12 |  | NPA #12 French | high |
| `npa-14` | NPA #14 |  | NPA #14 French | high |
| `npa-16` | NPA #16 |  | NPA #16 French | high |
| `npa-18` | NPA #18 |  | NPA #18 French | high |
| `npa-20` | NPA #20 |  | NPA #20 French | high |
| `npa-22` | NPA #22 |  | NPA #22 French | high |
| `npa-24` | NPA #24 |  | NPA #24 French | high |
| `npa-26` | NPA #26 |  | NPA #26French | high |
| `npa-28` | NPA #28 |  | NPA #28 French | high |
| `npa-30` | NPA #30 |  | NPA #30 French | high |
| `opa-100mm` | OPA #100mm |  | OPA 100 mm | high |
| `opa-110mm` | OPA #110mm |  | OPA 110 mm | high |
| `opa-40mm` | OPA #40mm |  | OPA 40mm | high |
| `opa-50mm` | OPA #50mm |  | OPA 50mm | high |
| `opa-60mm` | OPA #60mm |  | OPA 60 mm | high |
| `opa-80mm` | OPA #80mm |  | OPA 80 mm | high |
| `opa-90mm` | OPA #90mm |  | OPA 90 mm | high |
| `pedi-quick-trach` | Pedi Quick-trach |  | Pedi Quick Trach | high |
| `pen-light` |  | PEN LIGHT | Pen Lights | high |
| `peroxide` |  | PEROXIDE | Peroxide | high |
| `quikclot-gauze` |  | QUICK CLOT GAUZE | Quikclot Rolled Gauze 3" X 48" | medium |
| `racemic-epi` |  | RACEMIC EPI | Racemic Epinepherine | high |
| `ring-cutter` |  | RING CUTTER | Ring Cutters | high |
| `safety-glasses` | Safety Glassess |  | Safety Glasses | high |
| `sharps-shuttle` |  | SHARPS SHUTTLE | Sharps Shuttle #1368 | high |
| `sodium-bicarb-4-2` |  | SODIUM BICARB 4.2% | Infant Bicarb 4.2% (PREFILLED) | high |
| `sodium-bicarb-8-4` |  | SODIUM BICARB 8.4% | Adult Bicarb 8.4% (PREFILLED) | high |
| `steri-pad-2x2` |  | 2X2 / 4X4 / 5X9 STERI PADS | 2 X 2 Gauze Sponges STERI PADS | medium |
| `steri-pad-4x4` |  | 2X2 / 4X4 / 5X9 STERI PADS | 4 X 4 Gauze Sponges (Curaplex) STERI PADS | medium |
| `steri-pad-5x9` |  | 2X2 / 4X4 / 5X9 STERI PADS | 5 X 9 Abdominal Pads STERI PADS | medium |
| `sterile-water` |  | STERIL WATER | 500 ml Irrigation Water STERILE WATER | medium |
| `stethoscope` | STETHOSCOPE | STETHESCOPE | Stethoscope | high |
| `suction-catheter-10fr` | Suction cath 6Fr-18Fr |  | Suction Catheters French #10 | medium |
| `suction-catheter-12fr` | Suction cath 6Fr-18Fr |  | Suction Catheters French #12 | medium |
| `suction-catheter-14fr` | Suction cath 6Fr-18Fr |  | Suction Catheters French #14 | medium |
| `suction-catheter-16fr` | Suction cath 6Fr-18Fr |  | Suction Catheters French #16 | medium |
| `suction-catheter-18fr` | Suction cath 6Fr-18Fr |  | Suction Catheters French #18 | medium |
| `suction-catheter-6fr` | Suction cath 6Fr-18Fr |  | Suction Catheters French #6 | medium |
| `suction-catheter-8fr` | Suction cath 6Fr-18Fr |  | Suction Catheters French #8 | medium |
| `syringe-10-12cc` |  | SYRINGE 1cc / 3cc / 10 or 12cc | 10-12 cc syringes w/o needles | medium |
| `syringe-1cc` |  | SYRINGE 1cc / 3cc / 10 or 12cc | 1 cc syringes w/o needles | medium |
| `syringe-3cc` |  | SYRINGE 1cc / 3cc / 10 or 12cc | 3 cc syringes w/o needles | medium |
| `syringe-60cc` |  | SYRINGE 60 CC | 60 cc syringes w/o needles | medium |
| `t-nebulizer-adult` | T-nebulizer adult |  | T-adapter nebulizer (Adult) T-NEBU | medium |
| `tape-1-2in` |  | TAPE 1/2" - 1" - 3" | 1/2" Tape | high |
| `tape-1in` |  | TAPE 1/2" - 1" - 3" | 1" Tape- | high |
| `tape-3in` |  | TAPE 1/2" - 1" - 3" | 3" Tape- PREFER DUKAL- CLOTH TAPE | high |
| `test-strips` |  | TEST STRIPS | Assure Prism Glucose Test Strips | medium |
| `tetracaine-drops` |  | TETRACAINE DROPS | Tetracaine Eyedrops 0.5% 15mL | high |
| `three-way-stopcock` |  | 3-WAY STOPCOCK | 3-way stopcock | high |
| `trauma-shears` |  | TRAUMA SHEARS | Trauma Shears | high |
| `triangle-bandage` |  | TRIANGLE BANDAGE | Triangular Bandage | high |
| `veniguard` |  | VENIGUARD | Veni-guard dressing | high |
| `vomex-bag` |  | VOMEX BAG | Barf Bags VOMEX CONVENIENCE BAGS | medium |

131 shared keys. Every other `catalog_ref` is unique to one file (file-specific suffixes such as `-airway-bag` / `-als-bag` are used where the two bags have similarly named but unconfirmed items, e.g. gloves).

## 5. Unconfirmed links for admin review

Possible matches that were **not** asserted (distinct `catalog_ref`s were given).

| Bag-form item | Possible supply-room (or other) match | Why not asserted |
|---|---|---|
| Airway `ET tube 2.5 (cuffed)` .. `9.0 (cuffed)` (14 sizes) | Supply `E.T. Flexi-set 2.5 mm` .. `9.0 mm` (H3B) | Same size series, but 'Flexi-set' vs 'ET tube (cuffed)' is not proven the same product |
| Airway `Mac #2,3,4`, `Miller #0,1,2,3,` | Supply `Disposable Mac 4..0`, `Disposable Miller 4..0` (H4B) | Bag form does not say disposable; supply room also stocks Mac 0/1 and Miller 4 |
| Airway `Non-rebreather adult` | Supply `Non- Rebreather Elongated -Adult` (G4) | 'Elongated' qualifier and no alias; the pedi/infant rows do have aliases and were linked |
| Airway `Nasal Capnoline adult/pedi`, `BVM Capnoline` | Supply `Smart Capnoline NC/O2- Adult/Pedi` (G2) | Brand family matches; item identity (nasal vs BVM) not proven |
| Airway `Tracheostomy mask set` | Supply `Tracheostomy Mask` (H1A) | 'mask set' vs 'mask' |
| Airway `ET tube extension`, `Filter` | Supply `BVM Tube Extention Adaptors`, `ET Tube Filters` (H1A) | Names only partly similar |
| Airway `BVM w/ adult/pedi mask` | Supply `BVM (Pediatric)`, `BVM (Infant)`, `Masks for BVM` (H4A) | No adult BVM row; mask/BVM relationship unclear |
| Airway `ET lube` | Supply `Sterile Lubricating Jelly` (H1A) | Name differs |
| Airway `Tube holder(adult/pedi)` | Supply `E.T. Holder (adult)/(pedi)` alias ENDOLOCK (I5A/I4A) | 'Tube holder' vs 'E.T. Holder'; probably the same, not proven |
| Airway `10cc syringe` | Supply `10-12 cc syringes w/o needles` (linked to ALS `10 or 12cc`) or `10 ml saline flush syringes` | Airway form does not say with/without needle or flush |
| Airway `GLOVES 3 SETS`, ALS `GLOVES 2 PAIR` | Supply LIFESTAR Nitrile (S..XXL) and LATEX GLOVES (S..XXL) | Size/material not stated on bag forms; 'sets' vs 'pair' |
| Airway `SLEEVES 3 SETS` | Supply `Tyvek Sleeves` (Shelf) | Type not stated |
| Airway `RED BAGS (Small)`, ALS `RED BAG` | Supply `Red Bio Bags 23 x 23 (sml)` / `55 gal`; also `G3 ALS Soft Red Bag` carry bags | ALS 'RED BAG' could be a biohazard bag or an equipment bag |
| Airway and ALS `BP CUFF(ADULT)` (linked to each other) | Supply `BP AN (ADULT)` (aneroid) or `Auto BP Cuff (regular adult)` | Two candidates; manual vs automatic not stated |
| Airway `D cylinder w/ Regulator` | Supply `Oxygen Regulator` (O) | Cylinder vs regulator |
| ALS `BURN DRESSING 4X16` | Supply `Burn Gel 4" x 16" Dressing` alias LG BURN GEL | Alias wording differs ('LG BURN GEL' vs 'BURN DRESSING 4X16'); likely the same |
| ALS `IV 14G` | Supply `IV Catheters 14 ga. (1.75")` (B3); the other 14 ga. row (3.25", DECOMPRESS) was linked to `ARS Decomp 14ga Needle` | Two 14 ga. supply rows |
| ALS `IV TOURNIQUET` | Supply `Latex-free Tourniquet` (B3) | Name differs |
| ALS `10 CC FLUSH` | Supply `10 ml saline flush syringes` (B4) | cc vs ml wording |
| ALS `EXTENSION SET (J LOOP)` | Supply `6" IV Extension Set` (B5) | J-loop not stated on supply row |
| ALS `AMMONIA INHALANTS 1 BOX` | Supply `Ammonia Wipes` (A2) | Inhalants vs wipes likely different products |
| ALS `DUONEB` | Supply `Ipratropium Bromide` alias ALBUTEROL (VEND) | Alias says ALBUTEROL, not DUONEB |
| ALS `NITRO SPRAY` | Supply `Nitroglycerin, 0.4mg, Sublingual Tablets` (VEND) | Spray vs tablet; no spray on the supply list |
| ALS `SOLU-MEDEROL`, `ZOFRAN` (IV / ORAL) | (no supply-room row found) | No match |
| ALS `FOIL RESCUE BLANKET` | Supply `Metal Rescue Blanket (84"X56")` (D2) | Foil vs metal wording |
| ALS `MAGELL FORCEPS` | Supply `Forceps Magill Adult` / `Pedi` (H2B) | No adult/pedi size on the ALS row |
| ALS `SAFETY GOGGLES` | Supply `Safety Glasses` (I2A) | Goggles vs glasses |
| ALS `SURGE` (of `SURGE/N95`) | (no supply-room row found) | 'SURGE' undefined |
| ALS `THERMOMETER STRIP` | Supply `Forehead Thermometers` (A2) | Strip vs forehead thermometer |
| ALS note `Bobo Bag` | Supply `BOO-BOO Bag` (O, accessories) | Spelling differs; note is a form note, not an item |
| ALS `NARCS BOX Y / N` | Supply `500/501 NARCOTICS` block (`Lock, Drug Cynch`, narcotics locker drugs) | Different concepts (box present vs stock lines) |

## 6. Verification method

Interpreter: `C:\Users\Peter Darley\AppData\Local\Programs\Python\Python313\python.exe` (3.13.1), every script run as `python -I -X utf8`. Libraries: pdfplumber (words with positions and colours, rects), PyMuPDF (renders, text trace).

1. **Text layer**: pdfplumber words clustered into table rows (2.6 pt tolerance) and assigned to columns by x position (LOC <46, ITEM 46-190, ORDER LEVEL 190-221, check 221-236, QTY 236-270, PRIMARY 270-393, SECONDARY 393-509, TERTIARY 509-584, ITEM # 584-684, ORDER AMOUNT 684+ pt). Word gaps near zero (colour-split words) joined.
2. **Overlap scan**: pairwise glyph bounding-box overlap per page; hits only on p2 (two cells). Each overlapped cell was decomposed into its two strings and compared with the render.
3. **Ink scan**: every text-layer word on all five pages (1453/1689/1307/1145/137 words) was tested for visible ink in a 300 dpi render; the only low-ink words were pale-green text on white (`(CHP)`, `LP`, `LIFEPACK`, `ONLY`) which are visible in the image. No hidden or clipped words other than the two occluded strings above.
4. **Row count**: table-rule count from the 300 dpi renders compared with parsed rows (section 1).
5. **Images read** (Read tool on PNGs): airway page at 150 dpi; ALS page at 150 dpi; all five supply-room pages at 130 dpi; supply-room 175 dpi crops, left/right x top/bottom, of pages 1-4 (16 crops: item/order/qty/primary on the left, secondary/tertiary/item #/order amount on the right) compared line by line with the extracted rows; page 5 crop at 200 dpi; the supplier legend and title/print-stamp crops at 400 dpi; the p4 section-band crop (220 dpi); p2 Nitrile/Latex glove rows (400 dpi) and their ORDER LEVEL cells (1200 dpi) for the overlap decisions.
6. **Not done**: no OCR; price digits, dates and item numbers were proofread against the crops by eye at the 175 dpi level, not re-typed independently. Cell fill colours were not extracted. The text layer is trusted wherever the render shows the same text.

Row numbering: bag forms `source_ref.row` = table row with the yellow title banner as row 1 and the header row as row 2. Supply-room `row` = 1-based data row on the page, band rows excluded; `row_key` = `sr.p{page}.r{nn}`.


## 7. ALS Transport Vehicle Weekly Inventory (added 2026-10-30)

Source: a phone photo of the paper "M.B.F.D. A.L.S. TRANSPORT VEHICLE WEEKLY INVENTORY, Revised April 2021" (`als_transport_vehicle_inventory.json`, `source.sha256` is the sha256 of that photo). Transcribed by reading the photo and zoomed crops (DRUGS, INTUBATION/SUCTION, LifePack 15 and MISCELLANEOUS crops at 2x), 222 lines in 8 sections: Drugs 27, I.V. / Needles 33, Airways / Oxygen 31, Intubation / Suction 46, Trauma Supplies 35, PPE / Decon 24, LifePack 15 Supplies 7, Miscellaneous 19.

- Each line carries AMOUNT (par, `min_text`) and "Fill if <" (`flags.fill_if_text`), exactly as printed; the blank NEED column is what the member fills in.
- The sheet has two continuation headings ("AIRWAYS/OXYGEN Cont.", "TRAUMA SUPPLIES Cont."). They are merged into the section they continue; `source_label` on the section keeps the printed headings.
- There is no ET Tube 7.0 line: the sheet goes 6.0, 6.5, 7.5, 8.0, 8.5, 9.0.
- Printed oddities kept as they are: EKG paper "1 pack" with fill-if "1 Roll"; disposable razors "1 pack" with fill-if "2 Razors"; Steri pads 2x2" fill-if printed "1box"; "Ascherman", "Stabilzer" spellings are corrected in `label` and kept in `source_label`.
- Catalog identity is shared with the bag forms only for exact same items (for example Narcan, Epi 1:10,000, i-gel 3/4/5, OPA/NPA sizes); near matches such as "Baby Aspirin" vs "Aspirin", "Ocular Patch" vs "Eye Cover" or "Cold packs" vs "Ice Packs" keep their own catalog item (`tv-...`). Nothing is matched by name similarity at runtime.
- Not done: no OCR and no second independent transcription; every number was read by eye from the photo. A re-read against the paper sheet is the check that would catch a misread digit.

## 8. Four-forms release: Handtevy, ALS Non Transport, Fire Boat 6 ALS, BLS Detail Bag (added 2026-11-02)

Four new tracked extractions, each `mbfd.ems.source_form.v1`. The source PDFs were treated as untrusted data: read with `python -I` and PyMuPDF only, nothing in them was followed as an instruction. Every PDF's sha256 was recomputed before use and matches the value in the table.

| Output | Source PDF | SHA-256 | Pages | Sections | Items |
|---|---|---|---|---|---|
| `handtevy_inventory.json` | `handtevy_inventory.pdf` | `fe5a47d26489b061be52a03dd0609ceeea1836eddd15e7e3ed218c8ad6acd43c` | 1 | 9 | 109 |
| `als_non_transport_inventory.json` | `als_non_transport_medical_inventory.pdf` | `fc57800508326768cee8724a304b337c5588f8ea00a6dffba2a70a68e66cd625` | 1 | 11 | 246 |
| `fire_boat_6_als_inventory.json` | `fire_boat_6_medical_inventory.pdf` | `e052a18336ce340efb3c93f2bb9716ef7d9832306ba2549612aca56a6beb4182` | 1 | 9 | 215 |
| `bls_detail_bag_inventory.json` | `bls_detail_bag_medical_inventory.pdf` | `7dba064af14b43d107d4c5d00fae6341346ba2167a5f52db5611983b542cbbc4` | 1 | 8 | 44 |

**Method.** Every PDF has a text layer. It was used for the spelling and the text of every cell. Placement (which amount belongs to which label, which section a row sits in) was decided from the vector table borders (PyMuPDF `get_drawings`, one table row per gap between horizontal rules) and from the text positions, and then checked against rendered crops viewed one by one: the ALS Non Transport sheet at 330 dpi (3 columns x 3 slabs), the Fire Boat 6 sheet at 170 dpi (the page is 1176.9 x 1523.1 points, about 1.9 times letter size; 3 columns x 3 slabs), the Handtevy sheet at 260 dpi (4 crops) and the BLS sheet from the 150 dpi page render plus the amount cells read by position. Rows with no name but with numbers were looked for explicitly. No OCR and no second independent transcription; the row counts below come from the table borders and agree with the parsed rows.

**Unchanged lists.** The 2026-11-02 attachments `ALS_Med_bag_inventory_new.pdf` (sha256 `65eab0e2828191cdcc6bc09aa868f3c4c96a7128db6d02c2c84d5592a407a4f6`) and `als_airway_bag_medical_inventory.pdf` (sha256 `017c463cae414ff142ed426a9527f0eba8d37ba01f99023cdede6a14d065e06f`) are byte-identical to the already imported sources of `als_med_bag_inventory.json` and `airway_bag_inventory.json`. Those two lists are unchanged and `ems:import-sources` reports them as `unchanged`.

**Conventions for the four lists.** Item identities (`item_ref`) are `ht.`, `nt.`, `fb6.` and `bls.` followed by a lowercase section slug and a label slug; they are unique within the file and become permanent draft and request keys, so they are never reused or renumbered. `source_label` is the printed cell text with whitespace runs collapsed to one space (misspellings and capital letters kept; where the printed cell also carries a location such as `(1) PLACED IN BVM BAG` or `-IN HANDTEVY-` the whole cell is the `source_label` and the location goes to `flags.location_text`). `label` fixes only an obvious spelling of the same product (listed per sheet). A blank AMOUNT stays blank, never 0. Nothing printed as a controlled, refrigerated, rescue-only or detail-airway-bag-only item exists on these sheets, so those four flags are false everywhere. `catalog_ref` is reused from the three older lists only when the item is the same product, the same dose or concentration and the same size (an identical printed label, an obvious spelling or plural or abbreviation difference, a printed parenthetical qualifier, or the same measured size such as ET tube 4.5 cuffed, OPA 60mm, NPA 20F, IV catheter 18 gauge, i-gel 2.5); everything else has its own specific key. Nothing is matched by name similarity.

### 8a. Handtevy (`handtevy_inventory.json`, key `handtevy`)

Source: `handtevy_inventory.pdf`, one page, no title, date or revision printed (the title is the file's name, `version_label` is "undated" as on the airway bag list). Two side-by-side item blocks: the left half is Top Insert, Bottom Bin, < 1 Y/O (Grey / Pink / Red) and 1 year (Purple); the right half is 2 year (Yellow), 3 & 4 years (White), 5 & 6 year (Blue), 7 & 8 year (Orange) and 9 - 13 year (Green). Items follow that reading order.

Printed rows and items per section (a row that lists several sizes is one printed row and one item per printed size):

| Section key | Printed heading | Printed rows | Items |
|---|---|---|---|
| `ht-top-insert` | Top Insert | 12 | 15 |
| `ht-bottom-bin` | Bottom Bin | 10 | 13 |
| `ht-under-1` | < 1 Y/O Grey Pink Red | 9 | 13 |
| `ht-1-year` | 1 year (Purple) | 7 | 10 |
| `ht-2-year` | 2 year (Yellow) | 7 | 11 |
| `ht-3-4-years` | 3 & 4 years (White) | 7 | 11 |
| `ht-5-6-years` | 5 & 6 year (Blue) | 7 | 12 |
| `ht-7-8-years` | 7 & 8 year (Orange) | 7 | 11 |
| `ht-9-13-years` | 9 - 13 year (Green) | 7 | 13 |
| **Total** | | **73** | **109** |

Cross-check from the table borders: each half has 42 table rows. Left half = 4 headings + 38 item rows (12 + 10 + 9 + 7). Right half = 5 headings + 35 item rows (7 x 5) + 1 empty row + the black legend row. 73 printed item rows, 109 items.

How the tricky cells were read:

- The Amount and Need columns are empty. The only printed quantity rule is the black legend at the bottom right, "Quantity 1 unless indicated in ( )" (recorded in the source notes, not as an item). `min_text` is the number in ( ) for a size that has one, otherwise "1". `min_applies_to` is `item`.
- `Needles - 21Ga 1"(2), 25Ga 1" (1 each)`: two items. 21Ga 1" is 2 (the `(2)` right after it). 25Ga 1" is 1 (the `(1 each)` that closes the row; read as applying to the 25Ga size because 21Ga already has its own number). The other reading, "1 each" overriding the 21Ga `(2)`, is not supported by the print.
- `Syringes- 1cc(2), 3cc(2), 10cc(2)`: three items, 2 each. `IV Catheter- 22Ga(2 each), 24Ga(2 each)` (< 1 Y/O and 1 year): two items, 2 each. `IV Catheter - 18Ga, 20Ga, 22Ga(2 each)` (2 year, 3 & 4 years, 5 & 6 year): three items, 2 each, because "each" covers the whole list. `IV Catheter - 18Ga, 20Ga(2 each)` (7 & 8 year, 9 - 13 year): two items, 2 each.
- `ET Tube - 6.5mm, 7.0mm Cuffed(1 each)` and `Syringes - 1cc, 3cc, 10cc(1 each)` (9 - 13 year): one item per size, 1 each.
- Multi-size rows with no quantity (`Syringes - 1cc, 3cc, 10cc` in every other age block, `French Catheter- 6F,8F`, `French Catheter - 10F, 12F`, `Blade - 2 Straight & Curved`, `Blade - 3 Straight & Curved`) are one item per printed size or type with the rule's default 1. This is a reading, not a printed number: the "(1 each)" printed on the 9 - 13 year multi-size rows supports 1 per size but a person should confirm it (see `docs/ems/source-review-2026-11.md`). The item notes say so.
- `iGels   1.0 / 1.5 / 2.0 / 2.5`: four items (variant group `igel`, 1 each). In the PDF text layer this row is the last text on the page; in the picture it is the last row of the Bottom Bin color band, above the < 1 Y/O heading, and that is where it is placed.
- `Tape- 1"(1roll)` is `1 roll` (unit `roll`); `Trauma Sleevs(3 sets)` is `3 sets` (unit `sets`; label corrected to "Trauma sleeves", "Sleevs" stays in the source label and a search term); `IV Start Kit(located in each pouch)` is 1 with `location_text` "located in each pouch".
- `Meconium Aspirator/ MAD Device` and `Infant & Child BP Cuff` name two things with one quantity; each stays one item (item notes say so).
- < 1 Y/O block: the rows are printed across the Grey, Pink and Red color columns (the text is centered over all three; the backgrounds differ per row). The rows are kept as printed, every one of the 13 items has `color_band` "Grey / Pink / Red (< 1 year)" and `needs_review` true with the reason "does not say which size belongs to which color". No size was assigned to a color.
- `color_band` on the age blocks is the printed color and age group, for example "Yellow (2 years)", "Green (9 - 13 years)". Top Insert and Bottom Bin print no color name.

Flagged `needs_review` (13): all items of `ht-under-1` (reason above).

Rows with numbers but no label: none. Empty table rows: one (right half, between the 9 - 13 year block and the legend).

Catalog: 39 keys reused from the older lists, 22 new. Reused (this sheet's printed row <- key):

```
bite-stick <- Bite Stick   [older list: Bite Stick]
pen-light <- Penlight   [older list: Pen Lights]
trauma-shears <- Trauma Shears   [older list: TRAUMA SHEARS]
syringe-1cc <- Syringes- 1cc(2), 3cc(2), 10cc(2) | Syringes- 1cc, 3cc, 10cc | Syringes - 1cc, 3cc, 10cc | Syringes - 1cc, 3cc, 10cc(1 each)   [older list: Syringe 1cc]
syringe-3cc <- Syringes- 1cc(2), 3cc(2), 10cc(2) | Syringes- 1cc, 3cc, 10cc | Syringes - 1cc, 3cc, 10cc | Syringes - 1cc, 3cc, 10cc(1 each)   [older list: Syringe 3cc]
tv-syringe-10-cc <- Syringes- 1cc(2), 3cc(2), 10cc(2) | Syringes- 1cc, 3cc, 10cc | Syringes - 1cc, 3cc, 10cc | Syringes - 1cc, 3cc, 10cc(1 each)   [older list: Syringe 10cc]
tape-1in <- Tape- 1"(1roll)   [older list: 1" tape]
three-way-stopcock <- Three-way stopcock(2)   [older list: 3 way stopcock]
arm-board-9in <- Arm Board 9"(1)   [older list: Armboards 9"]
tube-holder-pedi <- Pedi Tube Holder   [older list: Tube-lock / Pedi]
safety-glasses <- Safety Glasses(2)   [older list: Safety Glasses (Clear)]
stethoscope <- Stethoscope   [older list: STETHOSCOPE]
tv-i-gel-size-1-0 <- iGels 1.0 / 1.5 / 2.0 / 2.5   [older list: i-gel 1.0]
tv-i-gel-size-1-5 <- iGels 1.0 / 1.5 / 2.0 / 2.5   [older list: i-gel 1.5]
tv-i-gel-size-2-0 <- iGels 1.0 / 1.5 / 2.0 / 2.5   [older list: i-gel 2.0]
tv-i-gel-size-2-5 <- iGels 1.0 / 1.5 / 2.0 / 2.5   [older list: i-gel 2.5]
et-tube-2-5-cuffed <- ET Tube- 2.5mm Cuffed   [older list: ET tube 2.5 (cuffed)]
et-tube-3-0-cuffed <- ET Tube- 3.0mm Cuffed   [older list: ET tube 3.0 (cuffed)]
et-tube-3-5-cuffed <- ET Tube- 3.5mm Cuffed   [older list: ET tube 3.5 (cuffed)]
iv-catheter-22g <- IV Catheter- 22Ga(2 each), 24Ga(2 each) | IV Catheter - 18Ga, 20Ga, 22Ga(2 each)   [older list: IV cath 22ga]
iv-catheter-24g <- IV Catheter- 22Ga(2 each), 24Ga(2 each)   [older list: IV cath 24ga]
opa-50mm <- OPA- 50mm   [older list: OPA #1 50mm]
npa-14 <- NPA- 14F   [older list: NPA #14 FR]
et-tube-4-0-cuffed <- ET Tube- 4.0mm Cuffed   [older list: ET tube 4.0 (cuffed)]
opa-60mm <- OPA - 60mm   [older list: OPA #2 60mm]
npa-18 <- NPA - 18F   [older list: NPA #18 FR]
et-tube-4-5-cuffed <- ET TUBE - 4.5mm Cuffed   [older list: ET tube 4.5 (cuffed)]
iv-catheter-18g <- IV Catheter - 18Ga, 20Ga, 22Ga(2 each) | IV Catheter - 18Ga, 20Ga(2 each)   [older list: IV cath 18ga]
iv-catheter-20g <- IV Catheter - 18Ga, 20Ga, 22Ga(2 each) | IV Catheter - 18Ga, 20Ga(2 each)   [older list: IV cath 20ga]
npa-20 <- NPA - 20F   [older list: NPA #20 FR]
et-tube-5-0-cuffed <- ET TUBE - 5.0mm Cuffed   [older list: ET tube 5.0 (cuffed)]
npa-22 <- NPA - 22F   [older list: NPA #22 FR]
et-tube-5-5-cuffed <- ET Tube - 5.5mm Cuffed   [older list: ET tube 5.5 (cuffed)]
npa-24 <- NPA - 24F   [older list: NPA #24 FR]
et-tube-6-0-cuffed <- ET Tube - 6.0mm Cuffed   [older list: ET tube 6.0 (cuffed)]
opa-80mm <- OPA - 80mm   [older list: OPA #3 80mm]
npa-26 <- NPA - 26F   [older list: NPA #26 FR]
et-tube-6-5-cuffed <- ET Tube - 6.5mm, 7.0mm Cuffed(1 each)   [older list: ET tube 6.5 (cuffed)]
et-tube-7-0-cuffed <- ET Tube - 6.5mm, 7.0mm Cuffed(1 each)   [older list: ET tube 7.0 (cuffed)]
```

New keys: `ace-bandage-3in`, `kling-3in`, `laryngoscope-handle`, `meconium-aspirator-mad-device`, `needle-21ga-1in`, `needle-25ga-1in`, `iv-start-kit`, `bulb-syringe`, `bvm-infant-small-child`, `bp-cuff-infant-child`, `pediatric-capnoline-nasal-cannula`, `trauma-sleeves`, `blade-1-straight`, `french-catheter-6f`, `french-catheter-8f`, `french-catheter-10f`, `blade-2-straight`, `blade-2-curved`, `opa-70mm`, `blade-3-straight`, `blade-3-curved`, `french-catheter-12f`.

Not matched on purpose: `Needles 21Ga 1"` and `25Ga 1"` (the bag lists print the gauge without a length), `Blade - N Straight/Curved` (not the MAC / MILLER blades of the other lists), `French Catheter` (not the suction catheters), `Infant / Small Child Size BVM` (not `BVM Infant`), `Trauma Sleevs` (the airway bag's `SLEEVES` prints no "trauma").

### 8b. ALS Non Transport (`als_non_transport_inventory.json`, key `als-non-transport`)

Source: `als_non_transport_medical_inventory.pdf`, one page, printed header `Date: / / M.B.F.D. A.L.S. NON-TRANSPORT UNIT WEEKLY INVENTORY Unit# Revised 1-2024`. Three printed columns, each ITEM / AMOUNT / Fill if < / NEED; read left column, middle column, right column, top to bottom.

| Section key | Printed heading | Printed rows | Items |
|---|---|---|---|
| `nt-handtevy` | (none printed) | 2 | 2 |
| `nt-medications` | Medications | 28 | 28 |
| `nt-iv-needles` | I.V./NEEDLES | 33 | 33 |
| `nt-airway-left` | AIRWAY | 17 | 17 |
| `nt-airway-middle` | AIRWAY | 12 | 12 |
| `nt-airway-bvm-bag` | AIRWAY / BVM Bag | 52 | 52 |
| `nt-trauma-supplies` | TRAUMA SUPPLIES (continued as TRAUMA SUPPLIES CONT.) | 34 | 34 |
| `nt-ppe-decon` | PPE - DECON | 29 | 30 |
| `nt-ekg-supplies` | EKG SUPPLIES | 5 | 5 |
| `nt-miscellaneous` | MISCELLANEOUS | 12 | 12 |
| `nt-life-pack-inventory` | LIFE PACK INVENTORY | 17 | 21 |
| **Total** | | **241** | **246** |

Cross-check from the table borders (rows per column, including headings and empty rows): left 87 = 3 headings + 80 rows with a name + 2 nameless numbered + 2 empty; middle 88 = 3 headings + 1 italic note + 83 rows + 1 empty; right 88 = 5 headings + 78 rows + 5 empty. 241 named rows (headings, the note and the empty rows excluded). The 246 items are those 241 rows plus 5 variant expansions: 1 extra for "Manual B/P Cuffs Adult/ Pedi" (2 items) and 4 extra for "Auto BP Cuffs XL, L, REG,, PEDI, INFANT" (5 items).

How the tricky cells were read:

- The first two rows of the left column, above the "Medications" heading, have no heading of their own: they are section `nt-handtevy`. `HANDTEVY` (AMOUNT 1, Fill if 1) is a quantity item, no catalog item. `HANDTEVY 1st Tuesday-Monthly Inventory-Inspection completed` is one table cell wrapped over two text lines with no numbers: a `yes_no` item with `flags.cadence = monthly_first_tuesday`.
- Two headings are both printed `AIRWAY` (bottom of the left column with OPA and NPA, top of the middle column with the masks); kept as two sections `nt-airway-left` and `nt-airway-middle`. `TRAUMA SUPPLIES CONT.` (top of the right column) is merged into `TRAUMA SUPPLIES` with the printed headings in the section's `source_label`, as the Cont. headings are on the transport vehicle list.
- The italic row `BVMs come with Reg Adult & Child Masks / PEEP Valve / Filter / Tubing` under the first row of `AIRWAY / BVM Bag` is the printed note of that section (`section.note`) and is repeated in the item note of the `CURAPLEX BVMs W/ Manometer` row. It is not an item.
- Five yellow rows `ET Tube extention(1) PLACED IN BVM BAG`, `BVM CO Capnoline (1) PLACED IN BVM BAG`, `i-gel 5/4/3 (1) PLACED IN BVM BAG`: AMOUNT is blank and `1 in bag` is printed under `Fill if <`. Recorded as printed (`min_text` null, `fill_if_text` "1 in bag", `fill_if_qty` 1, `location_text` "Placed in BVM bag"), flagged `needs_review`.
- Rows marked `-IN HANDTEVY-` (i-gel 2.5, 2.0, 1.5, 1.0, CURAPLEX infant BVM, Pedi Manual BP Cuff, Infant Manual BP Cuff) have `location_text` "In Handtevy"; AMOUNT and Fill if are printed normally.
- Differences from the transport vehicle sheet are kept exactly: Tyvek sleeves 24 / 12, red biohazard bags (LG) and (Sm) 5 / 3, CURAPLEX BVMs 2 / 1, `2 IN BOX` / 2 for the adult EZ-IO and `1 IN BOX` / 1 for the other two, Steri and Surgi pads `5 each` / `5 each`, EKG paper `2 rolls` / `2 roll`, disposable razors `2 pack` / `2 razors` (unit pack against razors, as on the transport sheet), Quick-trach `1 in bag` / 1 (the "in bag" is in the AMOUNT cell), shoe covers `3 pair`, disposable straps `2 pair` / `1 pair`, `1 box` rows, `1 each` rows, `2 bags`, `1 bag`. Latex gloves are printed XXL, XL, Large, medium, small and there are four purple nitrile glove sizes (XL, LG, MED, SM); Tyvek suits are printed 2XL and XL only.
- `Manual B/P Cuffs   Adult/ Pedi` (`1 each`) is one item for Adult and one for Pedi; `Auto BP Cuffs XL, L, REG,, PEDI, INFANT` (`1 each`; printed with a double comma after REG) is five items (XL, L, REG, PEDI, INFANT). `Syringe 10/12cc` stays one item (a 10cc or a 12cc).
- `Stethescope` sits in the PPE - DECON block (by position, between the BP cuff row and EKG SUPPLIES); kept there.
- Repeated words in different places are separate items: `Disposable razors` (EKG SUPPLIES, 2 pack / 2 razors) and `Disposable Razors` (LIFE PACK INVENTORY, 2 / 1); `EKG electrodes` and `EKG Electrodes`; `Ammonia inhalants / wipes` (MISCELLANEOUS) and `Ammonia inhalants` (LIFE PACK); `Stethescope` and `Stethoscope`.
- Printed qualifiers moved to flags: `( MCI BAG)` on the CAT tourniquet (`qualifier_text` "MCI BAG"), `(single use)` on the two SPO2 sensors, `-sensor-Connected to LP` and `-sensor-Rear Pouch of LP` as `location_text`. Obvious spelling fixed in `label` only: Adenocard 12 mg (spacing), Solu-Medrol, Asherman chest seals, Stethoscope, ET tube extension, Gluco lancets, Glucometer w/ lancets & strips, EZ set 21ga (Butterfly). Not changed: Nasal MADDET, Speedy Cleans, CURAPLEX, TPOD, Carry alls.

**Rows with numbers but no label (2, not items):** left column, between `22GA 1.5" ADMIN NEEDLES` (table row 54) and `Saline flush 10cc` (table row 57): table row 55 prints AMOUNT 2 / Fill if 2, table row 56 prints AMOUNT 2 / Fill if 2, both with an empty ITEM cell (PDF text layer: AMOUNT at x 133.0 and Fill if at x 151.6, y 460.3 and y 467.4 points). Not imported; listed in `source.review_notes` and in `docs/ems/source-review-2026-11.md`.

Flagged `needs_review` (5): `nt.bvm-bag.et-tube-extension`, `nt.bvm-bag.bvm-co-capnoline`, `nt.bvm-bag.i-gel-5`, `nt.bvm-bag.i-gel-4`, `nt.bvm-bag.i-gel-3`. Reason for all five: AMOUNT is blank and "1 in bag" is printed under Fill if <.

Catalog: 193 keys reused from the older lists, 43 new. Reused (this sheet's printed row <- key):

```
adenocard-12mg <- Adenocard 12mg   [older list: Adenocard 12mg]
amiodarone <- Amiodarone   [older list: Amiodarone]
atropine <- Atropine   [older list: Atropine]
tv-baby-aspirin <- Baby Aspirin   [older list: Baby Aspirin]
benadryl <- Benadryl   [older list: Benadryl]
sodium-bicarb-8-4 <- Bicarb 8.4%   [older list: Bicarb 8.4%]
sodium-bicarb-4-2 <- Bicarb 4.2%   [older list: Bicarb 4.2%]
calcium-chloride <- Calcium chloride   [older list: Calcium chloride]
cardene <- Cardene   [older list: Cardene]
dextrose-50 <- Dextrose 50%   [older list: Dextrose 50%]
dextrose-25 <- Dextrose 25%   [older list: Dextrose 25%]
dopamine <- Dopamine drip   [older list: Dopamine drip]
duoneb-als-bag <- Duoneb   [older list: Duoneb]
epi-1-10000 <- Epi 1:10,000   [older list: Epi 1:10,000]
epi-1-1000 <- Epi 1:1,000   [older list: Epi 1:1,000]
etomidate <- Etomidate   [older list: Etomidate]
glucose-gel <- Glucose oral gel   [older list: Glucose oral gel]
glucagon <- Glucagon   [older list: Glucagon]
lasix <- Lasix   [older list: Lasix]
lidocaine <- Lidocaine   [older list: Lidocaine]
mag-sulfate <- Magnesium sulf.   [older list: Magnesium sulf.]
narcan <- Narcan   [older list: Narcan]
nitro-spray-als-bag <- Nitro Spray   [older list: Nitromist Spray]
racemic-epi <- Racemic Epi   [older list: Racemic Epi]
solu-medrol <- Solu-Mederol   [older list: SOLU-MEDEROL]
tetracaine-drops <- Tetracaine drops   [older list: Tetracaine drops]
zofran-oral <- Zofran Oral   [older list: ZOFRAN]
zofran-iv <- Zofran IV   [older list: ZOFRAN]
test-strips <- Glucometer strips   [older list: Glucometer strips]
lancets <- Gluco lancettes   [older list: Gluco lancettes]
mini-drip-set <- Mini drips   [older list: Mini drips]
maxi-drip-set <- Maxi drips   [older list: Maxi drips]
tv-extension-sets <- Extension sets   [older list: Extension sets]
three-way-stopcock <- 3 way stopcock   [older list: 3 way stopcock]
veniguard <- Veniguards   [older list: Veniguards]
tv-adult-ez-io-blue <- Adult EZ-IO (BLUE)   [older list: Adult EZ-IO Blue]
tv-pedi-ez-io-pink <- Pedi EZ-IO (PINK)   [older list: Pedi EZ-IO Pink]
tv-large-adult-ez-io-yellow <- Lg Adult EZ-IO (YELLOW)   [older list: Lg Adult EZ-IO Yellow]
iv-catheter-24g <- IV cath 24ga   [older list: IV cath 24ga]
iv-catheter-22g <- IV cath 22ga   [older list: IV cath 22ga]
iv-catheter-20g <- IV cath 20ga   [older list: IV cath 20ga]
iv-catheter-18g <- IV cath 18ga   [older list: IV cath 18ga]
iv-catheter-16g <- IV cath 16ga   [older list: IV cath 16ga]
iv-catheter-14g <- IV cath 14ga   [older list: IV cath 14ga]
ez-set-21ga-butterfly <- EZ set 21ga ( Butterfly)   [older list: EZ set 21ga]
flush-10cc-als-bag <- Saline flush 10cc   [older list: Saline flush 10cc]
syringe-1cc <- Syringe 1cc   [older list: Syringe 1cc]
syringe-3cc <- Syringe 3cc   [older list: Syringe 3cc]
syringe-10-12cc <- Syringe 10/12cc   [older list: SYRINGE 1cc / 3cc / 10 or 12cc]
syringe-60cc <- Syringe 60cc   [older list: Syringe 60cc]
arm-board-9in <- Armboards 9"   [older list: Armboards 9"]
arm-board-18in <- Armboards 18"   [older list: Armboards 18"]
betadine-prep <- Betadine preps   [older list: Betadine preps]
alcohol-prep <- Alcohol preps   [older list: Alcohol preps]
normal-saline-1000 <- N. Saline 1000cc   [older list: N. Saline 1000cc]
d5w-50cc <- D5w 50cc   [older list: D5w 50cc]
nasal-maddet <- Nasal MADDET   [older list: NASAL MADDET]
opa-40mm <- OPA #0 40mm   [older list: OPA #0 40mm]
opa-50mm <- OPA #1 50mm   [older list: OPA #1 50mm]
opa-60mm <- OPA #2 60mm   [older list: OPA #2 60mm]
opa-80mm <- OPA #3 80mm   [older list: OPA #3 80mm]
opa-90mm <- OPA #4 90mm   [older list: OPA #4 90mm]
opa-100mm <- OPA #5 100mm   [older list: OPA #5 100mm]
opa-110mm <- OPA #6 110mm   [older list: OPA #6 110mm]
npa-30 <- NPA #30 FR   [older list: NPA #30 FR]
npa-28 <- NPA #28 FR   [older list: NPA #28 FR]
npa-26 <- NPA #26 FR   [older list: NPA #26 FR]
npa-24 <- NPA #24 FR   [older list: NPA #24 FR]
npa-22 <- NPA #22 FR   [older list: NPA #22 FR]
npa-20 <- NPA #20 FR   [older list: NPA #20 FR]
npa-18 <- NPA #18 FR   [older list: NPA #18 FR]
npa-16 <- NPA #16 FR   [older list: NPA #16 FR]
npa-14 <- NPA #14 FR   [older list: NPA #14 FR]
npa-12 <- NPA #12 FR   [older list: NPA #12 FR]
bite-stick <- Bite sticks   [older list: Bite Stick]
non-rebreather-mask-adult <- Non-rebreather/adult   [older list: Non-rebreather Adult]
nasal-cannula-adult <- Nasal cannula adult   [older list: Nasal cannula Adult]
nebulizer-mask-adult <- Nebulizer adult   [older list: Nebulizer Adult]
t-nebulizer-adult <- T-nebulizer adult   [older list: T-nebulizer Adult]
nasal-capnoline-adult <- Nasal Capnoline adult   [older list: Nasal Prong Capnoline Adult]
non-rebreather-pedi <- Non-rebreather/pedi   [older list: Non-rebreather/Pedi]
nasal-cannula-pedi <- Nasal cannula pedi   [older list: Nasal cannula Pedi]
nebulizer-mask-pedi <- Nebulizer pedi   [older list: Nebulizer Pedi]
nasal-capnoline-pedi <- Nasal Capnoline pedi   [older list: Nasal Prong Capnoline Pedi]
non-rebreather-infant <- Non-rebreather/infant   [older list: Non-rebreather/Infant]
tv-cpap-tube-mask-set <- CPAP tube/mask set   [older list: CPAP tube/mask set]
et-tube-extension <- ET Tube extention(1) PLACED IN BVM BAG   [older list: ET tube extension]
igel-5 <- i-gel 5 (1) PLACED IN BVM BAG   [older list: i-gel 5]
igel-4 <- i-gel 4 (1) PLACED IN BVM BAG   [older list: i-gel 4]
igel-3 <- i-gel 3 (1) PLACED IN BVM BAG   [older list: i-gel 3]
tv-i-gel-size-2-5 <- i-gel 2.5 -IN HANDTEVY-   [older list: i-gel 2.5]
tv-i-gel-size-2-0 <- i-gel 2.0 -IN HANDTEVY-   [older list: i-gel 2.0]
tv-i-gel-size-1-5 <- i-gel 1.5 -IN HANDTEVY-   [older list: i-gel 1.5]
tv-i-gel-size-1-0 <- i-gel 1.0 -IN HANDTEVY-   [older list: i-gel 1.0]
et-tube-2-5-cuffed <- ET tube 2.5 (cuffed)   [older list: ET tube 2.5 (cuffed)]
et-tube-3-0-cuffed <- ET tube 3.0 (cuffed)   [older list: ET tube 3.0 (cuffed)]
et-tube-3-5-cuffed <- ET tube 3.5 (cuffed)   [older list: ET tube 3.5 (cuffed)]
et-tube-4-0-cuffed <- ET tube 4.0 (cuffed)   [older list: ET tube 4.0 (cuffed)]
et-tube-4-5-cuffed <- ET tube 4.5 (cuffed)   [older list: ET tube 4.5 (cuffed)]
et-tube-5-0-cuffed <- ET tube 5.0 (cuffed)   [older list: ET tube 5.0 (cuffed)]
et-tube-5-5-cuffed <- ET tube 5.5 (cuffed)   [older list: ET tube 5.5 (cuffed)]
et-tube-6-0-cuffed <- ET tube 6.0 (cuffed)   [older list: ET tube 6.0 (cuffed)]
et-tube-6-5-cuffed <- ET tube 6.5 (cuffed)   [older list: ET tube 6.5 (cuffed)]
et-tube-7-0-cuffed <- ET tube 7.0 (cuffed)   [older list: ET tube 7.0 (cuffed)]
et-tube-7-5-cuffed <- ET tube 7.5 (cuffed)   [older list: ET tube 7.5 (cuffed)]
et-tube-8-0-cuffed <- ET tube 8.0 (cuffed)   [older list: ET tube 8.0 (cuffed)]
et-tube-8-5-cuffed <- ET tube 8.5 (cuffed)   [older list: ET tube 8.5 (cuffed)]
et-tube-9-0-cuffed <- ET tube 9.0 (cuffed)   [older list: ET tube 9.0 (cuffed)]
bougie-10fr <- Bougie 10Fr   [older list: Pedi Bougie 10FR]
bougie-15fr <- Bougie 15Fr   [older list: Adult Bougie 15FR]
tube-holder-adult <- Tube-lock / adult   [older list: Tube-lock / Adult]
tube-holder-pedi <- Tube-lock / pedi   [older list: Tube-lock / Pedi]
suction-catheter-6fr <- Suction cath 6Fr   [older list: Suction cath 6Fr]
suction-catheter-8fr <- Suction cath 8Fr   [older list: Suction cath 8Fr]
suction-catheter-10fr <- Suction cath 10Fr   [older list: Suction cath 10Fr]
suction-catheter-12fr <- Suction cath 12Fr   [older list: Suction cath 12Fr]
suction-catheter-14fr <- Suction cath 14Fr   [older list: Suction cath 14Fr]
suction-catheter-16fr <- Suction cath 16Fr   [older list: Suction cath 6Fr-18Fr]
suction-catheter-18fr <- Suction cath 18Fr   [older list: Suction cath 18Fr]
tv-suction-yankauer <- Suction yankauer   [older list: Suction yankauer]
meconium-aspirator <- Meconium aspirators   [older list: Meconium aspirators]
tv-portable-suction-canister <- Portable suction canister   [older list: Portable suction canister]
tv-portable-suction-tubing <- Portable suction tubing   [older list: Portable suction tubing]
tv-lubricating-gel <- Lubricating gel   [older list: Lubricating gel]
mac-blade-4 <- MAC #4   [older list: MAC #4]
mac-blade-3 <- MAC #3   [older list: MAC #3]
mac-blade-2 <- MAC #2   [older list: MAC #2]
miller-blade-3 <- MILLER #3   [older list: MILLER #3]
miller-blade-2 <- MILLER #2   [older list: MILLER #2]
miller-blade-1 <- MILLER #1   [older list: MILLER #1]
miller-blade-0 <- MILLER #0   [older list: MILLER #0]
adult-quick-trach <- Quick-trach / adult   [older list: Quick-trach / Adult]
pedi-quick-trach <- Quick-trach / pedi   [older list: Quick-trach / Pedi]
et-madd-device <- ET Tube MADD Device   [older list: ET MADD]
tracheostomy-mask-set <- Tracheostomy Mask Set   [older list: Tracheostomy mask set]
tv-500-ml-irrigation-water <- 500 ml irrigation water   [older list: 500 ml irrigation water]
tv-c-collar-adult-no-neck <- C-collar/adult-no-neck   [older list: C-collar/adult no-neck]
tv-c-collar-adjustable-pedi <- C-collar-adjustable pedi   [older list: C-collar-adjustable pedi]
tv-cardboard-splints-short <- Cardboard splints short   [older list: Cardboard splints short]
tv-cardboard-splints-long <- Cardboard splints long   [older list: Cardboard splints long]
tv-burn-sheets <- Burn sheets   [older list: Burn sheets]
burn-gel-face-mask <- Burn Gel Face Mask   [older list: Burn Gel Face Mask]
tv-burn-gel-4-x-4-dressing <- Burn Gel 4x4 Dressing   [older list: Burn Gel 4x4 Dressing]
burn-gel-small-packet <- Burn Gel 3.5g Packets   [older list: Burn Gel 3.5g Packets]
tape-1-2in <- 1/2" tape   [older list: 1/2" tape]
tape-1in <- 1" tape   [older list: 1" tape]
tape-3in <- 3" tape   [older list: 3" tape]
ace-bandage-4in <- 4" ace bandage   [older list: 4" ace bandage]
ace-bandage-6in <- 6" ace bandage   [older list: 6" ace bandage]
tv-t-bandages <- T-bandages   [older list: T-bandages]
cat-tourniquet <- Combat App. Tourniquet CAT ( MCI BAG)   [older list: Combat App. Tourniquet CAT]
kling-2in <- Kling 2"   [older list: Kling 2"]
kling-4in <- Kling 4"   [older list: Kling 4"]
kling-6in <- Kling 6"   [older list: Kling 6"]
steri-pad-2x2 <- Steri pads 2x2"   [older list: Steri pads 2x2"]
steri-pad-4x4 <- Steri pads 4x4"   [older list: Steri pads 4x4"]
tv-surgi-pads-5-x-9 <- Surgi pads 5x9"   [older list: Surgi pads 5x9"]
tv-trauma-dressing-12-x-30 <- Trauma dressing 12x30"   [older list: Trauma dressing 12x30"]
tv-band-aids-1-x-3 <- Band aids 1 x 3" small   [older list: Band aids 1 x 3"]
tv-band-aids-4-1-2-x-2 <- Band aids 4 1/2" x 2" large   [older list: Band aids 4 1/2" x 2"]
quikclot-gauze <- Quick-clot   [older list: Quick-clot]
asherman-chest-seal <- Ascherman chest seals   [older list: Ascherman chest seals]
tv-cold-packs <- Cold packs   [older list: Cold packs]
tv-tyvek-suits-2xl <- Tyvek suits 2XL   [older list: Tyvek suits 2XL]
tv-tyvek-suits-xl <- Tyvek suits XL   [older list: Tyvek suits XL]
tv-shoe-covers <- Shoe covers   [older list: Shoe covers]
tv-tyvek-sleeves <- Tyvek sleeves   [older list: Tyvek sleeves]
n95-respirator <- N95 Masks   [older list: N95 Masks]
tv-3-4-face-shields <- 3/4 Face shields   [older list: 3/4 Face shields]
safety-glasses <- Safety Glasses (Clear)   [older list: Safety Glasses (Clear)]
tv-convenience-bags <- Convenience bags(VOMEX)   [older list: Convenience bags]
tv-peroxide-bottle <- Peroxide bottle   [older list: Peroxide bottle]
tv-alcohol-bottle <- Alcohol bottle   [older list: Alcohol bottle]
tv-cida-hand-gel <- Cida hand gel   [older list: Cida hand gel]
tv-sharps-container-small <- Sharps container Small   [older list: Sharps container Small]
sharps-shuttle <- Sharps shuttle   [older list: Sharps shuttle]
stethoscope <- Stethescope | Stethoscope   [older list: STETHOSCOPE]
tv-ekg-electrodes <- EKG electrodes | EKG Electrodes   [older list: EKG electrodes]
tv-combi-pads-adult <- Combi pads-adult | Combi Pads ADULT   [older list: Combi pads-adult]
tv-combi-pads-pedi <- Combi pads-pedi | Combi Pads PEDI   [older list: Combi pads-pedi]
tv-ekg-paper-lifepak-15 <- EKG paper lifepack 15 | EKG Lifepack 15 Paper   [older list: EKG paper lifepack 15]
tv-disposable-razors <- Disposable razors | Disposable Razors   [older list: Disposable razors]
pen-light <- Pen Lights   [older list: Pen Lights]
tv-yellow-foam-blanket <- Yellow foam blanket   [older list: Yellow foam blanket]
foil-rescue-blanket <- Foil rescue blanket   [older list: Foil rescue blanket]
tv-ob-kit <- OB kit   [older list: OB kit]
tv-disposable-straps <- Disposable Straps   [older list: Disposable straps]
ammonia-inhalants-als-bag <- Ammonia inhalants   [older list: Ammonia inhalants]
tv-auto-bp-cuff-adult-xl <- Auto BP Cuffs XL, L, REG,, PEDI, INFANT   [older list: Auto BP cuff adult XL]
tv-auto-bp-cuff-adult-lg <- Auto BP Cuffs XL, L, REG,, PEDI, INFANT   [older list: Auto BP cuff adult LG]
tv-auto-bp-cuff-adult-reg <- Auto BP Cuffs XL, L, REG,, PEDI, INFANT   [older list: Auto BP cuff adult REG]
tv-auto-bp-cuff-pedi <- Auto BP Cuffs XL, L, REG,, PEDI, INFANT   [older list: Auto BP cuff PEDI]
tv-auto-bp-cuff-infant <- Auto BP Cuffs XL, L, REG,, PEDI, INFANT   [older list: Auto BP cuff INFANT]
```

New keys: `ars-decomp-14ga-3in`, `needle-18ga-1-5in-filtered`, `needle-22ga-1-5in-admin`, `iv-tourniquet-latex-free`, `curaplex-bvm-manometer`, `bvm-co-capnoline`, `curaplex-infant-bvm`, `c-collar-adult-regular`, `burn-gel-4x16-dressing`, `tpod-pelvic-device`, `carry-all`, `speedy-cleans`, `ocular-eye-patch`, `latex-gloves-xxl`, `latex-gloves-xl`, `latex-gloves-large`, `latex-gloves-medium`, `latex-gloves-small`, `purple-nitrile-gloves-xl`, `purple-nitrile-gloves-lg`, `purple-nitrile-gloves-med`, `purple-nitrile-gloves-sm`, `sanizide-32oz-with-sprayer`, `sanizide-1-gallon`, `red-biohazard-bags-lg`, `red-biohazard-bags-sm`, `wipes`, `manual-bp-cuff-adult`, `manual-bp-cuff-pedi`, `disp-thermometer-strips`, `ammonia-inhalants-wipes`, `vinegar`, `backboard-with-disposable-straps`, `manual-bp-cuff-infant`, `lifepak-modem`, `lifepak-spo2-pulseox-sensor`, `lifepak-co2-rainbow-sensor`, `spo2-pediatric-sensor`, `spo2-infant-sensor`, `lifepak-4-lead-cable`, `lifepak-12-lead-cable`, `lifepak-therapy-cable`, `glucometer-with-lancets-and-strips`.

Reuse decisions worth a second look (each is the same product by its printed words, not by similarity, but a person who knows the stock may disagree): `Zofran Oral` / `Zofran IV` use the ALS bag's split keys `zofran-oral` / `zofran-iv`; `Syringe 10/12cc` uses `syringe-10-12cc` (the ALS bag's "10 or 12cc"); `Combat App. Tourniquet CAT ( MCI BAG)` and `Tourniquet CAT` use `cat-tourniquet`; `Band aids 1 x 3" small` / `4 1/2" x 2" large` use the transport sheet's dimension keys; `Convenience bags(VOMEX)` uses `tv-convenience-bags`; `Disp. Thermometer Strips` has its own key `disp-thermometer-strips` (it could be `tv-disposable-thermometer` or `thermometer-strip`); `ARS Decomp 14ga 3"` has its own key (the transport sheet prints "Decomp 14ga 3"" and the ALS bag "ARS Decomp 14ga Needle"); `Auto BP Cuffs` reuse the transport sheet's five cuff keys (same five sizes).

### 8c. Fire Boat 6 ALS (`fire_boat_6_als_inventory.json`, key `fire-boat-6-als`)

Source: `fire_boat_6_medical_inventory.pdf`, one page (1176.9 x 1523.1 points), printed header `Date: / / M.B.F.D. FIRE BOAT ALS INVENTORY Unit# FB# 4 & 5 Revised 7-2025`. Same table layout as the non-transport sheet.

| Section key | Printed heading | Printed rows | Items |
|---|---|---|---|
| `fb6-meds-red-bag` | MEDS RED BAG | 27 | 27 |
| `fb6-iv-needles` | I.V./NEEDLES | 32 | 32 |
| `fb6-airway-green-bag-left` | AIRWAY GREEN BAG | 17 | 17 |
| `fb6-airway` | AIRWAY | 12 | 12 |
| `fb6-airway-green-bag-middle` | AIRWAY GREEN BAG | 51 | 51 |
| `fb6-trauma-1-box` | TRAUMA #1 BOX (continued as TRAUMA #1 BOX ( CONT )) | 24 | 24 |
| `fb6-trauma-2-box` | TRAUMA #2 BOX | 14 | 14 |
| `fb6-miscellaneous-on-boat` | MISCELLANEOUS ON BOAT | 12 | 12 |
| `fb6-life-pack-inventory` | LIFE PACK INVENTORY | 22 | 26 |
| **Total** | | **211** | **215** |

Cross-check from the table borders: left 87 table rows = 3 headings + 76 rows + 8 empty; middle 88 = 3 headings + 1 italic note + 84 rows; right 88 = 4 headings + 51 rows + 33 empty. 211 named rows (headings, the note and the empty rows excluded); 215 items, the 211 rows plus 4 extra because the one `Auto BP Cuffs XL, L, REG,, PEDI, INFANT` row is five items. No row prints numbers without a name.

**Header conflict (recorded, no data change).** The header prints `Unit# FB# 4 & 5` while three item rows say `FIREBOAT 6` (`Portable suction with container (FIREBOAT 6)`, `Cardboard splints short (FIREBOAT 6)`, `Cardboard splints long  (FIREBOAT 6)`). The header is kept verbatim in `source.printed_header`, the conflict is the first entry of `source.review_notes`, the list's key is `fire-boat-6-als` and no title, label, source label, flag or note refers to FB4 or FB5 as its unit (`FireBoat6AlsSheetTest` enforces this). The three FIREBOAT 6 rows carry `qualifier_text` "FIREBOAT 6".

How the tricky cells were read:

- Same reading rules as the non-transport sheet for `1 in bag`, `2 IN BOX`, `5 each`, `2 pair` / `1 pair`, `1 box`, `1 bag`, `1 each`, the five yellow BVM rows (AMOUNT blank, `1 in bag` under Fill if <, flagged), the italic BVM note (the note of the second `AIRWAY GREEN BAG` section), `Syringe 10/12cc` as one item, and the five-size `Auto BP Cuffs` row.
- The boat's numbers differ from the non-transport sheet and are kept as printed: N. Saline 1000cc 4 / 4 (non-transport 2 / 2), `Tourniquet CAT` 6 / 6 (the non-transport sheet has `Combat App. Tourniquet CAT ( MCI BAG)` 10 / 10), 1" tape and 3" tape 2 / 2, 4" and 6" ace bandage 5 / 5, Armboards 4 / 4, Red biohazard bags 3 / 3, `Disposable Straps` 2 pair / 1 pair, Foil rescue blanket 2 / 2, Quick-clot 2 / 2, Safety Glasses (Clear) 4 / 4, no Tyvek or purple nitrile or Sanizide rows, latex gloves XL, Large and medium only. The boat sheet has `Manual Suction in Airway-Bag`, `Spare O2 Bottle`, `Vinegar 1 Gallon` and `Carry all` (singular) that the non-transport sheet does not; the boat prints no HANDTEVY rows, no `-IN HANDTEVY-` text and no yes/no check.
- Two headings are both printed `AIRWAY GREEN BAG` (bottom of the left column with OPA and NPA; second heading in the middle column above the BVM rows) and are kept as two sections. `TRAUMA #1 BOX ( CONT )` (top of the right column) is merged into `TRAUMA #1 BOX`. `Adult Manual BP Cuff`, `Pedi Manual BP Cuff` and `Convenience bags(VOMEX)` sit under I.V./NEEDLES; `Safety Glasses (Clear)` is its first row.
- `color_band`: the headings `MEDS RED BAG` (red band) and `AIRWAY GREEN BAG` (green band) print their color; the `I.V./NEEDLES` heading is on a red band and the `AIRWAY` heading on a green band, so those carry Red and Green too. The TRAUMA boxes are amber, MISCELLANEOUS ON BOAT blue and LIFE PACK INVENTORY light green; they print no color name and carry no `color_band`. Blank table rows between sections carry nothing.
- Boat machinery, electronics, SCBA and fire gear are not on this sheet and were not added anywhere.

Flagged `needs_review` (5): `fb6.airway-green-middle.et-tube-extension`, `fb6.airway-green-middle.bvm-co-capnoline`, `fb6.airway-green-middle.i-gel-5`, `fb6.airway-green-middle.i-gel-4`, `fb6.airway-green-middle.i-gel-3`. Reason for all five: AMOUNT is blank and "1 in bag" is printed under Fill if <.

Rows with numbers but no label: none.

Catalog: 181 keys reused from the older lists, 34 new. The reuse decisions are the same as for the non-transport sheet for every label the two sheets share; the reused keys that only this sheet uses (printed row <- key):

```
tv-bvm-infant <- Infant BVM   [older list: BVM Infant]
```

New keys: `needle-18ga-1-5in-filtered`, `needle-22ga-1-5in-admin`, `iv-tourniquet-latex-free`, `manual-bp-cuff-adult`, `manual-bp-cuff-pedi`, `curaplex-bvm-manometer`, `bvm-co-capnoline`, `manual-suction-in-airway-bag`, `portable-suction-with-container`, `ars-decomp-14ga-3in`, `ocular-eye-patch`, `c-collar-adult-regular`, `burn-gel-4x16-dressing`, `tpod-pelvic-device`, `carry-all`, `vinegar-1-gallon`, `backboard-with-disposable-straps`, `red-biohazard-bags-lg`, `red-biohazard-bags-sm`, `latex-gloves-xl`, `latex-gloves-large`, `latex-gloves-medium`, `spare-o2-bottle`, `disp-thermometer-strips`, `lifepak-modem`, `lifepak-spo2-pulseox-sensor`, `lifepak-co2-rainbow-sensor`, `spo2-pediatric-sensor`, `spo2-infant-sensor`, `lifepak-4-lead-cable`, `lifepak-12-lead-cable`, `lifepak-therapy-cable`, `glucometer-with-lancets-and-strips`, `ammonia-inhalants-wipes`. `Infant BVM` uses `tv-bvm-infant` (the transport sheet's `BVM Infant`); `Tourniquet CAT` uses `cat-tourniquet`.

**Cross-check against the FIRE BOAT 6 Daily apparatus checkout** (`fb6_apparatus_daily_checkout.pdf`, sha256 `ecddfa5463544c20e72a3c8769f4c1816b7f788f4285dcb85e3dd40413c2975c`, "8/2026 Version 4"). Only the medical equipment group was compared; nothing from that sheet was transcribed into any list, and no boat machinery, electronics, SCBA or fire gear field was added. The checkout's group is `Medical Inventory`: Airway Bag, Med Box/Boo Boo-Bag, LifePak 15, Spare O2 Bottle (upper storage), LSU Suction, Gloves SM-XXL (1BXea), Sleeves (1BX), Backboards (2) (lower storage, cubby), plus the Tuesday task "(Medical Inventory)". What the boat ALS sheet covers:

| Daily checkout line | Boat ALS sheet |
|---|---|
| Airway Bag | Not an item. The sheet lists the bag's contents under AIRWAY and AIRWAY GREEN BAG (and `Manual Suction in Airway-Bag`). |
| Med Box/Boo Boo-Bag | Not an item. The contents are under MEDS RED BAG and I.V./NEEDLES. |
| LifePak 15 | Not an item. LIFE PACK INVENTORY lists its supplies (paper, electrodes, pads, cables, sensors, modem). |
| Spare O2 Bottle | Covered: `Spare O2 Bottle` 1 / 1 under MISCELLANEOUS ON BOAT. |
| LSU Suction | Not named. `Portable suction with container (FIREBOAT 6)` 1 / 1 may be the same unit; the sheets do not say. |
| Gloves SM-XXL (1BXea) | Partly: `Latex gloves XL`, `Latex gloves Large`, `Latex gloves medium`, `1 box` each. No small and no XXL on the ALS sheet. |
| Sleeves (1BX) | Not covered. No sleeves row on the ALS sheet. |
| Backboards (2) | Partly: `Backboard with Disposable Straps` 1 / 1 (the checkout says 2). |

### 8d. BLS Detail Bag (`bls_detail_bag_inventory.json`, key `bls-detail-bag`)

Source: `bls_detail_bag_medical_inventory.pdf`, one page, title `BLS DETAIL BAG`, `REV 2020`. Two printed columns (item, one unlabeled quantity cell); the left column is read top to bottom, then the right column. The sheet prints no `Fill if <` and no NEED column.

| Section key | Printed heading | Printed rows | Items |
|---|---|---|---|
| `bls-front-pouch` | FRONT POUCH | 3 | 3 |
| `bls-left-side` | LEFT SIDE (6) | 4 | 4 |
| `bls-right-side` | RIGHT SIDE (5) | 4 | 4 |
| `bls-pouch-4` | POUCH 4 | 8 | 8 |
| `bls-pouch-3` | POUCH 3 | 3 | 3 |
| `bls-pouch-2` | POUCH 2 | 5 | 5 |
| `bls-compartment-1` | COMPARTMENT 1 | 9 | 9 |
| `bls-main-compartment` | MAIN COMPARTMENT | 8 | 8 |
| **Total** | | **44** | **44** |

Cross-check from the table borders: the left column has 35 table rows = 6 headings + 27 item rows + 2 empty rows (under ADULT PADS and under BURN GEL 4X4); the right column has 2 headings + 17 item rows. 44 item rows, 44 items (no row lists several variants).

How the tricky cells were read:

- `1 BOX` (BAND AIDES 1X3") is kept as printed (`min_text` "1 BOX", `min_qty` 1, `min_unit` box). Every other quantity is a plain number.
- `LEFT SIDE (6)` and `RIGHT SIDE (5)`: the number in brackets is part of the printed heading and is kept in the section label. It is not a row count (4 and 4 rows follow); it may be a pouch number.
- Misspellings stay in `source_label` and are corrected only in `label`: TRAMUA SHEARS (Trauma shears), ORAL GLUCTOSE (Oral glucose), TYVEX SLEEVES (Tyvek sleeves), STETHESCOPE (Stethoscope), BAND AIDES (Band aids), ASHERMAN CHEST SEALS (Asherman chest seals). `MADDETT` is not an obvious spelling of another product and is kept (label "Maddett"). The printed spellings are search terms.
- `OPAS 2 ADULT`, `OPAS 1 CHILD`, `OPAS 1 INF` print no OPA sizes; quantities 2, 1, 1.

Flagged `needs_review`: none. Rows with numbers but no label: none.

Catalog: 26 keys reused from the older lists, 18 new. Reused (this sheet's printed row <- key):

```
ice-pack <- ICE PACKS   [older list: ICE PACKS]
tv-band-aids-1-x-3 <- BAND AIDES 1X3"   [older list: Band aids 1 x 3"]
vomex-bag <- VOMEX BAG   [older list: VOMEX BAG]
n95-respirator <- N95 MASKS   [older list: N95 Masks]
tv-tyvek-sleeves <- TYVEX SLEEVES   [older list: Tyvek sleeves]
ace-bandage-4in <- ACE BANDAGE 4"   [older list: 4" ace bandage]
ace-bandage-6in <- ACE BANDAGE 6"   [older list: 6" ace bandage]
kling-6in <- KLING 6"   [older list: Kling 6"]
kling-4in <- KLING 4"   [older list: Kling 4"]
kling-2in <- KLING 2"   [older list: Kling 2"]
triangle-bandage <- TRIANGLE BANDAGE   [older list: TRIANGLE BANDAGE]
tv-burn-gel-2-x-6-dressing <- BURN GEL 2X6"   [older list: Burn Gel 2x6 Dressing]
tv-burn-gel-4-x-4-dressing <- BURN GEL 4X4   [older list: Burn Gel 4x4 Dressing]
tv-bvm-pedi <- BVM PEDI   [older list: BVM Pedi]
cat-tourniquet <- CAT TOURNIQUET   [older list: Combat App. Tourniquet CAT]
trauma-shears <- TRAMUA SHEARS   [older list: TRAUMA SHEARS]
pen-light <- PEN LIGHT   [older list: Pen Lights]
peroxide <- PEROXIDE   [older list: PEROXIDE]
tape-1in <- TAPE 1"   [older list: 1" tape]
tape-3in <- TAPE 3"   [older list: 3" tape]
asherman-chest-seal <- ASHERMAN CHEST SEALS   [older list: Ascherman chest seals]
quikclot-gauze <- QUICK CLOT   [older list: Quick-clot]
stethoscope <- STETHESCOPE   [older list: STETHOSCOPE]
red-bag-als-bag <- RED BAG   [older list: RED BAG]
glucometer <- GLUCOMETER   [older list: Glucometer]
narcan <- NARCAN   [older list: Narcan]
```

New keys: `aed`, `aed-pads-pedi`, `aed-pads-adult`, `band-aids-2x3`, `gloves-bls-detail-bag`, `goggles`, `combi-pad-5x9`, `gauze-4x4`, `burn-gel-small`, `bvm-adult`, `opas-adult`, `opas-child`, `opas-infant`, `oral-glucose`, `arm-board-long`, `arm-board-short`, `bp-cuff`, `maddett`. Not matched on purpose (printed differently): `GLOVES` (5, unit unknown; the bags print `GLOVES 3 SETS` and `GLOVES 2 PAIR`), `GOGGLES` (the ALS bag says SAFETY GOGGLES), `BURN GEL SMALL` (the ALS bag says BURN GEL SMALL PACKET), `ORAL GLUCTOSE` (the lists say glucose gel), `BP CUFF` (the bags say BP CUFF (ADULT)), `COMBI PAD 5X9"` (not the defibrillator combi pads).

### 8e. Verification run

`tests/Feature/Ems/{HandtevySheetTest,NonTransportSheetTest,FireBoat6AlsSheetTest,BlsDetailBagSheetTest}.php` hold each sheet row by row (printed text, label, AMOUNT, Fill if <, flags, response type, catalog key), the section order, row totals, unique item refs, the sha256, the printed header and review notes, and import through `SourceImporter`.
