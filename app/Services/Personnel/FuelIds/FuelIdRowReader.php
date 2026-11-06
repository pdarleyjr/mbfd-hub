<?php

declare(strict_types=1);

namespace App\Services\Personnel\FuelIds;

use JsonException;
use Symfony\Component\Process\Process;

/**
 * Reads a fuel list (the City PDF, a CSV with the same columns, or the JSON rows the PDF extractor prints) into
 * validated rows. The PDF is read by scripts/personnel-forms/fuel_id_extract.py under the configured Python (the
 * policy library's PyMuPDF environment in production). Nothing here logs row values.
 */
final class FuelIdRowReader
{
    public const FORMATS = ['pdf', 'csv', 'json'];

    public const MAX_ROWS = 5000;

    private const CSV_HEADERS = [
        'employee_id' => ['employee_id', 'employee_number', 'employee_no', 'emp_id'],
        'last_name' => ['driver_last_name', 'last_name'],
        'first_name' => ['driver_first_name', 'first_name'],
        'prompt_id' => ['driver_prompt_id', 'prompt_id', 'fuel_pin', 'pin'],
        'status' => ['status_indicator', 'status'],
        'department' => ['driver_department', 'department', 'dept'],
        'page' => ['page'],
    ];

    public function detectFormat(string $path, ?string $originalName = null, string $requested = 'auto'): string
    {
        if (in_array($requested, self::FORMATS, true)) {
            return $requested;
        }
        if ($requested !== 'auto') {
            throw new FuelIdImportException('The format must be auto, pdf, csv or json.');
        }
        $extension = strtolower(pathinfo($originalName ?? $path, PATHINFO_EXTENSION));
        if (in_array($extension, self::FORMATS, true)) {
            return $extension;
        }
        $head = (string) @file_get_contents($path, false, null, 0, 8);
        if (str_starts_with($head, '%PDF-')) {
            return 'pdf';
        }
        if (str_starts_with(ltrim($head, "\xEF\xBB\xBF \t\r\n"), '[')) {
            return 'json';
        }

        return 'csv';
    }

