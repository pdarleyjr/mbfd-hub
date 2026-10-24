<?php

declare(strict_types=1);

namespace Tests\Feature\VideoConferencing;

use App\Contracts\VideoConferencing\ConferenceProvider;
use App\Enums\VideoConferencing\ConferenceJoinRole;
use App\Models\Employee;
use App\Models\User;
use App\Models\VideoConferenceParticipation;
use App\Models\VideoConferenceSession;
use App\Services\Security\Command300Access;
use App\Services\VideoConferencing\ConferenceTokenService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\Fakes\FakeConferenceProvider;
use Tests\TestCase;

/**
 * Member seat capacity and the Morning Lineup time limit, enforced on the server
 * for the status endpoint, the token gate, the start path and the expiry command.
 */
class LineupSeatsAndTimeLimitTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/employee/video-conferencing/api';

    private const STATUS = self::BASE.'/lineup/status';

    private const FULL_MESSAGE = 'Morning Lineup is full right now. You can watch for a seat to open; try again in a moment.';

    private FakeConferenceProvider $provider;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'broadcasting.default' => 'null',
            'video-conferencing.enabled' => true,
            'video-conferencing.timezone' => 'America/New_York',
            'video-conferencing.lineup_max_minutes' => 15,
            'video-conferencing.lineup_time' => null,
            'video-conferencing.member_seats' => 6,
            'video-conferencing.livekit.url' => 'wss://video.test.example',
        ]);
        $this->provider = new FakeConferenceProvider;
        $this->app->instance(ConferenceProvider::class, $this->provider);
        Permission::findOrCreate(Command300Access::PERMISSION, 'web');
    }

    // ---- Seats -------------------------------------------------------------------------

    public function test_the_seat_limit_follows_the_runbook_plan_of_six_member_seats(): void
    {
        $this->assertSame(6, app(\App\Services\VideoConferencing\ConferenceSeatService::class)->limit());

        // A smaller room never promises more member seats than it has beyond the six endpoint places.
        config(['video-conferencing.livekit.max_participants' => 8, 'video-conferencing.member_seats' => 6]);
        $this->assertSame(2, app(\App\Services\VideoConferencing\ConferenceSeatService::class)->limit());
    }

    public function test_a_full_lineup_refuses_the_next_member_before_creating_a_participation_or_token(): void
    {
        config(['video-conferencing.member_seats' => 2]);
        $sessionId = $this->startLineupAs($this->member('Captain'))['session']['id'];
        $first = $this->member('Firefighter');
        $second = $this->member('Firefighter');
        $third = $this->member('Firefighter');
        $this->joinAs($first, $sessionId)->assertOk();
        $this->joinAs($second, $sessionId)->assertOk();
        $tokens = count($this->provider->issuedTokens);
        $rows = VideoConferenceParticipation::query()->count();

        $this->joinAs($third, $sessionId)
            ->assertStatus(409)
            ->assertJsonPath('code', 'room_full')
            ->assertJsonPath('message', self::FULL_MESSAGE);

        $this->assertCount($tokens, $this->provider->issuedTokens);
        $this->assertSame($rows, VideoConferenceParticipation::query()->count());
        $this->assertSame(0, VideoConferenceParticipation::query()->where('employee_id', $third->employee_profile_id)->count());
    }

    public function test_a_seat_opens_when_a_member_leaves(): void
    {
        config(['video-conferencing.member_seats' => 1]);
        $sessionId = $this->startLineupAs($this->member('Captain'))['session']['id'];
        $first = $this->member('Firefighter');
        $waiting = $this->member('Firefighter');
        $participation = $this->joinAs($first, $sessionId)->assertOk()->json('participation_id');
        $this->joinAs($waiting, $sessionId)->assertStatus(409)->assertJsonPath('code', 'room_full');

        $this->actAs($first)->postJson(self::BASE.'/participations/'.$participation.'/leave')->assertNoContent();

        $this->joinAs($waiting, $sessionId)->assertOk();
    }

    public function test_stations_and_300_never_use_or_wait_for_member_seats(): void
    {
        config(['video-conferencing.member_seats' => 1]);
        $captain = $this->member('Captain');
        $started = $this->startLineupAs($captain);
        $session = VideoConferenceSession::query()->findOrFail($started['session']['id']);
        $this->joinAs($this->member('Firefighter'), $session->id)->assertOk();
        $this->joinAs($this->member('Firefighter'), $session->id)->assertStatus(409)->assertJsonPath('code', 'room_full');

        // 300's rejoin and every station can still be issued a token while members fill the room.
        $this->startLineupAs($captain, takeover: true);
        $employee = Employee::query()->findOrFail($captain->employee_profile_id);
        foreach (ConferenceJoinRole::stationRoles() as $station) {
            $issued = app(ConferenceTokenService::class)->issue($session, $employee, $station);
            $this->assertSame('mbfd:'.$station->value, $issued['participation']->participant_identity);
        }
    }

    public function test_a_member_rejoining_never_competes_with_their_own_seat(): void
    {
        config(['video-conferencing.member_seats' => 1]);
        $sessionId = $this->startLineupAs($this->member('Captain'))['session']['id'];
        $member = $this->member('Firefighter');
        $this->joinAs($member, $sessionId)->assertOk();

        // The only seat is theirs: the answer is the existing "already connected" takeover offer, not "full".
        $this->joinAs($member, $sessionId)->assertStatus(409)->assertJsonPath('code', 'endpoint_in_use');
        $this->joinAs($member, $sessionId, takeover: true)->assertOk();
        $this->actAs($member)->getJson(self::STATUS)->assertJsonPath('full', false);
    }

    public function test_status_reports_full_only_for_members_who_do_not_hold_a_seat(): void
    {
        config(['video-conferencing.member_seats' => 1]);
        $sessionId = $this->startLineupAs($this->member('Captain'))['session']['id'];
        $holder = $this->member('Firefighter');
        $other = $this->member('Firefighter');
        $this->actAs($other)->getJson(self::STATUS)->assertJsonPath('state', 'active')->assertJsonPath('full', false);

        $this->joinAs($holder, $sessionId)->assertOk();

        $this->actAs($other)->getJson(self::STATUS)->assertJsonPath('state', 'active')->assertJsonPath('full', true);
        $this->actAs($holder)->getJson(self::STATUS)->assertJsonPath('full', false);
    }

    public function test_an_unconfirmed_join_that_never_connected_frees_its_seat_after_the_token_lifetime(): void
    {
        config(['video-conferencing.member_seats' => 1]);
        $sessionId = $this->startLineupAs($this->member('Captain'))['session']['id'];
        $orphaned = $this->member('Firefighter');
        $waiting = $this->member('Firefighter');
        $this->joinAs($orphaned, $sessionId)->assertOk();
        $this->joinAs($waiting, $sessionId)->assertStatus(409)->assertJsonPath('code', 'room_full');

        // Never confirmed by LiveKit (no joined_at) and the 600 s token is long dead.
        VideoConferenceParticipation::query()->update(['token_issued_at' => now()->subMinutes(12)]);
        $this->joinAs($waiting, $sessionId)->assertOk();
    }

    public function test_a_confirmed_member_keeps_their_seat_beyond_the_token_lifetime(): void
    {
        config(['video-conferencing.member_seats' => 1, 'video-conferencing.lineup_max_minutes' => 30]);
        $sessionId = $this->startLineupAs($this->member('Captain'))['session']['id'];
        $this->joinAs($this->member('Firefighter'), $sessionId)->assertOk();
        VideoConferenceParticipation::query()->update(['token_issued_at' => now()->subMinutes(12), 'joined_at' => now()->subMinutes(11)]);

        $this->joinAs($this->member('Firefighter'), $sessionId)->assertStatus(409)->assertJsonPath('code', 'room_full');
    }

    public function test_the_last_seat_goes_to_exactly_one_of_two_members_who_ask_back_to_back(): void
    {
        // Real concurrency needs two database connections; here the second request must observe the
        // first one's row. Under MySQL/PostgreSQL the session row lock makes that true for parallel requests.
        config(['video-conferencing.member_seats' => 1]);
        $sessionId = $this->startLineupAs($this->member('Captain'))['session']['id'];
        $one = $this->member('Firefighter');
        $two = $this->member('Firefighter');

        $results = [
            $this->joinAs($one, $sessionId)->status(),
            $this->joinAs($two, $sessionId)->status(),
        ];

        sort($results);
        $this->assertSame([200, 409], $results);
        $this->assertSame(1, VideoConferenceParticipation::query()->where('join_as', 'self')->count());
    }

    // ---- Time limit --------------------------------------------------------------------

    public function test_a_75_minute_old_lineup_reads_ended_and_cannot_be_joined_even_before_the_cron_runs(): void
    {
        $captain = $this->member('Captain');
        $sessionId = $this->startLineupAs($captain)['session']['id'];
        $session = VideoConferenceSession::query()->findOrFail($sessionId);
        $session->update(['started_at' => now()->subMinutes(75)]);
        $firefighter = $this->member('Firefighter');

        $status = $this->actAs($firefighter)->getJson(self::STATUS)->assertOk();
        $status->assertJsonPath('state', 'ended')->assertJsonPath('session_id', null)->assertJsonPath('ends_at', null);
        $this->assertTrue(
            $session->fresh()->started_at->addMinutes(15)->equalTo(CarbonImmutable::parse($status->json('ended_at'))),
            'ended_at reports the time limit when the row has not been closed yet.',
        );
        $this->assertNull($session->fresh()->ended_at, 'The status read itself never writes.');

        $this->joinAs($firefighter, $sessionId)->assertStatus(410);
        $this->assertSame(0, VideoConferenceParticipation::query()->where('join_as', 'self')->count());
    }

    public function test_the_300_can_restart_a_lineup_that_ran_past_its_limit_before_the_cron_closed_it(): void
    {
        $captain = $this->member('Captain');
        $oldId = $this->startLineupAs($captain)['session']['id'];
        VideoConferenceSession::query()->whereKey($oldId)->update(['started_at' => now()->subMinutes(20)]);
        $this->travelTo(now()->addSecond());

        $newId = $this->startLineupAs($captain)['session']['id'];

        $this->assertNotSame($oldId, $newId);
        $this->assertNotNull(VideoConferenceSession::query()->findOrFail($oldId)->ended_at);
        $this->actAs($this->member('Firefighter'))->getJson(self::STATUS)->assertJsonPath('state', 'active')->assertJsonPath('session_id', $newId);
    }

    public function test_a_lineup_started_at_2350_eastern_stays_live_after_midnight_then_is_expired_on_time(): void
    {
        $captain = $this->member('Captain');
        $firefighter = $this->member('Firefighter');
        $this->travelTo(CarbonImmutable::parse('2026-08-12T03:50:00Z')); // 23:50 on 11 Aug in New York
        $id = $this->startLineupAs($captain)['session']['id'];
        $this->assertSame('lineup:2026-08-11', VideoConferenceSession::query()->findOrFail($id)->logical_key);

        // 00:03 on 12 Aug: a new service day, but the lineup is inside its limit, so it is live and joinable.
        $this->travelTo(CarbonImmutable::parse('2026-08-12T04:03:00Z'));
        $live = $this->actAs($firefighter)->getJson(self::STATUS)->assertOk();
        $live->assertJsonPath('state', 'active')->assertJsonPath('session_id', $id);
        $this->assertTrue(CarbonImmutable::parse('2026-08-12T04:05:00Z')->equalTo(CarbonImmutable::parse($live->json('ends_at'))));
        $joined = $this->actAs($firefighter)->postJson(self::BASE.'/sessions', ['room' => 'lineup'])->assertOk()->assertJsonPath('session.id', $id);
        $this->assertTrue(CarbonImmutable::parse('2026-08-12T04:05:00Z')->equalTo(CarbonImmutable::parse($joined->json('session.ends_at'))), 'The join response carries the end time of the lineup being joined.');
        $this->joinAs($firefighter, $id)->assertOk();
        // The 300 reconnects into the same lineup instead of starting a second one for the new day.
        $this->assertSame($id, $this->startLineupAs($captain, takeover: true)['session']['id']);
        $this->artisan('video-conferencing:expire-lineup')->assertSuccessful();
        $this->assertNull(VideoConferenceSession::query()->findOrFail($id)->ended_at, 'Not yet 00:05, so the cron leaves it running.');

        // 00:06: past the limit. Over, never "not started yet" for a lineup that is still running.
        $this->travelTo(CarbonImmutable::parse('2026-08-12T04:06:00Z'));
        $this->actAs($firefighter)->getJson(self::STATUS)->assertJsonPath('state', 'not_started');
        $this->joinAs($this->member('Firefighter'), $id)->assertStatus(410);

        $this->artisan('video-conferencing:expire-lineup')->assertSuccessful();

        $session = VideoConferenceSession::query()->findOrFail($id);
        $this->assertNotNull($session->ended_at, 'The expiry is date-independent.');
        $this->assertNull($session->active_key);
        $this->assertContains($session->livekit_room_name, $this->provider->closedRooms);
        $this->assertSame(0, VideoConferenceParticipation::query()->where('session_id', $id)->whereNull('left_at')->count());
        $this->actAs($firefighter)->getJson(self::STATUS)->assertJsonPath('state', 'not_started');
    }

    public function test_a_stale_row_that_never_finished_provisioning_is_cleared_by_the_cron_without_a_provider_call(): void
    {
        $date = CarbonImmutable::now('America/New_York')->toDateString();
        $row = VideoConferenceSession::query()->create([
            'type' => 'lineup',
            'logical_key' => 'lineup:'.$date,
            'active_key' => 'lineup:'.$date,
            'livekit_profile' => app(\App\Services\VideoConferencing\LiveKitProfileConfiguration::class)->profile(),
            'livekit_room_name' => 'mbfd-lineup-'.$date.'-neverbuilt01',
            'started_at' => now()->subMinutes(40),
            'provisioned_at' => null,
        ]);

        $this->artisan('video-conferencing:expire-lineup')->assertSuccessful();

        $this->assertNotNull($row->fresh()->ended_at);
        $this->assertSame([], $this->provider->closedRooms);
    }

    public function test_a_status_read_survives_an_invalid_livekit_profile_and_says_unavailable(): void
    {
        $this->startLineupAs($this->member('Captain'));
        config(['video-conferencing.livekit.profile' => 'bogus']);

        $this->actAs($this->member('Firefighter'))->getJson(self::STATUS)
            ->assertOk()
            ->assertJsonPath('state', 'unavailable')
            ->assertJsonPath('session_id', null)
            ->assertJsonPath('full', false);
    }

    // ---- Helpers -----------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function startLineupAs(User $captain, bool $takeover = false): array
    {
        return $this->actAs($captain)
            ->postJson(self::BASE.'/lineup/start', ['confirmed_takeover' => $takeover])
            ->assertOk()
            ->json();
    }

    private function joinAs(User $member, string $sessionId, bool $takeover = false): \Illuminate\Testing\TestResponse
    {
        return $this->actAs($member)->postJson(self::BASE.'/sessions/'.$sessionId.'/token', [
            'join_as' => 'self',
            'confirmed_takeover' => $takeover,
        ]);
    }

    private function member(string $rank): User
    {
        $this->sequence++;
        $employee = Employee::query()->create([
            'employee_id' => 'E-LST-'.$this->sequence,
            'name' => 'Seat Member '.$this->sequence,
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
