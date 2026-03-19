# CLAUDE.md — MBFD Hub AI Context

**Last Updated**: 2026-03-18 19:20 EST

> ✅ **Master Remediation & Supervisord Decommission** (2026-03-18) — Supervisord decommissioned from DeerFlow WSL. All 5 Docker containers running via native `restart: unless-stopped` policy. Mandatory recovery sequence executed. LangGraph health `{"ok":true}`. CLAUDE.md restored from corruption (previous agent injected ~800 lines of hallucinated garbage).
> ✅ **DeerFlow 2.0 Integration Fix, Optimization, and Hardening** (2026-03-18) — Reconciled repository structure, upgraded Node.js to 22+, hardened network topology with Cloudflare Tunnel and 1.1.1.1 DNS, implemented ECI using Sysbox Runtime for sandbox isolation, refined UI/UX with Impeccable design language. Supervisord DECOMMISSIONED — Docker restart policies now manage all lifecycle.
> ✅ **DeerFlow 2.0 Emergency Recovery COMPLETE** (2026-03-17) — Three critical failures reversed: (1) DB lockout from `chown -R 1000:1000` fixed by `docker exec deer-flow-langgraph chmod -R 777 /app/backend/.deer-flow/`. (2) Poisoned `filesystem` MCP server removed from `extensions_config.json` — only 5 authorized servers remain. (3) Nginx stale DNS cache fixed by `docker exec deer-flow-nginx nginx -s reload` after restart. Errors documented as ERROR-063 through ERROR-068.
> ✅ **Employee Portal DEPLOYED** (2026-03-16) — New Filament panel at `/employee`. Features: assigned equipment viewer, gear request form, forced password change on first login. Commit: `464e4b69`.

---

## ⚠️ MANDATORY PRE-WORK READING

**BEFORE making ANY changes**, you MUST read `AI_AGENT_ERRORS.md` in its entirety. Failure to do so WILL result in breaking existing functionality. Previous agents have caused catastrophic system regressions by ignoring project-specific constraints.

---

## Architecture Overview

### MBFD Hub (Production VPS: 145.223.73.170)
- **Stack**: Laravel 11 + Filament v3 + React 18 + PostgreSQL 15 + Redis
- **Container**: `mbfd-hub-laravel.test-1` (includes Reverb WebSocket + queue worker)
- **Tunnel**: Cloudflare Tunnel (`cloudflared-mbfdhub`)
- **Domains**: `www.mbfdhub.com`, `mbfdhub.com`, `baserow.mbfdhub.com`
- **SSH**: `ssh -i "C:\Users\Peter Darley\.ssh\id_ed25519_hpb_docker" root@145.223.73.170`
- **App Root**: `/root/mbfd-hub`

### DeerFlow 2.0 (Local WSL — NOT on VPS!)
- **Location**: `~/src/deer-flow` in WSL
- **Docker Compose**: `~/src/deer-flow/docker/docker-compose-dev.yaml`
- **Containers**: nginx (port 2026), frontend, gateway, langgraph, cloudflared
- **Tunnel**: `code.mbfdhub.com` via Cloudflare Zero Trust (Google auth)
- **Process Management**: Docker `restart: unless-stopped` — NO supervisord
- **Config**: `~/src/deer-flow/config.yaml` (config_version: 2)

### Critical Constraint
**DeerFlow runs LOCALLY in WSL. It does NOT exist on the VPS.** If any agent touches `/root/src/` on the VPS for DeerFlow purposes, that is a CRITICAL ERROR.

---

## Key Files

