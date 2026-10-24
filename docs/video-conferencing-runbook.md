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

## 300 Command access

The 300 command PIN was removed. Starting or ending Morning Lineup, direct station calls, the command status feed, 300 moderation controls and any 300/Command LiveKit token all ask `App\Services\Security\Command300Access` on every request (no session marker, no cache). Access is granted to: administrators (`super_admin` or the `admin.access` permission), members whose personnel rank is Captain or Division Chief, the member with a verified current 300 bid assignment, and any member granted the direct `command.300.access` permission. A demotion or revoked grant takes effect on the next request; a member who is already connected loses the moderation controls (mute, give floor) immediately, and the lineup time limit (15 minutes by default, see "Member waiting room") is unchanged.

A client-supplied `join_as=300` never escalates: unqualified members receive 403. Ordinary member (`self`) participation and station endpoints are unchanged.

Conference participation is keyed to a personnel profile, so an authorized administrator whose account is not linked to an employee record sees: "300 video conferencing needs a linked MBFD personnel profile. Ask a Hub administrator to link your account to your employee record." Link the account in the Admin Panel instead of creating placeholder employees.

`POST /employee/video-conferencing/api/lineup/command/authorize` remains for one release so cached browsers keep working. It takes no body and only reports the live decision (`{"authorized": true}` or 403). Remove it in the following release.

`VIDEO_CONFERENCING_COMMAND_PIN_HASH` is no longer read by conferencing. It is retained only for the legacy Command Board "use the camera PIN" copy action in the Admin Panel.

## Install

1. Generate a unique API key, at least 32 random API-secret characters. There is no 300 command PIN: 300 Command video controls are authorized by the central `Command300Access` policy (see "300 Command access" below).
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

6. Configure Laravel with browser-facing `LIVEKIT_URL=wss://...`, a server-side `LIVEKIT_API_URL`, the matching key/secret, and an optional `VIDEO_CONFERENCING_LINEUP_TIME=HH:MM`. A Laravel container co-located with host-networked LiveKit should use the host gateway (for example, `http://172.20.5.1:7880`) rather than hairpinning through its Tailscale/public DNS name. Permit TCP 7880 only from the exact application Docker subnet; keep it closed to public and Tailnet clients. A remote application host should use the trusted HTTPS API endpoint. Keep `VIDEO_CONFERENCING_ENABLED=false`, then run migrations and clear cached config.
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
$env:VIDEO_CONFERENCING_E2E_EMPLOYEE_ID='VC-E2E'
$env:VIDEO_CONFERENCING_E2E_PASSWORD='<local-unique-password>'
# The seeded VC-E2E member must hold 300 Command access (grant the command.300.access permission or set a Captain rank).
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

For a production TURN/TLS acceptance run, use a production fixture member who has 300 Command access (for example a Captain) and set `VIDEO_CONFERENCING_E2E_FORCE_RELAY=true` before starting Playwright. The test then adds the non-sensitive `force_relay=1` diagnostic query, connects all three LiveKit clients with WebRTC `iceTransportPolicy: relay`, verifies that every peer connection retained that policy, and fails unless the three endpoints connect and moderation still works. Successful connection in this mode proves that no host or server-reflexive candidate was used. Do not leave disposable production employee fixtures behind after the run.

Physical acceptance remains separate: repeat on the 300 device, representative station devices, iPad/Safari, actual USB camera/microphone/speaker hardware, and a restricted-network/TURN-only path. Confirm speaker routing, hot-plug recovery, intelligibility, echo suppression, camera framing, touch targets, takeover messaging, and a sustained 30-minute lineup.

## Gallery layout, capacity and reconnect behavior

