<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\AccountProfileResource\Pages\EditAccountProfile;
use App\Filament\Resources\EmployeeResource\Pages\EditEmployee;
use App\Models\Employee;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class CanonicalEmailEditingTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_save_cannot_desynchronize_email_by_forging_fields(): void
    {
        [$target, $employee] = $this->linkedMember();
        $this->admin();
        Livewire::test(EditEmployee::class, ['record' => $employee->getRouteKey()])
            ->assertFormFieldIsDisabled('city_email')
            ->assertFormFieldDoesNotExist('email')
            ->fillForm(['name' => 'Updated Personnel Name', 'email' => 'forged@miamibeachfl.gov', 'city_email' => 'forged@miamibeachfl.gov'])
            ->call('save')->assertHasNoFormErrors();
        self::assertSame('Updated Personnel Name', $employee->fresh()->name);
        self::assertSame('Updated Personnel Name', $target->fresh()->getRawOriginal('name'));
        self::assertSame('canonicalmember@miamibeachfl.gov', $target->fresh()->email);
        self::assertSame($target->fresh()->email, $employee->fresh()->city_email);
    }

    public function test_historical_timestamp_does_not_claim_mailbox_confirmation(): void
    {
        [$target, $employee] = $this->linkedMember();
        $target->forceFill(['email_verified_at' => now()])->save();
        $this->admin();
        Livewire::test(EditEmployee::class, ['record' => $employee->getRouteKey()])
            ->assertDontSee('Mailbox verified')
            ->assertSee('mailbox ownership is not independently verified');
        self::assertNotNull($target->fresh()->email_verified_at);
    }

    public function test_protected_city_email_change_updates_both_fields_and_requires_new_proof(): void
    {
        [$target, $employee] = $this->linkedMember();
        $target->forceFill(['email_verified_at' => now()])->save();
        $version = $target->security_version;
        $this->admin();
        Livewire::test(EditEmployee::class, ['record' => $employee->getRouteKey()])
            ->callAction('changeCityEmail', $this->emailChange('correctedmember@miamibeachfl.gov'))
            ->assertHasNoActionErrors();
        self::assertSame('correctedmember@miamibeachfl.gov', $target->fresh()->email);
        self::assertSame($target->fresh()->email, $employee->fresh()->city_email);
        self::assertNull($target->fresh()->email_verified_at);
        self::assertSame($version + 1, $target->fresh()->security_version);
        $this->assertDatabaseHas('employee_profile_events', ['employee_id' => $employee->id, 'reason' => 'Approved address correction']);
    }

    public function test_unrelated_profile_edit_preserves_existing_email_proof(): void
    {
        [$target, $employee] = $this->linkedMember();
        $target->forceFill(['email_verified_at' => now()])->save();
        $verifiedAt = $target->email_verified_at->toISOString();
        $this->admin();
        Livewire::test(EditEmployee::class, ['record' => $employee->getRouteKey()])
            ->fillForm(['display_name' => 'Preferred'])->call('save')->assertHasNoFormErrors();
        self::assertSame($verifiedAt, $target->fresh()->email_verified_at?->toISOString());
        self::assertSame('Preferred', $employee->fresh()->display_name);
    }

    public function test_city_email_collision_is_validation_error_without_persisting_pending_profile_edits(): void
    {
        [$target, $employee] = $this->linkedMember();
        User::factory()->create(['email' => 'occupied@miamibeachfl.gov']);
        $this->admin();
        Livewire::test(EditEmployee::class, ['record' => $employee->getRouteKey()])
            ->fillForm(['name' => 'Must not persist'])
            ->callAction('changeCityEmail', $this->emailChange('occupied@miamibeachfl.gov'))
            ->assertHasActionErrors(['city_email']);
        self::assertSame('Canonical Member', $employee->fresh()->name);
        self::assertSame('canonicalmember@miamibeachfl.gov', $target->fresh()->email);
    }

    public function test_city_email_does_not_accept_external_domain(): void
    {
        [$target, $employee] = $this->linkedMember();
        $this->admin();
        Livewire::test(EditEmployee::class, ['record' => $employee->getRouteKey()])
            ->callAction('changeCityEmail', $this->emailChange('member@example.com'))
            ->assertHasActionErrors(['city_email']);
        self::assertSame('canonicalmember@miamibeachfl.gov', $target->fresh()->email);
    }

    public function test_city_email_requires_current_password_and_audit_reason(): void
    {
        [$target, $employee] = $this->linkedMember();
        $this->admin();
        Livewire::test(EditEmployee::class, ['record' => $employee->getRouteKey()])
            ->callAction('changeCityEmail', [...$this->emailChange('changed@miamibeachfl.gov'), 'current_password' => 'wrong'])
            ->assertHasActionErrors(['current_password']);
        Livewire::test(EditEmployee::class, ['record' => $employee->getRouteKey()])
            ->callAction('changeCityEmail', [...$this->emailChange('changed@miamibeachfl.gov'), 'reason' => ''])
            ->assertHasActionErrors(['reason']);
        self::assertSame('canonicalmember@miamibeachfl.gov', $target->fresh()->email);
    }

    public function test_self_profile_can_edit_contact_but_not_identity_or_email(): void
    {
        [$target, $employee] = $this->linkedMember();
        $target->assignRole(Role::findOrCreate('super_admin', 'web'));
        $this->actingAs($target);
        $this->withoutVite();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(EditEmployee::class, ['record' => $employee->getRouteKey()])
            ->assertFormFieldIsDisabled('name')->assertFormFieldIsDisabled('city_email')
            ->assertActionHidden('changeCityEmail')
            ->fillForm(['display_name' => 'Preferred', 'phone' => '123', 'name' => 'Forged'])
            ->call('save')->assertHasNoFormErrors();
        self::assertSame('Canonical Member', $employee->fresh()->name);
        self::assertSame('Preferred', $employee->fresh()->display_name);
        self::assertSame('canonicalmember@miamibeachfl.gov', $target->fresh()->email);
    }

    public function test_unlinked_account_profile_does_not_offer_an_unprotected_recovery_email_editor(): void
    {
        $target = User::factory()->create(['account_status' => 'active']);
        $this->admin();
        Livewire::test(EditAccountProfile::class, ['record' => $target->getRouteKey()])
            ->assertFormFieldDoesNotExist('email')
            ->fillForm(['display_name' => 'Preferred', 'email' => 'forged@example.com'])
            ->call('save')->assertHasNoFormErrors();
        self::assertSame($target->email, $target->fresh()->email);
        self::assertSame('Preferred', $target->fresh()->display_name);
    }

    public function test_unlinked_recovery_email_uses_protected_reauthentication_action(): void
    {
        $target = User::factory()->create(['account_status' => 'active', 'email' => 'old@miamibeachfl.gov']);
        $this->admin();
        Livewire::test(EditAccountProfile::class, ['record' => $target->getRouteKey()])
            ->callAction('changeRecoveryEmail', ['email' => 'new@miamibeachfl.gov', 'current_password' => 'wrong', 'reason' => 'Approved correction'])
            ->assertHasActionErrors(['current_password']);
        self::assertSame('old@miamibeachfl.gov', $target->fresh()->email);
        Livewire::test(EditAccountProfile::class, ['record' => $target->getRouteKey()])
            ->callAction('changeRecoveryEmail', ['email' => 'new@miamibeachfl.gov', 'current_password' => 'administrator-password', 'reason' => 'Approved correction'])
            ->assertHasNoActionErrors();
        self::assertSame('new@miamibeachfl.gov', $target->fresh()->email);
        self::assertNull($target->fresh()->email_verified_at);
        $this->assertDatabaseHas('security_action_events', ['target_user_id' => $target->id, 'action' => 'change_recovery_email', 'result' => 'denied']);
        $this->assertDatabaseHas('security_action_events', ['target_user_id' => $target->id, 'action' => 'change_recovery_email', 'result' => 'allowed']);
    }

    private function emailChange(string $email): array
    {
        return ['city_email' => $email, 'current_password' => 'administrator-password', 'reason' => 'Approved address correction'];
    }

    private function admin(): void
    {
        $admin = User::factory()->create(['account_status' => 'active', 'password' => 'administrator-password']);
        $admin->assignRole(Role::findOrCreate('super_admin', 'web'));
        $this->actingAs($admin);
        $this->withoutVite();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    /** @return array{User, Employee} */
    private function linkedMember(): array
    {
        $employee = Employee::query()->create([
            'employee_id' => 'EMAIL-EDIT-100', 'name' => 'Canonical Member',
            'city_email' => 'canonicalmember@miamibeachfl.gov', 'roster_status' => 'active', 'password' => 'test-employee-password',
        ]);
        $user = User::factory()->create([
            'employee_id' => $employee->employee_id, 'employee_profile_id' => $employee->id,
            'email' => $employee->city_email, 'account_status' => 'active',
        ]);

        return [$user, $employee];
    }
}
