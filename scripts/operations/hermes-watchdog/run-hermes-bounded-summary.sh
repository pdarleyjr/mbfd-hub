#!/usr/bin/env bash
# Bounded, tool-free scheduled interpretation of deterministic MBFD evidence.
set -Eeuo pipefail
umask 077

export HOME=/var/lib/mbfd-aiops
export HERMES_HOME=/opt/mbfd/hermes/home
export PATH=/var/lib/mbfd-aiops/.local/bin:/opt/mbfd/hermes/home/node/bin:/opt/mbfd/hermes/home/bin:/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin

OUT_DIR=/opt/mbfd/hermes/reports
STATE_DIR=/opt/mbfd/hermes/status
LOCK_FILE=/run/lock/mbfd-hermes-bounded-summary.lock
GATEWAY_URL=http://127.0.0.1:11440/api/chat
CAPABILITY=mbfd-ops-summary
MAX_EVIDENCE_BYTES=14000
MAX_MODEL_CALLS=2
REQUEST_TIMEOUT_SECONDS=36
RETRY_TIMEOUT_SECONDS=6

mkdir -p "$OUT_DIR" "$STATE_DIR"
exec 9>"$LOCK_FILE"
if ! flock -n 9; then
  printf '{"detector":"scheduled-summary","severity":"P3","llm_invoked":false,"outcome":"skipped_concurrency_lock","fallback_status":"not_required","notification_result":"not_required"}\n'
  exit 0
fi

TS=$(date +%Y%m%d-%H%M%S)
REQUEST_ID="watchdog-summary-$TS-$$"
RAW="$OUT_DIR/mbfd-hermes-raw-report-$TS.txt"
EVIDENCE="$OUT_DIR/mbfd-hermes-evidence-$TS.txt"
EVIDENCE_FULL="$OUT_DIR/.evidence-full-$TS.txt"
REQUEST="$OUT_DIR/.request-$TS.json"
RESPONSE="$OUT_DIR/.response-$TS.json"
RESPONSE_HEADERS="$OUT_DIR/.response-headers-$TS.txt"
SUMMARY="$OUT_DIR/mbfd-hermes-summary-$TS.txt"
FALLBACK="$OUT_DIR/mbfd-hermes-fallback-$TS.txt"
META="$OUT_DIR/mbfd-hermes-audit-$TS.json"
DELIVERY_LOG="$OUT_DIR/telegram-delivery-$TS.log"

cleanup() {
  rm -f "$REQUEST" "$RESPONSE" "$RESPONSE_HEADERS" "$EVIDENCE_FULL"
}
trap cleanup EXIT

report_rc=0
set +e
timeout 8s sudo -n -u mbfd /opt/mbfd/runbooks/mbfd-daily-infra-report.sh >"$RAW" 2>&1
report_rc=$?
set -e
if (( report_rc != 0 )); then
  printf '\nDETERMINISTIC_REPORT_STATUS=partial_or_timed_out exit=%s\n' "$report_rc" >>"$RAW"
fi

{
  printf 'EVIDENCE_GENERATED=%s\n' "$(date -Is)"
  printf 'REQUEST_ID=%s\n' "$REQUEST_ID"
  printf 'DETERMINISTIC_REPORT_EXIT=%s\n' "$report_rc"
  printf 'EOC_SOURCE_CHECK_LATEST_BEGIN\n'
  if [[ -r /var/lib/mbfd-watchdog/source-check-latest.json ]]; then
    jq '{timestamp,detector,severity,outcome,counts,source_count,robots,sources}' /var/lib/mbfd-watchdog/source-check-latest.json 2>/dev/null || true
  else
    printf 'unavailable\n'
  fi
  printf 'EOC_SOURCE_CHECK_LATEST_END\n'
  printf 'EOC_SCRAPE_AUDIT_LATEST_BEGIN\n'
  if [[ -r /var/lib/mbfd-watchdog/scrape-audit-latest.json ]]; then
    jq '{timestamp,detector,severity,outcome,counts,source_count,robots,sources}' /var/lib/mbfd-watchdog/scrape-audit-latest.json 2>/dev/null || true
  else
    printf 'unavailable\n'
  fi
  printf 'EOC_SCRAPE_AUDIT_LATEST_END\n'
  printf 'INFRA_REPORT_TAIL_BEGIN\n'
  tail -c 20000 "$RAW"
  printf '\nINFRA_REPORT_TAIL_END\n'
} >"$EVIDENCE_FULL"
head -c "$MAX_EVIDENCE_BYTES" "$EVIDENCE_FULL" >"$EVIDENCE"

jq -n --rawfile evidence "$EVIDENCE" --arg model "$CAPABILITY" --arg request_id "$REQUEST_ID" '{
  model: $model,
  stream: false,
  think: false,
  messages: [
    {role:"system",content:"You are a bounded MBFD operations summarizer. Use only the supplied deterministic evidence. Do not call tools, browse, delegate, or infer missing facts. Keep output under 1200 words. Label P0/P1/P2/P3, cite local evidence references, and explicitly state unavailable evidence."},
    {role:"user",content:("Request ID: " + $request_id + "\nSummarize this evidence with sections Overall Status, Immediate P0/P1, Deferred P2, Trends P3, EOC Freshness, Services and Containers, Capacity Memory OOM GPU, Backups, Cloudflare HLS Cameras, and Manual Actions.\n\n" + $evidence)}
  ],
  options: {num_predict:768,temperature:0.1}
}' >"$REQUEST"

