#!/usr/bin/env bash
set -euo pipefail

container_name=${MEDIA_CONTROL_CONTAINER:-media-control}
db_dir=${MEDIA_CONTROL_NVME_DB_DIR:-/var/lib/mbfd/media-control-db}
db_path="$db_dir/remote_display.db"
health_url=${MEDIA_CONTROL_HEALTH_URL:-http://127.0.0.1:8096/api/status}
expected_mount="bind|$db_dir"

actual_mount=$(
  docker inspect -f \
    '{{range .Mounts}}{{if eq .Destination "/app/data/db"}}{{.Type}}|{{.Source}}{{end}}{{end}}' \
    "$container_name"
)
test "$actual_mount" = "$expected_mount"
test "$(docker inspect -f '{{.State.Health.Status}}' "$container_name")" = healthy
curl -fsS --max-time 3 "$health_url" >/dev/null

sudo test -f "$db_path"
test "$(sudo sqlite3 "$db_path" 'PRAGMA quick_check;')" = ok
test "$(sudo sqlite3 "$db_path" \
  "SELECT COUNT(*) FROM display_states WHERE target_type='display';")" -gt 0
test "$(sudo sqlite3 "$db_path" 'SELECT COUNT(*) FROM video_walls;')" -gt 0

echo "Media Control NVMe database cutover verified: $actual_mount"
