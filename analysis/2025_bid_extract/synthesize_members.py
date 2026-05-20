"""
MBFD 2026 Bid Member Synthesis

Combines:
  - 2025_rank_seniority_list.pdf  (228 active bid members with rank + rank_seniority)
  - personnel.csv                  (Telestaff dump: employee_id, RscSeniority, hire/promotion dates)
  - 2025 Bid Pick                  (who held what position in 2025 -> infer certs)
  - 2025 cert taxonomy             (57 certs)

Applies business rules:
  - Exclude Derek Lewis (emp 20734) and Hans Estrada (emp 19131)  [no longer employed]
  - Promote Aurelio Mederos (emp 17836) from FF -> LT  [Darley senior to him]
  - Peter Darley (emp 20731) is already LT in the 2025 PDF (rank_seniority 74)

Outputs:
  members_2026_synthesis.csv -- ready for /admin/members/import
  members_2026_synthesis.json -- structured for review and cert pre-fill
  missing_from_portal.json  -- members in PDF roster but missing from personnel.csv
"""

import csv
import json
import os
import sys
from pathlib import Path

BASE = Path(__file__).resolve().parent
PORTAL_CSV = Path("D:/GitHub_Repos/MBFD_Hub/analysis/personnel.csv")
EXCLUDE_EMP_IDS = {20734, 19131}  # Derek Lewis, Hans Estrada
PROMOTE_TO_LT = {17836}            # Aurelio Mederos (FF -> LT)

# ------- load PDF roster -------
pdf = json.loads((BASE / "pdf_roster.json").read_text(encoding="utf-8"))
pdf_members = []
for emp, last, first, rs in [(r[1], r[2], r[3], r[4]) for r in pdf["captains"]]:
    pdf_members.append({"emp": emp, "last": last, "first": first, "rank": "Captain", "rank_seniority": rs})
for emp, last, first, rs in [(r[1], r[2], r[3], r[4]) for r in pdf["lieutenants"]]:
    pdf_members.append({"emp": emp, "last": last, "first": first, "rank": "Lieutenant", "rank_seniority": rs})
for emp, last, first, rs in [(r[1], r[2], r[3], r[4]) for r in pdf["firefighters"]]:
    pdf_members.append({"emp": emp, "last": last, "first": first, "rank": "Firefighter", "rank_seniority": rs})

pdf_de_set = set(pdf["firefighter_de_emp_ids"])

print(f"PDF roster: {len(pdf_members)} active bid members")

# ------- load portal CSV -------
portal_by_emp = {}
with PORTAL_CSV.open(encoding="utf-8-sig", newline="") as f:
    reader = csv.DictReader(f)
    for row in reader:
        emp = row.get("Employee Id", "").strip()
        if emp.isdigit():
            portal_by_emp[int(emp)] = row

print(f"Portal CSV: {len(portal_by_emp)} rows")

# ------- load 2025 bid picks (infer certs from positions held) -------
with (BASE / "bid_pick_2025.json").open() as f:
    picks_rows = json.load(f)
picks_header = picks_rows[0]
picks_by_emp = {}
for r in picks_rows[1:]:
    rec = {h: r[i] for i, h in enumerate(picks_header) if h is not None}
    emp = rec.get("Emp Id")
    if isinstance(emp, int):
        picks_by_emp[emp] = rec

print(f"2025 Bid Picks: {len(picks_by_emp)} picks indexed")

# ------- load cert taxonomy -------
with (BASE / "credentials_master.json").open() as f:
    cred_rows = json.load(f)
ALL_CERTS = [{"id": r[0], "name": r[1], "points": r[2], "notes": r[3]} for r in cred_rows[1:] if r[0] is not None]
print(f"Cert taxonomy: {len(ALL_CERTS)} credentials")

# ------- infer certs from 2025 position -------
# Position-based cert inference: if a member held a specialty position in 2025,
# they MUST have held the required certs for that position. This is a baseline;
# the chief will toggle additional certs in the UI.
#
# Position prefixes / station codes -> inferred certs.
POSITION_CERT_HINTS = {
    # Marine station 8 (Rescue Boat) -- Marine Ops certs
    "_801": ["IADRS Swim Evaluation", "Open Water Diver Certified", "Public Safety Diver", "Merchant Mariner Credential (MMC)"],
    # TRT (Station 2) -- all 6 Operations + Tech
    "_201": ["Hazardous Materials Operations", "Rope Rescue Operations", "Confined Space Operations",
             "Structural Collapse Operations", "Trench Rescue Operations", "Vehicle & Machinery Rescue Operations"],
    "_202": ["Hazardous Materials Operations", "Rope Rescue Operations", "Confined Space Operations",
             "Structural Collapse Operations", "Trench Rescue Operations", "Vehicle & Machinery Rescue Operations"],
    # Air Tech 810
    "_810": ["Cylinder Hazmat & FSO Compliance (AIR TECH REQUIREMENT)"],
    # Captain 5 (Fire Prevention)
    "_5":    ["Firesafety Inspector I", "Firesafety Inspector II", "Fire Investigator I", "Instructor I"],
}

