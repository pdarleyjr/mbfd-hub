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

/** Renders an independently revalidated proposal; never reads or writes a domain model. */
final class TrainingCrewPlanExporter
{
    public const LEGEND = 'Planning proposal only; not a TeleStaff deployment approval, sign-in roster, or attendance record.';

    public function pdf(array $projection): string
    {
        $report = $this->report($projection);

        return Pdf::loadView('training.crew-planner-proposal', ['report' => $report])
            ->setPaper('letter')
            ->setOptions(['isRemoteEnabled' => false, 'isPhpEnabled' => false, 'isJavascriptEnabled' => false])
            ->output();
    }

    public function xlsx(array $projection): string
    {
        $report = $this->report($projection);
        $book = new Spreadsheet;
        try {
            $summary = $book->getActiveSheet();
            $summary->setTitle('Summary');
            $this->write($summary, [
                ['MBFD TRAINING - PROPOSED CLASS ATTENDANCE'],
                ['For Battalion Chief 300 review'],
                ['Course', $report['course_name']],
                ['Scope', $report['scope_label']],
                ['Training date', $report['schedule_date']],
                ['Roster shift', $report['shift']],
                ['Schedule generated', $report['printed_at']],
                ['Proposal prepared', $report['generated_at']],
                ['Prepared by', $report['author']],
                ['Unique proposed members', $report['total']],
                ['Assigned and eligible', $report['eligible_total']],
                ['Needs verification', $report['review_total']],
                ['Unassigned', $report['unassigned_total']],
                ['Meaning', self::LEGEND],
                ['Availability', 'Scheduled duty based on an uploaded snapshot; confirm current operational availability.'],
                ['Time zone', 'America/New_York'],
                [],
                ['Class slot', 'Time (Eastern)', 'Proposed members'],
                ...array_map(static fn (array $group): array => [$group['label'], $group['time'], count($group['rows'])], $report['groups']),
            ], false);
            $names = ['summary'];
            foreach ($report['groups'] as $index => $group) {
                $sheet = $book->createSheet();
                $sheet->setTitle($this->sheetName($group['label'], $index, $names));
                $this->write($sheet, [
                    ['For Battalion Chief 300 review - proposal only'],
                    [$report['course_name'], $report['scope_label']],
                    [$group['label'], $group['time'], count($group['rows']).' proposed'],
                    ['Name', 'Employee ID', 'Rank', 'Published bid seat', 'Duty station', 'Duty apparatus', 'Planning status'],
                    ...array_map(static fn (array $row): array => [
                        $row['name'], $row['employee_id'], $row['rank'], $row['bid_position'],
                        $row['station'], $row['apparatus'], $row['status'],
                    ], $group['rows']),
                ], true);
            }
            $book->setActiveSheetIndex(0);
            $stream = fopen('php://temp/maxmemory:8388608', 'w+b');
            if ($stream === false) {
                throw new \RuntimeException('Unable to prepare the planning workbook.');
            }
            try {
                (new Xlsx($book))->save($stream);
                rewind($stream);
                $bytes = stream_get_contents($stream);
                if ($bytes === false) {
                    throw new \RuntimeException('Unable to read the planning workbook.');
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
            throw ValidationException::withMessages(['export' => 'Use at most 20 class slots and 1,000 proposed members.']);
        }
        $groups = [];
        foreach ($slots as $slot) {
            $id = (string) ($slot['id'] ?? '');
            if ($id === '' || isset($groups[$id])) {
                throw ValidationException::withMessages(['export' => 'Each class slot must have a unique identifier.']);
            }
            $groups[$id] = ['label' => (string) $slot['label'], 'time' => $this->time($slot), 'rows' => []];
        }
        $unassigned = ['label' => 'Unassigned', 'time' => 'Class slot not assigned', 'rows' => []];
        $review = ['label' => 'Needs verification', 'time' => 'Chief confirmation required before scheduling', 'rows' => []];
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
                $safe['status'] = 'Requires verification'.(isset($groups[$slotId]) ? ' - proposed '.$groups[$slotId]['label'] : '');
                $review['rows'][] = $safe;
            } elseif ($slotId === '' || ! isset($groups[$slotId])) {
                $safe['status'] = 'Unassigned';
                $unassigned['rows'][] = $safe;
            } else {
                $safe['status'] = 'Scheduled duty covers class slot';
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
        foreach (['course_name', 'scope_label', 'schedule_date', 'shift', 'printed_at', 'generated_at', 'author'] as $field) {
            $report[$field] = (string) ($projection[$field] ?? '');
        }
        $report['printed_at'] = $report['printed_at'] !== '' ? $report['printed_at'] : 'Not recorded in source PDF';
        if (! empty($projection['module_name'])) {
            $report['scope_label'] = (string) $projection['module_name'].' - '.$report['scope_label'];
        }

        return $report + [
            'groups' => $groups, 'total' => count($seen), 'eligible_total' => $eligible,
            'review_total' => count($review['rows']), 'unassigned_total' => count($unassigned['rows']),
            'legend' => self::LEGEND,
        ];
    }

    private function time(array $slot): string
    {
        try {
            $start = CarbonImmutable::parse((string) $slot['start_at'], 'America/New_York')->setTimezone('America/New_York');
            $end = CarbonImmutable::parse((string) $slot['end_at'], 'America/New_York')->setTimezone('America/New_York');
        } catch (\Throwable) {
            throw ValidationException::withMessages(['export' => 'Complete each class slot start and end time before export.']);
        }
        if ($end->lessThanOrEqualTo($start)) {
            throw ValidationException::withMessages(['export' => 'Each class slot must end after it starts.']);
        }

        return $start->format('H:i').' - '.$end->format($end->isSameDay($start) ? 'H:i T' : 'M j H:i T');
    }

    private function sheetName(string $label, int $index, array &$names): string
    {
        $name = trim((string) preg_replace('/[\\\\\/\?\*\[\]:\x00-\x1F]/u', ' ', $label), " '\t\n\r");
        $name = mb_substr($name !== '' ? $name : 'Class slot', 0, 25);
        if (in_array(mb_strtolower($name), $names, true)) {
            $name .= ' '.($index + 1);
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
        $sheet->getHeaderFooter()->setOddFooter('&LProposal only - Battalion Chief 300 review&RPage &P of &N');
    }
}
