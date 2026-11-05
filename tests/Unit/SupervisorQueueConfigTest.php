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

    public function test_production_image_runs_a_separate_ai_automation_worker_within_the_retry_window(): void
    {
        $config = file_get_contents(dirname(__DIR__, 2).'/docker/production/supervisord.conf');

        $this->assertIsString($config);
        $this->assertStringContainsString('[program:ai-automation-worker]', $config);
        $this->assertStringContainsString('queue:work --queue=ai-automation,ai-backlog --sleep=3 --tries=1 --timeout=80', $config);
        // The main worker keeps its queues; AI work never shares a process with operational forms.
        $this->assertStringContainsString('--queue=operational-forms,notifications,default', $config);

        // Worker timeout must stay below every connection's retry_after so a slow job is never run twice.
        $queue = file_get_contents(dirname(__DIR__, 2).'/config/queue.php');
        $this->assertIsString($queue);
        $this->assertMatchesRegularExpression("/REDIS_QUEUE_RETRY_AFTER', (\\d+)/", $queue);
        preg_match("/REDIS_QUEUE_RETRY_AFTER', (\\d+)/", $queue, $redis);
        preg_match("/DB_QUEUE_RETRY_AFTER', (\\d+)/", $queue, $database);
        $this->assertGreaterThan(80, (int) $redis[1]);
        $this->assertGreaterThan(80, (int) $database[1]);
    }
}
