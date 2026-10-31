<?php

declare(strict_types=1);

namespace App\Filament\Resources\ApparatusOperationalAlertResource\Pages;

use App\Filament\Resources\ApparatusOperationalAlertResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListApparatusOperationalAlerts extends ListRecords
{
    protected static string $resource = ApparatusOperationalAlertResource::class;

    public function getTitle(): string
    {
        return 'Operational alerts';
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('New alert')];
    }
}
