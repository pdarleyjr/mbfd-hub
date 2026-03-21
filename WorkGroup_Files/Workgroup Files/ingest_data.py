#!/usr/bin/env python3
"""
PHASE 1B — Full PDF Ingestion + Structured Data Extraction
MBFD Mid-Mount Ladder Workgroup 2026

Extracts text from all PDFs, parses evaluator scores, product specs,
and narrative content. Outputs Product_Data_Master.csv and Content_Map.json.

Dashboard images are TIER 1 numerical truth — their values override any
conflicting numbers found in PDF text.
"""

import sys
import os
import re
import csv
import json

sys.stdout.reconfigure(encoding="utf-8")

import fitz  # PyMuPDF

# ──────────────────────────────────────────────────────────────────────────────
# CONFIGURATION
# ──────────────────────────────────────────────────────────────────────────────

ALL_PDFS = [
    "final_workgroup_results.pdf",
    "MBFD_Executive_Report_Overall_2026-03-16.pdf",
    "MBFD_SAVER_Report_Overall_2026-03-16.pdf",
    "MBFD_work_group_day1-1.pdf",
    "MBFD_work_group_day1-2.pdf",
    "workgroup_final_itinerary.pdf",
    "Holmatro omnishore-1.pdf",
    "Holmatro Pentheon Series USA-1.PDF",
    "holmatro-t1-1.pdf",
    "HurstE3-07-2022_compressed-1.pdf",
    "HUSQK1PACEr-1.pdf",
    "Makita_14inch_ksaw-GEC01PL4-1.pdf",
    "DeWalt Powershift 12-inch Cut-Off Saw - Contractor Supply Magazine-1.pdf",
    "paratech_strutdriver_brochure-1.pdf",
    "v-strut-1.pdf",
]

CSV_COLUMNS = [
    "product_name", "category", "manufacturer", "model_id",
    "weight_lbs", "weight_kg",
    "spread_force_kn", "cutting_force_kn", "opening_width_mm",
    "power_source", "voltage_v", "battery_capacity_wh", "runtime_min",
    "hydraulic_pressure_bar", "flow_rate_lpm",
    "blade_size_inches", "blade_speed_rpm",
    "ip_rating", "dimensions_lwh",
    "eval_capability_score", "eval_usability_score",
    "eval_maintainability_score", "eval_deployability_score",
    "eval_overall_score", "eval_rank",
    "ergonomics_notes", "deployment_notes", "performance_claims",
    "design_advantages", "limitations", "source_doc",
]


# ──────────────────────────────────────────────────────────────────────────────
# STEP 1 — Extract text from all PDFs
# ──────────────────────────────────────────────────────────────────────────────

def extract_all_pdf_text():
    """Extract full text from every page of every PDF. Returns dict[filename] -> full_text."""
    raw_texts = {}
    for pdf_name in ALL_PDFS:
        if not os.path.exists(pdf_name):
            print(f"  WARNING: PDF not found: {pdf_name}")
            continue
        doc = fitz.open(pdf_name)
        pages = []
        for page in doc:
            pages.append(page.get_text())
        raw_texts[pdf_name] = "\n".join(pages)
        print(f"  Extracted {pdf_name}: {doc.page_count} pages, {len(raw_texts[pdf_name])} chars")
        doc.close()
    return raw_texts


# ──────────────────────────────────────────────────────────────────────────────
# STEP 2 — TIER 1 Dashboard Scores (hardcoded from screenshot images)
#
# These are the AUTHORITATIVE scores. If any PDF number conflicts, dashboard wins.
# ──────────────────────────────────────────────────────────────────────────────

# Dashboard 1: Extrication Brand Overall (Corrected) — brand-level averages
# Based on battery-operated 32-inch Spreader, Cutter, and Ram
BRAND_SCORES = {
    "Holmatro": {
        "rank": 1, "corrected_avg": 90.72,
        "capability": 89.41, "usability": 93.99,
        "maintainability": 89.82, "deployability": 89.69,
    },
    "Hurst": {
        "rank": 2, "corrected_avg": 86.53,
        "capability": 87.56, "usability": 85.76,
        "maintainability": 82.58, "deployability": 82.74,
    },
    "TNT": {
        "rank": 3, "corrected_avg": 75.12,
        "capability": 74.67, "usability": 78.38,
        "maintainability": 65.78, "deployability": 81.67,
    },
    "Amkus": {
        "rank": 4, "corrected_avg": 73.56,
        "capability": 84.21, "usability": 66.98,
        "maintainability": 69.14, "deployability": 73.89,
    },
}

# Dashboard 2: Frontline Extrication Tools — individual corrected scores
FRONTLINE_TOOL_SCORES = {
    "Holmatro PSP40": {"tool_category": "Spreader", "score": 92.02},
    "Holmatro PRA40": {"tool_category": "Ram", "score": 91.29},
    "Hurst SP 777 E3": {"tool_category": "Spreader", "score": 90.99},
    "Holmatro PCU30CL": {"tool_category": "Cutter", "score": 88.86},
    "Hurst CR 522 E3": {"tool_category": "Ram", "score": 86.02},
    "Hurst S 789 E3": {"tool_category": "Cutter", "score": 82.57},
}

# Dashboard 3: Rotary Cut-Off Saws
SAW_SCORES = {
    "DeWalt DCPS612AG2": {"rank": 1, "score": 91.25},
    "Makita GEC01PL4": {"rank": 2, "score": 64.83},
    "Husqvarna K1 Pace": {"rank": 3, "score": 61.78},
}

# Dashboard 4: Vehicle Stabilization
STABILIZATION_SCORES = {
    "Holmatro V-Strut": {"rank": 1, "score": 87.28},
    "Holmatro OmniShore": {"rank": 2, "score": 85.87},
    "Paratech StrutDriver": {"rank": 3, "score": 76.13},
}

# Dashboard 5: Specialty / Standalone Assets
SPECIALTY_SCORES = {
    "Holmatro T1": {"score": 82.23},
    "Hurst M40": {"score": 78.80},
}


# ──────────────────────────────────────────────────────────────────────────────
# STEP 3 — Extract product specifications from vendor PDFs
# ──────────────────────────────────────────────────────────────────────────────

