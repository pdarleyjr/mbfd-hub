<?php

declare(strict_types=1);

namespace App\Services\Training;

use App\Models\Training\TrainingRosterVersion;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\MemoryDrawing;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

final class TrainingRosterExportService
{
    public const CLASSIFICATIONS = [
        'Task Level/Company', 'Special Ops', 'Marine Training', 'SIMM Training',
        'Officer Training', 'RIT/MD/Survival', 'Water Rescue', 'EMS Training',
        'ICS / NIMS / FFOG', 'Hazmat Training', 'Fire Boat Operation', 'Pre-Fire/Prevention',
        'Driver Training', 'Extrication', 'Night Drill', 'Equipment Inservice',
    ];

    public function download(TrainingRosterVersion $version, string $format): Response
    {
        $bytes = $this->bytes($version, $format);
        $id = $version->snapshot['session']['public_id'];
        $course = Str::limit(Str::slug($version->snapshot['session']['course_name']), 80, '');
        $date = CarbonImmutable::parse($version->snapshot['session']['held_on'])->format('Y-m-d');
        $filename = 'MBFD-Training-'.$course.'-'.$date.'-'.$id.'-r'.$version->revision.'.'.$format;

        return response($bytes, 200, [
            'Content-Type' => $format === 'pdf' ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function bytes(TrainingRosterVersion $version, string $format): string
    {
        if (! in_array($format, ['pdf', 'xlsx'], true)) {
            abort(404);
        }

        $attribute = $format.'_artifact';
        if ($version->{$attribute}) {
            return $this->verifiedBytes($version->{$attribute});
        }

        $data = $this->renderData($version);
        $bytes = $format === 'pdf' ? $this->pdf($data) : $this->xlsx($data);
        $disk = (string) config('filesystems.private', 'local');
        $path = 'training/rosters/'.$version->training_session_id.'/'.$version->revision.'/'.Str::ulid().'.'.$format;
        $artifact = ['disk' => $disk, 'path' => $path, 'sha256' => hash('sha256', $bytes), 'mime' => $format === 'pdf' ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
        if (! Storage::disk($disk)->put($path, $bytes, ['visibility' => 'private'])) {
            throw new RuntimeException('The roster export could not be stored. Please try again.');
        }

        try {
            $this->verifiedBytes($artifact);

            return DB::transaction(function () use ($version, $attribute, $artifact, $bytes, $disk, $path): string {
                $locked = TrainingRosterVersion::query()->lockForUpdate()->findOrFail($version->getKey());
                if ($locked->{$attribute}) {
                    Storage::disk($disk)->delete($path);

                    return $this->verifiedBytes($locked->{$attribute});
                }
                $locked->{$attribute} = $artifact;
                $locked->save();
                $version->{$attribute} = $artifact;

                return $bytes;
            });
        } catch (\Throwable $exception) {
            Storage::disk($disk)->delete($path);
            throw $exception;
        }
    }

    /** @param array{disk:string,path:string,sha256:string} $file */
    private function verifiedBytes(array $file): string
    {
        $bytes = Storage::disk($file['disk'])->get($file['path']);
        if (! is_string($bytes) || ! hash_equals($file['sha256'], hash('sha256', $bytes))) {
            throw new RuntimeException('Roster evidence failed its integrity check. Contact Training administration.');
        }

        return $bytes;
    }

    /** @return array<string,mixed> */
    private function renderData(TrainingRosterVersion $version): array
    {
        $snapshot = $version->snapshot;
        $rows = [];
        foreach ($snapshot['attendance'] as $index => $entry) {
            $signature = $this->verifiedBytes($entry['signature']);
            $signatureSignedAt = $entry['signature_signed_at'] ?? $entry['signed_at'];
            $rows[] = [
                ...$entry,
                'number' => $index + 1,
                'signature_bytes' => $signature,
                'signature_url' => 'data:image/png;base64,'.base64_encode($signature),
                'signature_signed_at' => $signatureSignedAt,
                'date' => CarbonImmutable::parse($signatureSignedAt)->setTimezone('America/New_York')->format('m/d/Y'),
                'time' => CarbonImmutable::parse($signatureSignedAt)->setTimezone('America/New_York')->format('H:i:s T'),
            ];
        }

        $certification = $snapshot['certification'] ?? null;
        if ($certification !== null) {
            $certification['signature_bytes'] = $this->verifiedBytes($certification['oic_signature']);
            $certification['signature_url'] = 'data:image/png;base64,'.base64_encode($certification['signature_bytes']);
            $certification['signed_at_local'] = CarbonImmutable::parse($certification['certified_at'])->setTimezone('America/New_York')->format('m/d/Y H:i:s T');
        }
        $submission = $snapshot['submission'] ?? null;
        $completion = $certification !== null ? 'Certified' : ($submission !== null ? 'Submitted' : 'Provisional');
        $custom = array_values(array_diff($snapshot['session']['classifications'], self::CLASSIFICATIONS));
        $customInline = mb_strlen(implode('; ', $custom)) <= 24 ? implode('; ', array_map(fn (string $label): string => '[X] '.$label, $custom)) : '';
        $customColumns = $custom === [] ? [] : array_chunk($custom, (int) ceil(count($custom) / 2));
        $customHeight = $custom === [] ? 0 : 4 + 9 * (1 + max(array_map(
            fn (array $labels): int => (int) array_sum(array_map(fn (string $label): int => (int) ceil((mb_strlen($label) + 4) / 22), $labels)),
            $customColumns,
        )));

        $logo = $this->logoBytes();
        $longestName = max([0, ...array_map(fn (array $row): int => max(mb_strlen($row['snapshot']['first_name']), mb_strlen($row['snapshot']['last_name'])), $rows)]);
        // Reserve enough vertical space even for wide, unbroken names. Ordinary
        // rosters retain the original 16 rows; long names get complete taller rows.
        $rowHeight = $longestName > 28 ? ceil(($longestName + 4) / 14) * 9.6 : 18;
        $rowsPerPage = $longestName > 28 ? max(1, min(16, (int) floor(280 / ($rowHeight + 5)))) : 16;
        if (max(mb_strlen($snapshot['session']['course_name']), mb_strlen($snapshot['session']['location'] ?? '')) > 120) {
            $rowsPerPage = min($rowsPerPage, 12);
        }
        if ($custom !== [] && $customInline === '') {
            $rowsPerPage = max(1, $rowsPerPage - (int) ceil(($customHeight + 10) / max(25, $rowHeight + 5)));
        }

        return [
            'session' => $snapshot['session'],
            'rows' => $rows,
            'pages' => $rows === [] ? [[]] : array_chunk($rows, $rowsPerPage),
            'rows_per_page' => $rowsPerPage,
            'row_height' => $rowHeight,
            'certification' => $certification,
            'submission' => $submission,
            'completion' => $completion,
            'completion_label' => $completion === 'Provisional' ? 'PROVISIONAL — NOT CERTIFIED' : strtoupper($completion),
            'classifications' => self::CLASSIFICATIONS,
            'custom_classification_columns' => $customColumns,
            'custom_classification_height' => $customHeight,
            'custom_classifications_inline' => $customInline,
            'revision' => $version->revision,
            'captured_at' => $snapshot['captured_at'],
            'logo_bytes' => $logo,
            'logo' => 'data:image/png;base64,'.base64_encode($logo),
        ];
    }

    private function logoBytes(): string
    {
        $source = imagecreatefrompng(public_path('images/training/mbfd-logo.png'));
        if ($source === false) {
            throw new RuntimeException('The Training roster logo could not be loaded.');
        }
        // 256 pixels retains over 300 dpi at the printed size without embedding
        // the full-resolution transparent asset into every export renderer.
        $height = (int) round(256 * imagesy($source) / imagesx($source));
        $thumbnail = imagecreatetruecolor(256, $height);
        if ($thumbnail === false) {
            throw new RuntimeException('The Training roster logo could not be prepared.');
        }
        imagealphablending($thumbnail, false);
        imagesavealpha($thumbnail, true);
        imagecopyresampled($thumbnail, $source, 0, 0, 0, 0, 256, $height, imagesx($source), imagesy($source));
        ob_start();
        imagepng($thumbnail);

        return (string) ob_get_clean();
    }

    /** @param array<string,mixed> $data */
    private function pdf(array $data): string
    {
        return Pdf::loadView('training.roster-pdf', $data)
            ->setPaper('letter', 'portrait')
            ->setOptions(['isRemoteEnabled' => false, 'isPhpEnabled' => false, 'defaultFont' => 'DejaVu Sans'])
            ->output();
    }

    /** @param array<string,mixed> $data */
    private function xlsx(array $data): string
    {
        $book = new Spreadsheet;
        $book->getProperties()->setCreator('Miami Beach Fire Department')->setTitle('MBFD Training Roster');
        $print = $book->getActiveSheet()->setTitle('Printable roster');
        $print->setShowGridlines(false);
        $book->getDefaultStyle()->getFont()->setName('Arial')->setSize(10);
        foreach (['A' => 7, 'B' => 20, 'C' => 20, 'D' => 13, 'E' => 25] as $column => $width) {
            $print->getColumnDimension($column)->setWidth($width);
        }
        foreach ($data['pages'] as $pageIndex => $rows) {
            $start = $pageIndex * ($data['rows_per_page'] + 16) + 1;
            if ($pageIndex > 0) {
                $print->setBreak('A'.($start - 1), Worksheet::BREAK_ROW);
            }
            $print->mergeCells('B'.$start.':E'.($start + 1));
            $this->text($print, 'B'.$start, 'MBFD TRAINING ROSTER');
            $print->getStyle('B'.$start)->getFont()->setBold(true)->setSize(19);
            $this->drawing($print, $data['logo_bytes'], 'A'.$start, 'MBFD logo', 45, 45);
            if ($data['custom_classification_columns'] !== []) {
                $customRow = $start + 2;
                foreach (['A' => 'C', 'D' => 'E'] as $first => $last) {
                    $print->mergeCells($first.$customRow.':'.$last.$customRow);
                    $labels = $data['custom_classification_columns'][$first === 'A' ? 0 : 1] ?? [];
                    $this->text($print, $first.$customRow, ($first === 'A' ? 'Additional selected classifications:' : '')."\n".implode("\n", array_map(fn (string $label): string => '[X] '.$label, $labels)));
                    $print->getStyle($first.$customRow)->getAlignment()->setWrapText(true)->setVertical('top');
                    $print->getStyle($first.$customRow)->getFont()->setSize(7);
                }
                $print->getRowDimension($customRow)->setRowHeight($data['custom_classification_height']);
            }
            foreach (array_chunk(self::CLASSIFICATIONS, 4) as $classificationRow => $labels) {
                $value = implode('   ', array_map(fn (string $label): string => (in_array($label, $data['session']['classifications'], true) ? '[X] ' : '[ ] ').$label, $labels));
                $row = $start + 3 + $classificationRow;
                $print->mergeCells('A'.$row.':E'.$row);
                $this->text($print, 'A'.$row, $value);
                $print->getStyle('A'.$row)->getFont()->setSize(8);
            }
            $courseRow = $start + 7;
            $print->mergeCells('A'.$courseRow.':E'.$courseRow);
            $this->text($print, 'A'.$courseRow, 'COURSE NAME: '.$data['session']['course_name']);
            $print->getStyle('A'.$courseRow)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFFF00');
            $print->getStyle('A'.$courseRow)->getAlignment()->setWrapText(true);
            $print->getRowDimension($courseRow)->setRowHeight(max(42, ceil((mb_strlen($data['session']['course_name']) + 13) / 45) * 12 + 8));
            $metaRow = $start + 8;
            $print->mergeCells('A'.$metaRow.':E'.$metaRow);
            $this->text($print, 'A'.$metaRow, $data['session']['held_on'].' | '.$data['completion_label'].' | Revision '.$data['revision'].' | '.count($data['rows']).' attendees'.(($data['session']['location'] ?? null) ? "\n".$data['session']['location'] : ''));
            $print->getStyle('A'.$metaRow)->getAlignment()->setWrapText(true);
            $print->getRowDimension($metaRow)->setRowHeight(($data['session']['location'] ?? null) ? max(50, ceil(mb_strlen($data['session']['location']) / 45) * 12 + 17) : 17);
            $header = $start + 9;
            foreach (['A' => '#', 'B' => 'Last Name', 'C' => 'First Name', 'D' => 'DATE', 'E' => 'SIGNATURE'] as $column => $label) {
                $this->text($print, $column.$header, $label);
            }
            $print->getStyle('A'.$header.':E'.$header)->getFont()->setBold(true);
            for ($i = 0; $i < $data['rows_per_page']; $i++) {
                $row = $header + 1 + $i;
                $print->getRowDimension($row)->setRowHeight(max(25, $data['row_height'] + 5));
                if (isset($rows[$i])) {
                    $entry = $rows[$i];
                    $print->setCellValue('A'.$row, $entry['number']);
                    $this->text($print, 'B'.$row, $entry['snapshot']['last_name']);
                    $this->text($print, 'C'.$row, $entry['snapshot']['first_name']);
                    $this->text($print, 'D'.$row, $entry['date']."\n".$entry['time']);
                    $print->getStyle('D'.$row)->getFont()->setSize(8);
                    $this->drawing($print, $entry['signature_bytes'], 'E'.$row, 'Signature '.$entry['number'], 150, 29);
                }
            }
            $print->getStyle('A'.$header.':E'.($header + $data['rows_per_page']))->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
            $print->getStyle('A'.$header.':E'.($header + $data['rows_per_page']))->getAlignment()->setWrapText(true)->setVertical('center');
            $print->getStyle('B'.($header + 1).':C'.($header + $data['rows_per_page']))->getFont()->setSize($data['rows_per_page'] < 16 ? 8 : 9);
            $oicRow = $start + $data['rows_per_page'] + 11;
            $print->mergeCells('A'.$oicRow.':D'.$oicRow);
            $cert = $data['certification'];
            $this->text($print, 'A'.$oicRow, 'OIC NAME (PRINT): '.($cert['oic_snapshot']['display_name'] ?? '').'   ID# '.($cert['oic_snapshot']['employee_id'] ?? ''));
            $print->getStyle('A'.$oicRow)->getAlignment()->setWrapText(true);
            $print->getRowDimension($oicRow)->setRowHeight(30);
            if ($cert) {
                $this->drawing($print, $cert['signature_bytes'], 'E'.$oicRow, 'OIC signature', 150, 30);
                $this->text($print, 'E'.($oicRow + 1), $cert['signed_at_local']);
                $print->getStyle('E'.($oicRow + 1))->getFont()->setSize(7);
            } else {
                $this->text($print, 'E'.$oicRow, 'OIC SIGNATURE:');
            }
            $print->mergeCells('A'.($oicRow + 2).':E'.($oicRow + 2));
            $this->text($print, 'A'.($oicRow + 2), $data['session']['public_id'].' | '.$data['session']['status'].' | '.$data['captured_at'].' | Page '.($pageIndex + 1).' of '.count($data['pages']));
            $print->getStyle('A'.($oicRow + 2))->getFont()->setSize(8);
            if ($data['submission']) {
                $print->mergeCells('A'.($oicRow + 3).':E'.($oicRow + 3));
                $this->text($print, 'A'.($oicRow + 3), 'Submitted '.$data['submission']['submitted_at']);
                $print->getStyle('A'.($oicRow + 3))->getFont()->setSize(8);
            }
        }
        $print->getPageSetup()->setOrientation(PageSetup::ORIENTATION_PORTRAIT)->setPaperSize(PageSetup::PAPERSIZE_LETTER)->setFitToWidth(1)->setFitToHeight(0)->setPrintArea('A1:E'.(count($data['pages']) * ($data['rows_per_page'] + 16)));
        $print->getPageMargins()->setTop(0.35)->setBottom(0.35)->setLeft(0.3)->setRight(0.3);

        $table = $book->createSheet()->setTitle('Attendance data');
        $headers = ['Roster ID', 'Revision', 'Course', 'Session date', 'Classifications', 'Status', 'Number', 'Employee ID', 'Last name', 'First name', 'Rank', 'Shift', 'Bid term', 'Assignment source', 'Assignment ID', 'Assignment as of', 'Signed at (UTC)', 'Signature SHA256', 'Completion', 'OIC name', 'OIC employee ID', 'Certified at (UTC)', 'OIC signature SHA256', 'Location', 'Starts at (UTC)', 'Snapshot captured at (UTC)', 'Submitted at (UTC)', 'Signature signed at (UTC)'];
        $table->fromArray($headers, null, 'A1');
        foreach ($data['rows'] as $index => $entry) {
            $person = $entry['snapshot'];
            $cert = $data['certification'];
            $values = [$data['session']['public_id'], (string) $data['revision'], $data['session']['course_name'], $data['session']['held_on'], implode('; ', $data['session']['classifications']), $data['session']['status'], (string) $entry['number'], $person['employee_id'], $person['last_name'], $person['first_name'], $person['rank'] ?? '', $person['shift'] ?? '', $person['term_label'] ?? '', $person['assignment_source'] ?? '', (string) ($person['assignment_id'] ?? ''), $person['as_of'] ?? '', $entry['signed_at'], $entry['signature']['sha256'], $data['completion'], $cert['oic_snapshot']['display_name'] ?? '', $cert['oic_snapshot']['employee_id'] ?? '', $cert['certified_at'] ?? '', $cert['oic_signature']['sha256'] ?? '', $data['session']['location'] ?? '', $data['session']['starts_at'] ?? '', $data['captured_at'], $data['submission']['submitted_at'] ?? '', $entry['signature_signed_at']];
            foreach ($values as $column => $value) {
                $table->getCell([$column + 1, $index + 2])->setValueExplicit((string) $value, DataType::TYPE_STRING);
            }
        }
        $table->freezePane('A2')->setAutoFilter('A1:AB'.max(1, count($data['rows']) + 1));
        $table->getStyle('A1:AB1')->getFont()->setBold(true);
        foreach ([...range('A', 'Z'), 'AA', 'AB'] as $column) {
            $table->getColumnDimension($column)->setWidth(22);
        }
        $book->setActiveSheetIndex(0);
        $stream = fopen('php://temp', 'w+b');
        try {
            (new Xlsx($book))->save($stream);
            rewind($stream);

            return (string) stream_get_contents($stream);
        } finally {
            fclose($stream);
            $book->disconnectWorksheets();
        }
    }

    private function text(Worksheet $sheet, string $cell, string $value): void
    {
        $sheet->setCellValueExplicit($cell, $value, DataType::TYPE_STRING);
    }

    private function drawing(Worksheet $sheet, string $bytes, string $cell, string $description, int $width, int $height): void
    {
        $drawing = MemoryDrawing::fromString($bytes);
        $drawing->setName($description)->setDescription($description)->setCoordinates($cell)->setOffsetX(4)->setOffsetY(2);
        $drawing->setWidthAndHeight($width, $height);
        $drawing->setWorksheet($sheet);
    }
}
