<?php

declare(strict_types=1);

namespace App\Services\Exports;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Filament\Support\Contracts\HasLabel;
use Filament\Tables\Columns\CheckboxColumn;
use Filament\Tables\Columns\Column;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\SelectColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use stdClass;
use Throwable;

/**
 * Turns a Filament table's own column definitions into typed export cells.
 *
 * Only columns the signed-in user can see are exported: columns hidden by authorization
 * (hidden()/visible() rules, on the column or on a layout around it) are never read, and
 * columns the user toggled off in the column picker are skipped unless the caller asks.
 */
final class ExportColumnReader
{
    /** Columns that cannot be represented as a spreadsheet value. */
    private const UNSUPPORTED = [ImageColumn::class, ViewColumn::class];

    /** Whole numbers beyond this lose digits as a spreadsheet number, so they stay text. */
    private const MAX_SAFE_INTEGER = 999_999_999_999_999;

    /**
     * @param  list<string|Column>|null  $override  Resource-supplied export column list: table column names and/or Column instances.
     * @return list<Column>
     */
    public function columns(Table $table, bool $includeToggledOff, ?array $override = null): array
    {
        $available = $table->getColumns();
        $candidates = [];

        if ($override === null) {
            $candidates = array_values($available);
        } else {
            foreach ($override as $entry) {
                if ($entry instanceof Column) {
                    $entry->table($table);
                    $candidates[] = $entry;
                } elseif (is_string($entry) && isset($available[$entry])) {
                    $candidates[] = $available[$entry];
                }
            }
        }

        $columns = [];
        foreach ($candidates as $column) {
            if ($this->isHidden($column) || $this->isUnsupported($column)) {
                continue;
            }
            // An explicit resource override already states which columns belong in the export.
            if ($override === null && ! $includeToggledOff && $column->isToggledHidden()) {
                continue;
            }

            $export = clone $column;
            if (method_exists($export, 'limit')) {
                $export->limit(null);
            }
            if (method_exists($export, 'words')) {
                $export->words(null);
            }
            $columns[] = $export;
        }

        return $columns;
    }

    /** @param  list<Column>  $columns */
    public function prepareQuery(Builder $query, array $columns): Builder
    {
        foreach ($columns as $column) {
            $column->applyRelationshipAggregates($query);
            $column->applyEagerLoading($query);
        }

        return $query;
    }

    /** @param  list<Column>  $columns
     * @return list<string> */
    public function headers(array $columns): array
    {
        return array_map(static function (Column $column): string {
            $label = $column->getLabel();

            return trim(strip_tags($label instanceof Htmlable ? $label->toHtml() : (string) $label));
        }, $columns);
    }

    /**
     * @param  list<Column>  $columns
     * @return list<ExportCell>
     */
    public function row(array $columns, Model $record, int $index = 0, int $count = 1): array
    {
        $loop = new stdClass;
        $loop->index = $index;
        $loop->iteration = $index + 1;
        $loop->remaining = max(0, $count - $index - 1);
        $loop->count = $count;
        $loop->first = $index === 0;
        $loop->last = $index === $count - 1;
        $loop->even = $index % 2 === 1;
        $loop->odd = $index % 2 === 0;
        $loop->depth = 1;
        $loop->parent = null;

        $cells = [];
        foreach ($columns as $column) {
            $column->record($record);
            $column->rowLoop($loop);
            try {
                $cells[] = $this->cell($column);
            } catch (Throwable $exception) {
                throw new ExportFailedException(sprintf('The "%s" column could not be read for an export row.', $column->getName()), 0, $exception);
            }
        }

        return $cells;
    }

    private function isHidden(Column $column): bool
    {
        if ($column->isHidden()) {
            return true;
        }

        // Columns are flattened out of their layout; a hidden Split or Stack hides what is inside it.
        $layout = $column->getLayout();
        while ($layout !== null) {
            if ($layout->isHidden()) {
                return true;
            }
            $layout = $layout->getLayout();
        }

        return false;
    }

    private function isUnsupported(Column $column): bool
    {
        foreach (self::UNSUPPORTED as $class) {
            if ($column instanceof $class) {
                return true;
            }
        }

        return false;
    }

