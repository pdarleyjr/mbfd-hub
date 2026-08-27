<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\DailyCheckoutChecklistTemplate;
use App\Models\Apparatus;
use App\Models\Station;
use Illuminate\Console\Command;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class AuditDailyCheckoutReadinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_passes_for_a_required_standard_family_apparatus_and_reports_its_pending_template_transparently(): void
    {
        $apparatus = $this->makeApparatus('required', 'engine-3', [
            'unit_id' => 'E3',
            'name' => 'Engine 3',
            'designation' => 'E3',
        ]);

        $this->assertSame(DailyCheckoutChecklistTemplate::Pending, $apparatus->fresh()->daily_checkout_template);

        [$status, $report] = $this->audit();

        $this->assertSame(Command::SUCCESS, $status);
        $this->assertTrue($report['gate_passed']);
        $this->assertSame('pending', $report['apparatus'][0]['daily_checkout_template']);
        $this->assertSame('family', $report['apparatus'][0]['resolution_source']);
        $this->assertSame('/daily/vehicle-inspections/engine-3', $report['apparatus'][0]['checkout_url']);
        $this->assertNull($report['apparatus'][0]['ambiguity']);
        $this->assertSame(1, $report['daily_checkout']['required_total']);
        $this->assertSame(0, $report['daily_checkout']['completed']);
        $this->assertSame('not_checked', $report['apparatus'][0]['daily_checkout']['state']);
        $this->assertTrue($report['apparatus'][0]['daily_checkout']['included_in_required_total']);
    }

    public function test_it_blocks_deployment_when_an_apparatus_has_not_been_classified_by_an_authorized_owner(): void
    {
        $this->makeApparatus('unknown');

        $this->artisan('daily-checkout:audit', ['--json' => true])
            ->expectsOutputToContain('daily_checkout_requirement_unknown')
            ->assertExitCode(Command::FAILURE);
    }

    public function test_it_reports_the_pre_migration_schema_as_a_read_only_policy_blocker(): void
    {
        $this->makeApparatus('required');

        Schema::table('apparatuses', function (Blueprint $table): void {
            $table->dropIndex(['daily_checkout_requirement']);
            $table->dropIndex(['daily_checkout_template']);
            $table->dropColumn(['daily_checkout_requirement', 'daily_checkout_template']);
        });

        [$status, $report] = $this->audit();

        $this->assertSame(Command::FAILURE, $status);
        $this->assertFalse($report['schema']['daily_checkout_requirement_column_present']);
        $this->assertFalse($report['schema']['daily_checkout_template_column_present']);
        $this->assertSame('unknown', $report['apparatus'][0]['daily_checkout_requirement']);
        $this->assertNull($report['apparatus'][0]['checkout_url']);
        $this->assertContains('daily_checkout_requirement_schema_absent', $report['apparatus'][0]['issues']);
        $this->assertContains('daily_checkout_template_schema_absent', $report['apparatus'][0]['issues']);
    }

    public function test_it_fails_closed_when_the_operational_status_ledger_schema_is_absent(): void
    {
        $this->makeApparatus('required');
        Schema::dropIfExists('apparatus_operational_status_events');

        [$status, $report] = $this->audit();

        $this->assertSame(Command::FAILURE, $status);
        $this->assertFalse($report['schema']['apparatus_operational_status_ledger_present']);
        $this->assertNull($report['daily_checkout']);
        $this->assertContains('apparatus_operational_status_ledger_schema_absent', $report['apparatus'][0]['issues']);
    }

    public function test_it_blocks_deployment_when_a_required_apparatus_has_no_routable_daily_checkout_slug(): void
    {
        $this->makeApparatus('required', null);

        $this->artisan('daily-checkout:audit', ['--json' => true])
            ->expectsOutputToContain('required_apparatus_slug_missing')
            ->assertExitCode(Command::FAILURE);
    }

    public function test_it_blocks_deployment_when_a_required_specialty_apparatus_has_no_explicit_template(): void
    {
        $this->makeApparatus('required', 'air-1', [
            'unit_id' => 'AIR1',
            'name' => 'Air Truck 1',
            'type' => 'Air Truck',
            'class_description' => 'Air Support',
            'designation' => 'Air 1',
        ]);

        $this->artisan('daily-checkout:audit', ['--json' => true])
            ->expectsOutputToContain('required_apparatus_checklist_specialty_pending')
            ->assertExitCode(Command::FAILURE);
    }

    public function test_it_accepts_an_explicit_approved_template_for_a_required_specialty_apparatus(): void
    {
        $this->makeApparatus('required', 'air-1', [
            'unit_id' => 'AIR1',
            'name' => 'Air Truck 1',
            'type' => 'Air Truck',
            'class_description' => 'Air Support',
            'designation' => 'Air 1',
            'daily_checkout_template' => DailyCheckoutChecklistTemplate::Rescue->value,
        ]);

        [$status, $report] = $this->audit();

        $this->assertSame(Command::SUCCESS, $status);
        $this->assertTrue($report['gate_passed']);
        $this->assertSame('configured_template', $report['apparatus'][0]['resolution_source']);
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

    /** @return array{int, array<string, mixed>} */
    private function audit(): array
    {
        $status = Artisan::call('daily-checkout:audit', ['--json' => true]);
        /** @var array<string, mixed> $report */
        $report = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        return [$status, $report];
    }
}
