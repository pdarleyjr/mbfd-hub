# Final Security Report — Phase 2 (MBFD Hub Ecosystem)

Assessment + remediation date: 2026-06-06
Operator: automated security pass (continuation of Phase 1)
Scope: MBFD Hub (Laravel), Media Control, Cloudflare edge, GMKtec production host, backups, AI/observability tooling.

## Executive summary

Phase 1 delivered an initial code-level hardening pass but left the highest-residual items open (Cloudflare live config was auth-blocked, public operational APIs, public file storage, encrypted backups, AI/observability exposure, deployment). **Phase 2 obtained live Cloudflare + production access and closed those items**, then merged and **deployed all changes to production with verification and documented rollbacks**.

Net result: the public attack surface is materially reduced (edge WAF/rate-limit, admin tools behind Access, public APIs redacted, anonymous apparatus-out-of-service blocked, sensitive files off the public disk), the homelab now has **encrypted off-host backups with a verified restore**, the Docker socket is no longer directly exposed to log/monitor containers, and the display-control plane has rate-limiting, audit logging, and SSRF protection.

The one unavoidable open item is **owner credential rotation** (several live secrets were pasted into the working transcript and must be treated as exposed) — documented, not performed, per instruction.

## What was deployed to production

- **MBFD Hub (Laravel)** → `main` `92917b5e`, live on GMKtec. Includes: workgroup auth + admin throttles + CSP/session/PWA hardening (Phase 1, now actually deployed), public apparatus inspection pending-review gate, public station + apparatus API redaction, private-disk storage for sensitive files, and full Baserow decommission.
- **Media Control** → `feat/multiview-layout` `4ca7accd` (security merged in), live on GMKtec. Includes: per-socket display-control rate-limit + queue-depth, redacted audit logging (`audit_log`), and DNS-resolution SSRF policy.
- See `PRODUCTION_DEPLOYMENT_VERIFICATION.md` for SHAs, smoke results, and rollback commands.

## Cloudflare (live — applied)
- **WAF scanner-block rule** (blocks `/.env`, `/.git`, `/wp-*`, `/phpmyadmin`, db-dump suffixes) — verified 403.
- **Login/auth brute-force rate-limit** (free-tier: 1 rule/10s/block).
- **CF Access added** for Snipe-IT UI (with an `/api` bypass that preserves the live Laravel↔Snipe-IT integration) and the `gm-test` app alias.
- **Baserow** public exposure removed (DNS deleted) and the service decommissioned.
- Verified-mitigated: `vacation-origin` (X-Origin-Token guard), ONLYOFFICE (JWT exception, dated). Full classification in `CLOUDFLARE_LIVE_ROUTE_REVIEW.md`.

## Host / infra (live — applied)
- **Encrypted off-host backups**: Restic → Cloudflare R2 (`mbfd-hub-backups`), nightly, retained 7d/4w/6m, **restore test PASSED**. Staleness alerting added. (`BACKUP_RESTORE_TEST_REPORT.md`)
- **Docker socket-proxy**: dozzle now reads Docker through a read-only `docker-socket-proxy` (writes denied/403); uptime-kuma's unused socket mount removed. Only the proxy touches `docker.sock`.
- **Host alerting**: `/opt/mbfd/alerts.sh` (disk/DB/app/tunnel/backup/containers/queue/SSH-spike) every 15 min.
- **AI tooling**: verified Open WebUI signup disabled (persisted in compose) and AI containers already least-privilege; recommendations documented. (`AI_TOOLING_HARDENING_REPORT.md`)

## Application security (deployed)
- **H-01** anonymous apparatus → Out-of-Service: **fixed** (pending-review + gated approve endpoint; verified 401). 
- **H-02** public operational data exposure: **fixed** (station + apparatus/checklist redaction; verified no VIN/serial/financials/PII leak).
- **H-04** sensitive files on public disk: **fixed** (private disk + authorized/signed serving + move command). (`PUBLIC_API_HARDENING_REPORT.md`, `PRIVATE_STORAGE_MIGRATION_PLAN.md`)
- Media Control display-control hardening deployed (`audit_log` verified present).

## Residual risk / owner actions
1. **Credential rotation (Critical)** — rotate the exposed CF token, R2 keys, GitHub PAT, stale box CF token, Snipe-IT DB password; record the new Restic passphrase. **`DEFERRED_OWNER_SECRET_ROTATION.md`** (no values).
2. **Deploy-workflow SSH-config clobber** — the self-hosted deploy job overwrites `~/.ssh/config`, breaking the manual git remote aliases (restored this session). Fix the workflow to append.
3. **Stale committed Vite assets** — require a rebuild every deploy; commit a complete set or gitignore `public/build`.
4. **CF rate-limiting is Free-tier** (1 rule/10s) — upgrade for per-path rules (public-write/upload/AI/webhook) and longer windows.
5. **`ts.mbfdhub.com`** app-auth only; **employee roster** is public for the kiosk operator dropdown (consider PIN-gating the daily-checkout); **office** path-restriction; **image digest pinning**; **Nextcloud user-data** in backups. (`SECURITY_FINDINGS_PHASE_2.md`)
6. **50 Dependabot alerts** on the default branch (2 critical/17 high) — update lockfiles in reviewed PRs (Dependabot branches already opened).
7. **Media Control origin git hygiene** — push `feat/multiview-layout` with the security merge from a push-capable machine.

## Deliverables (this phase)
`FINAL_SECURITY_REPORT_PHASE_2.md` (this), `SECURITY_FINDINGS_PHASE_2.md`, `SECURITY_REMEDIATION_LOG_PHASE_2.md`, `CLOUDFLARE_LIVE_ROUTE_REVIEW.md`, `PRODUCTION_DEPLOYMENT_VERIFICATION.md`, `PUBLIC_API_HARDENING_REPORT.md`, `PRIVATE_STORAGE_MIGRATION_PLAN.md`, `AI_TOOLING_HARDENING_REPORT.md`, `BACKUP_RESTORE_TEST_REPORT.md`, `DEFERRED_OWNER_SECRET_ROTATION.md`.

## Secret-handling compliance
No secrets were rotated. No secret values appear in any deliverable, commit, or log. Credentials provided in the transcript were used only to gain authorized access (Cloudflare live review, R2 backups), kept out of all outputs/reports/commits via a single session-local 0600 file (deleted at session end), and are listed by name/purpose for owner rotation.

## Final status
Cloudflare live config reviewed + corrected ✓ · security branches deployed + verified ✓ · public apparatus/station risks fixed ✓ · sensitive files off public storage ✓ · AI least-privilege verified + documented ✓ · encrypted off-host backups + restore test ✓ · no secrets rotated/printed/committed ✓ · owner rotations documented as deferred ✓ · production stable ✓.