    private function cell(Column $column): ExportCell
    {
        $state = $column->getState();
        if ($state instanceof Arrayable) {
            $state = $state->toArray();
        }

        if (is_array($state)) {
            $parts = [];
            foreach ($state as $item) {
                $text = $this->text($column, $item);
                if ($text !== '') {
                    $parts[] = $text;
                }
            }

            return ExportCell::text(implode(', ', $parts));
        }

        if ($state === null || $state === '') {
            return ExportCell::blank();
        }

        if ($state instanceof DateTimeInterface) {
            return $this->temporal($column, $state);
        }

        if ($column instanceof SelectColumn) {
            $options = $column->getOptions();

            return ExportCell::text((string) ($options[$state] ?? $state));
        }

        if (is_bool($state) || $column instanceof ToggleColumn || $column instanceof CheckboxColumn
            || ($column instanceof IconColumn && $column->isBoolean())) {
            return ExportCell::text($this->yesNo($column, $state));
        }

        if (is_string($state) && $this->isTemporalColumn($column) && $this->looksLikeDate($state)) {
            try {
                return $this->temporal($column, CarbonImmutable::parse($state));
            } catch (Throwable) {
                // Not parseable as a date: fall through and export the displayed text.
            }
        }

        $formatted = $this->text($column, $state);

        if ((is_int($state) || is_float($state)) && $this->numberMatchesDisplay($state, $formatted)) {
            return ExportCell::number($state);
        }

        if (is_string($state) && is_numeric($state) && $this->isDecimalCast($column)) {
            return ExportCell::number((float) $state);
        }

        return ExportCell::text($formatted);
    }

    /**
     * Calendar dates stay calendar dates; real timestamps become New York wall-clock time.
     * A date-cast attribute arrives as midnight in the app time zone and must not shift to the evening before.
     */
    private function temporal(Column $column, DateTimeInterface $value): ExportCell
    {
        $appTime = CarbonImmutable::instance($value)->setTimezone(config('app.timezone'));
        if ($appTime->format('H:i:s') === '00:00:00') {
            return ExportCell::date($appTime);
        }

        $dateOnly = method_exists($column, 'isDate') && $column->isDate() && ! $column->isDateTime();
        if ($dateOnly) {
            return ExportCell::date(CarbonImmutable::instance($value)->setTimezone(TableExportService::TIMEZONE));
        }

        return ExportCell::dateTime($value);
    }

    private function isTemporalColumn(Column $column): bool
    {
        return method_exists($column, 'isDate') && ($column->isDate() || $column->isDateTime());
    }

    private function looksLikeDate(string $value): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}([ T]\d{2}:\d{2}(:\d{2})?)?/', $value) === 1;
    }

    private function yesNo(Column $column, mixed $state): string
    {
        if (is_bool($state) && method_exists($column, 'formatState')) {
            $display = $this->text($column, $state);
            if (! in_array($display, ['', '0', '1'], true)) {
                return $display;
            }
        }

        return filter_var($state, FILTER_VALIDATE_BOOLEAN) ? 'Yes' : 'No';
    }

    /**
     * A whole number or decimal is exported as a number only when what the table shows is that number,
     * optionally with a currency sign, thousands commas, a percent sign or a short unit word after it.
     * Labels that merely contain digits ("Medic 5", "E-12", "#1042") stay text.
     */
    private function numberMatchesDisplay(int|float $state, string $formatted): bool
    {
        if (is_int($state) && abs($state) > self::MAX_SAFE_INTEGER) {
            return false;
        }

        if (preg_match('/^[\s$€£]*(-?[\d,]*\.?\d+)\s*(?:%|[A-Za-z.]{1,12})?$/u', $formatted, $match) !== 1) {
            return false;
        }

        $digits = str_replace(',', '', $match[1]);

        return is_numeric($digits) && abs((float) $digits - (float) $state) < 0.005;
    }

    private function isDecimalCast(Column $column): bool
    {
        $record = $column->getRecord();
        if (! $record instanceof Model || str_contains($column->getName(), '.')) {
            return false;
        }

        $cast = $record->getCasts()[$column->getName()] ?? null;

        return is_string($cast) && (str_starts_with($cast, 'decimal') || in_array($cast, ['float', 'double', 'real'], true));
    }

    /** Displayed text for one state value (never truncated); markup is removed only when the column renders HTML. */
    private function text(Column $column, mixed $state): string
    {
        if ($state instanceof \UnitEnum && ! $state instanceof HasLabel) {
            $state = $state instanceof \BackedEnum ? $state->value : $state->name;
        }

        $display = method_exists($column, 'formatState') ? $column->formatState($state) : $state;

        $isHtml = $display instanceof Htmlable || (method_exists($column, 'isHtml') && $column->isHtml());
        if ($display instanceof Htmlable) {
            $display = $display->toHtml();
        }
        if ($display instanceof \UnitEnum) {
            $display = $display instanceof HasLabel ? $display->getLabel() : ($display instanceof \BackedEnum ? $display->value : $display->name);
        }
        if (is_bool($display)) {
            $display = $display ? 'Yes' : 'No';
        }
        if ($display === null) {
            return '';
        }
        if (is_array($display)) {
            $display = implode(', ', array_map(static fn (mixed $part): string => is_scalar($part) ? (string) $part : (string) json_encode($part), $display));
        }
        if (! is_scalar($display) && ! $display instanceof \Stringable) {
            $display = (string) json_encode($display);
        }

        $text = (string) $display;
        if ($isHtml) {
            $text = html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />', '</li>', '</p>'], "\n", $text)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        return trim($text);
    }
}
