<?php

declare(strict_types=1);

namespace Tests\Feature\MemberReplies;

use App\Enums\AccountStatus;
use App\Filament\Resources\StationRequestResource\Pages\ViewStationRequest;
use App\Models\Employee;
use App\Models\Station;
use App\Models\StationRequest;
use App\Models\SubmissionMessage;
use App\Models\User;
use App\Services\MemberReplies\MemberReplyLedger;
use App\Services\MemberReplies\SubmissionConversationService;
use App\Support\MemberReplies\ReplySubject;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** docs/member-replies.md section 2.3: the staff side, from the admin record page. */
final class AdminConversationActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_see_the_member_reply_and_answer_from_the_station_request_page(): void
    {
        Queue::fake();
        $station = Station::query()->create(['station_number' => 2, 'name' => 'Station 2', 'address' => '2 Test Street', 'is_active' => true]);
        $employee = Employee::query()->create(['employee_id' => '32001', 'name' => 'Pat Requester', 'rank' => 'Firefighter', 'password' => 'unused', 'must_change_password' => false]);
        $member = User::factory()->create(['employee_id' => '32001', 'employee_profile_id' => $employee->id, 'account_status' => AccountStatus::Active]);
        $request = StationRequest::query()->create([
            'station_id' => $station->id, 'actor_user_id' => $member->id, 'requested_by_employee_id' => $employee->id,
            'requester_name_snapshot' => 'Pat Requester', 'request_type' => 'repair_service', 'subject_type' => 'kitchen',
            'title' => 'Kitchen sink leak', 'description' => 'Drips.', 'priority' => 'normal', 'status' => 'pending',
        ]);
        app(SubmissionConversationService::class)->postAsMember($member, ReplySubject::STATION_REQUEST, $request->id, 'Still dripping today.', '4c1d2e3f-5a6b-4c7d-8e9f-0a1b2c3d4e51');

        $staff = User::factory()->create(['account_status' => AccountStatus::Active, 'must_change_password' => false]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (['admin.access', 'admin.stations.view', 'admin.stations.manage'] as $permission) {
            $staff->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs($staff->fresh());
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->withoutVite();

        Livewire::test(ViewStationRequest::class, ['record' => $request->getRouteKey()])
            ->assertActionVisible('member_conversation')
            ->mountAction('member_conversation')
            ->assertSee('Still dripping today.')
            ->setActionData(['body' => 'Plumber is booked for Friday.'])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $reply = SubmissionMessage::query()->where('author_side', SubmissionMessage::SIDE_STAFF)->sole();
        $this->assertSame('Plumber is booked for Friday.', $reply->body);
        $this->assertSame($staff->id, $reply->author_user_id);
        $this->assertFalse(app(MemberReplyLedger::class)->isAwaiting(ReplySubject::STATION_REQUEST, $request->id));
    }
}
