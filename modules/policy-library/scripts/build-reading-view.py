"""Build a private review candidate from an exact native DOCX/PDF pair.

No inferred PDF text reflow, network assets, source mutation, or approval stamp.
Source paragraphs require coordinate-bound PDF range proof. Supported native
tables preserve exact cells and merges; unproved/complex content links to its
original PDF page. Missing source-page mappings withhold the whole artifact.
"""
import argparse
import hashlib
import json
import re
import unicodedata
from pathlib import Path
from zipfile import ZipFile

from lxml import etree
from pypdf import PdfReader

NS = {"w": "http://schemas.openxmlformats.org/wordprocessingml/2006/main"}
W = "{" + NS["w"] + "}"


def sha(data):
    return hashlib.sha256(data).hexdigest()


def normalized(text):
    text = re.sub(r"(?<=-)\r?\n(?=\w)", "", text)
    return re.sub(r"\s+", " ", unicodedata.normalize("NFKC", text)).strip()


def visible_text(node):
    values = []
    for item in node.iter():
        if item.tag not in (W + "t", W + "tab", W + "br"):
            continue
        if any(parent.tag == W + "del" for parent in item.iterancestors()):
            continue
        run = next((parent for parent in item.iterancestors() if parent.tag == W + "r"), None)
        if run is not None and run.find("w:rPr/w:vanish", NS) is not None:
            continue
        values.append(item.text or "" if item.tag == W + "t" else "\t" if item.tag == W + "tab" else "\n")
    return "".join(values).strip()


def source_destination(node, pdf, page_text):
    destinations = pdf._reading_destinations
    for anchor in node.xpath(".//w:bookmarkStart/@w:name", namespaces=NS):
        for key in (anchor, "/" + anchor, anchor.replace("_", "5F"), "/" + anchor.replace("_", "5F")):
            if key in destinations:
                page = pdf.get_destination_page_number(destinations[key]) + 1
                if 1 <= page <= len(pdf.pages):
                    top, left = destinations[key].top, destinations[key].left
                    return {"source_anchor": anchor, "pdf_destination": key, "pdf_page": page, "mapping": "named_destination",
                            "pdf_top": float(top) if top is not None else None, "pdf_left": float(left) if left is not None else None}
    text = normalized(visible_text(node))
    pages = [index + 1 for index, page in enumerate(page_text) if text and text in page]
    if len(pages) == 1:
        return {"pdf_page": pages[0], "mapping": "unique_actual_pdf_text"}
    return None


def pdf_spans(pdf, header_text, bottom, uniform_top_margin=None):
    pages, bounds = [], []
    for page in pdf.pages:
        spans = []
        def visit(text, cm, tm, font, size):
            if text.strip():
                spans.append({"text": text, "x": tm[4] * cm[0] + tm[5] * cm[2] + cm[4],
                              "y": tm[4] * cm[1] + tm[5] * cm[3] + cm[5], "font_size": size})
        page.extract_text(visitor_text=visit)
        # Header strings come from the actual native header parts. Their PDF
        # positions are measured, while the footer boundary is the native margin.
        header_spans = [span for span in spans if normalized(span["text"]) in header_text and span["y"] > float(page.mediabox.height) / 2]
        header_floor = min((span["y"] - span["font_size"] * .25 for span in header_spans), default=float(page.mediabox.height))
        if uniform_top_margin is not None:
            header_floor = min(header_floor, float(page.mediabox.height) - uniform_top_margin)
        pages.append([span for span in spans if span["y"] >= bottom and span["y"] < header_floor])
        bounds.append({"header_floor": header_floor, "footer_body_boundary": bottom,
                       "native_uniform_top_margin_points": uniform_top_margin,
                       "removed_header_spans": header_spans, "native_footer_margin_points": bottom})
    return pages, bounds


def paragraph_range(text, start, end, spans, bounds):
    if start["mapping"] != "named_destination" or start.get("pdf_top") is None:
        return {"matched": False, "reason": "Source paragraph has no coordinate-bound destination"}
    first = start["pdf_page"]
    last = end["pdf_page"] if end else len(spans)
    if last < first or (last == first and end and end.get("pdf_top", 0) >= start["pdf_top"]):
        return {"matched": False, "reason": "Source destination range is ambiguous"}
    selected = []
    for page in range(first, last + 1):
        upper = start["pdf_top"] if page == first else bounds[page - 1]["header_floor"]
        lower = end.get("pdf_top") if end and page == last else bounds[page - 1]["footer_body_boundary"]
        if lower is None:
            return {"matched": False, "reason": "Next source destination has no coordinate"}
        selected.extend({**span, "pdf_page": page} for span in spans[page - 1] if span["y"] <= upper + .5 and span["y"] > lower + .5)
    extracted = span_text(selected)
    matched = normalized(text) in normalized(extracted)
    return {"matched": matched, "start": start, "next_source_destination": end,
            "pdf_page_range": [first, last], "extracted_span_sha256": sha(extracted.encode("utf-8")),
            "source_text_sha256": sha(text.encode("utf-8")), "spans": selected}


