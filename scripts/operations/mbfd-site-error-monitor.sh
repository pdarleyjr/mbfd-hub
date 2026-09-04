#!/usr/bin/env bash
# Stateful main/admin error monitor with deduplication and recovery notices.
set -Eeuo pipefail

readonly MODE=${1:-run}
readonly BASE=/opt/mbfd/site-monitor
readonly STATE=${BASE}/state.env
readonly REPORT_DIR=${BASE}/reports
readonly LARAVEL_LOG=/opt/mbfd/mbfd-hub/storage/logs/laravel.log
readonly LARAVEL_EVENT_FILTER=/opt/mbfd/runbooks/filter_laravel_monitor_events.py
readonly ALERT_COOLDOWN_SECONDS=900
readonly HERMES_ENV="HOME=/var/lib/mbfd-aiops HERMES_HOME=/opt/mbfd/hermes/home PATH=/var/lib/mbfd-aiops/.local/bin:/opt/mbfd/hermes/home/node/bin:/opt/mbfd/hermes/home/bin:/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin"

mkdir -p "${REPORT_DIR}"

redact() {
    sed -E \
        -e 's/--token [A-Za-z0-9._=+\/-]+/--token [REDACTED_TUNNEL_TOKEN]/g' \
        -e 's/(token=)[A-Za-z0-9._=+\/-]+/\1[REDACTED_TOKEN]/g' \
        -e 's/(access_token=)[A-Za-z0-9._=+\/-]+/\1[REDACTED_TOKEN]/g' \
        -e 's/(kid=)[A-Za-z0-9._=+\/-]+/\1[REDACTED]/g' \
        -e 's/(meta=)[A-Za-z0-9._=+\/-]+/\1[REDACTED]/g' \
        -e 's/(cfut_|cfat_|ghp_|github_pat_)[A-Za-z0-9_\-]+/[REDACTED_TOKEN]/g' \
        -e 's/eyJ[A-Za-z0-9._\-]+/[REDACTED_JWT]/g' \
        -e 's/(password|secret|authorization|bearer|api[_-]?key)([ =:]+)[^[:space:],}]+/\1\2[REDACTED]/Ig'
}

now_epoch() { date +%s; }

init_state() {
    local offset=0
    [[ -f "${LARAVEL_LOG}" ]] && offset=$(stat -c %s "${LARAVEL_LOG}")
    write_state "Healthy" "" 0 0 "" "${offset}"
}

load_state() {
    [[ -f "${STATE}" ]] || init_state
    # shellcheck disable=SC1090
    source "${STATE}"
    LAST_EPOCH=${LAST_EPOCH:-$(now_epoch)}
    LARAVEL_OFFSET=${LARAVEL_OFFSET:-0}
    CURRENT_STATE=${CURRENT_STATE:-Unknown}
    LAST_ALERT_KEY=${LAST_ALERT_KEY:-}
    LAST_ALERT_EPOCH=${LAST_ALERT_EPOCH:-0}
    INCIDENT_START_EPOCH=${INCIDENT_START_EPOCH:-0}
    CORRELATION_ID=${CORRELATION_ID:-}
}

write_state() {
    local current_state=$1 alert_key=$2 alert_epoch=$3 incident_epoch=$4 correlation_id=$5 laravel_offset=$6
    local temporary
    temporary=$(mktemp "${BASE}/.state.env.XXXXXX")
    cat > "${temporary}" <<STATE
LAST_EPOCH=$(now_epoch)
LARAVEL_OFFSET=${laravel_offset}
CURRENT_STATE=${current_state}
LAST_ALERT_KEY=${alert_key}
LAST_ALERT_EPOCH=${alert_epoch}
INCIDENT_START_EPOCH=${incident_epoch}
CORRELATION_ID=${correlation_id}
STATE
    chmod 0640 "${temporary}"
    mv -f "${temporary}" "${STATE}"
}

probe_sites() {
    python3 - <<'PY'
import urllib.error
import urllib.request

checks = [
    ('main', 'https://mbfdhub.com/', {200}),
    ('www', 'https://www.mbfdhub.com/', {200}),
    ('admin', 'https://www.mbfdhub.com/admin', {302}),
    ('admin-login', 'https://www.mbfdhub.com/admin/login', {200}),
]

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, request, response, code, message, headers, new_url):
        return None

