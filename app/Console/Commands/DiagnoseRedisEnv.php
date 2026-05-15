<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;
use Redis as PhpRedis;

/**
 * Read-only diagnostic for the production NOAUTH puzzle.
 *
 *   docker exec mbfd-hub-laravel php artisan mbfd:diagnose-redis
 *
 * Prints redacted info about REDIS_PASSWORD as seen from three sources:
 *   1. env()         — what phpdotenv parsed from .env at boot
 *   2. config()      — what Laravel cached in config/database.php
 *   3. .env file     — raw line bytes (length only)
 *
 * Then attempts an explicit phpredis AUTH using each source's value, so we can
 * see exactly which path mints the wrong password. Never prints the actual
 * password — only lengths, first/last byte ord, and pass/fail outcomes.
 */
class DiagnoseRedisEnv extends Command
{
    /** @var string */
    protected $signature = 'mbfd:diagnose-redis';

    /** @var string */
    protected $description = 'Compare REDIS_PASSWORD as env() / config() / .env bytes see it, then test phpredis AUTH';

    public function handle(): int
    {
        $this->info('=== MBFD Redis Env Diagnostic (redacted) ===');
        $this->newLine();

        $report = function (string $label, ?string $value) {
            if ($value === null || $value === '') {
                $this->line("  {$label}: <empty / null>");

                return;
            }
            $this->line(sprintf(
                '  %s: length=%d, first ord=%d, last ord=%d',
                $label,
                strlen($value),
                ord(substr($value, 0, 1)),
                ord(substr($value, -1)),
            ));
        };

        // 1) phpdotenv → env()
        $envPwd = env('REDIS_PASSWORD');
        $this->line('1) phpdotenv env():');
        $report('  REDIS_PASSWORD', $envPwd);
        $this->line('  REDIS_HOST     : '.(env('REDIS_HOST') ?? '<unset>'));
        $this->line('  REDIS_PORT     : '.(env('REDIS_PORT') ?? '<unset>'));
        $this->line('  REDIS_USERNAME : '.(env('REDIS_USERNAME') !== null ? '[set]' : '<unset>'));
        $this->line('  REDIS_CLIENT   : '.(env('REDIS_CLIENT') ?? '<unset>'));

        $this->newLine();

        // 2) cached config
        $cfgPwd = config('database.redis.default.password');
        $this->line('2) config(database.redis.default):');
        $report('  password', $cfgPwd);
        $this->line('  host    : '.(config('database.redis.default.host') ?? '<unset>'));

        $this->newLine();

        // 3) raw .env bytes
        $envFile = base_path('.env');
        $rawPwd = null;
        if (is_readable($envFile)) {
            foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                if (str_starts_with($line, 'REDIS_PASSWORD=')) {
                    $rawPwd = substr($line, strlen('REDIS_PASSWORD='));
                    break;
                }
            }
        }
        $this->line('3) raw .env bytes (after `REDIS_PASSWORD=`):');
        $report('  raw', $rawPwd);

        // Also report what the value would be if we stripped matched outer quotes
        if ($rawPwd !== null && strlen($rawPwd) >= 2) {
            $first = substr($rawPwd, 0, 1);
            $last = substr($rawPwd, -1);
            if (($first === "'" && $last === "'") || ($first === '"' && $last === '"')) {
                $stripped = substr($rawPwd, 1, -1);
                $report('  raw (quotes stripped)', $stripped);
            }
        }

        $this->newLine();

        // 4) AUTH attempts — try each value, report which one Redis accepts
        $this->line('4) phpredis AUTH attempts (host='.env('REDIS_HOST', '127.0.0.1').'):');

        $tryAuth = function (string $label, ?string $pwd) {
            if ($pwd === null || $pwd === '') {
                $this->line("  {$label}: SKIPPED (empty)");

                return;
            }
            try {
                $r = new PhpRedis();
                $ok = @$r->connect(env('REDIS_HOST', '127.0.0.1'), (int) env('REDIS_PORT', 6379), 2.0);
                if (! $ok) {
                    $this->line("  {$label}: CONNECT FAILED");

                    return;
                }
                $authOk = @$r->auth($pwd);
                $ping = $authOk ? @$r->ping() : '<no-ping>';
                $r->close();
                $this->line(sprintf('  %s: auth=%s, ping=%s', $label, $authOk ? 'OK' : 'FAIL', $ping));
            } catch (\Throwable $e) {
                $this->line(sprintf('  %s: EXCEPTION %s', $label, $e->getMessage()));
            }
        };

        $tryAuth('env() value         ', $envPwd);
        $tryAuth('config() value      ', $cfgPwd);
        $tryAuth('.env raw value      ', $rawPwd);
        if ($rawPwd !== null && strlen($rawPwd) >= 2) {
            $first = substr($rawPwd, 0, 1);
            $last = substr($rawPwd, -1);
            if (($first === "'" && $last === "'") || ($first === '"' && $last === '"')) {
                $tryAuth('.env quotes stripped', substr($rawPwd, 1, -1));
            }
        }

        $this->newLine();
        $this->info('Diagnostic complete.');

        return self::SUCCESS;
    }
}
