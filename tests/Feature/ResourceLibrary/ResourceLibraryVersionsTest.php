<?php

declare(strict_types=1);

namespace Tests\Feature\ResourceLibrary;

use App\Enums\AccountStatus;
use App\Filament\Resources\ResourceDocumentResource\Pages\EditResourceDocument;
use App\Models\ResourceDocument;
use App\Models\ResourceDocumentEvent;
use App\Models\ResourceDocumentVersion;
use App\Models\User;
use App\Services\ResourceLibrary\ResourceFileInput;
use App\Services\ResourceLibrary\ResourceImportException;
use App\Services\ResourceLibrary\ResourceLibraryService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class ResourceLibraryVersionsTest extends TestCase
{
    use BuildsResourceLibraryAdmins;
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

    public function test_replacing_keeps_the_old_bytes_at_the_old_path_and_records_a_version(): void
    {
        $old = $this->makePdf('manual.pdf', 'Old hydrant text');
        $document = $this->importFixture('manual', 'Manual', 'Manuals', $old);
        $oldPath = $document->original_path;
        $admin = $this->manager();
        $this->actingAs($admin);

        $new = $this->makePdf('manual-2026.pdf', 'Revised sprinkler text');
        Livewire::test(EditResourceDocument::class, ['record' => $document->getKey()])
            ->fillForm(['replacement_original' => UploadedFile::fake()->createWithContent('manual-2026.pdf', (string) file_get_contents($new))])
            ->call('save')
            ->assertHasNoFormErrors();

        $fresh = ResourceDocument::query()->findOrFail($document->id);
        self::assertNotSame($oldPath, $fresh->original_path);
        self::assertSame('resource-library/'.$document->uuid.'/v2/original.pdf', $fresh->original_path);
        self::assertSame(file_get_contents($old), Storage::disk('local')->get($oldPath), 'old bytes are untouched');
        self::assertSame(file_get_contents($new), Storage::disk('local')->get($fresh->original_path));
        self::assertEqualsCanonicalizing([$oldPath, $fresh->original_path], Storage::disk('local')->allFiles());

        $version = $fresh->versions()->sole();
        self::assertSame(1, $version->version);
        self::assertSame($oldPath, $version->original_path);
        self::assertSame(hash_file('sha256', $old), $version->original_sha256);
        self::assertSame('manual.pdf', $version->original_filename);
        self::assertSame('application/pdf', $version->original_mime);
        self::assertSame(filesize($old), $version->original_size);
        self::assertSame($admin->id, $version->replaced_by);
        self::assertNotNull($version->replaced_at);
        self::assertSame(2, $fresh->currentVersionNumber());

        $event = $fresh->events()->where('event', 'replaced')->sole();
        self::assertSame($admin->id, $event->actor_id);
        self::assertSame(hash_file('sha256', $old), $event->metadata['previous']['original_sha256']);

        // The next replacement goes to v3 and v2 becomes history too.
        $third = $this->makePdf('manual-3.pdf', 'Third pump text');
        app(ResourceLibraryService::class)->store(['slug' => 'manual', 'title' => 'Manual', 'category' => 'Manuals'], new ResourceFileInput($third, 'manual-3.pdf'), actorId: $admin->id, source: 'admin');
        $latest = ResourceDocument::query()->findOrFail($document->id);
        self::assertSame('resource-library/'.$document->uuid.'/v3/original.pdf', $latest->original_path);
        self::assertSame([1, 2], $latest->versions()->orderBy('version')->pluck('version')->all());
        self::assertCount(3, Storage::disk('local')->allFiles());
    }

    public function test_replacing_only_the_pdf_copy_keeps_the_old_copy_in_history(): void
    {
        $docx = $this->makeDocx('guide.docx', 'Guide');
        $firstCopy = $this->makePdf('guide.pdf', 'First export');
        $document = $this->importFixture('guide', 'Guide', 'Policies', $docx, $firstCopy);
        $oldPreviewPath = $document->preview_path;

        $secondCopy = $this->makePdf('guide-b.pdf', 'Second export with lanyards');
        app(ResourceLibraryService::class)->store(
            ['slug' => 'guide', 'title' => 'Guide', 'category' => 'Policies'],
            new ResourceFileInput($docx, 'guide.docx'),
            new ResourceFileInput($secondCopy, 'guide-b.pdf'),
        );

        $fresh = ResourceDocument::query()->findOrFail($document->id);
        self::assertNotSame($oldPreviewPath, $fresh->preview_path);
        self::assertSame(file_get_contents($firstCopy), Storage::disk('local')->get($oldPreviewPath));
        self::assertSame($oldPreviewPath, $fresh->versions()->sole()->preview_path);
        self::assertSame($document->original_path, $fresh->original_path, 'the original did not change');
    }

    public function test_a_manager_downloads_a_previous_version_with_private_headers(): void
    {
        $old = $this->makePdf('Manual Old.pdf', 'Old hydrant text');
        $document = $this->importFixture('manual', 'Manual', 'Manuals', $old);
        app(ResourceLibraryService::class)->store(['slug' => 'manual', 'title' => 'Manual', 'category' => 'Manuals'], new ResourceFileInput($this->makePdf('Manual New.pdf', 'New text'), 'Manual New.pdf'));

        $this->actingAs($this->manager());
        $response = $this->get($this->versionUrl($document, 1))->assertOk();

        self::assertSame('application/pdf', $response->headers->get('Content-Type'));
        self::assertStringStartsWith('attachment;', (string) $response->headers->get('Content-Disposition'));
        self::assertStringContainsString('Manual Old.pdf', (string) $response->headers->get('Content-Disposition'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertSame(file_get_contents($old), $response->streamedContent());
        self::assertStringNotContainsString('resource-library', implode("\n", array_map(fn ($v) => implode(',', $v), $response->headers->all())));

        $this->get($this->versionUrl($document, 2))->assertNotFound();
        $this->get($this->versionUrl($document, 99))->assertNotFound();
        $this->get('/admin/resource-library/not-a-uuid/versions/1/download')->assertNotFound();
    }

    public function test_a_super_administrator_can_download_a_previous_version(): void
    {
        $old = $this->makePdf('manual.pdf', 'Old hydrant text');
        $document = $this->importFixture('manual', 'Manual', 'Manuals', $old);
        app(ResourceLibraryService::class)->store(['slug' => 'manual', 'title' => 'Manual', 'category' => 'Manuals'], new ResourceFileInput($this->makePdf('new.pdf', 'New text'), 'new.pdf'));

        $this->actingAs($this->superAdmin());

        self::assertSame(file_get_contents($old), $this->get($this->versionUrl($document, 1))->assertOk()->streamedContent());
    }

    public function test_previous_versions_are_refused_to_viewers_members_other_admins_and_guests(): void
    {
        $document = $this->importFixture('manual', 'Manual', 'Manuals', $this->makePdf('manual.pdf', 'Old hydrant text'));
        app(ResourceLibraryService::class)->store(['slug' => 'manual', 'title' => 'Manual', 'category' => 'Manuals'], new ResourceFileInput($this->makePdf('new.pdf', 'New text'), 'new.pdf'));
        $url = $this->versionUrl($document, 1);

        $this->actingAs($this->viewer());
        $this->get($url)->assertForbidden();

        $this->actingAs($this->adminWith(['admin.access', 'admin.forms.manage', 'admin.department_updates.manage']));
        $this->get($url)->assertForbidden();

        $this->actingAs($this->adminWith(['admin.resources.manage']));
        $this->get($url)->assertForbidden();

        $this->actingAs(User::factory()->create(['account_status' => AccountStatus::Active]));
        $this->get($url)->assertForbidden();

        $this->actingAsCanonicalFixture();
        $this->get($url)->assertForbidden();

        auth()->guard('web')->logout();
        $this->get($url)->assertRedirect();
    }

    public function test_the_edit_page_lists_earlier_versions_for_managers_and_viewers_cannot_open_it(): void
    {
        $document = $this->importFixture('manual', 'Manual', 'Manuals', $this->makePdf('manual.pdf', 'Old hydrant text'));
        $admin = $this->manager();
        app(ResourceLibraryService::class)->store(['slug' => 'manual', 'title' => 'Manual', 'category' => 'Manuals'], new ResourceFileInput($this->makePdf('new.pdf', 'New text'), 'new.pdf'), actorId: $admin->id, source: 'admin');

        $this->actingAs($admin);
        Livewire::test(EditResourceDocument::class, ['record' => $document->getKey()])
            ->assertSee('Earlier versions')
            ->assertSee('Version 1')
            ->assertSee('manual.pdf')
            ->assertSee($this->versionUrl($document, 1), false);

        $this->actingAs($this->viewer());
        Livewire::test(EditResourceDocument::class, ['record' => $document->getKey()])->assertForbidden();
    }

    public function test_a_failed_replacement_leaves_no_staged_or_new_files_and_no_version_row(): void
    {
        $old = $this->makePdf('manual.pdf', 'Old hydrant text');
        $document = $this->importFixture('manual', 'Manual', 'Manuals', $old);
        $filesBefore = Storage::disk('local')->allFiles();
        ResourceDocumentEvent::creating(function (ResourceDocumentEvent $event): void {
            if ($event->event === 'replaced') {
                throw new RuntimeException('disk full');
            }
        });

        try {
            app(ResourceLibraryService::class)->store(['slug' => 'manual', 'title' => 'Manual', 'category' => 'Manuals'], new ResourceFileInput($this->makePdf('new.pdf', 'New text'), 'new.pdf'));
            self::fail('The replacement must fail.');
        } catch (ResourceImportException $exception) {
            self::assertStringContainsString('Could not replace "manual"', $exception->getMessage());
        }

        self::assertSame($filesBefore, Storage::disk('local')->allFiles(), 'no .incoming or half-written files');
        self::assertSame(0, ResourceDocumentVersion::count(), 'the version row rolled back with the transaction');
        self::assertSame(hash_file('sha256', $old), ResourceDocument::query()->findOrFail($document->id)->original_sha256);
    }

    public function test_going_back_to_an_earlier_file_of_the_same_document_is_allowed_but_not_for_another_document(): void
    {
        $first = $this->makePdf('a.pdf', 'Version A text');
        $this->importFixture('manual', 'Manual', 'Manuals', $first);
        $service = app(ResourceLibraryService::class);
        $second = $this->makePdf('b.pdf', 'Version B text');
        $service->store(['slug' => 'manual', 'title' => 'Manual', 'category' => 'Manuals'], new ResourceFileInput($second, 'b.pdf'));

        $back = $service->store(['slug' => 'manual', 'title' => 'Manual', 'category' => 'Manuals'], new ResourceFileInput($first, 'a.pdf'));

        self::assertSame('replaced', $back->outcome);
        self::assertSame(hash_file('sha256', $first), $back->document->original_sha256);
        self::assertSame([1, 2], $back->document->versions()->orderBy('version')->pluck('version')->all());
        self::assertSame(3, $back->document->currentVersionNumber());

        $this->expectException(ResourceImportException::class);
        $this->expectExceptionMessage('This exact file is an earlier version of "Manual"');
        // Version B is now history of "Manual": another document may not claim those bytes.
        $service->store(['slug' => 'other', 'title' => 'Other', 'category' => 'Manuals'], new ResourceFileInput($second, 'b.pdf'));
    }

    public function test_versions_are_append_only(): void
    {
        $document = $this->importFixture('manual', 'Manual', 'Manuals', $this->makePdf('manual.pdf', 'Old hydrant text'));
        app(ResourceLibraryService::class)->store(['slug' => 'manual', 'title' => 'Manual', 'category' => 'Manuals'], new ResourceFileInput($this->makePdf('new.pdf', 'New text'), 'new.pdf'));
        $version = $document->versions()->sole();

        $this->expectException(\LogicException::class);
        $version->update(['original_filename' => 'tampered.pdf']);
    }

    private function versionUrl(ResourceDocument $document, int $version): string
    {
        return route('admin.resources.versions.download', ['document' => $document->uuid, 'version' => $version]);
    }
}
