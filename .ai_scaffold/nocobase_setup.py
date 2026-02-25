#!/usr/bin/env python3
"""
NocoBase automated setup for MBFD Support Hub.
Phases 4, 5, 8 — runs on the VPS directly against localhost:13000
"""
import json, requests, sys

BASE = "http://localhost:13000/api"

# ── Step 1: Sign in with default credentials ──
print("=== Step 1: Signing in ===")
r = requests.post(f"{BASE}/auth:signIn", json={"email": "admin@nocobase.com", "password": "admin123"})
token = r.json()["data"]["token"]
H = {"Authorization": f"Bearer {token}", "Content-Type": "application/json"}
print(f"  ✓ Signed in, token: {token[:40]}...")

# ── Step 2: Update admin password and email ──
print("=== Step 2: Update admin credentials ===")
r = requests.put(f"{BASE}/users:updateProfile", headers=H, json={
    "email": "pdarleyjr@gmail.com",
    "nickname": "Peter Darley (Admin)",
    "newPassword": "MBFDNocoBase2026!",
    "confirmPassword": "MBFDNocoBase2026!"
})
print(f"  Profile update: {r.status_code}")

# Re-sign in with new credentials
r2 = requests.post(f"{BASE}/auth:signIn", json={"email": "pdarleyjr@gmail.com", "password": "MBFDNocoBase2026!"})
if r2.status_code == 200 and r2.json().get("data", {}).get("token"):
    token = r2.json()["data"]["token"]
    H = {"Authorization": f"Bearer {token}", "Content-Type": "application/json"}
    print(f"  ✓ Re-signed in with new credentials")
else:
    print(f"  ⚠ Using original token")

# ── Step 3: Create PostgreSQL external data source ──
print("=== Step 4: Configure mbfd_hub PostgreSQL data source ===")
r = requests.post(f"{BASE}/dataSources:create", headers=H, json={
    "displayName": "MBFD Hub Database",
    "name": "mbfd_hub",
    "type": "postgres",
    "options": {
        "host": "pgsql",
        "port": 5432,
        "database": "mbfd_hub",
        "username": "mbfd_user",
        "password": "mbfd_secure_pass_2026",
        "schema": "public"
    }
})
print(f"  Data source create: {r.status_code} — {r.json().get('data', {}).get('name', r.json().get('errors', 'unknown'))}")

# ── Step 4: Create Admin role ──
print("=== Step 5a: Create Logistics Admin role ===")
r = requests.post(f"{BASE}/roles:create", headers=H, json={
    "name": "logistics-admin",
    "title": "Logistics Admin",
    "description": "Full administrative access to all logistics data",
    "allowConfigure": True
})
admin_role = r.json().get("data", {})
print(f"  Admin role: {r.status_code} — {admin_role.get('name', r.json().get('errors'))}")

# ── Step 5: Create Member role ──
print("=== Step 5b: Create Station Member role ===")
r = requests.post(f"{BASE}/roles:create", headers=H, json={
    "name": "station-member",
    "title": "Station Member",
    "description": "Station personnel — read access and form submissions",
    "allowConfigure": False
})
member_role = r.json().get("data", {})
print(f"  Member role: {r.status_code} — {member_role.get('name', r.json().get('errors'))}")

# ── Step 6: Provision admin users ──
print("=== Step 5a: Provision logistics admin users ===")
admin_users = [
    {"email": "MiguelAnchia@miamibeachfl.gov", "username": "ManuelAnchia", "nickname": "Miguel Anchia"},
    {"email": "RichardQuintela@miamibeachfl.gov", "username": "RichardQuintela", "nickname": "Richard Quintela"},
    {"email": "PeterDarley@miamibeachfl.gov", "username": "PeterDarley", "nickname": "Peter Darley"},
    {"email": "GreciaTrabanino@miamibeachfl.gov", "username": "GreciaTrabanino", "nickname": "Grecia Trabanino"},
]
for u in admin_users:
    r = requests.post(f"{BASE}/users:create", headers=H, json={
        "email": u["email"],
        "username": u["username"],
        "nickname": u["nickname"],
        "password": "MBFDHub2026!",
        "roles": [{"name": "logistics-admin"}]
    })
    print(f"  User {u['email']}: {r.status_code}")

