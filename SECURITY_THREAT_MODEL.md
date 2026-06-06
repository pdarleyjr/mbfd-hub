# MBFD Hub Threat Model

## Attacker Profiles

- Random internet scanner probing `.env`, WordPress/phpMyAdmin paths, Laravel debug, Git metadata, backup files, and exposed admin tools.
- Credential-stuffing bot targeting Filament, Baserow, Snipe-IT, Nextcloud, Open WebUI, Media Control, and worker PIN gates.
- Opportunistic self-hosting attacker exploiting the misconception that Cloudflare Tunnel means private access.
- Supply-chain attacker compromising npm/composer/GitHub Actions dependencies or third-party actions.
- Low-privilege authenticated user attempting IDOR/BOLA, workgroup report access, station data enumeration, or display-control abuse.
- Display-control attacker attempting malicious URLs, stored XSS, token theft, fake display pairing, or kiosk takeover.
- AI/prompt-injection attacker attempting URL-loader SSRF, tool abuse, secret discovery, data exfiltration, or resource exhaustion.
- Insider/misconfiguration risk from overbroad PATs, local `.env` files, logs, backups, and ignored deployment scripts.

## Critical Trust Boundaries

- Cloudflare edge to loopback origins: every hostname must be classified as public, Access-protected, app-auth-only, deprecated, or blocked.
- Public app/API to Laravel controllers: public routes must not mutate operational state or expose internal station/equipment/personnel data.
- Authenticated user to workgroup/admin: route middleware and object-level policies must match panel authorization.
- Media Control dashboard to display devices: only write-tier users should control displays; public playback must not expose sensitive content by accident.
- AI tools to files/URLs/shell: prompt and document content must not grant filesystem, token, database, or shell access.
- GitHub Actions to production runner: self-hosted runner workflows are production code and must be pinned, gated, and minimally privileged.
- Backups/logs to operators: backup and log stores contain secrets or private data and require encryption, retention, and access controls.

## High-Impact Scenarios

| Scenario | Likelihood | Impact | Status |
|---|---:|---:|---|
| Public admin/internal service reachable through a Tunnel without Access | Medium | Critical | Needs live Cloudflare export; repo/docs show several high-value hostnames |
| Exposed token/PAT/R2 key reused by attacker | High | Critical | Rotation required; local embedded GitHub PAT was removed from git remotes |
| Unauthenticated apparatus inspection changes operational status | Medium | High | Not changed due workflow risk; backlog requires signed/PIN/auth review flow |
| Authenticated non-workgroup user reads reports/exports | Medium | High | Route-level `workgroup.access` added and tested |
| Media Control TUS upload serves script-capable content | Medium | High | MIME allowlist shared with multipart path and tested |
| Legacy display provisioning leaks full device row | Medium | High | Legacy endpoint disabled; pairing now write-tier and minimized |
| Prompt-injected AI reads secrets or uses tools | Medium | High | Config findings documented; default profiles must be restricted |
| Local disk/host compromise destroys backups | Medium | High | Offhost encrypted backup remains required |

## Security Objectives

- No public DB/Redis/Docker/log/admin tools without strong Access and app auth.
- No production secrets in git, local remotes, logs, frontend bundles, or reports.
- Admin and display-control actions require authenticated, authorized, auditable users.
- Public endpoints are minimal, rate-limited, logged, and safe under bot traffic.
- CI/CD uses pinned actions, deterministic installs, blocking high/critical security gates, and protected production environments.
- AI tools are least-privilege, local/private by default, and isolated from secrets.
