# CLAUDE.md — MBFD Hub AI Context

> **Mission Status: ✅ Production** (2026-03-08)  
> NocoBase has been **decommissioned** (2026-03-08) — container stopped, image removed, volume deleted. All Nocobase scripts removed from repo.

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
| `app` | `app` | 8000 | Laravel + Octane |
| `baserow` | `baserow` | 80 (internal) | Baserow self-hosted |
| `db` | `db` | 5432 (internal) | PostgreSQL |
| `redis` | `redis` | 6379 (internal) | Redis |
| `reverb` | `reverb` | 8080 | Laravel Reverb WebSockets |

## Domains
- `www.mbfdhub.com` → Laravel/React app (port 8080) via Cloudflare Tunnel (tunnel ID: 89429799-7028-4df2-870d-f2fb858a49d7)
- `mbfdhub.com` → same as www.mbfdhub.com (redirect)
- `baserow.mbfdhub.com` → Baserow (port 8082) via Cloudflare Tunnel

## Credentials (non-production; rotate before go-live)
- Baserow token: `5c25f5700fedb0f3b46f77b3c9ef41cf` (in `.env` as `BASEROW_TOKEN`)
- GitHub: `pdarleyjr@gmail.com` / token in `.env`
- Sentry DSN: in `config/sentry.php`

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

## CI/CD Notes
- Smoke tests in `deploy.yml` target `https://www.mbfdhub.com`
- All darleyplex.com references have been migrated to mbfdhub.com

---

## Pump Simulator (2026-03-03)

### Summary
A React SPA pump training simulator implemented as a new Vite entry point within the Laravel app. **V2 upgrade (2026-03-03):** Fixed critical build errors, migrated to real Zustand, added advanced fire hydraulics.

### Route
- `GET /pump-simulator` — public, no auth required
- Served by `Route::view('/pump-simulator', 'pump-simulator')->name('pump-simulator')` in `routes/web.php`
- Blade template: `resources/views/pump-simulator.blade.php`

### V2 Build Fixes Applied
- **Removed Tailwind CDN** from blade template (was causing MIME/build conflicts)
- **Added `import React`** to all .tsx files (fixes `ReferenceError: React is not defined`)
- **Removed `rollupOptions.output.entryFileNames`** from `vite.config.js` (was overriding ALL entry filenames)
- **⚠️ STRICT RULE: No `@apply` in pump-simulator CSS** — causes iOS black-screen crash. All styles use plain CSS properties or inline styles.

### Frontend Architecture
- Entry: `resources/js/pump-simulator/main.tsx`
- State: **Zustand store** at `resources/js/pump-simulator/stores/usePumpStore.tsx` (migrated from React Context)
- Components: `PumpPanel.tsx`, `Gauge.tsx` (SVG chrome bezel), `ValveControl.tsx` (expanded), `ShiftModeToggle.tsx`
- CSS: `resources/js/pump-simulator/styles/index.css` (bundled into the main.tsx JS chunk — do NOT add CSS separately to `@vite()` directive)
- Vite config: `pump-simulator` input added in `vite.config.js`

### Zustand Hydraulics Store Architecture
- **10 nozzle profiles**: Smooth bore (15/16", 1", 1⅛", 1¼"), fog (100-250 GPM), master stream (500 GPM), booster (60 GPM)
- **Friction loss formula**: `FL = C × (GPM/100)² × (Length/100)`
  - Coefficients: 1" = 150, 1¾" = 15.5, 2½" = 2.0, 3" = 0.8, 5" = 0.08
- **Intake controls**: Tank-to-Pump, 5" LDH Intake, 3" Pony Suction
- **6 discharge lines**: 2× Crosslays (1¾"), Deck Gun (2½"), Booster (1"), 2× Discharge (2½")
- **Per-line config**: Adjustable hose length and nozzle selection
- **Computed state**: Total flow GPM, pump capacity %, master discharge pressure, cavitation detection
- **Cavitation trigger**: pump mode + intake < 0 PSI + MDP > 150 + throttle > 40%

### Framer Motion Animations
- **Gauge needle**: Spring animation (`stiffness: 100, damping: 10`)
- **Cavitation vibration**: Keyframe array on needle rotation + CSS shake on panel
- **Valve toggles**: Spring-animated thumb position

### Dependencies Added
- `react`, `react-dom`, `@types/react`, `@types/react-dom` — React runtime
- `zustand` — state management (actual Zustand, not React Context wrapper)
- `framer-motion` — animations for gauge needles and cavitation

