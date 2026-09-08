#!/usr/bin/env python3
"""Restricted SSH relay; identity serialization lives with the container mutator."""

from __future__ import annotations

import json
import os
import re
import subprocess
import sys
from pathlib import Path


def reconcile(request: dict, approved_uids: set[str], run=subprocess.run) -> dict:
    if not isinstance(request, dict) or set(request) != {'uid', 'revision', 'enabled'}:
        raise ValueError('Unsupported identity operation')
    uid, revision, enabled = request['uid'], request['revision'], request['enabled']
    if (not isinstance(uid, str) or not re.fullmatch(r'[a-z][a-z0-9._-]{1,63}', uid)
            or uid not in approved_uids or type(revision) is not int
            or not 1 <= revision <= 2**53 - 1 or type(enabled) is not bool):
        raise ValueError('Unapproved identity or invalid state')
    # This timeout bounds only the SSH response, not the container operation.
    # The complete transition engine retains its own UID lock and revision
    # fence inside the container until the actual OCC child has terminated.
    result = run(
        ['/usr/bin/docker', 'exec', '-i', '-u', 'www-data', 'mbfd-nextcloud', 'php',
         '/var/www/html/config/hub-identity-bridge.cli.php'],
        input=json.dumps(request), capture_output=True, text=True, timeout=20, check=False,
    )
    if result.returncode != 0 or len(result.stdout) > 4096:
        raise RuntimeError('Cloud identity transition was not acknowledged')
    ack = json.loads(result.stdout)
    if (not isinstance(ack, dict) or set(ack) != {'uid', 'revision', 'enabled', 'old_tokens_purged'}
            or ack['uid'] != uid or type(ack['revision']) is not int or ack['revision'] != revision
            or type(ack['enabled']) is not bool or ack['enabled'] != enabled
            or ack['old_tokens_purged'] is not True):
        raise RuntimeError('Cloud identity acknowledgement did not match')
    return ack


def main() -> int:
    os.umask(0o077)
    try:
        if os.environ.get('SSH_ORIGINAL_COMMAND') != 'nextcloud-identity':
            raise ValueError('Only the fixed identity command is supported')
        raw = sys.stdin.buffer.read(2049)
        if len(raw) > 2048:
            raise ValueError('Identity request is too large')
        approved = json.loads(Path('/etc/mbfd/nextcloud-identity-uids.json').read_text())
        if not isinstance(approved, list) or not approved or any(not isinstance(uid, str) for uid in approved):
            raise ValueError('Approved identity inventory is unavailable')
        print(json.dumps(reconcile(json.loads(raw), set(approved))))
        return 0
    except (OSError, ValueError, RuntimeError, subprocess.TimeoutExpired):
        # Never echo input, account tokens, provider output, or command stderr.
        print(json.dumps({'error': 'Cloud identity transition was not verified'}))
        return 1


if __name__ == '__main__':
    raise SystemExit(main())
