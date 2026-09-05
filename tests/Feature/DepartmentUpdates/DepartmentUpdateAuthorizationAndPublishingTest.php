<?php

declare(strict_types=1);

namespace Tests\Feature\DepartmentUpdates;

use App\Enums\AccountStatus;
use App\Enums\DepartmentUpdateStatus;
use App\Filament\Pages\ComposeEmail;
use App\Filament\Resources\DepartmentUpdateResource;
use App\Filament\Resources\DepartmentUpdateResource\Pages\CreateDepartmentUpdate;
use App\Filament\Resources\DepartmentUpdateResource\Pages\ListDepartmentUpdates;
use App\Models\DepartmentUpdate;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class DepartmentUpdateAuthorizationAndPublishingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->withoutVite();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_department_updates_use_dedicated_capabilities_without_granting_email_access(): void
    {
        $admin = User::factory()->create(['account_status' => AccountStatus::Active]);
        $admin->assignRole(Role::findOrCreate('logistics_admin', 'web'));
        $admin->givePermissionTo(Permission::findOrCreate('admin.access', 'web'));
        $employee = User::factory()->create(['account_status' => AccountStatus::Active]);

        $this->actingAs($admin);
        self::assertFalse(DepartmentUpdateResource::canViewAny());
        self::assertFalse(DepartmentUpdateResource::canCreate());

        $admin->givePermissionTo(Permission::findOrCreate('admin.department_updates.view', 'web'));
        self::assertTrue(DepartmentUpdateResource::canViewAny());
        self::assertFalse(DepartmentUpdateResource::canCreate());

        $admin->givePermissionTo(Permission::findOrCreate('admin.department_updates.manage', 'web'));
        self::assertTrue(DepartmentUpdateResource::canCreate());
        self::assertFalse($admin->can('admin.communications.view'));
        self::assertFalse($admin->can('admin.communications.send'));
        self::assertFalse(ComposeEmail::canAccess());

        $this->actingAs($employee);
        self::assertFalse(DepartmentUpdateResource::canViewAny());
        self::assertFalse(DepartmentUpdateResource::canCreate());
    }

    public function test_admin_create_workflow_attributes_the_author_and_validates_publication_fields(): void
    {
        $admin = User::factory()->create(['account_status' => AccountStatus::Active]);
        $admin->givePermissionTo([
            Permission::findOrCreate('admin.access', 'web'),
            Permission::findOrCreate('admin.department_updates.view', 'web'),
            Permission::findOrCreate('admin.department_updates.manage', 'web'),
        ]);
        $this->actingAs($admin);

        Livewire::test(CreateDepartmentUpdate::class)
            ->fillForm([
                'title' => 'Water rescue training schedule',
                'body' => '<p>Review the updated training schedule.</p>',
                'category' => 'training',
                'priority' => 'important',
                'status' => 'published',
                'publish_at' => now()->subMinute()->format('Y-m-d H:i:s'),
                'expires_at' => now()->addWeek()->format('Y-m-d H:i:s'),
                'cta_label' => 'View Schedule',
                'cta_url' => '/employee/training',
                'send_in_app' => false,
                'send_web_push' => false,
                'audience' => 'everyone',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $update = DepartmentUpdate::query()->sole();
        self::assertSame($admin->id, $update->author_id);
        self::assertSame('Water rescue training schedule', $update->title);

        Livewire::test(CreateDepartmentUpdate::class)
            ->fillForm([
                'title' => 'Unsafe link',
                'body' => '<p>Body</p>',
                'category' => 'general',
                'priority' => 'normal',
                'status' => 'published',
                'publish_at' => now()->format('Y-m-d H:i:s'),
                'expires_at' => now()->subDay()->format('Y-m-d H:i:s'),
                'cta_label' => 'Open',
                'cta_url' => 'javascript:alert(1)',
                'audience' => 'everyone',
            ])
            ->call('create')
            ->assertHasFormErrors(['expires_at', 'cta_url']);

        Livewire::test(CreateDepartmentUpdate::class)
            ->fillForm([
                'title' => 'Forged archived notice',
                'body' => '<p>Never published.</p>',
                'category' => 'general',
                'priority' => 'normal',
                'status' => 'archived',
                'audience' => 'everyone',
            ])
            ->call('create')
            ->assertHasFormErrors(['status']);
    }

    public function test_view_only_user_cannot_forge_mutating_table_actions(): void
    {
        $viewer = $this->adminWithDepartmentUpdatePermissions(manage: false);
        $update = $this->departmentUpdate(DepartmentUpdateStatus::Draft);

        $this->actingAs($viewer);

        self::assertTrue(DepartmentUpdateResource::canViewAny());
        self::assertFalse(DepartmentUpdateResource::canCreate());
        self::assertFalse(DepartmentUpdateResource::canEdit($update));
        self::assertFalse(DepartmentUpdateResource::canDelete($update));
        self::assertFalse(DepartmentUpdateResource::canRestore($update));
        $this->get(DepartmentUpdateResource::getUrl('create'))->assertForbidden();
        $this->get(DepartmentUpdateResource::getUrl('edit', ['record' => $update]))->assertForbidden();

        foreach (['publish', 'unpublish', 'archive', 'delete', 'restore'] as $action) {
            Livewire::test(ListDepartmentUpdates::class)
                ->call('mountTableAction', $action, (string) $update->getKey())
                ->assertSet('mountedTableActions', []);
        }

        self::assertSame(DepartmentUpdateStatus::Draft, $update->fresh()->status);
        self::assertNull($update->fresh()->deleted_at);
    }

    public function test_manage_user_and_super_admin_can_execute_authorized_custom_actions(): void
    {
        $manager = $this->adminWithDepartmentUpdatePermissions(manage: true);
        $update = $this->departmentUpdate(DepartmentUpdateStatus::Draft);

        $this->actingAs($manager);
        Livewire::test(ListDepartmentUpdates::class)->callTableAction('publish', $update);
        self::assertSame(DepartmentUpdateStatus::Published, $update->fresh()->status);

        $update->refresh();
        Livewire::test(ListDepartmentUpdates::class)->callTableAction('unpublish', $update);
        self::assertSame(DepartmentUpdateStatus::Draft, $update->fresh()->status);

        $update->refresh();
        Livewire::test(ListDepartmentUpdates::class)->callTableAction('publish', $update);
        $update->refresh();
        Livewire::test(ListDepartmentUpdates::class)->callTableAction('archive', $update);
        self::assertSame(DepartmentUpdateStatus::Archived, $update->fresh()->status);

        $deletable = $this->departmentUpdate(DepartmentUpdateStatus::Draft);
        self::assertTrue(DepartmentUpdateResource::canEdit($deletable));
        self::assertTrue(DepartmentUpdateResource::canDelete($deletable));
        self::assertTrue(DepartmentUpdateResource::canRestore($deletable));
        Livewire::test(ListDepartmentUpdates::class)->callTableAction('delete', $deletable);
        $this->assertSoftDeleted($deletable);
        $deletable->refresh();
        Livewire::test(ListDepartmentUpdates::class)
            ->filterTable('trashed', false)
            ->callTableAction('restore', $deletable);
        self::assertNull($deletable->fresh()->deleted_at);

    }

    public function test_super_admin_can_execute_department_update_actions(): void
    {
        $superAdmin = User::factory()->create(['account_status' => AccountStatus::Active]);
        $superAdmin->assignRole(Role::findOrCreate('super_admin', 'web'));
        $update = $this->departmentUpdate(DepartmentUpdateStatus::Draft);

        $this->actingAs($superAdmin);
        self::assertTrue(DepartmentUpdateResource::canCreate());
        self::assertTrue(DepartmentUpdateResource::canEdit($update));
        self::assertTrue(DepartmentUpdateResource::canDelete($update));
        self::assertTrue(DepartmentUpdateResource::canRestore($update));
        Livewire::test(ListDepartmentUpdates::class)->callTableAction('publish', $update);
        self::assertSame(DepartmentUpdateStatus::Published, $update->fresh()->status);
    }

    public function test_archive_action_preserves_only_legitimate_published_history(): void
    {
        $manager = $this->adminWithDepartmentUpdatePermissions(manage: true);
        $this->actingAs($manager);

        $draft = $this->departmentUpdate(DepartmentUpdateStatus::Draft);
        Livewire::test(ListDepartmentUpdates::class)
            ->call('mountTableAction', 'archive', (string) $draft->getKey())
            ->assertSet('mountedTableActions', []);
        self::assertSame(DepartmentUpdateStatus::Draft, $draft->fresh()->status);
        self::assertFalse($draft->fresh()->isPublishedHistory());

        $future = $this->departmentUpdate(DepartmentUpdateStatus::Published);
        $future->update(['publish_at' => now()->addHour()]);
        Livewire::test(ListDepartmentUpdates::class)->callTableAction('unpublish', $future);
        Livewire::test(ListDepartmentUpdates::class)
            ->call('mountTableAction', 'archive', (string) $future->getKey())
            ->assertSet('mountedTableActions', []);
        $this->travel(2)->hours();
        self::assertFalse($future->fresh()->isPublishedHistory());

        $expired = $this->departmentUpdate(DepartmentUpdateStatus::Published);
        $expired->update(['expires_at' => now()->subMinute()]);
        self::assertTrue($expired->fresh()->isPublishedHistory());

        $published = $this->departmentUpdate(DepartmentUpdateStatus::Published);
        Livewire::test(ListDepartmentUpdates::class)->callTableAction('archive', $published);
        self::assertSame(DepartmentUpdateStatus::Archived, $published->fresh()->status);
        self::assertTrue($published->fresh()->isPublishedHistory());
    }

    public function test_private_attachment_requires_authentication(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create(['account_status' => AccountStatus::Active]);
        $path = UploadedFile::fake()->create('memo.pdf', 64, 'application/pdf')->store('department-updates/attachments', 'local');
        $update = DepartmentUpdate::query()->create([
            'title' => 'Memo',
            'body' => '<p>Attached memo.</p>',
            'category' => 'administration',
            'priority' => 'normal',
            'status' => 'published',
            'publish_at' => now()->subMinute(),
            'attachment_path' => $path,
            'attachment_name' => 'memo.pdf',
            'author_id' => $admin->id,
        ]);

        $this->get("/updates/{$update->id}/attachment")->assertRedirect('/login');
        $this->actingAsCanonicalFixture();
        $this->get("/updates/{$update->id}/attachment")->assertOk()
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private');
    }

    private function adminWithDepartmentUpdatePermissions(bool $manage): User
    {
        $admin = User::factory()->create(['account_status' => AccountStatus::Active]);
        $permissions = [
            Permission::findOrCreate('admin.access', 'web'),
            Permission::findOrCreate('admin.department_updates.view', 'web'),
        ];
        if ($manage) {
            $permissions[] = Permission::findOrCreate('admin.department_updates.manage', 'web');
        }
        $admin->givePermissionTo($permissions);

        return $admin;
    }

    private function departmentUpdate(DepartmentUpdateStatus $status): DepartmentUpdate
    {
        return DepartmentUpdate::query()->create([
            'title' => 'Authorization fixture',
            'body' => '<p>Authorization fixture.</p>',
            'category' => 'general',
            'priority' => 'normal',
            'status' => $status,
            'publish_at' => now()->subMinute(),
            'author_id' => User::factory()->create()->id,
        ]);
    }
}
