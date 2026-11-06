<?php

declare(strict_types=1);

namespace App\Services\Personnel;

use App\Models\Employee;
use App\Models\FuelIdEvent;
use App\Models\FuelIdRow;
use App\Models\FuelIdSource;
use App\Models\User;
use App\Services\Personnel\FuelIds\FuelIdImportException;
use App\Services\Personnel\FuelIds\FuelIdImportPlan;
use App\Services\Personnel\FuelIds\FuelIdImportResult;
use App\Services\Personnel\FuelIds\FuelIdNameMatcher;
use App\Services\Personnel\FuelIds\FuelIdPlannedRow;
use App\Services\Personnel\FuelIds\FuelIdRowReader;
use App\Services\Personnel\FuelIds\FuelIdSourceRow;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Imports the City fuel list (Driver Prompt ID = Fuel PIN).
 *
 * Matching: the source Employee ID must equal exactly one employees.employee_id after trimming whitespace and
 * leading zeros, the ID must appear only once in the list, and the source last name must agree with that
 * employee's name. Only then is the PIN assigned (matched). An ID listed twice is a conflict, a disagreeing name is
 * a name mismatch, and an unknown ID is unmatched; none of those is assigned to anyone until an administrator
 * resolves it. A newly matched row supersedes the employee's previous PIN row; rows that are not matched never
 * touch a current PIN. Importing a file whose SHA-256 is already on record changes nothing.
 */
final class FuelIdImporter
{
    public function __construct(
        private readonly FuelIdRowReader $reader,
        private readonly FuelPinDirectory $directory,
    ) {}

    public function plan(string $path, string $format = 'auto', ?string $originalName = null): FuelIdImportPlan
    {
        $format = $this->reader->detectFormat($path, $originalName, $format);
        $sha256 = $this->hash($path);

        return new FuelIdImportPlan(
            format: $format,
            sha256: $sha256,
            rows: $this->classify($this->reader->read($path, $format)),
            alreadyImported: $this->alreadyImported([$sha256]),
        );
    }

    /**
     * @param  string|null  $sourcePdfPath  When importing CSV or JSON rows, the original PDF to keep as the source record.
     */
    public function import(
        string $path,
        ?User $actor,
        string $format = 'auto',
        ?string $originalName = null,
        ?string $sourcePdfPath = null,
        ?string $sourcePdfName = null,
    ): FuelIdImportResult {
        $plan = $this->plan($path, $format, $originalName);
        $recordPath = $path;
        $recordName = $originalName ?? basename($path);
        $recordFormat = $plan->format;
        if ($sourcePdfPath !== null) {
            if ((string) @file_get_contents($sourcePdfPath, false, null, 0, 5) !== '%PDF-') {
                throw new FuelIdImportException('The source record must be a PDF.');
            }
            $recordPath = $sourcePdfPath;
            $recordName = $sourcePdfName ?? basename($sourcePdfPath);
            $recordFormat = 'pdf';
        }
        $recordSha = $this->hash($recordPath);
        if ($plan->alreadyImported || $this->alreadyImported([$recordSha])) {
            return new FuelIdImportResult($plan, null, false);
        }

        $diskName = (string) config('filesystems.private', 'local');
        $storedPath = 'fuel-ids/sources/'.now()->format('Y/m').'/'.Str::uuid()->toString().'.'.$recordFormat;
        $stream = fopen($recordPath, 'rb');
        if ($stream === false || ! Storage::disk($diskName)->put($storedPath, $stream)) {
            throw new FuelIdImportException('The source file could not be stored.');
        }
        if (is_resource($stream)) {
            fclose($stream);
        }

        try {
            [$source, $superseded] = DB::transaction(fn (): array => $this->persist($plan, $actor, [
                'disk' => $diskName,
                'path' => $storedPath,
                'sha256' => $recordSha,
                'data_sha256' => $plan->sha256 === $recordSha ? null : $plan->sha256,
                'original_name' => Str::limit($this->safeName($recordName), 250, ''),
                'mime_type' => $this->reader->mimeFor($recordFormat),
                'file_size' => (int) filesize($recordPath),
                'format' => $plan->format,
            ]));
        } catch (QueryException $exception) {
            Storage::disk($diskName)->delete($storedPath);
            if ($this->alreadyImported([$recordSha, $plan->sha256])) {
                return new FuelIdImportResult($plan, null, false);
            }
            throw $exception;
        } catch (\Throwable $exception) {
            Storage::disk($diskName)->delete($storedPath);
            throw $exception;
        }

        return new FuelIdImportResult($plan, $source, true, $superseded);
    }

