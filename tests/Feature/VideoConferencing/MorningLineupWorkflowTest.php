<?php

namespace Tests\Feature\VideoConferencing;

use App\Contracts\VideoConferencing\ConferenceProvider;
use App\Models\Employee;
use App\Models\VideoConferenceParticipation;
use App\Models\VideoConferenceSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Fakes\FakeConferenceProvider;
use Tests\TestCase;

class MorningLineupWorkflowTest extends TestCase
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
            'video-conferencing.livekit.profile' => 'cloud',
            'video-conferencing.livekit.profiles.cloud.url' => 'wss://cloud.video.test.example',
            'video-conferencing.livekit.profiles.cloud.api_url' => 'https://cloud.video.test.example',
            'video-conferencing.livekit.profiles.cloud.api_key' => 'cloud-key',
            'video-conferencing.livekit.profiles.cloud.api_secret' => 'cloud-secret',
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

    public function test_public_station_launch_is_intent_bound_and_ready_does_not_touch_livekit(): void
    {
        $this->withoutVite();

        $this->get('/video-conferencing/stations/1')
            ->assertOk()
            ->assertSee('video-conferencing-root')
            ->assertSee('"entry_mode":"station"', false)
            ->assertSee('"join_as":"sta1"', false);
        $launchContext = $this->latestLaunchContext();

        $this->postJson('/video-conferencing/api/lineup/ready', [
            'launch_context' => $launchContext,
            'join_as' => 'sta4',
            'camera_ready' => true,
            'microphone_ready' => true,
        ])->assertOk()
            ->assertJsonPath('station.join_as', 'sta1')
            ->assertJsonPath('station.ready', true);

        $this->assertSame([], $this->provider->createdRooms);
        $this->assertSame([], $this->provider->issuedTokens);
        $this->assertSame(0, VideoConferenceSession::query()->count());
        $this->assertSame(0, VideoConferenceParticipation::query()->count());
    }

    public function test_same_browser_may_deliberately_launch_a_different_station(): void
    {
        $this->withoutVite();
        $this->get('/video-conferencing/stations/1')->assertOk();
        $stationOneContext = $this->latestLaunchContext();
        $this->get('/video-conferencing/stations/4')->assertOk();
        $stationFourContext = $this->latestLaunchContext();

        $this->assertNotSame($stationOneContext, $stationFourContext);
        $this->postJson('/video-conferencing/api/lineup/ready', [
            'launch_context' => $stationOneContext,
            'camera_ready' => true,
            'microphone_ready' => true,
        ])->assertJsonPath('station.join_as', 'sta1');
        $this->postJson('/video-conferencing/api/lineup/ready', [
            'launch_context' => $stationFourContext,
            'camera_ready' => true,
            'microphone_ready' => true,
        ])->assertJsonPath('station.join_as', 'sta4');

        $this->postJson('/video-conferencing/api/lineup/stand-down', [
            'launch_context' => $stationOneContext,
        ])->assertNoContent();
        $this->getJson('/video-conferencing/api/lineup/status?launch_context='.$stationOneContext)
            ->assertJsonPath('station.ready', false);
        $this->getJson('/video-conferencing/api/lineup/status?launch_context='.$stationFourContext)
            ->assertJsonPath('station.ready', true);
    }

    public function test_300_reuses_employee_session_and_requires_server_side_pin_before_start(): void
    {
        $this->withoutVite();
        $this->get('/employee/video-conferencing/command')->assertRedirect('/employee/login');
        $this->actingAs($this->employee, 'employee')
            ->get('/employee/video-conferencing/command')
            ->assertOk()
            ->assertSee('"entry_mode":"command"', false)
            ->assertSee('"join_as":"300"', false);

        $this->actingAs($this->employee, 'employee')
            ->postJson('/employee/video-conferencing/api/lineup/start')
            ->assertForbidden();
        $this->actingAs($this->employee, 'employee')
            ->postJson('/employee/video-conferencing/api/lineup/command/authorize', ['command_pin' => '1111'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('command_pin');
        $this->actingAs($this->employee, 'employee')
            ->postJson('/employee/video-conferencing/api/lineup/command/authorize', ['command_pin' => self::COMMAND_PIN])
            ->assertOk()
            ->assertJsonPath('authorized', true);

        $started = $this->actingAs($this->employee, 'employee')
            ->postJson('/employee/video-conferencing/api/lineup/start')
            ->assertOk()
            ->assertJsonPath('participant.identity', 'mbfd:300')
            ->assertJsonPath('participant.name', 'Captain Taylor Morgan — 300');

        $this->assertNotNull($started->json('session.id'));
        $this->assertCount(1, $this->provider->createdRooms);
        $this->assertCount(1, $this->provider->issuedTokens);
    }

    public function test_ready_station_joins_only_after_start_and_body_cannot_mutate_identity(): void
    {
        $this->withoutVite();
        $this->get('/video-conferencing/stations/1')->assertOk();
        $launchContext = $this->latestLaunchContext();
        $this->postJson('/video-conferencing/api/lineup/ready', [
            'launch_context' => $launchContext,
            'camera_ready' => true,
            'microphone_ready' => true,
        ])->assertOk();

        $this->postJson('/video-conferencing/api/lineup/token', [
            'launch_context' => $launchContext,
            'join_as' => 'sta4',
        ])->assertConflict()->assertJsonPath('code', 'lineup_not_started');

        $this->authorizeCommand();
        $this->actingAs($this->employee, 'employee')
            ->postJson('/employee/video-conferencing/api/lineup/start')
            ->assertOk();

        $this->postJson('/video-conferencing/api/lineup/token', [
            'launch_context' => $launchContext,
            'join_as' => 'sta4',
        ])->assertOk()
            ->assertJsonPath('participant.identity', 'mbfd:sta1')
            ->assertJsonPath('participant.name', 'Station 1')
            ->assertJsonPath('server_url', 'wss://cloud.video.test.example');

        $this->assertSame('mbfd:sta1', $this->provider->issuedTokens[1]['identity']);
    }

    public function test_end_closes_room_clears_readiness_and_disconnects_lineup(): void
    {
        $this->withoutVite();
        $this->get('/video-conferencing/stations/6')->assertOk();
        $launchContext = $this->latestLaunchContext();
        $this->postJson('/video-conferencing/api/lineup/ready', [
            'launch_context' => $launchContext,
            'camera_ready' => true,
            'microphone_ready' => true,
        ])->assertOk();
        $this->authorizeCommand();
        $started = $this->actingAs($this->employee, 'employee')
            ->postJson('/employee/video-conferencing/api/lineup/start')
            ->assertOk();

        $this->actingAs($this->employee, 'employee')
            ->postJson('/employee/video-conferencing/api/lineup/end')
            ->assertOk()
            ->assertJsonPath('lineup.active', false);

        $this->assertSame([$this->provider->createdRooms[0]['room']], $this->provider->closedRooms);
        $this->assertNull(VideoConferenceSession::query()->findOrFail($started->json('session.id'))->active_key);
        $this->getJson('/video-conferencing/api/lineup/status?launch_context='.$launchContext)
            ->assertOk()
            ->assertJsonPath('station.ready', false)
            ->assertJsonPath('lineup.active', false);
    }

    public function test_300_direct_call_is_visible_only_to_target_station_and_station_identity_is_bound(): void
    {
        $this->withoutVite();
        $this->get('/video-conferencing/stations/4')->assertOk();
        $stationFourContext = $this->latestLaunchContext();
        $this->postJson('/video-conferencing/api/lineup/ready', [
            'launch_context' => $stationFourContext,
            'camera_ready' => true,
            'microphone_ready' => true,
        ])->assertOk();

        $this->get('/video-conferencing/stations/3')->assertOk();
        $stationThreeContext = $this->latestLaunchContext();
        $this->postJson('/video-conferencing/api/lineup/ready', [
            'launch_context' => $stationThreeContext,
            'camera_ready' => true,
            'microphone_ready' => true,
        ])->assertOk();

        $this->authorizeCommand();
        $started = $this->actingAs($this->employee, 'employee')
            ->postJson('/employee/video-conferencing/api/direct/start', ['station' => 'sta4'])
            ->assertOk()
            ->assertJsonPath('session.type', 'direct')
            ->assertJsonPath('session.target_station', 'sta4')
            ->assertJsonPath('participant.identity', 'mbfd:300');

        $this->getJson('/video-conferencing/api/lineup/status?launch_context='.$stationFourContext)
            ->assertOk()
            ->assertJsonPath('direct.active', true)
            ->assertJsonPath('direct.target_station', 'sta4');
        $this->getJson('/video-conferencing/api/lineup/status?launch_context='.$stationThreeContext)
            ->assertOk()
            ->assertJsonPath('direct.active', false);

        $this->postJson('/video-conferencing/api/lineup/token', [
            'launch_context' => $stationThreeContext,
            'room' => 'direct',
        ])->assertConflict()->assertJsonPath('code', 'direct_not_started');
        $this->postJson('/video-conferencing/api/lineup/token', [
            'launch_context' => $stationFourContext,
            'room' => 'direct',
            'join_as' => 'sta1',
        ])->assertOk()
            ->assertJsonPath('participant.identity', 'mbfd:sta4')
            ->assertJsonPath('participant.join_as', 'sta4');

        $this->actingAs($this->employee, 'employee')
            ->postJson('/employee/video-conferencing/api/sessions/'.$started->json('session.id').'/end')
            ->assertOk()
            ->assertJsonPath('active', false);

        $this->assertCount(1, $this->provider->closedRooms);
    }

    private function authorizeCommand(): void
    {
        $this->actingAs($this->employee, 'employee')
            ->postJson('/employee/video-conferencing/api/lineup/command/authorize', [
                'command_pin' => self::COMMAND_PIN,
            ])->assertOk();
    }

    private function latestLaunchContext(): string
    {
        $launches = session('video_conferencing.launches', []);
        $this->assertNotEmpty($launches);

        return (string) array_key_last($launches);
    }
}
