<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Resources\Under25kProjectResource;
use App\Models\Under25kProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/** The API resource reads the real under_25k_projects columns. */
final class Under25kProjectApiResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_resource_exposes_stored_project_values(): void
    {
        $project = Under25kProject::query()->create([
            'project_number' => 'U25-7', 'name' => 'Bay door seals', 'budget_amount' => 1200,
            'spend_amount' => 300, 'target_completion_date' => '2026-11-01',
            'actual_completion_date' => '2026-10-20', 'project_manager' => 'Lt. Rivera',
        ]);

        $data = (new Under25kProjectResource($project->fresh()))->toArray(Request::create('/'));

        $this->assertSame('Bay door seals', $data['title']);
        $this->assertSame('Lt. Rivera', $data['project_lead']);
        $this->assertSame('2026-10-20', $data['actual_completion']?->toDateString());
        $this->assertSame('2026-11-01', $data['estimated_completion']?->toDateString());
        $this->assertEquals(1200, $data['budget']);
        $this->assertEquals(300, $data['spent']);
    }
}
