<?php

declare(strict_types=1);

namespace App\Services\Personnel;

use App\Models\PersonnelReconciliation;
use App\Models\User;
use App\Services\Roster\RosterHtmlParser;
use App\Services\Security\ApplicationAccessService;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use ZipArchive;

/** Workbook proposals are evidence only. This service never writes employees or accounts. */
final class PersonnelWorkbookReconciler
{
    public const HEADERS = ['Name', 'Employee ID', 'Email Address', 'Rank / Position', 'Shift', 'A Day', 'Station / Division', 'Seat / Unit', 'Bid Position', 'Email Match Status'];

    public static function canView(?User $actor, bool $refreshActor = true): bool
    {
        $actor = $refreshActor ? $actor?->fresh() : $actor;

        return $actor !== null && $actor->isAuthenticationAllowed()
            && $actor->hasCurrentAdminPanelEntitlement()
            && ($actor->hasRole('super_admin') || $actor->hasDirectWebPermission('personnel.directory.view')
                || $actor->hasDirectWebPermission('admin.members.view')
                || $actor->hasDirectWebPermission('admin.members.manage')
                || (($actor->hasDirectWebPermission('admin.personnel.view') || $actor->hasDirectWebPermission('admin.personnel.manage'))
                    && ! ApplicationAccessService::isUniformManagerOnly($actor)));
    }

    public static function canReconcile(?User $actor): bool
    {
        $actor = $actor?->fresh();

        return self::canView($actor) && ($actor->hasRole('super_admin')
            || $actor->hasDirectWebPermission('admin.members.manage')
            || $actor->hasDirectWebPermission('personnel.directory.edit')
            || ($actor->hasDirectWebPermission('admin.personnel.manage') && ! ApplicationAccessService::isUniformManagerOnly($actor)));
    }

    public static function canExport(?User $actor): bool
    {
        $actor = $actor?->fresh();

        return self::canView($actor) && ($actor->hasRole('super_admin')
            || $actor->hasDirectWebPermission('admin.members.manage')
            || $actor->hasDirectWebPermission('personnel.directory.export')
            || ($actor->hasDirectWebPermission('admin.personnel.manage') && ! ApplicationAccessService::isUniformManagerOnly($actor)));
    }

