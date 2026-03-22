# Security Hardening Guide

**Phase 5 – Production Hardening**  
**Date:** 2026-02-09

---

## 1. Token Encryption

All external-service tokens are encrypted at rest using Laravel's `Crypt::encryptString()` (AES-256-CBC via `APP_KEY`).

- **Model:** `ExternalSource` stores `token_encrypted` column
- **Access:** Virtual `token` attribute decrypts on read
- **Serialization:** `token_encrypted` is in `$hidden` — never included in JSON/array output
- **Logging:** `BaserowClient` logs errors with source name/ID only; tokens are never logged

### Sensitive Data Filtering

The logging config uses Laravel's built-in `replace_placeholders` to prevent accidental interpolation. Never pass raw tokens to `Log::*` calls.

---

## 2. Rate Limiting

| Endpoint | Limit | Key |
|----------|-------|-----|
| `/api/webhooks/baserow` | 30 req/min | IP (default throttle) |
| `/api/public/*` | 60 req/min | IP (default throttle) |
| `/api/admin/*` | Sanctum-authed | Token-based |

The Baserow webhook route is defined in [`routes/api.php`](../routes/api.php:74) with `throttle:30,1` middleware.

---

## 3. Network Security

### Baserow Container Isolation

Baserow binds to `127.0.0.1:8082` only — not accessible from the public internet.

```yaml
# docker-compose.yml
ports:
  - "127.0.0.1:8082:80"
```

### Cloudflare Access (Recommended)

Protect `baserow.support.darleyplex.com` with Cloudflare Access:
1. Create an Access Application in the Cloudflare Zero Trust dashboard
2. Set policy to allow only organization email addresses
3. Optionally protect `/training/*` routes the same way

---

## 4. Security Headers

Applied via [`AddBuildHeaders`](../app/Http/Middleware/AddBuildHeaders.php) middleware:

| Header | Value |
|--------|-------|
| `X-Content-Type-Options` | `nosniff` |
| `X-Frame-Options` | `SAMEORIGIN` |
| `Strict-Transport-Security` | `max-age=31536000; includeSubDomains` |
| `Referrer-Policy` | `strict-origin-when-cross-origin` |
| `Content-Security-Policy` | See CSP rules below |

### CSP Policy

- `frame-src 'self' https://baserow.support.darleyplex.com` — allows Baserow iframe embedding
- `connect-src 'self' https://*.sentry.io` — allows Sentry telemetry
- `script-src 'self' 'unsafe-inline' 'unsafe-eval'` — required for Livewire/Filament

---

## 5. Livewire 500 Diagnostics

### Middleware

[`LogLivewireErrors`](../app/Http/Middleware/LogLivewireErrors.php) catches Livewire update requests that return 500+ status codes and logs:

- User ID, URL, component name, fingerprint
- IP address, user agent, timestamp
- Adds Sentry breadcrumb for correlation

### Sentry Configuration

- `send_default_pii: true` — captures user context on errors
- `traces_sample_rate: 0.2` — samples 20% of requests for performance tracing
- Livewire breadcrumbs and tracing enabled by default
- Exception handler enriches Livewire errors with component metadata

### Troubleshooting Livewire 500s

1. Check Sentry for the error — look for `livewire_component` context
2. Check `storage/logs/laravel.log` for `Livewire 500 error detected` entries
3. Common causes:
   - Session expiry (CSRF token mismatch) — user needs to refresh
   - Component state serialization failure — check model `$hidden`/`$casts`
   - Database connection timeout — check health endpoint

---

## 6. Health Check

**Endpoint:** `GET /health`

Returns JSON with status of:
- **Database** — connection + latency
- **Cache** — read/write test
- **Baserow** — HTTP health check to `127.0.0.1:8082`

Response codes: `200` (healthy) / `503` (degraded)

Use for uptime monitoring (e.g., UptimeRobot, Cloudflare Health Checks).

---

## 7. Incident Response for 500s

1. **Check Sentry** — look for new issues with Livewire context
2. **Check `/health`** — verify services are running
3. **Check logs** — `docker exec <container> tail -100 storage/logs/laravel.log`
4. **If Baserow-related** — verify container: `curl http://127.0.0.1:8082/api/_health/`
5. **If session-related** — check Redis/file session driver status
6. **Escalate** if database is down or data corruption suspected
