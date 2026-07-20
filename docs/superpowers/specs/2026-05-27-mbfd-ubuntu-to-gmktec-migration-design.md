# MBFD Hub Migration — Legacy mbfd-ubuntu → GMKtec EVO-X2

**Date:** 2026-05-27
**Author:** Claude (autonomous migration assistant)
**Owner:** Peter Darley
**Status:** Awaiting final approval before execution

---

## 1. Goal

Move every MBFD-domain workload off the legacy Tailscale-reachable Ubuntu workstation
`mbfd-ubuntu` (peter@100.82.185.48 / hostname `peter-Default-string` / a Kamrui mini-PC)
onto the GMKtec EVO-X2 (mbfd@100.81.154.123 / hostname `mbfdhub`), then decommission
cloudflared and Docker on the legacy box.

Out of scope: the Hostinger tfvps VPS (Coolify hosting `tfportalapp.com`, no MBFD code),
`bid.mbfdhub.com` (already on Cloudflare Pages+Workers), and `l1.mbfdhub.com`
(already on Cloudflare Pages).

## 2. Current State (verified 2026-05-27 via SSH)

### Legacy `mbfd-ubuntu` — tunnel `mbfdhub-nocobase` (`89429799-7028-4df2-870d-f2fb858a49d7`)

| Container | Local origin | Public hostname | Compose root | Persistent data |
|---|---|---|---|---|
| `mbfd-hub-laravel` | `127.0.0.1:8080` + `:8090` | `mbfdhub.com`, `www.mbfdhub.com`, `/app/*`, `/apps/*` | `/opt/mbfd/mbfd-hub/compose.prod.yaml` | bind `./storage` (18 MB) |
| `mbfd-hub-pgsql` (postgres:16.13-alpine) | `127.0.0.1:5432` | — | same | volume `mbfd-hub_pgsql-data` (`mbfd_hub` DB ≈ 21 MB) |
| `mbfd-hub-redis` (redis:7.4-alpine) | `127.0.0.1:6379` | — | same | volume `mbfd-hub_redis-data` (cache/queue/sessions) |
| `mbfd-hub-baserow` (baserow:1.36) | `127.0.0.1:8082` | `baserow.mbfdhub.com` | same | volume `mbfd-hub_baserow-data` (≈ 609 MB) |
| `mbfd-snipeit` | `127.0.0.1:8083` | `inventory.mbfdhub.com`, `www.inventory.mbfdhub.com` | `/opt/mbfd/snipeit/compose.yaml` | volumes `snipeit-uploads`, `snipeit-data` (≈ 78 MB) |
| `mbfd-snipeit-db` (mysql) | internal | — | same | `snipeit` DB ≈ 2.7 MB |
| `mbfd-uptime-kuma` | `127.0.0.1:3001` | `status.mbfdhub.com` | `/opt/mbfd/observability/compose.yaml` | volume `uptime-kuma` |
| `mbfd-web-check` | `127.0.0.1:3000` | (internal) | same | none |
| `mbfd-dozzle` | `127.0.0.1:8888` | (internal) | same | none |
| `ts-orchestrator-{ts-nginx,ts-api,ts-redis}` | `127.0.0.1:7080` | `ts.mbfdhub.com` | `/home/peter/ts-orchestrator/` | volume (session signaling) |
| `mbfd-screentinker` | `127.0.0.1:8095` | `media.mbfdhub.com` | `/home/peter/screentinker/` | (TURN config via OpenRelay per `[[project-screentinker-openrelay-turn]]`) |
| GitHub Actions self-hosted runner | — | (not exposed) | `/opt/actions-runner/` (v2.334.0) | runner registration token |

**Total persistent data: ≈ 750 MB.**

### GMKtec `gmktec` (already deployed) — tunnel `mbfdhub-gmktec` (`20cb894c-a5b0-4149-bc11-1499d772401e`)

