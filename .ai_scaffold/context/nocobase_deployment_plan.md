# NocoBase Deployment Plan — Mission Context

**Created:** 2026-02-23  
**Mission:** Deploy NocoBase at mbfdhub.com on the MBFD Support Hub VPS with zero production downtime.  
**Status:** SCAFFOLDING COMPLETE — AWAITING PHASE 1 EXECUTION

---

## Mission Objectives

### Phase 1 & 2: Repo Hygiene
**Scope:** ONLY the following — do NOT execute any other repo restructuring.

- [ ] **1a.** Delete terminal artifact files from the repo root:
  - `count()` (file named literally `count()`)
  - `bcrypt('Penco1'])` (file named literally `bcrypt('Penco1'])`)
  - `bootstrap()` (file named literally `bootstrap()`)
  - `cnt` (junk artifact file)
  - `$null` (PowerShell artifact)
  - `'In` (partial artifact)
  - `compose.yaml.backup2` (empty backup)
  - `compose-vps.yaml` (empty file)
  - `assignRole('training_admin')` (artifact)

- [ ] **1b.** Relocate root-level markdown reports to `docs/`:
  - `BASELINE_EVIDENCE_REPORT.md` → `docs/`
  - `CACHE_IMPLEMENTATION.md` → `docs/`
  - `CHATIFY_FIX_REPORT.md` → `docs/`
  - `CLOUDFLARE_ADMIN_ACCESS_FIX.md` → `docs/`
  - `CSV_VS_DATABASE_ANALYSIS.md` → `docs/`
  - `DIAGNOSTIC_ANALYSIS_REPORT.md` → `docs/`
  - `deploy_manual_steps.md` → `docs/`

### Phase 3: NocoBase Docker Deployment
- [ ] **3a.** Add NocoBase service to Docker Compose on port `13000`.
  - Container name: `nocobase`
  - Attach to `sail` network.
  - Data persistence: named volume `nocobase_data`.
  - Environment: connect to existing `mbfd_test` PostgreSQL container.
- [ ] **3b.** Start NocoBase container without stopping existing services.
- [ ] **3c.** Verify NocoBase is accessible at `http://localhost:13000`.

### Phase 4: NocoBase Data Source Mapping
- [ ] **4a.** Via NocoBase UI/API: configure existing PostgreSQL (`mbfd_test`) as an external data source.
- [ ] **4b.** Map tables as read/write collections (NO schema changes):
  - `station_inventory_items`
  - `capital_projects`
  - `apparatuses`
  - `uniforms`
  - `shop_works`
  - `stations`
  - `unit_master_vehicles`
  - `single_gas_meters`
  - `apparatus_inspections`

### Phase 5: NocoBase Role Provisioning
- [ ] **5a.** Create `Admin` role in NocoBase — assign to logistics users:
  - MiguelAnchia@miamibeachfl.gov
  - RichardQuintela@miamibeachfl.gov
  - PeterDarley@miamibeachfl.gov
  - GreciaTrabanino@miamibeachfl.gov
- [ ] **5b.** Create `Member` role in NocoBase — assign to station personnel.

### Phase 6: Baserow HTTP API Configuration
- [ ] **6a.** Configure Baserow HTTP API connector within NocoBase.
- [ ] **6b.** Test connectivity to Baserow instance.

### Phase 7: Cloudflare Tunnel Configuration
- [ ] **7a.** Install `cloudflared` on VPS if not present.
- [ ] **7b.** Create Cloudflare Tunnel routing `www.mbfdhub.com` → `localhost:13000`.
  - This bypasses Nginx entirely.
  - Does NOT modify existing Nginx configuration.
- [ ] **7c.** Verify `https://www.mbfdhub.com` returns NocoBase UI.
- [ ] **7d.** Verify `https://support.darleyplex.com` is still UP (zero downtime check).

### Phase 8: NocoBase UI Scaffolding via API/CLI
- [ ] **8a.** Scaffold "Member Portal" page schema (Inventory Form) via NocoBase API/CLI.
- [ ] **8b.** Scaffold "Logistics Admin Dashboard" page schema via NocoBase API/CLI.
- [ ] **8c.** Export scaffold JSON to `.ai/skills/nocobase_schemas/` for version control.

### Phase 9: CI/CD Workflow Updates
- [ ] **9a.** Update `.github/workflows/` to reference relocated markdown paths in `docs/`.
- [ ] **9b.** Verify CI passes after path updates.

---

## Safety Checkpoints

Before EVERY phase:
1. `curl -sI https://support.darleyplex.com | head -1` → must return `HTTP/2 200`
2. Confirm no existing Laravel/React files will be modified.
3. Confirm no schema migrations will run against `mbfd_test`.

---

## Rollback Plan

| Phase | Rollback Action |
|-------|----------------|
| Phase 1 Hygiene | `git revert HEAD` — restore deleted files from git history |
| Phase 3 NocoBase | `docker compose stop nocobase` — does not affect existing services |
| Phase 7 Tunnel | Delete tunnel in Cloudflare dashboard — DNS reverts |

---

## Current Blockers
- None. Scaffolding complete. Ready to begin Phase 1 upon user approval.
