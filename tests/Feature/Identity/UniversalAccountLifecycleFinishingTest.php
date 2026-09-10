<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Enums\AccountStatus;
use App\Exceptions\PasswordChangeRequired;
use App\Filament\Pages\SetPasswordPage;
use App\Models\AuthenticationSession;
use App\Models\Employee;
use App\Models\User;
use App\Services\Identity\AccountSecurityService as IdentityAccountSecurityService;
use App\Services\Identity\AuthenticatedMemberContextResolver;
use App\Services\Security\AccountSecurityService as SecurityAccountSecurityService;
use App\Services\Security\EmployeeAccountAdministration;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Mockery;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class UniversalAccountLifecycleFinishingTest extends TestCase
{
    use RefreshDatabase;

    public function test_stateful_must_change_member_api_is_denied_without_destroying_password_change_session(): void
    {
        $employee = Employee::query()->create([
            'employee_id' => 'TEMP-API-100',
            'name' => 'Temporary API Member',
            'password' => 'unusable-employee-credential',
            'roster_status' => 'active',
        ]);
        $user = User::factory()->create([
            'employee_profile_id' => $employee->id,
            'employee_id' => $employee->employee_id,
            'account_status' => AccountStatus::Active,
            'password' => 'temporary-private-handoff',
            'must_change_password' => true,
        ]);
        $user->assignRole(Role::findOrCreate('member', 'web'));

        $this->actingAsCanonicalUser($user);
        $laravelSessionId = $this->app['session.store']->getId();
        $registryId = session('auth.canonical_session_id');

        foreach ([
            ['GET', '/api/me/context'],
            ['POST', '/api/public/station_request'],
        ] as [$method, $url]) {
            $response = $method === 'GET'
                ? $this->getJson($url, $this->sameOriginHeaders())
                : $this->postJson($url, [], $this->sameOriginHeaders());

            $response->assertForbidden()
                ->assertJson([
                    'message' => 'Password change required.',
                    'code' => 'password_change_required',
                ]);
            self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
            $this->assertAuthenticatedAs($user, 'web');
            self::assertSame($laravelSessionId, $this->app['session.store']->getId());
            self::assertNull(AuthenticationSession::query()->findOrFail($registryId)->revoked_at);
        }

        $this->withoutVite();
        $this->get('/employee/set-password')->assertOk();
        $this->bindCanonicalSessionToLivewireTestRequests();
        Filament::setCurrentPanel(Filament::getPanel('employee'));
        Livewire::test(SetPasswordPage::class)
            ->fillForm([
                'current_password' => 'temporary-private-handoff',
                'password' => 'permanent-private-password-2026',
                'password_confirmation' => 'permanent-private-password-2026',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $user->refresh();
        self::assertFalse($user->must_change_password);
        self::assertFalse(Hash::check('temporary-private-handoff', $user->getAuthPassword()));
        $this->withCookie(
            (string) config('session.cookie'),
            $this->app['session.store']->getId(),
        )->withCredentials()->getJson('/api/me/context', $this->sameOriginHeaders())->assertOk();
    }

    public function test_context_resolver_fails_closed_for_must_change_member(): void
    {
        $employee = Employee::query()->create([
            'employee_id' => 'TEMP-CONTEXT-100',
            'name' => 'Temporary Context Member',
            'password' => 'unusable-employee-credential',
            'roster_status' => 'active',
        ]);
        $user = User::factory()->create([
            'employee_profile_id' => $employee->id,
            'employee_id' => $employee->employee_id,
            'account_status' => AccountStatus::Active,
            'must_change_password' => true,
        ]);
        $this->actingAsCanonicalUser($user);

        $request = Request::create('/api/me/context', 'GET');
        $request->setLaravelSession($this->app['session.store']);
        $request->setUserResolver(static fn (): User => $user);

        $this->expectException(PasswordChangeRequired::class);
        app(AuthenticatedMemberContextResolver::class)->resolve($request);
    }

    public function test_temporary_password_uniqueness_uses_one_fingerprint_and_clears_after_permanent_change(): void
    {
        $actor = $this->administrator();
        $firstEmployee = $this->employee('TEMP-UNIQUE-1');
        $secondEmployee = $this->employee('TEMP-UNIQUE-2');
        app(\App\Services\Identity\CanonicalUserProvisioner::class)
            ->create($secondEmployee->id, 'MISSING_OR_UNSUPPORTED', now());
        $unrelated = User::factory()->create([
            'account_status' => AccountStatus::Active,
            'must_change_password' => true,
        ]);

        $first = app(EmployeeAccountAdministration::class)->createForEmployee(
            $actor,
            $firstEmployee,
            'unique-temporary-password-2026',
            'admin-password',
            'Controlled first handoff',
        );

        self::assertNotNull($first->temporary_credential_fingerprint);
        self::assertSame(64, strlen($first->temporary_credential_fingerprint));
        self::assertNull($unrelated->fresh()->temporary_credential_fingerprint);

        try {
            app(EmployeeAccountAdministration::class)->createForEmployee(
                $actor,
                $secondEmployee,
                'unique-temporary-password-2026',
                'admin-password',
                'Controlled second handoff',
            );
            self::fail('A duplicate active temporary password must be rejected.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('temporary_password', $exception->errors());
        }

        $second = $secondEmployee->user()->sole();
        self::assertSame(AccountStatus::PendingActivation, $second->account_status);
        self::assertNull($second->temporary_credential_fingerprint);

        $changed = app(IdentityAccountSecurityService::class)->changePassword(
            $first,
            Hash::make('permanent-private-password-2026'),
            now(),
        );
        self::assertNull($changed->temporary_credential_fingerprint);
        self::assertFalse($changed->must_change_password);
        self::assertFalse(Hash::check('unique-temporary-password-2026', $changed->getAuthPassword()));
    }

    public function test_database_unique_index_is_the_final_temporary_fingerprint_authority(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();
        $fingerprint = str_repeat('a', 64);

        DB::table('users')->where('id', $first->id)->update([
            'temporary_credential_fingerprint' => $fingerprint,
        ]);

        $this->expectException(QueryException::class);
        DB::table('users')->where('id', $second->id)->update([
            'temporary_credential_fingerprint' => $fingerprint,
        ]);
    }

    public function test_temporary_password_issuance_does_not_scan_other_must_change_hashes(): void
    {
        $actor = $this->administrator();
        $employee = $this->employee('TEMP-O1-1');
        User::factory()->count(25)->create([
            'account_status' => AccountStatus::Active,
            'must_change_password' => true,
        ]);
        $actorHash = $actor->getAuthPassword();
        $hasher = Hash::getFacadeRoot();
        Hash::swap(Mockery::mock($hasher)->makePartial());
        Hash::shouldReceive('check')->once()->with('admin-password', $actorHash)->andReturnTrue();
        Hash::shouldReceive('check')->with('constant-time-temporary-2026', Mockery::any())->never();

        app(EmployeeAccountAdministration::class)->createForEmployee(
            $actor,
            $employee,
            'constant-time-temporary-2026',
            'admin-password',
            'Constant-time handoff',
        );

        self::assertNotNull($employee->user()->sole()->temporary_credential_fingerprint);
    }

    public function test_administrative_recovery_uses_the_same_unique_temporary_credential_authority(): void
    {
        $actor = $this->administrator();
        $first = User::factory()->create(['account_status' => AccountStatus::Active]);
        $second = User::factory()->create(['account_status' => AccountStatus::Active]);
        $preserved = ['password', 'security_version'];
        $secondBefore = array_intersect_key($second->getRawOriginal(), array_flip($preserved));
        $service = app(SecurityAccountSecurityService::class);

        $service->resetPassword(
            $actor,
            $first,
            'recovery-handoff-unique-2026',
            'Verified individual recovery',
            now(),
            'admin-password',
        );
        self::assertNotNull($first->fresh()->temporary_credential_fingerprint);

        try {
            $service->resetPassword(
                $actor,
                $second,
                'recovery-handoff-unique-2026',
                'Verified individual recovery',
                now(),
                'admin-password',
            );
            self::fail('Administrative recovery must reject an active duplicate temporary credential.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('temporary_password', $exception->errors());
        }

        $secondAfter = array_intersect_key($second->fresh()->getRawOriginal(), array_flip($preserved));
        self::assertSame($secondBefore, $secondAfter);
        self::assertFalse($second->fresh()->must_change_password);
        self::assertNull($second->fresh()->temporary_credential_fingerprint);
    }

    private function administrator(): User
    {
        $actor = User::factory()->create([
            'account_status' => AccountStatus::Active,
            'password' => 'admin-password',
        ]);
        $actor->assignRole(Role::findOrCreate('super_admin', 'web'));

        return $actor;
    }

    private function employee(string $employeeId): Employee
    {
        return Employee::query()->create([
            'employee_id' => $employeeId,
            'name' => 'Universal Account Member',
            'password' => 'unusable-employee-credential',
            'roster_status' => 'active',
        ]);
    }

    /** @return array<string, string> */
    private function sameOriginHeaders(): array
    {
        return [
            'Origin' => (string) config('app.url'),
            'Referer' => rtrim((string) config('app.url'), '/').'/',
            'Accept' => 'application/json',
        ];
    }
}
