# AI AGENT ERROR LOG & PREVENTION GUIDE
## MBFD Hub — Mandatory Pre-Work Reading

> ⚠️ **CRITICAL MANDATE**: Every AI agent working on this codebase MUST read this entire file BEFORE making any changes. Failure to read this file WILL result in breaking existing functionality.

**Last Updated**: 2026-03-12  
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
- Never use `x-filament::card.heading` or `x-filament::card.content`

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

### ERROR-033: Phase 1 Impeccable Design System

**Date**: 2026-03-11  
**Severity**: 🟢 INFO  

Design modernization. No `@apply`, no bouncy easing, warm stone neutrals only.

---

### ERROR-034: Unified Filament Theme Pipeline

**Date**: 2026-03-11  
**Severity**: 🔴 CRITICAL  

All 3 panels use `->viteTheme('resources/css/filament/admin/theme.css')` with `@import` of Filament dist CSS. Font: Plus Jakarta Sans.

---

### ERROR-035: Station Inspection & Fire Equipment Request Forms — Hallucinated Data

**Date**: 2026-03-11  
**Severity**: 🔴 CRITICAL  

Forms contained hallucinated data. MBFD stations: 1, 2, 3, 4, 6 (NO Station 5). Always consult actual PDF forms.

---

### ERROR-036: VitePWA generateSW Overwrites Push Notification Listeners in Daily Checkout SPA

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
3. After any `npm run build` in `resources/js/daily-checkout/`, always verify push listeners exist: `grep "addEventListener('push'" public/daily/sw.js`
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
Ran `docker compose exec laravel.test npm run build` directly on the VPS to recompile the Filament theme. Confirmed 11 build artifacts generated including `theme-B-aUFWYd.css` at 121.40 KB. Cleared all caches with `optimize:clear`.

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
docker compose exec -u root laravel.test chmod -R 777 storage bootstrap/cache
```

**Prevention**:
1. After ANY container recreation, always run: `docker compose exec -u root laravel.test chmod -R 777 storage bootstrap/cache`
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
docker compose exec laravel.test bash -c 'npm install && npm run build'
```

**Prevention**:
1. **ALWAYS run `npm run build` INSIDE the Docker container**, never on the host
2. Command: `docker compose exec laravel.test bash -c 'npm install && npm run build'`
3. After major changes, recreate containers: `docker compose down && docker compose up -d`

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
This was a cascade delete的影响，当对象删除时，将它与之相关的对象也删除。未能预料到连锁反应可能导致额外的破坏，导致误删除了本该保留的方法。

Solution:
rolled back to the previous version and added back the deleted methods. Explanation has been provided in the deploy notes so that developers can be aware of this change.
