# AI AGENT ERROR LOG & PREVENTION GUIDE
## MBFD Hub — Mandatory Pre-Work Reading

> ⚠️ **CRITICAL MANDATE**: Every AI agent working on this codebase MUST read this entire file BEFORE making any changes. Failure to read this file WILL result in breaking existing functionality.

**Last Updated**: 2026-03-18  
**Project**: MBFD Hub (Laravel 11, Filament v3, VPS at 145.223.73.170) + DeerFlow 2.0 (WSL local)

---

## HOW TO USE THIS FILE

1. **Read every error entry** before starting any task
2. **Add new entries** when you encounter and fix errors
3. **Reference existing entries** when making similar changes to avoid repeat mistakes
4. **Document the fix** completely — include file paths, code before/after, and root cause

---

## ⚠️ ERROR LOG

---

### ERROR-001: Filament v3 Component Compatibility — `x-filament::card.heading` / `x-filament::card.content`

**Date**: 2026-03-05  
**Severity**: 🔴 CRITICAL — causes 500 error, crashes blade cache  
**File(s) Affected**: Any `.blade.php` in `resources/views/filament*/**`

**Symptom**:
```
InvalidArgumentException: Unable to locate a class or view for component [filament::card.heading]
```

**Root Cause**: 
`x-filament::card.heading` and `x-filament::card.content` are NOT valid Filament v3 components.

**Fix Applied**:
Replace with plain HTML or `x-filament::section`.

**Prevention**: 
- Never use `x-filament::card.heading` or `x-filament::card.content`.

---

### ERROR-002: SCP File Transfer — Path with Spaces Causes Silent Failure

**Date**: 2026-03-05  
**Severity**: 🟡 MEDIUM  

Use FULL absolute paths in SCP commands when workspace has spaces.

---

### ERROR-003: Overwriting Critical PHP Files That Had Previous Bug Fixes

**Date**: 2026-03-05  
**Severity**: 🔴 CRITICAL  

**Prevention**: ALWAYS read `CLAUDE.md` and check VPS version before overwriting PHP files with `canAccess()` or role-checking logic.

---

### ERROR-004: Similarity Threshold Too High — Chatbot Returns Empty Context

**Date**: 2026-03-05  
**Severity**: 🟡 MEDIUM  

Threshold for `mbfd-rag-index` should stay at 0.2 or lower.

---

### ERROR-005: getHeaderWidgets() vs getWidgets() in Filament v3 Page Views

**Date**: 2026-03-05  
**Severity**: 🟡 MEDIUM  

Use `getWidgets()` not `getHeaderWidgets()` for main page widgets.

---

### ERROR-006: Vision Worker Model Requires ToS Acceptance — Error 5016

**Date**: 2026-03-08  
**Severity**: 🔴 CRITICAL  

Before using any Cloudflare AI model, check ToS requirements. Never deploy Workers without committing source code.

---

### ERROR-007: `mbfd-hub-app` Container Crash — PHP Version Mismatch

**Date**: 2026-03-08  
**Severity**: 🟡 MEDIUM — does NOT affect production (served by `laravel.test-1`)

---

### ERROR-018: Filament v3 Widgets as Livewire Children — Stale State on Parent Property Change
**Date**: 2026-03-08
**Status**: ✅ RESOLVED (2026-03-11)

Remove Livewire widgets from pages with reactive switching. Use `getViewData()` + plain Blade.

---

### ERROR-019: `pxlrbt/filament-excel` Not Installed — ApparatusResource 500

**Date**: 2026-03-09  
**Severity**: 🔴 CRITICAL  

**Prevention**: NEVER add `use` imports for packages not in `composer.json`.

---

### ERROR-020: Google Sheets Apparatus Sync — Three Stacked Failures

**Date**: 2026-03-09  
**Severity**: 🔴 CRITICAL  

1. `google/apiclient` not installed
2. Service account JSON not mounted
3. No queue worker running

---

### ERROR-021: Chatify NS_BINDING_ABORTED — Missing `enabledTransports`

**Date**: 2026-03-09  
**Severity**: 🔴 CRITICAL  

Always add `enabledTransports: ['ws', 'wss']` to prevent SockJS fallback.

---

### ERROR-022: Reverb WebSocket Server Not Running in Container After Restart

**Date**: 2026-03-09  
**Severity**: 🔴 CRITICAL  

Add `[program:reverb]` to supervisord.conf. Verify after restarts: `docker exec mbfd-hub-laravel.test-1 ps aux | grep reverb`

---

### ERROR-023: Chatify "No internet access" Despite Successful WebSocket Connection

**Date**: 2026-03-09  
**Severity**: 🔴 CRITICAL — **FIXED**  

Split-brain config: backend must use internal Reverb endpoint (127.0.0.1:8080), frontend uses public endpoint (www.mbfdhub.com:443).

---

### ERROR-024: Chatify Root Cause Discovery Audit
**Date**: 2026-03-09  
**Severity**: 🔣 DIAGNOSTIC  

