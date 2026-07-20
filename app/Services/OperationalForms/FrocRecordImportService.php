<?php

namespace App\Services\OperationalForms;

use App\Models\Employee;
use App\Models\OperationalFormEvent;
use App\Models\OperationalFormImport;
use App\Models\OperationalFormRecord;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class FrocRecordImportService
{
    public const PARSER_VERSION = '2';

    public function __construct(
        private readonly FrocImportService $extractor,
        private readonly FrocImportMergeService $merger,
        private readonly FormDataValidator $validator,
    ) {}

    public function apply(
        Employee $employee,
        string $recordId,
        int $revision,
        string $unitId,
        ?string $notes,
        ?UploadedFile $file,
        string $idempotencyKey,
    ): array {
        $record = $this->ownedFroc($employee, $recordId);
        $existing = OperationalFormImport::query()
            ->where('form_record_id', $record->id)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing && $existing->status === 'applied') {
            return $this->result($this->loadRecord($record->fresh()), $existing, true);
        }

        $analysis = data_get($existing?->result, 'analysis');
        if (! is_array($analysis)) {
            $analysis = $this->extractor->preview($unitId, $notes, $file, [
                'import_id' => (string) Str::ulid(),
                'record_hash' => hash('sha256', $record->id),
                'employee_hash' => hash('sha256', (string) $employee->getKey()),
            ]);
            $analysis = $this->safeAnalysis($analysis);
        }

        $identityHash = hash('sha256', implode('|', [
            $record->id,
            $analysis['source_sha256'],
            mb_strtoupper($unitId),
            self::PARSER_VERSION,
        ]));

        $sameSource = OperationalFormImport::query()
            ->where('identity_hash', $identityHash)
            ->where('status', 'applied')
            ->first();
        if ($sameSource) {
            return $this->result($this->loadRecord($record->fresh()), $sameSource, true);
        }

        $import = $existing ?? OperationalFormImport::query()->create([
            'form_record_id' => $record->id,
            'employee_id' => $employee->getKey(),
            'idempotency_key' => $idempotencyKey,
            'identity_hash' => $identityHash,
            'parser_version' => self::PARSER_VERSION,
            'unit_id' => $unitId,
            'source_sha256' => $analysis['source_sha256'],
            'source_type' => $analysis['source_type'],
            'engine' => $analysis['engine'],
            'fallback_used' => $analysis['engine'] === 'deterministic-fallback',
            'fallback_reason' => $analysis['fallback_reason'] ?? null,
            'matched_message_count' => $analysis['matched_message_count'],
            'status' => 'analyzed',
            'result' => ['analysis' => $analysis],
        ]);

        $applied = DB::transaction(function () use ($employee, $record, $revision, $analysis, $import): array {
            $locked = OperationalFormRecord::query()
                ->whereKey($record->id)
                ->where('employee_id', $employee->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->revision !== $revision) {
                return ['conflict' => true, 'record' => $locked];
            }

            $before = $locked->data;
            $beforeTitle = $locked->title;
            $merged = $this->merger->merge($before, $locked->title, $analysis);
            $validated = $this->validator->validate($locked->form_type, $merged['data']);
            $locked->forceFill([
                'title' => $merged['title'],
                'data' => $validated,
                'revision' => $locked->revision + 1,
                'status' => $locked->latest_pdf_version === null ? $locked->status : 'draft',
                'last_autosaved_at' => now(),
            ])->save();

            $summary = array_merge($merged['summary'], [
                'id' => $import->id,
                'engine' => $import->engine,
                'fallback_used' => $import->fallback_used,
                'fallback_reason' => $import->fallback_reason,
                'matched_message_count' => $import->matched_message_count,
                'source_sha256' => $import->source_sha256,
                'source_type' => $import->source_type,
            ]);
            $import->forceFill([
                'status' => 'applied',
                'before_data' => $before,
                'applied_revision' => $locked->revision,
                'result' => [
                    'summary' => $summary,
                    'after_data' => $validated,
                    'before_title' => $beforeTitle,
                    'after_title' => $merged['title'],
                ],
            ])->save();
            OperationalFormEvent::query()->create([
                'form_record_id' => $locked->id,
                'employee_id' => $employee->getKey(),
                'event_type' => 'froc_import_applied',
                'created_at' => now(),
            ]);

            return ['conflict' => false, 'record' => $this->loadRecord($locked)];
        });

        if ($applied['conflict']) {
            return [
                'conflict' => true,
                'server_revision' => $applied['record']->revision,
                'import' => [
                    'id' => $import->id,
                    'engine' => $import->engine,
                    'fallback_used' => $import->fallback_used,
                    'matched_message_count' => $import->matched_message_count,
                    'source_sha256' => $import->source_sha256,
                ],
            ];
        }

        return $this->result($applied['record'], $import->fresh(), false);
    }

    public function undo(Employee $employee, string $recordId, string $importId, int $revision): OperationalFormRecord
    {
        return DB::transaction(function () use ($employee, $recordId, $importId, $revision): OperationalFormRecord {
            $record = $this->ownedFroc($employee, $recordId, true);
            $import = OperationalFormImport::query()
                ->whereKey($importId)
                ->where('form_record_id', $record->id)
                ->where('employee_id', $employee->getKey())
                ->where('status', 'applied')
                ->firstOrFail();

            if ($record->revision !== $revision) {
                abort(409, 'The form changed before the import could be undone. Reload and try again.');
            }

            $data = $record->data;
            $before = $import->before_data ?? [];
            $after = data_get($import->result, 'after_data', []);
            $summary = data_get($import->result, 'summary', []);
            foreach ($summary['applied_fields'] ?? [] as $path) {
                if ($path === 'title') {
                    continue;
                }
                if (data_get($data, $path) === data_get($after, $path)) {
                    data_set($data, $path, data_get($before, $path));
                }
            }
            foreach (array_reverse($summary['appended_labor_rows'] ?? []) as $index) {
                if (($data['labor'][$index] ?? null) === ($after['labor'][$index] ?? null)) {
                    array_splice($data['labor'], $index, 1);
                }
            }
            foreach (array_reverse($summary['appended_mileage_rows'] ?? []) as $index) {
                if (($data['vehicle_mileage'][$index] ?? null) === ($after['vehicle_mileage'][$index] ?? null)) {
                    array_splice($data['vehicle_mileage'], $index, 1);
                }
            }
            $title = $record->title;
            if ($title === data_get($import->result, 'after_title')) {
                $title = (string) data_get($import->result, 'before_title', $title);
            }
            $data = $this->validator->validate($record->form_type, $data);
            $record->forceFill([
                'title' => $title,
                'data' => $data,
                'revision' => $record->revision + 1,
                'last_autosaved_at' => now(),
            ])->save();
            $import->forceFill(['status' => 'undone', 'undone_at' => now()])->save();
            OperationalFormEvent::query()->create([
                'form_record_id' => $record->id,
                'employee_id' => $employee->getKey(),
                'event_type' => 'froc_import_undone',
                'created_at' => now(),
            ]);

            return $this->loadRecord($record);
        });
    }

    private function ownedFroc(Employee $employee, string $recordId, bool $lock = false): OperationalFormRecord
    {
        $query = OperationalFormRecord::query()
            ->whereKey($recordId)
            ->where('employee_id', $employee->getKey())
            ->where('form_type', 'froc_log_001_ff');

        return ($lock ? $query->lockForUpdate() : $query)->firstOrFail();
    }

    private function safeAnalysis(array $analysis): array
    {
        $analysis['labor'] = array_map(function (array $row): array {
            unset($row['source_excerpt']);

            return $row;
        }, $analysis['labor'] ?? []);
        unset($analysis['warning']);

        return $analysis;
    }

    private function result(OperationalFormRecord $record, OperationalFormImport $import, bool $replay): array
    {
        $summary = data_get($import->result, 'summary', []);
        $summary['idempotent_replay'] = $replay;

        return ['conflict' => false, 'record' => $record, 'import' => $summary];
    }

    private function loadRecord(OperationalFormRecord $record): OperationalFormRecord
    {
        return $record->load([
            'documents',
            'imports' => fn ($query) => $query->where('status', 'applied')->latest(),
        ]);
    }
}