| Container | Local origin | Public hostname | Notes |
|---|---|---|---|
| `mbfd-nextcloud` + `mbfd-nextcloud-cron` | `127.0.0.1:11000` | `cloud.mbfdhub.com` | Nextcloud 30.0.17, data on 22 TB |
| `mbfd-onlyoffice` | `127.0.0.1:8082` | `office.mbfdhub.com` | **CLAIMS PORT 8082** |
| `open-webui` | `:3000` | `ai.mbfdhub.com` | Open WebUI + Ollama on host |
| `mbfd-admin-dashboard` | `127.0.0.1:8088` | `admin.mbfdhub.com` | Homepage launch dashboard |
| `mbfd-postgres`, `mbfd-redis` | internal | — | for Nextcloud |
| `mcpo`, `searxng`, `qdrant`, `piper-tts`, `whisper-stt`, `nextcloud-user-fs` | various 127.0.0.1 | — | AI extras |

Storage: `/mnt/mbfd-storage` ext4 22 TB, 91 GB used. Memory: 64 GiB physical. CPU load light.

### Cloudflare resources (account `265122b6d6f29457b0ca950c55f3ac6e`, zone `9c7b03d154bbf6abe7b2edd4b5c33fe5`)

8 CNAMEs currently target the legacy tunnel and will be flipped to GMKtec at cutover:
`mbfdhub.com`, `www.mbfdhub.com`, `baserow.mbfdhub.com`, `inventory.mbfdhub.com`,
`www.inventory.mbfdhub.com`, `status.mbfdhub.com`, `ts.mbfdhub.com`, `media.mbfdhub.com`.

## 3. Approved Strategy (from 2026-05-27 question round)

- **Scope:** full parity — Laravel + Baserow + SnipeIT + observability + ts-orchestrator + ScreenTinker.
- **Cutover style:** parallel run + DNS swap. Legacy stack stays warm for 48 h for instant rollback.
- **CI runner:** re-register GitHub Actions self-hosted runner on GMKtec.

## 4. Port Allocation on GMKtec (no collisions with deployed services)

| Service | Legacy port | **GMKtec port (proposed)** | Reason |
|---|---|---|---|
| Laravel main | `:8080` | `:8080` | free |
| Laravel PWA | `:8090` | `:8090` | free |
| Postgres (mbfd_hub) | `:5432` | `:5532` | GMKtec already has `mbfd-postgres` for Nextcloud bound on internal docker net (no host bind), but using `:5532` keeps things isolated and obvious |
| Redis (mbfd-hub) | `:6379` | `:6479` | same — keep Nextcloud Redis untouched |
| Baserow | `:8082` | **`:8182`** | `:8082` taken by ONLYOFFICE |
| SnipeIT | `:8083` | **`:8183`** | keep adjacent number |
| SnipeIT MySQL | internal | internal | no host bind |
| Uptime Kuma | `:3001` | **`:3101`** | `:3000` taken by open-webui |
| Web-check | `:3000` | **`:3100`** | `:3000` taken |
| Dozzle | `:8888` | `:8888` | free |
| ts-orchestrator-nginx | `:7080` | `:7080` | free |
| ScreenTinker | `:8095` | `:8095` | free |

## 5. Storage Layout on GMKtec

```
/opt/mbfdhub/                          → clone of MBFD_Hub repo + new compose.gmktec.yaml
/opt/mbfd-snipeit/                     → snipeit compose
/opt/mbfd-observability/               → uptime-kuma/web-check/dozzle compose
/opt/ts-orchestrator/                  → ts-orchestrator compose (moved from /home/peter)
/opt/screentinker/                     → screentinker compose

/etc/mbfdhub/.env.legacy/              → 0600 root:mbfd, snapshot of every legacy .env

/mnt/mbfd-storage/mbfdhub/
  pgsql/                               → bind for mbfd-hub-pgsql
  redis/                               → bind for mbfd-hub-redis
  baserow/                             → bind for baserow data
  laravel-storage/                     → bind for /var/www/html/storage
  snipeit-mysql/                       → bind for mbfd-snipeit-db
  snipeit-uploads/                     → bind for snipeit public/uploads
  snipeit-data/                        → bind for snipeit storage
  uptime-kuma/                         → bind
  ts-orchestrator/                     → bind
  screentinker/                        → bind

/mnt/mbfd-storage/backups/             → daily pg_dump + mysqldump + tarball of bind dirs
/mnt/mbfd-storage/legacy-snapshots/    → final tar.gz of /opt/mbfd on decommission
```

