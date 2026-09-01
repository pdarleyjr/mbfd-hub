#!/usr/bin/env bash
set -euo pipefail

readonly root="$(git rev-parse --show-toplevel)"
readonly release_sha="$(git -C "$root" rev-parse HEAD)"
readonly compose_file="$root/docker/staging/compose.federation.yaml"
readonly runtime_env="${HUB_STAGING_RUNTIME_ENV:-/opt/mbfd/staging/hub-federation/runtime.env}"

test -f "$runtime_env"
test "$(git -C "$root" status --porcelain)" = ''
grep -qx 'APP_ENV=staging' "$runtime_env"
grep -qx 'APP_URL=https://staging.mbfdhub.com' "$runtime_env"
grep -qx 'SESSION_COOKIE=mbfd_hub_staging_session' "$runtime_env"
grep -qx 'CACHE_PREFIX=mbfd_hub_staging' "$runtime_env"
grep -qx 'QUEUE_CONNECTION=sync' "$runtime_env"
grep -qx 'PORTAL_WRITEBACK_ENABLED=false' "$runtime_env"

export HUB_STAGING_RELEASE_SHA="$release_sha"
export HUB_STAGING_APP_ENV="$runtime_env"

docker compose --project-name mbfd-hub-staging --env-file "$runtime_env" -f "$compose_file" config --quiet
docker compose --project-name mbfd-hub-staging --env-file "$runtime_env" -f "$compose_file" build --pull hub
docker compose --project-name mbfd-hub-staging --env-file "$runtime_env" -f "$compose_file" up -d postgres redis
docker compose --project-name mbfd-hub-staging --env-file "$runtime_env" -f "$compose_file" run --rm --entrypoint /usr/bin/php hub artisan migrate --force --no-interaction
docker compose --project-name mbfd-hub-staging --env-file "$runtime_env" -f "$compose_file" up -d hub
docker compose --project-name mbfd-hub-staging --env-file "$runtime_env" -f "$compose_file" exec -T hub php artisan optimize:clear
docker compose --project-name mbfd-hub-staging --env-file "$runtime_env" -f "$compose_file" exec -T hub php artisan config:cache
docker compose --project-name mbfd-hub-staging --env-file "$runtime_env" -f "$compose_file" exec -T hub php artisan route:cache
docker compose --project-name mbfd-hub-staging --env-file "$runtime_env" -f "$compose_file" exec -T hub php artisan bid:seed-federation-staging-identities
docker compose --project-name mbfd-hub-staging --env-file "$runtime_env" -f "$compose_file" up -d tunnel

docker image inspect --format '{{index .Config.Labels "org.opencontainers.image.revision"}}' "mbfd-hub-staging:$release_sha" | grep -qx "$release_sha"
docker compose --project-name mbfd-hub-staging --env-file "$runtime_env" -f "$compose_file" exec -T hub curl -fsS http://localhost:8081/up >/dev/null
printf 'hub_staging_release_sha=%s\n' "$release_sha"
