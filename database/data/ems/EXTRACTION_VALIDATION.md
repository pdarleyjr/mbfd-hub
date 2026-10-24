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

