#!/usr/bin/env bash
set -Eeuo pipefail

if [[ ${EUID} -ne 0 ]]; then
    echo "Run as root" >&2
    exit 1
fi

readonly SOURCE_DIR="${1:?source directory is required}"
readonly CREDENTIAL_DIR="/etc/ollama-ai-proxy"
readonly LEGACY_CREDENTIAL="${CREDENTIAL_DIR}/api-key"
readonly CONFIG_FILE="${CREDENTIAL_DIR}/gateway.json"
readonly UNIT_FILE="/etc/systemd/system/ollama-ai-proxy.service"
readonly INSTALL_DIR="/opt/ollama-ai-proxy"
readonly SCRIPT_FILE="${INSTALL_DIR}/mbfd_ai_gateway.py"
readonly BACKUP_DIR="/var/backups/mbfd-ai-gateway/$(date -u +%Y%m%dT%H%M%SZ)-source-convergence"

readonly SOURCE_SCRIPT="${SOURCE_DIR}/mbfd_ai_gateway.py"
readonly SOURCE_CONFIG="${SOURCE_DIR}/mbfd-ai-gateway.json"
readonly SOURCE_UNIT="${SOURCE_DIR}/ollama-ai-proxy.service"

for required in \
    "${SOURCE_SCRIPT}" \
    "${SOURCE_CONFIG}" \
    "${SOURCE_UNIT}" \
    "${LEGACY_CREDENTIAL}"; do
    if [[ ! -f ${required} ]]; then
        echo "Required gateway artifact is missing: ${required}" >&2
        exit 2
    fi
done

for credential in "${LEGACY_CREDENTIAL}"; do
    if [[ $(stat -c '%U:%G %a' "${credential}") != "root:root 600" ]]; then
        echo "Gateway credential has unsafe ownership or mode: ${credential}" >&2
        exit 2
    fi
done

CREDENTIALS_DIRECTORY="${CREDENTIAL_DIR}" \
    python3 "${SOURCE_SCRIPT}" --validate-config "${SOURCE_CONFIG}"
python3 -m py_compile "${SOURCE_SCRIPT}"
systemd-analyze verify "${SOURCE_UNIT}"

install -d -o root -g root -m 0700 "${BACKUP_DIR}"
for live in "${SCRIPT_FILE}" "${CONFIG_FILE}" "${UNIT_FILE}"; do
    if [[ -f ${live} ]]; then
        cp --archive "${live}" "${BACKUP_DIR}/$(basename "${live}").before"
    fi
done
sha256sum "${BACKUP_DIR}"/*.before >"${BACKUP_DIR}/SHA256SUMS"

restore_previous() {
    set +e
    for live in "${SCRIPT_FILE}" "${CONFIG_FILE}" "${UNIT_FILE}"; do
        previous="${BACKUP_DIR}/$(basename "${live}").before"
        if [[ -f ${previous} ]]; then
            cp --archive "${previous}" "${live}"
        fi
    done
    systemctl daemon-reload
    systemctl restart ollama-ai-proxy.service
    echo "Gateway deployment failed; prior files restored from ${BACKUP_DIR}" >&2
}

deployment_started=false
trap 'if [[ ${deployment_started} == true ]]; then restore_previous; fi' ERR

deployment_started=true
install -d -o root -g root -m 0755 "${INSTALL_DIR}"
install -o root -g root -m 0755 "${SOURCE_SCRIPT}" "${SCRIPT_FILE}"
install -o root -g root -m 0600 "${SOURCE_CONFIG}" "${CONFIG_FILE}"
install -o root -g root -m 0644 "${SOURCE_UNIT}" "${UNIT_FILE}"

CREDENTIALS_DIRECTORY="${CREDENTIAL_DIR}" \
    python3 "${SCRIPT_FILE}" --validate-config "${CONFIG_FILE}"
python3 -m py_compile "${SCRIPT_FILE}"
systemd-analyze verify "${UNIT_FILE}"
systemctl daemon-reload
systemctl restart ollama-ai-proxy.service

authenticated_probe() {
    local credential_file=$1
    local capability=$2
    local token
    token=$(<"${credential_file}")
    printf '%s\n' \
        'silent' \
        'show-error' \
        'fail' \
        'max-time = 5' \
        'url = "http://127.0.0.1:11440/health/live"' \
        "header = \"Authorization: Bearer ${token}\"" \
        "header = \"X-MBFD-Capability: ${capability}\"" \
        | curl --config - >/dev/null
    unset token
}

gateway_ready=false
for _ in {1..20}; do
    if systemctl is-active --quiet ollama-ai-proxy.service \
        && authenticated_probe "${LEGACY_CREDENTIAL}" "mbfd-general"; then
        gateway_ready=true
        break
    fi
    sleep 1
done

if [[ ${gateway_ready} != true ]] \
    || ! ss -ltn '( sport = :11440 )' | grep -q '127.0.0.1:11440' \
    || ! ss -ltn '( sport = :11440 )' | grep -q '172.20.11.1:11440'; then
    echo "Gateway did not pass post-deployment health or listener checks" >&2
    false
fi

deployment_started=false
trap - ERR

echo "MBFD AI Gateway canonical source and active non-BID policy converged"
echo "Rollback backup: ${BACKUP_DIR}"
