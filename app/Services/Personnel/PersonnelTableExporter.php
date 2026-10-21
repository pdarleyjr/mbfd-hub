<?php

declare(strict_types=1);

namespace App\Services\Personnel;

use App\Models\Employee;
use App\Models\PersonnelTable;
use App\Models\PersonnelTableEntry;
use App\Models\PersonnelTableEvent;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class PersonnelTableExporter
{
    /** @param Collection<int, Employee> $employees @param list<string> $columns */
    public function export(User $actor, PersonnelTable $table, Collection $employees, array $columns, string $format, string $scope): StreamedResponse
    {
        $table = PersonnelTable::query()->findOrFail($table->getKey());
        $service = app(PersonnelTableService::class);
        $service->authorize($actor, $table, 'export');
        if (! in_array($format, ['csv', 'xlsx', 'pdf'], true)
            || ! in_array($scope, ['all', 'filtered', 'selected'], true)
            || count($columns) < 1 || count($columns) > 50 || count($columns) !== count(array_unique($columns))
            || $employees->count() > 5000) {
            throw ValidationException::withMessages(['export' => 'Choose a supported export, 1 to 50 columns, and no more than 5,000 members.']);
        }

        $source = app(PersonnelSource::class);
        $definitions = [];
        foreach ($source->fields() as $key => $label) {
            $definitions[$key] = ['label' => $label, 'type' => 'source'];
        }
        foreach ($table->columns ?? [] as $column) {
            $definitions[$column['id']] = $column;
        }
        if (array_diff($columns, array_keys($definitions)) !== []) {
            throw ValidationException::withMessages(['export' => 'The selected columns changed. Reload the table before exporting.']);
        }

        // Re-read every employee from the authorized membership; never export a caller-supplied model's attributes.
        $ids = $employees->modelKeys();
        $authorized = $service->members($actor, $table)->whereIn('employees.id', $ids)->get()->keyBy('id');
        if ($authorized->count() !== count(array_unique($ids))) {
            abort(403);
        }
        $entries = PersonnelTableEntry::query()->where('table_id', $table->getKey())
            ->whereIn('employee_id', $ids)->get()->keyBy('employee_id');
        $headers = array_map(fn (string $key): string => (string) $definitions[$key]['label'], $columns);
        $rows = [];
        $emailStatuses = in_array('email_match_status', $columns, true) && PersonnelWorkbookReconciler::canView($actor)
            ? app(PersonnelWorkbookReconciler::class)->latestEmailStatuses($actor) : [];
        foreach ($ids as $id) {
            $employee = $authorized->get($id);
            $values = $entries->get($id)->values ?? [];
            $row = [];
            foreach ($columns as $key) {
                $definition = $definitions[$key];
                $value = $definition['type'] === 'source'
                    ? ($key === 'email_match_status' ? ($emailStatuses[$id] ?? null) : $source->value($employee, $key))
                    : ($values[$key] ?? null);
                $row[] = match (true) {
                    $definition['type'] === 'checkbox' => $value === null ? null : (bool) $value,
                    $definition['type'] === 'number' && $value !== null => (float) $value,
                    is_array($value) => implode('; ', $value),
                    default => $value === null ? '' : (string) $value,
                };
            }
            $rows[] = $row;
        }

        $title = (string) $table->name;
        $generated = now('America/New_York')->format('M j, Y g:i A T');
        $bytes = match ($format) {
            'csv' => $this->csv($headers, $rows),
            'xlsx' => $this->xlsx($title, $headers, $rows, $scope, $generated),
            'pdf' => Pdf::loadView('pdf.personnel-table', compact('title', 'headers', 'rows', 'scope', 'generated'))
                ->setPaper('letter', 'landscape')->output(),
        };
        PersonnelTableEvent::query()->create(['table_id' => $table->getKey(), 'actor_user_id' => $actor->getKey(),
            'action' => 'export_generated', 'metadata' => ['format' => $format, 'scope' => $scope, 'record_count' => count($rows), 'columns' => $columns]]);
        $name = 'MBFD - '.Str::limit(Str::slug($title, ' '), 90, '').' - '.now('America/New_York')->format('Y-m-d').'.'.$format;

        return response()->streamDownload(static function () use ($bytes): void {
            echo $bytes;
        }, $name, [
            'Content-Type' => match ($format) {
                'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'pdf' => 'application/pdf',
                default => 'text/csv; charset=UTF-8',
            },
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function csv(array $headers, array $rows): string
    {
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, "\xEF\xBB\xBF");
        foreach ([$headers, ...$rows] as $row) {
            fputcsv($stream, array_map(static function (mixed $value): mixed {
                if (is_bool($value)) {
                    return $value ? 'Yes' : 'No';
                }
                if (is_string($value) && (preg_match('/^[\s\x00-\x1F]*[=+\-@＝＋－＠]/u', $value) === 1 || preg_match('/^[\t\r\n]/', $value) === 1)) {
                    return "'".$value;
                }

                return $value;
            }, $row), ',', '"', '', "\r\n");
        }
        rewind($stream);
        $bytes = stream_get_contents($stream);
        fclose($stream);

        return $bytes;
    }

    private function xlsx(string $title, array $headers, array $rows, string $scope, string $generated): string
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('Personnel');
        foreach ([$headers, ...$rows] as $rowIndex => $row) {
            foreach ($row as $index => $value) {
                $type = is_bool($value) ? DataType::TYPE_BOOL : (is_float($value) || is_int($value) ? DataType::TYPE_NUMERIC : DataType::TYPE_STRING);
                $sheet->setCellValueExplicit([$index + 1, $rowIndex + 1], $value ?? '', $type);
            }
        }
        $lastColumn = $sheet->getHighestColumn();
        $sheet->getStyle('A1:'.$lastColumn.'1')->getFont()->setBold(true);
        $sheet->getStyle('A1:'.$lastColumn.max(1, count($rows) + 1))->getAlignment()->setWrapText(true);
        $sheet->freezePane('A2');
        $sheet->setAutoFilter('A1:'.$lastColumn.max(1, count($rows) + 1));
        foreach ($headers as $index => $header) {
            $sheet->getColumnDimensionByColumn($index + 1)->setWidth(min(42, max(18, mb_strlen($header) + 2)));
        }
        $about = $book->createSheet();
        $about->setTitle('About this export');
        foreach ([['Title', $title], ['Scope', $scope], ['Generated (ET)', $generated], ['Record count', (string) count($rows)]] as $index => [$label, $value]) {
            $about->setCellValueExplicit([1, $index + 1], $label, DataType::TYPE_STRING);
            $about->setCellValueExplicit([2, $index + 1], $value, DataType::TYPE_STRING);
        }
        $about->getColumnDimension('A')->setWidth(22);
        $about->getColumnDimension('B')->setWidth(72);
        $book->setActiveSheetIndex(0);
        $stream = fopen('php://temp', 'w+');
        (new Xlsx($book))->save($stream);
        rewind($stream);
        $bytes = stream_get_contents($stream);
        fclose($stream);
        $book->disconnectWorksheets();

        return $bytes;
    }
}
