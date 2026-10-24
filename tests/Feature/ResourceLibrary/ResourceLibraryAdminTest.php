<?php

declare(strict_types=1);

namespace Tests\Feature\ResourceLibrary;

use App\Enums\AccountStatus;
use App\Filament\Resources\ResourceDocumentResource;
use App\Filament\Resources\ResourceDocumentResource\Pages\EditResourceDocument;
use App\Filament\Resources\ResourceDocumentResource\Pages\ListResourceDocuments;
use App\Models\ResourceDocument;
use App\Models\User;
use App\Support\ApplicationAccessRegistry;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use ReflectionMethod;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class ResourceLibraryAdminTest extends TestCase
{
    use BuildsResourceLibraryFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->setUpResourceLibrary();
        $this->withoutVite();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    protected function tearDown(): void
    {
        $this->tearDownResourceLibrary();
        parent::tearDown();
    }

    public function test_view_and_manage_are_separate_direct_capabilities(): void
    {
        $user = $this->adminWith(['admin.access']);
        $document = $this->importFixture('manual', 'Manual', 'Manuals', $this->makePdf('manual.pdf', 'Hydrant flow'));
        $this->actingAs($user);

        self::assertFalse(ResourceDocumentResource::canViewAny());
        self::assertFalse(ResourceDocumentResource::canEdit($document));

        $user->givePermissionTo(Permission::findOrCreate('admin.resources.view', 'web'));
        self::assertTrue(ResourceDocumentResource::canViewAny());
        self::assertTrue(ResourceDocumentResource::canView($document));
        self::assertFalse(ResourceDocumentResource::canEdit($document));

        $user->givePermissionTo(Permission::findOrCreate('admin.resources.manage', 'web'));
        self::assertTrue(ResourceDocumentResource::canEdit($document));
        self::assertFalse(ResourceDocumentResource::canCreate(), 'documents are added with resources:import');
        self::assertFalse(ResourceDocumentResource::canDelete($document), 'hide instead of delete');
    }

    public function test_members_and_other_admins_cannot_reach_the_resource(): void
    {
        $document = $this->importFixture('manual', 'Manual', 'Manuals', $this->makePdf('manual.pdf', 'Hydrant flow'));

        $this->actingAs(User::factory()->create(['account_status' => AccountStatus::Active]));
        self::assertFalse(ResourceDocumentResource::canViewAny());
        self::assertFalse(ResourceDocumentResource::canEdit($document));

        // Holding other admin capabilities does not grant the library.
        $this->actingAs($this->adminWith(['admin.access', 'admin.department_updates.view', 'admin.department_updates.manage', 'admin.forms.manage']));
        self::assertFalse(ResourceDocumentResource::canViewAny());
        self::assertFalse(ResourceDocumentResource::canEdit($document));

        // The capability without the Admin entry permission does nothing either.
        $this->actingAs($this->adminWith(['admin.resources.view', 'admin.resources.manage']));
        self::assertFalse(ResourceDocumentResource::canViewAny());
    }

    public function test_super_administrators_manage_the_library(): void
    {
        $document = $this->importFixture('manual', 'Manual', 'Manuals', $this->makePdf('manual.pdf', 'Hydrant flow'));
        $user = User::factory()->create(['account_status' => AccountStatus::Active]);
        $user->assignRole(Role::findOrCreate('super_admin', 'web'));
        $this->actingAs($user);

        self::assertTrue(ResourceDocumentResource::canViewAny());
        self::assertTrue(ResourceDocumentResource::canEdit($document));
    }

    public function test_the_capability_is_offered_in_member_access_administration(): void
    {
        $options = app(ApplicationAccessRegistry::class)->capabilityOptions();

        self::assertSame('Resource library — view', $options['admin.resources.view']);
        self::assertSame('Resource library — manage', $options['admin.resources.manage']);
    }

    public function test_the_migration_creates_the_capability_but_grants_it_to_nobody(): void
    {
        // The production deploy gate refuses permission changes on established accounts; the library
        // capability is granted deliberately in the capability editor, never by a migration.
        $holder = $this->adminWith(['admin.access']);
        $member = User::factory()->create(['account_status' => AccountStatus::Active]);
        DB::table('permissions')->whereIn('name', ['admin.resources.view', 'admin.resources.manage'])->delete();
        $before = DB::table('model_has_permissions')->count();

        $this->runPermissionProvisioning();
        $this->runPermissionProvisioning(); // idempotent
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ([$holder, $member] as $user) {
            self::assertFalse($user->fresh()->hasDirectWebPermission('admin.resources.view'));
            self::assertFalse($user->fresh()->hasDirectWebPermission('admin.resources.manage'));
        }
        self::assertSame(2, Permission::query()->whereIn('name', ['admin.resources.view', 'admin.resources.manage'])->count());
        self::assertSame($before, DB::table('model_has_permissions')->count());
    }

    public function test_a_viewer_can_list_but_not_open_the_edit_form(): void
    {
        $document = $this->importFixture('manual', 'Manual', 'Manuals', $this->makePdf('manual.pdf', 'Hydrant flow'));
        $this->actingAs($this->adminWith(['admin.access', 'admin.resources.view']));

        Livewire::test(ListResourceDocuments::class)->call('loadTable')->assertSuccessful()->assertCanSeeTableRecords([$document]);
        Livewire::test(EditResourceDocument::class, ['record' => $document->getKey()])->assertForbidden();
    }

    public function test_a_manager_edits_metadata_and_visibility_with_history(): void
    {
        $pdf = $this->makePdf('manual.pdf', 'Hydrant flow');
        $document = $this->importFixture('manual', 'Manual', 'Manuals', $pdf);
        $admin = $this->adminWith(['admin.access', 'admin.resources.view', 'admin.resources.manage']);
        $this->actingAs($admin);

        Livewire::test(EditResourceDocument::class, ['record' => $document->getKey()])
            ->fillForm(['title' => 'Flow Manual 2026', 'category' => 'Apparatus Manuals', 'description' => 'Updated line.', 'sort' => 3, 'published' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        $fresh = ResourceDocument::query()->findOrFail($document->id);
        self::assertSame('Flow Manual 2026', $fresh->title);
        self::assertSame('Apparatus Manuals', $fresh->category);
        self::assertFalse($fresh->published);
        self::assertSame(3, $fresh->sort);
        self::assertSame(file_get_contents($pdf), Storage::disk('local')->get($fresh->original_path), 'metadata edits never touch the file');

        $event = $fresh->events()->where('event', 'unpublished')->sole();
        self::assertSame($admin->id, $event->actor_id);
    }

    public function test_a_manager_replaces_the_original_through_the_same_service(): void
    {
        $document = $this->importFixture('manual', 'Manual', 'Manuals', $this->makePdf('manual.pdf', 'Old hydrant text'));
        $oldSha = $document->original_sha256;
        $admin = $this->adminWith(['admin.access', 'admin.resources.view', 'admin.resources.manage']);
        $this->actingAs($admin);

        $replacement = $this->makePdf('manual-2026.pdf', 'Revised sprinkler text');
        Livewire::test(EditResourceDocument::class, ['record' => $document->getKey()])
            ->fillForm(['replacement_original' => UploadedFile::fake()->createWithContent('manual-2026.pdf', (string) file_get_contents($replacement))])
            ->call('save')
            ->assertHasNoFormErrors();

        $fresh = ResourceDocument::query()->findOrFail($document->id);
        self::assertSame(hash_file('sha256', $replacement), $fresh->original_sha256);
        self::assertSame('manual-2026.pdf', $fresh->original_filename);
        self::assertSame($document->uuid, $fresh->uuid);
        // Replacing keeps the superseded bytes as a version (see ResourceLibraryVersionsTest).
        self::assertEqualsCanonicalizing([$document->original_path, $fresh->original_path], Storage::disk('local')->allFiles());
        self::assertStringContainsString('sprinkler', (string) ResourceDocument::query()->whereKey($fresh->id)->value('search_text'));

        $event = $fresh->events()->where('event', 'replaced')->sole();
        self::assertSame($admin->id, $event->actor_id);
        self::assertSame('admin', $event->metadata['source']);
        self::assertSame($oldSha, $event->metadata['previous']['original_sha256']);
    }

    public function test_a_replacement_that_is_not_the_claimed_type_is_refused_and_changes_nothing(): void
    {
        $document = $this->importFixture('manual', 'Manual', 'Manuals', $this->makePdf('manual.pdf', 'Old hydrant text'));
        $before = $document->original_sha256;
        $this->actingAs($this->adminWith(['admin.access', 'admin.resources.view', 'admin.resources.manage']));

        Livewire::test(EditResourceDocument::class, ['record' => $document->getKey()])
            ->fillForm(['title' => 'Should not stick', 'replacement_original' => UploadedFile::fake()->createWithContent('fake.pdf', 'plain text, not a pdf')])
            ->call('save');

        $fresh = ResourceDocument::query()->findOrFail($document->id);
        self::assertSame($before, $fresh->original_sha256);
        self::assertSame('Manual', $fresh->title, 'a refused replacement saves nothing');
        self::assertSame(0, $fresh->events()->where('event', 'replaced')->count());
    }

    /** @param  list<string>  $permissions */
    private function adminWith(array $permissions): User
    {
        $user = User::factory()->create(['account_status' => AccountStatus::Active]);
        $user->givePermissionTo(array_map(fn (string $name): Permission => Permission::findOrCreate($name, 'web'), $permissions));

        return $user;
    }

    private function runPermissionProvisioning(): void
    {
        $migration = require base_path('database/migrations/2026_10_23_220000_create_resource_library_tables.php');
        (new ReflectionMethod($migration, 'provisionPermissions'))->invoke($migration);
    }
}
