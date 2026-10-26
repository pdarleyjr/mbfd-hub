<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\ResourceDocument;
use App\Services\ResourceLibrary\ResourceFileInput;
use App\Services\ResourceLibrary\ResourceImportException;
use App\Services\ResourceLibrary\ResourceLibraryService;
use App\Services\ResourceLibrary\ResourcePdfInspector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Throwable;

/** Private taskbooks use existing personnel and private resource storage; no account or notification writes. */
final class ImportTaskbooks extends Command
{
    protected $signature = 'taskbooks:import
        {dir : Temporary folder containing the PDFs}
        {--manifest= : Absolute path to the verified personnel/file manifest}
        {--dry-run : Verify the entire manifest and every PDF without writing}';

    protected $description = 'Publish personalized 16-page taskbooks to their verified employee recipients.';

    public function handle(ResourceLibraryService $library, ResourcePdfInspector $inspector): int
    {
        try {
            $manifest = (string) $this->option('manifest');
            $directory = realpath((string) $this->argument('dir'));
            if ($directory === false || ! is_dir($directory) || ! is_file($manifest)) {
                throw new ResourceImportException('The source folder and manifest must exist.');
            }
            $data = json_decode((string) file_get_contents($manifest), true, 16, JSON_THROW_ON_ERROR);
            $validator = Validator::make(is_array($data) ? $data : [], [
                'version' => ['required', 'integer', 'in:1'],
                'documents' => ['required', 'array', 'min:1', 'max:500'],
                'documents.*.employee_number' => ['required', 'string', 'max:64', 'distinct'],
                'documents.*.employee_name' => ['required', 'string', 'max:200'],
                'documents.*.slug' => ['required', 'string', 'max:120', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', 'distinct'],
                'documents.*.title' => ['required', 'string', 'max:200'],
                'documents.*.category' => ['required', 'string', 'max:120'],
                'documents.*.description' => ['nullable', 'string', 'max:2000'],
                'documents.*.page_count' => ['required', 'integer', 'in:16'],
                'documents.*.original.file' => ['required', 'string', 'max:255', 'regex:/^[^\\\\\/]+\.pdf$/i', 'distinct'],
                'documents.*.original.sha256' => ['required', 'string', 'regex:/^[a-fA-F0-9]{64}$/', 'distinct'],
            ]);
            if ($validator->fails()) {
                throw new ResourceImportException('Manifest is invalid: '.implode(' ', $validator->errors()->all()));
            }

            // Resolve and inspect the whole batch before any private-storage or database write.
            $prepared = [];
            foreach ($data['documents'] as $entry) {
                $employees = Employee::query()->where('employee_id', $entry['employee_number'])->get(['id', 'employee_id', 'name']);
                if ($employees->count() !== 1) {
                    throw new ResourceImportException('Employee number must resolve exactly once: '.$entry['employee_number']);
                }
                $employee = $employees->sole();
                if ($employee->name !== $entry['employee_name']) {
                    throw new ResourceImportException('Employee name does not match department records: '.$entry['employee_number']);
                }
                if ($employee->user()->where('employee_id', $employee->employee_id)->count() !== 1) {
                    throw new ResourceImportException('Employee has no unique matching canonical account: '.$entry['employee_number']);
                }
                $existing = ResourceDocument::withTrashed()->where('slug', $entry['slug'])->first();
                if ($existing !== null && ($existing->trashed() || $existing->recipient_employee_id !== $employee->id)) {
                    throw new ResourceImportException('Slug is unavailable or belongs to a different recipient: '.$entry['slug']);
                }
                $path = $directory.DIRECTORY_SEPARATOR.$entry['original']['file'];
                if (! is_file($path) || ! is_readable($path)) {
                    throw new ResourceImportException('Missing file: '.$entry['original']['file']);
                }
                if (filesize($path) > ResourceLibraryService::MAX_BYTES) {
                    throw new ResourceImportException('File exceeds the resource-library size limit: '.$entry['original']['file']);
                }
                if (hash_file('sha256', $path) !== strtolower($entry['original']['sha256'])) {
                    throw new ResourceImportException('Checksum mismatch: '.$entry['original']['file']);
                }
                if ($inspector->inspect($path)['pages'] !== 16) {
                    throw new ResourceImportException('Taskbook expected 16 pages: '.$entry['original']['file']);
                }
                $duplicate = ResourceDocument::withTrashed()->where('original_sha256', strtolower($entry['original']['sha256']))
                    ->when($existing !== null, fn ($query) => $query->where('id', '!=', $existing->id))->exists();
                if ($duplicate) {
                    throw new ResourceImportException('File already belongs to another document: '.$entry['slug']);
                }
                $prepared[] = ['entry' => $entry, 'employee' => $employee, 'path' => $path];
            }
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());
            $this->error('Nothing was imported.');

            return self::FAILURE;
        }

        $rows = [];
        $failed = false;
        foreach ($prepared as ['entry' => $entry, 'employee' => $employee, 'path' => $path]) {
            if ($this->option('dry-run')) {
                $rows[] = [$entry['slug'], 'verified; no writes'];

                continue;
            }
            try {
                $result = $library->store(
                    [
                        'slug' => $entry['slug'], 'title' => $entry['title'], 'category' => $entry['category'],
                        'description' => $entry['description'] ?? null, 'recipient_employee_id' => (int) $employee->id,
                    ],
                    new ResourceFileInput($path, $entry['original']['file'], $entry['original']['sha256']),
                    source: 'taskbook-import',
                    expectedPages: 16,
                );
                $rows[] = [$entry['slug'], $result->outcome];
            } catch (ResourceImportException $exception) {
                $failed = true;
                $rows[] = [$entry['slug'], 'FAILED: '.$exception->getMessage()];
            }
        }
        $this->table(['Taskbook', 'Result'], $rows);

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