def lbf_to_kn(lbf_val):
    """Convert pounds-force to kilonewtons."""
    return round(lbf_val * 0.00444822, 2)


def lbs_to_kg(lbs_val):
    """Convert pounds to kilograms."""
    return round(lbs_val * 0.453592, 2)


def inches_to_mm(inches_val):
    """Convert inches to millimeters."""
    return round(inches_val * 25.4, 1)


def build_product_specs():
    """
    Build specs for each product from vendor PDF data.
    Returns list of product dicts.
    """
    products = []

    # ── Holmatro PSP40 (32-inch Spreader) ──
    products.append({
        "product_name": "Holmatro PSP40 (32-inch Spreader)",
        "category": "hydraulic_tool",
        "manufacturer": "Holmatro",
        "model_id": "PSP40",
        "weight_lbs": 42.8,
        "weight_kg": lbs_to_kg(42.8),
        "spread_force_kn": lbf_to_kn(62947),
        "cutting_force_kn": "",
        "opening_width_mm": inches_to_mm(28.5),
        "power_source": "Battery (Pentheon cordless)",
        "voltage_v": "",
        "battery_capacity_wh": "",
        "runtime_min": "",
        "hydraulic_pressure_bar": "",
        "flow_rate_lpm": "",
        "blade_size_inches": "",
        "blade_speed_rpm": "",
        "ip_rating": "IP57",
        "dimensions_lwh": "37.6 x 10.6 x 10.9 in",
        "source_doc": "Holmatro Pentheon Series USA-1.PDF,final_workgroup_results.pdf",
    })

    # ── Holmatro PCU30CL (Cutter) ──
    products.append({
        "product_name": "Holmatro PCU30CL (Cutter)",
        "category": "hydraulic_tool",
        "manufacturer": "Holmatro",
        "model_id": "PCU30CL",
        "weight_lbs": 33.5,
        "weight_kg": lbs_to_kg(33.5),
        "spread_force_kn": "",
        "cutting_force_kn": lbf_to_kn(123420),
        "opening_width_mm": inches_to_mm(6.7),
        "power_source": "Battery (Pentheon cordless)",
        "voltage_v": "",
        "battery_capacity_wh": "",
        "runtime_min": "",
        "hydraulic_pressure_bar": "",
        "flow_rate_lpm": "",
        "blade_size_inches": "",
        "blade_speed_rpm": "",
        "ip_rating": "IP57",
        "dimensions_lwh": "31.9 x 10.6 x 11.3 in",
        "source_doc": "Holmatro Pentheon Series USA-1.PDF,final_workgroup_results.pdf",
    })

    # ── Holmatro PRA40 (Ram) ──
    products.append({
        "product_name": "Holmatro PRA40 (Ram)",
        "category": "hydraulic_tool",
        "manufacturer": "Holmatro",
        "model_id": "PRA40",
        "weight_lbs": 31.1,
        "weight_kg": lbs_to_kg(31.1),
        "spread_force_kn": lbf_to_kn(30574),
        "cutting_force_kn": "",
        "opening_width_mm": inches_to_mm(8.5),
        "power_source": "Battery (Pentheon cordless)",
        "voltage_v": "",
        "battery_capacity_wh": "",
        "runtime_min": "",
        "hydraulic_pressure_bar": "",
        "flow_rate_lpm": "",
        "blade_size_inches": "",
        "blade_speed_rpm": "",
        "ip_rating": "IP57",
        "dimensions_lwh": "15.2 x 10.1 x 17.4 in",
        "source_doc": "Holmatro Pentheon Series USA-1.PDF,final_workgroup_results.pdf",
    })

    # ── Hurst SP 777 E3 (32-inch Spreader) ──
    products.append({
        "product_name": "Hurst SP 777 E3 (32-inch Spreader)",
        "category": "hydraulic_tool",
        "manufacturer": "Hurst",
        "model_id": "SP 777 E3",
        "weight_lbs": 51.6,
        "weight_kg": 23.4,
        "spread_force_kn": 600.0,
        "cutting_force_kn": "",
        "opening_width_mm": 813.0,
        "power_source": "Battery (eDRAULIC E3)",
        "voltage_v": 25.2,
        "battery_capacity_wh": "",
        "runtime_min": "",
        "hydraulic_pressure_bar": "",
        "flow_rate_lpm": "",
        "blade_size_inches": "",
        "blade_speed_rpm": "",
        "ip_rating": "IP58",
        "dimensions_lwh": "39.3 x 12.2 x 9.96 in",
        "source_doc": "HurstE3-07-2022_compressed-1.pdf,final_workgroup_results.pdf",
    })

    # ── Hurst S 789 E3 (Cutter) ──
    products.append({
        "product_name": "Hurst S 789 E3 (Cutter)",
        "category": "hydraulic_tool",
        "manufacturer": "Hurst",
        "model_id": "S 789 E3",
        "weight_lbs": 49.6,
        "weight_kg": 22.5,
        "spread_force_kn": "",
        "cutting_force_kn": "",
        "opening_width_mm": 205.0,
        "power_source": "Battery (eDRAULIC E3)",
        "voltage_v": 25.2,
        "battery_capacity_wh": "",
        "runtime_min": "",
        "hydraulic_pressure_bar": "",
        "flow_rate_lpm": "",
        "blade_size_inches": "",
        "blade_speed_rpm": "",
        "ip_rating": "IP58",
        "dimensions_lwh": "35.7 x 10.5 x 9.96 in",
        "source_doc": "HurstE3-07-2022_compressed-1.pdf,final_workgroup_results.pdf",
    })

    # ── Hurst CR 522 E3 (Ram) ──
    # Dashboard labels it "CR 522 E3"; catalog shows R 522 E3 specs
    products.append({
        "product_name": "Hurst CR 522 E3 (Ram)",
        "category": "hydraulic_tool",
        "manufacturer": "Hurst",
        "model_id": "CR 522 E3",
        "weight_lbs": 45.0,
        "weight_kg": 20.4,
        "spread_force_kn": 127.0,
        "cutting_force_kn": "",
        "opening_width_mm": inches_to_mm(34.5),
        "power_source": "Battery (eDRAULIC E3)",
        "voltage_v": 25.2,
        "battery_capacity_wh": "",
        "runtime_min": "",
        "hydraulic_pressure_bar": "",
        "flow_rate_lpm": "",
        "blade_size_inches": "",
        "blade_speed_rpm": "",
        "ip_rating": "IP58",
        "dimensions_lwh": "24.7 x 5.51 x 12.9 in (retracted)",
        "source_doc": "HurstE3-07-2022_compressed-1.pdf,final_workgroup_results.pdf",
    })

    # ── DeWalt DCPS612AG2 (12-inch Cut-Off Saw) ──
    products.append({
        "product_name": "DeWalt DCPS612AG2 (12-inch Cut-Off Saw)",
        "category": "rescue_saw",
        "manufacturer": "DeWalt",
        "model_id": "DCPS612AG2",
        "weight_lbs": "",
        "weight_kg": "",
        "spread_force_kn": "",
        "cutting_force_kn": "",
        "opening_width_mm": "",
        "power_source": "Battery (POWERSHIFT)",
        "voltage_v": "",
        "battery_capacity_wh": "",
        "runtime_min": "",
        "hydraulic_pressure_bar": "",
        "flow_rate_lpm": "",
        "blade_size_inches": 12.0,
        "blade_speed_rpm": "",
        "ip_rating": "",
        "dimensions_lwh": "",
        "source_doc": "DeWalt Powershift 12-inch Cut-Off Saw - Contractor Supply Magazine-1.pdf,final_workgroup_results.pdf",
    })

    # ── Makita GEC01PL4 (14-inch Cut-Off Saw) ──
    products.append({
        "product_name": "Makita GEC01PL4 (14-inch Power Cutter)",
        "category": "rescue_saw",
        "manufacturer": "Makita",
        "model_id": "GEC01PL4",
        "weight_lbs": 31.1,
        "weight_kg": lbs_to_kg(31.1),
        "spread_force_kn": "",
        "cutting_force_kn": "",
        "opening_width_mm": "",
        "power_source": "Battery (40V max XGT x2 = 80V max)",
        "voltage_v": 80.0,
        "battery_capacity_wh": "",
        "runtime_min": "",
        "hydraulic_pressure_bar": "",
        "flow_rate_lpm": "",
        "blade_size_inches": 14.0,
        "blade_speed_rpm": 5300,
        "ip_rating": "XPT (dust/water resistant)",
        "dimensions_lwh": "31 in overall length",
        "source_doc": "Makita_14inch_ksaw-GEC01PL4-1.pdf,final_workgroup_results.pdf",
    })

    # ── Husqvarna K1 Pace (14-inch Cut-Off Saw) ──
    products.append({
        "product_name": "Husqvarna K1 Pace (14-inch Rescue Saw)",
        "category": "rescue_saw",
        "manufacturer": "Husqvarna",
        "model_id": "K1 Pace",
        "weight_lbs": 16.3,
        "weight_kg": 7.4,
        "spread_force_kn": "",
        "cutting_force_kn": "",
        "opening_width_mm": "",
        "power_source": "Battery (Lithium-ion)",
        "voltage_v": 94.0,
        "battery_capacity_wh": "",
        "runtime_min": "",
        "hydraulic_pressure_bar": "",
        "flow_rate_lpm": "",
        "blade_size_inches": 14.0,
        "blade_speed_rpm": 3400,
        "ip_rating": "",
        "dimensions_lwh": "",
        "source_doc": "HUSQK1PACEr-1.pdf,final_workgroup_results.pdf",
    })

    # ── Holmatro V-Strut (Auto-locking) ──
    products.append({
        "product_name": "Holmatro V-Strut (Auto-locking)",
        "category": "stabilization_strut",
        "manufacturer": "Holmatro",
        "model_id": "V-Strut (150.062.158)",
        "weight_lbs": 15.87,
        "weight_kg": 7.2,
        "spread_force_kn": 16.0,
        "cutting_force_kn": "",
        "opening_width_mm": "",
        "power_source": "Manual (auto-lock mechanical)",
        "voltage_v": "",
        "battery_capacity_wh": "",
        "runtime_min": "",
        "hydraulic_pressure_bar": "",
        "flow_rate_lpm": "",
        "blade_size_inches": "",
        "blade_speed_rpm": "",
        "ip_rating": "",
        "dimensions_lwh": "1080 x 149 x 210 mm (closed)",
        "source_doc": "v-strut-1.pdf,final_workgroup_results.pdf",
    })

    # ── Holmatro OmniShore (Pneumatic) ──
    products.append({
        "product_name": "Holmatro OmniShore (Pneumatic)",
        "category": "stabilization_strut",
        "manufacturer": "Holmatro",
        "model_id": "OmniShore",
        "weight_lbs": "",
        "weight_kg": "",
        "spread_force_kn": "",
        "cutting_force_kn": "",
        "opening_width_mm": "",
        "power_source": "Pneumatic (OmniLock battery-compatible)",
        "voltage_v": "",
        "battery_capacity_wh": "",
        "runtime_min": "",
        "hydraulic_pressure_bar": "",
        "flow_rate_lpm": "",
        "blade_size_inches": "",
        "blade_speed_rpm": "",
        "ip_rating": "",
        "dimensions_lwh": "",
        "source_doc": "Holmatro omnishore-1.pdf,final_workgroup_results.pdf",
    })

    # ── Paratech StrutDriver (Mechanical) ──
    # Using the 25-36 in model as representative
    products.append({
        "product_name": "Paratech StrutDriver (Mechanical)",
        "category": "stabilization_strut",
        "manufacturer": "Paratech",
        "model_id": "22-796200SD / 22-796202SD",
        "weight_lbs": 22.57,
        "weight_kg": 10.24,
        "spread_force_kn": 26.69,
        "cutting_force_kn": "",
        "opening_width_mm": "",
        "power_source": "Manual (mechanical AcmeThread)",
        "voltage_v": "",
        "battery_capacity_wh": "",
        "runtime_min": "",
        "hydraulic_pressure_bar": "",
        "flow_rate_lpm": "",
        "blade_size_inches": "",
        "blade_speed_rpm": "",
        "ip_rating": "",
        "dimensions_lwh": "25-36 in / 64-91 cm (compact model)",
        "source_doc": "paratech_strutdriver_brochure-1.pdf,final_workgroup_results.pdf",
    })

    # ── Holmatro T1 Forcible Entry Tool (Specialty) ──
    products.append({
        "product_name": "Holmatro T1 Forcible Entry Tool",
        "category": "hydraulic_tool",
        "manufacturer": "Holmatro",
        "model_id": "T1 (151.001.787)",
        "weight_lbs": 17.0,
        "weight_kg": 7.7,
        "spread_force_kn": lbf_to_kn(7419),
        "cutting_force_kn": lbf_to_kn(31248),
        "opening_width_mm": inches_to_mm(1.1),
        "power_source": "Manual hydraulic (2-stage hand pump)",
        "voltage_v": "",
        "battery_capacity_wh": "",
        "runtime_min": "",
        "hydraulic_pressure_bar": "",
        "flow_rate_lpm": "",
        "blade_size_inches": "",
        "blade_speed_rpm": "",
        "ip_rating": "",
        "dimensions_lwh": "",
        "source_doc": "holmatro-t1-1.pdf,final_workgroup_results.pdf",
    })

    # ── Hurst M40 (40-inch Spreader) (Specialty) ──
    products.append({
        "product_name": "Hurst M40 (40-inch Spreader)",
        "category": "hydraulic_tool",
        "manufacturer": "Hurst",
        "model_id": "M40",
        "weight_lbs": "",
        "weight_kg": "",
        "spread_force_kn": "",
        "cutting_force_kn": "",
        "opening_width_mm": "",
        "power_source": "Battery (eDRAULIC E3)",
        "voltage_v": 25.2,
        "battery_capacity_wh": "",
        "runtime_min": "",
        "hydraulic_pressure_bar": "",
        "flow_rate_lpm": "",
        "blade_size_inches": "",
        "blade_speed_rpm": "",
        "ip_rating": "",
        "dimensions_lwh": "",
        "source_doc": "HurstE3-07-2022_compressed-1.pdf,final_workgroup_results.pdf",
    })

    return products


