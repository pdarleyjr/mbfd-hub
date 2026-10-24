<?php

declare(strict_types=1);

namespace Tests\Feature\ResourceLibrary;

use App\Models\ResourceDocument;
use App\Models\ResourceDocumentEvent;
use App\Services\ResourceLibrary\ResourceFileInput;
use App\Services\ResourceLibrary\ResourceImportException;
use App\Services\ResourceLibrary\ResourceLibraryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use LogicException;
use Tests\TestCase;

final class ResourceLibraryImportTest extends TestCase
{
    use BuildsResourceLibraryFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpResourceLibrary();
    }

    protected function tearDown(): void
    {
        $this->tearDownResourceLibrary();
        parent::tearDown();
    }

    public function test_dry_run_verifies_files_and_writes_nothing(): void
    {
        $pdf = $this->makePdf('manual.pdf', 'Hydrant flow standard');
        $manifest = $this->writeManifest([$this->manifestEntry('flow-manual', 'Flow Manual', 'Manuals', $pdf)]);

        $this->importCommand($manifest, ['--dry-run' => true])
            ->expectsOutputToContain('would create')
            ->assertSuccessful();

        self::assertSame(0, ResourceDocument::withTrashed()->count());
        self::assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_import_stores_a_pdf_once_with_pages_text_and_history(): void
    {
        $pdf = $this->makePdf('manual.pdf', 'Hydrant flow standard', 2);
        $manifest = $this->writeManifest([$this->manifestEntry('flow-manual', 'Flow Manual', 'Manuals', $pdf, extra: ['sort' => 7])]);

        $this->importCommand($manifest)->assertSuccessful();

        $document = ResourceDocument::query()->where('slug', 'flow-manual')->firstOrFail();
        self::assertSame('resource-library/'.$document->uuid.'/original.pdf', $document->original_path);
        self::assertNull($document->preview_path);
        self::assertSame('application/pdf', $document->original_mime);
        self::assertSame(hash_file('sha256', $pdf), $document->original_sha256);
        self::assertSame(filesize($pdf), $document->original_size);
        self::assertGreaterThanOrEqual(2, $document->page_count);
        self::assertTrue($document->published);
        self::assertNotNull($document->published_at);
        self::assertSame(7, $document->sort);
        self::assertSame('manual.pdf', $document->original_filename);

        $disk = Storage::disk('local');
        self::assertSame(file_get_contents($pdf), $disk->get($document->original_path));
        self::assertSame([$document->original_path], $disk->allFiles(), 'exactly one stored copy');

        $text = (string) ResourceDocument::query()->whereKey($document->id)->value('search_text');
        self::assertStringContainsString('Hydrant flow standard', $text);

        $event = $document->events()->sole();
        self::assertSame('imported', $event->event);
        self::assertSame($document->original_sha256, $event->metadata['sha256']);
        self::assertSame('import', $event->metadata['source']);
    }

    public function test_a_docx_original_gets_a_pdf_preview_and_searchable_text(): void
    {
        $docx = $this->makeDocx('guide.docx', 'Uniform guide');
        $preview = $this->makePdf('guide.pdf', 'Dress uniform badge placement', 3);
        $manifest = $this->writeManifest([$this->manifestEntry('guide', 'Guide', 'Policies', $docx, $preview)]);

        $this->importCommand($manifest)->assertSuccessful();

        $document = ResourceDocument::query()->where('slug', 'guide')->firstOrFail();
        self::assertSame('resource-library/'.$document->uuid.'/original.docx', $document->original_path);
        self::assertSame('resource-library/'.$document->uuid.'/preview.pdf', $document->preview_path);
        self::assertSame('application/vnd.openxmlformats-officedocument.wordprocessingml.document', $document->original_mime);
        self::assertSame(hash_file('sha256', $preview), $document->preview_sha256);
        self::assertSame($document->preview_path, $document->previewDiskPath());
        self::assertGreaterThanOrEqual(3, $document->page_count);
        self::assertStringContainsString('badge placement', (string) ResourceDocument::query()->whereKey($document->id)->value('search_text'));
        self::assertEqualsCanonicalizing([$document->original_path, $document->preview_path], Storage::disk('local')->allFiles());
        self::assertSame(file_get_contents($docx), Storage::disk('local')->get($document->original_path));
    }

    public function test_rerunning_an_import_is_a_no_op(): void
    {
        $pdf = $this->makePdf('manual.pdf', 'Hydrant flow standard');
        $docx = $this->makeDocx('guide.docx', 'Uniform guide');
        $preview = $this->makePdf('guide.pdf', 'Dress uniform');
        $manifest = $this->writeManifest([
            $this->manifestEntry('flow-manual', 'Flow Manual', 'Manuals', $pdf),
            $this->manifestEntry('guide', 'Guide', 'Policies', $docx, $preview),
        ]);

        $this->importCommand($manifest)->assertSuccessful();
        $before = ResourceDocument::query()->orderBy('slug')->get(['id', 'uuid', 'updated_at'])->toArray();
        $files = Storage::disk('local')->allFiles();
        sort($files);

        $this->importCommand($manifest)->expectsOutputToContain('unchanged')->assertSuccessful();

        self::assertSame(2, ResourceDocument::count());
        self::assertSame($before, ResourceDocument::query()->orderBy('slug')->get(['id', 'uuid', 'updated_at'])->toArray());
        $after = Storage::disk('local')->allFiles();
        sort($after);
        self::assertSame($files, $after);
        self::assertSame(2, ResourceDocumentEvent::count(), 'no second imported event');
    }

    public function test_a_checksum_mismatch_fails_the_whole_import_before_anything_is_written(): void
    {
        $good = $this->makePdf('good.pdf', 'Good file');
        $bad = $this->makePdf('bad.pdf', 'Tampered file');
        $manifest = $this->writeManifest([
            $this->manifestEntry('good', 'Good', 'Manuals', $good),
            array_replace_recursive($this->manifestEntry('bad', 'Bad', 'Manuals', $bad), ['original' => ['sha256' => str_repeat('a', 64)]]),
        ]);

        $this->importCommand($manifest)
            ->expectsOutputToContain('Checksum mismatch for "bad.pdf"')
            ->assertFailed();

        self::assertSame(0, ResourceDocument::withTrashed()->count());
        self::assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_a_missing_file_fails_clearly(): void
    {
        $pdf = $this->makePdf('gone.pdf', 'Soon missing');
        $manifest = $this->writeManifest([$this->manifestEntry('gone', 'Gone', 'Manuals', $pdf)]);
        unlink($pdf);

        $this->importCommand($manifest)->expectsOutputToContain('Missing file')->assertFailed();

        self::assertSame(0, ResourceDocument::count());
    }

    public function test_a_wrong_page_count_in_the_manifest_is_refused(): void
    {
        $pdf = $this->makePdf('manual.pdf', 'Hydrant flow standard');
        $manifest = $this->writeManifest([$this->manifestEntry('flow-manual', 'Flow Manual', 'Manuals', $pdf, extra: ['page_count' => 999])]);

        $this->importCommand($manifest)->expectsOutputToContain('should have 999 pages')->assertFailed();

        self::assertSame(0, ResourceDocument::count());
        self::assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_an_invalid_manifest_is_rejected(): void
    {
        $pdf = $this->makePdf('manual.pdf', 'Hydrant flow standard');
        $manifest = $this->writeManifest([$this->manifestEntry('Not A Slug!', 'Flow Manual', 'Manuals', $pdf)]);

        $this->importCommand($manifest)->expectsOutputToContain('Manifest is invalid')->assertFailed();

        self::assertSame(0, ResourceDocument::count());
    }

    public function test_a_changed_file_for_the_same_slug_is_a_replacement_that_keeps_history(): void
    {
        $original = $this->makePdf('manual.pdf', 'Original hydrant text');
        $manifest = $this->writeManifest([$this->manifestEntry('flow-manual', 'Flow Manual', 'Manuals', $original)]);
        $this->importCommand($manifest)->assertSuccessful();
        $first = ResourceDocument::query()->where('slug', 'flow-manual')->firstOrFail();
        $firstSha = $first->original_sha256;

        $replacement = $this->makePdf('manual.pdf', 'Revised pump panel text');
        $manifest = $this->writeManifest([$this->manifestEntry('flow-manual', 'Flow Manual', 'Manuals', $replacement)]);
        $this->importCommand($manifest)->expectsOutputToContain('replaced')->assertSuccessful();

        $document = ResourceDocument::query()->where('slug', 'flow-manual')->firstOrFail();
        self::assertSame(1, ResourceDocument::count());
        self::assertSame($first->uuid, $document->uuid, 'same document, same link');
        self::assertNotSame($firstSha, $document->original_sha256);
        self::assertSame(hash_file('sha256', $replacement), $document->original_sha256);
        self::assertSame(file_get_contents($replacement), Storage::disk('local')->get($document->original_path));
        self::assertSame([$document->original_path], Storage::disk('local')->allFiles(), 'one copy, no staged leftovers');

        $text = (string) ResourceDocument::query()->whereKey($document->id)->value('search_text');
        self::assertStringContainsString('pump panel', $text);
        self::assertStringNotContainsString('Original hydrant', $text);

        $events = $document->events()->orderBy('id')->get();
        self::assertSame(['imported', 'replaced'], $events->pluck('event')->all());
        self::assertSame($firstSha, $events[1]->metadata['previous']['original_sha256']);
        self::assertSame($document->original_sha256, $events[1]->metadata['sha256']);
    }

    public function test_replacing_a_docx_without_a_new_preview_drops_the_stale_preview_and_warns(): void
    {
        $preview = $this->makePdf('guide.pdf', 'Old guide text');
        $document = $this->importFixture('guide', 'Guide', 'Policies', $this->makeDocx('guide.docx', 'Old guide'), $preview);
        $oldPreviewPath = $document->preview_path;

        $newDocx = $this->makeDocx('guide-v2.docx', 'New guide');
        $result = app(ResourceLibraryService::class)->store(['slug' => 'guide', 'title' => 'Guide', 'category' => 'Policies'], new ResourceFileInput($newDocx, 'guide-v2.docx'));

        self::assertSame('replaced', $result->outcome);
        self::assertNull($result->document->preview_path);
        self::assertNull($result->document->preview_sha256);
        self::assertNotEmpty($result->warnings);
        self::assertFalse(Storage::disk('local')->exists($oldPreviewPath));
        self::assertSame([$result->document->original_path], Storage::disk('local')->allFiles());
        self::assertNull(ResourceDocument::query()->whereKey($document->id)->value('search_text'));
    }

    public function test_replacing_only_the_preview_keeps_the_original_untouched(): void
    {
        $docx = $this->makeDocx('guide.docx', 'Guide');
        $document = $this->importFixture('guide', 'Guide', 'Policies', $docx, $this->makePdf('guide.pdf', 'First export'));
        $originalSha = $document->original_sha256;

        $newPreview = $this->makePdf('guide-b.pdf', 'Second export with lanyards');
        $result = app(ResourceLibraryService::class)->store(
            ['slug' => 'guide', 'title' => 'Guide', 'category' => 'Policies'],
            new ResourceFileInput($docx, 'guide.docx'),
            new ResourceFileInput($newPreview, 'guide-b.pdf'),
        );

        self::assertSame('replaced', $result->outcome);
        self::assertSame($originalSha, $result->document->original_sha256);
        self::assertSame(hash_file('sha256', $newPreview), $result->document->preview_sha256);
        self::assertStringContainsString('lanyards', (string) ResourceDocument::query()->whereKey($document->id)->value('search_text'));
    }

    public function test_the_same_bytes_cannot_back_two_documents(): void
    {
        $pdf = $this->makePdf('manual.pdf', 'Hydrant flow standard');
        $this->importFixture('first', 'First', 'Manuals', $pdf);

        $this->expectException(ResourceImportException::class);
        $this->expectExceptionMessage('already in the library as "First"');
        $this->importFixture('second', 'Second', 'Manuals', $pdf);
    }

    public function test_files_that_are_not_what_their_extension_claims_are_refused(): void
    {
        $fake = $this->fixtureDir().DIRECTORY_SEPARATOR.'fake.pdf';
        file_put_contents($fake, 'this is plain text, not a pdf');

        try {
            $this->importFixture('fake', 'Fake', 'Manuals', $fake);
            self::fail('A non-PDF named .pdf must be refused.');
        } catch (ResourceImportException $exception) {
            self::assertStringContainsString('does not look like a real .pdf file', $exception->getMessage());
        }
        self::assertSame(0, ResourceDocument::count());
        self::assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_import_never_deletes_documents_missing_from_the_manifest_and_respects_unpublished(): void
    {
        $kept = $this->makePdf('kept.pdf', 'Kept document');
        $this->importFixture('kept', 'Kept', 'Manuals', $kept);
        $other = $this->makePdf('other.pdf', 'Other document');
        $manifest = $this->writeManifest([$this->manifestEntry('other', 'Other', 'Manuals', $other, extra: ['published' => false])]);

        $this->importCommand($manifest)->assertSuccessful();

        self::assertSame(2, ResourceDocument::count());
        self::assertFalse(ResourceDocument::query()->where('slug', 'other')->value('published'));
        self::assertNull(ResourceDocument::query()->where('slug', 'other')->value('published_at'));
    }

    public function test_events_are_append_only(): void
    {
        $document = $this->importFixture('kept', 'Kept', 'Manuals', $this->makePdf('kept.pdf', 'Kept document'));
        $event = $document->events()->sole();

        $this->expectException(LogicException::class);
        $event->update(['event' => 'tampered']);
    }

    public function test_the_tracked_manifest_is_valid_and_lists_the_five_launch_documents(): void
    {
        $manifest = json_decode((string) file_get_contents(base_path('database/data/resources/resource-library.json')), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(1, $manifest['version']);
        self::assertSame(
            ['uniform-guidelines', 'driver-engineer-manual-2026', 'back-to-basics-officers-task-book', 'puc-manual-2019', 'pierce-mid-mount-manual'],
            array_column($manifest['documents'], 'slug'),
        );
        foreach ($manifest['documents'] as $entry) {
            self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $entry['original']['sha256']);
            self::assertArrayNotHasKey('bytes', $entry);
        }
        self::assertStringStartsWith('59a1b16fbdbdeb49', $manifest['documents'][0]['original']['sha256']);
        self::assertSame('2026 Uniform Guideline FINAL.pdf', $manifest['documents'][0]['preview']['file']);
        self::assertSame([35, 244, 12, 66, 128], array_column($manifest['documents'], 'page_count'));
    }

    /** @param  array<string, mixed>  $options */
    private function importCommand(string $manifest, array $options = [])
    {
        return $this->artisan('resources:import', ['dir' => $this->fixtureDir(), '--manifest' => $manifest] + $options);
    }
}
