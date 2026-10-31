<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Enums\AccountStatus;
use App\Livewire\Mail\MessageComposer;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Every tag remove button must have an accessible name. The recipient fields of the Email Center's composer replaced the
 * Compose Email TagsInputs (they need server suggestions a TagsInput cannot give), so the rule is asserted on their chips.
 */
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

        $html = Livewire::test(MessageComposer::class)->html();

        // One named remove button per chip, in To, Cc and Bcc, and a screen-reader note when an address is not valid.
        $this->assertSame(3, substr_count($html, ':aria-label="\'Remove \' + chip"'));
        $this->assertSame(3, substr_count($html, 'class="mail-chip__x"'));
        $this->assertStringContainsString('<span class="mail-sr"', $html);
    }
}