opener = urllib.request.build_opener(NoRedirect)
for name, url, expected in checks:
    request = urllib.request.Request(url, method='HEAD', headers={'User-Agent': 'MBFD-Site-Monitor/2.0'})
    try:
        response = opener.open(request, timeout=12)
        code = response.status
        location = response.headers.get('location', '')
    except urllib.error.HTTPError as error:
        code = error.code
        location = error.headers.get('location', '')
    except Exception as error:
        print(f'ISSUE http_probe name={name} error={type(error).__name__}')
        continue
    if code not in expected:
        print(f'ISSUE http_probe name={name} expected={sorted(expected)} actual={code}')
    else:
        print(f'OK http_probe name={name} actual={code} location={location}')
PY
}

probe_runtime() {
    local container details status health
    for container in mbfd-hub-laravel mbfd-hub-pgsql mbfd-hub-redis mbfd-livekit-server; do
        if ! details=$(docker inspect --format '{{.State.Status}}|{{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}|{{.State.StartedAt}}|{{.RestartCount}}' "${container}" 2>/dev/null); then
            echo "ISSUE runtime_probe container=${container} state=unavailable"
            continue
        fi
        IFS='|' read -r status health started_at restart_count <<< "${details}"
        if [[ "${status}" == "running" && ( "${health}" == "healthy" || "${health}" == "none" ) ]]; then
            echo "OK runtime_probe container=${container} status=${status} health=${health} started_at=${started_at} restarts=${restart_count}"
        else
            echo "ISSUE runtime_probe container=${container} status=${status} health=${health} started_at=${started_at} restarts=${restart_count}"
        fi
    done

    if [[ "$(systemctl is-active cloudflared.service 2>/dev/null || true)" == "active" ]]; then
        echo "OK runtime_probe service=cloudflared status=active"
    else
        echo "ISSUE runtime_probe service=cloudflared status=inactive_or_unknown"
    fi
}

collect_events() {
    local output=$1 since="@${LAST_EPOCH}" current_offset=0
    {
        echo "MBFD site/admin monitor event collection"
        echo "event_occurrence_time=$(date -Is)"
        echo "collection_time=$(date -Is)"
        echo "report_generation_time=$(date -Is)"
        echo "time_zone=$(date +%:z)"
        echo "data_source=synthetic_http,docker_runtime,systemd,cloudflared_journal,laravel_log"
        echo
        echo "===== HTTP PROBES ====="
        probe_sites
        echo
        echo "===== RUNTIME PROBES ====="
        probe_runtime
        echo
        echo "===== ACTIONABLE CLOUDFLARED ERRORS FOR MAIN/ADMIN ====="
        journalctl -u cloudflared.service --since "${since}" --no-pager 2>/dev/null \
            | grep -Ei ' ERR |unable to reach origin|connection refused|connection reset by peer|TLS handshake|HTTP 50[234]' \
            | grep -Ei 'dest=https://(www\.)?mbfdhub\.com(/|[[:space:]])|originService=http://localhost:(8080|8090)([[:space:]]|$)' \
            | grep -Eiv 'context canceled|request ended abruptly|canceled by remote|client disconnected' \
            | redact || true
        echo
        echo "===== NEW LARAVEL ERROR LOG LINES ====="
        if [[ -f "${LARAVEL_LOG}" ]]; then
            current_offset=$(stat -c %s "${LARAVEL_LOG}")
            if (( current_offset < LARAVEL_OFFSET )); then LARAVEL_OFFSET=0; fi
            if [[ -r "${LARAVEL_EVENT_FILTER}" ]]; then
                tail -c +$((LARAVEL_OFFSET + 1)) "${LARAVEL_LOG}" 2>/dev/null \
                    | python3 "${LARAVEL_EVENT_FILTER}" \
                    | tail -400 \
                    | redact || true
            else
                echo "ISSUE laravel_event_filter source_unavailable"
            fi
        else
            echo "UNKNOWN laravel_log source_unavailable"
        fi
    } > "${output}"
}

has_issue() {
    grep -Ev '^(=====|MBFD site/admin monitor|event_occurrence_time=|collection_time=|report_generation_time=|time_zone=|data_source=|$)' "$1" \
        | grep -Eiv 'context canceled|request ended abruptly|canceled by remote|client disconnected' \
        | grep -Eq '(^ISSUE |\bERR\b|\bERROR\b|CRITICAL|ALERT|EMERGENCY|Exception|TypeError|SQLSTATE|Stack trace|Unable to reach|connection refused|HTTP 50[234])'
}

