# CLAUDE.md â MBFD Hub AI Context

> ✅ **Workgroup Analytics Fix DEPLOYED** (2026-03-14) — Fixed three compounding bugs: (1) Pending count now correctly subtracts both submitted AND in-progress drafts (`Pending = MaxPossible - Submitted - InProgress`). (2) "Overall" AI report no longer defaults to Day 1 — `generateExecutiveReport()` refactored to accept `Workgroup + ?WorkgroupSession` with new aggregate query methods. (3) Anonymous evaluator comments (narrative_payload, deal_breaker_note, legacy EvaluationComment) now injected into executive report and SAVER report AI payloads with RAG directive for vendor spec cross-referencing via workgroup-specs Vectorize index.
> ✅ **DeerFlow Zero Trust Exposure** (2026-03-13) — Cloudflare Tunnel `deerflow-local` (ID: `c64064b3-d224-4392-a977-93aad34f41ee`) created with outbound-only QUIC connections. `code.mbfdhub.com` mapped via CNAME to tunnel UUID. Cloudflare Access Application enforces Google identity for `pdarleyjr@gmail.com` only (24h session). Hardened `cloudflared` sidecar deployed with read-only filesystem, `no-new-privileges`, all caps dropped, no docker.sock mount, internal Docker network only. Telegram Long-Polling unaffected.
> ✅ **Mission Status: ✅ Production** (2026-03-12)  
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
> ✅ **DeerFlow 2.0 Agentic Orchestration Installed** (2026-03-13) — DeerFlow 2.0 cloned to WSL `~/src/deer-flow` with Docker-first architecture. GLM-5 reasoning engine via DeepInfra configured. AIO sandbox bind-mounts `~/src/mbfd-hub` at `/mnt/user-data/workspace/mbfd-hub`. Telegram bot (@MBFDHubBot) integrated for task injection. 4 MBFD skills created (Plan, Implement, Review, Scribe). VPS `/root/src` cleaned of legacy artifacts. Environment segmentation enforced: Local=orchestration, VPS=runtime only.
- Impeccable design audit: OKLCH color space, no @apply, tinted neutrals enforcement

---

## Apparatus Layout Planner — DeerFlow Orchestration (2026-03-13)

### Overview
Public (no-auth) React SPA for fire apparatus compartment layout planning, mounted on the existing Laravel 11 backend. Uses multi-model DeerFlow orchestration with specialized DeepInfra models.

### Multi-Model Configuration (`~/src/deer-flow/config.yaml`)
| Model Name | DeepInfra Model | Role |
|---|---|---|
| `coordinator-model` | `zai-org/GLM-5` | Long-context planning, reasoning, sub-agent orchestration |
| `coder-model` | `MiniMaxAI/MiniMax-M2.5` | React/Konva implementation, Laravel API, TypeScript |
| `vision-model` | `Qwen/Qwen2.5-VL-32B-Instruct` | Image pipeline, OCR on spec sheets, tool normalization |

### Custom Skills (`~/src/deer-flow/skills/custom/`)
| Skill | Path | Purpose |
|---|---|---|
| `mbfd-planner` | `skills/custom/mbfd-planner/SKILL.md` | Architecture, task decomposition, milestone planning |
| `mbfd-coder` | `skills/custom/mbfd-coder/SKILL.md` | Code generation, API integration, save system |
| `mbfd-image-pipeline` | `skills/custom/mbfd-image-pipeline/SKILL.md` | Two-track tool asset gathering and normalization |
| `mbfd-reviewer` | `skills/custom/mbfd-reviewer/SKILL.md` | Vitest, Playwright, export verification, design audit |

### Frontend Stack
React 18, TypeScript, Vite, react-konva (Konva.js), shadcn/ui, Tailwind (compiled), Zustand (client state), TanStack Query (server state), Dexie/IndexedDB (offline drafts), pdf-lib (landscape PDF export).

### Backend
Laravel 11 public API routes at `/api/public/apparatus-layout/*`, PostgreSQL JSONB for snapshot storage. Tables: `apparatus_compartments`, `apparatus_layout_tools`, `apparatus_layout_snapshots`.

### Image Pipeline (Two-Track)
- **Track 1 (Preferred)**: Real product photo → OCR dimension extraction → `rembg` background removal → scaled transparent PNG
- **Track 2 (Fallback)**: No photo available → FLUX.1-Kontext-dev synthetic generation → `rembg` → scaled PNG with "low confidence" tag

### Save System
- **Layer 1**: Dexie/IndexedDB autosave every 30 seconds (max 10 local drafts)
- **Layer 2**: PostgreSQL JSONB named snapshots via public API

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

