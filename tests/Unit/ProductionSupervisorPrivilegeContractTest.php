<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class ProductionSupervisorPrivilegeContractTest extends TestCase
{
    public function test_immutable_image_uses_a_non_switching_supervisor_configuration(): void
    {
        $dockerfile = file_get_contents(__DIR__.'/../../docker/production/Dockerfile');
        $supervisor = file_get_contents(__DIR__.'/../../docker/production/supervisord.conf');

        $this->assertIsString($dockerfile);
        $this->assertIsString($supervisor);
        $this->assertStringContainsString(
            'COPY docker/production/supervisord.conf /etc/supervisor/conf.d/supervisord.conf',
            $dockerfile
        );
        $this->assertDoesNotMatchRegularExpression('/^user\s*=/mi', $supervisor);
        $this->assertStringContainsString('[program:php]', $supervisor);
        $this->assertStringContainsString('[program:queue-worker]', $supervisor);
        $this->assertStringContainsString('[program:reverb]', $supervisor);
        $this->assertStringContainsString('[program:scheduler]', $supervisor);
    }
}
