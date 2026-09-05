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
        $tables = $xpath->query('//table');
        if ($tables->length === 0) {
            throw new InvalidArgumentException('The roster export does not contain a table.');
        }

        $table = null;
        $headerRow = null;
        $headers = [];
        foreach ($tables as $candidate) {
            foreach ($xpath->query('.//tr', $candidate) as $row) {
                $candidateHeaders = [];
                foreach ($xpath->query('./*[self::th or self::td]', $row) as $index => $cell) {
                    $candidateHeaders[$this->normalize((string) $cell->textContent)] = $index;
                }
                if ($this->headerIndex($candidateHeaders, ['employee id', 'employeeid', 'emp id', 'id']) !== null
                    && $this->headerIndex($candidateHeaders, ['name', 'employee name']) !== null) {
                    $table = $candidate;
                    $headerRow = $row;
                    $headers = $candidateHeaders;
                    break 2;
                }
            }
        }
        if (! $table instanceof DOMElement || ! $headerRow instanceof DOMElement) {
            throw new InvalidArgumentException('The roster export must contain Employee ID and Name columns.');
        }

        $employeeIdIndex = $this->headerIndex($headers, ['employee id', 'employeeid', 'emp id', 'id']);
        $nameIndex = $this->headerIndex($headers, ['name', 'employee name']);

        $rankIndex = $this->headerIndex($headers, ['rank', 'classification', 'position']);
        $assignmentIndex = $this->headerIndex($headers, ['assignment', 'unit', 'station', 'current assignment']);
        $seen = [];
        $rows = [];
        $pastHeader = false;
        foreach ($xpath->query('.//tr', $table) as $row) {
            if ($row === $headerRow) {
                $pastHeader = true;

                continue;
            }
            if (! $pastHeader) {
                continue;
            }
            $cells = iterator_to_array($xpath->query('./*[self::th or self::td]', $row));
            $employeeId = trim((string) ($cells[$employeeIdIndex]->textContent ?? ''));
            $name = $this->normalizeName((string) ($cells[$nameIndex]->textContent ?? ''));
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

    private function normalizeName(string $value): string
    {
        $value = trim((string) preg_replace('/\s+/', ' ', $value));
        if (! str_contains($value, ',')) {
            return $value;
        }

        [$lastName, $givenNames] = array_map('trim', explode(',', $value, 2));
        $suffix = null;
        if (preg_match('/\s+(JR\.?|SR\.?|II|III|IV)$/i', $lastName, $matches) === 1) {
            $lastName = trim(substr($lastName, 0, -strlen($matches[0])));
            $suffix = match (strtoupper(rtrim($matches[1], '.'))) {
                'JR' => 'Jr.',
                'SR' => 'Sr.',
                default => strtoupper($matches[1]),
            };
        }

        $name = mb_convert_case(mb_strtolower(trim($givenNames.' '.$lastName)), MB_CASE_TITLE, 'UTF-8');
        $name = (string) preg_replace_callback(
            "/(?<=[\x{27}\x{2019}])\p{Ll}/u",
            static fn (array $matches): string => mb_strtoupper($matches[0], 'UTF-8'),
            $name,
        );

        return $suffix === null ? $name : $name.' '.$suffix;
    }
}