- **Tile order.** In a Morning Lineup the gallery always shows `300 Command`, `Station 1`, `2`, `3`, `4`, `6` in that order. An endpoint that has not connected (or has left) keeps its position as a dashed "Waiting to join" placeholder, so tiles never reshuffle when a station joins. Direct calls show only 300 and the called station. Individual members (`mbfd:member:<employee id>`) follow the core six, ordered by join time.
- **Tile shape.** Every tile media box is an exact 16:9 box with `object-fit: contain`. Tile size is computed in `gallery-layout.ts` from the gallery container's width and height (largest tiles that fit); minimum 200 px wide on desktop, 150 px on phones (1 or 2 columns). If tiles would fall below the minimum the gallery scrolls vertically instead of shrinking. Presentation (screen share or pin) shows a large 16:9 focus tile plus a scrolling filmstrip: a vertical rail on wide screens, a horizontal strip on narrow or landscape-short screens.
- **Capacity.** `LIVEKIT_MAX_PARTICIPANTS` is 12. A full lineup is 6 (300 plus five stations), leaving 6 slots for individual members. This is sufficient for the planned physical test; raise it only if more than six members will join one lineup, remembering that every extra participant increases participant-minute and downstream usage on LiveKit Cloud.
- **Member seats (enforced by the Hub).** `VIDEO_CONFERENCING_MEMBER_SEATS` (default 6, `config('video-conferencing.member_seats')`) is the number of individual member places, further capped at `LIVEKIT_MAX_PARTICIPANTS` minus the 6 reserved endpoint places. Only `self` participations count; 300 and the stations never use or wait for a member seat. Before it creates a member participation or token, the token endpoint counts held seats in the database and, when none is free, answers HTTP 409 `room_full` ("Morning Lineup is full right now. You can watch for a seat to open; try again in a moment.") without creating a row. A seat is held from token issue until the member leaves (or the lineup ends). A join LiveKit never confirmed (no `joined_at`) stops holding its seat once its token could no longer have been used (token lifetime plus 90 s), so a failed connect whose leave call was lost cannot block the room. The member's own previous participation is never counted against them. The count runs inside the same transaction as the insert, under a row lock (`SELECT ... FOR UPDATE`) on the session row, so two members racing for the last seat are serialized: the second sees the first's row and gets `room_full`. (SQLite, used only by tests, serializes writers itself.) LiveKit's own `max_participants` stays as the backstop.
- **Failed connects release their seat.** Every failed browser connect after a token was issued (and the early exits before connecting) calls the leave endpoint for that participation, so a network blip never leaves an orphaned seat or a false "already connected from another device" prompt.
- **Reconnect.** The LiveKit SDK handles short network blips itself. If a connection is lost completely: stations re-acquire camera and microphone and rejoin automatically through the existing status poll; 300 and members retry up to three times (after 2 s, 6 s and 12 s) and only while the same lineup is still active, otherwise they return to the device-check screen, where members also see the lineup's live status (see "Member waiting room"). An endpoint closed because another device took it over never rejoins automatically. A lineup is never started or restarted by an automatic rejoin.
- **Lineup-ended detection.** `ROOM_DELETED` still sends endpoints back immediately. A status poll reporting an inactive session must be repeated (at least two polls, at least 1.5 s apart) before a station, 300 or member is navigated away, so one transient server error cannot drop an endpoint.
- **Same member in two places.** A second join with the same `mbfd:member:<id>` identity returns HTTP 409 `endpoint_in_use`. The page offers to join here and disconnect the other tab or device; the older connection receives a clear "connected from another device" message.

## Member waiting room (Employee Portal)

A signed-in member with a linked personnel profile can open `/employee/video-conferencing` before 300 has started Morning Lineup. The page shows the camera and microphone check as before and, next to it, the real state of today's lineup. It never creates a lineup, a participation or a token; only 300 can start one, through the existing 300-gated start endpoint.

