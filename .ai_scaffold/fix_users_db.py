#!/usr/bin/env python3
"""
Fix NocoBase user passwords via nocobase CLI (nocobase pm:install style)
using bcrypt hash update directly in the database.
"""
import subprocess, json

# NocoBase uses bcrypt. Run in the container.
users = [
    # Existing users needing password fix
    ("MiguelAnchia@miamibeachfl.gov",   "Penco1"),
    ("RichardQuintela@miamibeachfl.gov","Penco2"),
    ("PeterDarley@miamibeachfl.gov",    "Penco3"),
    ("GreciaTrabanino@miamibeachfl.gov","MBFDSupport!"),
    # New users to add
    ("geralddeyoung@miamibeachfl.gov",  "MBFDGerry1"),
    ("danielgato@miamibeachfl.gov",     "Gato1234!"),
    ("victorwhite@miamibeachfl.gov",    "Vic1234!"),
    ("ClaudioNavas@miamibeachfl.gov",   "Flea1234!"),
    ("michaelsica@miamibeachfl.gov",    "Sica1234!"),
]

# Use bcrypt to hash passwords
try:
    import bcrypt
    def hash_pw(pw):
        return bcrypt.hashpw(pw.encode(), bcrypt.gensalt(rounds=10)).decode()
except ImportError:
    # Use node to hash since NocoBase uses bcrypt via node
    def hash_pw(pw):
        result = subprocess.run(
            ['node', '-e', f"const bcrypt=require('bcrypt');bcrypt.hash('{pw}',10).then(h=>process.stdout.write(h))"],
            cwd='/app/nocobase',
            capture_output=True, text=True
        )
        return result.stdout.strip()

print("Hashing passwords and updating database...")

for email, password in users:
    pw_hash = hash_pw(password)
    # Check if user exists
    check = subprocess.run(
        ['docker', 'exec', 'mbfd-hub-pgsql-1', 'psql', '-U', 'mbfd_user', '-d', 'nocobase_storage',
         '-t', '-c', f"SELECT id FROM users WHERE email='{email}';"],
        capture_output=True, text=True
    )
    uid = check.stdout.strip()

    if uid:
        # Update existing user's password
        subprocess.run(
            ['docker', 'exec', 'mbfd-hub-pgsql-1', 'psql', '-U', 'mbfd_user', '-d', 'nocobase_storage',
             '-c', f"UPDATE users SET password='{pw_hash}' WHERE email='{email}';"],
            capture_output=True
        )
        print(f"  ✓ Updated password for {email}")
    else:
        # Insert new user — need to use NocoBase API for proper setup
        print(f"  + Need to create: {email}")

print("\nDone.")
