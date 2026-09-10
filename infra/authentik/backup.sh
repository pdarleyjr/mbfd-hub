#!/usr/bin/env bash
set -euo pipefail
umask 077

root="${AUTHENTIK_ROOT:-/opt/mbfd/authentik}"
environment="${AUTHENTIK_ENV_FILE:-/etc/mbfd/authentik.env}"
compose="$root/compose.yaml"
backup_root="$root/backups"
stamp="$(date -u +%Y%m%dT%H%M%SZ)"
target="$backup_root/$stamp"
mkdir -p "$target"

docker compose --env-file "$environment" -f "$compose" exec -T postgresql pg_dump \
  --username="${PG_USER:-authentik}" --dbname="${PG_DB:-authentik}" \
  --format=custom --no-owner --no-acl > "$target/authentik.dump"
tar --create --gzip --file="$target/authentik-state.tgz" \
  --directory="$root" state/data certs custom-templates
cp "$compose" "$target/compose.yaml"
(cd "$target" && sha256sum authentik.dump authentik-state.tgz compose.yaml > SHA256SUMS)

find "$backup_root" -mindepth 1 -maxdepth 1 -type d -mtime +14 -print0 | xargs -0r rm -rf --
printf 'AUTHENTIK_BACKUP_OK=%s\n' "$target"
