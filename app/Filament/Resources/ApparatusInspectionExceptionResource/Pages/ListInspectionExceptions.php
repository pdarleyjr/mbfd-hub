<?php

declare(strict_types=1);

namespace App\Filament\Resources\ApparatusInspectionExceptionResource\Pages;

use App\Filament\Resources\ApparatusInspectionExceptionResource;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

final class ListInspectionExceptions extends ListRecords
{
    protected static string $resource = ApparatusInspectionExceptionResource::class;

    public function getTabs(): array
    {
        return [
            'open' => Tab::make('Needs attention')->modifyQueryUsing(fn (Builder $query): Builder => $query->whereNotIn('status', ['resolved', 'dismissed'])),
            'history' => Tab::make('History')->modifyQueryUsing(fn (Builder $query): Builder => $query->whereIn('status', ['resolved', 'dismissed'])),
        ];
    }
}
