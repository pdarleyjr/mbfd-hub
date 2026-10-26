<?php

declare(strict_types=1);

namespace App\Services\Training;

use App\Models\Training\TrainingCourse;
use App\Models\User;
use App\Services\Exports\ExportDataset;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

final class TrainingMatrixExportService
{
    public function __construct(private readonly TrainingAttendanceMatrixService $matrix) {}

    public function dataset(User $actor, TrainingCourse $course, array $filters = [], string $view = 'modules'): ExportDataset
    {
        if (! in_array($view, ['modules', 'sessions'], true)) {
            throw ValidationException::withMessages(['view' => 'Choose module summary or session detail.']);
        }
        $snapshot = $this->matrix->snapshot($course, $filters);
        $rows = $this->matrixRows($snapshot, $view);
        $headers = array_shift($rows);
        $rows = array_map(static function (array $row): array {
            $row[6] = $row[6]->format('Y-m-d'); // Assignment as-of is a calendar date, not an instant.
            $row[9] = $row[9] === null ? null : $row[9] * 100;

            return $row;
        }, $rows);

        return ExportDataset::fromRows($actor, $course->name.' Attendance Matrix', $headers, $rows,
            'Current '.$view.' view', ['As of: '.$snapshot['as_of']]);
    }

    public function bytes(TrainingCourse $course, array $filters = [], bool $complete = false, string $view = 'modules'): string
    {
        if (! in_array($view, ['modules', 'sessions'], true)) {
            throw ValidationException::withMessages(['view' => 'Choose module summary or session detail.']);
        }
        $snapshot = $this->matrix->snapshot($course, $complete ? [] : $filters);
        $views = $complete ? [
            'All Personnel' => [], 'Officers' => ['cohort' => 'officers'], 'Captains' => ['cohort' => 'captain'],
            'Lieutenants' => ['cohort' => 'lieutenant'], 'Rescue Officers' => ['cohort' => 'officers', 'assignment' => 'rescue'],
            'Combat Officers' => ['cohort' => 'officers', 'assignment' => 'combat'], 'All Firefighters' => ['cohort' => 'firefighters'],
            'Rescue FF' => ['cohort' => 'firefighters', 'assignment' => 'rescue'],
            'Combat FF' => ['cohort' => 'firefighters', 'assignment' => 'combat'], 'DE Bid' => ['de_bid' => true],
            'Missing Training' => ['status' => 'missing'],
        ] : ['Current View' => null];
        $sheets = [];
        foreach ($views as $name => $selection) {
            $data = $selection === null ? $snapshot : $this->matrix->filterSnapshot($snapshot, $selection);
            $sheets[$name] = $this->matrixRows($data, $complete ? 'modules' : $view);
        }
        if ($complete) {
            $sheets['Session Detail'] = $this->matrixRows($snapshot, 'sessions');
        }
        $sheets['Sessions'] = [['Session ID', 'Module', 'Class date', 'Start time (Eastern)', 'Location', 'Roster status', 'Source']];
        foreach ($snapshot['sessions'] as $session) {
            $sheets['Sessions'][] = [$session['id'], $session['module_name'], CarbonImmutable::parse($session['held_on']),
                $session['starts_at'] === null ? null : CarbonImmutable::parse($session['starts_at'])->setTimezone('America/New_York'),
                $session['location'], $session['status'], $session['source']];
        }
        $sheets['Attendance Evidence'] = [['Employee ID', 'Session ID', 'Module', 'Class date', 'Evidence source', 'Evidence status',
            'Digital roster status', 'Digitally signed at (UTC)', 'Paper source SHA-256', 'Source page', 'Source row', 'Paper reviewed at (UTC)', 'Recorded rank', 'Recorded shift', 'Attendance recorded at (UTC)']];
        $sessions = array_column($snapshot['sessions'], null, 'id');
        foreach ($snapshot['rows'] as $person) {
            foreach ($person['session_cells'] as $sessionId => $cell) {
                $session = $sessions[$sessionId];
                foreach ($cell['sources'] as $source) {
                    $sheets['Attendance Evidence'][] = [$person['employee_id'], $sessionId, $session['module_name'], CarbonImmutable::parse($session['held_on']),
                        $source['source'], TrainingAttendanceMatrixService::LABELS[$source['status']], $source['roster_status'] ?? null,
                        isset($source['signature_signed_at']) ? CarbonImmutable::parse($source['signature_signed_at'])->utc() : null,
                        $source['source_sha256'] ?? null, $source['source_page'] ?? null, $source['source_row'] ?? null,
                        isset($source['reviewed_at']) ? CarbonImmutable::parse($source['reviewed_at'])->utc() : null,
                        $source['attendance_snapshot']['rank'] ?? null, $source['attendance_snapshot']['shift'] ?? null,
                        isset($source['signed_at']) ? CarbonImmutable::parse($source['signed_at'])->utc() : null];
                }
            }
        }
        $sheets['Import-Data Exceptions'] = [['Type', 'Employee profile ID', 'Session ID', 'Module ID', 'Source SHA-256', 'Page', 'Source row', 'Reason']];
        foreach ($snapshot['exceptions'] as $exception) {
            $sheets['Import-Data Exceptions'][] = [$exception['type'], $exception['employee_profile_id'], $exception['session_id'],
                $exception['module_id'], $exception['source_sha256'] ?? null, $exception['source_page'] ?? null,
                $exception['source_row'] ?? null, $exception['reason']];
        }
        $cells = array_sum(array_map(static fn (array $rows): int => count($rows) * count($rows[0]), $sheets));
        if ($cells > 250000) {
            throw ValidationException::withMessages(['export' => 'This workbook is too large. Export a narrower current view or module.']);
        }
        $book = new Spreadsheet;
        try {
            $overview = $book->getActiveSheet();
            $overview->setTitle('Overview');
            $this->write($overview, [
                ['MBFD Training Attendance', 'Documented module coverage'], ['Course', $snapshot['course']['name']],
                ['Personnel assignment as of', CarbonImmutable::parse($snapshot['as_of'])],
                ['Report scope', $complete ? 'Complete course workbook; all personnel and modules' : 'Current filtered view'],
                ['Filters', json_encode($snapshot['filters'], JSON_THROW_ON_ERROR)],
                ['Personnel', $snapshot['totals']['personnel']], ['Covered required modules', $snapshot['totals']['covered']],
                ['Applicable required modules', $snapshot['totals']['required']], ['Data exceptions', $snapshot['totals']['exceptions']],
                ['Coverage meaning', 'Documented attendance only; no qualification, proficiency, training credit or certification is inferred.'],
                ['Digital', 'Nonvoid signed attendance on a submitted or certified roster, or preserved finalized attendance in the current archived version. Archiving a provisional roster does not finalize it.'],
                ['Paper', 'Reviewed approved identified paper evidence; original ink is available only through protected source documents.'],
                ['N/A and review', 'N/A is outside the module audience. Unknown audience applicability needs review and is excluded from the denominator.'],
                ['Current personnel', 'Canonical active Employee rank and latest published bid term as of the displayed date. Attendance-time context is retained separately.'],
                ['Optional modules', 'Displayed attendance is retained; optional modules do not increase the required denominator.'],
            ], false);
            foreach ($sheets as $name => $rows) {
                $sheet = $book->createSheet();
                $sheet->setTitle($name);
                $this->write($sheet, $rows, true);
            }
            $book->setActiveSheetIndex(0);
            $stream = fopen('php://temp/maxmemory:8388608', 'w+b');
            if ($stream === false) {
                throw new \RuntimeException('Unable to prepare training workbook.');
            }
            try {
                (new Xlsx($book))->save($stream);
                rewind($stream);
                $bytes = stream_get_contents($stream);
                if ($bytes === false) {
                    throw new \RuntimeException('Unable to read training workbook.');
                }

                return $bytes;
            } finally {
                fclose($stream);
            }
        } finally {
            $book->disconnectWorksheets();
        }
    }

