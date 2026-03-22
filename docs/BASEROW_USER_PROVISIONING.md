# Baserow User Provisioning

## Provisioned Users (2026-02-11)

### Support Services Workspace (ID: 131)
| Name | Email | Role |
|------|-------|------|
| Miguel Anchia | MiguelAnchia@miamibeachfl.gov | Admin |
| Richard Quintela | RichardQuintela@miamibeachfl.gov | Admin |
| Peter Darley | PeterDarley@miamibeachfl.gov | Admin |
| Grecia Trabanino | GreciaTrabanino@miamibeachfl.gov | Admin |
| Gerald DeYoung | geralddeyoung@miamibeachfl.gov | Member |

### Training Workspace (ID: 132)
| Name | Email | Role |
|------|-------|------|
| Daniel Gato | danielgato@miamibeachfl.gov | Admin |
| Victor White | victorwhite@miamibeachfl.gov | Admin |
| Claudio Navas | ClaudioNavas@miamibeachfl.gov | Admin |
| Michael Sica | michaelsica@miamibeachfl.gov | Admin |

## How Provisioning Was Done

1. **User creation:** Via Baserow REST API (`POST /api/user/`) with temporary passwords meeting the 8-character minimum
2. **Short password override:** For users needing shorter passwords, direct database update was used:
   ```bash
   docker exec baserow-baserow-1 /baserow/env/bin/python -c "
   from django.contrib.auth.hashers import make_password
   print(make_password('shortpw'))
   "
   # Then UPDATE django_auth_user SET password='<hash>' WHERE email='...';
   ```
3. **Workspace invitations:** Via API (`POST /api/workspaces/{id}/invitations/`) with appropriate permissions (ADMIN or MEMBER)
4. **Invitation acceptance:** Via API (`POST /api/workspaces/invitations/{id}/accept/`) using each user's JWT token

## Current Settings

- **Signups disabled:** Public self-registration is turned off in Baserow settings
- **Workspace creation disabled:** Non-admin users cannot create new workspaces

## How to Add New Users in the Future

1. Create the user via API:
   ```bash
   curl -X POST http://127.0.0.1:8082/api/user/ \
     -H 'Host: baserow.support.darleyplex.com' \
     -H 'Content-Type: application/json' \
     -d '{"name":"Full Name","email":"user@domain.com","password":"TempPass1!","authenticate":true}'
   ```
2. Get an admin JWT token:
   ```bash
   curl -X POST http://127.0.0.1:8082/api/user/token-auth/ \
     -H 'Host: baserow.support.darleyplex.com' \
     -H 'Content-Type: application/json' \
     -d '{"email":"admin@domain.com","password":"adminpass"}'
   ```
3. Invite user to workspace:
   ```bash
   curl -X POST http://127.0.0.1:8082/api/workspaces/{WORKSPACE_ID}/invitations/ \
     -H 'Host: baserow.support.darleyplex.com' \
     -H "Authorization: JWT <admin_token>" \
     -H 'Content-Type: application/json' \
     -d '{"email":"user@domain.com","permissions":"MEMBER","message":"Welcome"}'
   ```
4. Accept invitation using the new user's token:
   ```bash
   curl -X POST http://127.0.0.1:8082/api/workspaces/invitations/{INVITATION_ID}/accept/ \
     -H 'Host: baserow.support.darleyplex.com' \
     -H "Authorization: JWT <user_token>"
   ```
