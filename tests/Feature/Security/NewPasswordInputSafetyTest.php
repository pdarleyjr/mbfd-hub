<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Filament\Pages\SetPasswordPage;
use App\Filament\Resources\EmployeeResource\Pages\EditEmployee;
use App\Models\Employee;
use App\Models\User;
use App\Services\Security\AccountSecurityService;
use App\Services\Security\EmployeeAccountAdministration;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class NewPasswordInputSafetyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['hashing.driver' => 'bcrypt']);
        Http::fake();
        $this->withoutVite();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    #[DataProvider('unsafePasswords')]
    public function test_administrative_replacement_rejects_unsafe_input_before_hash_or_security_changes(string $password): void
    {
        $actor = $this->administrator();
        [, $target] = $this->member();
        $original = $target->fresh()->getRawOriginal();
        try {
            app(AccountSecurityService::class)->resetPassword($actor, $target, $password, 'Approved recovery', now(), 'current-password');
            self::fail('Unsafe new password must be a validation error.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('temporary_password', $exception->errors());
            self::assertSame($original, $target->fresh()->getRawOriginal());
        }
        Http::assertNothingSent();
    }

    #[DataProvider('unsafePasswords')]
    public function test_account_creation_rejects_unsafe_input_before_account_exists(string $password): void
    {
        $actor = $this->administrator();
        $employee = $this->employee();
        $hash = $employee->getRawOriginal('password');
        try {
            app(EmployeeAccountAdministration::class)->createForEmployee($actor, $employee, $password, 'current-password', 'Approved onboarding');
            self::fail('Unsafe new password must be a validation error.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('temporary_password', $exception->errors());
            self::assertFalse($employee->user()->exists());
            self::assertSame($hash, $employee->fresh()->getRawOriginal('password'));
        }
        Http::assertNothingSent();
    }

    #[DataProvider('unsafePasswords')]
    public function test_self_service_password_page_reports_inline_error_without_changing_hash(string $password): void
    {
        $user = $this->administrator();
        $this->actingAs($user);
        $hash = $user->getRawOriginal('password');
        Livewire::test(SetPasswordPage::class)
            ->fillForm(['current_password' => 'current-password', 'password' => $password, 'password_confirmation' => $password])
            ->call('save')->assertHasFormErrors(['password']);
        self::assertSame($hash, $user->fresh()->getRawOriginal('password'));
        Http::assertNothingSent();
    }

    #[DataProvider('unsafePasswords')]
    public function test_reset_link_rejects_unsafe_input_and_preserves_unused_token(string $password): void
    {
        [$employee, $user] = $this->member();
        $token = Password::broker()->createToken($user);
        $original = $user->fresh()->getRawOriginal();
        $this->post('/reset-password', [
            'employee_id' => $employee->employee_id, 'token' => $token,
            'password' => $password, 'password_confirmation' => $password,
        ])->assertSessionHasErrors(['password']);
        self::assertSame($original, $user->fresh()->getRawOriginal());
        self::assertTrue(Password::broker()->tokenExists($user->fresh(), $token));
        Http::assertNothingSent();
    }

    #[DataProvider('unsafePasswords')]
    public function test_shared_administration_password_action_reports_inline_error(string $password): void
    {
        $actor = $this->administrator();
        [$employee, $target] = $this->member();
        $hash = $target->getRawOriginal('password');
        $this->actingAs($actor);
        Livewire::test(EditEmployee::class, ['record' => $employee->getRouteKey()])
            ->callAction('resetPassword', ['temporary_password' => $password, 'current_password' => 'current-password', 'reason' => 'Approved recovery'])
            ->assertHasActionErrors(['temporary_password']);
        self::assertSame($hash, $target->fresh()->getRawOriginal('password'));
        Http::assertNothingSent();
    }

    public function test_exactly_72_bytes_work_for_new_password_boundaries(): void
    {
        $actor = $this->administrator();
        [$employee, $target] = $this->member();
        $temporary = str_repeat('a', 72);
        app(AccountSecurityService::class)->resetPassword($actor, $target, $temporary, 'Approved recovery', now(), 'current-password');
        self::assertTrue(Hash::check($temporary, $target->fresh()->getAuthPassword()));
        $token = Password::broker()->createToken($target->fresh());
        $replacement = str_repeat('é', 36);
        self::assertSame(72, strlen($replacement));
        $this->post('/reset-password', [
            'employee_id' => $employee->employee_id, 'token' => $token,
            'password' => $replacement, 'password_confirmation' => $replacement,
        ])->assertRedirect('/login');
        self::assertTrue(Hash::check($replacement, $target->fresh()->getAuthPassword()));
        $this->actingAsCanonicalUser($actor);
        $this->bindCanonicalSessionToLivewireTestRequests();
        Livewire::test(SetPasswordPage::class)
            ->fillForm(['current_password' => 'current-password', 'password' => $replacement, 'password_confirmation' => $replacement])
            ->call('save')->assertHasNoFormErrors();
        self::assertTrue(Hash::check($replacement, $actor->fresh()->getAuthPassword()));
        $created = app(EmployeeAccountAdministration::class)->createForEmployee($actor->fresh(), $this->employee('PASS-SAFE-2'), $temporary, $replacement, 'Approved onboarding');
        self::assertTrue(Hash::check($temporary, $created->getAuthPassword()));
        Http::assertNothingSent();
    }

    public static function unsafePasswords(): array
    {
        return [
            '73 ASCII bytes' => [str_repeat('a', 73)],
            '74 Unicode bytes' => [str_repeat('é', 37)],
            'NUL' => ["new-password\0suffix"],
        ];
    }

    private function administrator(): User
    {
        $actor = User::factory()->create(['account_status' => 'active', 'password' => 'current-password']);
        $actor->assignRole(Role::findOrCreate('super_admin', 'web'));

        return $actor;
    }

    private function employee(string $id = 'PASS-SAFE-1'): Employee
    {
        return Employee::query()->create(['employee_id' => $id, 'name' => 'Password Safety Member', 'password' => 'legacy-password', 'roster_status' => 'active']);
    }

    private function member(): array
    {
        $employee = $this->employee();
        $user = User::factory()->create(['employee_profile_id' => $employee->id, 'employee_id' => $employee->employee_id, 'password' => 'old-password', 'account_status' => 'active']);

        return [$employee, $user];
    }
}
