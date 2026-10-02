<?php

declare(strict_types=1);

namespace Mbfd\PolicyLibrary\Tests;

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Mbfd\PolicyLibrary\Filament\Pages\ManualBuilder;
use Mbfd\PolicyLibrary\Jobs\AnalyzeImport;
use Mbfd\PolicyLibrary\Models\Manual;
use Mbfd\PolicyLibrary\Models\ManualNode;
use Mbfd\PolicyLibrary\Services\ImportService;
use Mbfd\PolicyLibrary\Services\ImportSubmissionService;
use Mbfd\PolicyLibrary\Services\LibraryManagementService;
use Mbfd\PolicyLibrary\Services\ManualUploadService;
use Mbfd\PolicyLibrary\Services\RevisionService;
use Mbfd\PolicyLibrary\Services\TreeService;
use Tests\TestCase;

final class ManualBuilderTest extends TestCase
{
    use RefreshDatabase;

    private string $privateRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->privateRoot = sys_get_temp_dir().'/mbfd-native-builder-test-'.bin2hex(random_bytes(8));
        mkdir($this->privateRoot, 0700, true);
        config(['policy-library.storage_root' => $this->privateRoot, 'policy-library.scanner_enabled' => false,
            'filesystems.disks.tmp-for-tests' => ['driver' => 'local', 'root' => $this->privateRoot.'/uploads'],
            'policy-library.qpdf_binary' => getenv('TEST_QPDF_BINARY') ?: 'qpdf',
            'policy-library.pdfinfo_binary' => getenv('TEST_PDFINFO_BINARY') ?: 'pdfinfo',
            'policy-library.pdftotext_binary' => getenv('TEST_PDFTOTEXT_BINARY') ?: 'pdftotext',
            'policy-library.python_binary' => getenv('TEST_PYTHON_BINARY') ?: 'python3']);
    }

    protected function tearDown(): void
    {
        if (isset($this->privateRoot)) {
            (new Filesystem)->deleteDirectory($this->privateRoot);
        }
        parent::tearDown();
    }

    public function test_builder_is_the_management_home_and_members_cannot_open_it(): void
    {
        $this->manager();
        $this->withoutExceptionHandling();
        $this->get('https://files.mbfdhub.com/manage')->assertOk()->assertSee('Manual Builder')->assertSee('Add Manual');
        $this->withExceptionHandling();
        $this->actingAs(User::factory()->create(['must_change_password' => false]));
        $this->get('https://files.mbfdhub.com/manage')->assertForbidden();
    }

    public function test_builder_creates_and_retitles_a_real_hierarchy_without_changing_bookmarks(): void
    {
        $this->manager();
        $builder = Livewire::test(ManualBuilder::class)->callAction('addManual', ['name' => 'Training', 'type' => 'other']);
        $manual = Manual::query()->firstOrFail();
        $builder->assertSet('manualId', $manual->id)->callAction('addSection', ['title' => 'Operations']);
        $section = $manual->nodes()->where('title', 'Operations')->firstOrFail();
        $builder->callAction('addSubsection', ['title' => 'Water rescue', 'parent_id' => $section->id]);
        $subsection = $manual->nodes()->where('title', 'Water rescue')->firstOrFail();
        $this->assertSame($section->id, $subsection->parent_id);
        $builder->callAction('addDocument', ['title' => 'Boat operations', 'parent_id' => $subsection->id]);
        $document = $manual->nodes()->where('title', 'Boat operations')->firstOrFail();
        $builder->set('data.title', 'Marine operations')->call('save')->assertHasNoFormErrors();
        $this->assertSame('Marine operations', $document->fresh()->title);
        $this->assertSame('boat-operations', $document->fresh()->slug);
        $builder->call('selectManual', $manual->id)->set('data.name', 'Training Library')->call('save')->assertHasNoFormErrors();
        $this->assertSame('Training Library', $manual->fresh()->name);
        $this->assertSame('training', $manual->fresh()->slug);
    }

    public function test_builder_moves_and_reorders_nodes_and_rejects_descendant_cycles(): void
    {
        $manager = $this->manager();
        $service = app(LibraryManagementService::class);
        $manual = $service->createManual(['name' => 'Operations', 'type' => 'other'], $manager->id);
        $edition = $manual->editions()->firstOrFail();
        $first = $service->createNode($edition, ['title' => 'First section', 'type' => 'section'], $manager->id);
        $second = $service->createNode($edition, ['title' => 'Second section', 'type' => 'section'], $manager->id);
        $child = $service->createNode($edition, ['title' => 'Child section', 'type' => 'section', 'parent_id' => $first->id], $manager->id);
        $builder = Livewire::test(ManualBuilder::class)->call('reorderNode', $second->id, $first->id);
        $this->assertSame([$second->id, $first->id], $edition->nodes()->whereNull('parent_id')->orderBy('sort_order')->pluck('id')->all());
        $builder->call('selectNode', $child->id)->set('data.parent_id', $second->id)->call('save')->assertHasNoFormErrors();
        $this->assertSame($second->id, $child->fresh()->parent_id);
        $builder->call('selectNode', $second->id)->set('data.parent_id', $child->id)->call('save')->assertHasFormErrors(['parent_id']);
        $this->assertNull($second->fresh()->parent_id);
    }

    public function test_archiving_removes_member_navigation_and_preserves_pdf_history(): void
    {
        $manager = $this->manager();
        $service = app(LibraryManagementService::class);
        $manual = $service->createManual(['name' => 'Medical', 'type' => 'medical'], $manager->id);
        $edition = $manual->editions()->firstOrFail();
        $section = $service->createNode($edition, ['title' => 'Adult', 'type' => 'section'], $manager->id);
        $node = $service->createNode($edition, ['title' => 'Assessment', 'type' => 'document', 'parent_id' => $section->id], $manager->id);
        $revision = $node->revisions()->create(['uuid' => (string) Str::uuid(), 'source_filename' => 'assessment.pdf', 'storage_path' => 'revisions/'.str_repeat('a', 64).'.pdf', 'sha256' => str_repeat('a', 64), 'page_count' => 1, 'state' => 'published']);
        $node->update(['current_revision_id' => $revision->id]);
        $edition->update(['state' => 'published']);
        $manual->update(['active_edition_id' => $edition->id]);
        $this->assertSame([$node->id], app(TreeService::class)->tree($manual)['documents']);
        $builder = Livewire::test(ManualBuilder::class)->call('selectNode', $section->id)->callAction('archive');
        $this->assertSame([], app(TreeService::class)->tree($manual)['documents']);
        $this->assertSame($revision->id, $node->fresh()->current_revision_id);
        $this->assertSame(1, $node->revisions()->count());
        $builder->call('selectManual', $manual->id)->callAction('archive');
        $this->assertFalse($manual->fresh()->is_active);
        $this->assertSame($edition->id, $manual->fresh()->active_edition_id);
        $this->assertFalse($service->canDeleteManual($manual->fresh()));
        try {
            $service->deleteManual($manual->fresh(), $manager->id);
            $this->fail('A manual with published history was deleted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('remove', $exception->errors());
        }
        $this->assertNotNull(ManualNode::query()->find($node->id));
    }

    public function test_unused_draft_can_be_deleted_and_manual_order_is_persisted(): void
    {
        $manager = $this->manager();
        $service = app(LibraryManagementService::class);
        $first = $service->createManual(['name' => 'First', 'type' => 'other'], $manager->id);
        $second = $service->createManual(['name' => 'Second', 'type' => 'other'], $manager->id);
        $builder = Livewire::test(ManualBuilder::class)->call('reorderManual', $second->id, $first->id);
        $this->assertSame([$second->id, $first->id], Manual::query()->orderBy('sort_order')->pluck('id')->all());
        $builder->call('selectManual', $first->id)->callAction('addSection', ['title' => 'Unused section'])->callAction('deleteDraft');
        $this->assertSame(0, $first->nodes()->count());
        $builder->callAction('deleteDraft');
        $this->assertNull(Manual::query()->find($first->id));
        $this->assertSame(0, $first->editions()->count());
        $this->assertNotNull(Manual::query()->find($second->id));
    }

    public function test_pdf_manual_and_section_imports_prepare_drafts_and_preserve_other_sections(): void
    {
        $manager = $this->manager();
        $service = app(LibraryManagementService::class);
        $manual = $service->createManual(['name' => 'Training', 'type' => 'other'], $manager->id);
        $pdf = $this->pdf('training', ['Original training content']);
        $editions = app(ManualUploadService::class)->stagePdf($pdf, 'Training.pdf', $manager->id, $manual);
        $this->assertCount(1, $editions);
        $this->assertNull($manual->fresh()->active_edition_id);
        app(ImportService::class)->publish($editions[0], $manager->id);
        $section = $editions[0]->nodes()->where('type', 'section')->firstOrFail();
        $bookmark = $section->children()->where('type', 'document')->value('slug');
        $section->update(['is_active' => false]);
        $other = $service->createNode($editions[0], ['title' => 'Other section', 'type' => 'section'], $manager->id);
        $otherDocument = $service->createNode($editions[0], ['title' => 'Other PDF', 'type' => 'document', 'parent_id' => $other->id], $manager->id);
        $original = app(RevisionService::class)->createDraft($otherDocument, $pdf, 'Other.pdf', $manager->id);
        app(RevisionService::class)->publish($original, $manager->id);
        Queue::fake();
        $replacement = $this->pdf('updated', ['Updated training content']);
        $batch = app(ImportSubmissionService::class)->submit(new UploadedFile($replacement, 'Updated.pdf', 'application/pdf', null, true), 'pdf', $manager->id, $section->id);
        Queue::assertPushed(AnalyzeImport::class);
        (new AnalyzeImport($batch->id))->handle();
        $this->assertSame('ready', $batch->fresh()->state);
        $this->assertSame($manual->id, $batch->fresh()->manual_id);
        $draft = $manual->editions()->findOrFail($batch->fresh()->edition_ids[0]);
        $this->assertSame($editions[0]->id, $manual->fresh()->active_edition_id);
        $this->assertSame($original->sha256, $draft->nodes()->where('slug', $otherDocument->slug)->firstOrFail()->currentRevision->sha256);
        $this->assertSame($section->slug, $draft->nodes()->where('type', 'section')->where('title', $section->title)->firstOrFail()->slug);
        $this->assertFalse($draft->nodes()->where('slug', $section->slug)->firstOrFail()->is_active);
        $this->assertSame($bookmark, $draft->nodes()->where('parent_id', $draft->nodes()->where('slug', $section->slug)->value('id'))->where('type', 'document')->value('slug'));
        app(ImportService::class)->publish($draft, $manager->id);
        $this->assertSame($draft->id, $manual->fresh()->active_edition_id);
        $this->assertSame('archived', $editions[0]->fresh()->state);
        $this->assertSame([$draft->nodes()->where('slug', $otherDocument->slug)->value('id')], app(TreeService::class)->tree($manual->fresh())['documents']);
    }

    public function test_sog_section_pdf_is_mapped_by_existing_policy_header_importer(): void
    {
        $manager = $this->manager();
        $manual = app(LibraryManagementService::class)->createManual(['name' => 'Guidelines', 'type' => 'sog'], $manager->id);
        $path = $this->pdf('section', ['DIVISION: OPERATIONS', 'POLICY NUMBER: 100.01', 'TITLE: Response Standards', 'ISSUE DATE: 10/02/2026', 'Respond using the correct apparatus.']);
        $editions = app(ManualUploadService::class)->stageSog($path, 'Uploaded section.pdf', $manager->id, $manual);
        $this->assertCount(1, $editions);
        $this->assertSame($manual->id, $editions[0]->manual_id);
        $document = $editions[0]->nodes()->where('type', 'document')->with('currentRevision.pages')->firstOrFail();
        $this->assertStringContainsString('100.01', $document->title);
        $this->assertStringContainsString('Response Standards', $document->title);
        $this->assertSame(1, $document->currentRevision->page_count);
        $this->assertSame(1, $document->currentRevision->pages->first()->physical_page);
        $this->assertStringContainsString('correct apparatus', $document->currentRevision->pages->first()->text);
        $this->assertNull($manual->fresh()->active_edition_id);
    }

    public function test_revision_publish_rollback_and_page_replacement_work_through_builder_actions(): void
    {
        $manager = $this->manager();
        $service = app(LibraryManagementService::class);
        $manual = $service->createManual(['name' => 'Revision workflows', 'type' => 'other'], $manager->id);
        $edition = $manual->editions()->firstOrFail();
        $node = $service->createNode($edition, ['title' => 'Response', 'type' => 'document'], $manager->id);
        $original = app(RevisionService::class)->createDraft($node, $this->pdf('first', ['Original response instructions']), 'First.pdf', $manager->id);
        $builder = Livewire::test(ManualBuilder::class)->call('selectNode', $node->id)->callAction('publishRevision', arguments: ['revision' => $original->id]);
        $this->assertSame($original->id, $node->fresh()->current_revision_id);
        $builder->callAction('publishEdition');
        $this->assertSame($edition->id, $manual->fresh()->active_edition_id);
        $replacement = $this->pdf('replacement', ['Replacement response instructions']);
        $builder->call('selectNode', $node->id)->callAction('replacePages', ['start' => 1, 'end' => 1, 'pdf' => UploadedFile::fake()->createWithContent('Replacement.pdf', file_get_contents($replacement))]);
        $this->assertSame([], $builder->instance()->getErrorBag()->toArray());
        $draft = $node->revisions()->latest('id')->firstOrFail();
        $this->assertNotSame($original->id, $draft->id);
        $this->assertSame($original->id, $node->fresh()->current_revision_id);
        $builder->callAction('publishRevision', arguments: ['revision' => $draft->id]);
        $this->assertSame($draft->id, $node->fresh()->current_revision_id);
        $builder->callAction('publishRevision', arguments: ['revision' => $original->id]);
        $this->assertSame($original->id, $node->fresh()->current_revision_id);
        $this->assertSame(2, $node->revisions()->count());
    }

    private function pdf(string $name, array $lines): string
    {
        $content = 'BT /F1 12 Tf 20 360 Td ';
        foreach ($lines as $line) {
            $content .= '('.str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $line).') Tj 0 -20 Td ';
        }
        $content .= 'ET';
        $objects = ['<</Type /Catalog /Pages 2 0 R>>', '<</Type /Pages /Count 1 /Kids [3 0 R]>>', '<</Type /Page /Parent 2 0 R /MediaBox [0 0 600 800] /Resources <</Font <</F1 5 0 R>>>> /Contents 4 0 R>>', '<</Length '.strlen($content).">>\nstream\n".$content."\nendstream", '<</Type /Font /Subtype /Type1 /BaseFont /Helvetica>>'];
        $bytes = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $index => $object) {
            $offsets[] = strlen($bytes);
            $bytes .= ($index + 1)." 0 obj\n".$object."\nendobj\n";
        }
        $xref = strlen($bytes);
        $bytes .= "xref\n0 6\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $bytes .= sprintf("%010d 00000 n \n", $offset);
        }
        $bytes .= "trailer <</Size 6 /Root 1 0 R>>\nstartxref\n".$xref."\n%%EOF\n";
        $path = $this->privateRoot.'/'.$name.'.pdf';
        file_put_contents($path, $bytes);

        return $path;
    }

    private function manager(): User
    {
        $manager = User::factory()->create(['name' => 'Builder Test Manager', 'must_change_password' => false]);
        $manager->givePermissionTo('files.manage');
        $this->actingAs($manager);
        Filament::setCurrentPanel(Filament::getPanel('policy-library'));

        return $manager;
    }
}
