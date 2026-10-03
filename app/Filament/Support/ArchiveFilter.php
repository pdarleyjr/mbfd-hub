<?php

declare(strict_types=1);

namespace App\Filament\Support;

use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;

final class ArchiveFilter
{
    public static function make(): SelectFilter
    {
        return SelectFilter::make('archive_state')
            ->label('Visibility')
            ->options(['active' => 'Active', 'archived' => 'Archived', 'all' => 'All'])
            ->default('active')
            ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? 'active') {
                'archived' => $query->whereNotNull($query->getModel()->qualifyColumn('archived_at')),
                'all' => $query,
                default => $query->whereNull($query->getModel()->qualifyColumn('archived_at')),
            });
    }
}
