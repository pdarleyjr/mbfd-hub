# Security Remediation Log — Phase 2 (MBFD Hub)

Date: 2026-06-06
Engagement: continuation of the Phase-1 ecosystem hardening. This log records **Phase-2** changes only. No secret values appear here.

Branches: `security/ecosystem-hardening-20260606` (MBFD Hub), `security/display-hardening-20260606` (Media Control).

## A. Cloudflare (LIVE — applied via API with owner-provided token)

| # | Change | Object | Verification | Rollback |
|---|---|---|---|---|
| A1 | WAF custom rule blocking scanner/probe paths (`/.env`, `/.git`, `/wp-*`, `/xmlrpc.php`, `/phpmyadmin`, `/vendor/phpunit`, `.sql/.bak/.old`) | zone `http_request_firewall_custom` entrypoint | `/.env`→403, `/wp-login.php`→403, `/`→200 | `PUT …/phases/http_request_firewall_custom/entrypoint {"rules":[]}` |
| A2 | Rate-limit: login/auth brute-force (>5 POST/10s per IP+colo, block) | zone `http_ratelimit` entrypoint | rule live (free-tier: 1 rule/10s/block) | `PUT …/phases/http_ratelimit/entrypoint {"rules":[]}` |
| A3 | CF Access: Snipe-IT UI gate (`inventory` + `www.inventory`, allow @miamibeachfl.gov) | access apps `b3f266d5…`, `8b7259f3…` | UI→Access redirect | `DELETE access/apps/{id}` |
| A4 | CF Access: Snipe-IT `/api` bypass (server-to-server preserved) | access apps `9c9ef231…`, `19ded9ff…` | `/api/v1`→Snipe-IT (no Access) | `DELETE access/apps/{id}` |
| A5 | CF Access: gm-test alias gate (allow @miamibeachfl.gov) | access app `dd1b8773…` | — | `DELETE access/apps/{id}` |
| A6 | DNS delete: `baserow.mbfdhub.com` (decommission) | zone DNS | record removed | re-create proxied CNAME → tunnel |

Existing controls confirmed healthy: managed WAF ruleset, DDoS L7, normalization, `security_level=high`, Access on ai/cloud/admin/status/media-control/vscode/media-`/app`, vacation-origin X-Origin-Token guard. Details in `CLOUDFLARE_LIVE_ROUTE_REVIEW.md`.

## B. Backups (LIVE — on box)

| # | Change | Detail | Rollback |
|---|---|---|---|
| B1 | Restic 0.18.1 installed | apt | `apt remove restic` |
| B2 | R2 bucket `mbfd-hub-backups` created | wrangler | `wrangler r2 bucket delete mbfd-hub-backups` (empties first) |
| B3 | Encrypted restic repo initialized on R2 | passphrase generated on-box, stored `/opt/mbfd/secrets/restic.env` (0600) | n/a (keep) |
| B4 | `/opt/mbfd/restic-backup.sh` + cron `0 4 * * *` (keep 7d/4w/6m, prune) | backs up `/mnt/mbfd-storage/backups/daily` | `crontab -e` remove line; `rm` script |
| B5 | `/opt/mbfd/restic-check.sh` + cron `30 7 * * *` (staleness >36h) | monitoring | remove cron |
| B6 | **Restore test PASSED** (sha256 match) + full daily snapshot `0ce3736f` | see `BACKUP_RESTORE_TEST_REPORT.md` | n/a |

## C. Observability / Docker socket (LIVE — on box)

| # | Change | Detail | Rollback |
|---|---|---|---|
| C1 | Added read-only `docker-socket-proxy` (`tecnativa/docker-socket-proxy:0.3.0`); only it mounts `docker.sock` | `/opt/mbfd/observability/compose.yaml` (backup `*.pre-socketproxy.bak`) | restore backup + `docker compose up -d` |
| C2 | dozzle → `tcp://docker-socket-proxy:2375` (no direct socket) | verified 8888→200, "Connected to Docker" | restore backup |
| C3 | uptime-kuma socket mount **removed** (0 docker monitors — unused) | verified healthy | restore backup |
| C4 | Proxy denies writes | `POST /containers/create`→403, `GET /images/json`→403 | n/a |

## D. AI tooling (LIVE verification + recommendations)

| # | Finding | Action |
|---|---|---|
| D1 | Open WebUI signup | Confirmed `ENABLE_SIGNUP:"false"`/`WEBUI_AUTH:"true"`/`DEFAULT_USER_ROLE:"pending"` in compose (persists recreate) — no change needed |
| D2 | AI tool containers | Verified no SSH/`docker.sock`/broad host mounts — already least-privilege |
| D3 | Ollama wildcard bind | Firewall-contained (UFW default-deny); documented `ufw deny in on tailscale0 … 11434` option | 
| D4 | Profiles + log retention | Documented (see `AI_TOOLING_HARDENING_REPORT.md`) |

## E. Alerting (LIVE — on box)

| # | Change | Detail | Rollback |
|---|---|---|---|
| E1 | `/opt/mbfd/alerts.sh` + cron `*/15 * * * *` | Detects: disk>85%, pg down, app down, cloudflared down, restic stale, unhealthy/restarting containers, failed_jobs>25, SSH auth-failure spike. Logs to `/var/log/mbfd-alerts.log`; POSTs to `/opt/mbfd/secrets/alert-webhook` if present | remove cron + script |
| E2 | Delivery channel | Uptime Kuma has **no notification configured**; owner should populate `alert-webhook` or add a Kuma channel | — |

## F. Baserow decommission

| # | Change | Status |
|---|---|---|
| F1 | DNS removed (A6) | ✅ live |
| F2 | Container `mbfd-hub-baserow` stopped + `restart=no` (volume preserved, in backups) | ✅ live |
| F3 | Code removal (HealthCheck, webhook, BaserowClient, Filament External resources, compose service, env, scripts, docs) | via agent on branch — merged at deploy (see deployment verification) |

## G. Code changes (via delegated agents — merged into the security branches)
- **P3 Public API hardening** (apparatus inspection mutation gate + public station field redaction + response-schema tests) — see `PUBLIC_API_HARDENING_REPORT.md`.
- **P4 Private storage migration** (sensitive files → private disk + authorized/signed serving + tests) — see `PRIVATE_STORAGE_MIGRATION_PLAN.md`.
- **P7 Media Control** (per-socket display-control rate-limit + queue depth, `audit_log` with redaction, DNS-based SSRF policy) — commit `81aaec4`, 158/158 tests.

## H. Documented / deferred (maintenance window or owner)
- **Container image digest pinning** (P7): pin mutable `:latest`/major tags by `@sha256:` digest in a maintenance window. Approach: `docker inspect --format '{{index .RepoDigests 0}}' <image>` per running container, then replace the tag in each compose with the digest; recreate one stack at a time and verify health. Socket-proxy is already pinned to `:0.3.0`.
- **CF rate-limiting plan upgrade** to add per-path rules (public-API write, upload, AI, webhook) and longer windows.
- **Secret rotation** — `DEFERRED_OWNER_SECRET_ROTATION.md`.