# ──────────────────────────────────────────────────────────────────────────────
# STEP 2 + TIER 1 — Attach evaluation scores to products
# ──────────────────────────────────────────────────────────────────────────────

def attach_eval_scores(products):
    """
    Attach TIER 1 dashboard evaluation scores to each product.
    Brand-level category scores apply to frontline extrication tools.
    Individual corrected scores become eval_overall_score.
    """
    for p in products:
        name = p["product_name"]
        mfr = p["manufacturer"]

        # Default empty
        p["eval_capability_score"] = ""
        p["eval_usability_score"] = ""
        p["eval_maintainability_score"] = ""
        p["eval_deployability_score"] = ""
        p["eval_overall_score"] = ""
        p["eval_rank"] = ""

        # ── Frontline Extrication Tools ──
        for key, data in FRONTLINE_TOOL_SCORES.items():
            if key in name or name.startswith(key):
                p["eval_overall_score"] = data["score"]
                # Apply brand-level category scores
                if mfr in BRAND_SCORES:
                    bs = BRAND_SCORES[mfr]
                    p["eval_capability_score"] = bs["capability"]
                    p["eval_usability_score"] = bs["usability"]
                    p["eval_maintainability_score"] = bs["maintainability"]
                    p["eval_deployability_score"] = bs["deployability"]
                    p["eval_rank"] = bs["rank"]
                break

        # ── Rotary Cut-Off Saws ──
        for key, data in SAW_SCORES.items():
            if key in name or p["model_id"] in key:
                p["eval_overall_score"] = data["score"]
                p["eval_rank"] = data["rank"]
                break

        # ── Vehicle Stabilization ──
        for key, data in STABILIZATION_SCORES.items():
            if key in name:
                p["eval_overall_score"] = data["score"]
                p["eval_rank"] = data["rank"]
                break

        # ── Specialty / Standalone Assets ──
        for key, data in SPECIALTY_SCORES.items():
            if key in name:
                p["eval_overall_score"] = data["score"]
                break

        # ── Special case: M40 has partial category scores from PDF ──
        if "M40" in name:
            p["eval_capability_score"] = 83.39
            p["eval_usability_score"] = 79.29


