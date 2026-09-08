<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Filament\Workgroup\Pages\Profile;
use App\Models\Employee;
use App\Models\User;
use App\Models\Workgroup;
use App\Models\WorkgroupMember;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class CanonicalEmailEditingTest extends TestCase
{
    use RefreshDatabase;

    public function test_workgroup_profile_ignores_forged_email_for_a_linked_member(): void
    {
        [$user, $employee] = $this->linkedMember();
        $this->actingAs($user);
        $identity = $user->only(['email', 'employee_id', 'employee_profile_id', 'password']);

        $profile = new class extends Profile
        {
            public function saveProfileForTest(array $data): void
            {
                $this->updateProfile($data);
            }
        };
        $profile->saveProfileForTest(['name' => 'Updated Display Name', 'email' => 'forged@miamibeachfl.gov']);

        $this->assertSame('Updated Display Name', $user->refresh()->name);
        $this->assertSame($identity, $user->only(array_keys($identity)));
        $this->assertSame('canonicalmember@miamibeachfl.gov', $employee->refresh()->city_email);
    }

    public function test_admin_cannot_desynchronize_linked_email_by_forging_the_raw_field(): void
    {
        [$target, $employee] = $this->linkedMember();
        $this->admin();

        Livewire::test(EditUser::class, ['record' => $target->getRouteKey()])
            ->assertFormFieldIsDisabled('email')
            ->fillForm(['name' => 'Updated Display Name', 'email' => 'forged@miamibeachfl.gov'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Updated Display Name', $target->refresh()->name);
        $this->assertSame('canonicalmember@miamibeachfl.gov', $target->email);
        $this->assertSame($target->email, $employee->refresh()->city_email);
    }

    public function test_historical_email_timestamp_does_not_claim_member_mailbox_confirmation(): void
    {
        [$target] = $this->linkedMember();
        $target->forceFill(['email_verified_at' => now()])->save();
        $this->admin();

        Livewire::test(EditUser::class, ['record' => $target->getRouteKey()])
            ->assertDontSee('Verified by the member')
            ->assertSee('Mailbox ownership has not been verified through city email confirmation.');

        $this->assertNotNull($target->refresh()->email_verified_at);
    }

    public function test_admin_save_boundary_discards_raw_email_even_when_city_email_is_not_submitted(): void
    {
        [$target] = $this->linkedMember();
        $this->admin();
        $editor = new class extends EditUser
        {
            public function sanitizeForTest(User $target, array $data): array
            {
                $this->record = $target;

                return $this->mutateFormDataBeforeSave($data);
            }
        };

        $data = $editor->sanitizeForTest($target, ['name' => 'Updated', 'email' => 'forged@miamibeachfl.gov']);

        $this->assertArrayNotHasKey('email', $data);
        $this->assertSame('Updated', $data['name']);
    }

    public function test_linked_workgroup_profile_offers_the_secure_city_email_flow(): void
    {
        [$user] = $this->linkedMember();
        $workgroup = Workgroup::query()->create(['name' => 'Email testing', 'created_by' => $user->id]);
        WorkgroupMember::query()->create([
            'workgroup_id' => $workgroup->id,
            'user_id' => $user->id,
            'role' => 'member',
            'is_active' => true,
        ]);
        $this->actingAs($user);
        $this->withoutVite();
        Filament::setCurrentPanel(Filament::getPanel('workgroups'));

        Livewire::test(Profile::class)
            ->assertActionVisible('cityEmail')
            ->assertActionHasUrl('cityEmail', route('city-email.show'))
            ->mountAction('editProfile')
            ->setActionData(['name' => 'Profile Display Name', 'email' => 'forged@miamibeachfl.gov'])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertSame('Profile Display Name', $user->refresh()->name);
        $this->assertSame('canonicalmember@miamibeachfl.gov', $user->email);
    }

    public function test_admin_city_email_change_updates_both_fields_and_requires_new_mailbox_proof(): void
    {
        [$target, $employee] = $this->linkedMember();
        $target->forceFill(['email_verified_at' => now()])->save();
        $this->admin();

        Livewire::test(EditUser::class, ['record' => $target->getRouteKey()])
            ->fillForm(['city_email' => 'correctedmember@miamibeachfl.gov'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('correctedmember@miamibeachfl.gov', $target->refresh()->email);
        $this->assertSame($target->email, $employee->refresh()->city_email);
        $this->assertNull($target->email_verified_at);
    }

    public function test_admin_unrelated_edit_preserves_existing_city_email_proof(): void
    {
        [$target] = $this->linkedMember();
        $target->forceFill(['email_verified_at' => now()])->save();
        $verifiedAt = $target->email_verified_at->toISOString();
        $this->admin();

        Livewire::test(EditUser::class, ['record' => $target->getRouteKey()])
            ->fillForm(['name' => 'Updated Display Name'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($verifiedAt, $target->refresh()->email_verified_at?->toISOString());
    }

    public function test_admin_city_email_collision_is_a_validation_error_and_rolls_back_other_fields(): void
    {
        [$target, $employee] = $this->linkedMember();
        User::factory()->create(['email' => 'occupied@miamibeachfl.gov']);
        $originalName = $target->name;
        $this->admin();

        Livewire::test(EditUser::class, ['record' => $target->getRouteKey()])
            ->fillForm(['name' => 'Must not persist', 'city_email' => 'occupied@miamibeachfl.gov'])
            ->call('save')
            ->assertHasFormErrors(['city_email']);

        $this->assertSame($originalName, $target->refresh()->name);
        $this->assertSame('canonicalmember@miamibeachfl.gov', $target->email);
        $this->assertSame($target->email, $employee->refresh()->city_email);
    }

    public function test_admin_city_email_does_not_accept_an_external_domain(): void
    {
        [$target, $employee] = $this->linkedMember();
        $this->admin();

        Livewire::test(EditUser::class, ['record' => $target->getRouteKey()])
            ->fillForm(['city_email' => 'canonicalmember@example.com'])
            ->call('save')
            ->assertHasFormErrors(['city_email']);

        $this->assertSame('canonicalmember@miamibeachfl.gov', $target->refresh()->email);
        $this->assertSame($target->email, $employee->refresh()->city_email);
    }

    public function test_unlinked_legacy_profile_email_remains_editable(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $profile = new class extends Profile
        {
            public function saveProfileForTest(array $data): void
            {
                $this->updateProfile($data);
            }
        };
        $profile->saveProfileForTest(['name' => 'Legacy Member', 'email' => 'legacy@example.com']);

        $this->assertSame('legacy@example.com', $user->refresh()->email);
    }

    private function admin(): void
    {
        $admin = User::factory()->create(['account_status' => \App\Enums\AccountStatus::Active]);
        $admin->assignRole(Role::findOrCreate('super_admin', 'web'));
        $this->actingAs($admin);
        $this->withoutVite();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    /** @return array{User, Employee} */
    private function linkedMember(): array
    {
        $employee = Employee::query()->create([
            'employee_id' => 'EMAIL-EDIT-100',
            'name' => 'Canonical Member',
            'city_email' => 'canonicalmember@miamibeachfl.gov',
            'roster_status' => 'active',
            'password' => bcrypt('test-employee-password'),
        ]);
        $user = User::factory()->create([
            'employee_id' => $employee->employee_id,
            'employee_profile_id' => $employee->id,
            'email' => $employee->city_email,
        ]);

        return [$user, $employee];
    }
}
