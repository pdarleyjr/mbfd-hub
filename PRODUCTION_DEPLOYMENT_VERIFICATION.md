# Production Deployment Verification — MBFD Hub Phase 2

Date: 2026-06-06 (deploy window ~21:30–22:30 ET, low-usage)
Host: GMKtec (`mbfdhub`)

Both applications were merged to their deployment branch and deployed to the live GMKtec box, then verified.

## 1. MBFD Hub (Laravel)

| Item | Value |
|---|---|
| PRs merged to `main` | #79 (Phase-2 work) + #96 (apparatus-index redaction follow-up) |
| Deployed SHA (origin/main == box HEAD) | **`92917b5e`** |
| Rollback SHA (pre-deploy) | `465d8d6a` |
| Pre-deploy DB dump | `/mnt/mbfd-storage/backups/daily/predeploy-mbfd_hub-20260606-213154.pgdump.gz` |
| Schema change applied | `2026_06_06_000001_add_review_status_to_apparatus_inspections_table` (additive, default `approved`) — `migrate --force` → DONE |
| Containers | `mbfd-hub-laravel` healthy; `mbfd-hub-baserow` **removed** (`up -d --remove-orphans`) |
| Frontend | rebuilt via `docker run node:20 … npm ci && npm run build` (committed build set was stale/incomplete — see note) |

### Smoke results (live, through Cloudflare)
| Check | Result |
|---|---|
| `www.mbfdhub.com/` | 200 |
| `/daily/` | 200 |
| `/admin/login` | 200 (Filament boots cleanly post-Baserow) |
| `/up` health | 200 |
| `inventory.mbfdhub.com/` (UI) | 302 → CF Access |
| `inventory.mbfdhub.com/api/v1/hardware` | 302 → Snipe-IT login (NOT CF Access → Laravel integration preserved) |
| `POST /api/apparatus-inspections/1/approve` (no auth, JSON) | **401** (gate works) |
| `/api/public/apparatuses` sensitive-field leak | **clean** (no vin/snipeit/notes/current_location; 26 rows; status/pm_health kept) |

### Rollback (Laravel)
```bash
cd /opt/mbfd/mbfd-hub
git reset --hard 465d8d6a
docker run --rm -v "$PWD":/app -w /app -u "$(id -u):$(id -g)" -e HOME=/tmp node:20 sh -c "npm ci --legacy-peer-deps && npm run build"
docker exec -u sail mbfd-hub-laravel php artisan migrate:rollback --step=1 --force   # drops review_status (only if needed; column is harmless)
docker exec -u sail mbfd-hub-laravel php artisan optimize:clear && ... config:cache route:cache view:cache
docker restart mbfd-hub-laravel
# To restore Baserow: re-create its compose service + DNS CNAME and `docker compose up -d`.
```

## 2. MBFD Media Control

| Item | Value |
|---|---|
| Security commit | `81aaec4` (security/display-hardening-20260606), 158/158 tests |
| Box branch (diverged, live display features) | `feat/multiview-layout` |
| Merge commit on box | **`4ca7accd`** (security merged into multiview; 1 conflict in `server/server.js` resolved — kept HEAD's Live-News block + auto-merged security additions) |
| Rollback tag | `pre-security-merge-20260606` → `8f133169` |
| Rollback image | `media-control-media-control:rollback-20260606` |
| Build/deploy | `docker compose build --build-arg CACHEBUST=… && docker compose up -d` |

### Verification
| Check | Result |
|---|---|
| Container | healthy |
| Live displays | reconnected after restart (boot log shows devices back online) |
| `media.mbfdhub.com/` (player) | 302 → /app |
| `media-control.mbfdhub.com/` | 302 → CF Access |
| `media-control.mbfdhub.com/player/` (device path) | 200 |
| Merged code syntax | `node --check` clean on all changed files |
| `audit_log` table | **EXISTS** (created by idempotent boot migration; confirmed via app db module) |
| `/api/version` | `0216b9bc` unchanged — expected (hash covers frontend/player files only; changes were server-side) |

### Rollback (Media Control)
```bash
cd /home/mbfd/media-control/app && git reset --hard pre-security-merge-20260606
cd /home/mbfd/media-control
docker tag media-control-media-control:rollback-20260606 media-control-media-control:latest
docker compose up -d
```

## 3. Important operational notes / gotchas discovered

1. **Self-hosted deploy workflow auto-triggers on push to `main`** (`.github/workflows/deploy.yml`, `deploy` job on `self-hosted`). Its "Setup SSH" step **overwrites `~/.ssh/config`** with only a `deploy-target` entry, which **removed the `github-mbfdhub` and `github-media-control` git remote aliases** the box uses for manual fetches. I restored both aliases (keys: `~/.ssh/mbfd-hub-deploy`, `~/.ssh/media-control-deploy`). **Recommend fixing the workflow to append (not overwrite) the SSH config, or to not clobber the repo deploy aliases.**
2. **Committed Vite build assets are stale/incomplete** — after `git reset --hard origin/main` the manifest referenced 4 missing asset files. A frontend rebuild is required on every deploy (the deploy workflow already does this). **Recommend either committing a complete build set or gitignoring `public/build` and always building on deploy.**
3. `public/deploy-marker.json` shows a cosmetically-stale SHA (last updated by a prior workflow run); the actual deployed HEAD is `92917b5e`.
4. Media Control box runs `feat/multiview-layout` (not `main`); origin/feat/multiview-layout does **not** yet contain the security merge (box deploy key is read-only). **Recommend pushing `feat/multiview-layout` (or merging security/display-hardening into it) from a push-capable machine for git hygiene** — the deployed code is correct regardless.

## 4. CI security gates
The `ci` job (GitHub-hosted) gates `deploy`. If GitHub-hosted minutes are billing-blocked, the gate may fail and the self-hosted deploy may not run automatically — in which case use the documented manual deploy above. (During this window the self-hosted deploy job did run, indicating CI was available.)
