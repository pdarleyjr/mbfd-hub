<?php

declare(strict_types=1);

namespace Tests\Feature\VideoConferencing;

use App\Contracts\VideoConferencing\ConferenceProvider;
use App\Data\VideoConferencing\VerifiedConferenceWebhook;
use App\Models\Employee;
use App\Models\User;
use App\Models\VideoConferenceParticipation;
use App\Models\VideoConferenceSession;
use App\Services\Security\Command300Access;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\Fakes\FakeConferenceProvider;
use Tests\TestCase;

/**
 * GET employee/video-conferencing/api/lineup/status is the member waiting room's
 * only server input. It is read-only, database-only, open to any signed-in member
 * with a linked profile, and must never create a session, participation or token.
 */
class MemberLineupStatusTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/employee/video-conferencing/api';

    private const STATUS = self::BASE.'/lineup/status';

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
            'video-conferencing.livekit.url' => 'wss://video.test.example',
        ]);
        $this->provider = new FakeConferenceProvider;
        $this->app->instance(ConferenceProvider::class, $this->provider);
        Permission::findOrCreate(Command300Access::PERMISSION, 'web');
    }

    public function test_guests_get_401_for_json_and_a_login_redirect_for_browsers(): void
    {
        $this->getJson(self::STATUS)->assertUnauthorized();
        $this->get(self::STATUS)->assertRedirect(route('login'));
    }

    public function test_an_account_without_a_linked_personnel_profile_is_refused_and_learns_nothing(): void
    {
        $this->startLineupAs($this->member('Captain'));
        $unlinked = User::factory()->create(['account_status' => 'active', 'must_change_password' => false]);

        $response = $this->actingAsCanonicalUser(User::query()->findOrFail($unlinked->id))->getJson(self::STATUS);

        $response->assertForbidden();
        $this->assertArrayNotHasKey('state', $response->json() ?? []);
        $this->assertArrayNotHasKey('session_id', $response->json() ?? []);
    }

    public function test_before_anyone_starts_it_reports_not_started_and_creates_nothing(): void
    {
        $firefighter = $this->member('Firefighter');

        $response = $this->actAs($firefighter)->getJson(self::STATUS)->assertOk();

        $response->assertJsonPath('state', 'not_started')
            ->assertJsonPath('session_id', null)
            ->assertJsonPath('started_at', null)
            ->assertJsonPath('ends_at', null)
            ->assertJsonPath('ended_at', null);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertNotNull($response->json('server_time'));
        $this->assertNothingWasCreated();
    }

    public function test_repeated_polling_never_creates_a_session_participation_or_token(): void
    {
        $firefighter = $this->member('Firefighter');
        for ($i = 0; $i < 8; $i++) {
            $this->actAs($firefighter)->getJson(self::STATUS)->assertOk();
        }
        $this->assertNothingWasCreated();

        $this->startLineupAs($this->member('Captain'));
        $sessions = VideoConferenceSession::query()->count();
        $participations = VideoConferenceParticipation::query()->count();
        $rooms = count($this->provider->createdRooms);
        $tokens = count($this->provider->issuedTokens);

        for ($i = 0; $i < 8; $i++) {
            $this->actAs($firefighter)->getJson(self::STATUS)->assertOk()->assertJsonPath('state', 'active');
        }

        $this->assertSame($sessions, VideoConferenceSession::query()->count());
        $this->assertSame($participations, VideoConferenceParticipation::query()->count());
        $this->assertCount($rooms, $this->provider->createdRooms);
        $this->assertCount($tokens, $this->provider->issuedTokens);
    }

    public function test_status_never_calls_the_media_provider(): void
    {
        $this->startLineupAs($this->member('Captain'));
        $this->app->instance(ConferenceProvider::class, new class extends FakeConferenceProvider
        {
            public function healthCheck(): bool
            {
                throw new \LogicException('The member status must not call the provider.');
            }

            public function participants(string $roomName): array
            {
                throw new \LogicException('The member status must not call the provider.');
            }

            public function createRoom(string $roomName, string $metadata): void
            {
                throw new \LogicException('The member status must not call the provider.');
            }

            public function closeRoom(string $roomName): void
            {
                throw new \LogicException('The member status must not call the provider.');
            }

            public function issueToken(string $roomName, string $identity, string $displayName, string $metadata): \App\Data\VideoConferencing\IssuedConferenceToken
            {
                throw new \LogicException('The member status must not call the provider.');
            }
        });

        $this->actAs($this->member('Firefighter'))->getJson(self::STATUS)->assertOk()->assertJsonPath('state', 'active');
    }

    public function test_an_unprovisioned_row_reads_as_starting_without_a_join_id_and_then_unavailable_when_stale(): void
    {
        $session = $this->insertSession(['provisioned_at' => null, 'started_at' => now()]);

        $this->actAs($this->member('Firefighter'))->getJson(self::STATUS)
            ->assertOk()
            ->assertJsonPath('state', 'starting')
            // A starting lineup cannot be joined yet (the token path answers 503), so no id is offered.
            ->assertJsonPath('session_id', null)
            ->assertJsonPath('ends_at', null);

        $session->forceFill(['started_at' => now()->subMinutes(5)])->save();
        $this->actAs($this->member('Firefighter'))->getJson(self::STATUS)
            ->assertOk()
            ->assertJsonPath('state', 'unavailable')
            ->assertJsonPath('session_id', null);
    }

    public function test_a_failed_start_leaves_no_row_so_status_returns_to_not_started(): void
    {
        $this->app->instance(ConferenceProvider::class, new class extends FakeConferenceProvider
        {
            public function createRoom(string $roomName, string $metadata): void
            {
                throw new \App\Exceptions\VideoConferencing\ConferenceUnavailableException('The video conferencing service is temporarily unavailable.');
            }
        });
        $this->actAs($this->member('Captain'))->postJson(self::BASE.'/lineup/start')->assertServiceUnavailable();

        $this->actAs($this->member('Firefighter'))->getJson(self::STATUS)->assertOk()->assertJsonPath('state', 'not_started');
    }

    public function test_active_lineup_exposes_only_the_whitelisted_fields_and_the_shared_end_time(): void
    {
        $started = $this->startLineupAs($this->member('Captain'));
        $session = VideoConferenceSession::query()->findOrFail($started['session']['id']);

        $response = $this->actAs($this->member('Firefighter'))->getJson(self::STATUS)->assertOk();

        $response->assertJsonPath('state', 'active')->assertJsonPath('session_id', $session->id);
        $this->assertSame(
            ['ended_at', 'ends_at', 'full', 'scheduled_for', 'server_time', 'session_id', 'started_at', 'state'],
            collect($response->json())->keys()->sort()->values()->all(),
        );
        $this->assertTrue($session->started_at->addMinutes(15)->equalTo(CarbonImmutable::parse($response->json('ends_at'))));
        $body = $response->getContent();
        $this->assertStringNotContainsString($session->livekit_room_name, (string) $body);
        $this->assertStringNotContainsString('mbfd:', (string) $body);
        $this->assertStringNotContainsString('participants', (string) $body);
        $this->assertStringNotContainsString('token', (string) $body);
        $this->assertStringNotContainsString('station', (string) $body);
    }

    public function test_the_end_time_uses_the_clamped_lineup_limit(): void
    {
        $this->startLineupAs($this->member('Captain'));
        $session = VideoConferenceSession::query()->firstOrFail();

        config(['video-conferencing.lineup_max_minutes' => 600]);
        $this->assertSame(30, $this->minutesUntilEnd($session));
        config(['video-conferencing.lineup_max_minutes' => 1]);
        $this->assertSame(5, $this->minutesUntilEnd($session));
    }

    public function test_scheduled_for_follows_the_configured_new_york_lineup_time(): void
    {
        config(['video-conferencing.lineup_time' => '07:00']);
        $this->travelTo(CarbonImmutable::parse('2026-08-11T10:00:00Z'));

        $response = $this->actAs($this->member('Firefighter'))->getJson(self::STATUS)->assertOk();

        $response->assertJsonPath('state', 'not_started');
        $this->assertTrue(CarbonImmutable::parse('2026-08-11T11:00:00Z')->equalTo(CarbonImmutable::parse($response->json('scheduled_for'))));
    }

    public function test_ended_by_300_reports_ended_with_the_end_time_and_no_join_id(): void
    {
        $captain = $this->member('Captain');
        $this->startLineupAs($captain);
        $this->actAs($captain)->postJson(self::BASE.'/lineup/end')->assertOk();

        $response = $this->actAs($this->member('Firefighter'))->getJson(self::STATUS)->assertOk();

        $response->assertJsonPath('state', 'ended')->assertJsonPath('session_id', null)->assertJsonPath('ends_at', null);
        $this->assertNotNull($response->json('ended_at'));
    }

    public function test_ended_by_the_time_limit_and_by_the_room_finished_webhook_report_ended(): void
    {
        $this->startLineupAs($this->member('Captain'));
        $session = VideoConferenceSession::query()->firstOrFail();
        $session->update(['started_at' => now()->subMinutes(16)]);
        $this->artisan('video-conferencing:expire-lineup')->assertSuccessful();
        $this->actAs($this->member('Firefighter'))->getJson(self::STATUS)->assertOk()->assertJsonPath('state', 'ended');

        $this->travelTo(now()->addSecond());
        $this->startLineupAs($this->member('Captain'));
        $second = VideoConferenceSession::query()->whereNull('ended_at')->firstOrFail();
        $this->provider->webhook = new VerifiedConferenceWebhook(
            id: 'EV_room_finished_1',
            event: 'room_finished',
            roomName: $second->livekit_room_name,
            participantIdentity: null,
            occurredAt: CarbonImmutable::now(),
        );
        $this->postJson('/webhooks/livekit', [], ['Authorization' => 'Bearer signed'])->assertNoContent();

        $this->actAs($this->member('Firefighter'))->getJson(self::STATUS)->assertOk()->assertJsonPath('state', 'ended');
    }

    public function test_a_restart_the_same_day_is_active_again_with_a_new_session_id(): void
    {
        $captain = $this->member('Captain');
        $firefighter = $this->member('Firefighter');
        $first = $this->startLineupAs($captain)['session']['id'];
        $this->actAs($captain)->postJson(self::BASE.'/lineup/end')->assertOk();
        $this->actAs($firefighter)->getJson(self::STATUS)->assertJsonPath('state', 'ended');

        $this->travelTo(now()->addSeconds(30));
        $second = $this->startLineupAs($captain)['session']['id'];
        $this->assertNotSame($first, $second);

        $this->actAs($firefighter)->getJson(self::STATUS)
            ->assertOk()
            ->assertJsonPath('state', 'active')
            ->assertJsonPath('session_id', $second);
    }

    public function test_the_service_day_changes_at_midnight_new_york_time(): void
    {
        $captain = $this->member('Captain');
        $firefighter = $this->member('Firefighter');

        // 23:30 on 11 Aug in New York (03:30Z on the 12th): lineup runs and is live.
        $this->travelTo(CarbonImmutable::parse('2026-08-12T03:30:00Z'));
        $eveningId = $this->startLineupAs($captain)['session']['id'];
        $this->actAs($firefighter)->getJson(self::STATUS)->assertJsonPath('state', 'active')->assertJsonPath('session_id', $eveningId);

        // 00:05 on 12 Aug in New York: UTC date is the same, but the service day rolled over.
        $this->travelTo(CarbonImmutable::parse('2026-08-12T04:05:00Z'));
        $this->actAs($firefighter)->getJson(self::STATUS)
            ->assertOk()
            ->assertJsonPath('state', 'not_started')
            ->assertJsonPath('session_id', null);

        // Yesterday's ended lineup is "not started", never "ended today".
        VideoConferenceSession::query()->whereKey($eveningId)->update(['active_key' => null, 'ended_at' => '2026-08-12 03:40:00']);
        $this->actAs($firefighter)->getJson(self::STATUS)->assertJsonPath('state', 'not_started');

        // A lineup started after midnight is the new day's own session.
        $morningId = $this->startLineupAs($captain)['session']['id'];
        $this->assertNotSame($eveningId, $morningId);
        $this->actAs($firefighter)->getJson(self::STATUS)->assertJsonPath('state', 'active')->assertJsonPath('session_id', $morningId);
    }

    public function test_just_before_midnight_in_new_york_is_still_the_same_service_day(): void
    {
        $captain = $this->member('Captain');
        $this->travelTo(CarbonImmutable::parse('2026-08-12T03:50:00Z'));
        $id = $this->startLineupAs($captain)['session']['id'];
        $this->travelTo(CarbonImmutable::parse('2026-08-12T03:59:30Z'));

        $this->actAs($this->member('Firefighter'))->getJson(self::STATUS)
            ->assertJsonPath('state', 'active')
            ->assertJsonPath('session_id', $id);
    }

    public function test_a_plain_firefighter_can_read_status_but_cannot_start_or_end(): void
    {
        $captain = $this->member('Captain');
        $started = $this->startLineupAs($captain);
        $firefighter = $this->member('Firefighter');

        $this->actAs($firefighter)->getJson(self::STATUS)->assertOk()->assertJsonPath('state', 'active');
        $this->actAs($firefighter)->postJson(self::BASE.'/lineup/start')->assertForbidden();
        $this->actAs($firefighter)->postJson(self::BASE.'/lineup/end')->assertForbidden();
        $this->actAs($firefighter)->postJson(self::BASE.'/sessions/'.$started['session']['id'].'/end')->assertForbidden();
        $this->actAs($firefighter)->getJson(self::BASE.'/lineup/command/status')->assertForbidden();

        $this->assertNull(VideoConferenceSession::query()->findOrFail($started['session']['id'])->ended_at);
        $this->actAs($captain)->getJson(self::STATUS)->assertOk()->assertJsonPath('state', 'active');
    }

    public function test_the_payload_is_identical_for_a_300_user_and_a_firefighter(): void
    {
        $captain = $this->member('Captain');
        $this->startLineupAs($captain);

        $asCaptain = $this->actAs($captain)->getJson(self::STATUS)->json();
        $asFirefighter = $this->actAs($this->member('Firefighter'))->getJson(self::STATUS)->json();

        unset($asCaptain['server_time'], $asFirefighter['server_time']);
        $this->assertSame($asCaptain, $asFirefighter);
    }

    public function test_joining_still_goes_through_the_token_gate_as_self_after_reading_status(): void
    {
        $this->startLineupAs($this->member('Captain'));
        $firefighter = $this->member('Firefighter');
        $id = $this->actAs($firefighter)->getJson(self::STATUS)->json('session_id');
        $this->assertCount(1, $this->provider->issuedTokens);

        $this->actAs($firefighter)->postJson(self::BASE.'/sessions/'.$id.'/token', ['join_as' => '300'])
            ->assertOk()
            ->assertJsonPath('participant.join_as', 'self')
            ->assertJsonPath('participant.identity', 'mbfd:member:'.$firefighter->employee_profile_id);
        $this->assertCount(2, $this->provider->issuedTokens);
    }

    public function test_the_feature_flag_still_applies(): void
    {
        config(['video-conferencing.enabled' => false]);

        $this->actAs($this->member('Firefighter'))->getJson(self::STATUS)
            ->assertServiceUnavailable()
            ->assertJsonPath('code', 'conference_disabled');
    }

    public function test_status_reads_have_their_own_per_employee_limit_and_say_when_to_retry(): void
    {
        $firefighter = $this->member('Firefighter');
        for ($i = 0; $i < 30; $i++) {
            $this->actAs($firefighter)->getJson(self::STATUS)->assertOk();
        }

        $limited = $this->actAs($firefighter)->getJson(self::STATUS)->assertStatus(429);
        $this->assertNotNull($limited->headers->get('Retry-After'));
        // Another member on the same address is unaffected by this member's polling.
        $this->actAs($this->member('Firefighter'))->getJson(self::STATUS)->assertOk();
    }

    public function test_member_status_polling_cannot_starve_the_300_or_the_stations_shared_bucket(): void
    {
        $captain = $this->member('Captain');
        $this->startLineupAs($captain);
        // Many waiting members polling from one address, each at their own limit.
        for ($member = 0; $member < 3; $member++) {
            $firefighter = $this->member('Firefighter');
            for ($i = 0; $i < 30; $i++) {
                $this->actAs($firefighter)->getJson(self::STATUS)->assertOk();
            }
        }

        // The 300's own status feed and the stations' shared endpoints still answer.
        $this->actAs($captain)->getJson(self::BASE.'/lineup/command/status')->assertOk();
        $this->assertNotSame(429, $this->getJson('/video-conferencing/api/lineup/status')->status());
    }

    public function test_the_member_page_bootstrap_points_at_the_status_route(): void
    {
        $this->withoutVite();

        $this->actAs($this->member('Firefighter'))->get('/employee/video-conferencing')
            ->assertOk()
            ->assertSee('lineup_status', false)
            ->assertSee('api\/lineup\/status', false)
            ->assertDontSee('video.test.example', false);
    }

    private function assertNothingWasCreated(): void
    {
        $this->assertSame(0, VideoConferenceSession::query()->count());
        $this->assertSame(0, VideoConferenceParticipation::query()->count());
        $this->assertSame([], $this->provider->createdRooms);
        $this->assertSame([], $this->provider->issuedTokens);
    }

    private function minutesUntilEnd(VideoConferenceSession $session): int
    {
        $response = $this->actAs($this->member('Firefighter'))->getJson(self::STATUS)->assertOk();

        return (int) $session->started_at->diffInMinutes(CarbonImmutable::parse($response->json('ends_at')), true);
    }

    /** @param array<string, mixed> $overrides */
    private function insertSession(array $overrides = []): VideoConferenceSession
    {
        $date = CarbonImmutable::now('America/New_York')->toDateString();

        return VideoConferenceSession::query()->create(array_merge([
            'type' => 'lineup',
            'logical_key' => 'lineup:'.$date,
            'active_key' => 'lineup:'.$date,
            'livekit_profile' => app(\App\Services\VideoConferencing\LiveKitProfileConfiguration::class)->profile(),
            'livekit_room_name' => 'mbfd-lineup-'.$date.'-testroom0001',
            'started_at' => now(),
            'provisioned_at' => now(),
        ], $overrides));
    }

    /** @return array<string, mixed> */
    private function startLineupAs(User $captain): array
    {
        return $this->actAs($captain)->postJson(self::BASE.'/lineup/start')->assertOk()->json();
    }

    private function member(string $rank): User
    {
        $this->sequence++;
        $employee = Employee::query()->create([
            'employee_id' => 'E-LS-'.$this->sequence,
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
