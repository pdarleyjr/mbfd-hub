# C03 — Snipe and Pilot Runtime Prerequisites

## Scope and safety boundary

This change adds a read-only Snipe identity preview and a public, minimal
readiness endpoint. It does not create, update, disable, delete, or retry any
Snipe identity, Snipe asset, queue job, production service, or Cloudflare
resource.

`snipeit:sync-users` is blocked because its email-based create/update behavior
could detach or duplicate a Snipe numeric identity. The replacement command is:

```text
php artisan snipeit:reconcile-identities --preview --format=table
```

The command only issues authenticated `GET /users` requests and reads the local
User and Employee tables in a read-only database transaction. It never offers
an apply mode. Exact `employee_num` is the only automatic identification rule;
name and email are review context only. A future owner-approved external
identity mapping must persist the Snipe numeric ID, captured employee number,
approval, and capture time with a unique `(system, external_numeric_id)`
constraint. C03 does not add that schema because C01 owns canonical identity
schema and the mapping architecture must be approved first.

## Health contract

`/up` remains Laravel liveness. `/health` is a readiness endpoint that returns
only `{"status":"ok"}` with HTTP 200 when the application can reach PostgreSQL
and the configured cache store (Redis in production). It returns only
`{"status":"degraded"}` with HTTP 503 otherwise. It intentionally does not
expose hostnames, connection settings, exception messages, queue payloads, or
detailed Spatie Health results; those remain for authenticated operations.

## Queue evidence and disposition

The 2026-08-31 read-only production query grouped six historical failed jobs:
five `GenerateOperationalFormPdf` jobs failed because the controlled PDF
generator subprocess exited non-zero, and one `GenerateCommandCenterSummaryJob`
timed out. The retained failed-job record does not contain safe enough renderer
stderr to establish a narrower root cause. No production job was retried,
deleted, or otherwise changed.

The PDF job already has a 45-second timeout, two attempts, a unique generation
key, user-safe failure state, and structured generator failure metadata. Its
remaining release gate is a disposable runtime replay using the exact image,
Node dependency tree, writable private storage, and representative controlled
form data. The Command Center job needs an independently measured model
timeout/cold-start policy; it is not changed in C03.

## Deferred production HTTP runtime ticket

**Owner:** release/runtime owner. **Files:** `docker/production/Dockerfile`,
`docker/production/supervisord.conf`, immutable-image proof and activation
workflows. **Finding:** production Supervisor runs `artisan serve`, the PHP
built-in server. **Recommendation:** choose and prove a project-supported
nginx plus PHP-FPM (or another owner-approved HTTP server) in a new immutable
candidate; do not change it opportunistically in C03.

The ticket must preserve the non-root `sail` runtime identity and current
Supervisor ownership, retain `/up` liveness and `/health` readiness semantics,
prove queue/scheduler/Reverb behavior, compare load and graceful shutdown, and
exercise image rollback before activation. Production deployment is out of
scope for C03.
