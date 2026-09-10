<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
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

    public function test_source_defaults_keep_member_bootstrap_disabled_and_never_configure_plaintext(): void
    {
        self::assertFalse((bool) config('identity.employee_bootstrap_login_enabled'));
        self::assertFalse((bool) config('identity.member_bootstrap.enabled'));
        self::assertNull(config('identity.member_bootstrap.password_hash'));
        $example = (string) file_get_contents(base_path('.env.example'));
        self::assertStringNotContainsString('MBFD_EMPLOYEE_BOOTSTRAP_LOGIN_ENABLED', $example);
        self::assertStringNotContainsString('MBFD_EMPLOYEE_BOOTSTRAP_PASSWORD', $example);
        self::assertStringContainsString('MBFD_MEMBER_BOOTSTRAP_ENABLED=false', $example);
        self::assertStringContainsString('MBFD_MEMBER_BOOTSTRAP_PASSWORD_HASH=', $example);
        self::assertStringNotContainsString('MBFD_MEMBER_BOOTSTRAP_PASSWORD=', $example);
    }

    public function test_operational_status_check_exposes_no_credential_and_fails_closed(): void
    {
        config()->set('identity.member_bootstrap.enabled', false);
        config()->set('identity.member_bootstrap.password_hash', null);
        self::assertSame(1, Artisan::call('identity:member-bootstrap-status', ['--require-available' => true]));
        self::assertStringContainsString('MEMBER_BOOTSTRAP_AVAILABLE=0', Artisan::output());

        config()->set('identity.member_bootstrap.enabled', true);
        config()->set('identity.member_bootstrap.password_hash', Hash::make('test-only-bootstrap-status'));
        self::assertSame(0, Artisan::call('identity:member-bootstrap-status', ['--require-available' => true]));
        self::assertSame("MEMBER_BOOTSTRAP_AVAILABLE=1\n", str_replace("\r\n", "\n", Artisan::output()));
    }
}
