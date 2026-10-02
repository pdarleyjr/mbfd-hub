"""Stage immutable, losslessly extracted policy PDFs and an explicit page manifest.

The source paths are read-only. qpdf copies original pages; PyMuPDF reads and
verifies their text and raster appearance. Neither OCR nor raster replacement is
used for serving assets.
"""

import argparse
import hashlib
import json
import re
import shutil
import subprocess
import sys
import zipfile
from collections import defaultdict
from datetime import datetime, timezone
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "var" / "python"))
import pymupdf
from PIL import Image, ImageChops

INITIAL_MOMS = Path(r"C:\Users\Peter Darley\Downloads\MOMS-compressed.pdf")
INITIAL_SOG = Path(r"E:\Organized Files\MBFD\Policies & Procedures\SOG Analysis\SOG Files")
VERSION = re.compile(r"(?<![A-Za-z0-9])(?:version|v)\s*(\d+(?:\.\d+)*)(?!\d)", re.I)
HEADER = re.compile(r"POLICY NUMBER:?\s+([^\n]+)\s+TITLE:?\s+(.+?)\s+ISSUE DATE", re.S)
MENU_LINE = re.compile(r"\s*(\d+)\.\s*(.+?)\s*\.+\s*(inserted after page|page)\s+(\d+)\s*$", re.I)
EXCLUDED_PARTS = {"sources", "research", "control", "historical_review_evidence", "prior_review_evidence", "historical_rd2", "historical_transfer_evidence", "receiving_owner_transfer"}
COMPANIONS = {
    "mbfd_900_company_standards": "Company Standards",
    "mbfd_900_de_task_book": "Driver Engineer Task Book",
    "mbfd_900_training_control_forms": "Training Control Forms",
}
CONTROL_COMPANION_PACKAGE = "mbfd_section_100_references_companions"
CONTROL_COMPANION_POLICIES = {"100.XX-GOV-A1", "100.XX-GOV-F1"}
MOMS_SECTIONS = [
    ("ADULT MEDICAL", "Adult Medical Protocols", "protocol"),
    ("ADULT TRAUMA", "Adult Trauma Protocols", "protocol"),
    ("PEDIATRIC MEDICAL", "Pediatric Medical Protocols", "protocol"),
    ("PEDIATRIC TRAUMA", "Pediatric Trauma Protocols", "protocol"),
    ("MEDICATIONS", "Medications", "medication"),
    ("PROCEDURES", "Procedures", "procedure"),
    ("ADMINISTRATIVE", "Administrative", "administrative item"),
    ("ADULT MEDICAL ALGORITHMS", "Adult Medical Algorithms", "algorithm"),
    ("ADULT TRAUMA ALGORITHMS", "Adult Trauma Algorithms", "algorithm"),
    ("PEDIATRIC MEDICAL ALGORITHMS", "Pediatric Medical Algorithms", "algorithm"),
    ("PEDIATRIC TRAUMA ALGORITHMS", "Pediatric Trauma Algorithms", "algorithm"),
]


def normalized(text):
    return re.sub(r"[^a-z0-9]+", " ", text.lower()).strip()


def slug(text):
    return re.sub(r"[^a-z0-9]+", "-", text.lower()).strip("-")


def digest(path):
    result = hashlib.sha256()
    with Path(path).open("rb") as stream:
        for block in iter(lambda: stream.read(1024 * 1024), b""):
            result.update(block)
    return result.hexdigest()


def numeric_version(path):
    versions = [tuple(map(int, match[1].split("."))) for match in VERSION.finditer(str(path))]
    return max(versions, default=())


def version_key(version):
    return tuple(version) + (0,) * (8 - len(version))


def latest_candidate(candidates):
    if not candidates:
        raise ValueError("No policy candidates")
    version = max((c["version"] for c in candidates), key=version_key)
    current = [c for c in candidates if version_key(c["version"]) == version_key(version)]
    if len({c["sha256"] for c in current}) > 1:
        raise ValueError("Conflicting PDFs have the same highest explicit version: " + ", ".join(c["path"] for c in current))
    return sorted(current, key=lambda c: (bool(c.get("archive_member")), len(Path(c["path"]).parts), c["path"].casefold()))[0]


def run_qpdf(binary, args, allow_warnings=False):
    result = subprocess.run([str(binary), *map(str, args)], capture_output=True, text=True, check=False)
    if result.returncode != 0 and not (allow_warnings and result.returncode == 3):
        raise RuntimeError(f"qpdf failed ({result.returncode}): {result.stderr.strip()}")
    return {"exit_code": result.returncode, "stdout": result.stdout.strip(), "stderr": result.stderr.strip()}


