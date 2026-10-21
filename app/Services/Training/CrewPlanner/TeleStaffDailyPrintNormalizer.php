<?php

declare(strict_types=1);

namespace App\Services\Training\CrewPlanner;

use InvalidArgumentException;

/** The tagged Edge print has different margins and repeated overprinted labels. */
final class TeleStaffDailyPrintNormalizer
{
    public function normalize(array $pages, array $pageSequence): ?array
    {
        $identifiers = $clocks = $expected = [];
        foreach ($pages as $page => &$items) {
            $seen = [];
            $items = array_values(array_filter($items, function (array $item) use (&$seen): bool {
                $key = json_encode($item);
                if (isset($seen[$key])) {
                    return false;
                }
                $seen[$key] = true;

                return true;
            }));
            foreach ($items as $item) {
                if (abs((float) $item[0][0] - 0.61693545) > 0.00001
                    || abs((float) $item[0][3] - (float) $item[0][0]) > 0.00001
                    || abs((float) $item[0][1]) > 0.00001 || abs((float) $item[0][2]) > 0.00001) {
                    return null;
                }
                $text = trim($item[1]);
                if (preg_match('/^\d{4,6}$/', $text)) {
                    $identifiers[] = (float) $item[0][4];
                    $key = ($page + 1).'|'.$text;
                    $expected[$key] = ($expected[$key] ?? 0) + 1;
                }
                if (preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $text)) {
                    $clocks[(string) round((float) $item[0][4], 2)] = (float) $item[0][4];
                }
            }
        }
        unset($items);
        if ($identifiers === [] || count($clocks) !== 2) {
            throw new InvalidArgumentException('The daily print columns could not be confirmed.');
        }
        $id = array_sum($identifiers) / count($identifiers);
        sort($clocks, SORT_NUMERIC);
        $factor = ($clocks[0] - $id) / (494.7 - 311);
        $offset = $id - $factor * 311;
        if ($factor < 0.5 || $factor > 2 || max($identifiers) - min($identifiers) > 0.75
            || abs(($clocks[1] - $offset) / $factor - 515.9) > 1) {
            throw new InvalidArgumentException('The daily print columns are inconsistent.');
        }
        $headcounts = false;
        $reported = null;
        $terminal = false;
        $terminalRows = 0;
        foreach ($pages as $page => &$items) {
            $lines = [];
            $ordered = $items;
            usort($ordered, fn (array $a, array $b): int => (float) $b[0][5] <=> (float) $a[0][5]);
            foreach ($ordered as $item) {
                $last = count($lines) - 1;
                if ($last < 0 || abs($lines[$last]['y'] - (float) $item[0][5]) >= 3.2) {
                    $lines[] = ['y' => (float) $item[0][5], 'items' => []];
                }
                $lines[array_key_last($lines)]['items'][] = $item;
            }
            foreach ($lines as $line) {
                usort($line['items'], fn (array $a, array $b): int => (float) $a[0][4] <=> (float) $b[0][4]);
                $text = implode('', array_column($line['items'], 1));
                $headcounts = $headcounts || str_contains($text, '{Headcounts}');
                if (! $headcounts) {
                    continue;
                }
                $label = $left = $right = '';
                foreach ($line['items'] as $item) {
                    $x = (float) $item[0][4];
                    if ($x < 85) {
                        $label .= $item[1];
                    } elseif ($x < 120) {
                        $left .= $item[1];
                    } elseif ($x >= 340 && $x < 380) {
                        $right .= $item[1];
                    }
                }
                $label = trim((string) preg_replace('/\p{Co}/u', '', $label));
                $numbers = ctype_digit(trim($left)) && trim($left) === trim($right);
                if ($label === 'Rank' && $numbers && $reported === null) {
                    $reported = (int) trim($left);
                }
                if ($label === 'Can Act As' && $page === count($pages) - 1 && $numbers) {
                    $terminal = true;
                } elseif ($terminal && $label !== '' && $numbers) {
                    $terminalRows++;
                }
            }
            foreach ($items as &$item) {
                $item[0][0] = $item[0][3] = 0.36040324;
                $item[0][4] = ((float) $item[0][4] - $offset) / $factor;
            }
            unset($item);
        }
        unset($items);

        return ['pages' => $pages, 'expected_rows' => $expected, 'reported_headcount' => $reported,
            'terminal_summary' => $terminal && $terminalRows >= 4,
            'page_sequence_verified' => $pageSequence === range(0, count($pages) - 1)];
    }

    public function complete(array $layout, array $snapshot): bool
    {
        $observed = [];
        $scheduled = 0;
        foreach ($snapshot['parsed_assignments'] as $row) {
            $key = $row['source_page'].'|'.$row['raw_employee_id'];
            $observed[$key] = ($observed[$key] ?? 0) + 1;
            // This reconciles report totals only. It never grants exchange approval
            // or changes UNKNOWN 2WXEON into an eligible working assignment.
            if (in_array($row['normalized_code'], ['SP', '2WXON', '2WXEON'], true)
                && $row['start_at'] === $snapshot['start_at'] && $row['end_at'] === $snapshot['end_at']) {
                $scheduled++;
            }
        }
        ksort($observed);
        $expected = $layout['expected_rows'];
        ksort($expected);
        if ($observed !== $expected) {
            throw new InvalidArgumentException('Some daily print staff rows could not be accounted for.');
        }

        return $layout['page_sequence_verified'] && $layout['terminal_summary']
            && $layout['reported_headcount'] !== null && $scheduled === $layout['reported_headcount'];
    }
}
