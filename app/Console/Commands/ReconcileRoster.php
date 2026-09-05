<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Employee;
use App\Services\Roster\RosterHtmlParser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class ReconcileRoster extends Command
{
    protected $signature = 'mbfd:roster-reconcile
                            {file : Authoritative roster HTML export}
                            {--apply : Apply exact-ID changes}
                            {--json : Output the exact reconciliation sets as JSON}';

    protected $description = 'Preview or apply an exact Employee ID roster reconciliation without deleting records';

    public function handle(RosterHtmlParser $parser): int
    {
        $path = (string) $this->argument('file');
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('The roster export is missing or unreadable.');
        }

        $rows = $parser->parse((string) file_get_contents($path));
        $existingRows = Employee::query()->get();
        $productionIds = $existingRows
            ->map(fn (Employee $employee): string => (string) $employee->employee_id)
            ->sort()
            ->values();
        $duplicateIds = $productionIds
            ->countBy()
            ->filter(fn (int $count): bool => $count > 1)
            ->keys()
            ->sort()
            ->values();
        $existing = $existingRows->keyBy(fn (Employee $employee): string => (string) $employee->employee_id);
        $incomingIds = collect($rows)->pluck('employee_id')->sort()->values();
        $currentMatch = $incomingIds->intersect($productionIds)->values();
        $missing = $incomingIds->diff($productionIds)->values();
        $departed = $productionIds->diff($incomingIds)->values();
        $new = collect($rows)->reject(fn (array $row): bool => $existing->has($row['employee_id']));
        $changed = collect($rows)->filter(function (array $row) use ($existing): bool {
            $employee = $existing->get($row['employee_id']);

            return $employee instanceof Employee
                && $employee->roster_status !== 'active';
        });
        $report = [
            'roster_rows' => count($rows),
            'roster_unique_employee_ids' => $incomingIds->unique()->count(),
            'production_employees' => $productionIds->count(),
            'current_match' => $currentMatch->all(),
            'current_missing_from_db' => $missing->all(),
            'db_not_in_current_roster' => $departed->all(),
            'duplicate_collision' => $duplicateIds->all(),
            'status_reactivation' => $changed->pluck('employee_id')->sort()->values()->all(),
            'applied' => false,
        ];
        if ($this->option('json') && ! $this->option('apply')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        } elseif (! $this->option('json')) {
            $this->table(
                ['Roster rows', 'Unique IDs', 'Current matches', 'Status reactivations', 'New IDs', 'Not in roster', 'Duplicate collisions'],
                [[count($rows), $incomingIds->unique()->count(), $currentMatch->count(), $changed->count(), $new->count(), $departed->count(), $duplicateIds->count()]],
            );
        }
        if ($duplicateIds->isNotEmpty()) {
            $this->error('Duplicate production Employee IDs detected. No database rows were changed.');

            return self::FAILURE;
        }
        if (! $this->option('apply')) {
            if (! $this->option('json')) {
                $this->warn('DRY RUN ONLY. No database rows were changed.');
            }

            return self::SUCCESS;
        }

        DB::transaction(function () use ($rows, $incomingIds): void {
            foreach ($rows as $row) {
                $employee = Employee::query()->firstOrCreate(
                    ['employee_id' => $row['employee_id']],
                    [
                        'name' => $row['name'],
                        'rank' => $row['rank'],
                        'roster_status' => 'active',
                        // Roster sync creates an operational profile, not an issued human credential.
                        'password' => Str::random(64),
                        'must_change_password' => false,
                    ],
                );
                if (! $employee->wasRecentlyCreated && $employee->roster_status !== 'active') {
                    $employee->update(['roster_status' => 'active']);
                }
            }
            Employee::query()->whereNotIn('employee_id', $incomingIds)->update(['roster_status' => 'departed']);
        });
        if ($this->option('json')) {
            $report['applied'] = true;
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        } else {
            $this->info('Applied exact Employee ID reconciliation. No records were deleted, no human credential was issued, and no city email was fabricated.');
        }

        return self::SUCCESS;
    }
}
