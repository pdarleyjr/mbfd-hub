# Hub uploaded files on the `/mnt/mbfd-storage` array

Move the Hub's uploaded and generated files (`storage/app`: the `local`/private
and `public` disks) from the NVMe root volume to the 22 TB array, while the hot,
small state stays on NVMe.

| Path in container | Today (NVMe) | After |
| --- | --- | --- |
| `/var/www/html/storage/app` (private + public disks, ~4.3 GB, ~3,600 files) | `/opt/mbfd/mbfd-hub/storage/app` | `/mnt/mbfd-storage/mbfdhub/laravel-storage/app` |
| `/var/www/html/storage/framework` (views, cache, maintenance flag) | `/opt/mbfd/mbfd-hub/storage/framework` | unchanged |
| `/var/www/html/storage/logs` | `/opt/mbfd/mbfd-hub/storage/logs` | unchanged |
| `storage/oauth-*.key`, `storage/checklists` | NVMe | unchanged |

The PostgreSQL database is not touched by this runbook.

Container paths do not change, so no application code or `config/filesystems.php`
change is needed: `compose.prod.image.yaml` mounts `HUB_STORAGE_APP_PATH` over
`/var/www/html/storage/app`. When `HUB_STORAGE_APP_PATH` is unset the mount
source is `HUB_STORAGE_PATH/app`, i.e. exactly today's layout, and nothing below
applies. When it is set, two guards keep uploads off the root disk:

- The mount uses `create_host_path: false`, so a missing source directory stops
  the start.
- An unmounted array usually still leaves the directory itself on the root disk
  (empty), which the mount would accept. So with `HUB_STORAGE_APP_PATH` set,
  compose sets `HUB_STORAGE_APP_SENTINEL_REQUIRED=1` and
  `docker/production/check-storage-mount` refuses to start unless the file
  `.mbfd-storage-sentinel` is at the top of the mounted `storage/app`. It runs in
  the container entrypoint and in `deploy.yml` before activation, so a deploy
  stops while the running release is still serving. Step 4 creates the file.

`public/storage` in the image is the relative symlink `../storage/app/public`;
it resolves through the new mount, so `/storage/...` URLs keep working. The
PWA and static assets under `public/` are inside the image and are unaffected.

## Read this first: RAID 0

`/dev/sda1` ("Pro RAID 0") has no redundancy: one failed member loses the whole
array. Note that Docker's data root (`/mnt/mbfd-storage/docker-data`) already
lives there, including the Hub PostgreSQL volume, so the Hub already depends on
this array; what makes the move safe is the backup coverage below.

Today the Hub files are protected by:

| Backup | Covers Hub files today via | After the move |
| --- | --- | --- |
| `/opt/mbfd/backups.sh` 03:15 (tar to `/mnt/mbfd-storage/backups/daily`) | `docker exec mbfd-hub-laravel tar ... storage` | still covered (GNU tar crosses the nested mount), **but the tarball sits on the same RAID 0** |
| `/opt/mbfd/restic-backup.sh` 04:00 (off-host R2) | `/opt/mbfd` and `/mnt/mbfd-storage/backups/daily` | `/opt/mbfd` copy goes stale; only the daily tarball remains → **add the new path** |
| `mbfd-ecosystem-backup.timer` 02:16 (USB HDD `/mnt/mbfd-backup-local`) | `/opt/mbfd` | **not covered → add the new path** |

### Backup changes (do these BEFORE the switch, step 0)

1. Off-host restic (R2): append one line to `/opt/mbfd/restic-sources.txt`:

   ```
   # MBFD Hub uploaded files (storage/app on the RAID 0 array; see mbfd-hub docs/ops/storage-on-mbfd-storage.md)
   /mnt/mbfd-storage/mbfdhub/laravel-storage/app
   ```

   Optionally add to `/opt/mbfd/restic-excludes.txt`:

   ```
   /mnt/mbfd-storage/mbfdhub/laravel-storage/app/private/livewire-tmp
   ```

2. Local USB restic: in `/usr/local/sbin/mbfd-ecosystem-backup` add to the
   `paths=( ... )` array (or to that script's source of truth if it is managed
   elsewhere):

   ```
   /mnt/mbfd-storage/mbfdhub/laravel-storage/app
   ```

   The unit's `ProtectSystem=full` allows reading `/mnt`; no unit change needed.

3. `/opt/mbfd/backups.sh`: no change. After the first night, prove the tarball
   still contains the files:
   `tar -tzf /mnt/mbfd-storage/backups/daily/laravel-storage-<TS>.tgz | grep -c '^storage/app/'`
   (expect roughly the file count from step 5).

