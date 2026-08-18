# MBFD Video Conferencing Runbook

## Scope and safety boundary

This feature uses self-hosted LiveKit only for SFU signaling/media. Laravel remains authoritative for employee authentication, room selection, short-lived join tokens, fixed-endpoint takeover, moderation authorization, and audit lifecycle. Reverb does not transport media. Recording/egress, SIP, arbitrary room names, guest links, and remote camera control are intentionally absent.

The application feature flag defaults to `false`. Keep it false until DNS, trusted TLS, firewall paths, webhook delivery, and a real three-device acceptance run are complete.

## Production topology

- `video.<domain>`: DNS-only record to the LiveKit host; the official LiveKit Layer 4 Caddy pattern terminates trusted HTTPS/WSS on TCP 443 and proxies to `127.0.0.1:7880`.
- `turn.<domain>`: DNS-only record to the same host; Caddy selects it by SNI on TCP 443, terminates TLS, and proxies unencrypted TURN to LiveKit's loopback TCP 5349 listener. LiveKit advertises TURN/TLS to clients on 443.
- RTC reaches the host directly on TCP 7881 and UDP 50000-60000.
- Dedicated Redis is bound only to host loopback port 6380. It is not publicly reachable.
- Cloudflare Tunnel/proxy cannot carry the required UDP media paths. Do not orange-cloud the LiveKit or TURN records.

For a host with a public address, required inbound firewall rules are TCP 443 for signaling and TURN/TLS, UDP 3478 for TURN/UDP, TCP 7881 for RTC fallback, and UDP 50000-60000 for WebRTC. TCP 5349 is an internal Caddy-to-LiveKit hop and must not be exposed. TCP 80 is needed only when Caddy performs HTTP certificate issuance; DNS-01 issuance does not need it. Restrict SSH and metrics port 6789 to the management network/Tailscale; never expose Redis.

The GMKtec production host is behind Starlink CGNAT and its routed IPv6 is filtered by the Starlink router. It therefore cannot accept general public-internet WebRTC traffic directly. Its supported deployment mode is Tailnet-only: both DNS-only records resolve to the GMKtec Tailscale address, all conference endpoints must have Tailscale connected, `LIVEKIT_NODE_IP` is set to that routable Tailscale address while external-IP discovery remains disabled, the host firewall remains default-deny off Tailnet, and certificates are issued by a public CA with Cloudflare DNS-01 validation. A future non-Tailscale deployment requires a public-IP VPS/L4 relay or LiveKit Cloud; a Cloudflare HTTP tunnel is not a substitute for LiveKit UDP/TURN reachability.

Current Chromium browsers classify the Tailscale endpoint as local address space. Chrome and Edge users must grant the employee portal's Local Network Access prompt in addition to connecting Tailscale; the application performs a bounded browser reachability check before it creates a room so this permission is requested explicitly and failures are actionable. Firefox does not currently enforce that Chromium permission. A failed preflight must not create a LiveKit room or participation row.

## Install

1. Generate a unique API key, at least 32 random API-secret characters, and a separate 4-8 digit 300 command PIN. Store the PIN only in the approved operator secret store and put a one-way application hash in `VIDEO_CONFERENCING_COMMAND_PIN_HASH`; never store or commit the plaintext PIN in `.env`.
2. Obtain one trusted certificate covering both signaling and TURN names. For the GMKtec Tailnet deployment, use Cloudflare DNS-01 validation. Copy the resulting certificate to `fullchain.pem` and key to `privkey.pem` under the root-owned directory identified by `LIVEKIT_CERT_DIR`; never place the DNS API token in the repository or container environment.
3. Copy `infra/livekit/caddy.yaml.example` to the path identified by `LIVEKIT_CADDY_CONFIG`, replace both example SNI names, and validate it with the pinned Caddy image. Copy `infra/livekit/.env.example` to an operator-owned secret file outside the repository and set all values. In the Tailnet-only topology, set `LIVEKIT_NODE_IP` to the LiveKit host's Tailscale address; do not use a CGNAT address discovered through STUN.
4. Validate without starting services:

   ```sh
   docker compose --env-file /secure/path/livekit.env -f infra/livekit/compose.yaml config --quiet
   ```

5. Start Caddy, Redis, and the pinned LiveKit server:

   ```sh
   docker compose --env-file /secure/path/livekit.env -f infra/livekit/compose.yaml up -d
   docker compose --env-file /secure/path/livekit.env -f infra/livekit/compose.yaml ps
   docker compose --env-file /secure/path/livekit.env -f infra/livekit/compose.yaml logs --tail=100 livekit
   ```

6. Configure Laravel with browser-facing `LIVEKIT_URL=wss://...`, a server-side `LIVEKIT_API_URL`, the matching key/secret, `VIDEO_CONFERENCING_COMMAND_PIN_HASH`, and an optional `VIDEO_CONFERENCING_LINEUP_TIME=HH:MM`. A Laravel container co-located with host-networked LiveKit should use the host gateway (for example, `http://172.20.5.1:7880`) rather than hairpinning through its Tailscale/public DNS name. Permit TCP 7880 only from the exact application Docker subnet; keep it closed to public and Tailnet clients. A remote application host should use the trusted HTTPS API endpoint. Keep `VIDEO_CONFERENCING_ENABLED=false`, then run migrations and clear cached config.
7. Verify signed webhook delivery to `/webhooks/livekit`, then enable the feature and clear cached config again.

