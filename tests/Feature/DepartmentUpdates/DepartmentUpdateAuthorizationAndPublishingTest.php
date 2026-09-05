<?php

declare(strict_types=1);

namespace Tests\Feature\DepartmentUpdates;

use App\Enums\AccountStatus;
use App\Filament\Resources\DepartmentUpdateResource;
use App\Filament\Resources\DepartmentUpdateResource\Pages\CreateDepartmentUpdate;
use App\Models\DepartmentUpdate;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
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

    public function test_department_updates_use_granular_communications_capabilities(): void
    {
        $admin = User::factory()->create(['account_status' => AccountStatus::Active]);
        $admin->givePermissionTo(Permission::findOrCreate('admin.access', 'web'));
        $employee = User::factory()->create(['account_status' => AccountStatus::Active]);

        $this->actingAs($admin);
        self::assertFalse(DepartmentUpdateResource::canViewAny());
        self::assertFalse(DepartmentUpdateResource::canCreate());

        $admin->givePermissionTo(Permission::findOrCreate('admin.communications.view', 'web'));
        self::assertTrue(DepartmentUpdateResource::canViewAny());
        self::assertFalse(DepartmentUpdateResource::canCreate());

        $admin->givePermissionTo(Permission::findOrCreate('admin.communications.send', 'web'));
        self::assertTrue(DepartmentUpdateResource::canCreate());

        $this->actingAs($employee);
        self::assertFalse(DepartmentUpdateResource::canViewAny());
        self::assertFalse(DepartmentUpdateResource::canCreate());
    }

    public function test_admin_create_workflow_attributes_the_author_and_validates_publication_fields(): void
    {
        $admin = User::factory()->create(['account_status' => AccountStatus::Active]);
        $admin->givePermissionTo([
            Permission::findOrCreate('admin.access', 'web'),
            Permission::findOrCreate('admin.communications.view', 'web'),
            Permission::findOrCreate('admin.communications.send', 'web'),
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
}
