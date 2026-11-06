"""Extract rows from the City fuel list PDF (Driver Prompt ID report) as JSON on stdout.

Usage: python -I fuel_id_extract.py <path-to-pdf>

Each output row: {"page", "employee_id", "last_name", "first_name", "prompt_id", "status", "department"}.
Values are kept as strings so leading zeros survive. Nothing but the JSON document is written to stdout;
errors go to stderr with a non-zero exit code. The PIN values are never logged.
"""

import json
import os
import re
import sys

# Only the JSON rows may reach stdout. PyMuPDF (and MuPDF underneath it) can print notices and warnings to
# stdout, which would corrupt the result, so keep a private copy of the real stdout for the JSON and send
# everything else written to fd 1 (Python or C level) to stderr from here on.
_RESULT = os.fdopen(os.dup(1), "w", encoding="utf-8")
os.dup2(2, 1)
sys.stdout = sys.stderr

import fitz  # PyMuPDF  # noqa: E402 - imported after stdout is redirected on purpose

# Column boundaries (PDF points, left edge of each word).
COLUMNS = (
    ("employee_id", 0, 110),
    ("last_name", 110, 200),
    ("first_name", 200, 320),
    ("prompt_id", 320, 380),
    ("status", 380, 460),
    ("department", 460, 10_000),
)
HEADER_WORDS = {"Employee", "Driver", "Name", "ID", "Department"}
ROW_BUCKET = 3.0
DIGITS = re.compile(r"^\d+$")


def column_for(x0):
    for name, start, end in COLUMNS:
        if start <= x0 < end:
            return name
    return None


def page_rows(page, page_number):
    buckets = {}
    for x0, y0, _x1, _y1, text, *_rest in page.get_text("words"):
        if text in HEADER_WORDS:
            continue
        buckets.setdefault(round(y0 / ROW_BUCKET), []).append((x0, text))

    rows = []
    for key in sorted(buckets):
        cells = {name: [] for name, _s, _e in COLUMNS}
        for x0, text in sorted(buckets[key]):
            column = column_for(x0)
            if column is not None:
                cells[column].append(text)
        row = {name: " ".join(parts).strip() for name, parts in cells.items()}
        if not DIGITS.match(row["employee_id"]) or not DIGITS.match(row["prompt_id"]):
            continue
        rows.append({
            "page": page_number,
            "employee_id": row["employee_id"],
            "last_name": row["last_name"],
            "first_name": row["first_name"],
            "prompt_id": row["prompt_id"],
            "status": row["status"],
            "department": row["department"] or None,
        })
    return rows


def main(argv):
    if len(argv) != 2:
        print("usage: fuel_id_extract.py <pdf>", file=sys.stderr)
        return 2
    try:
        document = fitz.open(argv[1])
    except Exception as error:  # noqa: BLE001 - report any open failure without a traceback
        print(f"Could not open the PDF: {type(error).__name__}", file=sys.stderr)
        return 3
    rows = []
    with document:
        for index, page in enumerate(document, start=1):
            rows.extend(page_rows(page, index))
    json.dump(rows, _RESULT)
    _RESULT.flush()
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv))