def archive_source(source, output, sources):
    source_hash = digest(source)
    relative = f"sources/{source_hash}.pdf"
    destination = output / relative
    if not destination.exists():
        shutil.copyfile(source, destination)
    if digest(destination) != source_hash:
        raise ValueError("Archived source hash mismatch")
    if not any(s["sha256"] == source_hash for s in sources):
        with pymupdf.open(source) as pdf:
            sources.append({"source_filename": source.name, "original_path": str(source), "source_path": relative, "sha256": source_hash, "page_count": len(pdf)})
    return relative, source_hash


def extract_document(binary, source, output, start, end, title, document_slug, pages, source_path, version_label, revision_date, metadata, verify_render, linearize=False):
    temporary = output / "assets" / "extracting.pdf"
    run_qpdf(binary, [*(["--linearize"] if linearize else []), "--empty", "--deterministic-id", "--object-streams=generate", "--remove-unreferenced-resources=no", "--pages", source, f"{start}-{end}", "--", temporary])
    extracted_hash = digest(temporary)
    destination = output / "assets" / f"{extracted_hash}.pdf"
    if destination.exists():
        if digest(destination) != extracted_hash:
            raise ValueError("Immutable asset hash conflict")
        temporary.unlink()
    else:
        temporary.replace(destination)
    maximum_raster_delta = 0
    with pymupdf.open(source) as original, pymupdf.open(destination) as extracted:
        if len(extracted) != end - start + 1:
            raise ValueError("Extracted page count mismatch")
        for i, page in enumerate(extracted):
            expected = original[start - 1 + i]
            if (page.rect, page.mediabox, page.cropbox, page.artbox, page.trimbox, page.bleedbox, page.rotation) != (expected.rect, expected.mediabox, expected.cropbox, expected.artbox, expected.trimbox, expected.bleedbox, expected.rotation) or page.get_text() != expected.get_text():
                raise ValueError(f"Extracted page/text mismatch: {title}, physical {start + i}")
            if [extracted.xref_stream(x) for x in page.get_contents()] != [original.xref_stream(x) for x in expected.get_contents()]:
                raise ValueError(f"Original decoded page-content stream changed: {title}, physical {start + i}")
            source_fonts = sorted((font[3:6], hashlib.sha256(original.extract_font(font[0])[3]).hexdigest()) for font in expected.get_fonts())
            asset_fonts = sorted((font[3:6], hashlib.sha256(extracted.extract_font(font[0])[3]).hexdigest()) for font in page.get_fonts())
            if source_fonts != asset_fonts:
                raise ValueError(f"Original embedded font changed: {title}, physical {start + i}")
            source_images = sorted((image[2:9], hashlib.sha256(original.xref_stream(image[0])).hexdigest(), hashlib.sha256(original.xref_stream(image[1])).hexdigest() if image[1] else None) for image in expected.get_images())
            asset_images = sorted((image[2:9], hashlib.sha256(extracted.xref_stream(image[0])).hexdigest(), hashlib.sha256(extracted.xref_stream(image[1])).hexdigest() if image[1] else None) for image in page.get_images())
            if source_images != asset_images:
                raise ValueError(f"Original embedded image changed: {title}, physical {start + i}")
            if verify_render:
                actual_pixmap = page.get_pixmap(alpha=False)
                expected_pixmap = expected.get_pixmap(alpha=False)
                if actual_pixmap.samples != expected_pixmap.samples:
                    actual_image = Image.frombytes("RGB", (actual_pixmap.width, actual_pixmap.height), actual_pixmap.samples)
                    expected_image = Image.frombytes("RGB", (expected_pixmap.width, expected_pixmap.height), expected_pixmap.samples)
                    delta = max(channel[1] for channel in ImageChops.difference(actual_image, expected_image).getextrema())
                    maximum_raster_delta = max(maximum_raster_delta, delta)
                    raise ValueError(f"Raster pixels changed despite resource preservation: {title}, physical {start + i}; maximum channel delta {delta}")
    return {
        "title": title,
        "slug": document_slug,
        "source_path": source_path,
        "asset_path": f"assets/{extracted_hash}.pdf",
        "sha256": extracted_hash,
        "page_count": end - start + 1,
        "version_label": version_label,
        "revision_date": revision_date,
        "pages": pages,
        "metadata": {**metadata, "source_filename": source.name, "physical_start_page": start, "physical_end_page": end, "validation": {"page_count": "pass", "text_and_geometry": "pass", "decoded_content_stream_identity": "pass", "embedded_font_and_image_stream_identity": "pass", "render_comparison": "measured" if verify_render else "not run", "maximum_raster_channel_delta": maximum_raster_delta if verify_render else None}},
    }


