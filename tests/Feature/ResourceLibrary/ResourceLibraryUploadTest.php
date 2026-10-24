<?php

declare(strict_types=1);

namespace Tests\Feature\ResourceLibrary;

use App\Enums\AccountStatus;
use App\Filament\Resources\ResourceDocumentResource;
use App\Filament\Resources\ResourceDocumentResource\Pages\EditResourceDocument;
use App\Filament\Resources\ResourceDocumentResource\Pages\ListResourceDocuments;
use App\Models\ResourceDocument;
use App\Models\User;
use App\Services\ResourceLibrary\ResourceFileInput;
use App\Services\ResourceLibrary\ResourceImportException;
use App\Services\ResourceLibrary\ResourceLibraryService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class ResourceLibraryUploadTest extends TestCase
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

    public function test_a_manager_adds_a_pdf_and_members_see_it_immediately_with_a_working_preview(): void
    {
        $pdf = $this->makePdf('Hydrant Flow.pdf', 'Hydrant flow standard', 2);
        $admin = $this->manager();

        $this->addDocument($admin, [
            'file' => $this->uploaded($pdf),
            'title' => 'Hydrant Flow Manual',
            'category' => 'Manuals',
            'description' => 'Pump hydraulics reference.',
            'published' => true,
        ])->assertHasNoActionErrors();

        $document = ResourceDocument::query()->where('slug', 'hydrant-flow-manual')->firstOrFail();
        self::assertTrue($document->published);
        self::assertNotNull($document->published_at);
        self::assertSame('Manuals', $document->category);
        self::assertSame(hash_file('sha256', $pdf), $document->original_sha256);
        self::assertSame(file_get_contents($pdf), Storage::disk('local')->get($document->original_path));
        self::assertGreaterThanOrEqual(2, $document->page_count);
        $event = $document->events()->sole();
        self::assertSame('imported', $event->event);
        self::assertSame($admin->id, $event->actor_id);
        self::assertSame('admin', $event->metadata['source']);

        $this->actingAsCanonicalFixture();
        $this->get('/employee/resources')->assertOk()
            ->assertSee('Hydrant Flow Manual')
            ->assertSee('Pump hydraulics reference.')
            ->assertSee('class="rl-badge-new"', false);
        $preview = $this->get(route('employee.resources.preview', ['document' => $document->uuid]))->assertOk();
        self::assertSame('application/pdf', $preview->headers->get('Content-Type'));
        self::assertSame(file_get_contents($pdf), $preview->streamedContent());
    }

    public function test_a_docx_without_a_pdf_copy_is_download_only(): void
    {
        $docx = $this->makeDocx('Dress Code.docx', 'Dress code');

        $this->addDocument($this->manager(), [
            'file' => $this->uploaded($docx),
            'title' => 'Dress Code',
            'category' => 'Policies',
            'published' => true,
        ])->assertHasNoActionErrors();

        $document = ResourceDocument::query()->where('slug', 'dress-code')->firstOrFail();
        self::assertNull($document->preview_path);
        self::assertFalse($document->hasPreview());

        $this->actingAsCanonicalFixture();
        $this->get(route('employee.resources.preview', ['document' => $document->uuid]))->assertNotFound();
        $download = $this->get(route('employee.resources.download', ['document' => $document->uuid]))->assertOk();
        self::assertSame(file_get_contents($docx), $download->streamedContent());
    }

    public function test_a_docx_with_a_pdf_copy_can_be_opened_in_the_browser(): void
    {
        $docx = $this->makeDocx('Dress Code.docx', 'Dress code');
        $copy = $this->makePdf('Dress Code.pdf', 'Dress code badge placement');

        $this->addDocument($this->manager(), [
            'file' => $this->uploaded($docx),
            'preview' => $this->uploaded($copy),
            'title' => 'Dress Code',
            'category' => 'Policies',
            'published' => true,
        ])->assertHasNoActionErrors();

        $document = ResourceDocument::query()->where('slug', 'dress-code')->firstOrFail();
        self::assertSame($document->preview_path, $document->previewDiskPath());
        self::assertStringContainsString('badge placement', (string) ResourceDocument::query()->whereKey($document->id)->value('search_text'));

        $this->actingAsCanonicalFixture();
        $open = $this->get(route('employee.resources.preview', ['document' => $document->uuid]))->assertOk();
        self::assertSame(file_get_contents($copy), $open->streamedContent());
        $download = $this->get(route('employee.resources.download', ['document' => $document->uuid]))->assertOk();
        self::assertSame(file_get_contents($docx), $download->streamedContent());
    }

    public function test_a_document_can_be_added_hidden_and_stays_off_the_member_page(): void
    {
        $this->addDocument($this->manager(), [
            'file' => $this->uploaded($this->makePdf('draft.pdf', 'Draft text')),
            'title' => 'Draft Procedure',
            'category' => 'Drafts',
            'published' => false,
        ])->assertHasNoActionErrors();

        $document = ResourceDocument::query()->where('slug', 'draft-procedure')->firstOrFail();
        self::assertFalse($document->published);
        self::assertNull($document->published_at);

        $this->actingAsCanonicalFixture();
        $this->get('/employee/resources')->assertOk()->assertDontSee('Draft Procedure');
        $this->get(route('employee.resources.preview', ['document' => $document->uuid]))->assertNotFound();
    }

    public function test_the_slug_comes_from_the_title_and_is_unique_even_against_deleted_documents(): void
    {
        $deleted = $this->importFixture('hydrant-manual', 'Old', 'Manuals', $this->makePdf('old.pdf', 'Old text'));
        $deleted->delete();
        $this->importFixture('hydrant-manual-2', 'Other', 'Manuals', $this->makePdf('other.pdf', 'Other text'));

        $this->addDocument($this->manager(), [
            'file' => $this->uploaded($this->makePdf('new.pdf', 'New text')),
            'title' => 'Hydrant Manual',
            'category' => 'Manuals',
            'published' => true,
        ])->assertHasNoActionErrors();

        self::assertTrue(ResourceDocument::query()->where('slug', 'hydrant-manual-3')->exists());
        self::assertSame('document', app(ResourceLibraryService::class)->uniqueSlug('!!!'));
    }

    public function test_the_title_is_prefilled_from_the_file_name(): void
    {
        $this->actingAs($this->manager());

        Livewire::test(ListResourceDocuments::class)
            ->mountAction('addDocument')
            ->setActionData(['file' => $this->uploaded($this->makePdf('Pump_Operations-Guide.pdf', 'Pump text'))])
            ->assertActionDataSet(['title' => 'Pump Operations Guide']);
    }

    public function test_duplicate_bytes_are_refused_with_a_clear_message_and_nothing_is_saved(): void
    {
        $pdf = $this->makePdf('manual.pdf', 'Hydrant flow standard');
        $this->importFixture('first', 'First Manual', 'Manuals', $pdf);
        $filesBefore = Storage::disk('local')->allFiles();

        $this->addDocument($this->manager(), [
            'file' => $this->uploaded($pdf),
            'title' => 'Second Copy',
            'category' => 'Manuals',
            'published' => true,
        ])->assertNotified('The document was not added');

        self::assertSame(1, ResourceDocument::withTrashed()->count());
        self::assertSame($filesBefore, Storage::disk('local')->allFiles());
        try {
            $this->importFixture('third', 'Third', 'Manuals', $pdf);
            self::fail('Duplicate bytes must be refused.');
        } catch (ResourceImportException $exception) {
            self::assertStringContainsString('already in the library as "First Manual"', $exception->getMessage());
        }
    }

    public function test_a_file_that_is_not_what_its_name_claims_is_refused_and_nothing_is_saved(): void
    {
        $this->addDocument($this->manager(), [
            'file' => UploadedFile::fake()->createWithContent('fake.pdf', 'plain text, not a pdf'),
            'title' => 'Fake',
            'category' => 'Manuals',
            'published' => true,
        ])->assertNotified('The document was not added');

        self::assertSame(0, ResourceDocument::withTrashed()->count());
        self::assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_an_unsupported_type_is_refused_by_the_form_before_anything_is_saved(): void
    {
        $this->addDocument($this->manager(), [
            'file' => UploadedFile::fake()->createWithContent('notes.txt', 'plain text'),
            'title' => 'Notes',
            'category' => 'Manuals',
            'published' => true,
        ])->assertHasActionErrors(['file']);

        self::assertSame(0, ResourceDocument::count());
        self::assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_a_pdf_original_does_not_take_a_pdf_copy(): void
    {
        $this->addDocument($this->manager(), [
            'file' => $this->uploaded($this->makePdf('manual.pdf', 'Hydrant flow')),
            'preview' => $this->uploaded($this->makePdf('copy.pdf', 'Other text')),
            'title' => 'Manual',
            'category' => 'Manuals',
            'published' => true,
        ])->assertNotified('The document was not added');

        self::assertSame(0, ResourceDocument::count());
        self::assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_the_service_refuses_office_files_without_their_package_part(): void
    {
        $service = app(ResourceLibraryService::class);
        $cases = [
            'no-word.docx' => ['[Content_Types].xml', 'xl/workbook.xml'],
            'no-xl.xlsx' => ['[Content_Types].xml', 'word/document.xml'],
            'no-ppt.pptx' => ['[Content_Types].xml', 'docProps/app.xml'],
            'no-types.docx' => ['word/document.xml'],
        ];
        foreach ($cases as $name => $parts) {
            $path = $this->makeZip($name, $parts);
            try {
                $service->store(['slug' => 'x-'.substr($name, 0, 6), 'title' => 'X', 'category' => 'Y'], new ResourceFileInput($path, $name));
                self::fail($name.' must be refused.');
            } catch (ResourceImportException $exception) {
                self::assertStringContainsString('does not look like a real', $exception->getMessage(), $name);
            }
        }
        self::assertSame(0, ResourceDocument::count());
        self::assertSame([], Storage::disk('local')->allFiles());

        // Genuine packages of each type pass.
        foreach ([
            'ok.docx' => ['[Content_Types].xml', 'word/document.xml'],
            'ok.xlsx' => ['[Content_Types].xml', 'xl/workbook.xml'],
            'ok.pptx' => ['[Content_Types].xml', 'ppt/presentation.xml'],
        ] as $name => $parts) {
            $result = $service->store(['slug' => 'ok-'.substr($name, 3), 'title' => 'Ok '.$name, 'category' => 'Y'], new ResourceFileInput($this->makeZip($name, $parts), $name));
            self::assertSame('created', $result->outcome, $name);
        }
    }

    public function test_the_service_refuses_files_over_the_size_limit(): void
    {
        $huge = $this->makeOversizePdf('huge.pdf');

        try {
            app(ResourceLibraryService::class)->store(['slug' => 'huge', 'title' => 'Huge', 'category' => 'Manuals'], new ResourceFileInput($huge, 'huge.pdf'));
            self::fail('A file over the limit must be refused.');
        } catch (ResourceImportException $exception) {
            self::assertStringContainsString('The limit is 50 MB', $exception->getMessage());
        }
        self::assertSame(0, ResourceDocument::count());
        self::assertSame([], Storage::disk('local')->allFiles());
        self::assertSame(52_428_800, ResourceLibraryService::MAX_BYTES);
    }

    public function test_a_viewer_cannot_see_or_run_add(): void
    {
        $viewer = $this->viewer();
        $this->actingAs($viewer);
        self::assertFalse(Gate::allows('create', ResourceDocument::class));

        $component = Livewire::test(ListResourceDocuments::class)->assertActionHidden('addDocument');
        // Calling the hidden action directly must still save nothing.
        $this->runHiddenAction($component, 'addDocument', [
            'file' => $this->uploaded($this->makePdf('sneaky.pdf', 'Sneaky text')),
            'title' => 'Sneaky',
            'category' => 'Manuals',
            'published' => true,
        ]);

        self::assertSame(0, ResourceDocument::withTrashed()->count());
        self::assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_members_and_other_admins_cannot_add_documents(): void
    {
        $this->actingAs(User::factory()->create(['account_status' => AccountStatus::Active]));
        self::assertFalse(Gate::allows('create', ResourceDocument::class));
        Livewire::test(ListResourceDocuments::class)->assertForbidden();

        $this->actingAs($this->adminWith(['admin.access', 'admin.forms.manage', 'admin.department_updates.manage']));
        self::assertFalse(Gate::allows('create', ResourceDocument::class));
        Livewire::test(ListResourceDocuments::class)->assertForbidden();
    }

    public function test_a_super_administrator_can_add_documents(): void
    {
        $admin = $this->superAdmin();
        $this->actingAs($admin);
        self::assertTrue(Gate::allows('create', ResourceDocument::class));

        $this->addDocument($admin, [
            'file' => $this->uploaded($this->makePdf('manual.pdf', 'Hydrant flow')),
            'title' => 'Super Manual',
            'category' => 'Manuals',
            'published' => true,
        ])->assertHasNoActionErrors();

        self::assertTrue(ResourceDocument::query()->where('slug', 'super-manual')->exists());
    }

    public function test_hiding_a_document_removes_it_from_members_and_is_audited_and_showing_restores_it(): void
    {
        $document = $this->importFixture('manual', 'Flow Manual', 'Manuals', $this->makePdf('manual.pdf', 'Hydrant flow'));
        $admin = $this->manager();
        $this->actingAs($admin);

        Livewire::test(ListResourceDocuments::class)->call('loadTable')
            ->callTableAction('toggleVisibility', $document)
            ->assertHasNoTableActionErrors();

        self::assertFalse(ResourceDocument::query()->findOrFail($document->id)->published);
        $event = $document->events()->where('event', 'unpublished')->sole();
        self::assertSame($admin->id, $event->actor_id);

        $this->actingAsCanonicalFixture();
        $this->get(route('employee.resources.preview', ['document' => $document->uuid]))->assertNotFound();
        $this->get(route('employee.resources.download', ['document' => $document->uuid]))->assertNotFound();
        $this->get('/employee/resources')->assertOk()->assertDontSee('Flow Manual');

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin);
        Livewire::test(ListResourceDocuments::class)->call('loadTable')->callTableAction('toggleVisibility', $document);
        self::assertTrue(ResourceDocument::query()->findOrFail($document->id)->published);
        self::assertSame(1, $document->events()->where('event', 'published')->count());
        self::assertTrue(Storage::disk('local')->exists($document->original_path), 'hiding never touches the bytes');
        self::assertFalse(ResourceDocumentResource::canDelete($document));
        self::assertFalse(ResourceDocumentResource::canDeleteAny());
    }

    public function test_a_viewer_cannot_hide_a_document(): void
    {
        $document = $this->importFixture('manual', 'Flow Manual', 'Manuals', $this->makePdf('manual.pdf', 'Hydrant flow'));
        $this->actingAs($this->viewer());

        $component = Livewire::test(ListResourceDocuments::class)->call('loadTable')->assertTableActionHidden('toggleVisibility', $document);
        $this->runHiddenTableAction($component, 'toggleVisibility', $document);

        self::assertTrue(ResourceDocument::query()->findOrFail($document->id)->published);
        self::assertSame(0, $document->events()->where('event', 'unpublished')->count());
    }

    public function test_renaming_recategorizing_and_describing_are_audited_with_before_and_after(): void
    {
        $document = $this->importFixture('manual', 'Flow Manual', 'Manuals', $this->makePdf('manual.pdf', 'Hydrant flow'), description: 'Old line.');
        $admin = $this->manager();
        $this->actingAs($admin);

        Livewire::test(EditResourceDocument::class, ['record' => $document->getKey()])
            ->fillForm(['title' => 'Flow Manual 2026', 'category' => 'Apparatus Manuals', 'description' => 'New line.'])
            ->call('save')
            ->assertHasNoFormErrors();

        $event = $document->events()->where('event', 'details_changed')->sole();
        self::assertSame($admin->id, $event->actor_id);
        self::assertSame('admin', $event->metadata['source']);
        self::assertSame(['title' => 'Flow Manual', 'category' => 'Manuals', 'description' => 'Old line.'], $event->metadata['before']);
        self::assertSame(['title' => 'Flow Manual 2026', 'category' => 'Apparatus Manuals', 'description' => 'New line.'], $event->metadata['after']);

        // Saving again without changes writes no new event.
        Livewire::test(EditResourceDocument::class, ['record' => $document->getKey()])->call('save');
        self::assertSame(1, $document->events()->where('event', 'details_changed')->count());
    }

    public function test_reordering_saves_the_new_order_and_audits_each_move_with_the_actor(): void
    {
        $first = $this->importFixture('first', 'First', 'Manuals', $this->makePdf('a.pdf', 'A text'));
        $second = $this->importFixture('second', 'Second', 'Manuals', $this->makePdf('b.pdf', 'B text'));
        $third = $this->importFixture('third', 'Third', 'Manuals', $this->makePdf('c.pdf', 'C text'));
        ResourceDocument::query()->whereKey($first->id)->update(['sort' => 1]);
        ResourceDocument::query()->whereKey($second->id)->update(['sort' => 2]);
        ResourceDocument::query()->whereKey($third->id)->update(['sort' => 3]);
        $admin = $this->manager();
        $this->actingAs($admin);

        Livewire::test(ListResourceDocuments::class)->call('loadTable')
            ->call('reorderTable', [$third->id, $first->id, $second->id]);

        self::assertSame([1, 2, 3], [
            ResourceDocument::query()->find($third->id)->sort,
            ResourceDocument::query()->find($first->id)->sort,
            ResourceDocument::query()->find($second->id)->sort,
        ]);
        $moved = $third->events()->where('event', 'reordered')->sole();
        self::assertSame($admin->id, $moved->actor_id);
        self::assertSame('admin', $moved->metadata['source']);
        self::assertSame(['sort' => 3], $moved->metadata['before']);
        self::assertSame(['sort' => 1], $moved->metadata['after']);
        self::assertSame(1, $first->events()->where('event', 'reordered')->count());
        self::assertSame(1, $second->events()->where('event', 'reordered')->count());

        // Members see the new order inside the category.
        $this->actingAsCanonicalFixture();
        $this->get('/employee/resources')->assertOk()->assertSeeInOrder(['Third', 'First', 'Second']);
    }

    public function test_a_viewer_cannot_reorder(): void
    {
        $first = $this->importFixture('first', 'First', 'Manuals', $this->makePdf('a.pdf', 'A text'));
        $second = $this->importFixture('second', 'Second', 'Manuals', $this->makePdf('b.pdf', 'B text'));
        ResourceDocument::query()->whereKey($first->id)->update(['sort' => 1]);
        ResourceDocument::query()->whereKey($second->id)->update(['sort' => 2]);
        $this->actingAs($this->viewer());

        Livewire::test(ListResourceDocuments::class)->call('loadTable')
            ->call('reorderTable', [$second->id, $first->id])
            ->assertForbidden();

        self::assertSame(1, ResourceDocument::query()->find($first->id)->sort);
        self::assertSame(2, ResourceDocument::query()->find($second->id)->sort);
        self::assertSame(0, $first->events()->where('event', 'reordered')->count());
    }

    public function test_the_new_badge_shows_only_for_documents_published_in_the_last_14_days(): void
    {
        $recent = $this->importFixture('recent', 'Recent Guide', 'Guides', $this->makePdf('r.pdf', 'Recent text'));
        $old = $this->importFixture('old', 'Older Guide', 'Guides', $this->makePdf('o.pdf', 'Older text'));
        $edge = $this->importFixture('edge', 'Edge Guide', 'Guides', $this->makePdf('e.pdf', 'Edge text'));
        ResourceDocument::query()->whereKey($recent->id)->update(['published_at' => now()->subDays(13)]);
        ResourceDocument::query()->whereKey($old->id)->update(['published_at' => now()->subDays(15)]);
        ResourceDocument::query()->whereKey($edge->id)->update(['published_at' => now()->subDays(14)->subMinute()]);
        $this->actingAsCanonicalFixture();

        $page = $this->get('/employee/resources')->assertOk();

        self::assertSame(1, substr_count($page->getContent(), 'class="rl-badge-new"'));
        $page->assertSeeInOrder(['Edge Guide', 'Older Guide', 'Recent Guide', 'class="rl-badge-new"'], false);
    }

    public function test_the_admin_list_shows_status_type_and_the_download_only_hint(): void
    {
        $this->importFixture('manual', 'Flow Manual', 'Manuals', $this->makePdf('manual.pdf', 'Hydrant flow'));
        $this->importFixture('guide', 'Dress Guide', 'Policies', $this->makeDocx('guide.docx', 'Guide'), published: false);
        $this->importFixture('with-copy', 'Copy Guide', 'Policies', $this->makeDocx('copy.docx', 'Copy'), $this->makePdf('copy.pdf', 'Copy text'));
        $this->actingAs($this->viewer());

        $component = Livewire::test(ListResourceDocuments::class)->call('loadTable')
            ->assertSee('Visible')
            ->assertSee('Hidden')
            ->assertSee('DOCX')
            ->assertSee('Download only - add a PDF copy to allow preview');

        self::assertSame(1, substr_count($component->html(), 'Download only - add a PDF copy to allow preview'), 'only the DOCX without a PDF copy carries the hint');
    }

    public function test_the_empty_list_explains_how_to_add_the_first_document(): void
    {
        $this->actingAs($this->manager());

        Livewire::test(ListResourceDocuments::class)->call('loadTable')
            ->assertSee('No documents yet')
            ->assertSee('Choose Add document at the top of this page')
            ->assertActionVisible('addDocument');

        $this->actingAs($this->viewer());
        Livewire::test(ListResourceDocuments::class)->call('loadTable')
            ->assertSee('A resource library manager adds documents here');
    }

    private function uploaded(string $path): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(basename($path), (string) file_get_contents($path));
    }

    /** @param  array<string, mixed>  $data */
    private function addDocument(User $admin, array $data): Testable
    {
        $this->actingAs($admin);

        return Livewire::test(ListResourceDocuments::class)->callAction('addDocument', data: $data);
    }

    /**
     * Drives a header action the way a crafted Livewire request would, bypassing the visibility check the
     * Filament test helper applies. The caller asserts that nothing was saved.
     *
     * @param  array<string, mixed>  $data
     */
    private function runHiddenAction(Testable $component, string $name, array $data): void
    {
        $component->call('mountAction', $name);
        self::assertSame([], $component->instance()->mountedActions, 'the server refuses to mount an action the user may not run');
        $component->setActionData($data);
        $component->call('callMountedAction');
    }

    private function runHiddenTableAction(Testable $component, string $name, ResourceDocument $record): void
    {
        $component->call('mountTableAction', $name, (string) $record->getKey());
        self::assertSame([], $component->instance()->mountedTableActions, 'the server refuses to mount an action the user may not run');
        $component->call('callMountedTableAction');
    }
}
