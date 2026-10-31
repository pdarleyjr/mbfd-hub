<?php

declare(strict_types=1);

namespace Tests\Feature\Fleet\Changeouts;

use App\Enums\AccountStatus;
use App\Jobs\FanOutNotification;
use App\Models\Apparatus;
use App\Models\ApparatusChangeout;
use App\Models\ApparatusChangeoutItem;
use App\Models\ApparatusOperationalAlert;
use App\Models\ApparatusServiceTicket;
use App\Models\Station;
use App\Models\User;
use App\Services\DailyCheckoutChecklistResolver;
use App\Services\Fleet\ApparatusChangeoutService;
use App\Services\Fleet\ApparatusOperationalAlertService;
use App\Support\HubTimezone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Fleet\Concerns\BuildsVehicleSwapFixtures;
use Tests\TestCase;

/**
 * Trucks shaped like production: E2 on V-202 (frontline), E3 on V-303 (frontline), and reserve engine V-909, from the
 * swap fixtures. Most tests use the small checklists below, so what is expected of every line is visible here. The real
 * templates have their own class.
 */
abstract class ChangeoutTestCase extends TestCase
{
    use BuildsVehicleSwapFixtures;
    use RefreshDatabase;

    /** Item keys on the small engine checklist. */
    protected const RADIO = 'cab-item-1';

    protected const MAP_BOOK = 'cab-item-2';

    protected const HALLIGAN = 'comp_1-item-1';

    protected const AXE = 'comp_1-item-2';

    protected const ENGINE_LINES = 4;

