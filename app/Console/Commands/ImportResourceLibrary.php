<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ResourceDocument;
use App\Services\ResourceLibrary\ResourceFileInput;
use App\Services\ResourceLibrary\ResourceImportException;
use App\Services\ResourceLibrary\ResourceImportResult;
use App\Services\ResourceLibrary\ResourceLibraryService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use JsonException;

/**
 * Loads the tracked resource-library manifest into private storage. The manifest holds metadata and
 * checksums only; the document files are supplied from {dir} and verified before anything is written.
 */
class ImportResourceLibrary extends Command
{
    protected $signature = 'resources:import
        {dir : Folder that holds the files named in the manifest}
        {--manifest=database/data/resources/resource-library.json : Manifest path, absolute or relative to the project root}
        {--dry-run : Verify the files and report what would change without writing anything}';

    protected $description = 'Import library documents from a manifest, verifying every checksum first. Never deletes a document.';

    public function handle(ResourceLibraryService $library): int
    {
        $directory = rtrim((string) $this->argument('dir'), '\\/');
        if ($directory === '' || ! is_dir($directory)) {
            $this->error('Source folder not found: '.$this->argument('dir'));

            return self::FAILURE;
        }

        try {
            $entries = $this->loadManifest($this->manifestPath());
        } catch (ResourceImportException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $problems = $this->preflight($entries, $directory);
        if ($problems !== []) {
            foreach ($problems as $problem) {
                $this->error($problem);
            }
            $this->error('Nothing was imported.');

            return self::FAILURE;
        }

        return $this->option('dry-run')
            ? $this->dryRun($entries)
            : $this->import($library, $entries, $directory);
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     */
    private function import(ResourceLibraryService $library, array $entries, string $directory): int
    {
        $rows = [];
        $failed = false;
        foreach ($entries as $entry) {
            try {
                $result = $library->store(
                    [
                        'slug' => $entry['slug'],
                        'title' => $entry['title'],
                        'category' => $entry['category'],
                        'description' => $entry['description'] ?? null,
                        'sort' => (int) ($entry['sort'] ?? 0),
                        'published' => (bool) ($entry['published'] ?? true),
                    ],
                    new ResourceFileInput($directory.DIRECTORY_SEPARATOR.$entry['original']['file'], $entry['original']['file'], $entry['original']['sha256']),
                    isset($entry['preview'])
                        ? new ResourceFileInput($directory.DIRECTORY_SEPARATOR.$entry['preview']['file'], $entry['preview']['file'], $entry['preview']['sha256'])
                        : null,
                    null,
                    'import',
                    isset($entry['page_count']) ? (int) $entry['page_count'] : null,
                );
            } catch (ResourceImportException $exception) {
                $failed = true;
                $rows[] = [$entry['slug'], 'FAILED', '', $exception->getMessage()];

                continue;
            }
            $rows[] = [$entry['slug'], $result->outcome, (string) ($result->document->page_count ?? '-'), implode(' ', $result->warnings)];
        }

        $this->table(['Document', 'Result', 'Pages', 'Notes'], $rows);

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     */
    private function dryRun(array $entries): int
    {
        $rows = [];
        foreach ($entries as $entry) {
            $existing = ResourceDocument::withTrashed()->where('slug', $entry['slug'])->first();
            $outcome = match (true) {
                $existing === null => 'would create',
                $existing->trashed() => 'blocked: deleted document',
                $existing->original_sha256 !== strtolower($entry['original']['sha256']) => 'would replace',
                isset($entry['preview']) && $existing->preview_sha256 !== strtolower($entry['preview']['sha256']) => 'would replace preview',
                default => ResourceImportResult::UNCHANGED,
            };
            $rows[] = [$entry['slug'], $outcome, 'files verified'];
        }

        $this->table(['Document', 'Dry run', 'Checksums'], $rows);
        $this->info('Dry run only. Nothing was written.');

        return self::SUCCESS;
    }

    /**
     * Every file must exist and match its manifest checksum before any document is touched.
     *
     * @param  list<array<string, mixed>>  $entries
     * @return list<string>
     */
    private function preflight(array $entries, string $directory): array
    {
        $problems = [];
        foreach ($entries as $entry) {
            foreach (array_filter([$entry['original'], $entry['preview'] ?? null]) as $file) {
                $path = $directory.DIRECTORY_SEPARATOR.$file['file'];
                if (! is_file($path)) {
                    $problems[] = '['.$entry['slug'].'] Missing file: '.$path;

                    continue;
                }
                $actual = hash_file('sha256', $path);
                if ($actual !== strtolower($file['sha256'])) {
                    $problems[] = '['.$entry['slug'].'] Checksum mismatch for "'.$file['file'].'": manifest '.strtolower($file['sha256']).', file '.$actual.'.';
                }
            }
        }

        return $problems;
    }

    private function manifestPath(): string
    {
        $path = (string) $this->option('manifest');

        return preg_match('~^(?:[A-Za-z]:[\\\\/]|/)~', $path) === 1 ? $path : base_path($path);
    }

    /**
     * @return list<array<string, mixed>>
     *
     * @throws ResourceImportException
     */
    private function loadManifest(string $path): array
    {
        if (! is_file($path)) {
            throw new ResourceImportException('Manifest not found: '.$path);
        }
        try {
            $manifest = json_decode((string) file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ResourceImportException('Manifest is not valid JSON: '.$exception->getMessage(), 0, $exception);
        }

        $fileName = ['string', 'max:255', 'not_regex:~[\\\\/]~'];
        $validator = Validator::make(is_array($manifest) ? $manifest : [], [
            'version' => ['required', 'integer', 'in:1'],
            'documents' => ['required', 'array', 'min:1'],
            'documents.*.slug' => ['required', 'string', 'max:120', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', 'distinct'],
            'documents.*.title' => ['required', 'string', 'max:200'],
            'documents.*.category' => ['required', 'string', 'max:120'],
            'documents.*.description' => ['nullable', 'string', 'max:2000'],
            'documents.*.sort' => ['nullable', 'integer', 'min:0'],
            'documents.*.published' => ['nullable', 'boolean'],
            'documents.*.page_count' => ['nullable', 'integer', 'min:1'],
            'documents.*.original' => ['required', 'array'],
            'documents.*.original.file' => ['required', ...$fileName],
            'documents.*.original.sha256' => ['required', 'string', 'regex:/^[A-Fa-f0-9]{64}$/'],
            'documents.*.preview' => ['nullable', 'array'],
            'documents.*.preview.file' => ['required_with:documents.*.preview', ...$fileName],
            'documents.*.preview.sha256' => ['required_with:documents.*.preview', 'string', 'regex:/^[A-Fa-f0-9]{64}$/'],
        ]);
        if ($validator->fails()) {
            throw new ResourceImportException('Manifest is invalid: '.implode(' ', $validator->errors()->all()));
        }

        return array_values($manifest['documents']);
    }
}
