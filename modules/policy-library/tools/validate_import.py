"""Validate staged coverage and render source/derived first and last page samples."""

import argparse
import json
from pathlib import Path

from import_sources import digest, pymupdf
from PIL import Image, ImageChops, ImageDraw, ImageOps, ImageStat


def walk_sections(sections):
    for section in sections:
        yield section
        yield from walk_sections(section.get("children", []))


def require_coverage(pages, page_count):
    actual = [page["physical_page"] for page in pages]
    if len(actual) != len(set(actual)):
        raise ValueError("Physical page is owned by more than one document")
    if sorted(actual) != list(range(1, page_count + 1)):
        raise ValueError("Physical page coverage has a gap or an out-of-range page")


def diff_statistics(original, derived):
    difference = ImageChops.difference(original, derived)
    statistics = ImageStat.Stat(difference)
    return {
        "changed_pixel_count": sum(pixel != (0, 0, 0) for pixel in difference.get_flattened_data()),
        "pixel_count": original.width * original.height,
        "difference_bounds": difference.getbbox(),
        "mean_absolute_channel_delta": sum(statistics.mean) / 3,
        "maximum_channel_delta": max(maximum for _, maximum in difference.getextrema()),
    }


def render_image(page):
    pixmap = page.get_pixmap(alpha=False)
    return Image.frombytes("RGB", (pixmap.width, pixmap.height), pixmap.samples)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--root", type=Path, required=True)
    parser.add_argument("--render-samples", action="store_true")
    args = parser.parse_args()
    root = args.root.resolve()
    manifest = json.loads((root / "import-manifest.json").read_text(encoding="utf-8"))
    report = {"manuals": [], "source_archives": len(manifest["sources"]), "render_samples": []}
    for source in manifest["sources"]:
        if digest(root / source["source_path"]) != source["sha256"]:
            raise ValueError("Immutable source archive hash changed")
    samples = []
    for manual in manifest["manuals"]:
        documents = [document for section in walk_sections(manual["sections"]) for document in section.get("documents", [])]
        if len({d["slug"] for d in documents}) != len(documents):
            raise ValueError("Duplicate document slug within manual")
        for document in documents:
            if digest(root / document["asset_path"]) != document["sha256"]:
                raise ValueError("Immutable serving asset hash changed")
            with pymupdf.open(root / document["asset_path"]) as pdf:
                if len(pdf) != document["page_count"] or len(document["pages"]) != len(pdf):
                    raise ValueError("Serving asset page-count metadata mismatch")
        for source_hash in {d["metadata"]["source_sha256"] for d in documents}:
            source_documents = [d for d in documents if d["metadata"]["source_sha256"] == source_hash]
            pages = [page for d in source_documents for page in d["pages"]]
            source = next(s for s in manifest["sources"] if s["sha256"] == source_hash)
            pages.extend({"physical_page": n} for exclusion in source.get("coverage_exclusions", []) for n in exclusion["physical_pages"])
            require_coverage(pages, source["page_count"])
        for section in walk_sections(manual["sections"]):
            candidates = [d for d in section.get("documents", []) if d["metadata"].get("classification") != "menu"]
            if candidates:
                samples.append((manual["slug"], section["title"], candidates[0]))
                if candidates[-1] is not candidates[0]:
                    samples.append((manual["slug"], section["title"] + " - Final document", candidates[-1]))
        if manual["slug"] == "medical-protocols":
            for title_fragment in ["CARDIAC ARREST", "PATIENT RESTRAINT", "ROCEPHIN", "POINT OF CARE ULTRASOUND", "ENVIRONMENTAL EMERGENCIES", "WHOLE BLOOD TRANSFUSION", "ABUSE HOTLINE", "DNRO FORM", "HOSPITAL CAPABILITY", "STROKE ALERT ASSESSMENT", "HYPERTENSIVE", "NEAR DROWNING"]:
                for document in documents:
                    if title_fragment in document["title"] and document not in [sample[2] for sample in samples]:
                        samples.append((manual["slug"], "Additional mapping check", document))
        if manual["slug"] == "sogs":
            for document in documents:
                if document["title"].startswith("300-SAF -"):
                    samples.append((manual["slug"], "Previously investigated resource-preservation check", document))
        for source_hash in {d["metadata"]["source_sha256"] for d in documents}:
            if not any(sample[2]["metadata"]["source_sha256"] == source_hash for sample in samples):
                document = next(d for d in documents if d["metadata"]["source_sha256"] == source_hash)
                samples.append((manual["slug"], "Additional source archive check", document))
        report["manuals"].append({"slug": manual["slug"], "sections": len(manual["sections"]), "documents": len(documents), "physical_pages": sum(d["page_count"] for d in documents), "unique_document_slugs": True})
    if args.render_samples:
        qa = root / "qa"
        qa.mkdir(exist_ok=True)
        contact_rows = []
        for manual_slug, section_title, document in samples:
            with pymupdf.open(root / document["source_path"]) as source, pymupdf.open(root / document["asset_path"]) as asset:
                for relative in sorted({0, len(asset) - 1}):
                    physical = document["pages"][relative]["physical_page"]
                    original_image = render_image(source[physical - 1])
                    derived_image = render_image(asset[relative])
                    stats = diff_statistics(original_image, derived_image)
                    original_image.save(qa / f"{document['slug']}-physical-{physical}-source.png")
                    derived_image.save(qa / f"{document['slug']}-physical-{physical}-derived.png")
                    report["render_samples"].append({"manual": manual_slug, "section": section_title, "document": document["title"], "physical_page": physical, "relative_page": relative + 1, **stats})
                    contact_rows.append((document["title"], physical, original_image, derived_image))
        for index in range(0, len(contact_rows), 4):
            batch = contact_rows[index:index + 4]
            canvas = Image.new("RGB", (1224, 836 * len(batch)), "#dce2e6")
            drawing = ImageDraw.Draw(canvas)
            for row, (title, physical, original, derived) in enumerate(batch):
                drawing.text((10, row * 836 + 8), f"SOURCE | {title} | physical {physical}", fill="black")
                drawing.text((622, row * 836 + 8), "DERIVED | Original PDF page copied by qpdf", fill="black")
                canvas.paste(ImageOps.contain(original, (612, 792)), (0, row * 836 + 35))
                canvas.paste(ImageOps.contain(derived, (612, 792)), (612, row * 836 + 35))
            canvas.save(qa / f"contact-{index // 4 + 1:02d}.png")
    (root / "validation-report.json").write_text(json.dumps(report, indent=2), encoding="utf-8")
    if any(sample["changed_pixel_count"] for sample in report["render_samples"]):
        raise ValueError("Representative rendered pixels differ; inspect validation-report.json and qa source/derived images")
    print(json.dumps({"manuals": report["manuals"], "render_samples": len(report["render_samples"]), "source_archives": report["source_archives"]}, indent=2))


if __name__ == "__main__":
    main()
