<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Workgroup\WorkgroupAIService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TestEnvironmentIsolationTest extends TestCase
{
    public function test_known_external_integrations_are_disabled_in_phpunit(): void
    {
        $this->assertEmpty(config('services.screentinker.sync_url'));
        $this->assertEmpty(config('services.screentinker.sync_token'));
        $this->assertFalse(app(WorkgroupAIService::class)->isEnabled());
        $this->assertTrue(Http::getFacadeRoot()->preventingStrayRequests());
    }
}
