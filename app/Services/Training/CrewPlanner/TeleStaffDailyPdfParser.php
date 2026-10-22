<?php

declare(strict_types=1);

namespace App\Services\Training\CrewPlanner;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Smalot\PdfParser\Config;
use Smalot\PdfParser\Parser;

/** Geometry is deliberately restricted to the privately validated MBFD daily template. */
final class TeleStaffDailyPdfParser
{
    public const MAX_BYTES = 8388608;

    public const MAX_PAGES = 12;

    public const MAX_MARKUP_ANNOTATIONS = 200;

    public function parse(string $path): array
    {
        $size = is_file($path) ? filesize($path) : false;
        if ($size === false || $size < 8 || $size > self::MAX_BYTES) {
            throw new InvalidArgumentException('Upload a daily PDF smaller than 8 MB.');
        }
        $bytes = file_get_contents($path);
        if ($bytes === false || ! str_starts_with($bytes, '%PDF-')) {
            throw new InvalidArgumentException('The upload is not a valid PDF.');
        }
        // Reject action dictionaries, external resources, encryption and compressed
        // object dictionaries. The validated browser-print template requires none.
        $names = preg_replace_callback('/#([0-9a-f]{2})/i', fn (array $match): string => chr(hexdec($match[1])), $bytes);
        if (preg_match('/\/(?:JavaScript|JS|AA|OpenAction|Launch|EmbeddedFiles?|Filespec|RichMedia|AcroForm|XFA|SubmitForm|ImportData|GoToR|GoToE|Rendition|Sound|Movie|FileAttachment|URI|Encrypt|ObjStm)\b/', $names)) {
            throw new InvalidArgumentException('Use a plain, unprotected daily report PDF without interactive content.');
        }
        $rawObjects = preg_match_all('/\d+\s+\d+\s+obj\b/', $bytes);
        $structureObjects = preg_match_all('/\/Type\s*\/StructElem\b/', $names);
        // Officers highlight or note rows before uploading; each passive markup annotation
        // carries its own appearance stream, so allow one extra stream per annotation.
        $markup = preg_match_all('/\/Subtype\s*\/(?:Highlight|Underline|StrikeOut|Squiggly|Text|FreeText|Ink|Square|Circle|Popup)\b/', $names);
        if (preg_match_all('/\bstream\b/', $bytes) > 64 + min($markup, self::MAX_MARKUP_ANNOTATIONS) || $rawObjects > 12000 || $rawObjects - $structureObjects > 800) {
            throw new InvalidArgumentException('The daily report is too complex.');
        }
        $config = new Config;
        $config->setRetainImageContent(false);
        $config->setDecodeMemoryLimit(1048576);
        $started = microtime(true);
        try {
            $document = (new Parser([], $config))->parseContent($bytes);
            $pages = $document->getPages();
            if (count($pages) < 1 || count($pages) > self::MAX_PAGES || count($document->getObjects()) > 12000) {
                throw new InvalidArgumentException('The daily report has too many pages or objects.');
            }
            $decodedBytes = 0;
            $nonStructureObjects = 0;
            foreach ($document->getObjects() as $object) {
                if (! $object->getHeader()->get('Type')->equals('StructElem')) {
                    $nonStructureObjects++;
                }
                $decodedBytes += strlen($object->getContent());
                if ($decodedBytes > 33554432 || $nonStructureObjects > 800) {
                    throw new InvalidArgumentException('The daily report is too complex.');
                }
            }
            $positionedPages = [];
            $pageSequence = [];
            foreach ($pages as $page) {
                $pageSequence[] = filter_var($page->getHeader()->get('StructParents')->getContent(), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
                $positionedPages[] = $page->getDataTm();
                if (count($positionedPages[array_key_last($positionedPages)]) > 18000 || microtime(true) - $started > 12) {
                    throw new InvalidArgumentException('The daily report is too complex.');
                }
            }

            return $this->parsePositionedPages($positionedPages, hash('sha256', $bytes), $pageSequence);
        } catch (\Throwable) {
            // Never propagate parser text, object contents or employee data into logs.
            throw new InvalidArgumentException('This PDF could not be read safely. Use a complete daily TeleStaff report.');
        }
    }

    /** Positioned synthetic fixtures exercise the same path used by the PDF decoder. */
    public function parsePositionedPages(array $pages, string $sha256 = '', array $pageSequence = []): array
    {
        if (count($pages) < 1 || count($pages) > self::MAX_PAGES) {
            throw new InvalidArgumentException('The report page count is invalid.');
        }
        $normalizer = new TeleStaffDailyPrintNormalizer;
        $layout = $normalizer->normalize($pages, $pageSequence);
        $pages = $layout['pages'] ?? $pages;
        $snapshot = [
            'schedule_date' => null, 'shift' => null, 'start_at' => null, 'end_at' => null,
            'printed_at' => null, 'page_count' => count($pages),
            'template_signature' => 'mbfd-daily-letter-body-0.36040324-v1',
            'source_sha256' => $sha256, 'parsed_assignments' => [], 'vacancies' => [],
            'warnings' => [], 'definitive_eligibility_blocked' => false,
        ];
        $station = $apparatus = $rank = null;
        $observedShift = null;
        $datedHeader = false;
        $pagination = [];
        foreach ($pages as $index => $items) {
            if (count($items) > 18000) {
                throw new InvalidArgumentException('The report is too complex.');
            }
            $body = array_values(array_filter($items, fn (array $item): bool => abs((float) $item[0][0] - 0.36040324) < 0.00001));
            if ($body === []) {
                throw new InvalidArgumentException('This daily report layout is not supported.');
            }
            $footer = array_values(array_filter($items, fn (array $item): bool => abs((float) $item[0][0] - 0.5) < 0.00001));
            foreach ($this->lines($footer) as $line) {
                $text = $this->column($line, 0, 100);
                if (preg_match('/\b(\d+) of (\d+)\b/', $text, $match)) {
                    $pagination[$index + 1] = [(int) $match[1], (int) $match[2]];
                }
                $text = $this->column($line, 500, 650);
                if (preg_match('/\b(\d{1,2}\/\d{1,2}\/\d{4},\s*\d{1,2}:\d{2}\s*[AP]M)\b/', $text, $match)) {
                    $printed = DateTimeImmutable::createFromFormat('!n/j/Y, g:i A', $match[1], new DateTimeZone('America/New_York'));
                    $snapshot['printed_at'] = $printed ? $printed->format(DATE_ATOM) : null;
                }
            }
            $summary = false;
            foreach ($this->lines($body) as $line) {
                $text = $this->clean($this->column($line, 0, 600));
                if (preg_match('/(?:Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday),\s+([A-Z][a-z]+ \d{1,2}, \d{4})/', $text, $match)) {
                    $date = DateTimeImmutable::createFromFormat('!F j, Y', $match[1], new DateTimeZone('America/New_York'));
                    $dateErrors = DateTimeImmutable::getLastErrors();
                    if (! $date || (is_array($dateErrors) && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0)) || ($snapshot['schedule_date'] !== null && $snapshot['schedule_date'] !== $date->format('Y-m-d'))) {
                        throw new InvalidArgumentException('The report contains inconsistent schedule dates.');
                    }
                    $snapshot['schedule_date'] = $date->format('Y-m-d');
                    $datedHeader = true;
                    $snapshot['start_at'] = $date->setTime(8, 0)->format(DATE_ATOM);
                    $snapshot['end_at'] = $date->modify('+1 day')->setTime(8, 0)->format(DATE_ATOM);
                }
                if (preg_match('/\b([ABC]) Shift\b/', $this->clean($this->column($line, 38, 170)), $match)) {
                    if ($datedHeader && $snapshot['shift'] !== null && $snapshot['shift'] !== $match[1]) {
                        throw new InvalidArgumentException('The report contains inconsistent shifts.');
                    }
                    $snapshot['shift'] ??= $match[1];
                    $observedShift = $match[1];
                    $datedHeader = false;
                    if ($observedShift !== $snapshot['shift']) {
                        $snapshot['warnings'][] = 'A secondary shift section needs verification';
                    }

                    continue;
                }
                if (str_contains($text, '{Headcounts}')) {
                    $summary = true;
                }
                if ($summary) {
                    continue;
                }
                $id = $this->column($line, 309, 334);
                $name = $this->clean($this->column($line, 170, 305));
                $heading = $this->clean($this->column($line, 38, 170));
                if (! preg_match('/^\d{4,6}$/', $id)) {
                    if ($name !== '' && ! in_array($name, ['-,-', '?,?'], true)
                        && preg_match('/^\d{2}:\d{2}$/', $this->column($line, 494, 515))
                        && preg_match('/^\d{2}:\d{2}$/', $this->column($line, 515, 537))
                        && $this->column($line, 340, 463) !== '') {
                        $snapshot['warnings'][] = 'A staff row has no reliable employee identifier';
                        $snapshot['definitive_eligibility_blocked'] = true;
                    }
                    if ($layout !== null && $heading === '{off roster}' && $name === '') {
                        $apparatus = $rank = null;
                    } elseif ($layout !== null && preg_match('/\bFloat Pool$/', $heading) && $name === '') {
                        $station = $rank = null;
                        $apparatus = $heading;
                    } elseif ($layout !== null && preg_match('/^Captain\s+\d+$/', $heading) && $name === '') {
                        $apparatus = $heading;
                        $rank = null;
                    } elseif (preg_match('/^Station\s+\d+$/', $heading)) {
                        $station = $heading;
                        $apparatus = $rank = null;
                    } elseif (preg_match('/^(?:Division Chief|Engine|Ladder|Rescue|Combat|Air Truck|Marine|Fire Boat)\b/', $heading) && $name === '') {
                        $apparatus = $heading;
                        $rank = null;
                    }
                    if (in_array($name, ['-,-', '?,?'], true)) {
                        $snapshot['vacancies'][] = ['source_page' => $index + 1, 'station' => $station, 'apparatus' => $apparatus];
                    }

                    continue;
                }
                if ($snapshot['schedule_date'] === null) {
                    throw new InvalidArgumentException('A schedule date must precede staff rows.');
                }
                $rank = $heading !== '' ? $heading : $rank;
                $from = $this->column($line, 494, 515);
                $to = $this->column($line, 515, 537);
                $hours = $this->clean($this->column($line, 537, 557));
                $rawCode = $this->column($line, 340, 463);
                $normalized = $this->clean($rawCode);
                $reasons = [];
                if ($observedShift !== $snapshot['shift']) {
                    $reasons[] = 'Duty shift section needs verification';
                }
                [$class, $approval] = $this->code($normalized);
                if ($this->withoutFamilyIcon($rawCode, $normalized) !== $normalized || $class === 'UNKNOWN' || $class === 'ANNOTATION' || $approval !== 'VERIFIED_EFFECTIVE') {
                    $reasons[] = 'Duty assignment needs verification';
                }
                $period = $this->period($snapshot['schedule_date'], $from, $to, $hours);
                if ($period === null) {
                    $reasons[] = 'Duty time needs verification';
                }
                if ($name === '') {
                    $reasons[] = 'Employee display needs verification';
                }
                $snapshot['parsed_assignments'][] = [
                    'raw_employee_id' => $id,
                    'raw_name' => trim(explode('(', $name, 2)[0]),
                    'seat_rank' => $rank,
                    'reported_station' => $station,
                    'reported_apparatus' => $apparatus,
                    'observed_shift' => $observedShift,
                    'raw_code' => $rawCode,
                    'normalized_code' => $normalized,
                    'code_class' => $class,
                    'approval_state' => $approval,
                    'start_at' => $period['start_at'] ?? null,
                    'end_at' => $period['end_at'] ?? null,
                    'source_page' => $index + 1,
                    'bbox' => [309, $line['y'], 557, $line['y']],
                    'coordinate_space' => 'smalot_text_matrix',
                    'review_reasons' => $reasons,
                ];
                if (count($snapshot['parsed_assignments']) > 1500) {
                    throw new InvalidArgumentException('The report contains too many assignments.');
                }
            }
        }
        if ($snapshot['schedule_date'] === null || $snapshot['shift'] === null || $snapshot['parsed_assignments'] === []) {
            throw new InvalidArgumentException('The daily report date, shift or staff rows could not be confirmed.');
        }
        $completePrint = $layout !== null && $normalizer->complete($layout, $snapshot);
        if ($layout !== null) {
            $snapshot['template_signature'] = 'mbfd-daily-tagged-print-v1';
        }
        foreach (array_keys($pages) as $index) {
            if (! $completePrint && ($pagination[$index + 1] ?? null) !== [$index + 1, count($pages)]) {
                $snapshot['warnings'][] = 'Report pagination is incomplete or could not be verified';
                $snapshot['definitive_eligibility_blocked'] = true;
            }
        }
        if ($snapshot['printed_at'] === null) {
            $snapshot['warnings'][] = 'Report print time is not available';
        }
        $snapshot['warnings'] = array_values(array_unique($snapshot['warnings']));

        return $snapshot;
    }