def sog_printed_label(page, text):
    printed = re.search(r"Page\s+(\d+)\s+of\s+\d+", text, re.I)
    if printed:
        return printed[1]
    footer = page.get_text(clip=pymupdf.Rect(0, page.rect.height - 45, page.rect.width, page.rect.height)).strip()
    return footer if re.fullmatch(r"\d+", footer) else None


def sog_sources(root):
    inventory = []
    groups = defaultdict(list)
    for path in sorted(root.rglob("*.pdf")):
        relative = path.relative_to(root)
        stem = path.stem.lower()
        section = re.fullmatch(r"mbfd_section_(\d{3})(?:_r\d+(?:\.\d+)*)?", stem)
        logical = f"section-{section[1]}" if section else stem if stem in COMPANIONS or stem == CONTROL_COMPANION_PACKAGE else None
        excluded = any(part.lower() in EXCLUDED_PARTS or part.lower().startswith("historical") for part in relative.parts[:-1])
        candidate = {
            "path": str(path), "source_folder": str(path.parent), "relative_path": str(relative),
            "logical_document": logical, "version": numeric_version(relative), "sha256": digest(path),
            "modified_at": datetime.fromtimestamp(path.stat().st_mtime, timezone.utc).isoformat(),
            "classification": "historical or source evidence" if excluded else "policy candidate" if logical else "supporting administrative or research artifact",
        }
        with pymupdf.open(path) as pdf:
            candidate["page_count"] = len(pdf)
            candidate["title_evidence"] = pdf[0].get_text()[:800] if len(pdf) else ""
        inventory.append(candidate)
        if logical and not excluded:
            groups[logical].append(candidate)
    archive_inventory = []
    for archive_path in sorted(root.rglob("*.zip")):
        container_hash = digest(archive_path)
        count = 0
        with zipfile.ZipFile(archive_path) as archive:
            for member in sorted(archive.namelist()):
                if not member.lower().endswith(".pdf"):
                    continue
                count += 1
                relative = Path(archive_path.relative_to(root)) / Path(member)
                stem = Path(member).stem.lower()
                section = re.fullmatch(r"mbfd_section_(\d{3})(?:_r\d+(?:\.\d+)*)?", stem)
                logical = f"section-{section[1]}" if section else stem if stem in COMPANIONS or stem == CONTROL_COMPANION_PACKAGE else None
                excluded = any(part.lower() in EXCLUDED_PARTS or part.lower().startswith("historical") for part in relative.parts[:-1])
                data = archive.read(member)
                candidate = {"path": str(archive_path) + "!" + member, "source_folder": str(archive_path.parent), "relative_path": str(relative), "logical_document": logical, "version": numeric_version(relative), "sha256": hashlib.sha256(data).hexdigest(), "modified_at": datetime.fromtimestamp(archive_path.stat().st_mtime, timezone.utc).isoformat(), "classification": "historical or source evidence" if excluded else "policy candidate" if logical else "supporting administrative or research artifact", "archive_path": str(archive_path), "archive_member": member, "container_sha256": container_hash}
                with pymupdf.open(stream=data, filetype="pdf") as pdf:
                    candidate["page_count"] = len(pdf)
                    candidate["title_evidence"] = pdf[0].get_text()[:800] if len(pdf) else ""
                inventory.append(candidate)
                if logical and not excluded:
                    groups[logical].append(candidate)
        archive_inventory.append({"path": str(archive_path), "sha256": container_hash, "pdf_members_inspected": count, "status": "inspected read-only"})
    for candidate in inventory:
        parts = Path(candidate["relative_path"]).parts
        if "references" in [p.lower() for p in parts] and Path(candidate["relative_path"]).name.startswith("300.32"):
            candidate["classification"] = "legacy cross-section reference copy"
            candidate["exclusion_reason"] = "Original policy dated 11/4/2025, printed pages219-220; same bytes copied into Section100 packages. Containing Section100 version is not a Section300 policy version. Canonical current300.32 is inside selected Section300 replacement."
        elif candidate["classification"] != "policy candidate":
            candidate["exclusion_reason"] = "Source, historical, research, control, planning or administrative evidence; not a current operational policy PDF or controlled companion"
    # Unversioned consolidated copies inherit a version only on exact byte identity.
    for candidates in groups.values():
        for candidate in candidates:
            if not candidate["version"]:
                exact = [c["version"] for c in candidates if c["version"] and c["sha256"] == candidate["sha256"]]
                if exact:
                    candidate["version"] = max(exact, key=version_key)
                    candidate["version_evidence"] = "Exact SHA-256 identity with an explicit versioned PDF"
    return inventory, groups, archive_inventory


