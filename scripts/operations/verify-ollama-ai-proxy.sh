#!/usr/bin/env bash
set -Eeuo pipefail

if [[ ${EUID} -ne 0 ]]; then
    echo "Run as root" >&2
    exit 1
fi

readonly SOURCE_DIR="${1:?source directory is required}"
readonly EXPECTED_SOURCE_SHA="${2:?exact 40-character source SHA is required}"
readonly PROTECTED_REF="refs/remotes/origin/main"
readonly CREDENTIAL_DIR="/etc/ollama-ai-proxy"
readonly CREDENTIAL_FILE="${CREDENTIAL_DIR}/api-key"
readonly STATE_FILE="${CREDENTIAL_DIR}/deployment-source.json"
readonly CONFIG_FILE="${CREDENTIAL_DIR}/gateway.json"
readonly SCRIPT_FILE="/opt/ollama-ai-proxy/mbfd_ai_gateway.py"
readonly UNIT_FILE="/etc/systemd/system/ollama-ai-proxy.service"
readonly SOURCE_RELEASE="${SOURCE_DIR}/mbfd_ai_gateway_release.py"
readonly SOURCE_SMOKE="${SOURCE_DIR}/mbfd-ai-gateway-smoke.py"

release_arguments=(
    --source-dir "${SOURCE_DIR}"
    --expected-sha "${EXPECTED_SOURCE_SHA}"
    --protected-ref "${PROTECTED_REF}"
    --state-file "${STATE_FILE}"
)

/usr/bin/python3 "${SOURCE_RELEASE}" validate-source "${release_arguments[@]}"
/usr/bin/python3 "${SOURCE_RELEASE}" verify-live \
    "${release_arguments[@]}" \
    --live-script "${SCRIPT_FILE}" \
    --live-config "${CONFIG_FILE}" \
    --live-unit "${UNIT_FILE}"

[[ $(stat -c '%U:%G %a' "${CREDENTIAL_FILE}") == "root:root 600" ]]
[[ $(stat -c '%U:%G %a' "${CONFIG_FILE}") == "root:root 600" ]]
[[ $(stat -c '%U:%G %a' "${STATE_FILE}") == "root:root 600" ]]
[[ $(stat -c '%U:%G %a' "${SCRIPT_FILE}") == "root:root 755" ]]
[[ $(stat -c '%U:%G %a' "${UNIT_FILE}") == "root:root 644" ]]

systemctl is-active --quiet ollama-ai-proxy.service
systemctl is-enabled --quiet ollama-ai-proxy.service
actual_listeners="$(ss -ltnH '( sport = :11440 )' | awk '{print $4}' | sort)"
expected_listeners=$'127.0.0.1:11440\n172.20.11.1:11440'
if [[ ${actual_listeners} != "${expected_listeners}" ]]; then
    echo "Gateway listener scope is not exact" >&2
    printf 'observed_listeners=%s\n' "${actual_listeners}" >&2
    exit 2
fi

if grep -q '11441' "${CONFIG_FILE}"; then
    echo "Retired BID experiment port 11441 remains in gateway configuration" >&2
    exit 2
fi
if ss -ltnH | awk '$4 ~ /:11441$/ {found=1} END {exit !found}'; then
    echo "Retired BID experiment port 11441 has a listener" >&2
    exit 2
fi

unauthenticated_status="$(
    curl -sS -o /dev/null -w '%{http_code}' --max-time 5 \
        http://127.0.0.1:11440/health/live
)"
printf 'UNAUTHENTICATED_HEALTH_STATUS=%s\n' "${unauthenticated_status}"
[[ ${unauthenticated_status} == 401 ]]

CREDENTIALS_DIRECTORY="${CREDENTIAL_DIR}" \
    /usr/bin/python3 "${SOURCE_SMOKE}"

printf 'GATEWAY_LISTENERS=127.0.0.1:11440,172.20.11.1:11440\n'
printf 'GATEWAY_CANONICAL_SOURCE=PASS\n'