### Deployment Notes
- The `database/migrations/2026_02_17_000005_update_apparatuses_status_constraint.php` migration requires `'Available'` status to be included (VPS had rows with status='Available')
- After merging to `main` on VPS, run `npm install` then `npm run build` to rebuild Vite assets
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

---

## Workgroup Analytics & AI Worker (2026-03-04)

### Workgroup Analytics Page (Admin Panel)
- **URL**: `/admin/workgroup-analytics`
- **File**: `app/Filament/Pages/WorkgroupAnalytics.php`
- **View**: `resources/views/filament/pages/workgroup-analytics.blade.php`
- **Navigation**: "Workgroup Management" group in admin sidebar
- **Access**: `super_admin`, `admin`, `logistics_admin`
- **Features**: AI summary, session stats, top 3 products per category, evaluator completion tracker

### Workgroup Data Hub (Workgroup Panel)
- **URL**: `/workgroups/admin-dashboard`
- **File**: `app/Filament/Workgroup/Pages/AdminDashboard.php`
- **View**: `resources/views/filament-workgroup/pages/admin-dashboard.blade.php`
- Same analytics, accessible to workgroup facilitators/admins

### Session Results Page (Fixed)
- **URL**: `/workgroups/session-results`
- **File**: `app/Filament/Workgroup/Pages/SessionResultsPage.php`
- **View**: `resources/views/filament/workgroup/pages/session-results.blade.php`
- Read-only for ALL workgroup members, shows aggregate scores + anonymous feedback
- **2026-03-05 Rebuild**: Full page rebuild with header stats widgets, SAVER score breakdown table, AI report panel, Filament v3 `Action` + `Select` form for session switching
- **Widgets enabled**: `SessionProgressWidget` (header), `FinalistsWidget` (footer) — both receive session via Livewire property
- **FinalistsWidget fix**: Replaced deprecated `BadgeColumn` (Filament v2) with `TextColumn::badge()` (Filament v3)
- **heroicon-o-medal fix**: Replaced non-existent `heroicon-o-medal` with `heroicon-o-trophy` in `filament-workgroup/pages/session-results.blade.php`

### Note Sharing Feature
- **File**: `app/Filament/Workgroup/Pages/Notes.php`
- **Model**: `app/Models/WorkgroupNote.php` (added `is_shared`, `shared_with_user_id`)
- **Migration**: `2026_03_04_000004_add_sharing_fields_to_workgroup_notes.php`
- Share toggle + share-with-specific-member select + share action on table rows

### Cloudflare Workgroup AI Worker
- **Worker**: `mbfd-workgroup-ai.pdarleyjr.workers.dev`
- **Vectorize Index**: `workgroup-specs` (1024 dims, cosine)
- **Env var**: `WORKGROUP_AI_WORKER_URL=https://mbfd-workgroup-ai.pdarleyjr.workers.dev`
- **Endpoints**: `/vectorize`, `/analyze`, `/summary`, `/executive-report`, `/health`
- **Models**: `@cf/baai/bge-large-en-v1.5` (embeddings), `@cf/meta/llama-3.3-70b-instruct-fp8-fast` (analysis)

### Home Button
- Admin panel: SVG home icon rendered via `PanelsRenderHook::GLOBAL_SEARCH_BEFORE` + "Return to Home" in user menu
- Workgroup panel: `->homeUrl('/')` + "Home" in user menu
- Training panel: `->homeUrl('/')` + "Home" in user menu
- Pump simulator blade: Fixed red Home button overlay (mobile/iOS safe-area-aware)
- Daily checkout SPA: Sticky HomeNav component at top of all pages

### Pump Panel Landing Card
- Landing page `welcome.blade.php` Pump Panel card links to: `https://pdarleyjr.github.io/puc-sim-manual-ui/` (external, opens in new tab)
- Internal `/pump-simulator` still exists and is functional but is no longer linked from the landing page

### Workgroup Panel Access (FIXED 2026-03-05)
- `admin` and `logistics_admin` roles now have access to workgroup panel
- Fixed in both `User::canAccessPanel('workgroups')` and `EnsureWorkgroupPanelAccess` middleware

# --- 
# 
# ### Cloudflare works illustrated with a diagram (omitted for formatting):  
# [Diagram of Cloudflare Workers and Vectorize Indexes]  
# |                         |aniumстрация             |                               |–––––––––––––––––––––| volticheska careless |Võтivъи andмен|Ятира набъл_seed|ʼ.splitext(d)’|
# |———————————————————–|—————————————————–|—————————————————-|——————————————|[Cloudflare Worker|None|Handled by YakshOhkLee (https://github.com/yakshohklee)||
# |ER Workgroup Workshop     |[https://docs.google.com/forms/d/|                               || grads|JSON|CSV|API|
# |Session Log               |[https://docs.google.com/forms/d/|                               || grads|JSON|CSV|API|
# 
# 

