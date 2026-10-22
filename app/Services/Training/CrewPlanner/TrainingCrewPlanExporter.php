<?php

declare(strict_types=1);

namespace App\Services\Training\CrewPlanner;

use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/** Renders an independently revalidated personnel request; never reads or writes a domain model. */
final class TrainingCrewPlanExporter
{
    public const TITLE = 'TRAINING ATTENDANCE — PERSONNEL REQUEST';

    public const INTRO = 'The following personnel have not yet attended the training course or module identified below, according to current attendance records. Please coordinate their attendance at the designated session times, subject to operational staffing and coverage requirements.';

    public const LEGEND = 'Eligibility reflects the uploaded daily roster and Hub attendance records at the time generated.';

    public const VERIFY_NOTE = 'These entries need confirmation before scheduling.';

    private const LOGO_PATH = 'images/training/mbfd-logo.png';

    private const TZ = 'America/New_York';

    public function pdf(array $projection): string
    {
        $report = $this->report($projection);

        return Pdf::loadView('training.crew-planner-proposal', ['report' => $report, 'logo' => $this->logo()])
            ->setPaper('letter', 'portrait')
            ->setOptions(['isRemoteEnabled' => false, 'isPhpEnabled' => false, 'isJavascriptEnabled' => false, 'defaultFont' => 'DejaVu Sans'])
            ->output();
    }

    /**
     * Official logo, proportionally resampled to 256 px wide (alpha preserved) and embedded as a data URI.
     *
     * @return array{uri:string,width:int,height:int}
     */
    public function logo(): array
    {
        $source = @imagecreatefrompng(public_path(self::LOGO_PATH));
        if ($source === false) {
            throw new \RuntimeException('The MBFD logo could not be loaded.');
        }
        $height = max(1, (int) round(256 * imagesy($source) / imagesx($source)));
        $thumbnail = imagecreatetruecolor(256, $height);
        if ($thumbnail === false) {
            throw new \RuntimeException('The MBFD logo could not be prepared.');
        }
        imagealphablending($thumbnail, false);
        imagesavealpha($thumbnail, true);
        imagecopyresampled($thumbnail, $source, 0, 0, 0, 0, 256, $height, imagesx($source), imagesy($source));
        ob_start();
        imagepng($thumbnail);
        $bytes = (string) ob_get_clean();

        return ['uri' => 'data:image/png;base64,'.base64_encode($bytes), 'width' => 256, 'height' => $height];
    }

    public function xlsx(array $projection): string
    {
        $report = $this->report($projection);
        $book = new Spreadsheet;
        try {
            $summary = $book->getActiveSheet();
            $summary->setTitle('Summary');
            $sessions = array_values(array_filter($report['groups'], static fn (array $g): bool => $g['kind'] === 'session'));
            $this->write($summary, [
                ['MIAMI BEACH FIRE RESCUE'],
                [self::TITLE],
                ['Course', $report['course_name']],
                ['Module', $report['module_name']],
                ['Training date', $report['date_long']],
                ['Shift', $report['shift_label']],
                ['Generated', $report['generated_display']],
                ['Prepared by', $report['author']],
                ['Personnel listed', $report['total']],
                ['Assigned to a session', $report['eligible_total']],
                ['Needs verification', $report['review_total']],
                ['Unassigned', $report['unassigned_total']],
                ['Notes', self::INTRO],
                ['Eligibility', self::LEGEND],
                ['Time zone', 'America/New_York'],
                [],
                ['Session', 'Time (Eastern)', 'Personnel'],
                ...array_map(static fn (array $group): array => [$group['label'], $group['time'], count($group['rows'])], $sessions),
            ], false);
            $names = ['summary'];
            foreach ($report['groups'] as $index => $group) {
                $sheet = $book->createSheet();
                $sheet->setTitle($this->sheetName($group['label'], $index, $names));
                $this->write($sheet, [
                    [self::TITLE],
                    [$report['course_name'], $report['module_name'] !== '' ? $report['module_name'] : $report['scope_label']],
                    [$group['label'], $group['time'], count($group['rows']).' listed'],
                    ['Name', 'Employee ID', 'Rank', 'Published bid seat', 'Station', 'Unit', 'Status'],
                    ...array_map(static fn (array $row): array => [
                        $row['name'], $row['employee_id'], $row['rank'], $row['bid_position'],
                        $row['station'], $row['apparatus'], $row['status'],
                    ], $group['rows']),
                ], true);
            }
            $book->setActiveSheetIndex(0);
            $stream = fopen('php://temp/maxmemory:8388608', 'w+b');
            if ($stream === false) {
                throw new \RuntimeException('Unable to prepare the workbook.');
            }
            try {
                (new Xlsx($book))->save($stream);
                rewind($stream);
                $bytes = stream_get_contents($stream);
                if ($bytes === false) {
                    throw new \RuntimeException('Unable to read the workbook.');
                }

                return $bytes;
            } finally {
                fclose($stream);
            }
        } finally {
            $book->disconnectWorksheets();
        }
    }

