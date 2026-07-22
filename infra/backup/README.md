# MBFD Backup Selector Correction (§10)

Moves the backup/restore-exact-snapshot correction out of the evidence
directory into the version-controlled operations tree. **Deploy POST-CLASS.**

## Defect
The original restore-smoke independently selected the "latest" snapshot via
`restic snapshots --latest 1`, which could select an older/wrong snapshot if
the restic cache was stale or the backup hadn't fully registered.

## Fix (canonical, version-controlled)
- `mbfd_backup_snapshot.py` — resolve/verify an EXACT snapshot id. **Never
  falls back to "latest".** Fails closed on empty / missing / wrong-host /
  wrong-tag / ambiguous. Two modes: `capture` (backup) and `verify` (restore).
- `mbfd-backup-capture-snapshot-id.sh` — called by the backup script right
  after `restic backup`; writes the exact short id to
  `$STAGING/latest-snapshot-id`.
- `mbfd-ecosystem-restore-smoke.sh` — reads the exact id, verifies it against
  `restic snapshots --json` via the helper, restores into an isolated mktemp
  dir, checks SHA256SUMS, and fails on any mismatch/absence.

## §10 invariants
| # | Requirement | Where |
|---|-------------|-------|
| 1 | backup returns the exact new snapshot id | `mbfd-backup-capture-snapshot-id.sh` |
| 2 | restore receives that exact id explicitly | reads `latest-snapshot-id` |
| 3 | restored id == requested id | `mbfd_backup_snapshot.py verify` |
| 4 | restore into an isolated dir | `mktemp -d` + trap |
| 5 | compare representative hashes | `sha256sum -c SHA256SUMS` |
| 6 | fail on hash mismatch | `: FAILED$` count check |
| 7 | fail when snapshot absent | helper raises on not-found |
| 8 | never silently select another "latest" | helper only matches requested id |
| 9 | log only safe metadata | sizes, short id, pass/fail only |
| 10 | unit tests | `tests/test_backup_snapshot.py` (13 tests) |

## Tests
```sh
python3 -m pytest infra/backup/tests -q          # 13 passed
python3 -m ruff check infra/backup/mbfd_backup_snapshot.py
```

## Deployment (POST-CLASS only)
```sh
sudo install -m 0755 infra/backup/mbfd_backup_snapshot.py            /usr/local/sbin/
sudo install -m 0755 infra/backup/mbfd-backup-capture-snapshot-id.sh  /usr/local/sbin/
sudo install -m 0755 infra/backup/mbfd-ecosystem-restore-smoke.sh     /usr/local/sbin/
# Wire the capture step into /usr/local/sbin/mbfd-ecosystem-backup (call after restic backup).
# Then: run a new backup, run restore-smoke, preserve evidence.
# No canary deployment until exact-restore validation passes.
```
