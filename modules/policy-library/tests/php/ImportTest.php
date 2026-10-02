<?php

declare(strict_types=1);

namespace Mbfd\PolicyLibrary\Tests;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Mbfd\PolicyLibrary\Jobs\AnalyzeImport;
use Mbfd\PolicyLibrary\Models\Edition;
use Mbfd\PolicyLibrary\Models\Manual;
use Mbfd\PolicyLibrary\Services\ArchiveImportService;
use Mbfd\PolicyLibrary\Services\ImportService;
use Mbfd\PolicyLibrary\Services\ImportSubmissionService;
use Mbfd\PolicyLibrary\Services\PdfService;
use Mbfd\PolicyLibrary\Services\StagingStorage;
use ZipArchive;

final class ImportTest extends TestCase
{
    private function fixture(): array
    {
        if (! is_dir($this->privateRoot)) {
            mkdir($this->privateRoot, 0700, true);
        }
        $hash = hash('sha256', 'test PDF bytes');
        $root = $this->privateRoot.'/manifest';
        mkdir($root.'/assets', 0700, true);
        file_put_contents($root.'/assets/'.$hash.'.pdf', 'test PDF bytes');
        $this->app->instance(PdfService::class, new class extends PdfService
        {
            public function inspect(string $path): array
            {
                return ['page_count' => 2, 'sha256' => hash_file('sha256', $path)];
            }

            public function pageText(string $path): array
            {
                return ['Page one', 'Page two'];
            }
        });
        $document = ['title' => 'Protocol', 'slug' => 'protocol', 'asset_path' => 'assets/'.$hash.'.pdf', 'sha256' => $hash, 'page_count' => 2, 'pages' => [['physical_page' => 51, 'printed_label' => '49'], ['physical_page' => 52, 'printed_label' => '50']]];

        return [['manuals' => [['slug' => 'medical', 'name' => 'Medical Protocols', 'type' => 'medical', 'sections' => [['title' => 'Adult', 'slug' => 'adult', 'children' => [], 'documents' => [$document]], ['title' => 'Other', 'slug' => 'other', 'children' => [], 'documents' => [array_merge($document, ['title' => 'Other Protocol', 'slug' => 'other-protocol'])]]]]]], $root];
    }

    private function zip(array $manifest, string $root): UploadedFile
    {
        $path = $root.'/import.zip';
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path, ZipArchive::CREATE) === true);
        $zip->addFromString('import-manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
        foreach (glob($root.'/assets/*.pdf') as $asset) {
            $zip->addFile($asset, 'assets/'.basename($asset));
        }
        $zip->close();

        return new UploadedFile($path, 'import.zip', 'application/zip', null, true);
    }

    public function test_staged_whole_manual_and_section_replacement_keep_published_content_until_switch(): void
    {
        [$manifest,$root] = $this->fixture();
        $imports = $this->app->make(ImportService::class);
        $edition = $imports->stage($manifest, $root)[0];
        $manual = Manual::query()->firstOrFail();
        $this->assertNull($manual->active_edition_id);
        $imports->publish($edition, null);
        $manual->refresh();
        $section = $edition->nodes()->where('slug', 'adult')->firstOrFail();
        $replacement = $manifest['manuals'][0]['sections'][0];
        $replacement['title'] = 'Updated Adult';
        $replacement['documents'][0]['title'] = 'Updated Protocol';
        $next = $imports->stageSection($section, $replacement, $root, null);
        $this->assertSame($edition->id, $manual->fresh()->active_edition_id);
        $this->assertSame(2, $next->nodes()->where('type', 'document')->count());
        $this->assertSame('Other Protocol', $next->nodes()->where('slug', 'other-protocol')->value('title'));
        $imports->publish($next, null);
        $this->assertSame($next->id, $manual->fresh()->active_edition_id);
        $imports->publish($edition, null);
        $this->assertSame($edition->id, $manual->fresh()->active_edition_id);
    }

    public function test_queued_import_preserves_upload_and_claims_execution_once(): void
    {
        Queue::fake();
        [$manifest,$root] = $this->fixture();
        $file = $this->zip($manifest, $root);
        $batch = $this->app->make(ImportSubmissionService::class)->submit($file, 'package', null);
        Queue::assertPushedOn('policy-library', AnalyzeImport::class);
        $this->assertSame('queued', $batch->state);
        $this->assertSame(0, Edition::query()->count());
        unlink($file->getRealPath()); // Temporary upload cleanup must not affect the queued job.
        $job = new AnalyzeImport($batch->id);
        $job->handle();
        $this->assertSame('ready', $batch->fresh()->state);
        $this->assertCount(1, $batch->fresh()->edition_ids);
        $this->assertNull(Manual::query()->firstOrFail()->active_edition_id);
        $this->assertSame([], glob($this->privateRoot.'/staging/*'));
        $this->assertFileExists($this->privateRoot.'/'.$batch->storage_path);
        $job->handle();
        $this->assertSame(1, Edition::query()->count());
        $this->assertGreaterThan($job->timeout, config('queue.connections.policy-library.retry_after'));
        $this->assertSame(90, config('queue.connections.redis.retry_after'));
    }

    public function test_zip_traversal_is_denied_without_staging_an_edition(): void
    {
        [$manifest,$root] = $this->fixture();
        $file = $this->zip($manifest, $root);
        $zip = new ZipArchive;
        $zip->open($file->getRealPath());
        $zip->addFromString('../outside.pdf', 'test PDF bytes');
        $zip->close();
        try {
            $this->app->make(ArchiveImportService::class)->stage($file->getRealPath(), null);
            $this->fail('Traversal ZIP was accepted.');
        } catch (ValidationException) {
        }
        $this->assertSame(0, Edition::query()->count());
        $this->assertFileDoesNotExist($root.'/outside.pdf');
        $this->assertSame([], glob($this->privateRoot.'/staging/*'));
    }

    public function test_staging_cleanup_refuses_paths_outside_its_private_uuid_directory(): void
    {
        mkdir($this->privateRoot.'/sources', 0700, true);
        file_put_contents($this->privateRoot.'/sources/original.pdf', 'original archive');
        try {
            app(StagingStorage::class)->remove($this->privateRoot.'/sources');
            $this->fail('Cleanup accepted an immutable source directory.');
        } catch (\LogicException) {
        }
        $this->assertSame('original archive', file_get_contents($this->privateRoot.'/sources/original.pdf'));
    }

    public function test_failed_import_cannot_replace_current_manual(): void
    {
        Queue::fake();
        [$manifest,$root] = $this->fixture();
        $imports = $this->app->make(ImportService::class);
        $current = $imports->stage($manifest, $root)[0];
        $imports->publish($current, null);
        $file = $this->zip($manifest, $root);
        $batch = $this->app->make(ImportSubmissionService::class)->submit($file, 'package', null);
        file_put_contents($this->privateRoot.'/'.$batch->storage_path, 'corrupted upload');
        try {
            (new AnalyzeImport($batch->id))->handle();
            $this->fail('Corrupt upload was analyzed.');
        } catch (\RuntimeException) {
        }
        $this->assertSame('failed', $batch->fresh()->state);
        $this->assertSame($current->id, Manual::query()->firstOrFail()->active_edition_id);
    }
}
