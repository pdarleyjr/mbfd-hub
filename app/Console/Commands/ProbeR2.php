<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Read-only health probe for the Cloudflare R2 disk. Mirrors mbfd:activate-redis.
 *
 *   docker exec mbfd-hub-laravel php artisan mbfd:probe-r2
 *
 * Performs a put / get / delete round-trip against the configured `r2` disk.
 * Returns non-zero exit if any step fails, so it can be used as a gate inside
 * the production-activate.yml workflow before treating the cutover as
 * successful.
 */
class ProbeR2 extends Command
{
    /** @var string */
    protected $signature = 'mbfd:probe-r2';

    /** @var string */
    protected $description = 'Round-trip a tiny object against the r2 disk to verify R2_* env vars are wired correctly';

    public function handle(): int
    {
        $this->info('=== MBFD R2 Disk Probe ===');

        $bucket = config('filesystems.disks.r2.bucket');
        $endpoint = config('filesystems.disks.r2.endpoint');

        if (! $bucket || ! $endpoint) {
            $this->error('R2 disk is not configured. Check R2_BUCKET / R2_ENDPOINT in .env');

            return self::FAILURE;
        }

        $this->line("Bucket:   {$bucket}");
        $this->line("Endpoint: {$endpoint}");

        $key = '_probe/r2-probe-'.uniqid('', true).'.txt';
        $body = 'probe-'.now()->toIso8601String();

        try {
            Storage::disk('r2')->put($key, $body);
            $this->info("  ✓ PUT {$key}");

            $back = Storage::disk('r2')->get($key);
            if ($back !== $body) {
                $this->error("  ✗ GET mismatch — wrote [{$body}], got [{$back}]");
                Storage::disk('r2')->delete($key);

                return self::FAILURE;
            }
            $this->info('  ✓ GET round-trip OK');

            Storage::disk('r2')->delete($key);
            $this->info('  ✓ DEL OK');
        } catch (\Throwable $e) {
            $this->error("  ✗ R2 round-trip failed: {$e->getMessage()}");
            $this->error('  → Check R2_ACCESS_KEY_ID / R2_SECRET_ACCESS_KEY / R2_BUCKET in .env');

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('✓ R2 disk is healthy.');

        return self::SUCCESS;
    }
}
