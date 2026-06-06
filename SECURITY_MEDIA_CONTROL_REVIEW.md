# Media Control / Screen Tinker Security Review

## Fixed in `D:/GitHub_Repos/media-control`

- TUS upload finalizer now reuses the multipart upload MIME allowlist and rejects SVG/HTML/JavaScript/unknown types.
- Multipart upload allowlist no longer accepts arbitrary `image/*` or `video/*` wildcards.
- Public file, thumbnail, and upload routes add `X-Content-Type-Options: nosniff`; content files set stored MIME explicitly.
- Legacy `/api/provision` endpoint now returns 410 and cannot pair/clear devices.
- `/api/provision/pair` now requires write-tier workspace access and returns only `id`, `name`, `workspace_id`, `status`.
- Added `server/test/upload-policy.test.js` regression tests.

## Remaining High/Medium Risks

- Public deck/player routes expose content by UUID. Add optional signed short-lived playback tokens for sensitive content.
- Public widget render supports raw HTML. Add sandbox CSP on render routes or isolate on a separate origin.
- Remote URL broadcasting needs a centralized URL policy that blocks private/link-local/loopback/Tailscale/metadata targets after DNS resolution.
- Device-token legacy fallback should be removed after a migration assigns tokens to all devices.
- WebSocket display-control events need per-socket rate limits and queue-depth controls.
- Public player-debug logs should redact tokens/query strings and have TTL pruning.
- Local password policy still allows short passwords; raise to passphrase-grade minimums.

## Tests Run

- `node --check server/server.js`
- `node --check server/lib/finalize-upload.js`
- `node --check server/middleware/upload.js`
- `node --test server/test/upload-policy.test.js`

## Deployment Notes

- Do not deploy during active class/display use without the standard rollback image and DB backup.
- Verify `/api/version`, container health, pairing flow, upload flow, and player playback after deploy.
