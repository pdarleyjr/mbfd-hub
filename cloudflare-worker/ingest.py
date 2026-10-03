#!/usr/bin/env python3
"""
MBFD RAG Document Ingestion Script
Reads PDF/DOCX files, chunks text, generates embeddings via Cloudflare Workers AI,
and inserts vectors into Cloudflare Vectorize.
"""

import argparse
import hashlib
import json
import os
from pathlib import Path
import sys
import time
import uuid
from urllib.error import HTTPError
from urllib.request import Request, urlopen

# --- Configuration ---
CF_ACCOUNT_ID = "265122b6d6f29457b0ca950c55f3ac6e"
CF_API_TOKEN = os.environ.get("CLOUDFLARE_API_TOKEN", "")
VECTORIZE_INDEX = "mbfd-rag-index"
EMBEDDING_MODEL = "@cf/baai/bge-large-en-v1.5"
CHUNK_SIZE = 400  # words per chunk
CHUNK_OVERLAP = 80  # overlapping words
SOG_MANIFEST_SHA256 = "82a32d7127e42604f77f591e39a26341844b2c7010e5f1b148d20472aaf20b6c"
SOG_NAMESPACE = "sog-r2-82a32d7127e4"
SOG_EDITION = "MBFD-COORDINATED-20261002-R2"
SOG_CHUNKS = 2160
SOG_CORPUS_SHA256 = "d38246146776853a83deaa9b6e0e35775af4090f2a03a4b3f6272d7d4ae2bf17"
REFERENCE_NAMESPACE = "mbfd-support-reference"

CF_AI_URL = f"https://api.cloudflare.com/client/v4/accounts/{CF_ACCOUNT_ID}/ai/run/{EMBEDDING_MODEL}"
CF_VECTORIZE_URL = f"https://api.cloudflare.com/client/v4/accounts/{CF_ACCOUNT_ID}/vectorize/v2/indexes/{VECTORIZE_INDEX}"

HEADERS = {
    "Authorization": f"Bearer {CF_API_TOKEN}",
    "Content-Type": "application/json",
}


def cloudflare_post(url, body, ndjson=False):
    """Use the standard library for the existing Cloudflare REST calls."""
    if ndjson:
        # Match the locked Wrangler V2 upsert's `vectors` NDJSON file part.
        boundary = "mbfd-vectorize-" + uuid.uuid4().hex
        data = (f'--{boundary}\r\nContent-Disposition: form-data; name="vectors"; filename="vectors.ndjson"\r\n'
                'Content-Type: application/x-ndjson\r\n\r\n').encode("utf-8")
        data += body.encode("utf-8") + f'\r\n--{boundary}--\r\n'.encode("utf-8")
        headers = HEADERS | {"Content-Type": f"multipart/form-data; boundary={boundary}"}
    else:
        data = json.dumps(body).encode("utf-8")
        headers = HEADERS
    try:
        response = urlopen(Request(url, data=data, headers=headers, method="POST"), timeout=120)
    except HTTPError as error:
        response = error
    with response:
        return response.status, json.loads(response.read())


def is_retired_sog_source(source):
    import re
    name = Path(source).name.lower()
    return bool(re.search(r"(?:^|[^a-z])sogs?(?:[^a-z]|$)|standard[ _-]operating", name)) or name in {
        "extra_info_for_ai.pdf", "driver_manual", "driver_manual.pdf",
    }


def extract_text_from_pdf(filepath):
    """Extract text from a PDF file using PyPDF2 or pdfplumber."""
    try:
        import fitz
        with fitz.open(filepath) as pdf:
            return [(i + 1, page.get_text()) for i, page in enumerate(pdf)]
    except ImportError:
        pass

    try:
        import pdfplumber
        text_pages = []
        with pdfplumber.open(filepath) as pdf:
            for i, page in enumerate(pdf.pages):
                t = page.extract_text()
                text_pages.append((i + 1, t or ""))
        return text_pages
    except ImportError:
        pass

    try:
        from PyPDF2 import PdfReader
        reader = PdfReader(filepath)
        text_pages = []
        for i, page in enumerate(reader.pages):
            t = page.extract_text()
            text_pages.append((i + 1, t or ""))
        return text_pages
    except ImportError:
        print("ERROR: Install pdfplumber or PyPDF2: pip install pdfplumber PyPDF2")
        sys.exit(1)


