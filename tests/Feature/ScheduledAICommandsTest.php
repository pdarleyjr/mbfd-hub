<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ProjectPriority;
use App\Enums\ProjectStatus;
use App\Models\CapitalProject;
use App\Services\CloudflareAIService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

class ScheduledAICommandsTest extends TestCase
{
    use RefreshDatabase;

    public function test_analyze_project_priorities_passes_collection_and_handles_priorities_shape(): void
    {
        $project = $this->createCapitalProject(ProjectPriority::Medium);

        $aiService = Mockery::mock(CloudflareAIService::class);
        $aiService->shouldReceive('canMakeRequest')->once()->andReturnTrue();
        $aiService->shouldReceive('prioritizeProjects')
            ->once()
            ->with(Mockery::on(fn ($projects) => $projects instanceof Collection && $projects->contains('id', $project->id)))
            ->andReturn([
                'priorities' => [[
                    'id' => $project->id,
                    'rank' => 4,
                    'score' => 82,
                    'risk_level' => 'medium',
                ]],
                'summary' => 'Test prioritization summary.',
                'model' => 'test-model',
                'tokens_used' => 123,
            ]);

        $this->app->instance(CloudflareAIService::class, $aiService);

        $this->artisan('projects:analyze-priorities')->assertExitCode(0);

        $project->refresh();
        $this->assertSame(4, $project->ai_priority_rank);
        $this->assertSame(82, $project->ai_priority_score);
        $this->assertSame('medium', $project->ai_reasoning);
        $this->assertDatabaseHas('ai_analysis_logs', [
            'type' => 'priority_ranking',
            'projects_analyzed' => 1,
        ]);
    }

    public function test_weekly_summary_passes_collection_to_ai_service(): void
    {
        $project = $this->createCapitalProject(ProjectPriority::Medium);

        $aiService = Mockery::mock(CloudflareAIService::class);
        $aiService->shouldReceive('canMakeRequest')->once()->andReturnTrue();
        $aiService->shouldReceive('generateWeeklySummary')
            ->once()
            ->with(Mockery::on(fn ($projects) => $projects instanceof Collection && $projects->contains('id', $project->id)))
            ->andReturn('Weekly AI summary.');

        $this->app->instance(CloudflareAIService::class, $aiService);

        $this->artisan('projects:weekly-summary')->assertExitCode(0);
    }

    public function test_analyze_priorities_succeeds_and_keeps_priorities_when_gateway_returns_503(): void
    {
        $this->useGatewayReturning(503);
        $project = $this->createCapitalProject(ProjectPriority::Medium);
        DB::table('capital_projects')->where('id', $project->id)->update([
            'ai_priority_rank' => 2,
            'ai_priority_score' => 70,
            'ai_reasoning' => 'previous',
        ]);
        Log::spy();

        $this->artisan('projects:analyze-priorities')->assertExitCode(0);

        $project->refresh();
        $this->assertSame(2, $project->ai_priority_rank);
        $this->assertSame(70, $project->ai_priority_score);
        $this->assertSame('previous', $project->ai_reasoning);
        Log::shouldNotHaveReceived('error');
        Log::shouldHaveReceived('warning')->withArgs(
            fn (string $message): bool => str_contains($message, 'projects:analyze-priorities skipped - AI service unavailable'),
        )->once();
    }

    public function test_analyze_priorities_still_fails_on_unexpected_errors(): void
    {
        $this->createCapitalProject(ProjectPriority::Medium);
        $aiService = Mockery::mock(CloudflareAIService::class);
        $aiService->shouldReceive('canMakeRequest')->once()->andReturnTrue();
        $aiService->shouldReceive('prioritizeProjects')->once()->andThrow(new \LogicException('bug'));
        $this->app->instance(CloudflareAIService::class, $aiService);

        $this->artisan('projects:analyze-priorities')->assertExitCode(1);
    }

    public function test_weekly_summary_is_sent_without_ai_insights_when_gateway_returns_503(): void
    {
        $this->useGatewayReturning(503);
        $this->createCapitalProject(ProjectPriority::Medium);
        Log::spy();

        $this->artisan('projects:weekly-summary')->assertExitCode(0);

        Log::shouldNotHaveReceived('error');
        Log::shouldHaveReceived('info')->withArgs(
            fn (string $message): bool => $message === 'Weekly summary generated',
        )->once();
    }

    private function useGatewayReturning(int $status): void
    {
        config()->set('cloudflare.ai.driver', 'gateway');
        config()->set('cloudflare.ai.gateway.url', 'http://gateway.test:11440');
        config()->set('cloudflare.ai.gateway.capability', 'mbfd-general');
        config()->set('cloudflare.ai.gateway.credential', 'gateway-test-secret');
        Http::preventStrayRequests();
        Http::fake(['http://gateway.test:11440/*' => Http::response('unavailable', $status)]);
    }

    private function createCapitalProject(ProjectPriority $priority): CapitalProject
    {
        $now = now();
        $id = DB::table('capital_projects')->insertGetId([
            'project_number' => 'CP-'.$now->format('Hisv').'-'.$priority->value,
            'name' => 'Scheduled AI Regression Project',
            'description' => 'Regression test project.',
            'budget_amount' => 100000,
            'status' => ProjectStatus::InProgress->value,
            'priority' => $priority->value,
            'start_date' => $now->toDateString(),
            'target_completion_date' => $now->copy()->addMonth()->toDateString(),
            'actual_completion' => null,
            'percent_complete' => 25,
            'notes' => null,
            'attachments' => json_encode([]),
            'station_id' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return CapitalProject::query()->findOrFail($id);
    }
}