| Purpose | Path |
|---|---|
| Error prevention log | `AI_AGENT_ERRORS.md` |
| Project summary | `.project_summary.md` |
| Discovery report | `MBFD_HUB_DISCOVERY_REPORT_2026-02-12.md` |
| DeerFlow config | `~/src/deer-flow/config.yaml` |
| DeerFlow extensions | `~/src/deer-flow/extensions_config.json` |
| DeerFlow compose | `~/src/deer-flow/docker/docker-compose-dev.yaml` |
| MBFD Hub compose | `/root/mbfd-hub/compose.yaml` (VPS) |
| CI/CD deploy | `.github/workflows/deploy.yml` |
| Landing page | `resources/views/welcome.blade.php` |
| Daily Checkout SPA | `resources/js/daily-checkout/src/App.tsx` |
| Filament admin theme | `resources/css/filament/admin/theme.css` |

---

## DeerFlow 2.0 Configuration

### Config Version
`config.yaml` MUST have `config_version: 2` (per ERROR-067 upstream restructure).

### Module Paths (DeerFlow 2.0 Restructure)
- Gateway ASGI: `app.gateway.app:app` (NOT `src.gateway.app:app`)
- LangGraph agent: `deerflow.agents:make_lead_agent` (NOT `src.agents:...`)
- Sandbox provider: `deerflow.community.aio_sandbox:AioSandboxProvider`
- Directory structure: `backend/app/` (gateway, channels) + `backend/packages/harness/deerflow/` (agents, community)

### Required Config Sections
- `tool_groups`: web, file:read, file:write, bash
- `tools`: 9 built-in tools (web_search, web_fetch, image_search, ls, read_file, write_file, str_replace, bash)
- `subagents`: default 900s timeout, general-purpose 1800s, bash 300s
- `tool_search`: enabled: false

### MCP Servers (extensions_config.json)
**Authorized (5 only)**: github, memory, sequential-thinking, git-mcp, context7
**FORBIDDEN**: `filesystem` — use AioSandboxProvider instead (ERROR-064)

---

## Mandatory Recovery Sequence (After ANY DeerFlow Restart)

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

---

## MBFD Hub VPS Operations

### After Container Recreation
```bash
docker exec mbfd-hub-laravel.test-1 chmod -R 777 storage bootstrap/cache
```

### After CSS/JS Changes (git pull alone is NOT sufficient)
```bash
docker exec mbfd-hub-laravel.test-1 npm run build
```

---

## Design System Rules

1. **No `@apply`** — causes iOS Safari crashes (ERROR-031)
2. **No Tailwind CDN** in production — use `@vite()` (ERROR-032)
3. **Warm stone neutrals** — no cold grays
4. **Plus Jakarta Sans** + **Source Sans 3** fonts
5. **No bouncy easing** — professional motion only
6. **OKLCH color space** where supported

---

## Filament v3 Rules

1. **No `x-filament::card.heading`** or `x-filament::card.content` — not valid in v3 (ERROR-001)
2. **No global LoginResponse bindings** — affects all panels (ERROR-070)
3. Use `new LoginResponse()` not `app(LoginResponse::class)` in custom login pages
4. **No Livewire widgets** on pages with reactive switching — use `getViewData()` + plain Blade (ERROR-018)
5. **Never add `use` imports** for packages not in `composer.json` (ERROR-019)
6. JSON/array columns in Filament: always use `->getStateUsing()` to serialize (ERROR-041)

---

## Filament Panels

| Panel | Path | Purpose |
|---|---|---|
| Logistics / Admin | `/admin` | Fleet, inventory, projects, personnel |
| Training | `/training` | Training resources and support content |
| Workgroup | `/workgroups` | Eval Feedback Hub |
| Employee | `/employee` | Personnel gear viewer & equipment requests |

## Public SPAs

| App | Path | Purpose |
|---|---|---|
| MBFD Forms | `/daily` | Daily operational forms and vehicle inspections |
| Pump Simulator | `/pump-simulator` | Fire pump operations training |
| Apparatus Layout Planner | `/apparatus-layout` | Visual compartment layout tool |

---

*This file was restored on 2026-03-18 after severe corruption by a previous AI agent. The original content below the "Key Files" section had been replaced with ~800 lines of hallucinated garbage text (mixed Japanese, code fragments, and nonsense). That content has been removed and replaced with the structured reference above.*
