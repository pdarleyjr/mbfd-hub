# Final Security Report

Assessment date: 2026-06-06  
Branches: `security/ecosystem-hardening-20260606` in MBFD Hub, `security/display-hardening-20260606` in Media Control.

## Executive Summary

The MBFD Hub ecosystem is now harder to attack in several important areas: workgroup report access is role-gated, admin/API telemetry is throttled, admin PWA no longer caches authenticated content, CI security gates are blocking, GitHub Actions SHA pinning is enforced live, deterministic production npm installs are configured, a local embedded GitHub PAT was removed from git remotes, and Media Control upload/provisioning flaws were hardened with tests.

The most important residual risk is credential rotation. The prompt contained live credentials, and a local git remote had an embedded GitHub PAT before remediation. Those values must be treated as exposed and rotated. Cloudflare live configuration could not be fully verified because the Cloudflare MCP returned 403 and no token was available in the local environment without pasting secrets into shell commands.

## What Was Reviewed

- MBFD Hub Laravel routes, middleware, Filament panels, API routes, PWA/service worker, session config, CI/CD, dependency posture, ignored ops scripts.
- Media Control upload, provisioning, public file serving, display-control surfaces, and tests.
- GitHub repo metadata, secrets by name only, Actions permissions, workflows.
- GMKtec live server metadata: OS, UFW, SSHD effective config, listening ports, Docker containers, services.
- Cloudflare/Tunnel/Access posture from repo/docs; live Cloudflare API blocked.
- AI, observability, backup, database, and network posture from repo/docs and safe host metadata.

## What Was Exposed

- GitHub/Cloudflare/R2 credentials were pasted into the prompt and require rotation.
- A GitHub PAT was embedded in local MBFD Hub git remote URLs; removed.
- Snipe-IT DB password was hardcoded in ignored `backup.sh`; removed from script but must be rotated.
- Public station/apparatus APIs expose more operational data and mutation capability than ideal.
- Some high-value Cloudflare Tunnel hostnames remain unverified for live Access coverage.

## What Was Protected / Fixed

- Workgroup files, reports, exports, and AI routes now require `workgroup.access`.
- Admin audit routes now have route-level admin role checks and throttles.
- CSP report endpoint is throttled and bounded.
- Production sessions default to encrypted and secure cookies.
- Admin PWA stops caching authenticated admin content.
- Security workflows now block high/critical findings instead of passing with `|| true`/exit-code 0.
- Production deploy uses `npm ci` instead of `npm install`.
- Troubleshooting workflow is gated, short-lived, minimally permissioned, and redacts obvious secrets from logs.
- Dependabot covers nested npm projects.
- Lighthouse temporary public reports disabled.
- Uptime Kuma Docker socket mount removed from local compose.
- Media Control TUS upload and provisioning hardened.
- GitHub Actions SHA pinning enforced live for `mbfd-hub` and `media-control`.
- Cleanup removed disposable Playwright snapshots, e2e screenshots/auth state, one failed-test temp DB directory, and zero-byte shell/JS-fragment garbage files.

## What Could Not Be Safely Fixed

- Cloudflare DNS/Tunnel/Access/WAF live configuration: blocked by Cloudflare auth; needs secure token handling and export.
- Public apparatus/station workflow redesign: requires operational validation to avoid breaking daily checkout/inspection workflows.
- Public storage migration for PDFs/uploads: requires data migration and URL compatibility plan.
- Server package updates/reboots: require maintenance window.
- Starlink/router Wi-Fi/UPnP/segmentation posture: requires local router/app review.

## Critical Findings

- Exposed credentials require immediate rotation.
- Hardcoded Snipe-IT DB password removed from script, but credential rotation is mandatory.

## High Findings

- Public apparatus inspection mutation risk.
- Public station operational data exposure.
- Workgroup authorization gap fixed.
- Public storage of sensitive PDFs/uploads needs migration.
- Media Control TUS upload bypass fixed.
- Media Control legacy provisioning fixed.
- Cloudflare Access coverage must be live-verified.

## Medium Findings

- CI advisory-only gates fixed.
- Admin PWA authenticated caching fixed.
- CSP report abuse fixed.
- Open WebUI/AI sandbox permissions need hardening.
- Backups need encrypted offhost restore-tested design.
- Docker socket and mutable image risks remain partially open.

## Low Findings

- Dependabot nested coverage fixed.
- Lighthouse public report storage fixed.
- Documentation and runbook gaps reduced with deliverables.

## Secrets Rotated or Requiring Rotation

No secret values are listed.

Requiring rotation: GitHub PAT used locally/provided in prompt, Cloudflare Wrangler/API token, Cloudflare R2 token/key pair for MBFD Hub Laravel, GitHub repo secret `GH_PAT` if same or overbroad, repo secrets `CLOUDFLARE_API_TOKEN`, `R2_ACCESS_KEY_ID`, `R2_SECRET_ACCESS_KEY`, and the Snipe-IT DB backup credential.

## GitHub Changes

- Live: enforced SHA-pinned Actions for `pdarleyjr/mbfd-hub` and `pdarleyjr/media-control`.
- Local workflow hardening committed in branch pending commit: blocking audits, deterministic deploy installs, safer troubleshoot logs, expanded Dependabot.

## Cloudflare Changes

- No live Cloudflare changes were made because Cloudflare auth failed and tokens were not pasted into shell commands.
- Required: live export and route classification.

## Server Changes

- Read-only server review performed.
- Local ignored `backup.sh` and `observability-compose.yaml` hardened; apply/verify on host before relying on them.

## App Changes

- Laravel route/middleware/session/PWA/CSP hardening completed and tested.

## DB Changes

- No database data was read or modified.
- Backup credential handling fixed in script; credential rotation and encrypted offhost backups remain.

## Media Control Changes

- Upload allowlist/TUS finalizer/provisioning/public file nosniff hardening completed and tested in `D:/GitHub_Repos/media-control`.

## AI Changes

- No live AI service config changed. Hardening recommendations documented.

## Network/Starlink Notes

- UFW/SSH/Tailscale posture looks strong from GMKtec metadata.
- Starlink/router details remain unknown and require local review.

## Residual Risk

Residual risk remains highest around unrotated exposed credentials, unverified Cloudflare Access coverage, public operational APIs, public storage migration, and backup/offhost restore posture.

## Immediate Next Steps

1. Rotate exposed credentials and update GitHub/Cloudflare secrets.
2. Export Cloudflare live configuration and protect/retire every non-public hostname.
3. Review and deploy branches during a controlled window.
4. Update vulnerable dependency lockfiles.
5. Implement private storage for sensitive generated/uploaded files.

## Long-Term Roadmap

- Continuous asset inventory and Cloudflare route drift checks.
- Security regression suite for route auth, public response schemas, display-control tokens, and storage privacy.
- Central audit logging and alerting for auth/admin/display/AI actions.
- Encrypted offsite backups with restore drills.
- Network segmentation and AI least-privilege profiles.
