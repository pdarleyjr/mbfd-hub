#!/usr/bin/env python3
"""Set defaultRole=root for all provisioned users via NocoBase API"""
import requests, json

BASE = "http://localhost:13000/api"

# Sign in as super admin
r = requests.post(f"{BASE}/auth:signIn", json={"email": "admin@nocobase.com", "password": "admin123"})
token = r.json()["data"]["token"]
H = {"Authorization": f"Bearer {token}", "Content-Type": "application/json"}

# Get all users
users_r = requests.get(f"{BASE}/users:list?pageSize=50", headers=H)
users = users_r.json().get("data", [])

print("Setting defaultRole=root for all non-admin users...")
for u in users:
    if u["id"] == 1:
        continue
    uid = u["id"]
    current_settings = u.get("systemSettings") or {}
    new_settings = {**current_settings, "defaultRole": "root"}
    r2 = requests.post(f"{BASE}/users:update?filterByTk={uid}", headers=H,
                       json={"systemSettings": new_settings})
    print(f"  [{uid}] {u['email']}: {r2.status_code}")

print("\nDone. All users will now log in with root role as default.")
print("Testing login for peterdarley...")
test = requests.post(f"{BASE}/auth:signIn", json={"email": "peterdarley@miamibeachfl.gov", "password": "Penco3"})
test_token = test.json()["data"]["token"]
H2 = {"Authorization": f"Bearer {test_token}"}
role_r = requests.get(f"{BASE}/roles:check", headers=H2)
role_data = role_r.json().get("data", {})
print(f"  Active role: {role_data.get('name')}")
print(f"  allowConfigure: {role_data.get('allowConfigure')}")
snippets = role_data.get("snippets", [])
print(f"  snippets: {snippets[:5]} (has *: {'*' in snippets})")
