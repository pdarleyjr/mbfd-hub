<?php

declare(strict_types=1);

namespace Tests\Feature\ResourceLibrary;

use App\Models\ResourceDocument;
use App\Services\ResourceLibrary\ResourceFileInput;
use App\Services\ResourceLibrary\ResourceLibraryService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ZipArchive;

/** Generated, throwaway library files: a tiny PDF made with DomPDF and a minimal DOCX zip. Nothing is committed. */
trait BuildsResourceLibraryFixtures
{
    private ?string $fixtureDirectory = null;

    protected function setUpResourceLibrary(): void
    {
        Storage::fake('local');
        config(['filesystems.private' => 'local']);
    }

    protected function tearDownResourceLibrary(): void
    {
        if ($this->fixtureDirectory !== null) {
            File::deleteDirectory($this->fixtureDirectory);
            $this->fixtureDirectory = null;
        }
    }

    protected function fixtureDir(): string
    {
        if ($this->fixtureDirectory === null) {
            $this->fixtureDirectory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'resource-library-'.Str::random(12);
            mkdir($this->fixtureDirectory, 0777, true);
        }

        return $this->fixtureDirectory;
    }

    /** @return string absolute path of the generated PDF */
    protected function makePdf(string $name, string $text, int $pages = 1): string
    {
        $html = '';
        for ($page = 1; $page <= $pages; $page++) {
            $html .= '<div style="page-break-after: always"><h1>'.e($text).'</h1><p>Page '.$page.' of the generated fixture.</p></div>';
        }
        $path = $this->fixtureDir().DIRECTORY_SEPARATOR.$name;
        file_put_contents($path, Pdf::loadHTML($html)->output());

        return $path;
    }

    /** @return string absolute path of a minimal, valid DOCX container */
    protected function makeDocx(string $name, string $text): string
    {
        $path = $this->fixtureDir().DIRECTORY_SEPARATOR.$name;
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>');
        $zip->addFromString('word/document.xml', '<?xml version="1.0" encoding="UTF-8"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>'.e($text).'</w:t></w:r></w:p></w:body></w:document>');
        $zip->close();

        return $path;
    }

    /**
     * Manifest entry for files already in the fixture directory; checksums are computed from the real bytes.
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    protected function manifestEntry(string $slug, string $title, string $category, string $originalPath, ?string $previewPath = null, array $extra = []): array
    {
        return array_filter(array_merge([
            'slug' => $slug,
            'title' => $title,
            'category' => $category,
            'description' => 'Fixture '.$title,
            'sort' => 10,
            'original' => ['file' => basename($originalPath), 'sha256' => hash_file('sha256', $originalPath)],
            'preview' => $previewPath === null ? null : ['file' => basename($previewPath), 'sha256' => hash_file('sha256', $previewPath)],
        ], $extra), fn (mixed $value): bool => $value !== null);
    }

    /** @param  list<array<string, mixed>>  $documents */
    protected function writeManifest(array $documents): string
    {
        $path = $this->fixtureDir().DIRECTORY_SEPARATOR.'manifest-'.Str::random(6).'.json';
        file_put_contents($path, json_encode(['version' => 1, 'documents' => $documents], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return $path;
    }

    /** Imports a generated document directly through the service. */
    protected function importFixture(string $slug, string $title, string $category, string $originalPath, ?string $previewPath = null, bool $published = true, string $description = ''): ResourceDocument
    {
        return app(ResourceLibraryService::class)->store(
            ['slug' => $slug, 'title' => $title, 'category' => $category, 'description' => $description, 'sort' => 10, 'published' => $published],
            new ResourceFileInput($originalPath, basename($originalPath)),
            $previewPath === null ? null : new ResourceFileInput($previewPath, basename($previewPath)),
        )->document;
    }
}