Check the first runs after the switch: `sudo tail -50 /var/log/mbfd-restic.log`
(no `WARN: source missing`) and `journalctl -u mbfd-ecosystem-backup -n 50`.

## Prerequisites

- The release containing this compose change is live (it is a no-op until
  `HUB_STORAGE_APP_PATH` is set). Check:
  `grep -n HUB_STORAGE_APP_PATH /opt/mbfd/mbfd-hub/compose.prod.image.yaml`.
- Container identity: the image runs as `sail` = UID/GID `1000:1000`
  (`HUB_WWWUSER`/`HUB_WWWGROUP` in `/etc/mbfd/mbfd-hub/immutable-runtime.env`).
  On the host that is `mbfd:mbfd`. The target must be owned `1000:1000`.
- Space: `df -h /mnt/mbfd-storage` (≈20 TB free) — plenty.

Variables used below:

```bash
SRC=/opt/mbfd/mbfd-hub/storage/app
DST=/mnt/mbfd-storage/mbfdhub/laravel-storage/app
RUNTIME_ENV=/etc/mbfd/mbfd-hub/immutable-runtime.env
APP=mbfd-hub-laravel
SUPERVISOR='supervisorctl -c /etc/supervisor/conf.d/supervisord.conf'
```

## 1. Pre-copy while live (no downtime)

```bash
mountpoint -q /mnt/mbfd-storage && findmnt /mnt/mbfd-storage
sudo install -d -o 1000 -g 1000 -m 0775 "$DST"
sudo rsync -aHAX --numeric-ids --info=progress2 "$SRC/" "$DST/"
# repeat once to shrink the final delta
sudo rsync -aHAX --numeric-ids "$SRC/" "$DST/"
```

## 2. Maintenance window (a few minutes)

Pick a quiet time; check no long policy-library job is running
(`docker exec $APP $SUPERVISOR status`, `docker logs --since 10m $APP | grep -i policy`).

```bash
docker exec -u sail $APP php artisan down --render=errors::503
# Stop the container so nothing (web, queues, scheduler) writes during the final copy.
docker stop -t 120 $APP
sudo rsync -aHAX --numeric-ids --delete "$SRC/" "$DST/"
```

## 3. Verify the copy (before switching)

```bash
sudo rsync -aHAXn --numeric-ids --delete --itemize-changes "$SRC/" "$DST/" | head   # expect no output
sudo find "$SRC" -type f | wc -l ; sudo find "$DST" -type f | wc -l                 # equal
sudo du -sb "$SRC" "$DST"                                                            # equal bytes
sudo rsync -aHAXcn --numeric-ids --delete --itemize-changes "$SRC/" "$DST/" | head  # checksum pass, expect no output
sudo stat -c '%u:%g %a %n' "$DST" "$DST/private" "$DST/public"                       # 1000:1000
```

## 4. Switch the mount

Create the sentinel only now, after the last `rsync --delete` (which would remove
it, since the NVMe copy has none). Without it the container refuses to start once
`HUB_STORAGE_APP_PATH` is set:

```bash
mountpoint -q /mnt/mbfd-storage   # the array really is mounted
sudo install -o 1000 -g 1000 -m 0644 /dev/null "$DST/.mbfd-storage-sentinel"
sudo stat -c '%u:%g %a %n' "$DST/.mbfd-storage-sentinel"   # 1000:1000 644
```

Never create the sentinel in the bare mountpoint directory on the root disk
(e.g. while the array is unmounted): that would defeat the check.

```bash
sudo cp -a "$RUNTIME_ENV" "$RUNTIME_ENV.pre-storage-array-$(date -u +%Y%m%dT%H%M%SZ)"
echo "HUB_STORAGE_APP_PATH=$DST" | sudo tee -a "$RUNTIME_ENV"

cd /opt/mbfd/mbfd-hub
IMAGE_REF="$(docker inspect --format '{{.Config.Image}}' $APP)"   # the running digest; must be *@sha256:*
echo "$IMAGE_REF"
HUB_IMAGE_REF="$IMAGE_REF" docker compose --env-file "$RUNTIME_ENV" -f compose.prod.image.yaml \
  config --format json | jq '.services["laravel.test"].volumes'   # shows $DST -> /var/www/html/storage/app
HUB_IMAGE_REF="$IMAGE_REF" docker compose --env-file "$RUNTIME_ENV" -f compose.prod.image.yaml \
  up -d --pull never --no-build --no-deps --force-recreate laravel.test
```