event_key() {
    sed -E \
        -e 's/[0-9]{4}-[0-9]{2}-[0-9]{2}[T ][0-9:.+-]+/[TIMESTAMP]/g' \
        -e 's/connIndex=[0-9]+/connIndex=[N]/g' \
        -e 's/\[[0-9]+\]/[N]/g' \
        "$1" \
        | grep -Ev '^(=====|MBFD site/admin monitor|event_occurrence_time=|collection_time=|report_generation_time=|time_zone=|data_source=|$)' \
        | grep -E '(^ISSUE |\bERR\b|\bERROR\b|CRITICAL|ALERT|EMERGENCY|Exception|TypeError|SQLSTATE|Stack trace|Unable to reach|connection refused|HTTP 50[234])' \
        | sha256sum \
        | awk '{print $1}'
}

severity_for() {
    if grep -Eq '^ISSUE http_probe|^ISSUE runtime_probe (container=mbfd-hub-(laravel|pgsql|redis)|service=cloudflared)|Unable to reach|connection refused|connection reset by peer|HTTP 50[234]' "$1"; then
        echo high
    else
        echo warning
    fi
}

observed_state_for() {
    if [[ "$(severity_for "$1")" == "high" ]]; then
        echo "Degraded"
    else
        echo "ApplicationEvent"
    fi
}

diagnose_and_notify() {
    local event_file=$1 timestamp=$2 severity=$3 correlation_id=$4 observed_state=$5
    local assessment=${REPORT_DIR}/assessment-${timestamp}.txt
    local audit=${REPORT_DIR}/audit-${timestamp}.json
    local policy_severity=P2 notification_result=failed
    [[ "${severity}" == "high" ]] && policy_severity=P1
    {
        printf 'MBFD deterministic site/admin alert\n'
        printf 'Severity: %s\n' "${policy_severity}"
        printf 'Current state: %s\n' "${observed_state}"
        printf 'Historical state: %s\n' "${CURRENT_STATE}"
        printf 'Affected service: MBFD Hub main/admin\n'
        printf 'Correlation ID: %s\n' "${correlation_id}"
        printf 'Incident identity: site-admin:%s\n' "${correlation_id}"
        printf 'Evidence reference: %s\n' "${event_file}"
        printf 'LLM invoked: false\n'
        printf 'Fallback: deterministic evidence is authoritative\n'
        printf '\nNEW EVENTS:\n'
        tail -c 30000 "${event_file}"
    } > "${assessment}"
    chgrp mbfd-aiops "${assessment}"
    chmod 0640 "${assessment}"
    if timeout 15 sudo -u mbfd-aiops env ${HERMES_ENV} ASSESSMENT_FILE="${assessment}" bash -lc \
        'cd /opt/mbfd/hermes && hermes send --to telegram --subject "[MBFD Site/Admin Alert]" --file "$ASSESSMENT_FILE"' \
        >> "${REPORT_DIR}/telegram-${timestamp}.log" 2>&1; then
        notification_result=sent
    fi
    chmod 0640 "${REPORT_DIR}/telegram-${timestamp}.log" 2>/dev/null || true
    jq -n --arg timestamp "$(date -Is)" --arg severity "${policy_severity}" \
        --arg evidence_reference "${event_file}" --arg correlation_id "${correlation_id}" \
        --arg notification_result "${notification_result}" \
        '{schema_version:1,timestamp:$timestamp,detector:"site-error-monitor",severity:$severity,evidence_reference:$evidence_reference,llm_invoked:false,request_id:null,outcome:"deterministic_alert",fallback_status:"not_required",notification_result:$notification_result,correlation_id:$correlation_id}' \
        > "${audit}"
    chmod 0640 "${audit}"
}