def import_sog(args, output, sources, audits):
    inventory, groups, archive_inventory = sog_sources(args.sog)
    sections = []
    for logical, candidates in sorted(groups.items()):
        selected = latest_candidate(candidates)
        if selected.get("archive_member"):
            source = output / "inputs" / selected["sha256"] / Path(selected["archive_member"]).name
            source.parent.mkdir(parents=True, exist_ok=True)
            with zipfile.ZipFile(selected["archive_path"]) as archive:
                source.write_bytes(archive.read(selected["archive_member"]))
        else:
            source = Path(selected["path"])
        source_path, source_hash = archive_source(source, output, sources)
        version_label = "V" + ".".join(map(str, selected["version"])) if selected["version"] else "Unversioned"
        audit = {"logical_document": logical, "candidates": candidates, "selected": selected, "selection_reason": "Highest explicit numeric version; identical highest-version copies choose shortest then casefolded lexical path", "pdf_validation": run_qpdf(args.qpdf, ["--check", source], allow_warnings=True), "warnings": []}
        if any(c["sha256"] == selected["sha256"] and version_key(c["version"]) != version_key(selected["version"]) for c in candidates):
            audit["warnings"].append("Identical PDF bytes also exist under an older explicit version; the highest folder version is retained")
        audits.append(audit)
        with pymupdf.open(source) as pdf:
            pages_text = [page.get_text() for page in pdf]
            if logical == CONTROL_COMPANION_PACKAGE:
                starts = []
                previous = None
                for i, text in enumerate(pages_text):
                    match = HEADER.search(text[:1400])
                    if match and match[1].strip() in CONTROL_COMPANION_POLICIES and match[1].strip() != previous:
                        previous = match[1].strip()
                        starts.append((i + 1, previous + " - " + re.sub(r"\s+", " ", match[2]).strip()))
                if len(starts) != len(CONTROL_COMPANION_POLICIES):
                    raise ValueError("Governance companion package does not contain both controlled documents")
                section = {"title": "100 - Policy Governance Companions", "slug": "governance-companions", "children": [], "documents": []}
                sections.append(section)
                exclusion = {"physical_pages": list(range(1, starts[0][0])), "reason": "100.03-R00 and R01-R08 are superseded by the same logical references in selected Section100V5; unique GOV-A1 and GOV-F1 companions remain selected at V1"}
                next(s for s in sources if s["sha256"] == source_hash)["coverage_exclusions"] = [exclusion]
                audit["range_exclusions"] = [exclusion]
            elif logical.startswith("section-"):
                starts = []
                previous = None
                for i, text in enumerate(pages_text):
                    match = HEADER.search(text[:1400])
                    if match and match[1].strip() != previous:
                        previous = match[1].strip()
                        starts.append((i + 1, previous + " - " + re.sub(r"\s+", " ", match[2]).strip()))
                if not starts or starts[0][0] != 1:
                    raise ValueError(f"No complete policy header map for {source}")
                division = re.search(r"DIVISION:?\s+(.+?)\s+POLICY NUMBER", pages_text[0], re.S)
                division_title = re.sub(r"\s+", " ", division[1]).strip() if division else logical
                section_number = logical.split("-")[1]
                division_title = re.sub(r"^\d{3}\s*[-–—]\s*", "", division_title)
                section = {"title": f"{section_number} - {division_title.title() if division_title.isupper() else division_title}", "slug": logical, "children": [], "documents": []}
                sections.append(section)
            else:
                starts = [(1, COMPANIONS[logical])]
                section = next(s for s in sections if s["slug"] == "section-900") if any(s["slug"] == "section-900" for s in sections) else None
                if section is None:
                    # Companion names sort before section names; defer them until their parent exists.
                    section = next((s for s in sections if s["slug"] == "training-companions"), None)
                    if section is None:
                        section = {"title": "900 - Training Companions", "slug": "training-companions", "children": [], "documents": []}
                        sections.append(section)
            for index, (start, title) in enumerate(starts):
                end = starts[index + 1][0] - 1 if index + 1 < len(starts) else len(pdf)
                pages = []
                for n in range(start, end + 1):
                    pages.append({"physical_page": n, "printed_label": sog_printed_label(pdf[n - 1], pages_text[n - 1]), "title": title, "text": pages_text[n - 1]})
                section["documents"].append(extract_document(args.qpdf, source, output, start, end, title, slug(title), pages, source_path, version_label, None, {"source_sha256": source_hash, "logical_source_document": logical, "mapping_evidence": "Repeated policy number/title headers"}, args.verify_render, args.linearize))
        print(f"SOG {logical}: {version_label}, {selected['page_count']} pages", flush=True)
    companion_sections = [s for s in sections if s["slug"] == "training-companions"]
    training = next((s for s in sections if s["slug"] == "section-900"), None)
    if training:
        for section in companion_sections:
            training["children"].append(section)
            sections.remove(section)
    governance = next((s for s in sections if s["slug"] == "governance-companions"), None)
    chief = next((s for s in sections if s["slug"] == "section-100"), None)
    if governance and chief:
        chief["children"].append(governance)
        sections.remove(governance)
    audits.append({"kind": "sog_inventory", "pdf_count": len(inventory), "loose_pdf_count": sum(not bool(c.get("archive_member")) for c in inventory), "archive_pdf_count": sum(bool(c.get("archive_member")) for c in inventory), "files": inventory, "archives": archive_inventory, "warnings": ["No Section 700 policy PDF was supplied"] if not any(s["slug"] == "section-700" for s in sections) else []})
    return {"slug": "sogs", "name": "Standard Operating Guidelines", "type": "sog", "sections": sections}, inventory