    private function code(string $code): array
    {
        return match ($code) {
            // An exchange printed onto the published daily roster is the staffed seat for that
            // interval, exactly as 2WXOFF is trusted as the matching absence.
            'SP', '+OT', 'OT', '2WXON', '2WXEON' => ['WORKING', 'VERIFIED_EFFECTIVE'],
            '2WXOFF', 'ADONIS DAY', 'VAC', 'VACA', 'EVAC', 'SIC' => ['NOT_WORKING', 'VERIFIED_EFFECTIVE'],
            '^OOC', '^ROC', '^MOC', '^TOC' => ['ANNOTATION', 'UNVERIFIED'],
            default => ['UNKNOWN', 'UNVERIFIED'],
        };
    }

    /**
     * TeleStaff prefixes some codes with a category icon: U+E646 on exchanges and U+E521 on
     * overtime, observed only on those families across every validated daily report. An icon
     * on its own family is presentation; any other glyph remains an unverified modifier.
     */
    private function withoutFamilyIcon(string $raw, string $code): string
    {
        $icon = match ($code) {
            '2WXON', '2WXEON', '2WXOFF' => "\u{E646}",
            '+OT', 'OT' => "\u{E521}",
            default => null,
        };

        $raw = trim($raw);

        return $icon !== null && str_starts_with($raw, $icon) ? trim(substr($raw, strlen($icon))) : $raw;
    }