---

## ⚠️ CRITICAL BUG PATTERNS — DO NOT REPEAT (2026-03-05)

### 1. Filament FileUpload in Action Forms Returns STRING, not UploadedFile
**File**: `app/Filament/Workgroup/Pages/SharedUploads.php`  
**Pattern**: When using `FileUpload::make()` inside an `Action::make()->form([...])`, the `$data['file']` value is a **string** (Livewire temp path like `livewire-tmp/abc123.pdf`), NOT an `UploadedFile` object.

**WRONG** (crashes):
```php
$path = $file->store('my-dir', 'public'); // Error: store() on string
```

**CORRECT**:
```php
if (is_string($file)) {
    $contents = Storage::disk('local')->get($file);
    Storage::disk('public')->put('my-dir/' . basename($file), $contents);
    Storage::disk('local')->delete($file);
} else {
    $path = $file->store('my-dir', 'public');
}
```

### 2. Eloquent Accessor Methods Must NOT Use `int` Type Hint
**File**: `app/Models/WorkgroupNote.php`  
**Pattern**: Laravel's accessor system calls `getXxxAttribute($value)` passing `null` as `$value` for computed attributes. PHP rejects `null` for `int` typed parameters.

**WRONG** (crashes on `$note->preview`):
```php
public function getPreviewAttribute(int $length = 100): string {
    return \Str::limit($this->content, $length);
}
```

**CORRECT**:
```php
public function getPreviewAttribute($length = null): string {
    return \Str::limit($this->content ?? '', (int) ($length ?? 100));
}
```

### 3. EvaluationService::getOrCreateDraft() Must NOT Filter by Status
**File**: `app/Services/Workgroup/EvaluationService.php`  
**Pattern**: When loading an evaluation form, always look for ANY existing submission for the member+product pair, regardless of status. If you filter by `status = 'draft'` and the submission is `submitted`, the method won't find it and will try to INSERT a duplicate, violating the unique constraint.

**WRONG** (unique constraint violation on re-visit):
```php
$submission = EvaluationSubmission::where('workgroup_member_id', $member->id)
    ->where('candidate_product_id', $productId)
    ->where('status', 'draft')  // ← WRONG: misses 'submitted' rows
    ->first();
```

**CORRECT**:
```php
$submission = EvaluationSubmission::where('workgroup_member_id', $member->id)
    ->where('candidate_product_id', $productId)
    ->first(); // find ANY existing, regardless of status
```

### 4. Workgroup Panel Access Roles
The workgroup panel (`/workgroups`) requires access via `canAccessPanel('workgroups')` in `User.php` and `EnsureWorkgroupPanelAccess` middleware. Both must include ALL authorized roles:
- `super_admin`, `admin`, `logistics_admin`
- `workgroup_admin`, `workgroup_facilitator`, `workgroup_member`

### 5. Stale Cache After Code Deployments
After ANY code deployment, always run this full cache clear sequence:
```bash
php artisan optimize:clear
php artisan filament:clear-cached-components
php artisan permission:cache-reset
php artisan cache:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
```
Failure to run `permission:cache-reset` leaves stale Spatie role/permission data cached in Redis.

### 6. Evaluation Form — Evaluations Are Editable
**File**: `app/Filament/Workgroup/Pages/EvaluationFormPage.php`  
By design, submitted evaluations are now fully editable. `isReadOnly` is always `false`. Do NOT add `$this->isReadOnly = true` back — users must be able to revise their evaluations at any time.

---

## NotebookLM MCP Integration (2026-03-05)

### Overview
The `notebooklm-mcp@latest` package is the active NotebookLM MCP server for this workspace. It is configured at the project level so AI agents can query the MBFD Hub knowledge base directly from Kilo Code without relying on a legacy global server.

### Target Notebook
- **Notebook URL:** `https://notebooklm.google.com/notebook/1f2a60f2-e047-4499-a43f-4e0f3157a743?authuser=1`
- **Account:** `pdarleyjr@gmail.com`
- **Contents:** MBFD Hub architecture docs, phase reports, schema references, deployment decisions

