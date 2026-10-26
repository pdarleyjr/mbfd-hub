<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\SingleGasMeterResource\Pages\ListSingleGasMeters;
use App\Filament\Resources\StationResource;
use App\Filament\Resources\StationResource\RelationManagers\SingleGasMetersRelationManager;
use App\Models\Apparatus;
use App\Models\CapitalProject;
use App\Models\ProjectMilestone;
use App\Models\SingleGasMeter;
use App\Models\Station;
use App\Models\Under25kProject;
use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Tests\Feature\Exports\ExportTestCase;

final class CalendarDateBoundariesTest extends ExportTestCase
{
    public function test_project_scopes_and_scheduled_commands_preserve_due_day_rules_on_the_eastern_day(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-26T00:30:00Z'));
        $projects = [];
        foreach (['2026-10-24', '2026-10-26'] as $index => $day) {
            $projects[] = CapitalProject::factory()->inProgress()->create(['target_completion_date' => $day]);
            Under25kProject::create(['project_number' => 'FIRE-DATE-'.$index, 'name' => 'Calendar fixture', 'target_completion_date' => $day]);
            ProjectMilestone::create(['capital_project_id' => $projects[$index]->id, 'title' => 'Calendar milestone '.$index, 'due_date' => $day, 'completed' => false]);
        }

        self::assertSame([$projects[0]->id], CapitalProject::query()->overdue()->pluck('id')->all());
        self::assertSame(['FIRE-DATE-0'], Under25kProject::query()->overdue()->pluck('project_number')->all());
        self::assertSame(['Calendar milestone 0'], ProjectMilestone::query()->overdue()->pluck('title')->all());
        $this->artisan('projects:check-overdue')->expectsOutputToContain('Found 1 overdue projects')->expectsOutputToContain('Found 1 overdue milestones')->assertSuccessful();
        $this->artisan('projects:milestone-reminders')->expectsOutputToContain('Found 1 milestones due in 1 days')->assertSuccessful();
    }

    public function test_meter_list_and_station_filters_use_eastern_calendar_bounds_without_expiring_tomorrow_early(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-26T00:30:00Z'));
        $this->actingAs($this->admin);
        $station = Station::create(['station_number' => 1, 'name' => 'Calendar station', 'address' => 'Test fixture', 'is_active' => true]);
        $unit = Apparatus::create(['station_id' => $station->id, 'unit_id' => 'TZ-METER', 'name' => 'Meter fixture', 'slug' => 'tz-meter', 'type' => 'Engine', 'status' => 'In Service']);
        $meters = [];
        foreach (['2026-10-24', '2026-10-26', '2027-01-24'] as $index => $day) {
            $meters[] = SingleGasMeter::create(['apparatus_id' => $unit->id, 'serial_number' => 'TZ-'.$index, 'activation_date' => CarbonImmutable::parse($day)->subYears(2)->toDateString()]);
        }

        $list = Livewire::test(ListSingleGasMeters::class)->call('loadTable');
        $relation = Livewire::test(SingleGasMetersRelationManager::class, ['ownerRecord' => $station, 'pageClass' => StationResource::getPages()['view']->getPage()])->call('loadTable');
        foreach ([$list, $relation] as $table) {
            $table->filterTable('expired')->assertCanSeeTableRecords([$meters[0]])->assertCanNotSeeTableRecords([$meters[1], $meters[2]]);
            $table->removeTableFilter('expired')->filterTable('expiring_soon')->assertCanSeeTableRecords([$meters[1]])->assertCanNotSeeTableRecords([$meters[0], $meters[2]]);
        }
    }
}
