# Security Remediation Log

## Local Code/Config Changes

| Area | Change | Files | Rollback |
|---|---|---|---|
| Laravel workgroup auth | Added `workgroup.access` alias; applied to workgroup files, reports, exports, and AI routes | `bootstrap/app.php`, `routes/web.php`, `routes/api.php` | Revert those route/middleware edits |
| Laravel admin API | Added route-level admin role and throttles for audit/admin API groups | `routes/api.php` | Remove added middleware values |
| CSP report sink | Added throttle and log truncation/body-size guard | `routes/web.php`, `app/Http/Controllers/CspReportController.php` | Revert route middleware/controller truncation |
| Session cookies | Production defaults now secure/encrypted unless explicitly overridden | `config/session.php` | Set env overrides or revert defaults |
| Admin PWA | No longer caches authenticated admin HTML/JSON; cache version bumped | `public/admin-pwa/service-worker.js` | Revert SW to previous version; browsers may retain old cache until update |
| CI security | Blocking Composer/npm/Trivy/CodeQL high-risk checks | `.github/workflows/security.yml`, `.github/workflows/03-trivy-repo.yml` | Revert workflow changes if CI blocks emergency deploy; do not leave advisory permanently |
| Deploy determinism | Replaced production `npm install` with `npm ci` | `.github/workflows/deploy.yml` | Revert only if lockfile drift blocks deploy; fix lockfile instead |
| Debug workflow | Added production gate, timeout, permissions, log redaction | `.github/workflows/troubleshoot.yml` | Remove environment gate for emergency only |
| Dependabot | Added nested npm ecosystem coverage | `.github/dependabot.yml` | Remove entries if a directory is archived/decommissioned |
| Lighthouse | Disabled temporary public report storage | `.github/workflows/lighthouse.yml` | Re-enable only if public reports are explicitly acceptable |
| Backup secret | Removed literal Snipe-IT DB password from ignored backup script | `backup.sh` | Set `SNIPEIT_DB_PASSWORD` env file; do not reintroduce literal secret |
| Observability | Removed Docker socket mount from Uptime Kuma ignored compose | `observability-compose.yaml` | Restore temporarily only for loopback-only monitoring |
| Media Control upload | Shared allowlist with TUS; rejects SVG/HTML/JS; nosniff on public file routes | `D:/GitHub_Repos/media-control/server/*` | Revert media-control branch commit if uploads break; keep SVG/HTML blocked |
| Media Control provisioning | Disabled legacy pairing endpoint; pair route requires write-tier and returns minimized response | `D:/GitHub_Repos/media-control/server/*` | Re-enable only behind workspace write auth and minimized response |

## Live Settings Changes

| Platform | Change | Verification | Rollback |
|---|---|---|---|
| GitHub `pdarleyjr/mbfd-hub` | Enforced repository Actions SHA pinning | `sha_pinning_required: true` | `gh api --method PUT repos/pdarleyjr/mbfd-hub/actions/permissions -F enabled=true -f allowed_actions=all -F sha_pinning_required=false` |
| GitHub `pdarleyjr/media-control` | Enforced repository Actions SHA pinning | `sha_pinning_required: true` | Same API call against `media-control` |
| Local git remotes | Removed embedded PAT from MBFD Hub `origin` and `vacation` remotes | Remote URLs now plain HTTPS | Re-add normal credential helper/token if needed, not in URL |

## Verification Run

- `php artisan test tests/Feature/SecurityHardeningRoutesTest.php`: passed with PHP deprecation notices.
- `node --check public/admin-pwa/service-worker.js`: passed.
- `node --check server/server.js`, `server/lib/finalize-upload.js`, `server/middleware/upload.js`: passed.
- `node --test server/test/upload-policy.test.js`: passed.

## Cleanup Notes

- Removed clearly disposable generated artifacts: `.playwright-mcp/`, `tests/e2e/screenshots/`, `tests/e2e/.auth/`, one leftover Media Control upload-policy temp DB directory from a failed test run, and zero-byte root files with shell/JS-fragment names left by prior broken commands.
- Deliberately retained non-empty images, markdown notes, analysis data, generated docs, and domain-specific artifacts because they may be useful project evidence or user data.
