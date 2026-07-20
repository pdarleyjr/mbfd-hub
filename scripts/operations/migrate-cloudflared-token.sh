#!/usr/bin/env bash
set -Eeuo pipefail

readonly SOURCE_UNIT=${1:-}
readonly CURRENT_UNIT=/etc/systemd/system/cloudflared.service
readonly TOKEN_DIR=/etc/cloudflared
readonly TOKEN_FILE=${TOKEN_DIR}/tunnel.token
readonly BACKUP_DIR=/var/lib/mbfd-remediation/20260720-081719

if [[ -z "${SOURCE_UNIT}" || ! -f "${SOURCE_UNIT}" ]]; then
    echo "Usage: $0 /path/to/cloudflared.service" >&2
    exit 64
fi

if [[ ! -f "${CURRENT_UNIT}" ]]; then
    echo "Current cloudflared unit is missing" >&2
    exit 1
fi

mapfile -t token_lines < <(sed -nE 's/^ExecStart=.*[[:space:]]--token[=[:space:]]+([^[:space:]]+).*$/\1/p' "${CURRENT_UNIT}")
if (( ${#token_lines[@]} != 1 )) || [[ -z "${token_lines[0]}" ]]; then
    echo "Expected exactly one token argument in the current cloudflared unit" >&2
    exit 1
fi

token=${token_lines[0]}
install -d -m 0700 -o root -g root "${TOKEN_DIR}"
install -d -m 0700 -o root -g root "${BACKUP_DIR}"
cp -a "${CURRENT_UNIT}" "${BACKUP_DIR}/cloudflared.service.before-token-file"

umask 077
token_tmp=$(mktemp "${TOKEN_DIR}/.tunnel.token.XXXXXX")
trap 'rm -f "${token_tmp:-}"' EXIT
printf '%s\n' "${token}" > "${token_tmp}"
chown root:root "${token_tmp}"
chmod 0600 "${token_tmp}"
mv -f "${token_tmp}" "${TOKEN_FILE}"
trap - EXIT

install -m 0600 -o root -g root "${SOURCE_UNIT}" "${CURRENT_UNIT}"
systemd-analyze verify cloudflared.service
systemctl daemon-reload

if systemctl show -p ExecStart --value cloudflared.service | grep -q -- '--token[ =]'; then
    echo "Refusing restart: token argument remains in loaded unit" >&2
    exit 1
fi

systemctl restart cloudflared.service
for attempt in $(seq 1 30); do
    if systemctl is-active --quiet cloudflared.service; then
        break
    fi
    if (( attempt == 30 )); then
        echo "cloudflared did not become active" >&2
        exit 1
    fi
    sleep 1
done

pid=$(systemctl show -p MainPID --value cloudflared.service)
if tr '\0' ' ' < "/proc/${pid}/cmdline" | grep -q -- '--token[ =]'; then
    echo "Token remains visible in process arguments" >&2
    exit 1
fi

echo "cloudflared_token_file_migration=pass"
