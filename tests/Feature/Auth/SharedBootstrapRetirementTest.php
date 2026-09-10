<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SharedBootstrapRetirementTest extends TestCase
{
    use RefreshDatabase;

    public function test_employee_only_shared_credential_cannot_claim_a_canonical_account_even_if_legacy_config_is_injected(): void
    {
        config()->set('identity.employee_bootstrap_login_enabled', true);
        config()->set('security.employee_bootstrap.secret', 'retired-shared-bootstrap');
        $employee = Employee::query()->create([
            'employee_id' => 'RETIRED-BOOTSTRAP-1',
            'name' => 'Roster Only Member',
            'password' => 'retired-shared-bootstrap',
            'roster_status' => 'active',
        ]);

        $this->from('/login')->post('/login', [
            'employee_id' => $employee->employee_id,
            'password' => 'retired-shared-bootstrap',
        ])->assertRedirect('/login')->assertSessionHasErrors('employee_id');

        $this->assertGuest('web');
        self::assertFalse(session()->has('auth.canonical_activation_intent'));
        self::assertSame(0, User::query()->count());
    }

    public function test_public_shared_bootstrap_claim_routes_are_removed(): void
    {
        $this->get('/activate-account')->assertNotFound();
        $this->post('/activate-account', [])->assertNotFound();
        self::assertFalse(app('router')->has('activate-account.create'));
        self::assertFalse(app('router')->has('activate-account.store'));
    }

    public function test_source_defaults_cannot_reenable_shared_bootstrap_login(): void
    {
        self::assertFalse((bool) config('identity.employee_bootstrap_login_enabled'));
        $example = (string) file_get_contents(base_path('.env.example'));
        self::assertStringNotContainsString('MBFD_EMPLOYEE_BOOTSTRAP_LOGIN_ENABLED', $example);
        self::assertStringNotContainsString('MBFD_EMPLOYEE_BOOTSTRAP_PASSWORD', $example);
    }
}
