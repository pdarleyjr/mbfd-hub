<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\TrtInventoryCatalogItem;
use App\Models\TrtInventorySession;
use App\Models\TrtInventorySubmission;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TrtInventorySessionIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsCanonicalFixture();
    }

    public function test_shared_session_date_changes_at_eastern_midnight_while_timestamps_stay_utc(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-26T00:30:00Z'));
        $evening = TrtInventorySession::findOrCreateForToday();
        self::assertSame('2026-10-25', $evening->session_date->toDateString());
        self::assertSame('2026-10-26 00:30:00', $evening->getRawOriginal('created_at'));

        $this->travelTo(CarbonImmutable::parse('2026-10-26T03:59:59Z'));
        self::assertSame($evening->id, TrtInventorySession::findOrCreateForToday()->id);
        $this->travelTo(CarbonImmutable::parse('2026-10-26T04:00:00Z'));
        $morning = TrtInventorySession::findOrCreateForToday();
        self::assertNotSame($evening->id, $morning->id);
        self::assertSame('2026-10-26', $morning->session_date->toDateString());
    }

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

    public function test_replaying_the_same_owned_client_submission_does_not_duplicate_entries(): void
    {
        $catalogItem = TrtInventoryCatalogItem::query()->create([
            'name' => 'Idempotent rescue tool',
            'category' => 'Audit',
            'expected_quantity' => 1,
            'active' => true,
        ]);
        $payload = [
            'client_submission_id' => 'e0100000-0000-4000-8000-000000000010',
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
            ->assertOk();

        $this->assertSame($first->json('data.session_id'), $second->json('data.session_id'));
        $this->assertDatabaseCount('trt_inventory_entries', 1);
        $this->assertDatabaseCount('trt_inventory_submissions', 1);
        $this->assertSame(1, TrtInventorySubmission::query()->sole()->entries_count);
    }
}
