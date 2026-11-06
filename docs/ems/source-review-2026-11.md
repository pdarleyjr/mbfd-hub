# Source review: four new inventory sheets (November 2026)

Status: a short list for a person to settle. Nothing here blocks the release; each line says what the app does today. Sheets: Handtevy, ALS Non Transport (revised 1-2024), Fire Boat 6 ALS (revised 7-2025), BLS Detail Bag (REV 2020). Details and row-by-row notes are in `database/data/ems/EXTRACTION_VALIDATION.md` section 8. Page is always 1 of 1.

The ALS Med Bag and Airway Bag sheets re-sent on 2026-11-02 are byte-identical to the imported ones (sha256 65eab0e2... and 017c463c...); those two lists are unchanged.

## Needs a person who knows the sheets

| # | Sheet, where | What is printed | What the app does now |
|---|---|---|---|
| 1 | Fire Boat 6 ALS, page header | `Unit# FB# 4 & 5`, while three rows say `FIREBOAT 6` (portable suction with container, cardboard splints short and long) | The list is Fire Boat 6's (key `fire-boat-6-als`). The header is stored word for word in the form's notes with a review note; no label or title mentions FB4 or FB5. Fix: print the next revision with the right unit. |
| 2 | ALS Non Transport, left column, table rows 55 and 56 (between `22GA 1.5" ADMIN NEEDLES` and `Saline flush 10cc`) | Two rows with AMOUNT 2 and Fill if 2 and no item name | Not shown on the form and not counted. Someone should say what they are (more needles or syringes?) so they can be added. |
| 3 | ALS Non Transport and Fire Boat 6 ALS, `AIRWAY / BVM Bag` and the second `AIRWAY GREEN BAG`: ET Tube extention, BVM CO Capnoline, i-gel 5, i-gel 4, i-gel 3 | AMOUNT is blank; `1 in bag` is printed under `Fill if <`; the row says `(1) PLACED IN BVM BAG` | The five rows (on each sheet) show no par, show `1 in bag` as the fill level and the location "Placed in BVM bag", and carry a review flag. Probably a column shifted on the paper sheet. |
| 4 | Handtevy, `< 1 Y/O` block (9 printed rows) | Rows are printed across the Grey, Pink and Red color columns; the sheet does not say which size goes with which color | All 13 items are listed together under "Grey / Pink / Red (< 1 year)" with a review flag. No size is assigned to a color. |
| 5 | Handtevy, rows that list sizes without a quantity: `Syringes - 1cc, 3cc, 10cc` (every age block except 9 - 13), `French Catheter- 6F,8F`, `French Catheter - 10F, 12F`, `Blade - 2 Straight & Curved`, `Blade - 3 Straight & Curved` | The sheet's rule is `Quantity 1 unless indicated in ( )`. It is not clear whether 1 means 1 per size or 1 per row. The 9 - 13 year block prints `(1 each)` on its syringes and ET tubes. | One item per size or blade type, 1 each. If 1 per row is meant, the extra items are wrong. |
| 6 | Handtevy, Top Insert, `Needles - 21Ga 1"(2), 25Ga 1" (1 each)` | `(2)` after 21Ga and `(1 each)` at the end | 21Ga 1" is 2; 25Ga 1" is 1. (If `1 each` was meant to cover both, 21Ga would be 1.) |
| 7 | Fire Boat 6 Daily checkout (8/2026 v4) against the boat ALS sheet | The checkout's medical group lists Backboards (2), Gloves SM-XXL (1BXea), Sleeves (1BX), LSU Suction, Spare O2 Bottle. The ALS sheet has Backboard with Disposable Straps 1 / 1, latex gloves XL, Large and medium only, no sleeves, `Portable suction with container (FIREBOAT 6)` 1 / 1, Spare O2 Bottle 1 / 1 | The boat form shows only what the ALS sheet prints; nothing from the checkout was added. If the boat really carries sleeves, small and XXL gloves, or 2 backboards, the ALS sheet needs those rows. |

## Small things (kept as printed)

- Handtevy prints no title, date or revision. The list is labelled "undated".
- Handtevy `Meconium Aspirator/ MAD Device` and `Infant & Child BP Cuff` each name two things with one quantity; each is one item.
- ALS Non Transport, PPE - DECON block: `Stethescope` and `Manual B/P Cuffs Adult/ Pedi` sit with the PPE rows. Fire Boat 6, I.V./NEEDLES block: `Safety Glasses (Clear)`, `Adult Manual BP Cuff`, `Pedi Manual BP Cuff` and `Convenience bags(VOMEX)` sit with the IV rows. Kept in the printed block.
- ALS Non Transport and Fire Boat 6: the two `AIRWAY` headings (non-transport) and two `AIRWAY GREEN BAG` headings (boat) are separate sections, labelled "left column" and "middle column" because the sheet prints the same heading twice.
- Disposable razors on ALS Non Transport EKG SUPPLIES: AMOUNT `2 pack`, Fill if `2 razors` (the transport sheet has the same mix). The units differ on purpose.
- BLS Detail Bag: `LEFT SIDE (6)` and `RIGHT SIDE (5)` keep the printed number in brackets (it is not the row count; maybe a pouch number). `OPAS 2 ADULT / OPAS 1 CHILD / OPAS 1 INF` print no sizes. `GLOVES` is 5 with no unit (the bags say sets or pairs).

## Items that might be the same stock as an item on another list

Each pair below is its own catalog item today, because the printed words or sizes differ. If a person who knows the stock says two are the same, the catalog link can be added later without changing any request.

- `disp-thermometer-strips` (Disp. Thermometer Strips) with `tv-disposable-thermometer` (Dispo. Thermometer) and `thermometer-strip` (THERMOMETER STRIP).
- `ars-decomp-14ga-3in` (ARS Decomp 14ga 3") with `tv-decompression-needle-14-ga-3` and `ars-decomp-14ga-needle`.
- `iv-tourniquet-latex-free` (I.V. Tourniquet Latex Free) with `tv-tourniquet-latex-free` and `iv-tourniquet`.
- `latex-gloves-*` (latex gloves XL, Large, medium, small, XXL) with `tv-disposable-gloves-*`; `red-biohazard-bags-lg` / `-sm` with `tv-red-biohazard-bags`; `sanizide-32oz-with-sprayer` and `sanizide-1-gallon` with `tv-sanizide`; `vinegar` with `tv-vinegar-bottle`.
- `manual-bp-cuff-adult` (Adult Manual BP Cuff) with `bp-cuff-adult`; `curaplex-infant-bvm` with `tv-bvm-infant`; `bvm-co-capnoline` with `bvm-capnoline`.
- BLS: `burn-gel-small` with `burn-gel-small-packet`; `oral-glucose` with `glucose-gel`; `trauma-sleeves` (Handtevy) with `sleeves-airway-bag`; `bp-cuff` with `bp-cuff-adult`; `goggles` with `safety-goggles`.
- `ocular-eye-patch` with `tv-ocular-patch` and `eye-cover`; Handtevy `french-catheter-*` with `suction-catheter-*`; Handtevy `needle-21ga-1in` / `needle-25ga-1in` with `needle-21g` / `needle-25g`.