# ── Step 7: Scaffold Member Portal page ──
print("=== Step 8a: Scaffold Member Portal page ===")
member_portal_schema = {
    "title": "Member Portal — Inventory Form",
    "name": "member-portal",
    "path": "member-portal",
    "icon": "FormOutlined",
    "type": "page",
    "schema": {
        "type": "void",
        "title": "Member Portal",
        "x-decorator": "Page",
        "x-component": "Grid",
        "properties": {
            "row1": {
                "type": "void",
                "x-component": "Grid.Row",
                "properties": {
                    "col1": {
                        "type": "void",
                        "x-component": "Grid.Col",
                        "properties": {
                            "card1": {
                                "type": "void",
                                "title": "Station Inventory Submission",
                                "x-decorator": "BlockItem",
                                "x-component": "CardItem",
                                "x-component-props": {
                                    "title": "Submit Inventory Count"
                                }
                            }
                        }
                    }
                }
            }
        }
    }
}
r = requests.post(f"{BASE}/pages:create", headers=H, json=member_portal_schema)
print(f"  Member portal page: {r.status_code} — {r.json().get('data', {}).get('title', r.json().get('errors'))}")

# ── Step 8: Scaffold Logistics Admin Dashboard page ──
print("=== Step 8b: Scaffold Logistics Admin Dashboard page ===")
admin_dashboard_schema = {
    "title": "Logistics Admin Dashboard",
    "name": "logistics-admin-dashboard",
    "path": "logistics-admin-dashboard",
    "icon": "DashboardOutlined",
    "type": "page",
    "schema": {
        "type": "void",
        "title": "Logistics Admin Dashboard",
        "x-decorator": "Page",
        "x-component": "Grid",
        "properties": {
            "row1": {
                "type": "void",
                "x-component": "Grid.Row",
                "properties": {
                    "col1": {
                        "type": "void",
                        "x-component": "Grid.Col",
                        "properties": {
                            "inventoryPanel": {
                                "type": "void",
                                "title": "Station Inventory Overview",
                                "x-decorator": "BlockItem",
                                "x-component": "CardItem"
                            }
                        }
                    },
                    "col2": {
                        "type": "void",
                        "x-component": "Grid.Col",
                        "properties": {
                            "capitalProjects": {
                                "type": "void",
                                "title": "Capital Projects",
                                "x-decorator": "BlockItem",
                                "x-component": "CardItem"
                            }
                        }
                    }
                }
            },
            "row2": {
                "type": "void",
                "x-component": "Grid.Row",
                "properties": {
                    "col3": {
                        "type": "void",
                        "x-component": "Grid.Col",
                        "properties": {
                            "apparatus": {
                                "type": "void",
                                "title": "Fleet / Apparatus Status",
                                "x-decorator": "BlockItem",
                                "x-component": "CardItem"
                            }
                        }
                    }
                }
            }
        }
    }
}
r = requests.post(f"{BASE}/pages:create", headers=H, json=admin_dashboard_schema)
print(f"  Admin dashboard page: {r.status_code} — {r.json().get('data', {}).get('title', r.json().get('errors'))}")

# ── Save schemas to disk ──
print("=== Saving schemas to disk ===")
import os
os.makedirs("/root/mbfd-hub/.ai/skills/nocobase_schemas", exist_ok=True)
with open("/root/mbfd-hub/.ai/skills/nocobase_schemas/member_portal.json", "w") as f:
    json.dump(member_portal_schema, f, indent=2)
with open("/root/mbfd-hub/.ai/skills/nocobase_schemas/logistics_admin_dashboard.json", "w") as f:
    json.dump(admin_dashboard_schema, f, indent=2)
print("  ✓ Schemas saved to .ai/skills/nocobase_schemas/")

print("\n=== SETUP COMPLETE ===")
print("NocoBase admin: pdarleyjr@gmail.com / MBFDNocoBase2026!")
print("Logistics admin users: provisioned with MBFDHub2026!")
print("Access: https://www.mbfdhub.com")