    /** Both formats consume this same allowlisted projection, excluding private duty codes and notes. */
    public function report(array $projection): array
    {
        $slots = $projection['slots'] ?? [];
        $rows = $projection['rows'] ?? [];
        if (! is_array($slots) || ! is_array($rows) || count($slots) > 20 || count($rows) > 1000) {
            throw ValidationException::withMessages(['export' => 'Use at most 20 class slots and 1,000 listed members.']);
        }
        $groups = [];
        foreach ($slots as $slot) {
            $id = (string) ($slot['id'] ?? '');
            if ($id === '' || isset($groups[$id])) {
                throw ValidationException::withMessages(['export' => 'Each class slot must have a unique identifier.']);
            }
            $groups[$id] = ['kind' => 'session', 'label' => (string) ($slot['label'] ?? ''), 'time' => $this->time($slot), 'rows' => []];
        }
        $unassigned = ['kind' => 'unassigned', 'label' => 'Unassigned', 'time' => 'Session not assigned', 'rows' => []];
        $review = ['kind' => 'review', 'label' => 'Needs verification', 'time' => self::VERIFY_NOTE, 'rows' => []];
        $seen = [];
        foreach ($rows as $key => $row) {
            $identity = (string) ($row['employee_profile_id'] ?? $key);
            if (isset($seen[$identity])) {
                throw ValidationException::withMessages(['export' => 'A member appears more than once. Refresh the planning list.']);
            }
            $seen[$identity] = true;
            $safe = [];
            foreach (['name', 'employee_id', 'rank', 'bid_position', 'station', 'apparatus'] as $field) {
                $safe[$field] = (string) ($row[$field] ?? '');
            }
            $slotId = (string) ($row['slot_id'] ?? '');
            if (($row['verification_required'] ?? false) === true) {
                $safe['status'] = 'Needs verification'.(isset($groups[$slotId]) ? ' - '.$groups[$slotId]['label'] : '');
                $review['rows'][] = $safe;
            } elseif ($slotId === '' || ! isset($groups[$slotId])) {
                $safe['status'] = 'Unassigned';
                $unassigned['rows'][] = $safe;
            } else {
                $safe['status'] = 'On duty during session';
                $groups[$slotId]['rows'][] = $safe;
            }
        }
        $eligible = array_sum(array_map(static fn (array $group): int => count($group['rows']), $groups));
        $groups = [...array_values($groups), $unassigned, $review];
        foreach ($groups as &$group) {
            usort($group['rows'], static fn (array $a, array $b): int => [$a['station'], $a['apparatus'], $a['name']] <=> [$b['station'], $b['apparatus'], $b['name']]);
        }
        unset($group);
        $report = [];
        foreach (['course_name', 'module_name', 'scope_label', 'schedule_date', 'shift', 'printed_at', 'generated_at', 'author'] as $field) {
            $report[$field] = trim((string) ($projection[$field] ?? ''));
        }
        $shift = $report['shift'];
        $report['shift_label'] = $shift === '' ? '' : (preg_match('/shift$/i', $shift) === 1 ? $shift : $shift.' Shift');
        $report['date_long'] = $this->formatDate($report['schedule_date'], 'l, F j, Y');
        $report['generated_display'] = $this->formatDate($report['generated_at'], 'F j, Y g:i A T');

        return $report + [
            'groups' => $groups, 'total' => count($seen), 'eligible_total' => $eligible,
            'review_total' => count($review['rows']), 'unassigned_total' => count($unassigned['rows']),
            'legend' => self::LEGEND, 'title' => self::TITLE, 'intro' => self::INTRO, 'verify_note' => self::VERIFY_NOTE,
        ];
    }