    /**
     * @param  array<string, mixed>  $sourceAttributes
     * @return array{0: FuelIdSource, 1: int}
     */
    private function persist(FuelIdImportPlan $plan, ?User $actor, array $sourceAttributes): array
    {
        $counts = $plan->counts();
        $source = FuelIdSource::query()->create([
            ...$sourceAttributes,
            'uploaded_by_user_id' => $actor?->getKey(),
            'row_count' => count($plan->rows),
            'matched_count' => $counts[FuelIdRow::MATCHED] ?? 0,
            'exception_count' => count($plan->exceptions()),
            'imported_at' => now(),
        ]);

        // Open exceptions from an older list are replaced by this list's own exceptions.
        FuelIdRow::query()->whereIn('match_status', FuelIdRow::OPEN_EXCEPTIONS)
            ->where('fuel_id_source_id', '!=', $source->id)
            ->update(['match_status' => FuelIdRow::SUPERSEDED, 'superseded_at' => now()]);

        $superseded = 0;
        foreach ($plan->rows as $planned) {
            $row = FuelIdRow::query()->create([
                'fuel_id_source_id' => $source->id,
                'row_number' => $planned->row->rowNumber,
                'page' => $planned->row->page,
                'source_employee_number' => $planned->row->employeeNumber,
                'normalized_employee_number' => $planned->row->normalizedEmployeeNumber(),
                'last_name' => $planned->row->lastName,
                'first_name' => $planned->row->firstName !== '' ? $planned->row->firstName : null,
                'prompt_id' => $planned->row->promptId,
                'status' => $planned->row->status,
                'department' => $planned->row->department,
                'match_status' => $planned->status,
                'employee_id' => $planned->status === FuelIdRow::MATCHED ? $planned->employeeId : null,
                'candidate_employee_id' => $planned->candidateEmployeeId,
                'employee_departed' => $planned->employeeDeparted,
            ]);
            if ($planned->status === FuelIdRow::MATCHED && $planned->employeeId !== null) {
                $superseded += $this->directory->makeCurrent($row, $planned->employeeId) ? 1 : 0;
            }
        }

        $this->directory->record(FuelIdEvent::IMPORTED, $actor, source: $source, metadata: [
            'format' => $plan->format,
            'rows' => count($plan->rows),
            'counts' => $counts,
            'superseded' => $superseded,
        ]);

        return [$source, $superseded];
    }

    /**
     * @param  list<FuelIdSourceRow>  $rows
     * @return list<FuelIdPlannedRow>
     */
    private function classify(array $rows): array
    {
        $occurrences = [];
        foreach ($rows as $row) {
            $key = $row->normalizedEmployeeNumber();
            $occurrences[$key] = ($occurrences[$key] ?? 0) + 1;
        }
        $employees = [];
        Employee::query()->whereNotNull('employee_id')->select(['id', 'employee_id', 'name', 'roster_status'])
            ->orderBy('id')->each(function (Employee $employee) use (&$employees): void {
                $key = FuelIdNameMatcher::normalizeEmployeeNumber((string) $employee->employee_id);
                if ($key !== '') {
                    $employees[$key][] = $employee;
                }
            });

        return array_map(function (FuelIdSourceRow $row) use ($occurrences, $employees): FuelIdPlannedRow {
            $key = $row->normalizedEmployeeNumber();
            $candidates = $employees[$key] ?? [];
            $single = count($candidates) === 1 ? $candidates[0] : null;
            if ($occurrences[$key] > 1) {
                return new FuelIdPlannedRow($row, FuelIdRow::CONFLICT, "Employee ID appears {$occurrences[$key]} times in the list.", candidateEmployeeId: $single?->id, employeeName: $single?->name);
            }
            if ($candidates === []) {
                return new FuelIdPlannedRow($row, FuelIdRow::UNMATCHED, 'No Hub employee has this employee ID.');
            }
            if ($single === null) {
                return new FuelIdPlannedRow($row, FuelIdRow::CONFLICT, 'More than one Hub employee has this employee ID.');
            }
            $departed = $single->roster_status === 'departed';
            if (! FuelIdNameMatcher::lastNameAgrees($row->lastName, $single->name)) {
                return new FuelIdPlannedRow($row, FuelIdRow::NAME_MISMATCH, 'The last name does not match the Hub employee with this ID.', candidateEmployeeId: $single->id, employeeName: $single->name, employeeDeparted: $departed);
            }

            return new FuelIdPlannedRow($row, FuelIdRow::MATCHED, $departed ? 'Matched to a departed employee.' : 'Matched.', employeeId: $single->id, employeeName: $single->name, employeeDeparted: $departed);
        }, $rows);
    }

    /** @param list<string> $hashes */
    private function alreadyImported(array $hashes): bool
    {
        return FuelIdSource::query()->whereIn('sha256', $hashes)->orWhereIn('data_sha256', $hashes)->exists();
    }

    private function hash(string $path): string
    {
        $hash = @hash_file('sha256', $path);
        if (! is_string($hash)) {
            throw new FuelIdImportException('The fuel list file could not be read.');
        }

        return $hash;
    }

    private function safeName(string $name): string
    {
        return preg_replace('~[\x00-\x1f\x7f"\\\\/]+~', '_', basename($name)) ?: 'fuel-list';
    }
}