def footer_label(page):
    text = page.get_text(clip=pymupdf.Rect(page.rect.width - 112, page.rect.height - 50, page.rect.width, page.rect.height)).strip()
    tokens = re.findall(r"(?<!\w)(inserted|\d{1,4})(?!\w)", text, re.I)
    return tokens[-1].lower() if len(tokens) == 1 else None


def section_for_menu(text):
    heading = " ".join(text.split())[:420].upper()
    for prefix, title, kind in reversed(MOMS_SECTIONS):
        if prefix in heading and "MENU" in heading:
            return title, kind
    return None


def blank_page(page):
    text = page.get_text(clip=pymupdf.Rect(0, 50, page.rect.width, page.rect.height - 70))
    return not text.strip() and not page.get_images()


def menu_link_target(page, menu_number):
    rectangles = []
    for block in page.get_text("dict")["blocks"]:
        for line in block.get("lines", []):
            text = "".join(span["text"] for span in line["spans"])
            match = MENU_LINE.match(text)
            if match and int(match[1]) == menu_number:
                rectangles.append(pymupdf.Rect(line["bbox"]))
    targets = {
        link["page"] + 1 for link in page.get_links()
        if link.get("kind") == pymupdf.LINK_GOTO and link.get("page", -1) >= 0
        and any(link["from"].intersects(rectangle) for rectangle in rectangles)
    }
    if len(targets) > 1:
        raise ValueError(f"Ambiguous embedded PDF menu links for menu entry {menu_number}")
    return next(iter(targets), None)


def validate_protocol_start(entry, page, has_menu_link, bookmark_titles=()):
    heading = page.get_text(clip=pymupdf.Rect(0, 15, page.rect.width, 100))
    stopwords = {"and", "the", "or", "of", "with", "to", "after", "adult", "pediatric", "pedi", "city", "ctiy", "key", "only", "biscayne", "miami", "beach", "hialeah", "coral", "gables", "for", "formerly", "revised"}
    def tokens(text):
        return {word[:6] if len(word) > 6 else word for word in normalized(text).split() if word not in stopwords}
    title_tokens = tokens(entry["title"])
    core_tokens = tokens(re.sub(r"\([^)]*\)", "", entry["title"]))
    actual_tokens = tokens(heading)
    overlap = max(len(title_tokens & actual_tokens) / max(len(title_tokens), 1), len(core_tokens & actual_tokens) / max(len(core_tokens), 1))
    bookmark_overlap = max((max(len(title_tokens & tokens(title)) / max(len(title_tokens), 1), len(core_tokens & tokens(title)) / max(len(core_tokens), 1)) for title in bookmark_titles), default=0)
    furniture = {"COMMON EMS PROTOCOLS", "EMS COMMON PROTOCOLS", "ADULT PROTOCOLS", "PEDIATRIC PROTOCOLS", "MEDICATIONS", "PROCEDURES", "ADMINISTRATIVE", "PREVIOUS", "NEXT", "MAIN"}
    primary_lines = [line.strip() for line in heading.splitlines() if line.strip() and line.strip().upper() not in furniture and not line.strip().upper().startswith("COMMON EMS PROTOCOLS")]
    primary_tokens = tokens(primary_lines[0]) if primary_lines else set()
    first_core_word = next((word[:6] if len(word) > 6 else word for word in normalized(re.sub(r"\([^)]*\)", "", entry["title"])).split() if word not in stopwords), None)
    concise_primary_title = bool(has_menu_link and first_core_word in primary_tokens and len(primary_tokens) <= 3)
    page_number = re.search(r"Page\s+(\d+)\s+of\s+(\d+)", page.get_text(), re.I)
    if page_number and int(page_number[1]) != 1:
        raise ValueError(f"Selected protocol start is Page {page_number[1]}, not Page 1: {entry['title']}")
    image_document = entry["classification"] == "administrative item" and bool(page.get_images())
    if overlap < 0.45 and not concise_primary_title and not (image_document and has_menu_link):
        raise ValueError(f"Selected PDF heading does not identify the menu entry: {entry['title']} (heading-token overlap {overlap:.3f})")
    return {"actual_heading": heading.strip(), "heading_token_overlap": overlap, "concise_primary_title_verified": concise_primary_title, "bookmark_titles": list(bookmark_titles), "bookmark_title_overlap": bookmark_overlap, "protocol_page_number": int(page_number[1]) if page_number else None, "protocol_page_count": int(page_number[2]) if page_number else None, "embedded_menu_link_verified": has_menu_link, "image_document": image_document}