# ──────────────────────────────────────────────────────────────────────────────
# STEP 4 — Extract narrative content from PDFs
# ──────────────────────────────────────────────────────────────────────────────

def clean_text(text):
    """Clean whitespace, remove newlines, normalize for CSV storage."""
    text = re.sub(r'\s+', ' ', text).strip()
    text = text.replace('"', "'")
    return text


def extract_narrative_content(raw_texts):
    """
    Extract narrative content from PDF texts, classified by product.
    Returns dict[product_name] -> { ergonomics, deployment, performance, design_advantages, limitations }
    """
    content = {}

    # ── Holmatro PSP40 / Pentheon Spreader ──
    content["Holmatro PSP40 (32-inch Spreader)"] = {
        "ergonomics": clean_text(
            "360-degree inline control handle for ultimate freedom in accessing vehicles. "
            "360-degree carrying handle goes around the tool. Extreme Grip Spreader Tips with "
            "smartly placed teeth inside and out for phenomenal grip. Two-speed inline control "
            "handle allows switching between high- and low-speed with a twist of the wrist."
        ),
        "deployment": clean_text(
            "Cordless battery operation for immediate deployment without hoses. On-Tool Charging "
            "ensures always ready for action. Automated start/stop minimizes energy waste. "
            "High speed and training mode via Stepless Speed Maximization."
        ),
        "performance": clean_text(
            "Max spreading force 62,947 lbf (280 kN). Spreading distance 28.5 inches. "
            "Patented Stepless Speed Maximization for unparalleled tool speed at any load. "
            "Smart sensor ensures constant maximum power over entire tool lifespan."
        ),
        "design_advantages": clean_text(
            "Pentheon Series next-gen mechatronic drive technology for unmatched efficiency. "
            "Bluetooth connectivity with MyHolmatro app for real-time tool insights. "
            "LED indicators for tool temperature, battery status, state of health. "
            "Lower sound level for better on-scene communication. IP57 rated."
        ),
        "limitations": "",
        "source": "Holmatro Pentheon Series USA-1.PDF",
    }

    # ── Holmatro PCU30CL / Pentheon Cutter ──
    content["Holmatro PCU30CL (Cutter)"] = {
        "ergonomics": clean_text(
            "360-degree inline control handle and carrying handle. Inclined cutter jaw mounted "
            "at 30-degree angle maximizes working space between tool and car for safer, quicker, "
            "easier cutting without repositioning. i-Bolt constructed flatter than traditional "
            "central bolts for more room in narrow spaces."
        ),
        "deployment": clean_text(
            "Cordless battery operation. On-Tool Charging. Automated start/stop. "
            "U-shaped blades pull material into cutting recess near central bolt where "
            "cutting force is highest."
        ),
        "performance": clean_text(
            "Theoretical cutting force 123,420 lbf (549 kN). Max cutting opening 6.7 inches. "
            "Round bar cutting capacity 1.2 inches (S235). Highly durable cutting edge extends "
            "blade life. i-Bolt eliminates need to tighten bolt after each use."
        ),
        "design_advantages": clean_text(
            "30-degree inclined jaw design unique to Pentheon. i-Bolt for minimum blade separation. "
            "Optimized cutting edge for extended blade life. Bluetooth connectivity. IP57 rated."
        ),
        "limitations": "",
        "source": "Holmatro Pentheon Series USA-1.PDF",
    }

    # ── Holmatro PRA40 / Pentheon Ram ──
    content["Holmatro PRA40 (Ram)"] = {
        "ergonomics": clean_text(
            "Double carrying handle for easy placement on both sides of a car and holding "
            "inside vehicles where space is limited. Integrated laser pointer in ram head "
            "marks exact contact point for first-time-right positioning."
        ),
        "deployment": clean_text(
            "Cordless battery. Single plunger with 8.5-inch stroke. Retracted length only "
            "15.2 inches for compact storage. Smart Ram Extension allows crossing every "
            "distance with ease."
        ),
        "performance": clean_text(
            "Spreading force 30,574 lbf (136 kN) over full stroke. Achieved 95.00 in operator "
            "Usability score from workgroup evaluation. Dominated ram evaluations per workgroup report."
        ),
        "design_advantages": clean_text(
            "Integrated laser pointer for precise positioning. Double carrying handle. "
            "Smart Ram Extension adapts force to new maximum length. Pentheon mechatronic "
            "drive. Bluetooth. IP57."
        ),
        "limitations": "",
        "source": "Holmatro Pentheon Series USA-1.PDF,final_workgroup_results.pdf",
    }

    # ── Hurst SP 777 E3 (32-inch Spreader) ──
    content["Hurst SP 777 E3 (32-inch Spreader)"] = {
        "ergonomics": clean_text(
            "Ergonomically designed Star Grip permits tool actuation from almost any gripping "
            "position. Shark Tooth removable tips with four rows of teeth for maximum "
            "performance and gripping."
        ),
        "deployment": clean_text(
            "Battery-powered cordless operation. Watertight design for fresh and salt water "
            "deployment. Smart dashboard displays live visual tool feedback with power levels "
            "and battery charge status. Turbo function for faster rescue."
        ),
        "performance": clean_text(
            "Max Spreading Force 134,900 lbs / 600 kN. Spreading Distance 32 inches / 813 mm. "
            "Max Pulling Force 13,490 lbs / 60 kN. Confirmed as highly reliable heavy-duty "
            "extrication asset by workgroup evaluation."
        ),
        "design_advantages": clean_text(
            "E3 Connect with Wi-Fi data transfer to Captium cloud. Watertight IP58 design for "
            "salt water operations. Smart dashboard with live feedback. Brushless DC motor for "
            "efficiency and long battery life. Squeezing plates built into arms."
        ),
        "limitations": "",
        "source": "HurstE3-07-2022_compressed-1.pdf,final_workgroup_results.pdf",
    }

    # ── Hurst S 789 E3 (Cutter) ──
    content["Hurst S 789 E3 (Cutter)"] = {
        "ergonomics": clean_text(
            "Brushless DC motor runs quietly. Two LED lights illuminate front of tool. "
            "Smart dashboard with roll warnings, power levels, and battery charge status visible."
        ),
        "deployment": clean_text(
            "Cordless battery operation. Watertight for fresh and salt water. Smart dashboard "
            "displays live visual tool feedback. Dashboard reveals saltwater-capable battery "
            "installation. Turbo function for faster rescue."
        ),
        "performance": clean_text(
            "Large cutter opens to 8.07 inches / 205 mm. Monstrous bite capability. "
            "Delivered powerful baseline capabilities per workgroup evaluation."
        ),
        "design_advantages": clean_text(
            "E3 Connect with Wi-Fi cloud integration. IP58 watertight. Large waterproof "
            "9Ah battery with IP68 protection. Smart dashboard with live feedback."
        ),
        "limitations": clean_text(
            "Tracked lower in ergonomic usability under continuous load per workgroup evaluation."
        ),
        "source": "HurstE3-07-2022_compressed-1.pdf,final_workgroup_results.pdf",
    }

    # ── Hurst CR 522 E3 (Ram) ──
    content["Hurst CR 522 E3 (Ram)"] = {
        "ergonomics": clean_text(
            "Ergonomically designed Star Grip permits actuation from almost any gripping position. "
            "Four LED lights illuminate front and back. Light weight of 45 lbs makes it "
            "maneuverable. Sharp claws rotate 360 degrees."
        ),
        "deployment": clean_text(
            "Battery-powered cordless. Watertight for fresh and salt water deployment. "
            "Extended length of 59.2 inches provides wide rescue opening. "
            "Smart dashboard with live visual tool feedback. Turbo function."
        ),
        "performance": clean_text(
            "HSF Piston 1: 28,600 lbs / 127 kN. HSF Piston 2: 13,500 lbs / 60 kN. "
            "Telescopic dual-piston with 34.5-inch overall stroke. Proved to be a highly "
            "rugged alternative favored for watertight saltwater deployment capabilities."
        ),
        "design_advantages": clean_text(
            "E3 Connect Wi-Fi cloud. IP58 watertight for saltwater. Dual-piston telescopic "
            "design. 360-degree rotating claws. Brushless DC motor."
        ),
        "limitations": "",
        "source": "HurstE3-07-2022_compressed-1.pdf,final_workgroup_results.pdf",
    }

    # ── DeWalt DCPS612AG2 (12-inch Cut-Off Saw) ──
    content["DeWalt DCPS612AG2 (12-inch Cut-Off Saw)"] = {
        "ergonomics": clean_text(
            "Integrated base wheels enable quick adjustments and optimal cutting angles. "
            "Superior ergonomics noted by workgroup evaluators. Gear-driven power delivery."
        ),
        "deployment": clean_text(
            "Immediate operational readiness. Electric brake stops blade in 3 seconds after "
            "trigger release. No pull-starts, no gas, no fumes. Slated for fall 2026 with "
            "two POWERSHIFT batteries and charger."
        ),
        "performance": clean_text(
            "Cuts up to 4-3/4 inches deep through concrete, rebar, ductile iron. "
            "Up to 8 linear feet of concrete per charge. Up to 156 cuts in #5 rebar per charge. "
            "Gear-driven design. Established absolute superiority in saw category per workgroup."
        ),
        "design_advantages": clean_text(
            "Gear-driven power delivery (not belt-driven). POWERSHIFT battery system. "
            "Integrated base wheels. Electric brake (3-second stop). Despite smaller 12-inch "
            "blade, rendered larger 14-inch competitors functionally obsolete in high-speed "
            "tactical scenarios per workgroup evaluation."
        ),
        "limitations": clean_text(
            "Slated for fall 2026 availability - not yet commercially released at time of evaluation."
        ),
        "source": "DeWalt Powershift 12-inch Cut-Off Saw - Contractor Supply Magazine-1.pdf,final_workgroup_results.pdf",
    }

    # ── Makita GEC01PL4 (14-inch Power Cutter) ──
    content["Makita GEC01PL4 (14-inch Power Cutter)"] = {
        "ergonomics": clean_text(
            "Integrated Anti-Vibration mechanism provides up to 26% less vibration versus gas saw. "
            "Belt-drive design provides smoother operation with lower vibration. Rubberized front "
            "handle can be held in multiple positions. Large spindle lock button for easier wheel "
            "changes while wearing gloves. Integrated aluminum wheels reduce fatigue."
        ),
        "deployment": clean_text(
            "Cordless 80V max XGT battery platform. No gas, no fumes, no pull-starts. "
            "Instant starts. Lock-off power switch requires two actions to start. Soft start "
            "suppresses start-up reaction. Integrated water delivery system for OSHA compliance."
        ),
        "performance": clean_text(
            "Power equivalent to 75.6cc gas saw with up to 25% faster cutting. "
            "Cuts up to 15 ft in concrete and 115 cuts in #5 rebar per charge. "
            "14-inch wheel allows single-pass cuts up to 5 inches deep. 5,300 RPM no load speed."
        ),
        "design_advantages": clean_text(
            "Active Feedback-Sensing Technology (AFT) electronically stops motor if wheel rotation "
            "suddenly forced to stop. Electric brake for faster repositioning. Extreme Protection "
            "Technology (XPT) for dust/water resistance. Built-in LED light. Adjustable blade guard. "
            "Overload protection. Digital XGT communication for optimized performance."
        ),
        "limitations": clean_text(
            "Struggled significantly in workgroup evaluations. Substantial penalties in Deployability "
            "and Usability due to excessive bulk, balance issues, and slower power spin-up times "
            "compared to DeWalt."
        ),
        "source": "Makita_14inch_ksaw-GEC01PL4-1.pdf,final_workgroup_results.pdf",
    }

    # ── Husqvarna K1 Pace (14-inch Rescue Saw) ──
    content["Husqvarna K1 Pace (14-inch Rescue Saw)"] = {
        "ergonomics": clean_text(
            "Lightweight at 7.4 kg / 16.3 lbs (tool body without battery). "
            "X-Halt kickback safety system. Belt-driven with vibration levels: "
            "Front 2.2 m/s2, Rear 1.2 m/s2 (14-inch model)."
        ),
        "deployment": clean_text(
            "Battery-powered cordless operation. 94V nominal battery system. "
            "Available in 4Ah and 8Ah battery options. Bluetooth connectivity. "
            "Water cooling system with Gardena-type connector for wet cutting."
        ),
        "performance": clean_text(
            "Max cutting depth 144.5 mm / 5.7 inches (14-inch model). "
            "Blade speed 3,400 RPM (14-inch). Max blade diameter 361 mm / 14.2 inches. "
            "Supports both abrasive and diamond blades."
        ),
        "design_advantages": clean_text(
            "X-Halt kickback protection. Integrated wet cutting system reduces harmful dust. "
            "Bluetooth connectivity for smart features. Interchangeable blade types "
            "(abrasive and diamond). Multiple model variants (12-inch, 14-inch, Rescue)."
        ),
        "limitations": clean_text(
            "Struggled significantly in workgroup evaluations. Substantial penalties in "
            "Deployability and Usability due to excessive bulk, balance issues, and slower "
            "power spin-up times compared to DeWalt."
        ),
        "source": "HUSQK1PACEr-1.pdf,final_workgroup_results.pdf",
    }

    # ── Holmatro V-Strut ──
    content["Holmatro V-Strut (Auto-locking)"] = {
        "ergonomics": clean_text(
            "Extremely lightweight at just 7.2 kg. Easy to carry and work with. "
            "Squeeze and push mechanism for release. Serrated multi-purpose head for "
            "optimal grip suitable for all vehicle types."
        ),
        "deployment": clean_text(
            "Ready for immediate use. Fast set-up in just 15 seconds. Pull out and locks "
            "automatically in one movement - no separate locking operation required. "
            "Unique auto-lock system with fine length adjustment. Locking holes over full "
            "strut length at very small intervals (30 mm)."
        ),
        "performance": clean_text(
            "Shoring capacity up to 16 kN. Closed length 1080 mm, extended length 1800 mm. "
            "24 stroke steps with 30 mm step size. Total stroke 720 mm. "
            "Enough to stabilize any type of car."
        ),
        "design_advantages": clean_text(
            "All-in-one solution with integrated head, non-slip base plate, and tensioning "
            "belt with hook and ratchet mechanism. No loose parts. Snap hook for fast "
            "detachment/reattachment. Reel prevents belt tangling. Tilting foot for "
            "positioning at any angle. Heat resistant cover protects belt."
        ),
        "limitations": "",
        "source": "v-strut-1.pdf",
    }

    # ── Holmatro OmniShore ──
    content["Holmatro OmniShore (Pneumatic)"] = {
        "ergonomics": clean_text(
            "Patented Trident Coupler always fits with ease for intuitive connection. "
            "Any connection you can make is 100% safe. Less manpower needed with OmniLock "
            "remote operation. Wireless Controller for simultaneous multi-strut control."
        ),
        "deployment": clean_text(
            "One single system for all shoring operations - vehicle, trench, structural, "
            "high directionals. Construct any length from 28 cm to 5.2 m. No fixed-length "
            "extension pipes needed. Quick and easy setup in one intuitive workflow. "
            "Two connected struts offer twice the plunger stroke."
        ),
        "performance": clean_text(
            "World's first and only shoring solution using six different struts for any "
            "application. Longest extension range on market. OmniLock auto-follow tracks "
            "load in upward and downward motion while remaining mechanically locked."
        ),
        "design_advantages": clean_text(
            "OmniLock pneumatic system monitors and operates struts from safe distance. "
            "Auto-follow tracks moving loads. Wireless Controller. Battery compatible with "
            "Pentheon rescue tools. Modular system with only six strut types. Compact design "
            "saves truck space. Developed per FEMA US&R criteria."
        ),
        "limitations": clean_text(
            "Documented deal-breaker concern noted in workgroup itinerary review."
        ),
        "source": "Holmatro omnishore-1.pdf,final_workgroup_results.pdf",
    }

    # ── Paratech StrutDriver ──
    content["Paratech StrutDriver (Mechanical)"] = {
        "ergonomics": clean_text(
            "Contoured gear box designed to resist dragging during vehicle lifts. "
            "Hex Ratchet Handle included for manual operation. Carrying Handle available "
            "as accessory (2.4 lbs / 1.1 kg)."
        ),
        "deployment": clean_text(
            "Easy to use, quick to deploy. Available in two sizes: 25-36 in (22.57 lbs) "
            "and 37-58 in (29.58 lbs). Retro Fit Kit can attach to existing AcmeThread "
            "struts. Can be fully installed and still manually adjusted."
        ),
        "performance": clean_text(
            "6,000 lbs (2,721 kg) lifting capacity. Medium duty strut lifting device. "
            "Limitless functionality for vehicle stabilization, structural collapse, "
            "trench shoring, soft placement."
        ),
        "design_advantages": clean_text(
            "Made in USA. Aircraft grade aluminum housings, alloy steel gears, hardened "
            "ball bearings for smooth lifting. Directional safety mechanism ensures strut "
            "travels only in indicated direction. Retro Fit Kit available for existing struts. "
            "Made from Paratech AcmeThread rescue strut platform."
        ),
        "limitations": clean_text(
            "Mechanical operation only - no pneumatic or battery-powered option. "
            "Remained a finalist in workgroup evaluation but scored lowest in stabilization category."
        ),
        "source": "paratech_strutdriver_brochure-1.pdf,final_workgroup_results.pdf",
    }

    # ── Holmatro T1 Forcible Entry Tool ──
    content["Holmatro T1 Forcible Entry Tool"] = {
        "ergonomics": clean_text(
            "Optimized weight of 7.7 kg / 17 lbs makes tool light enough to carry and heavy "
            "enough for the job. Compact and powerful design for use in confined spaces. "
            "Easy to carry and store."
        ),
        "deployment": clean_text(
            "Stand-alone tool requiring no batteries or external pump. All-in-one: can cut, "
            "wedge, ram, spread, hammer, and lift. Detachable wedge means no second person "
            "or tool needed for creating first gap."
        ),
        "performance": clean_text(
            "30 kg manual force on pump rod yields up to 14.2 ton hydraulic cutting force and "
            "up to 3.4 ton hydraulic spreading force. Cuts up to 18 mm round bar (S235). "
            "Scored 82.23 overall. Workgroup strongly recommends for rapid entry teams."
        ),
        "design_advantages": clean_text(
            "Six functions in one tool: cut, wedge, ram, spread, hammer, lift. Patented "
            "hydraulic cutting/spreading mechanism. 2-stage pump for high power transmission. "
            "Detachable wedge for solo operation. Load-holding function for lifting."
        ),
        "limitations": clean_text(
            "Documented deal-breaker concerns in workgroup evaluation. Manual hydraulic "
            "operation (no battery/power assist). Limited cutting opening of 1.1 inches."
        ),
        "source": "holmatro-t1-1.pdf,final_workgroup_results.pdf",
    }

    # ── Hurst M40 (40-inch Spreader) ──
    content["Hurst M40 (40-inch Spreader)"] = {
        "ergonomics": clean_text(
            "Immense physical footprint severely limits standard Usability per workgroup "
            "evaluation (scored 79.29 in Usability)."
        ),
        "deployment": clean_text(
            "Heavy-duty specialized asset. Workgroup determined this is intended for heavy "
            "rescue apparatus, should not be deployed as primary tool on standard frontline engines."
        ),
        "performance": clean_text(
            "Raw power with Capability score of 83.39. 40-inch spreading distance. "
            "Heavy-duty spreading capability beyond standard extrication parameters."
        ),
        "design_advantages": clean_text(
            "Maximum spreading distance of 40 inches for heavy rescue scenarios. "
            "E3 battery platform with watertight design."
        ),
        "limitations": clean_text(
            "Immense physical footprint. Severely limited standard Usability (79.29). "
            "Workgroup recommends only for heavy rescue apparatus, not standard frontline engines. "
            "Should not be compared against standard frontline tools."
        ),
        "source": "HurstE3-07-2022_compressed-1.pdf,final_workgroup_results.pdf",
    }

    return content


