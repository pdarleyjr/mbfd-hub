"""Cell parsers for the supply room workbook import.

Pure functions, no file access. The shapes mirror database/data/ems/captain5_supply_room_inventory.json
(parsed.threshold / parsed.package / parsed.order) so one convention covers the PDF extraction and the
workbook. A value that cannot be read with certainty comes back as None or as an explicit problem,
never as a guess.
"""

from __future__ import annotations

import re
from typing import Any

PACKAGE_UNITS = ("box", "case", "pack", "bag")

# Spellings seen (or reasonably expected) next to a number, mapped to a unit.
_UNIT_WORDS = {
    "u": "each", "unit": "each", "units": "each", "ea": "each", "each": "each",
    "bx": "box", "box": "box", "boxes": "box", "bxs": "box",
    "cs": "case", "case": "case", "cases": "case",
    "pk": "pack", "pks": "pack", "pack": "pack", "packs": "pack",
    "bg": "bag", "bgs": "bag", "bag": "bag", "bags": "bag",
}


def norm(value: Any) -> str:
    """Whitespace-collapsed text, the form used for every comparison and for stored text."""
    if value is None:
        return ""
    return re.sub(r"\s+", " ", str(value).replace("\xa0", " ")).strip()


def cell_text(value: Any) -> str | None:
    """Cell as stored text: numbers become plain digits; blank becomes None."""
    if value is None:
        return None
    if isinstance(value, bool):
        return str(value)
    if isinstance(value, float) and value.is_integer():
        value = int(value)
    text = norm(value)
    return text or None


def unit_of(word: str) -> str | None:
    return _UNIT_WORDS.get(word.strip().strip(".").lower())


def plural(unit: str | None) -> str:
    """Plain-language plural for messages: box -> boxes."""
    return {"box": "boxes", "case": "cases", "pack": "packs", "bag": "bags"}.get(unit or "", "packages")


# ------------------------------------------------------------------ Count sheet quantity

_QTY_UNIT = re.compile(r"^(\d+)\s*([A-Za-z]+)\.?(?:\s*\((\d+)\))?$")


def parse_quantity(raw: Any) -> dict[str, Any]:
    """The QUANTITY cell of the Count sheet.

    kind: blank | bare | unit | invalid. Blank is never zero. An integer cell is a bare number.
    "N bx", "Nbx", "N Bx", "N bx (50)" are N in that unit (the bracket is the units-per-package note).
    Anything else is invalid and becomes an exception.
    """
    if raw is None or (isinstance(raw, str) and raw.strip() == ""):
        return {"kind": "blank", "value": None, "unit": None, "units_note": None}
    if isinstance(raw, bool):
        return {"kind": "invalid", "value": None, "unit": None, "units_note": None}
    if isinstance(raw, (int, float)):
        if float(raw).is_integer() and raw >= 0:
            return {"kind": "bare", "value": int(raw), "unit": None, "units_note": None}
        return {"kind": "invalid", "value": None, "unit": None, "units_note": None}
    text = norm(raw)
    if re.fullmatch(r"\d+", text):
        return {"kind": "bare", "value": int(text), "unit": None, "units_note": None}
    match = _QTY_UNIT.match(text)
    if match:
        unit = unit_of(match.group(2))
        if unit is not None:
            note = int(match.group(3)) if match.group(3) else None
            return {"kind": "unit", "value": int(match.group(1)), "unit": unit, "units_note": note}
    return {"kind": "invalid", "value": None, "unit": None, "units_note": None}


# ------------------------------------------------------------------ ORDER LEVEL (threshold)

_THRESHOLD = re.compile(r"^<\s*(\d+)\s*(.*)$")


