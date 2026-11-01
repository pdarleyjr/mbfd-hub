<?php

declare(strict_types=1);

namespace Tests\Feature\Fleet\Changeouts;

use App\Models\Apparatus;
use App\Models\ApparatusChangeout;
use App\Models\ApparatusOperationalAlert;
use App\Models\User;
use App\Services\Fleet\ApparatusChangeoutPlanner;
use App\Services\Fleet\ApparatusChangeoutService;
use App\Services\Fleet\ApparatusOperationalAlertService;
use App\Support\HubTimezone;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\Fleet\Concerns\BuildsVehicleSwapFixtures;
use Tests\TestCase;

/** E2 on truck V-202, an eligible reserve truck V-909 and a frontline E3 on V-303, through the real services. */
abstract class AdminChangeoutTestCase extends TestCase
{
    use BuildsVehicleSwapFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSwapFixtures();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    protected function futureDate(int $days = 2): string
    {
        return HubTimezone::today()->addDays($days)->format('Y-m-d');
    }

    /** @param array<string, mixed> $overrides */
    protected function alertFor(Apparatus $unit, array $overrides = []): ApparatusOperationalAlert
    {
        return app(ApparatusOperationalAlertService::class)->create([
            'apparatus_id' => $unit->id,
            'category' => ApparatusOperationalAlert::CATEGORY_UPCOMING_SWAP,
            'title' => 'Upcoming Apparatus Changeout: '.$unit->designation,
            'message' => 'Keep using the assigned truck until the changeout is done.',
            'reason' => 'Exhaust repair',
            'scheduled_date' => $this->futureDate(),
            ...$overrides,
        ], $this->fleetOperationsManager());
    }

    /**
     * A crew's submission, which happens when it is sent.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function submit(User $actor, array $overrides = []): ApparatusChangeout
    {
        return app(ApparatusChangeoutService::class)->submit($this->unit->fresh(), $actor, [
            'client_submission_id' => (string) Str::uuid(),
            'expected_vehicle_number' => 'V-202',
            'expected_unit_slug' => $this->unit->fresh()->slug,
            'to_apparatus_id' => $this->reserve->id,
            ...$overrides,
        ])['changeout'];
    }

    protected function firstItemKey(): string
    {
        $context = app(ApparatusChangeoutPlanner::class)->context($this->unit->fresh());

        return (string) $context['equipment']['compartments'][0]['items'][0]['item_key'];
    }

    /** @return list<mixed> what no decision that changes nothing may touch */
    protected function trucksNow(): array
    {
        return collect([$this->unit, $this->reserve, $this->frontline])
            ->map(fn (Apparatus $truck): array => $truck->fresh()->only(['designation', 'unit_id', 'slug', 'station_id', 'vehicle_number', 'status']))
            ->all();
    }
}