def span_text(spans):
    result, previous = [], None
    for span in spans:
        if previous and (span.get("pdf_page") != previous.get("pdf_page") or abs(span["y"] - previous["y"]) > 1):
            result.append("\n")
        result.append(span["text"])
        previous = span
    return "".join(result)


def native_table(node, pdf, page_text, spans, bounds, numbered_styles):
    """Preserve supported native cell structure; reject guessed merged geometry."""
    columns = len(node.findall("w:tblGrid/w:gridCol", NS))
    reason = None
    if columns < 1 or columns > 20 or node.xpath(".//w:tc/w:tbl|.//w:drawing|.//w:pict|.//w:object|.//w:numPr|.//w:instrText|.//w:fldSimple", namespaces=NS) or any(style in numbered_styles for style in node.xpath(".//w:pStyle/@w:val", namespaces=NS)):
        reason = "Complex table, graphic, field, or numbering requires original PDF"
    rows, cells_proof, active = [], [], {}
    widths = [int(column.get(W + "w", "0")) / 20 for column in node.findall("w:tblGrid/w:gridCol", NS)]
    for row_number, row in enumerate(node.findall("w:tr", NS)):
        header_node = row.find("w:trPr/w:tblHeader", NS)
        header = header_node is not None and header_node.get(W + "val", "1") not in ("0", "false", "off")
        if row.find("w:trPr/w:gridBefore", NS) is not None or row.find("w:trPr/w:gridAfter", NS) is not None:
            reason = "Omitted native grid cells require original PDF"
        result, next_active, column = [], {}, 0
        for cell_number, cell in enumerate(row.findall("w:tc", NS)):
            span_node = cell.find("w:tcPr/w:gridSpan", NS)
            span = int(span_node.get(W + "val", "1")) if span_node is not None else 1
            merge = cell.find("w:tcPr/w:vMerge", NS)
            mode = merge.get(W + "val", "continue") if merge is not None else None
            paragraphs = []
            for paragraph_index, paragraph in enumerate(cell.xpath(".//w:p", namespaces=NS)):
                text = visible_text(paragraph)
                if not text and not paragraph.xpath(".//w:drawing|.//w:pict|.//w:object", namespaces=NS):
                    continue
                mapping = source_destination(paragraph, pdf, page_text)
                if not mapping:
                    raise ValueError(f"Table cell {row_number}/{cell_number}/{paragraph_index} has no verified PDF page")
                coordinate_proof = None
                if mapping.get("pdf_left") is not None and mapping.get("pdf_top") is not None and text:
                    width = sum(widths[column:column + span])
                    selected = [{**item, "pdf_page": mapping["pdf_page"]} for item in spans[mapping["pdf_page"] - 1]
                                if mapping["pdf_left"] - .6 <= item["x"] < mapping["pdf_left"] + width - .6 and item["y"] < mapping["pdf_top"] + .5]
                    extracted = span_text(selected)
                    starts_at_anchor = bool(selected) and 0 <= mapping["pdf_top"] - selected[0]["y"] <= 2 * selected[0]["font_size"] + 2
                    matched = starts_at_anchor and normalized(extracted).startswith(normalized(text))
                    coordinate_proof = {"matched": matched, "source_grid_width_points": width, "destination": mapping,
                                        "starts_at_anchor": starts_at_anchor,
                                        "actual_pdf_spans": selected, "extracted_span_sha256": sha(extracted.encode("utf-8"))}
                    if not matched:
                        reason = "Cell text does not match its measured PDF destination/grid band; original PDF required"
                elif text:
                    reason = "Cell has no coordinate-bound PDF text proof; original PDF required"
                paragraphs.append({"paragraph_index": paragraph_index, "text": text, "source_text_sha256": sha(text.encode("utf-8")),
                                   "coordinate_proof": coordinate_proof, **mapping})
            text = "\n".join(item["text"] for item in paragraphs)
            page = paragraphs[0]["pdf_page"] if paragraphs else None
            cells_proof.append({"row": row_number, "native_cell": cell_number, "grid_column": column,
                                "grid_span": span, "v_merge": mode, "repeating_header": header, "paragraphs": paragraphs})
            if not 1 <= span <= columns:
                reason = "Invalid native grid span requires original PDF"
            key = (column, span)
            if mode == "continue":
                previous = active.get(key)
                if previous is None or text:
                    reason = "Unresolved native vertical merge requires original PDF"
                else:
                    previous["row_span"] += 1
                    next_active[key] = previous
            else:
                output = {"text": text, "col_span": span, "row_span": 1, "header": header, "pdf_page": page}
                result.append(output)
                if mode == "restart":
                    next_active[key] = output
            column += span
        if column != columns:
            reason = "Native row width does not match the declared table grid"
        rows.append(result)
        active = next_active
    # Empty cells retain their native structure; a table-wide verified destination
    # binds those cells without inventing text or assigning them a separate page.
    mapping = source_destination(node, pdf, page_text)
    if not mapping:
        raise ValueError("Table has no verified PDF destination")
    for row in rows:
        for cell in row:
            if cell["pdf_page"] is None:
                cell["pdf_page"] = mapping["pdf_page"]
                cell["empty_cell_bound_to_table"] = True
    evidence = {"columns": columns, "cells": cells_proof, "fallback_reason": reason}
    if reason:
        return {"type": "pdf_reference", "text": "Original table or chart — view the PDF to preserve its layout and complete cells.", "pdf_page": mapping["pdf_page"]}, evidence
    return {"type": "table", "text": "", "pdf_page": mapping["pdf_page"], "columns": columns, "rows": rows}, evidence


