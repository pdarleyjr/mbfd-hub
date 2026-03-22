#!/usr/bin/env python3
import requests

BASE = "http://localhost:13000/api"

r = requests.post(f"{BASE}/auth:signIn", json={"email": "peterdarley@miamibeachfl.gov", "password": "Penco3"})
token = r.json()["data"]["token"]
H = {"Authorization": f"Bearer {token}"}

role = requests.get(f"{BASE}/roles:check", headers=H).json()
print("Role check:", role.get("data", {}).get("name", "?"))
print("allowConfigure:", role.get("data", {}).get("allowConfigure"))
snippets = role.get("data", {}).get("snippets", [])
print("snippets count:", len(snippets))
print("has *:", "*" in snippets)
print("snippet samples:", snippets[:5])
print()

# Check what the root role grants
import json
root_r = requests.get(f"{BASE}/roles/root", headers=H)
print("root role:", root_r.status_code, root_r.text[:200])
