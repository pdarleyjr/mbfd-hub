#!/usr/bin/env python3
"""Extract images from PDFs, remove backgrounds, and build a visual assets index."""

import os
import json
import shutil
import glob
from pathlib import Path
from io import BytesIO

import fitz  # PyMuPDF
from PIL import Image
from rembg import remove

# Workspace root
ROOT = Path(".")

# PDF files to process
PDFS = [
    "v-strut-1.pdf",
    "workgroup_final_itinerary.pdf",
    "DeWalt Powershift 12-inch Cut-Off Saw - Contractor Supply Magazine-1.pdf",
    "final_workgroup_results.pdf",
    "Holmatro omnishore-1.pdf",
    "Holmatro Pentheon Series USA-1.PDF",
    "holmatro-t1-1.pdf",
    "HurstE3-07-2022_compressed-1.pdf",
    "HUSQK1PACEr-1.pdf",
    "Makita_14inch_ksaw-GEC01PL4-1.pdf",
    "MBFD_Executive_Report_Overall_2026-03-16.pdf",
    "MBFD_SAVER_Report_Overall_2026-03-16.pdf",
    "MBFD_work_group_day1-1.pdf",
    "MBFD_work_group_day1-2.pdf",
    "paratech_strutdriver_brochure-1.pdf",
]

# Product name mapping from PDF filename patterns
PRODUCT_MAP = {
    "holmatro-t1": "Holmatro T1",
    "HurstE3": "Hurst E3",
    "HUSQK1PACEr": "Husqvarna K1 Pace",
    "Makita_14inch_ksaw": "Makita GEC01PL4",
    "DeWalt": "DeWalt Powershift",
    "paratech": "Paratech StrutDriver",
    "v-strut": "V-Strut",
    "Holmatro omnishore": "Holmatro Omnishore",
    "Holmatro Pentheon": "Holmatro Pentheon",
}

# High-priority PDFs (vendor product PDFs)
HIGH_PRIORITY_STEMS = [
    "v-strut-1", "DeWalt Powershift 12-inch Cut-Off Saw - Contractor Supply Magazine-1",
    "Holmatro omnishore-1", "Holmatro Pentheon Series USA-1",
    "holmatro-t1-1", "HurstE3-07-2022_compressed-1", "HUSQK1PACEr-1",
    "Makita_14inch_ksaw-GEC01PL4-1", "paratech_strutdriver_brochure-1",
]

RAW_DIR = ROOT / "images" / "raw"
CLEAN_DIR = ROOT / "images" / "cleaned"


def detect_product(pdf_name: str) -> str:
    for pattern, product in PRODUCT_MAP.items():
        if pattern.lower() in pdf_name.lower():
            return product
    return ""


def get_priority(pdf_stem: str) -> str:
    for hp in HIGH_PRIORITY_STEMS:
        if hp.lower() == pdf_stem.lower():
            return "high"
    return "medium"


def extract_and_clean():
    os.makedirs(RAW_DIR, exist_ok=True)
    os.makedirs(CLEAN_DIR, exist_ok=True)

    index = []
    total_extracted = 0
    total_cleaned = 0
    total_skipped = 0

    for pdf_name in PDFS:
        pdf_path = ROOT / pdf_name
        if not pdf_path.exists():
            print(f"WARNING: {pdf_name} not found, skipping.")
            continue

        pdf_stem = Path(pdf_name).stem
        product = detect_product(pdf_name)
        priority = get_priority(pdf_stem)

        print(f"Processing: {pdf_name}")
        try:
            doc = fitz.open(str(pdf_path))
        except Exception as e:
            print(f"  ERROR opening: {e}")
            continue

        for page_num in range(len(doc)):
            page = doc[page_num]
            image_list = page.get_images(full=True)

            for img_idx, img_info in enumerate(image_list):
                xref = img_info[0]
                try:
                    base_image = doc.extract_image(xref)
                except Exception:
                    continue

                image_bytes = base_image["image"]
                try:
                    pil_img = Image.open(BytesIO(image_bytes))
                except Exception:
                    continue

                w, h = pil_img.size
                if w < 100 or h < 100:
                    total_skipped += 1
                    continue

                total_extracted += 1
                fname = f"{pdf_stem}_p{page_num}_i{img_idx}.png"

                # Convert CMYK or other modes to RGB for PNG compatibility
                if pil_img.mode in ("CMYK", "YCbCr", "LAB"):
                    pil_img = pil_img.convert("RGB")

                # Save raw
                raw_path = RAW_DIR / fname
                pil_img.save(str(raw_path), "PNG")

                # Remove background
                try:
                    if pil_img.mode != "RGBA":
                        pil_img = pil_img.convert("RGBA")
                    cleaned = remove(pil_img)

                    # Crop to non-transparent bounding box
                    bbox = cleaned.getbbox()
                    if bbox:
                        cleaned = cleaned.crop(bbox)

                    clean_path = CLEAN_DIR / fname
                    cleaned.save(str(clean_path), "PNG")
                    total_cleaned += 1

                    index.append({
                        "product": product,
                        "image_file": f"images/cleaned/{fname}",
                        "type": "tool",
                        "placement": "",
                        "caption": "",
                        "priority": priority,
                    })
                except Exception as e:
                    print(f"  Background removal failed for {fname}: {e}")

        doc.close()

    # Copy dashboard screenshots
    dash_files = sorted(glob.glob(str(ROOT / "Screenshot *.png")))
    for df in dash_files:
        dst = RAW_DIR / Path(df).name
        shutil.copy2(df, str(dst))
        index.append({
            "product": "",
            "image_file": f"images/raw/{Path(df).name}",
            "type": "dashboard",
            "placement": "",
            "caption": "",
            "priority": "medium",
        })
        print(f"Copied dashboard: {Path(df).name}")

    # Write JSON index
    with open("Visual_Assets_Index.json", "w", encoding="utf-8") as f:
        json.dump(index, f, indent=2)

    print(f"\n=== SUMMARY ===")
    print(f"Total images extracted: {total_extracted}")
    print(f"Total images cleaned:   {total_cleaned}")
    print(f"Total images skipped:   {total_skipped}")
    print(f"Dashboard screenshots:  {len(dash_files)}")
    print(f"Visual_Assets_Index.json entries: {len(index)}")


if __name__ == "__main__":
    extract_and_clean()
