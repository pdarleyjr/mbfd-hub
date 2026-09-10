#!/usr/bin/env bash
set -euo pipefail
umask 077

[[ "${EUID}" -eq 0 ]] || { echo 'Run as root.' >&2; exit 1; }
source_dir="$(realpath "${1:?usage: install-production.sh /absolute/source/infra/authentik}")"
[[ -f "$source_dir/compose.yaml" && -f "$source_dir/configure-tenant.sh" ]] || {
  echo 'The source directory is not an authentik deployment bundle.' >&2
  exit 1
}

root=/opt/mbfd/authentik
environment=/etc/mbfd/authentik.env
hub_environment=/etc/mbfd/authentik-hub.env
install -d -m 0750 -o root -g mbfd /etc/mbfd "$root" "$root/backups" "$root/blueprints" \
  "$root/custom-templates" "$root/state"
install -d -m 0750 -o 1000 -g 1000 "$root/certs" "$root/state/data"
install -d -m 0700 -o 70 -g 70 "$root/state/postgresql"
install -m 0644 "$source_dir/compose.yaml" "$root/compose.yaml"
install -m 0644 "$source_dir/blueprints/mbfd-identity-recovery.yaml" "$root/blueprints/mbfd-identity-recovery.yaml"
for script in backup.sh configure-tenant.sh healthcheck.sh restore-drill.sh; do
  install -m 0750 "$source_dir/$script" "$root/$script"
done

if [[ ! -f "$environment" ]]; then
  pg_pass="$(openssl rand -hex 32)"
  authentik_secret="$(openssl rand -base64 60 | tr -d '\n')"
  bootstrap_password="$(openssl rand -base64 36 | tr -d '\n')"
  bootstrap_token="$(openssl rand -hex 32)"
  {
    printf 'PG_DB=authentik\nPG_USER=authentik\n'
    printf 'PG_PASS=%s\n' "$pg_pass"
    printf 'AUTHENTIK_SECRET_KEY=%s\n' "$authentik_secret"
    printf 'AUTHENTIK_BOOTSTRAP_PASSWORD=%s\n' "$bootstrap_password"
    printf 'AUTHENTIK_BOOTSTRAP_TOKEN=%s\n' "$bootstrap_token"
    printf 'AUTHENTIK_ERROR_REPORTING__ENABLED=false\nAUTHENTIK_DISABLE_UPDATE_CHECK=true\n'
    printf 'AUTHENTIK_COMPOSE_PROJECT=mbfd-authentik\n'
    printf 'AUTHENTIK_ROOT=%s\nAUTHENTIK_ENV_FILE=%s\n' "$root" "$environment"
    printf 'AUTHENTIK_IDENTITY_SUBNET=172.31.243.0/28\n'
    printf 'AUTHENTIK_INGRESS_SUBNET=172.31.244.0/28\n'
    printf 'AUTHENTIK_TRUSTED_PROXY_CIDRS=172.31.244.1/32,127.0.0.1/32\n'
    printf 'AUTHENTIK_BIND_PORT=9000\n'
  } > "$environment"
  chmod 0600 "$environment"
fi

docker compose --env-file "$environment" -f "$root/compose.yaml" config --quiet
docker compose --env-file "$environment" -f "$root/compose.yaml" pull --quiet
docker compose --env-file "$environment" -f "$root/compose.yaml" up -d --remove-orphans >/dev/null
for _ in $(seq 1 90); do
  AUTHENTIK_ROOT="$root" AUTHENTIK_ENV_FILE="$environment" "$root/healthcheck.sh" >/dev/null 2>&1 && break
  sleep 2
done
AUTHENTIK_ROOT="$root" AUTHENTIK_ENV_FILE="$environment" "$root/healthcheck.sh" >/dev/null

if [[ ! -f "$hub_environment" ]]; then
  AUTHENTIK_ROOT="$root" \
  AUTHENTIK_ENV_FILE="$environment" \
  AUTHENTIK_HUB_ENV_FILE="$hub_environment" \
  AUTHENTIK_BREAK_GLASS_FILE=/etc/mbfd/authentik-break-glass.env \
  AUTHENTIK_BIND_PORT=9000 \
  CLOUDFLARE_ACCESS_REDIRECT_URI=https://darl.cloudflareaccess.com/cdn-cgi/access/callback \
    "$root/configure-tenant.sh"
fi

for unit in mbfd-authentik.service mbfd-authentik-health.service mbfd-authentik-health.timer \
  mbfd-authentik-backup.service mbfd-authentik-backup.timer; do
  install -m 0644 "$source_dir/$unit" "/etc/systemd/system/$unit"
done
systemctl daemon-reload
systemctl enable --now mbfd-authentik.service mbfd-authentik-health.timer mbfd-authentik-backup.timer >/dev/null

echo 'AUTHENTIK_PRODUCTION_INSTALL=PASS'
