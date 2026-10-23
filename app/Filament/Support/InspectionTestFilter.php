<?php

declare(strict_types=1);

namespace App\Filament\Support;

use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;

final class InspectionTestFilter
{
    public static function make(): SelectFilter
    {
        return SelectFilter::make('test_classification')
            ->label('Records')
            ->options(['operational' => 'Operational inspections', 'test' => 'Excluded tests', 'all' => 'All retained records'])
            ->default('operational')
            ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? 'operational') {
                'test' => $query->whereNotNull($query->getModel()->qualifyColumn('test_excluded_at')),
                'all' => $query,
                default => $query->whereNull($query->getModel()->qualifyColumn('test_excluded_at')),
            });
    }
}