    /** @return list<FuelIdSourceRow> */
    public function read(string $path, string $format): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new FuelIdImportException('The fuel list file could not be read.');
        }

        return match ($format) {
            'pdf' => $this->fromRecords($this->extractPdf($path), 'PDF'),
            'json' => $this->fromRecords($this->decodeJson((string) file_get_contents($path)), 'JSON'),
            'csv' => $this->readCsv($path),
            default => throw new FuelIdImportException('The format must be pdf, csv or json.'),
        };
    }

    public function mimeFor(string $format): string
    {
        return match ($format) {
            'pdf' => 'application/pdf',
            'csv' => 'text/csv',
            default => 'application/json',
        };
    }

    /** @return list<mixed> */
    private function extractPdf(string $path): array
    {
        $head = (string) @file_get_contents($path, false, null, 0, 5);
        if ($head !== '%PDF-') {
            throw new FuelIdImportException('The file is not a PDF.');
        }
        $process = new Process(
            [(string) config('fuel-ids.python_binary', 'python3'), '-I', base_path('scripts/personnel-forms/fuel_id_extract.py'), $path],
            null,
            null,
            null,
            (float) config('fuel-ids.extractor_timeout_seconds', 120),
        );
        try {
            $process->run();
        } catch (\Throwable) {
            throw new FuelIdImportException('The PDF reader is unavailable. Import the list as CSV instead.');
        }
        if (! $process->isSuccessful()) {
            throw new FuelIdImportException('The PDF could not be read. Confirm it is the City fuel list report, or import it as CSV.');
        }

        return $this->decodeJson($process->getOutput());
    }

    /** @return list<mixed> */
    private function decodeJson(string $json): array
    {
        try {
            $decoded = json_decode(ltrim($json, "\xEF\xBB\xBF"), true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new FuelIdImportException('The rows are not valid JSON.');
        }
        if (! is_array($decoded) || ! array_is_list($decoded)) {
            throw new FuelIdImportException('The JSON must be a list of rows.');
        }

        return $decoded;
    }

    /** @return list<FuelIdSourceRow> */
    private function readCsv(string $path): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new FuelIdImportException('The CSV could not be opened.');
        }
        try {
            $header = fgetcsv($handle, 0, ',', '"', '');
            if (! is_array($header)) {
                throw new FuelIdImportException('The CSV is empty.');
            }
            $columns = $this->csvColumns($header);
            $records = [];
            while (($line = fgetcsv($handle, 0, ',', '"', '')) !== false) {
                if ($line === [null] || trim(implode('', array_map('strval', $line))) === '') {
                    continue;
                }
                $record = [];
                foreach ($columns as $field => $index) {
                    $record[$field] = $line[$index] ?? null;
                }
                $records[] = $record;
                if (count($records) > self::MAX_ROWS) {
                    throw new FuelIdImportException('The file has more than '.self::MAX_ROWS.' rows.');
                }
            }
        } finally {
            fclose($handle);
        }

        return $this->fromRecords($records, 'CSV');
    }

    /**
     * @param  list<string|null>  $header
     * @return array<string, int>
     */
    private function csvColumns(array $header): array
    {
        $normalized = [];
        foreach ($header as $index => $label) {
            $key = trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower(ltrim((string) $label, "\xEF\xBB\xBF"))), '_');
            $normalized[$key] = $index;
        }
        $columns = [];
        foreach (self::CSV_HEADERS as $field => $aliases) {
            foreach ($aliases as $alias) {
                if (array_key_exists($alias, $normalized)) {
                    $columns[$field] = $normalized[$alias];
                    break;
                }
            }
        }
        foreach (['employee_id' => 'Employee ID', 'last_name' => 'Driver Last Name', 'prompt_id' => 'Driver Prompt ID'] as $field => $label) {
            if (! isset($columns[$field])) {
                throw new FuelIdImportException("The CSV needs a \"{$label}\" column.");
            }
        }

        return $columns;
    }

    /**
     * @param  list<mixed>  $records
     * @return list<FuelIdSourceRow>
     */
    private function fromRecords(array $records, string $label): array
    {
        if ($records === []) {
            throw new FuelIdImportException("The {$label} has no fuel list rows.");
        }
        if (count($records) > self::MAX_ROWS) {
            throw new FuelIdImportException('The file has more than '.self::MAX_ROWS.' rows.');
        }
        $rows = [];
        foreach ($records as $index => $record) {
            $rows[] = $this->row($index + 1, $record);
        }

        return $rows;
    }

    private function row(int $number, mixed $record): FuelIdSourceRow
    {
        if (! is_array($record)) {
            throw new FuelIdImportException("Row {$number} is not a row object.");
        }
        $employeeNumber = trim((string) $this->scalar($record['employee_id'] ?? null, $number, 'Employee ID'));
        if (preg_match('/^\d{1,20}$/', $employeeNumber) !== 1) {
            throw new FuelIdImportException("Row {$number}: Employee ID must be a number.");
        }
        $pin = $record['prompt_id'] ?? null;
        if (! is_string($pin)) {
            throw new FuelIdImportException("Row {$number}: Driver Prompt ID must be text so leading zeros are kept.");
        }
        $pin = trim($pin);
        if (preg_match('/^\d{4,8}$/', $pin) !== 1) {
            throw new FuelIdImportException("Row {$number}: Driver Prompt ID must be 4 to 8 digits. If the file came from a spreadsheet, keep that column as text so leading zeros are not lost.");
        }
        $lastName = $this->text($record['last_name'] ?? null, 120, $number, 'Driver Last Name');
        if ($lastName === null) {
            throw new FuelIdImportException("Row {$number}: Driver Last Name is blank.");
        }
        $page = $record['page'] ?? null;

        return new FuelIdSourceRow(
            rowNumber: $number,
            page: is_int($page) || (is_string($page) && ctype_digit($page)) ? max(0, min(65535, (int) $page)) : null,
            employeeNumber: $employeeNumber,
            lastName: $lastName,
            firstName: $this->text($record['first_name'] ?? null, 120, $number, 'Driver First Name') ?? '',
            promptId: $pin,
            status: $this->text($record['status'] ?? null, 32, $number, 'Status Indicator'),
            department: $this->text($record['department'] ?? null, 64, $number, 'Driver Department'),
        );
    }

    private function text(mixed $value, int $max, int $number, string $label): ?string
    {
        $value = $this->scalar($value, $number, $label);
        if ($value === null) {
            return null;
        }
        $value = trim((string) preg_replace('/\s+/u', ' ', (string) $value));
        if ($value === '') {
            return null;
        }
        if (mb_strlen($value) > $max) {
            throw new FuelIdImportException("Row {$number}: {$label} is longer than {$max} characters.");
        }

        return $value;
    }

    private function scalar(mixed $value, int $number, string $label): string|int|null
    {
        if ($value === null || is_string($value) || is_int($value)) {
            return $value;
        }
        throw new FuelIdImportException("Row {$number}: {$label} has an unexpected value.");
    }
}
