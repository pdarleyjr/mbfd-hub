<?php

declare(strict_types=1);

namespace App\Services\Ems\Exports;

/** Everything one EMS export needs: a title, the facts printed at the top, and the tables. */
final readonly class ReportSpec
{
    /**
     * @param  list<array{0: string, 1: string}>  $facts  label / value pairs printed above the tables
     * @param  list<ReportSection>  $sections
     */
    public function __construct(
        public string $title,
        public array $facts,
        public array $sections,
        public string $filenameStem,
    ) {}
}
