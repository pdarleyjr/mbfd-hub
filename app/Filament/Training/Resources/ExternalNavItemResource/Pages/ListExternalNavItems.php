<?php

namespace App\Filament\Training\Resources\ExternalNavItemResource\Pages;

use App\Filament\Training\Resources\ExternalNavItemResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListExternalNavItems extends ListRecords
{
    protected static string $resource = ExternalNavItemResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('open_baserow')
                ->label('Open Baserow')
                ->icon('heroicon-o-arrow-top-right-on-square')
                ->url('https://baserow.mbfdhub.com', shouldOpenInNewTab: true)
                ->color('info'),
            Actions\CreateAction::make(),
        ];
    }
}