    private function formatDate(string $value, string $format): string
    {
        if ($value === '') {
            return '';
        }
        try {
            return CarbonImmutable::parse($value, self::TZ)->setTimezone(self::TZ)->format($format);
        } catch (\Throwable) {
            return $value;
        }
    }

    private function time(array $slot): string
    {
        if (blank($slot['start_at'] ?? null) || blank($slot['end_at'] ?? null)) {
            throw ValidationException::withMessages(['export' => 'Complete each class slot start and end time before export.']);
        }
        try {
            $start = CarbonImmutable::parse((string) $slot['start_at'], self::TZ)->setTimezone(self::TZ);
            $end = CarbonImmutable::parse((string) $slot['end_at'], self::TZ)->setTimezone(self::TZ);
        } catch (\Throwable) {
            throw ValidationException::withMessages(['export' => 'Complete each class slot start and end time before export.']);
        }
        if ($end->lessThanOrEqualTo($start)) {
            throw ValidationException::withMessages(['export' => 'Each class slot must end after it starts.']);
        }

        return $start->format('H:i').'–'.$end->format($end->isSameDay($start) ? 'H:i' : 'M j H:i');
    }

    private function sheetName(string $label, int $index, array &$names): string
    {
        $name = trim((string) preg_replace('/[\\\\\/\?\*\[\]:\x00-\x1F]/u', ' ', $label), " '\t\n\r");
        $name = mb_substr($name !== '' ? $name : 'Class slot', 0, 25);
        $base = $name;
        $suffix = $index + 1;
        while (in_array(mb_strtolower($name), $names, true)) {
            $name = $base.' '.$suffix++;
        }
        $names[] = mb_strtolower($name);

        return $name;
    }

    private function write(Worksheet $sheet, array $rows, bool $table): void
    {
        foreach ($rows as $rowIndex => $values) {
            foreach ($values as $columnIndex => $value) {
                // Explicit text for every external value, including strings beginning = + - @.
                $sheet->setCellValueExplicit([$columnIndex + 1, $rowIndex + 1], (string) $value, DataType::TYPE_STRING);
            }
        }
        $last = max(count($rows), 4);
        $sheet->getStyle('A1:G'.$last)->getFont()->setName('Calibri')->setSize(11);
        $sheet->getStyle('A1:G'.$last)->getAlignment()->setWrapText(true)->setVertical('top');
        $sheet->getStyle('A1:G1')->getFont()->setBold(true)->setSize(14)->getColor()->setRGB('18354B');
        foreach (['A' => 28, 'B' => 18, 'C' => 20, 'D' => 27, 'E' => 16, 'F' => 24, 'G' => 38] as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }
        if ($table) {
            $sheet->getStyle('A4:G4')->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
            $sheet->getStyle('A4:G4')->getFill()->setFillType('solid')->getStartColor()->setRGB('18354B');
            $sheet->freezePane('C5');
            $sheet->setAutoFilter('A4:G'.$last);
            $sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd(1, 4);
        } else {
            $sheet->getColumnDimension('B')->setWidth(85);
        }
        $sheet->getPageSetup()->setOrientation('landscape')->setPaperSize(1)->setFitToWidth(1)->setFitToHeight(0)->setPrintArea('A1:G'.$last);
        $sheet->getHeaderFooter()->setOddFooter('&LGenerated by MBFD Hub&RPage &P of &N');
    }
}
