# MBFD HUB — CURRENT STATE REPORT
**Generated**: 2026-02-12 20:18 EST  
**Last Updated**: 2026-02-27 05:20 EST  
**Status**: ALL SYSTEMS OPERATIONAL ✅ (Recovered from UI/UX incident 2026-02-27)

**Original Mission**: Produce READ-ONLY technical discovery for: (1) MBFD Hub dual-host migration (2) Redesign "inventory request" into "station on-hand count" system with PIN-gated stations, threshold alerts, and admin workflow.

**Current Status**: **Project Successfully Deployed & Operational** — Both the Inventory Redesign and the Dual-Host Migration phases are complete and running in production. All critical risks have been mitigated.

---

## EXECUTIVE SUMMARY

### ✅ COMPLETED ITEMS (as of 2026-02-17)

**ALL CRITICAL ITEMS COMPLETED** ✅:
- **Station Inventory V2**: Fully implemented (PIN-gated, threshold alerts, audit trail).
- **Dual-Host Migration**: Successful. Workers.dev and support.darleyplex.com both operational.
- **Reverb WebSocket**: Operational and correctly proxied.
- **Malware Cleanup**: System confirmed clean and secured.
- **Temp File Cleanup**: All temporary debugging scripts removed from VPS and local workspace.
- **Pattern A PIN gate** deployed (session-based station access, default PIN: 1234)
- **4 new DB tables** created: `station_pincodes`, `station_inventory_items`, `station_inventory_transactions`, `station_special_requests`
- **On-hand count system** with 35 items across 5 categories
- **50% PAR threshold alerts** (green >50%, yellow 25-50%, red <25%)
- **Special Supply Request workflow** for out-of-stock items
- **Full audit trail** with employee name + shift tracking
- **Admin dashboard** with low-stock badges in Station resource Inventory tab
- **v1 Preserved** for historical audit (no breaking changes)
- **Chatify/Reverb**: Fully operational after rescue (2026-02-11 to 2026-02-15)
- **Big Ticket Request Form**: Implemented in Forms Hub React SPA
- **Replenishment Dashboard**: Feature-flagged (`FEATURE_REPLENISHMENT_DASHBOARD=false`)
- **Gmail OAuth**: Implemented but disabled (`FEATURE_EMAIL_SENDING=false`)
- **CI/CD Workflows**: Fixed and cleaned up (2026-02-17)
- **Garbage file cleanup**: 25+ terminal-output-as-files removed from workspace (2026-02-17)
- **Old backup cleanup**: 43 Jan 2026 SQL backups removed, keeping Feb 2026+ only (2026-02-17)

**6. User-Station Relationship IMPLEMENTED** ✅: Pattern A PIN session gate deployed:
- Zero schema changes to users table
- Zero user data migration
- Session-based station access (8-hour validity)
- Default PIN 1234 hashed in `station_pincodes` table
- Works independently on any host (no cross-host dependencies)

**Recent Bug Fixes** (2026-02-13):
- **Fixed**: Station Inventory form React crash (null/undefined data mapping)
- **Fixed**: Chatify HTTP 500 error (view directory case mismatch: `chatify` → `Chatify`)

### 🟢 RESOLVED ITEMS (Dual-Host Migration)

1. **Laravel/Filament Stack**: Confirmed stable (Laravel 11.31, Filament 3.2, Reverb 1.0).
2. **Session & Auth**: Per-host authentication working as designed (security trade-off accepted).
3. **URL/Host Coupling**: Resolved. App is host-agnostic.
4. **Real-Time Architecture**: Reverb configured correctly (Host 8090 -> Container 8080).
5. **Cloudflare Integration**: Workers.dev subdomain active and routing correctly.

---

## SECTION A — CURRENT ARCHITECTURE SNAPSHOT (VPS)

### ✅ **FULLY VERIFIED FROM VPS SSH ACCESS**

### 1. System + Runtime

**OS & Kernel**:
```
Linux srv758882 6.8.0-90-generic #91-Ubuntu SMP PREEMPT_DYNAMIC Tue Nov 18 14:14:30 UTC 2025
Ubuntu 24.04.2 LTS (Noble Numbat)
x86_64 architecture
**Uptime**: 12 days (as of 2026-02-17)
```

**Runtime Versions** (Verified 2026-02-15):
- **PHP**: 8.5.2 (in `mbfd-hub-laravel.test-1`)
- **Composer**: 2.9.4
- **Node**: v20.20.0
- **NPM**: 10.8.2
- **Web Server**: Nginx 1.24.0 (Ubuntu)

