<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Employee;
use App\Services\Roster\RosterHtmlParser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class ReconcileRoster extends Command
{
    protected $signature = 'mbfd:roster-reconcile {file : Authoritative roster HTML export} {--apply : Apply exact-ID changes}';

    protected $description = 'Preview or apply an exact Employee ID roster reconciliation without deleting records';

    public function handle(RosterHtmlParser $parser): int
    {
        $path = (string) $this->argument('file');
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('The roster export is missing or unreadable.');
        }

        $rows = $parser->parse((string) file_get_contents($path));
        $existing = Employee::query()->get()->keyBy(fn (Employee $employee): string => (string) $employee->employee_id);
        $incomingIds = collect($rows)->pluck('employee_id');
        $new = collect($rows)->reject(fn (array $row): bool => $existing->has($row['employee_id']));
        $changed = collect($rows)->filter(function (array $row) use ($existing): bool {
            $employee = $existing->get($row['employee_id']);

            return $employee instanceof Employee
                && ($employee->name !== $row['name'] || $employee->rank !== $row['rank'] || $employee->roster_status !== 'active');
        });
        $departed = $existing->reject(fn (Employee $employee): bool => $incomingIds->contains((string) $employee->employee_id));

        $this->table(['Roster rows', 'Exact updates', 'New IDs', 'Not in roster'], [[
            count($rows), $changed->count(), $new->count(), $departed->count(),
        ]]);
        if (! $this->option('apply')) {
            $this->warn('DRY RUN ONLY. No database rows were changed.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($rows, $incomingIds): void {
            foreach ($rows as $row) {
                Employee::query()->updateOrCreate(
                    ['employee_id' => $row['employee_id']],
                    ['name' => $row['name'], 'rank' => $row['rank'], 'roster_status' => 'active'],
                );
            }
            Employee::query()->whereNotIn('employee_id', $incomingIds)->update(['roster_status' => 'departed']);
        });
        $this->info('Applied exact Employee ID reconciliation. No records were deleted and no city email was fabricated.');

        return self::SUCCESS;
    }
}