### MCP Server Config (project `.kilocode/mcp.json`)
Canonical config location:
```json
{
  "notebooklm": {
    "command": "npx",
    "args": [
      "-y",
      "notebooklm-mcp@latest"
    ],
    "env": {
      "HEADLESS": "false",
      "NOTEBOOK_PROFILE_STRATEGY": "single",
      "NOTEBOOK_CLONE_PROFILE": "false",
      "SESSION_TIMEOUT": "3600",
      "NOTEBOOK_CLEANUP_ON_STARTUP": "true",
      "NOTEBOOK_CLEANUP_ON_SHUTDOWN": "true"
    }
  }
}
```

### Authentication
Use the MCP auth flow from the agent:
- `get_health` to confirm auth status
- `setup_auth` for first-time login
- `re_auth` if the saved session expires or breaks

Persistent NotebookLM MCP data lives under `%LOCALAPPDATA%\notebooklm-mcp\`. Do not store passwords or enable auto-login; the user logs in manually when prompted.

### Rules File
`/.kilocode/rules/NotebookLM.md` — instructs AI agents to query the notebook before asking the user clarifying questions about MBFD Hub architecture.

### Available Tools (post-activation)
- `ask_question`
- `list_notebooks`
- `get_notebook`
- `select_notebook`
- `add_notebook`
- `update_notebook`
- `remove_notebook`
- `get_health`
- `setup_auth`
- `re_auth`
- and `cleanup_data`

---

### Key Files Added/Modified (2026-03-04 through 2026-03-05)
| File | Purpose |
|---|---|
| `app/Filament/Pages/WorkgroupAnalytics.php` | Admin panel analytics page |
| `app/Filament/Workgroup/Pages/AdminDashboard.php` | Workgroup panel data hub |
| `app/Filament/Workgroup/Pages/SessionResultsPage.php` | Fixed member results page |
| `app/Filament/Workgroup/Pages/Notes.php` | Note sharing feature |
| `app/Filament/Workgroup/Pages/SharedUploads.php` | Fixed file upload (string vs UploadedFile) |
| `app/Filament/Workgroup/Pages/EvaluationFormPage.php` | Evaluations always editable (no read-only) |
| `app/Models/WorkgroupNote.php` | Added sharing fields, fixed preview accessor |
| `app/Models/User.php` | Added admin/logistics_admin to workgroup panel access |
| `app/Http/Middleware/EnsureWorkgroupPanelAccess.php` | Added admin/logistics_admin roles |
| `app/Services/Workgroup/EvaluationService.php` | Fixed getOrCreateDraft to find any status |
| `app/Providers/Filament/AdminPanelProvider.php` | Home button, nav groups |
| `app/Providers/Filament/WorkgroupPanelProvider.php` | Home URL, AdminDashboard registration |
| `app/Providers/Filament/TrainingPanelProvider.php` | Home URL, home button |
| `resources/views/filament/pages/workgroup-analytics.blade.php` | Analytics view |
| `resources/views/filament-workgroup/pages/admin-dashboard.blade.php` | Data hub view |
| `resources/views/filament-workgroup/pages/session-results.blade.php` | Results view |
| `resources/views/pump-simulator.blade.php` | Home button overlay |
| `resources/views/welcome.blade.php` | External pump panel link |
| `resources/js/daily-checkout/src/App.tsx` | HomeNav component |
| `cloudflare-worker/workgroup-ai/` | Cloudflare AI Worker source |

---

## ⚠️ Recent Migration: Session Results Page Rebuild (2026-03-05 Late Evening)

### Root Cause of Original 500 Error
`Filament\Actions\SelectAction` was imported and used in `SessionResultsPage.php` — this class **does not exist** in Filament v3. It was likely copied from a Filament v2 example or hallucinated by a previous AI agent.

### Changes Made

#### `app/Filament/Workgroup/Pages/SessionResultsPage.php` — Full Rebuild
- Removed non-existent `SelectAction` import; replaced with `Action` + `Select` form
- Added `getHeaderWidgets()` returning `SessionProgressWidget` with session passed via `::make()`
- Added `getFooterWidgets()` returning `FinalistsWidget` with session passed via `::make()`  
- Added `getViewData()` returning rich category results with SAVER score breakdowns
- Added `switchSession()` Livewire method for interactive session selection
- Methods `getHeaderWidgetsColumns()` / `getFooterWidgetsColumns()` must be **public** (parent class requires it)

#### `app/Filament/Workgroup/Widgets/FinalistsWidget.php` — v3 Fix
- Removed `use Filament\Tables\Columns\BadgeColumn;` (Filament v2)
- Replaced `BadgeColumn::make('rank')` with `TextColumn::make()` using `->badge()` and `->color()` (Filament v3)
- Simplified subquery: uses direct `AVG(overall_score)` instead of complex COALESCE with legacy fallback
- Added `emptyStateHeading`, `emptyStateDescription`, `emptyStateIcon` for better UX

#### `resources/views/filament/workgroup/pages/session-results.blade.php` — Rich UI
- Renders `getHeaderWidgets()` (session progress stats)
- AI Executive Report panel (same Alpine.js component, improved styling)
- Category Rankings grid: shows each rankable category with full SAVER breakdown table (S/A/V/E/R columns)
- Gold/silver placement badges for top 2 finalists per category
- Advance recommendation votes (✓/✕) and deal-breaker warnings
- Response count with threshold indicators
- Empty state for no active session
- Renders `getFooterWidgets()` (finalists table)

#### `resources/views/filament-workgroup/pages/session-results.blade.php` — Icon Fix
- Replaced `heroicon-o-medal` (doesn't exist) with `heroicon-o-trophy`
- This file is NOT the active view (the active one is at `filament/workgroup/pages/`) but caused `view:cache` failures

### NotebookLM Research Fallback Rule
- If the current NotebookLM MCP returns an empty or blank answer, treat it as an authentication or session failure.
- Run `get_health` first, then `setup_auth` or `re_auth` as needed.
- If NotebookLM still fails, use local project files + local-rag + Context7 for research until auth is restored.

---

## Known Errors & Fixes

### PowerShell `Get-Content` / `type` command for reading files (2026-03-05)
**Error:** Running `Get-Content` in a cmd-backed terminal (e.g., the VS Code integrated terminal when the shell is `cmd.exe`) causes:
```
'Get-Content' is not recognized as an internal or external command
```
**Root cause:** The terminal session defaulted to `cmd.exe` rather than PowerShell 7. `Get-Content` is a PowerShell cmdlet and is not available in cmd.

**Fix applied:** Used the cmd-compatible `type` command instead:
```cmd
type "%APPDATA%\Code\User\globalStorage\kilocode.kilo-code\settings\mcp_settings.json"
```
**Prevention for future agents:** Before using PowerShell cmdlets (`Get-Content`, `Select-String`, `Remove-Item`, etc.) always check whether the active shell is PowerShell or cmd. Use cmd-native equivalents (`type`, `findstr`, `del`) when in cmd, or force PowerShell with `pwsh -Command "..."`.

## 🆕 NEW (2026-03-05): Workgroup Eval AI + Chatbot Optimization

### Landing Page Chatbot Optimization (mbfd-support-ai)
- **Worker**: `mbfd-support-ai.pdarleyjr.workers.dev`
- **Model**: `@cf/meta/llama-3.3-70b-instruct-fp8-fast` (best free-tier, 70B params FP8)
- **Embeddings**: `@cf/baai/bge-large-en-v1.5` (1024-dim, top semantic search)
- **Vectorize Index**: `mbfd-rag-index` (1024-dim, cosine) — SOG + driver manual docs
- **Enhancements**:
  - Streaming responses with blinking cursor UI
  - Conversation history (last 6 turns for multi-turn context)
  - 0.4 similarity threshold to filter low-relevance chunks
  - Top-6 chunk retrieval per query (up from 5)
  - Temperature 0.3 for factual/procedural accuracy
  - Clear conversation button
  - Non-streaming fallback on stream failure

### Workgroup Evaluation AI System (NEW — mbfd-workgroup-ai)
- **Worker**: `mbfd-workgroup-ai.pdarleyjr.workers.dev`
- **Vectorize Index**: `workgroup-specs` (1024-dim, cosine) — vendor product PDFs/specs/brochures
- **Models**: `@cf/meta/llama-3.3-70b-instruct-fp8-fast` + `@cf/baai/bge-large-en-v1.5`
- **⚠️ COMPLETELY SEPARATE from landing page chatbot**
- **Endpoints**:
  - `POST /vectorize` — ingest vendor PDF/spec text chunks into `workgroup-specs` index
  - `POST /analyze` — AI analysis for a single product (pulls eval scores + spec context from vector index)
  - `POST /summary` — category-level ranking summary (battery hydraulics ranked by brand)
  - `POST /executive-report` — full executive report for Health & Safety Committee / Fire Chief
  - `GET /health` — health check
- **Worker source**: `cloudflare-worker/workgroup-ai/` (wrangler.toml + src/index.ts)

### Laravel Integration
- **WorkgroupAIService**: `app/Services/Workgroup/WorkgroupAIService.php`
  - `analyzeProduct()` — generate + cache (2h) AI analysis for a single CandidateProduct
  - `generateCategorySummary()` — category summary with battery-hydraulics brand ranking
  - `generateExecutiveReport()` — full exec report, cached 30min
  - `vectorizeUpload()` — extract text from file and vectorize chunks
  - `clearProductCache()` — invalidate when new eval submitted
- **WorkgroupAIReportExporter**: `app/Filament/Workgroup/Exports/WorkgroupAIReportExporter.php`
  - Exports all products with: category rank, aggregate SAVER scores, finalist votes, deal-breaker count, AI analysis summary, evaluator narrative
  - Access: "🤖 Export AI Report" button in AdminDashboard + SessionResultsPage header
- **WorkgroupAIController**: `app/Http/Controllers/Workgroup/WorkgroupAIController.php`
  - Routes at `POST /api/workgroup/ai/analyze-product/{id}`, `/category-summary`, `/executive-report`, `/vectorize-upload/{id}`
  - Auth: `web` + `auth` middleware (Filament session)
- **WorkgroupSharedUploadObserver**: `app/Observers/WorkgroupSharedUploadObserver.php`
  - Auto-vectorizes uploaded PDF/DOCX/TXT/PPT files after response (non-blocking `dispatch->afterResponse()`)

### AI Intelligence Report Panel (UI)
- **AdminDashboard** (`/workgroups/admin-dashboard`): Alpine.js "AI Intelligence Summary" panel
  - Purple gradient card with Generate/Regenerate report button
  - Calls `POST /api/workgroup/ai/executive-report` (cached, auto-refreshes on demand)
  - Copy to clipboard button
- **SessionResultsPage** (`/workgroups/session-results`): Same panel, more compact
- Both panels use Alpine.js with CSRF token extraction

### Cache Strategy
- Product analysis: `workgroup_ai_product_{id}` — 2 hours (cleared on eval submit)
- Category summary: MD5-keyed — 2 hours
- Executive report: `workgroup_ai_exec_report_{session_id}` — 30 minutes
- `EvaluationSubmission::updated()` observer clears product cache when status → submitted

### Env Var (already on VPS)
```
WORKGROUP_AI_WORKER_URL=https://mbfd-workgroup-ai.pdarleyjr.workers.dev
```

### VPS Commit
`be0f4c30` — feat: workgroup AI eval system + chatbot optimization

---

### ✨ Phase 4: Equipment Intake UI + Snipe-IT Integration (2026-03-06)

#### Equipment Intake Page (Admin Panel)
- **URL**: `/admin/equipment-intake`
- **File**: `app/Filament/Admin/Pages/EquipmentIntake.php`
- **View**: `resources/views/filament/admin/pages/equipment-intake.blade.php`
- **Navigation**: "Inventory & Logistics" group in admin sidebar
- **Access**: `super_admin`, `admin`, `logistics_admin`

#### Mode A: AI Camera Scan
- HTML5 camera capture (`<input type="file" accept="image/*" capture="environment">`)
- Alpine.js `equipmentScanner()` component converts image to Base64
- Sends POST to Cloudflare Vision Worker: `https://vision-agent.pdarleyjr.workers.dev`
- Worker returns `{"brand": "...", "model": "...", "serial": "..."}`
- Pre-fills Filament form fields; user reviews and selects Location (required)
- "Approve & Save" fires `SnipeItService::createAsset()` → Snipe-IT API
- Seamless loop: form clears (keeps location) and camera re-opens after success