attempts=0
llm_outcome=failed
gateway_auth=unavailable
gateway_token=
credential_file="${CREDENTIALS_DIRECTORY:-}/ai-gateway-api-key"
if [[ -n "${CREDENTIALS_DIRECTORY:-}" && -r "$credential_file" ]]; then
  gateway_token=$(tr -d '\r\n' <"$credential_file")
  if [[ "$gateway_token" =~ ^[A-Za-z0-9_-]{32,256}$ ]]; then
    gateway_auth=available
  fi
fi

while [[ "$gateway_auth" == available ]] && (( attempts < MAX_MODEL_CALLS )); do
  attempts=$((attempts + 1))
  request_timeout=$REQUEST_TIMEOUT_SECONDS
  if (( attempts > 1 )); then
    request_timeout=$RETRY_TIMEOUT_SECONDS
  fi
  if curl --silent --show-error --fail --connect-timeout 3 --max-time "$request_timeout" \
      --config <(printf 'header = "Authorization: Bearer %s"\n' "$gateway_token") \
      --header 'Content-Type: application/json' \
      --header "X-MBFD-Capability: $CAPABILITY" \
      --header "X-Request-ID: $REQUEST_ID" \
      --dump-header "$RESPONSE_HEADERS" \
      --data-binary "@$REQUEST" "$GATEWAY_URL" >"$RESPONSE" \
      && tr -d '\r' <"$RESPONSE_HEADERS" | grep -Fxiq "X-Request-ID: $REQUEST_ID" \
      && jq -e '.message.content | type == "string" and length > 0' "$RESPONSE" >/dev/null 2>&1; then
    jq -r '.message.content' "$RESPONSE" >"$SUMMARY"
    llm_outcome=completed
    break
  fi
done
unset gateway_token

fallback_status=not_required
deliver_file="$SUMMARY"
if [[ "$llm_outcome" != completed ]]; then
  fallback_status=deterministic_report_used
  {
    printf 'MBFD deterministic watchdog report (AI interpretation unavailable)\n'
    printf 'Request ID: %s\n' "$REQUEST_ID"
    printf 'Model attempts: %s of %s\n' "$attempts" "$MAX_MODEL_CALLS"
    printf 'Evidence reference: %s\n\n' "$EVIDENCE"
    cat "$EVIDENCE"
  } >"$FALLBACK"
  deliver_file="$FALLBACK"
fi

notification_result=not_configured
if grep -q '^TELEGRAM_BOT_TOKEN=' "$HERMES_HOME/.env" 2>/dev/null && grep -q '^TELEGRAM_HOME_CHANNEL=' "$HERMES_HOME/.env" 2>/dev/null; then
  if timeout 8s hermes send --to telegram --subject '[MBFD Hermes Scheduled Summary]' --file "$deliver_file" >"$DELIVERY_LOG" 2>&1; then
    notification_result=sent
  else
    notification_result=failed
  fi
fi

jq -n \
  --arg timestamp "$(date -Is)" \
  --arg request_id "$REQUEST_ID" \
  --arg capability "$CAPABILITY" \
  --arg gateway_auth "$gateway_auth" \
  --arg evidence_reference "$EVIDENCE" \
  --arg outcome "$llm_outcome" \
  --arg fallback_status "$fallback_status" \
  --arg notification_result "$notification_result" \
  --argjson model_calls "$attempts" \
  --argjson evidence_bytes "$(wc -c <"$EVIDENCE")" \
  '{schema_version:1,timestamp:$timestamp,detector:"scheduled-summary",severity:"P2",evidence_reference:$evidence_reference,llm_invoked:($model_calls > 0),request_id:$request_id,outcome:$outcome,fallback_status:$fallback_status,notification_result:$notification_result,model_calls:$model_calls,max_model_calls:2,evidence_bytes:$evidence_bytes,max_evidence_bytes:14000,model:$capability,capability:$capability,gateway_auth:$gateway_auth,tools_exposed:[]}' >"$META"

cp "$META" "$STATE_DIR/last-bounded-summary.json"
printf 'audit=%s\nresult=%s\n' "$META" "$deliver_file"

# Bound archives without touching unrelated Hermes artifacts.
find "$OUT_DIR" -maxdepth 1 -type f \( -name 'mbfd-hermes-raw-report-*.txt' -o -name 'mbfd-hermes-evidence-*.txt' -o -name 'mbfd-hermes-summary-*.txt' -o -name 'mbfd-hermes-fallback-*.txt' -o -name 'mbfd-hermes-audit-*.json' -o -name 'telegram-delivery-*.log' \) -printf '%T@ %p\n' \
  | sort -nr | tail -n +181 | cut -d' ' -f2- | xargs -r rm -f