See ERROR-023 for details.

---

### ERROR-029: JSON Checklist Files in Wrong Storage Path

**Date**: 2026-03-10  
**Severity**: 🔴 CRITICAL  

`storage_path('app/')` maps to `storage/app/`, NOT `storage/`. Use `designation` not `type` for ladder sub-type differentiation.

---

### ERROR-030: SPA Deep Route 404s — Not an Nginx Issue
**Date**: 2026-03-10  
**Severity**: 🟢 INFO  

Routing stack already correctly configured. Stale SW cache causes 404s.

---

### ERROR-031: Filament Admin Theme CSS — Broken Selectors + `@apply` iOS Risk

**Date**: 2026-03-10  
**Severity**: 🔴 CRITICAL  

CSS selectors missing `.` prefix. Never use `@apply` (iOS Safari crash risk).

---

### ERROR-032: Tailwind CDN on Production Blade Page

**Date**: 2026-03-10  
**Severity**: 🔴 CRITICAL  

Never use `cdn.tailwindcss.com` in production. Use `@vite('resources/css/app.css')`.

---

### ERROR-035: Phase 1 Impeccable Design System

**Date**: 2026-03-11  
**Severity**: 🟢 INFO  

Design modernization. No `@apply`, no bouncy easing, warm stone neutrals only.

---

### ERROR-037: UI Uniformity Failure — Dark Header CSS Not Compiled on VPS

**Date**: 2026-03-12  
**Severity**: 🟡 MEDIUM  
**Status**: ✅ RESOLVED  
**File(s) Affected**: `resources/css/filament/admin/theme.css`, `public/build/assets/theme-*.css`

**Symptom**:
After committing and pushing dark topbar CSS changes to `main` and pulling on VPS via `git pull`, the Filament panels still showed the old white topbar. The source CSS in `resources/css/filament/admin/theme.css` was correct on the VPS.

**Root Cause**:
`npm run build` was never executed on the VPS after `git pull`. The `public/build/` directory is gitignored, so compiled Vite assets do not transfer via git. The VPS was still serving the previously compiled theme CSS which did not include the dark topbar styles.

**Fix Applied**:
Ran `docker exec mbfd-hub-laravel.test-1 npm run build` directly on the VPS to recompile the Filament theme. Confirmed 11 build artifacts generated including `theme-B-aUFWYd.css` at 121.40 KB. Cleared all caches with `optimize:clear`.

**Prevention**:
1. **Any change to `resources/css/` requires server-side Vite compilation** — `git pull` alone is NOT sufficient
2. After pulling CSS changes on VPS, always run: `docker exec mbfd-hub-laravel.test-1 npm run build`
3. Verify the build output includes the expected theme file: `ls -la public/build/assets/theme-*.css`
4. The CI/CD pipeline (`deploy.yml`) handles this automatically, but manual deploys via `git pull` do NOT

---

### ERROR-038: Station Inspection API Endpoint Mismatch

**Date**: 2026-03-12  
**Severity**: 🔴 HIGH  
**Status**: ✅ RESOLVED  
**File(s) Affected**: `routes/api.php`, `app/Http/Controllers/Api/StationInspectionController.php`, `resources/js/daily-checkout/src/pages/StationInspection.tsx`

**Symptom**:
React station inspection form submitted but received 401/404/500 errors. Multiple compounding issues prevented successful submission.

**Root Cause**:
Four layered issues:
1. **Wrong URL**: React posted to `/api/station-inspections` but Laravel route was `/api/public/station_inspection` (underscores, singular)
2. **Auth barrier**: Route was inside `auth:sanctum` middleware group; public tablet submissions have no auth token
3. **Data shape mismatch**: Controller expected flat fields but React sent nested JSON structure
4. **Station name accessor**: Controller used `$station->name` but Station model had no `name` attribute — it was `station_name`

**Fix Applied**:
1. Moved route to public API group (no auth middleware)
2. Updated React to POST to `/api/public/station_inspection`
3. Aligned controller to accept the nested JSON structure from React
4. Fixed station name accessor to use `station_name` column

**Prevention**:
1. Always verify API route paths match between frontend and backend before deploying new forms
2. Public-facing tablet forms must use unauthenticated API routes under `/api/public/`
3. Check model column names against database schema, not assumptions

---

### ERROR-039: Storage Permissions Denied (500 Error)

**Date**: 2026-03-12  
**Severity**: 🔴 HIGH  
**Status**: ✅ RESOLVED  
**File(s) Affected**: `storage/`, `bootstrap/cache/`

**Symptom**:
500 errors on various pages. Laravel log showed "Permission denied" when writing to `storage/framework/views/` and `storage/logs/`.

**Root Cause**:
Docker container runs as `sail` user (UID 1000), not `www-data`. After container recreation or volume remount, file ownership reverts to root, preventing the application from writing to storage directories.

**Fix Applied**:
```bash
docker exec mbfd-hub-laravel.test-1 chmod -R 777 storage bootstrap/cache
```

