<?php

declare(strict_types=1);

namespace App\Filament\Resources\StationInventorySubmissionResource\Pages;

use App\Filament\Resources\StationInventorySubmissionResource;
use App\Models\StationInventorySubmission;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

final class ViewStationInventorySubmission extends ViewRecord
{
    protected static string $resource = StationInventorySubmissionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('downloadPdf')
                ->label('Download private PDF')
                ->icon('heroicon-o-arrow-down-tray')
                ->url(function (): string {
                    $record = $this->getRecord();
                    abort_unless($record instanceof StationInventorySubmission, 404);

                    return route('download-inventory-pdf', $record);
                }),
        ];
    }
}
