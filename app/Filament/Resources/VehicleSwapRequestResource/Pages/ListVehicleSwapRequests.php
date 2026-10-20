<?php

declare(strict_types=1);

namespace App\Filament\Resources\VehicleSwapRequestResource\Pages;

use App\Filament\Resources\VehicleSwapRequestResource;
use App\Models\ApparatusVehicleSwapRequest;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListVehicleSwapRequests extends ListRecords
{
    protected static string $resource = VehicleSwapRequestResource::class;

    public function getTitle(): string
    {
        return 'Pending vehicle swaps';
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'pending';
    }

    /** @return array<string, Tab> */
    public function getTabs(): array
    {
        return [
            'pending' => Tab::make('Pending')
                ->badge(fn (): int => ApparatusVehicleSwapRequest::query()->where('status', ApparatusVehicleSwapRequest::STATUS_PENDING)->count())
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', ApparatusVehicleSwapRequest::STATUS_PENDING)),
            'decided' => Tab::make('Decided')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', '!=', ApparatusVehicleSwapRequest::STATUS_PENDING)),
            'all' => Tab::make('All'),
        ];
    }
}
