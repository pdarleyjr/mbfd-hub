# Web Application Security Review

## Fixed

- Workgroup report/export/file/AI routes now require `workgroup.access` in addition to authentication.
- Admin audit routes now have route-level admin role middleware and throttling.
- Admin API group has a route-level throttle.
- CSP report endpoint has throttling and bounded logging.
- Production session defaults now enable encrypted sessions and secure cookies unless explicitly overridden.
- Admin PWA no longer caches authenticated admin HTML/JSON.

## Remaining High-Risk App Items

1. Public apparatus inspection submissions can change apparatus status. Convert to signed/PIN/authenticated pending-review flow.
2. Public station APIs expose internal operational details. Split public/internal resources and add response-schema tests.
3. Public station inventory PDFs and shared uploads use public storage. Move to private disk/R2 and serve through authorized/signed URLs.
4. Some public write endpoints need stronger bot controls, lower per-route throttles, and audit logging.
5. Reverb/channel authorization should be explicitly defined before sensitive private channels are introduced.

## Positive Controls

- Filament panels use auth/session/CSRF middleware.
- Login pages have rate limiting.
- Bid credential bridge fails closed when shared token is missing and uses timing-safe comparison.
- Base64 image helper validates magic bytes and size.
- Security headers and CSP are present.

## Recommended Tests

- Public API response-schema tests for station/apparatus endpoints.
- Negative authorization tests for public write routes.
- Signed URL/PIN tests for inventory and apparatus workflows.
- Storage access tests proving sensitive files are not directly reachable under `/storage`.
- Browser test proving admin content is not available offline after logout.