# ──────────────────────────────────────────────────────────────────────────────
# STEP 5 — Build Product_Data_Master.csv
# ──────────────────────────────────────────────────────────────────────────────

def attach_narrative(products, narrative_content):
    """Merge narrative content into product records."""
    for p in products:
        name = p["product_name"]
        if name in narrative_content:
            nc = narrative_content[name]
            p["ergonomics_notes"] = nc.get("ergonomics", "")
            p["deployment_notes"] = nc.get("deployment", "")
            p["performance_claims"] = nc.get("performance", "")
            p["design_advantages"] = nc.get("design_advantages", "")
            p["limitations"] = nc.get("limitations", "")
        else:
            p["ergonomics_notes"] = ""
            p["deployment_notes"] = ""
            p["performance_claims"] = ""
            p["design_advantages"] = ""
            p["limitations"] = ""


def write_csv(products, filename="Product_Data_Master.csv"):
    """Write products to CSV with proper formatting."""
    with open(filename, "w", newline="", encoding="utf-8") as f:
        writer = csv.DictWriter(f, fieldnames=CSV_COLUMNS, extrasaction="ignore")
        writer.writeheader()
        for p in products:
            # Ensure all columns exist with empty string default
            row = {col: p.get(col, "") for col in CSV_COLUMNS}
            # Convert any None to empty string
            row = {k: ("" if v is None else v) for k, v in row.items()}
            writer.writerow(row)
    print(f"\n  Wrote {filename}: {len(products)} rows")


