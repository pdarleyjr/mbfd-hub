<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\TrtInventoryCatalogItem;
use App\Models\TrtInventorySession;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TrtInventorySessionIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_session_date_is_unique_when_trailer_is_null(): void
    {
        $date = now()->toDateString();

        TrtInventorySession::query()->create([
            'session_date' => $date,
        ]);

        $this->expectException(QueryException::class);

        TrtInventorySession::query()->create([
            'session_date' => $date,
        ]);
    }

    public function test_find_or_create_returns_the_single_default_session_for_today(): void
    {
        $first = TrtInventorySession::findOrCreateForToday();
        $second = TrtInventorySession::findOrCreateForToday();

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('trt_inventory_sessions', 1);
    }

    public function test_two_public_submissions_for_today_merge_into_one_default_session(): void
    {
        $catalogItem = TrtInventoryCatalogItem::query()->create([
            'name' => 'Audit rescue tool',
            'category' => 'Audit',
            'expected_quantity' => 1,
            'active' => true,
        ]);

        $payload = [
            'entries' => [[
                'catalog_item_id' => $catalogItem->id,
                'present' => true,
                'actual_quantity' => 1,
                'condition' => 'good',
                'action' => 'keep',
            ]],
        ];

        $first = $this->postJson('/api/public/trt-inventory/submit', $payload)
            ->assertCreated();
        $second = $this->postJson('/api/public/trt-inventory/submit', $payload)
            ->assertCreated();

        $this->assertSame($first->json('data.session_id'), $second->json('data.session_id'));
        $this->assertDatabaseCount('trt_inventory_sessions', 1);
        $this->assertDatabaseCount('trt_inventory_entries', 2);
    }
}
