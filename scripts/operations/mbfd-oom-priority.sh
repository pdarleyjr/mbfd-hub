#!/usr/bin/env bash
# Apply/report conservative OOM priorities for core MBFD processes.
set -Eeuo pipefail

readonly MODE="${1:-status}"

case "${MODE}" in
    status|apply|reset) ;;
    *) echo "Usage: $0 status|apply|reset" >&2; exit 64 ;;
esac

set_adjustment() {
    local pid="${1}"
    local adjustment="${2}"
    local label="${3}"

    if [[ -z ${pid} || ${pid} -le 1 || ! -e /proc/${pid}/oom_score_adj ]]; then
        return
    fi

    if [[ ${MODE} == apply || ${MODE} == reset ]]; then
        printf '%s\n' "${adjustment}" >"/proc/${pid}/oom_score_adj"
    fi

    printf '%-36s pid=%-8s adj=%-5s score=%s comm=%s\n' \
        "${label}" "${pid}" \
        "$(</proc/${pid}/oom_score_adj)" \
        "$(</proc/${pid}/oom_score)" \
        "$(</proc/${pid}/comm)"
}

process_tree() {
    local root_pid="${1}"
    local child_pid

    [[ ${root_pid} -gt 1 ]] || return
    printf '%s\n' "${root_pid}"
    while read -r child_pid; do
        [[ -n ${child_pid} ]] && process_tree "${child_pid}"
    done < <(pgrep -P "${root_pid}" 2>/dev/null || true)
}

apply_unit() {
    local unit="${1}"
    local adjustment="${2}"
    local pid

    pid="$(systemctl show -p MainPID --value "${unit}" 2>/dev/null || echo 0)"
    while read -r process_pid; do
        set_adjustment "${process_pid}" "${adjustment}" "unit:${unit}"
    done < <(process_tree "${pid:-0}" | sort -nu)
}

apply_container() {
    local container="${1}"
    local adjustment="${2}"
    local pid

    pid="$(docker inspect "${container}" --format '{{.State.Pid}}' 2>/dev/null || echo 0)"
    while read -r process_pid; do
        set_adjustment "${process_pid}" "${adjustment}" "container:${container}"
    done < <(process_tree "${pid:-0}" | sort -nu)
}

if [[ ${MODE} == reset ]]; then
    connectivity_adjustment=0
    runtime_adjustment=0
    data_adjustment=0
    application_adjustment=0
    ai_adjustment=0
else
    # Keep connectivity and container supervision preferentially available without
    # making every workload effectively immune to the kernel's last-resort OOM choice.
    connectivity_adjustment=-750
    runtime_adjustment=-500
    data_adjustment=-300
    application_adjustment=-150
    ai_adjustment=-100
fi

printf '===== OOM PRIORITY %s =====\n' "${MODE}"
apply_unit cloudflared.service "${connectivity_adjustment}"
apply_unit tailscaled.service "${connectivity_adjustment}"
apply_unit docker.service "${runtime_adjustment}"
apply_unit crowdsec.service "${application_adjustment}"
apply_unit auditd.service "${application_adjustment}"
apply_unit ollama.service "${ai_adjustment}"

for container in \
    mbfd-postgres mbfd-hub-pgsql mbfd-snipeit-db vac-postgres \
    mbfd-hub-redis mbfd-redis vac-redis qdrant; do
    apply_container "${container}" "${data_adjustment}"
done

for container in \
    media-control mbfd-screentinker open-webui mbfd-hub-laravel \
    mbfd-nextcloud mbfd-nextcloud-cron whisper-stt piper-tts doc-generator; do
    apply_container "${container}" "${application_adjustment}"
done

printf '\nRuntime-only settings; the timer reapplies them after service/container restarts.\n'
printf 'Rollback: sudo %s reset\n' "$0"
