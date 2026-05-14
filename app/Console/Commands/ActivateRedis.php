<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;

/**
 * One-shot health-check command for the Redis activation cutover.
 *
 * Run inside the production container BEFORE flipping CACHE_STORE etc.:
 *
 *   docker exec mbfd-hub-laravel php artisan mbfd:activate-redis --dry-run
 *
 * The command:
 *   - Verifies Redis is reachable with the configured REDIS_HOST + auth
 *   - Verifies the Redis container responds to PING
 *   - Round-trips a test key (read/write/delete) to prove serialization works
 *   - Reports what env vars need to be flipped to "redis" and (if currently
 *     unset) which need to be set fresh
 *
 * The command itself does NOT modify .env — env editing happens via the
 * deploy workflow / SSH (see docs/REDIS_ACTIVATION.md). This is a
 * read-only readiness probe.
 */
class ActivateRedis extends Command
{
    /** @var string */
    protected $signature = 'mbfd:activate-redis
                            {--dry-run : Probe Redis and report status without prompting}';

    /** @var string */
    protected $description = 'Verify Redis readiness before flipping CACHE_STORE/QUEUE_CONNECTION/SESSION_DRIVER/BROADCAST_DRIVER to redis';

    public function handle(): int
    {
        $this->info('=== MBFD Redis Activation Probe ===');
        $this->newLine();

        // 1) Connectivity probe
        $this->line('1) Redis connectivity...');
        try {
            $pong = Redis::ping();
            $this->info("   ✓ PING → {$pong}");
        } catch (\Throwable $e) {
            $this->error("   ✗ Cannot reach Redis: {$e->getMessage()}");
            $this->error('   → Check REDIS_HOST, REDIS_PORT, REDIS_PASSWORD in .env');
            $this->error('   → Confirm the redis service is healthy: docker compose -f compose.prod.yaml ps redis');

            return self::FAILURE;
        }

        // 2) Round-trip a test key
        $this->line('2) Round-trip test...');
        $key = 'mbfd:activate-redis:probe:'.uniqid('', true);
        $value = 'probe-'.now()->toIso8601String();
        try {
            Redis::setex($key, 10, $value);
            $back = Redis::get($key);
            Redis::del($key);
            if ($back === $value) {
                $this->info('   ✓ Read/write round-trip OK');
            } else {
                $this->error("   ✗ Round-trip mismatch — wrote [{$value}], got back [{$back}]");

                return self::FAILURE;
            }
        } catch (\Throwable $e) {
            $this->error("   ✗ Round-trip failed: {$e->getMessage()}");

            return self::FAILURE;
        }

        // 3) Show what's currently configured
        $this->line('3) Current driver configuration:');
        $current = [
            'CACHE_STORE' => config('cache.default'),
            'QUEUE_CONNECTION' => config('queue.default'),
            'SESSION_DRIVER' => config('session.driver'),
            'BROADCAST_CONNECTION' => config('broadcasting.default'),
        ];
        foreach ($current as $envKey => $value) {
            $isRedis = $value === 'redis' || $value === 'reverb';
            $marker = $isRedis ? '✓' : ' ';
            $this->line(sprintf('   %s %-22s = %s', $marker, $envKey, $value));
        }

        // 4) Verify cache.php has redis store wired
        $this->newLine();
        $this->line('4) Cache config sanity:');
        $cacheStores = array_keys(config('cache.stores', []));
        if (in_array('redis', $cacheStores, true)) {
            $this->info('   ✓ cache.stores.redis is configured');
        } else {
            $this->error('   ✗ cache.stores.redis is NOT configured (config/cache.php)');

            return self::FAILURE;
        }

        // 5) Final guidance
        $this->newLine();
        $needsFlip = collect($current)
            ->filter(fn ($v) => $v !== 'redis' && $v !== 'reverb')
            ->keys();

        if ($needsFlip->isEmpty()) {
            $this->info('✓ All four drivers already point at redis/reverb. No changes required.');

            return self::SUCCESS;
        }

        $this->warn('Redis is reachable. To complete activation, add to the production .env:');
        $this->newLine();
        $envBlock = [
            'CACHE_STORE' => 'redis',
            'QUEUE_CONNECTION' => 'redis',
            'SESSION_DRIVER' => 'redis',
            'BROADCAST_DRIVER' => 'reverb',
            'BROADCAST_CONNECTION' => 'reverb',
        ];
        foreach ($envBlock as $k => $v) {
            if (in_array($k, $needsFlip->all(), true)
                || ($k === 'BROADCAST_DRIVER' && $current['BROADCAST_CONNECTION'] !== 'reverb')
                || ($k === 'BROADCAST_CONNECTION' && $current['BROADCAST_CONNECTION'] !== 'reverb')) {
                $this->line("   {$k}={$v}");
            }
        }
        $this->newLine();
        $this->warn('Then run: php artisan config:cache && php artisan queue:restart');
        $this->warn('See docs/REDIS_ACTIVATION.md for the full procedure + rollback.');

        return self::SUCCESS;
    }
}
