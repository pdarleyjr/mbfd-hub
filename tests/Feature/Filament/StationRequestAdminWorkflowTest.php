<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\RoomAssetResource\Pages\CreateRoomAsset;
use App\Filament\Resources\RoomAssetResource\Pages\EditRoomAsset;
use App\Filament\Resources\RoomAssetResource\Pages\ListRoomAssets;
use App\Filament\Resources\StationRequestResource\Pages\ListStationRequests;
use App\Filament\Resources\StationRequestResource\Pages\ViewStationRequest;
use App\Models\Room;
use App\Models\RoomAsset;
use App\Models\Station;
use App\Models\StationRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

#[RunTestsInSeparateProcesses]
class StationRequestAdminWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Station $station;

    private Room $room;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $role = Role::query()->create(['name' => 'super_admin', 'guard_name' => 'web']);
        $this->admin = User::factory()->create();
        $this->admin->assignRole($role);
        Gate::before(fn (User $user): ?bool => $user->hasRole('super_admin') ? true : null);
        $this->actingAs($this->admin);
        $this->withoutVite();

        $this->station = Station::query()->create([
            'station_number' => 1,
            'name' => 'Station 1',
            'address' => '1 Test Street',
            'is_active' => true,
        ]);
        $this->room = Room::query()->create([
            'station_id' => $this->station->id,
            'name' => 'Kitchen',
            'room_type' => 'kitchen',
        ]);
    }

    public function test_station_request_table_search_and_filters_work(): void
    {
        $open = $this->request('pending', 'Kitchen refrigerator repair', 'high');
        $closed = $this->request('completed', 'Dorm mattress replacement', 'low');

        Livewire::test(ListStationRequests::class)
            ->assertSuccessful()
            ->call('loadTable')
            ->assertTableFilterExists('station_id')
            ->assertTableFilterExists('room_id')
            ->assertTableFilterExists('request_type')
            ->assertTableFilterExists('status')
            ->assertTableFilterExists('priority')
            ->assertTableFilterExists('open')
            ->assertCanSeeTableRecords([$open])
            ->assertCanNotSeeTableRecords([$closed])
            ->searchTable('refrigerator')
            ->assertCanSeeTableRecords([$open])
            ->searchTable('mattress')
            ->assertCanNotSeeTableRecords([$open]);
    }

    public function test_status_action_completes_request_and_creates_and_links_asset_once(): void
    {
        $request = $this->request('pending', 'Portable radio request', 'normal');
        $item = $request->items()->create([
            'item_name' => 'Portable radio',
            'category' => 'communications',
            'quantity' => 1,
            'reason' => 'Needed',
        ]);

        Livewire::test(ViewStationRequest::class, ['record' => $request->getRouteKey()])
            ->assertSuccessful()
            ->assertActionExists('update_workflow')
            ->callAction('update_workflow', data: [
                'status' => 'completed',
                'public_note' => 'Radio delivered.',
                'asset_operations' => [[
                    'operation' => 'create',
                    'station_request_item_id' => $item->id,
                    'room_id' => $this->room->id,
                    'name' => 'Portable radio',
                    'asset_tag' => 'S1-RAD-LIVEWIRE',
                    'quantity' => 1,
                    'condition' => 'new',
                ]],
            ])
            ->assertHasNoActionErrors();

        $asset = RoomAsset::query()->where('asset_tag', 'S1-RAD-LIVEWIRE')->sole();
        $this->assertSame('completed', $request->refresh()->status);
        $this->assertSame($asset->id, $item->refresh()->room_asset_id);
        $this->assertDatabaseHas('room_asset_events', [
            'room_asset_id' => $asset->id,
            'station_request_id' => $request->id,
            'event_type' => 'created_from_request',
        ]);
    }

    public function test_room_asset_create_edit_validation_toggle_and_filters_work(): void
    {
        Livewire::test(CreateRoomAsset::class)
            ->fillForm([
                'room_id' => $this->room->id,
                'asset_tag' => 'S1-KIT-LIVEWIRE',
                'name' => 'Ice machine',
                'category' => 'appliance',
                'quantity' => 2,
                'condition' => 'good',
                'is_active' => true,
                'purchase_price' => 1200.50,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $asset = RoomAsset::query()->where('asset_tag', 'S1-KIT-LIVEWIRE')->sole();
        Livewire::test(EditRoomAsset::class, ['record' => $asset->getRouteKey()])
            ->fillForm(['quantity' => 0, 'purchase_price' => -1])
            ->call('save')
            ->assertHasFormErrors(['quantity', 'purchase_price'])
            ->fillForm(['quantity' => 3, 'purchase_price' => 1000, 'is_active' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(3, $asset->refresh()->quantity);
        $this->assertFalse($asset->is_active);

        Livewire::test(ListRoomAssets::class)
            ->assertSuccessful()
            ->call('loadTable')
            ->assertTableFilterExists('room_id')
            ->assertTableFilterExists('is_active')
            ->searchTable('Ice machine')
            ->assertCanSeeTableRecords([$asset])
            ->filterTable('is_active', true)
            ->assertCanNotSeeTableRecords([$asset]);
    }

    private function request(string $status, string $title, string $priority): StationRequest
    {
        $request = StationRequest::query()->create([
            'station_id' => $this->station->id,
            'room_id' => $this->room->id,
            'room_name_snapshot' => $this->room->name,
            'requester_name_snapshot' => 'Admin Workflow Tester',
            'request_type' => str_contains($title, 'repair') ? 'repair_service' : 'equipment',
            'subject_type' => 'test',
            'title' => $title,
            'description' => 'Admin workflow test fixture.',
            'priority' => $priority,
            'status' => $status,
            'completed_at' => $status === 'completed' ? now() : null,
        ]);
        $request->updates()->create(['status' => $status, 'changed_by_user_id' => $this->admin->id]);

        return $request->refresh();
    }
}
