<?php

declare(strict_types=1);

namespace App\Services\Ems\Exports;

/**
 * One table of a report: a heading, an optional note, column headers and rows of cells.
 * Columns listed in $numericColumns (0-based) are written as real numbers; everything else is text.
 */
final readonly class ReportSection
{
    /**
     * @param  list<string>  $headers
     * @param  list<list<scalar|null>>  $rows
     * @param  list<int>  $numericColumns
     * @param  list<int>  $widths  column widths in characters (Excel); empty = automatic
     */
    public function __construct(
        public string $heading,
        public array $headers,
        public array $rows,
        public array $numericColumns = [],
        public ?string $note = null,
        public array $widths = [],
    ) {}
}
