#!/usr/bin/env python3
"""
MBFD Support AI — Manual Ingestion Script
==========================================
Parses 4 source files, chunks text, embeds via Cloudflare AI API,
and inserts vectors into the mbfd-rag-index Vectorize index.

Sources:
  - PUC_Engine_manual.pdf      → source: "puc_engine"
  - Support Services SOG.md    → source: "support_sog"
  - L1_L11_manual.pdf          → source: "l1_l11"
  - L3_manual.pdf              → source: "l3"

Usage:
  python scripts/ai/ingest_manuals.py
"""

import os
import re
import sys
import json
import time
import uuid
import requests
import fitz  # PyMuPDF

# Force UTF-8 output to avoid Windows cp1252 codec errors
if hasattr(sys.stdout, 'reconfigure'):
    sys.stdout.reconfigure(encoding='utf-8')

# ── Configuration ─────────────────────────────────────────────────────────────
CF_API_TOKEN   = "U6XGuhQXd5JwIrkuIprFiXA_OvyCqd6ZQeLs_cmZ"
CF_ACCOUNT_ID  = "265122b6d6f29457b0ca950c55f3ac6e"
EMBED_MODEL    = "@cf/baai/bge-large-en-v1.5"
INDEX_NAME     = "mbfd-rag-index"

CHUNK_WORDS    = 400   # target words per chunk (smaller = more granular, better retrieval)
OVERLAP_WORDS  = 60    # word overlap between chunks
BATCH_SIZE     = 50    # vectors per Vectorize insert call

SOURCES = [
    {
        "path": r"C:\Users\Peter Darley\Downloads\PUC_Engine_manual.pdf",
        "source_tag": "puc_engine",
        "type": "pdf",
    },
    {
        "path": r"C:\Users\Peter Darley\Downloads\Support Services SOG.md",
        "source_tag": "support_sog",
        "type": "md",
    },
    {
        "path": r"C:\Users\Peter Darley\Downloads\L1_L11_manual.pdf",
        "source_tag": "l1_l11",
        "type": "pdf",
    },
    {
        "path": r"C:\Users\Peter Darley\Downloads\L3_manual.pdf",
        "source_tag": "l3",
        "type": "pdf",
    },
    {
        "path": r"C:\Users\Peter Darley\Downloads\driver_manual.pdf",
        "source_tag": "driver_manual",
        "type": "pdf",
    },
]

# ── Helpers ───────────────────────────────────────────────────────────────────

def extract_pdf_text(path: str) -> str:
    """Extract all text from a PDF file using PyMuPDF."""
    doc = fitz.open(path)
    pages = []
    for page in doc:
        text = page.get_text("text")
        if text.strip():
            pages.append(text)
    doc.close()
    return "\n".join(pages)


def extract_md_text(path: str) -> str:
    """Read a Markdown file as plain text."""
    with open(path, "r", encoding="utf-8") as f:
        return f.read()


def chunk_text(text: str, chunk_words: int = CHUNK_WORDS, overlap: int = OVERLAP_WORDS):
    """
    Split text into overlapping word-based chunks.
    Returns list of (chunk_index, chunk_text) tuples.
    """
    # Normalise whitespace
    text = re.sub(r"\s+", " ", text).strip()
    words = text.split(" ")
    chunks = []
    start = 0
    idx = 0
    while start < len(words):
        end = min(start + chunk_words, len(words))
        chunk = " ".join(words[start:end]).strip()
        if len(chunk) > 50:   # skip tiny fragments
            chunks.append((idx, chunk))
            idx += 1
        start += chunk_words - overlap
    return chunks


def embed_texts(texts: list[str]) -> list[list[float]]:
    """
    Call Cloudflare Workers AI to embed a batch of texts.
    Returns list of 1024-dim float vectors.
    """
    url = f"https://api.cloudflare.com/client/v4/accounts/{CF_ACCOUNT_ID}/ai/run/{EMBED_MODEL}"
    headers = {
        "Authorization": f"Bearer {CF_API_TOKEN}",
        "Content-Type": "application/json",
    }
    payload = {"text": texts}
    resp = requests.post(url, headers=headers, json=payload, timeout=60)
    if resp.status_code != 200:
        raise RuntimeError(f"Embedding API error {resp.status_code}: {resp.text[:300]}")
    data = resp.json()
    if not data.get("success"):
        raise RuntimeError(f"Embedding returned success=false: {data}")
    return data["result"]["data"]