### Core Context Files for AI Agents (Exact Filenames)

> ⚠️ **DeerFlow / AI agents MUST use these exact filenames.** Note the leading dot on `.project_summary.md`.

| File | Exact Filename | Purpose |
|------|---------------|---------|
| Project Summary | `.project_summary.md` | High-level project overview (hidden file — leading dot required) |
| AI Context | `CLAUDE.md` | Discovery/orchestration context, deployment rules, architecture |
| AI Error Log | `AI_AGENT_ERRORS.md` | Known agent error patterns and prevention rules |
| Discovery Report | `MBFD_HUB_DISCOVERY_REPORT_2026-02-12.md` | Initial codebase discovery findings |
| Snipe-IT Brief | `SNIPE_IT_PROJECT_BRIEF.md` | Equipment intake / Snipe-IT integration brief |
| UI/UX Plan | `UI_UX_MODERNIZATION_PLAN.md` | Impeccable design system modernization plan |

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

---

## DeerFlow 2.0 Agentic Orchestration (2026-03-13)

### Architecture
DeerFlow 2.0 is the autonomous agent plane that transforms the local workstation into a decentralized command center. Tasks are injected via Telegram and executed through a Plan → Implement → Review → Scribe workflow.

### Environment Segmentation (Zero-Tolerance Policy)
| Environment | Purpose | Location | Forbidden Actions |
|---|---|---|---|
| Local WSL (Env A) | Orchestration & Control Plane | `~/src/deer-flow` + `~/src/mbfd-hub` | No production DB changes without CI/CD |
| Production VPS (Env B) | Runtime Deployment | `145.223.73.170:/root/mbfd-hub` | No DeerFlow installation; no manual code edits |

### Components
| Component | Location | Purpose |
|---|---|---|
| DeerFlow 2.0 | WSL `~/src/deer-flow` | Agent orchestration framework |
| GLM-5 via DeepInfra | `api.deepinfra.com/v1/openai` | Reasoning engine (model: `zai-org/GLM-5`) |
| AIO Sandbox | Docker container | Isolated code execution, bind-mounts MBFD Hub |
| Telegram Bot | `@MBFDHubBot` | Task injection interface |
| MBFD Skills | `~/src/deer-flow/skills/mbfd-*.md` | Plan, Implement, Review, Scribe workflows |

### Docker Services (Local WSL)
| Container | Port | Purpose |
|---|---|---|
| `deer-flow-nginx` | 2026 | Reverse proxy |
| `deer-flow-frontend` | 3000 (internal) | Next.js UI |
| `deer-flow-gateway` | 8001 (internal) | Backend Gateway API |
| `deer-flow-langgraph` | 2024 (internal) | LangGraph server |

### Key Configuration Files
| File | Purpose |
|---|---|
| `~/src/deer-flow/config.yaml` | Model, sandbox, tools, Telegram, skills config |
| `~/src/deer-flow/.env` | API keys (DeepInfra, Telegram, GitHub) |
| `~/src/deer-flow/skills/mbfd-*.md` | 4 MBFD workflow skills |
| `~/src/deer-flow/.ssh/id_ed25519_hpb_docker` | VPS SSH key for deployment |

### Standard Workflow
1. Inject task via Telegram → DeerFlow receives at `@MBFDHubBot`
2. **Plan**: Read CLAUDE.md + AI_AGENT_ERRORS.md, enumerate steps
3. **Implement**: Branch, edit, commit, push from sandbox
4. **Review**: Cross-reference against error log and design system
5. **Scribe**: Update documentation (CLAUDE.md, AI_AGENT_ERRORS.md)
6. CI/CD deploys to VPS automatically via `.github/workflows/deploy.yml`

### _zero Trust Remote Access (2026-03-13)
DeerFlow UI is securely exposed to the public internet via Cloudflare Zero Trust, eliminating all inbound firewall ports.

| Component | Detail |
|---|---|
| Tunnel Name | `deerflow-local` |
| Tunnel ID | `c64064b3-d224-4392-a977-93aad34f41ee` |
| Public URL | `https://code.mbfdhub.com` |
| Identity Provider | Google (auto-redirect) |
| Allowed Email | `pdarleyjr@gmail.com` |
| Session Duration | 24 hours |
| Access App ID | `03532c94-9886-4359-9759-746f954c65bf` |

**Architecture:**
```
Browser → https://code.mbfdhub.com → Cloudflare Access (302 → Google Auth)
  → Authenticated → Cloudflare Edge → QUIC tunnel → deer-flow-cloudflared container
  → deer-flow-nginx:2026 → DeerFlow UI
```

