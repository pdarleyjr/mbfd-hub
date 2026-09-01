#!/usr/bin/env bash
set -euo pipefail

readonly root="$(git rev-parse --show-toplevel)"
readonly release_sha="$(git -C "$root" rev-parse HEAD)"
readonly compose_file="$root/docker/staging/compose.federation.yaml"
readonly app_env="${HUB_STAGING_APP_ENV:-/opt/mbfd/staging/hub-federation/app.env}"
readonly compose_env="${HUB_STAGING_COMPOSE_ENV:-/opt/mbfd/staging/hub-federation/compose.env}"

test -f "$app_env"
test -f "$compose_env"
test "$(git -C "$root" status --porcelain)" = ''
grep -qx 'APP_ENV=staging' "$app_env"
grep -qx 'APP_URL=https://staging.mbfdhub.com' "$app_env"
grep -qx 'SESSION_COOKIE=mbfd_hub_staging_session' "$app_env"
grep -qx 'CACHE_PREFIX=mbfd_hub_staging' "$app_env"
grep -qx 'QUEUE_CONNECTION=sync' "$app_env"
grep -qx 'PORTAL_WRITEBACK_ENABLED=false' "$app_env"

export HUB_STAGING_RELEASE_SHA="$release_sha"
export HUB_STAGING_APP_ENV="$app_env"

docker compose --project-name mbfd-hub-staging --env-file "$compose_env" -f "$compose_file" config --quiet
# This host intentionally does not expose docker0. Use host networking only
# while building, so BuildKit does not try to attach a temporary build step to
# the absent default bridge. The deployed runtime still uses hub-staging only.
docker build --network host --pull \
  --build-arg "WWWUSER=${HUB_WWWUSER:-1000}" \
  --build-arg "WWWGROUP=${HUB_WWWGROUP:-1000}" \
  --build-arg "SOURCE_REVISION=$release_sha" \
  --tag "mbfd-hub-staging:$release_sha" \
  --file "$root/docker/production/Dockerfile" \
  "$root"
docker compose --project-name mbfd-hub-staging --env-file "$compose_env" -f "$compose_file" up -d postgres redis
docker compose --project-name mbfd-hub-staging --env-file "$compose_env" -f "$compose_file" run --rm --entrypoint /usr/bin/php hub artisan migrate --force --no-interaction
docker compose --project-name mbfd-hub-staging --env-file "$compose_env" -f "$compose_file" up -d hub
docker compose --project-name mbfd-hub-staging --env-file "$compose_env" -f "$compose_file" exec -T hub php artisan optimize:clear
docker compose --project-name mbfd-hub-staging --env-file "$compose_env" -f "$compose_file" exec -T hub php artisan config:cache
docker compose --project-name mbfd-hub-staging --env-file "$compose_env" -f "$compose_file" exec -T hub php artisan route:cache
docker compose --project-name mbfd-hub-staging --env-file "$compose_env" -f "$compose_file" exec -T hub php artisan bid:seed-federation-staging-identities
docker compose --project-name mbfd-hub-staging --env-file "$compose_env" -f "$compose_file" up -d tunnel

docker image inspect --format '{{index .Config.Labels "org.opencontainers.image.revision"}}' "mbfd-hub-staging:$release_sha" | grep -qx "$release_sha"
docker compose --project-name mbfd-hub-staging --env-file "$compose_env" -f "$compose_file" exec -T hub curl -fsS http://localhost:8081/up >/dev/null
printf 'hub_staging_release_sha=%s\n' "$release_sha"