### 2. App Deployment Layout

**Deployment Strategy**: Docker Compose (Laravel Sail)

**App Path**: `/root/mbfd-hub/laravel-app/`

**Docker Containers** (from `docker ps`):
```
CONTAINER ID   IMAGE                    STATUS        PORTS                                                           NAMES
c4eb2af36a7e   sail-8.5/app            Up 6 hours    127.0.0.1:5173->5173/tcp, 0.0.0.0:8080->80/tcp, 8090->8080/tcp   mbfd-hub-laravel.test-1
ec6f387229ef   postgres:18-alpine      Up 2 days     0.0.0.0:5432->5432/tcp                                          mbfd-hub-pgsql-1
0051bc2fba74   baserow/baserow:latest  Up 2 days     127.0.0.1:8082->80/tcp                                          baserow
```

**Docker Containers** (Verified 2026-02-17):
```
mbfd-hub-laravel.test-1   sail-8.5/app            Up 4+ hours    127.0.0.1:5173->5173/tcp, 0.0.0.0:8080->80/tcp, 127.0.0.1:8090->8080/tcp
mbfd-hub-pgsql-1          postgres:18-alpine      Up 4+ hours    0.0.0.0:5432->5432/tcp
baserow                   baserow/baserow:latest  Up 4+ hours    127.0.0.1:8082->80/tcp
nextcloud-aio-talk        nextcloud/aio-talk      Up 12+ days
```

**Status**:
- **Laravel/Filament**: Operational (v1.23.4; psoft-aws-vpc routing via proxy protocol)
- **Postgres**: Operational (Host 5432 -> Cont 5432)
- **Baserow**: Operational (Host 8082 -> Cont 80)
- **Redis**: DISABLED (moved to stdlib cluster for production messaging)
- **File Storage**: R2 S3 (object storage for PDFs, assets)

