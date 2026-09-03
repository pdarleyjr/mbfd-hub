<?php

namespace Tests\Feature\VideoConferencing;

use App\Contracts\VideoConferencing\ConferenceProvider;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Mockery;
use Spatie\Permission\Models\Role;
use Tests\Fakes\FakeConferenceProvider;
use Tests\TestCase;

class ConferenceAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_sent_to_canonical_login_with_the_full_intended_query(): void
    {
        $response = $this->get('/employee/video-conferencing?room=lineup&join_as=sta1');

        $response->assertRedirect('/login');
        $this->assertSame(
            url('/employee/video-conferencing?join_as=sta1&room=lineup'),
            session('url.intended'),
        );
    }

    public function test_disabled_page_is_professional_and_never_loads_media_code(): void
    {
        $this->withoutVite();
        $response = $this->actingAs($this->employee(), 'employee')->get('/employee/video-conferencing');

        $response->assertOk()
            ->assertSee('Video conferencing is not available yet')
            ->assertDontSee('video-conferencing-root')
            ->assertDontSee('livekit', false);
    }

    public function test_enabled_page_has_no_employee_identifier_or_secret_configuration(): void
    {
        config([
            'video-conferencing.enabled' => true,
            'video-conferencing.command_pin_hash' => 'private-command-pin-hash',
            'video-conferencing.livekit.api_key' => 'private-api-key',
            'video-conferencing.livekit.api_secret' => 'private-api-secret',
        ]);
        $this->withoutVite();
        $employee = $this->employee();

        $this->actingAs($employee, 'employee')->get('/employee/video-conferencing')
            ->assertOk()
            ->assertSee('video-conferencing-root')
            ->assertDontSee($employee->employee_id)
            ->assertDontSee('private-command-pin-hash')
            ->assertDontSee('private-api-key')
            ->assertDontSee('private-api-secret');
    }

    public function test_enabled_employee_page_exposes_self_context_without_livekit_credentials_or_url(): void
    {
        config([
            'video-conferencing.enabled' => true,
            'video-conferencing.livekit.url' => 'wss://video.test.example',
            'video-conferencing.livekit.api_key' => 'private-api-key',
            'video-conferencing.livekit.api_secret' => 'private-api-secret',
        ]);
        $this->withoutVite();

        $this->actingAs($this->employee(), 'employee')->get('/employee/video-conferencing')
            ->assertOk()
            ->assertSee('"entry_mode":"self"', false)
            ->assertSee('"join_as":"self"', false)
            ->assertSee('connectivity_failures', false)
            ->assertDontSee('video.test.example', false)
            ->assertDontSee('private-api-key')
            ->assertDontSee('private-api-secret');
    }

    public function test_authenticated_employee_can_report_a_sanitized_client_connection_failure(): void
    {
        config(['video-conferencing.enabled' => true]);
        Log::spy();
        $employee = $this->employee();

        $this->actingAs($employee, 'employee')->postJson(
            '/employee/video-conferencing/api/connectivity-failures',
            [
                'stage' => 'preflight',
                'room' => 'lineup',
                'join_as' => 'sta1',
                'failure_code' => 'conference_network_unreachable',
            ],
        )->assertNoContent();

        Log::shouldHaveReceived('warning')->once()->with(
            'Video conference client connection failed',
            Mockery::on(fn (array $context): bool => $context['employee_id'] === $employee->getKey()
                && $context['stage'] === 'preflight'
                && $context['room'] === 'lineup'
                && $context['join_as'] === 'sta1'
                && $context['failure_code'] === 'conference_network_unreachable'
                && is_string($context['client_ip_hash'])
                && strlen($context['client_ip_hash']) === 64
                && ! array_key_exists('token', $context)),
        );
    }

    public function test_client_failure_telemetry_rejects_unbounded_or_unknown_values(): void
    {
        config(['video-conferencing.enabled' => true]);

        $this->actingAs($this->employee(), 'employee')->postJson(
            '/employee/video-conferencing/api/connectivity-failures',
            [
                'stage' => 'arbitrary-stage',
                'room' => 'arbitrary-room',
                'join_as' => 'arbitrary-role',
                'failure_code' => str_repeat('x', 100),
            ],
        )->assertUnprocessable()
            ->assertJsonValidationErrors(['stage', 'room', 'join_as', 'failure_code']);
    }

    public function test_admin_guard_does_not_authorize_conference_api(): void
    {
        config(['video-conferencing.enabled' => true]);
        $this->app->instance(ConferenceProvider::class, new FakeConferenceProvider);

        $this->actingAs(User::factory()->create(), 'web')
            ->postJson('/employee/video-conferencing/api/sessions', ['room' => 'lineup'])
            ->assertUnauthorized();
    }

    public function test_disabled_api_fails_closed_without_contacting_provider(): void
    {
        $provider = new FakeConferenceProvider;
        $this->app->instance(ConferenceProvider::class, $provider);

        $this->actingAs($this->employee(), 'employee')
            ->postJson('/employee/video-conferencing/api/sessions', ['room' => 'lineup'])
            ->assertServiceUnavailable()
            ->assertJsonPath('code', 'conference_disabled');
        $this->assertSame([], $provider->createdRooms);
    }

    public function test_permissions_policy_is_open_only_on_conference_paths(): void
    {
        $employee = $this->employee();
        $this->withoutVite();

        $this->actingAs($employee, 'employee')->get('/employee/video-conferencing')
            ->assertHeader('Permissions-Policy', 'camera=(self), microphone=(self), geolocation=()');
        $this->get('/')
            ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
    }

    public function test_homepage_does_not_expose_a_video_conferencing_launch(): void
    {
        $this->withoutVite();
        $this->actingAsCanonicalFixture();

        $this->get('/')
            ->assertOk()
            ->assertDontSee('Join Morning Lineup or connect directly with an MBFD station')
            ->assertDontSee('/employee/video-conferencing', false);
    }

    public function test_health_endpoint_is_admin_only_and_reports_disabled_without_provider_access(): void
    {
        $provider = new FakeConferenceProvider;
        $this->app->instance(ConferenceProvider::class, $provider);

        $this->getJson('/admin/video-conferencing/health')->assertUnauthorized();
        $this->actingAs(User::factory()->create(), 'web')
            ->getJson('/admin/video-conferencing/health')
            ->assertForbidden();
        $trainingUser = User::factory()->create();
        $trainingUser->assignRole(Role::findOrCreate('training_admin', 'web'));
        $this->actingAs($trainingUser, 'web')
            ->getJson('/admin/video-conferencing/health')
            ->assertForbidden();
        $admin = User::factory()->create();
        $admin->assignRole(Role::findOrCreate('admin', 'web'));
        $this->actingAs($admin, 'web')
            ->getJson('/admin/video-conferencing/health')
            ->assertOk()
            ->assertJsonPath('status', 'disabled');
        $this->assertSame([], $provider->createdRooms);
    }

    public function test_health_endpoint_reports_degraded_after_repeated_recent_client_failures(): void
    {
        config([
            'video-conferencing.enabled' => true,
            'video-conferencing.client_failure_degraded_threshold' => 3,
        ]);
        $provider = new FakeConferenceProvider;
        $this->app->instance(ConferenceProvider::class, $provider);
        Log::spy();
        $employee = $this->employee();

        foreach (range(1, 3) as $attempt) {
            $this->actingAs($employee, 'employee')->postJson(
                '/employee/video-conferencing/api/connectivity-failures',
                [
                    'stage' => 'preflight',
                    'room' => 'lineup',
                    'join_as' => 'sta1',
                    'failure_code' => 'conference_network_unreachable',
                ],
            )->assertNoContent();
        }

        $admin = User::factory()->create();
        $admin->assignRole(Role::findOrCreate('admin', 'web'));
        $this->actingAs($admin, 'web')
            ->getJson('/admin/video-conferencing/health')
            ->assertOk()
            ->assertJsonPath('status', 'degraded')
            ->assertJsonPath('checks.provider_api', 'healthy')
            ->assertJsonPath('checks.recent_client_connection_failures', 3)
            ->assertJsonPath('client_transport', 'tailnet');
    }

    private function employee(array $overrides = []): Employee
    {
        return Employee::query()->create(array_merge([
            'employee_id' => 'F042',
            'name' => 'Taylor Morgan',
            'rank' => 'Captain',
            'password' => 'ConferenceTest!1',
            'must_change_password' => false,
        ], $overrides));
    }
}
