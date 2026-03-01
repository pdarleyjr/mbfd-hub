#!/usr/bin/env python3
"""
MBFD RAG Document Ingestion Script
Reads PDF/DOCX files, chunks text, generates embeddings via Cloudflare Workers AI,
and inserts vectors into Cloudflare Vectorize.
"""

import json
import os
import sys
import time
import uuid
import requests

# --- Configuration ---
CF_ACCOUNT_ID = "265122b6d6f29457b0ca950c55f3ac6e"
CF_API_TOKEN = os.environ.get("CLOUDFLARE_API_TOKEN", "U6XGuhQXd5JwIrkuIprFiXA_OvyCqd6ZQeLs_cmZ")
VECTORIZE_INDEX = "mbfd-rag-index"
EMBEDDING_MODEL = "@cf/baai/bge-large-en-v1.5"
CHUNK_SIZE = 400  # words per chunk
CHUNK_OVERLAP = 80  # overlapping words

CF_AI_URL = f"https://api.cloudflare.com/client/v4/accounts/{CF_ACCOUNT_ID}/ai/run/{EMBEDDING_MODEL}"
CF_VECTORIZE_URL = f"https://api.cloudflare.com/client/v4/accounts/{CF_ACCOUNT_ID}/vectorize/v2/indexes/{VECTORIZE_INDEX}"

HEADERS = {
    "Authorization": f"Bearer {CF_API_TOKEN}",
    "Content-Type": "application/json",
}


def extract_text_from_pdf(filepath):
    """Extract text from a PDF file using PyPDF2 or pdfplumber."""
    try:
        import pdfplumber
        text_pages = []
        with pdfplumber.open(filepath) as pdf:
            for i, page in enumerate(pdf.pages):
                t = page.extract_text()
                if t:
                    text_pages.append((i + 1, t))
        return text_pages
    except ImportError:
        pass

    try:
        from PyPDF2 import PdfReader
        reader = PdfReader(filepath)
        text_pages = []
        for i, page in enumerate(reader.pages):
            t = page.extract_text()
            if t:
                text_pages.append((i + 1, t))
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


def get_embeddings(texts):
    """Call Cloudflare Workers AI to generate embeddings."""
    payload = {"text": texts}
    resp = requests.post(CF_AI_URL, headers=HEADERS, json=payload)
    if resp.status_code != 200:
        print(f"Embedding API error {resp.status_code}: {resp.text}")
        return None
    data = resp.json()
    if not data.get("success"):
        print(f"Embedding API failed: {data}")
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
        }))
    ndjson_body = "\n".join(ndjson_lines)

    resp = requests.post(
        f"{CF_VECTORIZE_URL}/upsert",
        headers={
            "Authorization": f"Bearer {CF_API_TOKEN}",
            "Content-Type": "application/x-ndjson",
        },
        data=ndjson_body,
    )
    if resp.status_code != 200:
        print(f"Vectorize upsert error {resp.status_code}: {resp.text}")
        return False
    result = resp.json()
    if not result.get("success"):
        print(f"Vectorize upsert failed: {result}")
        return False
    print(f"  Upserted {len(vectors)} vectors successfully.")
    return True


def process_file(filepath):
    """Process a single file: extract text, chunk, embed, and upsert."""
    filename = os.path.basename(filepath)
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


def main():
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
