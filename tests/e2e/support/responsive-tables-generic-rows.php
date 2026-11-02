<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Columns whose value must be real for the page to render (a list of values is cycled row by row).
 * apparatuses.designation: a Select filter that preloads designations fails on a blank one (reported separately).
 * apparatus_service_tickets.status: the resource reads it with ApparatusServiceTicketStatus::from(), and the
 * default "Open" tab only lists open statuses.
 */
const RESPONSIVE_TABLES_TEXT_OVERRIDES = [
    'apparatuses' => ['designation' => 'Synthetic unit'],
    'apparatus_service_tickets' => ['status' => ['submitted', 'acknowledged', 'scheduled'], 'priority' => ['routine', 'attention', 'urgent']],
];

/**
 * Fills the table behind every Filament resource model with synthetic rows so list pages render real
 * table markup (not an empty state). Values come from the column type only; the first row of every
 * text column carries one long unbroken string. Disposable SQLite database only. A table whose
 * constraints reject the generated row is left empty and reported, never forced.
 *
 * @param  list<string>  $skip  tables that the fixture fills by hand or that must stay empty
 * @return array<string, string> table => "n rows" or the reason it was skipped
 */
function responsiveTablesSeedGenericRows(array $skip, int $rows = 12): array
{
    // Disposable SQLite only: related rows need not exist for a list page to render its table.
    DB::statement('PRAGMA foreign_keys = OFF');
    $report = [];
    $tables = [];
    foreach (Filament\Facades\Filament::getPanels() as $panel) {
        foreach ($panel->getResources() as $resource) {
            $model = $resource::getModel();
            if (is_a($model, Illuminate\Database\Eloquent\Model::class, true)) {
                $tables[(new $model)->getTable()] = $model;
            }
        }
    }
    $skip = [...$skip, 'users', 'roles', 'permissions', 'employees', 'unit_master_vehicles', 'migrations', 'sessions', 'jobs', 'cache', 'model_has_roles', 'model_has_permissions', 'role_has_permissions'];
    foreach ($tables as $table => $model) {
        if (in_array($table, $skip, true) || ! Schema::hasTable($table)) {
            continue;
        }
        $report[$table] = responsiveTablesFillTable($table, $rows, responsiveTablesEnumCasts($model), RESPONSIVE_TABLES_TEXT_OVERRIDES[$table] ?? []);
    }

    return $report;
}

/** Backed-enum casts of a model: column => every allowed value, so statuses and categories are real values that the page can read back. @return array<string, list<string|int>> */
function responsiveTablesEnumCasts(string $model): array
{
    $casts = [];
    foreach ((new $model)->getCasts() as $column => $cast) {
        if (is_string($cast) && enum_exists($cast) && is_subclass_of($cast, BackedEnum::class)) {
            $casts[$column] = array_map(static fn (BackedEnum $case) => $case->value, $cast::cases());
        }
    }

    return $casts;
}

/** @return array<string, string> column => first allowed value, read from the table's CHECK ("col" in (...)) rules */
function responsiveTablesEnums(string $table): array
{
    $sql = (string) DB::table('sqlite_master')->where('name', $table)->value('sql');
    $enums = [];
    if (preg_match_all('/"(\w+)"\s+in\s*\(\s*\'([^\']*)\'/i', $sql, $found, PREG_SET_ORDER)) {
        foreach ($found as $match) {
            $enums[$match[1]] = $match[2];
        }
    }

    return $enums;
}

function responsiveTablesFillTable(string $table, int $rows, array $casts = [], array $overrides = []): string
{
    $columns = Schema::getColumns($table);
    $enums = responsiveTablesEnums($table);
    foreach ([false, true] as $requiredOnly) {
        DB::table($table)->delete();
        try {
            for ($n = 1; $n <= $rows; $n++) {
                $row = [];
                foreach ($columns as $column) {
                    $name = $column['name'];
                    if (($column['auto_increment'] ?? false) || $name === 'id') {
                        continue;
                    }
                    // Soft-deleted and archived rows are hidden by a list page's default filter: keep every row live.
                    if (in_array($name, ['deleted_at', 'archived_at'], true)) {
                        $row[$name] = null;

                        continue;
                    }
                    if ($requiredOnly && ! isset($overrides[$name]) && (($column['nullable'] ?? true) || ($column['default'] ?? null) !== null)) {
                        continue;
                    }
                    $allowed = $casts[$name] ?? [];
                    $override = $overrides[$name] ?? null;
                    if (is_array($override)) {
                        $override = $override[($n - 1) % count($override)];
                    }
                    $row[$name] = $override ?? ($allowed !== [] ? $allowed[($n - 1) % count($allowed)] : ($enums[$name] ?? responsiveTablesValue($column, $n)));
                }
                DB::table($table)->insert($row);
            }

            return $rows.' rows'.($requiredOnly ? ' (required columns only)' : '');
        } catch (Throwable $e) {
            $reason = substr(str_replace("\n", ' ', $e->getMessage()), 0, 90);
        }
    }
    DB::table($table)->delete();

    return 'skipped: '.$reason;
}

/** @param array<string, mixed> $column */
function responsiveTablesValue(array $column, int $n): mixed
{
    $type = strtolower((string) ($column['type_name'] ?? $column['type'] ?? ''));
    $name = (string) $column['name'];
    $now = now()->subDays($n)->toDateTimeString();

    return match (true) {
        str_ends_with($name, '_id') && str_contains($type, 'int') => 1 + ($n % 3),
        str_contains($type, 'bool') || $type === 'tinyint' => $n % 2,
        str_contains($type, 'int') => $n,
        str_contains($type, 'decimal') || str_contains($type, 'float') || str_contains($type, 'double') || str_contains($type, 'numeric') || str_contains($type, 'real') => $n + 0.5,
        str_contains($type, 'datetime') || str_contains($type, 'timestamp') => $now,
        $type === 'date' => substr($now, 0, 10),
        $type === 'time' => '08:30:00',
        str_contains($type, 'json') => '{}',
        str_contains($name, 'email') => 'synthetic'.$n.'@example.test',
        str_contains($name, 'url') || str_contains($name, 'link') => 'https://example.test/synthetic/'.str_repeat('very-long-path-segment/', $n === 1 ? 6 : 1).$n,
        default => $n === 1
            ? 'Synthetic '.$name.' '.str_repeat('UnbrokenSyntheticValue', 6)
            : 'Synthetic '.str_replace('_', ' ', $name).' '.$n,
    };
}
