#!/usr/bin/env bash
set -Eeuo pipefail

if [[ ${EUID} -ne 0 ]]; then
    echo "Run as root" >&2
    exit 1
fi

readonly BACKUP_DIR="/var/lib/mbfd-remediation/20260720-081719/ollama-ai-proxy"
readonly CREDENTIAL_DIR="/etc/ollama-ai-proxy"
readonly CREDENTIAL_FILE="${CREDENTIAL_DIR}/api-key"
readonly UNIT_FILE="/etc/systemd/system/ollama-ai-proxy.service"
readonly SCRIPT_FILE="/opt/ollama-ai-proxy/ollama_ai_proxy.py"
readonly SOURCE_DIR="${1:?source directory is required}"

install -d -o root -g root -m 0700 "${BACKUP_DIR}" "${CREDENTIAL_DIR}"
install -d -o root -g root -m 0755 /opt/ollama-ai-proxy

if ! id ollama-proxy >/dev/null 2>&1; then
    useradd --system --user-group --home-dir /nonexistent --shell /usr/sbin/nologin ollama-proxy
fi

cp --archive "${UNIT_FILE}" "${BACKUP_DIR}/ollama-ai-proxy.service.before"
cp --archive "${SCRIPT_FILE}" "${BACKUP_DIR}/ollama_ai_proxy.py.before"

old_key="$(systemctl show ollama-ai-proxy.service -p Environment --value \
    | tr ' ' '\n' \
    | sed -n 's/^OLLAMA_PROXY_API_KEY=//p' \
    | head -n 1)"
if [[ -z ${old_key} ]]; then
    echo "Existing proxy key could not be recovered; refusing migration" >&2
    exit 1
fi

mapfile -t consumers < <(
    find /etc /opt /srv -xdev -type f -size -2M \
        ! -path '*/.git/*' ! -path '*/node_modules/*' ! -path '*/vendor/*' \
        ! -path '*/storage/*' ! -path '*/data/*' -print0 2>/dev/null \
        | xargs -0r grep -IlF -- "${old_key}" 2>/dev/null \
        | grep -v -E '^/etc/systemd/system/ollama-ai-proxy.service$' \
        | grep -v -E '^/etc/systemd/system/multi-user.target.wants/ollama-ai-proxy.service$' \
        | grep -v -E '^/opt/ollama-ai-proxy/API_KEY.txt$' \
        | grep -v -E '^/var/lib/mbfd-remediation/' \
        || true
)
if (( ${#consumers[@]} > 0 )); then
    printf 'Additional proxy-key consumers require coordinated update:\n' >&2
    printf '  %s\n' "${consumers[@]}" >&2
    exit 2
fi

printf '%s\n' "${old_key}" >"${CREDENTIAL_FILE}"
chown root:root "${CREDENTIAL_FILE}"
chmod 0600 "${CREDENTIAL_FILE}"
if [[ -f /opt/ollama-ai-proxy/API_KEY.txt ]]; then
    cp --archive /opt/ollama-ai-proxy/API_KEY.txt "${BACKUP_DIR}/API_KEY.txt.before"
fi
unset old_key

install -o root -g root -m 0755 "${SOURCE_DIR}/ollama-ai-proxy.py" "${SCRIPT_FILE}"
install -o root -g root -m 0644 "${SOURCE_DIR}/ollama-ai-proxy.service" "${UNIT_FILE}"
python3 -m py_compile "${SCRIPT_FILE}"
systemd-analyze verify "${UNIT_FILE}"
systemctl daemon-reload
systemctl restart ollama-ai-proxy.service

proxy_ready=false
for _ in {1..20}; do
    if curl --silent --show-error --fail \
        --header "Authorization: Bearer $(<"${CREDENTIAL_FILE}")" \
        http://127.0.0.1:11440/api/tags >/dev/null; then
        proxy_ready=true
        break
    fi
    sleep 1
done

if [[ ${proxy_ready} != true ]] \
    || ! systemctl is-active --quiet ollama-ai-proxy.service \
    || ! ss -ltn '( sport = :11440 )' | grep -q '127.0.0.1:11440'; then
    cp --archive "${BACKUP_DIR}/ollama-ai-proxy.service.before" "${UNIT_FILE}"
    cp --archive "${BACKUP_DIR}/ollama_ai_proxy.py.before" "${SCRIPT_FILE}"
    systemctl daemon-reload
    systemctl restart ollama-ai-proxy.service
    echo "Migration failed and was rolled back" >&2
    exit 3
fi

rm -f /opt/ollama-ai-proxy/API_KEY.txt

echo "Ollama proxy credential migrated and loopback listener verified"
