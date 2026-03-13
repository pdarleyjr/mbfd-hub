# CLAUDE.md â MBFD Hub AI Context

> **Mission Status: ✅ Production** (2026-03-12)  
> NocoBase has been **decommissioned** (2026-03-08) â container stopped, image removed, volume deleted. All Nocobase scripts removed from repo.  
> ✅ **Chatify real-time chat FIXED** (2026-03-09 evening) â Split-brain config resolved; backend uses internal Reverb (127.0.0.1:8080), frontend uses public wss:// via Cloudflare.  
> ✅ **Daily Vehicle Inspections revived** (2026-03-09 late evening) â MBFD Forms now includes a dedicated Vehicle Inspections card, historical inspections render in a branded admin results viewer, checklist payloads are normalized for React, and the daily SPA now ships with updated service-worker cache busting plus custom `artisan serve` router handling for `/daily/*` routes.  
> ✅ **Vehicle Inspection Checklists FIXED + ICS-212 Features** (2026-03-10) â Checklist pathing bug resolved (storage/checklists â storage/app/checklists), ladder type detection fixed to use designation, digital officer signatures added via react-signature-canvas, automated HOLD logic sets apparatus "Out of Service" on critical defects.
> ✅ **Impeccable Design System Installed** (2026-03-10) â All 17 Impeccable skills installed to `.kilocode/skills/`, 7 domain reference files in `frontend-design/reference/`. UI/UX Modernization Plan generated.
> ✅ **UI/UX Modernization Phase 0-3 DEPLOYED** (2026-03-10) â Removed all 37+ `@apply` from theme.css (iOS crash fix), fixed broken selectors, replaced pure grays with warm-tinted neutrals, new typography system (Plus Jakarta Sans + Source Sans 3), flattened nested stat cards, skeleton loading, stagger animations, search filter on vehicle inspections, reduced motion support.
> ✅ **UI/UX Modernization Phase 4-8 DEPLOYED + CI/CD FIX** (2026-03-10 late evening) â Phase 4: button press feedback, sliding tab underline, focus-visible rings, toast animations. Phase 5: landing page redesign (cards primary, chatbot secondary, accent bars, removed System Overview). Phase 6: mobile-first polish (pointer:coarse, safe areas, scroll-snap tabs). Phase 7: tabular numbers, enhanced empty states, fluid typography. Phase 8: skip navigation, ARIA labels, font preload. CI/CD: daily-checkout builds in Docker, Reverb/queue worker post-deploy verification, explicit compose.yaml, view:cache error handling, filament:assets step, www.mbfdhub.com smoke tests.
> ✅ **Enterprise Modernization Phases 1-7 MERGED** (2026-03-11) â Phase 1: Impeccable design system admin theme. Phase 2: Laravel Pulse + Spatie Health monitoring. Phase 3: Cloudflare AI Gateway routing (queue removed—requires paid plan). Phase 4: PWA hardening with Dexie offline DB, React Query, vite-plugin-pwa. Phase 5: fire_equipment_requests + station_inspections schema and API. Phase 6: FormsHub wizards (Equipment Request + Station Inspection). Phase 7: Filament admin restructuring with Station Management group and relation managers. Branch `feat/enterprise-modernization` merged to `main` and deployed to VPS.
> ✅ **Workgroup Evaluation Modernization MERGED** (2026-03-11) â Phase 1: EvaluationService brand aggregation + competitor grouping. Phase 2: ERROR-018 fix â removed Livewire widgets, inlined data via getViewData() + async AI. Phase 3: Impeccable UI/UX overhaul for session results + admin dashboard. Phase 4: SAVER document generator â AI-powered purchasing report. Branch `feat/workgroup-evaluation-modernization` merged to `main` and deployed to VPS. Migration: `add_brand_competitor_group_to_candidate_products`.
> ✅ **Unified Filament Theme Pipeline** (2026-03-11) â Fixed fragmented CSS: replaced render hook CSS injection with proper `->viteTheme()` across all 3 panels (Admin, Workgroup, Training). theme.css now imports Filament's pre-compiled dist CSS + custom MBFD overrides. All panels use Plus Jakarta Sans font and MBFD brand red. Build output 120KB unified theme.
> ✅ **Notification Preferences + WebPush Debug Logging** (2026-03-12) â New `notification_preferences` JSON column on users table. NotificationSettings Filament page with 5 toggle categories (Vehicle Inspections, Station Inspections, Fire Equipment Requests, Workgroup Evaluations, Station Inventory Alerts). Registered in Admin and Workgroup panel user menus. AppServiceProvider filters recipients by preferences before dispatch. Station inventory submissions now trigger NewSubmissionNotification. WebPush diagnostic logging added to NewSubmissionNotification (ShouldQueue + failed()), PushSubscriptionController, and push-notification-widget.js.
> ✅ **Dark Topbar UI Unification** (2026-03-12) — Filament topbar restyled to match React SPA dark header (`#171717` bg, MBFD red accent border, white/light text). All 3 panels (Admin, Workgroup, Training) unified via shared `theme.css`. No `@apply` used. VAPID keys verified present, queue worker confirmed running, config cache cleared. Notification pipeline healthy (6 push subscriptions, no failures in logs).
> ✅ **API + Model Fixes for React SPA** (2026-03-13) — StationController::index() now includes `withCount('capitalProjects', 'shopWorks')` so station list cards display correct project/shop work counts. Apparatus model auto-generates slugs from designation on create/update via `booted()` lifecycle hook. New `artisan apparatus:backfill-slugs` command to fix existing null-slug records (e.g., "Captain 5"). FileUpload in Filament Action modals audited — SharedUploads.php already handles temp string paths correctly.
> ✅ **Workgroup Results Page Analytics Restructuring** (2026-03-13) — New `EvaluationService::getGranularToolGroupings()` method provides keyword-based Collection filtering for granular data tables. Session results page now shows: T1 standalone table (with Rabbit Tool replacement note), Forcible Entry Cut-off Saws ranked table, Battery-Operated Extrication Tool Brand Rankings (#1-#4 with gold/silver/bronze), and separate Spreaders/Cutters/Rams ranked tables. Zero data loss — presentation layer only. Reusable Blade partial for tool ranking tables.

## Cloudflare Support AI Worker (2026-03-12)

### `mbfd-support-ai` Worker
- **CRITICAL OVERRIDE system prompt** for repair reporting: directs users to email `FireSupportServices@MiamiBeachFL.Gov` and provides phone contact order
- **RAG Index**: `mbfd-rag-index` — 12 vectors ingested from L1-L11, L3, PUC Engine manuals
- Deployed to Cloudflare Workers with Vectorize binding

### Station Inspection Form Overhaul (2026-03-12)
- Removed 3 sections from the original form (streamlined)
- Added **Pass All** buttons for rapid section completion
- Added **conditional fail inputs** with image capture (camera/file upload)
- Backend: `StationInspectionController@store` handles Base64→file conversion for fail images
- Filament: `StationInspectionsRelationManager` updated to display inspection results with image viewer

### VPS Deployment Notes
- **Any change to `resources/css/` requires `npm run build` inside the Docker container** on VPS
- `public/build/` is gitignored — compiled assets do NOT transfer via `git pull`
- Command: `docker exec mbfd-hub-laravel.test-1 npm run build`
- **Always run `npm run build` INSIDE Docker container**: `docker compose exec laravel.test bash -c 'npm install && npm run build'` (never on host — Docker overlay may serve stale files)
- **After any deployment, fix permissions**: `docker compose exec -u root laravel.test chmod -R 777 storage bootstrap/cache` (container runs as `sail` user, not `www-data`)
- **Station inspection public API**: `POST /api/public/station_inspection` (no auth required — used by tablet forms)

### Deployment Rules (Mandatory — 2026-03-13)

> These rules MUST be followed after every `git pull` on the VPS or CI/CD deploy.

1. **Vite assets MUST be compiled on the VPS** — `public/build/` is gitignored. After pulling CSS/JS changes:
   ```bash
   docker compose exec laravel.test bash -c 'npm install && npm run build'
   ```
2. **Queue workers MUST be restarted** after any Notification class, Job, or Listener change:
   ```bash
   docker exec mbfd-hub-laravel.test-1 php artisan queue:restart
   ```
   The queue worker daemon loads notification classes into RAM. Without restart, old code runs indefinitely.
3. **Cache MUST be cleared** after any config, route, or view change:
   ```bash
   docker exec mbfd-hub-laravel.test-1 php artisan optimize:clear
   docker exec mbfd-hub-laravel.test-1 php artisan view:clear
   ```
4. **Storage permissions MUST be fixed** after container recreation:
   ```bash
   docker compose exec -u root laravel.test chmod -R 777 storage bootstrap/cache
   ```
5. **Never run `npm run build` on the host** — Docker overlay filesystem serves stale files. Always build INSIDE the container.


---

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

---

## Notification System Architecture

### 1. Web Push Notifications (Browser Push)
- **Package**: `laravel-notification-channels/webpush`
- **Configuration**: VAPID keys in `.env` (`VAPID_PUBLIC_KEY`, `VAPID_PRIVATE_KEY`, `VAPID_SUBJECT`).
- **Database**: `push_subscriptions` table stores user subscriptions.
- **Frontend Integration**:
  - `PushNotificationWidget` (Filament widget) allows users to enable/disable push notifications and send a test notification.
  - `resources/js/push-notification-widget.js` handles the client-side logic.
  - Service workers:
    - `public/sw.js` handles push events for the main app (Admin, Training, Workgroup panels).
    - ⚠️ **ISSUE (ERROR-036)**: `public/daily/sw.js` (generated by VitePWA for the React app) currently **LACKS** the push event listener because it's overwritten by the build process.
- **Use Cases**:
  - **Chat Messages**: `ChMessageObserver` listens for new chat messages and sends a `ChatMessageReceived` push notification to the recipient. Includes rate limiting (max 1 per 30 seconds per sender-recipient pair).
  - **Test Notifications**: `TestPushNotification` can be triggered from the widget.
  - **Critical Alerts**: `CriticalAlertNotification` is defined but not currently implemented/used anywhere in the codebase.

### 2. In-App Database Notifications (Filament)
- **Package**: Built-in Filament Notifications (`Filament\Notifications\Notification`).
- **Database**: `notifications` table.
- **Polling**: Configured to poll every 30 seconds in `AdminPanelProvider`, `TrainingPanelProvider`, and `WorkgroupPanelProvider`.
- **Use Cases**:
  - **Project Management**: `NotificationService` sends notifications for overdue projects, overdue milestones, priority alerts, budget alerts, and status updates. Triggered by scheduled console commands (`projects:analyze-priorities`, `projects:check-overdue`, `projects:weekly-summary`, `projects:milestone-reminders`).
  - **Tracking**: `NotificationTracking` model and `notification_tracking` table are used to prevent duplicate notifications (cooldown period).
  - **Action Feedback**: Used extensively across Filament resources and pages to provide success/error feedback (e.g., "Draft Saved", "Evaluation Submitted", "Access Denied").
  - **Observers**: `TodoObserver` sends a notification when a new Todo is assigned.

### 3. Notification Preferences (2026-03-12)
- **Database**: `notification_preferences` JSON column on `users` table (nullable; defaults to all-enabled).
- **Model**: `User::getResolvedNotificationPreferences()` merges saved preferences with defaults. `User::wantsNotificationPreference($key)` checks a single category.
- **Categories**: `vehicle_inspections`, `station_inspections`, `fire_equipment_requests`, `workgroup_evaluations`, `station_inventory_alerts`.
- **UI**: `App\Filament\Pages\NotificationSettings` — Filament page with toggle form, accessible from user menu in Admin and Workgroup panels (role-gated via `canManageNotificationSettings()`).
- **Dispatch Filtering**: `AppServiceProvider::notifySubmissionRoles()` filters recipients by their saved preferences before sending `NewSubmissionNotification`.
- **Station Inventory Alerts**: `StationInventorySubmission::created` now dispatches `NewSubmissionNotification` to `super_admin` and `logistics_admin` roles.

### 4. WebPush Debug Logging (2026-03-12)
- `NewSubmissionNotification` implements `ShouldQueue` with `failed()` method logging VAPID key presence and exception details.
- `PushSubscriptionController` logs all store/delete requests with payload shape, user agent, and IP.
- `push-notification-widget.js` logs subscription payload fields, server response status, and error details to browser console.

### 5. Other Channels
- **Email**: No email notifications are currently implemented.
- **SMS**: No SMS notifications are currently implemented.
- **Third-Party**: No Slack/Discord/Teams integrations are currently implemented.

---

## Workgroup Evaluation Modernization (2026-03-11)

### Overview
Complete overhaul of the Workgroup Evaluation system across 4 phases, merged from `feat/workgroup-evaluation-modernization`.

### Phase 1 — EvaluationService
- New `App\Services\Workgroup\EvaluationService` with brand aggregation and competitor grouping logic
- `CandidateProduct` model extended with `brand` and `competitor_group` columns

### Phase 2 — ERROR-018 Resolution
- Removed all Livewire widget children from `SessionResultsPage` and `AdminDashboard`
- All data computed in `getViewData()` (always fresh on re-render)
- Async AI analysis triggered via WorkgroupAIService without blocking page load

### Phase 3 — UI/UX Overhaul
- Session results page redesigned with Impeccable design system principles
- Admin dashboard modernized with inline data rendering
- Warm neutral color palette, proper typography hierarchy

### Phase 4 — SAVER Document Generator
- AI-powered purchasing report generation via `WorkgroupAIService`
- SAVER report Blade template at `resources/views/filament/workgroup/pages/saver-report.blade.php`
- Route: `/workgroups/saver-report/{session}`

### Key Files
| File | Purpose |
|---|---|
| `app/Services/Workgroup/EvaluationService.php` | Brand aggregation, competitor grouping, scoring |
| `app/Services/Workgroup/WorkgroupAIService.php` | AI analysis + SAVER report generation |
| `app/Filament/Workgroup/Pages/SessionResultsPage.php` | Results page (widget-free) |
| `app/Filament/Workgroup/Pages/AdminDashboard.php` | Admin dashboard (widget-free) |
| `resources/views/filament/workgroup/pages/session-results.blade.php` | Results Blade template |
| `resources/views/filament/workgroup/pages/saver-report.blade.php` | SAVER report template |
| `resources/css/filament/admin/theme.css` | Updated with workgroup result styles |
