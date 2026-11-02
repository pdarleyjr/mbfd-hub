"""Builds database/data/ems/catalog_supply_links.json (schema mbfd.ems.catalog_supply_links.v1).

    "C:\\...\\python.exe" -I scripts\\ems\\build_catalog_supply_links.py

Reads the three member form extractions, the supply room extraction and the workbook extraction, then checks the reviewed
decisions in supply_links_reviewed.py against them: every decision must name a form item that really has no supply room
link today, and every target row must exist. Writes the tracked mapping. No names are compared anywhere.
"""

from __future__ import annotations

import argparse
import json
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

from supply_links_reviewed import LINKS, NOT_STOCKED, OPEN_NOTES  # noqa: E402

REPO = Path(__file__).resolve().parents[2]
DATA = REPO / "database" / "data" / "ems"
FORMS = {"airway-bag": "airway_bag_inventory.json", "als-med-bag": "als_med_bag_inventory.json", "als-transport-vehicle": "als_transport_vehicle_inventory.json"}
SCHEMA = "mbfd.ems.catalog_supply_links.v1"


def ref_of(entry: dict, name: str) -> str | None:
    return entry.get(f"{name}_ref") or entry.get(f"{name}_key")


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--data", default=str(DATA))
    parser.add_argument("--out", default=str(DATA / "catalog_supply_links.json"))
    args = parser.parse_args()
    data = Path(args.data)

    supply = json.loads((data / "captain5_supply_room_inventory.json").read_text(encoding="utf-8"))
    workbook = json.loads((data / "supply_room_workbook_2026.json").read_text(encoding="utf-8"))
    targets = {r["row_key"]: r["item_cell_text"] for r in supply["rows"]}
    targets.update({i["source_row_key"]: i["new_item"]["name"] for i in workbook["items"] if i["new"]})
    stocked_refs = {ref_of(r, "catalog") for r in supply["rows"]} - {None}

    unlinked: dict[str, dict] = {}
    form_items = 0
    for form_key, file_name in FORMS.items():
        document = json.loads((data / file_name).read_text(encoding="utf-8"))
        for section in document["sections"]:
            for item in section["items"]:
                ref = ref_of(item, "catalog")
                if item.get("response_type", "quantity") != "quantity" or ref is None or ref in stocked_refs:
                    continue
                form_items += 1
                entry = unlinked.setdefault(ref, {"catalog_ref": ref, "catalog_name": item["label"], "forms": [], "form_items": 0})
                entry["form_items"] += 1
                if form_key not in entry["forms"]:
                    entry["forms"].append(form_key)

    problems = []
    for name, table in (("LINKS", LINKS), ("NOT_STOCKED", NOT_STOCKED), ("OPEN_NOTES", OPEN_NOTES)):
        problems += [f"{name}: {ref} is not an unlinked form item" for ref in table if ref not in unlinked]
    problems += [f"LINKS: {ref} points at {row}, which does not exist" for ref, (row, _) in LINKS.items() if row not in targets]
    problems += [f"{ref} is both linked and not stocked" for ref in LINKS if ref in NOT_STOCKED]
    problems += [f"{ref} is both decided and open" for ref in OPEN_NOTES if ref in LINKS or ref in NOT_STOCKED]
    if problems:
        raise SystemExit("\n".join(problems))

    links, not_stocked, open_items = [], [], []
    for ref in sorted(unlinked):
        base = unlinked[ref]
        if ref in LINKS:
            row, reason = LINKS[ref]
            links.append({**base, "source_row_key": row, "supply_name": targets[row], "reason": reason})
        elif ref in NOT_STOCKED:
            not_stocked.append({**base, "reason": NOT_STOCKED[ref]})
        else:
            open_items.append({**base, "note": OPEN_NOTES.get(ref, "No matching row on the supply room list.")})

    document = {
        "schema": SCHEMA,
        "source": {
            "workbook_sha256": workbook["source"]["sha256"],
            "supply_source_sha256": supply["source"]["sha256"],
            "forms": sorted(FORMS.values()),
            "rule": "A link is written only when the form item and exactly one supply row name the same product and size. Anything else is left open.",
        },
        "summary": {
            "form_items_without_link": form_items, "catalog_items_without_link": len(unlinked),
            "linked": len(links), "not_stocked": len(not_stocked), "left_open": len(open_items),
            "form_items_linked": sum(e["form_items"] for e in links),
            "form_items_not_stocked": sum(e["form_items"] for e in not_stocked),
            "form_items_left_open": sum(e["form_items"] for e in open_items),
        },
        "links": links,
        "not_stocked": not_stocked,
        "open": open_items,
    }
    Path(args.out).write_text(json.dumps(document, indent=2, ensure_ascii=False) + "\n", encoding="utf-8", newline="\n")
    print(json.dumps(document["summary"], indent=2))
    print(f"Wrote {args.out}")


if __name__ == "__main__":
    main()
