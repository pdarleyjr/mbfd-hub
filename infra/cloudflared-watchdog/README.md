# MBFD Cloudflare Tunnel Watchdog

Durable, conservative protection for the public Media Control path served through
the on-host `cloudflared` connector. **Restarts only `cloudflared`** — never Media
Control, OBS, the podium, audio, or cameras.

## Canonical hostname (verified 2026-07-22)
- **`media.mbfdhub.com`** — classroom-facing Media Control (origin
  `http://localhost:8096`). 302→`/app`, `/login` 200, `/api/status` 200,
  `/socket.io` 200. No Cloudflare Access gate. This is the hostname the podium
  and web controller use.
- **`media-control.mbfdhub.com`** — Cloudflare-Access-protected admin variant
  (302→`darl.cloudflareaccess.com`). Same tunnel, dashboard-managed ingress.

Both resolve to the same Cloudflare anycast IPs; the tunnel is token-based
(`/run/credentials/cloudflared.service/cloudflare-tunnel-token`) with ingress
configured in the Cloudflare Zero Trust dashboard (no local `config.yml`).

## Root cause this guards against
At 2026-07-22 11:41 EDT a stale/degraded tunnel connection emitted
`Application error 0x0 (remote)` / `accept stream listener encountered a failure`
and caused mid-response request cancellations
(`stream ... canceled by remote with error code 0`) for static assets
(`/css/smartboard.css`, `/css/console.css`, `/sw-admin.js`) on `media.mbfdhub.com`.
The prior session restored service by restarting `cloudflared`, which re-registered
four fresh QUIC connections — but a one-off restart is not durable acceptance.

## How it works
1. Every 60s the timer fires `mbfd-cloudflared-watchdog.py`.
2. It checks the public path: `GET /api/status` (expect 200) and the Socket.IO
   polling endpoint (expect not-5xx / not-network-error). It also reads the
   recent count of `Registered tunnel connection` lines from journald as a
   best-effort signal.
3. On a healthy check, the consecutive-failure counter resets.
4. On an unhealthy check, the counter increments. **No action is taken until
   `FAILURE_THRESHOLD` (default 3) consecutive failures** occur — a single
   transient failure never triggers a restart.
5. Once the threshold is reached, the watchdog restarts `cloudflared` only if
   the `COOLDOWN_SECONDS` (default 300) has elapsed since the last restart.
6. **Restart-storm protection**: if `MAX_RESTARTS_PER_HOUR` (default 3) is
   reached, the watchdog stops restarting and emits an alert (exit 2) for
   Uptime Kuma / on-call.
7. After any restart it verifies recovery (polls the public path for up to 30s).

State is persisted in `/var/lib/mbfd-cloudflared-watchdog/state.json`:
`consecutive_failures`, `last_restart`, and a trimmed `restarts` history.

## Exit codes
- `0` healthy, or recovered after restart.
- `1` unhealthy but below threshold / within cooldown (no action).
- `2` alert-only (storm cap reached, or recovery failed).

All three are treated as success by the systemd unit so the timer keeps running;
alerting is via Uptime Kuma watching the unit's exit status / logs.

## Deployment (POST-CLASS maintenance window only)
```sh
sudo install -m 0755 infra/cloudflared-watchdog/mbfd-cloudflared-watchdog.py /usr/local/sbin/
sudo install -m 0644 infra/cloudflared-watchdog/mbfd-cloudflared-watchdog.service /etc/systemd/system/
sudo install -m 0644 infra/cloudflared-watchdog/mbfd-cloudflared-watchdog.timer /etc/systemd/system/
sudo systemctl daemon-reload
# Dry-run first (logs only, no restart):
sudo /usr/local/sbin/mbfd-cloudflared-watchdog.py --dry-run
sudo systemctl enable --now mbfd-cloudflared-watchdog.timer
```

## Uptime Kuma monitor (port 3001, on-host)
Add a monitor for the watchdog unit exit status and for the public path:
- **HTTP(s) monitor**: `https://media.mbfdhub.com/api/status`, keyword `ok`,
  60s interval, retry 3.
- **HTTP(s) monitor**: `https://media.mbfdhub.com/socket.io/?EIO=4&transport=polling`.
- **Push monitor** (optional): the watchdog can POST to a Uptime Kuma push URL
  on each cycle; alert when pushes stop or when exit code is 2.

## Active-class safety
The watchdog restarts **only** `cloudflared`. It must not be enabled during an
active class unless the public service is confirmed unavailable AND local Media
Control health is confirmed first AND the user authorizes emergency restoration
(per the active-class safety boundary). Default deployment is post-class.
