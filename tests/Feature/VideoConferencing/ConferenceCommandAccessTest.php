<?php

declare(strict_types=1);

namespace Tests\Feature\VideoConferencing;

use App\Contracts\VideoConferencing\ConferenceProvider;
use App\Data\VideoConferencing\ConferenceParticipant;
use App\Http\Middleware\EnsureEmployeeAuthenticated;
use App\Models\Employee;
use App\Models\User;
use App\Models\VideoConferenceSession;
use App\Services\Security\Command300Access;
use App\Services\VideoConferencing\ConferenceCommandAuthorizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Fakes\FakeConferenceProvider;
use Tests\TestCase;

/**
 * 300 Command video controls follow the central Command300Access decision,
 * evaluated live on every request. There is no PIN and no session marker.
 */
class ConferenceCommandAccessTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/employee/video-conferencing/api';

    private FakeConferenceProvider $provider;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'broadcasting.default' => 'null',
            'video-conferencing.enabled' => true,
            'video-conferencing.livekit.url' => 'wss://video.test.example',
        ]);
        $this->provider = new FakeConferenceProvider;
        $this->app->instance(ConferenceProvider::class, $this->provider);
        Permission::findOrCreate(Command300Access::PERMISSION, 'web');
    }

    public function test_captain_starts_the_lineup_without_a_pin(): void
    {
        $captain = $this->member('Captain');

        $this->actAs($captain)->postJson(self::BASE.'/lineup/start')
            ->assertOk()
            ->assertJsonPath('participant.identity', 'mbfd:300')
            ->assertJsonPath('participant.join_as', '300');
    }

    public function test_division_chief_starts_the_lineup(): void
    {
        $this->actAs($this->member('Division Chief'))->postJson(self::BASE.'/lineup/start')->assertOk();
    }

    public function test_super_admin_with_an_employee_profile_starts_the_lineup(): void
    {
        $user = $this->member('Firefighter');
        $user->assignRole(Role::findOrCreate('super_admin', 'web'));

        $this->actAs($user)->postJson(self::BASE.'/lineup/start')->assertOk();
    }

    public function test_admin_with_direct_admin_access_permission_starts_the_lineup(): void
    {
        $user = $this->member('Firefighter');
        $user->givePermissionTo(Permission::findOrCreate('admin.access', 'web'));

        $this->actAs($user)->postJson(self::BASE.'/lineup/start')->assertOk();
    }

    public function test_firefighter_with_a_direct_command_grant_starts_the_lineup_and_a_direct_call(): void
    {
        $user = $this->member('Firefighter');
        $user->givePermissionTo(Command300Access::PERMISSION);

        $this->actAs($user)->postJson(self::BASE.'/lineup/start')->assertOk();
        $this->actAs($user)->postJson(self::BASE.'/direct/start', ['station' => 'sta2'])->assertOk();
    }

    public function test_unqualified_members_are_denied_every_command_endpoint(): void
    {
        $captain = $this->member('Captain');
        $started = $this->actAs($captain)->postJson(self::BASE.'/lineup/start')->assertOk()->json();

        foreach (['Lieutenant', 'Firefighter'] as $rank) {
            $member = $this->member($rank);
            $denied = ConferenceCommandAuthorizationService::DENIED_MESSAGE;

            $this->actAs($member)->postJson(self::BASE.'/lineup/start')->assertForbidden()->assertJsonPath('message', $denied);
            $this->actAs($member)->postJson(self::BASE.'/direct/start', ['station' => 'sta1'])->assertForbidden();
            $this->actAs($member)->getJson(self::BASE.'/lineup/command/status')->assertForbidden();
            $this->actAs($member)->postJson(self::BASE.'/lineup/end')->assertForbidden();
            $this->actAs($member)->postJson(self::BASE.'/sessions/'.$started['session']['id'].'/end')->assertForbidden();
            $this->actAs($member)->postJson(self::BASE.'/lineup/command/authorize')->assertForbidden();
            $this->actAs($member)->postJson(self::BASE.'/lineup/command/authorize', ['command_pin' => '2468'])->assertForbidden();
        }

        $this->assertNull(VideoConferenceSession::query()->findOrFail($started['session']['id'])->ended_at);
    }

    public function test_unqualified_member_cannot_escalate_to_a_command_token_or_direct_session_with_join_as_300(): void
    {
        $captain = $this->member('Captain');
        $this->actAs($captain)->postJson(self::BASE.'/sessions', ['room' => 'direct', 'station' => 'sta3', 'join_as' => '300'])
            ->assertOk();
        $direct = VideoConferenceSession::query()->where('type', 'direct')->firstOrFail();
        $rooms = count($this->provider->createdRooms);
        $tokens = count($this->provider->issuedTokens);

        $lieutenant = $this->member('Lieutenant');
        $this->actAs($lieutenant)->postJson(self::BASE.'/sessions', ['room' => 'direct', 'station' => 'sta3', 'join_as' => '300', 'command_pin' => '2468'])
            ->assertForbidden();
        $this->actAs($lieutenant)->postJson(self::BASE.'/sessions/'.$direct->id.'/token', ['join_as' => '300', 'command_pin' => '2468'])
            ->assertForbidden();

        $this->assertCount($rooms, $this->provider->createdRooms);
        $this->assertCount($tokens, $this->provider->issuedTokens);
    }

    public function test_lineup_token_for_an_unqualified_member_stays_self_even_when_asking_for_300(): void
    {
        $this->actAs($this->member('Captain'))->postJson(self::BASE.'/lineup/start')->assertOk();
        $session = VideoConferenceSession::query()->where('type', 'lineup')->firstOrFail();
        $firefighter = $this->member('Firefighter');

        $this->actAs($firefighter)->postJson(self::BASE.'/sessions/'.$session->id.'/token', ['join_as' => '300'])
            ->assertOk()
            ->assertJsonPath('participant.join_as', 'self')
            ->assertJsonPath('participant.identity', 'mbfd:member:'.$firefighter->employee_profile_id);
    }

    public function test_ordinary_self_participation_still_works_for_an_unqualified_member(): void
    {
        $this->actAs($this->member('Captain'))->postJson(self::BASE.'/lineup/start')->assertOk();
        $member = $this->member('Firefighter');

        $this->actAs($member)->postJson(self::BASE.'/sessions', ['room' => 'lineup'])->assertOk()->assertJsonPath('session.type', 'lineup');
    }

    public function test_demotion_takes_effect_on_the_next_request(): void
    {
        $captain = $this->member('Captain');
        $this->actAs($captain)->postJson(self::BASE.'/lineup/start')->assertOk();
        $this->actAs($captain)->getJson(self::BASE.'/lineup/command/status')->assertOk();

        Employee::query()->whereKey($captain->employee_profile_id)->update(['rank' => 'Lieutenant']);

        $this->actAs($captain)->getJson(self::BASE.'/lineup/command/status')->assertForbidden();
        $this->actAs($captain)->postJson(self::BASE.'/lineup/end')->assertForbidden();
        $this->actAs($captain)->postJson(self::BASE.'/direct/start', ['station' => 'sta1'])->assertForbidden();
    }

    public function test_revoking_a_direct_grant_takes_effect_on_the_next_request(): void
    {
        $user = $this->member('Firefighter');
        $user->givePermissionTo(Command300Access::PERMISSION);
        $this->actAs($user)->getJson(self::BASE.'/lineup/command/status')->assertOk();

        $user->revokePermissionTo(Command300Access::PERMISSION);

        $this->actAs($user)->getJson(self::BASE.'/lineup/command/status')->assertForbidden();
    }

    public function test_moderation_requires_live_access_in_addition_to_an_active_command_participation(): void
    {
        $captain = $this->member('Captain');
        $started = $this->actAs($captain)->postJson(self::BASE.'/lineup/start')->assertOk()->json();
        $room = $this->provider->createdRooms[0]['room'];
        $this->provider->roomParticipants[$room] = [new ConferenceParticipant('mbfd:300', 'Captain — 300')];
        $mute = self::BASE.'/sessions/'.$started['session']['id'].'/moderation/mute-stations';

        $this->actAs($captain)->postJson($mute)->assertOk();

        Employee::query()->whereKey($captain->employee_profile_id)->update(['rank' => 'Lieutenant']);

        $this->actAs($captain)->postJson($mute)->assertForbidden();
        $this->actAs($captain)->postJson(
            self::BASE.'/sessions/'.$started['session']['id'].'/moderation/stations/sta2/microphone',
            ['enabled' => false],
        )->assertForbidden();
    }

    public function test_command_page_reports_access_in_the_bootstrap_and_renders_for_denied_members(): void
    {
        $this->withoutVite();

        $this->actAs($this->member('Captain'))->get('/employee/video-conferencing/command')
            ->assertOk()->assertSee('"command_access":true', false);
        $this->actAs($this->member('Firefighter'))->get('/employee/video-conferencing/command')
            ->assertOk()->assertSee('"command_access":false', false);
    }

    public function test_authorized_administrator_without_a_linked_employee_gets_a_specific_message(): void
    {
        $this->withoutVite();
        $admin = User::factory()->create(['account_status' => 'active', 'must_change_password' => false]);
        $admin->assignRole(Role::findOrCreate('super_admin', 'web'));

        $this->actingAsCanonicalUser($admin)
            ->postJson(self::BASE.'/lineup/start')
            ->assertForbidden()
            ->assertJsonPath('message', EnsureEmployeeAuthenticated::UNLINKED_MESSAGE);
        $this->actingAsCanonicalUser(User::query()->findOrFail($admin->id))
            ->get('/employee/video-conferencing/command')
            ->assertForbidden();
        $this->assertSame([], $this->provider->createdRooms);
    }

    private function member(string $rank): User
    {
        $this->sequence++;
        $employee = Employee::query()->create([
            'employee_id' => 'E-VC-'.$this->sequence,
            'name' => 'Member '.$this->sequence,
            'rank' => $rank,
            'password' => 'not-used-by-tests',
            'must_change_password' => false,
        ]);

        return User::factory()->create([
            'account_status' => 'active',
            'must_change_password' => false,
            'employee_profile_id' => $employee->id,
            'employee_id' => $employee->employee_id,
        ]);
    }

    /** Act as a freshly loaded account so relations never carry a stale rank between requests. */
    private function actAs(User $user): static
    {
        return $this->actingAsCanonicalUser(User::query()->findOrFail($user->id));
    }
}