    private function matrixRows(array $snapshot, string $view): array
    {
        $headers = ['Employee ID', 'Name', 'Rank', 'Shift', 'Current published assignment', 'Term', 'Assignment as of',
            'Covered', 'Required', 'Percent', 'Missing modules', 'Coverage status'];
        $columns = $view === 'sessions' ? $snapshot['sessions'] : $snapshot['modules'];
        foreach ($columns as $column) {
            $headers[] = $view === 'sessions'
                ? $column['module_name'].' | '.$column['held_on'].' | Session '.$column['id']
                    .($column['starts_at'] === null ? '' : ' | '.CarbonImmutable::parse($column['starts_at'])->setTimezone('America/New_York')->format('H:i T'))
                    .(blank($column['location']) ? '' : ' | '.$column['location'])
                : $column['name'].($column['is_required'] ? '' : ' (Optional)');
        }
        $rows = [$headers];
        foreach ($snapshot['rows'] as $person) {
            $row = [$person['employee_id'], $person['name'], $person['rank'], $person['shift'], $person['assignment'],
                $person['term_label'], CarbonImmutable::parse($person['assignment_as_of']), $person['covered_count'], $person['required_count'],
                $person['percent'] === null ? null : $person['percent'] / 100, implode(', ', $person['missing_modules']), $person['status']];
            foreach ($columns as $column) {
                $cell = $person[$view === 'sessions' ? 'session_cells' : 'module_cells'][$column['id']];
                $row[] = $cell['label'];
            }
            $rows[] = $row;
        }

        return $rows;
    }

