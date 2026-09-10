#!/usr/bin/env bash
set -euo pipefail
umask 077

dump="${1:?usage: restore-drill.sh /absolute/path/authentik.dump}"
[[ "$dump" = /* && -f "$dump" ]] || { echo 'Restore input must be an existing absolute path.' >&2; exit 2; }
image='docker.io/library/postgres:16.10-alpine3.22@sha256:ab8380566c3ea09690a9ecaa85a59d82bfc6eb86744151a2a54335866c83a3e9'
name="authentik-restore-drill-$$"
password="$(openssl rand -hex 32)"
environment="$(mktemp)"
printf 'POSTGRES_PASSWORD=%s\nPOSTGRES_DB=restore_drill\n' "$password" > "$environment"
cleanup() { docker rm -f "$name" >/dev/null 2>&1 || true; rm -f -- "$environment"; }
trap cleanup EXIT

docker run -d --name "$name" --tmpfs /var/lib/postgresql/data \
  --env-file "$environment" "$image" >/dev/null
for _ in $(seq 1 60); do
  docker exec "$name" pg_isready -U postgres -d restore_drill >/dev/null 2>&1 && break
  sleep 1
done
docker exec "$name" pg_isready -U postgres -d restore_drill >/dev/null
docker cp "$dump" "$name:/tmp/authentik.dump"
docker exec "$name" pg_restore --username=postgres --dbname=restore_drill --no-owner --no-acl /tmp/authentik.dump
objects="$(docker exec "$name" psql --username=postgres --dbname=restore_drill --tuples-only --no-align --command="select count(*) from pg_class where relkind in ('r','p');")"
[[ "$objects" =~ ^[1-9][0-9]*$ ]] || { echo 'Restore drill produced no tables.' >&2; exit 1; }
printf 'AUTHENTIK_RESTORE_DRILL_OK=objects:%s\n' "$objects"
