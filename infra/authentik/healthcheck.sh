#!/usr/bin/env bash
set -euo pipefail

root="${AUTHENTIK_ROOT:-/opt/mbfd/authentik}"
environment="${AUTHENTIK_ENV_FILE:-/etc/mbfd/authentik.env}"
compose="$root/compose.yaml"
bind_port="${AUTHENTIK_BIND_PORT:-9000}"
for service in postgresql server worker; do
  state="$(docker compose --env-file "$environment" -f "$compose" ps --format json "$service" | jq -r '.State')"
  health="$(docker compose --env-file "$environment" -f "$compose" ps --format json "$service" | jq -r '.Health // ""')"
  [[ "$state" == running && "$health" == healthy ]] || {
    printf 'AUTHENTIK_HEALTH_FAILED=%s:%s:%s\n' "$service" "$state" "$health" >&2
    exit 1
  }
done
curl --fail --silent --show-error --max-time 5 -o /dev/null "http://127.0.0.1:${bind_port}/-/health/live/"
printf 'AUTHENTIK_HEALTH_OK\n'
