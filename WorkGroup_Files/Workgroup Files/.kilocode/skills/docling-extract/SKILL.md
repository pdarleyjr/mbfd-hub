---
name: docling_extract
description: High-fidelity PDF to Markdown/JSON conversion using Granite-Docling-258M.
tools: ["python", "docling"]
safety: "Verify output directory existence before extraction."
---

# Docling Extract Skill

## Purpose
Extract structured content from PDF documents using the Docling pipeline with the Granite-Docling-258M VLM model.

## Usage
1. Place source PDFs in `source_pdfs/`
2. Run `python docling_extract.py`
3. Outputs land in `extracted_assets/` as Markdown + JSON with DocTags preserved
4. Images extracted with `generate_picture_images=True` are saved to `extracted_assets/images/`

## Configuration
- Model: Granite-Docling-258M
- Image extraction: enabled
- Output formats: Markdown, JSON (DocTags)
- Performance: ~5s/page on CPU
