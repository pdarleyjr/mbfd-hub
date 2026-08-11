# MBFD Video Conferencing Runbook

## Scope and safety boundary

This feature uses self-hosted LiveKit only for SFU signaling/media. Laravel remains authoritative for employee authentication, room selection, short-lived join tokens, fixed-endpoint takeover, moderation authorization, and audit lifecycle. Reverb does not transport media. Recording/egress, SIP, arbitrary room names, guest links, and remote camera control are intentionally absent.

The application feature flag defaults to `false`. Keep it false until DNS, trusted TLS, firewall paths, webhook delivery, and a real three-device acceptance run are complete.

## Production topology

- `video.<domain>`: DNS-only record to the LiveKit host; Caddy terminates trusted HTTPS/WSS and proxies to `127.0.0.1:7880`.
- `turn.<domain>`: DNS-only record to the same host; LiveKit terminates TURN/TLS on TCP 5349 and TURN/UDP on 3478.
- RTC reaches the host directly on TCP 7881 and UDP 50000-60000.
- Dedicated Redis is bound only to host loopback port 6380. It is not publicly reachable.
- Cloudflare Tunnel/proxy cannot carry the required UDP media paths. Do not orange-cloud the LiveKit or TURN records.

Required inbound firewall rules are TCP 80/443 for certificate issuance and signaling, TCP 5349 for TURN/TLS, UDP 3478 for TURN/UDP, TCP 7881 for RTC fallback, and UDP 50000-60000 for WebRTC. Restrict SSH and metrics port 6789 to the management network/Tailscale; never expose Redis.

## Install

1. Generate a unique API key and at least 32 random secret characters. Store them only in the host secret store and Laravel production environment. Never commit `.env`.
2. Obtain trusted certificates for both signaling and TURN names. Place the TURN certificate as `fullchain.pem` and key as `privkey.pem` under the directory identified by `LIVEKIT_CERT_DIR`.
3. Copy `infra/livekit/.env.example` to an operator-owned secret file outside the repository and set all values.
4. Validate without starting services:

   ```sh
   docker compose --env-file /secure/path/livekit.env -f infra/livekit/compose.yaml config --quiet
   ```

5. Start Redis and the pinned LiveKit server:

   ```sh
   docker compose --env-file /secure/path/livekit.env -f infra/livekit/compose.yaml up -d
   docker compose --env-file /secure/path/livekit.env -f infra/livekit/compose.yaml ps
   docker compose --env-file /secure/path/livekit.env -f infra/livekit/compose.yaml logs --tail=100 livekit
   ```

6. Configure Laravel with `LIVEKIT_URL=wss://...`, `LIVEKIT_API_URL=https://...`, the matching key/secret, and an optional `VIDEO_CONFERENCING_LINEUP_TIME=HH:MM`. Keep `VIDEO_CONFERENCING_ENABLED=false`, then run migrations and clear cached config.
7. Verify signed webhook delivery to `/webhooks/livekit`, then enable the feature and clear cached config again.

Remote unmute must remain disabled in LiveKit. The 300 UI uses signed Laravel moderation calls for server-side mute and the `mbfd.stationMic` client RPC for a station to unmute its own microphone.

## Health and observability

- Alert on LiveKit process/container restarts, Redis health, host CPU, memory, packet loss, and network saturation.
- Scrape Prometheus metrics from `127.0.0.1:6789/metrics` through the private monitoring network only.
- Monitor Laravel for `ConferenceUnavailableException`, HTTP 409 takeover rates, 401 webhook failures, token endpoint throttles, and incomplete participation rows.
- Probe `/admin/video-conferencing/health` with an authenticated administrator. It returns only `disabled`, `healthy`, or `unavailable` and never exposes configuration.
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
$env:VIDEO_CONFERENCING_E2E_EMPLOYEE_ID='VC-E2E'
$env:VIDEO_CONFERENCING_E2E_PASSWORD='<local-unique-password>'
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