    /** @return array{summary:array<string,mixed>,rows:list<array<string,mixed>>,workbook_sha256:string} */
    public function analyze(User $actor, string $path): array
    {
        abort_unless(self::canReconcile($actor), 403);
        $this->validateArchive($path);
        $reader = IOFactory::createReader('Xlsx');
        $reader->setReadDataOnly(true);
        $info = $reader->listWorksheetInfo($path);
        if (count($info) > 8) {
            throw ValidationException::withMessages(['workbook' => 'Use the supported MBFD directory workbook with at most eight worksheets.']);
        }
        foreach ($info as $sheetInfo) {
            if ($sheetInfo['totalRows'] > 2001 || $sheetInfo['totalColumns'] > 30) {
                throw ValidationException::withMessages(['workbook' => 'A worksheet exceeds the supported directory size.']);
            }
        }
        $reader->setReadFilter(new class implements IReadFilter
        {
            public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
            {
                return $row <= 2001 && Coordinate::columnIndexFromString($columnAddress) <= 30;
            }
        });
        $book = $reader->load($path);

        try {
            foreach ($book->getAllSheets() as $sheet) {
                if ($sheet->getHighestDataRow() > 2001 || Coordinate::columnIndexFromString($sheet->getHighestDataColumn()) > 30) {
                    throw ValidationException::withMessages(['workbook' => 'A worksheet exceeds the supported directory size.']);
                }
            }
            foreach (['Summary', 'MBFD Directory', 'By Assignment', 'Email QA', 'Assignment Overrides'] as $sheetName) {
                if ($book->getSheetByName($sheetName) === null) {
                    throw ValidationException::withMessages(['workbook' => 'The workbook must include all five MBFD directory worksheets.']);
                }
            }
            $directory = $book->getSheetByName('MBFD Directory');
            foreach ([
                'By Assignment' => ['Shift', 'Station / Division', 'Seat / Unit', 'Bid Position', 'Name', 'Rank / Position', 'Employee ID', 'Email Address', 'A Day'],
                'Email QA' => ['Employee ID', 'Assignment Name', 'Directory Display Name', 'Email Address', 'Match Status', 'Review Note'],
                'Assignment Overrides' => ['Employee ID', 'Name', 'Override Type', 'Original Shift', 'Original Station', 'Original Seat / Unit', 'Original Position', 'Applied Shift', 'Applied Station', 'Applied Seat / Unit', 'Applied Position', 'Reason / Source'],
            ] as $sheetName => $expectedHeaders) {
                $last = Coordinate::stringFromColumnIndex(count($expectedHeaders));
                $headers = array_map(fn ($value): string => trim((string) $value), $book->getSheetByName($sheetName)->rangeToArray('A1:'.$last.'1', null, false, false)[0]);
                if ($headers !== $expectedHeaders) {
                    throw ValidationException::withMessages(['workbook' => 'The '.$sheetName.' worksheet headers do not match the supported MBFD workbook.']);
                }
            }
            $actualHeaders = array_map(fn ($value): string => trim((string) $value), $directory->rangeToArray('A1:J1', null, false, false)[0]);
            if ($actualHeaders !== self::HEADERS || $directory->getHighestDataRow() > 2001) {
                throw ValidationException::withMessages(['workbook' => 'The directory headers must match the ten-column MBFD workbook, with at most 2,000 members.']);
            }

            $qa = [];
            foreach ($this->rows($book->getSheetByName('Email QA'), 'F') as $row) {
                $id = trim((string) $row[0]);
                if ($id !== '') {
                    $qa[$id] = ['status' => trim((string) $row[4]), 'note' => trim((string) $row[5]), 'excluded' => preg_match('/do\s+not\s+add/i', (string) $row[5]) === 1];
                }
            }
            $overrides = [];
            $overrideSheet = $book->getSheetByName('Assignment Overrides');
            $overrideRows = $overrideSheet->toArray(null, false, false, false);
            $overrideHeaders = array_map(fn ($v): string => strtolower(trim((string) $v)), array_shift($overrideRows));
            $idColumn = array_search('employee id', $overrideHeaders, true);
            if ($idColumn !== false) {
                foreach ($overrideRows as $row) {
                    $id = trim((string) ($row[$idColumn] ?? ''));
                    if ($id !== '') {
                        $overrides[$id] = array_combine($overrideHeaders, array_map(fn ($value): string => mb_substr(trim((string) $value), 0, 1000), $row));
                    }
                }
            }

            $source = app(PersonnelSource::class);
            $live = $source->query()->orderBy('employees.id')->get();
            $liveById = $live->groupBy(fn ($employee): string => (string) $employee->employee_id);
            $seen = [];
            $report = [];
            $summary = ['workbook_rows' => 0, 'hub_rows' => $live->count(), 'present_both' => 0, 'workbook_only' => 0, 'hub_only' => 0, 'excluded' => 0, 'conflicts' => 0, 'missing_email' => 0, 'email_review' => 0, 'duplicate_ids' => 0, 'assignment_view_mismatches' => 0, 'assignment_overrides' => count($overrides), 'shifts' => [], 'sheets' => $book->getSheetNames()];
            $assignmentById = [];
            foreach ($this->rows($book->getSheetByName('By Assignment'), 'I') as $row) {
                $assignmentById[trim((string) $row[6])] = array_map(fn ($v): string => trim((string) $v), $row);
            }
            $directoryRows = $this->rows($directory, 'J');
            $workbookIdCounts = array_count_values(array_map(fn ($row): string => trim((string) $row[1]), $directoryRows));
            foreach ($directoryRows as $index => $row) {
                $row = array_map(fn ($value): string => mb_substr(trim((string) $value), 0, 1000), $row);
                if (implode('', $row) === '') {
                    continue;
                }
                $summary['workbook_rows']++;
                [$name, $id, $email, $rank, $shift, $aDay, $station, $unit, $bidPosition, $emailStatus] = $row;
                $summary['shifts'][$shift] = ($summary['shifts'][$shift] ?? 0) + 1;
                $flags = [];
                $invalidId = ! preg_match('/^[0-9]{1,20}$/D', $id);
                if ($invalidId) {
                    $flags[] = 'Invalid employee ID; no identity match attempted.';
                }
                $duplicate = ($workbookIdCounts[$id] ?? 0) > 1 || ($liveById->get($id)?->count() ?? 0) > 1;
                if ($duplicate) {
                    $summary['duplicate_ids']++;
                    $flags[] = 'Duplicate employee ID requires identity review.';
                }
                $seen[$id] = true;
                $qaRow = $qa[$id] ?? [];
                $excluded = $qaRow['excluded'] ?? false;
                if ($excluded) {
                    $summary['excluded']++;
                    $flags[] = 'DO NOT ADD TO THE MBFD HUB';
                }
                if ($email === '') {
                    $summary['missing_email']++;
                    $flags[] = 'Missing email; no address inferred.';
                } elseif (Validator::make(['email' => $email], ['email' => 'email:rfc'])->fails()) {
                    $flags[] = 'Email format requires review.';
                }
                if (preg_match('/surname|probable|review|unresolved/i', $emailStatus.' '.($qaRow['status'] ?? ''))) {
                    $summary['email_review']++;
                    $flags[] = 'Email identity remains pending review, including contradictory confirmed labels.';
                }
                if (isset($overrides[$id])) {
                    $flags[] = 'Assignment override in workbook; permanent and temporary placements must be reviewed separately.';
                    if (preg_match('/unconfirm|not\s+confirmed|uncertain|unverified|pending/i', $overrides[$id]['reason / source'] ?? '')) {
                        $flags[] = 'The assignment override contains an unconfirmed value; review its source notes.';
                    }
                }
                $assignment = $assignmentById[$id] ?? null;
                if ($assignment !== [$shift, $station, $unit, $bidPosition, $name, $rank, $id, $email, $aDay]) {
                    $summary['assignment_view_mismatches']++;
                    $flags[] = 'By Assignment differs from the main directory.';
                }
                $employee = $invalidId || $duplicate ? null : $liveById->get($id)?->first();
                $workbook = ['name' => $name, 'city_email' => $email, 'rank' => $rank, 'shift' => $shift, 'a_day' => $aDay, 'station' => $station, 'unit' => $unit, 'bid_position' => $bidPosition, 'email_match_status' => $emailStatus];
                $current = [];
                $differences = [];
                if ($employee !== null) {
                    $summary['present_both']++;
                    foreach (['name', 'city_email', 'rank', 'station', 'shift', 'a_day', 'unit', 'bid_position'] as $field) {
                        $value = (string) ($source->value($employee, $field) ?? '');
                        $current[$field] = $value;
                        if ($this->comparisonValue($field, $value) !== $this->comparisonValue($field, $workbook[$field])) {
                            $differences[] = $field;
                        }
                    }
                    if ($employee->roster_status !== 'active') {
                        $flags[] = 'Existing historical/inactive personnel record; do not reactivate automatically.';
                    }
                } else {
                    $summary['workbook_only']++;
                }
                $classification = $excluded ? 'excluded' : ($invalidId || $duplicate ? 'identity_review' : ($employee === null ? 'workbook_only' : ($differences === [] ? 'matched' : 'conflict')));
                if ($classification === 'conflict') {
                    $summary['conflicts']++;
                }
                $report[] = ['employee_id' => $id, 'profile_id' => $employee?->id, 'name' => $employee->name ?? $name, 'classification' => $classification, 'workbook_row' => $index + 2, 'workbook' => $workbook, 'live' => $current, 'differences' => $differences, 'flags' => $flags, 'qa_note' => $qaRow['note'] ?? '', 'qa_status' => $qaRow['status'] ?? '', 'assignment_override' => $overrides[$id] ?? []];
            }
            foreach ($live as $employee) {
                if (! isset($seen[(string) $employee->employee_id])) {
                    $summary['hub_only']++;
                    $report[] = ['employee_id' => (string) $employee->employee_id, 'profile_id' => $employee->id, 'name' => $employee->name, 'classification' => 'hub_only', 'workbook' => [], 'live' => ['name' => $employee->name, 'roster_status' => $employee->roster_status], 'differences' => [], 'flags' => ['Absence from workbook does not authorize removal or account changes.'], 'qa_note' => '', 'assignment_override' => []];
                }
            }
            $summary['rules'] = 'Read-only comparison by employee ID. Live data remains unchanged. Name order, shift labels and A-Day group labels are normalized for comparison only. Workbook assignments may be older; permanent assignments are distinct from daily placements. Published Bid data has no effective-date guarantee. Exclusions and uncertain identities cannot be applied automatically.';

            return ['summary' => $summary, 'rows' => $report, 'workbook_sha256' => hash_file('sha256', $path)];
        } finally {
            $book->disconnectWorksheets();
        }
    }

