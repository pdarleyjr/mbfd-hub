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

### ERROR-037: (Reserved for future entries)
