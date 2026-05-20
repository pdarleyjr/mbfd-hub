# ScreenTinker × MBFD Hub — Integration Design

**Date:** 2026-05-20
**Status:** ✅ Phase 1 + Phase 2 both deployed and verified end-to-end
**Author:** Claude Opus 4.7 (autonomous build session)

---

## 1. What this is

ScreenTinker (open-source digital signage management — `github.com/screentinker/screentinker`) deployed at `https://media.mbfdhub.com`, gated by Cloudflare Access, with planned auth-mirror integration so MBFD admin dashboard credentials log into ScreenTinker.

## 2. Why this shape

The user wanted:
- Self-hosted on "the MBFD Hub Linux server" (Ubuntu desktop on Tailscale, not the Hostinger TF Portal VPS)
- Reachable at `media.mbfdhub.com`
- Logins reused from the MBFD admin dashboard (so any current/future MBFD admin "just works")
- Recommended install path from the upstream repo

The upstream repo recommends bare-metal Node + systemd + nginx. We deviated to **Docker compose** because:
1. The deployment user (`peter`) does not have a known sudo password — bare-metal install requires `useradd`, `systemctl`, and `/etc/systemd/system/*.service` edits, all of which require root.
2. The Ubuntu box already runs all other apps (`mbfd-hub-laravel`, `mbfd-snipeit`, `mbfd-baserow`, `mbfd-uptime-kuma`, etc.) as Docker containers behind `cloudflared`. Following the same pattern is operationally consistent.
3. Docker compose is functionally equivalent to the recommended setup — same Node runtime, same SQLite, same loopback bind, same reverse-proxy pattern.

For auth, ScreenTinker only supports JWT + bcrypt against its own local SQLite users table out of the box (no SAML SP, no OIDC, no LDAP). The user-mirror approach (Phase 2) was chosen over a SAML fork because the existing MBFD `laravel-samlidp` IdP is broken and would need a separate fix first.

## 3. Architecture (deployed)

```
                                    Cloudflare Edge
                                            │
        User browser ── HTTPS ──▶  ┌────────┴────────┐
                                   │  Cloudflare      │
                                   │  Access gate     │  Policy: email_domain miamibeachfl.gov
                                   │  (Email OTP IdP) │       OR email pdarleyjr@gmail.com
                                   └────────┬────────┘
                                            │
                              CNAME media.mbfdhub.com (proxied)
                                            │
                                   89429799-…cfargotunnel.com
                                            │
                                   ┌────────┴───────────┐
                                   │  cloudflared       │  Tunnel: mbfdhub-nocobase
                                   │  (dashboard-managed│  Ingress rule added via CF API:
                                   │   tunnel)          │    media.mbfdhub.com → http://127.0.0.1:8095
                                   └────────┬───────────┘
                                            │  loopback
                                            ▼
                                  127.0.0.1:8095   (host)
                                            │
                                            ▼  Docker port mapping
                                  container :3001
                              ┌──────────────────────┐
                              │  mbfd-screentinker   │  Docker Compose
                              │  Node 22 + SQLite +  │  /home/peter/screentinker/
                              │  ffmpeg + libvips    │  Volumes:
                              │                      │   - screentinker_db (named) → /app/server/db
                              │                      │   - ./data/uploads        → /app/server/uploads
                              │                      │   - ./data/certs          → /app/server/certs
                              └──────────────────────┘
```

## 4. Inventory of everything created

### 4.1 Ubuntu box `peter@100.82.185.48` (Tailscale)

| Path | Purpose |
|---|---|
| `/home/peter/screentinker/Dockerfile` | Custom image: Node 22 Alpine + ffmpeg + libvips + tini; multi-stage build with native module compile |
| `/home/peter/screentinker/docker-compose.yml` | Stack definition, env, volumes, healthcheck |
| `/home/peter/screentinker/.env` | `JWT_SECRET=<64-hex-byte>` (mode 600, gitignored) |
| `/home/peter/screentinker/.gitignore` | Excludes `.env` and `data/` |
| `/home/peter/screentinker/screentinker/` | Upstream repo clone (Phase 2 will switch this to the fork) |
| `/home/peter/screentinker/data/uploads/` | Bind-mounted upload storage |
| `/home/peter/screentinker/data/certs/` | Bind-mounted JWT secret cache (also where SSL certs would go if we ever terminate TLS at the container) |
| Docker named volume `screentinker_screentinker_db` | SQLite DB + db code (`database.js`, `schema.sql`); pre-populated from image on first mount |

