#!/usr/bin/env python3
"""Remove conflicting driver_manual vectors from Vectorize index.
These pages contain outdated SOG/policy info that conflicts with the current edited SOG."""

import json
import uuid
import requests

CF_ACCOUNT_ID = "265122b6d6f29457b0ca950c55f3ac6e"
CF_API_TOKEN = "U6XGuhQXd5JwIrkuIprFiXA_OvyCqd6ZQeLs_cmZ"
VECTORIZE_INDEX = "mbfd-rag-index"
CF_VECTORIZE_URL = f"https://api.cloudflare.com/client/v4/accounts/{CF_ACCOUNT_ID}/vectorize/v2/indexes/{VECTORIZE_INDEX}"

# Pages from driver_manual.pdf that contain outdated SOG/policy content
# Page 9: Equipment Maintenance POLICY section - outdated procedures for reporting damaged equipment
# Page 33: Introduction section that references "standard operating guidelines" with outdated policy descriptions  
CONFLICTING_PAGES = [9, 33]

SOURCE = "driver_manual.pdf"

# The ingestion script used uuid5 with namespace DNS: f"{source}:{page}:{chunk_index}"
# We need to find all chunk indices for these pages and generate matching IDs

# From the ingestion: 400 words per chunk, 80 overlap. 
# Each page typically produces 1-3 chunks depending on content length.
# We'll try chunk indices 0-5 to cover all possibilities.

ids_to_delete = []
for page in CONFLICTING_PAGES:
    for chunk_idx in range(6):  # Up to 6 chunks per page
        vec_id = str(uuid.uuid5(uuid.NAMESPACE_DNS, f"{SOURCE}:{page}:{chunk_idx}"))
        ids_to_delete.append(vec_id)

print(f"Attempting to delete {len(ids_to_delete)} potential vector IDs for pages {CONFLICTING_PAGES}")

# Delete vectors by ID
resp = requests.post(
    f"{CF_VECTORIZE_URL}/delete-by-ids",
    headers={
        "Authorization": f"Bearer {CF_API_TOKEN}",
        "Content-Type": "application/json",
    },
    json={"ids": ids_to_delete},
)

print(f"Status: {resp.status_code}")
print(f"Response: {resp.text}")

if resp.status_code == 200:
    result = resp.json()
    if result.get("success"):
        print(f"\n✅ Successfully removed vectors for conflicting pages {CONFLICTING_PAGES}")
    else:
        print(f"\n❌ Delete failed: {result}")
else:
    print(f"\n❌ HTTP error: {resp.status_code}")
