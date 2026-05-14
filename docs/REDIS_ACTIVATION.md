# Redis Production Activation Procedure

**Last updated:** 2026-05-14
**Audience:** Production operator with SSH access to `mbfd-hub-laravel`

This document is the step-by-step runbook for activating Redis as the
cache / queue / session / broadcast backend on production. The
infrastructure has been in place since commit `a01b1eba` (the Redis
service is declared in `compose.prod.yaml` and the env vars are documented
in `.env.example`), but the production `.env` has not yet flipped the
driver values from `database`/`log` to `redis`/`reverb`.

This flip cannot be performed automatically by CI — it requires writing
`/opt/mbfd/mbfd-hub/.env` on the production VPS. The deploy workflow does
`git reset --hard origin/main` which intentionally leaves `.env`
untouched. The procedure below is a one-time manual cutover.

## Pre-flight (~2 min)

```bash
# 1. SSH to production
ssh deploy-target

# 2. Confirm Redis container is running and healthy
docker compose -f /opt/mbfd/mbfd-hub/compose.prod.yaml ps redis
# Expected: STATUS = Up X minutes (healthy)

# 3. Run the readiness probe — verifies connectivity + round-trip
docker exec mbfd-hub-laravel php artisan mbfd:activate-redis --dry-run
```

If the probe reports `✓ All four drivers already point at redis/reverb`,
this procedure has already been completed — stop here.

If the probe reports `Redis is reachable. To complete activation, add
to the production .env:` followed by a block of env vars, continue.

## Cutover (~5 min, ~30s of cache-miss latency)

```bash
# 1. Back up the existing .env (rollback insurance)
cp /opt/mbfd/mbfd-hub/.env /opt/mbfd/mbfd-hub/.env.pre-redis-$(date +%Y%m%d_%H%M%S)

# 2. Edit /opt/mbfd/mbfd-hub/.env and SET (or update) these lines.
#    The Redis service uses the `redis` hostname inside the docker network.
#    REDIS_PASSWORD must match what compose.prod.yaml expects.
cat >> /opt/mbfd/mbfd-hub/.env <<'EOF'

# --- Redis cutover (activated 2026-XX-XX) ----------------------
CACHE_STORE=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis
BROADCAST_DRIVER=reverb
BROADCAST_CONNECTION=reverb
REDIS_HOST=redis
REDIS_PORT=6379
REDIS_PASSWORD=<paste-strong-secret-here>
REDIS_DB=0
REDIS_CACHE_DB=1
# ---------------------------------------------------------------
EOF

# 3. Inspect — make sure the new block didn't duplicate keys already
#    present higher up in the file. Laravel reads the LAST occurrence,
#    so duplicates are non-fatal but messy.
docker exec mbfd-hub-laravel bash -c 'grep -E "^(CACHE_STORE|QUEUE_CONNECTION|SESSION_DRIVER|BROADCAST_DRIVER|BROADCAST_CONNECTION|REDIS_)" .env | tail -20'

# 4. Rebuild config cache so the new env takes effect
docker exec mbfd-hub-laravel php artisan config:cache

# 5. Drain any in-flight database queue jobs BEFORE the worker switches
#    over. Jobs that were already on the DB queue stay there — but new
#    dispatches will route to Redis.
docker exec mbfd-hub-laravel php artisan queue:restart

# 6. Restart the Reverb daemon so it picks up Redis pub/sub
docker exec mbfd-hub-laravel bash -c 'pkill -f reverb:start || true; sleep 1; nohup php /var/www/html/artisan reverb:start --host=0.0.0.0 --port=8080 --no-interaction > /tmp/reverb.log 2>&1 &'

# 7. Verify the flip succeeded — re-run the probe
docker exec mbfd-hub-laravel php artisan mbfd:activate-redis --dry-run
# Expected: ✓ All four drivers already point at redis/reverb
```

## Smoke verification (~30s)

```bash
# /admin/login + dashboard
curl -fsSL -o /dev/null -w "/admin/login = %{http_code}\n" https://www.mbfdhub.com/admin/login

# WebSocket handshake (Reverb on /app/ path per nginx config)
curl -fsSL -o /dev/null -w "/app/ = %{http_code}\n" -H 'Upgrade: websocket' -H 'Connection: Upgrade' https://www.mbfdhub.com/app/

# Lookup API (proves cache.store.redis is wired — first hit warms cache)
curl -fsSL -b cookies.txt -o /dev/null -w "/api/admin/lookups/stations = %{http_code}\n" https://www.mbfdhub.com/api/admin/lookups/stations
```

All three should return 200.

## Rollback (~1 min)

If anything misbehaves within the first hour:

```bash
# 1. Restore the pre-flip .env
cp /opt/mbfd/mbfd-hub/.env.pre-redis-* /opt/mbfd/mbfd-hub/.env

# 2. Clear the cached config
docker exec mbfd-hub-laravel php artisan config:cache

# 3. Restart queue worker so it stops trying to read from Redis
docker exec mbfd-hub-laravel php artisan queue:restart
```

The database queue table never went away (it's the previous backend),
so in-flight Redis-queued jobs will be lost — that's the cost of rolling
back. Any genuinely important job should have been processed before
rolling back (or replayed from logs).

## Observability

After cutover, watch:
- **`docker stats mbfd-hub-redis`** — memory should stabilize well under
  the 512MB cap configured in `compose.prod.yaml`
- **Filament Pulse** at `/admin/pulse` — cache + queue panels should show
  Redis activity
- **Laravel logs** — `tail -f storage/logs/laravel-*.log` for the first
  hour

## What this unlocks

Once Redis is active, three modernization features start delivering value:

1. **`Cache::remember()` on the lookup endpoints** — the
   `LookupController` caches station/apparatus/personnel lists for 5 min.
   With `CACHE_STORE=database`, this writes to a SQL table on every miss;
   with Redis, it's an in-memory hit. The Dexie prefetch on admin PWA
   load goes from ~200ms to <50ms in steady state.
2. **Reverb scaling** — WebSocket events fan out via Redis pub/sub,
   removing the single-process bottleneck for the chat / notifications
   broadcast.
3. **Session-based admin UX** — `persistFiltersInSession()` /
   `persistSearchInSession()` / `persistSortInSession()` (applied to 15
   resources via `EnterpriseTable` trait) write to session on every
   filter change. Redis sessions make this near-free; database sessions
   add a write per interaction.
