#!/bin/bash
# /opt/mbfd/alerts.sh - lightweight health/security alerting for MBFD Hub.
# Detects: disk>85%, pg down, app down, cloudflared down, backup faults,
# unhealthy/restarting containers, queue failed jobs, ssh auth-failure spikes.
# Delivery: appends to /var/log/mbfd-alerts.log and POSTs each alert to the URL
# in /opt/mbfd/secrets/alert-webhook (if present). Backup faults also reach
# active Hub administrators through the existing inbox and web push channels.
LOG=/var/log/mbfd-alerts.log
WEBHOOK_FILE=/opt/mbfd/secrets/alert-webhook
BACKUP_ALERT_STATE=/opt/mbfd/secrets/restic-alert-last-event
BACKUP_STATUS=/opt/mbfd/secrets/restic-last-status
CHECK_STATUS=/opt/mbfd/secrets/restic-check-last-status
ALERTS=()
add(){ ALERTS+=("$1"); }

record_backup_alert() {
  local temp
  temp=$(mktemp "${BACKUP_ALERT_STATE}.XXXXXX") || return 1
  if ! printf '%s\n' "$backup_event" > "$temp" ||
     ! chmod 600 "$temp" || ! mv -f -- "$temp" "$BACKUP_ALERT_STATE"; then
    rm -f -- "$temp"
    return 1
  fi
}

# disk
while read -r fs use mnt; do
  u=${use%\%}
  [ "$u" -ge 85 ] 2>/dev/null && add "DISK ${mnt} at ${use}"
done < <(df -h --output=source,pcent,target / /mnt/mbfd-storage 2>/dev/null | tail -n +2 | awk '{print $1, $2, $3}')

# postgres (hub)
docker exec mbfd-hub-pgsql pg_isready -U mbfd_user >/dev/null 2>&1 || add "DB mbfd-hub-pgsql NOT ready"

# laravel app
code=$(curl -s -m8 -o /dev/null -w '%{http_code}' http://localhost:8080/ 2>/dev/null)
[ "$code" = "200" ] || [ "$code" = "302" ] || add "APP mbfd-hub-laravel http=$code"

# cloudflared tunnel
systemctl is-active --quiet cloudflared || add "TUNNEL cloudflared not active"

# Read the scheduled check result; do not start another Restic operation here.
backup_kind=$(/opt/mbfd/restic-check.sh --status 2>/dev/null || true)
backup_event=
case "$backup_kind" in
  ok) backup_kind=; rm -f -- "$BACKUP_ALERT_STATE" ;;
  backup_failure|backup_stale) backup_status_file=$BACKUP_STATUS ;;
  repository_inaccessible|restore_check_failure) backup_status_file=$CHECK_STATUS ;;
  *) backup_kind=restore_check_failure; backup_status_file=$CHECK_STATUS ;;
esac
if [ -n "$backup_kind" ]; then
  backup_event="$backup_kind:$(stat -c %Y "$backup_status_file" 2>/dev/null || echo missing)"
  if [ "$(cat "$BACKUP_ALERT_STATE" 2>/dev/null)" != "$backup_event" ]; then
    add "BACKUP $backup_kind"
  fi
fi

# unhealthy / restarting containers
bad=$(docker ps --format '{{.Names}} {{.Status}}' | grep -iE 'unhealthy|Restarting' | awk '{print $1}' | tr '\n' ',' )
[ -n "$bad" ] && add "CONTAINERS unhealthy/restarting: ${bad%,}"

# laravel failed queue jobs (if table exists)
fj=$(docker exec mbfd-hub-pgsql psql -U mbfd_user -d mbfd_hub -tAc "SELECT count(*) FROM failed_jobs;" 2>/dev/null)
[ -n "$fj" ] && [ "$fj" -gt 25 ] 2>/dev/null && add "QUEUE failed_jobs=$fj"

# ssh auth-failure spike (last 15 min)
af=$(journalctl -u ssh --since "15 min ago" 2>/dev/null | grep -c "Failed password")
[ -n "$af" ] && [ "$af" -gt 20 ] 2>/dev/null && add "SSH auth-failure spike: $af in 15m"

if [ ${#ALERTS[@]} -gt 0 ] || [ -n "$backup_kind" ]; then
  ts=$(date -Is)
  for a in "${ALERTS[@]}"; do echo "[$ts] ALERT: $a" >> "$LOG"; done
  if [ -n "$backup_event" ] && [ "$(cat "$BACKUP_ALERT_STATE" 2>/dev/null)" != "$backup_event" ]; then
    if docker exec -u sail mbfd-hub-laravel php /var/www/html/artisan mbfd:backup-alert "$backup_kind" >/dev/null 2>&1; then
      record_backup_alert || echo "[$ts] WARN: backup alert state could not be recorded" >> "$LOG"
    else
      echo "[$ts] WARN: backup alert delivery failed" >> "$LOG"
    fi
  fi
  if [ ${#ALERTS[@]} -gt 0 ] && [ -s "$WEBHOOK_FILE" ]; then
    url=$(head -1 "$WEBHOOK_FILE")
    msg="MBFD Hub alerts ($ts): $(printf '%s; ' "${ALERTS[@]}")"
    curl -s -m10 -X POST -H 'content-type: application/json' \
      --data "$(printf '{"text":%s}' "$(printf '%s' "$msg" | python3 -c 'import json,sys;print(json.dumps(sys.stdin.read()))')")" \
      "$url" >/dev/null 2>&1
  fi
  exit 1
fi
echo "[$(date -Is)] OK: all checks passed" >> "$LOG"
exit 0
