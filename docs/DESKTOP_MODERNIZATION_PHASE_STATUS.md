# MBFDHUB Desktop Modernization — Phase Status

**Last updated:** 2026-05-14 (post-deploy diagnostic + post-mortem)
**Tracking branch:** `main`
**Working directory:** `d:\GitHub_Repos\MBFD_Hub`

## Production Deploy Log

| Commit | Time (UTC) | Result | Notes |
|---|---|---|---|
| `a01b1eba` | 2026-05-14 17:42 | ⚠ deploy green, `/admin/login` 500 | Full feature push; broke admin panel — see post-mortem |
| `bca9d83a` | 2026-05-14 18:11 | ✅ recovered | Hotfix: reverted Spotlight + new renderHooks |
| `530db143` | 2026-05-14 18:27 | ✅ all 200 + smoke green | Bisect 1: re-added `globalSearchKeyBindings` only — SAFE |
| `8efb3ef9` | 2026-05-14 18:30 | ✅ all 200 + smoke green | Bisect 2: re-added BODY_END renderHook + safeRender + 4 partials — SAFE |
| `(this commit)` | 2026-05-14 | pending | Defensive fix: null-safe `auth()->user()` checks across 10 Workgroup files (root cause cleanup) |

**Current production state (post-bisect):** 4 of 5 features deployed safely.
- ✅ Phase 1 — Foundation (Redis, /up, env, cleanup, Playwright, Lighthouse-CI)
- ✅ Phase 2 — Desktop UX (EnterpriseTable, keyboard partial, status-bar partial, Tailwind variants, global search Cmd+K via `globalSearchKeyBindings`)
- ✅ Phase 3 — Admin PWA (manifest, SW, install prompt, prefetch, head-pwa partial)
- ✅ Phase 4 — Workspace density (context menu partial, tnum, 2xl tightening)
- ⏸ SpotlightPlugin (`pxlrbt/filament-spotlight`) — **deferred indefinitely**, see Post-Mortem below

## Post-Mortem: `/admin/login` 500 in commit a01b1eba

### Symptom
After deploying `a01b1eba`, the post-deploy smoke test reported `Admin login → HTTP 500 (expected 200)`. The 9 other endpoints (landing, daily, SWs, manifests, /up, Baserow, Snipe-IT, admin-pwa manifest, admin-pwa SW) all returned 200. The PWA infrastructure shipped correctly — the bug was specifically in Filament's admin panel render path.

### Root cause
The `pxlrbt/filament-spotlight` plugin's boot logic (`SpotlightPlugin::boot()` → `Filament::serving(...)` → `RegisterResources::boot($panel)`) **eagerly enumerates every resource the panel has discovered** so it can build search/jump entries for the Cmd+K palette. To decide whether to expose a resource, it calls `Resource::canViewAny()`.

