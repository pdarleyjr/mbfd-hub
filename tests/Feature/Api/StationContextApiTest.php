<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Employee;
use App\Models\EmployeeEquipmentRequest;
use App\Models\Room;
use App\Models\RoomAsset;
use App\Models\RoomAssetEvent;
use App\Models\Station;
use App\Models\StationRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class StationContextApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_station_activity_includes_station_requests_but_never_personal_employee_requests(): void
    {
        $station = $this->station();
        $employee = $this->employee();
        $request = $this->stationRequest($station, $employee);
        EmployeeEquipmentRequest::query()->create([
            'employee_portal_id' => $employee->id,
            'requested_items' => 'SECRET personal uniform request',
            'status' => 'Pending',
        ]);

        $response = $this->getJson("/api/public/stations/{$station->id}/activity")
            ->assertOk()
            ->assertJsonPath('activity.0.type', 'station_request')
            ->assertJsonPath('activity.0.request_number', $request->request_number);

        $this->assertStringNotContainsString('SECRET personal uniform request', $response->getContent());
        $this->assertStringNotContainsString('employee_equipment_request', $response->getContent());
    }

    public function test_room_profile_shows_safe_assets_open_requests_history_and_asset_events(): void
    {
        $station = $this->station();
        $employee = $this->employee();
        $room = Room::query()->create([
            'station_id' => $station->id,
            'name' => 'Kitchen',
            'room_type' => 'kitchen',
        ]);
        $asset = RoomAsset::query()->create([
            'room_id' => $room->id,
            'name' => 'Refrigerator',
            'asset_tag' => 'PUBLIC-TAG',
            'serial_number' => 'SECRET-SERIAL',
            'purchase_price' => 2500,
            'quantity' => 1,
            'condition' => 'good',
            'is_active' => true,
        ]);
        $open = $this->stationRequest($station, $employee, $room);
        $closed = $this->stationRequest($station, $employee, $room, 'completed');
        RoomAssetEvent::query()->create([
            'room_asset_id' => $asset->id,
            'station_request_id' => $closed->id,
            'event_type' => 'repair_completed',
            'event_at' => now(),
            'notes' => 'SECRET internal service note',
            'cost' => 950,
        ]);

        $response = $this->getJson("/api/public/stations/{$station->id}/rooms/{$room->id}/profile")
            ->assertOk()
            ->assertJsonPath('room.id', $room->id)
            ->assertJsonPath('current_assets.0.name', 'Refrigerator')
            ->assertJsonPath('open_requests.0.request_number', $open->request_number)
            ->assertJsonCount(2, 'request_history')
            ->assertJsonPath('asset_events.0.event_type', 'repair_completed');

        $body = $response->getContent();
        $this->assertStringNotContainsString('SECRET-SERIAL', $body);
        $this->assertStringNotContainsString('SECRET internal service note', $body);
        $this->assertStringNotContainsString('2500', $body);
        $this->assertStringNotContainsString('950', $body);
    }

    private function station(): Station
    {
        return Station::query()->create([
            'station_number' => 1,
            'name' => 'Station 1',
            'address' => '1 Test Street',
            'is_active' => true,
        ]);
    }

    private function employee(): Employee
    {
        return Employee::query()->create([
            'employee_id' => '20992',
            'name' => 'Activity Tester',
            'rank' => 'Firefighter',
            'password' => Hash::make('test-password-only'),
            'must_change_password' => false,
        ]);
    }

    private function stationRequest(Station $station, Employee $employee, ?Room $room = null, string $status = 'pending'): StationRequest
    {
        $request = StationRequest::query()->create([
            'station_id' => $station->id,
            'room_id' => $room?->id,
            'requested_by_employee_id' => $employee->id,
            'requester_name_snapshot' => $employee->name,
            'request_type' => 'repair_service',
            'subject_type' => 'appliance',
            'title' => "Test {$status} request",
            'description' => 'Test station-scoped request.',
            'priority' => 'normal',
            'status' => $status,
            'completed_at' => $status === 'completed' ? now() : null,
        ]);
        $request->updates()->create(['status' => $status, 'public_note' => 'Public update.']);

        return $request->refresh();
    }
}
