# Edge maintenance page ("Updating MBFD Hub")

Members see a branded **Updating MBFD Hub** page whenever the Hub cannot answer, without depending
on the Hub container, its database, its fonts or its build.

## Two layers

| When | Who answers | What members see |
|---|---|---|
| Planned deploy, container running, `php artisan down` | Hub container (Laravel pre-rendered `errors::503`) | Updating MBFD Hub, auto-retry every 30 s, **Try again** |
| Container stopped or being replaced (image switch), or crashed | Host nginx edge listener `127.0.0.1:8079` on GMKtec | Same page from `/var/www/mbfd-hub-edge/maintenance.html`; JSON 503 for API/fetch callers |
| Hub running normally | Hub container | Normal Hub. The edge passes every request through unchanged |

The edge replaces **only** nginx's own 502/504 (no TCP connection to the container). Every status the Hub
sends itself, including its own 503 and API errors, passes through untouched (`proxy_intercept_errors off`).
Page loads (`Accept: text/html`) get HTML; everything else (workers, `fetch`, webhooks, `/app-release`)
gets `{"status":"updating"}` JSON with `Retry-After: 30`, never a misleading HTML body.
The page polls `/app-release` and reloads the member's page as soon as it returns 200.

## Files

| Repo source | Installed on GMKtec |
|---|---|
| `infra/edge-maintenance/mbfd-hub-edge.conf` | `/etc/nginx/conf.d/mbfd-hub-edge-8079.conf` |
| `infra/edge-maintenance/maintenance.html` | `/var/www/mbfd-hub-edge/maintenance.html` |
| `infra/edge-maintenance/unavailable.json` | `/var/www/mbfd-hub-edge/unavailable.json` |
| `public/images/mbfd-official-seal-256.png` | `/var/www/mbfd-hub-edge/mbfd-official-seal-256.png` |

Cloudflare tunnel `mbfdhub-gmktec` (`20cb894c-…`) ingress: `www.mbfdhub.com` (catch-all rule),
`mbfdhub.com`, `files.mbfdhub.com` and `rosters.mbfdhub.com` point at `http://localhost:8079`.
The `/app/*` and `/apps/*` websocket rules stay on `http://localhost:8090` (Reverb), unchanged.
Changed 2026-11-06 (ingress version 24 → 25). Pre-change export:
`E:\MBFD_INFRASTRUCTURE_2026\07_BACKUPS\Cloudflare\tunnel-mbfdhub-gmktec-ingress-v24-before-edge-maintenance-20261106.json`.

## Install or update

```bash
scp infra/edge-maintenance/{mbfd-hub-edge.conf,maintenance.html,unavailable.json} public/images/mbfd-official-seal-256.png gmktec:/tmp/
ssh gmktec 'cd /tmp && sudo install -d -m 0755 /var/www/mbfd-hub-edge \
  && sudo install -m 0644 maintenance.html unavailable.json mbfd-official-seal-256.png /var/www/mbfd-hub-edge/ \
  && sudo install -m 0644 mbfd-hub-edge.conf /etc/nginx/conf.d/mbfd-hub-edge-8079.conf \
  && sudo nginx -t && sudo systemctl reload nginx'
```

## Verify

```bash
curl -s https://www.mbfdhub.com/__mbfd-edge/mbfd-official-seal-256.png -o /dev/null -w '%{http_code}\n'   # 200 = traffic flows through the edge
ssh gmktec 'sudo tail /var/log/nginx/mbfd-hub-edge.error.log'                                          # connect() failures appear here during outages
```

Failure-path test without touching production: copy the conf to a temporary listener on `:8078` whose
`proxy_pass` targets a dead port, request a page and an API path, then remove it (the 2026-11-06 install
recorded: page → 503 HTML "Updating MBFD Hub", API → 503 JSON, `Retry-After: 30`).

## Rollback

1. Restore the tunnel ingress from the export above (PUT its `result.config` to
   `/accounts/265122b6…/cfd_tunnel/20cb894c-a5b0-4149-bc11-1499d772401e/configurations`), or edit the four
   rules back to `http://localhost:8080` in the Zero Trust dashboard. Takes effect in seconds.
2. Optionally remove `/etc/nginx/conf.d/mbfd-hub-edge-8079.conf` and reload nginx. Leaving it is harmless.
