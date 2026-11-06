# Retained hashed assets across deploys

Release-standard finding F4. Production runs one immutable image whose
`public/build` and `public/daily` hold only that release's content-hashed
files. A page rendered by the previous release and still open in a member's
browser (an installed app can stay resident for days) lazy-loads its chunks by
their old hashed names, for example `/build/assets/foo-ABC12345.js`. After the
deploy the new image has no such file, so before this change the chunk failed
and the page could only offer the "Refresh app" banner
(`resources/js/pwa/chunk-recovery.ts`), possibly in the middle of a form.

Now the replaced release's hashed files stay available for a bounded time, so
the old page keeps working until the member reloads at a convenient moment.

## How it works

| Part | What it does |
| --- | --- |
| `.github/workflows/deploy.yml`, step "Retain the running release's hashed assets" | Runs right before "Migrate and activate prebuilt Hub image", while the old container is still serving. `docker cp`s `/var/www/html/public/build/assets/.` and `/var/www/html/public/daily/assets/.` from `mbfd-hub-laravel` into a temporary directory, then, in a throwaway container of the running image (`--network none --read-only --user 0:0 --cap-drop=ALL`), copies them into `/var/lib/mbfd-hub/retained-assets/{build,daily}/assets`, prunes and caps (below). |
| `compose.prod.image.yaml` | Mounts `/var/lib/mbfd-hub/retained-assets` read-only at `/var/www/retained` in `laravel.test`. |
| `docker/production/nginx.conf` | The hashed-asset location tries the image first (`try_files $uri @retained_asset`). Only if the image lacks the file does `location @retained_asset` look in `/var/www/retained`, else a plain 404. |

Host directory: `/var/lib/mbfd-hub/retained-assets`, owned by root, mode 0755,
files 0644 (world-readable, so `sail` in the container can read them). It is
created on first use by the deploy step (`docker run --volume`), and by compose
if it is ever missing; nothing needs to be prepared on the host.

The compose file reaches the host through the checkout: deploy.yml checks out
the exact release in `/opt/mbfd/mbfd-hub` and runs
`docker compose --env-file "$HUB_IMAGE_RUNTIME_ENV" -f compose.prod.image.yaml up ... laravel.test`
from there. There is no separate host copy to edit.

## Rules

- **Never overwritten.** A content-hashed name means the same bytes, so a file
  already in the retained directory is kept as it is. If the bytes differ
  anyway, the existing file is kept and the step logs a warning.
- **Written atomically.** Each new file is copied as `.<name>.partial` and then
  renamed. nginx never serves dotfiles, and leftovers are deleted on the next run.
- **Retention clock.** After copying, every file of the release being replaced
  is touched, so its mtime is the last time it belonged to a live release. A
  file carried unchanged through many releases is kept as long as any recent
  release used it.
- **Age bound.** Files not touched for more than 72 hours
  (`find ... -type f -mmin +4320 -delete`) are deleted on every deploy.
- **Size bound.** If the directory still holds more than 500 MB
  (524,288,000 bytes), the least recently touched files are deleted first until
  it is under the cap. One release's assets are a few MB today, so this is a
  guard, not the normal limit.
- **Never blocks a release.** The step has `continue-on-error: true` and a
  5-minute timeout, runs without `set -e`, wraps `docker cp` and `docker run` in
  `timeout`, turns every failure into a `::warning title=Hashed asset retention::`
  annotation and exits 0. It is separate from the activation step, so nothing
  in it can trip that step's `set -e`, its failure handling or the
  "Recover service after a failed activation" step. Running it twice is
  harmless: the second run copies nothing new.
- Pruning happens only at deploy time. Between deploys nothing changes, so
  after a long gap the previous release's files are still there until the next
  deploy prunes them.

### nginx behaviour

