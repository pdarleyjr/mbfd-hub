#!/usr/bin/env python3
"""
Set correct passwords for all NocoBase users and create remaining users.
"""
import requests, time

BASE = "http://localhost:13000/api"

# Sign in as admin
r = requests.post(f"{BASE}/auth:signIn", json={"email": "admin@nocobase.com", "password": "admin123"})
token = r.json()["data"]["token"]
H = {"Authorization": f"Bearer {token}", "Content-Type": "application/json"}
print(f"Signed in OK")

# Update existing users (IDs 2-5) with correct passwords
existing = [
    (2, "MiguelAnchia@miamibeachfl.gov",   "Penco1"),
    (3, "RichardQuintela@miamibeachfl.gov", "Penco2"),
    (4, "PeterDarley@miamibeachfl.gov",     "Penco3"),
    (5, "GreciaTrabanino@miamibeachfl.gov", "MBFDSupport!"),
]

print("\n=== Updating existing users ===")
for uid, email, pw in existing:
    r = requests.post(f"{BASE}/users:update?filterByTk={uid}", headers=H,
                      json={"password": pw})
    if r.status_code == 200:
        print(f"  ✓ Updated {email} (id={uid})")
    else:
        print(f"  ✗ Failed {email}: {r.text[:100]}")

# Create new users
new_users = [
    {"email": "geralddeyoung@miamibeachfl.gov",  "username": "GeraldDeYoung",  "nickname": "Gerald DeYoung",  "password": "MBFDGerry1"},
    {"email": "danielgato@miamibeachfl.gov",     "username": "DanielGato",     "nickname": "Daniel Gato",     "password": "Gato1234!"},
    {"email": "victorwhite@miamibeachfl.gov",    "username": "VictorWhite",    "nickname": "Victor White",    "password": "Vic1234!"},
    {"email": "ClaudioNavas@miamibeachfl.gov",   "username": "ClaudioNavas",   "nickname": "Claudio Navas",   "password": "Flea1234!"},
    {"email": "michaelsica@miamibeachfl.gov",    "username": "MichaelSica",    "nickname": "Michael Sica",    "password": "Sica1234!"},
]

print("\n=== Creating new users ===")
for u in new_users:
    r = requests.post(f"{BASE}/users:create", headers=H, json={
        "email": u["email"],
        "username": u["username"],
        "nickname": u["nickname"],
        "password": u["password"],
    })
    resp = r.json() if r.text else {}
    new_id = resp.get("data", {}).get("id") or resp.get("data", [{}])[0].get("id") if isinstance(resp.get("data"), list) else resp.get("data", {}).get("id")
    if r.status_code in [200, 201]:
        print(f"  ✓ Created {u['email']}")
        # Assign admin role
        if new_id:
            time.sleep(0.3)
            rr = requests.post(f"{BASE}/users/{new_id}/roles:add", headers=H, json=[{"name":"admin"}])
            print(f"    Role assigned: {rr.status_code}")
    else:
        print(f"  ✗ Failed {u['email']}: {r.status_code} — {r.text[:120]}")

# Assign admin role to all existing users
print("\n=== Assigning admin role to all users ===")
for uid, email, pw in existing:
    r = requests.post(f"{BASE}/users/{uid}/roles:add", headers=H, json=[{"name":"admin"}])
    print(f"  Role for {email}: {r.status_code}")

# List all users
print("\n=== Final user list ===")
r = requests.get(f"{BASE}/users:list?pageSize=50", headers=H)
for u in r.json().get("data", []):
    roles = [rr.get("name","") for rr in u.get("roles",[])]
    print(f"  [{u['id']}] {u['email']} — {', '.join(roles) or 'no role'}")
