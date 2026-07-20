#!/usr/bin/env bash
set -Eeuo pipefail

readonly STATUS_FILE=/var/lib/mbfd-origin-monitor/status.json
readonly EVENT_FILE=/var/log/mbfd-origin-monitor/events.jsonl
readonly MAX_AGE_SECONDS=300

if [[ ! -f "${STATUS_FILE}" ]]; then
    echo '{"monitor_state":"Unknown","reason":"status_file_missing"}'
    exit 2
fi

age=$(( $(date +%s) - $(stat -c %Y "${STATUS_FILE}") ))
if (( age > MAX_AGE_SECONDS )); then
    jq --argjson age "${age}" '.monitor_state="Failed" | .reason="status_stale" | .age_seconds=$age' "${STATUS_FILE}"
    exit 2
fi

jq --argjson age "${age}" '.age_seconds=$age' "${STATUS_FILE}"
if [[ -f "${EVENT_FILE}" ]]; then
    echo "latest_events:"
    tail -n 2 "${EVENT_FILE}" | jq -c '{affected_service,current_state,severity,collection_time,notification_disposition,recovery_notification}'
fi
