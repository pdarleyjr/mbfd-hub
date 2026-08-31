<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\HealthReadinessController;
use App\Services\Health\ReadinessProbe;
use Illuminate\Support\Facades\Route;
use Mockery;
use Tests\TestCase;

final class HealthEndpointTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::get('/health', HealthReadinessController::class)->name('health.readiness');
    }

    public function test_up_remains_liveness_and_health_reports_ready_without_diagnostics(): void
    {
        $probe = Mockery::mock(ReadinessProbe::class);
        $probe->shouldReceive('check')->once()->andReturn(['database' => true, 'redis' => true]);
        $this->app->instance(ReadinessProbe::class, $probe);

        $this->get('/up')->assertOk();
        $this->getJson('/health')
            ->assertOk()
            ->assertExactJson(['status' => 'ok']);
    }

    public function test_health_returns_service_unavailable_when_a_required_dependency_is_unhealthy(): void
    {
        $probe = Mockery::mock(ReadinessProbe::class);
        $probe->shouldReceive('check')->once()->andReturn(['database' => false, 'redis' => true]);
        $this->app->instance(ReadinessProbe::class, $probe);

        $this->getJson('/health')
            ->assertServiceUnavailable()
            ->assertExactJson(['status' => 'degraded']);
    }
}