notify_recovery() {
    local timestamp=$1 correlation_id=$2 assessment=${REPORT_DIR}/recovery-${timestamp}.txt recovery_state="recovered"
    if [[ "${CURRENT_STATE}" == "ApplicationEvent" ]]; then
        recovery_state="application event cleared; main/admin availability remained healthy"
    fi
    cat > "${assessment}" <<RECOVERY
Severity: informational
Affected service: MBFD Hub main/admin
Current state: Healthy
Historical state: ${CURRENT_STATE}
Recovery time: $(date -Is)
Correlation ID: ${correlation_id}
User impact: No current impact; all synthetic probes pass.
Evidence: main=200, www=200, admin=302, admin-login=200
Recovery state: ${recovery_state}
RECOVERY
    chmod 0640 "${assessment}"
    sudo -u mbfd-aiops env ${HERMES_ENV} ASSESSMENT_FILE="${assessment}" bash -lc \
        'cd /opt/mbfd/hermes && hermes send --to telegram --subject "[MBFD Site/Admin Recovery]" --file "$ASSESSMENT_FILE"' \
        >> "${REPORT_DIR}/telegram-recovery-${timestamp}.log" 2>&1 || true
}

run_monitor() {
    load_state
    local timestamp event_file current_offset key severity event_state now correlation incident_epoch
    timestamp=$(date +%Y%m%d-%H%M%S)
    event_file=${REPORT_DIR}/event-${timestamp}.txt
    collect_events "${event_file}"
    current_offset=0
    [[ -f "${LARAVEL_LOG}" ]] && current_offset=$(stat -c %s "${LARAVEL_LOG}")
    now=$(now_epoch)

    if has_issue "${event_file}"; then
        key=$(event_key "${event_file}")
        severity=$(severity_for "${event_file}")
        event_state=$(observed_state_for "${event_file}")
        correlation=${CORRELATION_ID}
        incident_epoch=${INCIDENT_START_EPOCH}
        if [[ "${CURRENT_STATE}" != "${event_state}" || -z "${correlation}" ]]; then
            correlation=$(cat /proc/sys/kernel/random/uuid)
            incident_epoch=${now}
        fi
        if [[ "${key}" == "${LAST_ALERT_KEY}" && $((now - LAST_ALERT_EPOCH)) -lt ${ALERT_COOLDOWN_SECONDS} ]]; then
            echo "issues_detected=1 notification=deduplicated key=${key} event_file=${event_file}"
            write_state "${event_state}" "${key}" "${LAST_ALERT_EPOCH}" "${incident_epoch}" "${correlation}" "${current_offset}"
        else
            diagnose_and_notify "${event_file}" "${timestamp}" "${severity}" "${correlation}" "${event_state}"
            echo "issues_detected=1 notification=sent severity=${severity} state=${event_state} correlation_id=${correlation} event_file=${event_file}"
            write_state "${event_state}" "${key}" "${now}" "${incident_epoch}" "${correlation}" "${current_offset}"
        fi
    else
        rm -f "${event_file}"
        if [[ "${CURRENT_STATE}" == "Degraded" || "${CURRENT_STATE}" == "ApplicationEvent" ]]; then
            notify_recovery "${timestamp}" "${CORRELATION_ID}"
            echo "issues_detected=0 recovery_notification=sent correlation_id=${CORRELATION_ID}"
        else
            echo "issues_detected=0 current_state=Healthy"
        fi
        write_state "Healthy" "" 0 0 "" "${current_offset}"
    fi

    find "${REPORT_DIR}" -maxdepth 1 -type f -printf '%T@ %p\n' \
        | sort -nr | tail -n +181 | cut -d' ' -f2- | xargs -r rm -f
}

case "${MODE}" in
    init)
        init_state
        echo "site_monitor_state=initialized"
        ;;
    status)
        load_state
        cat "${STATE}"
        ;;
    test-benign)
        test_file=$(mktemp)
        trap 'rm -f "${test_file}"' EXIT
        echo 'ERR Request failed error="Incoming request ended abruptly: context canceled" dest=https://cameras.mbfdhub.com/hls/anpviz-main/index.m3u8' > "${test_file}"
        if has_issue "${test_file}"; then
            echo "benign_classifier=fail" >&2
            exit 1
        fi
        echo "benign_classifier=pass"
        ;;
    test-empty)
        test_file=$(mktemp)
        trap 'rm -f "${test_file}"' EXIT
        cat > "${test_file}" <<'TEST'
===== NEW LARAVEL ERROR LOG LINES =====
===== ACTIONABLE CLOUDFLARED ERRORS FOR MAIN/ADMIN =====
TEST
        if has_issue "${test_file}"; then
            echo "empty_classifier=fail" >&2
            exit 1
        fi
        echo "empty_classifier=pass"
        ;;
    run)
        run_monitor
        ;;
    *)
        echo "Usage: $0 init|run|status|test-benign|test-empty" >&2
        exit 64
        ;;
esac
