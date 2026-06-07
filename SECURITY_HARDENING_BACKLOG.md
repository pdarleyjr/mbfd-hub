# Security Hardening Backlog

## Immediate

- Rotate exposed GitHub, Cloudflare, R2, and Snipe-IT DB credentials.
- Export Cloudflare DNS/Tunnel/Access/WAF/rate-limit configuration and classify every hostname.
- Confirm Baserow, Snipe-IT, Open WebUI, status, admin dashboard, Dozzle, Uptime Kuma, and Web-Check are not publicly reachable without Access/app auth.
- Create protected env file for `backup.sh` and rotate Snipe-IT DB password.
- Update vulnerable Composer/npm lockfiles in controlled PRs.

## Short Term

- Move public inventory PDFs and workgroup uploads to private storage.
- Convert apparatus public inspection status mutation to pending review with signed/PIN/auth controls.
- Redact public station APIs and add response-schema tests.
- Add signed playback mode for sensitive Media Control decks/assets.
- Add URL allow/block policy for Media Control remote URLs, widgets, kiosks, and broadcasts.
- Replace Dozzle Docker socket mount with a restricted socket proxy.
- Disable Open WebUI signup and audit AI tool permissions.

## Medium Term

- Encrypted offhost backups with quarterly restore drills.
- GitHub repo rulesets/branch protections and reviewed deployment environments.
- Cloudflare WAF/rate-limit/Turnstile tuning based on logs.
- Docker image digest pinning and Renovate/Dependabot digest updates.
- Systemd hardening for local app services and Pi/all-in-one installs.
- Network segmentation for displays vs admin/server.

## Long Term

- Formal asset inventory automation.
- Central audit event model for auth/admin/display/AI actions.
- Security regression suite for public/private route contracts.
- Incident simulations for token leak, display takeover, backup restore, tunnel outage, and AI tool abuse.
