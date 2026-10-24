<?php

declare(strict_types=1);

namespace App\Services\Exports;

use Carbon\CarbonImmutable;

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

    public function rowCount(): int
    {
        return count($this->rows);
    }

    public function columnCount(): int
    {
        return count($this->headers);
    }
}