The admin panel's `discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')` walks the entire `app/Filament/Resources/` tree, which includes `app/Filament/Resources/Workgroup/*.php`. Those workgroup resources have `canViewAny()` methods like:

```php
public static function canViewAny(): bool
{
    return auth()->user()->hasAnyRole(['super_admin', 'admin', 'logistics_admin']);
}
```

On the **unauthenticated `/admin/login` route**, `auth()->user()` returns `null` — so `null->hasAnyRole(...)` throws `Error: Call to a member function hasAnyRole() on null`, surfacing as a Filament `ViewException` at the SpotlightPlugin's render layer.

The bug was **dormant** before SpotlightPlugin was added because Filament's normal authorization layer only calls `canViewAny()` *after* the user is authenticated, when they navigate to a resource page. SpotlightPlugin breaks that assumption by forcing eager enumeration at panel boot.

### Why bisect-1 (`globalSearchKeyBindings`) and bisect-2 (BODY_END renderHook) were safe
Neither feature touches resource enumeration. They are purely cosmetic / UI-layer changes. They proved themselves green in production.

### Why local boot tests didn't catch it
Local `php artisan filament:upgrade` and `route:cache`/`view:cache`/`config:cache` all booted cleanly — those commands run as the artisan user with no HTTP request. The bug only manifests on an *unauthenticated request to admin/login*. A real HTTP smoke test (`tests/http-smoke.php` style) would have caught it; PR CI does not currently run authenticated browser tests against a fresh container.

### Defensive fix (this commit)
Replaced every `auth()->user()->hasAnyRole(...)` / `hasRole(...)` / `can(...)` call across **10 files in `app/Filament/Resources/Workgroup/`** with the null-safe canonical idiom `(auth()->user()?->hasAnyRole(...) ?? false)`. This fixes the bug class regardless of whether SpotlightPlugin is re-enabled in the future.

### SpotlightPlugin re-introduction policy
With the null-safety bug fixed, SpotlightPlugin would theoretically work. **However**:
- Filament 3 already supports Cmd+K via `globalSearchKeyBindings(['command+k','ctrl+k'])` — already deployed in commit `530db143` and confirmed green
- The visual difference between Filament's native global search and Spotlight's palette is minor
- Spotlight introduces a Livewire component dependency (`livewire-ui-spotlight`) that requires additional config publishing for full theme integration
- The marginal UX benefit does not justify the deployment risk

**Decision: Keep `pxlrbt/filament-spotlight` in composer.lock (already vendored) but do NOT register the plugin in any panel.** Cmd+K opens Filament's native global search — same keyboard ergonomics, less risk.

### Followups identified
- Add a real authenticated HTTP smoke test to the Playwright `admin-pwa-desktop` project so the next time a pre-existing bug surfaces, it's caught in CI before reaching production
- Audit other panel providers (Workgroup, Training, Employee) for the same null-safety anti-pattern in case future packages also enumerate resources eagerly

## Followup status (2026-05-14, this commit)

| Followup | Status | Where |
|---|---|---|
| Apply `EnterpriseTable` trait to top 15 resources | ✅ shipped | 15 files in `app/Filament/Resources/*.php` |
| `/api/admin/lookups/{stations,apparatus,personnel}` endpoints | ✅ shipped | `app/Http/Controllers/Api/Admin/LookupController.php` + `routes/api.php` |
| Authenticated Playwright smoke for `admin-pwa-desktop` project | ✅ shipped | `tests/e2e/admin-pwa.spec.ts` — 3 unauth + 5 auth + 4 lookup tests |
| Redis production env-flip | ⏸ runbook + readiness probe shipped | `php artisan mbfd:activate-redis --dry-run` + [docs/REDIS_ACTIVATION.md](REDIS_ACTIVATION.md) — manual SSH cutover by operator |
| CSP report-only → enforcing | ⏸ deferred | Wait for clean-week metric per `app/Http/Middleware/SecurityHeaders.php:35` |
| R2 disk activation | ⏸ deferred | `config/filesystems.php` ready; needs R2_* env vars |

### What the lookup endpoints unblock

The Dexie prefetch (`resources/js/admin-pwa/prefetch.ts`) silently fails
on production today because the endpoints don't exist — that was an
acceptable degradation: the PWA installs fine, the admin shell works,
the prefetch just returns nothing into IndexedDB. With this commit:

- `GET /api/admin/lookups/stations` → `{ data: [{id, label, station_number, name, address, is_active}, ...], meta: {...} }`
- `GET /api/admin/lookups/apparatus` → `{ data: [{id, label, unit_id, vehicle_number, designation, type, status, station_id}, ...], meta: {...} }`
- `GET /api/admin/lookups/personnel` → `{ data: [{id, label, name, rank, assignment?, employee_id?}, ...], meta: {...} }`
   (Falls back to the `personnel` table if the Snipe-IT integration has populated it; otherwise reads from `employees`.)

All three endpoints:
- Require `web` + `auth` middleware (Filament admin cookie session — same auth the browser admin uses)
- Require the calling user to have `super_admin` or `admin` role
- Are throttled at 60 req/min per IP
- Support `?q=<term>` for cheap server-side filtering
- Cap results at 500 rows to bound IndexedDB size
- Cache the response 5 minutes via `Cache::remember()` (huge speedup once Redis is active)

### EnterpriseTable trait application

The trait is applied to:
- ApparatusResource, Under25kProjectResource, EquipmentItemResource
- CapitalProjectResource, RecommendationResource, StationInspectionResource
- StationResource, EmployeeEquipmentRequestResource, FireEquipmentRequestResource
- DefectResource, EmployeeResource, SingleGasMeterResource
- UnitMasterVehicleResource, UserResource, InspectionResource

Each resource now ships with: striped rows, default page size 50,
options up to 250, extreme pagination links, persisted filters/search/sort
across navigations, and deferred initial load. The five smaller resources
(InventoryLocationResource, ShopWorkResource, InventoryItemResource,
TodoResource, UniformResource) were intentionally left on Filament
defaults — they're low-volume tables where the trait's overhead isn't
worth it.

### Redis activation — operator runbook

See [docs/REDIS_ACTIVATION.md](REDIS_ACTIVATION.md) for the full procedure.
Quick summary:

```bash
ssh deploy-target
docker exec mbfd-hub-laravel php artisan mbfd:activate-redis --dry-run
# If green: edit /opt/mbfd/mbfd-hub/.env per the runbook + config:cache + queue:restart
```

The `mbfd:activate-redis` command is a **read-only readiness probe** —
it does not modify `.env` and does not require a maintenance window.
Run it any time to verify Redis connectivity from inside the Laravel
container.

This document is the single source of truth for what has been scaffolded
in-repo versus what still requires production access (deployment, secrets,
or live infrastructure) to complete.

---

## Mobile/Tablet Safety Invariant

Every change in this initiative is **additive and gated**:

| Gate | Scope |
|---|---|
| `@media (min-width: 1280px) and (pointer: fine)` (Tailwind `desktop:` variant + JS `matchMedia`) | Desktop UX layer, install prompt, context menu, keyboard shortcuts |
| `@media (display-mode: standalone), (display-mode: window-controls-overlay)` (Tailwind `pwa:` variant + body class) | Window-controls-overlay, status bar density, native PWA chrome |
| Service Worker `scope: '/admin/'` | All PWA caching — physically cannot intercept fetches outside `/admin/*` |

**Verification:** [tests/e2e/regression-non-admin.spec.ts](../tests/e2e/regression-non-admin.spec.ts)
runs every PR under three viewports (iPhone 13, iPad Gen 7, 1920×1080
desktop) and asserts that the install banner never appears on mobile/tablet
and the admin service worker is never registered outside `/admin/*`.

---

## Phase 1 — Foundation Activation ✅ CODE COMPLETE

| Item | Status | File(s) |
|---|---|---|
| Redis service added to prod compose | ✅ scaffolded | [compose.prod.yaml](../compose.prod.yaml) — `redis` service with AOF, LRU eviction, password gate, healthcheck |
| `/up` healthcheck route | ✅ already exists | [bootstrap/app.php:13](../bootstrap/app.php#L13) — Laravel 11 default |
| Container HEALTHCHECK uses `/up` | ✅ flipped | [compose.prod.yaml](../compose.prod.yaml) — `curl -f http://localhost:80/up` |
| `.env.example` documents Redis-active production overrides | ✅ | [.env.example](../.env.example) — commented production block at end of Redis section |
| `old_theme.css` removed | ✅ | deleted |
| `.bak` / `.disabled` artifacts archived | ✅ | moved to [backups/legacy-files-2026-05-14/](../backups/legacy-files-2026-05-14/) |
| Playwright 3-viewport regression matrix | ✅ | [playwright.config.ts](../playwright.config.ts) — `regression-mobile`, `regression-tablet`, `regression-desktop`, `admin-pwa-desktop` projects |
| Regression smoke spec | ✅ | [tests/e2e/regression-non-admin.spec.ts](../tests/e2e/regression-non-admin.spec.ts) |
| Admin PWA smoke spec | ✅ | [tests/e2e/admin-pwa.spec.ts](../tests/e2e/admin-pwa.spec.ts) |
| Lighthouse-CI config | ✅ | [.lighthouserc.cjs](../.lighthouserc.cjs) |
| Lighthouse-CI GitHub workflow | ✅ | [.github/workflows/lighthouse-ci.yml](../.github/workflows/lighthouse-ci.yml) |

### Phase 1 — Manual production actions still required

1. **Flip the production `.env`** to activate Redis. Add to `.env`:
   ```env
   CACHE_STORE=redis
   QUEUE_CONNECTION=redis
   SESSION_DRIVER=redis
   BROADCAST_DRIVER=reverb
   BROADCAST_CONNECTION=reverb
   REDIS_HOST=redis
   REDIS_PASSWORD=<strong-secret-generated-fresh>
   ```
2. **Restart the stack** via `docker compose -f compose.prod.yaml up -d`.
   The new `redis` service will start first; `laravel.test` will wait on
   `redis: service_healthy` per the `depends_on` block.
3. **Capture Lighthouse baseline** by running `lhci autorun --config=.lighthouserc.cjs`
   from a workstation with the admin panel reachable. Paste numbers into
   the table in Section 8 of the proposal.
4. **Verify `/up`** returns 200 from inside the container:
   `docker exec mbfd-hub-laravel curl -f http://localhost:80/up`

---

## Phase 2 — Desktop UX Layer ✅ CODE COMPLETE

| Item | Status | File(s) |
|---|---|---|
| `pxlrbt/filament-spotlight` added to `composer.json` | ✅ | [composer.json](../composer.json) |
| SpotlightPlugin wired in admin panel | ✅ | [AdminPanelProvider.php](../app/Providers/Filament/AdminPanelProvider.php) — `->plugin(SpotlightPlugin::make())` |
| Global search enabled with Cmd+K binding | ✅ | [AdminPanelProvider.php](../app/Providers/Filament/AdminPanelProvider.php) — `->globalSearch()->globalSearchKeyBindings(['command+k', 'ctrl+k'])` |
| EnterpriseTable trait | ✅ | [app/Filament/Concerns/EnterpriseTable.php](../app/Filament/Concerns/EnterpriseTable.php) |
| Keyboard shortcuts (g a/g s/g e/g d/c/j/k/?/Esc) | ✅ | [resources/views/filament/admin/partials/keyboard-shortcuts.blade.php](../resources/views/filament/admin/partials/keyboard-shortcuts.blade.php) |
| Tailwind `pwa:`, `desktop:`, `desktop-pwa:` variants | ✅ | [tailwind.config.js](../tailwind.config.js) — plugin block at bottom |
| `tnum` + WCO utility classes | ✅ | [tailwind.config.js](../tailwind.config.js) |
| MBFD dark palette tokens | ✅ | [tailwind.config.js](../tailwind.config.js) — `colors.mbfd.*` |
| Status bar (WS state · queue · user · env) | ✅ | [resources/views/filament/admin/partials/status-bar.blade.php](../resources/views/filament/admin/partials/status-bar.blade.php) |
| All four BODY_END partials composed via render hook | ✅ | [AdminPanelProvider.php](../app/Providers/Filament/AdminPanelProvider.php) — `PanelsRenderHook::BODY_END` |

### Phase 2 — Manual actions still required

1. **`composer install`** to actually pull `pxlrbt/filament-spotlight`.
2. **`php artisan filament:cache-components`** after install.
3. **`npm run build`** to compile the new Tailwind variants.
4. **(Optional)** Apply `use EnterpriseTable;` to the top 15 most-used
   Resource classes (Apparatus, Station, Employee, etc.) — leaving the rest
   on Filament defaults is intentional so we can A/B compare table feel.

### Phase 2 — Apply EnterpriseTable to a resource (one-line change)

```php
use App\Filament\Concerns\EnterpriseTable;

class ApparatusResource extends Resource
{
    use EnterpriseTable;

    public static function table(Table $table): Table
    {
        return self::applyEnterpriseDefaults($table)
            ->columns([ ... ])
            ->filters([ ... ]);
    }
}
```

---

## Phase 3 — Desktop PWA ✅ CODE COMPLETE

| Item | Status | File(s) |
|---|---|---|
| `/admin-pwa/manifest.webmanifest` (scope `/admin/`, WCO display, shortcuts) | ✅ | [public/admin-pwa/manifest.webmanifest](../public/admin-pwa/manifest.webmanifest) |
| `/admin-pwa/service-worker.js` (network-first nav, SWR JSON, cache-first static, webpush hooks) | ✅ | [public/admin-pwa/service-worker.js](../public/admin-pwa/service-worker.js) |
| PWA icons (96/192/512) copied to admin-pwa scope | ✅ | [public/admin-pwa/icons/](../public/admin-pwa/icons/) |
| Install-prompt JS (matchMedia gated, 24h dismissal cooldown) | ✅ | [resources/js/admin-pwa/main.ts](../resources/js/admin-pwa/main.ts) |
| Dexie prefetch for Stations / Apparatus / Personnel | ✅ | [resources/js/admin-pwa/prefetch.ts](../resources/js/admin-pwa/prefetch.ts) |
| Head partial (manifest link + theme-color + Vite entry) | ✅ | [resources/views/filament/admin/partials/head-pwa.blade.php](../resources/views/filament/admin/partials/head-pwa.blade.php) |
| Standalone-mode CSS (tnum, density, WCO, dark) | ✅ | appended to [resources/css/filament/admin/theme.css](../resources/css/filament/admin/theme.css) — "ENTERPRISE DESKTOP LAYER" block |
| Laravel routes for manifest + SW (correct Content-Type + Service-Worker-Allowed) | ✅ | [routes/web.php](../routes/web.php) |
| Vite entry registered | ✅ | [vite.config.js](../vite.config.js) — `resources/js/admin-pwa/main.ts` |

### Phase 3 — Manual actions still required

1. **`npm i`** then `npm run build` — Dexie is already a dependency.
2. **Verify Lookup APIs exist.** The prefetch script assumes:
   - `GET /api/admin/lookups/stations`
   - `GET /api/admin/lookups/apparatus`
   - `GET /api/admin/lookups/personnel`
   If these routes don't exist yet, the prefetch will silently fail (correct
   behavior — the rest of the PWA is unaffected). To enable the perceived-latency win,
   add the three endpoints. Each is a 4-line controller method.
3. **Promote CSP from report-only to enforcing** (1-week clean window per
   [SecurityHeaders.php:35](../app/Http/Middleware/SecurityHeaders.php#L35)).
   After that grace period: change `Content-Security-Policy-Report-Only`
   to `Content-Security-Policy` at line 56.
4. **Pin desktop icon to taskbar / dock** — verified by an admin user
   on each major OS (Windows 10/11, macOS, Ubuntu+Chrome).

### Phase 3 — Kill switch

If anything goes wrong:
- Comment out the two `/admin-pwa/*` routes in [routes/web.php](../routes/web.php). Browsers will fail to fetch the manifest and SW; existing installations stay frozen but new ones cannot be created.
- For a hard reset of existing installs: replace the contents of [public/admin-pwa/service-worker.js](../public/admin-pwa/service-worker.js) with `self.addEventListener('install',()=>self.skipWaiting());self.addEventListener('activate',()=>{self.registration.unregister();});` and deploy. All clients will self-unregister on next visit.

---

## Phase 4 — Workspace Density ✅ CODE COMPLETE (scoped)

| Item | Status | File(s) |
|---|---|---|
| Right-click context menu | ✅ | [resources/views/filament/admin/partials/context-menu.blade.php](../resources/views/filament/admin/partials/context-menu.blade.php) — composes into BODY_END |
| Pop-out (multi-window) via context menu | ✅ | wired in same partial via `window.open('...', '_blank', 'popup,...')` |
| `tnum` numeric column tabular figures | ✅ | theme.css "ENTERPRISE DESKTOP LAYER" block |
| 2xl density (line-height, padding) | ✅ | theme.css "ENTERPRISE DESKTOP LAYER" block |
| Status bar bottom padding reservation | ✅ | theme.css |

### Phase 4 — Deferred (requires UX design + user testing)

- **3-pane Filament Split layout** for Apparatus, Station Operations Hub,
  and Workgroup Evaluations. This is mentioned in §6.3 of the proposal as
  the biggest "feels like Linear/Outlook" upgrade but needs the design
  team's involvement to choose pane proportions and the right Filament
  Split component config per page. Recommended approach: feature-flag
  the new layout (`config('features.split_pane_admin')`) and roll out one
  page at a time.

---

## Phase 5 — Optional Future Work

### 5.1 Cloudflare R2 disk

Scaffolded as commented config block in this commit at the bottom of
[config/filesystems.php](../config/filesystems.php). To activate:

1. Add to production `.env`:
   ```env
   R2_ACCESS_KEY_ID=...
   R2_SECRET_ACCESS_KEY=...
   R2_BUCKET=mbfdhub-media
   R2_ENDPOINT=https://<account>.r2.cloudflarestorage.com
   FILESYSTEM_DISK=r2   # only after the migration below
   ```
2. Migrate Spatie Media Library data:
   ```bash
   php artisan media-library:regenerate --disk-from=local --disk-to=r2
   ```
3. Verify a sample of files load before flipping `FILESYSTEM_DISK`.

### 5.2 Coolify migration (deferred — high risk vs benefit)

The current Sail-based prod stack works. Recommended fallback if Coolify
adoption is rejected: add Watchtower to the prod compose for automatic
zero-downtime restarts when an image tag updates. Watchtower is one
service block in [compose.prod.yaml](../compose.prod.yaml).

### 5.3 Filament v3 → v5 upgrade

**Blocked / out of scope.** Filament's current public major is v3. v5 is
aspirational in the reference doc. Re-evaluate when Filament v5 ships
publicly. Phases 1–4 already deliver the enterprise feel — v5 is not on
the critical path.

### 5.4 Tailwind v4

Same as Filament v5 — re-evaluate when stable. Tailwind v4 changes the
config format (`@config` directives in CSS) and the upgrade has a
non-trivial blast radius for the 1,200+ line custom theme.

---

## Verification Checklist (run after pulling this branch)

```bash
# 1. PHP syntax
vendor/bin/pint --test
vendor/bin/phpstan analyse --no-progress

# 2. Composer install (pulls pxlrbt/filament-spotlight)
composer install

# 3. Build assets
npm ci
npm run build

# 4. Laravel boot test
php artisan filament:upgrade
php artisan optimize:clear
php artisan config:cache

# 5. Run regression matrix (requires running server)
php artisan serve --port=8000 &
npx playwright test --project=regression-mobile --project=regression-tablet --project=regression-desktop
npx playwright test --project=admin-pwa-desktop

# 6. Lighthouse baseline
npx -p @lhci/cli@0.13.x lhci autorun --config=./.lighthouserc.cjs
```

---

## Files Created / Modified Summary

### New files (15)
- `app/Filament/Concerns/EnterpriseTable.php`
- `public/admin-pwa/manifest.webmanifest`
- `public/admin-pwa/service-worker.js`
- `public/admin-pwa/icons/icon-{96,192,512}.png` (copied from existing)
- `resources/js/admin-pwa/main.ts`
- `resources/js/admin-pwa/prefetch.ts`
- `resources/views/filament/admin/partials/head-pwa.blade.php`
- `resources/views/filament/admin/partials/keyboard-shortcuts.blade.php`
- `resources/views/filament/admin/partials/status-bar.blade.php`
- `resources/views/filament/admin/partials/context-menu.blade.php`
- `resources/views/filament/admin/partials/install-prompt.blade.php`
- `tests/e2e/regression-non-admin.spec.ts`
- `tests/e2e/admin-pwa.spec.ts`
- `.lighthouserc.cjs`
- `.github/workflows/lighthouse-ci.yml`
- `docs/DESKTOP_MODERNIZATION_PHASE_STATUS.md` (this file)

### Modified files (8)
- `app/Providers/Filament/AdminPanelProvider.php` — Spotlight, globalSearch, render hooks
- `composer.json` — added pxlrbt/filament-spotlight
- `compose.prod.yaml` — added redis service, flipped HEALTHCHECK to /up
- `.env.example` — documented Redis production overrides
- `playwright.config.ts` — added regression matrix + admin-pwa projects
- `tailwind.config.js` — added pwa/desktop/desktop-pwa variants, tnum utilities, MBFD dark palette
- `vite.config.js` — added admin-pwa entry
- `routes/web.php` — added /admin-pwa/* routes
- `resources/css/filament/admin/theme.css` — appended ENTERPRISE DESKTOP LAYER block

### Removed / archived
- `old_theme.css` — deleted (was unused per audit)
- `ApparatusResource.php.bak`, `TodoResource.php.disabled`, `compose.yaml.backup`, `compose.yaml.backup2` — moved to `backups/legacy-files-2026-05-14/`
