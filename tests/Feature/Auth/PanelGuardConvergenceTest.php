<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\AccountStatus;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class PanelGuardConvergenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_panel_login_routes_only_redirect_to_the_canonical_login(): void
    {
        foreach ([
            '/admin/login',
            '/employee/login',
            '/training/login',
            '/workgroups/login',
        ] as $legacyLoginPath) {
            $this->get($legacyLoginPath)->assertRedirect('/login');
        }
    }

    public function test_unauthenticated_panel_deep_links_go_directly_to_the_canonical_login(): void
    {
        foreach (['/admin', '/employee', '/training', '/workgroups'] as $panelPath) {
            $this->get($panelPath)->assertRedirect('/login');
        }
    }

    public function test_canonical_login_returns_an_authorized_employee_to_the_requested_employee_area(): void
    {
        $user = $this->linkedActiveUser('D03-EMPLOYEE');

        $this->get('/employee')->assertRedirect('/login');

        $this->post('/login', [
            'employee_id' => $user->employeeProfile->employee_id,
            'password' => 'correct-password',
        ])->assertRedirect('/employee');

        $this->assertAuthenticatedAs($user, 'web');
        $this->withCookie((string) config('session.cookie'), $this->app['session.store']->getId());
        $this->get('/employee')->assertRedirect('/employee/dashboard');
        $this->assertTrue(
            app(\App\Services\Identity\AuthenticatedMemberContextResolver::class)
                ->resolve($this->app['request'])
                ->actor()
                ->employee()
                ?->is($user->employeeProfile) ?? false,
        );
    }

    public function test_canonical_login_discards_an_unauthorized_or_external_intended_destination(): void
    {
        $user = $this->linkedActiveUser('D03-UNAUTHORIZED');

        $this->withSession(['url.intended' => '/admin'])
            ->post('/login', [
                'employee_id' => $user->employeeProfile->employee_id,
                'password' => 'correct-password',
            ])
            ->assertRedirect('/');

        $this->post('/logout')->assertRedirect('/login');

        $this->withSession(['url.intended' => '//evil.example/steal'])
            ->post('/login', [
                'employee_id' => $user->employeeProfile->employee_id,
                'password' => 'correct-password',
            ])
            ->assertRedirect('/');
    }

    private function linkedActiveUser(string $employeeId): User
    {
        $employee = Employee::query()->create([
            'employee_id' => $employeeId,
            'name' => 'D03 Panel User',
            'rank' => 'Firefighter',
            'password' => Hash::make('legacy-password'),
            'must_change_password' => false,
        ]);

        return User::factory()->create([
            'account_status' => AccountStatus::Active,
            'employee_profile_id' => $employee->id,
            'password' => Hash::make('correct-password'),
        ])->load('employeeProfile');
    }
}
