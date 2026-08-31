<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Enums\AccountStatus;
use App\Enums\SessionContextClass;
use App\Models\Employee;
use App\Models\User;
use App\Services\Identity\AccountSecurityService;
use App\Services\Identity\SessionRegistry;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class CanonicalIdentityFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_without_an_employee_profile_is_allowed_and_is_not_auto_linked(): void
    {
        $employee = $this->employee('10010');
        $user = User::factory()->create(['employee_id' => '10010']);

        $this->assertNull($user->employee_profile_id);
        $this->assertNull($user->employeeProfile);
        $this->assertNull($employee->user);
    }

    public function test_user_and_employee_expose_the_canonical_one_to_one_relationship(): void
    {
        $employee = $this->employee('10010');
        $user = User::factory()->create();

        $user->employeeProfile()->associate($employee);
        $user->save();

        $this->assertTrue($user->employeeProfile->is($employee));
        $this->assertTrue($employee->refresh()->user->is($user));
    }

    public function test_duplicate_user_to_employee_link_is_rejected_by_the_database(): void
    {
        $employee = $this->employee('10010');
        User::factory()->create(['employee_profile_id' => $employee->id]);

        $this->expectException(QueryException::class);

        User::factory()->create(['employee_profile_id' => $employee->id]);
    }

    public function test_unknown_employee_profile_id_is_rejected_by_the_database(): void
    {
        $this->expectException(QueryException::class);

        User::factory()->create(['employee_profile_id' => 999_999]);
    }

    public function test_only_active_accounts_are_allowed_to_authenticate(): void
    {
        $active = User::factory()->create(['account_status' => AccountStatus::Active]);
        $disabled = User::factory()->create(['account_status' => AccountStatus::Disabled]);

        $this->assertTrue($active->isAuthenticationAllowed());
        $this->assertFalse($disabled->isAuthenticationAllowed());
    }

    public function test_invalid_account_status_is_rejected_by_the_database(): void
    {
        $user = User::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('users')
            ->where('id', $user->id)
            ->update(['account_status' => 'unknown']);
    }

    public function test_disabling_an_account_increments_security_version_and_revokes_all_sessions(): void
    {
        $user = User::factory()->create(['account_status' => AccountStatus::Active]);
        $registry = app(SessionRegistry::class);
        $issuedAt = Carbon::parse('2026-08-31 12:00:00 UTC');
        $session = $registry->register(
            $user,
            'raw-laravel-session-id-before-disable',
            SessionContextClass::UnmanagedBrowser,
            $issuedAt,
            $issuedAt->copy()->addHour(),
            $issuedAt->copy()->addDay(),
        );

        app(AccountSecurityService::class)->disable($user, 'account disabled for test', $issuedAt->copy()->addMinute());

        $user->refresh();
        $session->refresh();
        $this->assertSame(AccountStatus::Disabled, $user->account_status);
        $this->assertSame(2, $user->security_version);
        $this->assertNotNull($session->revoked_at);
        $this->assertSame('account disabled for test', $session->revoked_reason);
    }

    public function test_registry_hashes_laravel_session_ids_and_recognizes_security_version_revocation(): void
    {
        $user = User::factory()->create(['account_status' => AccountStatus::Active]);
        $registry = app(SessionRegistry::class);
        $issuedAt = Carbon::parse('2026-08-31 12:00:00 UTC');
        $oldSessionId = 'raw-laravel-session-id-before-revocation';
        $old = $registry->register(
            $user,
            $oldSessionId,
            SessionContextClass::UnmanagedBrowser,
            $issuedAt,
            $issuedAt->copy()->addHour(),
            $issuedAt->copy()->addDay(),
        );

        $this->assertNotSame($oldSessionId, $old->session_id_hash);
        $this->assertStringNotContainsString($oldSessionId, json_encode($old->toArray(), JSON_THROW_ON_ERROR));
        $this->assertTrue($registry->isCurrent($user, $old, $issuedAt->copy()->addMinute()));

        app(AccountSecurityService::class)->revokeAll($user, 'global sign out', $issuedAt->copy()->addMinutes(2));
        $user->refresh();
        $old->refresh();
        $new = $registry->register(
            $user,
            'raw-laravel-session-id-after-revocation',
            SessionContextClass::UnmanagedBrowser,
            $issuedAt->copy()->addMinutes(3),
            $issuedAt->copy()->addHour()->addMinutes(3),
            $issuedAt->copy()->addDay()->addMinutes(3),
        );

        $this->assertFalse($registry->isCurrent($user, $old, $issuedAt->copy()->addMinutes(3)));
        $this->assertTrue($registry->isCurrent($user, $new, $issuedAt->copy()->addMinutes(3)));
    }

    public function test_recording_a_password_change_advances_the_security_version_and_records_the_time(): void
    {
        $user = User::factory()->create(['account_status' => AccountStatus::Active]);
        $changedAt = Carbon::parse('2026-08-31 12:00:00 UTC');

        app(AccountSecurityService::class)->recordPasswordChange($user, $changedAt);

        $user->refresh();
        $this->assertSame(2, $user->security_version);
        $this->assertTrue($user->password_changed_at->equalTo($changedAt));
    }

    private function employee(string $employeeId): Employee
    {
        return Employee::query()->create([
            'employee_id' => $employeeId,
            'name' => 'Identity Test Employee',
            'rank' => 'Firefighter',
            'password' => 'password',
            'must_change_password' => false,
        ]);
    }
}
