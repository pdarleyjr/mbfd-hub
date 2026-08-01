# Media Control NVMe state storage

Media Control keeps its SQLite database at `/app/data/db/remote_display.db`.
That state is latency-sensitive: device heartbeats, wall revisions, transport
state, screenshots, and multi-user convergence all write through it.

The GMKtec Docker data root is on the 22 TB bulk HDD. On 2026-08-01, a
read-only SQLite scan saturated that disk at 95% utilization, produced an I/O
queue of 61, blocked 41 processes in kernel disk wait, and made both the local
origin and the public Media Control route time out. CPU, memory, tunnel, and
connection-tracking capacity remained healthy.

The production layout therefore binds only `/app/data/db` to
`/var/lib/mbfd/media-control-db` on the NVMe system disk. Uploads, recordings,
container layers, and backups remain on bulk storage.

## Cutover contract

1. Snapshot the five classroom `display_states` rows and both classroom
   `video_walls` rows.
2. Back up the active Compose files.
3. Stop only `media-control`; do not restart Docker or `cloudflared`.
4. Use SQLite `.backup` to create a fresh database in the NVMe directory.
5. Run `PRAGMA quick_check` against the NVMe copy and compare the state/wall
   snapshots.
6. Add the volume mapping from
   `docker-compose.nvme-db.override.yml` to the active production override.
7. Recreate only `media-control`, wait for Docker health and HTTP 200, then run
   `verify-nvme-db-cutover.sh`.
8. Confirm the five Lenovo kiosk windows reconnect. A scoped kiosk restart may
   be required after a planned origin cutover.

The original named-volume database must remain untouched until the NVMe
release passes its dwell and operator-state checks.

## Rollback

Restore the saved active Compose override, recreate only `media-control`, and
verify that `/app/data/db` again resolves to the original Docker named volume.
The old database is preserved in:

`/mnt/mbfd-storage/docker-data/volumes/media-control_media_control_db/_data`

Do not restart the shared Cloudflare tunnel for a Media Control origin
failure. The tunnel serves unrelated MBFDHub sites.
