<?php

namespace App\Filament\Resources\TodoResource\Pages;

use App\Filament\Resources\TodoResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Filament\Resources\Components\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListTodos extends ListRecords
{
    protected static string $resource = TodoResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }

    public function getTabs(): array
    {
        return [
            'all' => Tab::make('All'),
            'assigned_to_me' => Tab::make('Assigned to me')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('assigned_to', auth()->id())),
            'created_by_me' => Tab::make('Created by me')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('created_by', auth()->id())),
        ];
    }
}