A recreated container has no Laravel caches (they live in the image's
`bootstrap/cache`); replay what deploy does, then reload php-fpm so its OPcache
(timestamps are never revalidated) picks them up:

```bash
for c in optimize:clear config:cache route:cache view:cache event:cache filament:clear-cached-components; do
  docker exec -u sail $APP php artisan $c
done
docker exec $APP $SUPERVISOR signal USR2 php-fpm
docker exec $APP $SUPERVISOR status
```

## 5. Verify in the container (still in maintenance mode)

```bash
docker inspect --format '{{range .Mounts}}{{.Source}} -> {{.Destination}}{{"\n"}}{{end}}' $APP
docker exec -u sail $APP sh -ec '
  cd /var/www/html
  findmnt -no SOURCE,TARGET storage/app
  test -f storage/app/.mbfd-storage-sentinel && test "$HUB_STORAGE_APP_SENTINEL_REQUIRED" = 1
  stat -c "%u:%g %n" storage/app/private storage/app/public
  find storage/app -type f | wc -l
  touch storage/app/private/.write-probe && rm storage/app/private/.write-probe
  readlink public/storage && ls public/storage/ | head
  cat storage/app/private/deploy-marker.json
'
# A public-disk photo through nginx (pick a real file name):
F="$(docker exec -u sail $APP sh -c 'cd /var/www/html/storage/app/public && find defects -type f | head -n 1')"
docker exec $APP curl -sS -o /dev/null -w '%{http_code} %{size_download}\n' "http://127.0.0.1:8081/storage/$F"   # 200
```

Then open the Hub:

```bash
docker exec -u sail $APP php artisan up
docker exec $APP curl -sS -o /dev/null -w '%{http_code}\n' http://localhost:8081/up   # 200
```

In a browser, check: an apparatus issue with photos (Issue History), a narcotics
check photo, a vehicle document / resource-library PDF, and upload one test
photo from a phone (then delete it). Watch `docker logs -f $APP` for 5xx lines.

## 6. Afterwards

- Leave the NVMe copy in place for at least 14 days as the rollback source. It
  is shadowed by the mount, so the app no longer writes to it.
- After 14 clean days and two verified nightly backups of `$DST`:

  ```bash
  sudo mv "$SRC" "$SRC.pre-array-$(date -u +%Y%m%d)"
  sudo install -d -o 1000 -g 1000 -m 0775 "$SRC"      # empty mountpoint
  # later, once nobody needs it:
  sudo rm -rf "$SRC.pre-array-YYYYMMDD"
  ```

## Rollback

Any time before step 6's cleanup:

```bash
docker exec -u sail $APP php artisan down --render=errors::503
docker stop -t 120 $APP
# bring back anything written on the array since the switch
sudo rsync -aHAX --numeric-ids "$DST/" "$SRC/"
sudo sed -i '/^HUB_STORAGE_APP_PATH=/d' "$RUNTIME_ENV"
cd /opt/mbfd/mbfd-hub
HUB_IMAGE_REF="$(docker inspect --format '{{.Config.Image}}' $APP)" docker compose --env-file "$RUNTIME_ENV" \
  -f compose.prod.image.yaml up -d --pull never --no-build --no-deps --force-recreate laravel.test
# replay the caches + php-fpm reload from step 4, verify as in step 5, then:
docker exec -u sail $APP php artisan up
```

Use `rsync` without `--delete` for the way back unless you also want deletions
made on the array to apply to the NVMe copy.

## Notes

- Deploys keep the mount automatically: `deploy.yml` passes
  `--env-file $MBFD_HUB_IMAGE_RUNTIME_ENV` (`/etc/mbfd/mbfd-hub/immutable-runtime.env`)
  to every `docker compose up`/`run`, including the migration container.
- Manually editing the runtime env or `.env` and running `config:cache` needs
  `supervisorctl ... signal USR2 php-fpm` afterwards (OPcache does not re-read
  PHP files in the web pool).
- `livewire-tmp` (temporary uploads) moves with the rest; it is small and safe
  to exclude from backups.
- If the Hub will not start and `docker logs mbfd-hub-laravel` says
  `.mbfd-storage-sentinel is missing`, the array is not mounted (or
  `HUB_STORAGE_APP_PATH` points at the wrong directory). Mount it
  (`mountpoint -q /mnt/mbfd-storage`, `ls -la "$DST"` shows the files and the
  sentinel), then start the container again. Do not create the sentinel to get
  past the error. To run from NVMe instead, follow Rollback.
- Restoring storage/app from a backup onto the array: check the sentinel is still
  there afterwards (`--delete` restores from an NVMe-era copy remove it).
