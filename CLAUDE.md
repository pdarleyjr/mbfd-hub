# CLAUDE.md â MBFD Hub AI Context

> **Mission Status: â Production** (2026-03-10)  
> NocoBase has been **decommissioned** (2026-03-08) â container stopped, image removed, volume deleted. All Nocobase scripts removed from repo.  
> â **Chatify real-time chat FIXED** (2026-03-09 evening) â Split-brain config resolved; backend uses internal Reverb (127.0.0.1:8080), frontend uses public wss:// via Cloudflare.  
> â **Daily Vehicle Inspections revived** (2026-03-09 late evening) â MBFD Forms now includes a dedicated Vehicle Inspections card, historical inspections render in a branded admin results viewer, checklist payloads are normalized for React, and the daily SPA now ships with updated service-worker cache busting plus custom `artisan serve` router handling for `/daily/*` routes.  
> â **Vehicle Inspection Checklists FIXED + ICS-212 Features** (2026-03-10) â Checklist pathing bug resolved (storage/checklists â storage/app/checklists), ladder type detection fixed to use designation, digital officer signatures added via react-signature-canvas, automated HOLD logic sets apparatus "Out of Service" on critical defects.
> â **Impeccable Design System Installed** (2026-03-10) â All 17 Impeccable skills installed to `.kilocode/skills/`, 7 domain reference files in `frontend-design/reference/`. UI/UX Modernization Plan generated.
> â **UI/UX Modernization Phase 0-3 DEPLOYED** (2026-03-10) â Removed all 37+ `@apply` from theme.css (iOS crash fix), fixed broken selectors, replaced pure grays with warm-tinted neutrals, new typography system (Plus Jakarta Sans + Source Sans 3), flattened nested stat cards, skeleton loading, stagger animations, search filter on vehicle inspections, reduced motion support.
> â **UI/UX Modernization Phase 4-8 DEPLOYED + CI/CD FIX** (2026-03-10 late evening) â Phase 4: button press feedback, sliding tab underline, focus-visible rings, toast animations. Phase 5: landing page redesign (cards primary, chatbot secondary, accent bars, removed System Overview). Phase 6: mobile-first polish (pointer:coarse, safe areas, scroll-snap tabs). Phase 7: tabular numbers, enhanced empty states, fluid typography. Phase 8: skip navigation, ARIA labels, font preload. CI/CD: daily-checkout builds in Docker, Reverb/queue worker post-deploy verification, explicit compose.yaml, view:cache error handling, filament:assets step, www.mbfdhub.com smoke tests.

## Project Identity
Miami Beach Fire Department (MBFD) internal operations hub. Laravel 11 + Filament 3 backend, React SPA daily checkout, Baserow data platform — all containerized on a single VPS.

## VPS
- **Host:** `145.223.73.170`
- **SSH:** `ssh -i "C:\Users\Peter Darley\.ssh\id_ed25519_hpb_docker" root@145.223.73.170`
- **Compose file:** `/root/mbfd-hub/docker-compose.yml`
- **Env file:** `/root/mbfd-hub/.env`

## Docker Services
| Service | Internal Host | External Port | Notes |
|---------|--------------|--------------|-------|
| `laravel.test` | `laravel.test` | 80 (app), 8080 (Reverb) | Laravel app + Reverb WebSockets (same container, supervisord manages both) |
| `pgsql` | `pgsql` | 5432 (internal) | PostgreSQL |
| `baserow` | `baserow` | 8082 (internal, 127.0.0.1) | Baserow self-hosted |

> ⚠️ **IMPORTANT**: There is NO separate `reverb` container. Reverb runs as a supervisord-managed process INSIDE the `laravel.test` container. Container name: `mbfd-hub-laravel.test-1`. Queue worker also runs in same container.

## Domains
- `www.mbfdhub.com` → Laravel/React app (port 8080) via Cloudflare Tunnel (tunnel ID: 89429799-7028-4df2-870d-f2fb858a49d7)
- `mbfdhub.com` → same as www.mbfdhub.com (redirect)
- `baserow.mbfdhub.com` → Baserow (port 8082) via Cloudflare Tunnel

## Credentials (non-production; rotate before go-live)
- Baserow token: `5c25f5700fedb0f3b46f77b3c9ef41cf` (in `.env` as `BASEROW_TOKEN`)
- GitHub: `pdarleyjr@gmail.com` / token in `.env`
- Sentry DSN: in `config/sentry.php`

---

## Allowed MCP Servers

> **STRICT RULE (2026-03-10):** The ONLY MCP servers allowed for MBFD Hub are:

| Server | Purpose |
|--------|---------|
| **GITHUB** | Repo operations, PRs, issues, code search |
| **MEMORY** | Persistent knowledge graph |
| **SEQUENTIAL THINKING** | Multi-step reasoning |
| **GIT-MCP** | Documentation fetching from GitHub repos |
| **CONTEXT7** | Upstream library/framework documentation |

