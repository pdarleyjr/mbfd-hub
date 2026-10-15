<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Enums\AccountStatus;
use App\Filament\Pages\ComposeEmail;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** Every tag remove button rendered by a TagsInput must have an accessible name. */
final class TagsInputAccessibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_tag_remove_buttons_are_named(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $admin = User::factory()->create(['account_status' => AccountStatus::Active, 'is_admin' => true]);
        $admin->assignRole(Role::create(['name' => 'super_admin', 'guard_name' => 'web']));
        Gate::before(fn (User $user): ?bool => $user->hasRole('super_admin') ? true : null);
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(ComposeEmail::class)
            ->assertSeeHtml('x-bind:aria-label="\'Remove \' + tag"')
            ->assertSeeHtml('<span class="sr-only">');
    }
}
