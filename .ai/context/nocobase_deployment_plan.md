# NocoBase Deployment Plan — Architectural Decision Record

## Status: Active
**Last updated:** 2026-02-24

## Overview
NocoBase is deployed alongside the main Laravel app as a supplementary admin overlay and data management interface for MBFD Hub. It is NOT the primary application — it augments Filament admin with a more flexible no-code UI for power users.

## Infrastructure
- **Image:** `nocobase/nocobase:latest` (Community Edition)
- **Container:** `nocobase` on Docker network `mbfd-hub_sail`
- **Port:** `13000` (host) → `80` (container)
- **Reverse proxy:** Cloudflare → `www.mbfdhub.com` → VPS:13000
- **Database:** Shares `db` (PostgreSQL) service; uses `nocobase` database
- **Env file:** `/root/mbfd-hub/.env` (shared with Laravel)

## Phase Execution Log

### Phase 6 — Baserow HTTP API Datasource
**Status:** ⚠️ Blocked (Pro feature)

**Finding:** The `@nocobase/plugin-data-source-http-api` plugin is not included in Community Edition. Only `type: "main"` datasource exists after installation.

**Available in CE:** `plugin-workflow-request` — can make outbound HTTP calls from Workflow triggers. This can be used to read/write Baserow data via `http://baserow:80/api/` with `Authorization: Token 5c25f5700fedb0f3b46f77b3c9ef41cf`.

**Pro upgrade path:**
```yaml
# docker-compose.yml change required:
image: nocobase/nocobase:pro  # was: nocobase/nocobase:latest
```
Then re-run phase6 script to register:
- `POST /api/dataSources` → `{ type: "http-api", key: "baserow", options: { baseURL: "http://baserow:80/api", headers: { Authorization: "Token <BASEROW_TOKEN>" } } }`
- Collections: `apparatuses`, `todos`, `apparatus_defects`, `capital_projects`, `station_inventory_submissions`

### Phase 8 — UI Layout Injection
**Status:** ✅ Complete

**Schemas registered:**
| Schema | x-uid | Desktop Route ID | Title |
|--------|-------|-----------------|-------|
| member_portal | `15ex2ujxqm4` | 350055550353408 | Member Portal |
| admin_dashboard | `afo40tnn0c4` | 350055550353409 | Admin Dashboard |

**Source files (retained):**
- `scripts/nocobase/ui_layouts/member_portal.json`
- `scripts/nocobase/ui_layouts/admin_dashboard.json`

**API calls used:**
```
POST /api/uiSchemas   → register schema, returns x-uid
POST /api/desktopRoutes  → create nav item linking to schema
```

### Phase 9 — Documentation
**Status:** ✅ Complete (this file)

## Collections in Main Datasource
The following Laravel/PostgreSQL tables are mirrored as NocoBase collections via the `main` datasource:
- `apparatuses`
- `apparatus_defects`
- `apparatus_inspections`
- `todos`
- `capital_projects`
- `station_inventory_submissions`
- `users`
- `under_25k_projects`
- `shop_works`
- `uniforms`

## Security Notes
- NocoBase admin password must be rotated before public go-live
- Baserow token should be rotated after Pro datasource is configured
- NocoBase is behind Cloudflare — direct port 13000 is not exposed publicly (firewall rule required)

## Known Issues / Limitations
1. CE does not support external datasources — Baserow integration requires Pro
2. NocoBase UI schemas are stored in the `nocobase` PostgreSQL DB — include in backup rotation
3. `plugin-workflow-request` is available as CE workaround for Baserow data reads
