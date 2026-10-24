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
use App\Services\RequestArchivalService;
use App\Services\StationRequestWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Spatie\Permission\Models\Permission;
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
            ->filterTable('open', true)
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

    public function test_repair_and_equipment_archive_restore_actions_preserve_history_and_are_discoverable(): void
    {
        foreach (['repair_service', 'equipment'] as $type) {
            $request = $this->request('completed', '[QA TEST] '.$type.' completed', 'normal');
            $request->update(['request_type' => $type]);
            Livewire::test(ViewStationRequest::class, ['record' => $request->getRouteKey()])
                ->callAction('archive', data: ['archive_reason' => 'QA complete; retain history.'])
                ->assertHasNoActionErrors();
            $this->assertTrue($request->refresh()->isArchived());
            $this->assertSame($this->admin->id, $request->archived_by);
            Livewire::test(ListStationRequests::class)->call('loadTable')
                ->filterTable('archive_state', 'active')
                ->assertCanNotSeeTableRecords([$request])
                ->filterTable('archive_state', 'archived')
                ->searchTable($type)
                ->assertCanSeeTableRecords([$request])
                ->filterTable('archive_state', 'all')
                ->assertCanSeeTableRecords([$request]);
            Livewire::test(ViewStationRequest::class, ['record' => $request->getRouteKey()])
                ->assertSee('QA complete; retain history.')
                ->callAction('restore_archive')->assertHasNoActionErrors();
            $this->assertFalse($request->refresh()->isArchived());
            $this->assertSame('completed', $request->status);
            $this->assertSame(['archived', 'restored'], $request->updates()->whereNotNull('metadata')->get()->pluck('metadata.event')->all());
            Livewire::test(ListStationRequests::class)->call('loadTable')
                ->filterTable('archive_state', 'active')
                ->assertCanSeeTableRecords([$request]);
        }
    }

    public function test_deny_and_cancel_need_a_reason_the_station_can_read(): void
    {
        foreach (['denied' => 'Denied', 'cancelled' => 'Cancelled'] as $status => $label) {
            $request = $this->request('pending', '[QA TEST] '.$status.' reason', 'normal');

            Livewire::test(ViewStationRequest::class, ['record' => $request->getRouteKey()])
                ->callAction('update_workflow', data: ['status' => $status])
                ->assertHasActionErrors(['public_note' => 'required'])
                ->callAction('update_workflow', data: ['status' => $status, 'public_note' => 'too short'])
                ->assertHasActionErrors(['public_note'])
                ->callAction('update_workflow', data: ['status' => $status, 'public_note' => 'Covered by the existing stock.'])
                ->assertHasNoActionErrors();

            $request->refresh();
            $this->assertSame($status, $request->status);
            $this->assertSame($label.': Covered by the existing stock.', $request->current_public_response);
            $this->assertSame('Covered by the existing stock.', $request->updates()->where('status', $status)->value('public_note'));
        }
    }

    public function test_service_refuses_a_blank_denial_but_does_not_ask_twice_for_an_already_denied_request(): void
    {
        $request = $this->request('pending', '[QA TEST] service denial', 'normal');
        $workflow = app(StationRequestWorkflowService::class);

        try {
            $workflow->transition($request, ['status' => 'denied', 'public_note' => '   '], $this->admin);
            $this->fail('A blank denial reason was accepted.');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->assertArrayHasKey('public_note', $exception->errors());
        }
        $this->assertSame('pending', $request->refresh()->status);

        $workflow->transition($request, ['status' => 'denied', 'public_note' => 'Not in this year budget.'], $this->admin);
        $workflow->transition($request, ['status' => 'denied', 'internal_note' => 'Re-save keeps the first reason.'], $this->admin);
        $this->assertSame('Denied: Not in this year budget.', $request->refresh()->current_public_response);
    }

    public function test_supply_request_denial_needs_a_reason_but_other_statuses_do_not(): void
    {
        $supply = \App\Models\StationSupplyRequest::query()->create([
            'station_id' => $this->station->id, 'request_text' => '[QA TEST] Paper towels', 'status' => 'open',
            'created_by_name' => 'Member', 'created_by_shift' => 'A',
        ]);
        $workflow = app(\App\Services\StationSupplyRequestWorkflowService::class);

        $workflow->update($supply, $this->admin, ['status' => 'ordered']);
        $this->assertSame('ordered', $supply->refresh()->status);

        foreach ([[], ['public_response' => ''], ['public_response' => 'no']] as $extra) {
            try {
                $workflow->update($supply, $this->admin, ['status' => 'denied'] + $extra);
                $this->fail('A supply denial without a reason was accepted.');
            } catch (\Illuminate\Validation\ValidationException $exception) {
                $this->assertArrayHasKey('public_response', $exception->errors());
            }
            $this->assertSame('ordered', $supply->refresh()->status);
        }

        $workflow->update($supply, $this->admin, ['status' => 'denied', 'public_response' => '  Stocked by the city contract.  ']);
        $supply->refresh();
        $this->assertSame('denied', $supply->status);
        $this->assertSame('Stocked by the city contract.', $supply->public_response);

        $workflow->update($supply, $this->admin, ['status' => 'denied', 'admin_notes' => 'Internal follow-up only.']);
        $this->assertSame('Stocked by the city contract.', $supply->refresh()->public_response);
    }

    public function test_station_viewer_cannot_process_or_archive_requests_without_manage_permission(): void
    {
        $request = $this->request('pending', '[QA TEST] viewer authorization repair', 'normal');
        $viewer = User::factory()->create();
        $viewer->givePermissionTo([
            Permission::findOrCreate('admin.access', 'web'),
            Permission::findOrCreate('admin.stations.view', 'web'),
        ]);
        $this->actingAs($viewer);
        Livewire::test(ViewStationRequest::class, ['record' => $request->getRouteKey()])
            ->assertActionHidden('update_workflow')->assertActionHidden('archive');
        try {
            app(StationRequestWorkflowService::class)->transition($request, ['status' => 'acknowledged'], $viewer);
            $this->fail('A station viewer processed a request.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(RequestArchivalService::class)->archive($request, $viewer);
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
