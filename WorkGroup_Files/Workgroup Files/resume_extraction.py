"""Resume extraction for PDFs that weren't completed in the previous run."""

import json
import shutil
import time
from pathlib import Path

from docling.datamodel.base_models import InputFormat
from docling.datamodel.pipeline_options import PdfPipelineOptions
from docling.document_converter import DocumentConverter, PdfFormatOption

OUTPUT_DIR = Path("extracted_assets")
IMAGES_DIR = OUTPUT_DIR / "images"

# Only the PDFs that are missing from extracted_assets/
REMAINING_PDFS = [
    Path("output/MBFD_work_group_day1-1.pdf"),
    Path("output/MBFD_work_group_day1-2.pdf"),
    Path("HurstE3-07-2022_compressed-1.pdf"),
    Path("HUSQK1PACEr-1.pdf"),
    Path("Makita_14inch_ksaw-GEC01PL4-1.pdf"),
    Path("paratech_strutdriver_brochure-1.pdf"),
    Path("v-strut-1.pdf"),
    Path("holmatro-t1-1.pdf"),
]

# Also copy the remaining .md
MD_TO_COPY = [
    Path("output/final_workgroup_results_v.2.md"),
]


def create_converter():
    opts = PdfPipelineOptions()
    opts.generate_picture_images = True
    opts.generate_table_images = True
    return DocumentConverter(
        format_options={InputFormat.PDF: PdfFormatOption(pipeline_options=opts)}
    )


def extract_pdf(converter, pdf_path):
    stem = pdf_path.stem
    print(f"\n{'='*60}")
    print(f"Extracting: {pdf_path}")

    start = time.time()
    result = converter.convert(str(pdf_path))
    elapsed = time.time() - start

    doc = result.document
    pages = len(doc.pages) if hasattr(doc, "pages") else 0
    print(f"  Pages: {pages} | Time: {elapsed:.1f}s")

    md_path = OUTPUT_DIR / f"{stem}.md"
    md_path.write_text(result.document.export_to_markdown(), encoding="utf-8")

    json_path = OUTPUT_DIR / f"{stem}.json"
    json_path.write_text(
        json.dumps(result.document.export_to_dict(), indent=2, default=str),
        encoding="utf-8",
    )

    img_count = 0
    if hasattr(result.document, "pictures"):
        for i, pic in enumerate(result.document.pictures):
            if hasattr(pic, "image") and pic.image is not None:
                (IMAGES_DIR / f"{stem}_pic_{i}.png").parent.mkdir(exist_ok=True)
                pic.image.pil_image.save(str(IMAGES_DIR / f"{stem}_pic_{i}.png"))
                img_count += 1
    if hasattr(result.document, "tables"):
        for i, tbl in enumerate(result.document.tables):
            if hasattr(tbl, "image") and tbl.image is not None:
                tbl.image.pil_image.save(str(IMAGES_DIR / f"{stem}_table_{i}.png"))
                img_count += 1

    print(f"  Images: {img_count} | MD: {md_path} | JSON: {json_path}")
    return {"file": str(pdf_path), "pages": pages, "time": round(elapsed, 1), "images": img_count}


def main():
    OUTPUT_DIR.mkdir(parents=True, exist_ok=True)
    IMAGES_DIR.mkdir(parents=True, exist_ok=True)

    # Copy any remaining .md files
    for md in MD_TO_COPY:
        dest = OUTPUT_DIR / md.name
        if not dest.exists() and md.exists():
            shutil.copy2(md, dest)
            print(f"Copied: {md} -> {dest}")

    # Check which PDFs still need extraction
    to_extract = []
    for pdf in REMAINING_PDFS:
        stem = pdf.stem
        if not (OUTPUT_DIR / f"{stem}.json").exists():
            to_extract.append(pdf)
        else:
            print(f"Already extracted: {stem}")

    if not to_extract:
        print("All PDFs already extracted!")
        return

    print(f"\n{len(to_extract)} PDFs remaining to extract.")
    converter = create_converter()
    results = []

    for pdf in to_extract:
        if pdf.exists():
            try:
                info = extract_pdf(converter, pdf)
                results.append(info)
            except Exception as e:
                print(f"  ERROR: {pdf}: {e}")
                results.append({"file": str(pdf), "error": str(e)})
        else:
            print(f"  NOT FOUND: {pdf}")

    # Append to manifest
    manifest_path = OUTPUT_DIR / "extraction_manifest.json"
    existing = []
    if manifest_path.exists():
        existing = json.loads(manifest_path.read_text(encoding="utf-8"))
    existing.extend(results)
    manifest_path.write_text(json.dumps(existing, indent=2), encoding="utf-8")
    print(f"\nDone. {len(results)} PDFs processed. Manifest updated.")


if __name__ == "__main__":
    main()
