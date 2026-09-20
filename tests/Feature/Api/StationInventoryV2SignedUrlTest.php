<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\AccountStatus;
use App\Models\Employee;
use App\Models\InventoryCategory;
use App\Models\InventoryItem;
use App\Models\Station;
use App\Models\StationInventoryItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Tests\TestCase;

final class StationInventoryV2SignedUrlTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $employee = Employee::query()->create([
            'employee_id' => 'E01-INVENTORY-TEST',
            'name' => 'Canonical Inventory Actor',
            'rank' => 'Captain',
            'password' => 'not-used',
            'must_change_password' => false,
        ]);
        $user = User::factory()->create([
            'account_status' => AccountStatus::Active,
            'employee_id' => $employee->employee_id,
            'employee_profile_id' => $employee->id,
        ]);
        $this->actingAsCanonicalUser($user);
    }

    public function test_every_station_inventory_route_requires_the_canonical_authenticated_member_context_without_a_pin_or_signed_url(): void
    {
        $routes = collect(RouteFacade::getRoutes()->getRoutes())
            ->filter(static fn (Route $route): bool => str_starts_with($route->uri(), 'api/v2/station-inventory/'));

        $this->assertNotEmpty($routes);
        $this->assertFalse($routes->contains(static fn (Route $route): bool => $route->uri() === 'api/v2/station-inventory/verify-pin'));

        foreach ($routes as $route) {
            $middleware = $route->gatherMiddleware();
            $this->assertContains('auth:sanctum', $middleware, $route->uri());
            $this->assertContains('canonical.api', $middleware, $route->uri());
            $this->assertNotContains('station-inventory.signed', $middleware, $route->uri());
        }
    }

    public function test_station_item_update_cannot_be_retargeted_to_another_station(): void
    {
        $source = $this->station('201');
        $target = $this->station('202');
        $category = InventoryCategory::query()->create(['name' => 'Test Supplies']);
        $catalog = InventoryItem::query()->create([
            'category_id' => $category->id,
            'name' => 'Test Gloves',
            'par_quantity' => 10,
        ]);
        $item = StationInventoryItem::query()->create([
            'station_id' => $target->id,
            'inventory_item_id' => $catalog->id,
            'on_hand' => 7,
        ]);

        $this->putJson("/api/v2/station-inventory/{$source->id}/item/{$item->id}", ['on_hand' => 3])
            ->assertNotFound();

        $this->assertDatabaseHas('station_inventory_items', ['id' => $item->id, 'on_hand' => 7]);
    }

    private function station(string $number): Station
    {
        return Station::query()->create([
            'station_number' => $number,
            'address' => $number.' Test Avenue',
            'zip_code' => '33139',
        ]);
    }
}