**Prevention**:
1. After ANY container recreation, always run: `docker exec mbfd-hub-laravel.test-1 chmod -R 777 storage bootstrap/cache`
2. Add this to deployment scripts as a post-deploy step
3. Laravel Sail uses `sail` user — never assume `www-data` ownership

---

### ERROR-040: Docker Overlay Filesystem Serving Stale Files

**Date**: 2026-03-12  
**Severity**: 🟡 MEDIUM  
**Status**: ✅ RESOLVED  
**File(s) Affected**: `public/build/`, `node_modules/`

**Symptom**:
After `git pull` and running `npm run build` on the host, the browser still served old JavaScript/CSS bundles. Vite manifest pointed to files that existed on disk but Docker served stale overlay content.

**Root Cause**:
Docker's overlay filesystem caches file layers. Running `npm run build` on the host writes to the bind-mounted volume, but the container's overlay may not reflect changes immediately — especially if `node_modules` inside the container differs from the host. The container must be recreated and `npm run build` must execute INSIDE the container.

**Fix Applied**:
```bash
docker compose down && docker compose up -d
docker exec mbfd-hub-laravel.test-1 bash -c 'npm install && npm run build'
```

**Prevention**:
1. **ALWAYS run `npm run build` INSIDE the Docker container**, never on the host
2. Command: `docker exec mbfd-hub-laravel.test-1 bash -c 'npm install && npm run build'`
3. After major changes, recreate containers: `docker-compose down && docker compose up -d`

---

### ERROR-041: Station Inspection View 500 — Array to String Conversion

**Date**: 2026-03-12  
**Severity**: 🟡 MEDIUM  
**Status**: ✅ RESOLVED  
**File(s) Affected**: `app/Filament/Resources/StationInspectionResource.php`

**Symptom**:
Viewing a station inspection record in Filament admin panel threw a 500 error: "Array to string conversion".

**Root Cause**:
The `station_inspection` table stores JSON columns (e.g., `piping`, `equipment_condition`). Filament's `TextEntry` attempted to render these JSON arrays as plain strings, causing a PHP "Array to string conversion" error.

**Fix Applied**:
Used `->getStateUsing()` on affected TextEntry fields to serialize JSON values before display:
```php
TextEntry::make('piping')
    ->getStateUsing(fn ($record) => is_array($record->piping) ? json_encode($record->piping, JSON_PRETTY_PRINT) : $record->piping),
```

**Prevention**:
1. When displaying JSON/array database columns in Filament, always use `->getStateUsing()` to serialize
2. Alternatively, use `->formatStateUsing()` or custom Filament view components for complex JSON display
3. Test Filament resource views with actual data before deploying

---

### ERROR-042: Station List Missing Counts — capitalProjects & shopWorks Not Eager-Loaded

**Date**: 2026-03-13  
**Severity**: 🟡 MEDIUM  
**Status**: ✅ RESOLVED  
**File(s) Affected**: `app/Http/Controllers/Api/StationController.php`

**Symptom**:
Station list cards in the React SPA (`StationCard.tsx`) showed "0 Projects" and "0 Shop Works" for all stations, despite data existing in the database.

**Root Cause**:
`StationController::index()` used `->withCount('apparatuses', 'rooms')` but omitted `capitalProjects` and `shopWorks`. The React frontend expected `capital_projects_count` and `shop_works_count` in the JSON response.

**Fix Applied**:
Added `'capitalProjects', 'shopWorks'` to the `withCount()` call in the `index()` method.

**Prevention**:
1. When adding count displays to React components, verify the backend API returns the corresponding `withCount` data
2. Always check both the API controller and the frontend component for data shape alignment

---

### ERROR-043: Apparatus Slug Null — Vehicle Inspection Link to `/vehicle-inspections/null`

**Date**: 2026-03-13  
**Severity**: 🟡 MEDIUM  
**Status**: ✅ RESOLVED  
**File(s) Affected**: `app/Models/Apparatus.php`, `app/Console/Commands/BackfillApparatusSlugs.php`

**Symptom**:
Apparatus records like "Captain 5" had `slug: null` in the database. When the React SPA rendered the vehicle inspection list, clicking these items would navigate to `/vehicle-inspections/null`. The React code (`VehicleInspectionSelect.tsx`) already handled null slugs gracefully (showing disabled cards), but the root cause — missing slugs — needed fixing.

**Root Cause**:
The Apparatus model had no auto-slug generation. Slugs were only populated if manually set during creation. Existing records that pre-dated the slug column addition had null values.

**Fix Applied**:
1. Added `booted()` lifecycle hook on `Apparatus` model to auto-generate `Str::slug(designation)` on `creating` and `updating` events when slug is empty
2. Created `artisan apparatus:backfill-slugs` command to fix all existing null-slug records

**Prevention**:
1. When adding a slug column to a model, always add an auto-generation boot hook AND a backfill migration/command
2. Run `php artisan apparatus:backfill-slugs` after deployment to fix existing records

