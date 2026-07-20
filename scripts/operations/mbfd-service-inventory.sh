#!/usr/bin/env bash
# Produce a read-only, secret-safe inventory of the GMKtec runtime.
set -Eeuo pipefail

if [[ ${EUID} -ne 0 ]]; then
    echo "Run as root" >&2
    exit 1
fi

readonly OUTPUT_ROOT="${1:-/var/lib/mbfd-remediation/inventory}"
readonly CREATED_AT="$(date -u +%Y%m%dT%H%M%SZ)"
readonly OUTPUT_DIR="${OUTPUT_ROOT}/${CREATED_AT}"

install -d -o root -g root -m 0700 "${OUTPUT_ROOT}" "${OUTPUT_DIR}"

{
    printf 'created_at_utc=%s\n' "${CREATED_AT}"
    printf 'hostname=%s\n' "$(hostname --fqdn 2>/dev/null || hostname)"
    printf 'kernel=%s\n' "$(uname -sr)"
    . /etc/os-release
    printf 'os=%s\n' "${PRETTY_NAME}"
    printf 'running_containers=%s\n' "$(docker ps -q | wc -l)"
    printf 'unhealthy_containers=%s\n' "$(docker ps --filter health=unhealthy -q | wc -l)"
    printf 'failed_units=%s\n' "$(systemctl --failed --no-legend | wc -l)"
} >"${OUTPUT_DIR}/host-summary.txt"

docker ps --no-trunc \
    --format '{{json .}}' \
    | jq -s 'sort_by(.Names)' >"${OUTPUT_DIR}/containers.json"

docker inspect $(docker ps -q) \
    | jq '[.[] | {
        name: (.Name | ltrimstr("/")),
        image_reference: .Config.Image,
        image_id: .Image,
        created: .Created,
        started_at: .State.StartedAt,
        restart_count: .RestartCount,
        oom_killed: .State.OOMKilled,
        health: (.State.Health.Status // "not-configured"),
        published_ports: [.NetworkSettings.Ports | to_entries[]? | {
            container_port: .key,
            host_bindings: (.value // [])
        }]
    }] | sort_by(.name)' >"${OUTPUT_DIR}/container-runtime.json"

docker image inspect $(docker ps --format '{{.Image}}' | sort -u) \
    | jq '[.[] | {
        tags: (.RepoTags // []),
        digests: (.RepoDigests // []),
        id: .Id,
        created: .Created,
        architecture: .Architecture,
        os: .Os
    }] | sort_by(.tags[0] // .id)' >"${OUTPUT_DIR}/running-images.json"

systemctl list-timers --all --no-pager --no-legend \
    | sed -E 's/[[:space:]]+/ /g' >"${OUTPUT_DIR}/timers.txt"
systemctl --failed --no-pager >"${OUTPUT_DIR}/failed-units.txt"
findmnt --json --real >"${OUTPUT_DIR}/mounts.json"
free --bytes >"${OUTPUT_DIR}/memory.txt"
df --block-size=1 --output=source,fstype,size,used,avail,pcent,target \
    >"${OUTPUT_DIR}/filesystems.txt"
ss -H -lnt \
    | awk '{print $1, $4}' \
    | sort -u >"${OUTPUT_DIR}/tcp-listeners.txt"

find "${OUTPUT_DIR}" -type f ! -name SHA256SUMS -print0 \
    | sort -z \
    | xargs -0 sha256sum >"${OUTPUT_DIR}/SHA256SUMS"
chmod -R go-rwx "${OUTPUT_DIR}"

printf 'Inventory written to %s\n' "${OUTPUT_DIR}"
