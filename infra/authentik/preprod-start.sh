#!/usr/bin/env bash
set -euo pipefail
umask 077

root=/opt/mbfd/authentik-preprod
environment="$root/preprod.env"
if [[ ! -f "$environment" ]]; then
  pg_pass="$(openssl rand -hex 32)"
  authentik_secret="$(openssl rand -base64 60 | tr -d '\n')"
  bootstrap_password="$(openssl rand -base64 36 | tr -d '\n')"
  bootstrap_token="$(openssl rand -hex 32)"
  printf '%s\n' \
    'PG_DB=authentik' \
    'PG_USER=authentik' \
    "PG_PASS=$pg_pass" \
    "AUTHENTIK_SECRET_KEY=$authentik_secret" \
    "AUTHENTIK_BOOTSTRAP_PASSWORD=$bootstrap_password" \
    "AUTHENTIK_BOOTSTRAP_TOKEN=$bootstrap_token" \
    'AUTHENTIK_ERROR_REPORTING__ENABLED=false' \
    'AUTHENTIK_DISABLE_UPDATE_CHECK=true' \
    'AUTHENTIK_COMPOSE_PROJECT=mbfd-authentik-preprod' \
    'AUTHENTIK_ROOT=/opt/mbfd/authentik-preprod' \
    'AUTHENTIK_ENV_FILE=/opt/mbfd/authentik-preprod/preprod.env' \
    'AUTHENTIK_IDENTITY_SUBNET=172.31.245.0/28' \
    'AUTHENTIK_INGRESS_SUBNET=172.31.246.0/28' \
    'AUTHENTIK_TRUSTED_PROXY_CIDRS=172.31.246.1/32,127.0.0.1/32' \
    'AUTHENTIK_BIND_PORT=19000' > "$environment"
  chmod 600 "$environment"
fi
grep -q '^AUTHENTIK_ENV_FILE=' "$environment" || printf '%s\n' \
  'AUTHENTIK_ENV_FILE=/opt/mbfd/authentik-preprod/preprod.env' >> "$environment"
grep -q '^AUTHENTIK_IDENTITY_SUBNET=' "$environment" || printf '%s\n' \
  'AUTHENTIK_IDENTITY_SUBNET=172.31.245.0/28' >> "$environment"
grep -q '^AUTHENTIK_INGRESS_SUBNET=' "$environment" || printf '%s\n' \
  'AUTHENTIK_INGRESS_SUBNET=172.31.246.0/28' >> "$environment"
grep -q '^AUTHENTIK_TRUSTED_PROXY_CIDRS=' "$environment" || printf '%s\n' \
  'AUTHENTIK_TRUSTED_PROXY_CIDRS=172.31.246.1/32,127.0.0.1/32' >> "$environment"

docker compose --env-file "$environment" -f "$root/compose.yaml" config --quiet
docker compose --env-file "$environment" -f "$root/compose.yaml" up -d --remove-orphans
