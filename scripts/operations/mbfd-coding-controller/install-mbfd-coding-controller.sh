#!/usr/bin/env bash
# Stage or verify the canonical MBFD Coding Controller source.
# This helper deliberately does not restart, enable, disable, or stop the service.
set -Eeuo pipefail
umask 027

readonly MODE=${1:-}
readonly SOURCE_DIR=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
readonly TARGET_DIR=/opt/mbfd-coding-controller
readonly BACKUP_ROOT=/mnt/mbfd-storage/backups/on-demand
readonly UNIT_TARGET=/etc/systemd/system/mbfd-coding-controller.service
readonly SUDOERS_TARGET=/etc/sudoers.d/mbfd-coding-controller
readonly LOGROTATE_TARGET=/etc/logrotate.d/mbfd-coding-controller

usage() {
  echo "Usage: $0 --stage|--check" >&2
  exit 2
}

require_root() {
  if [[ ${EUID} -ne 0 ]]; then
    echo "This operation requires root." >&2
    exit 1
  fi
}

validate_source() {
  python3 -m py_compile "${SOURCE_DIR}/controller.py"
  bash -n "${SOURCE_DIR}/install-mbfd-coding-controller.sh"
  visudo -cf "${SOURCE_DIR}/mbfd-coding-controller.sudoers" >/dev/null
  systemd-analyze verify "${SOURCE_DIR}/mbfd-coding-controller.service" >/dev/null
}

compare_staged_files() {
  cmp -s "${SOURCE_DIR}/controller.py" "${TARGET_DIR}/controller.py"
  cmp -s "${SOURCE_DIR}/requirements.txt" "${TARGET_DIR}/requirements.txt"
  cmp -s "${SOURCE_DIR}/mbfd-coding-controller.service" "${UNIT_TARGET}"
  cmp -s "${SOURCE_DIR}/mbfd-coding-controller.sudoers" "${SUDOERS_TARGET}"
  cmp -s "${SOURCE_DIR}/mbfd-coding-controller.logrotate" "${LOGROTATE_TARGET}"
}

backup_if_present() {
  local source=$1
  local destination=$2
  if [[ -e "$source" ]]; then
    cp -a -- "$source" "$destination/"
  fi
}

stage_source() {
  local timestamp backup_dir
  timestamp=$(date -u +%Y%m%dT%H%M%SZ)
  backup_dir="${BACKUP_ROOT}/mbfd-coding-controller-source-stage-${timestamp}"
  install -d -o root -g root -m 0750 "$backup_dir"
  backup_if_present "${TARGET_DIR}/controller.py" "$backup_dir"
  backup_if_present "${TARGET_DIR}/requirements.txt" "$backup_dir"
  backup_if_present "$UNIT_TARGET" "$backup_dir"
  backup_if_present "$SUDOERS_TARGET" "$backup_dir"
  backup_if_present "$LOGROTATE_TARGET" "$backup_dir"

  install -d -o root -g mbfd-coding -m 0750 "$TARGET_DIR"
  install -o root -g mbfd-coding -m 0750 \
    "${SOURCE_DIR}/controller.py" "${TARGET_DIR}/controller.py"
  install -o root -g mbfd-coding -m 0640 \
    "${SOURCE_DIR}/requirements.txt" "${TARGET_DIR}/requirements.txt"
  install -o root -g root -m 0644 \
    "${SOURCE_DIR}/mbfd-coding-controller.service" "$UNIT_TARGET"
  install -o root -g root -m 0440 \
    "${SOURCE_DIR}/mbfd-coding-controller.sudoers" "$SUDOERS_TARGET"
  install -o root -g root -m 0644 \
    "${SOURCE_DIR}/mbfd-coding-controller.logrotate" "$LOGROTATE_TARGET"

  compare_staged_files
  echo "backup=${backup_dir}"
  echo "staged_only=true"
  echo "service_restart_required_for_activation=true"
}

case "$MODE" in
  --stage)
    require_root
    validate_source
    stage_source
    ;;
  --check)
    validate_source
    compare_staged_files
    ;;
  *)
    usage
    ;;
esac

echo "MBFD coding controller ${MODE#--} passed."
