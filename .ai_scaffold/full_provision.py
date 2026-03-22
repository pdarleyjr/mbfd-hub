#!/usr/bin/env python3
"""
Full provisioning for fresh NocoBase install.
Creates all 9 users with correct emails/passwords and admin+root roles.
Normalizes emails to lowercase in DB after creation.
"""
import requests, time

BASE = "http://localhost:13000/api"

# Sign in as default admin
r = requests.post(f"{BASE}/auth:signIn", json={"email": "admin@nocobase.com", "password": "admin123"})
token = r.json()["data"]["token"]
H = {"Authorization": f"Bearer {token}", "Content-Type": "application/json"}
print(f"Signed in as admin@nocobase.com")

# All users to provision
ALL_USERS = [
    {"email": "miguelanchia@miamibeachfl.gov",   "username": "MiguelAnchia",   "nickname": "Miguel Anchia",   "password": "Penco1"},
    {"email": "richardquintela@miamibeachfl.gov", "username": "RichardQuintela","nickname": "Richard Quintela","password": "Penco2"},
    {"email": "peterdarley@miamibeachfl.gov",     "username": "PeterDarley",    "nickname": "Peter Darley",    "password": "Penco3"},
    {"email": "greciatrabanino@miamibeachfl.gov", "username": "GreciaTrabanino","nickname": "Grecia Trabanino","password": "MBFDSupport!"},
    {"email": "geralddeyoung@miamibeachfl.gov",   "username": "GeraldDeYoung",  "nickname": "Gerald DeYoung",  "password": "MBFDGerry1"},
    {"email": "danielgato@miamibeachfl.gov",      "username": "DanielGato",     "nickname": "Daniel Gato",     "password": "Gato1234!"},
    {"email": "victorwhite@miamibeachfl.gov",     "username": "VictorWhite",    "nickname": "Victor White",    "password": "Vic1234!"},
    {"email": "claudionavas@miamibeachfl.gov",    "username": "ClaudioNavas",   "nickname": "Claudio Navas",   "password": "Flea1234!"},
    {"email": "michaelsica@miamibeachfl.gov",     "username": "MichaelSica",    "nickname": "Michael Sica",    "password": "Sica1234!"},
]

print("\n=== Getting list of existing users ===")
existing_users = {}
r = requests.get(f"{BASE}/users:list?pageSize=50", headers=H)
for u in r.json().get("data", []):
    existing_users[u["email"].lower()] = u["id"]
print(f"  Existing users: {list(existing_users.keys())}")

print("\n=== Creating/updating users ===")
user_ids = {}
for u in ALL_USERS:
    email = u["email"].lower()
    if email in existing_users:
        uid = existing_users[email]
        # Update password
        r = requests.post(f"{BASE}/users:update?filterByTk={uid}", headers=H, json={"password": u["password"]})
        print(f"  ↻ Updated password for {email} (id={uid}): {r.status_code}")
        user_ids[email] = uid
    else:
        # Create new user
        r = requests.post(f"{BASE}/users:create", headers=H, json={
            "email": email,
            "username": u["username"],
            "nickname": u["nickname"],
            "password": u["password"],
        })
        resp = r.json()
        data = resp.get("data", {})
        uid = data.get("id") if isinstance(data, dict) else None
        if r.status_code == 200 and uid:
            print(f"  ✓ Created {email} (id={uid})")
            user_ids[email] = uid
        else:
            print(f"  ✗ Failed {email}: {r.status_code} — {resp.get('errors', resp)[:100]}")

# Ensure all emails are lowercase in DB (important!)
time.sleep(0.5)
r_all = requests.get(f"{BASE}/users:list?pageSize=50", headers=H)
for u in r_all.json().get("data", []):
    if u["id"] not in [1] and u["email"] != u["email"].lower():
        # Lowercase via update
        r2 = requests.post(f"{BASE}/users:update?filterByTk={u['id']}", headers=H, json={"email": u["email"].lower()})

print("\n=== Assigning admin + root roles ===")
for email, uid in user_ids.items():
    # Add admin role
    r = requests.post(f"{BASE}/users/{uid}/roles:add", headers=H, json=[{"name": "admin"}])
    print(f"  admin role → {email}: {r.status_code}")
    time.sleep(0.1)
    # Add root role
    r = requests.post(f"{BASE}/users/{uid}/roles:add", headers=H, json=[{"name": "root"}])
    print(f"  root role  → {email}: {r.status_code}")
    time.sleep(0.1)

print("\n=== Final user list ===")
r_final = requests.get(f"{BASE}/users:list?pageSize=50", headers=H)
for u in r_final.json().get("data", []):
    print(f"  [{u['id']}] {u['email']}")

print("\n=== DONE ===")
print("Test login: peterdarley@miamibeachfl.gov / Penco3")
r_test = requests.post(f"{BASE}/auth:signIn", json={"email": "peterdarley@miamibeachfl.gov", "password": "Penco3"})
print(f"Login test: {'OK' if r_test.json().get('data', {}).get('token') else 'FAILED'}")