def import_moms(args, output, sources, audits):
    source_path, source_hash = archive_source(args.moms, output, sources)
    source_validation = run_qpdf(args.qpdf, ["--check", args.moms], allow_warnings=True)
    with pymupdf.open(args.moms) as pdf:
        texts = [page.get_text() for page in pdf]
        bookmarks = defaultdict(list)
        for _, bookmark_title, destination in pdf.get_toc():
            if destination > 0:
                bookmarks[destination].append(bookmark_title)
        labels = [footer_label(page) for page in pdf]
        label_map = defaultdict(list)
        for index, label in enumerate(labels):
            if label and label != "inserted":
                label_map[label].append(index + 1)
        sections = [{"title": "Front Matter", "slug": "front-matter", "children": [], "documents": []}]
        menus = []
        entries = []
        for index, text in enumerate(texts):
            section_info = section_for_menu(text)
            if not section_info:
                continue
            title, kind = section_info
            if not menus or menus[-1]["title"] != title:
                section = {"title": title, "slug": slug(title), "children": [], "documents": []}
                sections.append(section)
                menus.append({"physical_page": index + 1, "title": title, "kind": kind, "section": section, "entries": []})
            for line in text.splitlines():
                match = MENU_LINE.match(line)
                if match:
                    entry = {"menu_number": int(match[1]), "title": re.sub(r"\s+", " ", match[2]).strip(), "menu_physical_page": index + 1, "printed_toc_label": match[4], "inserted": match[3].lower().startswith("inserted"), "section": title, "classification": kind}
                    menus[-1]["entries"].append(entry)
                    entries.append(entry)
        if len(menus) != len(MOMS_SECTIONS):
            raise ValueError(f"Expected all 11 manual menu categories; found {len(menus)}")
        for menu in menus:
            if [e["menu_number"] for e in menu["entries"]] != list(range(1, len(menu["entries"]) + 1)) or not menu["entries"]:
                raise ValueError(f"Incomplete menu-entry sequence: {menu['title']}")
        starts = [(1, "2025-2027 Common EMS Protocols Cover", sections[0], "front matter"), (2, "Common EMS Protocols Feedback Form", sections[0], "front matter"), (3, "Miami Beach Medical Operations Manual", sections[0], "front matter"), (5, "Introduction", sections[0], "front matter"), (7, "Acknowledgements", sections[0], "front matter"), (9, "Medical Director Overrides", sections[0], "front matter"), (11, "Manual Home Menu", sections[0], "menu")]
        if source_hash != "26124333fee98799515fd91239a77bdaae96645386e92582e5bb9c0f3fe5cb7d":
            starts = [(1, "Front Matter", sections[0], "front matter")]
        corrections = []
        for menu_index, menu in enumerate(menus):
            lower = menu["physical_page"]
            upper = menus[menu_index + 1]["physical_page"] if menu_index + 1 < len(menus) else len(pdf) + 1
            starts.append((lower, menu["title"] + " Menu", menu["section"], "menu"))
            for entry in menu["entries"]:
                candidates = [n for n in label_map[entry["printed_toc_label"]] if lower < n < upper]
                linked = menu_link_target(pdf[entry["menu_physical_page"] - 1], entry["menu_number"])
                if linked is not None and not lower < linked < upper:
                    raise ValueError(f"Embedded menu link leaves its section: {entry['title']}")
                if entry["inserted"]:
                    candidates = [n for n in range(lower + 1, upper) if labels[n - 1] == "inserted" and re.search(r"Page\s+1\s+of\s+\d+", texts[n - 1], re.I)]
                if linked is not None:
                    if candidates and linked not in candidates:
                        corrections.append({"title": entry["title"], "menu_printed_label": entry["printed_toc_label"], "actual_printed_label": labels[linked - 1], "physical_page": linked, "reason": "Printed TOC label disagrees with embedded PDF menu destination; actual destination independently verified against its protocol heading and Page 1 signal"})
                    candidates = [linked]
                    entry["mapping_evidence"] = "Embedded original PDF menu link, bounded section, actual protocol heading and protocol-relative page signal; printed footer independently recorded when present"
                if len(candidates) != 1:
                    # Some source pages have no printed footer: locate their actual first-page
                    # title in the bounded section and require its own Page 1 of N signal.
                    tokens = set(normalized(entry["title"]).split()) - {"and", "the", "or", "of", "adult", "pediatric"}
                    candidates = []
                    for n in range(lower + 1, upper):
                        heading = pdf[n - 1].get_text(clip=pymupdf.Rect(0, 15, pdf[n - 1].rect.width, 80))
                        overlap = len(tokens & set(normalized(heading).split())) / max(len(tokens), 1)
                        if overlap >= 0.5 and (re.search(r"Page\s+1\s+of\s+\d+", texts[n - 1], re.I) or "ABUSE HOTLINE" in entry["title"] and "FLORIDA ABUSE HOTLINE" in texts[n - 1]):
                            candidates.append(n)
                    entry["mapping_evidence"] = "Menu title, bounded section, actual page heading, protocol page count; printed footer absent"
                if len(candidates) != 1:
                    raise ValueError(f"Ambiguous MOMS entry: {entry['title']}: physical candidates {candidates}")
                physical = candidates[0]
                entry["start_validation"] = validate_protocol_start(entry, pdf[physical - 1], linked is not None, bookmarks[physical])
                entry["physical_start_page"] = physical
                entry.setdefault("mapping_evidence", "Manual menu and actual unique printed-footer label within its bounded section")
                starts.append((physical, entry["title"], menu["section"], "inserted update" if entry["inserted"] else entry["classification"]))
        back = {"title": "Back Matter", "slug": "back-matter", "children": [], "documents": []}
        final_entry_start = max(e["physical_start_page"] for e in entries)
        for n in range(final_entry_start + 1, len(pdf) + 1):
            if "FEEDBACK FORM" in texts[n - 1][:250]:
                starts.append((n, "Common EMS Protocols Feedback Form - Back Matter", back, "front matter"))
            elif "EMERGENCY MEDICAL SERVICES" in texts[n - 1][:400] and "MEDICAL DIRECTOR" in texts[n - 1][:600]:
                starts.append((n, "Common EMS Protocols Back Cover", back, "front matter"))
        if any(item[2] is back for item in starts):
            sections.append(back)
        starts.sort(key=lambda item: item[0])
        if len({item[0] for item in starts}) != len(starts):
            raise ValueError("Overlapping MOMS document starts")
        page_map = []
        revision_match = re.search(r"Version\s+(\d+\.\d+(?:\.\d+)?)", "\n".join(texts[:12]))
        version_label = revision_match[1] if revision_match else None
        date_match = re.search(r"updated on\s+(\d{2})/(\d{2})/(\d{4})", "\n".join(texts[:12]))
        revision_date = f"{date_match[3]}-{date_match[1]}-{date_match[2]}" if date_match else None
        for index, (start, title, section, classification) in enumerate(starts):
            end = starts[index + 1][0] - 1 if index + 1 < len(starts) else len(pdf)
            entry = next((e for e in entries if e["physical_start_page"] == start), None)
            document_slug = section["slug"] + "-" + slug(title)
            pages = []
            for n in range(start, end + 1):
                page_class = "blank/separator" if blank_page(pdf[n - 1]) else classification
                item = {"physical_page": n, "printed_label": labels[n - 1], "title": title, "text": texts[n - 1], "classification": page_class}
                pages.append(item)
                page_map.append({**item, "document_slug": document_slug, "section": section["title"], "protocol_relative_page": n - start + 1})
            metadata = {"source_sha256": source_hash, "classification": classification, "mapping_evidence": entry["mapping_evidence"] if entry else "Actual front matter or section-menu physical page", "printed_toc_label": entry["printed_toc_label"] if entry else labels[start - 1], "start_validation": entry["start_validation"] if entry else None}
            section["documents"].append(extract_document(args.qpdf, args.moms, output, start, end, title, document_slug, pages, source_path, version_label, revision_date, metadata, args.verify_render, args.linearize))
        if [p["physical_page"] for p in page_map] != list(range(1, len(pdf) + 1)):
            raise ValueError("MOMS page map contains a gap or overlap")
        inserted = [d["slug"] for s in sections for d in s["documents"] if d["metadata"]["classification"] == "inserted update"]
        sections.append({"title": "Updates / Inserts", "slug": "updates-inserts", "children": [], "documents": [], "metadata": {"related_document_slugs": inserted, "reason": "Inserted documents retain their authoritative Medications or Procedures menu placement"}})
        audit = {"kind": "moms_mapping", "source_sha256": source_hash, "source_pdf_validation": source_validation, "physical_page_count": len(pdf), "menu_entry_count": len(entries), "menus": [{k: v for k, v in m.items() if k != "section"} for m in menus], "corrections": corrections, "pages": page_map, "validation": {"complete_physical_page_coverage": "pass", "unexplained_overlaps": 0, "unexplained_gaps": 0, "global_offset_used": False, "decoded_content_stream_identity": "pass", "render_comparison": "pass" if args.verify_render else "not run"}}
        (output / "moms-page-map.json").write_text(json.dumps(audit, ensure_ascii=False, indent=2), encoding="utf-8")
        audits.append({k: v for k, v in audit.items() if k != "pages"})
        print(f"MOMS: {len(entries)} menu entries, {len(page_map)} physical pages", flush=True)
    return {"slug": "medical-protocols", "name": "Medical Protocols", "type": "medical", "sections": sections}


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--moms", type=Path, default=INITIAL_MOMS)
    parser.add_argument("--sog", type=Path, default=INITIAL_SOG)
    parser.add_argument("--output", type=Path, required=True)
    parser.add_argument("--qpdf", type=Path, default=shutil.which("qpdf"))
    scope = parser.add_mutually_exclusive_group()
    scope.add_argument("--moms-only", action="store_true")
    scope.add_argument("--sog-only", action="store_true")
    parser.add_argument("--delivery-manifest", type=Path, help="Stage the exact coordinated SOG delivery manifest instead of discovering version folders")
    parser.add_argument("--delivery-sha256", default="4b6f079bf3e392f45e2cf85e2246c268a606e138c10351610583b2677ebd8314", help="Expected SHA-256 of the explicitly selected delivery manifest")
    parser.add_argument("--verify-render", action="store_true")
    parser.add_argument("--linearize", action="store_true")
    args = parser.parse_args()
    if args.delivery_manifest and not args.sog_only:
        parser.error("--delivery-manifest requires --sog-only; the existing MOMS edition must remain unchanged")
    if not args.qpdf or not args.qpdf.is_file():
        parser.error("qpdf is required; provide its absolute executable path with --qpdf")
    output = args.output.resolve()
    for name in ["sources", "assets"]:
        (output / name).mkdir(parents=True, exist_ok=True)
    original_moms_hash = digest(args.moms) if not args.sog_only else None
    sources, audits, manuals = [], [], []
    inventory = []
    if args.delivery_manifest:
        from import_delivery import import_delivery
        manuals.extend(import_delivery(args, output, sources, audits))
    elif not args.moms_only:
        manual, inventory = import_sog(args, output, sources, audits)
        manuals.append(manual)
    if not args.sog_only:
        manuals.append(import_moms(args, output, sources, audits))
    if original_moms_hash and digest(args.moms) != original_moms_hash:
        raise ValueError("Original MOMS source changed during import")
    checked_containers = set()
    for item in inventory:
        source_path = item.get("archive_path", item["path"])
        expected_hash = item.get("container_sha256", item["sha256"])
        if source_path in checked_containers:
            continue
        checked_containers.add(source_path)
        if digest(source_path) != expected_hash:
            raise ValueError("Original SOG source changed during import")
    manifest = {"manuals": manuals, "sources": sources, "audits": audits, "validation": {"original_source_hashes_unchanged": True, "lossless_tool": run_qpdf(args.qpdf, ["--version"])["stdout"], "pymupdf_version": pymupdf.VersionBind}}
    (output / "import-manifest.json").write_text(json.dumps(manifest, ensure_ascii=False, indent=2), encoding="utf-8")
    (output / "sog-selected-versions.json").write_text(json.dumps([a for a in audits if "logical_document" in a], ensure_ascii=False, indent=2), encoding="utf-8")
    print(json.dumps({"manifest": str(output / "import-manifest.json"), "manuals": len(manuals), "source_archives": len(sources), "original_source_hashes_unchanged": True}), flush=True)


if __name__ == "__main__":
    main()
