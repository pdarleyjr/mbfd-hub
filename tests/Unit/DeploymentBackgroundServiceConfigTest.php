<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class DeploymentBackgroundServiceConfigTest extends TestCase
{
    public function test_reverb_is_supervised_and_deployment_checks_supervisor_state(): void
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
        $this->assertStringContainsString('supervisorctl -c /etc/supervisor/conf.d/supervisord.conf status', $workflow);
        $this->assertStringContainsString("grep -Eq '^php +RUNNING '", $workflow);
        $this->assertStringContainsString("grep -Eq '^queue-worker:queue-worker_00 +RUNNING '", $workflow);
        $this->assertStringContainsString("grep -Eq '^reverb +RUNNING '", $workflow);
        $this->assertStringNotContainsString("pgrep -af '^/usr/bin/php /var/www/html/artisan reverb:start '", $workflow);
        $this->assertStringNotContainsString("pgrep -af '^/usr/bin/php /var/www/html/artisan queue:work '", $workflow);
        $this->assertStringNotContainsString('pgrep -f \"reverb:start\" ||', $workflow);
    }
}
