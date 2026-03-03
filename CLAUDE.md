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
- `www.mbfdhub.com` → Laravel/React app (port 8080) via Cloudflare Tunnel (tunnel ID: 89429799-7028-4df2-870d-f2fb858a49d7)
- `mbfdhub.com` → same as www.mbfdhub.com (redirect)
- `nocobase.mbfdhub.com` → NocoBase (port 13000) via Cloudflare Tunnel
- `baserow.mbfdhub.com` → Baserow (port 8082) via Cloudflare Tunnel

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
- `.github/workflows/deploy.yml` — CI/CD deploy pipeline (smoke tests target `www.mbfdhub.com`)
- `docs/BASEROW_INTEGRATION.md` — Baserow integration notes

## CI/CD Notes
- Smoke tests in `deploy.yml` target `https://www.mbfdhub.com`
- All darleyplex.com references have been migrated to mbfdhub.com

---

## Pump Simulator (2026-03-03)

### Summary
A React SPA pump training simulator implemented as a new Vite entry point within the Laravel app.

### Route
- `GET /pump-simulator` — public, no auth required
- Served by `Route::view('/pump-simulator', 'pump-simulator')->name('pump-simulator')` in `routes/web.php`
- Blade template: `resources/views/pump-simulator.blade.php`

### Frontend Architecture
- Entry: `resources/js/pump-simulator/main.tsx`
- State: Zustand store at `resources/js/pump-simulator/stores/usePumpStore.tsx`
- Components: `PumpPanel.tsx`, `Gauge.tsx`, `ValveControl.tsx`, `ShiftModeToggle.tsx`
- CSS: `resources/js/pump-simulator/styles/index.css` (bundled into the main.tsx JS chunk — do NOT add CSS separately to `@vite()` directive)
- Vite config: `pump-simulator` input added in `vite.config.js`

### Dependencies Added
- `react`, `react-dom`, `@types/react`, `@types/react-dom` — React runtime
- `zustand` — state management
- `framer-motion` — animations for gauge needles

### Deployment Notes
- The `database/migrations/2026_02_17_000005_update_apparatuses_status_constraint.php` migration requires `'Available'` status to be included (VPS had rows with status='Available')
- After merging `feature/pump-simulator` to `main` on VPS, run `npm install` to add React packages before `npm run build`
- The CSS for the pump simulator is automatically bundled into the JS chunk by Vite — only reference `main.tsx` in the blade `@vite()` directive

---

## Export Feature — CSV / XLSX (2026-03-03)

### Package
`pxlrbt/filament-excel` ^2.5 installed via `composer require` in the `laravel.test` Docker container.

### Coverage
All tables across all three Filament panels now have:
- **Header Export button** — exports the entire table (respecting active filters) as `.xlsx` or `.csv`
- **Bulk Export action** — exports only the checked/selected rows as `.xlsx` or `.csv`

**Panels and resources covered:**
- **Logistics panel** (15 resources + 12 relation managers)
- **Training panel** (3 resources)
- **Workgroup panel** (9 resources + 4 relation managers)

Note: `SingleGasMeterResource` already had a native Filament ExportAction before this feature; it was NOT modified to avoid duplication.

### Implementation Pattern
Each resource file has:
```php
use pxlrbt\FilamentExcel\Actions\Tables\ExportAction;
use pxlrbt\FilamentExcel\Actions\Tables\ExportBulkAction;
use pxlrbt\FilamentExcel\Exports\ExcelExport;
```
- `->headerActions([ExportAction::make('export')->exports([xlsx, csv])])` in `table()`
- `ExportBulkAction::make('export_selected')` inside `BulkActionGroup::make([...])` in `table()`

Shared trait: `app/Filament/Concerns/HasExportActions.php` (available but resources are patched inline for Filament compatibility).

### ⚠️ High-Volume Monitoring
If `InspectionResource` or `StationResource/RelationManagers/InventorySubmissionsRelationManager` exceeds ~5,000 rows, consider migrating those specific tables to Filament's native queue-backed exporter (`Filament\Actions\Exports\ExcelExport`) to prevent PHP memory exhaustion during exports.
