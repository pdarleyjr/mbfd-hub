"""
Targeted Docling extraction pipeline for specific MBFD workgroup files.
Processes only the user-specified PDFs and copies .md files through.
"""

import json
import shutil
import time
from pathlib import Path

from docling.datamodel.base_models import InputFormat
from docling.datamodel.pipeline_options import PdfPipelineOptions
from docling.document_converter import DocumentConverter, PdfFormatOption

OUTPUT_DIR = Path("extracted_assets")
IMAGES_DIR = OUTPUT_DIR / "images"

# User-specified files
TARGET_FILES = [
    # From output/
    Path("output/Final_Recommendations_Report_v.1.md"),
    Path("output/Final_Recommendations_Report_v.2.pdf"),
    Path("output/final_workgroup_results_v.2.md"),
    Path("output/final_workgroup_results_v.2.pdf"),
    Path("output/MBFD_work_group_day1-1.pdf"),
    Path("output/MBFD_work_group_day1-2.pdf"),
    # From root
    Path("HurstE3-07-2022_compressed-1.pdf"),
    Path("HUSQK1PACEr-1.pdf"),
    Path("Makita_14inch_ksaw-GEC01PL4-1.pdf"),
    Path("paratech_strutdriver_brochure-1.pdf"),
    Path("v-strut-1.pdf"),
    Path("DeWalt Powershift 12-inch Cut-Off Saw - Contractor Supply Magazine-1.pdf"),
    Path("Holmatro omnishore-1.pdf"),
    Path("Holmatro Pentheon Series USA-1.PDF"),
    Path("holmatro-t1-1.pdf"),
]


def setup():
    OUTPUT_DIR.mkdir(parents=True, exist_ok=True)
    IMAGES_DIR.mkdir(parents=True, exist_ok=True)


def create_converter() -> DocumentConverter:
    pdf_pipeline_options = PdfPipelineOptions()
    pdf_pipeline_options.generate_picture_images = True
    pdf_pipeline_options.generate_table_images = True
    return DocumentConverter(
        format_options={
            InputFormat.PDF: PdfFormatOption(pipeline_options=pdf_pipeline_options)
        }
    )


def extract_pdf(converter: DocumentConverter, pdf_path: Path) -> dict:
    stem = pdf_path.stem
    print(f"\n{'='*60}")
    print(f"Extracting PDF: {pdf_path}")
    print(f"{'='*60}")

    start = time.time()
    result = converter.convert(str(pdf_path))
    elapsed = time.time() - start

    doc = result.document
    num_pages = len(doc.pages) if hasattr(doc, "pages") else 0
    print(f"  Pages: {num_pages} | Time: {elapsed:.1f}s")

    # Export Markdown
    md_path = OUTPUT_DIR / f"{stem}.md"
    md_content = result.document.export_to_markdown()
    md_path.write_text(md_content, encoding="utf-8")
    print(f"  Markdown: {md_path}")

    # Export JSON (DocTags)
    json_path = OUTPUT_DIR / f"{stem}.json"
    json_content = result.document.export_to_dict()
    json_path.write_text(json.dumps(json_content, indent=2, default=str), encoding="utf-8")
    print(f"  JSON: {json_path}")

    # Export images
    image_count = 0
    if hasattr(result.document, "pictures"):
        for i, pic in enumerate(result.document.pictures):
            if hasattr(pic, "image") and pic.image is not None:
                img_path = IMAGES_DIR / f"{stem}_pic_{i}.png"
                pic.image.pil_image.save(str(img_path))
                image_count += 1
    if hasattr(result.document, "tables"):
        for i, table in enumerate(result.document.tables):
            if hasattr(table, "image") and table.image is not None:
                img_path = IMAGES_DIR / f"{stem}_table_{i}.png"
                table.image.pil_image.save(str(img_path))
                image_count += 1

    print(f"  Images: {image_count}")
    return {
        "file": str(pdf_path),
        "pages": num_pages,
        "time_seconds": round(elapsed, 1),
        "images": image_count,
        "outputs": {"markdown": str(md_path), "json": str(json_path)},
    }


def copy_markdown(md_path: Path) -> dict:
    stem = md_path.stem
    dest = OUTPUT_DIR / f"{stem}.md"
    print(f"\nCopying Markdown: {md_path} -> {dest}")
    shutil.copy2(md_path, dest)
    return {"file": str(md_path), "type": "markdown_copy", "output": str(dest)}


def main():
    setup()
    converter = create_converter()
    manifest = []

    pdfs = [f for f in TARGET_FILES if f.suffix.lower() == ".pdf"]
    mds = [f for f in TARGET_FILES if f.suffix.lower() == ".md"]

    # Process markdown files (just copy)
    for md in mds:
        if md.exists():
            info = copy_markdown(md)
            manifest.append(info)
        else:
            print(f"WARNING: {md} not found, skipping.")
            manifest.append({"file": str(md), "error": "not found"})

    # Process PDFs with Docling
    print(f"\n{'#'*60}")
    print(f"Processing {len(pdfs)} PDFs with Docling...")
    print(f"{'#'*60}")

    for pdf in pdfs:
        if pdf.exists():
            try:
                info = extract_pdf(converter, pdf)
                manifest.append(info)
            except Exception as e:
                print(f"  ERROR: {pdf.name}: {e}")
                manifest.append({"file": str(pdf), "error": str(e)})
        else:
            print(f"WARNING: {pdf} not found, skipping.")
            manifest.append({"file": str(pdf), "error": "not found"})

    # Write manifest
    manifest_path = OUTPUT_DIR / "extraction_manifest.json"
    manifest_path.write_text(json.dumps(manifest, indent=2), encoding="utf-8")
    print(f"\n{'='*60}")
    print(f"Manifest: {manifest_path}")
    print(f"Total processed: {len(manifest)}")
    print(f"{'='*60}")


if __name__ == "__main__":
    main()
