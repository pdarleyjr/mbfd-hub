<?php

declare(strict_types=1);

namespace App\Services\Personnel\Promotions;

use App\Models\PromotionalList;
use App\Models\PromotionalListEntry;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Excel and PDF export of one promotional list. Text is always written as text (never as a
 * formula); only the rank columns and genuinely numeric scores are typed numbers.
 */
final class PromotionalListExporter
{
    public function __construct(private readonly PromotionalListAccess $access, private readonly PromotionalListService $service) {}

    public function export(User $actor, PromotionalList $list, string $format): StreamedResponse
    {
        $this->access->authorizeExport($actor);
        if (! in_array($format, ['xlsx', 'pdf'], true)) {
            throw ValidationException::withMessages(['format' => 'Choose Excel or PDF.']);
        }
        $list = PromotionalList::query()->findOrFail($list->getKey());
        $entries = $list->entries()->with('employee:id,employee_id')->orderBy('original_rank')->get();
        $headers = $this->headers();
        $rows = $entries->map(fn (PromotionalListEntry $entry): array => $this->row($entry))->all();
        $generated = now('America/New_York')->format('M j, Y g:i A T');
        $about = [
            'Title' => $list->title, 'List type' => $list->typeName(), 'Effective date' => $list->effective_date->toDateString(),
            'Expires on' => $list->expires_on?->toDateString() ?? 'No expiry date', 'Status' => $list->statusLabel(),
            'Source document' => $list->source_reference, 'Generated (ET)' => $generated, 'Entries' => (string) count($rows),
        ];
        $bytes = $format === 'xlsx'
            ? $this->xlsx($headers, $rows, $about)
            : Pdf::loadView('pdf.promotional-list', ['title' => $list->title, 'about' => $about, 'headers' => $headers, 'rows' => $rows])
                ->setPaper('letter', 'landscape')->output();
        $this->service->recordEvent($actor, $list, 'exported', null, ['format' => $format, 'entries' => count($rows)]);
        $name = 'MBFD - Promotional list - '.Str::limit(Str::slug($list->title, ' '), 80, '').' - '.now('America/New_York')->format('Y-m-d').'.'.$format;

        return response()->streamDownload(static function () use ($bytes): void {
            echo $bytes;
        }, $name, [
            'Content-Type' => $format === 'xlsx' ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' : 'application/pdf',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** @return list<string> */
    public function headers(): array
    {
        return ['Current rank', 'Original rank', 'SOI ID', 'Last name', 'First name', 'Status', 'Status note', 'Employee ID',
            ...array_values((array) config('promotional_lists.score_columns'))];
    }

    /** @return list<int|float|string|null> */
    private function row(PromotionalListEntry $entry): array
    {
        $scores = (array) $entry->scores;
        $row = [$entry->current_rank, $entry->original_rank, (string) $entry->soi_id, $entry->last_name, $entry->first_name,
            $entry->statusLabel(), (string) $entry->status_reason, (string) ($entry->employee->employee_id ?? '')];
        foreach (array_keys((array) config('promotional_lists.score_columns')) as $key) {
            $row[] = (string) ($scores[$key] ?? '');
        }

        return $row;
    }

    /**
     * @param  list<string>  $headers
     * @param  list<list<int|float|string|null>>  $rows
     * @param  array<string, string>  $about
     */
    private function xlsx(array $headers, array $rows, array $about): string
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('Promotional list');
        foreach ([$headers, ...$rows] as $rowIndex => $row) {
            foreach ($row as $index => $value) {
                $cell = [$index + 1, $rowIndex + 1];
                if ($rowIndex === 0 || $value === null || $value === '') {
                    $sheet->setCellValueExplicit($cell, (string) ($value ?? ''), DataType::TYPE_STRING);
                } elseif ($index < 2) {
                    $sheet->setCellValueExplicit($cell, (int) $value, DataType::TYPE_NUMERIC);
                } elseif ($index >= 8 && preg_match('/^-?\d+(\.\d+)?$/', (string) $value) === 1) {
                    $sheet->setCellValueExplicit($cell, (float) $value, DataType::TYPE_NUMERIC);
                    $sheet->getStyle($cell)->getNumberFormat()->setFormatCode('0.00');
                } else {
                    $sheet->setCellValueExplicit($cell, (string) $value, DataType::TYPE_STRING);
                }
            }
        }
        $last = $sheet->getHighestColumn();
        $sheet->getStyle('A1:'.$last.'1')->getFont()->setBold(true);
        $sheet->freezePane('A2');
        $sheet->setAutoFilter('A1:'.$last.max(1, count($rows) + 1));
        foreach ($headers as $index => $header) {
            $sheet->getColumnDimensionByColumn($index + 1)->setWidth(min(34, max(12, mb_strlen($header) + 3)));
        }
        $info = $book->createSheet();
        $info->setTitle('About this export');
        $line = 1;
        foreach ($about as $label => $value) {
            $info->setCellValueExplicit([1, $line], $label, DataType::TYPE_STRING);
            $info->setCellValueExplicit([2, $line], $value, DataType::TYPE_STRING);
            $line++;
        }
        $info->getColumnDimension('A')->setWidth(20);
        $info->getColumnDimension('B')->setWidth(80);
        $book->setActiveSheetIndex(0);
        $stream = fopen('php://temp', 'w+');
        (new Xlsx($book))->save($stream);
        rewind($stream);
        $bytes = (string) stream_get_contents($stream);
        fclose($stream);
        $book->disconnectWorksheets();

        return $bytes;
    }
}