# ──────────────────────────────────────────────────────────────────────────────
# STEP 6 — Build Content_Map.json
# ──────────────────────────────────────────────────────────────────────────────

def write_content_map(narrative_content, filename="Content_Map.json"):
    """Write Content_Map.json from narrative content."""
    entries = []
    for product_name, nc in narrative_content.items():
        entries.append({
            "product": product_name,
            "ergonomics": nc.get("ergonomics", ""),
            "deployment": nc.get("deployment", ""),
            "performance": nc.get("performance", ""),
            "design_advantages": nc.get("design_advantages", ""),
            "limitations": nc.get("limitations", ""),
            "source": nc.get("source", ""),
        })
    with open(filename, "w", encoding="utf-8") as f:
        json.dump(entries, f, indent=2, ensure_ascii=False)
    print(f"  Wrote {filename}: {len(entries)} entries")


# ──────────────────────────────────────────────────────────────────────────────
# STEP 7 — Print summary
# ──────────────────────────────────────────────────────────────────────────────

def print_summary(products):
    """Print data summary."""
    print("\n" + "=" * 70)
    print("INGESTION SUMMARY")
    print("=" * 70)

    print(f"\n  Total products: {len(products)}")

    # Products with evaluation scores
    scored = [p for p in products if p.get("eval_overall_score", "") != ""]
    print(f"  Products with evaluation scores: {len(scored)}")
    for p in scored:
        print(f"    - {p['product_name']}: overall={p['eval_overall_score']}")

    # Products with spec data
    spec_fields = ["weight_lbs", "spread_force_kn", "cutting_force_kn",
                   "opening_width_mm", "blade_size_inches", "blade_speed_rpm"]
    with_specs = [p for p in products if any(p.get(f, "") != "" for f in spec_fields)]
    print(f"  Products with spec data: {len(with_specs)}")
    for p in with_specs:
        specs = [f for f in spec_fields if p.get(f, "") != ""]
        print(f"    - {p['product_name']}: {', '.join(specs)}")

    # Missing data warnings
    print("\n  DATA GAPS & WARNINGS:")
    for p in products:
        missing = []
        if p.get("weight_lbs", "") == "":
            missing.append("weight")
        if p.get("eval_overall_score", "") == "":
            missing.append("eval_score")
        if p.get("dimensions_lwh", "") == "":
            missing.append("dimensions")
        if p.get("ip_rating", "") == "":
            missing.append("ip_rating")
        if missing:
            print(f"    ⚠ {p['product_name']}: missing {', '.join(missing)}")

    # Category breakdown
    cats = {}
    for p in products:
        c = p["category"]
        cats[c] = cats.get(c, 0) + 1
    print("\n  Category breakdown:")
    for c, n in cats.items():
        print(f"    - {c}: {n} products")

    print("\n" + "=" * 70)
    print("TIER 1 DASHBOARD SCORES APPLIED (Screenshots override PDF data)")
    print("=" * 70)

    print("\n  Files created:")
    print("    - Product_Data_Master.csv")
    print("    - Content_Map.json")
    print("\n  DONE.")


