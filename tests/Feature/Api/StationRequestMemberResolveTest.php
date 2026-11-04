<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\AccountStatus;
use App\Jobs\FanOutNotification;
use App\Models\Employee;
use App\Models\Room;
use App\Models\RoomAsset;
use App\Models\Station;
use App\Models\StationRequest;
use App\Models\User;
use App\Services\Dashboards\Sources\Station\StationRequestSource;
use App\Support\Dashboards\DashboardQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class StationRequestMemberResolveTest extends TestCase
{
    use RefreshDatabase;

    private const FIRST_ACTION_ID = '0b1f6f3e-6a0a-4d1a-9d39-2f1d7f8a1111';

    private const SECOND_ACTION_ID = '0b1f6f3e-6a0a-4d1a-9d39-2f1d7f8a2222';

    private Station $station;

    private User $requester;

    private User $other;

    private StationRequest $request;

    protected function setUp(): void
    {
        parent::setUp();

        $this->station = Station::query()->create([
            'station_number' => 1,
            'name' => 'Station 1',
            'address' => '1 Test Street',
            'is_active' => true,
        ]);
        $this->requester = $this->member('30001', 'Pat Requester');
        $this->other = $this->member('30002', 'Float Firefighter');
        $this->request = $this->makeRequest();
    }

    public function test_requester_resolves_with_one_history_row_and_a_retry_returns_the_first_result(): void
    {
        Queue::fake();
        $this->actingAsCanonicalUser($this->requester);

        $first = $this->resolve(['client_action_id' => self::FIRST_ACTION_ID, 'outcome' => 'fixed'])
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.status_label', 'Completed');

        $request = $this->request->fresh();
        $this->assertSame('completed', $request->status);
        $this->assertSame('member_resolved', $request->status_detail);
        $this->assertNotNull($request->completed_at);
        $this->assertSame('Marked resolved by Pat Requester: Fixed.', $request->current_public_response);

        $update = $request->updates()->sole();
        $this->assertSame('completed', $update->status);
        $this->assertSame($this->requester->id, $update->changed_by_user_id);
        $this->assertSame('member_resolved', $update->metadata['event']);
        $this->assertSame('fixed', $update->metadata['outcome']);
        $this->assertSame(self::FIRST_ACTION_ID, $update->metadata['client_action_id']);
        $this->assertTrue($update->metadata['resolver_is_requester']);

        // The requester resolved it, so only station staff are told.
        Queue::assertPushed(FanOutNotification::class, 1);

        $retry = $this->resolve(['client_action_id' => self::FIRST_ACTION_ID, 'outcome' => 'fixed'])->assertOk();
        $this->assertSame($first->json(), $retry->json());
        $this->assertSame(1, $request->updates()->count());
        Queue::assertPushed(FanOutNotification::class, 1);
    }

    public function test_requester_matched_by_employee_profile_alone_may_resolve_without_a_note(): void
    {
        $this->request->forceFill(['actor_user_id' => null, 'requested_by_employee_id' => $this->requester->employee_profile_id])->save();
        $this->actingAsCanonicalUser($this->requester);

        $this->resolve(['client_action_id' => self::FIRST_ACTION_ID, 'outcome' => 'no_longer_needed'])->assertOk();

        $this->assertSame('Marked resolved by Pat Requester: No longer needed.', $this->request->fresh()->current_public_response);
    }

    public function test_another_member_must_give_a_note_and_the_requester_is_told(): void
    {
        Queue::fake();
        $this->actingAsCanonicalUser($this->other);

        $this->resolve(['client_action_id' => self::FIRST_ACTION_ID, 'outcome' => 'fixed'])
            ->assertUnprocessable()->assertJsonValidationErrors('note');
        $this->resolve(['client_action_id' => self::FIRST_ACTION_ID, 'outcome' => 'fixed', 'note' => 'ok'])
            ->assertUnprocessable()->assertJsonValidationErrors('note');
        $this->assertSame('pending', $this->request->fresh()->status);
        $this->assertSame(0, $this->request->updates()->count());

        $this->resolve(['client_action_id' => self::FIRST_ACTION_ID, 'outcome' => 'fixed', 'note' => 'Replaced the  faucet washer.'])
            ->assertOk()->assertJsonPath('data.status', 'completed');

        $request = $this->request->fresh();
        $this->assertSame('Marked resolved by Float Firefighter: Fixed. Replaced the faucet washer.', $request->current_public_response);
        $this->assertFalse($request->updates()->sole()->metadata['resolver_is_requester']);
        // Station staff and the requester each get one alert.
        Queue::assertPushed(FanOutNotification::class, 2);
    }

    public function test_bad_input_is_refused_without_writing(): void
    {
        $this->actingAsCanonicalUser($this->requester);

        $this->resolve(['client_action_id' => self::FIRST_ACTION_ID, 'outcome' => 'fixed', 'note' => str_repeat('a', 1001)])
            ->assertUnprocessable()->assertJsonValidationErrors('note');
        $this->resolve(['client_action_id' => self::FIRST_ACTION_ID, 'outcome' => 'banana'])
            ->assertUnprocessable()->assertJsonValidationErrors('outcome');
        $this->resolve(['client_action_id' => 'not-a-uuid', 'outcome' => 'fixed'])
            ->assertUnprocessable()->assertJsonValidationErrors('client_action_id');
        $this->assertSame('pending', $this->request->fresh()->status);
        $this->assertSame(0, $this->request->updates()->count());
    }

    public function test_accounts_without_a_profile_or_that_are_not_active_are_refused(): void
    {
        $noProfile = User::factory()->create(['employee_profile_id' => null, 'employee_id' => null]);
        $this->actingAsCanonicalUser($noProfile);
        $this->resolve(['client_action_id' => self::FIRST_ACTION_ID, 'outcome' => 'fixed', 'note' => 'Fixed it myself.'])->assertForbidden();

        $trainingOnly = User::factory()->create(['employee_profile_id' => null, 'employee_id' => null]);
        $trainingOnly->assignRole(Role::query()->firstOrCreate(['name' => 'training_admin', 'guard_name' => 'web']));
        $this->actingAsCanonicalUser($trainingOnly);
        $this->resolve(['client_action_id' => self::SECOND_ACTION_ID, 'outcome' => 'fixed', 'note' => 'Fixed it myself.'])->assertForbidden();

        $this->actingAsCanonicalUser($this->other);
        $this->other->forceFill(['account_status' => AccountStatus::Disabled])->save();
        $this->assertContains(
            $this->resolve(['client_action_id' => self::FIRST_ACTION_ID, 'outcome' => 'fixed', 'note' => 'Fixed it myself.'])->status(),
            [401, 403],
        );

        $this->assertSame('pending', $this->request->fresh()->status);
        $this->assertSame(0, $this->request->updates()->count());
    }

    public function test_a_closed_or_archived_request_returns_409_with_its_current_status(): void
    {
        $this->actingAsCanonicalUser($this->requester);
        $closed = $this->makeRequest(['status' => 'denied', 'denied_at' => now()]);

        $this->postJson("/api/public/station-requests/{$closed->id}/member-resolve", ['client_action_id' => self::FIRST_ACTION_ID, 'outcome' => 'fixed'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'REQUEST_CLOSED')
            ->assertJsonPath('status', 'denied')
            ->assertJsonPath('status_label', 'Denied');
        $this->assertSame(0, $closed->updates()->count());

        $this->request->forceFill(['archived_at' => now()])->save();
        $this->resolve(['client_action_id' => self::SECOND_ACTION_ID, 'outcome' => 'fixed'])
            ->assertStatus(409)->assertJsonPath('code', 'REQUEST_CLOSED');
    }

    public function test_a_second_resolve_after_the_first_is_a_conflict_not_a_second_row(): void
    {
        $this->actingAsCanonicalUser($this->requester);
        $this->resolve(['client_action_id' => self::FIRST_ACTION_ID, 'outcome' => 'fixed'])->assertOk();

        $this->actingAsCanonicalUser($this->other);
        $this->resolve(['client_action_id' => self::SECOND_ACTION_ID, 'outcome' => 'fixed', 'note' => 'Also fixed it.'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'REQUEST_CLOSED')
            ->assertJsonPath('status', 'completed');
        $this->assertSame(1, $this->request->updates()->count());
    }

    public function test_the_same_action_id_from_a_different_member_is_a_conflict(): void
    {
        $this->actingAsCanonicalUser($this->requester);
        $this->resolve(['client_action_id' => self::FIRST_ACTION_ID, 'outcome' => 'fixed'])->assertOk();

        $this->actingAsCanonicalUser($this->other);
        $this->resolve(['client_action_id' => self::FIRST_ACTION_ID, 'outcome' => 'fixed', 'note' => 'Same key, other person.'])
            ->assertStatus(409)->assertJsonPath('code', 'CLIENT_ACTION_CONFLICT');
        $this->assertSame(1, $this->request->updates()->count());
    }

    public function test_the_staff_dashboard_source_sees_the_member_resolution(): void
    {
        $staff = $this->staff();
        $source = app(StationRequestSource::class);
        $this->actingAsCanonicalUser($staff);
        $before = $source->detail($staff, (string) $this->request->id);
        $this->assertNotNull($before);

        $this->actingAsCanonicalUser($this->requester);
        $this->resolve(['client_action_id' => self::FIRST_ACTION_ID, 'outcome' => 'received'])->assertOk();

        $this->actingAsCanonicalUser($staff);
        $after = $source->detail($staff, (string) $this->request->id);
        $this->assertNotNull($after);
        $this->assertNotSame($before->issue->version, $after->issue->version, 'a member update is a real, non-receipt update');
        $this->assertSame('completed', $after->issue->status);
        $this->assertSame('resolved', $after->issue->stage);
        $this->assertStringContainsString('Marked resolved by Pat Requester: Received.', $after->timeline[array_key_last($after->timeline)]['text']);
        $this->assertCount(1, $source->rows($staff, DashboardQuery::now(), 50));
    }

    public function test_staff_may_reopen_a_member_resolved_request_but_not_an_ordinary_completed_one(): void
    {
        $this->actingAsCanonicalUser($this->requester);
        $this->resolve(['client_action_id' => self::FIRST_ACTION_ID, 'outcome' => 'fixed'])->assertOk();

        $this->actingAsCanonicalUser($this->staff());
        $this->patchJson("/api/admin/station-requests/{$this->request->id}/transition", [
            'status' => 'in_progress',
            'public_note' => 'Still dripping, sending someone back.',
        ])->assertOk()->assertJsonPath('data.status', 'in_progress');

        $reopened = $this->request->fresh();
        $this->assertSame('in_progress', $reopened->status);
        $this->assertNull($reopened->completed_at);
        $this->assertNull($reopened->status_detail);
        $this->assertSame('Still dripping, sending someone back.', $reopened->current_public_response);
        $this->assertSame(2, $reopened->updates()->count());
        $this->assertTrue($reopened->is_open);

        // A completion that staff recorded stays terminal.
        $ordinary = $this->makeRequest();
        $this->patchJson("/api/admin/station-requests/{$ordinary->id}/transition", ['status' => 'completed', 'public_note' => 'Done.'])->assertOk();
        $this->patchJson("/api/admin/station-requests/{$ordinary->id}/transition", ['status' => 'in_progress'])
            ->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->assertSame('completed', $ordinary->fresh()->status);
    }

    public function test_a_member_resolved_request_with_asset_events_stays_terminal(): void
    {
        $room = Room::query()->create(['station_id' => $this->station->id, 'name' => 'Kitchen', 'room_type' => 'kitchen']);
        $asset = RoomAsset::query()->create([
            'room_id' => $room->id, 'asset_tag' => 'S1-KIT-9', 'name' => 'Oven', 'category' => 'appliance',
            'quantity' => 1, 'condition' => 'needs_repair', 'is_active' => true,
        ]);
        $this->request->forceFill(['status' => 'completed', 'status_detail' => 'member_resolved', 'completed_at' => now()])->save();
        $asset->events()->create(['station_request_id' => $this->request->id, 'event_type' => 'repair_completed', 'event_at' => now()]);

        $this->actingAsCanonicalUser($this->staff());
        $this->patchJson("/api/admin/station-requests/{$this->request->id}/transition", ['status' => 'in_progress'])
            ->assertUnprocessable()->assertJsonValidationErrors('status');
    }

    public function test_a_member_cannot_use_the_admin_transition_endpoint(): void
    {
        $this->actingAsCanonicalUser($this->requester);
        $this->resolve(['client_action_id' => self::FIRST_ACTION_ID, 'outcome' => 'fixed'])->assertOk();

        $this->patchJson("/api/admin/station-requests/{$this->request->id}/transition", ['status' => 'in_progress'])
            ->assertForbidden();
        $this->assertSame('completed', $this->request->fresh()->status);
    }

    public function test_lists_carry_member_resolution_for_the_signed_in_member(): void
    {
        $closed = $this->makeRequest(['status' => 'completed', 'completed_at' => now()]);
        $path = "/api/public/stations/{$this->station->id}/requests?scope=all";

        $this->actingAsCanonicalUser($this->requester);
        $rows = collect($this->getJson($path)->assertOk()->json('data'))->keyBy('id');
        $this->assertSame(['eligible' => true, 'note_required' => false], $rows[$this->request->id]['member_resolution']);
        $this->assertSame(['eligible' => false, 'note_required' => false], $rows[$closed->id]['member_resolution']);

        $this->actingAsCanonicalUser($this->other);
        $rows = collect($this->getJson($path)->assertOk()->json('data'))->keyBy('id');
        $this->assertSame(['eligible' => true, 'note_required' => true], $rows[$this->request->id]['member_resolution']);

        $this->actingAsCanonicalUser($this->requester);
        $this->getJson("/api/public/station-requests/{$this->request->id}")
            ->assertOk()
            ->assertJsonPath('data.member_resolution.eligible', true)
            ->assertJsonPath('data.member_resolution.note_required', false);
    }

    /** @param array<string, mixed> $payload */
    private function resolve(array $payload): TestResponse
    {
        return $this->postJson("/api/public/station-requests/{$this->request->id}/member-resolve", $payload);
    }

    /** @param array<string, mixed> $attributes */
    private function makeRequest(array $attributes = []): StationRequest
    {
        return StationRequest::query()->create(array_merge([
            'station_id' => $this->station->id,
            'actor_user_id' => $this->requester->id,
            'requested_by_employee_id' => $this->requester->employee_profile_id,
            'requester_name_snapshot' => 'Pat Requester',
            'request_type' => 'repair_service',
            'subject_type' => 'kitchen',
            'title' => 'Kitchen sink leak',
            'description' => 'The sink drips constantly.',
            'priority' => 'normal',
            'status' => 'pending',
        ], $attributes))->refresh();
    }

    private function member(string $employeeNumber, string $name): User
    {
        $employee = Employee::query()->create([
            'employee_id' => $employeeNumber,
            'name' => $name,
            'rank' => 'Firefighter',
            'password' => Hash::make('test-password-only'),
            'must_change_password' => false,
        ]);

        return User::factory()->create([
            'employee_id' => $employee->employee_id,
            'employee_profile_id' => $employee->id,
            'account_status' => AccountStatus::Active,
        ]);
    }

    private function staff(): User
    {
        $user = User::factory()->create(['account_status' => AccountStatus::Active, 'must_change_password' => false]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (['admin.access', 'admin.stations.view', 'admin.stations.manage'] as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }
}
