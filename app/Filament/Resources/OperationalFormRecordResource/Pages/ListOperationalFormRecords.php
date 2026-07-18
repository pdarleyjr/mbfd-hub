<?php

namespace App\Filament\Resources\OperationalFormRecordResource\Pages;

use App\Filament\Resources\OperationalFormRecordResource;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;

class ListOperationalFormRecords extends ListRecords
{
    protected static string $resource = OperationalFormRecordResource::class;

    public function getTabs(): array
    {
        return [
            'all' => Tab::make('All Forms'),
            'ics_214' => Tab::make('ICS 214')->modifyQueryUsing(fn ($query) => $query->where('form_type', 'ics_214')),
            'froc' => Tab::make('F-ROC Daily Activity Reports')->modifyQueryUsing(fn ($query) => $query->where('form_type', 'froc_log_001_ff')),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
