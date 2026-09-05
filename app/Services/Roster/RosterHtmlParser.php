<?php

declare(strict_types=1);

namespace App\Services\Roster;

use DOMDocument;
use DOMElement;
use DOMXPath;
use InvalidArgumentException;

final class RosterHtmlParser
{
    /** @return list<array{employee_id: string, name: string, rank: ?string, assignment: ?string, city_email: null}> */
    public function parse(string $html): array
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML($html, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (! $loaded) {
            throw new InvalidArgumentException('The roster export is not valid HTML.');
        }

        $xpath = new DOMXPath($document);
        $table = $xpath->query('//table')->item(0);
        if (! $table instanceof DOMElement) {
            throw new InvalidArgumentException('The roster export does not contain a table.');
        }

        $headers = [];
        foreach ($xpath->query('.//tr[1]/*[self::th or self::td]', $table) as $index => $cell) {
            $headers[$this->normalize((string) $cell->textContent)] = $index;
        }
        $employeeIdIndex = $this->headerIndex($headers, ['employee id', 'employeeid', 'id']);
        $nameIndex = $this->headerIndex($headers, ['name', 'employee name']);
        if ($employeeIdIndex === null || $nameIndex === null) {
            throw new InvalidArgumentException('The roster export must contain Employee ID and Name columns.');
        }

        $rankIndex = $this->headerIndex($headers, ['rank', 'classification']);
        $assignmentIndex = $this->headerIndex($headers, ['assignment', 'station', 'current assignment']);
        $seen = [];
        $rows = [];
        foreach ($xpath->query('.//tr', $table) as $rowIndex => $row) {
            if ($rowIndex === 0) {
                continue;
            }
            $cells = iterator_to_array($xpath->query('./*[self::th or self::td]', $row));
            $employeeId = trim((string) ($cells[$employeeIdIndex]->textContent ?? ''));
            $name = trim((string) ($cells[$nameIndex]->textContent ?? ''));
            if ($employeeId === '' && $name === '') {
                continue;
            }
            if ($employeeId === '' || $name === '') {
                throw new InvalidArgumentException('A roster row is missing Employee ID or Name.');
            }
            if (isset($seen[$employeeId])) {
                throw new InvalidArgumentException("Duplicate roster Employee ID: {$employeeId}");
            }
            $seen[$employeeId] = true;
            $rows[] = [
                'employee_id' => $employeeId,
                'name' => $name,
                'rank' => $rankIndex === null ? null : $this->nullableText($cells[$rankIndex]->textContent ?? null),
                'assignment' => $assignmentIndex === null ? null : $this->nullableText($cells[$assignmentIndex]->textContent ?? null),
                'city_email' => null,
            ];
        }

        if ($rows === []) {
            throw new InvalidArgumentException('The roster export contains no personnel rows.');
        }

        return $rows;
    }

    private function normalize(string $value): string
    {
        return strtolower(trim((string) preg_replace('/\s+/', ' ', $value)));
    }

    /** @param array<string, int> $headers @param list<string> $names */
    private function headerIndex(array $headers, array $names): ?int
    {
        foreach ($names as $name) {
            if (array_key_exists($name, $headers)) {
                return $headers[$name];
            }
        }

        return null;
    }

    private function nullableText(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