    public function store(User $actor, string $path, string $sourceName): PersonnelReconciliation
    {
        $result = $this->analyze($actor, $path);

        return PersonnelReconciliation::query()->create($result + ['uploaded_by' => $actor->id, 'source_name' => mb_substr(basename($sourceName), 0, 255)]);
    }

    /** @return array<int,string> */
    public function latestEmailStatuses(User $actor): array
    {
        abort_unless(self::canView($actor), 403);
        $report = PersonnelReconciliation::query()->latest('id')->first();
        $statuses = [];
        foreach ($report->rows ?? [] as $row) {
            if ($row['profile_id'] !== null) {
                $statuses[(int) $row['profile_id']] = $row['classification'] === 'excluded' ? 'Excluded — review only' : ($row['workbook']['email_match_status'] ?? 'Not in workbook');
            }
        }

        return $statuses;
    }

    /** @return list<list<mixed>> */
    private function rows(Worksheet $sheet, string $lastColumn): array
    {
        if ($sheet->getHighestDataRow() > 2001 || strlen($sheet->getHighestDataColumn()) > 2) {
            throw ValidationException::withMessages(['workbook' => 'A worksheet exceeds the supported 2,000-row directory size.']);
        }

        return $sheet->getHighestDataRow() < 2 ? [] : $sheet->rangeToArray('A2:'.$lastColumn.$sheet->getHighestDataRow(), null, false, false);
    }

