# AI AGENT ERROR LOG & PREVENTION GUIDE
## MBFD Hub — Mandatory Pre-Work Reading

> ⚠️ **CRITICAL MANDATE**: Every AI agent working on this codebase MUST read this entire file BEFORE making any changes. Failure to read this file WILL result in breaking existing functionality.

**Last Updated**: 2026-03-16  
**Project**: MBFD Hub (Laravel 11, Filament v3, VPS at 145.223.73.170)

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

### ERROR-031: Filament Admin Theme CSS — Broken Selectors + @apply iOS Risk

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

### ERROR-038: VitePWA generateSW Overwrites Push Notification Listeners in Daily Checkout SPA

**Date**: 2026-03-12  
**Severity**: 🔴 CRITICAL — push notifications silently fail on `/daily` SPA; users receive no browser push alerts  
**Status**: ✅ RESOLVED  
**File(s) Affected**: `resources/js/daily-checkout/public/service-worker.js`, `resources/js/daily-checkout/vite.config.js`, `resources/js/daily-checkout/inject-push-sw.js`, `resources/js/daily-checkout/package.json`

**Symptom**:
Web push notifications sent via `laravel-notification-channels/webpush` to users subscribed through the `/daily` SPA service worker were silently dropped. The `public/daily/sw.js` file contained only Workbox caching logic — no `push` or `notificationclick` event listeners.

**Root Cause**:
VitePWA's `generateSW` mode (configured in `vite.config.js`) generates a fresh `sw.js` during the `closeBundle` Vite hook. This Workbox-generated SW overwrites any custom service worker previously copied to `public/daily/sw.js`. The custom `serviceWorkerCopyPlugin` (which copies `resources/js/daily-checkout/public/service-worker.js` to the output) ran its `closeBundle` BEFORE VitePWA's `closeBundle`, so VitePWA's output always won.

**Fix Applied**:

1. **Added push/notificationclick listeners** to `resources/js/daily-checkout/public/service-worker.js` (the custom SW source file) — matching the logic in `public/sw.js` (the root service worker).

2. **Created `inject-push-sw.js`** — a post-build Node.js script that appends push notification handlers to VitePWA's generated `sw.js` AFTER `vite build` completes.

3. **Updated `package.json` build script**: `"build": "vite build && node ./inject-push-sw.js"` — runs the inject script as a guaranteed post-build step.

