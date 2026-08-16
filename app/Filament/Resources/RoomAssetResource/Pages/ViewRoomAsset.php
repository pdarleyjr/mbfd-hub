<?php

namespace App\Filament\Resources\RoomAssetResource\Pages;

use App\Filament\Resources\RoomAssetResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewRoomAsset extends ViewRecord
{
    protected static string $resource = RoomAssetResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\EditAction::make()];
    }
}
