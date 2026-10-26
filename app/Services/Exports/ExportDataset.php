<?php

declare(strict_types=1);

namespace App\Services\Exports;

use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/** Everything a writer needs to produce a table export; independent of Filament. */
final class ExportDataset
{
    /**
     * @param  list<string>  $headers
     * @param  list<list<ExportCell>>  $rows
     * @param  list<string>  $filterSummary
     */
    public function __construct(
        public readonly string $title,
        public readonly array $headers,
        public readonly array $rows,
        public readonly CarbonImmutable $generatedAt,
        public readonly string $generatedBy,
        public readonly string $scopeLabel,
        public readonly array $filterSummary = [],
        public readonly ?string $sortSummary = null,
    ) {}

    /**
     * @param  list<string>  $headers
     * @param  list<list<string|int|float|bool|DateTimeInterface|null>>  $rows
     * @param  list<string>  $filterSummary
     */
    public static function fromRows(User $actor, string $title, array $headers, array $rows, string $scopeLabel, array $filterSummary = []): self
    {
        return new self(
            title: $title,
            headers: $headers,
            rows: array_map(static fn (array $row): array => array_map(static fn ($value): ExportCell => match (true) {
                $value instanceof DateTimeInterface => ExportCell::dateTime($value),
                is_int($value), is_float($value) => ExportCell::number($value),
                is_bool($value) => ExportCell::text($value ? 'Yes' : 'No'),
                default => ExportCell::text($value),
            }, $row), $rows),
            generatedAt: CarbonImmutable::now(TableExportService::TIMEZONE),
            generatedBy: (string) $actor->name,
            scopeLabel: $scopeLabel,
            filterSummary: $filterSummary,
        );
    }

    public function rowCount(): int
    {
        return count($this->rows);
    }

    public function columnCount(): int
    {
        return count($this->headers);
    }
}
