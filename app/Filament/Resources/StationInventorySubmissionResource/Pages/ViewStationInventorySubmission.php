<?php

namespace App\Filament\Resources\StationInventorySubmissionResource\Pages;

use App\Filament\Resources\StationInventorySubmissionResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewStationInventorySubmission extends ViewRecord
{
    protected static string $resource = StationInventorySubmissionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('print')
                ->label('Print')
                ->icon('heroicon-o-printer')
                ->action(fn () => null)
                ->extraAttributes(['onclick' => 'window.print(); return false;'])
                ->color('gray'),
            Actions\Action::make('download_pdf')
                ->label('Download PDF')
                ->icon('heroicon-o-arrow-down-tray')
                ->url(fn ($record) => route('download-inventory-pdf', ['submission' => $record->id]))
                ->openUrlInNewTab()
                ->color('primary'),
        ];
    }
}
