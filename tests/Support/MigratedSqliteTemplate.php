<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Gives file-backed SQLite candidate databases their schema without re-running every
 * migration for each test.
 *
 * The first request in a test process runs the real `migrate:fresh` against the
 * connection configured exactly as the test configured it, into a private template
 * file. Every request copies that already-migrated file to the test's own database
 * file, so each test still starts from an independent, fully migrated database.
 */
final class MigratedSqliteTemplate
{
    /** @var array<string, string> template file by connection name and configuration hash */
    private static array $templates = [];

    private static bool $shutdownRegistered = false;

    /**
     * Populate $targetDatabase (the file the connection already points at) with the migrated schema.
     */
    public static function populate(string $connection, string $targetDatabase): void
    {
        $config = config('database.connections.'.$connection);
        if (! is_array($config) || ($config['driver'] ?? null) !== 'sqlite') {
            throw new RuntimeException("Connection [{$connection}] must be a configured SQLite connection.");
        }

        $key = $connection.'|'.hash('sha256', serialize(array_diff_key($config, ['database' => true])));
        if (! isset(self::$templates[$key])) {
            self::$templates[$key] = self::build($connection, $config);
        }

        DB::disconnect($connection);
        if (! copy(self::$templates[$key], $targetDatabase)) {
            throw new RuntimeException('Could not copy the migrated SQLite template.');
        }
        DB::purge($connection);
    }

    /** @param array<string, mixed> $config */
    private static function build(string $connection, array $config): string
    {
        $template = tempnam(sys_get_temp_dir(), 'mbfd-migrated-template-');
        if ($template === false) {
            throw new RuntimeException('Could not create the migrated SQLite template.');
        }

        if (! self::$shutdownRegistered) {
            self::$shutdownRegistered = true;
            register_shutdown_function(static function (): void {
                foreach (self::$templates as $path) {
                    if (is_file($path)) {
                        @unlink($path);
                    }
                }
            });
        }

        try {
            config()->set('database.connections.'.$connection, array_merge($config, ['database' => $template]));
            DB::purge($connection);
            $status = Artisan::call('migrate:fresh', ['--database' => $connection, '--force' => true]);
            DB::disconnect($connection);
            if ($status !== 0) {
                throw new RuntimeException('Migrating the SQLite template failed: '.Artisan::output());
            }
        } catch (\Throwable $exception) {
            @unlink($template);
            throw $exception;
        } finally {
            config()->set('database.connections.'.$connection, $config);
            DB::purge($connection);
        }

        return $template;
    }
}