    private ?string $checklistDirectory = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSwapFixtures();
    }

    protected function tearDown(): void
    {
        if ($this->checklistDirectory !== null) {
            File::deleteDirectory($this->checklistDirectory);
        }
        parent::tearDown();
    }

    /** Replaces the tracked templates with small ones, for this test only. */
    protected function useSmallChecklists(): void
    {
        $this->checklistDirectory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'mbfd-changeout-'.getmypid().'-'.Str::random(8);
        File::ensureDirectoryExists($this->checklistDirectory);
        $engine = json_encode([
            'title' => 'Engine',
            'officerChecklist' => [['id' => 'vehicle_num', 'name' => 'Vehicle #', 'inputType' => 'text']],
            'compartments' => [
                ['id' => 'cab', 'title' => 'Front Cab', 'items' => [
                    ['name' => 'Portable radio', 'expectedQuantity' => 2],
                    ['name' => 'Map book'],
                ]],
                ['id' => 'comp_1', 'name' => 'Compartment 1', 'items' => [
                    ['id' => self::HALLIGAN, 'name' => 'Halligan', 'expectedQuantity' => 1],
                    ['id' => self::AXE, 'name' => 'Axe'],
                ]],
            ],
        ], JSON_THROW_ON_ERROR);
        foreach (['engine', 'engine2', 'default', 'ladder1', 'ladder3'] as $template) {
            File::put($this->checklistDirectory."/{$template}_checklist.json", $engine);
        }
        File::put($this->checklistDirectory.'/rescue_checklist.json', json_encode([
            'title' => 'Rescue',
            'officerChecklist' => [
                ['id' => 'mileage', 'name' => 'Mileage', 'inputType' => 'number'],
                ['id' => 'narcotic_book', 'name' => 'Narcotic Book', 'inputType' => 'checkbox'],
                ['id' => 'narcotic_seal', 'name' => 'Narcotic Seal #', 'inputType' => 'text'],
            ],
            'compartments' => [
                ['id' => 'patient', 'title' => 'Patient Compartment', 'items' => [
                    ['name' => 'Airway Bag'],
                    ['name' => 'Med Box', 'expectedQuantity' => 1],
                    ['name' => 'LifePak 15', 'expectedQuantity' => 1],
                ]],
            ],
        ], JSON_THROW_ON_ERROR));
        $directory = $this->checklistDirectory;
        $this->app->bind(DailyCheckoutChecklistResolver::class, static fn (): DailyCheckoutChecklistResolver => new DailyCheckoutChecklistResolver($directory));
    }

    /** @param array<string, mixed> $overrides */
    protected function postChangeout(Apparatus $unit, array $overrides = []): TestResponse
    {
        $truck = $unit->fresh();
        if (($overrides['exceptions'] ?? []) !== [] && ! array_key_exists('checklist_version', $overrides)) {
            $overrides['checklist_version'] = app(DailyCheckoutChecklistResolver::class)->resolve($truck)['checklist_version'];
        }

        return $this->postJson("/api/public/apparatuses/{$unit->id}/changeouts", array_merge([
            'client_submission_id' => (string) Str::uuid(),
            'expected_vehicle_number' => $truck->vehicle_number,
            'expected_unit_slug' => $truck->slug,
        ], $overrides));
    }

    /** @param array<string, mixed> $overrides */
    protected function moveToReserve(array $overrides = []): TestResponse
    {
        return $this->postChangeout($this->unit, ['to_apparatus_id' => $this->reserve->id, ...$overrides]);
    }

    protected function changeout(int|TestResponse $id): ApparatusChangeout
    {
        return ApparatusChangeout::query()->findOrFail($id instanceof TestResponse ? $id->json('changeout.id') : $id);
    }

    protected function line(ApparatusChangeout $changeout, string $key): ApparatusChangeoutItem
    {
        return $changeout->items()->where('item_key', $key)->sole();
    }

    /** @param array<string, mixed> $overrides */
    protected function swapAlert(Apparatus $unit, array $overrides = []): ApparatusOperationalAlert
    {
        return app(ApparatusOperationalAlertService::class)->create(array_merge([
            'apparatus_id' => $unit->id,
            'category' => ApparatusOperationalAlert::CATEGORY_UPCOMING_SWAP,
            'title' => 'Upcoming Apparatus Changeout',
            'message' => 'Moving to a reserve while the truck is repaired.',
            'scheduled_date' => HubTimezone::today()->toDateString(),
        ], $overrides), null);
    }

    /** @param array<string, mixed> $overrides */
    protected function reserveEngine(string $number, array $overrides = []): Apparatus
    {
        return Apparatus::query()->create(array_merge([
            'station_id' => $this->reserve->station_id, 'vin' => 'VIN-'.$number, 'daily_checkout_requirement' => 'reserve', 'daily_checkout_template' => 'default',
            'unit_id' => 'RE-'.$number, 'designation' => 'RE-'.$number, 'name' => 'Reserve Engine '.$number, 'type' => 'Engine',
            'vehicle_number' => $number, 'status' => 'Reserve', 'current_engine_hours' => 100, 'current_miles' => 200,
        ], $overrides));
    }

    /** @return array{unit: Apparatus, reserve: Apparatus} R 44 on truck 17503, and reserve rescue truck 1033 carrying a number-derived identity. */
    protected function rescuePair(): array
    {
        $station = Station::query()->create(['station_number' => 4, 'name' => 'Station 4', 'address' => '6880 Indian Creek', 'is_active' => true]);
        $unit = Apparatus::query()->create([
            'station_id' => $station->id, 'vin' => 'VIN-17503', 'assignment' => 'Station 4', 'unit_id' => 'R 44', 'designation' => 'R 44', 'name' => 'Rescue 44',
            'slug' => 'r-44', 'type' => 'Rescue', 'vehicle_number' => '17503', 'status' => 'In Service',
            'daily_checkout_requirement' => 'required', 'daily_checkout_template' => 'rescue', 'current_engine_hours' => 1000, 'current_miles' => 50000,
        ]);
        $reserve = Apparatus::query()->create([
            'station_id' => $this->reserve->station_id, 'vin' => 'VIN-1033', 'unit_id' => 'R-1033', 'designation' => 'R-1033', 'name' => 'RESCUE 1033',
            'slug' => 'r1033', 'type' => 'Rescue', 'vehicle_number' => '1033', 'status' => 'Reserve',
            'daily_checkout_requirement' => 'reserve', 'daily_checkout_template' => 'rescue', 'current_engine_hours' => 300, 'current_miles' => 9000,
        ]);

        return ['unit' => $unit, 'reserve' => $reserve];
    }

    protected function actAsFleet(): User
    {
        $manager = $this->fleetOperationsManager();
        $this->actingAsCanonicalUser($manager);

        return $manager;
    }

    protected function actAsOtherMember(string $rank = 'Firefighter'): User
    {
        return $this->actingAsCanonicalFixture('M-'.Str::upper(Str::random(6)), 'Other Member', $rank);
    }

    protected function memberWithoutProfile(): User
    {
        $user = User::factory()->create(['account_status' => AccountStatus::Active]);
        $this->assertNull($user->fresh()->employeeProfile);

        return $user->fresh();
    }

    protected function service(): ApparatusChangeoutService
    {
        return app(ApparatusChangeoutService::class);
    }

    protected function openTicketOn(Apparatus $truck): ApparatusServiceTicket
    {
        return ApparatusServiceTicket::query()->create([
            'ticket_number' => 'T-'.Str::upper(Str::random(8)), 'apparatus_id' => $truck->id, 'station_id' => $truck->station_id,
            'unit_designation_snapshot' => $truck->designation, 'origin' => 'admin', 'category' => 'repair',
            'title' => 'Exhaust repair', 'description' => 'Exhaust leak.', 'priority' => 'normal', 'status' => 'submitted',
        ]);
    }

    protected function assertNotifiedFleetTimes(int $times): void
    {
        Queue::assertPushed(FanOutNotification::class, $times);
    }
}