#### Mode B: Bulk / Manual Entry
- Rapid-entry grid for consumables/tools without serial numbers
- Columns: Item Name, Quantity, Category (dropdown), Notes
- Add/remove rows dynamically
- Single location selector for entire batch
- "Submit All to Snipe-IT" creates one asset per quantity unit

#### Snipe-IT Integration
- **Service**: `app/Services/SnipeItService.php`
- **Config**: `config/snipeit.php`
- **Internal API**: `http://snipeit:80/api/v1/` (Docker container)
- **External API**: `https://inventory.mbfdhub.com/api/v1/`
- **Env vars**: `SNIPEIT_API_URL`, `SNIPEIT_API_TOKEN`
- Methods: `createAsset()`, `bulkCreateAssets()`, `getLocations()`, `getModels()`
- Fallback static locations if Snipe-IT API is unreachable

#### Cloudflare Vision Worker
- **URL**: `https://vision-agent.pdarleyjr.workers.dev`
- **Method**: POST `{"image": "base64_string"}`
- **Response**: `{"brand": "...", "model": "...", "serial": "..."}`
- Used exclusively by the Equipment Intake AI Camera Scan mode

#### Files Added
| File | Purpose |
|---|---|
| `app/Filament/Admin/Pages/EquipmentIntake.php` | Filament custom page (Livewire) |
| `resources/views/filament/admin/pages/equipment-intake.blade.php` | Blade view with Alpine.js scanner |
| `app/Services/SnipeItService.php` | Snipe-IT API client service |
| `config/snipeit.php` | Snipe-IT configuration |

