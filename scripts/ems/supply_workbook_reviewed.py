"""Decisions a person made by reading the workbook against the Hub's supply room list.

Nothing here is a fuzzy match. Rows are matched to Hub items by exact normalized text only (see
extract_supply_workbook.py). A row that matches nothing must be listed below with a decision and a
reason; an unlisted unmatched row stops the extraction so a changed workbook cannot slip through.

Reviewed against MEDICAL_SUPPLY_LIST_with_Count_Tab.xlsx (sha256 783ff238...) on 2026-11-01.
"""

from __future__ import annotations

# Rows whose text differs from a Hub item only by punctuation or spacing could be listed here as
# {"sheet": "count"|"inventory", "row": N, "text": "<normalized sheet text>", "source_row_key": "sr...", "reason": "..."}.
# The override applies only while the sheet still holds exactly that text at that row.
# None were needed: every row that matches a Hub item matches it by its exact text.
REVIEWED_OVERRIDES: list[dict] = []

# (sheet, row) -> decision for rows that match no Hub item.
#   action "new": a real item with a count; becomes a new supply item with source_row_key wb.c<row>.
#   action "exception": left out; the reason is printed in the report.
REVIEWED_UNMATCHED: dict[tuple[str, int], dict] = {
    ("count", 58): {
        "action": "new",
        "reason": "Vial Adapters is counted at shelf B4 but is not on the printed 2021 list. Nothing similar exists in the Hub list, so it is a new item.",
        "item": {"name": "Vial Adapters", "bag_alias_text": None, "section_key": "supplies", "catalog_ref": "wb-vial-adapters"},
    },
    ("count", 63): {
        "action": "exception",
        "reason": 'The Count sheet says "Syringe Needle 18 ga." but the Inventory sheet and the Hub list have "Syringe Needle 19 ga." in the same place (B4, between the EZ Set and the 21 ga). The gauge differs, so the two cannot be proven the same item. No new item was created and no count was applied. Captain 5 should confirm which gauge is on the shelf.',
    },
    ("count", 173): {
        "action": "new",
        "reason": "OPA 70 mm is counted at H1B but is missing from the printed list, which jumps from OPA 80 mm to OPA 60 mm. Each OPA size is its own item, so a new item was added for 70 mm.",
        "item": {"name": "OPA 70 mm", "bag_alias_text": None, "section_key": "supplies-after-unlabeled-band", "catalog_ref": "wb-opa-70-mm"},
    },
    ("count", 269): {
        "action": "exception",
        "reason": "A quantity (18) with shelf O but no item name. The same row on the Inventory sheet is Masimo Spo2 sensor single use Pedi, so it may belong to that item, but the name is missing so it cannot be proven. No count was applied.",
    },
    ("count", 301): {
        "action": "new",
        "reason": "Ondansetron (Zofran) is on both sheets (Count row 301 and Inventory row 307) but not on the printed 2021 list. It has a count (0) and an order level, so it is a new item. It is stored in the Captain 5 refrigerator.",
        "item": {
            "name": "Ondansetron (CAPT 5 Refrigerator)", "bag_alias_text": "ZOFRAN", "section_key": "medications",
            "catalog_ref": "wb-ondansetron", "refrigerated": True,
            "extra_review": "The shelf is written CAPT 5 here and CPT 5 elsewhere on the list. They look like the same place but were not merged; move the item if so.",
        },
    },
    ("count", 305): {
        "action": "new",
        "reason": "Part of the block headed NOT ON INVENTORY NEW PROJECT - DETAIL BAGS. A physical bag with a count (6). It has no shelf and no order level yet.",
        "item": {"name": "BLS Red Bag", "bag_alias_text": None, "section_key": "detail-bags", "catalog_ref": "wb-bls-red-bag"},
    },
    ("count", 306): {
        "action": "new",
        "reason": "Part of the DETAIL BAGS block. A physical bag with a count (8). It is a different item from the G3 Airway Soft Green Bag on the printed list, so it is kept separate.",
        "item": {"name": "Green Airway Bag", "bag_alias_text": None, "section_key": "detail-bags", "catalog_ref": "wb-green-airway-bag"},
    },
    ("count", 307): {
        "action": "new",
        "reason": 'Part of the DETAIL BAGS block. A physical box with a count (8). The sheet spells it "Orang"; the name is kept as typed so it can be found on the sheet.',
        "item": {"name": "ALS Orang Box", "bag_alias_text": None, "section_key": "detail-bags", "catalog_ref": "wb-als-orang-box"},
    },
    ("count", 308): {
        "action": "new",
        "reason": "Part of the DETAIL BAGS block. A count of 7. The name suggests loaner LifePak equipment rather than a consumable, so Captain 5 should confirm it belongs on the supply room list.",
        "item": {"name": "LP Loaners", "bag_alias_text": None, "section_key": "detail-bags", "catalog_ref": "wb-lp-loaners", "extra_review": "Wording suggests loaned equipment; confirm it belongs on the supply room list."},
    },
    ("inventory", 309): {
        "action": "exception",
        "reason": "A lone number (700) in the ITEM column with nothing else on the row. It has no name, shelf or order level, so it is ignored.",
    },
}

_RANDOM = (
    "One of the RANDOM ITEMS ORDERED rows: a one-time order note with no shelf, no order level, no count and 'As Needed' for the order amount. "
    "It is not a supply room stock row, so no item was created."
)
for _row in (313, 314, 315, 316, 317):
    REVIEWED_UNMATCHED[("inventory", _row)] = {"action": "exception", "reason": _RANDOM}