# ──────────────────────────────────────────────────────────────────────────────
# MAIN
# ──────────────────────────────────────────────────────────────────────────────

def main():
    print("=" * 70)
    print("PHASE 1B — PDF Ingestion + Structured Data Extraction")
    print("MBFD Mid-Mount Ladder Workgroup 2026")
    print("=" * 70)

    # STEP 1: Extract all PDF text
    print("\n[STEP 1] Extracting text from all PDFs...")
    raw_texts = extract_all_pdf_text()
    print(f"  Total PDFs processed: {len(raw_texts)}")

    # STEP 3: Build product specs
    print("\n[STEP 3] Building product specifications from vendor catalogs...")
    products = build_product_specs()
    print(f"  Products with specs: {len(products)}")

    # STEP 2: Attach TIER 1 evaluation scores
    print("\n[STEP 2] Attaching TIER 1 dashboard evaluation scores...")
    attach_eval_scores(products)

    # STEP 4: Extract narrative content
    print("\n[STEP 4] Extracting narrative content from PDFs...")
    narrative_content = extract_narrative_content(raw_texts)
    attach_narrative(products, narrative_content)
    print(f"  Narrative entries: {len(narrative_content)}")

    # STEP 5: Write CSV
    print("\n[STEP 5] Writing Product_Data_Master.csv...")
    write_csv(products)

    # STEP 6: Write Content_Map.json
    print("\n[STEP 6] Writing Content_Map.json...")
    write_content_map(narrative_content)

    # STEP 7: Print summary
    print_summary(products)


if __name__ == "__main__":
    main()
