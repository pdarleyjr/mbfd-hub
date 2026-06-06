# Cloudflare Review

## Status

Cloudflare live API review was blocked: the configured Cloudflare MCP returned `403 Authentication error`, and no `CLOUDFLARE_API_TOKEN` environment variable was present. I did not paste provided token values into shell commands to avoid putting secrets in transcript/tool logs.

## Evidence Reviewed From Repo/Docs

- `cloudflared-config.yml` legacy tunnel routes for MBFD Hub, Baserow, NocoBase, inventory/Snipe-IT.
- `.cf-work/*` ignored tunnel config snapshots noted by subagent, not committed.
- GMKtec docs describing remote-managed tunnel routes for `cloud`, `office`, `ai`, `admin`, `status`, `ts`, `media`, `vacation`, and origin hostnames.
- Cloudflare Worker configs under `cloudflare-worker` and `vacation-app/apps/pin-gate`.
- Live server confirms `cloudflared` active/enabled and many loopback origins.

## Findings

- Cloudflare Tunnel should not be considered private. Every public hostname must have Access, app auth, WAF/rate limits, or an explicit public classification.
- ONLYOFFICE appears intentionally Access-bypassed for iframe/callback compatibility; it needs JWT verification, WAF, path restrictions, and a dated exception record.
- `vacation-origin.mbfdhub.com` and legacy/test hostnames require direct-bypass validation.
- Baserow/Snipe-IT/status/admin/AI hostnames must be verified against live Access policies.
- Cloudflare tokens provided in prompt are exposed and require rotation.

## Required Live Export Checklist

1. DNS records with proxied/gray-cloud status.
2. Tunnel list and ingress rules.
3. Access applications and policies.
4. WAF managed rules/custom rules/rate-limit rules/bot settings.
5. Worker routes and secrets by name only.
6. Security events for `.env`, admin, WordPress/phpMyAdmin probes, auth failures, and bot spikes.

## Safe Recommended Rules

- Challenge or rate limit login/admin paths.
- Rate limit public write APIs, upload paths, AI endpoints, webhooks, and Media Control control-plane routes.
- Block known scanner paths: `/.env`, `/.git/*`, `/wp-*`, `/phpmyadmin`, backup extensions, debug tooling.
- Access-protect all admin tools: Baserow, Snipe-IT, Open WebUI, Dozzle, Uptime Kuma, Web-Check, code/admin dashboards.
