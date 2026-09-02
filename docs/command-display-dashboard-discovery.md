# MBFDHub Command Display Dashboard Discovery Report

> Discovery / planning document only. This report scopes a **new, separate, read-only
> "Command Display Dashboard"** that consumes the existing MBFDHub. It proposes **no changes**
> to the existing Filament admin panel, employee submission flows, or any write/approval path.
> All findings are grounded in the 10 parallel read-only workstreams. Where a capability was
> **not found** in the hub, it is flagged explicitly as a new external integration rather than
> invented as existing.

Date: 2026-06-07 · Repo: `d:\GitHub_Repos\MBFD_Hub` · Branch at time of discovery:
`security/ecosystem-hardening-20260606`

---

## 1. Executive Summary

**What was analyzed.** Ten parallel read-only workstreams inspected the MBFDHub codebase
(Laravel 11 + Filament 3 + React 19 multi-SPA), its admin dashboard widgets, the employee
submission/station workflows, the data model and API surface, external integrations, the
security/permission model, the AI/Ollama wiring, large-display UI/UX options, the frontend
tech stack options, and the production deployment topology.

**What already exists (partial plumbing is in place).** A new display dashboard does **not**
start from zero. The hub already exposes:

| Existing component | What it already does | Where |
|---|---|---|
| `AdminMetricsController` | Aggregated apparatus / defect / inspection / inventory counts + critical-stock list, behind `auth + admin.role`, `throttle:60,1` | `app/Http/Controllers/Api/AdminMetricsController.php` (`GET /api/admin/metrics`) |
| `SmartUpdatesController` | AI-generated operational summary + action items + risks (proxies a Cloudflare Worker today) | `app/Http/Controllers/Api/SmartUpdatesController.php` (`GET /api/admin/smart-updates`) |
| `CommandCenterAiService` | Single source of truth for ops metrics + change-driven (fingerprint) AI brief caching, dispatches `GenerateCommandCenterSummaryJob`; wired to local `qwen3.6:35b` via `LocalAIService` | `app/Services/CommandCenterAiService.php`, `app/Services/LocalAIService.php` |
| `StationOperationsHubWidget` | Per-station rollup (today's inspections, station inspections, equipment/supply/big-ticket requests, open defects), batch-loaded, 30s poll | `app/Filament/Widgets/StationOperationsHubWidget.php` |
| Public read API | Redacted apparatus/station/TRT endpoints serving the daily-checkout SPA | `routes/api.php` (`/api/public/*`) |
| `IncidentsController` | PulsePoint active-runs proxy with 60s server cache (public, `throttle:60,1`) | `app/Http/Controllers/IncidentsController.php` (`GET /api/incidents`) |

**Feasibility verdict.** **Feasible and low-risk.** The hub already aggregates exactly the
metrics a command display needs, already has a change-driven local-AI brief, and already has
a precedent (`mbfd-ops-wall.pages.dev`) for a separate read-only Cloudflare Pages display that
reads hub data over a tunnel. The recommended path is a **separate read-only SPA at
`command.mbfdhub.com`** that consumes a small new `/api/display/*` API layer (which mostly
re-packages `AdminMetricsController` + `CommandCenterAiService` data) plus the existing public
endpoints — with no schema changes, no write endpoints, and no modification to `/admin`.

**Two honest caveats surfaced by discovery:**

1. **Sanctum has no token scopes today** (no `tokenCan`/abilities usage found). A true
   read-only token tier must be added, or the display must rely on Cloudflare Access + a
   GET-only route group + a read-only role.
2. **Weather / marine / tides are NOT in the hub.** Any such module is a *new external
   integration* (NOAA/marine API), not a reuse of existing data. The `mbfd-ops-wall` repo has
   marine weather precedent but it is external to this repo.

---

## 2. Hard Boundary

The new Command Display Dashboard is a **separate, read-only, non-invasive** application. The
following are explicitly **do-not-touch / do-not-modify**:

**Do NOT modify (out of scope):**

- **The `/admin` Filament panel** — `app/Providers/Filament/AdminPanelProvider.php`, all
  resources under `app/Filament/Resources/`, and all admin widgets under
  `app/Filament/Widgets/`. The widgets may be *read as design references* but their Blade /
  Livewire state must not be mutated or re-pointed.
- **The other Filament panels** — `/employee` (`EmployeePanelProvider`), `/workgroups`
  (`WorkgroupPanelProvider`), `/training` (`TrainingPanelProvider`).
- **All write / approval / state-mutation flows**, including: apparatus status transitions
  (`ApparatusResource`), defect resolution (`DefectResource`), capital project completion
  (`CapitalProjectResource`), Equipment Intake → Snipe-IT (`EquipmentIntake` page),
  Bid PIN sync (`BidAccessPin`), Knowledge Base ingestion (`KnowledgeBase`), and the station
  inventory PIN (`StationResource.inventory_pin_hash`).
- **Employee submission endpoints** — the public POST endpoints for apparatus inspections,
  station inspections, inventory submissions, TRT submissions, support chat. The display reads
  the *results* of these flows; it never submits.
- **The production database** — never reset/wipe; reads only, via aggregated/cached endpoints.
- **The bid bridge, ScreenTinker sync, and KnowledgeBase upstream** — one-way internal bridges;
  the display must not duplicate or trigger them.

**How separation + read-only is enforced:**

- **Separate app + separate domain** (`command.mbfdhub.com`), separate deploy pipeline; no new
  route registered inside any existing Filament panel.
- **New `/api/display/*` namespace only** (no collision with `/api/admin/*` or `/api/public/*`),
  GET-only, with an explicit "method-not-allowed on non-GET" guard.
- **No direct DB access** from the display app; all data flows through cached, aggregated,
  redacted HTTP endpoints.
- **Cache invalidation hooks are additive** (model `creating`/`updating` listeners that only
  `Cache::forget` display keys) — they do not alter existing write logic.

---

## 3. Existing Architecture Inventory

### 3.1 Framework & versions

| Layer | Tech / version | File reference |
|---|---|---|
| Backend framework | Laravel 11.31 (PHP 8.2) | `composer.json`, `config/app.php` |
| Admin UI | Filament 3.2 (panels at `/admin`, `/employee`, `/workgroups`, `/training`) | `app/Providers/Filament/*PanelProvider.php` |
| Auth/permissions | Sanctum + spatie/permission 6.24 + filament-shield 3.2 | `config/auth.php`, `config/sanctum.php`, `app/Models/User.php` |
| Realtime | Laravel Reverb 1.0 (WebSockets) | `config/reverb.php`, `config/broadcasting.php` |
| Observability | Laravel Pulse 1.0, spatie/laravel-health, Sentry (laravel + browser) | `config/pulse.php`, `config/health.php`, `resources/js/app.js` |

### 3.2 Frontend stack

| Concern | Tech / version | File reference |
|---|---|---|
| UI library | React 19.2.4 + TypeScript 5.9.3 | `package.json` |
| Build | Vite 6.4.2 (multi-SPA) | `vite.config.js`, `tsconfig.json` |
| Styling | Tailwind 3.4.13 | `tailwind.config.js` |
| Server state | TanStack Query 5.90.21 | `resources/js/daily-checkout/src/providers/QueryProvider.tsx` |
| Client state | Zustand 5.0.11 | `resources/js/pump-simulator/` |
| Motion | framer-motion 12.34.5 | `resources/js/daily-checkout/` |
| Offline | Dexie 4.3.0 (IndexedDB), service workers | `resources/js/daily-checkout/`, `public/admin-pwa/` |

Frontend is a **multi-app monorepo**: landing (`/`), Daily Checkout SPA (`/daily/*`), Pump
Simulator (`/pump-simulator`), Workgroup Data
Dashboard, and an Admin Desktop PWA (`/admin/*`). **No Three.js is present today** — a greenfield
opportunity for the display.

### 3.3 Backend services (key)

| Service | Purpose | File |
|---|---|---|
| `CommandCenterAiService` | Ops metrics + change-driven AI brief cache | `app/Services/CommandCenterAiService.php` |
| `LocalAIService` | Routes AI to local Ollama `qwen3.6:35b` | `app/Services/LocalAIService.php` |
| `CloudflareAIService` | Cloudflare Workers AI fallback / base class | `app/Services/CloudflareAIService.php` |
| `SnipeItService` | Snipe-IT asset CRUD/audit | `app/Services/SnipeItService.php` |
| `KnowledgeBaseService` | RAG ingestion to Cloudflare Vectorize | `app/Services/KnowledgeBaseService.php` |
| `ApparatusSheetSyncService` | One-way apparatus → Google Sheet sync | `app/Services/GoogleSheets/ApparatusSheetSyncService.php` |
| `NotificationService` | DB + web push notifications | `app/Services/NotificationService.php` |

### 3.4 Data, queue, cache, broadcasting

| Concern | Production | Local | File |
|---|---|---|---|
| Database | PostgreSQL 16.13-alpine | SQLite | `config/database.php`, `compose.prod.yaml` |
| Cache | Redis 7.4-alpine | database | `config/cache.php` |
| Queue | database (`notifications`, `default`) | database | `config/queue.php`, `docker/supervisor/supervisord.conf` |
| Broadcasting | Reverb (`0.0.0.0:8080` listen, `127.0.0.1:8080` internal) | log | `config/reverb.php`, `config/broadcasting.php` |

Notable cache keys already in use: `command_center_ai_summary` (24h), `command_center_ai_pending`
(10m guard), `cloudflare_ai_requests` (rate limit), `pulsepoint_incidents` (60s), admin lookups
(5m).

### 3.5 Auth & guards

| Guard | Driver | Provider | Used by | File |
|---|---|---|---|---|
| `web` | session | users | Filament panels, admin lookups | `config/auth.php` |
| `sanctum` | sanctum | users | API tokens for PWA/headless | `config/sanctum.php` |
| `employee` | session | employees | Employee portal / bid bridge | `config/auth.php` |

### 3.6 Deployment, domains, subdomains

- **Host:** GMKtec homelab; Docker Compose (`mbfd-hub-laravel`, `mbfd-hub-pgsql`,
  `mbfd-hub-redis`); all bind to `127.0.0.1` only.
- **Ingress:** Cloudflare Tunnel `mbfdhub-gmktec` (`*.mbfdhub.com`, all proxied/orange).
- **CI:** GitHub Actions self-hosted runner — **currently billing-blocked → manual deploys**
  (`.github/workflows/deploy.yml`, `CLAUDE.md`).

| Subdomain | Service | Port (localhost) |
|---|---|---|
| `www.mbfdhub.com` | Laravel hub | :8080 |
| `admin.mbfdhub.com` | gethomepage.dev dashboard (existing display precedent) | :8088 |
| `inventory.mbfdhub.com` | Snipe-IT | — |
| `bid.mbfdhub.com` | Bid Worker | — |
| `media-control.mbfdhub.com` | Media Control / ScreenTinker | :8096 |
| `status.mbfdhub.com` | status | :3001 |
| `mbfd-ops-wall.pages.dev` | Cloudflare Pages ops-wall (separate read-only display precedent) | CF edge |

---

## 4. Current Admin Dashboard Inventory

> The display dashboard reuses the **data** these widgets compute, never the widgets themselves.
> "Safe to expose read-only?" assumes redaction + admin-gated access (see §15).

| Resource / Page / Widget | Model / data source | File path | Purpose | Useful for read-only display? | Safe to expose read-only? |
|---|---|---|---|---|---|
| StatsOverviewWidget | Apparatus, ApparatusDefect, ApparatusInspection | `app/Filament/Widgets/StatsOverviewWidget.php` | Fleet health snapshot (counts) | Yes — headline counts | Yes |
| FleetStatsWidget | Apparatus, ApparatusDefect | `app/Filament/Widgets/FleetStatsWidget.php` | OOS + critical defect counts (weekly trend is simulated) | Yes (drop simulated trend) | Yes |
| InventoryOverviewWidget | EquipmentItem | `app/Filament/Widgets/InventoryOverviewWidget.php` | Low-stock health | Yes | Yes |
| StationOperationsHubWidget | Station, Apparatus, inspections, defects, requests | `app/Filament/Widgets/StationOperationsHubWidget.php` | Per-station live cockpit (30s poll) | **Yes — primary model for station grid** | Mostly (replace Filament edit/view URLs with read links/deep-links) |
| SmartUpdatesWidget | All ops models + AI | `app/Filament/Widgets/SmartUpdatesWidget.php` | Instant bullets + AI brief + chat | Yes (instant metrics + AI brief; **exclude chat**) | Partial (chat is interactive) |
| ProjectStatsOverviewWidget | CapitalProject | `app/Filament/Widgets/ProjectStatsOverviewWidget.php` | Capital project health (60s) | Yes | Yes |
| PriorityNotificationsWidget | CapitalProject, ProjectMilestone | `app/Filament/Widgets/PriorityNotificationsWidget.php` | Overdue/critical alerts (30s) | Yes | Yes |
| TodoOverviewWidget | Todo | `app/Filament/Widgets/TodoOverviewWidget.php` | Task queue snapshot | Optional | Yes |
| RecentAllocationsWidget | ApparatusInventoryAllocation | `app/Filament/Widgets/RecentAllocationsWidget.php` | Equipment distribution log | Optional | Yes |
| RecentProjectUpdatesWidget | ProjectUpdate | `app/Filament/Widgets/RecentProjectUpdatesWidget.php` | Project activity feed | Optional | Yes |
| UpcomingMilestonesWidget | ProjectMilestone | `app/Filament/Widgets/UpcomingMilestonesWidget.php` | Milestone timeline | Optional | Yes |
| FireEquipmentStatsWidget | FireEquipmentRequest | `app/Filament/Widgets/FireEquipmentStatsWidget.php` | Equipment request health | Yes | Yes |
| PendingRecommendationsWidget | Recommendation, Apparatus | `app/Filament/Widgets/PendingRecommendationsWidget.php` | Replacement candidates | Optional | Yes |
| Fire Apparatus resource | Apparatus | `app/Filament/Resources/ApparatusResource.php` | Fleet CRUD | Read-only deep-link target | Read fields only (redact VIN/Snipe-IT/notes/location) |
| Stations resource | Station | `app/Filament/Resources/StationResource.php` | Station CRUD + relations | Deep-link target | Read fields only (hide `inventory_pin_hash`) |
| Capital Projects resource | CapitalProject | `app/Filament/Resources/CapitalProjectResource.php` | Project CRUD | Deep-link target | Read fields only (consider hiding exact budgets) |
| Defects resource | ApparatusDefect | `app/Filament/Resources/DefectResource.php` | Defect resolution | Deep-link target | Read fields only (redact photos/notes) |
| Equipment Intake page | SnipeItService | `app/Filament/Admin/Pages/EquipmentIntake.php` | AI scan → Snipe-IT write | No | **No — write/external** |
| Bid Access PIN page | Bid Worker bridge | `app/Filament/Admin/Pages/BidAccessPin.php` | PIN sync write | No | **No — write/external** |
| Knowledge Base page | KnowledgeBaseService | `app/Filament/Admin/Pages/KnowledgeBase.php` | RAG upload | No | **No — write/external** |
| Users / Roles | User, spatie roles | `app/Filament/Resources/UserResource.php`, shield | Auth admin | No | **No — auth/PII** |
| Workgroup widgets | Eval models | `app/Filament/Workgroup/Widgets/*` | Evaluation progress | No (role-sensitive) | **No — keep role-gated** |

Admin nav groups (for deep-link mapping): Dashboard, Active Operations, Fleet Management,
Inventory & Logistics, Workgroup Management, Station Management, Bid Administration.

---

## 5. Employee Submission / Station Workflow Inventory

These are the data-producing flows feeding the display. **Definitions used below:** *Complete* =
finalized and reviewed/approved; *Partial* = submitted but awaiting review, or local/offline draft;
*Missing* = no record exists for the station/apparatus/period.

| Workflow | Model(s) / table(s) | File paths | Station association | Timestamps | Completion status field | Defects / issues field | Suggested display metric |
|---|---|---|---|---|---|---|---|
| Apparatus Daily Inspection / Checkout | `ApparatusInspection`, `ApparatusDefect`, `SingleGasMeter` | `app/Models/ApparatusInspection.php`; `app/Http/Controllers/Api/ApparatusController.php`; `resources/js/daily-checkout/src/components/InspectionWizard.tsx` | via `apparatus.station_id` | `completed_at`, `created_at` | `review_status` (pending_review/approved) | linked `ApparatusDefect` (status Present/Missing/Damaged) | % apparatus with today's checkout complete; critical defects pending review |
| Station Building Inspection | `StationInspection` | `app/Models/StationInspection.php`; `app/Http/Controllers/Api/StationInspectionController.php`; `resources/js/daily-checkout/src/components/forms/StationInspectionWizard.tsx` | `station_id` | `inspection_date`, `reviewed_at` | `overall_status` (pass/fail/needs_attention) + `reviewed_at` | `form_data` JSON (per-item fail + photo) | station inspection pass rate; pending admin review |
| Station Inventory Submission | `StationInventorySubmission`, `StationInventoryItem`, `StationInventoryAudit` | `app/Http/Controllers/Api/StationInventoryV2Controller.php`; `resources/js/daily-checkout/src/components/StationInventoryForm.tsx` | `station_id` | `submitted_at`, `last_updated_at` | items JSON; `StationInventoryItem.status` (ok/low/ordered/overstocked) | low items; supply-request count | daily inventory submission rate; low-stock items across fleet |
| Apparatus Defect Reporting / Resolution | `ApparatusDefect`, `ApparatusDefectRecommendation` | `app/Models/ApparatusDefect.php` | via apparatus → station | `reported_date`, `resolved_at` | `resolved` (bool), `status` | item, photo_path, `defect_history` JSONB | unresolved critical defects; resolution SLA |
| Fire Equipment Request | `FireEquipmentRequest` | `app/Http/Controllers/Api/FireEquipmentRequestController.php` | `station_id` | `approved_at`, `created_at` | `status` (pending/approved/denied/fulfilled), `priority` | form_data, signatures | pending equipment requests; critical request age |
| Employee Equipment Request | `EmployeeEquipmentRequest` | `app/Http/Controllers/Api/EmployeeEquipmentRequestController.php` | indirect via `User.station` | `reviewed_at`, `created_at` | `status` (Pending/Ordered/Ready/Completed/Declined), `is_archived` | requested_items, reason, admin_notes | pending employee requests; processing SLA |
| Big Ticket Request | `BigTicketRequest` | `app/Models/BigTicketRequest.php`; `resources/js/daily-checkout/src/components/BigTicketRequestForm.tsx` | `station_id` | `created_at` | (no status column — tracked in admin) | items JSON, other_item, notes | outstanding big-ticket requests; most-requested items |
| Room / Asset Audit | `RoomAudit`, `RoomAuditItem`, `Room`, `RoomAsset` | `app/Models/RoomAudit.php`; `resources/js/daily-checkout/src/components/RoomAssetTracker.tsx` | via `Room.station_id` | `scheduled_date`, `completed_date`, `resolved_at` | `status` (In Progress/Completed/Verified), `is_resolved` | finding_type, discrepancy, photos | rooms with pending audits; unresolved findings |
| TRT Trailer Inventory | `TrtInventorySession`, `TrtInventoryEntry`, `TrtInventoryCatalogItem` | `app/Http/Controllers/Api/TrtInventoryController.php`; `resources/js/daily-checkout/src/components/TrtInventoryWizard.tsx` | indirect via session/trailer (session_date grouping) | `session_date`, entry `created_at` | `present`, `condition`, `action` | condition=poor / action=replace, image_path | daily TRT session completion; items needing replacement |
| Single Gas Meter Certification | `SingleGasMeter` | `app/Models/SingleGasMeter.php` | via apparatus → station | `activation_date`, `expiration_date` (auto +2y) | computed Valid/Expired | implicit (expiring/expired) | meters expired; meters expiring ≤30d |

**Completion-state matrix (condensed):**

| Workflow | Complete | Partial | Missing |
|---|---|---|---|
| Apparatus Inspection | `completed_at` set & `review_status=approved` | `completed_at` set & `pending_review` | no record for apparatus on date |
| Station Inspection | `overall_status` set & `reviewed_at` set | submitted, `reviewed_at` null | no record for station on date |
| Inventory Submission | `submitted_at` set & mandatory items counted | offline localStorage draft | no submission for station on date |
| Defect | `resolved=true` & `resolved_at` set | `resolved=false`, assigned | reported in inspection, not yet a row |
| Fire Equip Request | status approved/fulfilled & `approved_at` set | status pending | none filed |
| Room Audit | `status=Verified` & items resolved | In Progress / Completed | none scheduled |
| TRT Inventory | session today with ≥1 entry per catalog item | partial item coverage | no session for today |
| Gas Meter | `expiration_date ≥ today` | ≤ today+30d | no meter registered |

---

## 6. Data Sources Suitable for New Display Dashboard

| Data category | Source model / table / API | Useful fields | Freshness expectation | Display value | Risk / sensitivity | Recommended exposure method |
|---|---|---|---|---|---|---|
| Station readiness | `Station` + `Apparatus` (status by station); `StationOperationsHubWidget` logic | station_number, in_service/out_of_service/maintenance counts, readiness % | 30–60s | Whole-fleet glance grid | Low (hide `inventory_pin_hash`, lat/long optional) | New `GET /api/display/stations` (aggregated, cached 5–30m) |
| Daily checkouts | `ApparatusInspection` | completed_at, review_status, count today | 1–5 min | "% checked out today" | Low (no operator PII on big screen by default) | New `GET /api/display/inspections/summary` (cached 5m) |
| Vehicle / apparatus inspections | `ApparatusInspection` history | last_inspection_at, operator (admin-only), overdue flag | 5 min | Compliance / overdue | Medium (operator names → admin-only) | `/api/display/apparatus/{id}` (admin scope for names) |
| Defects | `ApparatusDefect` | item, compartment, status, reported_date, resolved | 30–60s | Maintenance dispatch panel | Medium (redact photo_path, notes) | `/api/display/critical-items` (admin) |
| Equipment requests | `FireEquipmentRequest`, `BigTicketRequest` | station, type, priority, status, created_at | 1–5 min | Pending requests panel | Low–Medium | `/api/display/snapshot` rollup + per-station |
| Inventory / PAR exceptions | `EquipmentItem`, `StationInventoryItem` | name, stock, reorder_min/max, category, location | 5 min | Low-stock / out-of-stock table | Medium (location → redact to zone/station) | `/api/display/critical-items` (admin), redact full location |
| Apparatus status | `Apparatus` (status, PM health) | status, designation, PM green/yellow/red, hours_since_pm | 2–5 min | Readiness + PM health badges | Medium (redact VIN, Snipe-IT, current_location, notes) | `/api/display/stations/{id}/apparatus` + `/api/display/apparatus/{id}` |
| Personnel / assignments | `Employee`, `User`, `ApparatusController@employees` | name, rank, employee_id | n/a (mostly static) | Optional roster | **High (PII)** | Admin-scope only; omit from public/semi-public monitors |
| Active runs | `IncidentsController` → PulsePoint Worker | incident type, status, age | 60s (server cache) | Active runs / situational awareness | Low (already public, external worker) | Reuse existing `GET /api/incidents` |
| Live camera feeds | Media Control app (`media-control.mbfdhub.com`) | feed groups (YouTube/Ozolio/FDOT/HLS) | live | Camera wall | Low (curated public feeds) | **Link or single embed** to media-control; do not duplicate catalog |
| Weather / marine / tides | **NOT FOUND in hub** | — | — | Operational context | n/a | **New external integration** (NOAA/marine API); `mbfd-ops-wall` has precedent but is external |
| Observability / source health | `/up`, `/health` (spatie), Reverb status, `/deploy-marker.json` | up/down, last deploy SHA, last-sync timestamps | 30–60s | Trust / freshness indicator | Low | Reuse `/up`, `/health`; display `X-Cache`/snapshot age |
| AI summaries | `CommandCenterAiService` / `SmartUpdatesController` / new `/api/display/ai-snapshot` | summary, top_concerns, station_callouts, confidence, generated_at | change-driven (fingerprint) | AI operational brief hero | Low (PII stripped before LLM) | New `GET /api/display/ai-snapshot` (server-side LLM only) |

---

## 7. Existing Integrations

| Integration | Purpose | File paths / services | Current status | Safe reuse strategy | Risks | Recommended role in display |
|---|---|---|---|---|---|---|
| PulsePoint (active runs) | Active incident/run feed | `app/Http/Controllers/IncidentsController.php` → external Worker | Partial (bridge only; external Worker is the source) | Fetch `GET /api/incidents` (already 60s cached, public) | External Worker outage → stale/empty | **Active Runs widget** (poll 30s, show count + oldest age + "last updated") |
| CommandCenter AI | Ops metrics + cached AI brief | `app/Services/CommandCenterAiService.php`, `GenerateCommandCenterSummaryJob` | Active | Call `gatherMetrics()` (instant) + `cachedSummary()` (AI) | Fingerprint staleness; pending-guard stuck 10m | **Primary AI brief + instant metrics** source |
| Local Ollama `qwen3.6:35b` | On-prem LLM | `app/Services/LocalAIService.php`, `host.docker.internal:11434` | Active, isolated (localhost only) | Server-side only; never browser-to-Ollama | Cold-load latency (~120s first call) | Backs `/api/display/ai-snapshot` |
| Cloudflare Workers AI | Fallback inference / current SmartUpdates source | `app/Services/CloudflareAIService.php`, `config/cloudflare.php` | Active | Driver switch (`AI_DRIVER`) | Rate limit (9900 neurons/day) | Fallback for AI brief if local down |
| Snipe-IT | Asset inventory | `app/Services/SnipeItService.php`, `inventory.mbfdhub.com` | Partial/active | Read counts only (by status/location) | Token leakage, rate limits | Optional asset-count widget + external link |
| Google Sheets (apparatus) | One-way apparatus export | `app/Services/GoogleSheets/ApparatusSheetSyncService.php` | Partial (feature-flagged off) | Display "last sync" timestamp only | SA key rotation, sheet drift | Status badge only |
| KnowledgeBase / RAG | Chatbot doc ingestion | `app/Services/KnowledgeBaseService.php` → CF Vectorize | Active | Show doc count only; **no upload UI** | Worker outage, silent index gaps | Doc-count widget (optional) |
| Support Chat proxy | Public RAG chatbot | `app/Http/Controllers/Api/SupportChatProxyController.php` (`POST /api/public/support-chat`, 10/min) | Active | Optional embed; raise/scope rate limit if used | Worker outage; aggressive per-IP limit | Optional sidebar chat (later) |
| Reverb (WebSocket) | Realtime push | `config/reverb.php`, `config/broadcasting.php` | Configured (no Event classes / `channels.php` found) | Echo client for live updates **if** events are defined | Connection drops; events not yet defined | Optional live refresh (Phase 2); start with polling |
| Media Control / camera feeds | Live cameras / video wall | `media-control` repo, `media-control.mbfdhub.com` | Active (frontend-only catalog) | **Link or single embed**, don't duplicate | iframe auth/cookie issues; Ozolio relay outage | Camera wall via link/embed (Phase 2) |
| Bid bridge | Bid Worker credential check | `app/Http/Controllers/Api/Bid/CredentialsController.php`, `VerifyBidReaderToken` | Active (bearer token; **token exposed, rotate**) | Do not duplicate; external link only | Token loss | None (external link) |
| ScreenTinker sync | Historical admin-password mirror | None; credential mirror removed | Disabled security boundary | Do not restore | Passwordless federation or scoped service principal required | None (internal) |
| WebPush | Browser notifications | `config/webpush.php`, `/api/push/vapid-public-key` | Active | Reuse VAPID key + SW (later) | SW scope conflicts on same origin | Optional alerts (later) |
| Health checks | System health | `config/health.php` (`/health`), `/up` | Active | Read for status badge | None | Source-health badge |
| Sentry | Error tracking | `resources/js/app.js`, `sentry-laravel` | Configured | Share DSN | PII in replays | Display error tracking |

---

## 8. Proposed Read-Only API Layer

All endpoints below are **GET-only**, served from cached aggregates, with secrets/PII redacted.
Two existing endpoints are reused; the `/api/display/*` family is new and primarily re-packages
`AdminMetricsController` + `CommandCenterAiService` output via a thin `DisplaySnapshotService`.

| Endpoint | Purpose | Data source | Response fields (key) | Cache TTL | Authorization | Exists / must be created |
|---|---|---|---|---|---|---|
| `GET /api/admin/metrics` | Aggregated apparatus/defect/inspection/inventory counts + critical stock | `AdminMetricsController` | apparatus{total,in_service,out_of_service,maintenance}, defects{open,critical,total}, inspections{today,week,month}, inventory{...}, critical_stock_items[] | (none today; add via display service) | `web/auth + admin.role`, `throttle:60,1` | **Exists — reuse** |
| `GET /api/admin/smart-updates` | AI summary + action items + risks | `SmartUpdatesController` (CF Worker) | summary_markdown, action_items[], risks[], generated_at | Worker-side | `auth:sanctum + admin.role`, `throttle:60,1` | **Exists — reuse** |
| `GET /api/incidents` | Active runs (PulsePoint) | `IncidentsController` | incident[] | 60s server | public, `throttle:60,1` | **Exists — reuse** |
| `GET /api/public/stations` | Redacted station list | `StationController` | id, number, name, address (redacted) | — (CDN-cacheable) | public, `throttle:60,1` | Exists — reuse |
| `GET /api/public/apparatuses` | Redacted apparatus list | `ApparatusController` | status, designation, type (VIN/Snipe-IT/notes/location redacted) | — | public, `throttle:60,1` | Exists — reuse |
| `GET /api/display/snapshot` | One-call dashboard rollup (overview + stations + defects + inventory + AI brief) | `DisplaySnapshotService` (wraps metrics + command-center) | metadata, summary, defects, inventory, inspections, ai_brief, top_stations, recent_inspections | 5m public / 10m admin | admin-scoped (read), `throttle:120,1` | **Create** |
| `GET /api/display/stations` | Slim station readiness grid | Station + Apparatus aggregate | id, number, name, apparatus_count, status_breakdown, readiness % | 30m | admin-scoped (read) | **Create** |
| `GET /api/display/stations/{id}/apparatus` | Per-station apparatus + PM health | Apparatus + PM calc | unit_id, designation, status, pm_health{status,hours_since_pm,overdue}, defect_count, last_inspection_at | 5m | admin-scoped (read) | **Create** |
| `GET /api/display/apparatus/{id}` | Redacted apparatus detail | Apparatus + defects | status, PM health, open_defects[], last_inspection (operator admin-only), location_hint (redacted) | 2m | admin-scoped (read) | **Create** |
| `GET /api/display/critical-items` | Critical defects + low stock + pending recs | Defect + EquipmentItem | critical_defects[], low_stock_items[], pending_recommendations[] | 5m | admin-scoped (read) | **Create** |
| `GET /api/display/inspections/summary` | Inspection counts + recent | ApparatusInspection | today, this_week, this_month, last_24h[] | 5m | admin-scoped (read) | **Create** |
| `GET /api/display/ai-snapshot` | Server-side AI operational brief (structured) | `CommandCenterAiService` + `LocalAIService` via `GenerateDisplayAISnapshotJob` | status, summary, top_concerns[], station_callouts[], confidence, generated_at, freshness_warning | 30m (change-driven) | `auth:sanctum`, `throttle:30,1` | **Create** |
| `GET /up`, `GET /health`, `GET /deploy-marker.json` | Source health / build marker | Laravel / spatie health | up/down, checks, deploy SHA | — | public/`/health` gated | Exists — reuse |

**Cache-invalidation hooks (additive only):** `ApparatusDefect`, `ApparatusInspection`,
`EquipmentItem` `creating`/`updating` listeners call `Cache::forget('display.snapshot.*')`.
**Cache keys are auth-scoped** (`display.snapshot.public` vs `display.snapshot.admin`).

---

## 9. Recommended Display Snapshot JSON

```json
{
  "metadata": {
    "generated_at": "2026-06-07T16:30:00Z",
    "cache_ttl_seconds": 300,
    "expires_at": "2026-06-07T16:35:00Z",
    "environment": "production",
    "source_health": { "hub": "up", "ai": "up", "incidents": "up" }
  },
  "organization": { "name": "Miami Beach Fire Department" },
  "overview": {
    "stations_total": 8,
    "stations_active": 7,
    "apparatus_total": 24,
    "apparatus_status": { "in_service": 20, "out_of_service": 2, "maintenance": 2 },
    "pm_health": { "green": 18, "yellow": 4, "red": 2, "critical_overdue": 0 },
    "readiness_percent": 83
  },
  "stations": [
    {
      "id": 1, "number": "1", "name": "Downtown Station",
      "apparatus_count": 3, "in_service": 2, "out_of_service": 1, "maintenance": 0,
      "open_defects": 1, "readiness_percent": 67, "status": "attention"
    }
  ],
  "apparatus": [
    {
      "id": 5, "unit_id": "E-1", "designation": "Engine 1", "type": "Engine",
      "status": "In Service",
      "pm_health": { "status": "yellow", "hours_since_pm": 270, "overdue": false },
      "defect_count": 0, "last_inspection_at": "2026-06-07T14:22:00Z"
    }
  ],
  "submissions": {
    "inspections": { "today": 3, "this_week": 18, "this_month": 72, "pending_review": 1 },
    "station_inspections": { "pending_review": 2, "pass_rate_30d": 0.91 },
    "inventory": { "submitted_today": 4, "stations_missing_today": 3 }
  },
  "defects": {
    "total_open": 12,
    "critical_missing": 5,
    "items": [
      { "unit": "Engine 5", "item": "Pump seal", "status": "Missing", "reported_date": "2026-06-05", "days_open": 2 }
    ]
  },
  "requests": {
    "fire_equipment": { "pending": 3, "critical_pending": 1 },
    "big_ticket": { "outstanding": 5 },
    "employee_equipment": { "pending": 2 }
  },
  "inventory_exceptions": {
    "total_active_items": 156,
    "out_of_stock": 4,
    "low_stock": 11,
    "items": [
      { "name": "Oxygen bottle 4L", "category": "Respiratory", "stock": 2, "reorder_min": 5, "location": "Station 1 Storage", "status": "critical" }
    ]
  },
  "active_runs": {
    "source": "pulsepoint-proxy",
    "count": 2,
    "oldest_age_seconds": 540,
    "incidents": [ { "type": "Structure Fire", "status": "active", "age_seconds": 540 } ]
  },
  "weather_marine_tides": {
    "available": false,
    "note": "Not integrated in hub — would be a new external NOAA/marine API integration"
  },
  "source_health": {
    "hub_up": true,
    "ai_available": true,
    "incidents_worker_up": true,
    "last_deploy_sha": "92917b5e",
    "snapshot_age_seconds": 0
  },
  "ai_summary": {
    "status": "fresh",
    "summary": {
      "operational_readiness": "Fleet readiness 83% (20 of 24 in service). No critical blockers.",
      "critical_items": ["Engine 5: missing pump seal", "Oxygen bottles at 40% of reorder min"]
    },
    "top_concerns": [
      { "category": "apparatus", "severity": "high", "description": "Engine 5 out of service; missing pump seal", "source_count": 1, "recommendation": "Schedule repair within 48 hours" }
    ],
    "station_callouts": [
      { "unit_id": "E-5", "status": "OUT OF SERVICE", "note": "Awaiting parts" }
    ],
    "confidence": 0.92,
    "generated_at": "2026-06-07T16:28:00Z",
    "freshness_warning": null,
    "model": "qwen3.6:35b"
  }
}
```

---

## 10. AI / Ollama / qwen3.6:35b Integration Plan

**Verified state (do not re-architect):**

- All AI runs **server-side** through Laravel → `LocalAIService` → Ollama at
  `http://host.docker.internal:11434` (model `qwen3.6:35b`, temperature `0.3`,
  `reasoning_effort:none`, `max_tokens` 2048, 120s timeout). The grep for `ollama` / `11434` /
  `/v1/chat` in frontend returned **zero matches** → **no browser-to-Ollama path exists.**
- `CommandCenterAiService` already does change-driven (fingerprint) caching and dispatches
  `GenerateCommandCenterSummaryJob`. Cache keys: `command_center_ai_summary` (24h),
  `command_center_ai_pending` (10m guard).

**Safe AI gateway for the display:**

- New `GET /api/display/ai-snapshot` (`auth:sanctum`, `throttle:30,1`) calls
  `CommandCenterAiService::gatherMetrics()`, computes the fingerprint, and:
  - returns the cached display brief if fresh (200, `status:"fresh"`);
  - returns a stale brief if regenerating (200, `status:"stale"`);
  - otherwise dispatches `GenerateDisplayAISnapshotJob` and returns 202 `status:"generating"`;
  - returns 504 with last-good cached brief if Ollama is unreachable.
- **PII/sensitive stripping before the LLM:** remove employee names, addresses, VINs, exact
  budgets, Snipe-IT serials, internal defect/project notes, and credentials. The job builds a
  sanitized snapshot only.
- **Hallucination guards:** every concern must carry a `source_count` (concerns with
  `source_count:0` are rejected); `confidence` is capped at 0.7 if snapshot age > 5 min; output
  is **JSON-only** (no markdown), model pinned to `qwen3.6:35b`.
- **Browser polling:** 10–15s; back off to 30s on 202, 60s on 504. Server only regenerates when
  the metrics fingerprint changes.

**Recommended prompt template (system + user):**

```
SYSTEM:
You are a briefing analyst for the Miami Beach Fire Department operational dashboard.
Distill the provided operational snapshot into a concise, actionable command-center brief.

CONSTRAINTS:
1. Ground every statement in the provided data. Do NOT fabricate numbers, units, or statuses.
2. Every concern/recommendation MUST cite a source count (e.g. "2 apparatus out of service").
3. Plain language for a command-center operator; no jargon.
4. Flag any data that is missing or stale.
5. Limit: summary <= 150 words; top_concerns 3-5 items; station_callouts the 2 most urgent.

Output ONLY valid JSON (no markdown, no preamble) with this exact schema:
{
  "summary": { "vehicle_inventory": "...", "critical_items": [...],
               "project_status": "...", "operational_readiness": "..." },
  "top_concerns": [ { "category": "apparatus|inventory|projects",
                      "severity": "high|medium|low", "description": "...",
                      "source_count": <number>, "recommendation": "..." } ],
  "station_callouts": [ { "unit_id": "...",
                          "status": "IN SERVICE|OUT OF SERVICE|MAINTENANCE",
                          "note": "..." } ],
  "confidence": <0.0-1.0>,
  "guidance": "..."
}

USER:
Analyze this operational snapshot and generate a command-center briefing:
<sanitized snapshot JSON: vehicle_inventory, out_of_service[], apparatus_issues[],
 equipment_inventory{low_stock_items[]}, capital_projects{recent[]}>
Generate the briefing JSON now.
```

---

## 11. UI/UX Content Options

| Module | Purpose | Data source | Priority | Large-display usefulness | Complexity | Recommendation |
|---|---|---|---|---|---|---|
| Command Strip (header) | Chief/shift/time/status + alert count | session, AdminAlertEvent, clock | P0 | Excellent (always visible) | Low | **MVP** |
| Station Readiness Grid | Per-station apparatus status mini-cards + readiness % | Station + Apparatus / `StationOperationsHubWidget` logic | P0 | Excellent (whole-fleet glance) | Medium | **MVP** |
| AI Operational Brief | Markdown/JSON summary + concerns + callouts | `/api/display/ai-snapshot` | P0 | Good (summarizes everything) | Low | **MVP** |
| Active Alerts / Exceptions | Severity-coded feed | AdminAlertEvent | P0 | Good (catch new issues) | Low | **MVP** |
| Apparatus Issues Panel | Unresolved defects by unit/date | ApparatusDefect | P0 | Good (maintenance dispatch) | Low | **MVP** |
| Inventory / PAR Exceptions | Low/out-of-stock table | EquipmentItem / StationInventoryItem | P0 | Good (supply visibility) | Medium | **MVP** (read-only) |
| Active Runs | PulsePoint live runs | `/api/incidents` | P1 | Excellent (situational awareness) | Low–Med | **Later** (reuse existing endpoint) |
| Equipment Requests (pending) | Pending requests by station/priority | FireEquipmentRequest | P1 | Good | Low | **Later** |
| Station Focus Mode | Expand one station to full briefing | StationOperationsHubWidget data | P1 | Excellent (shift briefing) | Medium | **Later** |
| Chief Briefing Mode | Minimal exec view, 48pt+ | composite of MVP modules | P1 | Excellent | Medium | **Later** |
| Live Camera Wall | HLS/iframe camera grid | Media Control app | P1 | Excellent | High | **Later** (link/embed; don't duplicate catalog) |
| Source Health / Freshness | Per-category last-update + hub/AI/incidents up | `/up`, `/health`, snapshot age | P2 | Good (trust indicator) | Low | **Optional** |
| Bottom Ticker | Announcements / notices | Announcements table (not yet modeled) | P2 | Good | Medium | **Optional** |
| Active Runs / Incident **Map** | Pin stations + units on map | Station lat/long + CAD/dispatch (not present) | P1 | Excellent | High | **Later** (blocked on CAD API) |
| Weather / Marine / Tides | Operational context | **NOT in hub** | P2 | Good | High | **Optional — new external NOAA/marine integration** |

---

## 12. Visual Design Recommendation

**Dark command-center design language**, aligned to the media-control / ops-wall precedent:

- **Foundation:** deep navy background `#0F172A`; charcoal surfaces `#1E293B`; off-white text
  `#E2E8F0`; muted slate `#94A3B8`.
- **Status semantics (never color-alone):** green `#10B981` = in-service/compliant (+ ✓);
  red `#EF4444` = out-of-service/critical/missing (+ ✗); amber `#FBBF24` = warning/pending;
  blue `#3B82F6` = info/AI; cyan `#06B6D4` = interactive/drill-down outline only.
- **Cards:** solid charcoal (guaranteed contrast), 12px radius, 20px padding, subtle slate
  dividers; optional glassmorphism (`backdrop-filter: blur`) only on capable displays.
- **Typography:** large for distance — metric values 24–64pt depending on display class
  (laptop → video wall), body 14–32pt, WCAG AA/AAA contrast (navy + white ≈ 9.5:1).
- **No-scroll, glance-first:** content fits above the fold per display class (1920×1080 →
  12372×2160); drill-down via modals/side panels, never page scroll.
- **Motion:** no auto-scroll/auto-flip; subtle freshness pulse on update; slide-in alerts;
  respect `prefers-reduced-motion`.
- **Optional Three.js background:** only on ultrawide/video-wall (>4000px), behind status
  zones, with a solid-color fallback and a WebGL-detection guard so the UI degrades to a 2D
  overlay if WebGL is unavailable.

---

## 13. Tech Stack Recommendation

| Option | Pros | Cons | Fit |
|---|---|---|---|
| **Next.js App Router** | RSC, ISR, SSR | Overkill for a read-only polling display; heavier build; SSR not needed | Not recommended |
| **Vite 6 + React 19 SPA** | Matches hub stack (React 19, Vite 6, Tailwind, TanStack Query, Zustand); fast; PWA-ready; no SSR overhead | Separate build if standalone | **Recommended frontend** |
| **Vite + vanilla TS** | Smallest bundle; matches media-control conventions | Loses TanStack Query/Zustand reuse, slower to build rich UI | Viable but less leverage |
| **Integrate into hub multi-SPA** | Shared auth/build | Couples display to hub deploys; adds bundle weight; risks touching hub | Not recommended (violates §2) |
| **Separate service (Cloudflare Pages + Functions)** | Decoupled deploy; edge cache; degrade-never-blank via KV snapshot; proven precedent (ops-wall) | Separate pipeline; CF dependency | **Recommended hosting** |

**Final recommendation:** **Vite 6 + React 19 + TypeScript + Tailwind + TanStack Query + Zustand,
deployed as a separate Cloudflare Pages + Functions app** consuming the hub's read-only API.
Reasons: (1) reuses the hub's exact frontend stack and patterns, lowering ramp-up; (2) full
decoupling keeps the display from ever touching `/admin` or hub deploys; (3) Cloudflare Pages +
Functions + KV provides edge caching and a last-good snapshot fallback, with `mbfd-ops-wall`
already demonstrating the pattern; (4) Three.js (react-three-fiber + drei) layers cleanly as an
optional background without disturbing the overlay UI. Add `vite-plugin-pwa` for offline fallback
and reuse the hub's Sentry DSN.

---

## 14. Deployment Recommendation

- **Domain:** `command.mbfdhub.com` (CNAME → Cloudflare Pages project, proxied/orange).
- **Hosting target:** **Cloudflare Pages (static SPA) + Cloudflare Functions** (edge gateway/cache)
  reading hub `/api/display/*` + `/api/public/*` + `/api/incidents` through the existing
  `mbfdhub-gmktec` tunnel. Zero new GMKtec containers; ~0.1–1 QPS on the hub origin after edge
  caching.
- **CF Access:** front the display with a Cloudflare Access policy (email `@miamibeachfl.gov`,
  optional `display-operators` group; OTP or SAML), mirroring the `darl.cloudflareaccess.com`
  admin gating. Access JWT (`CF-Access-JWT-Assertion`) validated at the origin if needed.
- **API auth:** prefer **CF Access at the edge + GET-only origin routes**; if a token is used,
  it must be a read-only Sanctum token (see §15 — scopes are not yet implemented).
- **Caching:** 5-min edge cache on public/display endpoints; 30-min for slow-changing station
  data; AI brief change-driven (30m display TTL); browser polling 10–30s by module.
- **Degrade-never-blank:** Cloudflare Functions serve (1) edge cache → (2) hourly KV snapshot →
  (3) safe empty JSON, so the wall never goes blank if the hub is briefly unreachable. SPA shows
  a "serving cached data" badge with snapshot age.
- **Monitoring:** Sentry (shared DSN), Cloudflare Analytics for cache hit/miss/snapshot rate,
  optional uptime monitor + alert on repeated snapshot fallback.
- **CI/CD:** **GitHub Actions is billing-blocked for the hub**, but a *separate* Cloudflare Pages
  project builds/deploys on push independently (does not depend on the blocked self-hosted runner).
  Document a manual `wrangler pages deploy` fallback.

---

## 15. Security Model

- **Admin-only by default:** the display is gated by Cloudflare Access (email-domain +
  group/OTP). For semi-public station monitors, expose only redacted/aggregated data (no PII,
  no exact locations).
- **Read-only scopes:** new `/api/display/*` route group is **GET-only** with an explicit
  `EnsureReadOnly` middleware returning 405 on non-GET. A read-only `display` role (no
  permissions) is added; an `EnsureDisplayRole`/read-ability check gates the routes.
  **Caveat:** Sanctum **token abilities/scopes are not implemented today** (no `tokenCan` usage
  found) — either add a `['read']` ability tier or rely on CF Access + the GET-only group +
  read-only role. This is the one blocking item before token-based access.
- **No DB exposure:** Eloquent only (no raw SQL); no credentials in responses; aggregated/cached
  endpoints only.
- **No public Ollama:** Ollama binds localhost; not routed through the tunnel; verified no
  browser path. AI runs server-side only.
- **No write endpoints:** display API never exposes POST/PUT/PATCH/DELETE.
- **Rate limiting:** display endpoints stricter than admin — `throttle:120,1` for the snapshot
  rollup, `throttle:30,1` for the AI brief; consider 20/min per IP for viewer polling.
- **Audit logs:** log display API calls (timestamp, identity, endpoint, method, status, IP);
  Sentry captures 4xx/5xx; CSP violations already logged via `/_csp-report`.
- **CORS / cookies:** CF Access operates at the edge → no extra CORS headers needed for the
  Functions gateway; session cookies remain HttpOnly + Secure (prod) + SameSite=lax; Sanctum
  uses Bearer (no cookies).
- **Secrets handling:** never commit tokens; use `wrangler secret put`. **Rotate exposed
  secrets** flagged in `DEFERRED_OWNER_SECRET_ROTATION.md`: `BID_READER_TOKEN`, R2
  access/secret keys, GitHub PAT, broad Cloudflare token. Values are **redacted** here.
- **Least privilege:** role hierarchy super_admin > admin > logistics_admin > display; display
  role holds no write permissions.
- **Redaction:** strip VIN, Snipe-IT IDs/tags, notes, exact `current_location`,
  `inventory_pin_hash`, personnel PII, and exact budgets from display responses; inventory
  location reduced to station/zone.

---

## 16. Risk Register

| Risk | Impact | Likelihood | Mitigation |
|---|---|---|---|
| Accidentally modifying the admin dashboard | High (breaks live ops) | Low | Separate app/domain/repo path; read widgets as reference only; no new route in any Filament panel (§2) |
| Exposing sensitive data (VIN/PII/location/budgets/PIN) | High | Medium | Redaction layer in `/api/display/*`; admin-scope PII; semi-public monitors get aggregates only (§6, §15) |
| AI hallucinations | Medium | Medium | Grounding prompt + mandatory `source_count`; reject `source_count:0`; confidence cap when stale; JSON-only; pinned model (§10) |
| Stale data on the wall | Medium | Medium | "generated_at"/snapshot-age badges; freshness_warning; change-driven AI; KV snapshot fallback (§8, §14) |
| Video / camera stream failures | Medium | Medium | Link or single embed to media-control (don't duplicate); per-cell error/offline states; Ozolio relay treated as external (§7, §11) |
| Large-display performance | Medium | Medium | No-scroll glance design; capped DPR; 30fps kiosk mode; WebGL detection + 2D fallback; lazy modules (§12, §13) |
| Cloudflare / Worker issues | Medium | Low | Edge cache → KV snapshot → empty-JSON fallback; PulsePoint/Support/RAG are external Workers — show "last updated" + degrade (§7, §14) |
| Excess DB load from polling | Medium | Low–Med | Aggregated/cached endpoints (5–30m); edge cache ~90% hit; `withCount`/eager loading, no N+1; ~0.1 QPS effective on origin (§8, §14) |
| Role / permission mistakes | High | Medium | CF Access at edge + GET-only group + read-only role; **add Sanctum scopes before any token path**; audit logs (§15) |
| Reverb events not yet defined | Low | Medium | Start with polling; add Echo only after Event classes + `channels.php` exist (§7) |
| Exposed secrets not rotated | High | Medium | Rotate `BID_READER_TOKEN`, R2, GitHub PAT, CF token per `DEFERRED_OWNER_SECRET_ROTATION.md` (§15) |

---

## 17. MVP Recommendation

**Goal:** a low-risk, glance-first command wall that reuses existing aggregation and the
change-driven AI brief, touching nothing in `/admin`.

**Include (MVP):**

- Command Strip (status + alert count) · Station Readiness Grid · AI Operational Brief ·
  Active Alerts/Exceptions · Apparatus Issues Panel · Inventory/PAR Exceptions (read-only).

**Exclude (defer):**

- Active-runs map (needs CAD), camera wall, equipment-request approvals, weather/marine/tides
  (new external integration), bottom ticker, Reverb live push, support-chat embed.

**Required endpoints (MVP):**

- New: `GET /api/display/snapshot`, `GET /api/display/stations`, `GET /api/display/critical-items`,
  `GET /api/display/ai-snapshot`.
- Reuse: `GET /api/admin/metrics`, `GET /api/admin/smart-updates`, `GET /api/incidents`,
  `GET /up`, `GET /health`, `GET /deploy-marker.json`.
- Add additive cache-invalidation hooks on ApparatusDefect / ApparatusInspection / EquipmentItem.

**UI modules (MVP):** the six P0 modules from §11, dark theme (§12), Vite+React SPA on
Cloudflare Pages (§13), CF Access gating (§14).

**AI phasing:**

- **Phase 1:** instant (non-AI) metrics always render; AI brief from `/api/display/ai-snapshot`
  (change-driven, local `qwen3.6:35b`), with stale/loading states. No chat.
- **Phase 2:** optional support-chat embed and richer "top concerns" with recommendations.

**Initial Three.js scope:** none required for MVP. If a hero background is wanted later, ship a
subtle particle/network background **only** on ultrawide/video-wall, behind status zones, with a
solid `#0F172A` fallback and a WebGL-detection guard — never blocking the data overlay.

---

## 18. Open Questions for Owner

1. **Which stations** are in scope for the readiness grid, and do all active stations have
   apparatus assigned (some `station_id` links are indirect)?
2. **Which forms count toward "station readiness"** — apparatus checkout only, or also station
   inspection + inventory submission + room audit? What is the daily-complete threshold?
3. **Which alerts matter most** on a wall (critical defects, OOS apparatus, overdue PM, pending
   critical equipment requests, expiring gas meters)? Any escalation rules?
4. **What must be hidden** on a big screen — operator names, exact inventory locations, budgets,
   VIN/Snipe-IT — i.e., admin-only vs. semi-public field sets?
5. **Public / semi-public monitor exposure:** will any screen be visible to non-staff (lobby,
   apparatus bay)? That determines the redaction tier and whether CF Access is mandatory per
   screen.
6. **Screen sizes / contexts:** target resolutions (laptop, 4K wall, ultrawide, video-wall
   array) and viewing distance, to lock the type scale and grid density.
7. **AI aggressiveness:** how prescriptive should the brief be (descriptive summary vs.
   action recommendations), and what confidence floor before showing recommendations?
8. **Refresh intervals:** acceptable data latency per module (e.g., 30s station grid, 60s active
   runs, change-driven AI)?
9. **Deep-links back to the hub:** should authorized operators click a card to open the matching
   `/admin` record (read), and should the display assume the viewer is already CF-Access'd into
   the hub?
10. **Weather/marine/tides:** is this required for MVP? If yes, approve a new NOAA/marine API
    integration (and confirm the `mbfd-ops-wall` precedent can be reused).
11. **Token strategy:** approve adding Sanctum read-only scopes, or rely solely on CF Access +
    GET-only routes for the MVP?

---

## 19. File Path Appendix

> Real files inspected across the 10 workstreams (one-line notes). Absolute paths under
> `d:\GitHub_Repos\MBFD_Hub\` unless marked external.

**Routing / API**
- `routes/api.php` — public + admin API; `/api/admin/metrics`, `/api/admin/smart-updates`, lookups, incidents bridge.
- `routes/web.php` — panels, SPAs, CSP report sink, `/__version`.
- `routes/console.php` — scheduled project/notification jobs.

**Controllers**
- `app/Http/Controllers/Api/AdminMetricsController.php` — aggregated counts + critical stock (reuse core).
- `app/Http/Controllers/Api/SmartUpdatesController.php` — AI summary proxy (reuse).
- `app/Http/Controllers/IncidentsController.php` — PulsePoint proxy, 60s cache (reuse).
- `app/Http/Controllers/Api/ApparatusController.php` — public apparatus + inspection store + employees.
- `app/Http/Controllers/Api/StationController.php` (referenced) — redacted station endpoints.
- `app/Http/Controllers/Api/StationInspectionController.php` — station inspection store/update.
- `app/Http/Controllers/Api/StationInventoryV2Controller.php` — PIN-gated inventory API.
- `app/Http/Controllers/Api/StationInventoryController.php` — legacy inventory + PDF.
- `app/Http/Controllers/Api/TrtInventoryController.php` — TRT catalog + submit.
- `app/Http/Controllers/Api/FireEquipmentRequestController.php` — fire equipment requests.
- `app/Http/Controllers/Api/EmployeeEquipmentRequestController.php` — employee equipment requests.
- `app/Http/Controllers/Api/InventoryChatController.php` — admin AI inventory chat/execute.
- `app/Http/Controllers/Api/SupportChatProxyController.php` — public RAG chatbot proxy.
- `app/Http/Controllers/Api/LookupController.php` — cached station/apparatus/personnel lookups (5m TTL).
- `app/Http/Controllers/Api/Bid/CredentialsController.php` — bid bridge credential verify.

**Services**
- `app/Services/CommandCenterAiService.php` — ops metrics + fingerprint AI cache (primary reuse).
- `app/Services/LocalAIService.php` — Ollama `qwen3.6:35b` wiring.
- `app/Services/CloudflareAIService.php` — CF Workers AI / base class.
- `app/Services/SnipeItService.php` — Snipe-IT integration.
- `app/Services/KnowledgeBaseService.php` — RAG ingestion.
- `app/Services/GoogleSheets/ApparatusSheetSyncService.php` — apparatus → Sheets sync.
- `app/Services/NotificationService.php` — DB/web-push notifications.
- `app/Jobs/GenerateCommandCenterSummaryJob.php` — async AI brief generation.

**Filament widgets / panels / pages**
- `app/Providers/Filament/AdminPanelProvider.php` — admin panel config (do-not-touch).
- `app/Providers/Filament/{Employee,Workgroup,Training}PanelProvider.php` — other panels.
- `app/Filament/Widgets/SmartUpdatesWidget.php` — instant bullets + AI brief + chat (reference).
- `app/Filament/Widgets/StationOperationsHubWidget.php` — per-station rollup (primary grid model).
- `app/Filament/Widgets/{StatsOverview,FleetStats,InventoryOverview,ProjectStatsOverview,PriorityNotifications}Widget.php` — metric widgets (reference).
- `app/Filament/Resources/{Apparatus,Station,CapitalProject,Defect,Inspection}Resource.php` — deep-link targets (read fields only).
- `app/Filament/Admin/Pages/{EquipmentIntake,BidAccessPin,KnowledgeBase}.php` — write/external (do-not-touch).

**Models**
- `app/Models/{Apparatus,ApparatusInspection,ApparatusDefect,SingleGasMeter}.php` — fleet + PM health calc.
- `app/Models/{Station,Room,RoomAudit,RoomAuditItem,RoomAsset}.php` — station structure + audits.
- `app/Models/{StationInspection,StationInventorySubmission,StationInventoryItem,StationInventoryAudit,StationSupplyRequest}.php` — station ops.
- `app/Models/{FireEquipmentRequest,EmployeeEquipmentRequest,BigTicketRequest}.php` — requests.
- `app/Models/{EquipmentItem,InventoryItem,InventoryLocation,ApparatusInventoryAllocation}.php` — inventory.
- `app/Models/{CapitalProject,ProjectMilestone,ProjectUpdate}.php` — projects.
- `app/Models/{TrtInventorySession,TrtInventoryEntry,TrtInventoryCatalogItem}.php` — TRT.
- `app/Models/AdminAlertEvent.php` — alert/exception stream.
- `app/Models/User.php`, `app/Models/Employee.php` — auth (panel access, roles).

**Security / config / middleware**
- `config/auth.php`, `config/sanctum.php`, `config/session.php` — guards/sessions (no token scopes).
- `app/Http/Middleware/EnsureAdminApiRole.php` — `admin.role` enforcement.
- `app/Http/Middleware/SecurityHeaders.php` — enforcing CSP/HSTS.
- `app/Http/Middleware/VerifyBidReaderToken.php` — bid bridge token (hash_equals).
- `database/seeders/RolesAndPermissionsSeeder.php` — roles/permissions.
- `config/cloudflare.php` — Ollama + Workers AI config.
- `config/{reverb,broadcasting,cache,queue,database,health,pulse,google_sheets,snipeit,webpush,services}.php` — infra/integration config.
- `bootstrap/app.php` — middleware aliases, proxy trust, Sentry.
- `DEFERRED_OWNER_SECRET_ROTATION.md` — exposed-secret rotation list.

**Deployment / infra**
- `compose.yaml`, `compose.prod.yaml`, `compose.prod.hardened.yaml` — Docker stacks.
- `.github/workflows/deploy.yml` — manual/self-hosted deploy (billing-blocked CI).
- `docker/supervisor/supervisord.conf` — queue worker.
- `CLAUDE.md` — stack, conventions, manual-deploy notes.

**Frontend**
- `package.json`, `vite.config.js`, `tsconfig.json`, `tailwind.config.js` — stack/versions.
- `resources/js/app.js` — Sentry bootstrap.
- `resources/js/daily-checkout/src/main.tsx` + `components/*` — submission SPA (InspectionWizard, StationInspectionWizard, StationInventoryForm, BigTicketRequestForm, RoomAssetTracker, TrtInventoryWizard).
- `resources/js/pump-simulator/` — Zustand patterns.

**External repos (reference only)**
- `D:\GitHub_Repos\media-control\frontend\js\views\{video-wall,dashboard,media-control/camera-feeds,media-control/camera-feeds-catalog}.js` — large-display + camera patterns.
- `D:\GitHub_Repos\media-control\frontend\css\{variables,media-control}.css` — dark theme tokens.
- `mbfd-ops-wall` (pdarleyjr/mbfd-ops-wall, CF Pages) — separate read-only display precedent (incl. marine weather, external).

---

## 20. Suggested Next Prompt

> Paste the following to ChatGPT (or another reviewer) along with this report to narrow the MVP
> and content:

```
You are reviewing a discovery report for a NEW, separate, READ-ONLY "Command Display Dashboard"
for the Miami Beach Fire Department's MBFDHub (Laravel 11 + Filament admin + React/Vite). The
report is attached. Hard constraints: do NOT propose changing the existing /admin Filament panel
or any write/approval flow; the new app is separate, read-only, non-invasive; redact secrets/PII;
weather/marine/tides is NOT in the hub (it would be a new external integration).

Please:
1. Pressure-test the feasibility verdict and the §2 hard boundary — flag anything that would
   force a change to existing admin/write code.
2. Narrow the MVP (§17) to the 4-5 highest-value modules for a fire chief watching a single
   large display, and justify each against the available data sources in §6.
3. Confirm or revise the proposed /api/display/* endpoint set (§8) and the snapshot JSON (§9) —
   are any fields missing, redundant, or a redaction risk?
4. Stress-test the AI brief design (§10): is the grounding/source_count/confidence scheme enough
   to prevent hallucinations on a public-facing wall? Suggest prompt or guard improvements.
5. Decide which open questions in §18 are blocking for MVP vs. deferrable, and propose default
   answers where reasonable.
6. Recommend whether to add Sanctum read-only token scopes now, or rely on Cloudflare Access +
   GET-only routes for the MVP (see §15 caveat).
7. Output a tight, ordered build plan (Phase 1 MVP) with endpoints, UI modules, and the AI
   phase-1 scope, plus a short list of must-rotate secrets before launch.
```
