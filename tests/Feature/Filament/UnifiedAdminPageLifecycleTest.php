<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Enums\AccountStatus;
use App\Filament\Pages\ComposeEmail;
use App\Filament\Pages\WorkgroupAdministration;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class UnifiedAdminPageLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->withoutVite();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_compose_email_page_initializes_its_form_during_mount(): void
    {
        $user = User::factory()->create(['account_status' => AccountStatus::Active]);
        $user->givePermissionTo(Permission::findOrCreate('admin.communications.send', 'web'));

        $this->actingAs($user);

        Livewire::test(ComposeEmail::class)
            ->assertSuccessful();
    }

    public function test_workgroup_administration_page_mounts_without_calling_a_missing_parent_hook(): void
    {
        $user = User::factory()->create(['account_status' => AccountStatus::Active]);
        $user->givePermissionTo(Permission::findOrCreate('admin.workgroups.view', 'web'));

        $this->actingAs($user);

        Livewire::test(WorkgroupAdministration::class)
            ->assertSuccessful();
    }
}
