<?php

namespace Tests\Feature\Api;

use App\Models\InventoryCategory;
use App\Models\InventoryItem;
use App\Models\Station;
use App\Models\StationInventoryItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class StationInventoryV2SignedUrlTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_unsigned_item_count_update_is_rejected_before_any_inventory_change(): void
    {
        $station = Station::create([
            'station_number' => 'Test-201',
            'address' => '201 Test Avenue',
            'zip_code' => '33139',
        ]);
        $category = InventoryCategory::create(['name' => 'Test Supplies']);
        $catalogItem = InventoryItem::create([
            'category_id' => $category->id,
            'name' => 'Test Gloves',
            'par_quantity' => 10,
        ]);
        $stationItem = StationInventoryItem::create([
            'station_id' => $station->id,
            'inventory_item_id' => $catalogItem->id,
            'on_hand' => 7,
        ]);

        $response = $this->putJson("/api/v2/station-inventory/{$station->id}/item/{$stationItem->id}", [
            'on_hand' => 3,
            'actor_name' => 'Test Captain',
            'actor_shift' => 'A-Day',
        ]);

        $response
            ->assertUnauthorized()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Invalid or expired token');
        $this->assertDatabaseHas('station_inventory_items', [
            'id' => $stationItem->id,
            'on_hand' => 7,
        ]);
        $this->assertDatabaseMissing('station_inventory_audits', [
            'station_id' => $station->id,
            'action' => 'count_updated',
        ]);
    }

    public function test_a_base_inventory_signature_authorizes_an_item_count_update_and_audits_the_catalog_item(): void
    {
        $station = Station::create([
            'station_number' => 'Test-101',
            'address' => '101 Test Avenue',
            'zip_code' => '33139',
        ]);
        $otherStation = Station::create([
            'station_number' => 'Test-102',
            'address' => '102 Test Avenue',
            'zip_code' => '33139',
        ]);
        $category = InventoryCategory::create(['name' => 'Test Supplies']);
        $catalogItem = InventoryItem::create([
            'category_id' => $category->id,
            'name' => 'Test Gloves',
            'par_quantity' => 10,
        ]);

        // Establish a different station-inventory primary key than the catalog-item key.
        StationInventoryItem::create([
            'station_id' => $otherStation->id,
            'inventory_item_id' => $catalogItem->id,
            'on_hand' => 2,
        ]);
        $stationItem = StationInventoryItem::create([
            'station_id' => $station->id,
            'inventory_item_id' => $catalogItem->id,
            'on_hand' => 7,
        ]);

        $baseUrl = URL::temporarySignedRoute(
            'api.v2.station-inventory.access',
            now()->addMinutes(5),
            [
                'stationId' => $station->id,
                'actor_name' => 'Test Captain',
                'actor_shift' => 'A-Day',
            ],
        );
        $parts = parse_url($baseUrl);
        $updateUrl = $parts['path'].'/item/'.$stationItem->id.'?'.$parts['query'];

        $response = $this->putJson($updateUrl, [
            'on_hand' => 3,
            'actor_name' => 'Forged Actor',
            'actor_shift' => 'Forged Shift',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('item.id', $stationItem->id)
            ->assertJsonPath('item.inventory_item_id', $catalogItem->id)
            ->assertJsonPath('item.on_hand', 3);

        $this->assertDatabaseHas('station_inventory_items', [
            'id' => $stationItem->id,
            'station_id' => $station->id,
            'inventory_item_id' => $catalogItem->id,
            'on_hand' => 3,
        ]);
        $this->assertDatabaseHas('station_inventory_audits', [
            'station_id' => $station->id,
            'inventory_item_id' => $catalogItem->id,
            'actor_name' => 'Test Captain',
            'actor_shift' => 'A-Day',
            'action' => 'count_updated',
        ]);
    }

    public function test_a_signed_supply_request_uses_the_actor_bound_to_the_url(): void
    {
        $station = Station::create([
            'station_number' => 'Test-301',
            'address' => '301 Test Avenue',
            'zip_code' => '33139',
        ]);
        $baseUrl = URL::temporarySignedRoute(
            'api.v2.station-inventory.supply-requests',
            now()->addMinutes(5),
            [
                'stationId' => $station->id,
                'actor_name' => 'Signed Officer',
                'actor_shift' => 'B-Day',
            ],
        );

        $response = $this->postJson($baseUrl, [
            'request_text' => 'Replace the damaged gloves.',
            'actor_name' => 'Forged Actor',
            'actor_shift' => 'Forged Shift',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('request.created_by_name', 'Signed Officer')
            ->assertJsonPath('request.created_by_shift', 'B-Day');

        $this->assertDatabaseHas('station_inventory_audits', [
            'station_id' => $station->id,
            'actor_name' => 'Signed Officer',
            'actor_shift' => 'B-Day',
            'action' => 'note_added',
        ]);
    }
}
