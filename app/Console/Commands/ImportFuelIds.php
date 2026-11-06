<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\FuelIdRow;
use App\Services\Personnel\FuelIdImporter;
use App\Services\Personnel\FuelIds\FuelIdImportException;
use App\Services\Personnel\FuelIds\FuelIdImportPlan;
use Illuminate\Console\Command;

/**
 * Import the City fuel list. Output is restricted: counts and exception identities only; every PIN is masked.
 */
final class ImportFuelIds extends Command
{
    protected $signature = 'personnel:fuel-ids-import
        {path : Path to the fuel list (PDF, CSV, or JSON rows from the PDF extractor)}
        {--format=auto : auto, pdf, csv or json}
        {--source-pdf= : With CSV or JSON rows, the original City PDF to keep as the source record}
        {--dry-run : Show what would happen without saving anything}';

    protected $description = 'Import Fuel PINs (Driver Prompt ID) from the City fuel list. The same file twice changes nothing.';

    private const LABELS = [
        FuelIdRow::MATCHED => 'Matched',
        FuelIdRow::NAME_MISMATCH => 'Name mismatch',
        FuelIdRow::CONFLICT => 'Conflict',
        FuelIdRow::UNMATCHED => 'Unmatched',
    ];

    public function handle(FuelIdImporter $importer): int
    {
        $path = (string) $this->argument('path');
        $format = strtolower((string) $this->option('format'));
        $sourcePdf = $this->option('source-pdf');
        if (! in_array($format, ['auto', 'pdf', 'csv', 'json'], true)) {
            $this->components->error('--format must be auto, pdf, csv or json.');

            return self::FAILURE;
        }
        if (! is_file($path)) {
            $this->components->error('The file was not found.');

            return self::FAILURE;
        }
        if (is_string($sourcePdf) && $sourcePdf !== '' && ! is_file($sourcePdf)) {
            $this->components->error('The --source-pdf file was not found.');

            return self::FAILURE;
        }

        try {
            if ($this->option('dry-run')) {
                $plan = $importer->plan($path, $format, basename($path));
                $this->report($plan);
                $this->newLine();
                $this->components->info($plan->alreadyImported
                    ? 'Dry run. This file has already been imported; importing it again changes nothing.'
                    : 'Dry run. Nothing was saved.');

                return self::SUCCESS;
            }
            $result = $importer->import($path, null, $format, basename($path),
                is_string($sourcePdf) && $sourcePdf !== '' ? $sourcePdf : null,
                is_string($sourcePdf) && $sourcePdf !== '' ? basename($sourcePdf) : null);
        } catch (FuelIdImportException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->report($result->plan);
        $this->newLine();
        if (! $result->imported) {
            $this->components->info('This file has already been imported. Nothing changed.');

            return self::SUCCESS;
        }
        $this->components->info("Imported source #{$result->source?->id}. {$result->superseded} earlier PIN rows were superseded.");

        return self::SUCCESS;
    }

    private function report(FuelIdImportPlan $plan): void
    {
        $summary = $plan->summary();
        $this->line("Format: {$summary['format']} · Rows: {$summary['total']}");
        $this->table(['Status', 'Rows'], array_map(
            fn (string $status, int $count): array => [self::LABELS[$status] ?? $status, $count],
            array_keys($summary['counts']), $summary['counts'],
        ));
        if ($summary['exceptions'] !== []) {
            $this->line('Exceptions (not assigned to anyone until an administrator resolves them):');
            $this->table(['Row', 'Employee ID', 'Name on list', 'Status', 'Fuel PIN', 'Reason'], array_map(fn (array $row): array => [
                $row['row'], $row['employee_number'], $row['name'], self::LABELS[$row['status']] ?? $row['status'], $row['pin'], $row['reason'],
            ], $summary['exceptions']));
        }
        if ($summary['departed'] !== []) {
            $this->line('Matched to departed employees (flagged):');
            $this->table(['Employee ID', 'Hub name'], array_map(fn (array $row): array => [$row['employee_number'], $row['name']], $summary['departed']));
        }
    }
}
