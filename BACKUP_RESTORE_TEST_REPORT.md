# Backup & Restore Test Report — MBFD Hub (Phase 2)

Date: 2026-06-06
Host: GMKtec (`mbfdhub`, user `mbfd`)
Objective: encrypted, off-host backups with a verified restore (Phase 1 had local-only, unencrypted backups).

## Before (Phase 1 state)
- `/opt/mbfd/backups.sh` (cron `15 3 * * *`) dumps Postgres (`mbfd_hub`), MySQL (`snipeit`), Laravel `storage`, Snipe-IT libdata, Baserow, Uptime Kuma into `/mnt/mbfd-storage/backups/daily` (22 TB volume, 14-day rotation).
- **Not encrypted, not off-host.** A site/host loss or ransomware event would take the only copy with it.

## After (Phase 2 — implemented)
Encrypted, deduplicated, off-host backups to Cloudflare R2 using **Restic 0.18.1**.

| Component | Detail |
|---|---|
| Tool | `restic` 0.18.1 (installed via apt) |
| Off-host target | Cloudflare R2 bucket **`mbfd-hub-backups`** (created Phase 2), S3 endpoint `https://265122…r2.cloudflarestorage.com` |
| Encryption | Restic repository encryption (AES-256). Passphrase generated **on-box** (`openssl rand`), never printed/transmitted |
| Secrets | `/opt/mbfd/secrets/restic.env` (`0600`, owner `mbfd`): `RESTIC_REPOSITORY`, `AWS_ACCESS_KEY_ID`/`AWS_SECRET_ACCESS_KEY` (box's R2 creds), `RESTIC_PASSWORD` |
| Source | `/mnt/mbfd-storage/backups/daily` (the local dump set — DB dumps + storage tarballs) |
| Retention | `restic forget --keep-daily 7 --keep-weekly 4 --keep-monthly 6 --prune` |
| Schedule | cron `0 4 * * *` (`/opt/mbfd/restic-backup.sh`), after the 03:15 local dump |
| Logs | `/var/log/mbfd-restic.log` (owner-writable; no secrets) + status file `/opt/mbfd/secrets/restic-last-status` |

## Restore test (PASSED)
Performed end-to-end against the live R2 repo using the production DB dump:

```
source : mbfd_hub-20260606-031501.pgdump.gz  (Postgres dump of mbfd_hub)
backup : restic backup --tag resttest  -> snapshot d9225584 saved
restore: restic restore d9225584 --target /tmp/restic-test  -> Restored 5 files/dirs
verify : sha256(original) == sha256(restored)
         08d72c947e38fee8dfb2a6cda9944477285533f6bba1ae16ab964c89b34f48a1  (match)
RESULT : >>> RESTORE TEST PASSED <<<
```

The encrypted round-trip (local → R2 → restored → byte-identical) is proven. Repo initialized: `created restic repository 8378f3e60a at s3:…/mbfd-hub-backups`.

## Monitoring
- `/opt/mbfd/restic-check.sh` (cron `30 7 * * *`): alerts if the last successful backup is missing or older than 36h.
- Integrated into `/opt/mbfd/alerts.sh` (cron every 15 min) — see `AI_TOOLING_HARDENING_REPORT.md` / remediation log for the full alert set (disk>85%, DB down, app down, tunnel down, restic stale, unhealthy containers, failed queue jobs, SSH auth-failure spikes). Alerts append to `/var/log/mbfd-alerts.log` and POST to the URL in `/opt/mbfd/secrets/alert-webhook` if present.

## Operational notes
- First full backup of the ~2.4 GB daily set was launched in the background and completes asynchronously; subsequent runs are incremental/deduplicated.
- **Restore command for the owner** (any snapshot):
  ```bash
  set -a; . /opt/mbfd/secrets/restic.env; set +a
  restic snapshots
  restic restore <short-id> --target /restore/path
  ```
- **Disaster recovery**: the R2 repo is independent of the GMKtec host. To restore on a fresh host: install restic, recreate `restic.env` with the repo URL + R2 creds + **the recorded passphrase**, then `restic restore latest`.

## Recommended follow-ups (not yet done)
- Add Nextcloud user-data to the backup set (currently excluded; can be large — consider a separate restic path or R2 lifecycle).
- Quarterly restore drills (calendar reminder) restoring a full DB dump into a scratch Postgres and validating row counts.
- Populate `/opt/mbfd/secrets/alert-webhook` (or configure an Uptime Kuma notification channel) so backup-failure alerts are delivered, not just logged.
- Rotate the R2 credentials per `DEFERRED_OWNER_SECRET_ROTATION.md`, then update `restic.env`.