---

### ERROR-044: Fast Edit File accidentally deleting methods
**Date**: 2026-03-14
**Status**: ✅ RESOLVED

Description:
The `fast_edit_file` feature was developed to quickly add or edit `Apparatus` models from the React frontend. It allowed users to modify up to five properties in one go. However, during a refactor of the `Apparatus` model, a direct database migration was applied which accidentally deleted four out of the five properties in the `fast_edit_file` feature's data table.
This was a cascade delete的影響，當對象删除時，將它與之相關的對象也刪除。未能预料到連鎖反應可能导致額外的破壞，導致誤刪除了一些本該保留的方法。

Solution:
rolled back to the previous version and added back the deleted methods. Explanation has been provided in the deploy notes so that developers can be aware of this change.

---

### ERROR-063: DeerFlow Total System Lockout — `chown -R 1000:1000` on `.deer-flow/` Revokes All Container Access

**Date**: 2026-03-17  
**Severity**: 🔴 CRITICAL  
**Status**: ✅ RESOLVED  
**File(s) Affected**: `~/src/deer-flow/backend/.deer-flow/` (entire directory tree)

**Symptom**:
- React frontend throws `JSON.parse` errors on every page load  
- All API calls return HTTP 500  
- Agent profiles, chat history, memory fail to load  
- File uploads fail: `Permission denied: '/mnt/user-data/uploads/filename'`
- Nginx returns HTTP 502 error pages

**Root Cause**:
Running `sudo chown -R 1000:1000 ~/src/deer-flow/backend/.deer-flow/` changed file ownership to UID 1000 (devcontainers), but left files as `644` (rw-r--r--). The Docker containers run as root (UID 0). While root can READ `644` files, SQLite WAL mode requires **exclusive WRITE access** to `checkpoints.db-shm` and `checkpoints.db-wal` to operate. Without write permission, every SQLite operation throws fatal disk I/O errors, crashing the API with 500s.

Similarly, the `threads/*/user-data/uploads/` directories become non-writable for the gateway, causing all file uploads to fail.

**Fix Applied**:
Run `chmod -R 777` from INSIDE the container as root:
```bash
docker exec deer-flow-langgraph chmod -R 777 /app/backend/.deer-flow/
docker exec deer-flow-gateway chmod -R 777 /app/backend/.deer-flow/
```

**Prevention**:
1. **NEVER run `chown` on `~/src/deer-flow/backend/.deer-flow/`**
2. The correct fix is always: `docker exec deer-flow-langgraph chmod -R 777 /app/backend/.deer-flow/`
3. Host-side `sudo chmod -R 777` may not propagate correctly — always apply from inside the container
4. Ensure that any scripts used inside containers (`inject-push-sw.js`) do not inadvertently change container file ownership

---

### ERROR-064: DeerFlow MCP Hallucination — `filesystem` Server Injected with Invalid Host Paths

**Date**: 2026-03-17  
**Severity**: 🔴 CRITICAL  
**Status**: ✅ RESOLVED  
**File(s) Affected**: `~/src/deer-flow/extensions_config.json`

**Symptom**:
LangGraph crashes fatally on startup. All agentic workflows fail with connection errors.

**Root Cause**:
A previous agent injected a `"filesystem"` MCP server with host paths (`/home/devcontainers/...`) that don't exist inside Docker containers. `CLAUDE.md` explicitly forbids this server — DeerFlow uses AioSandboxProvider.

**Fix Applied**:
Remove `"filesystem"` from `extensions_config.json`. Only 5 authorized servers: `github`, `memory`, `sequential-thinking`, `git-mcp`, `context7`.

**Prevention**:
1. **NEVER add `filesystem` MCP** — forbidden, use AioSandboxProvider instead
2. **NEVER use host paths** in MCP args inside Docker containers
3. After `extensions_config.json` changes: `docker exec deer-flow-gateway curl -sf http://langgraph:2024/ok`

---

### ERROR-065: DeerFlow Nginx Stale DNS Cache After `docker compose restart` — All API Routes 502

**Date**: 2026-03-17  
**Severity**: 🔴 CRITICAL  
**Status**: ✅ RESOLVED  
**File(s) Affected**: Docker networking, nginx upstream resolution

**Symptom**:
After `docker compose restart`, all `/api/*` and `/api/langgraph/*` routes return HTTP 502. LangGraph health check confirms it's up, but nginx routes to stale container IPs.

**Root Cause**:
`docker compose restart` may reassign container IPs. Nginx caches upstream DNS at startup and continues routing to stale IPs, causing "Connection refused."

**Fix Applied**:
```bash
docker exec deer-flow-nginx nginx -s reload
```

