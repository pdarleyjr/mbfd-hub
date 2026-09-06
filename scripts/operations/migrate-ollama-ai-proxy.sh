#!/usr/bin/env bash
set -Eeuo pipefail

if [[ ${EUID} -ne 0 ]]; then
    echo "Run as root" >&2
    exit 1
fi

readonly SOURCE_DIR="${1:?source directory is required}"
readonly EXPECTED_SOURCE_SHA="${2:?exact 40-character source SHA is required}"
readonly INITIALIZATION_MODE="${3:-}"
readonly PROTECTED_REF="refs/remotes/origin/main"
readonly CREDENTIAL_DIR="/etc/ollama-ai-proxy"
readonly LEGACY_CREDENTIAL="${CREDENTIAL_DIR}/api-key"
readonly SPORTS_CREDENTIAL="${CREDENTIAL_DIR}/sports-intelligence-api-key"
readonly APPLICATION_CREDENTIAL_FILES=(
    "${CREDENTIAL_DIR}/mbfd-hub-api-key"
    "${CREDENTIAL_DIR}/media-control-api-key"
    "${CREDENTIAL_DIR}/hermes-api-key"
    "${CREDENTIAL_DIR}/command-api-key"
    "${CREDENTIAL_DIR}/eoc-api-key"
    "${CREDENTIAL_DIR}/ts-orchestrator-api-key"
    "${CREDENTIAL_DIR}/mbfd-support-ai-api-key"
    "${CREDENTIAL_DIR}/external-coding-api-key"
)
readonly CONFIG_FILE="${CREDENTIAL_DIR}/gateway.json"
readonly STATE_FILE="${CREDENTIAL_DIR}/deployment-source.json"
readonly UNIT_FILE="/etc/systemd/system/ollama-ai-proxy.service"
readonly INSTALL_DIR="/opt/ollama-ai-proxy"
readonly SCRIPT_FILE="${INSTALL_DIR}/mbfd_ai_gateway.py"
readonly BACKUP_DIR="/var/backups/mbfd-ai-gateway/$(date -u +%Y%m%dT%H%M%SZ)-source-convergence"

readonly SOURCE_SCRIPT="${SOURCE_DIR}/mbfd_ai_gateway.py"
readonly SOURCE_CONFIG="${SOURCE_DIR}/mbfd-ai-gateway.json"
readonly SOURCE_UNIT="${SOURCE_DIR}/ollama-ai-proxy.service"
readonly SOURCE_SMOKE="${SOURCE_DIR}/mbfd-ai-gateway-smoke.py"
readonly SOURCE_RELEASE="${SOURCE_DIR}/mbfd_ai_gateway_release.py"
readonly SOURCE_VERIFY="${SOURCE_DIR}/verify-ollama-ai-proxy.sh"
readonly SOURCE_DEPLOY="${SOURCE_DIR}/migrate-ollama-ai-proxy.sh"

declare -a initialize_argument=()
case "${INITIALIZATION_MODE}" in
    "") ;;
    --initialize-source-state) initialize_argument=(--allow-initialize) ;;
    *)
        echo "Unsupported third argument: ${INITIALIZATION_MODE}" >&2
        exit 2
        ;;
esac

for required in \
    "${SOURCE_SCRIPT}" \
    "${SOURCE_CONFIG}" \
    "${SOURCE_UNIT}" \
    "${SOURCE_SMOKE}" \
    "${SOURCE_RELEASE}" \
    "${SOURCE_VERIFY}" \
    "${SOURCE_DEPLOY}" \
    "${LEGACY_CREDENTIAL}" \
    "${SPORTS_CREDENTIAL}" \
    "${APPLICATION_CREDENTIAL_FILES[@]}"; do
    if [[ ! -f ${required} || -L ${required} ]]; then
        echo "Required gateway artifact is missing or symlinked: ${required}" >&2
        exit 2
    fi
done

