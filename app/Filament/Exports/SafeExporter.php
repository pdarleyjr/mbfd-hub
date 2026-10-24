<?php

declare(strict_types=1);

namespace App\Filament\Exports;

use App\Services\Exports\CellSanitizer;
use Filament\Actions\Exports\Exporter;
use Illuminate\Database\Eloquent\Model;

/**
 * Base class for Filament's queued CSV/XLSX exporters.
 *
 * Every text value is passed through the shared formula-injection guard, so a cell that
 * starts with = + - @ (or a control character) is written as plain text instead of
 * being evaluated by Excel, Sheets or LibreOffice. Numbers and null are untouched.
 */
abstract class SafeExporter extends Exporter
{
    /** @return array<int, mixed> */
    public function __invoke(Model $record): array
    {
        return array_map(
            static fn (mixed $value): mixed => CellSanitizer::value($value),
            parent::__invoke($record),
        );
    }
}
