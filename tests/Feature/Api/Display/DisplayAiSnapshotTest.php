<?php

declare(strict_types=1);

namespace Tests\Feature\Api\Display;

use App\Jobs\GenerateDisplayAiSnapshotJob;
use App\Models\Apparatus;
use App\Models\Station;
use App\Services\CloudflareAIService;
use App\Services\Display\DisplayAiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The AI brief is descriptive-only, generated out-of-band, and never contains
 * prescriptive/imperative language. The endpoint returns 202 while no brief is
 * cached and 200 with the descriptive schema once generated.
 */
class DisplayAiSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private function seedFleet(): void
    {
        $station = Station::create([
            'station_number' => 2,
            'name' => 'Station 2',
            'address' => '451 Dade Blvd',
            'is_active' => true,
        ]);

        Apparatus::create([
            'unit_id' => 'E2',
            'station_id' => $station->id,
            'designation' => 'Engine 2',
            'make' => 'Pierce',
            'model' => 'Enforcer',
            'year' => 2020,
            'status' => 'In Service',
        ]);
    }

    public function test_returns_202_generating_when_no_cache_and_job_is_queued_not_run(): void
    {
        $this->seedFleet();
        Queue::fake();

        $response = $this->getJson('/api/display/ai-snapshot');

        $response->assertStatus(202)
            ->assertJson(['status' => 'generating']);

        // The request path must NOT call the LLM inline; it only dispatches.
        Queue::assertPushed(GenerateDisplayAiSnapshotJob::class);
    }

    public function test_returns_descriptive_schema_when_brief_is_cached(): void
    {
        $this->seedFleet();

        // Bind a fake AI service that returns valid descriptive JSON.
        $this->app->bind(CloudflareAIService::class, fn () => new FakeDescriptiveAiService);

        // First call dispatches the job; with the default sync queue the job
        // runs immediately and populates the cache.
        $this->getJson('/api/display/ai-snapshot');

        // Second call (same data => same fingerprint) returns the fresh brief.
        $response = $this->getJson('/api/display/ai-snapshot');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'brief' => [
                    'mode',
                    'briefing',
                    'station_summaries' => [['station', 'summary']],
                    'active_run_summary',
                    'camera_source_summary',
                    'data_gaps',
                    'confidence',
                    'generated_at',
                    'model',
                ],
                'generated_at',
            ]);

        $brief = $response->json('brief');
        $this->assertSame('descriptive', $brief['mode']);
        $this->assertSame('qwen3.6:35b', $brief['model']);
    }

    public function test_forbidden_words_are_stripped_from_output(): void
    {
        $this->seedFleet();

        $this->app->bind(CloudflareAIService::class, fn () => new FakePrescriptiveAiService);

        $this->getJson('/api/display/ai-snapshot');
        $response = $this->getJson('/api/display/ai-snapshot');

        $response->assertStatus(200);
        $brief = $response->json('brief');

        $haystack = strtolower(json_encode($brief) ?: '');
        foreach (DisplayAiService::FORBIDDEN_WORDS as $word) {
            $this->assertStringNotContainsString(
                strtolower($word),
                $haystack,
                "Forbidden word '{$word}' must be stripped from the brief"
            );
        }
    }

    public function test_enforce_descriptive_lowers_confidence_when_guard_fires(): void
    {
        $cleaned = DisplayAiService::enforceDescriptive([
            'mode' => 'descriptive',
            'briefing' => 'Station 2 is staffed. Crews should refuel the engine.',
            'station_summaries' => [],
            'active_run_summary' => '',
            'camera_source_summary' => '',
            'data_gaps' => [],
            'confidence' => 0.9,
            'generated_at' => now()->toISOString(),
            'model' => 'qwen3.6:35b',
        ]);

        $this->assertStringNotContainsString('should', strtolower($cleaned['briefing']));
        $this->assertStringContainsString('Station 2 is staffed', $cleaned['briefing']);
        $this->assertLessThan(0.9, $cleaned['confidence']);
    }
}

/**
 * Test double for the local/Cloudflare AI service: returns valid descriptive
 * JSON in the Cloudflare ['result']['response'] shape.
 */
class FakeDescriptiveAiService extends CloudflareAIService
{
    public function isEnabled(): bool
    {
        return true;
    }

    public function checkRateLimit(): bool
    {
        return true;
    }

    public function runModel(string $model, array $messages, array $options = []): array
    {
        $json = json_encode([
            'mode' => 'descriptive',
            'briefing' => 'The department shows one active station with one in-service engine.',
            'station_summaries' => [
                ['station' => 'Station 2', 'summary' => 'One engine in service, no open defects.'],
            ],
            'active_run_summary' => 'No active incidents are present in the snapshot.',
            'camera_source_summary' => 'Camera metadata is reported at territory scope only.',
            'data_gaps' => ['Incident feed status was not available in the snapshot.'],
            'confidence' => 0.8,
            'generated_at' => now()->toISOString(),
            'model' => 'qwen3.6:35b',
        ]);

        return ['result' => ['response' => $json]];
    }
}

/**
 * Test double that returns prescriptive language to exercise the guard.
 */
class FakePrescriptiveAiService extends CloudflareAIService
{
    public function isEnabled(): bool
    {
        return true;
    }

    public function checkRateLimit(): bool
    {
        return true;
    }

    public function runModel(string $model, array $messages, array $options = []): array
    {
        $json = json_encode([
            'mode' => 'descriptive',
            'briefing' => 'One engine is in service. Crews should refuel it and must inspect the ladder.',
            'station_summaries' => [
                ['station' => 'Station 2', 'summary' => 'Engine present. We recommend a follow-up audit.'],
            ],
            'active_run_summary' => 'No active runs. Dispatch need to monitor the area.',
            'camera_source_summary' => 'Camera metadata reported at territory scope.',
            'data_gaps' => [],
            'confidence' => 0.95,
            'generated_at' => now()->toISOString(),
            'model' => 'qwen3.6:35b',
        ]);

        return ['result' => ['response' => $json]];
    }
}