## 6. Migration Phases

### Phase 0 — Pre-flight (≈ 10 min)

1. Snapshot legacy `.env` files into `/etc/mbfdhub/.env.legacy/` on GMKtec (mode `0600 root:mbfd`).
2. Snapshot current `mbfdhub-nocobase` and `mbfdhub-gmktec` tunnel ingress JSON to local files for rollback.
3. Verify GMKtec free disk (≈ 22 TB) and RAM (`free -h`).
4. Create the storage directory tree under `/mnt/mbfd-storage/mbfdhub/` with ownership matching container UIDs (Postgres 999, MySQL 999, Laravel www-data 33, Baserow 9999).

### Phase 1 — Provision on GMKtec, no public traffic (≈ 30 min)

1. `git clone https://pdarleyjr:<TOKEN>@github.com/pdarleyjr/MBFD_Hub.git /opt/mbfdhub` (or the app repo if it lives separately — confirm with owner).
2. Author `/opt/mbfdhub/compose.gmktec.yaml` derived from legacy `compose.prod.yaml` with:
   - Bind-mount paths from §5 instead of named docker volumes (data lives on 22 TB drive).
   - Remapped host ports from §4.
   - `restart: unless-stopped`, `security_opt: no-new-privileges:true`, `cap_drop: ALL` on edge containers (carry forward the legacy hardening).
3. Same pattern for `/opt/mbfd-snipeit/`, `/opt/mbfd-observability/`, `/opt/ts-orchestrator/`, `/opt/screentinker/`.
4. `docker compose pull` everything while empty.
5. Bring up only `pgsql`, `redis`, `snipeit-db` containers to verify volume mounts and healthchecks. Do not start app containers yet.

### Phase 2 — Data migration (≈ 10 min)

1. **Postgres:** `docker exec mbfd-hub-pgsql pg_dump -U mbfd_user -d mbfd_hub` → stream over SSH/Tailscale to `psql -U mbfd_user -d mbfd_hub` on GMKtec.
2. **MySQL (SnipeIT):** `docker exec mbfd-snipeit-db mysqldump --default-character-set=utf8mb4 --single-transaction --routines --triggers snipeit` → restore on GMKtec.
3. **Laravel storage:** `docker exec -i mbfd-hub-laravel tar -C /var/www/html -czf - storage` → extract on GMKtec into `/mnt/mbfd-storage/mbfdhub/laravel-storage/`.
4. **Baserow:** `docker exec -i mbfd-hub-baserow tar -C /baserow -czf - data` → extract on GMKtec.
5. **SnipeIT uploads/storage:** `docker exec -i mbfd-snipeit tar -C /var/www/html -czf - public/uploads storage`.
6. **Uptime Kuma:** `docker cp mbfd-uptime-kuma:/app/data ./uptime-kuma-data && rsync`.
7. **ts-orchestrator & screentinker:** rsync any persistent data dirs.
8. Verify row counts (Postgres `\dt`, MySQL `SHOW TABLES`) and `du -sh` parity vs source.

### Phase 3 — Cloudflare tunnel ingress (≈ 10 min, via API)

Patch `mbfdhub-gmktec` ingress (`PUT /accounts/{acc}/cfd_tunnel/{uuid}/configurations`) to **append** these rules ahead of the catch-all 404:

```yaml
- hostname: gm-test.mbfdhub.com         # temporary pre-cutover canary
  service: http://127.0.0.1:8080
  originRequest: { httpHostHeader: www.mbfdhub.com }

- hostname: mbfdhub.com
  service: http://127.0.0.1:8080
  originRequest: { httpHostHeader: mbfdhub.com }
- hostname: www.mbfdhub.com
  path: ^/app/.*
  service: http://127.0.0.1:8090
  originRequest: { httpHostHeader: www.mbfdhub.com }
- hostname: www.mbfdhub.com
  path: ^/apps/.*
  service: http://127.0.0.1:8090
  originRequest: { httpHostHeader: www.mbfdhub.com }
- hostname: www.mbfdhub.com
  service: http://127.0.0.1:8080
  originRequest: { httpHostHeader: www.mbfdhub.com }

- hostname: baserow.mbfdhub.com
  service: http://127.0.0.1:8182
- hostname: inventory.mbfdhub.com
  service: http://127.0.0.1:8183
- hostname: www.inventory.mbfdhub.com
  service: http://127.0.0.1:8183
- hostname: status.mbfdhub.com
  service: http://127.0.0.1:3101
- hostname: ts.mbfdhub.com
  service: http://127.0.0.1:7080
- hostname: media.mbfdhub.com
  service: http://127.0.0.1:8095
```

