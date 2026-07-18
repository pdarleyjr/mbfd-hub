<?php

namespace Tests\Feature;

use App\Actions\Admin\ConsolidateTrainingAdminAccounts;
use App\Filament\Resources\OperationalFormRecordResource;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\Concerns\EnsuresPermissionTables;
use Tests\TestCase;

class UnifiedAdminAccessTest extends TestCase
{
    use EnsuresPermissionTables;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensurePermissionTables();
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (['admin', 'training_admin', 'training_viewer'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }

    public function test_training_accounts_are_consolidated_into_regular_admin_access(): void
    {
        $trainingAdmin = User::factory()->create();
        $trainingAdmin->assignRole('training_admin');
        $trainingViewer = User::factory()->create();
        $trainingViewer->assignRole('training_viewer');

        $updated = app(ConsolidateTrainingAdminAccounts::class)->handle();

        $this->assertSame(2, $updated);
        $this->assertTrue($trainingAdmin->refresh()->hasAllRoles(['training_admin', 'admin']));
        $this->assertTrue($trainingViewer->refresh()->hasAllRoles(['training_viewer', 'admin']));
    }

    public function test_consolidation_is_idempotent_and_preserves_existing_admins(): void
    {
        $alreadyUnified = User::factory()->create();
        $alreadyUnified->assignRole(['training_admin', 'admin']);

        $this->assertSame(0, app(ConsolidateTrainingAdminAccounts::class)->handle());
        $this->assertTrue($alreadyUnified->refresh()->hasAllRoles(['training_admin', 'admin']));
    }

    public function test_training_account_uses_the_regular_admin_panel_and_can_view_forms(): void
    {
        $user = User::factory()->create();
        $user->assignRole(['training_admin', 'admin']);

        $this->assertTrue($user->canAccessPanel(Filament::getPanel('admin')));

        $this->actingAs($user);
        $this->assertTrue(OperationalFormRecordResource::canViewAny());
        $this->assertSame(
            url('/admin/training-todos'),
            route('filament.admin.resources.training-todos.index'),
        );
    }

    public function test_legacy_training_urls_redirect_to_the_equivalent_admin_location(): void
    {
        $this->get('/training')->assertRedirect('/admin');
        $this->get('/training/login')->assertRedirect('/admin/login');
        $this->get('/training/training-todos/42')->assertRedirect('/admin/training-todos/42');
    }
}