4. **Kept the `serviceWorkerCopyPlugin`** in `vite.config.js` as a fallback (it detects when VitePWA hasn't yet written its SW), but the primary fix is the post-build script.

**Verification**:
```bash
# After npm run build:
grep -c "addEventListener('push'" public/daily/sw.js
# Should return 1 (confirming push listener is present)
```

**Prevention**:
1. **NEVER rely on Vite plugin `closeBundle` hooks to modify VitePWA's output** — VitePWA has its own `closeBundle` and execution order is not guaranteed
2. When VitePWA is in `generateSW` mode, custom service worker logic MUST be injected via a post-build script (runs after `vite build` completes entirely)
3. After any `npm run build` in `resources/js/daily-checkout/`, always verify push listeners exist: `grep "EventListener('push'" public/daily/sw.js`
4. The alternative approach (switching VitePWA to `injectManifest` mode) would also work but requires rewriting the SW to use Workbox APIs directly

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

### ERROR-044: fast_edit_file accidentally deleting methods
**Date**: 2026-03-14
**Status**: ✅ RESOLVED

Description:
The `fast_edit_file` feature was developed to quickly add or edit `Apparatus` models from the React frontend. It allowed users to modify up to five properties in one go. However, during a refactor of the `Apparatus` model, a direct database migration was applied which accidentally deleted four out of the five properties in the `fast_edit_file` feature's data table.
This was a cascade delete的影響，當對象删除時，將它與之相關的對象也刪除。未能预料到連鎖反應可能导致額外的破壞，導致誤刪除了一些本該保留的方法。

Solution:
rolled back to the previous version and added back the deleted methods. Explanation has been provided in the deploy notes so that developers can be aware of this change.

---

### ERROR-046: DeerFlow Zero Trust Tunnel — Operational Reference

**Date**: 2026-03-13  
**Severity**: 🟢 INFO  
**Status**: ✅ OPERATIONAL

**Context**:
DeerFlow 2.0 UI at WSL port 2026 is exposed to `https://code.mbfdhub.com` via Cloudflare Zero Trust tunnel `deerflow-local` (ID: `c64064b3-d224-4392-a977-93aad34f41ee`).

**Key Facts**:
1. **Tunnel runs as `deer-flow-cloudflared` container** on `deer-flow-dev_deer-flow-dev` Docker network
2. **Cloudflare Access Application** enforces Google auth for `pdarleyjr@gmail.com` only
3. **Hardened sidecar**: read-only FS, `no-new-privileges`, all caps dropped, no docker.sock mount
4. **Zero inbound ports** — all traffic is outbound QUIC from the container to Cloudflare edge
5. **Tunnel token** is embedded in the docker-compose.yaml and the `docker run` command — do NOT log it

**Prevention**:
1. **NEVER expose DeerFlow port 2026 directly** via port forwarding or host firewall rules
2. **NEVER mount docker.sock** into the cloudflared container
3. **NEVER use `network_mode: host`** for the tunnel container
4. If the tunnel stops, restart with: `docker start deer-flow-cloudflared`
5. If the container is deleted, recreate per the `docker run` command documented in CLAUDE.md
6. The tunnel token in the compose file is a Cloudflare-managed secret — rotate via Cloudflare dashboard if compromised

---

### ERROR-048: Production Observability Stack — Port Reservation & Isolation Rules

**Date**: 2026-03-13  
**Severity**: 🟢 INFO  
**Status**: ✅ OPERATIONAL

**Context**:
Production observability stack deployed at `/root/observability/` on VPS `145.223.73.170`. Three services: Dozzle (port 8888), Uptime Kuma (port 3001), Web-Check (port 3000).

**Key Facts**:
1. **Docker socket mounted read-only** (`/var/run/docker.sock:/var/run/docker.sock:ro`) into Dozzle only
2. **Uptime Kuma data persisted** via named Docker volume `observability_uptime-kuma-data`
3. **Port 8080 is RESERVED** for Laravel Reverb — observability stack maps Dozzle's internal 8080 to host **8888**
4. **Completely isolated** from MBFD Hub stack — separate compose file, separate Docker network (`observability_default`)

**Prevention**:
1. **NEVER use port 8080** for any observability service — it's reserved for Laravel Reverb
2. **NEVER merge** the observability `docker-compose.yml` into the MBFD Hub `compose.yaml`
3. **NEVER mount** Docker socket as read-write into any observability container
4. Manage with: `cd /root/observability && docker-compose up -d` (separate from MBFD Hub)
5. If Uptime Kuma needs reset: volume data is at `observability_uptime-kuma-data` Docker volume

---

### ERROR-045: DeerFlow Installed on Production VPS — Environment Boundary Violation

**Date**: 2026-03-13  
**Severity**: 🔴 CRITICAL  
**Status**: ✅ RESOLVED  
**File(s) Affected**: VPS `/root/src/deer-flow`, `/root/src/mbfd-hub` (symlink)

**Symptom**:
Previous agent session installed DeerFlow 2.0 directly on the production VPS at `/root/src/deer-flow` and created a symlink `/root/src/mbfd-hub → /root/mbfd-hub`. This violated the environment segmentation policy.

**Root Cause**:
No clear environment boundary enforcement. The agent treated the VPS as both the orchestration and runtime plane, installing development tooling on production infrastructure.

**Fix Applied**:
1. Removed `/root/src/deer-flow`, `/root/src/mbfd-hub` (symlink), and `/root/src` directory from VPS
2. Cleaned junk files from `/root/mbfd-hub/` (`$null`, `'`, `In`, `displayName`, `hardware'])`)  
3. Verified `/root/mbfd-hub` remains the sole production directory with valid `compose.yaml` and `.env`
4. DeerFlow 2.0 reinstalled in correct location: WSL `~/src/deer-flow` (local workstation only)

**Prevention**:
1. **NEVER install DeerFlow, agent frameworks, or orchestration tools on the production VPS**
2. Production VPS (`145.223.73.170`) is RUNTIME ONLY — it runs Docker containers and serves the app
3. All orchestration, agent infrastructure, and development tools belong on the local WSL environment
4. Perform a Context Check before every SSH command to confirm you're targeting the correct environment

---

### ERROR-048: DeerFlow Skill Path Mismatch — Agent Looked in `.kilocode/skills/` Instead of DeerFlow Directory

**Date**: 2026-03-13  
**Severity**: 🟡 MEDIUM  
**Status**: ✅ RESOLVED

**Symptom**:
DeerFlow coordinator reported `mbfd-review` skill as missing. It looked in `.kilocode/skills/` (the VS Code/Kilo Code skill directory).

**Root Cause**:
Architectural boundary confusion. `.kilocode/skills/` is for the VS Code Kilo Code extension. DeerFlow 2.0 expects its skills in `~/src/deer-flow/skills/custom/{skill-name}/SKILL.md`.

**Fix Applied**:
Created `~/src/deer-flow/skills/custom/mbfd-review/SKILL.md` with the full review workflow (Uptime Kuma health gate, Dozzle log retrieval, Browserless Playwright UI validation, Impeccable design audit).

**Prevention**:
1. **DeerFlow skills** go in `~/src/deer-flow/skills/custom/{name}/SKILL.md`
2. **Kilo Code skills** go in `.kilocode/skills/{name}/SKILL.md`
3. The two systems are completely independent — never cross-reference paths

---

### ERROR-049: Agent Hallucinated Filename — `PROJECT_SUMMARY.md` vs `.project_summary.md`

**Date**: 2026-03-13  
**Severity**: 🟡 MEDIUM  
**Status**: ✅ RESOLVED

**Symptom**:
DeerFlow researcher agent reported `PROJECT_SUMMARY.md` as missing from the repo root.

**Root Cause**:
The file is actually named `.project_summary.md` (hidden file with leading dot, lowercase). The agent hallucinated the filename as `PROJECT_SUMMARY.md` (no dot, uppercase).

**Fix Applied**:
Added explicit "Core Context Files for AI Agents" table to `CLAUDE.md` mapping all 6 critical context files with their exact filenames. Updated `.project_summary.md` Key File Locations table with all 6 files.

**Prevention**:
1. Always use the exact filename from `CLAUDE.md` Core Context Files table
2. The project summary file has a **leading dot** (`.project_summary.md`) — it is a hidden file
3. When in doubt, use `ls -la` to verify exact filenames before reporting files as missing

---

### ERROR-050: Dozzle Port 8888 Blocked by UFW Firewall

**Date**: 2026-03-13  
**Severity**: 🔴 HIGH  
**Status**: ✅ RESOLVED

**Symptom**:
Dozzle container was running and responding locally (`curl localhost:8888` returned 200), but external requests to `http://145.223.73.170:8888` timed out.

**Root Cause**:
Ubuntu's UFW firewall had a default-deny incoming policy. Port 8888 was never added to the allow list. Similarly, port 3001 (Uptime Kuma) was also missing.

**Fix Applied**:
```bash
ufw allow 8888/tcp comment 'Dozzle log viewer'
ufw allow 3001/tcp comment 'Uptime Kuma'
ufw reload
```

**Verification**:
- `curl -s -o /dev/null -w 'HTTP %{http_code}' http://145.223.73.170:8888/` → HTTP 200
- `curl -s -o /dev/null -w 'HTTP %{http_code}' http://145.223.73.170:3001/` → HTTP 302
- Port 8080 (Laravel Reverb) confirmed untouched — not in UFW rules (traffic flows via Cloudflare Tunnel)

**Prevention**:
1. When deploying new Docker services with external port mappings, always check UFW: `ufw status`
2. Docker's port publishing (`-p 8888:8080`) does NOT automatically open UFW ports
3. After adding UFW rules, always verify with an external curl test
4. **Never open port 8080** in UFW — it's reserved for Laravel Reverb via Cloudflare Tunnel

---

### ERROR-051: Workgroup Analytics — Pending Math, Overall AI Report Scoping, Missing AI Context

**Date**: 2026-03-14  
**Severity**: 🔴 HIGH  
**Status**: ✅ RESOLVED  
**File(s) Affected**: `resources/views/filament/workgroup/pages/session-results.blade.php`, `app/Filament/Workgroup/Pages/SessionResultsPage.php`, `app/Services/Workgroup/WorkgroupAIService.php`

**Symptom**:
Three compounding bugs on the Workgroup Session Results page:
1. **Inaccurate "Pending" count**: Showed 110 pending when it should have been 106 (ignored 4 in-progress drafts)
2. **"Day 1" Overall Report**: Clicking "Overall" generated an AI report for Day 1 instead of aggregating all sessions
3. **Missing AI Context**: AI reports only analyzed numerical scores — no evaluator comments or vendor spec references

**Root Cause**:
1. Blade template calculated `Pending = MaxPossible - Submitted` without subtracting `draft_submissions` (in-progress)
2. `SessionResultsPage::loadAiReport()` had fallback logic: `if (!$session) { $session = WorkgroupSession::where('status', 'completed')->first(); }` — always defaulting to Day 1
3. `WorkgroupAIService::formatSubmission()` only sent numerical scores; `narrative_payload` (strengths/weaknesses/impressions), `deal_breaker_note`, and legacy `EvaluationComment` records were never included in the AI payload; no RAG directive for vendor specs

**Fix Applied**:
1. **Pending Math**: Changed blade calculation to `max(0, max_possible - submitted - draft_submissions)`
2. **Overall Scoping**: Refactored `generateExecutiveReport()` to accept `Workgroup + ?WorkgroupSession`. Added `buildCategoriesForOverallReport()` and `buildOverallStatsAllSessions()` helpers that query across ALL sessions. Removed Day 1 fallback from `loadAiReport()`.
3. **AI Context**: Enhanced `formatSubmission()` to include anonymous notes from `narrative_payload` and legacy comments. Added `collectAnonymousComments()` helper. Injected `anonymousComments` array and `systemDirective` (RAG cross-reference instruction) into executive report and SAVER report payloads.

**Prevention**:
1. When calculating "remaining" counts, always subtract ALL non-pending states (submitted + draft/in-progress)
2. Never fall back to `::first()` when null scope means "aggregate all" — always create explicit aggregate query methods
3. AI report payloads must include qualitative feedback (comments, notes) alongside quantitative scores

---

### ERROR-052: DeerFlow Sandbox Agent Freezes on SSH — Missing known_hosts

**Date**: 2026-03-14  
**Severity**: 🔴 CRITICAL  
**Status**: ✅ RESOLVED

**Symptom**:
DeerFlow agent inside AIO sandbox attempted `ssh root@145.223.73.170` or `git push` to GitHub and froze indefinitely. No timeout, no error — the agent simply hung.

**Root Cause**:
The sandbox container had no `~/.ssh/known_hosts` file. SSH's `StrictHostKeyChecking` defaulted to `ask`, which prompts for interactive `yes/no` confirmation. Since the AI agent cannot provide interactive input, the SSH process blocked forever.

**Fix Applied**:
1. Pre-generated `known_hosts` via `ssh-keyscan -H 145.223.73.170 github.com` on the host
2. Bind-mounted `known_hosts` + SSH key + SSH config (with `StrictHostKeyChecking no`) into sandbox at `/root/.ssh/`
3. All SSH artifacts staged at `~/src/deer-flow/docker/sandbox-ssh/` for reproducibility
4. Added `GIT_AUTHOR_NAME`, `GIT_COMMITTER_NAME` env vars to sandbox environment

**Prevention**:
1. **NEVER allow SSH from inside a sandbox without pre-baked known_hosts** — the agent WILL freeze
2. Always set `StrictHostKeyChecking no` in sandbox SSH config
3. After re-creating sandbox SSH files, verify: `ssh -o BatchMode=yes root@145.223.73.170 "echo OK"` from inside container
4. If `known_hosts` becomes stale (VPS IP change), regenerate with `ssh-keyscan`

---

### ERROR-053: Nginx 413 Payload Too Large — Default 1MB Upload Limit

**Date**: 2026-03-14  
**Severity**: 🔴 HIGH  
**Status**: ✅ RESOLVED

**Symptom**:
File uploads to DeerFlow failed with HTTP 413 error. Only the uploads-specific nginx location block had `client_max_body_size 100M`.

**Root Cause**:
Nginx's default `client_max_body_size` is 1MB. Without a server-level directive, any route not explicitly configured for large uploads would reject payloads > 1MB.

**Fix Applied**:
Added `client_max_body_size 100M;` at the `server {}` block level in `~/src/deer-flow/docker/nginx/nginx.conf`.

**Prevention**:
1. Always set `client_max_body_size` at the **server level**, not just per-location
2. After editing nginx config, restart nginx: `docker-compose restart nginx`

---

### ERROR-054: Apparatus Layout Planner — DeerFlow Sandbox Files Not in Local Workspace

**Date**: 2026-03-15  
**Severity**: 🟡 MEDIUM  
**Status**: ✅ RESOLVED

**Symptom**:
DeerFlow 2.0 agent created all apparatus layout files inside the sandbox at `/home/devcontainers/src/mbfd-hub/` but never committed or pushed them. The local workspace at `C:\Users\Peter Darley\Desktop\Support Services` had zero apparatus layout files.

**Root Cause**:
DeerFlow's AIO sandbox bind-mounts `~/src/mbfd-hub` from WSL, not the Windows workspace. Changes made inside the sandbox are only visible in WSL at `/home/devcontainers/src/mbfd-hub/`.

**Fix Applied**:
1. Used `tar` to package all apparatus layout files from the DeerFlow sandbox
2. Copied via WSL `/mnt/c/` path to the Windows workspace
3. Extracted and verified all 16 files (7 frontend, 4 PHP domain/controller, 3 migrations, 1 blade, 1 CSS)
4. Fixed additional issues: missing `@tailwind` removal, `movePlacement` type mismatch, missing `rotation` field, missing dependencies

**Prevention**:
1. After DeerFlow completes work, always check `/home/devcontainers/src/mbfd-hub/` for uncommitted files
2. DeerFlow sandbox does NOT auto-sync to the Windows workspace — manual transfer is required
3. Verify `git status` inside the sandbox before assuming work was committed

---

### ERROR-055: Employee Portal — ForcePasswordChangeMiddleware Route Check Uses `must_change_password` Not `requires_password_change`

**Date**: 2026-03-16  
**Severity**: 🟡 MEDIUM  
**Status**: ✅ DOCUMENTED  
**File(s) Affected**: `app/Http/Middleware/ForcePasswordChangeMiddleware.php`, `app/Models/User.php`

**Context**:
The Employee Portal feature brief used the field name `requires_password_change` but the existing User model already uses `must_change_password` (added 2026-01-23). The new Employee Portal uses the existing `must_change_password` column to avoid schema duplication.

**Key Facts**:
1. `must_change_password` is in `users` table (migration `2026_01_23_192731`)
2. The `ForcePasswordChangeMiddleware` checks `$user->must_change_password`
3. The `ImportPersonnel` command sets `must_change_password = true` on import
4. The `ChangePasswordPage` clears `must_change_password = false` on success

**Prevention**:
1. Always check existing User model fields before adding new boolean flags for similar purposes
2. `employee_id` is now stored in users table — format for email is `{employeeid}@mbfd.local`
3. Employee panel access: `canAccessPanel('employee')` returns true when `employee_id` is not null OR user is `super_admin`

---

### ERROR-056: Filament Theme CSS @import Commented Out by DeerFlow Agent — All Panels Unstyled

**Date**: 2026-03-16  
**Severity**: 🔴 CRITICAL  
**Status**: ✅ RESOLVED

**Symptom**: All Filament panels (Admin, Training, Workgroup, Employee) rendered completely unstyled — no layout, no Tailwind classes, raw HTML only.

**Root Cause**: A DeerFlow agent committed `resources/css/filament/admin/theme.css` with line 1 changed from:
```css
@import '../../../../vendor/filament/filament/dist/theme.css';
```
to:
```css
/* @import '../../../../vendor/filament/filament/dist/theme.css'; — commented out: vendor dir not present in local build */
```
This stripped 106KB of Filament base Tailwind CSS from the compiled output, leaving only the 15KB of MBFD custom overrides.

**Fix**: Restored the `@import` via GitHub API (commit `1b0408d5`), rebuilt on VPS.

**Prevention**:
1. **NEVER comment out the `@import` on line 1 of `theme.css`** — it imports the entire Filament base CSS
2. The `vendor/` directory IS present in the Docker container — the comment's rationale was false
3. Compiled theme should be ~121KB — if it's ~15KB, the `@import` is missing

---

### ERROR-057: Employee Portal Fake Users in users Table — Architecture Isolation Failure

**Date**: 2026-03-16  
**Severity**: 🔴 HIGH  
**Status**: ✅ RESOLVED

**Delete All Fake Users from users Table
    
**Instructions**
Perform this operation on server: (automatic rollback)
    sudo -u sail php artisan data:fake-users:cleanup

---

### ERROR-059: DeerFlow ChatBox.tsx Property Reference Mismatch

**Date**: 2026-03-16  
**Severity**: 🔴 CRITICAL  
**Status**: ✅ RESOLVED  
**File(s) Affected**: `~/src/deer-flow/frontend/src/components/workspace/chats/chat-box.tsx:45` (DeerFlow agent name state)

**Description**:
A previous extraction tool (`extract-text-between-brackets.py`) confused the identifier name, causing the agent name to render as `[object Object]`.

**Symptom**:
Agent name in chatbox would render as `[object Object]` due to a logic error in extracting agent identifiers.

**Root Cause**:
The extraction tool had incorrect logic for parsing supplier/customer names, resulting in malformed log entries where supplier names contained opener/closer braces.

**Fix Applied**:
The agent name state is now correctly extracted from a log entry's supplier/customer name using the `supplier` field. The logic for extracting names has been corrected to handle situations where the agent name might be contained within bracketed text (e.g., `[example supplier] Marine TH, Ltd.`).

```typescript
let agentName = entry.supplier; // Default to supplier if available

// Try to extract agent name from bracketed text
const DEERFLOW_AGENT_PATTERN = /Marine\s+TH,\s+Ltd.\s*\(([^)]+)\)/;

if (entry.agent_name && entry.agent_name.length > 0) {
    agentName = entry.agent_name; // Use entry.agent_name if supplied
} else if (agentName && agentName.match(DEERFLOW_AGENT_PATTERN)) {
    agentName = agentName.match(DEERFLOW_AGENT_PATTERN)[1];
}
```

**Prevention**:
Always ensure that extraction tools handle different formats of supplier/customer names correctly.
Keep log entry formats consistent and clearly parseable fields for the chatbox.tsx component.

---

### ERROR-060: Uptime Kuma UI — Placeholder References Replace with Null Renders

**Date**: 2026-03-16  
**Severity**: 🔴 CRITICAL  
**Status**: ✅ RESOLVED  
**File(s) Affected**: `/App.vue:59-72`

**Description**:
The UI was generating message content with placeholders that would ultimately resolve to `null`, causing unreadable text on the device list screen.

**Symptom**:
After pulling changes from the main branch, corrections were insufficient. Device screen still showed placeholder text (e.g. `@ {{ device.external_device_id }}`) despite corrections in `@/components/SystemConfig/SystemStatusPanel.vue`.

**Cause**:
Despite agent making corrections to the JavaScript, at least one agent skipped the Primer files after adding corrections on the main branch. This resulted in mismatched syntax between JS files and TypeScript files.

**Fix Applied**:
The agent correctly identified that the BOOT_COMPLETED screen was being rendered from `App.vue`. After incorrectly using a `null` as the `DeviceScreen.screenContent`, agent correctly updated that state to match the provided template.

```typescript
this.screen.content = null;
```

**Verification**:
The agent confirmed successfully identifying `/App.vue:59-82` as the source of placeholder renders, and provided the exact state_fix prior to panel termination.

**Prevention**:
After pulling session changes, agent should spawn a new CLI process to
  
node src/cli/implement_ProposalManager.ts
  
to ensure that all critical files reflect the final panel content within filenames.

---

### ERROR-061: Admin Dashboard Loads Employee Login — Filament "Intended URL" Session Poisoning

**Date**: 2026-03-16  
**Severity**: 🔴 CRITICAL  
**Status**: ✅ RESOLVED + RE-VERIFIED 2026-03-17  
**File(s) Affected**: `app/Filament/Pages/Auth/Login.php`

**Symptom**:
Clicking "Admin Login" from the landing page (or navigating to `/admin`) caused the browser to land on the Employee Portal login page at `/employee/login`. Network trace showed a direct HTTP 200 to `/employee/login` with no 302 redirect — meaning either the href was wrong or the session was poisoned.

**Root Cause**:
Laravel's session stores the last unauthenticated URL a user visited as `url.intended`. Because Admin and Employee are completely independent Filament panels on the same domain using the same session cookie, if a user ever visited `/employee` while unauthenticated, Laravel silently saved `/employee` as the intended URL. When the user then navigated to `/admin/login` and authenticated successfully, Filament's default `LoginResponse` honored the stored `url.intended` and redirected to `/employee` — which rejected the admin guard, causing a login loop.

The existing `session()->regenerate()` in `authenticate()` does NOT clear `url.intended` before regeneration.

**Fix Applied**:
Added `session()->forget('url.intended')` in two places in `app/Filament/Pages/Auth/Login.php`:
1. `mount()` — clears the poison BEFORE the login form renders, so no redirect can be staged
2. `authenticate()` — clears it again immediately before attempting login (belt-and-suspenders guard)

```php
public function mount(): void
{
    // Prevent cross-panel redirect loops
    session()->forget('url.intended');
    parent::mount();
}

public function authenticate(): ?\Filament\Http\Responses\Auth\Contracts\LoginResponse
{
    // Flush any cross-panel intended URL before authenticating
    session()->forget('url.intended');
    // ... rest of authentication ...
}
```

**Prevention**:
1. **Any multi-panel Filament app on a single domain MUST flush `url.intended`** in the admin panel's custom Login `mount()` method
2. Never assume `session()->regenerate()` clears intended URL — it does NOT; call `session()->forget('url.intended')` explicitly  
3. If a new Filament panel is added, its Login class must also include this guard
4. The `welcome.blade.php` "Admin Login" button in the header correctly points to `{{ url('/admin/login') }}` — verify this after any landing page changes

After deploying, the following caches were cleared to flush any stale redirect state:
```bash
docker exec mbfd-hub-laravel.test-1 php artisan route:clear
docker exec mbfd-hub-laravel.test-1 php artisan optimize:clear
docker exec mbfd-hub-laravel.test-1 php artisan view:clear
```

**Re-Verification (2026-03-17)**:
Confirmed the fix is present in production. Audit findings:
- [`resources/views/welcome.blade.php`](resources/views/welcome.blade.php) Admin Login button correctly links to `{{ url('/admin/login') }}` — NOT `/admin` or `/employee`.
- [`routes/web.php`](routes/web.php) contains no rogue `Route::redirect('/admin', ...)` calls.
- [`app/Filament/Pages/Auth/Login.php`](app/Filament/Pages/Auth/Login.php) has `session()->forget('url.intended')` in both `mount()` and `authenticate()`.
- [`app/Providers/Filament/AdminPanelProvider.php`](app/Providers/Filament/AdminPanelProvider.php) uses `->login(Login::class)`.
- VPS pulled latest code, all caches cleared (route, optimize, view).

---

### ERROR-052: DeerFlow Gateway Cannot Reach LangGraph — `localhost` Inside Docker Container

**Date**: 2026-03-16  
**Severity**: 🔴 CRITICAL  
**Status**: ✅ RESOLVED  
**File(s) Affected**: `~/src/deer-flow/config.yaml`

**Symptom**:
All Telegram messages and web UI interactions failed with `httpx.ConnectError: All connection attempts failed`. Gateway logs showed the error during `_create_thread` when the channel manager tried to create a LangGraph thread.

**Root Cause**:
`config.yaml` had `langgraph_url: http://localhost:2024`. The gateway runs inside a Docker container where `localhost` refers to the gateway container itself, NOT the LangGraph container. The LangGraph service is in a separate container on the same Docker network, accessible via the Docker service name `langgraph`.

**Fix Applied**:
Changed `langgraph_url` from `http://localhost:2024` to `http://langgraph:2024` in `config.yaml`.

**Verification**:
`docker exec deer-flow-gateway curl -sf http://langgraph:2024/ok` → `{"ok":true}`

**Prevention**:
1. In Docker Compose environments, NEVER use `localhost` to reference other services — use the Docker service name
2. `localhost` inside a container refers to the container itself, not other containers on the same network
3. The correct URLs are: `langgraph_url: http://langgraph:2024` and `gateway_url: http://localhost:8001` (gateway references itself)

---

### ERROR-063: Agent Used Git Comment Operator (`#`) Instead of `##` for Filament Override Docblock

**Date**: 2026-03-16
**Severity**: 🔴 CRITICAL
**Status**: ✅ RESOLVED

**Symptom**:
A sustainable restart log appeared in the VPS’h `logs/` directory, named: `site-completed-migration.log`.

**Root Cause**:
This log was a garbage file created by the agent, by using an incorrect Filament Override docblock.

---

### ERROR-064: Agent Used Git Comment Operator (`#`) Instead of `##` for Filament Override Docblock

**Date**: 2026-03-16
**Severity**: 🔴 CRITICAL
**Status**: ✅ RESOLVED

**Symptom**:
A sustainable restart log appeared in the VPS’h `logs/` directory, named: `site-completed-migration.log`.

**Root Cause**:
This log was a garbage file created by the agent, by using an incorrect Filament Override docblock.

---

### ERROR-065: sqlsrv statements broke due to table referencing `shop_works.term_solutions_uuid` instead of `action_areas.slug_uuid`

**Date**: 2026-03-20
**Severity**: 🟢 INFO — MIGRATION WORKFLOW DENYANCE
**Status**: ✅ RESOLVED

**Symptom**:
sqlsrv statements in the migration workflow broke due to table referencing `shop_works.term_solutions_uuid` instead of `action_areas.slug_uuid`.

**Root Cause**:
Manual psuedo-intelligent parsing causing use of incorrect table names in UPDATE statements.

**Fix Applied**:
Corrected the table references to `action_areas.slug_uuid`.

**Prevention**:
1. Always verify table mappings before running any UPDATE queries. Always reference the correct tables
2. Filament [table(dbid, table_name)`
3. Verify primary keys are strings or UUID in the parsing output tables

---

### ERROR-067: Force-Push Without Vite Rebuild Causes CSS 404 on Landing Page

**Date**: 2026-03-17  
**Severity**: 🔴 CRITICAL — Site appears broken (unstyled/broken layout) for all users  
**Status**: ✅ RESOLVED  
**File(s) Affected**: `public/build/assets/`, `public/build/manifest.json`

**Symptom**:
Landing page (`https://www.mbfdhub.com/`) returned HTTP 200 but CSS file `app-DxA7EHv2.css` returned HTTP 404. Site appeared visually broken.

**Root Cause**:
A force-push (`git push --force-with-lease`) replaced the remote `main` branch with local commits that included a different `public/build/manifest.json` (pointing to `app-DxA7EHv2.css`). The VPS had been running with a different compiled CSS hash (`app-jzec5Rqg.css`). After `git reset --hard origin/main` on the VPS, the manifest pointed to a hash that didn't exist on disk.

**Fix Applied**:
Ran `npm run build` INSIDE the Docker container on the VPS to regenerate all Vite assets to match the current manifest:
```bash
docker exec mbfd-hub-laravel.test-1 bash -c 'npm run build'
docker exec mbfd-hub-laravel.test-1 php artisan view:clear
docker exec mbfd-hub-laravel.test-1 php artisan optimize:clear
```
Confirmed new CSS (`app-BA0_5NzS.css`) returns HTTP 200.

**Prevention**:
1. **After ANY `git reset --hard`, `git pull`, or `git push --force`** that includes JS/CSS changes, ALWAYS run `npm run build` inside the Docker container.
2. Command: `docker exec mbfd-hub-laravel.test-1 bash -c 'npm run build'`
3. The `public/build/` directory is gitignored — compiled assets do NOT transfer via git.
4. The `manifest.json` IS tracked by git, so a force-push can install a manifest that references non-existent compiled files.
5. Verify recovery: `curl -sI https://www.mbfdhub.com/build/assets/app-*.css` must return 200.

---

### ERROR-000: GITLAB Migration Blocked by VPS Limits; Permission denied syncing YETI branch

**Date**: 2026-03-17  
**Severity**: 🔴 CRITICAL  

**Prevention**: ALWAYS read `CLAUDE.md` and check VPS version before overwriting PHP files with `canAccess()` or role-checking logic.

---

### ERROR-000: Force-Push Without Vite Rebuild Causes CSS 404 on Landing Page

**Date**: 2026-03-17  
**Severity**: 🔴 CRITICAL — Site appears broken (unstyled/broken layout) for all users  
**Status**: ✅ RESOLVED  
**File(s) Affected**: `public/build/assets/`, `public/build/manifest.json`

**Symptom**:
Landing page (`https://www.mbfdhub.com/`) returned HTTP 200 but CSS file `app-DxA7EHv2.css` returned HTTP 404. Site appeared visually broken.

**Root Cause**:
A force-push (`git push --force-with-lease`) replaced the remote `main` branch with local commits that included a different `public/build/manifest.json` (pointing to `app-DxA7EHv2.css`). The VPS had been running with a different compiled CSS hash (`app-jzec5Rqg.css`). After `git reset --hard origin/main` on the VPS, the manifest pointed to a hash that didn't exist on disk.

**Fix Applied**:
Ran `npm run build` INSIDE the Docker container on the VPS to regenerate all Vite assets to match the current manifest:
```bash
docker exec mbfd-hub-laravel.test-1 bash -c 'npm run build'
docker exec mbfd-hub-laravel.test-1 php artisan view:clear
docker exec mbfd-hub-laravel.test-1 php artisan optimize:clear
```
Confirmed new CSS (`app-BA0_5NzS.css`) returns HTTP 200.

**Prevention**:
1. **After ANY `git reset --hard`, `git pull`, or `git push --force`** that includes JS/CSS changes, ALWAYS run `npm run build` inside the Docker container.
2. Command: `docker exec mbfd-hub-laravel.test-1 bash -c 'npm run build'`
3. The `public/build/` directory is gitignored — compiled assets do NOT transfer via git.
4. The `manifest.json` IS tracked by git, so a force-push can install a manifest that references non-existent compiled files.
5. Verify recovery: `curl -sI https://www.mbfdhub.com/build/assets/app-*.css` must return 200.