def parse_threshold(text: str | None) -> dict[str, Any] | None:
    """ORDER LEVEL text such as "< 15U", "< 2BX", "< 20", "< 2 bx/ 48 U", "< 20U BOXES".

    Returns None when there is no number (for example "As Needed" or "BOXES").
    unit is each|box|case|pack|bag or None when the text names no unit. unit_text keeps what followed the number.
    """
    if text is None:
        return None
    match = _THRESHOLD.match(norm(text))
    if not match:
        return None
    qty, rest = int(match.group(1)), match.group(2).strip()
    result: dict[str, Any] = {"qty": qty, "unit": None, "comparison": "lt", "unit_text": rest or None}
    if rest == "":
        return result
    first = re.match(r"^([A-Za-z]+)\.?(.*)$", rest)
    if first is None:
        return result
    unit = unit_of(first.group(1))
    tail = first.group(2).strip()
    if unit is None:
        return result
    if tail in ("", "BOXES"):
        # "< 20U BOXES": the unit is U, the word only says the glove boxes are the units.
        result["unit"] = unit
        return result
    alt = re.match(r"^/\s*(\d+)\s*([A-Za-z]+)\.?$", tail)
    if alt and unit_of(alt.group(2)) is not None:
        result["unit"] = unit
        result["alt"] = {"qty": int(alt.group(1)), "unit": unit_of(alt.group(2))}
        return result
    # A number followed by other words: the unit is not certain.
    return result


def mentions_package(text: str | None) -> bool:
    """True when the order level text mentions a package unit anywhere, for example "(1 box)"."""
    return any(re.search(rf"\b{word}\b", norm(text).lower()) for word in ("box", "boxes", "bx", "case", "cs", "pack", "pk", "pks", "bag", "bg"))


def threshold_view(text: str | None, package_unit: str | None, units_per_package: int | None, controlled: bool) -> dict[str, Any]:
    """The workbook order level as the Hub will hold it.

    status: converted | assumed_each | explicit_equivalent | not_converted | as_needed | none | controlled.
    class: each | package | none, which decides whether a bare count is ambiguous (see count rules).
    ORDER AMOUNT is never used here.
    """
    base: dict[str, Any] = {"text": norm(text) or None, "qty_base": None, "status": "none", "class": "none", "unit": None, "reason": None}
    if controlled:
        return {**base, "status": "controlled", "reason": "Controlled substance: no order level is kept here."}
    if base["text"] is None:
        return base
    parsed = parse_threshold(base["text"])
    if parsed is None:
        if base["text"].lower() == "as needed":
            return {**base, "status": "as_needed", "reason": "Marked As Needed; there is no order level to compare."}
        return {**base, "status": "not_converted", "reason": f'Order level "{base["text"]}" has no number.'}
    unit, qty = parsed["unit"], parsed["qty"]
    if unit == "each":
        return {**base, "qty_base": qty, "status": "converted", "class": "each", "unit": "each"}
    if unit is None:
        if mentions_package(parsed.get("unit_text")):
            return {**base, "status": "not_converted", "class": "package", "reason": f'Order level "{base["text"]}" mentions a package but names no unit for the number.'}
        return {**base, "qty_base": qty, "status": "assumed_each", "class": "each", "unit": "each",
                "reason": f'Order level "{base["text"]}" has no unit; read as individual units.'}
    # A package unit.
    alt = parsed.get("alt")
    if unit == package_unit and units_per_package:
        return {**base, "qty_base": qty * units_per_package, "status": "converted", "class": "package", "unit": unit}
    if alt and alt["unit"] == "each":
        return {**base, "qty_base": alt["qty"], "status": "explicit_equivalent", "class": "package", "unit": unit,
                "reason": f'Order level "{base["text"]}" states the number of individual units itself ({alt["qty"]}); that was used.'}
    return {**base, "status": "not_converted", "class": "package", "unit": unit,
            "reason": f'Order level "{base["text"]}" is in {plural(unit)} but the number of units per {unit} is not stated on this row, so it was not converted.'}


# ------------------------------------------------------------------ QTY PER BOX/CS (package)

_PACKAGE = re.compile(r"^(\d+)\s*U?\s*/?\s*([A-Za-z]+)$")


