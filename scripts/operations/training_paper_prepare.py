"""Local, read-only paper evidence preparation. Never connects to a database.

Requires installed pypdf for inventory page counts. Windows callers must invoke
an absolute verified interpreter. Output directories must already be private.
Matching proposes identities only; it never approves a row.
"""
from __future__ import annotations

import argparse
import hashlib
import io
import json
from pathlib import Path, PurePosixPath
import re
import sys
import unicodedata
import zipfile

EXPECTED = {f"Module 1 Ten 8 Aerial In Service  2026 Oct {day}.pdf" for day in (13, 14, 15)}
MAX_SIZE = 30 * 1024 * 1024


def inventory(archive: Path) -> dict:
    from pypdf import PdfReader

    if archive.stat().st_size > 80 * 1024 * 1024:
        raise ValueError("Archive size limit exceeded")
    sources = []
    with zipfile.ZipFile(archive) as package:
        members = package.infolist()
        if len(members) != 3 or {m.filename for m in members} != EXPECTED:
            raise ValueError("Expected exactly the three dated PDF originals")
        seen = set()
        for member in members:
            name = PurePosixPath(member.filename)
            if name.name != member.filename or "\\" in member.filename or ":" in member.filename or member.is_dir():
                raise ValueError("Unsafe member path")
            if member.flag_bits & 1 or not 0 < member.file_size <= MAX_SIZE:
                raise ValueError("Encrypted or oversized member")
            if member.compress_size <= 0 or member.file_size > 100 * member.compress_size:
                raise ValueError("Expansion limit exceeded")
            with package.open(member) as source:
                data = source.read(MAX_SIZE + 1)
            if len(data) != member.file_size or not data.startswith(b"%PDF-"):
                raise ValueError("Invalid PDF original")
            digest = hashlib.sha256(data).hexdigest()
            if digest in seen:
                raise ValueError("Repeated PDF bytes")
            seen.add(digest)
            pages = len(PdfReader(io.BytesIO(data)).pages)
            if pages != 2:
                raise ValueError("Expected two pages per original")
            sources.append({"source_filename": member.filename, "source_sha256": digest, "page_count": pages})
    return {"archive_sha256": hashlib.sha256(archive.read_bytes()).hexdigest(), "documents": sources, "page_total": 6}


def normalize(value: str) -> str:
    ascii_text = unicodedata.normalize("NFKD", value).encode("ascii", "ignore").decode().casefold()
    return " ".join(re.findall(r"[a-z0-9]+", ascii_text))


def reconcile(transcription: dict, canonical: dict) -> dict:
    employees = canonical["employees"]
    result = []
    seen = {}
    for source in transcription["rows"]:
        row = dict(source)
        tokens = set(normalize(row["raw_transcription"]).split())
        exact = [e for e in employees if len(tokens) >= 2 and tokens == set(normalize(e["name"]).split())]
        # Token subsets allow canonical middle names but remain proposals only.
        candidates = exact or [e for e in employees if len(tokens) >= 2 and tokens <= set(normalize(e["name"]).split())]
        row["candidate_employee_ids"] = [e["id"] for e in candidates]
        row["match_rule"] = "exact_normalized_tokens" if exact else "canonical_token_subset" if candidates else "unresolved"
        row["employee_profile_id"] = None
        row["identity_confirmed"] = False
        row["review_status"] = "pending"
        row["possible_duplicate_of"] = None
        if len(candidates) == 1:
            key = (row["day"], candidates[0]["id"])
            if key in seen:
                row["possible_duplicate_of"] = seen[key]
            else:
                seen[key] = {"page": row["page"], "row": row["row"]}
        result.append(row)
    return {"identity_approval": "NOT_PERFORMED", "rows": result}


def build_manifest(source_inventory: dict, reviewed: dict, source_dir: Path,
                   course_id: int, module_id: int, reviewer_id: int) -> dict:
    if min(course_id, module_id, reviewer_id) < 1:
        raise ValueError("Confirmed positive course, module and reviewer IDs required")
    sources = source_inventory.get("documents", source_inventory.get("source_files", []))
    documents = []
    for source in sources:
        filename = source["source_filename"]
        if filename not in EXPECTED:
            raise ValueError("Unexpected source document")
        day = next(day for day in ("13", "14", "15") if f"Oct {day}.pdf" in filename)
        rows = []
        for observed in reviewed["rows"]:
            if observed["day"] != day:
                continue
            row = {key: observed[key] for key in ["page", "row", "raw_transcription", "evidence_present",
                                                   "review_status", "employee_profile_id", "identity_confirmed", "review_reason"]}
            row["raw_transcription"] += " | written date: " + str(observed.get("written_date", "unreadable"))
            if row["review_status"] == "approved" and (not row["identity_confirmed"] or not row["employee_profile_id"] or not row["evidence_present"]):
                raise ValueError("Unconfirmed approval in reviewed ledger")
            rows.append(row)
        documents.append({"source_path": str(source_dir / filename), "source_filename": filename,
                          "source_sha256": source.get("source_sha256", source.get("pdf_sha256")),
                          "page_count": source["page_count"], "held_on": f"2026-10-{day}",
                          "course_id": course_id, "module_id": module_id, "rows": rows})
    if len(documents) != 3:
        raise ValueError("All three sources are required")
    return {"schema_version": 1, "archive_sha256": source_inventory["archive_sha256"],
            "reviewer_id": reviewer_id, "documents": documents}


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    sub = parser.add_subparsers(dest="operation", required=True)
    scan = sub.add_parser("inventory")
    scan.add_argument("--archive", required=True, type=Path)
    match = sub.add_parser("reconcile")
    match.add_argument("--transcription", required=True, type=Path)
    match.add_argument("--employees", required=True, type=Path)
    manifest = sub.add_parser("manifest")
    manifest.add_argument("--inventory", required=True, type=Path)
    manifest.add_argument("--reviewed", required=True, type=Path)
    manifest.add_argument("--source-dir", required=True, type=Path)
    manifest.add_argument("--course-id", required=True, type=int)
    manifest.add_argument("--module-id", required=True, type=int)
    manifest.add_argument("--reviewer-id", required=True, type=int)
    parser.add_argument("--out", required=True, type=Path)
    args = parser.parse_args()
    try:
        if not args.out.parent.is_dir() or args.out.exists():
            raise ValueError("A private existing output directory and new filename are required")
        if args.operation == "inventory":
            result = inventory(args.archive)
        elif args.operation == "reconcile":
            result = reconcile(json.loads(args.transcription.read_text(encoding="utf-8-sig")),
                               json.loads(args.employees.read_text(encoding="utf-8-sig")))
        else:
            result = build_manifest(json.loads(args.inventory.read_text(encoding="utf-8-sig")),
                                    json.loads(args.reviewed.read_text(encoding="utf-8-sig")), args.source_dir,
                                    args.course_id, args.module_id, args.reviewer_id)
        with args.out.open("x", encoding="utf-8") as output:
            json.dump(result, output, indent=2)
        print(json.dumps({"operation": args.operation, "status": "PASS", "documents": len(result.get("documents", [])),
                          "rows": len(result.get("rows", [])), "approval": "NOT_PERFORMED"}))
        return 0
    except Exception as error:
        # Paths and raw values may identify employees. Report only exception type.
        print(f"PREPARATION=FAIL ({type(error).__name__})", file=sys.stderr)
        return 2


if __name__ == "__main__":
    raise SystemExit(main())
