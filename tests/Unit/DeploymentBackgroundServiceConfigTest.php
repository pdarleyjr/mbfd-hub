<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class DeploymentBackgroundServiceConfigTest extends TestCase
{
    public function test_reverb_is_supervised_and_deployment_checks_exact_processes(): void
    {
        $supervisor = file_get_contents(__DIR__.'/../../docker/supervisor/supervisord.conf');
        $workflow = file_get_contents(__DIR__.'/../../.github/workflows/deploy.yml');

        $this->assertIsString($supervisor);
        $this->assertIsString($workflow);
        $this->assertStringContainsString('[program:reverb]', $supervisor);
        $this->assertStringContainsString(
            'command=/usr/bin/php /var/www/html/artisan reverb:start --host=0.0.0.0 --port=8080 --no-interaction',
            $supervisor
        );
        $this->assertMatchesRegularExpression(
            '/\[program:reverb\].*?autostart=true.*?autorestart=true/s',
            $supervisor
        );
        $this->assertStringContainsString(
            "pgrep -af '^/usr/bin/php /var/www/html/artisan reverb:start '",
            $workflow
        );
        $this->assertStringContainsString(
            "pgrep -af '^/usr/bin/php /var/www/html/artisan queue:work '",
            $workflow
        );
        $this->assertStringNotContainsString('pgrep -f \"reverb:start\" ||', $workflow);
    }
}