def extract_text_from_docx(filepath):
    """Extract text from a DOCX file."""
    try:
        from docx import Document
        doc = Document(filepath)
        full_text = "\n".join([p.text for p in doc.paragraphs if p.text.strip()])
        return [(1, full_text)]
    except ImportError:
        print("ERROR: Install python-docx: pip install python-docx")
        sys.exit(1)


def chunk_text(text, source_name, page_num, chunk_size=CHUNK_SIZE, overlap=CHUNK_OVERLAP):
    """Split text into overlapping chunks by word count."""
    words = text.split()
    chunks = []
    start = 0
    idx = 0
    while start < len(words):
        end = start + chunk_size
        chunk_words = words[start:end]
        chunk_text = " ".join(chunk_words)
        if len(chunk_text.strip()) > 50:  # skip very short chunks
            chunks.append({
                "text": chunk_text,
                "source": source_name,
                "page": page_num,
                "chunk_index": idx,
            })
            idx += 1
        start += chunk_size - overlap
    return chunks


def chunk_sog_text(text, size=1500, overlap=200):
    """Keep every extracted character, including the final short tail."""
    chunks = []
    start = 0
    while start < len(text):
        end = min(start + size, len(text))
        chunks.append(text[start:end])
        if end == len(text):
            break
        start = end - overlap
    return chunks


def build_sog_corpus(manifest_path):
    """Prepare only the canonical PDFs of the frozen Library SOG edition."""
    manifest_path = Path(manifest_path).resolve()
    raw = manifest_path.read_bytes()
    if hashlib.sha256(raw).hexdigest() != SOG_MANIFEST_SHA256:
        raise ValueError("SOG manifest differs from the frozen Library source.")
    manifest = json.loads(raw)
    manuals = [manual for manual in manifest["manuals"] if manual["slug"] == "sogs"]
    if len(manuals) != 1 or manuals[0]["version_label"] != SOG_EDITION:
        raise ValueError("Expected the frozen SOG manual.")
    documents = [document for section in manuals[0]["sections"] for document in section["documents"]]
    if len(documents) != 32:
        raise ValueError("Expected 32 canonical SOG assets.")
    records, assets = [], []
    primary_ids, alias_ids = set(), set()
    page_count = 0
    for document in documents:
        metadata = document["metadata"]
        if metadata.get("admin_only") or metadata["review_edition"] != SOG_EDITION:
            raise ValueError("Hidden review controls or another edition cannot enter support chat.")
        source_path = (manifest_path.parent / document["source_path"]).resolve()
        if not source_path.is_relative_to(manifest_path.parent):
            raise ValueError("Canonical source escapes the staged directory.")
        source_sha = hashlib.sha256(source_path.read_bytes()).hexdigest()
        if source_sha != metadata["canonical_sha256"] or source_sha != metadata["source_sha256"]:
            raise ValueError("Canonical SOG source hash differs.")
        pages = extract_text_from_pdf(source_path)
        if [number for number, _ in pages] != list(range(1, document["page_count"] + 1)):
            raise ValueError("Canonical physical-page coverage differs.")
        page_count += len(pages)
        asset_id = metadata["asset_id"]
        entries = metadata["primary_entries"]
        aliases = metadata["subject_aliases"]
        primary_ids.update(entry["id"] for entry in entries)
        alias_ids.update(alias["source_record_id"] for alias in aliases)
        source = Path(metadata["artifact_pdf"]).name
        assets.append({"asset_id": asset_id, "source": source, "sha256": source_sha, "pages": len(pages)})
        for page, text in pages:
            page_entries = [entry for entry in entries if page in entry["semantic_pages"]]
            page_ids = {entry["id"] for entry in page_entries}
            page_aliases = [alias for alias in aliases if page_ids.intersection(alias["current_ids"])]
            labels = [f'{entry["id"]} — {entry["title"]}' for entry in page_entries]
            labels.extend(f'Historical subject {alias["legacy_id"]}: {alias["source_title"]} ({alias["source_record_id"]}); current {", ".join(alias["current_ids"])}' for alias in page_aliases)
            heading = f'{document["title"]}\n{asset_id}; physical page {page}; {SOG_EDITION}\n' + "\n".join(labels)
            for chunk_index, chunk in enumerate(chunk_sog_text(heading + "\n\n" + text)):
                identity = f'{SOG_MANIFEST_SHA256}:{asset_id}:{page}:{chunk_index}'
                records.append({
                    "id": hashlib.sha256(identity.encode()).hexdigest(),
                    "namespace": SOG_NAMESPACE,
                    "metadata": {
                        "text": chunk, "source": source, "page": page, "chunk_index": chunk_index,
                        "asset_id": asset_id, "source_sha256": source_sha,
                        "manifest_sha256": SOG_MANIFEST_SHA256,
                        "primary_ids": ", ".join(entry["id"] for entry in page_entries),
                        "url": f'https://files.mbfdhub.com/current-sog/{asset_id}?page={page}',
                    },
                })
    if (page_count, len(primary_ids), len(alias_ids)) != (887, 347, 130):
        raise ValueError("Frozen SOG page, identity or subject-alias counts differ.")
    return records, {"manifest_sha256": SOG_MANIFEST_SHA256, "namespace": SOG_NAMESPACE,
                     "assets": assets, "pages": page_count, "primary_ids": len(primary_ids),
                     "subject_aliases": len(alias_ids), "chunks": len(records)}


