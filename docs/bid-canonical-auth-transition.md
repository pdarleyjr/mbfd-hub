# Bid canonical authentication transition

Bid federates human authentication to the canonical Hub `web` session. Bid does not collect,
forward, or verify the Hub password on the canonical path.

## Contract

1. Bid creates a 32-byte random `state`, stores it in a secure HTTP-only `SameSite=Lax` cookie
   for five minutes, and redirects to:

   `GET /auth/bid/authorize?client_id=bid&redirect_uri={registered callback}&state={state}`

2. Hub requires the canonical `web` login and a current D01 authentication-session record, then
   resolves D02 `AuthenticatedMemberContext`. An unlinked User is denied; no name or email lookup
   is attempted.
3. Hub redirects only to one of these exact callbacks:

   - `https://bid.mbfdhub.com/api/auth/callback`
   - `https://staging.bid.mbfdhub.com/api/auth/callback`

4. Success returns `code` and the unchanged `state`. The opaque 256-bit code is stored only as a
   SHA-256 cache key for 60 seconds and is bound server-side to the Hub issuer, `bid` audience,
   exact callback, User ID, Employee profile ID, and User security version.
5. The Bid Worker exchanges the code server-to-server at `POST /api/v2/bid/auth/exchange`, using
   the existing Bid reader bearer token. Redemption is serialized by a cache lock and deletes the
   code before a successful response, so concurrent or repeated redemption fails.
6. Hub rechecks active User status, security version, exact Employee linkage, and current canonical
   admin entitlement. It returns identity claims only. The Worker verifies issuer/audience and then
   creates an independent eight-hour Bid JWT.

The Hub code is not a Hub session or bearer token. Bid logout does not destroy the Hub session.

## Compatibility and retirement

`POST /api/v2/verify-credentials` and the non-null `employees.password` hash remain available only
for deployment compatibility. D03 removed normal Hub UI and command paths that issue, reset, or manage
that Employee password; it is not a human Hub credential. The source rollout order is Hub
authorize/exchange first, then Bid web/Worker migration. After both are deployed, prove zero legacy calls
before removing the endpoint and compatibility storage in a separate authorized phase. No production or
Cloudflare change is part of this source development.
