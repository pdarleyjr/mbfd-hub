<?php

namespace Tests\Feature\VideoConferencing;

use App\Contracts\VideoConferencing\ConferenceProvider;
use App\Data\VideoConferencing\ConferenceParticipant;
use App\Data\VideoConferencing\VerifiedConferenceWebhook;
use App\Exceptions\VideoConferencing\ConferenceUnavailableException;
use App\Models\Employee;
use App\Models\VideoConferenceEvent;
use App\Models\VideoConferenceParticipation;
use App\Models\VideoConferenceSession;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\FakeConferenceProvider;
use Tests\TestCase;

class ConferenceWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private FakeConferenceProvider $provider;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'video-conferencing.enabled' => true,
            'video-conferencing.livekit.url' => 'wss://video.test.example',
        ]);
        $this->provider = new FakeConferenceProvider;
        $this->app->instance(ConferenceProvider::class, $this->provider);
        $this->employee = Employee::query()->create([
            'employee_id' => 'F043',
            'name' => 'Taylor Morgan',
            'rank' => 'Captain',
            'password' => 'ConferenceTest!1',
            'must_change_password' => false,
        ]);
    }

    public function test_daily_lineup_uses_one_opaque_server_room_and_never_returns_its_name(): void
    {
        CarbonImmutable::setTestNow('2026-08-11 13:00:00');

        $first = $this->postSession(['room' => 'lineup'])->assertOk()->json('session');
        $second = $this->postSession(['room' => 'lineup'])->json('session');

        $this->assertSame($first['id'], $second['id']);
        $this->assertArrayNotHasKey('livekit_room_name', $first);
        $this->assertCount(1, $this->provider->createdRooms);
        $this->assertMatchesRegularExpression('/^mbfd-lineup-2026-08-11-[a-zA-Z0-9]{12}$/', $this->provider->createdRooms[0]['room']);
        CarbonImmutable::setTestNow();
    }

    public function test_daily_lineup_date_is_calculated_in_america_new_york(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-12T02:30:00Z'));

        $this->postSession(['room' => 'lineup'])->assertOk();

        $this->assertMatchesRegularExpression(
            '/^mbfd-lineup-2026-08-11-[a-zA-Z0-9]{12}$/',
            $this->provider->createdRooms[0]['room'],
        );
        CarbonImmutable::setTestNow();
    }

    public function test_provider_outage_returns_a_stable_503_and_removes_unprovisioned_room(): void
    {
        $provider = new class extends FakeConferenceProvider
        {
            public function createRoom(string $roomName, string $metadata): void
            {
                throw new ConferenceUnavailableException('The video conferencing service is temporarily unavailable.');
            }
        };
        $this->app->instance(ConferenceProvider::class, $provider);

        $this->postSession(['room' => 'lineup'])
            ->assertServiceUnavailable()
            ->assertJsonPath('code', 'conference_unavailable');
        $this->assertSame(0, VideoConferenceSession::query()->count());
    }

    public function test_a_previous_days_lineup_cannot_issue_new_tokens(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-11T16:00:00Z'));
        $session = $this->postSession(['room' => 'lineup'])->json('session');
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-12T16:00:00Z'));

        $this->actingAs($this->employee, 'employee')->postJson(
            "/employee/video-conferencing/api/sessions/{$session['id']}/token",
            ['join_as' => 'self'],
        )->assertGone();

        CarbonImmutable::setTestNow();
    }

    public function test_self_token_uses_ulid_identity_ranked_name_and_no_employee_id(): void
    {
        $session = $this->postSession(['room' => 'lineup'])->json('session');

        $response = $this->actingAs($this->employee, 'employee')->postJson(
            "/employee/video-conferencing/api/sessions/{$session['id']}/token",
            ['join_as' => 'self'],
        );

        $response->assertOk()
            ->assertJsonPath('participant.name', 'Captain Taylor Morgan')
            ->assertJsonPath('participant.join_as', 'self')
            ->assertJsonPath('server_url', 'wss://video.test.example')
            ->assertHeader('Cache-Control', 'no-store, private');
        $identity = $response->json('participant.identity');
        $this->assertMatchesRegularExpression('/^mbfd:member:[0-9A-HJKMNP-TV-Z]{26}$/', $identity);
        $this->assertStringNotContainsString($this->employee->employee_id, json_encode($response->json(), JSON_THROW_ON_ERROR));
        $this->assertSame('signed-test-token', $response->json('token'));
    }

    public function test_fixed_endpoint_requires_an_explicit_confirmed_takeover(): void
    {
        $session = $this->postSession(['room' => 'lineup'])->json('session');
        $room = $this->provider->createdRooms[0]['room'];
        $this->provider->roomParticipants[$room] = [new ConferenceParticipant('mbfd:sta1', 'Station 1')];
        $url = "/employee/video-conferencing/api/sessions/{$session['id']}/token";

        $this->actingAs($this->employee, 'employee')->postJson($url, ['join_as' => 'sta1'])
            ->assertConflict()
            ->assertJsonPath('code', 'endpoint_in_use')
            ->assertJsonPath('takeover_available', true);
        $this->assertSame([], $this->provider->removedParticipants);

        $this->actingAs($this->employee, 'employee')->postJson($url, [
            'join_as' => 'sta1',
            'confirmed_takeover' => true,
        ])->assertOk()->assertJsonPath('participant.identity', 'mbfd:sta1');
        $this->assertCount(1, $this->provider->removedParticipants);
    }

    public function test_direct_calls_allow_only_300_and_the_selected_station(): void
    {
        $session = $this->postSession(['room' => 'direct', 'station' => 'sta3', 'join_as' => '300'])->json('session');
        $url = "/employee/video-conferencing/api/sessions/{$session['id']}/token";

        $this->actingAs($this->employee, 'employee')->postJson($url, ['join_as' => 'sta1'])->assertForbidden();
        $this->actingAs($this->employee, 'employee')->postJson($url, ['join_as' => 'self'])->assertForbidden();
        $this->actingAs($this->employee, 'employee')->postJson($url, ['join_as' => '300'])->assertOk();
    }

    public function test_a_station_cannot_start_a_direct_call_and_sta5_is_rejected(): void
    {
        $this->postSession(['room' => 'direct', 'station' => 'sta2', 'join_as' => 'sta2'])->assertNotFound();
        $this->postSession(['room' => 'direct', 'station' => 'sta5', 'join_as' => '300'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('station');
    }

    public function test_target_station_can_retrieve_but_other_stations_cannot_enter_a_300_started_direct_call(): void
    {
        $started = $this->postSession(['room' => 'direct', 'station' => 'sta4', 'join_as' => '300'])
            ->assertOk()
            ->json('session');
        $target = $this->postSession(['room' => 'direct', 'station' => 'sta4', 'join_as' => 'sta4'])
            ->assertOk()
            ->json('session');

        $this->assertSame($started['id'], $target['id']);
        $this->postSession(['room' => 'direct', 'station' => 'sta4', 'join_as' => 'sta3'])->assertForbidden();
    }

    public function test_only_the_connected_300_employee_can_moderate_and_unmute_is_rpc_only(): void
    {
        $session = $this->postSession(['room' => 'lineup'])->json('session');
        $room = $this->provider->createdRooms[0]['room'];
        $tokenUrl = "/employee/video-conferencing/api/sessions/{$session['id']}/token";
        $this->actingAs($this->employee, 'employee')->postJson($tokenUrl, ['join_as' => '300'])->assertOk();
        $this->provider->roomParticipants[$room] = [new ConferenceParticipant('mbfd:300', '300 (Command)')];

        $this->actingAs($this->employee, 'employee')->postJson(
            "/employee/video-conferencing/api/sessions/{$session['id']}/moderation/mute-stations",
        )->assertOk()->assertJsonCount(5, 'muted');
        $this->assertSame(
            ['mbfd:sta1', 'mbfd:sta2', 'mbfd:sta3', 'mbfd:sta4', 'mbfd:sta6'],
            array_column($this->provider->mutedParticipants, 'identity'),
        );

        $before = count($this->provider->mutedParticipants);
        $this->actingAs($this->employee, 'employee')->postJson(
            "/employee/video-conferencing/api/sessions/{$session['id']}/moderation/stations/sta2/microphone",
            ['enabled' => true],
        )->assertOk()
            ->assertJsonPath('rpc_required', true)
            ->assertJsonPath('method', 'mbfd.stationMic')
            ->assertJsonPath('identity', 'mbfd:sta2');
        $this->assertCount($before, $this->provider->mutedParticipants, 'Remote unmute must never use Room Service.');
    }

    public function test_signed_webhook_events_are_idempotent_and_release_fixed_identity(): void
    {
        $session = $this->postSession(['room' => 'lineup'])->json('session');
        $room = $this->provider->createdRooms[0]['room'];
        $token = $this->actingAs($this->employee, 'employee')->postJson(
            "/employee/video-conferencing/api/sessions/{$session['id']}/token",
            ['join_as' => 'sta2'],
        );
        $participationId = $token->json('participation_id');
        $this->provider->webhook = new VerifiedConferenceWebhook(
            id: 'EV_test_1',
            event: 'participant_left',
            roomName: $room,
            participantIdentity: 'mbfd:sta2',
            occurredAt: CarbonImmutable::now(),
        );

        $this->postJson('/webhooks/livekit', [], ['Authorization' => 'Bearer signed'])->assertNoContent();
        $this->postJson('/webhooks/livekit', [], ['Authorization' => 'Bearer signed'])->assertNoContent();

        $this->assertSame(1, VideoConferenceEvent::query()->count());
        $participation = VideoConferenceParticipation::query()->findOrFail($participationId);
        $this->assertNull($participation->active_identity_key);
        $this->assertNotNull($participation->left_at);
    }

    private function postSession(array $payload): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->employee, 'employee')
            ->postJson('/employee/video-conferencing/api/sessions', $payload);
    }
}
