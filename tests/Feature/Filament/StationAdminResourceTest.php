<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\StationResource\Pages\EditStation;
use App\Filament\Resources\StationResource\Pages\ListStations;
use App\Models\StationInspection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class StationAdminResourceTest extends TestCase
{
    use RefreshDatabase;
    use StationAdminFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpStationAdmin();
    }

    public function test_admin_edit_persists_captain_address_name_and_active_state(): void
    {
        $this->actingAsSuperAdmin();
        $station = $this->station(3);

        Livewire::test(EditStation::class, ['record' => $station->getRouteKey()])
            ->assertFormFieldExists('name')
            ->assertFormFieldExists('is_active')
            ->fillForm([
                'name' => 'Station 3 - North Beach',
                'captain_in_charge' => 'Capt. Rivera',
                'city' => 'North Bay Village',
                'state' => 'FL',
                'zip_code' => '33141',
                'is_active' => false,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $station->refresh();
        self::assertSame('Capt. Rivera', $station->captain_in_charge);
        self::assertSame('North Bay Village', $station->city);
        self::assertSame('FL', $station->state);
        self::assertSame('33141', $station->zip_code);
        self::assertSame('Station 3 - North Beach', $station->getRawOriginal('name'));
        self::assertSame('Station 3 - North Beach', $station->name);
        self::assertFalse($station->is_active);
    }

    public function test_blank_display_name_falls_back_to_the_station_number_label(): void
    {
        $this->actingAsSuperAdmin();
        $station = $this->station(4, ['name' => 'Old name']);

        Livewire::test(EditStation::class, ['record' => $station->getRouteKey()])
            ->assertFormSet(['name' => 'Old name', 'is_active' => true])
            ->fillForm(['name' => '   '])
            ->call('save')
            ->assertHasNoFormErrors();

        $station->refresh();
        self::assertNull($station->getRawOriginal('name'));
        self::assertSame('Station 4', $station->name);
    }

    public function test_bulk_delete_is_not_offered_and_inspected_stations_cannot_be_deleted(): void
    {
        $this->actingAsSuperAdmin();
        $inspected = $this->station(1);
        StationInspection::query()->create([
            'station_id' => $inspected->id,
            'inspection_date' => '2026-10-01',
            'inspection_type' => 'weekly',
            'overall_status' => 'pass',
            'form_data' => [],
        ]);

        Livewire::test(ListStations::class)
            ->call('loadTable')
            ->assertTableBulkActionDoesNotExist('delete');

        Livewire::test(EditStation::class, ['record' => $inspected->getRouteKey()])
            ->assertActionHidden('delete');

        self::assertTrue($inspected->newQuery()->whereKey($inspected->id)->exists());
    }
}
