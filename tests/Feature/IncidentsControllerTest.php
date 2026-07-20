<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class IncidentsControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config()->set('services.pulsepoint.worker_url', 'https://pulsepoint.test/incidents');
    }

    public function test_it_caches_a_valid_incident_feed(): void
    {
        Http::fake(['https://pulsepoint.test/incidents' => Http::response([
            'active' => [['id' => 'active-1']],
            'recent' => [],
            'fetchedAt' => now()->toISOString(),
        ])]);

        $this->getJson('/api/incidents')
            ->assertOk()
            ->assertHeader('X-Data-Source', 'pulsepoint-proxy')
            ->assertHeader('X-Data-Stale', 'false')
            ->assertJsonPath('active.0.id', 'active-1');

        $this->getJson('/api/incidents')
            ->assertOk()
            ->assertHeader('X-Data-Source', 'pulsepoint-cache');

        Http::assertSentCount(1);
    }

    public function test_it_serves_last_known_good_data_when_the_worker_times_out(): void
    {
        Cache::put('pulsepoint_incidents_last_good', [
            'data' => ['active' => [], 'recent' => [['id' => 'recent-1']]],
            'stored_at' => '2026-07-20T08:00:00-04:00',
        ], 3600);
        Http::fakeSequence()->pushStatus(503)->pushStatus(503);
        Log::spy();

        $this->getJson('/api/incidents')
            ->assertOk()
            ->assertHeader('X-Data-Source', 'pulsepoint-last-known-good')
            ->assertHeader('X-Data-Stale', 'true')
            ->assertJsonPath('recent.0.id', 'recent-1')
            ->assertJsonPath('stale', true);

        Http::assertSentCount(2);
        Log::shouldHaveReceived('warning')->once()->withArgs(
            fn (string $message, array $context): bool => $message === 'PulsePoint fetch failed.'
                && $context['failure_code'] === 'worker_http_503'
                && $context['consecutive_failures'] === 1,
        );
    }

    public function test_it_opens_the_circuit_after_sustained_failures(): void
    {
        Http::fake(fn () => Http::response([], 503));

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->getJson('/api/incidents')->assertServiceUnavailable();
            Cache::forget('pulsepoint_incidents_failure_alert');
        }

        $requestsBeforeCircuitProbe = count(Http::recorded());
        $this->getJson('/api/incidents')->assertServiceUnavailable();

        $this->assertSame($requestsBeforeCircuitProbe, count(Http::recorded()));
        $this->assertGreaterThan(0, (int) Cache::get('pulsepoint_incidents_circuit_until'));
    }
}