---

## 🆕 Workgroup User & Results Page Fixes (2026-03-06 Afternoon)

### Luis Cruzado Login Fix
- **User**: `luiscruzado@miamibeachfl.gov` (user_id=23)
- **Root cause**: Had `workgroup_member` role but NO `workgroup_members` database record — could not access workgroup dashboard, evaluations, or files
- **Fix**: Created `WorkgroupMember` record (member_id=16, workgroup_id=2, role=member, is_active=true, count_evaluations=true)
- **Also set**: `email_verified_at` (was NULL, not required by panel but set for consistency)
- **NOTE**: Evelio Aleman (user_id=26) and Alejandro Trujillo (user_id=22) are NOT workgroup members — do NOT create records for them

### getOrCreateDraft ERROR-010 Fix (Finally Deployed to VPS)
- **File**: `app/Services/Workgroup/EvaluationService.php`
- The `->where('status', 'draft')` filter in `getOrCreateDraft()` was STILL present on VPS despite being documented as fixed
- Removed the filter so it finds ANY existing submission regardless of status
- This prevents unique constraint violations when users revisit submitted evaluations

### View Cache Fix (ERROR-001 Resolution)
- **File**: `resources/views/filament_workgroup/pages/session-results.blade.php`
- This UNUSED alternate blade view had `x-filament::card.heading` components (Filament v2 only)
- Replaced with a comment stub so `php artisan view:cache` succeeds
- The ACTIVE view at `resources/views/filament/workgroup/pages/session-results.blade.php` is unaffected