    private function period(string $date, string $from, string $to, string $hours): ?array
    {
        if (! preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $from) || ! preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $to) || ! is_numeric($hours) || (float) $hours <= 0 || (float) $hours > 25) {
            return null;
        }
        $start = new DateTimeImmutable($date.'T'.$from, new DateTimeZone('America/New_York'));
        if ($from < '08:00') {
            $start = $start->modify('+1 day');
        }
        $end = new DateTimeImmutable($start->format('Y-m-d').'T'.$to, new DateTimeZone('America/New_York'));
        if ($end <= $start) {
            $end = $end->modify('+1 day');
        }
        // TeleStaff's hours may be wall-clock hours over DST. Compare local-clock
        // difference as well as elapsed time; reject unrelated inconsistent values.
        $elapsed = ($end->getTimestamp() - $start->getTimestamp()) / 3600;
        $wall = $elapsed + ($end->getOffset() - $start->getOffset()) / 3600;
        if (abs($elapsed - (float) $hours) > 0.05 && abs($wall - (float) $hours) > 0.05) {
            return null;
        }

        return ['start_at' => $start->format(DATE_ATOM), 'end_at' => $end->format(DATE_ATOM)];
    }

    private function lines(array $items): array
    {
        usort($items, fn (array $a, array $b): int => (float) $b[0][5] <=> (float) $a[0][5]);
        $lines = [];
        foreach ($items as $item) {
            $y = (float) $item[0][5];
            $last = count($lines) - 1;
            if ($last >= 0 && abs($lines[$last]['y'] - $y) < 3.2) {
                $lines[$last]['items'][] = $item;
            } else {
                $lines[] = ['y' => $y, 'items' => [$item]];
            }
        }
        foreach ($lines as &$line) {
            usort($line['items'], fn (array $a, array $b): int => (float) $a[0][4] <=> (float) $b[0][4]);
        }
        unset($line);

        return $lines;
    }

    private function column(array $line, float $left, float $right): string
    {
        $text = '';
        foreach ($line['items'] as $item) {
            if ((float) $item[0][4] >= $left && (float) $item[0][4] < $right) {
                $text .= $item[1];
            }
        }

        return trim((string) preg_replace('/[\s\p{Z}]+/u', ' ', $text));
    }

    private function clean(string $text): string
    {
        return str_replace(['ﬁ', 'ﬀ', 'ﬃ'], ['fi', 'ff', 'ffi'], trim((string) preg_replace('/\p{Co}/u', '', $text)));
    }
}
