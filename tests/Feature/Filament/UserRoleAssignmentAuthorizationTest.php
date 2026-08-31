<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\UserResource;
use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class UserRoleAssignmentAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_with_generic_user_update_permission_cannot_assign_any_role(): void
    {
        [$adminRole, $roles] = $this->roles();
        $adminRole->givePermissionTo(Permission::findOrCreate('update_user', 'web'));

        $actor = User::factory()->create();
        $actor->assignRole($adminRole);
        $target = User::factory()->create();

        $this->actingAs($actor);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->withoutVite();

        Livewire::test(EditUser::class, ['record' => $target->getRouteKey()])
            ->fillForm(['roles' => $roles->pluck('id')->map(static fn (int $id): string => (string) $id)->all()])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame([], $target->refresh()->getRoleNames()->all());
    }

    public function test_super_admin_can_assign_roles_after_generic_user_update_permission_is_granted(): void
    {
        [$adminRole, $roles] = $this->roles();
        $updateUser = Permission::findOrCreate('update_user', 'web');
        $adminRole->givePermissionTo($updateUser);
        $roles['super_admin']->givePermissionTo($updateUser);

        $actor = User::factory()->create();
        $actor->assignRole($roles['super_admin']);
        $target = User::factory()->create();

        $this->actingAs($actor);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->withoutVite();

        Livewire::test(EditUser::class, ['record' => $target->getRouteKey()])
            ->fillForm(['roles' => $roles->pluck('id')->map(static fn (int $id): string => (string) $id)->all()])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(
            $roles->keys()->sort()->values()->all(),
            $target->refresh()->getRoleNames()->sort()->values()->all(),
        );
    }

    public function test_admin_with_generic_user_create_permission_cannot_create_a_user(): void
    {
        [$adminRole] = $this->roles();
        $adminRole->givePermissionTo(Permission::findOrCreate('create_user', 'web'));

        $actor = User::factory()->create();
        $actor->assignRole($adminRole);

        $this->actingAs($actor);
        $this->assertFalse(UserResource::canCreate());
        $this->assertDatabaseMissing('users', ['email' => 'new-user@example.test']);
    }

    /**
     * @return array{0: Role, 1: \Illuminate\Support\Collection<string, Role>}
     */
    private function roles(): array
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $roles = collect([
            'super_admin',
            'admin',
            'logistics_admin',
            'training_admin',
            'training_viewer',
            'workgroup_admin',
            'workgroup_facilitator',
            'workgroup_member',
            'staff',
        ])->mapWithKeys(fn (string $name): array => [
            $name => Role::findOrCreate($name, 'web'),
        ]);

        return [$roles['admin'], $roles];
    }
}
