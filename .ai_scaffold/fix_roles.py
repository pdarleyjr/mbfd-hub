#!/usr/bin/env python3
"""Fix roles for all provisioned users - assign admin and root roles via DB."""
import subprocess

# Add admin + root roles for all users except the system admin (id=1)
sql = """
INSERT INTO nocobase."rolesUsers" ("userId", "roleName", "createdAt", "updatedAt")
SELECT id, 'admin', NOW(), NOW() FROM nocobase.users WHERE id > 1
ON CONFLICT DO NOTHING;

INSERT INTO nocobase."rolesUsers" ("userId", "roleName", "createdAt", "updatedAt")
SELECT id, 'root', NOW(), NOW() FROM nocobase.users WHERE id > 1
ON CONFLICT DO NOTHING;

SELECT u.id, u.email, ARRAY_AGG(ru."roleName") as roles 
FROM nocobase.users u 
LEFT JOIN nocobase."rolesUsers" ru ON ru."userId"=u.id 
GROUP BY u.id, u.email 
ORDER BY u.id;
"""

result = subprocess.run(
    ['docker', 'exec', 'mbfd-hub-pgsql-1', 'psql', '-U', 'mbfd_user', '-d', 'mbfd_hub', '-c', sql],
    capture_output=True, text=True
)
print(result.stdout)
if result.stderr:
    print("STDERR:", result.stderr[:200])