def get_embeddings(texts):
    """Call Cloudflare Workers AI to generate embeddings."""
    payload = {"text": texts}
    status, data = cloudflare_post(CF_AI_URL, payload)
    if status != 200:
        print(f"Embedding API error {status}")
        return None
    if not data.get("success"):
        print("Embedding API failed")
        return None
    return data["result"]["data"]


def upsert_vectors(vectors):
    """Insert vectors into Cloudflare Vectorize using NDJSON."""
    ndjson_lines = []
    for v in vectors:
        ndjson_lines.append(json.dumps({
            "id": v["id"],
            "values": v["values"],
            "metadata": v["metadata"],
            **({"namespace": v["namespace"]} if "namespace" in v else {}),
        }))
    ndjson_body = "\n".join(ndjson_lines)

    status, result = cloudflare_post(f"{CF_VECTORIZE_URL}/upsert", ndjson_body, ndjson=True)
    if status != 200:
        print(f"Vectorize upsert error {status}")
        return False
    if not result.get("success"):
        print("Vectorize upsert failed")
        return False
    print(f"  Upserted {len(vectors)} vectors successfully.")
    return True


def process_file(filepath):
    """Process a single file: extract text, chunk, embed, and upsert."""
    filename = os.path.basename(filepath)
    if is_retired_sog_source(filename):
        raise ValueError("SOG sources must use the frozen Library manifest, not the reference ingester.")
    ext = os.path.splitext(filepath)[1].lower()

    print(f"\n📄 Processing: {filename}")

    if ext == ".pdf":
        pages = extract_text_from_pdf(filepath)
    elif ext in (".docx", ".doc"):
        pages = extract_text_from_docx(filepath)
    else:
        print(f"  Unsupported file type: {ext}")
        return

    # Chunk all pages
    all_chunks = []
    for page_num, text in pages:
        chunks = chunk_text(text, filename, page_num)
        all_chunks.extend(chunks)

    print(f"  Extracted {len(pages)} pages, {len(all_chunks)} chunks")

    if not all_chunks:
        print("  No chunks to process.")
        return

    # Process in batches of 20 (API limit for embedding)
    batch_size = 20
    total_upserted = 0

    for i in range(0, len(all_chunks), batch_size):
        batch = all_chunks[i:i + batch_size]
        texts = [c["text"] for c in batch]

        print(f"  Embedding batch {i // batch_size + 1}/{(len(all_chunks) + batch_size - 1) // batch_size}...")
        embeddings = get_embeddings(texts)

        if embeddings is None:
            print("  Failed to get embeddings, skipping batch.")
            continue

        vectors = []
        for j, (chunk, embedding) in enumerate(zip(batch, embeddings)):
            vec_id = str(uuid.uuid5(uuid.NAMESPACE_DNS, f"{chunk['source']}:{chunk['page']}:{chunk['chunk_index']}"))
            vectors.append({
                "id": vec_id,
                "namespace": REFERENCE_NAMESPACE,
                "values": embedding,
                "metadata": {
                    "text": chunk["text"][:1000],  # Vectorize metadata limit
                    "source": chunk["source"],
                    "page": chunk["page"],
                    "chunk_index": chunk["chunk_index"],
                },
            })

        if upsert_vectors(vectors):
            total_upserted += len(vectors)

        # Small delay to respect rate limits
        time.sleep(0.5)

    print(f"  ✅ Total vectors upserted for {filename}: {total_upserted}")


def write_receipt(output, receipt, exclusive=False):
    """Sync complete bytes before atomically publishing each receipt state."""
    temporary = output.with_name(output.name + ".pending")
    with temporary.open("x", encoding="utf-8", newline="\n") as handle:
        json.dump(receipt, handle, indent=2)
        handle.flush()
        os.fsync(handle.fileno())
    if exclusive:
        os.link(temporary, output)
        temporary.unlink()
    else:
        os.replace(temporary, output)