for credential_file in \
    "${LEGACY_CREDENTIAL}" \
    "${SPORTS_CREDENTIAL}" \
    "${APPLICATION_CREDENTIAL_FILES[@]}"; do
    if [[ $(stat -c '%U:%G %a' "${credential_file}") != "root:root 600" ]]; then
        echo "Gateway credential has unsafe ownership or mode: ${credential_file}" >&2
        exit 2
    fi
done

release_arguments=(
    --source-dir "${SOURCE_DIR}"
    --expected-sha "${EXPECTED_SOURCE_SHA}"
    --protected-ref "${PROTECTED_REF}"
    --state-file "${STATE_FILE}"
    "${initialize_argument[@]}"
)

/usr/bin/python3 "${SOURCE_RELEASE}" validate-source "${release_arguments[@]}"
CREDENTIALS_DIRECTORY="${CREDENTIAL_DIR}" \
    /usr/bin/python3 "${SOURCE_SCRIPT}" --validate-config "${SOURCE_CONFIG}"
/usr/bin/python3 -m py_compile \
    "${SOURCE_SCRIPT}" \
    "${SOURCE_SMOKE}" \
    "${SOURCE_RELEASE}"
bash -n "${SOURCE_DEPLOY}" "${SOURCE_VERIFY}"
systemd-analyze verify "${SOURCE_UNIT}"

state_candidate="$(mktemp "${CREDENTIAL_DIR}/.deployment-source.XXXXXX")"
cleanup_state_candidate() {
    rm -f -- "${state_candidate}"
}
trap cleanup_state_candidate EXIT
/usr/bin/python3 "${SOURCE_RELEASE}" build-state "${release_arguments[@]}" \
    >"${state_candidate}"
chmod 0600 "${state_candidate}"

install -d -o root -g root -m 0700 "${BACKUP_DIR}"
STATE_WAS_PRESENT=false
for live in "${SCRIPT_FILE}" "${CONFIG_FILE}" "${UNIT_FILE}" "${STATE_FILE}"; do
    if [[ -f ${live} ]]; then
        cp --archive "${live}" "${BACKUP_DIR}/$(basename "${live}").before"
        if [[ ${live} == "${STATE_FILE}" ]]; then
            STATE_WAS_PRESENT=true
        fi
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
    if [[ ${STATE_WAS_PRESENT} == true ]]; then
        cp --archive "${BACKUP_DIR}/$(basename "${STATE_FILE}").before" "${STATE_FILE}"
    else
        rm -f -- "${STATE_FILE}"
    fi
    systemctl daemon-reload
    systemctl restart ollama-ai-proxy.service
    echo "Gateway deployment failed; prior files restored from ${BACKUP_DIR}" >&2
}

deployment_failure() {
    local status="${1:-1}"
    trap - ERR
    if [[ ${deployment_started} == true ]]; then
        restore_previous
    fi
    exit "${status}"
}

deployment_started=false
trap 'deployment_failure "$?"' ERR

deployment_started=true
install -d -o root -g root -m 0755 "${INSTALL_DIR}"
install -o root -g root -m 0755 "${SOURCE_SCRIPT}" "${SCRIPT_FILE}"
install -o root -g root -m 0600 "${SOURCE_CONFIG}" "${CONFIG_FILE}"
install -o root -g root -m 0644 "${SOURCE_UNIT}" "${UNIT_FILE}"
install -o root -g root -m 0600 "${state_candidate}" "${STATE_FILE}"

CREDENTIALS_DIRECTORY="${CREDENTIAL_DIR}" \
    /usr/bin/python3 "${SCRIPT_FILE}" --validate-config "${CONFIG_FILE}"
/usr/bin/python3 -m py_compile "${SCRIPT_FILE}"
systemd-analyze verify "${UNIT_FILE}"
systemctl daemon-reload
systemctl restart ollama-ai-proxy.service

"${SOURCE_VERIFY}" "${SOURCE_DIR}" "${EXPECTED_SOURCE_SHA}"

deployment_started=false
trap - ERR
echo "MBFD AI Gateway canonical source converged at ${EXPECTED_SOURCE_SHA}"
echo "Rollback backup: ${BACKUP_DIR}"