**Hardened Sidecar (`deer-flow-cloudflared`):**
- `read_only: true` — immutable filesystem
- `no_new_privileges: true` — prevents privilege escalation
- `cap_drop: ALL` — zero Linux capabilities
- NO `/var/run/docker.sock` mount
- NO host PID/network namespace sharing
- Internal Docker network `deer-flow-dev_deer-flow-dev` only
- `--no-autoupdate` flag prevents unsanctioned binary changes

**Docker Compose Addition** (`~/src/deer-flow/docker/docker-compose.yaml`):
The `cloudflared` service is defined in the production compose file. When using `docker-compose-dev.yaml`, the container must be started separately via `docker run` on the same network.

## Apparatus Layout Planner DeerFlow Skills (2026-03-13)

### Architecture
A multi-model orchestration pipeline with a dedicated coordination task queue for the layout planning process. This architecture aids in breaking down a complex end-to-end deployment into manageable microtasks, bringing the CI/CD automation much closer to the ideal state.

| Component | Cloud Model | Region | GPU | Memory | Purpose |
|---|---|---|---|---|---|
| Coordinator/Planner | GLM-5 | *local* | T4 | 32 GB | Task orchestration (~70M tokens); architectural decomposition; sequential chaining of images/videos |
| Coder | `command-r-plus/text-to-python` on MiniMax-M2.5 | *local* | T4 | 32 GB | Script generation; exact FFI calls to React/Node (Babel, Webpack, Vite plugins, Konva components), Laravel API (server-side & Eloquent), and simplified CirrusCI/Temporal workflows (file uploads, Vercel deploys) |
| Image/Vision | Qwen2.5-VL-32B on Azure AI | East US | 2xA10G | 48 GB | Asset review pipeline (~2B tokens for architectural images+diagrams); multi-modal followup question answering; two-track normalization (model `A` on device견인품连载, 모델 `B` on Azure 클라우드에서 파이썬 스크립트 실행을 통한 안전한 API 구현을 체크해요); image-to-image translation pipelines by device code/name; direct embedding of specs images into code; Tangram 데이터셋(SPIFF, EOBD-WUF, ÉFE앞면, 드rum박스,릿라운저ück,...) => React Components/Laravel JSONB |
| Reviewer (Q&A) | Anthropic/LangChain | *local* | T4 | 32 GB | Internal QA audit for code quality; dtos/factory builder construction; imprecise data correction; persona context (Model/drça력을 산출하기 위한 사전 수행) |

### Key Configuration
| File | Description |
|---|---|
| `~/src/deer-flow/config.yaml` | 모든 모델/도구, Telegram, 지식파일 셋팅(세부 스킬 안에서 반복적인 변수 사용) |
| `~/src/deer-flow/.env` |特朗普 키(DeepInfra, Telegram, GitHub), API 키 |
| `~/src/deer-flow/skills/mbfd-*.md` | 반복적인 표기문제로 인해 여러 파일에서 스키 고생하는 스킬;(objectschemas에 통합될 경우 계속 수정될 수 있음) |
| `~/src/deer-flow/.ssh/id_ed25519_hpb_docker` | VPS SSH 키 |

