#!/usr/bin/env bash
set -Eeuo pipefail

readonly SOURCE_UNIT=${1:-}
readonly OBS_UNIT=/etc/systemd/system/mbfd-obs.service
readonly DIRECTOR_ENV=/etc/mbfd/ai-live-director/config.env
readonly OBS_CONFIG=/home/mbfd/.config/obs-studio/plugin_config/obs-websocket/config.json
readonly BACKUP_DIR=/var/lib/mbfd-remediation/20260720-081719/obs-credential-rotation

if [[ -z "${SOURCE_UNIT}" || ! -f "${SOURCE_UNIT}" ]]; then
    echo "Usage: $0 /path/to/mbfd-obs.service" >&2
    exit 64
fi

for required in "${OBS_UNIT}" "${DIRECTOR_ENV}" "${OBS_CONFIG}"; do
    if [[ ! -f "${required}" ]]; then
        echo "Required file is missing: ${required}" >&2
        exit 1
    fi
done

install -d -m 0700 -o root -g root "${BACKUP_DIR}"
cp -a "${OBS_UNIT}" "${BACKUP_DIR}/mbfd-obs.service.before"
cp -a "${DIRECTOR_ENV}" "${BACKUP_DIR}/config.env.before"
cp -a "${OBS_CONFIG}" "${BACKUP_DIR}/obs-websocket-config.json.before"

umask 077
credential_tmp=$(mktemp /run/mbfd-obs-credential.XXXXXX)
trap 'rm -f "${credential_tmp:-}"' EXIT
openssl rand -hex 32 > "${credential_tmp}"

OBS_CREDENTIAL_FILE="${credential_tmp}" python3 - <<'PY'
import json
import os
import re
from pathlib import Path

env_path = Path('/etc/mbfd/ai-live-director/config.env')
config_path = Path('/home/mbfd/.config/obs-studio/plugin_config/obs-websocket/config.json')
new_secret = Path(os.environ['OBS_CREDENTIAL_FILE']).read_text().strip()
env_text = env_path.read_text()
match = re.search(r'^OBS_WEBSOCKET_PASSWORD=(.*)$', env_text, re.MULTILINE)
if match is None:
    raise SystemExit('OBS_WEBSOCKET_PASSWORD is missing from director environment')
old_secret = match.group(1).strip().strip('"\'')
if not old_secret:
    raise SystemExit('Existing OBS credential is empty')

config = json.loads(config_path.read_text())
if config.get('server_password') != old_secret:
    raise SystemExit('OBS and AI Director credentials do not match')

updated_env, substitutions = re.subn(
    r'^OBS_WEBSOCKET_PASSWORD=.*$',
    'OBS_WEBSOCKET_PASSWORD=' + new_secret,
    env_text,
    count=1,
    flags=re.MULTILINE,
)
if substitutions != 1:
    raise SystemExit('Could not update AI Director credential')

config['auth_required'] = True
config['server_enabled'] = True
config['server_password'] = new_secret

def atomic_write(path: Path, content: str) -> None:
    stat = path.stat()
    temporary = path.with_name('.' + path.name + '.rotation')
    temporary.write_text(content)
    os.chown(temporary, stat.st_uid, stat.st_gid)
    os.chmod(temporary, stat.st_mode & 0o777)
    os.replace(temporary, path)

atomic_write(env_path, updated_env)
atomic_write(config_path, json.dumps(config, indent=2) + '\n')

candidate_paths = []
for pattern in (
    '/etc/mbfd/ai-live-director/config.env.*',
    '/home/mbfd/.config/obs-studio/logs/*.txt',
    '/home/mbfd/.bash_history',
    '/root/.bash_history',
):
    candidate_paths.extend(Path('/').glob(pattern.lstrip('/')))

redacted = 0
for path in candidate_paths:
    try:
        if not path.is_file() or path in (env_path, config_path):
            continue
        raw = path.read_bytes()
        if old_secret.encode() not in raw:
            continue
        path.write_bytes(raw.replace(old_secret.encode(), b'[REDACTED_ROTATED_OBS_CREDENTIAL]'))
        redacted += 1
    except OSError:
        continue

print(f'obs_historical_files_redacted={redacted}')
PY

install -m 0644 -o root -g root "${SOURCE_UNIT}" "${OBS_UNIT}"
systemd-analyze verify mbfd-obs.service
systemctl daemon-reload

rollback() {
    echo "OBS credential rotation failed; restoring protected configuration" >&2
    cp -a "${BACKUP_DIR}/mbfd-obs.service.before" "${OBS_UNIT}"
    cp -a "${BACKUP_DIR}/config.env.before" "${DIRECTOR_ENV}"
    cp -a "${BACKUP_DIR}/obs-websocket-config.json.before" "${OBS_CONFIG}"
    systemctl daemon-reload
    systemctl restart mbfd-obs.service
    systemctl restart mbfd-ai-director.service
}

if ! systemctl restart mbfd-obs.service; then
    rollback
    exit 1
fi

for attempt in $(seq 1 45); do
    if systemctl is-active --quiet mbfd-obs.service && ss -lntH 'sport = :4455' | grep -q .; then
        break
    fi
    if (( attempt == 45 )); then
        rollback
        exit 1
    fi
    sleep 1
done

if ! systemctl restart mbfd-ai-director.service; then
    rollback
    exit 1
fi

for attempt in $(seq 1 45); do
    if curl --fail --silent --show-error --max-time 3 \
        http://127.0.0.1:8766/health | jq -e '.status == "ok" and .obs == true' >/dev/null; then
        break
    fi
    if (( attempt == 45 )); then
        rollback
        exit 1
    fi
    sleep 1
done

obs_pid=$(systemctl show -p MainPID --value mbfd-obs.service)
if tr '\0' ' ' < "/proc/${obs_pid}/cmdline" | grep -qi 'password'; then
    rollback
    echo "OBS process arguments still contain a password option" >&2
    exit 1
fi

rm -f "${credential_tmp}"
trap - EXIT
echo "obs_websocket_credential_rotation=pass"
