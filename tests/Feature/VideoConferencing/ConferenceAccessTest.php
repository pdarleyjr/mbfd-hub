<?php

namespace Tests\Feature\VideoConferencing;

use App\Contracts\VideoConferencing\ConferenceProvider;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\FakeConferenceProvider;
use Tests\TestCase;

class ConferenceAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_sent_to_employee_login_with_the_full_intended_query(): void
    {
        $response = $this->get('/employee/video-conferencing?room=lineup&join_as=sta1');

        $response->assertRedirect('/employee/login');
        $this->assertSame(
            '/employee/video-conferencing?join_as=sta1&room=lineup',
            session('employee.intended_path'),
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
            'video-conferencing.livekit.api_key' => 'private-api-key',
            'video-conferencing.livekit.api_secret' => 'private-api-secret',
        ]);
        $this->withoutVite();
        $employee = $this->employee();

        $this->actingAs($employee, 'employee')->get('/employee/video-conferencing')
            ->assertOk()
            ->assertSee('video-conferencing-root')
            ->assertDontSee($employee->employee_id)
            ->assertDontSee('private-api-key')
            ->assertDontSee('private-api-secret');
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

    public function test_homepage_exposes_the_full_width_video_conferencing_launch(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Video Conferencing')
            ->assertSee('Join Morning Lineup or connect directly with an MBFD station')
            ->assertSee('/employee/video-conferencing', false);
    }

    public function test_health_endpoint_is_admin_only_and_reports_disabled_without_provider_access(): void
    {
        $provider = new FakeConferenceProvider;
        $this->app->instance(ConferenceProvider::class, $provider);

        $this->getJson('/admin/video-conferencing/health')->assertUnauthorized();
        $this->actingAs(User::factory()->create(), 'web')
            ->getJson('/admin/video-conferencing/health')
            ->assertOk()
            ->assertJsonPath('status', 'disabled');
        $this->assertSame([], $provider->createdRooms);
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