    private function write(Worksheet $sheet, array $rows, bool $filter): void
    {
        foreach ($rows as $rowIndex => $row) {
            foreach ($row as $columnIndex => $value) {
                $address = Coordinate::stringFromColumnIndex($columnIndex + 1).($rowIndex + 1);
                if ($value instanceof \DateTimeInterface) {
                    $sheet->setCellValueExplicit($address, Date::dateTimeToExcel($value), DataType::TYPE_NUMERIC);
                    $hasTime = ($sheet->getTitle() === 'Sessions' && $columnIndex === 3)
                        || ($sheet->getTitle() === 'Attendance Evidence' && in_array($columnIndex, [7, 11, 14], true));
                    $sheet->getStyle($address)->getNumberFormat()->setFormatCode($hasTime ? 'yyyy-mm-dd hh:mm' : 'yyyy-mm-dd');
                } elseif (is_int($value) || is_float($value)) {
                    $sheet->setCellValueExplicit($address, $value, DataType::TYPE_NUMERIC);
                    if ($columnIndex === 9 && $rowIndex > 0 && ! in_array($sheet->getTitle(), ['Overview', 'Sessions', 'Import-Data Exceptions', 'Attendance Evidence'], true)) {
                        $sheet->getStyle($address)->getNumberFormat()->setFormatCode('0.0%');
                    }
                } else {
                    $sheet->setCellValueExplicit($address, $value === null ? '' : (string) $value, DataType::TYPE_STRING);
                }
            }
        }
        $last = Coordinate::stringFromColumnIndex(count($rows[0]));
        $sheet->getStyle('A1:'.$last.'1')->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
        $sheet->getStyle('A1:'.$last.'1')->getFill()->setFillType('solid')->getStartColor()->setARGB('FF1E293B');
        $sheet->getStyle('A1:'.$last.count($rows))->getAlignment()->setVertical('top')->setWrapText(true);
        $sheet->getRowDimension(1)->setRowHeight(44);
        $sheet->freezePane($filter ? 'C2' : 'A2');
        if ($filter) {
            $sheet->setAutoFilter('A1:'.$last.count($rows));
        }
        for ($column = 1; $column <= count($rows[0]); $column++) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($column))->setWidth($column === 2 ? 30 : 23);
        }
        if ($sheet->getTitle() === 'Overview') {
            $sheet->getColumnDimension('B')->setWidth(100);
        }
    }
}