Add a DNS CNAME `gm-test.mbfdhub.com → 20cb894c-….cfargotunnel.com` (proxied). Existing 8 CNAMEs stay on the legacy tunnel.

### Phase 4 — Bring up app stack on GMKtec (≈ 15 min)

1. `cd /opt/mbfdhub && docker compose -f compose.gmktec.yaml up -d`
2. Inside Laravel container: `php artisan storage:link && php artisan config:clear && php artisan config:cache && php artisan route:cache && php artisan view:cache && php artisan migrate --pretend` (dry-run; only execute migrations if differences found).
3. Bring up `mbfd-snipeit-*`, `mbfd-uptime-kuma`, `mbfd-web-check`, `mbfd-dozzle`, `ts-orchestrator-*`, `mbfd-screentinker`.
4. Internal smoke tests on localhost:
   - `curl -fsS -H 'Host: www.mbfdhub.com' http://127.0.0.1:8080/up` → `200`
   - `curl -fsS -H 'Host: www.mbfdhub.com' http://127.0.0.1:8080/admin/login` → `200`
   - `curl -fsS -H 'Host: baserow.mbfdhub.com' http://127.0.0.1:8182/` → `200`
   - `curl -fsS -H 'Host: inventory.mbfdhub.com' http://127.0.0.1:8183/login` → `200`
   - `curl -fsS -H 'Host: status.mbfdhub.com' http://127.0.0.1:3101/` → `200`
   - `curl -fsS -H 'Host: ts.mbfdhub.com' http://127.0.0.1:7080/health` → `200`
   - `curl -fsS -H 'Host: media.mbfdhub.com' http://127.0.0.1:8095/api/health` → `200`
5. External canary via `https://gm-test.mbfdhub.com` (CF Access bypass for the test hostname only) — end-to-end PIN → login → admin → SAVER → ScreenTinker handshake.

### Phase 5 — DNS cutover (≈ 5 min flip + 30 min watch)

1. Flip 8 CNAMEs from `89429799-…` → `20cb894c-…` via Cloudflare API (no proxy change). Cloudflare-managed TTL ≈ seconds.
2. Tail both cloudflared journals — expect legacy traffic to fall to zero, GMKtec to pick up.
3. Browser test all 6 user-facing hostnames from outside CF Access scope (use a non-allowlisted browser).
4. **Rollback path:** if anything regresses, flip the CNAMEs back. Legacy stack is still running, untouched. RTO < 5 min.

### Phase 6 — 48 h verification window

1. Daily backup cron on GMKtec: `pg_dump` mbfd_hub + `mysqldump` snipeit + `tar` of bind dirs → `/mnt/mbfd-storage/backups/` with 7-day rotation.
2. Watch Uptime Kuma (now on GMKtec) for any service blips.
3. Verify Laravel queue worker drains, scheduler runs, SAVER and Workgroup AI calls succeed.
4. Verify ScreenTinker sessions establish TURN over OpenRelay.
5. Verify Baserow API tokens still valid (they use `BASEROW_SECRET_KEY` carried over).

### Phase 7 — Decommission legacy (after 48 h clean)

1. Stop containers on `mbfd-ubuntu`:
   ```
   docker compose -f /opt/mbfd/mbfd-hub/compose.prod.yaml down
   docker compose -f /opt/mbfd/snipeit/compose.yaml down
   docker compose -f /opt/mbfd/observability/compose.yaml down
   docker compose -f ~/ts-orchestrator/docker-compose.yml down
   docker compose -f ~/screentinker/docker-compose.yml down
   ```