**All other MCP servers (including `notebooklm-mcp@latest`, `local-rag`, etc.) are DEPRECATED and MUST NOT be used.** Any cached references to deprecated servers must be ignored.

---

## Impeccable Design System — Repo Conventions

> **STRICT RULE (2026-03-10):** All UI/UX tasks MUST use the `frontend-design` skill and follow Impeccable design principles.

### Mandatory Workflow for UI Changes
1. Before implementing any React component or Blade view, review the relevant Impeccable reference files in `.kilocode/skills/frontend-design/reference/`.
2. Use `/critique` to evaluate existing designs before making changes.
3. Use `/polish` before finalizing any UI component for deployment.
4. Use `/audit` for comprehensive accessibility and quality checks.

### Anti-Patterns — NEVER DO THESE
- ❌ **NO generic Arial/Inter/system-ui font stacks** — Choose distinctive, readable font pairings
- ❌ **NO purple gradients on white backgrounds** — The canonical "AI slop" aesthetic
- ❌ **NO pure black (#000) or pure gray (#808080)** — Use tinted neutrals (warm or cool)
- ❌ **NO cards nested inside cards** — Flatten hierarchy; use spacing and dividers
- ❌ **NO bouncy/elastic spring animations** — Use purposeful easing (cubic-bezier)
- ❌ **NO `@apply` in CSS files** — Causes iOS black-screen crashes (see AI_AGENT_ERRORS.md)
- ❌ **NO deprecated `x-filament::card.heading` components** — Use current Filament v3 equivalents
- ❌ **NO uniform 16px padding everywhere** — Use a deliberate spatial rhythm (4/8/12/16/24/32/48)
- ❌ **NO identical border-radius on every element** — Vary radius by component role
- ❌ **NO low-contrast text** — Minimum WCAG AA (4.5:1 for body, 3:1 for large text)

### Impeccable Skills Available
The following 17 steering commands are installed in `.kilocode/skills/`:
`/adapt`, `/animate`, `/audit`, `/bolder`, `/clarify`, `/colorize`, `/critique`, `/delight`, `/distill`, `/extract`, `/frontend-design`, `/harden`, `/normalize`, `/onboard`, `/optimize`, `/polish`, `/quieter`, `/teach-impeccable`

---

## Google Sheets Apparatus Sync (2026-03-03)

### Overview
One-way automatic sync from the MBFD Hub Fire Apparatus admin page to the `Equipment Maintenance` tab in Google Sheets.

### Target Spreadsheet
- **Spreadsheet ID:** `1u9MYILAkfEaMfNZnBujvB1J0J33Ha8TybWCd_mVMJC4`
- **Tab:** `Equipment Maintenance` (sheetId: `1714038258`)
- **Column mapping:** A=Designation, B=Vehicle#, C=Status, D=Location, E=Comments, F=Reported

### Architecture
- `App\Services\GoogleSheets\ApparatusSheetSyncService` — core sync service with metadata verification and retry
- `App\Jobs\SyncApparatusToSheetJob` — queued job dispatched after each apparatus save
- `App\Observers\ApparatusObserver` — stamps `reported_at`, dispatches sync job after commit
- `App\Console\Commands\SyncApparatusSheet` — `artisan apparatus:sync-sheet [--dry-run] [--force]`
- `config/google_sheets.php` — feature flag + secure credential path config

### Credentials
- Service account JSON: `/root/secrets/google_service_account.json` on VPS host
- Mounted read-only into container at `/run/secrets/google_service_account.json`
- **Never committed to git**
- Loaded via env var `GOOGLE_SERVICE_ACCOUNT_JSON_PATH`

### Env Vars (in /root/mbfd-hub/.env)
```
GOOGLE_SHEETS_APPARATUS_SYNC_ENABLED=true
GOOGLE_SERVICE_ACCOUNT_JSON_PATH=/run/secrets/google_service_account.json
GOOGLE_SHEETS_SPREADSHEET_ID=1u9MYILAkfEaMfNZnBujvB1J0J33Ha8TybWCd_mVMJC4
GOOGLE_SHEETS_TAB_TITLE="Equipment Maintenance"
GOOGLE_SHEETS_TAB_SHEET_ID=1714038258
```

### Fire Apparatus Page UI Changes
- **Location column**: Condenses Station + Assignment + Current Location into smart single column
- **Class column**: Hidden by default (data preserved, togglable)
- **Notes → Comments**: Column relabeled
- **Reported**: New auto-stamped datetime column

---

## Key Files
- `.github/workflows/deploy.yml` — CI/CD deploy pipeline (smoke tests target `www.mbfdhub.com`)
- `docs/BASEROW_INTEGRATION.md` — Baserow integration notes
- `server.php` — custom PHP built-in server router override required so `/daily/*` SPA routes work correctly when served through `php artisan serve`

## CI/CD Notes
- Smoke tests in `deploy.yml` target `https://www.mbfdhub.com`
- All darleyplex.com references have been migrated to mbfdhub.com