def build(docx_path, pdf_path, identity, title):
    docx_bytes, pdf_bytes = docx_path.read_bytes(), pdf_path.read_bytes()
    with ZipFile(docx_path) as package:
        document = etree.fromstring(package.read("word/document.xml"), etree.XMLParser(resolve_entities=False, no_network=True))
        header_text = set()
        for part in package.namelist():
            if re.fullmatch(r"word/header\d+\.xml", part):
                header = etree.fromstring(package.read(part), etree.XMLParser(resolve_entities=False, no_network=True))
                header_text.update(normalized(text) for text in header.xpath(".//w:t/text()", namespaces=NS) if text.strip())
        numbered_styles = set()
        if "word/styles.xml" in package.namelist():
            styles_root = etree.fromstring(package.read("word/styles.xml"), etree.XMLParser(resolve_entities=False, no_network=True))
            styles = {style.get(W + "styleId"): style for style in styles_root.findall("w:style", NS)}
            numbered_styles = {key for key, style in styles.items() if style.find("w:pPr/w:numPr", NS) is not None}
            previous = None
            while previous != len(numbered_styles):
                previous = len(numbered_styles)
                numbered_styles.update(key for key, style in styles.items() if style.find("w:basedOn", NS) is not None and style.find("w:basedOn", NS).get(W + "val") in numbered_styles)
    pdf = PdfReader(pdf_path)
    destinations = pdf.named_destinations
    pdf._reading_destinations = destinations
    page_text = [normalized(page.extract_text()) for page in pdf.pages]
    margins = [int(node.get(W + "bottom", "0")) / 20 for node in document.findall(".//w:sectPr/w:pgMar", NS)]
    if not margins or min(margins) <= 0:
        raise ValueError("Native footer/body margin is unknown")
    top_margins = {int(node.get(W + "top", "0")) / 20 for node in document.findall(".//w:sectPr/w:pgMar", NS)}
    uniform_top_margin = next(iter(top_margins)) if len(top_margins) == 1 and min(top_margins) > 0 else None
    spans, body_bounds = pdf_spans(pdf, header_text, min(margins), uniform_top_margin)
    body_nodes = list(document.find("w:body", NS))
    mapped_nodes = [(index, source_destination(node, pdf, page_text)) for index, node in enumerate(body_nodes) if node.tag in (W + "p", W + "tbl") and (visible_text(node) or node.xpath(".//w:drawing|.//w:pict|.//w:object", namespaces=NS))]
    blocks, proof, unresolved, cover = [], [], [], []
    started = False
    for index, node in enumerate(document.find("w:body", NS)):
        if node.tag not in (W + "p", W + "tbl"):
            if node.tag != W + "sectPr" and (visible_text(node) or node.xpath(".//w:drawing|.//w:pict|.//w:object", namespaces=NS)):
                raise ValueError("Unsupported native body container has content; artifact withheld")
            continue
        text = visible_text(node)
        graphic = bool(node.xpath(".//w:drawing|.//w:pict|.//w:object", namespaces=NS))
        if not text and not graphic:
            continue
        bookmarks = node.xpath(".//w:bookmarkStart/@w:name", namespaces=NS)
        resolved = []
        for anchor in bookmarks:
            for key in (anchor, "/" + anchor, anchor.replace("_", "5F"), "/" + anchor.replace("_", "5F")):
                if key in destinations:
                    page = pdf.get_destination_page_number(destinations[key]) + 1
                    if 1 <= page <= len(pdf.pages):
                        resolved.append((anchor, key, page))
                        break
        if not resolved:
            # The generated cover is shown by the title/metadata and original PDF.
            # Once source content starts, an unmapped block is a release blocker.
            if started:
                unresolved.append({"body_index": index, "text": text, "reason": "No verified PDF bookmark destination"})
            elif text:
                cover.append(text)
            continue
        started = True
        anchor, destination, page = resolved[0]
        complex_content = graphic or bool(node.xpath(".//w:numPr|.//w:instrText|.//w:fldSimple", namespaces=NS)) or any(style in numbered_styles for style in node.xpath(".//w:pStyle/@w:val", namespaces=NS))
        table_proof = None
        range_proof = None
        if node.tag == W + "tbl":
            block, table_proof = native_table(node, pdf, page_text, spans, body_bounds, numbered_styles)
        elif complex_content:
            block = {"type": "pdf_reference", "text": "Table, figure, form, or numbered item — view the original PDF.", "pdf_page": page}
        else:
            kind = "heading" if text.endswith(":") and len(text) < 130 else "paragraph"
            start = source_destination(node, pdf, page_text)
            end = next((mapping for position, mapping in mapped_nodes if position > index and mapping), None)
            range_proof = paragraph_range(text, start, end, spans, body_bounds)
            block = {"type": kind, "text": text, "pdf_page": page} if range_proof["matched"] else {
                "type": "pdf_reference", "text": "Original paragraph — view the PDF for exact text and layout.", "pdf_page": page}
        blocks.append(block)
        proof.append({"body_index": index, "source_anchor": anchor, "pdf_destination": destination, "pdf_page": page,
                      "type": block["type"], "source_text_sha256": sha(text.encode("utf-8")),
                      "normalized_text_found_in_pdf": range_proof["matched"] if range_proof else None,
                      "paragraph_range_proof": range_proof, "table_proof": table_proof,
                      "original_pdf_fallback": block["type"] == "pdf_reference"})
    if unresolved or not blocks:
        raise ValueError(f"Unresolved source content: {len(unresolved)} blocks. Reading artifact withheld.")
    cover_pages = set(range(1, len(pdf.pages) + 1))
    for text in cover:
        cover_pages &= {index + 1 for index, page in enumerate(page_text) if normalized(text) in page}
    if cover and len(cover_pages) != 1:
        raise ValueError("Source cover does not resolve to exactly one actual PDF page")
    cover_page = next(iter(cover_pages)) if cover else None
    artifact = {"schema": "mbfd-reading-v1", "pdf_sha256": sha(pdf_bytes), "source_docx_sha256": sha(docx_bytes),
                "cover": cover, "cover_pdf_page": cover_page,
                "page_count": len(pdf.pages), "entries": [{"id": identity, "title": title, "blocks": blocks}]}
    evidence = {"scope": "Candidate only; independent source/PDF/render review must approve before validated=true is set during a NEW revision import.",
                "docx": str(docx_path), "pdf": str(pdf_path), "pdf_sha256": artifact["pdf_sha256"],
                "source_docx_sha256": artifact["source_docx_sha256"], "block_proof": proof,
                "cover_proof": [{"text": text, "pdf_page": cover_page, "normalized_text_found_in_pdf": True} for text in cover],
                "native_body_bounds": body_bounds,
                "normalized_pdf_text_mismatches": [item["body_index"] for item in proof if item["normalized_text_found_in_pdf"] is False],
                "unmapped_blocks": unresolved, "publication_validated": False}
    return artifact, evidence


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--docx", type=Path, required=True)
    parser.add_argument("--pdf", type=Path, required=True)
    parser.add_argument("--identity", required=True)
    parser.add_argument("--title", required=True)
    parser.add_argument("--out-dir", type=Path, required=True)
    args = parser.parse_args()
    artifact, proof = build(args.docx, args.pdf, args.identity, args.title)
    data = json.dumps(artifact, ensure_ascii=False, separators=(",", ":")).encode("utf-8")
    digest = sha(data)
    args.out_dir.mkdir(parents=True, exist_ok=True)
    output = args.out_dir / (digest + ".json")
    if output.exists() and output.read_bytes() != data:
        raise ValueError("Immutable artifact filename already contains different bytes")
    output.write_bytes(data)
    proof["artifact_sha256"] = digest
    proof["candidate_revision_metadata"] = {"source_docx_sha256": artifact["source_docx_sha256"], "reading_view": {"sha256": digest, "validated": False}}
    (args.out_dir / (digest + ".proof.json")).write_text(json.dumps(proof, ensure_ascii=False, indent=2), encoding="utf-8")
    print(json.dumps({"artifact": str(output), "artifact_sha256": digest, "blocks": len(artifact["entries"][0]["blocks"]),
                      "text_mismatches": proof["normalized_pdf_text_mismatches"], "publication_validated": False}))


if __name__ == "__main__":
    main()