### 4.2 Cloudflare zone `mbfdhub.com` (`9c7b03d154bbf6abe7b2edd4b5c33fe5`)

| Resource | Value |
|---|---|
| DNS record | CNAME `media.mbfdhub.com` → `89429799-7028-4df2-870d-f2fb858a49d7.cfargotunnel.com` (proxied) |
| Tunnel | `mbfdhub-nocobase` UUID `89429799-7028-4df2-870d-f2fb858a49d7` (config_src=cloudflare) |
| Tunnel ingress rule | `media.mbfdhub.com → http://127.0.0.1:8095` with `originRequest.httpHostHeader=media.mbfdhub.com`, inserted before the catch-all (position 9 of 11) |
| Cloudflare Access app | `MBFD Media (ScreenTinker)` id `f229f58b-5de4-498d-8ef8-63433bdb55e6`, type self_hosted, 24h session, allowed_idps=[OTP only] |
| Access policy | `Allow MBFD admins …` id `dc1bc249-e978-4846-8000-edfd82596236`, decision allow, include = `[email_domain miamibeachfl.gov, email pdarleyjr@gmail.com]` |
| IdP used | One-time PIN, id `84d30127-e959-4fea-b5b2-d60458a6d90f` (existed already) |

### 4.3 ScreenTinker app state

| | |
|---|---|
| Version booted | v1.2.0 |
| Auto-migrations run | Phase 1 multi-tenancy, Phase 4 group_id, zone_id backfill — all completed cleanly on first boot |
| First user | `peterdarley@miamibeachfl.gov` — role `platform_admin`, plan `enterprise` |
| Registration | DISABLED (after first user bootstrap) — `/api/auth/register` returns 403 |
| Env vars active | `NODE_ENV=production`, `PORT=3001`, `SELF_HOSTED=true`, `DISABLE_HOMEPAGE=true`, `DISABLE_REGISTRATION=true`, `APP_URL=https://media.mbfdhub.com`, `JWT_SECRET=<from .env>` |

## 5. Runbook

### Restart
```bash
ssh mbfd-ubuntu
cd /home/peter/screentinker
docker compose restart screentinker
```

### Update screentinker code
```bash
ssh mbfd-ubuntu
cd /home/peter/screentinker/screentinker  # or the fork once Phase 2 lands
git pull
cd ..
docker compose build screentinker
docker compose up -d
```
Schema migrations run automatically on boot — the server takes a timestamped snapshot before any migration and exits if migration fails (snapshot path logged).

### Backup SQLite DB
```bash
ssh mbfd-ubuntu
# Use SQLite's online backup so we don't race writes
docker exec mbfd-screentinker sh -c "sqlite3 /app/server/db/remote_display.db \".backup /app/server/db/backup-\$(date +%F).db\""
docker cp mbfd-screentinker:/app/server/db/backup-$(date +%F).db /opt/mbfd/backups/screentinker/
```
Add this to `/opt/mbfd/backup-daily.sh` (sibling to existing MBFD backups).

### View logs
```bash
docker logs -f mbfd-screentinker
```

### Toggle registration (e.g. to onboard a new admin without using the API sync)
Edit `/home/peter/screentinker/docker-compose.yml`, set `DISABLE_REGISTRATION: "false"`, `docker compose up -d`, register the user, flip it back to `"true"`, `docker compose up -d` again.

### Cloudflare Access — adjust who can pass the gate
Edit policy `dc1bc249-e978-4846-8000-edfd82596236` on app `f229f58b-5de4-498d-8ef8-63433bdb55e6` via the Zero Trust dashboard, or PATCH via the API at `/accounts/{acct}/access/apps/{app_id}/policies/{policy_id}`.

