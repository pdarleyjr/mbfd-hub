# CLAUDE.md — MBFD Support Hub: Authoritative Project Brain

> **This file is the single source of truth for all AI agents operating on this project.**
> Every agent MUST read this file before taking any action.

---

## 1. Project Overview

- **Application:** MBFD Support Hub
- **Production URL:** https://support.darleyplex.com
- **VPS:** Hostinger Docker VPS — `145.223.73.170`
- **App Root:** `/root/mbfd-hub/`

---

## 2. Technology Stack

| Layer | Technology |
|-------|-----------|
| Backend Framework | Laravel (PHP 8.x) via Laravel Sail |
| Frontend | React / TypeScript (Vite) |
| Containerization | Docker Compose (Laravel Sail) |
| Primary Database | PostgreSQL 18-alpine (`mbfd_test` database) |
| Low-Code Layer | NocoBase (port 13000) |
| CDN / Tunnel | Cloudflare (Tunnel via `cloudflared`) |
| Reverse Proxy | Nginx (existing, production) |

---

## 3. Docker Network Policy

- **Existing bridge network:** `sail` (created by Laravel Sail's Docker Compose).
- **Rule:** ALL new containers (NocoBase, cloudflared, etc.) MUST attach to the `sail` network.
- **Prohibited:** Creating a separate isolated network that cannot reach the existing database container.

```yaml
# Required on every new service
networks:
  sail:
    external: true
```

---

## 4. Safety Policy — NON-NEGOTIABLE

### 4.1 Zero Code Modification
- **ZERO modifications** to any existing Laravel PHP files (`app/`, `routes/`, `config/`, `database/migrations/`).
- **ZERO modifications** to any existing React/TypeScript files (`resources/js/`).
- New files may be added; existing files are READ-ONLY for all AI agents.

### 4.2 Zero Downtime
- The production application at `support.darleyplex.com` must remain live throughout all operations.
- Before any Docker operation, verify `curl -sI https://support.darleyplex.com | head -1` returns `HTTP/2 200`.
- After any Docker operation, re-verify the same check.

### 4.3 Database Integrity
- The `mbfd_test` PostgreSQL database is an **external Read/Write data source ONLY**.
- **ZERO schema migrations** may be run against `mbfd_test` from any external tool (NocoBase, scripts, etc.).
- NocoBase must connect to `mbfd_test` as a read/write data source — it maps existing tables, it does NOT create or alter them.
- Prohibited SQL: `ALTER TABLE`, `DROP TABLE`, `CREATE TABLE`, `DROP COLUMN`, `ADD COLUMN` on `mbfd_test`.

### 4.4 Destructive Command Gate
The following commands require **explicit user approval in the current session** before execution:

| Command | Risk | Required Action |
|---------|------|-----------------|
| `docker compose down -v` | Destroys volumes (data loss) | TYPE "I APPROVE docker compose down -v" |
| `rm -rf <any path>` | Permanent file deletion | TYPE "I APPROVE rm -rf <path>" |
| Any `DROP` / `ALTER` SQL | Schema destruction | TYPE "I APPROVE schema change: <sql>" |
| Modifying Nginx config | Production downtime risk | TYPE "I APPROVE nginx change: <description>" |

---

## 5. Agent Workflow

| Agent | File | Responsibility |
|-------|------|----------------|
| Planner | `.ai/prompts/00_planner.md` | Decompose objectives into ordered steps |
| Implementer | `.ai/prompts/01_implementer.md` | Execute approved steps only |
| Reviewer | `.ai/prompts/02_reviewer.md` | Verify each step meets acceptance criteria |
| Chronicler | `.ai/prompts/03_end_of_session.md` | Record session state and next-session handoff |

---

## 6. Key Infrastructure Details

### Existing Database Tables (mbfd_test — READ/WRITE, NO SCHEMA CHANGES)
- `station_inventory_items`
- `capital_projects`
- `apparatuses`
- `uniforms`
- `shop_works`
- `users`
- `stations`
- `unit_master_vehicles`
- `single_gas_meters`
- `apparatus_inspections`
- `apparatus_defects`

### Target New Services
- **NocoBase:** port `13000`, container name `nocobase`, data dir `/var/lib/nocobase`
- **cloudflared:** Cloudflare Tunnel routing `www.mbfdhub.com` → `localhost:13000` (bypasses Nginx)

### CI/CD
- Workflows: `.github/workflows/`
- Markdown docs relocated to: `docs/`

---

## 7. Current Mission Status

See `.ai/context/nocobase_deployment_plan.md` for the active deployment plan and step tracking.
