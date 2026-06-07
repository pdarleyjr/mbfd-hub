# Private Storage Migration Plan — Finding H-04

Moves sensitive files (station inventory PDFs, workgroup shared uploads) off
Laravel's web-reachable `public` disk and onto a private disk, serving them only
through authorized controller routes.

## What changed (code)

| File | Change |
|------|--------|
| `config/filesystems.php` | New `private` disk key: `env('PRIVATE_FILESYSTEM_DISK', 'local')`. Defaults to the existing `local` disk (`storage/app/private`, not web-reachable). Set `PRIVATE_FILESYSTEM_DISK=r2` to use the existing private R2 disk instead — no code change required. |
| `app/Http/Controllers/Api/StationInventoryController.php` | New inventory PDFs are written to the private disk. The store response now returns `pdf_download_url` (the authenticated `download-inventory-pdf` route) instead of `pdf_url` (a public `/storage` URL). `downloadPdf` streams from the private disk, with a fallback to the legacy `public` disk for files not yet migrated. (Also fixed a latent bug: the download filename used `$submission->station->slug`, which does not exist on the `Station` model — now uses `station_number`.) |
| `app/Filament/Workgroup/Pages/SharedUploads.php` | New workgroup shared uploads are written to the private disk (both the Livewire-temp-string path and the `UploadedFile` fallback). |
| `app/Http/Controllers/Workgroup/FileDownloadController.php` | `downloadSharedUpload` streams from the private disk, falling back to `public` for un-migrated files. |
| `app/Services/Workgroup/WorkgroupAIService.php` | `vectorizeUpload` reads from the private disk, falling back to `public`. |
| `app/Console/Commands/MoveSensitiveFilesToPrivate.php` | New one-time migration command (run later on the server). |
| `database/migrations/*` (8 files) | Guarded Postgres-only raw DDL with `if (DB::connection()->getDriverName() !== 'pgsql') return;` so the suite (and `RefreshDatabase`) runs on SQLite. Behaviour on Postgres is unchanged. |

### Files that were NOT moved (genuinely public — left on the `public` disk)
- Filament image uploads / previews for Apparatus defects, Capital projects,
  Under-25k projects, Todos, Training todos (admin-panel image thumbnails served
  via `asset('storage/...')`).
- `WorkgroupFile` uploads already use Filament `FileUpload` with
  `->visibility('private')` + the `workgroup-files` directory and are served
  through the auth-gated `workgroup.file.download`/`preview` routes.

## Which files move on the server

Two directories on the `public` disk hold sensitive files and must move to the
private disk:

- `storage/app/public/inventory-submissions/**`  (station inventory PDFs)
- `storage/app/public/workgroup-shared-uploads/**`  (workgroup shared uploads)

Existing DB rows store the relative path (e.g. `inventory-submissions/foo.pdf`),
so **no DB update is needed** — the same relative path resolves on the private
disk after the files are copied. The download controllers already fall back to
the `public` disk, so downloads keep working even before the move runs.

## The move command

```bash
# 1. Preview (no changes)
php artisan storage:move-sensitive-private --dry-run

# 2. Copy public -> private (originals left in place; downloads now prefer private)
php artisan storage:move-sensitive-private

# 3. After verifying downloads work, remove the public originals
php artisan storage:move-sensitive-private --prune
```

The command is additive and idempotent: it copies (never moves blindly), skips
files already present on the private disk, verifies each copy landed before
counting it, and only deletes public originals when `--prune` is passed. It
refuses to run if `filesystems.private` resolves to the `public` disk.

## How serving changes

- **Before:** inventory PDFs and shared uploads were retrievable directly at
  `https://<host>/storage/inventory-submissions/...` and
  `.../workgroup-shared-uploads/...`, bypassing all controller authorization.
- **After:** the files live on a non-symlinked private disk. They are reachable
  only through:
  - `GET /api/station-inventory-submissions/{submission}/pdf` and
    `GET /inventory-pdf/{submission}` (`download-inventory-pdf`) — `auth` gated.
  - `GET /workgroup/shared-upload/{upload}/download`
    (`workgroup.shared-upload.download`) — `auth` + `workgroup.access` gated,
    plus a per-record check that the member belongs to the upload's workgroup.

## Backward compatibility

- DB paths are unchanged; no migration of data rows.
- All read paths (download controllers, AI vectorizer) check the private disk
  first and fall back to `public`, so files generated before the deploy keep
  working until the move command is run.
- The store API response key changed from `pdf_url` to `pdf_download_url`. The
  daily-checkout frontend does not read either key (it downloads via the
  dedicated `downloadInventoryPdf` blob endpoint), so this is non-breaking.

## Verification

1. Deploy the code, then upload a NEW inventory submission and a NEW workgroup
   shared upload.
2. Confirm the files appear under `storage/app/private/...` and **not** under
   `storage/app/public/...`.
3. Confirm `https://<host>/storage/inventory-submissions/<new-file>` returns
   403/404 (not the file).
4. Confirm the in-app download buttons still return the file for authorized
   users, and that an anonymous request is redirected/denied.
5. Run the move command (`--dry-run`, then real, then `--prune`) and re-verify
   step 3 for the previously-public legacy files.

## Rollback

Code is additive and safe to revert:

1. `git revert` the commit (or redeploy the previous build). The read paths fall
   back to `public`, and the legacy `/storage/...` symlink still exists.
2. If files were already pruned from the public disk, copy them back from the
   private disk:
   ```bash
   # On the server, with tinker or a short script:
   foreach (['inventory-submissions','workgroup-shared-uploads'] as $d) {
     foreach (Storage::disk(config('filesystems.private'))->allFiles($d) as $p) {
       if (! Storage::disk('public')->exists($p)) {
         Storage::disk('public')->put($p, Storage::disk(config('filesystems.private'))->get($p));
       }
     }
   }
   ```
3. No DB changes were made, so there is nothing to roll back in the database.

## Note (out of scope, flagged)

`resources/views/pdf/station-inventory.blade.php` has a pre-existing bug: the
closure at line ~130 references `$categories` without capturing it
(`use ($category)` is missing `$categories`), which throws
`Undefined variable $categories` when a PDF is rendered. This is unrelated to
H-04 and was left untouched; it should be fixed separately.