**Mandatory Recovery Sequence** (after ANY restart):
```bash
cd ~/src/deer-flow/docker && docker compose -f docker-compose-dev.yaml restart
docker exec deer-flow-langgraph chmod -R 777 /app/backend/.deer-flow/
docker exec deer-flow-gateway chmod -R 777 /app/backend/.deer-flow/
for s in $(docker ps --filter name=deer-flow-sandbox --format '{{.Names}}'); do
  docker exec $s chmod -R 777 /mnt/user-data/
done
docker exec deer-flow-nginx nginx -s reload
docker exec deer-flow-gateway curl -sf http://langgraph:2024/ok
```

**Prevention**:
1. **Always reload nginx after any DeerFlow restart**: `docker exec deer-flow-nginx nginx -s reload`
2. Check nginx logs for stale IPs: `docker logs deer-flow-nginx --tail=20 | grep "Connection refused"`

---

### ERROR-066: DeerFlow AIO Sandbox File Upload Fails — `/mnt/user-data` Parent Dir is 755 Root-Owned

**Date**: 2026-03-17  
**Severity**: 🔴 CRITICAL  
**Status**: ✅ RESOLVED  (permanent fix applied to codebase)
**File(s) Affected**: `backend/packages/harness/deerflow/community/aio_sandbox/aio_sandbox_provider.py`, AIO sandbox containers

**Symptom**:
All file uploads fail with `Permission denied: '/mnt/user-data/uploads/filename'`. Applies to local UI uploads, remote Cloudflare tunnel uploads, and Telegram file attachments. Error comes from the AIO sandbox API (port 8080) trying to sync files to the sandbox's virtual filesystem.

**Root Cause**:
Docker creates the `/mnt/user-data` directory inside new sandbox containers as `root:755` by default. The AIO sandbox's `gem` user process cannot write to the parent directory itself (though subdirs created by the harness are already 777). Every new sandbox container spawned by the provider has this problem on first use.

Additionally, when `docker compose restart` runs, the gateway provider spawns a NEW sandbox container (on a different port). That new container inherits the root:755 problem on `/mnt/user-data`. Even though the old sandbox was fixed in the same session, the new one is broken.

**Fix Applied**:
1. **Immediate**: `docker exec {sandbox_name} chmod -R 777 /mnt/user-data/`
2. **Permanent**: Added `chmod -R 777 /mnt/user-data` to `_create_sandbox()` in `aio_sandbox_provider.py` immediately after the sandbox becomes ready:
```python
sandbox = AioSandbox(id=sandbox_id, base_url=info.sandbox_url)
# Ensure /mnt/user-data is writable by the sandbox gem user
try:
    sandbox.execute_command("chmod -R 777 /mnt/user-data")
    logger.info(f"Set /mnt/user-data permissions for sandbox {sandbox_id}")
except Exception as e:
    logger.warning(f"Could not chmod /mnt/user-data in sandbox {sandbox_id}: {e}")
```

**Prevention**:
1. After ANY gateway restart, check for new sandbox containers and fix them: `for s in $(docker ps --filter name=deer-flow-sandbox --format '{{.Names}}'); do docker exec $s chmod -R 777 /mnt/user-data/; done`
2. The permanent code fix in `aio_sandbox_provider.py` ensures all NEW sandbox containers get 777 permissions automatically
3. Upstream DeerFlow does not have this fix — it was added by MBFD. Do NOT revert the patch when pulling upstream updates

---

### ERROR-067: DeerFlow Major Upstream Restructure — `backend/src/` Renamed to `backend/app/` + `backend/packages/harness/` (2026-03-17)

**Date**: 2026-03-17  
**Severity**: 🔴 CRITICAL  
**Status**: ✅ RESOLVED  

**Symptom**:
After `git pull` from upstream, containers fail to start with:
- Gateway: `Error loading ASGI app. Could not import module "src.gateway.app"`
- LangGraph: `FileNotFoundError: '/app/backend/src/agents/checkpointer/async_provider.py'`

**Root Cause**:
Upstream DeerFlow 2.0 commit `0091d9f` performed a major restructure:
- `backend/src/channels/` → `backend/app/channels/`
- `backend/src/gateway/` → `backend/app/gateway/`
- `backend/src/agents/`, `backend/src/community/`, etc. → `backend/packages/harness/deerflow/`
- The gateway ASGI command changed from `src.gateway.app:app` to `app.gateway.app:app`
- `langgraph.json` updated to use `deerflow.agents:make_lead_agent` and new checkpointer path
- `config.example.yaml` bumped to `config_version: 2` and sandbox `use` changed from `src.community.aio_sandbox:AioSandboxProvider` to `deerflow.community.aio_sandbox:AioSandboxProvider`

**Fix Applied**:
1. `git pull origin main` with stash/pop to preserve MBFD customizations
2. Git auto-merged renamed files correctly (our diffs now live in `backend/app/channels/telegram.py` and `backend/packages/harness/deerflow/community/aio_sandbox/aio_sandbox_provider.py`)
3. Updated `config.yaml` with `config_version: 2` and correct `deerflow.*` module paths
4. Full `docker compose down -v && docker compose build --no-cache && docker compose up -d`
5. Forced removal of stale Docker network (old network had active endpoints blocking new network creation)

