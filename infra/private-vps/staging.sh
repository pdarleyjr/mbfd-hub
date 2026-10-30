#!/bin/bash
# Only for an approved isolated Linux test host. Never installs Docker or alters
# host networking. No production import, route switch, token or backup deletion.
set -euo pipefail
cd "$(dirname "$0")"
export STAGING_SECRET_DIR=${STAGING_SECRET_DIR:-/run/mbfd-v2-synthetic-secrets}
engine=(docker --host unix:///var/run/docker.sock)
compose=("${engine[@]}" compose -p mbfd-v2-synthetic -f compose.private.yaml --profile private)
host_gate() {
  test "$(id -u)" = 0 || { echo 'Use a dedicated root-owned Linux rehearsal environment.' >&2; exit 1; }
  for tool in ip systemctl hostname docker openssl; do command -v "$tool" >/dev/null; done
  local host_name addresses unit_files
  host_name=$(hostname)
  addresses=$(ip -o -4 addr show)
  unit_files=$(systemctl list-unit-files --type=service --no-legend --no-pager)
  test -n "$host_name" && test -n "$addresses"
  case "$host_name" in srv758882*) echo 'Bonding VPS is blocked pending provider/recovery and OMR retirement gates.' >&2; exit 1;; esac
  if grep -q '145\.223\.73\.170/' <<< "$addresses"; then echo 'Bonding VPS is blocked.' >&2; exit 1; fi
  if grep -Eq '^(mqvpn|omr-admin)\.service[[:space:]]' <<< "$unit_files"; then
    echo 'An installed OMR host is blocked even if a service is stopped.' >&2; exit 1
  fi
  test "${MBFD_SYNTHETIC_ONLY:-}" = YES || { echo 'Set MBFD_SYNTHETIC_ONLY=YES only on an approved isolated synthetic test host.' >&2; exit 1; }
  test "$STAGING_SECRET_DIR" = /run/mbfd-v2-synthetic-secrets || { echo 'Only the dedicated volatile synthetic secret directory is accepted.' >&2; exit 1; }
  test -z "${DOCKER_HOST:-}${DOCKER_CONTEXT:-}${DOCKER_TLS_VERIFY:-}${DOCKER_CERT_PATH:-}" || { echo 'Docker endpoint overrides are forbidden.' >&2; exit 1; }
  test -S /var/run/docker.sock || { echo 'Verified local Docker Unix socket required.' >&2; exit 1; }
  "${engine[@]}" info >/dev/null
}
case "${1:-check}" in
  check)
    # No daemon required. Never emit interpolated secrets or runtime environment.
    "${compose[@]}" config --quiet
    echo 'Compose syntax valid; runtime, provider and recovery acceptance remain separate.'
    ;;
  init)
    host_gate
    test ! -e "$STAGING_SECRET_DIR" || { echo 'Synthetic secret directory already exists; refusing overwrite.' >&2; exit 1; }
    umask 077
    mkdir -m 700 "$STAGING_SECRET_DIR"
    for name in hub_db reverb_key reverb_secret identity_db identity_key; do
      openssl rand -hex 32 > "$STAGING_SECRET_DIR/$name"
      chmod 444 "$STAGING_SECRET_DIR/$name"
    done
    { printf 'base64:'; openssl rand -base64 32; } > "$STAGING_SECRET_DIR/hub_key"
    chmod 444 "$STAGING_SECRET_DIR/hub_key"
    echo 'Synthetic secret files created in volatile root-only directory; values withheld.'
    ;;
  start)
    host_gate
    for name in hub_db hub_key reverb_key reverb_secret identity_db identity_key; do test -s "$STAGING_SECRET_DIR/$name"; done
    # Avoid silently pulling/rebuilding Command from another source.
    expected=sha256:00890e1afed6b3b916232047ce7691f1d554742531321c535402afc448ad9817
    test "$("${engine[@]}" image inspect "$expected" --format '{{.Id}}')" = "$expected"
    "${compose[@]}" config --quiet
    "${compose[@]}" up -d hub-db redis identity-db
    "${compose[@]}" run --rm hub /usr/bin/php /var/www/html/artisan migrate --force --no-interaction
    "${compose[@]}" up -d
    echo 'Synthetic stack started; verify health, egress denial, scanner and synthetic OIDC before acceptance.'
    ;;
  stop)
    host_gate
    "${compose[@]}" stop
    echo 'Stopped only the synthetic project; all volumes and data retained.'
    ;;
  *) echo 'Usage: staging.sh check|init|start|stop' >&2; exit 2;;
esac
