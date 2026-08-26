<?php

declare(strict_types=1);

namespace Tests\Feature;

use Symfony\Component\Process\Process as SymfonyProcess;
use Tests\TestCase;

final class DisposablePostgresBootstrapGuardTest extends TestCase
{
    public function test_disposable_postgres_opt_in_rejects_a_non_loopback_target_before_laravel_boots(): void
    {
        $process = $this->runBootstrap([
            'DISPOSABLE_POSTGRES_HOST' => '192.0.2.10',
            'DISPOSABLE_POSTGRES_PORT' => '55432',
            'DISPOSABLE_POSTGRES_DATABASE' => 'mbfd_hub_test_guard',
            'DISPOSABLE_POSTGRES_USERNAME' => 'mbfd_test_guard',
        ]);

        $this->assertFalse($process->isSuccessful());
        $this->assertStringContainsString('loopback-only', $process->getErrorOutput());
    }

    public function test_disposable_postgres_opt_in_rejects_a_non_disposable_database_before_laravel_boots(): void
    {
        $process = $this->runBootstrap([
            'DISPOSABLE_POSTGRES_HOST' => '127.0.0.1',
            'DISPOSABLE_POSTGRES_PORT' => '55432',
            'DISPOSABLE_POSTGRES_DATABASE' => 'mbfd_hub_production',
            'DISPOSABLE_POSTGRES_USERNAME' => 'mbfd_test_guard',
        ]);

        $this->assertFalse($process->isSuccessful());
        $this->assertStringContainsString('dedicated mbfd_hub_test_* database', $process->getErrorOutput());
    }

    /** @param array<string, string> $environment */
    private function runBootstrap(array $environment): SymfonyProcess
    {
        $process = new SymfonyProcess(
            [
                PHP_BINARY,
                '-r',
                "require 'tests/bootstrap.php';",
            ],
            base_path(),
            array_merge([
                'MBFD_ALLOW_DISPOSABLE_POSTGRES' => '1',
                'SystemRoot' => (string) getenv('SystemRoot'),
                'WINDIR' => (string) getenv('WINDIR'),
            ], $environment),
        );

        $process->run();

        return $process;
    }
}
