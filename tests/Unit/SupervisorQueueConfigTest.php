<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class SupervisorQueueConfigTest extends TestCase
{
    public function test_queue_worker_uses_configured_queue_connection_and_container_stdout(): void
    {
        $config = file_get_contents(dirname(__DIR__, 2).'/docker/supervisor/supervisord.conf');

        $this->assertIsString($config);
        $this->assertStringContainsString('queue:work --sleep=3 --tries=3 --max-time=3600 --queue=operational-forms,notifications,default', $config);
        $this->assertStringNotContainsString('queue:work database', $config);
        $this->assertStringContainsString('stdout_logfile=/dev/stdout', $config);
        $this->assertStringContainsString('stdout_logfile_maxbytes=0', $config);
    }
}
