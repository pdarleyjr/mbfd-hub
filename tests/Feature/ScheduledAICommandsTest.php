<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ProjectPriority;
use App\Enums\ProjectStatus;
use App\Models\CapitalProject;
use App\Services\CloudflareAIService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class ScheduledAICommandsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        putenv('DB_CONNECTION=sqlite');
        putenv('DB_DATABASE=:memory:');
        $_ENV['DB_CONNECTION'] = 'sqlite';
        $_ENV['DB_DATABASE'] = ':memory:';
        $_SERVER['DB_CONNECTION'] = 'sqlite';
        $_SERVER['DB_DATABASE'] = ':memory:';

        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        $this->ensureRoleTablesExist();
    }

    public function test_analyze_project_priorities_passes_collection_and_handles_priorities_shape(): void
    {
        $project = $this->createCapitalProject(ProjectPriority::Medium);

        $aiService = Mockery::mock(CloudflareAIService::class);
        $aiService->shouldReceive('canMakeRequest')->once()->andReturnTrue();
        $aiService->shouldReceive('prioritizeProjects')
            ->once()
            ->with(Mockery::on(fn ($projects) => $projects instanceof Collection && $projects->contains('id', $project->id)))
            ->andReturn([
                'priorities' => [
                    [
                        'id' => $project->id,
                        'rank' => 4,
                        'score' => 82,
                        'risk_level' => 'medium',
                    ],
                ],
                'summary' => 'Test prioritization summary.',
                'model' => 'test-model',
                'tokens_used' => 123,
            ]);

        $this->app->instance(CloudflareAIService::class, $aiService);

        $this->artisan('projects:analyze-priorities')
            ->assertExitCode(0);

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

        $this->artisan('projects:weekly-summary')
            ->assertExitCode(0);
    }

    private function createCapitalProject(ProjectPriority $priority): CapitalProject
    {
        $now = now();
        $id = DB::table('capital_projects')->insertGetId([
            'project_number' => 'CP-' . $now->format('Hisv') . '-' . $priority->value,
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

    private function ensureRoleTablesExist(): void
    {
        if (! Schema::hasTable('roles')) {
            Schema::create('roles', function (Blueprint $table): void {
                $table->id();
                $table->string('name');
                $table->string('guard_name')->default('web');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('model_has_roles')) {
            Schema::create('model_has_roles', function (Blueprint $table): void {
                $table->unsignedBigInteger('role_id');
                $table->string('model_type');
                $table->unsignedBigInteger('model_id');
            });
        }
    }
}
