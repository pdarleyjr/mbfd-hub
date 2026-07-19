# Operational Forms deployment and rollback

This module is deployed as part of the existing MBFD Hub Laravel application. It requires PHP 8.2+, Node.js 20+, a running Laravel queue worker, `qpdf`, and Poppler's `pdfinfo`. Production PDF generation fails closed when neither external structural validator is available.

## Pre-deployment gates

1. Back up the application database and the configured private filesystem.
2. Confirm `PRIVATE_FILESYSTEM_DISK` points to a private local or R2 disk. On the GMKtec local disk, generated documents remain below `storage/app/private/operational-forms/` and must not be symlinked into `public/storage`.
3. Confirm `node --version`, `qpdf --version`, and `pdfinfo -v` succeed for the service account.
4. Confirm the queue supervisor can consume the `operational-forms` queue.
5. Record the current release identifier and database migration state for rollback.

## Release sequence

```sh
php artisan down
composer install --no-dev --prefer-dist --optimize-autoloader
npm ci
npm run build
php artisan migrate --force
php artisan optimize
php artisan queue:restart
php artisan up
```

Run the acceptance smoke with a non-production test employee: authenticate directly to `/employee/forms`, create and reopen one draft of each form type, generate both controlled PDFs, preview and download them, and verify the Admin **Forms** resource can preview the same immutable versions. Confirm `/storage/operational-forms/...` is not reachable.

Monitor Laravel logs, failed queue jobs, Sentry, disk capacity, and queue latency without logging source form contents.

## Rollback

1. Enter maintenance mode and stop new queue consumption.
2. Restore the prior application release and prior built assets.
3. Do not delete generated PDFs, structured records, generation jobs, or audit events.
4. Leave the additive tables in place unless a verified backup restore is being performed and the tables contain no production data.
5. Restart the previous queue workers, leave maintenance mode, and verify the Employee and Admin panels.

Database rollback is intentionally not the default because immutable document metadata and audit history must be retained. If a full backup restore is required, restore the database and private filesystem from the same recovery point so their checksums and paths remain consistent.
