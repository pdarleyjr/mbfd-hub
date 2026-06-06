# Spam Bot, Abuse, and Cost-Control Review

## High-Value Abuse Surfaces

- Filament login/password reset.
- Public station/apparatus/checkout submissions.
- Support chat and AI endpoints.
- Worker PIN gates and webhooks.
- Upload endpoints and TUS chunking.
- Media Control pairing/display-control/remote-key/screenshot/whiteboard events.
- Player debug log ingestion.
- Queue jobs and expensive import/export/PDF generation.

## Fixed

- CSP report log endpoint throttled and bounded.
- Admin API/audit routes throttled.
- Media Control TUS upload content type narrowed.
- Media Control legacy provisioning disabled/write-gated.
- CI now blocks high/critical dependency/security findings instead of silently passing.

## Required Controls

1. Add route-specific lower throttles to public write APIs and expensive operations.
2. Consider Cloudflare Turnstile on public forms that do not need kiosk/no-touch operation.
3. Add queue depth/size limits and alerts for large uploads, repeated imports, and AI calls.
4. Add display-control command rate limits and audit logs.
5. Redact sensitive tokens/URLs in debug sinks.
6. Alert on failed-login spikes, password reset spikes, WAF spikes, and repeated public write submissions.