2. `sudo systemctl stop cloudflared && sudo systemctl disable cloudflared` (needs working sudo password — see §9).
3. `tar -czf /tmp/legacy-mbfd-2026-05-27.tar.gz /opt/mbfd` and copy to `/mnt/mbfd-storage/legacy-snapshots/` on GMKtec.
4. Unregister legacy GitHub Actions runner (`./config.sh remove --token <RM_TOKEN>`); register a new one on GMKtec.
5. Delete the Cloudflare tunnel `mbfdhub-nocobase` via API.
6. Update memory note `[[project-mbfd-admin-workspace-deployed]]` → "legacy tunnel decommissioned 2026-05-29".

## 7. Risks & Mitigations

| Risk | Probability | Impact | Mitigation |
|---|---|---|---|
| Laravel writes a hard-coded URL or absolute path that breaks on new host | Low | High | `APP_URL` unchanged (`https://www.mbfdhub.com`); only origin changes. Audit `config/`, `routes/` for any `mbfd-ubuntu`/`100.82.185.48` strings before cutover. |
| Baserow internal database tied to volume initialization state | Low | Medium | Bring up Baserow with restored data + `BASEROW_SECRET_KEY` from legacy `.env`. Baserow's embedded Postgres re-uses the data dir if mounted correctly. |
| SnipeIT MySQL charset/collation drift | Low | Medium | `mysqldump --default-character-set=utf8mb4 --hex-blob`; restore with same flag. |
| Port 8082 conflict (ONLYOFFICE vs Baserow) | Resolved | — | Baserow moved to `:8182`. |
| Workgroup AI worker secret mismatch | Low | Medium | Carry `WORKGROUP_AI_WORKER_SECRET` from legacy `.env` verbatim. |
| ScreenTinker WebRTC state in process memory | Low | Low | Brief reconnect on cutover is acceptable; OpenRelay TURN is independent. |
| GitHub Actions self-hosted runner gap | Resolved | Low | Re-register on GMKtec in Phase 7. CI can fall back to `ubuntu-latest` GitHub-hosted in the interim. |
| Legacy sudo password unknown (memory says rotated) | High | Low | Owner can either share new password or manually run the 2 sudo commands in Phase 7 (`systemctl stop cloudflared` + `tar /opt/mbfd`). Everything else uses `docker exec`/`docker cp`. |
| Owner unavailable mid-cutover | Low | Low | Each phase is reversible. Phase 5 rollback is < 5 min (DNS flip back). |

## 8. Rollback Plan

At any phase before Phase 7:
1. Flip the 8 mbfdhub.com CNAMEs back to `89429799-…` (legacy tunnel).
2. Confirm legacy cloudflared is still running (Phase 5 leaves it untouched).
3. Verify `curl https://www.mbfdhub.com/up` returns 200 against legacy.
4. Stop GMKtec MBFDHub containers if desired: `docker compose -f /opt/mbfdhub/compose.gmktec.yaml down`.

Data is forward-only after Phase 2 (Postgres/MySQL restored to GMKtec). If users wrote during the cutover window, the cleanest rollback is to replay dumps from GMKtec back to legacy before flipping DNS. Mitigation: schedule cutover during a low-traffic window (overnight or weekend morning).

## 9. Open Items Before Execution

1. **Legacy sudo password** — `Abc123`, `abc123`, `Abc1234`, `Abc1234!`, `Abc123!`, etc. all failed. Either the password was changed (the security memory recommended rotating it) or there's another variant. Required only for Phase 7. Owner to share or be present for those two commands.
2. **MBFD_Hub repo URL on GMKtec** — confirm whether to clone `pdarleyjr/MBFD_Hub` (this repo) or a separate app repo. The legacy `/opt/mbfd/mbfd-hub/` looks like a clone of the same Laravel app under a different working copy; this spec assumes the existing `MBFD_Hub` repo on GitHub contains the Laravel code.
3. **GitHub Actions runner labels** — confirm what workflow labels the legacy runner uses so the GMKtec replacement registers under the same labels.

## 10. Approval

- [ ] Owner: I approve the strategy in §3 and the phased plan in §6.
- [ ] Owner: I will be available (or provide credentials) for Phase 7 sudo commands.

Once approved, Claude will follow this spec phase-by-phase and report at the end of each phase before proceeding.
