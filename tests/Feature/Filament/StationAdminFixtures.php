<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\StationResource\Pages\ViewStation;
use App\Models\Apparatus;
use App\Models\CapitalProject;
use App\Models\InventoryCategory;
use App\Models\InventoryItem;
use App\Models\Station;
use App\Models\StationInventoryItem;
use App\Models\Under25kProject;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Shared fixtures for the Station admin resource tests.
 */
trait StationAdminFixtures
{
    /** @var list<string> */
    private array $lastQueries = [];

    private function setUpStationAdmin(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->withoutVite();
    }

    private function countQueries(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $callback();
        $this->lastQueries = array_map(fn (array $query): string => (string) $query['query'], DB::getQueryLog());
        DB::disableQueryLog();

        return count($this->lastQueries);
    }

    /** @return array<string, mixed> */
    private function relationParams(Station $station): array
    {
        return ['ownerRecord' => $station, 'pageClass' => ViewStation::class, 'lazy' => false];
    }

    private function actingAsSuperAdmin(): User
    {
        Role::findOrCreate('super_admin', 'web');
        $user = User::factory()->create(['account_status' => 'active']);
        $user->assignRole('super_admin');
        $this->actingAsCanonicalUser($user);

        return $user;
    }

    /** @param list<string> $permissions */
    private function actingAsAdminWith(array $permissions): User
    {
        $user = User::factory()->create(['account_status' => 'active']);
        $user->givePermissionTo(collect(['admin.access', ...$permissions])
            ->map(fn (string $permission) => Permission::findOrCreate($permission, 'web')));
        $this->actingAs($user);

        return $user;
    }

    /** @param array<string, mixed> $overrides */
    private function station(int $number, array $overrides = []): Station
    {
        return Station::query()->create(array_merge([
            'station_number' => $number,
            'address' => "{$number} Test Street",
            'city' => 'Miami Beach',
            'state' => 'FL',
            'zip_code' => '33139',
            'is_active' => true,
        ], $overrides));
    }

    private function apparatus(Station $station, string $unit): Apparatus
    {
        return Apparatus::query()->create([
            'station_id' => $station->id,
            'unit_id' => $unit,
            'designation' => $unit,
            'vehicle_number' => 'V-'.$unit,
            'make' => 'Fixture',
            'model' => 'Engine',
            'status' => 'In Service',
        ]);
    }

    private function inventoryItem(Station $station, string $status): StationInventoryItem
    {
        $category = InventoryCategory::query()->firstOrCreate(['name' => 'Test Supplies']);
        $catalog = InventoryItem::query()->create([
            'category_id' => $category->id,
            'name' => 'Test Gloves '.uniqid(),
            'par_quantity' => 10,
        ]);

        return StationInventoryItem::query()->create([
            'station_id' => $station->id,
            'inventory_item_id' => $catalog->id,
            'on_hand' => 1,
            'status' => $status,
        ]);
    }

    private function capitalProject(): CapitalProject
    {
        return CapitalProject::query()->create([
            'project_number' => 'CP-'.uniqid(),
            'name' => 'Roof Replacement',
            'budget_amount' => 1000,
            'status' => 'pending',
            'priority' => 'medium',
        ]);
    }

    private function under25kProject(): Under25kProject
    {
        // Under25kProject's global scope only exposes FIRE-/NEW- projects.
        return Under25kProject::query()->create([
            'project_number' => 'FIRE-'.uniqid(),
            'name' => 'Kitchen Refresh',
            'budget_amount' => 5000,
            'status' => 'Planning',
            'priority' => 'medium',
        ]);
    }
}
