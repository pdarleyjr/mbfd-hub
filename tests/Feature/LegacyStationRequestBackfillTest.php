<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\BigTicketRequest;
use App\Models\FireEquipmentRequest;
use App\Models\Room;
use App\Models\Station;
use App\Models\StationRequest;
use App\Models\User;
use App\Services\LegacyStationRequestBackfillService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LegacyStationRequestBackfillTest extends TestCase
{
    use RefreshDatabase;

    public function test_backfill_is_idempotent_and_preserves_legacy_payloads_and_statuses(): void
    {
        Storage::fake('public');
        $png = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';
        $station = Station::query()->create([
            'station_number' => 1,
            'name' => 'Station 1',
            'address' => '1 Test Street',
            'is_active' => true,
        ]);
        $room = Room::query()->create([
            'station_id' => $station->id,
            'name' => 'Kitchen',
            'room_type' => 'kitchen',
        ]);
        $user = User::factory()->create(['name' => 'Legacy Captain']);

        $bigTicket = BigTicketRequest::query()->create([
            'station_id' => $station->id,
            'room_type' => 'kitchen',
            'room_label' => 'Kitchen',
            'items' => ['Refrigerator', 'Dining table'],
            'other_item' => 'Ice machine',
            'notes' => 'Replace failing station appliances.',
            'created_by' => $user->id,
        ]);
        $equipment = FireEquipmentRequest::query()->create([
            'station_id' => $station->id,
            'requested_by' => $user->id,
            'requested_by_name' => 'Legacy Firefighter',
            'equipment_type' => 'Portable radio',
            'description' => '1x Portable radio',
            'explanation' => 'Radio was stolen during an incident.',
            'priority' => 'high',
            'status' => 'shift_chief_approved',
            'form_data' => [
                'date' => '2026-08-10',
                'items' => [[
                    'description' => 'Portable radio',
                    'quantity' => 1,
                    'reason' => 'Stolen',
                    'pd_case_number' => 'MBPD-2026-42',
                    'photo' => $png,
                ]],
            ],
            'signature' => $png,
            'officer_signature' => 'fire-equipment-requests/signatures/officer.png',
            'pd_case_number' => 'MBPD-2026-42',
        ]);
        $legacyCreatedAt = now()->subYears(2)->startOfSecond();
        foreach ([$bigTicket, $equipment] as $legacyRequest) {
            $legacyRequest->timestamps = false;
            $legacyRequest->forceFill([
                'created_at' => $legacyCreatedAt,
                'updated_at' => $legacyCreatedAt->copy()->addDay(),
            ])->saveQuietly();
            $legacyRequest->timestamps = true;
            $legacyRequest->refresh();
        }

        $service = app(LegacyStationRequestBackfillService::class);
        $first = $service->run();
        $second = $service->run();

        $this->assertSame(2, $first->created);
        $this->assertSame(0, $second->created);
        $this->assertSame(2, $second->skipped);
        $this->assertDatabaseCount('station_requests', 2);

        $repair = StationRequest::query()
            ->where('legacy_source', 'big_ticket_requests')
            ->where('legacy_id', $bigTicket->id)
            ->with('items')
            ->sole();
        $this->assertSame('repair_service', $repair->request_type);
        $this->assertSame($room->id, $repair->room_id);
        $this->assertSame($room->name, $repair->room_name_snapshot);
        $this->assertTrue($repair->created_at->equalTo($legacyCreatedAt));
        $this->assertTrue($repair->updated_at->equalTo($legacyCreatedAt->copy()->addDay()));
        $this->assertSame('pending', $repair->status);
        $this->assertSame(['Refrigerator', 'Dining table'], $repair->metadata['legacy']['items']);
        $this->assertCount(3, $repair->items);

        $replacement = StationRequest::query()
            ->where('legacy_source', 'fire_equipment_requests')
            ->where('legacy_id', $equipment->id)
            ->with('items')
            ->sole();
        $this->assertSame('acknowledged', $replacement->status);
        $this->assertSame('shift_chief_approved', $replacement->metadata['legacy']['status']);
        $this->assertSame('MBPD-2026-42', $replacement->items->sole()->pd_case_number);
        $this->assertSame(
            'fire-equipment-requests/signatures/officer.png',
            $replacement->metadata['signatures']['officer'],
        );
        $this->assertStringStartsWith('station-requests/legacy/photos/', $replacement->items->sole()->photo_path);
        $this->assertStringStartsWith('station-requests/legacy/signatures/', $replacement->metadata['signatures']['member']);
        Storage::disk('public')->assertExists($replacement->items->sole()->photo_path);
        Storage::disk('public')->assertExists($replacement->metadata['signatures']['member']);
        $this->assertArrayNotHasKey('photo', $replacement->metadata['legacy']['form_data']['items'][0]);
        $this->assertArrayNotHasKey('signature', $replacement->metadata['legacy']);
        $this->assertStringNotContainsString('data:image', json_encode($replacement->metadata, JSON_THROW_ON_ERROR));
    }

    public function test_backfill_does_not_guess_when_a_room_match_is_ambiguous(): void
    {
        $station = Station::query()->create([
            'station_number' => 3,
            'name' => 'Station 3',
            'address' => '3 Test Street',
            'is_active' => true,
        ]);
        foreach (['Kitchen North', 'Kitchen South'] as $name) {
            Room::query()->create([
                'station_id' => $station->id,
                'name' => $name,
                'room_type' => 'kitchen',
            ]);
        }

        BigTicketRequest::query()->create([
            'station_id' => $station->id,
            'room_type' => 'kitchen',
            'items' => ['Table'],
            'notes' => 'Legacy ambiguous room.',
            'created_by' => null,
        ]);

        app(LegacyStationRequestBackfillService::class)->run();

        $request = StationRequest::query()->sole();
        $this->assertNull($request->room_id);
        $this->assertSame('Kitchen', $request->room_name_snapshot);
        $this->assertSame('ambiguous', $request->metadata['legacy']['room_match']);
    }
}
