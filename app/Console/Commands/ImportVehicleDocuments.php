<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\UnitMasterVehicle;
use App\Models\VehicleDocument;
use App\Services\Fleet\VehicleDocumentLibrary;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/** Local analysis supplies evidence; this bounded writer never edits source files or vehicles. */
final class ImportVehicleDocuments extends Command
{
    protected $signature = 'fleet:import-vehicle-documents {manifest : Private JSONL inventory} {source-root : Read-only source collection} {--batch= : Stable import batch name} {--apply : Store originals and relationships} {--limit=0 : Maximum non-excluded source rows}';

    protected $description = 'Validate or resumably import locally analyzed historical vehicle documents';

    public function handle(VehicleDocumentLibrary $library): int
    {
        $root = realpath((string) $this->argument('source-root'));
        $manifest = (string) $this->argument('manifest');
        if ($root === false || ! is_dir($root) || ! is_file($manifest) || is_link($manifest)) {
            $this->error('The source collection or inventory is unavailable.');

            return self::FAILURE;
        }
        $batch = (string) ($this->option('batch') ?: 'inventory-'.substr((string) hash_file('sha256', $manifest), 0, 16));
        if (! preg_match('/^[a-zA-Z0-9_-]{1,100}$/', $batch)) {
            $this->error('Use a stable batch name containing letters, numbers, hyphens or underscores.');

            return self::FAILURE;
        }
        $directory = storage_path('app/private/vehicle-document-imports/'.$batch);
        File::ensureDirectoryExists($directory, 0700);
        $receipt = $directory.'/'.($this->option('apply') ? 'import' : 'validation').'.jsonl';
        $counts = ['discovered' => 0, 'excluded' => 0, 'validated' => 0, 'imported_sources' => 0, 'linked_sources' => 0, 'needs_review' => 0, 'unmatched' => 0, 'duplicates' => 0, 'failed' => 0];
        $vehicles = [];
        $handle = fopen($manifest, 'rb');
        if ($handle === false) {
            throw new RuntimeException('The inventory could not be read.');
        }
        try {
            while (($line = fgets($handle, 1024 * 1024)) !== false) {
                if (trim($line) === '') {
                    continue;
                }
                $row = [];
                $result = ['row' => ++$counts['discovered'], 'batch' => $batch];
                try {
                    $row = json_decode($line, true, 64, JSON_THROW_ON_ERROR);
                    if (! is_array($row)) {
                        throw new RuntimeException('invalid_manifest_row');
                    }
                    $result['source_relative_path'] = (string) ($row['source_relative_path'] ?? '');
                    $result['sha256'] = (string) ($row['sha256'] ?? '');
                    if (! empty($row['exclusion_reason'])) {
                        $counts['excluded']++;
                        $result['status'] = 'excluded';
                        $result['reason'] = Str::limit((string) $row['exclusion_reason'], 200);
                    } else {
                        $path = $this->sourcePath($root, (string) ($row['source_relative_path'] ?? ''));
                        if (! preg_match('/^[a-f0-9]{64}$/', (string) ($row['sha256'] ?? ''))
                            || ! hash_equals((string) $row['sha256'], (string) hash_file('sha256', $path))
                            || filesize($path) !== (int) ($row['size_bytes'] ?? -1)) {
                            throw new RuntimeException('source_integrity_mismatch');
                        }
                        $status = (string) ($row['match_status'] ?? 'unmatched');
                        if (! in_array($status, ['verified', 'needs_review', 'unmatched'], true)) {
                            throw new RuntimeException('invalid_match_status');
                        }
                        // Candidates are never links. Only a verified evidence outcome supplies relationships.
                        $ids = $status === 'verified' ? array_values($row['matched_vehicle_ids'] ?? []) : [];
                        if ($status === 'verified' && ($ids === [] || empty($row['match_evidence'] ?? $row['observations'] ?? []))) {
                            throw new RuntimeException('verified_match_requires_evidence');
                        }
                        if (UnitMasterVehicle::query()->whereKey($ids)->count() !== count(array_unique($ids))) {
                            throw new RuntimeException('authoritative_vehicle_missing');
                        }
                        $library->inspectFile($path, (string) ($row['original_filename'] ?? basename($path)));
                        $counts['validated']++;
                        $result['status'] = 'validated';
                        if ($this->option('apply')) {
                            $existing = VehicleDocument::query()->where('sha256', $row['sha256'])->exists();
                            $document = $library->ingest($path, [
                                'title' => $row['title'] ?? pathinfo($path, PATHINFO_FILENAME),
                                'category' => $row['category_slug'] ?? 'other',
                                'description' => null,
                                'original_filename' => $row['original_filename'] ?? basename($path),
                                'document_date' => $row['document_date'] ?? null,
                                'report_number' => $row['report_number'] ?? null,
                                'source_type' => 'historical_import',
                                'source_relative_path' => $row['source_relative_path'],
                                'import_batch' => $batch,
                                'review_status' => $status,
                                'is_sensitive' => true,
                                'classification_evidence' => [
                                    'rule' => $row['match_rule'] ?? null,
                                    'confidence' => $row['classification_confidence'] ?? $row['confidence'] ?? null,
                                    'observations' => $row['observations'] ?? [],
                                    'match_evidence' => $row['match_evidence'] ?? [],
                                    'document_type' => $row['document_type'] ?? null,
                                    'review_reasons' => $row['review_reasons'] ?? [],
                                ],
                                'candidate_vehicle_ids' => $row['candidate_vehicle_ids'] ?? [],
                            ], array_map('intval', $ids));
                            $result['document_id'] = $document->id;
                            $result['status'] = $status;
                            $counts['imported_sources']++;
                            $counts['duplicates'] += (int) $existing;
                            $counts['linked_sources'] += (int) ($ids !== []);
                            $counts['needs_review'] += (int) ($status === 'needs_review');
                            $counts['unmatched'] += (int) ($status === 'unmatched');
                            $vehicles = [...$vehicles, ...$ids];
                        }
                    }
                } catch (Throwable $exception) {
                    $counts['failed']++;
                    $result['status'] = 'failed';
                    // Detailed content and exception traces never enter console output.
                    $result['error_type'] = class_basename($exception);
                }
                if (file_put_contents($receipt, json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n", FILE_APPEND | LOCK_EX) === false) {
                    throw new RuntimeException('The private reconciliation receipt could not be saved.');
                }
                $limit = (int) $this->option('limit');
                if ($limit > 0 && $limit <= $counts['validated'] + $counts['failed']) {
                    break;
                }
            }
        } finally {
            fclose($handle);
        }
        $counts['unique_vehicles'] = count(array_unique($vehicles));
        $counts['applied'] = (bool) $this->option('apply');
        File::put($directory.'/summary.json', json_encode($counts, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        $this->line(json_encode($counts, JSON_THROW_ON_ERROR));
        $this->line('Private reconciliation: '.$receipt);

        return $counts['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function sourcePath(string $root, string $relative): string
    {
        $relative = str_replace('\\', '/', $relative);
        if ($relative === '' || str_starts_with($relative, '/') || str_contains($relative, ':')
            || in_array('..', explode('/', $relative), true) || str_contains($relative, "\0")) {
            throw new RuntimeException('unsafe_source_reference');
        }
        $path = realpath($root.DIRECTORY_SEPARATOR.$relative);
        $prefix = rtrim(str_replace('\\', '/', $root), '/').'/';
        $resolved = $path === false ? '' : str_replace('\\', '/', $path);
        if (DIRECTORY_SEPARATOR === '\\') {
            $resolved = strtolower($resolved);
            $prefix = strtolower($prefix);
        }
        if ($path === false || ! str_starts_with($resolved, $prefix)
            || ! is_file($path) || is_link($path)) {
            throw new RuntimeException('source_outside_collection');
        }

        return $path;
    }
}