def insert_vectors(vectors: list[dict]):
    """
    Insert a batch of vectors into Cloudflare Vectorize via REST API.
    Each vector dict: { id, values, metadata }
    """
    url = f"https://api.cloudflare.com/client/v4/accounts/{CF_ACCOUNT_ID}/vectorize/v2/indexes/{INDEX_NAME}/upsert"
    headers = {
        "Authorization": f"Bearer {CF_API_TOKEN}",
        "Content-Type": "application/x-ndjson",
    }
    # Vectorize upsert expects NDJSON
    ndjson_body = "\n".join(json.dumps(v) for v in vectors)
    resp = requests.post(url, headers=headers, data=ndjson_body.encode("utf-8"), timeout=60)
    if resp.status_code not in (200, 201):
        raise RuntimeError(f"Vectorize upsert error {resp.status_code}: {resp.text[:300]}")
    result = resp.json()
    if not result.get("success"):
        raise RuntimeError(f"Vectorize upsert failed: {result}")
    return result


# ── Main ──────────────────────────────────────────────────────────────────────

def process_source(source_cfg: dict) -> list[dict]:
    """Extract text, chunk, and return vector dicts ready for embedding+insert."""
    path       = source_cfg["path"]
    source_tag = source_cfg["source_tag"]
    file_type  = source_cfg["type"]

    print(f"\n{'='*60}")
    print(f"Processing: {os.path.basename(path)}  (tag={source_tag})")

    if not os.path.exists(path):
        print(f"  WARNING: File not found: {path} -- skipping.")
        return []

    if file_type == "pdf":
        raw_text = extract_pdf_text(path)
    else:
        raw_text = extract_md_text(path)

    print(f"  Extracted {len(raw_text):,} characters")

    if len(raw_text) < 100:
        print(f"  WARNING: Very little text extracted. PDF may be image-only/scanned.")
        print(f"           Skipping this source -- please provide a text-based PDF or OCR'd version.")
        return []

    chunks = chunk_text(raw_text)
    print(f"  Split into {len(chunks)} chunks")

    records = []
    for chunk_idx, chunk_text_str in chunks:
        records.append({
            "chunk_index": chunk_idx,
            "text":        chunk_text_str,
            "source":      source_tag,
        })
    return records


def run_ingestion():
    all_records = []
    for src in SOURCES:
        records = process_source(src)
        all_records.extend(records)

    total = len(all_records)
    print(f"\n\n{'='*60}")
    print(f"Total chunks to ingest: {total}")
    print(f"Embedding and uploading in batches of {BATCH_SIZE}...")

    inserted = 0
    for batch_start in range(0, total, BATCH_SIZE):
        batch = all_records[batch_start : batch_start + BATCH_SIZE]
        texts = [r["text"] for r in batch]

        # Embed
        try:
            vectors = embed_texts(texts)
        except Exception as e:
            print(f"  ERROR: Embedding failed for batch {batch_start}: {e}")
            sys.exit(1)

        # Build vector dicts
        vector_dicts = []
        for i, record in enumerate(batch):
            vector_dicts.append({
                "id":       str(uuid.uuid4()),
                "values":   vectors[i],
                "metadata": {
                    "source":      record["source"],
                    "chunk_index": record["chunk_index"],
                    "text":        record["text"][:4000],  # Vectorize metadata limit ~10KB
                },
            })

        # Insert
        try:
            result = insert_vectors(vector_dicts)
            count  = result.get("result", {}).get("count", len(vector_dicts))
            inserted += count
            print(f"  OK Batch {batch_start//BATCH_SIZE + 1}: inserted {count} vectors  (total so far: {inserted})")
        except Exception as e:
            print(f"  ERROR: Insert failed for batch {batch_start}: {e}")
            sys.exit(1)

        # Small delay to avoid rate-limit
        time.sleep(0.5)

    print(f"\nDONE: {inserted} vectors inserted into '{INDEX_NAME}'")
    print("\nSource breakdown:")
    from collections import Counter
    counts = Counter(r["source"] for r in all_records)
    for src, cnt in sorted(counts.items()):
        print(f"  {src}: {cnt} chunks")


if __name__ == "__main__":
    run_ingestion()