The Caddy certificate reload hook should run `docker exec mbfd-livekit-caddy caddy reload --config /etc/caddy.yaml --adapter yaml` after renewal. Validate automatic renewal with the CA client's dry-run command before enabling the feature.

Remote unmute must remain disabled in LiveKit. The 300 UI uses signed Laravel moderation calls for server-side mute and the `mbfd.stationMic` client RPC for a station to unmute its own microphone.

## Health and observability

- Alert on LiveKit process/container restarts, Redis health, host CPU, memory, packet loss, and network saturation.
- Set `vm.overcommit_memory=1` persistently on the LiveKit host so Redis background persistence remains reliable under memory pressure.
- Scrape Prometheus metrics from `127.0.0.1:6789/metrics` through the private monitoring network only.
- Monitor Laravel for `ConferenceUnavailableException`, HTTP 409 takeover rates, 401 webhook failures, token endpoint throttles, and incomplete participation rows.
- Probe `/admin/video-conferencing/health` with an authenticated administrator. It returns `disabled`, `healthy`, `degraded`, or `unavailable` and never exposes secrets. Three or more sanitized browser connection failures in the rolling 15-minute window mark the client path degraded even when the server-side LiveKit API remains healthy.
- Webhook rows store event ID/type, opaque session ID, participant identity, and timestamps. Raw payloads, employee IDs in participant names, API secrets, and tokens are not logged.

## Integration acceptance

The integration compose file is explicitly non-production and contains test-only credentials:

```powershell
docker compose -f infra/livekit/compose.integration.yaml up -d
$env:VIDEO_CONFERENCING_ENABLED='true'
$env:LIVEKIT_URL='ws://127.0.0.1:7880'
$env:LIVEKIT_API_URL='http://127.0.0.1:7880'
$env:LIVEKIT_API_KEY='mbfd-integration-key'
$env:LIVEKIT_API_SECRET='mbfd-integration-secret-not-for-production'
$env:VIDEO_CONFERENCING_COMMAND_PIN_HASH = php -r "echo password_hash('246810', PASSWORD_BCRYPT);"
$env:VIDEO_CONFERENCING_E2E_EMPLOYEE_ID='VC-E2E'
$env:VIDEO_CONFERENCING_E2E_PASSWORD='<local-unique-password>'
$env:VIDEO_CONFERENCING_E2E_COMMAND_PIN='246810'
$env:VIDEO_CONFERENCING_E2E_BASE_URL='http://127.0.0.1:8000'
php artisan migrate
php artisan db:seed --class=VideoConferenceIntegrationSeeder
php artisan serve --host=127.0.0.1 --port=8000
```

In a second PowerShell window with the same two E2E variables:

```powershell
npx playwright test --config=playwright.video-conferencing.config.ts
```

This opens independent 300, Station 1, and Station 2 contexts with fake media, joins the same opaque lineup, checks all three tiles, verifies station starts muted, and exercises the verified 300 RPC path. Afterward, stop the app and run `docker compose -f infra/livekit/compose.integration.yaml down`. Do not use `-v` unless intentionally deleting the disposable integration volume.

For a production TURN/TLS acceptance run, set the production fixture's `VIDEO_CONFERENCING_E2E_COMMAND_PIN` and `VIDEO_CONFERENCING_E2E_FORCE_RELAY=true` before starting Playwright. The test then adds the non-sensitive `force_relay=1` diagnostic query, connects all three LiveKit clients with WebRTC `iceTransportPolicy: relay`, verifies that every peer connection retained that policy, and fails unless the three endpoints connect and moderation still works. Successful connection in this mode proves that no host or server-reflexive candidate was used. Do not leave disposable production employee fixtures behind after the run.

Physical acceptance remains separate: repeat on the 300 device, representative station devices, iPad/Safari, actual USB camera/microphone/speaker hardware, and a restricted-network/TURN-only path. Confirm speaker routing, hot-plug recovery, intelligibility, echo suppression, camera framing, touch targets, takeover messaging, and a sustained 30-minute lineup.

## Load smoke

Install a pinned LiveKit CLI release on a test VM with adequate bandwidth, add the self-hosted project, then run:

```sh
lk load-test --room mbfd-load-smoke --duration 2m --video-publishers 3 --audio-publishers 3 --subscribers 3 --simulate-speakers
```

Use a dedicated non-production room/server. Record CLI/server versions, CPU, memory, outbound bandwidth, latency, and dropped packets. A passing synthetic load smoke does not replace device or TURN acceptance.

## Rollback

1. Set `VIDEO_CONFERENCING_ENABLED=false` and clear Laravel config cache. This immediately stops new rooms/tokens while keeping employee login intact.
2. Revert the application release and run only the documented down migrations if database rollback is required; the additive tables do not alter employee/auth data.
3. Roll LiveKit back by restoring the prior pinned image/config and running `docker compose up -d`; retain the previous config and image digest before every upgrade.
4. If credentials may have leaked, rotate the LiveKit key/secret in both LiveKit and Laravel before re-enabling. Existing short-lived tokens expire within ten minutes by default.

Redis contains ephemeral room coordination rather than recordings. Preserve Laravel database backups under the normal MBFD backup policy; this feature creates no media archive to back up.

Conference leave disconnects LiveKit and stops browser tracks but does not log the employee out. The existing employee-session idle/absolute policy remains authoritative; token TTL is not an employee-session timeout.