def infer_certs_from_position(position_id):
    if not position_id or not isinstance(position_id, str):
        return []
    certs = set()
    for suffix, cert_list in POSITION_CERT_HINTS.items():
        if position_id.endswith(suffix):
            certs.update(cert_list)
    return sorted(certs)

# ------- synthesize -------
output = []
missing_from_portal = []
notes = []

for m in pdf_members:
    emp = m["emp"]
    if emp in EXCLUDE_EMP_IDS:
        notes.append(f"EXCLUDED: {emp} {m['first']} {m['last']} (no longer employed)")
        continue

    # Apply promotion
    rank = m["rank"]
    rank_seniority = m["rank_seniority"]
    if emp in PROMOTE_TO_LT:
        rank = "Lieutenant"
        # Place junior to Darley (rank_seniority 74). Use 74.5 for tiebreaker semantics.
        rank_seniority = 74.5
        notes.append(f"PROMOTED: {emp} {m['first']} {m['last']} -> Lieutenant, rank_seniority {rank_seniority} (junior to Darley=74)")

    # Pull supplementary data from portal (Telestaff dump)
    portal = portal_by_emp.get(emp)
    if portal is None:
        missing_from_portal.append({"emp": emp, "first": m["first"], "last": m["last"], "rank": rank})
        # Synthesize minimal record; staging import requires rsc_seniority. Use rank_seniority as a stand-in
        # and mark these for the user to fix in the portal.
        rsc_seniority = rank_seniority
        rsc_in_portal = False
    else:
        rsc_seniority = int(portal.get("RscSeniorityIn", rank_seniority))
        rsc_in_portal = True

    # Bid category: officers (Captain, LT) = OFC; everyone else = FF
    bid_category = "OFC" if rank in ("Captain", "Lieutenant", "Division Chief", "Deputy Fire Chief", "Fire Chief") else "FF"

    # Inferred certs from 2025 pick
    pick = picks_by_emp.get(emp)
    inferred_certs = []
    if pick:
        pos = pick.get("Position #") or ""
        inferred_certs = infer_certs_from_position(str(pos))

    # Driver/Engineer subset (from PDF DE roster)
    if emp in pdf_de_set and "Driver Engineer Qualified" not in inferred_certs:
        inferred_certs.append("Driver Engineer Qualified")

    output.append({
        "employee_id": emp,
        "last_name": m["last"],
        "first_name": m["first"],
        "current_rank": rank,
        "bid_rank": rank,
        "bid_category": bid_category,
        "bid": "Include",
        "rsc_seniority": rsc_seniority,
        "rank_seniority": rank_seniority,
        "rsc_in_portal": rsc_in_portal,
        "inferred_certs_2025": inferred_certs,
        "position_2025": (pick or {}).get("Position #"),
    })

print(f"\nSynthesis result: {len(output)} members")
print(f"  Excluded: {len(EXCLUDE_EMP_IDS)}")
print(f"  Promoted: {len(PROMOTE_TO_LT)}")
print(f"  Missing from portal: {len(missing_from_portal)}")
print(f"  In portal: {sum(1 for m in output if m['rsc_in_portal'])}")
print()
print("Notes:")
for n in notes:
    print(f"  {n}")
print()
if missing_from_portal:
    print("Members in PDF roster but NOT in personnel.csv (need portal account):")
    for m in missing_from_portal:
        print(f"  emp={m['emp']:6}  {m['first']:15} {m['last']:20}  {m['rank']}")

# ------- write outputs -------
import_csv = BASE / "members_2026_synthesis.csv"
with import_csv.open("w", encoding="utf-8", newline="") as f:
    # Use the same column names personnel.csv uses so /admin/members/import accepts it as-is.
    writer = csv.writer(f)
    writer.writerow([
        "Employee Id", "Last Name", "First Name", "Current Rank",
        "Bid Rank", "Bid Category", "Bid", "RscSeniorityIn",
    ])
    for m in output:
        writer.writerow([
            m["employee_id"], m["last_name"], m["first_name"], m["current_rank"],
            m["bid_rank"], m["bid_category"], m["bid"], m["rsc_seniority"],
        ])

(BASE / "members_2026_synthesis.json").write_text(
    json.dumps(output, indent=2, default=str), encoding="utf-8"
)
(BASE / "missing_from_portal.json").write_text(
    json.dumps(missing_from_portal, indent=2), encoding="utf-8"
)

print(f"\nWrote {import_csv}  ({len(output)} rows)")
print(f"Wrote {BASE / 'members_2026_synthesis.json'}")
print(f"Wrote {BASE / 'missing_from_portal.json'}  ({len(missing_from_portal)} entries)")
