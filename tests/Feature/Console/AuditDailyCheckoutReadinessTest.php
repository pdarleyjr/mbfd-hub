<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Apparatus;
use App\Models\Station;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AuditDailyCheckoutReadinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_passes_when_each_required_apparatus_has_an_explicit_policy_slug_and_usable_checklist(): void
    {
        $this->makeApparatus('required');

        $this->artisan('daily-checkout:audit', ['--json' => true])
            ->expectsOutputToContain('"gate_passed": true')
            ->assertExitCode(Command::SUCCESS);
    }

    public function test_it_blocks_deployment_when_an_apparatus_has_not_been_classified_by_an_authorized_owner(): void
    {
        $this->makeApparatus('unknown');

        $this->artisan('daily-checkout:audit', ['--json' => true])
            ->expectsOutputToContain('daily_checkout_requirement_unknown')
            ->assertExitCode(Command::FAILURE);
    }

    public function test_it_blocks_deployment_when_a_required_apparatus_has_no_routable_daily_checkout_slug(): void
    {
        $this->makeApparatus('required', null);

        $this->artisan('daily-checkout:audit', ['--json' => true])
            ->expectsOutputToContain('required_apparatus_slug_missing')
            ->assertExitCode(Command::FAILURE);
    }

    public function test_it_blocks_deployment_when_a_required_apparatus_has_no_explicit_checklist_mapping(): void
    {
        $this->makeApparatus('required', 'engine-9', [
            'unit_id' => 'E9',
            'name' => 'Engine 9',
            'designation' => 'E9',
        ]);

        $this->artisan('daily-checkout:audit', ['--json' => true])
            ->expectsOutputToContain('required_apparatus_checklist_unmapped')
            ->assertExitCode(Command::FAILURE);
    }

    /** @param array<string, mixed> $overrides */
    private function makeApparatus(string $requirement, ?string $slug = 'engine-1', array $overrides = []): Apparatus
    {
        $station = Station::query()->create([
            'station_number' => 1,
            'name' => 'Station 1',
            'address' => '123 Main St',
            'is_active' => true,
        ]);

        return Apparatus::withoutEvents(fn (): Apparatus => Apparatus::query()->create(array_merge([
            'station_id' => $station->id,
            'unit_id' => 'E1',
            'name' => 'Engine 1',
            'type' => 'Engine',
            'vehicle_number' => 'V1',
            'designation' => 'E1',
            'slug' => $slug,
            'make' => 'Pierce',
            'model' => 'Enforcer',
            'year' => 2020,
            'status' => 'In Service',
            'daily_checkout_requirement' => $requirement,
        ], $overrides)));
    }
}