**Prevention**:
1. When pulling DeerFlow upstream, always check `config.example.yaml` for `config_version` changes — run `make config-upgrade` or update manually
2. After major upstream changes, always do `docker compose down -v` (remove volumes) before rebuild
3. Check `backend/src/` for any stale non-Python files (e.g., `__pycache__`) that confuse uvicorn's hot-reload
4. The sandbox `use` path MUST use `deerflow.*` not `src.*` after the harness refactor

---

### ERROR-068: DeerFlow React Hydration Mismatch — Nested `<button>` Elements in Prompt Input UI

**Date**: 2026-03-17  
**Severity**: 🔴 CRITICAL — file upload menu completely non-functional, onClick listeners fail to bind  
**Status**: ✅ RESOLVED  
**File(s) Affected**: 
- `frontend/src/components/ui/input-group.tsx` (InputGroupButton)
- `frontend/src/components/ai-elements/prompt-input.tsx` (PromptInputButton, PromptInputActionMenuTrigger)
- `frontend/src/components/ai-elements/suggestion.tsx` (Suggestion)
- `frontend/src/components/workspace/chats/chat-box.tsx` (ResizablePanelGroup prop)
- `frontend/src/components/workspace/mode-hover-guide.tsx` (ModeHoverGuide)

**Symptom**:
Browser console throws React Hydration Mismatch error. The attachment/upload menu cannot be opened. All `onClick` listeners on the prompt input toolbar fail to bind.

**Root Cause (Two-Part)**:

**Part 1 — Missing `forwardRef`**: Radix UI's `asChild` uses `Slot` to merge props onto child elements. Components without `forwardRef` cause Slot to render its own `<button>` wrapper, creating invalid nested `<button>` elements.

**Part 2 — Radix ID Collision**: `ModeHoverGuide` wraps `PromptInputActionMenuTrigger` with `<TooltipTrigger asChild>`, while the trigger internally uses `<DropdownMenuTrigger asChild>`. Both Radix triggers merge onto the same `<button>`, each setting auto-generated `id` attributes that differ between SSR and client hydration.

**Fix Applied**:

1. **`InputGroupButton`** — converted to `React.forwardRef`, passes `ref` to `<Button>`
2. **`PromptInputButton`** — converted to `forwardRef`, passes `ref` to `<InputGroupButton>`
3. **`PromptInputActionMenuTrigger`** — converted to `forwardRef`, passes `ref` to `<PromptInputButton>`
4. **`Suggestion`** — converted to `React.forwardRef`, passes `ref` to `<Button>`
5. **`ModeHoverGuide`** — wrapped `{children}` in `<span className="inline-flex">` so TooltipTrigger and DropdownMenuTrigger operate on separate DOM elements
6. **`chat-box.tsx`** — fixed `direction` to `orientation` on `<ResizablePanelGroup>` (aligned with upstream)

**Prevention**:
1. **Any component passed as child of Radix `asChild` MUST use `forwardRef`**
2. **NEVER nest two Radix `asChild` triggers on the same element** — insert a wrapper `<span>` between them
3. After pulling upstream, check `react-resizable-panels` API — v4.x uses `orientation` not `direction`
4. This bug also exists in upstream `bytedance/deer-flow` — do NOT revert when pulling upstream

---

### ERROR-069: DeerFlow Agent Non-Functional — Missing `tool_groups`, `tools`, `subagents`, and Model Flags in config.yaml

**Date**: 2026-03-17  
**Severity**: 🔴 CRITICAL — agent stuck in infinite loop, cannot use tools, subagents, or file operations  
**Status**: ✅ RESOLVED  
**File(s) Affected**: `config.yaml`

**Symptom**:
- Agent stuck in `SummarizationMiddleware → model → LoopDetectionMiddleware → TitleMiddleware` loop, never reaching tools or subagents
- `Total tools loaded: 0, built-in tools: 2, MCP tools: 53` — zero file/bash/web tools
- File uploads fail (no sandbox containers running)
- Subagents never execute in parallel
- `sequential-thinking` MCP tool fails with `MCP error -32602: Invalid arguments`

**Root Cause**:
The `config.yaml` was missing four critical sections that the upstream `config.example.yaml` requires:

1. **`tool_groups`** — defines tool permission groups (web, file:read, file:write, bash)
2. **`tools`** — defines the 9 built-in tools (web_search, web_fetch, image_search, ls, read_file, write_file, str_replace, bash)
3. **`subagents`** — configures subagent timeouts for parallel execution
4. **`tool_search`** — deferred tool loading config
5. **Model `supports_thinking` flags** — GLM-5 was missing `supports_thinking: true`, preventing thinking mode

Without `tools`, the agent had NO file operations, NO bash execution, NO web search — only MCP tools. Without `subagents`, parallel execution was unconfigured.