- **Status endpoint.** `GET /employee/video-conferencing/api/lineup/status` (route `employee.video-conferencing.api.lineup.status`, bootstrap key `endpoints.lineup_status`). It sits in the existing employee API group, so it needs employee sign-in with a linked profile and the feature flag. It has its own `conference-lineup-status` rate limiter (registered in `App\Providers\ConferenceRateLimitServiceProvider`): 30 reads per minute per employee and 1200 per minute per network address, under keys that no other conferencing route uses, so waiting members can never spend the stations' heartbeat/status budget or the 300's start/end/moderation budget (those stay on `conference-controls`). It does not need 300 access, reads only the database (no LiveKit call, so it works and costs the same when the provider is down), and sends `Cache-Control: no-store`. A 300 account and a firefighter receive the same payload.
- **Payload.** `state` is `not_started`, `starting` (row exists, room still being created, at most 2 minutes), `active`, `ended` or `unavailable` (never finished provisioning, a different LiveKit profile is running, or the configured profile is invalid). Also `session_id` (only while `active`; a `starting` lineup cannot be joined yet, so it is not offered), `started_at`, `ends_at` (active only; `started_at` plus the clamped `VIDEO_CONFERENCING_LINEUP_MAX_MINUTES` of 5 to 30), `ended_at` (ended only; the time limit when the row has not been closed yet), `scheduled_for` (the configured lineup time in America/New_York, if any), `full` (an active lineup with no member seat free for this member; false otherwise and for a member who already holds a seat) and `server_time`. It never returns room names, participant identities or lists, stations, usage, provider health or tokens.
- **Time limit and the day boundary.** Every lineup ends at `started_at` plus the clamped limit, whatever the date. The status reads `ended` as soon as that moment passes, even if the every-minute `video-conferencing:expire-lineup` command has not closed the row yet, and the token path answers HTTP 410 ("This Morning Lineup has ended.") for a lineup past its limit. The expiry command selects every unfinished lineup regardless of the day it was created on (and clears one that never finished provisioning without a provider call), so a lineup started at 23:50 Eastern is closed at 00:05. "Today" is the America/New_York service date, the same one the 300 start uses. A lineup belongs to the day it started on and stays live until its own limit: at 00:03 a lineup begun at 23:50 still reads `active`, can be joined, and 300 reconnects into it instead of starting a second one. A lineup from the previous service day that is over (ended or past its limit) reads `not_started`, never `ended`; one from today that is over reads `ended`. If today's lineup ended and 300 starts it again, the member sees `active` with a new `session_id`. If 300 starts a lineup while today's row is past its limit but not yet closed, that row is closed first and a fresh lineup is started. The POST `/sessions` response carries the joined lineup's own `ends_at`, which is what the in-call countdown uses.
- **What the member sees.** "Waiting for Morning Lineup to start" (with the scheduled time if configured), "Morning Lineup is starting", "Morning Lineup is live" with a **Join Morning Lineup** button, "Morning Lineup has ended" with the end time, and plain-language notices if the Hub cannot be reached or the sign-in expired. A chime plays once when a lineup goes live while the member is waiting with devices ready (never for a lineup that was already running when the page opened). Waiting uses no call minutes.
- **Manual join only.** Nothing joins automatically and no token is requested or prefetched while waiting. The token request happens only when the member presses Join, after the lineup is live; the token path, the forced `self` role, `mbfd:member:<id>` identity and the 10-minute token lifetime are unchanged. A Join that finds the lineup not started, ended or still being prepared shows a notice and refreshes the waiting state instead of a red error.
- **Polling.** The page reads the endpoint every 10 to 15 s (twice the bootstrap `status_poll_ms`, clamped), every 5 s while `starting`, every 60 s while the tab is hidden, backs off up to 60 s when the Hub is unreachable, and reads immediately when the tab becomes visible or the network returns. Every delay carries up to 25% random jitter (never shortening it) so members who open the page together do not hit the Hub in lockstep. On HTTP 429 the page waits at least the `Retry-After` the Hub sent (30 s without the header, capped at 2 minutes). A refresh requested while a read is in flight (for example after a Join learned the lineup changed) is queued and runs as soon as that read finishes. A 200 response that is not a lineup status (a captive portal or proxy page) counts as a failed read, not as "no lineup". It also listens for the existing public Reverb `lineup.changed` nudge (state only, no new channel authorization); polling remains authoritative if Reverb is down.
- **Idle camera.** The preview camera and microphone are never released while a Join is in flight, while the lineup is `starting` or `active`, or from 15 minutes before `scheduled_for`; the idle clock restarts whenever one of those holds. They are released only after 10 minutes of not being live and not near the start, and at once when a lineup this page saw go live has ended (a page opened after the lineup was already over is not affected). A **Turn camera back on** button then appears. Media is never kept live without the member's action. A Join in flight (and a 300 start or direct call) also marks the page busy (`joining`, next to `active-call`) so an app-update reload cannot interrupt it and orphan a participation.
- **Failure copy.** HTTP 403 (password change or onboarding pending) reads "Finish setting up your account first"; 429 "Too many checks. Waiting a moment."; a repeated 5xx "The Hub had a problem. Try again."; 503 with the feature off "Video conferencing is turned off right now"; only a network failure or an unreadable 200 reads "Cannot reach the Hub".
- **Join button.** It uses `aria-disabled` rather than `disabled`, so keyboard and screen-reader users can reach it and hear why it is off (it is described by the status text and, when present, the hint); `aria-busy` is set while joining. The status headline and detail are the single polite live region.
- **If the connection drops while in the lineup.** The page keeps the existing three automatic rejoin attempts (after 2 s, 6 s and 12 s) on the same lineup only. If they fail, the member lands in the waiting room with the lineup's live status: **Rejoin Morning Lineup** if it is still running, or "Morning Lineup has ended". A connection closed because the member joined from another device is never rejoined automatically.
- **Bundle budget.** The waiting room (`MemberWaitingRoom.tsx`, `useMemberLineupStatus.ts`, `waiting-room.ts`, `waiting-room.css`) is a lazily loaded chunk so the `mount` budget in `scripts/ci/check-video-conferencing-bundle.mjs` is unaffected. The two lazy chunks and the waiting-room stylesheet have their own budgets in the same script (8 KB / 3 KB gzip, 10 KB / 3.5 KB gzip and 1.5 KB). If the chunk cannot load, a plain **Join Morning Lineup** button is shown instead.

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
