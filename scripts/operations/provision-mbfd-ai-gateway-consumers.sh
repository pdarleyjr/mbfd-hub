#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

if [[ ${EUID} -ne 0 ]]; then
    echo "Run as root" >&2
    exit 1
fi

readonly CREDENTIAL_DIR="/etc/ollama-ai-proxy"
readonly -a CONSUMERS=(
    "mbfd-hub"
    "media-control"
    "hermes"
    "command"
    "eoc"
    "ts-orchestrator"
    "mbfd-support-ai"
    "external-coding"
)

if [[ ! -d ${CREDENTIAL_DIR} || -L ${CREDENTIAL_DIR} ]]; then
    echo "Gateway credential directory is missing or symlinked" >&2
    exit 2
fi
if [[ $(stat -c '%U:%G %a' "${CREDENTIAL_DIR}") != "root:root 700" ]]; then
    echo "Gateway credential directory must be root:root 700" >&2
    exit 2
fi
command -v openssl >/dev/null
command -v sha256sum >/dev/null

temporary_file=""
cleanup() {
    if [[ -n ${temporary_file} && -f ${temporary_file} ]]; then
        rm -f -- "${temporary_file}"
    fi
}
trap cleanup EXIT

for consumer in "${CONSUMERS[@]}"; do
    credential_file="${CREDENTIAL_DIR}/${consumer}-api-key"
    if [[ -L ${credential_file} ]]; then
        echo "Credential target is symlinked: ${credential_file}" >&2
        exit 2
    fi
    if [[ -e ${credential_file} ]]; then
        if [[ ! -f ${credential_file} \
            || $(stat -c '%U:%G %a' "${credential_file}") != "root:root 600" \
            || ! -s ${credential_file} ]]; then
            echo "Existing credential has unsafe type, ownership, mode, or size: ${credential_file}" >&2
            exit 2
        fi
        fingerprint="$(sha256sum "${credential_file}" | awk '{print substr($1, 1, 16)}')"
        printf 'CREDENTIAL=%s|STATE=EXISTING|FINGERPRINT=%s\n' \
            "${consumer}" "${fingerprint}"
        continue
    fi

    temporary_file="$(mktemp "${CREDENTIAL_DIR}/.${consumer}.XXXXXX")"
    openssl rand -hex 32 >"${temporary_file}"
    chown root:root "${temporary_file}"
    chmod 0600 "${temporary_file}"
    mv -- "${temporary_file}" "${credential_file}"
    temporary_file=""
    fingerprint="$(sha256sum "${credential_file}" | awk '{print substr($1, 1, 16)}')"
    printf 'CREDENTIAL=%s|STATE=CREATED|FINGERPRINT=%s\n' \
        "${consumer}" "${fingerprint}"
done

declare -A fingerprint_owner=()
while IFS= read -r credential_file; do
    fingerprint="$(sha256sum "${credential_file}" | awk '{print $1}')"
    name="$(basename "${credential_file}")"
    if [[ -n ${fingerprint_owner[${fingerprint}]:-} ]]; then
        printf 'Duplicate gateway credentials: %s and %s\n' \
            "${fingerprint_owner[${fingerprint}]}" "${name}" >&2
        exit 2
    fi
    fingerprint_owner[${fingerprint}]="${name}"
done < <(
    find "${CREDENTIAL_DIR}" -maxdepth 1 -type f \
        \( -name 'api-key' -o -name '*-api-key' \) \
        -print \
        | sort
)

printf 'MBFD_AI_GATEWAY_CONSUMER_CREDENTIALS=PASS\n'
