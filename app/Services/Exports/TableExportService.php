<?php

declare(strict_types=1);

namespace App\Services\Exports;

use App\Models\User;
use App\Services\Security\SecurityAuditRecorder;
use Carbon\CarbonImmutable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Indicator;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

use function Livewire\invade;

/**
 * Shared, secure export of any Filament list table to Excel or PDF.
 *
 * The record query is always rebuilt on the server from the table's own authorized
 * query. Client input is limited to the format, the scope and (for "selected") record
 * keys that are intersected with that query. Authorization is re-checked on execution.
 */
final class TableExportService
{
    public const TIMEZONE = 'America/New_York';

    public const XLSX_ROW_CAP = 10000;

    /** Rows x columns. Keeps one request inside a normal PHP memory and time budget (about 10 s, under 200 MB). */
    public const XLSX_CELL_CAP = 100000;

    public const PDF_ROW_CAP = 2000;

    public const PDF_BLOCK_ROW_CAP = 600;

    public const EXPORTS_PER_MINUTE = 20;

    public const FORMATS = ['xlsx', 'pdf'];

    public const SCOPES = ['filtered', 'all', 'selected'];

    private const FETCH_CHUNK = 500;

    private const CONTENT_TYPES = [
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'pdf' => 'application/pdf',
    ];

    public function __construct(
        private readonly ExportColumnReader $reader,
        private readonly XlsxExportWriter $xlsx,
        private readonly PdfExportWriter $pdf,
        private readonly SecurityAuditRecorder $audit,
    ) {}

    /** May this user export the given resource's table? Mirrors the list page's own viewAny rule. */
    public function canExport(?User $user, string $resource): bool
    {
        if (! $user instanceof User) {
            return false;
        }

        $model = $resource::getModel();

        return Gate::forUser($user)->allows('viewAny', $model) && $resource::canViewAny();
    }