def parse_package(raw: Any) -> dict[str, Any]:
    """QTY PER BOX/CS. A factor is taken only when the text states it with a package unit."""
    text = cell_text(raw)
    empty = {"unit": None, "base_units_per_package": None, "inner_packages": None}
    if text is None:
        return empty
    if text.lower() in ("ea", "each"):
        return {**empty, "unit": "each"}
    if re.fullmatch(r"\d+", text):
        # A bare number names no unit: the factor is kept, the unit stays unknown.
        return {**empty, "base_units_per_package": int(text)}
    inner = re.fullmatch(r"1\s*/\s*([A-Za-z]+)\s*=\s*(\d+)\s*([A-Za-z]+)", text)
    if inner and unit_of(inner.group(1)) in PACKAGE_UNITS and unit_of(inner.group(3)) in PACKAGE_UNITS:
        return {**empty, "unit": unit_of(inner.group(1)), "inner_packages": {"count": int(inner.group(2)), "unit": unit_of(inner.group(3))}}
    match = _PACKAGE.match(text)
    if match:
        unit = unit_of(match.group(2))
        if unit in PACKAGE_UNITS:
            return {**empty, "unit": unit, "base_units_per_package": int(match.group(1))}
    return empty


# ------------------------------------------------------------------ ORDER AMOUNT (kept for the record only)

_ORDER_SIMPLE = re.compile(r"^(\d+)\s*([A-Za-z]+)\s*=\s*(\d+)\s*([A-Za-z]*)$")


def parse_order(raw: Any) -> dict[str, Any]:
    """ORDER AMOUNT, recorded for reference. It is never an order level."""
    text = cell_text(raw)
    out: dict[str, Any] = {"text_qty": None, "text_unit": None, "base_units": None}
    if text is None:
        return out
    match = _ORDER_SIMPLE.match(text)
    if match and unit_of(match.group(2)) in PACKAGE_UNITS:
        if unit_of(match.group(4) or "u") == "each":
            return {"text_qty": int(match.group(1)), "text_unit": unit_of(match.group(2)), "base_units": int(match.group(3))}
        # "1CS = 300CS": the result is packages, not units, so no unit count is read from it.
        return {"text_qty": int(match.group(1)), "text_unit": unit_of(match.group(2)), "base_units": None}
    units_first = re.fullmatch(r"(\d+)\s*(?:u|units?)\s*/\s*(\d+)\s*([A-Za-z]+)", text, re.IGNORECASE)
    if units_first and unit_of(units_first.group(3)) in PACKAGE_UNITS:
        return {"text_qty": int(units_first.group(2)), "text_unit": unit_of(units_first.group(3)), "base_units": int(units_first.group(1))}
    packages_first = re.fullmatch(r"(\d+)\s*([A-Za-z]+)\s*/\s*(\d+)\s*(?:u|units?)", text, re.IGNORECASE)
    if packages_first and unit_of(packages_first.group(2)) in PACKAGE_UNITS:
        return {"text_qty": int(packages_first.group(1)), "text_unit": unit_of(packages_first.group(2)), "base_units": int(packages_first.group(3))}
    compound = re.fullmatch(r"(\d+)\s*([A-Za-z]+)\s*X\s*(\d+)\s*([A-Za-z]+)s?\s*=\s*(\d+)\s*U", text, re.IGNORECASE)
    if compound and unit_of(compound.group(2)) in PACKAGE_UNITS:
        return {"text_qty": int(compound.group(1)), "text_unit": unit_of(compound.group(2)),
                "inner_qty": int(compound.group(3)), "inner_unit": unit_of(compound.group(4)), "base_units": int(compound.group(5))}
    unit_only = re.fullmatch(r"(\d+)\s*(?:U|units?)", text, re.IGNORECASE)
    if unit_only:
        return {**out, "base_units": int(unit_only.group(1))}
    return out
