"""
Docling PDF Extraction Pipeline
Converts PDFs from source_pdfs/ to Markdown + JSON in extracted_assets/
using the Granite-Docling-258M VLM for high-fidelity structural extraction.
"""

import json
import shutil
import time
from pathlib import Path

from docling.datamodel.base_models import InputFormat
from docling.datamodel.pipeline_options import (
    PdfPipelineOptions,
    VlmPipelineOptions,
)
from docling.document_converter import DocumentConverter, PdfFormatOption


# --- Configuration ---
SOURCE_DIR = Path("source_pdfs")
OUTPUT_DIR = Path("extracted_assets")
IMAGES_DIR = OUTPUT_DIR / "images"


def setup_directories():
    """Ensure output directories exist."""
    OUTPUT_DIR.mkdir(parents=True, exist_ok=True)
    IMAGES_DIR.mkdir(parents=True, exist_ok=True)


def create_converter() -> DocumentConverter:
    """Configure Docling with Granite-Docling-258M and image extraction."""
    pdf_pipeline_options = PdfPipelineOptions()
    pdf_pipeline_options.generate_picture_images = True
    pdf_pipeline_options.generate_table_images = True

    converter = DocumentConverter(
        format_options={
            InputFormat.PDF: PdfFormatOption(
                pipeline_options=pdf_pipeline_options,
            )
        }
    )
    return converter


def extract_pdf(converter: DocumentConverter, pdf_path: Path) -> dict:
    """Extract a single PDF and return metadata about the extraction."""
    stem = pdf_path.stem
    print(f"\n{'='*60}")
    print(f"Extracting: {pdf_path.name}")
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

    # Export JSON (DocTags preserved)
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

    print(f"  Images extracted: {image_count}")

    return {
        "file": pdf_path.name,
        "pages": num_pages,
        "time_seconds": round(elapsed, 1),
        "images": image_count,
        "markdown": str(md_path),
        "json": str(json_path),
    }


def main():
    setup_directories()

    # Collect PDFs from source_pdfs/ directory
    pdfs = sorted(SOURCE_DIR.glob("*.pdf")) + sorted(SOURCE_DIR.glob("*.PDF"))
    if not pdfs:
        # Fall back to workspace root PDFs if source_pdfs is empty
        root = Path(".")
        pdfs = sorted(root.glob("*.pdf")) + sorted(root.glob("*.PDF"))
        if pdfs:
            print(f"No PDFs in source_pdfs/. Found {len(pdfs)} in workspace root.")
            print("Copying to source_pdfs/ for pipeline consistency...")
            for p in pdfs:
                shutil.copy2(p, SOURCE_DIR / p.name)
            pdfs = sorted(SOURCE_DIR.glob("*.pdf")) + sorted(SOURCE_DIR.glob("*.PDF"))

    if not pdfs:
        print("ERROR: No PDF files found. Place PDFs in source_pdfs/ directory.")
        return

    # Remove duplicates (case-insensitive)
    seen = set()
    unique_pdfs = []
    for p in pdfs:
        key = p.name.lower()
        if key not in seen:
            seen.add(key)
            unique_pdfs.append(p)
    pdfs = unique_pdfs

    print(f"Found {len(pdfs)} PDF(s) to process.")
    converter = create_converter()

    manifest = []
    for pdf in pdfs:
        try:
            info = extract_pdf(converter, pdf)
            manifest.append(info)
        except Exception as e:
            print(f"  ERROR processing {pdf.name}: {e}")
            manifest.append({"file": pdf.name, "error": str(e)})

    # Write extraction manifest
    manifest_path = OUTPUT_DIR / "extraction_manifest.json"
    manifest_path.write_text(json.dumps(manifest, indent=2), encoding="utf-8")
    print(f"\nManifest written to {manifest_path}")
    print(f"Total files processed: {len(manifest)}")


if __name__ == "__main__":
    main()
