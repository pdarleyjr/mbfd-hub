<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Apparatus;
use App\Models\UnitMasterVehicle;
use App\Services\Fleet\ApparatusMasterVehicleLink;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use JsonException;
use Throwable;

/**
 * Ties every apparatus row to its master vehicle record and repairs drift.
 *
 * Safe to run any number of times: unchanged data produces zero changes. Never creates
 * master rows (only a newly created apparatus does that). A dry run executes the real
 * logic inside a transaction and rolls it back, so its report is exactly what a real
 * run would do.
 */
final class LinkMasterVehicles extends Command
{
    /** The master inventory section that lists frontline and reserve fire apparatus. */
    public const APPARATUS_SECTION = 'Frontline and Reserve Fire Apparatus';

    protected $signature = 'fleet:link-master-vehicles
                            {--dry-run : Report what would change and write nothing}
                            {--json= : Also write the report as JSON to this path (contains VINs; keep it private)}';

    protected $description = 'Link apparatus rows to master vehicle records by vehicle number and repair shared-field drift';

    public function handle(ApparatusMasterVehicleLink $link): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $jsonPath = trim((string) $this->option('json'));
        if ($jsonPath !== '' && ! is_dir(dirname($jsonPath))) {
            $this->error('The folder for --json does not exist: '.dirname($jsonPath));

            return self::FAILURE;
        }

        DB::beginTransaction();
        try {
            $rows = [];
            foreach (Apparatus::query()->orderBy('id')->get() as $apparatus) {
                $rows[] = $this->rowFor($apparatus, $link->reconcile($apparatus));
            }
            $masterOnly = $this->masterOnly();
            $dryRun ? DB::rollBack() : DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();
            $this->error('Linking failed and nothing was written: '.$e->getMessage());

            return self::FAILURE;
        }

        $summary = $this->summary($rows, count($masterOnly));
        $this->printReport($rows, $masterOnly, $summary, $dryRun);

        if ($jsonPath !== '' && ! $this->writeJson($jsonPath, $dryRun, $summary, $rows, $masterOnly)) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param  array{outcome: string, changed: bool, master: ?UnitMasterVehicle, reason: ?string, fields: list<array{field: string, label: string, from: ?string, to: ?string, side: string}>}  $result
     * @return array<string, mixed>
     */
    private function rowFor(Apparatus $apparatus, array $result): array
    {
        return [
            'apparatus_id' => $apparatus->getKey(),
            'unit' => $apparatus->designation ?: ($apparatus->name ?: '(no unit)'),
            'vehicle_number' => $apparatus->vehicle_number,
            'outcome' => $result['outcome'],
            'changed' => $result['changed'],
            'master_id' => $result['master']?->getKey(),
            'master_vehicle_number' => $result['master']?->veh_number,
            'reason' => $result['reason'],
            'fields' => $result['fields'],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function masterOnly(): array
    {
        return UnitMasterVehicle::query()
            ->whereDoesntHave('apparatus')
            ->whereRaw('LOWER(TRIM(section)) = ?', [mb_strtolower(self::APPARATUS_SECTION)])
            ->where(fn ($query) => $query->whereNull('asset_category')->orWhere('asset_category', UnitMasterVehicle::CATEGORY_VEHICLE))
            ->orderBy('id')
            ->get()
            ->map(fn (UnitMasterVehicle $vehicle): array => [
                'master_id' => $vehicle->getKey(),
                'vehicle_number' => $vehicle->veh_number,
                'year' => $vehicle->year,
                'make' => $vehicle->make,
                'model' => $vehicle->model,
                'section' => $vehicle->section,
            ])
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, int>
     */
    private function summary(array $rows, int $masterOnly): array
    {
        $count = static fn (string $outcome): int => count(array_filter($rows, static fn (array $row): bool => $row['outcome'] === $outcome));

        return [
            'apparatuses' => count($rows),
            'linked' => $count(ApparatusMasterVehicleLink::OUTCOME_LINKED),
            'corrected' => $count(ApparatusMasterVehicleLink::OUTCOME_CORRECTED),
            'already_linked' => $count(ApparatusMasterVehicleLink::OUTCOME_ALREADY_LINKED),
            'needs_review' => $count(ApparatusMasterVehicleLink::OUTCOME_NEEDS_REVIEW),
            'changes' => count(array_filter($rows, static fn (array $row): bool => $row['changed'])),
            'master_only' => $masterOnly,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<array<string, mixed>>  $masterOnly
     * @param  array<string, int>  $summary
     */
    private function printReport(array $rows, array $masterOnly, array $summary, bool $dryRun): void
    {
        $this->newLine();
        $this->line($dryRun ? '<comment>DRY RUN - nothing was written.</comment>' : '<info>APPLIED</info> - changes written in one transaction.');
        $this->newLine();

        $this->table(
            ['ID', 'Unit', 'Vehicle #', 'Outcome', 'Details'],
            array_map(fn (array $row): array => [
                $row['apparatus_id'], $row['unit'], $row['vehicle_number'] ?? '(none)', $row['outcome'], $this->details($row),
            ], $rows),
        );

        $this->newLine();
        $this->line('Master only (for Fleet review): road vehicles in "'.self::APPARATUS_SECTION.'" with no apparatus row');
        $this->table(
            ['Master ID', 'Vehicle #', 'Year', 'Make', 'Model'],
            array_map(static fn (array $row): array => [
                $row['master_id'], $row['vehicle_number'] ?? '(none)', $row['year'] ?? '', $row['make'] ?? '', $row['model'] ?? '',
            ], $masterOnly),
        );

        $this->newLine();
        $this->line(sprintf(
            'Apparatuses: %d | Linked: %d | Corrected: %d | Already linked: %d | Needs review: %d | %s: %d | Master only: %d',
            $summary['apparatuses'], $summary['linked'], $summary['corrected'], $summary['already_linked'], $summary['needs_review'],
            $dryRun ? 'Changes that would be written' : 'Changes written', $summary['changes'], $summary['master_only'],
        ));
    }

    /** @param array<string, mixed> $row */
    private function details(array $row): string
    {
        if ($row['outcome'] === ApparatusMasterVehicleLink::OUTCOME_NEEDS_REVIEW) {
            return (string) $row['reason'];
        }
        $parts = ['Master '.($row['master_vehicle_number'] ?? '#'.$row['master_id'])];
        foreach ($row['fields'] as $field) {
            $where = $field['side'] === ApparatusMasterVehicleLink::SIDE_MASTER ? 'master filled' : 'apparatus copy';
            $parts[] = sprintf('%s (%s): %s -> %s', $field['label'], $where, $field['from'] ?? '(blank)', $field['to'] ?? '(blank)');
        }

        return implode('; ', $parts);
    }

    /**
     * @param  array<string, int>  $summary
     * @param  list<array<string, mixed>>  $rows
     * @param  list<array<string, mixed>>  $masterOnly
     */
    private function writeJson(string $path, bool $dryRun, array $summary, array $rows, array $masterOnly): bool
    {
        try {
            $json = json_encode([
                'generated_at' => now()->toIso8601String(),
                'dry_run' => $dryRun,
                'summary' => $summary,
                'apparatuses' => $rows,
                'master_only' => $masterOnly,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->error('Could not encode the JSON report: '.$e->getMessage());

            return false;
        }
        if (file_put_contents($path, $json."\n") === false) {
            $this->error("Could not write the JSON report to {$path}");

            return false;
        }
        $this->line("JSON report written to {$path}");

        return true;
    }
}
