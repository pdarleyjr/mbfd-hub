# Incident Response Runbook

## Contacts and Channels

- Owner: MBFD Hub operator/admin.
- Primary systems: GMKtec Ubuntu server, GitHub repos, Cloudflare account, GitHub Actions, app dashboards.
- Do not paste secrets in chat/tickets. Use password manager or provider secret stores.

## First 15 Minutes

1. Preserve availability unless active compromise requires isolation.
2. Identify affected asset/hostname/account.
3. Capture timestamps, Cloudflare Ray IDs, request IDs, GitHub run IDs, and container names.
4. Stop credential spread: revoke/rotate exposed tokens, remove embedded credentials from remotes/env/logs.
5. If display-control compromise is suspected, switch displays to a safe local/static mode and disable remote control routes if necessary.

## Triage Commands (Read-Only)

- Git status: `git status --short --branch`
- Server health: `ssh mbfd@gmktec 'docker ps --format "table {{.Names}}\t{{.Status}}"'`
- Local app health: `curl -fsS http://127.0.0.1:8096/api/version` on GMKtec for Media Control.
- Cloudflared: `systemctl status cloudflared --no-pager`
- Auth logs: review `journalctl`/app logs with redaction; do not paste raw secrets.

## Detection Checklist

- Failed login spike.
- Password reset spike.
- New admin user or role change.
- Admin route probing.
- WAF/security event spike.
- Cloudflare Tunnel down.
- DB/Redis down.
- Disk usage > 85%.
- Queue failures or stuck jobs.
- Reverb/WebSocket failure.
- AI endpoint abuse/cost spike.
- Unauthorized display-control attempt.
- GitHub secret scanning or workflow failure.
- Package vulnerability alert.

## Credential Leak Procedure

1. Revoke leaked token/key.
2. Create least-privilege replacement.
3. Update GitHub/Cloudflare/app secrets by name only in docs.
4. Remove embedded token from git remotes/config/scripts.
5. Search for file paths containing the token pattern without printing values.
6. Invalidate sessions if user auth tokens are exposed.

## Display-Control Incident

1. Disable public/player route for affected content if needed.
2. Rotate display/device tokens.
3. Remove malicious playlist/scene/widget/media.
4. Review audit logs and WebSocket events.
5. Re-pair trusted displays only.

## Recovery

- Restore from tested backup only after validating clean state.
- Rebuild containers from known-good commits.
- Re-enable Cloudflare routes one at a time.
- Document root cause, containment, eradication, recovery, and follow-up controls.