### NS_BINDING_ABORTED Errors (Browser-Side, Not Server Bug)
- These are browser request cancellations — occur when Livewire sends AJAX updates and old requests get superseded
- Normal Filament v3 behavior with multiple widget polls and AI report auto-generation
- NOT a server error and does NOT indicate broken functionality

### GitHub Commit
- `c36c5cf6` — fix: getOrCreateDraft status filter + stale blade view cache fix

---

## 🆕 Equipment Intake UI Fixes + Snipe-IT SAML SSO (2026-03-06 Evening)

### Equipment Intake UI Fixes
1. **Inline script → `@push('scripts')`**: The `equipmentScanner()` Alpine.js component in `equipment-intake.blade.php` was in a bare `<script>` tag. Moved to `@push('scripts')` for proper Filament asset loading order.
2. **N+1 API fix**: `submitBulkItems()` in `EquipmentIntake.php` was calling `createAsset()` per quantity unit in a nested loop. Refactored to build a flat payload array and call `bulkCreateAssets()` once.

### Snipe-IT Navigation Link
- Added `NavigationItem::make('Snipe-IT Inventory')` to `AdminPanelProvider.php`
- URL: `https://inventory.mbfdhub.com/`, opens in new tab
- Group: "Inventory & Logistics", icon: `heroicon-o-cube`

### Admin Role Verification
- **Command**: `php artisan mbfd:ensure-admin-roles`
- **File**: `app/Console/Commands/EnsureAdminRoles.php`
- Ensures 5 specified users have admin-level roles (case-insensitive email lookup)
- Run on VPS after deploy, then `php artisan permission:cache-reset`

### Snipe-IT SAML SSO
- **Research**: Snipe-IT natively supports SAML as a Service Provider. No OAuth or shared-session support.
- **Chosen method**: SAML 2.0 with MBFD Hub as IdP using `codegreencreative/laravel-samlidp`
- **Config**: `config/samlidp.php` — IdP issuer, cert paths, SP registration for `inventory.mbfdhub.com`
- **Setup guide**: `docs/SNIPEIT_SSO_SETUP.md`
- **Deployment steps**:
  1. `composer require codegreencreative/laravel-samlidp` on VPS
  2. `php artisan samlidp:cert` to generate self-signed certificate
  3. Configure Snipe-IT admin → Settings → SAML with IdP metadata from `https://www.mbfdhub.com/saml/metadata`
  4. Set env vars: `SAML_IDP_ISSUER`, `SNIPEIT_SAML_ENTITY_ID`, `SNIPEIT_SAML_ACS_URL`, `SNIPEIT_SAML_SLS_URL`