## 6. Known design tensions

### 6.1 Password length mismatch (Phase 2 driver)
- Upstream ScreenTinker enforces `password.length >= 8` at `/auth/register` and `/auth/change-password`.
- MBFD admin passwords (e.g., `Penco3`) are 6 chars.
- **Resolution chosen:** Fork upstream → `pdarleyjr/screentinker`, lower minimum to 4 chars, rebase periodically. See § 7.

### 6.2 Two-step login UX
The current flow is: Cloudflare Access OTP → ScreenTinker login. The user enters their MBFD email twice (once for OTP, once for the app login). Single-sign-on click-through would require either trusting Cloudflare Access JWT in ScreenTinker (custom middleware) or a SAML SP in the fork — both are larger work. Phase 2 stays at "same credentials, two prompts."

### 6.3 Token exposure
Cloudflare tokens (`cfut_…`, `cfat_…`) and the GitHub PAT (`ghp_…`) were pasted twice in the chat that produced this work. They are logged in Anthropic conversation transcripts. Phase 2 step "Rotate exposed tokens" handles the CF side automatically; the GitHub PAT must be rotated via the GitHub UI (no API for classic PAT rotation).

## 7. Phase 2 — Auth-mirror DEPLOYED

### 7.0 As-built summary

The plan in § 7.1–7.6 below was executed in this same session. The mirror is live:
ScreenTinker password mirror flows automatically whenever an MBFD admin user
(roles `super_admin`, `admin`, `logistics_admin`, `training_admin`) is created
or has their password changed in the MBFD admin dashboard. Verified end-to-end
in three flows:

1. **New admin: `User::create([..., password => 'Penco3'])` → `assignRole('admin')`** —
   mirror fires via the `RoleAttached` event listener; screentinker login with
   `Penco3` (6 chars!) succeeds.
2. **Existing admin: `$u->password = 'Up!d4t3d'; $u->save()`** — mirror fires via
   the `User::saved` model observer; screentinker login with the new password
   succeeds; old password is rejected (no orphaned hash).
3. **Token rotation: in-place** — `MBFD_SYNC_TOKEN` on both sides rotated mid-flight
   without dropping any sync calls.

Three bugs were caught and fixed during deployment:

- **Eloquent `__set` intercept**: storing plaintext on a model dynamic property
  was routed through `setAttribute()`. Fixed by switching to a `WeakMap<Model, string>`
  sidecar on the cast class (commit `d9bbdc04`).
- **Save-vs-role timing**: in Filament's `User::create → assignRole` flow,
  `User::saved` fires before the role is attached, so the role check failed.
  Fixed by also listening to Spatie's `RoleAttached` event (commit `82243f54`).
- **Spatie `events_enabled=false` default**: `RoleAttached` was never dispatched.
  Fixed by overriding `config('permission.events_enabled') => true` in
  `AppServiceProvider::register()` (commit `8d7a42bf`).

### 7.1 Goal

### 7.1 Goal
Any user with `is_admin = true` (or equivalent MBFD admin role) in the MBFD Hub Laravel `users` table can log into ScreenTinker with the same email + plaintext password they use on the MBFD admin dashboard.