| Request | Answer |
| --- | --- |
| Hashed file present in the image | 200 from the image, `Cache-Control: public, max-age=31536000, immutable` (unchanged) |
| Missing from the image, present in the retained directory | 200 (or 304), same immutable header, plus `X-Hub-Asset-Source: retained` |
| Missing from both | 404, `Cache-Control: no-store`, `X-Hub-Asset-Source: retained` (the fallback was consulted) |
| Retained directory absent (compose without the mount) or unreadable | 404 as above, never a 500. An unreadable directory also logs a `crit` `stat() ... Permission denied` line per request. |

A miss is no longer handed to Laravel. Before this change, a missing Daily
chunk (`/daily/assets/...`) reached the `/daily/{path}` route and came back to a
signed-in member as the Daily HTML shell with status 200, which the page could
not recognise as missing. It is now a 404, which is what `chunk-recovery.ts`
looks for.

The Daily service worker already keeps the previous release's `/daily/assets/*`
in its cache (`docs/pwa-service-worker-architecture.md`, section on cache
upgrades); it is unchanged. This server-side copy also covers the Hub bundles
under `/build/assets/` and Daily pages without a worker.

## Verify on the host after a deploy

1. The step's log line, for example
   `Retained hashed assets: 68 new, 0 already kept; now 68 files, 4.3M.`
   A yellow "Hashed asset retention" annotation means something was skipped;
   the release itself is unaffected.
2. On the host:

   ```bash
   sudo find /var/lib/mbfd-hub/retained-assets -type f -printf '%TY-%Tm-%Td %TH:%TM %s %P\n' | sort | tail
   sudo du -sh /var/lib/mbfd-hub/retained-assets
   stat -c '%U:%G %a %n' /var/lib/mbfd-hub/retained-assets
   docker inspect --format '{{range .Mounts}}{{if eq .Destination "/var/www/retained"}}{{.Source}} rw={{.RW}}{{end}}{{end}}' mbfd-hub-laravel
   # expect: /var/lib/mbfd-hub/retained-assets rw=false
   ```

3. Pick a file that was retained but is no longer in the current image, and
   ask for it through the edge:

   ```bash
   OLD=$(comm -23 \
     <(sudo ls /var/lib/mbfd-hub/retained-assets/build/assets | sort) \
     <(docker exec mbfd-hub-laravel ls /var/www/html/public/build/assets | sort) | head -n 1)
   curl -sI "https://www.mbfdhub.com/build/assets/$OLD"
   # expect: HTTP/2 200, cache-control: public, max-age=31536000, immutable, x-hub-asset-source: retained
   curl -sI https://www.mbfdhub.com/build/assets/does-not-exist-ABCDEFGH.js
   # expect: HTTP/2 404, cache-control: no-store
   ```

   This works from the first deploy that ships the change: the step retains
   the release being replaced (which predates it), and the new image's nginx
   and the new mount serve those files. `comm` prints nothing only when the two
   releases share every asset.

## Turn it off

- Serving: remove the `/var/lib/mbfd-hub/retained-assets:/var/www/retained:ro`
  line from `compose.prod.image.yaml` (a normal PR and deploy; production uses
  the file from the checkout). Without the mount, nginx answers every miss with
  the plain 404 described above.
- Collecting: remove the "Retain the running release's hashed assets" step from
  `deploy.yml`.
- Emergency, without a deploy: empty the directory
  (`sudo find /var/lib/mbfd-hub/retained-assets -type f -delete`). Misses are
  404 again at once; the next deploy refills it with that deploy's previous
  release only.
- Removing the directory entirely is safe too (`sudo rm -rf` it); the next
  container start or deploy recreates it empty.

## Rollback note

An image built before this change has no `@retained_asset` location: started
with this compose file it ignores the mount, and behaves exactly as before. A
manual rollback (`docker compose ... up` with the previous image, outside
deploy.yml) does not run the retention step, so pages rendered by the release
being rolled back from can lose their chunks; the page-side chunk recovery
still handles that case as before.