### Files Added/Modified
| File | Change |
|---|---|
| `equipment-intake.blade.php` | `@push('scripts')` wrapper |
| `EquipmentIntake.php` | Bulk submit refactor |
| `AdminPanelProvider.php` | Snipe-IT nav link |
| `EnsureAdminRoles.php` | New artisan command |
| `config/samlidp.php` | New SAML IdP config |
| `docs/SNIPEIT_SSO_SETUP.md` | New SSO setup guide |

---

## 🆕 Vision Worker Fix & Equipment Intake Bug Investigation (2026-03-08)

### Root Cause Found & Fixed

**Critical Bug**: Equipment intake AI pipeline was never triggering after image upload.

**Root Causes (Two Compound Issues)**:
1. **Vision Worker source code was NEVER committed** — `cloudflare-worker/vision-agent/` only contained `package-lock.json`. A previous agent deployed the Worker directly to Cloudflare without committing source.
2. **Wrong model name** — the previous Worker used `@cf/llava-1.5-7b-hf` (doesn't exist). Correct name: `@cf/llava-hf/llava-1.5-7b-hf`
3. **llama-3.2-11b-vision ToS not accepted** — When the Worker fell back to llama-3.2-11b-vision-instruct, it returned error 5016 (ToS required). **Fixed by submitting `{ "prompt": "agree" }` to the Cloudflare API**.

**The UI Code Was Correct** — The blade template already has:
- "Analyze Photos with AI" button (`@click="analyzeAllImages()"`)
- Alpine.js `analyzeAllImages()` function that POSTs to the Vision Worker
- `@this.processVisionResult()` to send results to Livewire
- Complete Livewire methods: `processVisionResult()`, `handleScanError()`, `approveAndSave()`, etc.

### Vision Worker Architecture (NEW — 2026-03-08)

**Worker**: `vision-agent.pdarleyjr.workers.dev`
**Source**: `cloudflare-worker/vision-agent/src/index.ts`

**Models Used**:
| Model | Role | Notes |
|---|---|---|
| `@cf/llava-hf/llava-1.5-7b-hf` | Primary | No ToS gate. Input: `{ image: number[], prompt, max_tokens }` |
| `@cf/meta/llama-3.2-11b-vision-instruct` | Fallback | ToS accepted 2026-03-08. Input: messages array with image_url |

**API Contract**:
```
POST https://vision-agent.pdarleyjr.workers.dev
Body: { "image": "base64string" }   OR   { "images": ["base64", ...] }
Response: { "brand": "...", "model": "...", "serial": "...", "confidence": "high|medium|low", "notes": "...", "images_analyzed": N }
```

**ToS Acceptance** (already done, one-time):
```bash
curl -X POST https://api.cloudflare.com/client/v4/accounts/265122b6d6f29457b0ca950c55f3ac6e/ai/run/@cf/meta/llama-3.2-11b-vision-instruct \
  -H "Authorization: Bearer <CF_TOKEN>" \
  -d '{"prompt":"agree"}'
```

### Snipe-IT Verification
- Snipe-IT container (`snipeit`) confirmed UP and reachable from Laravel container
- API endpoint `http://snipeit:80/api/v1/hardware` returns 200 with valid auth token
- `SNIPEIT_API_URL` and `SNIPEIT_API_TOKEN` properly configured in `.env`
- `config/snipeit.php` maps env vars correctly

### Additional Finding: `mbfd-hub-app` Container Crash
- Container `mbfd-hub-app` is crash-looping (PHP 8.3 but composer requires ≥8.4)
- **Does NOT affect production** — `mbfd-hub-laravel.test-1` (sail-8.5/app) is serving traffic successfully
- Site returns 200 at `https://www.mbfdhub.com` — no user impact

### Files Added (2026-03-08)
| File | Purpose |
|---|---|
| `cloudflare-worker/vision-agent/wrangler.toml` | Wrangler config for vision-agent Worker |
| `cloudflare-worker/vision-agent/src/index.ts` | Vision Worker source (llava primary, llama-3.2 fallback) |
| `cloudflare-worker/vision-agent/package.json` | Package metadata |
| `cloudflare-worker/vision-agent/test_vision.py` | Integration test script |
| `tests/Feature/EquipmentIntakeTest.php` | Livewire test coverage for EquipmentIntake page |

---
