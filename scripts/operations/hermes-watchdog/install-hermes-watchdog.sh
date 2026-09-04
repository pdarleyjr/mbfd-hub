#!/usr/bin/env bash
# Install or verify the production Hermes watchdog artifacts managed by MBFD Hub.
set -Eeuo pipefail
umask 027

readonly MODE=${1:-}
readonly SOURCE_DIR=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
readonly OPERATIONS_DIR=$(cd -- "${SOURCE_DIR}/.." && pwd)
readonly SYSTEMD_SOURCE_DIR=${SOURCE_DIR}/systemd
readonly JOBS_FILE=/opt/mbfd/hermes/home/cron/jobs.json
readonly BACKUP_ROOT=/mnt/mbfd-storage/backups/on-demand
readonly HERMES_JOB_NAMES='["eoc-source-check","eoc-scrape-audit","eoc-public-brief"]'
readonly MANAGED_TIMERS=(
  mbfd-eoc-source-check.timer
  mbfd-eoc-scrape-audit.timer
  mbfd-hermes-bounded-summary.timer
)
readonly UNIT_FILES=(
  mbfd-eoc-source-check.service
  mbfd-eoc-source-check.timer
  mbfd-eoc-scrape-audit.service
  mbfd-eoc-scrape-audit.timer
  mbfd-hermes-bounded-summary.service
  mbfd-hermes-bounded-summary.timer
)

usage() {
  echo "Usage: $0 --apply|--check" >&2
  exit 2
}

require_root() {
  if (( EUID != 0 )); then
    echo 'This installer must run as root.' >&2
    exit 1
  fi
}

validate_source() {
  bash -n "${OPERATIONS_DIR}/mbfd-site-error-monitor.sh"
  bash -n "${SOURCE_DIR}/run-hermes-bounded-summary.sh"
  python3 - "${SOURCE_DIR}/mbfd-eoc-watchdog.py" <<'PY'
from pathlib import Path
import sys

source = Path(sys.argv[1])
compile(source.read_bytes(), str(source), "exec")
PY
  jq -e '.schema_version == 1' "${SOURCE_DIR}/managed-config.json" >/dev/null
  if grep -R -E -n 'timeout[[:space:]]+420[[:space:]]+hermes|hermes[[:space:]].*--provider.*-z' \
      "${OPERATIONS_DIR}/mbfd-site-error-monitor.sh" "${SOURCE_DIR}"; then
    echo 'Obsolete full-agent watchdog invocation remains in deployable source.' >&2
    exit 1
  fi
}

compare_managed_files() {
  cmp -s "${OPERATIONS_DIR}/mbfd-site-error-monitor.sh" /opt/mbfd/runbooks/mbfd-site-error-monitor.sh
  cmp -s "${SOURCE_DIR}/mbfd-eoc-watchdog.py" /opt/mbfd/hermes/mbfd-eoc-watchdog.py
  cmp -s "${SOURCE_DIR}/run-hermes-bounded-summary.sh" /opt/mbfd/hermes/run-hermes-bounded-summary.sh
  local unit
  for unit in "${UNIT_FILES[@]}"; do
    cmp -s "${SYSTEMD_SOURCE_DIR}/${unit}" "/etc/systemd/system/${unit}"
  done
}

verify_runtime_policy() {
  local timer
  for timer in "${MANAGED_TIMERS[@]}"; do
    test "$(systemctl is-enabled "${timer}")" = enabled
    test "$(systemctl is-active "${timer}")" = active
  done
  test "$(systemctl is-enabled mbfd-hermes-daily-summary.timer 2>/dev/null || true)" != enabled
  if [[ -f "${JOBS_FILE}" ]]; then
    jq -e --argjson names "${HERMES_JOB_NAMES}" \
      '[.jobs[]? | select((.name as $name | $names | index($name)) and .enabled == true)] | length == 0' \
      "${JOBS_FILE}" >/dev/null
  fi
}

backup_if_present() {
  local path=$1 backup_dir=$2
  if [[ -e "${path}" ]]; then
    install -D -p "${path}" "${backup_dir}${path}"
  fi
}

apply_managed_files() {
  local timestamp backup_dir unit temporary_jobs
  timestamp=$(date -u +%Y%m%dT%H%M%SZ)
  backup_dir=${BACKUP_ROOT}/hermes-watchdog-source-install-${timestamp}
  install -d -m 0750 "${backup_dir}"

  backup_if_present /opt/mbfd/runbooks/mbfd-site-error-monitor.sh "${backup_dir}"
  backup_if_present /opt/mbfd/hermes/mbfd-eoc-watchdog.py "${backup_dir}"
  backup_if_present /opt/mbfd/hermes/run-hermes-bounded-summary.sh "${backup_dir}"
  for unit in "${UNIT_FILES[@]}"; do
    backup_if_present "/etc/systemd/system/${unit}" "${backup_dir}"
  done
  backup_if_present "${JOBS_FILE}" "${backup_dir}"

  install -o root -g root -m 0755 "${OPERATIONS_DIR}/mbfd-site-error-monitor.sh" /opt/mbfd/runbooks/mbfd-site-error-monitor.sh
  install -o root -g mbfd-aiops -m 0750 "${SOURCE_DIR}/mbfd-eoc-watchdog.py" /opt/mbfd/hermes/mbfd-eoc-watchdog.py
  install -o root -g mbfd-aiops -m 0750 "${SOURCE_DIR}/run-hermes-bounded-summary.sh" /opt/mbfd/hermes/run-hermes-bounded-summary.sh
  for unit in "${UNIT_FILES[@]}"; do
    install -o root -g root -m 0644 "${SYSTEMD_SOURCE_DIR}/${unit}" "/etc/systemd/system/${unit}"
  done

  if [[ -f "${JOBS_FILE}" ]]; then
    temporary_jobs=$(mktemp --tmpdir="$(dirname "${JOBS_FILE}")" .jobs.json.XXXXXX)
    jq --argjson names "${HERMES_JOB_NAMES}" \
      '(.jobs[]? | select(.name as $name | $names | index($name)) | .enabled) = false' \
      "${JOBS_FILE}" >"${temporary_jobs}"
    chown --reference="${JOBS_FILE}" "${temporary_jobs}"
    chmod --reference="${JOBS_FILE}" "${temporary_jobs}"
    mv -f "${temporary_jobs}" "${JOBS_FILE}"
  fi

  systemctl daemon-reload
  systemctl disable --now mbfd-hermes-daily-summary.timer >/dev/null 2>&1 || true
  systemctl enable --now "${MANAGED_TIMERS[@]}"
  echo "backup=${backup_dir}"
}

case "${MODE}" in
  --apply)
    require_root
    validate_source
    apply_managed_files
    compare_managed_files
    verify_runtime_policy
    ;;
  --check)
    validate_source
    compare_managed_files
    verify_runtime_policy
    ;;
  *) usage ;;
esac

echo "Hermes watchdog ${MODE#--} passed."