### 7.2 Approach
**Fork-and-mirror:**
1. **Fork** `screentinker/screentinker` → `pdarleyjr/screentinker` on GitHub via API
2. **Patch the fork** with two changes:
   - Lower `password.length` minimum from 8 → 4 in [server/routes/auth.js](https://github.com/pdarleyjr/screentinker/blob/main/server/routes/auth.js) (and wherever else this is validated — `change-password`, OAuth provisioning if it validates)
   - Add `POST /api/admin/users/sync` endpoint (auth: bearer `MBFD_SYNC_TOKEN` from env) that:
     - Accepts `{email, password, name?, role?}`
     - Bypasses the `canRegister()` gate
     - Upserts the user (UPDATE if email exists, INSERT otherwise) with fresh bcrypt hash
     - Default role: `platform_admin` (MBFD admins are top-tier in screentinker too)
     - Returns `{user_id, action: "created"|"updated"}`
3. **Switch the Dockerfile** to clone from the fork instead of upstream
4. **Add a Laravel listener** to MBFD Hub:
   - Hook the `User` model's `saving` event to capture plaintext password (before Laravel hashes it)
   - Hook the `User` model's `saved` event to call the sync endpoint, *only if* the user has an admin role
   - Failures are logged but non-blocking (sync is best-effort, not a transactional dependency)
5. **Env vars added** to MBFD Hub `.env`:
   - `SCREENTINKER_SYNC_URL=https://media.mbfdhub.com/api/admin/users/sync`  *(or http://127.0.0.1:8095/api/admin/users/sync if we want it to bypass the public route entirely — see § 7.3)*
   - `SCREENTINKER_SYNC_TOKEN=<64-byte random hex>`
6. **Same token** is mounted into the screentinker container via the existing `.env` file (`MBFD_SYNC_TOKEN=<same value>`)

### 7.3 Network path for the sync call
Two options:
- **Public**: Laravel container → out through cloudflared → CF Access (would need a service-token bypass) → tunnel → screentinker
- **Loopback**: Laravel container → `host.docker.internal:8095` → screentinker

Loopback is simpler (no Access bypass needed) but requires `extra_hosts: ["host.docker.internal:host-gateway"]` in the Laravel compose (if not already present). Going with loopback.

### 7.4 Filtering — what counts as "admin"
The MBFD admin dashboard (Filament) uses a permission system on the `users` table. The listener will filter by the existing admin role check (specific implementation determined during § 9 of the implementation plan when we inspect `app/Models/User.php` and the role system).

### 7.5 Failure modes & handling
| Failure | Behavior |
|---|---|
| Sync endpoint is down (screentinker container restarting) | Log to `storage/logs/laravel.log`; user save still succeeds in MBFD |
| Sync token mismatch | Same as above — log, don't block |
| Network unreachable | Same — log, don't block |
| Password is empty (model save without password change) | Skip sync entirely |
| User is being deleted | Optionally call DELETE on the sync endpoint (out of scope for v1; we'll just leave the screentinker account active) |

### 7.6 Tests
- Unit test the listener with a fake HTTP client (Laravel's HTTP::fake)
- Integration test: create a user in MBFD via factory, assert sync was attempted
- E2E manual test: change a password in MBFD's `/admin/users/{id}` Filament form, confirm log in to media.mbfdhub.com works with the new password

### 7.7 As-built file map (Phase 2)

**ScreenTinker fork** (`pdarleyjr/screentinker` branch `mbfd-integration`):
- `server/routes/auth.js` — password min lowered 8→4 in 4 enforcement points
- `server/routes/admin-sync.js` — new bearer-auth'd `POST /api/admin/users/sync` upsert
- `server/server.js` — mount line `app.use('/api/admin', require('./routes/admin-sync'))`

**MBFD Hub Laravel** (`pdarleyjr/mbfd-hub` branch `main`):
- `app/Casts/HashedAndCaptured.php` — WeakMap sidecar cast (replaces `'hashed'`)
- `app/Observers/SyncToScreentinker.php` — `saved()` + `onRoleAttached()` hooks
- `app/Models/User.php` — `'password' => HashedAndCaptured::class`
- `app/Providers/AppServiceProvider.php` — observer + event listener registration + `events_enabled` override
- `config/services.php` — `screentinker.sync_url` + `screentinker.sync_token`
- `tests/Unit/Casts/HashedAndCapturedTest.php` — 5 tests
- `tests/Feature/Observers/SyncToScreentinkerTest.php` — 6 tests (incl. RoleAttached flow)

**Network topology** added in Phase 2:
- `mbfd-screentinker` container joined the existing `mbfd-hub_mbfd-net` Docker network (in addition to its own `screentinker_default`)
- Reachable from `mbfd-hub-laravel` at `http://mbfd-screentinker:3001` (no public route, no Cloudflare round-trip)
- `SCREENTINKER_SYNC_URL=http://mbfd-screentinker:3001/api/admin/users/sync` in MBFD's `.env`
- `MBFD_SYNC_TOKEN` (matching value) in both `.env` files, mode 600

## 8. Token rotation status

Five secrets touched this session. Status:

| Token | Status | Note |
|---|---|---|
| `MBFD_SYNC_TOKEN` (the one I generated mid-session) | ✅ ROTATED | Old `aa5350bf…8063d4d` invalidated; new value lives only in `.env` files on box (mode 600), never in chat |
| `JWT_SECRET` (ScreenTinker JWT signing key) | OK | Generated fresh in this session; lives only in `/home/peter/screentinker/.env` |
| Cloudflare User Token `cfut_…fdbaf63f` | ⚠ NEEDS USER ACTION | Was pasted twice in chat. Verified still ACTIVE via API. **Rotate via dashboard:** https://dash.cloudflare.com/profile/api-tokens → find the user token → Roll → save new value to password manager |
| Cloudflare R2 token `cfat_…0033cb` (tfportalapp) | ⚠ NEEDS USER ACTION | Pasted in chat. Rotate via Cloudflare dashboard → R2 → Manage API Tokens |
| Cloudflare R2 token `cfat_…3337d6` (mbfd-hub-laravel) | ⚠ NEEDS USER ACTION | Same path as above |
| GitHub PAT `ghp_…WgB3lkTx5` | ⚠ NEEDS USER ACTION | Pasted in chat. **No API path for rotating classic PATs.** Rotate via https://github.com/settings/tokens → Revoke + create new with same scopes |

## 9. Reversal / rollback

If we ever want to undo this entire deployment:

1. Delete the Cloudflare Access app: `DELETE /accounts/{acct}/access/apps/f229f58b-5de4-498d-8ef8-63433bdb55e6`
2. Remove the tunnel ingress rule: PUT the tunnel config back without the `media.mbfdhub.com` entry
3. Delete the DNS record: `DELETE /zones/{zone_id}/dns_records/<media_record_id>`
4. Stop and remove the container: `cd /home/peter/screentinker && docker compose down -v`
5. Remove the install dir: `rm -rf /home/peter/screentinker`
6. Revert the Laravel listener changes in the MBFD repo

Reversal does not need sudo. Everything that needed root has been avoided.

## 10. Related docs

- Upstream: [screentinker/screentinker README](https://github.com/screentinker/screentinker)
- MBFD Hub Ubuntu migration plan: [docs/archive/MBFD_HUB_UBUNTU_MIGRATION_HARDENING_MASTER_PLAN.md](../../archive/MBFD_HUB_UBUNTU_MIGRATION_HARDENING_MASTER_PLAN.md)
- Snipe-IT SSO precedent (different pattern, native SAML): [docs/SNIPEIT_SSO_SETUP.md](../../SNIPEIT_SSO_SETUP.md)
- Backup/restore for other MBFD apps: [docs/BACKUP-RESTORE.md](../../BACKUP-RESTORE.md)

## 11. Follow-ups (not blocking)

1. **Run PHPUnit in a dev environment** — production install is `--no-dev` so phpunit binary isn't shipped. Tests pass `php -l` syntax check; running them requires `composer install` in a non-prod environment. The integration is verified end-to-end via the tinker E2E test which is more decisive anyway.
2. **Add screentinker SQLite backup to `/opt/mbfd/backup-daily.sh`** — currently only MBFD-Hub-proper is in the daily backup. The screentinker DB lives in the Docker named volume `screentinker_screentinker_db`; back it up via `docker exec mbfd-screentinker sqlite3 /app/server/db/remote_display.db ".backup ..."`.
3. **Audit existing MBFD admins on next password change** — the mirror fires on password set/change. To pre-seed all existing admins into screentinker right now without waiting for them to change passwords, we'd need a one-shot script that prompts each admin (or asks the user to enter a temp password the script then forwards through the mirror). Out of scope for this session; can be added as a Filament page if desired.
4. **Add screentinker to monitoring** — `mbfd-uptime-kuma` already lives on this box. Add a monitor for `https://media.mbfdhub.com/app` so degradation is visible.