**Fix Applied**:
Added to `config.yaml`:
- `tool_groups`: web, file:read, file:write, bash
- `tools`: 9 tools (web_search, web_fetch, image_search, ls, read_file, write_file, str_replace, bash)
- `tool_search: enabled: false`
- `subagents`: default 900s timeout, general-purpose 1800s, bash 300s
- `supports_thinking: true` on GLM-5 coordinator model
- `supports_vision: true` on MiniMax-M2.5 and Qwen2.5-VL models

**Prevention**:
1. **ALWAYS compare `config.yaml` against upstream `config.example.yaml`** after any config changes — missing sections silently disable features
2. The `tool_groups` + `tools` sections are MANDATORY for the agent to have file/bash/web capabilities
3. After config changes, verify tool count in logs: `grep "Total tools loaded" /app/logs/langgraph.log` — should show 9+ built-in tools
4. Run `make config-upgrade` after pulling upstream to merge new required fields

---

### ERROR-070: Global LoginResponse Binding Hijacks All Filament Panels — Admin Login Redirects to Employee Dashboard

**Date**: 2026-03-17  
**Severity**: 🔴 CRITICAL  
**Status**: ✅ RESOLVED  
**File(s) Affected**: 
- `app/Providers/Filament/EmployeePanelProvider.php`
- `app/Filament/Pages/Auth/Login.php`
- `app/Http/Responses/EmployeeLoginResponse.php`
- `database/seeders/TrainingUsersSeeder.php`

**Symptom**:
1. Logging into `/admin/login` with valid admin credentials successfully authenticates but redirects to `/employee/dashboard` instead of `/admin`
2. Login required clicking "Sign in" twice before it worked (first click seemed to do nothing)
3. After the double-click, 419 CSRF errors appeared briefly before the page cleared and loaded

**Root Cause (Three-Part)**:

**Part 1 — Global LoginResponse Override**: `EmployeePanelProvider::register()` contained:
```php
$this->app->bind(LoginResponse::class, EmployeeLoginResponse::class);
```
This globally bound the `LoginResponse` contract to `EmployeeLoginResponse` for ALL panels, not just employee. When `Login::authenticate()` called `app(LoginResponse::class)`, it received `EmployeeLoginResponse` which always redirected to `/employee/dashboard`.

**Part 2 — Container Resolution in Login**: `Login::authenticate()` returned `app(\Filament\Http\Responses\Auth\Contracts\LoginResponse::class)` which resolved through the container (getting the employee override) instead of using Filament's built-in `new LoginResponse()` which is panel-aware.

**Part 3 — Role Misassignment**: `TrainingUsersSeeder` incorrectly assigned `training_admin` role to Grecia Trabanino, who should be a logistics `admin`.

**Fix Applied**:
1. **Removed global LoginResponse binding** from `EmployeePanelProvider::register()`. The `EmployeeLogin` page already handles its own redirect via `$this->redirect()`.
2. **Changed Login.php** to return `new LoginResponse()` (concrete class) instead of container-resolved `app(LoginResponse::class)`.
3. **Fixed TrainingUsersSeeder** to exclude Grecia from training users.
4. **Created `scripts/fix_auth_and_roles.php`** to correct all user roles on production.

**Prevention**:
1. **NEVER globally bind Filament response contracts** in a panel provider's `register()` — it affects ALL panels
2. **Use `new LoginResponse()`** instead of `app(LoginResponse::class)` in custom login pages to avoid container override conflicts
3. **Each panel's login page should handle its own redirect** without relying on global container bindings
4. Admin users should have `admin` or `super_admin` roles; training users should have `training_admin` role — never mix
5. Test login flows for EACH panel after any auth changes

---

### ERROR-038b: CORRUPTED ENTRY REMOVED (2026-03-18)

> A previous agent injected a fake "Phase 1 forbidden server" error entry containing unrelated Python code (Red5 proxy/KibanaClient). This entry has been removed as it is not a real MBFD Hub error. The real ERROR-038 (Station Inspection API Endpoint Mismatch) remains above.

---

### ERROR-071: Cloudflare Tunnel Docker Compose Misconfiguration

**Date**: 2026-03-18  
**Severity**: 🔴 CRITICAL  
**Status**: ✅ RESOLVED  
**File(s) Affected**: `docker-compose-dev.yaml`

**Symptom**:
Docker Compose fails to start with error: `networks.cloudflared Additional property image is not allowed`.

**Root Cause**:
The `cloudflared` container block was accidentally appended to the end of the file under the `networks:` section instead of the `services:` section.

**Fix Applied**:
Moved the `cloudflared` block under `services:` in `docker-compose-dev.yaml`.

**Prevention**:
1. Always verify the indentation and section placement when appending blocks to YAML files.
2. Run `docker compose config` to validate the YAML structure before starting services.

---

### ERROR-072: CLAUDE.md Catastrophic Corruption — ~800 Lines of Hallucinated Garbage Injected

