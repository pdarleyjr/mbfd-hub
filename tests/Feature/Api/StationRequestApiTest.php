<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Employee;
use App\Models\Room;
use App\Models\RoomAsset;
use App\Models\Station;
use App\Models\StationRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use LogicException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class StationRequestApiTest extends TestCase
{
    use RefreshDatabase;

    private Station $station;

    private Station $otherStation;

    private Employee $employee;

    private Room $room;

    private RoomAsset $asset;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->station = $this->makeStation(1);
        $this->otherStation = $this->makeStation(2);
        $this->employee = Employee::query()->create([
            'employee_id' => '20991',
            'name' => 'Firefighter Requester',
            'rank' => 'Firefighter',
            'password' => Hash::make('test-password-only'),
            'must_change_password' => false,
        ]);
        $this->room = Room::query()->create([
            'station_id' => $this->station->id,
            'name' => 'Kitchen',
            'room_type' => 'kitchen',
        ]);
        $this->asset = RoomAsset::query()->create([
            'room_id' => $this->room->id,
            'asset_tag' => 'S1-KIT-001',
            'name' => 'Refrigerator',
            'category' => 'appliance',
            'quantity' => 1,
            'condition' => 'needs_repair',
            'is_active' => true,
        ]);
    }

    public function test_public_repair_submission_creates_one_canonical_request_with_history(): void
    {
        $response = $this->postJson('/api/public/station_request', [
            'client_submission_id' => '67f5a680-220d-47df-8b94-0ed1d99dd2e4',
            'station_id' => $this->station->id,
            'room_id' => $this->room->id,
            'requested_by_employee_id' => $this->employee->id,
            'request_type' => 'repair_service',
            'subject_type' => 'appliance',
            'title' => 'Kitchen refrigerator is warm',
            'description' => 'The refrigerator temperature rose above the safe range.',
            'priority' => 'high',
            'items' => [[
                'room_asset_id' => $this->asset->id,
                'item_name' => 'Refrigerator',
                'category' => 'appliance',
                'quantity' => 1,
                'reason' => 'Damaged/Broken',
                'condition' => 'needs_repair',
            ]],
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.station_id', $this->station->id)
            ->assertJsonPath('data.room_id', $this->room->id)
            ->assertJsonPath('data.room_name_snapshot', 'Kitchen')
            ->assertJsonPath('data.request_type', 'repair_service')
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonMissingPath('data.requester');

        $record = StationRequest::query()->with(['items', 'updates'])->sole();

        $this->assertMatchesRegularExpression('/^SR-\d{4}-\d{6}$/', $record->request_number);
        $this->assertSame($this->employee->id, $record->requested_by_employee_id);
        $this->assertSame('Firefighter Requester', $record->requester_name_snapshot);
        $this->assertSame('Kitchen', $record->room_name_snapshot);
        $this->assertCount(1, $record->items);
        $this->assertSame($this->asset->id, $record->items->sole()->room_asset_id);
        $this->assertCount(1, $record->updates);
        $this->assertSame('pending', $record->updates->sole()->status);
    }

    public function test_public_submission_is_idempotent_by_client_uuid(): void
    {
        $payload = $this->equipmentPayload();

        $first = $this->postJson('/api/public/station_request', $payload)->assertCreated();
        $second = $this->postJson('/api/public/station_request', $payload)->assertOk();

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertDatabaseCount('station_requests', 1);
        $this->assertDatabaseCount('station_request_items', 1);
        $this->assertDatabaseCount('station_request_updates', 1);
    }

    public function test_multi_item_submission_stores_photos_and_signatures_without_raw_image_payloads(): void
    {
        $payload = $this->equipmentPayload();
        $payload['items'][0]['photo'] = $this->pngFixture();
        $payload['items'][] = [
            'item_name' => 'Thermal camera',
            'category' => 'communications',
            'quantity' => 2,
            'reason' => 'Needed',
        ];

        $this->postJson('/api/public/station_request', $payload)->assertCreated();
        $request = StationRequest::query()->with('items')->sole();

        $this->assertCount(2, $request->items);
        $this->assertSame(2, $request->items[1]->quantity);
        $this->assertNotNull($request->items[0]->photo_path);
        Storage::disk('public')->assertExists($request->items[0]->photo_path);
        Storage::disk('public')->assertExists($request->metadata['signatures']['member']);
        Storage::disk('public')->assertExists($request->metadata['signatures']['officer']);
        $this->assertStringNotContainsString('data:image', json_encode($request->getAttributes(), JSON_THROW_ON_ERROR));
    }

    public function test_malformed_items_return_validation_errors_instead_of_server_errors(): void
    {
        $payload = $this->equipmentPayload();
        $payload['items'] = ['not-an-item-object'];

        $this->postJson('/api/public/station_request', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items.0.item_name');

        Sanctum::actingAs($this->makeAdmin('logistics_admin'));
        $this->postJson('/api/admin/fire-equipment-requests', [
            'station_id' => $this->station->id,
            'equipment_type' => 'Radio',
            'description' => 'Malformed legacy payload must be rejected.',
            'priority' => 'normal',
            'form_data' => ['items' => ['not-an-item-object']],
        ])->assertUnprocessable()->assertJsonValidationErrors('form_data.items.0');

        $this->assertDatabaseCount('station_requests', 0);
    }

    public function test_unlisted_room_is_preserved_as_a_snapshot_without_creating_a_room(): void
    {
        $payload = $this->equipmentPayload();
        $payload['room_id'] = null;
        $payload['room_name_snapshot'] = '  Rear storage alcove  ';

        $this->postJson('/api/public/station_request', $payload)
            ->assertCreated()
            ->assertJsonPath('data.room_id', null)
            ->assertJsonPath('data.room_name_snapshot', 'Rear storage alcove');

        $request = StationRequest::query()->sole();
        $this->assertNull($request->room_id);
        $this->assertSame('Rear storage alcove', $request->room_name_snapshot);
        $this->assertDatabaseCount('rooms', 1);
    }

    public function test_station_room_asset_and_employee_ownership_are_validated(): void
    {
        $foreignRoom = Room::query()->create([
            'station_id' => $this->otherStation->id,
            'name' => 'Bay',
            'room_type' => 'apparatus_bay',
        ]);
        $foreignAsset = RoomAsset::query()->create([
            'room_id' => $foreignRoom->id,
            'name' => 'Compressor',
            'quantity' => 1,
            'condition' => 'good',
        ]);

        $payload = $this->equipmentPayload();
        $payload['room_id'] = $foreignRoom->id;
        $payload['items'][0]['room_asset_id'] = $foreignAsset->id;

        $this->postJson('/api/public/station_request', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['room_id', 'items.0.room_asset_id']);

        $this->assertDatabaseCount('station_requests', 0);
    }

    public function test_stolen_equipment_requires_a_police_case_number(): void
    {
        $payload = $this->equipmentPayload();
        $payload['items'][0]['reason'] = 'Stolen';
        $payload['items'][0]['pd_case_number'] = null;

        $this->postJson('/api/public/station_request', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items.0.pd_case_number');
    }

    public function test_public_request_history_redacts_internal_fields_and_employee_identifiers(): void
    {
        $this->postJson('/api/public/station_request', $this->equipmentPayload())->assertCreated();
        $request = StationRequest::query()->sole();

        $request->updates()->create([
            'status' => 'under_review',
            'public_note' => 'Logistics is reviewing availability.',
            'internal_note' => 'SECRET vendor negotiation detail',
        ]);

        $response = $this->getJson("/api/public/stations/{$this->station->id}/requests")
            ->assertOk()
            ->assertJsonPath('data.0.request_number', $request->request_number)
            ->assertJsonPath('data.0.updates.1.public_note', 'Logistics is reviewing availability.');

        $body = $response->getContent();
        $this->assertStringNotContainsString('SECRET vendor negotiation detail', $body);
        $this->assertStringNotContainsString('20991', $body);
        $this->assertArrayNotHasKey('internal_note', $response->json('data.0.updates.1'));
        $this->assertArrayNotHasKey('requested_by_employee_id', $response->json('data.0'));
    }

    public function test_public_history_filters_and_paginates_canonical_requests(): void
    {
        $first = $this->equipmentPayload();
        $second = $this->equipmentPayload();
        $second['client_submission_id'] = '90a9e23c-cb11-4909-a443-9dbfc64e91ce';
        $second['request_type'] = 'repair_service';
        unset($second['member_signature'], $second['officer_signature']);

        $this->postJson('/api/public/station_request', $first)->assertCreated();
        $this->postJson('/api/public/station_request', $second)->assertCreated();

        $this->getJson("/api/public/stations/{$this->station->id}/requests?scope=all&request_type=equipment&per_page=1")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.request_type', 'equipment')
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('meta.total', 1);

        $this->getJson("/api/public/stations/{$this->otherStation->id}/requests?scope=all")
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->getJson("/api/public/stations/{$this->station->id}/requests?status=not-a-real-status")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
    }

    public function test_authorized_workflow_transition_is_append_only_and_sets_terminal_timestamps(): void
    {
        $this->postJson('/api/public/station_request', $this->equipmentPayload())->assertCreated();
        $request = StationRequest::query()->with('items')->sole();
        $requestItem = $request->items->sole();
        $admin = $this->makeAdmin('logistics_admin');
        Sanctum::actingAs($admin);

        $this->patchJson("/api/admin/station-requests/{$request->id}/transition", [
            'status' => 'acknowledged',
            'public_note' => 'Request received by Support Services.',
            'internal_note' => 'Assigned to logistics queue.',
        ])->assertOk()->assertJsonPath('data.status', 'acknowledged');

        $this->patchJson("/api/admin/station-requests/{$request->id}/transition", [
            'status' => 'completed',
            'public_note' => 'Replacement delivered to Station 1.',
            'asset_operations' => [[
                'operation' => 'create',
                'station_request_item_id' => $requestItem->id,
                'room_id' => $this->room->id,
                'name' => 'Portable radio',
                'asset_tag' => 'S1-RAD-009',
                'serial_number' => 'SERIAL-PRIVATE-009',
                'category' => 'communications',
                'quantity' => 1,
                'condition' => 'new',
            ]],
        ])->assertOk()->assertJsonPath('data.status', 'completed');

        $request->refresh();
        $this->assertNotNull($request->acknowledged_at);
        $this->assertSame($admin->id, $request->acknowledged_by);
        $this->assertTrue($request->acknowledgedBy->is($admin));
        $this->assertNotNull($request->completed_at);
        $this->assertCount(3, $request->updates);
        $this->assertDatabaseHas('room_assets', [
            'room_id' => $this->room->id,
            'asset_tag' => 'S1-RAD-009',
        ]);
        $this->assertDatabaseHas('room_asset_events', [
            'station_request_id' => $request->id,
            'event_type' => 'created_from_request',
        ]);
        $this->assertNotNull($requestItem->refresh()->room_asset_id);

        $this->getJson("/api/public/stations/{$this->station->id}/rooms/{$this->room->id}/profile")
            ->assertOk()
            ->assertJsonFragment(['name' => 'Portable radio'])
            ->assertJsonFragment(['event_type' => 'created_from_request']);
    }

    public function test_completed_asset_fulfillment_is_idempotent_and_only_runs_on_completion(): void
    {
        $this->postJson('/api/public/station_request', $this->equipmentPayload())->assertCreated();
        $request = StationRequest::query()->with('items')->sole();
        $requestItem = $request->items->sole();
        Sanctum::actingAs($this->makeAdmin('logistics_admin'));
        $operation = [
            'operation' => 'create',
            'station_request_item_id' => $requestItem->id,
            'room_id' => $this->room->id,
            'name' => 'Portable radio',
            'asset_tag' => 'S1-RAD-IDEMPOTENT',
        ];

        $this->patchJson("/api/admin/station-requests/{$request->id}/transition", [
            'status' => 'approved',
            'asset_operations' => [$operation],
        ])->assertUnprocessable()->assertJsonValidationErrors('asset_operations');

        $this->patchJson("/api/admin/station-requests/{$request->id}/transition", [
            'status' => 'completed',
            'asset_operations' => [$operation],
        ])->assertOk();
        $linkedAssetId = $requestItem->refresh()->room_asset_id;

        $this->patchJson("/api/admin/station-requests/{$request->id}/transition", [
            'status' => 'completed',
            'asset_operations' => [$operation],
        ])->assertOk();

        $this->assertSame($linkedAssetId, $requestItem->refresh()->room_asset_id);
        $this->assertDatabaseCount('room_assets', 2);
        $this->assertDatabaseCount('room_asset_events', 1);
    }

    public function test_asset_fulfillment_must_target_an_item_on_the_same_request(): void
    {
        $this->postJson('/api/public/station_request', $this->equipmentPayload())->assertCreated();
        $request = StationRequest::query()->with('items')->sole();
        $otherPayload = $this->equipmentPayload();
        $otherPayload['client_submission_id'] = 'f0ec0e60-19f5-4379-a0b8-96b2c35a2485';
        $this->postJson('/api/public/station_request', $otherPayload)->assertCreated();
        $otherItem = StationRequest::query()->whereKeyNot($request->id)->firstOrFail()->items()->sole();
        Sanctum::actingAs($this->makeAdmin('logistics_admin'));

        $this->patchJson("/api/admin/station-requests/{$request->id}/transition", [
            'status' => 'completed',
            'asset_operations' => [[
                'operation' => 'create',
                'station_request_item_id' => $otherItem->id,
                'room_id' => $this->room->id,
                'name' => 'Wrong request asset',
            ]],
        ])->assertUnprocessable()->assertJsonValidationErrors('asset_operations');

        $this->assertDatabaseMissing('room_assets', ['name' => 'Wrong request asset']);
        $this->assertSame('pending', $request->refresh()->status);
    }

    public function test_each_request_item_can_only_be_fulfilled_once_per_completion(): void
    {
        $this->postJson('/api/public/station_request', $this->equipmentPayload())->assertCreated();
        $request = StationRequest::query()->with('items')->sole();
        $item = $request->items->sole();
        Sanctum::actingAs($this->makeAdmin('logistics_admin'));

        $this->patchJson("/api/admin/station-requests/{$request->id}/transition", [
            'status' => 'completed',
            'asset_operations' => [
                [
                    'operation' => 'create',
                    'station_request_item_id' => $item->id,
                    'room_id' => $this->room->id,
                    'name' => 'First duplicate fulfillment',
                ],
                [
                    'operation' => 'create',
                    'station_request_item_id' => $item->id,
                    'room_id' => $this->room->id,
                    'name' => 'Second duplicate fulfillment',
                ],
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors('asset_operations');

        $this->assertSame('pending', $request->refresh()->status);
        $this->assertDatabaseMissing('room_assets', ['name' => 'First duplicate fulfillment']);
        $this->assertDatabaseMissing('room_assets', ['name' => 'Second duplicate fulfillment']);
    }

    public function test_link_and_replace_operations_update_item_mapping_condition_and_append_history(): void
    {
        $this->postJson('/api/public/station_request', $this->equipmentPayload())->assertCreated();
        $request = StationRequest::query()->with('items')->sole();
        $item = $request->items->sole();
        Sanctum::actingAs($this->makeAdmin('logistics_admin'));

        $this->patchJson("/api/admin/station-requests/{$request->id}/transition", [
            'status' => 'completed',
            'asset_operations' => [[
                'operation' => 'link',
                'station_request_item_id' => $item->id,
                'room_asset_id' => $this->asset->id,
                'condition' => 'good',
                'notes' => 'Repair verified.',
            ]],
        ])->assertOk();

        $this->assertSame($this->asset->id, $item->refresh()->room_asset_id);
        $this->assertSame('good', $this->asset->refresh()->condition);
        $this->assertDatabaseHas('room_asset_events', [
            'room_asset_id' => $this->asset->id,
            'station_request_id' => $request->id,
            'event_type' => 'condition_changed',
        ]);
        $this->assertDatabaseHas('room_asset_events', [
            'room_asset_id' => $this->asset->id,
            'station_request_id' => $request->id,
            'event_type' => 'linked_to_request',
        ]);
    }

    public function test_replace_operation_retires_old_asset_and_maps_item_to_replacement(): void
    {
        $payload = $this->equipmentPayload();
        $payload['items'][0]['room_asset_id'] = $this->asset->id;
        $this->postJson('/api/public/station_request', $payload)->assertCreated();
        $request = StationRequest::query()->with('items')->sole();
        $item = $request->items->sole();
        Sanctum::actingAs($this->makeAdmin('logistics_admin'));

        $this->patchJson("/api/admin/station-requests/{$request->id}/transition", [
            'status' => 'completed',
            'asset_operations' => [[
                'operation' => 'replace',
                'station_request_item_id' => $item->id,
                'room_asset_id' => $this->asset->id,
                'room_id' => $this->room->id,
                'name' => 'Replacement refrigerator',
                'asset_tag' => 'S1-KIT-002',
                'condition' => 'new',
            ]],
        ])->assertOk();

        $replacement = RoomAsset::query()->where('asset_tag', 'S1-KIT-002')->sole();
        $this->assertSame($replacement->id, $item->refresh()->room_asset_id);
        $this->assertFalse($this->asset->refresh()->is_active);
        $this->assertSame($replacement->id, $this->asset->replaced_by_room_asset_id);
        $this->assertDatabaseHas('room_asset_events', ['room_asset_id' => $this->asset->id, 'event_type' => 'replaced']);
        $this->assertDatabaseHas('room_asset_events', ['room_asset_id' => $replacement->id, 'event_type' => 'created_as_replacement']);
    }

    public function test_training_only_user_cannot_manage_station_requests(): void
    {
        $this->postJson('/api/public/station_request', $this->equipmentPayload())->assertCreated();
        $request = StationRequest::query()->sole();
        $trainingUser = $this->makeAdmin('training_admin');
        Sanctum::actingAs($trainingUser);

        $this->patchJson("/api/admin/station-requests/{$request->id}/transition", [
            'status' => 'acknowledged',
        ])->assertForbidden();
    }

    public function test_admin_mutation_requires_authentication_and_denial_and_cancellation_set_timestamps(): void
    {
        $this->postJson('/api/public/station_request', $this->equipmentPayload())->assertCreated();
        $denied = StationRequest::query()->sole();

        $this->patchJson("/api/admin/station-requests/{$denied->id}/transition", [
            'status' => 'denied',
        ])->assertUnauthorized();

        Sanctum::actingAs($this->makeAdmin('logistics_admin'));
        $this->patchJson("/api/admin/station-requests/{$denied->id}/transition", [
            'status' => 'denied',
        ])->assertOk();
        $this->assertNotNull($denied->refresh()->denied_at);

        $payload = $this->equipmentPayload();
        $payload['client_submission_id'] = '2b232c61-ecc5-4df1-b980-3ec408088fbe';
        $this->postJson('/api/public/station_request', $payload)->assertCreated();
        $cancelled = StationRequest::query()->where('status', 'pending')->sole();
        $this->patchJson("/api/admin/station-requests/{$cancelled->id}/transition", [
            'status' => 'cancelled',
        ])->assertOk();
        $this->assertNotNull($cancelled->refresh()->cancelled_at);
    }

    public function test_request_updates_and_room_asset_events_are_append_only(): void
    {
        $this->postJson('/api/public/station_request', $this->equipmentPayload())->assertCreated();
        $request = StationRequest::query()->with('updates')->sole();

        try {
            $request->updates->sole()->update(['public_note' => 'Overwritten']);
            $this->fail('Station request updates must not be mutable.');
        } catch (LogicException $exception) {
            $this->assertSame('Station request updates are append-only.', $exception->getMessage());
        }

        $event = $this->asset->events()->create([
            'station_request_id' => $request->id,
            'event_type' => 'test_event',
            'event_at' => now(),
        ]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Room asset events are append-only.');
        $event->delete();
    }

    private function equipmentPayload(): array
    {
        return [
            'client_submission_id' => '3659682f-c94a-4df8-874f-09f2244d3918',
            'station_id' => $this->station->id,
            'room_id' => $this->room->id,
            'requested_by_employee_id' => $this->employee->id,
            'request_type' => 'equipment',
            'subject_type' => 'communications',
            'title' => 'Portable radio replacement',
            'description' => 'A replacement portable radio is needed.',
            'priority' => 'normal',
            'member_signature' => $this->pngFixture(),
            'officer_signature' => $this->pngFixture(),
            'items' => [[
                'item_name' => 'Portable radio',
                'category' => 'communications',
                'quantity' => 1,
                'reason' => 'Needed',
                'pd_case_number' => null,
            ]],
        ];
    }

    private function pngFixture(): string
    {
        return 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';
    }

    private function makeStation(int $number): Station
    {
        return Station::query()->create([
            'station_number' => $number,
            'name' => "Station {$number}",
            'address' => "{$number} Test Street",
            'is_active' => true,
        ]);
    }

    private function makeAdmin(string $roleName): User
    {
        $role = Role::query()->firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }
}
