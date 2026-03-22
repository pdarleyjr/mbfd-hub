#!/usr/bin/env python3
"""
Provision all NocoBase users with exact passwords and admin roles.
"""
import json, requests

BASE = "http://localhost:13000/api"

# Sign in
r = requests.post(f"{BASE}/auth:signIn", json={"email": "admin@nocobase.com", "password": "admin123"})
token = r.json()["data"]["token"]
H = {"Authorization": f"Bearer {token}", "Content-Type": "application/json"}
print(f"Signed in, token: {token[:40]}...")

# All users to provision
users = [
    # Logistics Admins
    {"email": "MiguelAnchia@miamibeachfl.gov",   "username": "MiguelAnchia",   "nickname": "Miguel Anchia",   "password": "Penco1",       "roles": ["admin"]},
    {"email": "RichardQuintela@miamibeachfl.gov", "username": "RichardQuintela","nickname": "Richard Quintela","password": "Penco2",       "roles": ["admin"]},
    {"email": "PeterDarley@miamibeachfl.gov",     "username": "PeterDarley",    "nickname": "Peter Darley",    "password": "Penco3",       "roles": ["admin"]},
    {"email": "GreciaTrabanino@miamibeachfl.gov", "username": "GreciaTrabanino","nickname": "Grecia Trabanino","password": "MBFDSupport!", "roles": ["admin"]},
    {"email": "geralddeyoung@miamibeachfl.gov",   "username": "GeraldDeYoung",  "nickname": "Gerald DeYoung",  "password": "MBFDGerry1",   "roles": ["admin"]},
    # Training Admins
    {"email": "danielgato@miamibeachfl.gov",      "username": "DanielGato",     "nickname": "Daniel Gato",     "password": "Gato1234!",    "roles": ["admin"]},
    {"email": "victorwhite@miamibeachfl.gov",     "username": "VictorWhite",    "nickname": "Victor White",    "password": "Vic1234!",     "roles": ["admin"]},
    {"email": "ClaudioNavas@miamibeachfl.gov",    "username": "ClaudioNavas",   "nickname": "Claudio Navas",   "password": "Flea1234!",    "roles": ["admin"]},
    {"email": "michaelsica@miamibeachfl.gov",     "username": "MichaelSica",    "nickname": "Michael Sica",    "password": "Sica1234!",    "roles": ["admin"]},
]

for u in users:
    # Try to create user
    payload = {
        "email": u["email"],
        "username": u["username"],
        "nickname": u["nickname"],
        "password": u["password"],
        "roles": [{"name": r} for r in u["roles"]]
    }
    r = requests.post(f"{BASE}/users:create", headers=H, json=payload)
    data = r.json()

    if r.status_code == 200:
        uid = data.get("data", {}).get("id", "?")
        print(f"  ✓ Created user {u['email']} (id={uid})")
    elif "already exists" in str(data) or "duplicate" in str(data).lower():
        # Update existing user's password
        # First find the user
        search = requests.get(f"{BASE}/users:list?filter[email]={u['email']}", headers=H)
        users_list = search.json().get("data", [])
        if users_list:
            uid = users_list[0]["id"]
            upd = requests.post(f"{BASE}/users:update?filterByTk={uid}", headers=H, json={
                "password": u["password"],
                "nickname": u["nickname"]
            })
            print(f"  ↻ Updated existing user {u['email']} (id={uid}) — {upd.status_code}")
        else:
            print(f"  ⚠ Could not find/update {u['email']}")
    else:
        print(f"  ✗ Error for {u['email']}: {r.status_code} — {data.get('errors', data)}")

print("\nDone. All users provisioned.")
print("NocoBase admin default: admin@nocobase.com / admin123")
print("\nUser list:")
all_users = requests.get(f"{BASE}/users:list?pageSize=50", headers=H)
for u in all_users.json().get("data", []):
    roles = [rr.get("name","") for rr in u.get("roles",[])]
    print(f"  {u['email']} — {', '.join(roles) or 'no role'}")