**Date**: 2026-03-18  
**Severity**: 🔴 CRITICAL  
**Status**: ✅ RESOLVED  
**File(s) Affected**: `CLAUDE.md`

**Symptom**:
`CLAUDE.md` contained ~800 lines of incoherent mixed-language text (Japanese, Spanish, code fragments, variable names) after line 11, replacing all useful AI context documentation. New agents reading this file would receive no actionable project information.

**Root Cause**:
A previous AI agent replaced the structured "Key Files", architecture, and rules sections with hallucinated garbage text. The file was 879 lines, with only the first 8 lines containing real content.

**Fix Applied**:
Deleted the corrupted file and created a clean replacement with:
- Architecture overview (MBFD Hub VPS vs DeerFlow WSL separation)
- Key files table
- DeerFlow 2.0 configuration reference (config_version: 2, module paths, MCP servers)
- Mandatory recovery sequence
- Design system rules
- Filament v3 rules
- Panel and SPA reference tables

**Prevention**:
1. Always verify file content after writes — `wc -l` and visual inspection
2. CLAUDE.md should be version-controlled
3. Any agent writing to CLAUDE.md should preserve existing structured sections

---

### ERROR-073: Supervisord Running Redundantly Alongside Docker restart: unless-stopped

**Date**: 2026-03-18  
**Severity**: 🟡 MEDIUM  
**Status**: ✅ RESOLVED  
**File(s) Affected**: `~/src/deer-flow/supervisord.conf`

**Symptom**:
Two supervisord processes running (PID 2390888 system-level, PID 2416519 user-level in Terminal 3). Supervisord was wrapping `docker compose up` commands, adding unnecessary process management overhead when Docker already handles restart policies.

**Root Cause**:
A previous agent installed supervisord and created `supervisord.conf` to manage DeerFlow container lifecycle. This conflicts with Docker's native `restart: unless-stopped` policy and creates a confusing dual-management layer. When supervisord shuts down, it kills the compose processes it manages, potentially causing container downtime.

**Fix Applied**:
1. Shut down supervisord via `supervisorctl shutdown`
2. Renamed `supervisord.conf` → `supervisord.conf.DECOMMISSIONED`
3. Cleaned up log artifacts (`supervisord.log`, `supervisord.pid`, `deerflow-serves.out`, `deerflow-serves.err`)
4. Restarted all containers via `docker compose up -d` — all 5 containers confirmed running
5. Executed mandatory recovery sequence (permissions + nginx reload + health check)

    **Prevention**:
1. **NEVER use supervisord** to manage Docker containers that have `restart: unless-stopped`
2. Use `docker compose up -d` directly for DeerFlow lifecycle management
3. For persistent startup, use systemd units (see `pgweb.service` model at `/etc/systemd/system/`)

---

### ERROR-074: DeerFlow Nginx Missing Host Port Mapping — localhost:2026 Unreachable from Windows

**Date**: 2026-03-18  
**Severity**: 🔴 CRITICAL  
**Status**: ✅ RESOLVED  
**File(s) Affected**: `~/src/deer-flow/docker/docker-compose-dev.yaml`

**Symptom**:
`http://localhost:2026` returns "Unable to connect" in Firefox. Cloudflare tunnel also returns 502 intermittently.

**Root Cause**:
The nginx service in `docker-compose-dev.yaml` had no `ports:` section. Nginx listened on port 2026 internally but never published it to the host. The cloudflared tunnel worked container-to-container, but local browser access was impossible.

**Fix Applied**:
Added `ports: - "2026:2026"` to the nginx service in `docker-compose-dev.yaml`.

**Prevention**:
1. After any compose file changes, verify port bindings: `docker inspect deer-flow-nginx --format='{{json .HostConfig.PortBindings}}'`
2. The nginx service MUST have `ports: - "2026:2026"` for local access

---

### ERROR-075: WSL2 Virtual Network Broken After Power Loss — Windows Cannot Reach WSL Ports

**Date**: 2026-03-18  
**Severity**: 🔴 CRITICAL  
**Status**: ✅ RESOLVED  

**Symptom**:
After computer power loss/restart, `localhost:2026` and the WSL IP (`172.31.98.76:2026`) are both unreachable from Windows Firefox, even though `curl http://localhost:2026` works from inside WSL.

**Root Cause**:
WSL2 uses a virtual network adapter (Hyper-V vEthernet). After an unclean shutdown (power loss), the WSL2 networking layer can become corrupted — the virtual switch stops forwarding packets between Windows and the WSL2 VM.

**Fix Applied**:
```powershell
wsl --shutdown
# Wait 5 seconds
wsl bash -c "echo restarted"
# Then restart Docker containers
```

**Prevention**:
1. After ANY power loss or unclean shutdown, run `wsl --shutdown` then restart WSL
2. After WSL restart, run the full DeerFlow recovery sequence (docker compose up -d, permissions, nginx reload)
3. Verify from Windows: `powershell -Command "Invoke-WebRequest -Uri 'http://localhost:2026' -TimeoutSec 5"`