### Workflow
| 단계 | 상세 조치 |
|---|---|
| ** 걸쳐준경우("**fix broken selector**..." issue부터 ""), 새로운 스키 생성<br>Imgur로 업로드된 조각트를 하위 구조에 객체화시키거나 `frontend-design/reference/`에 추가. |
| 참고 문헌<br>🛠️ 스킵ikki 코드에 대한 최대한 많은 정보를 포함.<br>꽃 패턴, 시 OPP, 축적 지지 기술 등. | 예시: `longarm	Texture\n Swivel Magnitude: 450 ft\n Extending Arm: ` => `nlsw-450 | temp(` => `arm(rib) => ...` |
| 현재 문서를 메모리에 로드하여 스텐 도메인에 대한 기본적인 이해 확보 | 사용법, 코드명입니다. 스텐은 상당히수 계층적이고, Conformity Horizon에 추가될 수 있습니다. |
| 연관된 API 엔드포인트의 전략을 정의하고 <br>별본 문제 상식을 보다 심각하게 처리하기 위해 테스트를 기본 메모리에 추가 | ❗️ 만약 규모가 커지면 <br> Load`table.split下来的모든 rows`<br> May: `groupBy('pronouncement.newsletter_subtitle').count()`. |
| 스토리니지 또는 정렬 비디오 컨트롤의 베이스에 해당하는 단위:诗句. 단위 참고 (Ex: `default 아직 설치됨`; `미구현`; `개발 예정`)는 적절히 사용해야 합니다. |
| 최적의 `->viteTheme()|.css|.vue`를 사용하도록 모든 서드파티 컴포넌트와 <br>로드된 공통 백그라운드 스타일(예시: `theme.css`)을 교체하는 반복적인 작업 유지. |
| 텔레그램의 **Plan** 스테이지에서 클라우드 모델에 여러 참조 문서를 만드세요, <br>또한 IQ 향상을 위해 이전 텔레그램 창의 모든 `doctests`과 댓글을 선택정보화하세요. |
| 범위가 전역적인 반복적인 기능 지원을 위한 종기적인 마커와 예외처리에 대해 유의하세요. 보통 여기에는 생략된 함수 키(`$`), 특정 환경 특정 언어(`$i18n`), <br>Git tags/개요(프로젝트 문서)와 같은 정보가 포함됩니다. |
| `pdf-lib` 및 React에서 사용하는 모든 라이브러리가 최신으로 유지됩니다. 모든 버전 기록은 IDE에서 계속해서 숨겨져야 합니다. |
| **_반환_** React 템플릿, 기계 학습 모델, 포트폴리오 요약 템플릿, `.catalog.json`, `critique.md`, 기본 스타일 시트 및 샘플 컴포넌트. |

### 언어 선택 이유
- **Multimodel:** 이 활동에 최적화된 테스트 토플로지 기반의 여러 이드코어된 모델을 병렬로 사용합니다.
- **Open-source:** 모든 연결된 다음 단계에서 재작업 보장입니다. 모든 모델을 또는, 수준이 높은 기능을 추가하기 위해领先합니다.
- **Cost:** 나타날 수 있는 비용으로 지불되는 돈입니다.
- **Capacity:** 텍스트에 너무 긴 응답을 받을 수 있는 용량입니다.

### 주요 지표
| 지표 | 목표 |
|---|---|
| Latency | ≤ **5** * async"=>delay₁-delay₂<br>((Time to send a doc string to GLM-5)\)\n|
| Georgia Font | ✓ |
| Slack | ✓ |
| Required Commands | App::getInstance()->call("...") |


---

## Production Observability Stack (2026-03-13)

### Architecture
Isolated observability stack deployed at `/root/observability/` on the production VPS. Completely separated from the MBFD Hub application stack to prevent dependency conflicts.

### Services

| Service | Container | Host Port | Container Port | Purpose |
|---|---|---|---|---|
| Dozzle | `observability-dozzle-1` | 8888 | 8080 | Real-time Docker log streaming |
| Uptime Kuma | `observability-uptime-kuma-1` | 3001 | 3001 | Heartbeat/uptime monitoring |
| Web-Check | `observability-web-check-1` | 3000 | 3000 | Security audit & header analysis |

### Access URLs
- **Dozzle**: `http://145.223.73.170:8888`
- **Uptime Kuma**: `http://145.223.73.170:3001`
- **Web-Check**: `http://145.223.73.170:3000`

### Compose File
`/root/observability/docker-compose.yml` — managed independently from `/root/mbfd-hub/compose.yaml`.

### Critical Port Note
⚠️ **Port 8080 is RESERVED for Laravel Reverb** (mapped via laravel.test container). The observability stack does NOT use port 8080 externally. Dozzle's internal port 8080 is mapped to host port 8888.

### Management Commands
```bash
# Start/restart observability stack
cd /root/observability && docker compose up -d

# View logs
cd /root/observability && docker compose logs -f

# Stop without removing data
cd /root/observability && docker compose down
```

---

## Local AI-Assisting Development Sandbox (2026-03-13)

### Purpose
Shift-left testing environment. Local sandbox is the final authority for code quality before VPS deployment.

### Services (docker-compose.local-sandbox.yml)

| Service | Port | Binding | Purpose |
|---|---|---|---|
| Browserless | 3000 | 0.0.0.0 | Headless Chrome for Puppeteer/Playwright |
| Pgweb | 8081 | 127.0.0.1 ONLY | Visual PostgreSQL management |

### Prerequisites
- Node.js 22+
- pnpm or uv for DeerFlow dependencies
- Crawl4AI via `uv pip install crawl4ai` for documentation scraping

### Start Commands
```bash
docker compose -f docker-compose.local-sandbox.yml up -d
```

### Security
- Pgweb is bound to `127.0.0.1:8081` — **NEVER bind to 0.0.0.0**
- Browserless allows up to 5 concurrent sessions with 60s timeout

---