    private function validateArchive(string $path): void
    {
        if (! is_file($path) || filesize($path) > 5 * 1024 * 1024) {
            throw ValidationException::withMessages(['workbook' => 'Upload an XLSX workbook of at most 5 MB.']);
        }
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw ValidationException::withMessages(['workbook' => 'The upload is not a readable XLSX workbook.']);
        }
        try {
            $expanded = 0;
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $info = $zip->statIndex($index);
                $expanded += $info['size'];
                if ($expanded > 30 * 1024 * 1024 || $zip->numFiles > 1000 || str_contains($info['name'], '../') || preg_match('/vbaProject\.bin$/i', $info['name'])) {
                    throw ValidationException::withMessages(['workbook' => 'The workbook archive is too large or contains unsupported content.']);
                }
            }
        } finally {
            $zip->close();
        }
    }

    private function comparisonValue(string $field, string $value): string
    {
        if ($field === 'name') {
            $value = app(RosterHtmlParser::class)->normalizeName($value);
        } elseif ($field === 'shift') {
            $value = preg_replace('/^([ABCD])\s+Shift$/i', '$1', $value);
            if (strcasecmp($value, 'Days') === 0) {
                $value = 'D';
            }
        } elseif ($field === 'a_day') {
            $value = preg_replace('/^Group\s+([1-4])$/i', '$1', $value);
        }

        return mb_strtolower(trim(preg_replace('/\s+/', ' ', $value)));
    }
}