def ingest_sogs(manifest_path, output, apply=False, prepared_corpus=None):
    records, receipt = build_sog_corpus(manifest_path)
    corpus_bytes = None
    if apply:
        if not prepared_corpus:
            raise ValueError("An exact --prepared-corpus is required for SOG upserts.")
        corpus_bytes = Path(prepared_corpus).read_bytes()
        if hashlib.sha256(corpus_bytes).hexdigest() != SOG_CORPUS_SHA256:
            raise ValueError("Prepared SOG corpus bytes differ from the reviewed snapshot.")
        prepared_records = [json.loads(line) for line in corpus_bytes.decode("utf-8").splitlines()]
        if len(records) != SOG_CHUNKS or len(prepared_records) != SOG_CHUNKS:
            raise ValueError("Expected exactly 2160 reviewed SOG chunks.")
        if records != prepared_records:
            raise ValueError("Canonical extraction differs from the reviewed prepared corpus.")
        receipt["prepared_corpus"] = str(Path(prepared_corpus).resolve())
        receipt["prepared_corpus_sha256"] = SOG_CORPUS_SHA256
    receipt["status"] = "PREPARED"
    output = Path(output)
    # Exclusive receipts make an incomplete run visible; no silent overwrite.
    write_receipt(output, receipt, exclusive=True)
    corpus_path = output.with_suffix(".ndjson")
    if corpus_bytes is not None:
        with corpus_path.open("xb") as handle:
            handle.write(corpus_bytes)
    else:
        with corpus_path.open("x", encoding="utf-8") as handle:
            for record in records:
                handle.write(json.dumps(record, ensure_ascii=False) + "\n")
    if not apply:
        print(json.dumps({key: value for key, value in receipt.items() if key != "assets"}))
        return
    if not CF_API_TOKEN:
        raise ValueError("CLOUDFLARE_API_TOKEN is required for an explicit SOG upsert.")
    receipt["status"] = "UPSERTING"
    receipt["mutations"] = []
    for offset in range(0, len(records), 10):
        batch = records[offset:offset + 10]
        embeddings = get_embeddings([record["metadata"]["text"] for record in batch])
        if embeddings is None or len(embeddings) != len(batch) or any(len(vector) != 1024 for vector in embeddings):
            raise ValueError("Embedding batch failed or differs from index dimensions.")
        vectors = [dict(record, values=embedding) for record, embedding in zip(batch, embeddings)]
        body = "\n".join(json.dumps(vector) for vector in vectors)
        receipt["pending_ids"] = [record["id"] for record in batch]
        write_receipt(output, receipt)
        status, result = cloudflare_post(f'{CF_VECTORIZE_URL}/upsert', body, ndjson=True)
        mutation = result.get("result", {}).get("mutationId")
        if status != 200 or not result.get("success") or not mutation:
            raise ValueError(f'SOG upsert failed with HTTP {status}.')
        receipt["mutations"].append({"mutation_id": mutation, "ids": [record["id"] for record in batch]})
        del receipt["pending_ids"]
        write_receipt(output, receipt)
        print(f'Accepted SOG batch {offset // 10 + 1}; vectors {min(offset + 10, len(records))}/{len(records)}')
    # Accepted mutation IDs do not establish async query availability.
    receipt["status"] = "UPSERT_ACCEPTED_READBACK_REQUIRED"
    write_receipt(output, receipt)


def main():
    if "--sog-manifest" in sys.argv:
        parser = argparse.ArgumentParser(description="Prepare or explicitly upsert the frozen Library SOG corpus.")
        parser.add_argument("--sog-manifest", required=True)
        parser.add_argument("--receipt", required=True)
        parser.add_argument("--apply", action="store_true")
        parser.add_argument("--prepared-corpus")
        args = parser.parse_args()
        ingest_sogs(args.sog_manifest, args.receipt, args.apply, args.prepared_corpus)
        return
    if len(sys.argv) < 2:
        print("Usage: python ingest.py <file1.pdf> [file2.docx] ...")
        sys.exit(1)

    files = sys.argv[1:]
    for f in files:
        if not os.path.exists(f):
            print(f"File not found: {f}")
            continue
        process_file(f)

    print("\n🎉 Ingestion complete!")


if __name__ == "__main__":
    main()