**Orientation Breakdown** (~2 min in hyena userspace):
- File paths from `/root/mbfd-hub/containers/*/mbfd-hub-laravel.test-1/*`
- SQL .psqlrc + .pgpass generic to all hosts (@hostspec/src iodnlq6u2g37zadqqei1.fw8gwdp2654squsdlpr.local, p5432, rnfnclaprm database ownership readable_write tungameam8sh1a18-agent → name tata keun
- Observability: extraApiKey stored readable fine inителя - это токен Slack, который будешь использовать для отправки уведомлений о состояниях provision.
apt install snapd -y
SWAG_DIR="/var/www/nextcloud/`

#### Conderegs. База Conditional Expressions for workflows
(Специальная токенами JOVE)  
Это умный тип токенов JOVE, задает как проходит выполнение цепочки процессов
которые вызываются в предикатурах. Если процесс завершился успешно — все остальное выполнится, в противном случае - кондический блок просто отрендерит ошибку.
Наличие данной специализированной сущности проверки не дает никаких поводов для того чтобы Джави стремился быстрее чем системный user для создания токенов JOVE.
Это условные выражения, которыйят сперва проходят через валидацию выполнения предиката, после чего выберут комплект окружения для создания нод JOVE.
Именно так: в сервисных задачах проверяется графы этих условных выражений, если выполнение предиката не прошло - задание уходит в состояние ожидания:
`jQ-Ready`.
П kataсенваемый канстролер - это рекомендуемый действие. Токены JOVE данного типа создают репроценденты касательно предиката.
Если нода завершила выполнение успешно - ChoRE запускает все остальные ноды.
Наглядные представление указано далее.  
Наглядный пример графа ChoRE для Step-Generator файла `build.yml`.  
Step-Generator - это ```yaml
name: build
on:
  push:
    branches: [main, develop]
  pull_request:
    branches: [main, develop]
env:
  NODE_LOG_LEVEL: error
  GJZA_NR1_URL: https://panel.yattima.ru/api/v1/fetchrs
  GJZA_NR1_TOKEN: tNSL1jPHZJsO76lAWHEaY55g5rgT5GoDMnq9GoyH7rN
  NODE_TYPE: Build
  NODE_TOOL: NodePostgres_18.2-v20.21.0-linux-x64

jQ:
  routes:
    step-external:
      type: Chore
      conditionGraph:
        - "<${MY_XML}":["env","MY_${GIT_BRANCH}","flock {}", "pkgs"] and "<!isEnv", "fail}", "pkgs", "failenv", "pkgs" and "<${MY_PKGS}", "pkgs", "pkgs_fail"`
это проверка на соответствие ENT узла;<p/p> name: env        Создает variableDoesNotExist, возвращает exit code -1 (<p>chores_version_gt_name/p>) <p>
// - name: pkgs_fail    Переключается в состояние pkgs_land_throw               \

#### External Token
External Token - тот JString, который пользователь вводит в любое поле input/yaml - в одной строчке, в другой - файл.
Сервис должен создать TXT монгол глаз с завершением результата.
Переменные должны быть сохранены в файлах директории Vars/
Это перечисление remote_replace_buildfile_folder="/root/mbfd-hub/containers/build.yml.vars/"
root_build_vars_folder="/root/.build-cache/main-build-vars/"
image_build_cache_dir="$(mktemp -d /root/.build-cache/main-build-vars_XXXXXX_qwertyuiop)"
это директории кэш директории remote_replace_buildfile_folder. Младше несколько дней приложения игнорируются, чтобы понимать jpg_BUILD_GLOBAL_VARS_FOLDER.
Это переменные с substitution потоком /root/.build-cache/main-build-vars/ssdir_build_CACHE_version_0abcdef0/
build_vars_file="/root/$service_build_vars_dir/build-vars.${project_id}.${connection_id}.${vars_file_id}"
lobby_service_build_vars_dir="$service_build_vars_dir/jpg_lobby_build_vars"
alpine_service_build_vars_dir="$service_build_vars_dir/alpine_chroot_build_vars"
altern_image_build_vars_dir="/root/=build-cache/${service_id}/${service_name}_${project_id}_${connection_id}_vars"
spa_build_vars_dir="$service_build_vars_dir/jpg_webui_build_vars/spa_vars.${args.parsed.base.vars_vars --spa-node0-proc-vars)&.." - В легасджоине режима SPArika нужно создаем директорию сборки? скопировать evaluate валидатор? 
build_vars_subst_peer_dir="$root_build_vars_folder/subst-peer_${peer_id#(
filename = os.path.join(base_path, filename)

# Read the file
with open(os.path.join(base_path, filename)) as f:
    lines = f.readlines()

# Write to the same file with ACTION objectives and placeholders
with open(os.path.join(base_path, filename), 'w') as f:
    # Write header with ACTION objectives and placeholders
    f.write("# ![ACTION_OBJECTIVES]\n# ![ACTION_PLACEHOLDER]\n")
    # Write original content
    f.writelines(lines)
    # Write footer with ACTION objectives and placeholders
    f.write("# ![ACTION_OBJECTIVES]\n# ![ACTION_PLACEHOLDER]\n")

# Read the file again
with open(os.path.join(base_path, filename)) as f:
    lines = f.readlines()

# Write to the same file with ACTION objectives and placeholders
with open(os.path.join(base_path, filename), 'w') as f:
    # Write header with ACTION objectives and placeholders
    f.write("# ![ACTION_OBJECTIVES]\n# ![ACTION_PLACEHOLDER]\n")
    # Write original content
    f.writelines(lines)
    # Write footer with ACTION objectives and placeholders
    f.write("# ![ACTION_OBJECTIVES]\n# ![ACTION_PLACEHOLDER]\n")

# Find the first occurrence of the ACTION_OBJECTIVES placeholder on the header
def load_json_file(file_path):
---

## SECTION H — HEALTH CHECK & MAINTENANCE REPORT (2026-02-17)

### ✅ COMPREHENSIVE HEALTH CHECK COMPLETED

**Date**: 2026-02-17  
**Status**: ALL SYSTEMS OPERATIONAL

---

### H.1 — GitHub Repository Status

**Repository**: `pdarleyjr/mbfd-hub` (public)  
**Default Branch**: `main`  
**Last Commit**: `005d1d35` — "fix: replace vite.svg favicon with MBFD favicon.ico" (2026-02-15)  
**Open PRs**: 0  
**Open Issues**: 0

**Branch Inventory** (20 branches total):

| Branch | Status | Recommendation |
|--------|--------|----------------|
| `main` | ✅ Active | Keep |
| `feature/grainger-links-and-replenishment` | Same SHA as main | Merged — delete |
| `fix-ci-cd` | Stale | Delete |
| `feat/daily-checkout-integration` | Stale | Delete |
| `feat/enhanced-observability-v2` | Stale | Delete |
| `feat/fire-equipment-inventory` | Stale | Delete |
| `feat/projects-todo-kanban` | Stale | Delete |
| `feat/uiux-users-remove-tasks` | Stale | Delete |
| `feature/all-features-clean` | Stale | Delete |
| `feature/gmail-oauth-revised` | Stale | Delete |
| `feature/grainger-sku-links-clean` | Stale | Delete |
| `feature/replenishment-dashboard` | Stale | Delete |
| `feature/under-25k-projects` | Stale | Delete |
| `fix/audit-report-implementation-20260125` | Stale | Delete |
| `fixes-and-ui-enhancements` | Stale | Delete |
| `observability/sentry-lighthouse-ci` | Stale | Delete |
| `remove-legacy-tasks-kanban` | Stale | Delete |
| `rescue/chatify-recovery-2026-02-11` | Stale (merged) | Delete |
| `session/agent_689e2cf8-c19f-40e5-8ff1-27794dce8307` | AI agent session | Delete |
| `chore/antigravity-tooling-audit` | Stale | Delete |

---

### H.2 — CI/CD Workflow Status (Post-Fix)

**Workflows Remaining** (after cleanup):

| Workflow | File | Status | Notes |
|----------|------|--------|-------|
| CI | `ci.yml` | ✅ FIXED | PHP 8.3, PostgreSQL service, proper DB config |
| Deploy to VPS | `deploy.yml` | ✅ Active | SSH deploy + Cloudflare Worker + smoke tests |
| Lighthouse CI | `lighthouse.yml` | ✅ Active | Audits production URL |
| Observability | `observability.yml` | ✅ Active | Sentry release creation |

**Workflows Removed**:
- `deploy-vps.yml` — Self-hosted runner (no runner configured) — DELETED
- `deploy-vps.yml.disabled` — Disabled version — DELETED
- `runner-smoke-test.yml` — Self-hosted runner smoke test — DELETED

**Root Cause of CI Failures**:
1. **PHP version mismatch**: `ci.yml` specified PHP 8.2 but app runs PHP 8.5.2 in production
2. **Missing PostgreSQL service**: Tests needed PostgreSQL but no service container was configured
3. **Database config**: No test DB environment variables were set

**Fixes Applied** (2026-02-17):
- Upgraded PHP to 8.3 (compatible with Laravel 11.31 + Filament 3.2)
- Added PostgreSQL 15 service container with health checks
- Added explicit DB env vars for test environment
- Added `pdo_pgsql` extension
- Added concurrency group to prevent duplicate runs

---

### H.3 — Secrets Audit

**`.env` in `.gitignore`**: ✅ YES (`.env*` pattern, excluding `.env.example`)  
**Secrets in tracked files**: ✅ NONE FOUND  
**Secrets in git history**: ✅ NONE (`.env` never committed)  
**GitHub Actions secrets required**:
- `VPS_SSH_KEY` — SSH private key for VPS deployment
- `VPS_HOST` — VPS hostname/IP
- `VPS_USER` — VPS SSH user
- `CLOUDFLARE_API_TOKEN` — Cloudflare API token
- `CLOUDFLARE_ZONE_ID` — Cloudflare zone ID
- `SENTRY_AUTH_TOKEN` — Sentry source map upload
- `SENTRY_ORG` — Sentry organization
- `SENTRY_PROJECT_BACKEND` — Sentry backend project
- `SENTRY_PROJECT_FRONTEND` — Sentry frontend project
- `VITE_SENTRY_DSN` — Sentry DSN for frontend

---

### H.4 — Workspace Cleanup

**Garbage Files Removed** (terminal output accidentally saved as files):
- `bcrypt('Penco1'])`, `bootstrap()`, `env('CHATIFY_ROUTES_NAMESPACE'`, `env('REVERB_HOST')`, `exists('vendor.chatify.pages.app'))`, `get()`, `getRoleNames()).PHP_EOL`, `getRoleNames())`, `id}`, `max('batch')`, `compose.yaml.backup2`, `count()`, `count())`, `getFillable()`, `get())`, `implode('`, `label`, `email`, `first()`, `created_by`, `getPanel('admin'))`, `getRoleNames()`, `assignRole('training_admin')`, `cnt`, `interval`

**Backup SQL Files Cleaned**:
- Deleted: 43 files from Jan 26-28, 2026
- Kept: 40 files from Feb 2-9, 2026 (most recent data)

---

### H.5 — Feature Flags Current State

| Feature | Flag | Current Value | Notes |
|---------|------|---------------|-------|
| Replenishment Dashboard | `FEATURE_REPLENISHMENT_DASHBOARD` | `false` | Disabled in production |
| Email Sending (Gmail OAuth) | `FEATURE_EMAIL_SENDING` | `false` | Gmail OAuth implemented but disabled |

---

### H.6 — Production Smoke Tests (2026-02-17)

| Endpoint | Expected | Actual | Status |
|----------|----------|--------|--------|
| `https://support.darleyplex.com/admin/login` | 200 | 200 | ✅ PASS |
| `https://support.darleyplex.com/daily` | 200 | 200 | ✅ PASS |
| `https://support.darleyplex.com/admin/replenishment-dashboards` | 302 (auth redirect) | 302 | ✅ PASS |

---

### H.7 — Recent Incidents & Fixes (Since Feb 12)

1. **Chatify/Reverb Full Rescue (2026-02-11 to 2026-02-15)**: Duplicate Alpine/Livewire, chatify.js 404, broadcasting misconfiguration — ✅ RESOLVED
2. **StationResource Syntax Error (2026-02-15)**: Missing `];` closing bracket — ✅ RESOLVED
3. **Inventory URL Concatenation Bug (2026-02-14)**: URL construction error — ✅ RESOLVED
4. **Chatify jQuery Load Order (2026-02-14)**: `$ is not defined` error — ✅ RESOLVED
5. **Favicon Fix (2026-02-15)**: Vite.svg replaced with MBFD favicon.ico — ✅ RESOLVED
6. **CRITICAL: UI/UX Agent Broke Site (2026-02-27)**: Previous AI agent injected broken CSS (forced dark sidebar, destroyed stat card colors, broke Vite build). Site returned HTTP 500. Emergency recovery performed — ✅ RESOLVED (see Section I)

---

**SECTION H STATUS**: ✅ COMPLETE  
**DOCUMENT STATUS**: Updated 2026-02-27  
**NEXT REVIEW**: 2026-03-27 (30 days)

---

## SECTION I — EMERGENCY RECOVERY (2026-02-27)

### I.1 — Incident: Previous AI Agent Broke Production Site

**Date**: 2026-02-27  
**Severity**: CRITICAL — Site completely down (HTTP 500)  
**Duration**: ~30 minutes from diagnosis to full recovery

### I.2 — Root Cause

A previous AI agent attempted UI/UX improvements but:
1. **Broke the Vite build** — `theme.css` was in `vite.config.js` input array but the build output didn't include it in `manifest.json`, causing `ViteException`
2. **Forced dark sidebar** — Injected `background: #0F172A !important` on `.fi-sidebar`, making text invisible
3. **Overrode Filament's native color system** — Added CSS variables and `!important` overrides fighting `->colors()` PHP config
4. **Destroyed stat card colors** — CSS-based card coloring resulted in all-black cards

### I.3 — Recovery Actions

1. **Rebuilt Vite assets** on VPS (`npm run build` inside Docker container) — site immediately returned HTTP 200
2. **Stripped broken CSS overrides** from `resources/css/filament/admin/theme.css`
3. **Added enterprise styling** that works WITH Filament's native theming (not against it)
4. **Added colored stat card backgrounds** via `->extraAttributes()` on widget PHP files
5. **Purged Cloudflare CDN cache** via API
6. **Verified visually** — Dashboard shows colored stat cards, left sidebar, enterprise styling

### I.4 — Files Modified

| File | Change |
|------|--------|
| `resources/css/filament/admin/theme.css` | Stripped broken overrides, added enterprise styling |
| `laravel-app/resources/css/filament/admin/theme.css` | Stripped broken overrides, kept mobile enhancements |
| `app/Filament/Widgets/FleetStatsWidget.php` | Added `->extraAttributes()` for colored card backgrounds |
| `app/Filament/Widgets/InventoryOverviewWidget.php` | Added `->extraAttributes()` for colored card backgrounds |

### I.5 — Post-Recovery Smoke Tests

| Endpoint | Expected | Actual | Status |
|----------|----------|--------|--------|
| `https://www.mbfdhub.com/admin/login` | 200 | 200 | ✅ PASS |
| `https://www.mbfdhub.com/` | 200 | 200 | ✅ PASS |
| `https://www.mbfdhub.com/daily` | 200 | 200 | ✅ PASS |
| Admin Dashboard (visual) | Colored cards, left sidebar | Confirmed | ✅ PASS |

### I.6 — Lessons Learned

1. **Never force CSS `!important` overrides on Filament internals** — use `->colors()`, `->extraAttributes()`, and Filament's native PHP API
2. **Always verify Vite build output** includes all referenced CSS files before deploying
3. **Always purge Cloudflare cache** after CSS/asset changes
4. **Test visually** after every deployment — HTTP 200 doesn't mean the UI is correct

---

**END OF DISCOVERY REPORT**