    /**
     * @param  class-string  $resource  A Filament resource class
     * @param  list<int|string>|null  $selectedKeys  Required for the "selected" scope
     */
    public function download(
        ?User $actor,
        HasTable $livewire,
        string $resource,
        string $format,
        string $scope,
        bool $includeToggledOff = false,
        ?array $selectedKeys = null,
    ): StreamedResponse {
        abort_unless($actor instanceof User, 403);
        if (! in_array($format, self::FORMATS, true) || ! in_array($scope, self::SCOPES, true)) {
            throw new ExportFailedException('Choose Excel or PDF and a supported scope.');
        }

        if (! $this->canExport($actor, $resource)) {
            $this->audit($actor, $resource, $scope, $format, 'denied', 0);
            abort(403);
        }

        $limiterKey = 'table-export:'.$actor->getKey();
        if (RateLimiter::tooManyAttempts($limiterKey, self::EXPORTS_PER_MINUTE)) {
            $this->audit($actor, $resource, $scope, $format, 'refused', 0, ['reason' => 'rate limited']);

            throw new ExportFailedException('Too many exports in the last minute. Wait a moment and try again.');
        }
        RateLimiter::hit($limiterKey, 60);

        try {
            $dataset = $this->buildDataset($actor, $livewire, $resource, $format, $scope, $includeToggledOff, $selectedKeys);
        } catch (ExportFailedException $exception) {
            $this->audit($actor, $resource, $scope, $format, 'refused', 0, ['reason' => $exception->getMessage()]);
            if ($exception->getPrevious() instanceof Throwable) {
                Log::error('table.export.column_failed', [
                    'resource' => $resource,
                    'user_id' => $actor->getKey(),
                    'error' => $exception->getPrevious()->getMessage(),
                ]);
            }

            throw $exception;
        }

        $bytes = $format === 'pdf' ? $this->pdf->write($dataset) : $this->xlsx->write($dataset);
        $this->audit($actor, $resource, $scope, $format, 'allowed', $dataset->rowCount(), [
            'columns' => $dataset->columnCount(),
            'filters' => $dataset->filterSummary,
        ]);

        return response()->streamDownload(static function () use ($bytes): void {
            echo $bytes;
        }, self::filename($dataset->title, $format, $dataset->generatedAt), [
            'Content-Type' => self::CONTENT_TYPES[$format],
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** @param  list<int|string>|null  $selectedKeys */
    public function buildDataset(
        User $actor,
        HasTable $livewire,
        string $resource,
        string $format,
        string $scope,
        bool $includeToggledOff = false,
        ?array $selectedKeys = null,
    ): ExportDataset {
        $table = $livewire->getTable();
        $override = method_exists($resource, 'exportColumns') ? $resource::exportColumns() : null;
        $columns = $this->reader->columns($table, $includeToggledOff, $override);
        if ($columns === []) {
            throw new ExportFailedException('There are no columns you can export from this table.');
        }

        $query = $this->query($livewire, $scope, $selectedKeys);
        // A filtered query already eager-loads and aggregates every table column (except in a groups-only
        // table); adding them twice would duplicate aggregate aliases, so add only what it lacks.
        $tableColumns = $table->getColumns();
        $this->reader->prepareQuery($query, $scope === 'filtered' && ! $table->isGroupsOnly()
            ? array_values(array_filter($columns, static fn ($column): bool => ! isset($tableColumns[$column->getName()])))
            : $columns);

        $keys = $this->orderedKeys($query, $this->rowCap($format, count($columns)), $format, count($columns));

        $rows = [];
        $total = count($keys);
        $position = 0;
        foreach (array_chunk($keys, self::FETCH_CHUNK) as $chunk) {
            $models = (clone $query)->reorder()->whereKey($chunk)->get()->keyBy(static fn ($model) => $model->getKey());
            foreach ($chunk as $key) {
                $record = $models->get($key);
                if ($record !== null) {
                    $rows[] = $this->reader->row($columns, $record, $position, $total);
                }
                $position++;
            }
        }

        return new ExportDataset(
            title: self::title($resource),
            headers: $this->reader->headers($columns),
            rows: $rows,
            generatedAt: CarbonImmutable::now(self::TIMEZONE),
            generatedBy: (string) ($actor->name ?? $actor->email ?? 'Unknown user'),
            scopeLabel: $this->scopeLabel($scope, count($rows)),
            filterSummary: $scope === 'filtered' ? $this->filterSummary($livewire) : [],
            sortSummary: $this->sortSummary($livewire),
        );
    }

    public static function title(string $resource): string
    {
        return Str::title($resource::getPluralModelLabel());
    }

    /** "MBFD - <Table title> - YYYY-MM-DD.<ext>" with characters unsafe in file names removed. */
    public static function filename(string $title, string $format, ?CarbonImmutable $at = null): string
    {
        $clean = (string) preg_replace('/[\x00-\x1F\x7F\\\\\/:*?"<>|]+/u', ' ', $title);
        $clean = trim((string) preg_replace('/\s+/u', ' ', $clean), " .\t");
        $clean = trim(mb_substr($clean, 0, 80));
        $clean = $clean === '' ? 'Export' : $clean;
        $date = ($at ?? CarbonImmutable::now(self::TIMEZONE))->setTimezone(self::TIMEZONE)->format('Y-m-d');

        return 'MBFD - '.$clean.' - '.$date.'.'.$format;
    }

    private function rowCap(string $format, int $columns): int
    {
        if ($format === 'pdf') {
            return $columns > PdfExportWriter::MAX_TABLE_COLUMNS ? self::PDF_BLOCK_ROW_CAP : self::PDF_ROW_CAP;
        }

        return min(self::XLSX_ROW_CAP, intdiv(self::XLSX_CELL_CAP, max(1, $columns)));
    }

    /**
     * The ordered primary keys of the rows to export, read first so the cap is exact and the
     * models can then be loaded a chunk at a time instead of all at once.
     *
     * @return list<int|string>
     */
    private function orderedKeys(Builder $query, int $cap, string $format, int $columns): array
    {
        $keys = (clone $query)->limit($cap + 1)->pluck($query->getModel()->getQualifiedKeyName())->all();
        if (count($keys) > $cap) {
            $kind = $format === 'pdf' ? 'PDF' : 'Excel';
            $advice = $format === 'pdf'
                ? 'Narrow the filters or choose Excel'
                : ($cap < self::XLSX_ROW_CAP ? 'Narrow the filters or turn off columns' : 'Narrow the filters');

            throw new ExportFailedException(sprintf(
                '%s exports are limited to %s rows%s. %s and try again.',
                $kind,
                number_format($cap),
                ($format === 'xlsx' && $cap < self::XLSX_ROW_CAP) ? sprintf(' with %d columns', $columns) : '',
                $advice,
            ));
        }

        return array_values($keys);
    }

    /** @param  list<int|string>|null  $selectedKeys */
    private function query(HasTable $livewire, string $scope, ?array $selectedKeys): Builder
    {
        if ($scope === 'filtered') {
            return $livewire->getFilteredSortedTableQuery();
        }

        $query = $livewire->getTable()->getQuery();
        if ($query instanceof Relation) {
            $query = $query->getQuery();
        }

        if ($scope === 'selected') {
            if ($selectedKeys === null || $selectedKeys === []) {
                throw new ExportFailedException('Select at least one record to export.');
            }
            $query->whereKey($selectedKeys);
        } elseif (in_array(SoftDeletes::class, class_uses_recursive($query->getModel()), true)) {
            // "All" never includes records that were moved to the trash; pick them in the table to export them.
            $query->withoutTrashed();
        }

        invade($livewire)->applySortingToTableQuery($query);

        return $query;
    }

    private function scopeLabel(string $scope, int $count): string
    {
        return match ($scope) {
            'filtered' => 'Current view (filters, search and sort applied)',
            'selected' => 'Selected records ('.number_format($count).')',
            default => 'All records this user can see (filters and search ignored; trashed records left out)',
        };
    }

    /** @return list<string> */
    private function filterSummary(HasTable $livewire): array
    {
        $labels = [];
        foreach ($livewire->getTable()->getFilterIndicators() as $indicator) {
            if (! $indicator instanceof Indicator) {
                continue;
            }
            $label = $indicator->getLabel();
            $label = trim(strip_tags($label instanceof Htmlable ? $label->toHtml() : (string) $label));
            if ($label !== '') {
                $labels[] = $label;
            }
        }

        return $labels;
    }

    private function sortSummary(HasTable $livewire): ?string
    {
        $column = $livewire->getTableSortColumn();
        if (! is_string($column) || $column === '') {
            return null;
        }

        $label = $livewire->getTable()->getColumn($column)?->getLabel();
        $label = $label instanceof Htmlable ? strip_tags($label->toHtml()) : (string) ($label ?? $column);

        return $label.' ('.($livewire->getTableSortDirection() === 'desc' ? 'descending' : 'ascending').')';
    }

    /** @param  array<string, mixed>  $extra */
    private function audit(User $actor, string $resource, string $scope, string $format, string $result, int $rows, array $extra = []): void
    {
        $context = [
            'resource' => $resource,
            'model' => $resource::getModel(),
            'scope' => $scope,
            'format' => $format,
            'row_count' => $rows,
            ...$extra,
        ];

        $this->audit->record($actor, $actor, 'table_export', $result, null, $context);
        Log::info('table.export', ['user_id' => $actor->getKey(), 'result' => $result, ...$context]);
    }
}
