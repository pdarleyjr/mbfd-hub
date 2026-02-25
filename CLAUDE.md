# CLAUDE.md — MBFD Hub AI Context

> **Mission Status: ✅ NocoBase Infrastructure Deployment — COMPLETE** (2026-02-24)  
> Phases 1–5, 7, 8, 9 fully complete. Phase 6 CE-blocked (documented below).

## Project Identity
Miami Beach Fire Department (MBFD) internal operations hub. Laravel 11 + Filament 3 backend, React SPA daily checkout, NocoBase admin overlay, Baserow data platform — all containerized on a single VPS.

## VPS
- **Host:** `145.223.73.170`
- **SSH:** `ssh -i "C:\Users\Peter Darley\.ssh\id_ed25519_hpb_docker" root@145.223.73.170`
- **Compose file:** `/root/mbfd-hub/docker-compose.yml`
- **Env file:** `/root/mbfd-hub/.env`

## Docker Services
| Service | Internal Host | External Port | Notes |
|---------|--------------|--------------|-------|
| `app` | `app` | 8000 | Laravel + Octane |
| `nocobase` | `nocobase` | 13000 | NocoBase CE (community) |
| `baserow` | `baserow` | 80 (internal) | Baserow self-hosted |
| `db` | `db` | 5432 (internal) | PostgreSQL |
| `redis` | `redis` | 6379 (internal) | Redis |
| `reverb` | `reverb` | 8080 | Laravel Reverb WebSockets |

## Domains
- `support.darleyplex.com` → Laravel app (port 8000) / React SPA / `sw.js` (Cloudflare proxied, direct A record to VPS 145.223.73.170)
- `www.mbfdhub.com` → NocoBase (port 13000) via Cloudflare Tunnel (tunnel ID: 89429799-7028-4df2-870d-f2fb858a49d7)
- `mbfdhub.com` → NocoBase (port 13000) via same Cloudflare Tunnel
- `nocobase.mbfdhub.com` → NocoBase (port 13000) via Cloudflare Tunnel [NEW - added 2026-02-25]
- `baserow.mbfdhub.com` → Baserow (port 8082) via Cloudflare Tunnel [NEW - added 2026-02-25]

## Credentials (non-production; rotate before go-live)
- NocoBase admin: `admin@nocobase.com` / `admin123`
- Baserow token: `5c25f5700fedb0f3b46f77b3c9ef41cf` (in `.env` as `BASEROW_TOKEN`)
- GitHub: `pdarleyjr@gmail.com` / token in `.env`
- Sentry DSN: in `config/sentry.php`

## Phase Status
| Phase | Status | Notes |
|-------|--------|-------|
| 1–5 | ✅ Complete | Apparatus, todos, capital projects, inventory, shop works |
| 6 | ⚠️ Blocked | `http-api` datasource requires NocoBase Pro. Workaround: use `plugin-workflow-request` to call `http://baserow:80/api/` with `Authorization: Token {{BASEROW_TOKEN}}` |
| 7 | ✅ Complete | User provisioning via `provision_nocobase_users.py` |
| 8 | ✅ Complete | UI layouts injected: member_portal (uid: `15ex2ujxqm4`), admin_dashboard (uid: `afo40tnn0c4`) |
| 9 | ✅ Complete | This file + `.ai/context/nocobase_deployment_plan.md` |

---

## Phase 6 — NocoBase CE Limitation & Workaround

### Problem
`@nocobase/plugin-data-source-http-api` is **not included** in `nocobase/nocobase:latest` (Community Edition).  
Attempting `POST /api/dataSources` with `type: "http-api"` returns a 400 error — the plugin is unregistered.  
Only `type: "main"` (the built-in PostgreSQL datasource) is available in CE.

### CE Workaround — plugin-workflow-request
The `plugin-workflow-request` IS available in CE. Use it to call Baserow's REST API from within NocoBase Workflows:

- **Baserow internal URL:** `http://baserow:80/api/`
- **Auth header:** `Authorization: Token 5c25f5700fedb0f3b46f77b3c9ef41cf`
- **Token env var:** `BASEROW_TOKEN` in `/root/mbfd-hub/.env`

**How to configure in NocoBase UI:**
1. Go to `Settings → Workflow`
2. Create a new Workflow (trigger: Manual or Schedule)
3. Add a "HTTP Request" node
4. Set URL to `http://baserow:80/api/database/rows/table/<TABLE_ID>/?user_field_names=true`
5. Set header: `Authorization: Token {{$env.BASEROW_TOKEN}}`

### Pro Upgrade Path
When a NocoBase Pro license is obtained:
1. Edit `/root/mbfd-hub/docker-compose.yml`: change `nocobase/nocobase:latest` → `nocobase/nocobase:pro`
2. Run `docker compose pull nocobase && docker compose up -d nocobase`
3. Re-execute phase 6 registration (script template in `.ai/context/nocobase_deployment_plan.md`)
4. Baserow datasource: `type: "http-api"`, `baseURL: "http://baserow:80/api"`, `Authorization: Token <BASEROW_TOKEN>`
5. Register collections: `apparatuses`, `todos`, `apparatus_defects`, `capital_projects`, `station_inventory_submissions`

---

## Key Files
- `scripts/nocobase/ui_layouts/member_portal.json` — member portal UI schema
- `scripts/nocobase/ui_layouts/admin_dashboard.json` — admin dashboard UI schema
- `provision_nocobase_users.py` — user provisioning script
- `.github/workflows/deploy.yml` — CI/CD deploy pipeline (smoke tests target `support.darleyplex.com`)
- `docs/BASEROW_INTEGRATION.md` — Baserow integration notes

## CI/CD Notes
- Smoke tests in `deploy.yml` target `https://support.darleyplex.com` — do NOT change this domain
- `www.mbfdhub.com` and the new mbfdhub.com tunnels are NocoBase only; they do NOT serve the SPA or `sw.js`
