<?php

declare(strict_types=1);

namespace Mbfd\PolicyLibrary\Tests;

use Mbfd\PolicyLibrary\Models\Manual;
use Mbfd\PolicyLibrary\Services\ImportService;
use Mbfd\PolicyLibrary\Services\PdfService;
use Mbfd\PolicyLibrary\Services\RevisionService;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

final class PdfTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $qpdf = getenv('TEST_QPDF_BINARY') ?: (new ExecutableFinder)->find('qpdf') ?: __DIR__.'/../../var/qpdf/qpdf-12.4.2-msvc64/bin/qpdf.exe';
        $pdfinfo = getenv('TEST_PDFINFO_BINARY') ?: 'pdfinfo';
        $pdftotext = getenv('TEST_PDFTOTEXT_BINARY') ?: 'pdftotext';
        $this->assertFileExists($qpdf, 'qpdf is required. Set TEST_QPDF_BINARY to its executable path.');
        config(['policy-library.qpdf_binary' => $qpdf, 'policy-library.pdfinfo_binary' => $pdfinfo, 'policy-library.pdftotext_binary' => $pdftotext]);
        mkdir($this->privateRoot, 0700, true);
    }

    private function pdf(string $name, int $pages): string
    {
        $font = 3 + $pages * 2;
        $kids = implode(' ', array_map(fn ($index) => ($index + 3).' 0 R', range(0, $pages - 1)));
        $objects = [1 => '<</Type /Catalog /Pages 2 0 R>>', 2 => '<</Type /Pages /Count '.$pages.' /Kids ['.$kids.']>>'];
        for ($index = 0; $index < $pages; $index++) {
            $objects[3 + $index] = '<</Type /Page /Parent 2 0 R /MediaBox [0 0 300 400] /Resources <</Font <</F1 '.$font.' 0 R /F2 '.($font + 1).' 0 R>>>> /Contents '.(3 + $pages + $index).' 0 R>>';
            $content = 'BT /F1 12 Tf 20 200 Td ('.$name.' page '.($index + 1).') Tj ET';
            $objects[3 + $pages + $index] = '<</Length '.strlen($content).">>\nstream\n".$content."\nendstream";
        }
        $objects[$font] = '<</Type /Font /Subtype /Type1 /BaseFont /Helvetica>>';
        $objects[$font + 1] = '<</Type /Font /Subtype /Type1 /BaseFont /Courier>>';
        ksort($objects);
        $bytes = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $number => $object) {
            $offsets[$number] = strlen($bytes);
            $bytes .= $number." 0 obj\n".$object."\nendobj\n";
        }
        $xref = strlen($bytes);
        $bytes .= 'xref'."\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $bytes .= sprintf("%010d 00000 n \n", $offset);
        }
        $bytes .= 'trailer <</Size '.(count($objects) + 1).' /Root 1 0 R>>'."\nstartxref\n".$xref."\n%%EOF\n";
        $path = $this->privateRoot.'/'.$name.'.pdf';
        file_put_contents($path, $bytes);

        return $path;
    }

    public function test_lossless_page_range_replacement_builds_new_valid_revision(): void
    {
        $manual = Manual::query()->create(['name' => 'Medical Protocols', 'slug' => 'medical', 'type' => 'medical']);
        $edition = $manual->editions()->create(['label' => 'Test edition']);
        $node = $edition->nodes()->create(['manual_id' => $manual->id, 'title' => 'Protocol', 'slug' => 'protocol', 'type' => 'document']);
        $service = $this->app->make(RevisionService::class);
        $source = $this->pdf('original', 3);
        $incoming = $this->pdf('replacement', 2);
        $originalHash = hash_file('sha256', $source);
        $old = $service->createDraft($node, $source, 'original.pdf', null);
        $service->publish($old, null);
        $new = $service->replaceRange($old, 2, 2, $incoming, 'replacement.pdf', null);
        $this->assertSame(4, $new->page_count);
        $this->assertSame(3, $old->page_count);
        $this->assertSame($old->id, $node->fresh()->current_revision_id);
        $this->assertSame($originalHash, hash_file('sha256', $source));
        $texts = $this->app->make(PdfService::class)->pageText($service->path($new->storage_path));
        $this->assertStringContainsString('original page 1', $texts[0]);
        $this->assertStringContainsString('replacement page 1', $texts[1]);
        $this->assertStringContainsString('replacement page 2', $texts[2]);
        $this->assertStringContainsString('original page 3', $texts[3]);
        $this->assertStringContainsString('/Type /ObjStm', file_get_contents($service->path($new->storage_path)), 'Serving PDFs must retain object stream compression.');
        $decoded = new Process([config('policy-library.qpdf_binary'), '--qdf', '--object-streams=disable', $service->path($new->storage_path), '-']);
        $decoded->mustRun();
        $this->assertStringContainsString('/F2', $decoded->getOutput(), 'Untouched pages must retain their complete resource dictionaries.');
        foreach ([$source => 'original-render', $service->path($new->storage_path) => 'replacement-render'] as $path => $prefix) {
            $process = new Process(['pdftoppm', '-f', '1', '-singlefile', '-png', $path, $this->privateRoot.'/'.$prefix]);
            $process->mustRun();
        }
        $this->assertSame(hash_file('sha256', $this->privateRoot.'/original-render.png'), hash_file('sha256', $this->privateRoot.'/replacement-render.png'), 'An untouched page must retain exact raster pixels after replacement.');
    }

    public function test_private_pdf_range_delivery_and_draft_preview_authorization(): void
    {
        $manual = Manual::query()->create(['name' => 'Medical Protocols', 'slug' => 'medical', 'type' => 'medical']);
        $edition = $manual->editions()->create(['label' => 'Test edition']);
        $node = $edition->nodes()->create(['manual_id' => $manual->id, 'title' => 'Protocol', 'slug' => 'protocol', 'type' => 'document']);
        $revision = $this->app->make(RevisionService::class)->createDraft($node, $this->pdf('range', 2), 'range.pdf', null);
        $node->update(['current_revision_id' => $revision->id]);
        $this->app->make(ImportService::class)->publish($edition, null);
        $member = $this->user();
        $this->actingAs($member)->withSession(['policy-library.access' => $this->accessGrant($member)]);
        $response = $this->withHeader('Range', 'bytes=0-9')->get('https://files.mbfdhub.com/assets/'.$revision->uuid);
        $response->assertStatus(206)->assertHeader('Accept-Ranges', 'bytes')->assertHeader('Content-Range', 'bytes 0-9/'.filesize($this->app->make(RevisionService::class)->path($revision->storage_path)));
        $response->assertHeader('Cloudflare-CDN-Cache-Control', 'no-store')->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
        $this->get('https://files.mbfdhub.com/manage/revisions/'.$revision->uuid.'/preview')->assertForbidden();
        $member->givePermissionTo('files.manage');
        $this->withHeaders(['Range' => ''])->withSession(['policy-library.access' => null])->get('https://files.mbfdhub.com/manage/revisions/'.$revision->uuid.'/preview')->assertOk();
    }
}
