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
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Fakes\FakeConferenceProvider;
use Tests\TestCase;

class ConferenceWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private const COMMAND_PIN = '2468';

    private FakeConferenceProvider $provider;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'broadcasting.default' => 'null',
            'video-conferencing.enabled' => true,
            'video-conferencing.command_pin_hash' => Hash::make(self::COMMAND_PIN),
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

    public function test_ordinary_lineup_lookup_does_not_create_a_room_or_token(): void
    {
        $this->actingAs($this->employee, 'employee')
            ->postJson('/employee/video-conferencing/api/sessions', ['room' => 'lineup'])
            ->assertConflict()
            ->assertJsonPath('code', 'lineup_not_started');

        $this->assertSame([], $this->provider->createdRooms);
        $this->assertSame([], $this->provider->issuedTokens);
        $this->assertSame(0, VideoConferenceSession::query()->count());
    }

    public function test_command_start_uses_one_opaque_room_and_new_york_service_date(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-12T02:30:00Z'));
        try {
            $started = $this->authorizeAndStart();
            $this->assertArrayNotHasKey('livekit_room_name', $started['session']);
            $this->assertCount(1, $this->provider->createdRooms);
            $this->assertMatchesRegularExpression(
                '/^mbfd-lineup-2026-08-11-[a-zA-Z0-9]{12}$/',
                $this->provider->createdRooms[0]['room'],
            );
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_provider_outage_returns_stable_503_and_removes_unprovisioned_room(): void
    {
        $provider = new class extends FakeConferenceProvider
        {
            public function createRoom(string $roomName, string $metadata): void
            {
                throw new ConferenceUnavailableException('The video conferencing service is temporarily unavailable.');
            }
        };
        $this->app->instance(ConferenceProvider::class, $provider);
        $this->authorizeCommand();

        $this->actingAs($this->employee, 'employee')
            ->postJson('/employee/video-conferencing/api/lineup/start')
            ->assertServiceUnavailable()
            ->assertJsonPath('code', 'conference_unavailable');
        $this->assertSame(0, VideoConferenceSession::query()->count());
    }

    public function test_employee_lineup_token_is_stable_self_identity_and_ignores_submitted_station_role(): void
    {
        $started = $this->authorizeAndStart();
        $response = $this->actingAs($this->employee, 'employee')->postJson(
            "/employee/video-conferencing/api/sessions/{$started['session']['id']}/token",
            ['join_as' => 'sta4', 'display_name' => 'Fake Name', 'identity' => 'mbfd:300'],
        );

        $response->assertOk()
            ->assertJsonPath('participant.identity', 'mbfd:member:'.$this->employee->getKey())
            ->assertJsonPath('participant.name', 'Captain Taylor Morgan')
            ->assertJsonPath('participant.join_as', 'self')
            ->assertJsonPath('server_url', 'wss://video.test.example');
        $this->assertStringNotContainsString(
            $this->employee->employee_id,
            json_encode($response->json(), JSON_THROW_ON_ERROR),
        );
    }

    public function test_300_can_start_direct_station_call_only_with_valid_pin(): void
    {
        $payload = ['room' => 'direct', 'station' => 'sta3', 'join_as' => '300'];

        $this->actingAs($this->employee, 'employee')->postJson(
            '/employee/video-conferencing/api/sessions',
            $payload,
        )->assertUnprocessable()->assertJsonValidationErrors('command_pin');
        $this->actingAs($this->employee, 'employee')->postJson(
            '/employee/video-conferencing/api/sessions',
            [...$payload, 'command_pin' => self::COMMAND_PIN],
        )->assertOk()->assertJsonPath('session.target_station', 'sta3');
        $this->assertCount(1, $this->provider->createdRooms);
    }

    public function test_incorrect_300_pin_attempts_are_rate_limited_by_employee_and_ip(): void
    {
        $ipAddress = '203.0.113.10';
        $employeeKey = 'conference-command-pin:employee:'.$this->employee->getKey();
        $ipKey = 'conference-command-pin:ip:'.hash('sha256', $ipAddress);

        try {
            for ($attempt = 0; $attempt < 5; $attempt++) {
                $this->actingAs($this->employee, 'employee')
                    ->withServerVariables(['REMOTE_ADDR' => $ipAddress])
                    ->postJson('/employee/video-conferencing/api/lineup/command/authorize', ['command_pin' => '1111'])
                    ->assertUnprocessable();
            }
            $this->actingAs($this->employee, 'employee')
                ->withServerVariables(['REMOTE_ADDR' => $ipAddress])
                ->postJson('/employee/video-conferencing/api/lineup/command/authorize', ['command_pin' => self::COMMAND_PIN])
                ->assertTooManyRequests();
        } finally {
            RateLimiter::clear($employeeKey);
            RateLimiter::clear($ipKey);
        }
    }

    public function test_connected_300_can_mute_and_give_floor_with_rpc_only_unmute(): void
    {
        $started = $this->authorizeAndStart();
        $room = $this->provider->createdRooms[0]['room'];
        $this->provider->roomParticipants[$room] = [new ConferenceParticipant('mbfd:300', 'Captain Taylor Morgan — 300')];

        $this->actingAs($this->employee, 'employee')->postJson(
            "/employee/video-conferencing/api/sessions/{$started['session']['id']}/moderation/mute-stations",
        )->assertOk()->assertJsonCount(5, 'muted');
        $before = count($this->provider->mutedParticipants);
        $this->actingAs($this->employee, 'employee')->postJson(
            "/employee/video-conferencing/api/sessions/{$started['session']['id']}/moderation/stations/sta2/microphone",
            ['enabled' => true],
        )->assertOk()
            ->assertJsonPath('rpc_required', true)
            ->assertJsonPath('method', 'mbfd.stationMic')
            ->assertJsonPath('identity', 'mbfd:sta2');
        $this->assertCount($before, $this->provider->mutedParticipants);
    }

    public function test_signed_webhook_is_idempotent_and_releases_station_identity(): void
    {
        $this->withoutVite();
        $this->get('/video-conferencing/stations/2')->assertOk();
        $launchContext = (string) array_key_last(session('video_conferencing.launches'));
        $this->postJson('/video-conferencing/api/lineup/ready', [
            'launch_context' => $launchContext,
            'camera_ready' => true,
            'microphone_ready' => true,
        ])->assertOk();
        $this->authorizeAndStart();
        $token = $this->postJson('/video-conferencing/api/lineup/token', [
            'launch_context' => $launchContext,
        ])->assertOk();
        $room = $this->provider->createdRooms[0]['room'];
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
        $participation = VideoConferenceParticipation::query()->findOrFail($token->json('participation_id'));
        $this->assertNull($participation->active_identity_key);
        $this->assertNotNull($participation->left_at);
    }

    /** @return array<string, mixed> */
    private function authorizeAndStart(): array
    {
        $this->authorizeCommand();

        return $this->actingAs($this->employee, 'employee')
            ->postJson('/employee/video-conferencing/api/lineup/start')
            ->assertOk()
            ->json();
    }

    private function authorizeCommand(): void
    {
        $this->actingAs($this->employee, 'employee')
            ->postJson('/employee/video-conferencing/api/lineup/command/authorize', [
                'command_pin' => self::COMMAND_PIN,
            ])->assertOk();
    }
}
